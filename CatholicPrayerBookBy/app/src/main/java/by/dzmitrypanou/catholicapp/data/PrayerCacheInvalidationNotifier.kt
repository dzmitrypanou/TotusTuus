package by.dzmitrypanou.catholicapp.data

object PrayerCacheInvalidationNotifier {

    @Volatile
    private var pendingReload: Boolean = false

    fun signalCacheCleared() {
        pendingReload = true
    }

fun signalRemotePrayerCacheUpdated() {
        pendingReload = true
    }

    fun consumePendingReload(): Boolean {
        if (!pendingReload) return false
        pendingReload = false
        return true
    }
}
