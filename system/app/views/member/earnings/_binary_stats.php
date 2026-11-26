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
 * @var array{
 *   activation_credited_total:float, activation_pairs:int, activation_points:int,
 *   order_credited_total:float, order_pairs:int, order_points:int,
 *   ...
 * } $stats
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

$stats = array_merge(
    [
        'activation_credited_total' => 0.0,
        'activation_pairs'          => 0,
        'activation_points'         => 0,
        'order_credited_total'      => 0.0,
        'order_pairs'               => 0,
        'order_points'              => 0,
    ],
    $stats ?? []
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

$activationCredited = round((float) $stats['activation_credited_total'], 2);
$activationPairs    = (int) $stats['activation_pairs'];
$activationPoints   = (int) $stats['activation_points'];
$orderCredited      = round((float) $stats['order_credited_total'], 2);
$orderPairs         = (int) $stats['order_pairs'];
$orderPoints        = (int) $stats['order_points'];

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
    [
        'label' => 'Repeat purchases',
        'value' => money($orderCredited),
        'hint'  => $orderPairs === 0
            ? 'No product orders placed points yet'
            : $orderPairs . ' pair' . ($orderPairs === 1 ? '' : 's') . ' · ' . (string) $orderPoints . ' pts',
        'icon'  => 'bx-cart',
        'tone'  => 'info',
    ],
    [
        'label' => 'Package activations',
        'value' => money($activationCredited),
        'hint'  => $activationPairs === 0
            ? 'No package activation placed points yet'
            : $activationPairs . ' pair' . ($activationPairs === 1 ? '' : 's') . ' · ' . (string) $activationPoints . ' pts',
        'icon'  => 'bx-package',
        'tone'  => 'info',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="earnings-binary-stats">
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
                    + <strong><?= BinaryService::holdDays() ?> days</strong>.
<?php endif; ?>
                    Pending amounts become withdrawable after the return window.
                  </p>
                </div>
<?php endif; ?>
              </div>
