<?php

/**
 * @file   views/admin/codes.php
 * @brief  Registration codes management UI
 */
?>
<?php $pageTitle = 'Registration Codes'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <!-- Stats -->
    <div class="row g-3 mb-3">
      <?php foreach (
        [
          ['Total Codes',  $stats['total'],  'primary', 'primary'],
          ['Unused',       $stats['unused'], 'success', 'success'],
          ['Used / Sold',  $stats['used'],   'orange', 'warning'],
          ['Revenue',      fmt_money($stats['revenue']), 'purple', 'purple'],
        ] as [$label, $val, $accent, $color]
      ): ?>
        <div class="col-6 col-xl-3">
          <div class="card stat-card">
            <div class="stat-accent stat-accent-<?= $accent ?>"></div>
            <div class="card-body pt-4">
              <div class="stat-label"><?= $label ?></div>
              <div class="stat-value text-<?= $color === 'purple' ? 'primary' : $color ?>"><?= is_numeric($val) ? number_format($val) : $val ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
      <!-- Generate form -->
      <div class="col-12 col-lg-6">
        <div class="card no-print">
          <div class="card-header"><span class="card-title">⚡ Generate Codes</span></div>
          <div class="card-body">
            <form id="genCodesForm" method="POST" action="<?= APP_URL ?>/?page=admin_gen_codes">
              <?= csrf_field() ?>
              <div class="mb-3">
                <label class="form-label">Package <span class="text-danger">*</span></label>
                <select name="package_id" class="form-select" required onchange="autoPrice(this)" id="genPkgSelect">
                  <option value="">— Select package —</option>
                  <?php foreach ($packages as $pkg): ?>
                    <option value="<?= $pkg['id'] ?>" data-entry="<?= $pkg['entry_fee'] ?>"><?= e($pkg['name']) ?> (entry: <?= fmt_money($pkg['entry_fee']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="row g-3 mb-3">
                <div class="col-6">
                  <label class="form-label">Quantity <span class="text-danger">*</span></label>
                  <input type="number" name="quantity" id="genQty" class="form-control" min="1" max="500" value="10" required>
                  <div class="form-text">Max 500 per batch</div>
                </div>
                <div class="col-6">
                  <label class="form-label">Code Price (₱) <span class="text-danger">*</span></label>
                  <input type="number" name="price" id="genPrice" class="form-control" min="0" step="0.01" required placeholder="10500.00">
                  <div class="form-text">Your selling price per code</div>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Expiry Date <span class="text-muted">(optional)</span></label>
                <input type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
              </div>
              <div class="mb-3">
                <label class="form-label">Code Type</label>
                <select name="code_type" id="genCodeType" class="form-select">
                  <option value="registration">🎟️ Registration Code</option>
                  <option value="cd">⏳ CD Code — auto-assigns Commission-Deduct</option>
                  <option value="upgrade">⬆️ Upgrade Code — pays package upgrade diff</option>
                </select>
                <div class="form-text">CD codes are prefixed <code>CD-</code>, upgrade codes <code>UP-</code>. Registration rejects upgrade codes.</div>
              </div>
              <!-- Trigger modal confirm INSTEAD of direct submit -->
              <button type="button" class="btn btn-primary w-100" onclick="confirmGenerate()">
                🎟️ Generate Codes
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- Filters + Export -->
      <div class="col-12 col-lg-6">
        <div class="card no-print">
          <div class="card-header"><span class="card-title">🔍 Filter & Export</span></div>
          <div class="card-body">
            <form method="GET" action="<?= APP_URL ?>/">
              <input type="hidden" name="page" value="admin_codes">
              <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                  <option value="">All Statuses</option>
                  <?php foreach (['unused', 'used', 'expired'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Package</label>
                <select name="pkg" class="form-select" onchange="this.form.submit()">
                  <option value="">All Packages</option>
                  <?php foreach ($packages as $pkg): ?>
                    <option value="<?= $pkg['id'] ?>" <?= ($pkgId ?? 0) == $pkg['id'] ? 'selected' : '' ?>><?= e($pkg['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </form>
            <hr>
            <div class="d-flex flex-column gap-2">
              <a href="<?= APP_URL ?>/?page=admin_export_codes&status=<?= urlencode($status ?? '') ?>&pkg=<?= $pkgId ?? 0 ?>"
                class="btn btn-outline-primary w-100">📥 Export to CSV / Excel</a>
              <button class="btn btn-outline-secondary w-100" onclick="printCodes()">🖨️ Print / PDF</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Codes table -->
    <div class="card no-print">
      <div class="card-header">
        <form method="GET" action="<?= APP_URL ?>/" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <input type="hidden" name="page" value="admin_codes">
          <input type="hidden" name="status" value="<?= e($status ?? '') ?>">
          <input type="hidden" name="pkg" value="<?= $pkgId ?? 0 ?>">
          <div class="d-flex align-items-center gap-2">
            <span class="card-title">🎟️ Code List</span>
            <?php if ($status || $pkgId): ?>
              <a href="<?= APP_URL ?>/?page=admin_codes" class="btn btn-sm btn-outline-secondary">✕ Clear filter</a>
            <?php endif; ?>
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
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Code</th>
              <th>Type</th>
              <th>Package</th>
              <th>Price</th>
              <th>Status</th>
              <th>Used By</th>
              <th>Created</th>
              <th>Expires</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($codes['data'])): ?>
              <tr>
                <td colspan="9" class="text-center py-5 text-muted">No codes found.</td>
              </tr>
              <?php else: foreach ($codes['data'] as $i => $c): ?>
                <tr>
                  <td class="td-muted" style="font-size:.72rem;"><?= ($codes['page'] - 1) * $codes['per_page'] + $i + 1 ?></td>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <span class="reg-code"><?= e($c['code']) ?></span>
                      <button type="button"
                        class="btn btn-sm btn-link p-0 text-muted"
                        onclick="copyText(<?= js($c['code']) ?>)"
                        title="Copy code"
                        style="font-size:.85rem;line-height:1;text-decoration:none;">
                        📋
                      </button>
                    </div>
                  </td>
                  <td>
                    <?php $ct = $c['code_type'] ?? 'registration';
                    if ($ct === 'cd'): ?>
                      <span class="badge bg-warning-subtle text-warning" style="font-size:.65rem;">⏳ CD</span>
                    <?php elseif ($ct === 'upgrade'): ?>
                      <span class="badge bg-info-subtle text-info" style="font-size:.65rem;">⬆️ Upgrade</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary" style="font-size:.65rem;">Regular</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge bg-primary-subtle text-primary"><?= e($c['package_name']) ?></span></td>
                  <td class="font-mono fw-bold"><?= fmt_money($c['price']) ?></td>
                  <td><?php $b = match ($c['status']) {
                        'unused' => 'bg-success-subtle text-success',
                        'used' => 'bg-secondary-subtle text-secondary',
                        'expired' => 'bg-danger-subtle text-danger',
                        default => 'bg-secondary-subtle'
                      }; ?>
                    <span class="badge <?= $b ?>"><?= ucfirst($c['status']) ?></span>
                  </td>
                  <td class="td-muted"><?= $c['used_by_username'] ? '@' . e($c['used_by_username']) : '—' ?></td>
                  <td class="td-muted" style="font-size:.72rem;"><?= fmt_date($c['created_at']) ?></td>
                  <td class="td-muted" style="font-size:.72rem;"><?= $c['expires_at'] ? fmt_date($c['expires_at']) : '—' ?></td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($codes['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($codes, APP_URL . '/?page=admin_codes&status=' . urlencode($status ?? '') . '&pkg=' . ($pkgId ?? 0) . '&per_page=' . $perPage) ?></div>
      <?php endif; ?>
    </div>

    <!-- Print grid (hidden until print) -->
    <div class="print-grid" id="printGrid">
      <?php foreach ($codes['data'] as $c): if ($c['status'] !== 'unused') continue; ?>
        <div class="print-code-card">
          <div style="font-size:9px;color:#666;text-transform:uppercase;letter-spacing:1px;"><?= e(setting('site_name', APP_NAME)) ?></div>
          <div style="font-size:8px;color:#999;margin-bottom:5px;"><?= e($c['package_name']) ?> · <?= fmt_money($c['price']) ?></div>
          <div style="font-family:monospace;font-size:15px;font-weight:700;letter-spacing:2px;"><?= e($c['code']) ?></div>
          <div style="font-size:8px;color:#bbb;margin-top:3px;"><?= $c['expires_at'] ? 'Expires ' . fmt_date($c['expires_at']) : 'No expiry' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── Generate Codes Confirm Modal ──────────────────────────── -->
<div class="modal fade" id="genConfirmModal" tabindex="-1" aria-labelledby="genConfirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="genConfirmTitle">🎟️ Confirm Code Generation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3" style="font-size:.9rem;" id="genSummary">—</p>
        <div class="alert alert-warning py-2 mb-0" style="font-size:.8rem;">
          ⚠️ Once generated, codes are immediately available. Make sure the details are correct before proceeding.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="genConfirmBtn">✓ Generate</button>
      </div>
    </div>
  </div>
</div>

<script>
  function autoPrice(select) {
    const opt = select.options[select.selectedIndex];
    if (opt.dataset.entry) document.getElementById('genPrice').value = (parseFloat(opt.dataset.entry) + 500).toFixed(2);
  }

  function confirmGenerate() {
    const pkg = document.getElementById('genPkgSelect');
    const qty = document.getElementById('genQty').value;
    const price = document.getElementById('genPrice').value;
    const pkgName = pkg.options[pkg.selectedIndex]?.text?.split(' (')[0] || '—';

    if (!pkg.value || !qty || !price) {
      showToast('Please fill in Package, Quantity and Price.', 'danger');
      return;
    }

    const codeType = document.getElementById('genCodeType').value;
const typeLabel = codeType === 'cd' ? 'CD' : codeType === 'upgrade' ? 'Upgrade' : 'Registration';
      // Only the LABEL text and a class name are carried here; the markup lives
      // in the template. A variable holding ready-made HTML cannot be escaped at
      // the sink without destroying it, which is how these sinks go wrong.
      const typeClass = codeType === 'cd' ? 'text-warning' : codeType === 'upgrade' ? 'text-info' : '';
      document.getElementById('genSummary').innerHTML =
        `Generate <strong>${escHtml(qty)}</strong> <strong class="${escHtml(typeClass)}">${escHtml(typeLabel)}</strong> code(s) for package <strong>${escHtml(pkgName)}</strong> at <strong>₱${escHtml(parseFloat(price).toLocaleString('en-PH',{minimumFractionDigits:2}))}</strong> each.<br>
       <span class="text-muted" style="font-size:.8rem;">Total value: ₱${escHtml((qty * parseFloat(price)).toLocaleString('en-PH',{minimumFractionDigits:2}))}</span>`;

    const modal = new bootstrap.Modal(document.getElementById('genConfirmModal'));
    document.getElementById('genConfirmBtn').onclick = () => {
      modal.hide();
      document.getElementById('genCodesForm').submit();
    };
    modal.show();
  }

  function printCodes() {
    document.getElementById('printGrid').style.display = 'grid';
    window.print();
    setTimeout(() => {
      document.getElementById('printGrid').style.display = 'none';
    }, 500);
  }
</script>
<?php require 'views/partials/footer.php'; ?>