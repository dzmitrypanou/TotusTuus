<?php
/**
 * Ограничение частоты попыток входа в админку по IP (защита от перебора паролей).
 *
 * @param Database $db
 */
function ensure_admin_login_throttle_table($db) {
    $pdo = $db->getConnection();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_login_throttle (
            ip_key CHAR(64) NOT NULL PRIMARY KEY,
            fail_count INT UNSIGNED NOT NULL DEFAULT 0,
            window_start INT UNSIGNED NOT NULL,
            locked_until INT UNSIGNED NOT NULL DEFAULT 0,
            INDEX idx_locked (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
