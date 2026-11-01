<?php

/**
 * @file   views/partials/settings_offcanvas.php
 * @brief  Right off-canvas settings tab navigation — slides from right like cart.
 *
 * Included from settings.php. The gear trigger button lives in topbar.php.
 * Clicking a tab switches the pane in the main content area and closes the
 * drawer. On any other page, clicking a tab navigates to the settings page
 * with the correct hash.
 */
if (!Auth::isAdmin()) {
    return;
}

$offcanvasTabs = [
    'basics'        => ['icon' => '🌐', 'label' => 'Site Basics'],
    'maint'         => ['icon' => '🛡️', 'label' => 'Maintenance & Security'],
    'payments'      => ['icon' => '🔄', 'label' => 'Payments'],
    'ewallet'       => ['icon' => '💱', 'label' => 'E-Wallet Transfers'],
    'payouts'       => ['icon' => '💸', 'label' => 'Payout Methods'],
    'compensations' => ['icon' => '💰', 'label' => 'Compensations'],
    'password'      => ['icon' => '🔒', 'label' => 'Change Password'],
    'reset'         => ['icon' => '⏱️', 'label' => 'Daily Cap Reset'],
    'documents'     => ['icon' => '🧾', 'label' => 'Invoices & Documents'],
    'overview'      => ['icon' => 'ℹ️', 'label' => 'System Overview'],
];
$isSettingsPage = ($_GET['page'] ?? '') === 'admin_settings';
?>
<div class="offcanvas offcanvas-end" tabindex="-1" id="settingsOffcanvas" aria-labelledby="settingsOffcanvasLabel">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title d-flex align-items-center gap-2" id="settingsOffcanvasLabel">
            <span style="font-size:1.1rem;">⚙️</span> Settings
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-2 d-flex flex-column">
        <nav class="nav flex-column nav-pills settings-offcanvas-nav" role="tablist">
            <?php foreach ($offcanvasTabs as $tid => $t): ?>
                <button class="nav-link settings-offcanvas-link"
                    type="button"
                    data-settings-tab="<?= $tid ?>"
                    data-on-settings="<?= $isSettingsPage ? '1' : '0' ?>">
                    <span class="me-2" style="font-size:1rem;"><?= $t['icon'] ?></span>
                    <span><?= $t['label'] ?></span>
                </button>
            <?php endforeach; ?>
        </nav>
    </div>
    <style>
        .settings-offcanvas-nav .settings-offcanvas-link {
            text-align: left;
            padding: 0.7rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 0.15rem;
            font-size: .85rem;
            color: #475569;
            border: none;
            background: none;
            display: flex;
            align-items: center;
            transition: background .15s, color .15s;
        }

        .settings-offcanvas-nav .settings-offcanvas-link:hover {
            background: #f1f5f9;
        }

        .settings-offcanvas-nav .settings-offcanvas-link.active {
            background: #eef2ff;
            color: #4f46e5;
            font-weight: 600;
        }

        .settings-offcanvas-nav .settings-offcanvas-link.active .me-2 {
            filter: none;
        }
    </style>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var offcanvasEl = document.getElementById('settingsOffcanvas');
        if (!offcanvasEl) return;

        // ── Activate correct tab from URL hash when offcanvas opens ──
        offcanvasEl.addEventListener('show.bs.offcanvas', function() {
            var hash = window.location.hash.replace('#tabPane-', '');
            if (!hash) hash = 'basics';
            document.querySelectorAll('.settings-offcanvas-link').forEach(function(btn) {
                btn.classList.toggle('active', btn.getAttribute('data-settings-tab') === hash);
            });
        });

        // ── Tab click handler ──
        document.querySelectorAll('.settings-offcanvas-link').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var tabId = this.getAttribute('data-settings-tab');
                var onSettings = this.getAttribute('data-on-settings') === '1';

                if (onSettings) {
                    // ── On the settings page: switch pane in main content ──
                    // Deactivate all panes
                    document.querySelectorAll('.tab-pane').forEach(function(p) {
                        p.classList.remove('show', 'active');
                    });
                    // Activate target pane
                    var pane = document.getElementById('tabPane-' + tabId);
                    if (pane) pane.classList.add('show', 'active');
                    // Update nav active state
                    document.querySelectorAll('.settings-offcanvas-link').forEach(function(b) {
                        b.classList.remove('active');
                    });
                    this.classList.add('active');
                    // Update URL hash
                    history.replaceState(null, '', window.location.pathname + window.location.search + '#tabPane-' + tabId);
                    // Close offcanvas
                    var inst = bootstrap.Offcanvas.getInstance(offcanvasEl);
                    if (inst) inst.hide();
                } else {
                    // ── Not on settings page: navigate to settings with hash ──
                    window.location.href = '<?= APP_URL ?>/?page=admin_settings#tabPane-' + tabId;
                }
            });
        });
    });
</script>