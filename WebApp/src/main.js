(function () {
    'use strict';

    const BOOKMARKS_KEY = 'totus_web_prayer_bookmarks';
    const SONGBOOK_BOOKMARKS_KEY = 'totus_web_songbook_bookmarks';
    let currentView = 'home';
    let currentDate = luxon.DateTime.now();

    const CALENDAR_DIOCESES_KEY = 'totus_calendar_diocese_toggles';

    /**
     * Карты галоўнага экрана; тыя ж выявы, што HomeFragment (drawable у дадатку).
     * У WebApp: WebP для падтрымліваючых браўзераў + JPEG fallback (без вялікіх PNG у сетцы).
     */
    const HOME_CARDS = [
        {
            title: 'Ordo Missae',
            span: 1,
            target: 'ordo-missae',
            available: true,
            image: 'assets/home/ordo_missae_header_image.jpg',
            preload: true,
        },
        {
            title: 'Малітоўнік',
            span: 1,
            target: 'prayers',
            available: true,
            image: 'assets/home/prayerbook_header_image.jpg',
        },
        {
            title: 'Літургічны каляндар',
            span: 2,
            target: 'calendar',
            available: true,
            image: 'assets/home/liturgy_calendar_header_image.jpg',
        },
        {
            title: 'Кантарал',
            span: 1,
            target: 'kantaral',
            available: true,
            image: 'assets/home/kantaral_header_image.jpg',
            preload: true,
        },
        {
            title: 'Спеўнік',
            span: 1,
            target: 'songbook',
            available: true,
            image: 'assets/home/songbook_header_image.jpg',
        },
        {
            title: 'Урачыстасці і святы',
            span: 1,
            target: 'solemnities',
            available: true,
            image: 'assets/home/solemnities_header_image.jpg',
        },
        {
            title: 'Святое Пісанне',
            span: 1,
            target: 'scripture',
            available: true,
            image: 'assets/home/scripture_header_bible.jpg',
        },
    ];

    const SCRIPTURE_TR_KEY = 'totus_scripture_translation_id';
    /** Як ScriptureTestamentExpandStore: стан разгортання Новага/Старога Запавета { nt, ot }. */
    const SCRIPTURE_TESTAMENT_EXPAND_KEY = 'totus_scripture_testament_expand';
    /** Як SongbookCategoryExpandStore: разгортаньне раздзелаў спеўніка. */
    const SONGBOOK_CATEGORY_EXPAND_KEY = 'totus_songbook_category_expand';
    const SONGBOOK_GROUP_UNCATEGORIZED = '__uncategorized__';
    /** Як ScriptureVerseFavoritesStore / ScriptureComparisonStore у Android. */
    const SCRIPTURE_FAVORITES_KEY = 'totus_scripture_favorite_verses';
    const SCRIPTURE_COMPARE_VERSES_KEY = 'totus_scripture_compare_verses';
    const SCRIPTURE_COMPARE_TRS_KEY = 'totus_scripture_compare_translations';
    const SCRIPTURE_COMPARE_HINT_KEY = 'totus_scripture_compare_hint_dismissed';
    const SCRIPTURE_COMPARE_EXPAND_KEY = 'totus_scripture_compare_translations_expanded';
    /** Як SEARCH_DEBOUNCE_MS у ScriptureWordSearchFragment. */
    const SCRIPTURE_WORD_SEARCH_DEBOUNCE_MS = 320;
    const SCRIPTURE_BUNDLE_BASE = 'assets/scripture';
    const SCRIPTURE_DEFAULT_TR = 'catholic_nt';
    /** Парадак id як у ScriptureCatalog.allTranslations() (Android). */
    const SCRIPTURE_TRANSLATION_ORDER = [
        'catholic_nt',
        'bokun',
        'semiukha',
        'charniauski_2017',
        'stankevich',
        'synodal_ru',
    ];
    const TEXT_STEP_KEY = 'totus_web_text_step';
    let solemnitiesSelectedYear = null;
    /** sans | serif | mono — як ключы AppFontFamilyStore.Family у Android */
    const FONT_FAMILY_KEY = 'totus_web_font_family';
    /** current | white | dark | beige */
    const APP_THEME_KEY = 'totus_web_color_theme';

    /** Адзін WebP для вузкіх картак (span 1): max 480px у файле. */
    function totusHomeCardWebpPath(rasterPath) {
        if (!rasterPath) return '';
        const s = String(rasterPath);
        return s.replace(/\.(jpe?g|png)$/i, '.webp');
    }

    /** Дзве выявы WebP для шырокіх картак (span 2): *-640.webp і *-928.webp. */
    function homeCardWebpSrcset(rasterPath, span) {
        if (!rasterPath) return '';
        const s = String(rasterPath);
        if (s === 'assets/home/ordo_missae_header_image.jpg' || s === 'assets/home/kantaral_header_image.jpg') return '';
        const base = String(rasterPath).replace(/\.(jpe?g|png)$/i, '');
        if (span === 2) {
            const u640 = escapeHtml(totusAssetUrl(`${base}-640.webp`));
            const u928 = escapeHtml(totusAssetUrl(`${base}-928.webp`));
            return `${u640} 640w, ${u928} 928w`;
        }
        if (s === 'assets/home/solemnities_header_image.jpg') return '';
        const u = escapeHtml(totusAssetUrl(totusHomeCardWebpPath(rasterPath)));
        return `${u} 480w`;
    }

    /** Адметнасць шырыні для sizes у picture/img (сетка max-w 480px, gap 8px, px-2). */
    function homeCardImageSizes(span) {
        return span === 2
            ? '(max-width: 480px) calc(100vw - 1rem), min(464px, calc(100vw - 1rem))'
            : '(max-width: 480px) calc((100vw - 1rem - 8px) / 2), min(228px, calc((100vw - 1rem - 8px) / 2))';
    }

    /**
     * @param {{ fetchPriorityHigh?: boolean, decodeSync?: boolean }} [opts] — fetchPriorityHigh для магчымага LCP (картка «Пісанне» у сетцы).
     * Усе карты галоўнай бачныя адразу — без loading=lazy.
     */
    function homeCardPictureHtml(rasterPath, span, opts) {
        if (!rasterPath) return '';
        const high = opts && opts.fetchPriorityHigh;
        const sync = opts && opts.decodeSync;
        const sizes = escapeHtml(homeCardImageSizes(span));
        const src = escapeHtml(totusAssetUrl(rasterPath));
        const fetchPri = high ? ' fetchpriority="high"' : '';
        const decoding = sync ? 'sync' : 'async';
        const useWebpSource = /\.jpe?g$/i.test(String(rasterPath));
        if (!useWebpSource) {
            return `<img src="${src}" alt="" class="absolute inset-0 h-full w-full object-cover pointer-events-none" loading="eager" decoding="${decoding}" sizes="${sizes}"${fetchPri} />`;
        }
        const webpSrcset = homeCardWebpSrcset(rasterPath, span);
        if (!webpSrcset) {
            return `<img src="${src}" alt="" class="absolute inset-0 h-full w-full object-cover pointer-events-none" loading="eager" decoding="${decoding}" sizes="${sizes}"${fetchPri} />`;
        }
        return `<picture class="absolute inset-0 h-full w-full pointer-events-none">
            <source type="image/webp" srcset="${webpSrcset}" sizes="${sizes}" />
            <img src="${src}" alt="" class="absolute inset-0 h-full w-full object-cover pointer-events-none" loading="eager" decoding="${decoding}" sizes="${sizes}"${fetchPri} />
        </picture>`;
    }

    /** Лакальныя рэсурсы (PNG/JPEG/WebP, scripture/*.json) — ?v=TOTUS_WEB_APP_BUILD; API і закладкі не чапаем. */
    function totusAssetUrl(relativePath) {
        const v =
            window.TOTUS_WEB_APP_BUILD != null && String(window.TOTUS_WEB_APP_BUILD) !== ''
                ? String(window.TOTUS_WEB_APP_BUILD)
                : '';
        if (!relativePath) return relativePath;
        try {
            const u = new URL(relativePath, window.location.href);
            if (v) u.searchParams.set('v', v);
            return u.href;
        } catch {
            if (!v) return relativePath;
            const sep = String(relativePath).includes('?') ? '&' : '?';
            return `${relativePath}${sep}v=${encodeURIComponent(v)}`;
        }
    }

    /** versionName з meta totus-web-build (агульна з totus-app-version.properties). */
    function totusWebDisplayedVersion() {
        const v =
            window.TOTUS_WEB_APP_BUILD != null && String(window.TOTUS_WEB_APP_BUILD) !== ''
                ? String(window.TOTUS_WEB_APP_BUILD)
                : '';
        return v || '0';
    }

    const BE_MONTH_GEN = [
        '',
        'студзеня',
        'лютага',
        'сакавіка',
        'красавіка',
        'мая',
        'чэрвеня',
        'ліпеня',
        'жніўня',
        'верасня',
        'кастрычніка',
        'лістапада',
        'снежня',
    ];

    /** Назоўны склон для загалоўка месяца ў календары (Luxon CDN без be-локалі дае англійскія назвы). */
    const BE_MONTH_NOM = [
        '',
        'студзень',
        'люты',
        'сакавік',
        'красавік',
        'май',
        'чэрвень',
        'ліпень',
        'жнівень',
        'верасень',
        'кастрычнік',
        'лістапад',
        'снежань',
    ];

    function readCalendarDioceseToggles() {
        const empty = { pinskaya: false, minsk_mogilev: false, vitebskaya: false, grodzenskaya: false };
        try {
            const raw = localStorage.getItem(CALENDAR_DIOCESES_KEY);
            if (!raw) return { ...empty };
            const o = JSON.parse(raw);
            if (!o || typeof o !== 'object') return { ...empty };
            return {
                pinskaya: !!o.pinskaya,
                minsk_mogilev: !!o.minsk_mogilev,
                vitebskaya: !!o.vitebskaya,
                grodzenskaya: !!o.grodzenskaya,
            };
        } catch {
            return { ...empty };
        }
    }

    function writeCalendarDioceseToggles(t) {
        try {
            localStorage.setItem(CALENDAR_DIOCESES_KEY, JSON.stringify(t));
        } catch {
            /* ignore */
        }
    }

    /** GET dioceses= для API (як liturgy_calendar_diocese_options_from_request). */
    function calendarDiocesesApiParam() {
        const t = readCalendarDioceseToggles();
        const p = [];
        if (t.pinskaya) p.push('pinskaya');
        if (t.minsk_mogilev) p.push('minsk_mogilev');
        if (t.vitebskaya) p.push('vitebskaya');
        if (t.grodzenskaya) p.push('grodzenskaya');
        return p.join(',');
    }

    function calendarDiocesesTitleSuffix() {
        const t = readCalendarDioceseToggles();
        const selected = [];
        if (t.pinskaya) selected.push('Пінская');
        if (t.minsk_mogilev) selected.push('Мінска-магілёўская');
        if (t.vitebskaya) selected.push('Віцебская');
        if (t.grodzenskaya) selected.push('Гродзенская');
        if (selected.length === 0) return 'Агульны';
        if (selected.length === 1) return `${selected[0]} дыяцэзія`;
        if (selected.length === 2) return `${selected[0]} і ${selected[1]} дыяцэзіі`;
        return `${selected.slice(0, -1).join(', ')} і ${selected[selected.length - 1]} дыяцэзіі`;
    }

    /** Поўнаэкранная старонка наладаў (як settings), не модальнае акно. */
    function calendarSettingsShellHtml() {
        const t = readCalendarDioceseToggles();
        return `
    <div id="calendar-settings-root" class="max-w-[480px] mx-auto px-2 pb-8 pt-2">
        <section class="rounded-md border border-app-stroke bg-app-elevated p-[18px] space-y-3">
            <p class="text-[13px] text-app-textSec leading-relaxed">Калі не паставіць галачкі, то паказваецца агульны Рымскі каляндар. Абярыце дыяцэзіі, каб бачыць іх мясцовыя святы.</p>
            ${dioceseSettingsCheckboxListHtml(t, 'calendar')}
        </section>
    </div>`;
    }

    function dioceseSettingsCheckboxListHtml(t, scope) {
        const row = (id, label) => {
            const c = t[id] ? ' checked' : '';
            return `<label class="flex items-start gap-3 py-2.5 cursor-pointer">
                <input type="checkbox" data-${scope}-diocese-toggle="${id}" class="mt-1 rounded border-app-stroke"${c} />
                <span class="text-[15px] text-app-text leading-snug">${label}</span>
            </label>`;
        };
        return `<div class="rounded-lg border border-app-stroke/80 bg-app-bg2/40 px-3 divide-y divide-app-stroke/50">
                ${row('pinskaya', 'Паказваць святы: Пінская дыяцэзія')}
                ${row('minsk_mogilev', 'Паказваць святы: Мінска-магілёўская архідыяцэзія')}
                ${row('vitebskaya', 'Паказваць святы: Віцебская дыяцэзія')}
                ${row('grodzenskaya', 'Паказваць святы: Гродзенская дыяцэзія')}
            </div>`;
    }

    function formatCalendarMonthYearBe(dt) {
        const d = dt instanceof luxon.DateTime ? dt : luxon.DateTime.fromISO(String(dt));
        if (!d.isValid) return '';
        const name = BE_MONTH_NOM[d.month] || '';
        if (!name) return String(d.year);
        const cap = name.charAt(0).toUpperCase() + name.slice(1);
        return `${cap} ${d.year}`;
    }

    const TEXT_STEP_MIN = 0;
    const TEXT_STEP_MAX = 4;
    const TEXT_SCALE_STEP_DELTA = 0.25;
    /** Як AppGlobalTextScaleStore.DEFAULT_BASE_SCALE — крок 1 ужывае гэты множнік. */
    const TEXT_READ_BASE_SCALE = 0.925;

    /** Тэкст узору ў наладах — як strings.xml settings_global_text_preview_sample (Android). */
    const SETTINGS_TEXT_PREVIEW_SAMPLE =
        'Той, хто сядзеў на троне, сказаў: «Вось чыню ўсё новае». (Апакаліпсіс 21:5)';

    /** Як у scriptureEnsureAllTranslationMeta() — калі API не вяртае спіс (стары скрыпт, памылка), абраць пераклад усё адно магчыма. */
    const SCRIPTURE_TRANSLATIONS_FALLBACK = [
        { id: 'catholic_nt', title: 'Новы Запавет Рыма-Каталіцкага Касцёла' },
        { id: 'bokun', title: 'Біблія ў перакладзе Антонія Бокуна' },
        { id: 'semiukha', title: 'Біблія беларуская ў перакладзе Сёмухі' },
        { id: 'charniauski_2017', title: 'Пераклад Уладзіслава Чарняўскага 2017' },
        { id: 'stankevich', title: 'Сьвятая Бібля у перакладзе Яна Станкевіча' },
        { id: 'synodal_ru', title: 'Синодальный перевод Библии' },
    ];

    /** Як ScriptureCatalog.shortTitle у Android (шапка: «Святое Пісанне: BCAT»). */
    const SCRIPTURE_TR_SHORT = {
        catholic_nt: 'BCAT',
        bokun: 'BBB',
        semiukha: 'BBS',
        charniauski_2017: 'BVC-2017',
        stankevich: 'BJS',
        synodal_ru: 'SYN',
    };

    let prayersCache = null;
    let categoriesCache = null;
    let prayersLoadError = null;
    /** true калі апошні збой загрузкі малітваў — сетка / fetch, не HTTP-паводзіны сервера */
    let prayersLoadErrorIsNetwork = false;

    let songbookCache = null;
    let kantaralCache = null;
    let songbookLoadError = null;
    let kantaralLoadError = null;
    let songbookLoadErrorIsNetwork = false;
    let kantaralLoadErrorIsNetwork = false;
    /** Рэжым спісу «Выбранае (спеўнік)», як nav_songbook_bookmarked у Android. */
    let songbookBookmarksOnly = false;
    /** list | search — як пераход паміж SongbookListFragment і SongbookSearchFragment. */
    let songbookView = 'list';
    let songbookSearchQuery = '';
    let songbookSearchDebounceTimer = null;

    function isSongbookLikeView() {
        return currentView === 'songbook' || currentView === 'kantaral';
    }

    function songbookSectionTitle() {
        return currentView === 'kantaral' ? 'Кантарал' : 'Спеўнік';
    }

    let ordoMissaeSearchQuery = '';
    let ordoMissaeSearchDebounceTimer = null;
    let ordoMissaeSearchResults = [];
    let ordoMissaeSearchIndex = -1;

    /** Спіс перакладаў, для якіх ёсць лакальныя JSON (bundled_translations.json + scripture_catalog.json). */
    let scriptureTranslationsList = null;
    /** id → { id, title, description } з scripture_catalog.json */
    let scriptureCatalogById = null;
    let scriptureCatalogPromise = null;
    /** Парадак і id ўбудаваных перакладаў з bundled_translations.json */
    let scriptureBundledIds = null;
    let scriptureBundledPromise = null;
    let scriptureData = null;
    let scriptureSelectedId = null;
    let scBookIdx = null;
    let scChapterNum = null;
    /** Экран выбару перакладу з шасцярэнкі ў шапцы (як ScriptureTranslationsFragment). */
    let scTranslationSettingsOpen = false;
    /** null | 'favorites' | 'compare' | 'word_search' — як nav_scripture_favorites / compare / word_search. */
    let scPanel = null;
    /** Пракрутка да верша пасля адкрыцця з «Выбраныя» (як ARG_FOCUS_VERSE). */
    let scFocusVerse = null;
    /** Чарнавік поля пошуку па Пісанні (як у ScriptureWordSearchFragment). */
    let scriptureWordSearchQuery = '';
    let scriptureWordSearchRunId = 0;
    let scriptureWordSearchDebounceTimer = null;
    let scriptureWordSearchScheduleGen = 0;

    const app = document.getElementById('app');
    if (!app) {
        return;
    }

    /** Прамы зварот да WebPanel/api (ключ у браўзеры — толькі калі не useServerProxy). */
    function getResolvedDirectApiBase() {
        const c = window.API_CONFIG || {};
        let raw = c.apiBaseUrl;
        if (raw === undefined || raw === null || String(raw) === '' || String(raw) === 'auto') {
            const path = window.location.pathname || '/';
            const marker = '/WebApp';
            const pos = path.indexOf(marker);
            if (pos >= 0) {
                return (path.slice(0, pos) || '') + '/WebPanel/api';
            }
            raw = '../WebPanel/api';
        }
        const s = String(raw).replace(/\/$/, '');
        if (/^https?:\/\//i.test(s)) {
            return s;
        }
        try {
            return new URL(s + '/', window.location.href).href.replace(/\/$/, '');
        } catch {
            return s;
        }
    }

    /** Серверны проксі WebApp/api/index.php — ключ толькі на серверы. */
    function getResolvedProxyBase() {
        const path = window.location.pathname || '/';
        const marker = '/WebApp';
        const pos = path.indexOf(marker);
        let absPath;
        if (pos >= 0) {
            absPath = (path.slice(0, pos) || '') + marker + '/api';
        } else {
            const dir = path.replace(/\/[^/]*$/, '') || '';
            absPath = `${dir.replace(/\/?$/, '')}/api`.replace(/\/+/g, '/');
            if (!absPath.startsWith('/')) absPath = '/' + absPath;
        }
        try {
            return new URL(absPath, window.location.origin).href.replace(/\/$/, '');
        } catch {
            return absPath.replace(/\/$/, '');
        }
    }

    /** Карэнь WebPanel для медыяфайлаў (калі API ідзе праз проксі, apiBaseUrl = WebApp/api). */
    function getResolvedWebPanelRoot() {
        const c = window.API_CONFIG || {};
        let raw = c.webPanelRootUrl;
        if (raw != null && String(raw).trim() !== '') {
            const s = String(raw).replace(/\/$/, '');
            if (/^https?:\/\//i.test(s)) return s;
            try {
                return new URL(s + '/', window.location.href).href.replace(/\/$/, '');
            } catch {
                return s;
            }
        }
        const path = window.location.pathname || '/';
        const marker = '/WebApp';
        const pos = path.indexOf(marker);
        if (pos >= 0) {
            const p = (path.slice(0, pos) || '') + '/WebPanel';
            try {
                const tail = p.startsWith('/') ? p.slice(1) : p;
                return new URL(tail + '/', window.location.origin).href.replace(/\/$/, '');
            } catch {
                return p;
            }
        }
        try {
            const base = window.location.href.replace(/[^/]*$/, '');
            return new URL('../WebPanel/', base).href.replace(/\/$/, '');
        } catch {
            return '../WebPanel';
        }
    }

    function getApiConfig() {
        const c = window.API_CONFIG || {};
        const useServerProxy = !!c.useServerProxy;
        return {
            apiBaseUrl: useServerProxy ? getResolvedProxyBase() : getResolvedDirectApiBase(),
            apiKey: useServerProxy ? '' : String(c.apiKey || ''),
            useServerProxy,
        };
    }

    function isApiConfigured() {
        const x = getApiConfig();
        return x.useServerProxy || (x.apiKey !== '' && String(x.apiKey).trim() !== '');
    }

    function configBannerHtml() {
        return `
        <div class="shrink-0 bg-amber-950/90 border-b border-amber-700/50 text-amber-100 px-4 py-3 text-sm text-center">
            <strong>Канфігурацыя:</strong> уключыце <code class="bg-black/30 px-1 rounded">useServerProxy: true</code> у <code class="bg-black/30 px-1 rounded">api-config.js</code>
            і наладзьце <code class="bg-black/30 px-1 rounded">WebApp/api/proxy-secrets.php</code>, альбо ўпішыце <code class="bg-black/30 px-1 rounded">apiKey</code> для прамога доступу да API.
            Шаблоны: <code class="bg-black/30 px-1 rounded">api-config.example.js</code>, <code class="bg-black/30 px-1 rounded">api/proxy-secrets.example.php</code>
        </div>`;
    }

    /** Аднолькавыя GET у поле — адзін сеткавы запыт (паралельныя выклікі чакаюць той самы Promise). */
    const apiFetchInflight = new Map();

    async function apiFetch(scriptName, params) {
        const { apiBaseUrl, apiKey, useServerProxy } = getApiConfig();
        const search = new URLSearchParams(params || {});
        const headers = { Accept: 'application/json' };
        let url;
        if (useServerProxy) {
            search.set('totus_route', scriptName);
            url = `${apiBaseUrl}/index.php?${search.toString()}`;
        } else {
            const q = search.toString();
            url = q ? `${apiBaseUrl}/${scriptName}?${q}` : `${apiBaseUrl}/${scriptName}`;
            if (apiKey) headers['X-Totus-Api-Key'] = apiKey;
        }

        const inflight = apiFetchInflight.get(url);
        if (inflight) return inflight;

        const promise = (async () => {
            try {
                const fetchOpts = { method: 'GET', headers };
                if (scriptName === 'ordo_missae.php') {
                    fetchOpts.cache = 'no-store';
                }
                const res = await fetch(url, fetchOpts);
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch {
                    data = { error: 'invalid_json', message: text.slice(0, 400) };
                }
                return { ok: res.ok, status: res.status, data };
            } catch (e) {
                const msg = e instanceof Error ? e.message : String(e);
                return {
                    ok: false,
                    status: 0,
                    data: { error: 'network_error', message: msg },
                };
            } finally {
                apiFetchInflight.delete(url);
            }
        })();

        apiFetchInflight.set(url, promise);
        return promise;
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** Як looksLikeHtml у PrayerDetailFragment.kt / SongbookDetailFragment.kt. */
    function stringLooksLikeHtmlFragment(value) {
        return /<\s*\/?\s*[a-zA-Z][^>]*>/.test(String(value || ''));
    }

    /**
     * Значок параўнання — тыя ж path, што ic_compare_24 / ic_compare_outline_24 (Android vector).
     * @param {boolean} filled true — абрадзены як у спісе параўнання; false — контур (другі стралок з празрыстасцю).
     */
    function scriptureCompareIconSvg(filled, extraClass = '') {
        const cls = String(extraClass || '').trim();
        const classAttr = cls ? ` class="${cls}"` : '';
        /* 1.125rem у шапцы × 1.5 — памер як дамаўляліся з карыстальнікам */
        const sz = 'width="1.6875rem" height="1.6875rem"';
        if (filled) {
            return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"${classAttr} ${sz} fill="currentColor" aria-hidden="true" focusable="false"><path d="M10,3L6,7l4,4V8h7V6h-7V3zM14,13v3H7v2h7v3l4,-4l-4,-4z"/></svg>`;
        }
        return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"${classAttr} ${sz} aria-hidden="true" focusable="false"><path fill="currentColor" d="M10,3L6,7l4,4V8h7V6h-7V3z"/><path fill="currentColor" fill-opacity="0.55" d="M14,13v3H7v2h7v3l4,-4l-4,-4z"/></svg>`;
    }

    /**
     * Плоскі тэкст малітвы (без HTML): «шум» ад пашырэнняў, br/div/p → пераносы, астатнія тэгі выдаляюцца.
     * Калі ў змесце ёсць HTML з стылямі (колер і г.д.), глядзі sanitizePrayerHtmlForWebDisplay + stringLooksLikeHtmlFragment.
     */
    function normalizePrayerTextForDisplay(raw) {
        let t = String(raw ?? '');
        if (!t) return '';
        t = t.replace(/\s*bis_skin_checked\s*=\s*"[^"]*"/gi, '');
        t = t.replace(/\s*bis_skin_checked\s*=\s*'[^']*'/gi, '');
        t = t.replace(/<\s*br\s*\/?>/gi, '\n');
        t = t.replace(/<\/\s*(div|p)\s*>/gi, '\n');
        t = t.replace(/<[^>]+>/g, '');
        const ta = document.createElement('textarea');
        ta.innerHTML = t.trim();
        t = ta.value;
        t = t.replace(/\r\n/g, '\n');
        // Пасля HTML часта «прыляпляюцца» пункты: «…Бог.2. Бог…» — ставім перанос перад нумарам
        t = t.replace(/([.!?])(\d{1,2}\.\s)/g, '$1\n$2');
        return t.replace(/\n{3,}/g, '\n\n').trim();
    }

    /**
     * Як sanitizePrayerHtmlPreserveLayout + stripUnsafeWebContent у PrayerDetailFragment.kt:
     * захоўваем span[style*=color], font[color], разметку; выдаляем небяспечнае і рэдактарскі шум.
     */
    /** Толькі для Ordo: TinyMCE часта піша font-size у style — ламае А±. */
    function stripOrdoInlineFontSizesFromHtml(html) {
        let s = String(html || '');
        s = s.replace(/\bfont-size\s*:\s*[^;]+;?/gi, '');
        s = s.replace(/;\s*;+/g, ';');
        s = s.replace(/\sstyle\s*=\s*"\s*;*\s*"/gi, '');
        s = s.replace(/\sstyle\s*=\s*'\s*;*\s*'/gi, '');
        return s;
    }

    /** Прыбраць open у <details … ordo-missae-section …> — па змаўчанні ўсё згорнута. */
    function stripOrdoDetailsOpenFromHtml(html) {
        return String(html || '').replace(/<details\b[^>]*>/gi, (tag) => {
            if (!/ordo-missae-section/i.test(tag)) return tag;
            let t = tag.replace(/\s+open\s*=\s*(?:"[^"]*"|'[^']*')/gi, '');
            t = t.replace(/\s+open\b(?=\s|>)/gi, '');
            return t;
        });
    }

    /** Толькі светлыя колеры (як Android stripLightEditorTextColorsForOrdo); чырвоныя рубрыкі захоўваюцца. */
    function stripOrdoLightTextColorsFromHtml(html) {
        let s = String(html || '');
        const imp = '(?:\\s*!important)?';
        const colorDecl = [
            `\\bcolor\\s*:\\s*#fff\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#ffffff\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#fefefe\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#f0f0f0\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#f4f4f4\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#f5f5f5\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#fafafa\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#eeeeee\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#e8e8e8\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*#e0e0e0\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*white\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*ivory\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*snow\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*ghostwhite\\b${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*rgb\\s*\\(\\s*255\\s*,\\s*255\\s*,\\s*255\\s*\\)${imp}\\s*;?`,
            `\\bcolor\\s*:\\s*rgba\\s*\\(\\s*255\\s*,\\s*255\\s*,\\s*255\\s*,\\s*[\\d.]+\\s*\\)${imp}\\s*;?`,
            `\\b-webkit-text-fill-color\\s*:\\s*#fff\\b${imp}\\s*;?`,
            `\\b-webkit-text-fill-color\\s*:\\s*#ffffff\\b${imp}\\s*;?`,
            `\\b-webkit-text-fill-color\\s*:\\s*white\\b${imp}\\s*;?`,
            `\\b-webkit-text-fill-color\\s*:\\s*rgb\\s*\\(\\s*255\\s*,\\s*255\\s*,\\s*255\\s*\\)${imp}\\s*;?`,
        ];
        for (const pat of colorDecl) {
            s = s.replace(new RegExp(pat, 'gi'), '');
        }
        s = s.replace(
            /(<font\b[^>]*?)\s+color\s*=\s*["']?(?:#fff(?:fff)?|#fefefe|#f5f5f5|#eeeeee|#e0e0e0|white|ivory|snow)["']?/gi,
            '$1',
        );
        s = s.replace(/;\s*;+/g, ';');
        s = s.replace(/\sstyle\s*=\s*"\s*;*\s*"/gi, '');
        s = s.replace(/\sstyle\s*=\s*'\s*;*\s*'/gi, '');
        return s;
    }

    function sanitizePrayerHtmlForWebDisplay(raw) {
        let s = String(raw ?? '');
        if (!s) return '';
        s = s.replace(/\s*bis_skin_checked\s*=\s*"[^"]*"/gi, '');
        s = s.replace(/\s*bis_skin_checked\s*=\s*'[^']*'/gi, '');
        s = s.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
        s = s.replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '');
        s = s.replace(/<script\b[^>]*\/>/gi, '');
        s = s.replace(/<iframe\b[^>]*\/>/gi, '');
        s = s.replace(/<head\b[^>]*>[\s\S]*?<\/head>/gi, '');
        s = s.replace(/<meta\b[^>]*\/?>/gi, '');
        s = s.replace(/<title\b[^>]*>[\s\S]*?<\/title>/gi, '');
        s = s.replace(/<link\b[^>]*\/?>/gi, '');
        s = s.replace(/<base\b[^>]*\/?>/gi, '');
        s = s.replace(/\s+on\w+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '');
        s = s.replace(/\s(href|src)\s*=\s*(["'])\s*javascript:[^"']*\2/gi, ' $1=$2#$2');
        return s.trim();
    }

    /** Падказка пры праблемах сеткі (без спасылкі на канкрэтны PHP — карыстальнік ужо бачыць адрас у наладах сервера). */
    function apiNetworkFailureHint(apiBaseUrl) {
        const chunks = [];
        const baseLine = `<p><strong>База API зараз:</strong> <code class="bg-black/30 px-1 rounded break-all text-[11px]">${escapeHtml(String(apiBaseUrl || '—'))}</code></p>`;
        chunks.push(baseLine);

        if (window.location.protocol === 'https:' && /^http:\/\//i.test(apiBaseUrl)) {
            chunks.push(
                '<p><strong>Змешаны змест:</strong> старонка адкрыта па HTTPS, а ў <code>api-config.js</code> пазначаны API па HTTP — браўзер блакуе запыт. Пастаўце ў <code>apiBaseUrl</code> адрас з <strong>https://</strong> (уключыце SSL для дамена API).</p>'
            );
        }

        if (window.location.protocol === 'file:') {
            chunks.push(
                '<p>Адкрыта праз <strong>file://</strong> — зварот да вонкавага API часта блакіруецца. Адкрыйце сайт праз хасцінг (<code>http://</code> / <code>https://</code>) або ўсталюйце ў <code>api-config.js</code> <code>apiBaseUrl: \'auto\'</code>, калі WebApp і WebPanel на адным серверы.</p>'
            );
        }

        chunks.push(
            '<p>Праверце інтэрнэт, адрас у <code>api-config.js</code> і што пратакол супадае са старонкай (напрыклад HTTPS + HTTPS). У інструментах распрацоўшчыка (Укладка «Сетка») паглядзіце, ці не блакуе запыт бяспека або пашырэнне.</p>'
        );

        return `<div class="mt-3 space-y-2 text-xs leading-relaxed text-app-textSec">${chunks.join('')}</div>`;
    }

    function readTextStep() {
        try {
            const n = Number(localStorage.getItem(TEXT_STEP_KEY));
            if (Number.isFinite(n)) return Math.max(TEXT_STEP_MIN, Math.min(TEXT_STEP_MAX, n));
        } catch {
            /* ignore */
        }
        return 0;
    }

    function writeTextStep(step) {
        const s = Math.max(TEXT_STEP_MIN, Math.min(TEXT_STEP_MAX, step));
        try {
            localStorage.setItem(TEXT_STEP_KEY, String(s));
        } catch {
            /* ignore */
        }
        return s;
    }

    /** Множнік чытання як readScale() у Android; UI на фіксаваным rem (html 16px). */
    function applyTextStep(stepIndex) {
        const step = Math.max(TEXT_STEP_MIN, Math.min(TEXT_STEP_MAX, stepIndex));
        const scale = TEXT_READ_BASE_SCALE * (1 + step * TEXT_SCALE_STEP_DELTA);
        const root = document.documentElement;
        root.style.fontSize = '16px';
        root.style.setProperty('--totus-read-scale', String(scale));
    }

    /** Без renderApp() — толькі падпіс і кнопкі ў наладах (пазбягаем «дрыжанняў» ад поўнага перамалёўкі DOM). */
    function syncSettingsTextStepUi() {
        if (currentView !== 'settings') return;
        const step = readTextStep();
        const label = document.getElementById('settings-text-step-label');
        if (label) label.textContent = `Крок ${step + 1} з 5`;
        const smaller = document.getElementById('settings-font-smaller');
        const larger = document.getElementById('settings-font-larger');
        if (smaller) smaller.disabled = step <= TEXT_STEP_MIN;
        if (larger) larger.disabled = step >= TEXT_STEP_MAX;
    }

    /** Сінхранізацыя выпаднага спіса шрыфта ў наладах пасля reset / знешняй змены. */
    function syncSettingsFontFamilyUi() {
        if (currentView !== 'settings') return;
        const ff = readFontFamily();
        const sel = document.getElementById('settings-font-select');
        if (sel && (ff === 'sans' || ff === 'serif' || ff === 'mono')) {
            sel.value = ff;
        }
        const presetRow = SETTINGS_FONT_ROWS.find((r) => r.id === ff) || SETTINGS_FONT_ROWS[0];
        const trigLab = document.getElementById('settings-font-trigger-label');
        if (trigLab) trigLab.textContent = presetRow.title;
        document.querySelectorAll('[data-settings-font-option]').forEach((btn) => {
            const oid = String(btn.dataset.settingsFontOption);
            const picked = oid === ff;
            btn.setAttribute('aria-selected', picked ? 'true' : 'false');
            btn.className =
                'settings-font-option-btn w-full text-left px-4 py-3 text-sm border-0 cursor-pointer transition-colors border-solid ' +
                (picked ? 'bg-app-surface text-app-text font-medium' : 'bg-transparent text-app-text hover:bg-white/[0.06]');
        });
    }

    function syncSettingsThemeUi() {
        if (currentView !== 'settings') return;
        const t = readAppTheme();
        const sel = document.getElementById('settings-theme-select');
        if (sel) sel.value = t;
        document.querySelectorAll('[data-settings-theme-switch]').forEach((btn) => {
            const id = String(btn.dataset.settingsThemeSwitch || '');
            const picked = id === t;
            btn.setAttribute('aria-pressed', picked ? 'true' : 'false');
            btn.className =
                'settings-theme-switch-btn flex-1 min-w-0 px-4 py-2.5 rounded-lg text-sm font-medium cursor-pointer border-solid transition-colors ' +
                (picked
                    ? 'bg-app-surface text-app-text border-2 border-app-stroke'
                    : 'bg-app-bg2 text-app-textSec border border-app-stroke hover:bg-white/[0.04]');
        });
    }

    /** Як SettingsFragment.buttonSettingsResetTextDefaults: крок 1 памера + Sans. */
    function resetTextAndFontToDefaults() {
        writeTextStep(TEXT_STEP_MIN);
        writeFontFamily('sans');
        applyTextStep(readTextStep());
        applyFontFamily();
        settingsFontPanelClose();
        syncSettingsTextStepUi();
        syncSettingsFontFamilyUi();
        syncReadingTextToolbarButtons();
        scheduleToolbarTitleFit();
    }

    function readFontFamily() {
        try {
            const v = localStorage.getItem(FONT_FAMILY_KEY);
            if (v === 'sans' || v === 'serif' || v === 'mono') return v;
        } catch {
            /* ignore */
        }
        return 'sans';
    }

    function writeFontFamily(fam) {
        try {
            localStorage.setItem(FONT_FAMILY_KEY, fam);
        } catch {
            /* ignore */
        }
    }

    function readAppTheme() {
        try {
            const v = localStorage.getItem(APP_THEME_KEY);
            if (v === 'current' || v === 'dark') return 'current';
            if (v === 'beige' || v === 'white' || v === 'light') return 'beige';
        } catch {
            /* ignore */
        }
        return 'current';
    }

    function writeAppTheme(theme) {
        try {
            localStorage.setItem(APP_THEME_KEY, theme);
        } catch {
            /* ignore */
        }
    }

    function applyAppTheme() {
        document.documentElement.setAttribute('data-app-theme', readAppTheme());
    }

    /** Сінхранізуе з CSS у index.html (html[data-app-font]). */
    function applyFontFamily() {
        const fam = readFontFamily();
        document.documentElement.setAttribute('data-app-font', fam);
    }

    const SETTINGS_FONT_ROWS = [
        { id: 'sans', title: 'Sans (без засечак)' },
        { id: 'serif', title: 'Serif (з засечкамі)' },
        { id: 'mono', title: 'Monospace' },
    ];
    const SETTINGS_THEME_ROWS = [
        { id: 'current', title: 'Цёмная' },
        { id: 'beige', title: 'Светлая' },
    ];

    /** Толькі дата без дня тыдня і без колеру: «27 сакавіка 2026». */
    function formatDateDayMonthYearBe(dt) {
        const d = dt instanceof luxon.DateTime ? dt : luxon.DateTime.fromISO(String(dt));
        if (!d.isValid) return '—';
        const month = BE_MONTH_GEN[d.month] || '';
        return `${d.day} ${month} ${d.year}`;
    }

    function humanizeClientFetchError(msg) {
        const m = String(msg || '').trim();
        if (
            /failed to fetch|networkerror|load failed|aborted|збой сеткі|certificate|ssl|tls|timed out|timeout|err_connection|net::/i.test(
                m
            )
        ) {
            return 'Не атрымалася звязацца з серверам. Праверце інтэрнэт, адрас API ў api-config.js і адпаведнасць пратаколу (HTTPS).';
        }
        return m;
    }

    function getBookmarksSet() {
        try {
            const raw = localStorage.getItem(BOOKMARKS_KEY);
            const arr = raw ? JSON.parse(raw) : [];
            return new Set(Array.isArray(arr) ? arr.map(Number) : []);
        } catch {
            return new Set();
        }
    }

    function saveBookmarksSet(set) {
        localStorage.setItem(BOOKMARKS_KEY, JSON.stringify([...set]));
    }

    function getSongbookBookmarksSet() {
        try {
            const raw = localStorage.getItem(SONGBOOK_BOOKMARKS_KEY);
            const arr = raw ? JSON.parse(raw) : [];
            return new Set(Array.isArray(arr) ? arr.map(Number) : []);
        } catch {
            return new Set();
        }
    }

    function saveSongbookBookmarksSet(set) {
        localStorage.setItem(SONGBOOK_BOOKMARKS_KEY, JSON.stringify([...set]));
    }

    function toggleSongbookBookmark(id) {
        const s = getSongbookBookmarksSet();
        const n = Number(id);
        if (s.has(n)) s.delete(n);
        else s.add(n);
        saveSongbookBookmarksSet(s);
        if (isSongbookLikeView() && songbookDetailId != null) {
            syncSongbookDetailToolbarBookmark();
        }
    }

    /** Іконка закладкі ў шапцы на экране запісу спеўніка (як у Android menu_songbook_detail). */
    function syncSongbookDetailToolbarBookmark() {
        if (!isSongbookLikeView() || songbookDetailId == null) return;
        const id = Number(songbookDetailId);
        const btn = document.querySelector('[data-action="toolbar-songbook-detail-bookmark"]');
        if (!btn || !Number.isFinite(id)) return;
        const ic = btn.querySelector('i');
        if (!ic) return;
        const sbm = getSongbookBookmarksSet();
        ic.className = (sbm.has(id) ? 'fas fa-bookmark text-amber-400' : 'far fa-bookmark') + ' text-lg';
    }

    function toggleBookmark(id) {
        const s = getBookmarksSet();
        if (s.has(id)) s.delete(id);
        else s.add(id);
        saveBookmarksSet(s);
        if (currentView === 'prayers') {
            if (prayerNav.screen === 'detail') {
                renderPrayerDetail();
                syncPrayerDetailToolbarBookmark();
            } else if (prayerView === 'search') {
                hydratePrayerSearchResults();
            } else renderPrayerBrowse();
        }
    }

    /** Іконка закладкі ў шапцы на экране малітвы (як toolbar_prayer_detail_actions у Android). */
    function syncPrayerDetailToolbarBookmark() {
        if (currentView !== 'prayers' || prayerNav.screen !== 'detail') return;
        const id = Number(prayerNav.prayerId);
        const btn = document.querySelector('[data-action="toolbar-prayer-detail-bookmark"]');
        if (!btn || !Number.isFinite(id)) return;
        const ic = btn.querySelector('i');
        if (!ic) return;
        const bm = getBookmarksSet();
        ic.className = (bm.has(id) ? 'fas fa-bookmark text-amber-400' : 'far fa-bookmark') + ' text-lg';
    }

    /** Як PrayerRepository у Android: слуцельны радок для «няма падкатэгорыі» пры фільтры. */
    const NO_CATEGORY_TITLE = 'Без катэгорыі';
    const NO_SUBCATEGORY_TITLE = 'Без подкатегории';

    function normCat(value) {
        return String(value || '').trim().toLowerCase();
    }

    function prayerAdditionalParts(p) {
        const a = p.additional_categories;
        if (!a) return [];
        return String(a)
            .split(',')
            .map((x) => x.trim())
            .filter(Boolean);
    }

    function getCategoryNamesFromData(prayers, meta) {
        const fromPrayers = [
            ...new Set(
                (prayers || []).map((p) => p.category).filter((x) => x && String(x).trim())
            ),
        ];
        const normPrayerRoots = new Set(fromPrayers.map(normCat));
        const roots = (meta || [])
            .filter((r) => r.parent_id == null || r.parent_id === '')
            .sort((a, b) => {
                const so = Number(a.sort_order) - Number(b.sort_order);
                if (so !== 0) return so;
                return Number(a.id) - Number(b.id);
            });
        if (roots.length === 0) {
            return [...fromPrayers].sort((a, b) => a.localeCompare(b, 'be', { sensitivity: 'base' }));
        }
        const seenNorm = new Set();
        const ordered = [];
        for (const node of roots) {
            const n = normCat(node.name);
            if (normPrayerRoots.has(n) && !seenNorm.has(n)) {
                ordered.push(fromPrayers.find((x) => normCat(x) === n));
                seenNorm.add(n);
            }
        }
        const orphans = fromPrayers
            .filter((x) => !seenNorm.has(normCat(x)))
            .sort((a, b) => a.localeCompare(b, 'be', { sensitivity: 'base' }));
        const base = [...ordered, ...orphans];
        const hasUncategorized = (prayers || []).some((p) => !p.category || !String(p.category).trim());
        return hasUncategorized ? [NO_CATEGORY_TITLE, ...base] : base;
    }

    function getSubcategoryNamesFromData(prayers, meta, category) {
        if (category === NO_CATEGORY_TITLE) return [];
        const parentNorm = normCat(category);
        const fromPrayers = [
            ...new Set(
                (prayers || [])
                    .filter((p) => normCat(p.category) === parentNorm)
                    .map((p) => p.subcategory)
                    .filter((x) => x && String(x).trim())
            ),
        ];
        const normSet = new Set(fromPrayers.map(normCat));
        const parentNode = (meta || []).find(
            (r) => (r.parent_id == null || r.parent_id === '') && normCat(r.name) === parentNorm
        );
        if (!parentNode) {
            return [...fromPrayers].sort((a, b) => a.localeCompare(b, 'be', { sensitivity: 'base' }));
        }
        const children = (meta || [])
            .filter((r) => Number(r.parent_id) === Number(parentNode.id))
            .sort((a, b) => {
                const so = Number(a.sort_order) - Number(b.sort_order);
                if (so !== 0) return so;
                return Number(a.id) - Number(b.id);
            });
        const seenNorm = new Set();
        const ordered = [];
        for (const node of children) {
            const n = normCat(node.name);
            if (normSet.has(n) && !seenNorm.has(n)) {
                ordered.push(fromPrayers.find((x) => normCat(x) === n));
                seenNorm.add(n);
            }
        }
        const orphans = fromPrayers
            .filter((x) => !seenNorm.has(normCat(x)))
            .sort((a, b) => a.localeCompare(b, 'be', { sensitivity: 'base' }));
        return [...ordered, ...orphans];
    }

    function getPrayersInSubcategoryJs(prayers, category, subcategory) {
        if (category === NO_CATEGORY_TITLE) {
            return (prayers || [])
                .filter((p) => !p.category || !String(p.category).trim())
                .sort((a, b) => {
                    const s = Number(a.sort_order) - Number(b.sort_order);
                    if (s !== 0) return s;
                    return Number(a.id) - Number(b.id);
                });
        }
        const normalizedCategory = normCat(category);
        return (prayers || [])
            .filter((p) => {
                const primaryMatch = normCat(p.category) === normalizedCategory;
                const addParts = prayerAdditionalParts(p);
                const additionalMatch = addParts.some((addCategory) => normCat(addCategory) === normalizedCategory);
                const additionalSubcategoryMatch =
                    subcategory !== NO_SUBCATEGORY_TITLE &&
                    addParts.some((addCategory) => normCat(addCategory) === normCat(subcategory));
                const primarySubcategoryMatch =
                    subcategory === NO_SUBCATEGORY_TITLE
                        ? !p.subcategory || !String(p.subcategory).trim()
                        : normCat(p.subcategory) === normCat(subcategory);
                return (
                    (primaryMatch && primarySubcategoryMatch) ||
                    (additionalMatch && subcategory === NO_SUBCATEGORY_TITLE) ||
                    additionalSubcategoryMatch
                );
            })
            .sort((a, b) => {
                const s = Number(a.sort_order) - Number(b.sort_order);
                if (s !== 0) return s;
                return Number(a.id) - Number(b.id);
            });
    }

    /** Навігацыя малітоўніка: каранёвыя катэгорыі → падкатэгорыі → спіс малітваў (як TransformFragment у дадатку). */
    let prayerNav = { screen: 'categories' };
    /** list | search — як songbookView у спеўніку. */
    let prayerView = 'list';
    let prayerSearchDebounceTimer = null;
    /** Перад экранам малітвы: каб «Назад» вярнуў спіс/пошук і чарнавік запыту. */
    let prayerBeforeDetail = null;

    /** Пакаленне запытаў hydrateCalendar — ігнараваць састарэлыя адказы пры хуткім пераключэнні месяца. */
    let calendarHydrateGeneration = 0;
    /** In-memory кэш месяца календара, каб не было «скачка» колераў пры адкрыцці. */
    const calendarMonthCache = new Map();
    /** In-memory кэш даведніка «Урачыстасці і святы». */
    const solemnitiesApiItemsByYear = new Map();
    let solemnitiesHydrateGeneration = 0;
    let solemnitiesApiLoadingYear = null;
    let solemnitiesApiErrorYear = null;
    let solemnitiesApiErrorMessage = '';
    let solemnitiesSettingsOpen = false;
    const SOLEMNITIES_SECTION_COLLAPSE_KEY = 'totus_solemnities_collapsed_sections';
    let solemnitiesCollapsedSections = readSolemnitiesCollapsedSections();
    /** Адкуль адкрылі налады: 'calendar' | 'day'. */
    let calendarSettingsReturnView = null;

    let songbookDetailId = null;
    /** { view: 'list'|'search', query: string } перад адкрыццём запісу спеўніка. */
    let songbookStateBeforeDetail = null;

    const TOOLBAR_ICON_BTN =
        'w-11 h-11 shrink-0 flex items-center justify-center rounded-xl text-app-text hover:bg-white/10 transition-colors active:bg-white/15 border-0 bg-transparent cursor-pointer';

    /** Агульны стыль радкоў меню (малітоўнік, Пісанне, раскрывальныя summary). */
    const APP_LIST_ROW_BTN_CLASS =
        'w-full text-left rounded-md border border-app-stroke border-solid bg-app-elevated p-0 hover:bg-white/[0.04] transition-colors cursor-pointer text-inherit';
    const APP_LIST_ROW_INNER_CLASS =
        'flex w-full min-h-16 items-center justify-between gap-3 box-border py-3.5 px-[18px]';

    /** Адзіны выгляд поля пошуку: малітоўнік і спеўнік. */
    const APP_SEARCH_BAR_CLASS =
        'rounded-md border border-app-stroke border-solid bg-app-bg2 px-3 py-2.5 flex items-center gap-2 focus-within:border-app-stroke/80';
    const APP_SEARCH_INPUT_CLASS =
        'flex-1 bg-transparent border-0 text-app-text text-sm placeholder:text-app-textTer outline-none min-w-0';

    /** Вышыня радка шапкі (як Android toolbar_min_height_extended). */
    const TOOLBAR_BAR_H_PX = 72;
    /** Макс. вышыня тэксту загалоўка ўнутры шапкі — каб h1 не раздзімаў header. */
    const TOOLBAR_TITLE_AREA_MAX_H_PX = 60;
    /** Autosize у межах шапкі: як у Android toolbarTitleAutoSizeSpBounds (~15–22 sp). */
    const TOOLBAR_TITLE_MAX_PX = 20;
    const TOOLBAR_TITLE_MIN_PX = 12;
    const TOOLBAR_H1_CLASS =
        'totus-toolbar-title-autosize text-app-text font-medium leading-[1.2] flex-1 text-left pl-0.5 min-w-0 break-words max-h-[60px] overflow-hidden';

    let toolbarTitleFitResizeBound = false;

    function toolbarTitleMeasureLineHeightPx(el) {
        const st = getComputedStyle(el);
        const lh = st.lineHeight;
        if (lh === 'normal') {
            const fs = parseFloat(st.fontSize);
            return (Number.isFinite(fs) ? fs : TOOLBAR_TITLE_MAX_PX) * 1.2;
        }
        const n = parseFloat(lh);
        if (Number.isFinite(n)) return n;
        const fs = parseFloat(st.fontSize);
        return (Number.isFinite(fs) ? fs : TOOLBAR_TITLE_MAX_PX) * 1.2;
    }

    /** Падбор шрыфту: не больш за 3 радкі і не вышэй за [TOOLBAR_TITLE_AREA_MAX_H_PX] — шапка застаецца [TOOLBAR_BAR_H_PX]. */
    function fitToolbarTitleH1(el) {
        if (!el || !el.isConnected) return;
        el.classList.remove('line-clamp-3');
        el.style.setProperty('overflow', 'visible', 'important');

        const maxPx = TOOLBAR_TITLE_MAX_PX;
        const minPx = TOOLBAR_TITLE_MIN_PX;
        let lo = minPx;
        let hi = maxPx;
        let best = minPx;
        while (lo <= hi) {
            const mid = Math.floor((lo + hi + 1) / 2);
            el.style.setProperty('font-size', `${mid}px`, 'important');
            void el.offsetHeight;
            const lineH = toolbarTitleMeasureLineHeightPx(el);
            const threeLines = 3 * lineH + 2;
            const limit = Math.min(threeLines, TOOLBAR_TITLE_AREA_MAX_H_PX);
            if (el.scrollHeight <= limit) {
                best = mid;
                lo = mid + 1;
            } else {
                hi = mid - 1;
            }
        }
        el.style.setProperty('font-size', `${best}px`, 'important');
        void el.offsetHeight;
        const lineHF = toolbarTitleMeasureLineHeightPx(el);
        const limitF = Math.min(3 * lineHF + 2, TOOLBAR_TITLE_AREA_MAX_H_PX);
        if (el.scrollHeight > limitF) {
            el.classList.add('line-clamp-3');
        }
        el.style.removeProperty('overflow');
    }

    /** Спеўнік, дэталь: загаловак да 2 радкоў, катэгорыя 1 радок — памер шрыфту падбіраецца. */
    const SONGBOOK_TB_TITLE_MAX_PX = 20;
    const SONGBOOK_TB_TITLE_MIN_PX = 11;
    const SONGBOOK_TB_CAT_MAX_PX = 15;
    const SONGBOOK_TB_CAT_MIN_PX = 9;

    function fitSongbookDetailToolbarTitleH1(el) {
        if (!el || !el.isConnected) return;
        el.style.whiteSpace = '';
        el.style.textOverflow = '';
        el.style.removeProperty('max-height');
        el.style.setProperty('overflow', 'visible', 'important');
        let lo = SONGBOOK_TB_TITLE_MIN_PX;
        let hi = SONGBOOK_TB_TITLE_MAX_PX;
        let best = lo;
        while (lo <= hi) {
            const mid = Math.floor((lo + hi + 1) / 2);
            el.style.setProperty('font-size', `${mid}px`, 'important');
            void el.offsetHeight;
            const lineH = toolbarTitleMeasureLineHeightPx(el);
            const limit = 2 * lineH + 2;
            if (el.scrollHeight <= limit) {
                best = mid;
                lo = mid + 1;
            } else {
                hi = mid - 1;
            }
        }
        el.style.setProperty('font-size', `${best}px`, 'important');
        void el.offsetHeight;
        const lineHF = toolbarTitleMeasureLineHeightPx(el);
        const limitF = 2 * lineHF + 2;
        if (el.scrollHeight > limitF) {
            el.style.maxHeight = `${limitF}px`;
            el.style.overflow = 'hidden';
            el.classList.add('line-clamp-2');
        } else {
            el.style.removeProperty('max-height');
            el.style.removeProperty('overflow');
            el.classList.remove('line-clamp-2');
        }
    }

    function fitSongbookDetailToolbarCategoryP(el) {
        if (!el || !el.isConnected) return;
        el.classList.remove('line-clamp-1');
        el.style.whiteSpace = 'nowrap';
        el.style.overflow = 'hidden';
        el.style.textOverflow = 'ellipsis';
        el.style.removeProperty('max-height');
        void el.offsetWidth;
        if (el.clientWidth <= 0) {
            el.style.setProperty('font-size', `${SONGBOOK_TB_CAT_MIN_PX}px`, 'important');
            return;
        }
        let lo = SONGBOOK_TB_CAT_MIN_PX;
        let hi = SONGBOOK_TB_CAT_MAX_PX;
        let best = lo;
        while (lo <= hi) {
            const mid = Math.floor((lo + hi + 1) / 2);
            el.style.setProperty('font-size', `${mid}px`, 'important');
            void el.offsetWidth;
            if (el.scrollWidth <= el.clientWidth + 1) {
                best = mid;
                lo = mid + 1;
            } else {
                hi = mid - 1;
            }
        }
        el.style.setProperty('font-size', `${best}px`, 'important');
    }

    function fitSongbookDetailToolbarTitles() {
        const wrap = document.querySelector('[data-totus-songbook-toolbar-detail]');
        if (!wrap || !wrap.isConnected) return;
        const h1 = wrap.querySelector('.totus-songbook-detail-toolbar-title');
        const p = wrap.querySelector('.totus-songbook-detail-toolbar-cat');
        if (h1) fitSongbookDetailToolbarTitleH1(h1);
        if (p) fitSongbookDetailToolbarCategoryP(p);
    }

    function fitAllToolbarTitles() {
        document.querySelectorAll('header h1.totus-toolbar-title-autosize').forEach((h) => fitToolbarTitleH1(h));
        fitSongbookDetailToolbarTitles();
    }

    let toolbarTitleFitRafPending = false;
    function scheduleToolbarTitleFit() {
        if (toolbarTitleFitRafPending) return;
        toolbarTitleFitRafPending = true;
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                toolbarTitleFitRafPending = false;
                fitAllToolbarTitles();
            });
        });
    }

    /**
     * Загаловак шапкі пры чытанні: як MainActivity для nav_scripture_chapters (толькі кніга)
     * і nav_scripture_chapter_text («Ад Мацьвея 13»).
     */
    function scriptureReadingToolbarTitleText() {
        if (scPanel) return null;
        if (!scriptureSelectedId || scTranslationSettingsOpen) return null;
        if (scriptureData?.error) return null;
        const books = (scriptureData && scriptureData.books) || [];
        if (scBookIdx == null || scBookIdx < 0 || scBookIdx >= books.length) return null;
        const bk = books[scBookIdx];
        const name = String(bk.book_name || '').trim();
        if (!name) return null;
        if (scChapterNum == null) return name;
        return `${name} ${scChapterNum}`;
    }

    function toolbarTitleHtml() {
        if (currentView === 'home') {
            return `
                <div class="flex flex-1 min-w-0 items-center py-0.5 pl-[10px] pr-1">
                    <span class="home-toolbar-brand-title truncate min-w-0">Totus Tuus</span>
                </div>`;
        }
        if (currentView === 'prayers') {
            if (prayerNav.screen === 'detail') {
                const pid = Number(prayerNav.prayerId);
                const p = (prayersCache || []).find((x) => Number(x.id) === pid);
                const t = p?.title || 'Малітоўнік';
                return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml(t)}</h1>`;
            }
            if (prayerView === 'search') {
                return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml('Пошук у малітоўніку')}</h1>`;
            }
            if (prayerNav.screen === 'bookmarks_all') {
                return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml('Выбранае (малітвы)')}</h1>`;
            }
            let t = 'Малітоўнік';
            if (prayerNav.screen === 'subcategories') {
                t = prayerNav.category;
            } else if (prayerNav.screen === 'prayers') {
                const { category, subcategory } = prayerNav;
                t = subcategory === NO_SUBCATEGORY_TITLE ? category : subcategory;
            }
            return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml(t)}</h1>`;
        }
        if (isSongbookLikeView()) {
            if (songbookDetailId != null) {
                const e = (songbookCache || []).find((x) => Number(x.id) === Number(songbookDetailId));
                if (!e) {
                    return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml(songbookSectionTitle())}</h1>`;
                }
                const titleText = songbookBookmarksOnly ? songbookBookmarkListLabel(e) : songbookListLabel(e);
                const catLine = songbookCategoryToolbarSubtitle(e);
                return `<div data-totus-songbook-toolbar-detail class="flex flex-col flex-1 min-w-0 justify-center gap-0.5 py-0.5 pl-0.5 min-h-0 overflow-hidden">
                    <h1 class="totus-songbook-detail-toolbar-title text-app-text font-medium leading-tight min-w-0 break-words" style="font-size:${SONGBOOK_TB_TITLE_MAX_PX}px">${escapeHtml(titleText)}</h1>
                    <p class="totus-songbook-detail-toolbar-cat text-app-textSec leading-tight min-w-0" style="font-size:${SONGBOOK_TB_CAT_MAX_PX}px">${escapeHtml(catLine)}</p>
                </div>`;
            }
            if (songbookView === 'search') {
                return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml(currentView === 'kantaral' ? 'Пошук у кантарале' : 'Пошук у спеўніку')}</h1>`;
            }
            const st = songbookBookmarksOnly ? (currentView === 'kantaral' ? 'Выбранае (кантарал)' : 'Выбранае (спеўнік)') : songbookSectionTitle();
            return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml(st)}</h1>`;
        }
        if (currentView === 'ordo-missae') {
            return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml('Ordo Missae')}</h1>`;
        }
        if (currentView === 'scripture') {
            const h1Cls = TOOLBAR_H1_CLASS;
            if (scPanel === 'favorites') {
                return `<h1 id="scripture-toolbar-title" class="${h1Cls}">${escapeHtml('Выбраныя вершы')}</h1>`;
            }
            if (scPanel === 'compare') {
                return `<h1 id="scripture-toolbar-title" class="${h1Cls}">${escapeHtml('Параўнанне вершаў')}</h1>`;
            }
            if (scPanel === 'word_search') {
                return `<h1 id="scripture-toolbar-title" class="${h1Cls}">${escapeHtml('Пошук па Пісанні')}</h1>`;
            }
            const readingTitle = scriptureReadingToolbarTitleText();
            if (readingTitle) {
                return `<h1 id="scripture-toolbar-title" class="${h1Cls}">${escapeHtml(readingTitle)}</h1>`;
            }
            const showShort = scriptureSelectedId && !scTranslationSettingsOpen;
            const short = showShort ? scriptureTranslationShortLabel(scriptureSelectedId) : '';
            if (short) {
                return `<h1 id="scripture-toolbar-title" class="${h1Cls}">${escapeHtml(`Святое Пісанне: ${short}`)}</h1>`;
            }
            return `<h1 id="scripture-toolbar-title" class="${h1Cls}">${escapeHtml('Святое Пісанне')}</h1>`;
        }
        const titles = {
            calendar: `Літургічны каляндар: ${calendarDiocesesTitleSuffix()}`,
            day: 'Лекцыянарый дня',
            solemnities: 'Урачыстасці і святы',
            'calendar-settings': 'Налады календара',
            settings: 'Налады',
            about: 'Інфармацыя',
        };
        const t = titles[currentView] || 'Totus Tuus';
        return `<h1 class="${TOOLBAR_H1_CLASS}">${escapeHtml(t)}</h1>`;
    }

    function toolbarContentClass() {
        return 'w-full max-w-[480px] mx-auto px-2 h-full min-h-0 flex items-center gap-1 py-1.5';
    }

    /** А± у шапцы на экранах чытання (як ReadingTextScaleToolbar у Android). */
    function toolbarReadingTextScaleGroupHtml() {
        const step = readTextStep();
        const disSm = step <= TEXT_STEP_MIN ? ' disabled' : '';
        const disLg = step >= TEXT_STEP_MAX ? ' disabled' : '';
        /* w-11 h-11 = 44px — як іншыя кнопкі шапкі; бліжэй паміж А− і А+ */
        return `<div class="flex items-center shrink-0 gap-0 [&>button+button]:-ml-1.5" role="group" aria-label="Памер тэксту чытання"><button type="button" data-action="reading-font-smaller" class="w-11 h-11 shrink-0 flex items-center justify-center rounded-xl text-app-text text-base font-bold hover:bg-white/10 border-0 bg-transparent cursor-pointer disabled:opacity-35 disabled:cursor-not-allowed"${disSm} title="Паменшыць тэкст">А−</button><button type="button" data-action="reading-font-larger" class="w-11 h-11 shrink-0 flex items-center justify-center rounded-xl text-app-text text-base font-bold hover:bg-white/10 border-0 bg-transparent cursor-pointer disabled:opacity-35 disabled:cursor-not-allowed"${disLg} title="Павялічыць тэкст">А+</button></div>`;
    }

    function syncReadingTextToolbarButtons() {
        const step = readTextStep();
        document.querySelectorAll('[data-action="reading-font-smaller"]').forEach((b) => {
            b.disabled = step <= TEXT_STEP_MIN;
        });
        document.querySelectorAll('[data-action="reading-font-larger"]').forEach((b) => {
            b.disabled = step >= TEXT_STEP_MAX;
        });
    }

    /** Правыя дзеянні шапкі: налады толькі на галоўным (як menu_home у Android); малітоўнік/спеўнік — як menu_prayer_book / menu_songbook_book. */
    function toolbarRightActionsHtml() {
        if (currentView === 'home') {
            return `<div class="flex items-center shrink-0 gap-0 [&>button+button]:-ml-1.5">
                <button type="button" data-action="open-settings" class="${TOOLBAR_ICON_BTN}" aria-label="Налады"><i class="fas fa-gear text-lg" aria-hidden="true"></i></button>
                <button type="button" data-action="toggle-home-theme" class="${TOOLBAR_ICON_BTN}" aria-label="Колеравая схема"><i class="fas fa-circle-half-stroke text-lg" aria-hidden="true"></i></button>
                <button type="button" data-action="open-about-app" class="${TOOLBAR_ICON_BTN}" aria-label="Інфармацыя"><i class="fas fa-circle-info text-lg" aria-hidden="true"></i></button>
            </div>`;
        }
        if (currentView === 'prayers' && prayerNav.screen === 'detail') {
            const id = Number(prayerNav.prayerId);
            const bm = getBookmarksSet();
            const bmCls = Number.isFinite(id) && bm.has(id) ? 'fas fa-bookmark text-amber-400' : 'far fa-bookmark';
            return `${toolbarReadingTextScaleGroupHtml()}<button type="button" data-action="toolbar-prayer-detail-bookmark" data-prayer-toggle-bm="${Number.isFinite(id) ? id : ''}" class="${TOOLBAR_ICON_BTN}" aria-label="У выбранае"><i class="${bmCls} text-lg" aria-hidden="true"></i></button>`;
        }
        const prayerToolsScreens = ['categories', 'subcategories', 'prayers', 'bookmarks_all'];
        if (
            currentView === 'prayers' &&
            (prayerView === 'search' || prayerToolsScreens.includes(prayerNav.screen))
        ) {
            const bmBtn =
                prayerNav.screen === 'bookmarks_all'
                    ? ''
                    : `<button type="button" data-action="toolbar-prayer-bookmarks" class="${TOOLBAR_ICON_BTN}" aria-label="Выбранае (малітвы)"><i class="far fa-bookmark text-lg" aria-hidden="true"></i></button>`;
            return `<div class="flex items-center shrink-0 gap-0 [&>button+button]:-ml-1.5">${bmBtn}<button type="button" data-action="toolbar-prayer-search" class="${TOOLBAR_ICON_BTN}" aria-label="Пошук"><i class="fas fa-search text-lg" aria-hidden="true"></i></button></div>`;
        }
        if (isSongbookLikeView() && songbookDetailId != null) {
            const id = Number(songbookDetailId);
            const entry = (songbookCache || []).find((x) => Number(x.id) === id);
            const ct = entry ? String(entry.content_type || 'text').toLowerCase() : '';
            const mediaUrl = entry && ct === 'image' ? mediaAbsoluteUrl(entry.media_url) : '';
            const isImageEntry = ct === 'image' && !!mediaUrl;
            /* Пакуль кэш не падцягнуўся (entry няма) — слот пад А± усё адно займае месца, без «прыгадку» закладкі. */
            const useInvisibleScaleSlot = !entry || isImageEntry;
            const scaleInner = toolbarReadingTextScaleGroupHtml();
            const scaleHtml = useInvisibleScaleSlot
                ? `<div class="pointer-events-none shrink-0 [&_button]:pointer-events-none" style="visibility:hidden" aria-hidden="true">${scaleInner}</div>`
                : scaleInner;
            const sbm = getSongbookBookmarksSet();
            const bmCls = Number.isFinite(id) && sbm.has(id) ? 'fas fa-bookmark text-amber-400' : 'far fa-bookmark';
            return `${scaleHtml}<button type="button" data-action="toolbar-songbook-detail-bookmark" data-songbook-toggle-bm="${Number.isFinite(id) ? id : ''}" class="${TOOLBAR_ICON_BTN}" aria-label="У выбранае"><i class="${bmCls} text-lg" aria-hidden="true"></i></button>`;
        }
        if (isSongbookLikeView()) {
            const bmBtn = songbookBookmarksOnly
                ? ''
                : `<button type="button" data-action="toolbar-songbook-bookmarks" class="${TOOLBAR_ICON_BTN}" aria-label="${escapeHtml(currentView === 'kantaral' ? 'Выбранае (кантарал)' : 'Выбранае (спеўнік)')}"><i class="far fa-bookmark text-lg" aria-hidden="true"></i></button>`;
            return `<div class="flex items-center shrink-0 gap-0 [&>button+button]:-ml-1.5">${bmBtn}<button type="button" data-action="toolbar-songbook-search" class="${TOOLBAR_ICON_BTN}" aria-label="Пошук"><i class="fas fa-search text-lg" aria-hidden="true"></i></button></div>`;
        }
        if (currentView === 'scripture') {
            const chOpen =
                !scTranslationSettingsOpen &&
                scBookIdx != null &&
                scChapterNum != null &&
                scriptureData &&
                !scriptureData.error;
            const scaleHtml = chOpen ? toolbarReadingTextScaleGroupHtml() : '';
            const favCompareOpen =
                !scTranslationSettingsOpen &&
                scriptureSelectedId &&
                scriptureData &&
                !scriptureData.error &&
                (scriptureData.books?.length || 0) > 0;
            const favBtn =
                favCompareOpen && scPanel !== 'favorites'
                    ? `<button type="button" data-action="scripture-open-favorites" class="${TOOLBAR_ICON_BTN}" aria-label="Выбраныя вершы"><i class="far fa-bookmark text-lg" aria-hidden="true"></i></button>`
                    : '';
            const cmpBtn =
                favCompareOpen && scPanel !== 'compare'
                    ? `<button type="button" data-action="scripture-open-compare" class="${TOOLBAR_ICON_BTN} text-app-text" aria-label="Параўнанне вершаў">${scriptureCompareIconSvg(false, 'block mx-auto')}</button>`
                    : '';
            const favCompareHtml = favBtn + cmpBtn;
            return `${scaleHtml}${favCompareHtml}<button type="button" data-action="open-scripture-translation-settings" class="${TOOLBAR_ICON_BTN}" aria-label="Налады перакладу Пісання"><i class="fas fa-gear text-lg" aria-hidden="true"></i></button>`;
        }
        if (currentView === 'calendar-settings') {
            return '';
        }
        if (currentView === 'day') {
            return `${toolbarReadingTextScaleGroupHtml()}<button type="button" data-action="open-calendar-settings" class="${TOOLBAR_ICON_BTN}" aria-label="Налады календара"><i class="fas fa-gear text-lg" aria-hidden="true"></i></button>`;
        }
        if (currentView === 'calendar') {
            return `<button type="button" data-action="open-calendar-settings" class="${TOOLBAR_ICON_BTN}" aria-label="Налады календара"><i class="fas fa-gear text-lg" aria-hidden="true"></i></button>`;
        }
        if (currentView === 'solemnities') {
            if (solemnitiesSettingsOpen) return '';
            return toolbarReadingTextScaleGroupHtml();
        }
        if (currentView === 'ordo-missae') {
            return toolbarReadingTextScaleGroupHtml();
        }
        return '';
    }

    function renderChrome() {
        const cfg = getApiConfig();
        const showBanner = !isApiConfigured();
        const navUp = currentView !== 'home';
        const lead = navUp
            ? `<button type="button" data-action="nav-up" class="${TOOLBAR_ICON_BTN}" aria-label="Назад"><i class="fas fa-arrow-left text-lg" aria-hidden="true"></i></button>`
            : '';
        return `
        ${showBanner ? configBannerHtml() : ''}
        <header class="shrink-0 z-50 h-[72px] overflow-hidden bg-app-toolbar shadow-lg shadow-black/25 border-b border-white/5">
            <div class="${toolbarContentClass()}">
                ${lead}
                ${toolbarTitleHtml()}
                <div id="totus-toolbar-right" class="flex items-center shrink-0 gap-0 [&>*:not(:first-child)]:-ml-1.5">${toolbarRightActionsHtml()}</div>
            </div>
        </header>`;
    }

    function ordoMissaeSearchChromeHtml() {
        if (currentView !== 'ordo-missae') return '';
        return `
        <div class="shrink-0 z-40">
            <div class="w-full max-w-[480px] mx-auto" style="padding:8px;box-sizing:border-box;">
                <div class="relative w-full box-border rounded-md border border-app-stroke border-solid bg-app-elevated focus-within:border-app-stroke/80" style="height:56px;min-height:56px;">
                    <input type="text" id="ordo-missae-search-query" autocomplete="off" placeholder="Слова або «словазлучэнне»…"
                        value="${escapeHtml(ordoMissaeSearchQuery)}" class="absolute w-full box-border bg-transparent border-0 outline-none text-app-text placeholder:text-app-textSec text-[17px]" style="left:0;top:0;height:56px;padding:0 96px 0 12px;line-height:56px;" />
                    <div id="ordo-missae-search-nav" class="absolute flex items-center gap-0 shrink-0" style="right:8px;top:10px;height:36px;visibility:${ordoMissaeSearchQuery.trim() ? 'visible' : 'hidden'}">
                        <button type="button" data-action="ordo-search-prev" id="ordo-missae-search-prev" class="shrink-0 flex items-center justify-center rounded-full text-app-text hover:bg-white/[0.06] border-0 bg-transparent cursor-pointer disabled:opacity-45 disabled:cursor-default p-0" style="width:36px;height:36px;" aria-label="Папярэдні вынік"><i class="fas fa-chevron-left text-sm" aria-hidden="true"></i></button>
                        <button type="button" data-action="ordo-search-next" id="ordo-missae-search-next" class="shrink-0 flex items-center justify-center rounded-full text-app-text hover:bg-white/[0.06] border-0 bg-transparent cursor-pointer disabled:opacity-45 disabled:cursor-default p-0" style="width:36px;height:36px;" aria-label="Наступны вынік"><i class="fas fa-chevron-right text-sm" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
        </div>`;
    }

    /** Пасля hydrateScriptureView (без поўнага renderApp) — абнавіць А± і шасцярэнку ў шапцы. */
    function refreshToolbarRightActions() {
        const el = document.getElementById('totus-toolbar-right');
        if (el) el.innerHTML = toolbarRightActionsHtml();
        syncReadingTextToolbarButtons();
        scheduleToolbarTitleFit();
    }

    function renderHome() {
        const cards = HOME_CARDS.map((c) => {
            /** Як GridLayoutManager у HomeFragment: span 2 = на ўсю шырыню сеткі (не толькі з sm). */
            const colSpan = c.span === 2 ? 'col-span-2' : '';
            const img = c.image
                ? homeCardPictureHtml(c.image, c.span, {
                    fetchPriorityHigh: c.target === 'scripture' || c.preload,
                    decodeSync: c.preload,
                })
                : '';
            const unavailableOverlay = c.available
                ? ''
                : `<div class="absolute inset-0 pointer-events-none" style="background-color: rgba(71, 85, 105, 0.46);"></div>
                    <div class="absolute inset-0 flex items-center justify-center px-2 pointer-events-none">
                        <span class="rounded-md px-3 py-1 text-sm font-semibold" style="background-color: rgba(71, 85, 105, 0.86); color: #ffffff !important; -webkit-text-fill-color: #ffffff;">У распрацоўцы</span>
                    </div>`;
            /** Фільтр толькі на медыя — інакш «In progress» атрымлівае той жа grayscale/brightness і выглядае шэрым. */
            const unavailableMediaFilterStyle = c.available ? '' : 'filter: grayscale(1) brightness(0.5) saturate(0);';
            const unavailableCardStyle = c.available
                ? ''
                : 'background-color: rgba(30, 41, 59, 0.72); border-color: rgba(71, 85, 105, 0.55);';
            const cardInteractionClasses = c.available ? 'active:scale-[0.98] cursor-pointer' : 'cursor-default';
            const cardHeight = Number.isFinite(c.height) ? Number(c.height) : 132;
            const infoHintBadge = c.infoHint
                ? `<span
                        class="absolute right-2 top-2 z-20"
                        style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;min-width:22px;min-height:22px;border-radius:9999px;background-color:#111827;color:#ffffff;font-size:12px;font-weight:700;line-height:1;font-family:system-ui,-apple-system,sans-serif;flex-shrink:0;"
                        title="${escapeHtml(c.infoHint)}"
                        aria-label="${escapeHtml(c.infoHint)}">i</span>`
                : '';
            return `
            <div class="relative w-full min-h-0 ${colSpan}">
                <button type="button" data-home-card="${c.target}" data-home-available="${c.available ? '1' : '0'}"
                    class="text-left rounded-md border border-app-stroke bg-app-elevated overflow-hidden w-full min-h-0 p-0 transition-transform ${cardInteractionClasses}"
                    style="${unavailableCardStyle}">
                    <div class="home-card-head relative w-full overflow-hidden bg-app-surface" style="height:${cardHeight}px;">
                        <div class="absolute inset-0 overflow-hidden" style="${unavailableMediaFilterStyle}">
                            ${img}
                            <div class="absolute inset-0 pointer-events-none" style="background: linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,0.2) 72%, rgba(0,0,0,0.8) 100%);"></div>
                        </div>
                        ${unavailableOverlay}
                        ${infoHintBadge}
                        <div class="absolute z-10 text-left pointer-events-none" style="left:6px;right:6px;bottom:6px;">
                            <div class="font-medium text-[20px] leading-[1.2] text-white max-w-full" style="text-shadow: 0 2px 6px rgba(0,0,0,0.9); color: #ffffff !important; -webkit-text-fill-color: #ffffff; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">${escapeHtml(c.title)}</div>
                        </div>
                    </div>
                </button>
            </div>`;
        }).join('');
        return `
        <div class="w-full max-w-[480px] mx-auto px-2 pb-8 pt-2">
            <div class="grid grid-cols-2 gap-2 w-full">${cards}</div>
        </div>`;
    }

    function readSolemnitiesYear() {
        return Number.isFinite(solemnitiesSelectedYear) ? solemnitiesSelectedYear : luxon.DateTime.now().year;
    }

    function writeSolemnitiesYear(year) {
        const y = Math.max(1900, Math.min(2199, Math.trunc(Number(year) || luxon.DateTime.now().year)));
        solemnitiesSelectedYear = y;
        return y;
    }

    function solemnitiesCacheKey(year) {
        return `solemnities|${year}`;
    }

    function readSolemnitiesCollapsedSections() {
        try {
            const raw = localStorage.getItem(SOLEMNITIES_SECTION_COLLAPSE_KEY);
            const arr = JSON.parse(raw || '[]');
            return new Set(Array.isArray(arr) ? arr.map((x) => String(x || '').trim()).filter(Boolean) : []);
        } catch {
            return new Set();
        }
    }

    function writeSolemnitiesCollapsedSections() {
        try {
            localStorage.setItem(SOLEMNITIES_SECTION_COLLAPSE_KEY, JSON.stringify([...solemnitiesCollapsedSections]));
        } catch {
            /* ignore */
        }
    }

    function solemnitiesApiRowsAsItems(year) {
        const apiItems = solemnitiesApiItemsByYear.get(solemnitiesCacheKey(year));
        if (!Array.isArray(apiItems)) return null;
        const out = [];
        let lastSection = null;
        let currentCollapsed = false;
        for (const row of apiItems) {
            if (!row) continue;
            const date = String(row.date_label || '').trim();
            const title = String(row.title || '').trim();
            const section = String(row.section_title || '').trim();
            if (!date || !title) continue;
            if (section && section !== lastSection) {
                currentCollapsed = solemnitiesCollapsedSections.has(section);
                out.push({ type: 'header', title: section, collapsed: currentCollapsed });
                lastSection = section;
            }
            if (!currentCollapsed) out.push({ date, title });
        }
        return out;
    }

    function solemnitiesSettingsShellHtml() {
        const t = readCalendarDioceseToggles();
        return `<div id="solemnities-settings-root" class="max-w-[480px] mx-auto px-2 pb-8 pt-2">
            <section class="rounded-md border border-app-stroke bg-app-elevated p-[18px] space-y-3">
                <p class="text-[13px] text-app-textSec leading-relaxed">Гэтыя налады ўплываюць на раздзел «Урачыстасці і святы»: рухомыя і асноўныя святы па Імшале застаюцца заўсёды, а мясцовыя святы паказваюцца для абраных дыяцэзій.</p>
                ${dioceseSettingsCheckboxListHtml(t, 'solemnities')}
            </section>
        </div>`;
    }

    async function hydrateSolemnities() {
        if (currentView !== 'solemnities' || solemnitiesSettingsOpen) return;
        const year = readSolemnitiesYear();
        const key = solemnitiesCacheKey(year);
        if (solemnitiesApiItemsByYear.has(key)) return;
        const gen = ++solemnitiesHydrateGeneration;
        solemnitiesApiLoadingYear = key;
        solemnitiesApiErrorYear = null;
        solemnitiesApiErrorMessage = '';
        refreshSolemnitiesContent();
        const { ok, data } = await apiFetch('solemnities.php', { year });
        if (gen !== solemnitiesHydrateGeneration) return;
        solemnitiesApiLoadingYear = null;
        if (ok && Array.isArray(data)) {
            solemnitiesApiItemsByYear.set(key, data);
        } else {
            solemnitiesApiErrorYear = key;
            solemnitiesApiErrorMessage = String(data?.message || data?.error || 'Не ўдалося загрузіць урачыстасці і святы.');
        }
        refreshSolemnitiesContent();
    }

    function refreshSolemnitiesContent() {
        const root = document.getElementById('solemnities-root');
        const list = document.getElementById('solemnities-list');
        if (!root || !list || currentView !== 'solemnities' || solemnitiesSettingsOpen) return;
        const year = Number(root.dataset.solemnitiesYear) || readSolemnitiesYear();
        list.innerHTML = solemnitiesRowsHtml(year);
    }

    function solemnityDateLabel(dt) {
        return `${dt.day} ${BE_MONTH_GEN[dt.month]}*`;
    }

    function firstAdventSunday(year) {
        let dt = luxon.DateTime.local(year, 11, 27).startOf('day');
        while (dt.weekday !== 7) dt = dt.plus({ days: 1 });
        return dt;
    }

    function solemnitiesMovableDates(year) {
        const easter = gregorianEasterSundayUtc(year).setZone('local').startOf('day');
        const advent = firstAdventSunday(year);
        return {
            ashWednesday: solemnityDateLabel(easter.minus({ days: 46 })),
            palmSunday: solemnityDateLabel(easter.minus({ days: 7 })),
            easter: solemnityDateLabel(easter),
            ascension: solemnityDateLabel(easter.plus({ days: 39 })),
            pentecost: solemnityDateLabel(easter.plus({ days: 49 })),
            corpusChristi: solemnityDateLabel(easter.plus({ days: 60 })),
            sacredHeart: solemnityDateLabel(easter.plus({ days: 68 })),
            christKing: solemnityDateLabel(advent.minus({ days: 7 })),
            firstAdventSunday: solemnityDateLabel(advent),
        };
    }

    function solemnitiesItems(year) {
        const apiRows = solemnitiesApiRowsAsItems(year);
        if (apiRows !== null) {
            return apiRows;
        }
        const d = solemnitiesMovableDates(year);
        return [
            { type: 'header', title: 'Абавязковыя святы і ўрачыстасці' },
            { date: '1 студзеня', title: 'Святой Багародзіцы Марыі' },
            { date: '6 студзеня', title: 'Аб’яўлення Пана (Тры Каралі)' },
            { date: '19 сакавіка', title: 'Святога Юзафа' },
            { date: d.ascension, title: 'Унебаўшэсця Пана' },
            { date: d.corpusChristi, title: 'Цела і Крыві Хрыста (Божага Цела)' },
            { date: '29 чэрвеня', title: 'Святых апосталаў Пятра і Паўла' },
            { date: '15 жніўня', title: 'Унебаўзяцце Найсвяцейшай Панны Марыі' },
            { date: '1 лістапада', title: 'Усіх Святых' },
            { date: '8 снежня', title: 'Беззаганнага Зачацця Найсвяцейшай Панны Марыі' },
            { date: '25 снежня', title: 'Нараджэнне Пана' },
            { type: 'header', title: 'Важнейшыя рухомыя святы і ўрачыстасці' },
            { date: d.ashWednesday, title: 'Папялец' },
            { date: d.easter, title: 'Вялікдзень' },
            { date: d.ascension, title: 'Унебаўшэсце' },
            { date: d.pentecost, title: 'Спасланне Духа Святога' },
            { date: d.corpusChristi, title: 'Цела і Крыві Пана' },
            { date: d.firstAdventSunday, title: 'Першая нядзеля Адвэнту' },
            { type: 'header', title: 'Урачыстасці і святы (па агульным парадку)' },
            { date: '1 студзеня', title: 'Урачыстасць Святой Багародзіцы Марыі' },
            { date: '6 студзеня', title: 'Аб’яўленне Пана, Тры Каралі' },
            { date: '2 лютага', title: 'Ахвяраванне Пана' },
            { date: d.ashWednesday, title: 'Папяльцовая серада – пачатак Вялікага посту' },
            { date: '22 лютага', title: 'Свята Катэдры святога Пятра' },
            { date: '19 сакавіка', title: 'Урачыстасць святога Юзафа' },
            { date: '25 сакавіка', title: 'Звеставанне Пана' },
            { date: d.palmSunday, title: 'Пальмовая нядзеля' },
            { date: d.easter, title: 'Уваскрасенне Пана' },
            { date: d.ascension, title: 'Унебаўшэсце Пана, урачыстасць' },
            { date: d.pentecost, title: 'Спасланне Духа Святога' },
            { date: d.corpusChristi, title: 'Урачыстасць Найсвяцейшага Цела і Крыві Хрыста' },
            { date: d.sacredHeart, title: 'Урачыстасць Найсвяцейшага Сэрца Пана Езуса' },
            { date: '24 чэрвеня', title: 'Нараджэнне святога Яна Хрысціцеля' },
            { date: '29 чэрвеня', title: 'Урачыстасць святых апосталаў Пятра і Паўла' },
            { date: '2 ліпеня', title: 'Урачыстасць Найсвяцейшай Панны Марыі Будслаўскай' },
            { date: '6 жніўня', title: 'Перамяненне Пана' },
            { date: '15 жніўня', title: 'Унебаўзяцце Найсвяцейшай Панны Марыі' },
            { date: '14 верасня', title: 'Свята Узвышэння Святога Крыжа' },
            { date: '1 лістапада', title: 'Урачыстасць Усіх Святых' },
            { date: '2 лістапада', title: 'Успамін усіх памерлых вернікаў' },
            { date: d.christKing, title: 'Урачыстасць Пана Нашага Езуса Хрыста, Валадара Сусвету' },
            { date: '8 снежня', title: 'Беззаганнае Зачацце Найсвяцейшай Панны Марыі' },
            { date: '25 снежня', title: 'Нараджэнне Пана' },
        ];
    }

    function solemnitiesRowsHtml(year) {
        const key = solemnitiesCacheKey(year);
        let currentCollapsed = false;
        const rows = solemnitiesItems(year).map((item) => {
            if (item.type === 'header') {
                currentCollapsed = item.collapsed === true || solemnitiesCollapsedSections.has(String(item.title || '').trim());
                return `<button type="button" data-action="toggle-solemnities-section" data-section-title="${escapeHtml(item.title)}" class="w-full px-1 pt-3 pb-2 border-0 bg-transparent text-left font-bold text-app-text leading-snug flex items-center justify-between gap-3 cursor-pointer" style="font-size:calc(20px * var(--totus-read-scale));">
                    <span>${escapeHtml(item.title)}</span>
                    <i class="fas ${currentCollapsed ? 'fa-chevron-down' : 'fa-chevron-up'} text-sm text-app-textSec" aria-hidden="true"></i>
                </button>`;
            }
            if (currentCollapsed) return '';
            return `<article class="rounded-md border border-app-stroke bg-app-elevated px-[18px] py-3 min-h-[56px] flex items-center gap-3">
                <div class="w-[92px] shrink-0 font-bold text-app-textSec leading-snug" style="font-size:calc(15px * var(--totus-read-scale));">${escapeHtml(item.date)}</div>
                <div class="flex-1 min-w-0 text-app-text leading-snug" style="font-size:calc(17px * var(--totus-read-scale));">${escapeHtml(item.title)}</div>
            </article>`;
        }).join('');
        const status = solemnitiesApiLoadingYear === key
            ? ''
            : (solemnitiesApiErrorYear === key && solemnitiesApiErrorMessage
                ? `<p class="px-1 text-[13px] text-red-300 leading-relaxed">${escapeHtml(solemnitiesApiErrorMessage)}</p>`
                : '');
        return `${status}${rows}`;
    }

    function solemnitiesShellHtml() {
        if (solemnitiesSettingsOpen) return solemnitiesSettingsShellHtml();
        const year = readSolemnitiesYear();
        return `<div id="solemnities-root" data-solemnities-year="${year}" class="max-w-[480px] mx-auto px-2 pb-8 pt-2">
            <section class="rounded-md border border-app-stroke bg-app-elevated px-3 py-2 flex items-center gap-1">
                <div class="flex-1 min-w-0 font-bold text-app-textSec" style="font-size:calc(15px * var(--totus-read-scale));">Год</div>
                <button type="button" data-action="solemnities-year-prev" class="w-8 h-10 shrink-0 flex items-center justify-center rounded-lg border-0 bg-transparent text-app-text hover:bg-white/10 disabled:opacity-40" aria-label="Папярэдні год" ${year <= 1900 ? 'disabled' : ''}><i class="fas fa-chevron-left text-sm" aria-hidden="true"></i></button>
                <div class="min-w-[56px] px-1 text-center font-bold text-app-text leading-none" style="font-size:calc(17px * var(--totus-read-scale));">${year}</div>
                <button type="button" data-action="solemnities-year-next" class="w-8 h-10 shrink-0 flex items-center justify-center rounded-lg border-0 bg-transparent text-app-text hover:bg-white/10 disabled:opacity-40" aria-label="Наступны год" ${year >= 2199 ? 'disabled' : ''}><i class="fas fa-chevron-right text-sm" aria-hidden="true"></i></button>
            </section>
            <div id="solemnities-list" class="mt-3 space-y-2">${solemnitiesRowsHtml(year)}</div>
        </div>`;
    }

    /** Грэгарыянская Вялікдзень (нядзеля), UTC — як у LiturgyDayFragment / liturgy_common.php. */
    function gregorianEasterSundayUtc(year) {
        const a = year % 19;
        const b = Math.floor(year / 100);
        const c = year % 100;
        const d = Math.floor(b / 4);
        const e = b % 4;
        const f = Math.floor((b + 8) / 25);
        const g = Math.floor((b - f + 1) / 3);
        const h = (19 * a + b - d - g + 15) % 30;
        const i = Math.floor(c / 4);
        const k = c % 4;
        const l = (32 + 2 * e + 2 * i - h - k) % 7;
        const m2 = Math.floor((a + 11 * h + 22 * l) / 451);
        const month = Math.floor((h + l - 7 * m2 + 114) / 31);
        const day = ((h + l - 7 * m2 + 114) % 31) + 1;
        return luxon.DateTime.utc(year, month, day).startOf('day');
    }

    function isPaschalOctaveWeekdayDateStr(dateStr) {
        const iso = String(dateStr || '').slice(0, 10);
        const dt = luxon.DateTime.fromISO(iso, { zone: 'utc' });
        if (!dt.isValid) return false;
        if (dt.weekday === 7) return false;
        const easter = gregorianEasterSundayUtc(dt.year);
        const from = easter.plus({ days: 1 });
        const until = easter.plus({ days: 6 });
        const d = dt.startOf('day');
        return d >= from && d <= until;
    }

    function paschalOctaveWeekdayDisplayTitle(dateStr) {
        if (!isPaschalOctaveWeekdayDateStr(dateStr)) return '';
        const iso = String(dateStr || '').slice(0, 10);
        const dt = luxon.DateTime.fromISO(iso, { zone: 'utc' });
        const names = {
            1: 'Панядзелак',
            2: 'Аўторак',
            3: 'Серада',
            4: 'Чацвер',
            5: 'Пятніца',
            6: 'Субота',
        };
        const n = names[dt.weekday];
        return n ? `${n} ў актаве Пасхі` : '';
    }

    function liturgyNormalizeTitleKey(t) {
        return String(t || '')
            .replace(/\u00a0/g, ' ')
            .trim()
            .toLowerCase()
            .replace(/\s+/g, ' ');
    }

    /** Калі API/кэш яшчэ старыя — як у CatholicPrayerBookBy LiturgyDayFragment. */
    function liturgyClientResolveMainTitle(apiTitle, autoTitle, dateStr) {
        const octave = paschalOctaveWeekdayDisplayTitle(dateStr);
        if (octave) return octave;
        const s = String(apiTitle || autoTitle || '').trim();
        return s || '—';
    }

    function liturgyClientOptionalMemorialForDisplay(data, dateStr) {
        if (isPaschalOctaveWeekdayDateStr(dateStr)) return '';
        return String(data.optional_memorial_title || '');
    }

    function stripPaschalOptionalMemorialDetailsHtml(html) {
        const raw = String(html || '');
        if (!raw) return raw;
        return raw.replace(/<details\b[^>]*>[\s\S]*?<\/details>/gi, (block) => {
            const m = block.match(/<summary\b[^>]*>([\s\S]*?)<\/summary>/i);
            if (!m) return block;
            const tmp = document.createElement('div');
            tmp.innerHTML = m[1];
            const plain = (tmp.textContent || '').trim();
            if (/успамін/i.test(plain) || /успамінам/i.test(plain)) return '';
            return block;
        });
    }

    /** Прыбраць вядучыя <br> / пустыя p і div — меней «пустога радка» пад загалоўкам карткі (вэб). */
    function trimLeadingEmptyHtmlBlocksJs(html) {
        let h = String(html || '').trimStart();
        const re =
            /^(?:\s|&nbsp;)*(?:<br\s*\/?>|<p\b[^>]*>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>|<div\b[^>]*>(?:\s|&nbsp;|<br\s*\/?>)*<\/div>)+/gi;
        for (let i = 0; i < 24; i++) {
            const next = h.replace(re, '').trimStart();
            if (next === h) break;
            h = next;
        }
        return h;
    }

    /** Нармалізаваныя варыянты старога ключа лекцыянарыя для пн–сб актавы («— I/І Тыдзень Велікоднага перыяду»). */
    function paschalOctaveLegacyTitleKeysForDate(dateStr) {
        if (!isPaschalOctaveWeekdayDateStr(dateStr)) return [];
        const iso = String(dateStr || '').slice(0, 10);
        const dt = luxon.DateTime.fromISO(iso, { zone: 'utc' });
        const names = {
            1: 'Панядзелак',
            2: 'Аўторак',
            3: 'Серада',
            4: 'Чацвер',
            5: 'Пятніца',
            6: 'Субота',
        };
        const wd = names[dt.weekday];
        if (!wd) return [];
        const keys = new Set();
        const dashes = ['—', '–', '-', '−'];
        const romans = ['I', 'І'];
        for (const d of dashes) {
            for (const r of romans) {
                keys.add(liturgyNormalizeTitleKey(`${wd} ${d} ${r} Тыдзень Велікоднага перыяду`));
            }
        }
        return [...keys];
    }

    /** Эўрыстка: кароткі радок = стары загаловак тыдня ў актаве (без пераліку ўсіх злучкаў). */
    function isPaschalOctaveLegacyEmbeddedLine(kn, dateStr) {
        if (!isPaschalOctaveWeekdayDateStr(dateStr)) return false;
        if (!kn.includes('тыдзень') || !kn.includes('велікоднага') || !kn.includes('перыяду')) return false;
        if (!/\s(i|і)\s+тыдзень\b/.test(kn)) return false;
        const iso = String(dateStr || '').slice(0, 10);
        const dt = luxon.DateTime.fromISO(iso, { zone: 'utc' });
        const wdn = {
            1: 'панядзелак',
            2: 'аўторак',
            3: 'серада',
            4: 'чацвер',
            5: 'пятніца',
            6: 'субота',
        }[dt.weekday];
        return !!(wdn && kn.startsWith(wdn));
    }

    /**
     * Выдаліць з HTML убудаваны дублікат «Дзень — I Тыдзень Велікоднага перыяду» (лекцыянарый у БД).
     */
    function stripEmbeddedLegacyPaschalOctaveTitleHtml(html, dateStr) {
        if (!isPaschalOctaveWeekdayDateStr(dateStr)) return String(html || '');
        const keyList = paschalOctaveLegacyTitleKeysForDate(dateStr);
        const keySet = new Set(keyList);
        let h = String(html || '');
        try {
            const wrapperId = 'totus-strip-oct-legacy';
            const doc = new DOMParser().parseFromString(`<div id="${wrapperId}">${h}</div>`, 'text/html');
            const root = doc.getElementById(wrapperId);
            if (!root) return h;
            const sel = 'h1, h2, h3, h4, h5, h6, p, strong, b, font, div';
            const toRemove = [];
            root.querySelectorAll(sel).forEach((el) => {
                const t = (el.textContent || '').trim();
                if (!t || t.length > 220) return;
                const kn = liturgyNormalizeTitleKey(t);
                if (keySet.has(kn) || isPaschalOctaveLegacyEmbeddedLine(kn, dateStr)) {
                    toRemove.push(el);
                }
            });
            const nodeDepth = (el) => {
                let d = 0;
                for (let n = el; n && n.parentElement; n = n.parentElement) d += 1;
                return d;
            };
            toRemove.sort((a, b) => nodeDepth(b) - nodeDepth(a));
            toRemove.forEach((el) => el.remove());
            root.querySelectorAll('p, div').forEach((el) => {
                if (el.children.length === 0 && !(el.textContent || '').trim()) {
                    el.remove();
                }
            });
            return root.innerHTML;
        } catch {
            return h;
        }
    }

    /**
     * Адзіны <details> — без раскрывання; калі загаловак супадае з шапкай — без паўтору назвы.
     * У пн–сб актавы выдаляем блокі з успамінам.
     */
    function flattenSingleLiturgyDetailsForWeb(html, dateStr, mainTitle) {
        const finish = (x) =>
            trimLeadingEmptyHtmlBlocksJs(stripEmbeddedLegacyPaschalOctaveTitleHtml(String(x ?? ''), dateStr));
        let h = String(html || '');
        if (isPaschalOctaveWeekdayDateStr(dateStr)) {
            h = stripPaschalOptionalMemorialDetailsHtml(h);
        }
        try {
            const wrapperId = 'totus-liturgy-rw';
            const wrapped = `<div id="${wrapperId}">${h}</div>`;
            const doc = new DOMParser().parseFromString(wrapped, 'text/html');
            const root = doc.getElementById(wrapperId);
            if (!root) return finish(h);
            const detailsList = [...root.querySelectorAll('details')];
            if (detailsList.length !== 1) return finish(root.innerHTML);
            const det = detailsList[0];
            const sumEl = det.querySelector('summary');
            const sumText = sumEl ? (sumEl.textContent || '').trim() : '';
            const mainNorm = liturgyNormalizeTitleKey(mainTitle);
            const sumNorm = liturgyNormalizeTitleKey(sumText);
            const bodyWrap = doc.createElement('div');
            [...det.children].forEach((ch) => {
                if (ch.tagName && ch.tagName.toLowerCase() === 'summary') return;
                bodyWrap.appendChild(ch.cloneNode(true));
            });
            const moved = [...bodyWrap.childNodes];
            if (moved.length === 0) return finish(root.innerHTML);
            if (mainNorm && sumNorm && mainNorm === sumNorm) {
                det.replaceWith(...moved);
            } else if (sumText) {
                const strong = doc.createElement('strong');
                strong.textContent = sumText;
                const br1 = doc.createElement('br');
                det.replaceWith(strong, br1, ...moved);
            } else {
                det.replaceWith(...moved);
            }
            return finish(root.innerHTML);
        } catch {
            return finish(h);
        }
    }

    function liturgyNoteCard() {
        return `
        <div class="rounded-md border border-app-stroke bg-app-elevated p-[18px]">
            <p class="text-[15px] text-app-textSec leading-relaxed">Націсніце на дату, каб адкрыць лекцыянарый. Мясцовыя святы дыяцэзій Беларусі можна ўключыць праз шасцярэнку ў правым верхнім куце.</p>
            <p class="mt-3 text-[15px] text-app-textSec leading-relaxed">Пераклад здзейснены Секцыяй па перакладзе літургічных тэкстаў і афіцыйных дакументаў Касцёла пры ККББ.</p>
            <p class="mt-3 text-[15px] text-app-textSec leading-relaxed">* - гэта значыць, што ёсць успамін ці іншае.</p>
        </div>`;
    }

    function liturgyLegendCard() {
        const light = readAppTheme() === 'beige';
        const rows = [
            [
                ['#2E7D32', 'зялёны'],
                ['#C62828', 'чырвоны'],
            ],
            [
                ['#6A1B9A', 'фіялетавы'],
                ['#E5E7EB', 'белы'],
            ],
            [
                ['#F48FB1', 'ружовы'],
                ['#374151', 'чорны'],
            ],
        ];
        let html =
            '<div class="rounded-md border border-app-stroke bg-app-elevated p-[18px]"><div class="text-[14px] font-bold text-app-textSec">Колеры літургічных адзенняў</div><div class="mt-3 space-y-2">';
        for (const row of rows) {
            html += '<div class="grid grid-cols-2 gap-2">';
            for (const [hex, label] of row) {
                const isWhite = /^#?e5e7eb$/i.test(String(hex || ''));
                const dotBorderClass = light && isWhite ? 'border-app-stroke/80' : 'border-app-stroke/40';
                html += `<div class="flex items-center gap-2 min-w-0">
                    <span class="w-2.5 h-2.5 rounded-full shrink-0 border ${dotBorderClass}" style="background:${hex}"></span>
                    <span class="text-[13px] text-app-textSec truncate">${escapeHtml(label)}</span>
                </div>`;
            }
            html += '</div>';
        }
        html += '</div></div>';
        return html;
    }

    function calendarShellHtml() {
        const monthTitle = formatCalendarMonthYearBe(currentDate);
        const wd = ['НД', 'ПН', 'АЎ', 'СР', 'ЧЦ', 'ПТ', 'СБ'];
        return `
    <div id="calendar-root" class="px-2 pb-8 pt-2 max-w-[480px] mx-auto space-y-2">
        <div class="rounded-md border border-app-stroke bg-app-elevated p-[18px]">
            <div class="flex items-center justify-between gap-2">
                <button type="button" data-action="prev-month" class="w-10 h-10 shrink-0 flex items-center justify-center text-app-text text-xl font-bold hover:bg-white/5 rounded-lg border-0 bg-transparent cursor-pointer">←</button>
                <h2 data-calendar-month-title class="text-center text-app-text font-bold text-[17px] flex-1 leading-tight">${escapeHtml(monthTitle)}</h2>
                <button type="button" data-action="next-month" class="w-10 h-10 shrink-0 flex items-center justify-center text-app-text text-xl font-bold hover:bg-white/5 rounded-lg border-0 bg-transparent cursor-pointer">→</button>
            </div>
            <div class="flex mt-2.5 text-[12px] text-app-textTer font-medium">
                ${wd.map((d) => `<div class="flex-1 text-center">${d}</div>`).join('')}
            </div>
            <div class="relative mt-1.5 min-h-[220px]">
                <div data-calendar-grid class="grid grid-cols-7 gap-[3px]"></div>
            </div>
        </div>
        ${liturgyNoteCard()}
        ${liturgyLegendCard()}
    </div>`;
    }

    function bindCalendarSwipeNavigation() {
        const root = document.getElementById('calendar-root');
        if (!root) return;
        let startX = 0;
        let startY = 0;
        root.addEventListener(
            'touchstart',
            (e) => {
                const t = e.touches && e.touches[0];
                if (!t) return;
                startX = t.clientX;
                startY = t.clientY;
            },
            { passive: true }
        );
        root.addEventListener(
            'touchend',
            (e) => {
                const t = e.changedTouches && e.changedTouches[0];
                if (!t) return;
                const dx = t.clientX - startX;
                const dy = t.clientY - startY;
                if (Math.abs(dx) < 42 || Math.abs(dx) <= Math.abs(dy) * 1.2) return;
                shiftCalendarMonth(dx < 0 ? 1 : -1);
            },
            { passive: true }
        );
    }

    function syncCalendarMonthTitle() {
        const el = document.querySelector('[data-calendar-month-title]');
        if (!el) return;
        el.textContent = formatCalendarMonthYearBe(currentDate);
    }

    function shiftCalendarMonth(deltaMonths) {
        if (!Number.isFinite(deltaMonths) || deltaMonths === 0) return;
        currentDate = currentDate.plus({ months: deltaMonths });
        syncCalendarMonthTitle();
        hydrateCalendar();
    }

    function mapOptionalMemorialColor(name) {
        const n = String(name || '').trim().toLowerCase();
        const m = {
            white: '#E5E7EB',
            red: '#C62828',
            purple: '#6A1B9A',
            violet: '#6A1B9A',
            green: '#2E7D32',
            rose: '#F48FB1',
            pink: '#F48FB1',
            black: '#374151',
        };
        return m[n] || '#E5E7EB';
    }

    function hexToRgb(hex) {
        const s = String(hex || '').trim().replace(/^#/, '');
        if (!/^[0-9a-f]{6}$/i.test(s)) return null;
        return {
            r: parseInt(s.slice(0, 2), 16),
            g: parseInt(s.slice(2, 4), 16),
            b: parseInt(s.slice(4, 6), 16),
        };
    }

    function blendHex(baseHex, tintHex, alpha) {
        const b = hexToRgb(baseHex);
        const t = hexToRgb(tintHex);
        const a = Math.max(0, Math.min(1, Number(alpha) || 0));
        if (!b || !t) return baseHex;
        const r = Math.round(b.r * (1 - a) + t.r * a);
        const g = Math.round(b.g * (1 - a) + t.g * a);
        const b2 = Math.round(b.b * (1 - a) + t.b * a);
        return `rgb(${r},${g},${b2})`;
    }

    function lightenColor(color, amount) {
        const a = Math.max(0, Math.min(1, Number(amount) || 0));
        return blendHex(color, '#FFFFFF', a);
    }

    function readableTextOnColor(bgColor) {
        const color = String(bgColor || '').trim();
        let rgb = null;
        const rgbMatch = color.match(/^rgb\((\d+),(\d+),(\d+)\)$/i);
        if (rgbMatch) {
            rgb = {
                r: Number(rgbMatch[1]),
                g: Number(rgbMatch[2]),
                b: Number(rgbMatch[3]),
            };
        } else {
            rgb = hexToRgb(color);
        }
        if (!rgb) return '#FFFFFF';
        const luma = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
        return luma >= 150 ? '#111827' : '#FFFFFF';
    }

    function readableTextOnColors(bgA, bgB) {
        const cWhite = '#FFFFFF';
        const cDark = '#111827';
        const scoreWhite = Math.min(contrastRatio(cWhite, bgA), contrastRatio(cWhite, bgB));
        const scoreDark = Math.min(contrastRatio(cDark, bgA), contrastRatio(cDark, bgB));
        return scoreWhite >= scoreDark ? cWhite : cDark;
    }

    function readableTextOnPalette(bgColors) {
        const list = Array.isArray(bgColors) ? bgColors.filter(Boolean) : [];
        if (!list.length) return '#FFFFFF';
        if (list.length === 1) return readableTextOnColor(list[0]);
        if (list.length === 2) return readableTextOnColors(list[0], list[1]);
        const cWhite = '#FFFFFF';
        const cDark = '#111827';
        const scoreWhite = Math.min(...list.map((bg) => contrastRatio(cWhite, bg)));
        const scoreDark = Math.min(...list.map((bg) => contrastRatio(cDark, bg)));
        return scoreWhite >= scoreDark ? cWhite : cDark;
    }

    function relativeLuminance(color) {
        const rgb = (() => {
            const s = String(color || '').trim();
            const m = s.match(/^rgb\((\d+),(\d+),(\d+)\)$/i);
            if (m) return { r: Number(m[1]), g: Number(m[2]), b: Number(m[3]) };
            return hexToRgb(s);
        })();
        if (!rgb) return 0;
        const toLin = (v) => {
            const srgb = v / 255;
            return srgb <= 0.03928 ? srgb / 12.92 : Math.pow((srgb + 0.055) / 1.055, 2.4);
        };
        const r = toLin(rgb.r);
        const g = toLin(rgb.g);
        const b = toLin(rgb.b);
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    }

    function contrastRatio(fg, bg) {
        const l1 = relativeLuminance(fg);
        const l2 = relativeLuminance(bg);
        const hi = Math.max(l1, l2);
        const lo = Math.min(l1, l2);
        return (hi + 0.05) / (lo + 0.05);
    }

    function isNearWhiteHex(hex) {
        const rgb = hexToRgb(hex);
        if (!rgb) return false;
        return rgb.r >= 224 && rgb.g >= 224 && rgb.b >= 224;
    }

    /**
     * 42 клеткі месяца без API: нумары дзён і шэрыя колеры (як афлайн у дадатку), затым падкарэктуем з сервера.
     * Адлюстраванне сеткі як у liturgy_calendar_month.php: першае нядзеля да 1-га.
     */
    function buildPlaceholderCalendarDays(year, month) {
        const first = luxon.DateTime.fromObject({ year, month, day: 1 });
        const daysFromSunday = first.weekday === 7 ? 0 : first.weekday;
        const gridStart = first.minus({ days: daysFromSunday });
        const todayIso = luxon.DateTime.now().toISODate();
        const days = [];
        for (let i = 0; i < 42; i++) {
            const d = gridStart.plus({ days: i });
            const key = d.toISODate();
            days.push({
                date: key,
                day: d.day,
                is_current_month: d.month === month,
                is_today: key === todayIso,
                lectionary_count: 1,
                liturgical_color_hex: '#6B7280',
                has_optional_memorial: false,
                optional_memorial_color: '',
            });
        }
        return days;
    }

    function getLectionaryCountForCalendarDay(day) {
        const titleParts = new Set();
        const addTitleParts = (raw) => {
            const s = String(raw || '').trim();
            if (!s) return;
            s
                .split(/\s+альбо\s+|[\/;\n]+/i)
                .map((x) => x.trim())
                .filter(Boolean)
                .forEach((x) => titleParts.add(x.toLowerCase()));
        };
        addTitleParts(day?.title);
        addTitleParts(day?.auto_title);
        addTitleParts(day?.optional_memorial_title);
        if (titleParts.size > 1) return titleParts.size;

        const raw =
            day?.lectionary_count ??
            day?.lectionaries_count ??
            day?.readings_count ??
            day?.lections_count ??
            day?.lectionary_variants_count;
        const n = Number(raw);
        if (Number.isFinite(n) && n > 0) return n;

        const arrCandidates = [
            day?.lectionaries,
            day?.lections,
            day?.readings_variants,
            day?.liturgies,
        ];
        for (const arr of arrCandidates) {
            if (Array.isArray(arr) && arr.length > 0) return arr.length;
        }

        const readingsHtml = String(day?.readings_full || day?.readings || '').trim();
        if (readingsHtml) {
            const detailsCount = (readingsHtml.match(/<details\b/gi) || []).length;
            if (detailsCount > 0) return detailsCount;
        }

        return 1;
    }

    /** Значок «несколькі загалоўкаў»: зорачка ў правым верхнім куце. */
    function calendarMultiLectionaryBadgeHtml(dayFg, sizePx = 12) {
        const s = sizePx;
        const insetX = Math.round((4 * s) / 12);
        const insetY = Math.round((3 * s) / 12);
        return `<span class="pointer-events-none leading-none font-bold" style="position:absolute;right:${insetX}px;top:${insetY}px;color:${dayFg};font-size:${s}px;line-height:1" aria-hidden="true">*</span>`;
    }

    function renderCalendarCell(day) {
        const inMonth = day.is_current_month;
        const isToday = day.is_today;
        const primary = day.liturgical_color_hex || '#6B7280';
        const optionalColors = Array.isArray(day.optional_memorial_colors)
            ? day.optional_memorial_colors.map((v) => String(v || '').trim()).filter(Boolean)
            : [];
        const firstOptionalColor = optionalColors[0] || String(day.optional_memorial_color || '').trim();
        const allHexes = [primary];
        if (day.has_optional_memorial) {
            if (optionalColors.length) {
                optionalColors.forEach((name) => allHexes.push(mapOptionalMemorialColor(name)));
            } else if (firstOptionalColor) {
                allHexes.push(mapOptionalMemorialColor(firstOptionalColor));
            }
        }
        const uniqueHexes = Array.from(
            new Set(
                allHexes
                    .map((h) => String(h || '').toUpperCase())
                    .filter(Boolean),
            ),
        ).slice(0, 3);
        const light = readAppTheme() === 'beige';

        let bg;
        let stroke;
        let strokeW;
        if (isToday) {
            bg = light ? '#F2EBE0' : '#1B2030';
            stroke = light ? '#A68B6F' : '#C9BE9A';
            strokeW = 2;
        } else if (inMonth) {
            bg = light ? '#F6EFE2' : '#171A2A';
            stroke = light ? '#C6B39A' : '#39415E';
            strokeW = 1;
        } else {
            bg = light ? '#EADFCE' : '#13172C';
            stroke = light ? '#C6B39A' : '#39415E';
            strokeW = 1;
        }
        const fg = inMonth ? (light ? '#3F3121' : '#FFFFFF') : light ? '#7C6650' : '#7A8499';
        const opacity = inMonth ? '' : 'opacity-[0.72]';
        const tintedPalette = inMonth
            ? uniqueHexes.map((hex) =>
                  lightenColor(blendHex(bg, hex, isToday ? 0.55 : 0.54), 0.05),
              )
            : [bg];
        let bgStyle = `background-color:${tintedPalette[0]};`;
        if (tintedPalette.length === 2) {
            bgStyle = `background:linear-gradient(90deg,${tintedPalette[0]} 0 50%,${tintedPalette[1]} 50% 100%);`;
        } else if (tintedPalette.length >= 3) {
            bgStyle = `background:linear-gradient(90deg,${tintedPalette[0]} 0 33.333%,${tintedPalette[1]} 33.333% 66.666%,${tintedPalette[2]} 66.666% 100%);`;
        }
        const adaptiveFg = inMonth ? readableTextOnPalette(tintedPalette) : fg;
        const bothWhiteLiturgicalColors =
            uniqueHexes.length > 1 && uniqueHexes.every((hex) => isNearWhiteHex(hex));
        const isDarkTheme = readAppTheme() === 'current';
        let dayFg = bothWhiteLiturgicalColors ? (isDarkTheme ? '#FFFFFF' : '#111827') : adaptiveFg;
        if (isDarkTheme && inMonth) {
            dayFg = '#FFFFFF';
        }
        const showOmega = getLectionaryCountForCalendarDay(day) > 1;

        const todayGlow =
            isToday && inMonth
                ? light
                    ? 'box-shadow:0 0 0 1px rgba(139,111,86,0.14);'
                    : 'box-shadow:0 0 0 1px rgba(201,190,154,0.18);'
                : '';
        return `
            <button type="button" data-date="${escapeHtml(day.date)}"
                class="calendar-cell-app relative flex flex-col items-center justify-center gap-[5px] rounded-[10px] border border-solid p-0 box-border cursor-pointer ${opacity}"
                style="aspect-ratio:1/1;${bgStyle}border-color:${stroke};border-width:${strokeW}px;${todayGlow}">
                ${showOmega ? calendarMultiLectionaryBadgeHtml(dayFg) : ''}
                <span class="text-base font-bold leading-none" style="color:${dayFg}">${day.day}</span>
            </button>`;
    }

    /** "Сегодня" определяем на клиенте, чтобы избежать скачка при отличии часового пояса API. */
    function normalizeCalendarToday(days) {
        const todayIso = luxon.DateTime.now().toISODate();
        return (Array.isArray(days) ? days : []).map((d) => ({
            ...d,
            is_today: String(d?.date || '') === todayIso,
        }));
    }

    function calendarMonthCacheKey(year, month, diocesesParam) {
        return `${year}-${month}|${String(diocesesParam || '')}`;
    }

    function readCachedCalendarMonthDays(year, month, diocesesParam) {
        const key = calendarMonthCacheKey(year, month, diocesesParam);
        const cached = calendarMonthCache.get(key);
        if (!cached || !Array.isArray(cached.days)) return null;
        return cached.days;
    }

    function writeCachedCalendarMonthDays(year, month, diocesesParam, days) {
        const key = calendarMonthCacheKey(year, month, diocesesParam);
        calendarMonthCache.set(key, { days: Array.isArray(days) ? days : [] });
    }

    async function prefetchCalendarMonthInBackground(year, month) {
        if (!isApiConfigured()) return;
        const dio = calendarDiocesesApiParam();
        if (readCachedCalendarMonthDays(year, month, dio)) return;
        try {
            const calParams = { year: String(year), month: String(month) };
            if (dio) calParams.dioceses = dio;
            const { ok, data } = await apiFetch('liturgy_calendar_month.php', calParams);
            if (!ok || data?.error) return;
            const days = normalizeCalendarToday(data.days || []);
            writeCachedCalendarMonthDays(year, month, dio, days);
        } catch {
            /* ignore: prefetch should never block UI */
        }
    }

    async function hydrateCalendar() {
        const grid = document.querySelector('[data-calendar-grid]');
        if (!grid) return;

        const gen = ++calendarHydrateGeneration;
        const y = currentDate.year;
        const m = currentDate.month;
        const { apiBaseUrl } = getApiConfig();

        if (!isApiConfigured()) {
            grid.innerHTML = `<p class="col-span-7 text-center text-app-textTer py-10 text-sm">Наладзьце API: проксі (useServerProxy) або ключ у api-config.js</p>`;
            return;
        }

        const dio = calendarDiocesesApiParam();
        const cachedDays = readCachedCalendarMonthDays(y, m, dio);
        if (cachedDays && cachedDays.length > 0) {
            grid.innerHTML = cachedDays.map(renderCalendarCell).join('');
        } else {
            // Keep current grid until fresh month arrives to avoid visual jump.
            if (!grid.children || grid.children.length === 0) {
                const placeholder = buildPlaceholderCalendarDays(y, m);
                grid.innerHTML = placeholder.map(renderCalendarCell).join('');
            }
        }

        function showCalendarFetchError(htmlMsg) {
            const wrap = grid.parentElement;
            if (!wrap) return;
            let banner = wrap.querySelector('[data-calendar-fetch-error]');
            if (!banner) {
                banner = document.createElement('div');
                banner.setAttribute('data-calendar-fetch-error', '');
                wrap.insertBefore(banner, grid);
            }
            banner.className =
                'mb-2 p-3 rounded-md text-sm bg-red-950/40 border border-red-500/30 text-app-error';
            banner.innerHTML = htmlMsg;
        }

        function clearCalendarFetchError() {
            const wrap = grid.parentElement;
            wrap?.querySelector('[data-calendar-fetch-error]')?.remove();
        }

        try {
            const calParams = { year: String(y), month: String(m) };
            if (dio) calParams.dioceses = dio;
            const { ok, data } = await apiFetch('liturgy_calendar_month.php', calParams);
            if (gen !== calendarHydrateGeneration) return;
            if (!ok || data.error) {
                const isNetwork = data.error === 'network_error';
                const hint = isNetwork ? apiNetworkFailureHint(apiBaseUrl) : '';
                const msg = escapeHtml(data.message || data.error || 'Памылка запыту');
                showCalendarFetchError(`${msg}${hint}`);
                return;
            }

            clearCalendarFetchError();
            const days = normalizeCalendarToday(data.days || []);
            writeCachedCalendarMonthDays(y, m, dio, days);
            grid.innerHTML = days.map(renderCalendarCell).join('');
        } catch {
            if (gen !== calendarHydrateGeneration) return;
            showCalendarFetchError('Не атрымалася загрузіць каляндар.');
        }
    }

    async function loadLiturgyDay(dateStr) {
        if (!isApiConfigured()) {
            return { ok: false, data: { error: 'no_api_key', message: 'Няма канфігурацыі API (проксі або ключ)' } };
        }
        const dio = calendarDiocesesApiParam();
        const dayParams = { date: dateStr };
        if (dio) dayParams.dioceses = dio;
        return apiFetch('liturgy_day.php', dayParams);
    }

    /** Як colorIntFromName у LiturgyDayFragment.kt — для optional_memorial_color (імя з API). */
    function liturgyColorNameToHex(name) {
        const n = String(name || '')
            .trim()
            .toLowerCase();
        const map = {
            green: '#2E7D32',
            red: '#C62828',
            purple: '#6A1B9A',
            violet: '#6A1B9A',
            white: '#E5E7EB',
            rose: '#F48FB1',
            pink: '#F48FB1',
            black: '#374151',
            gray: '#6B7280',
            grey: '#6B7280',
        };
        return map[n] || '#6B7280';
    }

    function liturgySanitizeHex(raw) {
        const s = String(raw || '').trim();
        if (/^#[0-9A-Fa-f]{6}$/.test(s)) return s;
        if (/^#[0-9A-Fa-f]{3}$/.test(s)) {
            const r = s[1],
                g = s[2],
                b = s[3];
            return `#${r}${r}${g}${g}${b}${b}`;
        }
        return '';
    }

    function liturgyMainColorHex(data, err) {
        if (err) return '#6B7280';
        const fromApi = liturgySanitizeHex(data.liturgical_color_hex);
        if (fromApi) return fromApi;
        return liturgyColorNameToHex(data.liturgical_color);
    }

    function liturgyOptionalColorHex(data) {
        const fromApi = liturgySanitizeHex(data.optional_memorial_color_hex);
        if (fromApi) return fromApi;
        return liturgyColorNameToHex(data.optional_memorial_color);
    }

    function liturgyOptionalColorHexes(data) {
        const list = Array.isArray(data?.optional_memorial_colors) ? data.optional_memorial_colors : [];
        const out = list
            .map((name) => liturgyColorNameToHex(name))
            .filter((hex) => !!liturgySanitizeHex(hex));
        if (out.length) return out;
        return [liturgyOptionalColorHex(data)];
    }

    /** Квадрат колеру дня — як сімвал ■ у шапцы LiturgyDayFragment. */
    function liturgyDayColorSwatchHtml(hex) {
        const safe = liturgySanitizeHex(hex) || '#6B7280';
        return `<span class="liturgy-day-swatch shrink-0 w-3.5 h-3.5 rounded-sm border border-app-stroke/60 mt-1.5" style="background-color:${safe}" role="img" aria-hidden="true"></span>`;
    }

    /** API злучае даброўныя успаміны праз «альбо» ў адным радку — як splitOptionalMemorialTitles у LiturgyDayFragment.kt */
    function splitOptionalMemorialTitles(combined) {
        const raw = String(combined || '').trim();
        if (!raw) return [];
        return raw
            .split(/\s+альбо\s+/i)
            .map((s) => s.trim())
            .filter(Boolean);
    }

    function liturgyDayTitleBlockHtml(data, err, title, optionalCombinedOverride) {
        const mainHex = liturgyMainColorHex(data, err);
        const optRaw =
            optionalCombinedOverride !== undefined && optionalCombinedOverride !== null
                ? String(optionalCombinedOverride)
                : String(data.optional_memorial_title || '');
        const optParts = !err ? splitOptionalMemorialTitles(optRaw) : [];
        let html = `<div class="mt-1 space-y-1.5">
            <div class="flex items-start gap-2.5">
                ${liturgyDayColorSwatchHtml(mainHex)}
                <h2 class="text-[18px] font-bold text-app-text leading-snug flex-1 min-w-0">${escapeHtml(title)}</h2>
            </div>`;
        if (optParts.length) {
            const optHexes = liturgyOptionalColorHexes(data);
            optParts.forEach((part, idx) => {
                const optHex = optHexes[idx] || optHexes[optHexes.length - 1] || '#E5E7EB';
                html += `<p class="text-[13px] text-app-textSec leading-snug pl-6">альбо</p>
            <div class="flex items-start gap-2.5">
                ${liturgyDayColorSwatchHtml(optHex)}
                <p class="text-[18px] font-bold text-app-text leading-snug flex-1 min-w-0">${escapeHtml(String(part || '').trim())}</p>
            </div>`;
        });
        }
        html += '</div>';
        return html;
    }

    function showDayDetail(payload, dateStr, ok) {
        const container = document.getElementById('day-detail');
        if (!container) return;

        const date = luxon.DateTime.fromISO(dateStr);
        const data = payload || {};
        const err = !ok || data.error;
        const title = liturgyClientResolveMainTitle(data.title, data.auto_title, dateStr);
        const optForUi = liturgyClientOptionalMemorialForDisplay(data, dateStr);
        const subtitle = formatDateDayMonthYearBe(date);

        let body = '';
        if (err) {
            const msg = escapeHtml(data.message || data.error || 'Не ўдалося загрузіць дзень');
            body = `<div class="p-4 bg-red-950/40 border border-red-500/30 rounded-md text-app-error text-sm">${msg}</div>`;
        } else if (data.readings_full) {
            const readingsHtml = flattenSingleLiturgyDetailsForWeb(data.readings_full, dateStr, title);
            body = `<div class="totus-read-15 readings-content totus-reading-detail">${readingsHtml}</div>`;
        } else {
            body = `<p class="text-app-textTer italic text-sm">Для гэтага дня няма тэксту чытанняў.</p>`;
        }

        const titleBlock = liturgyDayTitleBlockHtml(data, err, title, optForUi);

        container.innerHTML = `
        <div class="px-2 pb-8 pt-2 max-w-[480px] mx-auto">
            <div class="rounded-md border border-app-stroke bg-app-elevated px-[18px] pt-[18px] pb-4">
                <p class="text-[14px] text-app-textSec leading-snug">${escapeHtml(subtitle)}</p>
                ${titleBlock}
                <div class="mt-2 text-app-text">${body}</div>
            </div>
        </div>`;
    }

    /** Кнопка «Назад» у браўзеры / жэст: History API + існуючы goBack(). */
    let totusHistorySyncSkipPop = false;
    let totusHistoryLen = 0;
    /** Лічыльнік «Назад» на галоўным без унутранага стэка: 1-ы — застаёмся, 2-і — confirm (без таймера). */
    let totusExitBackPresses = 0;
    /** true калі экран наладаў перакладу адкрыты праз шасцярэнку (ёсць адпаведны pushState). */
    let scriptureTrOverlayFromGear = false;

    function totusHistoryMarkForward() {
        totusExitBackPresses = 0;
        try {
            history.pushState({ totus: 1 }, '', window.location.pathname + window.location.search || '');
            totusHistoryLen++;
        } catch (_) {
            /* file:// або абмежаванні асяроддзя */
        }
    }

    /** Другі запіс у стэсе пасля replaceState — каб першы «Назад» не закрыў укладку/PWA. */
    function totusHistoryPushExitBlocker() {
        try {
            const u = window.location.pathname + window.location.search || '';
            history.pushState({ totus: 1 }, '', u || '/');
        } catch (_) {
            /* ignore */
        }
    }

    function totusHistoryInstall() {
        if (window.__totusHistoryInstalled) return;
        window.__totusHistoryInstalled = true;
        try {
            const u = window.location.pathname + window.location.search || '';
            history.replaceState({ totus: 1 }, '', u || '/');
        } catch (_) {
            /* ignore */
        }
        totusHistoryPushExitBlocker();
        window.addEventListener('popstate', () => {
            if (totusHistorySyncSkipPop) {
                totusHistorySyncSkipPop = false;
                if (totusHistoryLen > 0) totusHistoryLen--;
                return;
            }
            if (totusHistoryLen > 0) {
                totusExitBackPresses = 0;
                goBack();
                totusHistoryLen--;
                return;
            }
            goBack();
            totusExitBackPresses++;
            if (totusExitBackPresses >= 2) {
                totusExitBackPresses = 0;
                const ok = window.confirm(
                    'Вы уверены, что хотите выйти со страницы?'
                );
                if (ok) {
                    try {
                        history.go(-1);
                    } catch (_) {
                        /* ignore */
                    }
                    return;
                }
                totusHistoryPushExitBlocker();
                return;
            }
            totusHistoryPushExitBlocker();
        });
    }

    function exitCalendarSettings() {
        const ret = calendarSettingsReturnView;
        calendarSettingsReturnView = null;
        if (ret === 'day') {
            currentView = 'day';
        } else {
            currentView = 'calendar';
        }
        renderApp();
        if (ret === 'day' && currentDate && currentDate.isValid) {
            const dateStr = currentDate.toISODate();
            void loadLiturgyDay(dateStr).then(({ ok, data }) => showDayDetail(data, dateStr, ok));
        }
    }

    function goBack() {
        if (currentView === 'calendar-settings') {
            exitCalendarSettings();
            return;
        }
        if (currentView === 'solemnities' && solemnitiesSettingsOpen) {
            solemnitiesSettingsOpen = false;
            renderApp();
            void hydrateSolemnities();
            return;
        }
        if (currentView === 'day') {
            currentView = 'calendar';
        } else if (currentView === 'scripture') {
            if (scTranslationSettingsOpen) {
                scTranslationSettingsOpen = false;
                scriptureTrOverlayFromGear = false;
                hydrateScriptureView();
                return;
            }
            if (scPanel) {
                scPanel = null;
                if (scriptureWordSearchDebounceTimer) {
                    clearTimeout(scriptureWordSearchDebounceTimer);
                    scriptureWordSearchDebounceTimer = null;
                }
                hydrateScriptureView();
                refreshToolbarRightActions();
                syncScriptureToolbarTitle();
                return;
            }
            if (scChapterNum !== null) {
                scChapterNum = null;
                scFocusVerse = null;
                renderApp();
                return;
            }
            if (scBookIdx !== null) {
                scBookIdx = null;
                scFocusVerse = null;
                renderApp();
                return;
            }
            currentView = 'home';
        } else if (currentView === 'prayers') {
            if (prayerNav.screen === 'detail') {
                if (prayerBeforeDetail) {
                    prayerNav = prayerBeforeDetail.nav;
                    prayerView = prayerBeforeDetail.view;
                    prayerSearchDraft = prayerBeforeDetail.draft;
                    prayerBeforeDetail = null;
                } else {
                    prayerNav = { screen: 'categories' };
                    prayerView = 'list';
                    prayerSearchDraft = '';
                }
                renderApp();
                return;
            }
            if (prayerView === 'search') {
                if (prayerSearchDebounceTimer) {
                    clearTimeout(prayerSearchDebounceTimer);
                    prayerSearchDebounceTimer = null;
                }
                prayerView = 'list';
                renderApp();
                return;
            }
            if (prayerNav.screen === 'bookmarks_all') {
                prayerNav = { screen: 'categories' };
                renderApp();
                return;
            }
            if (prayerNav.screen === 'prayers') {
                const subs = getSubcategoryNamesFromData(
                    prayersCache || [],
                    categoriesCache || [],
                    prayerNav.category
                );
                prayerNav =
                    subs.length === 0
                        ? { screen: 'categories' }
                        : { screen: 'subcategories', category: prayerNav.category };
                renderApp();
                return;
            }
            if (prayerNav.screen === 'subcategories') {
                prayerNav = { screen: 'categories' };
                renderApp();
                return;
            }
            currentView = 'home';
        } else if (isSongbookLikeView()) {
            if (songbookDetailId != null) {
                songbookDetailId = null;
                if (songbookStateBeforeDetail) {
                    songbookView = songbookStateBeforeDetail.view;
                    songbookSearchQuery = songbookStateBeforeDetail.query;
                    songbookStateBeforeDetail = null;
                }
                renderApp();
                return;
            }
            if (songbookView === 'search') {
                if (songbookSearchDebounceTimer) {
                    clearTimeout(songbookSearchDebounceTimer);
                    songbookSearchDebounceTimer = null;
                }
                songbookView = 'list';
                songbookSearchQuery = '';
                renderApp();
                return;
            }
            if (songbookBookmarksOnly) {
                songbookBookmarksOnly = false;
                renderApp();
                return;
            }
            currentView = 'home';
        } else if (currentView === 'ordo-missae') {
            currentView = 'home';
        } else if (currentView === 'solemnities') {
            currentView = 'home';
        } else if (currentView === 'calendar') {
            currentView = 'home';
        } else if (currentView === 'settings') {
            currentView = 'home';
        } else if (currentView === 'about') {
            currentView = 'home';
        }
        renderApp();
    }

    async function selectDate(dateStr) {
        calendarSettingsReturnView = null;
        currentDate = luxon.DateTime.fromISO(dateStr);
        currentView = 'day';
        totusHistoryMarkForward();
        renderApp();
        const { ok, data } = await loadLiturgyDay(dateStr);
        showDayDetail(data, dateStr, ok);
    }

    let prayerSearchDraft = '';

    function attachPrayerSearchIndex(prayers) {
        for (const p of prayers || []) {
            p._totusSearchBlob = [p.title, p.text, p.category, p.subcategory, p.additional_categories]
                .join(' ')
                .toLowerCase();
        }
    }

    async function ensurePrayersLoaded() {
        if (prayersCache !== null) return;
        prayersLoadError = null;
        prayersLoadErrorIsNetwork = false;
        if (!isApiConfigured()) {
            prayersLoadError = 'Наладзьце API: WebApp/api/proxy-secrets.php (useServerProxy) або apiKey у api-config.js';
            prayersCache = [];
            categoriesCache = [];
            return;
        }
        const [prRes, catRes] = await Promise.all([
            apiFetch('prayers.php'),
            apiFetch('prayer_category_meta.php'),
        ]);
        if (!prRes.ok || prRes.data.error) {
            const isNet = prRes.status === 0 || prRes.data.error === 'network_error';
            prayersLoadErrorIsNetwork = isNet;
            prayersLoadError = isNet
                ? humanizeClientFetchError(prRes.data.message || '')
                : prRes.data.message || prRes.data.error || 'Малітвы: памылка';
            prayersCache = [];
        } else {
            prayersCache = Array.isArray(prRes.data) ? prRes.data : [];
            attachPrayerSearchIndex(prayersCache);
        }
        if (!catRes.ok || catRes.data.error) {
            categoriesCache = [];
        } else {
            categoriesCache = Array.isArray(catRes.data) ? catRes.data : [];
        }
    }

    /** Радок спісу малітваў — стыль як у item_prayer_tree; у спеўніку радкі без шэврона (акрамя разгартаючых катэгорый). */
    function prayerTreeRowHtml(p) {
        const id = Number(p.id);
        const label = escapeHtml(String(p.title || '').trim() || '—');
        return `<button type="button" data-prayer-id="${id}" class="prayer-open w-full text-left rounded-md border border-app-stroke bg-app-elevated border-solid shadow-none hover:bg-white/[0.03] active:bg-white/[0.05] transition-colors cursor-pointer text-inherit p-0">
            <div class="flex items-center min-h-16 py-3.5 pl-[18px] pr-3 box-border">
                <div class="flex-1 min-w-0 pr-2">
                    <div class="text-app-text text-[17px] leading-snug" style="line-height:1.35">${label}</div>
                </div>
                <span class="shrink-0 w-7 h-7 flex items-center justify-center text-app-textTer" aria-hidden="true"><i class="fas fa-chevron-right text-sm"></i></span>
            </div>
        </button>`;
    }

    function renderPrayerBrowse() {
        const root = document.getElementById('prayer-browse-root');
        if (!root) return;

        if (prayersLoadError) {
            const { apiBaseUrl } = getApiConfig();
            const hint = prayersLoadErrorIsNetwork ? apiNetworkFailureHint(apiBaseUrl) : '';
            root.innerHTML = `<div class="p-4 bg-red-950/40 border border-red-500/30 text-app-error rounded-md text-sm">${escapeHtml(prayersLoadError)}${hint}</div>`;
            return;
        }

        const prayers = prayersCache || [];
        const meta = categoriesCache || [];

        if (prayerNav.screen === 'bookmarks_all') {
            const bm = getBookmarksSet();
            let rows = prayers.filter((p) => bm.has(Number(p.id)));
            rows.sort((a, b) => {
                const s = Number(a.sort_order) - Number(b.sort_order);
                if (s !== 0) return s;
                return Number(a.id) - Number(b.id);
            });
            if (rows.length === 0) {
                root.innerHTML = `<p class="text-app-textTer text-center py-12 text-sm">${
                    bm.size === 0
                        ? 'Пакуль няма выбраных малітваў.'
                        : 'Нічога не знойдзена.'
                }</p>`;
                return;
            }
            root.innerHTML = `<div class="flex flex-col gap-2">${rows.map((p) => prayerTreeRowHtml(p)).join('')}</div>`;
            return;
        }

        if (prayerNav.screen === 'categories') {
            let cats = getCategoryNamesFromData(prayers, meta);
            if (cats.length === 0) {
                root.innerHTML = `<p class="text-app-textTer text-center py-12 text-sm">Нічога не знойдзена.</p>`;
                return;
            }
            root.innerHTML = `<div class="space-y-2">${cats
                .map(
                    (cat) => `
                <button type="button" data-prayer-pick-cat="${encodeURIComponent(cat)}" class="${APP_LIST_ROW_BTN_CLASS}">
                    <div class="${APP_LIST_ROW_INNER_CLASS}">
                        <span class="font-medium text-app-text flex-1 min-w-0 leading-snug">${escapeHtml(cat)}</span>
                        <span class="shrink-0 w-7 h-7 flex items-center justify-center text-app-textTer" aria-hidden="true"><i class="fas fa-chevron-right text-sm"></i></span>
                    </div>
                </button>`
                )
                .join('')}</div>`;
            return;
        }

        if (prayerNav.screen === 'subcategories') {
            const cat = prayerNav.category;
            let subs = getSubcategoryNamesFromData(prayers, meta, cat);
            let catPrayers = getPrayersInSubcategoryJs(prayers, cat, NO_SUBCATEGORY_TITLE);
            if (subs.length === 0 && catPrayers.length === 0) {
                root.innerHTML = `<p class="text-app-textTer text-center py-12 text-sm">Нічога не знойдзена.</p>`;
                return;
            }
            const parentEnc = encodeURIComponent(cat);
            const parts = [];
            for (const sub of subs) {
                parts.push(`
                <button type="button" data-prayer-pick-sub="${encodeURIComponent(sub)}" data-prayer-pick-parent="${parentEnc}" class="${APP_LIST_ROW_BTN_CLASS}">
                    <div class="${APP_LIST_ROW_INNER_CLASS}">
                        <span class="font-medium text-app-text flex-1 min-w-0 leading-snug">${escapeHtml(sub)}</span>
                        <span class="shrink-0 w-7 h-7 flex items-center justify-center text-app-textTer" aria-hidden="true"><i class="fas fa-chevron-right text-sm"></i></span>
                    </div>
                </button>`);
            }
            for (const p of catPrayers) {
                parts.push(prayerTreeRowHtml(p));
            }
            root.innerHTML = `<div class="flex flex-col gap-2">${parts.join('')}</div>`;
            return;
        }

        let rows = getPrayersInSubcategoryJs(prayers, prayerNav.category, prayerNav.subcategory);

        if (rows.length === 0) {
            root.innerHTML = `<p class="text-app-textTer text-center py-12 text-sm">Нічога не знойдзена.</p>`;
            return;
        }

        root.innerHTML = `<div class="flex flex-col gap-2">${rows.map((p) => prayerTreeRowHtml(p)).join('')}</div>`;
    }

    function hydratePrayerSearchResults() {
        const statusEl = document.getElementById('prayer-search-status');
        const resRoot = document.getElementById('prayer-search-results');
        const qInput = document.getElementById('prayer-search-query');
        if (!statusEl || !resRoot) return;
        const qVal = qInput ? qInput.value : prayerSearchDraft;
        prayerSearchDraft = qVal;
        const all = prayersCache || [];
        if (prayersLoadError) {
            const { apiBaseUrl } = getApiConfig();
            const hint = prayersLoadErrorIsNetwork ? apiNetworkFailureHint(apiBaseUrl) : '';
            statusEl.classList.add('hidden');
            resRoot.innerHTML = `<div class="p-4 bg-red-950/40 border border-red-500/30 text-app-error rounded-md text-sm">${escapeHtml(prayersLoadError)}${hint}</div>`;
            return;
        }
        if (all.length === 0) {
            statusEl.textContent = 'Увядзіце пошукавы запыт — спіс абнавіцца адразу.';
            statusEl.classList.remove('hidden');
            resRoot.innerHTML = '';
            return;
        }
        const trimmed = String(qVal || '').trim();
        if (trimmed === '') {
            statusEl.textContent = 'Увядзіце пошукавы запыт — спіс абнавіцца адразу.';
            statusEl.classList.remove('hidden');
            resRoot.innerHTML = '';
            return;
        }
        const nq = trimmed.toLowerCase();
        const rows = all
            .filter((p) => {
                const blob = p._totusSearchBlob;
                if (blob) return blob.includes(nq);
                return [p.title, p.text, p.category, p.subcategory, p.additional_categories]
                    .join(' ')
                    .toLowerCase()
                    .includes(nq);
            })
            .sort((a, b) => {
                const s = Number(a.sort_order) - Number(b.sort_order);
                if (s !== 0) return s;
                return Number(a.id) - Number(b.id);
            });
        if (rows.length === 0) {
            statusEl.textContent = 'Нічога не знойдзена. Паспрабуйце іншы запыт.';
            statusEl.classList.remove('hidden');
            resRoot.innerHTML = '';
            return;
        }
        statusEl.classList.add('hidden');
        resRoot.innerHTML = `<div class="flex flex-col gap-2">${rows.map((p) => prayerTreeRowHtml(p)).join('')}</div>`;
    }

    function renderPrayerDetail() {
        const root = document.getElementById('prayer-detail-root');
        if (!root) return;
        const pid = Number(prayerNav.prayerId);
        const p = (prayersCache || []).find((x) => Number(x.id) === pid);
        if (!p) {
            root.innerHTML = `<p class="text-app-textTer text-center py-12 text-sm">Малітоўня не знойдзена.</p>`;
            return;
        }
        const rawText = p.text || '';
        const useHtml = stringLooksLikeHtmlFragment(rawText);
        const bodyInner = useHtml
            ? sanitizePrayerHtmlForWebDisplay(rawText)
            : escapeHtml(normalizePrayerTextForDisplay(rawText));
        const bodyClass = useHtml
            ? 'totus-read-18 p-4 text-app-text totus-reading-detail prayer-detail-html'
            : 'totus-read-18 p-4 text-app-text totus-reading-detail whitespace-pre-wrap';
        root.innerHTML = `
        <div class="rounded-md border border-app-stroke bg-app-elevated overflow-hidden">
            <div class="${bodyClass}">${bodyInner}</div>
        </div>`;
    }

    async function hydratePrayers() {
        const loading = document.getElementById('prayers-loading');
        const listScreen = document.getElementById('prayer-list-screen');
        const searchScreen = document.getElementById('prayer-search-screen');
        const browseRoot = document.getElementById('prayer-browse-root');
        const detailRoot = document.getElementById('prayer-detail-root');
        const searchEntry = document.getElementById('prayer-search-entry');
        const qInput = document.getElementById('prayer-search-query');

        if (prayerNav.screen === 'detail') {
            if (listScreen) listScreen.classList.add('hidden');
            if (searchScreen) searchScreen.classList.add('hidden');
            if (detailRoot) detailRoot.classList.remove('hidden');
            if (loading) loading.classList.remove('hidden');
            await ensurePrayersLoaded();
            if (loading) loading.classList.add('hidden');
            renderPrayerDetail();
            return;
        }

        if (detailRoot) detailRoot.classList.add('hidden');

        if (prayerView === 'search') {
            if (listScreen) listScreen.classList.add('hidden');
            if (searchScreen) searchScreen.classList.remove('hidden');
            if (loading) loading.classList.add('hidden');
            await ensurePrayersLoaded();
            if (qInput) qInput.value = prayerSearchDraft;
            hydratePrayerSearchResults();
            return;
        }

        if (listScreen) listScreen.classList.remove('hidden');
        if (searchScreen) searchScreen.classList.add('hidden');

        if (browseRoot) browseRoot.classList.remove('hidden');

        if (loading) loading.classList.remove('hidden');

        await ensurePrayersLoaded();

        if (loading) loading.classList.add('hidden');
        if (searchEntry) {
            if (prayersLoadError || (prayersCache || []).length === 0) {
                searchEntry.classList.add('hidden');
            } else {
                searchEntry.classList.remove('hidden');
            }
        }
        renderPrayerBrowse();
    }

    function prayersShellHtml() {
        return `
    <div class="max-w-[480px] mx-auto px-2 pb-8 pt-2 flex flex-col gap-3">
        <div id="prayer-list-screen" class="flex flex-col gap-2">
            <div id="prayers-loading" class="hidden text-sm text-app-textTer px-1 py-2"><i class="fas fa-circle-notch fa-spin"></i> Загрузка…</div>
            <button type="button" data-action="prayer-open-search" id="prayer-search-entry" class="hidden w-full ${APP_SEARCH_BAR_CLASS} hover:bg-white/[0.04] cursor-pointer text-left transition-colors border-solid">
                <i class="fas fa-search text-app-textTer text-sm shrink-0" aria-hidden="true"></i>
                <span class="flex-1 min-w-0 text-sm text-app-textTer truncate text-left">Пошук у малітоўніку</span>
            </button>
            <div id="prayer-browse-root" class="space-y-2 min-h-[200px]"></div>
        </div>
        <div id="prayer-search-screen" class="hidden space-y-3 pb-4">
            <div class="${APP_SEARCH_BAR_CLASS}">
                <i class="fas fa-search text-app-textTer text-sm shrink-0" aria-hidden="true"></i>
                <input type="search" id="prayer-search-query" autocomplete="off" placeholder="Назва, тэкст або катэгорыя…"
                    class="${APP_SEARCH_INPUT_CLASS}" />
            </div>
            <p id="prayer-search-status" class="text-sm text-app-textSec leading-snug px-0.5"></p>
            <div id="prayer-search-results" class="space-y-0"></div>
        </div>
        <div id="prayer-detail-root" class="hidden min-h-[200px]"></div>
    </div>`;
    }

    function ordoMissaeShellHtml() {
        return `
        <div class="w-full max-w-[480px] mx-auto px-2 pb-8 min-h-[min(70dvh,640px)] flex flex-col gap-3">
            <div id="ordo-missae-root" class="min-h-[min(70dvh,640px)] flex flex-col">
                <div class="flex flex-1 flex-col items-center justify-center py-16 gap-3 text-app-textTer">
                    <i class="fas fa-circle-notch fa-spin text-3xl text-app-textSec" aria-hidden="true"></i>
                    <span class="text-sm">Загрузка…</span>
                </div>
            </div>
        </div>`;
    }

    function ordoMissaeContentFp(raw) {
        const s = String(raw || '').trim();
        let h = 2166136261 >>> 0;
        for (let i = 0; i < s.length; i++) {
            h ^= s.charCodeAt(i);
            h = Math.imul(h, 16777619) >>> 0;
        }
        return h.toString(16) + '_' + s.length;
    }

    const ORDO_MISSAE_BUILT_IN_SECTION_KEYS = ['intro', 'liturgy_word', 'eucharist', 'eucharist_prayer2', 'communion', 'closing'];

    function ordoMissaeSectionKeys(hostEl) {
        const keys = [];
        const seen = new Set();
        if (hostEl) {
            hostEl.querySelectorAll('details.ordo-missae-section[data-ordo-section]').forEach((det) => {
                const k = String(det.getAttribute('data-ordo-section') || '').trim();
                if (!k || seen.has(k)) return;
                seen.add(k);
                keys.push(k);
            });
        }
        return keys.length > 0 ? keys : ORDO_MISSAE_BUILT_IN_SECTION_KEYS;
    }

    function ordoMissaeFoldStorage(hostEl, rawOriginal) {
        const raw = String(rawOriginal || '');
        const fp = ordoMissaeContentFp(raw);
        return {
            fp,
            keys: ordoMissaeSectionKeys(hostEl),
            lsKey: (k) => 'totus.ordo.fold.' + fp + '.' + k,
            prefsFpKey: 'totus.ordo.fold.content_fp',
        };
    }

    function ordoMissaeEnsureFoldFingerprint(store) {
        try {
            const old = localStorage.getItem(store.prefsFpKey);
            if (old && old !== store.fp) {
                const prefix = 'totus.ordo.fold.' + old + '.';
                for (let i = localStorage.length - 1; i >= 0; i--) {
                    const k = localStorage.key(i);
                    if (k && k.startsWith(prefix)) localStorage.removeItem(k);
                }
            }
            localStorage.setItem(store.prefsFpKey, store.fp);
        } catch (e) {
            /* ignore */
        }
    }

    function ordoMissaeSavedOpen(store, sectionKey) {
        let open = false;
        try {
            const v = localStorage.getItem(store.lsKey(sectionKey));
            if (v === '1') open = true;
            else if (v === '0') open = false;
        } catch (e) {
            /* ignore */
        }
        return open;
    }

    function ordoMissaeRestoreFoldState(hostEl, rawOriginal) {
        if (!hostEl) return;
        const store = ordoMissaeFoldStorage(hostEl, rawOriginal);
        ordoMissaeEnsureFoldFingerprint(store);
        hostEl.dataset.ordoFoldApplying = '1';
        hostEl.querySelectorAll('details.ordo-missae-section').forEach((det) => {
            det.open = false;
            det.classList.remove('ordo-search-match');
        });
        hostEl.querySelectorAll('details.ordo-missae-section[data-ordo-section]').forEach((det) => {
            const k = det.getAttribute('data-ordo-section');
            if (!k) return;
            det.open = ordoMissaeSavedOpen(store, k);
        });
        queueMicrotask(() => {
            if (hostEl && hostEl.dataset) delete hostEl.dataset.ordoFoldApplying;
        });
    }

    /** Запамінае разгорнутыя/згорнутыя часткі Ordo Missae (localStorage, прывязка да зместу). */
    function ordoMissaeApplyFoldMemory(hostEl, rawOriginal) {
        const raw = String(rawOriginal || '');
        if (!hostEl || raw.indexOf('data-ordo-section=') < 0) return;
        hostEl.__totusOrdoRaw = raw;
        const store = ordoMissaeFoldStorage(hostEl, raw);
        ordoMissaeRestoreFoldState(hostEl, raw);
        hostEl.querySelectorAll('details.ordo-missae-section[data-ordo-section]').forEach((det) => {
            if (det.dataset.ordoFoldBound === '1') return;
            det.dataset.ordoFoldBound = '1';
            det.addEventListener(
                'toggle',
                () => {
                    if (hostEl.dataset.ordoFoldApplying === '1' || hostEl.classList.contains('ordo-search-active')) return;
                    const k = det.getAttribute('data-ordo-section');
                    if (!k) return;
                    try {
                        localStorage.setItem(store.lsKey(k), det.open ? '1' : '0');
                    } catch (e3) {
                        /* ignore */
                    }
                },
                { passive: true },
            );
        });
    }

    function ordoMissaeNormalizeSearchText(value) {
        return String(value || '')
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[’‘ʼ`´]/g, "'")
            .toLowerCase()
            .replace(/[^\p{L}\p{N}'-]+/gu, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function ordoMissaeSearchTerms(query) {
        const terms = [];
        const seen = new Set();
        const re = /"([^"]+)"|'([^']+)'|«([^»]+)»|“([^”]+)”|(\S+)/g;
        let m;
        while ((m = re.exec(String(query || ''))) !== null) {
            const raw = m[1] || m[2] || m[3] || m[4] || m[5] || '';
            const n = ordoMissaeNormalizeSearchText(raw);
            if (!n || seen.has(n)) continue;
            seen.add(n);
            terms.push(n);
        }
        const whole = ordoMissaeNormalizeSearchText(String(query || '').replace(/["'«»“”]/g, ' '));
        return { terms, whole };
    }

    function ordoMissaeTextMatchesSmartQuery(normalizedText, parsedQuery) {
        if (!normalizedText || !parsedQuery || parsedQuery.terms.length === 0) return false;
        if (parsedQuery.whole && normalizedText.includes(parsedQuery.whole)) return true;
        return parsedQuery.terms.every((term) => normalizedText.includes(term));
    }

    function syncOrdoSearchNav(query, count) {
        const nav = document.getElementById('ordo-missae-search-nav');
        const prev = document.getElementById('ordo-missae-search-prev');
        const next = document.getElementById('ordo-missae-search-next');
        const hasQuery = String(query || '').trim().length > 0;
        if (nav) nav.style.visibility = hasQuery ? 'visible' : 'hidden';
        const canMove = count > 1;
        if (prev) prev.disabled = !canMove;
        if (next) next.disabled = !canMove;
    }

    function syncOrdoSearchNavVisibilityImmediate(query) {
        const nav = document.getElementById('ordo-missae-search-nav');
        if (nav) nav.style.visibility = String(query || '').trim().length > 0 ? 'visible' : 'hidden';
    }

    function ordoMissaeClearHighlights(host) {
        if (!host) return;
        host.querySelectorAll('mark.ordo-search-highlight').forEach((mark) => {
            const parent = mark.parentNode;
            if (!parent) return;
            parent.replaceChild(document.createTextNode(mark.textContent || ''), mark);
            parent.normalize();
        });
        host.querySelectorAll('.ordo-search-match').forEach((el) => el.classList.remove('ordo-search-match'));
        ordoMissaeSearchResults = [];
        ordoMissaeSearchIndex = -1;
    }

    function ordoMissaeSelectResult(index, shouldScroll) {
        if (!Array.isArray(ordoMissaeSearchResults) || ordoMissaeSearchResults.length === 0) return;
        if (index < 0 || index >= ordoMissaeSearchResults.length) return;
        ordoMissaeSearchResults.forEach((el) => el.classList.remove('ordo-search-highlight-active'));
        const current = ordoMissaeSearchResults[index];
        if (!current) return;
        current.classList.add('ordo-search-highlight-active');
        ordoMissaeSearchIndex = index;
        if (shouldScroll !== false) {
            current.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
        }
    }

    function moveOrdoSearchResult(delta) {
        if (!Array.isArray(ordoMissaeSearchResults) || ordoMissaeSearchResults.length === 0) return;
        let next = ordoMissaeSearchIndex;
        if (!Number.isFinite(next) || next < 0) next = 0;
        else next = (next + delta + ordoMissaeSearchResults.length) % ordoMissaeSearchResults.length;
        ordoMissaeSelectResult(next, true);
    }

    function hydrateOrdoMissaeSearchState() {
        const root = document.getElementById('ordo-missae-root');
        const host = root ? root.querySelector('.prayer-detail-html') : null;
        const qInput = document.getElementById('ordo-missae-search-query');
        const q = qInput ? qInput.value : ordoMissaeSearchQuery;
        ordoMissaeSearchQuery = q;
        const trimmed = String(q || '').trim();
        if (!host) {
            syncOrdoSearchNav(trimmed, 0);
            return;
        }
        const raw = String(host.__totusOrdoRaw || '');
        ordoMissaeClearHighlights(host);
        if (!trimmed) {
            host.classList.remove('ordo-search-active');
            ordoMissaeRestoreFoldState(host, raw);
            syncOrdoSearchNav(trimmed, 0);
            return;
        }
        const needle = String(trimmed).toLocaleLowerCase();
        if (!needle) {
            syncOrdoSearchNav(trimmed, 0);
            return;
        }
        host.classList.add('ordo-search-active');
        host.dataset.ordoFoldApplying = '1';
        host.querySelectorAll('details.ordo-missae-section[data-ordo-section]').forEach((det) => {
            det.open = false;
        });
        const walker = document.createTreeWalker(host, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                if (!node || !node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                let el = node.parentElement;
                while (el) {
                    const tag = String(el.tagName || '').toLowerCase();
                    if (
                        tag === 'script' ||
                        tag === 'style' ||
                        tag === 'noscript' ||
                        tag === 'textarea' ||
                        tag === 'input' ||
                        tag === 'select' ||
                        tag === 'option'
                    ) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    if (tag === 'mark' && el.classList.contains('ordo-search-highlight')) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    el = el.parentElement;
                }
                return NodeFilter.FILTER_ACCEPT;
            },
        });
        const textNodes = [];
        let node;
        while ((node = walker.nextNode())) textNodes.push(node);
        textNodes.forEach((textNode) => {
            const text = String(textNode.nodeValue || '');
            const hay = text.toLocaleLowerCase();
            let pos = 0;
            let hit = hay.indexOf(needle, pos);
            if (hit < 0) return;
            const frag = document.createDocumentFragment();
            while (hit >= 0) {
                if (hit > pos) frag.appendChild(document.createTextNode(text.slice(pos, hit)));
                const mark = document.createElement('mark');
                mark.className = 'ordo-search-highlight';
                mark.textContent = text.slice(hit, hit + trimmed.length);
                frag.appendChild(mark);
                pos = hit + trimmed.length;
                hit = hay.indexOf(needle, pos);
            }
            if (pos < text.length) frag.appendChild(document.createTextNode(text.slice(pos)));
            textNode.parentNode.replaceChild(frag, textNode);
        });
        const marks = Array.from(host.querySelectorAll('mark.ordo-search-highlight'));
        marks.forEach((mark) => {
            let el = mark.parentElement;
            while (el && el !== host) {
                if (el.tagName && String(el.tagName).toLowerCase() === 'details' && el.classList.contains('ordo-missae-section')) {
                    el.open = true;
                    el.classList.add('ordo-search-match');
                }
                el = el.parentElement;
            }
        });
        ordoMissaeSearchResults = marks;
        ordoMissaeSearchIndex = marks.length > 0 ? 0 : -1;
        if (marks.length > 0) ordoMissaeSelectResult(0, true);
        queueMicrotask(() => {
            if (host && host.dataset) delete host.dataset.ordoFoldApplying;
        });
        syncOrdoSearchNav(trimmed, marks.length);
    }

    async function hydrateOrdoMissae() {
        const root = document.getElementById('ordo-missae-root');
        if (!root || currentView !== 'ordo-missae') return;
        const shellMin = 'min-h-[min(70dvh,640px)]';
        if (!isApiConfigured()) {
            root.innerHTML = `<div class="${shellMin}">${configBannerHtml()}</div>`;
            return;
        }
        const res = await apiFetch('ordo_missae.php', { _: Date.now() });
        if (!res.ok || res.data.error) {
            const { apiBaseUrl } = getApiConfig();
            const isNet = res.status === 0 || res.data.error === 'network_error';
            const hint = isNet ? apiNetworkFailureHint(apiBaseUrl) : '';
            const msg = res.data.message || res.data.error || 'Памылка загрузкі';
            root.innerHTML = `<div class="${shellMin} bg-red-950/40 border border-red-500/30 text-app-error rounded-md text-sm p-4">${escapeHtml(msg)}${hint}</div>`;
            return;
        }
        let raw = String(res.data.html ?? '').trim();
        raw = stripOrdoDetailsOpenFromHtml(raw);
        if (!raw) {
            root.innerHTML = `<div class="rounded-md border border-app-stroke bg-app-elevated overflow-hidden ${shellMin}"><div class="totus-read-18 p-4 text-app-text totus-reading-detail whitespace-pre-wrap">${escapeHtml(
                'Тэкст пакуль не дададзены ў панэлі кіравання.'
            )}</div></div>`;
            return;
        }
        const useHtml = stringLooksLikeHtmlFragment(raw);
        const bodyInner = useHtml
            ? sanitizePrayerHtmlForWebDisplay(
                  stripOrdoLightTextColorsFromHtml(stripOrdoInlineFontSizesFromHtml(raw)),
              )
            : escapeHtml(normalizePrayerTextForDisplay(raw));
        const bodyClass = useHtml
            ? 'totus-read-18 p-4 text-app-text totus-reading-detail prayer-detail-html'
            : 'totus-read-18 p-4 text-app-text totus-reading-detail whitespace-pre-wrap';
        root.innerHTML = `<div class="rounded-md border border-app-stroke bg-app-elevated overflow-hidden ${shellMin}"><div class="${bodyClass}">${bodyInner}</div></div>`;
        if (useHtml) {
            const host = root.querySelector('.prayer-detail-html');
            if (host) ordoMissaeApplyFoldMemory(host, raw);
        }
        const qInput = document.getElementById('ordo-missae-search-query');
        if (qInput) qInput.value = ordoMissaeSearchQuery;
        hydrateOrdoMissaeSearchState();
    }

    function scriptureTrPanelClose() {
        const panel = document.getElementById('scripture-tr-panel');
        const wrap = document.querySelector('.scripture-tr-custom');
        const trig = document.getElementById('scripture-tr-trigger');
        if (panel) panel.classList.add('hidden');
        if (wrap) wrap.classList.remove('scripture-tr-open');
        if (trig) trig.setAttribute('aria-expanded', 'false');
    }

    function settingsFontPanelClose() {
        const panel = document.getElementById('settings-font-panel');
        const wrap = document.querySelector('.settings-font-custom');
        const trig = document.getElementById('settings-font-trigger');
        if (panel) panel.classList.add('hidden');
        if (wrap) wrap.classList.remove('settings-font-open');
        if (trig) trig.setAttribute('aria-expanded', 'false');
    }

    function settingsThemePanelClose() {
        const panel = document.getElementById('settings-theme-panel');
        const wrap = document.querySelector('.settings-theme-custom');
        const trig = document.getElementById('settings-theme-trigger');
        if (panel) panel.classList.add('hidden');
        if (wrap) wrap.classList.remove('settings-theme-open');
        if (trig) trig.setAttribute('aria-expanded', 'false');
    }

    function homeThemePanelClose() {
        const panel = document.getElementById('home-theme-panel');
        const wrap = document.querySelector('.home-theme-custom');
        const trig = document.getElementById('home-theme-trigger');
        if (panel) panel.classList.add('hidden');
        if (wrap) wrap.classList.remove('home-theme-open');
        if (trig) trig.setAttribute('aria-expanded', 'false');
    }

    function bindDelegatedEvents() {
        app.addEventListener('click', (e) => {
            const trPanelEarly = document.getElementById('scripture-tr-panel');
            if (
                trPanelEarly &&
                !trPanelEarly.classList.contains('hidden') &&
                !e.target.closest('.scripture-tr-custom')
            ) {
                scriptureTrPanelClose();
            }

            const fontPanelEarly = document.getElementById('settings-font-panel');
            if (
                fontPanelEarly &&
                !fontPanelEarly.classList.contains('hidden') &&
                !e.target.closest('.settings-font-custom')
            ) {
                settingsFontPanelClose();
            }
            const themePanelEarly = document.getElementById('settings-theme-panel');
            if (
                themePanelEarly &&
                !themePanelEarly.classList.contains('hidden') &&
                !e.target.closest('.settings-theme-custom')
            ) {
                settingsThemePanelClose();
            }
            const homeThemePanelEarly = document.getElementById('home-theme-panel');
            if (
                homeThemePanelEarly &&
                !homeThemePanelEarly.classList.contains('hidden') &&
                !e.target.closest('.home-theme-custom')
            ) {
                homeThemePanelClose();
            }

            const trTrig = e.target.closest('#scripture-tr-trigger');
            if (trTrig) {
                e.preventDefault();
                const panel = document.getElementById('scripture-tr-panel');
                const wrap = trTrig.closest('.scripture-tr-custom');
                if (panel && wrap) {
                    if (panel.classList.contains('hidden')) {
                        panel.classList.remove('hidden');
                        wrap.classList.add('scripture-tr-open');
                        trTrig.setAttribute('aria-expanded', 'true');
                    } else {
                        scriptureTrPanelClose();
                    }
                }
                return;
            }

            const fontTrig = e.target.closest('#settings-font-trigger');
            if (fontTrig) {
                e.preventDefault();
                const panel = document.getElementById('settings-font-panel');
                const wrap = fontTrig.closest('.settings-font-custom');
                if (panel && wrap) {
                    if (panel.classList.contains('hidden')) {
                        panel.classList.remove('hidden');
                        wrap.classList.add('settings-font-open');
                        fontTrig.setAttribute('aria-expanded', 'true');
                    } else {
                        settingsFontPanelClose();
                    }
                }
                return;
            }

            const homeThemeTrig = e.target.closest('#home-theme-trigger');
            if (homeThemeTrig) {
                e.preventDefault();
                const panel = document.getElementById('home-theme-panel');
                const wrap = homeThemeTrig.closest('.home-theme-custom');
                if (panel && wrap) {
                    if (panel.classList.contains('hidden')) {
                        panel.classList.remove('hidden');
                        wrap.classList.add('home-theme-open');
                        homeThemeTrig.setAttribute('aria-expanded', 'true');
                    } else {
                        homeThemePanelClose();
                    }
                }
                return;
            }

            const trOpt = e.target.closest('[data-scripture-tr-option]');
            if (trOpt && trOpt.dataset.scriptureTrOption !== undefined) {
                const id = String(trOpt.dataset.scriptureTrOption);
                const sel = document.getElementById('scripture-tr-select');
                if (sel) {
                    sel.value = id;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                }
                const trigLab = document.getElementById('scripture-tr-trigger-label');
                const titleEl = trOpt.querySelector('.scripture-tr-option-title');
                if (trigLab && titleEl) trigLab.textContent = titleEl.textContent;
                document.querySelectorAll('[data-scripture-tr-option]').forEach((btn) => {
                    const oid = String(btn.dataset.scriptureTrOption);
                    const picked = oid === id;
                    btn.setAttribute('aria-selected', picked ? 'true' : 'false');
                    btn.className =
                        'scripture-tr-option-btn w-full text-left px-4 py-3 text-sm border-0 cursor-pointer transition-colors border-solid ' +
                        (picked
                            ? 'bg-app-surface text-app-text font-medium'
                            : 'bg-transparent text-app-text hover:bg-white/[0.06]');
                });
                scriptureTrPanelClose();
                return;
            }

            const fontOpt = e.target.closest('[data-settings-font-option]');
            if (fontOpt && fontOpt.dataset.settingsFontOption !== undefined) {
                const id = String(fontOpt.dataset.settingsFontOption);
                if (id === 'sans' || id === 'serif' || id === 'mono') {
                    const sel = document.getElementById('settings-font-select');
                    if (sel) {
                        sel.value = id;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    const trigLab = document.getElementById('settings-font-trigger-label');
                    const titleEl = fontOpt.querySelector('.settings-font-option-title');
                    if (trigLab && titleEl) trigLab.textContent = titleEl.textContent;
                    document.querySelectorAll('[data-settings-font-option]').forEach((btn) => {
                        const oid = String(btn.dataset.settingsFontOption);
                        const picked = oid === id;
                        btn.setAttribute('aria-selected', picked ? 'true' : 'false');
                        btn.className =
                            'settings-font-option-btn w-full text-left px-4 py-3 text-sm border-0 cursor-pointer transition-colors border-solid ' +
                            (picked
                                ? 'bg-app-surface text-app-text font-medium'
                                : 'bg-transparent text-app-text hover:bg-white/[0.06]');
                    });
                    settingsFontPanelClose();
                }
                return;
            }

            const themeSwitch = e.target.closest('[data-settings-theme-switch]');
            if (themeSwitch && themeSwitch.dataset.settingsThemeSwitch !== undefined) {
                const id = String(themeSwitch.dataset.settingsThemeSwitch);
                if (id === 'current' || id === 'beige') {
                    const sel = document.getElementById('settings-theme-select');
                    if (sel) {
                        sel.value = id;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    syncSettingsThemeUi();
                }
                return;
            }

            const homeThemeOption = e.target.closest('[data-home-theme-option]');
            if (homeThemeOption && homeThemeOption.dataset.homeThemeOption !== undefined) {
                const id = String(homeThemeOption.dataset.homeThemeOption);
                if (id === 'current' || id === 'beige') {
                    writeAppTheme(id);
                    applyAppTheme();
                    homeThemePanelClose();
                    if (currentView === 'settings') syncSettingsThemeUi();
                    renderApp();
                }
                return;
            }

            /* Раней за [data-action]: каб клік па Пісанні не трапляў у «Пошук малітоўніка» пры суцельным bar-класе і г.д. */
            const scr = e.target.closest('[data-scripture-action]');
            if (scr) {
                const act = scr.getAttribute('data-scripture-action');
                if (act === 'apply-tr') {
                    const sel = document.getElementById('scripture-tr-select');
                    if (sel && sel.value) {
                        const next = String(sel.value);
                        const prev = scriptureSelectedId != null ? String(scriptureSelectedId) : null;
                        scriptureSelectedId = next;
                        try {
                            localStorage.setItem(SCRIPTURE_TR_KEY, scriptureSelectedId);
                        } catch {
                            /* ignore */
                        }
                        if (prev !== next) {
                            scriptureData = null;
                            scBookIdx = null;
                            scChapterNum = null;
                        }
                        scTranslationSettingsOpen = false;
                        hydrateScriptureView();
                        if (scriptureTrOverlayFromGear && totusHistoryLen > 0) {
                            scriptureTrOverlayFromGear = false;
                            totusHistorySyncSkipPop = true;
                            history.back();
                        }
                    }
                    return;
                }
                if (act === 'toggle-testament') {
                    const key = String(scr.dataset.testamentSection || '');
                    if (key !== 'nt' && key !== 'ot') return;
                    const panel = document.getElementById(`scripture-testament-${key}-books`);
                    if (!panel) return;
                    const wasExpanded = scr.getAttribute('aria-expanded') === 'true';
                    const next = !wasExpanded;
                    scr.setAttribute('aria-expanded', next ? 'true' : 'false');
                    writeScriptureTestamentExpanded(key, next);
                    panel.classList.toggle('hidden', !next);
                    const icon = scr.querySelector('.scripture-testament-chevron');
                    if (icon) {
                        icon.classList.remove('fa-chevron-up', 'fa-chevron-down');
                        icon.classList.add(next ? 'fa-chevron-up' : 'fa-chevron-down');
                    }
                    return;
                }
                if (act === 'open-book') {
                    scBookIdx = Number(scr.dataset.bookIdx);
                    scChapterNum = null;
                    scFocusVerse = null;
                    totusHistoryMarkForward();
                    hydrateScriptureView();
                    return;
                }
                if (act === 'open-chapter') {
                    scChapterNum = Number(scr.dataset.chapterNum);
                    scFocusVerse = null;
                    totusHistoryMarkForward();
                    hydrateScriptureView();
                    return;
                }
                if (act === 'verse-fav-toggle') {
                    const vn = Number(scr.dataset.vVerse);
                    const books = (scriptureData && scriptureData.books) || [];
                    if (!scriptureSelectedId || scBookIdx == null || scChapterNum == null) return;
                    const bk = books[scBookIdx];
                    if (!bk) return;
                    const chObj = (bk.chapters || []).find((c) => Number(c.chapter) === Number(scChapterNum));
                    const vs = chObj && (chObj.verses || []).find((x) => Number(x.verse) === vn);
                    if (!vs) return;
                    const trTitle =
                        (scriptureTranslationsList || []).find((x) => String(x.id) === String(scriptureSelectedId))
                            ?.title || '';
                    const favObj = {
                        translationId: String(scriptureSelectedId),
                        translationTitle: trTitle,
                        bookId: bk.book_id,
                        bookTitle: bk.book_name,
                        chapter: Number(scChapterNum),
                        verse: vn,
                        text: vs.text,
                    };
                    const added = toggleScriptureFavorite(favObj);
                    totusToast(
                        added ? 'Верш дададзены ў выбранае' : 'Верш выдалены з выбранага'
                    );
                    const icon = scr.querySelector('i');
                    if (icon) {
                        icon.className = added
                            ? 'fas fa-bookmark text-base text-amber-400'
                            : 'far fa-bookmark text-base';
                    }
                    return;
                }
                if (act === 'verse-compare-toggle') {
                    const vn = Number(scr.dataset.vVerse);
                    const books = (scriptureData && scriptureData.books) || [];
                    if (scBookIdx == null || scChapterNum == null) return;
                    const bk = books[scBookIdx];
                    if (!bk) return;
                    const cmpObj = {
                        bookId: bk.book_id,
                        bookTitle: bk.book_name,
                        chapter: Number(scChapterNum),
                        verse: vn,
                    };
                    const added = toggleCompareVerse(cmpObj);
                    totusToast(
                        added ? 'Верш дададзены да параўнання' : 'Верш прыбраны з параўнання'
                    );
                    scr.classList.toggle('text-app-text', added);
                    scr.classList.toggle('text-app-textTer', !added);
                    scr.classList.toggle('opacity-70', !added);
                    scr.innerHTML = scriptureCompareIconSvg(added, 'w-[1.725rem] h-[1.725rem] block mx-auto');
                    return;
                }
                if (act === 'open-favorite-verse') {
                    const idx = Number(scr.dataset.favIndex);
                    if (!Number.isFinite(idx)) return;
                    void (async () => {
                        const items = readScriptureFavorites();
                        const item = items[idx];
                        if (!item) return;
                        scriptureSelectedId = item.translationId;
                        try {
                            localStorage.setItem(SCRIPTURE_TR_KEY, scriptureSelectedId);
                        } catch {
                            /* ignore */
                        }
                        scriptureData = null;
                        await ensureScriptureDataLoaded();
                        const books = (scriptureData && scriptureData.books) || [];
                        const bix = books.findIndex((b) => Number(b.book_id) === Number(item.bookId));
                        if (bix < 0) {
                            totusToast('Кніга не знойдзена ў абраным перакладзе');
                            return;
                        }
                        scBookIdx = bix;
                        scChapterNum = Number(item.chapter);
                        scFocusVerse = Number(item.verse);
                        scPanel = null;
                        totusHistoryMarkForward();
                        await hydrateScriptureView();
                        refreshToolbarRightActions();
                        syncScriptureToolbarTitle();
                    })();
                    return;
                }
                if (act === 'compare-remove-ref') {
                    const ref = {
                        bookId: Number(scr.dataset.bookId),
                        bookTitle: '',
                        chapter: Number(scr.dataset.chapter),
                        verse: Number(scr.dataset.verse),
                    };
                    const next = readCompareVerses().filter((x) => compareVerseKey(x) !== compareVerseKey(ref));
                    writeCompareVerses(next);
                    if (currentView === 'scripture' && scPanel === 'compare') hydrateScriptureView();
                    return;
                }
                if (act === 'compare-open-chapter') {
                    const bid = Number(scr.dataset.bookId);
                    const ch = Number(scr.dataset.chapter);
                    void (async () => {
                        await ensureScriptureDataLoaded();
                        const books = (scriptureData && scriptureData.books) || [];
                        const bix = books.findIndex((b) => Number(b.book_id) === bid);
                        if (bix < 0) {
                            totusToast('Раздзел не знойдзены для бягучага перакладу');
                            return;
                        }
                        scBookIdx = bix;
                        scChapterNum = ch;
                        scFocusVerse = null;
                        scPanel = null;
                        totusHistoryMarkForward();
                        await hydrateScriptureView();
                        refreshToolbarRightActions();
                        syncScriptureToolbarTitle();
                    })();
                    return;
                }
                if (act === 'open-word-search') {
                    if (scriptureWordSearchDebounceTimer) {
                        clearTimeout(scriptureWordSearchDebounceTimer);
                        scriptureWordSearchDebounceTimer = null;
                    }
                    scPanel = 'word_search';
                    totusHistoryMarkForward();
                    hydrateScriptureView();
                    refreshToolbarRightActions();
                    syncScriptureToolbarTitle();
                    return;
                }
                if (act === 'word-search-hit') {
                    const bid = Number(scr.dataset.bookId);
                    const ch = Number(scr.dataset.chapter);
                    if (scriptureWordSearchDebounceTimer) {
                        clearTimeout(scriptureWordSearchDebounceTimer);
                        scriptureWordSearchDebounceTimer = null;
                    }
                    void (async () => {
                        await ensureScriptureDataLoaded();
                        const books = (scriptureData && scriptureData.books) || [];
                        const bix = books.findIndex((b) => Number(b.book_id) === bid);
                        if (bix < 0) {
                            totusToast('Раздзел не знойдзены для бягучага перакладу');
                            return;
                        }
                        scBookIdx = bix;
                        scChapterNum = ch;
                        scFocusVerse = null;
                        scPanel = null;
                        totusHistoryMarkForward();
                        await hydrateScriptureView();
                        refreshToolbarRightActions();
                        syncScriptureToolbarTitle();
                    })();
                    return;
                }
            }

            const action = e.target.closest('[data-action]');
            if (action) {
                const a = action.dataset.action;
                if (a === 'open-settings') {
                    currentView = 'settings';
                    totusHistoryMarkForward();
                    renderApp();
                    return;
                }
                if (a === 'open-about-app') {
                    currentView = 'about';
                    totusHistoryMarkForward();
                    renderApp();
                    return;
                }
                if (a === 'toggle-home-theme') {
                    const next = readAppTheme() === 'beige' ? 'current' : 'beige';
                    writeAppTheme(next);
                    applyAppTheme();
                    renderApp();
                    return;
                }
                if (a === 'open-calendar-settings') {
                    if (currentView === 'calendar' || currentView === 'day') {
                        calendarSettingsReturnView = currentView;
                        currentView = 'calendar-settings';
                        totusHistoryMarkForward();
                        renderApp();
                    }
                    return;
                }
                if (a === 'open-solemnities-settings') {
                    if (currentView === 'solemnities') {
                        solemnitiesSettingsOpen = true;
                        totusHistoryMarkForward();
                        renderApp();
                    }
                    return;
                }
                if (a === 'toggle-solemnities-section') {
                    if (currentView !== 'solemnities' || solemnitiesSettingsOpen) return;
                    const title = String(action.dataset.sectionTitle || '').trim();
                    if (!title) return;
                    if (solemnitiesCollapsedSections.has(title)) {
                        solemnitiesCollapsedSections.delete(title);
                    } else {
                        solemnitiesCollapsedSections.add(title);
                    }
                    writeSolemnitiesCollapsedSections();
                    refreshSolemnitiesContent();
                    return;
                }
                if (a === 'open-scripture-translation-settings') {
                    if (currentView === 'scripture') {
                        scPanel = null;
                        if (scriptureWordSearchDebounceTimer) {
                            clearTimeout(scriptureWordSearchDebounceTimer);
                            scriptureWordSearchDebounceTimer = null;
                        }
                        totusHistoryMarkForward();
                        scriptureTrOverlayFromGear = true;
                        scTranslationSettingsOpen = true;
                        hydrateScriptureView();
                        refreshToolbarRightActions();
                        syncScriptureToolbarTitle();
                    }
                    return;
                }
                if (a === 'scripture-open-favorites') {
                    if (currentView === 'scripture') {
                        if (scriptureWordSearchDebounceTimer) {
                            clearTimeout(scriptureWordSearchDebounceTimer);
                            scriptureWordSearchDebounceTimer = null;
                        }
                        totusHistoryMarkForward();
                        scPanel = 'favorites';
                        hydrateScriptureView();
                        refreshToolbarRightActions();
                        syncScriptureToolbarTitle();
                    }
                    return;
                }
                if (a === 'scripture-open-compare') {
                    if (currentView === 'scripture') {
                        if (scriptureWordSearchDebounceTimer) {
                            clearTimeout(scriptureWordSearchDebounceTimer);
                            scriptureWordSearchDebounceTimer = null;
                        }
                        totusHistoryMarkForward();
                        scPanel = 'compare';
                        hydrateScriptureView();
                        refreshToolbarRightActions();
                        syncScriptureToolbarTitle();
                    }
                    return;
                }
                if (a === 'scripture-compare-hide-hint') {
                    try {
                        localStorage.setItem(SCRIPTURE_COMPARE_HINT_KEY, '1');
                    } catch {
                        /* ignore */
                    }
                    if (currentView === 'scripture' && scPanel === 'compare') hydrateScriptureView();
                    return;
                }
                if (a === 'scripture-compare-toggle-tr-section') {
                    const cur = localStorage.getItem(SCRIPTURE_COMPARE_EXPAND_KEY) === '1';
                    try {
                        localStorage.setItem(SCRIPTURE_COMPARE_EXPAND_KEY, cur ? '0' : '1');
                    } catch {
                        /* ignore */
                    }
                    if (currentView === 'scripture' && scPanel === 'compare') hydrateScriptureView();
                    return;
                }
                if (a === 'ordo-search-prev') {
                    if (currentView !== 'ordo-missae') return;
                    moveOrdoSearchResult(-1);
                    return;
                }
                if (a === 'ordo-search-next') {
                    if (currentView !== 'ordo-missae') return;
                    moveOrdoSearchResult(1);
                    return;
                }
                if (a === 'toolbar-prayer-bookmarks') {
                    prayerBeforeDetail = null;
                    prayerView = 'list';
                    prayerNav = { screen: 'bookmarks_all' };
                    totusHistoryMarkForward();
                    renderApp();
                    return;
                }
                if (a === 'toolbar-prayer-search') {
                    if (currentView !== 'prayers') return;
                    prayerView = 'search';
                    prayerSearchDraft = '';
                    totusHistoryMarkForward();
                    renderApp();
                    queueMicrotask(() => {
                        const el = document.getElementById('prayer-search-query');
                        if (el) el.value = '';
                        el?.focus();
                    });
                    return;
                }
                if (a === 'prayer-open-search') {
                    if (currentView !== 'prayers') return;
                    prayerView = 'search';
                    prayerSearchDraft = '';
                    totusHistoryMarkForward();
                    renderApp();
                    queueMicrotask(() => {
                        const el = document.getElementById('prayer-search-query');
                        if (el) el.value = '';
                        el?.focus();
                    });
                    return;
                }
                if (a === 'toolbar-songbook-bookmarks') {
                    songbookDetailId = null;
                    songbookStateBeforeDetail = null;
                    songbookBookmarksOnly = !songbookBookmarksOnly;
                    renderApp();
                    return;
                }
                if (a === 'toolbar-songbook-search') {
                    if (!isSongbookLikeView()) return;
                    songbookDetailId = null;
                    songbookStateBeforeDetail = null;
                    songbookSearchQuery = '';
                    songbookView = 'search';
                    totusHistoryMarkForward();
                    renderApp();
                    queueMicrotask(() => {
                        const el = document.getElementById('songbook-search-query');
                        if (el) el.value = '';
                        el?.focus();
                    });
                    return;
                }
                if (a === 'songbook-open-search') {
                    if (!isSongbookLikeView()) return;
                    songbookDetailId = null;
                    songbookStateBeforeDetail = null;
                    songbookSearchQuery = '';
                    songbookView = 'search';
                    totusHistoryMarkForward();
                    renderApp();
                    queueMicrotask(() => {
                        const el = document.getElementById('songbook-search-query');
                        if (el) el.value = '';
                        el?.focus();
                    });
                    return;
                }
                if (a === 'songbook-toggle-category') {
                    if (!isSongbookLikeView()) return;
                    const idx = action.dataset.songCategoryIdx;
                    const skRaw = action.dataset.songCategoryStorageKey;
                    if (idx == null || skRaw == null) return;
                    let sk;
                    try {
                        sk = decodeURIComponent(String(skRaw));
                    } catch {
                        return;
                    }
                    const idBase = `songbook-cat-${idx}`;
                    const head = document.getElementById(`${idBase}-head`);
                    const panel = document.getElementById(`${idBase}-songs`);
                    if (!head || !panel) return;
                    const wasExpanded = head.getAttribute('aria-expanded') === 'true';
                    const next = !wasExpanded;
                    head.setAttribute('aria-expanded', next ? 'true' : 'false');
                    writeSongbookCategoryExpanded(sk, next);
                    panel.classList.toggle('hidden', !next);
                    const icon = head.querySelector('.songbook-category-chevron');
                    if (icon) {
                        icon.classList.remove('fa-chevron-up', 'fa-chevron-down');
                        icon.classList.add(next ? 'fa-chevron-up' : 'fa-chevron-down');
                    }
                    return;
                }
                if (a === 'toolbar-prayer-detail-bookmark') {
                    const id = Number(action.dataset.prayerToggleBm);
                    if (id) toggleBookmark(id);
                    return;
                }
                if (a === 'toolbar-songbook-detail-bookmark') {
                    const id = Number(action.dataset.songbookToggleBm);
                    if (id) toggleSongbookBookmark(id);
                    return;
                }
                if (a === 'nav-up') {
                    e.preventDefault();
                    if (currentView === 'solemnities') {
                        if (solemnitiesSettingsOpen) {
                            solemnitiesSettingsOpen = false;
                            renderApp();
                            void hydrateSolemnities();
                            return;
                        }
                        currentView = 'home';
                        renderApp();
                        return;
                    }
                    goBack();
                    if (totusHistoryLen > 0) {
                        totusHistorySyncSkipPop = true;
                        history.back();
                    }
                    return;
                }
                if (a === 'prev-month') {
                    shiftCalendarMonth(-1);
                    return;
                }
                if (a === 'next-month') {
                    shiftCalendarMonth(1);
                    return;
                }
                if (a === 'solemnities-year-prev') {
                    if (currentView !== 'solemnities' || solemnitiesSettingsOpen) return;
                    const current = Number(document.getElementById('solemnities-root')?.dataset.solemnitiesYear) || readSolemnitiesYear();
                    writeSolemnitiesYear(current - 1);
                    renderApp();
                    void hydrateSolemnities();
                    return;
                }
                if (a === 'solemnities-year-next') {
                    if (currentView !== 'solemnities' || solemnitiesSettingsOpen) return;
                    const current = Number(document.getElementById('solemnities-root')?.dataset.solemnitiesYear) || readSolemnitiesYear();
                    writeSolemnitiesYear(current + 1);
                    renderApp();
                    void hydrateSolemnities();
                    return;
                }
                if (a === 'font-text-smaller') {
                    writeTextStep(readTextStep() - 1);
                    applyTextStep(readTextStep());
                    syncSettingsTextStepUi();
                    syncReadingTextToolbarButtons();
                    return;
                }
                if (a === 'font-text-larger') {
                    writeTextStep(readTextStep() + 1);
                    applyTextStep(readTextStep());
                    syncSettingsTextStepUi();
                    syncReadingTextToolbarButtons();
                    return;
                }
                if (a === 'reading-font-smaller') {
                    writeTextStep(readTextStep() - 1);
                    applyTextStep(readTextStep());
                    syncReadingTextToolbarButtons();
                    syncSettingsTextStepUi();
                    return;
                }
                if (a === 'reading-font-larger') {
                    writeTextStep(readTextStep() + 1);
                    applyTextStep(readTextStep());
                    syncReadingTextToolbarButtons();
                    syncSettingsTextStepUi();
                    return;
                }
                if (a === 'reset-font-text-defaults') {
                    resetTextAndFontToDefaults();
                    return;
                }
                if (a === 'clear-local-data') {
                    const ok = window.confirm(
                        'Будуць выдалены закладкі малітваў і спеўніка, захаваны выбар перакладу Пісання, стан разгортвання спісаў Новага/Старога Запавета, кэш малітваў, спеўніка і метаданых Пісання (убудаваныя файлы Пісання не змяняюцца). Працягнуць?'
                    );
                    if (!ok) return;
                    saveBookmarksSet(new Set());
                    saveSongbookBookmarksSet(new Set());
                    songbookBookmarksOnly = false;
                    if (prayerNav.screen === 'bookmarks_all') {
                        prayerNav = { screen: 'categories' };
                    }
                    try {
                        localStorage.removeItem(SCRIPTURE_TR_KEY);
                        localStorage.removeItem(SCRIPTURE_TESTAMENT_EXPAND_KEY);
                        localStorage.removeItem(SCRIPTURE_FAVORITES_KEY);
                        localStorage.removeItem(SCRIPTURE_COMPARE_VERSES_KEY);
                        localStorage.removeItem(SCRIPTURE_COMPARE_TRS_KEY);
                        localStorage.removeItem(SCRIPTURE_COMPARE_HINT_KEY);
                        localStorage.removeItem(SCRIPTURE_COMPARE_EXPAND_KEY);
                    } catch {
                        /* ignore */
                    }
                    scriptureJsonCache.clear();
                    scriptureSelectedId = null;
                    scriptureData = null;
                    scBookIdx = null;
                    scChapterNum = null;
                    scTranslationSettingsOpen = false;
                    scPanel = null;
                    scFocusVerse = null;
                    scriptureTranslationsList = null;
                    scriptureBundledIds = null;
                    scriptureBundledPromise = null;
                    scriptureCatalogById = null;
                    scriptureCatalogPromise = null;
                    prayersCache = null;
                    categoriesCache = null;
                    prayersLoadError = null;
                    prayersLoadErrorIsNetwork = false;
                    songbookCache = null;
                    songbookLoadError = null;
                    songbookLoadErrorIsNetwork = false;
                    renderApp();
                    window.alert(
                        'Дадзеныя былі скінуты. Малітвы, спеўнік і Пісанне зноў загрузяцца пры наступным адкрыцці адпаведных раздзелаў.'
                    );
                    return;
                }
            }
            const hc = e.target.closest('[data-home-card]');
            if (hc) {
                if (hc.dataset.homeAvailable === '0') {
                    return;
                }
                switchView(hc.dataset.homeCard);
                return;
            }
            const dateBtn = e.target.closest('[data-date]');
            if (dateBtn && dateBtn.dataset.date) {
                selectDate(dateBtn.dataset.date);
                return;
            }
            const pickCat = e.target.closest('[data-prayer-pick-cat]');
            if (pickCat && pickCat.dataset.prayerPickCat !== undefined) {
                const cat = decodeURIComponent(pickCat.dataset.prayerPickCat);
                prayerView = 'list';
                prayerSearchDraft = '';
                const subs = getSubcategoryNamesFromData(prayersCache || [], categoriesCache || [], cat);
                prayerNav =
                    subs.length === 0
                        ? { screen: 'prayers', category: cat, subcategory: NO_SUBCATEGORY_TITLE }
                        : { screen: 'subcategories', category: cat };
                totusHistoryMarkForward();
                renderApp();
                return;
            }
            const pickSub = e.target.closest('[data-prayer-pick-sub]');
            if (pickSub && pickSub.dataset.prayerPickSub !== undefined) {
                prayerView = 'list';
                prayerSearchDraft = '';
                const sub = decodeURIComponent(pickSub.dataset.prayerPickSub);
                const cat = decodeURIComponent(pickSub.dataset.prayerPickParent || '');
                prayerNav = { screen: 'prayers', category: cat, subcategory: sub };
                totusHistoryMarkForward();
                renderApp();
                return;
            }
            const openP = e.target.closest('.prayer-open');
            if (openP) {
                const id = Number(openP.dataset.prayerId);
                const p = (prayersCache || []).find((x) => Number(x.id) === id);
                if (p) {
                    prayerBeforeDetail = {
                        nav: { ...prayerNav },
                        view: prayerView,
                        draft: prayerSearchDraft,
                    };
                    prayerNav = { screen: 'detail', prayerId: id };
                    totusHistoryMarkForward();
                    renderApp();
                }
                return;
            }

            const sbRow = e.target.closest('[data-songbook-id]');
            if (sbRow) {
                const id = Number(sbRow.dataset.songbookId);
                const entry = (songbookCache || []).find((x) => Number(x.id) === id);
                if (entry) {
                    songbookStateBeforeDetail = { view: songbookView, query: songbookSearchQuery };
                    songbookDetailId = id;
                    totusHistoryMarkForward();
                    renderApp();
                }
                return;
            }

        });

        app.addEventListener('input', (e) => {
            if (e.target.id === 'prayer-search-query') {
                prayerSearchDraft = e.target.value;
                if (prayerSearchDebounceTimer) clearTimeout(prayerSearchDebounceTimer);
                prayerSearchDebounceTimer = setTimeout(() => {
                    prayerSearchDebounceTimer = null;
                    hydratePrayerSearchResults();
                }, 200);
            }
            if (e.target.id === 'songbook-search-query') {
                songbookSearchQuery = e.target.value;
                if (songbookSearchDebounceTimer) clearTimeout(songbookSearchDebounceTimer);
                songbookSearchDebounceTimer = setTimeout(() => {
                    songbookSearchDebounceTimer = null;
                    hydrateSongbookSearchResults();
                }, 200);
            }
            if (e.target.id === 'ordo-missae-search-query') {
                ordoMissaeSearchQuery = e.target.value;
                syncOrdoSearchNavVisibilityImmediate(ordoMissaeSearchQuery);
                if (ordoMissaeSearchDebounceTimer) clearTimeout(ordoMissaeSearchDebounceTimer);
                ordoMissaeSearchDebounceTimer = setTimeout(() => {
                    ordoMissaeSearchDebounceTimer = null;
                    hydrateOrdoMissaeSearchState();
                }, 180);
            }
        });

        app.addEventListener('keydown', (e) => {
            if (e.target.id !== 'ordo-missae-search-query') return;
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if (ordoMissaeSearchDebounceTimer) {
                clearTimeout(ordoMissaeSearchDebounceTimer);
                ordoMissaeSearchDebounceTimer = null;
            }
            ordoMissaeSearchQuery = e.target.value;
            hydrateOrdoMissaeSearchState();
        });

        app.addEventListener('change', (e) => {
            if (e.target.id === 'settings-font-select') {
                const v = e.target.value;
                if (v === 'sans' || v === 'serif' || v === 'mono') {
                    writeFontFamily(v);
                    applyFontFamily();
                    scheduleToolbarTitleFit();
                }
            }
            if (e.target.id === 'settings-theme-select') {
                const v = String(e.target.value || '');
                if (v === 'current' || v === 'beige') {
                    writeAppTheme(v);
                    applyAppTheme();
                    syncSettingsThemeUi();
                }
            }
            if (e.target.id === 'scripture-tr-select') {
                const el = document.getElementById('scripture-tr-description');
                if (el) {
                    const id = e.target.value;
                    el.textContent = scriptureCatalogDescription(id);
                }
            }
            const ctr = e.target.closest('[data-compare-tr-check]');
            if (ctr && ctr.matches('input[type="checkbox"]')) {
                const id = String(ctr.dataset.compareTrCheck || '');
                if (!id) return;
                const sel = getSelectedCompareTranslationIds();
                if (ctr.checked) sel.add(id);
                else sel.delete(id);
                const all = allCompareTranslationIdsDefault();
                if (sel.size === 0) {
                    all.forEach((x) => sel.add(x));
                }
                writeCompareTranslationIds(sel);
                if (currentView === 'scripture' && scPanel === 'compare') hydrateScriptureView();
            }
            const calDio = e.target.closest('[data-calendar-diocese-toggle]');
            if (calDio && calDio.matches('input[type="checkbox"]')) {
                const key = String(calDio.getAttribute('data-calendar-diocese-toggle') || '');
                if (!key) return;
                const t = readCalendarDioceseToggles();
                t[key] = calDio.checked;
                writeCalendarDioceseToggles(t);
                if (currentView === 'calendar') hydrateCalendar();
                if (currentView === 'day' && currentDate && currentDate.isValid) {
                    const dateStr = currentDate.toISODate();
                    void (async () => {
                        const { ok, data } = await loadLiturgyDay(dateStr);
                        showDayDetail(data, dateStr, ok);
                    })();
                }
            }
            const solemnDio = e.target.closest('[data-solemnities-diocese-toggle]');
            if (solemnDio && solemnDio.matches('input[type="checkbox"]')) {
                const key = String(solemnDio.getAttribute('data-solemnities-diocese-toggle') || '');
                if (!key) return;
                const t = readCalendarDioceseToggles();
                t[key] = solemnDio.checked;
                writeCalendarDioceseToggles(t);
            }
        });

        if (!window.__totusScriptureTrEsc) {
            window.__totusScriptureTrEsc = true;
            document.addEventListener('keydown', (ev) => {
                if (ev.key !== 'Escape') return;
                if (currentView === 'solemnities' && solemnitiesSettingsOpen) {
                    solemnitiesSettingsOpen = false;
                    renderApp();
                    void hydrateSolemnities();
                    if (totusHistoryLen > 0) {
                        totusHistorySyncSkipPop = true;
                        history.back();
                    }
                    return;
                }
                if (currentView === 'calendar-settings') {
                    exitCalendarSettings();
                    if (totusHistoryLen > 0) {
                        totusHistorySyncSkipPop = true;
                        history.back();
                    }
                    return;
                }
                const panel = document.getElementById('scripture-tr-panel');
                if (panel && !panel.classList.contains('hidden')) scriptureTrPanelClose();
                const fontPanel = document.getElementById('settings-font-panel');
                if (fontPanel && !fontPanel.classList.contains('hidden')) settingsFontPanelClose();
                const themePanel = document.getElementById('settings-theme-panel');
                if (themePanel && !themePanel.classList.contains('hidden')) settingsThemePanelClose();
                const homeThemePanel = document.getElementById('home-theme-panel');
                if (homeThemePanel && !homeThemePanel.classList.contains('hidden')) homeThemePanelClose();
            });
        }
    }

    function switchView(view) {
        if (view !== 'songbook' && view !== 'kantaral' && songbookSearchDebounceTimer) {
            clearTimeout(songbookSearchDebounceTimer);
            songbookSearchDebounceTimer = null;
        }
        if (view !== 'prayers' && prayerSearchDebounceTimer) {
            clearTimeout(prayerSearchDebounceTimer);
            prayerSearchDebounceTimer = null;
        }
        if (view !== 'ordo-missae' && ordoMissaeSearchDebounceTimer) {
            clearTimeout(ordoMissaeSearchDebounceTimer);
            ordoMissaeSearchDebounceTimer = null;
        }
        if (view === 'home') {
            prayerNav = { screen: 'categories' };
            prayerBeforeDetail = null;
            prayerView = 'list';
        }
        currentView = view;
        if (view === 'prayers') {
            prayerNav = { screen: 'categories' };
            prayerBeforeDetail = null;
            prayerView = 'list';
            prayerSearchDraft = '';
        }
        if (view === 'songbook' || view === 'kantaral') {
            songbookBookmarksOnly = false;
            songbookView = 'list';
            songbookSearchQuery = '';
            songbookSearchDebounceTimer = null;
            songbookDetailId = null;
            songbookStateBeforeDetail = null;
            if (view === 'kantaral') {
                songbookCache = kantaralCache;
                songbookLoadError = kantaralLoadError;
                songbookLoadErrorIsNetwork = kantaralLoadErrorIsNetwork;
            }
        }
        if (view === 'ordo-missae') {
            ordoMissaeSearchQuery = '';
            ordoMissaeSearchDebounceTimer = null;
        }
        if (view === 'solemnities') {
            solemnitiesSelectedYear = luxon.DateTime.now().year;
            solemnitiesSettingsOpen = false;
        }
        if (view === 'scripture') {
            scBookIdx = null;
            scChapterNum = null;
            scTranslationSettingsOpen = false;
            scPanel = null;
            scFocusVerse = null;
        }
        if (view !== 'calendar-settings') {
            calendarSettingsReturnView = null;
        }
        if (view !== 'scripture' && scriptureWordSearchDebounceTimer) {
            clearTimeout(scriptureWordSearchDebounceTimer);
            scriptureWordSearchDebounceTimer = null;
        }
        renderApp();
        if (view === 'solemnities') {
            void hydrateSolemnities();
        }
        if (view !== 'home') {
            totusHistoryMarkForward();
        }
    }

    /** Як SongbookEntry.numberPrefix() / listLabel() у Android. */
    function songbookNumberPrefix(e) {
        const maj = Number(e.chapter_major) || 0;
        if (e.subchapter == null || e.subchapter === '') return `${maj}.`;
        const sub = Number(e.subchapter);
        if (Number.isNaN(sub)) return `${maj}.`;
        return `${maj}.${sub}`;
    }

    function songbookListLabel(e) {
        const t = String(e.title || '').trim();
        const p = songbookNumberPrefix(e);
        if (e && e.show_number === false) return t === '' ? p : t;
        return t === '' ? p : `${p} ${t}`;
    }

    /** Як SongbookEntry.bookmarkListLabel() — без нумарацыі ў выбраным. */
    function songbookBookmarkListLabel(e) {
        const t = String(e.title || '').trim();
        return t !== '' ? t : songbookNumberPrefix(e);
    }

    function songbookCategoryKey(e) {
        return String(e && e.category != null ? e.category : '').trim();
    }

    /** Падзагаловак у шапцы дэталі — як SongbookEntry.categoryToolbarSubtitle(). */
    function songbookCategoryToolbarSubtitle(e) {
        const k = songbookCategoryKey(e).replace(/\s+/g, ' ').trim();
        return k === '' ? 'Без раздзелу' : k;
    }

    /** Як SongbookContentType.IMAGE. */
    function songbookContentIsImage(e) {
        return String(e && e.content_type != null ? e.content_type : '').toLowerCase() === 'image';
    }

    function songbookShouldShowBadge(e) {
        return songbookContentIsImage(e) && (!e || e.show_badge !== false);
    }

    /** Як songbookOrderComparator у SongbookRepository. */
    function sortSongbookLikeAndroid(entries) {
        return [...(entries || [])].sort((a, b) => {
            const ca = songbookCategoryKey(a);
            const cb = songbookCategoryKey(b);
            if (ca !== cb) return ca.localeCompare(cb, undefined, { sensitivity: 'base' });
            const ma = Number(a.chapter_major) || 0;
            const mb = Number(b.chapter_major) || 0;
            if (ma !== mb) return ma - mb;
            const sa = a.subchapter != null && a.subchapter !== '' ? Number(a.subchapter) : 0;
            const sb = b.subchapter != null && b.subchapter !== '' ? Number(b.subchapter) : 0;
            if (sa !== sb) return sa - sb;
            const oa = Number(a.sort_order) || 0;
            const ob = Number(b.sort_order) || 0;
            if (oa !== ob) return oa - ob;
            return (Number(a.id) || 0) - (Number(b.id) || 0);
        });
    }

    /** Выбранае (спеўнік): без першасці катэгорыі — толькі глава / падглава / sort_order / id. */
    function sortSongbookWithoutCategory(entries) {
        return [...(entries || [])].sort((a, b) => {
            const ma = Number(a.chapter_major) || 0;
            const mb = Number(b.chapter_major) || 0;
            if (ma !== mb) return ma - mb;
            const sa = a.subchapter != null && a.subchapter !== '' ? Number(a.subchapter) : 0;
            const sb = b.subchapter != null && b.subchapter !== '' ? Number(b.subchapter) : 0;
            if (sa !== sb) return sa - sb;
            const oa = Number(a.sort_order) || 0;
            const ob = Number(b.sort_order) || 0;
            if (oa !== ob) return oa - ob;
            return (Number(a.id) || 0) - (Number(b.id) || 0);
        });
    }

    const SONGBOOK_HTML_TAG_RE = /<[^>]+>/g;
    const SONGBOOK_NBSP_RE = /&nbsp;|&#160;/gi;

    function stripHtmlForSongbookSearch(html) {
        return String(html || '')
            .replace(SONGBOOK_NBSP_RE, ' ')
            .replace(SONGBOOK_HTML_TAG_RE, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function songbookEntryMatchesQuery(e, nq) {
        if (!nq) return false;
        if (e._totusSearchBody != null) {
            const cat = e._totusSearchCat;
            if (cat && cat.includes(nq)) return true;
            if (e._totusSearchTitle.includes(nq)) return true;
            if (e._totusSearchLabel.includes(nq)) return true;
            if (e._totusSearchNum.includes(nq)) return true;
            return e._totusSearchBody.includes(nq);
        }
        const cat = songbookCategoryKey(e).toLowerCase();
        if (cat && cat.includes(nq)) return true;
        const title = String(e.title || '').toLowerCase();
        if (title.includes(nq)) return true;
        if (songbookListLabel(e).toLowerCase().includes(nq)) return true;
        if (songbookNumberPrefix(e).toLowerCase().includes(nq)) return true;
        const body = stripHtmlForSongbookSearch(e.text || '');
        return body.includes(nq);
    }

    function attachSongbookSearchIndex(entries) {
        for (const e of entries || []) {
            e._totusSearchCat = songbookCategoryKey(e).toLowerCase();
            e._totusSearchTitle = String(e.title || '').toLowerCase();
            e._totusSearchLabel = songbookListLabel(e).toLowerCase();
            e._totusSearchNum = songbookNumberPrefix(e).toLowerCase();
            e._totusSearchBody = stripHtmlForSongbookSearch(e.text || '');
        }
    }

    function songbookFilterSearchResults(entries, query) {
        const nq = String(query || '').trim().toLowerCase();
        if (!nq) return [];
        const seen = new Set();
        const out = [];
        for (const e of entries || []) {
            const id = Number(e.id);
            if (seen.has(id)) continue;
            if (!songbookEntryMatchesQuery(e, nq)) continue;
            seen.add(id);
            out.push(e);
        }
        return sortSongbookLikeAndroid(out);
    }

    function songbookPlainBodyToHtml(text) {
        return escapeHtml(String(text || '')).replace(/\n/g, '<br/>');
    }

    /** Як java.lang.String.hashCode() / Kotlin — для ключоў згодна з Android SongbookCategoryExpandStore. */
    function javaStringHashCode(str) {
        let h = 0;
        const s = String(str || '');
        for (let i = 0; i < s.length; i++) {
            h = (Math.imul(31, h) + s.charCodeAt(i)) | 0;
        }
        return h;
    }

    function songbookCategoryStorageKeyFromGroupKey(groupKey) {
        if (groupKey === SONGBOOK_GROUP_UNCATEGORIZED) return 'u';
        return `c_${javaStringHashCode(groupKey)}`;
    }

    function readSongbookCategoryExpanded(storageKey) {
        try {
            const raw = localStorage.getItem(SONGBOOK_CATEGORY_EXPAND_KEY);
            if (raw) {
                const o = JSON.parse(raw);
                if (o && typeof o[storageKey] === 'boolean') return o[storageKey];
            }
        } catch {
            /* ignore */
        }
        return false;
    }

    function writeSongbookCategoryExpanded(storageKey, expanded) {
        try {
            let o = {};
            const raw = localStorage.getItem(SONGBOOK_CATEGORY_EXPAND_KEY);
            if (raw) {
                const p = JSON.parse(raw);
                if (p && typeof p === 'object') o = p;
            }
            o[storageKey] = expanded;
            localStorage.setItem(SONGBOOK_CATEGORY_EXPAND_KEY, JSON.stringify(o));
        } catch {
            /* ignore */
        }
    }

    /** Групы як [SongbookCategorySection.groupedIntoCategorySections] у Android. */
    function songbookGroupCategorySections(entries) {
        const sorted = sortSongbookLikeAndroid(entries || []);
        const map = new Map();
        for (const e of sorted) {
            const raw = songbookCategoryKey(e);
            const gk = raw === '' ? SONGBOOK_GROUP_UNCATEGORIZED : raw;
            if (!map.has(gk)) map.set(gk, []);
            map.get(gk).push(e);
        }
        const uncategorizedLabel = 'Без раздзелу';
        const out = [];
        let idx = 0;
        for (const [gk, list] of map) {
            const title = gk === SONGBOOK_GROUP_UNCATEGORIZED ? uncategorizedLabel : gk;
            const storageKey = songbookCategoryStorageKeyFromGroupKey(gk);
            out.push({ idx: idx++, groupKey: gk, storageKey, title, entries: list });
        }
        return out;
    }

    /** Карта раздзела — як scriptureTestamentCardHtml + songbook_category_section.xml. */
    function songbookCategoryCardHtml(sectionIdx, storageKey, title, items, expanded) {
        const idBase = `songbook-cat-${sectionIdx}`;
        const songsHtml = items.map((item) => songbookTreeRowHtml(item, { tight: true })).join('');
        const chevron = expanded ? 'fa-chevron-up' : 'fa-chevron-down';
        const panelHidden = expanded ? '' : ' hidden';
        const expAttr = expanded ? 'true' : 'false';
        const skAttr = encodeURIComponent(storageKey);
        return `<section class="rounded-md border border-app-stroke bg-app-elevated p-[18px]" aria-label="${escapeHtml(title)}">
            <button type="button" data-action="songbook-toggle-category" data-song-category-idx="${sectionIdx}" data-song-category-storage-key="${skAttr}"
                id="${idBase}-head" aria-expanded="${expAttr}" aria-controls="${idBase}-songs"
                class="w-full flex items-center gap-2 border-0 bg-transparent cursor-pointer text-left rounded-lg -mx-1 px-1 py-0.5">
                <span class="flex-1 min-w-0 font-bold text-app-text text-[17px] leading-snug">${escapeHtml(title)}</span>
                <span class="w-10 h-10 shrink-0 flex items-center justify-center text-app-textSec rounded-lg" aria-hidden="true">
                    <i class="songbook-category-chevron fas ${chevron} text-base"></i>
                </span>
            </button>
            <div id="${idBase}-songs" role="region" aria-labelledby="${idBase}-head" class="pt-3 space-y-2${panelHidden}">${songsHtml}</div>
        </section>`;
    }

    function songbookBuildSectionedListHtml(sortedEntries) {
        const sections = songbookGroupCategorySections(sortedEntries);
        if (sections.length === 0) return '';
        return sections
            .map((s) =>
                songbookCategoryCardHtml(
                    s.idx,
                    s.storageKey,
                    s.title,
                    s.entries,
                    readSongbookCategoryExpanded(s.storageKey)
                )
            )
            .join('');
    }

    /** Выбранае: адзін спіс без картачак катэгорый. */
    function songbookBuildFlatListHtml(sortedEntries) {
        if (!sortedEntries || sortedEntries.length === 0) return '';
        return sortedEntries.map((item) => songbookTreeRowHtml(item, { tight: true })).join('');
    }

    function songbookTreeRowHtml(item, opts) {
        const tight = opts && opts.tight;
        const gapCls = tight ? '' : ' mb-2 last:mb-0';
        const rowLabel = songbookBookmarksOnly ? songbookBookmarkListLabel(item) : songbookListLabel(item);
        const label = escapeHtml(rowLabel);
        const noteHtml = songbookShouldShowBadge(item)
            ? `<span class="w-5 h-5 shrink-0 flex items-center justify-center text-app-textSec" aria-label="З нотамі (відарыс)"><i class="fas fa-music text-sm" aria-hidden="true"></i></span>`
            : '';
        if (tight) {
            return `<button type="button" data-songbook-id="${Number(item.id)}" class="${APP_LIST_ROW_BTN_CLASS} songbook-tree-row${gapCls}">
                    <div class="${APP_LIST_ROW_INNER_CLASS}">
                        <span class="font-medium text-app-text flex-1 min-w-0 leading-snug">${label}</span>
                        ${noteHtml}
                    </div>
                </button>`;
        }
        return `<button type="button" data-songbook-id="${Number(item.id)}" class="songbook-tree-row w-full text-left rounded-md border border-app-stroke bg-app-elevated${gapCls} border-solid shadow-none hover:bg-white/[0.03] active:bg-white/[0.05] transition-colors cursor-pointer text-inherit p-0">
            <div class="flex items-center min-h-16 py-3.5 pl-[18px] pr-[18px] box-border gap-2">
                <div class="flex-1 min-w-0">
                    <div class="text-app-text text-[17px] leading-snug" style="line-height:1.35">${label}</div>
                </div>
                ${noteHtml}
            </div>
        </button>`;
    }

    function mediaAbsoluteUrl(path) {
        if (!path) return null;
        const p = String(path).trim();
        if (!p) return null;
        if (/^https?:\/\//i.test(p)) return p;
        const { apiBaseUrl, useServerProxy } = getApiConfig();
        const base = useServerProxy
            ? getResolvedWebPanelRoot()
            : apiBaseUrl.replace(/\/?$/i, '').replace(/\/api$/i, '');
        const seg = p.startsWith('/') ? p : '/' + p;
        try {
            return new URL(seg, base + '/').href;
        } catch {
            return base + seg;
        }
    }

    function songbookEntriesForListView(allEntries) {
        let list = allEntries || [];
        if (songbookBookmarksOnly) {
            const bm = getSongbookBookmarksSet();
            list = list.filter((e) => bm.has(Number(e.id)));
            return sortSongbookWithoutCategory(list);
        }
        return sortSongbookLikeAndroid(list);
    }

    async function ensureSongbookLoaded() {
        const isKantaral = currentView === 'kantaral';
        if (isKantaral && kantaralCache !== null) {
            songbookCache = kantaralCache;
            songbookLoadError = kantaralLoadError;
            songbookLoadErrorIsNetwork = kantaralLoadErrorIsNetwork;
            return;
        }
        if (!isKantaral && songbookCache !== null) return;
        songbookLoadError = null;
        songbookLoadErrorIsNetwork = false;
        if (!isApiConfigured()) {
            songbookLoadError = 'Наладзьце API: WebApp/api/proxy-secrets.php (useServerProxy) або apiKey у api-config.js';
            if (isKantaral) kantaralCache = [];
            else songbookCache = [];
            return;
        }
        const res = await apiFetch(isKantaral ? 'kantaral.php' : 'songbook.php');
        if (!res.ok || res.data.error) {
            const isNet = res.status === 0 || res.data.error === 'network_error';
            songbookLoadErrorIsNetwork = isNet;
            songbookLoadError = isNet
                ? humanizeClientFetchError(res.data.message || '')
                : res.data.message || res.data.error || (isKantaral ? 'Не ўдалося загрузіць кантарал' : 'Не ўдалося загрузіць спеўнік');
            if (isKantaral) kantaralCache = [];
            else songbookCache = [];
        } else {
            const loaded = Array.isArray(res.data) ? res.data : [];
            attachSongbookSearchIndex(loaded);
            if (isKantaral) kantaralCache = loaded;
            else songbookCache = loaded;
        }
        if (isKantaral) {
            songbookCache = kantaralCache;
            kantaralLoadError = songbookLoadError;
            kantaralLoadErrorIsNetwork = songbookLoadErrorIsNetwork;
        }
    }

    function songbookShellHtml() {
        return `
    <div class="max-w-[480px] mx-auto px-2 pb-8 pt-2 flex flex-col gap-3">
        <div id="songbook-list-screen" class="flex flex-col gap-2">
            <div id="songbook-loading" class="flex flex-col items-center justify-center py-16 gap-2 text-app-textTer">
                <i class="fas fa-circle-notch fa-spin text-3xl" aria-hidden="true"></i>
                <span class="text-sm">Загрузка спеўніка…</span>
            </div>
            <button type="button" data-action="songbook-open-search" id="songbook-search-entry" class="hidden w-full ${APP_SEARCH_BAR_CLASS} hover:bg-white/[0.04] cursor-pointer text-left transition-colors">
                <i class="fas fa-search text-app-textTer text-sm shrink-0" aria-hidden="true"></i>
                <span class="flex-1 min-w-0 text-sm text-app-textTer truncate text-left">${escapeHtml(currentView === 'kantaral' ? 'Пошук у кантарале' : 'Пошук у спеўніку')}</span>
            </button>
            <div id="songbook-root" class="hidden min-h-[200px]"></div>
        </div>
        <div id="songbook-search-screen" class="hidden space-y-3 pb-4">
            <div class="${APP_SEARCH_BAR_CLASS}">
                <i class="fas fa-search text-app-textTer text-sm shrink-0" aria-hidden="true"></i>
                <input type="search" id="songbook-search-query" autocomplete="off" placeholder="Назва або слова з тэксту…"
                    class="${APP_SEARCH_INPUT_CLASS}" />
            </div>
            <p id="songbook-search-status" class="text-sm text-app-textSec leading-snug px-0.5"></p>
            <div id="songbook-search-results" class="space-y-0"></div>
        </div>
        <div id="songbook-detail-screen" class="hidden">
            <div id="songbook-detail-panel" class="songbook-detail-panel rounded-xl border border-app-stroke bg-app-elevated shadow-lg flex flex-col overflow-hidden max-h-[calc(100dvh-5.5rem)]">
                <div id="songbook-detail-body" class="totus-read-17 overflow-y-auto leading-relaxed flex-1 min-h-0"></div>
            </div>
        </div>
    </div>`;
    }

    function hydrateSongbookSearchResults() {
        const statusEl = document.getElementById('songbook-search-status');
        const resRoot = document.getElementById('songbook-search-results');
        const qInput = document.getElementById('songbook-search-query');
        if (!statusEl || !resRoot) return;
        const q = qInput ? qInput.value : songbookSearchQuery;
        const allEntries = songbookCache || [];
        if (songbookLoadError || allEntries.length === 0) {
            statusEl.textContent = 'Увядзіце пошукавы запыт — спіс абнавіцца адразу.';
            statusEl.classList.remove('hidden');
            resRoot.innerHTML = '';
            return;
        }
        const trimmed = String(q || '').trim();
        if (trimmed === '') {
            statusEl.textContent = 'Увядзіце пошукавы запыт — спіс абнавіцца адразу.';
            statusEl.classList.remove('hidden');
            resRoot.innerHTML = '';
            return;
        }
        const results = songbookFilterSearchResults(allEntries, trimmed);
        if (results.length === 0) {
            statusEl.textContent = 'Нічога не знойдзена. Паспрабуйце іншы запыт.';
            statusEl.classList.remove('hidden');
            resRoot.innerHTML = '';
            return;
        }
        statusEl.classList.add('hidden');
        resRoot.innerHTML = `<div class="flex flex-col gap-2">${songbookBuildSectionedListHtml(results)}</div>`;
    }

    async function hydrateSongbook() {
        const listScreen = document.getElementById('songbook-list-screen');
        const searchScreen = document.getElementById('songbook-search-screen');
        const detailScreen = document.getElementById('songbook-detail-screen');
        const loadEl = document.getElementById('songbook-loading');
        const root = document.getElementById('songbook-root');
        const searchEntry = document.getElementById('songbook-search-entry');
        const qInput = document.getElementById('songbook-search-query');
        if (!loadEl || !root) return;

        await ensureSongbookLoaded();

        if (songbookDetailId != null) {
            if (listScreen) listScreen.classList.add('hidden');
            if (searchScreen) searchScreen.classList.add('hidden');
            if (detailScreen) detailScreen.classList.remove('hidden');
            const entry = (songbookCache || []).find((x) => Number(x.id) === Number(songbookDetailId));
            renderSongbookDetailView(entry || null);
            loadEl.classList.add('hidden');
            root.classList.add('hidden');
            return;
        }
        if (detailScreen) detailScreen.classList.add('hidden');

        const showSearchUi = songbookView === 'search';
        if (listScreen) listScreen.classList.toggle('hidden', showSearchUi);
        if (searchScreen) searchScreen.classList.toggle('hidden', !showSearchUi);

        if (showSearchUi) {
            if (qInput) qInput.value = songbookSearchQuery;
            hydrateSongbookSearchResults();
            loadEl.classList.add('hidden');
            root.classList.add('hidden');
            return;
        }

        loadEl.classList.remove('hidden');
        root.classList.add('hidden');
        if (searchEntry) searchEntry.classList.add('hidden');

        if (songbookLoadError) {
            loadEl.classList.add('hidden');
            root.classList.remove('hidden');
            if (searchEntry) searchEntry.classList.add('hidden');
            const { apiBaseUrl } = getApiConfig();
            const hint = songbookLoadErrorIsNetwork ? apiNetworkFailureHint(apiBaseUrl) : '';
            root.innerHTML = `<div class="p-4 bg-red-950/40 border border-red-500/30 text-app-error rounded-md text-sm">${escapeHtml(songbookLoadError)}${hint}</div>`;
            return;
        }

        loadEl.classList.add('hidden');
        root.classList.remove('hidden');
        if (searchEntry && !songbookBookmarksOnly) searchEntry.classList.remove('hidden');

        const allEntries = songbookCache || [];
        if (allEntries.length === 0) {
            root.innerHTML = `<p class="text-center text-app-textSec text-[15px] leading-snug py-12 px-4">Пакуль няма спеваў. Уключыце інтэрнэт (або згоду на аўтаабнаўленне ў наладах) — змест падцягваецца з сервера.</p>`;
            return;
        }

        const entries = songbookEntriesForListView(allEntries);
        if (entries.length === 0) {
            root.innerHTML = `<p class="text-center text-app-textSec text-[15px] leading-snug py-12 px-4">Пакуль няма выбраных спеваў.</p>`;
            return;
        }

        const listBody = songbookBookmarksOnly
            ? songbookBuildFlatListHtml(entries)
            : songbookBuildSectionedListHtml(entries);
        root.innerHTML = `<div class="flex flex-col gap-2">${listBody}</div>`;
    }

    /** Павелічэнне па кліку і перацягванне ўнутры блока (мыш / тач). */
    function initSongbookDetailImageZoom() {
        const host = document.querySelector('[data-songbook-zoom-host]');
        if (!host) return;
        const inner = host.querySelector('.songbook-image-zoom-inner');
        const img = host.querySelector('img');
        if (!inner || !img) return;

        let scale = 1;
        let tx = 0;
        let ty = 0;
        let drag = false;
        let startX = 0;
        let startY = 0;
        let stx = 0;
        let sty = 0;
        let moved = false;
        const DRAG_THRESH = 6;

        function apply() {
            inner.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`;
            inner.style.transformOrigin = 'left top';
            host.style.cursor = scale > 1.01 ? (drag ? 'grabbing' : 'grab') : 'zoom-in';
            host.classList.toggle('songbook-image-zoom--scaled', scale > 1.01);
        }

        function clamp() {
            const w = host.clientWidth;
            const h = host.clientHeight;
            const iw = img.offsetWidth * scale;
            const ih = img.offsetHeight * scale;
            if (iw <= w) tx = 0;
            else tx = Math.min(0, Math.max(w - iw, tx));
            if (ih <= h) ty = 0;
            else ty = Math.min(0, Math.max(h - ih, ty));
        }

        function onPointerDown(e) {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            if (scale > 1.01) {
                drag = true;
                moved = false;
                startX = e.clientX;
                startY = e.clientY;
                stx = tx;
                sty = ty;
                e.preventDefault();
                try {
                    host.setPointerCapture(e.pointerId);
                } catch (_) {
                    /* ignore */
                }
            }
        }

        function clearBrowserSelection() {
            const sel = window.getSelection && window.getSelection();
            if (sel && sel.rangeCount > 0) sel.removeAllRanges();
        }

        function onPointerMove(e) {
            if (!drag) return;
            e.preventDefault();
            clearBrowserSelection();
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            if (Math.abs(dx) > DRAG_THRESH || Math.abs(dy) > DRAG_THRESH) moved = true;
            tx = stx + dx;
            ty = sty + dy;
            clamp();
            apply();
        }

        function onPointerUp(e) {
            if (drag) {
                drag = false;
                clearBrowserSelection();
                try {
                    host.releasePointerCapture(e.pointerId);
                } catch (_) {
                    /* ignore */
                }
            }
        }

        host.addEventListener('click', () => {
            if (moved) {
                moved = false;
                return;
            }
            scale = scale > 1.5 ? 1 : 2;
            tx = 0;
            ty = 0;
            clamp();
            apply();
        });

        host.addEventListener('pointerdown', onPointerDown);
        host.addEventListener('pointermove', onPointerMove);
        host.addEventListener('pointerup', onPointerUp);
        host.addEventListener('pointercancel', onPointerUp);
        host.addEventListener(
            'selectstart',
            (e) => {
                e.preventDefault();
            },
            true
        );
        host.addEventListener(
            'dragstart',
            (e) => {
                e.preventDefault();
            },
            true
        );
        img.addEventListener('dragstart', (e) => e.preventDefault());

        function afterLayout() {
            clamp();
            apply();
        }
        if (img.complete) {
            requestAnimationFrame(afterLayout);
        }
        img.addEventListener('load', () => requestAnimationFrame(afterLayout));
        apply();
    }

    function renderSongbookDetailView(entry) {
        const panel = document.getElementById('songbook-detail-panel');
        const b = document.getElementById('songbook-detail-body');
        if (!panel || !b) return;
        if (!entry) {
            b.className = 'totus-read-17 overflow-y-auto leading-relaxed flex-1 min-h-0 p-[18px]';
            b.innerHTML = `<p class="text-app-textTer italic">Запіс не знойдзены.</p>`;
            refreshToolbarRightActions();
            syncReadingTextToolbarButtons();
            return;
        }

        const ct = String(entry.content_type || 'text').toLowerCase();
        const mediaUrl = mediaAbsoluteUrl(entry.media_url);
        let inner = '';
        let lightPaper = false;

        if (ct === 'image' && mediaUrl) {
            lightPaper = true;
            inner = `<div class="songbook-image-zoom-host w-full min-h-[200px] max-h-[min(72vh,640px)] shrink-0 overflow-hidden bg-white relative select-none touch-manipulation outline-none focus:outline-none" data-songbook-zoom-host tabindex="-1" aria-label="Выява: павялічыць клікам, перацягнуць пры павелічэнні">
                <div class="songbook-image-zoom-inner inline-block will-change-transform">
                    <img src="${escapeHtml(mediaUrl)}" alt="" class="block w-full h-auto max-w-none pointer-events-none" draggable="false" />
                </div>
            </div>`;
        } else if (ct === 'audio' && mediaUrl) {
            inner = `<div class="p-4"><div class="rounded-lg bg-app-bg2 p-3"><audio controls class="w-full" src="${escapeHtml(mediaUrl)}">Ваш браўзер не прайграе гук.</audio></div></div>`;
        } else {
            const raw = String(entry.text || '').trim();
            if (raw === '' && !mediaUrl) {
                inner = `<div class="p-[18px]"><p class="text-app-textTer italic">Няма тэксту.</p></div>`;
            } else if (stringLooksLikeHtmlFragment(raw)) {
                inner = `<div class="songbook-detail-html p-[18px] text-app-text">${raw}</div>`;
            } else {
                inner = `<div class="songbook-detail-plain p-[18px] text-app-text">${songbookPlainBodyToHtml(raw)}</div>`;
            }
        }

        if (lightPaper) {
            panel.classList.add('bg-white', 'border-stone-200');
            panel.classList.remove('bg-app-elevated', 'border-app-stroke');
            b.className =
                'totus-read-17 overflow-y-auto songbook-modal-paper bg-white text-stone-900 leading-relaxed flex-1 min-h-0';
        } else {
            panel.classList.remove('bg-white', 'border-stone-200');
            panel.classList.add('bg-app-elevated', 'border-app-stroke');
            b.className = 'totus-read-17 overflow-y-auto p-0 leading-relaxed flex-1 min-h-0';
        }
        b.innerHTML = inner;
        refreshToolbarRightActions();
        syncReadingTextToolbarButtons();
        if (ct === 'image' && mediaUrl) {
            requestAnimationFrame(() => initSongbookDetailImageZoom());
        }
    }

    function settingsShellHtml() {
        const step = readTextStep();
        const stepLabel = step + 1;
        const ff = readFontFamily();
        const theme = readAppTheme();
        const opts = SETTINGS_FONT_ROWS.map(
            (row) =>
                `<option value="${escapeHtml(row.id)}"${row.id === ff ? ' selected' : ''}>${escapeHtml(row.title)}</option>`
        ).join('');
        const themeOpts = SETTINGS_THEME_ROWS.map(
            (row) =>
                `<option value="${escapeHtml(row.id)}"${row.id === theme ? ' selected' : ''}>${escapeHtml(row.title)}</option>`
        ).join('');
        const themeSwitchRows = SETTINGS_THEME_ROWS.map((row) => {
            const picked = row.id === theme;
            return `<button type="button" data-settings-theme-switch="${escapeHtml(row.id)}" aria-pressed="${picked ? 'true' : 'false'}"
            class="settings-theme-switch-btn flex-1 min-w-0 px-4 py-2.5 rounded-lg text-sm font-medium cursor-pointer border-solid transition-colors ${picked ? 'bg-app-surface text-app-text border-2 border-app-stroke' : 'bg-app-bg2 text-app-textSec border border-app-stroke hover:bg-white/[0.04]'}">${escapeHtml(row.title)}</button>`;
        }).join('');
        const presetRow = SETTINGS_FONT_ROWS.find((r) => r.id === ff) || SETTINGS_FONT_ROWS[0];
        const presetTitleEsc = escapeHtml(presetRow.title);
        const optionRows = SETTINGS_FONT_ROWS.map((row) => {
            const picked = row.id === ff;
            return `<button type="button" role="option" data-settings-font-option="${escapeHtml(row.id)}" aria-selected="${picked ? 'true' : 'false'}"
            class="settings-font-option-btn w-full text-left px-4 py-3 text-sm border-0 cursor-pointer transition-colors border-solid ${picked ? 'bg-app-surface text-app-text font-medium' : 'bg-transparent text-app-text hover:bg-white/[0.06]'}">
            <span class="settings-font-option-title leading-snug">${escapeHtml(row.title)}</span>
        </button>`;
        }).join('');
        return `
    <div class="max-w-[480px] mx-auto px-2 pb-8 pt-2 space-y-3">
      <section class="rounded-md border border-app-stroke bg-app-elevated p-[18px] space-y-3">
        <h2 class="text-base font-bold text-app-text">Тэкст і шрыфты</h2>
        <div>
          <label id="settings-font-label" class="block text-sm text-app-textSec leading-snug">Шрыфт для ўсяго дадатка:</label>
          <div class="settings-font-custom relative mt-1.5">
            <select id="settings-font-select" class="sr-only" aria-labelledby="settings-font-label" tabindex="-1">${opts}</select>
            <button type="button" id="settings-font-trigger" aria-labelledby="settings-font-label" aria-haspopup="listbox" aria-expanded="false"
              class="w-full rounded-xl border border-solid border-app-stroke bg-app-bg2 px-3 py-2.5 min-h-[52px] flex items-stretch gap-2 text-left hover:bg-white/[0.04] transition-colors focus:outline-none">
              <span id="settings-font-trigger-label" class="flex-1 min-w-0 self-center truncate text-sm text-app-text font-medium">${presetTitleEsc}</span>
              <span class="settings-font-chevron-wrap shrink-0 w-11 h-11 flex items-center justify-center self-center rounded-lg bg-app-surface border border-app-stroke/80 text-app-textSec" aria-hidden="true"><i class="fas fa-chevron-down text-sm"></i></span>
            </button>
            <div id="settings-font-panel" class="hidden absolute left-0 right-0 top-[calc(100%+8px)] z-[100] rounded-xl border border-solid border-app-stroke bg-app-elevated py-2 shadow-2xl shadow-black/40 max-h-[min(50vh,340px)] overflow-y-auto divide-y divide-app-stroke/40" role="listbox" aria-labelledby="settings-font-label">
              ${optionRows}
            </div>
          </div>
        </div>
        <p class="text-sm text-app-textSec leading-relaxed">Памер тэксту для малітваў, спеўніка, вершаў Святога Пісання і лекцыянарыя. Спісы, меню і шапка застаюцца без зменаў. 5 крокаў: крок 1 — прадвызначаны, крок 5 — удвая больш за крок 1.</p>
        <div class="mt-2.5 space-y-1">
        <p class="text-xs text-app-textTer leading-snug">Прыклад тэксту:</p>
        <div class="rounded-md border border-app-stroke bg-app-bg2 overflow-y-auto h-[99px] px-3 py-2.5">
            <p class="totus-read-16 text-app-text leading-[1.35]">${escapeHtml(SETTINGS_TEXT_PREVIEW_SAMPLE)}</p>
        </div>
        </div>
        <p id="settings-text-step-label" class="text-center text-lg font-bold text-app-text">Крок ${stepLabel} з 5</p>
        <div class="flex items-center justify-between gap-2">
          <p class="text-sm text-app-textSec leading-snug whitespace-nowrap m-0">Націсніце, каб змяніць:</p>
          <div class="flex items-center gap-2 justify-end">
            <button type="button" id="settings-font-smaller" data-action="font-text-smaller" class="px-4 py-2.5 rounded-md border border-app-stroke bg-app-bg2 text-app-text text-sm font-medium hover:bg-white/5 cursor-pointer border-solid disabled:opacity-40" ${step <= TEXT_STEP_MIN ? 'disabled' : ''}>А−</button>
            <button type="button" id="settings-font-larger" data-action="font-text-larger" class="px-4 py-2.5 rounded-md border border-app-stroke bg-app-bg2 text-app-text text-sm font-medium hover:bg-white/5 cursor-pointer border-solid disabled:opacity-40" ${step >= TEXT_STEP_MAX ? 'disabled' : ''}>А+</button>
          </div>
        </div>
        <button type="button" data-action="reset-font-text-defaults" class="w-full px-4 py-2.5 rounded-md border border-app-stroke bg-transparent text-app-text text-sm font-medium hover:bg-white/5 cursor-pointer border-solid">
          Скінуць налады тэксту да прадвызначаных
        </button>
      </section>
      <section class="rounded-md border border-app-stroke bg-app-elevated p-[18px] space-y-3">
        <h2 class="text-base font-bold text-app-text">Колеравая схема</h2>
        <select id="settings-theme-select" class="sr-only" tabindex="-1">${themeOpts}</select>
        <div class="flex items-center gap-2">
          ${themeSwitchRows}
        </div>
      </section>
      <section class="rounded-md border border-app-stroke bg-app-elevated p-[18px] space-y-3">
        <h2 class="text-base font-bold text-app-text">Даныя ў браўзеры</h2>
        <p class="text-sm text-app-textSec leading-relaxed">Можна ачысціць лакальна загружаныя малітвы і спеўнік (уключна з файламі). Каб загрузіць зноў: у малітоўніку — «Абнавіць малітвы», у спеўніку — «Абнавіць спеўнік» (калі аўтаабнаўленне выключанае).</p>
        <button type="button" data-action="clear-local-data" class="w-full px-4 py-2.5 rounded-md border border-app-stroke bg-app-bg2 text-app-text text-sm font-medium hover:bg-white/5 cursor-pointer border-solid">
          Скінуць дадзеныя і перазапусціць
        </button>
      </section>
    </div>`;
    }

    function aboutShellHtml() {
        return `
    <div class="max-w-[480px] mx-auto px-2 pb-8 pt-2">
      <section class="totus-about-card rounded-md border border-app-stroke bg-app-elevated p-[18px] flex flex-col text-sm leading-snug">
        <p class="text-app-textSec m-0">Totus Tuus - гэта прыкладанне для каталікоў на беларускай мове. Дадатак аб'ядноўвае ў адным месцы малітоўнік, спеўнік, Святое Пісанне і літургічны каляндар, каб патрэбныя тэксты заўсёды былі пад рукой.</p>
        <p class="text-app-textSec m-0">Просьба пра памылкі ці ідэі пісаць на Email:<br><a href="mailto:dzmitrypanou@gmail.com" class="text-app-text underline-offset-2 hover:underline">dzmitrypanou@gmail.com</a></p>
        <p class="text-app-textSec m-0">Вэб-дадатак:<br><a href="https://app.kasciolhomiel.by/" target="_blank" rel="noopener noreferrer" class="text-app-text underline-offset-2 hover:underline">app.kasciolhomiel.by</a></p>
        <p class="text-app-textSec m-0">Версія: v${escapeHtml(totusWebDisplayedVersion())}</p>
      </section>
    </div>`;
    }

    function scriptureShellHtml() {
        return `<div class="max-w-[480px] mx-auto px-2 pb-3 pt-2 flex flex-col min-h-0 min-w-0">
        <div id="scripture-root" class="flex flex-col min-h-0 min-w-0 gap-3 text-app-text"></div>
    </div>`;
    }

    /** Як ScriptureTextRepository.getTestaments: book_id 40–66 — Новы Запавет (кананічная нумарацыя). */
    function scriptureIsNewTestamentBookId(bookId) {
        const n = Number(bookId);
        return Number.isFinite(n) && n >= 40 && n <= 66;
    }

    function scriptureTestamentSectionTitles(translationId) {
        return String(translationId) === 'synodal_ru'
            ? { nt: 'Новый завет', ot: 'Старый завет' }
            : { nt: 'Новы Запавет', ot: 'Стары Запавет' };
    }

    function readScriptureTestamentExpanded(sectionKey) {
        try {
            const raw = localStorage.getItem(SCRIPTURE_TESTAMENT_EXPAND_KEY);
            if (raw) {
                const o = JSON.parse(raw);
                if (o && typeof o[sectionKey] === 'boolean') return o[sectionKey];
            }
        } catch {
            /* ignore */
        }
        return false;
    }

    function writeScriptureTestamentExpanded(sectionKey, expanded) {
        try {
            let o = {};
            const raw = localStorage.getItem(SCRIPTURE_TESTAMENT_EXPAND_KEY);
            if (raw) {
                const p = JSON.parse(raw);
                if (p && typeof p === 'object') o = p;
            }
            o[sectionKey] = expanded;
            localStorage.setItem(SCRIPTURE_TESTAMENT_EXPAND_KEY, JSON.stringify(o));
        } catch {
            /* ignore */
        }
    }

    function scriptureBookRowButtonHtml(bk, idx) {
        return `<button type="button" data-scripture-action="open-book" data-book-idx="${idx}" class="${APP_LIST_ROW_BTN_CLASS}">
                    <div class="${APP_LIST_ROW_INNER_CLASS}">
                        <span class="font-medium text-app-text flex-1 min-w-0 leading-snug">${escapeHtml(bk.book_name)}</span>
                        <span class="flex items-center gap-2 shrink-0">
                            <span class="text-app-textTer text-sm tabular-nums">${bk.chapter_count}</span>
                            <span class="w-7 h-7 flex items-center justify-center text-app-textTer" aria-hidden="true"><i class="fas fa-chevron-right text-sm"></i></span>
                        </span>
                    </div>
                </button>`;
    }

    /** Карта секцыі завета — як scripture_testament_section.xml + collapse у ScriptureFragment. */
    function scriptureTestamentCardHtml(sectionKey, title, items, expanded) {
        const idBase = `scripture-testament-${sectionKey}`;
        const booksHtml = items.map((x) => scriptureBookRowButtonHtml(x.bk, x.idx)).join('');
        const chevron = expanded ? 'fa-chevron-up' : 'fa-chevron-down';
        const panelHidden = expanded ? '' : ' hidden';
        const expAttr = expanded ? 'true' : 'false';
        return `<section class="rounded-md border border-app-stroke bg-app-elevated p-[18px]" aria-label="${escapeHtml(title)}">
            <button type="button" data-scripture-action="toggle-testament" data-testament-section="${escapeHtml(sectionKey)}"
                id="${idBase}-head" aria-expanded="${expAttr}" aria-controls="${idBase}-books"
                class="w-full flex items-center gap-2 border-0 bg-transparent cursor-pointer text-left rounded-lg -mx-1 px-1 py-0.5">
                <span class="flex-1 min-w-0 font-bold text-app-text text-[17px] leading-snug">${escapeHtml(title)}</span>
                <span class="w-10 h-10 shrink-0 flex items-center justify-center text-app-textSec rounded-lg" aria-hidden="true">
                    <i class="scripture-testament-chevron fas ${chevron} text-base"></i>
                </span>
            </button>
            <div id="${idBase}-books" role="region" aria-labelledby="${idBase}-head" class="pt-3 space-y-2${panelHidden}">${booksHtml}</div>
        </section>`;
    }

    function scriptureBooksListHtml(books, translationId) {
        const { nt: ntTitle, ot: otTitle } = scriptureTestamentSectionTitles(translationId);
        const withIdx = books.map((bk, idx) => ({ bk, idx }));
        const ntItems = withIdx.filter((x) => scriptureIsNewTestamentBookId(x.bk.book_id));
        const otItems = withIdx.filter((x) => !scriptureIsNewTestamentBookId(x.bk.book_id));
        const parts = [];
        if (ntItems.length > 0) {
            parts.push(scriptureTestamentCardHtml('nt', ntTitle, ntItems, readScriptureTestamentExpanded('nt')));
        }
        if (otItems.length > 0) {
            parts.push(
                scriptureTestamentCardHtml('ot', otTitle, otItems, readScriptureTestamentExpanded('ot'))
            );
        }
        if (parts.length === 0) {
            return '<p class="text-app-textTer text-center py-12 text-sm">Няма кніг у гэтым перакладзе.</p>';
        }
        const searchRow = `<button type="button" data-scripture-action="open-word-search" class="w-full ${APP_SEARCH_BAR_CLASS} hover:bg-white/[0.04] cursor-pointer text-left transition-colors border-solid">
                <i class="fas fa-search text-app-textTer text-sm shrink-0" aria-hidden="true"></i>
                <span class="flex-1 min-w-0 text-sm text-app-textTer truncate text-left">Пошук па Пісанні</span>
            </button>`;
        return `<div class="flex flex-col gap-2">${searchRow}${parts.join('')}</div>`;
    }

    /** Літаральныя «\\n» / «\\r» ў JSON (падвоены экран) → сапраўдныя пераносы для whitespace-pre-wrap. */
    function normalizeScriptureCatalogDescription(s) {
        return String(s || '')
            .replace(/\\r\\n/g, '\n')
            .replace(/\\r/g, '\n')
            .replace(/\\n/g, '\n');
    }

    async function loadScriptureCatalogOnce() {
        if (scriptureCatalogById) return;
        if (!scriptureCatalogPromise) {
            scriptureCatalogPromise = (async () => {
                const map = new Map();
                try {
                    const url = totusAssetUrl(`${SCRIPTURE_BUNDLE_BASE}/scripture_catalog.json`);
                    const r = await fetch(url);
                    if (r.ok) {
                        const arr = await r.json();
                        if (Array.isArray(arr)) {
                            for (const e of arr) {
                                const id = String(e.id || '');
                                if (!id) continue;
                                map.set(id, {
                                    id,
                                    title: String(e.title || id),
                                    description: normalizeScriptureCatalogDescription(String(e.description || '')),
                                });
                            }
                        }
                    }
                } catch {
                    /* offline / file:// */
                }
                if (map.size === 0) {
                    for (const e of SCRIPTURE_TRANSLATIONS_FALLBACK) {
                        map.set(String(e.id), { id: String(e.id), title: e.title, description: '' });
                    }
                }
                scriptureCatalogById = map;
            })();
        }
        await scriptureCatalogPromise;
    }

    function scriptureCatalogDescription(trId) {
        const row = scriptureCatalogById?.get(String(trId));
        return row?.description || '';
    }

    function scriptureTranslationShortLabel(trId) {
        const id = String(trId || '');
        if (SCRIPTURE_TR_SHORT[id]) return SCRIPTURE_TR_SHORT[id];
        const row = (scriptureTranslationsList || []).find((x) => String(x.id) === id);
        const title = row?.title || id;
        if (title.length <= 28) return title;
        return `${title.slice(0, 26).trim()}…`;
    }

    function syncScriptureToolbarTitle() {
        if (currentView !== 'scripture') return;
        const el = document.getElementById('scripture-toolbar-title');
        if (!el) return;
        const h1Cls = TOOLBAR_H1_CLASS;
        if (scPanel === 'favorites') {
            el.className = h1Cls;
            el.textContent = 'Выбраныя вершы';
            scheduleToolbarTitleFit();
            return;
        }
        if (scPanel === 'compare') {
            el.className = h1Cls;
            el.textContent = 'Параўнанне вершаў';
            scheduleToolbarTitleFit();
            return;
        }
        if (scPanel === 'word_search') {
            el.className = h1Cls;
            el.textContent = 'Пошук па Пісанні';
            scheduleToolbarTitleFit();
            return;
        }
        const readingTitle = scriptureReadingToolbarTitleText();
        if (readingTitle) {
            el.className = h1Cls;
            el.textContent = readingTitle;
            scheduleToolbarTitleFit();
            return;
        }
        const showShort = scriptureSelectedId && !scTranslationSettingsOpen;
        const short = showShort ? scriptureTranslationShortLabel(scriptureSelectedId) : '';
        if (short) {
            el.className = h1Cls;
            el.textContent = `Святое Пісанне: ${short}`;
        } else {
            el.className = h1Cls;
            el.textContent = 'Святое Пісанне';
        }
        scheduleToolbarTitleFit();
    }

    /** Як ScriptureWordSearch.wholeWordRegex — цэлыя словы, без уліку рэгістра (Unicode). */
    function buildScriptureWholeWordRegex(query) {
        const trimmed = String(query || '').trim();
        if (!trimmed) return null;
        const escaped = trimmed.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const body = `(?<![\\p{L}\\p{M}\\p{N}])${escaped}(?![\\p{L}\\p{M}\\p{N}])`;
        try {
            return new RegExp(body, 'giu');
        } catch {
            try {
                return new RegExp(body, 'gi');
            } catch {
                return null;
            }
        }
    }

    function scriptureWordSearchCountMatches(text, re) {
        if (!re) return 0;
        const r = new RegExp(re.source, re.flags);
        let n = 0;
        let m;
        while ((m = r.exec(text)) !== null) {
            n += 1;
            if (m[0].length === 0) r.lastIndex += 1;
        }
        return n;
    }

    function highlightScriptureWordSearchHtml(plainText, re) {
        if (!re) return escapeHtml(plainText);
        const r = new RegExp(re.source, re.flags);
        let out = '';
        let last = 0;
        let m;
        while ((m = r.exec(plainText)) !== null) {
            out += escapeHtml(plainText.slice(last, m.index));
            out += `<mark class="bg-amber-500/35 text-app-text rounded px-0.5">${escapeHtml(m[0])}</mark>`;
            last = m.index + m[0].length;
            if (m[0].length === 0) r.lastIndex += 1;
        }
        out += escapeHtml(plainText.slice(last));
        return out;
    }

    async function scriptureWordSearchCollect(trimmedQuery) {
        const re = buildScriptureWholeWordRegex(trimmedQuery);
        const hits = [];
        let totalOccurrences = 0;
        if (!re) return { re: null, hits, totalOccurrences, versesWithMatches: 0 };
        const books = (scriptureData && scriptureData.books) || [];
        for (let bi = 0; bi < books.length; bi++) {
            const bk = books[bi];
            for (const ch of bk.chapters || []) {
                const chNum = Number(ch.chapter);
                for (const v of ch.verses || []) {
                    const t = String(v.text || '');
                    const n = scriptureWordSearchCountMatches(t, re);
                    if (n > 0) {
                        totalOccurrences += n;
                        hits.push({
                            bookId: Number(bk.book_id),
                            bookName: String(bk.book_name || ''),
                            chapter: chNum,
                            verse: Number(v.verse),
                            text: t,
                            matchesInVerse: n,
                        });
                    }
                }
            }
            await new Promise((res) => setTimeout(res, 0));
        }
        return { re, hits, totalOccurrences, versesWithMatches: hits.length };
    }

    /** Вынік пошуку па Пісанні — тая ж сетка, што prayerTreeRowHtml / hydratePrayerSearchResults. */
    function scriptureWordSearchHitRowHtml(h, re) {
        const refLabel = `${h.bookName} ${h.chapter}:${h.verse}`;
        const a11y = `Адкрыць ${refLabel}`;
        const body = highlightScriptureWordSearchHtml(h.text, re);
        return `<button type="button" data-scripture-action="word-search-hit" data-book-id="${h.bookId}" data-chapter="${h.chapter}"
            class="w-full text-left rounded-md border border-app-stroke bg-app-elevated border-solid shadow-none hover:bg-white/[0.03] active:bg-white/[0.05] transition-colors cursor-pointer text-inherit p-0"
            aria-label="${escapeHtml(a11y)}">
            <div class="flex items-start min-h-16 py-3.5 pl-[18px] pr-3 box-border">
                <div class="flex-1 min-w-0 pr-2">
                    <div class="text-app-text text-[17px] leading-snug" style="line-height:1.35">${escapeHtml(refLabel)}</div>
                    <div class="text-app-textSec text-sm leading-snug mt-1 line-clamp-3 totus-read-18">${body}</div>
                </div>
                <span class="shrink-0 w-7 h-7 self-center flex items-center justify-center text-app-textTer" aria-hidden="true"><i class="fas fa-chevron-right text-sm"></i></span>
            </div>
        </button>`;
    }

    function renderScriptureWordSearchResultsHtml(re, hits) {
        return `<div class="flex flex-col gap-2">${hits.map((h) => scriptureWordSearchHitRowHtml(h, re)).join('')}</div>`;
    }

    function renderScriptureWordSearchShellHtml() {
        /* Як #prayer-search-screen: тая ж разметка поля, што prayersShellHtml. */
        return `
        <div class="space-y-3 pb-4 min-h-[40vh]">
            <div class="${APP_SEARCH_BAR_CLASS}">
                <i class="fas fa-search text-app-textTer text-sm shrink-0" aria-hidden="true"></i>
                <input type="search" id="scripture-word-search-input" autocomplete="off" placeholder="Слова для пошуку…"
                    class="${APP_SEARCH_INPUT_CLASS}" />
            </div>
            <p id="scripture-word-search-status" class="text-sm text-app-textSec leading-snug px-0.5"></p>
            <div id="scripture-word-search-results" class="space-y-0"></div>
            <p id="scripture-word-search-hint" class="text-sm text-app-textSec leading-snug px-0.5" role="note">Пошук ідзе па бягучым перакладзе.</p>
        </div>`;
    }

    function initScriptureWordSearchPanel(root) {
        const input = root.querySelector('#scripture-word-search-input');
        const statusEl = root.querySelector('#scripture-word-search-status');
        const resultsEl = root.querySelector('#scripture-word-search-results');
        const hintEl = root.querySelector('#scripture-word-search-hint');
        if (!input || !statusEl || !resultsEl) return;

        const setSearchHintVisible = (visible) => {
            if (!hintEl) return;
            hintEl.classList.toggle('hidden', !visible);
        };

        const run = async () => {
            if (currentView !== 'scripture' || scPanel !== 'word_search') return;
            const myRun = ++scriptureWordSearchRunId;
            const q = input.value;
            scriptureWordSearchQuery = q;
            const trimmed = q.trim();

            if (!trimmed) {
                if (myRun !== scriptureWordSearchRunId) return;
                statusEl.textContent = '';
                statusEl.classList.add('hidden');
                resultsEl.innerHTML = '';
                setSearchHintVisible(true);
                return;
            }

            setSearchHintVisible(false);
            const reProbe = buildScriptureWholeWordRegex(trimmed);
            if (!reProbe) {
                if (myRun !== scriptureWordSearchRunId) return;
                statusEl.textContent = 'Пошук у гэтым браўзеры недаступны (памылка шаблону).';
                statusEl.classList.remove('hidden');
                resultsEl.innerHTML = '';
                return;
            }

            statusEl.classList.add('hidden');
            resultsEl.innerHTML = `<div class="flex justify-center py-8 text-app-textTer" aria-hidden="true"><i class="fas fa-circle-notch fa-spin text-2xl"></i></div>`;

            await ensureScriptureDataLoaded();
            if (myRun !== scriptureWordSearchRunId) return;
            if (scriptureData?.error) {
                statusEl.classList.remove('hidden');
                statusEl.textContent = '';
                resultsEl.innerHTML = `<p class="text-app-error text-sm px-0.5">${escapeHtml(scriptureData.error)}</p>`;
                return;
            }

            const { re, hits, totalOccurrences, versesWithMatches } = await scriptureWordSearchCollect(trimmed);
            if (myRun !== scriptureWordSearchRunId) return;

            if (versesWithMatches === 0) {
                statusEl.textContent = 'Нічога не знойдзена. Паспрабуйце іншае слова.';
                statusEl.classList.remove('hidden');
                resultsEl.innerHTML = '';
            } else {
                const displayWord = trimmed;
                statusEl.textContent = `Слова «${displayWord}» сустракаецца ${totalOccurrences} раз у ${versesWithMatches} вершах.`;
                statusEl.classList.remove('hidden');
                resultsEl.innerHTML = renderScriptureWordSearchResultsHtml(re, hits);
            }
        };

        input.value = scriptureWordSearchQuery;

        input.addEventListener('input', () => {
            setSearchHintVisible(input.value.trim() === '');
            if (scriptureWordSearchDebounceTimer) clearTimeout(scriptureWordSearchDebounceTimer);
            const mySchedule = ++scriptureWordSearchScheduleGen;
            scriptureWordSearchDebounceTimer = setTimeout(() => {
                scriptureWordSearchDebounceTimer = null;
                if (mySchedule !== scriptureWordSearchScheduleGen) return;
                void run();
            }, SCRIPTURE_WORD_SEARCH_DEBOUNCE_MS);
        });

        const trimmedInit = input.value.trim();
        if (!trimmedInit) {
            statusEl.textContent = '';
            statusEl.classList.add('hidden');
            resultsEl.innerHTML = '';
            setSearchHintVisible(true);
        } else {
            setSearchHintVisible(false);
            void run();
        }
    }

    function renderScriptureFavoritesPanelHtml() {
        const items = readScriptureFavorites();
        if (items.length === 0) {
            return `<p class="text-app-textTer text-center py-12 text-sm px-2">Пакуль няма выбраных вершаў.</p>`;
        }
        return `<div class="space-y-2 px-0.5">${items
            .map((item, idx) => {
                const meta = `${item.translationTitle} • ${item.bookTitle} ${item.chapter}:${item.verse}`;
                const body = String(item.text || '').trim();
                return `<button type="button" data-scripture-action="open-favorite-verse" data-fav-index="${idx}"
                class="w-full text-left rounded-md border border-app-stroke bg-app-elevated p-[18px] border-solid hover:bg-white/[0.04] transition-colors cursor-pointer">
                <p class="text-[13px] text-app-textSec leading-snug">${escapeHtml(meta)}</p>
                <p class="text-base text-app-text leading-relaxed mt-2 line-clamp-4">${escapeHtml(body)}</p>
            </button>`;
            })
            .join('')}</div>`;
    }

    async function renderScriptureComparePanelInto(root) {
        const hintGone = localStorage.getItem(SCRIPTURE_COMPARE_HINT_KEY) === '1';
        const trExpand = localStorage.getItem(SCRIPTURE_COMPARE_EXPAND_KEY) === '1';
        const verses = readCompareVerses();
        const selectedSet = getSelectedCompareTranslationIds();
        await ensureScriptureTranslationsList();
        const allIds = (scriptureTranslationsList || []).map((x) => String(x.id));
        const orderedTrs = SCRIPTURE_TRANSLATION_ORDER.filter((id) => allIds.includes(id));
        const extra = allIds.filter((id) => !SCRIPTURE_TRANSLATION_ORDER.includes(id));
        const orderedFull = [...orderedTrs, ...extra];

        let hintHtml = '';
        if (!hintGone) {
            hintHtml = `<div class="relative mb-3 rounded-md border border-app-stroke bg-app-bg2 pl-3 pr-10 py-3 text-sm text-app-textSec leading-relaxed">
                <button type="button" class="absolute top-2 right-2 flex h-8 w-8 items-center justify-center rounded-lg border-0 bg-transparent text-app-textSec cursor-pointer hover:bg-white/10 hover:text-app-text" data-action="scripture-compare-hide-hint" aria-label="Схаваць паведамленне"><i class="fas fa-xmark text-base" aria-hidden="true"></i></button>
                <p class="pr-1 m-0">Націсніце значок параўнання каля верша ў тэксце главы. Доўгі націск у спісе — выдаліць верш са параўнання.</p>
            </div>`;
        }

        const checksHtml = orderedFull
            .map((id) => {
                const row = (scriptureTranslationsList || []).find((x) => String(x.id) === id);
                const lab = row?.title || id;
                const short = SCRIPTURE_TR_SHORT[id] || id;
                const ck = selectedSet.has(id) ? ' checked' : '';
                return `<label class="flex items-center gap-2 py-1 cursor-pointer text-sm text-app-text"><input type="checkbox" class="rounded border-app-stroke" data-compare-tr-check="${escapeHtml(id)}"${ck}/><span>${escapeHtml(short)} — ${escapeHtml(lab)}</span></label>`;
            })
            .join('');

        const trSectionHidden = trExpand ? '' : 'hidden';
        const chevRot = trExpand ? 'rotate-90' : '';

        let cardsHtml = '';
        if (verses.length === 0) {
            cardsHtml = `<p class="text-app-textTer text-center py-8 text-sm">Пакуль няма вершаў для параўнання.</p>`;
        } else {
            for (const ref of verses) {
                const refLabel = `${ref.bookTitle} ${ref.chapter}:${ref.verse}`;
                let linesHtml = '';
                for (const trId of orderedFull) {
                    if (!selectedSet.has(String(trId))) continue;
                    const { text, kind } = await scriptureGetVerseText(trId, ref.bookId, ref.chapter, ref.verse);
                    const short = SCRIPTURE_TR_SHORT[trId] || String(trId).slice(0, 6);
                    let body = text;
                    if (!body) {
                        body =
                            kind === 'bcat_nt'
                                ? 'У BCAT няма гэтай кнігі: пераклад змяшчае толькі Новы Запавет.'
                                : 'Тэкст у гэтым перакладзе не знойдзены (глава або верш).';
                    }
                    linesHtml += `<p class="text-[15px] text-app-textSec leading-relaxed pt-2 first:pt-0"><span class="font-semibold text-app-text">${escapeHtml(short)}:</span> ${escapeHtml(body)}</p>`;
                }
                cardsHtml += `<div class="rounded-md border border-app-stroke bg-app-elevated p-4">
                    <div class="flex justify-between items-center gap-3">
                        <button type="button" class="text-left flex-1 min-w-0 text-base font-semibold leading-tight text-app-text scripture-compare-card-open cursor-pointer border-0 bg-transparent p-0" data-scripture-action="compare-open-chapter" data-book-id="${Number(ref.bookId)}" data-chapter="${Number(ref.chapter)}">${escapeHtml(refLabel)}</button>
                        <button type="button" class="shrink-0 ${TOOLBAR_ICON_BTN}" data-scripture-action="compare-remove-ref" data-book-id="${Number(ref.bookId)}" data-chapter="${Number(ref.chapter)}" data-verse="${Number(ref.verse)}" aria-label="Выдаліць з параўнання"><i class="fas fa-xmark text-lg" aria-hidden="true"></i></button>
                    </div>
                    ${linesHtml}
                </div>`;
            }
        }

        root.innerHTML = `${hintHtml}
        <div class="space-y-2">
            <div class="rounded-md border border-app-stroke bg-app-elevated overflow-hidden">
                <button type="button" class="w-full flex items-center gap-2 px-4 py-3 text-left text-sm font-medium text-app-text border-0 bg-transparent hover:bg-white/5 cursor-pointer" data-action="scripture-compare-toggle-tr-section" aria-expanded="${trExpand ? 'true' : 'false'}">
                    <i class="fas fa-chevron-right transition-transform ${chevRot}" aria-hidden="true"></i>
                    Якія пераклады параўноўваць
                </button>
                <div class="${trSectionHidden} px-4 pb-3 border-t border-app-stroke/40">${checksHtml}</div>
            </div>
            <div class="space-y-2">${cardsHtml}</div>
        </div>
        `;
    }

    const scriptureJsonCache = new Map();
    let totusToastTimer = null;

    function totusToast(msg) {
        let t = document.getElementById('totus-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'totus-toast';
            t.className =
                'fixed left-1/2 bottom-20 z-[200] -translate-x-1/2 max-w-[min(92vw,360px)] rounded-lg border border-app-stroke bg-app-elevated px-4 py-2.5 text-sm text-app-text shadow-lg shadow-black/40 pointer-events-none hidden';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.classList.remove('hidden');
        if (totusToastTimer) clearTimeout(totusToastTimer);
        totusToastTimer = setTimeout(() => {
            t.classList.add('hidden');
        }, 2200);
    }

    function readScriptureFavorites() {
        try {
            const raw = localStorage.getItem(SCRIPTURE_FAVORITES_KEY);
            const arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch {
            return [];
        }
    }

    function writeScriptureFavorites(items) {
        try {
            localStorage.setItem(SCRIPTURE_FAVORITES_KEY, JSON.stringify(items));
        } catch {
            /* ignore */
        }
    }

    function favoriteVerseKey(f) {
        return `${f.translationId}|${f.bookId}|${f.chapter}|${f.verse}`;
    }

    function toggleScriptureFavorite(f) {
        const list = readScriptureFavorites();
        const k = favoriteVerseKey(f);
        const idx = list.findIndex((x) => favoriteVerseKey(x) === k);
        if (idx >= 0) {
            list.splice(idx, 1);
            writeScriptureFavorites(list);
            return false;
        }
        list.unshift(f);
        writeScriptureFavorites(list);
        return true;
    }

    function isScriptureFavorite(f) {
        const k = favoriteVerseKey(f);
        return readScriptureFavorites().some((x) => favoriteVerseKey(x) === k);
    }

    function readCompareVerses() {
        try {
            const raw = localStorage.getItem(SCRIPTURE_COMPARE_VERSES_KEY);
            const arr = raw ? JSON.parse(raw) : [];
            if (!Array.isArray(arr)) return [];
            return arr.filter((o) => o && Number(o.bookId) >= 0 && String(o.bookTitle || '').trim() !== '');
        } catch {
            return [];
        }
    }

    function writeCompareVerses(verses) {
        try {
            localStorage.setItem(SCRIPTURE_COMPARE_VERSES_KEY, JSON.stringify(verses));
        } catch {
            /* ignore */
        }
    }

    function compareVerseKey(ref) {
        return `${ref.bookId}|${ref.chapter}|${ref.verse}`;
    }

    function toggleCompareVerse(ref) {
        const list = readCompareVerses();
        const k = compareVerseKey(ref);
        const idx = list.findIndex((x) => compareVerseKey(x) === k);
        if (idx >= 0) {
            list.splice(idx, 1);
            writeCompareVerses(list);
            return false;
        }
        list.push(ref);
        writeCompareVerses(list);
        return true;
    }

    function isCompareVerse(ref) {
        return readCompareVerses().some((x) => compareVerseKey(x) === compareVerseKey(ref));
    }

    function readCompareTranslationIdsStored() {
        try {
            const raw = localStorage.getItem(SCRIPTURE_COMPARE_TRS_KEY);
            if (!raw) return null;
            const arr = JSON.parse(raw);
            if (!Array.isArray(arr)) return null;
            const s = new Set(arr.map(String).filter(Boolean));
            return s.size ? s : null;
        } catch {
            return null;
        }
    }

    function writeCompareTranslationIds(ids) {
        try {
            localStorage.setItem(SCRIPTURE_COMPARE_TRS_KEY, JSON.stringify([...ids]));
        } catch {
            /* ignore */
        }
    }

    function allCompareTranslationIdsDefault() {
        const list = scriptureTranslationsList || [];
        if (list.length) return new Set(list.map((x) => String(x.id)));
        return new Set(SCRIPTURE_TRANSLATION_ORDER);
    }

    function getSelectedCompareTranslationIds() {
        const stored = readCompareTranslationIdsStored();
        const all = allCompareTranslationIdsDefault();
        if (!stored) return all;
        const inter = new Set([...stored].filter((id) => all.has(String(id))));
        return inter.size ? inter : all;
    }

    async function scriptureGetVerseText(trId, bookId, chapter, verseNum) {
        const data = await loadBundledScriptureJson(String(trId));
        if (!data || !data.books) return { text: '', kind: 'missing' };
        const bk = data.books.find((b) => Number(b.book_id) === Number(bookId));
        if (!bk) {
            const kind = String(trId) === 'catholic_nt' && Number(bookId) < 40 ? 'bcat_nt' : 'missing';
            return { text: '', kind };
        }
        const chObj = (bk.chapters || []).find((c) => Number(c.chapter) === Number(chapter));
        if (!chObj) return { text: '', kind: 'missing' };
        const vs = (chObj.verses || []).find((x) => Number(x.verse) === Number(verseNum));
        if (!vs || !String(vs.text || '').trim()) return { text: '', kind: 'missing' };
        return { text: String(vs.text).trim(), kind: 'ok' };
    }

    const scriptureJsonInflight = new Map();

    async function loadBundledScriptureJson(translationId) {
        const id = String(translationId);
        if (scriptureJsonCache.has(id)) {
            return scriptureJsonCache.get(id);
        }
        let inflight = scriptureJsonInflight.get(id);
        if (inflight) return inflight;

        inflight = (async () => {
            try {
                const url = totusAssetUrl(`${SCRIPTURE_BUNDLE_BASE}/${id}.json`);
                const r = await fetch(url);
                if (!r.ok) {
                    scriptureJsonCache.set(id, null);
                    return null;
                }
                const data = await r.json();
                if (!data || !Array.isArray(data.books)) {
                    scriptureJsonCache.set(id, null);
                    return null;
                }
                scriptureJsonCache.set(id, data);
                return data;
            } catch {
                scriptureJsonCache.set(id, null);
                return null;
            } finally {
                scriptureJsonInflight.delete(id);
            }
        })();

        scriptureJsonInflight.set(id, inflight);
        return inflight;
    }

    async function loadScriptureBundledIdsOnce() {
        if (scriptureBundledIds) return;
        if (!scriptureBundledPromise) {
            scriptureBundledPromise = (async () => {
                const fallback = [SCRIPTURE_DEFAULT_TR];
                try {
                    const url = totusAssetUrl(`${SCRIPTURE_BUNDLE_BASE}/bundled_translations.json`);
                    const r = await fetch(url);
                    if (r.ok) {
                        const arr = await r.json();
                        if (Array.isArray(arr) && arr.length > 0) {
                            scriptureBundledIds = arr.map((x) => String(x)).filter(Boolean);
                            return;
                        }
                    }
                } catch {
                    /* file:// / сетка */
                }
                scriptureBundledIds = fallback;
            })();
        }
        await scriptureBundledPromise;
    }

    /** Усе пераклады з каталога ў парадку ScriptureCatalog (Android); убудаваныя JSON могуць быць толькі для часткі id. */
    async function ensureScriptureTranslationsList() {
        await loadScriptureCatalogOnce();
        await loadScriptureBundledIdsOnce();
        if (scriptureTranslationsList === null) {
            const list = [];
            const seen = new Set();
            for (const id of SCRIPTURE_TRANSLATION_ORDER) {
                const row = scriptureCatalogById?.get(id);
                if (row) {
                    list.push({ id: row.id, title: row.title });
                    seen.add(id);
                }
            }
            if (scriptureCatalogById) {
                for (const id of scriptureCatalogById.keys()) {
                    if (!seen.has(id)) {
                        const row = scriptureCatalogById.get(id);
                        list.push({ id: row.id, title: row.title });
                    }
                }
            }
            scriptureTranslationsList = list;
        }
    }

    async function ensureScriptureDataLoaded() {
        if (!scriptureSelectedId) return;
        const tr = String(scriptureSelectedId);
        const hasValid =
            scriptureData &&
            scriptureData._tr === tr &&
            !scriptureData.error &&
            (scriptureData.books?.length || 0) > 0;
        if (hasValid) return;

        const bundled = await loadBundledScriptureJson(tr);
        if (bundled && bundled.books?.length) {
            scriptureData = { ...bundled, _tr: tr };
            return;
        }
        scriptureData = {
            _tr: tr,
            error:
                'Файл assets/scripture/' +
                tr +
                '.json не знойдзены або пашкоджаны. Дадайце JSON перакладу і запіс у bundled_translations.json.',
            books: [],
        };
    }

    async function hydrateScriptureView() {
        const root = document.getElementById('scripture-root');
        if (!root) return;
        try {
        root.innerHTML = `<div class="flex flex-col items-center justify-center py-12 gap-2 text-app-textTer"><i class="fas fa-circle-notch fa-spin text-3xl" aria-hidden="true"></i><span class="text-sm">Загрузка…</span></div>`;

        await ensureScriptureTranslationsList();
        let trList = scriptureTranslationsList || [];

        if (scriptureSelectedId && !trList.some((x) => String(x.id) === String(scriptureSelectedId))) {
            scriptureSelectedId = null;
            scriptureData = null;
            try {
                localStorage.removeItem(SCRIPTURE_TR_KEY);
            } catch {
                /* ignore */
            }
        }

        const saved = localStorage.getItem(SCRIPTURE_TR_KEY);
        if (!scriptureSelectedId && saved && trList.some((x) => String(x.id) === String(saved))) {
            scriptureSelectedId = saved;
        }

        if (trList.length === 0) {
            root.innerHTML = `<div class="p-4 rounded-md border border-app-stroke bg-app-elevated text-sm text-app-textSec leading-relaxed">
                <p>Няма каталога перакладаў. Дадайце файл <code class="bg-black/30 px-1 rounded text-xs">assets/scripture/scripture_catalog.json</code>.</p>
            </div>`;
            return;
        }

        const showTranslationPicker = !scriptureSelectedId || scTranslationSettingsOpen;

        if (showTranslationPicker) {
            let presetId = scriptureSelectedId
                ? String(scriptureSelectedId)
                : saved && trList.some((x) => String(x.id) === String(saved))
                  ? String(saved)
                  : SCRIPTURE_DEFAULT_TR;
            if (!trList.some((x) => String(x.id) === String(presetId))) {
                presetId = String(trList[0].id);
            }
            const opts = trList
                .map((x) => {
                    const id = String(x.id);
                    const sel = id === presetId ? ' selected' : '';
                    return `<option value="${escapeHtml(id)}"${sel}>${escapeHtml(x.title || id)}</option>`;
                })
                .join('');
            const presetRow = trList.find((x) => String(x.id) === String(presetId));
            const presetTitleEsc = escapeHtml((presetRow && presetRow.title) || presetId);
            const optionRows = trList
                .map((x) => {
                    const id = String(x.id);
                    const picked = id === presetId;
                    return `<button type="button" role="option" data-scripture-tr-option="${escapeHtml(id)}" aria-selected="${picked ? 'true' : 'false'}"
            class="scripture-tr-option-btn w-full text-left px-4 py-3 text-sm border-0 cursor-pointer transition-colors border-solid ${picked ? 'bg-app-surface text-app-text font-medium' : 'bg-transparent text-app-text hover:bg-white/[0.06]'}">
            <span class="scripture-tr-option-title leading-snug">${escapeHtml(x.title || id)}</span>
        </button>`;
                })
                .join('');
            const descText = escapeHtml(scriptureCatalogDescription(presetId));
            root.innerHTML = `
        <div class="rounded-md border border-app-stroke bg-app-elevated p-[18px] flex flex-col gap-3 min-h-0 min-w-0 h-[calc(100dvh-7rem)] max-h-[calc(100dvh-7rem)]">
            <div class="shrink-0">
                <label id="scripture-tr-label" class="block text-sm text-app-textSec leading-snug">Пераклад Бібліі</label>
                <div class="scripture-tr-custom relative mt-1.5">
                    <select id="scripture-tr-select" class="sr-only" aria-labelledby="scripture-tr-label" tabindex="-1">${opts}</select>
                    <button type="button" id="scripture-tr-trigger" aria-labelledby="scripture-tr-label" aria-haspopup="listbox" aria-expanded="false"
                        class="w-full rounded-xl border border-solid border-app-stroke bg-app-bg2 px-3 py-2.5 min-h-[52px] flex items-stretch gap-2 text-left hover:bg-white/[0.04] transition-colors focus:outline-none">
                        <span id="scripture-tr-trigger-label" class="flex-1 min-w-0 self-center truncate text-sm text-app-text font-medium">${presetTitleEsc}</span>
                        <span class="scripture-tr-chevron-wrap shrink-0 w-11 h-11 flex items-center justify-center self-center rounded-lg bg-app-surface border border-app-stroke/80 text-app-textSec" aria-hidden="true"><i class="fas fa-chevron-down text-sm"></i></span>
                    </button>
                    <div id="scripture-tr-panel" class="hidden absolute left-0 right-0 top-[calc(100%+8px)] z-[100] rounded-xl border border-solid border-app-stroke bg-app-elevated py-2 shadow-2xl shadow-black/40 max-h-[min(50vh,340px)] overflow-y-auto divide-y divide-app-stroke/40" role="listbox" aria-labelledby="scripture-tr-label">
                        ${optionRows}
                    </div>
                </div>
            </div>
            <div class="flex flex-col flex-1 min-h-0 gap-1.5">
                <p class="text-xs text-app-textTer leading-snug shrink-0">Апісанне перакладу</p>
                <div class="flex-1 min-h-0 rounded-md border border-app-stroke bg-app-bg2 overflow-y-auto p-4">
                    <p id="scripture-tr-description" class="text-[15px] leading-relaxed text-app-textSec whitespace-pre-wrap">${descText}</p>
                </div>
            </div>
            <button type="button" data-scripture-action="apply-tr" class="shrink-0 w-full px-4 py-2.5 rounded-md border border-app-stroke bg-transparent text-app-text text-sm font-medium hover:bg-white/5 cursor-pointer border-solid">Захаваць і вярнуцца</button>
        </div>`;
            return;
        }

        if (scPanel === 'word_search') {
            if (!scriptureSelectedId) {
                scPanel = null;
                await hydrateScriptureView();
                return;
            }
            await ensureScriptureDataLoaded();
            if (scriptureData && scriptureData.error) {
                root.innerHTML = `<div class="p-4 space-y-3">
                <div class="bg-red-950/40 border border-red-500/30 text-app-error rounded-md text-sm p-4">${escapeHtml(scriptureData.error)}</div>
                <p class="text-sm text-app-textSec leading-relaxed">Іншы пераклад можна абраць праз <span class="text-app-text">шасцярэнку</span> ў шапцы.</p>
            </div>`;
                return;
            }
            root.innerHTML = renderScriptureWordSearchShellHtml();
            initScriptureWordSearchPanel(root);
            return;
        }

        if (scPanel === 'favorites') {
            root.innerHTML = renderScriptureFavoritesPanelHtml();
            return;
        }
        if (scPanel === 'compare') {
            root.innerHTML = `<div class="flex flex-col items-center justify-center py-12 gap-2 text-app-textTer"><i class="fas fa-circle-notch fa-spin text-3xl" aria-hidden="true"></i><span class="text-sm">Загрузка…</span></div>`;
            await renderScriptureComparePanelInto(root);
            return;
        }

        await ensureScriptureDataLoaded();
        if (scriptureData && scriptureData.error) {
            root.innerHTML = `<div class="p-4 space-y-3">
                <div class="bg-red-950/40 border border-red-500/30 text-app-error rounded-md text-sm p-4">${escapeHtml(scriptureData.error)}</div>
                <p class="text-sm text-app-textSec leading-relaxed">Іншы пераклад можна абраць праз <span class="text-app-text">шасцярэнку</span> ў шапцы.</p>
            </div>`;
            return;
        }
        const books = (scriptureData && scriptureData.books) || [];
        if (books.length === 0) {
            root.innerHTML = `<p class="text-app-textTer text-center py-12 text-sm">Няма кніг у гэтым перакладзе.</p>`;
            return;
        }

        if (scBookIdx === null || scBookIdx < 0 || scBookIdx >= books.length) {
            root.innerHTML = scriptureBooksListHtml(books, scriptureSelectedId);
            return;
        }

        const bk = books[scBookIdx];
        const chapters = bk.chapters || [];

        if (scChapterNum === null) {
            let html = '<div class="grid grid-cols-4 sm:grid-cols-6 gap-2">';
            for (const ch of chapters) {
                html += `<button type="button" data-scripture-action="open-chapter" data-chapter-num="${ch.chapter}"
                    class="rounded-lg border border-app-stroke bg-app-bg2 py-2.5 text-center text-sm font-medium text-app-text hover:bg-white/10 cursor-pointer border-solid">${ch.chapter}</button>`;
            }
            html += '</div>';
            root.innerHTML = html;
            return;
        }

        const chObj = chapters.find((c) => Number(c.chapter) === Number(scChapterNum));
        let html = '';
        if (!chObj) {
            html += `<p class="text-app-textTer text-sm">Раздзел не знойдзены.</p>`;
        } else {
            const trTitle =
                (scriptureTranslationsList || []).find((x) => String(x.id) === String(scriptureSelectedId))
                    ?.title || '';
            html += `<div class="rounded-md border border-app-stroke bg-app-elevated p-4 space-y-2 leading-relaxed">`;
            for (const v of chObj.verses || []) {
                const vn = Number(v.verse);
                const favObj = {
                    translationId: String(scriptureSelectedId),
                    translationTitle: trTitle,
                    bookId: bk.book_id,
                    bookTitle: bk.book_name,
                    chapter: Number(scChapterNum),
                    verse: vn,
                    text: v.text,
                };
                const cmpObj = {
                    bookId: bk.book_id,
                    bookTitle: bk.book_name,
                    chapter: Number(scChapterNum),
                    verse: vn,
                };
                const favOn = isScriptureFavorite(favObj);
                const cmpOn = isCompareVerse(cmpObj);
                const favI = favOn ? 'fas fa-bookmark text-amber-400' : 'far fa-bookmark';
                const cmpBtnCls = cmpOn ? `${TOOLBAR_ICON_BTN} text-app-text` : `${TOOLBAR_ICON_BTN} text-app-textTer opacity-70`;
                const hi =
                    scFocusVerse != null && Number(scFocusVerse) === vn
                        ? ' ring-2 ring-violet-500/50 border-violet-400/40'
                        : ' border-app-stroke';
                html += `<div class="rounded-md border bg-app-bg2/80 p-3 flex gap-2 items-stretch${hi}" data-verse-focus-anchor="${vn}">
                    <div class="flex-1 min-w-0 totus-read-18 leading-relaxed"><span class="text-app-textTer font-medium mr-1">${vn}</span>${escapeHtml(v.text)}</div>
                    <div class="totus-scripture-verse-actions">
                        <button type="button" data-scripture-action="verse-fav-toggle" class="${TOOLBAR_ICON_BTN}" aria-label="У выбранае" data-v-verse="${vn}"><i class="${favI} text-base" aria-hidden="true"></i></button>
                        <button type="button" data-scripture-action="verse-compare-toggle" class="${cmpBtnCls}" aria-label="Параўнанне" data-v-verse="${vn}">${scriptureCompareIconSvg(cmpOn, 'w-[1.725rem] h-[1.725rem] block mx-auto')}</button>
                    </div>
                </div>`;
            }
            html += '</div>';
        }
        root.innerHTML = html;
        if (scFocusVerse != null && chObj) {
            const anchor = Number(scFocusVerse);
            requestAnimationFrame(() => {
                const el = root.querySelector(`[data-verse-focus-anchor="${anchor}"]`);
                el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        }
        } finally {
            syncScriptureToolbarTitle();
            refreshToolbarRightActions();
        }
    }

    function renderApp() {
        let content = '';
        if (currentView === 'home') {
            content = renderHome();
        } else if (currentView === 'calendar') {
            content = calendarShellHtml();
        } else if (currentView === 'day') {
            content = `
            <div id="day-detail" class="min-h-[50vh]">
                <div class="flex flex-col items-center justify-center py-24 gap-3 text-app-textTer">
                    <i class="fas fa-circle-notch fa-spin text-3xl text-app-textSec"></i>
                    <span class="text-sm">Загрузка дня…</span>
                </div>
            </div>`;
        } else if (currentView === 'prayers') {
            content = prayersShellHtml();
        } else if (isSongbookLikeView()) {
            content = songbookShellHtml();
        } else if (currentView === 'scripture') {
            content = scriptureShellHtml();
        } else if (currentView === 'ordo-missae') {
            content = ordoMissaeShellHtml();
        } else if (currentView === 'solemnities') {
            content = solemnitiesShellHtml();
        } else if (currentView === 'settings') {
            content = settingsShellHtml();
        } else if (currentView === 'about') {
            content = aboutShellHtml();
        } else if (currentView === 'calendar-settings') {
            content = calendarSettingsShellHtml();
        }

        app.innerHTML = `
        <div class="flex flex-1 flex-col min-h-0 h-full max-h-full overflow-hidden">
            ${renderChrome()}
            ${ordoMissaeSearchChromeHtml()}
            <main class="app-main-scroll flex-1 w-full min-h-0">${content}</main>
        </div>`;

        if (currentView === 'calendar') {
            bindCalendarSwipeNavigation();
            hydrateCalendar();
        } else if (currentView === 'home') {
            void prefetchCalendarMonthInBackground(currentDate.year, currentDate.month);
        } else if (currentView === 'prayers') {
            hydratePrayers();
        } else if (isSongbookLikeView()) {
            hydrateSongbook();
        } else if (currentView === 'scripture') {
            hydrateScriptureView();
        } else if (currentView === 'ordo-missae') {
            void hydrateOrdoMissae();
        } else if (currentView === 'solemnities' && !solemnitiesSettingsOpen) {
            void hydrateSolemnities();
        }
        scheduleToolbarTitleFit();
        syncReadingTextToolbarButtons();
    }

    bindDelegatedEvents();

    function boot() {
        if (typeof luxon === 'undefined' || !luxon.DateTime) {
            app.innerHTML =
                '<div class="h-full min-h-0 overflow-y-auto overscroll-y-contain"><div class="p-8 max-w-lg mx-auto text-center text-red-300 text-sm">Не загрузілася бібліятэка дат Luxon (патрэбна падлучэнне да інтэрнэту для загрузкі з сервера). Паспрабуйце абнавіць старонку.</div></div>';
            return;
        }
        if (window.location.protocol === 'file:') {
            app.innerHTML = `
            <div class="h-full min-h-0 overflow-y-auto overscroll-y-contain flex items-center justify-center p-6 bg-amber-50">
              <div class="max-w-xl bg-white border border-amber-200 rounded-2xl p-8 shadow-lg text-stone-800 text-sm leading-relaxed">
                <h2 class="text-lg font-bold text-amber-900 mb-3">Гэты сайт не працуе пры адкрыцці файла з дыска</h2>
                <p class="mb-4">Браўзер блакіруе зварот да API пры пратаколе <code class="bg-stone-100 px-1 rounded">file://</code>. Запусціце лакальны сервер з PHP.</p>
                <p class="mb-2 font-medium text-stone-700">У тэрмінале, у каталозе праекта (напрыклад AndroidStudioProjects):</p>
                <pre class="bg-stone-900 text-green-400 text-xs p-4 rounded-xl overflow-x-auto text-left mb-4">php -S localhost:8080</pre>
                <p class="mb-4">Затым адкрыйце: <code class="bg-stone-100 px-1 rounded break-all">http://localhost:8080/WebApp/index.html</code></p>
                <p class="text-stone-600 text-xs">На хасцінгу загрузіце папкі WebApp і WebPanel на адзін дамен і адкрывайце старонку праз HTTPS.</p>
              </div>
            </div>`;
            return;
        }
        applyFontFamily();
        applyAppTheme();
        applyTextStep(readTextStep());
        renderApp();
        if (!toolbarTitleFitResizeBound) {
            toolbarTitleFitResizeBound = true;
            window.addEventListener('resize', scheduleToolbarTitleFit, { passive: true });
        }
        totusHistoryInstall();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

