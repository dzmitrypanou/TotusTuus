package by.dzmitrypanou.catholicapp.data.remote

import by.dzmitrypanou.catholicapp.data.PrayerCategoryMeta
import com.google.gson.annotations.SerializedName

data class PrayerCategoryMetaDto(
    @SerializedName("id") val id: Long,
    @SerializedName("name") val name: String,
    @SerializedName("parent_id") val parentId: Long?,
    @SerializedName("sort_order") val sortOrder: Int?
) {
    fun toDomain(): PrayerCategoryMeta = PrayerCategoryMeta(
        id = id,
        name = name,
        parentId = parentId,
        sortOrder = sortOrder ?: 0
    )
}
