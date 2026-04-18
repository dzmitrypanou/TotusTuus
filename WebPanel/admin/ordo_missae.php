<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel_security.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/schema.php';
require_once __DIR__ . '/../includes/panel_auth.php';
require_once __DIR__ . '/../includes/ordo_missae_sections.php';

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

$isAjaxRequest = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
    || (($_POST['ajax'] ?? '') === '1');

function ordo_missae_ajax_response(bool $ok, string $messageText = '', string $errorText = ''): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        [
            'ok' => $ok,
            'message' => $messageText,
            'error' => $errorText,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ordo_save_ajax']) && !isset($_POST['logout'])) {
    panel_require_section_for_post('liturgy', $isAjaxRequest);
    if (!panel_csrf_token_valid()) {
        ordo_missae_ajax_response(false, '', 'Несапраўдны токен бяспекі. Абнавіце старонку.');
    }
    ensurePanelOrdoMissaeTable();
    $stmtRow = db()->query('SELECT * FROM panel_ordo_missae WHERE id = 1 LIMIT 1');
    $prevRow = $stmtRow->fetch();
    $prevRow = is_array($prevRow) ? $prevRow : [];

    $layoutRaw = (string)($_POST['ordo_layout_json'] ?? '');
    $layoutDecoded = json_decode($layoutRaw, true);
    if (!is_array($layoutDecoded)) {
        ordo_missae_ajax_response(false, '', 'Некарэктныя даныя парадку секцый (ordo_layout_json). Абнавіце старонку.');
    }
    $layout = ordo_missae_normalize_layout($layoutDecoded);

    $customTitles = $_POST['ordo_custom_title'] ?? [];
    $customHtmls = $_POST['ordo_custom_html'] ?? [];
    if (!is_array($customTitles)) {
        $customTitles = [];
    }
    if (!is_array($customHtmls)) {
        $customHtmls = [];
    }

    foreach ($layout['order'] as $slot) {
        if (!is_array($slot) || ($slot['type'] ?? '') !== 'custom') {
            continue;
        }
        $id = (string)($slot['id'] ?? '');
        if ($id === '' || !ordo_missae_custom_id_valid($id)) {
            continue;
        }
        $layout['custom'][$id]['title'] = ordo_missae_sanitize_custom_title(
            (string)($customTitles[$id] ?? ($layout['custom'][$id]['title'] ?? ''))
        );
        $layout['custom'][$id]['html'] = (string)($customHtmls[$id] ?? ($layout['custom'][$id]['html'] ?? ''));
    }

    $parts = [];
    $titlesStored = [];
    $titlesEffective = [];
    foreach (ordo_missae_section_defs() as $d) {
        $k = $d['key'];
        if (array_key_exists('ordo_' . $k, $_POST)) {
            $parts[$k] = (string)$_POST['ordo_' . $k];
        } else {
            $parts[$k] = (string)($prevRow[$d['column']] ?? '');
        }
        if (array_key_exists('ordo_title_' . $k, $_POST)) {
            $titlesStored[$k] = ordo_missae_title_storage_from_post(
                (string)$_POST['ordo_title_' . $k],
                $d['label']
            );
        } else {
            $titlesStored[$k] = (string)($prevRow[$d['title_column']] ?? '');
        }
        $titlesEffective[$k] = trim($titlesStored[$k]) !== ''
            ? $titlesStored[$k]
            : $d['label'];
    }

    $merged = ordo_missae_merged_html_for_legacy_column($parts, $titlesEffective, $layout);
    $layoutJsonOut = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($layoutJsonOut === false) {
        ordo_missae_ajax_response(false, '', 'Не ўдалося скласці JSON размеркавання.');
    }

    $sets = [];
    $params = [];
    foreach (ordo_missae_section_defs() as $d) {
        $sets[] = '`' . $d['column'] . '` = :' . $d['key'];
        $params[':' . $d['key']] = $parts[$d['key']] ?? '';
        $sets[] = '`' . $d['title_column'] . '` = :t_' . $d['key'];
        $params[':t_' . $d['key']] = $titlesStored[$d['key']] ?? '';
    }
    $sets[] = '`html` = :merged';
    $params[':merged'] = $merged;
    $sets[] = '`ordo_layout_json` = :ordo_layout_json';
    $params[':ordo_layout_json'] = $layoutJsonOut;
    $sql = 'UPDATE panel_ordo_missae SET ' . implode(', ', $sets) . ' WHERE id = 1';
    try {
        $upd = db()->prepare($sql);
        $upd->execute($params);
        ordo_missae_ajax_response(true, 'Захавана.', '');
    } catch (Throwable $e) {
        ordo_missae_ajax_response(false, '', $e->getMessage());
    }
}

