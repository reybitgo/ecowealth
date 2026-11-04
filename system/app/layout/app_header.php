<?php
declare(strict_types=1);

/**
 * Shared header for app pages — EcoWealth vertical layout (plan §3.5).
 *
 * Two app layouts share this file; the menu comes from layout/nav.php:
 *   layout=member  member portal  (Shop, Cart, Orders, Wallet, …)
 *   layout=admin   back office    (Products, Packages, Orders, …)
 *   layout=plain   no sidebar — branded pages for 403/404/500/503
 *
 * @var array|null $user         Current user row (resolved when absent)
 * @var string     $layout       member | admin | plain
 * @var string     $page_title   Document title
 * @var string[]   $page_css     Extra stylesheets, paths relative to public/
 * @var string[]   $page_js      Extra scripts, paths relative to public/
 * @var string     $page_styles  Optional inline CSS for one page
 * @var string     $current_nav  Active nav item key (derived when absent)
 * @var array      $nav_notes    Optional notification entries:
 *                               [['icon' => 'bx bx-user-plus', 'tone' => 'primary',
 *                                 'title' => 'New member', 'text' => 'jane', 'time' => '2 min ago'], …]
 * @var array      $nav_badges   Optional menu counters, e.g. ['cart' => 3]
 * @var string     $nav_search_placeholder Optional navbar search hint
 * @var string     $nav_search_url         Optional navbar search target
 */

$layout      = in_array($layout ?? 'member', ['member', 'admin', 'plain'], true) ? ($layout ?? 'member') : 'member';
$me          = $user ?? auth_user();
$user        = $me;   /* sidebar.php reads $user */
$page_title  = $page_title ?? APP_NAME;
$page_css    = $page_css ?? [];
$page_js     = $page_js ?? [];
$page_styles = $page_styles ?? '';
$nav_notes   = $nav_notes ?? [];
$nav_badges  = $nav_badges ?? [];
$current_nav = $current_nav ?? ($layout === 'plain' ? '' : nav_current_key($layout, $me));
$has_menu    = $layout !== 'plain';

/* Cart badge for the sidebar item and the navbar drawer button (Phase 4).
 * A missing/older cart table must never blank every page, so the read is
 * defensive — the migration turns this into the live count. */
$cart_count = 0;
if ($me !== null) {
    try {
        $cart_count = Cart::countFor((int) $me['id']);
    } catch (Throwable $e) {
        error_log('[ecowealth] cart badge unavailable: ' . $e->getMessage());
    }
}

$nav_badges = array_merge(['cart' => $cart_count], $nav_badges);

/* A page can point the shared search at its own catalogue — the shop does
 * this for admins so their back-office sidebar does not change its target. */
$nav_search_placeholder = $nav_search_placeholder ?? ($layout === 'admin' ? 'Search products…' : 'Search the shop…');
$nav_search_url         = $nav_search_url ?? url($layout === 'admin' ? 'admin/products' : 'shop');
$html_class  = $has_menu
    ? 'layout-navbar-fixed layout-menu-fixed layout-compact customizer-hide'
    : 'layout-compact customizer-hide';
?>
<!doctype html>
<!-- beautify ignore:start -->
<html
  lang="en"
  class="<?= e($html_class) ?>"
  dir="ltr"
  data-skin="default"
  data-bs-theme="light"
  data-assets-path="<?= e(base_url() . 'assets/') ?>"
  data-template="vertical-menu-template"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <meta name="robots" content="noindex, nofollow" />
    <title><?= e($page_title) ?> | <?= e(APP_NAME) ?></title>
    <!-- Favicon -->
    <link
      rel="icon"
      type="image/png"
      href="<?= e(url('assets/img/favicon/favicon.png')) ?>"
    />
    <link
      rel="alternate icon"
      type="image/x-icon"
      href="<?= e(url('assets/img/favicon/favicon.ico')) ?>"
    />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/fonts/iconify-icons.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/boxicons/boxicons.min.css')) ?>">
    <!-- Core CSS -->
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/core.css')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/demo.css')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>" />
<?php if ($layout === 'plain'): ?>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/errors.css')) ?>" />
<?php endif; ?>
    <!-- Vendors CSS -->
    <link rel="stylesheet" href="<?= e(url('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css')) ?>" />
