package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context

object ScriptureTranslationStore {
    private const val PREFS = "scripture_prefs"
    private const val KEY_TRANSLATION = "selected_translation_id"

    fun getSelectedTranslationId(context: Context): String {
        return context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_TRANSLATION, ScriptureCatalog.DEFAULT_TRANSLATION_ID)
            ?: ScriptureCatalog.DEFAULT_TRANSLATION_ID
    }

    fun setSelectedTranslationId(context: Context, translationId: String) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_TRANSLATION, translationId)
            .apply()
    }
}
