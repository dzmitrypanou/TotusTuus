<?php
declare(strict_types=1);

/**
 * Секцыі Ordo Missae: парадак для API і рэдактара.
 *
 * @return list<array{key: string, column: string, title_column: string, label: string}>
 */
function ordo_missae_section_defs(): array
{
    return [
        ['key' => 'intro', 'column' => 'html_intro', 'title_column' => 'title_intro', 'label' => 'Уступныя абрады'],
        ['key' => 'liturgy_word', 'column' => 'html_liturgy_word', 'title_column' => 'title_liturgy_word', 'label' => 'Літургія слова'],
        ['key' => 'eucharist', 'column' => 'html_eucharist', 'title_column' => 'title_eucharist', 'label' => 'Эўхарыстычная літургія'],
        ['key' => 'eucharist_prayer2', 'column' => 'html_eucharist_prayer2', 'title_column' => 'title_eucharist_prayer2', 'label' => 'Другая Эўхарыстычная малітва'],
        ['key' => 'communion', 'column' => 'html_communion', 'title_column' => 'title_communion', 'label' => 'Абрад Камуніі'],
        ['key' => 'closing', 'column' => 'html_closing', 'title_column' => 'title_closing', 'label' => 'Абрад заканчэння'],
    ];
}

/** @return array<string, array{key: string, column: string, title_column: string, label: string}> */
function ordo_missae_section_defs_by_key(): array
{
    $m = [];
    foreach (ordo_missae_section_defs() as $d) {
        $m[$d['key']] = $d;
    }

    return $m;
}

function ordo_missae_def_by_key(string $key): ?array
{
    $m = ordo_missae_section_defs_by_key();

    return $m[$key] ?? null;
}

function ordo_missae_custom_id_valid(string $id): bool
{
    return (bool) preg_match('/^c_[a-zA-Z0-9]{8,48}$/', $id);
}

function ordo_missae_generate_custom_id(): string
{
    return 'c_' . bin2hex(random_bytes(8));
}

/** Прадвызначаны парадак: усе ўбудаваныя секцыі ў фіксаваным парадку, без кастамных. */
function ordo_missae_default_layout_array(): array
{
    return [
        'v' => 1,
        'order' => array_map(static function ($d) {
            return ['type' => 'built_in', 'key' => $d['key']];
        }, ordo_missae_section_defs()),
        'custom' => [],
    ];
}

/**
 * Нармалізацыя структуры з БД або POST.
 *
 * @return array{v: int, order: list<array{type: string, key?: string, id?: string}>, custom: array<string, array{title: string, html: string}>}
 */
function ordo_missae_normalize_layout(array $j): array
{
    $validBuiltInKeys = [];
    foreach (ordo_missae_section_defs() as $d) {
        $validBuiltInKeys[$d['key']] = true;
    }

    $order = [];
    $seenBuiltIn = [];
    $customOut = [];

    $incomingOrder = $j['order'] ?? [];
    if (!is_array($incomingOrder)) {
        $incomingOrder = [];
    }

    foreach ($incomingOrder as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $type = (string) ($slot['type'] ?? '');
        if ($type === 'built_in') {
            $key = (string) ($slot['key'] ?? '');
            if ($key === '' || !isset($validBuiltInKeys[$key])) {
                continue;
            }
            if (isset($seenBuiltIn[$key])) {
                continue;
            }
            $seenBuiltIn[$key] = true;
            $order[] = ['type' => 'built_in', 'key' => $key];
        } elseif ($type === 'custom') {
            $id = (string) ($slot['id'] ?? '');
            if (!ordo_missae_custom_id_valid($id)) {
                continue;
            }
            $order[] = ['type' => 'custom', 'id' => $id];
            if (!isset($customOut[$id])) {
                $customOut[$id] = ['title' => '', 'html' => ''];
            }
        }
    }

    $incomingCustom = $j['custom'] ?? [];
    if (is_array($incomingCustom)) {
        foreach ($incomingCustom as $cid => $block) {
            $cid = (string) $cid;
            if (!ordo_missae_custom_id_valid($cid)) {
                continue;
            }
            if (!is_array($block)) {
                continue;
            }
            $customOut[$cid] = [
                'title' => (string) ($block['title'] ?? ''),
                'html' => (string) ($block['html'] ?? ''),
            ];
        }
    }

    foreach ($order as $slot) {
        if (($slot['type'] ?? '') === 'custom') {
            $id = (string) ($slot['id'] ?? '');
            if (!isset($customOut[$id])) {
                $customOut[$id] = ['title' => '', 'html' => ''];
            }
        }
    }

    if ($order === []) {
        return ordo_missae_default_layout_array();
    }

    return [
        'v' => 1,
        'order' => $order,
        'custom' => $customOut,
    ];
}

/**
 * @param array<string, mixed> $row радок panel_ordo_missae
 *
 * @return array{v: int, order: list<array{type: string, key?: string, id?: string}>, custom: array<string, array{title: string, html: string}>}
 */
function ordo_missae_layout_from_db_row(array $row): array
{
    $raw = trim((string) ($row['ordo_layout_json'] ?? ''));
    if ($raw === '') {
        return ordo_missae_default_layout_array();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ordo_missae_default_layout_array();
    }

    return ordo_missae_normalize_layout($decoded);
}

/** Тэкст загалоўка для публічнага HTML: кастам з БД або прадвызначаны label. */
function ordo_missae_effective_section_title(array $row, array $d): string
{
    $raw = trim((string) ($row[$d['title_column']] ?? ''));

    return $raw !== '' ? $raw : $d['label'];
}

