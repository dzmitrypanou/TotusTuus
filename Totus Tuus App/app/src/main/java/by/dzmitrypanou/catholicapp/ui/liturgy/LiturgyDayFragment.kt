package by.dzmitrypanou.catholicapp.ui.liturgy

import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.view.Gravity
import androidx.core.text.HtmlCompat
import androidx.core.view.isVisible
import android.text.Html
import android.text.SpannableStringBuilder
import android.text.Spanned
import android.text.style.AbsoluteSizeSpan
import android.text.style.ForegroundColorSpan
import android.text.style.RelativeSizeSpan
import android.content.Context
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.TextView
import by.dzmitrypanou.catholicapp.ui.themeColor
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.remote.LiturgyDayDto
import by.dzmitrypanou.catholicapp.databinding.FragmentLiturgyDayBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlinx.coroutines.launch

class LiturgyDayFragment : Fragment() {

    private var _binding: FragmentLiturgyDayBinding? = null
    private val binding get() = _binding!!
    private var preservedScrollY: Int = 0
    private var lastLiturgyDayDto: LiturgyDayDto? = null
    private var lastLiturgyDioceseCacheKey: String? = null
    private data class ReadingsSection(val title: String, val bodyHtml: String, val openByDefault: Boolean)

    private val apiDateFmt = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val displayDateFmt = SimpleDateFormat("d MMMM yyyy 'г.'", Locale.forLanguageTag("be"))
    private val selectedDate: String
        get() = arguments?.getString(ARG_DATE).orEmpty().ifBlank { apiDateFmt.format(Date()) }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentLiturgyDayBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.scrollLiturgyDay.setOnScrollChangeListener { _, _, scrollY, _, _ ->
            preservedScrollY = scrollY
        }
        bindTypography()
        loadDay(selectedDate)
    }

    override fun onResume() {
        super.onResume()
        bindTypography()
        val ctx = context ?: return
        val key = LiturgyDiocesePreferences.cacheKeySuffix(ctx)
        if (lastLiturgyDioceseCacheKey != null && key != lastLiturgyDioceseCacheKey) {
            loadDay(selectedDate)
        }
        lastLiturgyDioceseCacheKey = key
    }

