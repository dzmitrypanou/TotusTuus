package by.dzmitrypanou.catholicapp.data

import android.content.Context
import by.dzmitrypanou.catholicapp.R

enum class SongbookContentType {
    TEXT,
    IMAGE
}

data class SongbookEntry(
    val id: String,
    val title: String,

    val category: String? = null,
    val chapterMajor: Int,
    val subchapter: Int? = null,
    val contentType: SongbookContentType,
    val textBody: String = "",

    val mediaFileName: String? = null,
    val sortOrder: Int = 0,
    val showNumber: Boolean? = null,
    val showBadge: Boolean? = null
) {

    fun categorySortKey(): String {
        val raw = category?.trim().orEmpty()
        if (raw.isEmpty()) return ""
        return raw.replace(CATEGORY_WHITESPACE, " ")
    }

fun categoryToolbarSubtitle(context: Context): String {
        val key = categorySortKey()
        return if (key.isEmpty()) {
            context.getString(R.string.songbook_category_uncategorized)
        } else {
            key
        }
    }

    fun numberPrefix(): String =
        if (subchapter == null) "$chapterMajor." else "$chapterMajor.$subchapter"

    fun listLabel(showNumber: Boolean = this.showNumber != false): String {
        val t = title.trim()
        if (!showNumber) return t.ifEmpty { numberPrefix() }
        return if (t.isEmpty()) numberPrefix() else "${numberPrefix()} $t"
    }

fun bookmarkListLabel(): String {
        val t = title.trim()
        return if (t.isNotEmpty()) t else numberPrefix()
    }

    fun sortKey(): Triple<Int, Int, Int> = Triple(chapterMajor, subchapter ?: 0, sortOrder)

    companion object {
        private val CATEGORY_WHITESPACE = Regex("\\s+")

        val DISPLAY_ORDER: Comparator<SongbookEntry> = compareBy(
            { it.categorySortKey() },
            { it.chapterMajor },
            { it.subchapter ?: 0 },
            { it.sortOrder },
            { it.id.toLongOrNull() ?: 0L }
        )
    }
}
