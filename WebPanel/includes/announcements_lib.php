<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/liturgy_common.php';

function announcements_diocese_opts_from_csv(string $csv): array
{
    $raw = liturgy_diocese_options_default();
    $q = trim($csv);
    if ($q === '') {
        return $raw;
    }
    foreach (explode(',', $q) as $part) {
        $k = strtolower(trim($part));
        if ($k !== '' && array_key_exists($k, $raw)) {
            $raw[$k] = true;
        }
    }

    return $raw;
}

function announcements_diocese_csv_from_post(array $post): string
{
    $box = isset($post['ann_dioc']) && is_array($post['ann_dioc']) ? $post['ann_dioc'] : [];
    $p = [];
    foreach (liturgy_diocese_keys() as $dk) {
        if (!empty($box[$dk])) {
            $p[] = $dk;
        }
    }

    return implode(',', $p);
}

function announcements_month_genitive_be(int $month): string
{
    return match ($month) {
        1 => 'студзеня',
        2 => 'лютага',
        3 => 'сакавіка',
        4 => 'красавіка',
        5 => 'мая',
        6 => 'чэрвеня',
        7 => 'ліпеня',
        8 => 'жніўня',
        9 => 'верасня',
        10 => 'кастрычніка',
        11 => 'лістапада',
        12 => 'снежня',
        default => '',
    };
}

function announcements_ucfirst_utf8(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return $s;
    }
    if (function_exists('mb_strtoupper') && function_exists('mb_substr')) {
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
    }

    return ucfirst($s);
}

function announcements_weekday_name_locative_be(DateTimeImmutable $d): string
{
    return match ((int)$d->format('w')) {
        1 => 'у панядзелак',
        2 => 'ў аўторак',
        3 => 'у сераду',
        4 => 'у чацвер',
        5 => 'у пятніцу',
        6 => 'у суботу',
        0 => 'у нядзелю',
        default => '',
    };
}

function announcements_weekday_locative_capitalized_for_bulletin_be(DateTimeImmutable $d): string
{
    $c = announcements_ucfirst_utf8(announcements_weekday_name_locative_be($d));
    if (mb_substr($c, 0, 1, 'UTF-8') === 'Ў') {
        return 'У' . mb_substr($c, 1, null, 'UTF-8');
    }

    return $c;
}

function announcements_normalize_bulletin_capital_u(string $text): string
{
    $out = preg_replace('/(^|\n)Ў(\s+)/u', '$1У$2', $text);

    return is_string($out) ? $out : $text;
}

function announcements_week_note_has_day_date_prefix(string $t): bool
{
    $t = trim($t);
    if ($t === '') {
        return true;
    }
    if (preg_match('/^Сёння,\s*\d{1,2}\s+\p{L}+,/u', $t) === 1) {
        return true;
    }
    if (preg_match('/^(?:Ў|У)\s+\p{L}+,\s*\d{1,2}\s+\p{L}+,/u', $t) === 1) {
        return true;
    }

    return preg_match('/^\d{1,2}\s+\p{L}+,\s*/u', $t) === 1;
}

function announcements_week_date_prefix_for_row_be(DateTimeImmutable $rowDate): string
{
    if (liturgy_is_great_monday_through_easter_sunday($rowDate)) {
        return announcements_day_month_phrase_be($rowDate) . ', ';
    }
    $wd = announcements_weekday_locative_capitalized_for_bulletin_be($rowDate);
    $dm = announcements_day_month_phrase_be($rowDate);

    return $wd . ', ' . $dm . ', ';
}

function announcements_week_note_prepend_row_date_if_missing(DateTimeImmutable $rowDate, string $text): string
{
    $t = trim($text);
    if ($t === '') {
        return $text;
    }
    if (announcements_week_note_has_day_date_prefix($t)) {
        return announcements_normalize_bulletin_capital_u($text);
    }

    return announcements_normalize_bulletin_capital_u(announcements_week_date_prefix_for_row_be($rowDate) . $t);
}

function announcements_weekday_offset_from_be_text(string $text): ?int
{
    $lower = mb_strtolower(trim($text), 'UTF-8');
    if ($lower === '') {
        return null;
    }

    $needles = [
        'у панядзелак' => 0,
        'панядзелак' => 0,
        'ў аўторак' => 1,
        'у аўторак' => 1,
        'аўторак' => 1,
        'у сераду' => 2,
        'сераду' => 2,
        'серада' => 2,
        'у чацвер' => 3,
        'чацвер' => 3,
        'у пятніцу' => 4,
        'пятніцу' => 4,
        'пятніца' => 4,
        'у суботу' => 5,
        'суботу' => 5,
        'субота' => 5,
        'у нядзелю' => 6,
        'нядзелю' => 6,
        'нядзеля' => 6,
    ];

    $bestPos = null;
    $bestOff = null;
    foreach ($needles as $needle => $off) {
        $p = mb_strpos($lower, $needle, 0, 'UTF-8');
        if ($p === false) {
            continue;
        }
        if ($bestPos === null || $p < $bestPos) {
            $bestPos = $p;
            $bestOff = $off;
        }
    }

    return $bestOff;
}

function announcements_day_month_phrase_be(DateTimeImmutable $d): string
{
    $day = (int)$d->format('j');
    $month = (int)$d->format('n');

    return $day . ' ' . announcements_month_genitive_be($month);
}

function announcements_titles_allowed_for_date(DateTimeImmutable $date, ?array $dioceseOpts = null): array
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    $auto = liturgy_auto_day_info($date, $dioceseOpts);
    $allowed = [];
    $main = trim((string)($auto['title'] ?? ''));
    if ($main !== '') {
        $allowed[] = $main;
        $stripped = preg_replace('/,\s*Год\s*[ABC]\s*$/u', '', $main);
        if (is_string($stripped)) {
            $stripped = trim($stripped);
            if ($stripped !== '' && $stripped !== $main) {
                $allowed[] = $stripped;
            }
        }
    }
    $opt = trim((string)($auto['optional_memorial_title'] ?? ''));
    if ($opt !== '') {
        $allowed[] = $opt;
        $parts = preg_split('/\s+альбо\s+/u', $opt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $t = trim((string)$part);
            if ($t !== '') {
                $allowed[] = $t;
            }
        }
        foreach (liturgy_expand_optional_memorial_title_variants($opt) as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $allowed[] = $v;
            }
        }
        $lookups = $auto['optional_memorial_lookup_titles'] ?? null;
        if (is_array($lookups)) {
            foreach ($lookups as $lt) {
                $t = trim((string)$lt);
                if ($t !== '') {
                    $allowed[] = $t;
                }
            }
        }
    }

    return array_values(array_unique($allowed));
}

