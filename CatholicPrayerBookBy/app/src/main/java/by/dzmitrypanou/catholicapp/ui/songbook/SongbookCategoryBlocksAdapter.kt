package by.dzmitrypanou.catholicapp.ui.songbook

import android.content.Context
import android.graphics.Rect
import android.graphics.Typeface
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.SongbookEntry
import by.dzmitrypanou.catholicapp.databinding.ItemPrayerTreeBinding
import by.dzmitrypanou.catholicapp.databinding.ItemSongbookCategoryBlockBinding
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography

class SongbookCategoryBlocksAdapter(
    private val appContext: Context,
    private val onSongClick: (SongbookEntry, displayCategory: String) -> Unit
) : ListAdapter<SongbookCategorySection, SongbookCategoryBlocksAdapter.BlockViewHolder>(SectionDiff) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): BlockViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        val binding = ItemSongbookCategoryBlockBinding.inflate(inflater, parent, false)
        return BlockViewHolder(binding)
    }

    override fun onBindViewHolder(holder: BlockViewHolder, position: Int) {
        holder.bind(getItem(position))
    }

    inner class BlockViewHolder(
        private val binding: ItemSongbookCategoryBlockBinding
    ) : RecyclerView.ViewHolder(binding.root) {

        private var section: SongbookCategorySection? = null

        init {
            binding.layoutSongbookCategoryHeader.setOnClickListener {
                val s = section ?: return@setOnClickListener
                val expanded = SongbookCategoryExpandStore.isExpanded(appContext, s.groupKey, defaultExpanded = false)
                val nextExpanded = !expanded
                SongbookCategoryExpandStore.setExpanded(appContext, s.groupKey, nextExpanded)
                bindExpandedState(s, expanded = nextExpanded)
            }
        }

        fun bind(s: SongbookCategorySection) {
            section = s
            val ctx = binding.root.context
            binding.textSongbookCategoryTitle.text = s.displayTitle
            PrayerBookUiTypography.applyUiSp(
                binding.textSongbookCategoryTitle,
                R.dimen.text_songbook_category_header,
                ctx
            )
            binding.textSongbookCategoryTitle.setTypeface(binding.textSongbookCategoryTitle.typeface, Typeface.BOLD)

            val expanded = SongbookCategoryExpandStore.isExpanded(appContext, s.groupKey, defaultExpanded = false)
            bindExpandedState(s, expanded)
        }

        private fun bindExpandedState(
            s: SongbookCategorySection,
            expanded: Boolean
        ) {
            val ctx = binding.root.context
            binding.imageSongbookCategoryExpand.setImageResource(
                if (expanded) R.drawable.ic_expand_less_24 else R.drawable.ic_expand_more_24
            )
            binding.layoutSongbookCategoryHeader.contentDescription =
                if (expanded) {
                    ctx.getString(R.string.songbook_category_collapse_list_a11y, s.displayTitle)
                } else {
                    ctx.getString(R.string.songbook_category_expand_list_a11y, s.displayTitle)
                }

            val songList = if (expanded) s.entries else emptyList()
            if (!expanded) {
                binding.recyclerSongbookSectionSongs.visibility = View.GONE
                binding.recyclerSongbookSectionSongs.removeAllViews()
                return
            }

            bindSongRows(songList, s.displayTitle)
            binding.recyclerSongbookSectionSongs.visibility = View.VISIBLE
        }

        private fun bindSongRows(entries: List<SongbookEntry>, displayCategory: String) {
            val container = binding.recyclerSongbookSectionSongs
            container.removeAllViews()
            val inflater = LayoutInflater.from(container.context)
            entries.forEach { entry ->
                val rowBinding = ItemPrayerTreeBinding.inflate(inflater, container, false)
                rowBinding.textTreeTitle.text = entry.listLabel()
                rowBinding.textTreeSubtitle.visibility = View.GONE
                rowBinding.root.setOnClickListener { onSongClick(entry, displayCategory) }
                PrayerBookUiTypography.bindSongbookTreeRow(
                    rowBinding,
                    entry,
                    rowBinding.root.context,
                    showImageBadge = entry.showBadge != false
                )
                PrayerBookUiTypography.applyPrayerTreeRowTypography(rowBinding, rowBinding.root.context)
                container.addView(rowBinding.root)
            }
        }
    }

    private object SectionDiff : DiffUtil.ItemCallback<SongbookCategorySection>() {
        override fun areItemsTheSame(a: SongbookCategorySection, b: SongbookCategorySection): Boolean =
            a.groupKey == b.groupKey

        override fun areContentsTheSame(a: SongbookCategorySection, b: SongbookCategorySection): Boolean =
            a == b
    }
}

class SongbookCategoryBlocksTopSpacingDecoration(
    private val gapPx: Int
) : RecyclerView.ItemDecoration() {
    override fun getItemOffsets(outRect: Rect, view: View, parent: RecyclerView, state: RecyclerView.State) {
        val pos = parent.getChildAdapterPosition(view)
        if (pos > 0) {
            outRect.top = gapPx
        }
    }
}
