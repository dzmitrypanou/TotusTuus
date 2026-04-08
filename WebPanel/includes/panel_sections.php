<?php
declare(strict_types=1);

/** @return list<string> */
function panel_content_section_keys(): array
{
    return ['prayers', 'songbook', 'scripture', 'liturgy', 'lectionary', 'announcements'];
}

/** @return array<string, string> */
function panel_section_labels_be(): array
{
    return [
        'prayers' => 'Малітвы і катэгорыі',
        'songbook' => 'Спеўнік',
        'scripture' => 'Біблія (пераклады)',
        'liturgy' => 'Літургія (каляндар, святы, пустыя дні)',
        'lectionary' => 'Лекцыянарый',
        'announcements' => 'Аб’явы (бюлетэнь)',
    ];
}

function panel_valid_section_key(string $key): bool
{
    return in_array($key, panel_content_section_keys(), true);
}

/** Раздзел для падстаронкі галоўнай панэлі (?view=). */
function panel_view_section(string $view): ?string
{
    static $map = [
        'categories' => 'prayers',
        'add-category' => 'prayers',
        'add-prayer' => 'prayers',
        'prayers' => 'prayers',
        'songbook' => 'songbook',
        'add-songbook' => 'songbook',
        'scripture' => 'scripture',
        'scripture-import' => 'scripture',
        'scripture-chapter' => 'scripture',
        'no-access' => null,
    ];

    return $map[$view] ?? null;
}
