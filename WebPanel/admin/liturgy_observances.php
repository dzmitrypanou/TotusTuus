<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel_security.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/schema.php';
require_once __DIR__ . '/../includes/panel_auth.php';
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
panel_require_section_get('liturgy');

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

$ruleTypes = ['fixed_md' => 'Фіксаваная дата (месяц/дзень)', 'easter_offset' => 'Зрух ад Вялікодня (дзён)', 'epiphany_observed' => 'Аб\'яўленне (канфіг)', 'advent_offset' => 'Зрух ад I Нядзелі Адвэнту'];
$kinds = ['important' => 'Свята / урачыстасць (важны дзень)', 'optional' => 'Добраўвальны успамін', 'patch' => 'Толькі дапаўненне загалова'];
$sourceTags = ['fixed' => 'fixed', 'movable' => 'movable', 'regional' => 'regional'];
$colors = ['white' => 'белы', 'red' => 'чырвоны', 'purple' => 'фіялетавы', 'green' => 'зялёны', 'rose' => 'ружовы', 'black' => 'чорны'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !panel_post_skips_csrf_check()) {
    if (!panel_csrf_token_valid()) {
        $error = 'Сесія пратэрмінаваная. Абнавіце старонку.';
    } else {
        try {
            if (isset($_POST['delete_id'])) {
                $id = (int)$_POST['delete_id'];
                if ($id > 0) {
                    $del = db()->prepare('DELETE FROM liturgy_observances WHERE id = :id');
                    $del->execute([':id' => $id]);
                    liturgy_observances_invalidate_cache();
                    $message = 'Запіс выдалены.';
                }
            } elseif (isset($_POST['save_observance'])) {
                $id = (int)($_POST['id'] ?? 0);
                $ruleType = trim((string)($_POST['rule_type'] ?? 'fixed_md'));
                if (!isset($ruleTypes[$ruleType])) {
                    $ruleType = 'fixed_md';
                }
                $month = ($_POST['month'] === '' || $_POST['month'] === null) ? null : (int)$_POST['month'];
                $day = ($_POST['day'] === '' || $_POST['day'] === null) ? null : (int)$_POST['day'];
                $easterOffset = ($_POST['easter_offset'] === '' || $_POST['easter_offset'] === null) ? null : (int)$_POST['easter_offset'];
                $adventOff = ($_POST['advent_offset_days'] === '' || $_POST['advent_offset_days'] === null) ? null : (int)$_POST['advent_offset_days'];
                $kind = trim((string)($_POST['observance_kind'] ?? 'optional'));
                if (!isset($kinds[$kind])) {
                    $kind = 'optional';
                }
                $rank = trim((string)($_POST['regional_rank'] ?? ''));
                $title = trim((string)($_POST['title'] ?? ''));
                $color = trim((string)($_POST['liturgical_color'] ?? 'white'));
                if ($color === '' || !isset($colors[$color])) {
                    $color = 'white';
                }
                $src = trim((string)($_POST['source_tag'] ?? 'fixed'));
                if (!isset($sourceTags[$src])) {
                    $src = 'fixed';
                }
                $any = trim((string)($_POST['require_any_of'] ?? ''));
                $all = trim((string)($_POST['require_all_of'] ?? ''));
                $forbid = trim((string)($_POST['forbid_if_any_of'] ?? ''));
                $pri = (int)($_POST['match_priority'] ?? 0);
                $sortOrder = (int)($_POST['sort_order'] ?? 0);
                $active = isset($_POST['is_active']) ? 1 : 0;
                $patchMd = trim((string)($_POST['patch_append_to_mmdd'] ?? ''));
                $patchSuf = trim((string)($_POST['patch_suffix'] ?? ''));
                if ($patchMd !== '' && !preg_match('/^\d{2}-\d{2}$/', $patchMd)) {
                    $error = 'patch_append_to_mmdd: фармат MM-DD.';
                } elseif ($kind !== 'patch' && $title === '') {
                    $error = 'Пазначце загаловак (для тыпу «patch» загаловак можа заставацца пустым).';
                } elseif ($ruleType === 'fixed_md' && ($month === null || $day === null || $month < 1 || $month > 12)) {
                    $error = 'Для фіксаванай даты пазначыце карэктны месяц і дзень.';
                } elseif ($ruleType === 'easter_offset' && $easterOffset === null) {
                    $error = 'Для зруху ад Вялікодня пазначыце колькасць дзён (можа быць адмоўная).';
                } elseif ($ruleType === 'advent_offset' && $adventOff === null) {
                    $error = 'Для зруху ад Адвэнту пазначыце колькасць дзён (напрыклад 0 або -7).';
                } else {
                    if ($id > 0) {
                        $sql = 'UPDATE liturgy_observances SET
                            rule_type = :rule_type, month = :month, day = :day,
                            easter_offset = :easter_offset, advent_offset_days = :advent_offset_days,
                            observance_kind = :observance_kind, regional_rank = :regional_rank,
                            title = :title, liturgical_color = :liturgical_color, source_tag = :source_tag,
                            require_any_of = :require_any_of, require_all_of = :require_all_of,
                            forbid_if_any_of = :forbid_if_any_of, match_priority = :match_priority,
                            is_active = :is_active, sort_order = :sort_order,
                            patch_append_to_mmdd = :patch_md, patch_suffix = :patch_suf
                            WHERE id = :id';
                        $stmt = db()->prepare($sql);
                        $stmt->execute([
                            ':id' => $id,
                            ':rule_type' => $ruleType,
                            ':month' => $month,
                            ':day' => $day,
                            ':easter_offset' => $easterOffset,
                            ':advent_offset_days' => $adventOff,
                            ':observance_kind' => $kind,
                            ':regional_rank' => $rank,
                            ':title' => $title,
                            ':liturgical_color' => $color,
                            ':source_tag' => $src,
                            ':require_any_of' => $any,
                            ':require_all_of' => $all,
                            ':forbid_if_any_of' => $forbid,
                            ':match_priority' => $pri,
                            ':is_active' => $active,
                            ':sort_order' => $sortOrder,
                            ':patch_md' => $patchMd !== '' ? $patchMd : null,
                            ':patch_suf' => $patchSuf !== '' ? $patchSuf : null,
                        ]);
                    } else {
                        $sql = 'INSERT INTO liturgy_observances (
                            rule_type, month, day, easter_offset, advent_offset_days,
                            observance_kind, regional_rank, title, liturgical_color, source_tag,
                            require_any_of, require_all_of, forbid_if_any_of,
                            match_priority, uses_cycle_suffix, suppressed_by_ordinary_sunday,
                            patch_append_to_mmdd, patch_suffix, is_active, sort_order
                        ) VALUES (
                            :rule_type, :month, :day, :easter_offset, :advent_offset_days,
                            :observance_kind, :regional_rank, :title, :liturgical_color, :source_tag,
                            :require_any_of, :require_all_of, :forbid_if_any_of,
                            :match_priority, 0, 0,
                            :patch_md, :patch_suf, :is_active, :sort_order
                        )';
                        $stmt = db()->prepare($sql);
                        $stmt->execute([
                            ':rule_type' => $ruleType,
                            ':month' => $month,
                            ':day' => $day,
                            ':easter_offset' => $easterOffset,
                            ':advent_offset_days' => $adventOff,
                            ':observance_kind' => $kind,
                            ':regional_rank' => $rank,
                            ':title' => $title,
                            ':liturgical_color' => $color,
                            ':source_tag' => $src,
                            ':require_any_of' => $any,
                            ':require_all_of' => $all,
                            ':forbid_if_any_of' => $forbid,
                            ':match_priority' => $pri,
                            ':is_active' => $active,
                            ':sort_order' => $sortOrder,
                            ':patch_md' => $patchMd !== '' ? $patchMd : null,
                            ':patch_suf' => $patchSuf !== '' ? $patchSuf : null,
                        ]);
                    }
                    liturgy_observances_invalidate_cache();
                    $message = 'Захавана.';
                }
            }
        } catch (Throwable $e) {
            $error = 'Памылка: ' . $e->getMessage();
        }
    }
}

