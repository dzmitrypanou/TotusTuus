package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import android.content.SharedPreferences

/**
 * Пазнакі «дзень плана (1…365) выкананы» для бягучай сесіі плана (якар дзён старту + тып плана).
 * Старыя даныя ў фармаце y_YEAR мігруюць адной копіяй пры першым адкрыцці пасля абнаўлення.
 * Ключ a_ANCHOR без суфікса (стары фармат) мігруе ў a_ANCHOR_linear.
 */
object ScriptureReadingPlanCompletionStore {

    private const val PREFS = "scripture_reading_plan_done"

    private fun keySession(anchorDayMs: Long, kindKey: String) = "a_${anchorDayMs}_$kindKey"

    private fun keyLegacyAnchorOnly(anchorDayMs: Long) = "a_$anchorDayMs"

    private fun yearKey(year: Int) = "y_$year"

    private fun loadMutableSet(
        prefs: SharedPreferences,
        anchorDayMs: Long,
        kind: ScriptureReadingPlanKind
    ): MutableSet<String> {
        val k = keySession(anchorDayMs, kind.storageKey)
        val cur = prefs.getStringSet(k, null)
        if (cur != null) return cur.toMutableSet()
        if (kind == ScriptureReadingPlanKind.LINEAR) {
            val legacy = prefs.getStringSet(keyLegacyAnchorOnly(anchorDayMs), null)
            if (legacy != null) {
                val copy = LinkedHashSet(legacy)
                prefs.edit()
                    .putStringSet(k, copy)
                    .remove(keyLegacyAnchorOnly(anchorDayMs))
                    .apply()
                return copy.toMutableSet()
            }
        }
        return mutableSetOf()
    }

    fun migrateFromLegacyYearIfNeeded(
        context: Context,
        calendarYear: Int,
        planAnchorDayMs: Long
    ) {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val sessionKey = keySession(planAnchorDayMs, ScriptureReadingPlanKind.LINEAR.storageKey)
        if (prefs.contains(sessionKey)) return
        val legacy = prefs.getStringSet(yearKey(calendarYear), null) ?: return
        if (legacy.isEmpty()) return
        prefs.edit().putStringSet(sessionKey, LinkedHashSet(legacy)).apply()
    }

    fun markDayDone(
        context: Context,
        planAnchorDayMs: Long,
        planDayIndex0Based: Int,
        kind: ScriptureReadingPlanKind
    ) {
        if (planDayIndex0Based !in 0 until ScriptureYearReadingPlan.PLAN_LENGTH) return
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val set = loadMutableSet(prefs, planAnchorDayMs, kind)
        set.add(planDayIndex0Based.toString())
        prefs.edit().putStringSet(keySession(planAnchorDayMs, kind.storageKey), set).apply()
    }

    fun markDayUndone(
        context: Context,
        planAnchorDayMs: Long,
        planDayIndex0Based: Int,
        kind: ScriptureReadingPlanKind
    ) {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val set = loadMutableSet(prefs, planAnchorDayMs, kind)
        set.remove(planDayIndex0Based.toString())
        prefs.edit().putStringSet(keySession(planAnchorDayMs, kind.storageKey), set).apply()
    }

    fun isDayDone(
        context: Context,
        planAnchorDayMs: Long,
        planDayIndex0Based: Int,
        kind: ScriptureReadingPlanKind
    ): Boolean {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val set = loadMutableSet(prefs, planAnchorDayMs, kind)
        return set.contains(planDayIndex0Based.toString())
    }

    fun completedCountForSession(
        context: Context,
        planAnchorDayMs: Long,
        kind: ScriptureReadingPlanKind
    ): Int {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        return loadMutableSet(prefs, planAnchorDayMs, kind).size
    }
}
