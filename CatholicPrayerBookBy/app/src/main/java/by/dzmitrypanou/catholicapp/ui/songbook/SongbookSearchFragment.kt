package by.dzmitrypanou.catholicapp.ui.songbook

import android.content.Context
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.EditorInfo
import android.view.inputmethod.InputMethodManager
import android.widget.Toast
import androidx.core.os.bundleOf
import androidx.core.widget.doAfterTextChanged
import androidx.fragment.app.Fragment
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.SongbookCacheInvalidationNotifier
import by.dzmitrypanou.catholicapp.data.SongbookEntry
import by.dzmitrypanou.catholicapp.data.SongbookRepository
import by.dzmitrypanou.catholicapp.databinding.FragmentSongbookSearchBinding
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

class SongbookSearchFragment : Fragment(), SongbookToolbarActions {

    private var _binding: FragmentSongbookSearchBinding? = null
    private val binding get() = _binding!!

    private var allEntries: List<SongbookEntry> = emptyList()
    private var toolbarSongbookSyncInProgress: Boolean = false
    private var lastSongbookCacheGeneration: Long = Long.MIN_VALUE
    private var entriesLoaded: Boolean = false
    private var searchJob: Job? = null
    private var applySearchJob: Job? = null

    private val adapter = SearchResultAdapter { entry ->
        findNavController().navigate(
            R.id.action_nav_songbook_search_to_nav_songbook_detail,
            bundleOf(
                "entryId" to entry.id,
                "displayTitle" to entry.listLabel(),
                "displayCategory" to entry.categoryToolbarSubtitle(requireContext())
            )
        )
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentSongbookSearchBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.recyclerSongbookSearchResults.adapter = adapter
        binding.textSongbookSearchStatus.text = getString(R.string.prayer_search_prompt)
        binding.textSongbookSearchStatus.visibility = View.VISIBLE
        adapter.submitList(emptyList())

        lastSongbookCacheGeneration = SongbookCacheInvalidationNotifier.currentGeneration()
        reloadFromCache()
        viewLifecycleOwner.lifecycleScope.launch {
            viewLifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
                SongbookCacheInvalidationNotifier.updates.collect {
                    lastSongbookCacheGeneration = SongbookCacheInvalidationNotifier.currentGeneration()
                    reloadFromCache()
                }
            }
        }

        binding.editSongbookSearchQuery.setOnEditorActionListener { _, actionId, _ ->
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                applySearch(binding.editSongbookSearchQuery.text?.toString().orEmpty())
                true
            } else {
                false
            }
        }

        binding.editSongbookSearchQuery.doAfterTextChanged { editable ->
            searchJob?.cancel()
            val raw = editable?.toString().orEmpty()
            searchJob = viewLifecycleOwner.lifecycleScope.launch {
                delay(SEARCH_DEBOUNCE_MS)
                applySearch(raw)
            }
        }

        binding.editSongbookSearchQuery.post {
            binding.editSongbookSearchQuery.requestFocus()
            val imm = requireContext().getSystemService(Context.INPUT_METHOD_SERVICE) as? InputMethodManager
            imm?.showSoftInput(binding.editSongbookSearchQuery, InputMethodManager.SHOW_IMPLICIT)
        }
    }

    override fun onResume() {
        super.onResume()
        requireActivity().invalidateOptionsMenu()
        val gen = SongbookCacheInvalidationNotifier.currentGeneration()
        if (gen != lastSongbookCacheGeneration) {
            lastSongbookCacheGeneration = gen
            reloadFromCache()
        }
        val b = _binding ?: return
        val ctx = requireContext()
        PrayerBookUiTypography.applyUiSp(b.editSongbookSearchQuery, R.dimen.text_list_row_title, ctx)
        PrayerBookUiTypography.applyUiSp(b.textSongbookSearchStatus, R.dimen.text_banner_message, ctx)
        adapter.notifyDataSetChanged()
    }

