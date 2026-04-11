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

$dioceseLabels = [
    LITURGY_DIOCESE_PINSK => 'Пінская',
    LITURGY_DIOCESE_MINSK_MOGILEV => 'Мінск-Магілёў',
    LITURGY_DIOCESE_VITEBSK => 'Віцебская',
    LITURGY_DIOCESE_GRODNO => 'Гродзенская',
];

const OBS_GAP_YEAR_MIN = 1970;
const OBS_GAP_YEAR_MAX = 2100;

function obs_gap_clamp_year(int $y): int
{
    return max(OBS_GAP_YEAR_MIN, min(OBS_GAP_YEAR_MAX, $y));
}

/**
 * @return list<int>
 */
function obs_gap_years_for_period(string $period, int $yOne, int $yFrom, int $yTo): array
{
    switch ($period) {
        case 'range':
            $lo = obs_gap_clamp_year(min($yFrom, $yTo));
            $hi = obs_gap_clamp_year(max($yFrom, $yTo));
            if ($lo > $hi) {
                return [obs_gap_clamp_year((int)date('Y'))];
            }

            return range($lo, $hi);
        case 'all':
            return range(OBS_GAP_YEAR_MIN, OBS_GAP_YEAR_MAX);
        case 'one':
        default:
            return [obs_gap_clamp_year($yOne)];
    }
}

function obs_gap_format_ymd_be(string $ymd): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

    return $dt === false ? $ymd : $dt->format('d.m.Y');
}

function obs_gap_dm_from_ymd(string $ymd): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

    return $dt === false ? '' : $dt->format('d.m');
}

function obs_gap_dm_range_label(string $minYmd, string $maxYmd): string
{
    $a = obs_gap_dm_from_ymd($minYmd);
    $b = obs_gap_dm_from_ymd($maxYmd);
    if ($a === '' || $b === '') {
        return $a !== '' ? $a : $b;
    }

    return $a === $b ? $a : ($a . '–' . $b);
}

/**
 * @param array<string, true> $lectionaryKeysWithText
 * @param list<int>           $years
 *
 * @return list<array<string, mixed>>
 */
function obs_gap_compute_missing(
    array $lectionaryKeysWithText,
    array $dioceseOpts,
    bool $obsHideNonDiocesan,
    array $years
): array {
    $obsCandidates = [];
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
        $obsCandidates[] = $obsRow;
    }

    /** @var array<int, array<string, mixed>> $byId */
    $byId = [];
    foreach ($years as $obsYear) {
        $easterObs = liturgy_observances_easter_sunday($obsYear);
        foreach ($obsCandidates as $obsRow) {
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
            $id = (int)($obsRow['id'] ?? 0);
            $reqAny = trim((string)($obsRow['require_any_of'] ?? ''));
            $reqAll = trim((string)($obsRow['require_all_of'] ?? ''));
            $forbid = trim((string)($obsRow['forbid_if_any_of'] ?? ''));
            $rec = [
                'id' => $id,
                'date_min' => $ymd,
                'date_max' => $ymd,
                'title' => $obsTitle,
                'require_any_of' => $reqAny,
                'require_all_of' => $reqAll,
                'forbid_if_any_of' => $forbid,
                'source_tag' => trim((string)($obsRow['source_tag'] ?? '')),
            ];
            if (!isset($byId[$id])) {
                $byId[$id] = $rec;
            } else {
                if (strcmp($ymd, (string)$byId[$id]['date_min']) < 0) {
                    $byId[$id]['date_min'] = $ymd;
                }
                if (strcmp($ymd, (string)$byId[$id]['date_max']) > 0) {
                    $byId[$id]['date_max'] = $ymd;
                }
            }
        }
    }

    $out = array_values($byId);
    usort($out, static function (array $a, array $b): int {
        $c = strcmp((string)$a['date_min'], (string)$b['date_min']);
        if ($c !== 0) {
            return $c;
        }

        return strcmp((string)$a['title'], (string)$b['title']);
    });

    return $out;
}

