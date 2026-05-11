package by.dzmitrypanou.catholicapp.ui.home

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import android.graphics.Color
import android.graphics.ColorMatrix
import android.graphics.ColorMatrixColorFilter
import android.util.TypedValue
import android.view.Gravity
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import android.widget.FrameLayout
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureTextRepository
import kotlinx.coroutines.launch
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.GridLayoutManager
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.FragmentHomeBinding
import by.dzmitrypanou.catholicapp.databinding.ItemHomeSectionBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography

class HomeFragment : Fragment() {

    private var _binding: FragmentHomeBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentHomeBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        val sections = listOf(
            HomeSection(
                getString(R.string.home_item_ordo_missae),
                true,
                R.drawable.ordo_missae_header_image,
            ),
            HomeSection(getString(R.string.home_item_prayerbook), true, R.drawable.prayerbook_header_image),
            HomeSection(getString(R.string.home_item_liturgy_calendar), true, R.drawable.liturgy_calendar_header_image, spanSize = 2),
            HomeSection(getString(R.string.home_item_solemnities), true, R.drawable.solemnities_header_image),
            HomeSection(getString(R.string.home_item_kantaral), false, R.drawable.kantaral_header_image),
            HomeSection(getString(R.string.home_item_songbook), true, R.drawable.songbook_header_image),
            HomeSection(getString(R.string.home_item_scripture), true, R.drawable.scripture_header_bible)
        )

        val adapter = HomeSectionAdapter(
            onAvailableClick = { section ->
                when (section.title) {
                    getString(R.string.home_item_prayerbook) ->
                        findNavController().navigate(R.id.action_nav_home_to_nav_transform)
                    getString(R.string.home_item_scripture) ->
                        findNavController().navigate(R.id.action_nav_home_to_nav_scripture)
                    getString(R.string.home_item_songbook) ->
                        findNavController().navigate(R.id.action_nav_home_to_nav_songbook)
                    getString(R.string.home_item_liturgy_calendar) ->
                        findNavController().navigate(R.id.action_nav_home_to_nav_liturgy_calendar)
                    getString(R.string.home_item_solemnities) ->
                        findNavController().navigate(R.id.action_nav_home_to_nav_solemnities)
                    getString(R.string.home_item_ordo_missae) ->
                        findNavController().navigate(R.id.action_nav_home_to_nav_ordo_missae)
                }
            },
            onUnavailableClick = {
                Toast.makeText(requireContext(), getString(R.string.home_in_progress), Toast.LENGTH_SHORT).show()
            },
            onInfoClick = { hint ->
                Toast.makeText(requireContext(), hint, Toast.LENGTH_SHORT).show()
            }
        )
        val grid = GridLayoutManager(requireContext(), 2).apply {
            spanSizeLookup = object : GridLayoutManager.SpanSizeLookup() {
                override fun getSpanSize(position: Int): Int =
                    adapter.currentList.getOrNull(position)?.spanSize?.coerceIn(1, 2) ?: 1
            }
        }
        binding.recyclerviewHome.layoutManager = grid
        binding.recyclerviewHome.adapter = adapter
        adapter.submitList(sections)

        viewLifecycleOwner.lifecycleScope.launch {
            ScriptureTextRepository.warmTestamentUiCatalog(requireContext().applicationContext)
        }
    }

    override fun onResume() {
        super.onResume()
        binding.recyclerviewHome.adapter?.notifyDataSetChanged()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

}

data class HomeSection(
    val title: String,
    val isAvailable: Boolean,
    val imageRes: Int,
    /** 1 — палова шырыні сеткі, 2 — на ўсю шырыню (як два звычайныя). */
    val spanSize: Int = 1,
    val infoHint: String? = null,
    val tileHeightDp: Int = 132,
    val leftIconRes: Int? = null,
    val centerTitle: Boolean = false,
)

