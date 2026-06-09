package by.dzmitrypanou.catholicapp.ui.liturgy

import android.graphics.Color
import android.graphics.Canvas
import android.graphics.Path
import android.view.MotionEvent
import android.graphics.drawable.GradientDrawable
import android.graphics.drawable.Drawable
import android.graphics.PixelFormat
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.os.bundleOf
import androidx.core.graphics.ColorUtils
import androidx.core.view.doOnLayout
import by.dzmitrypanou.catholicapp.ui.themeColor
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.GridLayoutManager
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.AppColorSchemeStore
import by.dzmitrypanou.catholicapp.data.remote.LiturgyCalendarDayCellDto
import by.dzmitrypanou.catholicapp.data.remote.LiturgyCalendarMonthDto
import by.dzmitrypanou.catholicapp.databinding.FragmentLiturgyCalendarBinding
import by.dzmitrypanou.catholicapp.databinding.ItemLiturgyCalendarDayBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.time.format.TextStyle
import java.util.Locale
import kotlin.math.roundToInt
import kotlinx.coroutines.launch

class LiturgyCalendarFragment : Fragment() {

    private var _binding: FragmentLiturgyCalendarBinding? = null
    private val binding get() = _binding!!

    private val beLocale = Locale.forLanguageTag("be")
    private var monthCursor: YearMonth = YearMonth.now()
    private val apiDateFmt = DateTimeFormatter.ISO_LOCAL_DATE
    private val todayDate: String
        get() = LocalDate.now().format(apiDateFmt)
    private var dayMap: Map<String, LiturgyCalendarDayCellDto> = emptyMap()
    private lateinit var adapter: LiturgyCalendarAdapter
    private var lastLiturgyDioceseCacheKey: String? = null

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentLiturgyCalendarBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        setupCalendarGrid()
        setupCalendarSwipeNavigation()
        bindTypography()
        binding.textLiturgyPrevMonth.setOnClickListener {
            shiftMonth(-1)
        }
        binding.textLiturgyNextMonth.setOnClickListener {
            shiftMonth(1)
        }
        loadMonth()
    }

    override fun onResume() {
        super.onResume()
        bindTypography()
        val key = LiturgyDiocesePreferences.cacheKeySuffix(requireContext())
        if (lastLiturgyDioceseCacheKey != null && key != lastLiturgyDioceseCacheKey) {
            loadMonth()
        }
        lastLiturgyDioceseCacheKey = key
    }

    private fun bindTypography() {
        val ctx = context ?: return
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyMonthTitle, R.dimen.text_list_row_title, ctx)
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyCalendarNote, R.dimen.text_list_row_subtitle, ctx)
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyCalendarNoteTranslation, R.dimen.text_list_row_subtitle, ctx)
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyCalendarNoteStarMeaning, R.dimen.text_list_row_subtitle, ctx)
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyPrevMonth, 20f, ctx)
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyNextMonth, 20f, ctx)

        val weekdayRow = binding.layoutLiturgyWeekdayHeader
        for (i in 0 until weekdayRow.childCount) {
            (weekdayRow.getChildAt(i) as? android.widget.TextView)?.let {
                PrayerBookUiTypography.applyUiSp(it, 12f, ctx)
            }
        }
        adapter.notifyDataSetChanged()
    }

    private fun setupCalendarGrid() {
        adapter = LiturgyCalendarAdapter(
            onClick = { day ->
                if (!isAdded || parentFragmentManager.isStateSaved) return@LiturgyCalendarAdapter
                val navController = findNavController()
                val canNavigate =
                    navController.currentDestination
                        ?.getAction(R.id.action_nav_liturgy_calendar_to_nav_liturgy_day) != null
                if (!canNavigate) return@LiturgyCalendarAdapter
                runCatching {
                    navController.navigate(
                        R.id.action_nav_liturgy_calendar_to_nav_liturgy_day,
                        bundleOf("date" to day.date)
                    )
                }
            }
        )
        val gridLayoutManager = GridLayoutManager(requireContext(), 7).apply {
            isAutoMeasureEnabled = true
        }
        binding.recyclerviewLiturgyCalendar.layoutManager = gridLayoutManager
        binding.recyclerviewLiturgyCalendar.adapter = adapter
        binding.recyclerviewLiturgyCalendar.setHasFixedSize(false)
        binding.recyclerviewLiturgyCalendar.itemAnimator = null
    }

    private fun setupCalendarSwipeNavigation() {
        bindCalendarSwipeNavigation(binding.recyclerviewLiturgyCalendar)
    }

    private fun bindCalendarSwipeNavigation(target: View) {
        var downX = 0f
        var downY = 0f
        target.setOnTouchListener { v, event ->
            when (event.actionMasked) {
                MotionEvent.ACTION_DOWN -> {
                    downX = event.x
                    downY = event.y
                    false
                }
                MotionEvent.ACTION_MOVE -> {
                    val dx = kotlin.math.abs(event.x - downX)
                    val dy = kotlin.math.abs(event.y - downY)
                    if (dx > dy && dx > 16f * resources.displayMetrics.density) {
                        v.parent?.requestDisallowInterceptTouchEvent(true)
                    }
                    false
                }
                MotionEvent.ACTION_UP -> {
                    val dx = event.x - downX
                    val dy = event.y - downY
                    val minSwipe = 48f * resources.displayMetrics.density
                    if (kotlin.math.abs(dx) > minSwipe && kotlin.math.abs(dx) > kotlin.math.abs(dy) * 1.25f) {
                        shiftMonth(if (dx < 0f) 1 else -1)
                        true
                    } else {
                        false
                    }
                }
                else -> false
            }
        }
    }

    private fun shiftMonth(delta: Int) {
        monthCursor = monthCursor.plusMonths(delta.toLong())
        loadMonth()
    }

    private fun loadMonth() {
        val year = monthCursor.year
        val month = monthCursor.monthValue
        binding.textLiturgyMonthTitle.text = monthTitle(monthCursor)

        val cachedMonth = LiturgyCalendarRepository.getCachedMonth(requireContext(), year, month)
        if (cachedMonth != null) {

            applyMonthDto(cachedMonth)
        } else {

            dayMap = emptyMap()
            adapter.submitList(buildPlaceholderGridCells())
            adapter.setSelectedDate(todayDate)
        }

        val requestedMonth = monthCursor
        viewLifecycleOwner.lifecycleScope.launch {
            val dto = LiturgyCalendarRepository.getMonthFromNetworkAndCache(requireContext(), year, month)
            if (monthCursor != requestedMonth) return@launch
            if (dto != null) {
                applyMonthDto(dto)
            } else if (cachedMonth != null) {
                applyMonthDto(cachedMonth)
            } else {
                adapter.submitList(buildPlaceholderGridCells())
                adapter.setSelectedDate(todayDate)
            }
        }
    }

    private fun applyMonthDto(dto: LiturgyCalendarMonthDto) {
        dayMap = dto.days.associateBy { it.date }
        val cells = dto.days.map { apiDay ->
            val colorHex = apiDay.liturgicalColorHex
            UiCalendarCell(
                date = apiDay.date,
                dayNumber = apiDay.day,
                inCurrentMonth = apiDay.isCurrentMonth,
                liturgicalHexes = buildList {
                    add(colorHex)
                    if (apiDay.hasOptionalMemorial) {
                        val optionalColorNames = apiDay.optionalMemorialColors
                            ?.map { it.trim() }
                            ?.filter { it.isNotEmpty() }
                            .orEmpty()
                        if (optionalColorNames.isNotEmpty()) {
                            optionalColorNames.forEach { add(optionalMemorialColorHex(it)) }
                        } else {
                            add(optionalMemorialColorHex(apiDay.optionalMemorialColor))
                        }
                    }
                }.distinctBy { it.lowercase(Locale.ROOT) }.take(3),
                isToday = apiDay.isToday,
                isImportant = apiDay.isImportant,
                hasContent = apiDay.hasContent,
                lectionaryCount = resolveLectionaryCount(apiDay)
            )
        }
        adapter.submitList(cells)
        adapter.setSelectedDate(dto.days.firstOrNull { it.isToday }?.date.orEmpty())
    }

    private fun buildPlaceholderGridCells(): List<UiCalendarCell> {
        val first = monthCursor.atDay(1)
        val daysFromSunday = first.dayOfWeek.value % 7
        val start = first.minusDays(daysFromSunday.toLong())
        return (0 until 42).map { offset ->
            val date = start.plusDays(offset.toLong())
            UiCalendarCell(
                date = date.format(apiDateFmt),
                dayNumber = date.dayOfMonth,
                inCurrentMonth = YearMonth.from(date) == monthCursor,
                liturgicalHexes = listOf("#6B7280"),
                isToday = date.format(apiDateFmt) == todayDate,
                isImportant = false,
                hasContent = false,
                lectionaryCount = 1
            )
        }
    }

    private fun monthTitle(month: YearMonth): String {
        val raw = "${month.month.getDisplayName(TextStyle.FULL_STANDALONE, beLocale)} ${month.year}"
        return raw.replaceFirstChar { ch ->
            if (ch.isLowerCase()) ch.titlecase(beLocale) else ch.toString()
        }
    }

    private fun resolveLectionaryCount(apiDay: LiturgyCalendarDayCellDto?): Int {
        if (apiDay == null) return 1
        val titleParts = linkedSetOf<String>()
        fun collectTitles(raw: String?) {
            val src = raw?.trim().orEmpty()
            if (src.isBlank()) return
            LiturgyOptionalMemorialSplit.split(src)
                .forEach { titleParts.add(it.lowercase(Locale.ROOT)) }
        }
        collectTitles(apiDay.title)
        collectTitles(apiDay.autoTitle)
        collectTitles(apiDay.optionalMemorialTitle)
        if (titleParts.size > 1) return titleParts.size

        val explicit = listOfNotNull(
                        apiDay?.lectionaryCount,
                        apiDay?.lectionariesCount,
                        apiDay?.readingsCount,
                        apiDay?.lectionsCount,
                        apiDay?.lectionaryVariantsCount
        ).firstOrNull { it > 0 }
        if (explicit != null) return explicit

        val rawReadings = (apiDay.readingsFull ?: apiDay.readings).orEmpty()
        if (rawReadings.isNotBlank()) {
            val detailsCount = Regex("<details\\b", RegexOption.IGNORE_CASE).findAll(rawReadings).count()
            if (detailsCount > 0) return detailsCount
        }

        return 1
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    private data class UiCalendarCell(
        val date: String,
        val dayNumber: Int,
        val inCurrentMonth: Boolean,
        val liturgicalHexes: List<String>,
        val isToday: Boolean,
        val isImportant: Boolean,
        val hasContent: Boolean,
        val lectionaryCount: Int
    )

    private class LiturgyCalendarAdapter(
        private val onClick: (UiCalendarCell) -> Unit
    ) : ListAdapter<UiCalendarCell, LiturgyCalendarAdapter.DayHolder>(Diff) {

        private var selectedDate: String = ""

        fun setSelectedDate(date: String) {
            if (selectedDate == date) return
            selectedDate = date
            notifyDataSetChanged()
        }

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): DayHolder {
            val binding = ItemLiturgyCalendarDayBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return DayHolder(binding)
        }

        override fun onBindViewHolder(holder: DayHolder, position: Int) {
            holder.bind(getItem(position), selectedDate, onClick)
        }

        class DayHolder(private val binding: ItemLiturgyCalendarDayBinding) :
            RecyclerView.ViewHolder(binding.root) {
            fun bind(item: UiCalendarCell, selectedDate: String, onClick: (UiCalendarCell) -> Unit) {
                ensureSquareCell()
                val ctx = binding.root.context
                binding.textLiturgyDayNumber.text = item.dayNumber.toString()
                PrayerBookUiTypography.applyUiSp(binding.textLiturgyDayNumber, 16f, ctx)
                val fg = if (item.inCurrentMonth) {
                    ctx.themeColor(R.attr.totusColorTextPrimary)
                } else {
                    ctx.themeColor(R.attr.totusColorTextTertiary)
                }
                binding.textLiturgyDayNumber.setTextColor(fg)

                val liturgicalColors = item.liturgicalHexes
                    .mapNotNull { hex -> runCatching { Color.parseColor(hex) }.getOrNull() }
                    .ifEmpty { listOf(ctx.themeColor(R.attr.totusColorTextTertiary)) }
                val primaryColor = liturgicalColors.first()
                val secondary = liturgicalColors.getOrNull(1)
                val tertiary = liturgicalColors.getOrNull(2)

                val selected = item.date == selectedDate
                val density = ctx.resources.displayMetrics.density
                fun strokeWidthDp(dpVal: Float) = (dpVal * density).roundToInt().coerceAtLeast(1)
                val baseCardBg = when {
                    selected -> ctx.themeColor(R.attr.totusColorScriptureHighlightFill)
                    item.inCurrentMonth && item.isToday -> ColorUtils.blendARGB(
                        ctx.themeColor(R.attr.totusColorBgSecondary),
                        ctx.themeColor(R.attr.totusColorScriptureHighlightFill),
                        0.2f
                    )
                    item.inCurrentMonth -> ctx.themeColor(R.attr.totusColorBgSecondary)
                    else -> ctx.themeColor(R.attr.totusColorBgPrimary)
                }
                val primaryBlend = when {
                    selected -> 0.58f
                    item.isToday -> 0.48f
                    else -> 0.52f
                }
                val whiteWash = when {
                    selected -> 0.08f
                    item.isToday -> 0.04f
                    else -> 0.05f
                }
                val cardBg = if (item.inCurrentMonth) {

                    val tinted = ColorUtils.blendARGB(baseCardBg, primaryColor, primaryBlend)
                    ColorUtils.blendARGB(tinted, Color.WHITE, whiteWash)
                } else {
                    baseCardBg
                }
                val liturgicalBlend = when {
                    selected -> 0.62f
                    item.isToday -> 0.5f
                    else -> 0.54f
                }
                val liturgicalWhite = when {
                    selected -> 0.07f
                    item.isToday -> 0.04f
                    else -> 0.05f
                }
                val tintedLiturgical = liturgicalColors.map { src ->
                    val tinted = ColorUtils.blendARGB(baseCardBg, src, liturgicalBlend)
                    ColorUtils.blendARGB(tinted, Color.WHITE, liturgicalWhite)
                }
                val primaryTint = tintedLiturgical.first()
                val secondaryTint = tintedLiturgical.getOrNull(1)
                applyDayBackground(tintedLiturgical)
                val highlightStroke = ctx.themeColor(R.attr.totusColorScriptureHighlightStroke)
                val defaultStroke = ctx.themeColor(R.attr.totusColorSurfaceStroke)
                val stroke = when {
                    selected -> highlightStroke
                    item.isToday && item.inCurrentMonth ->
                        ColorUtils.blendARGB(highlightStroke, defaultStroke, 0.38f)
                    else -> defaultStroke
                }
                val readableTextColor = if (tintedLiturgical.size > 1) {
                    bestReadableTextColorForBackgrounds(tintedLiturgical)
                } else {
                    bestReadableTextColor(cardBg)
                }
                val bothWhiteLiturgicalColors =
                    secondary != null &&
                        isNearWhite(primaryColor) &&
                        isNearWhite(secondary) &&
                        (tertiary == null || isNearWhite(tertiary))
                val isLightScheme = AppColorSchemeStore.readScheme(ctx) == AppColorSchemeStore.Scheme.LIGHT
                binding.textLiturgyDayNumber.setTextColor(
                    if (bothWhiteLiturgicalColors) {
                        val forced = if (isLightScheme) Color.BLACK else Color.WHITE
                        if (item.inCurrentMonth) forced else ColorUtils.setAlphaComponent(forced, 190)
                    } else {
                        if (item.inCurrentMonth) readableTextColor else ColorUtils.setAlphaComponent(readableTextColor, 190)
                    }
                )
                val starColor = binding.textLiturgyDayNumber.currentTextColor
                binding.textLiturgyDayMoreStar.setTextColor(starColor)
                binding.textLiturgyDayMoreStar.visibility = if (item.lectionaryCount > 1) View.VISIBLE else View.GONE
                binding.root.setCardBackgroundColor(cardBg)
                binding.root.strokeColor = stroke
                binding.root.strokeWidth = when {
                    selected -> strokeWidthDp(2f)
                    item.isToday && item.inCurrentMonth -> strokeWidthDp(1.5f)
                    else -> strokeWidthDp(1f)
                }

                binding.root.alpha = if (item.inCurrentMonth) 1f else 0.72f
                binding.root.contentDescription = "${item.dayNumber}"
                binding.root.setOnClickListener { onClick(item) }
            }

            private fun ensureSquareCell() {
                fun applySquare(width: Int) {
                    if (width <= 0) return
                    val lp = binding.root.layoutParams ?: return
                    if (lp.height != width) {
                        lp.height = width
                        binding.root.layoutParams = lp
                    }
                }

                val widthNow = binding.root.width
                if (widthNow > 0) {
                    applySquare(widthNow)
                } else {
                    binding.root.doOnLayout { view -> applySquare(view.width) }
                }
            }

            private fun applyDayBackground(tints: List<Int>) {
                val cornerRadius = 10f * binding.root.resources.displayMetrics.density
                val bg = if (tints.size > 1) {
                    MultiSplitRoundedRectDrawable(tints, cornerRadius)
                } else {
                    GradientDrawable().apply {
                        this.cornerRadius = cornerRadius
                        setColor(tints.firstOrNull() ?: Color.GRAY)
                    }
                }
                binding.layoutLiturgyDayContent.background = bg
            }
        }

        private object Diff : DiffUtil.ItemCallback<UiCalendarCell>() {
            override fun areItemsTheSame(oldItem: UiCalendarCell, newItem: UiCalendarCell): Boolean =
                oldItem.date == newItem.date

            override fun areContentsTheSame(oldItem: UiCalendarCell, newItem: UiCalendarCell): Boolean =
                oldItem == newItem
        }

    }

    private fun optionalMemorialColorHex(colorName: String?): String {
        return when (colorName?.trim()?.lowercase(Locale.ROOT)) {
            "white" -> "#E5E7EB"
            "red" -> "#C62828"
            "purple", "violet" -> "#6A1B9A"
            "green" -> "#2E7D32"
            "rose", "pink" -> "#F48FB1"
            "black" -> "#374151"
            else -> "#E5E7EB"
        }
    }
}


