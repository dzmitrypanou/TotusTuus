package by.dzmitrypanou.catholicapp.ui.ordomissae

import android.annotation.SuppressLint
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.webkit.JavascriptInterface
import by.dzmitrypanou.catholicapp.data.OrdoMissaeFoldStore

@SuppressLint("JavascriptInterface")
class OrdoFoldJsBridge(
    private val appContext: Context,
    private val htmlSupplier: () -> String,
) {

@JavascriptInterface
    fun save(sectionKey: String, isOpen: String) {
        Handler(Looper.getMainLooper()).post {
            val sk = sectionKey.trim()
            if (sk.isEmpty()) return@post
            val flag = isOpen.trim()
            val open = flag == "1" || flag.equals("true", ignoreCase = true)
            OrdoMissaeFoldStore.saveSectionOpen(
                appContext,
                htmlSupplier.invoke(),
                sk,
                open,
            )
        }
    }
}
