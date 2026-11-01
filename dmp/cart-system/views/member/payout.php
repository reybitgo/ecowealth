<?php

/**
 * @file   views/member/payout.php
 * @brief  Member payout UI
 */
?>
<?php
$pageTitle        = 'Payouts';
$minPayout        = (float)setting('min_payout', '500');
$availableBalance = (float)$user['ewallet_balance'];
$withdrawableBalance = (float)($user['withdrawable_balance'] ?? 0);
$nonWithdrawableBalance = $availableBalance - $withdrawableBalance;
$hasPending       = false;
foreach ($history['data'] as $h) {
  if ($h['status'] === 'pending') {
    $hasPending = true;
    break;
  }
}

// ── Payout method availability (admin-controlled) ────────────────────────────
$gcashEnabled = setting('gcash_enabled', '1') === '1';
$mayaEnabled  = setting('maya_enabled', '1') === '1';

$methods = [];
if ($gcashEnabled) {
  $methods[] = ['gcash', 'GCash', '#0070d8', $user['gcash_number'] ?? ''];
}
if ($mayaEnabled) {
  $methods[] = ['maya', 'Maya', '#48b0db', $user['maya_number'] ?? ''];
}
// USDT networks are always enabled
$methods[] = ['usdt_trc20', 'USDT TRC20', '#26a17b', $user['usdt_trc20_address'] ?? ''];
$methods[] = ['usdt_bep20', 'USDT BEP20', '#f0b90b', $user['usdt_bep20_address'] ?? ''];

// Default: first method with saved account, or first available
$defaultMethod = 'usdt_trc20';
foreach ($methods as $m) {
  if (!empty($m[3])) {
    $defaultMethod = $m[0];
    break;
  }
}

// Build clean JS config arrays (avoids PHP tags inside JS objects)
$jsFeePct = [];
if ($gcashEnabled) $jsFeePct['gcash'] = (float)setting('service_fee_gcash', '0');
if ($mayaEnabled)  $jsFeePct['maya']  = (float)setting('service_fee_maya', '0');
$jsFeePct['usdt_trc20']  = (float)setting('service_fee_usdt_trc20', '5');
$jsFeePct['usdt_bep20'] = (float)setting('service_fee_usdt_bep20', '5');

$jsAccounts = [];
if ($gcashEnabled) $jsAccounts['gcash'] = $user['gcash_number'] ?? '';
if ($mayaEnabled)  $jsAccounts['maya']  = $user['maya_number']  ?? '';
$jsAccounts['usdt_trc20'] = $user['usdt_trc20_address'] ?? '';
$jsAccounts['usdt_bep20'] = $user['usdt_bep20_address'] ?? '';

$jsLabels = [];
if ($gcashEnabled) {
  $jsLabels['gcash'] = ['label' => 'GCash Number', 'placeholder' => '09XXXXXXXXX', 'type' => 'tel', 'mono' => false];
}
if ($mayaEnabled) {
  $jsLabels['maya'] = ['label' => 'Maya Number', 'placeholder' => '09XXXXXXXXX', 'type' => 'tel', 'mono' => false];
}
$jsLabels['usdt_trc20']  = ['label' => 'USDT TRC20 Address', 'placeholder' => 'T... (34 characters)', 'type' => 'text', 'mono' => true];
$jsLabels['usdt_bep20'] = ['label' => 'USDT BEP20 Address', 'placeholder' => '0x... (42 characters)', 'type' => 'text', 'mono' => true];

