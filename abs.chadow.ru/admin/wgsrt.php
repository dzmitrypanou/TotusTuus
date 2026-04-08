<?php
require_once __DIR__ . '/includes/bootstrap.php';
$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';

admin_require_web();

$coefficients = [];
$grades = [];
$db_error = null;

try {
    $coefficients = $db->fetchAll("
        SELECT * FROM wgsrt_coefficients 
        WHERE is_active = 1
        ORDER BY parameter_name
    ");

    $grades = $db->fetchAll("
        SELECT * FROM wgsrt_grades 
        ORDER BY sort_order
    ");
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

$paramNames = [
    'damage' => 'Урон',
    'kills' => 'Фраги',
    'assisted' => 'Ассист',
    'received' => 'Заблок. урон',
    'survival' => 'Выживаемость',
    'hitRatio' => 'Точность',
    'penRatio' => 'Пробиваемость',
    'spots' => 'Обнаружения',
    'winRate' => 'Победы'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WGSRT Редактор | Анализ АБС реплеев</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <link rel="stylesheet" href="/admin/css/wgsrt.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <?php require __DIR__ . '/includes/csrf_head.php'; ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-chart-line" style="color: #ffd966;"></i>
                WGSRT Редактор
            </h1>
            <?php $navCurrent = 'wgsrt'; include __DIR__ . '/includes/header_nav.php'; ?>
        </div>

        <?php if ($db_error !== null): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> 
                Ошибка подключения к базе данных: <?php echo htmlspecialchars($db_error); ?>
            </div>
        <?php else: ?>
        
        <div class="tabs">
            <button class="tab active" data-tab="coefficients">
                <i class="fas fa-calculator"></i> Коэффициенты WGSRT
            </button>
            <button class="tab" data-tab="grades">
                <i class="fas fa-palette"></i> Градация рейтинга
            </button>
        </div>
        
        <!-- Вкладка Коэффициенты -->
        <div id="tab-coefficients" class="tab-content active">
            <div class="section-header">
                <h2><i class="fas fa-calculator"></i> Коэффициенты WGSRT</h2>
                <button type="button" class="btn btn-primary btn-small" id="resetCoefficientsBtn">
                    <i class="fas fa-undo-alt"></i> Сбросить
                </button>
            </div>
            
            <form id="coefficientsForm">
                <table class="coefficients-table">
                    <thead>
                        <tr>
                            <th style="width: 15%">Параметр</th>
                            <th style="width: 20%">Вес</th>
                            <th style="width: 25%">Нормализация</th>
                            <th style="width: 20%">Мин.</th>
                            <th style="width: 20%">Макс.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coefficients as $coef): 
                            $paramName = $paramNames[$coef['parameter_name']] ?? $coef['parameter_name'];
                        ?>
                        <tr data-param="<?php echo $coef['parameter_name']; ?>">
                            <td class="coefficient-name"><?php echo $paramName; ?></td>
                            <td>
                                <div class="number-wrapper">
                                    <input type="number" step="any" 
                                           name="coef_<?php echo $coef['parameter_name']; ?>" 
                                           value="<?php echo $coef['coefficient_value']; ?>" 
                                           class="number-input coef-value" id="coef_<?php echo $coef['parameter_name']; ?>">
                                    <div class="number-controls">
                                        <button type="button" class="number-up" onclick="incrementNumber('coef_<?php echo $coef['parameter_name']; ?>', 0.01)">▲</button>
                                        <button type="button" class="number-down" onclick="decrementNumber('coef_<?php echo $coef['parameter_name']; ?>', 0.01)">▼</button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="number-wrapper">
                                    <input type="number" step="any" 
                                           name="norm_<?php echo $coef['parameter_name']; ?>" 
                                           value="<?php echo $coef['normalization_factor']; ?>" 
                                           class="number-input" id="norm_<?php echo $coef['parameter_name']; ?>">
                                    <div class="number-controls">
                                        <button type="button" class="number-up" onclick="incrementNumber('norm_<?php echo $coef['parameter_name']; ?>', 100)">▲</button>
                                        <button type="button" class="number-down" onclick="decrementNumber('norm_<?php echo $coef['parameter_name']; ?>', 100)">▼</button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="number-wrapper">
                                    <input type="number" step="any" 
                                           name="min_<?php echo $coef['parameter_name']; ?>" 
                                           value="<?php echo $coef['min_value']; ?>" 
                                           class="number-input" id="min_<?php echo $coef['parameter_name']; ?>">
                                    <div class="number-controls">
                                        <button type="button" class="number-up" onclick="incrementNumber('min_<?php echo $coef['parameter_name']; ?>', 1)">▲</button>
                                        <button type="button" class="number-down" onclick="decrementNumber('min_<?php echo $coef['parameter_name']; ?>', 1)">▼</button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="number-wrapper">
                                    <input type="number" step="any" 
                                           name="max_<?php echo $coef['parameter_name']; ?>" 
                                           value="<?php echo $coef['max_value']; ?>" 
                                           class="number-input" id="max_<?php echo $coef['parameter_name']; ?>">
                                    <div class="number-controls">
                                        <button type="button" class="number-up" onclick="incrementNumber('max_<?php echo $coef['parameter_name']; ?>', 1)">▲</button>
                                        <button type="button" class="number-down" onclick="decrementNumber('max_<?php echo $coef['parameter_name']; ?>', 1)">▼</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="action-bar">
                    <div class="action-bar-left"></div>
                    <button type="button" class="btn" id="cancelCoefficientsBtn">
                        <i class="fas fa-undo-alt"></i> Отменить
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveCoefficientsBtn">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Вкладка Градация -->
        <div id="tab-grades" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-palette"></i> Градация рейтинга</h2>
            </div>
            
            <form id="gradesForm">
                <table class="grades-table" id="gradesTable">
                    <thead>
                        <tr>
                            <th style="width: 50px">Цвет</th>
                            <th style="width: 15%">Название (RU)</th>
                            <th style="width: 15%">Название (EN)</th>
                            <th style="width: 12%">Код CSS</th>
                            <th style="width: 10%">Мин.</th>
                            <th style="width: 10%">Макс.</th>
                            <th style="width: 14%">Описание (RU)</th>
                            <th style="width: 14%">Описание (EN)</th>
                            <th style="width: 8%">Порядок</th>
                            <th style="width: 52px">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="gradesList">
                        <?php foreach ($grades as $grade): ?>
                        <tr class="grade-row" data-grade-id="<?php echo $grade['id']; ?>">
                            <td class="grade-color-cell">
                                <div class="grade-color" style="background: <?php echo $grade['color']; ?>" onclick="openColorPicker(this, event)"></div>
                                <input type="hidden" name="color_<?php echo $grade['id']; ?>" value="<?php echo $grade['color']; ?>" class="grade-color-input">
                            </td>
                            <td>
                                <input type="text" name="name_<?php echo $grade['id']; ?>" value="<?php echo htmlspecialchars($grade['grade_name']); ?>" 
                                       class="grade-input" placeholder="Название (RU)" required>
                            </td>
                            <td>
                                <input type="text" name="name_en_<?php echo $grade['id']; ?>" value="<?php echo htmlspecialchars($grade['grade_name_en'] ?? ''); ?>" 
                                       class="grade-input" placeholder="Name (EN)">
                            </td>
                            <td>
                                <input type="text" name="code_<?php echo $grade['id']; ?>" value="<?php echo $grade['grade_code']; ?>" 
                                       class="grade-input" placeholder="Код CSS" required pattern="[a-z-]+">
                            </td>
                            <td>
                                <div class="number-wrapper">
                                    <input type="number" step="any" 
                                           name="min_<?php echo $grade['id']; ?>" value="<?php echo $grade['min_value']; ?>" 
                                           class="number-input number-input-small" id="min_<?php echo $grade['id']; ?>" required>
                                    <div class="number-controls">
                                        <button type="button" class="number-up" onclick="incrementNumber('min_<?php echo $grade['id']; ?>', 1)">▲</button>
                                        <button type="button" class="number-down" onclick="decrementNumber('min_<?php echo $grade['id']; ?>', 1)">▼</button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="number-wrapper">
                                    <input type="number" step="any" 
                                           name="max_<?php echo $grade['id']; ?>" value="<?php echo $grade['max_value']; ?>" 
                                           class="number-input number-input-small" id="max_<?php echo $grade['id']; ?>" required>
                                    <div class="number-controls">
                                        <button type="button" class="number-up" onclick="incrementNumber('max_<?php echo $grade['id']; ?>', 1)">▲</button>
                                        <button type="button" class="number-down" onclick="decrementNumber('max_<?php echo $grade['id']; ?>', 1)">▼</button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <input type="text" name="desc_<?php echo $grade['id']; ?>" value="<?php echo htmlspecialchars($grade['description'] ?? ''); ?>" 
                                       class="grade-input" placeholder="Описание (RU)">
                            </td>
                            <td>
                                <input type="text" name="desc_en_<?php echo $grade['id']; ?>" value="<?php echo htmlspecialchars($grade['description_en'] ?? ''); ?>" 
                                       class="grade-input" placeholder="Description (EN)">
                            </td>
                            <td>
                                <div class="number-wrapper">
                                    <input type="number" step="any" 
                                           name="order_<?php echo $grade['id']; ?>" value="<?php echo $grade['sort_order']; ?>" 
                                           class="number-input number-input-small" id="order_<?php echo $grade['id']; ?>">
                                    <div class="number-controls">
                                        <button type="button" class="number-up" onclick="incrementNumber('order_<?php echo $grade['id']; ?>', 1)">▲</button>
                                        <button type="button" class="number-down" onclick="decrementNumber('order_<?php echo $grade['id']; ?>', 1)">▼</button>
                                    </div>
                                </div>
                            </td>
                            <td class="grade-actions">
                                <button type="button" class="btn btn-icon" onclick="deleteGrade(this, <?php echo $grade['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="action-bar">
                    <div class="action-bar-left">
                        <button type="button" class="btn" id="addGradeBtn">
                            <i class="fas fa-plus"></i> Добавить градацию
                        </button>
                    </div>
                    <button type="button" class="btn" id="cancelGradesBtn">
                        <i class="fas fa-undo-alt"></i> Отменить изменения
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveGradesBtn">
                        <i class="fas fa-save"></i> Сохранить градацию
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <?php include __DIR__ . '/includes/footer.php'; ?>
    <?php if ($db_error === null): ?>
    <script src="/admin/js/wgsrt.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
    <?php endif; ?>
</body>
</html>