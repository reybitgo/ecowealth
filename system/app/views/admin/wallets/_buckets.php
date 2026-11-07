<?php
declare(strict_types=1);

/**
 * Admin wallet page — the two buckets at a glance.
 *
 * @var array<string, mixed> $member
 */

$total        = round((float) $member['ewallet_balance'], 2);
$withdrawable = round((float) $member['withdrawable_balance'], 2);
$locked       = round($total - $withdrawable, 2);
?>
              <div class="row g-6 mb-6" data-live-region="wallet-buckets">
                <div class="col-12 col-md-4">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-primary"><i class="icon-base bx bx-wallet"></i></span>
                        </span>
                        <span class="text-body-secondary small">Total balance</span>
                      </div>
                      <h4 class="mb-0"><?= e(money($total)) ?></h4>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-md-4">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-success"><i class="icon-base bx bx-money-withdraw"></i></span>
                        </span>
                        <span class="text-body-secondary small">Withdrawable</span>
                      </div>
                      <h4 class="mb-0"><?= e(money($withdrawable)) ?></h4>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-md-4">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-warning"><i class="icon-base bx bx-lock-alt"></i></span>
                        </span>
                        <span class="text-body-secondary small">Non-withdrawable</span>
                      </div>
                      <h4 class="mb-0"><?= e(money($locked)) ?></h4>
                    </div>
                  </div>
                </div>
              </div>
