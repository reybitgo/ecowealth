<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — float and bucket cards.
 *
 * @var array{
 *   total:float, withdrawable:float, locked:float, funded_members:int,
 *   topups:int, topup_total:float, topups_today:int, topup_today_total:float,
 *   credits_today:float, debits_today:float
 * } $totals
 */

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
        'hint'  => 'Cashable by members',
        'icon'  => 'bx-money-withdraw',
        'tone'  => 'success',
    ],
    [
        'label' => 'Non-withdrawable',
        'value' => money((float) $totals['locked']),
        'hint'  => 'Spendable in the shop only',
        'icon'  => 'bx-lock-alt',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Top-ups today',
        'value' => money((float) $totals['topup_today_total']),
        'hint'  => $totals['topups_today'] . ' today · ' . money((float) $totals['topup_total']) . ' lifetime',
        'icon'  => 'bx-plus-circle',
        'tone'  => 'info',
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
