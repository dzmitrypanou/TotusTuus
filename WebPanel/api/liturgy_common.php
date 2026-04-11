<?php
declare(strict_types=1);

/**
 * Аўтавылічэнне літургічнага календара (важныя даты + колер дня)
 * і дапаможныя метады для API/адмінкі.
 */

require_once __DIR__ . '/liturgy_particular_calendar.php';
require_once __DIR__ . '/liturgy_observances_lib.php';

/**
 * @return array{
 *   epiphany_transfer_to_sunday:bool,
 *   ascension_on_sunday:bool,
 *   corpus_christi_on_sunday:bool
 * }
 */
function liturgy_calendar_transfers(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $defaults = [
        'epiphany_transfer_to_sunday' => false,
        'ascension_on_sunday' => false,
        'corpus_christi_on_sunday' => false,
    ];
    $path = __DIR__ . '/liturgy_calendar_config.php';
    $fromFile = is_readable($path) ? include $path : [];
    $cache = array_merge($defaults, is_array($fromFile) ? $fromFile : []);

    return $cache;
}

/** Дата ўрачыстасці Аб'яўлення (6 студзеня або нядзеля 2–8.01 пры пераносе). */
function liturgy_epiphany_observance_date(int $year): DateTimeImmutable
{
    $tz = new DateTimeZone('UTC');
    $jan6 = new DateTimeImmutable(sprintf('%04d-01-06', $year), $tz);
    $cfg = liturgy_calendar_transfers();
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

function liturgy_color_palette(): array
{
    return [
        'green' => '#2E7D32',
        'red' => '#C62828',
        'purple' => '#6A1B9A',
        'white' => '#E5E7EB',
        'rose' => '#F48FB1',
        'black' => '#374151',
    ];
}

function liturgy_color_name(string $color): string
{
    return match ($color) {
        'green' => 'green',
        'red' => 'red',
        'purple' => 'purple',
        'white' => 'white',
        'rose' => 'rose',
        'black' => 'black',
        default => 'green',
    };
}

function liturgy_color_hex(string $color): string
{
    $palette = liturgy_color_palette();
    return $palette[$color] ?? $palette['green'];
}

function liturgy_easter_sunday(int $year): DateTimeImmutable
{
    // Important: avoid timestamp-based conversion (easter_date),
    // which may shift by one day depending on server timezone.
    $base = new DateTimeImmutable(sprintf('%04d-03-21', $year), new DateTimeZone('UTC'));
    $offset = easter_days($year, CAL_EASTER_ALWAYS_GREGORIAN);
    return $base->modify(sprintf('+%d day', $offset));
}

/** Вялікі панядзелак — Вялікдзень уключна (для загалоўкаў без «Панядзелак — …» у календары і API). */
function liturgy_is_great_monday_through_easter_sunday(DateTimeImmutable $date): bool
{
    $easter = liturgy_easter_sunday((int)$date->format('Y'));
    $greatMonday = $easter->modify('-6 day');
    $ymd = $date->format('Y-m-d');

    return $ymd >= $greatMonday->format('Y-m-d') && $ymd <= $easter->format('Y-m-d');
}

function liturgy_first_advent_sunday(int $year): DateTimeImmutable
{
    $start = new DateTimeImmutable(sprintf('%04d-11-27', $year), new DateTimeZone('UTC'));
    $dow = (int)$start->format('w'); // 0=Sun
    return $start->modify(sprintf('+%d day', (7 - $dow) % 7));
}

function liturgy_baptism_of_lord(int $year): DateTimeImmutable
{
    // Пасля даты Аб'яўлення (гл. liturgy_epiphany_observance_date): калі Аб'яўленне ў нядзелю — Крэшчанне ў панядзелак;
    // інакш — бліжэйшая нядзеля пасля гэтай даты.
    $epiphany = liturgy_epiphany_observance_date($year);
    $dow = (int)$epiphany->format('w');
    if ($dow === 0) {
        return $epiphany->modify('+1 day');
    }
    $shift = (7 - $dow) % 7;

    return $epiphany->modify(sprintf('+%d day', $shift));
}

function liturgy_is_baptism_of_lord(DateTimeImmutable $date): bool
{
    $baptism = liturgy_baptism_of_lord((int)$date->format('Y'));
    return $date->format('Y-m-d') === $baptism->format('Y-m-d');
}

function liturgy_resolve_effective_color(DateTimeImmutable $date, string $overrideColor, string $autoColor): string
{
    // Keep Feast of Baptism of the Lord white even if stale manual override exists.
    if (liturgy_is_baptism_of_lord($date)) {
        return 'white';
    }
    return $overrideColor !== '' ? $overrideColor : $autoColor;
}

function liturgy_normalize_liturgical_color_string(string $raw): string
{
    $c = strtolower(trim($raw));
    return array_key_exists($c, liturgy_color_palette()) ? $c : '';
}

/**
 * Колер успаміна па назве (фіксаваны спіс усё яшчэ «white» у крыніцы даных;
 * мучанікі — red, Усе душы — black).
 */
function liturgy_infer_optional_memorial_color(string $title): string
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

/**
 * Expand a combined optional memorial title into alternative variants.
 * Example: "Успамін св. Юрыя..., і св. Адальбэрта..." ->
 * ["Успамін св. Юрыя...", "Успамін св. Адальбэрта..."].
 *
 * @return array<int, string>
 */
function liturgy_expand_optional_memorial_title_variants(string $title): array
{
    $raw = trim($title);
    if ($raw === '') {
        return [];
    }
    $partsByOr = preg_split('/\s+альбо\s+/iu', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if ($partsByOr !== false && count($partsByOr) > 1) {
        return array_values(array_map(static fn(string $p): string => trim($p), $partsByOr));
    }
    if (preg_match('/^Успамін\s+(.+)$/iu', $raw, $m) !== 1) {
        return [$raw];
    }
    $body = trim((string)($m[1] ?? ''));
    if ($body === '') {
        return [$raw];
    }
    $split = preg_split('/,\s*і\s+(?=(?:св\.|бл\.))/iu', $body, -1, PREG_SPLIT_NO_EMPTY);
    if ($split === false || count($split) <= 1) {
        return [$raw];
    }
    $variants = [];
    foreach ($split as $item) {
        $t = trim($item);
        if ($t !== '') {
            $variants[] = 'Успамін ' . $t;
        }
    }

    return $variants !== [] ? $variants : [$raw];
}

/**
 * @param array<int, string> $titles
 * @return array<int, string>
 */
function liturgy_optional_memorial_colors_for_titles(array $titles): array
{
    $colors = [];
    foreach ($titles as $title) {
        $t = trim((string)$title);
        if ($t === '') {
            continue;
        }
        $colors[] = liturgy_infer_optional_memorial_color($t);
    }

    return $colors;
}

function liturgy_regional_feast_rank(string $rank): int
{
    return match ($rank) {
        'solemnity' => 4,
        'feast' => 3,
        'memorial' => 2,
        default => 1,
    };
}

/**
 * Угодныя святы дыяцэзій Беларусі (пасля базавых картаў важных дзён і даброўных успамінаў).
 *
 * @param array<string, array{title:string,color:string,is_important:bool,source:string,rank?:string}> $important
 * @param array<string, array{title:string,color:string}> $optional
 * @param array<string, bool> $dioceseOpts
 */
function liturgy_apply_regional_belarus_calendar(int $year, array &$important, array &$optional, array $dioceseOpts): void
{
    // Рэгіянальныя і агульныя ўводзіны — у табліцы liturgy_observances (адмінка /admin/liturgy_observances.php).
    unset($year, $important, $optional, $dioceseOpts);
}

/**
 * Прыарытэт: падмена ў календары → колер з запісу лекцыянарыя → аўтакалер.
 * Хрост Пана заўсёды белы.
 */
function liturgy_resolve_liturgical_color_for_day(
    DateTimeImmutable $date,
    string $calendarOverride,
    string $lectionaryColor,
    string $autoColor
): string {
    if (liturgy_is_baptism_of_lord($date)) {
        return 'white';
    }
    $cal = liturgy_normalize_liturgical_color_string($calendarOverride);
    if ($cal !== '') {
        return $cal;
    }
    $lec = liturgy_normalize_liturgical_color_string($lectionaryColor);
    if ($lec !== '') {
        return $lec;
    }
    return $autoColor;
}

/**
 * @param array<string, array<string,mixed>> $lectionaryMap
 */
function liturgy_lectionary_row_liturgical_color(array $lectionaryMap, string $lookupKey): string
{
    if ($lookupKey === '' || !isset($lectionaryMap[$lookupKey])) {
        return '';
    }
    return liturgy_normalize_liturgical_color_string(
        trim((string)($lectionaryMap[$lookupKey]['liturgical_color'] ?? ''))
    );
}

function liturgy_cycle_letter(DateTimeImmutable $date): string
{
    $year = (int)$date->format('Y');
    $firstAdvent = liturgy_first_advent_sunday($year);
    $liturgicalYear = $date >= $firstAdvent ? $year + 1 : $year;
    return match ($liturgicalYear % 3) {
        1 => 'A',
        2 => 'B',
        default => 'C',
    };
}

function liturgy_strip_cycle_suffix(string $title): string
{
    $t = trim($title);
    $t = preg_replace('/,\s*Год\s*A\s*,\s*B\s*,\s*C\s*$/u', '', $t);
    $t = preg_replace('/,\s*Год\s*A\s*(?:или|або)\s*B\s*(?:или|або)\s*C\s*$/u', '', $t);
    $t = preg_replace('/,\s*Год\s*(?:[ABC]|I{1,3}|IV|V|VI|VII|VIII|IX|X|1|2)\s*$/u', '', trim((string)$t));
    return trim((string)$t);
}

function liturgy_append_cycle_suffix(string $title, string $cycle): string
{
    $trimmed = trim($title);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('/,\s*Год\s*A\s*,\s*B\s*,\s*C\s*$/u', $trimmed) === 1
        || preg_match('/,\s*Год\s*A\s*(?:или|або)\s*B\s*(?:или|або)\s*C\s*$/u', $trimmed) === 1
        || preg_match('/,\s*Год\s*[ABC]\s*$/u', $trimmed) === 1) {
        return $trimmed;
    }
    return $trimmed . ', Год ' . $cycle;
}

/**
 * @return array<string,bool>
 */
function liturgy_no_cycle_fixed_dates(): array
{
    static $dates = null;
    if ($dates !== null) {
        return $dates;
    }

    $dates = array_fill_keys([
        '11-30',
        '12-03', '12-07', '12-08', '12-13', '12-14', '12-24', '12-25', '12-26', '12-27', '12-28',
        '01-01', '01-02', '01-06', '01-17', '01-21', '01-24', '01-25', '01-26', '01-28', '01-31',
        '02-02', '02-05', '02-06', '02-10', '02-14', '02-22', '02-23',
        '03-04', '03-07', '03-19', '03-25',
        '04-07', '04-11', '04-25', '04-29',
        '05-01', '05-02', '05-03', '05-14', '05-16', '05-26', '05-31',
        '06-24', '06-29',
        '07-02', '07-03', '07-11', '07-15', '07-16', '07-22', '07-23', '07-25', '07-26', '07-29', '07-31',
        '08-01', '08-04', '08-08', '08-10', '08-11', '08-15', '08-20', '08-21', '08-22', '08-24', '08-27', '08-28', '08-29',
        '09-03', '09-08', '09-13', '09-14', '09-15', '09-16', '09-21', '09-23', '09-27', '09-29', '09-30',
        '10-01', '10-02', '10-04', '10-07', '10-15', '10-17', '10-18', '10-22', '10-28',
        '11-01', '11-02', '11-03', '11-04', '11-09', '11-10', '11-11', '11-12', '11-17', '11-21', '11-22', '11-23', '11-24',
    ], true);

    return $dates;
}

function liturgy_important_title_uses_cycle_suffix(DateTimeImmutable $date, string $title): bool
{
    $baseTitle = liturgy_strip_cycle_suffix($title);
    if (
        $baseTitle === 'Унебаўшэсце Пана'
        || $baseTitle === 'Урачыстасць Хрыста Валадара Сусвету'
        || $baseTitle === 'Свята Святой Сям’і — Езуса, Марыі і Юзафа'
        || $baseTitle === 'I Нядзеля Адвэнту'
        || $baseTitle === 'Першая нядзеля Адвэнту'
        || $baseTitle === 'Урачыстасць Найсвяцейшай Тройцы'
        || $baseTitle === 'Пальмовая нядзеля'
        || $baseTitle === 'Урачыстасць Найсвяцейшага Цела і Крыві Хрыста'
        || $baseTitle === 'Урачыстасць Найсвяцейшага Сэрца Пана Езуса'
        || $baseTitle === 'Пасхальная вігілія ў святую ноч'
    ) {
        return true;
    }
    if (in_array($baseTitle, [
        'Папяльцовая серада',
        'Вялікі панядзелак',
        'Вялікі аўторак',
        'Вялікая серада',
        'Вялікі чацвер',
        'Вялікая пятніца',
        'Вялікая пятніца Мукі Пана',
        'Вялікая субота',
        'Уваскрасенне Пана (Вялікдзень)',
        'Нядзеля спаслання Духа Святога',
        // Адзіны набор чытанняў у лекцыянарыі — без нядзельнага цыклу A/B/C.
        'ІІ Нядзеля пасля Нараджэння Пана',
        // Чацвер пасля Пятідзесятніцы — уласныя тэксты, без A/B/C.
        'Свята Езуса Хрыста, Найвышэйшага і Вечнага Святара',
    ], true)) {
        return false;
    }

    return !isset(liturgy_no_cycle_fixed_dates()[$date->format('m-d')]);
}

function liturgy_weekday_cycle_i_ii(DateTimeImmutable $date): string
{
    $year = (int)$date->format('Y');
    return ($year % 2 === 0) ? 'II' : 'I';
}

/**
 * Returns title_override when it is truly custom, but auto-fixes old
 * important-day overrides (without cycle suffix) to include current liturgical year.
 *
 * @param array<string,mixed>|null $entry
 * @param array<string,mixed> $auto
 */
function liturgy_effective_title_for_date(DateTimeImmutable $date, ?array $entry, array $auto): string
{
    $autoTitle = trim((string)($auto['title'] ?? ''));
    // Актава Пасхі (пн–сб): ручны title_override не павінен перакрываць імшу актавы (напр. успамін са святамі ў БД).
    if (liturgy_is_paschal_octave_weekday_date($date)) {
        return $autoTitle;
    }
    $titleOverride = is_array($entry) ? trim((string)($entry['title_override'] ?? '')) : '';
    if ($titleOverride === '') {
        return $autoTitle;
    }
    if (liturgy_title_equals_paschal_octave_legacy_weekday($date, $titleOverride)) {
        return $autoTitle;
    }

    $overrideBase = liturgy_strip_cycle_suffix($titleOverride);
    $autoBase = liturgy_strip_cycle_suffix($autoTitle);
    // Same liturgical label as auto but override omitted ", Год A/B/C" (or I/II) — use canonical auto title.
    if ($overrideBase !== '' && $autoTitle !== '' && $overrideBase === $autoBase) {
        return $autoTitle;
    }

    return $titleOverride;
}

function liturgy_roman(int $value): string
{
    if ($value <= 0) {
        return '';
    }
    $map = [
        1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
        100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
        10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
    ];
    $n = $value;
    $r = '';
    foreach ($map as $arabic => $roman) {
        while ($n >= $arabic) {
            $r .= $roman;
            $n -= $arabic;
        }
    }
    return $r;
}

function liturgy_weekday_name(DateTimeImmutable $date): string
{
    return match ((int)$date->format('w')) {
        0 => 'Нядзеля',
        1 => 'Панядзелак',
        2 => 'Аўторак',
        3 => 'Серада',
        4 => 'Чацвер',
        5 => 'Пятніца',
        6 => 'Субота',
        default => '',
    };
}

/**
 * Ці загаловак ужо змяшчае назву гэтага календарнага дня (без дубля «Нядзеля — …» у API).
 */
function liturgy_title_display_already_includes_calendar_weekday(string $title, string $day): bool
{
    if ($title === '' || $day === '') {
        return false;
    }
    $len = mb_strlen($day, 'UTF-8');
    $head = mb_substr($title, 0, $len, 'UTF-8');
    if (mb_strtolower($head, 'UTF-8') === mb_strtolower($day, 'UTF-8')) {
        if (mb_strlen($title, 'UTF-8') <= $len) {
            return true;
        }
        $after = mb_substr($title, $len, null, 'UTF-8');

        return $after === '' || (bool)preg_match('/^[\p{Z}\s—–\-,]/u', $after);
    }
    if ($day === 'Нядзеля') {
        if ((bool)preg_match('/^[IVXLCDM]+\s+Нядзеля\b/u', $title)) {
            return true;
        }

        return (bool)preg_match('/(?<![\p{L}])нядзеля(?![\p{L}])/iu', $title);
    }

    return false;
}

/**
 * Загаловак для кліента: «Дзень тыдня — назва дня». Ключы лекцыянарыя заўсёды без гэтага прэфікса.
 * У Вялікдзень тыдзень — толькі назва (без прэфікса дня тыдня).
 */
function liturgy_title_with_weekday_for_display(DateTimeImmutable $date, string $title): string
{
    $t = trim($title);
    if (liturgy_title_equals_paschal_octave_legacy_weekday($date, $t)) {
        $t = sprintf('%s ў актаве Пасхі', liturgy_weekday_name($date));
    }
    if (liturgy_is_great_monday_through_easter_sunday($date)) {
        return $t;
    }
    $day = liturgy_weekday_name($date);
    if ($day === '') {
        return $t;
    }
    if ($t === '') {
        return $day;
    }
    if (liturgy_title_display_already_includes_calendar_weekday($t, $day)) {
        return $t;
    }
    $prefix = $day . ' — ';
    if (mb_strpos($t, $prefix, 0, 'UTF-8') === 0) {
        return $t;
    }

    return $prefix . $t;
}

/**
 * Прыбірае вядомы прэфікс «дзень — …» для выяўлення рангу (Успамін / Свята / …).
 */
function liturgy_optional_label_strip_leading_weekday(string $title): string
{
    $t = trim($title);
    if ($t === '') {
        return $t;
    }
    if (preg_match(
        '/^(?:Панядзелак|Аўторак|Серада|Чацвер|Пятніца|Субота|Нядзеля)\s*—\s*(.+)$/u',
        $t,
        $m
    ) === 1) {
        return trim((string)($m[1] ?? ''));
    }

    return $t;
}

/**
 * Прэфікс назвы ўрачыстасці / свята / успаміна ў пачатку радка («Успамін », «Свята — » і г.д.).
 */
function liturgy_optional_extract_observance_prefix(string $title): ?string
{
    $t = liturgy_optional_label_strip_leading_weekday(trim($title));
    if ($t === '') {
        return null;
    }
    if (preg_match(
        '/^((?:Даброўны\s+успамін|Урачыстасць|Свята|Успамін)(?:\s*[—–\-]\s*|\s+))/iu',
        $t,
        $m
    ) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * Кожны варыянт пасля «альбо» атрымлівае той жа тып назвы (Успамін / Свята / …), што і першы,
 * калі ў другім варыянце тып не пазначаны (напр. толькі «св. …»).
 *
 * @param array<int, string> $labels
 * @return array<int, string>
 */
function liturgy_optional_enrich_alternative_labels_with_observance(array $labels): array
{
    $out = [];
    $inherited = null;
    $seenFirst = false;
    foreach (array_values($labels) as $lab) {
        $raw = trim((string)$lab);
        if ($raw === '') {
            continue;
        }
        if (!$seenFirst) {
            $inherited = liturgy_optional_extract_observance_prefix($raw);
            $seenFirst = true;
            $out[] = $raw;
            continue;
        }
        $core = liturgy_optional_label_strip_leading_weekday($raw);
        if ($inherited !== null && liturgy_optional_extract_observance_prefix($core) === null) {
            $raw = $inherited . $core;
        }
        $out[] = $raw;
    }

    return $out;
}

/**
 * Адна галіна optional_memorial_title для кліента: без «Чацвер — …»;
 * замест гэтага «Успамін — …» / «Свята — …» (як у шапцы экрана дня).
 */
function liturgy_optional_memorial_variant_for_client_display(string $label): string
{
    $t = trim($label);
    if ($t === '') {
        return '';
    }
    $t = liturgy_optional_label_strip_leading_weekday($t);
    if ($t === '') {
        return '';
    }
    if (preg_match(
        '/^((?:Даброўны\s+успамін|Урачыстасць|Свята|Успамін)(?:\s*[—–\-]\s*|\s+))(.*)$/isu',
        $t,
        $m
    ) !== 1) {
        return 'Успамін — ' . $t;
    }
    $pre = (string)($m[1] ?? '');
    $body = trim((string)($m[2] ?? ''));
    if (preg_match('/^Даброўны\s+успамін/iu', $pre) === 1) {
        return 'Даброўны успамін — ' . $body;
    }
    if (preg_match('/^Урачыстасць/iu', $pre) === 1) {
        return 'Урачыстасць — ' . $body;
    }
    if (preg_match('/^Свята/iu', $pre) === 1) {
        return 'Свята — ' . $body;
    }
    if (preg_match('/^Успамін/iu', $pre) === 1) {
        return 'Успамін — ' . $body;
    }

    return 'Успамін — ' . $t;
}

/**
 * optional_memorial_title для JSON: варыянты праз «альбо» з тыпам дня (Успамін — …), без паўтору дня тыдня.
 *
 * @param array<int, string> $optionalLookupTitles
 */
function liturgy_format_optional_memorial_title_for_display(
    DateTimeImmutable $date,
    string $optionalMemorialTitleRaw,
    array $optionalLookupTitles
): string {
    $raw = trim($optionalMemorialTitleRaw);
    $labels = liturgy_optional_memorial_row_labels($raw, $optionalLookupTitles);
    if ($labels === []) {
        if ($raw !== '') {
            return liturgy_optional_memorial_variant_for_client_display($raw);
        }

        return '';
    }
    $labels = liturgy_optional_enrich_alternative_labels_with_observance($labels);
    $parts = [];
    foreach ($labels as $lab) {
        $parts[] = liturgy_optional_memorial_variant_for_client_display(trim((string)$lab));
    }

    return implode(' альбо ', $parts);
}

function liturgy_be_ordinal_word(int $value): string
{
    return match ($value) {
        1 => 'першы',
        2 => 'другі',
        3 => 'трэці',
        4 => 'чацвёрты',
        5 => 'пяты',
        6 => 'шосты',
        7 => 'сёмы',
        8 => 'восьмы',
        9 => 'дзявяты',
        10 => 'дзясяты',
        11 => 'адзінаццаты',
        12 => 'дванаццаты',
        13 => 'трынаццаты',
        14 => 'чатырнаццаты',
        15 => 'пятнаццаты',
        16 => 'шаснаццаты',
        17 => 'сямнаццаты',
        18 => 'васямнаццаты',
        19 => 'дзевятнаццаты',
        20 => 'дваццаты',
        default => (string)$value,
    };
}

function liturgy_christmas_octave_day_number(DateTimeImmutable $date): int
{
    $year = (int)$date->format('Y');
    $month = (int)$date->format('m');
    $liturgicalChristmasYear = $month === 12 ? $year : ($year - 1);
    $start = new DateTimeImmutable(sprintf('%04d-12-25', $liturgicalChristmasYear), new DateTimeZone('UTC'));
    $end = new DateTimeImmutable(sprintf('%04d-12-31', $liturgicalChristmasYear), new DateTimeZone('UTC'));
    if ($date < $start || $date > $end) {
        return 0;
    }
    return (int)$start->diff($date)->days + 1;
}

function liturgy_christmas_octave_date_label_by_day_number(int $dayNumber): string
{
    return match ($dayNumber) {
        1 => '25 снежня',
        2 => '26 снежня',
        3 => '27 снежня',
        4 => '28 снежня',
        5 => '29 снежня',
        6 => '30 снежня',
        7 => '31 снежня',
        default => '',
    };
}

function liturgy_christmas_octave_date_label_from_title(string $effectiveTitle): string
{
    $base = liturgy_strip_cycle_suffix(trim($effectiveTitle));
    if ($base === '') {
        return '';
    }
    if (
        preg_match(
            '/^[^—]+—\s*([[:alpha:]ёіў\'’-]+)\s+дзень\s+у\s+актаве\s+Нараджэння\s+Пана$/u',
            $base,
            $m
        ) !== 1
    ) {
        return '';
    }
    $ordinal = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string)($m[1] ?? '')), 'UTF-8')
        : strtolower(trim((string)($m[1] ?? '')));
    $toDayNumber = [
        'першы' => 1,
        'другі' => 2,
        'трэці' => 3,
        'чацвёрты' => 4,
        'пяты' => 5,
        'шосты' => 6,
        'сёмы' => 7,
        'восьмы' => 8,
        'дзявяты' => 9,
        'дзясяты' => 10,
        'адзінаццаты' => 11,
        'дванаццаты' => 12,
        'трынаццаты' => 13,
        'чатырнаццаты' => 14,
        'пятнаццаты' => 15,
        'шаснаццаты' => 16,
        'сямнаццаты' => 17,
        'васямнаццаты' => 18,
    ];
    $dayNumber = (int)($toDayNumber[$ordinal] ?? 0);
    if ($dayNumber <= 0) {
        return '';
    }
    return liturgy_christmas_octave_date_label_by_day_number($dayNumber);
}

