package by.dzmitrypanou.catholicapp.sync

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import by.dzmitrypanou.catholicapp.BuildConfig
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient

class AppUpdateCheckWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext
        if (!AppUpdateCheckStore.isEnabled(app)) return Result.success()
        val dto = runCatching { PrayerApiClient.service.getAppVersion() }
            .getOrElse { return Result.retry() }
        val latestCode = dto.versionCode ?: return Result.success()
        if (latestCode <= BuildConfig.VERSION_CODE) return Result.success()
        if (AppUpdateCheckStore.lastNotifiedVersionCode(app) >= latestCode) return Result.success()
        if (!AppUpdateNotificationHelper.canNotify(app)) return Result.success()

        val latestName = dto.versionName.orEmpty().ifBlank { latestCode.toString() }
        AppUpdateNotificationHelper.showUpdateAvailable(
            context = app,
            versionName = latestName,
            playStoreUrl = dto.playStoreUrl.orEmpty(),
            message = dto.message.orEmpty()
        )
        AppUpdateCheckStore.markNotified(app, latestCode)
        return Result.success()
    }
}
