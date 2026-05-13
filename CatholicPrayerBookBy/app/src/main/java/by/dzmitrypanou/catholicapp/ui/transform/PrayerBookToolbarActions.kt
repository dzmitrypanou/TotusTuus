package by.dzmitrypanou.catholicapp.ui.transform

interface PrayerBookToolbarActions {
    fun refreshPrayerDataFromToolbar()
    fun isPrayerDataSyncInProgress(): Boolean = false
}
