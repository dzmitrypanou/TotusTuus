<?php
declare(strict_types=1);

function panel_is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $fwd = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    return strtolower($fwd) === 'https';
}

function panel_configure_session_before_start(): void
{
    $secure = panel_is_https_request();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
}

function panel_ensure_csrf_token(): void
{
    if (empty($_SESSION['panel_csrf']) || !is_string($_SESSION['panel_csrf'])) {
        $_SESSION['panel_csrf'] = bin2hex(random_bytes(32));
    }
}

function panel_rotate_csrf_token(): void
{
    $_SESSION['panel_csrf'] = bin2hex(random_bytes(32));
}

function panel_csrf_token_value(): string
{
    panel_ensure_csrf_token();
    return (string)$_SESSION['panel_csrf'];
}

function panel_csrf_field(): string
{
    $t = htmlspecialchars(panel_csrf_token_value(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<input type="hidden" name="csrf_token" value="' . $t . '">';
}

function panel_post_skips_csrf_check(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return true;
    }
    if (isset($_POST['login_password'])) {
        return true;
    }
    if (isset($_POST['panel_login'], $_POST['panel_password'])) {
        return true;
    }
    if (isset($_POST['setup_password'])) {
        return true;
    }

    return false;
}

function panel_csrf_token_valid(): bool
{
    $posted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['panel_csrf'] ?? '');

    return $posted !== '' && $stored !== '' && hash_equals($stored, $posted);
}

function panel_send_admin_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: interest-cohort=(), geolocation=(), microphone=(), camera=()');
    header('X-XSS-Protection: 0');
}
