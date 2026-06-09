<?php
declare(strict_types=1);

require_once __DIR__ . '/api_public_guard.php';
api_public_guard_enforce();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/../includes/ordo_missae_sections.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

header('Cache-Control: private, max-age=0, must-revalidate');

try {

    ensurePanelOrdoMissaeTable();
    $stmt = db()->query('SELECT * FROM panel_ordo_missae WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    $html = is_array($row) ? ordo_missae_public_html_from_row($row) : '';
    $updatedAt = is_array($row) ? (string)($row['updated_at'] ?? '') : '';
    echo json_encode(
        ['html' => $html, 'updated_at' => $updatedAt],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'database_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
