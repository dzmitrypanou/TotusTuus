package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.res.ColorStateList
import android.os.Bundle
import android.view.GestureDetector
import android.view.Gravity
import android.view.LayoutInflater
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.core.os.bundleOf
import androidx.core.view.GestureDetectorCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.NavController
import androidx.navigation.NavOptions
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.sync.ScriptureRemoteSync
import kotlinx.coroutines.launch
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureChapterTextBinding
import by.dzmitrypanou.catholicapp.ui.navigation.navigateSafely
import by.dzmitrypanou.catholicapp.ui.themeColor
import com.google.android.material.card.MaterialCardView
import com.google.android.material.textview.MaterialTextView

class ScriptureChapterTextFragment : Fragment(), ScriptureToolbarActions {
    private var _binding: FragmentScriptureChapterTextBinding? = null
    private val binding get() = _binding!!
    private var totalChapters: Int = 0

    companion object {
        const val ARG_FOCUS_VERSE = "focusVerse"

        fun navigateBackToChapterList(navController: NavController): Boolean {
            val entry = navController.currentBackStackEntry ?: return false
            if (entry.destination.id != R.id.nav_scripture_chapter_text) return false
            val args = entry.arguments ?: return false
            val bookId = args.getInt(ScriptureChaptersFragment.ARG_BOOK_ID, -1)
            val bookTitle = args.getString(ScriptureChaptersFragment.ARG_BOOK_TITLE).orEmpty()
            val chapter = args.getInt(ScriptureChaptersFragment.ARG_CHAPTER, 1)
            if (bookId < 0 || bookTitle.isBlank()) return false
            return try {
                navController.getBackStackEntry(R.id.nav_scripture_chapters).savedStateHandle.set(
                    ScriptureChaptersFragment.KEY_CHAPTER_HIGHLIGHT,
                    chapter
                )
                navController.popBackStack(R.id.nav_scripture_chapters, false)
            } catch (_: IllegalArgumentException) {
                val opts = NavOptions.Builder()
                    .setPopUpTo(R.id.nav_scripture_chapter_text, true)
                    .build()
                navController.navigate(
                    R.id.nav_scripture_chapters,
                    bundleOf(
                        ScriptureChaptersFragment.ARG_BOOK_ID to bookId,
                        ScriptureChaptersFragment.ARG_BOOK_TITLE to bookTitle,
                        ScriptureChaptersFragment.ARG_HIGHLIGHT_CHAPTER to chapter
                    ),
                    opts
                )
                true
            }
        }
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureChapterTextBinding.inflate(inflater, container, false)
        setupSwipeNavigation()
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        requireActivity().onBackPressedDispatcher.addCallback(
            viewLifecycleOwner,
            object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() {
                    val nav = findNavController()
                    if (navigateBackToChapterList(nav)) return
                    isEnabled = false
                    requireActivity().onBackPressedDispatcher.onBackPressed()
                    isEnabled = true
                }
            }
        )
        parentFragmentManager.setFragmentResultListener(
            ScriptureChapterPickerDialogFragment.REQUEST_KEY,
            viewLifecycleOwner
        ) { _, bundle ->
            val ch = bundle.getInt(ScriptureChapterPickerDialogFragment.RESULT_CHAPTER)
            val bookId = arguments?.getInt(ScriptureChaptersFragment.ARG_BOOK_ID, -1) ?: -1
            val bookTitle = arguments?.getString(ScriptureChaptersFragment.ARG_BOOK_TITLE).orEmpty()
            if (bookId >= 0 && bookTitle.isNotBlank()) {
                openChapter(bookId, bookTitle, ch)
            }
        }
    }

    override fun onResume() {
        super.onResume()
        lifecycleScope.launch {
            val translationId = ScriptureTranslationStore.getSelectedTranslationId(requireContext())
            renderChapter()
            val sync = ScriptureRemoteSync.refreshTranslation(requireContext(), translationId, forceRefresh = false)
            if (!isAdded) return@launch
            if (sync == ScriptureRemoteSync.Result.DiskCacheUpdated) renderChapter()
        }
    }

    override fun onScriptureTextScaleChanged() {
        renderChapter()
    }

    private fun renderChapter() {
        val bookId = arguments?.getInt(ScriptureChaptersFragment.ARG_BOOK_ID, -1) ?: -1
        val bookTitle = arguments?.getString(ScriptureChaptersFragment.ARG_BOOK_TITLE).orEmpty()
        val chapter = arguments?.getInt(ScriptureChaptersFragment.ARG_CHAPTER, 1) ?: 1
        val focusVerse = arguments?.getInt(ARG_FOCUS_VERSE, -1) ?: -1
        val translationId = ScriptureTranslationStore.getSelectedTranslationId(requireContext())
        totalChapters = ScriptureTextRepository.getChapterCountById(requireContext(), translationId, bookId)
        val translationTitle = ScriptureCatalog.allTranslations()
            .firstOrNull { it.id == translationId }
            ?.title
            .orEmpty()
        val verses = ScriptureTextRepository.getChapterVersesById(requireContext(), translationId, bookId, chapter)
        binding.layoutScriptureVerses.removeAllViews()
        bindChapterNavigation(bookId = bookId, bookTitle = bookTitle, chapter = chapter)
        if (verses.isEmpty()) {
            binding.textScriptureChapterBodyFallback.visibility = View.VISIBLE
            binding.textScriptureChapterBodyFallback.text = getString(R.string.scripture_text_not_available)
            ScriptureUiTypography.applyReadingSp(binding.textScriptureChapterBodyFallback, 18f)
            return
        }
        binding.textScriptureChapterBodyFallback.visibility = View.GONE
        var focusRowView: View? = null
        verses.forEach { verse ->
            val isFocusTarget = focusVerse > 0 && verse.number == focusVerse
            val rowCard = MaterialCardView(requireContext()).apply {
                layoutParams = LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT
                ).also { it.topMargin = resources.getDimensionPixelSize(R.dimen.prayer_book_block_gap) }
                radius = resources.getDimension(R.dimen.prayer_card_corner)
                if (isFocusTarget) {
                    setCardBackgroundColor(requireContext().themeColor(R.attr.totusColorScriptureHighlightFill))
                    strokeWidth = resources.getDimensionPixelSize(R.dimen.scripture_focus_verse_stroke_width)
                    strokeColor = requireContext().themeColor(R.attr.totusColorScriptureHighlightStroke)
                } else {
                    setCardBackgroundColor(requireContext().themeColor(R.attr.totusColorSurfaceElevated))
                    strokeWidth = 1
                    strokeColor = requireContext().themeColor(R.attr.totusColorSurfaceStroke)
                }
                cardElevation = 0f
            }
            if (isFocusTarget) focusRowView = rowCard
            val row = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.HORIZONTAL
                setPadding(16, 14, 12, 14)
            }
            val text = MaterialTextView(requireContext()).apply {
                this.text = "${verse.number} ${verse.text}"
                setTextColor(requireContext().themeColor(R.attr.totusColorTextPrimary))
                ScriptureUiTypography.applyReadingSp(this, 18f)
            }
            row.addView(text, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f))

            val fav = FavoriteVerse(
                translationId = translationId,
                translationTitle = translationTitle,
                bookId = bookId,
                bookTitle = bookTitle,
                chapter = chapter,
                verse = verse.number,
                text = verse.text
            )
            val bookmark = ImageView(requireContext()).apply {
                setPadding(8, 8, 8, 8)
                contentDescription = getString(R.string.scripture_favorite_toggle)
                setBookmarkIcon(this, ScriptureVerseFavoritesStore.isFavorite(requireContext(), fav))
                setOnClickListener {
                    val added = ScriptureVerseFavoritesStore.toggle(requireContext(), fav)
                    setBookmarkIcon(this, added)
                    Toast.makeText(
                        requireContext(),
                        getString(if (added) R.string.scripture_favorite_added else R.string.scripture_favorite_removed),
                        Toast.LENGTH_SHORT
                    ).show()
                }
            }

            val compareRef = ComparisonVerseRef(
                bookId = bookId,
                bookTitle = bookTitle,
                chapter = chapter,
                verse = verse.number
            )
            val compare = ImageView(requireContext()).apply {
                setPadding(8, 8, 8, 8)
                contentDescription = getString(R.string.scripture_compare_toggle)
                setCompareIcon(this, ScriptureComparisonStore.isInComparison(requireContext(), compareRef))
                setOnClickListener {
                    val added = ScriptureComparisonStore.toggleVerse(requireContext(), compareRef)
                    setCompareIcon(this, added)
                    Toast.makeText(
                        requireContext(),
                        getString(if (added) R.string.scripture_compare_added else R.string.scripture_compare_removed),
                        Toast.LENGTH_SHORT
                    ).show()
                }
            }

            val verseActions = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.VERTICAL
                gravity = Gravity.CENTER_HORIZONTAL
            }
            val iconLp = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            )
            verseActions.addView(bookmark, LinearLayout.LayoutParams(iconLp))
            verseActions.addView(compare, LinearLayout.LayoutParams(iconLp))

            row.addView(
                verseActions,
                LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.WRAP_CONTENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT
                ).apply { gravity = Gravity.CENTER_VERTICAL }
            )

            rowCard.addView(row)
            binding.layoutScriptureVerses.addView(rowCard)
        }
        val rowToScroll = focusRowView
        if (rowToScroll != null) {
            val scrollView = binding.root as ScrollView
            scrollView.post {
                if (!isAdded) return@post
                val container = binding.layoutScriptureVerses
                val y = container.top + rowToScroll.top
                scrollView.smoothScrollTo(0, y.coerceAtLeast(0))
            }
        }
    }

    private fun bindChapterNavigation(bookId: Int, bookTitle: String, chapter: Int) {
        val hasPrev = chapter > 1
        val hasNext = chapter < totalChapters
        binding.buttonScripturePrevChapter.isEnabled = hasPrev
        binding.buttonScriptureNextChapter.isEnabled = hasNext
        val total = totalChapters.coerceAtLeast(chapter)
        binding.textScriptureChapterPickerSummary.text =
            getString(R.string.scripture_chapter_dropdown_item, chapter, total)
        ScriptureUiTypography.applyUiSp(binding.textScriptureChapterPickerSummary, 16f)
        binding.cardScriptureChapterPickerTrigger.setOnClickListener {
            ScriptureChapterPickerDialogFragment.newInstance(
                bookTitle = bookTitle,
                current = chapter,
                total = total
            ).show(parentFragmentManager, ScriptureChapterPickerDialogFragment.TAG)
        }
        binding.buttonScripturePrevChapter.setOnClickListener {
            if (hasPrev) openChapter(bookId, bookTitle, chapter - 1)
        }
        binding.buttonScriptureNextChapter.setOnClickListener {
            if (hasNext) openChapter(bookId, bookTitle, chapter + 1)
        }
    }

    private fun setupSwipeNavigation() {
        val detector = GestureDetectorCompat(requireContext(), object : GestureDetector.SimpleOnGestureListener() {
            override fun onFling(
                e1: MotionEvent?,
                e2: MotionEvent,
                velocityX: Float,
                velocityY: Float
            ): Boolean {
                if (e1 == null) return false
                val diffX = e2.x - e1.x
                val diffY = e2.y - e1.y
                if (kotlin.math.abs(diffX) < kotlin.math.abs(diffY)) return false
                if (kotlin.math.abs(diffX) < 120 || kotlin.math.abs(velocityX) < 300) return false

                val bookId = arguments?.getInt(ScriptureChaptersFragment.ARG_BOOK_ID, -1) ?: -1
                val bookTitle = arguments?.getString(ScriptureChaptersFragment.ARG_BOOK_TITLE).orEmpty()
                val chapter = arguments?.getInt(ScriptureChaptersFragment.ARG_CHAPTER, 1) ?: 1
                if (bookId < 0 || bookTitle.isBlank()) return false

                return if (diffX < 0 && chapter < totalChapters) {
                    openChapter(bookId, bookTitle, chapter + 1)
                    true
                } else if (diffX > 0 && chapter > 1) {
                    openChapter(bookId, bookTitle, chapter - 1)
                    true
                } else {
                    false
                }
            }
        })
        binding.root.setOnTouchListener { _, event -> detector.onTouchEvent(event) }
    }

    private fun openChapter(bookId: Int, bookTitle: String, chapter: Int) {
        findNavController().navigateSafely(
            R.id.nav_scripture_chapter_text,
            bundleOf(
                ScriptureChaptersFragment.ARG_BOOK_ID to bookId,
                ScriptureChaptersFragment.ARG_BOOK_TITLE to bookTitle,
                ScriptureChaptersFragment.ARG_CHAPTER to chapter
            )
        )
    }

    private fun setBookmarkIcon(view: ImageView, selected: Boolean) {
        view.setImageResource(if (selected) R.drawable.ic_bookmark_filled_24 else R.drawable.ic_bookmark_border_24)
        view.imageTintList = ColorStateList.valueOf(requireContext().themeColor(R.attr.totusColorTextPrimary))
    }

    private fun setCompareIcon(view: ImageView, selected: Boolean) {
        view.setImageResource(if (selected) R.drawable.ic_compare_24 else R.drawable.ic_compare_outline_24)
        view.imageTintList = ColorStateList.valueOf(requireContext().themeColor(R.attr.totusColorTextPrimary))
    }

    override fun onPause() {
        super.onPause()
        val bookId = arguments?.getInt(ScriptureChaptersFragment.ARG_BOOK_ID, -1) ?: -1
        val bookTitle = arguments?.getString(ScriptureChaptersFragment.ARG_BOOK_TITLE).orEmpty()
        val chapter = arguments?.getInt(ScriptureChaptersFragment.ARG_CHAPTER, 1) ?: 1
        if (bookId >= 0 && bookTitle.isNotBlank()) {
            val tr = ScriptureTranslationStore.getSelectedTranslationId(requireContext())
            ScriptureReadingProgressStore.save(
                requireContext(),
                translationId = tr,
                bookId = bookId,
                bookTitle = bookTitle,
                chapter = chapter
            )
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
