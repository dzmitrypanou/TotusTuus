package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context

object ScriptureReadingProgressStore {

    private const val PREFS = "scripture_reading_progress"
    private const val KEY_TRANSLATION = "translation_id"
    private const val KEY_BOOK_ID = "book_id"
    private const val KEY_BOOK_TITLE = "book_title"
    private const val KEY_CHAPTER = "chapter"
    private const val KEY_UPDATED_MS = "updated_ms"

    fun save(
        context: Context,
        translationId: String,
        bookId: Int,
        bookTitle: String,
        chapter: Int
    ) {
        if (bookId < 0 || bookTitle.isBlank()) return
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_TRANSLATION, translationId)
            .putInt(KEY_BOOK_ID, bookId)
            .putString(KEY_BOOK_TITLE, bookTitle)
            .putInt(KEY_CHAPTER, chapter)
            .putLong(KEY_UPDATED_MS, System.currentTimeMillis())
            .apply()
    }

    fun read(context: Context): ScriptureReadingProgress? {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val tr = prefs.getString(KEY_TRANSLATION, null) ?: return null
        val bookId = prefs.getInt(KEY_BOOK_ID, -1)
        val title = prefs.getString(KEY_BOOK_TITLE, null).orEmpty()
        if (bookId < 0 || title.isBlank()) return null
        return ScriptureReadingProgress(
            translationId = tr,
            bookId = bookId,
            bookTitle = title,
            chapter = prefs.getInt(KEY_CHAPTER, 1).coerceAtLeast(1),
            updatedAtMs = prefs.getLong(KEY_UPDATED_MS, 0L)
        )
    }

    fun clear(context: Context) {
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply()
    }
}

data class ScriptureReadingProgress(
    val translationId: String,
    val bookId: Int,
    val bookTitle: String,
    val chapter: Int,
    val updatedAtMs: Long
)
