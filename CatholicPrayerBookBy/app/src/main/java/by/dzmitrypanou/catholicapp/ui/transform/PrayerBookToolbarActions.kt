package by.dzmitrypanou.catholicapp.ui.transform

/** Экраны малітоўніка з кнопкамі ў кастомным action layout шапкі. */
interface PrayerBookToolbarActions {
    fun refreshPrayerDataFromToolbar()
    fun isPrayerDataSyncInProgress(): Boolean = false
}
