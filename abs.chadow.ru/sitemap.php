<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=UTF-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/ensure_cms_pages.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $forwardedProto = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
    if ($forwardedProto === 'https' || $forwardedProto === 'http') {
        $scheme = $forwardedProto;
    }
}
$host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
$base = $host !== '' ? ($scheme . '://' . $host) : '';

$now = gmdate('c');
$urls = [
    ['loc' => $base . '/', 'lastmod' => $now, 'changefreq' => 'daily', 'priority' => '1.0'],
    ['loc' => $base . '/en', 'lastmod' => $now, 'changefreq' => 'daily', 'priority' => '1.0'],
];

try {
    $db = Database::getInstance();
    ensure_cms_pages_table($db);
    $rows = $db->fetchAll(
        'SELECT slug, updated_at FROM cms_pages WHERE is_published = 1 ORDER BY slug ASC'
    );

    foreach ($rows as $row) {
        $slug = isset($row['slug']) ? trim((string) $row['slug']) : '';
        if ($slug === '' || !preg_match('/^[a-z0-9\-]{1,128}$/', $slug)) {
            continue;
        }

        $updatedAt = isset($row['updated_at']) ? strtotime((string) $row['updated_at']) : false;
        $lastmod = $updatedAt !== false ? gmdate('c', $updatedAt) : $now;

        $urls[] = ['loc' => $base . '/' . $slug, 'lastmod' => $lastmod, 'changefreq' => 'weekly', 'priority' => '0.8'];
        $urls[] = ['loc' => $base . '/en/' . $slug, 'lastmod' => $lastmod, 'changefreq' => 'weekly', 'priority' => '0.8'];
    }
} catch (Throwable $e) {
    // Keep sitemap available even if DB is temporarily unavailable.
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $item) {
    if ($base === '' || empty($item['loc'])) {
        continue;
    }
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars((string) $item['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . htmlspecialchars((string) $item['lastmod'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</lastmod>\n";
    echo '    <changefreq>' . htmlspecialchars((string) $item['changefreq'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</changefreq>\n";
    echo '    <priority>' . htmlspecialchars((string) $item['priority'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
