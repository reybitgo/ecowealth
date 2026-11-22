<?php
declare(strict_types=1);

/**
 * Member Binary bonus tab — the Package activations card (23E-S1,
 * plan §12.11, Appendix C.4).
 *
 * Downward-only copy: names the member's own package and their own
 * package outcome, never who earned from their activation. No upline,
 * no sponsor, no "Team chain", no level words.
 *
 * @var array{
 *   has_package:bool, package_name:?string, binary_enabled:bool,
 *   binary_points:int, qualification:bool, qualification_reason:?string,
 *   pairs_earned:int, money_earned:float,
 *   last_activation:?array{id:int, points:int, total:float, processed_at:?string}
 * } $package_card
 */

$card   = $package_card ?? [];
$has    = (bool) ($card['has_package'] ?? false);
$name   = (string) ($card['package_name'] ?? '');
$points = (int) ($card['binary_points'] ?? 0);
$qual   = (bool) ($card['qualification'] ?? false);
$pairs  = (int) ($card['pairs_earned'] ?? 0);
$money  = round((float) ($card['money_earned'] ?? 0), 2);
$last   = $card['last_activation'] ?? null;
?>

<div class="row g-4 mb-4" data-live-region="earnings-binary-package">
<?php if (!$has || !$card['binary_enabled']): ?>
  <?php if (!$has): ?>
    <div class="col-12">
      <div class="card h-100">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div>
            <h5 class="card-title mb-0">Package activations</h5>
          </div>
        </div>
        <div class="card-body text-center py-4">
          <i class="icon-base bx bx-package text-body-muted mb-3" style="font-size: 2rem;"></i>
          <h6 class="mb-2">No package on your account</h6>
          <p class="text-body-secondary small mb-0">
            Activate a package to start placing points in the binary tree.
          </p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="col-12">
      <div class="card h-100">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div>
            <h5 class="card-title mb-0">Package activations</h5>
          </div>
        </div>
        <div class="card-body text-center py-4">
          <i class="icon-base bx bx-package text-body-muted mb-3" style="font-size: 2rem;"></i>
          <h6 class="mb-2"><?= e($name) ?></h6>
          <p class="text-body-secondary small mb-0">
            Your package is not in the binary: your activations place no points
            and package activations do not pay you package pairs.
          </p>
        </div>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <div class="col-12">
    <div class="card h-100">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
          <h5 class="card-title mb-0">Package activations</h5>
        </div>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-start gap-3 mb-3">
          <div class="avatar avatar-lg">
            <span class="avatar-initial rounded-circle bg-label-primary fw-semibold fs-4">
              <?= e(initials($name)) ?>
            </span>
          </div>
          <div>
            <h6 class="mb-1"><?= e($name) ?></h6>
            <div class="text-body-secondary small">
              <?= e((string) $points) ?> pts per activation
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-6 col-lg-4">
            <div class="small text-body-secondary">Package pairs earned</div>
            <div class="h5 mb-0 commission-num"><?= e((string) $pairs) ?></div>
            <div class="small text-body-secondary"><?= e(money($money)) ?> credited</div>
          </div>
          <div class="col-6 col-lg-4">
            <div class="small text-body-secondary">Qualification</div>
            <div class="h5 mb-0 <?= $qual ? 'text-success' : 'text-body-secondary' ?>">
              <?= $qual ? 'Qualified' : 'Not qualified' ?>
            </div>
            <div class="small text-body-secondary">
              <?= $qual
                ? 'You hold a binary package: your activations place points and your team’s activations can pay you package pairs.'
                : (($card['qualification_reason'] ?? '') !== '' ? e($card['qualification_reason']) : 'Package pairs are paid to members with a binary package.') ?>
            </div>
          </div>
          <div class="col-6 col-lg-4">
            <div class="small text-body-secondary">Your last activation</div>
            <div class="h5 mb-0">
              <?= $last !== null ? e((string) $last['points']) . ' pts' : '—' ?>
            </div>
            <div class="small text-body-secondary">
              <?= $last !== null ? 'placed ' . format_date($last['processed_at']) : 'No binary activity yet' ?>
            </div>
          </div>
        </div>

        <?php if ($last !== null): ?>
        <div class="mt-3 p-3 bg-light rounded">
          <div class="small text-body-secondary">Your activation placed <strong><?= e((string) $last['points']) ?></strong> pts in your tree.</div>
          <div class="small text-body-secondary mt-1">
            Package pairs paid: <strong><?= e((string) $pairs) ?></strong> · <?= e(money($money)) ?> credited to your e-wallet.
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>
