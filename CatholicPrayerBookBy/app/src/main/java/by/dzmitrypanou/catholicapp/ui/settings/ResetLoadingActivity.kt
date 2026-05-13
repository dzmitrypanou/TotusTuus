package by.dzmitrypanou.catholicapp.ui.settings

import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.os.Bundle
import android.os.SystemClock
import android.view.View
import android.widget.ImageView
import android.widget.ProgressBar
import android.widget.TextView
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.lifecycle.lifecycleScope
import by.dzmitrypanou.catholicapp.MainActivity
import by.dzmitrypanou.catholicapp.MainLightActivity
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.AppColorSchemeStore
import by.dzmitrypanou.catholicapp.data.OrdoMissaeRepository
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.data.SongbookRepository
import by.dzmitrypanou.catholicapp.sync.ScriptureRemoteSync
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureTranslationStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

open class ResetLoadingActivity : AppCompatActivity() {
    private lateinit var root: View
    private lateinit var imageLogo: ImageView
    private lateinit var progressBar: ProgressBar
    private lateinit var textTitle: TextView
    private lateinit var textPercent: TextView
    private lateinit var textStatus: TextView
    private lateinit var textQuote: TextView
    private lateinit var textQuoteAuthor: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        val scheme = AppColorSchemeStore.readScheme(this)
        setTheme(scheme.themeResId)
        super.onCreate(savedInstanceState)
        val lightBars = scheme.prefersLightSystemBars
        enableEdgeToEdge(
            statusBarStyle = if (lightBars) {
                SystemBarStyle.light(Color.TRANSPARENT, Color.TRANSPARENT)
            } else {
                SystemBarStyle.dark(Color.TRANSPARENT)
            },
            navigationBarStyle = if (lightBars) {
                SystemBarStyle.light(Color.TRANSPARENT, Color.TRANSPARENT)
            } else {
                SystemBarStyle.dark(Color.TRANSPARENT)
            },
        )
        setContentView(R.layout.activity_reset_loading)
        root = findViewById(R.id.reset_loading_root)
        ViewCompat.setOnApplyWindowInsetsListener(root) { v, windowInsets ->
            val bars = windowInsets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout(),
            )
            v.updatePadding(bars.left, bars.top, bars.right, bars.bottom)
            windowInsets
        }
        ViewCompat.requestApplyInsets(root)
        imageLogo = findViewById(R.id.image_reset_loading_logo)
        progressBar = findViewById(R.id.progress_reset_loading)
        textTitle = findViewById(R.id.text_reset_loading_title)
        textPercent = findViewById(R.id.text_reset_loading_percent)
        textStatus = findViewById(R.id.text_reset_loading_status)
        textQuote = findViewById(R.id.text_reset_loading_quote_text)
        textQuoteAuthor = findViewById(R.id.text_reset_loading_quote_author)
        applyPalette(scheme)
        runLoading()
    }

    private fun applyPalette(scheme: AppColorSchemeStore.Scheme) {
        val isLight = scheme == AppColorSchemeStore.Scheme.LIGHT
        root.setBackgroundResource(
            if (isLight) R.drawable.bg_gradient_loading_light else R.drawable.bg_gradient_dark,
        )
        imageLogo.setImageResource(
            if (isLight) R.drawable.logo_brand_cross_light else R.drawable.logo_brand_cross,
        )

        val primaryText = Color.parseColor(if (isLight) "#2B2117" else "#FFFFFFFF")
        val secondaryText = Color.parseColor(if (isLight) "#5E4A34" else "#B8C0D9")
        val progressTrack = Color.parseColor(if (isLight) "#C6B39A" else "#39415E")

        textTitle.setTextColor(primaryText)
        textPercent.setTextColor(primaryText)
        textStatus.setTextColor(secondaryText)
        textQuote.setTextColor(secondaryText)
        textQuoteAuthor.setTextColor(secondaryText)
        progressBar.progressTintList = ColorStateList.valueOf(secondaryText)
        progressBar.progressBackgroundTintList = ColorStateList.valueOf(progressTrack)
    }

    private fun runLoading() {
        lifecycleScope.launch {
            val startedAt = SystemClock.elapsedRealtime()
            updateProgress(0, R.string.reset_loading_status_prepare)
            withContext(Dispatchers.IO) {
                val prayerRepo = PrayerRepository(applicationContext)
                runCatching {
                    prayerRepo.refreshPrayers(
                        existingLocal = prayerRepo.getCachedPrayers(),
                        allowHashShortCircuit = true
                    )
                }
            }
            updateProgress(25, R.string.reset_loading_status_prayers)

            withContext(Dispatchers.IO) {
                val songbookRepo = SongbookRepository(applicationContext)
                runCatching {
                    songbookRepo.refreshFromApi(
                        existingLocal = songbookRepo.getCachedEntries(),
                        allowHashShortCircuit = true,
                        allowNetwork = true,
                        onProgress = songbookProgress@{ p ->
                            if (isDestroyed) return@songbookProgress
                            val start = 25
                            val span = 30
                            val pct =
                                start + if (p.total <= 0) span else span * p.done / p.total
                            runOnUiThread {
                                if (isDestroyed) return@runOnUiThread
                                val clamped = pct.coerceIn(start, start + span)
                                progressBar.progress = clamped
                                textPercent.text =
                                    getString(R.string.reset_loading_percent, clamped)
                                textStatus.setText(R.string.reset_loading_status_songbook)
                            }
                        }
                    )
                }
            }
            updateProgress(55, R.string.reset_loading_status_songbook)

            withContext(Dispatchers.IO) {
                val trId = ScriptureTranslationStore.getSelectedTranslationId(applicationContext)
                runCatching {
                    ScriptureRemoteSync.refreshTranslation(
                        applicationContext,
                        trId,
                        forceRefresh = true
                    )
                }
                runCatching { OrdoMissaeRepository(applicationContext).syncFromRemote() }
            }
            updateProgress(100, R.string.reset_loading_status_done)

            val elapsed = SystemClock.elapsedRealtime() - startedAt
            val targetDurationMs = 1000L
            if (elapsed < targetDurationMs) {
                delay(targetDurationMs - elapsed)
            }
            openMain()
        }
    }

    private fun updateProgress(percent: Int, statusRes: Int) {
        progressBar.progress = percent
        textPercent.text = getString(R.string.reset_loading_percent, percent)
        textStatus.text = getString(statusRes)
    }

    private fun openMain() {
        val scheme = AppColorSchemeStore.readScheme(this)
        val mainActivityClass = if (scheme == AppColorSchemeStore.Scheme.LIGHT) {
            MainLightActivity::class.java
        } else {
            MainActivity::class.java
        }
        val intent = Intent(this, mainActivityClass).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
            putExtra(MainActivity.EXTRA_OPEN_PRAYER_BOOK_REFRESH, false)
        }
        startActivity(intent)
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out)
        finish()
    }
}

class ResetLoadingDarkActivity : ResetLoadingActivity()

class ResetLoadingLightActivity : ResetLoadingActivity()
