package by.dzmitrypanou.catholicapp

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import by.dzmitrypanou.catholicapp.ui.settings.ResetLoadingActivity

/**
 * Entry з ярлыка: [Activity] + [android.R.style.Theme_Translucent_NoTitleBar] (без AppCompat — інакш краш у onCreate).
 */
abstract class LauncherEntryActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val next = Intent(this, ResetLoadingActivity::class.java)
        intent.extras?.let { next.putExtras(it) }
        startActivity(next)
        finish()
        @Suppress("DEPRECATION")
        overridePendingTransition(0, 0)
    }
}

class LauncherDark : LauncherEntryActivity()

class LauncherLight : LauncherEntryActivity()
