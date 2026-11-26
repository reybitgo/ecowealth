<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — the counters (Phase 17E, plan §9;
 * Phase 18 escrow tile, plan §10.3).
 *
 * Team chain directed: every counter describes the income the member
 * earned from their Team chain's paid orders. There is deliberately
 * **no "as buyer" figure**: a member earns nothing on their own
 * purchase, so a tile describing their own spending as earnings would
 * be a lie. Their purchases appear in the two-clock status panel
 * instead, where they belong.
 *
 * Phase 18 splits the money in two: **Total credited** is released
 * money (in the wallet), **Pending release** is earned money that is
 * still outside it. The footer line names the next promised release
 * date when one exists, and otherwise the hold the member can expect
 * (plan §10.3) — never an invented date before delivery.
 *
 * @var array{
 *   count:int, credited:float, this_month:float, skipped:int, last_at:?string,
 *   pending:float, pending_count:int, next_release_at:?string
 * } $totals
 */

$totals = array_merge(
    [
        'count'           => 0,
        'credited'        => 0.0,
        'this_month'      => 0.0,
        'skipped'         => 0,
        'last_at'         => null,
        'pending'         => 0.0,
        'pending_count'   => 0,
        'next_release_at' => null,
    ],
    $totals ?? []
);

$credited     = round((float) $totals['credited'], 2);
$pending      = round((float) $totals['pending'], 2);
$pendingCount = (int) $totals['pending_count'];
$awardCount   = (int) $totals['count'] + $pendingCount;
$nextRelease  = $totals['next_release_at'];
$hasNext      = $nextRelease !== null && trim((string) $nextRelease) !== '';

$cards = [
    [
        'label' => 'Total credited',
        'value' => money($credited),
        'hint'  => (int) $totals['count'] === 0
            ? 'No credited awards yet'
            : (string) $totals['count'] . ' award' . ((int) $totals['count'] === 1 ? '' : 's') . ' · in your wallet',
        'icon'  => 'bx-line-chart',
        'tone'  => 'success',
    ],
    [
        'label' => 'Pending release',
        'value' => money($pending),
        'hint'  => $pendingCount === 0
            ? 'Nothing pending'
            : $pendingCount . ' award' . ($pendingCount === 1 ? '' : 's') . ' · not withdrawable yet',
        'icon'  => 'bx-time-five',
        'tone'  => 'info',
    ],
    [
        'label' => 'This month',
        'value' => money((float) $totals['this_month']),
        'hint'  => $totals['last_at'] === null ? 'Nothing credited yet' : 'Last award ' . time_ago((string) $totals['last_at']),
        'icon'  => 'bx-calendar',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Awards',
        'value' => (string) $awardCount,
        'hint'  => $pendingCount === 0
            ? (int) $totals['count'] . ' credited'
            : (int) $totals['count'] . ' credited · ' . $pendingCount . ' pending release',
        'icon'  => 'bx-receipt',
        'tone'  => 'info',
    ],
    [
        'label' => 'Not paid',
        'value' => (string) (int) $totals['skipped'],
        'hint'  => (int) $totals['skipped'] === 0
            ? 'No level was skipped'
            : 'Your level was skipped — you were not eligible that month',
        'icon'  => 'bx-block',
        'tone'  => 'warning',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="earnings-repeat-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-sm-6 col-lg-4 col-xl">
                  <?= View::partial('stat_card', $card) ?>
                </div>
<?php endforeach; ?>
<?php if ($pendingCount > 0): ?>
                <div class="col-12">
                  <p class="small text-body-secondary mb-0 mt-2">
                    <i class="icon-base bx bx-calendar-check me-1"></i>
<?php if ($hasNext): ?>
                    Next release: <strong><?= e(format_date((string) $nextRelease)) ?></strong>.
<?php else: ?>
                    Pending awards on orders still in transit are released after delivery
                    + <strong><?= UnilevelService::holdDays() ?> days</strong>.
<?php endif; ?>
                    Pending amounts become withdrawable after the return window.
                  </p>
                </div>
<?php endif; ?>
              </div>
