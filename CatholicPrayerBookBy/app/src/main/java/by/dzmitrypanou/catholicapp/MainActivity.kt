package by.dzmitrypanou.catholicapp

import android.Manifest
import android.content.Context
import android.content.DialogInterface
import android.content.Intent
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.os.Build
import android.os.Bundle
import android.text.TextUtils
import android.util.Log
import android.view.Gravity
import android.view.Menu
import android.view.MenuItem
import android.view.View
import android.graphics.Color
import android.view.ViewGroup
import android.widget.LinearLayout
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.annotation.DimenRes
import androidx.appcompat.app.ActionBar
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.graphics.drawable.DrawerArrowDrawable
import androidx.appcompat.widget.AppCompatTextView
import androidx.appcompat.widget.Toolbar
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.core.view.isVisible
import androidx.core.os.bundleOf
import androidx.core.content.ContextCompat
import androidx.core.view.updatePadding
import androidx.navigation.NavController
import androidx.navigation.findNavController
import androidx.navigation.fragment.NavHostFragment
import androidx.navigation.ui.AppBarConfiguration
import androidx.navigation.ui.navigateUp
import by.dzmitrypanou.catholicapp.data.PrayerRefreshRequestStore
import by.dzmitrypanou.catholicapp.data.PrayerRepository
import by.dzmitrypanou.catholicapp.data.PrayerAutoUpdateConsentStore
import by.dzmitrypanou.catholicapp.data.AppFontFamilyStore
import by.dzmitrypanou.catholicapp.data.AppColorSchemeStore
import by.dzmitrypanou.catholicapp.data.SongbookRepository
import by.dzmitrypanou.catholicapp.databinding.ActivityMainBinding
import by.dzmitrypanou.catholicapp.sync.AppUpdateCheckStore
import by.dzmitrypanou.catholicapp.ui.PrayerBookUiTypography
import by.dzmitrypanou.catholicapp.ui.ReadingTextScaleToolbar
import by.dzmitrypanou.catholicapp.ui.liturgy.LiturgyDayFragment
import by.dzmitrypanou.catholicapp.ui.ordomissae.OrdoMissaeFragment
import by.dzmitrypanou.catholicapp.ui.liturgy.LiturgyDiocesePreferences
import by.dzmitrypanou.catholicapp.ui.themeColor
import by.dzmitrypanou.catholicapp.ui.TotusToolbarTitleTextView
import by.dzmitrypanou.catholicapp.ui.ToolbarBrandTitleTextView
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureCatalog
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureToolbarActions
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureChapterTextFragment
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureReadingPlanActivationStore
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureReadingPlanKind
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureTranslationStore
import by.dzmitrypanou.catholicapp.ui.solemnities.SolemnitiesFragment
import by.dzmitrypanou.catholicapp.ui.songbook.SongbookDetailFragment
import by.dzmitrypanou.catholicapp.ui.songbook.SongbookToolbarActions
import by.dzmitrypanou.catholicapp.ui.transform.PrayerBookToolbarActions
import by.dzmitrypanou.catholicapp.ui.transform.PrayerDetailFragment
import com.google.android.material.dialog.MaterialAlertDialogBuilder

open class MainActivity : AppCompatActivity() {

    companion object {
        private const val TAG = "MainActivity"
        const val EXTRA_OPEN_PRAYER_BOOK_REFRESH = "extra_open_prayer_book_refresh"
    }

    private lateinit var appBarConfiguration: AppBarConfiguration
    private lateinit var binding: ActivityMainBinding
    private var currentDestinationId: Int = R.id.nav_home
    private var toolbarCustomRow: View? = null
    private var defaultToolbarContentInsetStartWithNavigation: Int = -1
    private var defaultToolbarContentInsetEndWithActions: Int = -1
    private var navigationInitialized: Boolean = false

