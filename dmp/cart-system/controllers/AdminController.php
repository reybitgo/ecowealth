<?php

/**
 * @file   controllers/AdminController.php
 * @brief  Admin controller for handling admin-specific actions
 */
class AdminController
{
    public function dashboard(): void
    {
        Auth::guard('admin');
        $memberCounts  = User::counts();
        $codeStat      = Code::stats();
        $pendingPayout = Payout::pendingTotal();
        $totalPaid     = Payout::totalPaid();
        $pendingList   = Payout::all(1, 'pending')['data'];

        // v2: Cap & DFI stat cards
        $pdo = db();
        $v2Stats = [
            'capped'        => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='capped'")->fetchColumn(),
            'perminact'     => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='perminact'")->fetchColumn(),
            'react_revenue' => (float)$pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM reactivations WHERE status='completed'")->fetchColumn(),
            'dfi_today'     => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM daily_fixed_income_log WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        ];

        require 'views/admin/dashboard.php';
    }

    public function users(): void
    {
        Auth::guard('admin');
        $page     = max(1, (int)($_GET['pg'] ?? 1));
        $search   = trim($_GET['q']      ?? '');
        $status   = $_GET['status']      ?? '';
        $pkgId    = (int)($_GET['pkg']   ?? 0);
        $perPage  = max(5, (int)($_GET['per_page'] ?? 10));
        $packages = Package::all();
        $result   = User::allMembers($page, $search, $status, $pkgId, $perPage);
        require 'views/admin/users.php';
    }

    public function viewUser(): void
    {
        Auth::guard('admin');
        $id   = (int)($_GET['id'] ?? 0);
        $user = User::find($id);
        if (!$user) {
            flash('error', 'User not found.');
            redirect('/?page=admin_users');
        }

        $tab     = $_GET['tab'] ?? 'commissions';
        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $perPage = max(5, (int)($_GET['per_page'] ?? 10));

        $summary  = Commission::summary($id);
        $payouts  = Payout::forUser($id, $page, $perPage);
        $commHist = Commission::history($id, $page, $perPage);
        $ledger   = Ewallet::ledger($id, $page, $perPage);
        $pairingStatus = User::todayPairingStatus($id);
        $cdStatus = CdStatus::getActive($id);
        $cdHistory = CdStatus::history($id);

        // v2: Cap & DFI data for admin user view tab
        $capStatus = User::getCapStatus($id);
        $dfiStatus = DailyFixedIncome::getMemberDFIStatus($id);
        $reactivationHistory = Reactivation::getReactivationHistory($id, $page, $perPage);
        $capBlocked = paginate(
            "SELECT c.*, u.username AS source_username
             FROM commissions c
             LEFT JOIN users u ON u.id = c.source_user_id
             WHERE c.user_id = ? AND c.cap_deduction > 0
             ORDER BY c.created_at DESC",
            [$id],
            $page,
            $perPage
        );

        // Transfer history for e-wallet tab
        $transferHistory = paginate(
            "SELECT t.*, su.username AS sender_username, ru.username AS recipient_username
             FROM ewallet_transfers t
             JOIN users su ON su.id = t.sender_id
             JOIN users ru ON ru.id = t.recipient_id
             WHERE t.sender_id = ? OR t.recipient_id = ?
             ORDER BY t.created_at DESC",
            [$id, $id],
            $page,
            $perPage
        );

        require 'views/admin/user_view.php';
    }

