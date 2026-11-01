<?php

/**
 * @file   views/admin/dashboard.php
 * @brief  Admin Dashboard UI
 */
?>
<?php
$pageTitle = 'Admin Dashboard';
$totalCommissions = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='credited'")->fetchColumn();
$totalEwallets    = (float)db()->query("SELECT COALESCE(SUM(ewallet_balance),0) FROM users WHERE role='member'")->fetchColumn();
$recentJoins      = db()->query("SELECT u.*, p.name AS package_name FROM users u LEFT JOIN packages p ON p.id=u.package_id WHERE u.role='member' ORDER BY u.joined_at DESC LIMIT 6")->fetchAll();
$lastReset        = setting('last_reset');
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="row g-3 mb-3">
      <?php foreach (
        [
          ['Total Members',    number_format((int)$memberCounts['total']),   'primary', '👥', '+' . number_format((int)$memberCounts['joined_today']) . ' today · ' . number_format((int)$memberCounts['active']) . ' active'],
          ['Seat Capacity',    number_format((int)seatsRemaining()),         'warning', '🪑', 'Limit: ' . number_format((int)setting('seat_limit', '1000')) . ' · ' . number_format((int)$memberCounts['total']) . ' filled'],
          ['Code Revenue',     fmt_money($codeStat['revenue']),              'success', '💰', number_format($codeStat['used']) . ' codes sold'],
          ['Pending Payouts',  fmt_money($pendingPayout),                    'warning', '💸', '<a href="' . APP_URL . '/?page=admin_payouts" class="text-decoration-none fw-semibold" style="font-size:.72rem;">Review →</a>'],
          ['Total Paid Out',   fmt_money($totalPaid),                        'purple', '✅', 'Completed payouts'],
          ['Commissions Paid', fmt_money($totalCommissions),                 'success', '🏆', 'All credited bonuses'],
          ['E-Wallet Holdings', fmt_money($totalEwallets),                    'primary', '🏦', 'Sum of all balances'],
          ['Unused Codes',     number_format($codeStat['unused']),           'orange', '🎟️', '<a href="' . APP_URL . '/?page=admin_codes" class="text-decoration-none fw-semibold" style="font-size:.72rem;">Manage →</a>'],
          ['Pending Activation', number_format((int)($memberCounts['pending'] ?? 0)), 'warning', '⏳', '<a href="' . APP_URL . '/?page=admin_users&status=pending" class="text-decoration-none fw-semibold" style="font-size:.72rem;">View →</a>'],
          ['Suspended',        number_format((int)$memberCounts['suspended']), 'danger', '🚫', '<a href="' . APP_URL . '/?page=admin_users&status=suspended" class="text-decoration-none fw-semibold" style="font-size:.72rem;">View →</a>'],
          ['Capped Members',     number_format($v2Stats['capped']),             'warning', '⚠️', '<a href="' . APP_URL . '/?page=admin_cap_monitor&status=capped" class="text-decoration-none fw-semibold" style="font-size:.72rem;">View →</a>'],
          ['Permanently Inactive', number_format($v2Stats['perminact']),        'danger', '⛔', '<a href="' . APP_URL . '/?page=admin_cap_monitor&status=perminact" class="text-decoration-none fw-semibold" style="font-size:.72rem;">View →</a>'],
          ['Reactivation Revenue', fmt_money($v2Stats['react_revenue']),        'success', '🔄', 'Completed reactivations'],
          ['DFI Paid Today',     fmt_money($v2Stats['dfi_today']),              'primary', '📅', '<a href="' . APP_URL . '/?page=admin_dfi" class="text-decoration-none fw-semibold" style="font-size:.72rem;">View →</a>'],
        ] as [$label, $val, $accent, $icon, $sub]
      ): ?>
        <div class="col-6 col-xl-3">
          <div class="card stat-card h-100">
            <div class="stat-accent stat-accent-<?= $accent ?>"></div>
            <div class="card-body pt-4">
              <div class="stat-label"><?= $label ?></div>
              <div class="stat-value"><?= $val ?></div>
              <div class="stat-sub"><?= $sub ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3">
      <!-- Pending payouts -->
      <div class="col-12 col-lg-6">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="card-title">⏳ Pending Payout Requests</span>
            <a href="<?= APP_URL ?>/?page=admin_payouts" class="btn btn-outline-primary btn-sm" style="font-size:.72rem;">View all</a>
          </div>
          <?php if (empty($pendingList)): ?>
            <div class="card-body text-center py-4 text-muted">
              <div style="font-size:2rem;">🎉</div>
              <p class="mt-2 mb-0" style="font-size:.85rem;">No pending requests.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead>
                  <tr>
                    <th>Member</th>
                    <th>Amount</th>
                    <th>Method / Account</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pendingList as $pr):
                    $method  = $pr['payout_method']  ?: 'gcash';
                    $account = $pr['payout_account']  ?: '—';
                    $mLabel  = match ($method) {
                      'maya'        => 'Maya',
                      'usdt_trc20'  => 'USDT TRC20',
                      'usdt_bep20'  => 'USDT BEP20',
                      default       => 'GCash'
                    };
                    $mColor  = match ($method) {
                      'maya'        => '#48b0db',
                      'usdt_trc20'  => '#26a17b',
                      'usdt_bep20'  => '#f0b90b',
                      default       => '#0070d8'
                    };
                  ?>
                    <tr>
                      <td>
                        <div class="fw-600" style="font-size:.825rem;">@<?= e($pr['username']) ?></div>
                        <div class="text-muted" style="font-size:.72rem;"><?= fmt_date($pr['requested_at']) ?></div>
                      </td>
                      <td class="td-green font-mono fw-bold"><?= fmt_money($pr['amount']) ?></td>
                      <td>
                        <span class="badge" style="background:<?= $mColor ?>20;color:<?= $mColor ?>;border:1px solid <?= $mColor ?>40;font-size:.65rem;"><?= $mLabel ?></span>
                        <div class="font-mono" style="font-size:.72rem;color:#6b7a99;"><?= e($account) ?></div>
                      </td>
                      <td><a href="<?= APP_URL ?>/?page=admin_payouts" class="btn btn-sm btn-primary">Review</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent joins -->
      <div class="col-12 col-lg-6">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="card-title">🆕 Recent Members</span>
            <a href="<?= APP_URL ?>/?page=admin_users" class="btn btn-outline-primary btn-sm" style="font-size:.72rem;">View all</a>
          </div>
          <div class="card-body py-0 px-3">
            <?php foreach ($recentJoins as $m): ?>
              <div class="activity-item">
                <div class="user-avatar"><?= strtoupper(substr($m['username'], 0, 1)) ?></div>
                <div class="flex-grow-1 min-w-0">
                  <div class="activity-desc">@<?= e($m['username']) ?><?= $m['full_name'] ? ' · ' . e($m['full_name']) : '' ?></div>
                  <div class="activity-meta"><?= e($m['package_name'] ?? 'Member') ?> · <?= fmt_datetime($m['joined_at']) ?></div>
                </div>
                <a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $m['id'] ?>" class="btn btn-outline-secondary btn-sm">View</a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Reset bar -->
    <div class="card mt-3">
      <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2 py-3">
        <div style="font-size:.875rem;"><strong>Daily Pair Cap Reset</strong> <span class="text-muted ms-2" style="font-size:.78rem;">Last run: <?= $lastReset ? fmt_datetime($lastReset) : 'Never' ?></span></div>
        <form method="POST" action="<?= APP_URL ?>/?page=admin_manual_reset" class="mb-0">
          <?= csrf_field() ?>
          <button type="button" class="btn btn-outline-secondary btn-sm"
            onclick="showConfirm({title:'Run Daily Reset',message:'Reset <strong>pairs_paid_today = 0</strong> and <strong>pairs_volume_today = 0</strong> for all members now? This simulates the midnight cron.',confirmText:'⟳ Run Reset',confirmClass:'btn-warning',formId:'manualResetForm'})">
            ⟳ Run Reset Now
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>