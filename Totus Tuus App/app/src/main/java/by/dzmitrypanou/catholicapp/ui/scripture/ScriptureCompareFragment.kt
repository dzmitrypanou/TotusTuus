package by.dzmitrypanou.catholicapp.ui.scripture

import android.graphics.Typeface
import android.os.Bundle
import android.text.SpannableString
import android.text.Spanned
import android.text.style.StyleSpan
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.CheckBox
import android.widget.LinearLayout
import androidx.core.os.bundleOf
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.sync.ScriptureRemoteSync
import by.dzmitrypanou.catholicapp.ui.themeColor
import kotlinx.coroutines.launch
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureCompareBinding
import com.google.android.material.card.MaterialCardView
import com.google.android.material.textview.MaterialTextView

class ScriptureCompareFragment : Fragment(), ScriptureToolbarActions {
    private var _binding: FragmentScriptureCompareBinding? = null
    private val binding get() = _binding!!
    private var translationsExpanded: Boolean = false

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureCompareBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onResume() {
        super.onResume()
        lifecycleScope.launch {
            val ids = ScriptureComparisonStore.selectedTranslationIds(requireContext())
            translationsExpanded = ScriptureComparisonStore.isTranslationsExpanded(requireContext())
            render()
            var diskUpdated = false
            for (id in ids) {
                if (ScriptureRemoteSync.refreshTranslation(requireContext(), id, forceRefresh = false) ==
                    ScriptureRemoteSync.Result.DiskCacheUpdated
                ) {
                    diskUpdated = true
                }
            }
            if (!isAdded) return@launch
            if (diskUpdated) render()
        }
    }

    override fun onScriptureTextScaleChanged() {
        render()
    }

    private fun render() {
        val hintDismissed = ScriptureComparisonStore.isHintDismissed(requireContext())
        binding.layoutScriptureCompareHint.visibility = if (hintDismissed) View.GONE else View.VISIBLE
        ScriptureUiTypography.applyUiSp(binding.textScriptureCompareHint, 15f)
        ScriptureUiTypography.applyUiSp(binding.textCompareTranslationsSectionTitle, 17f)
        binding.buttonScriptureCompareHideHint.setOnClickListener {
            ScriptureComparisonStore.dismissHint(requireContext())
            binding.layoutScriptureCompareHint.visibility = View.GONE
        }

        binding.layoutCompareTranslationsHeader.setOnClickListener {
            translationsExpanded = !translationsExpanded
            ScriptureComparisonStore.setTranslationsExpanded(requireContext(), translationsExpanded)
            applyTranslationsExpandedUi()
        }

        val selectedTranslationIds = ScriptureComparisonStore.selectedTranslationIds(requireContext()).toMutableSet()
        binding.layoutCompareTranslations.removeAllViews()
        ScriptureCatalog.allTranslations().forEach { tr ->
            val cb = CheckBox(requireContext()).apply {
                text = ScriptureCatalog.shortTitle(tr.id)
                setTextColor(requireContext().themeColor(R.attr.totusColorTextPrimary))
                ScriptureUiTypography.applyUiSp(this, 14f)
                isChecked = selectedTranslationIds.contains(tr.id)
                setOnCheckedChangeListener { _, checked ->
                    if (checked) selectedTranslationIds.add(tr.id) else selectedTranslationIds.remove(tr.id)
                    ScriptureComparisonStore.setSelectedTranslationIds(requireContext(), selectedTranslationIds)
                    lifecycleScope.launch {
                        if (checked) {
                            ScriptureRemoteSync.refreshTranslation(requireContext(), tr.id, forceRefresh = false)
                        }
                        if (isAdded) renderVerseCards()
                    }
                }
            }
            binding.layoutCompareTranslations.addView(cb)
        }
        applyTranslationsExpandedUi()
        renderVerseCards()
    }

    private fun applyTranslationsExpandedUi() {
        binding.layoutCompareTranslations.visibility = if (translationsExpanded) View.VISIBLE else View.GONE
        binding.imageCompareTranslationsExpand.rotation = if (translationsExpanded) 90f else 0f
    }

    private fun renderVerseCards() {
        val verses = ScriptureComparisonStore.allVerses(requireContext())
        val selectedTranslationIds = ScriptureComparisonStore.selectedTranslationIds(requireContext())
        val selectedTranslations = ScriptureCatalog.allTranslations().filter { selectedTranslationIds.contains(it.id) }
        binding.layoutCompareVerses.removeAllViews()
        val empty = verses.isEmpty()
        binding.textScriptureCompareEmpty.visibility = if (empty) View.VISIBLE else View.GONE
        ScriptureUiTypography.applyUiSp(binding.textScriptureCompareEmpty, 15f)
        if (empty) return

        verses.forEach { ref ->
            val card = MaterialCardView(requireContext()).apply {
                layoutParams = LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT
                ).also { it.topMargin = resources.getDimensionPixelSize(R.dimen.prayer_book_block_gap) }
                radius = resources.getDimension(R.dimen.prayer_card_corner)
                setCardBackgroundColor(requireContext().themeColor(R.attr.totusColorSurfaceElevated))
                strokeWidth = 1
                strokeColor = requireContext().themeColor(R.attr.totusColorSurfaceStroke)
                cardElevation = 0f
                isClickable = true
                isFocusable = true
                setOnClickListener {
                    findNavController().navigate(
                        R.id.nav_scripture_chapter_text,
                        bundleOf(
                            ScriptureChaptersFragment.ARG_BOOK_ID to ref.bookId,
                            ScriptureChaptersFragment.ARG_BOOK_TITLE to ref.bookTitle,
                            ScriptureChaptersFragment.ARG_CHAPTER to ref.chapter
                        )
                    )
                }
                setOnLongClickListener {
                    ScriptureComparisonStore.toggleVerse(requireContext(), ref)
                    renderVerseCards()
                    true
                }
            }
            val content = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(16, 14, 16, 14)
            }
            val title = MaterialTextView(requireContext()).apply {
                text = getString(
                    R.string.scripture_compare_ref_format,
                    ref.bookTitle,
                    ref.chapter,
                    ref.verse
                )
                setTextColor(requireContext().themeColor(R.attr.totusColorTextPrimary))
                ScriptureUiTypography.applyUiSp(this, 17f)
            }
            content.addView(title)

            selectedTranslations.forEach { tr ->
                val verseRaw = ScriptureTextRepository.getVerseTextById(
                    requireContext(),
                    tr.id,
                    ref.bookId,
                    ref.chapter,
                    ref.verse
                )
                val verseText = verseRaw?.trim().orEmpty()
                val author = ScriptureCatalog.shortTitle(tr.id)
                val body = when {
                    verseText.isNotEmpty() -> verseText
                    tr.id == ScriptureCatalog.DEFAULT_TRANSLATION_ID && ref.bookId < 40 ->
                        getString(R.string.scripture_compare_bcat_only_nt)
                    else -> getString(R.string.scripture_compare_translation_verse_unavailable)
                }
                val line = MaterialTextView(requireContext()).apply {
                    val full = "$author: $body"
                    text = SpannableString(full).apply {
                        setSpan(
                            StyleSpan(Typeface.BOLD),
                            0,
                            author.length,
                            Spanned.SPAN_EXCLUSIVE_EXCLUSIVE
                        )
                    }
                    setTextColor(requireContext().themeColor(R.attr.totusColorTextSecondary))
                    ScriptureUiTypography.applyReadingSp(this, 15f)
                    setPadding(0, 8, 0, 0)
                }
                content.addView(line)
            }
            card.addView(content)
            binding.layoutCompareVerses.addView(card)
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
