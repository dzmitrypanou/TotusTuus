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

$message = null;
$error = null;
$view = 'liturgy';
$colorLabels = [
    'green' => 'зялёны',
    'red' => 'чырвоны',
    'purple' => 'фіялетавы',
    'white' => 'белы',
    'rose' => 'ружовы',
    'black' => 'чорны',
];

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
    if (!panel_csrf_token_valid()) {
        $error = 'Сесія пратэрмінаваная або токен несапраўдны. Абнавіце старонку.';
    } else {
        $date = trim((string)($_POST['liturgy_date'] ?? ''));
        $isDelete = isset($_POST['delete_liturgy_day']);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $error = 'Пакажыце карэктную дату ў фармаце YYYY-MM-DD.';
        }
        if ($error === null) {
            try {
                if ($isDelete) {
                    $del = db()->prepare('DELETE FROM liturgy_calendar_entries WHERE liturgy_date = :d');
                    $del->execute([':d' => $date]);
                    $message = 'Запіс дня выдалены.';
                } else {
                    $title = trim((string)($_POST['title_override'] ?? ''));
                    $color = trim((string)($_POST['color_override'] ?? ''));
                    $readingsFull = trim((string)($_POST['readings_full'] ?? ''));
                    if ($color !== '' && !in_array($color, ['green', 'red', 'purple', 'white', 'rose', 'black'], true)) {
                        $error = 'Недапушчальны колер літургічнага дня.';
                    } else {
                        $upsert = db()->prepare(
                            'INSERT INTO liturgy_calendar_entries
                                (liturgy_date, title_override, color_override, readings_full)
                             VALUES
                                (:d, :t, :c, :readings_full)
                             ON DUPLICATE KEY UPDATE
                                title_override = VALUES(title_override),
                                color_override = VALUES(color_override),
                                readings_full = VALUES(readings_full)'
                        );
                        $upsert->execute([
                            ':d' => $date,
                            ':t' => $title !== '' ? $title : null,
                            ':c' => $color !== '' ? $color : null,
                            ':readings_full' => $readingsFull !== '' ? $readingsFull : null,
                        ]);
                        $message = 'Літургічны дзень захаваны.';
                    }
                }
            } catch (Throwable $e) {
                $error = 'Памылка захавання: ' . $e->getMessage();
            }
        }
    }
}

$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
$selectedDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate, new DateTimeZone('UTC'));
if ($selectedDateObj === false || $selectedDateObj->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
    $selectedDateObj = new DateTimeImmutable($selectedDate, new DateTimeZone('UTC'));
}
$selectedMonthFromGet = (int)($_GET['month'] ?? 0);
$selectedYearFromGet = (int)($_GET['year'] ?? 0);
$hasExplicitMonthYear = $selectedMonthFromGet >= 1 && $selectedMonthFromGet <= 12 && $selectedYearFromGet >= 1900 && $selectedYearFromGet <= 2100;

$year = $hasExplicitMonthYear ? $selectedYearFromGet : (int)$selectedDateObj->format('Y');
$month = $hasExplicitMonthYear ? $selectedMonthFromGet : (int)$selectedDateObj->format('n');

$liturgyDioceseFilterApplied = isset($_GET['liturgy_dioc_filter']);
$dioceseOpts = liturgy_diocese_options_default();
if ($liturgyDioceseFilterApplied) {
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

$dioceseLabels = [
    LITURGY_DIOCESE_PINSK => 'Пінская',
    LITURGY_DIOCESE_MINSK_MOGILEV => 'Мінск-Магілёў',
    LITURGY_DIOCESE_VITEBSK => 'Віцебская',
    LITURGY_DIOCESE_GRODNO => 'Гродзенская',
];

$calNavQuery = ['month' => $month, 'year' => $year];
if ($liturgyDioceseFilterApplied) {
    $calNavQuery['liturgy_dioc_filter'] = '1';
    $dNav = [];
    foreach (liturgy_diocese_keys() as $dk) {
        if (!empty($dioceseOpts[$dk])) {
            $dNav[$dk] = '1';
        }
    }
    if ($dNav !== []) {
        $calNavQuery['d'] = $dNav;
    }
}

$monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC'));
$monthEnd = $monthStart->modify('last day of this month');

if ($hasExplicitMonthYear && !isset($_GET['date'])) {
    $selectedDateObj = $monthStart;
    $selectedDate = $selectedDateObj->format('Y-m-d');
}

$liturgyPostAction = '/admin/liturgy.php?' . http_build_query(array_merge($calNavQuery, ['date' => $selectedDate]));

$entries = liturgy_fetch_entries_in_range($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));

