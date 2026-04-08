package by.dzmitrypanou.catholicapp.ui.transform

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.os.bundleOf
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.ViewModelProvider
import androidx.navigation.fragment.findNavController
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.PrayerCacheInvalidationNotifier
import by.dzmitrypanou.catholicapp.data.PrayerRefreshRequestStore
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.databinding.FragmentTransformBinding
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import kotlinx.coroutines.launch

/**
 * Fragment that demonstrates a responsive layout pattern where the format of the content
 * transforms depending on the size of the screen. Specifically this Fragment shows items in
 * the [RecyclerView] using LinearLayoutManager in a small screen
 * and shows items using GridLayoutManager in a large screen.
 */
class TransformFragment : Fragment(), PrayerBookToolbarActions {

    private var _binding: FragmentTransformBinding? = null

    // This property is only valid between onCreateView and
    // onDestroyView.
    private val binding get() = _binding!!

    private lateinit var categoryAdapter: CategoryAdapter
    private lateinit var transformViewModel: TransformViewModel
    private var toolbarSyncInProgress: Boolean = false

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        transformViewModel = ViewModelProvider(this)[TransformViewModel::class.java]
        _binding = FragmentTransformBinding.inflate(inflater, container, false)
        val root: View = binding.root

        binding.layoutPrayerBookSearchEntry?.setOnClickListener {
            findNavController().navigate(R.id.action_global_nav_prayer_search)
        }

        val recyclerView = binding.recyclerviewTransform
        val repository = PrayerRepository(requireContext())
        categoryAdapter = CategoryAdapter { category ->
                lifecycleScope.launch {
                    val subcategories = repository.getSubcategoryNames(category)
                    if (subcategories.isEmpty()) {
                        // No visible subcategories: open category prayers directly.
                        findNavController().navigate(
                            R.id.action_nav_transform_to_nav_prayer_list,
                            bundleOf(
                                "category" to category,
                                "subcategory" to PrayerRepository.NO_SUBCATEGORY_TITLE
                            )
                        )
                    } else {
                        findNavController().navigate(
                            R.id.action_nav_transform_to_nav_subcategories,
                            bundleOf("category" to category)
                        )
                    }
                }
            }
        recyclerView.adapter = categoryAdapter
        transformViewModel.uiState.observe(viewLifecycleOwner) { state ->
            toolbarSyncInProgress = state.isSyncingInToolbar
            requireActivity().invalidateOptionsMenu()
            categoryAdapter.submitList(state.categories)
            binding.progressTransform.visibility = if (state.isLoading) View.VISIBLE else View.GONE

            val showCentered = !state.isLoading && state.centeredEmptyMessage != null
            binding.textTransformEmptyCenter.visibility = if (showCentered) View.VISIBLE else View.GONE
            binding.textTransformEmptyCenter.text = state.centeredEmptyMessage.orEmpty()
            binding.recyclerviewTransform.visibility = when {
                state.isLoading -> View.INVISIBLE
                showCentered -> View.GONE
                else -> View.VISIBLE
            }

            binding.textStatusTransform.text = state.bannerMessage
            binding.textStatusTransform.visibility =
                if (state.bannerMessage.isNullOrBlank()) View.GONE else View.VISIBLE
        }
        return root
    }

    override fun onResume() {
        super.onResume()
        requireActivity().invalidateOptionsMenu()
        applyPrayerBookListTypography()
        if (::categoryAdapter.isInitialized) {
            categoryAdapter.notifyDataSetChanged()
        }
        if (PrayerCacheInvalidationNotifier.consumePendingReload()) {
            transformViewModel.loadPrayers(forceRefresh = false)
        }
        if (PrayerRefreshRequestStore.consumePendingRefresh(requireContext())) {
            transformViewModel.loadPrayers(forceRefresh = true)
        }
    }

    override fun refreshPrayerDataFromToolbar() {
        if (::transformViewModel.isInitialized) {
            transformViewModel.loadPrayers(forceRefresh = true)
        }
    }

    override fun isPrayerDataSyncInProgress(): Boolean = toolbarSyncInProgress

    private fun applyPrayerBookListTypography() {
        val b = _binding ?: return
        val ctx = requireContext()
        b.textPrayerBookSearchEntryTitle?.let { title ->
            PrayerBookUiTypography.applyUiSp(title, R.dimen.text_list_row_title, ctx)
        }
        PrayerBookUiTypography.applyUiSp(b.textStatusTransform, R.dimen.text_banner_message, ctx)
        PrayerBookUiTypography.applyUiSp(b.textTransformEmptyCenter, R.dimen.text_banner_message, ctx)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    class CategoryAdapter(private val onClick: (String) -> Unit) :
        ListAdapter<String, CategoryViewHolder>(object : DiffUtil.ItemCallback<String>() {
            override fun areItemsTheSame(oldItem: String, newItem: String): Boolean = oldItem == newItem
            override fun areContentsTheSame(oldItem: String, newItem: String): Boolean = oldItem == newItem
        }) {
        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): CategoryViewHolder {
            val binding = ItemPrayerTreeBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return CategoryViewHolder(binding)
        }

        override fun onBindViewHolder(holder: CategoryViewHolder, position: Int) {
            holder.bind(getItem(position), onClick)
        }
    }

    class CategoryViewHolder(private val binding: ItemPrayerTreeBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(title: String, onClick: (String) -> Unit) {
            binding.textTreeTitle.text = title
            binding.textTreeSubtitle.visibility = View.GONE
            binding.root.setOnClickListener { onClick(title) }
            PrayerBookUiTypography.bindPrayerTreeRow(binding, binding.root.context)
        }
    }
}