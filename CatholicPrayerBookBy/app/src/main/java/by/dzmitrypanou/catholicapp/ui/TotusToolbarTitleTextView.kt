package by.dzmitrypanou.catholicapp.ui

import android.content.Context
import android.util.AttributeSet
import androidx.appcompat.widget.AppCompatTextView
import by.dzmitrypanou.catholicapp.data.UsesToolbarTitleTypeface

/**
 * Загаловак шапкі (акрамя брэнда): шрыфт як у WebApp — Inter medium пры выбраным SANS.
 */
class TotusToolbarTitleTextView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : AppCompatTextView(context, attrs, defStyleAttr), UsesToolbarTitleTypeface {

    init {
        // Меньше «лишней» зоны под базовой линией — визуально ближе к вертикальному центру шапки.
        includeFontPadding = false
    }
}
