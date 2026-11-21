<?php
declare(strict_types=1);

/**
 * Admin dashboard catalogue-value card.
 *
 * @var array<string, int|float> $stats
 * @var array{labels: list<string>, values: list<float>, stock: list<int>} $chart
 */

$stats = array_merge([
    'inventory_value' => 0.0,
    'stock_units'     => 0,
], $stats ?? []);

$chart = array_merge(['labels' => [], 'values' => [], 'stock' => []], $chart ?? []);

$topProduct = (string) ($chart['labels'][0] ?? '');
$topValue   = (float) ($chart['values'][0] ?? 0);
?>
                <div class="col-xl-8" data-live-region="dashboard-catalogue">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                      <div>
                        <h5 class="card-title mb-1">Catalogue value</h5>
                        <p class="text-body-secondary small mb-0">Retail value of the stock currently on hand.</p>
                      </div>
                      <span class="badge rounded-pill bg-label-primary">
                        <?= e(money((float) $stats['inventory_value'])) ?>
                      </span>
                    </div>
                    <div class="card-body pt-2">
                      <div id="catalogueValueChart" class="dashboard-chart"></div>

                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 border-top pt-4 mt-2">
                        <div class="d-flex align-items-center gap-3">
                          <span class="avatar avatar-sm">
                            <span class="avatar-initial rounded bg-label-primary">
                              <i class="icon-base bx bx-cube"></i>
                            </span>
                          </span>
                          <div>
                            <small class="text-body-secondary d-block">Units on hand</small>
                            <span class="fw-semibold"><?= number_format((int) $stats['stock_units']) ?></span>
                          </div>
                        </div>
                        <div class="text-end">
                          <small class="text-body-secondary d-block">Highest stock value</small>
                          <span class="fw-semibold">
                            <?= e($topProduct !== '' ? $topProduct : '—') ?> &middot; <?= e(money($topValue)) ?>
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