function announcements_extract_auto_line_title_be(string $line): string
{
    $s = trim($line);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\.$/u', '', $s) ?? $s;
    if (preg_match('/^[^,]+,\s*\d{1,2}\s+[[:alpha:]ёўіʼ’\-]+\s*,\s*(.+)$/u', $s, $m) === 1) {
        return trim((string)($m[1] ?? ''));
    }
    if (preg_match('/^\d{1,2}\s+[[:alpha:]ёўіʼ’\-]+\s*,\s*(.+)$/u', $s, $m) === 1) {
        return trim((string)($m[1] ?? ''));
    }

    return '';
}

function announcements_piece_matches_allowed_titles(string $piece, array $allowed): bool
{
    $piece = trim($piece);
    if ($piece === '') {
        return true;
    }
    $piece = preg_replace('/\.$/u', '', $piece) ?? $piece;
    $piece = trim((string)$piece);
    if (in_array($piece, $allowed, true)) {
        return true;
    }
    $trimmedCommas = trim($piece, " \t\n\r\0\x0B,");
    if ($trimmedCommas !== '' && $trimmedCommas !== $piece && in_array($trimmedCommas, $allowed, true)) {
        return true;
    }
    $expanded = liturgy_expand_optional_memorial_title_variants($piece);
    if (count($expanded) > 1) {
        foreach ($expanded as $ex) {
            $ex = trim((string)$ex);
            $ex = preg_replace('/\.$/u', '', $ex) ?? $ex;
            $ex = trim((string)$ex);
            if ($ex === '' || !in_array($ex, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
    $pieces = preg_split('/\s+і\s+/u', $piece, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($pieces) < 2) {
        return false;
    }
    foreach ($pieces as $p) {
        $t = trim((string)$p);
        $t = preg_replace('/\.$/u', '', $t) ?? $t;
        $t = trim($t, " \t\n\r\0\x0B,");
        if ($t === '' || !in_array($t, $allowed, true)) {
            return false;
        }
    }

    return true;
}

function announcements_norm_cmp_string(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

    return mb_strtolower($s, 'UTF-8');
}

function announcements_format_optional_for_bulletin(string $rawOptional): string
{
    $opt = trim($rawOptional);
    if ($opt === '') {
        return '';
    }
    $opt = preg_replace('/\s*\R+\s*/u', ' ', $opt) ?? $opt;
    $opt = trim((string)$opt);
    $parts = preg_split('/\s+альбо\s+/u', $opt, -1, PREG_SPLIT_NO_EMPTY) ?: [$opt];
    $out = [];
    foreach ($parts as $part) {
        $p = trim((string)$part);
        if ($p !== '') {
            $out[] = $p;
        }
    }

    return implode('; ', $out);
}

function announcements_text_contains_all_calendar_optionals(string $text, DateTimeImmutable $date, ?array $dioceseOpts = null): bool
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    $auto = liturgy_auto_day_info($date, $dioceseOpts);
    $joined = trim((string)($auto['optional_memorial_title'] ?? ''));
    if ($joined === '') {
        return false;
    }
    $normText = announcements_norm_cmp_string($text);
    $parts = preg_split('/\s+альбо\s+/u', $joined, -1, PREG_SPLIT_NO_EMPTY) ?: [$joined];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') {
            continue;
        }
        $variants = liturgy_expand_optional_memorial_title_variants($part);
        if ($variants === []) {
            continue;
        }
        foreach ($variants as $variant) {
            $v = trim((string)$variant);
            if ($v === '') {
                continue;
            }
            $nv = announcements_norm_cmp_string($v);
            if ($nv === '' || mb_strpos($normText, $nv, 0, 'UTF-8') === false) {
                return false;
            }
        }
    }

    return true;
}

function announcements_should_publish_week_note_line(string $line, DateTimeImmutable $date, ?array $dioceseOpts = null): bool
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    $allowed = announcements_titles_allowed_for_date($date, $dioceseOpts);
    if ($allowed === []) {
        return true;
    }

    $marker = 'Даброўны успамін:';
    if (mb_strpos($line, $marker, 0, 'UTF-8') !== false) {
        $markerLen = mb_strlen($marker, 'UTF-8');
        $markerPos = mb_strpos($line, $marker, 0, 'UTF-8');
        $head = trim(mb_substr($line, 0, (int)$markerPos, 'UTF-8'));
        $tail = trim(mb_substr($line, (int)$markerPos + $markerLen, null, 'UTF-8'));
        $mainTitle = announcements_extract_auto_line_title_be($head);
        if ($mainTitle !== '' && !announcements_piece_matches_allowed_titles($mainTitle, $allowed)) {
            $mainExpected = announcements_auto_main_title($date, $dioceseOpts);
            if ($mainExpected === '' || announcements_norm_cmp_string($mainTitle) !== announcements_norm_cmp_string($mainExpected)) {
                return false;
            }
        }
        $tail = trim((string)(preg_replace('/\.$/u', '', $tail) ?? $tail));
        if ($tail !== '') {
            $tailOk = announcements_piece_matches_allowed_titles($tail, $allowed);
            if (!$tailOk) {
                $chunks = preg_split('/\s+;\s+|\s+альбо\s+/u', $tail, -1, PREG_SPLIT_NO_EMPTY) ?: [$tail];
                $tailOk = true;
                foreach ($chunks as $ch) {
                    if (!announcements_piece_matches_allowed_titles(trim((string)$ch), $allowed)) {
                        $tailOk = false;
                        break;
                    }
                }
            }
            if (!$tailOk) {
                $auto = liturgy_auto_day_info($date, $dioceseOpts);
                $optJoined = trim((string)($auto['optional_memorial_title'] ?? ''));
                if ($optJoined === '') {
                    return false;
                }
                $expectedTail = announcements_format_optional_for_bulletin($optJoined);
                $tailOk = announcements_norm_cmp_string($tail) === announcements_norm_cmp_string($expectedTail)
                    || announcements_text_contains_all_calendar_optionals($tail, $date, $dioceseOpts);
                if (!$tailOk) {
                    return false;
                }
            }
        }

        return true;
    }

    $title = announcements_extract_auto_line_title_be($line);
    if ($title === '') {
        return true;
    }
    if (announcements_piece_matches_allowed_titles($title, $allowed)) {
        return true;
    }
    $auto = liturgy_auto_day_info($date, $dioceseOpts);
    if (trim((string)($auto['optional_memorial_title'] ?? '')) !== ''
        && announcements_text_contains_all_calendar_optionals($title, $date, $dioceseOpts)
    ) {
        return true;
    }
    $mainExpected = announcements_auto_main_title($date, $dioceseOpts);

    return $mainExpected !== '' && announcements_norm_cmp_string($title) === announcements_norm_cmp_string($mainExpected);
}

function announcements_auto_main_title(DateTimeImmutable $d, ?array $dioceseOpts = null): string
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();

$auto = liturgy_auto_day_info($d, $dioceseOpts);
    $raw = trim((string)($auto['title'] ?? ''));
    if ($raw === '') {
        $map = liturgy_important_dates_for_year((int)$d->format('Y'), $dioceseOpts);
        $imp = $map[$d->format('Y-m-d')] ?? null;
        $raw = liturgy_auto_title($d, $imp);
    }
    $stripped = preg_replace('/,\s*Год\s*[ABC]\s*$/u', '', $raw);

    return trim((string)($stripped !== null ? $stripped : $raw));
}

