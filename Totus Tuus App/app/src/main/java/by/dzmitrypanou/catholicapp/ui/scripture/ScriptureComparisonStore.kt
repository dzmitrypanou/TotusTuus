package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

data class ComparisonVerseRef(
    val bookId: Int,
    val bookTitle: String,
    val chapter: Int,
    val verse: Int
) {
    val key: String get() = "$bookId|$chapter|$verse"
}

object ScriptureComparisonStore {
    private const val PREFS = "scripture_compare_store"
    private const val KEY_VERSES = "compare_verses"
    private const val KEY_TRANSLATIONS = "compare_translations"
    private const val KEY_HINT_DISMISSED = "compare_hint_dismissed"
    private const val KEY_TRANSLATIONS_EXPANDED = "compare_translations_expanded"

    fun allVerses(context: Context): List<ComparisonVerseRef> {
        val raw = prefs(context).getString(KEY_VERSES, "[]").orEmpty()
        return runCatching {
            val array = JSONArray(raw)
            buildList {
                for (i in 0 until array.length()) {
                    val o = array.getJSONObject(i)
                    add(
                        ComparisonVerseRef(
                            bookId = o.optInt("bookId", -1),
                            bookTitle = o.optString("bookTitle"),
                            chapter = o.optInt("chapter", 1),
                            verse = o.optInt("verse", 1)
                        )
                    )
                }
            }.filter { it.bookId >= 0 && it.bookTitle.isNotBlank() }
        }.getOrDefault(emptyList())
    }

    fun isInComparison(context: Context, ref: ComparisonVerseRef): Boolean =
        allVerses(context).any { it.key == ref.key }

    fun toggleVerse(context: Context, ref: ComparisonVerseRef): Boolean {
        val current = allVerses(context).toMutableList()
        val idx = current.indexOfFirst { it.key == ref.key }
        val added = idx < 0
        if (added) current.add(ref) else current.removeAt(idx)
        saveVerses(context, current)
        return added
    }

    fun clearVerses(context: Context) {
        prefs(context).edit().putString(KEY_VERSES, "[]").apply()
    }

    fun selectedTranslationIds(context: Context): Set<String> {
        val raw = prefs(context).getString(KEY_TRANSLATIONS, null)
        if (raw.isNullOrBlank()) return ScriptureCatalog.allTranslations().map { it.id }.toSet()
        return runCatching {
            val arr = JSONArray(raw)
            buildSet {
                for (i in 0 until arr.length()) add(arr.getString(i))
            }
        }.getOrDefault(emptySet()).ifEmpty {
            ScriptureCatalog.allTranslations().map { it.id }.toSet()
        }
    }

    fun setSelectedTranslationIds(context: Context, ids: Set<String>) {
        val safeIds = ids.ifEmpty { ScriptureCatalog.allTranslations().map { it.id }.toSet() }
        val arr = JSONArray().apply { safeIds.forEach { put(it) } }
        prefs(context).edit().putString(KEY_TRANSLATIONS, arr.toString()).apply()
    }

    fun isHintDismissed(context: Context): Boolean = prefs(context).getBoolean(KEY_HINT_DISMISSED, false)

    fun dismissHint(context: Context) {
        prefs(context).edit().putBoolean(KEY_HINT_DISMISSED, true).apply()
    }

    fun isTranslationsExpanded(context: Context): Boolean =
        prefs(context).getBoolean(KEY_TRANSLATIONS_EXPANDED, false)

    fun setTranslationsExpanded(context: Context, expanded: Boolean) {
        prefs(context).edit().putBoolean(KEY_TRANSLATIONS_EXPANDED, expanded).apply()
    }

    private fun saveVerses(context: Context, verses: List<ComparisonVerseRef>) {
        val arr = JSONArray().apply {
            verses.forEach {
                put(
                    JSONObject()
                        .put("bookId", it.bookId)
                        .put("bookTitle", it.bookTitle)
                        .put("chapter", it.chapter)
                        .put("verse", it.verse)
                )
            }
        }
        prefs(context).edit().putString(KEY_VERSES, arr.toString()).apply()
    }

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
}
