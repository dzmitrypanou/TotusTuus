package by.dzmitrypanou.catholicapp.data

import android.content.Context

/** Сігнал для [by.dzmitrypanou.catholicapp.ui.transform.TransformFragment]: прымусова абнавіць з сервера. */
object PrayerRefreshRequestStore {

    private const val PREFS = "prayer_app_prefs"
    private const val KEY_PENDING = "pending_force_refresh_prayers"

    fun setPendingRefresh(context: Context) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_PENDING, true)
            .apply()
    }

    fun consumePendingRefresh(context: Context): Boolean {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (!prefs.getBoolean(KEY_PENDING, false)) return false
        prefs.edit().putBoolean(KEY_PENDING, false).apply()
        return true
    }
}
