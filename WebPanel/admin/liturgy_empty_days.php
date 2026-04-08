<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel_security.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/schema.php';
require_once __DIR__ . '/../includes/panel_auth.php';
require_once __DIR__ . '/../api/liturgy_common.php';

panel_configure_session_before_start();
session_start();
panel_ensure_csrf_token();
panel_send_admin_security_headers();

if (!panel_is_logged_in()) {
    header('Location: /', true, 302);
    exit;
}

ensureSchemaAndSeed();
panel_require_section_get('liturgy');

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

$currentYear = (int)date('Y');
$fromYear = (int)($_GET['from_year'] ?? $currentYear);
$toYear = (int)($_GET['to_year'] ?? $currentYear);

if ($fromYear < 1900 || $fromYear > 2100) {
    $fromYear = $currentYear;
}
if ($toYear < 1900 || $toYear > 2100) {
    $toYear = $currentYear;
}
if ($fromYear > $toYear) {
    [$fromYear, $toYear] = [$toYear, $fromYear];
}

$from = new DateTimeImmutable(sprintf('%04d-01-01', $fromYear), new DateTimeZone('UTC'));
$to = new DateTimeImmutable(sprintf('%04d-12-31', $toYear), new DateTimeZone('UTC'));

$entries = liturgy_fetch_entries_in_range($from->format('Y-m-d'), $to->format('Y-m-d'));
$titlesForLectionary = [];
$dayMeta = [];
$cursor = $from;
while ($cursor <= $to) {
    $k = $cursor->format('Y-m-d');
    $auto = liturgy_auto_day_info($cursor);
    $entry = $entries[$k] ?? null;
    $effectiveTitle = liturgy_effective_title_for_date($cursor, $entry, $auto);
    $optionalTitle = (string)($auto['optional_memorial_title'] ?? '');
    $dateLookupTitle = liturgy_christmas_period_date_lookup_title($cursor, (bool)$auto['is_important']);

    $titlesForLectionary[] = $effectiveTitle;
    if ($optionalTitle !== '') {
        $titlesForLectionary[] = $optionalTitle;
    }
    if ($dateLookupTitle !== '') {
        $titlesForLectionary[] = $dateLookupTitle;
    }
    $legacyEasterOctave = liturgy_easter_octave_weekday_legacy_lookup_title($cursor);
    if ($legacyEasterOctave !== '') {
        $titlesForLectionary[] = $legacyEasterOctave;
    }

    $dayMeta[$k] = [
        'entry' => $entry,
        'title' => $effectiveTitle,
        'optional_title' => $optionalTitle,
        'date_lookup_title' => $dateLookupTitle,
    ];
    $cursor = $cursor->modify('+1 day');
}

