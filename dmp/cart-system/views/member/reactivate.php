<?php
/**
 * @file   views/member/reactivate.php
 * @brief  Member reactivation page — styled after payout.php
 */
?>
<?php
$pageTitle = 'Reactivate Account';

// Build method config for JS
// E-Wallet is always shown (like register page) but disabled when balance is insufficient
$methods = [];
$methods[] = ['ewallet', 'E-Wallet', '#2d6a35', '💳', 'Deduct from your balance'];
$methods[] = ['gcash', 'GCash', '#0070d8', '', 'Send via GCash'];
$methods[] = ['maya', 'Maya', '#48b0db', '', 'Send via Maya'];
$methods[] = ['usdt_trc20', 'USDT TRC20', '#26a17b', '', 'Send via USDT'];
$methods[] = ['usdt_bep20', 'USDT BEP20', '#f0b90b', '', 'Send via USDT'];

$defaultMethod = $request['can_use_ewallet'] ? 'ewallet' : 'gcash';

// Admin details for JS
$jsAdmin = [
  'gcash'      => ['number' => $admin['gcash_number'] ?? '', 'label' => 'GCash Number', 'color' => '#0070d8'],
  'maya'       => ['number' => $admin['maya_number']  ?? '', 'label' => 'Maya Number',  'color' => '#48b0db'],
  'usdt_trc20' => ['number' => $admin['usdt_trc20_address'] ?? '', 'label' => 'USDT TRC20 Address', 'color' => '#26a17b'],
  'usdt_bep20' => ['number' => $admin['usdt_bep20_address'] ?? '', 'label' => 'USDT BEP20 Address', 'color' => '#f0b90b'],
];
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="row g-3 mb-3">
      <!-- Status Hero -->
      <div class="col-12 col-md-6">
        <div class="card h-100" style="background:linear-gradient(135deg,#7c2d12,#d97706);border:none;">
          <div class="card-body text-white d-flex flex-column">
            <div style="font-size:.68rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;opacity:.7;margin-bottom:.5rem;">Account Reactivation</div>
            <div style="font-size:2.2rem;font-weight:800;font-family:var(--font-mono);line-height:1;"><?= fmt_money($request['fee']) ?></div>
            <div style="font-size:.78rem;opacity:.85;margin-top:.5rem;">Reactivation Fee</div>

            <div style="margin-top:auto;padding-top:1.5rem;border-top:1px solid rgba(255,255,255,.15);">
              <div class="d-flex justify-content-between mb-2" style="font-size:.8rem;">
                <span style="opacity:.7;">Lifetime Earned</span>
                <span class="fw-bold"><?= fmt_money($capStatus['lifetime_earned']) ?></span>
              </div>
              <div class="d-flex justify-content-between mb-2" style="font-size:.8rem;">
                <span style="opacity:.7;">Lifetime Cap</span>
                <span class="fw-bold"><?= fmt_money($capStatus['lifetime_cap']) ?></span>
              </div>
              <div class="d-flex justify-content-between" style="font-size:.8rem;">
                <span style="opacity:.7;">Window Closes In</span>
                <span class="fw-bold"><?= (int)$request['days_remaining'] ?> day(s)</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Reactivation Form -->
      <div class="col-12 col-md-6">
        <div class="card h-100">
          <div class="card-header"><span class="card-title">🔄 Reactivate Your Account</span></div>
          <div class="card-body">
            <form method="POST" action="<?= link_to('do_reactivate') ?>" id="reactivateForm" enctype="multipart/form-data">
              <?= csrf_field() ?>

              <!-- Payment Method -->
              <div class="mb-3">
                <label class="form-label" style="font-size:.8rem;font-weight:600;color:var(--text-muted);">Payment Method <span class="text-danger">*</span></label>
                <div class="d-flex gap-2 flex-wrap" id="methodBtns">
                  <?php foreach ($methods as $idx => [$val, $label, $color, $icon, $hint]): ?>
                    <?php
                      $isDisabled = false;
                      $title = '';
                      if ($val === 'ewallet' && !$request['can_use_ewallet']) {
                        $isDisabled = true;
                        $title = 'Insufficient balance: ' . fmt_money($request['ewallet_balance']) . ' available, ' . fmt_money($request['fee']) . ' required';
                      }
                    ?>
                    <label class="method-option <?= $isDisabled ? 'method-disabled' : '' ?>"
                      style="--mc:<?= $color ?>;"
                      title="<?= $title ?>">
                      <input type="radio" name="payment_method" value="<?= $val ?>"
                        <?= $val === $defaultMethod ? 'checked' : '' ?>
                        <?= $isDisabled ? 'disabled' : '' ?>
                        onchange="switchMethod('<?= $val ?>')">
                      <?php if ($icon): ?><span style="font-size:1.1rem;"><?= $icon ?></span><?php endif; ?>
                      <span><?= $label ?></span>
                      <?php if ($val === 'ewallet' && $request['can_use_ewallet']): ?>
                        <small>Bal: <?= fmt_money($request['ewallet_balance']) ?></small>
                      <?php elseif ($val === 'ewallet'): ?>
                        <small class="text-danger">Low balance</small>
                      <?php else: ?>
                        <small><?= $hint ?></small>
                      <?php endif; ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Admin Payment Details (shown for external methods) -->
              <div id="adminPaymentDetails" class="d-none">
                <div class="rounded p-3 mb-3" style="background:#f8fafd;border:1px solid #dde3ef;font-size:.85rem;">
                  <div class="fw-bold mb-2" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);">Send Payment To</div>

                  <div id="detailGcash" class="d-none">
                    <div class="d-flex align-items-center gap-2 mb-1">
                      <span class="fw-bold">GCash</span>
                    </div>
                    <div class="font-mono fw-bold" style="font-size:1rem;color:#0070d8;"><?= e($admin['gcash_number'] ?? '—') ?></div>
                  </div>

                  <div id="detailMaya" class="d-none">
                    <div class="d-flex align-items-center gap-2 mb-1">
                      <span class="fw-bold">Maya</span>
                    </div>
                    <div class="font-mono fw-bold" style="font-size:1rem;color:#48b0db;"><?= e($admin['maya_number'] ?? '—') ?></div>
                  </div>

                  <div id="detailUsdtTrc20" class="d-none">
                    <div class="d-flex align-items-center gap-2 mb-1">
                      <span class="fw-bold">USDT (TRC20)</span>
                    </div>
                    <div class="font-mono fw-bold" style="font-size:.85rem;color:#26a17b;word-break:break-all;"><?= e($admin['usdt_trc20_address'] ?? '—') ?></div>
                  </div>

                  <div id="detailUsdtBep20" class="d-none">
                    <div class="d-flex align-items-center gap-2 mb-1">
                      <span class="fw-bold">USDT (BEP20)</span>
                    </div>
                    <div class="font-mono fw-bold" style="font-size:.85rem;color:#f0b90b;word-break:break-all;"><?= e($admin['usdt_bep20_address'] ?? '—') ?></div>
                  </div>

                  <div class="mt-3 pt-2" style="border-top:1px dashed #dde3ef;">
                    <div class="d-flex justify-content-between align-items-center">
                      <span style="font-size:.75rem;color:var(--text-muted);">Amount to send</span>
                      <span class="fw-bold" style="font-size:1.1rem;"><?= fmt_money($request['fee']) ?></span>
                    </div>
                  </div>
                </div>

                <!-- Proof Upload -->
                <div class="mb-3">
                  <label class="form-label" style="font-size:.85rem;font-weight:600;">📎 Proof of Payment <span class="text-danger">*</span></label>
                  <div class="form-text mb-2" style="font-size:.75rem;">Upload a screenshot or photo showing your successful payment.</div>
                  <input type="file" name="proof_image" id="proofImage" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                  <div class="form-text" style="font-size:.7rem;">Max 5MB. JPEG, PNG, GIF, or WebP.</div>
                </div>
              </div>

              <!-- E-Wallet Preview (shown for ewallet method) -->
              <div id="ewalletPreview" class="rounded p-3 mb-3" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:.85rem;">
                <div class="d-flex justify-content-between mb-1">
                  <span style="color:#166534;">Current Balance</span>
                  <span class="font-mono fw-bold" style="color:#166534;"><?= fmt_money($request['ewallet_balance']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                  <span style="color:#166534;">Reactivation Fee</span>
                  <span class="font-mono fw-bold text-danger">−<?= fmt_money($request['fee']) ?></span>
                </div>
                <hr class="my-2" style="border-color:#bbf7d0;">
                <div class="d-flex justify-content-between fw-bold">
                  <span style="color:#14532d;">Remaining After</span>
                  <span class="font-mono" style="color:#14532d;"><?= fmt_money($request['ewallet_balance'] - $request['fee']) ?></span>
                </div>
              </div>

              <hr class="my-3">

              <!-- Terms -->
              <div class="mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="termsCheck" required>
                  <label class="form-check-label" for="termsCheck" style="font-size:.8rem;color:var(--text-muted);">
                    I understand that reactivation resets my lifetime earnings counter to zero and starts a new cycle. Previous earnings are retained but do not count toward the new cap.
                  </label>
                </div>
              </div>

              <button type="submit" class="btn btn-primary w-100" id="reactivateBtn" disabled>
                🔄 Request Reactivation — <?= fmt_money($request['fee']) ?>
              </button>
            </form>

            <a href="<?= link_to('dashboard') ?>" class="btn btn-link btn-sm w-100 mt-2">← Back to Dashboard</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Info Card -->
    <div class="card">
      <div class="card-header"><span class="card-title">ℹ️ What Happens After Reactivation?</span></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <div class="d-flex align-items-start gap-3">
              <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">🔄</div>
              <div>
                <div class="fw-bold" style="font-size:.85rem;">Counter Resets</div>
                <div style="font-size:.78rem;color:var(--text-muted);">Your lifetime earnings counter returns to zero. You can earn up to the full cap again.</div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="d-flex align-items-start gap-3">
              <div style="width:40px;height:40px;border-radius:10px;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">💰</div>
              <div>
                <div class="fw-bold" style="font-size:.85rem;">Earnings Retained</div>
                <div style="font-size:.78rem;color:var(--text-muted);">All previously earned and withdrawn funds stay yours. Nothing is taken away.</div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="d-flex align-items-start gap-3">
              <div style="width:40px;height:40px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">⏰</div>
              <div>
                <div class="fw-bold" style="font-size:.85rem;">Time Sensitive</div>
                <div style="font-size:.78rem;color:var(--text-muted);">You have <strong><?= (int)$request['days_remaining'] ?> day(s)</strong> to reactivate. After that, your account becomes permanently inactive.</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .method-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: .55rem .9rem;
    background: #f8fafd;
    border: 1.5px solid #dde3ef;
    border-radius: .6rem;
    cursor: pointer;
    font-size: .8rem;
    font-weight: 600;
    color: #374151;
    transition: all .15s;
    min-width: 90px;
    text-align: center;
  }
  .method-option small {
    font-size: .65rem;
    font-weight: 400;
    color: #9ca3af;
  }
  .method-option input[type=radio] {
    display: none;
  }
  .method-option:has(input:checked) {
    border-color: var(--mc, var(--primary));
    background: color-mix(in srgb, var(--mc, var(--primary)) 10%, white);
    color: var(--mc, var(--primary));
  }
  .method-option:has(input:checked) small {
    color: var(--mc, var(--primary));
    opacity: .8;
  }
  .method-option.method-disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
  .method-option.method-disabled:has(input:checked) {
    border-color: #dc3545;
    background: #fff5f5;
    color: #dc3545;
  }
