<?php
/**
 * Страница «404 — не найдено»: общая шапка/подвал с главного сайта.
 * Подключается из page.php и через ErrorDocument в .htaccess.
 */
http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');

$lang = 'ru';
try {
    require_once __DIR__ . '/includes/lang.php';
    $lang = abs_detect_lang();
} catch (Throwable $e) {
    $lang = 'ru';
}

$pageTitle = $lang === 'en' ? 'Page not found | ABS Replays Analysis' : 'Страница не найдена | Анализ АБС реплеев';
$bodyClass = 'page-404';
$extraHeadHtml = '<meta name="robots" content="noindex">';

require __DIR__ . '/includes/site_header.php';
?>

        <main class="error-page">
            <div class="error-page-inner">
                <p class="error-page-code" aria-hidden="true">404</p>
                <h1 class="error-page-title"><?php echo $lang === 'en' ? 'Page not found' : 'Страница не найдена'; ?></h1>
                <p class="error-page-text">
                    <?php echo $lang === 'en'
                        ? 'The requested page does not exist, was removed, or is not published yet.'
                        : 'Запрашиваемая страница не существует, удалена или ещё не опубликована.'; ?>
                </p>
                <p class="error-page-actions">
                    <a href="<?php echo $lang === 'en' ? '/en' : '/'; ?>" class="error-page-link error-page-link--primary"><?php echo $lang === 'en' ? 'Go to homepage' : 'На главную'; ?></a>
                    <a href="javascript:history.back()" class="error-page-link"><?php echo $lang === 'en' ? 'Go back' : 'Назад'; ?></a>
                </p>
            </div>
        </main>

<?php require __DIR__ . '/includes/site_footer.php'; ?>

    <script>
        fetch('/api/get_version.php')
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (!data || !data.success || !data.version) return;
                const versionEl = document.getElementById('siteVersion');
                if (versionEl) versionEl.textContent = data.version;
            })
            .catch(() => {});
    </script>
</body>
</html>
