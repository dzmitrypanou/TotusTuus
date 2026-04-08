<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ensure_map_dictionary.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $db = Database::getInstance();
    ensure_map_dictionary_table($db);
    $maps = $db->fetchAll(
        'SELECT map_code, display_name_ru, display_name_en, is_moderated
         FROM map_dictionary
         ORDER BY display_name_ru'
    );

    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'count' => count($maps),
        'data' => $maps
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'count' => 0,
        'data' => [],
        'warning' => 'map_dictionary: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