fun applyReadingTextScaleFromToolbar() {
        bindTypography()
        val day = lastLiturgyDayDto ?: return
        showDay(day, forceGrayColor = false)
        restoreScroll()
    }

    private fun bindTypography() {
        val ctx = context ?: return
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyDaySubtitle, R.dimen.text_list_row_subtitle, ctx)
        PrayerBookUiTypography.applyContentSp(binding.textLiturgyDayReadings, R.dimen.text_list_row_subtitle, ctx)
    }

    private fun loadDay(date: String) {
        val cached = LiturgyCalendarRepository.getCachedDay(requireContext(), date)
        if (cached != null) {

            showDay(cached, forceGrayColor = false)
        }
        viewLifecycleOwner.lifecycleScope.launch {
            val day = LiturgyCalendarRepository.getDayFromNetworkAndCache(requireContext(), date)
            if (day != null) {
                showDay(day, forceGrayColor = false)
            } else if (cached == null) {
                showNoDataDayState()
            } else {
                showDay(cached, forceGrayColor = false)
            }
        }
    }

    private fun showDay(day: LiturgyDayDto, forceGrayColor: Boolean) {
        lastLiturgyDayDto = day
        val dayDate = day.date.orEmpty().ifBlank { selectedDate }
        val sourceTitle = day.title.orEmpty().ifBlank { getString(R.string.liturgy_ordinary_day) }
        val title = sourceTitle
        val optionalMemorialRaw = day.optionalMemorialTitle.orEmpty().trim()
        val optionalMemorial = optionalMemorialRaw
        val optionalMemorialColors = if (optionalMemorial.isNotBlank()) {
            day.optionalMemorialColors.orEmpty().map { it.trim() }
        } else {
            emptyList()
        }
        val mainColorInt = if (forceGrayColor) {
            colorIntFromName("gray")
        } else {
            colorIntFromName(day.liturgicalColor.orEmpty())
        }
        val optionalColorInt = if (optionalMemorial.isNotBlank()) {
            if (forceGrayColor) colorIntFromName("gray") else colorIntFromName(day.optionalMemorialColor.orEmpty())
        } else {
            null
        }
        bindLiturgyTitleRows(title, mainColorInt, optionalMemorial, optionalColorInt, optionalMemorialColors)
        binding.textLiturgyDaySubtitle.text = formatDisplayDate(dayDate)
        val fullText = day.readingsFull.orEmpty().ifBlank { day.readings.orEmpty() }
        renderReadingsBlock(fullText, dayDate, mainDisplayTitle = title)
        restoreScroll()
    }

    private fun renderReadingsBlock(
        content: String,
        apiDateYmd: String,
        mainDisplayTitle: String? = null
    ) {
        val normalized = content.trim()
        if (normalized.isBlank()) {
            binding.layoutLiturgyDayReadingsSections.isVisible = false
            binding.textLiturgyDayReadings.isVisible = true
            binding.textLiturgyDayReadings.text = getString(R.string.liturgy_readings_not_found)
            return
        }

        val sections = parseExpandableReadingsSections(normalized, apiDateYmd)
        if (sections.isEmpty()) {
            binding.layoutLiturgyDayReadingsSections.isVisible = false
            binding.textLiturgyDayReadings.isVisible = true
            binding.textLiturgyDayReadings.text = renderReadingsContent(normalized)
            return
        }
        if (sections.size == 1) {
            val outsideHtml = htmlOutsideDetailsBlocks(normalized).trim()
            val outsideRendered =
                if (outsideHtml.isNotBlank()) renderReadingsContent(outsideHtml) else null
            val plain = renderSingleReadingSectionPlain(sections.first(), mainDisplayTitle)
            binding.layoutLiturgyDayReadingsSections.isVisible = false
            binding.textLiturgyDayReadings.isVisible = true
            binding.textLiturgyDayReadings.text = when {
                plain != null && outsideRendered != null ->
                    SpannableStringBuilder().apply {
                        append(outsideRendered)
                        append("\n\n")
                        append(plain)
                    }
                plain != null -> plain
                else -> renderReadingsContent(normalized)
            }
            return
        }
        val hasRenderedSections = populateExpandableReadingsSections(sections)
        if (!hasRenderedSections) {
            binding.layoutLiturgyDayReadingsSections.isVisible = false
            binding.textLiturgyDayReadings.isVisible = true
            binding.textLiturgyDayReadings.text = renderReadingsContent(normalized)
            return
        }
        val outsideHtml = htmlOutsideDetailsBlocks(normalized).trim()
        if (outsideHtml.isNotBlank()) {
            prependReadingsIntroHtml(binding.layoutLiturgyDayReadingsSections, outsideHtml)
        }
        binding.layoutLiturgyDayReadingsSections.isVisible = true
        binding.textLiturgyDayReadings.isVisible = false
    }

