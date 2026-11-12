<?php
declare(strict_types=1);

/**
 * Admin dashboard — commerce & finance roll-up (plan §7 Phase 15).
 *
 * Sales today and over 30 days, the orders that need action, the
 * commissions paid in the last 30 days, the repeat-purchase bonus paid
 * over the same window, the wallet float and the money currently held
 * for withdrawals. The figures come from the same ReportService the
 * reports desk uses.
 *
 * @var array<string, mixed> $commerce
 */

$commerce = $commerce ?? [];
$today    = array_merge(['orders' => 0, 'amount' => 0.0], (array) ($commerce['sales_today'] ?? []));
$last30   = array_merge(['orders' => 0, 'amount' => 0.0], (array) ($commerce['sales_30d'] ?? []));
$paid     = array_merge(['amount' => 0.0, 'count' => 0, 'all_time' => 0.0], (array) ($commerce['commissions'] ?? []));
$repeat   = array_merge(
    ['amount' => 0.0, 'count' => 0, 'recipients' => 0, 'all_time' => 0.0, 'this_month' => 0.0],
    (array) ($commerce['unilevel'] ?? [])
);
$wallet   = array_merge(['accounts' => 0, 'float' => 0.0, 'withdrawable' => 0.0], (array) ($commerce['wallet'] ?? []));
$payouts  = array_merge(['held' => 0.0, 'held_requests' => 0], (array) ($commerce['payouts'] ?? []));
$members  = array_merge(['active' => 0, 'pending' => 0, 'new_30d' => 0], (array) ($commerce['members'] ?? []));

$cards = [
    [
        'label' => 'Sales today',
        'value' => money($today['amount']),
        'hint'  => (int) $today['orders'] . ' paid order' . ((int) $today['orders'] === 1 ? '' : 's') . ' since midnight',
        'icon'  => 'bx-cart-alt',
        'tone'  => 'success',
        'href'  => 'admin/orders',
    ],
    [
        'label' => 'Sales · 30 days',
        'value' => money($last30['amount']),
        'hint'  => (int) $last30['orders'] . ' paid orders · avg ' . money((float) ($last30['average'] ?? 0)),
        'icon'  => 'bx-line-chart',
        'tone'  => 'primary',
        'href'  => 'admin/reports',
    ],
    [
        'label' => 'Orders needing action',
        'value' => (string) (int) ($commerce['open_orders'] ?? 0),
        'hint'  => 'Payment review, packing, ready, shipped',
        'icon'  => 'bx-time-five',
        'tone'  => 'warning',
        'href'  => 'admin/orders?status=payment_review',
    ],
    [
        'label' => 'Commissions · 30 days',
        'value' => money($paid['amount']),
        'hint'  => (int) $paid['count'] . ' awards · ' . money($paid['all_time']) . ' all time',
        'icon'  => 'bx-gift',
        'tone'  => 'info',
        'href'  => 'admin/commissions',
    ],
    [
        'label' => 'Repeat bonus (30 d)',
        'value' => money($repeat['amount']),
        'hint'  => (int) $repeat['count'] . ' awards · ' . (int) $repeat['recipients'] . ' recipients',
        'icon'  => 'bx-receipt',
        'tone'  => 'dark',
        'href'  => 'admin/commissions?ledger=repeat',
    ],
    [
        'label' => 'Wallet float',
        'value' => money($wallet['float']),
        'hint'  => money($wallet['withdrawable']) . ' withdrawable · ' . (int) $wallet['accounts'] . ' accounts',
        'icon'  => 'bx-wallet',
        'tone'  => 'secondary',
        'href'  => 'admin/wallets',
    ],
    [
        'label' => 'Held for payout',
        'value' => money($payouts['held']),
        'hint'  => (int) $payouts['held_requests'] . ' open request' . ((int) $payouts['held_requests'] === 1 ? '' : 's'),
        'icon'  => 'bx-money-withdraw',
        'tone'  => 'danger',
        'href'  => 'admin/payouts',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="dashboard-commerce">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-md-4 col-xl-3">
                  <a class="card h-100 stat-card text-reset text-decoration-none" href="<?= e(url($card['href'])) ?>">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small text-end"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-1 stat-value"><?= e($card['value']) ?></h4>
                      <small class="text-body-secondary"><?= e($card['hint']) ?></small>
                    </div>
                  </a>
                </div>
<?php endforeach; ?>
              </div>
