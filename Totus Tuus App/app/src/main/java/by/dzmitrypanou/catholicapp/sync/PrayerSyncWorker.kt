package by.dzmitrypanou.catholicapp.sync

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import by.dzmitrypanou.catholicapp.data.OrdoMissaeRepository
import by.dzmitrypanou.catholicapp.data.PrayerAutoUpdateConsentStore
import by.dzmitrypanou.catholicapp.data.PrayerCacheInvalidationNotifier
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.data.SongbookRepository

class PrayerSyncWorker(
    appContext: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(appContext, workerParams) {

    override suspend fun doWork(): Result {
        if (!PrayerAutoUpdateConsentStore.isGranted(applicationContext)) {
            return Result.success()
        }
        return runCatching {
            val repository = PrayerRepository(applicationContext)
            val before = repository.getCachedPrayers()
            repository.refreshPrayers(before, allowHashShortCircuit = false)
            PrayerCacheInvalidationNotifier.signalRemotePrayerCacheUpdated()
            syncSongCatalog(SongbookRepository.Catalog.SONGBOOK)
            syncSongCatalog(SongbookRepository.Catalog.KANTARAL)

            ScriptureRemoteSync.downloadIfChanged(applicationContext)
            runCatching { OrdoMissaeRepository(applicationContext).syncFromRemote() }
        }.fold(
            onSuccess = { Result.success() },
            onFailure = { Result.retry() }
        )
    }

    private suspend fun syncSongCatalog(catalog: SongbookRepository.Catalog) {
        val repository = SongbookRepository(applicationContext, catalog)
        repository.refreshFromApi(
            existingLocal = repository.getCachedEntries(),
            allowHashShortCircuit = true,
            allowNetwork = true
        )
    }
}
