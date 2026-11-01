<?php

/**
 * @file   views/admin/settings.php
 * @brief  System settings UI — vertical tab layout
 */
?>
<?php $pageTitle = 'System Settings'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<style>
    .tab-pane {
        display: none;
    }

    .tab-pane.show.active {
        display: block;
    }

    .tab-pane .card {
        margin-bottom: 0;
    }
</style>
<div class="main-content">
    <?php require 'views/partials/topbar.php'; ?>
    <div class="page-content">
        <?= render_flash() ?>

        <!-- Hidden external forms (referenced via form="" attribute) -->
        <form method="POST" action="<?= APP_URL ?>/?page=save_profile" id="changePasswordForm" class="d-none"><?= csrf_field() ?></form>
        <form method="POST" action="<?= APP_URL ?>/?page=admin_manual_reset" id="manualResetForm" class="d-none"><?= csrf_field() ?></form>

        <div class="tab-content">

            <!-- ════════════════════════════════════════════
             TAB 1 — SITE BASICS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="tabPane-basics" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="basics">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:var(--primary-light);border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🌐</span>
                            <span class="card-title">Site Basics</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Site Name</label>
                                <input type="text" name="site_name" class="form-control" value="<?= e(setting('site_name')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Site Tagline</label>
                                <input type="text" name="site_tagline" class="form-control" value="<?= e(setting('site_tagline')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contact Email</label>
                                <input type="email" name="contact_email" class="form-control" value="<?= e(setting('contact_email')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Minimum Payout (₱)</label>
                                <input type="number" name="min_payout" class="form-control" min="0" step="0.01" value="<?= e(setting('min_payout', '500')) ?>">
                                <div class="form-text">Members cannot request below this amount</div>
                            </div>

                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="shop_enabled" id="shopEnabled" value="1" <?= setting('shop_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="shopEnabled">🛍️ Enable Shop</label>
                                </div>
                                <div class="form-text">Members and staff can browse the catalog and place orders</div>
                            </div>

                            <label class="form-label fw-semibold" style="font-size:.8rem;">Shop lifecycle rules</label>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:.72rem;">Payment deadline (hours)</label>
                                    <input type="number" name="shop_payment_deadline_hours" class="form-control form-control-sm" min="1" value="<?= e(setting('shop_payment_deadline_hours', '24')) ?>">
                                    <div class="form-text" style="font-size:.7rem;">T7: pending orders expire after this</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:.72rem;">Correction window (hours)</label>
                                    <input type="number" name="shop_correction_window_hours" class="form-control form-control-sm" min="1" value="<?= e(setting('shop_correction_window_hours', '24')) ?>">
                                    <div class="form-text" style="font-size:.7rem;">T3: re-upload window after a rejected proof</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:.72rem;">Max proof attempts</label>
                                    <input type="number" name="shop_max_proof_attempts" class="form-control form-control-sm" min="1" value="<?= e(setting('shop_max_proof_attempts', '3')) ?>">
                                    <div class="form-text" style="font-size:.7rem;">T2: uploads before the order goes on hold</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:.72rem;">Report window (days)</label>
                                    <input type="number" name="shop_report_window_days" class="form-control form-control-sm" min="1" value="<?= e(setting('shop_report_window_days', '5')) ?>">
                                    <div class="form-text" style="font-size:.7rem;">T24: delivered orders auto-complete after this</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:.72rem;">Max delivery attempts</label>
                                    <input type="number" name="shop_max_delivery_attempts" class="form-control form-control-sm" min="1" value="<?= e(setting('shop_max_delivery_attempts', '3')) ?>">
                                    <div class="form-text" style="font-size:.7rem;">T16: courier attempts before return opens</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 2 — MAINTENANCE & SECURITY
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-maint" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="maint">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#fff7ed;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🛡️</span>
                            <span class="card-title">Maintenance & Security</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Maintenance Mode</label>
                                <select name="maintenance_mode" class="form-select">
                                    <option value="0" <?= setting('maintenance_mode') === '0' ? 'selected' : '' ?>>🟢 Off — Site is live</option>
                                    <option value="1" <?= setting('maintenance_mode') === '1' ? 'selected' : '' ?>>🔴 On — Members see maintenance page</option>
                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">🪑 Seat Limit</label>
                                <input type="number" name="seat_limit" class="form-control" min="1" step="1" value="<?= e(setting('seat_limit', '1000')) ?>">
                                <div class="form-text d-flex justify-content-between">
                                    <span>Maximum member accounts allowed</span>
                                    <span class="font-mono" style="font-size:.75rem;">
                                        <?= User::counts()['total'] ?? 0 ?> / <?= e(setting('seat_limit', '1000')) ?> seats
                                        <?php $remaining = seatsRemaining(); ?>
                                        <span class="badge <?= $remaining <= 50 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>" style="font-size:.7rem;margin-left:.25rem;">
                                            <?= $remaining ?> left
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <div class="mb-3 mt-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="packages_disabled" id="packagesDisabled" value="1" <?= setting('packages_disabled', '0') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="packagesDisabled">📦 Disable Packages</label>
                                </div>
                                <div class="form-text">When enabled, all packages become inactive, adding new packages is disabled, and free registration is forced on.</div>
                            </div>

                            <div class="mb-0 mt-3">
                                <label class="form-label">🆓 Free Registration</label>
                                <?php $packagesDisabled = setting('packages_disabled', '0') === '1'; ?>
                                <select name="free_registration_enabled" id="freeRegistrationSelect" class="form-select" <?= $packagesDisabled ? 'disabled' : '' ?>>
                                    <option value="1" <?= ($packagesDisabled || setting('free_registration_enabled', '1') === '1') ? 'selected' : '' ?>>🟢 On — Anyone can start free (pending) registration</option>
                                    <option value="0" <?= (!$packagesDisabled && setting('free_registration_enabled', '1') !== '1') ? 'selected' : '' ?>>🔴 Off — Free registration disabled; codes/e-wallet only</option>
                                </select>
                                <?php if ($packagesDisabled): ?>
                                    <input type="hidden" name="free_registration_enabled" value="1">
                                <?php endif; ?>
                                <div class="form-text">
                                    <?php if ($packagesDisabled): ?>
                                        Free registration is forced on while packages are disabled.
                                    <?php else: ?>
                                        When Off, free/pending signups are blocked. Referral links then require immediate payment via a registration code or e-wallet.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 3 — PAYMENTS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-payments" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="payments">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#fef3c7;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🔄</span>
                            <span class="card-title">Payments</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:.8rem;font-weight:600;">Reactivation Payment Methods</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="reactivation_ewallet_enabled" id="reEwEnabled" value="1" <?= setting('reactivation_ewallet_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="reEwEnabled">E-Wallet (deduct balance immediately)</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="reactivation_external_enabled" id="reExtEnabled" value="1" <?= setting('reactivation_external_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="reExtEnabled">External (GCash / Maya / USDT with admin approval)</label>
                                </div>
                            </div>
                            <hr class="my-3">
                            <p class="fw-bold mb-2" style="font-size:.82rem;">🏦 Admin Payment Accounts</p>
                            <div class="form-text mb-3" style="font-size:.75rem;">Members send external reactivation payments to these accounts.</div>
                            <div class="mb-3">
                                <label class="form-label" style="color:#0070d8;font-weight:700;font-size:.75rem;">
                                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/GCash_logo.svg/16px-GCash_logo.svg.png" alt="" style="height:14px;vertical-align:middle;margin-right:.25rem;">GCash Number
                                </label>
                                <input type="tel" name="gcash_number" class="form-control font-mono" placeholder="09XXXXXXXXX" value="<?= e(setting('gcash_number', '')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="color:#48b0db;font-weight:700;font-size:.75rem;">
                                    <span style="color:#48b0db;margin-right:.25rem;">●</span>Maya Number
                                </label>
                                <input type="tel" name="maya_number" class="form-control font-mono" placeholder="09XXXXXXXXX" value="<?= e(setting('maya_number', '')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="color:#26a17b;font-weight:700;font-size:.75rem;">₮ USDT TRC20 Address</label>
                                <input type="text" name="usdt_trc20_address" class="form-control font-mono" placeholder="T..." value="<?= e(setting('usdt_trc20_address', '')) ?>">
                                <div class="form-text">TRC20 addresses start with T and are 34 characters</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label" style="color:#f0b90b;font-weight:700;font-size:.75rem;">₮ USDT BEP20 Address</label>
                                <input type="text" name="usdt_bep20_address" class="form-control font-mono" placeholder="0x..." value="<?= e(setting('usdt_bep20_address', '')) ?>">
                                <div class="form-text">BEP20 addresses start with 0x and are 42 characters</div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 4 — E-WALLET TRANSFERS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-ewallet" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="ewallet">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#eff6ff;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">💱</span>
                            <span class="card-title">E-Wallet Transfers</span>
                        </div>
                        <div class="card-body">
                            <div class="form-text mb-3" style="font-size:.75rem;">Configure member-to-member transfer rules and fees. Admin transfers are always free.</div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Transfer Fee (₱)</label>
                                    <input type="number" name="ewallet_transfer_fee" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_fee', '0.00')) ?>">
                                    <div class="form-text" style="font-size:.7rem;">Flat fee per member transfer</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Minimum Transfer (₱)</label>
                                    <input type="number" name="ewallet_min_transfer" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_min_transfer', '50.00')) ?>">
                                </div>
                            </div>
                            <div class="row g-2 mb-0">
                                <div class="col-6">
                                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Daily Limit (₱)</label>
                                    <input type="number" name="ewallet_transfer_daily_limit" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_daily_limit', '5000.00')) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Weekly Limit (₱)</label>
                                    <input type="number" name="ewallet_transfer_weekly_limit" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_weekly_limit', '20000.00')) ?>">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 5 — PAYOUT METHODS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-payouts" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="payouts">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#eff6ff;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">💸</span>
                            <span class="card-title">Payout Methods & Fees</span>
                        </div>
                        <div class="card-body">
                            <div class="form-text mb-3" style="font-size:.75rem;">Deducted from requested amount before sending. Set to 0 to disable.</div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:.8rem;font-weight:600;">Available Methods</label>
                                <div class="form-text mb-2" style="font-size:.75rem;">Disable methods to hide them from members. USDT is always enabled.</div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="gcash_enabled" id="gcashEnabled" value="1" <?= setting('gcash_enabled', '1') === '1' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="gcashEnabled" style="color:#0070d8;font-weight:600;font-size:.8rem;">
                                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/GCash_logo.svg/16px-GCash_logo.svg.png" alt="" style="height:14px;vertical-align:middle;margin-right:.25rem;">GCash
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="maya_enabled" id="mayaEnabled" value="1" <?= setting('maya_enabled', '1') === '1' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="mayaEnabled" style="color:#48b0db;font-weight:600;font-size:.8rem;">
                                                <span style="color:#48b0db;margin-right:.25rem;">●</span>Maya
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-3">
                                    <label class="form-label" style="color:#0070d8;font-weight:700;font-size:.75rem;">GCash %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="service_fee_gcash" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_gcash', '0')) ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="color:#48b0db;font-weight:700;font-size:.75rem;">Maya %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="service_fee_maya" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_maya', '0')) ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="color:#26a17b;font-weight:700;font-size:.75rem;">TRC20 %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="service_fee_usdt_trc20" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_usdt_trc20', '5')) ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="color:#f0b90b;font-weight:700;font-size:.75rem;">BEP20 %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="service_fee_usdt_bep20" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_usdt_bep20', '5')) ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2 mb-0">
                                <div class="col-6">
                                    <label class="form-label" style="color:#26a17b;font-weight:700;font-size:.75rem;">₮ TRC20 Gas Fee</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">USDT</span>
                                        <input type="number" name="usdt_trc20_gas_fee" class="form-control font-mono" min="0" step="0.0001" value="<?= e(setting('usdt_trc20_gas_fee', '2.50')) ?>">
                                    </div>
                                    <div class="form-text" style="font-size:.7rem;">Fixed TRC20 network fee (typically 1–3 USDT)</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="color:#f0b90b;font-weight:700;font-size:.75rem;">₮ BEP20 Gas Fee</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">USDT</span>
                                        <input type="number" name="usdt_bep20_gas_fee" class="form-control font-mono" min="0" step="0.000001" value="<?= e(setting('usdt_bep20_gas_fee', '0.05')) ?>">
                                    </div>
                                    <div class="form-text" style="font-size:.7rem;">Fixed BEP20 network fee (typically 0.01–0.10 USDT)</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 5B — COMPENSATIONS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-compensations" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings" id="compensationsForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="compensations">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#f0fdf4;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">💰</span>
                            <span class="card-title">Global Compensation Controls</span>
                        </div>
                        <div class="card-body">
                            <div id="compensationsPackagesDisabledNotice" class="alert alert-warning py-2 mb-3" style="font-size:.8rem; <?= setting('packages_disabled', '0') === '1' ? '' : 'display:none;' ?>">
                                <strong>Packages are disabled.</strong> Commissions are tied to packages, so all compensation toggles are disabled while packages are inactive.
                            </div>
                            <div class="alert alert-info py-2 mb-3" style="font-size:.8rem;">
                                <strong>Note:</strong> When the master switch is off, <u>all</u> commissions are disabled globally regardless of package configuration. Use this when there are no packages or to temporarily stop all commission payouts.
                            </div>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="commissions_enabled" id="commissionsEnabled" value="1" <?= setting('commissions_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="commissionsEnabled">🛑 Master: Enable All Commissions</label>
                                </div>
                                <div class="form-text">Turning this OFF disables Direct Referral, Indirect/Unilevel, Binary Pairing, and DFI payouts.</div>
                            </div>
                            <hr class="my-3">
                            <label class="form-label fw-semibold mb-2">Per-Type Toggles</label>
                            <div class="mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input comp-type" type="checkbox" name="commission_direct_ref_enabled" id="compDirect" value="1" <?= setting('commission_direct_ref_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="compDirect">👥 Direct Referral</label>
                                </div>
                            </div>
                            <div class="mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input comp-type" type="checkbox" name="commission_indirect_ref_enabled" id="compIndirect" value="1" <?= setting('commission_indirect_ref_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="compIndirect">🔗 Indirect/Unilevel Referral</label>
                                </div>
                            </div>
                            <div class="mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input comp-type" type="checkbox" name="commission_binary_enabled" id="compBinary" value="1" <?= setting('commission_binary_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="compBinary">🤝 Binary Pairing</label>
                                </div>
                            </div>
                            <div class="mb-0">
                                <div class="form-check form-switch">
                                    <input class="form-check-input comp-type" type="checkbox" name="commission_dfi_enabled" id="compDfi" value="1" <?= setting('commission_dfi_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="compDfi">📅 Daily Fixed Income (DFI)</label>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Compensation Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 6 — CHANGE PASSWORD (external form)
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-password" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span style="width:28px;height:28px;background:#fef2f2;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🔒</span>
                        <span class="card-title">Change Admin Password</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-3"><label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" name="current_password" id="cur_pw" class="form-control" autocomplete="current-password" form="changePasswordForm">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('cur_pw',this)">👁</button>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6"><label class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" name="new_password" id="new_pw" class="form-control" minlength="8" autocomplete="new-password" form="changePasswordForm">
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('new_pw',this)">👁</button>
                                </div>
                            </div>
                            <div class="col-md-6"><label class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" name="new_password_confirm" id="confirm_pw" class="form-control" autocomplete="new-password" form="changePasswordForm">
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('confirm_pw',this)">👁</button>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-outline-primary w-100" form="changePasswordForm">🔒 Update Password</button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 7 — DAILY CAP RESET
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-reset" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="reset">
                    <div class="card" style="margin-bottom:1rem;">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#eef2ff;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🎯</span>
                            <span class="card-title">Default Lifetime Cap Multiplier</span>
                        </div>
                        <div class="card-body">
                            <label class="form-label">Default Lifetime Cap Multiplier</label>
                            <input type="number" name="default_cap_multiplier" class="form-control" min="0" step="0.01" value="<?= e(setting('default_cap_multiplier', '3.00')) ?>">
                            <div class="form-text">Lifetime cap = entry fee × multiplier. Default: 3.00</div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>

                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span style="width:28px;height:28px;background:#fef3c7;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">⏱️</span>
                        <span class="card-title">Daily Pair Cap Reset</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3" style="font-size:.85rem;line-height:1.7;">
                            The midnight cron resets <code>pairs_paid_today = 0</code> and <code>pairs_volume_today = 0</code> for all members, clearing the daily pairing (matched volume) cap so they can earn again tomorrow.
                        </p>
                        <div class="rounded p-3 mb-3" style="background:#f4f6fb;">
                            <div class="text-muted mb-1" style="font-size:.68rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Last Reset</div>
                            <div class="fw-600 font-mono" style="font-size:.875rem;"><?= setting('last_reset') ? fmt_datetime(setting('last_reset')) : 'Never run' ?></div>
                        </div>
                        <div class="rounded p-3 mb-3 font-mono" style="background:#f4f6fb;font-size:.75rem;color:var(--muted);">
                            Crontab:<br><strong style="color:#111;">0 0 * * * php /path/to/site/cron/midnight_reset.php</strong>
                        </div>
                        <?php if (setting('dfi_enabled', '1') === '1'): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="trigger_dfi" id="triggerDfi" value="1" form="manualResetForm">
                                <label class="form-check-label" for="triggerDfi" style="font-size:.8rem;">Also trigger DFI payout now</label>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-warning w-100"
                            onclick="showConfirm({title:'Run Daily Reset',message:'Reset pairs_paid_today = 0 and pairs_volume_today = 0 for ALL active members now? This simulates the midnight cron.',confirmText:'⟳ Run Reset',confirmClass:'btn-warning',formId:'manualResetForm'})">
                            ⟳ Run Daily Reset Now
                        </button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 8 — INVOICES & DOCUMENTS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-documents" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings" id="invoiceAddressForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="documents">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#eff6ff;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🧾</span>
                            <span class="card-title">Invoices &amp; Documents</span>
                        </div>
                        <div class="card-body">
                            <div class="form-text mb-3" style="font-size:.75rem;">Legal details printed on the sales invoice and refund credit notes. Invoices already issued keep the details that were in effect when they were paid; changes here apply to invoices issued afterwards.</div>
                            <div class="mb-3">
                                <label class="form-label">Registered Business Name</label>
                                <input type="text" name="invoice_company_name" class="form-control" value="<?= e(setting('invoice_company_name', setting('site_name'))) ?>">
                                <div class="form-text">Defaults to the site name when left blank</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Region, Province, City, Barangay</label>
                                <button type="button" id="invoice_loc" class="form-control text-start js-psgc-trigger" aria-haspopup="dialog">
                                    <span class="text-muted js-psgc-placeholder">Select region, province, city, barangay…</span>
                                </button>
                                <input type="hidden" name="invoice_region" id="invoice_region" value="<?= e(setting('invoice_region')) ?>">
                                <input type="hidden" name="invoice_province" id="invoice_province" value="<?= e(setting('invoice_province')) ?>">
                                <input type="hidden" name="invoice_city" id="invoice_city" value="<?= e(setting('invoice_city')) ?>">
                                <input type="hidden" name="invoice_barangay" id="invoice_barangay" value="<?= e(setting('invoice_barangay')) ?>">
                                <input type="hidden" name="invoice_region_code" id="invoice_region_code" value="<?= e(setting('invoice_region_code')) ?>">
                                <input type="hidden" name="invoice_province_code" id="invoice_province_code" value="<?= e(setting('invoice_province_code')) ?>">
                                <input type="hidden" name="invoice_city_code" id="invoice_city_code" value="<?= e(setting('invoice_city_code')) ?>">
                                <input type="hidden" name="invoice_barangay_code" id="invoice_barangay_code" value="<?= e(setting('invoice_barangay_code')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Business Address</label>
                                <input type="text" name="invoice_street" id="invoice_street" class="form-control mb-2" maxlength="255" placeholder="Street name, building, house no." value="<?= e(setting('invoice_street', setting('invoice_address'))) ?>">
                                <textarea name="invoice_address" id="invoice_address" class="form-control" rows="2" readonly title="Composed automatically from the selected location and street above"><?= e(setting('invoice_address')) ?></textarea>
                                <div class="form-text">Composed automatically: street + barangay, city, province, region. Printed on invoices.</div>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">TIN</label>
                                    <input type="text" name="invoice_tin" class="form-control font-mono" value="<?= e(setting('invoice_tin')) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contact Phone</label>
                                    <input type="text" name="invoice_phone" class="form-control" value="<?= e(setting('invoice_phone')) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Credit Note Prefix</label>
                                    <input type="text" name="invoice_credit_prefix" class="form-control font-mono" value="<?= e(setting('invoice_credit_prefix', 'CN')) ?>" maxlength="12">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Currency Label</label>
                                    <input type="text" name="invoice_currency" class="form-control" value="<?= e(setting('invoice_currency', 'PHP')) ?>" maxlength="8">
                                </div>
                            </div>
                            <div class="mb-3 mt-3">
                                <label class="form-label">Tax Note</label>
                                <input type="text" name="invoice_vat_note" class="form-control" value="<?= e(setting('invoice_vat_note', 'Prices are inclusive of applicable taxes.')) ?>">
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Document Footer</label>
                                <input type="text" name="invoice_footer" class="form-control" value="<?= e(setting('invoice_footer', 'This document is computer-generated and valid without a signature.')) ?>">
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 9 — SYSTEM OVERVIEW (read-only)
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-overview" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span style="width:28px;height:28px;background:#f3f4f6;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">ℹ️</span>
                        <span class="card-title">System Overview</span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-borderless mb-0" style="font-size:.82rem;">
                            <tr>
                                <td class="ps-3 text-muted" style="width:40%;">PHP Version</td>
                                <td class="font-mono pe-3"><?= PHP_VERSION ?></td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td class="ps-3 text-muted">MySQL Version</td>
                                <td class="font-mono pe-3"><?= db()->query('SELECT VERSION()')->fetchColumn() ?></td>
                            </tr>
                            <tr>
                                <td class="ps-3 text-muted">Server Time</td>
                                <td class="font-mono pe-3"><?= date('Y-m-d H:i:s') ?></td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td class="ps-3 text-muted">App URL</td>
                                <td class="font-mono pe-3" style="font-size:.72rem;word-break:break-all;"><?= APP_URL ?></td>
                            </tr>
                            <tr>
                                <td class="ps-3 text-muted">Environment</td>
                                <td class="pe-3"><span class="badge <?= APP_ENV === 'production' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?>" style="font-size:.72rem;"><?= APP_ENV ?></span></td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td class="ps-3 text-muted">Members</td>
                                <td class="pe-3 font-mono"><?= User::counts()['total'] ?? 0 ?> / <?= e(setting('seat_limit', '1000')) ?></td>
                            </tr>
                            <tr>
                                <td class="ps-3 text-muted">Binary Status</td>
                                <td class="pe-3"><?php if (setting('binary_enabled', '1') === '1'): ?><span class="badge bg-success-subtle text-success" style="font-size:.72rem;">🟢 Enabled</span><?php else: ?><span class="badge bg-secondary-subtle text-secondary" style="font-size:.72rem;">⚪ Disabled</span><?php endif; ?></td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td class="ps-3 text-muted">Maintenance</td>
                                <td class="pe-3"><?php if (setting('maintenance_mode') === '1'): ?><span class="badge bg-danger-subtle text-danger" style="font-size:.72rem;">🔴 On</span><?php else: ?><span class="badge bg-success-subtle text-success" style="font-size:.72rem;">🟢 Off</span><?php endif; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /tab-content -->
    </div>
</div>

<?php require 'views/partials/address_picker.php'; ?>
<script>
    function togglePw(id, btn) {
        var el = document.getElementById(id);
        el.type = el.type === 'password' ? 'text' : 'password';
        btn.textContent = el.type === 'password' ? '👁' : '🙈';
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Activate tab from URL hash on page load
        var hash = window.location.hash || '#tabPane-basics';
        var pane = document.querySelector(hash);
        if (pane) {
            document.querySelectorAll('.tab-pane').forEach(function(p) {
                p.classList.remove('show', 'active');
            });
            pane.classList.add('show', 'active');
        }

        // Handle packages disabled toggle affecting free registration
        var packagesDisabledToggle = document.getElementById('packagesDisabled');
        var freeRegSelect = document.getElementById('freeRegistrationSelect');
        if (packagesDisabledToggle && freeRegSelect) {
            function syncFreeRegState() {
                if (packagesDisabledToggle.checked) {
                    freeRegSelect.value = '1';
                    freeRegSelect.disabled = true;
                    // Add hidden input if not exists
                    var hidden = document.querySelector('input[type="hidden"][name="free_registration_enabled"]');
                    if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'free_registration_enabled';
                        hidden.value = '1';
                        freeRegSelect.parentNode.appendChild(hidden);
                    } else {
                        hidden.value = '1';
                    }
                } else {
                    freeRegSelect.disabled = false;
                    var hidden = document.querySelector('input[type="hidden"][name="free_registration_enabled"]');
                    if (hidden) {
                        hidden.remove();
                    }
                }
            }
            packagesDisabledToggle.addEventListener('change', syncFreeRegState);
            syncFreeRegState();
        }

        // Handle packages disabled toggle affecting compensations
        var compMaster = document.getElementById('commissionsEnabled');
        var compTypes = document.querySelectorAll('.comp-type');
        var compNotice = document.getElementById('compensationsPackagesDisabledNotice');
        var compensationsForm = document.getElementById('compensationsForm');
        if (packagesDisabledToggle && (compMaster || compTypes.length > 0)) {
            function syncCompensationsState() {
                var disabled = packagesDisabledToggle.checked;
                if (compNotice) {
                    compNotice.style.display = disabled ? '' : 'none';
                }
                if (compMaster) {
                    compMaster.disabled = disabled;
                    if (disabled) {
                        compMaster.checked = false;
                    }
                    var masterHidden = document.getElementById('compMasterHidden');
                    if (disabled) {
                        if (!masterHidden && compensationsForm) {
                            masterHidden = document.createElement('input');
                            masterHidden.type = 'hidden';
                            masterHidden.name = 'commissions_enabled';
                            masterHidden.id = 'compMasterHidden';
                            masterHidden.value = '0';
                            compensationsForm.appendChild(masterHidden);
                        } else if (masterHidden) {
                            masterHidden.value = '0';
                        }
                    } else if (masterHidden) {
                        masterHidden.remove();
                    }
                }
                compTypes.forEach(function(cb) {
                    cb.disabled = disabled;
                    if (disabled) {
                        cb.checked = false;
                    }
                    var h = document.getElementById('compTypeHidden_' + cb.name);
                    if (disabled) {
                        if (!h && compensationsForm) {
                            h = document.createElement('input');
                            h.type = 'hidden';
                            h.name = cb.name;
                            h.id = 'compTypeHidden_' + cb.name;
                            h.value = '0';
                            compensationsForm.appendChild(h);
                        } else if (h) {
                            h.value = '0';
                        }
                    } else if (h) {
                        h.remove();
                    }
                });
            }
            if (packagesDisabledToggle) {
                packagesDisabledToggle.addEventListener('change', syncCompensationsState);
            }
            syncCompensationsState();
        }

        // ── Business address: PSGC picker (same psgc.cloud fetch as profile) ──
        var trigger = document.getElementById('invoice_loc');
        var docForm = document.getElementById('invoiceAddressForm');
        if (!trigger || !docForm || typeof PsgcPicker === 'undefined') return;

        var invFields = {
            region: 'invoice_region', province: 'invoice_province',
            city: 'invoice_city', barangay: 'invoice_barangay',
            regionCode: 'invoice_region_code', provinceCode: 'invoice_province_code',
            cityCode: 'invoice_city_code', barangayCode: 'invoice_barangay_code'
        };
        function setInv(name, value) {
            var el = document.getElementById(invFields[name]);
            if (el) el.value = value == null ? '' : value;
        }
        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; });
        }
        function composeAddress() {
            var street = (document.getElementById('invoice_street').value || '').trim();
            var parts = ['invoice_barangay', 'invoice_city', 'invoice_province', 'invoice_region'].map(function (id) {
                return (document.getElementById(id).value || '').trim();
            }).filter(Boolean);
            var lines = [street].concat(parts);
            document.getElementById('invoice_address').value = lines.length ? lines.join(', ') : '';
        }
        function renderInvLoc() {
            var parts = ['invoice_barangay', 'invoice_city', 'invoice_province', 'invoice_region'].map(function (id) {
                return (document.getElementById(id).value || '').trim();
            }).filter(Boolean);
            trigger.innerHTML = parts.length
                ? '<span>' + parts.map(esc).join(', ') + '</span>'
                : '<span class="text-muted js-psgc-placeholder">Select region, province, city, barangay…</span>';
        }
        PsgcPicker.attach({
            trigger: trigger,
            onPick: function (picked) {
                setInv('region', picked.region ? picked.region.name : '');
                setInv('province', picked.province ? picked.province.name : '');
                setInv('city', picked.city ? picked.city.name : '');
                setInv('barangay', picked.barangay ? picked.barangay.name : '');
                setInv('regionCode', picked.region ? picked.region.code : '');
                setInv('provinceCode', picked.province ? picked.province.code : '');
                setInv('cityCode', picked.city ? picked.city.code : '');
                setInv('barangayCode', picked.barangay ? picked.barangay.code : '');
                renderInvLoc();
                composeAddress();
            }
        });
        document.getElementById('invoice_street').addEventListener('input', composeAddress);
        docForm.addEventListener('submit', function () { renderInvLoc(); composeAddress(); });
        renderInvLoc();
        composeAddress();
    });
</script>

<?php require 'views/partials/settings_offcanvas.php'; ?>
<?php require 'views/partials/footer.php'; ?>