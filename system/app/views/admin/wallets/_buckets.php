<?php
declare(strict_types=1);

/**
 * Admin wallet page — the two buckets at a glance, plus the escrow line.
 *
 * Phase 18: pending repeat-purchase bonuses are earned but not in
 * `ewallet_balance`, so they are shown *outside* the bucket cards and
 * never added to `Total balance` (plan §19.4).
 *
 * @var array<string, mixed> $member
 * @var array{
 *   total:float, count:int, next_release_at:?string,
 *   undelivered:int, overdue:int, release_enabled:bool
 * } $pending_repeat
 */

$total        = round((float) $member['ewallet_balance'], 2);
$withdrawable = round((float) $member['withdrawable_balance'], 2);
$locked       = round($total - $withdrawable, 2);

$pending = array_merge([
    'total'           => 0.0,
    'count'           => 0,
    'next_release_at' => null,
    'undelivered'     => 0,
    'overdue'         => 0,
    'release_enabled' => true,
], $pending_repeat ?? UnilevelAward::pendingSummaryForUser((int) ($member['id'] ?? 0)));

$pendingTotal  = round((float) $pending['total'], 2);
$pendingCount  = (int) $pending['count'];
$pendingDue    = $pending['next_release_at'] === null ? null : (string) $pending['next_release_at'];
$pendingAwards = $pendingCount . ' award' . ($pendingCount === 1 ? '' : 's');
?>
              <div class="row g-6 mb-6" data-live-region="wallet-buckets">
                <div class="col-12 col-md-4">
                  <?= View::partial('stat_card', [
                      'label' => 'Total balance',
                      'value' => money($total),
                      'icon'  => 'bx-wallet',
                      'tone'  => 'primary',
                  ]) ?>
                </div>
                <div class="col-12 col-md-4">
                  <?= View::partial('stat_card', [
                      'label' => 'Withdrawable',
                      'value' => money($withdrawable),
                      'icon'  => 'bx-money-withdraw',
                      'tone'  => 'success',
                  ]) ?>
                </div>
                <div class="col-12 col-md-4">
                  <?= View::partial('stat_card', [
                      'label' => 'Non-withdrawable',
                      'value' => money($locked),
                      'icon'  => 'bx-lock-alt',
                      'tone'  => 'warning',
                  ]) ?>
                </div>
<?php if ($pendingTotal > 0.0): ?>
                <div class="col-12">
                  <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-3 mb-0" role="status">
                    <div class="d-flex align-items-start gap-2">
                      <i class="icon-base bx bx-time-five fs-5"></i>
                      <div>
                        <strong>Repeat-purchase pending release (not in wallet balance)</strong>
                        <div class="small">
                          Earned, but not spendable or withdrawable until the release job credits the wallet.
                        </div>
                      </div>
                    </div>
                    <div class="text-end">
                      <div class="fw-semibold fs-5"><?= e(money($pendingTotal)) ?></div>
                      <div class="small">
                        <?= e($pendingAwards) ?> ·
<?php if ($pendingDue !== null): ?>
                        next release <?= e(format_date($pendingDue)) ?>
<?php elseif ((int) $pending['overdue'] > 0): ?>
                        due now — next release run
<?php else: ?>
                        awaiting delivery
<?php endif; ?>
                      </div>
<?php if (!$pending['release_enabled']): ?>
                      <div class="small fw-semibold">Release is currently paused by the administrator.</div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
<?php endif; ?>
              </div>
