package by.dzmitrypanou.catholicapp.ui.ordomissae

import android.content.Context
import android.content.res.Configuration
import android.graphics.Color
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.annotation.ColorInt
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.AppFontFamilyStore
import by.dzmitrypanou.catholicapp.data.OrdoMissaeFoldStore
import by.dzmitrypanou.catholicapp.data.OrdoMissaeRepository
import by.dzmitrypanou.catholicapp.data.PrayerBodyTextSizeStore
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import by.dzmitrypanou.catholicapp.databinding.FragmentOrdoMissaeBinding
import by.dzmitrypanou.catholicapp.ui.ReadingTextScaleToolbar
import by.dzmitrypanou.catholicapp.ui.themeColor
import kotlin.math.abs
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.Locale
import org.json.JSONObject

class OrdoMissaeFragment : Fragment() {

    private var _binding: FragmentOrdoMissaeBinding? = null
    private val binding get() = _binding!!

    private var lastLoadedBodyPx: Float = Float.NaN
    private var bodyRaw: String = ""
    private var foldBridge: OrdoFoldJsBridge? = null
    /** Для перазагрузкі WebView пасля пераключэння светлай/цёмнай тэмы. */
    private var lastOrdoThemeSignature: Int? = null

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View {
        _binding = FragmentOrdoMissaeBinding.inflate(inflater, container, false)
        setupWebView()
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        // Лакальны кэш — адразу, як малітоўнік; сетка толькі калі змяніўся updated_at на серверы.
        bodyRaw = OrdoMissaeRepository(requireContext().applicationContext).getCachedHtml()
        reloadWebContent()
        loadFromNetwork()
    }

    override fun onResume() {
        super.onResume()
        val ctx = context ?: return
        val themeSig = ordoThemeSignature(ctx)
        if (lastLoadedBodyPx.isFinite() && lastOrdoThemeSignature != null && themeSig != lastOrdoThemeSignature) {
            reloadWebContent()
        }
        lastOrdoThemeSignature = themeSig
        val px = PrayerBodyTextSizeStore.readPx(ctx, resources)
        // Толькі калі ўжо былі паказаны даныя: інакш NaN дацягне перазагрузку з пустым bodyRaw да адказу сеткі → «прыганне» тэксту.
        if (lastLoadedBodyPx.isFinite() && abs(px - lastLoadedBodyPx) > 0.5f) {
            applyOrdoBodyFontToWebView(px)
        }
        requireActivity().invalidateOptionsMenu()
    }

    private fun ordoThemeSignature(ctx: Context): Int {
        val night = ctx.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK
        val text = ctx.themeColor(R.attr.totusColorTextPrimary)
        return night xor (text * 31)
    }

    private fun setupWebView() {
        val w = binding.webOrdoBody
        w.setBackgroundColor(Color.TRANSPARENT)
        w.isNestedScrollingEnabled = false
        w.isVerticalScrollBarEnabled = false
        w.isHorizontalScrollBarEnabled = false
        w.settings.apply {
            // Лакальны HTML Ordo: патрэбна для надзейнага раскрыцця <details> (у WebView часта зламана без JS).
            javaScriptEnabled = true
            domStorageEnabled = true
            builtInZoomControls = false
            displayZoomControls = false
            setSupportZoom(false)
            loadsImagesAutomatically = true
            blockNetworkImage = false
            // Лакальны HTML з meta viewport: без «overview» менш рывкоў маштабу пры першым малюнку.
            loadWithOverviewMode = false
            useWideViewPort = true
        }
        // Як у PrayerDetailFragment: маштаб ужо ў збудаваным CSS — без onPageFinished і паўторнага JS,
        // каб не было рыўка раскладкі пасля першай адмалёўкі.
        w.webViewClient = WebViewClient()
        foldBridge = OrdoFoldJsBridge(requireContext().applicationContext) { bodyRaw }
        w.addJavascriptInterface(foldBridge!!, "OrdoFold")
    }

