<?php
declare(strict_types=1);

/**
 * Reports — Royalty bonus summary (Phase 19D, plan §8, §17 risk 1).
 *
 * The window is read as whole **award periods** (a month is the unit of
 * the royalty ledger): every closed month whose `YYYY-MM` falls inside
 * the window. `paid` is money in wallets, `pending` is evaluated but not
 * paid, `skipped` rows were judged and paid nothing.
 *
 * `payout_ratio` is royalty paid as a percentage of the paid sales in
 * the same window — the figure the risky-economics review watches.
 *
 * The numbers come from `Report::royaltySummary()`, the same read the
 * dashboard tile uses, so the two screens cannot disagree.
 *
 * @var array{
 *   paid:float, pending:float, awarded:float, paid_rows:int, pending_rows:int,
 *   recipients:int, paid_recipients:int, skipped:int, average:float,
 *   periods:int, paid_sales:float, payout_ratio:float, paid_this_month:float
 * } $royalty
 * @var array{royalty:float, sales:float, ratio:float} $royalty_ratio
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$royalty = array_merge([
    'paid' => 0.0, 'pending' => 0.0, 'awarded' => 0.0, 'paid_rows' => 0,
    'pending_rows' => 0, 'recipients' => 0, 'paid_recipients' => 0, 'skipped' => 0,
    'average' => 0.0, 'periods' => 0, 'paid_sales' => 0.0, 'payout_ratio' => 0.0,
    'paid_this_month' => 0.0,
], $royalty ?? []);

$ratio = array_merge(
    ['royalty' => (float) $royalty['paid'], 'sales' => (float) $royalty['paid_sales'], 'ratio' => (float) $royalty['payout_ratio']],
    $royalty_ratio ?? []
);

$range = $range ?? ['label' => ''];
$pendingCount = (int) $royalty['pending_rows'];
?>
              <div class="card h-100 report-royalty-card" data-live-region="report-royalty">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Royalty bonus</h5>
                    <p class="text-body-secondary small mb-0">
                      Monthly override on group sales — <?= e($range['label']) ?>, read by award period
                    </p>
                  </div>
                  <a href="<?= e(url('admin/commissions?ledger=royalty')) ?>" class="btn btn-sm btn-label-primary">Ledger</a>
                </div>
                <div class="card-body">
                  <div class="d-flex align-items-baseline gap-2 mb-1">
                    <h3 class="mb-0"><?= e(money($royalty['paid'])) ?></h3>
                    <span class="text-body-secondary small">paid in this window</span>
                  </div>
                  <div class="small text-body-secondary mb-3">
                    Royalty paid ÷ paid sales =
                    <strong><?= e(number_format((float) $ratio['ratio'], 1, '.', ',')) ?>%</strong>
                    (<?= e(money($ratio['sales'])) ?> of sales in the window)
                  </div>

<?php if ($pendingCount > 0): ?>
                  <div class="alert alert-warning d-flex align-items-start justify-content-between gap-2 mb-3" role="status">
                    <div>
                      <strong>Pending liability</strong>
                      <div class="small">
                        <?= $pendingCount ?> award<?= $pendingCount === 1 ? '' : 's' ?> evaluated but not in a wallet yet.
                      </div>
                    </div>
                    <span class="fw-semibold text-nowrap"><?= e(money($royalty['pending'])) ?></span>
                  </div>
<?php endif; ?>

                  <div class="row g-3">
                    <div class="col-6">
                      <div class="text-body-secondary small">Recipients</div>
                      <div class="fw-semibold"><?= (int) $royalty['recipients'] ?></div>
                    </div>
                    <div class="col-6">
                      <div class="text-body-secondary small">Paid awards</div>
                      <div class="fw-semibold"><?= (int) $royalty['paid_rows'] ?></div>
                    </div>
                    <div class="col-6">
                      <div class="text-body-secondary small">Skipped</div>
                      <div class="fw-semibold"><?= (int) $royalty['skipped'] ?></div>
                    </div>
                    <div class="col-6">
                      <div class="text-body-secondary small">Average award</div>
                      <div class="fw-semibold"><?= e(money($royalty['average'])) ?></div>
                    </div>
                    <div class="col-12">
                      <div class="text-body-secondary small">Closed months in the window</div>
                      <div class="fw-semibold"><?= (int) $royalty['periods'] ?></div>
                    </div>
                  </div>
                </div>
                <div class="card-footer small text-body-secondary">
                  Paid <?= e(money($royalty['paid_this_month'])) ?> this month ·
                  a skipped row is an audit note, never money ·
                  Royalty is paid after the month closes, never per order.
                </div>
              </div>