function liturgy_christmas_period_date_lookup_title(DateTimeImmutable $date, bool $isImportant): string
{
    if ($isImportant) {
        return '';
    }
    if (liturgy_detect_season($date) !== 'CHRISTMAS') {
        return '';
    }
    if ((int)$date->format('w') === 0) {
        return '';
    }
    $month = (int)$date->format('m');
    $day = (int)$date->format('d');
    if ($month === 1 && $day >= 2 && $day <= 12) {
        return sprintf('%d студзеня', $day);
    }
    return '';
}

function liturgy_detect_season(DateTimeImmutable $date): string
{
    $year = (int)$date->format('Y');
    $month = (int)$date->format('m');
    $day = (int)$date->format('d');

    $easter = liturgy_easter_sunday($year);
    $ashWednesday = $easter->modify('-46 day');
    $pentecost = $easter->modify('+49 day');
    $firstAdvent = liturgy_first_advent_sunday($year);
    $christmas = new DateTimeImmutable(sprintf('%04d-12-25', $year), new DateTimeZone('UTC'));
    $baptism = liturgy_baptism_of_lord($year);

    if ($date >= $ashWednesday && $date < $easter) {
        return 'LENT';
    }
    if ($date >= $easter && $date <= $pentecost) {
        return 'EASTER';
    }
    if ($date >= $firstAdvent && $date < $christmas) {
        return 'ADVENT';
    }
    if (($month === 12 && $day >= 25) || ($month === 1 && $date <= $baptism)) {
        return 'CHRISTMAS';
    }
    return 'ORDINARY';
}

