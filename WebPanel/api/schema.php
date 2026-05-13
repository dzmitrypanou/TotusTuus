<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/scripture_lib.php';

function ensureAuthTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS admin_auth (
            id TINYINT PRIMARY KEY DEFAULT 1,
            password_hash VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensurePrayerCategoriesTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS prayer_categories (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            parent_id BIGINT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_prayer_categories_parent
                FOREIGN KEY (parent_id) REFERENCES prayer_categories(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensurePrayersTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS prayers (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            text TEXT NOT NULL,
            category VARCHAR(100) NULL,
            category_id BIGINT NULL,
            language VARCHAR(20) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':schema' => DB_NAME,
        ':table_name' => 'prayers',
        ':column_name' => 'category_id',
    ]);
    $exists = (int)($stmt->fetch()['cnt'] ?? 0) > 0;
    if (!$exists) {
        db()->exec('ALTER TABLE prayers ADD COLUMN category_id BIGINT NULL AFTER category');
    }

    $stmt->execute([
        ':schema' => DB_NAME,
        ':table_name' => 'prayers',
        ':column_name' => 'sort_order',
    ]);
    $sortOrderExists = (int)($stmt->fetch()['cnt'] ?? 0) > 0;
    if (!$sortOrderExists) {
        db()->exec('ALTER TABLE prayers ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active');
        // Першы раз: парадак як раней па id (ручны парадак потым у адмінцы).
        db()->exec('UPDATE prayers SET sort_order = id');
    }
}

function ensurePrayerCategoryLinksTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS prayer_category_links (
            prayer_id BIGINT NOT NULL,
            category_id BIGINT NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (prayer_id, category_id),
            KEY idx_prayer_category_links_category_id (category_id),
            KEY idx_prayer_category_links_prayer_primary (prayer_id, is_primary),
            CONSTRAINT fk_prayer_category_links_prayer
                FOREIGN KEY (prayer_id) REFERENCES prayers(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_prayer_category_links_category
                FOREIGN KEY (category_id) REFERENCES prayer_categories(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    db()->exec(
        'INSERT IGNORE INTO prayer_category_links (prayer_id, category_id, is_primary)
         SELECT id, category_id, 1
         FROM prayers
         WHERE category_id IS NOT NULL'
    );
}

function findOrCreateCategory(string $name, ?int $parentId): int
{
    $stmt = db()->prepare(
        'SELECT id
         FROM prayer_categories
         WHERE name = :name
           AND ((:parent_id IS NULL AND parent_id IS NULL) OR parent_id = :parent_id)
         LIMIT 1'
    );
    $stmt->execute([
        ':name' => $name,
        ':parent_id' => $parentId,
    ]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return (int)$row['id'];
    }

    $insert = db()->prepare(
        'INSERT INTO prayer_categories (name, parent_id, is_active)
         VALUES (:name, :parent_id, 1)'
    );
    $insert->execute([
        ':name' => $name,
        ':parent_id' => $parentId,
    ]);
    return (int)db()->lastInsertId();
}

function ensureSongbookEntriesTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS songbook_entries (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL DEFAULT \'\',
            chapter_major INT NOT NULL,
            subchapter INT NULL,
            content_type VARCHAR(16) NOT NULL,
            text_body MEDIUMTEXT NOT NULL,
            media_path VARCHAR(512) NULL,
            media_revision VARCHAR(64) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    ensureTableColumnExists(
        'songbook_entries',
        'category',
        'ALTER TABLE songbook_entries ADD COLUMN category VARCHAR(255) NOT NULL DEFAULT \'\' AFTER title'
    );
}

function ensureKantaralEntriesTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS kantaral_entries (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL DEFAULT \'\',
            chapter_major INT NOT NULL,
            subchapter INT NULL,
            content_type VARCHAR(16) NOT NULL,
            text_body MEDIUMTEXT NOT NULL,
            media_path VARCHAR(512) NULL,
            media_revision VARCHAR(64) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    ensureTableColumnExists(
        'kantaral_entries',
        'category',
        'ALTER TABLE kantaral_entries ADD COLUMN category VARCHAR(255) NOT NULL DEFAULT \'\' AFTER title'
    );
}

function ensureLiturgyCalendarEntriesTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS liturgy_calendar_entries (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            liturgy_date DATE NOT NULL UNIQUE,
            title_override VARCHAR(255) NULL,
            color_override VARCHAR(20) NULL,
            readings_full MEDIUMTEXT NULL,
            lectionary_key VARCHAR(191) NULL,
            lectionary_source VARCHAR(64) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    ensureTableColumnExists('liturgy_calendar_entries', 'readings_full', 'ALTER TABLE liturgy_calendar_entries ADD COLUMN readings_full MEDIUMTEXT NULL AFTER color_override');
    ensureTableColumnExists('liturgy_calendar_entries', 'lectionary_key', 'ALTER TABLE liturgy_calendar_entries ADD COLUMN lectionary_key VARCHAR(191) NULL AFTER readings_full');
    ensureTableColumnExists('liturgy_calendar_entries', 'lectionary_source', 'ALTER TABLE liturgy_calendar_entries ADD COLUMN lectionary_source VARCHAR(64) NULL AFTER lectionary_key');

    ensureTableColumnRemoved('liturgy_calendar_entries', 'readings');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'psalm');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'gospel_acclamation');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'gospel');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'lectionary_item_id');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'source_title');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'source_color');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'source_readings');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'source_readings_link');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'source_synced_at');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'first_reading_ref');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'first_reading_source');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'first_reading_text');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'responsorial_psalm');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'second_reading_ref');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'second_reading_source');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'second_reading_text');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'gospel_acclamation_text');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'gospel_ref');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'gospel_source');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'gospel_text');
    ensureTableColumnRemoved('liturgy_calendar_entries', 'notes');

    db()->exec('DROP TABLE IF EXISTS liturgy_lectionary_items');
}

function ensureLiturgyObservancesTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS liturgy_observances (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            rule_type VARCHAR(32) NOT NULL DEFAULT \'fixed_md\',
            month TINYINT UNSIGNED NULL,
            day TINYINT UNSIGNED NULL,
            easter_offset INT NULL,
            advent_offset_days INT NULL,
            observance_kind VARCHAR(16) NOT NULL,
            regional_rank VARCHAR(32) NOT NULL DEFAULT \'\',
            title VARCHAR(512) NOT NULL,
            liturgical_color VARCHAR(20) NOT NULL DEFAULT \'white\',
            source_tag VARCHAR(32) NOT NULL DEFAULT \'fixed\',
            require_any_of VARCHAR(255) NOT NULL DEFAULT \'\',
            require_all_of VARCHAR(255) NOT NULL DEFAULT \'\',
            forbid_if_any_of VARCHAR(255) NOT NULL DEFAULT \'\',
            match_priority INT NOT NULL DEFAULT 0,
            uses_cycle_suffix TINYINT(1) NOT NULL DEFAULT 0,
            suppressed_by_ordinary_sunday TINYINT(1) NOT NULL DEFAULT 0,
            patch_append_to_mmdd VARCHAR(5) NULL,
            patch_suffix VARCHAR(512) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_liturgy_obs_kind (observance_kind),
            KEY idx_liturgy_obs_active_sort (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    ensureTableColumnExists(
        'liturgy_observances',
        'optional_title_prefix_auto',
        'ALTER TABLE liturgy_observances ADD COLUMN optional_title_prefix_auto TINYINT(1) NOT NULL DEFAULT 1 AFTER title'
    );
}

function ensureLiturgyLectionaryEntriesTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS liturgy_lectionary_entries (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            lookup_key VARCHAR(191) NOT NULL,
            text_html MEDIUMTEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_liturgy_lectionary_lookup_key (lookup_key),
            KEY idx_liturgy_lectionary_title (title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    ensureTableColumnExists(
        'liturgy_lectionary_entries',
        'lookup_key',
        'ALTER TABLE liturgy_lectionary_entries ADD COLUMN lookup_key VARCHAR(191) NOT NULL AFTER title'
    );
    ensureTableColumnExists(
        'liturgy_lectionary_entries',
        'is_active',
        'ALTER TABLE liturgy_lectionary_entries ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER text_html'
    );
    ensureTableColumnExists(
        'liturgy_lectionary_entries',
        'liturgical_color',
        'ALTER TABLE liturgy_lectionary_entries ADD COLUMN liturgical_color VARCHAR(20) NOT NULL DEFAULT \'\' AFTER is_active'
    );
}

function ensureSolemnitiesEntriesTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS solemnities_entries (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            date_label VARCHAR(128) NOT NULL,
            date_kind VARCHAR(16) NOT NULL DEFAULT \'fixed\',
            movable_key VARCHAR(64) NOT NULL DEFAULT \'\',
            title VARCHAR(512) NOT NULL,
            section_title VARCHAR(255) NOT NULL DEFAULT \'\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_solemnities_active_sort (is_active, sort_order, id),
            KEY idx_solemnities_section_sort (section_title, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    ensureTableColumnExists(
        'solemnities_entries',
        'date_kind',
        'ALTER TABLE solemnities_entries ADD COLUMN date_kind VARCHAR(16) NOT NULL DEFAULT \'fixed\' AFTER date_label'
    );
    ensureTableColumnExists(
        'solemnities_entries',
        'movable_key',
        'ALTER TABLE solemnities_entries ADD COLUMN movable_key VARCHAR(64) NOT NULL DEFAULT \'\' AFTER date_kind'
    );
}

function seedSolemnitiesEntriesIfEmpty(): void
{
    $stmt = db()->query('SELECT COUNT(*) AS c FROM solemnities_entries');
    $row = $stmt->fetch();
    if (is_array($row) && (int)($row['c'] ?? 0) > 0) {
        return;
    }

    $sections = [
        'Абавязковыя святы і ўрачыстасці' => [
            ['1 студзеня', 'Святой Багародзіцы Марыі', 'fixed', ''],
            ['6 студзеня', 'Аб’яўлення Пана (Тры Каралі)', 'fixed', ''],
            ['19 сакавіка', 'Святога Юзафа', 'fixed', ''],
            ['', 'Унебаўшэсця Пана', 'movable', 'ascension'],
            ['', 'Цела і Крыві Хрыста (Божага Цела)', 'movable', 'corpus_christi'],
            ['29 чэрвеня', 'Святых апосталаў Пятра і Паўла', 'fixed', ''],
            ['15 жніўня', 'Унебаўзяцце Найсвяцейшай Панны Марыі', 'fixed', ''],
            ['1 лістапада', 'Усіх Святых', 'fixed', ''],
            ['8 снежня', 'Беззаганнага Зачацця Найсвяцейшай Панны Марыі', 'fixed', ''],
            ['25 снежня', 'Нараджэнне Пана', 'fixed', ''],
        ],
        'Важнейшыя рухомыя святы і ўрачыстасці' => [
            ['', 'Папялец', 'movable', 'ash_wednesday'],
            ['', 'Вялікдзень', 'movable', 'easter'],
            ['', 'Унебаўшэсце', 'movable', 'ascension'],
            ['', 'Спасланне Духа Святога', 'movable', 'pentecost'],
            ['', 'Цела і Крыві Пана', 'movable', 'corpus_christi'],
            ['', 'Першая нядзеля Адвэнту', 'movable', 'first_advent_sunday'],
        ],
        'Урачыстасці і святы (па агульным парадку)' => [
            ['1 студзеня', 'Урачыстасць Святой Багародзіцы Марыі', 'fixed', ''],
            ['6 студзеня', 'Аб’яўленне Пана, Тры Каралі', 'fixed', ''],
            ['2 лютага', 'Ахвяраванне Пана', 'fixed', ''],
            ['', 'Папяльцовая серада – пачатак Вялікага посту', 'movable', 'ash_wednesday'],
            ['22 лютага', 'Свята Катэдры святога Пятра', 'fixed', ''],
            ['19 сакавіка', 'Урачыстасць святога Юзафа', 'fixed', ''],
            ['25 сакавіка', 'Звеставанне Пана', 'fixed', ''],
            ['', 'Пальмовая нядзеля', 'movable', 'palm_sunday'],
            ['', 'Уваскрасенне Пана', 'movable', 'easter'],
            ['', 'Унебаўшэсце Пана, урачыстасць', 'movable', 'ascension'],
            ['', 'Спасланне Духа Святога', 'movable', 'pentecost'],
            ['', 'Урачыстасць Найсвяцейшага Цела і Крыві Хрыста', 'movable', 'corpus_christi'],
            ['', 'Урачыстасць Найсвяцейшага Сэрца Пана Езуса', 'movable', 'sacred_heart'],
            ['24 чэрвеня', 'Нараджэнне святога Яна Хрысціцеля', 'fixed', ''],
            ['29 чэрвеня', 'Урачыстасць святых апосталаў Пятра і Паўла', 'fixed', ''],
            ['2 ліпеня', 'Урачыстасць Найсвяцейшай Панны Марыі Будслаўскай', 'fixed', ''],
            ['6 жніўня', 'Перамяненне Пана', 'fixed', ''],
            ['15 жніўня', 'Унебаўзяцце Найсвяцейшай Панны Марыі', 'fixed', ''],
            ['14 верасня', 'Свята Узвышэння Святога Крыжа', 'fixed', ''],
            ['1 лістапада', 'Урачыстасць Усіх Святых', 'fixed', ''],
            ['2 лістапада', 'Успамін усіх памерлых вернікаў', 'fixed', ''],
            ['', 'Урачыстасць Пана Нашага Езуса Хрыста, Валадара Сусвету', 'movable', 'christ_king'],
            ['8 снежня', 'Беззаганнае Зачацце Найсвяцейшай Панны Марыі', 'fixed', ''],
            ['25 снежня', 'Нараджэнне Пана', 'fixed', ''],
        ],
    ];

    $insert = db()->prepare(
        'INSERT INTO solemnities_entries (date_label, date_kind, movable_key, title, section_title, sort_order, is_active)
         VALUES (:date_label, :date_kind, :movable_key, :title, :section_title, :sort_order, 1)'
    );
    $order = 10;
    foreach ($sections as $sectionTitle => $items) {
        foreach ($items as $item) {
            $insert->execute([
                ':date_label' => $item[0],
                ':date_kind' => $item[2],
                ':movable_key' => $item[3],
                ':title' => $item[1],
                ':section_title' => $sectionTitle,
                ':sort_order' => $order,
            ]);
            $order += 10;
        }
    }
}

function ensureTableColumnExists(string $tableName, string $columnName, string $alterSql): void
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':schema' => DB_NAME,
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);
    $exists = (int)($stmt->fetch()['cnt'] ?? 0) > 0;
    if (!$exists) {
        db()->exec($alterSql);
    }
}

function ensureTableColumnRemoved(string $tableName, string $columnName): void
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':schema' => DB_NAME,
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);
    $exists = (int)($stmt->fetch()['cnt'] ?? 0) > 0;
    if ($exists) {
        db()->exec(sprintf('ALTER TABLE %s DROP COLUMN %s', $tableName, $columnName));
    }
}

function ensurePanelAnnouncementsSettingsTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS panel_announcements_settings (
            id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
            last_bulletin_date DATE NULL,
            main_title TEXT NULL,
            logo_url VARCHAR(2048) NOT NULL DEFAULT \'\',
            lead_sentence TEXT NOT NULL DEFAULT \'\',
            list_1 TEXT NOT NULL DEFAULT \'\',
            list_2 TEXT NOT NULL DEFAULT \'\',
            list_3 TEXT NOT NULL DEFAULT \'\',
            list_4 TEXT NOT NULL DEFAULT \'\',
            cleaning_pool TEXT NOT NULL DEFAULT \'\',
            thanks_pool TEXT NOT NULL DEFAULT \'\',
            signature_name VARCHAR(255) NOT NULL DEFAULT \'\',
            signature_role VARCHAR(255) NOT NULL DEFAULT \'\',
            footer_website VARCHAR(512) NOT NULL DEFAULT \'\',
            include_optionals TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec('INSERT IGNORE INTO panel_announcements_settings (id) VALUES (1)');
    ensurePanelAnnouncementsSettingsExtendedColumns();
}

/**
 * Палі тыдня (панядзелак–нядзеля) і сцягі ўключэння блокаў аб’яваў.
 */
function ensurePanelAnnouncementsSettingsExtendedColumns(): void
{
    $after = 'include_optionals';
    $weekText = [
        'week_mon_note',
        'week_mon_clean',
        'week_tue_note',
        'week_tue_clean',
        'week_wed_note',
        'week_wed_clean',
        'week_thu_note',
        'week_thu_clean',
        'week_fri_note',
        'week_fri_clean',
        'week_sat_note',
        'week_sat_clean',
        'week_sun_note',
        'week_sun_clean',
    ];
    foreach ($weekText as $col) {
        ensureTableColumnExists(
            'panel_announcements_settings',
            $col,
            'ALTER TABLE panel_announcements_settings ADD COLUMN ' . $col . ' TEXT NOT NULL DEFAULT \'\' AFTER ' . $after
        );
        $after = $col;
    }
    $enCols = [
        'en_lead',
        'en_list_1',
        'en_list_2',
        'en_list_3',
        'en_list_4',
        'en_cleaning_pool',
        'en_thanks_pool',
        'en_signature',
        'en_footer',
        'en_week_mon_note',
        'en_week_mon_clean',
        'en_week_tue_note',
        'en_week_tue_clean',
        'en_week_wed_note',
        'en_week_wed_clean',
        'en_week_thu_note',
        'en_week_thu_clean',
        'en_week_fri_note',
        'en_week_fri_clean',
        'en_week_sat_note',
        'en_week_sat_clean',
        'en_week_sun_note',
        'en_week_sun_clean',
    ];
    foreach ($enCols as $col) {
        ensureTableColumnExists(
            'panel_announcements_settings',
            $col,
            'ALTER TABLE panel_announcements_settings ADD COLUMN ' . $col . ' TINYINT(1) NOT NULL DEFAULT 1 AFTER ' . $after
        );
        $after = $col;
    }
    ensureTableColumnExists(
        'panel_announcements_settings',
        'announcements_dioceses',
        'ALTER TABLE panel_announcements_settings ADD COLUMN announcements_dioceses VARCHAR(160) NOT NULL DEFAULT \'\' AFTER ' . $after
    );
}

function ensurePanelUsersTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS panel_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            login VARCHAR(64) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM(\'admin\', \'user\') NOT NULL DEFAULT \'user\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_panel_users_login (login)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ensurePanelUserSectionGrantsTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS panel_user_section_grants (
            user_id BIGINT UNSIGNED NOT NULL,
            section_key VARCHAR(32) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, section_key),
            KEY idx_panel_grants_section (section_key),
            CONSTRAINT fk_panel_grants_user FOREIGN KEY (user_id) REFERENCES panel_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ensurePanelUsersSchema(): void
{
    ensurePanelUsersTable();
    ensurePanelUserSectionGrantsTable();
}

function ensurePanelOrdoMissaeTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS panel_ordo_missae (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
            html MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec('INSERT IGNORE INTO panel_ordo_missae (id, html) VALUES (1, \'\')');
    ensurePanelOrdoMissaeSectionColumns();
}

function ensurePanelOrdoMissaeSectionColumns(): void
{
    ensureTableColumnExists(
        'panel_ordo_missae',
        'html_intro',
        'ALTER TABLE panel_ordo_missae ADD COLUMN html_intro MEDIUMTEXT NOT NULL DEFAULT \'\' AFTER html'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'html_liturgy_word',
        'ALTER TABLE panel_ordo_missae ADD COLUMN html_liturgy_word MEDIUMTEXT NOT NULL DEFAULT \'\' AFTER html_intro'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'html_eucharist',
        'ALTER TABLE panel_ordo_missae ADD COLUMN html_eucharist MEDIUMTEXT NOT NULL DEFAULT \'\' AFTER html_liturgy_word'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'html_eucharist_prayer2',
        'ALTER TABLE panel_ordo_missae ADD COLUMN html_eucharist_prayer2 MEDIUMTEXT NOT NULL DEFAULT \'\' AFTER html_eucharist'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'html_communion',
        'ALTER TABLE panel_ordo_missae ADD COLUMN html_communion MEDIUMTEXT NOT NULL DEFAULT \'\' AFTER html_eucharist_prayer2'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'html_closing',
        'ALTER TABLE panel_ordo_missae ADD COLUMN html_closing MEDIUMTEXT NOT NULL DEFAULT \'\' AFTER html_communion'
    );

    try {
        $row = db()->query(
            'SELECT
                LENGTH(COALESCE(html, \'\')) AS lh,
                LENGTH(COALESCE(html_intro, \'\')) + LENGTH(COALESCE(html_liturgy_word, \'\')) + LENGTH(COALESCE(html_eucharist, \'\')) + LENGTH(COALESCE(html_eucharist_prayer2, \'\')) + LENGTH(COALESCE(html_communion, \'\')) + LENGTH(COALESCE(html_closing, \'\')) AS ls
             FROM panel_ordo_missae
             WHERE id = 1
             LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && (int)($row['lh'] ?? 0) > 0 && (int)($row['ls'] ?? 0) === 0) {
            db()->exec('UPDATE panel_ordo_missae SET html_intro = html WHERE id = 1');
        }
    } catch (Throwable $e) {
        // ignore
    }

    ensurePanelOrdoMissaeSectionTitleColumns();
}

function ensurePanelOrdoMissaeSectionTitleColumns(): void
{
    ensureTableColumnExists(
        'panel_ordo_missae',
        'title_intro',
        'ALTER TABLE panel_ordo_missae ADD COLUMN title_intro VARCHAR(255) NOT NULL DEFAULT \'\' AFTER html_closing'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'title_liturgy_word',
        'ALTER TABLE panel_ordo_missae ADD COLUMN title_liturgy_word VARCHAR(255) NOT NULL DEFAULT \'\' AFTER title_intro'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'title_eucharist',
        'ALTER TABLE panel_ordo_missae ADD COLUMN title_eucharist VARCHAR(255) NOT NULL DEFAULT \'\' AFTER title_liturgy_word'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'title_eucharist_prayer2',
        'ALTER TABLE panel_ordo_missae ADD COLUMN title_eucharist_prayer2 VARCHAR(255) NOT NULL DEFAULT \'\' AFTER title_eucharist'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'title_communion',
        'ALTER TABLE panel_ordo_missae ADD COLUMN title_communion VARCHAR(255) NOT NULL DEFAULT \'\' AFTER title_eucharist_prayer2'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'title_closing',
        'ALTER TABLE panel_ordo_missae ADD COLUMN title_closing VARCHAR(255) NOT NULL DEFAULT \'\' AFTER title_communion'
    );
    ensureTableColumnExists(
        'panel_ordo_missae',
        'ordo_layout_json',
        'ALTER TABLE panel_ordo_missae ADD COLUMN ordo_layout_json MEDIUMTEXT NOT NULL DEFAULT \'\' AFTER title_closing'
    );
}

function ensureSchemaAndSeed(): void
{
    ensurePanelUsersSchema();
    ensurePrayerCategoriesTable();
    ensurePrayersTable();
    ensurePrayerCategoryLinksTable();
    ensureSongbookEntriesTable();
    ensureKantaralEntriesTable();
    ensureLiturgyCalendarEntriesTable();
    ensureLiturgyObservancesTable();
    ensureLiturgyLectionaryEntriesTable();
    ensureSolemnitiesEntriesTable();
    seedSolemnitiesEntriesIfEmpty();
    ensurePanelAnnouncementsSettingsTable();
    ensurePanelOrdoMissaeTable();
    scriptureEnsureSchema();
    scriptureEnsureAllTranslationMeta();
    require_once __DIR__ . '/liturgy_observances_seed.php';
    liturgy_seed_observances_if_empty();
}
