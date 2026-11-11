<?php
declare(strict_types=1);

/**
 * Admin dashboard — best sellers in the last 30 days (plan §7 Phase 15).
 *
 * @var array<string, mixed> $commerce
 */

$commerce = $commerce ?? [];
$products = (array) ($commerce['top_products'] ?? []);
?>
              <div class="card h-100" data-live-region="dashboard-top-products">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Top products · 30 days</h5>
                    <p class="text-body-secondary small mb-0">Units sold on paid orders</p>
                  </div>
                  <a href="<?= e(url('admin/reports')) ?>" class="btn btn-sm btn-label-secondary">Reports</a>
                </div>
                <div class="card-body">
<?php if ($products === []): ?>
                  <div class="text-center text-body-secondary py-6">
                    <i class="icon-base bx bx-package icon-xl d-block mb-2"></i>
                    No product has sold in the last 30 days.
                  </div>
<?php else: ?>
                  <ul class="list-unstyled mb-0 dashboard-top-list">
<?php foreach ($products as $index => $product): ?>
                    <li class="d-flex align-items-center gap-3 py-2 border-top">
                      <span class="badge rounded-circle bg-label-primary"><?= $index + 1 ?></span>
                      <div class="flex-grow-1">
                        <div class="fw-medium"><?= e((string) $product['name']) ?></div>
                        <small class="text-body-secondary">
                          <?= (int) $product['units'] ?> unit<?= (int) $product['units'] === 1 ? '' : 's' ?>
                        </small>
                      </div>
                      <span class="fw-semibold"><?= e(money($product['amount'])) ?></span>
                    </li>
<?php endforeach; ?>
                  </ul>
<?php endif; ?>
                </div>
              </div>
