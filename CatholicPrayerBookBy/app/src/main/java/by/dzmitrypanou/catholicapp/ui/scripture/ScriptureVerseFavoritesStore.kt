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
        val arr = JSONArray(raw)
        return buildList {
            for (i in 0 until arr.length()) {
                val o = arr.getJSONObject(i)
                add(
                    FavoriteVerse(
                        translationId = o.getString("translationId"),
                        translationTitle = o.getString("translationTitle"),
                        bookId = o.optInt("bookId", -1),
                        bookTitle = o.getString("bookTitle"),
                        chapter = o.getInt("chapter"),
                        verse = o.getInt("verse"),
                        text = o.getString("text")
                    )
                )
            }
        }
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
            current.add(0, verse)
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
