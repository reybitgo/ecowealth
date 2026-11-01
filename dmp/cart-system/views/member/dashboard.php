<?php

/**
 * @file   views/member/dashboard.php
 * @brief  Member dashboard UI
 */
?>
<?php $pageTitle = 'Dashboard'; ?>
<?php require 'views/partials/head.php'; ?>

<?php require 'views/partials/sidebar_member.php'; ?>

<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <?php
    $allPlans = User::isPrimaryAdmin((int)$user['id']) || Auth::isSuperadmin();
    $isBinary = $allPlans || Package::hasPairing((int)($user['package_id'] ?? 0));
    $pendingReactivation = false;
    if (($status['cap_status'] ?? 'active') === 'capped') {
        $pendingReactivation = (int)db()->query("SELECT COUNT(*) FROM reactivations WHERE user_id = {$user['id']} AND status = 'pending'")->fetchColumn() > 0;
    }
    ?>

    <?php if ($user['status'] === 'pending'): ?>
      <!-- Pending Activation Banner -->
      <div class="card mb-4 border-warning" style="border-width:2px;">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
              <div style="font-size:2rem;">⏳</div>
              <div>
                <h5 class="fw-700 mb-1">Account Pending Activation</h5>
                <p class="text-muted mb-0" style="font-size:.8rem;">
                  <?= $isBinary
                      ? 'Your binary position is reserved. Activate your account with a registration code or e-wallet to unlock all earning features.'
                      : 'Activate your account with a registration code or e-wallet to unlock all earning features.' ?>
                </p>
              </div>
            </div>
            <a href="<?= link_to('activate') ?>" class="btn btn-warning">
              ⚡ Activate Now
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($pendingReactivation): ?>
      <!-- Pending Reactivation Banner -->
      <div class="card mb-4 border-info" style="border-width:2px;">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <div style="font-size:2rem;">⏳</div>
            <div class="flex-grow-1">
              <h5 class="fw-700 mb-1">Reactivation Pending</h5>
              <p class="text-muted mb-0" style="font-size:.8rem;">
                Your reactivation request has been submitted and is awaiting admin confirmation.
              </p>
            </div>
          </div>
        </div>
      </div>
    <?php elseif (($status['cap_status'] ?? 'active') === 'capped'): ?>
      <!-- Capped Banner -->
      <div class="card mb-4 border-warning" style="border-width:2px;">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
              <div style="font-size:2rem;">⚠️</div>
              <div>
                <h5 class="fw-700 mb-1">Lifetime Income Cap Reached</h5>
                <p class="text-muted mb-0" style="font-size:.8rem;">
                  You've earned <?= fmt_money($status['lifetime_earned'] ?? 0) ?> of <?= fmt_money($status['lifetime_cap'] ?? 0) ?>
                </p>
              </div>
            </div>
            <a href="<?= link_to('reactivate') ?>" class="btn btn-warning">
              🔄 Reactivate Account
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($user['capping_bypass'])): ?>
      <!-- VIP Banner -->
      <div class="card mb-4 border-warning" style="border-width:2px;background:linear-gradient(135deg,#fffbeb,#fef3c7);">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <div style="font-size:2rem;">🏆</div>
            <div class="flex-grow-1">
              <h5 class="fw-700 mb-1">VIP Privilege Active</h5>
              <p class="text-muted mb-0" style="font-size:.8rem;">
                Your account has unlimited earning potential. Lifetime income capping is bypassed.
                <?php if (!empty($user['daily_cap_bypass'])): ?>
                  <br><strong>Daily pair cap is also disabled.</strong>
                <?php endif; ?>
              </p>
            </div>
            <span class="badge bg-warning text-dark" style="font-size:.85rem;padding:.5em 1em;">VIP</span>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($user['cd_active'])): ?>
      <?php
        $cd = CdStatus::getActive($user['id']);
        if ($cd):
          $cdTarget = (float)$cd['target_amount'];
          $cdFilled = (float)$cd['filled_amount'];
          $cdPct = $cdTarget > 0 ? min(100, ($cdFilled / $cdTarget) * 100) : 0;
      ?>
      <div class="card mb-4" style="border:2px solid #f59e0b;background:linear-gradient(135deg,#fffbeb,#fef3c7);">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
              <div style="font-size:2rem;">⏳</div>
              <div>
                <h5 class="fw-700 mb-1">Commission-Deduct Bucket</h5>
                <p class="text-muted mb-0" style="font-size:.8rem;">
                  Target: <strong><?= fmt_money($cdTarget) ?></strong> ·
                  Filled: <strong class="text-warning"><?= fmt_money($cdFilled) ?></strong> ·
                  Remaining: <strong><?= fmt_money(max(0, $cdTarget - $cdFilled)) ?></strong>
                </p>
                <div class="mt-2" style="max-width:300px;">
                  <div class="cap-bar-track">
                    <div class="cap-bar-fill" style="width:<?= $cdPct ?>%;background:linear-gradient(90deg,#f59e0b,#fbbf24);"></div>
                  </div>
                  <div class="d-flex justify-content-between" style="font-size:.72rem;">
                    <span><?= number_format($cdPct, 1) ?>%</span>
                    <span>DFI paused until full</span>
                  </div>
                </div>
              </div>
            </div>
            <span class="badge bg-warning text-dark" style="font-size:.85rem;padding:.5em 1em;">CD Active</span>
          </div>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Welcome row -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">Welcome back, <?= e($user['full_name'] ? explode(' ', $user['full_name'])[0] : '@' . $user['username']) ?>! 👋</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;"><?= e($user['package_name'] ?? 'Member') ?> · Joined <?= fmt_date($user['joined_at']) ?></p>
      </div>
      <a href="<?= link_to('payout') ?>" class="btn btn-primary btn-sm">💳 Request Payout</a>
      <?php if (!isSeatLimitReached()): ?>
        <a href="<?= link_to('register', ['sponsor' => $user['username']]) ?>"
          class="btn btn-success btn-sm">➕ Register Member</a>
      <?php else: ?>
        <span class="btn btn-secondary btn-sm" style="cursor:not-allowed;opacity:.6;"
              title="Seat limit reached — registration is closed.">🔒 Registration Closed</span>
      <?php endif; ?>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-3">
      <?php
      $withdrawable = (float) ($wallet['withdrawable_balance'] ?? 0);
      $nonWithdrawable = (float) ($wallet['ewallet_balance'] ?? 0) - $withdrawable;
      $balanceSub = 'Withdraw →';
      if ($nonWithdrawable > 0) {
          $balanceSub = fmt_money($withdrawable) . ' withdrawable · ' . fmt_money($nonWithdrawable) . ' locked';
      }
      /*
       * Indirect-referral ladder depth, counting level 1 plus every level above
       * it that is actually funded. A member's own package drives the figure;
       * the admin overview card has no package of its own, so it reports the
       * deepest ladder configured anywhere. Only a package with no level rows
       * at all resolves to 0, which hides the claim rather than advertising
       * "Up to 0 levels".
       */
      $indirectLevelCount = $allPlans
          ? Package::maxIndirectLevelCount()
          : Package::indirectLevelCount((int) ($user['package_id'] ?? 0));
      $indirectSub = $indirectLevelCount > 0
          ? 'Up to ' . $indirectLevelCount . ' ' . ($indirectLevelCount === 1 ? 'level' : 'levels')
          : null;

      $cards = [
        [$wallet['ewallet_balance'], 'E-Wallet Balance',   '💰', 'primary', 'primary', $balanceSub, 'payout'],
        ...($isBinary ? [
          [$summary['total_pairing'], 'Pairing Earnings', '🤝', 'success', 'success', fmt_money($status['matched_volume']) . ' matched lifetime', null],
        ] : []),
        [$summary['total_direct'],   'Direct Referral',  '👥', 'orange',  'warning', null, 'genealogy&view=referral'],
        ...(($allPlans || Package::hasIndirectReferral((int)$user['package_id'])) ? [
          [$summary['total_indirect'], 'Indirect Referral', '🔗', 'purple',  'purple',  $indirectSub, null],
        ] : []),
      ];
      foreach ($cards as [$val, $label, $icon, $accent, $color, $sub, $link]): ?>
        <div class="col-6 col-xl-3">
          <div class="card stat-card h-100">
            <div class="stat-accent stat-accent-<?= $accent ?>"></div>
            <div class="card-body pt-4">
              <div class="stat-icon bg-<?= $color === 'purple' ? 'purple' : ($color === 'orange' ? 'warning' : $color) ?>-subtle"><?= $icon ?></div>
              <div class="stat-label"><?= $label ?></div>
              <div class="stat-value text-<?= $color === 'orange' ? 'warning' : ($color === 'purple' ? 'primary' : $color) ?>"><?= fmt_money((float)$val) ?></div>
              <?php if ($sub): ?>
                <div class="stat-sub">
                  <?php if ($link): ?><a href="<?= link_to($link) ?>" class="text-decoration-none fw-semibold" style="font-size:.72rem;"><?= $sub ?></a>
                    <?php else: ?><?= $sub ?><?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
      <!-- Lifetime Cap Widget -->
      <div class="col-12 col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="card-title">🛡️ Lifetime Income Cap</div>
              <a href="<?= link_to('cap_status') ?>" class="btn btn-outline-primary btn-sm" style="font-size:.65rem;">View Details →</a>
            </div>
            <?php if (!empty($user['capping_bypass'])): ?>
              <div class="text-center py-3">
                <div style="font-size:2.5rem;">♾️</div>
                <div class="fw-bold text-warning mt-2">Unlimited Earnings</div>
                <div class="text-muted" style="font-size:.78rem;">VIP Privilege — cap bypassed</div>
              </div>
            <?php else: ?>
              <?php
              $lEarned = $status['lifetime_earned'] ?? 0;
              $lCap    = $status['lifetime_cap'] ?? 0;
              $lPct    = $lCap > 0 ? min(100, ($lEarned / $lCap) * 100) : 0;
              ?>
              <div class="cap-bar-track mb-2">
                <div class="cap-bar-fill <?= $lPct >= 100 ? 'full' : '' ?>" style="width:<?= $lPct ?>%"></div>
              </div>
              <div class="d-flex justify-content-between" style="font-size:.78rem;color:var(--muted);">
                <span><strong><?= fmt_money($lEarned) ?></strong> earned</span>
                <span><strong><?= fmt_money(max(0, $lCap - $lEarned)) ?></strong> remaining</span>
              </div>
            <?php endif; ?>
            <div class="cap-earned mt-2">
              <span>Cap Status</span>
              <strong><?= match($status['cap_status'] ?? 'active') { 'active' => 'Active ✅', 'capped' => 'Capped ⚠️', default => 'Inactive ⛔' } ?></strong>
              <?php if (!empty($user['daily_cap_bypass'])): ?>
                <span class="badge bg-info ms-1" style="font-size:.65rem;">⚡ Daily Off</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- DFI Widget -->
      <div class="col-12 col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="card-title">📅 Daily Fixed Income</div>
              <a href="<?= link_to('dfi_history') ?>" class="btn btn-outline-primary btn-sm" style="font-size:.65rem;">View History →</a>
            </div>
            <?php
            $dfiStatus = DailyFixedIncome::getMemberDFIStatus($user['id']);
            $dfiDays   = $dfiStatus['days_used'] + $dfiStatus['days_remaining'];
            $dfiPct    = $dfiDays > 0 ? ($dfiStatus['days_used'] / $dfiDays) * 100 : 0;
            ?>
            <div class="cap-bar-track mb-2">
              <div class="cap-bar-fill" style="width:<?= $dfiPct ?>%;background:linear-gradient(90deg,#3b6ff0,#60a5fa)"></div>
            </div>
            <div class="d-flex justify-content-between" style="font-size:.78rem;color:var(--muted);">
              <span><strong><?= $dfiStatus['days_used'] ?></strong> days used</span>
              <span><strong><?= $dfiStatus['days_remaining'] ?></strong> remaining</span>
            </div>
            <div class="cap-earned mt-2">
              <span><?= fmt_money($dfiStatus['daily_rate']) ?> / day</span>
              <strong><?= fmt_money($dfiStatus['total_dfi_earned']) ?> total</strong>
            </div>
            <?php if ($dfiStatus['status'] !== 'active'): ?>
              <div class="alert alert-warning py-2 mb-0 mt-2" style="font-size:.78rem;">
                <?= match($dfiStatus['status']) {
                    'capped'    => '⚠️ DFI paused — cap reached',
                    'perminact' => '⛔ DFI frozen — permanently inactive',
                    'completed' => '✅ DFI cycle completed',
                    'disabled'  => 'ℹ DFI not enabled for your package',
                    default     => '⏸️ DFI paused'
                } ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($isBinary): ?>
    <div class="row g-3 mb-3">
      <!-- Pairing cap widget -->
      <div class="col-12 col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="card-title">🎯 Today's Pairing Cap</div>
              <span class="badge bg-secondary-subtle text-secondary" style="font-size:.65rem;">Resets at midnight</span>
            </div>
            <?php $pct = $status['cap_percent']; ?>
            <div class="cap-bar-track mb-2">
              <div class="cap-bar-fill <?= $pct >= 100 ? 'full' : '' ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="d-flex justify-content-between" style="font-size:.78rem;color:var(--muted);">
              <span><strong><?= fmt_money($status['pairs_volume_today']) ?></strong> matched today</span>
              <span><strong><?= fmt_money($status['cap_remaining']) ?></strong> / <?= fmt_money($status['daily_cap_pesos']) ?> remaining</span>
            </div>
            <div class="cap-earned mt-2">
              <span>Earned today</span>
              <strong><?= fmt_money($status['earned_today']) ?></strong>
            </div>
            <div class="mt-2 pt-2" style="border-top:1px solid var(--border-color);font-size:.72rem;color:var(--muted);">
              Lifetime cap: <strong><?= fmt_money($status['lifetime_earned'] ?? 0) ?></strong> / <?= fmt_money($status['lifetime_cap'] ?? 0) ?>
              <a href="<?= link_to('cap_status') ?>" style="color:var(--primary);text-decoration:none;">View →</a>
            </div>
            <?php if ($status['cap_remaining'] === 0): ?>
              <div class="alert alert-warning py-2 mb-0 mt-2" style="font-size:.78rem;">⚡ Daily cap reached — resets at midnight</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Binary legs -->
      <div class="col-12 col-md-6">
        <div class="card h-100">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="card-title">🌳 Binary Legs</span>
            <a href="<?= link_to('genealogy&view=binary') ?>" class="btn btn-outline-primary btn-sm" style="font-size:.72rem;">View Tree</a>
          </div>
          <div class="card-body">
            <div class="row g-2 mb-3">
              <div class="col-6">
                <div class="leg-box text-center">
                  <div class="leg-label">↙ Left Leg</div>
                  <div class="leg-count"><?= number_format($status['left_pair_volume']) ?></div>
                  <div style="font-size:.72rem;color:var(--muted);"><?= $status['left_count'] ?> <?= $status['left_count'] == 1 ? 'head' : 'heads' ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="leg-box text-center">
                  <div class="leg-label">↘ Right Leg</div>
                  <div class="leg-count"><?= number_format($status['right_pair_volume']) ?></div>
                  <div style="font-size:.72rem;color:var(--muted);"><?= $status['right_count'] ?> <?= $status['right_count'] == 1 ? 'head' : 'heads' ?></div>
                </div>
              </div>
            </div>
            <?php foreach (
              [
                ['Lifetime matched volume',  fmt_money($status['matched_volume']), ''],
                ['Matched volume flushed',   fmt_money($status['pairs_volume_flushed']), 'color:var(--warning)'],
                ['Unmatched volume (carryover)', fmt_money($status['unpaired_volume']), 'color:var(--success)'],
              ] as [$k, $v, $s]
            ): ?>
              <div class="d-flex justify-content-between py-1 border-bottom" style="font-size:.8rem;">
                <span class="text-muted"><?= $k ?></span><strong style="<?= $s ?>"><?= $v ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Recent Activity -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">📋 Recent Activity</span>
        <a href="<?= link_to('earnings') ?>" class="btn btn-outline-primary btn-sm" style="font-size:.72rem;">View all</a>
      </div>
      <div class="card-body py-0 px-3">
        <?php if (empty($recent)): ?>
          <div class="text-center py-5 text-muted">
            <div style="font-size:2rem;">📭</div>
            <p class="mt-2 mb-0" style="font-size:.85rem;">No activity yet.</p>
          </div>
          <?php else: foreach ($recent as $item):
            $isCredit = $item['status'] === 'credited';
            $typeMap  = ['pairing' => ['🤝', '#ecfdf5', 'var(--success)'], 'direct_referral' => ['👥', '#fff7ed', 'var(--orange)'], 'daily_fixed_income' => ['📅', '#eff6ff', 'var(--primary)']];
            if ($allPlans || Package::hasIndirectReferral((int)$user['package_id'])) {
                $typeMap['indirect_referral'] = ['🔗', '#f5f3ff', 'var(--purple)'];
            }
            [$icon, $bg, $col] = $typeMap[$item['type']] ?? ['💬', '#f4f6fb', 'var(--muted)'];
            $typeName = match ($item['type']) {
              'pairing' => 'Pairing Bonus',
              'direct_referral' => 'Direct Referral',
              'indirect_referral' => ($allPlans || Package::hasIndirectReferral((int)$user['package_id'])) ? 'Indirect — Lvl ' . $item['level'] : $item['type'],
              'daily_fixed_income' => 'Daily Fixed Income',
              default => $item['type']
            };
          ?>
            <div class="activity-item">
              <div class="activity-dot" style="background:<?= $bg ?>;color:<?= $col ?>"><?= $icon ?></div>
              <div class="flex-grow-1 min-w-0">
                <div class="activity-desc"><?= e($typeName) ?><?php if ($item['source_username']): ?> <span class="text-muted">via @<?= e($item['source_username']) ?></span><?php endif; ?></div>
                <div class="activity-meta"><?= fmt_datetime($item['created_at']) ?><?php if ($item['pairs_count']): ?> · <?= $item['pairs_count'] ?> pair(s)<?php endif; ?></div>
              </div>
              <div class="activity-amount" style="color:<?= $isCredit ? 'var(--success)' : 'var(--muted)' ?>">
                <?= $isCredit ? '+'  . fmt_money($item['amount']) : '<span class="badge bg-warning-subtle text-warning">Flushed</span>' ?>
              </div>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require 'views/partials/footer.php'; ?>