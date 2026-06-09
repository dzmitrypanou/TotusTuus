package by.dzmitrypanou.catholicapp.sync

import android.content.Context

object AppUpdateCheckStore {

    private const val PREFS = "app_update_check_prefs"
    private const val KEY_ENABLED = "enabled"
    private const val KEY_LAST_NOTIFIED_VERSION_CODE = "last_notified_version_code"
    private const val KEY_NOTIFICATION_PERMISSION_PROMPTED = "notification_permission_prompted"

    fun isEnabled(context: Context): Boolean =
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(KEY_ENABLED, true)

    fun setEnabled(context: Context, enabled: Boolean) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_ENABLED, enabled)
            .apply()
    }

    fun lastNotifiedVersionCode(context: Context): Int =
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getInt(KEY_LAST_NOTIFIED_VERSION_CODE, 0)

    fun markNotified(context: Context, versionCode: Int) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putInt(KEY_LAST_NOTIFIED_VERSION_CODE, versionCode)
            .apply()
    }

    fun wasNotificationPermissionPrompted(context: Context): Boolean =
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(KEY_NOTIFICATION_PERMISSION_PROMPTED, false)

    fun markNotificationPermissionPrompted(context: Context) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_NOTIFICATION_PERMISSION_PROMPTED, true)
            .apply()
    }
}
