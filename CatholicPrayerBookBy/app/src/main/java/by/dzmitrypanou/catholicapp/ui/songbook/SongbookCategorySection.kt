package by.dzmitrypanou.catholicapp.ui.songbook

import android.content.Context
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.SongbookEntry

data class SongbookCategorySection(
    /** Ключ для [SongbookCategoryExpandStore] і групавання (масіў катэгорыі або [SongbookCategoryExpandStore.GROUP_KEY_UNCATEGORIZED]). */
    val groupKey: String,
    val displayTitle: String,
    val entries: List<SongbookEntry>
)

fun List<SongbookEntry>.groupedIntoCategorySections(context: Context): List<SongbookCategorySection> {
    val sorted = sortedWith(SongbookEntry.DISPLAY_ORDER)
    if (sorted.isEmpty()) return emptyList()
    val uncategorizedTitle = context.getString(R.string.songbook_category_uncategorized)
    val groups = LinkedHashMap<String, MutableList<SongbookEntry>>()
    for (e in sorted) {
        val raw = e.categorySortKey()
        val mapKey = if (raw.isEmpty()) {
            SongbookCategoryExpandStore.GROUP_KEY_UNCATEGORIZED
        } else {
            raw
        }
        groups.getOrPut(mapKey) { mutableListOf() }.add(e)
    }
    return groups.map { (key, list) ->
        val title = if (key == SongbookCategoryExpandStore.GROUP_KEY_UNCATEGORIZED) {
            uncategorizedTitle
        } else {
            key
        }
        SongbookCategorySection(
            groupKey = key,
            displayTitle = title,
            entries = list
        )
    }
}
