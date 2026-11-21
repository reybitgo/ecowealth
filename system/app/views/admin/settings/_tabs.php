<?php
declare(strict_types=1);

/**
 * Admin settings — horizontal section menu.
 *
 * One pill per independently saveable card. The labels are deliberately
 * shorter than some card titles so all eight fit without wrapping; the
 * `title` attribute carries the full card name for hover and assistive
 * technology. Plain anchors work without JavaScript; admin-settings.js
 * adds smooth scrolling and keeps the active pill in step with the
 * section on screen (including a hash left by a save).
 */

$settingsTabs = [
    ['id' => 'settings-general', 'label' => 'General', 'icon' => 'bx-buildings', 'title' => 'General'],
    ['id' => 'settings-shop', 'label' => 'Shop', 'icon' => 'bx-store', 'title' => 'Shop'],
    ['id' => 'settings-accounts', 'label' => 'Accounts', 'icon' => 'bx-receipt', 'title' => 'Receiving accounts'],
    ['id' => 'settings-payouts', 'label' => 'Payouts', 'icon' => 'bx-money-withdraw', 'title' => 'Payout methods'],
    ['id' => 'settings-windows', 'label' => 'Windows', 'icon' => 'bx-time-five', 'title' => 'Payment windows'],
    ['id' => 'settings-fulfilment', 'label' => 'Fulfilment', 'icon' => 'bx-package', 'title' => 'Fulfilment and invoices'],
    ['id' => 'settings-commissions', 'label' => 'Commissions', 'icon' => 'bx-line-chart', 'title' => 'Commissions'],
    ['id' => 'settings-transfers', 'label' => 'Transfers', 'icon' => 'bx-transfer', 'title' => 'Transfers'],
];
?>
              <nav
                class="settings-tabs card mb-6"
                aria-label="Settings sections"
                data-settings-tabs
              >
                <div class="card-body py-2 px-3">
                  <ul class="nav nav-pills settings-tab-list gap-1 flex-nowrap" role="list">
<?php foreach ($settingsTabs as $tab): ?>
                    <li class="nav-item" role="listitem">
                      <a
                        class="nav-link text-nowrap"
                        href="#<?= e($tab['id']) ?>"
                        data-settings-tab="<?= e($tab['id']) ?>"
                        title="<?= e($tab['title']) ?>"
                      >
                        <i class="icon-base <?= e(icon_class($tab['icon'])) ?> me-1"></i><?= e($tab['label']) ?>
                      </a>
                    </li>
<?php endforeach; ?>
                  </ul>
                </div>
              </nav>
