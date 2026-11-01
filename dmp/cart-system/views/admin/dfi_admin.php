<?php
/**
 * @file   views/admin/dfi_admin.php
 * @brief  Admin DFI monitoring & control (Phase 3)
 */
?>
<?php $pageTitle = 'Daily Fixed Income Admin'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">📅 Daily Fixed Income Admin</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Global DFI statistics and controls</p>
      </div>
      <a href="<?= APP_URL ?>/?page=admin_settings" class="btn btn-outline-primary btn-sm">← Back to Settings</a>
    </div>

    <!-- Stats cards -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-md-4">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-primary"></div>
          <div class="card-body pt-4">
            <div class="stat-icon bg-primary-subtle">📅</div>
            <div class="stat-label">DFI Paid Today</div>
            <div class="stat-value text-primary"><?= fmt_money($todayDfi) ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-success"></div>
          <div class="card-body pt-4">
            <div class="stat-icon bg-success-subtle">💰</div>
            <div class="stat-label">Total DFI Paid (All Time)</div>
            <div class="stat-value text-success"><?= fmt_money($totalDfi) ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-warning"></div>
          <div class="card-body pt-4">
            <div class="stat-icon bg-warning-subtle">👥</div>
            <div class="stat-label">Members with DFI</div>
            <div class="stat-value text-warning"><?= number_format($totalMembers) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Per-package control note -->
    <div class="card">
      <div class="card-header"><span class="card-title">📊 DFI Package Control</span></div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3">
          <div>
            <div class="fw-semibold">DFI is controlled per package</div>
            <div class="text-muted" style="font-size:.8rem;">
              Enable or disable Daily Fixed Income for each package from
              <a href="<?= APP_URL ?>/?page=admin_packages">Packages &rarr; Commission Toggles</a>.
              The nightly payout only credits members whose package has DFI enabled.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
