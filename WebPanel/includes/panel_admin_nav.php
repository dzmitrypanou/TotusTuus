<?php
declare(strict_types=1);

/**
 * Агульная навігацыя адмін-панэлі (пасля ўваходу).
 * Перад include: $panelNavPage — 'index'|'liturgy'|'liturgy_observances'|'lectionary'|'liturgy_empty'|'announcements'|'users';
 * для index: $panelNavView — бягучы ?view=; для liturgy*: $panelNavCalYear — год у спасылцы «Пустыя дні».
 */
if (!function_exists('panel_can_access_section')) {
    require_once __DIR__ . '/panel_auth.php';
}

$panelNavView = $panelNavView ?? 'categories';
$panelNavCalYear = isset($panelNavCalYear) ? (int)$panelNavCalYear : (int)date('Y');
if ($panelNavCalYear < 1900 || $panelNavCalYear > 2100) {
    $panelNavCalYear = (int)date('Y');
}

function panel_admin_nav_active_page(string $page, string $current): string
{
    return $page === $current ? ' active' : '';
}

function panel_admin_nav_index_views_active(string $panelNavPage, string $panelNavView, array $views): string
{
    return $panelNavPage === 'index' && in_array($panelNavView, $views, true) ? ' active' : '';
}

?>
<nav class="top-nav" aria-label="Раздзелы панэлі">
  <div class="top-nav-row">
    <?php if (panel_can_access_section('prayers')): ?>
    <div class="nav-group">
      <span class="nav-group-label">Катэгорыі</span>
      <div class="nav-group-items">
        <a href="/?view=categories" class="btn-pill<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['categories']) ?>">Дрэва</a>
        <a href="/?view=add-category" class="btn-pill<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['add-category']) ?>">Дадаць</a>
      </div>
    </div>
    <div class="nav-group">
      <span class="nav-group-label">Малітвы</span>
      <div class="nav-group-items">
        <a href="/?view=add-prayer" class="btn-pill<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['add-prayer']) ?>">Дадаць</a>
        <a href="/?view=prayers" class="btn-pill<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['prayers']) ?>">Каталог</a>
      </div>
    </div>
    <?php endif; ?>
    <?php if (panel_can_access_section('songbook')): ?>
    <div class="nav-group">
      <span class="nav-group-label">Спеўнік</span>
      <div class="nav-group-items">
        <a href="/?view=songbook" class="btn-pill<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['songbook', 'add-songbook']) ?>">Запісы</a>
      </div>
    </div>
    <?php endif; ?>
    <?php if (panel_can_access_section('scripture')): ?>
    <div class="nav-group">
      <span class="nav-group-label">Біблія</span>
      <div class="nav-group-items">
        <a href="/?view=scripture" class="btn-pill<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['scripture', 'scripture-import', 'scripture-chapter']) ?>">Пераклады</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div class="top-nav-row">
    <?php
    $anyLiturgyNav = panel_can_access_section('liturgy')
        || panel_can_access_section('lectionary')
        || panel_can_access_section('announcements');
    ?>
    <?php if ($anyLiturgyNav): ?>
    <div class="nav-group">
      <span class="nav-group-label">Літургія</span>
      <div class="nav-group-items">
        <?php if (panel_can_access_section('liturgy')): ?>
        <a href="/admin/liturgy.php" class="btn-pill<?= panel_admin_nav_active_page('liturgy', $panelNavPage) ?>">Каляндар</a>
        <a href="/admin/liturgy_observances.php" class="btn-pill<?= panel_admin_nav_active_page('liturgy_observances', $panelNavPage) ?>">Святы БД</a>
        <a href="/admin/liturgy_empty_days.php?from_year=<?= $panelNavCalYear ?>&amp;to_year=<?= $panelNavCalYear ?>" class="btn-pill<?= panel_admin_nav_active_page('liturgy_empty', $panelNavPage) ?>">Пустыя дні</a>
        <?php endif; ?>
        <?php if (panel_can_access_section('lectionary')): ?>
        <a href="/admin/lectionary.php" class="btn-pill<?= panel_admin_nav_active_page('lectionary', $panelNavPage) ?>">Лекцыянарый</a>
        <?php endif; ?>
        <?php if (panel_can_access_section('announcements')): ?>
        <a href="/admin/announcements.php" class="btn-pill<?= panel_admin_nav_active_page('announcements', $panelNavPage) ?>">Аб’явы</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if (panel_is_admin()): ?>
    <div class="nav-group">
      <div class="nav-group-items">
        <a href="/admin/users.php" class="btn-pill<?= panel_admin_nav_active_page('users', $panelNavPage) ?>">Карыстальнікі</a>
      </div>
    </div>
    <?php endif; ?>
    <div class="nav-group">
      <div class="nav-group-items">
        <form method="post"><?= panel_csrf_field() ?>
          <button class="btn-pill" type="submit" name="logout" value="1">Выйсці</button>
        </form>
      </div>
    </div>
  </div>
</nav>
