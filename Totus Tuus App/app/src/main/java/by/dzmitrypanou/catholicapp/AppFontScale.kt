package by.dzmitrypanou.catholicapp

import android.content.Context
import android.content.res.Configuration

object AppFontScale {
    private const val APP_FONT_SCALE = 1f

    fun wrap(context: Context): Context {
        val configuration = Configuration(context.resources.configuration)
        if (configuration.fontScale == APP_FONT_SCALE) return context
        configuration.fontScale = APP_FONT_SCALE
        return context.createConfigurationContext(configuration)
    }
}
