package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import java.util.Calendar
import java.util.TimeZone
import kotlin.math.ceil

object ScriptureYearReadingPlan {

    const val PLAN_LENGTH: Int = 365

private val CHRONOLOGICAL_BOOK_ORDER: List<Int> = listOf(
        1, 18,
        2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16,
        77, 73, 17,
        19, 20, 21, 22,
        75, 76,
        23, 24, 70, 25, 26, 27,
        28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39,
        67, 68,
        40, 41, 42, 43, 44,
        52, 53, 48, 46, 47, 45, 49, 50, 51, 57, 54, 56, 55,
        58, 59, 60, 65, 61, 62, 63, 64, 66
    )

fun planDayIndexFromCalendar(): Int {
        val cal = Calendar.getInstance()
        val dayOfYear = cal.get(Calendar.DAY_OF_YEAR)
        return (dayOfYear - 1).coerceIn(0, PLAN_LENGTH - 1)
    }

fun startOfLocalDayMillis(
        zone: TimeZone = TimeZone.getDefault(),
        dayOffset: Int = 0
    ): Long {
        val c = Calendar.getInstance(zone)
        c.set(Calendar.HOUR_OF_DAY, 0)
        c.set(Calendar.MINUTE, 0)
        c.set(Calendar.SECOND, 0)
        c.set(Calendar.MILLISECOND, 0)
        if (dayOffset != 0) {
            c.add(Calendar.DAY_OF_YEAR, dayOffset)
        }
        return c.timeInMillis
    }

fun planDayIndexFromAnchorDayMillis(anchorLocalDayStartMs: Long): Int {
        val todayStart = startOfLocalDayMillis()
        val deltaDays = ((todayStart - anchorLocalDayStartMs) / 86_400_000L).toInt()
        return deltaDays.coerceIn(0, PLAN_LENGTH - 1)
    }

    fun currentCalendarYear(): Int = Calendar.getInstance().get(Calendar.YEAR)

    fun buildDailyBuckets(
        context: Context,
        translationId: String,
        kind: ScriptureReadingPlanKind
    ): List<List<ScriptureChapterRef>> {
        return when (kind) {
            ScriptureReadingPlanKind.LINEAR -> {
                val flat = ScriptureTextRepository.getAllChaptersInCanonicalOrder(context, translationId)
                distributeTo365(flat)
            }
            ScriptureReadingPlanKind.CHRONOLOGICAL -> {
                val flat = buildChronologicalFlatList(context, translationId)
                distributeTo365(flat)
            }
            ScriptureReadingPlanKind.MIXED -> buildMixedDailyBuckets(context, translationId)
        }
    }

    private fun buildChronologicalFlatList(context: Context, translationId: String): List<ScriptureChapterRef> {
        val canonical = ScriptureTextRepository.getAllChaptersInCanonicalOrder(context, translationId)
        if (canonical.isEmpty()) return emptyList()
        val byBook = canonical.groupBy { it.bookId }.mapValues { (_, refs) ->
            refs.sortedBy { it.chapter }
        }
        val result = mutableListOf<ScriptureChapterRef>()
        val ordered = LinkedHashSet<Int>()
        for (bid in CHRONOLOGICAL_BOOK_ORDER) {
            byBook[bid]?.let {
                result.addAll(it)
                ordered.add(bid)
            }
        }
        val remainingIds = byBook.keys.filter { it !in ordered }.sorted()
        for (bid in remainingIds) {
            byBook[bid]?.let { result.addAll(it) }
        }
        return result
    }

private fun buildMixedDailyBuckets(context: Context, translationId: String): List<List<ScriptureChapterRef>> {
        val all = ScriptureTextRepository.getAllChaptersInCanonicalOrder(context, translationId)
        if (all.isEmpty()) return empty365()

        val otMain = all.filter { ref ->
            val id = ref.bookId
            id !in 40..66 && id != 19 && id != 20
        }.sortedWith(compareBy({ it.bookId }, { it.chapter }))

        val nt = all.filter { it.bookId in 40..66 }
            .sortedWith(compareBy({ it.bookId }, { it.chapter }))

        val wisdom = all.filter { it.bookId == 19 || it.bookId == 20 }
            .sortedWith(compareBy({ it.bookId }, { it.chapter }))

        return distributeThreeLanesTo365(otMain, nt, wisdom)
    }

    private fun empty365(): List<List<ScriptureChapterRef>> =
        List(PLAN_LENGTH) { emptyList() }

    private fun distributeThreeLanesTo365(
        laneA: List<ScriptureChapterRef>,
        laneB: List<ScriptureChapterRef>,
        laneC: List<ScriptureChapterRef>
    ): List<List<ScriptureChapterRef>> {
        val result = List(PLAN_LENGTH) { mutableListOf<ScriptureChapterRef>() }
        var ia = 0
        var ib = 0
        var ic = 0
        for (day in 0 until PLAN_LENGTH) {
            val daysLeft = PLAN_LENGTH - day
            ia = appendNextChunk(laneA, ia, daysLeft, result[day])
            ib = appendNextChunk(laneB, ib, daysLeft, result[day])
            ic = appendNextChunk(laneC, ic, daysLeft, result[day])
        }
        return result.map { it.toList() }
    }

    private fun appendNextChunk(
        lane: List<ScriptureChapterRef>,
        index: Int,
        daysLeft: Int,
        target: MutableList<ScriptureChapterRef>
    ): Int {
        if (index >= lane.size || daysLeft <= 0) return index
        val remaining = lane.size - index
        val take = ceil(remaining.toDouble() / daysLeft).toInt().coerceAtLeast(1).coerceAtMost(remaining)
        for (i in 0 until take) {
            target.add(lane[index + i])
        }
        return index + take
    }

    private fun distributeTo365(flat: List<ScriptureChapterRef>): List<List<ScriptureChapterRef>> {
        val result = List(PLAN_LENGTH) { mutableListOf<ScriptureChapterRef>() }
        if (flat.isEmpty()) return result.map { it.toList() }
        var index = 0
        val total = flat.size
        for (day in 0 until PLAN_LENGTH) {
            if (index >= total) break
            val daysRemaining = PLAN_LENGTH - day
            val chaptersRemaining = total - index
            val take = ceil(chaptersRemaining.toDouble() / daysRemaining).toInt().coerceAtLeast(1)
            repeat(take) {
                if (index < total) {
                    result[day].add(flat[index++])
                }
            }
        }
        return result.map { it.toList() }
    }
}
