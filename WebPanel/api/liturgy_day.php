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

    $dateRaw = trim((string)($_GET['date'] ?? ''));
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, new DateTimeZone('UTC'));
    if ($date === false || $date->format('Y-m-d') !== $dateRaw) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_date',
            'message' => 'Параметр date должен быть в формате YYYY-MM-DD.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $entry = liturgy_fetch_entry_for_date($dateRaw);
    $auto = liturgy_auto_day_info($date, $calDioceseOpts);
    $overrideColor = is_array($entry) ? trim((string)($entry['color_override'] ?? '')) : '';
    $effectiveTitle = liturgy_effective_title_for_date($date, $entry, $auto);
    $optionalMemorialTitle = (string)($auto['optional_memorial_title'] ?? '');
    $optionalLookupTitles = [];
    if (is_array($auto['optional_memorial_lookup_titles'] ?? null)) {
        foreach ($auto['optional_memorial_lookup_titles'] as $lookupTitle) {
            if (is_string($lookupTitle) && trim($lookupTitle) !== '') {
                $optionalLookupTitles[] = $lookupTitle;
            }
        }
    }
    $dateLookupTitle = liturgy_christmas_period_date_lookup_title($date, (bool)$auto['is_important']);
    $titlesForLookup = [$effectiveTitle, $optionalMemorialTitle];
    foreach ($optionalLookupTitles as $lookupTitle) {
        $titlesForLookup[] = $lookupTitle;
    }
    if ($dateLookupTitle !== '') {
        $titlesForLookup[] = $dateLookupTitle;
    }
    $legacyEasterOctave = liturgy_easter_octave_weekday_legacy_lookup_title($date);
    if ($legacyEasterOctave !== '') {
        $titlesForLookup[] = $legacyEasterOctave;
    }
    $lectionaryMap = liturgy_fetch_lectionary_map_by_titles($titlesForLookup);
    $resolvedReadings = liturgy_resolve_readings_text(
        $entry,
        $effectiveTitle,
        $optionalMemorialTitle,
        $lectionaryMap,
        $dateLookupTitle,
        $optionalLookupTitles,
        $date
    );
    $effectiveColor = liturgy_resolve_liturgical_color_for_day(
        $date,
        $overrideColor,
        (string)($resolvedReadings['liturgical_color'] ?? ''),
        (string)$auto['color']
    );
    echo json_encode([
        'date' => $dateRaw,
        'title' => liturgy_title_with_weekday_for_display($date, $effectiveTitle),
        'auto_title' => liturgy_title_with_weekday_for_display($date, (string)$auto['title']),
        'is_important' => (bool)$auto['is_important'],
        'has_optional_memorial' => (bool)($auto['has_optional_memorial'] ?? false),
        'optional_memorial_title' => liturgy_format_optional_memorial_title_for_display(
            $date,
            $optionalMemorialTitle,
            $optionalLookupTitles
        ),
        'optional_memorial_colors' => is_array($auto['optional_memorial_colors'] ?? null)
            ? array_values($auto['optional_memorial_colors'])
            : [],
        'optional_memorial_color' => (string)($auto['optional_memorial_color'] ?? ''),
        'liturgical_color' => liturgy_color_name($effectiveColor),
        'liturgical_color_hex' => liturgy_color_hex($effectiveColor),
        'readings' => $resolvedReadings['readings_full'],
        'readings_full' => $resolvedReadings['readings_full'],
        'lectionary_key' => $resolvedReadings['lectionary_key'],
        'lectionary_source' => $resolvedReadings['lectionary_source'],
        'updated_at' => is_array($entry) ? (string)($entry['updated_at'] ?? '') : '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'liturgy_day_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