$titlesForLectionary = [];
$monthDayMeta = [];
$iterForMeta = $monthStart;
while ($iterForMeta <= $monthEnd) {
    $k = $iterForMeta->format('Y-m-d');
    $autoDay = liturgy_auto_day_info($iterForMeta, $dioceseOpts);
    $row = $entries[$k] ?? null;
    $effectiveTitle = liturgy_effective_title_for_date($iterForMeta, $row, $autoDay);
    $optionalTitle = (string)($autoDay['optional_memorial_title'] ?? '');
    $dateLookupTitle = liturgy_christmas_period_date_lookup_title($iterForMeta, (bool)$autoDay['is_important']);

    $optionalLookups = array_values(array_filter(array_map(
        static fn($t): string => trim((string)$t),
        (array)($autoDay['optional_memorial_lookup_titles'] ?? [])
    ), static fn(string $t): bool => $t !== ''));

    $titlesForLectionary[] = $effectiveTitle;
    if ($optionalTitle !== '') {
        $titlesForLectionary[] = $optionalTitle;
    }
    foreach ($optionalLookups as $olt) {
        $titlesForLectionary[] = $olt;
    }
    if ($dateLookupTitle !== '') {
        $titlesForLectionary[] = $dateLookupTitle;
    }
    $legacyEasterOctave = liturgy_easter_octave_weekday_legacy_lookup_title($iterForMeta);
    if ($legacyEasterOctave !== '') {
        $titlesForLectionary[] = $legacyEasterOctave;
    }

    $monthDayMeta[$k] = [
        'auto' => $autoDay,
        'entry' => $row,
        'effective_title' => $effectiveTitle,
        'optional_title' => $optionalTitle,
        'optional_lookup_titles' => $optionalLookups,
        'optional_prefix_auto' => array_values(array_map(
            static fn($x): bool => (bool)$x,
            (array)($autoDay['optional_memorial_prefix_auto'] ?? [])
        )),
        'optional_memorial_color' => (string)($autoDay['optional_memorial_color'] ?? 'white'),
        'date_lookup_title' => $dateLookupTitle,
        'has_readings' => false,
        'readings_source' => '',
        'reading_slots' => [],
    ];
    $iterForMeta = $iterForMeta->modify('+1 day');
}

$lectionaryMapForMonth = liturgy_fetch_lectionary_map_by_titles($titlesForLectionary);
$daysWithReadingsCount = 0;
$daysWithoutReadingsCount = 0;
foreach ($monthDayMeta as $k => $meta) {
    $optionalLook = (array)($meta['optional_lookup_titles'] ?? []);
    $dayDate = DateTimeImmutable::createFromFormat('Y-m-d', $k, new DateTimeZone('UTC'));
    if ($dayDate === false) {
        $dayDate = null;
    }
    $resolvedReadings = liturgy_resolve_readings_text(
        is_array($meta['entry']) ? $meta['entry'] : null,
        (string)$meta['effective_title'],
        (string)$meta['optional_title'],
        $lectionaryMapForMonth,
        (string)$meta['date_lookup_title'],
        $optionalLook,
        $dayDate,
        (array)($meta['optional_prefix_auto'] ?? [])
    );
    $hasReadings = trim((string)($resolvedReadings['readings_full'] ?? '')) !== '';
    $monthDayMeta[$k]['has_readings'] = $hasReadings;
    $monthDayMeta[$k]['readings_source'] = (string)($resolvedReadings['lectionary_source'] ?? '');
    $monthDayMeta[$k]['reading_slots'] = liturgy_admin_reading_slots(
        is_array($meta['entry']) ? $meta['entry'] : null,
        (string)$meta['effective_title'],
        (string)$meta['optional_title'],
        (string)$meta['date_lookup_title'],
        $optionalLook,
        $lectionaryMapForMonth,
        $dayDate,
        (array)($meta['optional_prefix_auto'] ?? [])
    );
    if ($hasReadings) {
        $daysWithReadingsCount++;
    } else {
        $daysWithoutReadingsCount++;
    }
}

