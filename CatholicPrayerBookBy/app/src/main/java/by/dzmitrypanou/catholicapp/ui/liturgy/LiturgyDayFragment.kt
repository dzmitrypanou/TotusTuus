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
import java.util.Calendar
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
    private val ordinaryWeekRegex =
        Regex("([IVXLCDM]+)(\\s+Тыдзень\\s+Звычайнага\\s+часу)", RegexOption.IGNORE_CASE)
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

    /** Пасля А± у шапцы: перамаляваць чытанні з новым [PrayerBookUiTypography.applyContentSp]. */
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
            // Show cached data with real colors to avoid gray-to-color flicker on open.
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
        val title = mainLiturgyDisplayTitle(sourceTitle, dayDate)
        val optionalMemorialRaw = day.optionalMemorialTitle.orEmpty().trim()
        val optionalMemorial = if (isPaschalOctaveWeekday(dayDate)) "" else optionalMemorialRaw
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
        val normalized = preprocessReadingsHtmlForPaschalOctave(content.trim(), apiDateYmd)
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
            val plain = renderSingleReadingSectionPlain(sections.first(), mainDisplayTitle)
            binding.layoutLiturgyDayReadingsSections.isVisible = false
            binding.textLiturgyDayReadings.isVisible = true
            binding.textLiturgyDayReadings.text = plain ?: renderReadingsContent(normalized)
            return
        }
        val hasRenderedSections = populateExpandableReadingsSections(sections)
        if (!hasRenderedSections) {
            binding.layoutLiturgyDayReadingsSections.isVisible = false
            binding.textLiturgyDayReadings.isVisible = true
            binding.textLiturgyDayReadings.text = renderReadingsContent(normalized)
            return
        }
        binding.layoutLiturgyDayReadingsSections.isVisible = true
        binding.textLiturgyDayReadings.isVisible = false
    }

    /** Выдаляе з HTML блокі <details> з успамінам (другая імша) у дні пн–сб актавы Пасхі. */
    private fun preprocessReadingsHtmlForPaschalOctave(html: String, dateRaw: String): String {
        if (!isPaschalOctaveWeekday(dateRaw)) return html
        val detailsRegex = Regex("(?is)<details\\b[^>]*>.*?</details>")
        return detailsRegex.replace(html) { match ->
            val block = match.value
            val summaryMatch = Regex("(?is)<summary\\b[^>]*>(.*?)</summary>").find(block)
                ?: return@replace block
            val plain = HtmlCompat.fromHtml(
                summaryMatch.groupValues.getOrNull(1).orEmpty(),
                HtmlCompat.FROM_HTML_MODE_COMPACT
            ).toString().trim()
            if (isOptionalMemorialReadingsSummary(plain)) "" else block
        }.trim()
    }

    private fun parseExpandableReadingsSections(value: String, apiDateYmd: String): List<ReadingsSection> {
        val detailsRegex = Regex("(?is)<details\\b[^>]*>(.*?)</details>")
        val summaryRegex = Regex("(?is)<summary\\b[^>]*>(.*?)</summary>")
        val sections = mutableListOf<ReadingsSection>()

        detailsRegex.findAll(value).forEach { detailsMatch ->
            val detailsBlock = detailsMatch.groupValues.getOrNull(1).orEmpty()
            val summaryMatch = summaryRegex.find(detailsBlock) ?: return@forEach
            val summaryHtml = summaryMatch.groupValues.getOrNull(1).orEmpty().trim()
            var summaryText = HtmlCompat.fromHtml(summaryHtml, HtmlCompat.FROM_HTML_MODE_COMPACT).toString().trim()
            summaryText = normalizeReadingsSummaryForDisplay(summaryText, apiDateYmd)
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

    /** Адна секцыя чытанняў — без раскрывання: загаловак дадаём толькі калі ён яшчэ не ў шапцы экрана. */
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

    /**
     * В API-тэксце могуць прыходзіць html-памеры (h1/font-size), якія перабіваюць глабальны маштаб.
     * Выдаляем size-span і inline-колеры, каб у светлай тэме тэкст не заставаўся белым з цёмнага рэдактара.
     */
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
                container.addView(createLiturgyOptionRow(ctx, formatOptionalMemorialPartForDisplay(part), partColorInt))
            }
        }
    }

    /**
     * Як liturgy_optional_memorial_variant_for_client_display у WebPanel: без «Чацвер — …»,
     * для даброўных варыянтаў — «Успамін — …» (кэш/API могуць яшчэ з днём тыдня).
     */
    private fun formatOptionalMemorialPartForDisplay(part: String): String {
        var t = part.trim()
        if (t.isEmpty()) return ""
        val wdStrip = Regex(
            "^(?:Панядзелак|Аўторак|Серада|Чацвер|Пятніца|Субота|Нядзеля)\\s*—\\s*(.+)$",
            RegexOption.IGNORE_CASE
        ).find(t)?.groupValues?.getOrNull(1)?.trim()
        if (wdStrip != null) t = wdStrip
        if (t.isEmpty()) return ""
        val obs = Regex(
            "^((?:Даброўны\\s+успамін|Урачыстасць|Свята|Успамін)(?:\\s*[—–\\-]\\s*|\\s+))(.*)$",
            setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL)
        ).find(t)
        val body = obs?.groupValues?.getOrNull(2)?.trim().orEmpty()
        val pre = obs?.groupValues?.getOrNull(1).orEmpty()
        return when {
            obs == null -> "Успамін — $t"
            Regex("^Даброўны\\s+успамін", RegexOption.IGNORE_CASE).containsMatchIn(pre) -> "Даброўны успамін — $body"
            Regex("^Урачыстасць", RegexOption.IGNORE_CASE).containsMatchIn(pre) -> "Урачыстасць — $body"
            Regex("^Свята", RegexOption.IGNORE_CASE).containsMatchIn(pre) -> "Свята — $body"
            Regex("^Успамін", RegexOption.IGNORE_CASE).containsMatchIn(pre) -> "Успамін — $body"
            else -> "Успамін — $t"
        }
    }

    /**
     * API можа злучаць некалькі даброўных успамінаў праз «альбо», «або», касую рысу, «;» або перанос —
     * як у [LiturgyCalendarFragment] пры падліку варыянтаў, раскладваем у асобныя радкі з паўторным «альбо».
     */
    private fun splitOptionalMemorialTitles(combined: String): List<String> {
        val raw = combined.trim()
        if (raw.isEmpty()) return emptyList()
        return raw.split(Regex("\\s+(?:альбо|або)\\s+|[/;\\n]+", RegexOption.IGNORE_CASE))
            .map { it.trim() }
            .filter { it.isNotEmpty() }
    }

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

    private fun stripLiturgicalYearSuffix(title: String): String {
        return title.trim()
            .replace(Regex(""",\s*Год\s*[A-Ca-c]\s*$""", RegexOption.IGNORE_CASE), "")
            .replace(Regex(""",\s*Год\s*A\s*,\s*B\s*,\s*C\s*$""", RegexOption.IGNORE_CASE), "")
            .trim()
    }

    private fun calendarFromApiDate(dateRaw: String): Calendar? {
        val parsed = runCatching { apiDateFmt.parse(dateRaw) }.getOrNull() ?: return null
        return Calendar.getInstance(Locale.US).apply {
            time = parsed
            clearTimePart()
        }
    }

    /** Загаловак як у API: у пн–сб актавы Пасхі заўсёды «Дзень ў актаве Пасхі», без альтэрнатыў з БД. */
    private fun mainLiturgyDisplayTitle(sourceTitle: String, dateRaw: String): String {
        val ordinary = normalizeOrdinaryWeekTitle(sourceTitle, dateRaw)
        if (isPaschalOctaveWeekday(dateRaw)) {
            val wd = calendarWeekdayNameBe(dateRaw) ?: return normalizePaschalOctaveLegacyTitle(ordinary, dateRaw)
            return "$wd ў актаве Пасхі"
        }
        return normalizePaschalOctaveLegacyTitle(ordinary, dateRaw)
    }

    private fun calendarWeekdayNameBe(dateRaw: String): String? =
        when (calendarFromApiDate(dateRaw)?.get(Calendar.DAY_OF_WEEK)) {
            Calendar.MONDAY -> "Панядзелак"
            Calendar.TUESDAY -> "Аўторак"
            Calendar.WEDNESDAY -> "Серада"
            Calendar.THURSDAY -> "Чацвер"
            Calendar.FRIDAY -> "Пятніца"
            Calendar.SATURDAY -> "Субота"
            else -> null
        }

    /** Секцыя чытанняў для даброўнага успаміна (другая імша) — у актаве Пасхі не паказваем. */
    private fun isOptionalMemorialReadingsSummary(summaryPlain: String): Boolean {
        val t = summaryPlain.lowercase(Locale.forLanguageTag("be"))
        return t.contains("успамін") || t.contains("успамінам")
    }

    private fun normalizeReadingsSummaryForDisplay(summaryPlain: String, dateRaw: String): String {
        if (!isPaschalOctaveWeekday(dateRaw)) {
            return normalizePaschalOctaveLegacyTitle(summaryPlain, dateRaw)
        }
        val trimmed = summaryPlain.trim()
        if (trimmed.isEmpty()) return trimmed
        if (isOptionalMemorialReadingsSummary(trimmed)) return ""
        val wd = calendarWeekdayNameBe(dateRaw) ?: return normalizePaschalOctaveLegacyTitle(summaryPlain, dateRaw)
        return "$wd ў актаве Пасхі"
    }

    private fun isPaschalOctaveWeekday(dateRaw: String): Boolean {
        val target = calendarFromApiDate(dateRaw) ?: return false
        if (target.get(Calendar.DAY_OF_WEEK) == Calendar.SUNDAY) return false
        val year = target.get(Calendar.YEAR)
        val easter = gregorianEaster(year)
        val from = (easter.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, 1) }
        val until = (easter.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, 6) }
        return !target.before(from) && !target.after(until)
    }

    private val paschalOctaveLegacyTitleRegex = Regex(
        """^(Панядзелак|Аўторак|Серада|Чацвер|Пятніца|Субота)\s*\p{Pd}\s*[IІ]\s+Тыдзень\s+Велікоднага\s+перыяду$""",
        RegexOption.IGNORE_CASE
    )
    private val paschalOctaveLegacyShortRegex = Regex(
        """^[IІ]\s+Тыдзень\s+Велікоднага\s+перыяду$""",
        RegexOption.IGNORE_CASE
    )

    /** API / кэш могуць утрымліваць старую форму — нармалізуем як у WebPanel («ў актаве Пасхі»). */
    private fun normalizePaschalOctaveLegacyTitle(title: String, dateRaw: String): String {
        if (!isPaschalOctaveWeekday(dateRaw)) return title
        val base = stripLiturgicalYearSuffix(title)
        if (!paschalOctaveLegacyTitleRegex.matches(base) && !paschalOctaveLegacyShortRegex.matches(base)) return title
        val wd = calendarWeekdayNameBe(dateRaw) ?: return title
        return "$wd ў актаве Пасхі"
    }

    private fun normalizeOrdinaryWeekTitle(title: String, dateRaw: String): String {
        val match = ordinaryWeekRegex.find(title) ?: return title
        val expectedWeek = ordinaryTimeWeekNumber(dateRaw) ?: return title
        val currentWeek = romanToInt(match.groupValues[1]) ?: return title
        if (currentWeek == expectedWeek) return title
        val expectedRoman = intToRoman(expectedWeek)
        val romanGroup = match.groups[1] ?: return title
        return title.replaceRange(romanGroup.range, expectedRoman)
    }

    private fun ordinaryTimeWeekNumber(dateRaw: String): Int? {
        val parsedDate = runCatching { apiDateFmt.parse(dateRaw) }.getOrNull() ?: return null
        val target = Calendar.getInstance().apply {
            time = parsedDate
            clearTimePart()
        }
        val isSunday = target.get(Calendar.DAY_OF_WEEK) == Calendar.SUNDAY
        val year = target.get(Calendar.YEAR)

        val easter = gregorianEaster(year).apply { clearTimePart() }
        val ashWednesday = (easter.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, -46) }
        val pentecost = (easter.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, 49) }
        val adventStart = firstAdventSunday(year).apply { clearTimePart() }

        val ordinaryStart = baptismOfLordMonday(year).apply { clearTimePart() }
        val beforeLentEnd = (ashWednesday.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, -1) }

        if (!target.before(ordinaryStart) && !target.after(beforeLentEnd)) {
            val days = daysBetween(ordinaryStart, target)
            val baseWeek = 1 + (days / 7)
            return baseWeek + if (isSunday) 1 else 0
        }

        val afterPentecostStart = (pentecost.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, 1) }
        val ordinaryEnd = (adventStart.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, -1) }
        if (!target.before(afterPentecostStart) && !target.after(ordinaryEnd)) {
            val firstPartDays = daysBetween(ordinaryStart, beforeLentEnd)
            val firstPartLastWeek = 1 + (firstPartDays / 7)
            val secondPartStartWeek = firstPartLastWeek + 1
            val days = daysBetween(afterPentecostStart, target)
            val baseWeek = secondPartStartWeek + (days / 7)
            return baseWeek + if (isSunday) 1 else 0
        }

        return null
    }

    private fun gregorianEaster(year: Int): Calendar {
        val a = year % 19
        val b = year / 100
        val c = year % 100
        val d = b / 4
        val e = b % 4
        val f = (b + 8) / 25
        val g = (b - f + 1) / 3
        val h = (19 * a + b - d - g + 15) % 30
        val i = c / 4
        val k = c % 4
        val l = (32 + 2 * e + 2 * i - h - k) % 7
        val m = (a + 11 * h + 22 * l) / 451
        val month = (h + l - 7 * m + 114) / 31
        val day = ((h + l - 7 * m + 114) % 31) + 1
        return Calendar.getInstance().apply {
            set(Calendar.YEAR, year)
            set(Calendar.MONTH, month - 1)
            set(Calendar.DAY_OF_MONTH, day)
            clearTimePart()
        }
    }

    private fun baptismOfLordMonday(year: Int): Calendar {
        val jan6 = Calendar.getInstance().apply {
            set(Calendar.YEAR, year)
            set(Calendar.MONTH, Calendar.JANUARY)
            set(Calendar.DAY_OF_MONTH, 6)
            clearTimePart()
        }
        val baptismSunday = (jan6.clone() as Calendar).apply {
            val delta = (Calendar.SUNDAY - get(Calendar.DAY_OF_WEEK) + 7) % 7
            add(Calendar.DAY_OF_YEAR, delta)
        }
        return baptismSunday.apply { add(Calendar.DAY_OF_YEAR, 1) }
    }

    private fun firstAdventSunday(year: Int): Calendar {
        val christmas = Calendar.getInstance().apply {
            set(Calendar.YEAR, year)
            set(Calendar.MONTH, Calendar.DECEMBER)
            set(Calendar.DAY_OF_MONTH, 25)
            clearTimePart()
        }
        val cDow = christmas.get(Calendar.DAY_OF_WEEK)
        val offset = if (cDow == Calendar.SUNDAY) 28 else cDow - 1
        return (christmas.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, -offset) }
    }

    private fun daysBetween(start: Calendar, end: Calendar): Int {
        val s = start.clone() as Calendar
        val e = end.clone() as Calendar
        s.clearTimePart()
        e.clearTimePart()
        val diffMs = e.timeInMillis - s.timeInMillis
        return (diffMs / 86_400_000L).toInt()
    }

    private fun romanToInt(roman: String): Int? {
        if (roman.isBlank()) return null
        val values = mapOf('I' to 1, 'V' to 5, 'X' to 10, 'L' to 50, 'C' to 100, 'D' to 500, 'M' to 1000)
        val s = roman.uppercase(Locale.ROOT)
        var total = 0
        var i = 0
        while (i < s.length) {
            val cur = values[s[i]] ?: return null
            val next = if (i + 1 < s.length) values[s[i + 1]] else null
            if (next != null && next > cur) {
                total += (next - cur)
                i += 2
            } else {
                total += cur
                i++
            }
        }
        return total
    }

    private fun intToRoman(value: Int): String {
        val pairs = listOf(
            1000 to "M",
            900 to "CM",
            500 to "D",
            400 to "CD",
            100 to "C",
            90 to "XC",
            50 to "L",
            40 to "XL",
            10 to "X",
            9 to "IX",
            5 to "V",
            4 to "IV",
            1 to "I"
        )
        var n = value.coerceAtLeast(1)
        val sb = StringBuilder()
        for ((arabic, roman) in pairs) {
            while (n >= arabic) {
                sb.append(roman)
                n -= arabic
            }
        }
        return sb.toString()
    }

    private fun Calendar.clearTimePart() {
        set(Calendar.HOUR_OF_DAY, 0)
        set(Calendar.MINUTE, 0)
        set(Calendar.SECOND, 0)
        set(Calendar.MILLISECOND, 0)
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
            // Some layout passes happen after text updates; restore again on next frame.
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
