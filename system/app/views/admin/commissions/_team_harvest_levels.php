<?php
declare(strict_types=1);

/**
 * Admin Team Harvest ledger — the level distribution (Phase 20E, plan §9
 * Rule 11, Appendix C.2).
 *
 * Always the ladder's **ten rows**, including the levels nobody has
 * completed: a reader must see the shape of the plan, not just the paid
 * part. The amounts come from `team_harvest_levels` (the configured
 * ladder), the counts from the awards, and the thresholds from the
 * structural arithmetic — no view computes a threshold of its own.
 *
 * A level above `team_harvest_max_levels` is marked **not paid**: its block
 * may be complete, but the configured depth does not pay it (Rule 3 of
 * §12.6). A level whose amount is zero is marked **no amount**, which is a
 * switch rather than a promise (§12.6 Rule 2).
 *
 * @var array<int, array{level:int, amount:float, awards:int, credited:int,
 *                       credited_total:float, baseline:int, baseline_total:float}> $levels
 * @var int $max_levels
 */

$levels    = $levels ?? [];
$maxLevels = (int) ($max_levels ?? TeamHarvestLevel::LEVEL_MAX);
?>
              <div class="card mb-4" data-live-region="team-harvest-levels">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Level distribution</h5>
                    <p class="text-body-secondary small mb-0">
                      The ladder's ten levels. A level pays once, when its block is full —
                      3, 12, 39 … 88,572 qualified teammates.
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
                        <th scope="col" class="text-end">Teammates needed</th>
                        <th scope="col" class="text-end">Block size</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Paid awards</th>
                        <th scope="col" class="text-end">Paid total</th>
                        <th scope="col" class="text-end">Before go-live</th>
                        <th scope="col">State</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($levels as $row): ?>
<?php
    $level      = (int) $row['level'];
    $amount     = (float) $row['amount'];
    $credited   = (int) $row['credited'];
    $baselineN  = (int) $row['baseline'];
    $beyond     = $level > $maxLevels;
    $noAmount   = $amount <= 0.0;
    $state      = $beyond ? ['Not paid', 'secondary'] : ($noAmount ? ['No amount', 'warning'] : ['Pays', 'success']);
?>
                      <tr>
                        <td class="fw-medium text-nowrap">Level <?= $level ?></td>
                        <td class="text-end text-nowrap"><?= number_format(TeamHarvestLevel::threshold($level)) ?></td>
                        <td class="text-end text-nowrap"><?= number_format(TeamHarvestLevel::slots($level)) ?></td>
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
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  A level pays once, ever. A level that was already complete at go-live is recorded
                  <strong>Before go-live</strong> and is never paid.
                </div>
              </div>
