package by.dzmitrypanou.catholicapp.data

import android.content.Context
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import by.dzmitrypanou.catholicapp.data.remote.SongbookDto
import com.google.gson.GsonBuilder
import com.google.gson.JsonDeserializer
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.sync.withPermit
import kotlinx.coroutines.withContext
import java.io.File
import java.security.MessageDigest
import java.util.concurrent.atomic.AtomicInteger

class SongbookRepository(
    private val context: Context,
    private val catalog: Catalog = Catalog.SONGBOOK
) {
    data class SyncProgress(val done: Int, val total: Int)

    enum class Catalog {
        SONGBOOK,
        KANTARAL
    }

    private val refreshMutex = Mutex()

    private val appContext = context.applicationContext
    private val gson = GsonBuilder()
        .registerTypeAdapter(
            SongbookContentType::class.java,
            JsonDeserializer { el, _, _ ->
                when (el.asString.uppercase()) {
                    "IMAGE" -> SongbookContentType.IMAGE
                    "PDF" -> SongbookContentType.IMAGE
                    else -> SongbookContentType.TEXT
                }
            }
        )
        .create()
    private val prefs = appContext.getSharedPreferences(catalog.prefsName, Context.MODE_PRIVATE)
    private val rootDir = File(appContext.filesDir, catalog.dirName)
    private val mediaDir = File(rootDir, "media")

    init {
        rootDir.mkdirs()
        mediaDir.mkdirs()
    }

    fun mediaFile(fileName: String): File = File(mediaDir, fileName)

    fun getCachedEntries(): List<SongbookEntry> {
        val json = prefs.getString(KEY_ENTRIES, null) ?: return emptyList()
        val type = object : TypeToken<List<SongbookEntry>>() {}.type
        return gson.fromJson<List<SongbookEntry>>(json, type) ?: emptyList()
    }

    fun getCachedEntriesSorted(): List<SongbookEntry> =
        getCachedEntries().sortedWith(songbookOrderComparator)

    fun getCachedContentHash(): String? = prefs.getString(KEY_HASH, null)

    suspend fun getRemoteContentHashOrNull(allowNetwork: Boolean = false): String? =
        withContext(Dispatchers.IO) {
            if (!allowNetwork) return@withContext null
            runCatching { catalog.remoteHash() }.getOrNull()
        }

    /** Скід кэша спеўніка: JSON, хэш, файлы выяў, закладкі на песні. */
    fun clearCache() {
        prefs.edit().clear().apply()
            if (catalog == Catalog.SONGBOOK) {
                SongbookBookmarksStore(appContext).clearAll()
            }
        runCatching {
            mediaDir.listFiles()?.forEach { f ->
                if (f.isFile) f.delete()
            }
            val legacy = File(rootDir, "index.json")
            if (legacy.exists()) legacy.delete()
        }
    }

    /**
     * Кэш з сервера. Без сеткі — толькі лакальнае.
     * З [allowNetwork]: [refreshFromApi] з хэшам — поўны JSON толькі калі змест на серверы змяніўся або трэба дакачаць медыя.
     */
    suspend fun getEntries(
        forceRefresh: Boolean = false,
        allowNetwork: Boolean = false
    ): List<SongbookEntry> = withContext(Dispatchers.IO) {
        val cached = getCachedEntriesSorted()
        if (!forceRefresh || !allowNetwork) {
            return@withContext cached
        }
        refreshFromApi(cached, allowHashShortCircuit = true, allowNetwork = true)
    }

    /**
     * Хэш з [songbook_hash.php]: пры супадзенні з кэшам і цэласці файлаў — поўны [songbook.php] не запытваецца.
     */
    suspend fun refreshFromApi(
        existingLocal: List<SongbookEntry> = getCachedEntries(),
        allowHashShortCircuit: Boolean = true,
        allowNetwork: Boolean = false,
        onProgress: ((SyncProgress) -> Unit)? = null
    ): List<SongbookEntry> = refreshMutex.withLock {
        withContext(Dispatchers.IO) {
        if (!allowNetwork) {
            return@withContext existingLocal.sortedWith(songbookOrderComparator)
        }
        val remoteHash = runCatching {
            catalog.remoteHash()
        }.getOrNull()

        if (allowHashShortCircuit && remoteHash != null) {
            val cachedHash = prefs.getString(KEY_HASH, null)
            if (cachedHash == remoteHash && existingLocal.isNotEmpty()) {
                if (cachedSongbookMediaFilesIntact(existingLocal)) {
                    onProgress?.invoke(SyncProgress(existingLocal.size, existingLocal.size))
                    return@withContext existingLocal.sortedWith(songbookOrderComparator)
                }
            }
        }

        val dtos = runCatching { catalog.remoteEntries() }.getOrElse {
            return@withContext existingLocal.sortedWith(songbookOrderComparator)
        }

        val oldRevisions = readMediaRevisionMap()
        val total = dtos.size.coerceAtLeast(1)
        onProgress?.invoke(SyncProgress(0, total))

        val oldRevReadOnly: Map<String, String> = oldRevisions
        val doneCounter = AtomicInteger(0)
        val mediaSemaphore = Semaphore(MAX_PARALLEL_SONGBOOK_MEDIA_DOWNLOADS)
        val indexedRows = coroutineScope {
            dtos.mapIndexed { idx, dto ->
                async {
                    mediaSemaphore.withPermit {
                        val row = syncOneSongbookDto(dto, oldRevReadOnly)
                        val d = doneCounter.incrementAndGet()
                        onProgress?.invoke(SyncProgress(d, total))
                        idx to row
                    }
                }
            }.awaitAll()
        }
        val orderedRows = indexedRows.sortedBy { it.first }.map { it.second }

        val result = ArrayList<SongbookEntry>(orderedRows.size)
        val newRevisions = HashMap<String, String>()
        val neededMedia = HashSet<String>()
        for (row in orderedRows) {
            result.add(row.entry)
            newRevisions.putAll(row.revisionDeltas)
            row.neededMediaName?.let { neededMedia.add(it) }
        }

        val activeIds = result.map { it.id }.toSet()
        newRevisions.keys.retainAll { it in activeIds }
        for (e in result) {
            if (e.contentType != SongbookContentType.TEXT && e.id !in newRevisions) {
                newRevisions[e.id] = oldRevisions[e.id].orEmpty()
            }
        }

        mediaDir.listFiles()?.forEach { f ->
            if (f.isFile && f.name.startsWith("sb_") && f.name !in neededMedia) {
                f.delete()
            }
        }

        val hashToStore = remoteHash ?: computeEntriesHash(result)
        prefs.edit()
            .putString(KEY_ENTRIES, gson.toJson(result))
            .putString(KEY_HASH, hashToStore)
            .putString(KEY_MEDIA_REV, gson.toJson(newRevisions))
            .apply()

        if (catalog == Catalog.SONGBOOK) {
            SongbookBookmarksStore(appContext).retainOnly(activeIds)
        }
        migrateAwayFromLegacyIndexJson()
        SongbookCacheInvalidationNotifier.notifySongbookSyncFinished()
        result.sortedWith(songbookOrderComparator)
        }
    }

    fun getEntriesByIds(ids: Collection<String>): List<SongbookEntry> {
        if (ids.isEmpty()) return emptyList()
        val idSet = ids.toSet()
        return getCachedEntries()
            .filter { it.id in idSet }
            .sortedWith(songbookOrderComparator)
    }

    fun getById(id: String): SongbookEntry? =
        getCachedEntries().firstOrNull { it.id == id }

    private fun migrateAwayFromLegacyIndexJson() {
        val legacy = File(rootDir, "index.json")
        if (legacy.exists()) {
            legacy.delete()
        }
    }

    private fun readMediaRevisionMap(): MutableMap<String, String> {
        val json = prefs.getString(KEY_MEDIA_REV, null) ?: return mutableMapOf()
        val type = object : TypeToken<MutableMap<String, String>>() {}.type
        return gson.fromJson<MutableMap<String, String>>(json, type) ?: mutableMapOf()
    }

    private fun downloadToFile(url: String, dest: File): Boolean =
        PrayerApiClient.downloadBinaryToFile(url, dest)

    private data class SongbookSyncRow(
        val entry: SongbookEntry,
        val revisionDeltas: Map<String, String>,
        val neededMediaName: String?
    )

    /** Адна пазіцыя каталога: медыя качаецца тут — выклікаецца паралельна ў межах [MAX_PARALLEL_SONGBOOK_MEDIA_DOWNLOADS]. */
    private fun syncOneSongbookDto(
        dto: SongbookDto,
        oldRevisions: Map<String, String>
    ): SongbookSyncRow {
        when (dto.contentType()) {
            SongbookContentType.TEXT ->
                return SongbookSyncRow(dto.toEntry(null), emptyMap(), null)
            SongbookContentType.IMAGE -> {
                val url = dto.mediaUrl?.trim().orEmpty()
                if (url.isEmpty()) {
                    return SongbookSyncRow(dto.toEntry(null), emptyMap(), null)
                }
                val mediaName = localMediaFileName(dto.id, url)
                val dest = File(mediaDir, mediaName)
                val idStr = dto.id.toString()
                val revRemote = dto.mediaRevision?.trim().orEmpty()
                val revStored = oldRevisions[idStr].orEmpty()
                val needDownload =
                    !dest.exists() || revRemote.isEmpty() || revRemote != revStored
                val deltas = HashMap<String, String>()
                if (needDownload) {
                    val ok = downloadToFile(
                        PrayerApiClient.absoluteUrlForSitePath(url),
                        dest
                    )
                    if (ok) {
                        deltas[idStr] = revRemote.ifEmpty { sha256File(dest) }
                    } else if (dest.isFile && dest.length() > 0L) {
                        deltas[idStr] = revStored
                    }
                } else {
                    deltas[idStr] = revRemote.ifEmpty { revStored }
                }
                if (!dest.isFile || dest.length() == 0L) {
                    runCatching { dest.delete() }
                    deltas.remove(idStr)
                    return SongbookSyncRow(dto.toEntry(null), deltas, null)
                }
                return SongbookSyncRow(dto.toEntry(mediaName), deltas, mediaName)
            }
        }
    }

    /** Калі ў кэшы ёсць выявы без файлаў — нельга скакаць поўную сінхранізацыю па хэшы. */
    private fun cachedSongbookMediaFilesIntact(entries: List<SongbookEntry>): Boolean {
        for (e in entries) {
            if (e.contentType == SongbookContentType.TEXT) continue
            val name = e.mediaFileName ?: return false
            val f = mediaFile(name)
            if (!f.isFile || f.length() == 0L) return false
        }
        return true
    }

    private fun sha256File(file: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { stream ->
            val buf = ByteArray(8192)
            while (true) {
                val n = stream.read(buf)
                if (n <= 0) break
                md.update(buf, 0, n)
            }
        }
        return md.digest().joinToString("") { "%02x".format(it) }
    }

    private fun localMediaFileName(id: Long, mediaUrl: String): String {
        val path = mediaUrl.substringAfterLast('/').substringBefore('?')
        val ext = when {
            path.contains('.') -> path.substringAfterLast('.').lowercase().take(8)
            else -> "jpg"
        }
        val safeExt = ext.filter { it.isLetterOrDigit() }
        val e = if (safeExt.isNotEmpty()) safeExt else "jpg"
        return "${catalog.mediaPrefix}_${id}.$e"
    }

    private fun computeEntriesHash(entries: List<SongbookEntry>): String {
        val payload = entries
            .sortedWith(songbookOrderComparator)
            .joinToString("||") { e ->
                listOf(
                    e.id,
                    e.title,
                    e.categorySortKey(),
                    e.chapterMajor.toString(),
                    e.subchapter?.toString().orEmpty(),
                    e.contentType.name,
                    e.textBody,
                    e.mediaFileName.orEmpty(),
                    e.sortOrder.toString()
                ).joinToString("|")
            }
        val md = MessageDigest.getInstance("SHA-256").digest(payload.toByteArray(Charsets.UTF_8))
        return md.joinToString("") { "%02x".format(it) }
    }

    companion object {
        private const val MAX_PARALLEL_SONGBOOK_MEDIA_DOWNLOADS = 6

        private const val KEY_ENTRIES = "entries_json"
        private const val KEY_HASH = "content_hash"
        private const val KEY_MEDIA_REV = "media_revision_json"

        private val songbookOrderComparator = SongbookEntry.DISPLAY_ORDER
    }
}

