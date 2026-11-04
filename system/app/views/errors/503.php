<?php
declare(strict_types=1);

/**
 * 503 — maintenance mode. Rendered standalone (no app layout, no topbar) by
 * Core/MaintenanceGate before routing, so it stays safe even while the
 * database or the session layer is being worked on. Admin and superadmin
 * sessions never see this page (P1-T11).
 */

$retry_after = 'about an hour';

$code            = 503;
$heading         = 'Down for maintenance';
$message         = APP_NAME . ' is being updated and will be back shortly.';
$support         = 'Please try again in ' . $retry_after . '. Administrators can still sign in.';
$primary_url     = url('login');
$primary_label   = 'Administrator sign in';
$secondary_url   = '';
$secondary_label = '';
?>
<!doctype html>
<html
  lang="en"
  class="customizer-hide"
  data-skin="default"
  data-bs-theme="light"
  data-assets-path="<?= e(base_url() . 'assets/') ?>"
>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Back shortly | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(url('assets/img/favicon/favicon.png')) ?>" />
    <link rel="alternate icon" type="image/x-icon" href="<?= e(url('assets/img/favicon/favicon.ico')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/core.css')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/demo.css')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/errors.css')) ?>" />
  </head>
  <body class="error-page">
<?php require __DIR__ . '/../partials/error_panel.php'; ?>
    <footer class="error-footer">
      &#169; <?= date('Y') ?> <?= e(APP_NAME) ?> &mdash; <?= e(APP_TAGLINE) ?>.
    </footer>
  </body>
</html>
