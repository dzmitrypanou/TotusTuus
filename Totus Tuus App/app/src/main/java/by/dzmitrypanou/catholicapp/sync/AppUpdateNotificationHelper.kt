package by.dzmitrypanou.catholicapp.sync

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import by.dzmitrypanou.catholicapp.R

object AppUpdateNotificationHelper {

    const val CHANNEL_ID = "app_updates"
    private const val NOTIFICATION_ID = 7201
    private const val PACKAGE_NAME = "by.totustuus.app"
    private const val DEFAULT_PLAY_URL = "https://play.google.com/store/apps/details?id=$PACKAGE_NAME"
    private const val DARK_ACCENT = 0xFF1F2440.toInt()
    private const val LIGHT_ACCENT = 0xFFE5D9C6.toInt()

    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val app = context.applicationContext
        val nm = app.getSystemService(NotificationManager::class.java) ?: return
        if (nm.getNotificationChannel(CHANNEL_ID) != null) return
        val channel = NotificationChannel(
            CHANNEL_ID,
            app.getString(R.string.app_update_channel_name),
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply {
            description = app.getString(R.string.app_update_channel_description)
        }
        nm.createNotificationChannel(channel)
    }

    fun canNotify(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(
                context.applicationContext,
                Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED

    fun showUpdateAvailable(
        context: Context,
        versionName: String,
        playStoreUrl: String,
        message: String
    ) {
        val app = context.applicationContext
        if (!AppUpdateCheckStore.isEnabled(app)) return
        if (!canNotify(app)) return
        ensureChannel(app)

        val pendingIntent = PendingIntent.getActivity(
            app,
            0,
            marketIntent(playStoreUrl),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val title = app.getString(R.string.app_update_notification_title)
        val text = message.ifBlank {
            app.getString(R.string.app_update_notification_text, versionName)
        }
        val notification = NotificationCompat.Builder(app, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_refresh_24)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setColor(notificationAccentColor(app))
            .setColorized(false)
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .addAction(
                R.drawable.ic_refresh_24,
                app.getString(R.string.app_update_notification_action),
                pendingIntent
            )
            .build()

        NotificationManagerCompat.from(app).notify(NOTIFICATION_ID, notification)
    }

    private fun notificationAccentColor(context: Context): Int {
        val nightMode = context.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK
        return if (nightMode == Configuration.UI_MODE_NIGHT_YES) DARK_ACCENT else LIGHT_ACCENT
    }

    private fun marketIntent(playStoreUrl: String): Intent {
        val resolvedUrl = playStoreUrl.ifBlank { DEFAULT_PLAY_URL }
        val marketUrl = if (resolvedUrl.startsWith("market://", ignoreCase = true)) {
            resolvedUrl
        } else {
            "market://details?id=$PACKAGE_NAME"
        }
        return Intent(
            Intent.ACTION_VIEW,
            Uri.parse(marketUrl)
        ).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            putExtra("browser_fallback_url", resolvedUrl)
        }
    }
}