function liturgy_advent_week(DateTimeImmutable $date): int
{
    $firstAdvent = liturgy_first_advent_sunday((int)$date->format('Y'));
    $days = (int)$firstAdvent->diff($date)->days;
    return intdiv($days, 7) + 1;
}

function liturgy_lent_week(DateTimeImmutable $date): int
{
    $easter = liturgy_easter_sunday((int)$date->format('Y'));
    $ashWednesday = $easter->modify('-46 day');
    $firstLentSunday = $ashWednesday->modify('+4 day');
    if ($date < $firstLentSunday) {
        return 0;
    }
    $days = (int)$firstLentSunday->diff($date)->days;
    return intdiv($days, 7) + 1;
}

function liturgy_easter_week(DateTimeImmutable $date): int
{
    $easter = liturgy_easter_sunday((int)$date->format('Y'));
    $days = (int)$easter->diff($date)->days;
    return intdiv($days, 7) + 1;
}

/**
 * Панядзелак–субота (не нядзеля) ў межах актавы Пасхі: Easter+1 … Easter+6, сэзон EASTER.
 */
function liturgy_is_paschal_octave_weekday_date(DateTimeImmutable $date): bool
{
    if (liturgy_detect_season($date) !== 'EASTER') {
        return false;
    }
    if ((int)$date->format('w') === 0) {
        return false;
    }
    $easter = liturgy_easter_sunday((int)$date->format('Y'));
    $from = $easter->modify('+1 day');
    $until = $easter->modify('+6 day');

    return $date >= $from && $date <= $until;
}

/**
 * Панядзелак–субота ў актаве Пасхі: у БД лекцыянарыя застаўся ключ «— I Тыдзень Велікоднага перыяду».
 */
function liturgy_easter_octave_weekday_legacy_lookup_title(DateTimeImmutable $date): string
{
    if (!liturgy_is_paschal_octave_weekday_date($date)) {
        return '';
    }
    $weekday = liturgy_weekday_name($date);

    return sprintf('%s — %s Тыдзень Велікоднага перыяду', $weekday, liturgy_roman(1));
}

/**
 * Мяккая нармалізацыя толькі для fallback-параўнання (не «рэзаць» усе \p{Pd} у радку).
 */
function liturgy_normalize_title_for_paschal_octave_compare(string $title): string
{
    $t = liturgy_strip_cycle_suffix(trim($title));
    $t = str_replace(['І', 'і'], ['I', 'i'], $t);
    $t = preg_replace('/\s+/u', ' ', (string)$t);

    return mb_strtolower(trim((string)$t), 'UTF-8');
}

function liturgy_title_equals_paschal_octave_legacy_weekday(DateTimeImmutable $date, string $title): bool
{
    if (!liturgy_is_paschal_octave_weekday_date($date)) {
        return false;
    }
    $base = liturgy_strip_cycle_suffix(trim($title));
    $wd = liturgy_weekday_name($date);
    if ($wd !== '' && preg_match(
        '/^' . preg_quote($wd, '/') . '\s*\p{Pd}\s*[IІ]\s+Тыдзень\s+Велікоднага\s+перыяду$/iu',
        $base
    ) === 1) {
        return true;
    }
    if (preg_match('/^[IІ]\s+Тыдзень\s+Велікоднага\s+перыяду$/iu', $base) === 1) {
        return true;
    }
    $legacy = liturgy_easter_octave_weekday_legacy_lookup_title($date);
    $a = liturgy_normalize_title_for_paschal_octave_compare($title);
    $b = liturgy_normalize_title_for_paschal_octave_compare($legacy);

    return $a !== '' && $a === $b;
}

function liturgy_title_is_paschal_octave_weekday(string $title): bool
{
    $base = liturgy_strip_cycle_suffix(trim($title));

    return $base !== ''
        && (bool)preg_match(
            '/^(?:Панядзелак|Аўторак|Серада|Чацвер|Пятніца|Субота)\s+[уў]\s+актаве\s+Пасхі$/u',
            $base
        );
}

/**
 * @return array<string,int>
 */
function liturgy_ordinary_sunday_numbers(int $year): array
{
    $start = new DateTimeImmutable(sprintf('%04d-01-01', $year), new DateTimeZone('UTC'));
    $end = new DateTimeImmutable(sprintf('%04d-12-31', $year), new DateTimeZone('UTC'));
    $sundays = [];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        if ((int)$d->format('w') !== 0) {
            continue;
        }
        if (liturgy_detect_season($d) !== 'ORDINARY') {
            continue;
        }
        $sundays[] = $d->format('Y-m-d');
    }

    $result = [];
    $n = 34;
    for ($i = count($sundays) - 1; $i >= 0; $i--) {
        $result[$sundays[$i]] = $n;
        $n--;
    }
    return $result;
}

function liturgy_ordinary_week_number(DateTimeImmutable $date): int
{
    if (liturgy_detect_season($date) !== 'ORDINARY') {
        return 0;
    }
    $isSunday = (int)$date->format('w') === 0;
    $year = (int)$date->format('Y');
    $easter = liturgy_easter_sunday($year);
    $ashWednesday = $easter->modify('-46 day');
    $pentecost = $easter->modify('+49 day');
    $firstAdvent = liturgy_first_advent_sunday($year);

    // Ordinary Time starts on Monday after Baptism of the Lord.
    $ordinaryStart = liturgy_baptism_of_lord($year)->modify('+1 day');
    $beforeLentEnd = $ashWednesday->modify('-1 day');

    if ($date >= $ordinaryStart && $date <= $beforeLentEnd) {
        $days = (int)$ordinaryStart->diff($date)->days;
        $baseWeek = intdiv($days, 7) + 1;
        $w = $baseWeek + ($isSunday ? 1 : 0);

        return min($w, 34);
    }

    // Ordinary Time resumes on Monday after Pentecost.
    $afterPentecostStart = $pentecost->modify('+1 day');
    $ordinaryEnd = $firstAdvent->modify('-1 day');
    if ($date >= $afterPentecostStart && $date <= $ordinaryEnd) {
        $firstPartDays = (int)$ordinaryStart->diff($beforeLentEnd)->days;
        $firstPartLastWeek = intdiv($firstPartDays, 7) + 1;
        // Працяг нумарацыі тыдняў Звычайнага часу пасля Пятідзесятніцы: першы панядзелак = firstPartLastWeek + 1
        // (як у Roman Missal / ORDO; +2 зрушвала ўсе нядзелі на адзін нумар уверх).
        $secondPartStartWeek = $firstPartLastWeek + 1;
        $days = (int)$afterPentecostStart->diff($date)->days;
        $baseWeek = $secondPartStartWeek + intdiv($days, 7);
        // Пасля Пятідзесятніцы: будні — baseWeek+1 (як у ORDO, гл. 10.06.2025 = X тыдзень).
        // Нядзелі — baseWeek+2: «XII Нядзеля» на адзін больш за «панядзелак XI тыдня» (г.зн. 22.06.2025).
        $w = $baseWeek + ($isSunday ? 2 : 1);

        // У Звычайным часе ў Рымскім календары толькі да XXXIV тыдня (няма XXXV).
        return min($w, 34);
    }

    return 0;
}

/**
 * Пераносныя ўрачыстасці з залежнасцю ад liturgy_calendar_config.php.
 *
 * @return array<string, array{0:string,1:string}>
 */
function liturgy_transfer_dependent_movables(int $year, DateTimeImmutable $easter): array
{
    unset($year);
    $transfers = liturgy_calendar_transfers();
    $ascension = !empty($transfers['ascension_on_sunday'])
        ? $easter->modify('+42 day')
        : $easter->modify('+39 day');
    $corpusChristi = !empty($transfers['corpus_christi_on_sunday'])
        ? $easter->modify('+63 day')
        : $easter->modify('+60 day');

    return [
        $ascension->format('Y-m-d') => ['Унебаўшэсце Пана', 'white'],
        $corpusChristi->format('Y-m-d') => ['Урачыстасць Найсвяцейшага Цела і Крыві Хрыста', 'white'],
    ];
}

/**
 * @return array<string, array{title:string,color:string}>
 */
function liturgy_optional_memorials_for_year(int $year, ?array $dioceseOpts = null): array
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    return liturgy_observances_build_optional_map($year, $dioceseOpts);
}

