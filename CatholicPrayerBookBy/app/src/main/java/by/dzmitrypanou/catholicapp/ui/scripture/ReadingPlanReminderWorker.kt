package by.dzmitrypanou.catholicapp.ui.scripture

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters

class ReadingPlanReminderWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext
        if (!ScriptureReadingPlanActivationStore.shouldScheduleReminders(app)) {
            return Result.success()
        }
        ReadingPlanNotificationHelper.showDailyReminder(app)
        return Result.success()
    }
}
