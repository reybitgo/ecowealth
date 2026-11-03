<?php
declare(strict_types=1);

/**
 * Shop placeholder — the guarded storefront shell (Phase 1).
 * The catalogue, product detail, cart badge and checkout replace this
 * view from Phase 2 onwards (plan §7 Phase 2–6).
 *
 * @var bool $shop_open
 */

$shop_open = $shop_open ?? true;

$features = [
    ['icon' => 'bx-store', 'title' => 'Browse the catalogue', 'text' => 'Products with live stock badges and search.', 'phase' => 2],
    ['icon' => 'bx-cart', 'title' => 'Add to cart', 'text' => 'Adjust quantities against real availability.', 'phase' => 4],
    ['icon' => 'bx-credit-card', 'title' => 'Checkout', 'text' => 'Saved addresses, e-wallet or manual payment.', 'phase' => 6],
    ['icon' => 'bx-package', 'title' => 'Track your orders', 'text' => 'Payment proof, shipment tracking and invoices.', 'phase' => 6],
];

$me = auth_user();
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="row g-6">
                <div class="col-12">
                  <div class="card">
                    <div class="card-body">
                      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                        <div>
                          <span class="badge bg-label-primary mb-3">Phase 1 · core skeleton</span>
                          <h4 class="mb-2">The shop is being prepared</h4>
                          <p class="text-body-secondary mb-0" style="max-width: 46rem">
                            You are signed in as <strong><?= e($me['username'] ?? 'member') ?></strong> and
                            the member area is guarded, routed and themed. The storefront itself —
                            catalogue, cart, checkout and orders — arrives in the next phases.
                          </p>
                        </div>
                        <div class="text-end">
                          <a href="<?= e(url('dashboard')) ?>" class="btn btn-label-primary">
                            <i class="icon-base bx bx-home-smile me-1"></i> Back to dashboard
                          </a>
                        </div>
                      </div>

<?php if (!$shop_open): ?>
                      <div class="alert alert-warning d-flex align-items-center gap-2 mt-6 mb-0" role="alert">
                        <i class="icon-base bx bx-error fs-4"></i>
                        <span>The shop is currently switched off by the administrator.</span>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
                </div>

<?php foreach ($features as $feature): ?>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="avatar mb-4">
                        <span class="avatar-initial rounded bg-label-secondary">
                          <i class="icon-base <?= e($feature['icon']) ?> icon-md"></i>
                        </span>
                      </div>
                      <div class="d-flex align-items-center gap-2 mb-2">
                        <h6 class="mb-0"><?= e($feature['title']) ?></h6>
                        <span class="badge rounded-pill bg-label-secondary">Phase <?= (int) $feature['phase'] ?></span>
                      </div>
                      <p class="text-body-secondary small mb-0"><?= e($feature['text']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
            </div>
