<?php
declare(strict_types=1);

/**
 * Admin commission ledger — headline counters.
 *
 * @var array{
 *   credited_total:float, this_month:float, credited:int, skipped:int,
 *   recipients:int, average:float, top_level:int
 * } $stats
 */

$cards = [
    [
        'label' => 'Total credited',
        'value' => money($stats['credited_total']),
        'hint'  => (int) $stats['recipients'] . ' member' . ($stats['recipients'] === 1 ? '' : 's') . ' paid',
        'icon'  => 'bx-line-chart',
        'tone'  => 'success',
    ],
    [
        'label' => 'This month',
        'value' => money($stats['this_month']),
        'hint'  => 'Since the 1st',
        'icon'  => 'bx-calendar',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Awards',
        'value' => (string) $stats['credited'],
        'hint'  => $stats['credited'] === 0 ? 'Nothing paid yet' : 'Average ' . money($stats['average']),
        'icon'  => 'bx-receipt',
        'tone'  => 'info',
    ],
    [
        'label' => 'Skipped',
        'value' => (string) $stats['skipped'],
        'hint'  => 'Ineligible upline — no money moved',
        'icon'  => 'bx-block',
        'tone'  => 'warning',
    ],
];
?>
              <div class="row g-4 mb-4" data-live-region="commission-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
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