    private val requestNotificationPermission =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) {
            AppUpdateCheckStore.markNotificationPermissionPrompted(this)
        }

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(AppFontScale.wrap(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        val scheme = AppColorSchemeStore.applyActivityTheme(this)
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
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        WindowInsetsControllerCompat(window, binding.root).apply {
            isAppearanceLightStatusBars = lightBars
            isAppearanceLightNavigationBars = lightBars
        }

        val rootInsetTypes =
            WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
        ViewCompat.setOnApplyWindowInsetsListener(binding.appBarMain.coordinatorLayout) { v, windowInsets ->
            val bars = windowInsets.getInsets(rootInsetTypes)
            v.updatePadding(bars.left, bars.top, bars.right, 0)
            binding.appBarMain.contentMain.root.updatePadding(bottom = bars.bottom)
            windowInsets
        }
        ViewCompat.requestApplyInsets(binding.appBarMain.coordinatorLayout)

        setSupportActionBar(binding.appBarMain.toolbar)
        defaultToolbarContentInsetStartWithNavigation =
            binding.appBarMain.toolbar.contentInsetStartWithNavigation
        defaultToolbarContentInsetEndWithActions =
            binding.appBarMain.toolbar.contentInsetEndWithActions

        installMainNavHost(savedInstanceState)
        initNavigationIfReady()
        if (!navigationInitialized) {
            binding.appBarMain.coordinatorLayout.post {
                if (isFinishing || isDestroyed) return@post
                initNavigationIfReady()
            }
        } else {
            consumePrayerRefreshLaunchIntent(intent)
        }
        maybeAskNotificationPermissionForAppUpdates()
    }

    private fun maybeAskNotificationPermissionForAppUpdates() {
        if (!AppUpdateCheckStore.isEnabled(this)) return
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED
        ) return
        if (AppUpdateCheckStore.wasNotificationPermissionPrompted(this)) return
        binding.appBarMain.coordinatorLayout.post {
            if (isFinishing || isDestroyed) return@post
            val dialog = MaterialAlertDialogBuilder(this)
                .setTitle(R.string.app_update_permission_title)
                .setMessage(R.string.app_update_permission_message)
                .setPositiveButton(R.string.app_update_permission_positive) { _, _ ->
                    requestNotificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
                }
                .setNegativeButton(R.string.app_update_permission_negative) { _, _ ->
                    AppUpdateCheckStore.markNotificationPermissionPrompted(this)
                }
                .show()
            val buttonColor = themeColor(R.attr.totusColorTextPrimary)
            dialog.getButton(DialogInterface.BUTTON_POSITIVE)?.setTextColor(buttonColor)
            dialog.getButton(DialogInterface.BUTTON_NEGATIVE)?.setTextColor(buttonColor)
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        consumePrayerRefreshLaunchIntent(intent)
    }

    private fun consumePrayerRefreshLaunchIntent(intent: Intent?) {
        if (intent?.getBooleanExtra(EXTRA_OPEN_PRAYER_BOOK_REFRESH, false) != true) return
        intent.removeExtra(EXTRA_OPEN_PRAYER_BOOK_REFRESH)
        PrayerRefreshRequestStore.setPendingRefresh(this)
        binding.appBarMain.coordinatorLayout.post {
            if (isFinishing || isDestroyed) return@post
            if (!navigationInitialized) return@post
            runCatching {
                findNavController(R.id.nav_host_fragment_content_main).navigate(R.id.nav_transform)
            }
        }
    }

    private fun installMainNavHost(savedInstanceState: Bundle?) {
        val existing =
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
        if (existing != null) return
        if (savedInstanceState != null) return
        forceInstallMainNavHost()
    }

    private fun forceInstallMainNavHost() {
        val host = NavHostFragment.create(R.navigation.mobile_navigation)
        supportFragmentManager.beginTransaction()
            .replace(R.id.nav_host_fragment_content_main, host)
            .setPrimaryNavigationFragment(host)
            .commitNow()
    }

    private fun initNavigationIfReady() {
        if (navigationInitialized) return
        var navHostFragment =
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
        if (navHostFragment == null) {
            forceInstallMainNavHost()
            navHostFragment =
                supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
                    ?: return
        }
        navigationInitialized = true
        val navController = navHostFragment.navController
        appBarConfiguration = AppBarConfiguration(setOf(R.id.nav_home))

currentDestinationId = navController.currentDestination?.id ?: R.id.nav_home
        navController.addOnDestinationChangedListener(
            NavController.OnDestinationChangedListener { _, destination, arguments ->
                currentDestinationId = destination.id
                syncToolbarWithNavController(navController)
                applyDestinationToolbarUi(destination.id, arguments)
                invalidateOptionsMenu()
            }
        )
        syncToolbarWithNavController(navController)
        applyDestinationToolbarUi(
            navController.currentDestination?.id ?: R.id.nav_home,
            navController.currentBackStackEntry?.arguments
        )
        AppFontFamilyStore.applyToViewTree(binding.root, this)
        consumePrayerRefreshLaunchIntent(intent)
    }

    override fun onResume() {
        super.onResume()
        AppFontFamilyStore.applyToViewTree(binding.root, this)
    }

    override fun onConfigurationChanged(newConfig: Configuration) {
        super.onConfigurationChanged(newConfig)
        binding.appBarMain.coordinatorLayout.post {
            if (isFinishing || isDestroyed) return@post
            ViewCompat.requestApplyInsets(binding.appBarMain.coordinatorLayout)
            AppFontFamilyStore.applyToViewTree(binding.root, this)
            currentSongbookDetailFragment()?.onHostConfigurationChanged()
        }
    }

    override fun onStop() {
        super.onStop()
        if (!isChangingConfigurations) {
            runCatching { AppColorSchemeStore.syncLauncherIconIfPending(this) }
                .onFailure { Log.w(TAG, "Unable to sync launcher icon while stopping", it) }
        }
    }

    override fun onDestroy() {
        binding.appBarMain.coordinatorLayout.removeCallbacks(null)
        binding.appBarMain.toolbar.removeCallbacks(null)
        super.onDestroy()
    }

    private fun applyDestinationToolbarUi(destinationId: Int, arguments: Bundle?) {
        val toolbar = binding.appBarMain.toolbar
        removeToolbarCustomRow()
        toolbar.minimumHeight = resources.getDimensionPixelSize(R.dimen.toolbar_min_height_extended)

var usesCustomTitleRow = true
        when (destinationId) {
            R.id.nav_home -> attachHomeToolbarRow()
            R.id.nav_prayer_detail -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    arguments?.getString("title").orEmpty(),
                    getString(R.string.prayer_detail_title)
                )
            }
            R.id.nav_prayer_list -> {
                clearToolbarBrand()
                val category = arguments?.getString("category").orEmpty()
                val subcategory = arguments?.getString("subcategory").orEmpty()
                val title =
                    if (subcategory.isBlank() ||
                        subcategory == PrayerRepository.NO_SUBCATEGORY_TITLE
                    ) {
                        category
                    } else {
                        subcategory
                    }
                attachAutoSizedToolbarTitle(
                    title,
                    getString(R.string.prayers_list_title)
                )
            }
            R.id.nav_subcategories -> {
                clearToolbarBrand()
                val category = arguments?.getString("category").orEmpty()
                attachAutoSizedToolbarTitle(
                    category,
                    getString(R.string.subcategories_title)
                )
            }
            R.id.nav_prayer_search -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.prayer_search_title),
                    getString(R.string.prayer_search_title)
                )
            }
            R.id.nav_bookmarked_prayers -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.bookmarked_prayers_title),
                    getString(R.string.bookmarked_prayers_title)
                )
            }
            R.id.nav_songbook -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.home_item_songbook),
                    getString(R.string.home_item_songbook)
                )
            }
            R.id.nav_kantaral -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.home_item_kantaral),
                    getString(R.string.home_item_kantaral)
                )
            }
            R.id.nav_songbook_detail -> {
                clearToolbarBrand()
                attachSongbookDetailToolbarTitle(
                    arguments?.getString("displayTitle").orEmpty(),
                    arguments?.getString("displayCategory").orEmpty(),
                    getString(R.string.songbook_detail_title)
                )
            }
            R.id.nav_songbook_search -> {
                clearToolbarBrand()
                val title = if (currentSongbookCatalog() == SongbookRepository.Catalog.KANTARAL) {
                    getString(R.string.kantaral_search_title)
                } else {
                    getString(R.string.songbook_search_title)
                }
                attachAutoSizedToolbarTitle(
                    title,
                    title
                )
            }
            R.id.nav_songbook_bookmarked -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.songbook_bookmarked_title),
                    getString(R.string.songbook_bookmarked_title)
                )
            }
            R.id.nav_transform -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.menu_transform),
                    getString(R.string.menu_transform)
                )
            }
            R.id.nav_liturgy_calendar -> {
                clearToolbarBrand()
                val title = liturgyCalendarToolbarTitle()
                attachAutoSizedToolbarTitle(
                    title,
                    getString(R.string.home_item_liturgy_calendar)
                )
            }
            R.id.nav_liturgy_day -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.liturgy_day_screen_title),
                    getString(R.string.liturgy_day_screen_title)
                )
            }
            R.id.nav_liturgy_calendar_settings -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.liturgy_diocese_settings_title),
                    getString(R.string.liturgy_diocese_settings_title)
                )
            }
            R.id.nav_solemnities -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.home_item_solemnities),
                    getString(R.string.home_item_solemnities)
                )
            }
            R.id.nav_scripture -> {
                clearToolbarBrand()
                val translationId = ScriptureTranslationStore.getSelectedTranslationId(this)
                val shortTranslation = ScriptureCatalog.shortTitle(translationId)
                attachAutoSizedToolbarTitle(
                    "${getString(R.string.home_item_scripture)}: $shortTranslation",
                    getString(R.string.home_item_scripture),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_scripture_translations -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.scripture_translation_screen_title),
                    getString(R.string.scripture_translation_screen_title),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_scripture_chapters -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    arguments?.getString("bookTitle").orEmpty(),
                    getString(R.string.scripture_chapters_title),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_scripture_chapter_text -> {
                clearToolbarBrand()
                val bookTitle = arguments?.getString("bookTitle").orEmpty()
                val chapter = arguments?.getInt("chapter", 1) ?: 1
                attachAutoSizedToolbarTitle(
                    "$bookTitle $chapter",
                    getString(R.string.scripture_chapter_text_title),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_scripture_favorites -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.scripture_favorites_title),
                    getString(R.string.scripture_favorites_title),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_scripture_compare -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.scripture_compare_screen_title),
                    getString(R.string.scripture_compare_screen_title),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_scripture_reading_plans_hub -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.scripture_reading_plans_hub_screen_title),
                    getString(R.string.scripture_reading_plans_hub_screen_title),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_scripture_reading_plan -> {
                clearToolbarBrand()
                val k = if (ScriptureReadingPlanActivationStore.isPlanStarted(this)) {
                    ScriptureReadingPlanActivationStore.getPlanKind(this)
                } else {
                    ScriptureReadingPlanKind.fromStorage(
                        arguments?.getString(ScriptureReadingPlanKind.NAV_ARG_PLAN_KIND)
                    )
                }
                val title = when (k) {
                    ScriptureReadingPlanKind.LINEAR -> getString(R.string.scripture_reading_plan_title)
                    ScriptureReadingPlanKind.CHRONOLOGICAL ->
                        getString(R.string.scripture_reading_plan_chronological_title)
                    ScriptureReadingPlanKind.MIXED -> getString(R.string.scripture_reading_plan_mixed_title)
                }
                attachAutoSizedToolbarTitle(
                    title,
                    getString(R.string.scripture_reading_plan_detail_screen_title),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_scripture_word_search -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.scripture_word_search_title),
                    getString(R.string.scripture_word_search_title),
                    R.dimen.text_toolbar_scripture_title
                )
            }
            R.id.nav_ordo_missae -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.home_item_ordo_missae),
                    getString(R.string.home_item_ordo_missae)
                )
            }
            R.id.nav_slideshow -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.menu_slideshow),
                    getString(R.string.menu_slideshow)
                )
            }
            R.id.nav_settings -> {
                clearToolbarBrand()
                attachAutoSizedToolbarTitle(
                    getString(R.string.menu_settings),
                    getString(R.string.menu_settings)
                )
            }
            else -> {
                usesCustomTitleRow = false
                clearToolbarBrand()
                supportActionBar?.setDisplayShowTitleEnabled(true)
                if (defaultToolbarContentInsetStartWithNavigation >= 0) {
                    toolbar.contentInsetStartWithNavigation =
                        defaultToolbarContentInsetStartWithNavigation
                }
                if (defaultToolbarContentInsetEndWithActions >= 0) {
                    toolbar.contentInsetEndWithActions =
                        defaultToolbarContentInsetEndWithActions
                }
            }
        }
        if (usesCustomTitleRow) {
            toolbar.title = ""
            toolbar.subtitle = null
        }
        AppFontFamilyStore.applyToViewTree(toolbar, this)
        if (usesCustomTitleRow) {
            applyToolbarContentInsetsForDestination(destinationId)
        }

        if (usesCustomTitleRow && destinationId == R.id.nav_home) {
            val tbar = binding.appBarMain.toolbar
            tbar.post {
                if (isFinishing || isDestroyed) return@post
                if (currentDestinationId != R.id.nav_home) return@post
                tbar.navigationIcon = null
                tbar.navigationContentDescription = null
                supportActionBar?.setDisplayHomeAsUpEnabled(false)
                applyToolbarContentInsetsForDestination(R.id.nav_home)
                toolbarCustomRow?.requestLayout()
                tbar.requestLayout()
            }
        }
    }

