<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — float and bucket cards.
 *
 * @var array{
 *   total:float, withdrawable:float, locked:float, funded_members:int,
 *   topups:int, topup_total:float, topups_today:int, topup_today_total:float,
 *   transfers:int, transfer_volume:float, transfer_fees:float,
 *   transfers_today:int, transfer_today_volume:float,
 *   credits_today:float, debits_today:float
 * } $totals
 */

$withdrawablePercent = (float) $totals['total'] > 0
    ? round(((float) $totals['withdrawable'] / (float) $totals['total']) * 100, 1)
    : 0.0;

$lockedPercent = (float) $totals['total'] > 0
    ? round(((float) $totals['locked'] / (float) $totals['total']) * 100, 1)
    : 0.0;

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

