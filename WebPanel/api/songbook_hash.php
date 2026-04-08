<?php
declare(strict_types=1);

/**
 * Лёгкая праверка зменаў: SHA-256 ад таго ж JSON, што аддае songbook.php.
 */

require_once __DIR__ . '/api_public_guard.php';
api_public_guard_enforce();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    ensureSchemaAndSeed();
    require_once __DIR__ . '/songbook_common.php';
    $json = json_encode(fetch_active_songbook_for_api(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo json_encode(
        ['hash' => hash('sha256', $json)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'database_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