/**
 * @return array<string, array{title:string,color:string,is_important:bool,source:string}>
 */
function liturgy_important_dates_for_year(int $year, ?array $dioceseOpts = null): array
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    $result = liturgy_observances_build_important_map($year, $dioceseOpts);
    $easter = liturgy_easter_sunday($year);
    foreach (liturgy_transfer_dependent_movables($year, $easter) as $ymd => $pair) {
        $result[$ymd] = [
            'title' => $pair[0],
            'color' => $pair[1],
            'is_important' => true,
            'source' => 'movable',
        ];
    }
    liturgy_observances_apply_title_patches($year, $dioceseOpts, $result);
    return $result;
}

function liturgy_season_color(DateTimeImmutable $date, array $importantMap): string
{
    $key = $date->format('Y-m-d');
    if (isset($importantMap[$key])) {
        return (string)$importantMap[$key]['color'];
    }

    $season = liturgy_detect_season($date);
    $isSunday = (int)$date->format('w') === 0;

    // Gaudete Sunday (III Sunday of Advent) and Laetare Sunday (IV Sunday of Lent).
    if ($isSunday && $season === 'ADVENT' && liturgy_advent_week($date) === 3) {
        return 'rose';
    }
    if ($isSunday && $season === 'LENT' && liturgy_lent_week($date) === 4) {
        return 'rose';
    }

    return match ($season) {
        'ADVENT', 'LENT' => 'purple',
        'CHRISTMAS', 'EASTER' => 'white',
        default => 'green',
    };
}

function liturgy_auto_title(DateTimeImmutable $date, ?array $important): string
{
    $cycle = liturgy_cycle_letter($date);
    if (liturgy_is_baptism_of_lord($date)) {
        return sprintf('Свята Хросту Пана, Год %s', $cycle);
    }
    $season = liturgy_detect_season($date);
    $isSunday = (int)$date->format('w') === 0;
    $weekday = liturgy_weekday_name($date);

    // From 26 Dec until Sunday of Baptism of the Lord we use running day labels.
    // This is intentional for lectionary matching by day-in-sequence.
    if ($season === 'CHRISTMAS' && $important === null) {
        $octaveDay = liturgy_christmas_octave_day_number($date);
        if ($octaveDay > 0) {
            return sprintf(
                '%s — %s дзень у актаве Нараджэння Пана',
                $weekday,
                liturgy_be_ordinal_word($octaveDay)
            );
        }
    }

    if (liturgy_is_paschal_octave_weekday_date($date)) {
        $impRaw = $important !== null ? trim((string)($important['title'] ?? '')) : '';
        if (
            $important === null
            || $impRaw === ''
            || liturgy_title_equals_paschal_octave_legacy_weekday($date, $impRaw)
        ) {
            return sprintf('%s ў актаве Пасхі', $weekday);
        }
    }

    if ($important !== null) {
        $importantTitle = (string)$important['title'];
        if (!liturgy_important_title_uses_cycle_suffix($date, $importantTitle)) {
            return liturgy_strip_cycle_suffix($importantTitle);
        }
        return liturgy_append_cycle_suffix($importantTitle, $cycle);
    }

    $month = (int)$date->format('m');
    $day = (int)$date->format('d');

    return match ($season) {
        'ADVENT' => (function () use ($date, $isSunday, $weekday, $cycle, $month, $day): string {
            // O Antiphons period: fixed weekdays of Advent (17-24 Dec)
            // must resolve by calendar date, not by "week of Advent".
            if (!$isSunday && $month === 12 && $day >= 17 && $day <= 24) {
                return sprintf('%d снежня', $day);
            }
            return $isSunday
                ? sprintf('%s Нядзеля Адвэнту, Год %s', liturgy_roman(liturgy_advent_week($date)), $cycle)
                : sprintf('%s — %s Тыдзень Адвэнту', $weekday, liturgy_roman(liturgy_advent_week($date)));
        })(),

        'LENT' => (function () use ($date, $isSunday, $weekday, $cycle): string {
            // Шостая нядзеля посту ў Рымскім календары — заўсёды Пальмовая нядзеля (не «VI Нядзеля…»).
            if ($isSunday) {
                $palmSunday = liturgy_easter_sunday((int)$date->format('Y'))->modify('-7 day');
                if ($date->format('Y-m-d') === $palmSunday->format('Y-m-d')) {
                    return liturgy_append_cycle_suffix('Пальмовая нядзеля', $cycle);
                }
            }
            $week = liturgy_lent_week($date);
            if ($week === 0) {
                return $weekday . ' пасля Папяльцовай серады';
            }
            return $isSunday
                ? sprintf('%s Нядзеля Вялікага посту, Год %s', liturgy_roman($week), $cycle)
                : sprintf('%s — %s Тыдзень Вялікага посту', $weekday, liturgy_roman($week));
        })(),

        'EASTER' => (function () use ($date, $isSunday, $weekday, $cycle): string {
            if (!$isSunday && liturgy_is_paschal_octave_weekday_date($date)) {
                return sprintf('%s ў актаве Пасхі', $weekday);
            }
            return $isSunday
                ? sprintf('%s Нядзеля Велікоднага перыяду, Год %s', liturgy_roman(max(2, liturgy_easter_week($date))), $cycle)
                : sprintf('%s — %s Тыдзень Велікоднага перыяду', $weekday, liturgy_roman(max(1, liturgy_easter_week($date))));
        })(),

        'CHRISTMAS' => (function () use ($isSunday, $weekday, $cycle): string {
            if ($isSunday) {
                return sprintf('%s Раждзественскага перыяду, Год %s', $weekday, $cycle);
            }
            return $weekday . ' - будзень перыяду Нараджэння Пана';
        })(),

        default => (function () use ($date, $isSunday, $weekday, $cycle): string {
            $week = liturgy_ordinary_week_number($date);
            if ($isSunday) {
                $y = (int)$date->format('Y');
                $christKing = liturgy_first_advent_sunday($y)->modify('-7 day');
                // Апошняя нядзеля сегмента Звычайнага часу: у Місе гэта заўсёды Урачыстасць Хрыста Валадара, без «N-я нядзеля Звычайнага часу».
                if ($week === 34 || $date->format('Y-m-d') === $christKing->format('Y-m-d')) {
                    return liturgy_append_cycle_suffix('Урачыстасць Хрыста Валадара Сусвету', $cycle);
                }
            }
            if ($week <= 0) {
                return $weekday . ' Звычайнага часу';
            }
            return $isSunday
                ? sprintf('%s Нядзеля Звычайнага часу, Год %s', liturgy_roman($week), $cycle)
                : sprintf(
                    '%s — %s Тыдзень Звычайнага часу, Год %s',
                    $weekday,
                    liturgy_roman($week),
                    liturgy_weekday_cycle_i_ii($date)
                );
        })(),
    };
}

/**
 * When true, general optional memorials and fixed-date lectionary "альбо" rows are omitted:
 * Advent Sundays; Lent Sundays; Easter Sundays through Pentecost; Advent weekdays 17–24 Dec;
 * Holy Week; Ash Wednesday; Easter octave weekdays (Mon–Sat);
 * days that are already a fixed/movable solemnity or feast.
 * Іншыя нядзелі (Звычайны час): даброўныя успаміны праз «альбо» разам з імшой нядзелі.
 */
function liturgy_optional_memorials_suppressed_for_day(DateTimeImmutable $date, ?array $important): bool
{
    $season = liturgy_detect_season($date);
    if ($season === 'ADVENT' && (int)$date->format('w') === 0) {
        return true;
    }
    if ($season === 'LENT' && (int)$date->format('w') === 0) {
        return true;
    }
    if ($season === 'EASTER' && (int)$date->format('w') === 0) {
        return true;
    }
    $month = (int)$date->format('m');
    $day = (int)$date->format('d');
    if ($season === 'ADVENT' && $month === 12 && $day >= 17 && $day <= 24) {
        return true;
    }

    $year = (int)$date->format('Y');
    $easter = liturgy_easter_sunday($year);
    $palmSunday = $easter->modify('-7 day');
    $holySaturday = $easter->modify('-1 day');
    if ($date >= $palmSunday && $date <= $holySaturday) {
        return true;
    }

    $ashWednesday = $easter->modify('-46 day');
    if ($date->format('Y-m-d') === $ashWednesday->format('Y-m-d')) {
        return true;
    }

    // Easter octave weekdays (Mon–Sat): no general memorials alongside the octave.
    if (liturgy_is_paschal_octave_weekday_date($date)) {
        return true;
    }

    if ($important !== null) {
        $src = (string)($important['source'] ?? '');
        if ($src === 'fixed' || $src === 'movable' || $src === 'particular' || $src === 'regional') {
            return true;
        }
    }

    return false;
}

/**
 * Фіксаваныя святы святых (не ўрачыстасці, не святы Пана / Узвышэнне Крыжа / Перамяненне і пад.),
 * якія саступаюць нядзелі Звычайнага часу (норма ORDO: нядзеля Звычайнага часу пераважае над святамі святых).
 * Не ўключаць урачыстасці (напр. Пётр і Паўла) і святы Гасподнія ў рангу свята.
 *
 * @return array<string,bool> ключы mm-dd
 */
function liturgy_fixed_feasts_outranked_by_ordinary_sunday(): array
{
    static $dates = null;
    if ($dates !== null) {
        return $dates;
    }
    $dates = array_fill_keys([
        '01-25',
        '02-14',
        '02-22',
        '04-29',
        '05-01',
        '05-03',
        '05-14',
        '07-03',
        '07-11',
        '07-22',
        '07-23',
        '07-25',
        '09-08',
        '09-29',
        '10-18',
        '10-28',
        '11-09',
    ], true);

    return $dates;
}

/**
 * Ці нядзеля адмяняе фіксаваны «важны» дзень для загаловак/колеру (як у ORDO).
 */
function liturgy_fixed_important_suppressed_by_sunday(DateTimeImmutable $date, ?array $important): bool
{
    if ($important === null || (int)$date->format('w') !== 0) {
        return false;
    }
    $src = (string)($important['source'] ?? '');
    $season = liturgy_detect_season($date);
    if ($season === 'ADVENT' || $season === 'LENT' || $season === 'EASTER') {
        return $src === 'fixed' || $src === 'particular' || $src === 'regional';
    }
    if ($src !== 'fixed') {
        return false;
    }
    if ($season === 'ORDINARY') {
        return isset(liturgy_fixed_feasts_outranked_by_ordinary_sunday()[$date->format('m-d')]);
    }

    return false;
}

/**
 * @return array{
 *   date:string,
 *   title:string,
 *   color:string,
 *   color_hex:string,
 *   is_important:bool
 * }
 */