private fun htmlOutsideDetailsBlocks(html: String): String =
        Regex("(?is)<details\\b[^>]*>.*?</details>").replace(html) { "" }.trim()

    private fun prependReadingsIntroHtml(container: LinearLayout, outsideHtml: String) {
        val ctx = requireContext()
        val introView = TextView(ctx).apply {
            text = renderReadingsContent(outsideHtml)
            setTextColor(ctx.themeColor(R.attr.totusColorTextPrimary))
            setLineSpacing(0f, 1.12f)
            setPadding(0, 0, 0, dpToPx(10))
            PrayerBookUiTypography.applyContentSp(this, R.dimen.text_list_row_subtitle, ctx)
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            )
        }
        container.addView(introView, 0)
    }

    private fun parseExpandableReadingsSections(value: String, apiDateYmd: String): List<ReadingsSection> {
        val detailsRegex = Regex("(?is)<details\\b[^>]*>(.*?)</details>")
        val summaryRegex = Regex("(?is)<summary\\b[^>]*>(.*?)</summary>")
        val sections = mutableListOf<ReadingsSection>()

        detailsRegex.findAll(value).forEach { detailsMatch ->
            val detailsBlock = detailsMatch.groupValues.getOrNull(1).orEmpty()
            val summaryMatch = summaryRegex.find(detailsBlock) ?: return@forEach
            val summaryHtml = summaryMatch.groupValues.getOrNull(1).orEmpty().trim()
            val summaryText = HtmlCompat.fromHtml(summaryHtml, HtmlCompat.FROM_HTML_MODE_COMPACT).toString().trim()
            if (summaryText.isBlank()) return@forEach

            val bodyRaw = detailsBlock.substring(summaryMatch.range.last + 1).trim()
            val bodyHtml = unwrapSingleOuterDiv(bodyRaw)
            if (bodyHtml.isBlank()) return@forEach

            val openByDefault = true
            sections += ReadingsSection(
                title = summaryText,
                bodyHtml = bodyHtml,
                openByDefault = openByDefault
            )
        }
        return sections
    }

    private fun unwrapSingleOuterDiv(value: String): String {
        val openingDiv = Regex("(?is)^\\s*<div\\b[^>]*>")
        val closingDiv = Regex("(?is)</div>\\s*$")
        if (!openingDiv.containsMatchIn(value) || !closingDiv.containsMatchIn(value)) {
            return value.trim()
        }
        val withoutOpen = value.replaceFirst(openingDiv, "")
        return withoutOpen.replaceFirst(closingDiv, "").trim()
    }

private fun renderSingleReadingSectionPlain(
        section: ReadingsSection,
        mainDisplayTitle: String?
    ): CharSequence? {
        val compactHtml = trimTrailingEmptyHtmlBlocks(section.bodyHtml)
        val renderedBody = stripHtmlFontSizeSpans(
            HtmlCompat.fromHtml(compactHtml, HtmlCompat.FROM_HTML_MODE_COMPACT)
        ).trimEnd()
        if (renderedBody.toString().trim().isEmpty()) return null
        val skipHeader = mainDisplayTitle != null &&
            liturgyTitlesSemanticallyEqual(section.title, mainDisplayTitle)
        if (skipHeader) {
            return renderedBody
        }
        val titleEscaped = Html.escapeHtml(section.title)
        val combined = "<strong>$titleEscaped</strong><br><br>$compactHtml"
        return stripHtmlFontSizeSpans(
            HtmlCompat.fromHtml(combined, HtmlCompat.FROM_HTML_MODE_COMPACT)
        ).trimEnd()
    }

    private fun liturgyTitlesSemanticallyEqual(a: String, b: String): Boolean {
        fun norm(t: String) = t.trim().lowercase(Locale.forLanguageTag("be")).replace(Regex("""\s+"""), " ")
        return norm(a) == norm(b)
    }

    private fun populateExpandableReadingsSections(sections: List<ReadingsSection>): Boolean {
        val ctx = requireContext()
        val container = binding.layoutLiturgyDayReadingsSections
        container.removeAllViews()
        val titleColor = ctx.themeColor(R.attr.totusColorTextPrimary)
        val bodyColor = ctx.themeColor(R.attr.totusColorTextPrimary)
        var addedSections = 0

        sections.forEachIndexed { index, section ->
            val compactHtml = trimTrailingEmptyHtmlBlocks(section.bodyHtml)
            val renderedBody = stripHtmlFontSizeSpans(
                HtmlCompat.fromHtml(compactHtml, HtmlCompat.FROM_HTML_MODE_COMPACT)
            ).trimEnd()
            if (renderedBody.toString().trim().isEmpty()) {
                return@forEachIndexed
            }
            val itemWrap = LinearLayout(ctx).apply {
                orientation = LinearLayout.VERTICAL
                layoutParams = LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT
                ).also { params ->
                    if (addedSections > 0) params.topMargin = dpToPx(8)
                }
            }

            val bodyView = TextView(ctx).apply {
                text = renderedBody
                setTextColor(bodyColor)
                setLineSpacing(0f, 1.12f)
                setPadding(0, dpToPx(6), 0, dpToPx(4))
                visibility = if (section.openByDefault) View.VISIBLE else View.GONE
                PrayerBookUiTypography.applyContentSp(this, R.dimen.text_list_row_subtitle, ctx)
            }

            val headerView = TextView(ctx).apply {
                val marker = if (section.openByDefault) "▾" else "▸"
                text = "$marker ${section.title}"
                setTextColor(titleColor)
                setTypeface(typeface, android.graphics.Typeface.BOLD)
                setPadding(0, 0, 0, dpToPx(2))
                isClickable = true
                isFocusable = true
                PrayerBookUiTypography.applyUiSp(this, R.dimen.text_section_header_title, ctx)
                setOnClickListener {
                    val expanded = bodyView.visibility == View.VISIBLE
                    bodyView.visibility = if (expanded) View.GONE else View.VISIBLE
                    val updatedMarker = if (expanded) "▸" else "▾"
                    text = "$updatedMarker ${section.title}"
                }
            }

            itemWrap.addView(headerView)
            itemWrap.addView(bodyView)
            container.addView(itemWrap)
            addedSections++
        }
        return addedSections > 0
    }

    private fun dpToPx(dp: Int): Int {
        val density = resources.displayMetrics.density
        return (dp * density).toInt()
    }

    private fun renderReadingsContent(content: String): CharSequence {
        val normalized = content.trim()
        if (normalized.isBlank()) {
            return getString(R.string.liturgy_readings_not_found)
        }
        return if (looksLikeHtml(normalized)) {
            val compactHtml = trimTrailingEmptyHtmlBlocks(normalized)
            val parsed = HtmlCompat.fromHtml(compactHtml, HtmlCompat.FROM_HTML_MODE_COMPACT)
            stripHtmlFontSizeSpans(parsed).trimEnd()
        } else {
            normalized.trimEnd()
        }
    }

