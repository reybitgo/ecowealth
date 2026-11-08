<?php
declare(strict_types=1);

/**
 * Admin registration codes — headline counters.
 *
 * @var array{total:int, unused:int, used:int, expired:int, revenue:float} $stats
 */

$cards = [
    ['label' => 'Total codes', 'value' => (string) $stats['total'], 'icon' => 'bx-key', 'tone' => 'primary'],
    ['label' => 'Unused', 'value' => (string) $stats['unused'], 'icon' => 'bx-check-circle', 'tone' => 'success'],
    ['label' => 'Used', 'value' => (string) $stats['used'], 'icon' => 'bx-check-shield', 'tone' => 'info'],
    ['label' => 'Expired', 'value' => (string) $stats['expired'], 'icon' => 'bx-time-five', 'tone' => 'danger'],
    ['label' => 'Revenue (used)', 'value' => money($stats['revenue']), 'icon' => 'bx-line-chart', 'tone' => 'warning'],
];
?>
              <div class="row g-4 mb-4" data-live-region="code-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1 small"><?= e($card['label']) ?></p>
                      <h5 class="mb-0 text-truncate"><?= e($card['value']) ?></h5>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