function liturgy_auto_day_info(DateTimeImmutable $date, ?array $dioceseOpts = null): array
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    $y = (int)$date->format('Y');
    static $mergedMapsCache = [];
    $sig = (string)$y;
    foreach (liturgy_diocese_keys() as $dk) {
        $sig .= !empty($dioceseOpts[$dk]) ? '1' : '0';
    }
    if (!isset($mergedMapsCache[$sig])) {
        $importantMap = liturgy_important_dates_for_year($y, $dioceseOpts);
        $optionalMap = liturgy_optional_memorials_for_year($y, $dioceseOpts);
        liturgy_apply_regional_belarus_calendar($y, $importantMap, $optionalMap, $dioceseOpts);
        $mergedMapsCache[$sig] = [$importantMap, $optionalMap];
    }
    [$importantMap, $optionalMap] = $mergedMapsCache[$sig];
    $customOptionalMap = liturgy_fixed_date_lectionary_options_for_year($y);
    $key = $date->format('Y-m-d');
    $important = $importantMap[$key] ?? null;
    $effectiveImportantMap = $importantMap;
    $isSunday = (int)$date->format('w') === 0;
    $season = liturgy_detect_season($date);
    if (liturgy_fixed_important_suppressed_by_sunday($date, $important)) {
        // Нядзелі Адвэнту, посту і Велікоднага часу — над усімі фіксаванымі днямі;
        // нядзеля Звычайнага часу — над выбранымі святамі святых (не над урачыстасцямі).
        $important = null;
        unset($effectiveImportantMap[$key]);
    }
    if (liturgy_is_paschal_octave_weekday_date($date)) {
        // Панядзелак–субота ў актаве Пасхі: імша акутавы пераважае над фіксаванымі/рухомымі святамі і вобласнымі днямі.
        $important = null;
        unset($effectiveImportantMap[$key]);
    }
    if ($isSunday && $season === 'CHRISTMAS') {
        $octaveDay = liturgy_christmas_octave_day_number($date);
        if ($octaveDay >= 2 && $octaveDay <= 7) {
            // Sunday inside Christmas octave -> Feast of the Holy Family.
            $important = [
                'title' => 'Свята Святой Сям’і — Езуса, Марыі і Юзафа',
                'color' => 'white',
                'is_important' => true,
                'source' => 'computed',
            ];
        } elseif ((int)$date->format('m') === 1) {
            $day = (int)$date->format('d');
            if ($day >= 2 && $day <= 5) {
                // Sunday between Jan 2 and Jan 5 -> II Sunday after Christmas.
                $important = [
                    'title' => 'ІІ Нядзеля пасля Нараджэння Пана',
                    'color' => 'white',
                    'is_important' => true,
                    'source' => 'computed',
                ];
            }
        }
    }
    $suppressOptionals = liturgy_optional_memorials_suppressed_for_day($date, $important);
    $optional = null;
    $customOptionals = [];
    if (!$suppressOptionals) {
        $optional = $optionalMap[$key] ?? null;
        $customOptionals = $customOptionalMap[$key] ?? [];
    }
    $optionalTitles = [];
    $optionalLookupTitles = [];
    $optionalTitleColors = [];
    if ($optional !== null) {
        $optionalTitle = trim((string)($optional['title'] ?? ''));
        if ($optionalTitle !== '') {
            $optionalVariants = liturgy_expand_optional_memorial_title_variants($optionalTitle);
            $optionalColorsFromMap = [];
            if (is_array($optional['colors'] ?? null)) {
                foreach ($optional['colors'] as $c) {
                    $ct = trim((string)$c);
                    if ($ct !== '') {
                        $optionalColorsFromMap[] = $ct;
                    }
                }
            } else {
                $singleColor = trim((string)($optional['color'] ?? ''));
                if ($singleColor !== '') {
                    $optionalColorsFromMap[] = $singleColor;
                }
            }
            foreach ($optionalVariants as $variant) {
                if ($variant !== '' && !in_array($variant, $optionalTitles, true)) {
                    $variantIndex = count($optionalTitles);
                    $optionalTitles[] = $variant;
                    $optionalTitleColors[] = $optionalColorsFromMap[$variantIndex]
                        ?? liturgy_infer_optional_memorial_color($variant);
                }
            }
            foreach ($optionalVariants as $variantLookup) {
                if ($variantLookup !== '' && !in_array($variantLookup, $optionalLookupTitles, true)) {
                    $optionalLookupTitles[] = $variantLookup;
                }
            }
        }
    }
    foreach ($customOptionals as $customOptional) {
        if (!is_array($customOptional)) {
            continue;
        }
        $label = trim((string)($customOptional['label'] ?? ''));
        $lookupTitle = trim((string)($customOptional['lookup_title'] ?? ''));
        if ($lookupTitle === '') {
            continue;
        }
        if ($label === '') {
            $label = $lookupTitle;
        }
        if (!in_array($label, $optionalTitles, true)) {
            $optionalTitles[] = $label;
            $optionalTitleColors[] = liturgy_infer_optional_memorial_color($label);
        }
        if (!in_array($lookupTitle, $optionalLookupTitles, true)) {
            $optionalLookupTitles[] = $lookupTitle;
        }
    }
    // Вігілія Унебаўзяцця — у лекцыянарыі як папярэдні дзень; у спісе варыянтаў — апошнім (пасля іншых успамінаў / датаваных радкоў).
    if ($date->format('m-d') === '08-14') {
        $assumptionVigilLookup = 'Унебаўзяцце Найсвяцейшай Панны Марыі - Імша ў вігілію';
        $assumptionVigilLabel = 'Унебаўзяцце НПМ — Імша ў вігілію';
        if (!in_array($assumptionVigilLabel, $optionalTitles, true)) {
            $optionalTitles[] = $assumptionVigilLabel;
            $optionalTitleColors[] = liturgy_infer_optional_memorial_color($assumptionVigilLabel);
        }
        if (!in_array($assumptionVigilLookup, $optionalLookupTitles, true)) {
            $optionalLookupTitles[] = $assumptionVigilLookup;
        }
    }
    $color = $important !== null
        ? (string)$important['color']
        : liturgy_season_color($date, $effectiveImportantMap);

    return [
        'date' => $key,
        'title' => liturgy_auto_title($date, $important),
        'color' => $color,
        'color_hex' => liturgy_color_hex($color),
        'is_important' => $important !== null,
        'optional_memorial_title' => implode(' альбо ', $optionalTitles),
        'optional_memorial_lookup_titles' => $optionalLookupTitles,
        'optional_memorial_colors' => $optionalTitleColors !== []
            ? $optionalTitleColors
            : liturgy_optional_memorial_colors_for_titles($optionalTitles),
        'optional_memorial_color' => $optionalTitles !== []
            ? ($optionalTitleColors[0] ?? liturgy_infer_optional_memorial_color(implode(' ', $optionalTitles)))
            : 'white',
        'has_optional_memorial' => $optionalTitles !== [],
    ];
}

/**
 * @return array{month:int,day:int,label:string}|null
 */
function liturgy_parse_fixed_date_lectionary_title(string $title): ?array
{
    $value = trim($title);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*(?:[-—:]\s*|\s+альбо\s+)(.+)$/ui', $value, $m)) {
        return null;
    }
    $day = (int)($m[1] ?? 0);
    $month = (int)($m[2] ?? 0);
    $label = trim((string)($m[3] ?? ''));
    if ($day < 1 || $month < 1 || $month > 12 || $label === '') {
        return null;
    }
    return [
        'month' => $month,
        'day' => $day,
        'label' => $label,
    ];
}

/**
 * @return array<string, array<int, array{lookup_title:string,label:string}>>
 */
function liturgy_fixed_date_lectionary_options_for_year(int $year): array
{
    static $cache = [];
    if (isset($cache[$year]) && is_array($cache[$year])) {
        return $cache[$year];
    }

    $stmt = db()->query(
        'SELECT title
         FROM liturgy_lectionary_entries
         WHERE is_active = 1'
    );
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $fullTitle = trim((string)($row['title'] ?? ''));
        if ($fullTitle === '') {
            continue;
        }
        $parsed = liturgy_parse_fixed_date_lectionary_title($fullTitle);
        if ($parsed === null) {
            continue;
        }
        $month = (int)$parsed['month'];
        $day = (int)$parsed['day'];
        if (!checkdate($month, $day, $year)) {
            continue;
        }
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if (!isset($result[$dateKey]) || !is_array($result[$dateKey])) {
            $result[$dateKey] = [];
        }
        $label = (string)$parsed['label'];
        $alreadyAdded = false;
        foreach ($result[$dateKey] as $existing) {
            if (
                liturgy_normalize_lectionary_key((string)($existing['lookup_title'] ?? ''))
                === liturgy_normalize_lectionary_key($fullTitle)
            ) {
                $alreadyAdded = true;
                break;
            }
        }
        if ($alreadyAdded) {
            continue;
        }
        $result[$dateKey][] = [
            'lookup_title' => $fullTitle,
            'label' => $label,
        ];
    }

    $cache[$year] = $result;
    return $result;
}

function liturgy_normalize_lectionary_key(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    $withoutTags = strip_tags($decoded);
    $folded = preg_replace('/\s+/u', ' ', $withoutTags ?? '');
    $trimmed = trim((string)$folded);
    if ($trimmed === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($trimmed, 'UTF-8');
    }
    return strtolower($trimmed);
}

/**
 * Субота VII тыдня Велікоднага перыяду: назва дня ў календары адпавядае запісу
 * «Субота сёмага велікоднага тыдня - Імша раніцай» у лекцыянарыі (не дублюем як асобны «асноўны» варыянт).
 */
function liturgy_effective_title_is_saturday_seventh_easter_week(string $effectiveTitle): bool
{
    $base = liturgy_strip_cycle_suffix(trim($effectiveTitle));

    return $base === 'Субота — VII Тыдзень Велікоднага перыяду'
        || $base === 'Субота сёмага велікоднага тыдня';
}

/**
 * Нядзеля Пятідзесятніцы: у лекцыянарыі адзін запіс «… (Імша ўдзень)»; кароткая назва дня без «Імша ўдзень» не дублюецца як асобны «асноўны» радок.
 */
function liturgy_effective_title_is_pentecost_sunday(string $effectiveTitle): bool
{
    $base = liturgy_strip_cycle_suffix(trim($effectiveTitle));

    return $base === 'Нядзеля спаслання Духа Святога'
        || $base === 'Спасланне Духа Святога';
}

/**
 * Вялікі чацвер: чытанні толькі ў «Імша Хрызма» і «Імша Вячэры Пана», без асобнага радка «асноўны» па назве дня.
 */
function liturgy_effective_title_is_holy_thursday(string $effectiveTitle): bool
{
    return liturgy_strip_cycle_suffix(trim($effectiveTitle)) === 'Вялікі чацвер';
}

/**
 * Пётр і Павел (29 чэрвеня): чытанні толькі «Імша ў вігілію» і «Імша ўдзень», без радка «асноўны» па назве ўрачыстасці.
 */
function liturgy_effective_title_is_peter_and_paul_solemnity(string $effectiveTitle): bool
{
    return liturgy_strip_cycle_suffix(trim($effectiveTitle)) === 'Урачыстасць святых апосталаў Пятра і Паўла';
}

/**
 * Нараджэнне св. Яна Хрысціцеля: толькі «Імша ў вігілію» і «Імша ўдзень», без радка «асноўны» па назве ўрачыстасці.
 */
function liturgy_effective_title_is_john_baptist_nativity(string $effectiveTitle): bool
{
    return liturgy_strip_cycle_suffix(trim($effectiveTitle)) === 'Нараджэнне святога Яна Хрысціцеля';
}

/**
 * Унебаўзяцце Найсвяцейшай Панны Марыі: чытанні «Імша ўдзень» у дзень урачыстасці; «Імша ў вігілію» — на папярэдні дзень (гл. liturgy_auto_day_info).
 */
function liturgy_effective_title_is_assumption_mary(string $effectiveTitle): bool
{
    return liturgy_strip_cycle_suffix(trim($effectiveTitle)) === 'Унебаўзяцце Найсвяцейшай Панны Марыі';
}

/**
 * Успамін усіх памерлых вернікаў: толькі тры імшы, без радка «асноўны» па назве дня.
 */
function liturgy_effective_title_is_all_souls(string $effectiveTitle): bool
{
    return liturgy_strip_cycle_suffix(trim($effectiveTitle)) === 'Успамін усіх памерлых вернікаў';
}

/**
 * Нараджэнне Пана (25 снежня): толькі тры імшы, без радка «асноўны» па назве ўрачыстасці.
 */
function liturgy_effective_title_is_nativity_lord(string $effectiveTitle): bool
{
    $base = liturgy_strip_cycle_suffix(trim($effectiveTitle));

    return $base === 'Нараджэнне Пана' || $base === '25 снежня';
}

/**
 * @return array<int, array{lookup:string,label:string}>
 */
