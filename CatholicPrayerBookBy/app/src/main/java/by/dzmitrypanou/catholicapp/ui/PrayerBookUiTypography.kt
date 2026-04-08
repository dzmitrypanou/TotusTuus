package by.dzmitrypanou.catholicapp.ui

import android.content.Context
import android.util.TypedValue
import android.view.View
import android.widget.TextView
import androidx.annotation.DimenRes
import androidx.core.widget.TextViewCompat
import by.dzmitrypanou.catholicapp.data.AppFontFamilyStore
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.AppGlobalTextScaleStore
import by.dzmitrypanou.catholicapp.data.SongbookContentType
import by.dzmitrypanou.catholicapp.data.SongbookEntry
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import kotlin.math.roundToInt

object PrayerBookUiTypography {

    fun applyPrayerTreeRowTypography(binding: ItemPrayerTreeBinding, context: Context) {
        applyUiSp(binding.textTreeTitle, R.dimen.text_list_row_title, context)
        applyUiSp(binding.textTreeSubtitle, R.dimen.text_list_row_subtitle, context)
    }

    fun bindPrayerTreeRow(binding: ItemPrayerTreeBinding, context: Context) {
        applyPrayerTreeRowTypography(binding, context)
        binding.imageTreeNotesIndicator.visibility = View.GONE
        binding.imageTreeNotesIndicator.contentDescription = null
        binding.imageTreeNotesIndicator.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO
        binding.imageTreeChevron.visibility = View.VISIBLE
    }

    /** Радок спеўніка: іконка нот толькі для запісаў з відарысам (ноты). */
    fun bindSongbookTreeRow(binding: ItemPrayerTreeBinding, entry: SongbookEntry, context: Context) {
        applyPrayerTreeRowTypography(binding, context)
        if (entry.contentType == SongbookContentType.IMAGE) {
            binding.imageTreeNotesIndicator.visibility = View.VISIBLE
            binding.imageTreeNotesIndicator.contentDescription =
                context.getString(R.string.songbook_row_notes_a11y)
            binding.imageTreeNotesIndicator.importantForAccessibility =
                View.IMPORTANT_FOR_ACCESSIBILITY_YES
        } else {
            binding.imageTreeNotesIndicator.visibility = View.GONE
            binding.imageTreeNotesIndicator.contentDescription = null
            binding.imageTreeNotesIndicator.importantForAccessibility =
                View.IMPORTANT_FOR_ACCESSIBILITY_NO
        }
        binding.imageTreeChevron.visibility = View.GONE
    }

    /** Спісы, шапкі, пошук — без кроку памеру тэксту. */
    fun applyUiSp(textView: TextView, @DimenRes dimenSpId: Int, context: Context) {
        val res = context.resources
        val basePx = res.getDimension(dimenSpId)
        val baseSp = basePx / res.displayMetrics.scaledDensity
        textView.setTextSize(TypedValue.COMPLEX_UNIT_SP, baseSp)
        AppFontFamilyStore.applyToTextView(textView, context)
    }

    fun applyUiSp(textView: TextView, baseSp: Float, context: Context) {
        textView.setTextSize(TypedValue.COMPLEX_UNIT_SP, baseSp)
        AppFontFamilyStore.applyToTextView(textView, context)
    }

    /** Тэкст чытанняў (лекцыянарый і г.д.) — з [AppGlobalTextScaleStore]. */
    fun applyContentSp(textView: TextView, @DimenRes dimenSpId: Int, context: Context) {
        val res = context.resources
        val basePx = res.getDimension(dimenSpId)
        val baseSp = basePx / res.displayMetrics.scaledDensity
        val scale = AppGlobalTextScaleStore.readScale(context)
        textView.setTextSize(TypedValue.COMPLEX_UNIT_SP, baseSp * scale)
        AppFontFamilyStore.applyToTextView(textView, context)
    }

    /**
     * Загаловак раздзела ў шапцы (не галоўная): да 3 радкоў, пры доўгім тэксце маштаб ад max ([maxDimenSpId])
     * да ~45% ад гэтага памеру.
     */
    fun applyToolbarSectionTitleAutoSize(
        textView: TextView,
        @DimenRes maxDimenSpId: Int,
        context: Context
    ) {
        AppFontFamilyStore.applyToTextView(textView, context)
        val res = context.resources
        val maxSpFloat = res.getDimension(maxDimenSpId) / res.displayMetrics.scaledDensity
        val maxSp = maxSpFloat.roundToInt().coerceIn(14, 48)
        val minSp = (maxSpFloat * 0.45f).roundToInt().coerceIn(11, maxSp - 1)
        TextViewCompat.setAutoSizeTextTypeUniformWithConfiguration(
            textView,
            minSp,
            maxSp,
            1,
            TypedValue.COMPLEX_UNIT_SP
        )
    }

    /**
     * Загаловак запісу спеўніка ў шапцы: да 2 радкоў, autosize ад [maxDimenSpId] да ~45%.
     */
    fun applySongbookToolbarDetailTitleAutoSize(
        textView: TextView,
        @DimenRes maxDimenSpId: Int,
        context: Context
    ) {
        AppFontFamilyStore.applyToTextView(textView, context)
        val res = context.resources
        val maxSpFloat = res.getDimension(maxDimenSpId) / res.displayMetrics.scaledDensity
        val maxSp = maxSpFloat.roundToInt().coerceIn(14, 48)
        val minSp = (maxSpFloat * 0.45f).roundToInt().coerceIn(11, maxSp - 1)
        TextViewCompat.setAutoSizeTextTypeUniformWithConfiguration(
            textView,
            minSp,
            maxSp,
            1,
            TypedValue.COMPLEX_UNIT_SP
        )
    }

    /**
     * Катэгорыя пад загалоўкам (спеўнік): 1 радок, autosize ад [maxDimenSpId] да ~50%.
     */
    fun applySongbookToolbarCategoryAutoSize(
        textView: TextView,
        @DimenRes maxDimenSpId: Int,
        context: Context
    ) {
        AppFontFamilyStore.applyToTextView(textView, context)
        val res = context.resources
        val maxSpFloat = res.getDimension(maxDimenSpId) / res.displayMetrics.scaledDensity
        val maxSp = maxSpFloat.roundToInt().coerceIn(12, 22)
        val minSp = (maxSpFloat * 0.5f).roundToInt().coerceIn(9, maxSp - 1)
        TextViewCompat.setAutoSizeTextTypeUniformWithConfiguration(
            textView,
            minSp,
            maxSp,
            1,
            TypedValue.COMPLEX_UNIT_SP
        )
    }

}