$jsHints = [];
if ($gcashEnabled) $jsHints['gcash'] = 'Funds will be sent to this GCash number.';
if ($mayaEnabled)  $jsHints['maya']  = 'Funds will be sent to this Maya number.';
$jsHints['usdt_trc20']  = 'USDT will be sent to this TRC20 wallet address.';
$jsHints['usdt_bep20'] = 'USDT will be sent to this BEP20 wallet address (Binance Smart Chain).';
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>
    <div class="row g-3 mb-3">
      <!-- Balance hero -->
      <div class="col-12 col-md-6">
        <div class="card h-100" style="background:linear-gradient(135deg,#1a3a8f,#3b6ff0);border:none;">
          <div class="card-body text-white">
            <div style="font-size:.68rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;opacity:.7;margin-bottom:.5rem;">Available Balance</div>
            <div style="font-size:2.2rem;font-weight:800;font-family:var(--font-mono);line-height:1;"><?= fmt_money($availableBalance) ?></div>
            <div style="font-size:.78rem;opacity:.85;margin-top:.5rem;">
              ✅ Withdrawable: <?= fmt_money($withdrawableBalance) ?>
              <?php if ($nonWithdrawableBalance > 0): ?>
                <br>🔒 Non-Withdrawable: <?= fmt_money($nonWithdrawableBalance) ?> (internal use only)
              <?php endif; ?>
            </div>
            <div style="font-size:.75rem;opacity:.6;margin-top:.5rem;">Minimum withdrawal: <?= fmt_money($minPayout) ?></div>
          </div>
        </div>
      </div>
      <!-- Request form -->
      <div class="col-12 col-md-6">
        <div class="card h-100">
          <div class="card-header"><span class="card-title">💳 Request Payout</span></div>
          <div class="card-body">
            <?php if ($hasPending): ?>
              <div class="alert alert-warning mb-0">⏳ You already have a pending payout request.</div>
            <?php elseif ($withdrawableBalance < $minPayout): ?>
              <div class="alert alert-info mb-0">ℹ Minimum payout is <?= fmt_money($minPayout) ?>. Withdrawable balance: <?= fmt_money($withdrawableBalance) ?>.</div>
            <?php else: ?>
              <form method="POST" action="<?= link_to('request_payout') ?>" id="payoutForm">
                <?= csrf_field() ?>
                <div class="mb-3">
                  <label class="form-label">Amount <span class="text-danger">*</span></label>
                  <input type="number" name="amount" id="amountInput" class="form-control" inputmode="numeric"
                    min="<?= $minPayout ?>" max="<?= $withdrawableBalance ?>" step="1" required
                    placeholder="Min <?= fmt_money($minPayout) ?>" oninput="checkAmount(this.value)">
                  <div class="form-text" id="amountHint">Max withdrawable: <?= fmt_money($withdrawableBalance) ?></div>
                </div>
                <div class="mb-3">
                  <label class="form-label">Payout Method <span class="text-danger">*</span></label>
                  <div class="d-flex gap-2 flex-wrap" id="methodBtns">
                    <?php foreach ($methods as $idx => [$val, $label, $color, $saved]): ?>
                      <label class="method-option <?= $saved ? '' : 'needs-account' ?>"
                        style="--mc:<?= $color ?>;"
                        title="<?= $saved ? '' : 'No account saved for this method — set it in Profile first' ?>">
                        <input type="radio" name="payout_method" value="<?= $val ?>"
                          <?= $val === $defaultMethod ? 'checked' : '' ?>
                          onchange="switchMethod(<?= js($val) ?>,<?= js($saved) ?>)">
                        <span><?= $label ?></span>
                        <?php if ($saved): ?><small class="font-mono"><?= e(mask_account($saved)) ?></small><?php endif; ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php
                $defaultMethodDef = null;
                foreach ($methods as $m) {
                    if ($m[0] === $defaultMethod) {
                        $defaultMethodDef = $m;
                        break;
                    }
                }
                $defaultMethodDef = $defaultMethodDef ?: ['usdt_trc20', 'USDT TRC20', '#26a17b', $user['usdt_trc20_address'] ?? ''];
                ?>
                <div class="mb-3" id="accountGroup">
                  <label class="form-label" id="accountLabel"><?= $defaultMethodDef[1] ?> <span class="text-danger">*</span></label>
                  <input type="text" name="payout_account" id="accountInput" class="form-control"
                    value="<?= e($defaultMethodDef[3]) ?>"
                    placeholder="<?= $defaultMethodDef[0] === 'usdt_trc20' ? 'T... (34 characters)' : ($defaultMethodDef[0] === 'usdt_bep20' ? '0x... (42 characters)' : '09XXXXXXXXX') ?>" required>
                  <div class="form-text" id="accountHint">Funds will be sent to this account.</div>
                </div>

                <!-- Fee Preview Box -->
                <div id="feePreview" class="rounded p-3 mb-3" style="background:#f8fafd;border:1px solid #dde3ef;font-size:.82rem;display:none;">
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Requested Amount</span>
                    <span id="previewAmount" class="font-mono">—</span>
                  </div>
                  <div class="d-flex justify-content-between mb-1 d-none" id="previewFeeRow">
                    <span class="text-muted" id="previewFeeLabel">Service Fee (0%)</span>
                    <span id="previewFee" class="font-mono text-danger">—</span>
                  </div>
                  <div class="d-flex justify-content-between mb-1 d-none" id="previewGasRow">
                    <span class="text-muted"><span id="previewGasLabel">TRC20 Gas Fee</span> (<span id="previewGasUsdt"></span> USDT)</span>
                    <span id="previewGasPhp" class="font-mono text-danger">—</span>
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between fw-bold">
                    <span>You Receive</span>
                    <span id="previewNet" class="font-mono text-success">—</span>
                  </div>
                  <div id="previewUsdtRow" class="mt-2 text-center p-2 rounded d-none" style="background:#dcfce7;">
                    <div class="text-muted" style="font-size:.7rem;">USDT to Wallet</div>
                    <div class="fw-bold text-success" id="previewUsdtAmt" style="font-size:1.3rem;font-family:monospace;">0.0000</div>
                    <div class="text-muted" id="previewRate" style="font-size:.68rem;"></div>
                  </div>
                </div>

                <!-- Hidden fields for server-side fee calculation -->
                <input type="hidden" name="usdt_trc20_rate" id="usdtTrc20RateInput" value="0">
                <input type="hidden" name="usdt_bep20_rate" id="usdtBep20RateInput" value="0">

                <button type="submit" class="btn btn-primary w-100" id="submitBtn">Submit Payout Request</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <form method="GET" action="<?= APP_URL ?>/" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <input type="hidden" name="page" value="payout">
          <?php if (is_imp_session()): ?><input type="hidden" name="imp" value="<?= e(session_id()) ?>"><?php endif; ?>
          <span class="card-title">📋 Payout History</span>

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
              <th>Requested</th>
              <th>Requested (₱)</th>
              <th>Net / USDT</th>
              <th>Method</th>
              <th>Account</th>
              <th>Status</th>
              <th>Processed</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($history['data'])): ?>
              <tr>
                <td colspan="7" class="text-center py-5 text-muted">No payout requests yet.</td>
              </tr>
              <?php else: foreach ($history['data'] as $row):
                $method  = $row['payout_method']  ?: 'gcash';
                $account = $row['payout_account'];
                $methodLabel = match ($method) {
                  'maya'        => 'Maya',
                  'usdt_trc20'  => 'USDT TRC20',
                  'usdt_bep20'  => 'USDT BEP20',
                  default       => 'GCash'
                };
                $methodColor = match ($method) {
                  'maya'        => '#48b0db',
                  'usdt_trc20'  => '#26a17b',
                  'usdt_bep20'  => '#f0b90b',
                  default       => '#0070d8'
                };
              ?>
                <tr>
                  <td class="td-muted" style="font-size:.75rem;"><?= fmt_datetime($row['requested_at']) ?></td>
                  <td class="font-mono fw-bold"><?= fmt_money($row['amount']) ?></td>
                  <td>
                    <?php if ($row['payout_method'] === 'usdt_trc20' || $row['payout_method'] === 'usdt_bep20'):
                      $rateCol   = $row['payout_method'] === 'usdt_bep20' ? 'usdt_bep20_rate'   : 'usdt_trc20_rate';
                      $amountCol = $row['payout_method'] === 'usdt_bep20' ? 'usdt_bep20_amount' : 'usdt_trc20_amount';
                      ?>
                      <?php if ($row[$amountCol] > 0): ?>
                        <div class="fw-bold text-success font-mono"><?= number_format($row[$amountCol], 4) ?> USDT</div>
                        <?php if ($row[$rateCol] > 0): ?>
                          <div class="text-muted" style="font-size:.68rem;">@ ₱<?= number_format($row[$rateCol], 2) ?></div>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <?php
                      $netAmount = $row['amount'] - ($row['service_fee_amount'] ?? 0);
                      ?>
                      <div class="font-mono fw-bold"><?= fmt_money($netAmount) ?></div>
                      <?php if (($row['service_fee_amount'] ?? 0) > 0): ?>
                        <div class="text-muted" style="font-size:.68rem;">Fee: <?= fmt_money($row['service_fee_amount']) ?></div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge" style="background:<?= $methodColor ?>20;color:<?= $methodColor ?>;border:1px solid <?= $methodColor ?>40;"><?= $methodLabel ?></span></td>
                  <td class="td-muted font-mono" style="font-size:.78rem;"><?= $account ? e($account) : '<span class="text-muted">—</span>' ?></td>
                  <td><?php
                      $b = match ($row['status']) {
                        'pending' => 'bg-warning-subtle text-warning',
                        'approved' => 'bg-info-subtle text-info',
                        'completed' => 'bg-success-subtle text-success',
                        'rejected' => 'bg-danger-subtle text-danger',
                        default => 'bg-secondary-subtle text-secondary'
                      };
                      ?><span class="badge <?= $b ?>"><?= ucfirst($row['status']) ?></span></td>
                  <td class="td-muted" style="font-size:.75rem;"><?= $row['processed_at'] ? fmt_datetime($row['processed_at']) : '—' ?></td>
                  <td class="td-muted" style="font-size:.75rem;"><?= $row['admin_note'] ? e($row['admin_note']) : '—' ?></td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($history['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($history, link_to('payout', ['per_page' => $perPage])) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
  .method-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: .55rem 1rem;
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

  /* Style for methods that need account setup — enabled but no account saved */
  .method-option.needs-account {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .method-option.needs-account:has(input:checked) {
    border-color: #dc3545;
    background: #fff5f5;
    color: #dc3545;
  }

  .method-option.needs-account:has(input:checked) small {
    color: #dc3545;
  }
</style>

<script>
  // ── PHP-injected config (valid JSON — no PHP tags inside JS for IDE compatibility) ──
  window.PAYOUT_CONFIG = {
    feePct: <?= json_encode($jsFeePct) ?>,
    accounts: <?= json_encode($jsAccounts) ?>,
    labels: <?= json_encode($jsLabels) ?>,
    hints: <?= json_encode($jsHints) ?>,
    defaultMethod: '<?= $defaultMethod ?>',
    availableBalance: <?= $availableBalance ?>,
    minPayout: <?= $minPayout ?>,
    appUrl: '<?= APP_URL ?>',
    impQuery: '<?= is_imp_session() ? '&imp=' . e(session_id()) : '' ?>',
    usdtTrc20GasFee: <?= (float)setting('usdt_trc20_gas_fee', '2.50') ?>,
    usdtBep20GasFee: <?= (float)setting('usdt_bep20_gas_fee', '0.05') ?>,
  };

  const FEE_PCT = window.PAYOUT_CONFIG.feePct;
  const ACCOUNTS = window.PAYOUT_CONFIG.accounts;
  const LABELS = window.PAYOUT_CONFIG.labels;

  let currentMethod = window.PAYOUT_CONFIG.defaultMethod;
  let liveRate = 0; // PHP per 1 USDT
  let trc20GasInFlight = null;
  let bep20GasInFlight = null;

  // ── Fetch live USDT/PHP rate (shared by TRC20 & BEP20) ───────────────────────
  async function fetchRate() {
    try {
      const saved = localStorage.getItem('usdt_rate_cache');
      if (saved) {
        const c = JSON.parse(saved);
        if ((Date.now() - c.ts) < 300000) { // 5 min cache
          liveRate = c.rate;
          document.getElementById('usdtTrc20RateInput').value = liveRate;
          document.getElementById('usdtBep20RateInput').value = liveRate;
          updatePreview();
          return;
        }
      }
      const res = await fetch('https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=php');
      const data = await res.json();
      liveRate = data.tether.php;
      localStorage.setItem('usdt_rate_cache', JSON.stringify({
        rate: liveRate,
        ts: Date.now()
      }));
      document.getElementById('usdtTrc20RateInput').value = liveRate;
      document.getElementById('usdtBep20RateInput').value = liveRate;
    } catch (e) {
      console.warn('Rate fetch failed, using cached/default.');
    }
    updatePreview();
  }

  // ── Live TRC20 gas fee fetch (two strategies, persists to DB if changed) ─────
  async function fetchTrc20GasFeeInternal() {
    const GAS_CACHE_KEY = 'usdt_trc20_gas_fee_cache';
    const GAS_CACHE_TTL = 15 * 60 * 1000; // 15 minutes

    // Check local cache first
    try {
      const cached = localStorage.getItem(GAS_CACHE_KEY);
      if (cached) {
        const c = JSON.parse(cached);
        if ((Date.now() - c.ts) < GAS_CACHE_TTL) {
          window.PAYOUT_CONFIG.usdtTrc20GasFee = c.fee;
          updatePreview();
          return;
        }
      }
    } catch (e) {}

    let newFee = null;

    // Strategy 1: TRON network parameters → derive energy cost → convert to USDT
    try {
      const res = await fetch('https://api.trongrid.io/wallet/getchainparameters', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
      });
      if (!res.ok) throw new Error('TRON API error');
      const data = await res.json();

      const params = data.chainParameter || [];
      const energyFee = params.find(p => p.key === 'getEnergyFee')?.value || 420;
      const bandwidthFee = params.find(p => p.key === 'getTransactionFee')?.value || 1000;

      // Typical USDT TRC20 transfer: ~65,000 energy + bandwidth overhead
      const totalSun = (energyFee * 65000) + bandwidthFee;
      const trxCost = totalSun / 1_000_000; // sun → TRX

      // Approximate TRX/USDT ratio (~0.12 USD per TRX)
      newFee = parseFloat((trxCost * 0.12).toFixed(4));
      console.log('Gas fee from TRON network params:', newFee, 'USDT');
    } catch (e) {
      console.warn('TRON API failed, trying CoinGecko TRX price...');
    }

    // Strategy 2: CoinGecko TRX price × standard transfer cost
    if (!newFee) {
      try {
        const res = await fetch('https://api.coingecko.com/api/v3/simple/price?ids=tron&vs_currencies=usd');
        const data = await res.json();
        const trxPrice = data.tron.usd;

        // Conservative: 13.5 TRX per USDT TRC20 transfer
        newFee = parseFloat((13.5 * trxPrice).toFixed(4));
        console.log('Gas fee from CoinGecko TRX price:', newFee, 'USDT');
      } catch (e) {
        console.warn('Both gas fee APIs failed — keeping DB value:', window.PAYOUT_CONFIG.usdtTrc20GasFee);
        return;
      }
    }

    if (!newFee || newFee <= 0) return;

    // Update in-memory + local cache
    window.PAYOUT_CONFIG.usdtTrc20GasFee = newFee;
    localStorage.setItem(GAS_CACHE_KEY, JSON.stringify({
      fee: newFee,
      ts: Date.now()
    }));
    updatePreview();

    // Persist to DB in background — only if value changed
    try {
      await fetch(window.PAYOUT_CONFIG.appUrl + '/?page=update_usdt_gas' + window.PAYOUT_CONFIG.impQuery, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          fee: newFee
        }),
      });
      console.log('Gas fee synced to DB:', newFee, 'USDT');
    } catch (e) {
      console.warn('DB sync for gas fee failed (non-critical):', e);
    }
  }

  function fetchTrc20GasFee() {
    if (!trc20GasInFlight) {
      trc20GasInFlight = fetchTrc20GasFeeInternal().finally(() => {
        trc20GasInFlight = null;
      });
    }
    return trc20GasInFlight;
  }

  // ── Live BEP20 gas fee fetch (CoinGecko BNB price, persists to DB) ───────────
  async function fetchBep20GasFeeInternal() {
    const GAS_CACHE_KEY = 'usdt_bep20_gas_fee_cache';
    const GAS_CACHE_TTL = 15 * 60 * 1000; // 15 minutes

    // Check local cache first
    try {
      const cached = localStorage.getItem(GAS_CACHE_KEY);
      if (cached) {
        const c = JSON.parse(cached);
        if ((Date.now() - c.ts) < GAS_CACHE_TTL) {
          window.PAYOUT_CONFIG.usdtBep20GasFee = c.fee;
          updatePreview();
          return;
        }
      }
    } catch (e) {}

    let newFee = null;

    try {
      // Fetch BNB and USDT prices in USD from CoinGecko
      const res = await fetch('https://api.coingecko.com/api/v3/simple/price?ids=binancecoin,tether&vs_currencies=usd');
      const data = await res.json();
      const bnbUsd  = data?.binancecoin?.usd  || 0;
      const usdtUsd = data?.tether?.usd       || 1;

      if (bnbUsd > 0 && usdtUsd > 0) {
        // Typical USDT BEP20 transfer: ~65,000 gas @ 5 gwei
        const gasUnits   = 65000;
        const gasPriceGwei = 5;
        const gasBnb     = (gasUnits * gasPriceGwei) / 1e9;
        const gasUsd     = gasBnb * bnbUsd;
        const gasUsdt    = gasUsd / usdtUsd;

        newFee = parseFloat(gasUsdt.toFixed(6));
        console.log('BEP20 gas fee from CoinGecko BNB price:', newFee, 'USDT');
      }
    } catch (e) {
      console.warn('BEP20 gas fee API failed — keeping DB value:', window.PAYOUT_CONFIG.usdtBep20GasFee);
      return;
    }

    if (!newFee || newFee <= 0) return;

    // Update in-memory + local cache
    window.PAYOUT_CONFIG.usdtBep20GasFee = newFee;
    localStorage.setItem(GAS_CACHE_KEY, JSON.stringify({
      fee: newFee,
      ts: Date.now()
    }));
    updatePreview();

    // Persist to DB in background — only if value changed
    try {
      await fetch(window.PAYOUT_CONFIG.appUrl + '/?page=update_usdt_bep20_gas' + window.PAYOUT_CONFIG.impQuery, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          fee: newFee
        }),
      });
      console.log('BEP20 gas fee synced to DB:', newFee, 'USDT');
    } catch (e) {
      console.warn('DB sync for BEP20 gas fee failed (non-critical):', e);
    }
  }

  function fetchBep20GasFee() {
    if (!bep20GasInFlight) {
      bep20GasInFlight = fetchBep20GasFeeInternal().finally(() => {
        bep20GasInFlight = null;
      });
    }
    return bep20GasInFlight;
  }

  // ── Method switch ────────────────────────────────────────────────────────────
  function switchMethod(method, account) {
    currentMethod = method;
    const input = document.getElementById('accountInput');
    const label = document.getElementById('accountLabel');
    const hint = document.getElementById('accountHint');
    const cfg = LABELS[method];

    if (!cfg) return;

    label.innerHTML = cfg.label + ' <span class="text-danger">*</span>';
    input.placeholder = cfg.placeholder;
    input.type = cfg.type;
    input.value = account || ACCOUNTS[method] || '';
    input.className = 'form-control' + (cfg.mono ? ' font-mono' : '');

    const hints = window.PAYOUT_CONFIG.hints;
    hint.textContent = hints[method] || 'Funds will be sent to this account.';

    if ((method === 'usdt_trc20' || method === 'usdt_bep20') && !liveRate) fetchRate();
    if (method === 'usdt_trc20') fetchTrc20GasFee();
    if (method === 'usdt_bep20') fetchBep20GasFee();
    updatePreview();
  }

  // ── Fee preview ──────────────────────────────────────────────────────────────
  function updatePreview() {
    const amt = parseFloat(document.getElementById('amountInput')?.value) || 0;
    if (!amt || amt <= 0) {
      document.getElementById('feePreview').style.display = 'none';
      return;
    }

    const feePct = FEE_PCT[currentMethod] || 0;
    const feeAmt = amt * feePct / 100;
    const netPhp = amt - feeAmt;
    const preview = document.getElementById('feePreview');

    preview.style.display = 'block';
    document.getElementById('previewAmount').textContent = '₱' + amt.toLocaleString('en-PH', {
      minimumFractionDigits: 2
    });

    const feeRow = document.getElementById('previewFeeRow');
    if (feePct > 0) {
      feeRow.classList.remove('d-none');
      feeRow.classList.add('d-flex');
      document.getElementById('previewFeeLabel').textContent = `Service Fee (${feePct}%)`;
      document.getElementById('previewFee').textContent = '−₱' + feeAmt.toLocaleString('en-PH', {
        minimumFractionDigits: 2
      });
    } else {
      feeRow.classList.remove('d-flex');
      feeRow.classList.add('d-none');
    }

    const gasRow = document.getElementById('previewGasRow');
    const usdtRow = document.getElementById('previewUsdtRow');

    if ((currentMethod === 'usdt_trc20' || currentMethod === 'usdt_bep20') && liveRate > 0) {
      const isBep20 = currentMethod === 'usdt_bep20';
      const gasFeeUsdt = isBep20 ? window.PAYOUT_CONFIG.usdtBep20GasFee : window.PAYOUT_CONFIG.usdtTrc20GasFee;
      const gasLabel   = isBep20 ? 'BEP20 Gas Fee' : 'TRC20 Gas Fee';
      const gasPhp = gasFeeUsdt * liveRate;
      const netAfterGas = netPhp - gasPhp;
      const usdtAmt = netAfterGas > 0 ? netAfterGas / liveRate : 0;

      gasRow.classList.remove('d-none');
      gasRow.classList.add('d-flex');
      document.getElementById('previewGasLabel').textContent = gasLabel;
      document.getElementById('previewGasUsdt').textContent = gasFeeUsdt.toFixed(isBep20 ? 6 : 2);
      document.getElementById('previewGasPhp').textContent = '−₱' + gasPhp.toLocaleString('en-PH', {
        minimumFractionDigits: 2
      });
      document.getElementById('previewNet').textContent = usdtAmt.toFixed(4) + ' USDT';
      usdtRow.classList.remove('d-none');
      document.getElementById('previewUsdtAmt').textContent = usdtAmt.toFixed(4);
      document.getElementById('previewRate').textContent = '@ ₱' + liveRate.toLocaleString('en-PH', {
        minimumFractionDigits: 2
      }) + ' per USDT';
      document.getElementById(isBep20 ? 'usdtBep20RateInput' : 'usdtTrc20RateInput').value = liveRate;
    } else {
      gasRow.classList.remove('d-flex');
      gasRow.classList.add('d-none');
      usdtRow.classList.add('d-none');
      document.getElementById('previewNet').textContent = '₱' + netPhp.toLocaleString('en-PH', {
        minimumFractionDigits: 2
      });
    }
  }

  // ── Amount check ─────────────────────────────────────────────────────────────
  function checkAmount(v) {
    const el = document.getElementById('amountHint');
    const n = parseFloat(v) || 0;
    const max = window.PAYOUT_CONFIG.availableBalance;
    const min = window.PAYOUT_CONFIG.minPayout;
    if (n > max) el.innerHTML = '<span class="text-danger">Exceeds balance of ₱' + max.toLocaleString('en-PH', {
      minimumFractionDigits: 2
    }) + '</span>';
    else if (n < min && n > 0) el.innerHTML = '<span class="text-danger">Minimum is ₱' + min.toLocaleString('en-PH', {
      minimumFractionDigits: 2
    }) + '</span>';
    else el.textContent = 'Max: ₱' + max.toLocaleString('en-PH', {
      minimumFractionDigits: 2
    });
    updatePreview();
  }

  // ── Init ──────────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    const checked = document.querySelector('[name=payout_method]:checked');
    if (checked) switchMethod(checked.value, ACCOUNTS[checked.value]);
  });
</script>

<?php require 'views/partials/footer.php'; ?>