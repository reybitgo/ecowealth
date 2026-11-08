<?php
declare(strict_types=1);

/**
 * Member wallet statement — the three bucket cards.
 *
 * A live region: every wallet movement bumps the per-member statement
 * surface, so the total, the withdrawable share and the spend-only
 * remainder refresh in place.
 *
 * @var array<string, mixed> $account
 */

$total        = round((float) $account['ewallet_balance'], 2);
$withdrawable = round((float) $account['withdrawable_balance'], 2);
$locked       = round($total - $withdrawable, 2);

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
              </div>
