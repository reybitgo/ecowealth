<?php
/**
 * @file   views/admin/cap_monitor.php
 * @brief  Admin cap monitoring page (Phase 6)
 */
?>
<?php $pageTitle = 'Cap Monitoring'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">🛡️ Cap Monitoring</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Track member cap status across the network</p>
      </div>
    </div>

    <!-- Stats cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-4">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-success"></div>
          <div class="card-body pt-4">
            <div class="stat-icon bg-success-subtle">✅</div>
            <div class="stat-label">Active Members</div>
            <div class="stat-value text-success"><?= number_format($stats['active']) ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-warning"></div>
          <div class="card-body pt-4">
            <div class="stat-icon bg-warning-subtle">⚠️</div>
            <div class="stat-label">Capped Members</div>
            <div class="stat-value text-warning"><?= number_format($stats['capped']) ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stat-card h-100">
          <div class="stat-accent stat-accent-danger"></div>
          <div class="card-body pt-4">
            <div class="stat-icon bg-danger-subtle">⛔</div>
            <div class="stat-label">Permanently Inactive</div>
            <div class="stat-value text-danger"><?= number_format($stats['perminact']) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Status tabs -->
    <ul class="nav nav-pills mb-3">
      <?php foreach (['' => '📋 All', 'active' => '✅ Active', 'capped' => '⚠️ Capped', 'perminact' => '⛔ Permanent'] as $s => $label): ?>
        <li class="nav-item">
          <a class="nav-link <?= $status === $s ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=admin_cap_monitor&status=<?= $s ?>">
            <?= $label ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- Members table -->
    <div class="card">
      <div class="card-header">
        <form method="GET" action="<?= APP_URL ?>/" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <input type="hidden" name="page" value="admin_cap_monitor">
          <input type="hidden" name="status" value="<?= e($status) ?>">
          <div class="d-flex align-items-center gap-2">
            <span class="card-title">Members</span>
            <span class="badge bg-secondary-subtle text-secondary"><?= $result['total'] ?> records</span>
          </div>

          <!-- Rows per page -->
          <div class="d-flex align-items-center gap-2">
            <label for="perPageSelect" class="form-label mb-0 text-muted" style="font-size:.78rem;white-space:nowrap;">Rows per page</label>
            <select id="perPageSelect" name="per_page" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
              <?php foreach ([5, 10, 25, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.85rem;">
          <thead>
            <tr>
              <th>Member</th>
              <th>Package</th>
              <th>Cap Status</th>
              <th>Lifetime Earned</th>
              <th>Cap</th>
              <th>Progress</th>
              <th>Capped At</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($result['data'])): ?>
              <tr>
                <td colspan="8" class="text-center py-5 text-muted">No members found.</td>
              </tr>
            <?php else: foreach ($result['data'] as $row):
                $pct = $row['lifetime_cap'] > 0 ? min(100, (($row['lifetime_earned'] ?? 0) / $row['lifetime_cap']) * 100) : 0;
                $statusBadge = match ($row['cap_status']) {
                    'active'    => '<span class="badge bg-success-subtle text-success">✅ Active</span>',
                    'capped'    => '<span class="badge bg-warning-subtle text-warning">⚠️ Capped</span>',
                    'perminact' => '<span class="badge bg-danger-subtle text-danger">⛔ Permanent</span>',
                    default     => '<span class="badge bg-secondary-subtle text-secondary">' . e($row['cap_status']) . '</span>',
                };
            ?>
              <tr>
                <td>
                  <a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $row['id'] ?>" class="fw-bold text-decoration-none">@<?= e($row['username']) ?></a>
                  <div class="text-muted" style="font-size:.72rem;"><?= e($row['full_name'] ?? '') ?></div>
                  <?php if (!empty($row['capping_bypass'])): ?>
                    <span class="badge bg-warning text-dark" style="font-size:.6rem;">🏆 VIP</span>
                  <?php endif; ?>
                  <?php if (!empty($row['daily_cap_bypass'])): ?>
                    <span class="badge bg-info" style="font-size:.6rem;">⚡ Daily</span>
                  <?php endif; ?>
                </td>
                <td><?= e($row['package_name'] ?? '—') ?></td>
                <td><?= $statusBadge ?></td>
                <td class="font-mono fw-semibold"><?= fmt_money($row['lifetime_earned']) ?></td>
                <td class="font-mono text-muted"><?= fmt_money($row['lifetime_cap']) ?></td>
                <td style="min-width:140px;">
                  <div class="d-flex align-items-center gap-2">
                    <div class="cap-bar-track" style="flex:1;height:6px;">
                      <div class="cap-bar-fill <?= $pct >= 100 ? 'full' : '' ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span style="font-size:.68rem;min-width:32px;text-align:right;"><?= number_format($pct, 0) ?>%</span>
                  </div>
                </td>
                <td class="td-muted" style="font-size:.75rem;"><?= $row['capped_at'] ? fmt_datetime($row['capped_at']) : '—' ?></td>
                <td>
                  <a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $row['id'] ?>&tab=cap_dfi" class="btn btn-sm btn-outline-primary">View</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($result['total_pages'] > 1): ?>
        <div class="card-footer">
          <?= pagination_links($result, APP_URL . '/?page=admin_cap_monitor&status=' . urlencode($status) . '&per_page=' . $perPage) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
