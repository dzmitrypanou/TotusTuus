<?php
require_once __DIR__ . '/includes/bootstrap.php';
$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';
admin_require_web();

$stats = null;
$db_error = null;
try {
    $stats = $db->fetchOne("
        SELECT 
            COUNT(*) as total,
            SUM(is_premium) as premium,
            SUM(is_collectible) as collectible,
            SUM(CASE WHEN is_premium = 0 AND is_collectible = 0 THEN 1 ELSE 0 END) as regular,
            SUM(is_moderated) as moderated,
            SUM(CASE WHEN is_moderated = 0 THEN 1 ELSE 0 END) as unmoderated,
            SUM(CASE WHEN tank_type = 'heavy' THEN 1 ELSE 0 END) as heavy,
            SUM(CASE WHEN tank_type = 'medium' THEN 1 ELSE 0 END) as medium,
            SUM(CASE WHEN tank_type = 'light' THEN 1 ELSE 0 END) as light,
            SUM(CASE WHEN tank_type = 'td' THEN 1 ELSE 0 END) as td,
            SUM(CASE WHEN tank_type = 'spg' THEN 1 ELSE 0 END) as spg
        FROM tank_dictionary
    ");
} catch (Exception $e) {
    $stats = null;
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактор танков | Анализ АБС реплеев</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <style>
        .btn-primary,
        .btn-danger {
            min-width: 120px;
            text-align: center;
            justify-content: center;
        }
        .header-with-button .btn-primary {
            min-width: 140px;
        }
        .action-btn {
            min-width: 32px;
            text-align: center;
        }
    </style>
    <?php require __DIR__ . '/includes/csrf_head.php'; ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-tools" style="color: #ffd966;"></i>
                Редактор названий танков
            </h1>
            <?php $navCurrent = 'index'; include __DIR__ . '/includes/header_nav.php'; ?>
        </div>
        <?php if ($stats === null): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> 
                Ошибка подключения к базе данных: <?php echo isset($db_error) ? htmlspecialchars($db_error) : 'Неизвестная ошибка'; ?>
            </div>
        <?php else: ?>
            <?php
            $nationLabelRows = $db->fetchAll('SELECT nation_code, display_name_ru FROM nation_labels ORDER BY display_name_ru');
            $tankTypeLabelRows = $db->fetchAll('SELECT type_code, display_name_ru FROM tank_type_labels ORDER BY type_code');
            ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label"><i class="fas fa-tanks"></i> Всего танков</div>
                    <div class="value"><?php echo $stats['total']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-check-circle" style="color: #4caf50;"></i> Проверено</div>
                    <div class="value"><?php echo $stats['moderated']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-clock" style="color: #ffd966;"></i> На проверке</div>
                    <div class="value"><?php echo $stats['unmoderated']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-crown"></i> Премиум</div>
                    <div class="value"><?php echo $stats['premium']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-star"></i> Коллекционные</div>
                    <div class="value"><?php echo $stats['collectible']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-tank"></i> Обычные</div>
                    <div class="value"><?php echo $stats['regular']; ?></div>
                </div>
            </div>
            <div class="header-with-button">
                <h2><i class="fas fa-list"></i> Список танков</h2>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="/admin/dictionaries" class="btn">
                        <i class="fas fa-book"></i> Справочники наций и типов
                    </a>
                    <button type="button" class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Добавить танк
                    </button>
                </div>
            </div>
            <div class="search-section">
                <div class="filters-group">
                    <input type="text" name="search" placeholder="Поиск по коду или названию..." value="" class="search-input">
                    <div class="custom-select">
                        <select name="type">
                            <option value="all">Все типы</option>
                            <?php foreach ($tankTypeLabelRows as $tr): ?>
                                <?php if ($tr['type_code'] === 'unknown') {
                                    continue;
                                } ?>
                                <option value="<?php echo htmlspecialchars($tr['type_code']); ?>"><?php echo htmlspecialchars($tr['display_name_ru']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="custom-select">
                        <select name="nation">
                            <option value="all">Все нации</option>
                            <?php foreach ($nationLabelRows as $nr): ?>
                                <?php if ($nr['nation_code'] === 'unknown') {
                                    continue;
                                } ?>
                                <option value="<?php echo htmlspecialchars($nr['nation_code']); ?>"><?php echo htmlspecialchars($nr['display_name_ru']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="custom-select">
                        <select name="moderation">
                            <option value="all">Все статусы</option>
                            <option value="unmoderated">На проверке</option>
                            <option value="moderated">Проверенные</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-danger" id="resetFiltersBtn" title="Сбросить">
                        <i class="fas fa-times"></i> Сбросить
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table id="tanks-table">
                    <thead>
                        <tr>
                            <th>Код</th>
                            <th>Название (RU / EN)</th>
                            <th>Нация</th>
                            <th>Тип</th>
                            <th>Ур.</th>
                            <th>Статус</th>
                            <th>Модерация</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="8" style="text-align: center;">Загрузка...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination-info" id="paginationInfo"></div>
            <div class="modal" id="editModal">
                <div class="modal-content">
                    <h2><i class="fas fa-edit"></i> Редактировать танк</h2>
                    <form id="editForm">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="form-group">
                            <label>Код танка</label>
                            <div class="input-with-copy">
                                <input type="text" id="edit_code" disabled>
                                <button type="button" class="copy-btn" data-copy="edit_code" title="Копировать">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Название (рус.)</label>
                            <div class="input-with-copy">
                                <input type="text" name="display_name_ru" id="edit_name" required>
                                <button type="button" class="copy-btn" data-copy="edit_name" title="Копировать">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>English name</label>
                            <div class="input-with-copy">
                                <input type="text" name="display_name_en" id="edit_name_en" placeholder="e.g. IS-3">
                                <button type="button" class="copy-btn" data-copy="edit_name_en" title="Copy">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Нация</label>
                            <div class="custom-select">
                                <select name="nation" id="edit_nation" required>
                                    <?php foreach ($nationLabelRows as $nr): ?>
                                        <option value="<?php echo htmlspecialchars($nr['nation_code']); ?>"><?php echo htmlspecialchars($nr['display_name_ru']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Тип</label>
                            <div class="custom-select">
                                <select name="tank_type" id="edit_type" required>
                                    <?php foreach ($tankTypeLabelRows as $tr): ?>
                                        <option value="<?php echo htmlspecialchars($tr['type_code']); ?>"><?php echo htmlspecialchars($tr['display_name_ru']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Уровень</label>
                            <div class="custom-select">
                                <select name="tier" id="edit_tier" required>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                    <option value="6">6</option>
                                    <option value="7">7</option>
                                    <option value="8">8</option>
                                    <option value="9">9</option>
                                    <option value="10">10</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Тип техники</label>
                            <div class="tech-switcher">
                                <label class="switcher-option regular" id="edit_regular_label">
                                    <input type="radio" name="tech_type" value="regular">
                                    Обычный
                                </label>
                                <label class="switcher-option premium" id="edit_premium_label">
                                    <input type="radio" name="tech_type" value="premium">
                                    Премиум
                                </label>
                                <label class="switcher-option collectible" id="edit_collectible_label">
                                    <input type="radio" name="tech_type" value="collectible">
                                    Коллекционный
                                </label>
                            </div>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_moderated" id="edit_moderated" value="1">
                            <label for="edit_moderated">Отметить как проверенное</label>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-save"></i> Сохранить
                            </button>
                            <button type="button" class="btn" onclick="closeEditModal()">
                                <i class="fas fa-times"></i> Отмена
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="modal" id="addModal">
                <div class="modal-content">
                    <h2><i class="fas fa-plus"></i> Добавить новый танк</h2>
                    <form id="addForm">
                        <div class="form-group">
                            <label>Код танка *</label>
                            <div class="input-with-copy">
                                <input type="text" name="vehicle_code" id="add_code" required placeholder="например: ussr:R19_IS-3 или ussr-R19_IS-3">
                                <button type="button" class="copy-btn" data-copy="add_code" title="Копировать">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                            <small>Формат: нация:код</small>
                        </div>
                        <div class="form-group">
                            <label>Название (рус.) *</label>
                            <div class="input-with-copy">
                                <input type="text" name="display_name_ru" id="add_name" required placeholder="например: ИС-3">
                                <button type="button" class="copy-btn" data-copy="add_name" title="Копировать">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>English name</label>
                            <div class="input-with-copy">
                                <input type="text" name="display_name_en" id="add_name_en" placeholder="e.g. IS-3">
                                <button type="button" class="copy-btn" data-copy="add_name_en" title="Copy">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Нация</label>
                            <div class="custom-select">
                                <select name="nation" id="add_nation" required>
                                    <?php foreach ($nationLabelRows as $nr): ?>
                                        <?php if ($nr['nation_code'] === 'unknown') {
                                            continue;
                                        } ?>
                                        <option value="<?php echo htmlspecialchars($nr['nation_code']); ?>"<?php echo $nr['nation_code'] === 'ussr' ? ' selected' : ''; ?>><?php echo htmlspecialchars($nr['display_name_ru']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <small>По умолчанию подставляется из префикса кода (нация:…); можно изменить вручную.</small>
                        </div>
                        <div class="form-group">
                            <label>Тип</label>
                            <div class="custom-select">
                                <select name="tank_type" id="add_tank_type" required>
                                    <?php foreach ($tankTypeLabelRows as $tr): ?>
                                        <?php if ($tr['type_code'] === 'unknown') {
                                            continue;
                                        } ?>
                                        <option value="<?php echo htmlspecialchars($tr['type_code']); ?>"<?php echo $tr['type_code'] === 'medium' ? ' selected' : ''; ?>><?php echo htmlspecialchars($tr['display_name_ru']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Уровень</label>
                            <div class="custom-select">
                                <select name="tier" required>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                    <option value="6">6</option>
                                    <option value="7">7</option>
                                    <option value="8" selected>8</option>
                                    <option value="9">9</option>
                                    <option value="10">10</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Тип техники</label>
                            <div class="tech-switcher">
                                <label class="switcher-option regular active" id="add_regular_label">
                                    <input type="radio" name="tech_type" value="regular" checked>
                                    Обычный
                                </label>
                                <label class="switcher-option premium" id="add_premium_label">
                                    <input type="radio" name="tech_type" value="premium">
                                    Премиум
                                </label>
                                <label class="switcher-option collectible" id="add_collectible_label">
                                    <input type="radio" name="tech_type" value="collectible">
                                    Коллекционный
                                </label>
                            </div>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_moderated" id="add_moderated" value="1">
                            <label for="add_moderated">Отметить как проверенное</label>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-plus"></i> Добавить
                            </button>
                            <button type="button" class="btn" onclick="closeAddModal()">
                                <i class="fas fa-times"></i> Отмена
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <script src="/admin/js/admin.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
