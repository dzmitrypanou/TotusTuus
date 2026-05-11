package by.dzmitrypanou.catholicapp.ui.solemnities

import android.os.Bundle
import android.view.Menu
import android.view.MenuInflater
import android.view.MenuItem
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.view.MenuProvider
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import androidx.fragment.app.Fragment
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import by.dzmitrypanou.catholicapp.data.remote.SolemnityDto
import by.dzmitrypanou.catholicapp.databinding.FragmentSolemnitiesBinding
import by.dzmitrypanou.catholicapp.databinding.ItemSolemnityEntryBinding
import by.dzmitrypanou.catholicapp.databinding.ItemSolemnityGroupHeaderBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import by.dzmitrypanou.catholicapp.ui.ReadingTextScaleToolbar
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import java.time.LocalDate

class SolemnitiesFragment : Fragment() {

    private var _binding: FragmentSolemnitiesBinding? = null
    private val binding get() = _binding!!
    private lateinit var solemnitiesAdapter: SolemnitiesAdapter
    private var selectedYear: Int = 2026
    private var loadJob: Job? = null
    private val remoteItemsByYear = mutableMapOf<Int, List<SolemnityListItem>>()

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentSolemnitiesBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        selectedYear = savedInstanceState?.getInt(KEY_SELECTED_YEAR) ?: LocalDate.now().year
        setupToolbarTextScaleMenu()
        bindTypography()
        solemnitiesAdapter = SolemnitiesAdapter(
            isSectionCollapsed = { title -> !SolemnitiesSectionExpandStore.isExpanded(requireContext(), title) },
            onHeaderClick = { title -> toggleSection(title) }
        )
        binding.recyclerviewSolemnities.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerviewSolemnities.adapter = solemnitiesAdapter
        binding.buttonSolemnitiesPrevYear.setOnClickListener { changeYear(-1) }
        binding.buttonSolemnitiesNextYear.setOnClickListener { changeYear(1) }
        renderYear()
    }

    private fun setupToolbarTextScaleMenu() {
        requireActivity().addMenuProvider(
            object : MenuProvider {
                override fun onCreateMenu(menu: Menu, menuInflater: MenuInflater) = Unit

                override fun onPrepareMenu(menu: Menu) {
                    menu.findItem(R.id.action_solemnities_reading_text_scale)?.actionView?.let { actionView ->
                        bindSolemnitiesToolbarActions(actionView)
                    }
                }

                override fun onMenuItemSelected(menuItem: MenuItem): Boolean = false
            },
            viewLifecycleOwner,
            Lifecycle.State.RESUMED
        )
    }

    override fun onSaveInstanceState(outState: Bundle) {
        outState.putInt(KEY_SELECTED_YEAR, selectedYear)
        super.onSaveInstanceState(outState)
    }

    override fun onResume() {
        super.onResume()
        applyReadingTextScaleFromToolbar()
    }

    override fun onDestroyView() {
        loadJob?.cancel()
        loadJob = null
        _binding = null
        super.onDestroyView()
    }

    fun bindSolemnitiesToolbarActions(actionView: View) {
        ReadingTextScaleToolbar.bind(actionView, requireActivity()) {
            applyReadingTextScaleFromToolbar()
        }
    }

    fun applyReadingTextScaleFromToolbar() {
        bindTypography()
        if (::solemnitiesAdapter.isInitialized) {
            solemnitiesAdapter.notifyDataSetChanged()
        }
    }

    private fun changeYear(delta: Int) {
        selectedYear = (selectedYear + delta).coerceIn(MIN_YEAR, MAX_YEAR)
        renderYear()
    }

    private fun renderYear() {
        binding.textSolemnitiesYear.text = selectedYear.toString()
        binding.buttonSolemnitiesPrevYear.isEnabled = selectedYear > MIN_YEAR
        binding.buttonSolemnitiesNextYear.isEnabled = selectedYear < MAX_YEAR
        solemnitiesAdapter.submitItems(visibleItems(remoteItemsByYear[selectedYear] ?: buildItems(selectedYear)))
        loadRemoteItems(selectedYear)
    }

    private fun toggleSection(title: String) {
        val nextExpanded = !SolemnitiesSectionExpandStore.isExpanded(requireContext(), title)
        SolemnitiesSectionExpandStore.setExpanded(requireContext(), title, nextExpanded)
        solemnitiesAdapter.submitItems(visibleItems(remoteItemsByYear[selectedYear] ?: buildItems(selectedYear)))
    }

    private fun visibleItems(source: List<SolemnityListItem>): List<SolemnityListItem> {
        val out = mutableListOf<SolemnityListItem>()
        var currentCollapsed = false
        for (item in source) {
            when (item) {
                is SolemnityListItem.Header -> {
                    currentCollapsed = !SolemnitiesSectionExpandStore.isExpanded(requireContext(), item.title)
                    out.add(item)
                }
                is SolemnityListItem.Entry -> if (!currentCollapsed) out.add(item)
            }
        }
        return out
    }

    private fun loadRemoteItems(year: Int) {
        if (remoteItemsByYear.containsKey(year)) return
        loadJob?.cancel()
        loadJob = viewLifecycleOwner.lifecycleScope.launch {
            val remoteItems = runCatching {
                PrayerApiClient.service.getSolemnities(year)
            }.getOrNull()
                ?.toSolemnityListItems()
                ?.takeIf { it.isNotEmpty() }
                ?: return@launch
            remoteItemsByYear[year] = remoteItems
            if (_binding != null && selectedYear == year) {
                solemnitiesAdapter.submitItems(visibleItems(remoteItems))
            }
        }
    }

    private fun List<SolemnityDto>.toSolemnityListItems(): List<SolemnityListItem> {
        val out = mutableListOf<SolemnityListItem>()
        var lastSection = ""
        for (dto in this.sortedWith(compareBy<SolemnityDto> { it.sortOrder ?: 0 }.thenBy { it.id })) {
            val date = dto.dateLabel.trim()
            val title = dto.title.trim()
            if (date.isBlank() || title.isBlank()) continue
            val section = dto.sectionTitle.orEmpty().trim()
            if (section.isNotBlank() && section != lastSection) {
                out.add(SolemnityListItem.Header(section))
                lastSection = section
            }
            out.add(SolemnityListItem.Entry(date, title))
        }
        return out
    }

    private fun bindTypography() {
        val ctx = context ?: return
        PrayerBookUiTypography.applyContentSp(binding.textSolemnitiesYearLabel, R.dimen.text_list_row_subtitle, ctx)
        PrayerBookUiTypography.applyContentSp(binding.textSolemnitiesYear, R.dimen.text_list_row_title, ctx)
    }

    private fun buildItems(year: Int): List<SolemnityListItem> {
        val dates = MovableDates.forYear(year)
        return listOf(
        SolemnityListItem.Header(getString(R.string.solemnities_section_required)),
        SolemnityListItem.Entry("1 студзеня", "Святой Багародзіцы Марыі"),
        SolemnityListItem.Entry("6 студзеня", "Аб’яўлення Пана (Тры Каралі)"),
        SolemnityListItem.Entry("19 сакавіка", "Святога Юзафа"),
        SolemnityListItem.Entry(dates.ascension, "Унебаўшэсця Пана"),
        SolemnityListItem.Entry(dates.corpusChristi, "Цела і Крыві Хрыста (Божага Цела)"),
        SolemnityListItem.Entry("29 чэрвеня", "Святых апосталаў Пятра і Паўла"),
        SolemnityListItem.Entry("15 жніўня", "Унебаўзяцце Найсвяцейшай Панны Марыі"),
        SolemnityListItem.Entry("1 лістапада", "Усіх Святых"),
        SolemnityListItem.Entry("8 снежня", "Беззаганнага Зачацця Найсвяцейшай Панны Марыі"),
        SolemnityListItem.Entry("25 снежня", "Нараджэнне Пана"),

        SolemnityListItem.Header(getString(R.string.solemnities_section_movable)),
        SolemnityListItem.Entry(dates.ashWednesday, "Папялец"),
        SolemnityListItem.Entry(dates.easter, "Вялікдзень"),
        SolemnityListItem.Entry(dates.ascension, "Унебаўшэсце"),
        SolemnityListItem.Entry(dates.pentecost, "Спасланне Духа Святога"),
        SolemnityListItem.Entry(dates.corpusChristi, "Цела і Крыві Пана"),
        SolemnityListItem.Entry(dates.firstAdventSunday, "Першая нядзеля Адвэнту"),

        SolemnityListItem.Header(getString(R.string.solemnities_section_general_order)),
        SolemnityListItem.Entry("1 студзеня", "Урачыстасць Святой Багародзіцы Марыі"),
        SolemnityListItem.Entry("6 студзеня", "Аб’яўленне Пана, Тры Каралі"),
        SolemnityListItem.Entry("2 лютага", "Ахвяраванне Пана"),
        SolemnityListItem.Entry(dates.ashWednesday, "Папяльцовая серада – пачатак Вялікага посту"),
        SolemnityListItem.Entry("22 лютага", "Свята Катэдры святога Пятра"),
        SolemnityListItem.Entry("19 сакавіка", "Урачыстасць святога Юзафа"),
        SolemnityListItem.Entry("25 сакавіка", "Звеставанне Пана"),
        SolemnityListItem.Entry(dates.palmSunday, "Пальмовая нядзеля"),
        SolemnityListItem.Entry(dates.easter, "Уваскрасенне Пана"),
        SolemnityListItem.Entry(dates.ascension, "Унебаўшэсце Пана, урачыстасць"),
        SolemnityListItem.Entry(dates.pentecost, "Спасланне Духа Святога"),
        SolemnityListItem.Entry(dates.corpusChristi, "Урачыстасць Найсвяцейшага Цела і Крыві Хрыста"),
        SolemnityListItem.Entry(dates.sacredHeart, "Урачыстасць Найсвяцейшага Сэрца Пана Езуса"),
        SolemnityListItem.Entry("24 чэрвеня", "Нараджэнне святога Яна Хрысціцеля"),
        SolemnityListItem.Entry("29 чэрвеня", "Урачыстасць святых апосталаў Пятра і Паўла"),
        SolemnityListItem.Entry("2 ліпеня", "Урачыстасць Найсвяцейшай Панны Марыі Будслаўскай"),
        SolemnityListItem.Entry("6 жніўня", "Перамяненне Пана"),
        SolemnityListItem.Entry("15 жніўня", "Унебаўзяцце Найсвяцейшай Панны Марыі"),
        SolemnityListItem.Entry("14 верасня", "Свята Узвышэння Святога Крыжа"),
        SolemnityListItem.Entry("1 лістапада", "Урачыстасць Усіх Святых"),
        SolemnityListItem.Entry("2 лістапада", "Успамін усіх памерлых вернікаў"),
        SolemnityListItem.Entry(dates.christKing, "Урачыстасць Пана Нашага Езуса Хрыста, Валадара Сусвету"),
        SolemnityListItem.Entry("8 снежня", "Беззаганнае Зачацце Найсвяцейшай Панны Марыі"),
        SolemnityListItem.Entry("25 снежня", "Нараджэнне Пана"),
        )
    }

    private companion object {
        const val KEY_SELECTED_YEAR = "solemnities_selected_year"
        const val MIN_YEAR = 1900
        const val MAX_YEAR = 2199
    }
}

