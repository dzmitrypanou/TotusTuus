package by.dzmitrypanou.catholicapp.ui.songbook

interface SongbookToolbarActions {
    fun refreshSongbookDataFromToolbar()
    fun isSongbookDataSyncInProgress(): Boolean = false
    fun showSongbookToolbarSyncProgress(): Boolean = true
}
