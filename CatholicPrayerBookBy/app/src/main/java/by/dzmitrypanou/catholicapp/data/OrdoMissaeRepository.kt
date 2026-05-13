package by.dzmitrypanou.catholicapp.data

import android.content.Context
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

class OrdoMissaeRepository(context: Context) {

    private val appContext = context.applicationContext
    private val store = OrdoMissaeCacheStore(appContext)

    fun getCachedHtml(): String = store.readHtml()

    sealed class SyncOutcome {
        data object Unchanged : SyncOutcome()

        data class Updated(val html: String) : SyncOutcome()

data class Failed(val hadLocalCache: Boolean) : SyncOutcome()
    }

suspend fun syncFromRemote(): SyncOutcome = withContext(Dispatchers.IO) {
        val localHtml = store.readHtml()
        val localAt = store.readUpdatedAt()
        val remoteAt = runCatching {
            PrayerApiClient.service.getOrdoMissaeVersion().updatedAt?.trim().orEmpty()
        }.getOrElse { "" }

        if (localHtml.isNotBlank() && remoteAt.isNotEmpty() && remoteAt == localAt) {
            return@withContext SyncOutcome.Unchanged
        }

        val dto = runCatching {
            PrayerApiClient.service.getOrdoMissae()
        }.getOrNull() ?: return@withContext SyncOutcome.Failed(localHtml.isNotBlank())

        val html = dto.html.orEmpty()
        val at = dto.updatedAt?.trim().orEmpty().ifBlank { remoteAt }
        store.write(html, at)
        SyncOutcome.Updated(html)
    }
}
