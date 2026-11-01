<?php
/**
 * @file   views/admin/reactivations.php
 * @brief  Admin reactivation management (Phase 4)
 */
?>
<?php $pageTitle = 'Reactivation Log'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">🔄 Reactivation Log</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Review and confirm external reactivation payments</p>
      </div>
    </div>

    <!-- Status tabs -->
    <ul class="nav nav-pills mb-3">
      <?php foreach (['pending' => '⏳ Pending', 'completed' => '✅ Completed', 'rejected' => '❌ Rejected', '' => '📋 All'] as $s => $label): ?>
        <li class="nav-item">
          <a class="nav-link <?= $status === $s ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=admin_reactivations&status=<?= $s ?>">
            <?= $label ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- Stats -->
    <div class="row g-3 mb-3">
      <div class="col-12 col-md-6">
        <div class="card stat-card">
          <div class="stat-accent stat-accent-warning"></div>
          <div class="card-body pt-4">
            <div class="stat-label">Pending Fees</div>
            <div class="stat-value text-warning"><?= fmt_money($pendingTotal) ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <div class="card stat-card">
          <div class="stat-accent stat-accent-success"></div>
          <div class="card-body pt-4">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value text-success"><?= fmt_money($totalRevenue) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <form method="GET" action="<?= APP_URL ?>/" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <input type="hidden" name="page" value="admin_reactivations">
          <input type="hidden" name="status" value="<?= e($status) ?>">
          <div class="d-flex align-items-center gap-2">
            <span class="card-title">🔄 Reactivation Requests</span>
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
              <th>#</th>
              <th>Member</th>
              <th>Fee</th>
              <th>Previous Earned</th>
              <th>Method</th>
              <th>Proof</th>
              <th>Requested</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($result['data'])): ?>
              <tr>
                <td colspan="9" class="text-center py-5 text-muted">No reactivation requests found.</td>
              </tr>
            <?php else: foreach ($result['data'] as $row):
                $methodLabel = match ($row['payment_method']) {
                    'ewallet'     => 'E-Wallet',
                    'maya'        => 'Maya',
                    'usdt_trc20'  => 'USDT TRC20',
                    'usdt_bep20'  => 'USDT BEP20',
                    'admin'       => 'Admin',
                    default       => 'GCash'
                };
                $methodColor = match ($row['payment_method']) {
                    'ewallet'     => '#3b6ff0',
                    'maya'        => '#48b0db',
                    'usdt_trc20'  => '#26a17b',
                    'usdt_bep20'  => '#f0b90b',
                    'admin'       => '#6b7280',
                    default       => '#0070d8'
                };
            ?>
              <tr>
                <td class="td-muted" style="font-size:.72rem;"><?= $row['id'] ?></td>
                <td>
                  <a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $row['user_id'] ?>" class="fw-bold text-decoration-none">@<?= e($row['username']) ?></a>
                  <div class="text-muted" style="font-size:.72rem;"><?= e($row['full_name'] ?? '') ?></div>
                </td>
                <td class="font-mono fw-bold text-success" style="font-size:.95rem;"><?= fmt_money($row['amount_paid']) ?></td>
                <td class="font-mono"><?= fmt_money($row['previous_earned']) ?></td>
                <td>
                  <span class="badge" style="background:<?= $methodColor ?>20;color:<?= $methodColor ?>;border:1px solid <?= $methodColor ?>40;font-size:.72rem;">
                    <?= $methodLabel ?>
                  </span>
                  <?php
                  $adminAccount = match ($row['payment_method']) {
                      'gcash'       => $adminPayment['gcash_number'] ?? '',
                      'maya'        => $adminPayment['maya_number'] ?? '',
                      'usdt_trc20'  => $adminPayment['usdt_trc20_address'] ?? '',
                      'usdt_bep20'  => $adminPayment['usdt_bep20_address'] ?? '',
                      default       => ''
                  };
                  ?>
                  <?php if ($adminAccount): ?>
                    <div class="text-muted" style="font-size:.68rem;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($adminAccount) ?>">
                      → <?= e($adminAccount) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($row['proof_image'])): ?>
                    <a href="<?= APP_URL ?>/uploads/<?= e($row['proof_image']) ?>" target="_blank" rel="noopener">
                      <img src="<?= APP_URL ?>/uploads/<?= e($row['proof_image']) ?>" alt="Proof" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                    </a>
                  <?php else: ?>
                    <span class="text-muted" style="font-size:.75rem;">—</span>
                  <?php endif; ?>
                </td>
                <td class="td-muted" style="font-size:.75rem;"><?= fmt_datetime($row['created_at']) ?></td>
                <td>
                  <?php
                  $b = match ($row['status']) {
                      'pending'   => 'bg-warning-subtle text-warning',
                      'completed' => 'bg-success-subtle text-success',
                      'rejected'  => 'bg-danger-subtle text-danger',
                      default     => 'bg-secondary-subtle text-secondary'
                  };
                  ?>
                  <span class="badge <?= $b ?>"><?= ucfirst($row['status']) ?></span>
                  <?php if ($row['admin_note']): ?>
                    <div class="text-muted" style="font-size:.68rem;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                      <?= e($row['admin_note']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($row['status'] === 'pending'): ?>
                    <div class="d-flex gap-1 flex-wrap">
                      <?php
                      $acct = match ($row['payment_method']) {
                          'gcash'       => e($adminPayment['gcash_number'] ?? ''),
                          'maya'        => e($adminPayment['maya_number'] ?? ''),
                          'usdt_trc20'  => e($adminPayment['usdt_trc20_address'] ?? ''),
                          'usdt_bep20'  => e($adminPayment['usdt_bep20_address'] ?? ''),
                          default       => ''
                      };
                      ?>
                      <button class="btn btn-sm btn-success"
                        onclick="reactivationAction(<?= jsargs(['confirm', (int) $row['id'], $row['username'], fmt_money($row['amount_paid']), $methodLabel, $acct]) ?>)">
                        ✓ Confirm
                      </button>
                      <button class="btn btn-sm btn-danger"
                        onclick="reactivationAction(<?= jsargs(['reject', (int) $row['id'], $row['username'], fmt_money($row['amount_paid']), $methodLabel, $acct]) ?>)">
                        ✕ Reject
                      </button>
                    </div>
                  <?php else: ?>
                    <span class="td-muted" style="font-size:.75rem;">
                      <?= $row['processed_at'] ? fmt_datetime($row['processed_at']) : '—' ?>
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($result && $result['total_pages'] > 1): ?>
        <div class="card-footer">
          <?= pagination_links($result, APP_URL . '/?page=admin_reactivations&status=' . urlencode($status) . '&per_page=' . $perPage) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Reactivation Action Modal -->
