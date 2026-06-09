package by.dzmitrypanou.catholicapp.ui.transform

import android.content.Context
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.EditorInfo
import android.view.inputmethod.InputMethodManager
import androidx.core.os.bundleOf
import androidx.core.widget.doAfterTextChanged
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.Prayer
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.databinding.FragmentPrayerSearchBinding
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class PrayerSearchFragment : Fragment() {

    private var _binding: FragmentPrayerSearchBinding? = null
    private val binding get() = _binding!!

    private var allPrayers: List<Prayer> = emptyList()
    private var prayersLoaded: Boolean = false
    private var searchJob: Job? = null
    private var applySearchJob: Job? = null

    private val adapter = SearchResultAdapter { prayer ->
        findNavController().navigate(
            R.id.action_nav_prayer_search_to_nav_prayer_detail,
            bundleOf(
                "prayerId" to prayer.id,
                "title" to prayer.title,
                "text" to prayer.text,
                "category" to (prayer.category.orEmpty()),
                "subcategory" to (prayer.subcategory.orEmpty())
            )
        )
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentPrayerSearchBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.recyclerSearchResults.adapter = adapter
        binding.textSearchStatus.text = getString(R.string.prayer_search_prompt)
        binding.textSearchStatus.visibility = View.VISIBLE
        adapter.submitList(emptyList())

        viewLifecycleOwner.lifecycleScope.launch {
            val prayers = withContext(Dispatchers.Default) {
                PrayerRepository(requireContext().applicationContext).getCachedPrayers()
            }
            if (!isAdded) return@launch
            allPrayers = prayers
            prayersLoaded = true
            applySearch(binding.editSearchQuery.text?.toString().orEmpty())
        }

        binding.editSearchQuery.setOnEditorActionListener { _, actionId, _ ->
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                applySearch(binding.editSearchQuery.text?.toString().orEmpty())
                true
            } else {
                false
            }
        }

        binding.editSearchQuery.doAfterTextChanged { editable ->
            searchJob?.cancel()
            val raw = editable?.toString().orEmpty()
            searchJob = viewLifecycleOwner.lifecycleScope.launch {
                delay(SEARCH_DEBOUNCE_MS)
                applySearch(raw)
            }
        }

        binding.editSearchQuery.post {
            binding.editSearchQuery.requestFocus()
            val imm = requireContext().getSystemService(Context.INPUT_METHOD_SERVICE) as? InputMethodManager
            imm?.showSoftInput(binding.editSearchQuery, InputMethodManager.SHOW_IMPLICIT)
        }
    }

    override fun onResume() {
        super.onResume()
        val b = _binding ?: return
        val ctx = requireContext()
        PrayerBookUiTypography.applyUiSp(b.editSearchQuery, R.dimen.text_list_row_title, ctx)
        PrayerBookUiTypography.applyUiSp(b.textSearchStatus, R.dimen.text_banner_message, ctx)
        adapter.notifyDataSetChanged()
    }

    private fun applySearch(query: String) {
        val q = query.trim()
        if (!prayersLoaded) {
            binding.textSearchStatus.text = getString(R.string.prayer_search_prompt)
            binding.textSearchStatus.visibility = View.VISIBLE
            adapter.submitList(emptyList())
            return
        }

        applySearchJob?.cancel()
        when {
            q.isEmpty() -> {
                binding.textSearchStatus.text = getString(R.string.prayer_search_prompt)
                binding.textSearchStatus.visibility = View.VISIBLE
                adapter.submitList(emptyList())
            }
            else -> {
                applySearchJob = viewLifecycleOwner.lifecycleScope.launch {
                    val results = withContext(Dispatchers.Default) {
                        filterPrayers(allPrayers, q)
                    }
                    if (!isAdded) return@launch
                    if (binding.editSearchQuery.text?.toString()?.trim() != q) return@launch

                    if (results.isEmpty()) {
                        binding.textSearchStatus.text = getString(R.string.prayer_search_empty)
                        binding.textSearchStatus.visibility = View.VISIBLE
                    } else {
                        binding.textSearchStatus.visibility = View.GONE
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
        private val onClick: (Prayer) -> Unit
    ) : ListAdapter<Prayer, SearchResultAdapter.Holder>(Diff) {

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder {
            val itemBinding = ItemPrayerTreeBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return Holder(itemBinding, onClick)
        }

        override fun onBindViewHolder(holder: Holder, position: Int) {
            holder.bind(getItem(position))
        }

        class Holder(
            private val binding: ItemPrayerTreeBinding,
            private val onClick: (Prayer) -> Unit
        ) : RecyclerView.ViewHolder(binding.root) {

            fun bind(prayer: Prayer) {
                binding.textTreeTitle.text = prayer.title
                val meta = buildMetaLine(prayer)
                if (meta.isNotBlank()) {
                    binding.textTreeSubtitle.text = meta
                    binding.textTreeSubtitle.visibility = View.VISIBLE
                } else {
                    binding.textTreeSubtitle.text = ""
                    binding.textTreeSubtitle.visibility = View.GONE
                }
                binding.root.setOnClickListener { onClick(prayer) }
                PrayerBookUiTypography.bindPrayerTreeRow(binding, binding.root.context)
            }

            private fun buildMetaLine(prayer: Prayer): String {
                val cat = prayer.category?.trim().orEmpty()
                val sub = prayer.subcategory?.trim().orEmpty()
                val ctx = binding.root.context
                return when {
                    cat.isNotBlank() && sub.isNotBlank() && sub != PrayerRepository.NO_SUBCATEGORY_TITLE ->
                        "$cat • $sub"
                    cat.isNotBlank() -> cat
                    sub.isNotBlank() && sub != PrayerRepository.NO_SUBCATEGORY_TITLE -> sub
                    else -> ctx.getString(R.string.prayer_bucket_no_category)
                }
            }
        }

        private object Diff : DiffUtil.ItemCallback<Prayer>() {
            override fun areItemsTheSame(oldItem: Prayer, newItem: Prayer): Boolean = oldItem.id == newItem.id
            override fun areContentsTheSame(oldItem: Prayer, newItem: Prayer): Boolean = oldItem == newItem
        }
    }

    companion object {
        private const val SEARCH_DEBOUNCE_MS = 200L

        private val htmlTagRegex = Regex("<[^>]+>")
        private val nbspRegex = Regex("&nbsp;|&#160;", RegexOption.IGNORE_CASE)

        private fun stripHtmlForSearch(html: String): String {
            return nbspRegex.replace(htmlTagRegex.replace(html, " "), " ")
                .replace(Regex("\\s+"), " ")
                .trim()
        }

        private fun filterPrayers(prayers: List<Prayer>, query: String): List<Prayer> {
            val nq = normalizeSearchText(query)
            if (nq.isEmpty()) return emptyList()
            return prayers.filter { matches(it, nq) }
                .distinctBy { it.id }
                .sortedWith(compareBy({ it.sortOrder }, { it.id }))
        }

        private fun matches(prayer: Prayer, nq: String): Boolean {
            if (normalizeSearchText(prayer.title).contains(nq)) return true
            if (normalizeSearchText(prayer.category.orEmpty()).contains(nq)) return true
            if (normalizeSearchText(prayer.subcategory.orEmpty()).contains(nq)) return true
            if (prayer.additionalCategories.any { normalizeSearchText(it).contains(nq) }) return true
            val body = normalizeSearchText(stripHtmlForSearch(prayer.text))
            if (body.contains(nq)) return true
            return false
        }

        private fun normalizeSearchText(value: String): String =
            value.trim().lowercase().replace('i', 'і')
    }
}
