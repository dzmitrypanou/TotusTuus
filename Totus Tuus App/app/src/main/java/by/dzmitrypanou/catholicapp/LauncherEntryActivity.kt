package by.dzmitrypanou.catholicapp

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import by.dzmitrypanou.catholicapp.data.AppColorSchemeStore
import by.dzmitrypanou.catholicapp.ui.settings.ResetLoadingActivity
import by.dzmitrypanou.catholicapp.ui.settings.ResetLoadingDarkActivity
import by.dzmitrypanou.catholicapp.ui.settings.ResetLoadingLightActivity

abstract class LauncherEntryActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val loadingActivityClass = when (AppColorSchemeStore.readScheme(this)) {
            AppColorSchemeStore.Scheme.LIGHT -> ResetLoadingLightActivity::class.java
            AppColorSchemeStore.Scheme.DARK -> ResetLoadingDarkActivity::class.java
        }
        val next = Intent(this, loadingActivityClass)
        intent.extras?.let { next.putExtras(it) }
        startActivity(next)
        finish()
        @Suppress("DEPRECATION")
        overridePendingTransition(0, 0)
    }
}

class LauncherDark : LauncherEntryActivity()

class LauncherLight : LauncherEntryActivity()
