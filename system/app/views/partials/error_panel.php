<?php
declare(strict_types=1);

/**
 * Shared branded error panel for the plain layout (403 / 404 / 500 / 503).
 *
 * There is no topbar on error pages: the EcoWealth wordmark sits at the
 * top of the panel, followed by the status code, explanation and actions.
 *
 * @var int     $code
 * @var string  $heading
 * @var string  $message
 * @var string  $support
 * @var ?string $detail
 * @var string  $primary_url
 * @var string  $primary_label
 * @var string  $secondary_url   Empty string hides the secondary action
 * @var string  $secondary_label Empty string hides the secondary action
 */

$code    = (int) ($code ?? 500);
$heading = (string) ($heading ?? 'Something went wrong');
$message = (string) ($message ?? '');
$support = (string) ($support ?? '');
$detail  = $detail ?? null;

$isSignedIn      = auth_check();
$primary_url     = (string) ($primary_url ?? ($isSignedIn ? url('dashboard') : url('login')));
$primary_label   = (string) ($primary_label ?? ($isSignedIn ? 'Back to dashboard' : 'Sign in'));
$secondary_url   = (string) ($secondary_url ?? url('/'));
$secondary_label = (string) ($secondary_label ?? 'Home');

$brand_logo_height = 48;
$brand_mark        = false;
?>
<div class="error-shell">
  <div class="error-panel">
    <a href="<?= e(url('/')) ?>" class="error-brand" aria-label="<?= e(APP_NAME) ?> home">
      <?php require dirname(__DIR__, 2) . '/layout/logo.php'; ?>
    </a>

    <div class="error-code" aria-hidden="true"><?= $code ?></div>
    <h1 class="error-title"><?= e($heading) ?></h1>
    <p class="error-message"><?= e($message) ?></p>

<?php if ($support !== ''): ?>
    <p class="error-support"><?= e($support) ?></p>
<?php endif; ?>

    <div class="error-actions">
      <a href="<?= e($primary_url) ?>" class="btn btn-primary"><?= e($primary_label) ?></a>
<?php if ($secondary_url !== '' && $secondary_label !== ''): ?>
      <a href="<?= e($secondary_url) ?>" class="btn btn-label-secondary"><?= e($secondary_label) ?></a>
<?php endif; ?>
    </div>

<?php if (APP_DEBUG && is_string($detail) && $detail !== ''): ?>
    <div class="error-debug">
      <div class="alert alert-warning mb-0">
        <div class="fw-semibold mb-1">Debug detail (APP_DEBUG is on)</div>
        <pre class="mb-0 small" style="white-space: pre-wrap; word-break: break-word"><?= e($detail) ?></pre>
      </div>
    </div>
<?php endif; ?>
  </div>
</div>
