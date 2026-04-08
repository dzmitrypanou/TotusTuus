<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../config/ensure_map_dictionary.php';
$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';

admin_require_web();

$u = admin_user();
$username = $u['username'] ?? '';
$roleLabel = ($u['role'] ?? '') === 'admin' ? 'Администратор' : 'Пользователь';
$isAdmin = function_exists('admin_is_admin') && admin_is_admin();

if ($username === '') {
    $avatarLetter = '?';
} elseif (function_exists('mb_substr')) {
    $avatarLetter = mb_strtoupper(mb_substr($username, 0, 1, 'UTF-8'), 'UTF-8');
} else {
    $avatarLetter = strtoupper(substr($username, 0, 1));
}

$tankStats = null;
$mapStats = null;
$db_error = null;

try {
    $tankStats = $db->fetchOne("
        SELECT 
            COUNT(*) as total,
            SUM(is_moderated) as moderated,
            SUM(CASE WHEN is_moderated = 0 THEN 1 ELSE 0 END) as unmoderated
        FROM tank_dictionary
    ");

    ensure_map_dictionary_table($db);
    $mapRow = $db->fetchOne('SELECT COUNT(*) AS c FROM map_dictionary');
    $mapsCount = (int) ($mapRow['c'] ?? 0);
    $modRow = $db->fetchOne('SELECT SUM(is_moderated) AS m FROM map_dictionary');
    $mapsModerated = (int) ($modRow['m'] ?? 0);
    $mapStats = [
        'total' => $mapsCount,
        'moderated' => $mapsModerated,
        'unmoderated' => max(0, $mapsCount - $mapsModerated),
    ];
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

function n($v) {
    return (int) ($v ?? 0);
}

$statsOk = $tankStats !== null && $mapStats !== null;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд | Админ-панель</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <link rel="stylesheet" href="/admin/css/dashboard.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <?php require __DIR__ . '/includes/csrf_head.php'; ?>
</head>
<body class="dashboard-page">
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-tachometer-alt" style="color: #ffd966;"></i>
                Дашборд
            </h1>
            <?php $navCurrent = 'dashboard'; include __DIR__ . '/includes/header_nav.php'; ?>
        </div>

        <div class="profile-layout">
            <div class="profile-layout__main">
                <div class="profile-stack">
                    <section class="profile-card" aria-labelledby="profile-card-identity">
                        <div class="profile-card__head">
                            <h3 id="profile-card-identity" class="profile-card__title">
                                <i class="fas fa-id-badge" aria-hidden="true"></i> Профиль
                            </h3>
                        </div>
                        <div class="profile-card__body">
                            <div class="profile-identity">
                                <div class="profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($avatarLetter, ENT_QUOTES, 'UTF-8'); ?></div>
                                <dl class="profile-meta">
                                    <dt>Логин</dt>
                                    <dd><?php echo htmlspecialchars($username); ?></dd>
                                    <dt>Роль</dt>
                                    <dd><?php echo htmlspecialchars($roleLabel); ?></dd>
                                </dl>
                            </div>
                        </div>
                    </section>

                    <section class="profile-card" aria-labelledby="profile-card-password">
                        <div class="profile-card__head">
                            <h3 id="profile-card-password" class="profile-card__title">
                                <i class="fas fa-key" aria-hidden="true"></i> Смена пароля
                            </h3>
                        </div>
                        <div class="profile-card__body">
                            <form id="dashboardPasswordForm" class="profile-password-form" autocomplete="off">
                                <div class="form-group">
                                    <label for="current_password">Текущий пароль</label>
                                    <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
                                </div>
                                <div class="form-group">
                                    <label for="new_password">Новый пароль</label>
                                    <input type="password" name="new_password" id="new_password" required minlength="8" autocomplete="new-password" placeholder="Не менее 8 символов">
                                </div>
                                <div class="form-group">
                                    <label for="new_password_confirm">Повторите новый пароль</label>
                                    <input type="password" name="new_password_confirm" id="new_password_confirm" required minlength="8" autocomplete="new-password" placeholder="Введите ещё раз">
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Сохранить новый пароль
                                </button>
                            </form>
                        </div>
                    </section>
                </div>
            </div>

            <aside class="profile-layout__aside" aria-label="Разделы и сводка">
                <div class="profile-aside-stack">
                    <section class="profile-card" aria-labelledby="profile-card-modules">
                        <div class="profile-card__head">
                            <h3 id="profile-card-modules" class="profile-card__title">
                                <i class="fas fa-bars" aria-hidden="true"></i> Разделы админки
                            </h3>
                        </div>
                        <div class="profile-card__body">
                            <nav class="profile-module-links" aria-label="Разделы админ-панели">
                                <a href="/admin/tanks" class="profile-module-link">
                                    <i class="fas fa-tools" aria-hidden="true"></i> Редактор танков
                                </a>
                                <a href="/admin/dictionaries" class="profile-module-link">
                                    <i class="fas fa-book" aria-hidden="true"></i> Нации и типы техники
                                </a>
                                <a href="/admin/pages" class="profile-module-link">
                                    <i class="fas fa-file-alt" aria-hidden="true"></i> Страницы сайта
                                </a>
                                <a href="/admin/site-menu" class="profile-module-link">
                                    <i class="fas fa-bars" aria-hidden="true"></i> Меню сайта
                                </a>
                                <a href="/admin/maps" class="profile-module-link">
                                    <i class="fas fa-map" aria-hidden="true"></i> Карты
                                </a>
                                <a href="/admin/wgsrt" class="profile-module-link">
                                    <i class="fas fa-chart-line" aria-hidden="true"></i> WGSRT
                                </a>
                                <?php if ($isAdmin): ?>
                                    <a href="/admin/users" class="profile-module-link">
                                        <i class="fas fa-users-cog" aria-hidden="true"></i> Пользователи
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    </section>

                <?php if ($db_error !== null): ?>
                    <div class="alert alert-danger profile-aside-alert">
                        <i class="fas fa-exclamation-triangle"></i>
                        Сводка недоступна: <?php echo htmlspecialchars($db_error); ?>
                    </div>
                <?php elseif ($statsOk): ?>
                    <section class="profile-panel profile-panel--mini" aria-labelledby="profile-mini-stats-heading">
                        <h2 id="profile-mini-stats-heading" class="profile-section-title">
                            <i class="fas fa-chart-pie" aria-hidden="true"></i>
                            Сводка
                        </h2>
                        <div class="profile-panel__body profile-panel__body--mini">
                            <div class="profile-subsection-block">
                                <h3 class="profile-subsection-title profile-subsection-title--mini">
                                    <i class="fas fa-tools" aria-hidden="true"></i> Танки
                                </h3>
                                <div class="profile-stat-grid profile-stat-grid--mini-main">
                                    <div class="profile-stat-card profile-stat-card--mini">
                                        <div class="label">Всего</div>
                                        <div class="value"><?php echo n($tankStats['total']); ?></div>
                                    </div>
                                    <div class="profile-stat-card profile-stat-card--mini">
                                        <div class="label">Проверено</div>
                                        <div class="value"><?php echo n($tankStats['moderated']); ?></div>
                                    </div>
                                    <div class="profile-stat-card profile-stat-card--mini">
                                        <div class="label">На проверке</div>
                                        <div class="value"><?php echo n($tankStats['unmoderated']); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="profile-subsection-block profile-subsection-block--last">
                                <h3 class="profile-subsection-title profile-subsection-title--mini"><i class="fas fa-map"></i> Карты</h3>
                                <div class="profile-stat-grid profile-stat-grid--mini-maps">
                                    <div class="profile-stat-card profile-stat-card--mini">
                                        <div class="label">Всего</div>
                                        <div class="value"><?php echo (int) $mapStats['total']; ?></div>
                                    </div>
                                    <div class="profile-stat-card profile-stat-card--mini">
                                        <div class="label">Проверено</div>
                                        <div class="value"><?php echo (int) $mapStats['moderated']; ?></div>
                                    </div>
                                    <div class="profile-stat-card profile-stat-card--mini">
                                        <div class="label">На проверке</div>
                                        <div class="value"><?php echo (int) $mapStats['unmoderated']; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
    <script src="/admin/js/dashboard.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
</body>
</html>
