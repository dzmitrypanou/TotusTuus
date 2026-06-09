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

    fun currentUiSignature(context: Context): String =
        AppFontFamilyStore.readFamily(context).storageKey

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

fun bindSongbookTreeRow(
        binding: ItemPrayerTreeBinding,
        entry: SongbookEntry,
        context: Context,
        showImageBadge: Boolean = true
    ) {
        applyPrayerTreeRowTypography(binding, context)
        if (showImageBadge && entry.contentType == SongbookContentType.IMAGE) {
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

fun applyContentSp(textView: TextView, @DimenRes dimenSpId: Int, context: Context) {
        val res = context.resources
        val basePx = res.getDimension(dimenSpId)
        val baseSp = basePx / res.displayMetrics.scaledDensity
        val scale = AppGlobalTextScaleStore.readScale(context)
        textView.setTextSize(TypedValue.COMPLEX_UNIT_SP, baseSp * scale)
        AppFontFamilyStore.applyToTextView(textView, context)
    }

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
