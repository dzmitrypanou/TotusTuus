package by.dzmitrypanou.catholicapp

import android.app.Application
import by.dzmitrypanou.catholicapp.sync.SyncScheduler
import by.dzmitrypanou.catholicapp.ui.liturgy.LiturgyCalendarRepository
import by.dzmitrypanou.catholicapp.ui.scripture.ReadingPlanNotificationHelper
import by.dzmitrypanou.catholicapp.ui.scripture.ReadingPlanReminderScheduler
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureReadingPlanActivationStore
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

class PrayerBookApp : Application() {
    private val appScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onCreate() {
        super.onCreate()
        ReadingPlanNotificationHelper.ensureChannel(this)
        SyncScheduler.applyConsent(applicationContext)
        if (ScriptureReadingPlanActivationStore.shouldScheduleReminders(this)) {
            ReadingPlanReminderScheduler.schedule(this)
        }
        appScope.launch {
            LiturgyCalendarRepository.prefetchCurrentMonth(applicationContext)
        }
        by.dzmitrypanou.catholicapp.data.AppColorSchemeStore.syncLauncherIconIfPending(applicationContext)
    }
}
