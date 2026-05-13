package by.dzmitrypanou.catholicapp

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import by.dzmitrypanou.catholicapp.ui.settings.ResetLoadingActivity

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
