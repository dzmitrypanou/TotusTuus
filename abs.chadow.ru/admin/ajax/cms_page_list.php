<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
admin_require_ajax();

try {
    $rows = $db->fetchAll(
        'SELECT id, slug, title, is_published, updated_at FROM cms_pages ORDER BY updated_at DESC'
    );
    echo json_encode(['success' => true, 'pages' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
