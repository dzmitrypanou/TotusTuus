<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/liturgy_common.php';

/**
 * CSV як у GET dioceses= (пуста = усе false — агульны каляндар).
 *
 * @return array<string, bool>
 */
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

/**
 * З POST палёў ann_dioc[key]=1.
 */
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

/**
 * Назва месяца ў родным склоне (для «1 сакавіка»).
 */
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

/**
 * Змяшэнне тэксту (ручны радок, пул уборкі) з днём тыдня: 0 = панядзелак табліцы … 6 = нядзеля.
 * Шукае найранейшае ўваходжанне ключавых слоў (назоўнік / месны).
 */
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

/**
 * «1 сакавіка» (дзень + месяц).
 */
function announcements_day_month_phrase_be(DateTimeImmutable $d): string
{
    $day = (int)$d->format('j');
    $month = (int)$d->format('n');

    return $day . ' ' . announcements_month_genitive_be($month);
}

/**
 * @return list<string>
 */
function announcements_titles_allowed_for_date(DateTimeImmutable $date, ?array $dioceseOpts = null): array
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    $auto = liturgy_auto_day_info($date, $dioceseOpts);
    $allowed = [];
    $main = trim((string)($auto['title'] ?? ''));
    if ($main !== '') {
        $allowed[] = $main;
    }
    $opt = trim((string)($auto['optional_memorial_title'] ?? ''));
    if ($opt !== '') {
        $parts = preg_split('/\s+альбо\s+/u', $opt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $t = trim((string)$part);
            if ($t !== '') {
                $allowed[] = $t;
            }
        }
    }

    return array_values(array_unique($allowed));
}

/**
 * Вяртае загаловак падзеі з аўтарадка "weekday, date, title." альбо "date, title.".
 */
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

/**
 * Абарона ад публікацыі састарэлых аўтарадкоў (перакрытыя ўспаміны і г.д.).
 * Падтрымлівае складаны загаловак «A і B» (адзін радок у аб’явах замест двух).
 */
function announcements_should_publish_week_note_line(string $line, DateTimeImmutable $date, ?array $dioceseOpts = null): bool
{
    $title = announcements_extract_auto_line_title_be($line);
    if ($title === '') {
        return true;
    }
    $allowed = announcements_titles_allowed_for_date($date, $dioceseOpts);
    if ($allowed === []) {
        return true;
    }

    if (in_array($title, $allowed, true)) {
        return true;
    }

    $pieces = preg_split('/\s+і\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($pieces) < 2) {
        return false;
    }
    foreach ($pieces as $piece) {
        $t = trim((string)$piece);
        $t = preg_replace('/\.$/u', '', $t) ?? $t;
        $t = trim($t);
        if ($t === '' || !in_array($t, $allowed, true)) {
            return false;
        }
    }

    return true;
}

/**
 * Загаловак як у бюлетэні: без «, Год A/B/C».
 */
function announcements_auto_main_title(DateTimeImmutable $d, ?array $dioceseOpts = null): string
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    // Бярэм загаловак праз канчатковае аўта-развязанне дня (з улікам перакрыццяў:
    // актава Пасхі, нядзелі, suppressed optional і інш.).
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

/**
 * Назва дня тыдня ў назоўніку (для падпісаў радкоў формы).
 */
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

/**
 * @return list<array{key:string, label:string, note:string, clean:string, en_note:string, en_clean:string}>
 */
/**
 * Нядзеля бюлетэня: калі дата ў полі — нядзеля, яна ж; інакш бліжэйшая папярэдняя нядзеля.
 */
function announcements_bulletin_sunday(DateTimeImmutable $bulletinDate): DateTimeImmutable
{
    if ((int)$bulletinDate->format('w') === 0) {
        return $bulletinDate;
    }

    return $bulletinDate->modify('last sunday');
}

/**
 * Сямідзённая табліца аб’яваў: з панядзельніка адразу пасля нядзелі бюлетэня да наступнай нядзелі ўключна.
 */
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

/**
 * Ці варта аўтазапаўняць дзень: урачыстасць/свята, даброўны успамін або першы дзень табліцы (панядзелак пасля бюлетэня).
 *
 * @param DateTimeImmutable $bulletinDate дата з поля бюлетэня
 */
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

/**
 * Аўта-тэкст дня: літургічны загаловак + даброўны успамін з календара.
 * Пуста, калі дзень звычайны будзень без успамінаў (не ўрачыстасць і не першая дата бюлетэня).
 *
 * @param DateTimeImmutable $bulletinDateForWeek дата з поля «бюлетэнь» (для нядзелі — тыдзень табліцы з наступнага панядзельніка)
 */
function announcements_suggest_day_bulletin_line(DateTimeImmutable $cur, DateTimeImmutable $bulletinDateForWeek, ?array $dioceseOpts = null): string
{
    $dioceseOpts = $dioceseOpts ?? liturgy_diocese_options_default();
    if (!announcements_day_is_bulletin_notable($cur, $bulletinDateForWeek, $dioceseOpts)) {
        return '';
    }

    $auto = liturgy_auto_day_info($cur, $dioceseOpts);
    $dm = announcements_day_month_phrase_be($cur);
    $wd = announcements_ucfirst_utf8(announcements_weekday_name_locative_be($cur));
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
        $opt = trim((string)(preg_replace('/\s*\R+\s*/u', ' і ', $opt) ?? $opt));
        $line .= ' Даброўны успамін: ' . $opt . '.';
    }

    return $line;
}

