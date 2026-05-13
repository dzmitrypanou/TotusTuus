package by.dzmitrypanou.catholicapp.data

data class PrayerCategoryMeta(
    val id: Long,
    val name: String,
    val parentId: Long?,
    val sortOrder: Int
)
