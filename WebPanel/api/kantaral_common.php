<?php
declare(strict_types=1);

/**
 * @return list<array<string, mixed>>
 */
function fetch_active_kantaral_for_api(): array
{
    $stmt = db()->query(
        'SELECT id, title, category, chapter_major, subchapter, content_type, text_body, media_path, media_revision, sort_order
         FROM kantaral_entries
         WHERE is_active = 1
         ORDER BY category ASC, chapter_major ASC, COALESCE(subchapter, 0) ASC, sort_order ASC, id ASC'
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
        ];
    }

    return $out;
}

