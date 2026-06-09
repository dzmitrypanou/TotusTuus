package by.dzmitrypanou.catholicapp.data

import android.content.Context

object PrayerAutoUpdateConsentStore {

    private const val PREFS = "prayer_app_prefs"
    private const val KEY_AUTO_UPDATE = "auto_update_prayers_consent"

    fun isGranted(context: Context): Boolean {
        return context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(KEY_AUTO_UPDATE, true)
    }

    fun setGranted(context: Context, granted: Boolean) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_AUTO_UPDATE, granted)
            .apply()
    }
}
