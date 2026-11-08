<?php
declare(strict_types=1);

/**
 * Admin member directory — headline counters.
 *
 * The initial page and the live feed render this same partial, so the five
 * cards can never drift apart.
 *
 * @var array{total:int, active:int, pending:int, suspended:int, with_package:int, new_week:int} $stats
 */

$cards = [
    ['label' => 'Members', 'value' => (string) $stats['total'], 'icon' => 'bx-group', 'tone' => 'primary'],
    ['label' => 'Active', 'value' => (string) $stats['active'], 'icon' => 'bx-check-circle', 'tone' => 'success'],
    ['label' => 'Pending', 'value' => (string) $stats['pending'], 'icon' => 'bx-time-five', 'tone' => 'warning'],
    ['label' => 'Suspended', 'value' => (string) $stats['suspended'], 'icon' => 'bx-block', 'tone' => 'danger'],
    ['label' => 'With a package', 'value' => (string) $stats['with_package'], 'icon' => 'bx-medal', 'tone' => 'info'],
];
?>
              <div class="row g-4 mb-4" data-live-region="member-stats">
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
                      <h5 class="mb-0"><?= e($card['value']) ?></h5>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
