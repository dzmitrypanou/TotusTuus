package by.dzmitrypanou.catholicapp.ui.songbook

/** Экраны спеўніка з кнопкамі ў кастомным action layout шапкі. */
interface SongbookToolbarActions {
    fun refreshSongbookDataFromToolbar()
    fun isSongbookDataSyncInProgress(): Boolean = false
}
