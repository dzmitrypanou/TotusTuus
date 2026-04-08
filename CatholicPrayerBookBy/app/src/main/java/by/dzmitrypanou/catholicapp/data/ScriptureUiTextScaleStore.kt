package by.dzmitrypanou.catholicapp.data

import android.content.Context

/**
 * Множнік памеру тэксту для раздзела «Святое Пісанне» (спісы кніг, тэкст вершаў, параўнанне і г.д.).
 */
object ScriptureUiTextScaleStore {

    private const val PREFS_NAME = "ui_text_settings"
    private const val KEY_SCALE = "scripture_ui_scale"
    private const val DEFAULT = 1f
    private const val MIN = 0.85f
    private const val MAX = 1.45f
    private const val STEP = 0.06f

    fun readScale(context: Context): Float {
        val v = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getFloat(KEY_SCALE, DEFAULT)
        return v.coerceIn(MIN, MAX)
    }

    fun adjust(context: Context, directionSign: Float): Float {
        val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val next = (readScale(context) + directionSign * STEP).coerceIn(MIN, MAX)
        prefs.edit().putFloat(KEY_SCALE, next).apply()
        return next
    }

    fun resetToDefault(context: Context) {
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
            .putFloat(KEY_SCALE, DEFAULT)
            .apply()
    }
}