function announcements_weekday_name_nominative_be(DateTimeImmutable $d): string
{
    return match ((int)$d->format('w')) {
        1 => 'Панядзелак',
        2 => 'Аўторак',
        3 => 'Серада',
        4 => 'Чацвер',
        5 => 'Пятніца',
        6 => 'Субота',
        0 => 'Нядзеля',
        default => '',
    };
}

function announcements_bulletin_sunday(DateTimeImmutable $bulletinDate): DateTimeImmutable
{
    if ((int)$bulletinDate->format('w') === 0) {
        return $bulletinDate;
    }

    return $bulletinDate->modify('last sunday');
}

function announcements_week_table_monday(DateTimeImmutable $bulletinDate): DateTimeImmutable
{
    return announcements_bulletin_sunday($bulletinDate)->modify('+1 day');
}

function announcements_week_layout(): array
{
    return [
        ['key' => 'mon', 'label' => 'Панядзелак', 'note' => 'week_mon_note', 'clean' => 'week_mon_clean', 'en_note' => 'en_week_mon_note', 'en_clean' => 'en_week_mon_clean'],
        ['key' => 'tue', 'label' => 'Аўторак', 'note' => 'week_tue_note', 'clean' => 'week_tue_clean', 'en_note' => 'en_week_tue_note', 'en_clean' => 'en_week_tue_clean'],
        ['key' => 'wed', 'label' => 'Серада', 'note' => 'week_wed_note', 'clean' => 'week_wed_clean', 'en_note' => 'en_week_wed_note', 'en_clean' => 'en_week_wed_clean'],
        ['key' => 'thu', 'label' => 'Чацвер', 'note' => 'week_thu_note', 'clean' => 'week_thu_clean', 'en_note' => 'en_week_thu_note', 'en_clean' => 'en_week_thu_clean'],
        ['key' => 'fri', 'label' => 'Пятніца', 'note' => 'week_fri_note', 'clean' => 'week_fri_clean', 'en_note' => 'en_week_fri_note', 'en_clean' => 'en_week_fri_clean'],
        ['key' => 'sat', 'label' => 'Субота', 'note' => 'week_sat_note', 'clean' => 'week_sat_clean', 'en_note' => 'en_week_sat_note', 'en_clean' => 'en_week_sat_clean'],
        ['key' => 'sun', 'label' => 'Нядзеля', 'note' => 'week_sun_note', 'clean' => 'week_sun_clean', 'en_note' => 'en_week_sun_note', 'en_clean' => 'en_week_sun_clean'],
    ];
}

function announcements_day_is_bulletin_notable(DateTimeImmutable $cur, DateTimeImmutable $bulletinDate, ?array $dioceseOpts = null): bool
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    $auto = liturgy_auto_day_info($cur, $dioceseOpts);
    if (!empty($auto['is_important'])) {
        return true;
    }
    if (trim((string)($auto['optional_memorial_title'] ?? '')) !== '') {
        return true;
    }

    $weekMonday = announcements_week_table_monday($bulletinDate);

    return $cur->format('Y-m-d') === $weekMonday->format('Y-m-d');
}

function announcements_suggest_day_bulletin_line(DateTimeImmutable $cur, DateTimeImmutable $bulletinDateForWeek, ?array $dioceseOpts = null): string
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    if (!announcements_day_is_bulletin_notable($cur, $bulletinDateForWeek, $dioceseOpts)) {
        return '';
    }

    $auto = liturgy_auto_day_info($cur, $dioceseOpts);
    $dm = announcements_day_month_phrase_be($cur);
    $wd = announcements_weekday_locative_capitalized_for_bulletin_be($cur);
    $main = trim(announcements_auto_main_title($cur, $dioceseOpts));
    if ($main === '') {
        $main = trim((string)($auto['title'] ?? ''));
    }
    if ($main === '') {
        $main = 'літургічны дзень';
    }
    $main = trim((string)(preg_replace('/\s*\R+\s*/u', ' і ', $main) ?? $main));
    if (liturgy_is_great_monday_through_easter_sunday($cur)) {
        $line = sprintf('%s, %s.', $dm, $main);
    } else {
        $line = sprintf('%s, %s, %s.', $wd, $dm, $main);
    }
    $opt = trim((string)($auto['optional_memorial_title'] ?? ''));
    if ($opt !== '') {

        $line .= ' Даброўны успамін: ' . announcements_format_optional_for_bulletin($opt) . '.';
    }

    return $line;
}

function announcements_suggest_optional_memorial_lines(DateTimeImmutable $periodStart, ?array $dioceseOpts = null): array
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    $lines = [];
    for ($i = 0; $i < 7; $i++) {
        $cur = $periodStart->modify(sprintf('+%d day', $i));
        $key = $cur->format('Y-m-d');
        $auto = liturgy_auto_day_info($cur, $dioceseOpts);
        if (empty($auto['has_optional_memorial'])) {
            continue;
        }
        $joined = trim((string)($auto['optional_memorial_title'] ?? ''));
        if ($joined === '') {
            continue;
        }
        $parts = preg_split('/\s+альбо\s+/u', $joined, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $titlesOk = [];
        foreach ($parts as $part) {
            $title = trim((string)$part);
            if ($title === '') {
                continue;
            }
            if (liturgy_is_great_monday_through_easter_sunday($cur)) {
                $probe = sprintf('%s, %s.', announcements_day_month_phrase_be($cur), $title);
            } else {
                $probe = sprintf(
                    '%s, %s, %s.',
                    announcements_weekday_locative_capitalized_for_bulletin_be($cur),
                    announcements_day_month_phrase_be($cur),
                    $title
                );
            }
            if (!announcements_should_publish_week_note_line($probe, $cur, $dioceseOpts)) {
                continue;
            }
            $titlesOk[] = $title;
        }
        if ($titlesOk === []) {
            continue;
        }
        $combined = implode('; ', $titlesOk);
        if (liturgy_is_great_monday_through_easter_sunday($cur)) {
            $line = sprintf('%s, %s.', announcements_day_month_phrase_be($cur), $combined);
        } else {
            $line = sprintf(
                '%s, %s, %s.',
                announcements_weekday_locative_capitalized_for_bulletin_be($cur),
                announcements_day_month_phrase_be($cur),
                $combined
            );
        }
        $lines[] = ['date' => $key, 'line' => $line];
    }

    return $lines;
}

function announcements_default_body_paragraphs(): array
{
    return [
        announcements_default_list_1(),
        announcements_default_list_2(),
        announcements_default_list_3(),
        'Уборка касцёла ў пятніцу пасля вячэрняй св. Імшы.',
        announcements_default_list_4(),
        announcements_default_thanks_pool_first_line(),
    ];
}

