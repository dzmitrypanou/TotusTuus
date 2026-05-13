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
panel_require_section_get('kantaral');

const KANTARAL_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

function kantaral_uploads_dir(): string
{
    $dir = __DIR__ . '/../uploads/kantaral';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function kantaral_delete_media(?string $mediaPath): void
{
    if ($mediaPath === null || $mediaPath === '') return;
    $fs = __DIR__ . '/../' . ltrim($mediaPath, '/');
    if (is_file($fs)) @unlink($fs);
}

function kantaral_upload_image(int $id, array $file): bool
{
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp) || (int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, KANTARAL_IMAGE_EXTENSIONS, true)) return false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif','image/avif'], true)) return false;
        }
    }
    $name = $id . '.' . $ext;
    $dest = kantaral_uploads_dir() . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) return false;
    $rel = 'uploads/kantaral/' . $name;
    $rev = hash_file('sha256', $dest);
    $st = db()->prepare('UPDATE kantaral_entries SET media_path = :p, media_revision = :r WHERE id = :id');
    $st->execute([':p' => $rel, ':r' => $rev, ':id' => $id]);
    return true;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['logout'])) {
    panel_clear_login_session();
    session_destroy();
    header('Location: /', true, 302);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($_POST['logout'])) {
    panel_require_section_for_post('kantaral', false);
    if (!panel_csrf_token_valid()) {
        $error = 'Несапраўдны токен бяспекі. Абнавіце старонку.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $chapter = max(1, (int)($_POST['chapter_major'] ?? 1));
        $subRaw = trim((string)($_POST['subchapter'] ?? ''));
        $sub = $subRaw !== '' ? max(1, (int)$subRaw) : null;
        $sort = (int)($_POST['sort_order'] ?? 0);
        $type = (string)($_POST['content_type'] ?? 'text');
        $text = (string)($_POST['text_body'] ?? '');
        if (!in_array($type, ['text', 'image'], true)) $type = 'text';

        try {
            if (isset($_POST['bulk_category']) || isset($_POST['bulk_autonumber']) || isset($_POST['bulk_clear_numbering'])) {
                $idsRaw = $_POST['bulk_ids'] ?? [];
                $idsRaw = is_array($idsRaw) ? $idsRaw : [];
                $ids = [];
                foreach ($idsRaw as $rawId) {
                    $n = (int)$rawId;
                    if ($n > 0 && !in_array($n, $ids, true)) $ids[] = $n;
                }
                if (count($ids) === 0) throw new RuntimeException('Абярыце хаця б адзін запіс.');
                if (isset($_POST['bulk_category'])) {
                    $bulkCat = trim((string)($_POST['bulk_category_value'] ?? ''));
                    if (strlen($bulkCat) > 255) throw new RuntimeException('Катэгорыя не даўжэй за 255 сімвалаў.');
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $st = db()->prepare('UPDATE kantaral_entries SET category = ? WHERE id IN (' . $ph . ')');
                    $st->execute(array_merge([$bulkCat], $ids));
                    $message = 'Абноўлена запісаў: ' . (string)$st->rowCount() . '.';
                } elseif (isset($_POST['bulk_autonumber'])) {
                    db()->beginTransaction();
                    try {
                        $st = db()->prepare('UPDATE kantaral_entries SET chapter_major = :chapter_major, subchapter = NULL WHERE id = :id');
                        $updated = 0;
                        foreach ($ids as $idx => $bulkId) {
                            $st->execute([':chapter_major' => $idx + 1, ':id' => $bulkId]);
                            $updated += $st->rowCount();
                        }
                        db()->commit();
                    } catch (Throwable $tx) {
                        if (db()->inTransaction()) db()->rollBack();
                        throw $tx;
                    }
                    $message = 'Аўтанумарацыя ўжыта (1…' . (string)count($ids) . '). Абноўлена запісаў: ' . (string)$updated . '.';
                } else {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $st = db()->prepare('UPDATE kantaral_entries SET chapter_major = 0, subchapter = NULL WHERE id IN (' . $ph . ')');
                    $st->execute($ids);
                    $message = 'Нумарацыя ачышчана. Абноўлена запісаў: ' . (string)$st->rowCount() . '.';
                }
            } elseif (isset($_POST['delete_id'])) {
                $delId = (int)$_POST['delete_id'];
                $st = db()->prepare('SELECT media_path FROM kantaral_entries WHERE id = :id');
                $st->execute([':id' => $delId]);
                $row = $st->fetch();
                if (is_array($row)) kantaral_delete_media((string)($row['media_path'] ?? ''));
                db()->prepare('DELETE FROM kantaral_entries WHERE id = :id')->execute([':id' => $delId]);
                $message = 'Запіс кантарала выдалены.';
            } elseif ($id > 0) {
                $cur = db()->prepare('SELECT media_path FROM kantaral_entries WHERE id = :id');
                $cur->execute([':id' => $id]);
                $old = $cur->fetch();
                $oldPath = is_array($old) ? (string)($old['media_path'] ?? '') : '';
                if ($type === 'text') {
                    kantaral_delete_media($oldPath);
                    $mediaSql = ', media_path = NULL, media_revision = \'\'';
                } else {
                    $mediaSql = '';
                    $text = '';
                }
                $st = db()->prepare('UPDATE kantaral_entries SET title=:t, category=:c, chapter_major=:ch, subchapter=:s, content_type=:ct, text_body=:b, sort_order=:so' . $mediaSql . ' WHERE id=:id');
                $st->execute([':t'=>$title, ':c'=>$category, ':ch'=>$chapter, ':s'=>$sub, ':ct'=>$type, ':b'=>$text, ':so'=>$sort, ':id'=>$id]);
                if ($type === 'image' && isset($_FILES['media']) && is_array($_FILES['media']) && (int)($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    kantaral_delete_media($oldPath);
                    if (!kantaral_upload_image($id, $_FILES['media'])) $error = 'Не ўдалося захаваць выяву.';
                }
                if ($error === '') $message = 'Запіс абноўлены.';
            } else {
                if ($type === 'text' && trim($text) === '') throw new RuntimeException('Увядзіце тэкст.');
                if ($type === 'image' && (!isset($_FILES['media']) || !is_array($_FILES['media']) || (int)($_FILES['media']['error'] ?? 0) !== UPLOAD_ERR_OK)) throw new RuntimeException('Загрузіце выяву.');
                $st = db()->prepare('INSERT INTO kantaral_entries (title, category, chapter_major, subchapter, content_type, text_body, media_path, media_revision, sort_order, is_active) VALUES (:t,:c,:ch,:s,:ct,:b,NULL,\'\',:so,1)');
                $st->execute([':t'=>$title, ':c'=>$category, ':ch'=>$chapter, ':s'=>$sub, ':ct'=>$type, ':b'=>$type === 'text' ? $text : '', ':so'=>$sort]);
                $newId = (int)db()->lastInsertId();
                if ($type === 'image' && !kantaral_upload_image($newId, $_FILES['media'])) {
                    db()->prepare('DELETE FROM kantaral_entries WHERE id=:id')->execute([':id'=>$newId]);
                    throw new RuntimeException('Не ўдалося захаваць выяву.');
                }
                $message = 'Запіс кантарала дададзены.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$addMode = isset($_GET['add']) || $editId > 0;
$catRaw = $_GET['cat'] ?? [];
$catSelected = is_array($catRaw) ? array_values(array_unique(array_map('strval', $catRaw))) : ($catRaw !== '' ? [(string)$catRaw] : []);
$catSelected = array_values(array_filter(array_map('trim', $catSelected), static fn($v) => $v !== ''));
$catEmptyToken = '__kantaral_cat_none__';
$edit = null;
if ($editId > 0) {
    $st = db()->prepare('SELECT * FROM kantaral_entries WHERE id=:id');
    $st->execute([':id' => $editId]);
    $r = $st->fetch();
    $edit = is_array($r) ? $r : null;
}
$catDistinctRaw = db()->query('SELECT DISTINCT category FROM kantaral_entries ORDER BY category ASC')->fetchAll(PDO::FETCH_COLUMN);
$catDistinct = is_array($catDistinctRaw) ? array_map('strval', $catDistinctRaw) : [];
$where = '';
$exec = [];
if (count($catSelected) > 0) {
    $named = array_values(array_filter($catSelected, static fn($v) => $v !== $catEmptyToken));
    $conds = [];
    if (count($named) > 0) {
        $conds[] = 'category IN (' . implode(',', array_fill(0, count($named), '?')) . ')';
        $exec = array_merge($exec, $named);
    }
    if (in_array($catEmptyToken, $catSelected, true)) {
        $conds[] = 'TRIM(COALESCE(category, \'\')) = \'\'';
    }
    if (count($conds) > 0) $where = ' WHERE (' . implode(' OR ', $conds) . ')';
}
$stmtRows = db()->prepare('SELECT * FROM kantaral_entries' . $where . ' ORDER BY category ASC, chapter_major ASC, COALESCE(subchapter,0) ASC, sort_order ASC, id ASC');
$stmtRows->execute($exec);
$rows = $stmtRows->fetchAll();
$rows = is_array($rows) ? $rows : [];
?>
<!doctype html><html lang="be"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="/favicon.png"><title>Кантарал — Totus Tuus</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,500&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet"><style>
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
      --accent-2: #c4a35a;
      --accent-glow: rgba(124, 108, 240, 0.35);
      --danger: #f87171;
      --success: #4ade80;
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
    h3 { margin: 0 0 10px; font-size: 1rem; font-weight: 600; color: var(--text); }
    p { color: var(--muted); line-height: 1.55; }
    p code, .inline-code {
      font-size: 0.9em;
      padding: 2px 7px;
      border-radius: 6px;
      background: rgba(124, 108, 240, 0.15);
      border: 1px solid var(--line);
      color: #ddd6fe;
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
    .btn-pill--sm {
      padding: 6px 10px;
      font-size: 0.8125rem;
    }
    .btn-pill--purple {
      background: rgba(124, 108, 240, 0.22);
      color: #e0e7ff;
      border-color: rgba(124, 108, 240, 0.3);
    }
    .btn-pill--purple:hover {
      background: rgba(124, 108, 240, 0.32);
      border-color: rgba(124, 108, 240, 0.45);
      color: #fff;
    }
    .btn-pill--gold {
      background: rgba(196, 163, 90, 0.12);
      color: #e8d5a3;
      border-color: rgba(196, 163, 90, 0.28);
    }
    .btn-pill--gold:hover {
      background: rgba(196, 163, 90, 0.2);
      border-color: rgba(196, 163, 90, 0.4);
      color: #f5ecd4;
    }
    .btn-pill--muted {
      background: rgba(15, 23, 42, 0.55);
      color: #94a3b8;
      border-color: rgba(148, 163, 184, 0.2);
    }
    .btn-pill--muted:hover {
      background: rgba(30, 41, 59, 0.75);
      border-color: rgba(148, 163, 184, 0.35);
      color: #cbd5e1;
    }
    .btn-pill--danger {
      background: rgba(248, 113, 113, 0.12);
      color: #fca5a5;
      border-color: rgba(248, 113, 113, 0.28);
    }
    .btn-pill--danger:hover {
      background: rgba(248, 113, 113, 0.2);
      border-color: rgba(248, 113, 113, 0.45);
      color: #fecaca;
    }
    .form-actions-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 12px;
      margin-top: 14px;
    }
    .form-actions-row button { margin-top: 0; }
    .toolbar-actions { margin: 12px 0 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .songbook-intro { margin-bottom: 4px; max-width: 68ch; }
    .songbook-toolbar-top {
      margin: 12px 0 0;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
    }
    .songbook-admin-panel {
      margin-top: 14px;
      padding: 20px 22px;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background:
        linear-gradient(145deg, rgba(22, 28, 46, 0.72) 0%, rgba(15, 23, 42, 0.55) 100%);
      box-shadow: 0 10px 36px rgba(0, 0, 0, 0.22);
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .songbook-panel-section { margin: 0; padding: 0; border: none; }
    .songbook-panel-section__head {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      justify-content: space-between;
      gap: 8px 14px;
      margin-bottom: 6px;
    }
    .songbook-panel-section__title {
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #94a3b8;
    }
    .songbook-panel-section__meta {
      font-size: 0.8rem;
      color: #a5b4fc;
      font-weight: 600;
    }
    .songbook-panel-section__hint {
      margin: 0 0 12px;
      font-size: 0.875rem;
      line-height: 1.5;
      color: var(--muted);
      max-width: 58rem;
    }
    .songbook-panel-section__hint strong { color: #c4b5fd; font-weight: 600; }
    .songbook-panel-section__empty {
      margin: 0 0 12px;
      font-size: 0.875rem;
      color: var(--muted);
    }
    .songbook-panel-divider {
      height: 1px;
      margin: 0;
      background: linear-gradient(90deg, transparent 0%, rgba(148, 163, 184, 0.22) 20%, rgba(124, 108, 240, 0.2) 50%, rgba(148, 163, 184, 0.22) 80%, transparent 100%);
    }
    .songbook-filter-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 10px;
      margin-bottom: 14px;
    }
    .songbook-filter-chip {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      padding: 8px 14px 8px 11px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, 0.22);
      background: rgba(10, 12, 20, 0.5);
      cursor: pointer;
      user-select: none;
      font-size: 0.875rem;
      color: #e2e8f0;
      transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
    }
    .songbook-filter-chip:hover {
      border-color: rgba(124, 108, 240, 0.35);
      background: rgba(124, 108, 240, 0.08);
    }
    .songbook-filter-chip:has(input:checked) {
      border-color: rgba(124, 108, 240, 0.55);
      background: rgba(124, 108, 240, 0.2);
      box-shadow: 0 0 0 1px rgba(124, 108, 240, 0.12);
    }
    .songbook-filter-chip input[type="checkbox"] {
      width: 1rem;
      height: 1rem;
      margin: 0;
      flex-shrink: 0;
      accent-color: #7c6cf0;
      cursor: pointer;
    }
    .songbook-filter-chip span { line-height: 1.25; }
    .songbook-panel-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 12px;
    }
    .songbook-panel-actions .btn-pill { margin-top: 0; }
    .panel-filter-details { margin: 0; padding: 0; border: none; }
    .panel-filter-summary {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 8px 14px;
      padding: 11px 14px;
      border-radius: var(--radius-sm);
      border: 1px solid rgba(148, 163, 184, 0.22);
      background: rgba(10, 12, 20, 0.4);
      cursor: pointer;
      list-style: none;
      user-select: none;
      transition: border-color 0.15s ease, background 0.15s ease;
    }
    .panel-filter-summary:hover {
      border-color: rgba(124, 108, 240, 0.35);
      background: rgba(124, 108, 240, 0.08);
    }
    .panel-filter-summary::-webkit-details-marker { display: none; }
    .panel-filter-summary::marker { content: ''; }
    .panel-filter-summary__title {
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #94a3b8;
    }
    .panel-filter-summary__hint {
      width: 100%;
      flex-basis: 100%;
      margin: 4px 0 0;
      font-size: 0.78rem;
      font-weight: 400;
      text-transform: none;
      letter-spacing: 0.02em;
      color: var(--muted);
      line-height: 1.35;
    }
    .panel-filter-summary__meta {
      font-size: 0.8rem;
      color: #a5b4fc;
      font-weight: 600;
    }
    .panel-filter-details__body {
      margin-top: 14px;
      padding-top: 2px;
    }
    .prayers-filter-form .panel-filter-details {
      margin-bottom: 10px;
    }
    .songbook-bulk-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 14px;
    }
    .songbook-bulk-form .bulk-songbook-label {
      display: inline;
      margin: 0;
      font-weight: 600;
      font-size: 0.875rem;
      color: #cbd5e1;
      white-space: nowrap;
    }
    .songbook-bulk-form .bulk-songbook-input {
      width: auto;
      flex: 1 1 220px;
      min-width: 200px;
      max-width: 400px;
      margin: 0;
    }
    .songbook-bulk-form .btn-pill { margin-top: 0; }
    .songbook-admin-panel + table { margin-top: 22px; }
    table th.cell-checkbox, table td.cell-checkbox {
      width: 2.5rem;
      text-align: center;
      vertical-align: middle;
    }
    table th.cell-checkbox input, table td.cell-checkbox input[type="checkbox"] {
      width: auto;
      margin: 0;
    }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; }
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
    .card p { margin: 8px 0 0; }
    label { display: block; margin: 14px 0 7px; font-weight: 600; font-size: 0.875rem; color: #cbd5e1; }
    input, textarea, select {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      font: inherit;
      color: var(--text);
      background: rgba(10, 12, 20, 0.55);
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    input::placeholder, textarea::placeholder { color: var(--muted); opacity: 0.8; }
    input:focus, textarea:focus, select:focus {
      outline: none;
      border-color: rgba(124, 108, 240, 0.55);
      box-shadow: 0 0 0 3px var(--accent-glow);
    }
    textarea { min-height: 170px; resize: vertical; }
    textarea.scripture-verse-field {
      min-height: 3.25rem;
      max-height: 14rem;
      padding: 8px 11px;
      font-size: 0.9rem;
      line-height: 1.45;
    }
    .scripture-chapter-nav { margin-bottom: 4px; }
    .scripture-nav-row {
      display: flex;
      flex-wrap: wrap;
      gap: 14px 18px;
      align-items: flex-end;
      padding: 14px 16px;
      background: rgba(15, 23, 42, 0.45);
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
    }
    .scripture-nav-field { flex: 1 1 200px; min-width: 0; }
    .scripture-nav-field label {
      display: block;
      margin: 0 0 6px;
      font-size: 0.8rem;
      letter-spacing: 0.02em;
      color: #94a3b8;
    }
    .scripture-nav-field--chapter { flex: 0 0 108px; max-width: 120px; }
    .scripture-nav-field--chapter select { min-width: 0; }
    .scripture-form-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: 18px;
    }
    .scripture-form-actions button { margin-top: 0; }
    a.scripture-back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 11px 18px;
      border-radius: var(--radius-sm);
      font-weight: 600;
      font-size: 0.9rem;
      text-decoration: none;
      color: #e2e8f0;
      background: rgba(30, 41, 59, 0.75);
      border: 1px solid rgba(148, 163, 184, 0.28);
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
      transition: background 0.15s ease, border-color 0.15s ease, transform 0.1s ease;
    }
    a.scripture-back-btn:hover {
      background: rgba(51, 65, 85, 0.9);
      border-color: rgba(124, 108, 240, 0.45);
      color: #fff;
    }
    a.scripture-back-btn:active { transform: translateY(1px); }
    a.scripture-back-btn .scripture-back-icon {
      font-size: 1.1rem;
      line-height: 1;
      opacity: 0.9;
    }
    .scripture-bible-toolbar {
      margin: 16px 0 20px;
    }
    a.btn-scripture {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 11px 20px;
      border-radius: var(--radius-sm);
      font-weight: 600;
      font-size: 0.9rem;
      text-decoration: none;
      border: 1px solid transparent;
      transition: filter 0.15s ease, transform 0.1s ease, border-color 0.15s ease, background 0.15s ease;
    }
    a.btn-scripture:active { transform: translateY(1px); }
    a.btn-scripture-primary {
      color: #fff;
      background: linear-gradient(135deg, #6d5dfc 0%, #8b7cf5 50%, #a78bfa 100%);
      box-shadow: 0 8px 24px rgba(109, 93, 252, 0.35);
    }
    a.btn-scripture-primary:hover {
      filter: brightness(1.08);
      color: #fff;
    }
    a.btn-scripture-secondary {
      color: #e2e8f0;
      background: rgba(30, 41, 59, 0.75);
      border-color: rgba(148, 163, 184, 0.28);
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
    }
    a.btn-scripture-secondary:hover {
      background: rgba(51, 65, 85, 0.92);
      border-color: rgba(124, 108, 240, 0.45);
      color: #fff;
    }
    ul.scripture-translation-list {
      list-style: none;
      margin: 0;
      padding: 0;
    }
    li.scripture-translation-item {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px 16px;
      padding: 14px 16px;
      margin-top: 10px;
      background: rgba(15, 23, 42, 0.4);
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
    }
    li.scripture-translation-item:first-of-type { margin-top: 0; }
    .scripture-translation-item .scripture-translation-title {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }
    .scripture-translation-item .scripture-translation-title strong {
      font-size: 1rem;
    }
    select[multiple] { min-height: 140px; }

    select:not([multiple]) {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      padding: 11px 42px 11px 12px;
      background-color: rgba(10, 12, 20, 0.55);
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%2394a3b8' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-size: 14px 14px;
      cursor: pointer;
    }
    select:not([multiple]):focus {
      background-color: rgba(10, 12, 20, 0.65);
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24'%3E%3Cpath fill='%23cbd5e1' d='M7 10l5 5 5-5H7z'/%3E%3C/svg%3E");
    }
    option { background: var(--card-solid); color: var(--text); }
    .rich-editor-wrap {
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      overflow: hidden;
      background: rgba(10, 12, 20, 0.45);
    }
    .rich-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
      padding: 10px;
      border-bottom: 1px solid var(--line);
      background: rgba(15, 23, 42, 0.65);
    }
    .rich-toolbar-group {
      display: inline-flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px;
      padding-right: 12px;
      margin-right: 2px;
      border-right: 1px solid var(--line);
    }
    .rich-toolbar-group:last-child {
      border-right: none;
      margin-right: 0;
      padding-right: 0;
    }
    .rich-toolbar-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      margin-right: 2px;
    }
    .rich-btn {
      margin-top: 0;
      padding: 6px 11px;
      border-radius: 8px;
      font-size: 13px;
      background: rgba(124, 108, 240, 0.2);
      color: #e0e7ff;
      border: 1px solid rgba(124, 108, 240, 0.25);
    }
    .rich-btn:hover { background: rgba(124, 108, 240, 0.32); }
    .rich-btn.active {
      background: linear-gradient(135deg, var(--accent), #5b4fc9);
      color: #fff;
      border-color: transparent;
    }
    .rich-btn-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 38px;
      min-height: 38px;
      padding: 7px;
    }
    .rich-btn-icon svg {
      display: block;
      flex-shrink: 0;
    }
    .rich-color-picker-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
    }
    .rich-color-toggle {
      width: 34px;
      height: 34px;
      min-width: 34px;
      min-height: 34px;
      padding: 0;
      margin-top: 0;
      border-radius: 8px;
      border: 2px solid rgba(148, 163, 184, 0.45);
      background: #ffffff;
      cursor: pointer;
      box-shadow: none;
    }
    .rich-color-picker-wrap.open .rich-color-toggle {
      border-color: #ffffff;
      box-shadow: 0 0 0 2px rgba(124, 108, 240, 0.55);
    }
    .rich-color-dropdown {
      position: absolute;
      top: calc(100% + 8px);
      left: 0;
      z-index: 25;
      display: none;
      width: 168px;
      max-height: min(240px, 55vh);
      padding: 8px;
      border-radius: 10px;
      border: 1px solid rgba(124, 108, 240, 0.35);
      background: rgba(8, 10, 18, 0.98);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
      overflow-y: auto;
      overscroll-behavior: contain;
    }
    .rich-color-picker-wrap.open .rich-color-dropdown {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 6px;
    }
    .rich-color-swatch {
      width: 18px;
      height: 18px;
      margin: 0;
      padding: 0;
      border-radius: 4px;
      border: 1px solid rgba(148, 163, 184, 0.5);
      cursor: pointer;
      box-shadow: none;
      margin-top: 0;
    }
    .rich-color-swatch:hover {
      filter: brightness(1.1);
    }
    .rich-color-swatch.active,
    .rich-color-swatch:focus-visible {
      border-color: #ffffff;
      box-shadow: 0 0 0 1px rgba(124, 108, 240, 0.65);
      outline: none;
    }
    .rich-color-swatch--white {
      border-color: rgba(203, 213, 225, 0.9);
    }
    .rich-quick-toolbar {
      position: absolute;
      z-index: 40;
      display: none;
      align-items: center;
      gap: 6px;
      padding: 8px;
      border-radius: 10px;
      border: 1px solid rgba(124, 108, 240, 0.35);
      background: rgba(8, 10, 18, 0.96);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
    }
    .rich-quick-toolbar .rich-btn {
      min-width: 34px;
      min-height: 34px;
      padding: 6px 9px;
    }
    .rich-quick-toolbar .rich-color-dropdown {
      left: auto;
      right: 0;
    }
    .rich-editor {
      min-height: 200px;
      padding: 14px;
      outline: none;
      color: var(--text);
    }
    .rich-editor:focus { box-shadow: inset 0 0 0 2px rgba(124, 108, 240, 0.25); }
    .rich-editor p { margin: 0 0 8px; color: inherit; }
    .rich-editor ul, .rich-editor ol { margin: 0 0 8px 20px; }
    .rich-editor-hidden { display: none; }
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
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 14px;
      background: var(--card);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-radius: var(--radius);
      overflow: hidden;
      border: 1px solid var(--line);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
    }
    th, td { border-bottom: 1px solid var(--line); padding: 12px; text-align: left; vertical-align: top; }
    th {
      background: rgba(15, 23, 42, 0.85);
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--muted);
    }
    tbody tr { transition: background 0.12s ease; }
    tbody tr:hover { background: rgba(124, 108, 240, 0.06); }
    tr:last-child td { border-bottom: none; }
    .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .actions form { display: inline; margin: 0; }
    .prayers-filter-form { margin: 0 0 8px; }
    #dynamic-sections > .prayers-filter-form:first-child { margin-top: 16px; }
    .prayers-filter-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 12px 18px;
      align-items: end;
      padding: 16px 18px;
      background: rgba(15, 23, 42, 0.45);
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
    }
    .prayers-filter-grid label {
      display: block;
      margin: 0 0 6px;
      font-size: 0.8rem;
      font-weight: 600;
      color: #94a3b8;
    }
    .prayers-filter-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 14px;
      align-items: center;
      margin-top: 14px;
    }
    .prayers-filter-actions button { margin-top: 0; }
    .prayers-list-meta {
      font-size: 0.875rem;
      color: var(--muted);
      margin: 10px 0 0;
    }
    .logout { background: rgba(15, 23, 42, 0.9); box-shadow: none; border: 1px solid var(--line); }
    .tree > ul.tree-level {
      list-style: none;
      margin: 0;
      padding: 0;
      border: none;
    }
    .tree li > ul.tree-level {
      list-style: none;
      margin: 8px 0 0 0;
      padding: 0 0 0 14px;
      border-left: 1px dashed rgba(148, 163, 184, 0.35);
    }
    .tree li { margin: 10px 0; color: var(--text); }
    .tree-card-full {
      --tree-ctrl-h: 32px;
      margin-top: 16px;
    }
    .tree-node {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 10px;
      width: 100%;
      min-height: var(--tree-ctrl-h);
      flex-wrap: nowrap;
    }
    .tree-node-meta {
      flex: 1 1 auto;
      min-width: 0;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .tree-actions {
      flex: 0 0 auto;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-left: 8px;
    }
    .tree-actions form {
      display: inline-flex;
      margin: 0;
      align-items: center;
    }
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
    .drag-handle {
      cursor: grab;
      user-select: none;
      color: var(--muted);
      border: 1px dashed rgba(148, 163, 184, 0.4);
      border-radius: 8px;
      padding: 0 8px;
      font-weight: 700;
      background: rgba(0, 0, 0, 0.2);
      min-width: var(--tree-ctrl-h, 32px);
      height: var(--tree-ctrl-h, 32px);
      box-sizing: border-box;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .drag-handle:active { cursor: grabbing; }
    .tree-item.dragging { opacity: .45; }
    .tree-item.drop-target > .tree-node {
      outline: 2px dashed rgba(124, 108, 240, 0.7);
      outline-offset: 3px;
      border-radius: 8px;
    }
    .tree-node-meta .badge {
      display: inline-flex;
      align-items: center;
      height: var(--tree-ctrl-h, 32px);
      padding: 0 10px;
      border-radius: 999px;
      background: rgba(196, 163, 90, 0.15);
      color: #e8d5a3;
      font-size: 11px;
      font-weight: 600;
      margin-left: 0;
      border: 1px solid rgba(196, 163, 90, 0.25);
      box-sizing: border-box;
      flex-shrink: 0;
    }
    .move-btn {
      margin-top: 0;
      padding: 0;
      min-width: var(--tree-ctrl-h, 32px);
      height: var(--tree-ctrl-h, 32px);
      border-radius: 8px;
      font-size: 12px;
      line-height: 1;
      background: rgba(124, 108, 240, 0.2);
      color: #e0e7ff;
      border: 1px solid rgba(124, 108, 240, 0.28);
      box-shadow: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-sizing: border-box;
    }
    .tree-actions .btn-pill--sm {
      height: var(--tree-ctrl-h, 32px);
      min-height: var(--tree-ctrl-h, 32px);
      padding: 0 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-sizing: border-box;
    }
    .tree-actions .btn-mini {
      height: var(--tree-ctrl-h, 32px);
      min-height: var(--tree-ctrl-h, 32px);
      padding: 0 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-sizing: border-box;
    }
    .badge {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 999px;
      background: rgba(196, 163, 90, 0.15);
      color: #e8d5a3;
      font-size: 11px;
      font-weight: 600;
      margin-left: 6px;
      border: 1px solid rgba(196, 163, 90, 0.25);
    }
    .inline-help { font-size: 13px; color: var(--muted); margin-top: 6px; }
    .hidden { display: none; }
    .busy { opacity: 0.75; pointer-events: none; }
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
    .table-section-title { margin-top: 24px; margin-bottom: 0; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 900px) {
      .grid { grid-template-columns: 1fr; }
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
    }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
    }

body.body-auth {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      max-width: none;
      padding: 0;
    }
    body.body-auth .header {
      display: none;
    }
    .auth-layout {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px 16px 48px;
      width: 100%;
    }
    .auth-card {
      position: relative;
      width: 100%;
      max-width: 420px;
      padding: 36px 32px 32px;
      border-radius: 22px;
      background:
        linear-gradient(155deg, rgba(26, 32, 54, 0.97) 0%, rgba(15, 23, 42, 0.94) 45%, rgba(17, 24, 39, 0.96) 100%);
      border: 1px solid rgba(148, 163, 184, 0.22);
      box-shadow:
        0 0 0 1px rgba(124, 108, 240, 0.12),
        0 28px 56px rgba(0, 0, 0, 0.5),
        0 0 80px rgba(124, 108, 240, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.06);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
    }
    .auth-card::after {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      pointer-events: none;
      background: radial-gradient(ellipse 80% 50% at 50% -30%, rgba(124, 108, 240, 0.18), transparent 55%);
    }
    .auth-card > * { position: relative; z-index: 1; }
    .auth-card--warning {
      border-color: rgba(251, 191, 36, 0.35);
      box-shadow:
        0 0 0 1px rgba(251, 191, 36, 0.15),
        0 28px 56px rgba(0, 0, 0, 0.5),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
    }
    .auth-card--warning::after {
      background: radial-gradient(ellipse 80% 50% at 50% -30%, rgba(251, 191, 36, 0.12), transparent 55%);
    }
    .auth-card-head {
      text-align: center;
      margin-bottom: 28px;
    }
    .auth-eyebrow {
      margin: 0 0 8px;
      font-size: 0.6875rem;
      font-weight: 600;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: rgba(196, 163, 90, 0.9);
    }
    .auth-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 56px;
      height: 56px;
      margin-bottom: 18px;
      border-radius: 16px;
      background: linear-gradient(145deg, rgba(124, 108, 240, 0.35), rgba(196, 163, 90, 0.12));
      border: 1px solid rgba(124, 108, 240, 0.35);
      box-shadow: 0 8px 24px rgba(109, 93, 252, 0.25);
      color: #e9d5ff;
    }
    .auth-icon svg {
      width: 26px;
      height: 26px;
      opacity: 0.95;
    }
    .auth-card h2 {
      margin: 0 0 10px;
      font-size: 1.45rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: var(--text);
    }
    .auth-lead {
      margin: 0;
      font-size: 0.9375rem;
      line-height: 1.25;
      color: var(--muted);
    }
    .auth-alert {
      margin-bottom: 20px;
      padding: 12px 14px;
      border-radius: var(--radius-sm);
      font-size: 0.875rem;
      font-weight: 500;
      line-height: 1.45;
      border: 1px solid rgba(248, 113, 113, 0.35);
      background: rgba(127, 29, 29, 0.35);
      color: #fecaca;
    }
    .auth-alert--system {
      border-color: rgba(251, 191, 36, 0.4);
      background: rgba(120, 53, 15, 0.35);
      color: #fde68a;
    }
    .auth-form label {
      margin-top: 18px;
      margin-bottom: 8px;
      font-size: 0.8125rem;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: #94a3b8;
    }
    .auth-form label:first-of-type {
      margin-top: 0;
    }
    .auth-form input[type="password"],
    .auth-form input[type="text"] {
      padding: 14px 16px;
      font-size: 1rem;
      border-radius: 12px;
      background: rgba(10, 12, 20, 0.65);
      border: 1px solid rgba(148, 163, 184, 0.2);
    }
    .auth-form input[type="password"]:focus,
    .auth-form input[type="text"]:focus {
      border-color: rgba(124, 108, 240, 0.55);
      box-shadow: 0 0 0 4px var(--accent-glow);
    }
    .auth-form button[type="submit"] {
      width: 100%;
      margin-top: 26px;
      padding: 14px 20px;
      font-size: 0.9375rem;
      letter-spacing: 0.02em;
      border-radius: 12px;
    }
    .auth-hint {
      margin: 18px 0 0;
      text-align: center;
      font-size: 0.8125rem;
      color: var(--muted);
      line-height: 1.5;
    }
    .auth-hint code {
      font-size: 0.85em;
    }
  </style><style>.songbook-admin-panel + table{margin-top:22px}.kantaral-page-title{margin-top:16px}.kantaral-form-card{margin-top:16px}</style></head><body>
