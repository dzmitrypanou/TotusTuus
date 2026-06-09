package by.dzmitrypanou.catholicapp.ui.scripture

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.os.bundleOf
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureReadingPlansHubBinding
import com.google.android.material.dialog.MaterialAlertDialogBuilder

class ScriptureReadingPlansHubFragment : Fragment(), ScriptureToolbarActions {

    private var _binding: FragmentScriptureReadingPlansHubBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureReadingPlansHubBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.textReadingPlansHubIntro.text = getString(R.string.scripture_reading_plans_hub_intro)
        binding.layoutHubPlanLinear.setOnClickListener { openPlan(ScriptureReadingPlanKind.LINEAR) }
        binding.layoutHubPlanChrono.setOnClickListener { openPlan(ScriptureReadingPlanKind.CHRONOLOGICAL) }
        binding.layoutHubPlanMixed.setOnClickListener { openPlan(ScriptureReadingPlanKind.MIXED) }
        applyTypography()
    }

    override fun onScriptureTextScaleChanged() {
        applyTypography()
    }

    private fun applyTypography() {
        val ctx = context ?: return
        ScriptureUiTypography.applyUiSp(binding.textReadingPlansHubIntro, 15f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textHubPlanLinearTitle, 17f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textHubPlanLinearSubtitle, 13f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textHubPlanChronoTitle, 17f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textHubPlanChronoSubtitle, 13f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textHubPlanMixedTitle, 17f, ctx)
        ScriptureUiTypography.applyUiSp(binding.textHubPlanMixedSubtitle, 13f, ctx)
    }

    private fun openPlan(kind: ScriptureReadingPlanKind) {
        val ctx = requireContext()
        if (ScriptureReadingPlanActivationStore.isPlanStarted(ctx)) {
            val current = ScriptureReadingPlanActivationStore.getPlanKind(ctx)
            if (current == kind) {
                navigateToPlan(kind)
                return
            }
            MaterialAlertDialogBuilder(ctx)
                .setTitle(R.string.scripture_reading_plan_switch_kind_title)
                .setMessage(R.string.scripture_reading_plan_switch_kind_message)
                .setNegativeButton(R.string.dialog_cancel, null)
                .setPositiveButton(R.string.scripture_reading_plan_switch_kind_positive) { _, _ ->
                    ReadingPlanReminderScheduler.cancel(ctx)
                    ScriptureReadingPlanActivationStore.declinePlan(ctx)
                    navigateToPlan(kind)
                }
                .show()
            return
        }
        navigateToPlan(kind)
    }

    private fun navigateToPlan(kind: ScriptureReadingPlanKind) {
        findNavController().navigate(
            R.id.action_nav_scripture_reading_plans_hub_to_nav_scripture_reading_plan,
            bundleOf(ScriptureReadingPlanKind.NAV_ARG_PLAN_KIND to kind.storageKey)
        )
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
