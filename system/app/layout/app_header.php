<?php
/** Shared header for authenticated (app) pages — Sneat vertical layout. */

/** @var array $user Currently authenticated user (set by dashboard.php) */

$page_title = $page_title ?? APP_NAME;
$me         = $user;
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
      type="image/x-icon"
      href="<?= e(url('assets/img/favicon/favicon.ico')) ?>"
    />
    <link rel="stylesheet" href="<?= e(url('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/fonts/iconify-icons.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/boxicons/boxicons.min.css')) ?>">
    <!-- Core CSS -->
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/core.css')) ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/css/demo.css')) ?>" />
    <!-- Vendors CSS -->
    <link rel="stylesheet" href="<?= e(url('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css')) ?>" />
    <!-- Page CSS -->
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
              <span class="app-brand-text demo menu-text fw-bold ms-2">sneat</span>
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
            <!-- Dashboard -->
            <li class="menu-item active">
              <a href="<?= e(url('dashboard')) ?>" class="menu-link">
                <i class="menu-icon icon-base bx bx-home-smile"></i>
                <div data-i18n="Dashboard">Dashboard</div>
              </a>
            </li>
            <!-- Account -->
            <li class="menu-item">
              <a href="<?= e(url('dashboard')) ?>#account" class="menu-link">
                <i class="menu-icon icon-base bx bx-user"></i>
                <div data-i18n="My Account">My Account</div>
              </a>
            </li>
            <!-- Sign out -->
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
              <ul class="navbar-nav flex-row align-items-center ms-md-auto">
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
                      <a class="dropdown-item" href="<?= e(url('dashboard')) ?>#account">
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
                      <a class="dropdown-item" href="<?= e(url('dashboard')) ?>#account">
                        <i class="icon-base bx bx-user icon-md me-3"></i
                        ><span>My Profile</span>
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
