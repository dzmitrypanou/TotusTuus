package by.dzmitrypanou.catholicapp.ui.liturgy

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.FragmentLiturgyCalendarSettingsBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography

class LiturgyCalendarSettingsFragment : Fragment() {

    private var _binding: FragmentLiturgyCalendarSettingsBinding? = null
    private val binding get() = _binding!!

    private var suppressCheckedCallbacks = false

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentLiturgyCalendarSettingsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        bindTypography()
        val ctx = requireContext()
        val cur = LiturgyDiocesePreferences.readFlags(ctx)
        suppressCheckedCallbacks = true
        binding.checkDiocesePinsk.isChecked = cur.pinskaya
        binding.checkDioceseMinsk.isChecked = cur.minskMogilev
        binding.checkDioceseVitebsk.isChecked = cur.vitebskaya
        binding.checkDioceseGrodno.isChecked = cur.grodzenskaya
        suppressCheckedCallbacks = false

        val persist: () -> Unit = {
            if (!suppressCheckedCallbacks) {
                LiturgyDiocesePreferences.writeFlags(
                    ctx,
                    LiturgyDiocesePreferences.Flags(
                        pinskaya = binding.checkDiocesePinsk.isChecked,
                        minskMogilev = binding.checkDioceseMinsk.isChecked,
                        vitebskaya = binding.checkDioceseVitebsk.isChecked,
                        grodzenskaya = binding.checkDioceseGrodno.isChecked,
                    )
                )
            }
        }
        binding.checkDiocesePinsk.setOnCheckedChangeListener { _, _ -> persist() }
        binding.checkDioceseMinsk.setOnCheckedChangeListener { _, _ -> persist() }
        binding.checkDioceseVitebsk.setOnCheckedChangeListener { _, _ -> persist() }
        binding.checkDioceseGrodno.setOnCheckedChangeListener { _, _ -> persist() }
    }

    override fun onResume() {
        super.onResume()
        bindTypography()
    }

    private fun bindTypography() {
        val ctx = context ?: return
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyDioceseIntro, R.dimen.text_list_row_subtitle, ctx)
        PrayerBookUiTypography.applyUiSp(binding.textLiturgyCalendarSettingsNote, R.dimen.text_banner_message, ctx)
        listOf(
            binding.checkDiocesePinsk,
            binding.checkDioceseMinsk,
            binding.checkDioceseVitebsk,
            binding.checkDioceseGrodno,
        ).forEach { PrayerBookUiTypography.applyUiSp(it, R.dimen.text_list_row_title, ctx) }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }
}
