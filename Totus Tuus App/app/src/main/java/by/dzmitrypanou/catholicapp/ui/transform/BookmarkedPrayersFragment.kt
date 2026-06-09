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
import by.dzmitrypanou.catholicapp.data.PrayerBookmarksStore
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.databinding.FragmentBookmarkedPrayersBinding
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import kotlinx.coroutines.launch

class BookmarkedPrayersFragment : Fragment() {

    private var _binding: FragmentBookmarkedPrayersBinding? = null
    private val binding get() = _binding!!

    private lateinit var listAdapter: BookmarkedAdapter

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentBookmarkedPrayersBinding.inflate(inflater, container, false)
        listAdapter = BookmarkedAdapter { prayer ->
            findNavController().navigate(
                R.id.action_nav_bookmarked_to_nav_prayer_detail,
                bundleOf(
                    "prayerId" to prayer.id,
                    "title" to prayer.title,
                    "text" to prayer.text,
                    "category" to (prayer.category.orEmpty()),
                    "subcategory" to (prayer.subcategory.orEmpty())
                )
            )
        }
        binding.recyclerBookmarkedPrayers.adapter = listAdapter
        applyPrayerBookUiScale()
        loadBookmarkedPrayers()
        return binding.root
    }

    override fun onResume() {
        super.onResume()
        applyPrayerBookUiScale()
        if (_binding != null) {
            loadBookmarkedPrayers()
        }
    }

    private fun applyPrayerBookUiScale() {
        val b = _binding ?: return
        PrayerBookUiTypography.applyUiSp(b.textBookmarkedEmpty, R.dimen.text_banner_message, requireContext())
        if (::listAdapter.isInitialized) {
            listAdapter.notifyDataSetChanged()
        }
    }

    private fun loadBookmarkedPrayers() {
        val binding = _binding ?: return
        lifecycleScope.launch {
            val repo = PrayerRepository(requireContext())
            val ids = PrayerBookmarksStore(requireContext()).getBookmarkedIdsOrdered()
            val list = repo.getPrayersByIds(ids)
            listAdapter.submitList(list)
            binding.textBookmarkedEmpty.visibility = if (list.isEmpty()) View.VISIBLE else View.GONE
            binding.recyclerBookmarkedPrayers.visibility = if (list.isEmpty()) View.GONE else View.VISIBLE
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private class BookmarkedAdapter(
        private val onClick: (Prayer) -> Unit
    ) : ListAdapter<Prayer, BookmarkedAdapter.Holder>(Diff) {

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder {
            val itemBinding = ItemPrayerTreeBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return Holder(itemBinding, onClick)
        }

        override fun onBindViewHolder(holder: Holder, position: Int) {
            holder.bind(getItem(position))
        }

        class Holder(
            private val binding: ItemPrayerTreeBinding,
            private val onClick: (Prayer) -> Unit
        ) : RecyclerView.ViewHolder(binding.root) {

            fun bind(prayer: Prayer) {
                binding.textTreeTitle.text = prayer.title
                val cat = prayer.category?.trim().orEmpty()
                val sub = prayer.subcategory?.trim().orEmpty()
                val ctx = binding.root.context
                val meta = when {
                    cat.isNotBlank() && sub.isNotBlank() && sub != PrayerRepository.NO_SUBCATEGORY_TITLE ->
                        "$cat • $sub"
                    cat.isNotBlank() -> cat
                    sub.isNotBlank() && sub != PrayerRepository.NO_SUBCATEGORY_TITLE -> sub
                    else -> ctx.getString(R.string.prayer_bucket_no_category)
                }
                binding.textTreeSubtitle.text = meta
                binding.textTreeSubtitle.visibility = View.VISIBLE
                binding.root.setOnClickListener { onClick(prayer) }
                PrayerBookUiTypography.bindPrayerTreeRow(binding, binding.root.context)
            }
        }

        private object Diff : DiffUtil.ItemCallback<Prayer>() {
            override fun areItemsTheSame(oldItem: Prayer, newItem: Prayer): Boolean = oldItem.id == newItem.id
            override fun areContentsTheSame(oldItem: Prayer, newItem: Prayer): Boolean = oldItem == newItem
        }
    }
}
