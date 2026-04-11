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
panel_require_section_get('lectionary');

$colorLabels = [
    'green' => 'зялёны',
    'red' => 'чырвоны',
    'purple' => 'фіялетавы',
    'white' => 'белы',
    'rose' => 'ружовы',
    'black' => 'чорны',
];

$message = null;
$error = null;
$isAjaxRequest = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
    || (($_POST['ajax'] ?? '') === '1');

function lectionary_ajax_response(bool $ok, string $messageText = '', string $errorText = '', string $redirectUrl = ''): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $messageText,
        'error' => $errorText,
        'redirect' => $redirectUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lec_bulk_replace(string $text, string $find, string $replace, bool $caseSensitive): string
{
    if ($find === '') {
        return $text;
    }
    if ($caseSensitive) {
        return str_replace($find, $replace, $text);
    }
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
function lec_bulk_load_rows(bool $scopeFiltered, string $searchQ): array
{
    $searchQ = trim($searchQ);
    if ($scopeFiltered && $searchQ !== '') {
        $like = '%' . $searchQ . '%';
        $stmt = db()->prepare(
            'SELECT id, title, text_html, lookup_key
             FROM liturgy_lectionary_entries
             WHERE is_active = 1
               AND (title LIKE :q OR lookup_key LIKE :q2)
             ORDER BY id ASC'
        );
        $stmt->execute([':q' => $like, ':q2' => $like]);

        return $stmt->fetchAll();
    }
    $stmt = db()->query(
        'SELECT id, title, text_html, lookup_key
         FROM liturgy_lectionary_entries
         WHERE is_active = 1
         ORDER BY id ASC'
    );

    return $stmt->fetchAll();
}

/**
 * @return array{error: string|null, changes: list<array<string, mixed>>}
 */
function lec_bulk_compute(
    string $find,
    string $replace,
    bool $caseSensitive,
    bool $useTitle,
    bool $useText,
    bool $scopeFiltered,
    string $searchQ
): array {
    if ($find === '') {
        return ['error' => 'Увядзіце тэкст для пошуку.', 'changes' => []];
    }
    if (!$useTitle && !$useText) {
        return ['error' => 'Абярыце хаця б адно поле: назва і/або тэкст (HTML).', 'changes' => []];
    }
    $scan = lec_bulk_load_rows($scopeFiltered, $searchQ);
    $changes = [];
    foreach ($scan as $r) {
        $id = (int)$r['id'];
        $title = (string)($r['title'] ?? '');
        $textHtml = (string)($r['text_html'] ?? '');
        $nt = $useTitle ? lec_bulk_replace($title, $find, $replace, $caseSensitive) : $title;
        $nh = $useText ? lec_bulk_replace($textHtml, $find, $replace, $caseSensitive) : $textHtml;
        if ($nt !== $title || $nh !== $textHtml) {
            $changes[] = [
                'id' => $id,
                'title_old' => $title,
                'title_new' => $nt,
                'text_old' => $textHtml,
                'text_new' => $nh,
                'touch_title' => $useTitle && $nt !== $title,
                'touch_text' => $useText && $nh !== $textHtml,
            ];
        }
    }

    return ['error' => null, 'changes' => $changes];
}

/**
 * @param list<array<string, mixed>> $changes
 */
function lec_bulk_validate_final_lookup_keys_global(array $changes): ?string
{
    $stmt = db()->query('SELECT id, title FROM liturgy_lectionary_entries WHERE is_active = 1 ORDER BY id ASC');
    $titleById = [];
    while (is_array($r = $stmt->fetch())) {
        $titleById[(int)$r['id']] = (string)($r['title'] ?? '');
    }
    foreach ($changes as $ch) {
        $titleById[(int)$ch['id']] = (string)($ch['title_new'] ?? '');
    }
    $keyToIds = [];
    foreach ($titleById as $id => $t) {
        $k = liturgy_normalize_lectionary_key($t);
        if ($k === '') {
            return 'Пасля замены запіс id=' . $id . ' атрымлівае пусты ключ назвы.';
        }
        if (!isset($keyToIds[$k])) {
            $keyToIds[$k] = [];
        }
        $keyToIds[$k][] = $id;
    }
    foreach ($keyToIds as $k => $ids) {
        if (count($ids) > 1) {
            return 'Ключ назвы «' . $k . '» супадзе для запісаў: ' . implode(', ', $ids) . '. Зменіце шаблон замены.';
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $changes
 * @return list<array<string, mixed>>
 */
function lec_bulk_json_rows_for_preview(array $changes, int $maxRows, int $maxChars): array
{
    $slice = array_slice($changes, 0, $maxRows);
    $out = [];
    foreach ($slice as $ch) {
        $row = [
            'id' => (int)$ch['id'],
            'touch_title' => (bool)$ch['touch_title'],
            'touch_text' => (bool)$ch['touch_text'],
        ];
        foreach (['title_old', 'title_new'] as $k) {
            $s = (string)($ch[$k] ?? '');
            if (mb_strlen($s) > $maxChars) {
                $row[$k] = mb_substr($s, 0, $maxChars) . '…';
            } else {
                $row[$k] = $s;
            }
        }
        foreach (['text_old', 'text_new'] as $k) {
            $s = strip_tags((string)($ch[$k] ?? ''));
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
        $useText = isset($_POST['bulk_col_text']);
        $scopeFiltered = isset($_POST['bulk_scope_filtered']);
        $searchQ = trim((string)($_POST['bulk_search_q'] ?? ''));
        $computed = lec_bulk_compute($find, $replace, $caseSensitive, $useTitle, $useText, $scopeFiltered, $searchQ);
        if ($computed['error'] !== null) {
            echo json_encode(['ok' => false, 'error' => $computed['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $changes = $computed['changes'];
        if ($changes !== []) {
            $keyErr = lec_bulk_validate_final_lookup_keys_global($changes);
            if ($keyErr !== null) {
                echo json_encode(['ok' => false, 'error' => $keyErr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
        if (isset($_POST['bulk_execute']) && $changes === []) {
            echo json_encode(['ok' => false, 'error' => 'Няма змен для захавання. Спачатку зрабіце прадпрагляд.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (isset($_POST['bulk_preview'])) {
            $maxRows = 500;
            $maxChars = 600;
            $rowsJson = lec_bulk_json_rows_for_preview($changes, $maxRows, $maxChars);
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
                'UPDATE liturgy_lectionary_entries
                 SET title = :title,
                     lookup_key = :lookup_key,
                     text_html = :text_html
                 WHERE id = :id AND is_active = 1'
            );
            $n = 0;
            foreach ($changes as $ch) {
                $lk = liturgy_normalize_lectionary_key((string)$ch['title_new']);
                $upd->execute([
                    ':title' => $ch['title_new'],
                    ':lookup_key' => $lk,
                    ':text_html' => $ch['text_new'],
                    ':id' => $ch['id'],
                ]);
                $n++;
            }
            $pdo->commit();
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
    $ajaxRedirect = '';
    if (!panel_csrf_token_valid()) {
        $error = 'Сесія пратэрмінаваная або токен несапраўдны. Абнавіце старонку.';
    } else {
        $editId = (int)($_POST['lectionary_id'] ?? 0);
        $isDelete = isset($_POST['delete_lectionary_entry']);
        if ($isDelete) {
            if ($editId <= 0) {
                $error = 'Няма запісу для выдалення.';
            } else {
                try {
                    $stmt = db()->prepare('DELETE FROM liturgy_lectionary_entries WHERE id = :id');
                    $stmt->execute([':id' => $editId]);
                    $message = 'Запіс лекцыянарыя выдалены.';
                    $ajaxRedirect = '/admin/lectionary.php';
                } catch (Throwable $e) {
                    $error = 'Памылка выдалення: ' . $e->getMessage();
                }
            }
        } elseif (isset($_POST['save_lectionary_entry'])) {
            $title = trim((string)($_POST['lectionary_title'] ?? ''));
            $textHtml = trim((string)($_POST['lectionary_text_html'] ?? ''));
            $liturgicalColorRaw = trim((string)($_POST['lectionary_liturgical_color'] ?? ''));
            $liturgicalColor = liturgy_normalize_liturgical_color_string($liturgicalColorRaw);
            $lookupKey = liturgy_normalize_lectionary_key($title);

            if ($title === '' || $textHtml === '') {
                $error = 'Патрэбныя абавязковыя палі: назва і тэкст.';
            } elseif ($lookupKey === '') {
                $error = 'Назва павінна мець хоць адзін сімвал.';
            } else {
                try {
                    if ($editId > 0) {
                        $stmt = db()->prepare(
                            'UPDATE liturgy_lectionary_entries
                             SET title = :title,
                                 lookup_key = :lookup_key,
                                 text_html = :text_html,
                                 liturgical_color = :liturgical_color,
                                 is_active = 1
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            ':id' => $editId,
                            ':title' => $title,
                            ':lookup_key' => $lookupKey,
                            ':text_html' => $textHtml,
                            ':liturgical_color' => $liturgicalColor,
                        ]);
                        $ajaxRedirect = '/admin/lectionary.php?edit_id=' . $editId;
                    } else {
                        $stmt = db()->prepare(
                            'INSERT INTO liturgy_lectionary_entries (title, lookup_key, text_html, liturgical_color, is_active)
                             VALUES (:title, :lookup_key, :text_html, :liturgical_color, 1)
                             ON DUPLICATE KEY UPDATE
                                title = VALUES(title),
                                text_html = VALUES(text_html),
                                liturgical_color = VALUES(liturgical_color),
                                is_active = 1'
                        );
                        $stmt->execute([
                            ':title' => $title,
                            ':lookup_key' => $lookupKey,
                            ':text_html' => $textHtml,
                            ':liturgical_color' => $liturgicalColor,
                        ]);
                        $stmtSaved = db()->prepare(
                            'SELECT id
                             FROM liturgy_lectionary_entries
                             WHERE lookup_key = :lookup_key
                             LIMIT 1'
                        );
                        $stmtSaved->execute([':lookup_key' => $lookupKey]);
                        $savedId = (int)($stmtSaved->fetch()['id'] ?? 0);
                        if ($savedId > 0) {
                            $ajaxRedirect = '/admin/lectionary.php?edit_id=' . $savedId;
                        }
                    }
                    $message = 'Запіс лекцыянарыя захаваны.';
                } catch (Throwable $e) {
                    $error = 'Памылка захавання: ' . $e->getMessage();
                }
            }
        }
    }
    if ($isAjaxRequest && !isset($_POST['logout'])) {
        lectionary_ajax_response($error === null, $message ?? '', $error ?? '', $ajaxRedirect);
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$editId = (int)($_GET['edit_id'] ?? ($_POST['lectionary_id'] ?? 0));
$prefillTitle = trim((string)($_GET['prefill_title'] ?? ''));
if ($editId <= 0 && $prefillTitle !== '') {
    $resolvedPrefillId = liturgy_resolve_lectionary_edit_id_for_prefill($prefillTitle);
    if ($resolvedPrefillId > 0) {
        $editId = $resolvedPrefillId;
    }
}

if ($search !== '') {
    $stmtList = db()->prepare(
        'SELECT id, title, lookup_key, updated_at
         FROM liturgy_lectionary_entries
         WHERE is_active = 1
           AND (title LIKE :q OR lookup_key LIKE :q)
         ORDER BY title ASC'
    );
    $stmtList->execute([':q' => '%' . $search . '%']);
} else {
    $stmtList = db()->query(
        'SELECT id, title, lookup_key, updated_at
         FROM liturgy_lectionary_entries
         WHERE is_active = 1
         ORDER BY title ASC'
    );
}
$entries = $stmtList->fetchAll();

$editEntry = null;
if ($editId > 0) {
    $stmtEdit = db()->prepare(
        'SELECT id, title, lookup_key, text_html, liturgical_color, updated_at
         FROM liturgy_lectionary_entries
         WHERE id = :id
         LIMIT 1'
    );
    $stmtEdit->execute([':id' => $editId]);
    $row = $stmtEdit->fetch();
    if (is_array($row)) {
        $editEntry = $row;
    }
}

$currentTitle = is_array($editEntry) ? (string)($editEntry['title'] ?? '') : '';
$currentText = is_array($editEntry) ? (string)($editEntry['text_html'] ?? '') : '';
$currentLiturgicalColor = is_array($editEntry)
    ? liturgy_normalize_liturgical_color_string((string)($editEntry['liturgical_color'] ?? ''))
    : '';
if ($currentTitle === '' && $prefillTitle !== '') {
    $currentTitle = $prefillTitle;
}

$obsFilterApplied = isset($_GET['obs_filter']);
$obsYear = (int)($_GET['obs_year'] ?? date('Y'));
if ($obsYear < 1970 || $obsYear > 2100) {
    $obsYear = (int)date('Y');
}
$obsPeriod = trim((string)($_GET['obs_period'] ?? 'one'));
if (!in_array($obsPeriod, ['one', 'range', 'all'], true)) {
    $obsPeriod = 'one';
}
$obsYearFrom = (int)($_GET['obs_year_from'] ?? $obsYear);
$obsYearTo = (int)($_GET['obs_year_to'] ?? $obsYear);
if ($obsYearFrom < 1970 || $obsYearFrom > 2100) {
    $obsYearFrom = $obsYear;
}
if ($obsYearTo < 1970 || $obsYearTo > 2100) {
    $obsYearTo = $obsYear;
}
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
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Лекцыянарый — Totus Tuus</title>
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
    .grid { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 14px; }
    .card { background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 14px; overflow: hidden; }
    .table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .table th, .table td { border-bottom: 1px solid #273449; padding: 7px 6px; text-align: left; vertical-align: top; }
    .table tr:last-child td { border-bottom: none; }
    .muted { color: #94a3b8; font-size: 13px; }
    label { display: block; margin-top: 10px; margin-bottom: 4px; font-size: 13px; color: #cbd5e1; font-weight: 600; }
    input[type="text"], input[type="search"], input[type="number"], select, textarea {
      width: 100%;
      border: 1px solid #334155;
      background: #0f172a;
      color: #e2e8f0;
      border-radius: 10px;
      padding: 10px 11px;
      font: inherit;
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
    option { background: #111827; color: #e2e8f0; }
    .search-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: end; margin-bottom: 10px; }
    button { border: 1px solid #334155; background: #7c6cf0; color: #fff; font-weight: 700; border-radius: 10px; padding: 10px 12px; cursor: pointer; }
    .danger { background: #7f1d1d; border-color: #b91c1c; }
    .actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
    .rich-editor-wrap {
      border: 1px solid #334155;
      border-radius: 10px;
      background: #0b1224;
      overflow: hidden;
    }
    .rich-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      padding: 8px;
      border-bottom: 1px solid #334155;
      background: #0f172a;
    }
    .rich-toolbar-group { display: flex; align-items: center; gap: 6px; }
    .rich-toolbar-label { color: #94a3b8; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
    .rich-btn {
      border: 1px solid #334155;
      background: #1e293b;
      color: #e2e8f0;
      border-radius: 8px;
      padding: 6px 9px;
      line-height: 1;
      font-size: 13px;
      font-weight: 700;
    }
    .rich-color-picker-wrap { position: relative; }
    .rich-color-toggle {
      width: 26px;
      height: 26px;
      border-radius: 8px;
      border: 1px solid #475569;
      padding: 0;
      background: #fff;
    }
    .rich-color-dropdown {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      display: none;
      grid-template-columns: repeat(6, 20px);
      gap: 6px;
      padding: 8px;
      border-radius: 10px;
      border: 1px solid #334155;
      background: #0b1224;
      z-index: 20;
      width: max-content;
    }
    .rich-color-picker-wrap.open .rich-color-dropdown { display: grid; }
    .rich-color-swatch {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.35);
      padding: 0;
      cursor: pointer;
    }
    .rich-color-swatch.active { outline: 2px solid #f8fafc; outline-offset: 1px; }
    .rich-editor {
      min-height: 420px;
      padding: 12px;
      color: #e2e8f0;
      background: #0b1224;
      outline: none;
      white-space: normal;
      line-height: 1.3;
    }
    .rich-editor p {
      margin: 0 0 0.35em;
    }
    .rich-editor p:last-child {
      margin-bottom: 0;
    }
    .rich-editor-hidden { display: none; }
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
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
    }
    @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
    .lec-bulk-span { grid-column: 1 / -1; }
    .diff-old {
      color: #fecaca;
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
    .bulk-panel { margin-top: 0; padding-top: 0; border-top: none; }
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
    .bulk-fold:not(.bulk-fold--collapsed) .bulk-fold-toolbar { border-bottom-color: var(--line); }
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
    }
    button.bulk-fold-toggle:focus-visible {
      outline: 2px solid rgba(124, 108, 240, 0.55);
      outline-offset: 2px;
    }
    .bulk-fold--collapsed .bulk-fold-toggle .bulk-fold-chev { transform: rotate(-90deg); }
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
    .bulk-fold-chev svg { display: block; width: 12px; height: 12px; }
    .bulk-fold-title {
      margin: 0;
      flex: 1 1 140px;
      font-size: 0.9375rem;
      font-weight: 700;
      color: #e2e8f0;
    }
    .bulk-fold-body { padding: 12px 14px 14px; }
    .bulk-fold-body[hidden] { display: none !important; }
    .bulk-log-section { margin-top: 12px; }
    .bulk-log-section[hidden] { display: none !important; }
    .bulk-log-heading { margin: 0 0 8px; font-size: 0.875rem; font-weight: 600; color: #e2e8f0; }
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
    .btn-bulk-preview:disabled { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
    .bulk-status-line { font-size: 13px; flex: 1 1 220px; line-height: 1.45; min-height: 1.4em; margin: 0; }
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
    .bulk-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; align-items: start; }
    .bulk-form-grid .bulk-field input[type="text"] {
      min-height: 0;
      padding: 9px 11px;
      border-radius: 10px;
    }
    @media (max-width: 640px) { .bulk-form-grid { grid-template-columns: 1fr; } }
    .bulk-field label { margin-top: 0; }
    .bulk-options { border: none; padding: 0; margin: 10px 0 0; }
    .bulk-options__legend {
      font-size: 13px;
      text-transform: none;
      letter-spacing: normal;
      color: #cbd5e1;
      font-weight: 600;
      margin: 0 0 8px;
      padding: 0;
    }
    .bulk-chip-grid { display: flex; flex-wrap: wrap; gap: 6px 8px; }
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
    }
    label.bulk-chip:hover { border-color: rgba(124, 108, 240, 0.35); background: rgba(124, 108, 240, 0.06); }
    label.bulk-chip:has(input:checked) {
      border-color: rgba(196, 163, 90, 0.35);
      background: rgba(124, 108, 240, 0.12);
    }
    label.bulk-chip input { width: 15px; height: 15px; accent-color: #7c6cf0; cursor: pointer; }
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
      max-height: min(360px, 52vh);
      scrollbar-gutter: stable;
    }
    .bulk-change-log .bulk-log-placeholder { margin: 0; color: #64748b; padding: 6px 4px; }
    .bulk-log-item {
      padding: 10px 12px;
      border-radius: 8px;
      background: rgba(30, 41, 59, 0.4);
      border: 1px solid rgba(51, 65, 85, 0.5);
    }
    .bulk-log-id { font-weight: 700; color: #e2e8f0; margin-bottom: 8px; font-size: 12px; }
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
      box-shadow: 0 28px 56px rgba(0, 0, 0, 0.55);
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
    }
    .modal-dialog h3 { margin: 0 0 10px; font-size: 1.05rem; text-align: center; color: #fecaca; }
    .modal-dialog p { margin: 0 0 16px; font-size: 0.9rem; color: #cbd5e1; line-height: 1.45; }
    .modal-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
    .modal-actions button { min-width: 100px; }
    .btn-muted {
      border: 1px solid #334155;
      background: #1e293b;
      color: #e2e8f0;
      font-weight: 600;
      border-radius: 10px;
      padding: 10px 16px;
      cursor: pointer;
      font-family: inherit;
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
        $panelNavPage = 'lectionary';
        $panelNavView = 'categories';
        $panelNavCalYear = $obsYear;
        require __DIR__ . '/../includes/panel_admin_nav.php';
        ?>
  </div>

  <div class="toolbar-row">
    <a class="btn-pill" href="/admin/lectionary_observances_gap.php">Святы з БД без чытанняў</a>
  </div>

  <div class="grid">
    <section class="bulk-panel lec-bulk-span" aria-labelledby="lec-bulk-heading">
      <div class="bulk-fold bulk-fold--collapsed" id="lec-bulk-main-fold">
        <div class="bulk-fold-toolbar">
          <button type="button" class="bulk-fold-toggle" id="lec-bulk-main-toggle" aria-expanded="false" aria-controls="lec-bulk-main-body" title="Паказаць або схаваць налады"><span class="bulk-fold-chev" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.75 4.25L6 7.5l3.25-3.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
          <h2 id="lec-bulk-heading" class="bulk-fold-title">Масавая замена</h2>
        </div>
        <div id="lec-bulk-main-body" class="bulk-fold-body" hidden>
          <form method="post" id="lec-bulk-form" class="bulk-form" onsubmit="return false;">
            <?= panel_csrf_field() ?>
            <input type="hidden" name="bulk_search_q" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="bulk-form-grid">
              <div class="bulk-field">
                <label for="lec_bulk_find">Шукаць (фрагмент)</label>
                <input type="text" id="lec_bulk_find" name="bulk_find" autocomplete="off" placeholder="Напрыклад: частка назвы">
              </div>
              <div class="bulk-field">
                <label for="lec_bulk_replace">Замяніць на</label>
                <input type="text" id="lec_bulk_replace" name="bulk_replace" autocomplete="off" placeholder="Пуста — выдаліць фрагмент">
              </div>
            </div>
            <fieldset class="bulk-options">
              <legend class="bulk-options__legend">Параметры</legend>
              <div class="bulk-chip-grid">
                <label class="bulk-chip"><input type="checkbox" name="bulk_col_title" value="1" checked><span>Назва (title)</span></label>
                <label class="bulk-chip"><input type="checkbox" name="bulk_col_text" value="1"><span>Тэкст (HTML)</span></label>
                <label class="bulk-chip"><input type="checkbox" name="bulk_case_sensitive" value="1"><span>Улічваць рэгістр</span></label>
                <label class="bulk-chip"><input type="checkbox" name="bulk_scope_filtered" value="1" <?= $search !== '' ? 'checked' : '' ?>><span>Толькі вынікі бягучага пошуку<?= $search !== '' ? ' («' . htmlspecialchars(mb_substr($search, 0, 40), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . (mb_strlen($search) > 40 ? '…' : '') . '»)' : '' ?></span></label>
              </div>
            </fieldset>
          </form>
          <div class="bulk-preview-row">
            <button type="button" class="btn-bulk-preview" id="lec-bulk-btn-preview">Прадпрагляд змен</button>
            <button type="button" class="danger" id="lec-bulk-exec-open" disabled>Выканаць замену</button>
            <span class="bulk-spinner" id="lec-bulk-spinner" hidden>Загрузка</span>
            <p class="bulk-status-line muted" id="lec-bulk-status-line"></p>
          </div>
          <div id="lec-bulk-log-section" class="bulk-log-section" hidden>
            <h3 class="bulk-log-heading">Вынік прадпрагляду</h3>
            <div id="lec-bulk-change-log" class="bulk-change-log" role="log" aria-live="polite"></div>
          </div>
        </div>
      </div>
    </section>

    <div class="card">
      <h2 style="margin:0 0 8px; font-size:1rem;">Запісы лекцыянарыя</h2>
      <p class="muted" style="margin-top:0;">Падбор у каляндары ідзе па назве дня і, калі ёсць, па назве «успаміну». Кнопка «Святы з БД без чытанняў» вышэй — спіс optional-свят без непустога тэксту ў лекцыянарыі (варынт «альбо» па ключы назвы).</p>
      <form method="get" class="search-row">
        <?php if ($obsHideNonDiocesan): ?>
          <input type="hidden" name="obs_hide_general" value="1">
        <?php endif; ?>
        <?php if ($obsFilterApplied): ?>
          <input type="hidden" name="obs_filter" value="1">
          <input type="hidden" name="obs_period" value="<?= htmlspecialchars($obsPeriod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="obs_year" value="<?= (int)$obsYear ?>">
          <input type="hidden" name="obs_year_from" value="<?= (int)$obsYearFrom ?>">
          <input type="hidden" name="obs_year_to" value="<?= (int)$obsYearTo ?>">
          <?php foreach (liturgy_diocese_keys() as $dk): ?>
            <?php if (!empty($dioceseOpts[$dk])): ?>
              <input type="hidden" name="d[<?= htmlspecialchars($dk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]" value="1">
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
        <div>
          <label for="q" style="margin-top:0;">Пошук</label>
          <input id="q" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Частка назвы">
        </div>
        <div>
          <button type="submit">Знайсці</button>
        </div>
      </form>
      <table class="table">
        <thead>
        <tr>
          <th style="width:72px;">ID</th>
          <th>Назва</th>
          <th style="width:96px;"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $row): ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td>
              <strong><?= htmlspecialchars((string)$row['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
              <div class="muted"><code><?= htmlspecialchars((string)$row['lookup_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></div>
            </td>
            <td><a class="btn-pill" href="/admin/lectionary.php?edit_id=<?= (int)$row['id'] ?>">Адкрыць</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
          <tr><td colspan="3" class="muted">Нічога не знойдзена.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2 style="margin:0 0 8px; font-size:1rem;"><?= $editEntry !== null ? 'Рэдагаванне запісу' : 'Новы запіс' ?></h2>
      <form method="post" id="lectionary-form" class="js-ajax-form" data-refresh="1">
        <?= panel_csrf_field() ?>
        <input type="hidden" name="lectionary_id" value="<?= (int)($editEntry['id'] ?? 0) ?>">

        <label for="lectionary_title">Назва *</label>
        <input id="lectionary_title" type="text" name="lectionary_title" value="<?= htmlspecialchars($currentTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
        <p class="muted" style="margin:6px 0 0;">
          Для прывязкі да канкрэтнай даты (як «альбо» у дадатку) выкарыстоўвайце фармат:
          <strong>ДД.ММ - Назва свята</strong> (напрыклад, <strong>14.09 - Успамін ...</strong>).
        </p>

        <label for="lectionary_liturgical_color">Колер літургічнага дня</label>
        <select id="lectionary_liturgical_color" name="lectionary_liturgical_color">
          <option value="" <?= $currentLiturgicalColor === '' ? 'selected' : '' ?>>Сістэмны (па сезоне / календары)</option>
          <?php foreach (['green', 'red', 'purple', 'white', 'rose', 'black'] as $c): ?>
            <option value="<?= htmlspecialchars($c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $currentLiturgicalColor === $c ? 'selected' : '' ?>><?= htmlspecialchars((string)($colorLabels[$c] ?? $c), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <p class="muted" style="margin:6px 0 0;">Ужываецца, калі гэты запіс дае чытанні для дня: мае прыярытэт над аўтакалерам, але саступае падмене колеру ў «Календары».</p>

        <label for="lectionary_text_html">Тэкст чытання (HTML) *</label>
        <div class="rich-editor-wrap">
          <div class="rich-toolbar">
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="bold"><b>B</b></button>
              <button type="button" class="rich-btn" data-cmd="italic"><i>I</i></button>
              <button type="button" class="rich-btn" data-cmd="underline"><u>U</u></button>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="insertUnorderedList">• List</button>
              <button type="button" class="rich-btn" data-cmd="insertOrderedList">1. List</button>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="justifyLeft" title="Па левым краі">L</button>
              <button type="button" class="rich-btn" data-cmd="justifyCenter" title="Па цэнтры">C</button>
              <button type="button" class="rich-btn" data-cmd="justifyRight" title="Па правым краі">R</button>
              <button type="button" class="rich-btn" data-cmd="justifyFull" title="Па шырыні">J</button>
            </div>
            <div class="rich-toolbar-group" title="Колер тэксту">
              <span class="rich-toolbar-label">Колер</span>
              <div class="rich-color-picker-wrap">
                <button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;"></button>
                <div class="rich-color-dropdown" role="group" aria-label="Колер тэксту">
                  <button type="button" class="rich-color-swatch" data-color="#000000" style="background:#000000;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#374151" style="background:#374151;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#6b7280" style="background:#6b7280;"></button>
                  <button type="button" class="rich-color-swatch rich-color-swatch--white active" data-color="#ffffff" style="background:#ffffff;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#b91c1c" style="background:#b91c1c;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#ef4444" style="background:#ef4444;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#f97316" style="background:#f97316;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#eab308" style="background:#eab308;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#22c55e" style="background:#22c55e;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#14b8a6" style="background:#14b8a6;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#2563eb" style="background:#2563eb;"></button>
                  <button type="button" class="rich-color-swatch" data-color="#9333ea" style="background:#9333ea;"></button>
                </div>
              </div>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="formatBlock" data-value="h3">Загаловак</button>
              <button type="button" class="rich-btn" data-action="clear-font">Скінуць шрыфт</button>
              <button type="button" class="rich-btn" data-cmd="removeFormat">Ачысціць</button>
            </div>
          </div>
          <div id="lectionary_text_html_editor" class="rich-editor js-rich-editor" data-target-id="lectionary_text_html" data-initial-html="<?= htmlspecialchars((string)base64_encode($currentText), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" contenteditable="true"></div>
        </div>
        <textarea id="lectionary_text_html" class="rich-editor-hidden" name="lectionary_text_html" required><?= htmlspecialchars($currentText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

        <div class="actions">
          <button type="submit" name="save_lectionary_entry" value="1">Захаваць</button>
          <?php if ($editEntry !== null): ?>
            <button type="submit" class="danger" name="delete_lectionary_entry" value="1" onclick="return confirm('Выдаліць гэты запіс?')">Выдаліць</button>
            <a href="/admin/lectionary.php" class="btn-pill">Новы запіс</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div id="lec-bulk-modal" class="modal-overlay" hidden>
    <div class="modal-dialog" role="alertdialog" aria-modal="true" aria-labelledby="lec-bulk-modal-title">
      <div class="modal-danger-icon" aria-hidden="true">!</div>
      <h3 id="lec-bulk-modal-title">Пацвердзіце масавую замену</h3>
      <p>Гэта дзеянне зменіць даныя ў базе для ўсіх паказаных запісаў. Працягнуць?</p>
      <div class="modal-actions">
        <button type="button" class="btn-muted" id="lec-bulk-modal-no">Не</button>
        <button type="button" class="danger" id="lec-bulk-modal-yes">Так</button>
      </div>
    </div>
  </div>

  <div id="toast-wrap" class="toast-wrap"></div>
  <script>
    var initialMessage = <?= json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var initialError = <?= json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function showToast(type, text) {
      var wrap = document.getElementById('toast-wrap');
      if (!wrap || !text) return;
      var el = document.createElement('div');
      el.className = 'toast ' + (type === 'ok' ? 'ok' : 'err');
      el.textContent = text;
      wrap.appendChild(el);
      window.setTimeout(function () {
        if (el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }, 4200);
    }

    async function refreshLectionaryView(targetUrl) {
      var fetchUrl = targetUrl || (window.location.pathname + window.location.search);
      var response = await fetch(fetchUrl, { credentials: 'same-origin' });
      if (!response.ok) {
        throw new Error('refresh_failed');
      }
      var html = await response.text();
      var parser = new DOMParser();
      var doc = parser.parseFromString(html, 'text/html');
      var nextGrid = doc.querySelector('.grid');
      var currentGrid = document.querySelector('.grid');
      if (!nextGrid || !currentGrid || !currentGrid.parentNode) {
        throw new Error('grid_not_found');
      }
      currentGrid.parentNode.replaceChild(nextGrid, currentGrid);
      if (targetUrl) {
        history.replaceState(null, '', targetUrl);
      }
      initRichEditors();
      bindLectionaryForm();
    }

    function decodeBase64Unicode(value) {
      if (!value) return '';
      try {
        return decodeURIComponent(Array.prototype.map.call(atob(value), function (c) {
          return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
      } catch (e) {
        return '';
      }
    }

    function initRichEditors() {
      document.querySelectorAll('.js-rich-editor').forEach(function (editor) {
        if (editor.dataset.editorBound === '1') return;
        editor.dataset.editorBound = '1';
        var targetId = editor.getAttribute('data-target-id');
        if (!targetId) return;
        var hiddenField = document.getElementById(targetId);
        if (!hiddenField) return;
        var initialEncoded = editor.getAttribute('data-initial-html');
        if (initialEncoded && editor.innerHTML.trim() === '') {
          editor.innerHTML = decodeBase64Unicode(initialEncoded);
        } else if (hiddenField.value && editor.innerHTML.trim() === '') {
          editor.innerHTML = hiddenField.value;
        }
        if (editor.innerHTML.trim() === '') editor.innerHTML = '<p></p>';
        hiddenField.value = editor.innerHTML.trim();

        var wrap = editor.closest('.rich-editor-wrap');
        if (!wrap) return;
        function runCommand(cmd, value) {
          editor.focus();
          try {
            document.execCommand(cmd, false, value || null);
          } catch (e) {
            return;
          }
          hiddenField.value = editor.innerHTML.trim();
        }
        function clearFontStyles() {
          var temp = document.createElement('div');
          temp.innerHTML = editor.innerHTML;
          temp.querySelectorAll('[style]').forEach(function (el) {
            var styleValue = el.getAttribute('style') || '';
            var cleaned = styleValue
              .split(';')
              .map(function (rule) { return rule.trim(); })
              .filter(function (rule) { return rule !== ''; })
              .filter(function (rule) {
                var prop = rule.split(':')[0].trim().toLowerCase();
                return [
                  'font',
                  'font-family',
                  'font-size',
                  'font-style',
                  'font-weight',
                  'line-height',
                  'letter-spacing',
                  'word-spacing'
                ].indexOf(prop) === -1;
              })
              .join('; ');
            if (cleaned) {
              el.setAttribute('style', cleaned + ';');
            } else {
              el.removeAttribute('style');
            }
          });
          temp.querySelectorAll('font').forEach(function (fontEl) {
            var parent = fontEl.parentNode;
            while (fontEl.firstChild) {
              parent.insertBefore(fontEl.firstChild, fontEl);
            }
            parent.removeChild(fontEl);
          });
          editor.innerHTML = temp.innerHTML;
          hiddenField.value = editor.innerHTML.trim();
          editor.focus();
        }
        function normalizePastedFragment(fragmentRoot) {
          if (!fragmentRoot) return;
          fragmentRoot.querySelectorAll('style, link, meta').forEach(function (el) {
            if (el.parentNode) el.parentNode.removeChild(el);
          });
          fragmentRoot.querySelectorAll('mark').forEach(function (markEl) {
            var parent = markEl.parentNode;
            while (markEl.firstChild) {
              parent.insertBefore(markEl.firstChild, markEl);
            }
            parent.removeChild(markEl);
          });
          var FONT_RESET_PROPS = [
            'font',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'line-height',
            'letter-spacing',
            'word-spacing',
            'background',
            'background-color',
            'background-image',
            'text-highlight-color',
            'mso-highlight'
          ];
          var walker = document.createTreeWalker(fragmentRoot, NodeFilter.SHOW_ELEMENT, null);
          var nodes = [];
          while (walker.nextNode()) {
            nodes.push(walker.currentNode);
          }
          nodes.forEach(function (el) {
            if (el.tagName && el.tagName.toLowerCase() === 'font') {
              var parent = el.parentNode;
              while (el.firstChild) {
                parent.insertBefore(el.firstChild, el);
              }
              parent.removeChild(el);
              return;
            }
            FONT_RESET_PROPS.forEach(function (prop) {
              try { el.style.removeProperty(prop); } catch (e) {}
            });
            try { el.style.setProperty('color', '#ffffff', 'important'); } catch (e) {}
            try { el.style.setProperty('background', 'transparent', 'important'); } catch (e) {}
            try { el.style.setProperty('background-color', 'transparent', 'important'); } catch (e) {}
            if (el.getAttribute && el.hasAttribute('class')) {
              el.removeAttribute('class');
            }
            if (el.getAttribute && el.hasAttribute('id')) {
              el.removeAttribute('id');
            }
            if (el.getAttribute && el.hasAttribute('color')) {
              el.removeAttribute('color');
            }
            if (el.getAttribute && el.hasAttribute('face')) {
              el.removeAttribute('face');
            }
            if (el.getAttribute && el.hasAttribute('size')) {
              el.removeAttribute('size');
            }
            if (el.getAttribute && el.hasAttribute('bgcolor')) {
              el.removeAttribute('bgcolor');
            }
          });
        }
        function insertNormalizedPaste(html, plainText) {
          var container = document.createElement('div');
          if (html && html.trim() !== '') {
            container.innerHTML = html;
          } else {
            var paragraphs = (plainText || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
            container.innerHTML = paragraphs.map(function (line) {
              var escaped = line
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
              return '<p>' + (escaped || '<br>') + '</p>';
            }).join('');
          }
          normalizePastedFragment(container);
          runCommand('insertHTML', container.innerHTML);
        }
        function setActiveColor(color) {
          var normalized = (color || '').toLowerCase();
          wrap.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
            var swatchColor = (swatch.getAttribute('data-color') || '').toLowerCase();
            swatch.classList.toggle('active', swatchColor === normalized);
          });
          wrap.querySelectorAll('.rich-color-toggle').forEach(function (toggle) {
            toggle.style.background = color;
            toggle.setAttribute('data-color', color);
          });
        }

        wrap.querySelectorAll('.rich-btn').forEach(function (button) {
          button.addEventListener('click', function () {
            if (button.getAttribute('data-action') === 'clear-font') {
              clearFontStyles();
              return;
            }
            var cmd = button.getAttribute('data-cmd');
            if (!cmd) return;
            runCommand(cmd, button.getAttribute('data-value'));
          });
        });

        wrap.querySelectorAll('.rich-color-picker-wrap').forEach(function (pickerWrap) {
          var toggle = pickerWrap.querySelector('.rich-color-toggle');
          if (toggle) {
            toggle.addEventListener('click', function () {
              pickerWrap.classList.toggle('open');
            });
          }
          pickerWrap.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
            swatch.addEventListener('mousedown', function (event) { event.preventDefault(); });
            swatch.addEventListener('click', function () {
              var color = swatch.getAttribute('data-color');
              runCommand('foreColor', color);
              setActiveColor(color);
              pickerWrap.classList.remove('open');
            });
          });
        });

        editor.addEventListener('input', function () {
          hiddenField.value = editor.innerHTML.trim();
        });
        editor.addEventListener('paste', function (event) {
          event.preventDefault();
          var data = event.clipboardData || window.clipboardData;
          if (!data) {
            return;
          }
          var html = '';
          var text = '';
          try { html = data.getData('text/html') || ''; } catch (e) {}
          try { text = data.getData('text/plain') || ''; } catch (e) {}
          insertNormalizedPaste(html, text);
        });
      });
    }

    function syncRichEditors() {
      document.querySelectorAll('.js-rich-editor').forEach(function (editor) {
        var targetId = editor.getAttribute('data-target-id');
        var hiddenField = targetId ? document.getElementById(targetId) : null;
        if (hiddenField) hiddenField.value = editor.innerHTML.trim();
      });
    }

    document.addEventListener('mousedown', function (event) {
      if (event.target.closest('.rich-color-picker-wrap')) return;
      document.querySelectorAll('.rich-color-picker-wrap.open').forEach(function (wrap) {
        wrap.classList.remove('open');
      });
    });

    function bindLectionaryForm() {
      var form = document.getElementById('lectionary-form');
      if (!form || form.dataset.bound === '1') return;
      form.dataset.bound = '1';
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        syncRichEditors();
        var submitter = event.submitter || null;
        var submitButton = submitter || form.querySelector('button[type="submit"], button:not([type])');
        var originalHtml = submitButton ? submitButton.innerHTML : '';
        if (submitButton) {
          submitButton.innerHTML = 'Захаванне <span class="spinner"></span>';
          submitButton.disabled = true;
        }
        form.classList.add('busy');
        try {
          var formData = new FormData(form);
          if (submitter && submitter.name) {
            formData.append(submitter.name, submitter.value || '1');
          }
          formData.append('ajax', '1');
          var response = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
          });
          var json = await response.json();
          if (json.ok) {
            showToast('ok', json.message || 'Аперацыя выканана.');
            await refreshLectionaryView(json.redirect || '');
          } else {
            showToast('err', json.error || 'Памылка аперацыі.');
          }
        } catch (error) {
          showToast('err', 'Памылка сеткі. Паспрабуйце яшчэ раз.');
        } finally {
          form.classList.remove('busy');
          if (submitButton) {
            submitButton.innerHTML = originalHtml;
            submitButton.disabled = false;
          }
        }
      });
    }
    (function () {
      var BULK_OPEN_KEY = 'lectionary_bulk_open';
      var mainFold = document.getElementById('lec-bulk-main-fold');
      var mainBody = document.getElementById('lec-bulk-main-body');
      var mainToggle = document.getElementById('lec-bulk-main-toggle');
      function setMainExpanded(expanded) {
        if (!mainFold || !mainBody || !mainToggle) return;
        mainBody.hidden = !expanded;
        mainFold.classList.toggle('bulk-fold--collapsed', !expanded);
        mainToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        try { window.localStorage.setItem(BULK_OPEN_KEY, expanded ? '1' : '0'); } catch (e) {}
      }
      if (mainFold && mainBody && mainToggle) {
        try { setMainExpanded(window.localStorage.getItem(BULK_OPEN_KEY) === '1'); } catch (e) { setMainExpanded(false); }
        mainToggle.addEventListener('click', function () { setMainExpanded(!!mainBody.hidden); });
      }
      var bulkForm = document.getElementById('lec-bulk-form');
      var btnPreview = document.getElementById('lec-bulk-btn-preview');
      var btnApply = document.getElementById('lec-bulk-exec-open');
      var logEl = document.getElementById('lec-bulk-change-log');
      var logSection = document.getElementById('lec-bulk-log-section');
      var statusEl = document.getElementById('lec-bulk-status-line');
      var spinnerEl = document.getElementById('lec-bulk-spinner');
      var modal = document.getElementById('lec-bulk-modal');
      var noBtn = document.getElementById('lec-bulk-modal-no');
      var yesBtn = document.getElementById('lec-bulk-modal-yes');
      if (!bulkForm || !btnPreview || !btnApply || !logEl) return;
      var lastChangeCount = 0;
      function setBulkLogSectionVisible(visible) { if (logSection) logSection.hidden = !visible; }
      function bulkUrlNow() { return window.location.pathname + window.location.search; }
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
          if (r.touch_title) item.appendChild(makeDiffBlock('Назва', r.title_old, r.title_new));
          if (r.touch_text) item.appendChild(makeDiffBlock('Тэкст (без HTML)', r.text_old, r.text_new));
          logEl.appendChild(item);
        });
        var omitted = Number(rowsOmitted) || 0;
        if (omitted > 0) {
          var more = document.createElement('div');
          more.className = 'bulk-log-more';
          more.textContent = '… і яшчэ ' + omitted + ' запіс(аў).';
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
        Object.keys(extra).forEach(function (k) { fd.set(k, extra[k]); });
        return fd;
      }
      function bulkFetch(extra) {
        return fetch(bulkUrlNow(), {
          method: 'POST',
          body: buildBody(extra),
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
          return res.json().catch(function () { throw new Error('Некарэктны адказ сервера.'); });
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
          .finally(function () { setLoading(false); });
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
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
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
            window.setTimeout(function () { window.location.reload(); }, 500);
          })
          .catch(function (e) { setStatus(e.message || 'Памылка сеткі', true); })
          .finally(function () { setLoading(false); });
      });
    })();

    initRichEditors();
    bindLectionaryForm();
    if (initialMessage) showToast('ok', initialMessage);
    if (initialError) showToast('err', initialError);
  </script>
</body>
</html>