function announcements_default_list_1(): string
{
    return 'Штодня ад панядзелка да пятніцы а гадзіне 6.30 раніцай запрашаем да ўдзелу ў малітве «Ранішнія хвалы». Кожную пятніцу пасля вячэрняй св. Імшы праводзіцца набажэнства «Крыжовы шлях». А кожную нядзелю перад ранішняй св. Імшой мы спяваем «Песні Жальбы», разважаючы аб пакутах Езуса Хрыста.';
}

function announcements_default_list_2(): string
{
    return 'На гэтым тыдні ёсць першы чацвер месяца – дзень асаблівай малітвы аб пакліканнях да святарскага і манаскага жыцця; першая пятніца – дзень ўшанавання Найсвяцейшага Сэрца Езуса; першая субота – дзень, прысвечаны Беззаганнаму Сэрцу Найсвяцейшай Панны Марыі.';
}

function announcements_default_list_3(): string
{
    return 'Запрашайце святароў да хворых вернікаў, якія самі ўжо не могуць хадзіць да касцёла, каб яны маглі перад пасхальнымі святамі прыняць святыя сакрамэнты. Не адкладвайце гэтага на апошнія дні перад святам.';
}

function announcements_default_list_4(): string
{
    return 'Ахвяраванні ў наступную нядзелю будуць збірацца на патрэбы «Карытас».';
}

function announcements_default_cleaning_pool(): string
{
    return "Уборка касцёла ў пятніцу пасля вячэрняй св. Імшы.\nУборка касцёла ў суботу раніцай перад св. Імшой.";
}

function announcements_default_thanks_pool(): string
{
    return "Дзякуй за ахвяраванні і іншую дапамогу.\nДзякуй за вашу падтрымку парафіі.";
}

function announcements_is_thanks_item(string $text): bool
{
    $t = trim($text);
    if ($t === '') {
        return false;
    }
    $lower = mb_strtolower($t, 'UTF-8');

    return mb_strpos($lower, 'дзякуй', 0, 'UTF-8') === 0
        || mb_strpos($lower, 'дзякуй заахвяраванні', 0, 'UTF-8') !== false
        || mb_strpos($lower, 'дзякуй за ахвяраванні', 0, 'UTF-8') !== false;
}

function announcements_default_thanks_pool_first_line(): string
{
    $lines = announcements_non_empty_lines(announcements_default_thanks_pool());

    return $lines[0] ?? '';
}

function announcements_non_empty_lines(string $text): array
{
    $raw = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $out = [];
    foreach ($raw as $line) {
        $t = trim((string)$line);
        if ($t !== '') {
            $out[] = $t;
        }
    }

    return $out;
}

function announcements_pick_random_line(string $multiline): ?string
{
    $lines = announcements_non_empty_lines($multiline);
    if ($lines === []) {
        return null;
    }
    $i = random_int(0, count($lines) - 1);

    return $lines[$i];
}

function announcements_coalesce_display(string $fromDb, string $codeDefault): string
{
    return trim($fromDb) !== '' ? $fromDb : $codeDefault;
}

function announcements_ann_flag_on(array $row, string $col): bool
{
    return (int)($row[$col] ?? 1) === 1;
}

function announcements_post_en_flag(array $post, string $key): int
{
    return isset($post[$key]) ? 1 : 0;
}