private fun reloadFromCache() {
        if (_binding == null) return
        viewLifecycleOwner.lifecycleScope.launch {
            val entries = withContext(Dispatchers.IO) {
                SongbookRepository(requireContext()).getCachedEntriesSorted()
            }
            if (_binding == null || !isAdded) return@launch
            allEntries = entries
            entriesLoaded = true
            applySearch(binding.editSongbookSearchQuery.text?.toString().orEmpty())
        }
    }

    override fun refreshSongbookDataFromToolbar() {
        viewLifecycleOwner.lifecycleScope.launch {
            toolbarSongbookSyncInProgress = true
            requireActivity().invalidateOptionsMenu()
            val repo = SongbookRepository(requireContext())
            try {
                runCatching {
                    repo.refreshFromApi(allowHashShortCircuit = true, allowNetwork = true)
                }.onFailure { e ->
                    val msg = when (e) {
                        is UnknownHostException, is ConnectException ->
                            getString(R.string.songbook_error_network)
                        is SocketTimeoutException ->
                            getString(R.string.songbook_error_timeout)
                        else -> getString(R.string.songbook_error_sync)
                    }
                    Toast.makeText(requireContext(), msg, Toast.LENGTH_LONG).show()
                }
            } finally {
                toolbarSongbookSyncInProgress = false
                if (isAdded) {
                    requireActivity().invalidateOptionsMenu()
                }
            }
            if (isAdded) {
                lastSongbookCacheGeneration = SongbookCacheInvalidationNotifier.currentGeneration()
                reloadFromCache()
            }
        }
    }

    override fun isSongbookDataSyncInProgress(): Boolean = toolbarSongbookSyncInProgress

    private fun applySearch(query: String) {
        val q = query.trim()
        if (!entriesLoaded) {
            binding.textSongbookSearchStatus.text = getString(R.string.prayer_search_prompt)
            binding.textSongbookSearchStatus.visibility = View.VISIBLE
            adapter.submitList(emptyList())
            return
        }

        applySearchJob?.cancel()
        when {
            q.isEmpty() -> {
                binding.textSongbookSearchStatus.text = getString(R.string.prayer_search_prompt)
                binding.textSongbookSearchStatus.visibility = View.VISIBLE
                adapter.submitList(emptyList())
            }
            else -> {
                applySearchJob = viewLifecycleOwner.lifecycleScope.launch {
                    val results = withContext(Dispatchers.Default) {
                        filterEntries(allEntries, q)
                    }
                    if (!isAdded) return@launch
                    if (binding.editSongbookSearchQuery.text?.toString()?.trim() != q) return@launch

                    if (results.isEmpty()) {
                        binding.textSongbookSearchStatus.text = getString(R.string.prayer_search_empty)
                        binding.textSongbookSearchStatus.visibility = View.VISIBLE
                    } else {
                        binding.textSongbookSearchStatus.visibility = View.GONE
                    }
                    adapter.submitList(results)
                }
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        searchJob?.cancel()
        applySearchJob?.cancel()
        _binding = null
    }

    private class SearchResultAdapter(
        private val onClick: (SongbookEntry) -> Unit
    ) : ListAdapter<SongbookEntry, SearchResultAdapter.Holder>(Diff) {

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder {
            val itemBinding = ItemPrayerTreeBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return Holder(itemBinding, onClick)
        }

        override fun onBindViewHolder(holder: Holder, position: Int) {
            holder.bind(getItem(position))
        }

        class Holder(
            private val binding: ItemPrayerTreeBinding,
            private val onClick: (SongbookEntry) -> Unit
        ) : RecyclerView.ViewHolder(binding.root) {

            fun bind(entry: SongbookEntry) {
                binding.textTreeTitle.text = entry.listLabel()
                binding.textTreeSubtitle.visibility = View.GONE
                binding.root.setOnClickListener { onClick(entry) }
                PrayerBookUiTypography.bindSongbookTreeRow(binding, entry, binding.root.context)
            }
        }

        private object Diff : DiffUtil.ItemCallback<SongbookEntry>() {
            override fun areItemsTheSame(a: SongbookEntry, b: SongbookEntry) = a.id == b.id
            override fun areContentsTheSame(a: SongbookEntry, b: SongbookEntry) = a == b
        }
    }

    companion object {
        private const val SEARCH_DEBOUNCE_MS = 200L

        private val htmlTagRegex = Regex("<[^>]+>")
        private val nbspRegex = Regex("&nbsp;|&#160;", RegexOption.IGNORE_CASE)

        private fun stripHtmlForSearch(html: String): String =
            nbspRegex.replace(htmlTagRegex.replace(html, " "), " ")
                .replace(Regex("\\s+"), " ")
                .trim()

        private fun filterEntries(entries: List<SongbookEntry>, query: String): List<SongbookEntry> {
            val nq = query.trim().lowercase()
            if (nq.isEmpty()) return emptyList()
            return entries.filter { matches(it, nq) }
                .distinctBy { it.id }
                .sortedWith(SongbookEntry.DISPLAY_ORDER)
        }

        private fun matches(entry: SongbookEntry, nq: String): Boolean {
            if (entry.categorySortKey().lowercase().contains(nq)) return true
            if (entry.title.lowercase().contains(nq)) return true
            if (entry.listLabel().lowercase().contains(nq)) return true
            if (entry.numberPrefix().lowercase().contains(nq)) return true
            val body = stripHtmlForSearch(entry.textBody).lowercase()
            if (body.contains(nq)) return true
            return false
        }
    }
}
