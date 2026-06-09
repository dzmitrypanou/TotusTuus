package by.dzmitrypanou.catholicapp.data

import android.content.Context
import java.util.HashSet

class SongbookBookmarksStore(
    context: Context,
    private val catalog: SongbookRepository.Catalog = SongbookRepository.Catalog.SONGBOOK
) {

    private val prefs = context.applicationContext.getSharedPreferences(catalog.prefsName, Context.MODE_PRIVATE)

    fun isBookmarked(entryId: String): Boolean {
        if (entryId.isBlank()) return false
        return prefs.getStringSet(KEY_IDS, emptySet())?.contains(entryId) == true
    }

    fun getBookmarkedIds(): Set<String> =
        getBookmarkedIdsOrdered().toSet()

    fun getBookmarkedIdsOrdered(): List<String> {
        val ordered = getOrderedKeys()
        val valid = prefs.getStringSet(KEY_IDS, emptySet()).orEmpty()
        return ordered.filter { it in valid }
    }

    private fun getOrderedKeys(): List<String> {
        val current = prefs.getStringSet(KEY_IDS, emptySet()).orEmpty()
        val stored = prefs.getString(KEY_ORDERED_IDS, null)
            ?.split(ORDER_SEPARATOR)
            ?.filter { it.isNotBlank() }
            .orEmpty()
        val result = ArrayList<String>()
        val seen = HashSet<String>()
        stored.forEach { key ->
            if (key in current && seen.add(key)) result.add(key)
        }
        current.forEach { key ->
            if (seen.add(key)) result.add(key)
        }
        if (result != stored || current.any { it !in stored }) {
            prefs.edit().putString(KEY_ORDERED_IDS, result.joinToString(ORDER_SEPARATOR)).apply()
        }
        return result
    }

    @Deprecated("Use getBookmarkedIdsOrdered when display order matters")
    fun getBookmarkedIdsLegacy(): Set<String> =
        prefs.getStringSet(KEY_IDS, emptySet()).orEmpty()

    fun setBookmarked(entryId: String, bookmarked: Boolean) {
        if (entryId.isBlank()) return
        val current = HashSet(prefs.getStringSet(KEY_IDS, emptySet()).orEmpty())
        if (bookmarked) {
            current.add(entryId)
        } else {
            current.remove(entryId)
        }
        val ordered = getOrderedKeys().filter { it != entryId }.toMutableList()
        if (bookmarked) ordered.add(entryId)
        prefs.edit()
            .putStringSet(KEY_IDS, HashSet(current))
            .putString(KEY_ORDERED_IDS, ordered.joinToString(ORDER_SEPARATOR))
            .apply()
    }

    fun toggle(entryId: String): Boolean {
        val next = !isBookmarked(entryId)
        setBookmarked(entryId, next)
        return next
    }

fun clearAll() {
        prefs.edit().remove(KEY_IDS).remove(KEY_ORDERED_IDS).apply()
    }

fun retainOnly(validEntryIds: Set<String>) {
        val current = HashSet(prefs.getStringSet(KEY_IDS, emptySet()).orEmpty())
        val next = current.filterTo(HashSet()) { it in validEntryIds }
        val ordered = getOrderedKeys().filter { it in next }
        prefs.edit()
            .putStringSet(KEY_IDS, next)
            .putString(KEY_ORDERED_IDS, ordered.joinToString(ORDER_SEPARATOR))
            .apply()
    }

    companion object {
        private const val KEY_IDS = "entry_ids"
        private const val KEY_ORDERED_IDS = "entry_ids_ordered"
        private const val ORDER_SEPARATOR = "\n"
    }
}

private val SongbookRepository.Catalog.prefsName: String
    get() = when (this) {
        SongbookRepository.Catalog.SONGBOOK -> "songbook_bookmarks"
        SongbookRepository.Catalog.KANTARAL -> "kantaral_bookmarks"
    }
