<?php
require_once __DIR__ . '/includes/bootstrap.php';

$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';

admin_require_web();

$db_error = null;
$menuItems = [];
try {
    $menuItems = $db->fetchAll(
        'SELECT id, label, label_en, href, sort_order, is_enabled, placement FROM cms_site_menu ORDER BY placement, sort_order ASC, id ASC'
    );
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

$menuHeader = [];
$menuFooter = [];
foreach ($menuItems as $row) {
    $p = isset($row['placement']) ? trim((string) $row['placement']) : '';
    if ($p === 'footer') {
        $menuFooter[] = $row;
    } else {
        $menuHeader[] = $row;
    }
}

$pageTitle = 'Меню сайта | Админка';
$extraHead = '';
$bodyClass = 'admin-site-menu';
require __DIR__ . '/includes/admin_head.php';
?>

    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-bars" style="color: #ffd966;"></i>
                Меню сайта
            </h1>
            <?php $navCurrent = 'site-menu'; include __DIR__ . '/includes/header_nav.php'; ?>
        </div>

        <?php if ($db_error !== null): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                Ошибка БД: <?php echo htmlspecialchars($db_error); ?>
            </div>
        <?php else: ?>
            <p class="site-menu-hint">
                Ссылки для шапки и для подвала публичного сайта. Для страниц CMS укажите путь вида <code>/about</code> или коротко <code>about</code>.
                Внешние ссылки — полный URL (<code>https://…</code>).
            </p>

            <div class="site-menu-panels-footer" role="region" aria-label="Редакторы меню шапки и подвала">
                <div class="site-menu-panel">
                    <h2 class="site-menu-panel-title"><i class="fas fa-window-maximize" aria-hidden="true"></i> Шапка сайта</h2>
                    <p class="site-menu-panel-hint">Текстовые ссылки справа от заголовка (рядом с иконками соцсетей).</p>
                    <div class="site-menu-table-wrap">
                        <table class="site-menu-table">
                            <thead>
                                <tr>
                                    <th>Текст ссылки (RU)</th>
                                    <th>Text link (EN)</th>
                                    <th>Адрес (URL или путь)</th>
                                    <th class="site-menu-col-enabled">Показывать</th>
                                    <th class="site-menu-col-actions"></th>
                                </tr>
                            </thead>
                            <tbody id="siteMenuRowsHeader">
                                <?php foreach ($menuHeader as $row): ?>
                                    <tr class="site-menu-row">
                                        <td>
                                            <input type="text" class="site-menu-label" value="<?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Название">
                                        </td>
                                        <td>
                                            <input type="text" class="site-menu-label-en" value="<?php echo htmlspecialchars($row['label_en'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Name (EN)">
                                        </td>
                                        <td>
                                            <input type="text" class="site-menu-href" value="<?php echo htmlspecialchars($row['href'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="/page или https://…">
                                        </td>
                                        <td class="site-menu-col-enabled">
                                            <label class="site-menu-switch">
                                                <input type="checkbox" class="site-menu-enabled" <?php echo !empty($row['is_enabled']) ? 'checked' : ''; ?> title="Показывать пункт">
                                                <span class="site-menu-switch-slider" aria-hidden="true"></span>
                                            </label>
                                        </td>
                                        <td class="site-menu-col-actions">
                                            <button type="button" class="btn btn-danger site-menu-row-del" title="Удалить строку">Удалить</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="site-menu-panel-actions">
                        <button type="button" class="btn btn-primary site-menu-add-btn" data-target="siteMenuRowsHeader">
                            <i class="fas fa-plus"></i> Добавить пункт
                        </button>
                    </p>
                </div>
                <div class="site-menu-panel">
                    <h2 class="site-menu-panel-title"><i class="fas fa-grip-lines" aria-hidden="true"></i> Подвал сайта</h2>
                    <p class="site-menu-panel-hint">Текстовые ссылки над блоком соцсетей в подвале.</p>
                    <div class="site-menu-table-wrap">
                        <table class="site-menu-table">
                            <thead>
                                <tr>
                                    <th>Текст ссылки (RU)</th>
                                    <th>Text link (EN)</th>
                                    <th>Адрес (URL или путь)</th>
                                    <th class="site-menu-col-enabled">Показывать</th>
                                    <th class="site-menu-col-actions"></th>
                                </tr>
                            </thead>
                            <tbody id="siteMenuRowsFooter">
                                <?php foreach ($menuFooter as $row): ?>
                                    <tr class="site-menu-row">
                                        <td>
                                            <input type="text" class="site-menu-label" value="<?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Название">
                                        </td>
                                        <td>
                                            <input type="text" class="site-menu-label-en" value="<?php echo htmlspecialchars($row['label_en'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Name (EN)">
                                        </td>
                                        <td>
                                            <input type="text" class="site-menu-href" value="<?php echo htmlspecialchars($row['href'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="/page или https://…">
                                        </td>
                                        <td class="site-menu-col-enabled">
                                            <label class="site-menu-switch">
                                                <input type="checkbox" class="site-menu-enabled" <?php echo !empty($row['is_enabled']) ? 'checked' : ''; ?> title="Показывать пункт">
                                                <span class="site-menu-switch-slider" aria-hidden="true"></span>
                                            </label>
                                        </td>
                                        <td class="site-menu-col-actions">
                                            <button type="button" class="btn btn-danger site-menu-row-del" title="Удалить строку">Удалить</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="site-menu-panel-actions">
                        <button type="button" class="btn btn-primary site-menu-add-btn" data-target="siteMenuRowsFooter">
                            <i class="fas fa-plus"></i> Добавить пункт
                        </button>
                    </p>
                </div>
            </div>

            <p class="site-menu-save-wrap">
                <button type="button" class="btn btn-primary" id="siteMenuSaveBtn">
                    <i class="fas fa-save"></i> Сохранить всё
                </button>
            </p>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

<?php if ($db_error === null): ?>
    <script src="/admin/js/admin.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
    <script src="/admin/js/site-menu.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
