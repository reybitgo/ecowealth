<?php
declare(strict_types=1);

/**
 * Admin member page — wallet and activity summary card.
 *
 * Phase 18 adds the pending repeat-purchase line: the amount is earned
 * but not in the wallet, so it sits outside the balance/withdrawable
 * tiles and is never added to either figure (plan §19.5).
 *
 * @var array<string, mixed> $member
 * @var float $balance
 * @var float $withdrawable
 * @var array{total:int, open:int, completed:int, spent:float} $orders
 * @var array{count:int, credited:float, this_month:float, last_at:?string} $commissions
 * @var array{total:float, count:int, next_release_at:?string, undelivered:int, overdue:int, release_enabled:bool} $pending_repeat
 * @var list<array<string, mixed>> $activations
 * @var string $statement_url
 */

$pending = array_merge([
    'total'           => 0.0,
    'count'           => 0,
    'next_release_at' => null,
    'undelivered'     => 0,
    'overdue'         => 0,
    'release_enabled' => true,
], $pending_repeat ?? UnilevelAward::pendingSummaryForUser((int) ($member['id'] ?? 0)));

$pendingTotal = round((float) $pending['total'], 2);
$pendingCount = (int) $pending['count'];
$pendingDue   = $pending['next_release_at'] === null ? null : (string) $pending['next_release_at'];
?>
                  <div class="card h-100" data-live-region="member-wallet">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">Wallet</h5>
                      <a href="<?= e($statement_url) ?>" class="btn btn-sm btn-label-primary">Open</a>
                    </div>
                    <div class="card-body">
                      <div class="row g-3">
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Balance</div>
                            <div class="status-tile-value"><?= e(money($balance)) ?></div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Withdrawable</div>
                            <div class="status-tile-value"><?= e(money($withdrawable)) ?></div>
                          </div>
                        </div>
                      </div>

<?php if ($pendingTotal > 0.0): ?>
                      <div class="d-flex align-items-start justify-content-between gap-2 small py-1 mt-2">
                        <div>
                          <span class="text-body-secondary">Pending repeat-purchase</span>
                          <div class="text-body-secondary">Not in the wallet balance</div>
                        </div>
                        <div class="text-end">
                          <strong><?= e(money($pendingTotal)) ?></strong>
                          <div class="text-body-secondary">
                            <?= $pendingCount ?> award<?= $pendingCount === 1 ? '' : 's' ?><?php if ($pendingDue !== null): ?>
                              · next <?= e(format_date($pendingDue)) ?>
<?php endif; ?>
                          </div>
                        </div>
                      </div>
<?php endif; ?>

                      <hr class="my-3" />

                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Orders placed</span>
                        <strong><?= (int) $orders['total'] ?></strong>
                      </div>
                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Paid to the shop</span>
                        <strong><?= e(money($orders['spent'])) ?></strong>
                      </div>
                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Commission credited</span>
                        <strong><?= e(money($commissions['credited'])) ?></strong>
                      </div>
                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Commission awards</span>
                        <a href="<?= e(url('admin/commissions?q=' . rawurlencode((string) $member['username']))) ?>" class="text-body">
                          <?= (int) $commissions['count'] ?> <i class="icon-base bx bx-link-external"></i>
                        </a>
                      </div>
                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Activation requests</span>
                        <strong><?= count($activations) ?></strong>
                      </div>
                    </div>
                  </div>
