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

    $year = (int)($_GET['year'] ?? (int)date('Y'));
    $month = (int)($_GET['month'] ?? (int)date('n'));
    if ($year < 1970 || $year > 2100) {
        $year = (int)date('Y');
    }
    if ($month < 1 || $month > 12) {
        $month = (int)date('n');
    }

    $tz = new DateTimeZone('UTC');
    $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
    $offset = (int)$firstOfMonth->format('w'); // 0=Sunday
    $gridStart = $firstOfMonth->modify(sprintf('-%d day', $offset));
    $gridEnd = $gridStart->modify('+41 day');

    $entries = liturgy_fetch_entries_in_range($gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));
    $titlesForLectionary = [];
    for ($i = 0; $i < 42; $i++) {
        $d = $gridStart->modify(sprintf('+%d day', $i));
        $auto = liturgy_auto_day_info($d, $calDioceseOpts);
        $k = $d->format('Y-m-d');
        $entry = $entries[$k] ?? null;
        $effectiveTitle = liturgy_effective_title_for_date($d, $entry, $auto);
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
        $dateLookupTitle = liturgy_christmas_period_date_lookup_title($d, (bool)$auto['is_important']);
        if ($dateLookupTitle !== '') {
            $titlesForLectionary[] = $dateLookupTitle;
        }
        $legacyEasterOctave = liturgy_easter_octave_weekday_legacy_lookup_title($d);
        if ($legacyEasterOctave !== '') {
            $titlesForLectionary[] = $legacyEasterOctave;
        }
    }
    $lectionaryMap = liturgy_fetch_lectionary_map_by_titles($titlesForLectionary);

    $days = [];
    for ($i = 0; $i < 42; $i++) {
        $d = $gridStart->modify(sprintf('+%d day', $i));
        $auto = liturgy_auto_day_info($d, $calDioceseOpts);
        $k = $d->format('Y-m-d');
        $entry = $entries[$k] ?? null;

        $overrideColor = '';
        if (is_array($entry)) {
            $overrideColor = trim((string)($entry['color_override'] ?? ''));
        }
        $title = liturgy_effective_title_for_date($d, $entry, $auto);
        $optionalTitle = (string)($auto['optional_memorial_title'] ?? '');
        $optionalLookupTitles = [];
        if (is_array($auto['optional_memorial_lookup_titles'] ?? null)) {
            foreach ($auto['optional_memorial_lookup_titles'] as $lookupTitle) {
                if (is_string($lookupTitle) && trim($lookupTitle) !== '') {
                    $optionalLookupTitles[] = $lookupTitle;
                }
            }
        }
        $optionalMemorialPrefixAuto = [];
        if (is_array($auto['optional_memorial_prefix_auto'] ?? null)) {
            foreach ($auto['optional_memorial_prefix_auto'] as $pa) {
                $optionalMemorialPrefixAuto[] = (bool)$pa;
            }
        }
        $dateLookupTitle = liturgy_christmas_period_date_lookup_title($d, (bool)$auto['is_important']);
        $resolvedReadings = liturgy_resolve_readings_text(
            $entry,
            $title,
            $optionalTitle,
            $lectionaryMap,
            $dateLookupTitle,
            $optionalLookupTitles,
            $d,
            $optionalMemorialPrefixAuto
        );
        $effectiveColor = liturgy_resolve_liturgical_color_for_day(
            $d,
            $overrideColor,
            (string)($resolvedReadings['liturgical_color'] ?? ''),
            (string)$auto['color']
        );

        $hasContent = false;
        if ($resolvedReadings['readings_full'] !== '') {
            $hasContent = true;
        } elseif (is_array($entry) && trim((string)($entry['title_override'] ?? '')) !== '') {
            $hasContent = true;
        }

        $days[] = [
            'date' => $k,
            'day' => (int)$d->format('j'),
            'is_current_month' => (int)$d->format('n') === $month,
            'is_today' => $k === (new DateTimeImmutable('now', $tz))->format('Y-m-d'),
            'is_important' => (bool)$auto['is_important'],
            'has_optional_memorial' => (bool)($auto['has_optional_memorial'] ?? false),
            'optional_memorial_title' => liturgy_format_optional_memorial_title_for_display(
                $d,
                $optionalTitle,
                $optionalLookupTitles,
                $optionalMemorialPrefixAuto
            ),
            'optional_memorial_colors' => is_array($auto['optional_memorial_colors'] ?? null)
                ? array_values($auto['optional_memorial_colors'])
                : [],
            'optional_memorial_color' => (string)($auto['optional_memorial_color'] ?? ''),
            'title' => liturgy_title_with_weekday_for_display($d, $title),
            'liturgical_color' => liturgy_color_name($effectiveColor),
            'liturgical_color_hex' => liturgy_color_hex($effectiveColor),
            'has_content' => $hasContent,
        ];
    }

    echo json_encode([
        'year' => $year,
        'month' => $month,
        'grid_start' => $gridStart->format('Y-m-d'),
        'grid_end' => $gridEnd->format('Y-m-d'),
        'days' => $days,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'liturgy_calendar_month_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

