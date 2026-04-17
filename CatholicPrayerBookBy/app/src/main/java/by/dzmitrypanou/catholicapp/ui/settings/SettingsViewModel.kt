package by.dzmitrypanou.catholicapp.ui.settings

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.OrdoMissaeCacheStore
import by.dzmitrypanou.catholicapp.data.OrdoMissaeFoldStore
import by.dzmitrypanou.catholicapp.data.PrayerCacheInvalidationNotifier
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.data.SongbookCacheInvalidationNotifier
import by.dzmitrypanou.catholicapp.data.SongbookRepository
import by.dzmitrypanou.catholicapp.sync.ScriptureRemoteSync

class SettingsViewModel(application: Application) : AndroidViewModel(application) {

    private val repository = PrayerRepository(application)
    private val _message = MutableLiveData(
        application.getString(R.string.settings_data_hint)
    )
    val message: LiveData<String> = _message

    fun clearLocalData() {
        repository.clearCache()
        PrayerCacheInvalidationNotifier.signalCacheCleared()
        SongbookRepository(getApplication()).clearCache()
        SongbookCacheInvalidationNotifier.signalCacheCleared()
        ScriptureRemoteSync.clearCache(getApplication())
        OrdoMissaeCacheStore(getApplication()).clear()
        OrdoMissaeFoldStore.clear(getApplication())
        _message.value = getApplication<Application>().getString(R.string.settings_data_cleared)
    }
}