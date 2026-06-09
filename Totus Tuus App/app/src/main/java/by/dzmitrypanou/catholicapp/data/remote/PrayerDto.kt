package by.dzmitrypanou.catholicapp.data.remote

import by.dzmitrypanou.catholicapp.data.Prayer
import com.google.gson.annotations.SerializedName

data class PrayerDto(
    @SerializedName("id") val id: Long,
    @SerializedName("title") val title: String,
    @SerializedName("text") val text: String,
    @SerializedName("category") val category: String? = null,
    @SerializedName("subcategory") val subcategory: String? = null,
    @SerializedName("language") val language: String? = null,
    @SerializedName("additional_categories") val additionalCategoriesRaw: String? = null,
    @SerializedName("sort_order") val sortOrder: Int? = null
) {
    fun toDomain(): Prayer = Prayer(
        id = id,
        title = title,
        text = text,
        category = category,
        subcategory = subcategory,
        language = language,
        sortOrder = sortOrder ?: 0,
        additionalCategories = additionalCategoriesRaw
            ?.split(",")
            ?.map { it.trim() }
            ?.filter { it.isNotBlank() }
            ?.distinct()
            ?: emptyList()
    )
}
