<?php
declare(strict_types=1);

/**
 * Admin dashboard stock-health card.
 *
 * @var array<string, int|float> $stats
 * @var array{healthy:int, low:int, out:int} $stock
 * @var list<array<string, mixed>> $attention
 */

$stats = array_merge(['products' => 0], $stats ?? []);
$stock = array_merge(['healthy' => 0, 'low' => 0, 'out' => 0], $stock ?? []);
$attention = $attention ?? [];

$stockRows = [
    ['key' => 'healthy', 'label' => 'Healthy',     'tone' => 'success', 'hint' => '30+ units',  'count' => (int) $stock['healthy']],
    ['key' => 'low',     'label' => 'Low stock',   'tone' => 'warning', 'hint' => '1–29 units', 'count' => (int) $stock['low']],
    ['key' => 'out',     'label' => 'Out of stock', 'tone' => 'danger',  'hint' => '0 units',    'count' => (int) $stock['out']],
];
?>
                <div class="col-xl-4" data-live-region="dashboard-stock">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-1">Stock health</h5>
                      <p class="text-body-secondary small mb-0"><?= (int) $stats['products'] ?> products in the catalogue.</p>
                    </div>
                    <div class="card-body pt-2">
                      <div id="stockHealthChart" class="dashboard-chart-sm mx-auto" style="max-width: 15rem"></div>

                      <ul class="list-unstyled mb-0 mt-4">
<?php foreach ($stockRows as $row): ?>
                        <li class="d-flex align-items-center justify-content-between py-2">
                          <span class="d-flex align-items-center gap-2">
                            <span
                              class="rounded-circle d-inline-block"
                              style="width: .5rem; height: .5rem; background-color: var(--bs-<?= e($row['tone']) ?>)"
                            ></span>
                            <span class="fw-medium"><?= e($row['label']) ?></span>
                            <small class="text-body-secondary"><?= e($row['hint']) ?></small>
                          </span>
                          <span class="fw-semibold"><?= (int) $row['count'] ?></span>
                        </li>
<?php endforeach; ?>
                      </ul>

<?php if ($attention !== []): ?>
                      <hr class="my-4" />
                      <h6 class="mb-3">Needs attention</h6>
                      <div class="d-flex flex-column gap-1">
<?php foreach ($attention as $item): ?>
<?php $units = (int) ($item['stock'] ?? 0); ?>
                        <a
                          href="<?= e(url('admin/products/' . (int) $item['id'] . '/edit')) ?>"
                          class="d-flex align-items-center gap-2 py-1 text-reset text-decoration-none"
                        >
                          <span class="badge rounded-pill bg-label-<?= $units === 0 ? 'danger' : 'warning' ?>">
                            <i class="icon-base <?= $units === 0 ? 'bx bx-x-circle' : 'bx bx-error' ?>"></i>
                          </span>
                          <span class="flex-grow-1 small text-truncate"><?= e((string) $item['name']) ?></span>
                          <small class="text-body-secondary text-nowrap"><?= $units ?> left</small>
                        </a>
<?php endforeach; ?>
                      </div>
<?php else: ?>
                      <div class="alert alert-success d-flex align-items-center gap-2 mb-0 mt-4 py-2" role="status">
                        <i class="icon-base bx bx-check-circle"></i>
                        <span class="small">Every product is well stocked.</span>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