/**
 * Прапановы радкоў пра даброўныя успаміны за 7 дзён: з панядзельніка пасля нядзелі бюлетэня.
 * Тое ж адсейванне, што ў лекцыянарыі: прыдушаныя дні (нядзелі посту/Велікоднага часу, святы і г.д.) без радкоў.
 *
 * @return list<array{date:string,line:string}>
 */
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
                    announcements_ucfirst_utf8(announcements_weekday_name_locative_be($cur)),
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
        $combined = implode(' і ', $titlesOk);
        if (liturgy_is_great_monday_through_easter_sunday($cur)) {
            $line = sprintf('%s, %s.', announcements_day_month_phrase_be($cur), $combined);
        } else {
            $line = sprintf(
                '%s, %s, %s.',
                announcements_ucfirst_utf8(announcements_weekday_name_locative_be($cur)),
                announcements_day_month_phrase_be($cur),
                $combined
            );
        }
        $lines[] = ['date' => $key, 'line' => $line];
    }

    return $lines;
}

/**
 * Сталыя абзацы пасля ўводнага (як у ўзоры); для зваротнай сумяшчальнасці.
 *
 * @return array<int, string>
 */
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
    return "Дзякуй за ахвяраванні і разнастайную дапамогу.\nДзякуй за вашу падтрымку парафіі.";
}

function announcements_default_thanks_pool_first_line(): string
{
    $lines = announcements_non_empty_lines(announcements_default_thanks_pool());

    return $lines[0] ?? '';
}

/**
 * @return array<int, string>
 */
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

/**
 * Адна выпадковая непустая строка з шматрадковага поля (для ўборкі / удзячнасці).
 */
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

/**
 * @return array<string, mixed>
 */
function announcements_fetch_panel_settings_row(): array
{
    require_once __DIR__ . '/../api/db.php';
    $stmt = db()->query('SELECT * FROM panel_announcements_settings WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

/**
 * Загрузка наладаў з БД з падстаноўкай прыкладаў для пустых палёў.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function announcements_merge_settings_row(array $row, string $bulletinDateYmd, bool $fillEmptyWeekFromLiturgy = true): array
{
    $tz = new DateTimeZone('UTC');
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $bulletinDateYmd, $tz);
    if ($d === false || $d->format('Y-m-d') !== $bulletinDateYmd) {
        $d = new DateTimeImmutable('now', $tz);
    }
    /** Першы радок табліцы — панядзелак пасля нядзелі бюлетэня; далей +1…+6 да нядзелі. */
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

/**
 * Ператварае POST у выгляд радка наладаў (лічбы для сцягоў).
 *
 * @return array<string, mixed>
 */
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

/**
 * @deprecated use announcements_fetch_panel_settings_row + announcements_merge_settings_row
 */
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

/**
 * @param array{
 *   date:DateTimeImmutable,
 *   main_title?:string,
 *   logo_url?:string,
 *   lead_sentence?:string,
 *   list_1?:string,
 *   list_2?:string,
 *   list_3?:string,
 *   list_4?:string,
 *   cleaning_pool?:string,
 *   thanks_pool?:string,
 *   body_paragraphs?:array<int,string>,
 *   signature_name?:string,
 *   signature_role?:string,
 *   footer_website?:string,
 *   include_suggested_optionals?:bool,
 *   en_lead?:int|bool,
 *   en_list_1?:int|bool,
 *   en_list_2?:int|bool,
 *   en_list_3?:int|bool,
 *   en_list_4?:int|bool,
 *   en_cleaning_pool?:int|bool,
 *   en_thanks_pool?:int|bool,
 *   en_signature?:int|bool,
 *   en_footer?:int|bool,
 *   week_mon_note?:string,
 *   week_mon_clean?:string,
 *   … (усе week_* і en_week_* праз форму)
 * } $opts
 * @param bool $openPrintDialog пасля загрузкі старонкі выклікаць window.print() (новая ўкладка)
 */
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
        /* Радкі тыдня, даброўныя успаміны і ўборка з пула — у адным спісе па датах (пн…нд), каб «чацвер» не апярэджаў «сераду». */
        $periodStart = announcements_week_table_monday($d);
        /** @var list<array{ymd:string,tie:int,text:string}> $timed */
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
                    $off = announcements_weekday_offset_from_be_text($n);
                    $noteDate = $off !== null
                        ? $periodStart->modify(sprintf('+%d day', $off))
                        : $cur;
                    if (announcements_should_publish_week_note_line($n, $noteDate, $dioceseOpts)) {
                        $pushTimed($noteDate->format('Y-m-d'), 0, $n);
                    }
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
        foreach ($timed as $row) {
            $items[] = $row['text'];
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
        $liHtml .= '<li>' . nl2br($esc($item)) . '</li>';
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
    /* A4 партрэт, змест на 1+ аркушах */
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
