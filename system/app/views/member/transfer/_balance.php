<?php
declare(strict_types=1);

/**
 * Send funds — the balance card (live region).
 *
 * Both buckets are shown because a transfer spends the non-withdrawable
 * part first: the sender should see exactly what will be spent and what
 * stays withdrawable for a payout.
 *
 * @var array<string, mixed> $limits
 */

$staff        = (bool) ($limits['staff'] ?? false);
$balance      = (float) ($limits['balance'] ?? 0);
$withdrawable = (float) ($limits['withdrawable'] ?? 0);
$locked       = (float) ($limits['non_withdrawable'] ?? 0);
$fee          = (float) ($limits['fee'] ?? 0);
?>
                <div class="card h-100 wallet-transfer-balance" data-live-region="member-transfer-balance">
                  <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                      <div>
                        <span class="text-body-secondary small d-block">Wallet balance</span>
                        <h2 class="mb-0 wallet-stat-value"><?= e(money($balance)) ?></h2>
                      </div>
                      <span class="avatar avatar-md">
                        <span class="avatar-initial rounded-circle bg-label-primary">
                          <i class="icon-base bx bx-wallet icon-md"></i>
                        </span>
                      </span>
                    </div>

                    <div class="d-flex flex-wrap gap-4 mb-3">
                      <div>
                        <span class="text-body-secondary small d-block">Withdrawable</span>
                        <span class="fw-semibold wallet-amount-positive"><?= e(money($withdrawable)) ?></span>
                      </div>
                      <div>
                        <span class="text-body-secondary small d-block">Non-withdrawable</span>
                        <span class="fw-semibold text-body-secondary"><?= e(money($locked)) ?></span>
                      </div>
                    </div>

                    <ul class="list-unstyled small text-body-secondary mb-0 transfer-facts">
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Transfer fee</span>
                        <span class="fw-medium text-body"><?= $staff ? 'Waived (staff)' : e($fee > 0 ? money($fee) : 'None') ?></span>
                      </li>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Minimum transfer</span>
                        <span class="fw-medium text-body"><?= e(money((float) ($limits['min'] ?? 0))) ?></span>
                      </li>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Most you can send now</span>
                        <span class="fw-medium text-body"><?= e(money((float) ($limits['max_send'] ?? 0))) ?></span>
                      </li>
                    </ul>
                  </div>
                </div>
