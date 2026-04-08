package by.dzmitrypanou.catholicapp.data

import android.content.Context
import java.util.HashSet

class SongbookBookmarksStore(context: Context) {

    private val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun isBookmarked(entryId: String): Boolean {
        if (entryId.isBlank()) return false
        return prefs.getStringSet(KEY_IDS, emptySet())?.contains(entryId) == true
    }

    fun getBookmarkedIds(): Set<String> =
        prefs.getStringSet(KEY_IDS, emptySet()).orEmpty()

    fun setBookmarked(entryId: String, bookmarked: Boolean) {
        if (entryId.isBlank()) return
        val current = HashSet(prefs.getStringSet(KEY_IDS, emptySet()).orEmpty())
        if (bookmarked) {
            current.add(entryId)
        } else {
            current.remove(entryId)
        }
        prefs.edit().putStringSet(KEY_IDS, HashSet(current)).apply()
    }

    fun toggle(entryId: String): Boolean {
        val next = !isBookmarked(entryId)
        setBookmarked(entryId, next)
        return next
    }

    /** Пасля поўнага скіду лакальных дадзеных разам з кэшам спеўніка. */
    fun clearAll() {
        prefs.edit().remove(KEY_IDS).apply()
    }

    /** Пасля абнаўлення з сервера: прыбраць закладкі на запісы, якіх ужо няма. */
    fun retainOnly(validEntryIds: Set<String>) {
        val current = HashSet(prefs.getStringSet(KEY_IDS, emptySet()).orEmpty())
        val next = current.filterTo(HashSet()) { it in validEntryIds }
        prefs.edit().putStringSet(KEY_IDS, next).apply()
    }

    companion object {
        private const val PREFS_NAME = "songbook_bookmarks"
        private const val KEY_IDS = "entry_ids"
    }
}
