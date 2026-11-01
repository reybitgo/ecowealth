<?php
$name  = setting('site_name', APP_NAME);
$limit = (int) setting('seat_limit', '1000');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registration Closed — <?= e($name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --primary:#3b6ff0; --muted:#6b7a99; --bg:#f4f6fb; }
    * { box-sizing:border-box; }
    body {
      display:flex; align-items:center; justify-content:center;
      min-height:100vh; margin:0; background:var(--bg);
      font-family:'DM Sans',system-ui,sans-serif;
    }
    .box {
      text-align:center; padding:48px 40px; max-width:420px;
      background:#fff; border-radius:16px;
      box-shadow:0 8px 32px rgba(0,0,0,.06);
    }
    .emoji { font-size:3.5rem; margin-bottom:.5rem; }
    h1 { font-size:1.5rem; margin-bottom:.5rem; color:#1a1a2e; font-weight:700; }
    p { color:var(--muted); line-height:1.6; margin-bottom:1.75rem; font-size:.95rem; }
    .btn {
      display:inline-block; padding:.7rem 1.6rem; border-radius:10px;
      text-decoration:none; font-weight:600; font-size:.9rem; transition:opacity .2s;
    }
    .btn:hover { opacity:.9; }
    .btn-primary { background:var(--primary); color:#fff; }
    .btn-outline { background:transparent; color:var(--primary); border:1.5px solid var(--primary); margin-left:.5rem; }
  </style>
</head>
<body>
  <div class="box">
    <div class="emoji">🔒</div>
    <h1>Registration Closed</h1>
    <p>The member seat limit of <strong><?= number_format($limit) ?></strong> has been reached. No new accounts can be created at this time.</p>
    <a href="<?= APP_URL ?>/?page=login" class="btn btn-primary">Sign In →</a>
    <a href="<?= APP_URL ?>/" class="btn btn-outline">Home</a>
  </div>
</body>
</html>
