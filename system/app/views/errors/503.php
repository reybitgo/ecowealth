<?php
declare(strict_types=1);

/**
 * 503 — maintenance mode. Rendered standalone (no layout, no nav) by
 * Core/MaintenanceGate before routing, so it stays safe even while the
 * database or the session layer is being worked on. Admin and
 * superadmin sessions never see this page (P1-T11).
 */

$retry_after = '1 hour';
?>
<!doctype html>
<html lang="en" class="customizer-hide" data-skin="default" data-bs-theme="light" data-assets-path="<?= e(base_url() . 'assets/') ?>">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Back shortly | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(url('assets/img/favicon/favicon.png')) ?>" />
    <link rel="alternate icon" type="image/x-icon" href="<?= e(url('assets/img/favicon/favicon.ico')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/boxicons/boxicons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/core.css')) ?>" />
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/demo.css')) ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/pages/page-misc.css')) ?>" />
  </head>
  <body>
    <div class="container-xxl container-p-y">
      <div class="misc-wrapper text-center">
        <div class="app-brand justify-content-center mb-6">
          <span class="app-brand-link gap-2">
            <?php $brand_logo_height = 56; ?>
            <?php require dirname(__DIR__, 2) . '/layout/logo.php'; ?>
          </span>
        </div>
        <div class="avatar avatar-xl mx-auto mb-4">
          <span class="avatar-initial rounded-circle bg-label-warning">
            <i class="icon-base bx bx-time-five icon-lg"></i>
          </span>
        </div>
        <h1 class="mb-2" style="font-size: 4rem">503</h1>
        <h3 class="mb-2">Down for maintenance</h3>
        <p class="mb-2 text-body-secondary">
          <?= e(APP_NAME) ?> is being updated and will be back shortly.
        </p>
        <p class="mb-0 text-body-secondary small">
          Please try again in <?= e($retry_after) ?>. Administrators can sign in to keep working.
        </p>
        <div class="d-flex gap-2 justify-content-center flex-wrap mt-4">
          <a href="<?= e(url('login')) ?>" class="btn btn-primary">Administrator sign in</a>
        </div>
      </div>
    </div>
  </body>
</html>
