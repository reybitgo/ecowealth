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
                  <?= View::partial('stat_card', $card) ?>
                </div>
<?php endforeach; ?>
<?php if ($pendingShown): ?>
                <div class="col-12 col-md-6">
                  <?= View::partial('stat_card', [
                      'label' => 'Pending release',
                      'value' => money($pendingTotal),
                      'hint'  => 'Repeat-purchase bonus that is not withdrawable yet.'
                          . ($pendingMeta !== '' ? '<span class="d-block mt-1">' . e($pendingMeta) . '</span>' : '')
                          . '<a href="' . e($statement) . '" class="d-inline-block mt-1">See the repeat-purchase statement</a>',
                      'icon'  => 'bx-time-five',
                      'tone'  => 'info',
                  ]) ?>
                </div>
                <div class="col-12 col-md-6">
                  <?= View::partial('stat_card', [
                      'label' => 'Total funds',
                      'value' => money($totalFunds),
                      'hint'  => 'Wallet balance + pending release. Pending release is not spendable or withdrawable yet.',
                      'icon'  => 'bx-coin-stack',
                      'tone'  => 'secondary',
                  ]) ?>
                </div>
<?php endif; ?>
              </div>
