package by.dzmitrypanou.catholicapp.ui.songbook

import android.content.Context
import android.graphics.Rect
import android.graphics.Typeface
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.LinearLayoutManager
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

        private val songsAdapter = SongbookSectionRowsAdapter(
            onSongClick = { entry ->
                val cat = section?.displayTitle ?: return@SongbookSectionRowsAdapter
                onSongClick(entry, cat)
            }
        )

        init {
            binding.recyclerSongbookSectionSongs.apply {
                layoutManager = LinearLayoutManager(context).apply { isAutoMeasureEnabled = true }
                adapter = songsAdapter
                isNestedScrollingEnabled = false
                setHasFixedSize(false)
                itemAnimator = null
            }
            binding.layoutSongbookCategoryHeader.setOnClickListener {
                val pos = bindingAdapterPosition
                if (pos == RecyclerView.NO_POSITION) return@setOnClickListener
                val s = section ?: return@setOnClickListener
                val expanded = SongbookCategoryExpandStore.isExpanded(appContext, s.groupKey, defaultExpanded = true)
                SongbookCategoryExpandStore.setExpanded(appContext, s.groupKey, !expanded)
                this@SongbookCategoryBlocksAdapter.notifyItemChanged(pos)
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

            val expanded = SongbookCategoryExpandStore.isExpanded(appContext, s.groupKey, defaultExpanded = true)
            binding.recyclerSongbookSectionSongs.visibility = if (expanded) View.VISIBLE else View.GONE
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
            songsAdapter.submitList(songList)
            if (expanded && songList.isNotEmpty()) {
                binding.recyclerSongbookSectionSongs.post {
                    val n = songsAdapter.itemCount
                    if (n > 0) songsAdapter.notifyItemRangeChanged(0, n)
                }
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

private class SongbookSectionRowsAdapter(
    private val onSongClick: (SongbookEntry) -> Unit
) : ListAdapter<SongbookEntry, SongbookSectionRowsAdapter.RowViewHolder>(EntryDiff) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RowViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        val rowBinding = ItemPrayerTreeBinding.inflate(inflater, parent, false)
        return RowViewHolder(rowBinding, onSongClick)
    }

    override fun onBindViewHolder(holder: RowViewHolder, position: Int) {
        holder.bind(getItem(position))
    }

    class RowViewHolder(
        private val binding: ItemPrayerTreeBinding,
        private val onSongClick: (SongbookEntry) -> Unit
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(entry: SongbookEntry) {
            binding.textTreeTitle.text = entry.listLabel()
            binding.textTreeSubtitle.visibility = View.GONE
            binding.root.setOnClickListener { onSongClick(entry) }
            PrayerBookUiTypography.bindSongbookTreeRow(binding, entry, binding.root.context)
            PrayerBookUiTypography.applyPrayerTreeRowTypography(binding, binding.root.context)
        }
    }

    private object EntryDiff : DiffUtil.ItemCallback<SongbookEntry>() {
        override fun areItemsTheSame(a: SongbookEntry, b: SongbookEntry): Boolean = a.id == b.id
        override fun areContentsTheSame(a: SongbookEntry, b: SongbookEntry): Boolean = a == b
    }
}

/** Вертыкальная адступ паміж карткамі катэгорый (першы блок без адступу зверху). */
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
