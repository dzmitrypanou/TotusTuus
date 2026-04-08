<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
admin_require_ajax();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Неверный id']);
    exit;
}

try {
    $page = $db->fetchOne('SELECT id, slug, title, title_en, body_html, body_html_en, is_published FROM cms_pages WHERE id = ?', [$id]);
    if (!$page) {
        echo json_encode(['success' => false, 'error' => 'Страница не найдена']);
        exit;
    }
    echo json_encode(['success' => true, 'page' => $page], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
