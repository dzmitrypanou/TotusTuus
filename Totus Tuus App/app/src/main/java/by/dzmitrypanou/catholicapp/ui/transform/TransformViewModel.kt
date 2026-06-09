package by.dzmitrypanou.catholicapp.ui.transform

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.viewModelScope
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import kotlinx.coroutines.launch
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

class TransformViewModel(application: Application) : AndroidViewModel(application) {

    private val repository = PrayerRepository(application)
    private val app = application

    private val _uiState = MutableLiveData(PrayerUiState())
    val uiState: LiveData<PrayerUiState> = _uiState

    init {
        loadPrayers()
    }

    fun loadPrayers(forceRefresh: Boolean = false) {
        val cachedCategoriesBeforeSync = repository.getCategoryNames()
        val hasCache = cachedCategoriesBeforeSync.isNotEmpty()
        val silentAutoSync = !forceRefresh && hasCache

_uiState.value = _uiState.value?.copy(
            isLoading = !hasCache,
            isSyncingInToolbar = true,
            categories = if (hasCache) cachedCategoriesBeforeSync else (_uiState.value?.categories ?: emptyList()),
            bannerMessage = if (silentAutoSync) _uiState.value?.bannerMessage else null,
            centeredEmptyMessage = if (silentAutoSync) _uiState.value?.centeredEmptyMessage else null
        )

        viewModelScope.launch {
            runCatching {
                repository.getPrayers(forceRefresh)
                repository.getCategoryNames()
            }.onSuccess { categories ->
                val emptyMessage = if (categories.isEmpty()) {
                    if (forceRefresh) {
                        app.getString(R.string.transform_prayers_empty_after_load)
                    } else {
                        app.getString(R.string.transform_no_prayers_need_network)
                    }
                } else {
                    null
                }
                _uiState.postValue(
                    PrayerUiState(
                        isLoading = false,
                        isSyncingInToolbar = false,
                        categories = categories,
                        bannerMessage = null,
                        centeredEmptyMessage = emptyMessage
                    )
                )
            }.onFailure { error ->
                val cachedCategories = repository.getCategoryNames()
                if (cachedCategories.isEmpty()) {
                    _uiState.postValue(
                        PrayerUiState(
                            isLoading = false,
                            isSyncingInToolbar = false,
                            categories = emptyList(),
                            bannerMessage = null,
                            centeredEmptyMessage = app.getString(R.string.transform_no_prayers_need_network)
                        )
                    )
                } else {
                    _uiState.postValue(
                        PrayerUiState(
                            isLoading = false,
                            isSyncingInToolbar = false,
                            categories = cachedCategories,
                            bannerMessage = app.getString(
                                R.string.transform_offline_cached_banner,
                                formatLoadError(error)
                            ),
                            centeredEmptyMessage = null
                        )
                    )
                }
            }
        }
    }

    private fun formatLoadError(error: Throwable): String {
        val rootCause = generateSequence(error) { it.cause }.last()
        return when (rootCause) {
            is SocketTimeoutException -> app.getString(R.string.transform_error_timeout)
            is UnknownHostException,
            is ConnectException -> app.getString(R.string.transform_error_no_network)
            else -> rootCause.localizedMessage ?: app.getString(R.string.transform_error_network_generic)
        }
    }
}

data class PrayerUiState(
    val isLoading: Boolean = false,
    val isSyncingInToolbar: Boolean = false,
    val categories: List<String> = emptyList(),

    val bannerMessage: String? = null,

    val centeredEmptyMessage: String? = null
)
