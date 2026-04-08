<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод']);
    exit;
}

admin_require_csrf_ajax();

$nationCode = isset($_POST['nation_code']) ? trim($_POST['nation_code']) : '';
$displayName = isset($_POST['display_name_ru']) ? trim($_POST['display_name_ru']) : '';
$displayNameEn = isset($_POST['display_name_en']) ? trim((string) $_POST['display_name_en']) : '';

if ($nationCode === '' || $displayName === '') {
    echo json_encode(['success' => false, 'error' => 'Пустые поля']);
    exit;
}

if (!preg_match('/^[a-z0-9_]{1,40}$/', $nationCode)) {
    echo json_encode(['success' => false, 'error' => 'Некорректный код нации']);
    exit;
}

$row = $db->fetchOne('SELECT nation_code FROM nation_labels WHERE nation_code = ?', [$nationCode]);
if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Неизвестный код нации']);
    exit;
}

$db->update(
    'UPDATE nation_labels SET display_name_ru = ?, display_name_en = ? WHERE nation_code = ?',
    [$displayName, $displayNameEn !== '' ? $displayNameEn : $displayName, $nationCode]
);

echo json_encode(['success' => true]);