function liturgy_special_lectionary_titles_for_day(string $effectiveTitle): array
{
    $baseTitle = liturgy_strip_cycle_suffix($effectiveTitle);
    // Ранейшы памылковы аўта-загаловак; чытанні ў БД пад «Пальмовая нядзеля».
    if ($baseTitle === 'VI Нядзеля Вялікага посту') {
        return [
            ['lookup' => 'Пальмовая нядзеля', 'label' => 'Пальмовая нядзеля'],
        ];
    }
    // Старыя захаваныя загалоўкі (да выпраўлення аўта-назвы); чытанні пад Урачыстасцю Хрыста Валадара.
    if ($baseTitle === liturgy_roman(34) . ' Нядзеля Звычайнага часу') {
        return [
            ['lookup' => 'Урачыстасць Хрыста Валадара Сусвету', 'label' => 'Урачыстасць Хрыста Валадара Сусвету'],
        ];
    }
    if (str_contains($baseTitle, liturgy_roman(35) . ' Тыдзень Звычайнага часу')) {
        $fixed = str_replace(
            liturgy_roman(35) . ' Тыдзень Звычайнага часу',
            liturgy_roman(34) . ' Тыдзень Звычайнага часу',
            $baseTitle
        );

        return [
            ['lookup' => $fixed, 'label' => $fixed],
        ];
    }
    if ($baseTitle === 'Вялікі чацвер') {
        return [
            ['lookup' => 'Імша Хрызма', 'label' => 'Імша Хрызма'],
            ['lookup' => 'Імша Вячэры Пана', 'label' => 'Імша Вячэры Пана'],
        ];
    }
    if ($baseTitle === 'Нараджэнне святога Яна Хрысціцеля') {
        return [
            [
                'lookup' => 'Нараджэнне святога Яна Хрысціцеля - Імша ў вігілію',
                'label' => 'Імша ў вігілію',
            ],
            [
                'lookup' => 'Нараджэнне святога Яна Хрысціцеля - Імша ўдзень',
                'label' => 'Імша ўдзень',
            ],
        ];
    }
    if ($baseTitle === 'Урачыстасць святых апосталаў Пятра і Паўла') {
        return [
            [
                'lookup' => 'Урачыстасць святых апосталаў Пятра і Паўла - Імша ў вігілію',
                'label' => 'Імша ў вігілію',
            ],
            [
                'lookup' => 'Урачыстасць святых апосталаў Пятра і Паўла - Імша ўдзень',
                'label' => 'Імша ўдзень',
            ],
        ];
    }
    if ($baseTitle === 'Унебаўзяцце Найсвяцейшай Панны Марыі') {
        return [
            [
                'lookup' => 'Унебаўзяцце Найсвяцейшай Панны Марыі - Імша ўдзень',
                'label' => 'Імша ўдзень',
            ],
        ];
    }
    if ($baseTitle === 'Нядзеля спаслання Духа Святога' || $baseTitle === 'Спасланне Духа Святога') {
        return [
            [
                'lookup' => 'Нядзеля спаслання Духа Святога (Імша ўдзень)',
                'label' => 'Нядзеля спаслання Духа Святога (Імша ўдзень)',
            ],
        ];
    }
    if ($baseTitle === 'Успамін усіх памерлых вернікаў') {
        return [
            [
                'lookup' => 'Успамін усіх памерлых вернікаў - Першая Імша',
                'label' => 'Першая Імша',
            ],
            [
                'lookup' => 'Успамін усіх памерлых вернікаў - Другая Імша',
                'label' => 'Другая Імша',
            ],
            [
                'lookup' => 'Успамін усіх памерлых вернікаў - Трэцяя Імша',
                'label' => 'Трэцяя Імша',
            ],
        ];
    }
    if ($baseTitle === '24 снежня') {
        return [
            // Спачатку дзённая імша (ключ у БД часта «24 снежня» без суфікса).
            [
                'lookup' => '24 снежня',
                'label' => 'Імша ўдзень',
            ],
            [
                'lookup' => '24 снежня - Імша ў вігілію',
                'label' => 'Імша ў вігілію',
            ],
        ];
    }
    if ($baseTitle === 'Нараджэнне Пана' || $baseTitle === '25 снежня') {
        return [
            [
                'lookup' => '25 снежня, імша ноччу',
                'label' => 'Імша ноччу',
            ],
            [
                'lookup' => '25 снежня, імша на світанні',
                'label' => 'Імша на світанні',
            ],
            [
                'lookup' => '25 снежня, імша ўдзень',
                'label' => 'Імша ўдзень',
            ],
        ];
    }
    if (
        $baseTitle === 'Субота — VII Тыдзень Велікоднага перыяду'
        || $baseTitle === 'Субота сёмага велікоднага тыдня'
    ) {
        return [
            ['lookup' => 'Субота сёмага велікоднага тыдня - Імша раніцай', 'label' => 'Імша раніцай'],
            [
                'lookup' => 'Субота сёмага велікоднага тыдня - Імша ў вігілію - СПАСЛАННЕ ДУХА СВЯТОГА',
                'label' => 'Імша ў вігілію',
            ],
        ];
    }
    return [];
}

/**
 * @param array<int,array{title:string,text:string,open?:bool}> $sections
 */
