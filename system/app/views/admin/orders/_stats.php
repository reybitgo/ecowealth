<?php
declare(strict_types=1);

/**
 * Admin order desk — headline counters.
 *
 * The initial page and the live feed render this same partial, so the four
 * cards can never drift apart.
 *
 * @var array{
 *   total:int,
 *   awaiting_payment:int,
 *   in_review:int,
 *   in_progress:int,
 *   completed:int,
 *   cancelled:int,
 *   revenue:float,
 *   today_count:int,
 *   today_total:float
 * } $stats
 */

$cards = [
    ['label' => 'Orders', 'value' => (string) $stats['total'], 'hint' => $stats['today_count'] . ' today', 'icon' => 'bx-receipt', 'tone' => 'primary'],
    ['label' => 'Awaiting payment', 'value' => (string) $stats['awaiting_payment'], 'hint' => 'Manual payments pending', 'icon' => 'bx-time-five', 'tone' => 'warning'],
    ['label' => 'In progress', 'value' => (string) $stats['in_progress'], 'hint' => $stats['completed'] . ' completed', 'icon' => 'bx-package', 'tone' => 'info'],
    ['label' => 'Paid revenue', 'value' => money((float) $stats['revenue']), 'hint' => money((float) $stats['today_total']) . ' today', 'icon' => 'bx-line-chart', 'tone' => 'success'],
];
?>
              <div class="row g-6 mb-6" data-live-region="order-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100 order-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-2 order-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
