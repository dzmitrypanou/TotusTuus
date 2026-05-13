package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.res.ColorStateList
import android.os.Bundle
import android.view.Gravity
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import androidx.core.content.ContextCompat
import androidx.core.os.bundleOf
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.sync.ScriptureRemoteSync
import kotlinx.coroutines.launch
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureChaptersBinding
import by.dzmitrypanou.catholicapp.ui.themeColor
import com.google.android.material.card.MaterialCardView
import com.google.android.material.textview.MaterialTextView

class ScriptureChaptersFragment : Fragment(), ScriptureToolbarActions {
    private var _binding: FragmentScriptureChaptersBinding? = null
    private val binding get() = _binding!!
    private var pendingArgHighlightChapter: Int = -1

private data class ChaptersRenderFingerprint(
        val bookId: Int,
        val translationId: String,
        val chapterCount: Int,
        val highlightChapter: Int,
    )

    private var lastChaptersFingerprint: ChaptersRenderFingerprint? = null

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureChaptersBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        pendingArgHighlightChapter = arguments?.getInt(ARG_HIGHLIGHT_CHAPTER, -1) ?: -1
        val bookId = arguments?.getInt(ARG_BOOK_ID, -1) ?: -1
        if (bookId > 0) {
            lifecycleScope.launch {
                val app = requireContext().applicationContext
                val tr = ScriptureTranslationStore.getSelectedTranslationId(app)
                ScriptureTextRepository.warmChapterCountForBook(app, tr, bookId)
            }
        }
    }

    override fun onResume() {
        super.onResume()
        lifecycleScope.launch {
            val translationId = ScriptureTranslationStore.getSelectedTranslationId(requireContext())
            renderChapters(force = false)
            val sync = ScriptureRemoteSync.refreshTranslation(requireContext(), translationId, forceRefresh = false)
            if (!isAdded) return@launch
            if (sync == ScriptureRemoteSync.Result.DiskCacheUpdated) renderChapters(force = true)
        }
    }

    override fun onScriptureTextScaleChanged() {
        renderChapters(force = true)
    }

