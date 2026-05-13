package by.dzmitrypanou.catholicapp.ui.songbook

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.core.os.bundleOf
import androidx.fragment.app.Fragment
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.LinearLayoutManager
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.SongbookCacheInvalidationNotifier
import by.dzmitrypanou.catholicapp.data.SongbookEntry
import by.dzmitrypanou.catholicapp.data.SongbookRepository
import by.dzmitrypanou.catholicapp.databinding.FragmentSongbookListBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

class SongbookListFragment : Fragment(), SongbookToolbarActions {

    private var _binding: FragmentSongbookListBinding? = null
    private val binding get() = _binding!!

    private var allEntries: List<SongbookEntry> = emptyList()
    private var toolbarSongbookSyncInProgress: Boolean = false
    private var songbookListSyncBlockingUi: Boolean = false
    private var lastSongbookCacheGeneration: Long = Long.MIN_VALUE
    private val catalog: SongbookRepository.Catalog
        get() = if (arguments?.getString("catalog") == "kantaral") {
            SongbookRepository.Catalog.KANTARAL
        } else {
            SongbookRepository.Catalog.SONGBOOK
        }

    private lateinit var categoriesAdapter: SongbookCategoryBlocksAdapter

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentSongbookListBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        val appCtx = requireContext().applicationContext
        categoriesAdapter = SongbookCategoryBlocksAdapter(appCtx) { entry, displayCategory ->
            findNavController().navigate(
                if (catalog == SongbookRepository.Catalog.KANTARAL) {
                    R.id.action_nav_kantaral_to_nav_songbook_detail
                } else {
                    R.id.action_nav_songbook_to_nav_songbook_detail
                },
                bundleOf(
                    "entryId" to entry.id,
                    "displayTitle" to entry.listLabel(),
                    "displayCategory" to displayCategory,
                    "catalog" to if (catalog == SongbookRepository.Catalog.KANTARAL) "kantaral" else "songbook"
                )
            )
        }

        binding.recyclerSongbookCategories.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerSongbookCategories.adapter = categoriesAdapter
        binding.recyclerSongbookCategories.setHasFixedSize(false)
        binding.recyclerSongbookCategories.itemAnimator = null
        val blockGap = resources.getDimensionPixelSize(R.dimen.prayer_book_block_gap)
        binding.recyclerSongbookCategories.addItemDecoration(
            SongbookCategoryBlocksTopSpacingDecoration(blockGap)
        )

        binding.layoutSongbookSearchEntry.setOnClickListener {
            findNavController().navigate(R.id.action_global_nav_songbook_search)
        }
        binding.layoutSongbookSearchEntry.visibility =
            if (catalog == SongbookRepository.Catalog.KANTARAL) View.GONE else View.VISIBLE

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
        PrayerBookUiTypography.applyUiSp(b.textSongbookEmptyCenter, R.dimen.text_banner_message, requireContext())
        PrayerBookUiTypography.applyUiSp(
            b.textSongbookSearchEntryTitle,
            R.dimen.text_list_row_title,
            requireContext()
        )
        if (::categoriesAdapter.isInitialized) {
            categoriesAdapter.notifyDataSetChanged()
        }
    }

    private fun refreshListUi() {
        val b = _binding ?: return
        val hasData = allEntries.isNotEmpty()
        b.layoutSongbookSearchEntry.visibility =
            if (catalog == SongbookRepository.Catalog.KANTARAL) View.GONE else View.VISIBLE
        val loading = songbookListSyncBlockingUi
        val showCenteredEmpty = !hasData && !loading
        b.textSongbookEmptyCenter.setText(R.string.songbook_empty)
        b.textSongbookEmptyCenter.visibility = if (showCenteredEmpty) View.VISIBLE else View.GONE
        b.recyclerSongbookCategories.visibility = when {
            hasData -> View.VISIBLE
            loading -> View.INVISIBLE
            else -> View.GONE
        }
    }

    private fun reloadFromCache() {
        if (_binding == null) return
        viewLifecycleOwner.lifecycleScope.launch {
            val appCtx = requireContext().applicationContext
            val entries = withContext(Dispatchers.IO) {
                SongbookRepository(appCtx, catalog).getCachedEntriesSorted()
            }
            if (_binding == null || !isAdded) return@launch
            allEntries = entries
            songbookListSyncBlockingUi = false
            val sections = withContext(Dispatchers.Default) {
                if (entries.isEmpty()) emptyList()
                else entries.groupedIntoCategorySections(appCtx)
            }
            if (_binding == null || !isAdded) return@launch
            categoriesAdapter.submitList(sections)
            refreshListUi()
        }
    }

    override fun refreshSongbookDataFromToolbar() {
        viewLifecycleOwner.lifecycleScope.launch {
            toolbarSongbookSyncInProgress = true
            requireActivity().invalidateOptionsMenu()
            val appCtx = requireContext().applicationContext
            val repo = SongbookRepository(appCtx, catalog)
            try {
                val list = runCatching {
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
                }.getOrElse { repo.getCachedEntriesSorted() }
                if (isAdded) {
                    lastSongbookCacheGeneration = SongbookCacheInvalidationNotifier.currentGeneration()
                    allEntries = list
                    val sections = withContext(Dispatchers.Default) {
                        if (list.isEmpty()) emptyList()
                        else list.groupedIntoCategorySections(appCtx)
                    }
                    categoriesAdapter.submitList(sections)
                    refreshListUi()
                }
            } finally {
                toolbarSongbookSyncInProgress = false
                if (isAdded) {
                    requireActivity().invalidateOptionsMenu()
                }
            }
        }
    }

    override fun isSongbookDataSyncInProgress(): Boolean = toolbarSongbookSyncInProgress

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