private fun bestReadableTextColor(backgroundColor: Int): Int {
    val whiteContrast = ColorUtils.calculateContrast(Color.WHITE, backgroundColor)
    val blackContrast = ColorUtils.calculateContrast(Color.BLACK, backgroundColor)
    return if (whiteContrast >= blackContrast) Color.WHITE else Color.BLACK
}

private fun bestReadableTextColorForBackgrounds(colors: List<Int>): Int {
    if (colors.isEmpty()) return Color.WHITE
    val whiteWorst = colors
        .asSequence()
        .map { ColorUtils.calculateContrast(Color.WHITE, it) }
        .minOrNull() ?: 0.0
    val blackWorst = colors
        .asSequence()
        .map { ColorUtils.calculateContrast(Color.BLACK, it) }
        .minOrNull() ?: 0.0
    return if (whiteWorst >= blackWorst) Color.WHITE else Color.BLACK
}

private fun isNearWhite(color: Int): Boolean =
    Color.red(color) >= 224 && Color.green(color) >= 224 && Color.blue(color) >= 224

private class MultiSplitRoundedRectDrawable(
    private val colors: List<Int>,
    private val radiusPx: Float
) : Drawable() {
    private val paint = android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG).apply {
        style = android.graphics.Paint.Style.FILL
    }
    private val rect = android.graphics.RectF()

    override fun draw(canvas: Canvas) {
        val b = bounds
        if (b.width() <= 0 || b.height() <= 0) return
        rect.set(b)
        val roundedClip = Path().apply {
            addRoundRect(rect, radiusPx, radiusPx, Path.Direction.CW)
        }
        val segments = colors.ifEmpty { listOf(Color.GRAY) }
        val w = b.width().toFloat()
        val h = b.height().toFloat()
        val save = canvas.save()
        canvas.clipPath(roundedClip)
        segments.forEachIndexed { index, color ->
            val left = (index.toFloat() / segments.size) * w
            val right = ((index + 1).toFloat() / segments.size) * w
            paint.color = color
            canvas.drawRect(left, 0f, right, h, paint)
        }
        canvas.restoreToCount(save)
    }

    override fun setAlpha(alpha: Int) {
        paint.alpha = alpha
    }

    override fun setColorFilter(colorFilter: android.graphics.ColorFilter?) {
        paint.colorFilter = colorFilter
    }

    override fun getOpacity(): Int = PixelFormat.TRANSLUCENT
}
