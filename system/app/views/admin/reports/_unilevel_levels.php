<?php
declare(strict_types=1);

/**
 * Reports — repeat-purchase awards by ladder level (Phase 17D/18, plan §8, §19.6).
 *
 * One row per level 1–10 that saw activity in the window. Level 1 is
 * the buyer's direct sponsor and levels 2–10 the next nine uplines:
 * one ladder, no direct/indirect split (D-17), so the table has no
 * "leg" or "type" column and never will.
 *
 * `count` / `amount` are released money; the Pending column is the
 * escrow figure for the same level (earned, not in a wallet yet).
 *
 * @var list<array{
 *   level:int, count:int, amount:float, skipped:int,
 *   pending_count:int, pending_amount:float
 * }> $unilevel_levels
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$rows  = $unilevel_levels ?? [];
$range = $range ?? ['label' => ''];

$totals = ['count' => 0, 'amount' => 0.0, 'skipped' => 0, 'pending_count' => 0, 'pending_amount' => 0.0];

foreach ($rows as $row) {
    $totals['count']          += (int) $row['count'];
    $totals['amount']         += (float) $row['amount'];
    $totals['skipped']        += (int) $row['skipped'];
    $totals['pending_count']  += (int) ($row['pending_count'] ?? 0);
    $totals['pending_amount'] += (float) ($row['pending_amount'] ?? 0);
}

$totals['amount']         = round($totals['amount'], 2);
$totals['pending_amount'] = round($totals['pending_amount'], 2);
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
                        <th scope="col" class="text-end">Pending</th>
                        <th scope="col" class="text-end">Skipped</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($rows as $row): ?>
<?php
    $pendingCount  = (int) ($row['pending_count'] ?? 0);
    $pendingAmount = (float) ($row['pending_amount'] ?? 0);
?>
                      <tr>
                        <td class="text-nowrap">Level <?= (int) $row['level'] ?></td>
                        <td class="text-end"><?= (int) $row['count'] ?></td>
                        <td class="text-end"><?= e(money($row['amount'])) ?></td>
                        <td class="text-end">
                          <?= e(money($pendingAmount)) ?>
<?php if ($pendingCount > 0): ?>
                          <div class="small text-body-secondary"><?= $pendingCount ?> award<?= $pendingCount === 1 ? '' : 's' ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int) $row['skipped'] ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
                      <tr>
                        <td colspan="5" class="py-0">
<?php
    echo View::partial('empty_state', [
        'compact' => true,
        'icon'    => 'bx bx-layer',
        'title'   => 'No repeat-purchase award in this window',
        'text'    => 'Awards appear when a Team chain order pays one of the ten levels.',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
                    </tbody>
                    <tfoot>
                      <tr class="fw-semibold">
                        <td>Window</td>
                        <td class="text-end"><?= (int) $totals['count'] ?></td>
                        <td class="text-end"><?= e(money($totals['amount'])) ?></td>
                        <td class="text-end">
                          <?= e(money($totals['pending_amount'])) ?>
<?php if ($totals['pending_count'] > 0): ?>
                          <div class="small fw-normal text-body-secondary"><?= (int) $totals['pending_count'] ?> award<?= $totals['pending_count'] === 1 ? '' : 's' ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int) $totals['skipped'] ?></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
