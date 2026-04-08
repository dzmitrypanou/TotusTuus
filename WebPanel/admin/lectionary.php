<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel_security.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/schema.php';
require_once __DIR__ . '/../includes/panel_auth.php';
require_once __DIR__ . '/../api/liturgy_common.php';
require_once __DIR__ . '/../api/liturgy_observances_lib.php';
require_once __DIR__ . '/../api/liturgy_particular_calendar.php';

panel_configure_session_before_start();
session_start();
panel_ensure_csrf_token();
panel_send_admin_security_headers();

if (!panel_is_logged_in()) {
    header('Location: /', true, 302);
    exit;
}

ensureSchemaAndSeed();
panel_require_section_get('lectionary');

$colorLabels = [
    'green' => 'зялёны',
    'red' => 'чырвоны',
    'purple' => 'фіялетавы',
    'white' => 'белы',
    'rose' => 'ружовы',
    'black' => 'чорны',
];

$message = null;
$error = null;
$isAjaxRequest = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
    || (($_POST['ajax'] ?? '') === '1');

function lectionary_ajax_response(bool $ok, string $messageText = '', string $errorText = '', string $redirectUrl = ''): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $messageText,
        'error' => $errorText,
        'redirect' => $redirectUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ajaxRedirect = '';
    if (!panel_csrf_token_valid()) {
        $error = 'Сесія пратэрмінаваная або токен несапраўдны. Абнавіце старонку.';
    } else {
        $editId = (int)($_POST['lectionary_id'] ?? 0);
        $isDelete = isset($_POST['delete_lectionary_entry']);
        if ($isDelete) {
            if ($editId <= 0) {
                $error = 'Няма запісу для выдалення.';
            } else {
                try {
                    $stmt = db()->prepare('DELETE FROM liturgy_lectionary_entries WHERE id = :id');
                    $stmt->execute([':id' => $editId]);
                    $message = 'Запіс лекцыянарыя выдалены.';
                    $ajaxRedirect = '/admin/lectionary.php';
                } catch (Throwable $e) {
                    $error = 'Памылка выдалення: ' . $e->getMessage();
                }
            }
        } elseif (isset($_POST['save_lectionary_entry'])) {
            $title = trim((string)($_POST['lectionary_title'] ?? ''));
            $textHtml = trim((string)($_POST['lectionary_text_html'] ?? ''));
            $liturgicalColorRaw = trim((string)($_POST['lectionary_liturgical_color'] ?? ''));
            $liturgicalColor = liturgy_normalize_liturgical_color_string($liturgicalColorRaw);
            $lookupKey = liturgy_normalize_lectionary_key($title);

            if ($title === '' || $textHtml === '') {
                $error = 'Патрэбныя абавязковыя палі: назва і тэкст.';
            } elseif ($lookupKey === '') {
                $error = 'Назва павінна мець хоць адзін сімвал.';
            } else {
                try {
                    if ($editId > 0) {
                        $stmt = db()->prepare(
                            'UPDATE liturgy_lectionary_entries
                             SET title = :title,
                                 lookup_key = :lookup_key,
                                 text_html = :text_html,
                                 liturgical_color = :liturgical_color,
                                 is_active = 1
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            ':id' => $editId,
                            ':title' => $title,
                            ':lookup_key' => $lookupKey,
                            ':text_html' => $textHtml,
                            ':liturgical_color' => $liturgicalColor,
                        ]);
                        $ajaxRedirect = '/admin/lectionary.php?edit_id=' . $editId;
                    } else {
                        $stmt = db()->prepare(
                            'INSERT INTO liturgy_lectionary_entries (title, lookup_key, text_html, liturgical_color, is_active)
                             VALUES (:title, :lookup_key, :text_html, :liturgical_color, 1)
                             ON DUPLICATE KEY UPDATE
                                title = VALUES(title),
                                text_html = VALUES(text_html),
                                liturgical_color = VALUES(liturgical_color),
                                is_active = 1'
                        );
                        $stmt->execute([
                            ':title' => $title,
                            ':lookup_key' => $lookupKey,
                            ':text_html' => $textHtml,
                            ':liturgical_color' => $liturgicalColor,
                        ]);
                        $stmtSaved = db()->prepare(
                            'SELECT id
                             FROM liturgy_lectionary_entries
                             WHERE lookup_key = :lookup_key
                             LIMIT 1'
                        );
                        $stmtSaved->execute([':lookup_key' => $lookupKey]);
                        $savedId = (int)($stmtSaved->fetch()['id'] ?? 0);
                        if ($savedId > 0) {
                            $ajaxRedirect = '/admin/lectionary.php?edit_id=' . $savedId;
                        }
                    }
                    $message = 'Запіс лекцыянарыя захаваны.';
                } catch (Throwable $e) {
                    $error = 'Памылка захавання: ' . $e->getMessage();
                }
            }
        }
    }
    if ($isAjaxRequest && !isset($_POST['logout'])) {
        lectionary_ajax_response($error === null, $message ?? '', $error ?? '', $ajaxRedirect);
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$editId = (int)($_GET['edit_id'] ?? ($_POST['lectionary_id'] ?? 0));
$prefillTitle = trim((string)($_GET['prefill_title'] ?? ''));

if ($search !== '') {
    $stmtList = db()->prepare(
        'SELECT id, title, lookup_key, updated_at
         FROM liturgy_lectionary_entries
         WHERE is_active = 1
           AND (title LIKE :q OR lookup_key LIKE :q)
         ORDER BY title ASC'
    );
    $stmtList->execute([':q' => '%' . $search . '%']);
} else {
    $stmtList = db()->query(
        'SELECT id, title, lookup_key, updated_at
         FROM liturgy_lectionary_entries
         WHERE is_active = 1
         ORDER BY title ASC'
    );
}
$entries = $stmtList->fetchAll();

$editEntry = null;
if ($editId > 0) {
    $stmtEdit = db()->prepare(
        'SELECT id, title, lookup_key, text_html, liturgical_color, updated_at
         FROM liturgy_lectionary_entries
         WHERE id = :id
         LIMIT 1'
    );
    $stmtEdit->execute([':id' => $editId]);
    $row = $stmtEdit->fetch();
    if (is_array($row)) {
        $editEntry = $row;
    }
}

$currentTitle = is_array($editEntry) ? (string)($editEntry['title'] ?? '') : '';
$currentText = is_array($editEntry) ? (string)($editEntry['text_html'] ?? '') : '';
$currentLiturgicalColor = is_array($editEntry)
    ? liturgy_normalize_liturgical_color_string((string)($editEntry['liturgical_color'] ?? ''))
    : '';
if ($currentTitle === '' && $prefillTitle !== '') {
    $currentTitle = $prefillTitle;
}

$dioceseLabels = [
    LITURGY_DIOCESE_PINSK => 'Пінская',
    LITURGY_DIOCESE_MINSK_MOGILEV => 'Мінск-Магілёў',
    LITURGY_DIOCESE_VITEBSK => 'Віцебская',
    LITURGY_DIOCESE_GRODNO => 'Гродзенская',
];

$obsFilterApplied = isset($_GET['obs_filter']);
$obsYear = (int)($_GET['obs_year'] ?? date('Y'));
if ($obsYear < 1970 || $obsYear > 2100) {
    $obsYear = (int)date('Y');
}
$obsHideNonDiocesan = isset($_GET['obs_hide_general']) && (string)$_GET['obs_hide_general'] === '1';

$dioceseOpts = liturgy_diocese_options_default();
if ($obsFilterApplied) {
    $dGet = $_GET['d'] ?? [];
    if (!is_array($dGet)) {
        $dGet = [];
    }
    foreach (liturgy_diocese_keys() as $dk) {
        $dioceseOpts[$dk] = isset($dGet[$dk]) && (string)$dGet[$dk] === '1';
    }
} else {
    foreach (liturgy_diocese_keys() as $dk) {
        $dioceseOpts[$dk] = true;
    }
}

/** @var array<string, true> */
$lectionaryKeysWithText = [];
$lkStmt = db()->query(
    'SELECT lookup_key, title, text_html
     FROM liturgy_lectionary_entries
     WHERE is_active = 1 AND text_html IS NOT NULL'
);
while (is_array($row = $lkStmt->fetch())) {
    if (trim(strip_tags((string)($row['text_html'] ?? ''))) === '') {
        continue;
    }
    foreach (['lookup_key', 'title'] as $field) {
        $raw = trim((string)($row[$field] ?? ''));
        if ($raw === '') {
            continue;
        }
        $nk = liturgy_normalize_lectionary_key($raw);
        if ($nk !== '') {
            $lectionaryKeysWithText[$nk] = true;
        }
    }
}

$observancesMissingLectionary = [];
$easterObs = liturgy_observances_easter_sunday($obsYear);
foreach (liturgy_observances_fetch_active_rows() as $obsRow) {
    if ((string)($obsRow['observance_kind'] ?? '') !== 'optional') {
        continue;
    }
    if (!liturgy_observances_row_matches_diocese($obsRow, $dioceseOpts)) {
        continue;
    }
    $reqAny = trim((string)($obsRow['require_any_of'] ?? ''));
    $reqAll = trim((string)($obsRow['require_all_of'] ?? ''));
    $forbid = trim((string)($obsRow['forbid_if_any_of'] ?? ''));
    if ($obsHideNonDiocesan && $reqAny === '' && $reqAll === '' && $forbid === '') {
        continue;
    }
    $ymd = liturgy_observances_resolve_ymd($obsRow, $obsYear, $easterObs);
    if ($ymd === null) {
        continue;
    }
    $obsTitle = trim((string)($obsRow['title'] ?? ''));
    if ($obsTitle === '') {
        continue;
    }
    $matched = false;
    foreach ([$obsTitle, liturgy_strip_cycle_suffix($obsTitle)] as $t) {
        $nk = liturgy_normalize_lectionary_key($t);
        if ($nk !== '' && isset($lectionaryKeysWithText[$nk])) {
            $matched = true;
            break;
        }
    }
    if ($matched) {
        continue;
    }
    $observancesMissingLectionary[] = [
        'id' => (int)($obsRow['id'] ?? 0),
        'date' => $ymd,
        'title' => $obsTitle,
        'require_any_of' => $reqAny,
        'require_all_of' => $reqAll,
        'forbid_if_any_of' => $forbid,
        'source_tag' => trim((string)($obsRow['source_tag'] ?? '')),
    ];
}
usort($observancesMissingLectionary, static function (array $a, array $b): int {
    $c = strcmp($a['date'], $b['date']);
    if ($c !== 0) {
        return $c;
    }

    return strcmp($a['title'], $b['title']);
});
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Лекцыянарый — Totus Tuus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,500&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
  <style>
    :root {
      --text: #e2e8f0;
      --muted: #94a3b8;
      --line: rgba(148, 163, 184, 0.22);
      --bg-deep: #0a0c14;
      --bg-mid: #12182a;
      --bg-glow: #1a2240;
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
      min-height: 100vh;
      background:
        radial-gradient(ellipse 120% 80% at 100% -20%, rgba(124, 108, 240, 0.22), transparent 50%),
        radial-gradient(ellipse 90% 60% at -10% 50%, rgba(196, 163, 90, 0.08), transparent 45%),
        linear-gradient(165deg, var(--bg-deep) 0%, var(--bg-mid) 42%, var(--bg-glow) 100%);
      background-attachment: fixed;
      color: var(--text);
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
      margin-bottom: 16px;
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
    .header-brand h1 {
      text-align: center;
    }
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
    .nav-group { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
    .nav-group-label {
      font-size: 0.625rem;
      font-weight: 700;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: rgba(148, 163, 184, 0.85);
      line-height: 1;
    }
    .nav-group-items { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .nav-group-items form { display: inline; margin: 0; }
    a.btn-pill, button.btn-pill {
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
    .grid { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 14px; }
    .grid-full { grid-column: 1 / -1; }
    .card { background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 14px; overflow: hidden; }
    .table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .table th, .table td { border-bottom: 1px solid #273449; padding: 7px 6px; text-align: left; vertical-align: top; }
    .table tr:last-child td { border-bottom: none; }
    .muted { color: #94a3b8; font-size: 13px; }
    label { display: block; margin-top: 10px; margin-bottom: 4px; font-size: 13px; color: #cbd5e1; font-weight: 600; }
    input[type="text"], input[type="search"], input[type="number"], select, textarea {
      width: 100%;
      border: 1px solid #334155;
      background: #0f172a;
      color: #e2e8f0;
      border-radius: 10px;
      padding: 10px 11px;
      font: inherit;
    }
    select:not([multiple]) {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      padding: 10px 40px 10px 11px;
      background-color: #0f172a;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%2394a3b8' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      background-size: 14px 14px;
      cursor: pointer;
    }
    select:not([multiple]):focus {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%23cbd5e1' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E");
    }
    option { background: #111827; color: #e2e8f0; }
    .search-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: end; margin-bottom: 10px; }
    .obs-filter-row { display: flex; flex-wrap: wrap; gap: 12px 18px; align-items: flex-end; margin-bottom: 12px; }
    .obs-filter-row .field-year { min-width: 120px; flex: 0 0 auto; }
    .obs-filter-row .field-year label { margin-top: 0; }
    .diocese-checkboxes { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
    label.diocese-cb { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-weight: 600; cursor: pointer; }
    label.diocese-cb input { width: auto; margin: 0; }
    label.obs-hide-general-cb { flex-basis: 100%; margin-top: 4px; max-width: 42rem; }
    button { border: 1px solid #334155; background: #7c6cf0; color: #fff; font-weight: 700; border-radius: 10px; padding: 10px 12px; cursor: pointer; }
    .danger { background: #7f1d1d; border-color: #b91c1c; }
    .actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
    .rich-editor-wrap {
      border: 1px solid #334155;
      border-radius: 10px;
      background: #0b1224;
      overflow: hidden;
    }
    .rich-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      padding: 8px;
      border-bottom: 1px solid #334155;
      background: #0f172a;
    }
    .rich-toolbar-group { display: flex; align-items: center; gap: 6px; }
    .rich-toolbar-label { color: #94a3b8; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
    .rich-btn {
      border: 1px solid #334155;
      background: #1e293b;
      color: #e2e8f0;
      border-radius: 8px;
      padding: 6px 9px;
      line-height: 1;
      font-size: 13px;
      font-weight: 700;
    }
    .rich-color-picker-wrap { position: relative; }
    .rich-color-toggle {
      width: 26px;
      height: 26px;
      border-radius: 8px;
      border: 1px solid #475569;
      padding: 0;
      background: #fff;
    }
    .rich-color-dropdown {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      display: none;
      grid-template-columns: repeat(6, 20px);
      gap: 6px;
      padding: 8px;
      border-radius: 10px;
      border: 1px solid #334155;
      background: #0b1224;
      z-index: 20;
      width: max-content;
    }
    .rich-color-picker-wrap.open .rich-color-dropdown { display: grid; }
    .rich-color-swatch {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.35);
      padding: 0;
      cursor: pointer;
    }
    .rich-color-swatch.active { outline: 2px solid #f8fafc; outline-offset: 1px; }
    .rich-editor {
      min-height: 420px;
      padding: 12px;
      color: #e2e8f0;
      background: #0b1224;
      outline: none;
      white-space: normal;
      line-height: 1.3;
    }
    .rich-editor p {
      margin: 0 0 0.35em;
    }
    .rich-editor p:last-child {
      margin-bottom: 0;
    }
    .rich-editor-hidden { display: none; }
    .spinner {
      display: inline-block;
      width: 14px;
      height: 14px;
      border: 2px solid rgba(255, 255, 255, 0.35);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      vertical-align: text-bottom;
      margin-left: 8px;
    }
    .toast-wrap { position: fixed; top: 16px; right: 16px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
    .toast {
      min-width: 240px;
      max-width: 360px;
      padding: 12px 14px;
      border-radius: var(--radius-sm);
      color: #fff;
      box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
      border: 1px solid rgba(255, 255, 255, 0.1);
      animation: fadeIn 0.2s ease;
    }
    .toast.ok { background: linear-gradient(135deg, #15803d, #22c55e); }
    .toast.err { background: linear-gradient(135deg, #b91c1c, #ef4444); }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
      .top-nav { justify-content: flex-start; max-width: none; width: 100%; align-items: flex-start; }
      .top-nav-row { justify-content: flex-start; gap: 10px 14px; }
    }
    @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-brand">
      <h1>Totus Tuus</h1>
      <p class="header-tagline">Панэль кіравання<br>імя Біскупа Казіміра Велікасельца OP</p>
    </div>
    <?php
        $panelNavPage = 'lectionary';
        $panelNavView = 'categories';
        $panelNavCalYear = $obsYear;
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <div class="grid">
    <div class="card grid-full">
      <h2 style="margin:0 0 8px; font-size:1rem;">Святы з БД без чытанняў (варынт «альбо»)</h2>
      <p class="muted" style="margin-top:0;">Радкі <code>optional</code> з <code>liturgy_observances</code>, якія трапяюць у каляндар пры абраных дыяцэзіях, але не маюць непустога тэксту ў лекцыянарыі па ключы назвы. Ключ збіраецца як для поля «Назва» запісу лекцыянарыя.</p>
      <form method="get" class="obs-filter-form">
        <input type="hidden" name="obs_filter" value="1">
        <?php if ($search !== ''): ?>
          <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endif; ?>
        <?php if ($editId > 0): ?>
          <input type="hidden" name="edit_id" value="<?= $editId ?>">
        <?php endif; ?>
        <div class="obs-filter-row">
          <div class="field-year">
            <label for="obs_year">Год</label>
            <input id="obs_year" type="number" name="obs_year" min="1970" max="2100" value="<?= $obsYear ?>">
          </div>
          <div>
            <span class="nav-group-label" style="display:block;margin-bottom:6px;">Дыяцэзіі (для ўзору «хто ўкліканы»)</span>
            <div class="diocese-checkboxes">
              <?php foreach (liturgy_diocese_keys() as $dk): ?>
                <label class="diocese-cb">
                  <input type="checkbox" name="d[<?= htmlspecialchars($dk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]" value="1" <?= !empty($dioceseOpts[$dk]) ? 'checked' : '' ?>>
                  <?= htmlspecialchars((string)($dioceseLabels[$dk] ?? $dk), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </label>
              <?php endforeach; ?>
            </div>
            <label class="diocese-cb obs-hide-general-cb">
              <input type="checkbox" name="obs_hide_general" value="1" <?= $obsHideNonDiocesan ? 'checked' : '' ?>>
              Схаваць агульныя (без умоў any/all/forbid для дыяцэзій у БД)
            </label>
          </div>
          <div>
            <label style="margin-top:0;visibility:hidden;" for="obs-filter-submit">Дзеянне</label>
            <button type="submit" id="obs-filter-submit">Паказаць</button>
          </div>
        </div>
      </form>
      <p class="muted" style="margin:0 0 8px;">Без прадпісаных чытанняў у лекцыянарыі: <strong><?= count($observancesMissingLectionary) ?></strong>.
        <?php if (!$obsFilterApplied): ?><span class="muted"> Па змаўчанні ўсе дыяцэзіі ўлічаны як уключаныя; адмяніце непатрэбныя і націсніце «Паказаць», каб звузіць спіс.</span><?php endif; ?>
      </p>
      <div class="table-wrap" style="overflow-x:auto;">
        <table class="table">
          <thead>
          <tr>
            <th style="width:104px;">Дата</th>
            <th style="width:56px;">БД</th>
            <th>Назва</th>
            <th style="width:140px;">Умовы дыяцэзій</th>
            <th style="width:200px;"></th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($observancesMissingLectionary as $om): ?>
            <tr>
              <td><code><?= htmlspecialchars($om['date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
              <td><?= (int)$om['id'] ?></td>
              <td><?= htmlspecialchars($om['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td class="muted" style="font-size:12px;"><?php
                $bits = array_filter([
                    $om['require_any_of'] !== '' ? 'any: ' . $om['require_any_of'] : '',
                    $om['require_all_of'] !== '' ? 'all: ' . $om['require_all_of'] : '',
                    $om['forbid_if_any_of'] !== '' ? '!: ' . $om['forbid_if_any_of'] : '',
                ]);
                echo htmlspecialchars(implode('; ', $bits), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                ?></td>
              <td>
                <?php
                $prefillQ = [
                    'prefill_title' => $om['title'],
                    'obs_filter' => '1',
                    'obs_year' => (string)$obsYear,
                ];
                $dSub = [];
                foreach (liturgy_diocese_keys() as $dk) {
                    if (!empty($dioceseOpts[$dk])) {
                        $dSub[$dk] = '1';
                    }
                }
                if ($dSub !== []) {
                    $prefillQ['d'] = $dSub;
                }
                if ($search !== '') {
                    $prefillQ['q'] = $search;
                }
                if ($obsHideNonDiocesan) {
                    $prefillQ['obs_hide_general'] = '1';
                }
                $prefillHref = '/admin/lectionary.php?' . http_build_query($prefillQ);
                ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                  <a class="btn-pill" href="<?= htmlspecialchars($prefillHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Стварыць чытанне</a>
                  <a class="btn-pill" href="/admin/liturgy_observances.php?edit=<?= (int)$om['id'] ?>">Свята БД</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($observancesMissingLectionary === []): ?>
            <tr><td colspan="5" class="muted">Прагалаў для абраных умоў няма (або ўсе маюць тэкст у лекцыянарыі).</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 8px; font-size:1rem;">Запісы лекцыянарыя</h2>
      <p class="muted" style="margin-top:0;">Падбор у каляндары ідзе па назве дня і, калі ёсць, па назве «успаміну».</p>
      <form method="get" class="search-row">
        <?php if ($obsHideNonDiocesan): ?>
          <input type="hidden" name="obs_hide_general" value="1">
        <?php endif; ?>
        <?php if ($obsFilterApplied): ?>
          <input type="hidden" name="obs_filter" value="1">
          <input type="hidden" name="obs_year" value="<?= (int)$obsYear ?>">
          <?php foreach (liturgy_diocese_keys() as $dk): ?>
            <?php if (!empty($dioceseOpts[$dk])): ?>
              <input type="hidden" name="d[<?= htmlspecialchars($dk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]" value="1">
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
        <div>
          <label for="q" style="margin-top:0;">Пошук</label>
          <input id="q" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Частка назвы">
        </div>
        <div>
          <button type="submit">Знайсці</button>
        </div>
      </form>
      <table class="table">
        <thead>
        <tr>
          <th style="width:72px;">ID</th>
          <th>Назва</th>
          <th style="width:96px;"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $row): ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td>
              <strong><?= htmlspecialchars((string)$row['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
              <div class="muted"><code><?= htmlspecialchars((string)$row['lookup_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></div>
            </td>
            <td><a class="btn-pill" href="/admin/lectionary.php?edit_id=<?= (int)$row['id'] ?>">Адкрыць</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
          <tr><td colspan="3" class="muted">Нічога не знойдзена.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2 style="margin:0 0 8px; font-size:1rem;"><?= $editEntry !== null ? 'Рэдагаванне запісу' : 'Новы запіс' ?></h2>
      <form method="post" id="lectionary-form" class="js-ajax-form" data-refresh="1">
        <?= panel_csrf_field() ?>
        <input type="hidden" name="lectionary_id" value="<?= (int)($editEntry['id'] ?? 0) ?>">

        <label for="lectionary_title">Назва *</label>
        <input id="lectionary_title" type="text" name="lectionary_title" value="<?= htmlspecialchars($currentTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
        <p class="muted" style="margin:6px 0 0;">
          Для прывязкі да канкрэтнай даты (як «альбо» у дадатку) выкарыстоўвайце фармат:
          <strong>ДД.ММ - Назва свята</strong> (напрыклад, <strong>14.09 - Успамін ...</strong>).
        </p>

        <label for="lectionary_liturgical_color">Колер літургічнага дня</label>
        <select id="lectionary_liturgical_color" name="lectionary_liturgical_color">
          <option value="" <?= $currentLiturgicalColor === '' ? 'selected' : '' ?>>Сістэмны (па сезоне / календары)</option>
          <?php foreach (['green', 'red', 'purple', 'white', 'rose', 'black'] as $c): ?>
            <option value="<?= htmlspecialchars($c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $currentLiturgicalColor === $c ? 'selected' : '' ?>><?= htmlspecialchars((string)($colorLabels[$c] ?? $c), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <p class="muted" style="margin:6px 0 0;">Ужываецца, калі гэты запіс дае чытанні для дня: мае прыярытэт над аўтакалерам, але саступае падмене колеру ў «Календары».</p>

        <label for="lectionary_text_html">Тэкст чытання (HTML) *</label>
        <div class="rich-editor-wrap">
          <div class="rich-toolbar">
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="bold"><b>B</b></button>
              <button type="button" class="rich-btn" data-cmd="italic"><i>I</i></button>
              <button type="button" class="rich-btn" data-cmd="underline"><u>U</u></button>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="insertUnorderedList">• List</button>
              <button type="button" class="rich-btn" data-cmd="insertOrderedList">1. List</button>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="justifyLeft" title="Па левым краі">L</button>
              <button type="button" class="rich-btn" data-cmd="justifyCenter" title="Па цэнтры">C</button>
              <button type="button" class="rich-btn" data-cmd="justifyRight" title="Па правым краі">R</button>
              <button type="button" class="rich-btn" data-cmd="justifyFull" title="Па шырыні">J</button>
            </div>
            <div class="rich-toolbar-group" title="Колер тэксту">
              <span class="rich-toolbar-label">Колер</span>
              <div class="rich-color-picker-wrap">
                <button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;"></button>
                <div class="rich-color-dropdown" role="group" aria-label="Колер тэксту">
                  <button type="button" class="rich-color-swatch" data-color="#000000" style="background:#000000;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#374151" style="background:#374151;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#6b7280" style="background:#6b7280;"></button>
                  <button type="button" class="rich-color-swatch rich-color-swatch--white active" data-color="#ffffff" style="background:#ffffff;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#b91c1c" style="background:#b91c1c;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#ef4444" style="background:#ef4444;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#f97316" style="background:#f97316;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#eab308" style="background:#eab308;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#22c55e" style="background:#22c55e;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#14b8a6" style="background:#14b8a6;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#2563eb" style="background:#2563eb;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#9333ea" style="background:#9333ea;"></button>
                </div>
              </div>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="formatBlock" data-value="h3">Загаловак</button>
              <button type="button" class="rich-btn" data-action="clear-font">Скінуць шрыфт</button>
              <button type="button" class="rich-btn" data-cmd="removeFormat">Ачысціць</button>
            </div>
          </div>
          <div id="lectionary_text_html_editor" class="rich-editor js-rich-editor" data-target-id="lectionary_text_html" data-initial-html="<?= htmlspecialchars((string)base64_encode($currentText), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" contenteditable="true"></div>
        </div>
        <textarea id="lectionary_text_html" class="rich-editor-hidden" name="lectionary_text_html" required><?= htmlspecialchars($currentText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

        <div class="actions">
          <button type="submit" name="save_lectionary_entry" value="1">Захаваць</button>
          <?php if ($editEntry !== null): ?>
            <button type="submit" class="danger" name="delete_lectionary_entry" value="1" onclick="return confirm('Выдаліць гэты запіс?')">Выдаліць</button>
            <a href="/admin/lectionary.php" class="btn-pill">Новы запіс</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div id="toast-wrap" class="toast-wrap"></div>
  <script>
    var initialMessage = <?= json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var initialError = <?= json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function showToast(type, text) {
      var wrap = document.getElementById('toast-wrap');
      if (!wrap || !text) return;
      var el = document.createElement('div');
      el.className = 'toast ' + (type === 'ok' ? 'ok' : 'err');
      el.textContent = text;
      wrap.appendChild(el);
      window.setTimeout(function () {
        if (el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }, 4200);
    }

    async function refreshLectionaryView(targetUrl) {
      var fetchUrl = targetUrl || (window.location.pathname + window.location.search);
      var response = await fetch(fetchUrl, { credentials: 'same-origin' });
      if (!response.ok) {
        throw new Error('refresh_failed');
      }
      var html = await response.text();
      var parser = new DOMParser();
      var doc = parser.parseFromString(html, 'text/html');
      var nextGrid = doc.querySelector('.grid');
      var currentGrid = document.querySelector('.grid');
      if (!nextGrid || !currentGrid || !currentGrid.parentNode) {
        throw new Error('grid_not_found');
      }
      currentGrid.parentNode.replaceChild(nextGrid, currentGrid);
      if (targetUrl) {
        history.replaceState(null, '', targetUrl);
      }
      initRichEditors();
      bindLectionaryForm();
    }

    function decodeBase64Unicode(value) {
      if (!value) return '';
      try {
        return decodeURIComponent(Array.prototype.map.call(atob(value), function (c) {
          return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
      } catch (e) {
        return '';
      }
    }

    function initRichEditors() {
      document.querySelectorAll('.js-rich-editor').forEach(function (editor) {
        if (editor.dataset.editorBound === '1') return;
        editor.dataset.editorBound = '1';
        var targetId = editor.getAttribute('data-target-id');
        if (!targetId) return;
        var hiddenField = document.getElementById(targetId);
        if (!hiddenField) return;
        var initialEncoded = editor.getAttribute('data-initial-html');
        if (initialEncoded && editor.innerHTML.trim() === '') {
          editor.innerHTML = decodeBase64Unicode(initialEncoded);
        } else if (hiddenField.value && editor.innerHTML.trim() === '') {
          editor.innerHTML = hiddenField.value;
        }
        if (editor.innerHTML.trim() === '') editor.innerHTML = '<p></p>';
        hiddenField.value = editor.innerHTML.trim();

        var wrap = editor.closest('.rich-editor-wrap');
        if (!wrap) return;
        function runCommand(cmd, value) {
          editor.focus();
          try {
            document.execCommand(cmd, false, value || null);
          } catch (e) {
            return;
          }
          hiddenField.value = editor.innerHTML.trim();
        }
        function clearFontStyles() {
          var temp = document.createElement('div');
          temp.innerHTML = editor.innerHTML;
          temp.querySelectorAll('[style]').forEach(function (el) {
            var styleValue = el.getAttribute('style') || '';
            var cleaned = styleValue
              .split(';')
              .map(function (rule) { return rule.trim(); })
              .filter(function (rule) { return rule !== ''; })
              .filter(function (rule) {
                var prop = rule.split(':')[0].trim().toLowerCase();
                return [
                  'font',
                  'font-family',
                  'font-size',
                  'font-style',
                  'font-weight',
                  'line-height',
                  'letter-spacing',
                  'word-spacing'
                ].indexOf(prop) === -1;
              })
              .join('; ');
            if (cleaned) {
              el.setAttribute('style', cleaned + ';');
            } else {
              el.removeAttribute('style');
            }
          });
          temp.querySelectorAll('font').forEach(function (fontEl) {
            var parent = fontEl.parentNode;
            while (fontEl.firstChild) {
              parent.insertBefore(fontEl.firstChild, fontEl);
            }
            parent.removeChild(fontEl);
          });
          editor.innerHTML = temp.innerHTML;
          hiddenField.value = editor.innerHTML.trim();
          editor.focus();
        }
        function normalizePastedFragment(fragmentRoot) {
          if (!fragmentRoot) return;
          fragmentRoot.querySelectorAll('style, link, meta').forEach(function (el) {
            if (el.parentNode) el.parentNode.removeChild(el);
          });
          fragmentRoot.querySelectorAll('mark').forEach(function (markEl) {
            var parent = markEl.parentNode;
            while (markEl.firstChild) {
              parent.insertBefore(markEl.firstChild, markEl);
            }
            parent.removeChild(markEl);
          });
          var FONT_RESET_PROPS = [
            'font',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'line-height',
            'letter-spacing',
            'word-spacing',
            'background',
            'background-color',
            'background-image',
            'text-highlight-color',
            'mso-highlight'
          ];
          var walker = document.createTreeWalker(fragmentRoot, NodeFilter.SHOW_ELEMENT, null);
          var nodes = [];
          while (walker.nextNode()) {
            nodes.push(walker.currentNode);
          }
          nodes.forEach(function (el) {
            if (el.tagName && el.tagName.toLowerCase() === 'font') {
              var parent = el.parentNode;
              while (el.firstChild) {
                parent.insertBefore(el.firstChild, el);
              }
              parent.removeChild(el);
              return;
            }
            FONT_RESET_PROPS.forEach(function (prop) {
              try { el.style.removeProperty(prop); } catch (e) {}
            });
            try { el.style.setProperty('color', '#ffffff', 'important'); } catch (e) {}
            try { el.style.setProperty('background', 'transparent', 'important'); } catch (e) {}
            try { el.style.setProperty('background-color', 'transparent', 'important'); } catch (e) {}
            if (el.getAttribute && el.hasAttribute('class')) {
              el.removeAttribute('class');
            }
            if (el.getAttribute && el.hasAttribute('id')) {
              el.removeAttribute('id');
            }
            if (el.getAttribute && el.hasAttribute('color')) {
              el.removeAttribute('color');
            }
            if (el.getAttribute && el.hasAttribute('face')) {
              el.removeAttribute('face');
            }
            if (el.getAttribute && el.hasAttribute('size')) {
              el.removeAttribute('size');
            }
            if (el.getAttribute && el.hasAttribute('bgcolor')) {
              el.removeAttribute('bgcolor');
            }
          });
        }
        function insertNormalizedPaste(html, plainText) {
          var container = document.createElement('div');
          if (html && html.trim() !== '') {
            container.innerHTML = html;
          } else {
            var paragraphs = (plainText || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
            container.innerHTML = paragraphs.map(function (line) {
              var escaped = line
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
              return '<p>' + (escaped || '<br>') + '</p>';
            }).join('');
          }
          normalizePastedFragment(container);
          runCommand('insertHTML', container.innerHTML);
        }
        function setActiveColor(color) {
          var normalized = (color || '').toLowerCase();
          wrap.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
            var swatchColor = (swatch.getAttribute('data-color') || '').toLowerCase();
            swatch.classList.toggle('active', swatchColor === normalized);
          });
          wrap.querySelectorAll('.rich-color-toggle').forEach(function (toggle) {
            toggle.style.background = color;
            toggle.setAttribute('data-color', color);
          });
        }

        wrap.querySelectorAll('.rich-btn').forEach(function (button) {
          button.addEventListener('click', function () {
            if (button.getAttribute('data-action') === 'clear-font') {
              clearFontStyles();
              return;
            }
            var cmd = button.getAttribute('data-cmd');
            if (!cmd) return;
            runCommand(cmd, button.getAttribute('data-value'));
          });
        });

        wrap.querySelectorAll('.rich-color-picker-wrap').forEach(function (pickerWrap) {
          var toggle = pickerWrap.querySelector('.rich-color-toggle');
          if (toggle) {
            toggle.addEventListener('click', function () {
              pickerWrap.classList.toggle('open');
            });
          }
          pickerWrap.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
            swatch.addEventListener('mousedown', function (event) { event.preventDefault(); });
            swatch.addEventListener('click', function () {
              var color = swatch.getAttribute('data-color');
              runCommand('foreColor', color);
              setActiveColor(color);
              pickerWrap.classList.remove('open');
            });
          });
        });

        editor.addEventListener('input', function () {
          hiddenField.value = editor.innerHTML.trim();
        });
        editor.addEventListener('paste', function (event) {
          event.preventDefault();
          var data = event.clipboardData || window.clipboardData;
          if (!data) {
            return;
          }
          var html = '';
          var text = '';
          try { html = data.getData('text/html') || ''; } catch (e) {}
          try { text = data.getData('text/plain') || ''; } catch (e) {}
          insertNormalizedPaste(html, text);
        });
      });
    }

    function syncRichEditors() {
      document.querySelectorAll('.js-rich-editor').forEach(function (editor) {
        var targetId = editor.getAttribute('data-target-id');
        var hiddenField = targetId ? document.getElementById(targetId) : null;
        if (hiddenField) hiddenField.value = editor.innerHTML.trim();
      });
    }

    document.addEventListener('mousedown', function (event) {
      if (event.target.closest('.rich-color-picker-wrap')) return;
      document.querySelectorAll('.rich-color-picker-wrap.open').forEach(function (wrap) {
        wrap.classList.remove('open');
      });
    });

    function bindLectionaryForm() {
      var form = document.getElementById('lectionary-form');
      if (!form || form.dataset.bound === '1') return;
      form.dataset.bound = '1';
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        syncRichEditors();
        var submitter = event.submitter || null;
        var submitButton = submitter || form.querySelector('button[type="submit"], button:not([type])');
        var originalHtml = submitButton ? submitButton.innerHTML : '';
        if (submitButton) {
          submitButton.innerHTML = 'Захаванне <span class="spinner"></span>';
          submitButton.disabled = true;
        }
        form.classList.add('busy');
        try {
          var formData = new FormData(form);
          if (submitter && submitter.name) {
            formData.append(submitter.name, submitter.value || '1');
          }
          formData.append('ajax', '1');
          var response = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
          });
          var json = await response.json();
          if (json.ok) {
            showToast('ok', json.message || 'Аперацыя выканана.');
            await refreshLectionaryView(json.redirect || '');
          } else {
            showToast('err', json.error || 'Памылка аперацыі.');
          }
        } catch (error) {
          showToast('err', 'Памылка сеткі. Паспрабуйце яшчэ раз.');
        } finally {
          form.classList.remove('busy');
          if (submitButton) {
            submitButton.innerHTML = originalHtml;
            submitButton.disabled = false;
          }
        }
      });
    }
    initRichEditors();
    bindLectionaryForm();
    if (initialMessage) showToast('ok', initialMessage);
    if (initialError) showToast('err', initialError);
  </script>
</body>
</html>
