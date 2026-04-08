<?php
require_once __DIR__ . '/includes/bootstrap.php';
$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';

admin_require_web();

$db_error = null;
$nationLabelRows = [];
$tankTypeLabelRows = [];

try {
    $nationLabelRows = $db->fetchAll('SELECT nation_code, display_name_ru, display_name_en FROM nation_labels ORDER BY nation_code');
    $tankTypeLabelRows = $db->fetchAll('SELECT type_code, display_name_ru, display_name_en FROM tank_type_labels ORDER BY type_code');
} catch (Exception $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Справочники наций и типов техники | Админка</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo htmlspecialchars($appVersion); ?>">
    <style>
        .dict-page .dict-table code { color: #9aa7b2; }
        .dict-page .dict-table {
            table-layout: fixed;
        }
        .dict-page .dict-table th,
        .dict-page .dict-table td {
            vertical-align: middle;
        }
        .dict-page .dict-table .dict-col-code {
            width: 90px;
        }
        .dict-page .dict-table .dict-col-name {
            width: calc((100% - 90px - 140px) / 2);
        }
        .dict-page .dict-table .dict-col-actions {
            width: 140px;
            text-align: right;
        }
        .dict-page .dict-table input[type="text"] {
            width: 100%;
            padding: 8px 12px;
            background: #14181c;
            border: 1px solid #2a3138;
            color: #e8eef2;
            font-family: inherit;
        }
        .dict-page h2 { margin-top: 28px; margin-bottom: 12px; font-size: 1.15rem; }
        .dict-page h2:first-of-type { margin-top: 0; }
    </style>
    <?php require __DIR__ . '/includes/csrf_head.php'; ?>
</head>
<body class="dict-page">
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-book" style="color: #ffd966;"></i>
                Справочники наций и типов техники
            </h1>
            <?php $navCurrent = 'dictionaries'; include __DIR__ . '/includes/header_nav.php'; ?>
        </div>

        <?php if ($db_error !== null): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                Ошибка БД: <?php echo htmlspecialchars($db_error); ?>
            </div>
        <?php else: ?>
            <h2><i class="fas fa-flag"></i> Нации</h2>
            <div class="table-wrapper">
                <table class="dict-table" id="nationsTable">
                    <colgroup>
                        <col class="dict-col-code">
                        <col class="dict-col-name">
                        <col class="dict-col-name">
                        <col class="dict-col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Код</th>
                            <th>Название (RU)</th>
                            <th>Название (EN)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($nationLabelRows as $row): ?>
                            <tr data-code="<?php echo htmlspecialchars($row['nation_code']); ?>">
                                <td><code><?php echo htmlspecialchars($row['nation_code']); ?></code></td>
                                <td>
                                    <input type="text" name="display_name_ru"
                                           value="<?php echo htmlspecialchars($row['display_name_ru']); ?>"
                                           aria-label="Название RU для <?php echo htmlspecialchars($row['nation_code']); ?>">
                                </td>
                                <td>
                                    <input type="text" name="display_name_en"
                                           value="<?php echo htmlspecialchars($row['display_name_en'] ?? ''); ?>"
                                           aria-label="Название EN для <?php echo htmlspecialchars($row['nation_code']); ?>">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-primary save-nation-btn">
                                        <i class="fas fa-save"></i> Сохранить
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h2><i class="fas fa-cog"></i> Типы техники</h2>
            <div class="table-wrapper">
                <table class="dict-table" id="typesTable">
                    <colgroup>
                        <col class="dict-col-code">
                        <col class="dict-col-name">
                        <col class="dict-col-name">
                        <col class="dict-col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Код</th>
                            <th>Название (RU)</th>
                            <th>Название (EN)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tankTypeLabelRows as $row): ?>
                            <tr data-code="<?php echo htmlspecialchars($row['type_code']); ?>">
                                <td><code><?php echo htmlspecialchars($row['type_code']); ?></code></td>
                                <td>
                                    <input type="text" name="display_name_ru"
                                           value="<?php echo htmlspecialchars($row['display_name_ru']); ?>"
                                           aria-label="Название RU для <?php echo htmlspecialchars($row['type_code']); ?>">
                                </td>
                                <td>
                                    <input type="text" name="display_name_en"
                                           value="<?php echo htmlspecialchars($row['display_name_en'] ?? ''); ?>"
                                           aria-label="Название EN для <?php echo htmlspecialchars($row['type_code']); ?>">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-primary save-type-btn">
                                        <i class="fas fa-save"></i> Сохранить
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p>
                <a href="/admin/tanks" class="btn"><i class="fas fa-arrow-left"></i> К редактору танков</a>
            </p>

            <script src="/admin/js/dictionaries.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