private fun syncToolbarWithNavController(navController: NavController) {
        val toolbar = binding.appBarMain.toolbar
        val dest = navController.currentDestination ?: return
        if (appBarConfiguration.isTopLevelDestination(dest)) {
            toolbar.navigationIcon = null
            toolbar.navigationContentDescription = null
            toolbar.setNavigationOnClickListener(null)
            supportActionBar?.setDisplayHomeAsUpEnabled(false)
        } else {
            supportActionBar?.setDisplayHomeAsUpEnabled(true)
            val arrow = DrawerArrowDrawable(toolbar.context).apply {
                progress = 1f
                color = themeColor(R.attr.totusColorTextPrimary)
            }
            toolbar.navigationIcon = arrow
            toolbar.setNavigationContentDescription(
                androidx.navigation.ui.R.string.nav_app_bar_navigate_up_description
            )
            toolbar.setNavigationOnClickListener { onSupportNavigateUp() }
        }
    }

    private fun applyToolbarContentInsetsForDestination(destinationId: Int) {
        val toolbar = binding.appBarMain.toolbar
        when (destinationId) {
            R.id.nav_home -> {
                toolbar.contentInsetStartWithNavigation =
                    resources.getDimensionPixelSize(R.dimen.toolbar_home_content_inset_start)
            }
            else -> {
                toolbar.contentInsetStartWithNavigation =
                    resources.getDimensionPixelSize(R.dimen.toolbar_title_gap_after_nav)
            }
        }
        if (defaultToolbarContentInsetEndWithActions >= 0) {
            toolbar.contentInsetEndWithActions = defaultToolbarContentInsetEndWithActions
        }
    }

    private fun attachSongbookDetailToolbarTitle(
        titleText: String,
        categoryText: String,
        fallback: String,
        @DimenRes titleTextDimen: Int = R.dimen.text_toolbar_section_title
    ) {
        val category = categoryText.trim()
        if (category.isEmpty()) {
            attachAutoSizedToolbarTitle(titleText, fallback, titleTextDimen)
            return
        }
        val toolbar = binding.appBarMain.toolbar
        supportActionBar?.setDisplayShowTitleEnabled(false)
        toolbar.contentInsetStartWithNavigation =
            resources.getDimensionPixelSize(R.dimen.toolbar_title_gap_after_nav)

        val column = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
        }
        val titleTv = TotusToolbarTitleTextView(this).apply {
            setTextColor(themeColor(R.attr.totusColorTextPrimary))
            maxLines = 2
            ellipsize = TextUtils.TruncateAt.END
            gravity = Gravity.START or Gravity.CENTER_VERTICAL
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                firstBaselineToTopHeight = 0
                lastBaselineToBottomHeight = 0
            }
            PrayerBookUiTypography.applySongbookToolbarDetailTitleAutoSize(this, titleTextDimen, this@MainActivity)
            text = titleText.ifBlank { fallback }
        }
        column.addView(
            titleTv,
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            )
        )
        val subTv = AppCompatTextView(this).apply {
            setTextColor(themeColor(R.attr.totusColorTextSecondary))
            maxLines = 1
            ellipsize = TextUtils.TruncateAt.END
            gravity = Gravity.START
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                firstBaselineToTopHeight = 0
                lastBaselineToBottomHeight = 0
            }
            PrayerBookUiTypography.applySongbookToolbarCategoryAutoSize(
                this,
                R.dimen.text_list_row_subtitle,
                this@MainActivity
            )
            text = category
        }
        column.addView(
            subTv,
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            ).apply {
                topMargin =
                    resources.getDimensionPixelSize(R.dimen.songbook_toolbar_category_margin_top)
            }
        )
        val lp = ActionBar.LayoutParams(
            ActionBar.LayoutParams.MATCH_PARENT,
            ActionBar.LayoutParams.WRAP_CONTENT,
            Gravity.CENTER_VERTICAL or Gravity.START
        )
        supportActionBar?.setCustomView(column, lp)
        supportActionBar?.setDisplayShowCustomEnabled(true)
        toolbarCustomRow = column
    }

    private fun attachAutoSizedToolbarTitle(
        titleText: String,
        fallback: String,
        @DimenRes titleTextDimen: Int = R.dimen.text_toolbar_section_title
    ) {
        val toolbar = binding.appBarMain.toolbar
        supportActionBar?.setDisplayShowTitleEnabled(false)
        toolbar.contentInsetStartWithNavigation =
            resources.getDimensionPixelSize(R.dimen.toolbar_title_gap_after_nav)

val tv = createSectionToolbarTitleTextView(titleTextDimen)
        tv.text = titleText.ifBlank { fallback }
        val lp = ActionBar.LayoutParams(
            ActionBar.LayoutParams.MATCH_PARENT,
            ActionBar.LayoutParams.WRAP_CONTENT,
            Gravity.CENTER_VERTICAL or Gravity.START
        )
        supportActionBar?.setCustomView(tv, lp)
        supportActionBar?.setDisplayShowCustomEnabled(true)
        toolbarCustomRow = tv
    }

    private fun attachHomeToolbarRow() {
        val toolbar = binding.appBarMain.toolbar
        clearToolbarBrand()
        supportActionBar?.setDisplayShowTitleEnabled(false)
        supportActionBar?.setDisplayShowHomeEnabled(false)
        toolbar.contentInsetStartWithNavigation =
            resources.getDimensionPixelSize(R.dimen.toolbar_home_content_inset_start)

val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            isBaselineAligned = false
        }
        val tv = createHomeToolbarTitleTextView()
        tv.text = getString(R.string.toolbar_brand_title)
        row.addView(
            tv,
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            ).apply {
                gravity = Gravity.CENTER_VERTICAL
            }
        )
        val lp = ActionBar.LayoutParams(
            ActionBar.LayoutParams.MATCH_PARENT,
            ActionBar.LayoutParams.WRAP_CONTENT,
            Gravity.CENTER_VERTICAL or Gravity.START
        )
        supportActionBar?.setCustomView(row, lp)
        supportActionBar?.setDisplayShowCustomEnabled(true)
        toolbarCustomRow = row
    }

    private fun createHomeToolbarTitleTextView(): AppCompatTextView {
        return ToolbarBrandTitleTextView(this).apply {
            ellipsize = TextUtils.TruncateAt.END
            gravity = Gravity.START or Gravity.CENTER_VERTICAL
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                firstBaselineToTopHeight = 0
                lastBaselineToBottomHeight = 0
            }
            PrayerBookUiTypography.applyUiSp(this, R.dimen.text_toolbar_brand_title, this@MainActivity)
        }
    }

