<?php

/** Срок жизни cookie сессии при «Запомнить меня» (30 дней), в секундах. */
const ADMIN_SESSION_REMEMBER_LIFETIME_SEC = 60 * 60 * 24 * 30;

function admin_request_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * Отправить Set-Cookie для текущего id сессии (после session_regenerate_id).
 */
function admin_session_send_cookie(int $expiresUnix): void {
    $secure = admin_request_is_https();
    $name = session_name();
    $sid = session_id();
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $sid, [
            'expires' => $expiresUnix,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie($name, $sid, $expiresUnix, '/', '', $secure, true);
    }
}

function admin_user() {
    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }
    return [
        'id' => (int) $_SESSION['admin_user_id'],
        'username' => $_SESSION['admin_username'] ?? '',
        'role' => $_SESSION['admin_role'] ?? 'user',
    ];
}

function admin_is_logged_in() {
    return admin_user() !== null;
}

function admin_is_admin() {
    $u = admin_user();
    return $u && $u['role'] === 'admin';
}

function admin_require_web() {
    if (!admin_is_logged_in()) {
        $path = $_SERVER['REQUEST_URI'] ?? '/admin/';
        $return = (strpos($path, '/admin/login') === 0) ? '/admin/dashboard' : $path;
        header('Location: /admin/login?return=' . rawurlencode($return));
        exit();
    }
}

function admin_require_web_admin() {
    admin_require_web();
    if (!admin_is_admin()) {
        header('Location: /admin/dashboard');
        exit();
    }
}

function admin_require_ajax() {
    if (!admin_is_logged_in()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

function admin_require_ajax_admin() {
    admin_require_ajax();
    if (!admin_is_admin()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

function admin_attempt_login($db, $username, $password, $rememberMe = false) {
    $username = trim((string) $username);
    if ($username === '' || $password === '') {
        return false;
    }
    $row = $db->fetchOne(
        'SELECT id, username, password_hash, role FROM admin_users WHERE username = ?',
        [$username]
    );
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = (int) $row['id'];
    $_SESSION['admin_username'] = $row['username'];
    $_SESSION['admin_role'] = $row['role'];
    $_SESSION['admin_remember_me'] = $rememberMe ? 1 : 0;
    $cookieExpires = $rememberMe ? time() + ADMIN_SESSION_REMEMBER_LIFETIME_SEC : 0;
    admin_session_send_cookie($cookieExpires);
    admin_login_throttle_register_success($db);
    return true;
}

function admin_logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        $name = session_name();
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, '', [
                'expires' => time() - 42000,
                'path' => $p['path'] ?: '/',
                'domain' => $p['domain'] ?? '',
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        } else {
            setcookie($name, '', time() - 42000, $p['path'] ?: '/', $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
    }
    session_destroy();
}
