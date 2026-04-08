<?php
/**
 * @param Database $db
 */
function ensure_cms_pages_table($db) {
    $pdo = $db->getConnection();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cms_pages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(128) NOT NULL,
            title VARCHAR(255) NOT NULL,
            title_en VARCHAR(255) NULL,
            body_html MEDIUMTEXT NOT NULL,
            body_html_en MEDIUMTEXT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY slug_unique (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    ensure_cms_pages_lang_columns($pdo);
}

function ensure_cms_pages_lang_columns($pdo): void
{
    // Если таблица уже была создана старой версией — добавляем недостающие колонки.
    try {
        $pdo->exec('ALTER TABLE cms_pages ADD COLUMN title_en VARCHAR(255) NULL AFTER title');
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $pdo->exec('ALTER TABLE cms_pages ADD COLUMN body_html_en MEDIUMTEXT NULL AFTER body_html');
    } catch (Throwable $e) {
        // ignore
    }
}
