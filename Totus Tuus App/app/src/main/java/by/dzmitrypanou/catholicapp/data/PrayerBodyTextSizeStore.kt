package by.dzmitrypanou.catholicapp.data

import android.content.Context
import android.content.res.Resources
import by.dzmitrypanou.catholicapp.R

object PrayerBodyTextSizeStore {

    private const val PREFS_NAME = "ui_text_settings"
    private const val KEY_BODY_TEXT_PX = "prayer_body_text_size_px"

    fun defaultPx(resources: Resources): Float =
        resources.getDimension(R.dimen.text_prayer_body)

fun readPx(context: Context, resources: Resources): Float =
        defaultPx(resources) * AppGlobalTextScaleStore.readScale(context)

fun resetStoredToDefault(context: Context) {
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
            .remove(KEY_BODY_TEXT_PX)
            .apply()
    }
}