function liturgy_render_readings_sections_html(array $sections): string
{
    if ($sections === []) {
        return '';
    }

    // Адзіная сэкцыя за дзень — без <details> і без паўтору загалоўка (назва дня ўжо ў экране праграмы).
    if (count($sections) === 1) {
        $content = (string)$sections[0]['text'];

        return sprintf(
            '<div class="liturgy-readings liturgy-readings--single"><div class="liturgy-readings-body">%s</div></div>',
            $content
        );
    }

    $detailsBlocks = [];
    foreach ($sections as $section) {
        $summary = htmlspecialchars((string)$section['title'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $content = (string)$section['text'];
        $openAttr = !empty($section['open']) ? ' open' : '';
        $detailsBlocks[] = sprintf(
            '<details%s><summary><strong>%s</strong></summary><div>%s</div></details>',
            $openAttr,
            $summary,
            $content
        );
    }

    return implode("\n\n", $detailsBlocks);
}

function liturgy_sanitize_readings_html(string $html): string
{
    $value = trim($html);
    if ($value === '') {
        return '';
    }

    // Drop embedded style/meta/script blocks from pasted editors.
    $value = preg_replace('/<(style|script|meta|link)\b[^>]*>.*?<\/\1>/isu', '', $value) ?? $value;
    $value = preg_replace('/<(meta|link)\b[^>]*\/?>/isu', '', $value) ?? $value;

    // Unwrap tags that often carry highlight/background from clipboard.
    $value = preg_replace('/<\/?font\b[^>]*>/isu', '', $value) ?? $value;
    $value = preg_replace('/<\/?mark\b[^>]*>/isu', '', $value) ?? $value;

    // Remove explicit color/background/font attributes from pasted HTML.
    $value = preg_replace('/\s(?:bgcolor|color|face|size|class|id)\s*=\s*("|\').*?\1/isu', '', $value) ?? $value;

    // Keep style attribute, but strip color/background/font-related declarations.
    $value = preg_replace_callback(
        '/\sstyle\s*=\s*("|\')(.*?)\1/isu',
        static function (array $m): string {
            $style = trim((string)($m[2] ?? ''));
            if ($style === '') {
                return '';
            }
            $rules = preg_split('/\s*;\s*/u', $style) ?: [];
            $kept = [];
            foreach ($rules as $rule) {
                $rule = trim($rule);
                if ($rule === '' || strpos($rule, ':') === false) {
                    continue;
                }
                [$prop, $val] = array_map('trim', explode(':', $rule, 2));
                $propLc = function_exists('mb_strtolower') ? mb_strtolower($prop, 'UTF-8') : strtolower($prop);
                if (in_array($propLc, [
                    'font',
                    'font-family',
                    'font-size',
                    'font-style',
                    'font-weight',
                    'line-height',
                    'letter-spacing',
                    'word-spacing',
                    'color',
                    'background',
                    'background-color',
                    'background-image',
                    'text-highlight-color',
                    'mso-highlight',
                ], true)) {
                    continue;
                }
                $kept[] = $prop . ': ' . $val;
            }
            return $kept === [] ? '' : ' style="' . htmlspecialchars(implode('; ', $kept), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
        },
        $value
    ) ?? $value;

    return trim($value);
}

/**
 * @param array<int,string> $titles
 * @return array<string, array<string,mixed>>
 */
function liturgy_fetch_lectionary_map_by_titles(array $titles): array
{
    $keys = [];
    foreach ($titles as $title) {
        $rawTitle = (string)$title;
        $k = liturgy_normalize_lectionary_key($rawTitle);
        if ($k !== '') {
            $keys[$k] = true;
        }
        $withoutCycle = liturgy_strip_cycle_suffix($rawTitle);
        if ($withoutCycle !== '' && $withoutCycle !== $rawTitle) {
            $fallbackKey = liturgy_normalize_lectionary_key($withoutCycle);
            if ($fallbackKey !== '') {
                $keys[$fallbackKey] = true;
            }
        }
        $octaveDateLabel = liturgy_christmas_octave_date_label_from_title($rawTitle);
        if ($octaveDateLabel !== '') {
            $octaveDateKey = liturgy_normalize_lectionary_key($octaveDateLabel);
            if ($octaveDateKey !== '') {
                $keys[$octaveDateKey] = true;
            }
        }
        foreach (liturgy_special_lectionary_titles_for_day($rawTitle) as $specialTitle) {
            $specialLookup = (string)($specialTitle['lookup'] ?? '');
            $specialKey = liturgy_normalize_lectionary_key($specialLookup);
            if ($specialKey !== '') {
                $keys[$specialKey] = true;
            }
        }
    }
    $lookupKeys = array_keys($keys);
    if ($lookupKeys === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($lookupKeys as $i => $key) {
        $ph = ':k' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $key;
    }

    $sql = sprintf(
        'SELECT id, title, lookup_key, text_html, liturgical_color, updated_at
         FROM liturgy_lectionary_entries
         WHERE is_active = 1
           AND lookup_key IN (%s)',
        implode(', ', $placeholders)
    );
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $key = trim((string)($row['lookup_key'] ?? ''));
        if ($key !== '') {
            $map[$key] = $row;
        }
    }
    return $map;
}

/**
 * Усе варыянты чытанняў у лекцыянарыі для дня (адмінка): асноўныя, па датце ў калядным перыядзе,
 * кожны успамін па асобным радку, дадатковыя імшы (раніца / вігілія і г.д.).
 *
 * @param array<int, string> $optionalLookupTitles
 * @param array<string, array<string,mixed>> $lectionaryMap
 * @return array<int, array{kind:string,label:string,lookup_title:string,lookup_key:string,has_text:bool}>
 */
function liturgy_admin_reading_slots(
    ?array $entry,
    string $effectiveTitle,
    string $optionalMemorialTitle,
    string $dateLookupTitle,
    array $optionalLookupTitles,
    array $lectionaryMap,
    ?DateTimeImmutable $resolveDate = null
): array {
    $manual = is_array($entry) ? liturgy_sanitize_readings_html((string)($entry['readings_full'] ?? '')) : '';
    if ($manual !== '') {
        return [[
            'kind' => 'manual',
            'label' => 'Тэкст у запісу календара',
            'lookup_title' => '',
            'lookup_key' => trim((string)($entry['lectionary_key'] ?? '')),
            'has_text' => true,
        ]];
    }

    $textAtKey = static function (string $key) use ($lectionaryMap): string {
        if ($key === '' || !isset($lectionaryMap[$key])) {
            return '';
        }

        return liturgy_sanitize_readings_html((string)($lectionaryMap[$key]['text_html'] ?? ''));
    };

    $mainKey = liturgy_normalize_lectionary_key($effectiveTitle);
    $mainBaseKey = liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($effectiveTitle));
    $mainResolvedKey = $mainKey;
    $mainText = '';
    if ($mainKey !== '' && isset($lectionaryMap[$mainKey])) {
        $mainText = $textAtKey($mainKey);
    } elseif ($mainBaseKey !== '' && isset($lectionaryMap[$mainBaseKey])) {
        $mainText = $textAtKey($mainBaseKey);
        $mainResolvedKey = $mainBaseKey;
    } else {
        $octaveDateLabel = liturgy_christmas_octave_date_label_from_title($effectiveTitle);
        $octaveDateKey = liturgy_normalize_lectionary_key($octaveDateLabel);
        if ($octaveDateKey !== '' && isset($lectionaryMap[$octaveDateKey])) {
            $mainText = $textAtKey($octaveDateKey);
            $mainResolvedKey = $octaveDateKey;
        }
        if ($mainText === '' && $dateLookupTitle !== '') {
            $dlk = liturgy_normalize_lectionary_key($dateLookupTitle);
            if ($dlk !== '' && isset($lectionaryMap[$dlk])) {
                $mainText = $textAtKey($dlk);
                $mainResolvedKey = $dlk;
            }
        }
        if ($mainText === '' && $resolveDate !== null && liturgy_title_is_paschal_octave_weekday($effectiveTitle)) {
            $legacyEasterOctave = liturgy_easter_octave_weekday_legacy_lookup_title($resolveDate);
            if ($legacyEasterOctave !== '') {
                foreach ([
                    liturgy_normalize_lectionary_key($legacyEasterOctave),
                    liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($legacyEasterOctave)),
                ] as $legacyKey) {
                    if ($legacyKey !== '' && isset($lectionaryMap[$legacyKey])) {
                        $mainText = $textAtKey($legacyKey);
                        $mainResolvedKey = $legacyKey;
                        break;
                    }
                }
            }
        }
    }

    $slots = [];
    $seenKeys = [];

    $pushUnique = static function (string $kind, string $label, string $lookupTitle, string $resolvedKey) use (&$slots, &$seenKeys, $textAtKey): void {
        if ($resolvedKey === '' || isset($seenKeys[$resolvedKey])) {
            return;
        }
        $seenKeys[$resolvedKey] = true;
        $slots[] = [
            'kind' => $kind,
            'label' => $label,
            'lookup_title' => $lookupTitle,
            'lookup_key' => $resolvedKey,
            'has_text' => $textAtKey($resolvedKey) !== '',
        ];
    };

    if (
        !liturgy_effective_title_is_saturday_seventh_easter_week($effectiveTitle)
        && !liturgy_effective_title_is_pentecost_sunday($effectiveTitle)
        && !liturgy_effective_title_is_holy_thursday($effectiveTitle)
        && !liturgy_effective_title_is_peter_and_paul_solemnity($effectiveTitle)
        && !liturgy_effective_title_is_john_baptist_nativity($effectiveTitle)
        && !liturgy_effective_title_is_assumption_mary($effectiveTitle)
        && !liturgy_effective_title_is_all_souls($effectiveTitle)
        && !liturgy_effective_title_is_nativity_lord($effectiveTitle)
    ) {
        if ($mainResolvedKey !== '') {
            $mainLabel = trim($effectiveTitle) !== '' ? trim($effectiveTitle) : 'Асноўныя чытанні';
            $pushUnique('main', $mainLabel, $effectiveTitle, $mainResolvedKey);
        } elseif (trim($effectiveTitle) !== '') {
            $cand = $mainKey !== '' ? $mainKey : $mainBaseKey;
            if ($cand !== '') {
                $seenKeys[$cand] = true;
                $slots[] = [
                    'kind' => 'main',
                    'label' => trim($effectiveTitle),
                    'lookup_title' => $effectiveTitle,
                    'lookup_key' => $cand,
                    'has_text' => $textAtKey($cand) !== '',
                ];
            }
        }
    }

    if ($dateLookupTitle !== '') {
        $dateLookupKey = liturgy_normalize_lectionary_key($dateLookupTitle);
        if ($dateLookupKey !== '') {
            $pushUnique(
                'date',
                'Па датце: ' . $dateLookupTitle,
                $dateLookupTitle,
                $dateLookupKey
            );
        }
    }

    foreach ($optionalLookupTitles as $lookupTitle) {
        if (!is_string($lookupTitle)) {
            continue;
        }
        $lt = trim($lookupTitle);
        if ($lt === '') {
            continue;
        }
        $keysToTry = [];
        $k1 = liturgy_normalize_lectionary_key($lt);
        if ($k1 !== '') {
            $keysToTry[] = $k1;
        }
        $k2 = liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($lt));
        if ($k2 !== '' && !in_array($k2, $keysToTry, true)) {
            $keysToTry[] = $k2;
        }
        $chosen = '';
        foreach ($keysToTry as $cand) {
            if ($cand === '' || isset($seenKeys[$cand])) {
                continue;
            }
            $chosen = $cand;
            break;
        }
        if ($chosen === '' && $k1 !== '' && !isset($seenKeys[$k1])) {
            $chosen = $k1;
        }
        if ($chosen === '') {
            continue;
        }
        $seenKeys[$chosen] = true;
        $slots[] = [
            'kind' => 'optional',
            'label' => $lt,
            'lookup_title' => $lt,
            'lookup_key' => $chosen,
            'has_text' => $textAtKey($chosen) !== '',
        ];
    }

    if ($optionalLookupTitles === [] && trim($optionalMemorialTitle) !== '') {
        $optionalKey = liturgy_normalize_lectionary_key($optionalMemorialTitle);
        $optionalBaseKey = liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($optionalMemorialTitle));
        foreach ([$optionalKey, $optionalBaseKey] as $cand) {
            if ($cand === '' || isset($seenKeys[$cand])) {
                continue;
            }
            $seenKeys[$cand] = true;
            $slots[] = [
                'kind' => 'optional',
                'label' => trim($optionalMemorialTitle),
                'lookup_title' => $optionalMemorialTitle,
                'lookup_key' => $cand,
                'has_text' => $textAtKey($cand) !== '',
            ];
            break;
        }
    }

    foreach (liturgy_special_lectionary_titles_for_day($effectiveTitle) as $specialTitle) {
        $specialLookup = trim((string)($specialTitle['lookup'] ?? ''));
        $specialLabel = trim((string)($specialTitle['label'] ?? ''));
        if ($specialLabel === '') {
            $specialLabel = $specialLookup;
        }
        $specialKey = liturgy_normalize_lectionary_key($specialLookup);
        if ($specialKey === '') {
            continue;
        }
        if (isset($seenKeys[$specialKey])) {
            continue;
        }
        $seenKeys[$specialKey] = true;
        $slotKind = liturgy_effective_title_is_pentecost_sunday($effectiveTitle) ? 'main' : 'special';
        $slots[] = [
            'kind' => $slotKind,
            'label' => $specialLabel,
            'lookup_title' => $specialLookup,
            'lookup_key' => $specialKey,
            'has_text' => $textAtKey($specialKey) !== '',
        ];
    }

    return $slots;
}

/**
 * Адпаведныя падпісы для кожнага радка optional_memorial_lookup_titles
 * (той жа парадак, што ў optional_memorial_title праз «альбо»).
 *
 * @param array<int, string> $optionalLookupTitles
 * @return array<int, string>
 */
function liturgy_optional_memorial_row_labels(string $optionalMemorialTitle, array $optionalLookupTitles): array
{
    $lookups = [];
    foreach ($optionalLookupTitles as $lt) {
        if (is_string($lt)) {
            $t = trim($lt);
            if ($t !== '') {
                $lookups[] = $t;
            }
        }
    }
    $raw = trim($optionalMemorialTitle);
    if ($raw === '' && $lookups === []) {
        return [];
    }
    if ($raw === '') {
        return $lookups;
    }
    $parts = liturgy_expand_optional_memorial_title_variants($raw);
    $n = max(count($lookups), count($parts));
    if ($n === 0) {
        return [];
    }
    $labels = [];
    for ($i = 0; $i < $n; $i++) {
        if (isset($parts[$i]) && $parts[$i] !== '') {
            $labels[] = $parts[$i];
        } elseif (isset($lookups[$i]) && $lookups[$i] !== '') {
            $labels[] = $lookups[$i];
        } elseif ($lookups !== []) {
            $labels[] = $lookups[count($lookups) - 1];
        }
    }

    return $labels;
}

/**
 * @param array<string, array<string,mixed>> $lectionaryMap
 * @param array<int, string> $optionalLookupTitles
 * @param DateTimeImmutable|null $displayDate калі зададзена — загалоўкі асноўных секцый у readings_full з прэфіксам дня тыдня; для даброўных успамінаў у <details> — толькі назва без дня тыдня
 * @return array{readings_full:string, lectionary_key:string, lectionary_source:string, liturgical_color:string}
 */