<div class="modal fade" id="reactivationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="reactivationModalTitle">Confirm</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p id="reactivationModalDesc" style="font-size:.9rem;line-height:1.65;"></p>
        <div class="mb-0" id="reactivationNoteGroup">
          <label class="form-label" id="reactivationNoteLabel">Note</label>
          <textarea id="reactivationModalNote" class="form-control" rows="2" placeholder="Optional…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <form method="POST" action="<?= APP_URL ?>/?page=admin_reactivation_action"
          id="reactivationActionForm" class="m-0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" id="reactivationActionInput">
          <input type="hidden" name="id" id="reactivationIdInput">
          <input type="hidden" name="note" id="reactivationNoteInput">
          <button type="submit" class="btn" id="reactivationModalBtn">Confirm</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
  function reactivationAction(action, id, user, amount, method, account) {
    // Member-supplied values that land in innerHTML below — escape at the sink,
    // not just in the attribute. Defect C5b.
    const U = escHtml(user);
    const A = escHtml(amount);
    const M = escHtml(method);
    const accountLine = account
      ? `<br><span style="color:#6b7a99;font-size:.82rem;">Admin account: <strong>${escHtml(account)}</strong></span>`
      : '';
    const configs = {
      confirm: {
        title: '✓ Confirm Reactivation',
        desc: `Confirm you've received <strong>${A}</strong> from <strong>@${U}</strong> via <strong>${M}</strong>.${accountLine}<br>This will reset the member's cap state to active.`,
        btnClass: 'btn-success',
        btnText: '✓ Confirm',
        noteLabel: 'Note (optional)',
      },
      reject: {
        title: '✕ Reject Reactivation',
        desc: `Reject <strong>${A}</strong> reactivation for <strong>@${U}</strong>?<br>The member will remain capped and must submit a new request.`,
        btnClass: 'btn-danger',
        btnText: '✕ Reject',
        noteLabel: 'Rejection reason (shown to member)',
      },
    };

    const cfg = configs[action];
    document.getElementById('reactivationModalTitle').textContent = cfg.title;
    document.getElementById('reactivationModalDesc').innerHTML = cfg.desc;
    document.getElementById('reactivationModalBtn').className = 'btn ' + cfg.btnClass;
    document.getElementById('reactivationModalBtn').textContent = cfg.btnText;
    document.getElementById('reactivationNoteLabel').textContent = cfg.noteLabel;
    document.getElementById('reactivationModalNote').value = '';
    document.getElementById('reactivationActionInput').value = action;
    document.getElementById('reactivationIdInput').value = id;

    document.getElementById('reactivationActionForm').onsubmit = () => {
      document.getElementById('reactivationNoteInput').value =
        document.getElementById('reactivationModalNote').value;
    };

    new bootstrap.Modal(document.getElementById('reactivationModal')).show();
  }
</script>
<?php require 'views/partials/footer.php'; ?>
