package by.dzmitrypanou.catholicapp.ui.scripture

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.ComponentName
import android.content.Context
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.os.bundleOf
import androidx.navigation.NavDeepLinkBuilder
import by.dzmitrypanou.catholicapp.MainActivity
import by.dzmitrypanou.catholicapp.R

object ReadingPlanNotificationHelper {

    const val CHANNEL_ID = "reading_plan_reminders"
    private const val NOTIFICATION_ID = 7101

    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val app = context.applicationContext
        val nm = app.getSystemService(NotificationManager::class.java) ?: return
        if (nm.getNotificationChannel(CHANNEL_ID) != null) return
        val ch = NotificationChannel(
            CHANNEL_ID,
            app.getString(R.string.scripture_reading_plan_channel_name),
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply {
            description = app.getString(R.string.scripture_reading_plan_channel_description)
        }
        nm.createNotificationChannel(ch)
    }

    fun showDailyReminder(context: Context) {
        val app = context.applicationContext
        ensureChannel(app)

        val kind = ScriptureReadingPlanActivationStore.getPlanKind(app)
        val pendingIntent = NavDeepLinkBuilder(app)
            .setComponentName(ComponentName(app, MainActivity::class.java))
            .setGraph(R.navigation.mobile_navigation)
            .setDestination(R.id.nav_scripture_reading_plan)
            .setArguments(
                bundleOf(ScriptureReadingPlanKind.NAV_ARG_PLAN_KIND to kind.storageKey)
            )
            .createTaskStackBuilder()
            .getPendingIntent(
                0,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )

        val notification = NotificationCompat.Builder(app, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_menu_book_24)
            .setContentTitle(app.getString(R.string.scripture_reading_plan_notification_title))
            .setContentText(app.getString(R.string.scripture_reading_plan_notification_text))
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()

        NotificationManagerCompat.from(app).notify(NOTIFICATION_ID, notification)
    }
}
