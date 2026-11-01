<?php
/**
 * @file   views/member/activate.php
 * @brief  Member activation page for pending referral-link accounts
 */
?>
<?php $pageTitle = 'Activate Account'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="row justify-content-center">
      <div class="col-12 col-md-8 col-lg-6">
        <!-- Pending Banner -->
        <div class="card mb-3 border-warning" style="border-width:2px;">
          <div class="card-body">
            <div class="d-flex align-items-center gap-3">
              <div style="font-size:2rem;">⏳</div>
              <div>
                <h5 class="fw-700 mb-0">Account Pending Activation</h5>
                <p class="text-muted mb-0" style="font-size:.8rem;">
                  <?= $binaryPlaced ? 'Your binary position is reserved. Choose a binary package (e.g. Starter) to activate and start earning.' : 'No binary placement yet. Choose a package — a connection may be auto-selected on activation.' ?>
                </p>
              </div>
            </div>
          </div>
        </div>

        <?php if ($binaryPlaced): ?>
        <div class="alert alert-warning mb-3 py-2" style="font-size:.8rem;">
          🔗 Your binary position is reserved — activation is limited to binary packages only.
        </div>
        <?php if (empty($packages)): ?>
        <div class="alert alert-danger mb-3 py-2" style="font-size:.8rem;">
          No binary package is currently available for activation. Please use a binary-package registration code or contact support.
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Activation Form -->
        <div class="card">
          <div class="card-header"><span class="card-title">⚡ Activate Account</span></div>
          <div class="card-body">
            <form method="POST" action="<?= link_to('do_activate') ?>" id="activateForm">
              <?= csrf_field() ?>

              <!-- Payment Method -->
              <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size:.85rem;">Payment Method</label>
                <div class="payment-methods">
                  <label class="payment-option" for="pay_code">
                    <input type="radio" id="pay_code" name="payment_method" value="code" checked required>
                    <span class="payment-radio"></span>
                    <span class="payment-icon">🎫</span>
                    <span class="payment-text">Code</span>
                  </label>
                  <?php
                    $ewalletDisabled = !$canUseEwallet;
                    $ewalletTitle = '';
                    if ($ewalletDisabled) {
                      if (empty($packages)) {
                        $ewalletTitle = 'No binary package available for activation';
                      } else {
                        $ewalletTitle = 'Insufficient balance: '
                          . fmt_money((float)($user['ewallet_balance'] ?? 0))
                          . ' available, ' . fmt_money($minFee) . ' required';
                      }
                    }
                  ?>
                  <label class="payment-option <?= $ewalletDisabled ? 'payment-disabled' : '' ?>"
                    for="pay_ewallet"<?php if ($ewalletTitle): ?> title="<?= e($ewalletTitle) ?>"<?php endif; ?>>
                    <input type="radio" id="pay_ewallet" name="payment_method" value="ewallet"
                      <?= $ewalletDisabled ? 'disabled' : '' ?>>
                    <span class="payment-radio"></span>
                    <span class="payment-icon">💳</span>
                    <span class="payment-text">E-Wallet</span>
                    <?php if ($ewalletDisabled): ?>
                      <span class="ms-auto" style="font-size:.72rem;color:#dc2626;">Low balance</span>
                    <?php else: ?>
                      <span class="ms-auto" style="font-size:.72rem;color:#64748b;">Bal: <?= fmt_money((float)($user['ewallet_balance'] ?? 0)) ?></span>
                    <?php endif; ?>
                  </label>
                </div>
              </div>

              <style>
                .payment-methods {
                  display: flex;
                  flex-direction: column;
                  gap: 0.5rem;
                }
                .payment-option {
                  display: flex;
                  align-items: center;
                  gap: 0.625rem;
                  padding: 0.6rem 0.75rem;
                  border: 1.5px solid #e2e8f0;
                  border-radius: 0.55rem;
                  cursor: pointer;
                  transition: all 0.15s ease;
                  background: #fff;
                }
                .payment-option:hover {
                  border-color: #94a3b8;
                  background: #f8fafc;
                }
                .payment-option.payment-disabled {
                  opacity: .6;
                  cursor: not-allowed;
                  background: #f8fafc;
                }
                .payment-option.payment-disabled:hover {
                  border-color: #e2e8f0;
                  background: #f8fafc;
                }
                .payment-option input[type="radio"] {
                  display: none;
                }
                .payment-radio {
                  width: 18px;
                  height: 18px;
                  border: 2px solid #cbd5e1;
                  border-radius: 50%;
                  position: relative;
                  flex-shrink: 0;
                  transition: all 0.15s ease;
                }
                .payment-radio::after {
                  content: '';
                  position: absolute;
                  top: 50%;
                  left: 50%;
                  width: 9px;
                  height: 9px;
                  margin: 0;
                  border-radius: 50%;
                  background: #3b6ff0;
                  transform: translate(-50%, -50%) scale(0);
                  transition: transform 0.15s ease;
                }
                .payment-option input[type="radio"]:checked + .payment-radio {
                  border-color: #3b6ff0;
                }
                .payment-option input[type="radio"]:checked + .payment-radio::after {
                  transform: translate(-50%, -50%) scale(1);
                }
                .payment-option:has(input[type="radio"]:checked) {
                  border-color: #3b6ff0;
                  background: #f0f4ff;
                }
                .payment-icon {
                  font-size: 1.1rem;
                  line-height: 1;
                  flex-shrink: 0;
                }
                .payment-text {
                  font-size: 0.9rem;
                  font-weight: 500;
                  color: #1e293b;
                }
              </style>

              <!-- Code Input -->
              <div id="codeSection">
                <div class="mb-3">
                  <label class="form-label">Registration Code <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="text" id="reg_code" name="reg_code" class="form-control font-mono"
                      placeholder="XXXX-XXXX-XXXX" maxlength="14"
                      style="text-transform:uppercase;letter-spacing:2px;font-size:1rem;" required>
                    <button type="button" class="btn btn-outline-primary" id="validateCodeBtn">Validate</button>
                  </div>
                  <div class="form-text" id="codeHint"></div>
                </div>
                <input type="hidden" name="validated_code" id="validatedCode">

                <div id="packageInfo" class="code-verified d-none">
                  <span class="verify-icon">✅</span>
                  <div class="verify-body">
                    <div class="verify-title" id="pkgName"></div>
                    <div class="verify-subtitle" id="pkgDetails"></div>
                  </div>
                </div>

                <style>
                  #packageInfo.code-verified {
                    display: flex;
                    align-items: flex-start;
                    gap: 0.625rem;
                    background: #ecfdf5;
                    border: 1px solid #bbf7d0;
                    border-radius: 0.55rem;
                    padding: 0.875rem 1rem;
                    margin-bottom: 1rem;
                  }
                  #packageInfo.code-verified .verify-icon {
                    font-size: 1.25rem;
                    line-height: 1.4;
                    flex-shrink: 0;
                    margin-top: 0;
                  }
                  #packageInfo.code-verified .verify-body {
                    display: flex;
                    flex-direction: column;
                    gap: 0.125rem;
                    min-width: 0;
                  }
                  #packageInfo.code-verified .verify-title {
                    font-weight: 700;
                    font-size: 0.95rem;
                    color: #065f46;
                    line-height: 1.4;
                  }
                  #packageInfo.code-verified .verify-subtitle {
                    font-size: 0.8rem;
                    color: #047857;
                    line-height: 1.4;
                  }
                </style>
              </div>

              <!-- E-Wallet Package Selector -->
              <?php if ($canUseEwallet): ?>
              <div id="packageSection" style="display:none;">
                <div class="mb-3">
                  <label class="form-label">Package <span class="text-danger">*</span></label>
                  <?php $pkgCount = count($packages); ?>
                  <?php if ($pkgCount === 1): ?>
                    <?php $pkg = $packages[0]; ?>
                    <input type="hidden" name="package_id" id="packageId" value="<?= (int)$pkg['id'] ?>" data-pairing="<?= Package::hasPairing((int)$pkg['id']) ? '1' : '0' ?>">
                    <div class="card border-primary">
                      <div class="card-body">
                        <div class="fw-bold text-primary"><?= e($pkg['name']) ?></div>
                        <div style="font-size:.8rem;color:var(--muted);">
                          Entry: <?= fmt_money((float)$pkg['entry_fee']) ?> ·
                          Pair volume: <?= fmt_money((float)$pkg['pairing_bonus']) ?> ·
                          Cap: <?= fmt_money((float)$pkg['daily_pair_cap'] * (float)$pkg['pairing_bonus']) ?>/day
                        </div>
                      </div>
                    </div>
                    <div class="form-text text-success">✓ Package auto-selected.</div>
                  <?php else: ?>
                    <select class="form-select" id="packageSelect" name="package_id">
                      <option value="">Select a package…</option>
                      <?php foreach ($packages as $pkg): ?>
                        <option value="<?= (int)$pkg['id'] ?>"
                          data-name="<?= e($pkg['name']) ?>"
                          data-fee="<?= fmt_money((float)$pkg['entry_fee']) ?>"
                          data-bonus="<?= fmt_money((float)$pkg['pairing_bonus']) ?>"
                          data-cap="<?= (int)$pkg['daily_pair_cap'] ?>"
                          data-pairing="<?= Package::hasPairing((int)$pkg['id']) ? '1' : '0' ?>">
                          <?= e($pkg['name']) ?> — <?= fmt_money((float)$pkg['entry_fee']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="packageHint"></div>
                    <div id="packageCard" class="code-verified d-none mt-2">
                      <span style="font-size:1.2rem;">📦</span>
                      <div>
                        <div class="fw-bold" id="pkgCardName"></div>
                        <div style="font-size:.75rem;margin-top:2px;" id="pkgCardDetails"></div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="alert alert-info py-2 mb-3" style="font-size:.8rem;">
                  💳 Your balance:
                  <strong><?= fmt_money((float)($user['ewallet_balance'] ?? 0)) ?></strong>
                  (<?= fmt_money((float)($user['withdrawable_balance'] ?? 0)) ?> withdrawable)
                </div>
              </div>
              <?php endif; ?>

              <!-- Binary Connection (only when unplaced and a pairing package is chosen) -->
              <div id="binarySection" style="display:none;">
                <hr>
                <label class="form-label fw-semibold" style="font-size:.85rem;">Binary Connection</label>
                <div class="d-flex gap-2 mb-2">
                  <label class="btn btn-outline-primary" style="flex:1;">
                    <input type="radio" name="binary_mode" value="auto" checked class="d-none"> ✨ Auto
                  </label>
                  <label class="btn btn-outline-primary" style="flex:1;">
                    <input type="radio" name="binary_mode" value="manual" class="d-none"> 🖐 Manual
                  </label>
                </div>
                <p class="form-text" id="autoPreviewHint" style="display:none;"></p>

                <div id="manualBinaryFields" style="display:none;">
                  <div class="mb-2">
                    <label class="form-label">Binary Upline <span class="text-danger">*</span></label>
                    <input type="text" id="upline_username" name="upline_username" class="form-control font-mono" placeholder="username" autocomplete="off">
                    <div class="form-text" id="uplineHint"></div>
                  </div>
                  <div class="mb-2">
                    <label class="form-label">Position <span class="text-danger">*</span></label>
                    <div class="d-flex gap-2">
                      <label class="btn btn-outline-primary" style="flex:1;">
                        <input type="radio" id="pos_left" name="binary_position" value="left" class="d-none"> ↙ Left
                      </label>
                      <label class="btn btn-outline-primary" style="flex:1;">
                        <input type="radio" name="binary_position" value="right" class="d-none"> ↘ Right
                      </label>
                    </div>
                    <div class="form-text" id="positionHint"></div>
                  </div>
                </div>
              </div>

              <button type="submit" class="btn btn-primary w-100 btn-lg" id="submitBtn" disabled>
                ⚡ Activate Account
              </button>
            </form>

            <a href="<?= link_to('dashboard') ?>" class="btn btn-link btn-sm w-100 mt-2">← Back to Dashboard</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const IS_EWALLET_ENABLED = <?= $canUseEwallet ? 'true' : 'false' ?>;
  const PKG_COUNT = <?= (int)count($packages) ?>;
  const BINARY_PLACED = <?= $binaryPlaced ? 'true' : 'false' ?>;
  const FORCE_BINARY = <?= $binaryPlaced ? 'true' : 'false' ?>;
  const MY_USER_ID = <?= (int)$user['id'] ?>;
  let codeData = {}, selectedPkg = {}, uplineOk = false, slotData = {}, autoSuggestion = null;

  function setHint(id, msg, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'form-text' + (ok === true ? ' text-success' : ok === false ? ' text-danger' : '');
  }

  function getPaymentMethod() {
    return document.querySelector('[name=payment_method]:checked')?.value || 'code';
  }

  // Is the currently selected code/package binary-capable?
  function currentPairing() {
    if (getPaymentMethod() === 'code') return !!(codeData && codeData.pairing_enabled);
    if (selectedPkg.pairing === true || selectedPkg.pairing === false) return selectedPkg.pairing;
    const single = document.getElementById('packageId');
    if (single) return single.dataset.pairing === '1';
    const sel = document.getElementById('packageSelect');
    if (sel && sel.value) return sel.options[sel.selectedIndex].dataset.pairing === '1';
    return false;
  }

  function refreshAutoPreview() {
    const hint = document.getElementById('autoPreviewHint');
    if (!hint) return;
    hint.textContent = 'Finding the best position…';
    autoSuggestion = null;
    fetch('<?= link_to('auto_select_upline') ?>&exclude_id=' + MY_USER_ID)
      .then(r => r.json())
      .then(d => {
        if (!d.valid) {
          autoSuggestion = { valid: false };
          hint.textContent = d.message || 'No position available — you will become a binary network root.';
          return;
        }
        autoSuggestion = d;
        hint.textContent = 'Auto will place you under @' + d.upline_username + ' (' + d.position.charAt(0).toUpperCase() + d.position.slice(1) + ').';
      })
      .catch(() => {
        autoSuggestion = { valid: false };
        hint.textContent = 'Could not load placement suggestion.';
      });
  }

  function applyBinary() {
    const section = document.getElementById('binarySection');
    if (!section) return;
    const canShow = !BINARY_PLACED && currentPairing();
    section.style.display = canShow ? 'block' : 'none';
    renderBinaryChoice();
    const isAuto = document.querySelector('[name=binary_mode]:checked')?.value === 'auto';
    const manual = document.getElementById('manualBinaryFields');
    const hint = document.getElementById('autoPreviewHint');
    if (manual) manual.style.display = canShow && !isAuto ? 'block' : 'none';
    if (hint) hint.style.display = canShow && isAuto ? 'block' : 'none';
    const upEl = document.getElementById('upline_username');
    const left = document.getElementById('pos_left');
    const right = document.querySelector('[name=binary_position][value=right]');
    const needManual = canShow && !isAuto;
    if (upEl) upEl.required = needManual;
    if (left) left.required = needManual;
    if (right) right.required = needManual;
    if (!needManual) {
      if (upEl) upEl.value = '';
      if (left) left.checked = false;
      if (right) right.checked = false;
      uplineOk = false;
      slotData = {};
      setHint('uplineHint', '', null);
    }
    if (canShow && isAuto) refreshAutoPreview();
  }

  function renderBinaryChoice() {
    document.querySelectorAll('#binarySection label.btn').forEach(l => {
      const r = l.querySelector('input[type=radio]');
      if (!r) return;
      l.classList.toggle('btn-primary', r.checked);
      l.classList.toggle('btn-outline-primary', !r.checked);
    });
  }

  // Clicking an already-checked radio fires no `change` event, so listen to
  // both — clicking the active "Auto" still re-runs the preview for feedback.
  document.querySelectorAll('[name=binary_mode], [name=binary_position]').forEach(r => {
    r.addEventListener('change', applyBinary);
    r.addEventListener('click', applyBinary);
  });

  let upTimer2;
  function queueUplineCheck(ms) {
    const v = (document.getElementById('upline_username') || {}).value || '';
    uplineOk = false;
    slotData = {};
    clearTimeout(upTimer2);
    if (!v.trim()) {
      setHint('uplineHint', '', null);
      return;
    }
    setHint('uplineHint', 'Checking…', null);
    upTimer2 = setTimeout(async () => {
      const pos = document.querySelector('[name=binary_position]:checked')?.value || '';
      const d = await (await fetch('<?= link_to('check_upline') ?>&username=' + encodeURIComponent(v) + '&position=' + pos)).json();
      if (!d.valid) {
        setHint('uplineHint', '✗ ' + d.message, false);
        uplineOk = false;
        return;
      }
      slotData = d;
      uplineOk = true;
      setHint('uplineHint', '✓ Found @' + d.username, true);
    }, ms);
  }
  const upEl = document.getElementById('upline_username');
  if (upEl) upEl.addEventListener('input', () => queueUplineCheck(600));

  // Re-validate manual placement when the position changes under the same upline.
  document.querySelectorAll('[name=binary_position]').forEach(r => {
    r.addEventListener('change', () => queueUplineCheck(150));
  });

  function updateSubmitState() {
    const method = getPaymentMethod();
    const btn = document.getElementById('submitBtn');
    if (method === 'code') {
      btn.disabled = !document.getElementById('validatedCode').value;
    } else {
      if (PKG_COUNT === 1) {
        btn.disabled = false;
      } else {
        btn.disabled = !document.getElementById('packageSelect')?.value;
      }
    }
  }

  // Payment toggle
  document.querySelectorAll('[name=payment_method]').forEach(r => {
    r.addEventListener('change', function() {
      const method = this.value;
      const codeSec = document.getElementById('codeSection');
      const pkgSec = document.getElementById('packageSection');
      const regCode = document.getElementById('reg_code');
      const pkgSel = document.getElementById('packageSelect');

      if (method === 'code') {
        if (codeSec) codeSec.style.display = 'block';
        if (pkgSec) pkgSec.style.display = 'none';
        if (regCode) regCode.required = true;
        if (pkgSel) pkgSel.required = false;
      } else {
        if (codeSec) codeSec.style.display = 'none';
        if (pkgSec) pkgSec.style.display = 'block';
        if (regCode) regCode.required = false;
        if (pkgSel) pkgSel.required = true;
      }
      updateSubmitState();
      applyBinary();
    });
  });

  // Code formatting
  document.getElementById('reg_code').addEventListener('input', function() {
    const clean = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase().slice(0, 12);
    const parts = [clean.slice(0, 4), clean.slice(4, 8), clean.slice(8, 12)].filter(Boolean);
    this.value = parts.join('-');
    document.getElementById('validatedCode').value = '';
    document.getElementById('packageInfo').classList.add('d-none');
    setHint('codeHint', '', null);
    codeData = {};
    updateSubmitState();
    applyBinary();
  });

  // Validate code
  document.getElementById('validateCodeBtn').addEventListener('click', async function() {
    const code = document.getElementById('reg_code').value.trim();
    if (code.length < 14) {
      setHint('codeHint', 'Enter a complete code (XXXX-XXXX-XXXX)', false);
      return;
    }
    this.disabled = true;
    this.textContent = '…';
    try {
      const fd = new FormData();
      fd.append('code', code);
      fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
      const data = await (await fetch('<?= link_to('validate_code') ?>', {
        method: 'POST',
        body: fd
      })).json();
      if (data.valid) {
        if (FORCE_BINARY && !data.pairing_enabled) {
          codeData = {};
          document.getElementById('pkgName').textContent = '';
          document.getElementById('pkgDetails').textContent = '';
          document.getElementById('packageInfo').classList.add('d-none');
          document.getElementById('validatedCode').value = '';
          setHint('codeHint', '⚠ This code is for a non-binary package. Your binary position is reserved — use a binary package code (e.g. Starter).', false);
        } else {
          codeData = data;
          document.getElementById('pkgName').textContent = data.package_name;
          document.getElementById('pkgDetails').textContent =
            'Entry: ' + data.entry_fee + ' · Pair volume: ' + data.volume + ' · Cap: ' + data.cap_pesos + '/day';
          document.getElementById('packageInfo').classList.remove('d-none');
          document.getElementById('validatedCode').value = code;
          setHint('codeHint', '✓ Code is valid!', true);
        }
      } else {
        setHint('codeHint', data.message || 'Invalid code.', false);
      }
    } catch (e) {
      setHint('codeHint', 'Network error.', false);
    }
    this.disabled = false;
    this.textContent = 'Validate';
    updateSubmitState();
    applyBinary();
  });

  // Package selector
  const packageSelect = document.getElementById('packageSelect');
  if (packageSelect) {
    packageSelect.addEventListener('change', function() {
      const opt = this.options[this.selectedIndex];
      if (!this.value) {
        selectedPkg = {};
        document.getElementById('packageCard')?.classList.add('d-none');
        setHint('packageHint', '', null);
        updateSubmitState();
        applyBinary();
        return;
      }
      selectedPkg = {
        id: this.value,
        name: opt.dataset.name,
        fee: opt.dataset.fee,
        bonus: opt.dataset.bonus,
        cap: opt.dataset.cap,
        pairing: opt.dataset.pairing === '1'
      };
      document.getElementById('pkgCardName').textContent = selectedPkg.name;
      document.getElementById('pkgCardDetails').textContent =
        'Entry: ' + selectedPkg.fee + ' · Bonus: ' + selectedPkg.bonus + ' · Cap: ' + selectedPkg.cap + ' pairs/day';
      document.getElementById('packageCard').classList.remove('d-none');
      setHint('packageHint', '✓ Package selected.', true);
      updateSubmitState();
      applyBinary();
    });
  }

  // Submit (validate manual binary before activating)
  document.getElementById('activateForm').addEventListener('submit', function(e) {
    const section = document.getElementById('binarySection');
    if (section && section.style.display !== 'none') {
      const isAuto = document.querySelector('[name=binary_mode]:checked')?.value === 'auto';
      if (!isAuto) {
        if (!uplineOk) {
          setHint('uplineHint', 'Please enter a valid upline.', false);
          e.preventDefault();
          return;
        }
        if (!document.querySelector('[name=binary_position]:checked')) {
          setHint('positionHint', 'Please select a position.', false);
          e.preventDefault();
          return;
        }
      }
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Activating…';
  });

  updateSubmitState();
  applyBinary();
})();
</script>
<?php require 'views/partials/footer.php'; ?>
