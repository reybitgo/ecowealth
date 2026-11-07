<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — tab bar with the total counts.
 *
 * @var string $tab
 * @var array<string, int> $tab_counts
 */

$tabs = [
    'members' => ['label' => 'Members', 'icon' => 'bx-group'],
    'topups'  => ['label' => 'Top-ups', 'icon' => 'bx-plus-circle'],
    'ledger'  => ['label' => 'All movements', 'icon' => 'bx-transfer'],
];
?>
              <ul class="nav nav-pills mb-4 wallet-tabs" role="tablist" data-live-region="wallet-tabs">
<?php foreach ($tabs as $key => $meta): ?>
                <li class="nav-item" role="presentation">
                  <a
                    class="nav-link <?= $tab === $key ? 'active' : '' ?>"
                    href="<?= e(url('admin/wallets?tab=' . $key)) ?>"
                    role="tab"
                    aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
                  >
                    <i class="icon-base <?= e(icon_class($meta['icon'])) ?> me-1"></i> <?= e($meta['label']) ?>
                    <span class="badge rounded-pill bg-label-primary ms-1"><?= (int) ($tab_counts[$key] ?? 0) ?></span>
                  </a>
                </li>
<?php endforeach; ?>
              </ul>
