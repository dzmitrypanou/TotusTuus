<?php
declare(strict_types=1);

/**
 * Літургічныя святы / успаміны з табліцы liturgy_observances (рэдагаванне праз адмінку).
 */

require_once __DIR__ . '/db.php';

/**
 * Скінуць кэш радкоў (пасля змен у адмінцы).
 */
function liturgy_observances_invalidate_cache(): void
{
    $GLOBALS['__liturgy_obs_cache_gen'] = (int)($GLOBALS['__liturgy_obs_cache_gen'] ?? 0) + 1;
}

/**
 * @return array<string, bool>
 */
function liturgy_observances_csv_to_opts(string $csv): array
{
    $out = [];
    foreach (explode(',', $csv) as $part) {
        $k = strtolower(trim($part));
        if ($k !== '') {
            $out[$k] = true;
        }
    }

    return $out;
}

/**
 * @param array<string, bool> $needKeys
 */
function liturgy_observances_opts_has_any(array $dioceseOpts, array $needKeys): bool
{
    foreach ($needKeys as $k => $_) {
        if (!empty($dioceseOpts[$k])) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, bool> $needKeys
 */
function liturgy_observances_opts_has_all(array $dioceseOpts, array $needKeys): bool
{
    if ($needKeys === []) {
        return true;
    }
    foreach ($needKeys as $k => $_) {
        if (empty($dioceseOpts[$k])) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, bool> $dioceseOpts
 */
function liturgy_observances_row_matches_diocese(array $row, array $dioceseOpts): bool
{
    $anyReq = liturgy_observances_csv_to_opts((string)($row['require_any_of'] ?? ''));
    if ($anyReq !== [] && !liturgy_observances_opts_has_any($dioceseOpts, $anyReq)) {
        return false;
    }
    $allReq = liturgy_observances_csv_to_opts((string)($row['require_all_of'] ?? ''));
    if ($allReq !== [] && !liturgy_observances_opts_has_all($dioceseOpts, $allReq)) {
        return false;
    }
    $forbid = liturgy_observances_csv_to_opts((string)($row['forbid_if_any_of'] ?? ''));
    if ($forbid !== [] && liturgy_observances_opts_has_any($dioceseOpts, $forbid)) {
        return false;
    }

    return true;
}

function liturgy_observances_easter_sunday(int $year): DateTimeImmutable
{
    $base = new DateTimeImmutable(sprintf('%04d-03-21', $year), new DateTimeZone('UTC'));
    $offset = easter_days($year, CAL_EASTER_ALWAYS_GREGORIAN);

    return $base->modify(sprintf('%+d day', $offset));
}

function liturgy_observances_first_advent_sunday(int $year): DateTimeImmutable
{
    $start = new DateTimeImmutable(sprintf('%04d-11-27', $year), new DateTimeZone('UTC'));
    $dow = (int)$start->format('w');

    return $start->modify(sprintf('%+d day', (7 - $dow) % 7));
}

function liturgy_observances_epiphany_observance_date(int $year): DateTimeImmutable
{
    $tz = new DateTimeZone('UTC');
    $defaults = [
        'epiphany_transfer_to_sunday' => false,
        'ascension_on_sunday' => false,
        'corpus_christi_on_sunday' => false,
    ];
    $path = __DIR__ . '/liturgy_calendar_config.php';
    $fromFile = is_readable($path) ? include $path : [];
    $cfg = array_merge($defaults, is_array($fromFile) ? $fromFile : []);

    $jan6 = new DateTimeImmutable(sprintf('%04d-01-06', $year), $tz);
    if (empty($cfg['epiphany_transfer_to_sunday'])) {
        return $jan6;
    }
    for ($day = 2; $day <= 8; $day++) {
        $d = new DateTimeImmutable(sprintf('%04d-01-%02d', $year, $day), $tz);
        if ((int)$d->format('w') === 0) {
            return $d;
        }
    }

    return $jan6;
}

function liturgy_observances_infer_optional_color(string $title): string
{
    $t = mb_strtolower(trim($title), 'UTF-8');
    if ($t === '') {
        return 'white';
    }
    if (str_contains($t, 'памерл') || str_contains($t, 'усех памерлых')) {
        return 'black';
    }
    if (preg_match('/мучан/u', $t) === 1) {
        return 'red';
    }

    return 'white';
}

function liturgy_observances_normalize_color(string $raw): string
{
    $c = strtolower(trim($raw));
    return in_array($c, ['green', 'red', 'purple', 'white', 'rose', 'black'], true) ? $c : '';
}

/**
 * @param array<string, mixed> $row
 */
function liturgy_observances_resolve_ymd(array $row, int $year, DateTimeImmutable $easter): ?string
{
    $rule = (string)($row['rule_type'] ?? 'fixed_md');

    if ($rule === 'fixed_md') {
        $m = (int)($row['month'] ?? 0);
        $d = (int)($row['day'] ?? 0);
        if ($m < 1 || $m > 12 || $d < 1 || $d > 31) {
            return null;
        }
        if (!checkdate($m, $d, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $m, $d);
    }

    if ($rule === 'easter_offset') {
        $off = (int)($row['easter_offset'] ?? 0);
        $date = $easter->modify(sprintf('%+d day', $off));

        return $date->format('Y-m-d');
    }

    if ($rule === 'epiphany_observed') {
        $obs = liturgy_observances_epiphany_observance_date($year);

        return $obs->format('Y-m-d');
    }

    if ($rule === 'advent_offset') {
        $off = (int)($row['advent_offset_days'] ?? 0);
        $firstAdvent = liturgy_observances_first_advent_sunday($year);
        $date = $firstAdvent->modify(sprintf('%+d day', $off));

        return $date->format('Y-m-d');
    }

    return null;
}

/**
 * @param array<string, mixed> $row
 * @return array{title:string,color:string,is_important:true,source:string,rank?:string}
 */
function liturgy_observances_row_to_important_entry(array $row): array
{
    $src = (string)($row['source_tag'] ?? 'fixed');
    $rank = trim((string)($row['regional_rank'] ?? ''));
    /** @var array{title:string,color:string,is_important:true,source:string,rank?:string} $out */
    $out = [
        'title' => (string)$row['title'],
        'color' => (string)$row['liturgical_color'],
        'is_important' => true,
        'source' => $src,
    ];
    if ($rank !== '') {
        $out['rank'] = $rank;
    }

    return $out;
}

function liturgy_observances_rank_weight(string $rank): int
{
    return match ($rank) {
        'solemnity' => 4,
        'feast' => 3,
        'memorial' => 2,
        default => 1,
    };
}

/**
 * @return list<array<string, mixed>>
 */
function liturgy_observances_fetch_active_rows(): array
{
    static $gen = -1;
    static $cache = null;
    $g = (int)($GLOBALS['__liturgy_obs_cache_gen'] ?? 0);
    if ($g !== $gen) {
        $gen = $g;
        $cache = null;
    }
    if (is_array($cache)) {
        return $cache;
    }
    $stmt = db()->query(
        'SELECT *
         FROM liturgy_observances
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $cache = $stmt->fetchAll();

    return $cache;
}

/**
 * @param array<string, bool> $dioceseOpts
 * @return array<string, array{title:string,color:string,is_important:bool,source:string,rank?:string}>
 */
function liturgy_observances_build_important_map(int $year, array $dioceseOpts): array
{
    $easter = liturgy_observances_easter_sunday($year);
    /** @var array<string, list<array{pri:int,rank_w:int,entry:array}>> $buckets */
    $buckets = [];
    foreach (liturgy_observances_fetch_active_rows() as $row) {
        if ((string)($row['observance_kind'] ?? '') === 'patch') {
            continue;
        }
        if ((string)($row['observance_kind'] ?? '') !== 'important') {
            continue;
        }
        if (trim((string)($row['title'] ?? '')) === '') {
            continue;
        }
        if (!liturgy_observances_row_matches_diocese($row, $dioceseOpts)) {
            continue;
        }
        $ymd = liturgy_observances_resolve_ymd($row, $year, $easter);
        if ($ymd === null) {
            continue;
        }
        $pri = (int)($row['match_priority'] ?? 0);
        $rankStr = trim((string)($row['regional_rank'] ?? ''));
        $rw = liturgy_observances_rank_weight($rankStr);
        $entry = liturgy_observances_row_to_important_entry($row);
        $buckets[$ymd][] = ['pri' => $pri, 'rank_w' => $rw, 'entry' => $entry];
    }

    $result = [];
    foreach ($buckets as $ymd => $list) {
        usort($list, static function (array $a, array $b): int {
            $c = $b['pri'] <=> $a['pri'];
            if ($c !== 0) {
                return $c;
            }
            $c2 = $b['rank_w'] <=> $a['rank_w'];
            if ($c2 !== 0) {
                return $c2;
            }

            return 0;
        });
        $result[$ymd] = $list[0]['entry'];
    }

    return $result;
}

/**
 * @param array<string, bool> $dioceseOpts
 * @return array<string, array{title:string,color:string,colors?:list<string>,optional_title_prefix_auto?:list<bool>}>
 */
function liturgy_observances_build_optional_map(int $year, array $dioceseOpts): array
{
    $easter = liturgy_observances_easter_sunday($year);
    /** @var array<string, list<array{title:string,color:string,prefix_auto:bool}>> $branchesByDate */
    $branchesByDate = [];
    foreach (liturgy_observances_fetch_active_rows() as $row) {
        if ((string)($row['observance_kind'] ?? '') === 'patch') {
            continue;
        }
        if ((string)($row['observance_kind'] ?? '') !== 'optional') {
            continue;
        }
        if (!liturgy_observances_row_matches_diocese($row, $dioceseOpts)) {
            continue;
        }
        $ymd = liturgy_observances_resolve_ymd($row, $year, $easter);
        if ($ymd === null) {
            continue;
        }
        $t = trim((string)$row['title']);
        if ($t === '') {
            continue;
        }
        $dbColor = liturgy_observances_normalize_color((string)($row['liturgical_color'] ?? ''));
        $resolvedColor = $dbColor !== '' ? $dbColor : liturgy_observances_infer_optional_color($t);
        $prefixAuto = ((int)($row['optional_title_prefix_auto'] ?? 1)) !== 0;
        if (!isset($branchesByDate[$ymd])) {
            $branchesByDate[$ymd] = [];
        }
        $seen = false;
        foreach ($branchesByDate[$ymd] as $ex) {
            if ($ex['title'] === $t) {
                $seen = true;
                break;
            }
        }
        if (!$seen) {
            $branchesByDate[$ymd][] = [
                'title' => $t,
                'color' => $resolvedColor,
                'prefix_auto' => $prefixAuto,
            ];
        }
    }

    $result = [];
    foreach ($branchesByDate as $ymd => $items) {
        $titles = array_column($items, 'title');
        $colors = array_column($items, 'color');
        $prefixAutos = array_column($items, 'prefix_auto');
        if ($titles === []) {
            continue;
        }
        if (count($titles) === 1) {
            $merged = $titles[0];
        } else {
            $merged = implode(' альбо ', $titles);
        }
        $result[$ymd] = [
            'title' => $merged,
            'color' => $colors[0] ?? liturgy_observances_infer_optional_color($merged),
            'colors' => $colors,
            'optional_title_prefix_auto' => $prefixAutos,
        ];
    }

    return $result;
}

/**
 * @param array<string, array<string,mixed>> $important
 * @param array<string, bool> $dioceseOpts
 */
function liturgy_observances_apply_title_patches(int $year, array $dioceseOpts, array &$important): void
{
    $Y = sprintf('%04d', $year);
    foreach (liturgy_observances_fetch_active_rows() as $row) {
        $patchMd = trim((string)($row['patch_append_to_mmdd'] ?? ''));
        $suffix = trim((string)($row['patch_suffix'] ?? ''));
        if ($patchMd === '' || $suffix === '') {
            continue;
        }
        if (preg_match('/^\d{2}-\d{2}$/', $patchMd) !== 1) {
            continue;
        }
        if (!liturgy_observances_row_matches_diocese($row, $dioceseOpts)) {
            continue;
        }
        $ymd = $Y . '-' . $patchMd;
        if (!isset($important[$ymd])) {
            continue;
        }
        $t = trim((string)($important[$ymd]['title'] ?? ''));
        if ($t === '') {
            continue;
        }
        $needleLen = min(20, mb_strlen($suffix, 'UTF-8'));
        if ($needleLen > 0 && mb_stripos($t, mb_substr($suffix, 0, $needleLen, 'UTF-8'), 0, 'UTF-8') !== false) {
            continue;
        }
        $important[$ymd]['title'] = $t . $suffix;
    }
}

function liturgy_observances_count(): int
{
    $stmt = db()->query('SELECT COUNT(*) AS c FROM liturgy_observances');

    return (int)($stmt->fetch()['c'] ?? 0);
}