private fun peekHighlightChapter(): Int {
        pendingArgHighlightChapter.takeIf { it > 0 }?.let { return it }
        return findNavController().currentBackStackEntry?.savedStateHandle
            ?.get<Int>(KEY_CHAPTER_HIGHLIGHT) ?: -1
    }

    private fun consumePeekedHighlight(peeked: Int) {
        if (peeked <= 0) return
        if (pendingArgHighlightChapter == peeked) pendingArgHighlightChapter = -1
        val sh = findNavController().currentBackStackEntry?.savedStateHandle
        if (sh != null && sh.get<Int>(KEY_CHAPTER_HIGHLIGHT) == peeked) {
            sh.remove<Int>(KEY_CHAPTER_HIGHLIGHT)
        }
    }

    private fun renderChapters(force: Boolean) {
        val bookId = arguments?.getInt(ARG_BOOK_ID, -1) ?: -1
        val bookTitle = arguments?.getString(ARG_BOOK_TITLE).orEmpty()
        if (bookTitle.isBlank() || bookId < 0) return

        val translationId = ScriptureTranslationStore.getSelectedTranslationId(requireContext())
        val chapterCount = ScriptureTextRepository.getChapterCountById(requireContext(), translationId, bookId)
        val highlightPeek = peekHighlightChapter()

        val fingerprint = ChaptersRenderFingerprint(
            bookId = bookId,
            translationId = translationId,
            chapterCount = chapterCount,
            highlightChapter = highlightPeek,
        )
        if (!force &&
            fingerprint == lastChaptersFingerprint &&
            binding.layoutScriptureChapters.childCount > 0
        ) {
            consumePeekedHighlight(highlightPeek)
            return
        }
        consumePeekedHighlight(highlightPeek)
        val highlightChapter = highlightPeek
        lastChaptersFingerprint = fingerprint

        binding.layoutScriptureChapters.removeAllViews()
        val gap = resources.getDimensionPixelSize(R.dimen.prayer_book_block_gap)
        val gapHalf = gap / 2

        var scrollToCard: MaterialCardView? = null
        var chapter = 1
        while (chapter <= chapterCount) {
            val rowLayout = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
                layoutParams = LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT
                ).also {
                    if (binding.layoutScriptureChapters.childCount > 0) it.topMargin = gap
                }
            }

            val card1 = buildChapterCard(
                bookId, bookTitle, chapter,
                highlighted = chapter == highlightChapter
            )
            if (chapter == highlightChapter) scrollToCard = card1
            rowLayout.addView(
                card1,
                LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f).apply {
                    marginEnd = gapHalf
                }
            )

            if (chapter + 1 <= chapterCount) {
                val card2 = buildChapterCard(
                    bookId, bookTitle, chapter + 1,
                    highlighted = chapter + 1 == highlightChapter
                )
                if (chapter + 1 == highlightChapter) scrollToCard = card2
                rowLayout.addView(
                    card2,
                    LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f).apply {
                        marginStart = gapHalf
                    }
                )
            } else {
                rowLayout.addView(
                    View(requireContext()).apply { visibility = View.INVISIBLE },
                    LinearLayout.LayoutParams(0, 0, 1f)
                )
            }

            binding.layoutScriptureChapters.addView(rowLayout)
            chapter += 2
        }

        val cardToScroll = scrollToCard
        if (cardToScroll != null) {
            binding.root.post {
                if (!isAdded) return@post
                val scroll = binding.root
                var y = cardToScroll.top
                var p = cardToScroll.parent as? View
                val container = binding.layoutScriptureChapters
                while (p != null && p !== container) {
                    y += p.top
                    p = p.parent as? View
                }

scroll.smoothScrollTo(0, y.coerceAtLeast(0))
            }
        }
    }

    private fun buildChapterCard(
        bookId: Int,
        bookTitle: String,
        chapter: Int,
        highlighted: Boolean
    ): MaterialCardView {
        val ctx = requireContext()
        val row = MaterialCardView(ctx).apply {
            stateListAnimator = null
            radius = resources.getDimension(R.dimen.prayer_card_corner)
            if (highlighted) {
                setCardBackgroundColor(ctx.themeColor(R.attr.totusColorScriptureHighlightFill))
                strokeWidth = resources.getDimensionPixelSize(R.dimen.scripture_focus_verse_stroke_width)
                strokeColor = ctx.themeColor(R.attr.totusColorScriptureHighlightStroke)
            } else {
                setCardBackgroundColor(ctx.themeColor(R.attr.totusColorSurfaceElevated))
                strokeWidth = 1
                strokeColor = ctx.themeColor(R.attr.totusColorSurfaceStroke)
            }
            cardElevation = 0f
            rippleColor = ColorStateList.valueOf(ctx.getColor(R.color.ripple_card))
            isClickable = true
            isFocusable = true
            setOnClickListener {
                findNavController().navigate(
                    R.id.action_nav_scripture_chapters_to_nav_scripture_chapter_text,
                    bundleOf(
                        ARG_BOOK_ID to bookId,
                        ARG_BOOK_TITLE to bookTitle,
                        ARG_CHAPTER to chapter
                    )
                )
            }
        }
        val title = MaterialTextView(ctx).apply {
            text = getString(R.string.scripture_chapter_title_format, chapter)
            setTextColor(ctx.themeColor(R.attr.totusColorTextPrimary))
            ScriptureUiTypography.applyUiSp(this, 17f)
            setPadding(
                resources.getDimensionPixelSize(R.dimen.prayer_list_item_gap),
                resources.getDimensionPixelSize(R.dimen.prayer_book_block_gap),
                resources.getDimensionPixelSize(R.dimen.prayer_list_item_gap),
                resources.getDimensionPixelSize(R.dimen.prayer_book_block_gap)
            )
            gravity = Gravity.START or Gravity.CENTER_VERTICAL
            textAlignment = View.TEXT_ALIGNMENT_VIEW_START
            isClickable = false
            isFocusable = false
            highlightColor = ContextCompat.getColor(ctx, R.color.ripple_card)
        }
        row.addView(
            title,
            ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            )
        )
        return row
    }

    override fun onDestroyView() {
        super.onDestroyView()
        lastChaptersFingerprint = null
        _binding = null
    }

    companion object {
        const val ARG_BOOK_ID = "bookId"
        const val ARG_BOOK_TITLE = "bookTitle"
        const val ARG_CHAPTER = "chapter"
        const val ARG_HIGHLIGHT_CHAPTER = "highlightChapter"
        const val KEY_CHAPTER_HIGHLIGHT = "chapter_highlight_return"
    }
}
