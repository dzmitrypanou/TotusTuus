<?php
declare(strict_types=1);

/**
 * Агульная навігацыя адмін-панэлі (пасля ўваходу).
 * Перад include: $panelNavPage — 'index'|'liturgy'|'liturgy_observances'|'lectionary'|'lectionary_gap'|'liturgy_empty'|'ordo_missae'|'solemnities'|'solemnities_edit'|'announcements'|'users';
 * для index: $panelNavView — бягучы ?view=; для liturgy*: $panelNavCalYear — год у спасылцы «Пустыя дні».
 */
if (!function_exists('panel_can_access_section')) {
    require_once __DIR__ . '/panel_auth.php';
}

$panelNavView = $panelNavView ?? 'categories';
$panelNavPage = $panelNavPage ?? '';
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

if (!defined('PANEL_ADMIN_NAV_STYLE_EMITTED')) {
    define('PANEL_ADMIN_NAV_STYLE_EMITTED', true);
    ?>
  <style id="panel-admin-nav-styles">
    .header .panel-admin-nav {
      flex: 1 1 0%;
      min-width: 0;
      max-width: 100%;
      width: auto;
    }
    @media (min-width: 1181px) {
      .header:has(.panel-admin-nav) {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 3fr);
        align-items: center;
        gap: 20px;
      }
      .header:has(.panel-admin-nav) .panel-admin-nav {
        flex: none;
        width: 100%;
        max-width: 100%;
        justify-self: stretch;
      }
      .panel-nav-strip {
        justify-content: flex-end;
      }
    }
    .panel-admin-nav {
      --nav-bg: rgba(255, 255, 255, 0.07);
      --nav-bd: rgba(255, 255, 255, 0.12);
      --nav-hi: rgba(255, 255, 255, 0.11);
      --nav-line: rgba(148, 163, 184, 0.22);
      width: 100%;
      max-width: 100%;
      min-width: 0;
    }
    .panel-nav-toggle {
      display: none;
      width: 100%;
      margin: 0;
      padding: 14px 16px;
      border-radius: 12px;
      border: 1px solid var(--nav-bd);
      background: var(--nav-bg);
      color: #f1f5f9;
      font: inherit;
      font-weight: 700;
      font-size: 1rem;
      cursor: pointer;
      text-align: center;
    }
    .panel-nav-toggle:focus-visible {
      outline: 2px solid #a5b4fc;
      outline-offset: 2px;
    }
    .panel-nav-body {
      width: 100%;
      min-width: 0;
    }
    .panel-nav-strip {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      gap: 14px 20px;
      width: 100%;
    }
    .panel-nav-block {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px 10px;
      min-width: 0;
    }
    .panel-nav-block__label {
      font-size: 0.625rem;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: rgba(148, 163, 184, 0.9);
      line-height: 1.2;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .panel-nav-block__links {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px;
      min-width: 0;
    }
    .panel-nav-block__links form {
      margin: 0;
      display: inline;
    }
    a.panel-nav-link, button.panel-nav-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #e2e8f0;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.8125rem;
      padding: 8px 12px;
      border-radius: 10px;
      background: var(--nav-bg);
      border: 1px solid var(--nav-bd);
      line-height: 1.3;
      box-sizing: border-box;
      transition: background 0.12s ease, border-color 0.12s ease;
      white-space: nowrap;
      width: auto;
    }
    button.panel-nav-link {
      margin: 0;
      width: auto;
      font-family: inherit;
      cursor: pointer;
      box-shadow: none;
    }
    a.panel-nav-link:hover,
    button.panel-nav-link:hover {
      background: var(--nav-hi);
      border-color: rgba(255, 255, 255, 0.16);
    }
    a.panel-nav-link.active,
    button.panel-nav-link.active {
      background: linear-gradient(135deg, rgba(124, 108, 240, 0.45), rgba(196, 163, 90, 0.25));
      border-color: rgba(196, 163, 90, 0.45);
      color: #fff;
    }
    @media (max-width: 1099px) {
      .panel-nav-strip {
        justify-content: flex-start;
      }
      .panel-nav-toggle {
        display: block;
      }
      .panel-nav-body.is-collapsed {
        display: none;
      }
      .panel-nav-body {
        padding-top: 12px;
        margin-top: 10px;
        border-top: 1px solid var(--nav-line);
      }
      .panel-nav-strip {
        flex-direction: column;
        align-items: stretch;
        gap: 0;
      }
      .panel-nav-block {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        padding: 12px 0;
        border-bottom: 1px solid rgba(148, 163, 184, 0.12);
      }
      .panel-nav-block:last-child {
        border-bottom: none;
        padding-bottom: 0;
      }
      .panel-nav-block__label {
        font-size: 0.6875rem;
      }
      .panel-nav-block__links {
        flex-direction: column;
        align-items: stretch;
        gap: 6px;
      }
      .panel-nav-block__links form {
        display: block;
        width: 100%;
      }
      a.panel-nav-link, button.panel-nav-link {
        width: 100%;
        justify-content: flex-start;
        padding: 12px 14px;
        font-size: 0.9375rem;
        min-height: 48px;
        white-space: normal;
        text-align: left;
      }
    }
    @media (max-width: 1180px) {
      .header .panel-admin-nav {
        align-self: stretch;
        flex: 0 1 auto;
      }
    }
  </style>
    <?php
}

