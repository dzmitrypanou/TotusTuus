package by.dzmitrypanou.catholicapp.ui

import android.content.Context
import android.util.AttributeSet
import androidx.appcompat.widget.AppCompatTextView
import by.dzmitrypanou.catholicapp.data.UsesToolbarTitleTypeface

class TotusToolbarTitleTextView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : AppCompatTextView(context, attrs, defStyleAttr), UsesToolbarTitleTypeface {

    init {

        includeFontPadding = false
    }
}
