<?php
declare(strict_types=1);

/**
 * Reports — Product binary readiness card (Phase 22E, plan Rule 10).
 *
 * Read-only counts with links: products flagged with no P.V., products
 * with the switch off, members without a node, and whether the global
 * master is on. Nothing here changes a setting.
 *
 * @var array{
 *   stream_on:bool, products_on_no_points:int, products_off:int,
 *   members_unplaced:int, members_total:int
 * } $binary_readiness
 */

$ready = array_merge([
    'stream_on' => false, 'products_on_no_points' => 0, 'products_off' => 0,
    'members_unplaced' => 0, 'members_total' => 0,
], $binary_readiness ?? []);
?>
              <div class="card h-100 report-binary-readiness-card" data-live-region="report-binary-readiness">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Product binary readiness</h5>
                    <p class="text-body-secondary small mb-0">
                      Read-only counts — what is still unconfigured before the stream can pay.
                    </p>
                  </div>
                  <span class="badge rounded-pill bg-label-<?= $ready['stream_on'] ? 'success' : 'secondary' ?>">
                    <?= $ready['stream_on'] ? 'Stream on' : 'Stream off' ?>
                  </span>
                </div>
                <div class="card-body">
                  <div class="row g-3">
                    <div class="col-6 col-md-4">
                      <div class="text-body-secondary small">Products flagged, no points</div>
                      <div class="fw-semibold"><?= (int) $ready['products_on_no_points'] ?></div>
                      <div class="small text-body-secondary">
                        <code>binary_enabled = 1 AND pv = 0</code>
                      </div>
                    </div>
                    <div class="col-6 col-md-4">
                      <div class="text-body-secondary small">Products switched off</div>
                      <div class="fw-semibold"><?= (int) $ready['products_off'] ?></div>
                      <div class="small text-body-secondary">
                        <code>binary_enabled = 0</code>
                      </div>
                    </div>
                    <div class="col-6 col-md-4">
                      <div class="text-body-secondary small">Members unplaced</div>
                      <div class="fw-semibold"><?= (int) $ready['members_unplaced'] ?></div>
                      <div class="small text-body-secondary">
                        of <?= (int) $ready['members_total'] ?> members
                      </div>
                    </div>
                  </div>
                  <div class="alert alert-<?= $ready['stream_on'] ? 'info' : 'warning' ?> small mt-3 mb-0" role="status">
                    <strong>Stream:</strong> <?= $ready['stream_on'] ? 'on' : 'off' ?>.
                    <?php if ($ready['stream_on']): ?>
                      Awards flow once a product is flagged with a P.V. and an order is paid and placed.
                    <?php else: ?>
                      Turn the master on in Settings and flag a product with a P.V. before any order pays.
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card-footer small text-body-secondary">
                  Counts come straight from SQL. Links open the filtered product list; nothing here is a button
                  that changes a setting (plan Rule 10).
                </div>
              </div>