?>
<nav class="panel-admin-nav" aria-label="Навігацыя панэлі">
  <button type="button" class="panel-nav-toggle" aria-expanded="false" aria-controls="panel-nav-body" id="panel-nav-toggle-btn">Меню</button>
  <div class="panel-nav-body" id="panel-nav-body">
    <div class="panel-nav-strip">
    <?php if (panel_can_access_section('prayers')): ?>
      <div class="panel-nav-block">
        <span class="panel-nav-block__label" id="panel-nav-lbl-cat">Катэгорыі</span>
        <div class="panel-nav-block__links" role="group" aria-labelledby="panel-nav-lbl-cat">
          <a href="/?view=categories" class="panel-nav-link<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['categories']) ?>">Дрэва</a>
          <a href="/?view=add-category" class="panel-nav-link<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['add-category']) ?>">Дадаць катэгорыю</a>
        </div>
      </div>
      <div class="panel-nav-block">
        <span class="panel-nav-block__label" id="panel-nav-lbl-prayers">Малітвы</span>
        <div class="panel-nav-block__links" role="group" aria-labelledby="panel-nav-lbl-prayers">
          <a href="/?view=add-prayer" class="panel-nav-link<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['add-prayer']) ?>">Дадаць малітву</a>
          <a href="/?view=prayers" class="panel-nav-link<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['prayers']) ?>">Малітвы</a>
        </div>
      </div>
    <?php endif; ?>
    <?php if (panel_can_access_section('songbook')): ?>
      <div class="panel-nav-block">
        <span class="panel-nav-block__label" id="panel-nav-lbl-song">Спеўнік</span>
        <div class="panel-nav-block__links" role="group" aria-labelledby="panel-nav-lbl-song">
          <a href="/?view=songbook" class="panel-nav-link<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['songbook', 'add-songbook']) ?>">Запісы</a>
        </div>
      </div>
    <?php endif; ?>
    <?php if (panel_can_access_section('scripture')): ?>
      <div class="panel-nav-block">
        <span class="panel-nav-block__label" id="panel-nav-lbl-bible">Біблія</span>
        <div class="panel-nav-block__links" role="group" aria-labelledby="panel-nav-lbl-bible">
          <a href="/?view=scripture" class="panel-nav-link<?= panel_admin_nav_index_views_active($panelNavPage, $panelNavView, ['scripture', 'scripture-import', 'scripture-chapter']) ?>">Пераклады</a>
        </div>
      </div>
    <?php endif; ?>
    <?php
    $anyLiturgyNav = panel_can_access_section('liturgy')
        || panel_can_access_section('lectionary');
    ?>
    <?php if ($anyLiturgyNav): ?>
      <div class="panel-nav-block">
        <span class="panel-nav-block__label" id="panel-nav-lbl-liturgy">Літургія</span>
        <div class="panel-nav-block__links" role="group" aria-labelledby="panel-nav-lbl-liturgy">
          <?php if (panel_can_access_section('liturgy')): ?>
          <a href="/admin/liturgy.php" class="panel-nav-link<?= panel_admin_nav_active_page('liturgy', $panelNavPage) ?>">Каляндар</a>
          <a href="/admin/liturgy_observances.php" class="panel-nav-link<?= panel_admin_nav_active_page('liturgy_observances', $panelNavPage) ?>">Святы БД</a>
          <a href="/admin/liturgy_empty_days.php?from_year=<?= $panelNavCalYear ?>&amp;to_year=<?= $panelNavCalYear ?>" class="panel-nav-link<?= panel_admin_nav_active_page('liturgy_empty', $panelNavPage) ?>">Пустыя дні</a>
          <a href="/admin/ordo_missae.php" class="panel-nav-link<?= panel_admin_nav_active_page('ordo_missae', $panelNavPage) ?>">Ordo Missae</a>
          <?php endif; ?>
          <?php if (panel_can_access_section('lectionary')): ?>
          <a href="/admin/lectionary.php" class="panel-nav-link<?= panel_admin_nav_active_page('lectionary', $panelNavPage) ?>">Лекцыянарый</a>
          <a href="/admin/lectionary_observances_gap.php" class="panel-nav-link<?= panel_admin_nav_active_page('lectionary_gap', $panelNavPage) ?>">Без чытанняў</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php if (panel_can_access_section('solemnities')): ?>
      <div class="panel-nav-block">
        <span class="panel-nav-block__label" id="panel-nav-lbl-solemnities">Урачыстасці</span>
        <div class="panel-nav-block__links" role="group" aria-labelledby="panel-nav-lbl-solemnities">
          <a href="/admin/solemnities.php" class="panel-nav-link<?= in_array($panelNavPage, ['solemnities', 'solemnities_edit'], true) ? ' active' : '' ?>">Урачыстасці і святы</a>
        </div>
      </div>
    <?php endif; ?>
      <div class="panel-nav-block">
        <span class="panel-nav-block__label" id="panel-nav-lbl-panel">Панэль</span>
        <div class="panel-nav-block__links" role="group" aria-labelledby="panel-nav-lbl-panel">
    <?php if (panel_can_access_section('announcements')): ?>
          <a href="/admin/announcements.php" class="panel-nav-link<?= panel_admin_nav_active_page('announcements', $panelNavPage) ?>">Аб’явы</a>
    <?php endif; ?>
    <?php if (panel_is_admin()): ?>
          <a href="/admin/users.php" class="panel-nav-link<?= panel_admin_nav_active_page('users', $panelNavPage) ?>">Карыстальнікі</a>
    <?php endif; ?>
          <form method="post"><?= panel_csrf_field() ?>
            <button class="panel-nav-link" type="submit" name="logout" value="1">Выйсці</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</nav>
<?php

if (!defined('PANEL_ADMIN_NAV_SCRIPT_EMITTED')) {
    define('PANEL_ADMIN_NAV_SCRIPT_EMITTED', true);
    ?>
<script>
(function () {
  function q(s, r) { return (r || document).querySelector(s); }
  var nav = q('.panel-admin-nav');
  if (!nav) return;
  var btn = q('.panel-nav-toggle', nav);
  var body = q('.panel-nav-body', nav);
  var mqNav = window.matchMedia('(max-width: 1099px)');

  function syncMenuCollapse() {
    if (!btn || !body) return;
    if (mqNav.matches) {
      body.classList.add('is-collapsed');
      btn.setAttribute('aria-expanded', 'false');
    } else {
      body.classList.remove('is-collapsed');
      btn.setAttribute('aria-expanded', 'true');
    }
  }
  if (btn && body) {
    mqNav.addEventListener ? mqNav.addEventListener('change', syncMenuCollapse) : mqNav.addListener(syncMenuCollapse);
    btn.addEventListener('click', function () {
      if (!mqNav.matches) return;
      var collapsed = body.classList.toggle('is-collapsed');
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
    syncMenuCollapse();
  }

})();
</script>
    <?php
}