function announcements_fetch_panel_settings_row(): array
{
    require_once __DIR__ . '/../api/db.php';
    $stmt = db()->query('SELECT * FROM panel_announcements_settings WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

function announcements_merge_settings_row(array $row, string $bulletinDateYmd, bool $fillEmptyWeekFromLiturgy = true): array
{
    $tz = new DateTimeZone('UTC');
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $bulletinDateYmd, $tz);
    if ($d === false || $d->format('Y-m-d') !== $bulletinDateYmd) {
        $d = new DateTimeImmutable('now', $tz);
    }

    $periodStart = announcements_week_table_monday($d);
    $dioceseOpts = announcements_diocese_opts_from_csv((string)($row['announcements_dioceses'] ?? ''));

    $out = [
        'announcements_dioceses' => (string)($row['announcements_dioceses'] ?? ''),
        'last_bulletin_date' => $row['last_bulletin_date'] ?? null,
        'main_title' => (string)($row['main_title'] ?? ''),
        'logo_url' => (string)($row['logo_url'] ?? ''),
        'lead_sentence' => (string)($row['lead_sentence'] ?? ''),
        'list_1' => announcements_coalesce_display((string)($row['list_1'] ?? ''), announcements_default_list_1()),
        'list_2' => announcements_coalesce_display((string)($row['list_2'] ?? ''), announcements_default_list_2()),
        'list_3' => announcements_coalesce_display((string)($row['list_3'] ?? ''), announcements_default_list_3()),
        'list_4' => announcements_coalesce_display((string)($row['list_4'] ?? ''), announcements_default_list_4()),
        'cleaning_pool' => announcements_coalesce_display((string)($row['cleaning_pool'] ?? ''), announcements_default_cleaning_pool()),
        'thanks_pool' => announcements_coalesce_display((string)($row['thanks_pool'] ?? ''), announcements_default_thanks_pool()),
        'signature_name' => (string)($row['signature_name'] ?? ''),
        'signature_role' => (string)($row['signature_role'] ?? ''),
        'footer_website' => (string)($row['footer_website'] ?? ''),
        'include_optionals' => announcements_ann_flag_on($row, 'include_optionals'),
        'en_lead' => announcements_ann_flag_on($row, 'en_lead'),
        'en_list_1' => announcements_ann_flag_on($row, 'en_list_1'),
        'en_list_2' => announcements_ann_flag_on($row, 'en_list_2'),
        'en_list_3' => announcements_ann_flag_on($row, 'en_list_3'),
        'en_list_4' => announcements_ann_flag_on($row, 'en_list_4'),
        'en_cleaning_pool' => announcements_ann_flag_on($row, 'en_cleaning_pool'),
        'en_thanks_pool' => announcements_ann_flag_on($row, 'en_thanks_pool'),
        'en_signature' => announcements_ann_flag_on($row, 'en_signature'),
        'en_footer' => announcements_ann_flag_on($row, 'en_footer'),
    ];

    foreach (announcements_week_layout() as $i => $spec) {
        $cur = $periodStart->modify(sprintf('+%d day', $i));
        $nk = $spec['note'];
        $ck = $spec['clean'];
        if ($fillEmptyWeekFromLiturgy) {
            $out[$nk] = announcements_coalesce_display(
                (string)($row[$nk] ?? ''),
                announcements_suggest_day_bulletin_line($cur, $d, $dioceseOpts)
            );
        } else {
            $out[$nk] = (string)($row[$nk] ?? '');
        }
        $out[$ck] = (string)($row[$ck] ?? '');
        $out[$spec['en_note']] = announcements_ann_flag_on($row, $spec['en_note']);
        $out[$spec['en_clean']] = announcements_ann_flag_on($row, $spec['en_clean']);
    }

    return $out;
}

function announcements_post_as_settings_row(array $post): array
{
    $row = $post;
    $row['include_optionals'] = isset($post['include_suggested_optionals']) ? 1 : 0;
    $row['announcements_dioceses'] = announcements_diocese_csv_from_post($post);
    $row['en_lead'] = announcements_post_en_flag($post, 'en_lead');
    $row['en_list_1'] = announcements_post_en_flag($post, 'en_list_1');
    $row['en_list_2'] = announcements_post_en_flag($post, 'en_list_2');
    $row['en_list_3'] = announcements_post_en_flag($post, 'en_list_3');
    $row['en_list_4'] = announcements_post_en_flag($post, 'en_list_4');
    $row['en_cleaning_pool'] = announcements_post_en_flag($post, 'en_cleaning_pool');
    $row['en_thanks_pool'] = announcements_post_en_flag($post, 'en_thanks_pool');
    $row['en_signature'] = announcements_post_en_flag($post, 'en_signature');
    $row['en_footer'] = announcements_post_en_flag($post, 'en_footer');
    foreach (announcements_week_layout() as $spec) {
        $row[$spec['en_note']] = announcements_post_en_flag($post, $spec['en_note']);
        $row[$spec['en_clean']] = announcements_post_en_flag($post, $spec['en_clean']);
    }

    return $row;
}

function announcements_load_settings_merged(): array
{
    $row = announcements_fetch_panel_settings_row();
    $tz = new DateTimeZone('UTC');
    $ymd = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $ldb = $row['last_bulletin_date'] ?? null;
    if (is_string($ldb) && $ldb !== '' && $ldb !== '0000-00-00') {
        $ymd = $ldb;
    }

    return announcements_merge_settings_row($row, $ymd);
}

function announcements_extract_bulletin_day_prefix(string $text): ?string
{
    $t = trim($text);
    if ($t === '') {
        return null;
    }
    if (preg_match('/^(Сёння,\s*\d{1,2}\s+\p{L}+,\s*)/u', $t, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/^((?:Ў|У)\s+\p{L}+,\s*\d{1,2}\s+\p{L}+,\s*)/u', $t, $m) === 1) {
        return announcements_normalize_bulletin_capital_u($m[1]);
    }
    if (preg_match('/^((?:Ў|У)\s+\p{L}+,\s*)/u', $t, $m) === 1) {
        return announcements_normalize_bulletin_capital_u($m[1]);
    }
    if (preg_match('/^(\d{1,2}\s+\p{L}+,\s*)/u', $t, $m) === 1) {
        return $m[1];
    }

    return null;
}

function announcements_strip_bulletin_day_prefix(string $text, string $prefix): string
{
    $t = trim($text);
    $p = $prefix;
    if ($p !== '' && mb_strpos($t, $p, 0, 'UTF-8') === 0) {
        return trim(mb_substr($t, mb_strlen($p, 'UTF-8'), null, 'UTF-8'));
    }

    return $t;
}

function announcements_timed_rows_to_list_entries(array $timed): array
{
    if ($timed === []) {
        return [];
    }

    $byYmd = [];
    foreach ($timed as $row) {
        $ymd = $row['ymd'];
        if (!isset($byYmd[$ymd])) {
            $byYmd[$ymd] = [];
        }
        $byYmd[$ymd][] = $row;
    }
    $order = [];
    $seen = [];
    foreach ($timed as $row) {
        $ymd = $row['ymd'];
        if (!isset($seen[$ymd])) {
            $seen[$ymd] = true;
            $order[] = $ymd;
        }
    }
    $out = [];
    foreach ($order as $ymd) {
        $rows = $byYmd[$ymd];
        if (count($rows) === 1) {
            $out[] = $rows[0]['text'];
            continue;
        }
        $normTexts = [];
        foreach ($rows as $r) {
            $normTexts[] = announcements_normalize_bulletin_capital_u(trim($r['text']));
        }
        $prefixes = array_map(
            static fn(string $t): ?string => announcements_extract_bulletin_day_prefix($t),
            $normTexts
        );
        $firstPref = $prefixes[0];
        $mergeOk = $firstPref !== null;
        if ($mergeOk) {
            foreach ($prefixes as $pr) {
                if ($pr !== $firstPref) {
                    $mergeOk = false;
                    break;
                }
            }
        }
        if ($mergeOk && $firstPref !== null) {
            $parts = [];
            foreach ($normTexts as $t) {
                $body = trim(announcements_strip_bulletin_day_prefix($t, $firstPref));
                if ($body !== '') {

                    $parts[] = $body;
                }
            }
            if ($parts !== []) {
                $out[] = ['merged' => true, 'prefix' => $firstPref, 'parts' => $parts];
                continue;
            }
        }
        foreach ($rows as $r) {
            $out[] = $r['text'];
        }
    }

    return $out;
}

function announcements_split_bulletin_semicolon_clauses(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $raw = preg_split('/\s*;\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($raw === false || count($raw) <= 1) {
        return [$text];
    }
    $out = [];
    foreach ($raw as $p) {
        $t = trim((string)$p);
        if ($t !== '') {
            $out[] = $t;
        }
    }

    return count($out) > 1 ? $out : [$text];
}

function announcements_bulletin_clause_ensure_memorial_prefix_be(string $clause, bool $capitalUspamin): string
{
    $s = trim($clause);
    if ($s === '') {
        return $clause;
    }
    $word = $capitalUspamin ? 'Успамін ' : 'успамін ';
    if (preg_match('/^Успамін\s+/u', $s) === 1) {
        $rest = trim((string)(preg_replace('/^Успамін\s+/u', '', $s)));

        return $word . $rest;
    }
    if (preg_match('/^успамін\s+/u', $s) === 1) {
        $rest = trim((string)(preg_replace('/^успамін\s+/u', '', $s)));

        return $word . $rest;
    }
    if (preg_match('/^(?:Урачыстасць|Свята)\b/iu', $s) === 1) {
        return $s;
    }

    if (preg_match('/^(?:C|С)в\./iu', $s) === 1 || preg_match('/^Бл\./iu', $s) === 1) {
        return $word . $s;
    }

    return $s;
}

function announcements_bulletin_map_memorial_prefixes(array $chunks, bool $capitalUspamin): array
{
    return array_map(
        static fn(string $c): string => announcements_bulletin_clause_ensure_memorial_prefix_be(trim($c), $capitalUspamin),
        $chunks
    );
}

function announcements_format_ann_lead_before_sublist(string $lead): string
{
    $s = rtrim($lead);
    if ($s === '') {
        return $lead;
    }
    if (mb_substr($s, -1, 1, 'UTF-8') === ',') {
        return mb_substr($s, 0, -1, 'UTF-8') . ': ';
    }

    return $s . ': ';
}

function announcements_ann_merged_day_block_to_html(array $block): string
{
    $prefix = (string)($block['prefix'] ?? '');
    $parts = $block['parts'] ?? [];
    if (!is_array($parts) || $parts === []) {
        return '<li></li>';
    }
    $esc = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $sub = '';
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '') {
            continue;
        }
        foreach (announcements_bulletin_map_memorial_prefixes(announcements_split_bulletin_semicolon_clauses($p), true) as $chunk) {
            $chunk = trim((string)$chunk);
            if ($chunk === '') {
                continue;
            }
            $chunk = announcements_ann_uppercase_first_cyrillic_lower($chunk);
            $sub .= '<li class="ann-sublist-item">' . nl2br($esc($chunk)) . '</li>';
        }
    }
    if ($sub === '') {
        return '<li></li>';
    }

    $leadForSub = announcements_format_ann_lead_before_sublist($prefix);

    return '<li class="ann-li-merged-day"><strong class="ann-li-lead">' . $esc($leadForSub) . '</strong>'
        . '<ul class="ann-sublist">' . $sub . '</ul></li>';
}

