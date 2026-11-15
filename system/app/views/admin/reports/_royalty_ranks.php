<?php
declare(strict_types=1);

/**
 * Reports — Royalty money by paying rank (Phase 19D).
 *
 * Director, Manager and Supervisor are always listed, zero-filled: a
 * rank that paid nothing in the window is exactly what the rank
 * distribution review is looking for (plan §17 risk 2).
 *
 * @var list<array{
 *   rank:int, name:string, recipients:int, paid_recipients:int,
 *   paid:float, pending:float, skipped:int, average:float
 * }> $royalty_ranks
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$rows  = $royalty_ranks ?? [];
$range = $range ?? ['label' => ''];

$totals = ['recipients' => 0, 'paid' => 0.0, 'pending' => 0.0, 'skipped' => 0, 'paid_rows' => 0];

foreach ($rows as $row) {
    $totals['recipients'] += (int) $row['recipients'];
    $totals['paid']       += (float) $row['paid'];
    $totals['pending']    += (float) $row['pending'];
    $totals['skipped']    += (int) $row['skipped'];
    $totals['paid_rows']  += (int) $row['paid_recipients'];
}

$totals['paid']    = round($totals['paid'], 2);
$totals['pending'] = round($totals['pending'], 2);
$average = $totals['paid_rows'] === 0 ? 0.0 : round($totals['paid'] / $totals['paid_rows'], 2);
?>
              <div class="card h-100 report-royalty-ranks-card" data-live-region="report-royalty-ranks">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Royalty by rank</h5>
                    <p class="text-body-secondary small mb-0">
                      Only Supervisor, Manager and Director are paid. A rank with zero rows is shown, not hidden.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= count($rows) ?> paying rank<?= count($rows) === 1 ? '' : 's' ?></span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col">Rank</th>
                        <th scope="col" class="text-end">Recipients</th>
                        <th scope="col" class="text-end">Paid</th>
                        <th scope="col" class="text-end">Pending</th>
                        <th scope="col" class="text-end">Skipped</th>
                        <th scope="col" class="text-end">Average</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($rows as $row): ?>
<?php
    $paidRows = (int) $row['paid_recipients'];
    $paid     = (float) $row['paid'];
?>
                      <tr>
                        <td class="text-nowrap fw-medium"><?= e((string) $row['name']) ?></td>
                        <td class="text-end"><?= (int) $row['recipients'] ?></td>
                        <td class="text-end"><?= e(money($paid)) ?></td>
                        <td class="text-end"><?= e(money((float) $row['pending'])) ?></td>
                        <td class="text-end"><?= (int) $row['skipped'] ?></td>
                        <td class="text-end">
                          <?= e(money((float) $row['average'])) ?>
<?php if ($paidRows > 0): ?>
                          <div class="small text-body-secondary"><?= $paidRows ?> paid</div>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
                      <tr><td colspan="6" class="text-body-secondary">No Royalty rank row in this window.</td></tr>
<?php endif; ?>
                    </tbody>
                    <tfoot>
                      <tr class="fw-semibold">
                        <td>Window</td>
                        <td class="text-end"><?= (int) $totals['recipients'] ?></td>
                        <td class="text-end"><?= e(money($totals['paid'])) ?></td>
                        <td class="text-end"><?= e(money($totals['pending'])) ?></td>
                        <td class="text-end"><?= (int) $totals['skipped'] ?></td>
                        <td class="text-end"><?= e(money($average)) ?></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
