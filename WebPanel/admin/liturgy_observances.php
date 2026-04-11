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

function lo_obs_bulk_replace(string $text, string $find, string $replace, bool $caseSensitive): string
{
    if ($find === '') {
        return $text;
    }
    if ($caseSensitive) {
        return str_replace($find, $replace, $text);
    }
    // str_ireplace() is not Unicode-safe for Cyrillic (UTF-8); /iu + preg_quote gives true case-folding.
    $pattern = '/' . preg_quote($find, '/') . '/iu';

    return (string)preg_replace_callback(
        $pattern,
        static function () use ($replace): string {
            return $replace;
        },
        $text
    );
}

/**
 * @return list<array<string, mixed>>
 */
function lo_obs_bulk_load_rows(bool $scopeFiltered, string $filterKindForScope): array
{
    $sql = 'SELECT * FROM liturgy_observances WHERE 1=1';
    $params = [];
    if ($scopeFiltered && $filterKindForScope !== '' && in_array($filterKindForScope, ['important', 'optional', 'patch'], true)) {
        $sql .= ' AND observance_kind = :k';
        $params[':k'] = $filterKindForScope;
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @return array{error: string|null, changes: list<array<string, mixed>>}
 */
function lo_obs_bulk_compute(
    string $find,
    string $replace,
    bool $caseSensitive,
    bool $useTitle,
    bool $usePatch,
    bool $scopeFiltered,
    string $kindForBulk
): array {
    if (!in_array($kindForBulk, ['', 'important', 'optional', 'patch'], true)) {
        $kindForBulk = '';
    }
    if ($find === '') {
        return ['error' => 'Увядзіце тэкст для пошуку.', 'changes' => []];
    }
    if (!$useTitle && !$usePatch) {
        return ['error' => 'Абярыце хаця б адно поле: загаловак і/або patch_suffix.', 'changes' => []];
    }
    $scan = lo_obs_bulk_load_rows($scopeFiltered, $kindForBulk);
    $changes = [];
    foreach ($scan as $r) {
        $id = (int)$r['id'];
        $title = (string)($r['title'] ?? '');
        $patch = (string)($r['patch_suffix'] ?? '');
        $nt = $useTitle ? lo_obs_bulk_replace($title, $find, $replace, $caseSensitive) : $title;
        $np = $usePatch ? lo_obs_bulk_replace($patch, $find, $replace, $caseSensitive) : $patch;
        if ($nt !== $title || $np !== $patch) {
            $changes[] = [
                'id' => $id,
                'title_old' => $title,
                'title_new' => $nt,
                'patch_old' => $patch,
                'patch_new' => $np,
                'touch_title' => $useTitle && $nt !== $title,
                'touch_patch' => $usePatch && $np !== $patch,
            ];
        }
    }

    return ['error' => null, 'changes' => $changes];
}

/**
 * @param list<array<string, mixed>> $changes
 * @return list<array<string, mixed>>
 */
function lo_obs_bulk_json_rows_for_preview(array $changes, int $maxRows, int $maxChars): array
{
    $slice = array_slice($changes, 0, $maxRows);
    $out = [];
    foreach ($slice as $ch) {
        $row = [
            'id' => (int)$ch['id'],
            'touch_title' => (bool)$ch['touch_title'],
            'touch_patch' => (bool)$ch['touch_patch'],
        ];
        foreach (['title_old', 'title_new', 'patch_old', 'patch_new'] as $k) {
            $s = (string)($ch[$k] ?? '');
            if (mb_strlen($s) > $maxChars) {
                $row[$k] = mb_substr($s, 0, $maxChars) . '…';
            } else {
                $row[$k] = $s;
            }
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function lo_observances_rows_for_list_kind(string $kind): array
{
    $kind = trim($kind);
    if (!in_array($kind, ['', 'important', 'optional', 'patch'], true)) {
        $kind = '';
    }
    $sql = 'SELECT * FROM liturgy_observances WHERE 1=1';
    $params = [];
    if ($kind !== '') {
        $sql .= ' AND observance_kind = :k';
        $params[':k'] = $kind;
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @param list<array<string, mixed>> $rows
 */
function lo_observances_table_rows_html(array $rows): string
{
    ob_start();
    foreach ($rows as $r) {
        ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars((string)$r['rule_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td class="muted"><?php
                if ($r['rule_type'] === 'fixed_md') {
                    echo htmlspecialchars(sprintf('%02d-%02d', (int)$r['month'], (int)$r['day']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                } elseif ($r['rule_type'] === 'easter_offset') {
                    echo 'E' . (int)$r['easter_offset'];
                } elseif ($r['rule_type'] === 'advent_offset') {
                    echo 'A' . (int)$r['advent_offset_days'];
                } else {
                    echo '—';
                }
                ?></td>
              <td><?= htmlspecialchars((string)$r['observance_kind'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><?= htmlspecialchars(mb_substr((string)$r['title'], 0, 80), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= mb_strlen((string)$r['title']) > 80 ? '…' : '' ?></td>
              <td class="muted"><?php
                $bits = array_filter([
                    $r['require_any_of'] !== '' ? 'any:' . $r['require_any_of'] : '',
                    $r['forbid_if_any_of'] !== '' ? '!:' . $r['forbid_if_any_of'] : '',
                ]);
                echo htmlspecialchars(implode(' ', $bits), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                ?></td>
              <td>
                <div class="day-actions-row">
                  <a href="/admin/liturgy_observances_edit.php?id=<?= (int)$r['id'] ?>" class="btn">Змена</a>
                  <form method="post" style="margin:0;" onsubmit="return confirm('Выдаліць?');">
                    <?= panel_csrf_field() ?>
                    <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="danger" style="padding:6px 10px;font-size:12px;">Выдаліць</button>
                  </form>
                </div>
              </td>
            </tr>
        <?php
    }

    return (string)ob_get_clean();
}

$filterKind = trim((string)($_GET['kind'] ?? ''));
if (isset($_GET['edit'])) {
    $legacyEdit = (int)$_GET['edit'];
    if ($legacyEdit > 0) {
        header('Location: /admin/liturgy_observances_edit.php?id=' . $legacyEdit, true, 302);
        exit;
    }
}

$message = null;
$error = null;

$isBulkAjax = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
    && isset($_POST['bulk_ajax']) && (string)$_POST['bulk_ajax'] === '1'
    && (isset($_POST['bulk_preview']) || isset($_POST['bulk_execute']));

if ($isBulkAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!panel_csrf_token_valid()) {
            echo json_encode(['ok' => false, 'error' => 'Сесія пратэрмінаваная. Абнавіце старонку.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $find = (string)($_POST['bulk_find'] ?? '');
        $replace = (string)($_POST['bulk_replace'] ?? '');
        $caseSensitive = isset($_POST['bulk_case_sensitive']);
        $useTitle = isset($_POST['bulk_col_title']);
        $usePatch = isset($_POST['bulk_col_patch']);
        $scopeFiltered = isset($_POST['bulk_scope_filtered']);
        $kindForBulk = trim((string)($_POST['bulk_filter_kind'] ?? ''));
        $computed = lo_obs_bulk_compute($find, $replace, $caseSensitive, $useTitle, $usePatch, $scopeFiltered, $kindForBulk);
        if ($computed['error'] !== null) {
            echo json_encode(['ok' => false, 'error' => $computed['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $changes = $computed['changes'];
        if (isset($_POST['bulk_execute']) && $changes === []) {
            echo json_encode(['ok' => false, 'error' => 'Няма змен для захавання. Спачатку зрабіце прадпрагляд.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (isset($_POST['bulk_preview'])) {
            $maxRows = 500;
            $maxChars = 600;
            $rowsJson = lo_obs_bulk_json_rows_for_preview($changes, $maxRows, $maxChars);
            $omitted = count($changes) - count($rowsJson);
            echo json_encode([
                'ok' => true,
                'change_count' => count($changes),
                'rows' => $rowsJson,
                'rows_omitted' => max(0, $omitted),
                'message' => count($changes) === 0
                    ? 'Супадзенняў не знойдзена.'
                    : ('Знойдзена запісаў са зменамі: ' . count($changes) . ($omitted > 0 ? ' (у адказе першыя ' . count($rowsJson) . ')' : '') . '.'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                'UPDATE liturgy_observances SET title = :title, patch_suffix = :patch WHERE id = :id'
            );
            $n = 0;
            foreach ($changes as $ch) {
                $upd->execute([
                    ':title' => $ch['title_new'],
                    ':patch' => $ch['patch_new'] === '' ? null : $ch['patch_new'],
                    ':id' => $ch['id'],
                ]);
                $n++;
            }
            $pdo->commit();
            liturgy_observances_invalidate_cache();
            echo json_encode([
                'ok' => true,
                'updated' => $n,
                'message' => 'Масавая замена выканана. Абноўлена запісаў: ' . $n . '.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Памылка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$isListAjax = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
    && isset($_POST['list_ajax']) && (string)$_POST['list_ajax'] === '1';

if ($isListAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (!panel_csrf_token_valid()) {
        echo json_encode(['ok' => false, 'error' => 'Сесія пратэрмінаваная. Абнавіце старонку.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $listKind = trim((string)($_POST['list_kind'] ?? ''));
    if (!in_array($listKind, ['', 'important', 'optional', 'patch'], true)) {
        $listKind = '';
    }
    try {
        $listRows = lo_observances_rows_for_list_kind($listKind);
        echo json_encode([
            'ok' => true,
            'count' => count($listRows),
            'filter_kind' => $listKind,
            'tbody_html' => lo_observances_table_rows_html($listRows),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Памылка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
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
            }
        } catch (Throwable $e) {
            $error = 'Памылка: ' . $e->getMessage();
        }
    }
}

if ($filterKind !== '' && !in_array($filterKind, ['important', 'optional', 'patch'], true)) {
    $filterKind = '';
}
$rows = lo_observances_rows_for_list_kind($filterKind);

$year = (int)date('Y');

if (isset($_GET['deleted']) && (string)$_GET['deleted'] === '1' && $message === null) {
    $message = 'Запіс выдалены.';
}

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
      --accent-violet: #7c6cf0;
      --accent-violet-dim: rgba(124, 108, 240, 0.35);
      --accent-gold: #c4a35a;
      --accent-gold-dim: rgba(196, 163, 90, 0.22);
      --surface: #111827;
      --surface-inset: #0b1224;
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
    .card__title {
      font-size: 1.15rem;
      font-weight: 600;
      color: #f8fafc;
      margin: 0 0 6px;
    }
    .card__lead { margin: 0 0 16px; line-height: 1.5; }
    .card__lead a {
      color: #c4b5fd;
      text-decoration: none;
      border-bottom: 1px solid rgba(196, 181, 253, 0.35);
      transition: color 0.15s, border-color 0.15s;
    }
    .card__lead a:hover { color: #e9d5ff; border-bottom-color: rgba(233, 213, 255, 0.55); }
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
    button.btn-pill {
      margin-top: 0;
      font-family: inherit;
      font-weight: 600;
      padding: 8px 12px;
      border-radius: var(--radius-sm);
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.08);
      color: var(--text);
      box-shadow: none;
      filter: none;
    }
    button.btn-pill:hover:not(:disabled) {
      filter: brightness(1.08);
      box-shadow: none;
    }
    button.btn-pill:active:not(:disabled) { transform: none; }
    .btn { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border-radius: 10px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; padding: 8px 12px; font-weight: 600; }
    .msg { margin: 0 0 12px; padding: 10px 12px; border-radius: 10px; }
    .ok { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.45); color: #bbf7d0; }
    .err { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.45); color: #fecaca; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 18px; width: 100%; }
    .card {
      position: relative;
      background: linear-gradient(155deg, rgba(24, 32, 54, 0.98) 0%, rgba(17, 24, 39, 0.99) 50%, rgba(15, 23, 42, 1) 100%);
      border: 1px solid rgba(100, 116, 139, 0.35);
      border-radius: 16px;
      padding: 22px 22px 24px;
      overflow: hidden;
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.06) inset,
        0 20px 40px -24px rgba(0, 0, 0, 0.55);
    }
    .card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, rgba(196, 163, 90, 0.25), rgba(124, 108, 240, 0.35), transparent);
      pointer-events: none;
      opacity: 0.9;
    }
    .table-wrap { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; min-width: 820px; }
    .table th, .table td { border-bottom: 1px solid #273449; padding: 10px 8px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
    .table thead th {
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #94a3b8;
      font-weight: 700;
      background: rgba(15, 23, 42, 0.85);
      border-bottom: 1px solid rgba(124, 108, 240, 0.2);
    }
    .table tbody tr:hover td { background: rgba(124, 108, 240, 0.04); }
    .table tr:last-child td { border-bottom: none; }
    .table .btn { white-space: nowrap; padding: 6px 10px; }
    .muted { color: #94a3b8; font-size: 13px; }
    .status-row { margin: 6px 0 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .list-filter-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      margin: 4px 0 14px;
    }
    .list-count-line { margin: 0 0 12px; }
    label { display: block; margin-top: 10px; margin-bottom: 4px; font-size: 13px; color: #cbd5e1; font-weight: 600; }
    label.checkbox-row { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-weight: 600; }
    label.checkbox-row input { width: auto; }
    input[type="date"], input[type="text"], input[type="number"], select, textarea {
      width: 100%;
      border: 1px solid rgba(51, 65, 85, 0.9);
      background: var(--surface-inset);
      color: #e2e8f0;
      border-radius: 11px;
      padding: 11px 13px;
      font: inherit;
      transition: border-color 0.18s ease, box-shadow 0.18s ease;
    }
    input:focus, textarea:focus, select:focus {
      outline: none;
      border-color: rgba(124, 108, 240, 0.55);
      box-shadow: 0 0 0 3px rgba(124, 108, 240, 0.18);
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
    textarea { min-height: 88px; resize: vertical; max-width: 100%; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center; }
    button {
      border: 1px solid transparent;
      background: linear-gradient(135deg, #8b7cf8 0%, #6d5acd 100%);
      color: #fff;
      font-weight: 700;
      border-radius: 11px;
      padding: 11px 16px;
      cursor: pointer;
      font-family: inherit;
      transition: transform 0.12s ease, filter 0.15s ease, box-shadow 0.15s ease;
      box-shadow: 0 4px 14px -4px rgba(124, 108, 240, 0.55);
    }
    button:hover:not(:disabled) { filter: brightness(1.06); box-shadow: 0 6px 20px -4px rgba(124, 108, 240, 0.65); }
    button:active:not(:disabled) { transform: scale(0.98); }
    button:disabled {
      opacity: 0.52;
      cursor: not-allowed;
      transform: none;
      filter: none;
      box-shadow: none;
    }
    .danger {
      background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%);
      box-shadow: 0 4px 14px -4px rgba(220, 38, 38, 0.45);
    }
    .danger:hover:not(:disabled) { box-shadow: 0 6px 18px -4px rgba(220, 38, 38, 0.5); }
    code { font-size: 12px; background: rgba(15, 23, 42, 0.8); padding: 2px 6px; border-radius: 6px; border: 1px solid #334155; }
    .day-actions-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .toolbar-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
    .btn-muted {
      background: rgba(51, 65, 85, 0.65);
      border: 1px solid rgba(71, 85, 105, 0.85);
      color: #e2e8f0;
      box-shadow: none;
    }
    .btn-muted:hover:not(:disabled) {
      background: rgba(71, 85, 105, 0.85);
      filter: none;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    .diff-old {
      color: #fca5a5;
      text-decoration: line-through;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      font-size: 12px;
      padding: 6px 8px;
      margin-top: 4px;
      border-radius: 6px;
      background: rgba(127, 29, 29, 0.2);
      border: 1px solid rgba(248, 113, 113, 0.12);
    }
    .diff-new {
      color: #86efac;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      font-size: 12px;
      padding: 6px 8px;
      margin-top: 4px;
      border-radius: 6px;
      background: rgba(22, 101, 52, 0.2);
      border: 1px solid rgba(74, 222, 128, 0.12);
    }
    .bulk-panel {
      margin-top: 0;
      padding-top: 0;
      border-top: none;
    }
    /* Згортванне ў стылі дзён у аб’явах (announcements.php) */
    .bulk-fold {
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      margin-top: 0;
      background: rgba(0, 0, 0, 0.18);
      overflow: hidden;
    }
    .bulk-fold-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 14px;
      padding: 10px 12px;
      border-bottom: 1px solid transparent;
    }
    .bulk-fold:not(.bulk-fold--collapsed) .bulk-fold-toolbar {
      border-bottom-color: var(--line);
    }
    button.bulk-fold-toggle {
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
      line-height: 0;
      font-weight: 600;
      font-family: inherit;
      margin-top: 0;
      box-shadow: none;
      filter: none;
      transition: background 0.15s ease, color 0.15s ease;
    }
    button.bulk-fold-toggle:hover:not(:disabled) {
      background: rgba(124, 108, 240, 0.2);
      color: #fff;
      filter: none;
    }
    button.bulk-fold-toggle:active:not(:disabled) {
      transform: none;
    }
    button.bulk-fold-toggle:focus-visible {
      outline: 2px solid rgba(124, 108, 240, 0.55);
      outline-offset: 2px;
    }
    .bulk-fold--collapsed .bulk-fold-toggle .bulk-fold-chev {
      transform: rotate(-90deg);
    }
    .bulk-fold-chev {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 14px;
      height: 14px;
      flex-shrink: 0;
      transition: transform 0.15s ease;
      transform-origin: 50% 50%;
    }
    .bulk-fold-chev svg {
      display: block;
      width: 12px;
      height: 12px;
    }
    .bulk-fold-title {
      margin: 0;
      flex: 1 1 140px;
      font-size: 0.9375rem;
      font-weight: 700;
      color: #e2e8f0;
    }
    .bulk-fold-body {
      padding: 12px 14px 14px;
    }
    .bulk-fold-body[hidden] {
      display: none !important;
    }
    .bulk-log-section {
      margin-top: 12px;
    }
    .bulk-log-section[hidden] {
      display: none !important;
    }
    .bulk-log-heading {
      margin: 0 0 8px;
      font-size: 0.875rem;
      font-weight: 600;
      color: #e2e8f0;
    }
    .bulk-preview-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 14px;
      margin: 12px 0 10px;
    }
    .bulk-preview-row .btn-bulk-preview,
    .bulk-preview-row .danger {
      box-sizing: border-box;
      min-height: 42px;
      padding: 10px 16px;
      font-size: 0.875rem;
      font-weight: 600;
      line-height: 1.2;
      border-radius: var(--radius-sm);
      margin-top: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .bulk-preview-row .danger:disabled {
      opacity: 0.42;
      cursor: not-allowed;
      filter: grayscale(0.2);
      box-shadow: none;
    }
    .btn-bulk-preview {
      gap: 8px;
      min-width: 0;
      background: linear-gradient(135deg, rgba(124, 108, 240, 0.92) 0%, rgba(88, 63, 168, 0.95) 100%);
      border: 1px solid rgba(196, 181, 253, 0.28);
      color: #fff;
      cursor: pointer;
      font-family: inherit;
      box-shadow: 0 2px 12px -4px rgba(124, 108, 240, 0.5);
      transition: transform 0.12s ease, box-shadow 0.18s ease, filter 0.15s ease;
    }
    .btn-bulk-preview:hover:not(:disabled) {
      filter: brightness(1.06);
      box-shadow: 0 4px 16px -4px rgba(124, 108, 240, 0.55);
    }
    .btn-bulk-preview:active:not(:disabled) { transform: scale(0.98); }
    .btn-bulk-preview:disabled { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
    .bulk-status-line {
      font-size: 13px;
      flex: 1 1 220px;
      line-height: 1.45;
      min-height: 1.4em;
      margin: 0;
    }
    .bulk-status-line.is-error { color: #fecaca !important; }
    .bulk-spinner {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      color: #a5b4fc;
      font-weight: 600;
    }
    .bulk-spinner[hidden] { display: none !important; }
    .bulk-spinner::before {
      content: "";
      width: 14px;
      height: 14px;
      border: 2px solid rgba(165, 180, 252, 0.25);
      border-top-color: #a5b4fc;
      border-radius: 50%;
      animation: bulk-spin 0.7s linear infinite;
    }
    @keyframes bulk-spin { to { transform: rotate(360deg); } }
    .bulk-form { margin: 0; }
    .bulk-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 14px;
      align-items: start;
    }
    .bulk-form-grid .bulk-field input[type="text"] {
      min-height: 0;
      padding: 9px 11px;
      border-radius: 10px;
    }
    @media (max-width: 640px) {
      .bulk-form-grid { grid-template-columns: 1fr; }
    }
    .bulk-field label { margin-top: 0; }
    .bulk-options {
      border: none;
      padding: 0;
      margin: 10px 0 0;
    }
    .bulk-options__legend {
      font-size: 13px;
      text-transform: none;
      letter-spacing: normal;
      color: #cbd5e1;
      font-weight: 600;
      margin: 0 0 8px;
      padding: 0;
    }
    .bulk-chip-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 6px 8px;
    }
    label.bulk-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin: 0;
      padding: 6px 10px;
      border-radius: 8px;
      background: rgba(15, 23, 42, 0.65);
      border: 1px solid rgba(51, 65, 85, 0.75);
      cursor: pointer;
      font-weight: 600;
      font-size: 12px;
      color: #e2e8f0;
      transition: border-color 0.15s ease, background 0.15s ease;
    }
    label.bulk-chip:hover {
      border-color: rgba(124, 108, 240, 0.35);
      background: rgba(124, 108, 240, 0.06);
    }
    label.bulk-chip:has(input:checked) {
      border-color: rgba(196, 163, 90, 0.35);
      background: rgba(124, 108, 240, 0.12);
    }
    label.bulk-chip input {
      width: 15px;
      height: 15px;
      accent-color: var(--accent-violet);
      cursor: pointer;
    }
    .bulk-log-stack {
      margin-top: 4px;
      min-width: 0;
    }
    .bulk-change-log {
      display: flex;
      flex-direction: column;
      gap: 8px;
      overflow: auto;
      background: rgba(15, 23, 42, 0.55);
      border: 1px solid rgba(51, 65, 85, 0.55);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 12px;
      line-height: 1.45;
      margin: 0;
      scrollbar-gutter: stable;
    }
    .bulk-change-log:has(.bulk-log-item),
    .bulk-change-log:has(.bulk-log-more) {
      max-height: min(360px, 52vh);
    }
    .bulk-change-log::-webkit-scrollbar { width: 9px; }
    .bulk-change-log::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.5); border-radius: 5px; }
    .bulk-change-log::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, rgba(124, 108, 240, 0.45), rgba(124, 108, 240, 0.2));
      border-radius: 5px;
      border: 2px solid transparent;
      background-clip: padding-box;
    }
    .bulk-change-log .bulk-log-placeholder {
      margin: 0;
      color: #64748b;
      font-style: normal;
      padding: 6px 4px;
      text-align: left;
      line-height: 1.5;
    }
    .bulk-log-item {
      padding: 10px 12px;
      border-radius: 8px;
      background: rgba(30, 41, 59, 0.4);
      border: 1px solid rgba(51, 65, 85, 0.5);
    }
    .bulk-log-id {
      font-weight: 700;
      color: #e2e8f0;
      margin-bottom: 8px;
      font-size: 12px;
      letter-spacing: 0.02em;
    }
    .bulk-log-id code {
      font-size: 11px;
      vertical-align: middle;
    }
    .bulk-diff-block { margin-top: 8px; }
    .bulk-diff-label {
      display: block;
      margin-bottom: 6px;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: #94a3b8;
      font-weight: 700;
    }
    .bulk-log-more {
      margin-top: 4px;
      padding: 12px 14px;
      border-radius: 10px;
      background: rgba(124, 108, 240, 0.08);
      border: 1px dashed rgba(124, 108, 240, 0.28);
      color: #c4b5fd;
      font-size: 12px;
      line-height: 1.45;
    }
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(2, 6, 23, 0.72);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      z-index: 10000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .modal-overlay[hidden] { display: none !important; }
    .modal-dialog {
      background: linear-gradient(165deg, rgba(30, 27, 60, 0.98) 0%, rgba(17, 24, 39, 0.99) 100%);
      border: 1px solid rgba(124, 108, 240, 0.25);
      border-radius: 18px;
      padding: 26px 24px 24px;
      max-width: 420px;
      width: 100%;
      box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.05) inset,
        0 28px 56px rgba(0, 0, 0, 0.55);
    }
    .modal-danger-icon {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: linear-gradient(135deg, #b91c1c, #dc2626);
      color: #fff;
      font-size: 28px;
      font-weight: 900;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 14px;
      line-height: 1;
    }
    .modal-dialog h3 { margin: 0 0 10px; font-size: 1.05rem; text-align: center; color: #fecaca; }
    .modal-dialog p { margin: 0 0 16px; font-size: 0.9rem; color: #cbd5e1; line-height: 1.45; }
    .modal-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
    .modal-actions button { min-width: 100px; }
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
        $panelNavPage = 'liturgy_observances';
        $panelNavView = 'categories';
        $panelNavCalYear = $year;
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <?php if ($message !== null): ?><p class="msg ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="msg err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

  <div class="toolbar-row">
    <a class="btn-pill" href="/admin/liturgy_observances_edit.php">+ Новы запіс</a>
  </div>

  <div class="grid">
      <section class="bulk-panel" aria-labelledby="bulk-heading">
        <div class="bulk-fold bulk-fold--collapsed" id="bulk-main-fold">
          <div class="bulk-fold-toolbar">
            <button type="button" class="bulk-fold-toggle" id="bulk-main-toggle" aria-expanded="false" aria-controls="bulk-main-body" title="Паказаць або схаваць налады"><span class="bulk-fold-chev" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M2.75 4.25L6 7.5l3.25-3.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
            <h2 id="bulk-heading" class="bulk-fold-title">Масавая замена</h2>
          </div>
          <div id="bulk-main-body" class="bulk-fold-body" hidden>
        <form method="post" id="bulk-form" class="bulk-form" onsubmit="return false;">
          <?= panel_csrf_field() ?>
          <input type="hidden" name="bulk_filter_kind" value="<?= htmlspecialchars($filterKind, ENT_QUOTES, 'UTF-8') ?>">
          <div class="bulk-form-grid">
            <div class="bulk-field">
              <label for="bulk_find">Шукаць (фрагмент)</label>
              <input type="text" id="bulk_find" name="bulk_find" autocomplete="off" placeholder="Напрыклад: Успамін або Св.">
            </div>
            <div class="bulk-field">
              <label for="bulk_replace">Замяніць на</label>
              <input type="text" id="bulk_replace" name="bulk_replace" autocomplete="off" placeholder="Пуста — выдаліць фрагмент; напрыклад: св.">
            </div>
          </div>
          <fieldset class="bulk-options">
            <legend class="bulk-options__legend">Параметры</legend>
            <div class="bulk-chip-grid">
              <label class="bulk-chip"><input type="checkbox" name="bulk_col_title" value="1" checked><span>Загаловак (title)</span></label>
              <label class="bulk-chip"><input type="checkbox" name="bulk_col_patch" value="1"><span>patch_suffix</span></label>
              <label class="bulk-chip"><input type="checkbox" name="bulk_case_sensitive" value="1"><span>Улічваць рэгістр</span></label>
              <label class="bulk-chip"><input type="checkbox" name="bulk_scope_filtered" value="1"><span>Толькі бягучы фільтр спісу<span id="bulk-scope-kind-suffix"><?= $filterKind !== '' ? ' (' . htmlspecialchars($filterKind, ENT_QUOTES, 'UTF-8') . ')' : '' ?></span></span></label>
            </div>
          </fieldset>
        </form>

        <div class="bulk-preview-row">
          <button type="button" class="btn-bulk-preview" id="bulk-btn-preview">Прадпрагляд змен</button>
          <button type="button" class="danger" id="bulk-exec-open" disabled>Выканаць замену</button>
          <span class="bulk-spinner" id="bulk-spinner" hidden>Загрузка</span>
          <p class="bulk-status-line muted" id="bulk-status-line"></p>
        </div>

        <div id="bulk-log-section" class="bulk-log-section" hidden>
          <h3 id="bulk-log-heading" class="bulk-log-heading">Вынік прадпрагляду</h3>
          <div class="bulk-log-stack">
            <div id="bulk-change-log" class="bulk-change-log" role="log" aria-labelledby="bulk-log-heading" aria-live="polite"></div>
          </div>
        </div>
          </div>
        </div>
      </section>

    <div class="card">
      <h2 class="card__title">Спіс запісаў</h2>
      <div class="list-filter-bar" id="list-filter-shell" role="toolbar" aria-label="Фільтр спісу запісаў">
          <button type="button" class="btn-pill list-filter-btn<?= $filterKind === '' ? ' active' : '' ?>" data-kind="" aria-pressed="<?= $filterKind === '' ? 'true' : 'false' ?>">Усе</button>
          <button type="button" class="btn-pill list-filter-btn<?= $filterKind === 'important' ? ' active' : '' ?>" data-kind="important" aria-pressed="<?= $filterKind === 'important' ? 'true' : 'false' ?>">Важныя</button>
          <button type="button" class="btn-pill list-filter-btn<?= $filterKind === 'optional' ? ' active' : '' ?>" data-kind="optional" aria-pressed="<?= $filterKind === 'optional' ? 'true' : 'false' ?>">Даброўныя</button>
          <button type="button" class="btn-pill list-filter-btn<?= $filterKind === 'patch' ? ' active' : '' ?>" data-kind="patch" aria-pressed="<?= $filterKind === 'patch' ? 'true' : 'false' ?>">Дап. загаловак</button>
      </div>
      <p class="muted card__lead list-count-line">Усяго: <strong style="color:#e2e8f0;" id="list-count-num"><?= count($rows) ?></strong></p>
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
          <tbody id="obs-list-tbody">
          <?= lo_observances_table_rows_html($rows) ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div id="bulk-modal" class="modal-overlay" hidden>
    <div class="modal-dialog" role="alertdialog" aria-modal="true" aria-labelledby="bulk-modal-title">
      <div class="modal-danger-icon" aria-hidden="true">!</div>
      <h3 id="bulk-modal-title">Пацвердзіце масавую замену</h3>
      <p>Гэта дзеянне неадваротна зменіць даныя ў базе для ўсіх паказаных запісаў. Працягнуць?</p>
      <div class="modal-actions">
        <button type="button" class="btn-muted" id="bulk-modal-no">Не</button>
        <button type="button" class="danger" id="bulk-modal-yes">Так</button>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var BULK_OPEN_KEY = 'liturgy_observances_bulk_open';
    var mainFold = document.getElementById('bulk-main-fold');
    var mainBody = document.getElementById('bulk-main-body');
    var mainToggle = document.getElementById('bulk-main-toggle');

    function setMainExpanded(expanded) {
      if (!mainFold || !mainBody || !mainToggle) return;
      mainBody.hidden = !expanded;
      mainFold.classList.toggle('bulk-fold--collapsed', !expanded);
      mainToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      try {
        window.localStorage.setItem(BULK_OPEN_KEY, expanded ? '1' : '0');
      } catch (e) { /* ignore */ }
    }

    if (mainFold && mainBody && mainToggle) {
      try {
        setMainExpanded(window.localStorage.getItem(BULK_OPEN_KEY) === '1');
      } catch (e) {
        setMainExpanded(false);
      }
      mainToggle.addEventListener('click', function () {
        setMainExpanded(!!mainBody.hidden);
      });
    }

    var bulkForm = document.getElementById('bulk-form');
    var btnPreview = document.getElementById('bulk-btn-preview');
    var btnApply = document.getElementById('bulk-exec-open');
    var logEl = document.getElementById('bulk-change-log');
    var logSection = document.getElementById('bulk-log-section');
    var statusEl = document.getElementById('bulk-status-line');
    var spinnerEl = document.getElementById('bulk-spinner');
    var modal = document.getElementById('bulk-modal');
    var noBtn = document.getElementById('bulk-modal-no');
    var yesBtn = document.getElementById('bulk-modal-yes');
    if (!bulkForm || !btnPreview || !btnApply || !logEl) return;

    function setBulkLogSectionVisible(visible) {
      if (logSection) logSection.hidden = !visible;
    }

    function bulkUrlNow() {
      return window.location.pathname + window.location.search;
    }
    var lastChangeCount = 0;

    function setLoading(on) {
      btnPreview.disabled = on;
      btnApply.disabled = on || lastChangeCount === 0;
      spinnerEl.hidden = !on;
    }

    function setStatus(text, isErr) {
      statusEl.textContent = text || '';
      statusEl.classList.toggle('is-error', !!isErr);
    }

    function makeDiffBlock(label, oldV, newV) {
      var w = document.createElement('div');
      w.className = 'bulk-diff-block';
      var lb = document.createElement('span');
      lb.className = 'bulk-diff-label';
      lb.textContent = label;
      w.appendChild(lb);
      var o = document.createElement('div');
      o.className = 'diff-old';
      o.textContent = oldV;
      var n = document.createElement('div');
      n.className = 'diff-new';
      n.textContent = newV;
      w.appendChild(o);
      w.appendChild(n);
      return w;
    }

    function renderLog(rows, rowsOmitted, changeCount) {
      logEl.innerHTML = '';
      var total = typeof changeCount === 'number' ? changeCount : 0;
      lastChangeCount = total;
      if (total === 0) {
        var p0 = document.createElement('p');
        p0.className = 'bulk-log-placeholder';
        p0.textContent = 'Няма запісаў для змены пры гэтых умовах.';
        logEl.appendChild(p0);
        btnApply.disabled = true;
        return;
      }
      rows = rows || [];
      if (rows.length === 0) {
        var p1 = document.createElement('p');
        p1.className = 'bulk-log-placeholder';
        p1.textContent = 'Змены ёсць, але спіс для адлюстравання пусты — абнавіце старонку.';
        logEl.appendChild(p1);
        btnApply.disabled = false;
        return;
      }
      rows.forEach(function (r) {
        var item = document.createElement('div');
        item.className = 'bulk-log-item';
        var idEl = document.createElement('div');
        idEl.className = 'bulk-log-id';
        idEl.appendChild(document.createTextNode('Запіс '));
        var idCode = document.createElement('code');
        idCode.textContent = String(r.id);
        idEl.appendChild(idCode);
        item.appendChild(idEl);
        if (r.touch_title) {
          item.appendChild(makeDiffBlock('Загаловак', r.title_old, r.title_new));
        }
        if (r.touch_patch) {
          item.appendChild(makeDiffBlock('patch_suffix', r.patch_old, r.patch_new));
        }
        logEl.appendChild(item);
      });
      var omitted = Number(rowsOmitted) || 0;
      if (omitted > 0) {
        var more = document.createElement('div');
        more.className = 'bulk-log-more';
        more.textContent = '… і яшчэ ' + omitted + ' запіс(аў) (паказаны не ўсе ў логу; у базе будуць абноўленыя ўсе).';
        logEl.appendChild(more);
      }
      btnApply.disabled = false;
    }

    function placeholderLog() {
      logEl.innerHTML = '';
      lastChangeCount = 0;
      btnApply.disabled = true;
      setBulkLogSectionVisible(false);
    }

    function buildBody(extra) {
      var fd = new FormData(bulkForm);
      fd.set('bulk_ajax', '1');
      Object.keys(extra).forEach(function (k) {
        fd.set(k, extra[k]);
      });
      return fd;
    }

    function bulkFetch(extra) {
      return fetch(bulkUrlNow(), {
        method: 'POST',
        body: buildBody(extra),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (res) {
        return res.json().catch(function () {
          throw new Error('Некарэктны адказ сервера.');
        });
      });
    }

    btnPreview.addEventListener('click', function () {
      setStatus('');
      setBulkLogSectionVisible(false);
      setLoading(true);
      bulkFetch({ bulk_preview: '1' })
        .then(function (data) {
          if (!data.ok) {
            setStatus(data.error || 'Памылка', true);
            lastChangeCount = 0;
            btnApply.disabled = true;
            setBulkLogSectionVisible(false);
            return;
          }
          setStatus(data.message || '');
          renderLog(data.rows || [], data.rows_omitted, data.change_count);
          setBulkLogSectionVisible(true);
        })
        .catch(function (e) {
          setStatus(e.message || 'Памылка сеткі', true);
          btnApply.disabled = true;
          setBulkLogSectionVisible(false);
        })
        .finally(function () {
          setLoading(false);
        });
    });

    function closeModal() {
      modal.hidden = true;
      document.body.style.overflow = '';
    }
    function openModal() {
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      noBtn.focus();
    }

    btnApply.addEventListener('click', function () {
      if (btnApply.disabled || lastChangeCount === 0) return;
      openModal();
    });

    noBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    yesBtn.addEventListener('click', function () {
      closeModal();
      setStatus('');
      setLoading(true);
      bulkFetch({ bulk_execute: '1' })
        .then(function (data) {
          if (!data.ok) {
            setStatus(data.error || 'Памылка', true);
            return;
          }
          setStatus(data.message || 'Зроблена.');
          placeholderLog();
          window.setTimeout(function () {
            window.location.reload();
          }, 500);
        })
        .catch(function (e) {
          setStatus(e.message || 'Памылка сеткі', true);
        })
        .finally(function () {
          setLoading(false);
        });
    });
  })();

  (function () {
    var shell = document.getElementById('list-filter-shell');
    var tbody = document.getElementById('obs-list-tbody');
    var countEl = document.getElementById('list-count-num');
    var bulkKindInput = document.querySelector('#bulk-form [name="bulk_filter_kind"]');
    var scopeSuffix = document.getElementById('bulk-scope-kind-suffix');
    if (!shell || !tbody) return;

    function getCsrf() {
      var el = document.querySelector('#bulk-form input[name="csrf_token"]');
      return el ? el.value : '';
    }

    function syncBulkFilterHidden(kind) {
      if (bulkKindInput) bulkKindInput.value = kind;
      if (scopeSuffix) scopeSuffix.textContent = kind === '' ? '' : ' (' + kind + ')';
    }

    function setActiveButton(kind) {
      shell.querySelectorAll('.list-filter-btn').forEach(function (btn) {
        var k = btn.getAttribute('data-kind') || '';
        var on = k === kind;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    function pushUrl(kind) {
      var url = new URL(window.location.href);
      if (kind === '') {
        url.searchParams.delete('kind');
      } else {
        url.searchParams.set('kind', kind);
      }
      var qs = url.searchParams.toString();
      history.pushState({ listKind: kind }, '', url.pathname + (qs ? '?' + qs : '') + url.hash);
    }

    function loadList(kind) {
      var fd = new FormData();
      fd.set('csrf_token', getCsrf());
      fd.set('list_ajax', '1');
      fd.set('list_kind', kind);
      tbody.setAttribute('aria-busy', 'true');
      return fetch(window.location.pathname, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          tbody.removeAttribute('aria-busy');
          if (!data.ok) {
            window.alert(data.error || 'Памылка');
            return;
          }
          tbody.innerHTML = data.tbody_html || '';
          if (countEl) countEl.textContent = String(data.count);
          syncBulkFilterHidden(data.filter_kind != null ? data.filter_kind : kind);
        })
        .catch(function () {
          tbody.removeAttribute('aria-busy');
          window.alert('Памылка сеткі');
        });
    }

    shell.querySelectorAll('.list-filter-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var kind = btn.getAttribute('data-kind') || '';
        if (btn.classList.contains('active')) return;
        setActiveButton(kind);
        pushUrl(kind);
        loadList(kind);
      });
    });

    window.addEventListener('popstate', function () {
      var params = new URLSearchParams(window.location.search);
      var k = params.get('kind') || '';
      if (!['', 'important', 'optional', 'patch'].includes(k)) k = '';
      setActiveButton(k);
      loadList(k);
    });
  })();
  </script>
</body>
</html>
