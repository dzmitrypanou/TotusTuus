<?php
/**
 * Шапка публичного сайта (общая для index.php, page.php и т.д.).
 * Перед подключением задайте при необходимости: $pageTitle, $bodyClass, $siteVersion, $extraHeadHtml
 */
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    $pubSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($pubSecure) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

if (!isset($extraHeadHtml)) {
    $extraHeadHtml = '';
}

require_once __DIR__ . '/lang.php';
$absLang = abs_detect_lang();
$htmlLang = $absLang === 'en' ? 'en' : 'ru';

if (!isset($siteVersion)) {
    $_svRaw = @file_get_contents(__DIR__ . '/../config/version.json');
    $_svData = $_svRaw ? json_decode($_svRaw, true) : null;
    $siteVersion = (is_array($_svData) && !empty($_svData['version'])) ? $_svData['version'] : '3.4.4';
}
$pageTitle = isset($pageTitle) ? $pageTitle : ($absLang === 'en' ? 'ABS Replays Analysis' : 'Анализ АБС реплеев');
$bodyClass = isset($bodyClass) ? trim((string) $bodyClass) : '';
$metaDescription = isset($metaDescription) && trim((string) $metaDescription) !== ''
    ? trim((string) $metaDescription)
    : ($absLang === 'en'
        ? 'Analyze ABS replay files and review team statistics, WGSRT indicators, and battle performance metrics.'
        : 'Анализируйте реплеи АБС и смотрите статистику команды, показатели WGSRT и метрики эффективности в боях.');
$metaRobots = isset($metaRobots) && trim((string) $metaRobots) !== ''
    ? trim((string) $metaRobots)
    : 'index,follow';
$siteName = $absLang === 'en' ? 'ABS Replays Analysis' : 'Анализ АБС реплеев';
$siteSlugCurrent = abs_extract_slug_from_request();
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $forwardedProto = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
    if ($forwardedProto === 'https' || $forwardedProto === 'http') {
        $requestScheme = $forwardedProto;
    }
}
$requestHost = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
    ? trim($_SERVER['HTTP_HOST'])
    : '';
$absCanonicalRuPath = abs_build_lang_href('ru', $siteSlugCurrent);
$absCanonicalEnPath = abs_build_lang_href('en', $siteSlugCurrent);
$canonicalUrl = isset($canonicalUrl) && trim((string) $canonicalUrl) !== ''
    ? trim((string) $canonicalUrl)
    : ($requestHost !== '' ? ($requestScheme . '://' . $requestHost . ($absLang === 'en' ? $absCanonicalEnPath : $absCanonicalRuPath)) : '');
$alternateRuUrl = $requestHost !== '' ? ($requestScheme . '://' . $requestHost . $absCanonicalRuPath) : '';
$alternateEnUrl = $requestHost !== '' ? ($requestScheme . '://' . $requestHost . $absCanonicalEnPath) : '';
$ogType = isset($ogType) && trim((string) $ogType) !== '' ? trim((string) $ogType) : 'website';
$defaultOgImagePath = '/assets/seo/og-image.svg';
$defaultOgImageUrl = $requestHost !== '' ? ($requestScheme . '://' . $requestHost . $defaultOgImagePath) : $defaultOgImagePath;
$ogImage = isset($ogImage) && trim((string) $ogImage) !== '' ? trim((string) $ogImage) : $defaultOgImageUrl;
$twitterCard = $ogImage !== '' ? 'summary_large_image' : 'summary';
$ogLocale = $absLang === 'en' ? 'en_US' : 'ru_RU';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => $siteName,
    'url' => $requestHost !== '' ? ($requestScheme . '://' . $requestHost . '/') : '/',
    'inLanguage' => $absLang === 'en' ? 'en' : 'ru',
];
if ($canonicalUrl !== '') {
    $jsonLd['mainEntityOfPage'] = $canonicalUrl;
}

$siteMenuItems = [];
try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/ensure_site_menu.php';
    $_menuDb = Database::getInstance();
    ensure_site_menu_table($_menuDb);
    $siteMenuItems = $_menuDb->fetchAll(
        "SELECT label, label_en, href FROM cms_site_menu WHERE is_enabled = 1 AND (placement = 'header' OR placement IS NULL OR placement = '') ORDER BY sort_order ASC, id ASC"
    );
} catch (Throwable $e) {
    $siteMenuItems = [];
}

