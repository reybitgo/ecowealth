<?php
declare(strict_types=1);

/**
 * Reports — commissions by level (plan §6.4: levels 1–10, no compression).
 *
 * @var array{amount:float, count:int, skipped:int, recipients:int, all_time:float, this_month:float} $commissions
 * @var list<array{level:int, type:string, count:int, amount:float, skipped:int}> $levels
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$commissions = array_merge(
    ['amount' => 0.0, 'count' => 0, 'skipped' => 0, 'recipients' => 0, 'all_time' => 0.0, 'this_month' => 0.0],
    $commissions ?? []
);
$levels = $levels ?? [];
$range  = $range ?? ['label' => ''];

$byLevel = [];

foreach ($levels as $row) {
    $level = (int) $row['level'];
    $byLevel[$level] ??= ['level' => $level, 'amount' => 0.0, 'count' => 0, 'skipped' => 0, 'types' => []];
    $byLevel[$level]['amount'] += (float) $row['amount'];
    $byLevel[$level]['count'] += (int) $row['count'];
    $byLevel[$level]['skipped'] += (int) $row['skipped'];
    $byLevel[$level]['types'][(string) $row['type']] = true;
}

ksort($byLevel);
?>
              <div class="card h-100 report-commissions-card" data-live-region="report-commissions">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Commissions by level</h5>
                    <p class="text-body-secondary small mb-0">
                      <?= e(money($commissions['amount'])) ?> across <?= (int) $commissions['count'] ?> award<?= (int) $commissions['count'] === 1 ? '' : 's' ?>
                      · <?= (int) $commissions['recipients'] ?> recipient<?= (int) $commissions['recipients'] === 1 ? '' : 's' ?>
                    </p>
                  </div>
                  <a href="<?= e(url('admin/commissions')) ?>" class="btn btn-sm btn-label-primary">Ledger</a>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col">Level</th>
                        <th scope="col">Legs</th>
                        <th scope="col" class="text-end">Awards</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Skipped</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($byLevel as $row): ?>
                      <tr>
                        <td class="text-nowrap"><?= e(commission_level_label((int) $row['level'])) ?></td>
                        <td class="text-body-secondary small">
                          <?= e(implode(' · ', array_map('commission_type_label', array_keys($row['types'])))) ?>
                        </td>
                        <td class="text-end"><?= (int) $row['count'] ?></td>
                        <td class="text-end"><?= e(money($row['amount'])) ?></td>
                        <td class="text-end"><?= (int) $row['skipped'] ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($byLevel === []): ?>
                      <tr><td colspan="5" class="text-body-secondary">No awards in this window.</td></tr>
<?php endif; ?>
                    </tbody>
                    <tfoot>
                      <tr class="fw-semibold">
                        <td colspan="2">Window</td>
                        <td class="text-end"><?= (int) $commissions['count'] ?></td>
                        <td class="text-end"><?= e(money($commissions['amount'])) ?></td>
                        <td class="text-end"><?= (int) $commissions['skipped'] ?></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  All time <?= e(money($commissions['all_time'])) ?> · this month <?= e(money($commissions['this_month'])) ?> ·
                  level 1 is the direct referral; levels 2–10 are unilevel.
                </div>
              </div>
