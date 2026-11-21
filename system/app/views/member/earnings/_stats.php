<?php
declare(strict_types=1);

/**
 * Member earnings statement — the four summary cards.
 *
 * A live region: a credited or skipped award bumps the per-member
 * earnings surface, so the totals, the month figure and the "last
 * award" age refresh in place.
 *
 * @var array{
 *   count:int, credited:float, this_month:float, direct:float,
 *   indirect:float, skipped:int, last_at:?string
 * } $totals
 */

$cards = [
    [
        'label' => 'Total credited',
        'value' => money($totals['credited']),
        'hint'  => $totals['count'] === 0
            ? 'No awards yet'
            : (string) $totals['count'] . ' award' . ($totals['count'] === 1 ? '' : 's') . ' · withdrawable',
        'icon'  => 'bx-line-chart',
        'tone'  => 'success',
    ],
    [
        'label' => 'This month',
        'value' => money($totals['this_month']),
        'hint'  => $totals['last_at'] === null ? 'Nothing credited yet' : 'Last award ' . time_ago($totals['last_at']),
        'icon'  => 'bx-calendar',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Direct referral',
        'value' => money($totals['direct']),
        'hint'  => 'Level 1 — your personally sponsored associates',
        'icon'  => 'bx-user-plus',
        'tone'  => 'info',
    ],
    [
        'label' => 'Indirect referral',
        'value' => money($totals['indirect']),
        'hint'  => 'Levels 2–10 of the unilevel ladder',
        'icon'  => 'bx-git-branch',
        'tone'  => 'warning',
    ],
];
?>
              <div class="row g-4 mb-4" data-live-region="earnings-stats">
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
                        <span class="text-body-secondary small"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-2 commission-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