$stmt = db()->query('SELECT * FROM panel_ordo_missae WHERE id = 1 LIMIT 1');
$row = $stmt->fetch();
$row = is_array($row) ? $row : [];
$ordoLayout = ordo_missae_layout_from_db_row($row);
$initialB64Map = [];
foreach ($ordoLayout['order'] as $slot) {
    if (!is_array($slot)) {
        continue;
    }
    if (($slot['type'] ?? '') === 'built_in') {
        $k = (string)($slot['key'] ?? '');
        $d = ordo_missae_def_by_key($k);
        if (!$d) {
            continue;
        }
        $html = (string)($row[$d['column']] ?? '');
        $initialB64Map[$k] = $html !== '' ? base64_encode($html) : '';
    } elseif (($slot['type'] ?? '') === 'custom') {
        $id = (string)($slot['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $block = $ordoLayout['custom'][$id] ?? ['title' => '', 'html' => ''];
        $html = (string)($block['html'] ?? '');
        $initialB64Map[$id] = $html !== '' ? base64_encode($html) : '';
    }
}

$ordoSavePath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/admin/ordo_missae.php'));

?>
<!doctype html>
<html lang="be">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <title>Ordo Missae — Totus Tuus</title>
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
      --accent: #7c6cf0;
      --accent-2: #c4a35a;
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
      box-shadow: 0 4px 24px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.04) inset;
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
    .grid { display: grid; grid-template-columns: 1fr; gap: 14px; width: 100%; min-width: 0; }
    .card {
      background: #111827;
      border: 1px solid #334155;
      border-radius: 14px;
      padding: 16px;
      min-width: 0;
      overflow: visible;
    }
    /* Як у admin/index.php: усплывальныя тосты, не ў патоку старонкі */
    .toast-wrap {
      position: fixed;
      top: 16px;
      right: 16px;
      z-index: 95000;
      display: flex;
      flex-direction: column;
      gap: 8px;
      align-items: flex-end;
      pointer-events: none;
      max-width: calc(100vw - 32px);
    }
    .toast-wrap .toast { pointer-events: auto; }
    .toast {
      min-width: 220px;
      max-width: min(360px, calc(100vw - 32px));
      padding: 12px 14px;
      border-radius: var(--radius-sm);
      color: #fff;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.35;
      box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
      border: 1px solid rgba(255, 255, 255, 0.12);
      animation: ordoToastIn 0.22s ease;
    }
    .toast.ok { background: linear-gradient(135deg, #15803d, #22c55e); }
    .toast.err { background: linear-gradient(135deg, #b91c1c, #ef4444); }
    @keyframes ordoToastIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 520px) {
      .toast-wrap { top: auto; bottom: 16px; right: 12px; left: 12px; align-items: stretch; }
      .toast { max-width: none; min-width: 0; width: 100%; }
    }
    .ordo-editor-stack {
      width: 100%;
      max-width: 100%;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      background: rgba(10, 12, 20, 0.55);
      overflow: visible;
    }
    /* Панель рэдактара — як у молітвенніку (admin/index.php): .rich-toolbar, .rich-quick-toolbar */
    #ordo-toolbar.rich-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
      padding: 10px;
      border-bottom: 1px solid var(--line);
      background: rgba(15, 23, 42, 0.65);
      border-radius: var(--radius-sm) var(--radius-sm) 0 0;
    }
    #ordo-toolbar .rich-toolbar-group {
      display: inline-flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px;
      padding-right: 12px;
      margin-right: 2px;
      border-right: 1px solid var(--line);
    }
    #ordo-toolbar .rich-toolbar-group:last-child {
      border-right: none;
      margin-right: 0;
      padding-right: 0;
    }
    #ordo-toolbar .rich-toolbar-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      margin-right: 2px;
    }
    #ordo-toolbar .rich-btn {
      margin: 0;
      padding: 6px 11px;
      border-radius: 8px;
      font-size: 13px;
      font-family: inherit;
      font-weight: 600;
      background: rgba(124, 108, 240, 0.2);
      color: #e0e7ff;
      border: 1px solid rgba(124, 108, 240, 0.25);
      cursor: pointer;
      box-shadow: none;
    }
    #ordo-toolbar .rich-btn:hover { background: rgba(124, 108, 240, 0.32); }
    #ordo-toolbar .rich-btn.active {
      background: linear-gradient(135deg, var(--accent), #5b4fc9);
      color: #fff;
      border-color: transparent;
    }
    #ordo-toolbar .rich-btn-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 38px;
      min-height: 38px;
      padding: 7px;
    }
    #ordo-toolbar .rich-btn-icon svg { display: block; flex-shrink: 0; }
    #ordo-toolbar .rich-color-picker-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
    }
    #ordo-toolbar .rich-color-toggle {
      width: 34px;
      height: 34px;
      min-width: 34px;
      min-height: 34px;
      padding: 0;
      margin: 0;
      border-radius: 8px;
      border: 2px solid rgba(148, 163, 184, 0.45);
      background: #ffffff;
      cursor: pointer;
      box-shadow: none;
    }
    #ordo-toolbar .rich-color-picker-wrap.open .rich-color-toggle {
      border-color: #ffffff;
      box-shadow: 0 0 0 2px rgba(124, 108, 240, 0.55);
    }
    #ordo-toolbar .rich-color-dropdown {
      position: absolute;
      top: calc(100% + 8px);
      left: 0;
      z-index: 30;
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
    #ordo-toolbar .rich-color-picker-wrap.open .rich-color-dropdown {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 6px;
    }
    #ordo-toolbar .rich-color-swatch {
      width: 18px;
      height: 18px;
      margin: 0;
      padding: 0;
      border-radius: 4px;
      border: 1px solid rgba(148, 163, 184, 0.5);
      cursor: pointer;
      box-shadow: none;
    }
    #ordo-toolbar .rich-color-swatch:hover { filter: brightness(1.1); }
    #ordo-toolbar .rich-color-swatch.active,
    #ordo-toolbar .rich-color-swatch:focus-visible {
      border-color: #ffffff;
      box-shadow: 0 0 0 1px rgba(124, 108, 240, 0.65);
      outline: none;
    }
    #ordo-toolbar .rich-color-swatch--white { border-color: rgba(203, 213, 225, 0.9); }
    #ordo-quick-toolbar.rich-quick-toolbar {
      position: absolute;
      z-index: 100050;
      display: none;
      align-items: center;
      gap: 6px;
      padding: 8px;
      border-radius: 14px;
      border: 1px solid rgba(124, 108, 240, 0.45);
      background: rgba(8, 10, 18, 0.96);
      box-shadow:
        0 14px 36px rgba(0, 0, 0, 0.48),
        0 0 0 1px rgba(124, 108, 240, 0.35),
        0 0 28px rgba(99, 102, 241, 0.3);
    }
    #ordo-quick-toolbar .rich-btn {
      margin: 0;
      min-width: 34px;
      min-height: 34px;
      padding: 6px 9px;
      border-radius: 8px;
      font-size: 13px;
      font-family: inherit;
      font-weight: 600;
      background: rgba(124, 108, 240, 0.2);
      color: #e0e7ff;
      border: 1px solid rgba(124, 108, 240, 0.25);
      cursor: pointer;
      box-shadow: none;
    }
    #ordo-quick-toolbar .rich-btn:hover { background: rgba(124, 108, 240, 0.32); }
    #ordo-quick-toolbar .rich-color-picker-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
    }
    #ordo-quick-toolbar .rich-color-toggle {
      width: 34px;
      height: 34px;
      min-width: 34px;
      min-height: 34px;
      padding: 0;
      margin: 0;
      border-radius: 8px;
      border: 2px solid rgba(148, 163, 184, 0.45);
      background: #ffffff;
      cursor: pointer;
    }
    #ordo-quick-toolbar .rich-color-picker-wrap.open .rich-color-toggle {
      border-color: #ffffff;
      box-shadow: 0 0 0 2px rgba(124, 108, 240, 0.55);
    }
    #ordo-quick-toolbar .rich-color-dropdown {
      position: absolute;
      top: calc(100% + 8px);
      left: auto;
      right: 0;
      z-index: 2;
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
    #ordo-quick-toolbar .rich-color-picker-wrap.open .rich-color-dropdown {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 6px;
    }
    #ordo-quick-toolbar .rich-color-swatch {
      width: 18px;
      height: 18px;
      margin: 0;
      padding: 0;
      border-radius: 4px;
      border: 1px solid rgba(148, 163, 184, 0.5);
      cursor: pointer;
    }
    #ordo-quick-toolbar .rich-color-swatch--white { border-color: rgba(203, 213, 225, 0.9); }
    .ordo-body {
      display: block;
      width: 100%;
      min-height: min(52vh, 520px);
      max-height: none;
      padding: 16px 18px 20px;
      font-size: 16px;
      line-height: 1.55;
      color: var(--text);
      outline: none;
      overflow-y: auto;
      overflow-x: hidden;
      overflow-wrap: anywhere;
      word-break: break-word;
      border-radius: 0 0 var(--radius-sm) var(--radius-sm);
      background: rgba(6, 8, 14, 0.5);
    }
    .ordo-body:focus { box-shadow: inset 0 0 0 2px rgba(124, 108, 240, 0.35); }
    .ordo-sections { border-top: 1px solid var(--line); }
    details.ordo-section { border-bottom: 1px solid var(--line); }
    details.ordo-section:last-of-type { border-bottom: none; }
    details.ordo-section > summary {
      list-style: none;
      cursor: pointer;
      user-select: none;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 16px;
      font-weight: 700;
      font-size: 0.95rem;
      letter-spacing: 0.02em;
      color: #e0e7ff;
      background: rgba(15, 23, 42, 0.75);
      border-left: 3px solid rgba(124, 108, 240, 0.55);
    }
    details.ordo-section > summary::-webkit-details-marker { display: none; }
    details.ordo-section[open] > summary {
      background: rgba(30, 27, 75, 0.55);
      border-left-color: rgba(196, 163, 90, 0.55);
    }
    details.ordo-section > summary::after {
      content: "";
      flex-shrink: 0;
      width: 0.5em;
      height: 0.5em;
      border-right: 2px solid var(--muted);
      border-bottom: 2px solid var(--muted);
      transform: rotate(45deg);
      transition: transform 0.15s ease;
    }
    details.ordo-section[open] > summary::after { transform: rotate(-135deg); }
    .ordo-title-row {
      flex: 1 1 auto;
      min-width: 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .ordo-title-text {
      flex: 1 1 auto;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-weight: 700;
    }
    .ordo-title-edit-btn {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      margin: 0;
      padding: 0;
      border-radius: 10px;
      border: 1px solid rgba(124, 108, 240, 0.35);
      background: rgba(124, 108, 240, 0.18);
      color: #e0e7ff;
      cursor: pointer;
      font: inherit;
    }
    .ordo-title-edit-btn:hover {
      background: rgba(124, 108, 240, 0.32);
      border-color: rgba(196, 163, 90, 0.35);
      color: #fff;
    }
    .ordo-title-edit-btn svg { display: block; }
    .ordo-section-title-input {
      display: none;
      flex: 1 1 14rem;
      min-width: 0;
      margin: 0;
      padding: 8px 10px;
      border-radius: 8px;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(6, 8, 14, 0.65);
      color: #f1f5f9;
      font: inherit;
      font-size: 0.95rem;
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    details.ordo-section--title-editing > summary .ordo-title-text,
    details.ordo-section--title-editing > summary .ordo-title-edit-btn {
      display: none !important;
    }
    details.ordo-section--title-editing > summary .ordo-section-title-input {
      display: block !important;
    }
    .ordo-section-title-input::placeholder { color: #64748b; font-weight: 500; }
    .ordo-section-title-input:focus {
      outline: none;
      border-color: rgba(124, 108, 240, 0.65);
      box-shadow: 0 0 0 2px rgba(124, 108, 240, 0.2);
    }
    details.ordo-section .ordo-body {
      min-height: min(28vh, 360px);
      border-radius: 0;
      border-top: 1px solid var(--line);
    }
    .ordo-body p { margin: 0 0 10px; }
    .ordo-body ul, .ordo-body ol { margin: 0 0 10px 1.25rem; }
    .ordo-body h3 { margin: 12px 0 8px; font-size: 1.15rem; }
    .ordo-html-hidden { display: none !important; }
    .ordo-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; align-items: center; }
    .ordo-actions button[type="submit"] {
      margin: 0;
      border: 1px solid rgba(124, 108, 240, 0.45);
      background: linear-gradient(135deg, #6d5dfc 0%, #8b7cf5 50%, #a78bfa 100%);
      color: #fff;
      font-weight: 700;
      border-radius: 10px;
      padding: 12px 22px;
      cursor: pointer;
      font: inherit;
      font-size: 15px;
      box-shadow: 0 8px 24px rgba(109, 93, 252, 0.35);
    }
    .ordo-actions button[type="submit"]:hover { filter: brightness(1.08); }
    .ordo-actions button[type="submit"]:disabled { opacity: 0.65; cursor: wait; filter: none; }
    .ordo-section-toolbar {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      flex-shrink: 0;
      margin-right: 10px;
    }
    .ordo-move-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      padding: 0;
      border-radius: 8px;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.65);
      color: #cbd5e1;
      cursor: pointer;
      font: inherit;
      font-size: 14px;
      font-weight: 700;
      line-height: 1;
    }
    .ordo-move-btn:hover:not(:disabled) {
      border-color: rgba(124, 108, 240, 0.55);
      color: #fff;
      background: rgba(124, 108, 240, 0.22);
    }
    .ordo-move-btn:disabled {
      opacity: 0.35;
      cursor: not-allowed;
    }
    .ordo-remove-custom {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      padding: 0;
      border-radius: 8px;
      border: 1px solid rgba(239, 68, 68, 0.45);
      background: rgba(127, 29, 29, 0.35);
      color: #fecaca;
      cursor: pointer;
      font: inherit;
    }
    .ordo-remove-custom:hover {
      background: rgba(185, 28, 28, 0.55);
      color: #fff;
    }
    .ordo-add-section-wrap {
      padding: 12px 16px;
      border-top: 1px solid var(--line);
      background: rgba(15, 23, 42, 0.45);
    }
    .ordo-add-section {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 16px;
      border-radius: 10px;
      border: 1px dashed rgba(124, 108, 240, 0.45);
      background: rgba(124, 108, 240, 0.12);
      color: #e0e7ff;
      font: inherit;
      font-weight: 600;
      cursor: pointer;
    }
    .ordo-add-section:hover {
      border-style: solid;
      background: rgba(124, 108, 240, 0.22);
    }
    @media (max-width: 1180px) {
      .header { flex-direction: column; align-items: flex-start; }
      .header-brand { align-self: center; }
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
    $panelNavPage = 'ordo_missae';
    $panelNavView = 'categories';
    $panelNavCalYear = (int)date('Y');
    require __DIR__ . '/../includes/panel_admin_nav.php';
    ?>
  </div>

  <div class="grid">
    <div class="card">
      <form id="ordo-form" method="post" data-save-path="<?= htmlspecialchars($ordoSavePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?= panel_csrf_field() ?>
        <div class="ordo-editor-stack">
          <div class="rich-toolbar" id="ordo-toolbar">
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="bold" title="Тоўсты" aria-label="Тоўсты"><b>B</b></button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="italic" title="Курсіў" aria-label="Курсіў"><i>I</i></button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="underline" title="Падкрэслены" aria-label="Падкрэслены"><u>U</u></button>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="insertUnorderedList" title="Маркіраваны спіс" aria-label="Маркіраваны спіс">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 10.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zm0-6c-.83 0-1.5.67-1.5 1.5S3.17 7.5 4 7.5 5.5 6.83 5.5 6 4.83 4.5 4 4.5zm0 12c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zM7 19h14v-2H7v2zm0-6h14v-2H7v2zm0-8v2h14V5H7z"/></svg>
              </button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="insertOrderedList" title="Нумараваны спіс" aria-label="Нумараваны спіс">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1zm1-9h1V4H2v1h1v3zm-1 3h1.8L2 13.1v.9h3v-1H3.2L5 10.9V10H2v1zm5-6v2h14V5H7zm0 14h14v-2H7v2zm0-6h14v-2H7v2z"/></svg>
              </button>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyLeft" title="Выраўнованне ўлева" aria-label="Выраўнованне ўлева">L</button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyCenter" title="Выраўнованне па цэнтры" aria-label="Выраўнованне па цэнтры">C</button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyRight" title="Выраўнованне ўправа" aria-label="Выраўнованне ўправа">R</button>
              <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyFull" title="Выраўнованне па шырыні" aria-label="Выраўнованне па шырыні">J</button>
            </div>
            <div class="rich-toolbar-group" title="Колер вылучанага тэксту">
              <span class="rich-toolbar-label">Колер</span>
              <div class="rich-color-picker-wrap" id="ordo-main-color-picker">
                <button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;" title="Абраць колер" aria-label="Абраць колер"></button>
                <div class="rich-color-dropdown" id="ordo-main-color-dropdown" role="group" aria-label="Колер тэксту"></div>
              </div>
            </div>
            <div class="rich-toolbar-group">
              <button type="button" class="rich-btn" data-cmd="formatBlock" data-value="h3" title="Загаловак">Загаловак</button>
              <button type="button" class="rich-btn" data-cmd="removeFormat" title="Ачысціць фарматаванне">Ачысціць</button>
              <button type="button" class="rich-btn" data-action="clearBackground" title="Прыбраць колер/відарыс фону (колер тэксту і тоўсты/курсіў застаюцца)" aria-label="Без фону">Без фону</button>
            </div>
          </div>
          <input type="hidden" name="ordo_layout_json" id="ordo_layout_json" value="<?= htmlspecialchars(json_encode($ordoLayout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
          <div class="ordo-sections" id="ordo-sections">
            <?php
            $secIdx = 0;
            foreach ($ordoLayout['order'] as $slot) :
                if (!is_array($slot)) {
                    continue;
                }
                if (($slot['type'] ?? '') === 'built_in') :
                    $k = (string) ($slot['key'] ?? '');
                    $d = ordo_missae_def_by_key($k);
                    if (!$d) {
                        continue;
                    }
                    $label = $d['label'];
                    $effectiveTitle = ordo_missae_effective_section_title($row, $d);
                    ?>
            <details
              class="ordo-section"
              data-section-kind="built_in"
              data-ordo-key="<?= htmlspecialchars($k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-default-title="<?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              <?= $secIdx === 0 ? ' open' : '' ?>
            >
              <summary>
                <span class="ordo-section-toolbar" aria-label="Парадак секцый">
                  <button type="button" class="ordo-move-btn ordo-move-up" title="Уверх">↑</button>
                  <button type="button" class="ordo-move-btn ordo-move-down" title="Уніз">↓</button>
                </span>
                <span class="ordo-title-row">
                  <span class="ordo-title-text"><?= htmlspecialchars($effectiveTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <input
                    type="text"
                    class="ordo-section-title-input"
                    name="ordo_title_<?= htmlspecialchars($k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    value="<?= htmlspecialchars($effectiveTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    maxlength="255"
                    placeholder="<?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    title="Загаловак у публічным тэксце Ordo Missae"
                    aria-label="Загаловак секцыі"
                    autocomplete="off"
                    onclick="event.stopPropagation()"
                  />
                  <button
                    type="button"
                    class="ordo-title-edit-btn"
                    title="Рэдагаваць загаловак"
                    aria-label="Рэдагаваць загаловак"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                  </button>
                </span>
              </summary>
              <div
                id="ordo_editor_<?= htmlspecialchars($k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                class="ordo-body"
                contenteditable="true"
                role="textbox"
                aria-multiline="true"
                aria-label="<?= htmlspecialchars($effectiveTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                spellcheck="true"
                data-ordo-key="<?= htmlspecialchars($k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              ></div>
              <textarea
                id="ordo_html_<?= htmlspecialchars($k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                class="ordo-html-hidden"
                name="ordo_<?= htmlspecialchars($k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                aria-hidden="true"
              ></textarea>
            </details>
            <?php
                    $secIdx++;
                elseif (($slot['type'] ?? '') === 'custom') :
                    $cid = (string) ($slot['id'] ?? '');
                    if ($cid === '' || !ordo_missae_custom_id_valid($cid)) {
                        continue;
                    }
                    $cBlock = $ordoLayout['custom'][$cid] ?? ['title' => '', 'html' => ''];
                    $defaultCustom = 'Дадатковая частка';
                    $storedTitle = trim((string) ($cBlock['title'] ?? ''));
                    $effectiveTitle = $storedTitle !== '' ? $storedTitle : $defaultCustom;
                    ?>
            <details
              class="ordo-section ordo-section--custom"
              data-section-kind="custom"
              data-ordo-key="<?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-default-title="<?= htmlspecialchars($defaultCustom, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              <?= $secIdx === 0 ? ' open' : '' ?>
            >
              <summary>
                <span class="ordo-section-toolbar" aria-label="Парадак і выдаленне">
                  <button type="button" class="ordo-move-btn ordo-move-up" title="Уверх">↑</button>
                  <button type="button" class="ordo-move-btn ordo-move-down" title="Уніз">↓</button>
                  <button type="button" class="ordo-remove-custom" title="Выдаліць частку" aria-label="Выдаліць частку">×</button>
                </span>
                <span class="ordo-title-row">
                  <span class="ordo-title-text"><?= htmlspecialchars($effectiveTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <input
                    type="text"
                    class="ordo-section-title-input"
                    name="ordo_custom_title[<?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                    value="<?= htmlspecialchars($storedTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    maxlength="255"
                    placeholder="<?= htmlspecialchars($defaultCustom, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    title="Загаловак дадатковай часткі"
                    aria-label="Загаловак секцыі"
                    autocomplete="off"
                    onclick="event.stopPropagation()"
                  />
                  <button
                    type="button"
                    class="ordo-title-edit-btn"
                    title="Рэдагаваць загаловак"
                    aria-label="Рэдагаваць загаловак"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                  </button>
                </span>
              </summary>
              <div
                id="ordo_editor_<?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                class="ordo-body"
                contenteditable="true"
                role="textbox"
                aria-multiline="true"
                aria-label="<?= htmlspecialchars($effectiveTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                spellcheck="true"
                data-ordo-key="<?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              ></div>
              <textarea
                id="ordo_html_<?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                class="ordo-html-hidden"
                name="ordo_custom_html[<?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                aria-hidden="true"
              ></textarea>
            </details>
            <?php
                    $secIdx++;
                endif;
            endforeach;
            ?>
          </div>
          <div class="ordo-add-section-wrap">
            <button type="button" class="ordo-add-section" id="ordo-add-custom-section">+ Дадаць частку Святой Імшы</button>
          </div>
        </div>
        <div class="ordo-actions">
          <button type="submit" id="ordo-save-btn">Захаваць</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast-wrap" class="toast-wrap" aria-live="polite" aria-relevant="additions"></div>

  <script>
  (function () {
    'use strict';

    var COLORS = [
      '#000000','#1f2937','#374151','#6b7280','#9ca3af','#ffffff',
      '#7f1d1d','#b91c1c','#ef4444','#f87171','#fb923c','#f97316',
      '#854d0e','#eab308','#fde047','#3f6212','#15803d','#22c55e',
      '#4ade80','#0f766e','#14b8a6','#2dd4bf','#1e3a8a','#2563eb',
      '#60a5fa','#312e81','#4f46e5','#581c87','#9333ea','#d946ef'
    ];

    var activeEd = null;
    var savedRange = null;

    function decodeB64Utf8(b64) {
      if (!b64) return '';
      try {
        return decodeURIComponent(Array.prototype.map.call(atob(b64), function (c) {
          return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
      } catch (e) { return ''; }
    }

    function escapeHtmlAttr(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    }

    function escapeHtmlText(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    }

    function editors() {
      return Array.prototype.slice.call(document.querySelectorAll('.ordo-body[contenteditable="true"]'));
    }

    function ensureActiveEditor() {
      if (activeEd && document.body.contains(activeEd)) return activeEd;
      var list = editors();
      activeEd = list[0] || null;
      return activeEd;
    }

    function editorContainingNode(node) {
      var n = node;
      if (n && n.nodeType === 3 && n.parentElement) n = n.parentElement;
      while (n && n !== document.body) {
        if (n.classList && n.classList.contains('ordo-body')) return n;
        n = n.parentElement;
      }
      return null;
    }

    function saveSelectionRange() {
      var sel = window.getSelection();
      if (!sel || sel.rangeCount === 0) return;
      var range = sel.getRangeAt(0);
      var ed = editorContainingNode(range.commonAncestorContainer);
      if (!ed) return;
      activeEd = ed;
      savedRange = range.cloneRange();
    }

    function restoreSelectionRange() {
      if (!savedRange) return false;
      var sel = window.getSelection();
      if (!sel) return false;
      var ed = ensureActiveEditor();
      if (!ed || !ed.contains(savedRange.commonAncestorContainer)) return false;
      try {
        sel.removeAllRanges();
        sel.addRange(savedRange);
      } catch (err) {
        return false;
      }
      return true;
    }

    function syncOneEditorToTextarea(ed) {
      if (!ed) return;
      var key = ed.getAttribute('data-ordo-key');
      if (!key) return;
      var ta = document.getElementById('ordo_html_' + key);
      if (!ta) return;
      ta.value = ed.innerHTML.trim();
    }

    function syncAllToTextareas() {
      editors().forEach(syncOneEditorToTextarea);
    }

    function newCustomSectionId() {
      var bytes = new Uint8Array(8);
      if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(bytes);
      } else {
        for (var i = 0; i < bytes.length; i++) {
          bytes[i] = Math.floor(Math.random() * 256);
        }
      }
      var hex = '';
      for (var j = 0; j < bytes.length; j++) {
        hex += ('0' + bytes[j].toString(16)).slice(-2);
      }
      return 'c_' + hex;
    }

    function syncOrdoLayoutHiddenInput() {
      var order = [];
      var custom = {};
      var root = document.getElementById('ordo-sections');
      if (!root) return;
      Array.prototype.forEach.call(root.querySelectorAll(':scope > details.ordo-section'), function (det) {
        var kind = det.getAttribute('data-section-kind');
        var key = det.getAttribute('data-ordo-key');
        if (!key) return;
        if (kind === 'built_in') {
          order.push({ type: 'built_in', key: key });
        } else if (kind === 'custom') {
          order.push({ type: 'custom', id: key });
          var ti = det.querySelector('.ordo-section-title-input');
          var ta = det.querySelector('textarea.ordo-html-hidden');
          custom[key] = {
            title: ti ? ti.value.trim() : '',
            html: ta ? ta.value : ''
          };
        }
      });
      var inp = document.getElementById('ordo_layout_json');
      if (inp) {
        inp.value = JSON.stringify({ v: 1, order: order, custom: custom });
      }
    }

    function refreshOrdoMoveButtonsState() {
      var root = document.getElementById('ordo-sections');
      if (!root) return;
      var rows = root.querySelectorAll(':scope > details.ordo-section');
      for (var i = 0; i < rows.length; i++) {
        var det = rows[i];
        var up = det.querySelector('.ordo-move-up');
        var down = det.querySelector('.ordo-move-down');
        if (up) up.disabled = i === 0;
        if (down) down.disabled = i === rows.length - 1;
      }
    }

    function moveOrdoSection(detailsEl, delta) {
      var root = document.getElementById('ordo-sections');
      if (!root || !detailsEl || !root.contains(detailsEl)) return;
      if (delta < 0 && detailsEl.previousElementSibling) {
        root.insertBefore(detailsEl, detailsEl.previousElementSibling);
      } else if (delta > 0 && detailsEl.nextElementSibling) {
        root.insertBefore(detailsEl.nextElementSibling, detailsEl);
      }
      refreshOrdoMoveButtonsState();
    }

    function wireNewOrdoSectionEditors(detailsEl) {
      var edList = detailsEl.querySelectorAll('.ordo-body[contenteditable="true"]');
      Array.prototype.forEach.call(edList, function (ed) {
        ed.addEventListener('input', function () {
          positionOrdoQuickToolbar();
        });
        ed.addEventListener('mouseup', positionOrdoQuickToolbar);
        ed.addEventListener('keyup', positionOrdoQuickToolbar);
        ed.addEventListener('blur', function () {
          window.setTimeout(function () {
            var active = document.activeElement;
            var quick = document.getElementById('ordo-quick-toolbar');
            if (quick && active && quick.contains(active)) return;
            hideOrdoQuickToolbar();
          }, 0);
        });
      });
    }

    function showToast(ok, text) {
      var wrap = document.getElementById('toast-wrap');
      if (!wrap || !text) return;
      var el = document.createElement('div');
      el.className = 'toast ' + (ok ? 'ok' : 'err');
      el.setAttribute('role', 'status');
      el.textContent = text;
      wrap.appendChild(el);
      window.setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, ok ? 2600 : 5200);
    }

    function clearToasts() {
      var wrap = document.getElementById('toast-wrap');
      if (wrap) wrap.innerHTML = '';
    }

    function runCmd(cmd, val) {
      var ed = ensureActiveEditor();
      if (!ed) return;
      ed.focus();
      restoreSelectionRange();
      try {
        document.execCommand(cmd, false, val || null);
      } catch (e) {}
      syncAllToTextareas();
      positionOrdoQuickToolbar();
    }

    var BG_STYLE_PROPS = [
      'background',
      'background-color',
      'background-image',
      'background-size',
      'background-repeat',
      'background-position',
      'background-attachment',
      'background-clip',
      'background-origin'
    ];

    function stripBackgroundFromElement(el) {
      if (!el || el.nodeType !== 1) return;
      el.removeAttribute('bgcolor');
      if (el.style) {
        for (var i = 0; i < BG_STYLE_PROPS.length; i++) {
          el.style.removeProperty(BG_STYLE_PROPS[i]);
        }
        if (!el.style.cssText.trim()) {
          el.removeAttribute('style');
        }
      }
    }

    function clearBackgroundInSelection() {
      var ed = ensureActiveEditor();
      if (!ed) return;
      ed.focus();
      restoreSelectionRange();
      var sel = window.getSelection();
      var toVisit = [];
      if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
        ed.querySelectorAll('*').forEach(function (n) {
          toVisit.push(n);
        });
      } else {
        var range = sel.getRangeAt(0);
        ed.querySelectorAll('*').forEach(function (n) {
          try {
            if (range.intersectsNode(n)) toVisit.push(n);
          } catch (err) {}
        });
      }
      toVisit.forEach(stripBackgroundFromElement);
      syncAllToTextareas();
      positionOrdoQuickToolbar();
    }

    function fillColorDropdown(container) {
      if (!container) return;
      container.innerHTML = '';
      COLORS.forEach(function (hex) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'rich-color-swatch';
        if ((hex || '').toLowerCase() === '#ffffff') b.classList.add('rich-color-swatch--white');
        b.style.background = hex;
        b.setAttribute('data-color', hex);
        b.title = hex;
        container.appendChild(b);
      });
    }

    function setActiveColor(color) {
      var normalized = (color || '').toLowerCase();
      var scopes = [document.getElementById('ordo-toolbar'), document.getElementById('ordo-quick-toolbar')].filter(Boolean);
      scopes.forEach(function (scope) {
        scope.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
          var sc = (swatch.getAttribute('data-color') || '').toLowerCase();
          swatch.classList.toggle('active', sc === normalized);
        });
        scope.querySelectorAll('.rich-color-toggle').forEach(function (toggle) {
          toggle.setAttribute('data-color', color);
          toggle.style.background = color;
        });
      });
    }

    function closeAllColorPickers(exceptWrap) {
      document.querySelectorAll('.rich-color-picker-wrap.open').forEach(function (w) {
        if (exceptWrap && w === exceptWrap) return;
        w.classList.remove('open');
      });
    }

    function bindColorPickers(scope, keepSelectionOnToggle) {
      if (!scope) return;
      scope.querySelectorAll('.rich-color-picker-wrap').forEach(function (pickerWrap) {
        if (pickerWrap.dataset.bound === '1') return;
        pickerWrap.dataset.bound = '1';
        var toggle = pickerWrap.querySelector('.rich-color-toggle');
        if (toggle) {
          if (keepSelectionOnToggle) {
            toggle.addEventListener('mousedown', function (e) {
              e.preventDefault();
              restoreSelectionRange();
            });
          } else {
            toggle.addEventListener('mousedown', function (e) {
              e.preventDefault();
              saveSelectionRange();
            });
          }
          toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (keepSelectionOnToggle) restoreSelectionRange();
            else saveSelectionRange();
            var willOpen = !pickerWrap.classList.contains('open');
            closeAllColorPickers(pickerWrap);
            pickerWrap.classList.toggle('open', willOpen);
          });
        }
        pickerWrap.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
          swatch.addEventListener('mousedown', function (event) {
            event.preventDefault();
            restoreSelectionRange();
          });
          swatch.addEventListener('click', function () {
            var color = swatch.getAttribute('data-color');
            runCmd('foreColor', color);
            setActiveColor(color);
            pickerWrap.classList.remove('open');
          });
        });
      });
    }

    function hideOrdoQuickToolbar() {
      var quick = document.getElementById('ordo-quick-toolbar');
      if (!quick) return;
      quick.style.display = 'none';
      quick.querySelectorAll('.rich-color-picker-wrap').forEach(function (w) {
        w.classList.remove('open');
      });
    }

    function positionOrdoQuickToolbar() {
      var quick = document.getElementById('ordo-quick-toolbar');
      if (!quick) return;
      var ed = activeEd;
      if (!ed || !document.body.contains(ed)) {
        hideOrdoQuickToolbar();
        return;
      }
      var sel = window.getSelection();
      if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
        hideOrdoQuickToolbar();
        return;
      }
      var range = sel.getRangeAt(0);
      if (!ed.contains(range.commonAncestorContainer)) {
        hideOrdoQuickToolbar();
        return;
      }
      saveSelectionRange();
      var rect = range.getBoundingClientRect();
      if (!rect || (rect.width === 0 && rect.height === 0)) {
        hideOrdoQuickToolbar();
        return;
      }
      quick.style.display = 'flex';
      var top = window.scrollY + rect.top - quick.offsetHeight - 10;
      if (top < window.scrollY + 10) {
        top = window.scrollY + rect.bottom + 10;
      }
      var left = window.scrollX + rect.left + (rect.width / 2) - (quick.offsetWidth / 2);
      var maxLeft = window.scrollX + window.innerWidth - quick.offsetWidth - 10;
      if (left < window.scrollX + 10) left = window.scrollX + 10;
      if (left > maxLeft) left = maxLeft;
      quick.style.top = top + 'px';
      quick.style.left = left + 'px';
    }

    function createOrdoQuickToolbar() {
      var existing = document.getElementById('ordo-quick-toolbar');
      if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
      var quick = document.createElement('div');
      quick.id = 'ordo-quick-toolbar';
      quick.className = 'rich-quick-toolbar';
      quick.innerHTML = ''
        + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="bold" title="Тоўсты" aria-label="Тоўсты"><b>B</b></button>'
        + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="italic" title="Курсіў" aria-label="Курсіў"><i>I</i></button>'
        + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyLeft" title="Улева" aria-label="Улева">L</button>'
        + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyCenter" title="Па цэнтры" aria-label="Па цэнтры">C</button>'
        + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyRight" title="Управа" aria-label="Управа">R</button>'
        + '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyFull" title="Па шырыні" aria-label="Па шырыні">J</button>'
        + '<div class="rich-color-picker-wrap">'
        + '<button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;" title="Абраць колер" aria-label="Абраць колер"></button>'
        + '<div class="rich-color-dropdown" role="group" aria-label="Колер тэксту"></div>'
        + '</div>';
      document.body.appendChild(quick);
      fillColorDropdown(quick.querySelector('.rich-color-dropdown'));
      quick.querySelectorAll('.rich-btn').forEach(function (button) {
        button.addEventListener('mousedown', function (event) {
          event.preventDefault();
          restoreSelectionRange();
        });
        button.addEventListener('click', function () {
          var cmd = button.getAttribute('data-cmd');
          if (!cmd) return;
          runCmd(cmd, null);
        });
      });
      bindColorPickers(quick, true);
      return quick;
    }

    function defaultOrdoTitle(details) {
      return (details && details.getAttribute('data-default-title')) || '';
    }

    function syncTitleDisplayFromInput(details) {
      if (!details) return;
      var inp = details.querySelector('.ordo-section-title-input');
      var span = details.querySelector('.ordo-title-text');
      var ed = details.querySelector('.ordo-body');
      if (!inp || !span) return;
      var d = defaultOrdoTitle(details);
      var v = inp.value.trim();
      span.textContent = v || d;
      if (ed) {
        ed.setAttribute('aria-label', (span.textContent || d).trim());
      }
    }

    function syncInputFromTitleSpan(details) {
      if (!details) return;
      var inp = details.querySelector('.ordo-section-title-input');
      var span = details.querySelector('.ordo-title-text');
      if (!inp || !span) return;
      inp.value = span.textContent.trim() || defaultOrdoTitle(details);
    }

    function closeAllOrdoTitleEdits(exceptDetails) {
      document.querySelectorAll('details.ordo-section--title-editing').forEach(function (det) {
        if (det === exceptDetails) return;
        syncTitleDisplayFromInput(det);
        det.classList.remove('ordo-section--title-editing');
      });
    }

    function bindOrdoTitleEditors(sectionsRoot) {
      if (!sectionsRoot) return;
      sectionsRoot.addEventListener('mousedown', function (e) {
        if (e.target.closest('.ordo-title-edit-btn')) e.preventDefault();
      });
      sectionsRoot.addEventListener('click', function (e) {
        var btn = e.target.closest('.ordo-title-edit-btn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        var det = btn.closest('details.ordo-section');
        if (!det) return;
        var was = det.classList.contains('ordo-section--title-editing');
        if (was) {
          syncTitleDisplayFromInput(det);
          det.classList.remove('ordo-section--title-editing');
          return;
        }
        closeAllOrdoTitleEdits(null);
        syncInputFromTitleSpan(det);
        det.classList.add('ordo-section--title-editing');
        var inp = det.querySelector('.ordo-section-title-input');
        if (inp) {
          window.setTimeout(function () {
            inp.focus();
            try { inp.select(); } catch (err) {}
          }, 0);
        }
      });
      sectionsRoot.addEventListener('blur', function (e) {
        if (!e.target.classList || !e.target.classList.contains('ordo-section-title-input')) return;
        var det = e.target.closest('details.ordo-section');
        if (!det || !det.classList.contains('ordo-section--title-editing')) return;
        syncTitleDisplayFromInput(det);
        det.classList.remove('ordo-section--title-editing');
      }, true);
      sectionsRoot.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!e.target.classList || !e.target.classList.contains('ordo-section-title-input')) return;
        var det = e.target.closest('details.ordo-section');
        if (!det || !det.classList.contains('ordo-section--title-editing')) return;
        e.preventDefault();
        e.stopPropagation();
        syncTitleDisplayFromInput(det);
        det.classList.remove('ordo-section--title-editing');
      });
    }

    document.addEventListener('DOMContentLoaded', function () {
      var initialMap = <?= json_encode($initialB64Map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      var form = document.getElementById('ordo-form');
      var saveBtn = document.getElementById('ordo-save-btn');
      var stack = document.querySelector('.ordo-editor-stack');

      if (!form || !saveBtn || !stack) return;

      bindOrdoTitleEditors(document.getElementById('ordo-sections'));

      var sectionsRoot = document.getElementById('ordo-sections');
      if (sectionsRoot) {
        sectionsRoot.addEventListener('click', function (e) {
          var up = e.target.closest('.ordo-move-up');
          if (up) {
            e.preventDefault();
            e.stopPropagation();
            moveOrdoSection(up.closest('details.ordo-section'), -1);
            return;
          }
          var dn = e.target.closest('.ordo-move-down');
          if (dn) {
            e.preventDefault();
            e.stopPropagation();
            moveOrdoSection(dn.closest('details.ordo-section'), 1);
            return;
          }
          var rm = e.target.closest('.ordo-remove-custom');
          if (rm) {
            e.preventDefault();
            e.stopPropagation();
            var detRm = rm.closest('details.ordo-section');
            if (detRm && detRm.parentNode) {
              detRm.parentNode.removeChild(detRm);
              refreshOrdoMoveButtonsState();
            }
          }
        }, true);
      }

      var addSecBtn = document.getElementById('ordo-add-custom-section');
      if (addSecBtn && sectionsRoot) {
        addSecBtn.addEventListener('click', function () {
          var id = newCustomSectionId();
          var dc = 'Дадатковая частка';
          var html =
            '<details class="ordo-section ordo-section--custom" data-section-kind="custom" data-ordo-key="' + escapeHtmlAttr(id) + '" data-default-title="' + escapeHtmlAttr(dc) + '" open>'
            + '<summary>'
            + '<span class="ordo-section-toolbar" aria-label="Парадак і выдаленне">'
            + '<button type="button" class="ordo-move-btn ordo-move-up" title="Уверх">↑</button>'
            + '<button type="button" class="ordo-move-btn ordo-move-down" title="Уніз">↓</button>'
            + '<button type="button" class="ordo-remove-custom" title="Выдаліць частку" aria-label="Выдаліць частку">×</button>'
            + '</span>'
            + '<span class="ordo-title-row">'
            + '<span class="ordo-title-text">' + escapeHtmlText(dc) + '</span>'
            + '<input type="text" class="ordo-section-title-input" name="ordo_custom_title[' + id + ']" value="" maxlength="255" placeholder="' + escapeHtmlAttr(dc) + '" title="Загаловак дадатковай часткі" aria-label="Загаловак секцыі" autocomplete="off" onclick="event.stopPropagation()" />'
            + '<button type="button" class="ordo-title-edit-btn" title="Рэдагаваць загаловак" aria-label="Рэдагаваць загаловак">'
            + '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>'
            + '</button>'
            + '</span>'
            + '</summary>'
            + '<div id="ordo_editor_' + id + '" class="ordo-body" contenteditable="true" role="textbox" aria-multiline="true" aria-label="' + escapeHtmlAttr(dc) + '" spellcheck="true" data-ordo-key="' + escapeHtmlAttr(id) + '"></div>'
            + '<textarea id="ordo_html_' + id + '" class="ordo-html-hidden" name="ordo_custom_html[' + id + ']" aria-hidden="true"></textarea>'
            + '</details>';
          sectionsRoot.insertAdjacentHTML('beforeend', html);
          var last = sectionsRoot.lastElementChild;
          if (last && last.matches && last.matches('details.ordo-section')) {
            var bodyEl = last.querySelector('.ordo-body');
            if (bodyEl) {
              bodyEl.innerHTML = '<p></p>';
              syncOneEditorToTextarea(bodyEl);
            }
            wireNewOrdoSectionEditors(last);
          }
          refreshOrdoMoveButtonsState();
          closeAllOrdoTitleEdits(null);
        });
      }

      editors().forEach(function (ed) {
        var k = ed.getAttribute('data-ordo-key');
        var html = decodeB64Utf8((initialMap && initialMap[k]) ? initialMap[k] : '');
        ed.innerHTML = html && html.trim() ? html : '<p></p>';
      });
      syncAllToTextareas();
      activeEd = editors()[0] || null;
      refreshOrdoMoveButtonsState();

      stack.addEventListener('focusin', function (e) {
        var t = e.target;
        if (t && t.classList && t.classList.contains('ordo-body')) activeEd = t;
      });

      var mainColorDrop = document.getElementById('ordo-main-color-dropdown');
      fillColorDropdown(mainColorDrop);

      var tb = document.getElementById('ordo-toolbar');
      if (tb) {
        bindColorPickers(tb, false);

        tb.addEventListener('mousedown', function (e) {
          if (e.target.closest('.rich-btn') || e.target.closest('.rich-color-toggle') || e.target.closest('.rich-color-swatch')) {
            e.preventDefault();
          }
        });
        tb.addEventListener('click', function (e) {
          if (e.target.closest('.rich-color-picker-wrap')) return;
          var t = e.target.closest('.rich-btn');
          if (!t) return;
          var action = t.getAttribute('data-action');
          if (action === 'clearBackground') {
            clearBackgroundInSelection();
            return;
          }
          var cmd = t.getAttribute('data-cmd');
          if (!cmd) return;
          var val = t.getAttribute('data-value');
          runCmd(cmd, val);
        });
      }

      createOrdoQuickToolbar();
      setActiveColor('#ffffff');

      editors().forEach(function (ed) {
        ed.addEventListener('input', function () {
          positionOrdoQuickToolbar();
        });
        ed.addEventListener('mouseup', positionOrdoQuickToolbar);
        ed.addEventListener('keyup', positionOrdoQuickToolbar);
        ed.addEventListener('blur', function () {
          window.setTimeout(function () {
            var active = document.activeElement;
            var quick = document.getElementById('ordo-quick-toolbar');
            if (quick && active && quick.contains(active)) return;
            hideOrdoQuickToolbar();
          }, 0);
        });
      });
      document.addEventListener('selectionchange', positionOrdoQuickToolbar);
      window.addEventListener('scroll', positionOrdoQuickToolbar, true);
      window.addEventListener('resize', positionOrdoQuickToolbar);

      document.addEventListener('mousedown', function (event) {
        var inEditor = editors().some(function (ed) {
          return ed.contains(event.target);
        });
        if (inEditor) return;
        if (event.target.closest('.rich-color-picker-wrap')) return;
        closeAllColorPickers(null);
        var quick = document.getElementById('ordo-quick-toolbar');
        if (quick && !quick.contains(event.target)) hideOrdoQuickToolbar();
      });

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        closeAllColorPickers(null);
        hideOrdoQuickToolbar();
      });

      stack.addEventListener('input', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('ordo-body')) syncAllToTextareas();
      });
      stack.addEventListener('blur', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('ordo-body')) syncAllToTextareas();
      }, true);

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        syncAllToTextareas();
        syncOrdoLayoutHiddenInput();
        closeAllOrdoTitleEdits(null);

        var savePath = form.getAttribute('data-save-path') || window.location.pathname;
        var url = savePath.indexOf('/') === 0 ? savePath : ('/' + savePath);

        var fd = new FormData(form);
        fd.set('ordo_save_ajax', '1');
        fd.set('ajax', '1');

        var label = saveBtn.textContent;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Захаванне…';
        clearToasts();

        fetch(url, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
          var ct = (r.headers.get('content-type') || '').toLowerCase();
          if (ct.indexOf('application/json') !== -1) {
            return r.json().then(function (j) { return { kind: 'json', r: r, j: j }; });
          }
          return r.text().then(function (t) { return { kind: 'text', r: r, t: t }; });
        }).then(function (pack) {
          if (pack.kind === 'text') {
            showToast(false, 'Адказ не JSON (код ' + pack.r.status + '). Магчыма, сесія скончылася — абнавіце старонку.');
            return;
          }
          var j = pack.j;
          if (!j || typeof j.ok !== 'boolean') {
            showToast(false, 'Невядомы фармат адказу сервера.');
            return;
          }
          if (j.ok) {
            showToast(true, j.message || 'Захавана.');
          } else {
            showToast(false, j.error || 'Памылка захавання.');
          }
        }).catch(function () {
          showToast(false, 'Не ўдалося звязацца з серверам.');
        }).finally(function () {
          saveBtn.disabled = false;
          saveBtn.textContent = label;
        });
      });
    });
  })();
  </script>
</body>
</html>
