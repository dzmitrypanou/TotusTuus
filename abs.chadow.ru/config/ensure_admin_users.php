<?php
/**
 * Таблица учётных записей админки. Пароли только в виде password_hash (bcrypt).
 * При пустой таблице создаётся пользователь admin / admin — смените пароль после первого входа.
 *
 * @param Database $db
 */
function ensure_admin_users_table($db) {
    $pdo = $db->getConnection();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM(\'admin\', \'user\') NOT NULL DEFAULT \'user\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY username_unique (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $row = $db->fetchOne('SELECT COUNT(*) AS c FROM admin_users');
    if ($row && (int) $row['c'] === 0) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $db->query(
            'INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)',
            ['admin', $hash, 'admin']
        );
    }
}
