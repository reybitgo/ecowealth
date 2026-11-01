<?php
/**
 * @file   views/admin/ewallet_topup.php
 * @brief  Admin E-Wallet Top-Up page
 */
?>
<?php $pageTitle = 'E-Wallet Top-Up'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">💰 E-Wallet Top-Up</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Manually add e-wallet funds to any member account</p>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-md-6">
        <div class="card">
          <div class="card-header"><span class="card-title">📝 Top-Up Form</span></div>
          <div class="card-body">
            <form method="POST" action="<?= APP_URL ?>/?page=do_admin_ewallet_topup">
              <?= csrf_field() ?>

              <div class="mb-3">
                <label class="form-label" style="font-weight:700;font-size:.8rem;">Recipient Username</label>
                <div class="input-group">
                  <span class="input-group-text">@</span>
                  <input type="text" name="recipient" class="form-control" placeholder="username" required>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label" style="font-weight:700;font-size:.8rem;">Amount (₱)</label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
              </div>

              <div class="mb-3">
                <label class="form-label" style="font-weight:700;font-size:.8rem;">Note <span class="text-muted">(optional)</span></label>
                <input type="text" name="note" class="form-control" maxlength="255" placeholder="e.g. Bonus, correction, etc.">
              </div>

              <div class="alert alert-warning py-2" style="font-size:.78rem;">
                <strong>⚠️ Notice:</strong> This action creates funds out of thin air. No source account is debited. Use responsibly.
              </div>

              <button type="submit" class="btn btn-primary w-100">💰 Top Up Account</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-6">
        <div class="card h-100">
          <div class="card-header"><span class="card-title">📖 Instructions</span></div>
          <div class="card-body" style="font-size:.85rem;">
            <ul class="mb-0 ps-3">
              <li class="mb-2">Enter the exact username of the member.</li>
              <li class="mb-2">The amount will be credited immediately.</li>
              <li class="mb-2">No fee is applied to top-ups.</li>
              <li class="mb-2">All top-ups are logged in the <a href="<?= APP_URL ?>/?page=admin_ewallet_monitor">E-Wallet Monitor</a>.</li>
              <li>The recipient will see this as "Admin top-up" in their ledger.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
