<?php
/**
 * Компактное меню админки. Требует подключённый bootstrap и авторизацию.
 * Перед include задайте $navCurrent: index | pages | site-menu | dictionaries | maps | wgsrt | users | dashboard
 */
if (!isset($navCurrent)) {
    $navCurrent = 'index';
}
$au = function_exists('admin_user') ? admin_user() : null;
$displayName = $au && !empty($au['username']) ? $au['username'] : '';
?>
<div class="header-controls header-controls--compact">
    <?php if ($displayName !== ''): ?>
        <span class="admin-header-user" title="Вы вошли как"><?php echo htmlspecialchars($displayName); ?></span>
    <?php endif; ?>
    <a href="/admin/dashboard" class="btn admin-header-icon-btn" title="Дашборд" aria-label="Дашборд">
        <i class="fas fa-tachometer-alt"></i>
    </a>
    <button type="button" class="btn admin-header-icon-btn" onclick="location.reload()" title="Обновить страницу" aria-label="Обновить страницу">
        <i class="fas fa-sync-alt"></i>
    </button>
    <a href="/" class="btn admin-header-icon-btn" title="На сайт" aria-label="На сайт">
        <i class="fas fa-external-link-alt"></i>
    </a>
    <form method="post" action="/admin/logout" class="admin-header-logout-form">
        <input type="hidden" name="logout" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token()); ?>">
        <button type="submit" class="btn admin-header-logout-btn" title="Выйти из админ-панели" aria-label="Выйти">
            <i class="fas fa-sign-out-alt"></i>
            <span>Выйти</span>
        </button>
    </form>
    <details class="admin-nav-menu">
        <summary class="btn admin-nav-menu-toggle" aria-label="Открыть список разделов">
            <i class="fas fa-bars"></i>
            <span>Разделы</span>
        </summary>
        <div class="admin-nav-menu-panel">
            <div class="admin-nav-menu-group">
                <a href="/admin/dashboard" class="admin-nav-menu-item<?php echo $navCurrent === 'dashboard' ? ' is-active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Дашборд
                </a>
            </div>
            <div class="admin-nav-menu-divider" role="separator"></div>
            <div class="admin-nav-menu-group">
                <a href="/admin/tanks" class="admin-nav-menu-item<?php echo $navCurrent === 'index' ? ' is-active' : ''; ?>">
                    <i class="fas fa-tools"></i> Редактор танков
                </a>
                <a href="/admin/pages" class="admin-nav-menu-item<?php echo $navCurrent === 'pages' ? ' is-active' : ''; ?>">
                    <i class="fas fa-file-alt"></i> Страницы
                </a>
                <a href="/admin/site-menu" class="admin-nav-menu-item<?php echo $navCurrent === 'site-menu' ? ' is-active' : ''; ?>">
                    <i class="fas fa-bars"></i> Меню сайта
                </a>
                <a href="/admin/dictionaries" class="admin-nav-menu-item<?php echo $navCurrent === 'dictionaries' ? ' is-active' : ''; ?>">
                    <i class="fas fa-book"></i> Нации и типы
                </a>
                <a href="/admin/maps" class="admin-nav-menu-item<?php echo $navCurrent === 'maps' ? ' is-active' : ''; ?>">
                    <i class="fas fa-map"></i> Карты
                </a>
                <a href="/admin/wgsrt" class="admin-nav-menu-item<?php echo $navCurrent === 'wgsrt' ? ' is-active' : ''; ?>">
                    <i class="fas fa-chart-line"></i> WGSRT
                </a>
                <?php if (function_exists('admin_is_admin') && admin_is_admin()): ?>
                    <a href="/admin/users" class="admin-nav-menu-item<?php echo $navCurrent === 'users' ? ' is-active' : ''; ?>">
                        <i class="fas fa-users-cog"></i> Пользователи
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </details>
</div>
