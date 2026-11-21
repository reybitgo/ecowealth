<?php
declare(strict_types=1);

/**
 * Admin product stats region.
 *
 * The initial page and the live feed render this same partial, so the
 * four cards can never drift apart. The wrapper is the live region the
 * client replaces by outerHTML.
 *
 * @var array{total:int, active:int, inactive:int, out_of_stock:int} $stats
 */

$cards = [
    ['label' => 'Products', 'value' => $stats['total'], 'icon' => 'bx-package', 'tone' => 'primary'],
    ['label' => 'Active', 'value' => $stats['active'], 'icon' => 'bx-check-circle', 'tone' => 'success'],
    ['label' => 'Inactive', 'value' => $stats['inactive'], 'icon' => 'bx-pause-circle', 'tone' => 'secondary'],
    ['label' => 'Out of stock', 'value' => $stats['out_of_stock'], 'icon' => 'bx-error-circle', 'tone' => 'warning'],
];
?>
              <div class="row g-6 mb-6" data-live-region="product-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
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
