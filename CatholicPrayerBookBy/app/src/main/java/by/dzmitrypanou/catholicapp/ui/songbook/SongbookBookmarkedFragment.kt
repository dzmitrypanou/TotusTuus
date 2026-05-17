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
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.SongbookBookmarksStore
import by.dzmitrypanou.catholicapp.data.SongbookCacheInvalidationNotifier
import by.dzmitrypanou.catholicapp.data.SongbookEntry
import by.dzmitrypanou.catholicapp.data.SongbookLoadConsentStore
import by.dzmitrypanou.catholicapp.data.SongbookRepository
import by.dzmitrypanou.catholicapp.databinding.FragmentSongbookBookmarkedBinding
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

class SongbookBookmarkedFragment : Fragment(), SongbookToolbarActions {

    private var _binding: FragmentSongbookBookmarkedBinding? = null
    private val binding get() = _binding!!

    private lateinit var listAdapter: BookmarkedAdapter
    private var toolbarSongbookSyncInProgress: Boolean = false
    private val catalog: SongbookRepository.Catalog
        get() = if (arguments?.getString("catalog") == "kantaral") {
            SongbookRepository.Catalog.KANTARAL
        } else {
            SongbookRepository.Catalog.SONGBOOK
        }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentSongbookBookmarkedBinding.inflate(inflater, container, false)
        listAdapter = BookmarkedAdapter(
            showImageBadge = { catalog != SongbookRepository.Catalog.KANTARAL }
        ) { entry ->
            findNavController().navigate(
                R.id.action_nav_songbook_bookmarked_to_nav_songbook_detail,
                bundleOf(
                    "entryId" to entry.id,
                    "displayTitle" to entry.bookmarkListLabel(),
                    "displayCategory" to entry.categoryToolbarSubtitle(requireContext()),
                    "catalog" to if (catalog == SongbookRepository.Catalog.KANTARAL) "kantaral" else "songbook"
                )
            )
        }
        binding.recyclerSongbookBookmarked.adapter = listAdapter
        applyUiScale()
        loadBookmarked()
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        viewLifecycleOwner.lifecycleScope.launch {
            viewLifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
                SongbookCacheInvalidationNotifier.updates.collect {
                    loadBookmarked()
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        requireActivity().invalidateOptionsMenu()
        applyUiScale()
        if (_binding != null) {
            loadBookmarked()
        }
    }

    private fun applyUiScale() {
        val b = _binding ?: return
        PrayerBookUiTypography.applyUiSp(b.textSongbookBookmarkedEmpty, R.dimen.text_banner_message, requireContext())
        if (::listAdapter.isInitialized) {
            listAdapter.notifyDataSetChanged()
        }
    }

    private fun loadBookmarked() {
        val b = _binding ?: return
        viewLifecycleOwner.lifecycleScope.launch {
            val selectedCatalog = catalog
            val ids = SongbookBookmarksStore(requireContext(), selectedCatalog).getBookmarkedIdsOrdered()
            val list = withContext(Dispatchers.IO) {
                SongbookRepository(requireContext(), selectedCatalog).getEntriesByIds(ids)
            }
            listAdapter.submitList(list)
            b.textSongbookBookmarkedEmpty.visibility = if (list.isEmpty()) View.VISIBLE else View.GONE
            b.recyclerSongbookBookmarked.visibility = if (list.isEmpty()) View.GONE else View.VISIBLE
        }
    }

    override fun refreshSongbookDataFromToolbar() {
        if (!SongbookLoadConsentStore.isGranted(requireContext())) {
            return
        }
        viewLifecycleOwner.lifecycleScope.launch {
            toolbarSongbookSyncInProgress = true
            requireActivity().invalidateOptionsMenu()
            val repo = SongbookRepository(requireContext(), catalog)
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
            loadBookmarked()
        }
    }

    override fun isSongbookDataSyncInProgress(): Boolean = toolbarSongbookSyncInProgress

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private class BookmarkedAdapter(
        private val showImageBadge: () -> Boolean,
        private val onClick: (SongbookEntry) -> Unit
    ) : ListAdapter<SongbookEntry, BookmarkedAdapter.Holder>(Diff) {

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder {
            val itemBinding = ItemPrayerTreeBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return Holder(itemBinding, showImageBadge, onClick)
        }

        override fun onBindViewHolder(holder: Holder, position: Int) {
            holder.bind(getItem(position))
        }

        class Holder(
            private val binding: ItemPrayerTreeBinding,
            private val showImageBadge: () -> Boolean,
            private val onClick: (SongbookEntry) -> Unit
        ) : RecyclerView.ViewHolder(binding.root) {

            fun bind(entry: SongbookEntry) {
                binding.textTreeTitle.text = entry.bookmarkListLabel()
                binding.textTreeSubtitle.visibility = View.GONE
                binding.root.setOnClickListener { onClick(entry) }
                PrayerBookUiTypography.bindSongbookTreeRow(
                    binding,
                    entry,
                    binding.root.context,
                    showImageBadge = showImageBadge()
                )
            }
        }

        private object Diff : DiffUtil.ItemCallback<SongbookEntry>() {
            override fun areItemsTheSame(a: SongbookEntry, b: SongbookEntry) = a.id == b.id
            override fun areContentsTheSame(a: SongbookEntry, b: SongbookEntry) = a == b
        }
    }
}
