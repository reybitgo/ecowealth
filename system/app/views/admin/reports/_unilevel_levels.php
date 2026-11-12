<?php
declare(strict_types=1);

/**
 * Reports — repeat-purchase awards by ladder level (Phase 17D, plan §8).
 *
 * One row per level 1–10 that saw activity in the window. Level 1 is
 * the buyer's direct sponsor and levels 2–10 the next nine uplines:
 * one ladder, no direct/indirect split (D-17), so the table has no
 * "leg" or "type" column and never will.
 *
 * @var list<array{level:int, count:int, amount:float, skipped:int}> $unilevel_levels
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$rows  = $unilevel_levels ?? [];
$range = $range ?? ['label' => ''];

$totals = ['count' => 0, 'amount' => 0.0, 'skipped' => 0];

foreach ($rows as $row) {
    $totals['count']   += (int) $row['count'];
    $totals['amount']  += (float) $row['amount'];
    $totals['skipped'] += (int) $row['skipped'];
}

$totals['amount'] = round($totals['amount'], 2);
?>
              <div class="card h-100 report-unilevel-levels-card" data-live-region="report-unilevel-levels">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Repeat-purchase by level</h5>
                    <p class="text-body-secondary small mb-0">
                      One ladder, ten levels. A skipped level keeps its number — no compression.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= count($rows) ?> level<?= count($rows) === 1 ? '' : 's' ?> active</span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col">Level</th>
                        <th scope="col" class="text-end">Awards</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Skipped</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($rows as $row): ?>
                      <tr>
                        <td class="text-nowrap">Level <?= (int) $row['level'] ?></td>
                        <td class="text-end"><?= (int) $row['count'] ?></td>
                        <td class="text-end"><?= e(money($row['amount'])) ?></td>
                        <td class="text-end"><?= (int) $row['skipped'] ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
                      <tr><td colspan="4" class="text-body-secondary">No repeat-purchase award in this window.</td></tr>
<?php endif; ?>
                    </tbody>
                    <tfoot>
                      <tr class="fw-semibold">
                        <td>Window</td>
                        <td class="text-end"><?= (int) $totals['count'] ?></td>
                        <td class="text-end"><?= e(money($totals['amount'])) ?></td>
                        <td class="text-end"><?= (int) $totals['skipped'] ?></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
