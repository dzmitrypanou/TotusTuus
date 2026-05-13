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
        prefs.edit().putStringSet(KEY_IDS, HashSet(current)).apply()
    }

    fun toggle(prayerId: Long): Boolean {
        val next = !isBookmarked(prayerId)
        setBookmarked(prayerId, next)
        return next
    }

fun retainOnly(validPrayerIds: Set<Long>) {
        val current = HashSet(prefs.getStringSet(KEY_IDS, emptySet()).orEmpty())
        val next = current.filterTo(HashSet()) { idStr -> idStr.toLongOrNull() in validPrayerIds }
        prefs.edit().putStringSet(KEY_IDS, next).apply()
    }

    companion object {
        private const val PREFS_NAME = "prayer_bookmarks"
        private const val KEY_IDS = "prayer_ids"
    }
}
