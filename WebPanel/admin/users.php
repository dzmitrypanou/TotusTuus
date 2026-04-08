<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel_security.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/schema.php';
require_once __DIR__ . '/../includes/panel_auth.php';

panel_configure_session_before_start();
session_start();
panel_ensure_csrf_token();
panel_send_admin_security_headers();

if (!panel_is_logged_in()) {
    header('Location: /', true, 302);
    exit;
}

ensureSchemaAndSeed();

if (!panel_is_admin()) {
    http_response_code(403);
    echo '403 — толькі для адміністратара.';
    exit;
}

$message = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['logout'])) {
    panel_clear_login_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
    }
    session_destroy();
    header('Location: /', true, 302);
    exit;
}

function panel_count_admin_accounts(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM panel_users WHERE role = 'admin'")->fetchColumn();
}

/** @param list<string> $postedKeys */
function panel_sanitize_grant_keys(array $postedKeys): array
{
    $out = [];
    foreach ($postedKeys as $k) {
        $k = (string)$k;
        if (panel_valid_section_key($k)) {
            $out[$k] = true;
        }
    }

    return array_keys($out);
}

/** @param list<string> $grants */
function panel_save_user_grants(int $userId, array $grants): void
{
    db()->prepare('DELETE FROM panel_user_section_grants WHERE user_id = :id')->execute([':id' => $userId]);
    if (count($grants) === 0) {
        return;
    }
    $ins = db()->prepare(
        'INSERT INTO panel_user_section_grants (user_id, section_key) VALUES (:uid, :sk)'
    );
    foreach ($grants as $sk) {
        $ins->execute([':uid' => $userId, ':sk' => $sk]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isset($_POST['logout'])) {
    if (!panel_csrf_token_valid()) {
        http_response_code(403);
        echo '403 — токен бяспекі.';
        exit;
    }
    $selfId = (int)(panel_current_user()['id'] ?? 0);

    if (isset($_POST['panel_create_user'])) {
        $login = trim((string)($_POST['new_login'] ?? ''));
        $pass = (string)($_POST['new_password'] ?? '');
        $pass2 = (string)($_POST['new_password_confirm'] ?? '');
        $role = (string)($_POST['new_role'] ?? 'user');
        if (!in_array($role, ['admin', 'user'], true)) {
            $error = 'Нясапраўная роля.';
        } elseif ($login === '' || strlen($login) > 64 || !preg_match('/^[a-zA-Z0-9._-]+$/u', $login)) {
            $error = 'Лагін: 1–64 сімвалаў, лацінка, лічбы, . _ -';
        } elseif (strlen($pass) < 8) {
            $error = 'Пароль — не менш за 8 сімвалаў.';
        } elseif ($pass !== $pass2) {
            $error = 'Паролі не супадаюць.';
        } else {
            $grants = panel_sanitize_grant_keys($_POST['new_grant'] ?? []);
            if ($role === 'user' && count($grants) === 0) {
                $error = 'Для карыстальніка абярыце хаця б адзін раздзел.';
            } elseif (panel_find_user_by_login($login) !== null) {
                $error = 'Такі лагін ужо існуе.';
            } else {
                try {
                    db()->beginTransaction();
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $ins = db()->prepare(
                        'INSERT INTO panel_users (login, password_hash, role, is_active) VALUES (:l, :h, :r, 1)'
                    );
                    $ins->execute([':l' => $login, ':h' => $hash, ':r' => $role]);
                    $newId = (int)db()->lastInsertId();
                    if ($role === 'user') {
                        panel_save_user_grants($newId, $grants);
                    }
                    db()->commit();
                    panel_invalidate_user_cache();
                    $message = 'Карыстальнік створаны.';
                } catch (Throwable $e) {
                    db()->rollBack();
                    $error = 'Памылка: ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['panel_save_user'])) {
        $uid = (int)($_POST['edit_user_id'] ?? 0);
        $role = (string)($_POST['edit_role'] ?? 'user');
        $isActive = isset($_POST['edit_is_active']) ? 1 : 0;
        $grants = panel_sanitize_grant_keys($_POST['edit_grant'] ?? []);
        if ($uid <= 0) {
            $error = 'Некарэктны карыстальнік.';
        } elseif (!in_array($role, ['admin', 'user'], true)) {
            $error = 'Нясапраўная роля.';
        } else {
            $stmt = db()->prepare('SELECT id, role FROM panel_users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $uid]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $error = 'Карыстальнік не знойдзены.';
            } elseif ($role === 'user' && count($grants) === 0) {
                $error = 'Абярыце хаця б адзін раздзел для ролі «карыстальнік».';
            } elseif ($uid === $selfId && $isActive === 0) {
                $error = 'Нельга адключыць уласны ўліковы запіс.';
            } elseif ((string)$row['role'] === 'admin' && $role === 'user' && panel_count_admin_accounts() <= 1) {
                $error = 'Нельга зняць ролю адміністратара ў апошняга адміна.';
            } else {
                try {
                    db()->beginTransaction();
                    $wasAdmin = (string)$row['role'] === 'admin';
                    $upd = db()->prepare(
                        'UPDATE panel_users SET role = :r, is_active = :a WHERE id = :id'
                    );
                    $upd->execute([':r' => $role, ':a' => $isActive, ':id' => $uid]);
                    if ($role === 'admin') {
                        panel_save_user_grants($uid, []);
                    } else {
                        panel_save_user_grants($uid, $grants);
                    }
                    db()->commit();
                    panel_invalidate_user_cache();
                    $message = 'Змены захаваны.';
                } catch (Throwable $e) {
                    db()->rollBack();
                    $error = 'Памылка: ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['panel_reset_password'])) {
        $uid = (int)($_POST['pwd_user_id'] ?? 0);
        $pass = (string)($_POST['pwd_new'] ?? '');
        $pass2 = (string)($_POST['pwd_new_confirm'] ?? '');
        if ($uid <= 0) {
            $error = 'Некарэктны карыстальнік.';
        } elseif (strlen($pass) < 8) {
            $error = 'Пароль — не менш за 8 сімвалаў.';
        } elseif ($pass !== $pass2) {
            $error = 'Паролі не супадаюць.';
        } else {
            $stmt = db()->prepare('SELECT id FROM panel_users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $uid]);
            if (!is_array($stmt->fetch())) {
                $error = 'Карыстальнік не знойдзены.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                db()->prepare('UPDATE panel_users SET password_hash = :h WHERE id = :id')->execute([
                    ':h' => $hash,
                    ':id' => $uid,
                ]);
                panel_invalidate_user_cache();
                $message = 'Пароль абноўлены.';
            }
        }
    } elseif (isset($_POST['panel_delete_user'])) {
        $uid = (int)($_POST['delete_user_id'] ?? 0);
        if ($uid <= 0) {
            $error = 'Некарэктны карыстальнік.';
        } elseif ($uid === $selfId) {
            $error = 'Нельга выдаліць уласны ўліковы запіс.';
        } else {
            $stmt = db()->prepare('SELECT role FROM panel_users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $uid]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $error = 'Карыстальнік не знойдзены.';
            } elseif ((string)$row['role'] === 'admin' && panel_count_admin_accounts() <= 1) {
                $error = 'Нельга выдаліць апошняга адміністратара.';
            } else {
                db()->prepare('DELETE FROM panel_users WHERE id = :id')->execute([':id' => $uid]);
                panel_invalidate_user_cache();
                $message = 'Карыстальнік выдалены.';
            }
        }
    }
}

$usersList = db()->query(
    'SELECT id, login, role, is_active, created_at FROM panel_users ORDER BY login ASC'
)->fetchAll();

$grantsByUser = [];
foreach ($usersList as $ur) {
    $id = (int)$ur['id'];
    $grantsByUser[$id] = (string)$ur['role'] === 'admin'
        ? panel_content_section_keys()
        : panel_user_section_grants($id);
}

$labels = panel_section_labels_be();
$sectionKeys = panel_content_section_keys();

$labelsShort = [
    'prayers' => 'Малітвы',
    'songbook' => 'Спеўнік',
    'scripture' => 'Біблія',
    'liturgy' => 'Літургія',
    'lectionary' => 'Лекц.',
    'announcements' => 'Аб’явы',
];

$editFocus = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if (isset($_POST['panel_save_user'], $_POST['edit_user_id'])) {
    $editFocus = (int)$_POST['edit_user_id'];
} elseif (isset($_POST['panel_reset_password'], $_POST['pwd_user_id'])) {
    $editFocus = (int)$_POST['pwd_user_id'];
}

?>
<!doctype html>
<html lang="be">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(panel_csrf_token_value(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Карыстальнікі — Totus Tuus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,500&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg-deep: #0a0c14;
      --bg-mid: #12182a;
      --bg-glow: #1a2240;
      --card: rgba(22, 28, 46, 0.72);
      --card-solid: #161c2e;
      --text: #e8ecf4;
      --muted: #94a3b8;
      --line: rgba(148, 163, 184, 0.18);
      --accent: #7c6cf0;
      --accent-glow: rgba(124, 108, 240, 0.35);
      --radius: 14px;
      --radius-sm: 10px;
    }
    * { box-sizing: border-box; }
    html { color-scheme: dark; scrollbar-gutter: stable; }
    body {
      font-family: "DM Sans", system-ui, sans-serif;
      max-width: 1120px;
      margin: 0 auto;
      padding: 28px 16px 48px;
      color: var(--text);
      min-height: 100vh;
      background:
        radial-gradient(ellipse 120% 80% at 100% -20%, rgba(124, 108, 240, 0.22), transparent 50%),
        radial-gradient(ellipse 90% 60% at -10% 50%, rgba(196, 163, 90, 0.08), transparent 45%),
        linear-gradient(165deg, var(--bg-deep) 0%, var(--bg-mid) 42%, var(--bg-glow) 100%);
      background-attachment: fixed;
    }
    h1 {
      margin: 0;
      font-family: "Cormorant Garamond", "Times New Roman", serif;
      font-size: clamp(2rem, 4vw, 2.75rem);
      font-weight: 600;
      letter-spacing: 0.02em;
      line-height: 1.1;
      background: linear-gradient(120deg, #f1f5f9 0%, #e2d5b8 45%, #c7d2fe 100%);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    h2 { margin: 0 0 12px; font-size: 1.25rem; font-weight: 600; color: var(--text); }
    p { color: var(--muted); line-height: 1.55; }
    .header {
      position: relative;
      overflow: hidden;
      border-radius: calc(var(--radius) + 4px);
      padding: 22px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      border: 1px solid var(--line);
      background:
        linear-gradient(135deg, rgba(30, 27, 75, 0.95) 0%, rgba(15, 23, 42, 0.92) 50%, rgba(30, 41, 59, 0.88) 100%);
      box-shadow:
        0 4px 24px rgba(0, 0, 0, 0.35),
        0 0 0 1px rgba(255, 255, 255, 0.04) inset,
        0 1px 0 rgba(255, 255, 255, 0.06) inset;
    }
    .header::before {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(105deg, transparent 40%, rgba(196, 163, 90, 0.06) 70%, rgba(124, 108, 240, 0.12) 100%);
      pointer-events: none;
    }
    .header > * { position: relative; z-index: 1; }
    .header-brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      text-align: center;
    }
    .header-brand h1 { text-align: center; }
    .header-tagline {
      margin: 0;
      max-width: 22rem;
      font-size: calc(0.8125rem * 0.7);
      font-weight: 500;
      color: var(--muted);
      letter-spacing: 0.04em;
      text-transform: uppercase;
      line-height: 1.4;
      text-align: center;
    }
    .top-nav {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 8px;
      justify-content: flex-end;
      max-width: 100%;
      flex: 1 1 auto;
      min-width: 0;
    }
    .top-nav-row {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-end;
      gap: 14px 22px;
      justify-content: flex-end;
      width: 100%;
    }
    .nav-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
      align-items: flex-start;
    }
    .nav-group-label {
      font-size: 0.625rem;
      font-weight: 700;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: rgba(148, 163, 184, 0.85);
      line-height: 1;
    }
    .nav-group-items {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      align-items: center;
    }
    .nav-group-items form { display: inline; margin: 0; }
    a.btn-pill,
    button.btn-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--text);
      text-decoration: none;
      font-weight: 600;
      font-size: 0.875rem;
      padding: 8px 12px;
      border-radius: var(--radius-sm);
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.08);
      transition: background 0.15s ease, border-color 0.15s ease, filter 0.15s ease;
      box-sizing: border-box;
      line-height: 1.2;
    }
    button.btn-pill {
      margin-top: 0;
      font-family: inherit;
      cursor: pointer;
      box-shadow: none;
    }
    a.btn-pill:hover,
    button.btn-pill:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.14);
      filter: none;
    }
    a.btn-pill.active,
    button.btn-pill.active {
      background: linear-gradient(135deg, rgba(124, 108, 240, 0.35), rgba(196, 163, 90, 0.18));
      border-color: rgba(196, 163, 90, 0.35);
      color: #fff;
    }
    #dynamic-sections { margin-top: 16px; }
    .card {
      background: var(--card);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      margin-top: 16px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    }
    .card:first-of-type { margin-top: 0; }
    .msg {
      margin-bottom: 16px;
      padding: 12px 14px;
      border-radius: var(--radius-sm);
      font-size: 0.875rem;
      font-weight: 500;
      line-height: 1.45;
    }
    .msg.ok {
      border: 1px solid rgba(74, 222, 128, 0.35);
      background: rgba(22, 163, 74, 0.22);
      color: #bbf7d0;
    }
    .msg.err {
      border: 1px solid rgba(248, 113, 113, 0.35);
      background: rgba(127, 29, 29, 0.35);
      color: #fecaca;
    }
    label { display: block; margin: 14px 0 7px; font-weight: 600; font-size: 0.875rem; color: #cbd5e1; }
    input {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      font: inherit;
      color: var(--text);
      background: rgba(10, 12, 20, 0.55);
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    select {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      font: inherit;
      color: var(--text);
      background-color: rgba(10, 12, 20, 0.55);
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    /* Як у галоўнай панэлі: адступ справа і SVG-стрэлка, не сістэмная ў краі */
    select:not([multiple]) {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      padding: 11px 42px 11px 12px;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%2394a3b8' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-size: 14px 14px;
      cursor: pointer;
    }
    select:not([multiple]):focus {
      outline: none;
      border-color: rgba(124, 108, 240, 0.55);
      box-shadow: 0 0 0 3px var(--accent-glow);
      background-color: rgba(10, 12, 20, 0.65);
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%23cbd5e1' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E");
    }
    input:focus {
      outline: none;
      border-color: rgba(124, 108, 240, 0.55);
      box-shadow: 0 0 0 3px var(--accent-glow);
    }
    select[multiple]:focus {
      outline: none;
      border-color: rgba(124, 108, 240, 0.55);
      box-shadow: 0 0 0 3px var(--accent-glow);
    }
    option { background: var(--card-solid); color: var(--text); }
    .grant-grid {
      display: grid;
      gap: 8px;
      margin-top: 8px;
    }
    .grant-grid label {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 500;
      margin: 0;
      cursor: pointer;
      color: var(--text);
    }
    .grant-grid input { width: auto; margin: 0; }
    .user-active-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 10px;
      font-weight: 500;
      color: var(--text);
    }
    .user-active-row input { width: auto; margin: 0; }
    button {
      margin-top: 14px;
      padding: 11px 18px;
      border: none;
      border-radius: var(--radius-sm);
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      background: linear-gradient(135deg, #6d5dfc 0%, #8b7cf5 50%, #a78bfa 100%);
      color: #fff;
      box-shadow: 0 8px 24px rgba(109, 93, 252, 0.35);
      transition: filter 0.15s ease, transform 0.1s ease;
    }
    button:hover { filter: brightness(1.08); }
    button:active { transform: translateY(1px); }
    .btn-mini {
      margin-top: 0;
      padding: 5px 10px;
      border-radius: 8px;
      font-size: 12px;
      box-shadow: none;
    }
    .btn-mini.secondary {
      background: rgba(124, 108, 240, 0.22);
      color: #e0e7ff;
      border: 1px solid rgba(124, 108, 240, 0.3);
    }
    .btn-mini.danger {
      background: rgba(248, 113, 113, 0.12);
      color: #fca5a5;
      border: 1px solid rgba(248, 113, 113, 0.25);
    }
    .muted { color: var(--muted); font-size: 0.875rem; }
    .table-wrap {
      overflow-x: auto;
      margin-top: 4px;
      -webkit-overflow-scrolling: touch;
    }
    /* Адначасова сетка для шапкі і радкоў; слупкі ролі/статусу — аднолькавая шырыня ў усіх радкоў */
    .users-sheet {
      --users-cols:
        minmax(9rem, 1.35fr)
        minmax(11.25rem, 13rem)
        minmax(9rem, 10.75rem)
        minmax(10rem, 1.5fr)
        2rem;
      --users-gap: 14px 28px;
      width: 100%;
      min-width: min(100%, 520px);
      font-size: 0.875rem;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      overflow: hidden;
      background: rgba(10, 12, 20, 0.25);
    }
    .users-sheet-head {
      display: grid;
      grid-template-columns: var(--users-cols);
      gap: var(--users-gap);
      align-items: center;
      padding: 12px 20px;
      background: rgba(15, 23, 42, 0.65);
      border-bottom: 1px solid var(--line);
    }
    .users-sheet-head span {
      font-size: 0.65rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      line-height: 1.2;
    }
    .users-sheet-head .users-sheet-h-chev {
      text-align: center;
      font-size: 0.6rem;
      opacity: 0.85;
    }
    .col-login { font-weight: 600; color: var(--text); }
    .cell-role,
    .cell-status {
      justify-self: start;
      white-space: nowrap;
    }
    .cell-sections {
      min-width: 0;
    }
    .user-badges { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center; }
    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
      line-height: 1.2;
    }
    .badge-role-admin {
      background: rgba(196, 163, 90, 0.2);
      color: #e8d5a3;
      border: 1px solid rgba(196, 163, 90, 0.35);
    }
    .badge-role-user {
      background: rgba(124, 108, 240, 0.18);
      color: #c4b5fd;
      border: 1px solid rgba(124, 108, 240, 0.3);
    }
    .badge-on { color: #86efac; border: 1px solid rgba(74, 222, 128, 0.35); background: rgba(22, 163, 74, 0.15); }
    .badge-off { color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.3); background: rgba(127, 29, 29, 0.2); }
    .badge-sec {
      font-size: 0.6875rem;
      font-weight: 500;
      padding: 2px 6px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid var(--line);
      color: #cbd5e1;
    }
    .badge-all { font-size: 0.6875rem; color: #a5b4fc; border-color: rgba(124, 108, 240, 0.35); }
    details.user-edit--in-table {
      margin: 0;
      border: none;
      border-radius: 0;
      background: transparent;
      border-bottom: 1px solid var(--line);
    }
    details.user-edit--in-table:last-child {
      border-bottom: none;
    }
    details.user-edit--in-table[open] {
      background: rgba(15, 23, 42, 0.42);
    }
    details.user-edit--in-table summary.user-edit-summary-grid {
      list-style: none;
      cursor: pointer;
      user-select: none;
      display: grid;
      grid-template-columns: var(--users-cols);
      gap: var(--users-gap);
      align-items: center;
      padding: 12px 20px;
      transition: background 0.12s ease;
    }
    details.user-edit--in-table summary.user-edit-summary-grid:hover {
      background: rgba(124, 108, 240, 0.07);
    }
    details.user-edit--in-table[open] summary.user-edit-summary-grid {
      background: rgba(124, 108, 240, 0.04);
      border-bottom: 1px solid var(--line);
    }
    details.user-edit--in-table summary::-webkit-details-marker { display: none; }
    .user-edit-summary-grid .cell-chev {
      font-size: 0.65rem;
      color: var(--muted);
      text-align: center;
      line-height: 1;
      transition: transform 0.15s ease;
    }
    details.user-edit--in-table[open] summary .cell-chev { transform: rotate(-180deg); }
    details.user-edit--in-table .user-edit-body {
      padding: 18px 14px 22px;
      border-top: none;
    }
    details.user-edit--in-table .user-edit-body select {
      max-width: 22rem;
      width: 100%;
    }
    details.user-edit--in-table .grant-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px 1.25rem;
      align-items: start;
      margin-top: 4px;
      margin-bottom: 4px;
    }
    details.user-edit--in-table .grant-grid > label {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin: 0;
      padding: 6px 0;
      line-height: 1.35;
      font-weight: 500;
    }
    details.user-edit--in-table .grant-grid > label input[type="checkbox"] {
      margin-top: 3px;
      flex-shrink: 0;
    }
    @media (max-width: 560px) {
      .users-sheet {
        --users-cols: minmax(0, 1fr) max-content max-content minmax(0, 1fr) 1.5rem;
        --users-gap: 12px 16px;
      }
      .users-sheet-head,
      details.user-edit--in-table summary.user-edit-summary-grid {
        padding-left: 14px;
        padding-right: 14px;
      }
      details.user-edit--in-table .grant-grid {
        grid-template-columns: 1fr;
      }
    }
    details.user-edit--in-table .user-edit-body form button[type="submit"] {
      margin-top: 1.35rem;
    }
    details.user-edit--in-table .user-edit-body form .btn-mini {
      margin-top: 1.35rem;
    }
    details.user-edit--in-table .user-edit-body > form + form {
      margin-top: 18px;
      padding-top: 18px;
      border-top: 1px dashed rgba(148, 163, 184, 0.22);
    }
    details.user-edit--in-table .form-section-title {
      margin: 14px 0 6px;
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: rgba(148, 163, 184, 0.9);
    }
    details.user-edit--in-table .form-section-title:first-child { margin-top: 0; }
    @media (max-width: 900px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
      .top-nav { justify-content: flex-start; max-width: none; }
      .top-nav-row { justify-content: flex-start; gap: 10px 14px; }
    }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
      .top-nav { justify-content: flex-start; max-width: none; width: 100%; align-items: flex-start; }
      .top-nav-row { justify-content: flex-start; }
    }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-brand">
      <h1>Totus Tuus</h1>
      <p class="header-tagline">Панэль кіравання<br>імя Біскупа Казіміра Велікасельца OP</p>
    </div>
    <?php
        $panelNavPage = 'users';
        $panelNavView = 'categories';
        $panelNavCalYear = (int)date('Y');
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>
  <div id="dynamic-sections">
    <?php if ($message !== null): ?><p class="msg ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="msg err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

    <div class="card">
      <h2>Новы карыстальнік</h2>
      <form method="post"><?= panel_csrf_field() ?>
        <input type="hidden" name="panel_create_user" value="1">
        <label for="new_login">Лагін</label>
        <input id="new_login" name="new_login" type="text" required maxlength="64" pattern="[a-zA-Z0-9._\-]+" autocomplete="off">
        <label for="new_password">Пароль</label>
        <input id="new_password" name="new_password" type="password" required minlength="8" autocomplete="new-password">
        <label for="new_password_confirm">Паўтарыце пароль</label>
        <input id="new_password_confirm" name="new_password_confirm" type="password" required minlength="8" autocomplete="new-password">
        <label for="new_role">Роля</label>
        <select id="new_role" name="new_role">
          <option value="user">Карыстальнік (даступ па раздзелах)</option>
          <option value="admin">Адміністратар (усе раздзелы)</option>
        </select>
        <p class="muted" style="margin-top:14px;margin-bottom:4px;">Раздзелы для ролі «карыстальнік»:</p>
        <div class="grant-grid">
          <?php foreach ($sectionKeys as $sk): ?>
            <label><input type="checkbox" name="new_grant[]" value="<?= htmlspecialchars($sk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"> <?= htmlspecialchars($labels[$sk] ?? $sk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <?php endforeach; ?>
        </div>
        <button type="submit">Стварыць</button>
      </form>
    </div>

    <div class="card">
      <h2>Карыстальнікі</h2>
      <p class="muted" style="margin:0 0 12px;">Націсніце радок карыстальніка, каб раскрыць налады (роля, раздзелы, пароль, выдаленне).</p>
      <?php if (count($usersList) === 0): ?>
        <p class="muted">Няма запісаў.</p>
      <?php else: ?>
        <div class="table-wrap">
          <div class="users-sheet" role="table" aria-label="Спіс карыстальнікаў">
            <div class="users-sheet-head" role="row">
              <span role="columnheader">Лагін</span>
              <span role="columnheader">Роля</span>
              <span role="columnheader">Статус</span>
              <span role="columnheader">Раздзелы</span>
              <span class="users-sheet-h-chev" role="columnheader" aria-hidden="true">▼</span>
            </div>
              <?php foreach ($usersList as $urow): ?>
                <?php
                $uid = (int)$urow['id'];
                $g = $grantsByUser[$uid] ?? [];
                $isAdminRow = (string)$urow['role'] === 'admin';
                $loginEsc = htmlspecialchars((string)$urow['login'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $detailsOpen = ($editFocus === $uid);
                ?>
                    <details class="user-edit user-edit--in-table" id="user-edit-<?= $uid ?>"<?= $detailsOpen ? ' open' : '' ?>>
                      <summary class="user-edit-summary-grid">
                        <span class="cell-login"><?= $loginEsc ?> <span class="muted" style="font-weight:400;font-size:0.8rem;">#<?= $uid ?></span></span>
                        <span class="cell-role">
                          <?php if ($isAdminRow): ?>
                            <span class="badge badge-role-admin">Адмін</span>
                          <?php else: ?>
                            <span class="badge badge-role-user">Карыстальнік</span>
                          <?php endif; ?>
                        </span>
                        <span class="cell-status">
                          <?php if ((int)$urow['is_active']): ?>
                            <span class="badge badge-on">Актыўны</span>
                          <?php else: ?>
                            <span class="badge badge-off">Адключаны</span>
                          <?php endif; ?>
                        </span>
                        <span class="cell-sections">
                          <span class="user-badges">
                            <?php if ($isAdminRow): ?>
                              <span class="badge badge-sec badge-all">Усе раздзелы</span>
                            <?php elseif (count($g) === 0): ?>
                              <span class="muted" style="font-size:0.8125rem;">няма доступу</span>
                            <?php else: ?>
                              <?php foreach ($sectionKeys as $sk): ?>
                                <?php if (in_array($sk, $g, true)): ?>
                                  <span class="badge badge-sec"><?= htmlspecialchars($labelsShort[$sk] ?? $sk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </span>
                        </span>
                        <span class="cell-chev" aria-hidden="true">▼</span>
                      </summary>
                      <div class="user-edit-body">
                        <p class="form-section-title">Роля і раздзелы</p>
                        <form method="post"><?= panel_csrf_field() ?>
                          <input type="hidden" name="panel_save_user" value="1">
                          <input type="hidden" name="edit_user_id" value="<?= $uid ?>">
                          <label>Роля</label>
                          <select name="edit_role">
                            <option value="user"<?= (string)$urow['role'] === 'user' ? ' selected' : '' ?>>Карыстальнік</option>
                            <option value="admin"<?= (string)$urow['role'] === 'admin' ? ' selected' : '' ?>>Адміністратар</option>
                          </select>
                          <label class="user-active-row">
                            <input type="checkbox" name="edit_is_active" value="1"<?= (int)$urow['is_active'] ? ' checked' : '' ?>> Актыўны
                          </label>
                          <p class="muted" style="margin-top:12px;margin-bottom:4px;">Раздзелы (для карыстальніка; у адміна ўсе раздзелы без абмежаванняў):</p>
                          <div class="grant-grid">
                            <?php foreach ($sectionKeys as $sk): ?>
                              <label><input type="checkbox" name="edit_grant[]" value="<?= htmlspecialchars($sk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= in_array($sk, $g, true) ? ' checked' : '' ?>> <?= htmlspecialchars($labels[$sk] ?? $sk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                            <?php endforeach; ?>
                          </div>
                          <button type="submit" class="btn-mini secondary">Захаваць змены</button>
                        </form>
                        <p class="form-section-title">Пароль</p>
                        <form method="post"><?= panel_csrf_field() ?>
                          <input type="hidden" name="panel_reset_password" value="1">
                          <input type="hidden" name="pwd_user_id" value="<?= $uid ?>">
                          <label>Новы пароль</label>
                          <input name="pwd_new" type="password" minlength="8" required autocomplete="new-password">
                          <label>Паўтор пароля</label>
                          <input name="pwd_new_confirm" type="password" minlength="8" required autocomplete="new-password">
                          <button type="submit" class="btn-mini secondary">Змяніць пароль</button>
                        </form>
                        <?php if ($uid !== (int)(panel_current_user()['id'] ?? 0)): ?>
                        <p class="form-section-title">Небяспечныя дзеянні</p>
                        <form method="post" onsubmit="return confirm('Выдаліць карыстальніка?');"><?= panel_csrf_field() ?>
                          <input type="hidden" name="panel_delete_user" value="1">
                          <input type="hidden" name="delete_user_id" value="<?= $uid ?>">
                          <button type="submit" class="btn-mini danger">Выдаліць уліковы запіс</button>
                        </form>
                        <?php endif; ?>
                      </div>
                    </details>
              <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
(function () {
  var h = location.hash;
  if (!h || !/^#user-edit-\d+$/.test(h)) return;
  var el = document.querySelector(h);
  if (el && el.tagName === 'DETAILS') {
    el.open = true;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
})();
  </script>
</body>
</html>