private fun createSectionToolbarTitleTextView(@DimenRes textDimen: Int): AppCompatTextView {
        return TotusToolbarTitleTextView(this).apply {
            setTextColor(themeColor(R.attr.totusColorTextPrimary))
            maxLines = 3
            ellipsize = TextUtils.TruncateAt.END
            gravity = Gravity.START or Gravity.CENTER_VERTICAL
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                firstBaselineToTopHeight = 0
                lastBaselineToBottomHeight = 0
            }
            PrayerBookUiTypography.applyToolbarSectionTitleAutoSize(this, textDimen, this@MainActivity)
        }
    }

    private fun removeToolbarCustomRow() {
        val toolbar = binding.appBarMain.toolbar
        toolbarCustomRow?.let { v ->
            if (v.parent == toolbar) {
                toolbar.removeView(v)
            }
        }
        toolbarCustomRow = null
        supportActionBar?.setCustomView(null)
        supportActionBar?.setDisplayShowCustomEnabled(false)
    }

    private fun clearToolbarBrand() {
        supportActionBar?.setLogo(null)
        supportActionBar?.setDisplayUseLogoEnabled(false)
        supportActionBar?.setDisplayShowHomeEnabled(false)
        binding.appBarMain.toolbar.logoDescription = null
    }

    private fun currentPrayerDetailFragment(): PrayerDetailFragment? {
        val navHost =
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
                ?: return null
        return navHost.childFragmentManager.primaryNavigationFragment as? PrayerDetailFragment
    }

    private fun currentSongbookDetailFragment(): SongbookDetailFragment? {
        val navHost =
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
                ?: return null
        return navHost.childFragmentManager.primaryNavigationFragment as? SongbookDetailFragment
    }

    private fun currentOrdoMissaeFragment(): OrdoMissaeFragment? {
        val navHost =
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
                ?: return null
        return navHost.childFragmentManager.primaryNavigationFragment as? OrdoMissaeFragment
    }

    private fun bindLiturgyCalendarSettingsToolbarAction(menu: Menu) {
        menu.findItem(R.id.action_liturgy_calendar_settings)?.actionView
            ?.findViewById<View>(R.id.button_liturgy_calendar_settings)
            ?.setOnClickListener {
                runCatching {
                    findNavController(R.id.nav_host_fragment_content_main)
                        .navigate(R.id.action_global_nav_liturgy_calendar_settings)
                }
            }
    }

    private fun liturgyCalendarToolbarTitle(): String {
        val flags = LiturgyDiocesePreferences.readFlags(this)
        val selected = buildList {
            if (flags.pinskaya) add("Пінская")
            if (flags.minskMogilev) add("Мінска-магілёўская")
            if (flags.vitebskaya) add("Віцебская")
            if (flags.grodzenskaya) add("Гродзенская")
        }
        val suffix = when (selected.size) {
            0 -> "Агульны"
            1 -> "${selected.first()} дыяцэзія"
            2 -> "${selected[0]} і ${selected[1]} дыяцэзіі"
            else -> selected.dropLast(1).joinToString(", ") + " і " + selected.last() + " дыяцэзіі"
        }
        return "${getString(R.string.home_item_liturgy_calendar)}: $suffix"
    }

    private fun currentLiturgyDayFragment(): LiturgyDayFragment? {
        val navHost =
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
                ?: return null
        return navHost.childFragmentManager.primaryNavigationFragment as? LiturgyDayFragment
    }

    private fun currentSolemnitiesFragment(): SolemnitiesFragment? {
        val navHost =
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
                ?: return null
        return navHost.childFragmentManager.primaryNavigationFragment as? SolemnitiesFragment
    }

    override fun onCreateOptionsMenu(menu: Menu): Boolean {
        if (currentDestinationId == R.id.nav_prayer_detail) {
            menuInflater.inflate(R.menu.menu_prayer_detail, menu)
            return true
        }
        if (currentDestinationId == R.id.nav_songbook_detail) {
            menuInflater.inflate(R.menu.menu_songbook_detail, menu)
            return true
        }
        if (currentDestinationId == R.id.nav_ordo_missae) {
            menuInflater.inflate(R.menu.menu_ordo_missae, menu)
            return true
        }
        val showPrayerSearch =
            currentDestinationId == R.id.nav_transform ||
                currentDestinationId == R.id.nav_subcategories ||
                currentDestinationId == R.id.nav_prayer_list
        if (showPrayerSearch) {
            menuInflater.inflate(R.menu.menu_prayer_book, menu)
            return true
        }
        val showSongbookTools =
            currentDestinationId == R.id.nav_songbook ||
                currentDestinationId == R.id.nav_kantaral ||
                currentDestinationId == R.id.nav_songbook_search ||
                currentDestinationId == R.id.nav_songbook_bookmarked
        if (showSongbookTools) {
            menuInflater.inflate(R.menu.menu_songbook_book, menu)
            return true
        }
        if (currentDestinationId == R.id.nav_home) {
            menuInflater.inflate(R.menu.menu_home, menu)
            return true
        }
        val isScriptureDestination =
            currentDestinationId == R.id.nav_scripture ||
                currentDestinationId == R.id.nav_scripture_chapters ||
                currentDestinationId == R.id.nav_scripture_chapter_text ||
                currentDestinationId == R.id.nav_scripture_translations ||
                currentDestinationId == R.id.nav_scripture_favorites ||
                currentDestinationId == R.id.nav_scripture_compare ||
                currentDestinationId == R.id.nav_scripture_word_search ||
                currentDestinationId == R.id.nav_scripture_reading_plans_hub ||
                currentDestinationId == R.id.nav_scripture_reading_plan
        if (isScriptureDestination) {
            menuInflater.inflate(R.menu.menu_scripture, menu)
            return true
        }
        if (currentDestinationId == R.id.nav_liturgy_calendar) {
            menuInflater.inflate(R.menu.menu_liturgy_calendar, menu)
            return true
        }
        if (currentDestinationId == R.id.nav_liturgy_day) {
            menuInflater.inflate(R.menu.menu_liturgy_day, menu)
            return true
        }
        if (currentDestinationId == R.id.nav_solemnities) {
            menuInflater.inflate(R.menu.menu_solemnities, menu)
            return true
        }
        return super.onCreateOptionsMenu(menu)
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        if (item.itemId == R.id.action_home_settings) {
            runCatching {
                findNavController(R.id.nav_host_fragment_content_main)
                    .navigate(R.id.action_nav_home_to_nav_settings)
            }
            return true
        }
        return super.onOptionsItemSelected(item)
    }

    override fun onPrepareOptionsMenu(menu: Menu): Boolean {
        val result = super.onPrepareOptionsMenu(menu)
        if (currentDestinationId == R.id.nav_prayer_detail) {
            val actionView = menu.findItem(R.id.action_prayer_detail_tools)?.actionView
            if (actionView != null) {
                currentPrayerDetailFragment()?.bindPrayerDetailToolbarActions(actionView)
            }
        }
        if (currentDestinationId == R.id.nav_ordo_missae) {
            val actionView = menu.findItem(R.id.action_ordo_reading_text_scale)?.actionView
            if (actionView != null) {
                currentOrdoMissaeFragment()?.bindOrdoMissaeToolbarActions(actionView)
            }
        }
        if (currentDestinationId == R.id.nav_songbook_detail) {
            val actionView = menu.findItem(R.id.action_songbook_detail_tools)?.actionView
            if (actionView != null) {
                currentSongbookDetailFragment()?.bindSongbookDetailToolbarActions(actionView)
            }
        }
        if (currentDestinationId == R.id.nav_liturgy_calendar) {
            bindLiturgyCalendarSettingsToolbarAction(menu)
        }
        if (currentDestinationId == R.id.nav_liturgy_day) {
            val actionView = menu.findItem(R.id.action_liturgy_reading_text_scale)?.actionView
            if (actionView != null) {
                ReadingTextScaleToolbar.bind(actionView, this) {
                    currentLiturgyDayFragment()?.applyReadingTextScaleFromToolbar()
                }
            }
            bindLiturgyCalendarSettingsToolbarAction(menu)
        }
        if (currentDestinationId == R.id.nav_solemnities) {
            val actionView = menu.findItem(R.id.action_solemnities_reading_text_scale)?.actionView
            if (actionView != null) {
                currentSolemnitiesFragment()?.bindSolemnitiesToolbarActions(actionView)
            }
        }
        val showPrayerBookTools =
            currentDestinationId == R.id.nav_transform ||
                currentDestinationId == R.id.nav_subcategories ||
                currentDestinationId == R.id.nav_prayer_list
        if (showPrayerBookTools) {
            menu.findItem(R.id.action_prayer_book_tools)?.actionView?.let { bindPrayerBookToolbarActions(it) }
        }
        val showSongbookTools =
            currentDestinationId == R.id.nav_songbook ||
                currentDestinationId == R.id.nav_kantaral ||
                currentDestinationId == R.id.nav_songbook_search ||
                currentDestinationId == R.id.nav_songbook_bookmarked
        if (showSongbookTools) {
            menu.findItem(R.id.action_songbook_tools)?.actionView?.let { actionView ->
                actionView.findViewById<View>(R.id.button_songbook_bookmarked)?.isVisible =
                    currentDestinationId != R.id.nav_songbook_bookmarked
                bindSongbookToolbarActions(actionView)
            }
        }
        val isScriptureDestination =
            currentDestinationId == R.id.nav_scripture ||
                currentDestinationId == R.id.nav_scripture_chapters ||
                currentDestinationId == R.id.nav_scripture_chapter_text ||
                currentDestinationId == R.id.nav_scripture_translations ||
                currentDestinationId == R.id.nav_scripture_favorites ||
                currentDestinationId == R.id.nav_scripture_compare ||
                currentDestinationId == R.id.nav_scripture_word_search ||
                currentDestinationId == R.id.nav_scripture_reading_plans_hub ||
                currentDestinationId == R.id.nav_scripture_reading_plan
        if (currentDestinationId == R.id.nav_home) {
            menu.findItem(R.id.action_home_settings)?.actionView
                ?.findViewById<View>(R.id.button_home_settings)
                ?.setOnClickListener {
                    runCatching {
                        findNavController(R.id.nav_host_fragment_content_main)
                            .navigate(R.id.action_nav_home_to_nav_settings)
                    }
                }
            menu.findItem(R.id.action_home_settings)?.actionView
                ?.findViewById<View>(R.id.button_home_theme)
                ?.setOnClickListener {
                    toggleHomeColorScheme()
                }
            menu.findItem(R.id.action_home_settings)?.actionView
                ?.findViewById<View>(R.id.button_home_about)
                ?.setOnClickListener {
                    runCatching {
                        findNavController(R.id.nav_host_fragment_content_main)
                            .navigate(R.id.action_nav_home_to_nav_slideshow)
                    }
                }
        }
        if (isScriptureDestination) {
            val actionView = menu.findItem(R.id.action_scripture_tools)?.actionView
            if (actionView != null) {
                val scriptureOnlyFontSize =
                    currentDestinationId == R.id.nav_scripture_favorites ||
                        currentDestinationId == R.id.nav_scripture_compare
                val scriptureFullToolbar =
                    currentDestinationId == R.id.nav_scripture ||
                        currentDestinationId == R.id.nav_scripture_chapters ||
                        currentDestinationId == R.id.nav_scripture_chapter_text ||
                        currentDestinationId == R.id.nav_scripture_translations ||
                        currentDestinationId == R.id.nav_scripture_word_search ||
                        currentDestinationId == R.id.nav_scripture_reading_plans_hub ||
                        currentDestinationId == R.id.nav_scripture_reading_plan
                val showThree = scriptureFullToolbar && !scriptureOnlyFontSize
                actionView.findViewById<View>(R.id.button_scripture_favorites)?.apply {
                    isVisible = showThree && currentDestinationId != R.id.nav_scripture_favorites
                }
                actionView.findViewById<View>(R.id.button_scripture_compare)?.apply { isVisible = showThree }
                actionView.findViewById<View>(R.id.button_scripture_translations)?.apply { isVisible = showThree }
                actionView.findViewById<View>(R.id.frame_scripture_reading_text_scale)?.apply {
                    isVisible = currentDestinationId == R.id.nav_scripture_chapter_text
                }
                bindScriptureToolbarActions(actionView)
            }
        }
        if (toolbarCustomRow != null) {
            applyToolbarContentInsetsForDestination(currentDestinationId)
        }
        return result
    }

    private fun bindScriptureToolbarActions(actionView: View) {
        ReadingTextScaleToolbar.bind(actionView, this) {
            val navHost =
                supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
                    ?: return@bind
            (navHost.childFragmentManager.primaryNavigationFragment as? ScriptureToolbarActions)
                ?.onScriptureTextScaleChanged()
        }
        actionView.findViewById<View>(R.id.button_scripture_favorites)?.setOnClickListener {
            runCatching {
                findNavController(R.id.nav_host_fragment_content_main)
                    .navigate(R.id.action_global_nav_scripture_favorites)
            }
        }
        actionView.findViewById<View>(R.id.button_scripture_compare)?.setOnClickListener {
            runCatching {
                findNavController(R.id.nav_host_fragment_content_main)
                    .navigate(R.id.action_global_nav_scripture_compare)
            }
        }
        actionView.findViewById<View>(R.id.button_scripture_translations)?.setOnClickListener {
            runCatching {
                findNavController(R.id.nav_host_fragment_content_main).navigate(R.id.nav_scripture_translations)
            }
        }
    }

    private fun bindPrayerBookToolbarActions(actionView: View) {
        val primary = (
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
            )?.childFragmentManager?.primaryNavigationFragment as? PrayerBookToolbarActions

        val autoUpdateEnabled = PrayerAutoUpdateConsentStore.isGranted(this)
        val syncingInProgress = primary?.isPrayerDataSyncInProgress() == true
        actionView.findViewById<View>(R.id.button_prayer_book_refresh)?.visibility =
            if (autoUpdateEnabled) View.GONE else View.VISIBLE
        actionView.findViewById<View>(R.id.progress_prayer_book_sync)?.visibility =
            if (autoUpdateEnabled && syncingInProgress) View.VISIBLE else View.INVISIBLE
        actionView.findViewById<View>(R.id.button_prayer_book_refresh)?.setOnClickListener {
            primary?.refreshPrayerDataFromToolbar()
        }
        actionView.findViewById<View>(R.id.button_prayer_book_bookmarked)?.setOnClickListener {
            runCatching {
                findNavController(R.id.nav_host_fragment_content_main)
                    .navigate(R.id.action_global_nav_bookmarked_prayers)
            }
        }
        actionView.findViewById<View>(R.id.button_prayer_book_search)?.setOnClickListener {
            runCatching {
                findNavController(R.id.nav_host_fragment_content_main).navigate(R.id.action_global_nav_prayer_search)
            }
        }
    }

    private fun bindSongbookToolbarActions(actionView: View) {
        val primary = (
            supportFragmentManager.findFragmentById(R.id.nav_host_fragment_content_main) as? NavHostFragment
            )?.childFragmentManager?.primaryNavigationFragment as? SongbookToolbarActions

        val autoUpdateEnabled = PrayerAutoUpdateConsentStore.isGranted(this)
        val syncingInProgress = primary?.isSongbookDataSyncInProgress() == true
        val showToolbarProgress = primary?.showSongbookToolbarSyncProgress() != false
        actionView.findViewById<View>(R.id.button_songbook_refresh)?.visibility =
            if (autoUpdateEnabled) View.GONE else View.VISIBLE
        actionView.findViewById<View>(R.id.progress_songbook_sync)?.visibility =
            if (autoUpdateEnabled && syncingInProgress && showToolbarProgress) View.VISIBLE else View.INVISIBLE
        actionView.findViewById<View>(R.id.button_songbook_refresh)?.setOnClickListener {
            primary?.refreshSongbookDataFromToolbar()
        }
        actionView.findViewById<View>(R.id.button_songbook_bookmarked)?.setOnClickListener {
            runCatching {
                val catalog = currentSongbookCatalog()
                findNavController(R.id.nav_host_fragment_content_main).navigate(
                    R.id.action_global_nav_bookmarked_songs,
                    bundleOf(
                        "catalog" to if (catalog == SongbookRepository.Catalog.KANTARAL) "kantaral" else "songbook"
                    )
                )
            }
        }
        actionView.findViewById<View>(R.id.button_songbook_search)?.setOnClickListener {
            runCatching {
                val actionId = if (currentSongbookCatalog() == SongbookRepository.Catalog.KANTARAL) {
                    R.id.action_global_nav_kantaral_search
                } else {
                    R.id.action_global_nav_songbook_search
                }
                findNavController(R.id.nav_host_fragment_content_main).navigate(actionId)
            }
        }
    }

    private fun currentSongbookCatalog(): SongbookRepository.Catalog {
        val navController = findNavController(R.id.nav_host_fragment_content_main)
        val catalogArg = navController.currentBackStackEntry?.arguments?.getString("catalog")
        return if (currentDestinationId == R.id.nav_kantaral || catalogArg == "kantaral") {
            SongbookRepository.Catalog.KANTARAL
        } else {
            SongbookRepository.Catalog.SONGBOOK
        }
    }

    private fun toggleHomeColorScheme() {
        val next = if (AppColorSchemeStore.readScheme(this) == AppColorSchemeStore.Scheme.LIGHT) {
            AppColorSchemeStore.Scheme.DARK
        } else {
            AppColorSchemeStore.Scheme.LIGHT
        }
        AppColorSchemeStore.writeScheme(this, next)
        recreate()
    }

    override fun onSupportNavigateUp(): Boolean {
        if (!navigationInitialized) return super.onSupportNavigateUp()
        val navController = findNavController(R.id.nav_host_fragment_content_main)
        if (navController.currentDestination?.id == R.id.nav_scripture_reading_plan &&
            ScriptureReadingPlanActivationStore.isPlanStarted(this)
        ) {
            if (navController.popBackStack(R.id.nav_scripture, false)) {
                return true
            }
        }
        if (navController.currentDestination?.id == R.id.nav_scripture_chapter_text) {
            if (ScriptureChapterTextFragment.navigateBackToChapterList(navController)) {
                return true
            }
        }
        return navController.navigateUp(appBarConfiguration) || super.onSupportNavigateUp()
    }
}

class MainLightActivity : MainActivity()
