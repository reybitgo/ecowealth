<?php
declare(strict_types=1);

/**
 * Reports — closed repeat-purchase periods (Phase 17D, plan §8 Rule 10).
 *
 * A read-only history: one row per month the close job snapshotted,
 * with the member count, how many qualified for the following month
 * and the money the ladder paid in that month.
 *
 * Closing a month is an operations job, not a button on this page.
 *
 * @var list<array<string, mixed>> $unilevel_periods
 */

$periods = $unilevel_periods ?? [];

$monthLabel = static function (string $period): string {
    $ts = strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('M Y', $ts);
};
?>
              <div class="card h-100 report-unilevel-periods-card" data-live-region="report-unilevel-periods">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Closed months</h5>
                    <p class="text-body-secondary small mb-0">
                      The Active snapshot that decided who could be paid the following month.
                    </p>
                  </div>
                  <span class="badge bg-label-secondary"><?= count($periods) ?> closed</span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col">Month</th>
                        <th scope="col" class="text-end">Members</th>
                        <th scope="col" class="text-end">Qualified</th>
                        <th scope="col" class="text-end">Award total</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($periods as $period): ?>
                      <tr>
                        <td class="text-nowrap"><?= e($monthLabel((string) $period['period'])) ?></td>
                        <td class="text-end"><?= (int) $period['members_total'] ?></td>
                        <td class="text-end"><?= (int) $period['qualified_count'] ?></td>
                        <td class="text-end"><?= e(money((float) $period['award_total'])) ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($periods === []): ?>
                      <tr>
                        <td colspan="4" class="text-body-secondary">
                          No month has been closed yet. Until a month is closed, a member with no
                          snapshot is treated as Active — the live fallback keeps payouts correct.
                        </td>
                      </tr>
<?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  A closed month is immutable; a cancellation never rewrites its snapshot.
                </div>
              </div>
