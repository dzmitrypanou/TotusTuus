package by.dzmitrypanou.catholicapp.sync

import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import by.dzmitrypanou.catholicapp.data.PrayerAutoUpdateConsentStore
import java.util.concurrent.TimeUnit

object SyncScheduler {

    private const val PERIODIC_SYNC_WORK = "prayer_periodic_sync"
    private const val INITIAL_SYNC_WORK = "prayer_initial_sync"
    private const val ONE_SHOT_CHECK = "prayer_update_check_once"

fun applyConsent(context: Context) {
        val app = context.applicationContext
        val wm = WorkManager.getInstance(app)
        if (!PrayerAutoUpdateConsentStore.isGranted(app)) {
            wm.cancelUniqueWork(PERIODIC_SYNC_WORK)
            wm.cancelUniqueWork(INITIAL_SYNC_WORK)
            wm.cancelUniqueWork(ONE_SHOT_CHECK)
            return
        }

        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val periodicSync = PeriodicWorkRequestBuilder<PrayerSyncWorker>(6, TimeUnit.HOURS)
            .setConstraints(constraints)
            .build()
        wm.enqueueUniquePeriodicWork(
            PERIODIC_SYNC_WORK,
            ExistingPeriodicWorkPolicy.UPDATE,
            periodicSync
        )

        val once = OneTimeWorkRequestBuilder<PrayerSyncWorker>()
            .setConstraints(constraints)
            .build()
        wm.enqueueUniqueWork(
            ONE_SHOT_CHECK,
            ExistingWorkPolicy.REPLACE,
            once
        )
    }
}
