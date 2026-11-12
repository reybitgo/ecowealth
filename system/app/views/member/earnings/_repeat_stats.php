<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — the four counters (Phase 17E, plan §9).
 *
 * There is deliberately **no "as buyer" figure** (Rule 9): a member
 * earns nothing on their own purchase, so a tile describing their own
 * spending as earnings would be a lie. Their purchases appear in the
 * two-clock status panel instead, where they belong.
 *
 * @var array{count:int, credited:float, this_month:float, skipped:int, last_at:?string} $totals
 */

$totals = array_merge(
    ['count' => 0, 'credited' => 0.0, 'this_month' => 0.0, 'skipped' => 0, 'last_at' => null],
    $totals ?? []
);

$cards = [
    [
        'label' => 'Total credited',
        'value' => money((float) $totals['credited']),
        'hint'  => $totals['count'] === 0
            ? 'No awards yet'
            : (string) $totals['count'] . ' award' . ($totals['count'] === 1 ? '' : 's') . ' · withdrawable',
        'icon'  => 'bx-line-chart',
        'tone'  => 'success',
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
        'value' => (string) (int) $totals['count'],
        'hint'  => 'Levels that were credited to you',
        'icon'  => 'bx-receipt',
        'tone'  => 'info',
    ],
    [
        'label' => 'Not paid',
        'value' => (string) (int) $totals['skipped'],
        'hint'  => $totals['skipped'] === 0
            ? 'No level was skipped'
            : 'Levels skipped — you were not eligible that month',
        'icon'  => 'bx-block',
        'tone'  => 'warning',
    ],
];
?>
              <div class="row g-4 mb-4" data-live-region="earnings-repeat-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-sm-6 col-xl-3">
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
              </div>
