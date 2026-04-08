(() => {
    function buildLangPath(pathname, lang) {
        const path = typeof pathname === 'string' && pathname !== '' ? pathname : '/';
        if (lang === 'en') {
            if (path === '/') return '/en';
            if (path === '/en' || path.indexOf('/en/') === 0) return path;
            return '/en' + path;
        }
        if (path === '/en') return '/';
        if (path.indexOf('/en/') === 0) {
            const ru = path.slice(3);
            return ru === '' ? '/' : ru;
        }
        return path;
    }

    function getHrefForLang(currentHref, lang) {
        if (typeof currentHref !== 'string' || currentHref === '') return currentHref;
        if (/^https?:\/\//i.test(currentHref)) return currentHref;
        if (currentHref[0] !== '/') return currentHref;
        return buildLangPath(currentHref, lang);
    }

    function updateLangLinks(lang) {
        document.querySelectorAll('.site-lang-link').forEach((link) => {
            const linkLang = link.getAttribute('data-lang');
            if (linkLang) {
                link.classList.toggle('is-active', linkLang === lang);
            }
            link.href = getHrefForLang(window.location.pathname, linkLang || lang);
        });
    }

    function updateHeaderFooterTexts(lang) {
        const isEn = lang === 'en';
        const logo = document.getElementById('siteLogoLink');
        if (logo) {
            const txt = logo.getAttribute(isEn ? 'data-text-en' : 'data-text-ru');
            const href = logo.getAttribute(isEn ? 'data-href-en' : 'data-href-ru');
            if (txt) {
                const logoText = logo.querySelector('.site-logo-text');
                if (logoText) {
                    logoText.textContent = txt;
                } else {
                    logo.textContent = txt;
                }
            }
            if (href) logo.href = href;
        }

        document.querySelectorAll('.site-header-nav a, .site-footer-nav a').forEach((a) => {
            const label = a.getAttribute(isEn ? 'data-label-en' : 'data-label-ru');
            const href = a.getAttribute(isEn ? 'data-href-en' : 'data-href-ru');
            if (label) a.textContent = label;
            if (href && !/^https?:\/\//i.test(href)) {
                a.href = href;
            }
        });
    }

    function updateIndexStaticTexts(lang) {
        const isEn = lang === 'en';
        const uploadText = document.getElementById('uploadText');
        if (uploadText) {
            uploadText.innerHTML = isEn
                ? 'Drag replay files here <span>choose files</span>'
                : 'Перетащите файлы реплеев сюда <span>выберите файлы</span>';
        }

        const uploadFormatHint = document.getElementById('uploadFormatHint');
        if (uploadFormatHint) {
            uploadFormatHint.textContent = isEn
                ? 'Formats: .mtreplay, .wotreplay. Maximum file size: 10 MB.'
                : 'Форматы: .mtreplay, .wotreplay. Максимальный размер одного файла: 10 МБ.';
        }

        const saveReplaySwitchText = document.getElementById('saveReplaySwitchText');
        if (saveReplaySwitchText) {
            saveReplaySwitchText.textContent = isEn
                ? 'Save replay copies on the server for diagnostics'
                : 'Сохранять копии реплеев на сервере для диагностики';
        }

        const saveReplayConsentHint = document.getElementById('saveReplayConsentHint');
        if (saveReplayConsentHint) {
            saveReplayConsentHint.textContent = isEn
                ? 'By default disabled. Without consent, files are analyzed only in your browser. Files are stored for 30 days. When enabled, no more than 50 replays per batch; if you select more, the upload will be cancelled.'
                : 'Без согласия файлы анализируются только в браузере. При включённой опции за один раз не более 50 реплеев; если выбрано больше, загрузка не выполняется.';
        }

        const minBattlesLabel = document.getElementById('minBattlesLabel');
        if (minBattlesLabel) {
            minBattlesLabel.textContent = isEn ? 'Min. battles' : 'Мин. боёв';
        }

        const minBattlesUp = document.getElementById('minBattlesUp');
        if (minBattlesUp) {
            minBattlesUp.title = isEn ? 'Increase' : 'Увеличить';
            minBattlesUp.setAttribute('aria-label', isEn ? 'Increase min battles' : 'Увеличить минимум боёв');
        }
        const minBattlesDown = document.getElementById('minBattlesDown');
        if (minBattlesDown) {
            minBattlesDown.title = isEn ? 'Decrease' : 'Уменьшить';
            minBattlesDown.setAttribute('aria-label', isEn ? 'Decrease min battles' : 'Уменьшить минимум боёв');
        }

        const downloadBtn = document.getElementById('downloadBtn');
        if (downloadBtn) {
            downloadBtn.title = isEn ? 'Download table as JPEG' : 'Скачать таблицу как JPEG';
            downloadBtn.innerHTML = `<i class="fas fa-download"></i> ${isEn ? 'Download statistics' : 'Скачать статистику'}`;
        }

        const resetBtn = document.getElementById('resetBtn');
        if (resetBtn) {
            resetBtn.innerHTML = `<i class="fas fa-trash-alt"></i> ${isEn ? 'Clear all data' : 'Очистить все данные'}`;
        }

        const loading = document.getElementById('loading');
        if (loading && loading.classList.contains('hidden')) {
            loading.textContent = isEn ? 'Analyzing replays...' : 'Анализ реплеев...';
        }
    }

    async function switchLanguageInPlace(lang) {
        const hasMainApp = typeof AppConstants !== 'undefined'
            && typeof API !== 'undefined'
            && typeof FiltersUI !== 'undefined'
            && typeof Renderer !== 'undefined'
            && typeof UI !== 'undefined';

        if (!hasMainApp || !document.getElementById('uploadArea')) {
            window.location.href = buildLangPath(window.location.pathname, lang) + window.location.search + window.location.hash;
            return;
        }

        if (lang !== 'ru' && lang !== 'en') return;
        if (window.ABS_LANG === lang) return;

        window.ABS_LANG = lang;
        AppConstants.LANG = lang;
        AppConstants.COLUMN_HEADERS = lang === 'en'
            ? AppConstants.COLUMN_HEADERS_EN
            : AppConstants.COLUMN_HEADERS_RU;
        API.lang = lang;

        document.documentElement.lang = lang;
        document.title = lang === 'en' ? 'ABS Replays Analysis' : 'Анализ АБС реплеев';

        const newPath = buildLangPath(window.location.pathname, lang);
        window.history.replaceState({}, '', newPath + window.location.search + window.location.hash);

        updateLangLinks(lang);
        updateHeaderFooterTexts(lang);
        updateIndexStaticTexts(lang);

        if (typeof API.relocalizeCaches === 'function') {
            API.relocalizeCaches();
        } else {
            await Promise.all([API.loadTankDictionary(), API.loadMapDictionary()]);
        }

        FiltersUI.renderFilters();
        Renderer.updateDisplay();
        UI.checkAndHideContent();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.site-lang-link[data-lang]').forEach((link) => {
            link.addEventListener('click', async (e) => {
                const lang = link.getAttribute('data-lang');
                if (lang !== 'ru' && lang !== 'en') return;
                e.preventDefault();
                await switchLanguageInPlace(lang);
            });
        });
    });
})();
