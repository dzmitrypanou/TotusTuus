package by.dzmitrypanou.catholicapp.data

import android.content.Context
import java.nio.charset.StandardCharsets
import java.security.MessageDigest

/**
 * Запамінае, якія часткі Ordo Missae былі разгорнуты/згорнуты, для канкрэтнага зместу (fingerprint HTML).
 */
object OrdoMissaeFoldStore {

    private const val PREFS_NAME = "ordo_missae_folds"
    private const val KEY_FP = "content_fp"
    private const val KEY_PREFIX = "open."

    /** Тыя ж ключы, што ў WebPanel `ordo_missae_section_defs()` — парадак не абавязковы для чытання. */
    val SECTION_KEYS: List<String> = listOf(
        "intro",
        "liturgy_word",
        "eucharist",
        "eucharist_prayer2",
        "communion",
        "closing",
    )

    fun fingerprint(body: String): String {
        val normalized = body.trim()
        val md = MessageDigest.getInstance("SHA-256")
        val h = md.digest(normalized.toByteArray(StandardCharsets.UTF_8))
        return h.joinToString("") { b -> "%02x".format(b) }.take(16)
    }

    private fun prefs(ctx: Context) =
        ctx.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    /**
     * Ключ -> адкрыта/закрыта для загрузкі ў WebView.
     * Калі змест змяніўся (іншы fingerprint), захаванне скідаецца; прадвызначэнне — усе згорнутыя.
     */
    fun initialOpenMap(context: Context, bodyRaw: String): Map<String, Boolean> {
        val fp = fingerprint(bodyRaw)
        val p = prefs(context)
        val prefsFp = p.getString(KEY_FP, null)
        if (prefsFp != null && prefsFp != fp) {
            // commit: пасля clear + fp іначай apply() можа адстаць, і fpMatch будзе false → страцім захаваныя згорткі.
            p.edit().clear().putString(KEY_FP, fp).commit()
        }
        val fpMatch = p.getString(KEY_FP, null) == fp
        val out = LinkedHashMap<String, Boolean>()
        for (key in SECTION_KEYS) {
            val def = false
            val prefKey = KEY_PREFIX + key
            val hasSaved = p.all.containsKey(prefKey)
            out[key] = if (fpMatch && hasSaved) {
                p.getBoolean(prefKey, def)
            } else {
                def
            }
        }
        return out
    }

    fun saveSectionOpen(context: Context, bodyRaw: String, sectionKey: String, open: Boolean) {
        val key = sectionKey.trim()
        if (key.isEmpty()) return
        val fp = fingerprint(bodyRaw)
        // commit: каб стан details не згубіўся пры хуткім згортванні праграмы пасля toggle.
        prefs(context).edit()
            .putString(KEY_FP, fp)
            .putBoolean(KEY_PREFIX + key, open)
            .commit()
    }

    fun clear(context: Context) {
        prefs(context).edit().clear().apply()
    }
}
