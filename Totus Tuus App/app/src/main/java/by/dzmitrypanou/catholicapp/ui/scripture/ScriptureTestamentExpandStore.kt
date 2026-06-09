package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import by.dzmitrypanou.catholicapp.R

object ScriptureTestamentExpandStore {
    private const val PREFS = "scripture_testament_expand"
    private const val KEY_NT = "nt"
    private const val KEY_OT = "ot"

    fun preferenceKeyForTitle(context: Context, title: String): String {
        val nt = context.getString(R.string.scripture_new_testament_title)
        val ot = context.getString(R.string.scripture_old_testament_title)
        return when (title) {
            nt, "Новый завет" -> KEY_NT
            ot, "Старый завет" -> KEY_OT
            else -> "t_${title.hashCode()}"
        }
    }

    fun isExpanded(context: Context, key: String, defaultExpanded: Boolean = false): Boolean =
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(key, defaultExpanded)

    fun setExpanded(context: Context, key: String, expanded: Boolean) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(key, expanded)
            .apply()
    }
}