    private fun loadFromNetwork() {
        viewLifecycleOwner.lifecycleScope.launch {
            val appCtx = requireContext().applicationContext
            val outcome = withContext(Dispatchers.IO) {
                OrdoMissaeRepository(appCtx).syncFromRemote()
            }
            when (outcome) {
                is OrdoMissaeRepository.SyncOutcome.Unchanged -> Unit
                is OrdoMissaeRepository.SyncOutcome.Updated -> {
                    bodyRaw = outcome.html
                    reloadWebContent()
                }
                is OrdoMissaeRepository.SyncOutcome.Failed -> {
                    if (!outcome.hadLocalCache) {
                        Toast.makeText(
                            requireContext(),
                            getString(R.string.ordo_missae_load_error),
                            Toast.LENGTH_SHORT,
                        ).show()
                    }
                }
            }
        }
    }

    private fun reloadWebContent() {
        if (_binding == null) return
        val ctx = context ?: return
        val innerBase = buildInnerHtml(bodyRaw)
        val openMap = OrdoMissaeFoldStore.initialOpenMap(ctx, bodyRaw)
        val inner = applyOrdoSectionOpenAttributes(innerBase, openMap)
        val fontPx = PrayerBodyTextSizeStore.readPx(ctx, resources)
        val doc = buildHtmlDocument(inner, fontPx, bodyRaw)
        binding.webOrdoBody.loadDataWithBaseURL(
            PrayerApiClient.siteOriginForHtml,
            doc,
            "text/html",
            "UTF-8",
            null,
        )
        lastLoadedBodyPx = fontPx
    }

    fun applyReadingTextScaleFromToolbar() {
        val ctx = context ?: return
        val px = PrayerBodyTextSizeStore.readPx(ctx, resources)
        applyOrdoBodyFontToWebView(px)
    }

    /** Абнавіць памер тэксту без loadData — каб не скідваць пракрутку і не «прыгалі» ўверх. */
    private fun applyOrdoBodyFontToWebView(bodyFontPx: Float) {
        if (_binding == null) return
        val density = resources.displayMetrics.density.coerceAtLeast(0.5f)
        val cssPx = bodyFontPx / density
        val pxStr = String.format(Locale.US, "%.2f", cssPx)
        val js = "document.documentElement.style.setProperty('--ordo-body-font-px','" + pxStr + "px');"
        binding.webOrdoBody.evaluateJavascript(js, null)
        lastLoadedBodyPx = bodyFontPx
    }

    fun bindOrdoMissaeToolbarActions(actionView: View) {
        ReadingTextScaleToolbar.bind(actionView, requireActivity()) {
            applyReadingTextScaleFromToolbar()
        }
    }

    private fun looksLikeHtml(value: String): Boolean =
        Regex("<\\s*/?\\s*[a-zA-Z][^>]*>").containsMatchIn(value)

    private fun stripUnsafe(html: String): String {
        var s = html.replace(Regex("(?is)<script\\b[^>]*>.*?</script>"), "")
        s = s.replace(Regex("(?is)<iframe\\b[^>]*>.*?</iframe>"), "")
        s = s
            .replace(Regex("\\s*bis_skin_checked=\"[^\"]*\""), "")
            .replace(Regex("\\s*bis_skin_checked='[^']*'"), "")
        s = stripInlineFontSizeDeclarations(s)
        s = stripLightEditorTextColorsForOrdo(s)
        s = stripOpenAttributeFromOrdoDetails(s)
        return s.trim()
    }

    /** Кэш/legacy HTML з атрыбутам open — прыбіраем, каб па змаўчанні ўсе секцыі былі згорнутыя. */
    /**
     * Пачатковы атрыбут [open] у HTML — без «спачатку ўсе закрыць, потым адкрыць» у JS (без рыўка раскладкі).
     */
    private fun applyOrdoSectionOpenAttributes(html: String, openMap: Map<String, Boolean>): String =
        Regex("""(?i)<details(\s[^>]*?)>""").replace(html) { m ->
            val attrs = m.groupValues[1]
            if (!attrs.contains("ordo-missae-section", ignoreCase = true)) return@replace m.value
            val keyMatch = Regex("""(?i)data-ordo-section\s*=\s*["']([^"']+)["']""").find(attrs)
            val sectionKey = keyMatch?.groupValues?.getOrNull(1) ?: return@replace m.value
            val wantOpen = openMap[sectionKey] ?: false
            var a = attrs
            a = a.replace(Regex("""(?i)\s+open\s*=\s*(?:"[^"]*"|'[^']*')"""), "")
            a = a.replace(Regex("""(?i)\s+open\b(?=\s|>)"""), "")
            val openAttr = if (wantOpen) " open" else ""
            "<details$openAttr$a>"
        }

