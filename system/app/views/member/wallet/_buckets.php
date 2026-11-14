<?php
declare(strict_types=1);

/**
 * Member wallet statement — the three bucket cards and the escrow pair.
 *
 * A live region: every wallet movement bumps the per-member statement
 * surface, so the total, the withdrawable share and the spend-only
 * remainder refresh in place.
 *
 * The three bucket cards keep their Phase 5 meaning exactly:
 * `ewallet_balance` is the spendable total, `withdrawable_balance` is
 * the cashable part. Phase 18 adds the two escrow cards below them
 * (plan §10.1): **Pending release** is repeat-purchase money that is
 * earned but sits outside both buckets, and **Total funds** is
 * `wallet + pending` — a presentation-only sum, never a fourth bucket
 * and never spendable (ESC-01, ESC-09). Both cards are hidden when
 * nothing is pending, so the everyday wallet page gains no noise.
 *
 * @var array<string, mixed> $account
 * @var array{total:float, count:int, next_release_at:?string, undelivered:int, overdue:int, release_enabled:bool} $pending_repeat
 */

$total        = round((float) $account['ewallet_balance'], 2);
$withdrawable = round((float) $account['withdrawable_balance'], 2);
$locked       = round($total - $withdrawable, 2);

$pending      = is_array($pending_repeat ?? null) ? $pending_repeat : [];
$pendingTotal = round((float) ($pending['total'] ?? 0), 2);
$pendingCount = (int) ($pending['count'] ?? 0);
$pendingNext  = $pending['next_release_at'] ?? null;
$pendingShown = $pendingTotal > 0;
$totalFunds   = round($total + $pendingTotal, 2);
$statement    = url('earnings?ledger=repeat');

/* One extra line under the pending figure: when the next release is
 * due, or — before delivery — the hold the member can expect. */
$pendingMeta = $pendingCount > 0
    ? $pendingCount . ' award' . ($pendingCount === 1 ? '' : 's')
    : '';

if ($pendingNext !== null && trim((string) $pendingNext) !== '') {
    $pendingMeta .= ($pendingMeta === '' ? 'Next release ' : ' · next release ')
        . format_date((string) $pendingNext);
} elseif ((int) ($pending['undelivered'] ?? 0) > 0) {
    $pendingMeta .= ($pendingMeta === '' ? '' : ' · ')
        . 'after delivery + ' . UnilevelService::holdDays() . ' days';
}

$cards = [
    [
        'label' => 'Total balance',
        'value' => money($total),
        'hint'  => 'Spendable in the shop and available for payouts.',
        'icon'  => 'bx-wallet',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Withdrawable',
        'value' => money($withdrawable),
        'hint'  => 'May be paid out — commissions land here.',
        'icon'  => 'bx-money-withdraw',
        'tone'  => 'success',
    ],
    [
        'label' => 'Non-withdrawable',
        'value' => money($locked),
        'hint'  => 'Top-ups and transferred funds: spendable, not cashable.',
        'icon'  => 'bx-lock-alt',
        'tone'  => 'warning',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="member-wallet-buckets">
<?php foreach ($cards as $card): ?>
                <div class="col-12 col-md-4">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small"><?= e($card['label']) ?></span>
                      </div>
                      <h3 class="mb-2 wallet-stat-value"><?= e($card['value']) ?></h3>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
<?php if ($pendingShown): ?>
                <div class="col-12 col-md-6">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-info">
                            <i class="icon-base <?= e(icon_class('bx-time-five')) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small">Pending release</span>
                      </div>
                      <h3 class="mb-2 wallet-stat-value"><?= e(money($pendingTotal)) ?></h3>
                      <p class="text-body-secondary small mb-0">
                        Repeat-purchase bonus that is not withdrawable yet.
<?php if ($pendingMeta !== ''): ?>
                        <span class="d-block mt-1"><?= e($pendingMeta) ?></span>
<?php endif; ?>
                        <a href="<?= e($statement) ?>" class="d-inline-block mt-1">
                          See the repeat-purchase statement
                        </a>
                      </p>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-md-6">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-secondary">
                            <i class="icon-base <?= e(icon_class('bx-coin-stack')) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small">Total funds</span>
                      </div>
                      <h3 class="mb-2 wallet-stat-value"><?= e(money($totalFunds)) ?></h3>
                      <p class="text-body-secondary small mb-0">
                        Wallet balance + pending release. Pending release is not spendable or withdrawable yet.
                      </p>
                    </div>
                  </div>
                </div>
<?php endif; ?>
              </div>
