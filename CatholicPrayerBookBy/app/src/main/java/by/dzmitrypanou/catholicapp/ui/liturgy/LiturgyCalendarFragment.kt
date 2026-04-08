package by.dzmitrypanou.catholicapp.ui.liturgy

import android.graphics.Color
import android.graphics.PorterDuff
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
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import kotlinx.coroutines.launch

class LiturgyCalendarFragment : Fragment() {

    private var _binding: FragmentLiturgyCalendarBinding? = null
    private val binding get() = _binding!!

    private val monthCursor: Calendar = Calendar.getInstance().apply {
        set(Calendar.DAY_OF_MONTH, 1)
        clearTimePart()
    }
    private val apiDateFmt = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val monthTitleFmt = SimpleDateFormat("LLLL yyyy", Locale.forLanguageTag("be"))
    private val todayDate: String
        get() = apiDateFmt.format(Date())
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
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyPrevMonth, 20f, ctx)
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyNextMonth, 20f, ctx)
        // Масштабуем заголовкі дзён тыдня (Нд, Пн, …)
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
        var downX = 0f
        var downY = 0f
        binding.recyclerviewLiturgyCalendar.setOnTouchListener { v, event ->
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
        monthCursor.add(Calendar.MONTH, delta)
        monthCursor.set(Calendar.DAY_OF_MONTH, 1)
        loadMonth()
    }

    private fun loadMonth() {
        val year = monthCursor.get(Calendar.YEAR)
        val month = monthCursor.get(Calendar.MONTH) + 1
        binding.textLiturgyMonthTitle.text = monthTitleFmt.format(monthCursor.time).replaceFirstChar { ch ->
            if (ch.isLowerCase()) ch.titlecase(Locale.forLanguageTag("be")) else ch.toString()
        }

        val cachedMonth = LiturgyCalendarRepository.getCachedMonth(requireContext(), year, month)
        if (cachedMonth != null) {
            // Show cached content with its real colors to avoid gray-to-color flicker on open.
            applyMonthDto(cachedMonth, forceGray = false)
        } else {
            // If there is no cache, show a gray fallback month grid.
            dayMap = emptyMap()
            val cells = buildGridCells(forceGray = true)
            adapter.submitList(cells)
            adapter.setSelectedDate(todayDate)
        }

        viewLifecycleOwner.lifecycleScope.launch {
            val dto = LiturgyCalendarRepository.getMonthFromNetworkAndCache(requireContext(), year, month)
            if (dto != null) {
                applyMonthDto(dto, forceGray = false)
            } else if (cachedMonth != null) {
                applyMonthDto(cachedMonth, forceGray = false)
            }
        }
    }

    private fun applyMonthDto(dto: LiturgyCalendarMonthDto, forceGray: Boolean) {
        dayMap = dto.days.associateBy { it.date }
        val cells = buildGridCells(forceGray = forceGray)
        adapter.submitList(cells)
        adapter.setSelectedDate(todayDate)
    }

    private fun buildGridCells(forceGray: Boolean = false): List<UiCalendarCell> {
        val start = (monthCursor.clone() as Calendar).apply {
            set(Calendar.DAY_OF_MONTH, 1)
            clearTimePart()
            val offset = get(Calendar.DAY_OF_WEEK) - Calendar.SUNDAY
            add(Calendar.DAY_OF_MONTH, -offset)
        }

        val result = ArrayList<UiCalendarCell>(42)
        val currentMonth = monthCursor.get(Calendar.MONTH)
        for (i in 0 until 42) {
            val c = start.clone() as Calendar
            c.add(Calendar.DAY_OF_MONTH, i)
            val date = apiDateFmt.format(c.time)
            val apiDay = dayMap[date]
            val inCurrent = c.get(Calendar.MONTH) == currentMonth
            val colorHex = if (forceGray) "#6B7280" else (apiDay?.liturgicalColorHex ?: "#6B7280")
            result.add(
                UiCalendarCell(
                    date = date,
                    dayNumber = c.get(Calendar.DAY_OF_MONTH),
                    inCurrentMonth = inCurrent,
                    liturgicalHexes = if (forceGray) {
                        listOf("#6B7280")
                    } else {
                        buildList {
                            add(colorHex)
                            if (apiDay?.hasOptionalMemorial == true) {
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
                        }.distinctBy { it.lowercase(Locale.ROOT) }.take(3)
                    },
                    isToday = apiDay?.isToday == true,
                    isImportant = apiDay?.isImportant == true,
                    hasContent = apiDay?.hasContent == true,
                    lectionaryCount = resolveLectionaryCount(apiDay)
                )
            )
        }
        return result
    }

    private fun resolveLectionaryCount(apiDay: LiturgyCalendarDayCellDto?): Int {
        if (apiDay == null) return 1
        val titleParts = linkedSetOf<String>()
        fun collectTitles(raw: String?) {
            val src = raw?.trim().orEmpty()
            if (src.isBlank()) return
            src.split(Regex("\\s+альбо\\s+|[/;\\n]+", RegexOption.IGNORE_CASE))
                .map { it.trim() }
                .filter { it.isNotEmpty() }
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
                val baseCardBg = when {
                    selected -> ctx.themeColor(R.attr.totusColorScriptureHighlightFill)
                    item.inCurrentMonth -> ctx.themeColor(R.attr.totusColorBgSecondary)
                    else -> ctx.themeColor(R.attr.totusColorBgPrimary)
                }
                val cardBg = if (item.inCurrentMonth) {
                    // Keep liturgical color visible directly in the day cell background.
                    val tinted = ColorUtils.blendARGB(baseCardBg, primaryColor, if (selected) 0.42f else 0.30f)
                    ColorUtils.blendARGB(tinted, Color.WHITE, if (selected) 0.16f else 0.13f)
                } else {
                    baseCardBg
                }
                val tintedLiturgical = liturgicalColors.map { src ->
                    val tinted = ColorUtils.blendARGB(baseCardBg, src, if (selected) 0.48f else 0.36f)
                    ColorUtils.blendARGB(tinted, Color.WHITE, if (selected) 0.18f else 0.15f)
                }
                val primaryTint = tintedLiturgical.first()
                val secondaryTint = tintedLiturgical.getOrNull(1)
                applyDayBackground(tintedLiturgical)
                val stroke = if (selected) {
                    ctx.themeColor(R.attr.totusColorScriptureHighlightStroke)
                } else {
                    ctx.themeColor(R.attr.totusColorSurfaceStroke)
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
                val moreIconColor = binding.textLiturgyDayNumber.currentTextColor
                binding.imageLiturgyDayMore.setColorFilter(moreIconColor, PorterDuff.Mode.SRC_IN)
                binding.imageLiturgyDayMore.visibility = if (item.lectionaryCount > 1) View.VISIBLE else View.GONE
                binding.root.setCardBackgroundColor(cardBg)
                binding.root.strokeColor = stroke
                binding.root.strokeWidth = if (selected) 2 else 1

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

private fun Calendar.clearTimePart() {
    set(Calendar.HOUR_OF_DAY, 0)
    set(Calendar.MINUTE, 0)
    set(Calendar.SECOND, 0)
    set(Calendar.MILLISECOND, 0)
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

