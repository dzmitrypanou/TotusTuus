package by.dzmitrypanou.catholicapp.ui.ordomissae

import android.annotation.SuppressLint
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.webkit.JavascriptInterface
import by.dzmitrypanou.catholicapp.data.OrdoMissaeFoldStore

/**
 * Публічны клас для [android.webkit.WebView.addJavascriptInterface]: прыватныя ўкладзеныя класы
 * не заўсёды бачныя з JavaScript, з-за чаго не захоўваецца стан раскрыцця секцый (details).
 */
@SuppressLint("JavascriptInterface")
class OrdoFoldJsBridge(
    private val appContext: Context,
    private val htmlSupplier: () -> String,
) {
    /**
     * Другі аргумент — радок "1"/"0": WebView часам ненадзейна перадае boolean у @JavascriptInterface.
     */
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
