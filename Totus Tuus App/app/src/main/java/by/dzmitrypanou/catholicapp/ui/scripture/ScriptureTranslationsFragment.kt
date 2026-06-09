package by.dzmitrypanou.catholicapp.ui.scripture

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureTranslationsBinding

class ScriptureTranslationsFragment : Fragment(), ScriptureToolbarActions {
    private var _binding: FragmentScriptureTranslationsBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureTranslationsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.scrollScriptureDescription.isVerticalScrollBarEnabled = false
        binding.dropdownScriptureTranslation.keyListener = null
        binding.dropdownScriptureTranslation.setOnClickListener {
            binding.dropdownScriptureTranslation.showDropDown()
        }
        binding.dropdownScriptureTranslation.setOnItemClickListener { _, _, position, _ ->
            val translations = ScriptureCatalog.allTranslations()
            val selected = translations[position]
            ScriptureTranslationStore.setSelectedTranslationId(requireContext(), selected.id)
            binding.textScriptureDescription.text = selected.description
            ScriptureUiTypography.applyUiSp(binding.textScriptureDescription, 15f)
        }
        binding.buttonScriptureTranslationSaveAndReturn.setOnClickListener {
            val title = binding.dropdownScriptureTranslation.text?.toString().orEmpty()
            val match = ScriptureCatalog.allTranslations().firstOrNull { it.title == title }
            if (match != null) {
                ScriptureTranslationStore.setSelectedTranslationId(requireContext(), match.id)
            }
            findNavController().navigateUp()
        }
    }

    override fun onResume() {
        super.onResume()
        refreshTranslationUi()
    }

    override fun onScriptureTextScaleChanged() {
        refreshTranslationUi()
    }

    private fun refreshTranslationUi() {
        val translations = ScriptureCatalog.allTranslations()
        if (translations.isEmpty()) return
        val titles = translations.map { it.title }
        val adapter = object : ArrayAdapter<String>(
            requireContext(),
            R.layout.item_scripture_translation_dropdown,
            android.R.id.text1,
            titles
        ) {
            override fun getView(position: Int, convertView: View?, parent: ViewGroup): View {
                val v = super.getView(position, convertView, parent)
                v.findViewById<TextView>(android.R.id.text1)?.let {
                    ScriptureUiTypography.applyUiSp(it, 16f)
                }
                return v
            }

            override fun getDropDownView(position: Int, convertView: View?, parent: ViewGroup): View {
                val v = super.getDropDownView(position, convertView, parent)
                v.findViewById<TextView>(android.R.id.text1)?.let {
                    ScriptureUiTypography.applyUiSp(it, 16f)
                }
                return v
            }
        }
        binding.dropdownScriptureTranslation.setAdapter(adapter)
        ScriptureUiTypography.applyUiSp(binding.dropdownScriptureTranslation, 16f)
        val selectedId = ScriptureTranslationStore.getSelectedTranslationId(requireContext())
        val selected = translations.firstOrNull { it.id == selectedId } ?: translations.first()
        binding.dropdownScriptureTranslation.setText(selected.title, false)
        binding.textScriptureDescription.text = selected.description
        ScriptureUiTypography.applyUiSp(binding.textScriptureDescription, 15f)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