<div class="header"><div class="header-brand"><h1>Totus Tuus</h1><p class="header-tagline">Панэль кіравання Святой Памяці<br>Біскупа Казіміра Велікасельца OP</p></div><?php $panelNavPage='kantaral'; $panelNavView='kantaral'; $panelNavCalYear=(int)date('Y'); require __DIR__ . '/../includes/panel_admin_nav.php'; ?></div>
<h2 class="table-section-title kantaral-page-title">Кантарал</h2>
<?php if ($message !== ''): ?><p class="ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
<?php if ($addMode): ?>
<div class="card kantaral-form-card"><h2><?= $edit ? 'Рэдагаваць запіс' : 'Дадаць запіс' ?></h2><form method="post" enctype="multipart/form-data"><?= panel_csrf_field() ?><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?><label>Назва</label><input name="title" value="<?= htmlspecialchars((string)($edit['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><label>Катэгорыя</label><input name="category" value="<?= htmlspecialchars((string)($edit['category'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><label>Нумар главы</label><input type="number" min="1" name="chapter_major" required value="<?= (int)($edit['chapter_major'] ?? 1) ?>"><label>Падглава</label><input type="number" min="1" name="subchapter" value="<?= $edit && $edit['subchapter'] !== null ? (int)$edit['subchapter'] : '' ?>"><label>Парадак</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>"><label>Тып</label><select name="content_type"><option value="text"<?= (string)($edit['content_type'] ?? 'text') === 'text' ? 'selected' : '' ?>>Тэкст</option><option value="image"<?= (string)($edit['content_type'] ?? '') === 'image' ? 'selected' : '' ?>>Выява</option></select><label>Тэкст / HTML</label><textarea name="text_body" rows="12"><?= htmlspecialchars((string)($edit['text_body'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea><label>Выява JPEG, PNG, WebP, GIF, AVIF</label><input type="file" name="media" accept=".jpg,.jpeg,.png,.webp,.gif,.avif,image/*"><?php if ($edit && !empty($edit['media_path'])): ?><p>Бягучы файл: <code><?= htmlspecialchars((string)$edit['media_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></p><?php endif; ?><div class="form-actions-row"><button type="submit">Захаваць</button><a class="btn-pill btn-pill--gold" href="/admin/kantaral.php">Да спісу</a></div></form></div>
<?php else: ?>
<div class="songbook-toolbar-top" style="margin:12px 0 0"><a class="btn btn-pill btn-pill--purple" href="/admin/kantaral.php?add=1">Дадаць запіс</a></div>
<div class="songbook-admin-panel"><form method="get" class="songbook-panel-section songbook-filter-form" aria-label="Фільтр па катэгорыях кантарала"><details class="panel-filter-details"<?= count($catSelected) > 0 ? ' open' : '' ?>><summary class="panel-filter-summary"><span class="panel-filter-summary__title">Выбар катэгорый</span><span class="panel-filter-summary__meta"><?= count($catSelected) > 0 ? 'У фільтры: ' . (string)count($catSelected) : 'Усе запісы' ?></span><span class="panel-filter-summary__hint">Націсніце, каб разгарнуць або згарнуць спіс катэгорый і галачак.</span></summary><div class="panel-filter-details__body"><p class="songbook-panel-section__hint">Некалькі галачак працуюць як <strong>АБО</strong>: у спісе застаюцца запісы з любой з абраных катэгорый.</p><div class="songbook-filter-chips" role="group" aria-label="Катэгорыі"><?php $hasEmpty = in_array('', array_map('trim', $catDistinct), true); if ($hasEmpty): ?><label class="songbook-filter-chip"><input type="checkbox" name="cat[]" value="<?= htmlspecialchars($catEmptyToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= in_array($catEmptyToken, $catSelected, true) ? ' checked' : '' ?>><span>(без катэгорыі)</span></label><?php endif; ?><?php foreach ($catDistinct as $dc): ?><?php if (trim($dc) === '') continue; ?><label class="songbook-filter-chip"><input type="checkbox" name="cat[]" value="<?= htmlspecialchars($dc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= in_array($dc, $catSelected, true) ? ' checked' : '' ?>><span><?= htmlspecialchars($dc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></label><?php endforeach; ?></div><?php if (count($catDistinct) === 0): ?><p class="songbook-panel-section__empty">Катэгорыі з’явяцца пасля дадання запісаў.</p><?php endif; ?><div class="songbook-panel-actions"><button type="submit" class="btn btn-pill btn-pill--purple">Паказаць</button><a class="btn btn-pill btn-pill--muted" href="/admin/kantaral.php">Скід фільтра</a></div></div></details></form><div class="songbook-panel-divider" role="presentation"></div><form id="kantaral-bulk-form" method="post" class="songbook-panel-section songbook-bulk-form"><?= panel_csrf_field() ?><div class="songbook-panel-section__head"><span class="songbook-panel-section__title">Масавыя дзеянні</span></div><p class="songbook-panel-section__hint">Пазначыце радкі ў табліцы ніжэй. Можна або змяніць ім агульную катэгорыю, або выставіць нумарацыю <strong>1…N</strong> па парадку выбраных радкоў.</p><div class="songbook-bulk-row"><label for="bulk_category_value" class="bulk-songbook-label">Новая катэгорыя</label><input id="bulk_category_value" name="bulk_category_value" type="text" maxlength="255" class="bulk-songbook-input" placeholder="Напрыклад, Адвэнт; пуста — без загалоўка"><button type="submit" name="bulk_category" value="1" class="btn btn-pill btn-pill--gold">Ужыць катэгорыю</button><button type="submit" name="bulk_autonumber" value="1" class="btn btn-pill btn-pill--purple">Аўтанумарацыя 1…N</button><button type="submit" name="bulk_clear_numbering" value="1" class="btn btn-pill btn-pill--muted">Ачысціць нумарацыю</button></div></form></div>
<table><thead><tr><th class="cell-checkbox"><input type="checkbox" id="kantaral-bulk-select-all" title="Абраць усе" aria-label="Абраць усе запісы"></th><th>ID</th><th>Катэгорыя</th><th>№</th><th>Назва</th><th>Тып</th><th>Файл</th><th>Дзеянні</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td class="cell-checkbox"><input type="checkbox" class="kantaral-bulk-id-cb" name="bulk_ids[]" value="<?= (int)$r['id'] ?>" form="kantaral-bulk-form" aria-label="Абраць запіс<?= (int)$r['id'] ?>"></td><td><?= (int)$r['id'] ?></td><td><?= htmlspecialchars((string)$r['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td><?= (int)$r['chapter_major'] ?><?= $r['subchapter'] !== null ? '.' . (int)$r['subchapter'] : '.' ?></td><td><?= htmlspecialchars((string)$r['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$r['content_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($r['media_path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td class="actions"><a class="btn-pill btn-pill--sm btn-pill--purple" href="/admin/kantaral.php?edit=<?= (int)$r['id'] ?>">Рэдагаваць</a><form method="post" onsubmit="return confirm('Выдаліць запіс кантарала?')"><?= panel_csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>"><button class="btn-pill btn-pill--sm btn-pill--danger" type="submit">Выдаліць</button></form></td></tr><?php endforeach; ?></tbody></table><script>document.getElementById('kantaral-bulk-select-all')?.addEventListener('change',function(){document.querySelectorAll('.kantaral-bulk-id-cb').forEach(function(cb){cb.checked=this.checked}.bind(this));});</script>
<?php endif; ?>
</body></html>
