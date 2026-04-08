<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../config/ensure_map_dictionary.php';
$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';

admin_require_web();

$mapsCount = 0;
$mapsModerated = 0;
$mapsUnmoderated = 0;
$db_error = null;

try {
    ensure_map_dictionary_table($db);
    $row = $db->fetchOne('SELECT COUNT(*) AS c FROM map_dictionary');
    $mapsCount = (int) ($row['c'] ?? 0);
    $modRow = $db->fetchOne('SELECT SUM(is_moderated) AS m FROM map_dictionary');
    $mapsModerated = (int) ($modRow['m'] ?? 0);
    $mapsUnmoderated = max(0, $mapsCount - $mapsModerated);
} catch (Exception $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактор карт | Анализ АБС реплеев</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <style>
        .maps-stats-grid {
            width: 100%;
            max-width: none;
            box-sizing: border-box;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        @media (max-width: 768px) {
            .maps-stats-grid {
                grid-template-columns: 1fr;
            }
        }
        .maps-page .action-btn {
            min-width: 32px;
            text-align: center;
        }
    </style>
    <?php require __DIR__ . '/includes/csrf_head.php'; ?>
</head>
<body class="maps-page">
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-map" style="color: #ffd966;"></i>
                Редактор названий карт
            </h1>
            <?php $navCurrent = 'maps'; include __DIR__ . '/includes/header_nav.php'; ?>
        </div>

        <?php if ($db_error !== null): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                Ошибка БД: <?php echo htmlspecialchars($db_error); ?>
                <p style="margin-top:10px;">Нужны права на создание таблиц в этой базе. Либо выполните вручную SQL из <code>config/map_dictionary.sql</code>.</p>
            </div>
        <?php else: ?>
            <div class="stats-grid maps-stats-grid">
                <div class="stat-card">
                    <div class="label"><i class="fas fa-map-marked-alt"></i> Всего карт</div>
                    <div class="value" id="mapsCountDisplay"><?php echo (int) $mapsCount; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-check-circle" style="color: #4caf50;"></i> Проверено</div>
                    <div class="value" id="mapsModeratedCount"><?php echo (int) $mapsModerated; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-clock" style="color: #ffd966;"></i> На проверке</div>
                    <div class="value" id="mapsUnmoderatedCount"><?php echo (int) $mapsUnmoderated; ?></div>
                </div>
            </div>
            <div class="search-section">
                <div class="filters-group">
                    <input type="text" id="mapsSearch" class="search-input" placeholder="Поиск по коду или названию...">
                    <div class="custom-select">
                        <select id="mapsModeration" title="Модерация">
                            <option value="all">Все статусы</option>
                            <option value="unmoderated">На проверке</option>
                            <option value="moderated">Проверенные</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-danger" id="mapsResetFilters" title="Сбросить">
                        <i class="fas fa-times"></i> Сбросить
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table id="maps-table">
                    <thead>
                        <tr>
                            <th>Технический код</th>
                            <th scope="col">Название</th>
                            <th>Модерация</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody id="mapsTableBody">
                        <tr><td colspan="4" style="text-align: center;">Загрузка...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="modal" id="editMapModal">
                <div class="modal-content">
                    <h2><i class="fas fa-edit"></i> Редактировать карту</h2>
                    <form id="editMapForm">
                        <div class="form-group">
                            <label>Технический код</label>
                            <input type="text" id="edit_map_code" disabled>
                        </div>
                        <input type="hidden" name="map_code" id="edit_map_code_hidden">
                        <div class="form-group">
                            <label>Название (как в интерфейсе)</label>
                            <input type="text" name="display_name_ru" id="edit_map_display" required>
                        </div>
                        <div class="form-group">
                            <label>English name</label>
                            <input type="text" name="display_name_en" id="edit_map_display_en">
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_moderated" id="edit_map_moderated" value="1">
                            <label for="edit_map_moderated">Отметить как проверенное</label>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-save"></i> Сохранить
                            </button>
                            <button type="button" class="btn" onclick="closeEditMapModal()">
                                <i class="fas fa-times"></i> Отмена
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
    <?php if ($db_error === null): ?>
        <script src="/admin/js/maps.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
    <?php endif; ?>
</body>
</html>
