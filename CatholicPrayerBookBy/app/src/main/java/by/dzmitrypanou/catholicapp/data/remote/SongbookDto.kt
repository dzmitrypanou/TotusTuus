package by.dzmitrypanou.catholicapp.data.remote

import by.dzmitrypanou.catholicapp.data.SongbookContentType
import by.dzmitrypanou.catholicapp.data.SongbookEntry
import com.google.gson.annotations.SerializedName

data class SongbookDto(
    @SerializedName("id") val id: Long,
    @SerializedName("title") val title: String,
    @SerializedName(value = "category", alternate = ["section", "category_name", "Category"])
    val category: String? = null,
    @SerializedName("chapter_major") val chapterMajor: Int,
    @SerializedName("subchapter") val subchapter: Int? = null,
    @SerializedName("content_type") val contentTypeRaw: String,
    @SerializedName("text") val text: String = "",
    @SerializedName("media_url") val mediaUrl: String? = null,
    @SerializedName("media_revision") val mediaRevision: String? = null,
    @SerializedName("sort_order") val sortOrder: Int? = null
) {
    fun contentType(): SongbookContentType =
        when (contentTypeRaw.trim().lowercase()) {
            "image" -> SongbookContentType.IMAGE
            else -> SongbookContentType.TEXT
        }

    fun toEntry(mediaFileName: String?): SongbookEntry =
        SongbookEntry(
            id = id.toString(),
            title = title,
            category = category?.ifBlank { null },
            chapterMajor = chapterMajor,
            subchapter = subchapter,
            contentType = contentType(),
            textBody = text,
            mediaFileName = mediaFileName,
            sortOrder = sortOrder ?: 0
        )
}
