package by.dzmitrypanou.catholicapp.data

import android.content.Context
import java.util.HashSet

class PrayerBookmarksStore(context: Context) {

    private val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun isBookmarked(prayerId: Long): Boolean {
        if (prayerId < 0) return false
        return prefs.getStringSet(KEY_IDS, emptySet())?.contains(prayerId.toString()) == true
    }

    fun getBookmarkedIds(): Set<Long> {
        return getBookmarkedIdsOrdered().toSet()
    }

    fun getBookmarkedIdsOrdered(): List<Long> {
        val ordered = getOrderedKeys()
        val valid = prefs.getStringSet(KEY_IDS, emptySet()).orEmpty()
        return ordered
            .filter { it in valid }
            .mapNotNull { it.toLongOrNull() }
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
    fun getBookmarkedIdsLegacy(): Set<Long> {
        return prefs.getStringSet(KEY_IDS, emptySet())
            ?.mapNotNull { it.toLongOrNull() }
            ?.toSet()
            .orEmpty()
    }

    fun setBookmarked(prayerId: Long, bookmarked: Boolean) {
        if (prayerId < 0) return
        val current = HashSet(prefs.getStringSet(KEY_IDS, emptySet()).orEmpty())
        val key = prayerId.toString()
        if (bookmarked) {
            current.add(key)
        } else {
            current.remove(key)
        }
        val ordered = getOrderedKeys().filter { it != key }.toMutableList()
        if (bookmarked) ordered.add(key)
        prefs.edit()
            .putStringSet(KEY_IDS, HashSet(current))
            .putString(KEY_ORDERED_IDS, ordered.joinToString(ORDER_SEPARATOR))
            .apply()
    }

    fun toggle(prayerId: Long): Boolean {
        val next = !isBookmarked(prayerId)
        setBookmarked(prayerId, next)
        return next
    }

fun retainOnly(validPrayerIds: Set<Long>) {
        val current = HashSet(prefs.getStringSet(KEY_IDS, emptySet()).orEmpty())
        val next = current.filterTo(HashSet()) { idStr -> idStr.toLongOrNull() in validPrayerIds }
        val ordered = getOrderedKeys().filter { it in next }
        prefs.edit()
            .putStringSet(KEY_IDS, next)
            .putString(KEY_ORDERED_IDS, ordered.joinToString(ORDER_SEPARATOR))
            .apply()
    }

    companion object {
        private const val PREFS_NAME = "prayer_bookmarks"
        private const val KEY_IDS = "prayer_ids"
        private const val KEY_ORDERED_IDS = "prayer_ids_ordered"
        private const val ORDER_SEPARATOR = "\n"
    }
}
