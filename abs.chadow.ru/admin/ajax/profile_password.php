<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

admin_require_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса'], JSON_UNESCAPED_UNICODE);
    exit();
}

admin_require_csrf_ajax();

$current = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
$new = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
$confirm = isset($_POST['new_password_confirm']) ? (string) $_POST['new_password_confirm'] : '';

if ($current === '') {
    echo json_encode(['success' => false, 'error' => 'Введите текущий пароль'], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($new === '' || $confirm === '') {
    echo json_encode(['success' => false, 'error' => 'Заполните новый пароль и подтверждение'], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($new !== $confirm) {
    echo json_encode(['success' => false, 'error' => 'Новый пароль и подтверждение не совпадают'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (strlen($new) < 8) {
    echo json_encode(['success' => false, 'error' => 'Новый пароль: не менее 8 символов'], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($new === $current) {
    echo json_encode(['success' => false, 'error' => 'Новый пароль должен отличаться от текущего'], JSON_UNESCAPED_UNICODE);
    exit();
}

$userId = (int) $_SESSION['admin_user_id'];

try {
    $row = $db->fetchOne('SELECT id, password_hash FROM admin_users WHERE id = ?', [$userId]);
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (!password_verify($current, $row['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Неверный текущий пароль'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $db->query('UPDATE admin_users SET password_hash = ? WHERE id = ?', [$hash, $userId]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
