<?php
/** Shared header for authenticated (app) pages — Sneat vertical layout. */

/**
 * @var array    $user        Currently authenticated user (set by the page)
 * @var string[] $page_css    Extra stylesheets, paths relative to public/
 * @var string[] $page_js     Extra scripts, paths relative to public/
 * @var string   $page_styles Optional inline CSS for one page
 * @var array    $nav_notes   Optional notification entries:
 *                            [['icon' => 'bx bx-user-plus', 'tone' => 'primary',
 *                              'title' => 'New member', 'text' => 'jane', 'time' => '2 min ago'], …]
 */

$me          = $user;
$page_title  = $page_title ?? APP_NAME;
$page_css    = $page_css ?? [];
$page_js     = $page_js ?? [];
$page_styles = $page_styles ?? '';
$nav_notes   = $nav_notes ?? [];
$nav_badges  = $nav_badges ?? [];
$current_nav = $current_nav ?? 'dashboard';
?>
<!doctype html>
<!-- beautify ignore:start -->
<html
  lang="en"
  class="layout-navbar-fixed layout-menu-fixed layout-compact customizer-hide"
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
  <body>
    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <!-- Menu -->
        <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
          <div class="app-brand demo">
            <a href="<?= e(url('dashboard')) ?>" class="app-brand-link">
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
          <ul class="menu-inner py-1">
            <!-- Growth overview -->
            <li class="menu-header mt-7">
              <span class="menu-header-text" data-i18n="Growth overview">Growth overview</span>
            </li>
            <li class="menu-item <?= $current_nav === 'dashboard' ? 'active' : '' ?>">
              <a href="<?= e(url('dashboard')) ?>" class="menu-link" data-spy-target="overview">
                <i class="menu-icon icon-base bx bx-home-smile"></i>
                <div data-i18n="Overview">Overview</div>
              </a>
            </li>
            <li class="menu-item">
              <a href="#community" class="menu-link" data-spy-target="community">
                <i class="menu-icon icon-base bx bx-group"></i>
                <div data-i18n="Community">Community</div>
                <?php if (!empty($nav_badges['members'])): ?>
                  <span class="badge rounded-pill bg-label-primary ms-auto"><?= (int) $nav_badges['members'] ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li class="menu-item">
              <a href="#growth" class="menu-link" data-spy-target="growth">
                <i class="menu-icon icon-base bx bx-line-chart"></i>
                <div data-i18n="Community growth">Community growth</div>
              </a>
            </li>

            <!-- The ecosystem -->
            <li class="menu-header mt-7">
              <span class="menu-header-text" data-i18n="The ecosystem">The ecosystem</span>
            </li>
            <li class="menu-item">
              <a href="#dimensions" class="menu-link" data-spy-target="dimensions">
                <i class="menu-icon icon-base bx bx-layer"></i>
                <div data-i18n="Five dimensions">Five dimensions</div>
              </a>
            </li>
            <li class="menu-item">
              <a href="#leadership" class="menu-link" data-spy-target="leadership">
                <i class="menu-icon icon-base bx bx-medal"></i>
                <div data-i18n="Leadership path">Leadership path</div>
              </a>
            </li>
            <li class="menu-item">
              <a href="#participation" class="menu-link" data-spy-target="participation">
                <i class="menu-icon icon-base bx bx-check-shield"></i>
                <div data-i18n="Participation">Participation</div>
              </a>
            </li>
            <li class="menu-item">
              <a href="#journey" class="menu-link" data-spy-target="journey">
                <i class="menu-icon icon-base bx bx-map"></i>
                <div data-i18n="The journey">The journey</div>
              </a>
            </li>

            <!-- Account -->
            <li class="menu-header mt-7">
              <span class="menu-header-text" data-i18n="Account">Account</span>
            </li>
            <li class="menu-item">
              <a href="#membership" class="menu-link" data-spy-target="membership">
                <i class="menu-icon icon-base bx bx-user-circle"></i>
                <div data-i18n="My membership">My membership</div>
              </a>
            </li>
            <li class="menu-item">
              <a href="#questions" class="menu-link" data-spy-target="questions">
                <i class="menu-icon icon-base bx bx-help-circle"></i>
                <div data-i18n="Questions">Questions</div>
              </a>
            </li>
            <li class="menu-item">
              <a href="<?= e(url('register')) ?>" class="menu-link">
                <i class="menu-icon icon-base bx bx-user-plus"></i>
                <div data-i18n="Add member">Add member</div>
              </a>
            </li>
            <li class="menu-item">
              <form method="POST" action="<?= e(url('logout')) ?>" class="w-100">
                <?= csrf_field() ?>
                <button type="submit" class="menu-link w-100 border-0 bg-transparent">
                  <i class="menu-icon icon-base bx bx-power-off"></i>
                  <div data-i18n="Sign Out">Sign Out</div>
                </button>
              </form>
            </li>
          </ul>
        </aside>
        <!-- / Menu -->

        <!-- Layout container -->
        <div class="layout-page">
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
              <!-- Quick search -->
              <div class="navbar-nav align-items-center d-none d-md-flex me-xl-4">
                <div class="nav-item d-flex align-items-center">
                  <i class="icon-base bx bx-search icon-md"></i>
                  <input
                    type="search"
                    id="navbarQuickSearch"
                    class="form-control border-0 bg-transparent shadow-none ps-2"
                    placeholder="Search members…"
                    aria-label="Search members"
                  />
                </div>
              </div>
              <!-- / Quick search -->

              <ul class="navbar-nav flex-row align-items-center ms-md-auto">
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
                              <i class="icon-base bx <?= e($note['icon'] ?? 'bx-bell') ?>"></i>
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
                    <li class="border-top">
                      <div class="d-grid p-3">
                        <a class="btn btn-primary btn-sm" href="#community">View the community</a>
                      </div>
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
                      <a class="dropdown-item" href="#account">
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
                      <a class="dropdown-item" href="#membership">
                        <i class="icon-base bx bx-user icon-md me-3"></i
                        ><span>My membership</span>
                      </a>
                    </li>
                    <li>
                      <a class="dropdown-item" href="#questions">
                        <i class="icon-base bx bx-help-circle icon-md me-3"></i
                        ><span>Questions</span>
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
                          ><span>Log Out</span>
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

          <!-- Content wrapper -->
          <div class="content-wrapper">
