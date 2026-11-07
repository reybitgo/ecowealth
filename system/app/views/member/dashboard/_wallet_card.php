<?php
declare(strict_types=1);

/**
 * Member dashboard — the E-wallet card.
 *
 * A live region: every wallet movement bumps the per-member dashboard
 * surface, so both buckets and the spend-only note refresh in place.
 *
 * @var float $balance
 * @var float $withdrawable
 */

$lockedAmount = max(0.0, $balance - $withdrawable);
?>
                  <div class="card h-100" data-live-region="dashboard-wallet">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">E-wallet</h5>
                      <a href="<?= e(url('wallet')) ?>" class="btn btn-sm btn-label-primary">Statement</a>
                    </div>
                    <div class="card-body">
                      <div class="row g-3">
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Total balance</div>
                            <div class="status-tile-value"><?= e(money($balance)) ?></div>
                          </div>
                        </div>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Withdrawable</div>
                            <div class="status-tile-value"><?= e(money($withdrawable)) ?></div>
                          </div>
                        </div>
                      </div>

                      <div class="alert alert-secondary d-flex align-items-start gap-2 py-3 mt-3 mb-0" role="alert">
                        <i class="icon-base bx bx-info-circle fs-5"></i>
                        <div class="small">
                          <?= e(money($lockedAmount)) ?> of your balance is spend-only (admin top-ups). Commissions and
                          refunds stay withdrawable.
                        </div>
                      </div>
                    </div>
                  </div>