function announcements_ann_list_item_split_lead(string $firstLine): array
{
    if (preg_match('/^Сёння,\s*\d{1,2}\s+\p{L}+,\s*/u', $firstLine, $m)) {
        $pfx = $m[0];

        return [$pfx, mb_substr($firstLine, mb_strlen($pfx, 'UTF-8'), null, 'UTF-8')];
    }
    if (preg_match('/^(?:Ў|У)\s+\p{L}+,\s*\d{1,2}\s+\p{L}+,\s*/u', $firstLine, $m)) {
        $pfx = $m[0];

        return [$pfx, mb_substr($firstLine, mb_strlen($pfx, 'UTF-8'), null, 'UTF-8')];
    }
    if (preg_match('/^(?:Ў|У)\s+\p{L}+,\s*/u', $firstLine, $m)) {
        $pfx = $m[0];

        return [$pfx, mb_substr($firstLine, mb_strlen($pfx, 'UTF-8'), null, 'UTF-8')];
    }
    if (preg_match('/^\d{1,2}\s+\p{L}+,\s*/u', $firstLine, $m)) {
        $pfx = $m[0];

        return [$pfx, mb_substr($firstLine, mb_strlen($pfx, 'UTF-8'), null, 'UTF-8')];
    }
    $commaPos = mb_strpos($firstLine, ',', 0, 'UTF-8');
    if ($commaPos !== false) {
        $pfx = mb_substr($firstLine, 0, $commaPos + 1, 'UTF-8');
        $rest = ltrim(mb_substr($firstLine, $commaPos + 1, null, 'UTF-8'));

        return [$pfx . ' ', $rest];
    }
    if (preg_match('/^(\S+\s+\S+)/u', $firstLine, $m)) {
        return [$m[1], mb_substr($firstLine, mb_strlen($m[1], 'UTF-8'), null, 'UTF-8')];
    }
    if (preg_match('/^(\S+)/u', $firstLine, $m)) {
        return [$m[1], mb_substr($firstLine, mb_strlen($m[1], 'UTF-8'), null, 'UTF-8')];
    }

    return ['', $firstLine];
}

function announcements_ann_gap_after_lead_html(string $rest): string
{
    if ($rest === '') {
        return '';
    }
    if (preg_match('/^\s/u', $rest) === 1) {
        return '';
    }

    if (preg_match('/^[.,;:!?…)\]]/u', $rest) === 1) {
        return '';
    }

    return ' ';
}

function announcements_ann_lowercase_first_cyrillic_upper(string $text): string
{
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        if ($ch === '') {
            return $text;
        }
        if (preg_match('/^\s$/u', $ch) === 1) {
            continue;
        }
        if (preg_match('/^\p{Cyrillic}$/u', $ch) !== 1) {
            return $text;
        }
        $lo = mb_strtolower($ch, 'UTF-8');
        if ($lo === $ch) {
            return $text;
        }

        return mb_substr($text, 0, $i, 'UTF-8') . $lo . mb_substr($text, $i + 1, null, 'UTF-8');
    }

    return $text;
}

function announcements_ann_tail_after_comma_lead_lowercase(string $lead, string $tail): string
{
    if ($tail === '') {
        return $tail;
    }
    $leadTrim = rtrim($lead);
    if ($leadTrim === '' || !str_ends_with($leadTrim, ',')) {
        return $tail;
    }

    return announcements_ann_lowercase_first_cyrillic_upper($tail);
}

function announcements_ann_uppercase_first_cyrillic_lower(string $text): string
{
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        if ($ch === '') {
            return $text;
        }
        if (preg_match('/^\s$/u', $ch) === 1) {
            continue;
        }
        if (preg_match('/^\p{Cyrillic}$/u', $ch) !== 1) {
            return $text;
        }
        $up = mb_strtoupper($ch, 'UTF-8');
        if ($up === $ch) {
            return $text;
        }

        return mb_substr($text, 0, $i, 'UTF-8') . $up . mb_substr($text, $i + 1, null, 'UTF-8');
    }

    return $text;
}

function announcements_ann_list_item_to_html(string $item): string
{
    $item = announcements_normalize_bulletin_capital_u($item);
    $norm = str_replace(["\r\n", "\r"], "\n", $item);
    $parts = explode("\n", $norm, 2);
    $first = $parts[0];
    $more = isset($parts[1]) ? "\n" . $parts[1] : '';

if (announcements_is_thanks_item($item)) {
        $esc = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        return nl2br($esc($item));
    }

    [$lead, $tail] = announcements_ann_list_item_split_lead($first);
    $esc = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    if ($lead === '') {
        return nl2br($esc($item));
    }
    $rawChunks = announcements_split_bulletin_semicolon_clauses($tail);
    if (count($rawChunks) <= 1) {
        $single = $rawChunks[0] ?? $tail;
        $single = announcements_ann_tail_after_comma_lead_lowercase($lead, $single);
        $rawChunks = trim($single) === '' ? [] : [$single];
    }
    $chunks = announcements_bulletin_map_memorial_prefixes($rawChunks, count($rawChunks) > 1);
    if (count($chunks) > 1) {
        $chunks = array_map(
            static fn(string $c): string => announcements_ann_uppercase_first_cyrillic_lower(trim($c)),
            $chunks
        );
        $lis = '';
        $n = count($chunks);
        for ($i = 0; $i < $n; $i++) {
            $cell = nl2br($esc($chunks[$i]));
            if ($i === $n - 1 && $more !== '') {
                $cell .= nl2br($esc($more));
            }
            $lis .= '<li class="ann-sublist-item">' . $cell . '</li>';
        }
        $leadForSub = announcements_format_ann_lead_before_sublist($lead);

        return '<strong class="ann-li-lead">' . $esc($leadForSub) . '</strong><ul class="ann-sublist">' . $lis . '</ul>';
    }
    $singleBody = $chunks[0] ?? $tail;
    $rest = $singleBody . $more;

    return '<strong class="ann-li-lead">' . $esc($lead) . '</strong>'
        . announcements_ann_gap_after_lead_html($rest)
        . ($rest !== '' ? nl2br($esc($rest)) : '');
}