    private fun stripOpenAttributeFromOrdoDetails(html: String): String =
        Regex("""(?i)<details\b[^>]*>""").replace(html) { m ->
            val tag = m.value
            if (!tag.contains("ordo-missae-section", ignoreCase = true)) {
                return@replace tag
            }
            var t = tag.replace(Regex("""(?i)\s+open\s*=\s*(?:"[^"]*"|'[^']*')"""), "")
            t = t.replace(Regex("""(?i)\s+open\b(?=\s|>)"""), "")
            t
        }

    /**
     * Толькі вельмі светлыя колеры з рэдактара (white/#fff і г.д.) — каб у светлай тэме тэкст чытаўся.
     * Чырвоныя і іншыя насычаныя колеры ў `style` / `<font color>` не чапаем.
     */
    private fun stripLightEditorTextColorsForOrdo(html: String): String {
        var s = html
        val imp = """(?:\s*!important)?"""
        val styleColorPatterns = listOf(
            Regex("""(?i)\bcolor\s*:\s*#fff\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#ffffff\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#fefefe\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#f0f0f0\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#f4f4f4\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#f5f5f5\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#fafafa\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#eeeeee\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#e8e8e8\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*#e0e0e0\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*white\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*ivory\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*snow\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*ghostwhite\b$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*rgb\s*\(\s*255\s*,\s*255\s*,\s*255\s*\)$imp\s*;?"""),
            Regex("""(?i)\bcolor\s*:\s*rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*[\d.]+\s*\)$imp\s*;?"""),
            Regex("""(?i)\b-webkit-text-fill-color\s*:\s*#fff\b$imp\s*;?"""),
            Regex("""(?i)\b-webkit-text-fill-color\s*:\s*#ffffff\b$imp\s*;?"""),
            Regex("""(?i)\b-webkit-text-fill-color\s*:\s*white\b$imp\s*;?"""),
            Regex("""(?i)\b-webkit-text-fill-color\s*:\s*rgb\s*\(\s*255\s*,\s*255\s*,\s*255\s*\)$imp\s*;?"""),
        )
        for (re in styleColorPatterns) {
            s = s.replace(re, "")
        }
        s = s.replace(
            Regex(
                """(?i)(<font\b[^>]*?)\s+color\s*=\s*["']?(?:#fff(?:fff)?|#fefefe|#f5f5f5|#eeeeee|#e0e0e0|white|ivory|snow)["']?""",
            ),
            "$1",
        )
        s = s.replace(Regex(""";\s*;+"""), ";")
        s = s.replace(Regex("""(?i)\sstyle\s*=\s*"\s*;*\s*""""), "")
        s = s.replace(Regex("""(?i)\sstyle\s*=\s*'\s*;*\s*'"""), "")
        return s
    }

    /** TinyMCE часта піша font-size у style — тады не працуе маштаб чытання. */
    private fun stripInlineFontSizeDeclarations(html: String): String {
        var s = html.replace(Regex("""(?i)\bfont-size\s*:\s*[^;]+;?"""), "")
        s = s.replace(Regex(""";\s*;+"""), ";")
        s = s.replace(Regex("""(?i)\sstyle\s*=\s*"\s*;*\s*""""), "")
        s = s.replace(Regex("""(?i)\sstyle\s*=\s*'\s*;*\s*'"""), "")
        return s
    }

    private fun escapeHtmlPlain(text: String): String =
        text
            .replace("&", "&amp;")
            .replace("<", "&lt;")
            .replace(">", "&gt;")
            .replace("\"", "&quot;")
            .replace("\n", "<br/>")

    private fun buildInnerHtml(raw: String): String {
        val t = raw.trim()
        if (t.isEmpty()) {
            return "<p class=\"ordo-plain\">${escapeHtmlPlain(getString(R.string.ordo_missae_empty))}</p>"
        }
        return if (looksLikeHtml(t)) {
            stripUnsafe(t)
        } else {
            "<div class=\"ordo-plain\">${escapeHtmlPlain(t)}</div>"
        }
    }

    private fun buildHtmlDocument(bodyInnerHtml: String, fontPx: Float, bodyRawForFingerprint: String): String {
        val density = resources.displayMetrics.density.coerceAtLeast(0.5f)
        val cssBodyFontPx = fontPx / density
        val padPx = resources.getDimension(R.dimen.prayer_card_padding) / density
        val cssFontFamily = when (AppFontFamilyStore.readFamily(requireContext())) {
            AppFontFamilyStore.Family.SANS -> "sans-serif"
            AppFontFamilyStore.Family.SERIF -> "serif"
            AppFontFamilyStore.Family.MONO -> "monospace"
        }
        val textHex = colorHex(requireContext().themeColor(R.attr.totusColorTextPrimary))
        val secondaryHex = colorHex(requireContext().themeColor(R.attr.totusColorTextSecondary))
        val strokeHex = colorHex(requireContext().themeColor(R.attr.totusColorSurfaceStroke))
        val linkHex = colorHex(ContextCompat.getColor(requireContext(), R.color.teal_200))
        val css = """
            * { box-sizing: border-box; }
            :root { --ordo-body-font-px: ${cssBodyFontPx}px; }
            html {
              font-size: 16px;
              background: transparent;
              height: 100%;
              touch-action: manipulation;
            }
            body {
              margin: 0;
              padding: ${padPx}px;
              background: transparent;
              color: $textHex;
              font-family: $cssFontFamily;
              font-size: var(--ordo-body-font-px);
              line-height: 1.38;
              -webkit-text-size-adjust: 100%;
              touch-action: manipulation;
              word-wrap: break-word;
              overflow-wrap: break-word;
            }
            details.ordo-missae-section > :not(summary),
            section.ordo-missae-section {
              color: $textHex;
            }
            .ordo-plain { white-space: normal; }
            p { margin: 0.5em 0; }
            p:first-child { margin-top: 0; }
            p:last-child { margin-bottom: 0; }
            div { max-width: 100%; }
            blockquote {
              margin: 0.6em 0;
              padding-left: 12px;
              border-left: 3px solid $strokeHex;
              color: $secondaryHex;
            }
            ul, ol { margin: 0.5em 0; padding-left: 1.4em; }
            ul { list-style-type: disc; }
            ol { list-style-type: decimal; }
            li { margin: 0.25em 0; }
            strong, b { font-weight: 700; }
            em, i { font-style: italic; }
            u { text-decoration: underline; }
            s, strike, del { text-decoration: line-through; }
            sub { font-size: 0.85em; vertical-align: sub; }
            sup { font-size: 0.85em; vertical-align: super; }
            h1 { font-size: 1.55em; font-weight: 700; margin: 0.75em 0 0.35em; line-height: 1.25; }
            h2 { font-size: 1.35em; font-weight: 700; margin: 0.65em 0 0.3em; line-height: 1.3; }
            h3 { font-size: 1.2em; font-weight: 700; margin: 0.55em 0 0.25em; line-height: 1.35; }
            details.ordo-missae-section + details.ordo-missae-section { margin-top: 0.25rem; }
            details.ordo-missae-section:first-of-type { margin-top: 0; }
            section.ordo-missae-section + section.ordo-missae-section { margin-top: 0.25rem; }
            section.ordo-missae-section:first-child { margin-top: 0; }
            /* WebView: UA на <details> + явная зменная — маштаб без перазагрузкі HTML (evaluateJavascript). */
            details.ordo-missae-section,
            section.ordo-missae-section {
              font-family: $cssFontFamily !important;
              font-size: var(--ordo-body-font-px) !important;
              line-height: 1.38 !important;
            }
            details.ordo-missae-section > summary.ordo-missae-section-summary {
              list-style: none;
              cursor: pointer;
              user-select: none;
              display: block;
              position: relative;
              padding: 10px 28px 4px 0;
              margin: 0;
              font-size: 1rem;
              font-weight: 700;
              -webkit-tap-highlight-color: transparent;
            }
            details.ordo-missae-section[open] > *:last-child {
              margin-bottom: 0.175rem !important;
            }
            details.ordo-missae-section > summary.ordo-missae-section-summary::-webkit-details-marker {
              display: none;
            }
            details.ordo-missae-section > summary.ordo-missae-section-summary::after {
              content: "";
              position: absolute;
              right: 4px;
              top: 50%;
              width: 0.5em;
              height: 0.5em;
              margin-top: -0.25em;
              border-right: 2px solid $secondaryHex;
              border-bottom: 2px solid $secondaryHex;
              transform: rotate(45deg);
              transition: transform 0.15s ease;
              pointer-events: none;
            }
            details.ordo-missae-section[open] > summary.ordo-missae-section-summary::after {
              transform: rotate(-135deg);
              margin-top: -0.15em;
            }
            details.ordo-missae-section .ordo-missae-section-title {
              font-size: 1.06em;
              font-weight: 700;
              margin: 0;
              line-height: 1.35;
              display: block;
            }
            section.ordo-missae-section .ordo-missae-section-title {
              font-size: 1.06rem;
              font-weight: 700;
              margin: 0 0 0.175rem;
              line-height: 1.35;
            }
            h4, h5, h6 { font-size: 1.1em; font-weight: 700; margin: 0.5em 0 0.2em; line-height: 1.35; }
            table { border-collapse: collapse; width: 100%; margin: 0.5em 0; }
            th, td { border: 1px solid $strokeHex; padding: 6px 8px; vertical-align: top; }
            a { color: $linkHex; }
            img { max-width: 100%; height: auto; }
        """.trimIndent()

        val openMap = OrdoMissaeFoldStore.initialOpenMap(requireContext(), bodyRawForFingerprint)
        val initJson = JSONObject().apply {
            openMap.forEach { (k, v) -> put(k, v) }
        }.toString()
        val scripts = buildOrdoFoldScripts(initJson)

        return """
            <!DOCTYPE html>
            <html>
            <head>
            <meta charset="utf-8"/>
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"/>
            <style>$css</style>
            </head>
            <body>$bodyInnerHtml$scripts</body>
            </html>
        """.trimIndent()
    }

    private fun buildOrdoFoldScripts(initialOpenJson: String): String = """
<script>window.__ordoInitialOpen=$initialOpenJson;</script>
<script>
(function(){
  var init = window.__ordoInitialOpen || {};
  Object.keys(init).forEach(function(k){
    var d = document.querySelector('details.ordo-missae-section[data-ordo-section="' + k + '"]');
    if (d) d.open = !!init[k];
  });
  try { delete window.__ordoInitialOpen; } catch (e0) {}
  function bind(){
    var nodes = document.querySelectorAll('details.ordo-missae-section > summary.ordo-missae-section-summary');
    for (var i = 0; i < nodes.length; i++) {
      var sum = nodes[i];
      if (sum.getAttribute('data-ordo-bound') === '1') continue;
      sum.setAttribute('data-ordo-bound', '1');
      sum.addEventListener('click', function(ev){
        ev.preventDefault();
        var s = ev.currentTarget;
        var d = s && s.parentNode;
        if (!d || !d.tagName || d.tagName.toLowerCase() !== 'details') return;
        d.open = !d.open;
        var k = d.getAttribute('data-ordo-section');
        if (k && window.OrdoFold) {
          try { OrdoFold.save(String(k), d.open ? '1' : '0'); } catch (e1) {}
        }
      }, true);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
</script>
""".trimIndent()

    private fun colorHex(@ColorInt color: Int): String =
        String.format("#%06X", 0xFFFFFF and color)

    override fun onDestroyView() {
        super.onDestroyView()
        _binding?.webOrdoBody?.apply {
            removeJavascriptInterface("OrdoFold")
            stopLoading()
            loadUrl("about:blank")
            (parent as? ViewGroup)?.removeView(this)
            destroy()
        }
        foldBridge = null
        _binding = null
    }

}
