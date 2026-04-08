package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import android.util.JsonReader
import by.dzmitrypanou.catholicapp.BuildConfig
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileInputStream
import java.io.InputStream
import java.io.InputStreamReader
import java.nio.charset.StandardCharsets
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

object ScriptureTextRepository {
    private val assetsByTranslation = mapOf(
        "catholic_nt" to "bcat_nt_full.json",
        "bokun" to "bbb_full.json",
        "semiukha" to "bbs_full.json",
        "charniauski_2017" to "bvc-2017_full.json",
        "stankevich" to "bjs_full.json",
        ScriptureCatalog.SYNODAL_RU_TRANSLATION_ID to "syn_full.json"
    )

    @Volatile
    private var cache: MutableMap<String, List<ScriptureBookData>> = mutableMapOf()

    /** Толькі метаданыя кніг (без стыхаў) — для экрана «Святое Пісанне» і лічыльнікаў глав. */
    @Volatile
    private var metaCache: MutableMap<String, List<ScriptureBookMeta>> = mutableMapOf()

    fun clearMemoryCache() {
        synchronized(this) {
            cache.clear()
            metaCache.clear()
        }
    }

    /** Спачатку лакальны файл пасля scripture.php/scripture_hash.php; калі няма — убудаваны JSON у assets. */
    private fun loadJsonString(context: Context, translationId: String): String? {
        val f = File(context.filesDir, "scripture_cache/$translationId.json")
        if (f.isFile && f.length() > 0L) {
            return f.readText()
        }
        val assetFile = assetsByTranslation[translationId] ?: return null
        return context.assets.open(assetFile).bufferedReader().use { it.readText() }
    }

    fun getTestaments(context: Context, translationId: String): List<TestamentSection> {
        val books = loadBooksMetadata(context, translationId).map {
            Book(id = it.bookId, title = it.bookName, chapters = it.chapterCount)
        }
        val nt = books.filter { it.id in 40..66 }
        val ot = books.filter { it.id !in 40..66 }
        val (ntTitle, otTitle) = when (translationId) {
            ScriptureCatalog.SYNODAL_RU_TRANSLATION_ID -> "Новый завет" to "Старый завет"
            else -> "Новы Запавет" to "Стары Запавет"
        }
        return buildList {
            if (nt.isNotEmpty()) add(TestamentSection(ntTitle, nt))
            if (ot.isNotEmpty()) add(TestamentSection(otTitle, ot))
        }
    }

    /**
     * Фонавы прагрэў кэша метаданых і спіса заветаў/кніг — каб экран «Святое Пісанне» адмалёўваўся без чакання парсінгу.
     * Выклікаць з галоўнага экрана ці ў onCreate ScriptureFragment.
     */
    suspend fun warmTestamentUiCatalog(context: Context) {
        val app = context.applicationContext
        val id = ScriptureTranslationStore.getSelectedTranslationId(app)
        withContext(Dispatchers.Default) {
            getTestaments(app, id)
        }
    }

    /** Прагрэў лічыльніка глав для кнігі (плашкі глав) да адкрыцця [ScriptureChaptersFragment]. */
    suspend fun warmChapterCountForBook(context: Context, translationId: String, bookId: Int) {
        val app = context.applicationContext
        withContext(Dispatchers.Default) {
            getChapterCountById(app, translationId, bookId)
        }
    }

    fun getChapterCount(context: Context, translationId: String, bookTitle: String): Int =
        loadBooksMetadata(context, translationId).firstOrNull { it.bookName == bookTitle }?.chapterCount ?: 0

    fun getChapterCountById(context: Context, translationId: String, bookId: Int): Int =
        loadBooksMetadata(context, translationId).firstOrNull { it.bookId == bookId }?.chapterCount ?: 0

    /** Усе главы перакладу: сартаванне па book_id, потым нумар главы (для годовага плана чытання). */
    fun getAllChaptersInCanonicalOrder(context: Context, translationId: String): List<ScriptureChapterRef> =
        loadBooksMetadata(context, translationId)
            .sortedBy { it.bookId }
            .flatMap { meta ->
                (1..meta.chapterCount).map { ch ->
                    ScriptureChapterRef(
                        bookId = meta.bookId,
                        bookTitle = meta.bookName,
                        chapter = ch
                    )
                }
            }

    fun getChapterVerses(
        context: Context,
        translationId: String,
        bookTitle: String,
        chapter: Int
    ): List<ScriptureVerse> {
        val book = loadBooks(context, translationId).firstOrNull { it.bookName == bookTitle } ?: return emptyList()
        val chapterData = book.chapters.firstOrNull { it.chapter == chapter } ?: return emptyList()
        return chapterData.verses.map { ScriptureVerse(number = it.number, text = it.text) }
    }

