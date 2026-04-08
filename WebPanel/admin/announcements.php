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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_POST['announce_save_settings'])
    && !isset($_POST['logout'])) {
    if (!panel_csrf_token_valid()) {
        http_response_code(403);
        echo '403 — токен бяспекі.';
        exit;
    }
    $dateRaw = trim((string)($_POST['bulletin_date'] ?? ''));
    $dSave = DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, $tz);
    if ($dSave === false || $dSave->format('Y-m-d') !== $dateRaw) {
        $saveError = 'Некарэктная дата (YYYY-MM-DD). Налады не захаваны.';
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
        header('Location: /admin/announcements.php?saved=1', true, 302);
        exit;
    }
}

/**
 * @return array<string, mixed>
 */
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

$savedOk = isset($_GET['saved']);

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
    .nav-group-items form { margin: 0; }
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
    .week-day-block {
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      padding: 12px 14px;
      margin-top: 12px;
      background: rgba(0, 0, 0, 0.18);
    }
    .week-day-block h3 { margin: 0 0 10px; font-size: 0.9375rem; font-weight: 700; color: #e2e8f0; }
    .field-toggle { display: flex; align-items: center; gap: 8px; margin: 6px 0 4px; flex-wrap: wrap; }
    .field-toggle input[type="checkbox"] { width: auto; margin: 0; }
    .field-toggle label { margin: 0; font-weight: 600; font-size: 12px; color: #94a3b8; }
    .msg { margin: 0 0 12px; padding: 10px 12px; border-radius: 10px; font-size: 14px; }
    .msg.ok { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.35); color: #bbf7d0; }
    .msg.err { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #fecaca; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    button { border: 1px solid #334155; background: #7c6cf0; color: #fff; font-weight: 700; border-radius: 10px; padding: 10px 12px; cursor: pointer; }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
      .top-nav { justify-content: flex-start; max-width: none; width: 100%; align-items: flex-start; }
      .top-nav-row { justify-content: flex-start; gap: 10px 14px; }
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
        $panelNavPage = 'announcements';
        $panelNavView = 'categories';
        $panelNavCalYear = $year;
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <div class="grid">
  <div class="card">
    <h2 style="margin:0 0 8px; font-size:1rem;">Аб’явы</h2>
    <p class="muted" style="margin-top:0;">Генерацыя бюлетэня (прагляд у браўзеры, друк праз дыялог браўзера). Палі ніжэй можна захаваць у базе.</p>
    <?php if ($savedOk): ?>
      <p class="msg ok">Налады захаваны.</p>
    <?php endif; ?>
    <?php if ($saveError !== null): ?>
      <p class="msg err"><?= htmlspecialchars($saveError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="">
      <?= panel_csrf_field() ?>
      <label for="bulletin_date">Дата бюлетэня (звычайна нядзеля на вокладцы)</label>
      <input id="bulletin_date" type="date" name="bulletin_date" value="<?= htmlspecialchars($bulletinDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
      <?php if ($dPreview !== false): ?>
        <?php
        $tableMon = announcements_week_table_monday($dPreview);
        $pEnd = $tableMon->modify('+6 day');
        $rangeHint = $tableMon->format('d.m.Y') . ' — ' . $pEnd->format('d.m.Y');
        ?>
        <p class="muted">Тыдзень у табліцы (панядзелак–нядзеля): <strong><?= htmlspecialchars($rangeHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> — з панядзельніка пасля нядзелі бюлетэня.</p>
      <?php endif; ?>
      <?php if ($dPreview !== false && !$isSunday): ?>
        <p class="warn">Заўвага: зазвычай бяруць нядзелю бюлетэня. Загаловак і ўводны радок для абранай даты; першы радок табліцы — панядзелак пасля бліжэйшай папярэдняй нядзелі.</p>
      <?php endif; ?>
      <?php if ($autoTitlePreview !== ''): ?>
        <p class="muted">Аўта-загаловак для гэтай даты: <strong><?= htmlspecialchars($autoTitlePreview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
      <?php endif; ?>

      <div class="ann-diocese-block">
        <span class="ann-diocese-label" id="ann-diocese-heading">Каляндар для аўтазапаўнення (мясцовыя святы дыяцэзій Беларусі)</span>
        <p class="hint" style="margin-top:6px;">Без галачак — агульны Рымскі каляндар для Еўропы; святы дыяцэзій не ўлічваюцца ў прапановах радкоў, даброўных успамінах і аўтазагалоўку.</p>
        <div class="ann-diocese-checkboxes" role="group" aria-labelledby="ann-diocese-heading">
          <?php foreach (liturgy_diocese_keys() as $dk): ?>
            <label class="ann-diocese-cb">
              <input type="checkbox" name="ann_dioc[<?= htmlspecialchars($dk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]" value="1" <?= !empty($annDioceseForPreview[$dk]) ? 'checked' : '' ?>>
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

      <p class="muted" style="margin-top:12px;">Сем радкоў — <strong>панядзелак–нядзеля пасля нядзелі бюлетэня</strong> (першы радок заўсёды панядзелак). Загалоўкі — фактычны дзень і дата.</p>

      <div class="field-toggle">
        <input type="checkbox" id="en_lead" name="en_lead" value="1" <?= !empty($loaded['en_lead']) ? 'checked' : '' ?>>
        <label for="en_lead">Уключыць уводны пункт спісу</label>
      </div>
      <label for="lead_sentence">Уводны пункт (пуста = «Сёння, …» + літургічны дзень для даты бюлетэня)</label>
      <textarea id="lead_sentence" name="lead_sentence" rows="2" placeholder=""><?= htmlspecialchars($leadSentence, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

      <div class="field-toggle" style="margin-top:14px;">
        <input type="checkbox" id="include_suggested_optionals" name="include_suggested_optionals" value="1" <?= $includeOptionals ? 'checked' : '' ?>>
        <label for="include_suggested_optionals">Дадаць асобныя радкі пра ўсе даброўныя успаміны тыдня (як раней; можа дубляваць дні з табліцы)</label>
      </div>

      <h3 style="margin:20px 0 6px; font-size:0.95rem;">Дні тыдня (літургія + даброўны успамін; уборка)</h3>
      <p class="hint" style="margin-top:0;">Пустыя палі «дзень» аўтазапаўняюцца для <strong>урачыстасцяў і свят</strong>, дзён з <strong>даброўным успамінам</strong> і для <strong>першага панядзельніка</strong> тыдня табліцы; звычайныя будні без успамінаў застаюцца пустымі (можна ўпісаць уручную). Зняць галачку — не трапіць у бюлетэнь.</p>
      <?php
      $periodStartForm = $dPreview !== false ? announcements_week_table_monday($dPreview) : announcements_week_table_monday(new DateTimeImmutable('now', $tz));
      foreach (announcements_week_layout() as $i => $spec):
          $rowDate = $periodStartForm->modify(sprintf('+%d day', $i));
          $rowHeading = announcements_weekday_name_nominative_be($rowDate) . ', ' . $rowDate->format('d.m.Y');
      ?>
      <div class="week-day-block">
        <h3><?= htmlspecialchars($rowHeading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
        <div class="field-toggle">
          <input type="checkbox" id="<?= htmlspecialchars($spec['en_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" name="<?= htmlspecialchars($spec['en_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="1" <?= !empty($loaded[$spec['en_note']]) ? 'checked' : '' ?>>
          <label for="<?= htmlspecialchars($spec['en_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Уключыць тэкст дня</label>
        </div>
        <textarea class="ann-week-field" name="<?= htmlspecialchars($spec['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" rows="3" placeholder=""><?= htmlspecialchars((string)($loaded[$spec['note']] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        <div class="field-toggle">
          <input type="checkbox" id="<?= htmlspecialchars($spec['en_clean'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" name="<?= htmlspecialchars($spec['en_clean'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="1" <?= !empty($loaded[$spec['en_clean']]) ? 'checked' : '' ?>>
          <label for="<?= htmlspecialchars($spec['en_clean'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Уключыць уборку</label>
        </div>
        <textarea class="ann-week-field" name="<?= htmlspecialchars($spec['clean'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" rows="2" placeholder="Уборка…"><?= htmlspecialchars((string)($loaded[$spec['clean']] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
      </div>
      <?php endforeach; ?>

      <div class="field-toggle" style="margin-top:14px;">
        <input type="checkbox" id="en_list_1" name="en_list_1" value="1" <?= !empty($loaded['en_list_1']) ? 'checked' : '' ?>>
        <label for="en_list_1">Уключыць пункт 1</label>
      </div>
      <label for="list_1">Пункт спісу 1.</label>
      <textarea id="list_1" class="ann-list-field" name="list_1" rows="4"><?= htmlspecialchars($list1, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

      <div class="field-toggle">
        <input type="checkbox" id="en_list_2" name="en_list_2" value="1" <?= !empty($loaded['en_list_2']) ? 'checked' : '' ?>>
        <label for="en_list_2">Уключыць пункт 2</label>
      </div>
      <label for="list_2">Пункт спісу 2.</label>
      <textarea id="list_2" class="ann-list-field" name="list_2" rows="4"><?= htmlspecialchars($list2, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

      <div class="field-toggle">
        <input type="checkbox" id="en_list_3" name="en_list_3" value="1" <?= !empty($loaded['en_list_3']) ? 'checked' : '' ?>>
        <label for="en_list_3">Уключыць пункт 3</label>
      </div>
      <label for="list_3">Пункт спісу 3.</label>
      <textarea id="list_3" class="ann-list-field" name="list_3" rows="4"><?= htmlspecialchars($list3, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

      <div class="field-toggle">
        <input type="checkbox" id="en_list_4" name="en_list_4" value="1" <?= !empty($loaded['en_list_4']) ? 'checked' : '' ?>>
        <label for="en_list_4">Уключыць пункт 4</label>
      </div>
      <label for="list_4">Пункт спісу 4.</label>
      <textarea id="list_4" class="ann-list-field" name="list_4" rows="4"><?= htmlspecialchars($list4, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

      <div class="field-toggle">
        <input type="checkbox" id="en_cleaning_pool" name="en_cleaning_pool" value="1" <?= !empty($loaded['en_cleaning_pool']) ? 'checked' : '' ?>>
        <label for="en_cleaning_pool">Уключыць выпадковы радок з пулу ўборкі (ніжэй)</label>
      </div>
      <label for="cleaning_pool">Пул уборкі — шмат радкоў; выпадкова адзін радок як пункт спісу</label>
      <textarea id="cleaning_pool" class="ann-pool-field" name="cleaning_pool" rows="4"><?= htmlspecialchars($cleaningPool, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
      <p class="hint">Кожны варыянт з новага радка. Пуста — пункт не дадаецца.</p>

      <div class="field-toggle">
        <input type="checkbox" id="en_thanks_pool" name="en_thanks_pool" value="1" <?= !empty($loaded['en_thanks_pool']) ? 'checked' : '' ?>>
        <label for="en_thanks_pool">Уключыць удзячнасць за ахвяраванні (выпадковы радок з пулу)</label>
      </div>
      <label for="thanks_pool">Удзячнасць — шмат радкоў; апошні пункт спісу перад падпісам</label>
      <textarea id="thanks_pool" class="ann-pool-field" name="thanks_pool" rows="4"><?= htmlspecialchars($thanksPool, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
      <p class="hint">Апошні элемент у &lt;ol&gt; перад падпісам, калі ўключана.</p>

      <div class="field-toggle">
        <input type="checkbox" id="en_signature" name="en_signature" value="1" <?= !empty($loaded['en_signature']) ? 'checked' : '' ?>>
        <label for="en_signature">Уключыць падпіс і пасаду</label>
      </div>
      <label for="signature_name">Падпіс</label>
      <input id="signature_name" type="text" name="signature_name" value="<?= htmlspecialchars($signatureName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="кс. …">

      <label for="signature_role">Пасада</label>
      <input id="signature_role" type="text" name="signature_role" value="<?= htmlspecialchars($signatureRole, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="пробашч">

      <div class="field-toggle">
        <input type="checkbox" id="en_footer" name="en_footer" value="1" <?= !empty($loaded['en_footer']) ? 'checked' : '' ?>>
        <label for="en_footer">Уключыць заключны радок з сайтам</label>
      </div>
      <label for="footer_website">Сайт у заключным радку</label>
      <input id="footer_website" type="text" name="footer_website" value="<?= htmlspecialchars($footerWebsite, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="www.example.by">

      <div class="actions">
        <button type="submit" name="announce_save_settings" value="1">Захаваць налады ў базе</button>
        <button type="submit" name="announce_preview" value="1" formtarget="_blank">Прагляд у новай укладцы</button>
        <button type="submit" name="announce_print" value="1" formtarget="_blank" title="Адкрыць бюлетэнь і выклікаць друк у браўзеры">Друкаваць</button>
      </div>
    </form>
  </div>
  </div>
</body>
</html>
