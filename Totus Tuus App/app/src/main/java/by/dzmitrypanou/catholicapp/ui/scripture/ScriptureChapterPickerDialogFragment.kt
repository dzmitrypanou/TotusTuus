package by.dzmitrypanou.catholicapp.ui.scripture

import android.app.Dialog
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.os.bundleOf
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.fragment.app.DialogFragment
import androidx.recyclerview.widget.GridLayoutManager
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.DialogScriptureChapterPickerBinding
import by.dzmitrypanou.catholicapp.ui.themeColor
import by.dzmitrypanou.catholicapp.databinding.ItemScriptureChapterGridCellBinding
import com.google.android.material.card.MaterialCardView

class ScriptureChapterPickerDialogFragment : DialogFragment() {

    companion object {
        const val TAG = "ScriptureChapterPicker"
        const val REQUEST_KEY = "scripture_chapter_picker"
        const val RESULT_CHAPTER = "chapter"
        private const val ARG_BOOK_TITLE = "bookTitle"
        private const val ARG_CURRENT = "current"
        private const val ARG_TOTAL = "total"

        fun newInstance(bookTitle: String, current: Int, total: Int) =
            ScriptureChapterPickerDialogFragment().apply {
                arguments = bundleOf(
                    ARG_BOOK_TITLE to bookTitle,
                    ARG_CURRENT to current,
                    ARG_TOTAL to total
                )
            }
    }

    private var _binding: DialogScriptureChapterPickerBinding? = null
    private val binding get() = _binding!!

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setStyle(STYLE_NORMAL, R.style.Theme_CatholicPrayerBookBy_FullScreenDialog)
    }

    override fun onCreateDialog(savedInstanceState: Bundle?): Dialog {
        val d = super.onCreateDialog(savedInstanceState)
        d.window?.requestFeature(android.view.Window.FEATURE_NO_TITLE)
        return d
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = DialogScriptureChapterPickerBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onStart() {
        super.onStart()
        val w = dialog?.window ?: return
        WindowCompat.setDecorFitsSystemWindows(w, false)
        w.setLayout(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT,
        )
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val bookTitle = arguments?.getString(ARG_BOOK_TITLE).orEmpty()
        val current = arguments?.getInt(ARG_CURRENT, 1) ?: 1
        val total = arguments?.getInt(ARG_TOTAL, 0) ?: 0

        binding.toolbarChapterPicker.title = bookTitle
        binding.toolbarChapterPicker.setNavigationOnClickListener { dismiss() }

        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { v, windowInsets ->
            val bars = windowInsets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout(),
            )
            v.updatePadding(bars.left, bars.top, bars.right, bars.bottom)
            windowInsets
        }
        ViewCompat.requestApplyInsets(binding.root)

        ScriptureUiTypography.applyUiSp(binding.textChapterPickerEmpty, 16f)

        if (total <= 0) {
            binding.textChapterPickerEmpty.visibility = View.VISIBLE
            binding.recyclerChapterGrid.visibility = View.GONE
            return
        }

        binding.textChapterPickerEmpty.visibility = View.GONE
        binding.recyclerChapterGrid.visibility = View.VISIBLE

        val span = resources.getInteger(R.integer.scripture_chapter_grid_span)
        binding.recyclerChapterGrid.layoutManager = GridLayoutManager(requireContext(), span)
        binding.recyclerChapterGrid.adapter = ChapterGridAdapter(
            total = total,
            current = current,
            onCellClick = { chapter ->
                if (chapter != current) {
                    parentFragmentManager.setFragmentResult(
                        REQUEST_KEY,
                        bundleOf(RESULT_CHAPTER to chapter)
                    )
                }
                dismiss()
            }
        )

        binding.recyclerChapterGrid.post {
            if (current > 1) {
                val lm = binding.recyclerChapterGrid.layoutManager as? GridLayoutManager ?: return@post
                val pos = current - 1
                val pad = binding.recyclerChapterGrid.height / 4
                lm.scrollToPositionWithOffset(pos, pad.coerceAtLeast(0))
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private class ChapterGridAdapter(
        private val total: Int,
        private val current: Int,
        private val onCellClick: (Int) -> Unit
    ) : RecyclerView.Adapter<ChapterGridAdapter.VH>() {

        class VH(val binding: ItemScriptureChapterGridCellBinding) : RecyclerView.ViewHolder(binding.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
            val b = ItemScriptureChapterGridCellBinding.inflate(
                LayoutInflater.from(parent.context),
                parent,
                false
            )
            return VH(b)
        }

        override fun getItemCount(): Int = total

        override fun onBindViewHolder(holder: VH, position: Int) {
            val chapter = position + 1
            val card = holder.binding.root as MaterialCardView
            val ctx = holder.itemView.context
            holder.binding.textChapterCellNumber.text = chapter.toString()
            ScriptureUiTypography.applyUiSp(holder.binding.textChapterCellNumber, 17f)
            holder.itemView.contentDescription =
                ctx.getString(R.string.scripture_chapter_grid_cell_a11y, chapter)

            val strokeDefault = ctx.resources.getDimensionPixelSize(R.dimen.scripture_chapter_grid_cell_stroke_default)
            if (chapter == current) {
                card.strokeWidth = ctx.resources.getDimensionPixelSize(R.dimen.scripture_focus_verse_stroke_width)
                card.strokeColor = ctx.getColor(R.color.brand_totus_gold)
                card.setCardBackgroundColor(ctx.themeColor(R.attr.totusColorScriptureHighlightFill))
            } else {
                card.strokeWidth = strokeDefault
                card.strokeColor = ctx.themeColor(R.attr.totusColorSurfaceStroke)
                card.setCardBackgroundColor(ctx.themeColor(R.attr.totusColorBgSecondary))
            }

            holder.itemView.setOnClickListener { onCellClick(chapter) }
        }
    }
}