private val SongbookRepository.Catalog.prefsName: String
    get() = when (this) {
        SongbookRepository.Catalog.SONGBOOK -> "songbook_cache"
        SongbookRepository.Catalog.KANTARAL -> "kantaral_cache"
    }

private val SongbookRepository.Catalog.dirName: String
    get() = when (this) {
        SongbookRepository.Catalog.SONGBOOK -> "songbook"
        SongbookRepository.Catalog.KANTARAL -> "kantaral"
    }

private val SongbookRepository.Catalog.mediaPrefix: String
    get() = when (this) {
        SongbookRepository.Catalog.SONGBOOK -> "sb"
        SongbookRepository.Catalog.KANTARAL -> "kt"
    }

private suspend fun SongbookRepository.Catalog.remoteHash(): String = when (this) {
    SongbookRepository.Catalog.SONGBOOK -> PrayerApiClient.service.getSongbookContentHash().hash
    SongbookRepository.Catalog.KANTARAL -> PrayerApiClient.service.getKantaralContentHash().hash
}

private suspend fun SongbookRepository.Catalog.remoteEntries(): List<SongbookDto> = when (this) {
    SongbookRepository.Catalog.SONGBOOK -> PrayerApiClient.service.getSongbook()
    SongbookRepository.Catalog.KANTARAL -> PrayerApiClient.service.getKantaral()
}
