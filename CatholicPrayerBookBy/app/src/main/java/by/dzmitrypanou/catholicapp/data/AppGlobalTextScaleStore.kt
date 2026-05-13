package by.dzmitrypanou.catholicapp.data

import android.content.Context
import android.content.SharedPreferences
import android.content.res.Resources
import kotlin.math.abs

object AppGlobalTextScaleStore {

    private const val PREFS_NAME = "ui_text_settings"
    private const val KEY_STEP_INDEX = "app_text_size_step_index"
    private const val KEY_PERCENT = "app_text_size_percent"

    private const val KEY_LEGACY_SCALE = "app_global_text_scale"

    private const val MIN_STEP_INDEX = 0
    private const val MAX_STEP_INDEX = 4
    private const val DEFAULT_STEP_INDEX = 0
    private const val STEP_INDEX_DELTA = 1
    private const val DEFAULT_BASE_SCALE = 0.925f
    private const val SCALE_STEP_DELTA = 0.25f

    @Volatile
    private var cachedStepIndex: Int? = null

fun readScale(context: Context): Float = stepIndexToScale(readStepIndex(context))

    fun readStepIndex(context: Context): Int {
        cachedStepIndex?.let { return it }
        val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        migrateLegacyIfNeeded(prefs)
        val resolved = prefs.getInt(KEY_STEP_INDEX, DEFAULT_STEP_INDEX).coerceIn(MIN_STEP_INDEX, MAX_STEP_INDEX)
        cachedStepIndex = resolved
        return resolved
    }

    fun readStepNumber(context: Context): Int = readStepIndex(context) + 1

    fun adjust(context: Context, directionSign: Float): Int {
        val app = context.applicationContext
        val prefs = app.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        migrateLegacyIfNeeded(prefs)
        val delta = if (directionSign >= 0f) STEP_INDEX_DELTA else -STEP_INDEX_DELTA
        val next = (readStepIndex(app) + delta).coerceIn(MIN_STEP_INDEX, MAX_STEP_INDEX)
        prefs.edit().putInt(KEY_STEP_INDEX, next).apply()
        cachedStepIndex = next
        syncAdditionalSettingsToMatchGlobal(app, app.resources)
        return next
    }

fun resetTextSizeToDefaults(context: Context) {
        val app = context.applicationContext
        val prefs = app.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        prefs.edit()
            .putInt(KEY_STEP_INDEX, DEFAULT_STEP_INDEX)
            .remove(KEY_LEGACY_SCALE)
            .apply()
        cachedStepIndex = DEFAULT_STEP_INDEX
        syncAdditionalSettingsToMatchGlobal(app, app.resources)
    }

fun syncAdditionalSettingsToMatchGlobal(context: Context, resources: Resources) {
        PrayerBookUiTextScaleStore.resetToDefault(context)
        ScriptureUiTextScaleStore.resetToDefault(context)
        PrayerBodyTextSizeStore.resetStoredToDefault(context)
    }

    private fun migrateLegacyIfNeeded(prefs: SharedPreferences) {
        if (prefs.contains(KEY_STEP_INDEX)) return
        val editor = prefs.edit()
        val resolvedStep = if (prefs.contains(KEY_PERCENT)) {
            val p = prefs.getInt(KEY_PERCENT, 40)
            val scale = percentToScaleLegacy(p)
            val step = nearestStepIndexForScale(scale)
            editor.remove(KEY_PERCENT).putInt(KEY_STEP_INDEX, step)
            step
        } else if (prefs.contains(KEY_LEGACY_SCALE)) {
            val oldScale = prefs.getFloat(KEY_LEGACY_SCALE, 1f).coerceAtLeast(0.01f)
            val step = nearestStepIndexForScale(oldScale)
            editor.putInt(KEY_STEP_INDEX, step).remove(KEY_LEGACY_SCALE)
            step
        } else {
            editor.putInt(KEY_STEP_INDEX, DEFAULT_STEP_INDEX)
            DEFAULT_STEP_INDEX
        }
        editor.apply()
        cachedStepIndex = resolvedStep.coerceIn(MIN_STEP_INDEX, MAX_STEP_INDEX)
    }

    private fun nearestStepIndexForScale(scale: Float): Int {
        val target = scale.coerceAtLeast(0.01f)
        var best = DEFAULT_STEP_INDEX
        var bestDistance = Float.MAX_VALUE
        for (idx in MIN_STEP_INDEX..MAX_STEP_INDEX) {
            val dist = abs(stepIndexToScale(idx) - target)
            if (dist < bestDistance) {
                bestDistance = dist
                best = idx
            }
        }
        return best
    }

    private fun percentToScaleLegacy(percent: Int): Float {
        val p = percent.coerceIn(30, 100)
        return if (p <= 50) {
            0.85f + (p - 30) * (0.15f / 20f)
        } else {
            1.0f + (p - 50) * (0.45f / 50f)
        }
    }

    private fun stepIndexToScale(stepIndex: Int): Float {
        val idx = stepIndex.coerceIn(MIN_STEP_INDEX, MAX_STEP_INDEX)
        return DEFAULT_BASE_SCALE * (1f + idx * SCALE_STEP_DELTA)
    }
}
