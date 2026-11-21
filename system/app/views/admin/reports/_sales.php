<?php
declare(strict_types=1);

/**
 * Reports — sales by day (chart) and by payment method.
 *
 * The chart is drawn by assets/js/admin-reports.js from
 * window.ECOWEALTH_REPORTS; the whole card body is replaced by the live
 * feed, and the script re-renders the chart from the payload's `data`.
 *
 * @var array{orders:int, amount:float, average:float} $sales
 * @var list<array{day:string, orders:int, amount:float}> $sales_by_day
 * @var list<array{method:string, orders:int, amount:float}> $sales_by_method
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$sales = array_merge(['orders' => 0, 'amount' => 0.0, 'average' => 0.0], $sales ?? []);
$byDay = $sales_by_day ?? [];
$byMethod = $sales_by_method ?? [];
$range = $range ?? ['label' => ''];

$best = null;

foreach ($byDay as $day) {
    if ($best === null || $day['amount'] > $best['amount']) {
        $best = $day;
    }
}
?>
              <div class="card h-100 report-sales-card" data-live-region="report-sales">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Sales by day</h5>
                    <p class="text-body-secondary small mb-0">
                      <?= e(money($sales['amount'])) ?> across <?= (int) $sales['orders'] ?> paid order<?= (int) $sales['orders'] === 1 ? '' : 's' ?>
                      · <?= e((string) $range['label']) ?>
                    </p>
                  </div>
                  <span class="badge rounded-pill bg-label-success">Paid orders only</span>
                </div>
                <div class="card-body">
<?php if ($byDay === [] || $sales['orders'] === 0): ?>
                  <div class="text-center text-body-secondary py-6">
                    <i class="icon-base bx bx-bar-chart-alt-2 icon-xl d-block mb-2"></i>
                    No paid sales in this window yet.
                  </div>
<?php else: ?>
                  <div id="reportSalesChart" class="report-chart" aria-label="Sales by day chart"></div>
<?php endif; ?>

                  <div class="row g-4 mt-4">
                    <div class="col-12 col-md-7">
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 report-table">
                          <thead>
                            <tr>
                              <th scope="col">Payment method</th>
                              <th scope="col" class="text-end">Orders</th>
                              <th scope="col" class="text-end">Sales</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($byMethod as $row): ?>
                            <tr>
                              <td>
                                <i class="icon-base <?= e(payment_method_icon((string) $row['method'])) ?> me-1"></i>
                                <?= e(payment_method_label((string) $row['method'])) ?>
                              </td>
                              <td class="text-end"><?= (int) $row['orders'] ?></td>
                              <td class="text-end"><?= e(money($row['amount'])) ?></td>
                            </tr>
<?php endforeach; ?>
<?php if ($byMethod === []): ?>
                            <tr><td colspan="3" class="text-body-secondary">No paid orders in this window.</td></tr>
<?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <div class="col-12 col-md-5">
                      <div class="report-highlight">
                        <p class="text-body-secondary small mb-1">Average order</p>
                        <h5 class="mb-3"><?= e(money($sales['average'])) ?></h5>
                        <p class="text-body-secondary small mb-1">Best day<?= $best === null ? '' : ' · ' . e(date('M j', strtotime((string) $best['day'] . ' 12:00:00'))) ?></p>
                        <h6 class="mb-0"><?= e(money($best['amount'] ?? 0)) ?></h6>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
