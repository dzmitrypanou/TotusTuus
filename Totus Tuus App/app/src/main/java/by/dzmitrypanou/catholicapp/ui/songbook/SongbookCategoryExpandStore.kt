package by.dzmitrypanou.catholicapp.ui.songbook

import android.content.Context

object SongbookCategoryExpandStore {
    private const val PREFS = "songbook_category_expand"

const val GROUP_KEY_UNCATEGORIZED: String = "__uncategorized__"

    fun preferenceKeyForGroup(groupKey: String): String =
        when (groupKey) {
            GROUP_KEY_UNCATEGORIZED -> "sb_u"
            else -> "sb_${groupKey.hashCode()}"
        }

    fun isExpanded(context: Context, groupKey: String, defaultExpanded: Boolean = false): Boolean {
        val key = preferenceKeyForGroup(groupKey)
        return context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(key, defaultExpanded)
    }

    fun setExpanded(context: Context, groupKey: String, expanded: Boolean) {
        val key = preferenceKeyForGroup(groupKey)
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(key, expanded)
            .apply()
    }
}