private fun stripHtmlFontSizeSpans(content: CharSequence): CharSequence {
        val styled = SpannableStringBuilder(content)
        styled.getSpans(0, styled.length, AbsoluteSizeSpan::class.java).forEach(styled::removeSpan)
        styled.getSpans(0, styled.length, RelativeSizeSpan::class.java).forEach(styled::removeSpan)
        styled.getSpans(0, styled.length, ForegroundColorSpan::class.java).forEach(styled::removeSpan)
        return styled
    }

    private fun looksLikeHtml(value: String): Boolean {
        return Regex("<\\s*/?\\s*[a-zA-Z][^>]*>").containsMatchIn(value)
    }

    private fun trimTrailingEmptyHtmlBlocks(value: String): String {
        var result = value.trim()
        val trailingEmptyBlock = Regex(
            "(?is)(?:\\s|&nbsp;)*(?:<br\\s*/?>|<p\\b[^>]*>(?:\\s|&nbsp;|<br\\s*/?>)*</p>|<div\\b[^>]*>(?:\\s|&nbsp;|<br\\s*/?>)*</div>)+\\s*$"
        )
        while (true) {
            val updated = result.replace(trailingEmptyBlock, "").trimEnd()
            if (updated == result) break
            result = updated
        }
        return result
    }

    private fun colorIntFromName(rawColor: String): Int {
        return when (rawColor.trim().lowercase(Locale.ROOT)) {
            "green" -> Color.parseColor("#2E7D32")
            "red" -> Color.parseColor("#C62828")
            "purple", "violet" -> Color.parseColor("#6A1B9A")
            "white" -> Color.parseColor("#E5E7EB")
            "rose", "pink" -> Color.parseColor("#F48FB1")
            "black" -> Color.parseColor("#374151")
            "gray", "grey" -> Color.parseColor("#6B7280")
            else -> Color.parseColor("#6B7280")
        }
    }

    private fun bindLiturgyTitleRows(
        mainTitle: String,
        mainColor: Int,
        optionalTitle: String,
        optionalColor: Int?,
        optionalColors: List<String>
    ) {
        val ctx = context ?: return
        val container = binding.layoutLiturgyDayTitleRows
        container.removeAllViews()
        container.addView(createLiturgyOptionRow(ctx, mainTitle, mainColor))
        val optionalParts = splitOptionalMemorialTitles(optionalTitle)
        if (optionalParts.isNotEmpty() && optionalColor != null) {
            optionalParts.forEachIndexed { index, part ->
                val orView = TextView(ctx).apply {
                    text = getString(R.string.liturgy_title_or_separator)
                    setTextColor(ctx.themeColor(R.attr.totusColorTextTertiary))
                    PrayerBookUiTypography.applyUiSp(this, R.dimen.text_list_row_subtitle, ctx)
                    layoutParams = LinearLayout.LayoutParams(
                        ViewGroup.LayoutParams.MATCH_PARENT,
                        ViewGroup.LayoutParams.WRAP_CONTENT
                    ).apply {
                        topMargin = dpToPx(8)
                        bottomMargin = dpToPx(2)
                    }
                }
                container.addView(orView)
                val partColorName = optionalColors.getOrNull(index).orEmpty()
                val partColorInt = if (partColorName.isNotBlank()) {
                    colorIntFromName(partColorName)
                } else {
                    optionalColor
                }
                container.addView(createLiturgyOptionRow(ctx, part.trim(), partColorInt))
            }
        }
    }

