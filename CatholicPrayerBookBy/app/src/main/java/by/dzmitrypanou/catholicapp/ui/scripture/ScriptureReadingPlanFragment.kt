package by.dzmitrypanou.catholicapp.ui.scripture

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.core.os.bundleOf
import androidx.core.view.isVisible
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.NavOptions
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureReadingPlanBinding
import by.dzmitrypanou.catholicapp.ui.themeColor
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.textview.MaterialTextView
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class ScriptureReadingPlanFragment : Fragment(), ScriptureToolbarActions {

    private var _binding: FragmentScriptureReadingPlanBinding? = null
    private val binding get() = _binding!!

    private var cachedTranslationId: String? = null
    private var cachedBuckets: List<List<ScriptureChapterRef>>? = null
    private var cachedPlanKind: ScriptureReadingPlanKind? = null
    private var selectedDayIndex: Int = -1

    private val requestNotificationPermission =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) {
            finishStartPlan()
        }

    private lateinit var backToScriptureWhenPlanActive: OnBackPressedCallback

    private fun kindFromArgs(): ScriptureReadingPlanKind =
        ScriptureReadingPlanKind.fromStorage(arguments?.getString(ScriptureReadingPlanKind.NAV_ARG_PLAN_KIND))

    private fun planKindForSession(ctx: android.content.Context): ScriptureReadingPlanKind =
        if (ScriptureReadingPlanActivationStore.isPlanStarted(ctx)) {
            ScriptureReadingPlanActivationStore.getPlanKind(ctx)
        } else {
            kindFromArgs()
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        selectedDayIndex = savedInstanceState?.getInt(STATE_SELECTED_DAY, -1) ?: -1
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt(STATE_SELECTED_DAY, selectedDayIndex)
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureReadingPlanBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.buttonPlanStart.setOnClickListener { onClickStartPlan() }
        binding.buttonPlanPauseResume.setOnClickListener { onClickPauseResume() }
        binding.buttonPlanDecline.setOnClickListener { onClickDecline() }
        binding.buttonPlanPrevDay.setOnClickListener {
            if (selectedDayIndex > 0) {
                selectedDayIndex--
                bindUi()
            }
        }
        binding.buttonPlanNextDay.setOnClickListener {
            if (selectedDayIndex < ScriptureYearReadingPlan.PLAN_LENGTH - 1) {
                selectedDayIndex++
                bindUi()
            }
        }
        binding.buttonPlanToggleDone.setOnClickListener { toggleDoneForSelectedDay() }
        backToScriptureWhenPlanActive = object : OnBackPressedCallback(false) {
            override fun handleOnBackPressed() {
                val popped = findNavController().popBackStack(R.id.nav_scripture, false)
                if (!popped) {
                    isEnabled = false
                    requireActivity().onBackPressedDispatcher.onBackPressed()
                    isEnabled = true
                }
            }
        }
        requireActivity().onBackPressedDispatcher.addCallback(
            viewLifecycleOwner,
            backToScriptureWhenPlanActive
        )
        bindIntroCopy()
        bindActivationUi()
    }

    override fun onResume() {
        super.onResume()
        if (::backToScriptureWhenPlanActive.isInitialized) {
            updateBackToScriptureInterceptEnabled()
        }
        lifecycleScope.launch {
            val ctx = requireContext()
            ScriptureReadingPlanActivationStore.ensurePlanAnchorForActive(ctx)
            val tr = ScriptureTranslationStore.getSelectedTranslationId(ctx)
            val kind = planKindForSession(ctx)
            val buckets = withContext(Dispatchers.Default) {
                ScriptureYearReadingPlan.buildDailyBuckets(ctx, tr, kind)
            }
            if (!isAdded) return@launch
            val needReload =
                cachedTranslationId != tr ||
                    cachedBuckets == null ||
                    cachedPlanKind != kind
            if (needReload) {
                cachedTranslationId = tr
                cachedBuckets = buckets
                cachedPlanKind = kind
            }
            bindIntroCopy()
            bindActivationUi()
            bindUi()
        }
    }

    override fun onScriptureTextScaleChanged() {
        applyReadingRowsTypography()
        ScriptureUiTypography.applyUiSp(binding.textPlanIntroTitle, 18f)
        ScriptureUiTypography.applyUiSp(binding.textPlanIntroHint, 13f)
        ScriptureUiTypography.applyUiSp(binding.textPlanIntroBody, 15f)
        ScriptureUiTypography.applyUiSp(binding.textPlanDayNumber, 44f)
        ScriptureUiTypography.applyUiSp(binding.textPlanDaySubtitle, 13f)
        ScriptureUiTypography.applyUiSp(binding.textPlanProgressYear, 14f)
        ScriptureUiTypography.applyUiSp(binding.textPlanReadingsHeading, 12f)
        ScriptureUiTypography.applyUiSp(binding.textPlanTodayBadge, 11f)
    }

    private fun bindIntroCopy() {
        if (_binding == null) return
        val ctx = requireContext()
        val k = kindFromArgs()
        binding.textPlanIntroTitle.text = introTitle(ctx, k)
        binding.textPlanIntroHint.text = introHint(ctx, k)
        binding.textPlanIntroBody.text = introBody(ctx, k)
    }

    private fun introTitle(ctx: android.content.Context, k: ScriptureReadingPlanKind): String =
        when (k) {
            ScriptureReadingPlanKind.LINEAR -> ctx.getString(R.string.scripture_reading_plan_title)
            ScriptureReadingPlanKind.CHRONOLOGICAL -> ctx.getString(R.string.scripture_reading_plan_chronological_title)
            ScriptureReadingPlanKind.MIXED -> ctx.getString(R.string.scripture_reading_plan_mixed_title)
        }

    private fun introHint(ctx: android.content.Context, k: ScriptureReadingPlanKind): String =
        when (k) {
            ScriptureReadingPlanKind.LINEAR -> ctx.getString(R.string.scripture_reading_plan_entry_hint)
            ScriptureReadingPlanKind.CHRONOLOGICAL -> ctx.getString(R.string.scripture_reading_plan_chronological_hint)
            ScriptureReadingPlanKind.MIXED -> ctx.getString(R.string.scripture_reading_plan_mixed_hint)
        }

    private fun introBody(ctx: android.content.Context, k: ScriptureReadingPlanKind): String =
        when (k) {
            ScriptureReadingPlanKind.LINEAR -> ctx.getString(R.string.scripture_reading_plan_linear_activation_hint)
            ScriptureReadingPlanKind.CHRONOLOGICAL -> ctx.getString(R.string.scripture_reading_plan_chrono_activation_hint)
            ScriptureReadingPlanKind.MIXED -> ctx.getString(R.string.scripture_reading_plan_mixed_activation_hint)
        }

    private fun onClickStartPlan() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            val ctx = requireContext()
            val granted = ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) ==
                PackageManager.PERMISSION_GRANTED
            if (granted) {
                finishStartPlan()
            } else {
                requestNotificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
        } else {
            finishStartPlan()
        }
    }

    private fun finishStartPlan() {
        val ctx = requireContext()
        ScriptureReadingPlanActivationStore.startPlan(ctx, kindFromArgs())
        ReadingPlanReminderScheduler.schedule(ctx)
        selectedDayIndex = 0
        cachedPlanKind = null
        bindIntroCopy()
        bindActivationUi()
        lifecycleScope.launch {
            val tr = ScriptureTranslationStore.getSelectedTranslationId(ctx)
            val kind = planKindForSession(ctx)
            val buckets = withContext(Dispatchers.Default) {
                ScriptureYearReadingPlan.buildDailyBuckets(ctx, tr, kind)
            }
            if (!isAdded) return@launch
            cachedTranslationId = tr
            cachedBuckets = buckets
            cachedPlanKind = kind
            bindUi()
        }
    }

    private fun onClickPauseResume() {
        val ctx = requireContext()
        val paused = ScriptureReadingPlanActivationStore.isRemindersPaused(ctx)
        if (paused) {
            ScriptureReadingPlanActivationStore.setRemindersPaused(ctx, false)
            ReadingPlanReminderScheduler.schedule(ctx)
        } else {
            ScriptureReadingPlanActivationStore.setRemindersPaused(ctx, true)
            ReadingPlanReminderScheduler.cancel(ctx)
        }
        bindActivationUi()
    }

    private fun onClickDecline() {
        val ctx = requireContext()
        val dialog = MaterialAlertDialogBuilder(ctx)
            .setTitle(R.string.scripture_reading_plan_decline_confirm_title)
            .setMessage(R.string.scripture_reading_plan_decline_confirm_message)
            .setNegativeButton(R.string.dialog_cancel, null)
            .setPositiveButton(R.string.scripture_reading_plan_decline) { _, _ ->
                ReadingPlanReminderScheduler.cancel(ctx)
                ScriptureReadingPlanActivationStore.declinePlan(ctx)
                selectedDayIndex = -1
                cachedPlanKind = null
                navigateToPlansHubAfterDecline()
            }
            .create()
        dialog.setOnShowListener {
            val buttonText = ctx.themeColor(R.attr.totusColorTextPrimary)
            dialog.getButton(AlertDialog.BUTTON_POSITIVE)?.setTextColor(buttonText)
            dialog.getButton(AlertDialog.BUTTON_NEGATIVE)?.setTextColor(buttonText)
        }
        dialog.show()
    }

    /** Пасля адмовы — спіс планаў (хаб), а не экран аднаго плана з уводзінамі. */
    private fun navigateToPlansHubAfterDecline() {
        if (!isAdded) return
        val nav = findNavController()
        if (nav.popBackStack(R.id.nav_scripture_reading_plans_hub, false)) {
            return
        }
        val opts = NavOptions.Builder()
            .setPopUpTo(R.id.nav_scripture_reading_plan, true)
            .build()
        nav.navigate(R.id.nav_scripture_reading_plans_hub, null, opts)
    }

    private fun bindActivationUi() {
        if (_binding == null) return
        val ctx = requireContext()
        val started = ScriptureReadingPlanActivationStore.isPlanStarted(ctx)
        binding.cardPlanIntro.isVisible = !started
        binding.layoutPlanActive.isVisible = started
        if (started) {
            val paused = ScriptureReadingPlanActivationStore.isRemindersPaused(ctx)
            binding.buttonPlanPauseResume.text = getString(
                if (paused) R.string.scripture_reading_plan_resume_reminders
                else R.string.scripture_reading_plan_pause_reminders
            )
        }
        updateBackToScriptureInterceptEnabled()
    }

    /** Пасля «Пачаць план» — «Назад» адразу ў каталог Пісання, не праз хаб. */
    private fun updateBackToScriptureInterceptEnabled() {
        if (!::backToScriptureWhenPlanActive.isInitialized) return
        val ctx = context ?: return
        backToScriptureWhenPlanActive.isEnabled =
            ScriptureReadingPlanActivationStore.isPlanStarted(ctx)
    }

    private fun planAnchorOrNull(): Long? =
        ScriptureReadingPlanActivationStore.getPlanAnchorDayMillis(requireContext())

    private fun resolveSelectedDayIfNeeded() {
        val ctx = requireContext()
        if (!ScriptureReadingPlanActivationStore.isPlanStarted(ctx)) return
        if (selectedDayIndex >= 0) return
        val anchor = planAnchorOrNull() ?: return
        selectedDayIndex = ScriptureYearReadingPlan.planDayIndexFromAnchorDayMillis(anchor)
    }

    private fun toggleDoneForSelectedDay() {
        val ctx = requireContext()
        val anchor = planAnchorOrNull() ?: return
        val kind = planKindForSession(ctx)
        val done = ScriptureReadingPlanCompletionStore.isDayDone(ctx, anchor, selectedDayIndex, kind)
        if (done) {
            ScriptureReadingPlanCompletionStore.markDayUndone(ctx, anchor, selectedDayIndex, kind)
        } else {
            ScriptureReadingPlanCompletionStore.markDayDone(ctx, anchor, selectedDayIndex, kind)
        }
        bindProgressOnly()
        updateToggleDoneButton()
    }

    private fun bindProgressOnly() {
        val ctx = requireContext()
        val anchor = planAnchorOrNull() ?: return
        val kind = planKindForSession(ctx)
        val n = ScriptureReadingPlanCompletionStore.completedCountForSession(ctx, anchor, kind)
        val max = ScriptureYearReadingPlan.PLAN_LENGTH
        binding.progressPlanDays.max = max
        binding.progressPlanDays.progress = n.coerceIn(0, max)
        binding.textPlanProgressYear.text =
            getString(R.string.scripture_reading_plan_progress_session, n, max)
    }

    private fun updateToggleDoneButton() {
        val ctx = requireContext()
        val anchor = planAnchorOrNull() ?: return
        val kind = planKindForSession(ctx)
        val done = ScriptureReadingPlanCompletionStore.isDayDone(ctx, anchor, selectedDayIndex, kind)
        binding.buttonPlanToggleDone.text = getString(
            if (done) R.string.scripture_reading_plan_mark_undone
            else R.string.scripture_reading_plan_mark_done
        )
    }

    private fun bindUi() {
        val buckets = cachedBuckets
        val tr = cachedTranslationId
        if (buckets == null || tr == null) return
        if (!ScriptureReadingPlanActivationStore.isPlanStarted(requireContext())) return

        resolveSelectedDayIfNeeded()
        val anchor = planAnchorOrNull() ?: return
        val todayIndex = ScriptureYearReadingPlan.planDayIndexFromAnchorDayMillis(anchor)

        binding.textPlanDayNumber.text = (selectedDayIndex + 1).toString()
        binding.textPlanDaySubtitle.text = getString(
            R.string.scripture_reading_plan_day_subtitle,
            ScriptureYearReadingPlan.PLAN_LENGTH
        )
        binding.textPlanTodayBadge.isVisible = selectedDayIndex == todayIndex

        bindProgressOnly()
        updateToggleDoneButton()

        binding.buttonPlanPrevDay.isEnabled = selectedDayIndex > 0
        binding.buttonPlanNextDay.isEnabled =
            selectedDayIndex < ScriptureYearReadingPlan.PLAN_LENGTH - 1

        val readings = buckets.getOrNull(selectedDayIndex).orEmpty()
        binding.layoutPlanReadings.removeAllViews()

        val planHasAnyContent = buckets.any { it.isNotEmpty() }
        if (!planHasAnyContent) {
            binding.textPlanEmpty.isVisible = true
            binding.textPlanEmpty.text = getString(R.string.scripture_reading_plan_no_data)
            binding.textPlanReadingsHeading.isVisible = false
            binding.layoutPlanReadings.isVisible = false
            applyHeroTypography()
            ScriptureUiTypography.applyUiSp(binding.textPlanEmpty, 15f)
            return
        }

        binding.textPlanEmpty.isVisible = readings.isEmpty()
        binding.textPlanEmpty.text = getString(R.string.scripture_reading_plan_empty_day)
        binding.textPlanReadingsHeading.isVisible = readings.isNotEmpty()
        binding.layoutPlanReadings.isVisible = readings.isNotEmpty()

        val gap = resources.getDimensionPixelSize(R.dimen.prayer_book_block_gap)
        val inflater = layoutInflater
        readings.forEachIndexed { idx, ref ->
            val row = inflater.inflate(R.layout.item_reading_plan_chapter, binding.layoutPlanReadings, false)
            row.layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            ).apply {
                if (idx > 0) topMargin = gap
            }
            row.findViewById<MaterialTextView>(R.id.text_chapter_title).text = getString(
                R.string.scripture_reading_plan_chapter_line,
                ref.bookTitle,
                ref.chapter
            )
            row.setOnClickListener {
                findNavController().navigate(
                    R.id.nav_scripture_chapter_text,
                    bundleOf(
                        ScriptureChaptersFragment.ARG_BOOK_ID to ref.bookId,
                        ScriptureChaptersFragment.ARG_BOOK_TITLE to ref.bookTitle,
                        ScriptureChaptersFragment.ARG_CHAPTER to ref.chapter
                    )
                )
            }
            binding.layoutPlanReadings.addView(row)
        }

        applyHeroTypography()
        ScriptureUiTypography.applyUiSp(binding.textPlanReadingsHeading, 12f)
        ScriptureUiTypography.applyUiSp(binding.textPlanEmpty, 15f)
        applyReadingRowsTypography()
    }

    private fun applyHeroTypography() {
        ScriptureUiTypography.applyUiSp(binding.textPlanDayNumber, 44f)
        ScriptureUiTypography.applyUiSp(binding.textPlanDaySubtitle, 13f)
        ScriptureUiTypography.applyUiSp(binding.textPlanProgressYear, 14f)
        ScriptureUiTypography.applyUiSp(binding.textPlanTodayBadge, 11f)
    }

    private fun applyReadingRowsTypography() {
        val n = binding.layoutPlanReadings.childCount
        for (i in 0 until n) {
            val row = binding.layoutPlanReadings.getChildAt(i)
            val tv = row.findViewById<MaterialTextView>(R.id.text_chapter_title) ?: continue
            ScriptureUiTypography.applyUiSp(tv, 16f)
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    companion object {
        private const val STATE_SELECTED_DAY = "reading_plan_selected_day"
    }
}
