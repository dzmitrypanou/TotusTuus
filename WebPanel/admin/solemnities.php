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
    } elseif (isset($_POST['delete_id'])) {
        try {
            $deleteId = (int)$_POST['delete_id'];
            if ($deleteId > 0) {
                $stmt = db()->prepare('DELETE FROM solemnities_entries WHERE id = :id');
                $stmt->execute([':id' => $deleteId]);
                header('Location: /admin/solemnities.php?deleted=1', true, 302);
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Памылка: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['deleted']) && (string)$_GET['deleted'] === '1') {
    $message = 'Запіс выдалены.';
}

$q = trim((string)($_GET['q'] ?? ''));
$activeFilter = trim((string)($_GET['active'] ?? ''));
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(date_label LIKE :q OR title LIKE :q OR section_title LIKE :q OR movable_key LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($activeFilter === '1' || $activeFilter === '0') {
    $where[] = 'is_active = :active';
    $params[':active'] = (int)$activeFilter;
}
$sql = 'SELECT * FROM solemnities_entries';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY sort_order ASC, id ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
if (!is_array($rows)) {
    $rows = [];
}

?>
<!doctype html>
<html lang="be">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Урачыстасці і святы — Totus Tuus</title>
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
    .grid { display:grid; grid-template-columns:1fr; gap:14px; width:100%; }
    .card { background:#111827; border:1px solid #334155; border-radius:14px; padding:16px; overflow:hidden; }
    label { display:block; margin:10px 0 4px; font-size:13px; color:#cbd5e1; font-weight:600; }
    input[type="text"], select { width:100%; border:1px solid #334155; background:#0f172a; color:#e2e8f0; border-radius:10px; padding:10px 11px; font:inherit; }
    a.btn-pill, button.btn-pill { display:inline-flex; align-items:center; justify-content:center; color:var(--text); text-decoration:none; font-weight:600; font-size:.875rem; padding:8px 12px; border-radius:var(--radius-sm); background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); line-height:1.2; cursor:pointer; }
    a.btn-pill.active, button.btn-pill.active { background:linear-gradient(135deg, rgba(124,108,240,.35), rgba(196,163,90,.18)); border-color:rgba(196,163,90,.35); color:#fff; }
    button.btn-pill { margin-top:0; font-family:inherit; font-weight:600; padding:8px 12px; border-radius:var(--radius-sm); background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); color:var(--text); box-shadow:none; filter:none; }
    button.btn-pill:hover:not(:disabled) { filter:brightness(1.08); box-shadow:none; }
    button.btn-pill:active:not(:disabled) { transform:none; }
    .btn-pill--muted { background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.08); color:var(--text); }
    button { border:1px solid #334155; background:#7c6cf0; color:#fff; font-weight:700; border-radius:10px; padding:10px 12px; cursor:pointer; }
    button.danger { background:#7f1d1d; border-color:#b91c1c; color:#fecaca; padding:6px 10px; font-size:12px; }
    .toolbar-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px; }
    .actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
    .filter-row { display:grid; grid-template-columns:minmax(0,1fr) 150px auto; gap:8px; align-items:end; margin-bottom:14px; }
    .muted { color:#94a3b8; font-size:13px; }
    .msg { margin:0 0 12px; padding:10px 12px; border-radius:10px; font-size:.92rem; }
    .msg--ok { background:rgba(34,197,94,.12); border:1px solid rgba(34,197,94,.35); color:#bbf7d0; }
    .msg--err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.35); color:#fecaca; }
    .table-wrap { overflow-x:auto; }
    .table { width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed; min-width:900px; }
    .table th, .table td { border-bottom:1px solid #273449; padding:10px 8px; text-align:left; vertical-align:top; overflow-wrap:anywhere; }
    .table tr:last-child td { border-bottom:none; }
    .badge { display:inline-flex; align-items:center; padding:3px 7px; border-radius:999px; background:rgba(148,163,184,.12); color:#cbd5e1; font-size:.78rem; }
    .row-actions { display:flex; flex-wrap:wrap; gap:6px; justify-content:flex-end; }
    .row-actions form { margin:0; }
    @media (max-width:900px) { .filter-row { grid-template-columns:1fr; } }
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
        $panelNavPage = 'solemnities';
        $panelNavView = 'categories';
        $panelNavCalYear = (int)date('Y');
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <?php if ($message !== null): ?><p class="msg msg--ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="msg msg--err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

  <div class="toolbar-row" style="margin-bottom:12px;">
    <a class="btn-pill" href="/admin/solemnities_edit.php">+ Новы запіс</a>
  </div>

  <div class="grid">
    <section class="card">
      <h2>Запісы</h2>
      <form method="get" action="/admin/solemnities.php" class="filter-row">
        <div>
          <label for="q">Пошук</label>
          <input id="q" type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="дата, назва, раздзел або ключ">
        </div>
        <div>
          <label for="active">Статус</label>
          <select id="active" name="active">
            <option value=""<?= $activeFilter === '' ? ' selected' : '' ?>>усе</option>
            <option value="1"<?= $activeFilter === '1' ? ' selected' : '' ?>>актыўныя</option>
            <option value="0"<?= $activeFilter === '0' ? ' selected' : '' ?>>схаваныя</option>
          </select>
        </div>
        <div class="actions">
          <button type="submit">Фільтр</button>
          <a class="btn-pill btn-pill--muted" href="/admin/solemnities.php">Скід</a>
        </div>
      </form>
      <p class="muted">Паказана: <?= count($rows) ?>.</p>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:64px;">ID</th>
              <th style="width:82px;">Парадак</th>
              <th style="width:150px;">Дата</th>
              <th style="width:145px;">Тып</th>
              <th>Назва</th>
              <th style="width:220px;">Раздзел</th>
              <th style="width:95px;">Статус</th>
              <th style="width:170px;">Дзеянні</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows === []): ?>
            <tr><td colspan="8" class="muted">Запісаў няма.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td><?= (int)$row['sort_order'] ?></td>
              <td><?= htmlspecialchars((string)$row['date_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><span class="badge"><?= ((string)($row['date_kind'] ?? 'fixed') === 'movable') ? htmlspecialchars((string)($row['movable_key'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'fixed' ?></span></td>
              <td><?= htmlspecialchars((string)$row['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$row['section_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><span class="badge"><?= ((int)$row['is_active'] !== 0) ? 'актыўны' : 'схаваны' ?></span></td>
              <td>
                <div class="row-actions">
                  <a class="btn-pill btn-pill--muted" href="/admin/solemnities_edit.php?id=<?= (int)$row['id'] ?>">Змена</a>
                  <form method="post" action="/admin/solemnities.php" onsubmit="return confirm('Выдаліць запіс?');">
                    <?= panel_csrf_field() ?>
                    <input type="hidden" name="delete_id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="danger">Выдаліць</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</body>
</html>

