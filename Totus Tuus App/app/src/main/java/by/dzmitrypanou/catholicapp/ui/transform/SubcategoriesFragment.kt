package by.dzmitrypanou.catholicapp.ui.transform

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.os.bundleOf
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.Prayer
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.databinding.FragmentSubcategoriesBinding
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import kotlinx.coroutines.launch

class SubcategoriesFragment : Fragment(), PrayerBookToolbarActions {
    private var _binding: FragmentSubcategoriesBinding? = null
    private val binding get() = _binding!!

    private lateinit var treeAdapter: CategoryTreeAdapter

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentSubcategoriesBinding.inflate(inflater, container, false)
        val category = arguments?.getString("category").orEmpty()
        requireActivity().title =
            if (category == PrayerRepository.NO_CATEGORY_TITLE) {
                getString(R.string.prayer_bucket_no_category)
            } else {
                category
            }

        treeAdapter = CategoryTreeAdapter(
            onSubcategoryClick = { subcategory ->
                findNavController().navigate(
                    R.id.action_nav_subcategories_to_nav_prayer_list,
                    bundleOf(
                        "category" to category,
                        "subcategory" to subcategory
                    )
                )
            },
            onPrayerClick = { prayer ->
                findNavController().navigate(
                    R.id.action_nav_subcategories_to_nav_prayer_detail,
                    bundleOf(
                        "prayerId" to prayer.id,
                        "title" to prayer.title,
                        "text" to prayer.text,
                        "category" to prayer.category.orEmpty(),
                        "subcategory" to prayer.subcategory.orEmpty()
                    )
                )
            }
        )
        binding.recyclerSubcategories.adapter = treeAdapter

        lifecycleScope.launch {
            loadAndSubmitTree(category)
        }

        return binding.root
    }

    override fun onResume() {
        super.onResume()
        requireActivity().invalidateOptionsMenu()
        val b = _binding ?: return
        PrayerBookUiTypography.applyUiSp(b.textSubcategoriesEmpty, R.dimen.text_banner_message, requireContext())
        if (::treeAdapter.isInitialized) {
            treeAdapter.notifyDataSetChanged()
        }
    }

    override fun refreshPrayerDataFromToolbar() {
        val b = _binding ?: return
        val category = arguments?.getString("category").orEmpty()
        viewLifecycleOwner.lifecycleScope.launch {
            val repo = PrayerRepository(requireContext())
            runCatching { repo.refreshPrayers(allowHashShortCircuit = false) }
            loadAndSubmitTree(category)
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private suspend fun loadAndSubmitTree(category: String) {
        val repo = PrayerRepository(requireContext())
        val subcategories = repo.getSubcategoryNames(category)
        val categoryPrayers = repo.getPrayersInSubcategory(
            category = category,
            subcategory = PrayerRepository.NO_SUBCATEGORY_TITLE
        )

binding.textSubcategoriesEmpty.visibility =
            if (subcategories.isEmpty() && categoryPrayers.isEmpty()) View.VISIBLE else View.GONE

        val rows = buildList {
            addAll(subcategories.map { TreeRow.SubcategoryRow(it) })
            addAll(categoryPrayers.map { TreeRow.PrayerRow(it) })
        }
        treeAdapter.submitList(rows)
    }

    private sealed class TreeRow {
        data class SubcategoryRow(val title: String) : TreeRow()
        data class PrayerRow(val prayer: Prayer) : TreeRow()
    }

    private class CategoryTreeAdapter(
        private val onSubcategoryClick: (String) -> Unit,
        private val onPrayerClick: (Prayer) -> Unit
    ) : ListAdapter<TreeRow, TreeRowViewHolder>(
        object : DiffUtil.ItemCallback<TreeRow>() {
            override fun areItemsTheSame(oldItem: TreeRow, newItem: TreeRow): Boolean {
                return when {
                    oldItem is TreeRow.SubcategoryRow && newItem is TreeRow.SubcategoryRow ->
                        oldItem.title == newItem.title
                    oldItem is TreeRow.PrayerRow && newItem is TreeRow.PrayerRow ->
                        oldItem.prayer.id == newItem.prayer.id
                    else -> false
                }
            }

            override fun areContentsTheSame(oldItem: TreeRow, newItem: TreeRow): Boolean =
                oldItem == newItem
        }
    ) {
        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): TreeRowViewHolder {
            val binding = ItemPrayerTreeBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return TreeRowViewHolder(binding)
        }

        override fun onBindViewHolder(holder: TreeRowViewHolder, position: Int) {
            holder.bind(getItem(position), onSubcategoryClick, onPrayerClick)
        }
    }

    private class TreeRowViewHolder(private val binding: ItemPrayerTreeBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(
            row: TreeRow,
            onSubcategoryClick: (String) -> Unit,
            onPrayerClick: (Prayer) -> Unit
        ) {
            when (row) {
                is TreeRow.SubcategoryRow -> {
                    binding.textTreeTitle.text = row.title
                    binding.textTreeSubtitle.visibility = View.GONE
                    binding.root.setOnClickListener { onSubcategoryClick(row.title) }
                }

                is TreeRow.PrayerRow -> {
                    val prayer = row.prayer
                    binding.textTreeTitle.text = prayer.title
                    binding.textTreeSubtitle.visibility = View.GONE
                    binding.root.setOnClickListener { onPrayerClick(prayer) }
                }
            }
            PrayerBookUiTypography.bindPrayerTreeRow(binding, binding.root.context)
        }
    }
}
