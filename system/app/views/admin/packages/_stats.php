<?php
declare(strict_types=1);

/**
 * Admin package list — the three counters.
 *
 * @var array{total:int, active:int, inactive:int} $stats
 */

$cards = [
    ['label' => 'Packages', 'value' => $stats['total'], 'icon' => 'bx-box', 'tone' => 'primary'],
    ['label' => 'Active', 'value' => $stats['active'], 'icon' => 'bx-check-circle', 'tone' => 'success'],
    ['label' => 'Inactive', 'value' => $stats['inactive'], 'icon' => 'bx-pause-circle', 'tone' => 'secondary'],
];
?>
              <div class="row g-6 mb-6" data-live-region="package-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1"><?= e($card['label']) ?></p>
                      <h4 class="mb-0"><?= (int) $card['value'] ?></h4>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
