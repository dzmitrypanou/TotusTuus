<?php
/**
 * Регистрация наций из реплея: INSERT IGNORE в nation_labels для новых кодов.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ensure_dictionary_labels.php';
require_once __DIR__ . '/../config/dictionary_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('Неверный JSON');
    }
    $nations = $data['nations'] ?? [];
    if (!is_array($nations)) {
        $nations = [];
    }

    $db = Database::getInstance();
    ensure_dictionary_labels_tables($db);

    foreach ($nations as $rawCode) {
        if (!is_string($rawCode) && !is_numeric($rawCode)) {
            continue;
        }
        ensure_nation_label($db, (string) $rawCode);
    }

    $labelsRu = nation_label_map($db);
    $labelsEn = nation_label_map_en($db);
    echo json_encode([
        'success' => true,
        // старые клиенты
        'nation_labels' => $labelsRu,
        // новые клиенты
        'nation_labels_ru' => $labelsRu,
        'nation_labels_en' => $labelsEn,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
