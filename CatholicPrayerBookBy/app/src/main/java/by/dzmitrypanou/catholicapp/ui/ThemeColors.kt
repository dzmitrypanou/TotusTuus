package by.dzmitrypanou.catholicapp.ui

import android.content.Context
import android.util.TypedValue
import android.view.View
import androidx.annotation.AttrRes
import androidx.annotation.ColorInt
import androidx.core.content.ContextCompat

@ColorInt
fun Context.themeColor(@AttrRes attrRes: Int): Int {
    val tv = TypedValue()
    check(theme.resolveAttribute(attrRes, tv, true)) {
        "Could not resolve theme attribute 0x${Integer.toHexString(attrRes)}"
    }
    return when {
        tv.type == TypedValue.TYPE_REFERENCE && tv.resourceId != 0 ->
            ContextCompat.getColor(this, tv.resourceId)
        tv.type in TypedValue.TYPE_FIRST_COLOR_INT..TypedValue.TYPE_LAST_COLOR_INT ->
            tv.data
        tv.resourceId != 0 ->
            ContextCompat.getColor(this, tv.resourceId)
        else ->
            tv.data
    }
}

@ColorInt
fun View.themeColor(@AttrRes attrRes: Int): Int = context.themeColor(attrRes)
