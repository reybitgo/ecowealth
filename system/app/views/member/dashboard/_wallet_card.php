<?php
declare(strict_types=1);

/**
 * Member dashboard — the E-wallet card.
 *
 * A live region: every wallet movement bumps the per-member dashboard
 * surface, so both buckets and the spend-only note refresh in place.
 *
 * Phase 18 adds the escrow tiles (plan §10.2): repeat-purchase money
 * pending release is shown beside the buckets, together with the
 * presentation-only "Total funds" sum. It is neither spendable nor
 * withdrawable, so it never changes the balance or the withdrawable
 * figure above it (ESC-09).
 *
 * `$pending_repeat` is the controller's summary. `member/dashboard.php`
 * (outside this work package) does not pass it into the capture yet, so
 * the partial falls back to the same per-member read when it is missing
 * — the page and the live region then show the same tiles.
 *
 * @var float $balance
 * @var float $withdrawable
 * @var array{total:float, count:int, next_release_at:?string, undelivered:int, overdue:int, release_enabled:bool} $pending_repeat
 */

$lockedAmount = max(0.0, $balance - $withdrawable);

$owner = auth_user();
$owner = is_array($owner) ? (int) ($owner['id'] ?? 0) : 0;

$pending      = $pending_repeat ?? UnilevelAward::pendingSummaryForUser($owner);
$pending      = is_array($pending) ? $pending : [];
$pendingTotal = round((float) ($pending['total'] ?? 0), 2);
$pendingNext  = $pending['next_release_at'] ?? null;
$pendingShown = $pendingTotal > 0;

/* When the next release is known it is named; otherwise the copy stays
 * at "after the return window" — never a date the system has not
 * promised (plan §10.6). */
$pendingWhen = $pendingNext !== null && trim((string) $pendingNext) !== ''
    ? 'next release ' . format_date((string) $pendingNext)
    : 'released after the return window';
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
<?php if ($pendingShown): ?>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Pending release</div>
                            <div class="status-tile-value text-info"><?= e(money($pendingTotal)) ?></div>
                          </div>
                        </div>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Total funds</div>
                            <div class="status-tile-value"><?= e(money(round($balance + $pendingTotal, 2))) ?></div>
                          </div>
                        </div>
<?php endif; ?>
                      </div>

                      <div class="alert alert-secondary d-flex align-items-start gap-2 py-3 mt-3 mb-0" role="alert">
                        <i class="icon-base bx bx-info-circle fs-5"></i>
                        <div class="small">
                          <?= e(money($lockedAmount)) ?> of your balance is spend-only (admin top-ups). Commissions and
                          refunds stay withdrawable.
<?php if ($pendingShown): ?>
                          <span class="d-block mt-1">
                            <strong><?= e(money($pendingTotal)) ?> pending release</strong> is repeat-purchase bonus
                            that is not withdrawable yet — <?= e($pendingWhen) ?>. It becomes spendable and
                            withdrawable only when it is released.
                          </span>
<?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
