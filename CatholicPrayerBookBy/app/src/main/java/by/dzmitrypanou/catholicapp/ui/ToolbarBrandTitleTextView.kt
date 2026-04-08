package by.dzmitrypanou.catholicapp.ui

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.LinearGradient
import android.graphics.Shader
import android.graphics.Typeface
import android.os.Build
import android.util.AttributeSet
import androidx.appcompat.widget.AppCompatTextView
import androidx.core.content.res.ResourcesCompat
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.AppColorSchemeStore
import by.dzmitrypanou.catholicapp.data.PreservesOwnTypeface
import kotlin.math.max

/**
 * Загаловак «Totus Tuus» на галоўным экране: як у вэб-панэлі — Cormorant Garamond semibold
 * і градыент `linear-gradient(120deg, #f1f5f9, #e2d5b8, #c7d2fe)`.
 */
class ToolbarBrandTitleTextView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : AppCompatTextView(context, attrs, defStyleAttr), PreservesOwnTypeface {

    private val darkGradientColors = intArrayOf(
        Color.parseColor("#f1f5f9"),
        Color.parseColor("#e2d5b8"),
        Color.parseColor("#c7d2fe")
    )
    private val gradientPositions = floatArrayOf(0f, 0.45f, 1f)

    init {
        maxLines = 1
        // Иначе визуально тянет строку вниз относительно иконки в тулбаре.
        includeFontPadding = false
        letterSpacing = 0.02f
        setPadding(paddingLeft, 0, paddingRight, 0)
        val fromRes = ResourcesCompat.getFont(context, R.font.cormorant_garamond_variable)
        typeface = when {
            fromRes == null -> Typeface.create(Typeface.SERIF, Typeface.NORMAL)
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.P -> Typeface.create(fromRes, 600, false)
            else -> fromRes
        }
        setTextColor(Color.WHITE)
    }

    override fun onDraw(canvas: Canvas) {
        val textPaint = paint
        if (AppColorSchemeStore.readScheme(context) == AppColorSchemeStore.Scheme.LIGHT) {
            textPaint.shader = null
            setTextColor(Color.parseColor("#2b2117"))
            super.onDraw(canvas)
            return
        }
        val textStr = text?.toString().orEmpty()
        val textW = if (textStr.isEmpty()) 0f else textPaint.measureText(textStr)
        val w = max(textW, width.toFloat())
        val h = height.toFloat().coerceAtLeast(textPaint.textSize * 1.25f)
        textPaint.shader = LinearGradient(
            0f, h,
            w, 0f,
            darkGradientColors,
            gradientPositions,
            Shader.TileMode.CLAMP
        )
        super.onDraw(canvas)
        textPaint.shader = null
    }
}
