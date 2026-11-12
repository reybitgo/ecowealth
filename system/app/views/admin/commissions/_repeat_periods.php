<?php
declare(strict_types=1);

/**
 * Admin repeat-purchase ledger — closed months (Phase 17D, plan §8 Rule 10).
 *
 * The period card is a history, not a button: it reads
 * `unilevel_periods`, which only the monthly close writes. Closing a
 * month is an operations job (Phase 17F), so this card never offers an
 * action, only the snapshots it already has (D-14).
 *
 * @var list<array<string, mixed>> $periods
 */

$periods = $periods ?? [];

$monthLabel = static function (string $period): string {
    $ts = strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('M Y', $ts);
};
?>
              <div class="card mb-4" data-live-region="repeat-periods">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Closed months</h5>
                    <p class="text-body-secondary small mb-0">
                      The Active snapshot behind the monthly gate. A closed month is never rewritten.
                    </p>
                  </div>
                  <span class="badge bg-label-secondary"><?= count($periods) ?> closed</span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Month</th>
                        <th scope="col" class="text-end">Members</th>
                        <th scope="col" class="text-end">Qualified</th>
                        <th scope="col" class="text-end">Award total</th>
                        <th scope="col" class="text-end">Closed</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($periods as $period): ?>
                      <tr>
                        <td class="text-nowrap fw-medium"><?= e($monthLabel((string) $period['period'])) ?></td>
                        <td class="text-end"><?= (int) $period['members_total'] ?></td>
                        <td class="text-end"><?= (int) $period['qualified_count'] ?></td>
                        <td class="text-end"><?= e(money((float) $period['award_total'])) ?></td>
                        <td class="text-end small text-body-secondary text-nowrap">
                          <?= e(format_datetime((string) $period['closed_at'])) ?>
                        </td>
                      </tr>
<?php endforeach; ?>
<?php if ($periods === []): ?>
                      <tr>
                        <td colspan="5" class="text-body-secondary">
                          No month has been closed yet. Until a month is closed, a member with no snapshot
                          is treated as Active — the live fallback keeps payouts correct.
                        </td>
                      </tr>
<?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  Closing a month is an operations job; this card reads the snapshots it leaves behind.
                </div>
              </div>
