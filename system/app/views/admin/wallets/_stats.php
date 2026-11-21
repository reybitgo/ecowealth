<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — float, bucket cards and the escrow liability.
 *
 * Phase 18 adds the pending repeat-purchase figure as its own card: the
 * money is earned but not in any wallet, so it is a separate liability
 * and is never part of the float (plan §19.4).
 *
 * @var array{
 *   total:float, withdrawable:float, locked:float, funded_members:int,
 *   topups:int, topup_total:float, topups_today:int, topup_today_total:float,
 *   transfers:int, transfer_volume:float, transfer_fees:float,
 *   transfers_today:int, transfer_today_volume:float,
 *   credits_today:float, debits_today:float, repeat_pending?:array<string, mixed>
 * } $totals
 * @var array{total:float, count:int, recipients:int, next_release_at:?string, release_enabled:bool} $repeat_pending
 */

$withdrawablePercent = (float) $totals['total'] > 0
    ? round(((float) $totals['withdrawable'] / (float) $totals['total']) * 100, 1)
    : 0.0;

$lockedPercent = (float) $totals['total'] > 0
    ? round(((float) $totals['locked'] / (float) $totals['total']) * 100, 1)
    : 0.0;

/* The page template forwards `totals` to this card, so the controller
 * ships the liability inside it on the first render; the live feed
 * passes `repeat_pending` directly. Both paths reach the same card. */
$repeat = array_merge([
    'total'           => 0.0,
    'count'           => 0,
    'recipients'      => 0,
    'next_release_at' => null,
    'release_enabled' => true,
], $repeat_pending ?? ($totals['repeat_pending'] ?? []));

$repeatTotal      = round((float) $repeat['total'], 2);
$repeatCount      = (int) $repeat['count'];
$repeatRecipients = (int) $repeat['recipients'];
$repeatNext       = $repeat['next_release_at'] === null ? null : (string) $repeat['next_release_at'];

$pendingHint = 'Separate liability — not part of the float.';

if ($repeatCount > 0) {
    $pendingHint .= ' ' . $repeatCount . ' award' . ($repeatCount === 1 ? '' : 's')
        . ' for ' . $repeatRecipients . ' member' . ($repeatRecipients === 1 ? '' : 's') . '.';
}

if ($repeatNext !== null) {
    $pendingHint .= ' Next release ' . format_date($repeatNext) . '.';
} elseif ($repeatCount === 0) {
    $pendingHint .= ' Nothing is pending release.';
}

$cards = [
    [
        'label' => 'Total float',
        'value' => money((float) $totals['total']),
        'hint'  => $totals['funded_members'] . ' funded wallet' . ($totals['funded_members'] === 1 ? '' : 's'),
        'icon'  => 'bx-wallet',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Withdrawable',
        'value' => money((float) $totals['withdrawable']),
        'hint'  => $withdrawablePercent . '% of the float — cashable by members',
        'icon'  => 'bx-money-withdraw',
        'tone'  => 'success',
    ],
    [
        'label' => 'Non-withdrawable',
        'value' => money((float) $totals['locked']),
        'hint'  => $lockedPercent . '% of the float — spendable in the shop only',
        'icon'  => 'bx-lock-alt',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Pending repeat bonuses',
        'value' => money($repeatTotal),
        'hint'  => $pendingHint,
        'icon'  => 'bx-gift',
        'tone'  => 'danger',
    ],
    [
        'label' => 'Transfers today',
        'value' => money((float) $totals['transfer_today_volume']),
        'hint'  => $totals['transfers_today'] . ' today · ' . money((float) $totals['transfer_volume']) . ' lifetime'
            . ((float) $totals['transfer_fees'] > 0 ? ' · ' . money((float) $totals['transfer_fees']) . ' fees' : ''),
        'icon'  => 'bx-transfer',
        'tone'  => 'info',
    ],
    [
        'label' => 'Top-ups today',
        'value' => money((float) $totals['topup_today_total']),
        'hint'  => $totals['topups_today'] . ' today · ' . money((float) $totals['topup_total']) . ' lifetime',
        'icon'  => 'bx-plus-circle',
        'tone'  => 'secondary',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="wallet-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
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
                      <h4 class="mb-2 wallet-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
