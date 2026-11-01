<?php
/**
 * @file   views/admin/ewallet_monitor.php
 * @brief  Admin E-Wallet Monitoring Dashboard
 */
?>
<?php $pageTitle = 'E-Wallet Monitor'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">📊 E-Wallet Monitor</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Track transfers, top-ups, and fee collections</p>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-xl-3">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-primary"></div>
          <div class="card-body pt-4">
            <div class="stat-label">Total Transfers</div>
            <div class="stat-value text-primary"><?= fmt_money($stats['total_transfers']) ?></div>
            <div class="stat-sub"><?= number_format($stats['transfer_count']) ?> transactions</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-success"></div>
          <div class="card-body pt-4">
            <div class="stat-label">Total Fees Collected</div>
            <div class="stat-value text-success"><?= fmt_money($stats['total_fees']) ?></div>
            <div class="stat-sub">Credited to admin e-wallet</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-warning"></div>
          <div class="card-body pt-4">
            <div class="stat-label">Total Top-Ups</div>
            <div class="stat-value text-warning"><?= fmt_money($stats['total_topups']) ?></div>
            <div class="stat-sub"><?= number_format($stats['topup_count']) ?> top-ups</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-info"></div>
          <div class="card-body pt-4">
            <div class="stat-label">Net Movement</div>
            <div class="stat-value text-info"><?= fmt_money($stats['total_transfers'] + $stats['total_topups']) ?></div>
            <div class="stat-sub">Transfers + Top-ups</div>
          </div>
        </div>
      </div>
    </div>

    <!-- System Balance Overview -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-success"></div>
          <div class="card-body pt-4">
            <div class="stat-label">System Withdrawable</div>
            <div class="stat-value text-success"><?= fmt_money($stats['system_withdrawable']) ?></div>
            <div class="stat-sub">Earned by all members</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-danger"></div>
          <div class="card-body pt-4">
            <div class="stat-label">System Non-Withdrawable</div>
            <div class="stat-value text-danger"><?= fmt_money($stats['system_non_withdrawable']) ?></div>
            <div class="stat-sub">Transfers + top-ups in system</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <div class="card h-100">
          <div class="card-body d-flex align-items-center">
            <div class="flex-grow-1">
              <div style="font-size:.8rem;color:var(--muted);margin-bottom:.5rem;">Total E-Wallet Funds in System</div>
              <div style="font-size:1.8rem;font-weight:800;font-family:var(--font-mono);"><?= fmt_money($stats['system_withdrawable'] + $stats['system_non_withdrawable']) ?></div>
            </div>
            <div style="width:160px;">
              <?php $totalSys = $stats['system_withdrawable'] + $stats['system_non_withdrawable']; ?>
              <?php $wPct = $totalSys > 0 ? ($stats['system_withdrawable'] / $totalSys) * 100 : 0; ?>
              <div class="cap-bar-track mb-1">
                <div class="cap-bar-fill" style="width:<?= $wPct ?>%;background:#12a05c;"></div>
              </div>
              <div class="d-flex justify-content-between" style="font-size:.7rem;color:var(--muted);">
                <span>✅ <?= number_format($wPct, 0) ?>%</span>
                <span>🔒 <?= number_format(100 - $wPct, 0) ?>%</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <?php $tab = $_GET['tab'] ?? 'transfers'; ?>
    <ul class="nav nav-pills mb-3">
      <li class="nav-item">
        <a class="nav-link <?= $tab === 'transfers' ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=admin_ewallet_monitor&tab=transfers">💱 Transfers</a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $tab === 'topups' ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=admin_ewallet_monitor&tab=topups">💰 Top-Ups</a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $tab === 'fees' ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=admin_ewallet_monitor&tab=fees">💸 Fee Credits</a>
      </li>
    </ul>

    <?php if ($tab === 'transfers'): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">💱 All Transfers</span></div>
      <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.85rem;">
          <thead>
            <tr>
              <th>Date</th>
              <th>From</th>
              <th>To</th>
              <th>Amount</th>
              <th>Fee</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($transfers)): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">No transfers yet.</td></tr>
            <?php else: foreach ($transfers as $t): ?>
              <tr>
                <td style="font-size:.75rem;"><?= fmt_datetime($t['created_at']) ?></td>
                <td><a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $t['sender_id'] ?>" class="text-decoration-none fw-semibold">@<?= e($t['sender_username']) ?></a></td>
                <td><a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $t['recipient_id'] ?>" class="text-decoration-none fw-semibold">@<?= e($t['recipient_username']) ?></a></td>
                <td class="font-mono fw-semibold"><?= fmt_money($t['amount']) ?></td>
                <td class="font-mono text-muted"><?= $t['fee'] > 0 ? fmt_money($t['fee']) : '—' ?></td>
                <td class="text-muted" style="font-size:.75rem;"><?= e($t['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif ($tab === 'topups'): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">💰 Admin Top-Ups</span></div>
      <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.85rem;">
          <thead>
            <tr>
              <th>Date</th>
              <th>Admin</th>
              <th>Recipient</th>
              <th>Amount</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($topups)): ?>
              <tr><td colspan="5" class="text-center py-4 text-muted">No top-ups yet.</td></tr>
            <?php else: foreach ($topups as $t): ?>
              <tr>
                <td style="font-size:.75rem;"><?= fmt_datetime($t['created_at']) ?></td>
                <td><a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $t['admin_id'] ?>" class="text-decoration-none fw-semibold">@<?= e($t['admin_username']) ?></a></td>
                <td><a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $t['recipient_id'] ?>" class="text-decoration-none fw-semibold">@<?= e($t['recipient_username']) ?></a></td>
                <td class="font-mono fw-semibold text-success">+<?= fmt_money($t['amount']) ?></td>
                <td class="text-muted" style="font-size:.75rem;"><?= e($t['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php else: ?>
    <div class="card">
      <div class="card-header"><span class="card-title">💸 Fee Credits to Admin</span></div>
      <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.85rem;">
          <thead>
            <tr>
              <th>Date</th>
              <th>From Member</th>
              <th>To Member</th>
              <th>Fee Amount</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($fees)): ?>
              <tr><td colspan="5" class="text-center py-4 text-muted">No fees collected yet.</td></tr>
            <?php else: foreach ($fees as $f): ?>
              <tr>
                <td style="font-size:.75rem;"><?= fmt_datetime($f['created_at']) ?></td>
                <td><a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $f['sender_id'] ?>" class="text-decoration-none fw-semibold">@<?= e($f['sender_username']) ?></a></td>
                <td><a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $f['recipient_id'] ?>" class="text-decoration-none fw-semibold">@<?= e($f['recipient_username']) ?></a></td>
                <td class="font-mono fw-semibold text-success">+<?= fmt_money($f['amount']) ?></td>
                <td class="text-muted" style="font-size:.75rem;"><?= e($f['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
