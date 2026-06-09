package by.dzmitrypanou.catholicapp.ui.transform

import android.graphics.Color
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.webkit.WebViewClient
import android.widget.ImageButton
import androidx.annotation.ColorInt
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.AppFontFamilyStore
import by.dzmitrypanou.catholicapp.data.PrayerBodyTextSizeStore
import by.dzmitrypanou.catholicapp.data.PrayerBookmarksStore
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import by.dzmitrypanou.catholicapp.databinding.FragmentPrayerDetailBinding
import by.dzmitrypanou.catholicapp.ui.ReadingTextScaleToolbar
import by.dzmitrypanou.catholicapp.ui.themeColor
import kotlin.math.abs

class PrayerDetailFragment : Fragment() {

    private var _binding: FragmentPrayerDetailBinding? = null
    private val binding get() = _binding!!

private var lastLoadedBodyPx: Float = Float.NaN

    private var prayerId: Long = -1L
    private var prayerRawText: String = ""
    private var prayerTitle: String = ""
    private var prayerCategory: String = ""
    private var prayerSubcategory: String = ""

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentPrayerDetailBinding.inflate(inflater, container, false)

        prayerTitle = arguments?.getString("title").orEmpty()
        prayerRawText = arguments?.getString("text").orEmpty()
        prayerCategory = arguments?.getString("category").orEmpty()
        prayerSubcategory = arguments?.getString("subcategory").orEmpty()
        prayerId = arguments?.getLong("prayerId", -1L) ?: -1L

