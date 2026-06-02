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
import java.time.Year

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
        selectedYear = savedInstanceState?.getInt(KEY_SELECTED_YEAR) ?: Year.now().value
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
        solemnitiesAdapter.submitItems(visibleItems(remoteItemsByYear[selectedYear] ?: emptyList()))
        loadRemoteItems(selectedYear)
    }

    private fun toggleSection(title: String) {
        val nextExpanded = !SolemnitiesSectionExpandStore.isExpanded(requireContext(), title)
        SolemnitiesSectionExpandStore.setExpanded(requireContext(), title, nextExpanded)
        solemnitiesAdapter.submitItems(visibleItems(remoteItemsByYear[selectedYear] ?: emptyList()))
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
