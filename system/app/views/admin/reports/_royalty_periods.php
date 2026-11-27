<?php
declare(strict_types=1);

/**
 * Reports — closed Royalty months (Phase 19D).
 *
 * A read-only history: one row per month the close job snapshotted,
 * with the award count, how many reached a wallet, how many were
 * skipped and the two money totals. Closing a month is an operations
 * job, not a button on this page.
 *
 * @var list<array{
 *   period:string, status:string, award_count:int, paid_count:int,
 *   skipped_count:int, award_total:float, paid_total:float
 * }> $royalty_periods
 */

$periods = $royalty_periods ?? [];

$monthLabel = static fn (string $period): string => format_month($period, $period);
?>
              <div class="card h-100 report-royalty-periods-card" data-live-region="report-royalty-periods">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Royalty months</h5>
                    <p class="text-body-secondary small mb-0">
                      Every month the close job evaluated. A closed month is never rewritten.
                    </p>
                  </div>
                  <span class="badge bg-label-secondary"><?= count($periods) ?> closed</span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col">Month</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Awards</th>
                        <th scope="col" class="text-end">Paid</th>
                        <th scope="col" class="text-end">Skipped</th>
                        <th scope="col" class="text-end">Award total</th>
                        <th scope="col" class="text-end">Paid total</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($periods as $period): ?>
                      <tr>
                        <td class="text-nowrap"><?= e($monthLabel((string) $period['period'])) ?></td>
                        <td><?= status_badge((string) $period['status'], RoyaltyPeriod::STATUS_BADGES) ?></td>
                        <td class="text-end"><?= (int) $period['award_count'] ?></td>
                        <td class="text-end"><?= (int) $period['paid_count'] ?></td>
                        <td class="text-end"><?= (int) $period['skipped_count'] ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $period['award_total'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $period['paid_total'])) ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($periods === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'compact' => true,
        'icon'    => 'bx bx-calendar',
        'title'   => 'No Royalty month has closed yet',
        'text'    => 'The first period closes after the first full month following installation.',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  Royalty paid ÷ paid sales is reported above. A cancellation after the close never rewrites a month.
                </div>
              </div>
