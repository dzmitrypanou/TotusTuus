<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ensure_map_dictionary.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function normalizeMapCode($raw) {
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $s = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $raw);
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s, '_');
    if (strlen($s) > 128) {
        $s = substr($s, 0, 128);
    }
    return $s;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['map_code'])) {
        throw new Exception('Отсутствует map_code');
    }

    $mapCode = normalizeMapCode($data['map_code']);
    if ($mapCode === '') {
        throw new Exception('Некорректный map_code');
    }

    $suggested = isset($data['suggested_display']) && is_string($data['suggested_display'])
        ? trim($data['suggested_display'])
        : '';

    $suggestedEn = isset($data['suggested_display_en']) && is_string($data['suggested_display_en'])
        ? trim($data['suggested_display_en'])
        : '';
    if (function_exists('mb_strlen') && mb_strlen($suggested, 'UTF-8') > 255) {
        $suggested = mb_substr($suggested, 0, 255, 'UTF-8');
    } elseif (strlen($suggested) > 255) {
        $suggested = substr($suggested, 0, 255);
    }

    if (function_exists('mb_strlen') && mb_strlen($suggestedEn, 'UTF-8') > 255) {
        $suggestedEn = mb_substr($suggestedEn, 0, 255, 'UTF-8');
    } elseif (strlen($suggestedEn) > 255) {
        $suggestedEn = substr($suggestedEn, 0, 255);
    }

    $db = Database::getInstance();
    ensure_map_dictionary_table($db);

    $existing = $db->fetchOne(
        'SELECT map_code, display_name_ru, display_name_en, is_moderated FROM map_dictionary WHERE map_code = ?',
        [$mapCode]
    );

    if ($existing) {
        echo json_encode([
            'success' => true,
            'created' => false,
            'map_code' => $existing['map_code'],
            'display_name_ru' => $existing['display_name_ru'],
            'display_name_en' => $existing['display_name_en'],
            'is_moderated' => isset($existing['is_moderated']) ? (int) $existing['is_moderated'] : 0
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $display = $suggested !== '' ? $suggested : $mapCode;
    $displayEn = $suggestedEn !== '' ? $suggestedEn : $display;

    $db->query(
        'INSERT INTO map_dictionary (map_code, display_name_ru, display_name_en, is_moderated) VALUES (?, ?, ?, 0)',
        [$mapCode, $display, $displayEn]
    );

    echo json_encode([
        'success' => true,
        'created' => true,
        'map_code' => $mapCode,
        'display_name_ru' => $display,
        'display_name_en' => $displayEn,
        'is_moderated' => 0
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