/**
 * Значэнне для слупка title_*: пуста = прадвызначаны label пры паказе.
 * Калі ў полі ўвялі роўна тэкст прадвызначэння — таксама захоўваем як пуста.
 */
function ordo_missae_title_storage_from_post(string $post, string $defaultLabel): string
{
    $t = trim($post);
    if ($t === '' || $t === $defaultLabel) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($t, 0, 255);
    }

    return substr($t, 0, 255);
}

function ordo_missae_sanitize_custom_title(string $title): string
{
    $t = trim($title);
    if (function_exists('mb_substr')) {
        return mb_substr($t, 0, 255);
    }

    return substr($t, 0, 255);
}

function ordo_missae_emit_details_section(string $dataOrdoKey, string $titleText, string $chunkHtml): string
{
    if (trim($chunkHtml) === '') {
        return '';
    }
    $title = htmlspecialchars($titleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sk = htmlspecialchars($dataOrdoKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<details class="ordo-missae-section" data-ordo-section="' . $sk . '">'
        . '<summary class="ordo-missae-section-summary"><span class="ordo-missae-section-title">' . $title . '</span></summary>'
        . "\n" . $chunkHtml . "\n"
        . '</details>';
}

/**
 * Стары парадак (усё падрад па defs) — для сумяшчальнасці, калі layout яшчэ не выкарыстоўваецца.
 *
 * @param array<string, string> $partsByKey
 * @param array<string, string> $titlesByKey
 */
function ordo_missae_html_with_section_headings(array $partsByKey, array $titlesByKey): string
{
    $buf = '';
    foreach (ordo_missae_section_defs() as $d) {
        $chunk = (string) ($partsByKey[$d['key']] ?? '');
        if (trim($chunk) === '') {
            continue;
        }
        $titleText = (string) ($titlesByKey[$d['key']] ?? $d['label']);
        if (trim($titleText) === '') {
            $titleText = $d['label'];
        }
        $buf .= ordo_missae_emit_details_section($d['key'], $titleText, $chunk);
    }

    return $buf;
}

/**
 * @param array<string, mixed> $row
 * @param array{v: int, order: list, custom: array<string, array{title: string, html: string}>} $layout
 */
function ordo_missae_public_html_from_layout(array $row, array $layout): string
{
    $buf = '';
    foreach ($layout['order'] as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        if (($slot['type'] ?? '') === 'built_in') {
            $key = (string) ($slot['key'] ?? '');
            $d = ordo_missae_def_by_key($key);
            if (!$d) {
                continue;
            }
            $chunk = (string) ($row[$d['column']] ?? '');
            $title = ordo_missae_effective_section_title($row, $d);
            $buf .= ordo_missae_emit_details_section($key, $title, $chunk);
        } elseif (($slot['type'] ?? '') === 'custom') {
            $id = (string) ($slot['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $block = $layout['custom'][$id] ?? ['title' => '', 'html' => ''];
            $chunk = (string) ($block['html'] ?? '');
            $title = trim((string) ($block['title'] ?? ''));
            if ($title === '') {
                $title = 'Дадатковая частка';
            }
            $buf .= ordo_missae_emit_details_section($id, $title, $chunk);
        }
    }

    return $buf;
}

/**
 * Публічны HTML: парадак з layout; калі ўсё пуста — рэзерв з слупка html.
 *
 * @param array<string, mixed> $row
 */
function ordo_missae_public_html_from_row(array $row): string
{
    $layout = ordo_missae_layout_from_db_row($row);
    $html = ordo_missae_public_html_from_layout($row, $layout);
    if (trim($html) !== '') {
        return $html;
    }

    $parts = [];
    $any = false;
    foreach (ordo_missae_section_defs() as $d) {
        $chunk = (string) ($row[$d['column']] ?? '');
        $parts[$d['key']] = $chunk;
        if (trim($chunk) !== '') {
            $any = true;
        }
    }
    if ($any) {
        $titles = [];
        foreach (ordo_missae_section_defs() as $d) {
            $titles[$d['key']] = ordo_missae_effective_section_title($row, $d);
        }

        return ordo_missae_html_with_section_headings($parts, $titles);
    }

    return (string) ($row['html'] ?? '');
}

/**
 * @param array<string, string> $partsByKey
 * @param array<string, string> $titlesByKey
 * @param array{v: int, order: list, custom: array<string, array{title: string, html: string}>} $layout
 */
function ordo_missae_merged_html_for_legacy_column(array $partsByKey, array $titlesByKey, array $layout): string
{
    $buf = '';
    foreach ($layout['order'] as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        if (($slot['type'] ?? '') === 'built_in') {
            $key = (string) ($slot['key'] ?? '');
            $d = ordo_missae_def_by_key($key);
            if (!$d) {
                continue;
            }
            $chunk = (string) ($partsByKey[$key] ?? '');
            $titleText = (string) ($titlesByKey[$key] ?? $d['label']);
            if (trim($titleText) === '') {
                $titleText = $d['label'];
            }
            $buf .= ordo_missae_emit_details_section($key, $titleText, $chunk);
        } elseif (($slot['type'] ?? '') === 'custom') {
            $id = (string) ($slot['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $block = $layout['custom'][$id] ?? ['title' => '', 'html' => ''];
            $chunk = (string) ($block['html'] ?? '');
            $title = trim((string) ($block['title'] ?? ''));
            if ($title === '') {
                $title = 'Дадатковая частка';
            }
            $buf .= ordo_missae_emit_details_section($id, $title, $chunk);
        }
    }

    return $buf;
}
