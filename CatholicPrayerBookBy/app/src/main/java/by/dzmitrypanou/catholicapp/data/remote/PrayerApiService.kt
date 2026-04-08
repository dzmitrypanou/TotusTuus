package by.dzmitrypanou.catholicapp.data.remote

import retrofit2.http.GET
import retrofit2.http.Query

interface PrayerApiService {
    @GET("prayers_hash.php")
    suspend fun getPrayersContentHash(): PrayersHashDto

    @GET("prayers.php")
    suspend fun getPrayers(): List<PrayerDto>

    @GET("prayer_category_meta.php")
    suspend fun getPrayerCategoryMeta(): List<PrayerCategoryMetaDto>

    @GET("songbook_hash.php")
    suspend fun getSongbookContentHash(): PrayersHashDto

    @GET("songbook.php")
    suspend fun getSongbook(): List<SongbookDto>

    @GET("liturgy_calendar_month.php")
    suspend fun getLiturgyCalendarMonth(
        @Query("year") year: Int,
        @Query("month") month: Int,
        @Query("dioceses") dioceses: String?,
    ): LiturgyCalendarMonthDto

    @GET("liturgy_day.php")
    suspend fun getLiturgyDay(
        @Query("date") date: String,
        @Query("dioceses") dioceses: String?,
    ): LiturgyDayDto

    @GET("liturgy_calendar_range.php")
    suspend fun getLiturgyCalendarRange(
        @Query("from") from: String,
        @Query("to") to: String
    ): LiturgyCalendarRangeDto

    @GET("liturgy_calendar_range.php")
    suspend fun getLiturgyCalendarYear(
        @Query("year") year: Int
    ): LiturgyCalendarRangeDto
}
