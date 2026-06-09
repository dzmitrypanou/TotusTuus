package by.dzmitrypanou.catholicapp.data.remote

import com.google.gson.annotations.SerializedName

data class SolemnityDto(
    @SerializedName("id") val id: Long,
    @SerializedName("date_label") val dateLabel: String,
    @SerializedName("title") val title: String,
    @SerializedName("section_title") val sectionTitle: String? = null,
    @SerializedName("sort_order") val sortOrder: Int? = null,
)

