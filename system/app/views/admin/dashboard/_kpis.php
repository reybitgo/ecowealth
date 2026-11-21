<?php
declare(strict_types=1);

/**
 * Admin dashboard KPI row.
 *
 * @var array<string, int|float> $stats
 */

$stats = array_merge([
    'accounts'         => 0,
    'accounts_active'  => 0,
    'new_accounts'     => 0,
    'products'         => 0,
    'products_active'  => 0,
    'low_stock'        => 0,
    'out_of_stock'     => 0,
    'stock_units'      => 0,
    'inventory_value'  => 0.0,
    'packages'         => 0,
    'packages_active'  => 0,
    'package_value'    => 0.0,
], $stats ?? []);

$newAccounts = (int) $stats['new_accounts'];
$stockAlerts = (int) $stats['low_stock'] + (int) $stats['out_of_stock'];

$cards = [
    [
        'label'      => 'Total accounts',
        'value'      => number_format((int) $stats['accounts']),
        'icon'       => 'bx-group',
        'tone'       => 'primary',
        'hint'       => (int) $stats['accounts_active'] . ' active account' . ((int) $stats['accounts_active'] === 1 ? '' : 's'),
        'badge'      => $newAccounts > 0 ? '+' . $newAccounts . ' in 30 days' : 'No new accounts',
        'badge_tone' => $newAccounts > 0 ? 'success' : 'secondary',
        'badge_icon' => $newAccounts > 0 ? 'bx-up-arrow-alt' : 'bx-minus',
    ],
    [
        'label'      => 'Products',
        'value'      => number_format((int) $stats['products']),
        'icon'       => 'bx-package',
        'tone'       => 'info',
        'hint'       => (int) $stats['products_active'] . ' active in the catalogue',
        'badge'      => $stockAlerts > 0 ? $stockAlerts . ' stock alert' . ($stockAlerts === 1 ? '' : 's') : 'Stock healthy',
        'badge_tone' => $stockAlerts > 0 ? 'warning' : 'success',
        'badge_icon' => $stockAlerts > 0 ? 'bx-error-circle' : 'bx-check-circle',
    ],
    [
        'label'      => 'Inventory value',
        'value'      => money((float) $stats['inventory_value']),
        'icon'       => 'bx-wallet',
        'tone'       => 'success',
        'hint'       => number_format((int) $stats['stock_units']) . ' units on hand',
        'badge'      => 'Retail value',
        'badge_tone' => 'primary',
        'badge_icon' => 'bx-trending-up',
    ],
    [
        'label'      => 'Packages',
        'value'      => number_format((int) $stats['packages']),
        'icon'       => 'bx-box',
        'tone'       => 'warning',
        'hint'       => money((float) $stats['package_value']) . ' combined entry value',
        'badge'      => (int) $stats['packages_active'] . ' active',
        'badge_tone' => (int) $stats['packages_active'] > 0 ? 'success' : 'secondary',
        'badge_icon' => 'bx-check-circle',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="dashboard-kpis">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100 stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-4">
                        <span class="avatar avatar-md">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?> icon-md"></i>
                          </span>
                        </span>
                        <span class="badge rounded-pill bg-label-<?= e($card['badge_tone']) ?>">
                          <i class="icon-base <?= e(icon_class($card['badge_icon'])) ?> me-1"></i><?= e($card['badge']) ?>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1"><?= e($card['label']) ?></p>
                      <h4 class="mb-2 text-heading stat-value"><?= e($card['value']) ?></h4>
                      <small class="text-body-secondary"><?= e($card['hint']) ?></small>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