$entry = liturgy_fetch_entry_for_date($selectedDate);
$auto = liturgy_auto_day_info($selectedDateObj, $dioceseOpts);
$readingsFullValue = is_array($entry) ? (string)($entry['readings_full'] ?? '') : '';
$lectionaryKeyValue = is_array($entry) ? (string)($entry['lectionary_key'] ?? '') : '';
$lectionarySourceValue = is_array($entry) ? (string)($entry['lectionary_source'] ?? '') : '';
$autoColorKey = (string)$auto['color'];
$autoColorLabel = (string)($colorLabels[$autoColorKey] ?? $autoColorKey);

$effectiveSel = liturgy_effective_title_for_date($selectedDateObj, $entry, $auto);
$optionalMemSel = (string)($auto['optional_memorial_title'] ?? '');
$dateLookupSel = liturgy_christmas_period_date_lookup_title($selectedDateObj, (bool)$auto['is_important']);
$optionalLookupsSel = array_values(array_filter(array_map(
    static fn($t): string => trim((string)$t),
    (array)($auto['optional_memorial_lookup_titles'] ?? [])
), static fn(string $t): bool => $t !== ''));
$titlesSel = [$effectiveSel];
if ($optionalMemSel !== '') {
    $titlesSel[] = $optionalMemSel;
}
foreach ($optionalLookupsSel as $tlt) {
    $titlesSel[] = $tlt;
}
if ($dateLookupSel !== '') {
    $titlesSel[] = $dateLookupSel;
}
$legacyEasterSel = liturgy_easter_octave_weekday_legacy_lookup_title($selectedDateObj);
if ($legacyEasterSel !== '') {
    $titlesSel[] = $legacyEasterSel;
}
$lectionaryMapSelected = liturgy_fetch_lectionary_map_by_titles($titlesSel);
$optionalPrefixSel = array_values(array_map(
    static fn($x): bool => (bool)$x,
    (array)($auto['optional_memorial_prefix_auto'] ?? [])
));
$selectedReadingSlots = liturgy_admin_reading_slots(
    is_array($entry) ? $entry : null,
    $effectiveSel,
    $optionalMemSel,
    $dateLookupSel,
    $optionalLookupsSel,
    $lectionaryMapSelected,
    $selectedDateObj,
    $optionalPrefixSel
);

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Літургічны каляндар — Totus Tuus</title>
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
    .msg { margin: 0 0 12px; padding: 10px 12px; border-radius: 10px; }
    .ok { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.45); color: #bbf7d0; }
    .err { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.45); color: #fecaca; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 14px; width: 100%; }
    .card { background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 16px; overflow: hidden; }
    .table-wrap { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; min-width: 820px; }
    .table th, .table td { border-bottom: 1px solid #273449; padding: 10px 8px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
    .table tbody tr.row-sunday td { background: rgba(124, 108, 240, 0.14); }
    .table tr:last-child td { border-bottom: none; }
    .table .btn { white-space: nowrap; padding: 6px 10px; }
    .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; border: 1px solid rgba(255,255,255,0.25); vertical-align: middle; }
    .status-row { margin: 6px 0 12px; display: flex; gap: 8px; flex-wrap: wrap; }
    .status-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid #334155;
      background: #0f172a;
      color: #cbd5e1;
      white-space: nowrap;
    }
    .status-chip.ok { border-color: rgba(34,197,94,0.45); color: #86efac; }
    .status-chip.empty { border-color: rgba(239,68,68,0.45); color: #fca5a5; }
    .muted { color: #94a3b8; font-size: 13px; }
    .day-actions { display: flex; flex-direction: column; align-items: stretch; gap: 6px; }
    .day-actions-row { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }
    .day-actions .btn { justify-content: center; padding: 5px 9px; font-size: 12px; }
    .date-stack { display: flex; flex-direction: column; gap: 2px; line-height: 1.25; }
    .date-stack .d-num { font-weight: 700; font-variant-numeric: tabular-nums; color: #f1f5f9; }
    .date-stack .wkday { font-size: 11px; font-weight: 600; color: rgba(148, 163, 184, 0.95); text-transform: uppercase; letter-spacing: 0.06em; }
    .day-primary { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
    .day-primary .dot { flex-shrink: 0; margin-top: 4px; }
    .day-title-text { font-weight: 600; color: #f1f5f9; line-height: 1.35; }
    .readings-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
    .readings-chip {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    .readings-chip.ok { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.35); color: #86efac; }
    .readings-chip.empty { background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }
    .readings-src { font-size: 10px; color: rgba(148, 163, 184, 0.85); max-width: 100%; }
    .readings-slots {
      margin-top: 8px;
      padding: 8px 10px;
      border-radius: var(--radius-sm);
      background: rgba(15, 23, 42, 0.55);
      border: 1px solid rgba(51, 65, 85, 0.45);
    }
    .readings-slots-h {
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: rgba(148, 163, 184, 0.9);
      margin-bottom: 6px;
    }
    .reading-slot {
      display: grid;
      grid-template-columns: minmax(52px, auto) 1fr auto;
      gap: 6px 10px;
      align-items: start;
      font-size: 11px;
      padding: 5px 0;
      border-bottom: 1px solid rgba(51, 65, 85, 0.35);
    }
    .reading-slot:last-child { border-bottom: none; }
    .reading-slot-kind {
      font-weight: 800;
      font-size: 9px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #94a3b8;
      white-space: nowrap;
      padding-top: 2px;
    }
    .reading-slot-label { color: #cbd5e1; line-height: 1.35; min-width: 0; }
    .reading-slot-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 4px 8px;
      align-items: center;
      justify-content: flex-end;
    }
    .reading-slot-ok { color: #86efac; font-weight: 700; font-size: 10px; white-space: nowrap; }
    .reading-slot-miss { color: #fca5a5; font-weight: 700; font-size: 10px; white-space: nowrap; }
    .reading-slot-link {
      font-size: 10px;
      font-weight: 700;
      color: #a5b4fc;
      text-decoration: none;
      white-space: nowrap;
    }
    .reading-slot-link:hover { text-decoration: underline; }
    .readings-slots-empty { font-size: 11px; color: rgba(148, 163, 184, 0.85); margin: 0; padding: 4px 0; }
    label { display: block; margin-top: 10px; margin-bottom: 4px; font-size: 13px; color: #cbd5e1; font-weight: 600; }
    input[type="date"], input[type="text"], select, textarea {
      width: 100%; border: 1px solid #334155; background: #0f172a; color: #e2e8f0;
      border-radius: 10px; padding: 10px 11px; font: inherit;
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
    textarea { min-height: 90px; resize: vertical; max-width: 100%; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .month-picker { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 110px); gap: 8px; margin-bottom: 12px; align-items: end; }
    .month-picker label { margin-top: 0; }
    .month-picker .btn { width: 100%; }
    .diocese-cal-label {
      font-size: 0.625rem;
      font-weight: 700;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: rgba(148, 163, 184, 0.85);
      display: block;
      margin-bottom: 8px;
      margin-top: 4px;
    }
    .diocese-checkboxes-cal { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
    label.diocese-cb-cal {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin: 0;
      font-weight: 600;
      cursor: pointer;
      font-size: 13px;
      color: #cbd5e1;
    }
    label.diocese-cb-cal input { width: auto; margin: 0; }
    button { border: 1px solid #334155; background: #7c6cf0; color: #fff; font-weight: 700; border-radius: 10px; padding: 10px 12px; cursor: pointer; }
    .danger { background: #7f1d1d; border-color: #b91c1c; }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
    }
    @media (max-width: 980px) { .table { min-width: 640px; } }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-brand">
      <h1>Totus Tuus</h1>
      <p class="header-tagline">Панэль кіравання Святой Памяці<br>Біскупа Казіміра Велікасельца OP</p>
    </div>
    <?php
        $panelNavPage = 'liturgy';
        $panelNavView = 'categories';
        $panelNavCalYear = $year;
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

    <?php if ($message !== null): ?><p class="msg ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="msg err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

    <div class="grid">
      <div class="card">
        <h2 style="margin:0 0 8px; font-size:1rem;">Дні <?= htmlspecialchars($monthStart->format('m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p class="muted" style="margin-top:0;">* Важныя даты лічацца аўтаматычна. Ніжэй для кожнага дня паказаны ўсе варыянты чытанняў з лекцыянарыя (асноўны дзень, прывязка па датце, кожны успамін асобна, дадатковыя імшы), калі яны ўжываюцца да гэтага дня. Тут жа можна пераазначыць тэкст і колер у запісе дня. <strong>Дыяцэзіі:</strong> адлюстроўваюцца святы з БД з улікам поляў any/all/forbid для абраных дыяцэзій (як у публічным API).</p>
        <div class="status-row">
          <span class="status-chip ok">Ёсць чытанні: <?= (int)$daysWithReadingsCount ?></span>
          <span class="status-chip empty">Пустыя дні: <?= (int)$daysWithoutReadingsCount ?></span>
        </div>
        <form method="get" class="month-picker">
          <input type="hidden" name="liturgy_dioc_filter" value="1">
          <div>
            <label for="month">Месяц</label>
            <select id="month" name="month">
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= htmlspecialchars(sprintf('%02d', $m), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label for="year">Год</label>
            <input id="year" type="text" name="year" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" value="<?= htmlspecialchars((string)$year, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          </div>
          <div style="grid-column: 1 / -1;">
            <span class="diocese-cal-label">Адлюстроўваць святы дыяцэзій (з БД)</span>
            <div class="diocese-checkboxes-cal">
              <?php foreach (liturgy_diocese_keys() as $dk): ?>
                <label class="diocese-cb-cal">
                  <input type="checkbox" name="d[<?= htmlspecialchars($dk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]" value="1" <?= !empty($dioceseOpts[$dk]) ? 'checked' : '' ?>>
                  <?= htmlspecialchars((string)($dioceseLabels[$dk] ?? $dk), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="muted" style="margin:8px 0 0; font-size:12px;">Калі старонка адкрыта без параметраў фільтра, усе дыяцэзіі ўключаныя. Пасля «Перайсці» ў адрас дадаецца звычайны рэжым фільтра.</p>
          </div>
          <div style="grid-column: 1 / -1;">
            <button type="submit" class="btn">Перайсці</button>
          </div>
        </form>
        <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:96px;">Дата</th>
              <th>Літургічны дзень</th>
              <th style="width:200px;">Дзеянні</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $iter = $monthStart;
          while ($iter <= $monthEnd):
              $k = $iter->format('Y-m-d');
              $dayMeta = $monthDayMeta[$k] ?? null;
              $autoDay = is_array($dayMeta) ? (array)($dayMeta['auto'] ?? []) : liturgy_auto_day_info($iter, $dioceseOpts);
              $row = is_array($dayMeta) ? ($dayMeta['entry'] ?? null) : ($entries[$k] ?? null);
              $overrideColor = is_array($row) ? trim((string)($row['color_override'] ?? '')) : '';
              $effectiveColor = $overrideColor !== '' ? $overrideColor : (string)$autoDay['color'];
              $rawEffectiveTitle = is_array($dayMeta)
                  ? (string)($dayMeta['effective_title'] ?? (string)($autoDay['title'] ?? ''))
                  : ((is_array($row) ? trim((string)($row['title_override'] ?? '')) : '') ?: (string)($autoDay['title'] ?? ''));
              // Як у JSON API: аднолькавае адлюстраванне (актава Пасхі, без дубля дня тыдня ў загалоўку).
              $title = liturgy_title_with_weekday_for_display($iter, $rawEffectiveTitle);
              $lectionaryPrefillTitle = $rawEffectiveTitle;
              $hasReadings = is_array($dayMeta) ? (bool)($dayMeta['has_readings'] ?? false) : false;
              $readingsSource = is_array($dayMeta) ? (string)($dayMeta['readings_source'] ?? '') : '';
              $readingSlots = is_array($dayMeta) ? (array)($dayMeta['reading_slots'] ?? []) : [];
              $slotsFilled = 0;
              foreach ($readingSlots as $rs) {
                  if (is_array($rs) && !empty($rs['has_text'])) {
                      $slotsFilled++;
                  }
              }
              $slotsTotal = count($readingSlots);
              $wk = liturgy_weekday_name($iter);
              $kindShort = [
                  'manual' => 'Запіс',
                  'main' => 'Асноўн.',
                  'date' => 'Па датце',
                  'optional' => 'Успамін',
                  'special' => 'Імша',
              ];
              $isSundayRow = (int)$iter->format('w') === 0;
          ?>
            <tr<?= $isSundayRow ? ' class="row-sunday"' : '' ?>>
              <td>
                <div class="date-stack">
                  <span class="d-num"><?= htmlspecialchars($iter->format('d.m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="wkday"><?= htmlspecialchars($wk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="muted" style="font-size:10px; font-variant-numeric: tabular-nums;"><?= htmlspecialchars($iter->format('Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
              </td>
              <td>
                <div class="day-primary">
                  <span class="dot" style="background:<?= htmlspecialchars(liturgy_color_hex($effectiveColor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" title="<?= htmlspecialchars((string)($colorLabels[$effectiveColor] ?? $effectiveColor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></span>
                  <div class="day-title-text"><?= htmlspecialchars($title !== '' ? $title : 'Звычайны дзень', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
                <div class="readings-row" style="margin-bottom:6px;">
                  <span class="readings-chip <?= $hasReadings ? 'ok' : 'empty' ?>"><?= $hasReadings ? 'API: чытанні ёсць' : 'API: без чытанняў' ?></span>
                  <?php if ($slotsTotal > 0): ?>
                    <span class="readings-chip <?= $slotsFilled === $slotsTotal ? 'ok' : 'empty' ?>" style="font-weight:700;">Лекцыянарый: <?= (int)$slotsFilled ?>/<?= (int)$slotsTotal ?></span>
                  <?php endif; ?>
                  <?php if ($readingsSource !== ''): ?>
                    <span class="readings-src"><?= htmlspecialchars($readingsSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($slotsTotal > 0): ?>
                <div class="readings-slots">
                  <div class="readings-slots-h">Чытанні па варыянтах</div>
                  <?php foreach ($readingSlots as $slot): ?>
                    <?php if (!is_array($slot)) { continue; } ?>
                    <?php
                    $sk = (string)($slot['kind'] ?? '');
                    $slab = (string)($kindShort[$sk] ?? $sk);
                    $slabel = (string)($slot['label'] ?? '');
                    $lk = (string)($slot['lookup_title'] ?? '');
                    $hasT = !empty($slot['has_text']);
                    ?>
                    <div class="reading-slot">
                      <span class="reading-slot-kind"><?= htmlspecialchars($slab, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <div class="reading-slot-label"><?= htmlspecialchars($slabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <div class="reading-slot-meta">
                        <span class="<?= $hasT ? 'reading-slot-ok' : 'reading-slot-miss' ?>"><?= $hasT ? 'ёсць' : 'няма' ?></span>
                        <?php if ($lk !== ''): ?>
                          <a class="reading-slot-link" href="/admin/lectionary.php?prefill_title=<?= urlencode($lk) ?>">лекцыянарый</a>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="readings-slots-empty">Няма дадатковых радкоў лекцыянарыя для гэтага дня.</p>
                <?php endif; ?>
              </td>
              <td>
                <div class="day-actions">
                  <div class="day-actions-row">
                    <a class="btn" href="<?= htmlspecialchars('/admin/liturgy.php?' . http_build_query(array_merge($calNavQuery, ['date' => $k])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Адкрыць</a>
                    <a class="btn" href="/admin/lectionary.php?prefill_title=<?= urlencode($lectionaryPrefillTitle) ?>">Лекцыянарый (дзень)</a>
                  </div>
                </div>
              </td>
            </tr>
          <?php
              $iter = $iter->modify('+1 day');
          endwhile;
          ?>
          </tbody>
        </table>
        </div>
      </div>

      <div class="card">
        <h2 style="margin:0 0 8px; font-size:1rem;">Рэдагаванне дня</h2>
        <p class="muted" style="margin-top:0;display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap;">
          <span class="dot" style="background:<?= htmlspecialchars(liturgy_color_hex($autoColorKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>;flex-shrink:0;margin-top:3px;"></span>
          <span>Аўта: <strong><?= htmlspecialchars((string)$auto['title'] !== '' ? (string)$auto['title'] : 'Звычайны дзень', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>,
          колер <strong><?= htmlspecialchars($autoColorLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
          (<?= htmlspecialchars(liturgy_weekday_name($selectedDateObj), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>, <?= htmlspecialchars($selectedDateObj->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).
          <?php if ($lectionaryKeyValue !== ''): ?>
            <br>Лекцыянар: <code><?= htmlspecialchars($lectionaryKeyValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>
            <?php if ($lectionarySourceValue !== ''): ?>(<code><?= htmlspecialchars($lectionarySourceValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>)<?php endif; ?>.
          <?php endif; ?>
          </span>
        </p>
        <?php
        $editKindShort = [
            'manual' => 'Запіс',
            'main' => 'Асноўн.',
            'date' => 'Па датце',
            'optional' => 'Успамін',
            'special' => 'Імша',
        ];
        ?>
        <?php if (count($selectedReadingSlots) > 0): ?>
        <div class="readings-slots" style="margin-bottom:14px;">
          <div class="readings-slots-h">Усе чытанні за дзень (лекцыянарый)</div>
          <?php foreach ($selectedReadingSlots as $eslot): ?>
            <?php if (!is_array($eslot)) { continue; } ?>
            <?php
            $esk = (string)($eslot['kind'] ?? '');
            $eslab = (string)($editKindShort[$esk] ?? $esk);
            $eslabel = (string)($eslot['label'] ?? '');
            $elk = (string)($eslot['lookup_title'] ?? '');
            $ehas = !empty($eslot['has_text']);
            ?>
            <div class="reading-slot">
              <span class="reading-slot-kind"><?= htmlspecialchars($eslab, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <div class="reading-slot-label"><?= htmlspecialchars($eslabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="reading-slot-meta">
                <span class="<?= $ehas ? 'reading-slot-ok' : 'reading-slot-miss' ?>"><?= $ehas ? 'ёсць' : 'няма' ?></span>
                <?php if ($elk !== ''): ?>
                  <a class="reading-slot-link" href="/admin/lectionary.php?prefill_title=<?= urlencode($elk) ?>">лекцыянарый</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="post" action="<?= htmlspecialchars($liturgyPostAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <?= panel_csrf_field() ?>
          <label for="liturgy_date">Дата</label>
          <input id="liturgy_date" type="date" name="liturgy_date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>

          <label for="title_override">Назва (неабавязкова, калі трэба пераазначыць аўта)</label>
          <input id="title_override" type="text" name="title_override" value="<?= htmlspecialchars((string)($entry['title_override'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

          <label for="color_override">Колер літургічнага дня (неабавязкова)</label>
          <select id="color_override" name="color_override">
            <?php
            $currColor = (string)($entry['color_override'] ?? '');
            ?>
            <option value="" <?= $currColor === '' ? 'selected' : '' ?>>Аўта</option>
            <?php foreach (['green', 'red', 'purple', 'white', 'rose', 'black'] as $c): ?>
              <option value="<?= $c ?>" <?= $currColor === $c ? 'selected' : '' ?>><?= htmlspecialchars((string)($colorLabels[$c] ?? $c), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>

          <label for="readings_full">Поўны тэкст дня (цалкам, для API)</label>
          <textarea id="readings_full" name="readings_full" style="height:460px;"><?= htmlspecialchars($readingsFullValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

          <div class="actions">
            <button type="submit" name="save_liturgy_day" value="1">Захаваць</button>
            <button type="submit" class="danger" name="delete_liturgy_day" value="1" onclick="return confirm('Выдаліць запіс гэтага дня?')">Выдаліць запіс</button>
          </div>
        </form>
      </div>
    </div>
</body>
</html>

