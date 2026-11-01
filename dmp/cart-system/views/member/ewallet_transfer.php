<?php
/**
 * @file   views/member/ewallet_transfer.php
 * @brief  E-Wallet Transfer page (member & admin)
 */
?>
<?php $pageTitle = 'Send Money'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require Auth::isAdmin() ? 'views/partials/sidebar_admin.php' : 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">💱 Send Money</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Transfer e-wallet funds to another member</p>
      </div>
      <?php if (Auth::isAdmin()): ?>
        <span class="badge bg-warning text-dark" style="font-size:.8rem;">👤 Admin Mode — No Fee</span>
      <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
      <!-- Balance Card -->
      <div class="col-12 col-md-5">
        <div class="card h-100" style="background:linear-gradient(135deg,#1a3a8f,#3b6ff0);border:none;">
          <div class="card-body text-white">
            <div style="font-size:.68rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;opacity:.7;margin-bottom:.5rem;">E-Wallet Balance</div>
            <div style="font-size:2.2rem;font-weight:800;font-family:var(--font-mono);line-height:1;"><?= fmt_money((float)($user['ewallet_balance'] ?? 0)) ?></div>
            <div class="mt-2" style="font-size:.78rem;opacity:.85;">
              <?php
              $w = (float) ($user['withdrawable_balance'] ?? 0);
              $nw = (float) ($user['ewallet_balance'] ?? 0) - $w;
              ?>
              Withdrawable: <?= fmt_money($w) ?> · Non-withdrawable: <?= fmt_money($nw) ?>
              <?php if (!Auth::isAdmin()): ?>
                <br>Fee: <?= fmt_money($fee) ?> per transfer · Min: <?= fmt_money($min) ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Transfer Form -->
      <div class="col-12 col-md-7">
        <div class="card h-100">
          <div class="card-header"><span class="card-title">📝 Transfer Details</span></div>
          <div class="card-body">
            <form method="POST" action="<?= link_to('do_ewallet_transfer') ?>" id="transferForm">
              <?= csrf_field() ?>

              <div class="mb-3">
                <label class="form-label" style="font-weight:700;font-size:.8rem;">Recipient Username</label>
                <div class="input-group">
                  <span class="input-group-text">@</span>
                  <input type="text" name="recipient" class="form-control" placeholder="username" required value="<?= e($_GET['to'] ?? '') ?>">
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label" style="font-weight:700;font-size:.8rem;">Amount (₱)</label>
                <input type="number" name="amount" id="transferAmount" class="form-control" min="<?= $min ?>" max="<?= (float)($user['ewallet_balance'] ?? 0) ?>" step="0.01" required placeholder="0.00">
                <div class="form-text">Minimum: <?= fmt_money($min) ?> · Max: <?= fmt_money((float)($user['ewallet_balance'] ?? 0)) ?></div>
              </div>

              <div class="mb-3">
                <label class="form-label" style="font-weight:700;font-size:.8rem;">Note <span class="text-muted">(optional)</span></label>
                <input type="text" name="note" class="form-control" maxlength="255" placeholder="e.g. Payment for something">
              </div>

              <div class="mb-3">
                <label class="form-label" style="font-weight:700;font-size:.8rem;">Confirm Password</label>
                <div class="input-group">
                  <input type="password" name="password" id="confirmPw" class="form-control" required placeholder="Enter your password to confirm">
                  <button type="button" class="btn btn-outline-secondary" onclick="togglePw('confirmPw',this)">👁</button>
                </div>
              </div>

              <!-- Preview -->
              <?php if (!Auth::isAdmin()): ?>
              <div class="card bg-light border-0 mb-3">
                <div class="card-body py-2">
                  <div class="d-flex justify-content-between" style="font-size:.85rem;">
                    <span class="text-muted">You send</span>
                    <strong id="previewSend">₱0.00</strong>
                  </div>
                  <div class="d-flex justify-content-between" style="font-size:.85rem;">
                    <span class="text-muted">Fee</span>
                    <strong id="previewFee"><?= fmt_money($fee) ?></strong>
                  </div>
                  <hr class="my-1">
                  <div class="d-flex justify-content-between" style="font-size:.9rem;">
                    <span class="text-muted">Total debit</span>
                    <strong class="text-primary" id="previewTotal">₱0.00</strong>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <button type="submit" class="btn btn-primary w-100">
                💸 Send Transfer
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent Transfers -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">📋 Recent Transfers</span>
        <a href="<?= link_to('earnings') ?>" class="btn btn-outline-primary btn-sm" style="font-size:.72rem;">View Ledger →</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.85rem;">
          <thead>
            <tr>
              <th>Date</th>
              <th>Direction</th>
              <th>Counterparty</th>
              <th>Amount</th>
              <th>Fee</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $rows = $recent->fetchAll();
            if (empty($rows)): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">No transfers yet.</td></tr>
            <?php else: foreach ($rows as $t): ?>
              <tr>
                <td style="font-size:.75rem;"><?= fmt_datetime($t['created_at']) ?></td>
                <td>
                  <?php if ($t['sender_id'] == $user['id']): ?>
                    <span class="badge bg-danger-subtle text-danger" style="font-size:.65rem;">SENT</span>
                  <?php else: ?>
                    <span class="badge bg-success-subtle text-success" style="font-size:.65rem;">RECEIVED</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($t['sender_id'] == $user['id']): ?>
                    @<?= e($t['recipient_username']) ?>
                  <?php else: ?>
                    @<?= e($t['sender_username']) ?>
                  <?php endif; ?>
                </td>
                <td class="font-mono fw-semibold"><?= fmt_money($t['amount']) ?></td>
                <td class="font-mono text-muted"><?= $t['fee'] > 0 ? fmt_money($t['fee']) : '—' ?></td>
                <td class="text-muted" style="font-size:.75rem;"><?= e($t['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>

<script>
function togglePw(id, btn) {
  const el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
  btn.textContent = el.type === 'password' ? '👁' : '🙈';
}
</script>

<?php if (!Auth::isAdmin()): ?>
<script>
(function() {
  const amountInput = document.getElementById('transferAmount');
  const fee = <?= json_encode($fee) ?>;
  const previewSend = document.getElementById('previewSend');
  const previewTotal = document.getElementById('previewTotal');

  function fmt(n) {
    return '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  amountInput.addEventListener('input', function() {
    const amt = parseFloat(this.value) || 0;
    previewSend.textContent = fmt(amt);
    previewTotal.textContent = fmt(amt + fee);
  });
})();
</script>
<?php endif; ?>
