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

/** Тэкст загалоўка для публічнага HTML: кастам з БД або прадвызначаны label. */
function ordo_missae_effective_section_title(array $row, array $d): string
{
    $raw = trim((string)($row[$d['title_column']] ?? ''));

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

/**
 * Складае змест секцый у парадку: сварочныя <details> з загалоўкам у <summary> (без JS у WebView).
 * Усе секцыі па змаўчанні згорнутыя (без атрыбута open); кліенты запамінаюць выбар карыстальніка.
 *
 * @param array<string, string> $partsByKey ключы як у ordo_missae_section_defs()
 * @param array<string, string> $titlesByKey тэкст загалоўка для кожнай секцыі (ужо «эфектыўны»)
 */
function ordo_missae_html_with_section_headings(array $partsByKey, array $titlesByKey): string
{
    $buf = '';
    foreach (ordo_missae_section_defs() as $d) {
        $chunk = (string)($partsByKey[$d['key']] ?? '');
        if (trim($chunk) === '') {
            continue;
        }
        $titleText = (string)($titlesByKey[$d['key']] ?? $d['label']);
        if (trim($titleText) === '') {
            $titleText = $d['label'];
        }
        $title = htmlspecialchars($titleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sk = htmlspecialchars($d['key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $buf .= '<details class="ordo-missae-section" data-ordo-section="' . $sk . '">';
        $buf .= '<summary class="ordo-missae-section-summary"><span class="ordo-missae-section-title">' . $title . '</span></summary>';
        $buf .= "\n" . $chunk . "\n";
        $buf .= '</details>';
    }

    return $buf;
}

/**
 * Публічны HTML: секцыі па парадку + загалоўкі; калі ўсе пустыя — рэзерв з слупка html.
 */
function ordo_missae_public_html_from_row(array $row): string
{
    $parts = [];
    $any = false;
    foreach (ordo_missae_section_defs() as $d) {
        $chunk = (string)($row[$d['column']] ?? '');
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

    return (string)($row['html'] ?? '');
}

/**
 * @param array<string, string> $partsByKey ключы як у ordo_missae_section_defs()
 * @param array<string, string> $titlesByKey эфектыўныя загалоўкі (пасля POST або з БД)
 */
function ordo_missae_merged_html_for_legacy_column(array $partsByKey, array $titlesByKey): string
{
    return ordo_missae_html_with_section_headings($partsByKey, $titlesByKey);
}