</style>

<script>
  (function () {
    const terms      = document.getElementById('termsCheck');
    const btn        = document.getElementById('reactivateBtn');
    const detailsBox = document.getElementById('adminPaymentDetails');
    const ewalletBox = document.getElementById('ewalletPreview');
    const proofInput = document.getElementById('proofImage');

    if (!terms || !btn) return;

    terms.addEventListener('change', function () {
      btn.disabled = !this.checked;
    });

    function switchMethod(method) {
      // Show/hide admin details box
      if (method === 'ewallet') {
        detailsBox.classList.add('d-none');
        ewalletBox.classList.remove('d-none');
        if (proofInput) proofInput.removeAttribute('required');
      } else {
        detailsBox.classList.remove('d-none');
        ewalletBox.classList.add('d-none');
        if (proofInput) proofInput.setAttribute('required', 'required');
      }

      // Show specific detail
      ['Gcash', 'Maya', 'UsdtTrc20', 'UsdtBep20'].forEach(function (m) {
        const el = document.getElementById('detail' + m);
        if (el) el.classList.add('d-none');
      });
      const detailMap = {
        'gcash': 'detailGcash',
        'maya': 'detailMaya',
        'usdt_trc20': 'detailUsdtTrc20',
        'usdt_bep20': 'detailUsdtBep20'
      };
      const activeDetail = document.getElementById(detailMap[method] || '');
      if (activeDetail) activeDetail.classList.remove('d-none');
    }

    // Attach listeners
    document.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        switchMethod(this.value);
      });
    });

    // Initialize
    const checked = document.querySelector('input[name="payment_method"]:checked');
    if (checked) switchMethod(checked.value);
  })();
</script>
<?php require 'views/partials/footer.php'; ?>