$siteLogoText = $absLang === 'en' ? 'ABS Replays Analysis' : 'Анализ АБС реплеев';
$siteSlug = abs_extract_slug_from_request(); // slug без /en
$langRuHref = abs_build_lang_href('ru', $siteSlug);
$langEnHref = abs_build_lang_href('en', $siteSlug);
$homeRuHref = '/';
$homeEnHref = '/en';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="<?php echo htmlspecialchars($metaRobots, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($canonicalUrl !== ''): ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <?php if ($alternateRuUrl !== ''): ?>
    <link rel="alternate" hreflang="ru" href="<?php echo htmlspecialchars($alternateRuUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <?php if ($alternateEnUrl !== ''): ?>
    <link rel="alternate" hreflang="en" href="<?php echo htmlspecialchars($alternateEnUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="alternate" hreflang="x-default" href="<?php echo htmlspecialchars($alternateRuUrl !== '' ? $alternateRuUrl : $alternateEnUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta property="og:type" content="<?php echo htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($canonicalUrl !== ''): ?>
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta property="og:locale" content="<?php echo htmlspecialchars($ogLocale, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($ogImage !== ''): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?php echo htmlspecialchars($twitterCard, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($ogImage !== ''): ?>
    <meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image:type" content="image/svg+xml">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:image:alt" content="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <script type="application/ld+json"><?php echo json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <link rel="manifest" href="/site.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.3.2/css/flag-icons.min.css">
    <link rel="stylesheet" href="/css/style.css?v=<?php echo htmlspecialchars($siteVersion); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/icons/apple-touch-icon.svg">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" type="image/svg+xml" href="/favicon.svg">
    <?php if (!empty($extraHeadHtml)) {
        echo $extraHeadHtml;
    } ?>
    <script>
        window.ABS_LANG = <?php echo json_encode($absLang); ?>;
    </script>
</head>
<body<?php echo $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass) . '"' : ''; ?>>
    <div class="ambient-bg" id="ambientBg" aria-hidden="true"></div>
    <div class="container">
        <div class="header">
            <h1>
                <a
                    href="<?php echo htmlspecialchars($absLang === 'en' ? $homeEnHref : $homeRuHref, ENT_QUOTES, 'UTF-8'); ?>"
                    class="site-logo-link"
                    id="siteLogoLink"
                    data-text-ru="<?php echo htmlspecialchars('Анализ АБС реплеев', ENT_QUOTES, 'UTF-8'); ?>"
                    data-text-en="<?php echo htmlspecialchars('ABS Replays Analysis', ENT_QUOTES, 'UTF-8'); ?>"
                    data-href-ru="<?php echo htmlspecialchars($homeRuHref, ENT_QUOTES, 'UTF-8'); ?>"
                    data-href-en="<?php echo htmlspecialchars($homeEnHref, ENT_QUOTES, 'UTF-8'); ?>"
                >
                    <span class="site-logo-mark" aria-hidden="true"></span>
                    <span class="site-logo-text"><?php echo htmlspecialchars($siteLogoText, ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            </h1>
            <div class="header-right">
                <?php if (!empty($siteMenuItems)): ?>
                <nav class="site-header-nav" aria-label="<?php echo $absLang === 'en' ? 'Site sections' : 'Разделы сайта'; ?>">
                    <?php foreach ($siteMenuItems as $item):
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

                <div class="site-lang-switch" aria-label="Language switch">
                    <a class="site-lang-link<?php echo $absLang === 'ru' ? ' is-active' : ''; ?>" data-lang="ru" href="<?php echo htmlspecialchars($langRuHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Russian">
                        <span class="site-lang-flag fi fi-ru" aria-hidden="true"></span> RU
                    </a>
                    <a class="site-lang-link<?php echo $absLang === 'en' ? ' is-active' : ''; ?>" data-lang="en" href="<?php echo htmlspecialchars($langEnHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="English">
                        <span class="site-lang-flag fi fi-us" aria-hidden="true"></span> US
                    </a>
                </div>
            </div>
        </div>
