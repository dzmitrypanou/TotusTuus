-- Опционально: создание справочников вручную (обычно создаётся автоматически при открытии админки).
CREATE TABLE IF NOT EXISTS nation_labels (
    nation_code VARCHAR(40) NOT NULL PRIMARY KEY,
    display_name_ru VARCHAR(128) NOT NULL,
    display_name_en VARCHAR(128) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tank_type_labels (
    type_code VARCHAR(40) NOT NULL PRIMARY KEY,
    display_name_ru VARCHAR(128) NOT NULL,
    display_name_en VARCHAR(128) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
