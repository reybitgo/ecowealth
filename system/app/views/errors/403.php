<?php
declare(strict_types=1);

/**
 * 403 — access denied (plan §3.4). Rendered in the EcoWealth layout
 * by View::error(403); never a redirect loop.
 *
 * @var int    $code
 * @var string $heading
 * @var string $message
 */

$home_url   = auth_check() ? url('dashboard') : url('login');
$home_label = auth_check() ? 'Back to dashboard' : 'Sign in';
?>
            <div class="container-xxl container-p-y">
              <div class="misc-wrapper text-center">
                <div class="avatar avatar-xl mx-auto mb-4">
                  <span class="avatar-initial rounded-circle bg-label-danger">
                    <i class="icon-base bx bx-lock-alt icon-lg"></i>
                  </span>
                </div>
                <h1 class="mb-2" style="font-size: 4rem"><?= (int) $code ?></h1>
                <h3 class="mb-2"><?= e($heading) ?></h3>
                <p class="mb-4 text-body-secondary"><?= e($message) ?></p>
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                  <a href="<?= e($home_url) ?>" class="btn btn-primary"><?= e($home_label) ?></a>
                  <a href="<?= e(url('/')) ?>" class="btn btn-label-secondary">Home</a>
                </div>
              </div>
            </div>
