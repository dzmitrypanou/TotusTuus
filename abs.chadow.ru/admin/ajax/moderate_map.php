<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../config/ensure_map_dictionary.php';

header('Content-Type: application/json; charset=utf-8');

admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['map_code']) || !isset($_POST['moderated'])) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit();
}

admin_require_csrf_ajax();

$mapCode = trim((string) $_POST['map_code']);
if ($mapCode === '' || strlen($mapCode) > 128) {
    echo json_encode(['success' => false, 'error' => 'Некорректный код карты']);
    exit();
}

$moderated = (int) $_POST['moderated'] ? 1 : 0;

ensure_map_dictionary_table($db);

try {
    $db->update(
        'UPDATE map_dictionary SET is_moderated = ? WHERE map_code = ?',
        [$moderated, $mapCode]
    );
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
