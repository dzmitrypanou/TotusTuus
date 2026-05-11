package by.dzmitrypanou.catholicapp.ui.solemnities

import android.content.Context

object SolemnitiesSectionExpandStore {
    private const val PREFS = "solemnities_section_expand"

    fun isExpanded(context: Context, title: String, defaultExpanded: Boolean = true): Boolean {
        val key = title.trim()
        if (key.isBlank()) return defaultExpanded
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(key, defaultExpanded)
    }

    fun setExpanded(context: Context, title: String, expanded: Boolean) {
        val key = title.trim()
        if (key.isBlank()) return
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(key, expanded)
            .apply()
    }
}

