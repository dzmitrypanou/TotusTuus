package by.dzmitrypanou.catholicapp.data

import android.content.Context
import android.graphics.Typeface
import android.os.Build
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.core.content.res.ResourcesCompat
import by.dzmitrypanou.catholicapp.R

object AppFontFamilyStore {

    private const val PREFS_NAME = "ui_text_settings"
    private const val KEY_FONT_FAMILY = "app_font_family"

    enum class Family(val storageKey: String, val androidFamily: String) {
        SANS("sans", "sans-serif"),
        SERIF("serif", "serif"),
        MONO("mono", "monospace");

        companion object {
            fun fromStorage(value: String?): Family = entries.firstOrNull { it.storageKey == value } ?: SANS
        }
    }

    @Volatile
    private var cachedFamily: Family? = null

    @Volatile
    private var cachedBodyTypeface: Pair<Family, Typeface>? = null

    @Volatile
    private var cachedToolbarTitleTypeface: Pair<Family, Typeface>? = null

    fun readFamily(context: Context): Family {
        cachedFamily?.let { return it }
        val value = context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_FONT_FAMILY, Family.SANS.storageKey)
        val resolved = Family.fromStorage(value)
        cachedFamily = resolved
        return resolved
    }

    fun writeFamily(context: Context, family: Family) {
        context.applicationContext
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_FONT_FAMILY, family.storageKey)
            .apply()
        cachedFamily = family
        cachedBodyTypeface = null
        cachedToolbarTitleTypeface = null
    }

    fun resetToDefaults(context: Context) {
        writeFamily(context, Family.SANS)
    }

    fun applyToTextView(textView: TextView, context: Context = textView.context) {
        if (textView is PreservesOwnTypeface) return
        val family = readFamily(context)
        if (textView is UsesToolbarTitleTypeface) {
            val typeface = resolveToolbarTitleTypeface(context, family)
            if (textView.typeface != typeface) {
                textView.typeface = typeface
            }
            return
        }
        val typeface = resolveBodyTypeface(context, family)
        if (textView.typeface != typeface) {
            textView.typeface = typeface
        }
    }

    private fun resolveBodyTypeface(context: Context, family: Family): Typeface {
        cachedBodyTypeface?.let { (f, tf) -> if (f == family) return tf }
        val tf = Typeface.create(family.androidFamily, Typeface.NORMAL)
        cachedBodyTypeface = family to tf
        return tf
    }

private fun resolveToolbarTitleTypeface(context: Context, family: Family): Typeface {
        cachedToolbarTitleTypeface?.let { (f, tf) -> if (f == family) return tf }
        val tf = when (family) {
            Family.SANS -> {
                val font = ResourcesCompat.getFont(context, R.font.inter_variable)
                if (font != null) {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                        Typeface.create(font, 500, false)
                    } else {
                        Typeface.create(font, Typeface.NORMAL)
                    }
                } else {
                    Typeface.create(family.androidFamily, Typeface.NORMAL)
                }
            }
            Family.SERIF, Family.MONO -> Typeface.create(family.androidFamily, Typeface.NORMAL)
        }
        cachedToolbarTitleTypeface = family to tf
        return tf
    }

    fun applyToViewTree(root: View, context: Context = root.context) {
        if (root is TextView) {
            applyToTextView(root, context)
        }
        if (root is ViewGroup) {
            for (i in 0 until root.childCount) {
                applyToViewTree(root.getChildAt(i), context)
            }
        }
    }
}
