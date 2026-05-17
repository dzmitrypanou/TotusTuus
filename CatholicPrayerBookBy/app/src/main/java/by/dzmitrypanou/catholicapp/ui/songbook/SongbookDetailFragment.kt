package by.dzmitrypanou.catholicapp.ui.songbook

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.drawable.BitmapDrawable
import android.os.Build
import android.os.Bundle
import android.view.Gravity
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.ImageButton
import androidx.annotation.ColorInt
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.data.PrayerBodyTextSizeStore
import by.dzmitrypanou.catholicapp.data.SongbookBookmarksStore
import by.dzmitrypanou.catholicapp.data.SongbookContentType
import by.dzmitrypanou.catholicapp.data.SongbookEntry
import by.dzmitrypanou.catholicapp.data.SongbookRepository
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import by.dzmitrypanou.catholicapp.databinding.FragmentSongbookDetailBinding
import by.dzmitrypanou.catholicapp.ui.ReadingTextScaleToolbar
import by.dzmitrypanou.catholicapp.ui.themeColor
import kotlin.math.abs
import kotlin.math.max

class SongbookDetailFragment : Fragment() {

    private var _binding: FragmentSongbookDetailBinding? = null
    private val binding get() = _binding!!

    private var entryId: String = ""
    private var lastLoadedBodyPx: Float = Float.NaN
    private var displayedImageBitmap: Bitmap? = null
    private var currentContentType: SongbookContentType? = null
    private var fullscreenImageOpen: Boolean = false
    private var fullscreenOverlay: FrameLayout? = null
    private var previousSystemUiVisibility: Int? = null
    private val catalog: SongbookRepository.Catalog
        get() = if (arguments?.getString("catalog") == "kantaral") {
            SongbookRepository.Catalog.KANTARAL
        } else {
            SongbookRepository.Catalog.SONGBOOK
        }