    public function toggleUser(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id   = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'Invalid user ID.');
            redirect('/?page=admin_users');
            return;
        }

        $user = User::find($id);
        if (!$user || $user['role'] === 'admin') {
            flash('error', 'Invalid user or cannot modify administrator account.');
            redirect('/?page=admin_users');
            return;
        }

        $newStatus = $user['status'] === 'active' ? 'suspended' : 'active';

        // Deactivated users can only be reactivated via deactivateUser
        if ($user['status'] === 'deactivated') {
            flash('error', 'Use the Deactivate button to reactivate this member.');
            redirect('/?page=admin_user_view&id=' . $id);
            return;
        }

        $pdo = db();
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $success = $stmt->execute([$newStatus, $id]);

        if ($success) {
            $action = ($newStatus === 'active') ? 'activated' : 'suspended';
            flash('success', "User @{$user['username']} has been {$action} successfully.");
        } else {
            flash('error', 'Failed to update user status. Please try again.');
        }

        // Return JSON only for AJAX requests
        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            json_response(['ok' => $success, 'status' => $newStatus]);
        }

        // ── Smart Redirect Logic ─────────────────────────────────────
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        // If came from user view page → stay on that user's view
        if (strpos($referer, 'admin_user_view') !== false && strpos($referer, "id={$id}") !== false) {
            redirect("/?page=admin_user_view&id={$id}");
        }

        // Otherwise (from members list or anywhere else) → go back to members list
        redirect('/?page=admin_users');
    }

    public function deactivateUser(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'Invalid user ID.');
            redirect('/?page=admin_users');
            return;
        }

        $user = User::find($id);
        if (!$user || $user['role'] === 'admin') {
            flash('error', 'Invalid user or cannot modify administrator account.');
            redirect('/?page=admin_users');
            return;
        }

        $newStatus = $user['status'] === 'deactivated' ? 'active' : 'deactivated';

        $pdo = db();
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $success = $stmt->execute([$newStatus, $id]);

        if ($success) {
            $action = ($newStatus === 'deactivated') ? 'deactivated' : 'activated';
            flash('success', "User @{$user['username']} has been {$action} successfully.");
        } else {
            flash('error', 'Failed to update user status. Please try again.');
        }

        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            json_response(['ok' => $success, 'status' => $newStatus]);
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, 'admin_user_view') !== false && strpos($referer, "id={$id}") !== false) {
            redirect("/?page=admin_user_view&id={$id}");
        }
        redirect('/?page=admin_users');
    }

    public function changePassword(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId       = (int)($_POST['user_id'] ?? 0);
        $newPassword  = $_POST['new_password'] ?? '';
        $confirmPw    = $_POST['new_password_confirm'] ?? '';

        if ($userId <= 0) {
            flash('error', 'Invalid member ID.');
            redirect('/?page=admin_users');
        }

        $user = User::find($userId);
        if (!$user || $user['role'] !== 'member') {
            flash('error', 'Member not found.');
            redirect("/?page=admin_user_view&id={$userId}");
        }

        if (strlen($newPassword) < 8) {
            flash('error', 'New password must be at least 8 characters.');
            redirect("/?page=admin_user_view&id={$userId}");
        }

        if ($newPassword !== $confirmPw) {
            flash('error', 'New passwords do not match.');
            redirect("/?page=admin_user_view&id={$userId}");
        }

        $updated = User::updatePassword($userId, $newPassword);

        if ($updated) {
            flash('success', "Password for @{$user['username']} has been changed successfully.");
        } else {
            flash('error', 'Failed to update password. Please try again.');
        }

        redirect("/?page=admin_user_view&id={$userId}");
    }

    /**
     * Called from payout.php JS when live TRC20 gas fee differs from DB value.
     * Updates the setting only if the rounded value actually changed (max 2 decimals).
     * Accessible to logged-in members so the payout page can call it without admin session.
     */
    public function updateUsdtGas(): void
    {
        // Require JSON POST
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $fee  = isset($body['fee']) ? round((float)$body['fee'], 4) : null;

        if ($fee === null || $fee <= 0 || $fee > 50) {
            json_response(['ok' => false, 'error' => 'Invalid fee value.'], 400);
        }

        $current = round((float)setting('usdt_trc20_gas_fee', '2.50'), 4);

        // Only write if value actually changed (avoid unnecessary DB writes)
        if (abs($fee - $current) < 0.0001) {
            json_response(['ok' => true, 'updated' => false, 'fee' => $fee]);
        }

        db()->prepare("UPDATE settings SET value = ? WHERE key_name = 'usdt_trc20_gas_fee'")
            ->execute([(string)$fee]);

        json_response(['ok' => true, 'updated' => true, 'fee' => $fee, 'previous' => $current]);
    }

    public function updateUsdtBep20Gas(): void
    {
        // Require JSON POST
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $fee  = isset($body['fee']) ? round((float)$body['fee'], 4) : null;

        if ($fee === null || $fee <= 0 || $fee > 50) {
            json_response(['ok' => false, 'error' => 'Invalid fee value.'], 400);
        }

        $current = round((float)setting('usdt_bep20_gas_fee', '0.05'), 4);

        // Only write if value actually changed (avoid unnecessary DB writes)
        if (abs($fee - $current) < 0.0001) {
            json_response(['ok' => true, 'updated' => false, 'fee' => $fee]);
        }

        db()->prepare("UPDATE settings SET value = ? WHERE key_name = 'usdt_bep20_gas_fee'")
            ->execute([(string)$fee]);

        json_response(['ok' => true, 'updated' => true, 'fee' => $fee, 'previous' => $current]);
    }

    public function packages(): void
    {
        Auth::guard('admin');
        $page        = max(1, (int)($_GET['pg'] ?? 1));
        $perPage     = max(5, (int)($_GET['per_page'] ?? 10));
        $packages    = paginate(
            'SELECT * FROM packages ORDER BY entry_fee ASC',
            [],
            $page,
            $perPage
        );
        $allPackages = Package::all();
        $editPkg     = null;
        $viewPkg     = null;
        if (isset($_GET['edit'])) {
            $editPkg = Package::withLevels((int)$_GET['edit']);
        }
        if (isset($_GET['view'])) {
            $viewPkg = Package::withLevels((int)$_GET['view']);
        }
        require 'views/admin/packages.php';
    }

    public function savePackage(): void
    {
        Auth::guard('admin');
        csrf_verify();

        if (function_exists('setting') && setting('packages_disabled', '0') === '1') {
            flash('error', 'Packages are disabled. Adding or modifying packages is not allowed.');
            redirect('/?page=admin_packages');
            return;
        }

        $id       = (int)($_POST['package_id'] ?? 0);
        $existing = $id ? Package::find($id) : null;
        $data = [
            'name'             => trim($_POST['name']             ?? ''),
            'image'            => $existing ? ($existing['image'] ?? null) : null,
            'entry_fee'        => (float)($_POST['entry_fee']      ?? 0),
            'pairing_bonus'    => (float)($_POST['pairing_bonus']  ?? 0),
            'daily_pair_cap'   => (int)($_POST['daily_pair_cap']   ?? 3),
            'direct_ref_bonus' => (float)($_POST['direct_ref_bonus'] ?? 0),
            'status'           => $_POST['status'] ?? 'active',
            'indirect_levels'  => [],
            // NEW v2 fields
            'lifetime_cap_multiplier'  => (float)($_POST['lifetime_cap_multiplier']  ?? 3.00),
            'reactivation_fee'         => (float)($_POST['reactivation_fee']         ?? 0),
            'reactivation_window_days' => (int)($_POST['reactivation_window_days']    ?? 15),
            'daily_fixed_income'       => (float)($_POST['daily_fixed_income']       ?? 0),
            'daily_fixed_income_days'  => (int)($_POST['daily_fixed_income_days']    ?? 90),
            'indirect_referral_enabled' => !empty($_POST['indirect_referral_enabled']) ? 1 : 0,
            'dfi_enabled'               => !empty($_POST['dfi_enabled']) ? 1 : 0,
            'pairing_enabled'           => !empty($_POST['pairing_enabled']) ? 1 : 0,
        ];

        for ($lvl = 1; $lvl <= 10; $lvl++) {
            $data['indirect_levels'][$lvl] = (float)($_POST["indirect_{$lvl}"] ?? 0);
        }

        // Build back-URL so validation errors return to the correct form (edit or new)
        $backUrl = $id
            ? '/?page=admin_packages&edit=' . $id
            : '/?page=admin_packages';

        if (!$data['name'] || $data['entry_fee'] <= 0) {
            flash('error', 'Package name and entry fee are required.');
            redirect($backUrl);
        }

        // Validate v2 fields
        if ($data['lifetime_cap_multiplier'] < 1) {
            flash('error', 'Lifetime cap multiplier must be at least 1.0.');
            redirect($backUrl);
        }
        if ($data['reactivation_window_days'] < 1) {
            flash('error', 'Reactivation window must be at least 1 day.');
            redirect($backUrl);
        }
        if ($data['daily_fixed_income'] < 0) {
            flash('error', 'Daily fixed income cannot be negative.');
            redirect($backUrl);
        }
        if ($data['daily_fixed_income_days'] < 1) {
            flash('error', 'Max DFI days must be at least 1.');
            redirect($backUrl);
        }

        // Handle package image upload (optional — old image kept when none selected)
        if (!empty($_FILES['package_image']['tmp_name'])) {
            $file    = $_FILES['package_image'];
            $mime    = mime_content_type($file['tmp_name']);
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];

            if (!in_array($mime, $allowed, true)) {
                flash('error', 'Package image must be a JPG, PNG or WebP image.');
                redirect($backUrl);
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                flash('error', 'Package image must be 5 MB or smaller.');
                redirect($backUrl);
            }
            if (!empty($file['error'])) {
                flash('error', 'Package image upload failed. Please try again.');
                redirect($backUrl);
            }

            $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads'
                       . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $name = 'package_' . ($id ?: 'new') . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            $dest = $uploadDir . $name;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                if ($existing && !empty($existing['image'])) {
                    @unlink(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads'
                        . DIRECTORY_SEPARATOR . $existing['image']);
                }
                $data['image'] = 'packages/' . $name;
            } else {
                flash('error', 'Package image could not be saved. Please try again.');
                redirect($backUrl);
            }
        }

        Package::save($data, $id ?: null);
        flash('success', $id ? 'Package updated with v2 settings.' : 'Package created with v2 settings.');
        redirect('/?page=admin_packages');
    }

    // ── Registration Codes ────────────────────────────────────────────────────

    public function codes(): void
    {
        Auth::guard('admin');
        $page     = max(1, (int)($_GET['pg']  ?? 1));
        $status   = $_GET['status']            ?? '';
        $pkgId    = (int)($_GET['pkg']         ?? 0);
        $perPage  = max(5, (int)($_GET['per_page'] ?? 10));
        $packages = Package::all(true);
        $codes    = Code::all($page, $status, $pkgId, $perPage);
        $stats    = Code::stats();
        require 'views/admin/codes.php';
    }

    public function generateCodes(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $pkgId    = (int)($_POST['package_id'] ?? 0);
        $qty      = min(500, max(1, (int)($_POST['quantity'] ?? 1)));
        $price    = (float)($_POST['price']    ?? 0);
        $expires  = trim($_POST['expires_at']  ?? '');
        $codeType = in_array($_POST['code_type'] ?? 'registration', ['registration', 'cd', 'upgrade'], true)
            ? $_POST['code_type'] : 'registration';

        if (!$pkgId || $price <= 0) {
            flash('error', 'Package and price are required.');
            redirect('/?page=admin_codes');
        }

        $generated = Code::generate($pkgId, $qty, $price, $expires ?: null, Auth::actingAdminId(), $codeType);
        flash('success', count($generated) . ' code(s) generated (' . $codeType . ') successfully.');
        redirect('/?page=admin_codes');
    }

    public function exportCodes(): void
    {
        Auth::guard('admin');
        $status = $_GET['status'] ?? '';
        $pkgId  = (int)($_GET['pkg'] ?? 0);
        Code::exportCSV($status, $pkgId);
    }

    // ── Payouts ───────────────────────────────────────────────────────────────

    public function payouts(): void
    {
        Auth::guard('admin');
        $page    = max(1, (int)($_GET['pg']     ?? 1));
        $status  = $_GET['status']               ?? 'pending';
        $perPage = max(5, (int)($_GET['per_page'] ?? 10));
        $result  = Payout::all($page, $status, $perPage);
        require 'views/admin/payouts.php';
    }

    public function payoutAction(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $action   = $_POST['action']    ?? '';
        $id       = (int)($_POST['id']  ?? 0);
        $note     = trim($_POST['note'] ?? '');
        $adminId  = Auth::actingAdminId();

        switch ($action) {
            case 'approve':
                $ok = Payout::approve($id, $adminId);
                flash($ok ? 'success' : 'error', $ok ? 'Payout approved.' : 'Could not approve.');
                break;
            case 'reject':
                $ok = Payout::reject($id, $adminId, $note);
                flash($ok ? 'success' : 'error', $ok ? 'Payout rejected.' : 'Could not reject.');
                break;
            case 'complete':
                $result = Payout::complete($id, $adminId, $note);
                flash(
                    $result['ok'] ? 'success' : 'error',
                    $result['ok'] ? 'Payout marked as completed. E-wallet deducted.' : $result['error']
                );
                break;
            default:
                flash('error', 'Unknown action.');
        }
        redirect('/?page=admin_payouts');
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function settings(): void
    {
        Auth::guard('admin');
        require 'views/admin/settings.php';
    }

    public function saveSettings(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $groups = [
            'basics' => [
                'site_name',
                'site_tagline',
                'contact_email',
                'min_payout',
                'shop_enabled',
                'shop_payment_deadline_hours',
                'shop_correction_window_hours',
                'shop_max_proof_attempts',
                'shop_report_window_days',
                'shop_max_delivery_attempts',
            ],
            'maint' => [
                'maintenance_mode',
                'seat_limit',
                'free_registration_enabled',
                'packages_disabled',
            ],
            'payments' => [
                'reactivation_ewallet_enabled',
                'reactivation_external_enabled',
                'gcash_number',
                'maya_number',
                'usdt_trc20_address',
                'usdt_bep20_address',
            ],
            'ewallet' => [
                'ewallet_transfer_fee',
                'ewallet_min_transfer',
                'ewallet_transfer_daily_limit',
                'ewallet_transfer_weekly_limit',
            ],
            'payouts' => [
                'gcash_enabled',
                'maya_enabled',
                'service_fee_gcash',
                'service_fee_maya',
                'service_fee_usdt_trc20',
                'service_fee_usdt_bep20',
                'usdt_trc20_gas_fee',
                'usdt_bep20_gas_fee',
            ],
            'compensations' => [
                'commissions_enabled',
                'commission_direct_ref_enabled',
                'commission_indirect_ref_enabled',
                'commission_binary_enabled',
                'commission_dfi_enabled',
            ],
            'reset' => [
                'default_cap_multiplier',
            ],
            'documents' => [
                'invoice_company_name',
                'invoice_address',
                'invoice_street',
                'invoice_region',
                'invoice_province',
                'invoice_city',
                'invoice_barangay',
                'invoice_region_code',
                'invoice_province_code',
                'invoice_city_code',
                'invoice_barangay_code',
                'invoice_tin',
                'invoice_phone',
                'invoice_footer',
                'invoice_vat_note',
                'invoice_credit_prefix',
                'invoice_currency',
            ],
        ];
        $group = $_POST['group'] ?? '';
        if (!isset($groups[$group])) {
            flash('error', 'Unknown settings group.');
            redirect('/?page=admin_settings');
        }

        $checkboxKeys = ['gcash_enabled', 'maya_enabled', 'reactivation_ewallet_enabled', 'reactivation_external_enabled', 'shop_enabled', 'packages_disabled', 'commissions_enabled', 'commission_direct_ref_enabled', 'commission_indirect_ref_enabled', 'commission_binary_enabled', 'commission_dfi_enabled'];
        $hourKeys     = ['shop_payment_deadline_hours', 'shop_correction_window_hours', 'shop_max_proof_attempts', 'shop_report_window_days', 'shop_max_delivery_attempts'];

        $pdo = db();
        $st  = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");

        foreach ($groups[$group] as $key) {
            // Only the submitted tab's keys are written, so saving one tab can
            // never clobber toggles that live on another tab.
            if (in_array($key, $checkboxKeys, true)) {
                // Checkbox toggles: when unchecked the field is absent from POST,
                // so we explicitly save '0' for these keys when not present.
                $value = isset($_POST[$key]) && $_POST[$key] === '1' ? '1' : '0';
                $st->execute([$key, $value]);
            } elseif (in_array($key, $hourKeys, true)) {
                // Clamped in the controller (§4.3): a 0 deadline would expire
                // every order on arrival; an unbounded attempt count lets a
                // buyer loop proofs forever.
                $st->execute([$key, (string) max(1, (int) ($_POST[$key] ?? 1))]);
            } elseif (isset($_POST[$key])) {
                $st->execute([$key, trim($_POST[$key])]);
            }
        }

        // Enforce rules when packages are disabled
        if ($group === 'maint') {
            $packagesDisabled = isset($_POST['packages_disabled']) && $_POST['packages_disabled'] === '1';
            if ($packagesDisabled) {
                // Force free registration on and make it read-only
                $st2 = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('free_registration_enabled', '1') ON DUPLICATE KEY UPDATE value = '1'");
                $st2->execute();
                // Make all packages inactive
                $pdo->exec("UPDATE packages SET status = 'inactive'");
                // Disable all commissions while packages are disabled
                $compKeys = ['commissions_enabled', 'commission_direct_ref_enabled', 'commission_indirect_ref_enabled', 'commission_binary_enabled', 'commission_dfi_enabled'];
                $stComp = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, '0') ON DUPLICATE KEY UPDATE value = '0'");
                foreach ($compKeys as $ck) {
                    $stComp->execute([$ck]);
                    setting_forget($ck);
                }
                setting_forget('free_registration_enabled');
                setting_forget('packages_disabled');
            } else {
                setting_forget('free_registration_enabled');
                setting_forget('packages_disabled');
            }
        } else {
            setting_forget(null);
        }

        flash('success', 'Settings saved.');
        $hash = '#tabPane-' . $group;
        redirect('/?page=admin_settings' . $hash);
    }

    public function manualReset(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $affected = db()->exec("UPDATE users SET pairs_paid_today = 0, pairs_volume_today = 0 WHERE role = 'member'");
        db()->prepare("UPDATE settings SET value = ? WHERE key_name = 'last_reset'")
            ->execute([date('Y-m-d H:i:s')]);

        $msg = "Daily pair counter reset for {$affected} member(s).";

        // v3: Optional DFI trigger
        if (isset($_POST['trigger_dfi']) && $_POST['trigger_dfi'] === '1') {
            $dfiResult = DailyFixedIncome::processDailyPayout();
            if (($dfiResult['reason'] ?? '') === 'disabled') {
                $msg .= ' DFI is currently disabled.';
            } else {
                $msg .= " DFI: ₱" . number_format($dfiResult['paid'], 2)
                     . " paid to {$dfiResult['processed']} member(s),"
                     . " {$dfiResult['skipped']} skipped.";
            }
        }

        flash('success', $msg);
        redirect('/?page=admin_settings#tabPane-reset');
    }

    public function capMonitor(): void
    {
        Auth::guard('admin');
        $pdo = db();

        $stats = [
            'active'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='active'")->fetchColumn(),
            'capped'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='capped'")->fetchColumn(),
            'perminact' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='perminact'")->fetchColumn(),
        ];

        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $status  = $_GET['status'] ?? '';
        $perPage = max(5, (int)($_GET['per_page'] ?? 10));

        $where = "u.role='member'";
        $params = [];
        if ($status && in_array($status, ['active', 'capped', 'perminact'])) {
            $where .= " AND u.cap_status = ?";
            $params[] = $status;
        }

        $result = paginate(
            "SELECT u.*, p.name AS package_name, p.entry_fee, p.lifetime_cap_multiplier,
                    (p.entry_fee * p.lifetime_cap_multiplier) AS lifetime_cap
             FROM users u
             LEFT JOIN packages p ON p.id = u.package_id
             WHERE {$where}
             ORDER BY u.cap_status DESC, u.lifetime_earned DESC",
            $params,
            $page,
            $perPage
        );

        require 'views/admin/cap_monitor.php';
    }

    public function dfiAdmin(): void
    {
        Auth::guard('admin');
        $pdo = db();

        $todayDfi = (float)$pdo->query("
            SELECT COALESCE(SUM(amount), 0) 
            FROM daily_fixed_income_log 
            WHERE DATE(created_at) = CURDATE()
        ")->fetchColumn();

        $totalDfi = (float)$pdo->query("
            SELECT COALESCE(SUM(amount), 0) 
            FROM daily_fixed_income_log
        ")->fetchColumn();

        $totalMembers = (int)$pdo->query("
            SELECT COUNT(DISTINCT user_id) 
            FROM daily_fixed_income_log
        ")->fetchColumn();

        require 'views/admin/dfi_admin.php';
    }

    public function toggleVipBypass(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id     = (int)($_POST['id'] ?? 0);
        $bypass = (int)($_POST['bypass'] ?? 0);

        if ($id <= 0) {
            flash('error', 'Invalid user ID.');
            redirect('/?page=admin_users');
            return;
        }

        $user = User::find($id);
        if (!$user || $user['role'] !== 'member') {
            flash('error', 'Member not found.');
            redirect('/?page=admin_users');
            return;
        }

        // Only active members can receive VIP
        if ($bypass && $user['cap_status'] !== 'active') {
            flash('error', 'Only active members can be granted VIP privilege.');
            redirect('/?page=admin_user_view&id=' . $id);
            return;
        }

        db()->prepare("UPDATE users SET capping_bypass = ? WHERE id = ?")
            ->execute([$bypass ? 1 : 0, $id]);

        flash('success', $bypass ? 'VIP privilege granted.' : 'VIP privilege removed.');
        redirect('/?page=admin_user_view&id=' . $id);
    }

    public function toggleDailyCapBypass(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id     = (int)($_POST['id'] ?? 0);
        $bypass = (int)($_POST['bypass'] ?? 0);

        if ($id <= 0) {
            flash('error', 'Invalid user ID.');
            redirect('/?page=admin_users');
            return;
        }

        db()->prepare("UPDATE users SET daily_cap_bypass = ? WHERE id = ? AND role = 'member'")
            ->execute([$bypass ? 1 : 0, $id]);

        flash('success', $bypass ? 'Daily cap bypass enabled.' : 'Daily cap bypass disabled.');
        redirect('/?page=admin_user_view&id=' . $id);
    }

    public function reactivations(): void
    {
        Auth::guard('admin');
        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $status  = $_GET['status'] ?? '';
        $perPage = max(5, (int)($_GET['per_page'] ?? 10));
        $result  = Reactivation::all($page, $status, $perPage);

        $totalRevenue = Reactivation::completedTotal();
        $pendingTotal = Reactivation::pendingTotal();

        // Fetch admin payment details for reactivation display
        $pdo = db();
        $adminPayment = [];
        foreach (['gcash_number','maya_number','usdt_trc20_address','usdt_bep20_address'] as $k) {
            $adminPayment[$k] = $pdo->query("SELECT value FROM settings WHERE key_name='{$k}'")->fetchColumn() ?: '';
        }

        require 'views/admin/reactivations.php';
    }

    public function reactivationAction(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $action   = $_POST['action']    ?? '';
        $id       = (int)($_POST['id']  ?? 0);
        $note     = trim($_POST['note'] ?? '');
        $adminId  = Auth::actingAdminId();

        switch ($action) {
            case 'confirm':
                $result = Reactivation::confirm($id, $adminId, $note);
                flash(
                    $result['ok'] ? 'success' : 'error',
                    $result['ok'] ? $result['message'] : $result['error']
                );
                break;
            case 'reject':
                $ok = Reactivation::reject($id, $adminId, $note);
                flash($ok ? 'success' : 'error', $ok ? 'Reactivation rejected.' : 'Could not reject.');
                break;
            default:
                flash('error', 'Unknown action.');
        }
        redirect('/?page=admin_reactivations');
    }

    // ── E-Wallet Top-Up & Monitor ──────────────────────────────────────────

    public function ewalletTopUp(): void
    {
        Auth::guard('admin');
        require 'views/admin/ewallet_topup.php';
    }

    public function doEwalletTopUp(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $adminId = Auth::actingAdminId();
        $recipientUsername = trim($_POST['recipient'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        $recipient = User::findByUsername($recipientUsername);
        if (!$recipient) {
            flash('error', 'Recipient not found.');
            redirect('/?page=admin_ewallet_topup');
            return;
        }

        $result = Ewallet::adminTopUp($adminId, $recipient['id'], $amount, $note);

        flash($result['ok'] ? 'success' : 'error', $result['error'] ?? 'Top-up completed successfully.');
        redirect('/?page=admin_ewallet_topup');
    }

    public function ewalletMonitor(): void
    {
        Auth::guard('admin');
        $pdo = db();

        $transfers = $pdo->query("
            SELECT t.*, su.username AS sender_username, ru.username AS recipient_username
            FROM ewallet_transfers t
            JOIN users su ON su.id = t.sender_id
            JOIN users ru ON ru.id = t.recipient_id
            ORDER BY t.created_at DESC
            LIMIT 200
        ")->fetchAll();

        $topups = $pdo->query("
            SELECT tu.*, au.username AS admin_username, ru.username AS recipient_username
            FROM ewallet_admin_topups tu
            JOIN users au ON au.id = tu.admin_id
            JOIN users ru ON ru.id = tu.recipient_id
            ORDER BY tu.created_at DESC
            LIMIT 200
        ")->fetchAll();

        // Fee credits to admin from ewallet_ledger
        $fees = $pdo->query("
            SELECT l.*, t.sender_id, t.recipient_id,
                   su.username AS sender_username, ru.username AS recipient_username
            FROM ewallet_ledger l
            JOIN ewallet_transfers t ON t.id = l.reference_id
            JOIN users su ON su.id = t.sender_id
            JOIN users ru ON ru.id = t.recipient_id
            WHERE l.ref_type = 'transfer' AND l.type = 'credit'
              AND l.note LIKE '%fee%'
            ORDER BY l.created_at DESC
            LIMIT 200
        ")->fetchAll();

        $stats = [
            'total_transfers' => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
            'total_fees'      => (float) $pdo->query("SELECT COALESCE(SUM(fee),0) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
            'total_topups'    => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM ewallet_admin_topups")->fetchColumn(),
            'transfer_count'  => (int)   $pdo->query("SELECT COUNT(*) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
            'topup_count'     => (int)   $pdo->query("SELECT COUNT(*) FROM ewallet_admin_topups")->fetchColumn(),
            'system_withdrawable' => (float) $pdo->query("SELECT COALESCE(SUM(withdrawable_balance),0) FROM users WHERE role='member'")->fetchColumn(),
            'system_non_withdrawable' => (float) $pdo->query("SELECT COALESCE(SUM(ewallet_balance - withdrawable_balance),0) FROM users WHERE role='member'")->fetchColumn(),
        ];

        require 'views/admin/ewallet_monitor.php';
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  COMMISSION-DEDUCT (CD) ADMIN ACTIONS
    // ══════════════════════════════════════════════════════════════════════════

    public function assignCd(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId = (int)($_POST['user_id'] ?? 0);
        $target = (float)($_POST['target_amount'] ?? 0);

        if ($userId <= 0 || $target <= 0) {
            flash('error', 'Invalid user or target amount.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        try {
            CdStatus::assign($userId, $target, Auth::actingAdminId());
            flash('success', 'Commission-Deduct status assigned successfully.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/?page=admin_user_view&id=' . $userId);
    }

    public function completeCd(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId = (int)($_POST['user_id'] ?? 0);
        $cd = CdStatus::getActive($userId);

        if (!$cd) {
            flash('error', 'No active CD found for this user.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        CdStatus::complete((int)$cd['id'], $userId);
        flash('success', 'CD status marked as completed.');
        redirect('/?page=admin_user_view&id=' . $userId);
    }

    public function cancelCd(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId = (int)($_POST['user_id'] ?? 0);
        $cd = CdStatus::getActive($userId);

        if (!$cd) {
            flash('error', 'No active CD found for this user.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        CdStatus::cancel((int)$cd['id'], $userId, trim($_POST['reason'] ?? ''));
        flash('success', 'CD status cancelled. Filled amount is forfeited.');
        redirect('/?page=admin_user_view&id=' . $userId);
    }

    public function editCdTarget(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId = (int)($_POST['user_id'] ?? 0);
        $newTarget = (float)($_POST['target_amount'] ?? 0);

        $cd = CdStatus::getActive($userId);

        if (!$cd) {
            flash('error', 'No active CD found for this user.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        if ($newTarget <= 0) {
            flash('error', 'Target amount must be greater than zero.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        if ($newTarget < (float)$cd['filled_amount']) {
            flash('error', 'New target cannot be less than the already filled amount (' . fmt_money((float)$cd['filled_amount']) . ').');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        CdStatus::updateTarget($userId, $newTarget);
        flash('success', 'CD target updated to ' . fmt_money($newTarget) . '.');
        redirect('/?page=admin_user_view&id=' . $userId);
    }

    // ── Shop (physical storefront) ──────────────────────────────────────────
    // Full implementation per tmp/shop/CART_AND_ADMIN_PRODUCTS_PLAN_REVISED.md §4.2/§4.3.

    public function shopProducts(): void
    {
        Auth::guard('admin');
        $editProduct = null;
        if (($_GET['edit'] ?? '') !== '') {
            $editProduct = Product::find((int) $_GET['edit']);
        }
            $products = Product::allPaginated((int) ($_GET['pg'] ?? 1), per_page());
            // One batched query for the page, so the Delete button can explain
            // itself without a request and without an N+1 (see
            // Product::referenceMap). This is only the fast path: the server
            // still refuses the delete, because a member can add the item to a
            // cart between this render and the click.
            $productRefs = Product::referenceMap(array_column($products['data'] ?? [], 'id'));
            $pageTitle = 'Shop Products';
            require 'views/admin/shop_products.php';
    }

    public function saveShopProduct(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id    = (int) ($_POST['product_id'] ?? 0);
        $name  = trim((string) ($_POST['name'] ?? ''));
        $price = (float) ($_POST['price'] ?? 0);
        if ($name === '' || $price <= 0) {
            flash('error', 'Product name and a price above zero are required.');
            redirect('/?page=admin_shop');
        }

        $data = [
            'sku'               => trim((string) ($_POST['sku'] ?? '')),
            'name'              => $name,
            'price'             => $price,
            'stock'             => max(0, (int) ($_POST['stock'] ?? 0)),
            'short_description' => trim((string) ($_POST['short_description'] ?? '')),
            'description'       => trim((string) ($_POST['description'] ?? '')),
            'status'            => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ];

        try {
            // Replace image when a new file arrives or the remove box is ticked.
            $newImage = null;
            if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newImage = upload_image($_FILES['image'], 'products', 'product_' . ($id ?: 'new'), null);
            }
            $old = $id ? Product::find($id) : null;
            $removeImage = ($id && ($_POST['remove_image'] ?? '') === '1');
            $data['image_url'] = $old['image_url'] ?? null;
            if ($newImage) {
                if ($old && !empty($old['image_url'])) delete_uploaded_file($old['image_url']);
                $data['image_url'] = $newImage;
            } elseif ($removeImage) {
                if ($old && !empty($old['image_url'])) delete_uploaded_file($old['image_url']);
                $data['image_url'] = null;
            }

            Product::save($data, $id ?: null);
            flash('success', $id ? 'Product updated.' : 'Product created.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage() === 'type'
                ? 'Image must be a JPG, PNG, GIF, or WebP file.'
                : ($e->getMessage() === 'size' ? 'Image is too large (max 5 MB).' : 'Invalid image.'));
        } catch (RuntimeException $e) {
            flash('error', 'Could not save the product. Please try again.');
        }
        redirect('/?page=admin_shop');
    }

        /**
         * Enable / disable a product without deleting it.
         *
         * The reversible counterpart to deleteShopProduct(). Shares its AJAX
         * contract — ?ajax=1 answers JSON, anything else redirects with a flash
         * — so the page can update in place and a non-JS post still works.
         */
        public function productStatus(): void
        {
            Auth::guard('admin');
            csrf_verify();

            $id     = (int) ($_POST['product_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');
            $name   = (string) ($_POST['name'] ?? 'this product');
            $name   = mb_substr($name, 0, 160);

            $wantsJson = (($_GET['ajax'] ?? '') === '1')
                || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
                || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

            try {
                Product::setStatus($id, $status);
                $ok  = true;
                $msg = $status === 'inactive'
                    ? "'{$name}' is now disabled. It is hidden from the shop and members cannot add it to a cart."
                    : "'{$name}' is now enabled and visible in the shop.";
            } catch (Throwable $e) {
                error_log('[shop] productStatus failed: ' . $e->getMessage());
                $ok  = false;
                $msg = $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : 'Could not update the product status. Please try again.';
            }

            if ($wantsJson) {
                json_response(['ok' => $ok, 'message' => $msg, 'status' => $status]);
            }
            flash($ok ? 'success' : 'error', $msg);
            redirect('/?page=admin_shop' . ($_POST['edit_id'] ?? '' ? '&edit=' . (int) $_POST['edit_id'] : ''));
        }

        public function deleteShopProduct(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id = (int) ($_POST['product_id'] ?? 0);
        $wantsJson = (($_GET['ajax'] ?? '') === '1')
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        try {
            $ok = Product::delete($id);
            if ($ok) {
                $msg = 'Product deleted.';
            } else {
                // Say WHICH reference is blocking it. The remedies differ: an
                // order is a permanent record, a cart line the member can
                // empty. A single "cannot delete" made both look equally
                // permanent.
                $ref = Product::referenceMap([$id]);
                $r   = $ref[$id] ?? ['orders' => 0, 'carts' => 0];
                $msg = match (true) {
                    $r['orders'] > 0 && $r['carts'] > 0
                        => 'Cannot delete: this product has been bought and is in a cart.',
                    $r['orders'] > 0
                        => 'Cannot delete: this product has been bought.',
                    default
                        => 'Cannot delete: this product is in a member\'s cart.',
                };
            }
        } catch (Throwable $e) {
            // Backstop: a constraint violation must never surface as a 500.
            error_log('[shop] deleteShopProduct failed: ' . $e->getMessage());
            $ok = false;
            $msg = 'Cannot delete: this product is still referenced. Remove it from orders and carts first.';
        }

        if ($wantsJson) {
            json_response(['ok' => $ok, 'message' => $msg]);
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect('/?page=admin_shop');
    }

    public function shopOrders(): void
    {
        Auth::guard('admin');
        // Default to the 'All' tab, which is the first tab in the queue strip.
        // Was the top of the order-state sequence (pending), then 'payment_review'
        // before that.
        $status = (string) ($_GET['status'] ?? 'all');
        $orders = ShopOrder::allByStatus($status);
        $counts = ShopOrder::statusCounts();
        $counts['all'] = array_sum($counts);
        // A previous revision also ran a second COUNT(*) here for an
        // "actionable" badge that the view never rendered — an extra query on
        // every page load for nothing (defect M14).
        $pageTitle = 'Shop Orders';
        require 'views/admin/shop_orders.php';
    }

    public function shopOrder(): void
    {
        Auth::guard('admin');
        $id = (int) ($_GET['id'] ?? 0);
        $order = $id ? ShopOrder::findWithItems($id) : null;
        if (!$order) {
            flash('error', 'Order not found.');
            redirect('/?page=admin_shop_orders');
        }
        $orderId       = (int) $order['id'];
        $buyer         = User::find((int) $order['member_id']);
        $events        = ShopOrderEvent::forOrder($orderId);
        $proofs        = ShopPaymentProof::byOrder($orderId);
        $shipments     = ShopShipment::byOrder($orderId);
        $activeShipment = ShopShipment::activeFor($orderId);
        $refunds       = ShopRefund::forOrder($orderId);
        $counts        = ShopOrder::statusCounts();
        $staff         = User::assignableStaff();
        $pageTitle     = 'Order ' . e($order['order_no']);
        require 'views/admin/shop_order.php';
    }

    /**
     * The single transition endpoint (§5.3). Every admin status move is one
     * POST here carrying `to`. A false return means another actor won the
     * race — flash "just changed", never retry silently.
     */
    public function orderTransition(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $to      = (string) ($_POST['to'] ?? '');
        $order   = ShopOrder::find($orderId);
        $back    = '/?page=admin_shop_order&id=' . $orderId;

        if (!$order) {
            flash('error', 'Order not found.');
            redirect('/?page=admin_shop_orders');
        }

        try {
            ShopOrder::assertNotSelfReview($order);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        $ok = false;
        try {
            switch ($to) {
                case 'paid':
                    $ok = ShopOrder::markPaid($orderId, 'admin', Auth::id(),
                        isset($_POST['amount_sent']) ? (float) $_POST['amount_sent'] : null,
                        trim((string) ($_POST['payment_reference'] ?? '')) ?: null);
                    break;

                case 'payment_review':          // paid-revert
                    $ok = ShopOrder::revertPaidToReview($orderId, Auth::id(), trim((string) ($_POST['note'] ?? '')));
                    break;

                case 'payment_failed':
                    $ok = ShopOrder::rejectProof($orderId, Auth::id(),
                        trim((string) ($_POST['reason_code'] ?? 'unclear_proof')),
                        isset($_POST['amount_received']) ? (float) $_POST['amount_received'] : null);
                    break;

                case 'packing':
                    $ok = ShopOrder::startPacking($orderId, Auth::id());
                    break;

                case 'ready_to_ship':
                    $ok = ShopOrder::finishPacking($orderId, Auth::id());
                    break;

                case 'shipped':
                    $ok = ShopOrder::ship($orderId, Auth::id(),
                        trim((string) ($_POST['courier'] ?? '')),
                        trim((string) ($_POST['tracking_number'] ?? '')),
                        ($_POST['expected_delivery_at'] ?? '') !== '' ? $_POST['expected_delivery_at'] : null);
                    break;

                case 'out_for_delivery':
                    $ok = ShopOrder::markOutForDelivery($orderId, 'admin', Auth::id());
                    break;

                case 'delivery_failed':
                    $ok = ShopOrder::markDeliveryFailed($orderId, Auth::id(),
                        (string) ($_POST['fault'] ?? ''), trim((string) ($_POST['fail_reason'] ?? '')));
                    break;

                case 'returned_to_sender':
                    $ok = ShopOrder::returnToSender($orderId, 'admin', Auth::id(), trim((string) ($_POST['note'] ?? '')));
                    break;

                case 'delivered':
                    $podImage = null;
                    if (($_FILES['pod_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        try {
                            $podImage = upload_image($_FILES['pod_image'], 'shipments', 'pod_' . $orderId);
                        } catch (Throwable $e) {
                            flash('error', 'POD image upload failed.');
                            redirect($back);
                        }
                    }
                    $ok = ShopOrder::markDelivered($orderId, Auth::id(),
                        trim((string) ($_POST['pod_receiver'] ?? '')) ?: null, $podImage);
                    break;

                case 'cancelled':
                    $reason = trim((string) ($_POST['reason_code'] ?? 'admin_cancel'));
                    $note   = trim((string) ($_POST['note'] ?? ''));
                    $ok = ShopOrder::cancel($orderId, 'admin', Auth::id(), $reason, $note);
                    if ($ok && in_array($order['status'], ['paid', 'packing', 'ready_to_ship', 'on_hold', 'returned_to_sender'], true)) {
                        ShopRefund::create($orderId, (float) $order['total_price'], 'admin_cancel', null, null, Auth::id());
                    }
                    break;

                case 'on_hold':
                    $ok = ShopOrder::onHold($orderId, Auth::id(),
                        trim((string) ($_POST['hold_reason'] ?? '')),
                        (int) ($_POST['hold_owner_id'] ?? 0),
                        (string) ($_POST['hold_deadline'] ?? ''));
                    break;

default:
                    flash('error', 'Unknown transition target.');
            }
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        if ($ok) {
            flash('success', 'Order moved to ' . shop_status_label($to) . '.');
        } else {
            flash('error', 'This order was changed by someone else. Reload and try again.');
        }
        redirect($back);
    }

    public function orderHold(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $ownerId = (int) ($_POST['hold_owner_id'] ?? 0);

        if (!in_array($ownerId, array_column(User::assignableStaff(), 'id'), true)) {
            flash('error', 'Choose a valid staff owner for the hold.');
            redirect('/?page=admin_shop_order&id=' . $orderId);
        }

        try {
            $ok = ShopOrder::onHold($orderId, Auth::id(),
                trim((string) ($_POST['hold_reason'] ?? '')),
                $ownerId,
                (string) ($_POST['hold_deadline'] ?? ''));
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/?page=admin_shop_order&id=' . $orderId);
        }
        flash($ok ? 'success' : 'error', $ok
            ? 'Order placed on hold.'
            : 'Hold refused — reason, owner, and deadline are all required.');
        redirect('/?page=admin_shop_order&id=' . $orderId);
    }

    public function orderResume(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        try {
            $ok = ShopOrder::resume($orderId, Auth::id());
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/?page=admin_shop_order&id=' . $orderId);
        }
        flash($ok ? 'success' : 'error', $ok
            ? 'Hold released.'
            : 'Resume refused — the previous status is no longer reachable.');
        redirect('/?page=admin_shop_order&id=' . $orderId);
    }

    public function orderReship(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        try {
            $ok = ShopOrder::reship($orderId, Auth::id());
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/?page=admin_shop_order&id=' . $orderId);
        }
        flash($ok ? 'success' : 'error', $ok
            ? 'Reshipment opened — the shop absorbs the cost, no fee charged.'
            : 'Reship refused — the order must be returned_to_sender with no more than one prior reship.');
        redirect('/?page=admin_shop_order&id=' . $orderId);
    }

    /** Streams one payment-proof image through an authenticated handler (§5.11). */
    public function orderProofView(): void
    {
        Auth::guard('admin');

        $proofId  = (int) ($_GET['proof_id'] ?? 0);
        $orderId  = (int) ($_GET['id'] ?? 0);
        $proof    = $proofId ? ShopPaymentProof::findForOrder($proofId, $orderId) : null;
        if (!$proof) {
            http_response_code(404);
            exit('Proof not found');
        }

        ShopOrderEvent::log($orderId, null, ShopOrder::find($orderId)['status'], 'admin', Auth::id(), [
            'reason_code' => 'proof_viewed',
            'note'        => 'Proof #' . $proofId . ' viewed',
            'source'      => 'ui',
        ]);

        $path = dirname(__DIR__) . '/uploads/' . ltrim((string) $proof['proof_image'], '/\\');
        if (!is_file($path)) {
            http_response_code(404);
            exit('Proof file missing');
        }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    /** Printable sales invoice for an order — the buyer's proof of purchase. */
    public function orderInvoice(): void
    {
        Auth::guard('admin');

        $orderId = (int) ($_GET['id'] ?? 0);
        $order   = $orderId ? ShopOrder::find($orderId) : null;
        if (!$order) {
            flash('error', 'Order not found.');
            redirect('/?page=admin_shop_orders');
        }

        $doc = ShopInvoice::docForOrder((int) $order['id']);
        if (!$doc) {
            flash('error', 'Invoice not found.');
            redirect('/?page=admin_shop_order&id=' . (int) $order['id']);
        }
        require 'views/shop/invoice.php';
    }

    /** Printable credit note / refund receipt for a refund task. */
    public function orderRefundReceipt(): void
    {
        Auth::guard('admin');

        $refundId = (int) ($_GET['refund_id'] ?? 0);
        $refund   = $refundId ? ShopRefund::find($refundId) : null;
        if (!$refund) {
            http_response_code(404);
            exit('Refund not found');
        }

        $order  = ShopOrder::findWithItems((int) $refund['order_id']);
        $buyer  = User::find((int) $refund['member_id']);
        $seller = shop_seller_identity();

        ShopOrderEvent::log((int) $refund['order_id'], null, (string) $order['status'], 'admin', Auth::id(), [
            'reason_code' => 'refund_receipt_viewed',
            'note'        => 'Credit note ' . (string) $refund['receipt_ref'] . ' viewed',
            'source'      => 'ui',
        ]);

        require 'views/shop/refund_receipt.php';
    }

    /** Manual shipment facts edit — writes the ACTIVE shipment only (§4.2). */
    public function orderShipment(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $back    = '/?page=admin_shop_order&id=' . $orderId;

        // Same segregation of duties as the other order endpoints: an admin may
        // not edit the shipment on an order they placed themselves (defect H6).
        $order = ShopOrder::find($orderId);
        if (!$order) {
            flash('error', 'Order not found.');
            redirect('/?page=admin_shop_orders');
        }
        try {
            ShopOrder::assertNotSelfReview($order);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        $s = ShopShipment::activeFor($orderId);
        if (!$s) {
            flash('error', 'No active shipment to edit. Tracking is set at handoff.');
            redirect($back);
        }

        $data = [];
        foreach (['courier', 'tracking_number', 'expected_delivery_at'] as $k) {
            if (isset($_POST[$k])) $data[$k] = trim((string) $_POST[$k]) ?: null;
        }
        if (($_FILES['pod_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $data['pod_image'] = upload_image($_FILES['pod_image'], 'shipments', 'pod_' . $orderId);
            } catch (Throwable $e) {
                flash('error', 'POD image upload failed.');
                redirect($back);
            }
        }
        ShopShipment::touch((int) $s['id'], $data);
        flash('success', 'Shipment updated.');
        redirect($back);
    }

    public function orderRefund(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $back    = '/?page=admin_shop_order&id=' . $orderId;
        $action  = (string) ($_POST['refund_action'] ?? '');

        try {
            switch ($action) {
                case 'create':
                    $amount = (float) ($_POST['amount'] ?? 0);
                    if ($amount <= 0) {
                        flash('error', 'Refund amount must be above zero.');
                        redirect($back);
                    }
                    ShopRefund::create($orderId, $amount,
                        (string) ($_POST['cause'] ?? 'admin_cancel'),
                        trim((string) ($_POST['destination'] ?? '')) ?: null,
                        ($_POST['promised_at'] ?? '') ?: null,
                        Auth::id(),
                        // The payout route is explicit (Phase 6 / M6). It used to
                        // be inferred from `destination`, which the admin form
                        // still collected — so an admin typing "gcash" got a
                        // wallet credit once the inference was removed, because
                        // nothing sent `method` and the column default took over.
                        (string) ($_POST['method'] ?? 'ewallet'));
                    flash('success', 'Refund task opened.');
                    break;

                case 'approve':
                    ShopRefund::approve((int) ($_POST['refund_id'] ?? 0), Auth::id())
                        ? flash('success', 'Refund approved.')
                        : flash('error', 'Refund could not be approved.');
                    break;

                case 'mark_paid':
                    ShopRefund::markPaid((int) ($_POST['refund_id'] ?? 0), Auth::id(),
                        trim((string) ($_POST['external_ref'] ?? '')))
                        ? flash('success', 'Refund marked paid.')
                        : flash('error', 'Refund must be approved before it can be marked paid.');
                    break;

                case 'deny':
                    ShopRefund::deny((int) ($_POST['refund_id'] ?? 0), Auth::id(), trim((string) ($_POST['note'] ?? '')))
                        ? flash('success', 'Refund denied.')
                        : flash('error', 'Refund could not be denied.');
                    break;

                default:
                    flash('error', 'Unknown refund action.');
            }
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            // Backstop. A schema or constraint violation must never surface as
            // a 500 from this endpoint.
            error_log('[shop] orderRefund failed: ' . $e->getMessage());
            flash('error', 'The refund task could not be saved. Please try again.');
        }
        redirect($back);
    }

    /** Mirrors admin_manual_reset: cron runs on schedule, but there is a button. */
    public function shopExpire(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $expired  = ShopOrder::expireOverdue(200);
        $finished = ShopOrder::autoComplete(200);
        flash('success', "Expiry run: {$expired} order(s) cancelled, {$finished} auto-completed.");
        redirect('/?page=admin_shop_orders');
    }
}
