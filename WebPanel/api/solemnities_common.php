<?php
declare(strict_types=1);

require_once __DIR__ . '/liturgy_common.php';

/** @return array<string, string> */
function solemnities_movable_labels(int $year): array
{
    $easter = liturgy_easter_sunday($year);
    $transfers = liturgy_calendar_transfers();
    $ascension = !empty($transfers['ascension_on_sunday'])
        ? $easter->modify('+42 day')
        : $easter->modify('+39 day');
    $corpusChristi = !empty($transfers['corpus_christi_on_sunday'])
        ? $easter->modify('+63 day')
        : $easter->modify('+60 day');
    $firstAdvent = liturgy_first_advent_sunday($year);

    return [
        'ash_wednesday' => solemnities_date_label_be($easter->modify('-46 day')),
        'palm_sunday' => solemnities_date_label_be($easter->modify('-7 day')),
        'easter' => solemnities_date_label_be($easter),
        'ascension' => solemnities_date_label_be($ascension),
        'pentecost' => solemnities_date_label_be($easter->modify('+49 day')),
        'corpus_christi' => solemnities_date_label_be($corpusChristi),
        'sacred_heart' => solemnities_date_label_be($easter->modify('+68 day')),
        'christ_king' => solemnities_date_label_be($firstAdvent->modify('-7 day')),
        'first_advent_sunday' => solemnities_date_label_be($firstAdvent),
    ];
}

function solemnities_date_label_be(DateTimeImmutable $date): string
{
    static $months = [
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
    ];
    $month = (int)$date->format('n');
    return (int)$date->format('j') . ' ' . ($months[$month] ?? $date->format('m')) . '*';
}

/**
 * @return list<array<string, mixed>>
 */
function fetch_active_solemnities_for_api(?int $year = null): array
{
    $year = $year ?? (int)date('Y');
    if ($year < 1900 || $year > 2199) {
        $year = (int)date('Y');
    }
    $movableLabels = solemnities_movable_labels($year);

    $stmt = db()->query(
        'SELECT id, date_label, date_kind, movable_key, title, section_title, sort_order
         FROM solemnities_entries
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $dateKind = (string)($row['date_kind'] ?? 'fixed');
        $movableKey = (string)($row['movable_key'] ?? '');
        $dateLabel = (string)$row['date_label'];
        if ($dateKind === 'movable' && $movableKey !== '') {
            $dateLabel = $movableLabels[$movableKey] ?? $dateLabel;
        }
        $out[] = [
            'id' => (int)$row['id'],
            'date_label' => $dateLabel,
            'date_kind' => $dateKind,
            'movable_key' => $movableKey,
            'title' => (string)$row['title'],
            'section_title' => (string)($row['section_title'] ?? ''),
            'sort_order' => (int)($row['sort_order'] ?? 0),
        ];
    }

    return $out;
}

