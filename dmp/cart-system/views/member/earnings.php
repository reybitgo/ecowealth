<?php

/**
 * @file   views/member/earnings.php
 * @brief  Member earnings UI
 */
?>
<?php $pageTitle = 'Earnings'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>
    <?php
    $allPlans = User::isPrimaryAdmin((int)(Auth::user()['id'] ?? 0)) || Auth::isSuperadmin();
    $isBinary = $allPlans || Package::hasPairing((int)(Auth::user()['package_id'] ?? 0));
    $showIndirect = $allPlans || Package::hasIndirectReferral((int)(Auth::user()['package_id'] ?? 0));
    ?>
    <div class="row g-3 mb-3">
      <?php
      $statCards = [
        ['Total Earned',      $summary['total_earned'],   'primary', 'primary'],
        ...($isBinary ? [['Pairing Bonuses', $summary['total_pairing'], 'success', 'success']] : []),
        ['Direct Referral',   $summary['total_direct'],   'orange', 'warning'],
        ...($showIndirect ? [['Indirect Referral', $summary['total_indirect'], 'purple', 'primary']] : []),
        ['DFI',               $summary['total_dfi'] ?? 0,  'teal', 'info'],
      ];
      foreach ($statCards as [$label, $val, $accent, $color]):
      ?>
        <div class="col-6 col-xl-3">
          <div class="card stat-card">
            <div class="stat-accent stat-accent-<?= $accent ?>"></div>
            <div class="card-body pt-4">
              <div class="stat-label"><?= $label ?></div>
              <div class="stat-value text-<?= $color ?>"><?= fmt_money((float)$val) ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($cdStatus || !empty($cdLedger)): ?>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">⏳ CD Bucket Ledger</span>
        <?php if ($cdStatus): ?>
          <?php
            $cdTarget = (float)$cdStatus['target_amount'];
            $cdFilled = (float)$cdStatus['filled_amount'];
            $cdPct = $cdTarget > 0 ? min(100, ($cdFilled / $cdTarget) * 100) : 0;
          ?>
          <span class="badge bg-warning-subtle text-warning"><?= fmt_money($cdFilled) ?> / <?= fmt_money($cdTarget) ?> (<?= number_format($cdPct, 1) ?>%)</span>
        <?php else: ?>
          <span class="badge bg-success-subtle text-success">Completed</span>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.85rem;">
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th>Gross</th>
              <th>To CD Bucket</th>
              <th>To Wallet</th>
              <th>From</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($cdLedger)): ?>
              <tr><td colspan="6" class="text-center py-3 text-muted">No CD entries yet.</td></tr>
            <?php else: foreach ($cdLedger as $l): ?>
              <tr>
                <td class="td-muted" style="font-size:.72rem;"><?= fmt_datetime($l['created_at']) ?></td>
                <td>
                  <?php $tl = match ($l['type']) {
                    'pairing' => '🤝 Pairing',
                    'direct_referral' => '👥 Direct',
                    'indirect_referral' => '🔗 Indirect',
                    default => $l['type']
                  }; echo $tl; ?>
                </td>
                <td class="font-mono"><?= fmt_money($l['gross_amount']) ?></td>
                <td class="font-mono text-warning"><?= fmt_money($l['cd_amount']) ?></td>
                <td class="font-mono text-success"><?= fmt_money($l['withdrawable_amount']) ?></td>
                <td class="td-muted"><?= $l['source_username'] ? '@' . e($l['source_username']) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <form method="GET" action="<?= APP_URL ?>/" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <input type="hidden" name="page" value="earnings">
          <input type="hidden" name="type" value="<?= e($type) ?>">
          <?php if (is_imp_session()): ?><input type="hidden" name="imp" value="<?= e(session_id()) ?>"><?php endif; ?>
          <ul class="nav nav-pills card-header-pills gap-1">
            <?php
            $filterTabs = ['' => 'All', ...($isBinary ? ['pairing' => '🤝 Pairing'] : []), 'direct_referral' => '👥 Direct', ...($showIndirect ? ['indirect_referral' => '🔗 Indirect'] : []), 'daily_fixed_income' => '📅 DFI'];
            foreach ($filterTabs as $val => $label):
            ?>
              <li class="nav-item">
                <a class="nav-link <?= $type === $val ? 'active' : '' ?>" href="<?= link_to('earnings', ['type' => $val]) ?>"><?= $label ?></a>
              </li>
            <?php endforeach; ?>
          </ul>

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
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th>Description</th>
              <th>From</th>
              <th>Amount</th>
              <th>Cap Impact</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($history['data'])): ?>
              <tr>
                <td colspan="7" class="text-center py-5 text-muted">No earnings found.</td>
              </tr>
              <?php else: foreach ($history['data'] as $row):
                $typeName = match ($row['type']) {
                  'pairing' => '🤝 Pairing',
                  'direct_referral' => '👥 Direct Referral',
                  'indirect_referral' => $showIndirect ? '🔗 Indirect Lvl ' . $row['level'] : $row['type'],
                  'daily_fixed_income' => '📅 Daily Fixed Income',
                  default => $row['type']
                };
              ?>
                <tr>
                  <td class="td-muted font-mono" style="font-size:.72rem;"><?= fmt_datetime($row['created_at']) ?></td>
                  <td><?= $typeName ?></td>
                  <td class="text-truncate" style="max-width:200px;" title="<?= e($row['description']) ?>"><?= e($row['description']) ?></td>
                  <td class="td-muted"><?= $row['source_username'] ? '@' . e($row['source_username']) : '—' ?></td>
                  <td><?php if ($row['status'] === 'credited'): ?><span class="td-green">+<?= fmt_money($row['amount']) ?></span><?php else: ?><span class="td-muted">—</span><?php endif; ?></td>
                  <td>
                    <?php if ((float)$row['cap_deduction'] > 0): ?>
                      <span class="text-warning" style="font-size:.75rem;" title="<?= fmt_money($row['cap_deduction']) ?> blocked by lifetime cap">−<?= fmt_money($row['cap_deduction']) ?></span>
                    <?php else: ?>
                      <span class="text-muted" style="font-size:.75rem;">—</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge <?= $row['status'] === 'credited' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?>"><?= ucfirst($row['status']) ?></span></td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($history['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($history, link_to('earnings', ['type' => $type, 'per_page' => $perPage])) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>