function liturgy_resolve_readings_text(
    ?array $entry,
    string $effectiveTitle,
    string $optionalMemorialTitle,
    array $lectionaryMap,
    string $dateLookupTitle = '',
    array $optionalLookupTitles = [],
    ?DateTimeImmutable $displayDate = null
): array {
    $manual = is_array($entry) ? liturgy_sanitize_readings_html((string)($entry['readings_full'] ?? '')) : '';
    if ($manual !== '') {
        return [
            'readings_full' => $manual,
            'lectionary_key' => is_array($entry) ? trim((string)($entry['lectionary_key'] ?? '')) : '',
            'lectionary_source' => is_array($entry) ? trim((string)($entry['lectionary_source'] ?? 'manual')) : 'manual',
            'liturgical_color' => '',
        ];
    }

    if ($displayDate instanceof DateTimeImmutable && liturgy_is_paschal_octave_weekday_date($displayDate)) {
        $optionalMemorialTitle = '';
        $optionalLookupTitles = [];
    }

    $sectionTitleWithWeekday = static function (string $label) use ($displayDate): string {
        if ($displayDate === null) {
            return $label;
        }

        return liturgy_title_with_weekday_for_display($displayDate, $label);
    };

    /** Загаловак секцыі для даброўных успамінаў у <details> — без «у аўторак, …» (дзень ужо ў кантэксце дня). */
    $sectionTitleOptionalMemorial = static function (string $label): string {
        return $label;
    };

    $sectionEffectiveTitle = $effectiveTitle;
    if ($displayDate instanceof DateTimeImmutable) {
        $wdn = liturgy_weekday_name($displayDate);
        if ($wdn !== '' && liturgy_is_paschal_octave_weekday_date($displayDate)) {
            $sectionEffectiveTitle = sprintf('%s ў актаве Пасхі', $wdn);
        } elseif ($wdn !== '' && liturgy_title_equals_paschal_octave_legacy_weekday($displayDate, $effectiveTitle)) {
            $sectionEffectiveTitle = sprintf('%s ў актаве Пасхі', $wdn);
        }
    }

    $mainKey = liturgy_normalize_lectionary_key($effectiveTitle);
    $optionalKey = liturgy_normalize_lectionary_key($optionalMemorialTitle);
    $mainBaseKey = liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($effectiveTitle));
    $mainResolvedKey = $mainKey;
    $optionalResolvedKey = $optionalKey;
    $mainText = '';
    $optionalText = '';
    $specialParts = [];
    $specialKeys = [];
    if ($mainKey !== '' && isset($lectionaryMap[$mainKey])) {
        $mainText = liturgy_sanitize_readings_html((string)($lectionaryMap[$mainKey]['text_html'] ?? ''));
    } elseif ($mainBaseKey !== '' && isset($lectionaryMap[$mainBaseKey])) {
        $mainText = liturgy_sanitize_readings_html((string)($lectionaryMap[$mainBaseKey]['text_html'] ?? ''));
        $mainResolvedKey = $mainBaseKey;
    } else {
        $octaveDateLabel = liturgy_christmas_octave_date_label_from_title($effectiveTitle);
        $octaveDateKey = liturgy_normalize_lectionary_key($octaveDateLabel);
        if ($octaveDateKey !== '' && isset($lectionaryMap[$octaveDateKey])) {
            $mainText = liturgy_sanitize_readings_html((string)($lectionaryMap[$octaveDateKey]['text_html'] ?? ''));
            $mainResolvedKey = $octaveDateKey;
        }
        if ($mainText === '' && $dateLookupTitle !== '') {
            $dateLookupKey = liturgy_normalize_lectionary_key($dateLookupTitle);
            if ($dateLookupKey !== '' && isset($lectionaryMap[$dateLookupKey])) {
                $mainText = liturgy_sanitize_readings_html((string)($lectionaryMap[$dateLookupKey]['text_html'] ?? ''));
                $mainResolvedKey = $dateLookupKey;
            }
        }
        if ($mainText === '' && $displayDate instanceof DateTimeImmutable && liturgy_title_is_paschal_octave_weekday($effectiveTitle)) {
            $legacyEasterOctave = liturgy_easter_octave_weekday_legacy_lookup_title($displayDate);
            if ($legacyEasterOctave !== '') {
                foreach ([
                    liturgy_normalize_lectionary_key($legacyEasterOctave),
                    liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($legacyEasterOctave)),
                ] as $legacyKey) {
                    if ($legacyKey !== '' && isset($lectionaryMap[$legacyKey])) {
                        $mainText = liturgy_sanitize_readings_html((string)($lectionaryMap[$legacyKey]['text_html'] ?? ''));
                        $mainResolvedKey = $legacyKey;
                        break;
                    }
                }
            }
        }
    }
    $optionalLookupsClean = [];
    foreach ($optionalLookupTitles as $lookupTitle) {
        if (!is_string($lookupTitle)) {
            continue;
        }
        $t = trim($lookupTitle);
        if ($t !== '') {
            $optionalLookupsClean[] = $t;
        }
    }
    $rowLabels = liturgy_optional_memorial_row_labels($optionalMemorialTitle, $optionalLookupsClean);
    $optionalEntries = [];
    $seenOptKeys = [];
    foreach ($optionalLookupsClean as $i => $lookupTitle) {
        $lk = liturgy_normalize_lectionary_key($lookupTitle);
        $lb = liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($lookupTitle));
        $chosen = '';
        foreach ([$lk, $lb] as $cand) {
            if ($cand === '' || $cand === $mainResolvedKey || !isset($lectionaryMap[$cand])) {
                continue;
            }
            $chosen = $cand;
            break;
        }
        if ($chosen === '' || isset($seenOptKeys[$chosen])) {
            continue;
        }
        $candidateText = liturgy_sanitize_readings_html((string)($lectionaryMap[$chosen]['text_html'] ?? ''));
        if ($candidateText === '') {
            continue;
        }
        $seenOptKeys[$chosen] = true;
        $optionalEntries[] = [
            'title' => $rowLabels[$i] ?? $lookupTitle,
            'text' => $candidateText,
            'key' => $chosen,
        ];
    }

    $optionalText = '';
    $optionalResolvedKey = '';
    if ($optionalEntries !== []) {
        $optionalText = $optionalEntries[0]['text'];
        $optionalResolvedKey = $optionalEntries[0]['key'];
    } else {
        $optionalCandidateKeys = [];
        if ($optionalKey !== '') {
            $optionalCandidateKeys[] = $optionalKey;
        }
        $optionalBaseKey = liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($optionalMemorialTitle));
        if ($optionalBaseKey !== '' && !in_array($optionalBaseKey, $optionalCandidateKeys, true)) {
            $optionalCandidateKeys[] = $optionalBaseKey;
        }
        foreach ($optionalLookupsClean as $lookupTitle) {
            $lookupKey = liturgy_normalize_lectionary_key($lookupTitle);
            if ($lookupKey !== '' && !in_array($lookupKey, $optionalCandidateKeys, true)) {
                $optionalCandidateKeys[] = $lookupKey;
            }
            $lookupBaseKey2 = liturgy_normalize_lectionary_key(liturgy_strip_cycle_suffix($lookupTitle));
            if ($lookupBaseKey2 !== '' && !in_array($lookupBaseKey2, $optionalCandidateKeys, true)) {
                $optionalCandidateKeys[] = $lookupBaseKey2;
            }
        }
        foreach ($optionalCandidateKeys as $candidateKey) {
            if ($candidateKey === '' || $candidateKey === $mainResolvedKey || !isset($lectionaryMap[$candidateKey])) {
                continue;
            }
            $candidateText = liturgy_sanitize_readings_html((string)($lectionaryMap[$candidateKey]['text_html'] ?? ''));
            if ($candidateText === '') {
                continue;
            }
            $optionalText = $candidateText;
            $optionalResolvedKey = $candidateKey;
            break;
        }
    }
    foreach (liturgy_special_lectionary_titles_for_day($effectiveTitle) as $specialTitle) {
        $specialLookup = trim((string)($specialTitle['lookup'] ?? ''));
        $specialLabel = trim((string)($specialTitle['label'] ?? ''));
        if ($specialLabel === '') {
            $specialLabel = $specialLookup;
        }
        $specialKey = liturgy_normalize_lectionary_key($specialLookup);
        if ($specialKey === '' || !isset($lectionaryMap[$specialKey])) {
            continue;
        }
        $specialText = liturgy_sanitize_readings_html((string)($lectionaryMap[$specialKey]['text_html'] ?? ''));
        if ($specialText === '') {
            continue;
        }
        $specialParts[] = [
            'title' => $specialLabel,
            'text' => $specialText,
        ];
        $specialKeys[] = $specialKey;
    }

    if ($specialParts !== []) {
        $sections = [];
        foreach ($specialParts as $idx => $part) {
            $sections[] = [
                'title' => $sectionTitleWithWeekday((string)$part['title']),
                'text' => (string)$part['text'],
                'open' => $idx === 0,
            ];
        }
        $colorFromLectionary = '';
        foreach ($specialKeys as $sk) {
            $c = liturgy_lectionary_row_liturgical_color($lectionaryMap, $sk);
            if ($c !== '') {
                $colorFromLectionary = $c;
                break;
            }
        }
        return [
            'readings_full' => liturgy_render_readings_sections_html($sections),
            'lectionary_key' => implode(' | ', $specialKeys),
            'lectionary_source' => 'lectionary:auto',
            'liturgical_color' => $colorFromLectionary,
        ];
    }

    if ($mainText === '' && $optionalText === '' && $optionalEntries === []) {
        return [
            'readings_full' => '',
            'lectionary_key' => '',
            'lectionary_source' => '',
            'liturgical_color' => '',
        ];
    }

    $combined = $mainText !== '' ? $mainText : $optionalText;
    $sourceKey = $mainResolvedKey;
    $readingsFromSections = false;
    if ($optionalEntries !== []) {
        $mainSummary = trim($sectionEffectiveTitle);
        if ($mainSummary === '') {
            $mainSummary = 'Асноўныя чытанні';
        }
        if ($mainText !== '') {
            $sections = [
                ['title' => $sectionTitleWithWeekday($mainSummary), 'text' => $mainText, 'open' => true],
            ];
            foreach ($optionalEntries as $ent) {
                $sections[] = [
                    'title' => $sectionTitleOptionalMemorial((string)$ent['title']),
                    'text' => (string)$ent['text'],
                    'open' => false,
                ];
            }
            $combined = liturgy_render_readings_sections_html($sections);
            $readingsFromSections = true;
            $keyParts = array_filter([$mainResolvedKey], static fn(string $k): bool => $k !== '');
            foreach ($optionalEntries as $ent) {
                $k = (string)$ent['key'];
                if ($k !== '') {
                    $keyParts[] = $k;
                }
            }
            $sourceKey = implode(' | ', $keyParts);
        } else {
            $sections = [];
            foreach ($optionalEntries as $idx => $ent) {
                $sections[] = [
                    'title' => $sectionTitleOptionalMemorial((string)$ent['title']),
                    'text' => (string)$ent['text'],
                    'open' => $idx === 0,
                ];
            }
            $combined = liturgy_render_readings_sections_html($sections);
            $readingsFromSections = true;
            $sourceKey = implode(' | ', array_map(static fn(array $e): string => (string)$e['key'], $optionalEntries));
        }
    } elseif ($mainText !== '' && $optionalText !== '') {
        $mainSummary = trim($sectionEffectiveTitle);
        if ($mainSummary === '') {
            $mainSummary = 'Асноўныя чытанні';
        }
        $optionalSummary = trim($optionalMemorialTitle);
        if ($optionalSummary === '') {
            $optionalSummary = 'Альтэрнатыўныя чытанні';
        }
        $combined = liturgy_render_readings_sections_html([
            ['title' => $sectionTitleWithWeekday($mainSummary), 'text' => $mainText, 'open' => true],
            ['title' => $sectionTitleOptionalMemorial($optionalSummary), 'text' => $optionalText, 'open' => false],
        ]);
        $readingsFromSections = true;
        $sourceKey = $mainResolvedKey . ' | ' . $optionalResolvedKey;
    } elseif ($mainText === '' && $optionalText !== '') {
        $sourceKey = $optionalResolvedKey;
    }

    if (!$readingsFromSections && $displayDate !== null && $combined !== '') {
        $label = '';
        if ($mainText !== '') {
            $label = trim($sectionEffectiveTitle);
            if ($label === '') {
                $label = 'Асноўныя чытанні';
            }
        } else {
            $label = trim($optionalMemorialTitle);
            if ($label === '') {
                $label = 'Альтэрнатыўныя чытанні';
            }
        }
        $combined = liturgy_render_readings_sections_html([
            ['title' => $sectionTitleWithWeekday($label), 'text' => $combined, 'open' => true],
        ]);
    }

    // Колер «асноўнага» дня: толькі з радка асноўных чытанняў. Калі пуста — сезонны аўтаколер API.
    // Не падстаўляем колер альтэрнатыўнага ўспаміна, іначай (напр. мучаніца) апынецца каля звычайнага дня.
    $colorFromLectionary = '';
    if ($mainText !== '') {
        $colorFromLectionary = liturgy_lectionary_row_liturgical_color($lectionaryMap, $mainResolvedKey);
    }
    if ($colorFromLectionary === '' && $mainText === '' && $optionalText !== '') {
        $colorFromLectionary = liturgy_lectionary_row_liturgical_color($lectionaryMap, $optionalResolvedKey);
    }

    return [
        'readings_full' => $combined,
        'lectionary_key' => $sourceKey,
        'lectionary_source' => 'lectionary:auto',
        'liturgical_color' => $colorFromLectionary,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function liturgy_fetch_entries_in_range(string $fromDate, string $toDate): array
{
    $stmt = db()->prepare(
        'SELECT liturgy_date, title_override, color_override,
                readings_full,
                lectionary_key, lectionary_source,
                updated_at
         FROM liturgy_calendar_entries
         WHERE liturgy_date BETWEEN :d1 AND :d2'
    );
    $stmt->execute([
        ':d1' => $fromDate,
        ':d2' => $toDate,
    ]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $k = (string)($row['liturgy_date'] ?? '');
        if ($k !== '') {
            $map[$k] = $row;
        }
    }
    return $map;
}

/**
 * @return array<string, mixed>|null
 */
function liturgy_fetch_entry_for_date(string $date): ?array
{
    $stmt = db()->prepare(
        'SELECT liturgy_date, title_override, color_override,
                readings_full,
                lectionary_key, lectionary_source,
                updated_at
         FROM liturgy_calendar_entries
         WHERE liturgy_date = :d
         LIMIT 1'
    );
    $stmt->execute([':d' => $date]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