<?php foreach ($page_css as $css): ?>
    <link rel="stylesheet" href="<?= e(asset_url($css)) ?>" />
<?php endforeach; ?>
    <!-- Page CSS -->
<?php if ($page_styles !== ''): ?>
    <style>
<?= $page_styles ?>

    </style>
<?php endif; ?>
    <!-- Helpers -->
    <script src="<?= e(url('assets/vendor/js/helpers.js')) ?>"></script>
    <!--? Template customizer: To hide customizer set displayCustomizer value false in config.js.  -->
    <script src="<?= e(url('assets/vendor/js/template-customizer.js')) ?>"></script>
    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->
    <script src="<?= e(url('assets/js/config.js')) ?>"></script>
  </head>
  <body<?= $has_menu ? '' : ' class="error-page"' ?>>
    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
<?php if ($has_menu): ?>
        <!-- Menu -->
        <aside id="layout-menu" class="layout-menu menu-vertical menu">
          <div class="app-brand demo">
            <a
              href="<?= e(url($layout === 'admin' ? 'admin' : 'dashboard')) ?>"
              class="app-brand-link"
              aria-label="<?= e(APP_NAME) ?> <?= $layout === 'admin' ? 'back office' : 'dashboard' ?>"
            >
              <?php $brand_mark = true; ?>
              <?php require __DIR__ . '/logo.php'; ?>
            </a>
            <a
              href="javascript:void(0);"
              class="layout-menu-toggle menu-link text-large ms-auto"
            >
              <i class="bx bx-chevron-left bx-sm align-middle"></i>
            </a>
          </div>
          <div class="menu-inner-shadow"></div>
<?php require __DIR__ . '/sidebar.php'; ?>
        </aside>
        <!-- / Menu -->
<?php endif; ?>

        <!-- Layout container -->
        <div class="layout-page">
<?php if ($has_menu): ?>
          <!-- Navbar -->
          <nav
            class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
            id="layout-navbar"
          >
            <div
              class="layout-menu-toggle navbar-nav align-items-xl-center me-4 me-xl-0 d-xl-none"
            >
              <a
                class="nav-item nav-link px-0 me-xl-6"
                href="javascript:void(0)"
              >
                <i class="icon-base bx bx-menu icon-md"></i>
              </a>
            </div>
            <div
              class="navbar-nav-right d-flex align-items-center justify-content-end"
              id="navbar-collapse"
            >
<?php if ($has_menu): ?>
              <!-- Quick search -->
              <div class="navbar-nav align-items-center d-none d-md-flex me-xl-4">
                <div class="nav-item d-flex align-items-center">
                  <i class="icon-base bx bx-search icon-md"></i>
                  <input
                    type="search"
                    id="navbarQuickSearch"
                    class="form-control border-0 bg-transparent shadow-none ps-2"
                    placeholder="<?= e($nav_search_placeholder) ?>"
                    aria-label="Search"
                    data-search-url="<?= e($nav_search_url) ?>"
                  />
                </div>
              </div>
              <!-- / Quick search -->
<?php endif; ?>

              <ul class="navbar-nav flex-row align-items-center ms-md-auto">
                <!-- Cart (opens the drawer; the link still works without JS) -->
                <li class="nav-item me-2 me-xl-1">
                  <a
                    class="nav-link btn btn-icon btn-text-secondary rounded-pill position-relative cart-navbar-toggle"
                    href="<?= e(url('cart')) ?>"
                    data-cart-open
                    aria-label="Open cart<?= $cart_count > 0 ? ' (' . $cart_count . ' items)' : '' ?>"
                  >
                    <i class="icon-base bx bx-cart icon-md"></i>
                    <span class="badge rounded-pill bg-primary cart-navbar-badge" data-cart-badge<?= $cart_count > 0 ? '' : ' hidden' ?>><?= $cart_count ?></span>
                  </a>
                </li>
                <!-- / Cart -->

                <!-- Notifications -->
                <li class="nav-item dropdown-notifications navbar-dropdown dropdown me-3 me-xl-2">
                  <a
                    class="nav-link dropdown-toggle hide-arrow btn btn-icon btn-text-secondary rounded-pill"
                    href="javascript:void(0);"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"
                  >
                    <i class="icon-base bx bx-bell icon-md"></i>
                    <?php if ($nav_notes !== []): ?>
                      <span class="position-absolute top-0 start-50 translate-middle-y badge badge-dot bg-danger mt-2 border"></span>
                    <?php endif; ?>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end py-0" style="min-width: 21rem">
                    <li class="border-bottom">
                      <div class="d-flex align-items-center justify-content-between px-4 py-3">
                        <h6 class="mb-0">Notifications</h6>
                        <span class="badge rounded-pill bg-label-primary"><?= count($nav_notes) ?> new</span>
                      </div>
                    </li>
                    <li>
                      <ul class="list-group list-group-flush" style="max-height: 19rem; overflow-y: auto">