function announcements_render_html(array $opts, bool $openPrintDialog = false): string
{
    $d = $opts['date'];
    $dioceseOpts = isset($opts['diocese_opts']) && is_array($opts['diocese_opts'])
        ? liturgy_normalize_diocese_options($opts['diocese_opts'])
        : announcements_diocese_opts_from_csv((string)($opts['announcements_dioceses'] ?? ''));
    $mainTitle = trim((string)($opts['main_title'] ?? ''));
    if ($mainTitle === '') {
        $mainTitle = announcements_auto_main_title($d, $dioceseOpts);
    }

    $dateLine = '/' . $d->format('d.m.Y') . '/';
    $logoUrl = trim((string)($opts['logo_url'] ?? ''));
    $lead = trim((string)($opts['lead_sentence'] ?? ''));
    if ($lead === '') {
        $dm = announcements_day_month_phrase_be($d);
        $lit = announcements_auto_main_title($d, $dioceseOpts);
        $lead = sprintf('Сёння, %s, %s.', $dm, $lit);
    }

    $on = static function (array $o, string $k): bool {
        return (int)($o[$k] ?? 1) === 1;
    };

    $items = [];
    if ($on($opts, 'en_lead')) {
        $items[] = $lead;
    }

    if (array_key_exists('list_1', $opts)) {

        $periodStart = announcements_week_table_monday($d);

        $timed = [];
        $pushTimed = static function (string $ymd, int $tie, string $text) use (&$timed): void {
            $timed[] = ['ymd' => $ymd, 'tie' => $tie, 'text' => $text];
        };

        foreach (announcements_week_layout() as $i => $spec) {
            $cur = $periodStart->modify(sprintf('+%d day', $i));
            $ymd = $cur->format('Y-m-d');
            if ($on($opts, $spec['en_note'])) {
                $n = trim((string)($opts[$spec['note']] ?? ''));
                if ($n !== '') {
                    $n = announcements_week_note_prepend_row_date_if_missing($cur, $n);
                    $off = announcements_weekday_offset_from_be_text($n);
                    $noteDate = $off !== null
                        ? $periodStart->modify(sprintf('+%d day', $off))
                        : $cur;

$pushTimed($noteDate->format('Y-m-d'), 0, $n);
                }
            }
            if ($on($opts, $spec['en_clean'])) {
                $c = trim((string)($opts[$spec['clean']] ?? ''));
                if ($c !== '') {
                    $off = announcements_weekday_offset_from_be_text($c);
                    $dKey = $off !== null
                        ? $periodStart->modify(sprintf('+%d day', $off))->format('Y-m-d')
                        : $ymd;
                    $pushTimed($dKey, 2, $c);
                }
            }
        }
        if (!empty($opts['include_suggested_optionals'])) {
            foreach (announcements_suggest_optional_memorial_lines($periodStart, $dioceseOpts) as $sug) {
                $sugDate = DateTimeImmutable::createFromFormat('Y-m-d', (string)$sug['date'], new DateTimeZone('UTC'));
                if ($sugDate instanceof DateTimeImmutable
                    && !announcements_should_publish_week_note_line((string)$sug['line'], $sugDate, $dioceseOpts)
                ) {
                    continue;
                }
                $pushTimed($sug['date'], 1, $sug['line']);
            }
        }
        if ($on($opts, 'en_cleaning_pool')) {
            $cleanPick = announcements_pick_random_line((string)($opts['cleaning_pool'] ?? ''));
            if ($cleanPick !== null) {
                $off = announcements_weekday_offset_from_be_text($cleanPick);
                $ymdPool = $off !== null
                    ? $periodStart->modify(sprintf('+%d day', $off))->format('Y-m-d')
                    : $periodStart->modify('+4 day')->format('Y-m-d');
                $pushTimed($ymdPool, 3, $cleanPick);
            }
        }

        usort($timed, static function (array $a, array $b): int {
            $c = strcmp($a['ymd'], $b['ymd']);

            return $c !== 0 ? $c : ($a['tie'] <=> $b['tie']);
        });
        foreach (announcements_timed_rows_to_list_entries($timed) as $entry) {
            $items[] = $entry;
        }

        $listEn = ['list_1' => 'en_list_1', 'list_2' => 'en_list_2', 'list_3' => 'en_list_3', 'list_4' => 'en_list_4'];
        foreach ($listEn as $lk => $ek) {
            if (!$on($opts, $ek)) {
                continue;
            }
            $chunk = trim((string)($opts[$lk] ?? ''));
            if ($chunk !== '') {
                $items[] = $chunk;
            }
        }
        if ($on($opts, 'en_thanks_pool')) {
            $thanksPick = announcements_pick_random_line((string)($opts['thanks_pool'] ?? ''));
            if ($thanksPick !== null) {
                $items[] = $thanksPick;
            }
        }
    } else {
        $paras = array_key_exists('body_paragraphs', $opts)
            ? $opts['body_paragraphs']
            : announcements_default_body_paragraphs();
        if (!is_array($paras)) {
            $paras = announcements_default_body_paragraphs();
        }
        $paras = array_values(array_filter(array_map('trim', $paras), static fn(string $s): bool => $s !== ''));
        foreach ($paras as $p) {
            $items[] = $p;
        }
        if (!empty($opts['include_suggested_optionals'])) {
            foreach (announcements_suggest_optional_memorial_lines(announcements_week_table_monday($d), $dioceseOpts) as $sug) {
                $sugDate = DateTimeImmutable::createFromFormat('Y-m-d', (string)$sug['date'], new DateTimeZone('UTC'));
                if ($sugDate instanceof DateTimeImmutable
                    && !announcements_should_publish_week_note_line((string)$sug['line'], $sugDate, $dioceseOpts)
                ) {
                    continue;
                }
                $items[] = $sug['line'];
            }
        }
    }

    $sigName = trim((string)($opts['signature_name'] ?? ''));
    if ($sigName === '') {
        $sigName = 'кс. …';
    }
    if (!str_ends_with($sigName, ',')) {
        $sigName .= ',';
    }
    $sigRole = trim((string)($opts['signature_role'] ?? ''));
    if ($sigRole === '') {
        $sigRole = 'пробашч';
    }
    $footer = trim((string)($opts['footer_website'] ?? ''));
    if ($footer === '') {
        $footer = 'www.example.by';
    }

    $esc = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $dateHtml = '<p class="ann-date"><span class="ann-date-inner">' . $esc($dateLine) . '</span></p>';
    if ($logoUrl !== '') {
        $mastheadClass = 'ann-masthead ann-masthead--with-logo';
        $mastheadInner = '<div class="ann-masthead-inner">'
            . '<div class="ann-logo-block"><img class="ann-logo-img" src="' . $esc($logoUrl) . '" alt="" /></div>'
            . '<div class="ann-masthead-text">'
            . '<h1 class="ann-title">' . $esc($mainTitle) . '</h1>'
            . $dateHtml
            . '</div>'
            . '</div>';
    } else {
        $mastheadClass = 'ann-masthead ann-masthead--no-logo';
        $mastheadInner = '<h1 class="ann-title ann-title-only">' . $esc($mainTitle) . '</h1>' . $dateHtml;
    }

    $liHtml = '';
    foreach ($items as $item) {
        if (is_array($item) && !empty($item['merged'])) {
            $liHtml .= announcements_ann_merged_day_block_to_html($item);
        } else {
            $liHtml .= '<li>' . announcements_ann_list_item_to_html(is_string($item) ? $item : '') . '</li>';
        }
    }

    $closeBlock = '';
    if ($on($opts, 'en_signature')) {
        $closeBlock .= '<p class="ann-sig">' . $esc($sigName) . '<br>' . $esc($sigRole) . '</p>';
    }
    if ($on($opts, 'en_footer')) {
        $closeBlock .= '<p class="ann-footer">Аб’явы і іншая інфармацыя таксама на сайце касцёла ' . $esc($footer) . '</p>';
    }
    $closeWrap = $closeBlock !== '' ? '<div class="ann-close">' . $closeBlock . '</div>' : '';

    return '<!DOCTYPE html>
<html lang="be">
<head>
  <meta charset="utf-8">
  <title>' . $esc($mainTitle) . ' — ' . $esc($dateLine) . '</title>
  <style>
    @page {
      size: A4 portrait;
      margin: 12mm 14mm;
    }
    *, *::before, *::after { box-sizing: border-box; }
    html { height: auto; }
    body.ann-root {
      margin: 0;
      padding: 0;
      color: #252320;
      font-size: 16pt;
      line-height: 1.18;
      background: #e9e5de;
      -webkit-font-smoothing: antialiased;
      font-family: Georgia, "Times New Roman", "DejaVu Serif", serif;
    }
    .ann-sheet {
      max-width: 210mm;
      margin: 0 auto;
      min-height: 100vh;
      padding: 10mm 0 14mm;
    }
    .ann-frame {
      background: #fffef9;
      margin: 0 12px;
      padding: 18.9mm 21.8mm 23.3mm;
      border: 1px solid #d8d0c4;
      border-radius: 2px;
      box-shadow: 0 4px 24px rgba(35, 30, 22, 0.07);
    }
    .ann-masthead {
      page-break-after: avoid;
      page-break-inside: avoid;
      margin: 0 0 2mm;
      padding: 0 0 calc(10pt * 16 / 11);
      border-bottom: 1.5pt solid #c6a76a;
    }
    .ann-masthead--no-logo {
      text-align: center;
    }
    .ann-masthead--with-logo .ann-masthead-inner {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: clamp(calc(10pt * 16 / 11), 3.2vw, calc(28pt * 16 / 11));
      text-align: left;
    }
    .ann-masthead--with-logo .ann-masthead-text {
      flex: 1;
      min-width: 0;
      text-align: center;
    }
    .ann-masthead--with-logo .ann-logo-block {
      flex-shrink: 0;
      margin: 0;
      padding: calc(6pt * 16 / 11) calc(12pt * 16 / 11) calc(6pt * 16 / 11) 0;
      text-align: center;
      align-self: center;
    }
    .ann-logo-img {
      display: block;
      width: auto;
      max-width: min(28vw, 180px);
      max-height: min(34mm, 20vh);
      height: auto;
      object-fit: contain;
      object-position: center center;
      border-radius: 3px;
    }
    h1.ann-title {
      text-align: center;
      font-size: 17pt;
      font-weight: bold;
      font-style: italic;
      margin: 0 0 2pt;
      line-height: 1.09;
      color: #1a1815;
      letter-spacing: 0.02em;
    }
    .ann-masthead--with-logo h1.ann-title {
      text-align: center;
      hyphens: manual;
    }
    h1.ann-title-only { margin: 0 0 4pt; }
    p.ann-date { margin: calc(6pt * 16 / 11) 0 0; text-align: center; width: 100%; }
    .ann-masthead--with-logo p.ann-date { margin-top: calc(6pt * 16 / 11); }
    .ann-date-inner {
      display: inline-block;
      padding: calc(5pt * 16 / 11) calc(16pt * 16 / 11) calc(6pt * 16 / 11);
      border: 0.75pt solid #c4b08c;
      font-size: calc(12.5pt * 16 / 11);
      font-weight: bold;
      font-style: italic;
      color: #3d3428;
      letter-spacing: 0.06em;
      background: #faf7f2;
    }
    ol.ann-list {
      margin: calc(11pt * 16 / 11) 0 0;
      padding: 0 0 0 1.55em;
      font-size: calc(10.5pt * 16 / 11);
      line-height: 1.2;
      text-align: justify;
      hyphens: auto;
      -webkit-hyphens: auto;
      orphans: 2;
      widows: 2;
      color: #2c2924;
    }
    ol.ann-list li {
      margin: 0 0 0.26em;
      padding-left: 0.2em;
      break-inside: auto;
      page-break-inside: auto;
    }
    ol.ann-list li .ann-li-lead {
      font-weight: bold;
    }
    ol.ann-list > li.ann-li-merged-day {
      margin: 0 0 0.26em;
      padding-left: 0.2em;
    }
    ol.ann-list ul.ann-sublist {
      margin: 0.18em 0 0 0;
      padding: 0 0 0 1.35em;
      list-style-type: disc;
      font-weight: normal;
    }
    ol.ann-list ul.ann-sublist li.ann-sublist-item {
      margin: 0.1em 0;
      padding-left: 0;
      page-break-inside: avoid;
    }
    .ann-close {
      margin-top: calc(26pt * 16 / 11);
      padding-top: calc(10pt * 16 / 11);
      border-top: 0.5pt solid #e5ddd0;
      break-inside: avoid;
      page-break-inside: avoid;
    }
    .ann-sig {
      margin: 0 0 0.45em;
      padding-left: 0.2em;
      font-size: calc(10.5pt * 16 / 11);
      font-style: italic;
      line-height: 1.14;
      color: #333;
    }
    .ann-footer {
      margin: 1.4em 0 0;
      text-align: justify;
      font-size: calc(9pt * 16 / 11);
      font-weight: bold;
      font-style: italic;
      line-height: 1.175;
      color: #5c5348;
    }
    @media print {
      body.ann-root { background: #fff; padding: 0; }
      .ann-sheet { padding: 0; max-width: none; }
      .ann-frame { margin: 0; padding: 0; border: 0; box-shadow: none; border-radius: 0; background: #fff; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body class="ann-root">
  <div class="ann-sheet">
    <div class="ann-frame">
<div class="' . $mastheadClass . '">
' . $mastheadInner . '
</div>
<ol class="ann-list">' . $liHtml . '</ol>
' . $closeWrap . '
    </div>
  </div>
' . ($openPrintDialog ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},150);});</script>' : '') . '
</body>
</html>';
}
