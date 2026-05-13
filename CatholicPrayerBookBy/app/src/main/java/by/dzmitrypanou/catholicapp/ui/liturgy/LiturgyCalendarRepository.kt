package by.dzmitrypanou.catholicapp.ui.liturgy

import android.content.Context
import by.dzmitrypanou.catholicapp.data.remote.LiturgyCalendarMonthDto
import by.dzmitrypanou.catholicapp.data.remote.LiturgyDayDto
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import com.google.gson.Gson
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

object LiturgyCalendarRepository {

    private const val PREFS_NAME = "liturgy_calendar_cache"

    private const val CACHE_KEY_VERSION = "_v3"
    private const val MONTH_KEY_PREFIX = "month_"
    private const val DAY_KEY_PREFIX = "day_"

    private val gson = Gson()
    private val apiDateFmt = SimpleDateFormat("yyyy-MM-dd", Locale.US)

    private fun diocesesQuery(context: Context): String? =
        LiturgyDiocesePreferences.apiQueryParam(context.applicationContext)

    private fun monthKey(year: Int, month: Int, context: Context): String {
        val suf = LiturgyDiocesePreferences.cacheKeySuffix(context.applicationContext)
        return MONTH_KEY_PREFIX + year + "_" + month.toString().padStart(2, '0') + "_" + suf + CACHE_KEY_VERSION
    }

    private fun dayKey(date: String, context: Context): String {
        val suf = LiturgyDiocesePreferences.cacheKeySuffix(context.applicationContext)
        return DAY_KEY_PREFIX + date + "_" + suf + CACHE_KEY_VERSION
    }

    suspend fun prefetchCurrentMonth(context: Context) {
        val now = Calendar.getInstance()
        val year = now.get(Calendar.YEAR)
        val month = now.get(Calendar.MONTH) + 1
        val dio = diocesesQuery(context)
        runCatching {
            PrayerApiClient.service.getLiturgyCalendarMonth(year, month, dio)
        }.onSuccess { dto ->
            cacheMonth(context, dto)
            val today = apiDateFmt.format(Date())
            runCatching { PrayerApiClient.service.getLiturgyDay(today, dio) }
                .onSuccess { day -> cacheDay(context, day) }
        }
    }

    suspend fun getMonthFromNetworkAndCache(context: Context, year: Int, month: Int): LiturgyCalendarMonthDto? {
        val dio = diocesesQuery(context)
        return runCatching {
            PrayerApiClient.service.getLiturgyCalendarMonth(year, month, dio)
        }.onSuccess { dto ->
            cacheMonth(context, dto)
        }.getOrNull()
    }

    fun getCachedMonth(context: Context, year: Int, month: Int): LiturgyCalendarMonthDto? {
        val json = context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(monthKey(year, month, context), null) ?: return null
        return runCatching { gson.fromJson(json, LiturgyCalendarMonthDto::class.java) }.getOrNull()
    }

    suspend fun getDayFromNetworkAndCache(context: Context, date: String): LiturgyDayDto? {
        val dio = diocesesQuery(context)
        return runCatching {
            PrayerApiClient.service.getLiturgyDay(date, dio)
        }.onSuccess { day ->
            cacheDay(context, day)
        }.getOrNull()
    }

    fun getCachedDay(context: Context, date: String): LiturgyDayDto? {
        val json = context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(dayKey(date, context), null) ?: return null
        return runCatching { gson.fromJson(json, LiturgyDayDto::class.java) }.getOrNull()
    }

    private fun cacheMonth(context: Context, dto: LiturgyCalendarMonthDto) {
        context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putString(monthKey(dto.year, dto.month, context), gson.toJson(dto))
            .apply()
    }

    private fun cacheDay(context: Context, dto: LiturgyDayDto) {
        val keyDate = dto.date?.takeIf { it.isNotBlank() } ?: return
        context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putString(dayKey(keyDate, context), gson.toJson(dto))
            .apply()
    }
}