    fun getChapterVersesById(
        context: Context,
        translationId: String,
        bookId: Int,
        chapter: Int
    ): List<ScriptureVerse> {
        val book = loadBooks(context, translationId).firstOrNull { it.bookId == bookId } ?: return emptyList()
        val chapterData = book.chapters.firstOrNull { it.chapter == chapter } ?: return emptyList()
        return chapterData.verses.map { ScriptureVerse(number = it.number, text = it.text) }
    }

    fun getVerseComparisons(
        context: Context,
        bookId: Int,
        chapter: Int,
        verse: Int
    ): List<VerseComparison> {
        val translationMap = ScriptureCatalog.allTranslations().associateBy { it.id }
        return assetsByTranslation.keys.mapNotNull { translationId ->
            val trTitle = translationMap[translationId]?.let { ScriptureCatalog.shortTitle(it.id) } ?: return@mapNotNull null
            val book = loadBooks(context, translationId).firstOrNull { it.bookId == bookId } ?: return@mapNotNull null
            val chapterData = book.chapters.firstOrNull { it.chapter == chapter } ?: return@mapNotNull null
            val verseData = chapterData.verses.firstOrNull { it.number == verse } ?: return@mapNotNull null
            VerseComparison(translation = trTitle, text = verseData.text)
        }
    }

    fun getVerseTextById(
        context: Context,
        translationId: String,
        bookId: Int,
        chapter: Int,
        verse: Int
    ): String? {
        val book = loadBooks(context, translationId).firstOrNull { it.bookId == bookId } ?: return null
        val chapterData = book.chapters.firstOrNull { it.chapter == chapter } ?: return null
        return chapterData.verses.firstOrNull { it.number == verse }?.text
    }

    /** Пошук слова па ўсім тэксце бягучага перакладу (цэлыя словы). */
    fun searchWord(
        context: Context,
        translationId: String,
        rawQuery: String
    ): ScriptureWordSearchResult {
        val display = rawQuery.trim()
        val regex = ScriptureWordSearch.wholeWordRegex(rawQuery)
            ?: return ScriptureWordSearchResult(display, 0, 0, emptyList())
        val books = loadBooks(context, translationId)
        val hits = mutableListOf<ScriptureWordSearchHit>()
        var totalOccurrences = 0
        for (book in books) {
            for (chapter in book.chapters) {
                for (verse in chapter.verses) {
                    val n = ScriptureWordSearch.countMatches(verse.text, regex)
                    if (n > 0) {
                        totalOccurrences += n
                        hits.add(
                            ScriptureWordSearchHit(
                                bookId = book.bookId,
                                bookName = book.bookName,
                                chapter = chapter.chapter,
                                verse = verse.number,
                                text = verse.text,
                                matchesInVerse = n
                            )
                        )
                    }
                }
            }
        }
        return ScriptureWordSearchResult(
            queryDisplay = display,
            totalOccurrences = totalOccurrences,
            versesWithMatches = hits.size,
            hits = hits
        )
    }

    private fun openScriptureJsonStream(context: Context, translationId: String): InputStream? {
        val f = File(context.filesDir, "scripture_cache/$translationId.json")
        if (f.isFile && f.length() > 0L) {
            return FileInputStream(f)
        }
        val assetFile = assetsByTranslation[translationId] ?: return null
        return context.assets.open(assetFile)
    }

    /**
     * Ідэнтыфікатар крыніцы JSON для кэша метаданых (.meta.json).
     * Пры змене файла (або версіі APK для asset) — поўны стрым-парсінг яшчэ раз, потым зноў хуткі чытанне meta.
     */
    private fun scriptureSourceFingerprint(context: Context, translationId: String): String? {
        val f = File(context.filesDir, "scripture_cache/$translationId.json")
        if (f.isFile && f.length() > 0L) {
            return "f:${f.length()}:${f.lastModified()}"
        }
        val assetFile = assetsByTranslation[translationId] ?: return null
        return "a:$assetFile:${BuildConfig.VERSION_CODE}"
    }

    private fun booksMetaCacheFile(context: Context, translationId: String): File =
        File(context.filesDir, "scripture_cache/$translationId.meta.json")

    private fun readBooksMetaCacheIfValid(file: File, fingerprint: String): List<ScriptureBookMeta>? {
        if (!file.isFile || file.length() == 0L) return null
        return runCatching {
            val root = JSONObject(file.readText(Charsets.UTF_8))
            if (root.optString("fp") != fingerprint) return null
            val arr = root.getJSONArray("books")
            buildList {
                for (i in 0 until arr.length()) {
                    val o = arr.getJSONObject(i)
                    add(
                        ScriptureBookMeta(
                            bookId = o.getInt("book_id"),
                            bookName = o.getString("book_name"),
                            chapterCount = o.getInt("chapter_count")
                        )
                    )
                }
            }
        }.getOrNull()
    }

