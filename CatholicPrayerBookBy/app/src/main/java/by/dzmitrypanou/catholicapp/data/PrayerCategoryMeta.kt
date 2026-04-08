package by.dzmitrypanou.catholicapp.data

/**
 * Катэгорыя з сервера — парадак як у адмінцы ([sort_order], бацькоўскія спачатку).
 */
data class PrayerCategoryMeta(
    val id: Long,
    val name: String,
    val parentId: Long?,
    val sortOrder: Int
)
