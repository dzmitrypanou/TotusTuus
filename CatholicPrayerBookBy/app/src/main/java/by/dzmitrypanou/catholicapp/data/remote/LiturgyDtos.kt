package by.dzmitrypanou.catholicapp.data.remote

import com.google.gson.annotations.SerializedName

data class LiturgyCalendarMonthDto(
    @SerializedName("year") val year: Int,
    @SerializedName("month") val month: Int,
    @SerializedName("grid_start") val gridStart: String,
    @SerializedName("grid_end") val gridEnd: String,
    @SerializedName("days") val days: List<LiturgyCalendarDayCellDto>
)

data class LiturgyCalendarDayCellDto(
    @SerializedName("date") val date: String,
    @SerializedName("day") val day: Int,
    @SerializedName("is_current_month") val isCurrentMonth: Boolean,
    @SerializedName("is_today") val isToday: Boolean,
    @SerializedName("is_important") val isImportant: Boolean,
    @SerializedName("has_optional_memorial") val hasOptionalMemorial: Boolean = false,
    @SerializedName("optional_memorial_title") val optionalMemorialTitle: String? = null,
    @SerializedName("optional_memorial_colors") val optionalMemorialColors: List<String>? = null,
    @SerializedName("optional_memorial_color") val optionalMemorialColor: String? = null,
    @SerializedName("lectionary_count") val lectionaryCount: Int? = null,
    @SerializedName("lectionaries_count") val lectionariesCount: Int? = null,
    @SerializedName("readings_count") val readingsCount: Int? = null,
    @SerializedName("lections_count") val lectionsCount: Int? = null,
    @SerializedName("lectionary_variants_count") val lectionaryVariantsCount: Int? = null,
    @SerializedName("readings") val readings: String? = null,
    @SerializedName("readings_full") val readingsFull: String? = null,
    @SerializedName("title") val title: String,
    @SerializedName("auto_title") val autoTitle: String? = null,
    @SerializedName("source_color") val sourceColor: String?,
    @SerializedName("liturgical_color") val liturgicalColor: String,
    @SerializedName("liturgical_color_hex") val liturgicalColorHex: String,
    @SerializedName("has_content") val hasContent: Boolean
)

data class LiturgyDayDto(
    @SerializedName("date") val date: String?,
    @SerializedName("title") val title: String?,
    @SerializedName("auto_title") val autoTitle: String?,
    @SerializedName("is_important") val isImportant: Boolean,
    @SerializedName("has_optional_memorial") val hasOptionalMemorial: Boolean = false,
    @SerializedName("optional_memorial_title") val optionalMemorialTitle: String? = null,
    @SerializedName("optional_memorial_colors") val optionalMemorialColors: List<String>? = null,
    @SerializedName("optional_memorial_color") val optionalMemorialColor: String? = null,
    @SerializedName("liturgical_color") val liturgicalColor: String?,
    @SerializedName("liturgical_color_hex") val liturgicalColorHex: String?,
    @SerializedName("readings") val readings: String?,
    @SerializedName("readings_full") val readingsFull: String?,
    @SerializedName("kantaral_entry_id") val kantaralEntryId: Long? = null,
    @SerializedName("kantaral_title") val kantaralTitle: String? = null,
    @SerializedName("updated_at") val updatedAt: String?
)

data class LiturgyCalendarRangeDto(
    @SerializedName("from") val from: String,
    @SerializedName("to") val to: String,
    @SerializedName("count") val count: Int,
    @SerializedName("days") val days: List<LiturgyCalendarRangeDayDto>
)

data class LiturgyCalendarRangeDayDto(
    @SerializedName("date") val date: String,
    @SerializedName("title") val title: String,
    @SerializedName("auto_title") val autoTitle: String?,
    @SerializedName("is_important") val isImportant: Boolean,
    @SerializedName("liturgical_color") val liturgicalColor: String,
    @SerializedName("liturgical_color_hex") val liturgicalColorHex: String,
    @SerializedName("readings") val readings: String?,
    @SerializedName("readings_full") val readingsFull: String?,
    @SerializedName("updated_at") val updatedAt: String?
)