private fun splitOptionalMemorialTitles(combined: String): List<String> =
        LiturgyOptionalMemorialSplit.split(combined)

    private fun createLiturgyOptionRow(ctx: Context, title: String, colorInt: Int): LinearLayout {
        val row = LinearLayout(ctx).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            )
        }
        val swatchSize = dpToPx(14)
        val swatch = View(ctx).apply {
            background = liturgyColorSwatchDrawable(colorInt)
            layoutParams = LinearLayout.LayoutParams(swatchSize, swatchSize).apply {
                marginEnd = dpToPx(10)
            }
        }
        val label = TextView(ctx).apply {
            text = title
            setTextColor(ctx.themeColor(R.attr.totusColorTextPrimary))
            setTypeface(typeface, Typeface.BOLD)
            PrayerBookUiTypography.applyUiSp(this, R.dimen.text_section_header_title, ctx)
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f)
        }
        row.addView(swatch)
        row.addView(label)
        return row
    }

    private fun liturgyColorSwatchDrawable(fillColor: Int): GradientDrawable {
        val r = resources.displayMetrics.density
        return GradientDrawable().apply {
            shape = GradientDrawable.RECTANGLE
            cornerRadius = 2f * r
            setColor(fillColor)
            setStroke((0.5f + r * 0.5f).toInt().coerceAtLeast(1), Color.argb(55, 255, 255, 255))
        }
    }

    private fun formatDisplayDate(rawDate: String): String {
        val parsed = runCatching { apiDateFmt.parse(rawDate) }.getOrNull()
        return if (parsed != null) {
            displayDateFmt.format(parsed)
        } else {
            rawDate
        }
    }

    private fun showNoDataDayState() {
        lastLiturgyDayDto = null
        bindLiturgyTitleRows(
            getString(R.string.liturgy_ordinary_day),
            colorIntFromName("gray"),
            "",
            null,
            emptyList()
        )
        binding.textLiturgyDaySubtitle.text = formatDisplayDate(selectedDate)
        binding.layoutLiturgyDayReadingsSections.isVisible = false
        binding.textLiturgyDayReadings.isVisible = true
        binding.textLiturgyDayReadings.text = getString(R.string.liturgy_readings_not_found)
        restoreScroll()
    }

    private fun restoreScroll() {
        binding.scrollLiturgyDay.post {
            binding.scrollLiturgyDay.scrollTo(0, preservedScrollY)

            binding.scrollLiturgyDay.post {
                binding.scrollLiturgyDay.scrollTo(0, preservedScrollY)
            }
        }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        const val ARG_DATE = "date"
    }
}
