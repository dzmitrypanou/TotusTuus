package by.dzmitrypanou.catholicapp.ui

import android.view.View
import androidx.fragment.app.FragmentActivity
import com.google.android.material.button.MaterialButton
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.AppGlobalTextScaleStore

/** Кнопкі А± у шапцы на экранах чытання (малітва, спеўнік, Пісанне, лекцыянарый). */
object ReadingTextScaleToolbar {
    private const val MIN_STEP = 0
    private const val MAX_STEP = 4

    fun bind(root: View, activity: FragmentActivity, onAfterChange: () -> Unit) {
        val smaller = root.findViewById<MaterialButton>(R.id.button_reading_text_smaller) ?: return
        val larger = root.findViewById<MaterialButton>(R.id.button_reading_text_larger) ?: return
        smaller.visibility = View.VISIBLE
        larger.visibility = View.VISIBLE
        fun sync() {
            val step = AppGlobalTextScaleStore.readStepIndex(activity)
            smaller.isEnabled = step > MIN_STEP
            larger.isEnabled = step < MAX_STEP
        }
        sync()
        smaller.setOnClickListener {
            AppGlobalTextScaleStore.adjust(activity, -1f)
            sync()
            onAfterChange()
            activity.invalidateOptionsMenu()
        }
        larger.setOnClickListener {
            AppGlobalTextScaleStore.adjust(activity, 1f)
            sync()
            onAfterChange()
            activity.invalidateOptionsMenu()
        }
    }
}
