<?php
declare(strict_types=1);

require_once __DIR__ . '/api_public_guard.php';
api_public_guard_enforce();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/liturgy_common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Access-Control-Allow-Origin: *');

try {
    ensureSchemaAndSeed();

    $calDioceseOpts = liturgy_calendar_diocese_options_from_request();

    $fromRaw = trim((string)($_GET['from'] ?? ''));
    $toRaw = trim((string)($_GET['to'] ?? ''));
    $year = (int)($_GET['year'] ?? 0);
    $tz = new DateTimeZone('UTC');

    if ($year >= 1970 && $year <= 2100) {
        $from = new DateTimeImmutable(sprintf('%04d-01-01', $year), $tz);
        $to = new DateTimeImmutable(sprintf('%04d-12-31', $year), $tz);
    } else {
        $from = DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw, $tz);
        $to = DateTimeImmutable::createFromFormat('Y-m-d', $toRaw, $tz);
    }

    if ($from === false || $to === false) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_range',
            'message' => 'Укажыце year=YYYY або пару from/to у фармаце YYYY-MM-DD.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($to < $from) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_range',
            'message' => 'Дата to павінна быць не раней за from.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $maxDays = 370;
    $daysDiff = (int)$from->diff($to)->days;
    if ($daysDiff > $maxDays) {
        http_response_code(400);
        echo json_encode([
            'error' => 'range_too_large',
            'message' => 'Занадта вялікі дыяпазон. Максімум 371 дзень за запыт.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $entries = liturgy_fetch_entries_in_range($from->format('Y-m-d'), $to->format('Y-m-d'));
    $titlesForLectionary = [];
    $cursor = $from;
    while ($cursor <= $to) {
        $k = $cursor->format('Y-m-d');
        $auto = liturgy_auto_day_info($cursor, $calDioceseOpts);
        $entry = $entries[$k] ?? null;
        $effectiveTitle = liturgy_effective_title_for_date($cursor, $entry, $auto);
        $titlesForLectionary[] = $effectiveTitle;
        $optionalTitle = (string)($auto['optional_memorial_title'] ?? '');
        $optionalLookupTitles = [];
        if (is_array($auto['optional_memorial_lookup_titles'] ?? null)) {
            foreach ($auto['optional_memorial_lookup_titles'] as $lookupTitle) {
                if (is_string($lookupTitle) && trim($lookupTitle) !== '') {
                    $optionalLookupTitles[] = $lookupTitle;
                }
            }
        }
        if ($optionalTitle !== '') {
            $titlesForLectionary[] = $optionalTitle;
        }
        foreach ($optionalLookupTitles as $lookupTitle) {
            $titlesForLectionary[] = $lookupTitle;
        }
        $dateLookupTitle = liturgy_christmas_period_date_lookup_title($cursor, (bool)$auto['is_important']);
        if ($dateLookupTitle !== '') {
            $titlesForLectionary[] = $dateLookupTitle;
        }
        $legacyEasterOctave = liturgy_easter_octave_weekday_legacy_lookup_title($cursor);
        if ($legacyEasterOctave !== '') {
            $titlesForLectionary[] = $legacyEasterOctave;
        }
        $cursor = $cursor->modify('+1 day');
    }
    $lectionaryMap = liturgy_fetch_lectionary_map_by_titles($titlesForLectionary);

    $days = [];
    $cursor = $from;
    while ($cursor <= $to) {
        $k = $cursor->format('Y-m-d');
        $auto = liturgy_auto_day_info($cursor, $calDioceseOpts);
        $entry = $entries[$k] ?? null;

        $overrideColor = is_array($entry) ? trim((string)($entry['color_override'] ?? '')) : '';
        $title = liturgy_effective_title_for_date($cursor, $entry, $auto);
        $optionalTitle = (string)($auto['optional_memorial_title'] ?? '');
        $optionalLookupTitles = [];
        if (is_array($auto['optional_memorial_lookup_titles'] ?? null)) {
            foreach ($auto['optional_memorial_lookup_titles'] as $lookupTitle) {
                if (is_string($lookupTitle) && trim($lookupTitle) !== '') {
                    $optionalLookupTitles[] = $lookupTitle;
                }
            }
        }
        $dateLookupTitle = liturgy_christmas_period_date_lookup_title($cursor, (bool)$auto['is_important']);
        $resolvedReadings = liturgy_resolve_readings_text(
            $entry,
            $title,
            $optionalTitle,
            $lectionaryMap,
            $dateLookupTitle,
            $optionalLookupTitles,
            $cursor
        );
        $effectiveColor = liturgy_resolve_liturgical_color_for_day(
            $cursor,
            $overrideColor,
            (string)($resolvedReadings['liturgical_color'] ?? ''),
            (string)$auto['color']
        );

        $days[] = [
            'date' => $k,
            'title' => liturgy_title_with_weekday_for_display($cursor, $title),
            'auto_title' => liturgy_title_with_weekday_for_display($cursor, (string)$auto['title']),
            'is_important' => (bool)$auto['is_important'],
            'liturgical_color' => liturgy_color_name($effectiveColor),
            'liturgical_color_hex' => liturgy_color_hex($effectiveColor),
            'readings' => $resolvedReadings['readings_full'],
            'readings_full' => $resolvedReadings['readings_full'],
            'updated_at' => is_array($entry) ? (string)($entry['updated_at'] ?? '') : '',
        ];

        $cursor = $cursor->modify('+1 day');
    }

    echo json_encode([
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'count' => count($days),
        'days' => $days,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'liturgy_calendar_range_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