private sealed class SolemnityListItem {
    data class Header(val title: String, val note: String? = null) : SolemnityListItem()
    data class Entry(val date: String, val title: String) : SolemnityListItem()
}

private class SolemnitiesAdapter(
    private val isSectionCollapsed: (String) -> Boolean,
    private val onHeaderClick: (String) -> Unit,
    private var items: List<SolemnityListItem> = emptyList()
) : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

    fun submitItems(newItems: List<SolemnityListItem>) {
        items = newItems
        notifyDataSetChanged()
    }

    override fun getItemViewType(position: Int): Int = when (items[position]) {
        is SolemnityListItem.Header -> VIEW_TYPE_HEADER
        is SolemnityListItem.Entry -> VIEW_TYPE_ENTRY
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        return when (viewType) {
            VIEW_TYPE_HEADER -> HeaderViewHolder(
                ItemSolemnityGroupHeaderBinding.inflate(inflater, parent, false),
                isSectionCollapsed,
                onHeaderClick
            )
            else -> EntryViewHolder(
                ItemSolemnityEntryBinding.inflate(inflater, parent, false)
            )
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val item = items[position]) {
            is SolemnityListItem.Header -> (holder as HeaderViewHolder).bind(item)
            is SolemnityListItem.Entry -> (holder as EntryViewHolder).bind(item)
        }
    }

    override fun getItemCount(): Int = items.size

    private class HeaderViewHolder(
        private val binding: ItemSolemnityGroupHeaderBinding,
        private val isSectionCollapsed: (String) -> Boolean,
        private val onHeaderClick: (String) -> Unit,
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: SolemnityListItem.Header) {
            val ctx = binding.root.context
            PrayerBookUiTypography.applyContentSp(binding.textSolemnityGroupTitle, R.dimen.text_section_header_title, ctx)
            PrayerBookUiTypography.applyContentSp(binding.textSolemnityGroupNote, R.dimen.text_banner_message, ctx)
            binding.textSolemnityGroupTitle.text = item.title
            binding.textSolemnityGroupNote.text = item.note.orEmpty()
            binding.textSolemnityGroupNote.visibility = if (item.note.isNullOrBlank()) View.GONE else View.VISIBLE
            val collapsed = isSectionCollapsed(item.title)
            binding.imageSolemnityGroupExpand.setImageResource(
                if (collapsed) R.drawable.ic_expand_more_24 else R.drawable.ic_expand_less_24
            )
            binding.root.setOnClickListener { onHeaderClick(item.title) }
        }
    }

    private class EntryViewHolder(
        private val binding: ItemSolemnityEntryBinding
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: SolemnityListItem.Entry) {
            val ctx = binding.root.context
            PrayerBookUiTypography.applyContentSp(binding.textSolemnityDate, R.dimen.text_list_row_subtitle, ctx)
            PrayerBookUiTypography.applyContentSp(binding.textSolemnityTitle, R.dimen.text_list_row_title, ctx)
            binding.textSolemnityDate.text = item.date
            binding.textSolemnityTitle.text = item.title
        }
    }

    private companion object {
        const val VIEW_TYPE_HEADER = 1
        const val VIEW_TYPE_ENTRY = 2
    }
}

