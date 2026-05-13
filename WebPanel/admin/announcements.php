<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel_security.php';
require_once __DIR__ . '/../includes/announcements_lib.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/schema.php';
require_once __DIR__ . '/../includes/panel_auth.php';
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
panel_require_section_get('announcements');

$tz = new DateTimeZone('UTC');
$defaultDate = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$year = (int)(new DateTimeImmutable('now', $tz))->format('Y');

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

$saveError = null;

$annAcceptsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || str_contains(strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'xmlhttprequest');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_POST['announce_save_settings'])
    && !isset($_POST['logout'])) {
    if (!panel_csrf_token_valid()) {
        if ($annAcceptsJson) {
            header('Content-Type: application/json; charset=utf-8', true, 403);
            echo json_encode(['ok' => false, 'error' => 'Памылка бяспекі (CSRF). Абнавіце старонку.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        echo '403 — токен бяспекі.';
        exit;
    }
    $dateRaw = trim((string)($_POST['bulletin_date'] ?? ''));
    $dSave = DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, $tz);
    if ($dSave === false || $dSave->format('Y-m-d') !== $dateRaw) {
        $errMsg = 'Некарэктная дата (YYYY-MM-DD). Налады не захаваны.';
        if ($annAcceptsJson) {
            header('Content-Type: application/json; charset=utf-8', true, 400);
            echo json_encode(['ok' => false, 'error' => $errMsg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $saveError = $errMsg;
    } else {
        $io = isset($_POST['include_suggested_optionals']) ? 1 : 0;
        $weekParts = [];
        $dioCsv = announcements_diocese_csv_from_post($_POST);
        $params = [
            ':bd' => $dSave->format('Y-m-d'),
            ':ad' => $dioCsv,
            ':mt' => trim((string)($_POST['main_title'] ?? '')),
            ':lu' => trim((string)($_POST['logo_url'] ?? '')),
            ':ls' => trim((string)($_POST['lead_sentence'] ?? '')),
            ':l1' => (string)($_POST['list_1'] ?? ''),
            ':l2' => (string)($_POST['list_2'] ?? ''),
            ':l3' => (string)($_POST['list_3'] ?? ''),
            ':l4' => (string)($_POST['list_4'] ?? ''),
            ':cp' => (string)($_POST['cleaning_pool'] ?? ''),
            ':tp' => (string)($_POST['thanks_pool'] ?? ''),
            ':sn' => trim((string)($_POST['signature_name'] ?? '')),
            ':sr' => trim((string)($_POST['signature_role'] ?? '')),
            ':fw' => trim((string)($_POST['footer_website'] ?? '')),
            ':io' => $io,
            ':en_lead' => announcements_post_en_flag($_POST, 'en_lead'),
            ':en_list_1' => announcements_post_en_flag($_POST, 'en_list_1'),
            ':en_list_2' => announcements_post_en_flag($_POST, 'en_list_2'),
            ':en_list_3' => announcements_post_en_flag($_POST, 'en_list_3'),
            ':en_list_4' => announcements_post_en_flag($_POST, 'en_list_4'),
            ':en_cleaning_pool' => announcements_post_en_flag($_POST, 'en_cleaning_pool'),
            ':en_thanks_pool' => announcements_post_en_flag($_POST, 'en_thanks_pool'),
            ':en_signature' => announcements_post_en_flag($_POST, 'en_signature'),
            ':en_footer' => announcements_post_en_flag($_POST, 'en_footer'),
        ];
        foreach (announcements_week_layout() as $spec) {
            foreach ([$spec['note'], $spec['clean']] as $col) {
                $weekParts[] = $col . ' = :' . $col;
                $params[':' . $col] = (string)($_POST[$col] ?? '');
            }
            foreach ([$spec['en_note'], $spec['en_clean']] as $col) {
                $weekParts[] = $col . ' = :' . $col;
                $params[':' . $col] = announcements_post_en_flag($_POST, $col);
            }
        }
        $sql = 'UPDATE panel_announcements_settings SET
                last_bulletin_date = :bd,
                announcements_dioceses = :ad,
                main_title = :mt,
                logo_url = :lu,
                lead_sentence = :ls,
                list_1 = :l1,
                list_2 = :l2,
                list_3 = :l3,
                list_4 = :l4,
                cleaning_pool = :cp,
                thanks_pool = :tp,
                signature_name = :sn,
                signature_role = :sr,
                footer_website = :fw,
                include_optionals = :io,
                en_lead = :en_lead,
                en_list_1 = :en_list_1,
                en_list_2 = :en_list_2,
                en_list_3 = :en_list_3,
                en_list_4 = :en_list_4,
                en_cleaning_pool = :en_cleaning_pool,
                en_thanks_pool = :en_thanks_pool,
                en_signature = :en_signature,
                en_footer = :en_footer,
                ' . implode(', ', $weekParts) . '
             WHERE id = 1';
        db()->prepare($sql)->execute($params);
        if ($annAcceptsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => 'Налады захаваны.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $saveRedirect = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if ($saveRedirect === '') {
            $saveRedirect = '/admin/announcements.php';
        }
        header('Location: ' . $saveRedirect . '?saved=1', true, 302);
        exit;
    }
}

function announcements_form_to_opts(array $post): array
{
    global $tz;
    $dateRaw = trim((string)($post['bulletin_date'] ?? ''));
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, $tz);
    if ($d === false || $d->format('Y-m-d') !== $dateRaw) {
        throw new InvalidArgumentException('Некарэктная дата (YYYY-MM-DD).');
    }

    $dioCsv = announcements_diocese_csv_from_post($post);
    $o = [
        'date' => $d,
        'announcements_dioceses' => $dioCsv,
        'diocese_opts' => announcements_diocese_opts_from_csv($dioCsv),
        'main_title' => trim((string)($post['main_title'] ?? '')),
        'logo_url' => trim((string)($post['logo_url'] ?? '')),
        'lead_sentence' => trim((string)($post['lead_sentence'] ?? '')),
        'list_1' => trim((string)($post['list_1'] ?? '')),
        'list_2' => trim((string)($post['list_2'] ?? '')),
        'list_3' => trim((string)($post['list_3'] ?? '')),
        'list_4' => trim((string)($post['list_4'] ?? '')),
        'cleaning_pool' => (string)($post['cleaning_pool'] ?? ''),
        'thanks_pool' => (string)($post['thanks_pool'] ?? ''),
        'signature_name' => trim((string)($post['signature_name'] ?? '')),
        'signature_role' => trim((string)($post['signature_role'] ?? '')),
        'footer_website' => trim((string)($post['footer_website'] ?? '')),
        'include_suggested_optionals' => isset($post['include_suggested_optionals']),
        'en_lead' => announcements_post_en_flag($post, 'en_lead'),
        'en_list_1' => announcements_post_en_flag($post, 'en_list_1'),
        'en_list_2' => announcements_post_en_flag($post, 'en_list_2'),
        'en_list_3' => announcements_post_en_flag($post, 'en_list_3'),
        'en_list_4' => announcements_post_en_flag($post, 'en_list_4'),
        'en_cleaning_pool' => announcements_post_en_flag($post, 'en_cleaning_pool'),
        'en_thanks_pool' => announcements_post_en_flag($post, 'en_thanks_pool'),
        'en_signature' => announcements_post_en_flag($post, 'en_signature'),
        'en_footer' => announcements_post_en_flag($post, 'en_footer'),
    ];
    foreach (announcements_week_layout() as $spec) {
        $o[$spec['note']] = (string)($post[$spec['note']] ?? '');
        $o[$spec['clean']] = (string)($post[$spec['clean']] ?? '');
        $o[$spec['en_note']] = announcements_post_en_flag($post, $spec['en_note']);
        $o[$spec['en_clean']] = announcements_post_en_flag($post, $spec['en_clean']);
    }

    return $o;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (isset($_POST['announce_preview']) || isset($_POST['announce_print']))
    && !isset($_POST['logout'])) {
    if (!panel_csrf_token_valid()) {
        http_response_code(403);
        echo '403 — токен бяспекі.';
        exit;
    }
    try {
        $o = announcements_form_to_opts($_POST);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo announcements_render_html($o, isset($_POST['announce_print']));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && isset($_GET['announce_week_ajax']) && $_GET['announce_week_ajax'] === '1'
) {
    header('Content-Type: application/json; charset=utf-8');
    $dateRaw = trim((string)($_GET['bulletin_date'] ?? ''));
    $dAjax = DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, $tz);
    if ($dAjax === false || $dAjax->format('Y-m-d') !== $dateRaw) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некарэктная дата'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dioGet = $_GET['ann_dioc'] ?? [];
    $dioGet = is_array($dioGet) ? $dioGet : [];
    $fakePost = ['ann_dioc' => $dioGet];
    $dioCsvAjax = announcements_diocese_csv_from_post($fakePost);
    $dioOptsAjax = announcements_diocese_opts_from_csv($dioCsvAjax);
    $tableMonAjax = announcements_week_table_monday($dAjax);
    $pEndAjax = $tableMonAjax->modify('+6 day');
    $rangeHintAjax = $tableMonAjax->format('d.m.Y') . ' — ' . $pEndAjax->format('d.m.Y');
    $isSundayAjax = (int)$dAjax->format('w') === 0;
    $autoTitleAjax = announcements_auto_main_title($dAjax, $dioOptsAjax);
    $rowsAjax = [];
    foreach (announcements_week_layout() as $i => $spec) {
        $rowDateAjax = $tableMonAjax->modify(sprintf('+%d day', $i));
        $rowsAjax[] = [
            'key' => $spec['key'],
            'heading' => announcements_weekday_name_nominative_be($rowDateAjax) . ', ' . $rowDateAjax->format('d.m.Y'),
        ];
    }
    echo json_encode([
        'ok' => true,
        'rangeHint' => $rangeHintAjax,
        'isSunday' => $isSundayAjax,
        'autoTitle' => $autoTitleAjax,
        'rows' => $rowsAjax,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rowDb = announcements_fetch_panel_settings_row();
$bulletinDate = $defaultDate;
$ldb = $rowDb['last_bulletin_date'] ?? null;
if (is_string($ldb) && $ldb !== '' && $ldb !== '0000-00-00') {
    $bulletinDate = $ldb;
}
$loaded = announcements_merge_settings_row($rowDb, $bulletinDate, true);

if ($saveError !== null) {
    $bulletinDate = trim((string)($_POST['bulletin_date'] ?? $bulletinDate));
    $loaded = announcements_merge_settings_row(announcements_post_as_settings_row($_POST), $bulletinDate, false);
}

$mainTitle = (string)($loaded['main_title'] ?? '');
$logoUrl = (string)($loaded['logo_url'] ?? '');
$leadSentence = (string)($loaded['lead_sentence'] ?? '');
$list1 = (string)($loaded['list_1'] ?? '');
$list2 = (string)($loaded['list_2'] ?? '');
$list3 = (string)($loaded['list_3'] ?? '');
$list4 = (string)($loaded['list_4'] ?? '');
$cleaningPool = (string)($loaded['cleaning_pool'] ?? '');
$thanksPool = (string)($loaded['thanks_pool'] ?? '');
$signatureName = (string)($loaded['signature_name'] ?? '');
$signatureRole = (string)($loaded['signature_role'] ?? '');
$footerWebsite = (string)($loaded['footer_website'] ?? '');
$includeOptionals = (bool)($loaded['include_optionals'] ?? true);

$dPreview = DateTimeImmutable::createFromFormat('Y-m-d', $bulletinDate, $tz);
$isSunday = $dPreview !== false && (int)$dPreview->format('w') === 0;
$annDioceseForPreview = announcements_diocese_opts_from_csv((string)($loaded['announcements_dioceses'] ?? ''));
$autoTitlePreview = $dPreview !== false ? announcements_auto_main_title($dPreview, $annDioceseForPreview) : '';
$rangeHintInit = '';
if ($dPreview !== false) {
    $tableMonInit = announcements_week_table_monday($dPreview);
    $pEndInit = $tableMonInit->modify('+6 day');
    $rangeHintInit = $tableMonInit->format('d.m.Y') . ' — ' . $pEndInit->format('d.m.Y');
}
$annDioceseLabels = [
    LITURGY_DIOCESE_PINSK => 'Пінская дыяцэзія',
    LITURGY_DIOCESE_MINSK_MOGILEV => 'Мінска-магілёўская архідыяцэзія',
    LITURGY_DIOCESE_VITEBSK => 'Віцебская дыяцэзія',
    LITURGY_DIOCESE_GRODNO => 'Гродзенская дыяцэзія',
];

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Аб’явы — Totus Tuus</title>
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
    html {
      color-scheme: dark;
      scrollbar-gutter: stable;
    }
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
      line-height: 1.2;
      cursor: pointer;
    }
    a.btn-pill.active, button.btn-pill.active {
      background: linear-gradient(135deg, rgba(124, 108, 240, 0.35), rgba(196, 163, 90, 0.18));
      border-color: rgba(196, 163, 90, 0.35);
      color: #fff;
    }
    button.btn-pill { margin-top: 0; font-family: inherit; box-shadow: none; }
    .btn { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border-radius: 10px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; padding: 8px 12px; font-weight: 600; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 14px; width: 100%; }
    .card { background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 16px; overflow: hidden; }
    .muted { color: #94a3b8; font-size: 13px; }
    .warn { color: #fcd34d; font-size: 13px; margin: 8px 0 0; }
    .hint { font-size: 12px; color: #94a3b8; margin-top: 4px; }
    .ann-diocese-block { margin: 16px 0; padding: 14px 16px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: rgba(15, 23, 42, 0.35); }
    .ann-diocese-label { font-weight: 600; font-size: 0.95rem; display: block; color: var(--text); }
    .ann-diocese-checkboxes { display: flex; flex-wrap: wrap; gap: 10px 18px; margin-top: 10px; align-items: center; }
    label.ann-diocese-cb { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem; }
    label.ann-diocese-cb input { width: auto; margin: 0; }
    label { display: block; margin-top: 10px; margin-bottom: 4px; font-size: 13px; color: #cbd5e1; font-weight: 600; }
    input[type="date"], input[type="text"], input[type="url"], select, textarea {
      width: 100%; border: 1px solid #334155; background: #0f172a; color: #e2e8f0;
      border-radius: 10px; padding: 10px 11px; font: inherit;
    }
    textarea { min-height: 220px; resize: vertical; max-width: 100%; }
    textarea.ann-list-field { min-height: 100px; }
    textarea.ann-pool-field { min-height: 88px; }
    textarea.ann-week-field { min-height: 72px; }
    .ann-section {
      margin-top: 22px;
      padding-top: 18px;
      border-top: 1px solid var(--line);
    }
    .ann-section:first-of-type { border-top: 0; padding-top: 0; margin-top: 0; }
    .ann-section-title {
      margin: 0 0 8px;
      font-size: 1.05rem;
      font-weight: 700;
      color: #f1f5f9;
      letter-spacing: 0.02em;
    }
    .ann-section-tools {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 12px;
    }
    .ann-section-tools button[type="button"] {
      margin-top: 0;
      padding: 6px 11px;
      font-size: 0.8125rem;
      font-weight: 600;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: var(--text);
    }
    .week-day-block {
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      margin-top: 10px;
      background: rgba(0, 0, 0, 0.18);
      overflow: hidden;
    }
    .week-day-block.week-day-block--inactive {
      opacity: 0.92;
      background: rgba(0, 0, 0, 0.1);
    }
    .week-day-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 14px;
      padding: 10px 12px;
      border-bottom: 1px solid transparent;
    }
    .week-day-block:not(.week-day-block--collapsed) .week-day-toolbar {
      border-bottom-color: var(--line);
    }
    .week-day-expand-btn {
      flex: 0 0 auto;
      width: 34px;
      height: 34px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.12);
      background: rgba(255, 255, 255, 0.05);
      color: #cbd5e1;
      cursor: pointer;
      font-size: 0.65rem;
      line-height: 1;
      transition: transform 0.15s ease, background 0.15s ease;
    }
    .week-day-expand-btn:hover {
      background: rgba(124, 108, 240, 0.2);
      color: #fff;
    }
    .week-day-block.week-day-block--collapsed .week-day-expand-btn .week-chevron {
      transform: rotate(-90deg);
    }
    .week-chevron {
      display: inline-block;
      transition: transform 0.15s ease;
    }
    .week-day-title {
      margin: 0;
      flex: 1 1 140px;
      font-size: 0.9375rem;
      font-weight: 700;
      color: #e2e8f0;
    }
    .week-day-checks {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px 18px;
      flex: 1 1 200px;
      justify-content: flex-end;
    }
    .week-day-checks .field-toggle { margin: 0; }
    .week-day-badge {
      font-size: 0.6875rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      padding: 3px 8px;
      border-radius: 6px;
      background: rgba(148, 163, 184, 0.15);
      color: var(--muted);
    }
    .week-day-badge.on {
      background: rgba(34, 197, 94, 0.18);
      color: #86efac;
    }
    .week-day-body {
      padding: 12px 14px 14px;
    }
    .week-day-body[hidden] { display: none !important; }
    .week-clean-fields[hidden] { display: none !important; }
    .week-day-hint-collapsed {
      margin: 0 0 10px;
      font-size: 12px;
      color: #64748b;
      font-style: italic;
    }
    .week-day-block.week-day-block--collapsed .week-day-hint-collapsed { display: block; }
    .week-day-block:not(.week-day-block--collapsed) .week-day-hint-collapsed { display: none; }
    .ann-cblock {
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      margin-top: 12px;
      background: rgba(0, 0, 0, 0.12);
      overflow: hidden;
    }
    .ann-cblock-head {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border-bottom: 1px solid transparent;
    }
    .ann-cblock:not(.ann-cblock--collapsed) .ann-cblock-head { border-bottom-color: var(--line); }
    .ann-cblock-toggle {
      flex: 0 0 auto;
      width: 34px;
      height: 34px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.12);
      background: rgba(255, 255, 255, 0.05);
      color: #cbd5e1;
      cursor: pointer;
      font-size: 0.65rem;
    }
    .ann-cblock-toggle:hover {
      background: rgba(124, 108, 240, 0.2);
      color: #fff;
    }
    .ann-cblock--collapsed .ann-cblock-toggle .ann-chev { transform: rotate(-90deg); }
    .ann-chev {
      display: inline-block;
      transition: transform 0.15s ease;
    }
    .ann-cblock-title {
      margin: 0;
      flex: 1 1 auto;
      font-size: 0.875rem;
      font-weight: 600;
      color: #e2e8f0;
    }
    .ann-cblock .field-toggle { margin: 0; flex: 0 0 auto; }
    .ann-cblock-body { padding: 12px 14px 14px; }
    .ann-cblock-body[hidden] { display: none !important; }
    .ann-cblock.ann-cblock--inactive { opacity: 0.9; background: rgba(0, 0, 0, 0.08); }
    .field-toggle { display: flex; align-items: center; gap: 8px; margin: 6px 0 4px; flex-wrap: wrap; }
    .field-toggle input[type="checkbox"] { width: auto; margin: 0; }
    .field-toggle label { margin: 0; font-weight: 600; font-size: 12px; color: #94a3b8; }
    .ann-toast-root {
      position: fixed;
      top: 18px;
      right: 18px;
      z-index: 99999;
      display: flex;
      flex-direction: column;
      gap: 8px;
      align-items: flex-end;
      pointer-events: none;
      max-width: min(420px, calc(100vw - 28px));
    }
    .ann-toast {
      pointer-events: auto;
      margin: 0;
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.35;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.45);
      animation: ann-toast-in 0.28s ease;
    }
    @keyframes ann-toast-in {
      from { opacity: 0; transform: translateX(12px); }
      to { opacity: 1; transform: translateX(0); }
    }
    .ann-toast--ok {
      background: rgba(22, 101, 52, 0.95);
      border: 1px solid rgba(74, 222, 128, 0.45);
      color: #ecfdf5;
    }
    .ann-toast--err {
      background: rgba(127, 29, 29, 0.95);
      border: 1px solid rgba(252, 165, 165, 0.45);
      color: #fef2f2;
    }
    .ann-toast--out { animation: ann-toast-out 0.22s ease forwards; }
    @keyframes ann-toast-out {
      to { opacity: 0; transform: translateX(16px); }
    }
    .visually-hidden {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    button { border: 1px solid #334155; background: #7c6cf0; color: #fff; font-weight: 700; border-radius: 10px; padding: 10px 12px; cursor: pointer; }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
    }
  </style>
</head>
<body>
  <div id="ann-toast-root" class="ann-toast-root" aria-live="polite" aria-relevant="additions text"></div>
  <div class="header">
    <div class="header-brand">
      <h1>Totus Tuus</h1>
      <p class="header-tagline">Панэль кіравання Святой Памяці<br>Біскупа Казіміра Велікасельца OP</p>
    </div>
<?php
        $panelNavPage = 'announcements';
        $panelNavView = 'categories';
        $panelNavCalYear = $year;
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <div class="grid">
  <div class="card">
    <h2 style="margin:0 0 8px; font-size:1rem;">Аб’явы</h2>
    <p class="muted" style="margin-top:0;">Генерацыя аб’яваў (прагляд у браўзеры, друк праз дыялог браўзера). Палі ніжэй можна захаваць у базе.</p>
<?php if ($saveError !== null): ?>
    <noscript>
      <p style="margin:0 0 12px;padding:10px 12px;border-radius:10px;font-size:14px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.35);color:#fecaca;"><?= htmlspecialchars($saveError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </noscript>
<?php endif; ?>
    <form id="ann-settings-form" method="post" action="">
<?= panel_csrf_field() ?>
      <label for="bulletin_date">Дата аб’яваў (звычайна нядзеля на вокладцы)</label>
      <input id="bulletin_date" type="date" name="bulletin_date" value="<?= htmlspecialchars($bulletinDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
      <p class="muted" id="ann-week-range-hint"<?= $dPreview === false ? ' hidden' : '' ?>>
<?php if ($dPreview !== false): ?>
        Тыдзень у табліцы (панядзелак–нядзеля): <strong id="ann-week-range-strong"><?= htmlspecialchars($rangeHintInit, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> — з панядзельніка пасля нядзелі аб’яваў.
<?php endif; ?>
      </p>
      <p class="warn" id="ann-sunday-warn"<?= ($dPreview === false || $isSunday) ? ' hidden' : '' ?>>
        Заўвага: зазвычай бяруць нядзелю аб’яваў. Загаловак і ўводны радок для абранай даты; першы радок табліцы — панядзелак пасля бліжэйшай папярэдняй нядзелі.
      </p>
      <p class="muted" id="ann-auto-title-wrap"<?= $autoTitlePreview === '' ? ' hidden' : '' ?>>
        Аўта-загаловак для гэтай даты: <strong id="ann-auto-title-strong"><?= htmlspecialchars($autoTitlePreview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
      </p>

      <div class="ann-diocese-block">
        <span class="ann-diocese-label" id="ann-diocese-heading">Каляндар для аўтазапаўнення (мясцовыя святы дыяцэзій Беларусі)</span>
        <p class="hint" style="margin-top:6px;">Без галачак — агульны Рымскі каляндар для Еўропы; святы дыяцэзій не ўлічваюцца ў прапановах радкоў, даброўных успамінах і аўтазагалоўку.</p>
        <div class="ann-diocese-checkboxes" role="group" aria-labelledby="ann-diocese-heading">
<?php foreach (liturgy_diocese_keys() as $dk): ?>
            <label class="ann-diocese-cb">
              <input type="checkbox" name="ann_dioc[<?= htmlspecialchars($dk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]" value="1"<?= !empty($annDioceseForPreview[$dk]) ? 'checked' : '' ?>>
<?= htmlspecialchars((string)($annDioceseLabels[$dk] ?? $dk), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </label>
<?php endforeach; ?>
        </div>
      </div>

      <label for="main_title">Загаловак (пуста = з літургічнага календара)</label>
      <input id="main_title" type="text" name="main_title" value="<?= htmlspecialchars($mainTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="">
      <p class="hint">Прыклад: II нядзеля Вялікага посту</p>

      <label for="logo_url">URL лагатыпа (неабавязкова)</label>
      <input id="logo_url" type="url" name="logo_url" value="<?= htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="https://…">

      <p class="muted" style="margin-top:12px;">Сем радкоў — <strong>панядзелак–нядзеля пасля нядзелі аб’яваў</strong> (першы радок заўсёды панядзелак). Загалоўкі — фактычны дзень і дата.</p>

<?php $enLead = !empty($loaded['en_lead']); ?>
      <div class="ann-cblock<?= $enLead ? '' : ' ann-cblock--collapsed ann-cblock--inactive' ?>" data-ann-cblock>
        <div class="ann-cblock-head">
          <button type="button" class="ann-cblock-toggle" aria-expanded="<?= $enLead ? 'true' : 'false' ?>" aria-controls="ann-lead-body" title="Паказаць або схаваць поле">
            <span class="ann-chev" aria-hidden="true">▼</span>
          </button>
          <p class="ann-cblock-title">Уводны пункт спісу</p>
          <div class="field-toggle">
            <input type="checkbox" id="en_lead" name="en_lead" value="1"<?= $enLead ? 'checked' : '' ?> data-ann-en>
            <label for="en_lead">Уключыць у аб’явы</label>
          </div>
        </div>
        <div id="ann-lead-body" class="ann-cblock-body"<?= $enLead ? '' : 'hidden' ?>>
          <label for="lead_sentence">Тэкст (пуста = «Сёння, …» + літургічны дзень для даты аб’яваў)</label>
          <textarea id="lead_sentence" name="lead_sentence" rows="2" placeholder=""><?= htmlspecialchars($leadSentence, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </div>
      </div>

      <div class="field-toggle" style="margin-top:14px;">
        <input type="checkbox" id="include_suggested_optionals" name="include_suggested_optionals" value="1"<?= $includeOptionals ? 'checked' : '' ?>>
        <label for="include_suggested_optionals">Дадаць асобныя радкі пра ўсе даброўныя успаміны тыдня (як раней; можа дубляваць дні з табліцы)</label>
      </div>

      <section class="ann-section" aria-labelledby="ann-week-heading">
        <h2 id="ann-week-heading" class="ann-section-title">Дні тыдня</h2>
        <p class="hint" style="margin-top:0;">Літургія, даброўны успамін і ўборка. Пустыя палі аўтазапаўняюцца для ўрачыстасцяў, дзён з даброўным успамінам і першага панядзельніка тыдня. Зняць галачку — не трапіць у аб’явы. <strong>Выключаныя дні згорнуты</strong> — націсніце стрэлку, каб рэдагаваць тэкст.</p>
        <div class="ann-section-tools">
          <button type="button" id="ann-week-expand-all">Разгарнуць усе дні</button>
          <button type="button" id="ann-week-collapse-inactive">Згарнуць выключаныя</button>
        </div>
<?php
      $periodStartForm = $dPreview !== false ? announcements_week_table_monday($dPreview) : announcements_week_table_monday(new DateTimeImmutable('now', $tz));
      foreach (announcements_week_layout() as $i => $spec):
          $rowDate = $periodStartForm->modify(sprintf('+%d day', $i));
          $rowHeading = announcements_weekday_name_nominative_be($rowDate) . ', ' . $rowDate->format('d.m.Y');
          $wk = htmlspecialchars($spec['key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $enNote = !empty($loaded[$spec['en_note']]);
          $enClean = !empty($loaded[$spec['en_clean']]);
          $dayActive = $enNote || $enClean;
          $bodyId = 'week-body-' . $spec['key'];
      ?>
      <div class="week-day-block<?= $dayActive ? '' : ' week-day-block--collapsed week-day-block--inactive' ?>" data-week-day data-week-key="<?= $wk ?>">
        <div class="week-day-toolbar">
          <button type="button" class="week-day-expand-btn" aria-expanded="<?= $dayActive ? 'true' : 'false' ?>" aria-controls="<?= htmlspecialchars($bodyId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" title="Паказаць або схаваць палі дня">
            <span class="week-chevron" aria-hidden="true">▼</span>
          </button>
          <h3 class="week-day-title"><?= htmlspecialchars($rowHeading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
          <span class="week-day-badge<?= $dayActive ? ' on' : '' ?>" data-week-badge><?= $dayActive ? 'У аб’явах' : 'Выключана' ?></span>
          <div class="week-day-checks">
            <div class="field-toggle">
              <input type="checkbox" class="js-week-en-note" id="<?= htmlspecialchars($spec['en_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" name="<?= htmlspecialchars($spec['en_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="1"<?= $enNote ? 'checked' : '' ?>>
              <label for="<?= htmlspecialchars($spec['en_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Тэкст дня</label>
            </div>
            <div class="field-toggle">
              <input type="checkbox" class="js-week-en-clean" id="<?= htmlspecialchars($spec['en_clean'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" name="<?= htmlspecialchars($spec['en_clean'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="1"<?= $enClean ? 'checked' : '' ?>>
              <label for="<?= htmlspecialchars($spec['en_clean'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Уборка</label>
            </div>
          </div>
        </div>
        <div id="<?= htmlspecialchars($bodyId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="week-day-body"<?= $dayActive ? '' : 'hidden' ?>>
          <p class="week-day-hint-collapsed">Дзень выключаны з аб’яваў — палі схаваны для кампактнасці. Уключыце галачку вышэй або разгарніце стрэлкай, каб змяніць тэкст.</p>
          <div class="field-toggle">
            <span class="muted" style="margin:0;font-size:12px;">Абвестка дня</span>
          </div>
          <textarea class="ann-week-field" name="<?= htmlspecialchars($spec['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" rows="3" placeholder=""><?= htmlspecialchars((string)($loaded[$spec['note']] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <div class="week-clean-fields" data-week-clean-fields<?= $enClean ? '' : 'hidden' ?>>
            <div class="field-toggle" style="margin-top:8px;">
              <span class="muted" style="margin:0;font-size:12px;">Тэкст уборкі</span>
            </div>
            <textarea class="ann-week-field" name="<?= htmlspecialchars($spec['clean'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" rows="2" placeholder="Уборка…"><?= htmlspecialchars((string)($loaded[$spec['clean']] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          </div>
        </div>
      </div>
<?php endforeach; ?>
      </section>

      <section class="ann-section" aria-labelledby="ann-extra-heading">
        <h2 id="ann-extra-heading" class="ann-section-title">Дадатковыя пункты спісу</h2>
        <p class="hint" style="margin-top:0;">Фіксаваныя нумары ў аб’явах пасля тыдня. Выключаныя блокі згорнуты.</p>

<?php
      foreach ([1 => $list1, 2 => $list2, 3 => $list3, 4 => $list4] as $n => $listVal):
          $enK = 'en_list_' . $n;
          $enOn = !empty($loaded[$enK]);
          $bid = 'ann-list-body-' . $n;
      ?>
      <div class="ann-cblock<?= $enOn ? '' : ' ann-cblock--collapsed ann-cblock--inactive' ?>" data-ann-cblock>
        <div class="ann-cblock-head">
          <button type="button" class="ann-cblock-toggle" aria-expanded="<?= $enOn ? 'true' : 'false' ?>" aria-controls="<?= htmlspecialchars($bid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" title="Паказаць або схаваць поле">
            <span class="ann-chev" aria-hidden="true">▼</span>
          </button>
          <p class="ann-cblock-title">Пункт спісу<?= (int)$n ?></p>
          <div class="field-toggle">
            <input type="checkbox" id="<?= htmlspecialchars($enK, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" name="<?= htmlspecialchars($enK, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="1"<?= $enOn ? 'checked' : '' ?> data-ann-en>
            <label for="<?= htmlspecialchars($enK, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Уключыць у аб’явы</label>
          </div>
        </div>
        <div id="<?= htmlspecialchars($bid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="ann-cblock-body"<?= $enOn ? '' : 'hidden' ?>>
          <label for="list_<?= (int)$n ?>">Тэкст пункта<?= (int)$n ?></label>
          <textarea id="list_<?= (int)$n ?>" class="ann-list-field" name="list_<?= (int)$n ?>" rows="4"><?= htmlspecialchars((string)$listVal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </div>
      </div>
<?php endforeach; ?>
      </section>

      <section class="ann-section" aria-labelledby="ann-pools-heading">
        <h2 id="ann-pools-heading" class="ann-section-title">Пулы (выпадковы радок)</h2>

<?php $enCp = !empty($loaded['en_cleaning_pool']); ?>
      <div class="ann-cblock<?= $enCp ? '' : ' ann-cblock--collapsed ann-cblock--inactive' ?>" data-ann-cblock>
        <div class="ann-cblock-head">
          <button type="button" class="ann-cblock-toggle" aria-expanded="<?= $enCp ? 'true' : 'false' ?>" aria-controls="ann-cleaning-body" title="Паказаць або схаваць поле">
            <span class="ann-chev" aria-hidden="true">▼</span>
          </button>
          <p class="ann-cblock-title">Пул уборкі</p>
          <div class="field-toggle">
            <input type="checkbox" id="en_cleaning_pool" name="en_cleaning_pool" value="1"<?= $enCp ? 'checked' : '' ?> data-ann-en>
            <label for="en_cleaning_pool">Уключыць у аб’явы</label>
          </div>
        </div>
        <div id="ann-cleaning-body" class="ann-cblock-body"<?= $enCp ? '' : 'hidden' ?>>
          <label for="cleaning_pool">Шмат радкоў; выпадкова адзін радок як пункт спісу</label>
          <textarea id="cleaning_pool" class="ann-pool-field" name="cleaning_pool" rows="4"><?= htmlspecialchars($cleaningPool, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <p class="hint">Кожны варыянт з новага радка. Пуста — пункт не дадаецца.</p>
        </div>
      </div>

<?php $enTp = !empty($loaded['en_thanks_pool']); ?>
      <div class="ann-cblock<?= $enTp ? '' : ' ann-cblock--collapsed ann-cblock--inactive' ?>" data-ann-cblock>
        <div class="ann-cblock-head">
          <button type="button" class="ann-cblock-toggle" aria-expanded="<?= $enTp ? 'true' : 'false' ?>" aria-controls="ann-thanks-body" title="Паказаць або схаваць поле">
            <span class="ann-chev" aria-hidden="true">▼</span>
          </button>
          <p class="ann-cblock-title">Удзячнасць</p>
          <div class="field-toggle">
            <input type="checkbox" id="en_thanks_pool" name="en_thanks_pool" value="1"<?= $enTp ? 'checked' : '' ?> data-ann-en>
            <label for="en_thanks_pool">Уключыць у аб’явы</label>
          </div>
        </div>
        <div id="ann-thanks-body" class="ann-cblock-body"<?= $enTp ? '' : 'hidden' ?>>
          <label for="thanks_pool" class="visually-hidden">Тэкст пулу ўдзячнасці</label>
          <textarea id="thanks_pool" class="ann-pool-field" name="thanks_pool" rows="4" aria-label="Тэкст пулу ўдзячнасці, кожны варыянт з новага радка"><?= htmlspecialchars($thanksPool, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </div>
      </div>
      </section>

      <section class="ann-section" aria-labelledby="ann-close-heading">
        <h2 id="ann-close-heading" class="ann-section-title">Заканчэнне аб’яваў</h2>

<?php $enSig = !empty($loaded['en_signature']); ?>
      <div class="ann-cblock<?= $enSig ? '' : ' ann-cblock--collapsed ann-cblock--inactive' ?>" data-ann-cblock>
        <div class="ann-cblock-head">
          <button type="button" class="ann-cblock-toggle" aria-expanded="<?= $enSig ? 'true' : 'false' ?>" aria-controls="ann-sig-body" title="Паказаць або схаваць поле">
            <span class="ann-chev" aria-hidden="true">▼</span>
          </button>
          <p class="ann-cblock-title">Подпіс і пасада</p>
          <div class="field-toggle">
            <input type="checkbox" id="en_signature" name="en_signature" value="1"<?= $enSig ? 'checked' : '' ?> data-ann-en>
            <label for="en_signature">Уключыць подпіс і пасаду</label>
          </div>
        </div>
        <div id="ann-sig-body" class="ann-cblock-body"<?= $enSig ? '' : 'hidden' ?>>
          <label for="signature_name">Подпіс</label>
          <input id="signature_name" type="text" name="signature_name" value="<?= htmlspecialchars($signatureName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="кс. …">
          <label for="signature_role">Пасада</label>
          <input id="signature_role" type="text" name="signature_role" value="<?= htmlspecialchars($signatureRole, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="пробашч">
        </div>
      </div>

<?php $enFoot = !empty($loaded['en_footer']); ?>
      <div class="ann-cblock<?= $enFoot ? '' : ' ann-cblock--collapsed ann-cblock--inactive' ?>" data-ann-cblock>
        <div class="ann-cblock-head">
          <button type="button" class="ann-cblock-toggle" aria-expanded="<?= $enFoot ? 'true' : 'false' ?>" aria-controls="ann-footer-body" title="Паказаць або схаваць поле">
            <span class="ann-chev" aria-hidden="true">▼</span>
          </button>
          <p class="ann-cblock-title">Радок з сайтам</p>
          <div class="field-toggle">
            <input type="checkbox" id="en_footer" name="en_footer" value="1"<?= $enFoot ? 'checked' : '' ?> data-ann-en>
            <label for="en_footer">Уключыць у аб’явы</label>
          </div>
        </div>
        <div id="ann-footer-body" class="ann-cblock-body"<?= $enFoot ? '' : 'hidden' ?>>
          <label for="footer_website">Сайт у заключным радку</label>
          <input id="footer_website" type="text" name="footer_website" value="<?= htmlspecialchars($footerWebsite, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="www.example.by">
        </div>
      </div>
      </section>

      <div class="actions">
        <button type="submit" id="ann-btn-save" name="announce_save_settings" value="1">Захаваць налады ў базе</button>
        <button type="submit" name="announce_preview" value="1" formtarget="_blank">Прагляд у новай укладцы</button>
        <button type="submit" name="announce_print" value="1" formtarget="_blank" title="Адкрыць аб’явы і выклікаць друк у браўзеры">Друкаваць</button>
      </div>
    </form>
  </div>
  </div>
  <script>
  window.__ANN_BOOT_TOAST__ =<?= $saveError !== null
      ? json_encode(['type' => 'err', 'text' => $saveError], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
      : 'null' ?>;
  </script>
  <script>
  (function () {
    var annToastRoot = document.getElementById('ann-toast-root');
    function annToast(text, type) {
      if (!annToastRoot || text === null || text === '') return;
      var s = String(text);
      if (s.length > 240) s = s.slice(0, 237) + '…';
      var p = document.createElement('p');
      p.className = 'ann-toast ann-toast--' + (type === 'err' ? 'err' : 'ok');
      p.textContent = s;
      annToastRoot.appendChild(p);
      setTimeout(function () {
        p.classList.add('ann-toast--out');
        var done = function () { if (p.parentNode) p.remove(); };
        p.addEventListener('animationend', done, { once: true });
        setTimeout(done, 400);
      }, 4200);
    }

    if (window.__ANN_BOOT_TOAST__ && window.__ANN_BOOT_TOAST__.text) {
      annToast(window.__ANN_BOOT_TOAST__.text, window.__ANN_BOOT_TOAST__.type || 'err');
    }
    try {
      delete window.__ANN_BOOT_TOAST__;
    } catch (e1) { window.__ANN_BOOT_TOAST__ = null; }

    try {
      var u = new URL(window.location.href);
      if (u.searchParams.get('saved') === '1') {
        annToast('Налады захаваны.', 'ok');
        u.searchParams.delete('saved');
        var q = u.searchParams.toString();
        window.history.replaceState({}, '', u.pathname + (q ? '?' + q : '') + u.hash);
      }
    } catch (e2) {  }

    var annForm = document.getElementById('ann-settings-form');
    if (annForm) {
      annForm.addEventListener('submit', function (e) {
        var sb = e.submitter;
        if (!sb || sb.name !== 'announce_save_settings') return;
        e.preventDefault();
        var saveBtn = sb;
        var prevText = saveBtn.textContent;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Захаванне…';
        var fd = new FormData(annForm);
        fd.set('announce_save_settings', saveBtn.value || '1');
        var action = annForm.getAttribute('action');
        var url = (action && action.length) ? action : window.location.pathname;
        fetch(url, {
          method: 'POST',
          body: fd,
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        })
          .then(function (r) {
            var finalUrl = r.url || '';
            var redirected = r.redirected;
            return r.text().then(function (t) {
              var ct = (r.headers.get('content-type') || '').toLowerCase();
              var json = null;
              var trimmed = (t || '').trim();
              if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
                try {
                  json = JSON.parse(t);
                } catch (ex) {
                  json = null;
                }
              }
              if (json === null && ct.indexOf('application/json') !== -1) {
                try {
                  json = JSON.parse(t);
                } catch (ex) {
                  json = null;
                }
              }
              return {
                status: r.status,
                redirected: redirected,
                finalUrl: finalUrl,
                json: json,
                raw: t
              };
            });
          })
          .then(function (res) {
            if (res.json && res.json.ok) {
              annToast(res.json.message || 'Налады захаваны.', 'ok');
              return;
            }
            var savedRedirect = res.finalUrl.indexOf('saved=1') !== -1
              || res.finalUrl.indexOf('saved%3D1') !== -1;
            if (savedRedirect && (res.redirected || res.status === 200)) {
              annToast('Налады захаваны.', 'ok');
              try {
                var u = new URL(res.finalUrl, window.location.origin);
                if (u.searchParams.get('saved') === '1') {
                  u.searchParams.delete('saved');
                  var q = u.searchParams.toString();
                  window.history.replaceState({}, '', u.pathname + (q ? '?' + q : '') + u.hash);
                }
              } catch (e3) {  }
              return;
            }
            var errText;
            if (res.json && res.json.error) {
              errText = res.json.error;
            } else {
              var raw = res.raw || '';
              var looksHtml = /^\s*</.test(raw) || raw.indexOf('<!DOCTYPE') !== -1 || raw.indexOf('<html') !== -1;
              if (looksHtml || raw.length > 400) {
                errText = 'Памылка сервера (код ' + res.status + '). Абнавіце старонку або паспрабуйце яшчэ раз.';
              } else {
                errText = raw || ('HTTP ' + res.status);
              }
            }
            annToast(errText, 'err');
          })
          .catch(function (err) {
            annToast(err.message || String(err), 'err');
          })
          .finally(function () {
            saveBtn.disabled = false;
            saveBtn.textContent = prevText;
          });
      });
    }

    var annWeekMetaTimer = null;
    function annEscapeHtml(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }
    function annScheduleWeekMetaRefresh() {
      if (annWeekMetaTimer) clearTimeout(annWeekMetaTimer);
      annWeekMetaTimer = setTimeout(annRefreshWeekMetaFromAjax, 200);
    }
    function annRefreshWeekMetaFromAjax() {
      annWeekMetaTimer = null;
      var form = document.getElementById('ann-settings-form');
      var dateEl = document.getElementById('bulletin_date');
      if (!form || !dateEl) return;
      var v = dateEl.value || '';
      if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return;
      var p = new URLSearchParams();
      p.set('announce_week_ajax', '1');
      p.set('bulletin_date', v);
      form.querySelectorAll('input[type="checkbox"][name^="ann_dioc["]').forEach(function (cb) {
        if (cb.checked) p.append(cb.name, cb.value);
      });
      fetch(window.location.pathname + '?' + p.toString(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      })
        .then(function (r) {
          return r.text().then(function (t) {
            var j = null;
            try {
              j = JSON.parse(t);
            } catch (ex) {
              j = null;
            }
            return { ok: r.ok, j: j };
          });
        })
        .then(function (pack) {
          if (!pack.j || pack.j.ok !== true) return;
          var d = pack.j;
          var rh = document.getElementById('ann-week-range-hint');
          if (rh) {
            rh.hidden = false;
            rh.innerHTML = 'Тыдзень у табліцы (панядзелак–нядзеля): <strong>' + annEscapeHtml(d.rangeHint || '') + '</strong> — з панядзельніка пасля нядзелі аб\u2019яваў.';
          }
          var sw = document.getElementById('ann-sunday-warn');
          if (sw) sw.hidden = !!d.isSunday;
          var atw = document.getElementById('ann-auto-title-wrap');
          var ats = document.getElementById('ann-auto-title-strong');
          if (atw && ats) {
            var tt = d.autoTitle != null ? String(d.autoTitle) : '';
            if (tt === '') {
              atw.hidden = true;
              ats.textContent = '';
            } else {
              atw.hidden = false;
              ats.textContent = tt;
            }
          }
          var byKey = {};
          (d.rows || []).forEach(function (row) {
            if (row && row.key) byKey[String(row.key)] = row.heading != null ? String(row.heading) : '';
          });
          document.querySelectorAll('[data-week-day]').forEach(function (block) {
            var k = block.getAttribute('data-week-key');
            if (!k || typeof byKey[k] === 'undefined') return;
            var title = block.querySelector('.week-day-title');
            if (title) title.textContent = byKey[k];
          });
        })
        .catch(function () { /* ignore */ });
    }
    var annDateIn = document.getElementById('bulletin_date');
    if (annDateIn) {
      annDateIn.addEventListener('change', annScheduleWeekMetaRefresh);
      annDateIn.addEventListener('input', annScheduleWeekMetaRefresh);
    }
    var annFormMeta = document.getElementById('ann-settings-form');
    if (annFormMeta) {
      annFormMeta.querySelectorAll('input[type="checkbox"][name^="ann_dioc["]').forEach(function (cb) {
        cb.addEventListener('change', annScheduleWeekMetaRefresh);
      });
    }

    function updateWeekCleanFields(block) {
      var clean = block.querySelector('.js-week-en-clean');
      var wrap = block.querySelector('[data-week-clean-fields]');
      if (!clean || !wrap) return;
      wrap.hidden = !clean.checked;
    }

    function updateWeekDayChrome(block) {
      var note = block.querySelector('.js-week-en-note');
      var clean = block.querySelector('.js-week-en-clean');
      var body = block.querySelector('.week-day-body');
      var btn = block.querySelector('.week-day-expand-btn');
      var badge = block.querySelector('[data-week-badge]');
      if (!note || !clean || !body || !btn) return;
      updateWeekCleanFields(block);
      var active = note.checked || clean.checked;
      if (badge) {
        badge.textContent = active ? 'У аб\u2019явах' : 'Выключана';
        badge.classList.toggle('on', active);
      }
      block.classList.toggle('week-day-block--inactive', !active);
      var expanded = !body.hidden;
      block.classList.toggle('week-day-block--collapsed', !expanded);
      btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function weekDayFromCheckboxes(block) {
      var note = block.querySelector('.js-week-en-note');
      var clean = block.querySelector('.js-week-en-clean');
      var body = block.querySelector('.week-day-body');
      if (!note || !clean || !body) return;
      if (!note.checked && !clean.checked) {
        body.hidden = true;
      } else {
        body.hidden = false;
      }
      updateWeekDayChrome(block);
    }

    document.querySelectorAll('[data-week-day]').forEach(function (block) {
      var note = block.querySelector('.js-week-en-note');
      var clean = block.querySelector('.js-week-en-clean');
      var body = block.querySelector('.week-day-body');
      var btn = block.querySelector('.week-day-expand-btn');
      if (note) note.addEventListener('change', function () { weekDayFromCheckboxes(block); });
      if (clean) clean.addEventListener('change', function () { weekDayFromCheckboxes(block); });
      if (btn && body) {
        btn.addEventListener('click', function () {
          body.hidden = !body.hidden;
          updateWeekDayChrome(block);
        });
      }
      updateWeekCleanFields(block);
    });

    var expandAll = document.getElementById('ann-week-expand-all');
    var collapseInactive = document.getElementById('ann-week-collapse-inactive');
    if (expandAll) {
      expandAll.addEventListener('click', function () {
        document.querySelectorAll('[data-week-day]').forEach(function (block) {
          var body = block.querySelector('.week-day-body');
          if (body) body.hidden = false;
          updateWeekDayChrome(block);
        });
      });
    }
    if (collapseInactive) {
      collapseInactive.addEventListener('click', function () {
        document.querySelectorAll('[data-week-day]').forEach(function (block) {
          var note = block.querySelector('.js-week-en-note');
          var clean = block.querySelector('.js-week-en-clean');
          var body = block.querySelector('.week-day-body');
          if (note && clean && body && !note.checked && !clean.checked) {
            body.hidden = true;
          }
          updateWeekDayChrome(block);
        });
      });
    }

    function annCblockFromCheckbox(block) {
      var en = block.querySelector('[data-ann-en]');
      var body = block.querySelector('.ann-cblock-body');
      var btn = block.querySelector('.ann-cblock-toggle');
      if (!en || !body || !btn) return;
      if (en.checked) {
        body.hidden = false;
      } else {
        body.hidden = true;
      }
      updateAnnCblockChrome(block);
    }

    function updateAnnCblockChrome(block) {
      var en = block.querySelector('[data-ann-en]');
      var body = block.querySelector('.ann-cblock-body');
      var btn = block.querySelector('.ann-cblock-toggle');
      if (!en || !body || !btn) return;
      var on = en.checked;
      var expanded = !body.hidden;
      block.classList.toggle('ann-cblock--collapsed', !expanded);
      block.classList.toggle('ann-cblock--inactive', !on);
      btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    document.querySelectorAll('[data-ann-cblock]').forEach(function (block) {
      var en = block.querySelector('[data-ann-en]');
      var body = block.querySelector('.ann-cblock-body');
      var btn = block.querySelector('.ann-cblock-toggle');
      if (en) en.addEventListener('change', function () { annCblockFromCheckbox(block); });
      if (btn && body) {
        btn.addEventListener('click', function () {
          body.hidden = !body.hidden;
          updateAnnCblockChrome(block);
        });
      }
    });
  })();
  </script>
</body>
</html>
