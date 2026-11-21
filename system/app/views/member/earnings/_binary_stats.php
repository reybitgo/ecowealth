<?php
declare(strict_types=1);

/**
 * Member Product binary statement — the counters (22F, plan §10,
 * Appendix C.8).
 *
 * Every counter describes income the member earned: their own Self
 * Bonus and the pairs their Left and Right teams produced. No upline
 * figure appears here (plan §14.12, D-22).
 *
 * @var array{
 *   count:int, credited:float, pending:float, pending_count:int,
 *   cancelled:int, cancelled_total:float, voided:int, voided_total:float,
 *   self_total:float, pair_total:float, self_count:int, pair_count:int,
 *   last_at:?string, total_awarded:float, next_release_at:?string
 * } $totals
 * @var array{
 *   total:float, count:int, next_release_at:?string, undelivered:int,
 *   overdue:int, release_enabled:bool
 * } $pending
 */

$totals = array_merge(
    [
        'count'            => 0,
        'credited'         => 0.0,
        'pending'          => 0.0,
        'pending_count'    => 0,
        'cancelled'        => 0,
        'cancelled_total'  => 0.0,
        'voided'           => 0,
        'voided_total'     => 0.0,
        'self_total'       => 0.0,
        'pair_total'       => 0.0,
        'self_count'       => 0,
        'pair_count'       => 0,
        'last_at'          => null,
        'total_awarded'    => 0.0,
        'next_release_at'  => null,
    ],
    $totals ?? []
);

$pending = array_merge(
    [
        'total'           => 0.0,
        'count'           => 0,
        'next_release_at' => null,
        'undelivered'     => 0,
        'overdue'         => 0,
        'release_enabled' => true,
    ],
    $pending ?? []
);

$credited     = round((float) $totals['credited'], 2);
$pendingAmt   = round((float) $totals['pending'], 2);
$pendingCount = (int) $totals['pending_count'];
$selfTotal    = round((float) $totals['self_total'], 2);
$pairTotal    = round((float) $totals['pair_total'], 2);
$selfCount    = (int) $totals['self_count'];
$pairCount    = (int) $totals['pair_count'];
$awardCount   = (int) $totals['count'] + $pendingCount;
$nextRelease  = $totals['next_release_at'] ?? $pending['next_release_at'];
$hasNext      = $nextRelease !== null && trim((string) $nextRelease) !== '';

$cards = [
    [
        'label' => 'Total credited',
        'value' => money($credited),
        'hint'  => (int) $totals['count'] === 0
            ? 'No credited awards yet'
            : (int) $totals['count'] . ' award' . ((int) $totals['count'] === 1 ? '' : 's') . ' · in your wallet',
        'icon'  => 'bx-line-chart',
        'tone'  => 'success',
    ],
    [
        'label' => 'Pending release',
        'value' => money($pendingAmt),
        'hint'  => $pendingCount === 0
            ? 'Nothing pending'
            : $pendingCount . ' award' . ($pendingCount === 1 ? '' : 's') . ' · not withdrawable yet',
        'icon'  => 'bx-time-five',
        'tone'  => 'info',
    ],
    [
        'label' => 'Your self bonus',
        'value' => money($selfTotal),
        'hint'  => $selfCount === 0
            ? 'You earn on your own purchase: 5% of its points'
            : $selfCount . ' order' . ($selfCount === 1 ? '' : 's') . ' · paid on your own purchase',
        'icon'  => 'bx-receipt',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Your pairs',
        'value' => money($pairTotal),
        'hint'  => $pairCount === 0
            ? 'No pair matched yet'
            : $pairCount . ' pair' . ($pairCount === 1 ? '' : 's') . ' · 1,000 points each side',
        'icon'  => 'bx-git-compare',
        'tone'  => 'info',
    ],
    [
        'label' => 'Awards',
        'value' => (string) $awardCount,
        'hint'  => $pendingCount === 0
            ? (int) $totals['count'] . ' credited'
            : (int) $totals['count'] . ' credited · ' . $pendingCount . ' pending release',
        'icon'  => 'bx-award',
        'tone'  => 'info',
    ],
];
?>
              <div class="row g-4 mb-4" data-live-region="earnings-binary-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-sm-6 col-lg-4 col-xl">
                  <div class="card h-100 commission-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small text-end"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-2 commission-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
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
                    + <strong><?= BinaryService::holdDays() ?> days</strong>.
<?php endif; ?>
                    Pending amounts become withdrawable after the return window.
                  </p>
                </div>
<?php endif; ?>
              </div>
