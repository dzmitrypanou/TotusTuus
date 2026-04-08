<?php
/**
 * Подвал публичного сайта. Закрывает .container.
 */
if (!isset($siteVersion)) {
    $_svRaw = @file_get_contents(__DIR__ . '/../config/version.json');
    $_svData = $_svRaw ? json_decode($_svRaw, true) : null;
    $siteVersion = (is_array($_svData) && !empty($_svData['version'])) ? $_svData['version'] : '3.4.4';
}

$absLang = 'ru';
try {
    require_once __DIR__ . '/lang.php';
    $absLang = abs_detect_lang();
} catch (Throwable $e) {
    $absLang = 'ru';
}

$siteFooterMenuItems = [];
try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/ensure_site_menu.php';
    $_footerMenuDb = Database::getInstance();
    ensure_site_menu_table($_footerMenuDb);
    $siteFooterMenuItems = $_footerMenuDb->fetchAll(
        "SELECT label, label_en, href FROM cms_site_menu WHERE is_enabled = 1 AND placement = 'footer' ORDER BY sort_order ASC, id ASC"
    );
} catch (Throwable $e) {
    $siteFooterMenuItems = [];
}
?>
        <div class="page-bottom-spacer" aria-hidden="true"></div>
        <footer>
            <div class="footer-axis" aria-hidden="true">
                <div class="footer-axis-track"></div>
                <div class="footer-axis-ticks">
                    <span class="footer-axis-tick">0</span>
                    <span class="footer-axis-tick">1</span>
                    <span class="footer-axis-tick">2</span>
                    <span class="footer-axis-tick">3</span>
                    <span class="footer-axis-tick">4</span>
                    <span class="footer-axis-tick">5</span>
                    <span class="footer-axis-tick">6</span>
                    <span class="footer-axis-tick">7</span>
                    <span class="footer-axis-tick">8</span>
                    <span class="footer-axis-tick">9</span>
                    <span class="footer-axis-tick">10</span>
                    <span class="footer-axis-tick">11</span>
                    <span class="footer-axis-tick">12</span>
                </div>
            </div>
            <div class="site-footer-top">
                <?php if (!empty($siteFooterMenuItems)): ?>
                <nav class="site-footer-nav" aria-label="<?php echo $absLang === 'en' ? 'Footer links' : 'Ссылки в подвале'; ?>">
                    <?php foreach ($siteFooterMenuItems as $item):
                        $itemBaseHref = site_menu_normalize_href($item['href'] ?? '');
                        $itemHref = $itemBaseHref;
                        $itemExternal = preg_match('#^https?://#i', $itemBaseHref) === 1;
                        $itemHrefRu = $itemBaseHref;
                        $itemHrefEn = $itemBaseHref;
                        if (!$itemExternal && is_string($itemBaseHref) && strpos($itemBaseHref, '/') === 0) {
                            if ($itemBaseHref === '/') {
                                $itemHrefEn = '/en';
                            } elseif (strpos($itemBaseHref, '/en/') === 0 || $itemBaseHref === '/en') {
                                $itemHrefEn = $itemBaseHref;
                                $itemHrefRu = $itemBaseHref === '/en' ? '/' : substr($itemBaseHref, 3);
                                if ($itemHrefRu === '') {
                                    $itemHrefRu = '/';
                                }
                            } else {
                                $itemHrefEn = '/en' . $itemBaseHref;
                            }
                            $itemHref = $absLang === 'en' ? $itemHrefEn : $itemHrefRu;
                        }
                        $itemLabel = $absLang === 'en'
                            ? (!empty($item['label_en']) ? (string) $item['label_en'] : (string) ($item['label'] ?? ''))
                            : (string) ($item['label'] ?? '');
                        $itemLabelRu = (string) ($item['label'] ?? '');
                        $itemLabelEn = !empty($item['label_en']) ? (string) $item['label_en'] : $itemLabelRu;
                    ?>
                    <a
                        href="<?php echo htmlspecialchars($itemHref, ENT_QUOTES, 'UTF-8'); ?>"
                        class="site-footer-nav-link"
                        data-base-href="<?php echo htmlspecialchars($itemBaseHref, ENT_QUOTES, 'UTF-8'); ?>"
                        data-href-ru="<?php echo htmlspecialchars($itemHrefRu, ENT_QUOTES, 'UTF-8'); ?>"
                        data-href-en="<?php echo htmlspecialchars($itemHrefEn, ENT_QUOTES, 'UTF-8'); ?>"
                        data-label-ru="<?php echo htmlspecialchars($itemLabelRu, ENT_QUOTES, 'UTF-8'); ?>"
                        data-label-en="<?php echo htmlspecialchars($itemLabelEn, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $itemExternal ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
                    ><?php echo htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>
                <div class="site-lang-switch site-lang-switch-footer" aria-label="Language switch">
                    <a class="site-lang-link<?php echo $absLang === 'ru' ? ' is-active' : ''; ?>" data-lang="ru" href="<?php echo htmlspecialchars('/', ENT_QUOTES, 'UTF-8'); ?>" aria-label="Russian">
                        <span class="site-lang-flag fi fi-ru" aria-hidden="true"></span> RU
                    </a>
                    <a class="site-lang-link<?php echo $absLang === 'en' ? ' is-active' : ''; ?>" data-lang="en" href="<?php echo htmlspecialchars('/en', ENT_QUOTES, 'UTF-8'); ?>" aria-label="English">
                        <span class="site-lang-flag fi fi-us" aria-hidden="true"></span> US
                    </a>
                </div>
            </div>
            <div class="footer-links">
                <a href="https://twitch.tv/chadowfriend" target="_blank" rel="noopener noreferrer" class="social-link"><i class="fab fa-twitch"></i> Twitch</a>
                <span class="separator">•</span>
                <a href="https://t.me/chadowfriend" target="_blank" rel="noopener noreferrer" class="social-link"><i class="fab fa-telegram"></i> Telegram</a>
                <span class="separator">•</span>
                <a href="https://www.donationalerts.com/r/chadowfriend" target="_blank" rel="noopener noreferrer" class="social-link"><i class="fas fa-university"></i> Donation</a>
            </div>
            <div class="footer-text">
                Copyright (c) 2026 Analysis ABS replays <span class="version">ver. <span id="siteVersion"><?php echo htmlspecialchars($siteVersion); ?></span></span> by <span class="version">Immortal_Emperor</span>.
            </div>
            <div class="footer-text">
                I will make them and the places all around My hill a blessing; and I will cause showers to come down in their season; there shall be showers of blessing.
            </div>
            <div class="footer-text">
                Ezekiel 34:26 (NKJV)
            </div>
        </footer>
        <script src="/js/background-ambient.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
        <script src="/js/lang-switch.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    </div>
