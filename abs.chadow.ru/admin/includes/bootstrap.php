<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', '2592000');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    session_start();
}

$_root = dirname(__DIR__, 2);
require_once $_root . '/config/database.php';
require_once $_root . '/config/ensure_admin_users.php';
require_once $_root . '/config/ensure_dictionary_labels.php';
require_once $_root . '/config/vehicle_code.php';
require_once $_root . '/config/ensure_cms_pages.php';
require_once $_root . '/config/ensure_site_menu.php';
require_once $_root . '/config/ensure_wgsrt.php';

$db = Database::getInstance();
ensure_admin_users_table($db);
ensure_dictionary_labels_tables($db);
merge_duplicate_vehicle_codes($db);
ensure_cms_pages_table($db);
ensure_site_menu_table($db);
ensure_wgsrt_grades_lang_columns($db);

require_once $_root . '/config/ensure_login_throttle.php';
ensure_admin_login_throttle_table($db);
require_once __DIR__ . '/login_throttle.php';

require_once __DIR__ . '/csrf.php';
admin_csrf_ensure();
require_once __DIR__ . '/auth.php';

if (admin_is_logged_in() && !empty($_SESSION['admin_remember_me'])) {
    admin_session_send_cookie(time() + ADMIN_SESSION_REMEMBER_LIFETIME_SEC);
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($secure) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
