<?php
/**
 * Создаёт таблицу map_dictionary, если её ещё нет (не нужно вручную импортировать SQL).
 *
 * @param Database $db
 */
function ensure_map_dictionary_table($db) {
    $pdo = $db->getConnection();
    $sql = 'CREATE TABLE IF NOT EXISTS map_dictionary (
        map_code VARCHAR(128) NOT NULL PRIMARY KEY,
        display_name_ru VARCHAR(255) NOT NULL,
        display_name_en VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_moderated TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec($sql);

    $row = $db->fetchOne(
        'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'map_dictionary\'
           AND COLUMN_NAME = \'is_moderated\''
    );
    if ($row && (int) $row['c'] === 0) {
        $pdo->exec('ALTER TABLE map_dictionary ADD COLUMN is_moderated TINYINT(1) NOT NULL DEFAULT 0');
    }

    try {
        $pdo->exec('ALTER TABLE map_dictionary ADD COLUMN display_name_en VARCHAR(255) NOT NULL DEFAULT \'\' AFTER display_name_ru');
    } catch (Throwable $e) {
        // column already exists
    }
}