    private var songbookWhitePaperChrome: Boolean = false

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentSongbookDetailBinding.inflate(inflater, container, false)
        entryId = arguments?.getString("entryId").orEmpty()
        fullscreenImageOpen = savedInstanceState?.getBoolean(STATE_FULLSCREEN_IMAGE_OPEN, false) ?: false
        setupWebView()
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        loadEntry()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        outState.putBoolean(STATE_FULLSCREEN_IMAGE_OPEN, fullscreenImageOpen)
        super.onSaveInstanceState(outState)
    }

    fun onHostConfigurationChanged() {
        binding.imageSongbook.resetZoom()
        fullscreenOverlay?.let { overlay ->
            overlay.requestLayout()
            overlay.post {
                overlay.findViewWithTag<ZoomableImageView>(FULLSCREEN_IMAGE_TAG)?.resetZoom()
                overlay.findViewWithTag<ImageButton>(FULLSCREEN_EXIT_BUTTON_TAG)?.layoutParams =
                    fullscreenExitButtonLayoutParams()
                applyFullscreenSystemUi()
            }
        }
    }

    fun reloadBodyForCurrentTextScale() {
        val repo = SongbookRepository(requireContext(), catalog)
        val e = repo.getById(entryId) ?: return
        if (e.contentType == SongbookContentType.TEXT) {
            lastLoadedBodyPx = Float.NaN
            reloadWebText(e)
        }
    }

    fun bindSongbookDetailToolbarActions(actionView: View) {
        val repo = SongbookRepository(requireContext(), catalog)
        val resolvedType = currentContentType ?: repo.getById(entryId)?.contentType
        val showTextScaleButtons = resolvedType == SongbookContentType.TEXT
        val smallerButton = actionView.findViewById<View>(R.id.button_reading_text_smaller)
        val largerButton = actionView.findViewById<View>(R.id.button_reading_text_larger)

        smallerButton?.visibility = if (showTextScaleButtons) View.VISIBLE else View.INVISIBLE
        largerButton?.visibility = if (showTextScaleButtons) View.VISIBLE else View.INVISIBLE
        if (showTextScaleButtons) {
            ReadingTextScaleToolbar.bind(actionView, requireActivity()) {
                reloadBodyForCurrentTextScale()
            }
        }
        val bookmarkBtn = actionView.findViewById<ImageButton>(R.id.button_prayer_bookmark) ?: return
        val ctx = actionView.context
        if (entryId.isBlank()) {
            bookmarkBtn.visibility = View.GONE
            return
        }
        bookmarkBtn.visibility = View.VISIBLE
        bookmarkBtn.isClickable = true
        bookmarkBtn.isFocusable = true
        bookmarkBtn.contentDescription = ctx.getString(R.string.songbook_bookmark_toggle)
        val store = SongbookBookmarksStore(ctx, catalog)
        fun updateBookmarkIcon() {
            bookmarkBtn.setImageResource(
                if (store.isBookmarked(entryId)) R.drawable.ic_bookmark_filled_24
                else R.drawable.ic_bookmark_border_24
            )
        }
        updateBookmarkIcon()
        bookmarkBtn.setOnClickListener {
            store.toggle(entryId)
            updateBookmarkIcon()
        }
    }

    private fun setupWebView() {
        val w = binding.webSongbookBody
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

    private fun loadEntry() {
        val repo = SongbookRepository(requireContext(), catalog)
        val entry = repo.getById(entryId)
        if (entry == null) {
            findNavController().popBackStack()
            return
        }
        if (mediaTypeNeedsLocalFile(entry) && !localMediaPresent(entry, repo)) {
            hideAllContent()
            showMediaLoadError(getString(R.string.songbook_media_missing))
            return
        }
        bindEntry(entry, repo)
    }

    private fun mediaTypeNeedsLocalFile(entry: SongbookEntry): Boolean =
                entry.contentType == SongbookContentType.IMAGE

    private fun localMediaPresent(entry: SongbookEntry, repo: SongbookRepository): Boolean {
        val name = entry.mediaFileName ?: return false
        val f = repo.mediaFile(name)
        return f.isFile && f.length() > 0L
    }

    private fun bindEntry(entry: SongbookEntry, repo: SongbookRepository) {
        currentContentType = entry.contentType
        requireActivity().invalidateOptionsMenu()
        applySongbookDetailChrome(
            entry.contentType == SongbookContentType.IMAGE
        )
        hideAllContent()
        when (entry.contentType) {
            SongbookContentType.TEXT -> showText(entry)
            SongbookContentType.IMAGE -> showImage(entry, repo)
        }
    }

    private fun showMediaLoadError(message: String) {
        binding.webSongbookBody.visibility = View.VISIBLE
        val ctx = context ?: return
        val fontPx = PrayerBodyTextSizeStore.readPx(ctx, resources)
        val html = buildHtmlDocument("<p>${escapeHtml(message)}</p>", fontPx, songbookWhitePaperChrome)
        binding.webSongbookBody.loadDataWithBaseURL(
            PrayerApiClient.siteOriginForHtml,
            html,
            "text/html",
            "UTF-8",
            null
        )
    }

    private fun hideAllContent() {
        binding.webSongbookBody.visibility = View.GONE
        binding.scrollSongbookImage.visibility = View.GONE
        binding.buttonSongbookImageFullscreen.visibility = View.GONE
    }

    private fun showText(entry: SongbookEntry) {
        binding.webSongbookBody.visibility = View.VISIBLE
        reloadWebText(entry)
    }

    override fun onResume() {
        super.onResume()
        requireActivity().invalidateOptionsMenu()
        val repo = SongbookRepository(requireContext(), catalog)
        val e = repo.getById(entryId)
        if (e == null) {
            if (entryId.isNotBlank()) {
                findNavController().popBackStack()
            }
            return
        }
        val ctx = context ?: return
        val px = PrayerBodyTextSizeStore.readPx(ctx, resources)
        if (e.contentType == SongbookContentType.TEXT &&
            (!lastLoadedBodyPx.isFinite() || abs(px - lastLoadedBodyPx) > 0.5f)
        ) {
            reloadWebText(e)
        }
    }

    private fun reloadWebText(entry: SongbookEntry) {
        if (_binding == null) return
        val ctx = context ?: return
        val fontPx = PrayerBodyTextSizeStore.readPx(ctx, resources)
        val inner = buildTextInnerHtml(entry)
        val html = buildHtmlDocument(inner, fontPx, lightPaper = false)
        binding.webSongbookBody.loadDataWithBaseURL(
            PrayerApiClient.siteOriginForHtml,
            html,
            "text/html",
            "UTF-8",
            null
        )
        lastLoadedBodyPx = fontPx
    }

    private fun buildTextInnerHtml(entry: SongbookEntry): String {
        val raw = entry.textBody.trim()
        if (looksLikeHtml(raw)) {
            return raw
        }
        return "<div class=\"song-plain\">${escapeHtml(raw)}</div>"
    }

    private fun looksLikeHtml(value: String): Boolean =
        Regex("<\\s*/?\\s*[a-zA-Z][^>]*>").containsMatchIn(value)

    private fun escapeHtml(text: String): String =
        text
            .replace("&", "&amp;")
            .replace("<", "&lt;")
            .replace(">", "&gt;")
            .replace("\"", "&quot;")
            .replace("\n", "<br/>")

    private fun applySongbookDetailChrome(whitePaper: Boolean) {
        songbookWhitePaperChrome = whitePaper
        val ctx = context ?: return
        val white = ContextCompat.getColor(ctx, R.color.white)
        if (whitePaper) {
            binding.root.setBackgroundColor(white)
            binding.webSongbookBody.setBackgroundColor(white)
            binding.scrollSongbookImage.setBackgroundColor(white)
        } else {
            binding.root.setBackgroundResource(R.drawable.bg_gradient_dark)
            binding.webSongbookBody.setBackgroundColor(Color.TRANSPARENT)
            binding.scrollSongbookImage.setBackgroundColor(Color.TRANSPARENT)
        }
    }

    private fun buildHtmlDocument(bodyInnerHtml: String, fontPx: Float, lightPaper: Boolean = false): String {
        val density = resources.displayMetrics.density.coerceAtLeast(0.5f)
        val cssBodyFontPx = fontPx / density
        val padPx = resources.getDimension(R.dimen.prayer_card_padding) / density
        val ctx = requireContext()
        val textHex = colorHex(
            if (lightPaper) ContextCompat.getColor(ctx, R.color.black)
            else ctx.themeColor(R.attr.totusColorTextPrimary)
        )
        val secondaryHex = colorHex(
            if (lightPaper) ContextCompat.getColor(ctx, R.color.purple_700)
            else ctx.themeColor(R.attr.totusColorTextSecondary)
        )
        val strokeHex =
            if (lightPaper) "#CCCCCC" else colorHex(ctx.themeColor(R.attr.totusColorSurfaceStroke))
        val linkHex = colorHex(
            ContextCompat.getColor(
                ctx,
                if (lightPaper) R.color.teal_700 else R.color.teal_200
            )
        )
        val surfaceBg = if (lightPaper) "#FFFFFF" else "transparent"
        val css = """
            * { box-sizing: border-box; }
            html { background: $surfaceBg; height: 100%; touch-action: manipulation; }
            body {
              margin: 0;
              padding: ${padPx}px;
              background: $surfaceBg;
              color: $textHex;
              font-family: sans-serif;
              font-size: ${cssBodyFontPx}px;
              line-height: 1.38;
              -webkit-text-size-adjust: 100%;
              touch-action: manipulation;
              word-wrap: break-word;
              overflow-wrap: break-word;
            }
            .song-plain { white-space: normal; }
            p { margin: 0.5em 0; }
            img { max-width: 100%; height: auto; }
            a { color: $linkHex; }
            blockquote {
              margin: 0.6em 0;
              padding-left: 12px;
              border-left: 3px solid $strokeHex;
              color: $secondaryHex;
            }
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

    private fun showImage(entry: SongbookEntry, repo: SongbookRepository) {
        val name = entry.mediaFileName
        if (name.isNullOrBlank()) {
            showMediaLoadError(getString(R.string.songbook_media_missing))
            return
        }
        val file = repo.mediaFile(name)
        if (!file.isFile || file.length() == 0L) {
            showMediaLoadError(getString(R.string.songbook_media_missing))
            return
        }
        displayedImageBitmap?.recycle()
        displayedImageBitmap = decodeSampledBitmap(
            file.absolutePath,
            max(resources.displayMetrics.widthPixels, resources.displayMetrics.heightPixels)
        )
        if (displayedImageBitmap == null) {
            showMediaLoadError(getString(R.string.songbook_image_decode_error))
            return
        }
        binding.scrollSongbookImage.visibility = View.VISIBLE
        binding.buttonSongbookImageFullscreen.visibility = View.VISIBLE
        binding.imageSongbook.setFitMode(ZoomableImageView.FitMode.FIT_WIDTH)
        binding.imageSongbook.setImageBitmap(displayedImageBitmap)
        binding.buttonSongbookImageFullscreen.setOnClickListener {
            openImageFullscreen()
        }
        if (fullscreenImageOpen) {
            binding.scrollSongbookImage.post {
                if (_binding != null && displayedImageBitmap != null && fullscreenImageOpen) {
                    openImageFullscreen()
                }
            }
        }
    }

    private fun openImageFullscreen() {
        val bitmap = displayedImageBitmap ?: return
        val ctx = context ?: return
        fullscreenImageOpen = true
        fullscreenOverlay?.let {
            it.findViewWithTag<ZoomableImageView>(FULLSCREEN_IMAGE_TAG)?.resetZoom()
            return
        }
        val parent = activity?.findViewById<ViewGroup>(android.R.id.content) ?: return
        val root = FrameLayout(ctx).apply {
            setBackgroundColor(Color.WHITE)
            isClickable = true
            fitsSystemWindows = false
        }
        val imageView = ZoomableImageView(ctx).apply {
            tag = FULLSCREEN_IMAGE_TAG
            setFitMode(ZoomableImageView.FitMode.FIT_WIDTH)
            setImageDrawable(BitmapDrawable(resources, bitmap))
            setBackgroundColor(Color.WHITE)
        }
        root.addView(
            imageView,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
        )
        val exitButton = ImageButton(ctx).apply {
            tag = FULLSCREEN_EXIT_BUTTON_TAG
            setImageResource(R.drawable.ic_fullscreen_exit_24)
            setBackgroundResource(R.drawable.bg_fullscreen_fab)
            imageTintList = android.content.res.ColorStateList.valueOf(Color.WHITE)
            contentDescription = getString(R.string.songbook_image_fullscreen_exit_content_description)
            setPadding(
                (12f * resources.displayMetrics.density).toInt(),
                (12f * resources.displayMetrics.density).toInt(),
                (12f * resources.displayMetrics.density).toInt(),
                (12f * resources.displayMetrics.density).toInt()
            )
            setOnClickListener {
                closeImageFullscreen()
            }
        }
        root.addView(exitButton, fullscreenExitButtonLayoutParams())
        parent.addView(
            root,
            ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
        )
        fullscreenOverlay = root
        applyFullscreenSystemUi()
    }

    private fun closeImageFullscreen() {
        fullscreenImageOpen = false
        fullscreenOverlay?.let { overlay ->
            (overlay.parent as? ViewGroup)?.removeView(overlay)
        }
        fullscreenOverlay = null
        restoreSystemUi()
    }

    private fun fullscreenExitButtonLayoutParams(): FrameLayout.LayoutParams {
        val size = (48f * resources.displayMetrics.density).toInt()
        val margin = (16f * resources.displayMetrics.density).toInt()
        return FrameLayout.LayoutParams(size, size).apply {
            gravity = Gravity.BOTTOM or Gravity.END
            setMargins(margin, margin, margin, margin)
        }
    }

    private fun applyFullscreenSystemUi() {
        val decor = activity?.window?.decorView ?: return
        if (previousSystemUiVisibility == null) {
            previousSystemUiVisibility = decor.systemUiVisibility
        }
        decor.systemUiVisibility =
            previousSystemUiVisibility.orZero() or
                View.SYSTEM_UI_FLAG_FULLSCREEN or
                View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or
                View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN or
                View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION or
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            activity?.window?.let { window ->
                WindowInsetsControllerCompat(window, decor).hide(WindowInsetsCompat.Type.systemBars())
            }
        }
    }

    private fun restoreSystemUi() {
        val previous = previousSystemUiVisibility ?: return
        val window = activity?.window
        window?.decorView?.systemUiVisibility = previous
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R && window != null) {
            WindowInsetsControllerCompat(window, window.decorView).show(WindowInsetsCompat.Type.systemBars())
        }
        previousSystemUiVisibility = null
    }

    private fun Int?.orZero(): Int = this ?: 0

    private fun decodeSampledBitmap(path: String, maxSide: Int): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(path, bounds)
        var sample = 1
        val w = bounds.outWidth
        val h = bounds.outHeight
        if (w <= 0 || h <= 0) return BitmapFactory.decodeFile(path)
        while (max(w, h) / sample > maxSide) sample *= 2
        val opts = BitmapFactory.Options().apply { inSampleSize = sample }
        return BitmapFactory.decodeFile(path, opts)
    }

    override fun onDestroyView() {
        fullscreenOverlay?.let { overlay ->
            (overlay.parent as? ViewGroup)?.removeView(overlay)
        }
        fullscreenOverlay = null
        restoreSystemUi()
        binding.imageSongbook.setImageDrawable(null)
        displayedImageBitmap?.recycle()
        displayedImageBitmap = null
        _binding?.webSongbookBody?.apply {
            webViewClient = WebViewClient()
            stopLoading()
            loadUrl("about:blank")
            (parent as? ViewGroup)?.removeView(this)
            destroy()
        }
        _binding = null
        super.onDestroyView()
    }

    private companion object {
        const val STATE_FULLSCREEN_IMAGE_OPEN = "state_fullscreen_image_open"
        const val FULLSCREEN_IMAGE_TAG = "fullscreen_image"
        const val FULLSCREEN_EXIT_BUTTON_TAG = "fullscreen_exit_button"
    }
}
