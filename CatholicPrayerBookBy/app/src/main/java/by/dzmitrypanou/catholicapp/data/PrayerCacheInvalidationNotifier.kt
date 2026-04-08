package by.dzmitrypanou.catholicapp.data

/**
 * Пасля [PrayerRepository.clearCache] або фонавага абнаўлення з сервера — каб экран малітоўніка
 * перачытаў кэш, а не трымаў старыя катэгорыі ў ViewModel.
 */
object PrayerCacheInvalidationNotifier {

    @Volatile
    private var pendingReload: Boolean = false

    fun signalCacheCleared() {
        pendingReload = true
    }

    /** Пасля [PrayerRepository.refreshPrayers] у фоне (WorkManager). */
    fun signalRemotePrayerCacheUpdated() {
        pendingReload = true
    }

    fun consumePendingReload(): Boolean {
        if (!pendingReload) return false
        pendingReload = false
        return true
    }
}
