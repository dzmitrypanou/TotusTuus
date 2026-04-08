package by.dzmitrypanou.catholicapp.ui.scripture

import android.graphics.Typeface
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import androidx.core.os.bundleOf
import androidx.core.view.isVisible
import androidx.fragment.app.Fragment
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureBinding
import by.dzmitrypanou.catholicapp.ui.themeColor
import by.dzmitrypanou.catholicapp.databinding.ItemScriptureBookRowBinding
import by.dzmitrypanou.catholicapp.databinding.ScriptureTestamentSectionBinding

class ScriptureFragment : Fragment(), ScriptureToolbarActions {

    private var _binding: FragmentScriptureBinding? = null
    private val binding get() = _binding!!

    /** Апошні пераклад, для якога ўжо пабудаваны layoutScriptureContent (без перабудовы пры вяртанні ў раздзел). */
    private var lastRenderedTranslationId: String? = null

    /** Адзіны дамаход перабудовы спіса заветаў — інакш дзве карутыны могуць абнішчыць layout і намаляваць зноў («мігценне»). */
    private val scriptureContentMutex = Mutex()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        lifecycleScope.launch {
            ScriptureTextRepository.warmTestamentUiCatalog(requireContext().applicationContext)
        }
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureBinding.inflate(inflater, container, false)
        binding.layoutScriptureSearchEntry.setOnClickListener {
            findNavController().navigate(R.id.nav_scripture_word_search)
        }
        applySearchEntryTypography()
        bindContinueReadingRow()
        bindReadingPlansEntry()
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        // Адна паслядоўнасць пры кожным RESUMED — без паралельных карутын (тыпаграфіка + спіс кніг).
        viewLifecycleOwner.lifecycleScope.launch {
            viewLifecycleOwner.repeatOnLifecycle(Lifecycle.State.RESUMED) {
                val ctx = requireContext()
                val selectedId = ScriptureTranslationStore.getSelectedTranslationId(ctx)
                applySearchEntryTypography()
                bindReadingPlansEntry()
                bindContinueReadingRow()
                refreshScriptureContent(forceRebuild = false)
            }
        }
    }

    override fun onScriptureTextScaleChanged() {
        lifecycleScope.launch {
            refreshScriptureContent(forceRebuild = true)
            applySearchEntryTypography()
            bindContinueReadingRow()
            bindReadingPlansEntry()
        }
    }

    private fun applySearchEntryTypography() {
        val ctx = context ?: return
        ScriptureUiTypography.applyUiSp(binding.textScriptureSearchEntryTitle, 17f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textScriptureContinueTitle, 17f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textScriptureContinueSubtitle, 14f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textScriptureReadingPlansEntryTitle, 17f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textScriptureReadingPlansEntrySubtitle, 13f, ctx)
    }

    private fun bindReadingPlansEntry() {
        if (_binding == null) return
        val ctx = requireContext()
        val nav = findNavController()
        if (ScriptureReadingPlanActivationStore.isPlanStarted(ctx)) {
            ScriptureReadingPlanActivationStore.ensurePlanAnchorForActive(ctx)
            val kind = ScriptureReadingPlanActivationStore.getPlanKind(ctx)
            val planName = planKindDisplayTitle(kind)
            val anchor = ScriptureReadingPlanActivationStore.getPlanAnchorDayMillis(ctx)
            val planDayIndex = anchor?.let {
                ScriptureYearReadingPlan.planDayIndexFromAnchorDayMillis(it)
            } ?: 0
            val planDayDisplay = planDayIndex + 1
            val planLength = ScriptureYearReadingPlan.PLAN_LENGTH
            val titleWithDays = getString(
                R.string.scripture_reading_plans_chosen_title_with_days,
                planDayDisplay,
                planLength
            )
            binding.textScriptureReadingPlansEntryTitle.text = titleWithDays
            binding.textScriptureReadingPlansEntrySubtitle.text = planName
            binding.layoutScriptureReadingPlansEntry.contentDescription = "$titleWithDays. $planName"
            binding.layoutScriptureReadingPlansEntry.setOnClickListener {
                nav.navigate(
                    R.id.nav_scripture_reading_plan,
                    bundleOf(ScriptureReadingPlanKind.NAV_ARG_PLAN_KIND to kind.storageKey)
                )
            }
        } else {
            binding.textScriptureReadingPlansEntryTitle.text =
                getString(R.string.scripture_reading_plans_section_title)
            binding.textScriptureReadingPlansEntrySubtitle.text =
                getString(R.string.scripture_reading_plans_entry_subtitle)
            binding.layoutScriptureReadingPlansEntry.contentDescription =
                getString(R.string.scripture_reading_plans_section_title)
            binding.layoutScriptureReadingPlansEntry.setOnClickListener {
                nav.navigate(R.id.action_nav_scripture_to_nav_scripture_reading_plans_hub)
            }
        }
    }

    private fun planKindDisplayTitle(kind: ScriptureReadingPlanKind): String =
        when (kind) {
            ScriptureReadingPlanKind.LINEAR -> getString(R.string.scripture_reading_plan_title)
            ScriptureReadingPlanKind.CHRONOLOGICAL ->
                getString(R.string.scripture_reading_plan_chronological_title)
            ScriptureReadingPlanKind.MIXED -> getString(R.string.scripture_reading_plan_mixed_title)
        }

    private fun bindContinueReadingRow() {
        val ctx = context ?: return
        val tr = ScriptureTranslationStore.getSelectedTranslationId(ctx)
        val p = ScriptureReadingProgressStore.read(ctx)
        if (p != null && p.translationId == tr) {
            binding.layoutScriptureContinueReading.isVisible = true
            binding.textScriptureContinueSubtitle.text =
                getString(R.string.scripture_continue_reading_line, p.bookTitle, p.chapter)
            binding.layoutScriptureContinueReading.setOnClickListener {
                findNavController().navigate(
                    R.id.nav_scripture_chapter_text,
                    bundleOf(
                        ScriptureChaptersFragment.ARG_BOOK_ID to p.bookId,
                        ScriptureChaptersFragment.ARG_BOOK_TITLE to p.bookTitle,
                        ScriptureChaptersFragment.ARG_CHAPTER to p.chapter
                    )
                )
            }
        } else {
            binding.layoutScriptureContinueReading.isVisible = false
        }
    }

    private suspend fun refreshScriptureContent(forceRebuild: Boolean) {
        scriptureContentMutex.withLock {
            val ctx = requireContext()
            val selectedId = ScriptureTranslationStore.getSelectedTranslationId(ctx)
            if (_binding == null || !isAdded) return@withLock
            if (!forceRebuild &&
                selectedId == lastRenderedTranslationId &&
                binding.layoutScriptureContent.childCount > 0
            ) {
                bindContinueReadingRow()
                return@withLock
            }
            val testaments = withContext(Dispatchers.Default) {
                ScriptureTextRepository.getTestaments(ctx, selectedId)
            }
            if (_binding == null || !isAdded) return@withLock
            clearGeneratedTestaments()
            testaments.forEach { testament ->
                addTestament(testament)
            }
            lastRenderedTranslationId = selectedId
            bindContinueReadingRow()
        }
    }

    private fun clearGeneratedTestaments() {
        binding.layoutScriptureContent.removeAllViews()
    }

    private fun addTestament(testament: TestamentSection) {
        val context = requireContext()
        val inflater = layoutInflater

        val sectionBinding = ScriptureTestamentSectionBinding.inflate(inflater, binding.layoutScriptureContent, false)

        val lp = LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        ).apply {
            topMargin =
                if (binding.layoutScriptureContent.childCount == 0) {
                    0
                } else {
                    resources.getDimensionPixelSize(R.dimen.prayer_book_block_gap)
                }
        }
        binding.layoutScriptureContent.addView(sectionBinding.root, lp)

        sectionBinding.textTestamentTitle.text = testament.title
        ScriptureUiTypography.applyUiSp(sectionBinding.textTestamentTitle, 20f, context)
        sectionBinding.textTestamentTitle.setTypeface(sectionBinding.textTestamentTitle.typeface, Typeface.BOLD)

        testament.books.forEachIndexed { index, book ->
            val rowBinding = ItemScriptureBookRowBinding.inflate(inflater, sectionBinding.layoutBooks, false)

            rowBinding.textBookTitle.text = book.title
            ScriptureUiTypography.applyUiSp(rowBinding.textBookTitle, 17f, context)

            rowBinding.textBookChapters.text =
                getString(R.string.scripture_book_chapters_meta, book.chapters)
            ScriptureUiTypography.applyUiSp(rowBinding.textBookChapters, 13f, context)
            rowBinding.textBookChapters.setTextColor(
                context.themeColor(R.attr.totusColorTextTertiary)
            )

            rowBinding.root.contentDescription =
                getString(R.string.scripture_open_book_a11y, book.title)
            rowBinding.root.setOnClickListener {
                val appCtx = requireContext().applicationContext
                val trId = ScriptureTranslationStore.getSelectedTranslationId(appCtx)
                lifecycleScope.launch {
                    ScriptureTextRepository.warmChapterCountForBook(appCtx, trId, book.id)
                }
                findNavController().navigate(
                    R.id.action_nav_scripture_to_nav_scripture_chapters,
                    bundleOf(
                        ScriptureChaptersFragment.ARG_BOOK_ID to book.id,
                        ScriptureChaptersFragment.ARG_BOOK_TITLE to book.title
                    )
                )
            }

            val rowLp = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            ).apply {
                topMargin =
                    if (index == 0) {
                        0
                    } else {
                        resources.getDimensionPixelSize(R.dimen.scripture_book_row_spacing)
                    }
            }
            sectionBinding.layoutBooks.addView(rowBinding.root, rowLp)
        }

        val testamentKey = ScriptureTestamentExpandStore.preferenceKeyForTitle(context, testament.title)
        setupTestamentCollapse(sectionBinding, testament.title, testamentKey)
    }

    private fun setupTestamentCollapse(
        sectionBinding: ScriptureTestamentSectionBinding,
        testamentTitle: String,
        testamentKey: String
    ) {
        val ctx = requireContext().applicationContext
        var expanded = ScriptureTestamentExpandStore.isExpanded(ctx, testamentKey, true)
        fun applyExpandedState() {
            sectionBinding.layoutBooks.visibility = if (expanded) View.VISIBLE else View.GONE
            sectionBinding.imageTestamentExpand.setImageResource(
                if (expanded) {
                    R.drawable.ic_expand_less_24
                } else {
                    R.drawable.ic_expand_more_24
                }
            )
            sectionBinding.layoutTestamentHeader.contentDescription =
                if (expanded) {
                    getString(R.string.scripture_testament_collapse_list_a11y, testamentTitle)
                } else {
                    getString(R.string.scripture_testament_expand_list_a11y, testamentTitle)
                }
        }
        applyExpandedState()
        sectionBinding.layoutTestamentHeader.apply {
            isFocusable = true
            setOnClickListener {
                expanded = !expanded
                ScriptureTestamentExpandStore.setExpanded(ctx, testamentKey, expanded)
                applyExpandedState()
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        lastRenderedTranslationId = null
        _binding = null
    }

    companion object
}
