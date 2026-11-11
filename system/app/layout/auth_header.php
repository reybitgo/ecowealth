<?php
/** Shared header for the authentication (guest) pages — Sneat template. */
$site_name  = trim((string) setting('site_name', APP_NAME));
$brand_name = $site_name === '' ? APP_NAME : $site_name;
$page_title = $page_title ?? $brand_name . ' — Auth';
?>
<!doctype html>
<!-- beautify ignore:start -->
<html
  lang="en"
  class="layout-wide customizer-hide"
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
    <title><?= e($page_title) ?> | <?= e($brand_name) ?></title>
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
    <!-- build:css assets/vendor/css/theme.css  -->
    <link rel="stylesheet" href="<?= e(url('assets/vendor/libs/pickr/pickr-themes.css')) ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/core.css')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/demo.css')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>" />
    <!-- Vendors CSS -->
    <link
      rel="stylesheet"
      href="<?= e(url('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css')) ?>"
    />
    <!-- endbuild -->
    <!-- Vendor -->
    <link
      rel="stylesheet"
      href="<?= e(url('assets/vendor/libs/@form-validation/form-validation.css')) ?>"
    />
    <!-- Page CSS -->
    <!-- Page -->
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/pages/page-auth.css')) ?>" />
<?php foreach ((array) ($auth_page_css ?? []) as $auth_css): ?>
    <link rel="stylesheet" href="<?= e(asset_url((string) $auth_css)) ?>" />
<?php endforeach; ?>
<?php if (isset($auth_page_styles) && trim((string) $auth_page_styles) !== ''): ?>
    <style><?= (string) $auth_page_styles ?></style>
<?php endif; ?>
    <!-- Helpers -->
    <script src="<?= e(url('assets/vendor/js/helpers.js')) ?>"></script>
    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    <!--? Template customizer: To hide customizer set displayCustomizer value false in config.js.  -->
    <script src="<?= e(url('assets/vendor/js/template-customizer.js')) ?>"></script>
    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->
    <script src="<?= e(url('assets/js/config.js')) ?>"></script>
  </head>
  <body>
    <!-- Content -->
    <div class="container-xxl">
      <div class="authentication-wrapper authentication-basic container-p-y">
        <div class="authentication-inner<?= isset($auth_inner_class) && (string) $auth_inner_class !== '' ? ' ' . e((string) $auth_inner_class) : '' ?>">
