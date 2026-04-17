package by.dzmitrypanou.catholicapp.ui.home

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import android.graphics.ColorMatrix
import android.graphics.ColorMatrixColorFilter
import by.dzmitrypanou.catholicapp.ui.themeColor
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
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
                false,
                R.drawable.ordo_missae_header_image,
                infoHint = getString(R.string.home_ordo_missae_hint)
            ),
            HomeSection(getString(R.string.home_item_prayerbook), true, R.drawable.prayerbook_header_image),
            HomeSection(getString(R.string.home_item_liturgy_calendar), true, R.drawable.liturgy_calendar_header_image, spanSize = 2),
            HomeSection(getString(R.string.home_item_songbook), true, R.drawable.songbook_header_image),
            HomeSection(getString(R.string.home_item_kantaral), false, R.drawable.kantaral_header_image),
            HomeSection(getString(R.string.home_item_scripture), true, R.drawable.scripture_header_bible, spanSize = 2)
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
    val infoHint: String? = null
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
            PrayerBookUiTypography.applyUiSp(binding.textHomeItemStatus, R.dimen.text_home_card_caption, ctx)
            binding.textHomeItemTitle.text = item.title
            binding.imageHomeItem.setImageResource(item.imageRes)
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
                binding.textHomeItemTitle.setTextColor(
                    binding.root.context.themeColor(R.attr.totusColorTextPrimary)
                )
            } else {
                binding.textHomeItemStatus.visibility = View.VISIBLE
                binding.textHomeItemStatus.text = binding.root.context.getString(R.string.home_in_progress)
                binding.viewHomeItemOverlay.visibility = View.VISIBLE
                val matrix = ColorMatrix().apply { setSaturation(0f) }
                binding.imageHomeItem.colorFilter = ColorMatrixColorFilter(matrix)
                binding.textHomeItemTitle.setTextColor(
                    binding.root.context.themeColor(R.attr.totusColorTextPrimary)
                )
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