$obsFilterApplied = isset($_GET['obs_filter']);
$obsYear = (int)($_GET['obs_year'] ?? date('Y'));
if ($obsYear < OBS_GAP_YEAR_MIN || $obsYear > OBS_GAP_YEAR_MAX) {
    $obsYear = (int)date('Y');
}
$obsPeriod = trim((string)($_GET['obs_period'] ?? 'one'));
if (!in_array($obsPeriod, ['one', 'range', 'all'], true)) {
    $obsPeriod = 'one';
}
$obsYearFrom = obs_gap_clamp_year((int)($_GET['obs_year_from'] ?? $obsYear));
$obsYearTo = obs_gap_clamp_year((int)($_GET['obs_year_to'] ?? $obsYear));
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

$gapYears = obs_gap_years_for_period($obsPeriod, $obsYear, $obsYearFrom, $obsYearTo);
$panelNavGapYear = $gapYears === [] ? $obsYear : max($gapYears);

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

$observancesMissingLectionary = obs_gap_compute_missing(
    $lectionaryKeysWithText,
    $dioceseOpts,
    $obsHideNonDiocesan,
    $gapYears
);

$obsGapBaseQuery = [
    'obs_filter' => '1',
    'obs_period' => $obsPeriod,
    'obs_year' => (string)$obsYear,
    'obs_year_from' => (string)$obsYearFrom,
    'obs_year_to' => (string)$obsYearTo,
];
$obsGapBaseD = [];
foreach (liturgy_diocese_keys() as $dk) {
    if (!empty($dioceseOpts[$dk])) {
        $obsGapBaseD[$dk] = '1';
    }
}
if ($obsGapBaseD !== []) {
    $obsGapBaseQuery['d'] = $obsGapBaseD;
}
if ($obsHideNonDiocesan) {
    $obsGapBaseQuery['obs_hide_general'] = '1';
}
$lectionaryBackHref = '/admin/lectionary.php?' . http_build_query($obsGapBaseQuery);