$lectionaryMap = liturgy_fetch_lectionary_map_by_titles($titlesForLectionary);
$emptyByTitle = [];
foreach ($dayMeta as $k => $meta) {
    $dayDate = DateTimeImmutable::createFromFormat('Y-m-d', $k, new DateTimeZone('UTC'));
    if ($dayDate === false) {
        $dayDate = null;
    }
    $resolved = liturgy_resolve_readings_text(
        is_array($meta['entry']) ? $meta['entry'] : null,
        (string)$meta['title'],
        (string)$meta['optional_title'],
        $lectionaryMap,
        (string)$meta['date_lookup_title'],
        [],
        $dayDate
    );
    if (trim((string)($resolved['readings_full'] ?? '')) !== '') {
        continue;
    }
    $title = trim((string)$meta['title']);
    if ($title === '') {
        $title = 'Звычайны дзень';
    }
    if (!isset($emptyByTitle[$title])) {
        $emptyByTitle[$title] = [
            'title' => $title,
            'count' => 0,
            'first_date' => $k,
            'last_date' => $k,
        ];
    }
    $emptyByTitle[$title]['count']++;
    if ($k < $emptyByTitle[$title]['first_date']) {
        $emptyByTitle[$title]['first_date'] = $k;
    }
    if ($k > $emptyByTitle[$title]['last_date']) {
        $emptyByTitle[$title]['last_date'] = $k;
    }
}
ksort($emptyByTitle, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Пустыя дні — Totus Tuus</title>
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
      background: linear-gradient(135deg, rgba(30, 27, 75, 0.95) 0%, rgba(15, 23, 42, 0.92) 50%, rgba(30, 41, 59, 0.88) 100%);
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
    .btn { display:inline-flex; align-items:center; justify-content:center; padding:8px 12px; border:1px solid #334155; border-radius:10px; background:#1e293b; color:#e2e8f0; text-decoration:none; font-weight:600; }
    .btn.active { background:#334155; }
    .card { background:#111827; border:1px solid #334155; border-radius:14px; padding:14px; }
    .filters { display:grid; grid-template-columns: repeat(2, minmax(160px, 1fr)); gap:10px; align-items:end; margin-bottom:12px; }
    .filters-actions { grid-column: 1 / -1; display:flex; justify-content:flex-start; }
    .filters-actions .btn { min-width: 120px; }
    label { display:block; margin-bottom:4px; font-size:13px; color:#cbd5e1; font-weight:600; }
    input { width:100%; border:1px solid #334155; background:#0f172a; color:#e2e8f0; border-radius:10px; padding:10px 11px; font:inherit; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th, td { border-bottom:1px solid #273449; padding:8px 6px; text-align:left; vertical-align:top; }
    tr:last-child td { border-bottom:none; }
    .muted { color:#94a3b8; font-size:13px; }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
      .top-nav { justify-content: flex-start; max-width: none; width: 100%; align-items: flex-start; }
      .top-nav-row { justify-content: flex-start; gap: 10px 14px; }
    }
    @media (max-width: 760px) {
      .filters { grid-template-columns: 1fr; }
      .filters-actions { justify-content: stretch; }
      .filters-actions .btn { width: 100%; }
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
        $panelNavPage = 'liturgy_empty';
        $panelNavView = 'categories';
        $panelNavCalYear = $fromYear;
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <div class="card">
    <h2 style="margin:0 0 10px; font-size:1.05rem;">Пустыя дні (без дубляў)</h2>
    <form method="get" class="filters">
      <div>
        <label for="from_year">Ад года</label>
        <input id="from_year" type="text" name="from_year" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" value="<?= htmlspecialchars((string)$fromYear, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      </div>
      <div>
        <label for="to_year">Да года</label>
        <input id="to_year" type="text" name="to_year" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" value="<?= htmlspecialchars((string)$toYear, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      </div>
      <div class="filters-actions">
        <button class="btn" type="submit">Паказаць</button>
      </div>
    </form>

    <p class="muted" style="margin-top:0;">Перыяд: <?= htmlspecialchars((string)$fromYear, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — <?= htmlspecialchars((string)$toYear, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>. У табліцы толькі ўнікальныя назвы дзён без чытанняў.</p>

    <table>
      <thead>
        <tr>
          <th>Назва дня</th>
          <th style="width:90px;">К-ць</th>
          <th style="width:120px;">Першая дата</th>
          <th style="width:120px;">Апошняя дата</th>
          <th style="width:170px;"></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($emptyByTitle === []): ?>
        <tr><td colspan="5" class="muted">Пустых дзён у гэтым дыяпазоне не знойдзена.</td></tr>
      <?php else: ?>
        <?php foreach ($emptyByTitle as $row): ?>
          <?php
          $fdObj = DateTimeImmutable::createFromFormat('Y-m-d', (string)$row['first_date'], new DateTimeZone('UTC'));
          $titleShown = $fdObj instanceof DateTimeImmutable
              ? liturgy_title_with_weekday_for_display($fdObj, (string)$row['title'])
              : (string)$row['title'];
          ?>
          <tr>
            <td><?= htmlspecialchars($titleShown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= (int)$row['count'] ?></td>
            <td><?= htmlspecialchars((string)$row['first_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['last_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><a class="btn" href="/admin/lectionary.php?prefill_title=<?= urlencode((string)$row['title']) ?>">Стварыць чытанне</a></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>

