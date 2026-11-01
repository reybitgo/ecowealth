<?php
/**
 * @file   views/member/cap_status.php
 * @brief  Member cap status detail page (Phase 3/5)
 */
?>
<?php $pageTitle = 'Lifetime Cap Status'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>
    <?php
    $allPlans = User::isPrimaryAdmin((int)($user['id'] ?? 0)) || Auth::isSuperadmin();
    $isBinary = $allPlans || Package::hasPairing((int)($user['package_id'] ?? 0));
    $showIndirect = $allPlans || Package::hasIndirectReferral((int)($user['package_id'] ?? 0));
    ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">🛡️ Lifetime Income Cap</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Monitor your cap progress and earnings breakdown</p>
      </div>
      <a href="<?= link_to('dashboard') ?>" class="btn btn-outline-primary btn-sm">← Dashboard</a>
    </div>

    <!-- Cap Progress Card -->
    <div class="card mb-4">
      <div class="card-body">
        <?php if (!empty($user['capping_bypass'])): ?>
          <div class="d-flex align-items-center gap-2 mb-3">
            <span class="badge bg-warning text-dark" style="font-size:.9rem;padding:.5em 1em;">🏆 VIP Privilege Active</span>
            <span class="text-muted" style="font-size:.78rem;">Lifetime cap bypassed — unlimited earnings</span>
          </div>
        <?php endif; ?>
        <?php
        $earned = $capStatus['lifetime_earned'];
        $cap    = $capStatus['lifetime_cap'];
        $pct    = $cap > 0 ? min(100, ($earned / $cap) * 100) : 0;
        $remaining = max(0, $cap - $earned);
        ?>
        <div class="d-flex justify-content-between align-items-end mb-2">
          <div>
            <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;">Progress</div>
            <div class="fw-800" style="font-size:1.5rem;"><?= fmt_money($earned) ?> <span style="font-size:.9rem;color:var(--muted);">/ <?= fmt_money($cap) ?></span></div>
          </div>
          <div class="text-end">
            <div class="fw-700" style="font-size:1.25rem;"><?= number_format($pct, 1) ?>%</div>
            <div class="text-muted" style="font-size:.75rem;"><?= fmt_money($remaining) ?> remaining</div>
          </div>
        </div>
        <div class="cap-bar-track mb-3">
          <div class="cap-bar-fill <?= $pct >= 100 ? 'full' : '' ?>" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="d-flex gap-2">
          <?php if (!empty($user['capping_bypass'])): ?>
            <span class="badge bg-warning-subtle text-warning">🏆 VIP — Unlimited Earnings</span>
          <?php elseif ($capStatus['cap_status'] === 'active'): ?>
            <span class="badge bg-success-subtle text-success">✅ Active — You can still earn</span>
          <?php elseif ($capStatus['cap_status'] === 'capped'): ?>
            <span class="badge bg-warning-subtle text-warning">⚠️ Capped — Reactivation required</span>
          <?php else: ?>
            <span class="badge bg-danger-subtle text-danger">⛔ Permanently Inactive</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Visual Timeline -->
    <div class="card mb-4">
      <div class="card-header"><span class="card-title">📈 Earnings Timeline</span></div>
      <div class="card-body">
        <div class="timeline">
          <!-- Joined -->
          <div class="timeline-item">
            <div class="timeline-dot" style="background:#3b6ff0;"></div>
            <div class="timeline-content">
              <div class="fw-bold" style="font-size:.85rem;">Account Created</div>
              <div class="text-muted" style="font-size:.75rem;"><?= fmt_datetime($user['created_at'] ?? '') ?></div>
            </div>
          </div>

          <!-- Earning phases (only show if there's earnings) -->
          <?php if ($isBinary && (float)$summary['total_pairing'] > 0): ?>
          <div class="timeline-item">
            <div class="timeline-dot" style="background:#12a05c;"></div>
            <div class="timeline-content">
              <div class="fw-bold" style="font-size:.85rem;">🤝 Pairing Bonuses</div>
              <div class="text-muted" style="font-size:.75rem;"><?= fmt_money((float)$summary['total_pairing']) ?> earned</div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ((float)$summary['total_direct'] > 0): ?>
          <div class="timeline-item">
            <div class="timeline-dot" style="background:#f59e0b;"></div>
            <div class="timeline-content">
              <div class="fw-bold" style="font-size:.85rem;">👥 Direct Referrals</div>
              <div class="text-muted" style="font-size:.75rem;"><?= fmt_money((float)$summary['total_direct']) ?> earned</div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($showIndirect && (float)$summary['total_indirect'] > 0): ?>
          <div class="timeline-item">
            <div class="timeline-dot" style="background:#8b5cf6;"></div>
            <div class="timeline-content">
              <div class="fw-bold" style="font-size:.85rem;">🔗 Indirect Referrals</div>
              <div class="text-muted" style="font-size:.75rem;"><?= fmt_money((float)$summary['total_indirect']) ?> earned</div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ((float)($summary['total_dfi'] ?? 0) > 0): ?>
          <div class="timeline-item">
            <div class="timeline-dot" style="background:#06b6d4;"></div>
            <div class="timeline-content">
              <div class="fw-bold" style="font-size:.85rem;">📅 Daily Fixed Income</div>
              <div class="text-muted" style="font-size:.75rem;"><?= fmt_money((float)$summary['total_dfi']) ?> earned</div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Capped event -->
          <?php if ($capStatus['cap_status'] === 'capped' || $capStatus['cap_status'] === 'perminact'): ?>
          <div class="timeline-item">
            <div class="timeline-dot" style="background:#e03434;"></div>
            <div class="timeline-content">
              <div class="fw-bold" style="font-size:.85rem;color:#e03434;">⚠️ Lifetime Cap Reached</div>
              <div class="text-muted" style="font-size:.75rem;"><?= fmt_datetime($capStatus['capped_at'] ?? '') ?> — <?= fmt_money($capStatus['lifetime_earned']) ?> / <?= fmt_money($capStatus['lifetime_cap']) ?></div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Reactivation events -->
          <?php
          $reactivationHistory = Reactivation::getReactivationHistory($userId);
          foreach ($reactivationHistory['data'] as $r):
          ?>
          <div class="timeline-item">
            <div class="timeline-dot" style="background:#3b6ff0;"></div>
            <div class="timeline-content">
              <div class="fw-bold" style="font-size:.85rem;color:#3b6ff0;">🔄 Reactivated</div>
              <div class="text-muted" style="font-size:.75rem;"><?= fmt_datetime($r['created_at']) ?> — Fee: <?= fmt_money((float)$r['amount_paid']) ?> via <?= e($r['payment_method']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>

          <!-- Current state -->
          <div class="timeline-item">
            <div class="timeline-dot" style="background:<?= match($capStatus['cap_status']) { 'active' => '#12a05c', 'capped' => '#f59e0b', default => '#6b7280' } ?>;"></div>
            <div class="timeline-content">
              <div class="fw-bold" style="font-size:.85rem;">
                <?= !empty($user['capping_bypass']) ? '🏆 VIP — Cap Bypassed' : match($capStatus['cap_status']) { 'active' => '✅ Currently Active', 'capped' => '⚠️ Capped — Reactivation Required', default => '⛔ Permanently Inactive' } ?>
              </div>
              <div class="text-muted" style="font-size:.75rem;">
                <?= fmt_money($capStatus['lifetime_earned']) ?> / <?= fmt_money($capStatus['lifetime_cap']) ?> earned this cycle
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <style>
      .timeline { position: relative; padding-left: 1.25rem; }
      .timeline::before { content: ''; position: absolute; left: 5px; top: 4px; bottom: 4px; width: 2px; background: #e5e7eb; }
      .timeline-item { position: relative; padding-bottom: 1rem; display: flex; align-items: flex-start; gap: .75rem; }
      .timeline-item:last-child { padding-bottom: 0; }
      .timeline-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; margin-top: 3px; margin-left: -1.25rem; position: relative; z-index: 1; border: 2px solid #fff; box-shadow: 0 0 0 1px #e5e7eb; }
      .timeline-content { flex: 1; }
    </style>

    <?php if ($capStatus['cap_status'] === 'capped'): ?>
      <!-- Reactivation CTA -->
      <div class="card mb-4 border-warning" style="border-width:2px;">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
              <h5 class="fw-700 mb-1">⚠️ Reactivation Required</h5>
              <p class="text-muted mb-0" style="font-size:.8rem;">
                Window: <?= $capStatus['reactivation_window'] ?? 15 ?> days from <?= fmt_datetime($capStatus['capped_at']) ?>
              </p>
            </div>
            <a href="<?= link_to('reactivate') ?>" class="btn btn-warning">
              🔄 Reactivate — <?= fmt_money($capStatus['reactivation_fee'] ?? 0) ?>
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Reactivation History -->
    <?php
    $reactivationHistory = Reactivation::getReactivationHistory($userId);
    if (!empty($reactivationHistory['data'])):
    ?>
      <div class="card mb-4">
        <div class="card-header"><span class="card-title">🔄 Reactivation History</span></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.85rem;">
              <thead class="table-light">
                <tr>
                  <th>Date</th>
                  <th>Previous Earned</th>
                  <th>Fee Paid</th>
                  <th>Method</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($reactivationHistory['data'] as $r): ?>
                  <tr>
                    <td><?= fmt_datetime($r['created_at']) ?></td>
                    <td><?= fmt_money((float)$r['previous_earned']) ?></td>
                    <td class="fw-semibold text-success"><?= fmt_money((float)$r['amount_paid']) ?></td>
                    <td><span class="badge bg-secondary-subtle text-secondary" style="text-transform:uppercase;font-size:.65rem;"><?= e($r['payment_method']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Breakdown Table -->
    <div class="card">
      <div class="card-header"><span class="card-title">Earnings Breakdown</span></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" style="font-size:.85rem;">
            <thead class="table-light">
              <tr>
                <th>Type</th>
                <th>Amount</th>
                <th>% of Cap</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $breakdown = [
                  ...($isBinary ? [['Pairing', (float)$summary['total_pairing']]] : []),
                  ['Direct Referral', (float)$summary['total_direct']],
                  ...($showIndirect ? [['Indirect Referral', (float)$summary['total_indirect']]] : []),
                  ['Daily Fixed Income', (float)($summary['total_dfi'] ?? 0)],
              ];
              $hasRows = false;
              foreach ($breakdown as [$label, $amount]):
                  if ($amount <= 0) continue;
                  $hasRows = true;
                  $typePct = $cap > 0 ? ($amount / $cap) * 100 : 0;
              ?>
                <tr>
                  <td><?= $label ?></td>
                  <td class="fw-semibold"><?= fmt_money($amount) ?></td>
                  <td><?= number_format($typePct, 1) ?>%</td>
                  <td><span class="badge bg-success-subtle text-success">✅ Credited</span></td>
                </tr>
              <?php endforeach; ?>
              <?php if ((float)$summary['total_cap_blocked'] > 0):
                  $hasRows = true;
              ?>
                <tr class="table-warning">
                  <td>Blocked by Cap</td>
                  <td class="fw-semibold"><?= fmt_money((float)$summary['total_cap_blocked']) ?></td>
                  <td>—</td>
                  <td><span class="badge bg-warning-subtle text-warning">⛔ Not Paid</span></td>
                </tr>
              <?php endif; ?>
              <?php if (!$hasRows): ?>
                <tr>
                  <td colspan="4" class="text-center text-muted py-4">No earnings recorded yet.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