$exportKind = isset($_GET['export']) ? (string)$_GET['export'] : '';
if ($exportKind === 'txt' || $exportKind === 'txt_md') {
    $ts = time();
    $suffix = sprintf(
        '%02d_%02d_%02d_%02d_%04d',
        (int)date('H', $ts),
        (int)date('i', $ts),
        (int)date('d', $ts),
        (int)date('m', $ts),
        (int)date('Y', $ts)
    );
    $base = $exportKind === 'txt_md' ? 'lectionary_is_missing_by_day_' : 'lectionary_is_missing_';
    $fn = $base . $suffix . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    if ($exportKind === 'txt') {
        foreach ($observancesMissingLectionary as $om) {
            $min = (string)$om['date_min'];
            $max = (string)$om['date_max'];
            $datePart = $min === $max
                ? obs_gap_format_ymd_be($min)
                : (obs_gap_format_ymd_be($min) . ' – ' . obs_gap_format_ymd_be($max));
            echo $datePart . "\t" . (string)$om['title'] . "\n";
        }
    } else {
        $rows = $observancesMissingLectionary;
        usort($rows, static function (array $a, array $b): int {
            $ka = substr((string)$a['date_min'], 5) . "\0" . (string)$a['title'];
            $kb = substr((string)$b['date_min'], 5) . "\0" . (string)$b['title'];

            return strcmp($ka, $kb);
        });
        foreach ($rows as $om) {
            echo obs_gap_dm_range_label((string)$om['date_min'], (string)$om['date_max']) . "\t" . (string)$om['title'] . "\n";
        }
    }
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Святы без чытанняў — Лекцыянарый — Totus Tuus</title>
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
    .toolbar-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
    .card { background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 14px; overflow: hidden; }
    .table-wrap { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .table th, .table td { border-bottom: 1px solid #273449; padding: 7px 6px; text-align: left; vertical-align: top; }
    .table tr:last-child td { border-bottom: none; }
    .muted { color: #94a3b8; font-size: 13px; }
    label { display: block; margin-top: 10px; margin-bottom: 4px; font-size: 13px; color: #cbd5e1; font-weight: 600; }
    input[type="number"] {
      width: 100%;
      border: 1px solid #334155;
      background: #0f172a;
      color: #e2e8f0;
      border-radius: 10px;
      padding: 10px 11px;
      font: inherit;
    }
    .obs-filter-row { display: flex; flex-wrap: wrap; gap: 12px 18px; align-items: flex-end; margin-bottom: 12px; }
    .obs-filter-row .field-year { min-width: 120px; flex: 0 0 auto; }
    .obs-filter-row .field-year label { margin-top: 0; }
    .diocese-checkboxes { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
    label.diocese-cb { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-weight: 600; cursor: pointer; }
    label.diocese-cb input { width: auto; margin: 0; }
    label.obs-hide-general-cb { flex-basis: 100%; margin-top: 4px; max-width: 42rem; }
    .nav-group-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.12em; color: var(--muted); font-weight: 700; }
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
      transition: background 0.15s ease, border-color 0.15s ease;
      box-sizing: border-box;
      line-height: 1.2;
    }
    button.btn-pill { margin-top: 0; font-family: inherit; cursor: pointer; box-shadow: none; }
    a.btn-pill:hover, button.btn-pill:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.14);
      filter: none;
    }
    button {
      border: 1px solid #334155;
      background: #7c6cf0;
      color: #fff;
      font-weight: 700;
      border-radius: 10px;
      padding: 10px 12px;
      cursor: pointer;
      font-family: inherit;
    }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
    }
    @media (max-width: 980px) {
      .header { padding: 18px 16px; }
    }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-brand">
      <h1>Totus Tuus</h1>
      <p class="header-tagline">Панэль кіравання Святой Памяці<br>Біскупа Казіміра Велікасельца OP</p>
    </div>
    <?php
    $panelNavPage = 'lectionary_gap';
    $panelNavView = 'categories';
    $panelNavCalYear = $panelNavGapYear;
    require __DIR__ . '/../includes/panel_admin_nav.php';
    ?>
  </div>

  <div class="toolbar-row">
    <a class="btn-pill" href="<?= htmlspecialchars($lectionaryBackHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">← Да лекцыянарыя</a>
  </div>

  <div class="grid">
    <div class="card">
      <h2 style="margin:0 0 8px; font-size:1.05rem;">Святы з БД без чытанняў (варынт «альбо»)</h2>
      <p class="muted" style="margin-top:0;">Радкі <code>optional</code> з <code>liturgy_observances</code>, якія трапяюць у каляндар пры абраных дыяцэзіях, але не маюць непустога тэксту ў лекцыянарыі па ключы назвы. Ключ збіраецца як для поля «Назва» запісу лекцыянарыя.</p>
      <?php
      $obsGapExportTxt = '/admin/lectionary_observances_gap.php?' . http_build_query(array_merge($obsGapBaseQuery, ['export' => 'txt']));
      $obsGapExportTxtMd = '/admin/lectionary_observances_gap.php?' . http_build_query(array_merge($obsGapBaseQuery, ['export' => 'txt_md']));
      ?>
      <form method="get" class="obs-filter-form" id="obs-gap-filter-form" action="/admin/lectionary_observances_gap.php">
        <input type="hidden" name="obs_filter" value="1">
        <div class="obs-period-row" style="margin-bottom:12px;">
          <span class="nav-group-label" style="display:block;margin-bottom:6px;">Перыяд</span>
          <div class="obs-period-radios" style="display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;">
            <label class="diocese-cb" style="margin:0;">
              <input type="radio" name="obs_period" value="one" <?= $obsPeriod === 'one' ? 'checked' : '' ?>>
              Адзін год
            </label>
            <label class="diocese-cb" style="margin:0;">
              <input type="radio" name="obs_period" value="range" <?= $obsPeriod === 'range' ? 'checked' : '' ?>>
              Дыяпазон гадоў
            </label>
            <label class="diocese-cb" style="margin:0;">
              <input type="radio" name="obs_period" value="all" <?= $obsPeriod === 'all' ? 'checked' : '' ?>>
              Увесь час (1970–2100)
            </label>
          </div>
        </div>
        <div class="obs-filter-row">
          <div class="field-year">
            <label for="obs_year">Год</label>
            <input id="obs_year" type="number" name="obs_year" min="1970" max="2100" value="<?= $obsYear ?>">
          </div>
          <div class="field-year obs-year-range">
            <label for="obs_year_from">Ад году</label>
            <input id="obs_year_from" type="number" name="obs_year_from" min="1970" max="2100" value="<?= $obsYearFrom ?>">
          </div>
          <div class="field-year obs-year-range">
            <label for="obs_year_to">Да году</label>
            <input id="obs_year_to" type="number" name="obs_year_to" min="1970" max="2100" value="<?= $obsYearTo ?>">
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
      <p style="margin:0 0 12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <a class="btn-pill" href="<?= htmlspecialchars($obsGapExportTxt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" download>Сцягнуць .txt (даты + назва)</a>
        <a class="btn-pill" href="<?= htmlspecialchars($obsGapExportTxtMd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" download>Сцягнуць .txt (дзень.месяц + назва)</a>
      </p>
      <div class="table-wrap">
        <table class="table">
          <thead>
          <tr>
            <th style="width:128px;">Дата(ы)</th>
            <th style="width:56px;">БД</th>
            <th>Назва</th>
            <th style="width:140px;">Умовы дыяцэзій</th>
            <th style="width:200px;"></th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($observancesMissingLectionary as $om): ?>
            <tr>
              <td><code><?php
                $dmin = (string)$om['date_min'];
                $dmax = (string)$om['date_max'];
                if ($dmin === $dmax) {
                    echo htmlspecialchars($dmin . ' · ' . obs_gap_format_ymd_be($dmin), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                } else {
                    echo htmlspecialchars(
                        $dmin . ' – ' . $dmax . ' · ' . obs_gap_format_ymd_be($dmin) . ' – ' . obs_gap_format_ymd_be($dmax),
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    );
                }
                ?></code></td>
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
                    'obs_period' => $obsPeriod,
                    'obs_year' => (string)$obsYear,
                    'obs_year_from' => (string)$obsYearFrom,
                    'obs_year_to' => (string)$obsYearTo,
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
                if ($obsHideNonDiocesan) {
                    $prefillQ['obs_hide_general'] = '1';
                }
                $prefillHref = '/admin/lectionary.php?' . http_build_query($prefillQ);
                ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                  <a class="btn-pill" href="<?= htmlspecialchars($prefillHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Стварыць чытанне</a>
                  <a class="btn-pill" href="/admin/liturgy_observances_edit.php?id=<?= (int)$om['id'] ?>">Свята БД</a>
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
  </div>
  <script>
  (function () {
    var form = document.getElementById('obs-gap-filter-form');
    if (!form) return;
    var radios = form.querySelectorAll('input[name="obs_period"]');
    var yOne = document.getElementById('obs_year');
    var yFrom = document.getElementById('obs_year_from');
    var yTo = document.getElementById('obs_year_to');
    function sync() {
      var mode = 'one';
      radios.forEach(function (r) { if (r.checked) mode = r.value; });
      if (yOne) {
        yOne.disabled = mode !== 'one';
        yOne.closest('.field-year').style.opacity = mode === 'one' ? '1' : '0.45';
      }
      [yFrom, yTo].forEach(function (el) {
        if (!el) return;
        var on = mode === 'range';
        el.disabled = !on;
        var wrap = el.closest('.obs-year-range');
        if (wrap) wrap.style.opacity = on ? '1' : '0.45';
      });
    }
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  })();
  </script>
</body>
</html>
