package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

data class FavoriteVerse(
    val translationId: String,
    val translationTitle: String,
    val bookId: Int,
    val bookTitle: String,
    val chapter: Int,
    val verse: Int,
    val text: String
) {
    val key: String get() = "$translationId|$bookId|$chapter|$verse"
}

object ScriptureVerseFavoritesStore {
    private const val PREFS = "scripture_prefs"
    private const val KEY = "favorite_verses"

    fun all(context: Context): List<FavoriteVerse> {
        val raw = context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY, "[]")
            ?: "[]"
        return runCatching {
            val arr = JSONArray(raw)
            buildList {
                for (i in 0 until arr.length()) {
                    val o = arr.getJSONObject(i)
                    add(
                        FavoriteVerse(
                            translationId = o.optString("translationId"),
                            translationTitle = o.optString("translationTitle"),
                            bookId = o.optInt("bookId", -1),
                            bookTitle = o.optString("bookTitle"),
                            chapter = o.optInt("chapter", 1),
                            verse = o.optInt("verse", 1),
                            text = o.optString("text")
                        )
                    )
                }
            }
        }.getOrDefault(emptyList())
            .filter { it.translationId.isNotBlank() && it.bookId >= 0 && it.bookTitle.isNotBlank() && it.text.isNotBlank() }
    }

    fun isFavorite(context: Context, verse: FavoriteVerse): Boolean =
        all(context).any { it.key == verse.key }

    fun toggle(context: Context, verse: FavoriteVerse): Boolean {
        val current = all(context).toMutableList()
        val idx = current.indexOfFirst { it.key == verse.key }
        val added = if (idx >= 0) {
            current.removeAt(idx)
            false
        } else {
            current.add(verse)
            true
        }
        save(context, current)
        return added
    }

    private fun save(context: Context, verses: List<FavoriteVerse>) {
        val arr = JSONArray()
        verses.forEach {
            arr.put(
                JSONObject()
                    .put("translationId", it.translationId)
                    .put("translationTitle", it.translationTitle)
                    .put("bookId", it.bookId)
                    .put("bookTitle", it.bookTitle)
                    .put("chapter", it.chapter)
                    .put("verse", it.verse)
                    .put("text", it.text)
            )
        }
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY, arr.toString())
            .apply()
    }
}
