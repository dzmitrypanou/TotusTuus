package by.dzmitrypanou.catholicapp.data

import android.content.ComponentName
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.appcompat.app.AppCompatActivity
import by.dzmitrypanou.catholicapp.R

object AppColorSchemeStore {

    private const val PREFS_NAME = "ui_text_settings"
    private const val KEY_COLOR_SCHEME = "app_color_scheme"
    private const val KEY_LAUNCHER_SYNC_PENDING = "launcher_icon_sync_pending"
    private const val LAUNCHER_DARK_ALIAS_SUFFIX = ".LauncherDark"
    private const val LAUNCHER_LIGHT_ALIAS_SUFFIX = ".LauncherLight"

    enum class Scheme(val storageKey: String, val themeResId: Int, val prefersLightSystemBars: Boolean) {
        DARK("current", R.style.Theme_CatholicPrayerBookBy_NoActionBar, false),
        LIGHT("beige", R.style.Theme_CatholicPrayerBookBy_NoActionBar_Beige, true);

        companion object {
            fun fromStorage(value: String?): Scheme {
                val raw = (value ?: "").trim().lowercase()
                return when (raw) {
                    "beige", "white", "light" -> LIGHT
                    "current", "dark", "" -> DARK
                    else -> DARK
                }
            }
        }
    }

    @Volatile
    private var cachedScheme: Scheme? = null

    fun readScheme(context: Context): Scheme {
        cachedScheme?.let { return it }
        val value = context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_COLOR_SCHEME, Scheme.DARK.storageKey)
        val resolved = Scheme.fromStorage(value)
        cachedScheme = resolved
        return resolved
    }

    fun writeScheme(context: Context, scheme: Scheme) {
        context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_COLOR_SCHEME, scheme.storageKey)
            .putBoolean(KEY_LAUNCHER_SYNC_PENDING, true)
            .apply()
        cachedScheme = scheme
    }

    fun applyActivityTheme(activity: AppCompatActivity): Scheme {
        val scheme = readScheme(activity)
        activity.setTheme(scheme.themeResId)
        return scheme
    }

    fun syncLauncherIcon(context: Context) {
        syncLauncherIcon(context, readScheme(context))
        context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_LAUNCHER_SYNC_PENDING, false)
            .apply()
    }

    fun syncLauncherIconIfPending(context: Context) {
        val pending = context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getBoolean(KEY_LAUNCHER_SYNC_PENDING, false)
        if (!pending) return
        syncLauncherIcon(context)
    }

    private fun syncLauncherIcon(context: Context, scheme: Scheme) {
        val appContext = context.applicationContext
        val packageManager = appContext.packageManager
        val darkAlias = ComponentName(appContext, appContext.packageName + LAUNCHER_DARK_ALIAS_SUFFIX)
        val lightAlias = ComponentName(appContext, appContext.packageName + LAUNCHER_LIGHT_ALIAS_SUFFIX)
        val useLight = scheme == Scheme.LIGHT
        val flags = PackageManager.DONT_KILL_APP or
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) PackageManager.SYNCHRONOUS else 0

        // Enable the target first, then disable the other alias to avoid launcher gaps.
        val targetAlias = if (useLight) lightAlias else darkAlias
        val otherAlias = if (useLight) darkAlias else lightAlias
        packageManager.setComponentEnabledSetting(
            targetAlias,
            PackageManager.COMPONENT_ENABLED_STATE_ENABLED,
            flags,
        )
        packageManager.setComponentEnabledSetting(
            otherAlias,
            PackageManager.COMPONENT_ENABLED_STATE_DISABLED,
            flags,
        )
    }
}