        setupWebView()
        reloadPrayerWebContent()
        return binding.root
    }

    override fun onResume() {
        super.onResume()
        val ctx = context ?: return
        val px = PrayerBodyTextSizeStore.readPx(ctx, resources)
        if (!lastLoadedBodyPx.isFinite() || abs(px - lastLoadedBodyPx) > 0.5f) {
            reloadPrayerWebContent()
        }
        requireActivity().invalidateOptionsMenu()
    }

    private fun setupWebView() {
        val w = binding.webPrayerBody
        w.setBackgroundColor(Color.TRANSPARENT)

        w.isNestedScrollingEnabled = false
        w.isVerticalScrollBarEnabled = false
        w.isHorizontalScrollBarEnabled = false
        w.settings.apply {
            javaScriptEnabled = false
            builtInZoomControls = false
            displayZoomControls = false
            setSupportZoom(false)
            loadsImagesAutomatically = true
            blockNetworkImage = false
            loadWithOverviewMode = true
            useWideViewPort = true
        }
        w.webViewClient = WebViewClient()
    }

    private fun reloadPrayerWebContent() {
        if (_binding == null) return
        val ctx = context ?: return
        val inner = buildInnerHtmlFragment(prayerRawText, prayerTitle, prayerCategory, prayerSubcategory)
        val fontPx = PrayerBodyTextSizeStore.readPx(ctx, resources)
        val html = buildPrayerHtmlDocument(inner, fontPx)
        binding.webPrayerBody.loadDataWithBaseURL(
            PrayerApiClient.siteOriginForHtml,
            html,
            "text/html",
            "UTF-8",
            null
        )
        lastLoadedBodyPx = fontPx
    }

    private fun buildInnerHtmlFragment(text: String, title: String, category: String, subcategory: String): String {
        if (looksLikeHtml(text)) {
            return sanitizePrayerHtmlPreserveLayout(text, title, category, subcategory)
        }
        val plain = sanitizePrayerBody(text, title, category, subcategory)
        return "<div class=\"prayer-plain\">${escapeHtmlPlain(plain)}</div>"
    }

    private fun escapeHtmlPlain(text: String): String =
        text
            .replace("&", "&amp;")
            .replace("<", "&lt;")
            .replace(">", "&gt;")
            .replace("\"", "&quot;")
            .replace("\n", "<br/>")

    private fun buildPrayerHtmlDocument(bodyInnerHtml: String, fontPx: Float): String {
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
            html { background: transparent; height: 100%; touch-action: manipulation; }
            body {
              margin: 0;
              padding: ${padPx}px;
              background: transparent;
              color: $textHex;
              font-family: $cssFontFamily;
              font-size: ${cssBodyFontPx}px;
              line-height: 1.38;
              -webkit-text-size-adjust: 100%;
              touch-action: manipulation;
              word-wrap: break-word;
              overflow-wrap: break-word;
            }
            .prayer-plain { white-space: normal; }
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
            h4, h5, h6 { font-size: 1.1em; font-weight: 700; margin: 0.5em 0 0.2em; line-height: 1.35; }
            table { border-collapse: collapse; width: 100%; margin: 0.5em 0; }
            th, td { border: 1px solid $strokeHex; padding: 6px 8px; vertical-align: top; }
            a { color: $linkHex; }
            img { max-width: 100%; height: auto; }
        """.trimIndent()

        return """
            <!DOCTYPE html>
            <html>
            <head>
            <meta charset="utf-8"/>
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"/>
            <style>$css</style>
            </head>
            <body>$bodyInnerHtml</body>
            </html>
        """.trimIndent()
    }

    private fun colorHex(@ColorInt color: Int): String =
        String.format("#%06X", 0xFFFFFF and color)

private fun sanitizePrayerHtmlPreserveLayout(html: String, title: String, category: String, subcategory: String): String {
        val hiddenKeys = buildHiddenHeaderKeys(title, category, subcategory)
        val categoryMetaKeys = buildCategoryMetaKeys(category, subcategory)
        var cleaned = html
            .replace(Regex("(?is)<head\\b[^>]*>.*?</head>"), "")
            .replace(Regex("(?is)<meta\\b[^>]*/?>"), "")
            .replace(Regex("(?is)<meta\\b[^>]*>"), "")
            .replace(Regex("(?is)<title\\b[^>]*>.*?</title>"), "")
            .replace(Regex("(?is)<link\\b[^>]*/?>"), "")
            .replace(Regex("(?is)<base\\b[^>]*/?>"), "")

        val leadingBlockRegex = Regex(
            "^\\s*<(p|div|h[1-6]|section|article|span|strong|b|em|i)\\b[^>]*>(.*?)</\\1>\\s*",
            setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL)
        )
        while (true) {
            val match = leadingBlockRegex.find(cleaned) ?: break
            val inner = match.groupValues[2]
            val plain = htmlInnerToPlain(inner)
            val key = normalizeHeaderKey(plain)
            if (key.isEmpty() || key in hiddenKeys) {
                cleaned = cleaned.removeRange(match.range)
            } else {
                break
            }
        }

        cleaned = stripLeadingHtmlHeaderBlocks(cleaned, hiddenKeys)

        val brHeaderRegex = Regex(
            "^\\s*(?:<br\\s*/?>\\s*)+",
            RegexOption.IGNORE_CASE
        )
        while (true) {
            cleaned = cleaned.trimStart()
            val brMatch = brHeaderRegex.find(cleaned)
            if (brMatch != null) {
                cleaned = cleaned.removeRange(brMatch.range)
                continue
            }
            val nextBlock = findNextHtmlTextBlock(cleaned) ?: break
            val key = normalizeHeaderKey(nextBlock.plain)
            if (key.isEmpty() || key in hiddenKeys) {
                cleaned = cleaned.removeRange(nextBlock.range).trimStart()
                continue
            }
            break
        }

        cleaned = stripHtmlBlocksMatchingCategoryMeta(cleaned, categoryMetaKeys)
        cleaned = stripEditorNoiseAttributes(cleaned)
        cleaned = stripUnsafeWebContent(cleaned)
        return cleaned.trim()
    }

    private fun stripEditorNoiseAttributes(html: String): String =
        html
            .replace(Regex("\\s*bis_skin_checked=\"[^\"]*\""), "")
            .replace(Regex("\\s*bis_skin_checked='[^']*'"), "")

    private fun stripUnsafeWebContent(html: String): String {
        var s = html.replace(Regex("(?is)<script\\b[^>]*>.*?</script>"), "")
        s = s.replace(Regex("(?is)<iframe\\b[^>]*>.*?</iframe>"), "")
        return s
    }

    private fun sanitizePrayerBody(text: String, title: String, category: String, subcategory: String): String {
        val hiddenKeys = buildHiddenHeaderKeys(title, category, subcategory)
        val categoryMetaKeys = buildCategoryMetaKeys(category, subcategory)
        val lines = text.lineSequence().toMutableList()
        while (lines.isNotEmpty()) {
            val line = lines.first()
            if (line.trim().isEmpty()) {
                lines.removeAt(0)
                continue
            }
            if (normalizeHeaderKey(line) in hiddenKeys) {
                lines.removeAt(0)
                continue
            }
            break
        }
        return lines.joinToString("\n")
            .trimStart()
            .lineSequence()
            .filterNot { line ->
                val t = line.trim()
                t.isNotEmpty() && normalizeHeaderKey(t) in categoryMetaKeys
            }
            .joinToString("\n")
            .trim()
    }

fun reloadBodyForCurrentTextScale() {
        lastLoadedBodyPx = Float.NaN
        reloadPrayerWebContent()
    }

fun bindPrayerDetailToolbarActions(actionView: View) {
        ReadingTextScaleToolbar.bind(actionView, requireActivity()) {
            reloadBodyForCurrentTextScale()
        }
        val bookmarkBtn = actionView.findViewById<ImageButton>(R.id.button_prayer_bookmark)
        val ctx = actionView.context
        if (bookmarkBtn == null) return
        if (prayerId < 0L) {
            bookmarkBtn.visibility = View.GONE
            return
        }
        bookmarkBtn.visibility = View.VISIBLE
        val store = PrayerBookmarksStore(ctx)
        fun updateBookmarkIcon() {
            bookmarkBtn.setImageResource(
                if (store.isBookmarked(prayerId)) R.drawable.ic_bookmark_filled_24
                else R.drawable.ic_bookmark_border_24
            )
        }
        updateBookmarkIcon()
        bookmarkBtn.setOnClickListener {
            store.toggle(prayerId)
            updateBookmarkIcon()
        }
    }

    private fun stripHtmlBlocksMatchingCategoryMeta(html: String, keys: Set<String>): String {
        if (keys.isEmpty()) return html
        var result = html
        val patterns = listOf(
            Regex("<p\\b[^>]*>(.*?)</p>", setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL)),
            Regex("<div\\b[^>]*>(.*?)</div>", setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL)),
            Regex("<h[1-6]\\b[^>]*>(.*?)</h[1-6]>", setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL)),
            Regex("<span\\b[^>]*>(.*?)</span>", setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL)),
            Regex("<small\\b[^>]*>(.*?)</small>", setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL))
        )
        for (pattern in patterns) {
            result = pattern.replace(result) { match ->
                val plain = normalizeHeaderKey(htmlInnerToPlain(match.groupValues[1]))
                if (plain.isNotEmpty() && plain in keys) "" else match.value
            }
        }
        return result
    }

    private fun stripLeadingHtmlHeaderBlocks(html: String, hiddenKeys: Set<String>): String {
        var s = html
        val wrapperRegex = Regex(
            "^\\s*<(div|section|article|header)\\b[^>]*>(.*?)</\\1>\\s*",
            setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL)
        )
        while (true) {
            s = s.trimStart()
            val m = wrapperRegex.find(s) ?: break
            val inner = m.groupValues[2]
            val plain = htmlInnerToPlain(inner)
            val key = normalizeHeaderKey(plain)
            val innerLooksStructural = Regex("<\\s*/?\\s*[a-zA-Z][^>]*>").containsMatchIn(inner)
            if (key in hiddenKeys || (plain.isBlank() && innerLooksStructural)) {
                s = s.removeRange(m.range)
                continue
            }
            if (plain.isBlank() && !innerLooksStructural) {
                s = s.removeRange(m.range)
                continue
            }
            break
        }
        return s
    }

    private data class HtmlTextBlock(val range: IntRange, val plain: String)

    private fun findNextHtmlTextBlock(html: String): HtmlTextBlock? {
        val trimmed = html.trimStart()
        if (trimmed.isEmpty()) return null
        val offset = html.length - trimmed.length
        val patterns = listOf(
            Regex("^<(p|div|h[1-6])\\b[^>]*>(.*?)</\\1>", setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL)),
            Regex("^<(strong|b|em|i|span)\\b[^>]*>(.*?)</\\1>", setOf(RegexOption.IGNORE_CASE, RegexOption.DOT_MATCHES_ALL))
        )
        for (pattern in patterns) {
            val m = pattern.find(trimmed) ?: continue
            val plain = htmlInnerToPlain(m.groupValues[2])
            val start = offset + m.range.first
            val end = offset + m.range.last + 1
            return HtmlTextBlock(start until end, plain)
        }
        return null
    }

    private fun htmlInnerToPlain(inner: String): String {
        return inner
            .replace(Regex("(?is)<br\\s*/?>"), " ")
            .replace(Regex("(?is)<[^>]+>"), "")
            .replace("&nbsp;", " ")
            .replace("&#160;", " ")
            .trim()
    }

    private fun buildCategoryMetaKeys(category: String, subcategory: String): Set<String> {
        val raw = buildSet {
            val cleanCategory = category.trim()
            val cleanSubcategory = subcategory.trim()
            if (cleanCategory.isNotBlank()) {
                add(cleanCategory)
            }
            if (cleanSubcategory.isNotBlank() && cleanSubcategory != PrayerRepository.NO_SUBCATEGORY_TITLE) {
                add(cleanSubcategory)
                if (cleanCategory.isNotBlank()) {
                    add("$cleanCategory • $cleanSubcategory")
                    add("$cleanCategory · $cleanSubcategory")
                    add("$cleanCategory - $cleanSubcategory")
                    add("$cleanCategory – $cleanSubcategory")
                    add("$cleanCategory — $cleanSubcategory")
                }
            }
        }
        return raw.map { normalizeHeaderKey(it) }.filter { it.isNotEmpty() }.toSet()
    }

    private fun buildHiddenHeaderKeys(title: String, category: String, subcategory: String): Set<String> {
        val raw = buildSet {
            val cleanTitle = title.trim()
            val cleanCategory = category.trim()
            val cleanSubcategory = subcategory.trim()
            if (cleanTitle.isNotBlank()) {
                add(cleanTitle)
            }
            if (cleanCategory.isNotBlank()) {
                add(cleanCategory)
            }
            if (cleanSubcategory.isNotBlank() && cleanSubcategory != PrayerRepository.NO_SUBCATEGORY_TITLE) {
                add(cleanSubcategory)
                if (cleanCategory.isNotBlank()) {
                    add("$cleanCategory • $cleanSubcategory")
                    add("$cleanCategory · $cleanSubcategory")
                    add("$cleanCategory - $cleanSubcategory")
                    add("$cleanCategory – $cleanSubcategory")
                    add("$cleanCategory — $cleanSubcategory")
                }
            }
        }
        return raw.map { normalizeHeaderKey(it) }.filter { it.isNotEmpty() }.toSet()
    }

    private fun normalizeHeaderKey(value: String): String {
        return value
            .lowercase()
            .replace('\u00a0', ' ')
            .replace(Regex("\\s+"), " ")
            .replace(Regex("^[\"'«»]+|[\"'«»]+$"), "")
            .trim()
    }

    private fun looksLikeHtml(value: String): Boolean {
        return Regex("<\\s*/?\\s*[a-zA-Z][^>]*>").containsMatchIn(value)
    }

    override fun onDestroyView() {
        _binding?.webPrayerBody?.apply {
            webViewClient = WebViewClient()
            stopLoading()
            loadUrl("about:blank")
            (parent as? ViewGroup)?.removeView(this)
            destroy()
        }
        _binding = null
        super.onDestroyView()
    }
}
