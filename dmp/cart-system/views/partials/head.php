<?php

/**
 * @file   views/partials/head.php
 * @brief  Head for member pages
 */
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle ?? setting('site_name', APP_NAME)) ?></title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="<?= APP_URL ?>/assets/img/favicon.png?v=<?= filemtime(__DIR__ . '/../../assets/img/favicon.png') ?>" type="image/png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= APP_URL ?>/assets/img/apple-touch-icon.png?v=<?= filemtime(__DIR__ . '/../../assets/img/apple-touch-icon.png') ?>">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <!-- App CSS (cache-busted by file modification time) -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= filemtime(__DIR__ . '/../../assets/css/main.css') ?>">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/layout.css?v=<?= filemtime(__DIR__ . '/../../assets/css/layout.css') ?>">
  <?php if (!empty($pageTitle) && $pageTitle === 'Shop'): ?>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/shop.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shop.css') ?>">
  <?php endif; ?>
</head>

<body>