<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

admin_require_ajax_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры'], JSON_UNESCAPED_UNICODE);
    exit();
}

admin_require_csrf_ajax();

$id = (int) $_POST['id'];
$myId = (int) $_SESSION['admin_user_id'];

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Некорректный id'], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($id === $myId) {
    echo json_encode(['success' => false, 'error' => 'Нельзя удалить свою учётную запись'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $u = $db->fetchOne('SELECT id, role FROM admin_users WHERE id = ?', [$id]);
    if (!$u) {
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($u['role'] === 'admin') {
        $cnt = $db->fetchOne('SELECT COUNT(*) AS c FROM admin_users WHERE role = ?', ['admin']);
        if ((int) ($cnt['c'] ?? 0) <= 1) {
            echo json_encode(['success' => false, 'error' => 'Нельзя удалить последнего администратора'], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    $db->delete('DELETE FROM admin_users WHERE id = ?', [$id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
