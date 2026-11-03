<?php
declare(strict_types=1);

/**
 * 500 — unexpected failure. Technical detail is logged, never shown,
 * unless APP_DEBUG is on (plan §3.7).
 *
 * @var int     $code
 * @var string  $heading
 * @var string  $message
 * @var ?string $detail
 */

$detail     = $detail ?? null;
$home_url   = auth_check() ? url('dashboard') : url('login');
$home_label = auth_check() ? 'Back to dashboard' : 'Sign in';
?>
            <div class="container-xxl container-p-y">
              <div class="misc-wrapper text-center">
                <div class="avatar avatar-xl mx-auto mb-4">
                  <span class="avatar-initial rounded-circle bg-label-danger">
                    <i class="icon-base bx bx-error-circle icon-lg"></i>
                  </span>
                </div>
                <h1 class="mb-2" style="font-size: 4rem"><?= (int) $code ?></h1>
                <h3 class="mb-2"><?= e($heading) ?></h3>
                <p class="mb-4 text-body-secondary"><?= e($message) ?></p>
<?php if (APP_DEBUG && is_string($detail) && $detail !== ''): ?>
                <div class="text-start mx-auto mb-4" style="max-width: 52rem">
                  <div class="alert alert-warning mb-0">
                    <div class="fw-semibold mb-1">Debug detail (APP_DEBUG is on)</div>
                    <pre class="mb-0 small" style="white-space: pre-wrap; word-break: break-word"><?= e($detail) ?></pre>
                  </div>
                </div>
<?php endif; ?>
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                  <a href="<?= e($home_url) ?>" class="btn btn-primary"><?= e($home_label) ?></a>
                  <a href="<?= e(url('/')) ?>" class="btn btn-label-secondary">Home</a>
                </div>
              </div>
            </div>
