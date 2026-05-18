package by.dzmitrypanou.catholicapp.ui.settings

import android.content.DialogInterface
import android.content.Intent
import android.content.res.ColorStateList
import android.os.Bundle
import android.util.TypedValue
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import by.dzmitrypanou.catholicapp.ui.themeColor
import androidx.fragment.app.Fragment
import androidx.lifecycle.ViewModelProvider
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.AppColorSchemeStore
import by.dzmitrypanou.catholicapp.data.AppFontFamilyStore
import by.dzmitrypanou.catholicapp.data.AppGlobalTextScaleStore
import by.dzmitrypanou.catholicapp.databinding.FragmentSettingsBinding
import by.dzmitrypanou.catholicapp.sync.AppUpdateCheckStore
import com.google.android.material.button.MaterialButton
import com.google.android.material.dialog.MaterialAlertDialogBuilder

class SettingsFragment : Fragment() {
    companion object {
        private const val KEY_SCROLL_Y = "settings_scroll_y"
        @Volatile
        private var transientScrollY: Int = 0
    }

    private var _binding: FragmentSettingsBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        val settingsViewModel = ViewModelProvider(this)[SettingsViewModel::class.java]

        _binding = FragmentSettingsBinding.inflate(inflater, container, false)
        binding.buttonResetData.setOnClickListener {
            showResetDataConfirmation(settingsViewModel)
        }
        binding.buttonSettingsGlobalTextSmaller.setOnClickListener {
            AppGlobalTextScaleStore.adjust(requireContext(), -1f)
            applyGlobalPreview()
        }
        binding.buttonSettingsGlobalTextLarger.setOnClickListener {
            AppGlobalTextScaleStore.adjust(requireContext(), 1f)
            applyGlobalPreview()
        }
        binding.buttonSettingsResetTextDefaults.setOnClickListener {
            AppGlobalTextScaleStore.resetTextSizeToDefaults(requireContext())
            AppFontFamilyStore.resetToDefaults(requireContext())
            bindFontFamilySelector()
            applyGlobalPreview()
            applyFontEverywhere()
        }
        bindFontFamilySelector()
        bindColorSchemeSwitcher()
        bindAppUpdateNotificationsToggle()
        settingsViewModel.message.observe(viewLifecycleOwner) {
            binding.textSettingsStatus.text = it
        }
        val restoreY = savedInstanceState?.getInt(KEY_SCROLL_Y) ?: transientScrollY
        if (restoreY > 0) {
            binding.root.post {
                binding.root.scrollTo(0, restoreY)
                transientScrollY = 0
            }
        }
        return binding.root
    }

    override fun onResume() {
        super.onResume()
        applyGlobalPreview()
        bindFontFamilySelector()
        bindColorSchemeSwitcher()
        bindAppUpdateNotificationsToggle()
    }

    private fun bindAppUpdateNotificationsToggle() {
        val ctx = requireContext()
        binding.checkboxAppUpdateNotifications.setOnCheckedChangeListener(null)
        binding.checkboxAppUpdateNotifications.isChecked = AppUpdateCheckStore.isEnabled(ctx)
        binding.checkboxAppUpdateNotifications.setOnCheckedChangeListener { _, checked ->
            AppUpdateCheckStore.setEnabled(ctx, checked)
        }
    }

    private fun applyGlobalPreview() {
        val ctx = requireContext()
        val baseSp = 16f
        binding.textSettingsGlobalPreview.setTextSize(
            TypedValue.COMPLEX_UNIT_SP,
            baseSp * AppGlobalTextScaleStore.readScale(ctx)
        )
        binding.textSettingsFontPercent.text =
            getString(R.string.settings_font_size_step, AppGlobalTextScaleStore.readStepNumber(ctx))
        AppFontFamilyStore.applyToTextView(binding.textSettingsGlobalPreview, ctx)
        AppFontFamilyStore.applyToTextView(binding.textSettingsFontPercent, ctx)
    }

    private fun bindFontFamilySelector() {
        val ctx = requireContext()
        val dropdown = binding.dropdownSettingsFontFamily
        val families = listOf(
            AppFontFamilyStore.Family.SANS to getString(R.string.settings_font_family_sans),
            AppFontFamilyStore.Family.SERIF to getString(R.string.settings_font_family_serif),
            AppFontFamilyStore.Family.MONO to getString(R.string.settings_font_family_mono)
        )
        dropdown.setAdapter(
            ArrayAdapter(
                ctx,
                android.R.layout.simple_list_item_1,
                families.map { it.second }
            )
        )
        val selected = AppFontFamilyStore.readFamily(ctx)
        val selectedLabel = families.first { it.first == selected }.second
        dropdown.setText(selectedLabel, false)
        dropdown.setOnItemClickListener { _, _, position, _ ->
            val family = families[position].first
            AppFontFamilyStore.writeFamily(ctx, family)
            applyGlobalPreview()
            applyFontEverywhere()
        }
    }

    private fun bindColorSchemeSwitcher() {
        val ctx = requireContext()
        val selected = AppColorSchemeStore.readScheme(ctx)
        val selectedButtonId = if (selected == AppColorSchemeStore.Scheme.LIGHT) {
            R.id.button_settings_color_scheme_light
        } else {
            R.id.button_settings_color_scheme_dark
        }
        syncColorSchemeSwitcherUi(selectedButtonId)
        fun applySchemeIfNeeded(scheme: AppColorSchemeStore.Scheme) {
            if (scheme == AppColorSchemeStore.readScheme(ctx)) return
            transientScrollY = binding.root.scrollY
            AppColorSchemeStore.writeScheme(ctx, scheme)
            activity?.recreate()
        }
        binding.buttonSettingsColorSchemeDark.setOnClickListener {
            syncColorSchemeSwitcherUi(R.id.button_settings_color_scheme_dark)
            applySchemeIfNeeded(AppColorSchemeStore.Scheme.DARK)
        }
        binding.buttonSettingsColorSchemeLight.setOnClickListener {
            syncColorSchemeSwitcherUi(R.id.button_settings_color_scheme_light)
            applySchemeIfNeeded(AppColorSchemeStore.Scheme.LIGHT)
        }
    }

    private fun syncColorSchemeSwitcherUi(selectedButtonId: Int) {
        val activeBg = requireContext().themeColor(R.attr.totusColorBgSecondary)
        val activeText = requireContext().themeColor(R.attr.totusColorTextPrimary)
        val passiveBg = requireContext().themeColor(R.attr.totusColorBgPrimary)
        val passiveStroke = requireContext().themeColor(R.attr.totusColorSurfaceStroke)
        val passiveText = requireContext().themeColor(R.attr.totusColorTextSecondary)

        fun styleButton(button: MaterialButton, active: Boolean) {
            button.backgroundTintList = ColorStateList.valueOf(if (active) activeBg else passiveBg)
            button.strokeColor = ColorStateList.valueOf(passiveStroke)
            button.strokeWidth = if (active) 0 else 1
            button.setTextColor(if (active) activeText else passiveText)
        }

        styleButton(
            binding.buttonSettingsColorSchemeDark,
            selectedButtonId == R.id.button_settings_color_scheme_dark
        )
        styleButton(
            binding.buttonSettingsColorSchemeLight,
            selectedButtonId == R.id.button_settings_color_scheme_light
        )
    }

    private fun showResetDataConfirmation(settingsViewModel: SettingsViewModel) {
        val dialog = MaterialAlertDialogBuilder(requireContext())
            .setTitle(R.string.settings_reset_app_confirm_title)
            .setMessage(R.string.settings_reset_app_confirm_message)
            .setNegativeButton(R.string.settings_reset_app_no, null)
            .setPositiveButton(R.string.settings_reset_app_yes) { _, _ ->
                settingsViewModel.clearLocalData()
                restartApp()
            }
            .show()
        val buttonColor = requireContext().themeColor(R.attr.totusColorTextPrimary)
        dialog.getButton(DialogInterface.BUTTON_POSITIVE)?.setTextColor(buttonColor)
        dialog.getButton(DialogInterface.BUTTON_NEGATIVE)?.setTextColor(buttonColor)
    }

    private fun restartApp() {
        val activity = requireActivity()
        val loadingIntent = Intent(activity, ResetLoadingActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
        }
        startActivity(loadingIntent)
        activity.finish()
    }

    private fun applyFontEverywhere() {
        val activity = activity ?: return
        AppFontFamilyStore.applyToViewTree(activity.window.decorView, activity)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt(KEY_SCROLL_Y, _binding?.root?.scrollY ?: 0)
    }
}
