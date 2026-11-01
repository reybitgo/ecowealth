<?php
declare(strict_types=1);

/* 404 — styled like the Sneat "misc error" page. */
if (!function_exists('url')) {
    require __DIR__ . '/../app/helpers.php';
}
$page_title = 'Page Not Found';
?>
<!doctype html>
<html lang="en" class="customizer-hide" data-assets-path="<?= e(base_url() . 'assets/') ?>">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Page Not Found | <?= e(defined('APP_NAME') ? APP_NAME : 'Sneat') ?></title>
    <link rel="icon" type="image/x-icon" href="<?= e(url('assets/img/favicon/favicon.ico')) ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/boxicons/boxicons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/core.css')) ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/css/demo.css')) ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/vendor/css/pages/page-misc.css')) ?>" />
  </head>
  <body>
    <div class="container-xxl container-p-y">
      <div class="misc-wrapper">
        <h1 class="mb-2 mx-auto" style="font-size: 5rem">404</h1>
        <p class="mb-4 mx-auto">The page you were looking for could not be found &#x1f62b;</p>
        <a href="<?= e(url('/')) ?>" class="btn btn-primary">Back to home</a>
      </div>
    </div>
  </body>
</html>
