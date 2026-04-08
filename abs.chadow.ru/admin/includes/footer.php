<?php
/**
 * Общий подвал админки (как на странице редактора танков).
 * Обычно страница уже задаёт $appVersion; иначе читается из config/version.json.
 */
if (!isset($appVersion)) {
    $_fvRaw = @file_get_contents(__DIR__ . '/../../config/version.json');
    $_fvData = $_fvRaw ? json_decode($_fvRaw, true) : null;
    $appVersion = (is_array($_fvData) && !empty($_fvData['version'])) ? $_fvData['version'] : '3.4.4';
}
?>
    <div class="container admin-footer-wrap">
    <footer>
        <div class="footer-links">
            <a href="https://twitch.tv/chadowfriend" target="_blank" rel="noopener noreferrer" class="social-link"><i class="fab fa-twitch"></i> Twitch</a>
            <span class="separator">•</span>
            <a href="https://t.me/chadowfriend" target="_blank" rel="noopener noreferrer" class="social-link"><i class="fab fa-telegram"></i> Telegram</a>
            <span class="separator">•</span>
            <a href="https://www.donationalerts.com/r/chadowfriend" target="_blank" rel="noopener noreferrer" class="social-link"><i class="fas fa-university"></i> Donation</a>
        </div>
        <div class="footer-text">
            Copyright (c) 2026 Analysis ABS replays <span class="version">ver. <?php echo htmlspecialchars($appVersion); ?></span>.
        </div>
        <div class="footer-text">
            I will make them and the places all around My hill a blessing; and I will cause showers to come down in their season; there shall be showers of blessing.
        </div>
        <div class="footer-text">
            Ezekiel 34:26 (NKJV)
        </div>
    </footer>
    </div>
    <div class="admin-page-bottom-spacer" aria-hidden="true"></div>