<?php if ($nav_notes === []): ?>
                        <li class="list-group-item text-body-secondary small px-4 py-4">
                          Nothing new right now — you are all caught up.
                        </li>
<?php endif; ?>
<?php foreach ($nav_notes as $note): ?>
                        <li class="list-group-item d-flex align-items-start gap-3 px-4 py-3 border-0">
                          <div class="avatar avatar-sm flex-shrink-0">
                            <span class="avatar-initial rounded bg-label-<?= e($note['tone'] ?? 'primary') ?>">
                              <i class="icon-base <?= e(icon_class((string) ($note['icon'] ?? 'bx-bell'))) ?>"></i>
                            </span>
                          </div>
                          <div class="flex-grow-1">
                            <h6 class="mb-0 small"><?= e($note['title'] ?? '') ?></h6>
                            <small class="text-body-secondary"><?= e($note['text'] ?? '') ?></small>
                          </div>
                          <small class="text-body-secondary text-nowrap"><?= e($note['time'] ?? '') ?></small>
                        </li>
<?php endforeach; ?>
                      </ul>
                    </li>
                  </ul>
                </li>
                <!-- / Notifications -->

                <!-- User -->
                <li class="nav-item navbar-dropdown dropdown-user dropdown">
                  <a
                    class="nav-link dropdown-toggle hide-arrow p-0"
                    href="javascript:void(0)"
                    data-bs-toggle="dropdown"
                  >
                    <div class="avatar avatar-online">
                      <span
                        class="avatar-initial rounded-circle bg-label-primary fw-semibold"
                      ><?= e(initials($me['username'] ?? 'U')) ?></span>
                    </div>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <a class="dropdown-item" href="<?= e(url('dashboard')) ?>">
                        <div class="d-flex">
                          <div class="flex-shrink-0 me-3">
                            <div class="avatar avatar-online">
                              <span
                                class="avatar-initial rounded-circle bg-label-primary fw-semibold"
                              ><?= e(initials($me['username'] ?? 'U')) ?></span>
                            </div>
                          </div>
                          <div class="flex-grow-1">
                            <h6 class="mb-0"><?= e($me['username'] ?? 'User') ?></h6>
                            <small class="text-body-secondary"><?= e($me['email'] ?? '') ?></small>
                          </div>
                        </div>
                      </a>
                    </li>
                    <li>
                      <div class="dropdown-divider my-1"></div>
                    </li>
                    <li>
                      <a class="dropdown-item" href="<?= e(url($layout === 'admin' ? 'admin' : 'dashboard')) ?>">
                        <i class="icon-base bx bx-home-smile icon-md me-3"></i
                        ><span>Dashboard</span>
                      </a>
                    </li>
                    <li>
                      <a class="dropdown-item" href="<?= e(url('profile/password')) ?>">
                        <i class="icon-base bx bx-lock-alt icon-md me-3"></i
                        ><span>Change password</span>
                      </a>
                    </li>
                    <li>
                      <div class="dropdown-divider my-1"></div>
                    </li>
                    <li>
                      <form method="POST" action="<?= e(url('logout')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="dropdown-item border-0 bg-transparent w-100">
                          <i class="icon-base bx bx-power-off icon-md me-3"></i
                          ><span>Sign out</span>
                        </button>
                      </form>
                    </li>
                  </ul>
                </li>
                <!--/ User -->
              </ul>
            </div>
          </nav>
          <!-- / Navbar -->
<?php endif; ?>

          <!-- Content wrapper -->
          <div class="content-wrapper">
<?php if ($has_menu): ?>
<?php require dirname(__DIR__) . '/views/partials/flash.php'; ?>
<?php endif; ?>