    private fun writeBooksMetaCache(file: File, fingerprint: String, books: List<ScriptureBookMeta>) {
        val arr = JSONArray()
        for (b in books) {
            arr.put(
                JSONObject().apply {
                    put("book_id", b.bookId)
                    put("book_name", b.bookName)
                    put("chapter_count", b.chapterCount)
                }
            )
        }
        val root = JSONObject().apply {
            put("fp", fingerprint)
            put("books", arr)
        }
        file.writeText(root.toString(), Charsets.UTF_8)
    }

    private fun loadBooksMetadata(context: Context, translationId: String): List<ScriptureBookMeta> {
        metaCache[translationId]?.let { return it }
        synchronized(this) {
            metaCache[translationId]?.let { return it }
            val fingerprint = scriptureSourceFingerprint(context, translationId) ?: return emptyList()

            val metaFile = booksMetaCacheFile(context, translationId)
            readBooksMetaCacheIfValid(metaFile, fingerprint)?.let { parsed ->
                metaCache[translationId] = parsed
                return parsed
            }

            val stream = openScriptureJsonStream(context, translationId) ?: return emptyList()
            stream.use { input ->
                val parsed = JsonReader(InputStreamReader(input, StandardCharsets.UTF_8)).use { reader ->
                    parseBooksMetadata(reader)
                }
                metaCache[translationId] = parsed
                runCatching {
                    metaFile.parentFile?.mkdirs()
                    writeBooksMetaCache(metaFile, fingerprint, parsed)
                }
                return parsed
            }
        }
    }

    private fun parseBooksMetadata(reader: JsonReader): List<ScriptureBookMeta> {
        val books = mutableListOf<ScriptureBookMeta>()
        reader.beginObject()
        while (reader.hasNext()) {
            when (reader.nextName()) {
                "books" -> {
                    reader.beginArray()
                    while (reader.hasNext()) {
                        var bookId = 0
                        var bookName = ""
                        var chapterCount = 0
                        reader.beginObject()
                        while (reader.hasNext()) {
                            when (reader.nextName()) {
                                "book_id" -> bookId = reader.nextInt()
                                "book_name" -> bookName = reader.nextString()
                                "chapter_count" -> chapterCount = reader.nextInt()
                                "chapters" -> reader.skipValue()
                                else -> reader.skipValue()
                            }
                        }
                        reader.endObject()
                        books.add(ScriptureBookMeta(bookId, bookName, chapterCount))
                    }
                    reader.endArray()
                }
                else -> reader.skipValue()
            }
        }
        reader.endObject()
        return books
    }

    private fun loadBooks(context: Context, translationId: String): List<ScriptureBookData> {
        cache[translationId]?.let { return it }
        synchronized(this) {
            cache[translationId]?.let { return it }
            val json = loadJsonString(context, translationId) ?: return emptyList()
            val root = JSONObject(json)
            val booksArray = root.getJSONArray("books")
            val parsed = buildList {
                for (i in 0 until booksArray.length()) {
                    val bookObj = booksArray.getJSONObject(i)
                    val chaptersArray = bookObj.getJSONArray("chapters")
                    val chapters = buildList {
                        for (j in 0 until chaptersArray.length()) {
                            val chapterObj = chaptersArray.getJSONObject(j)
                            val versesArray = chapterObj.getJSONArray("verses")
                            val verses = buildList {
                                for (k in 0 until versesArray.length()) {
                                    val verseObj = versesArray.getJSONObject(k)
                                    add(
                                        ScriptureVerseData(
                                            number = verseObj.getInt("verse"),
                                            text = verseObj.getString("text")
                                        )
                                    )
                                }
                            }
                            add(
                                ScriptureChapterData(
                                    chapter = chapterObj.getInt("chapter"),
                                    verses = verses
                                )
                            )
                        }
                    }
                    add(
                        ScriptureBookData(
                            bookId = bookObj.getInt("book_id"),
                            bookName = bookObj.getString("book_name"),
                            chapterCount = bookObj.getInt("chapter_count"),
                            chapters = chapters
                        )
                    )
                }
            }
            cache[translationId] = parsed
            return parsed
        }
    }
}

/** Адна глава ў парадку book_id для плана чытання. */
data class ScriptureChapterRef(
    val bookId: Int,
    val bookTitle: String,
    val chapter: Int
)

data class ScriptureVerse(
    val number: Int,
    val text: String
)

data class VerseComparison(
    val translation: String,
    val text: String
)

private data class ScriptureBookMeta(
    val bookId: Int,
    val bookName: String,
    val chapterCount: Int
)

private data class ScriptureBookData(
    val bookId: Int,
    val bookName: String,
    val chapterCount: Int,
    val chapters: List<ScriptureChapterData>
)

private data class ScriptureChapterData(
    val chapter: Int,
    val verses: List<ScriptureVerseData>
)

private data class ScriptureVerseData(
    val number: Int,
    val text: String
)
