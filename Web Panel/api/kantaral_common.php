<?php
declare(strict_types=1);

function fetch_active_kantaral_for_api(): array
{
    $stmt = db()->query(
        'SELECT k.id, k.title, k.category, k.chapter_major, k.subchapter, k.content_type, k.text_body,
                k.media_path, k.media_revision, k.sort_order, k.show_number, k.show_badge,
                GROUP_CONCAT(l.id ORDER BY l.title ASC, l.id ASC SEPARATOR "||") AS lectionary_entry_ids,
                GROUP_CONCAT(l.title ORDER BY l.title ASC, l.id ASC SEPARATOR "||") AS lectionary_titles,
                GROUP_CONCAT(l.lookup_key ORDER BY l.title ASC, l.id ASC SEPARATOR "||") AS lectionary_lookup_keys
         FROM kantaral_entries k
         LEFT JOIN kantaral_lectionary_links kl ON kl.kantaral_entry_id = k.id
         LEFT JOIN liturgy_lectionary_entries l ON l.id = kl.lectionary_entry_id AND l.is_active = 1
         WHERE k.is_active = 1
         GROUP BY k.id, k.title, k.category, k.chapter_major, k.subchapter, k.content_type, k.text_body,
                  k.media_path, k.media_revision, k.sort_order, k.show_number, k.show_badge
         ORDER BY k.category ASC, k.chapter_major ASC, COALESCE(k.subchapter, 0) ASC, k.sort_order ASC, k.id ASC'
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
        $mediaPath = $row['media_path'] !== null && $row['media_path'] !== ''
            ? (string)$row['media_path']
            : null;
        $lectionaryIds = array_values(array_filter(array_map('trim', explode('||', (string)($row['lectionary_entry_ids'] ?? ''))), static fn($v) => $v !== ''));
        $lectionaryTitles = array_values(array_filter(array_map('trim', explode('||', (string)($row['lectionary_titles'] ?? ''))), static fn($v) => $v !== ''));
        $lectionaryKeys = array_values(array_filter(array_map('trim', explode('||', (string)($row['lectionary_lookup_keys'] ?? ''))), static fn($v) => $v !== ''));
        $out[] = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'category' => (string)($row['category'] ?? ''),
            'chapter_major' => (int)$row['chapter_major'],
            'subchapter' => $row['subchapter'] !== null ? (int)$row['subchapter'] : null,
            'content_type' => (string)$row['content_type'],
            'text' => (string)$row['text_body'],
            'media_url' => $mediaPath,
            'media_revision' => (string)($row['media_revision'] ?? ''),
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'show_number' => ((int)($row['show_number'] ?? 0)) !== 0,
            'show_badge' => ((int)($row['show_badge'] ?? 0)) !== 0,
            'lectionary_entry_ids' => array_map('intval', $lectionaryIds),
            'lectionary_titles' => $lectionaryTitles,
            'lectionary_lookup_keys' => $lectionaryKeys,
            'lectionary_entry_id' => isset($lectionaryIds[0]) ? (int)$lectionaryIds[0] : null,
            'lectionary_title' => (string)($lectionaryTitles[0] ?? ''),
            'lectionary_lookup_key' => (string)($lectionaryKeys[0] ?? ''),
        ];
    }

    return $out;
}
