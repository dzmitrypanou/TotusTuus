package by.dzmitrypanou.catholicapp.ui.songbook

import android.os.Bundle
import android.os.SystemClock
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
import by.dzmitrypanou.catholicapp.data.PrayerAutoUpdateConsentStore
import by.dzmitrypanou.catholicapp.data.SongbookCacheInvalidationNotifier
import by.dzmitrypanou.catholicapp.data.SongbookEntry
import by.dzmitrypanou.catholicapp.data.SongbookRepository
import by.dzmitrypanou.catholicapp.databinding.FragmentSongbookListBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import by.dzmitrypanou.catholicapp.ui.liturgy.LiturgyCalendarRepository
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class SongbookListFragment : Fragment(), SongbookToolbarActions {

    private var _binding: FragmentSongbookListBinding? = null
    private val binding get() = _binding!!

    private var allEntries: List<SongbookEntry> = emptyList()
    private var toolbarSongbookSyncInProgress: Boolean = false
    private var songbookListSyncBlockingUi: Boolean = false
    private var kantaralInitialSyncStarted: Boolean = false
    private var kantaralSyncProgress: SongbookRepository.SyncProgress? = null
    private var kantaralLoaderShownAtMs: Long = 0L
    private var songbookSyncJob: Job? = null
    private var kantaralTodayJob: Job? = null
    private var kantaralTodayState: KantaralTodayUiState = KantaralTodayUiState.Loading
    private var kantaralTodayLoadedForDate: String? = null
    private var lastSongbookCacheGeneration: Long = Long.MIN_VALUE
    private var lastAppliedUiSignature: String? = null
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
            navigateToSongbookEntry(entry, displayCategory)
        }

        binding.layoutKantaralTodayEntry.setOnClickListener {
            val state = kantaralTodayState as? KantaralTodayUiState.Ready ?: return@setOnClickListener
            val entry = state.entry ?: return@setOnClickListener
            navigateToSongbookEntry(entry, entry.categoryToolbarSubtitle(requireContext()))
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
            val actionId = if (catalog == SongbookRepository.Catalog.KANTARAL) {
                R.id.action_global_nav_kantaral_search
            } else {
                R.id.action_global_nav_songbook_search
            }
            findNavController().navigate(actionId)
        }
        binding.textSongbookSearchEntryTitle.setText(
            if (catalog == SongbookRepository.Catalog.KANTARAL) {
                R.string.kantaral_search_title
            } else {
                R.string.songbook_search_title
            }
        )
        binding.layoutSongbookSearchEntry.contentDescription = binding.textSongbookSearchEntryTitle.text
        binding.layoutSongbookSearchEntry.visibility = View.VISIBLE

        lastSongbookCacheGeneration = SongbookCacheInvalidationNotifier.currentGeneration()
        reloadFromCache()
        viewLifecycleOwner.lifecycleScope.launch {
            viewLifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
                SongbookCacheInvalidationNotifier.updates.collect {
                    if (toolbarSongbookSyncInProgress) return@collect
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
        if (gen != lastSongbookCacheGeneration && !toolbarSongbookSyncInProgress) {
            lastSongbookCacheGeneration = gen
            reloadFromCache()
        }
        val b = _binding ?: return
        PrayerBookUiTypography.applyUiSp(b.textSongbookEmptyCenter, R.dimen.text_banner_message, requireContext())
        PrayerBookUiTypography.applyUiSp(b.textKantaralLoadingCenter, R.dimen.text_banner_message, requireContext())
        PrayerBookUiTypography.applyUiSp(b.textKantaralTodayTitle, R.dimen.text_list_row_subtitle, requireContext())
        PrayerBookUiTypography.applyUiSp(b.textKantaralTodayStatus, R.dimen.text_list_row_title, requireContext())
        PrayerBookUiTypography.applyUiSp(
            b.textSongbookSearchEntryTitle,
            R.dimen.text_list_row_title,
            requireContext()
        )
        if (::categoriesAdapter.isInitialized && consumeUiSignatureChanged()) {
            categoriesAdapter.notifyDataSetChanged()
        }
    }

    private fun consumeUiSignatureChanged(): Boolean {
        val ctx = context ?: return false
        val signature = PrayerBookUiTypography.currentUiSignature(ctx)
        if (signature == lastAppliedUiSignature) return false

        lastAppliedUiSignature = signature
        return true
    }

    override fun onStop() {
        if (catalog == SongbookRepository.Catalog.KANTARAL) {
            songbookSyncJob?.cancel()
            songbookSyncJob = null
            toolbarSongbookSyncInProgress = false
            songbookListSyncBlockingUi = false
            kantaralSyncProgress = null
            if (isAdded) requireActivity().invalidateOptionsMenu()
        }
        super.onStop()
    }

    private fun refreshListUi() {
        val b = _binding ?: return
        val hasData = allEntries.isNotEmpty()
        b.layoutSongbookSearchEntry.visibility = View.VISIBLE
        val loading = songbookListSyncBlockingUi
        val showKantaralLoading = catalog == SongbookRepository.Catalog.KANTARAL && loading
        val showCenteredEmpty = !hasData && !loading && !toolbarSongbookSyncInProgress
        if (showKantaralLoading && kantaralLoaderShownAtMs == 0L) {
            kantaralLoaderShownAtMs = SystemClock.elapsedRealtime()
        } else if (!showKantaralLoading) {
            kantaralLoaderShownAtMs = 0L
        }
        b.textSongbookEmptyCenter.setText(R.string.songbook_empty)
        b.textSongbookEmptyCenter.visibility = if (showCenteredEmpty) View.VISIBLE else View.GONE
        b.layoutKantaralLoadingCenter.visibility = if (showKantaralLoading) View.VISIBLE else View.GONE
        bindKantaralTodayCard()
        bindKantaralLoadingProgress(showKantaralLoading)
        b.recyclerSongbookCategories.visibility = when {
            hasData -> View.VISIBLE
            loading -> View.INVISIBLE
            else -> View.GONE
        }
    }

    private fun bindKantaralLoadingProgress(visible: Boolean) {
        val b = _binding ?: return
        val progress = kantaralSyncProgress
        if (!visible || progress == null || progress.total <= 0) {
            b.progressKantaralLoadingCenter.visibility = View.GONE
            b.progressKantaralLoadingHorizontal.visibility = View.INVISIBLE
            b.progressKantaralLoadingHorizontal.isIndeterminate = false
            b.progressKantaralLoadingHorizontal.progress = 0
            b.textKantaralLoadingCenter.text = ""
            b.textKantaralLoadingCenter.visibility = View.GONE
            return
        }
        b.progressKantaralLoadingCenter.visibility = View.GONE
        b.progressKantaralLoadingHorizontal.visibility = View.VISIBLE
        b.textKantaralLoadingCenter.visibility = View.VISIBLE
        val total = progress.total.coerceAtLeast(1)
        val done = progress.done.coerceIn(0, total)
        b.progressKantaralLoadingHorizontal.isIndeterminate = false
        b.progressKantaralLoadingHorizontal.max = total
        b.progressKantaralLoadingHorizontal.progress = done
        b.textKantaralLoadingCenter.text = getString(R.string.kantaral_load_progress, done, total)
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
            if (!toolbarSongbookSyncInProgress) {
                songbookListSyncBlockingUi = false
                kantaralSyncProgress = null
            }
            val sections = withContext(Dispatchers.Default) {
                if (entries.isEmpty()) emptyList()
                else entries.groupedIntoCategorySections(appCtx)
            }
            if (_binding == null || !isAdded) return@launch
            categoriesAdapter.submitList(sections)
            refreshListUi()
            maybeLoadKantaralToday(entries)
            maybeStartInitialKantaralSync(entries)
            maybeStartVisibleKantaralUpdateCheck(entries)
        }
    }

    private fun maybeLoadKantaralToday(entries: List<SongbookEntry>) {
        if (catalog != SongbookRepository.Catalog.KANTARAL) return
        if (kantaralTodayJob?.isActive == true) return
        if (entries.isEmpty()) {
            kantaralTodayState = KantaralTodayUiState.None
            kantaralTodayLoadedForDate = null
            refreshListUi()
            return
        }
        val today = API_DATE_FORMAT.format(Date())
        if (kantaralTodayLoadedForDate == today && kantaralTodayState !is KantaralTodayUiState.Loading) {
            val currentReady = kantaralTodayState as? KantaralTodayUiState.Ready
            if (currentReady == null || entries.any { it.id == currentReady.entry.id }) {
                return
            }
        }
        kantaralTodayJob = viewLifecycleOwner.lifecycleScope.launch {
            val hasStableRenderedState = kantaralTodayLoadedForDate == today && kantaralTodayState !is KantaralTodayUiState.Loading
            val loadingShownAt = SystemClock.elapsedRealtime()
            if (!hasStableRenderedState) {
                kantaralTodayState = KantaralTodayUiState.Loading
                refreshListUi()
            }
            val appCtx = requireContext().applicationContext
            val day = withContext(Dispatchers.IO) {
                LiturgyCalendarRepository.getDayFromNetworkAndCache(appCtx, today)
                    ?: LiturgyCalendarRepository.getCachedDay(appCtx, today)
            }
            if (!hasStableRenderedState) {
                val elapsed = SystemClock.elapsedRealtime() - loadingShownAt
                val remaining = KANTARAL_TODAY_MIN_LOADING_MS - elapsed
                if (remaining > 0L) delay(remaining)
            }
            if (_binding == null || !isAdded) return@launch
            val id = day?.kantaralEntryId?.takeIf { it > 0L }?.toString()
            val entry = id?.let { entryId -> allEntries.firstOrNull { it.id == entryId } }
            kantaralTodayLoadedForDate = today
            kantaralTodayState = when {
                day == null -> KantaralTodayUiState.Error
                entry != null -> KantaralTodayUiState.Ready(entry, day.kantaralTitle.orEmpty())
                else -> KantaralTodayUiState.None
            }
            refreshListUi()
        }
    }

    private fun bindKantaralTodayCard() {
        val b = _binding ?: return
        val show = catalog == SongbookRepository.Catalog.KANTARAL && allEntries.isNotEmpty()
        b.layoutKantaralTodayEntry.visibility = if (show) View.VISIBLE else View.GONE
        if (!show) return

        val clickable = kantaralTodayState is KantaralTodayUiState.Ready
        b.layoutKantaralTodayEntry.isEnabled = clickable
        b.layoutKantaralTodayEntry.isClickable = clickable
        b.layoutKantaralTodayEntry.isFocusable = clickable
        b.imageKantaralTodayChevron.visibility = if (clickable) View.VISIBLE else View.GONE
        b.textKantaralTodayStatus.text = when (val state = kantaralTodayState) {
            KantaralTodayUiState.Loading -> getString(R.string.kantaral_today_loading)
            KantaralTodayUiState.None -> getString(R.string.kantaral_today_none)
            KantaralTodayUiState.Error -> getString(R.string.kantaral_today_error)
            is KantaralTodayUiState.Ready -> state.title.ifBlank { state.entry.listLabel() }
        }
        b.layoutKantaralTodayEntry.contentDescription =
            getString(R.string.kantaral_today_title) + ". " + b.textKantaralTodayStatus.text
    }

    private fun navigateToSongbookEntry(entry: SongbookEntry, displayCategory: String) {
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

    private fun maybeStartInitialKantaralSync(entries: List<SongbookEntry>) {
        if (catalog != SongbookRepository.Catalog.KANTARAL) return
        if (entries.isNotEmpty()) return
        if (kantaralInitialSyncStarted || toolbarSongbookSyncInProgress) return
        if (!PrayerAutoUpdateConsentStore.isGranted(requireContext())) return

        kantaralInitialSyncStarted = true
        refreshSongbookDataFromToolbar(showBlockingLoader = true)
    }

    private fun maybeStartVisibleKantaralUpdateCheck(entries: List<SongbookEntry>) {
        if (catalog != SongbookRepository.Catalog.KANTARAL) return
        if (entries.isEmpty()) return
        if (kantaralInitialSyncStarted || toolbarSongbookSyncInProgress) return
        if (!PrayerAutoUpdateConsentStore.isGranted(requireContext())) return
        if (viewLifecycleOwner.lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED).not()) return

        kantaralInitialSyncStarted = true
        refreshSongbookDataFromToolbar(showBlockingLoader = false)
    }

    override fun refreshSongbookDataFromToolbar() {
        refreshSongbookDataFromToolbar(showBlockingLoader = false)
    }

    private fun refreshSongbookDataFromToolbar(showBlockingLoader: Boolean) {
        if (songbookSyncJob?.isActive == true) return
        songbookSyncJob = viewLifecycleOwner.lifecycleScope.launch {
            toolbarSongbookSyncInProgress = true
            requireActivity().invalidateOptionsMenu()
            val appCtx = requireContext().applicationContext
            val repo = SongbookRepository(appCtx, catalog)
            try {
                val shouldShowBlockingLoader = if (catalog == SongbookRepository.Catalog.KANTARAL) {
                    val currentEntries = allEntries
                    val current = repo.isRemoteContentCurrent(
                        existingLocal = currentEntries,
                        allowNetwork = true
                    )
                    !current && (showBlockingLoader || currentEntries.isEmpty())
                } else {
                    showBlockingLoader
                }
                if (shouldShowBlockingLoader) {
                    songbookListSyncBlockingUi = true
                    kantaralSyncProgress = null
                    refreshListUi()
                }
                val list = try {
                    repo.refreshFromApi(
                        allowHashShortCircuit = true,
                        allowNetwork = true,
                        onProgress = { progress ->
                            updateKantaralProgress(progress)
                        }
                    )
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    if (_binding == null || !isAdded) return@launch
                    val msg = when (e) {
                        is UnknownHostException, is ConnectException ->
                            getString(R.string.songbook_error_network)
                        is SocketTimeoutException ->
                            getString(R.string.songbook_error_timeout)
                        else -> getString(R.string.songbook_error_sync)
                    }
                    Toast.makeText(requireContext(), msg, Toast.LENGTH_LONG).show()
                    repo.getCachedEntriesSorted()
                }
                showFinalKantaralProgressIfNeeded(list)
                holdKantaralLoaderMinimumIfNeeded()
                if (isAdded) {
                    lastSongbookCacheGeneration = SongbookCacheInvalidationNotifier.currentGeneration()
                    allEntries = list
                    maybeLoadKantaralToday(list)
                    val sections = withContext(Dispatchers.Default) {
                        if (list.isEmpty()) emptyList()
                        else list.groupedIntoCategorySections(appCtx)
                    }
                    categoriesAdapter.submitList(sections)
                    refreshListUi()
                }
            } catch (e: CancellationException) {
                if (catalog == SongbookRepository.Catalog.KANTARAL) {
                    allEntries = withContext(Dispatchers.IO) { repo.getCachedEntriesSorted() }
                }
                throw e
            } finally {
                toolbarSongbookSyncInProgress = false
                if (catalog == SongbookRepository.Catalog.KANTARAL && allEntries.isEmpty()) {
                    kantaralSyncProgress = null
                }
                songbookListSyncBlockingUi = false
                if (allEntries.isNotEmpty()) {
                    kantaralSyncProgress = null
                }
                if (songbookSyncJob === coroutineContext[Job]) {
                    songbookSyncJob = null
                }
                if (isAdded && _binding != null) {
                    refreshListUi()
                }
                if (isAdded) {
                    requireActivity().invalidateOptionsMenu()
                }
            }
        }
    }

    private suspend fun showFinalKantaralProgressIfNeeded(list: List<SongbookEntry>) {
        if (catalog != SongbookRepository.Catalog.KANTARAL) return
        if (!songbookListSyncBlockingUi || allEntries.isNotEmpty() || list.isEmpty()) return
        if (_binding == null || !isAdded) return

        kantaralSyncProgress = SongbookRepository.SyncProgress(list.size, list.size)
        refreshListUi()
        delay(KANTARAL_FINAL_PROGRESS_HOLD_MS)
    }

    private suspend fun holdKantaralLoaderMinimumIfNeeded() {
        if (catalog != SongbookRepository.Catalog.KANTARAL) return
        if (!songbookListSyncBlockingUi) return
        val shownAt = kantaralLoaderShownAtMs.takeIf { it > 0L } ?: return
        val elapsed = SystemClock.elapsedRealtime() - shownAt
        val remaining = KANTARAL_MIN_LOADER_VISIBLE_MS - elapsed
        if (remaining > 0L) {
            delay(remaining)
        }
    }

    private fun updateKantaralProgress(progress: SongbookRepository.SyncProgress) {
        if (catalog != SongbookRepository.Catalog.KANTARAL) return
        if (!songbookListSyncBlockingUi) return
        viewLifecycleOwner.lifecycleScope.launch(Dispatchers.Main.immediate) {
            if (_binding == null || !isAdded) return@launch
            kantaralSyncProgress = progress
            refreshListUi()
        }
    }

    override fun isSongbookDataSyncInProgress(): Boolean = toolbarSongbookSyncInProgress

    override fun showSongbookToolbarSyncProgress(): Boolean = catalog != SongbookRepository.Catalog.KANTARAL

    override fun onDestroyView() {
        kantaralTodayJob?.cancel()
        kantaralTodayJob = null
        super.onDestroyView()
        _binding = null
    }

    private sealed class KantaralTodayUiState {
        object Loading : KantaralTodayUiState()
        object None : KantaralTodayUiState()
        object Error : KantaralTodayUiState()
        data class Ready(val entry: SongbookEntry, val title: String) : KantaralTodayUiState()
    }

    companion object {
        private const val KANTARAL_FINAL_PROGRESS_HOLD_MS = 180L
        private const val KANTARAL_MIN_LOADER_VISIBLE_MS = 1_000L
        private const val KANTARAL_TODAY_MIN_LOADING_MS = 1_000L
        private val API_DATE_FORMAT = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    }
}
