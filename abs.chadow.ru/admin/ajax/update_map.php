<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../config/ensure_map_dictionary.php';

header('Content-Type: application/json; charset=utf-8');

admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit();
}

admin_require_csrf_ajax();

$mapCode = isset($_POST['map_code']) ? trim((string) $_POST['map_code']) : '';
$displayName = isset($_POST['display_name_ru']) ? trim((string) $_POST['display_name_ru']) : '';
$displayNameEn = isset($_POST['display_name_en']) ? trim((string) $_POST['display_name_en']) : '';
$isModerated = isset($_POST['is_moderated']) ? 1 : 0;

if ($mapCode === '' || $displayName === '') {
    echo json_encode(['success' => false, 'error' => 'Не заполнены обязательные поля']);
    exit();
}

if (strlen($mapCode) > 128) {
    echo json_encode(['success' => false, 'error' => 'Слишком длинный код карты']);
    exit();
}

if (function_exists('mb_strlen') && mb_strlen($displayName, 'UTF-8') > 255) {
    $displayName = mb_substr($displayName, 0, 255, 'UTF-8');
} elseif (strlen($displayName) > 255) {
    $displayName = substr($displayName, 0, 255);
}

if ($displayNameEn === '') {
    $displayNameEn = $displayName;
}

if (function_exists('mb_strlen') && mb_strlen($displayNameEn, 'UTF-8') > 255) {
    $displayNameEn = mb_substr($displayNameEn, 0, 255, 'UTF-8');
} elseif (strlen($displayNameEn) > 255) {
    $displayNameEn = substr($displayNameEn, 0, 255);
}

ensure_map_dictionary_table($db);

try {
    $exists = $db->fetchOne('SELECT map_code FROM map_dictionary WHERE map_code = ?', [$mapCode]);
    if (!$exists) {
        echo json_encode(['success' => false, 'error' => 'Карта не найдена в словаре']);
        exit();
    }
    $db->update(
        'UPDATE map_dictionary SET display_name_ru = ?, display_name_en = ?, is_moderated = ? WHERE map_code = ?',
        [$displayName, $displayNameEn, $isModerated, $mapCode]
    );
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
