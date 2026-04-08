<?php
/**
 * Публичная CMS-страница: /about или page.php?slug=about
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/ensure_cms_pages.php';
$lang = 'ru';
try {
    require_once __DIR__ . '/includes/lang.php';
    $lang = abs_detect_lang();
} catch (Throwable $e) {
    $lang = 'ru';
}

$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
if ($slug === '' && !empty($_SERVER['REQUEST_URI'])) {
    $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = trim((string) $path, '/');
    if (stripos($path, 'en/') === 0) {
        $path = substr($path, 3);
    }
    if ($path !== '' && preg_match('/^[a-z0-9\-]{1,128}$/', $path)) {
        $slug = $path;
    }
}
if ($slug === '' || !preg_match('/^[a-z0-9\-]{1,128}$/', $slug)) {
    require __DIR__ . '/404.php';
    exit;
}

try {
    $db = Database::getInstance();
    ensure_cms_pages_table($db);
    $page = $db->fetchOne(
        'SELECT id, slug, title, title_en, body_html, body_html_en
         FROM cms_pages
         WHERE slug = ? AND is_published = 1',
        [$slug]
    );
} catch (Exception $e) {
    $page = null;
}

if (!$page) {
    require __DIR__ . '/404.php';
    exit;
}

$pageTitle = $lang === 'en' && !empty($page['title_en'])
    ? $page['title_en']
    : $page['title'];
$bodyHtmlForDescription = $lang === 'en' && !empty($page['body_html_en'])
    ? (string) $page['body_html_en']
    : (string) $page['body_html'];
$plainDescription = trim(preg_replace('/\s+/u', ' ', strip_tags($bodyHtmlForDescription)));
if (function_exists('mb_substr')) {
    $plainDescription = mb_substr($plainDescription, 0, 160, 'UTF-8');
} else {
    $plainDescription = substr($plainDescription, 0, 160);
}
$metaDescription = $plainDescription !== ''
    ? $plainDescription
    : ($lang === 'en'
        ? 'Read ABS Replays Analysis page and related information.'
        : 'Ознакомьтесь со страницей и материалами сайта Анализ АБС реплеев.');
$bodyClass = 'cms-public-page';
$extraHeadHtml = '<link rel="stylesheet" href="https://cdn.quilljs.com/1.3.6/quill.snow.css">';
require __DIR__ . '/includes/site_header.php';
?>

        <main class="cms-page-main">
            <h1 class="cms-page-title"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <div class="cms-page-article ql-snow">
                <div class="ql-editor cms-page-article-inner">
                    <?php
                    $bodyHtml = $lang === 'en' && !empty($page['body_html_en'])
                        ? (string) $page['body_html_en']
                        : (string) $page['body_html'];
                    echo $bodyHtml;
                    ?>
                </div>
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
