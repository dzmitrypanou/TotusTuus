<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод']);
    exit;
}

admin_require_csrf_ajax();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$title = isset($_POST['title']) ? trim((string) $_POST['title']) : '';
$titleEn = isset($_POST['title_en']) ? trim((string) $_POST['title_en']) : '';
$slug = isset($_POST['slug']) ? trim((string) $_POST['slug']) : '';
$bodyHtml = isset($_POST['body_html']) ? (string) $_POST['body_html'] : '';
$bodyHtmlEn = isset($_POST['body_html_en']) ? (string) $_POST['body_html_en'] : '';
$isPublished = isset($_POST['is_published']) ? 1 : 0;

if ($title === '' || $slug === '') {
    echo json_encode(['success' => false, 'error' => 'Заполните заголовок и адрес (slug)']);
    exit;
}

if (!preg_match('/^[a-z0-9\-]{1,128}$/', $slug)) {
    echo json_encode(['success' => false, 'error' => 'Slug: только латиница, цифры и дефис (например about или help-page)']);
    exit;
}

$reservedSlugs = ['admin', 'api', 'css', 'js', 'config', 'includes', 'page', 'index', '404'];
if (in_array($slug, $reservedSlugs, true)) {
    echo json_encode(['success' => false, 'error' => 'Этот адрес зарезервирован системой']);
    exit;
}

try {
    $titleEn = $titleEn === '' ? null : $titleEn;
    $bodyHtmlEn = $bodyHtmlEn === '' ? null : $bodyHtmlEn;

    if ($id > 0) {
        $dup = $db->fetchOne(
            'SELECT id FROM cms_pages WHERE slug = ? AND id <> ?',
            [$slug, $id]
        );
        if ($dup) {
            echo json_encode(['success' => false, 'error' => 'Страница с таким slug уже есть']);
            exit;
        }
        $db->update(
            'UPDATE cms_pages SET slug = ?, title = ?, title_en = ?, body_html = ?, body_html_en = ?, is_published = ? WHERE id = ?',
            [$slug, $title, $titleEn, $bodyHtml, $bodyHtmlEn, $isPublished, $id]
        );
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        $dup = $db->fetchOne('SELECT id FROM cms_pages WHERE slug = ?', [$slug]);
        if ($dup) {
            echo json_encode(['success' => false, 'error' => 'Страница с таким slug уже есть']);
            exit;
        }
        $newId = (int) $db->insert(
            'INSERT INTO cms_pages (slug, title, title_en, body_html, body_html_en, is_published)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$slug, $title, $titleEn, $bodyHtml, $bodyHtmlEn, $isPublished]
        );
        echo json_encode(['success' => true, 'id' => $newId]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
