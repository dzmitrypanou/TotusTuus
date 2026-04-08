<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

admin_require_ajax_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса'], JSON_UNESCAPED_UNICODE);
    exit();
}

admin_require_csrf_ajax();

$id = (isset($_POST['id']) && $_POST['id'] !== '') ? (int) $_POST['id'] : 0;
$username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
$role = isset($_POST['role']) && $_POST['role'] === 'admin' ? 'admin' : 'user';

if ($username === '' || !preg_match('/^[a-zA-Z0-9_\-\.]{3,64}$/u', $username)) {
    echo json_encode(['success' => false, 'error' => 'Логин: 3–64 символа, латиница, цифры, _ - .'], JSON_UNESCAPED_UNICODE);
    exit();
}

function admin_count_admins($db) {
    $r = $db->fetchOne('SELECT COUNT(*) AS c FROM admin_users WHERE role = ?', ['admin']);
    return (int) ($r['c'] ?? 0);
}

try {
    if ($id <= 0) {
        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'error' => 'Пароль не короче 8 символов'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $dup = $db->fetchOne('SELECT id FROM admin_users WHERE username = ?', [$username]);
        if ($dup) {
            echo json_encode(['success' => false, 'error' => 'Такой логин уже занят'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $newId = $db->insert(
            'INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)',
            [$username, $hash, $role]
        );
        echo json_encode(['success' => true, 'id' => (int) $newId]);
        exit();
    }

    $existing = $db->fetchOne('SELECT id, username, role FROM admin_users WHERE id = ?', [$id]);
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $dup = $db->fetchOne('SELECT id FROM admin_users WHERE username = ? AND id != ?', [$username, $id]);
    if ($dup) {
        echo json_encode(['success' => false, 'error' => 'Такой логин уже занят'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $myId = (int) $_SESSION['admin_user_id'];
    $wasAdmin = $existing['role'] === 'admin';
    $admins = admin_count_admins($db);

    if ($wasAdmin && $role === 'user' && $admins <= 1) {
        echo json_encode(['success' => false, 'error' => 'Нельзя снять последнего администратора'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $db->query('UPDATE admin_users SET username = ?, role = ? WHERE id = ?', [$username, $role, $id]);

    if ($password !== '') {
        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'error' => 'Пароль не короче 8 символов'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->query('UPDATE admin_users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    if ($id === (int) $_SESSION['admin_user_id']) {
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role'] = $role;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
