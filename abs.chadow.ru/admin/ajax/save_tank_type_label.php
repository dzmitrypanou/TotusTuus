<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод']);
    exit;
}

admin_require_csrf_ajax();

$typeCode = isset($_POST['type_code']) ? trim($_POST['type_code']) : '';
$displayName = isset($_POST['display_name_ru']) ? trim($_POST['display_name_ru']) : '';
$displayNameEn = isset($_POST['display_name_en']) ? trim((string) $_POST['display_name_en']) : '';

if ($typeCode === '' || $displayName === '') {
    echo json_encode(['success' => false, 'error' => 'Пустые поля']);
    exit;
}

if (!preg_match('/^[a-z0-9_]{1,40}$/', $typeCode)) {
    echo json_encode(['success' => false, 'error' => 'Некорректный код типа']);
    exit;
}

$row = $db->fetchOne('SELECT type_code FROM tank_type_labels WHERE type_code = ?', [$typeCode]);
if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Неизвестный код типа']);
    exit;
}

$db->update(
    'UPDATE tank_type_labels SET display_name_ru = ?, display_name_en = ? WHERE type_code = ?',
    [$displayName, $displayNameEn !== '' ? $displayNameEn : $displayName, $typeCode]
);

echo json_encode(['success' => true]);
