<?php
declare(strict_types=1);

/**
 * Reports — the six KPI cards for the selected window.
 *
 * @var array{orders:int, amount:float, average:float} $sales
 * @var array{orders:int, amount:float, average:float} $sales_today
 * @var array{orders:int, amount:float, average:float} $sales_30d
 * @var array{amount:float, count:int, skipped:int, recipients:int, all_time:float, this_month:float} $commissions
 * @var array{accounts:int, float:float, withdrawable:float} $wallet
 * @var array{held:float, held_requests:int} $payouts
 * @var list<array<string, mixed>> $codes
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$range = $range ?? ['from' => '', 'to' => '', 'days' => 0, 'label' => ''];
$sales = array_merge(['orders' => 0, 'amount' => 0.0, 'average' => 0.0], $sales ?? []);
$salesToday = array_merge(['orders' => 0, 'amount' => 0.0], $sales_today ?? []);
$sales30d = array_merge(['orders' => 0, 'amount' => 0.0], $sales_30d ?? []);
$commissions = array_merge(['amount' => 0.0, 'count' => 0, 'skipped' => 0, 'recipients' => 0], $commissions ?? []);
$wallet = array_merge(['accounts' => 0, 'float' => 0.0, 'withdrawable' => 0.0], $wallet ?? []);
$payouts = array_merge(['held' => 0.0, 'held_requests' => 0], $payouts ?? []);

$codeTotals = ['issued' => 0, 'used' => 0, 'issued_value' => 0.0, 'redeemed_value' => 0.0];

foreach ($codes ?? [] as $row) {
    $codeTotals['issued'] += (int) ($row['issued'] ?? 0);
    $codeTotals['used'] += (int) ($row['used'] ?? 0);
    $codeTotals['issued_value'] += (float) ($row['issued_value'] ?? 0);
    $codeTotals['redeemed_value'] += (float) ($row['redeemed_value'] ?? 0);
}

$cards = [
    [
        'label' => 'Sales in window',
        'value' => money($sales['amount']),
        'hint'  => $sales['orders'] . ' paid order' . ($sales['orders'] === 1 ? '' : 's')
            . ' · avg ' . money($sales['average']),
        'icon'  => 'bx-cart-alt',
        'tone'  => 'success',
    ],
    [
        'label' => 'Sales today',
        'value' => money($salesToday['amount']),
        'hint'  => $salesToday['orders'] . ' paid today · ' . money($sales30d['amount']) . ' in 30 days',
        'icon'  => 'bx-calendar-check',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Commissions in window',
        'value' => money($commissions['amount']),
        'hint'  => $commissions['count'] . ' award' . ($commissions['count'] === 1 ? '' : 's')
            . ' · ' . $commissions['skipped'] . ' skipped · ' . money($commissions['all_time']) . ' all time',
        'icon'  => 'bx-line-chart',
        'tone'  => 'info',
    ],
    [
        'label' => 'Wallet float',
        'value' => money($wallet['float']),
        'hint'  => money($wallet['withdrawable']) . ' withdrawable · ' . $wallet['accounts'] . ' accounts',
        'icon'  => 'bx-wallet',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Held for payout',
        'value' => money($payouts['held']),
        'hint'  => $payouts['held_requests'] . ' open request' . ($payouts['held_requests'] === 1 ? '' : 's'),
        'icon'  => 'bx-money-withdraw',
        'tone'  => 'danger',
    ],
    [
        'label' => 'Codes redeemed',
        'value' => money($codeTotals['redeemed_value']),
        'hint'  => $codeTotals['used'] . ' of ' . $codeTotals['issued'] . ' issued · '
            . money($codeTotals['issued_value']) . ' face value',
        'icon'  => 'bx-key',
        'tone'  => 'secondary',
    ],
];
?>
              <div class="row g-4 mb-6" data-live-region="report-kpis">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-md-4 col-xl-2">
                  <div class="card h-100 report-kpi-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small text-end"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-1 report-kpi-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
