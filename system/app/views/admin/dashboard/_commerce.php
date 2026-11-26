<?php
declare(strict_types=1);

/*
 * Admin dashboard — commerce & finance roll-up (plan §7 Phase 15).
 *
 * Sales today and over 30 days, the orders that need action, the
 * commissions paid in the last 30 days, the repeat-purchase bonus paid
 * over the same window, the wallet float and the money currently held
 * for withdrawals. The figures come from the same ReportService the
 * reports desk uses.
 *
 * Phase 20E adds the **Team Harvest (30 d)** KPI: the level money decided
 * in the last 30 days, with the open liability it implies. The liability is
 * labelled *not yet paid* because it is derived, not stored (Rule 8).
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
$royalty  = array_merge(
    ['paid' => 0.0, 'pending' => 0.0, 'this_month' => 0.0, 'periods' => 0],
    (array) ($commerce['royalty'] ?? [])
);
$harvest  = array_merge(
    [
        'credited' => 0.0, 'count' => 0, 'recipients' => 0, 'this_month' => 0.0,
        'open' => 0.0, 'open_levels' => 0, 'queued' => 0, 'seeded' => false,
    ],
    (array) ($commerce['team_harvest'] ?? [])
);
$binary   = array_merge(
    [
        'credited' => 0.0, 'count' => 0, 'recipients' => 0, 'pending' => 0.0,
        'points_left' => 0, 'points_right' => 0, 'this_month' => 0.0,
    ],
    (array) ($commerce['binary'] ?? [])
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
        'label' => 'Royalty paid',
        'value' => money($royalty['paid']),
        'hint'  => money($royalty['this_month']) . ' this month · ' . money($royalty['pending']) . ' awaiting payment',
        'icon'  => 'bx-crown',
        'tone'  => 'success',
        'href'  => 'admin/commissions?ledger=royalty',
    ],
    [
        'label' => 'Team Harvest (30 d)',
        'value' => money($harvest['credited']),
        'hint'  => (int) $harvest['count'] . ' level' . ((int) $harvest['count'] === 1 ? '' : 's') . ' paid · '
            . money($harvest['open']) . ' open liability — not yet paid',
        'icon'  => 'bx-medal',
        'tone'  => 'info',
        'href'  => 'admin/commissions?ledger=team_harvest',
    ],
    [
        'label' => 'Product binary (30 d)',
        'value' => money($binary['credited']),
        'hint'  => (int) $binary['count'] . ' award' . ((int) $binary['count'] === 1 ? '' : 's') . ' · '
            . (int) $binary['recipients'] . ' recipient' . ((int) $binary['recipients'] === 1 ? '' : 's')
            . ' · ' . number_format((int) $binary['points_left'] + (int) $binary['points_right']) . ' pts placed',
        'icon'  => 'bx-link-alt',
        'tone'  => 'secondary',
        'href'  => 'admin/commissions?ledger=binary',
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
                  <?= View::partial('stat_card', $card) ?>
                </div>
<?php endforeach; ?>
              </div>
