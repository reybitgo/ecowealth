<?php
declare(strict_types=1);

/**
 * Member dashboard — the E-wallet KPI card.
 *
 * Rendered inside the `dashboard-kpis` region: an admin top-up, a
 * commission or any purchase refreshes the total and the withdrawable
 * line together with the rest of the row.
 *
 * @var float $balance
 * @var float $withdrawable
 */
?>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-success">
                            <i class="icon-base <?= e(icon_class('bx-wallet')) ?>"></i>
                          </span>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1 small">E-wallet</p>
                      <h4 class="mb-1 text-truncate"><?= e(money($balance)) ?></h4>
                      <small class="text-body-secondary d-block"><?= e(money($withdrawable)) ?> withdrawable</small>
                    </div>
                  </div>
                </div>
