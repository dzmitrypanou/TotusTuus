-- Словарь отображаемых названий карт (технический код = как в реплее после cleanMapName, напр. battle_for_moscow)
-- Таблица также создаётся автоматически при первом запросе к API (см. config/ensure_map_dictionary.php).
CREATE TABLE IF NOT EXISTS map_dictionary (
    map_code VARCHAR(128) NOT NULL PRIMARY KEY,
    display_name_ru VARCHAR(255) NOT NULL,
    display_name_en VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_moderated TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
