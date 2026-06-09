package by.dzmitrypanou.catholicapp.data

import android.content.Context

object SongbookLoadConsentStore {
    private const val PREFS = "prayer_app_prefs"
    private const val KEY_SONGBOOK_LOAD_ALLOWED = "songbook_load_allowed"

fun isGranted(context: Context): Boolean =
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(KEY_SONGBOOK_LOAD_ALLOWED, true)

    fun setGranted(context: Context, granted: Boolean) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_SONGBOOK_LOAD_ALLOWED, granted)
            .apply()
    }
}
