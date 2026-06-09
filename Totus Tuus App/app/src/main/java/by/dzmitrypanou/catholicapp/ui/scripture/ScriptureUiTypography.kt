package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import android.util.TypedValue
import android.widget.TextView
import by.dzmitrypanou.catholicapp.data.AppFontFamilyStore
import by.dzmitrypanou.catholicapp.data.AppGlobalTextScaleStore

object ScriptureUiTypography {

fun applyUiSp(textView: TextView, baseSp: Float, context: Context = textView.context) {
        textView.setTextSize(TypedValue.COMPLEX_UNIT_SP, baseSp)
        AppFontFamilyStore.applyToTextView(textView, context)
    }

fun applyReadingSp(textView: TextView, baseSp: Float, context: Context = textView.context) {
        val scaled = baseSp * AppGlobalTextScaleStore.readScale(context)
        textView.setTextSize(TypedValue.COMPLEX_UNIT_SP, scaled)
        AppFontFamilyStore.applyToTextView(textView, context)
    }
}
