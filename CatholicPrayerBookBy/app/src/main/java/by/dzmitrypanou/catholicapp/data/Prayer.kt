package by.dzmitrypanou.catholicapp.data

data class Prayer(
    val id: Long,
    val title: String,
    val text: String,
    val category: String? = null,
    val subcategory: String? = null,
    val language: String? = null,
    val additionalCategories: List<String> = emptyList(),
    /** Парадак у асноўнай катэгорыі (з сервера); пры роўных — па [id]. */
    val sortOrder: Int = 0
)