private class HomeSectionAdapter(
    private val onAvailableClick: (HomeSection) -> Unit,
    private val onUnavailableClick: () -> Unit,
    private val onInfoClick: (String) -> Unit
) : ListAdapter<HomeSection, HomeSectionAdapter.HomeSectionViewHolder>(Diff) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): HomeSectionViewHolder {
        val binding = ItemHomeSectionBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return HomeSectionViewHolder(binding)
    }

    override fun onBindViewHolder(holder: HomeSectionViewHolder, position: Int) {
        holder.bind(getItem(position), onAvailableClick, onUnavailableClick, onInfoClick)
    }

    class HomeSectionViewHolder(
        private val binding: ItemHomeSectionBinding
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(
            item: HomeSection,
            onAvailableClick: (HomeSection) -> Unit,
            onUnavailableClick: () -> Unit,
            onInfoClick: (String) -> Unit
        ) {
            val ctx = binding.root.context
            PrayerBookUiTypography.applyUiSp(binding.textHomeItemTitle, R.dimen.text_home_card_title, ctx)
            PrayerBookUiTypography.applyUiSp(binding.textHomeItemCenterTitle, R.dimen.text_home_card_title, ctx)
            PrayerBookUiTypography.applyUiSp(binding.textHomeItemStatus, R.dimen.text_home_card_caption, ctx)
            binding.textHomeItemTitle.text = item.title
            binding.textHomeItemCenterTitle.text = item.title
            binding.textHomeItemTitle.setTextColor(Color.WHITE)
            binding.textHomeItemCenterTitle.setTextColor(Color.WHITE)
            binding.imageHomeItem.setImageResource(item.imageRes)
            binding.viewHomeItemScrim.visibility = if (item.centerTitle) View.GONE else View.VISIBLE
            binding.imageHomeItem.layoutParams = binding.imageHomeItem.layoutParams.apply {
                height = ViewGroup.LayoutParams.MATCH_PARENT
            }
            binding.root.layoutParams = binding.root.layoutParams.apply {
                val hPx = TypedValue.applyDimension(
                    TypedValue.COMPLEX_UNIT_DIP,
                    item.tileHeightDp.toFloat(),
                    ctx.resources.displayMetrics
                ).toInt()
                height = hPx
            }
            if (item.leftIconRes != null) {
                binding.textHomeItemTitle.layoutParams =
                    (binding.textHomeItemTitle.layoutParams as FrameLayout.LayoutParams).apply {
                        gravity = if (item.centerTitle) {
                            Gravity.CENTER
                        } else {
                            Gravity.CENTER_VERTICAL or Gravity.START
                        }
                    }
                binding.textHomeItemTitle.maxLines = 1
                if (item.centerTitle) {
                    binding.imageHomeItemLeftIcon.visibility = View.GONE
                    binding.layoutHomeItemCenterLabel.visibility = View.VISIBLE
                    binding.imageHomeItemCenterIcon.setImageResource(item.leftIconRes)
                    binding.textHomeItemTitle.visibility = View.GONE
                    binding.textHomeItemTitle.setCompoundDrawablesRelativeWithIntrinsicBounds(0, 0, 0, 0)
                    binding.textHomeItemTitle.compoundDrawablePadding = 0
                } else {
                    binding.imageHomeItemLeftIcon.visibility = View.VISIBLE
                    binding.imageHomeItemLeftIcon.setImageResource(item.leftIconRes)
                    binding.layoutHomeItemCenterLabel.visibility = View.GONE
                    binding.textHomeItemTitle.visibility = View.VISIBLE
                    binding.textHomeItemTitle.gravity = Gravity.START or Gravity.CENTER_VERTICAL
                    binding.textHomeItemTitle.setCompoundDrawablesRelativeWithIntrinsicBounds(0, 0, 0, 0)
                    binding.textHomeItemTitle.compoundDrawablePadding = 0
                    val start = TypedValue.applyDimension(
                        TypedValue.COMPLEX_UNIT_DIP,
                        46f,
                        ctx.resources.displayMetrics
                    ).toInt()
                    val end = TypedValue.applyDimension(
                        TypedValue.COMPLEX_UNIT_DIP,
                        8f,
                        ctx.resources.displayMetrics
                    ).toInt()
                    binding.textHomeItemTitle.setPadding(start, 0, end, 0)
                }
            } else {
                binding.imageHomeItemLeftIcon.visibility = View.GONE
                binding.layoutHomeItemCenterLabel.visibility = View.GONE
                binding.textHomeItemTitle.visibility = View.VISIBLE
                binding.textHomeItemTitle.setCompoundDrawablesRelativeWithIntrinsicBounds(0, 0, 0, 0)
                binding.textHomeItemTitle.compoundDrawablePadding = 0
                binding.textHomeItemTitle.layoutParams =
                    (binding.textHomeItemTitle.layoutParams as FrameLayout.LayoutParams).apply {
                        gravity = Gravity.BOTTOM or Gravity.START
                    }
                binding.textHomeItemTitle.maxLines = 2
                val horizontal = TypedValue.applyDimension(
                    TypedValue.COMPLEX_UNIT_DIP,
                    6f,
                    ctx.resources.displayMetrics
                ).toInt()
                val bottom = TypedValue.applyDimension(
                    TypedValue.COMPLEX_UNIT_DIP,
                    6f,
                    ctx.resources.displayMetrics
                ).toInt()
                binding.textHomeItemTitle.setPadding(horizontal, 0, horizontal, bottom)
            }
            val hint = item.infoHint
            if (hint.isNullOrBlank()) {
                binding.buttonHomeItemInfo.visibility = View.GONE
                binding.buttonHomeItemInfo.setOnClickListener(null)
            } else {
                binding.buttonHomeItemInfo.visibility = View.VISIBLE
                binding.buttonHomeItemInfo.setOnClickListener { onInfoClick(hint) }
            }

            if (item.isAvailable) {
                binding.textHomeItemStatus.visibility = View.GONE
                binding.viewHomeItemOverlay.visibility = View.GONE
                binding.imageHomeItem.colorFilter = null
            } else {
                binding.textHomeItemStatus.visibility = View.VISIBLE
                binding.textHomeItemStatus.text = binding.root.context.getString(R.string.home_in_progress)
                binding.viewHomeItemOverlay.visibility = View.VISIBLE
                val matrix = ColorMatrix().apply { setSaturation(0f) }
                binding.imageHomeItem.colorFilter = ColorMatrixColorFilter(matrix)
            }

            binding.root.setOnClickListener {
                if (item.isAvailable) onAvailableClick(item) else onUnavailableClick()
            }
        }
    }

    private object Diff : DiffUtil.ItemCallback<HomeSection>() {
        override fun areItemsTheSame(oldItem: HomeSection, newItem: HomeSection): Boolean =
            oldItem.title == newItem.title

        override fun areContentsTheSame(oldItem: HomeSection, newItem: HomeSection): Boolean =
            oldItem == newItem
    }
}
