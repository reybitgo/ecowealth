<?php
declare(strict_types=1);

/**
 * Admin General Harvest ledger — the level distribution (Phase 21E-S1,
 * plan §9 Rule 11, Appendix C.2).
 *
 * Always the ladder's **ten rows**, including the levels nobody has
 * completed: a reader must see the shape of the plan, not just the paid
 * part. The amounts come from `general_harvest_levels` (the configured
 * ladder), the counts from the awards, and the frontier from
 * `GeneralHarvestBoard::frontier()` — no view computes a threshold of its
 * own.
 *
 * A level above `general_harvest_max_levels` is marked **not paying at
 * this depth**: its block may be complete, but the configured depth does
 * not pay it (Rule 3 of §12.6). A level whose amount is zero is marked
 * **unpriced** (§12.6 Rule 2).
 *
 * @var array<int, array{level:int, amount:float, awards:int, credited:int,
 *                       credited_total:float, baseline:int, baseline_total:float}> $levels
 * @var int $max_levels
 * @var list<array{level:int, rows:int, complete:int, missing:int, next_n:int, amount:float}> $frontier
 */

$levels    = $levels ?? [];
$maxLevels = (int) ($max_levels ?? GeneralHarvestLevel::LEVEL_MAX);
$frontier  = is_array($frontier ?? null) ? $frontier : [];
$frontierByLevel = [];

foreach ($frontier as $row) {
    $frontierByLevel[(int) $row['level']] = $row;
}
?>
              <div class="card mb-4" data-live-region="general-harvest-levels">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Level distribution</h5>
                    <p class="text-body-secondary small mb-0">
                      The ladder's ten levels. A level pays once, when its block is full —
                      3, 12, 39 … 88,572 qualified members.
                    </p>
                  </div>
                  <span class="badge bg-label-secondary">
                    Levels that pay: <?= $maxLevels ?>
                  </span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Level</th>
                        <th scope="col" class="text-end">Members needed</th>
                        <th scope="col" class="text-end">Block size</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Paid awards</th>
                        <th scope="col" class="text-end">Paid total</th>
                        <th scope="col" class="text-end">Before go-live</th>
                        <th scope="col">State</th>
                        <th scope="col" class="text-end">Next at N</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($levels as $row): ?>
<?php
    $level      = (int) $row['level'];
    $amount     = (float) $row['amount'];
    $credited   = (int) $row['credited'];
    $baselineN  = (int) $row['baseline'];
    $front      = $frontierByLevel[$level] ?? null;
    $beyond     = $level > $maxLevels;
    $noAmount   = $amount <= 0.0;
    $state      = $beyond ? ['Not paying', 'secondary'] : ($noAmount ? ['Unpriced', 'warning'] : ['Pays', 'success']);
    $nextN      = $front !== null ? (int) ($front['next_n'] ?? 0) : 0;
    $missing    = $front !== null ? (int) ($front['missing'] ?? 0) : 0;
?>
                      <tr>
                        <td class="fw-medium text-nowrap">Level <?= $level ?></td>
                        <td class="text-end text-nowrap"><?= number_format(GeneralHarvestLevel::threshold(0, $level)) ?></td>
                        <td class="text-end text-nowrap"><?= number_format(GeneralHarvestLevel::slots($level)) ?></td>
                        <td class="text-end text-nowrap"><?= e(money($amount)) ?></td>
                        <td class="text-end"><?= $credited ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $row['credited_total'])) ?></td>
                        <td class="text-end text-nowrap">
                          <?= $baselineN ?>
                          <div class="small text-body-secondary"><?= e(money((float) $row['baseline_total'])) ?></div>
                        </td>
                        <td class="text-nowrap">
                          <span class="badge bg-label-<?= e($state[1]) ?>"><?= e($state[0]) ?></span>
<?php if ($beyond): ?>
                          <div class="small text-body-secondary">above the configured depth of <?= $maxLevels ?></div>
<?php elseif ($noAmount): ?>
                          <div class="small text-body-secondary">a zero amount is a switch</div>
<?php elseif ($missing > 0): ?>
                          <div class="small text-body-secondary"><?= $missing ?> block<?= $missing === 1 ? '' : 's' ?> waiting at N = <?= $nextN ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap"><?= $nextN ?></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  A level pays once, ever. A block that was already full at go-live is recorded
                  <strong>Before go-live</strong> and is never paid. A level above the configured depth
                  stops paying until the depth is raised.
                </div>
              </div>
