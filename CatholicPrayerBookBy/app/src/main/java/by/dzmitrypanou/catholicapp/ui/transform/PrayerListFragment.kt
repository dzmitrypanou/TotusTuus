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
import by.dzmitrypanou.catholicapp.databinding.FragmentPrayerListBinding
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import kotlinx.coroutines.launch

class PrayerListFragment : Fragment(), PrayerBookToolbarActions {
    private var _binding: FragmentPrayerListBinding? = null
    private val binding get() = _binding!!

    private lateinit var prayerNameAdapter: PrayerNameAdapter

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentPrayerListBinding.inflate(inflater, container, false)
        val category = arguments?.getString("category").orEmpty()
        val subcategory = arguments?.getString("subcategory").orEmpty()
        val screenTitle =
            if (subcategory == PrayerRepository.NO_SUBCATEGORY_TITLE || subcategory.isBlank()) {
                if (category == PrayerRepository.NO_CATEGORY_TITLE) {
                    getString(R.string.prayer_bucket_no_category)
                } else {
                    category
                }
            } else {
                subcategory
            }
        requireActivity().title = screenTitle
        binding.textPrayerListTitle.visibility = View.GONE

        prayerNameAdapter = PrayerNameAdapter { prayer ->
            findNavController().navigate(
                R.id.action_nav_prayer_list_to_nav_prayer_detail,
                bundleOf(
                    "prayerId" to prayer.id,
                    "title" to prayer.title,
                    "text" to prayer.text,
                    "category" to prayer.category,
                    "subcategory" to prayer.subcategory
                )
            )
        }
        binding.recyclerPrayerList.adapter = prayerNameAdapter

        lifecycleScope.launch {
            val repo = PrayerRepository(requireContext())
            val list = repo.getPrayersInSubcategory(category, subcategory)
            prayerNameAdapter.submitList(list)
            binding.textPrayerListEmpty.text = if (list.isEmpty() && category == PrayerRepository.NO_CATEGORY_TITLE) {
                getString(R.string.no_prayers_uncategorized)
            } else {
                getString(R.string.no_prayers_in_subcategory)
            }
            binding.textPrayerListEmpty.visibility = if (list.isEmpty()) View.VISIBLE else View.GONE
        }
        return binding.root
    }

    override fun onResume() {
        super.onResume()
        requireActivity().invalidateOptionsMenu()
        val b = _binding ?: return
        val ctx = requireContext()
        PrayerBookUiTypography.applyUiSp(b.textPrayerListTitle, R.dimen.text_section_header_title, ctx)
        PrayerBookUiTypography.applyUiSp(b.textPrayerListEmpty, R.dimen.text_banner_message, ctx)
        if (::prayerNameAdapter.isInitialized) {
            prayerNameAdapter.notifyDataSetChanged()
        }
    }

    override fun refreshPrayerDataFromToolbar() {
        val b = _binding ?: return
        val category = arguments?.getString("category").orEmpty()
        val subcategory = arguments?.getString("subcategory").orEmpty()
        viewLifecycleOwner.lifecycleScope.launch {
            val repo = PrayerRepository(requireContext())
            runCatching { repo.refreshPrayers(allowHashShortCircuit = false) }
            val list = repo.getPrayersInSubcategory(category, subcategory)
            prayerNameAdapter.submitList(list)
            b.textPrayerListEmpty.text = if (list.isEmpty() && category == PrayerRepository.NO_CATEGORY_TITLE) {
                getString(R.string.no_prayers_uncategorized)
            } else {
                getString(R.string.no_prayers_in_subcategory)
            }
            b.textPrayerListEmpty.visibility = if (list.isEmpty()) View.VISIBLE else View.GONE
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    class PrayerNameAdapter(private val onClick: (Prayer) -> Unit) :
        ListAdapter<Prayer, PrayerNameViewHolder>(object : DiffUtil.ItemCallback<Prayer>() {
            override fun areItemsTheSame(oldItem: Prayer, newItem: Prayer): Boolean = oldItem.id == newItem.id
            override fun areContentsTheSame(oldItem: Prayer, newItem: Prayer): Boolean = oldItem == newItem
        }) {
        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): PrayerNameViewHolder {
            val binding = ItemPrayerTreeBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return PrayerNameViewHolder(binding)
        }

        override fun onBindViewHolder(holder: PrayerNameViewHolder, position: Int) {
            holder.bind(getItem(position), onClick)
        }
    }

    class PrayerNameViewHolder(private val binding: ItemPrayerTreeBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(prayer: Prayer, onClick: (Prayer) -> Unit) {
            binding.textTreeTitle.text = prayer.title
            binding.textTreeSubtitle.visibility = View.GONE
            binding.root.setOnClickListener { onClick(prayer) }
            PrayerBookUiTypography.bindPrayerTreeRow(binding, binding.root.context)
        }
    }
}
