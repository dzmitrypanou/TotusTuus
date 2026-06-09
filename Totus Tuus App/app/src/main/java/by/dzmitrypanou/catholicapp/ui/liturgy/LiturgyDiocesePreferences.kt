package by.dzmitrypanou.catholicapp.ui.liturgy

import android.content.Context

object LiturgyDiocesePreferences {

    private const val PREFS_NAME = "liturgy_diocese_prefs"
    private const val KEY_PINSK = "pinskaya"
    private const val KEY_MINSK = "minsk_mogilev"
    private const val KEY_VITEBSK = "vitebskaya"
    private const val KEY_GRODNO = "grodzenskaya"

    data class Flags(
        val pinskaya: Boolean,
        val minskMogilev: Boolean,
        val vitebskaya: Boolean,
        val grodzenskaya: Boolean,
    )

    private fun prefs(ctx: Context) =
        ctx.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun readFlags(context: Context): Flags {
        val p = prefs(context)
        return Flags(
            pinskaya = p.getBoolean(KEY_PINSK, false),
            minskMogilev = p.getBoolean(KEY_MINSK, false),
            vitebskaya = p.getBoolean(KEY_VITEBSK, false),
            grodzenskaya = p.getBoolean(KEY_GRODNO, false),
        )
    }

    fun writeFlags(context: Context, flags: Flags) {
        prefs(context).edit()
            .putBoolean(KEY_PINSK, flags.pinskaya)
            .putBoolean(KEY_MINSK, flags.minskMogilev)
            .putBoolean(KEY_VITEBSK, flags.vitebskaya)
            .putBoolean(KEY_GRODNO, flags.grodzenskaya)
            .apply()
    }

fun apiQueryParam(context: Context): String? {
        val f = readFlags(context)
        val parts = ArrayList<String>(4)
        if (f.pinskaya) parts.add("pinskaya")
        if (f.minskMogilev) parts.add("minsk_mogilev")
        if (f.vitebskaya) parts.add("vitebskaya")
        if (f.grodzenskaya) parts.add("grodzenskaya")
        return parts.takeIf { it.isNotEmpty() }?.joinToString(",")
    }

fun cacheKeySuffix(context: Context): String {
        val f = readFlags(context)
        return buildString(4) {
            append(if (f.pinskaya) '1' else '0')
            append(if (f.minskMogilev) '1' else '0')
            append(if (f.vitebskaya) '1' else '0')
            append(if (f.grodzenskaya) '1' else '0')
        }
    }
}
