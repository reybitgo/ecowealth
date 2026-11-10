<?php
declare(strict_types=1);

/**
 * Admin payout queue — status tabs with the lifetime counts.
 *
 * @var string $tab
 * @var array{pending:int, approved:int, completed:int, rejected:int, all:int} $tab_counts
 */

$tabs = [
    'pending'   => ['label' => 'Pending', 'icon' => 'bx-time-five'],
    'approved'  => ['label' => 'Approved', 'icon' => 'bx-paper-plane'],
    'completed' => ['label' => 'Completed', 'icon' => 'bx-check-circle'],
    'rejected'  => ['label' => 'Rejected', 'icon' => 'bx-x-circle'],
    ''          => ['label' => 'All requests', 'icon' => 'bx-list-ul'],
];
?>
              <ul class="nav nav-pills mb-4 wallet-tabs" role="tablist" data-live-region="payout-tabs">
<?php foreach ($tabs as $key => $meta): ?>
                <li class="nav-item" role="presentation">
                  <a
                    class="nav-link <?= $tab === $key ? 'active' : '' ?>"
                    href="<?= e(url('admin/payouts' . ($key === '' ? '' : '?status=' . $key))) ?>"
                    role="tab"
                    aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
                  >
                    <i class="icon-base <?= e(icon_class($meta['icon'])) ?> me-1"></i> <?= e($meta['label']) ?>
                    <span class="badge rounded-pill bg-label-primary ms-1"><?= (int) ($tab_counts[$key === '' ? 'all' : $key] ?? 0) ?></span>
                  </a>
                </li>
<?php endforeach; ?>
              </ul>
