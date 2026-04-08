<?php

function admin_csrf_ensure(): void {
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
}

function admin_csrf_token(): string {
    admin_csrf_ensure();
    return (string) $_SESSION['admin_csrf'];
}

function admin_csrf_verify(): bool {
    $token = '';
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = trim((string) $_SERVER['HTTP_X_CSRF_TOKEN']);
    } elseif (isset($_POST['csrf_token'])) {
        $token = (string) $_POST['csrf_token'];
    }
    if ($token === '' || empty($_SESSION['admin_csrf'])) {
        return false;
    }
    return hash_equals((string) $_SESSION['admin_csrf'], $token);
}

function admin_require_csrf_ajax(): void {
    if (!admin_csrf_verify()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности'], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
