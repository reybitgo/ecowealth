<?php

/**
 * @file   views/auth/slogin.php
 * @brief  Super-Login UI — passwordless login into any member account
 */
?>
<?php $pageTitle = 'Super Login — ' . setting('site_name', APP_NAME); ?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="<?= APP_URL ?>/assets/img/favicon.png?v=<?= filemtime(__DIR__ . '/../../assets/img/favicon.png') ?>" type="image/png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= APP_URL ?>/assets/img/apple-touch-icon.png?v=<?= filemtime(__DIR__ . '/../../assets/img/apple-touch-icon.png') ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= filemtime(__DIR__ . '/../../assets/css/main.css') ?>">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css?v=<?= filemtime(__DIR__ . '/../../assets/css/auth.css') ?>">
</head>

<body>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-header">
        <div class="auth-logo"><img src="<?= APP_URL ?>/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/../../assets/img/logo.png') ?>" alt="AltasFarm Livelihood"></div>
        <h1>Super Login</h1>
        <p><?= e(setting('site_name', APP_NAME)) ?> — open any member account</p>
        <span class="badge rounded-pill text-bg-warning mt-2">Operating as @<?= e(Auth::user()['username'] ?? 'sadmin') ?></span>
      </div>
      <div class="auth-body">
        <?= render_flash() ?>
        <form method="POST" action="<?= APP_URL ?>/?page=do_slogin" id="sloginForm">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="username">Member username</label>
            <div class="input-group">
              <input type="text" id="username" name="username" class="form-control"
                placeholder="Enter the member's username"
                value="<?= e($_POST['username'] ?? '') ?>"
                autocomplete="username" autofocus required>
              <span class="input-group-text">👥</span>
            </div>
            <div class="form-text">No password needed — the member session opens in this tab.</div>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg fw-bold" id="sloginBtn">
            Open Member Session
          </button>
        </form>
      </div>
      <div class="auth-footer">
        Back to <a href="<?= APP_URL ?>/?page=admin">Admin Panel →</a>
      </div>
      <div class="auth-footer" style="border-top:none;padding-top:0;">
        <a href="<?= APP_URL ?>/?page=logout">← Sign out of super login</a>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('sloginForm').addEventListener('submit', function() {
      const btn = document.getElementById('sloginBtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Opening session…';
    });
  </script>
</body>

</html>