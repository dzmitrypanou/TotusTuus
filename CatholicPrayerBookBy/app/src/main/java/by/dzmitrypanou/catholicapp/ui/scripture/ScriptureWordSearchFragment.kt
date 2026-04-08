package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import android.graphics.Typeface
import android.os.Bundle
import android.text.SpannableString
import android.text.style.ForegroundColorSpan
import android.text.style.StyleSpan
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.EditorInfo
import android.view.inputmethod.InputMethodManager
import androidx.core.content.ContextCompat
import androidx.core.os.bundleOf
import androidx.core.widget.doAfterTextChanged
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureWordSearchBinding
import by.dzmitrypanou.catholicapp.databinding.ItemScriptureWordSearchResultBinding
import by.dzmitrypanou.catholicapp.sync.ScriptureRemoteSync
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class ScriptureWordSearchFragment : Fragment(), ScriptureToolbarActions {

    private var _binding: FragmentScriptureWordSearchBinding? = null
    private val binding get() = _binding!!

    private var searchJob: Job? = null

    private val adapter = ScriptureWordSearchAdapter { hit ->
        findNavController().navigate(
            R.id.nav_scripture_chapter_text,
            bundleOf(
                ScriptureChaptersFragment.ARG_BOOK_ID to hit.bookId,
                ScriptureChaptersFragment.ARG_BOOK_TITLE to hit.bookName,
                ScriptureChaptersFragment.ARG_CHAPTER to hit.chapter
            )
        )
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureWordSearchBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.recyclerScriptureSearchResults.adapter = adapter

        binding.editScriptureSearchQuery.setOnEditorActionListener { _, actionId, _ ->
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                applySearch(binding.editScriptureSearchQuery.text?.toString().orEmpty())
                true
            } else {
                false
            }
        }

        binding.editScriptureSearchQuery.doAfterTextChanged { editable ->
            searchJob?.cancel()
            val raw = editable?.toString().orEmpty()
            searchJob = viewLifecycleOwner.lifecycleScope.launch {
                delay(SEARCH_DEBOUNCE_MS)
                applySearch(raw)
            }
        }

        binding.editScriptureSearchQuery.post {
            binding.editScriptureSearchQuery.requestFocus()
            val imm = requireContext().getSystemService(Context.INPUT_METHOD_SERVICE) as? InputMethodManager
            imm?.showSoftInput(binding.editScriptureSearchQuery, InputMethodManager.SHOW_IMPLICIT)
        }

        applySearch(binding.editScriptureSearchQuery.text?.toString().orEmpty())
    }

    override fun onResume() {
        super.onResume()
        val b = _binding ?: return
        val ctx = requireContext()
        ScriptureUiTypography.applyUiSp(b.editScriptureSearchQuery, 18f, ctx)
        ScriptureUiTypography.applyUiSp(b.textScriptureSearchStatus, 15f, ctx)
        adapter.notifyDataSetChanged()
        lifecycleScope.launch {
            val translationId = ScriptureTranslationStore.getSelectedTranslationId(ctx)
            val sync = ScriptureRemoteSync.refreshTranslation(ctx, translationId, forceRefresh = false)
            if (!isAdded) return@launch
            if (sync == ScriptureRemoteSync.Result.DiskCacheUpdated) {
                applySearch(b.editScriptureSearchQuery.text?.toString().orEmpty())
            }
        }
    }

    override fun onScriptureTextScaleChanged() {
        val b = _binding ?: return
        val ctx = requireContext()
        ScriptureUiTypography.applyUiSp(b.editScriptureSearchQuery, 18f, ctx)
        ScriptureUiTypography.applyUiSp(b.textScriptureSearchStatus, 15f, ctx)
        adapter.notifyDataSetChanged()
    }

    private fun applySearch(query: String) {
        val q = query.trim()
        if (q.isEmpty()) {
            binding.textScriptureSearchStatus.text = ""
            binding.textScriptureSearchStatus.visibility = View.GONE
            adapter.searchQuery = ""
            adapter.submitList(emptyList())
            return
        }

        val ctx = requireContext().applicationContext
        val translationId = ScriptureTranslationStore.getSelectedTranslationId(ctx)
        viewLifecycleOwner.lifecycleScope.launch {
            val result = withContext(Dispatchers.Default) {
                ScriptureTextRepository.searchWord(ctx, translationId, q)
            }
            if (!isAdded) return@launch
            if (binding.editScriptureSearchQuery.text?.toString()?.trim() != q) return@launch

            if (result.totalOccurrences == 0) {
                adapter.searchQuery = q
                binding.textScriptureSearchStatus.text = getString(R.string.scripture_word_search_empty)
                binding.textScriptureSearchStatus.visibility = View.VISIBLE
                adapter.submitList(emptyList())
            } else {
                adapter.searchQuery = q
                binding.textScriptureSearchStatus.text = getString(
                    R.string.scripture_word_search_stats,
                    result.queryDisplay,
                    result.totalOccurrences,
                    result.versesWithMatches
                )
                binding.textScriptureSearchStatus.visibility = View.VISIBLE
                adapter.submitList(result.hits)
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    companion object {
        private const val SEARCH_DEBOUNCE_MS = 320L
    }
}

private class ScriptureWordSearchAdapter(
    private val onClick: (ScriptureWordSearchHit) -> Unit
) : ListAdapter<ScriptureWordSearchHit, ScriptureWordSearchAdapter.VH>(Diff) {

    var searchQuery: String = ""

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val inflater = LayoutInflater.from(parent.context)
        val binding = ItemScriptureWordSearchResultBinding.inflate(inflater, parent, false)
        return VH(binding, onClick)
    }

    override fun onBindViewHolder(holder: VH, position: Int) {
        holder.bind(getItem(position), searchQuery)
    }

    class VH(
        private val binding: ItemScriptureWordSearchResultBinding,
        private val onClick: (ScriptureWordSearchHit) -> Unit
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(hit: ScriptureWordSearchHit, query: String) {
            val ctx = binding.root.context
            binding.textScriptureSearchRef.text = ctx.getString(
                R.string.scripture_compare_ref_format,
                hit.bookName,
                hit.chapter,
                hit.verse
            )
            binding.root.contentDescription = ctx.getString(
                R.string.scripture_word_search_open_verse_a11y,
                hit.bookName,
                hit.chapter,
                hit.verse
            )
            binding.textScriptureSearchVerse.text = highlightedVerse(hit.text, query, ctx)
            binding.root.setOnClickListener { onClick(hit) }
            ScriptureUiTypography.applyUiSp(binding.textScriptureSearchRef, 15f, ctx)
            ScriptureUiTypography.applyReadingSp(binding.textScriptureSearchVerse, 18f, ctx)
        }

        private fun highlightedVerse(text: String, query: String, ctx: Context): CharSequence {
            val trimmed = query.trim()
            if (trimmed.isEmpty()) return text
            val regex = ScriptureWordSearch.wholeWordRegex(trimmed) ?: return text
            val spanColor = ContextCompat.getColor(ctx, R.color.teal_200)
            val spannable = SpannableString(text)
            regex.findAll(text).forEach { m ->
                spannable.setSpan(
                    ForegroundColorSpan(spanColor),
                    m.range.first,
                    m.range.last + 1,
                    SpannableString.SPAN_EXCLUSIVE_EXCLUSIVE
                )
                spannable.setSpan(
                    StyleSpan(Typeface.BOLD),
                    m.range.first,
                    m.range.last + 1,
                    SpannableString.SPAN_EXCLUSIVE_EXCLUSIVE
                )
            }
            return spannable
        }
    }

    private object Diff : DiffUtil.ItemCallback<ScriptureWordSearchHit>() {
        override fun areItemsTheSame(a: ScriptureWordSearchHit, b: ScriptureWordSearchHit): Boolean =
            a.bookId == b.bookId && a.chapter == b.chapter && a.verse == b.verse

        override fun areContentsTheSame(a: ScriptureWordSearchHit, b: ScriptureWordSearchHit): Boolean =
            a == b
    }
}