private data class MovableDates(
    val ashWednesday: String,
    val palmSunday: String,
    val easter: String,
    val ascension: String,
    val pentecost: String,
    val corpusChristi: String,
    val sacredHeart: String,
    val christKing: String,
    val firstAdventSunday: String,
) {
    companion object {
        fun forYear(year: Int): MovableDates {
            val easter = westernEaster(year)
            val advent = firstAdventSunday(year)
            return MovableDates(
                ashWednesday = easter.minusDays(46).formatBy(),
                palmSunday = easter.minusDays(7).formatBy(),
                easter = easter.formatBy(),
                ascension = easter.plusDays(39).formatBy(),
                pentecost = easter.plusDays(49).formatBy(),
                corpusChristi = easter.plusDays(60).formatBy(),
                sacredHeart = easter.plusDays(68).formatBy(),
                christKing = advent.minusDays(7).formatBy(),
                firstAdventSunday = advent.formatBy(),
            )
        }

        private fun westernEaster(year: Int): LocalDate {
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
            return LocalDate.of(year, month, day)
        }

        private fun firstAdventSunday(year: Int): LocalDate {
            var date = LocalDate.of(year, 11, 27)
            while (date.dayOfWeek.value != 7) {
                date = date.plusDays(1)
            }
            return date
        }
    }
}

private fun LocalDate.formatBy(): String = "$dayOfMonth ${BELARUSIAN_MONTHS[monthValue - 1]}*"

private val BELARUSIAN_MONTHS = listOf(
    "студзеня",
    "лютага",
    "сакавіка",
    "красавіка",
    "мая",
    "чэрвеня",
    "ліпеня",
    "жніўня",
    "верасня",
    "кастрычніка",
    "лістапада",
    "снежня",
)
