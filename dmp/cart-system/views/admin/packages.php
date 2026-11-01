<?php

/**
 * @file   views/admin/packages.php
 * @brief  Package management UI — full-width table + modal create/edit form
 */
?>
<?php $pageTitle = 'Packages'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <!-- Toggle badge styles: OFF badges get a red diagonal slash overlay -->
    <style>
      .toggle-badge {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        padding: 3px 7px;
        font-size: .8rem;
      }
      .toggle-badge.toggle-off {
        opacity: .6;
        filter: grayscale(.4);
      }
      .toggle-badge.toggle-off::after {
        content: '';
        position: absolute;
        inset: 2px;
        background: linear-gradient(40deg,
          transparent  calc(50% - 1px),
          rgba(244,63,94,.85) calc(50% - 1px),
          rgba(244,63,94,.85) calc(50% + 1px),
          transparent  calc(50% + 1px));
      }
    </style>

    <!-- Header -->
    <?php $packagesDisabled = (defined('DB_NAME') ? false : false); ?>
    <?php $packagesDisabled = function_exists('setting') ? setting('packages_disabled', '0') === '1' : false; ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Packages</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Manage entry plans, bonuses, capping, DFI & commission toggles</p>
        <?php if ($packagesDisabled): ?>
          <div class="text-danger" style="font-size:.75rem;">Packages are disabled. All packages are inactive and adding new packages is disabled.</div>
        <?php endif; ?>
      </div>
      <button type="button" class="btn btn-primary" data-bs-toggle="<?= $packagesDisabled ? '' : 'modal' ?>" data-bs-target="<?= $packagesDisabled ? '' : '#packageModal' ?>" onclick="<?= $packagesDisabled ? 'return false;' : 'resetPackageForm()' ?>" <?= $packagesDisabled ? 'disabled' : '' ?>>
        + New Package
      </button>
    </div>

    <!-- Stats Row -->
    <?php
      $totalPkg  = count($allPackages);
      $activePkg = count(array_filter($allPackages, fn($p) => ($p['status'] ?? '') === 'active'));
      $inactivePkg = $totalPkg - $activePkg;
      $binaryPkg = count(array_filter($allPackages, fn($p) => (int)($p['pairing_enabled'] ?? 1) === 1));
    ?>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3">
        <div class="card p-3">
          <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total Plans</div>
          <div class="fw-bold fs-5"><?= $totalPkg ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card p-3">
          <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Active</div>
          <div class="fw-bold fs-5 text-success"><?= $activePkg ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card p-3">
          <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Inactive</div>
          <div class="fw-bold fs-5 text-secondary"><?= $inactivePkg ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card p-3">
          <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">🌳 Binary Plans</div>
          <div class="fw-bold fs-5"><?= $binaryPkg ?> <span class="text-muted" style="font-size:.7rem;font-weight:400;">of <?= $totalPkg ?></span></div>
        </div>
      </div>
    </div>

    <!-- Full-width Packages Table -->
    <div class="card">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="card-title">📦 All Packages</span>
        <div class="d-flex align-items-center gap-3">
          <span class="text-muted" style="font-size:.75rem;">🌳 binary · 🔗 indirect · 📅 DFI</span>
          <form method="GET" action="<?= APP_URL ?>/" class="d-flex align-items-center gap-2 m-0">
            <input type="hidden" name="page" value="admin_packages">
            <label for="perPageSelect" class="form-label mb-0 text-muted" style="font-size:.78rem;white-space:nowrap;">Rows</label>
            <select id="perPageSelect" name="per_page" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
              <?php foreach ([5, 10, 25, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="padding-left:1.25rem;">Package</th>
              <th class="text-end">Entry</th>
              <th class="text-end">Volume</th>
              <th class="text-end">Cap</th>
              <th class="text-center">DFI</th>
              <th class="text-center">Toggles</th>
              <th class="text-center">Status</th>
              <th class="text-end" style="padding-right:1.25rem;width:170px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($packages['data'])): ?>
              <tr>
                <td colspan="8" class="text-center py-5 text-muted">
                  <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem;">📦</div>
                  <div>No packages yet.</div>
                  <div style="font-size:.8rem;">Click <strong>+ New Package</strong> to create your first plan.</div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($packages['data'] as $pkg):
                $lifetimeCap = (float)$pkg['entry_fee'] * (float)$pkg['lifetime_cap_multiplier'];
                $hasDfi = (float)$pkg['daily_fixed_income'] > 0;
                $dfiOn = $hasDfi && (int)$pkg['dfi_enabled'] === 1;
                $pkgStatus = ($packagesDisabled) ? 'inactive' : $pkg['status'];
              ?>
                <tr>
                  <td style="padding-left:1.25rem;">
                    <div class="d-flex align-items-center gap-2">
                      <?php if (!empty($pkg['image'])): ?>
                        <img src="<?= APP_URL ?>/uploads/<?= e($pkg['image']) ?>" alt="" class="rounded" style="width:36px;height:36px;object-fit:cover;">
                      <?php endif; ?>
                      <div>
                        <div class="fw-semibold"><?= e($pkg['name']) ?></div>
                        <div class="text-muted" style="font-size:.7rem;">ID: <?= (int)$pkg['id'] ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="text-end font-mono"><?= fmt_money($pkg['entry_fee']) ?></td>
                  <td class="text-end font-mono td-green"><?= fmt_money($pkg['pairing_bonus']) ?></td>
                  <td class="text-end">
                    <div class="font-mono" style="font-size:.75rem;"><?= fmt_money($lifetimeCap) ?></div>
                    <div class="text-muted" style="font-size:.65rem;"><?= $pkg['lifetime_cap_multiplier'] ?>× entry</div>
                  </td>
                  <td class="text-center">
                    <?php if ($hasDfi): ?>
                      <div class="font-mono" style="font-size:.75rem;color:var(--pink);">₱<?= number_format((float)$pkg['daily_fixed_income'], 0) ?>/d</div>
                      <div class="text-muted" style="font-size:.65rem;"><?= (int)$pkg['daily_fixed_income_days'] ?> days</div>
                    <?php else: ?>
                      <span class="text-muted" style="font-size:.75rem;">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <div style="display:flex;gap:5px;flex-wrap:nowrap;justify-content:center;">
                      <span class="badge toggle-badge <?= (int)$pkg['pairing_enabled'] === 1 && !$packagesDisabled ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary toggle-off' ?>" title="Binary Network: <?= ((int)$pkg['pairing_enabled'] === 1 && !$packagesDisabled) ? 'ON' : 'OFF' ?>">🌳</span>
                      <span class="badge toggle-badge <?= (int)$pkg['indirect_referral_enabled'] === 1 && !$packagesDisabled ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary toggle-off' ?>" title="Indirect Referral: <?= ((int)$pkg['indirect_referral_enabled'] === 1 && !$packagesDisabled) ? 'ON' : 'OFF' ?>">🔗</span>
                      <span class="badge toggle-badge <?= $dfiOn && !$packagesDisabled ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary toggle-off' ?>" title="Daily Fixed Income: <?= ($dfiOn && !$packagesDisabled) ? 'ON' : 'OFF' ?>">📅</span>
                    </div>
                  </td>
                  <td class="text-center">
                    <?php if ($pkgStatus === 'active'): ?>
                      <span class="badge bg-success-subtle text-success" style="font-size:.72rem;">● Active</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary" style="font-size:.72rem;">○ Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end" style="padding-right:1.25rem;">
                    <a href="<?= APP_URL ?>/?page=admin_packages&view=<?= (int)$pkg['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                    <?php if ($packagesDisabled): ?>
                      <button class="btn btn-sm btn-outline-primary" disabled>Edit</button>
                    <?php else: ?>
                      <a href="<?= APP_URL ?>/?page=admin_packages&edit=<?= (int)$pkg['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($packages['total_pages']) && $packages['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($packages, APP_URL . '/?page=admin_packages&per_page=' . $perPage) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     PACKAGE MODAL  (Create / Edit)
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="packageModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="packageModalTitle">➕ New Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="POST" action="<?= APP_URL ?>/?page=admin_save_package" id="packageForm" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="package_id" id="packageId" value="<?= e($editPkg['id'] ?? '') ?>">

          <!-- ═══ COMMISSION TOGGLES (set these FIRST) ═══ -->
          <div class="mb-3" style="border:1px solid rgba(74,222,128,.35);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
            <div class="d-flex align-items-center gap-2 mb-2" style="color:#4ade80;">
              <i class="bi bi-toggles"></i>
              <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Commission Toggles</span>
            </div>
            <div class="form-text mb-3" style="color:#8b93a7;">Toggle features on first — disabled settings are hidden and reset to their defaults.</div>
            <div class="row g-3">
              <div class="col-md-4">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="pairing_enabled" id="pairingEnabled" value="1" <?= (int)($editPkg['pairing_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="pairingEnabled">🌳 Binary Network</label>
                </div>
                <div class="form-text">Member joins the binary tree, builds legs and earns pairing bonuses.</div>
              </div>
              <div class="col-md-4">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="indirect_referral_enabled" id="indirectRefEnabled" value="1" <?= (int)($editPkg['indirect_referral_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="indirectRefEnabled">🔗 Indirect Referral</label>
                </div>
                <div class="form-text">Pays the 10-level indirect referral bonuses for this package's members.</div>
              </div>
              <div class="col-md-4">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="dfi_enabled" id="dfiEnabledToggle" value="1" <?= (int)($editPkg['dfi_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="dfiEnabledToggle">📅 Daily Fixed Income</label>
                </div>
                <div class="form-text">Enables the daily fixed income payout for this package.</div>
              </div>
            </div>
          </div>

          <!-- Basic Info -->
          <div class="mb-3">
            <label class="form-label">Package Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="pkgName" class="form-control" value="<?= e($editPkg['name'] ?? '') ?>" placeholder="e.g. Starter, Pro, Elite" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Package Image</label>
            <?php if (!empty($editPkg['image'])): ?>
              <div class="mb-2" id="pkgImageCurrentWrap">
                <img src="<?= APP_URL ?>/uploads/<?= e($editPkg['image']) ?>" alt="Current package image" class="rounded" style="width:80px;height:80px;object-fit:cover;border:2px solid var(--border,rgba(255,255,255,.12));">
                <div class="form-text mt-1">Current image</div>
              </div>
            <?php endif; ?>
            <input type="file" name="package_image" id="pkgImageInput" class="form-control" accept="image/jpeg,image/png,image/webp">
            <div class="form-text">JPG, PNG or WebP · max 5 MB<?= ($editPkg ?? null) ? '. Leave empty to keep current image' : '' ?>.</div>
            <div class="mt-2 d-none" id="pkgImagePreviewWrap">
              <img id="pkgImagePreview" src="" alt="New image preview" class="rounded" style="width:80px;height:80px;object-fit:cover;border:2px solid var(--border,rgba(255,255,255,.12));">
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Entry Fee (₱) <span class="text-danger">*</span></label>
              <input type="number" name="entry_fee" id="pkgEntryFee" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['entry_fee'] ?? '') ?>" placeholder="10000.00" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Direct Referral Bonus (₱)</label>
              <input type="number" name="direct_ref_bonus" id="pkgDirectRefBonus" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['direct_ref_bonus'] ?? 0) ?>" placeholder="500.00">
              <div class="form-text">Paid once to sponsor on join</div>
            </div>
          </div>

          <!-- Binary group: shown only when 🌳 Binary Network is ON -->
          <div class="row g-3 mb-3" id="binaryFields">
            <div class="col-md-6">
              <label class="form-label">Pairing Bonus (₱) <span class="text-danger">*</span></label>
              <input type="number" name="pairing_bonus" id="pkgPairingBonus" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['pairing_bonus'] ?? '') ?>" placeholder="2000.00" required>
              <div class="form-text">Per pair paid out</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Daily Pair Cap <span class="text-danger">*</span></label>
              <input type="number" name="daily_pair_cap" id="pkgDailyPairCap" class="form-control" inputmode="numeric" min="0" max="100" value="<?= e($editPkg['daily_pair_cap'] ?? 3) ?>" required>
              <div class="form-text">Flush-out limit per member per day</div>
            </div>
          </div>

          <!-- ═══ LIFETIME INCOME CAPPING ═══ -->
          <div class="mb-3" style="border:1px solid var(--purple-border);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
            <div class="d-flex align-items-center gap-2 mb-3" style="color:var(--purple);">
              <i class="bi bi-shield-lock-fill"></i>
              <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Lifetime Income Capping</span>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" style="color:var(--purple);">Cap Multiplier</label>
                <input type="number" name="lifetime_cap_multiplier" id="pkgCapMult" class="form-control" inputmode="decimal" min="1" max="20" step="0.01" value="<?= e($editPkg['lifetime_cap_multiplier'] ?? 3.00) ?>" required>
                <div class="form-text">Lifetime cap = Entry Fee × Multiplier</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" style="color:var(--purple);">Auto-Cap Preview</label>
                <div class="form-control" style="background:var(--surface-3);border-color:var(--purple-border);color:var(--purple);font-family:var(--font-mono);font-weight:700;" id="capPreview">
                  <?= ($editPkg ?? null) ? fmt_money((float)($editPkg['entry_fee'] ?? 0) * (float)($editPkg['lifetime_cap_multiplier'] ?? 3.00)) : '₱0.00' ?>
                </div>
                <div class="form-text">Calculated automatically</div>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label" style="color:var(--purple);">Reactivation Fee (₱)</label>
                <input type="number" name="reactivation_fee" id="pkgReactivationFee" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['reactivation_fee'] ?? 0) ?>" placeholder="10000.00">
                <div class="form-text">Fee to reactivate after capping</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" style="color:var(--purple);">Reactivation Window (days)</label>
                <input type="number" name="reactivation_window_days" id="pkgReactivationWindow" class="form-control" inputmode="numeric" min="1" max="365" value="<?= e($editPkg['reactivation_window_days'] ?? 15) ?>">
                <div class="form-text">Days to reactivate before permanent deactivation</div>
              </div>
            </div>
          </div>

          <!-- ═══ DAILY FIXED INCOME (shown when 📅 is ON) ═══ -->
          <div class="mb-3" id="dfiFields" style="border:1px solid var(--pink-border);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
            <div class="d-flex align-items-center gap-2 mb-3" style="color:var(--pink);">
              <i class="bi bi-calendar-week-fill"></i>
              <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Daily Fixed Income</span>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" style="color:var(--pink);">Daily Fixed Income (₱/day)</label>
                <input type="number" name="daily_fixed_income" id="pkgDfi" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['daily_fixed_income'] ?? 0) ?>" placeholder="100.00">
                <div class="form-text">Set 0 to disable DFI for this package</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" style="color:var(--pink);">Max DFI Days</label>
                <input type="number" name="daily_fixed_income_days" id="pkgDfiDays" class="form-control" inputmode="numeric" min="1" value="<?= e($editPkg['daily_fixed_income_days'] ?? 90) ?>">
                <div class="form-text">Maximum days of fixed income per member</div>
              </div>
            </div>
          </div>

          <!-- Status -->
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="pkgStatus" class="form-select">
              <option value="active" <?= (($editPkg['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>🟢 Active</option>
              <option value="inactive" <?= (($editPkg['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>⚪ Inactive</option>
            </select>
          </div>

          <!-- Indirect Referral Levels (shown when 🔗 is ON) -->
          <div class="mb-0" id="indirectFields">
            <label class="form-label fw-bold">🔗 Indirect Referral Bonuses (10 Levels)</label>
            <div class="row g-2">
              <?php $lvls = $editPkg['indirect_levels'] ?? [];
                for ($lvl = 1; $lvl <= 10; $lvl++): ?>
                <div class="col-6 col-md-4">
                  <label class="form-label" style="font-size:.72rem;">Level <?= $lvl ?></label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">₱</span>
                    <input type="number" name="indirect_<?= $lvl ?>" id="indirect_<?= $lvl ?>" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($lvls[$lvl] ?? 0) ?>" placeholder="0.00">
                  </div>
                </div>
              <?php endfor; ?>
            </div>
            <div class="form-text mt-1">Set 0 to disable a level. Paid once to each upline sponsor on member join.</div>
          </div>
        </form>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--border, rgba(255,255,255,.08));">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" form="packageForm" id="pkgSubmitBtn">
          <?= ($editPkg ?? null) ? '💾 Update Package' : '➕ Create Package' ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     PACKAGE VIEW MODAL  (read-only)
     ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($viewPkg ?? null): ?>
<div class="modal fade" id="packageViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="packageViewModalTitle">📄 Package Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <!-- Header: image + name + status + toggle badges -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div class="d-flex align-items-center gap-3">
            <?php if (!empty($viewPkg['image'])): ?>
              <img src="<?= APP_URL ?>/uploads/<?= e($viewPkg['image']) ?>" alt="<?= e($viewPkg['name']) ?>" class="rounded" style="width:64px;height:64px;object-fit:cover;border:2px solid var(--border,rgba(255,255,255,.12));">
            <?php endif; ?>
            <div>
              <h5 class="mb-1"><?= e($viewPkg['name']) ?></h5>
              <span class="text-muted" style="font-size:.75rem;">ID: <?= (int)$viewPkg['id'] ?></span>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <?php if ($viewPkg['status'] === 'active'): ?>
              <span class="badge bg-success-subtle text-success" style="font-size:.72rem;">● Active</span>
            <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary" style="font-size:.72rem;">○ Inactive</span>
            <?php endif; ?>
            <div style="display:flex;gap:5px;flex-wrap:nowrap;justify-content:center;">
              <span class="badge toggle-badge <?= (int)$viewPkg['pairing_enabled'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary toggle-off' ?>" title="Binary Network: <?= (int)$viewPkg['pairing_enabled'] === 1 ? 'ON' : 'OFF' ?>">🌳</span>
              <span class="badge toggle-badge <?= (int)$viewPkg['indirect_referral_enabled'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary toggle-off' ?>" title="Indirect Referral: <?= (int)$viewPkg['indirect_referral_enabled'] === 1 ? 'ON' : 'OFF' ?>">🔗</span>
              <?php $viewDfiOn = (float)$viewPkg['daily_fixed_income'] > 0 && (int)$viewPkg['dfi_enabled'] === 1; ?>
              <span class="badge toggle-badge <?= $viewDfiOn ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary toggle-off' ?>" title="Daily Fixed Income: <?= $viewDfiOn ? 'ON' : 'OFF' ?>">📅</span>
            </div>
          </div>
        </div>

        <!-- Entry & Bonuses -->
        <div class="mb-3" style="border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
          <div class="d-flex align-items-center gap-2 mb-3" style="color:#e2e8f0;">
            <i class="bi bi-box-seam-fill"></i>
            <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Entry &amp; Bonuses</span>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Entry Fee</div>
              <div class="font-mono fw-bold" style="font-size:1.05rem;"><?= fmt_money($viewPkg['entry_fee']) ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Direct Referral Bonus</div>
              <div class="font-mono"><?= fmt_money($viewPkg['direct_ref_bonus']) ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Pairing Bonus</div>
              <?php if ((int)$viewPkg['pairing_enabled'] === 1): ?>
                <div class="font-mono" style="color:var(--green, #22c55e);"><?= fmt_money($viewPkg['pairing_bonus']) ?></div>
              <?php else: ?>
                <div class="text-muted">— (Binary OFF)</div>
              <?php endif; ?>
            </div>
            <div class="col-md-4">
              <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Daily Pair Cap</div>
              <?php if ((int)$viewPkg['pairing_enabled'] === 1): ?>
                <div class="font-mono"><?= (int)$viewPkg['daily_pair_cap'] ?> pairs/day</div>
              <?php else: ?>
                <div class="text-muted">—</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Lifetime Income Capping -->
        <div class="mb-3" style="border:1px solid var(--purple-border);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
          <div class="d-flex align-items-center gap-2 mb-3" style="color:var(--purple);">
            <i class="bi bi-shield-lock-fill"></i>
            <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Lifetime Income Capping</span>
          </div>
          <div class="row g-3">
            <div class="col-md-3">
              <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Cap Multiplier</div>
              <div class="font-mono"><?= (float)$viewPkg['lifetime_cap_multiplier'] ?>×</div>
            </div>
            <div class="col-md-3">
              <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Auto-Cap</div>
              <div class="font-mono fw-bold" style="color:var(--purple);"><?= fmt_money((float)$viewPkg['entry_fee'] * (float)$viewPkg['lifetime_cap_multiplier']) ?></div>
            </div>
            <div class="col-md-3">
              <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Reactivation Fee</div>
              <div class="font-mono"><?= fmt_money($viewPkg['reactivation_fee']) ?></div>
            </div>
            <div class="col-md-3">
              <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Reactivation Window</div>
              <div class="font-mono"><?= (int)$viewPkg['reactivation_window_days'] ?> days</div>
            </div>
          </div>
        </div>

        <!-- Daily Fixed Income -->
        <div class="mb-3" style="border:1px solid var(--pink-border);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
          <div class="d-flex align-items-center gap-2 mb-3" style="color:var(--pink);">
            <i class="bi bi-calendar-week-fill"></i>
            <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Daily Fixed Income</span>
          </div>
          <?php if ((int)$viewPkg['dfi_enabled'] === 1 && (float)$viewPkg['daily_fixed_income'] > 0): ?>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Daily Rate</div>
                <div class="font-mono fw-bold" style="color:var(--pink);"><?= fmt_money($viewPkg['daily_fixed_income']) ?>/day</div>
              </div>
              <div class="col-md-6">
                <div class="text-muted mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;">Max DFI Days</div>
                <div class="font-mono"><?= (int)$viewPkg['daily_fixed_income_days'] ?> days</div>
              </div>
            </div>
          <?php else: ?>
            <div class="text-muted"><i class="bi bi-dash-circle me-1"></i>Disabled for this package.</div>
          <?php endif; ?>
        </div>

        <!-- Indirect Referral Bonuses (10 Levels) -->
        <div class="mb-0" style="border:1px solid rgba(74,222,128,.3);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
          <div class="d-flex align-items-center gap-2 mb-3" style="color:#4ade80;">
            <i class="bi bi-link-45deg"></i>
            <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Indirect Referral Bonuses (10 Levels)</span>
          </div>
          <?php if ((int)$viewPkg['indirect_referral_enabled'] === 1): ?>
            <?php $lvls = $viewPkg['indirect_levels'] ?? []; ?>
            <div class="row g-2">
              <?php for ($lvl = 1; $lvl <= 10; $lvl++): ?>
                <?php $bonus = (float)($lvls[$lvl] ?? 0); ?>
                <div class="col-6 col-md-3">
                  <div class="text-muted mb-1" style="font-size:.72rem;">Level <?= $lvl ?></div>
                  <div class="font-mono" style="color:<?= $bonus > 0 ? 'var(--green, #22c55e)' : 'var(--muted, #7c8798)' ?>;"><?= fmt_money($bonus) ?></div>
                </div>
              <?php endfor; ?>
            </div>
          <?php else: ?>
            <div class="text-muted"><i class="bi bi-dash-circle me-1"></i>Disabled for this package.</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--border, rgba(255,255,255,.08));">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  // ── Package image preview on file select ──
  const pkgImageInput = document.getElementById('pkgImageInput');
  const pkgImagePreview = document.getElementById('pkgImagePreview');
  const pkgImagePreviewWrap = document.getElementById('pkgImagePreviewWrap');
  const pkgImageCurrentWrap = document.getElementById('pkgImageCurrentWrap');

  if (pkgImageInput) {
    pkgImageInput.addEventListener('change', function() {
      const file = this.files[0];
      if (!file) {
        pkgImagePreviewWrap.classList.add('d-none');
        if (pkgImageCurrentWrap) pkgImageCurrentWrap.style.display = '';
        return;
      }
      const reader = new FileReader();
      reader.onload = function(ev) {
        pkgImagePreview.src = ev.target.result;
        pkgImagePreviewWrap.classList.remove('d-none');
      };
      reader.readAsDataURL(file);
      if (pkgImageCurrentWrap) pkgImageCurrentWrap.style.display = 'none';
    });
  }

  // ── Cap preview live update ──
  const entryInput = document.getElementById('pkgEntryFee');
  const multInput = document.getElementById('pkgCapMult');
  const previewEl = document.getElementById('capPreview');

  function updateCapPreview() {
    const entry = parseFloat(entryInput?.value) || 0;
    const mult = parseFloat(multInput?.value) || 0;
    previewEl.textContent = '₱' + (entry * mult).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  if (entryInput) entryInput.addEventListener('input', updateCapPreview);
  if (multInput) multInput.addEventListener('input', updateCapPreview);

  // ── Toggle-linked sections: hide + reset to defaults when a feature is OFF ──
  function syncToggleSections() {
    const pairingOn = document.getElementById('pairingEnabled').checked;
    const binaryFields = document.getElementById('binaryFields');
    if (pairingOn) {
      binaryFields.style.display = '';
    } else {
      binaryFields.style.display = 'none';
      document.getElementById('pkgPairingBonus').value = '0';
      document.getElementById('pkgDailyPairCap').value = '0';
    }

    const dfiOn = document.getElementById('dfiEnabledToggle').checked;
    const dfiFields = document.getElementById('dfiFields');
    if (dfiOn) {
      dfiFields.style.display = '';
    } else {
      dfiFields.style.display = 'none';
      document.getElementById('pkgDfi').value = '0';
      document.getElementById('pkgDfiDays').value = '90';
    }

    const indirectOn = document.getElementById('indirectRefEnabled').checked;
    const indirectFields = document.getElementById('indirectFields');
    if (indirectOn) {
      indirectFields.style.display = '';
    } else {
      indirectFields.style.display = 'none';
      for (let i = 1; i <= 10; i++) {
        const el = document.getElementById('indirect_' + i);
        if (el) el.value = '0.00';
      }
    }
  }

  document.getElementById('pairingEnabled').addEventListener('change', syncToggleSections);
  document.getElementById('indirectRefEnabled').addEventListener('change', syncToggleSections);
  document.getElementById('dfiEnabledToggle').addEventListener('change', syncToggleSections);
  syncToggleSections();

  // ── Reset form for "New Package" ──
  function resetPackageForm() {
    const form = document.getElementById('packageForm');
    form.reset();
    document.getElementById('packageModalTitle').textContent = '➕ New Package';
    document.getElementById('packageId').value = '';
    document.getElementById('pkgSubmitBtn').textContent = '➕ Create Package';
    if (previewEl) previewEl.textContent = '₱0.00';
    if (pkgImagePreviewWrap) pkgImagePreviewWrap.classList.add('d-none');
    if (pkgImageCurrentWrap) pkgImageCurrentWrap.style.display = '';
    // Restore default toggle states for a new package (ON by default)
    document.getElementById('pairingEnabled').checked = true;
    document.getElementById('indirectRefEnabled').checked = true;
    document.getElementById('dfiEnabledToggle').checked = true;
    // Reset indirect levels
    for (let i = 1; i <= 10; i++) {
      const el = document.getElementById('indirect_' + i);
      if (el) el.value = '0.00';
    }
    syncToggleSections();
  }

  // ── Auto-open modal when the page loads with ?edit=ID ──
  <?php if ($editPkg ?? null): ?>
    document.addEventListener('DOMContentLoaded', function() {
      const modalEl = document.getElementById('packageModal');
      const modal = new bootstrap.Modal(modalEl);
      document.getElementById('packageModalTitle').textContent = '✏️ Edit Package';
      document.getElementById('pkgSubmitBtn').textContent = '💾 Update Package';
      updateCapPreview();
      modal.show();
    });
  <?php endif; ?>

  // ── Auto-open view modal when the page loads with ?view=ID ──
  <?php if ($viewPkg ?? null): ?>
    document.addEventListener('DOMContentLoaded', function() {
      const vModalEl = document.getElementById('packageViewModal');
      if (vModalEl) new bootstrap.Modal(vModalEl).show();
    });
  <?php endif; ?>

  // ── Loading state on submit ──
  const pkgFormEl = document.getElementById('packageForm');
  if (pkgFormEl) {
    pkgFormEl.addEventListener('submit', function() {
      const btn = document.getElementById('pkgSubmitBtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
    });
  }
</script>

<?php require 'views/partials/footer.php'; ?>