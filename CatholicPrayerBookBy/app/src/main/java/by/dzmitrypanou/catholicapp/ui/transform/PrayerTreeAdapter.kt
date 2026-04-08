package by.dzmitrypanou.catholicapp.ui.transform

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.graphics.Typeface
import androidx.core.content.ContextCompat
import by.dzmitrypanou.catholicapp.ui.themeColor
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.Prayer
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography

sealed class PrayerTreeItem {
    data class Category(
        val key: String,
        val title: String,
        val count: Int,
        val isExpanded: Boolean
    ) : PrayerTreeItem()

    data class Subcategory(
        val key: String,
        val parentKey: String,
        val title: String,
        val count: Int,
        val isExpanded: Boolean
    ) : PrayerTreeItem()

    data class PrayerLeaf(
        val key: String,
        val prayer: Prayer
    ) : PrayerTreeItem()
}

class PrayerTreeAdapter(
    private val onCategoryClick: (String) -> Unit,
    private val onSubcategoryClick: (String) -> Unit,
    private val onPrayerClick: (Prayer) -> Unit
) : ListAdapter<PrayerTreeItem, PrayerTreeAdapter.TreeViewHolder>(Diff) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): TreeViewHolder {
        val binding = ItemPrayerTreeBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return TreeViewHolder(binding)
    }

    override fun onBindViewHolder(holder: TreeViewHolder, position: Int) {
        when (val item = getItem(position)) {
            is PrayerTreeItem.Category -> holder.bindCategory(item, onCategoryClick)
            is PrayerTreeItem.Subcategory -> holder.bindSubcategory(item, onSubcategoryClick)
            is PrayerTreeItem.PrayerLeaf -> holder.bindPrayer(item, onPrayerClick)
        }
    }

    class TreeViewHolder(private val binding: ItemPrayerTreeBinding) : RecyclerView.ViewHolder(binding.root) {

        fun bindCategory(item: PrayerTreeItem.Category, onClick: (String) -> Unit) {
            binding.root.setOnClickListener { onClick(item.key) }
            binding.root.isClickable = true
            binding.textTreeTitle.text = (if (item.isExpanded) "▼ " else "▶ ") + item.title
            binding.textTreeTitle.setTextColor(ContextCompat.getColor(binding.root.context, R.color.teal_200))
            binding.textTreeTitle.setTypeface(null, Typeface.BOLD)
            binding.textTreeSubtitle.text = "Подкатегорий: ${item.count}"
            binding.textTreeSubtitle.visibility = View.VISIBLE
            PrayerBookUiTypography.bindPrayerTreeRow(binding, binding.root.context)
        }

        fun bindSubcategory(item: PrayerTreeItem.Subcategory, onClick: (String) -> Unit) {
            binding.root.setOnClickListener { onClick(item.key) }
            binding.root.isClickable = true
            binding.textTreeTitle.text = "   " + (if (item.isExpanded) "▼ " else "▶ ") + item.title
            binding.textTreeTitle.setTextColor(ContextCompat.getColor(binding.root.context, R.color.teal_200))
            binding.textTreeTitle.setTypeface(null, Typeface.BOLD)
            binding.textTreeSubtitle.text = "Молитв: ${item.count}"
            binding.textTreeSubtitle.visibility = View.VISIBLE
            PrayerBookUiTypography.bindPrayerTreeRow(binding, binding.root.context)
        }

        fun bindPrayer(item: PrayerTreeItem.PrayerLeaf, onClick: (Prayer) -> Unit) {
            binding.root.setOnClickListener { onClick(item.prayer) }
            binding.root.isClickable = true
            binding.textTreeTitle.text = "      • " + item.prayer.title
            binding.textTreeTitle.setTextColor(binding.root.context.themeColor(R.attr.totusColorTextPrimary))
            binding.textTreeTitle.setTypeface(null, Typeface.NORMAL)
            binding.textTreeSubtitle.text = item.prayer.language ?: ""
            binding.textTreeSubtitle.visibility =
                if (binding.textTreeSubtitle.text.isBlank()) View.GONE else View.VISIBLE
            PrayerBookUiTypography.bindPrayerTreeRow(binding, binding.root.context)
        }
    }

    private object Diff : DiffUtil.ItemCallback<PrayerTreeItem>() {
        override fun areItemsTheSame(oldItem: PrayerTreeItem, newItem: PrayerTreeItem): Boolean =
            when {
                oldItem is PrayerTreeItem.Category && newItem is PrayerTreeItem.Category -> oldItem.key == newItem.key
                oldItem is PrayerTreeItem.Subcategory && newItem is PrayerTreeItem.Subcategory -> oldItem.key == newItem.key
                oldItem is PrayerTreeItem.PrayerLeaf && newItem is PrayerTreeItem.PrayerLeaf -> oldItem.key == newItem.key
                else -> false
            }

        override fun areContentsTheSame(oldItem: PrayerTreeItem, newItem: PrayerTreeItem): Boolean =
            oldItem == newItem
    }
}
