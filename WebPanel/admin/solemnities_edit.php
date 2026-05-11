<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel_security.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/schema.php';
require_once __DIR__ . '/../includes/panel_auth.php';

$solemnityMovableOptions = [
    'ash_wednesday' => 'Папялец / Папяльцовая серада',
    'palm_sunday' => 'Пальмовая нядзеля',
    'easter' => 'Вялікдзень / Уваскрасенне Пана',
    'ascension' => 'Унебаўшэсце Пана',
    'pentecost' => 'Спасланне Духа Святога',
    'corpus_christi' => 'Цела і Кроў Хрыста',
    'sacred_heart' => 'Найсвяцейшае Сэрца Пана Езуса',
    'christ_king' => 'Хрыстус Валадар Сусвету',
    'first_advent_sunday' => 'Першая нядзеля Адвэнту',
];

panel_configure_session_before_start();
session_start();
panel_ensure_csrf_token();
panel_send_admin_security_headers();

if (!panel_is_logged_in()) {
    header('Location: /', true, 302);
    exit;
}

ensureSchemaAndSeed();
panel_require_section_get('solemnities');

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !panel_post_skips_csrf_check()) {
    if (!panel_csrf_token_valid()) {
        $error = 'Сесія пратэрмінаваная. Абнавіце старонку.';
    } elseif (isset($_POST['save_solemnity'])) {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $dateKind = trim((string)($_POST['date_kind'] ?? 'fixed'));
            if (!in_array($dateKind, ['fixed', 'movable'], true)) {
                $dateKind = 'fixed';
            }
            $movableKey = trim((string)($_POST['movable_key'] ?? ''));
            if ($dateKind !== 'movable') {
                $movableKey = '';
            }
            $dateLabel = trim((string)($_POST['date_label'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            $sectionTitle = trim((string)($_POST['section_title'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($dateKind === 'movable' && !isset($solemnityMovableOptions[$movableKey])) {
                $error = 'Абярыце рухомую дату.';
            } elseif ($dateKind === 'fixed' && $dateLabel === '') {
                $error = 'Пазначце дату або подпіс даты.';
            } elseif ($title === '') {
                $error = 'Пазначце назву.';
            } elseif (mb_strlen($dateLabel) > 128) {
                $error = 'Дата занадта доўгая (максімум 128 сімвалаў).';
            } elseif (mb_strlen($title) > 512) {
                $error = 'Назва занадта доўгая (максімум 512 сімвалаў).';
            } elseif (mb_strlen($sectionTitle) > 255) {
                $error = 'Назва раздзела занадта доўгая (максімум 255 сімвалаў).';
            } elseif ($id > 0) {
                $stmt = db()->prepare(
                    'UPDATE solemnities_entries
                     SET date_label = :date_label, date_kind = :date_kind, movable_key = :movable_key,
                         title = :title, section_title = :section_title, sort_order = :sort_order, is_active = :is_active
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':id' => $id,
                    ':date_label' => $dateLabel,
                    ':date_kind' => $dateKind,
                    ':movable_key' => $movableKey,
                    ':title' => $title,
                    ':section_title' => $sectionTitle,
                    ':sort_order' => $sortOrder,
                    ':is_active' => $isActive,
                ]);
                header('Location: /admin/solemnities_edit.php?id=' . $id . '&saved=1', true, 302);
                exit;
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO solemnities_entries (date_label, date_kind, movable_key, title, section_title, sort_order, is_active)
                     VALUES (:date_label, :date_kind, :movable_key, :title, :section_title, :sort_order, :is_active)'
                );
                $stmt->execute([
                    ':date_label' => $dateLabel,
                    ':date_kind' => $dateKind,
                    ':movable_key' => $movableKey,
                    ':title' => $title,
                    ':section_title' => $sectionTitle,
                    ':sort_order' => $sortOrder,
                    ':is_active' => $isActive,
                ]);
                $newId = (int)db()->lastInsertId();
                header('Location: /admin/solemnities_edit.php?id=' . $newId . '&created=1', true, 302);
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Памылка: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['saved']) && (string)$_GET['saved'] === '1') {
    $message = 'Запіс захаваны.';
}
if (isset($_GET['created']) && (string)$_GET['created'] === '1') {
    $message = 'Запіс створаны.';
}

$editId = (int)($_GET['id'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM solemnities_entries WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $editId]);
    $editRow = $stmt->fetch() ?: null;
    if ($editRow === null) {
        $error = 'Запіс не знойдзены.';
    }
}

$formRow = $editRow ?: [
    'id' => 0,
    'date_label' => '',
    'date_kind' => 'fixed',
    'movable_key' => '',
    'title' => '',
    'section_title' => '',
    'sort_order' => 0,
    'is_active' => 1,
];

?>
<!doctype html>
<html lang="be">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title><?= $editRow ? 'Рэдагаванне запісу' : 'Новы запіс' ?> — Урачыстасці і святы — Totus Tuus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,500&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
  <style>
    :root { --text:#e2e8f0; --muted:#94a3b8; --line:rgba(148,163,184,.22); --card:rgba(22,28,46,.72); --bg-deep:#0a0c14; --bg-mid:#12182a; --bg-glow:#1a2240; --accent:#7c6cf0; --radius:14px; --radius-sm:10px; }
    * { box-sizing:border-box; }
    html { color-scheme:dark; scrollbar-gutter:stable; }
    body { font-family:"DM Sans",system-ui,sans-serif; max-width:1120px; margin:0 auto; padding:28px 16px 48px; color:var(--text); min-height:100vh; background:radial-gradient(ellipse 120% 80% at 100% -20%, rgba(124,108,240,.22), transparent 50%), radial-gradient(ellipse 90% 60% at -10% 50%, rgba(196,163,90,.08), transparent 45%), linear-gradient(165deg, var(--bg-deep) 0%, var(--bg-mid) 42%, var(--bg-glow) 100%); background-attachment:fixed; }
    h1 { margin:0; font-family:"Cormorant Garamond","Times New Roman",serif; font-size:clamp(2rem,4vw,2.75rem); font-weight:600; letter-spacing:.02em; line-height:1.1; background:linear-gradient(120deg,#f1f5f9 0%,#e2d5b8 45%,#c7d2fe 100%); -webkit-background-clip:text; background-clip:text; color:transparent; }
    h2 { margin:0 0 12px; font-size:1.05rem; }
    .header { position:relative; overflow:hidden; border-radius:calc(var(--radius) + 4px); padding:22px 24px; display:flex; align-items:center; justify-content:space-between; gap:20px; border:1px solid var(--line); background:linear-gradient(135deg, rgba(30,27,75,.95) 0%, rgba(15,23,42,.92) 50%, rgba(30,41,59,.88) 100%); box-shadow:0 4px 24px rgba(0,0,0,.35), 0 0 0 1px rgba(255,255,255,.04) inset, 0 1px 0 rgba(255,255,255,.06) inset; margin-bottom:16px; }
    .header::before { content:""; position:absolute; inset:0; background:linear-gradient(105deg, transparent 40%, rgba(196,163,90,.06) 70%, rgba(124,108,240,.12) 100%); pointer-events:none; }
    .header > * { position:relative; z-index:1; }
    .header-brand { display:flex; flex-direction:column; align-items:center; gap:4px; text-align:center; }
    .header-brand h1 { text-align:center; }
    .header-tagline { margin:0; max-width:22rem; font-size:calc(.8125rem * .7); font-weight:500; color:var(--muted); letter-spacing:.04em; text-transform:uppercase; line-height:1.4; text-align:center; }
    .card { border:1px solid var(--line); border-radius:var(--radius); background:var(--card); box-shadow:0 18px 70px rgba(0,0,0,.35); padding:18px; backdrop-filter:blur(18px); max-width:720px; }
    label { display:block; margin:12px 0 6px; font-weight:700; font-size:.86rem; color:#cbd5e1; }
    input[type="text"], input[type="number"], select { width:100%; border:1px solid rgba(148,163,184,.28); border-radius:10px; background:rgba(15,23,42,.7); color:var(--text); padding:10px 12px; font:inherit; }
    select:not([multiple]) { appearance:none; -webkit-appearance:none; -moz-appearance:none; padding:10px 40px 10px 12px; background-color:rgba(15,23,42,.82); background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%2394a3b8' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; background-size:14px 14px; cursor:pointer; }
    select:not([multiple]):hover { border-color:rgba(148,163,184,.42); background-color:rgba(15,23,42,.95); }
    select:not([multiple]):focus { outline:none; border-color:rgba(124,108,240,.7); box-shadow:0 0 0 3px rgba(124,108,240,.18); background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%23cbd5e1' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E"); }
    input[type="checkbox"] { width:auto; margin:0; }
    .checkbox-row { display:flex; align-items:center; gap:8px; margin-top:12px; }
    .checkbox-row label { margin:0; }
    a.btn-pill, button.btn-pill { display:inline-flex; align-items:center; justify-content:center; color:var(--text); text-decoration:none; font-weight:600; font-size:.875rem; padding:8px 12px; border-radius:var(--radius-sm); background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); line-height:1.2; cursor:pointer; }
    a.btn-pill.active, button.btn-pill.active { background:linear-gradient(135deg, rgba(124,108,240,.35), rgba(196,163,90,.18)); border-color:rgba(196,163,90,.35); color:#fff; }
    button.btn-pill { margin-top:0; font-family:inherit; font-weight:600; padding:8px 12px; border-radius:var(--radius-sm); background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); color:var(--text); box-shadow:none; filter:none; }
    button.btn-pill:hover:not(:disabled) { filter:brightness(1.08); box-shadow:none; }
    button.btn-pill:active:not(:disabled) { transform:none; }
    .btn-pill--muted { background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.08); color:var(--text); }
    button { border:1px solid #334155; background:#7c6cf0; color:#fff; font-weight:700; border-radius:10px; padding:10px 12px; cursor:pointer; }
    .toolbar-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px; }
    .actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
    .muted { color:var(--muted); font-size:.88rem; line-height:1.45; }
    .msg { margin:0 0 12px; padding:10px 12px; border-radius:10px; font-size:.92rem; }
    .msg--ok { background:rgba(34,197,94,.12); border:1px solid rgba(34,197,94,.35); color:#bbf7d0; }
    .msg--err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.35); color:#fecaca; }
    @media (max-width:1180px) { .header { flex-direction:column; align-items:flex-start; } .header-brand { align-self:center; } }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-brand">
      <h1>Totus Tuus</h1>
      <p class="header-tagline">Панэль кіравання Святой Памяці<br>Біскупа Казіміра Велікасельца OP</p>
    </div>
    <?php
        $panelNavPage = 'solemnities_edit';
        $panelNavView = 'categories';
        $panelNavCalYear = (int)date('Y');
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <?php if ($message !== null): ?><p class="msg msg--ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="msg msg--err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

  <div class="toolbar-row" style="margin-bottom:12px;">
    <a class="btn-pill btn-pill--muted" href="/admin/solemnities.php">← Да спісу</a>
    <?php if ($editRow): ?><a class="btn-pill btn-pill--muted" href="/admin/solemnities_edit.php">+ Новы запіс</a><?php endif; ?>
  </div>

  <section class="card">
    <h2><?= $editRow ? 'Рэдагаваць запіс' : 'Новы запіс' ?></h2>
    <p class="muted">Для рухомых дат API разлічвае подпіс па абраным годзе.</p>
    <form method="post" action="/admin/solemnities_edit.php<?= $editRow ? '?id=' . (int)$editRow['id'] : '' ?>">
      <?= panel_csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$formRow['id'] ?>">

      <label for="date_kind">Тып даты</label>
      <?php $formDateKind = (string)($formRow['date_kind'] ?? 'fixed'); ?>
      <select id="date_kind" name="date_kind">
        <option value="fixed"<?= $formDateKind !== 'movable' ? ' selected' : '' ?>>Фіксаваны подпіс</option>
        <option value="movable"<?= $formDateKind === 'movable' ? ' selected' : '' ?>>Рухомая дата з літургічнага календара</option>
      </select>

      <label for="date_label">Дата / подпіс даты</label>
      <input id="date_label" type="text" name="date_label" value="<?= htmlspecialchars((string)$formRow['date_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="1 студзеня або запасны подпіс для рухомай даты">

      <label for="movable_key">Рухомая дата</label>
      <?php $formMovableKey = (string)($formRow['movable_key'] ?? ''); ?>
      <select id="movable_key" name="movable_key">
        <option value="">— не выбрана —</option>
        <?php foreach ($solemnityMovableOptions as $mk => $ml): ?>
          <option value="<?= htmlspecialchars($mk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $formMovableKey === $mk ? ' selected' : '' ?>><?= htmlspecialchars($ml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>

      <label for="title">Назва</label>
      <input id="title" type="text" name="title" value="<?= htmlspecialchars((string)$formRow['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>

      <label for="section_title">Раздзел</label>
      <input id="section_title" type="text" name="section_title" value="<?= htmlspecialchars((string)$formRow['section_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

      <label for="sort_order">Парадак</label>
      <input id="sort_order" type="number" name="sort_order" value="<?= (int)$formRow['sort_order'] ?>" step="1">

      <div class="checkbox-row">
        <input id="is_active" type="checkbox" name="is_active" value="1" <?= ((int)$formRow['is_active'] !== 0) ? 'checked' : '' ?>>
        <label for="is_active">Паказваць у API</label>
      </div>

      <div class="actions">
        <button type="submit" name="save_solemnity" value="1">Захаваць</button>
        <a class="btn-pill btn-pill--muted" href="/admin/solemnities.php">Адмяніць</a>
      </div>
    </form>
  </section>
</body>
</html>

