package by.dzmitrypanou.catholicapp.data

import android.content.Context

/**
 * Лакальны кэш тэксту Ordo Missae: паказ адразу, поўная загрузка толькі калі [updated_at] на серверы змяніўся.
 */
class OrdoMissaeCacheStore(context: Context) {

    private val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun readHtml(): String = prefs.getString(KEY_HTML, null).orEmpty()

    fun readUpdatedAt(): String = prefs.getString(KEY_UPDATED_AT, null)?.trim().orEmpty()

    fun write(html: String, updatedAt: String) {
        prefs.edit()
            .putString(KEY_HTML, html)
            .putString(KEY_UPDATED_AT, updatedAt.trim())
            .apply()
    }

    fun clear() {
        prefs.edit()
            .remove(KEY_HTML)
            .remove(KEY_UPDATED_AT)
            .apply()
    }

    companion object {
        private const val PREFS_NAME = "ordo_missae_cache"
        private const val KEY_HTML = "html"
        private const val KEY_UPDATED_AT = "updated_at"
    }
}
