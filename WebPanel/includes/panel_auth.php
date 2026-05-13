<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/panel_sections.php';

function panel_session_user_id(): ?int
{
    $id = $_SESSION['panel_user_id'] ?? null;
    if (is_int($id)) {
        return $id > 0 ? $id : null;
    }
    if (is_string($id) && ctype_digit($id)) {
        $n = (int)$id;

        return $n > 0 ? $n : null;
    }

    return null;
}

function panel_set_logged_in_user(int $userId): void
{
    $_SESSION['panel_user_id'] = $userId;
    unset($_SESSION['is_admin_logged_in']);
    panel_invalidate_user_cache();
}

function panel_clear_login_session(): void
{
    unset($_SESSION['panel_user_id'], $_SESSION['is_admin_logged_in']);
    panel_invalidate_user_cache();
}

function panel_invalidate_user_cache(): void
{
    unset($GLOBALS['panel_current_user_cache']);
}

function panel_current_user(): ?array
{
    if (array_key_exists('panel_current_user_cache', $GLOBALS)) {
        return $GLOBALS['panel_current_user_cache'];
    }
    $id = panel_session_user_id();
    if ($id === null) {
        $GLOBALS['panel_current_user_cache'] = null;

        return null;
    }
    $stmt = db()->prepare(
        'SELECT id, login, role, is_active FROM panel_users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        panel_clear_login_session();
        $GLOBALS['panel_current_user_cache'] = null;

        return null;
    }
    if (!(int)$row['is_active']) {
        panel_clear_login_session();
        $GLOBALS['panel_current_user_cache'] = null;

        return null;
    }
    $GLOBALS['panel_current_user_cache'] = $row;

    return $row;
}

function panel_is_logged_in(): bool
{
    return panel_current_user() !== null;
}

function panel_is_admin(): bool
{
    $u = panel_current_user();

    return $u !== null && (string)$u['role'] === 'admin';
}

function panel_users_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM panel_users')->fetchColumn();
}

function panel_find_user_by_login(string $login): ?array
{
    $stmt = db()->prepare('SELECT * FROM panel_users WHERE login = :l LIMIT 1');
    $stmt->execute([':l' => $login]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function panel_legacy_admin_password_hash(): ?string
{
    $stmt = db()->query('SELECT password_hash FROM admin_auth WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    return (string)$row['password_hash'];
}

function panel_attempt_login(string $login, string $password): array
{
    if ($password === '') {
        return ['ok' => false, 'error' => 'Увядзіце пароль.', 'user_id' => null];
    }
    $loginTrim = trim($login);
    $user = $loginTrim !== '' ? panel_find_user_by_login($loginTrim) : null;
    if ($user !== null) {
        if (!(int)$user['is_active']) {
            return ['ok' => false, 'error' => 'Уліковы запіс адключаны.', 'user_id' => null];
        }
        if (!password_verify($password, (string)$user['password_hash'])) {
            return ['ok' => false, 'error' => 'Нясапраўны лагін або пароль.', 'user_id' => null];
        }

        return ['ok' => true, 'error' => null, 'user_id' => (int)$user['id']];
    }
    if (panel_users_count() !== 0) {
        return ['ok' => false, 'error' => 'Нясапраўны лагін або пароль.', 'user_id' => null];
    }
    $legacyHash = panel_legacy_admin_password_hash();
    if ($legacyHash === null || $legacyHash === '' || !password_verify($password, $legacyHash)) {
        return ['ok' => false, 'error' => 'Нясапраўны лагін або пароль.', 'user_id' => null];
    }
    $newLogin = $loginTrim !== '' ? $loginTrim : 'admin';
    if (panel_find_user_by_login($newLogin) !== null) {
        return ['ok' => false, 'error' => 'Нясапраўны лагін або пароль.', 'user_id' => null];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = db()->prepare(
        'INSERT INTO panel_users (login, password_hash, role, is_active) VALUES (:l, :h, \'admin\', 1)'
    );
    $ins->execute([':l' => $newLogin, ':h' => $hash]);

    return ['ok' => true, 'error' => null, 'user_id' => (int)db()->lastInsertId()];
}

function panel_ensure_first_admin_user(string $passwordHash, string $login = 'admin'): ?int
{
    if (panel_users_count() > 0) {
        return null;
    }
    $login = trim($login);
    if ($login === '') {
        $login = 'admin';
    }
    $ins = db()->prepare(
        'INSERT INTO panel_users (login, password_hash, role, is_active) VALUES (:l, :h, \'admin\', 1)'
    );
    $ins->execute([':l' => $login, ':h' => $passwordHash]);

    return (int)db()->lastInsertId();
}

function panel_user_section_grants(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $stmt = db()->prepare(
        'SELECT section_key FROM panel_user_section_grants WHERE user_id = :id ORDER BY section_key ASC'
    );
    $stmt->execute([':id' => $userId]);
    $rows = $stmt->fetchAll();
    $keys = [];
    foreach ($rows as $row) {
        if (isset($row['section_key']) && panel_valid_section_key((string)$row['section_key'])) {
            $keys[] = (string)$row['section_key'];
        }
    }
    $cache[$userId] = $keys;

    return $keys;
}

function panel_can_access_section(string $sectionKey): bool
{
    if (!panel_valid_section_key($sectionKey)) {
        return false;
    }
    $user = panel_current_user();
    if ($user === null) {
        return false;
    }
    if ((string)$user['role'] === 'admin') {
        return true;
    }

    return in_array($sectionKey, panel_user_section_grants((int)$user['id']), true);
}

function panel_first_accessible_view(): string
{
    $try = ['categories', 'songbook', 'scripture', 'prayers', 'add-prayer'];
    foreach ($try as $v) {
        $s = panel_view_section($v);
        if ($s !== null && panel_can_access_section($s)) {
            return $v;
        }
    }

    return 'no-access';
}

function panel_require_login_redirect(): void
{
    if (!panel_is_logged_in()) {
        header('Location: /', true, 302);
        exit;
    }
}

function panel_require_admin_or_redirect(): void
{
    panel_require_login_redirect();
    if (!panel_is_admin()) {
        header('Location: /', true, 302);
        exit;
    }
}

function panel_require_section_for_post(string $sectionKey, bool $isAjaxRequest): void
{
    if (panel_can_access_section($sectionKey)) {
        return;
    }
    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => '',
            'error' => 'Няма доступу да гэтага раздзела.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(403);
    echo '403 — няма доступу да гэтага раздзела.';
    exit;
}

function panel_require_section_get(string $sectionKey): void
{
    if (panel_can_access_section($sectionKey)) {
        return;
    }
    http_response_code(403);
    echo '403 — няма доступу да гэтага раздзела.';
    exit;
}
