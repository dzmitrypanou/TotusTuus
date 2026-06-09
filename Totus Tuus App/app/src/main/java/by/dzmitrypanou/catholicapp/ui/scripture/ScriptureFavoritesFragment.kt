package by.dzmitrypanou.catholicapp.ui.scripture

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import androidx.core.os.bundleOf
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.FragmentScriptureFavoritesBinding
import by.dzmitrypanou.catholicapp.ui.themeColor
import com.google.android.material.card.MaterialCardView
import com.google.android.material.textview.MaterialTextView

class ScriptureFavoritesFragment : Fragment(), ScriptureToolbarActions {
    private var _binding: FragmentScriptureFavoritesBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScriptureFavoritesBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onResume() {
        super.onResume()
        bindFavorites()
    }

    override fun onScriptureTextScaleChanged() {
        bindFavorites()
    }

    private fun bindFavorites() {
        binding.layoutScriptureFavorites.removeAllViews()
        val items = ScriptureVerseFavoritesStore.all(requireContext())
        if (items.isEmpty()) {
            binding.textScriptureFavoritesEmpty.visibility = View.VISIBLE
            ScriptureUiTypography.applyUiSp(binding.textScriptureFavoritesEmpty, 15f)
            return
        }
        binding.textScriptureFavoritesEmpty.visibility = View.GONE
        items.forEach { item ->
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
                    ScriptureTranslationStore.setSelectedTranslationId(requireContext(), item.translationId)
                    findNavController().navigate(
                        R.id.nav_scripture_chapter_text,
                        bundleOf(
                            ScriptureChaptersFragment.ARG_BOOK_ID to item.bookId,
                            ScriptureChaptersFragment.ARG_BOOK_TITLE to item.bookTitle,
                            ScriptureChaptersFragment.ARG_CHAPTER to item.chapter,
                            ScriptureChapterTextFragment.ARG_FOCUS_VERSE to item.verse
                        )
                    )
                }
            }
            val content = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(18, 14, 18, 14)
            }
            val meta = MaterialTextView(requireContext()).apply {
                text = "${item.translationTitle} • ${item.bookTitle} ${item.chapter}:${item.verse}"
                setTextColor(requireContext().themeColor(R.attr.totusColorTextSecondary))
                ScriptureUiTypography.applyUiSp(this, 13f)
            }
            val body = MaterialTextView(requireContext()).apply {
                text = item.text
                setTextColor(requireContext().themeColor(R.attr.totusColorTextPrimary))
                ScriptureUiTypography.applyReadingSp(this, 16f)
                setPadding(0, 8, 0, 0)
                maxLines = 4
                ellipsize = android.text.TextUtils.TruncateAt.END
            }
            content.addView(meta)
            content.addView(body)
            card.addView(content)
            binding.layoutScriptureFavorites.addView(card)
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