$filterKind = trim((string)($_GET['kind'] ?? ''));
$sql = 'SELECT * FROM liturgy_observances WHERE 1=1';
$params = [];
if ($filterKind !== '' && in_array($filterKind, ['important', 'optional', 'patch'], true)) {
    $sql .= ' AND observance_kind = :k';
    $params[':k'] = $filterKind;
}
$sql .= ' ORDER BY sort_order ASC, id ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = db()->prepare('SELECT * FROM liturgy_observances WHERE id = :id LIMIT 1');
    $st->execute([':id' => $editId]);
    $editRow = $st->fetch() ?: null;
}

$year = (int)date('Y');

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Святы літургічнага календара — Totus Tuus</title>
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
    h2 { margin: 0 0 8px; font-size: 1rem; color: #f1f5f9; }
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
    .msg { margin: 0 0 12px; padding: 10px 12px; border-radius: 10px; }
    .ok { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.45); color: #bbf7d0; }
    .err { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.45); color: #fecaca; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 14px; width: 100%; }
    .card { background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 16px; overflow: hidden; }
    .table-wrap { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; min-width: 820px; }
    .table th, .table td { border-bottom: 1px solid #273449; padding: 10px 8px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
    .table tr:last-child td { border-bottom: none; }
    .table .btn { white-space: nowrap; padding: 6px 10px; }
    .muted { color: #94a3b8; font-size: 13px; }
    .status-row { margin: 6px 0 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    label { display: block; margin-top: 10px; margin-bottom: 4px; font-size: 13px; color: #cbd5e1; font-weight: 600; }
    label.checkbox-row { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-weight: 600; }
    label.checkbox-row input { width: auto; }
    input[type="date"], input[type="text"], input[type="number"], select, textarea {
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
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center; }
    button { border: 1px solid #334155; background: #7c6cf0; color: #fff; font-weight: 700; border-radius: 10px; padding: 10px 12px; cursor: pointer; }
    .danger { background: #7f1d1d; border-color: #b91c1c; }
    code { font-size: 12px; background: rgba(15, 23, 42, 0.8); padding: 2px 6px; border-radius: 6px; border: 1px solid #334155; }
    .day-actions-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
      .top-nav { justify-content: flex-start; max-width: none; width: 100%; align-items: flex-start; }
      .top-nav-row { justify-content: flex-start; gap: 10px 14px; }
    }
    @media (max-width: 980px) { .table { min-width: 640px; } }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-brand">
      <h1>Totus Tuus</h1>
      <p class="header-tagline">Панэль кіравання<br>імя Біскупа Казіміра Велікасельца OP</p>
    </div>
    <?php
        $panelNavPage = 'liturgy_observances';
        $panelNavView = 'categories';
        $panelNavCalYear = $year;
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <?php if ($message !== null): ?><p class="msg ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="msg err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

  <div class="grid">
    <div class="card">
      <h2><?= $editRow ? 'Рэдагаванне запісу' : 'Новы запіс' ?></h2>
      <p class="muted" style="margin-top:0;">Табліца <code>liturgy_observances</code>. Ключы дыяцэзій праз коску:
        <code>pinskaya</code>, <code>minsk_mogilev</code>, <code>vitebskaya</code>, <code>grodzenskaya</code>.
        <strong>require_any_of</strong> — хаця б адна ўключаная; <strong>forbid_if_any_of</strong> — не ўжываць, калі ўключаная любая з пераліку.
        Перанос Унебаўшэсця і Ціла Хрыста — у <code>liturgy_calendar_config.php</code>.</p>

      <div class="status-row">
        <span class="nav-group-label" style="margin:0;">Фільтр спісу:</span>
        <a href="?" class="btn-pill<?= $filterKind === '' ? ' active' : '' ?>">Усе</a>
        <a href="?kind=important" class="btn-pill<?= $filterKind === 'important' ? ' active' : '' ?>">Важныя</a>
        <a href="?kind=optional" class="btn-pill<?= $filterKind === 'optional' ? ' active' : '' ?>">Даброўныя</a>
        <a href="?kind=patch" class="btn-pill<?= $filterKind === 'patch' ? ' active' : '' ?>">Дап. загаловак</a>
      </div>

      <form method="post">
        <?= panel_csrf_field() ?>
        <input type="hidden" name="save_observance" value="1">
        <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">

        <label for="rule_type">Тып правіла</label>
        <select id="rule_type" name="rule_type">
            <?php foreach ($ruleTypes as $k => $lab): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= (($editRow['rule_type'] ?? 'fixed_md') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>

        <label>Месяц / дзень (фіксаваная дата)</label>
        <input type="number" name="month" min="1" max="12" step="1" value="<?= htmlspecialchars((string)($editRow['month'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="MM" aria-label="Месяц">
        <input type="number" name="day" min="1" max="31" step="1" value="<?= htmlspecialchars((string)($editRow['day'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DD" aria-label="Дзень">

        <label for="easter_offset">Зрух ад Вялікодня (дзён; дапушчаныя адмоўныя)</label>
        <input id="easter_offset" type="number" name="easter_offset" step="1" value="<?= htmlspecialchars((string)($editRow['easter_offset'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label for="advent_offset_days">Зрух ад I нядзелі Адвэнту (напрыклад 0, −7)</label>
        <input id="advent_offset_days" type="number" name="advent_offset_days" step="1" value="<?= htmlspecialchars((string)($editRow['advent_offset_days'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label for="observance_kind">Від запісу</label>
        <select id="observance_kind" name="observance_kind">
            <?php foreach ($kinds as $k => $lab): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= (($editRow['observance_kind'] ?? 'optional') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>

        <label for="regional_rank">Ранг (для рэгіянальных: solemnity / feast / memorial)</label>
        <input id="regional_rank" type="text" name="regional_rank" value="<?= htmlspecialchars((string)($editRow['regional_rank'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label for="title">Загаловак</label>
        <textarea id="title" name="title"><?= htmlspecialchars((string)($editRow['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="liturgical_color">Колер</label>
        <select id="liturgical_color" name="liturgical_color">
            <?php foreach ($colors as $k => $lab): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= (($editRow['liturgical_color'] ?? 'white') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($k . ' — ' . $lab, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>

        <label for="source_tag">source_tag</label>
        <select id="source_tag" name="source_tag">
            <?php foreach ($sourceTags as $k => $_): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= (($editRow['source_tag'] ?? 'fixed') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>

        <label for="require_any_of">require_any_of (CSV)</label>
        <input id="require_any_of" type="text" name="require_any_of" value="<?= htmlspecialchars((string)($editRow['require_any_of'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label for="require_all_of">require_all_of (CSV)</label>
        <input id="require_all_of" type="text" name="require_all_of" value="<?= htmlspecialchars((string)($editRow['require_all_of'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label for="forbid_if_any_of">forbid_if_any_of (CSV)</label>
        <input id="forbid_if_any_of" type="text" name="forbid_if_any_of" value="<?= htmlspecialchars((string)($editRow['forbid_if_any_of'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label for="match_priority">match_priority</label>
        <input id="match_priority" type="number" name="match_priority" step="1" value="<?= htmlspecialchars((string)($editRow['match_priority'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">

        <label for="sort_order">sort_order</label>
        <input id="sort_order" type="number" name="sort_order" step="1" value="<?= htmlspecialchars((string)($editRow['sort_order'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">

        <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" <?= !isset($editRow['is_active']) || (int)$editRow['is_active'] === 1 ? 'checked' : '' ?>> Актыўны</label>

        <label for="patch_append_to_mmdd">patch_append_to_mmdd (MM-DD)</label>
        <input id="patch_append_to_mmdd" type="text" name="patch_append_to_mmdd" value="<?= htmlspecialchars((string)($editRow['patch_append_to_mmdd'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label for="patch_suffix">patch_suffix</label>
        <textarea id="patch_suffix" name="patch_suffix"><?= htmlspecialchars((string)($editRow['patch_suffix'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

        <div class="actions">
          <button type="submit">Захаваць</button>
          <?php if ($editRow): ?>
            <a href="/admin/liturgy_observances.php" class="btn">Адмяніць рэдагаванне</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>Спіс запісаў (<?= count($rows) ?>)</h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
          <tr>
            <th style="width:56px;">id</th>
            <th style="width:110px;">тып</th>
            <th style="width:88px;">дата</th>
            <th style="width:100px;">від</th>
            <th>загаловак</th>
            <th style="width:180px;">дыяцэзіі</th>
            <th style="width:200px;">дзенні</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars((string)$r['rule_type'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="muted"><?php
                if ($r['rule_type'] === 'fixed_md') {
                    echo htmlspecialchars(sprintf('%02d-%02d', (int)$r['month'], (int)$r['day']), ENT_QUOTES, 'UTF-8');
                } elseif ($r['rule_type'] === 'easter_offset') {
                    echo 'E' . (int)$r['easter_offset'];
                } elseif ($r['rule_type'] === 'advent_offset') {
                    echo 'A' . (int)$r['advent_offset_days'];
                } else {
                    echo '—';
                }
                ?></td>
              <td><?= htmlspecialchars((string)$r['observance_kind'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars(mb_substr((string)$r['title'], 0, 80), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen((string)$r['title']) > 80 ? '…' : '' ?></td>
              <td class="muted"><?php
                $bits = array_filter([
                    $r['require_any_of'] !== '' ? 'any:' . $r['require_any_of'] : '',
                    $r['forbid_if_any_of'] !== '' ? '!:' . $r['forbid_if_any_of'] : '',
                ]);
                echo htmlspecialchars(implode(' ', $bits), ENT_QUOTES, 'UTF-8');
                ?></td>
              <td>
                <div class="day-actions-row">
                  <a href="?edit=<?= (int)$r['id'] ?>" class="btn">Змена</a>
                  <form method="post" style="margin:0;" onsubmit="return confirm('Выдаліць?');">
                    <?= panel_csrf_field() ?>
                    <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="danger" style="padding:6px 10px;font-size:12px;">Выдаліць</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
