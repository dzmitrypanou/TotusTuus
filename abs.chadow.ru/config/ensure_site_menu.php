<?php
/**
 * Пункты текстового меню в шапке публичного сайта.
 *
 * @param Database $db
 */
function ensure_site_menu_table($db) {
    $pdo = $db->getConnection();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cms_site_menu (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(255) NOT NULL,
            label_en VARCHAR(255) NOT NULL DEFAULT \'\' ,
            href VARCHAR(512) NOT NULL,
            placement VARCHAR(16) NOT NULL DEFAULT \'header\',
            sort_order INT NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY sort_order_idx (sort_order),
            KEY placement_idx (placement)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    ensure_site_menu_placement_column($pdo);
    ensure_site_menu_label_en_column($pdo);
}

/**
 * Миграция: колонка placement (header | footer) для старых установок.
 */
function ensure_site_menu_placement_column($pdo) {
    try {
        $pdo->exec(
            'ALTER TABLE cms_site_menu ADD COLUMN placement VARCHAR(16) NOT NULL DEFAULT \'header\' AFTER href'
        );
    } catch (Throwable $e) {
        // колонка уже есть
    }
    try {
        $pdo->exec('ALTER TABLE cms_site_menu ADD KEY placement_idx (placement)');
    } catch (Throwable $e) {
        // индекс уже есть
    }
}

/**
 * Миграция: колонка label_en для англ. текста меню.
 */
function ensure_site_menu_label_en_column($pdo): void
{
    try {
        $pdo->exec(
            'ALTER TABLE cms_site_menu ADD COLUMN label_en VARCHAR(255) NOT NULL DEFAULT \'\' AFTER label'
        );
    } catch (Throwable $e) {
        // колонка уже есть
    }
}

/**
 * Нормализация ссылки для вывода в шапке (относительные пути с ведущим /).
 */
function site_menu_normalize_href($href) {
    $href = trim((string) $href);
    if ($href === '' || strcasecmp($href, '/index.php') === 0 || strcasecmp($href, 'index.php') === 0) {
        return '/';
    }
    $low = strtolower($href);
    if (strpos($low, 'javascript:') === 0 || strpos($low, 'data:') === 0) {
        return '/';
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    if ($href[0] === '#') {
        return $href;
    }
    if ($href[0] !== '/') {
        return '/' . $href;
    }
    return $href;
}

/**
 * Проверка href при сохранении из админки.
 */
function site_menu_sanitize_href_input($href) {
    $href = trim((string) $href);
    if ($href === '' || strcasecmp($href, '/index.php') === 0 || strcasecmp($href, 'index.php') === 0) {
        return '/';
    }
    $low = strtolower($href);
    if (strpos($low, 'javascript:') === 0 || strpos($low, 'data:') === 0 || strpos($low, 'vbscript:') === 0) {
        return '/';
    }
    if (strlen($href) > 512) {
        $href = substr($href, 0, 512);
    }
    return $href;
}
