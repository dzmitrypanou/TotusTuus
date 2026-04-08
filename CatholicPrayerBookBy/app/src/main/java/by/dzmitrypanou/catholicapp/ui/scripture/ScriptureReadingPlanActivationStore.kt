package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import java.util.Calendar

/**
 * Стан плана чытання: пачаці / прыпыненне нагадванняў / адмова.
 * Якар — пачатак мясцовага каляндарнага дня старту; ад яго лічыцца «дзень 1, 2…».
 */
object ScriptureReadingPlanActivationStore {

    private const val PREFS = "scripture_reading_plan_activation"
    private const val KEY_ACTIVE = "active"
    private const val KEY_PAUSED = "paused"
    private const val KEY_PLAN_ANCHOR_DAY_MS = "plan_anchor_day_ms"
    private const val KEY_PLAN_KIND = "plan_kind"

    private fun startOfLocalDayMillis(): Long {
        val c = Calendar.getInstance()
        c.set(Calendar.HOUR_OF_DAY, 0)
        c.set(Calendar.MINUTE, 0)
        c.set(Calendar.SECOND, 0)
        c.set(Calendar.MILLISECOND, 0)
        return c.timeInMillis
    }

    fun getPlanAnchorDayMillis(context: Context): Long? {
        val p = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (!p.contains(KEY_PLAN_ANCHOR_DAY_MS)) return null
        val v = p.getLong(KEY_PLAN_ANCHOR_DAY_MS, 0L)
        return if (v > 0L) v else null
    }

    /**
     * Для ўжо актыўнага плана без якасці (абноўленне дадатка): якар такі,
     * каб бягучы «дзень плана» супаў з ранейшым каляндарным індэксам 0…364.
     */
    fun ensurePlanAnchorForActive(context: Context) {
        val app = context.applicationContext
        val p = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (!p.getBoolean(KEY_ACTIVE, false)) return
        if (!p.contains(KEY_PLAN_KIND)) {
            p.edit().putString(KEY_PLAN_KIND, ScriptureReadingPlanKind.LINEAR.storageKey).apply()
        }
        if (p.contains(KEY_PLAN_ANCHOR_DAY_MS)) return
        val calIdx = ScriptureYearReadingPlan.planDayIndexFromCalendar()
        val c = Calendar.getInstance()
        c.set(Calendar.HOUR_OF_DAY, 0)
        c.set(Calendar.MINUTE, 0)
        c.set(Calendar.SECOND, 0)
        c.set(Calendar.MILLISECOND, 0)
        c.add(Calendar.DAY_OF_YEAR, -calIdx)
        val anchorMs = c.timeInMillis
        p.edit().putLong(KEY_PLAN_ANCHOR_DAY_MS, anchorMs).apply()
        ScriptureReadingPlanCompletionStore.migrateFromLegacyYearIfNeeded(
            app,
            ScriptureYearReadingPlan.currentCalendarYear(),
            anchorMs
        )
    }

    fun getPlanKind(context: Context): ScriptureReadingPlanKind {
        val p = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        return ScriptureReadingPlanKind.fromStorage(p.getString(KEY_PLAN_KIND, null))
    }

    fun isPlanStarted(context: Context): Boolean {
        return context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(KEY_ACTIVE, false)
    }

    fun isRemindersPaused(context: Context): Boolean {
        return context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(KEY_PAUSED, false)
    }

    fun shouldScheduleReminders(context: Context): Boolean =
        isPlanStarted(context) && !isRemindersPaused(context)

    fun startPlan(context: Context, kind: ScriptureReadingPlanKind = ScriptureReadingPlanKind.LINEAR) {
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putBoolean(KEY_ACTIVE, true)
            .putBoolean(KEY_PAUSED, false)
            .putLong(KEY_PLAN_ANCHOR_DAY_MS, startOfLocalDayMillis())
            .putString(KEY_PLAN_KIND, kind.storageKey)
            .apply()
    }

    fun setRemindersPaused(context: Context, paused: Boolean) {
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putBoolean(KEY_PAUSED, paused)
            .apply()
    }

    fun declinePlan(context: Context) {
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putBoolean(KEY_ACTIVE, false)
            .putBoolean(KEY_PAUSED, false)
            .remove(KEY_PLAN_ANCHOR_DAY_MS)
            .remove(KEY_PLAN_KIND)
            .apply()
    }
}
