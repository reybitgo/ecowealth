<?php
declare(strict_types=1);

/**
 * Admin — reports (plan §7 Phase 15).
 *
 * Sales by day, commissions by level, the wallet statement export, the
 * payout register, registration-code revenue and the reconciliation /
 * health widget. Every table is a live region; the date-range form stays
 * outside them so a background refresh never discards a typed window.
 *
 * @var array{from:string, to:string, days:int, label:string} $range
 * @var array{orders:int, amount:float, average:float} $sales
 * @var array{orders:int, amount:float, average:float} $sales_today
 * @var array{orders:int, amount:float, average:float} $sales_30d
 * @var array{orders:int, amount:float, average:float} $sales_all
 * @var list<array{day:string, orders:int, amount:float}> $sales_by_day
 * @var list<array{method:string, orders:int, amount:float}> $sales_by_method
 * @var list<array{status:string, count:int, amount:float}> $orders
 * @var int $open_orders
 * @var list<array{name:string, sku:?string, units:int, amount:float}> $top_products
 * @var array{amount:float, count:int, skipped:int, recipients:int, all_time:float, this_month:float} $commissions
 * @var list<array{level:int, type:string, count:int, amount:float, skipped:int}> $levels
 * @var array{accounts:int, float:float, withdrawable:float, credited_today:float, debited_today:float, movements_today:int} $wallet
 * @var array{by_status:list<array{status:string,count:int,amount:float,fees:float}>, by_method:list<array{method:string,count:int,amount:float}>, held:float, held_requests:int, completed_amount:float, completed_fees:float} $payouts
 * @var list<array<string, mixed>> $codes
 * @var array{total:int, members:int, active:int, pending:int, suspended:int, new_30d:int} $members
 * @var array{ok:bool, checked_at:string, totals:array{checks:int,failing:int}, sections:list<array<string,mixed>>} $health
 * @var array<string, string> $export_types
 * @var string $export_url
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => 'admin.reports',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$exportTypes = $export_types ?? ReportService::EXPORTS;
$exportUrl   = $export_url ?? url('admin/reports/export');
$query       = '?from=' . rawurlencode((string) $range['from']) . '&to=' . rawurlencode((string) $range['to']);
?>

            <div
              class="container-xxl flex-grow-1 container-p-y reports-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Reports</h4>
                  <p class="text-body-secondary mb-0">
                    Sales, commissions, wallets, withdrawals and registration codes for
                    <strong><?= e($range['label']) ?></strong>.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <div class="dropdown">
                    <button class="btn btn-label-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="icon-base bx bx-download me-1"></i> Export CSV
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
<?php foreach ($exportTypes as $type => $label): ?>
                      <li>
                        <a class="dropdown-item" href="<?= e($exportUrl . $query . '&type=' . rawurlencode((string) $type)) ?>">
                          <i class="icon-base bx bx-file me-2"></i><?= e($label) ?>
                        </a>
                      </li>
<?php endforeach; ?>
                    </ul>
                  </div>
                  <a href="<?= e(url('admin/wallets')) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> E-wallet monitor
                  </a>
                </div>
              </div>

              <div class="card mb-6">
                <div class="card-body">
                  <form method="GET" action="<?= e(url('admin/reports')) ?>" class="d-flex flex-wrap align-items-end gap-3 reports-range">
                    <div class="reports-range-field">
                      <label class="form-label" for="reports-from">From</label>
                      <input type="date" name="from" id="reports-from" class="form-control" value="<?= e($range['from']) ?>" max="<?= e(date('Y-m-d')) ?>" />
                    </div>
                    <div class="reports-range-field">
                      <label class="form-label" for="reports-to">To</label>
                      <input type="date" name="to" id="reports-to" class="form-control" value="<?= e($range['to']) ?>" max="<?= e(date('Y-m-d')) ?>" />
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <button type="submit" class="btn btn-primary">
                        <i class="icon-base bx bx-filter-alt me-1"></i> Apply window
                      </button>
                      <a href="<?= e(url('admin/reports')) ?>" class="btn btn-label-secondary">Last 30 days</a>
                    </div>
                    <span class="text-body-secondary small ms-auto">
                      <?= (int) $range['days'] ?> day<?= (int) $range['days'] === 1 ? '' : 's' ?>
                      · windows are capped at <?= ReportService::MAX_DAYS ?> days
                    </span>
                  </form>
                </div>
              </div>

<?= View::capture('admin/reports/_kpis', [
    'sales'       => $sales,
    'sales_today' => $sales_today,
    'sales_30d'   => $sales_30d,
    'commissions' => $commissions,
    'wallet'      => $wallet,
    'payouts'     => $payouts,
    'codes'       => $codes,
    'range'       => $range,
]) ?>

              <div class="row g-6 mb-6">
                <div class="col-12 col-xl-7">
<?= View::capture('admin/reports/_health', ['health' => $health]) ?>
                </div>
                <div class="col-12 col-xl-5">
<?= View::capture('admin/reports/_orders', ['orders' => $orders, 'open_orders' => $open_orders]) ?>
                </div>
              </div>

              <div class="row g-6 mb-6">
                <div class="col-12 col-xl-8">
<?= View::capture('admin/reports/_sales', [
    'sales'           => $sales,
    'sales_by_day'    => $sales_by_day,
    'sales_by_method' => $sales_by_method,
    'range'           => $range,
]) ?>
                </div>
                <div class="col-12 col-xl-4">
<?= View::capture('admin/reports/_top_products', [
    'top_products' => $top_products,
    'range'        => $range,
]) ?>
                </div>
              </div>

              <div class="row g-6 mb-6">
                <div class="col-12 col-xl-7">
<?= View::capture('admin/reports/_commissions', [
    'commissions' => $commissions,
    'levels'      => $levels,
    'range'       => $range,
]) ?>
                </div>
                <div class="col-12 col-xl-5">
<?= View::capture('admin/reports/_payouts', [
    'payouts' => $payouts,
    'range'   => $range,
]) ?>
                </div>
              </div>

<?= View::capture('admin/reports/_codes', ['codes' => $codes]) ?>
            </div>

            <script>
              window.ECOWEALTH_REPORTS = <?= json_encode(
                  [
                      'sales' => [
                          'labels' => array_map(
                              static fn (array $day): string => date('M j', strtotime((string) $day['day'] . ' 12:00:00')),
                              $sales_by_day
                          ),
                          'values' => array_map(static fn (array $day): float => (float) $day['amount'], $sales_by_day),
                          'orders' => array_map(static fn (array $day): int => (int) $day['orders'], $sales_by_day),
                      ],
                  ],
                  JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
              ) ?: '{}' ?>;
            </script>
