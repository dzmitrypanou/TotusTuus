<?php
/**
 * Таблицы русских подписей для кодов наций и типов техники (в tank_dictionary хранятся коды).
 *
 * @param Database $db
 */
function ensure_dictionary_labels_tables($db) {
    $pdo = $db->getConnection();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nation_labels (
            nation_code VARCHAR(40) NOT NULL PRIMARY KEY,
            display_name_ru VARCHAR(128) NOT NULL,
            display_name_en VARCHAR(128) NOT NULL DEFAULT \'\'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tank_type_labels (
            type_code VARCHAR(40) NOT NULL PRIMARY KEY,
            display_name_ru VARCHAR(128) NOT NULL,
            display_name_en VARCHAR(128) NOT NULL DEFAULT \'\'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Для старых установок — добавляем колонки.
    try {
        $pdo->exec('ALTER TABLE nation_labels ADD COLUMN display_name_en VARCHAR(128) NOT NULL DEFAULT \'\' AFTER display_name_ru');
    } catch (Throwable $e) {}
    try {
        $pdo->exec('ALTER TABLE tank_type_labels ADD COLUMN display_name_en VARCHAR(128) NOT NULL DEFAULT \'\' AFTER display_name_ru');
    } catch (Throwable $e) {}

    $nations = [
        ['ussr', 'СССР', 'USSR'],
        ['germany', 'Германия', 'Germany'],
        ['usa', 'США', 'USA'],
        ['france', 'Франция', 'France'],
        ['uk', 'Великобритания', 'United Kingdom'],
        ['china', 'Китай', 'China'],
        ['japan', 'Япония', 'Japan'],
        ['czech', 'Чехословакия', 'Czechoslovakia'],
        ['poland', 'Польша', 'Poland'],
        ['sweden', 'Швеция', 'Sweden'],
        ['italy', 'Италия', 'Italy'],
        ['european', 'Европа', 'Europe'],
        ['intunion', 'Международный союз', 'International Union'],
        ['unknown', 'Неизвестно', 'Unknown'],
    ];
    foreach ($nations as $row) {
        $db->query(
            'INSERT IGNORE INTO nation_labels (nation_code, display_name_ru, display_name_en) VALUES (?, ?, ?)',
            $row
        );
    }

    $types = [
        ['heavy', 'Тяжёлый', 'Heavy'],
        ['medium', 'Средний', 'Medium'],
        ['light', 'Лёгкий', 'Light'],
        ['td', 'ПТ-САУ', 'Tank Destroyer'],
        ['spg', 'САУ', 'SPG'],
        ['unknown', 'Неизвестно', 'Unknown'],
    ];
    foreach ($types as $row) {
        $db->query(
            'INSERT IGNORE INTO tank_type_labels (type_code, display_name_ru, display_name_en) VALUES (?, ?, ?)',
            $row
        );
    }
}
