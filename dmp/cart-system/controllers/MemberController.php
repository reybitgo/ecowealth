<?php

/**
 * @file   controllers/MemberController.php
 * @brief  Member controller for handling member-specific actions
 */
class MemberController
{
    public function dashboard(): void
    {
        Auth::guard('member');
        $user    = Auth::user();
        $wallet  = Auth::actingUser();
        $summary = Commission::summary($user['id']);
        $status  = User::todayPairingStatus($user['id']);
        $recent  = Commission::recent($user['id'], 8);
        require 'views/member/dashboard.php';
    }

    public function profile(): void
    {
        Auth::guard('member');
        $user      = Auth::user();
        $addresses = MemberAddress::forMember(Auth::id());
        require 'views/member/profile.php';
    }

    public function saveProfile(): void
    {
        Auth::guard('member');
        csrf_verify();
        $id   = Auth::id();
        $user = Auth::user();

        $data = [
            'full_name'          => trim($_POST['full_name']            ?? ''),
            'email'              => trim($_POST['email']                ?? ''),
            'mobile'             => trim($_POST['mobile']               ?? ''),
            'gcash_number'       => trim($_POST['gcash_number']         ?? ''),
            'maya_number'        => trim($_POST['maya_number']          ?? ''),
            'usdt_trc20_address' => trim($_POST['usdt_trc20_address']   ?? ''),
            'usdt_bep20_address' => trim($_POST['usdt_bep20_address']   ?? ''),
            'address'            => trim($_POST['address']              ?? ''),
        ];
        $profileRedirect = Auth::isAdmin() ? '/?page=admin_settings#tabPane-password' : '/?page=profile';

        // Handle photo upload
        if (!empty($_FILES['photo']['tmp_name'])) {
            $file = $_FILES['photo'];

            // Verify MIME type
            $mime    = mime_content_type($file['tmp_name']);
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($mime, $allowed)) {
                flash('error', 'Photo must be JPEG, PNG, or WebP.');
                redirect('/?page=profile');
            }
            if ($file['size'] > 5 * 1024 * 1024) { // 5 MB — phone photos can be large
                flash('error', 'Photo must be under 5MB.');
                redirect('/?page=profile');
            }

            // Use absolute path so it works regardless of PHP's working directory.
            // dirname(__DIR__) = the project root (parent of /controllers/)
            $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;

            // Create directory if missing
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $name = 'photo_' . $id . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $name;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                flash('error', 'Failed to save photo. Check that the uploads/ folder exists and is writable.');
                redirect('/?page=profile');
            }

            // Delete old photo file if it exists
            if (!empty($user['photo'])) {
                $old = $uploadDir . $user['photo'];
                if (file_exists($old)) @unlink($old);
            }

            $data['photo'] = $name;
        }

        // Password change (optional)
        $newPw = $_POST['new_password'] ?? '';
        if ($newPw) {
            if (strlen($newPw) < 8) {
                flash('error', 'New password must be at least 8 characters.');
                redirect($profileRedirect);
            }
            if (!User::verifyPassword($id, $_POST['current_password'] ?? '')) {
                flash('error', 'Current password is incorrect.');
                redirect($profileRedirect);
            }
            if ($newPw !== ($_POST['new_password_confirm'] ?? '')) {
                flash('error', 'New passwords do not match.');
                redirect($profileRedirect);
            }
            User::updatePassword($id, $newPw);
        }

        User::updateProfile($id, $data);
        flash('success', 'Profile updated successfully.');
        redirect($profileRedirect);
    }

    /** Read the posted address-book fields (create or edit). */
    private function collectAddressInput(): array
    {
        return [
            'label'          => trim((string) ($_POST['label'] ?? '')),
            'recipient_name' => trim((string) ($_POST['recipient_name'] ?? '')),
            'phone'          => trim((string) ($_POST['phone'] ?? '')),
            'address_line'   => trim((string) ($_POST['address_line'] ?? '')),
            'barangay'       => trim((string) ($_POST['barangay'] ?? '')),
            'city'           => trim((string) ($_POST['city'] ?? '')),
            'province'       => trim((string) ($_POST['province'] ?? '')),
            'region'         => trim((string) ($_POST['region'] ?? '')),
            'postal'         => trim((string) ($_POST['postal'] ?? '')),
            'country'        => trim((string) ($_POST['country'] ?? 'PH')),
            'region_code'    => trim((string) ($_POST['region_code'] ?? '')),
            'province_code'  => trim((string) ($_POST['province_code'] ?? '')),
            'city_code'      => trim((string) ($_POST['city_code'] ?? '')),
            'barangay_code'  => trim((string) ($_POST['barangay_code'] ?? '')),
            'billing_name'   => trim((string) ($_POST['billing_name'] ?? '')),
            'billing_phone'  => trim((string) ($_POST['billing_phone'] ?? '')),
            'notes_member'   => trim((string) ($_POST['notes_member'] ?? '')),
            'is_default'     => ($_POST['is_default'] ?? '') === '1',
        ];
    }

    public function saveAddress(): void
    {
        Auth::guard('member');
        csrf_verify();
        $uid  = Auth::id();
        $id   = (int) ($_POST['address_id'] ?? 0);
        $data = $this->collectAddressInput();

        $missing = [];
        if ($data['recipient_name'] === '') $missing[] = 'recipient name';
        if ($data['address_line'] === '')   $missing[] = 'address';
        if ($missing) {
            flash('error', 'Please fill in the ' . implode(', ', $missing) . '.');
            redirect('/?page=profile#addresses');
        }

        if ($id > 0) {
            if (!MemberAddress::find($id, $uid)) {
                flash('error', 'Address not found.');
                redirect('/?page=profile#addresses');
            }
            MemberAddress::update($id, $uid, $data);
            flash('success', 'Delivery address updated.');
        } else {
            if (MemberAddress::countForMember($uid) >= MemberAddress::MAX_ADDRESSES) {
                flash('error', 'You can save up to ' . MemberAddress::MAX_ADDRESSES . ' addresses.');
                redirect('/?page=profile#addresses');
            }
            MemberAddress::create($uid, $data);
            flash('success', 'Delivery address saved.');
        }
        redirect('/?page=profile#addresses');
    }

    public function deleteAddress(): void
    {
        Auth::guard('member');
        csrf_verify();
        $id = (int) ($_POST['address_id'] ?? 0);
        if ($id <= 0 || !MemberAddress::delete($id, Auth::id())) {
            flash('error', 'Address not found.');
        } else {
            flash('success', 'Delivery address removed.');
        }
        redirect('/?page=profile#addresses');
    }

    public function setDefaultAddress(): void
    {
        Auth::guard('member');
        csrf_verify();
        $id = (int) ($_POST['address_id'] ?? 0);
        if ($id <= 0 || !MemberAddress::setDefault($id, Auth::id())) {
            flash('error', 'Address not found.');
        } else {
            flash('success', 'Default delivery address updated.');
        }
        redirect('/?page=profile#addresses');
    }

    public function earnings(): void
    {
        Auth::guard('member');
        $userId  = Auth::id();
        $type    = $_GET['type'] ?? '';
        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $perPage = max(5, (int)($_GET['per_page'] ?? 10));
        $summary = Commission::summary($userId);
        $history = Commission::history($userId, $page, $perPage, $type);
        $cdStatus = CdStatus::getActive($userId);
        $cdHistory = CdStatus::history($userId);
        $cdLedger = [];
        if ($cdStatus) {
            $cdLedger = CdStatus::ledger($userId, (int)$cdStatus['id']);
        } elseif (!empty($cdHistory)) {
            $lastCd = $cdHistory[0];
            if ($lastCd['status'] !== 'cancelled') {
                $cdLedger = CdStatus::ledger($userId, (int)$lastCd['id']);
            }
        }
        require 'views/member/earnings.php';
    }

    /**
     * Resolve the root member used to render the binary tree.
     *
     * Staff accounts (admin/superadmin) mirror each other: without an explicit
     * root they see the top-most node of the binary placement tree (the whole
     * network). That top node is a real user — normally a member, but it can
     * be a staff account members were placed under — so it is found by walking
     * the placement tree, not by role. All other users — including
     * impersonated sessions — see their own subtree.
     *
     * @param int|null $requestedRoot  Explicit ?root= value, or null for default.
     */
    private static function resolveBinaryRoot(?int $requestedRoot = null): int
    {
        if ($requestedRoot !== null && $requestedRoot > 0) {
            return $requestedRoot;
        }

        if (Auth::isAdmin()) {
            $top = db()->query(
                "SELECT u.id FROM users u
                 WHERE u.binary_parent_id IS NULL
                   AND EXISTS (
                       SELECT 1 FROM users c WHERE c.binary_parent_id = u.id
                   )
                 ORDER BY u.id ASC LIMIT 1"
            )->fetchColumn();
            if ($top) {
                return (int) $top;
            }
        }

        return Auth::id();
    }

    /**
     * Resolve the root user used to render the referral network.
     *
     * Mirrors resolveBinaryRoot(): staff accounts (admin/superadmin) see the
     * same referral network as each other — the top-most sponsor (the user the
     * whole sponsor tree hangs from, which may itself be a staff account).
     * Other users — including impersonated sessions — see their own tree.
     *
     * @param int|null $requestedRoot  Explicit ?root= value, or null for default.
     */
    private static function resolveReferralRoot(?int $requestedRoot = null): int
    {
        if ($requestedRoot !== null && $requestedRoot > 0) {
            return $requestedRoot;
        }

        if (Auth::isAdmin()) {
            $top = db()->query(
                "SELECT u.id FROM users u
                 WHERE u.sponsor_id IS NULL
                   AND EXISTS (
                       SELECT 1 FROM users c WHERE c.sponsor_id = u.id
                   )
                 ORDER BY u.id ASC LIMIT 1"
            )->fetchColumn();
            if ($top) {
                return (int) $top;
            }
        }

        return Auth::id();
    }

    public function genealogy(): void
    {
        Auth::guard('member');
        $user     = Auth::user();
        $view     = $_GET['view'] ?? 'binary'; // 'binary' | 'referral'
        $packages = Package::all(true);
        // Mirror the register page's default-method logic in the modal: an
        // e-wallet is the default paid method only when it can cover the
        // cheapest active package.
        $canUseEwallet = false;
        if (!empty($packages)) {
            $minFee = min(array_map(fn($p) => (float)$p['entry_fee'], $packages));
            $canUseEwallet = Ewallet::balance(Auth::actingAdminId()) >= $minFee;
        }
        // Same gate as ?page=register: no placement when packages are disabled
        // or the Binary Pairing compensation is off (master or per-type).
        $binaryUiEnabled = binary_placement_ui_enabled($packages);
        $allPlans       = User::isPrimaryAdmin((int)$user['id']) || Auth::isSuperadmin();
        $pairingEnabled = $allPlans || Package::hasPairing((int)$user['package_id']);
        $showIndirect   = $allPlans || Package::hasIndirectReferral((int)$user['package_id']);
        $binaryRootId   = self::resolveBinaryRoot();
        $referralRootId = self::resolveReferralRoot(isset($_GET['root']) ? (int)$_GET['root'] : null);

        // Admins may always view the binary tree; non-binary members never see it
        if ($view === 'binary' && !$pairingEnabled && !Auth::isAdmin()) {
            redirect('/?page=genealogy&view=referral');
        }

        $indirect = [];
        $direct   = [];
        if ($view === 'referral') {
            if ($showIndirect) {
                $indirect = User::indirectReferralTree($referralRootId);
            } else {
                $page    = max(1, (int)($_GET['pg'] ?? 1));
                $perPage = max(5, (int)($_GET['per_page'] ?? 10));
                $direct  = User::directReferrals($referralRootId, $page, $perPage);
            }
        }
        require 'views/member/genealogy.php';
    }

    public function apiBinaryTree(): void
    {
        Auth::guard('member');
        $user = Auth::user();

        // Mirror genealogy(): binary tree data is only for binary accounts (or admins)
        if (!Package::hasPairing((int)($user['package_id'] ?? 0)) && !Auth::isAdmin()) {
            json_response(['ok' => false, 'error' => 'Binary tree is not available for your account.']);
            return;
        }

        $rootId = self::resolveBinaryRoot(isset($_GET['root']) ? (int)$_GET['root'] : null);
        $depth  = min(4, max(1, (int)($_GET['depth'] ?? 3)));
        json_response(self::buildTreeNode($rootId, $depth));
    }

    private static function buildTreeNode(int $id, int $depth): array
    {
        $u = User::find($id);
        if (!$u) return [];

        $node = [
            'id'          => (int)$u['id'],
            'username'    => $u['username'],
            'full_name'   => $u['full_name'] ?: $u['username'],
            'status'      => $u['status'],
            'package'     => $u['package_name'] ?? '—',
            'joined'      => fmt_date($u['joined_at']),
            'left_count'  => (int)$u['left_count'],
            'right_count' => (int)$u['right_count'],
            'cd_active'   => !empty($u['cd_active']),
            'left'        => null,
            'right'       => null,
            'hasMore'     => false,
        ];

        $pdo = db();

        if ($depth > 0) {
            $st  = $pdo->prepare(
                "SELECT id FROM users WHERE binary_parent_id = ? AND binary_position = ?"
            );
            $st->execute([$id, 'left']);
            $lc = $st->fetchColumn();
            if ($lc) $node['left'] = self::buildTreeNode((int)$lc, $depth - 1);

            $st->execute([$id, 'right']);
            $rc = $st->fetchColumn();
            if ($rc) $node['right'] = self::buildTreeNode((int)$rc, $depth - 1);
        } else {
            // At max depth — flag if this node has deeper children not loaded yet
            $hasChildren = (bool) $pdo->query(
                "SELECT 1 FROM users WHERE binary_parent_id = {$id} LIMIT 1"
            )->fetchColumn();
            $node['hasMore'] = $hasChildren;
        }

        return $node;
    }

    public function payout(): void
    {
        Auth::guard('member');
        $userId  = Auth::id();
        $user    = Auth::user();
        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $perPage = max(5, (int)($_GET['per_page'] ?? 10));
        $history = Payout::forUser($userId, $page, $perPage);
        require 'views/member/payout.php';
    }

    public function requestPayout(): void
    {
        Auth::guard('member');
        csrf_verify();

        $amount   = (float)($_POST['amount']         ?? 0);
        $method   = trim($_POST['payout_method']     ?? 'gcash');
        $account  = trim($_POST['payout_account']    ?? '');
        $usdtRate = (float)($_POST['usdt_trc20_rate'] ?? $_POST['usdt_bep20_rate'] ?? 0);

        $allowed = ['gcash', 'maya', 'usdt_trc20', 'usdt_bep20'];
        if (!in_array($method, $allowed)) {
            flash('error', 'Invalid payout method.');
            redirect('/?page=payout');
        }

        if (!$account) {
            flash('error', 'Please enter your payout account details.');
            redirect('/?page=payout');
        }

        $result = Payout::request(Auth::id(), $amount, $method, $account, $usdtRate);
        if ($result['ok']) {
            flash('success', 'Payout request submitted. Admin will process it shortly.');
        } else {
            flash('error', $result['error']);
        }
        redirect('/?page=payout');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  PHASE 3: DAILY FIXED INCOME (DFI)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * JSON endpoint for DFI widget data.
     */
    public function apiDfiStatus(): void
    {
        Auth::guard('member');
        json_response(DailyFixedIncome::getMemberDFIStatus(Auth::id()));
    }

    /**
     * DFI payout history page.
     */
    public function dfiHistory(): void
    {
        Auth::guard('member');
        $userId  = Auth::id();
        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $perPage = max(5, (int)($_GET['per_page'] ?? 10));
        $history = DailyFixedIncome::getDFIHistory($userId, $page, $perPage);
        $status  = DailyFixedIncome::getMemberDFIStatus($userId);

        // Fetch all DFI records for calendar view (grouped by date)
        $calendarRaw = db()->prepare("
            SELECT DATE(created_at) AS day, amount, cap_status_at_payout
            FROM daily_fixed_income_log
            WHERE user_id = ?
            ORDER BY created_at ASC
        ");
        $calendarRaw->execute([$userId]);
        $calendarData = [];
        foreach ($calendarRaw->fetchAll() as $row) {
            $ym = date('Y-m', strtotime($row['day']));
            $d  = (int)date('j', strtotime($row['day']));
            $calendarData[$ym][$d] = [
                'amount' => (float)$row['amount'],
                'cap_status' => $row['cap_status_at_payout'],
            ];
        }

        require 'views/member/dfi_history.php';
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  PHASE 3/5: CAP STATUS MONITORING
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Cap status detail page.
     */
    public function capStatus(): void
    {
        Auth::guard('member');
        $userId    = Auth::id();
        $capStatus = User::getCapStatus($userId);
        $summary   = Commission::summary($userId);
        $user      = User::find($userId);
        require 'views/member/cap_status.php';
    }

    /**
     * JSON endpoint for cap widget data.
     */
    public function apiCapStatus(): void
    {
        Auth::guard('member');
        $userId    = Auth::id();
        $capStatus = User::getCapStatus($userId);
        $summary   = Commission::summary($userId);

        json_response([
            'lifetime_earned'  => $capStatus['lifetime_earned'],
            'lifetime_cap'     => $capStatus['lifetime_cap'],
            'remaining'        => $capStatus['remaining'],
            'cap_status'       => $capStatus['cap_status'],
            'capped_at'        => $capStatus['capped_at'],
            'total_pairing'    => (float)$summary['total_pairing'],
            'total_direct'     => (float)$summary['total_direct'],
            'total_indirect'   => (float)$summary['total_indirect'],
            'total_cap_blocked' => (float)$summary['total_cap_blocked'],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  PHASE 4: REACTIVATION (stubs — full UI in Phase 4)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Reactivation page.
     */
    public function reactivate(): void
    {
        Auth::guard('member');
        $userId  = Auth::id();
        $request = Reactivation::requestReactivation($userId);

        if (!$request['ok']) {
            flash('error', $request['error']);
            redirect('/?page=dashboard');
        }

        $capStatus = User::getCapStatus($userId);

        // Fetch admin payment details for external payment display (from settings)
        $admin = [];
        foreach (['gcash_number', 'maya_number', 'usdt_trc20_address', 'usdt_bep20_address'] as $k) {
            $admin[$k] = db()->query("SELECT value FROM settings WHERE key_name='{$k}'")->fetchColumn() ?: '';
        }

        require 'views/member/reactivate.php';
    }

    /**
     * Process reactivation request.
     */
    public function doReactivate(): void
    {
        Auth::guard('member');
        csrf_verify();

        $userId        = Auth::id();
        $paymentMethod = trim($_POST['payment_method'] ?? 'ewallet');
        $proofPath     = '';

        // Handle proof image upload for external payments
        if ($paymentMethod !== 'ewallet' && !empty($_FILES['proof_image']['tmp_name'])) {
            $file = $_FILES['proof_image'];
            $mime = mime_content_type($file['tmp_name']);
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($mime, $allowed)) {
                flash('error', 'Proof must be an image (JPEG, PNG, GIF, WebP).');
                redirect('/?page=reactivate');
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                flash('error', 'Image must be under 5MB.');
                redirect('/?page=reactivate');
            }

            $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'reactivation_proofs' . DIRECTORY_SEPARATOR;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
            $name = 'reactivation_' . $userId . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $name;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                flash('error', 'Failed to save proof image. Check uploads folder permissions.');
                redirect('/?page=reactivate');
            }

            $proofPath = 'reactivation_proofs/' . $name;
        }

        $result = Reactivation::processReactivation($userId, $paymentMethod, $proofPath);

        if ($result['ok']) {
            if (!empty($result['pending'])) {
                flash('info', $result['message']);
            } else {
                flash('success', $result['message']);
            }
        } else {
            flash('error', $result['error'] ?? 'Reactivation failed.');
        }

        redirect('/?page=dashboard');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ACTIVATION (for pending referral-link accounts)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Show activation page for pending accounts.
     */
    public function activate(): void
    {
        Auth::guard('member');
        $user = Auth::user();
        if (($user['reg_payment_method'] ?? '') === 'standard') {
            flash('info', 'Standard accounts cannot be activated or upgraded.');
            redirect('/?page=dashboard');
        }

        if ($user['status'] !== 'pending') {
            flash('info', 'Your account is already active.');
            redirect('/?page=dashboard');
        }

        $binaryPlaced = $user['binary_parent_id'] !== null && $user['binary_position'] !== null;

        $packages = Package::all(true);
        // A member with a reserved binary slot must activate with a binary
        // (pairing-enabled) package — non-binary packages are barred.
        if ($binaryPlaced) {
            $packages = array_values(array_filter($packages, fn($p) => (int)$p['pairing_enabled'] === 1));
        }

        $canUseEwallet = false;
        $minFee = 0.0;
        if (!empty($packages)) {
            $minFee = min(array_map(fn($p) => (float)$p['entry_fee'], $packages));
            $canUseEwallet = Ewallet::balance($user['id']) >= $minFee;
        }

        require 'views/member/activate.php';
    }

    /**
     * Process activation (code or e-wallet).
     */
    public function doActivate(): void
    {
        Auth::guard('member');
        csrf_verify();

        $user   = Auth::user();
        $userId = (int)$user['id'];

        if ($user['status'] !== 'pending') {
            flash('error', 'Your account is already active.');
            redirect('/?page=dashboard');
        }

        // Reserved binary slot must be honored: activation is limited to binary
        // (pairing-enabled) packages for members placed at registration.
        $placedInBinary = $user['binary_parent_id'] !== null && $user['binary_position'] !== null;

        $paymentMethod = $_POST['payment_method'] ?? 'code';
        $code          = strtoupper(trim($_POST['reg_code'] ?? ''));
        $packageId     = (int)($_POST['package_id'] ?? 0);

        $regCodeId = null;

        if ($paymentMethod === 'code') {
            if (empty($code)) {
                flash('error', 'Registration code is required.');
                redirect('/?page=activate');
            }
            $codeRow = Code::validate($code);
            if (!$codeRow) {
                flash('error', 'Invalid or already-used registration code.');
                redirect('/?page=activate');
            }
            if ($placedInBinary && !Package::hasPairing((int)$codeRow['package_id'])) {
                flash('error', 'This code is for a non-binary package. Your binary position is reserved, so activate with a binary package code (e.g. Starter).');
                redirect('/?page=activate');
            }
            $packageId = (int)$codeRow['package_id'];
            $regCodeId = (int)$codeRow['id'];
        } else {
            // E-Wallet
            if ($packageId <= 0) {
                flash('error', 'Please select a package.');
                redirect('/?page=activate');
            }
            $pkg = Package::find($packageId);
            if (!$pkg) {
                flash('error', 'Invalid package selected.');
                redirect('/?page=activate');
            }
            if ($placedInBinary && !Package::hasPairing($packageId)) {
                flash('error', 'Non-binary packages are not allowed. Your binary position is reserved — choose a binary package (e.g. Starter).');
                redirect('/?page=activate');
            }
            $entryFee = (float)$pkg['entry_fee'];
            $bal = Ewallet::balance($userId);
            if ($bal < $entryFee) {
                flash('error', 'Insufficient e-wallet balance. Required: ' . fmt_money($entryFee));
                redirect('/?page=activate');
            }

            // Debit user
            $debitOk = Ewallet::debitInternal(
                $userId,
                $entryFee,
                0,
                'registration',
                "Activation fee for @" . $user['username']
            );
            if (!$debitOk) {
                flash('error', 'E-wallet debit failed.');
                redirect('/?page=activate');
            }

            // Credit admin
            $adminId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")
                ->fetchColumn();
            if ($adminId) {
                Ewallet::credit(
                    $adminId,
                    $entryFee,
                    $userId,
                    'registration',
                    "Activation fee from @" . $user['username']
                );
            }
        }

        // ── Binary placement resolution ──
        // Activation may reuse a slot already reserved at registration, place
        // the member now (Auto), use a member-picked upline (Manual), or leave
        // the member as an unplaced binary network root when no slot exists.
        $binaryParentId = null;
        $binaryPosition = null;
        $pairingActivation = Package::hasPairing($packageId);
        if ($pairingActivation && $user['binary_parent_id'] === null) {
            $pairingMode = $_POST['binary_mode'] ?? 'auto';
            if ($pairingMode === 'manual') {
                $upU = trim($_POST['upline_username'] ?? '');
                $pos = trim($_POST['binary_position'] ?? '');
                $upline = User::findByUsername($upU);
                if (!$upline || !User::isValidBinaryUpline((int)$upline['id'])) {
                    flash('error', 'Invalid binary upline selected.');
                    redirect('/?page=activate');
                }
                if ((int)$upline['id'] === $userId) {
                    flash('error', 'You cannot place yourself under yourself.');
                    redirect('/?page=activate');
                }
                if (!in_array($pos, ['left', 'right'], true)) {
                    flash('error', 'Invalid binary position.');
                    redirect('/?page=activate');
                }
                if (!User::isSlotFree((int)$upline['id'], $pos)) {
                    flash('error', 'That binary position is already taken.');
                    redirect('/?page=activate');
                }
                $binaryParentId = (int)$upline['id'];
                $binaryPosition = $pos;
            } else {
                // Auto / default — no available slot means the member becomes a
                // binary network root (binary_parent_id stays NULL).
                $auto = User::findNextBinarySlotNetworkWide($userId);
                if ($auto) {
                    $binaryParentId = (int)$auto['upline_id'];
                    $binaryPosition = $auto['position'];
                }
            }
        }

        try {
            User::activate($userId, $packageId, $regCodeId, $paymentMethod, $binaryParentId, $binaryPosition);

            // Mark code as used
            if ($regCodeId) {
                db()->prepare("UPDATE reg_codes SET status = 'used', used_by = ?, used_at = NOW() WHERE id = ?")
                    ->execute([$userId, $regCodeId]);
            }

            flash('success', '🎉 Account activated! You can now start earning commissions.');
        } catch (\Exception $e) {
            flash('error', 'Activation failed: ' . $e->getMessage());
        }

        redirect('/?page=dashboard');
    }

    // ── Package Upgrade ─────────────────────────────────────────────────────

    public function showUpgrade(): void
    {
        $user    = $this->guardUpgradeAccount();
        $current = Package::find((int)$user['package_id']);
        if (!$current) {
            flash('error', 'Your current package is no longer available. Please contact support.');
            redirect('/?page=dashboard');
        }

        $curFee       = (float)$current['entry_fee'];
        $currentPlans = Package::plans((int)$current['id']);
        $curPairing   = (bool)$currentPlans['binary'];
        $binaryPlacementPreserved = $user['binary_parent_id'] !== null
            && in_array((string)$user['binary_position'], ['left', 'right'], true);
        $targets      = [];

        foreach (Package::all(true) as $pkg) {
            $packageId = (int)$pkg['id'];
            if ($packageId === (int)$user['package_id']
                || (float)$pkg['entry_fee'] <= $curFee
                || !Package::upgradeCompatible((int)$user['package_id'], $packageId)
            ) {
                continue;
            }
            $pkg['diff'] = (float)$pkg['entry_fee'] - $curFee;
            $pkg['plans'] = Package::plans($packageId);
            $targets[] = $pkg;
        }

        $oldState = $_SESSION['upgrade_form_state'] ?? [];
        unset($_SESSION['upgrade_form_state']);

        $targetIds = array_map(fn($pkg) => (int)$pkg['id'], $targets);
        $selectedPackageId = (int)($oldState['package_id'] ?? 0);
        if (!in_array($selectedPackageId, $targetIds, true)) {
            $selectedPackageId = (int)($targets[0]['id'] ?? 0);
        }

        $selectedTarget = null;
        foreach ($targets as $pkg) {
            if ((int)$pkg['id'] === $selectedPackageId) {
                $selectedTarget = $pkg;
                break;
            }
        }

        $balance = Ewallet::balance((int)$user['id']);
        $selectedPaymentMethod = in_array($oldState['payment_method'] ?? '', ['code', 'ewallet'], true)
            ? (string)$oldState['payment_method']
            : 'code';
        if ($balance + 0.005 >= (float)($selectedTarget['diff'] ?? 0)) {
            $selectedPaymentMethod = $selectedPaymentMethod === 'code' && empty($oldState['payment_method'])
                ? 'ewallet'
                : $selectedPaymentMethod;
        } elseif ($selectedPaymentMethod === 'ewallet') {
            $selectedPaymentMethod = 'code';
        }

        $selectedUpline = null;
        $selectedUplinePosition = '';
        $oldUplineId = (int)($oldState['binary_upline_id'] ?? 0);
        $selectedTargetPairing = (bool)($selectedTarget['plans']['binary'] ?? false);
        if (!$curPairing && $selectedTargetPairing && !$binaryPlacementPreserved && $oldUplineId > 0) {
            $candidate = User::find($oldUplineId);
            if ($candidate
                && (int)$candidate['id'] !== (int)$user['id']
                && User::isValidBinaryUpline($oldUplineId)
            ) {
                $leftFree = User::isSlotFree($oldUplineId, 'left');
                $rightFree = User::isSlotFree($oldUplineId, 'right');
                if ($leftFree || $rightFree) {
                    $candidatePackage = Package::find((int)$candidate['package_id']);
                    $selectedUpline = [
                        'id' => $oldUplineId,
                        'username' => (string)$candidate['username'],
                        'full_name' => (string)($candidate['full_name'] ?: $candidate['username']),
                        'package' => (string)($candidatePackage['name'] ?? 'Binary member'),
                        'left_free' => $leftFree,
                        'right_free' => $rightFree,
                    ];
                    $savedPosition = (string)($oldState['binary_position'] ?? '');
                    if (($savedPosition === 'left' && $leftFree) || ($savedPosition === 'right' && $rightFree)) {
                        $selectedUplinePosition = $savedPosition;
                    }
                }
            }
        }

        require 'views/member/upgrade.php';
    }

    public function doUpgrade(): void
    {
        $user   = $this->guardUpgradeAccount();
        csrf_verify();

        $userId        = (int)$user['id'];
        $paymentMethod = (string)($_POST['payment_method'] ?? '');
        $code          = strtoupper(trim((string)($_POST['upgrade_code'] ?? '')));
        $newPackageId  = (int)($_POST['package_id'] ?? 0);

        if (!in_array($paymentMethod, ['code', 'ewallet'], true)) {
            $this->redirectUpgradeWithError('Please select a valid payment method.');
        }
        if ($newPackageId <= 0) {
            $this->redirectUpgradeWithError('Please select an upgrade package.');
        }
        if ($paymentMethod === 'code' && $code === '') {
            $this->redirectUpgradeWithError('Upgrade code is required.');
        }

        $curPkg = Package::find((int)$user['package_id']);
        $targetPkg = Package::find($newPackageId);
        if (!$curPkg || !$targetPkg) {
            $this->redirectUpgradeWithError('Invalid package selection.');
        }
        if ((string)$targetPkg['status'] !== 'active') {
            $this->redirectUpgradeWithError('The selected package is no longer available.');
        }

        $diff = (float)$targetPkg['entry_fee'] - (float)$curPkg['entry_fee'];
        if ($diff <= 0) {
            $this->redirectUpgradeWithError('Choose a package with a higher entry fee.');
        }
        if (!Package::upgradeCompatible((int)$user['package_id'], $newPackageId)) {
            $this->redirectUpgradeWithError('This package does not preserve all of your current benefits.');
        }

        $upgradeCodeId = null;
        if ($paymentMethod === 'code') {
            $codeRow = Code::validate($code);
            if (!$codeRow || (string)($codeRow['code_type'] ?? 'registration') !== 'upgrade') {
                $this->redirectUpgradeWithError('Invalid, expired, or already-used upgrade code.');
            }
            if ((int)$codeRow['package_id'] !== $newPackageId) {
                $codePackage = Package::find((int)$codeRow['package_id']);
                $this->redirectUpgradeWithError(
                    'This code is for ' . ($codePackage['name'] ?? 'another package') . ', not the selected package.'
                );
            }
            $upgradeCodeId = (int)$codeRow['id'];
        } elseif (Ewallet::balance($userId) + 0.005 < $diff) {
            $this->redirectUpgradeWithError('Insufficient e-wallet balance. Required: ' . fmt_money($diff));
        }

        $binaryParentId = null;
        $binaryPosition = null;
        $curPairing = Package::hasPairing((int)$user['package_id']);
        $newPairing = Package::hasPairing($newPackageId);
        $hasExistingPlacement = !$curPairing
            && $newPairing
            && $user['binary_parent_id'] !== null
            && in_array((string)$user['binary_position'], ['left', 'right'], true);
        if (!$curPairing && $newPairing && !$hasExistingPlacement) {
            $binaryParentId = (int)($_POST['binary_upline_id'] ?? 0);
            $binaryPosition = (string)($_POST['binary_position'] ?? '');
            if ($binaryParentId <= 0 || !in_array($binaryPosition, ['left', 'right'], true)) {
                $this->redirectUpgradeWithError('Select a binary upline and an available position.');
            }
            $upline = User::find($binaryParentId);
            if (!$upline
                || (int)$upline['id'] === $userId
                || !User::isValidBinaryUpline($binaryParentId)
            ) {
                $this->redirectUpgradeWithError('The selected binary upline is not available.');
            }
            if (!User::isSlotFree($binaryParentId, $binaryPosition)) {
                $this->redirectUpgradeWithError('That binary position has already been occupied. Choose another position.');
            }
        }

        try {
            User::upgrade(
                $userId,
                $newPackageId,
                $paymentMethod,
                $binaryParentId,
                $binaryPosition,
                $upgradeCodeId
            );
            unset($_SESSION['upgrade_form_state']);
            flash('success', 'Package upgraded successfully.');
        } catch (\Exception $e) {
            $this->redirectUpgradeWithError('Upgrade failed: ' . $e->getMessage());
        }

        redirect('/?page=upgrade');
    }

    public function ajaxValidateUpgradeCode(): void
    {
        if (!Auth::check()) {
            json_response(['valid' => false, 'message' => 'Your session expired. Please log in again.'], 401);
        }
        if (!Auth::isMember()) {
            json_response(['valid' => false, 'message' => 'Access denied.'], 403);
        }
        $user = Auth::user();
        if ((string)($user['status'] ?? '') !== 'active' || (int)($user['package_id'] ?? 0) <= 0) {
            json_response(['valid' => false, 'message' => 'Your account cannot upgrade packages right now.'], 403);
        }
        $token = (string)($_POST['csrf_token'] ?? '');
        if ($token === '' || !hash_equals(csrf_token(), $token)) {
            json_response(['valid' => false, 'message' => 'Your session expired. Please refresh the page.'], 403);
        }

        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $selectedPackageId = (int)($_POST['package_id'] ?? 0);
        if ($code === '' || $selectedPackageId <= 0) {
            json_response(['valid' => false, 'message' => 'Select a package and enter an upgrade code.'], 422);
        }

        $codeRow = Code::validate($code);
        if (!$codeRow || (string)($codeRow['code_type'] ?? 'registration') !== 'upgrade') {
            json_response(['valid' => false, 'message' => 'Invalid, expired, or already-used upgrade code.'], 422);
        }

        $codePackageId = (int)$codeRow['package_id'];
        if ($codePackageId !== $selectedPackageId) {
            $codePackage = Package::find($codePackageId);
            json_response([
                'valid' => false,
                'message' => 'This code is for ' . ($codePackage['name'] ?? 'another package') . ', not the selected package.',
            ], 422);
        }

        $currentPackage = Package::find((int)$user['package_id']);
        $targetPackage = Package::find($selectedPackageId);
        if (!$currentPackage || !$targetPackage || (string)$targetPackage['status'] !== 'active') {
            json_response(['valid' => false, 'message' => 'The selected package is not available.'], 422);
        }
        $difference = (float)$targetPackage['entry_fee'] - (float)$currentPackage['entry_fee'];
        if ($difference <= 0 || !Package::upgradeCompatible((int)$user['package_id'], $selectedPackageId)) {
            json_response(['valid' => false, 'message' => 'The selected package is not a valid upgrade.'], 422);
        }

        json_response([
            'valid' => true,
            'package_id' => $selectedPackageId,
            'package_name' => (string)$targetPackage['name'],
            'upgrade_fee' => fmt_money($difference),
        ]);
    }

    /** AJAX: search pair-enabled active members with an available binary slot */
    public function ajaxBinaryUplines(): void
    {
        if (!Auth::check() || !Auth::isMember()) {
            json_response(['candidates' => [], 'message' => 'Your session expired. Please log in again.'], 401);
        }
        if ((string)(Auth::user()['status'] ?? '') !== 'active') {
            json_response(['candidates' => [], 'message' => 'Your account cannot use this feature right now.'], 403);
        }
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            json_response(['candidates' => []]);
        }
        $adminId = User::primaryAdminId();
        $st = db()->prepare("
            SELECT u.id, u.username, u.full_name,
                   COALESCE(p.name, 'Binary network root') AS package_name
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.status = 'active'
              AND u.id <> ?
              AND (COALESCE(p.pairing_enabled, 0) = 1 OR u.id = ?)
              AND (u.username LIKE ? OR u.full_name LIKE ?)
            ORDER BY (u.id = ?) DESC, u.username ASC
            LIMIT 20
        ");
        $like = '%' . $q . '%';
        $st->execute([Auth::id(), $adminId, $like, $like, $adminId]);

        $candidates = [];
        foreach ($st->fetchAll() as $u) {
            $leftFree  = User::isSlotFree((int)$u['id'], 'left');
            $rightFree = User::isSlotFree((int)$u['id'], 'right');
            if (!$leftFree && !$rightFree) continue;
            $candidates[] = [
                'id'         => (int)$u['id'],
                'username'   => $u['username'],
                'full_name'  => $u['full_name'] ?: $u['username'],
                'package'    => $u['package_name'],
                'left_free'  => $leftFree,
                'right_free' => $rightFree,
            ];
        }
        json_response(['candidates' => $candidates]);
    }

    // ── E-Wallet Transfer ──────────────────────────────────────────────────

    public function ewalletTransfer(): void
    {
        Auth::check() or redirect('/?page=login');
        $user = Auth::actingUser();
        $fee = Auth::isAdmin() ? 0.00 : (float) setting('ewallet_transfer_fee', '0.00');
        $min = (float) setting('ewallet_min_transfer', '50.00');
        $dailyLimit  = (float) setting('ewallet_transfer_daily_limit', '5000.00');
        $weeklyLimit = (float) setting('ewallet_transfer_weekly_limit', '20000.00');

        $pdo = db();
        $recent = $pdo->prepare("
            SELECT t.*, su.username AS sender_username, ru.username AS recipient_username
            FROM ewallet_transfers t
            JOIN users su ON su.id = t.sender_id
            JOIN users ru ON ru.id = t.recipient_id
            WHERE t.sender_id = ? OR t.recipient_id = ?
            ORDER BY t.created_at DESC
            LIMIT 20
        ");
        $recent->execute([$user['id'], $user['id']]);

        require 'views/member/ewallet_transfer.php';
    }

    public function doEwalletTransfer(): void
    {
        Auth::check() or redirect('/?page=login');
        csrf_verify();

        $senderId = Auth::id();
        $recipientUsername = trim($_POST['recipient'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $password = $_POST['password'] ?? '';

        // Password confirmation
        if (!User::verifyPassword($senderId, $password)) {
            flash('error', 'Password confirmation is incorrect.');
            redirect('/?page=ewallet_transfer');
            return;
        }

        $recipient = User::findByUsername($recipientUsername);
        if (!$recipient) {
            flash('error', 'Recipient not found.');
            redirect('/?page=ewallet_transfer');
            return;
        }

        if ($recipient['id'] === $senderId) {
            flash('error', 'You cannot transfer to yourself.');
            redirect('/?page=ewallet_transfer');
            return;
        }

        $result = Ewallet::transfer($senderId, $recipient['id'], $amount, $note);

        if ($result['ok']) {
            flash('success', 'Transfer completed successfully.');
        } else {
            flash('error', $result['error']);
        }
        redirect('/?page=ewallet_transfer');
    }

    public function apiCdStatus(): void
    {
        Auth::guard('member');
        $userId = Auth::id();
        $cd = CdStatus::getActive($userId);

        if (!$cd) {
            json_response(['active' => false]);
            return;
        }

        $percent = $cd['target_amount'] > 0
            ? round(((float)$cd['filled_amount'] / (float)$cd['target_amount']) * 100, 1)
            : 0;

        json_response([
            'active'      => true,
            'target'      => (float)$cd['target_amount'],
            'filled'      => (float)$cd['filled_amount'],
            'percent'     => $percent,
            'assigned_at' => $cd['assigned_at'],
        ]);
    }

    private function guardUpgradeAccount(): array
    {
        Auth::guard('member');
        if (!Auth::isMember()) {
            flash('error', 'Access denied.');
            redirect(Auth::isAdmin() ? '/?page=admin' : '/?page=dashboard');
        }

        $user = Auth::user();
        if (($user['reg_payment_method'] ?? '') === 'standard') {
            flash('error', 'Standard accounts cannot be upgraded.');
            redirect('/?page=dashboard');
        }
        $status = (string)($user['status'] ?? '');
        if ($status === 'pending') {
            flash('error', 'Activate your account before upgrading packages.');
            redirect('/?page=activate');
        }
        if ($status !== 'active') {
            flash('error', 'Your account must be active to upgrade packages.');
            redirect('/?page=dashboard');
        }
        if ((int)($user['package_id'] ?? 0) <= 0) {
            flash('error', 'Select a package before requesting an upgrade.');
            redirect('/?page=activate');
        }

        return $user;
    }

    private function redirectUpgradeWithError(string $message): never
    {
        $paymentMethod = (string)($_POST['payment_method'] ?? 'code');
        $binaryPosition = (string)($_POST['binary_position'] ?? '');
        $_SESSION['upgrade_form_state'] = [
            'package_id' => (int)($_POST['package_id'] ?? 0),
            'payment_method' => in_array($paymentMethod, ['code', 'ewallet'], true) ? $paymentMethod : 'code',
            'binary_upline_id' => (int)($_POST['binary_upline_id'] ?? 0),
            'binary_position' => in_array($binaryPosition, ['left', 'right'], true) ? $binaryPosition : '',
        ];
        flash('error', $message);
        redirect('/?page=upgrade');
    }

    // ── Shop (physical storefront) ──────────────────────────────────────────
    // Full implementation per tmp/shop/CART_AND_ADMIN_PRODUCTS_PLAN_REVISED.md §4.1.

    /** Storefront master switch (settings.shop_enabled). */
    private function guardShop(): void
    {
        if (setting('shop_enabled', '1') === '1') return;
        flash('error', 'The shop is currently closed.');
        redirect('/?page=dashboard');
    }

    /** Load an order for the member desk pages, or flash+redirect away. */
    private function resolveOwnOrder(): ?array
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'Order not found.');
            redirect('/?page=shop_orders');
        }
        $order = ShopOrder::findWithItems($id);
        if (!$order || (int) $order['member_id'] !== Auth::id()) {
            flash('error', 'Order not found.');
            redirect('/?page=shop_orders');
        }
        return $order;
    }

    public function shop(): void
    {
        Auth::guard('member');
        $this->guardShop();
        $products = Product::active();
        $cartId   = Cart::getActive(Auth::id())['id'] ?? null;
        $cartCount = $cartId ? (int) Cart::getTotals((int) $cartId)['total_items'] : 0;
        $pageTitle = 'Shop';
        require 'views/member/shop.php';
    }

    public function shopOrders(): void
    {
        Auth::guard('member');
        $orders = ShopOrder::allForMember(Auth::id());
        $pageTitle = 'My Orders';
        require 'views/member/shop_orders.php';
    }

    public function shopOrder(): void
    {
        Auth::guard('member');
        $order = $this->resolveOwnOrder();
        $orderId = (int) $order['id'];
        $events   = ShopOrderEvent::forOrder($orderId);
        $shipments = ShopShipment::byOrder($orderId);
        $activeShipment = ShopShipment::activeFor($orderId);
        $proofs   = ShopPaymentProof::byOrder($orderId);
        $refunds  = ShopRefund::forOrder($orderId);
        $pageTitle = 'Order ' . e($order['order_no']);
        require 'views/member/shop_order.php';
    }

    public function shopInvoice(): void
    {
        Auth::guard('member');
        $order = $this->resolveOwnOrder();
        $doc   = ShopInvoice::docForOrder((int) $order['id']);
        if (!$doc) {
            http_response_code(404);
            exit('Invoice not found');
        }
        require 'views/shop/invoice.php';
    }

    public function cart(): void
    {
        Auth::guard('member');
        $this->guardShop();
        $cart = Cart::getActive(Auth::id());
        $cartId = $cart ? (int) $cart['id'] : null;
        $items  = $cartId ? Cart::getItems($cartId) : [];
        $totals = $cartId ? Cart::getTotals($cartId) : ['total_price' => 0, 'total_items' => 0];
        $stockErrors = $cartId ? Cart::validateStock($cartId) : [];
        $pageTitle = 'Cart';
        require 'views/member/cart.php';
    }

    /** Shared target resolution for the cart AJAX verbs. */
    private function cartTarget(): array
    {
        $cart = Cart::getOrCreate(Auth::id());
        return [(int) $cart['id'], (int) ($_POST['product_id'] ?? ($_POST['item_id'] ?? 0))];
    }

    public function addToCart(): void
    {
        Auth::guard('member');
        csrf_verify();
        $this->guardShop();

        [$cartId, $productId] = $this->cartTarget();
        $qty = max(1, (int) ($_POST['quantity'] ?? 1));
        try {
            Cart::addItem($cartId, $productId, $qty);
            $count = (int) Cart::getTotals($cartId)['total_items'];
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                json_response(['ok' => true, 'count' => $count]);
            }
            flash('success', 'Added to cart.');
        } catch (InvalidArgumentException $e) {
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            flash('error', $e->getMessage());
        }
        redirect('/?page=shop&cart=1');
    }

    /**
     * The caller's active cart id, or a redirect away.
     *
     * Ownership anchor for every cart mutation. The posted item_id identifies a
     * row in cart_items, whose primary key is a sequential AUTO_INCREMENT shared
     * by every member — it is not proof of ownership on its own, so each verb
     * pairs it with this id (defect C3).
     */
    private function requireOwnCart(): int
    {
        $cart = Cart::getActive(Auth::id());
        if (!$cart) {
            flash('error', 'Your cart is empty.');
            redirect('/?page=shop');
        }
        return (int) $cart['id'];
    }

    public function updateCartItem(): void
    {
        Auth::guard('member');
        csrf_verify();
        $cartId = $this->requireOwnCart();

        $itemId = (int) ($_POST['item_id'] ?? 0);
        $qty    = (int) ($_POST['quantity'] ?? 0);
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

        try {
            Cart::updateItemQuantity($itemId, $qty, $cartId);
            $totals = Cart::getTotals($cartId);
            if ($isAjax) {
                json_response(['ok' => true, 'subtotal' => number_format((float) $totals['total_price'], 2), 'count' => (int) $totals['total_items']]);
            }
        } catch (InvalidArgumentException $e) {
            if ($isAjax) {
                json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            flash('error', $e->getMessage());
        }
        redirect('/?page=cart');
    }

    public function removeCartItem(): void
    {
        Auth::guard('member');
        csrf_verify();
        $cartId = $this->requireOwnCart();

        $itemId = (int) ($_POST['item_id'] ?? 0);
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

        try {
            Cart::removeItemById($itemId, $cartId);
            $totals = Cart::getTotals($cartId);
            if ($isAjax) {
                json_response(['ok' => true, 'subtotal' => number_format((float) $totals['total_price'], 2), 'count' => (int) $totals['total_items']]);
            }
        } catch (InvalidArgumentException $e) {
            if ($isAjax) {
                json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            flash('error', $e->getMessage());
        }
        redirect('/?page=cart');
    }

    public function checkout(): void
    {
        Auth::guard('member');
        $this->guardShop();

        $cart = Cart::getActive(Auth::id());
        if (!$cart) {
            flash('info', 'Your cart is empty.');
            redirect('/?page=shop');
        }
        $cartId = (int) $cart['id'];
        Cart::refreshPrices($cartId);
        $items = Cart::getItems($cartId);
        if (!$items) {
            flash('info', 'Your cart is empty.');
            redirect('/?page=shop');
        }
        $stockErrors = Cart::validateStock($cartId);
        if ($stockErrors) {
            flash('error', $stockErrors[0]);
            redirect('/?page=cart');
        }
        $totals = Cart::getTotals($cartId);
        $paymentMethods = [
            'ewallet'     => 'E-Wallet',
            'gcash'       => 'GCash',
            'maya'        => 'Maya',
            'usdt_trc20'  => 'USDT (TRC20)',
            'usdt_bep20'  => 'USDT (BEP20)',
        ];
        $addresses = MemberAddress::forMember(Auth::id());
        $pageTitle = 'Checkout';
        require 'views/member/checkout.php';
    }

    public function placeOrder(): void
    {
        Auth::guard('member');
        csrf_verify();
        $this->guardShop();

        $cart = Cart::getActive(Auth::id());
        if (!$cart) {
            flash('error', 'Your cart is empty.');
            redirect('/?page=shop');
        }

        $method = (string) ($_POST['payment_method'] ?? '');
        $billingName = trim((string) ($_POST['billing_name'] ?? ''));
        $addr = [
            'billing_name'      => $billingName,
            'billing_phone'     => trim((string) ($_POST['billing_phone'] ?? '')),
            'shipping_name'     => trim((string) ($_POST['shipping_name'] ?? $billingName)),
            'shipping_phone'    => trim((string) ($_POST['shipping_phone'] ?? '')),
            'shipping_address'  => trim((string) ($_POST['shipping_address'] ?? '')),
            'shipping_barangay' => trim((string) ($_POST['shipping_barangay'] ?? '')),
            'shipping_city'     => trim((string) ($_POST['shipping_city'] ?? '')),
            'shipping_province' => trim((string) ($_POST['shipping_province'] ?? '')),
            'shipping_region'   => trim((string) ($_POST['shipping_region'] ?? '')),
            'shipping_postal'   => trim((string) ($_POST['shipping_postal'] ?? '')),
            'region_code'       => trim((string) ($_POST['shipping_region_code'] ?? '')),
            'province_code'     => trim((string) ($_POST['shipping_province_code'] ?? '')),
            'city_code'         => trim((string) ($_POST['shipping_city_code'] ?? '')),
            'barangay_code'     => trim((string) ($_POST['shipping_barangay_code'] ?? '')),
            'notes_member'      => trim((string) ($_POST['notes_member'] ?? '')),
        ];
        // All three are required; the message names whichever are actually
        // blank so a member with only Billing name empty is not told to
        // "fill in the recipient name" they already typed.
        $missing = [];
        if ($addr['billing_name'] === '') {
            $missing[] = 'billing name';
        }
        if ($addr['shipping_name'] === '') {
            $missing[] = 'recipient name';
        }
        if ($addr['shipping_address'] === '') {
            $missing[] = 'delivery address';
        }
        if ($missing) {
            shop_checkout_remember_input($_POST);
            flash('error', 'Please fill in the ' . implode(', ', $missing) . '.');
            redirect('/?page=checkout');
        }
        if (($_POST['terms'] ?? '') !== '1') {
            shop_checkout_remember_input($_POST);
            flash('error', 'Please accept the terms to place your order.');
            redirect('/?page=checkout');
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();
            $orderId = ShopOrder::createFromCart(
                Auth::id(),
                (int) $cart['id'],
                $addr,
                $method,
                sha1(Auth::id() . '|' . $cart['id'] . '|' . ($_POST['idem'] ?? ''))
            );

            if ($method === 'ewallet') {
                // Replay guard: an idempotency race can hand two requests the same
                // order id. Lock the row, then pay only if it is still unpaid and
                // carries no shop_order debit yet — the second request commits
                // as a no-op instead of double-charging the wallet.
                $st = $pdo->prepare("SELECT status FROM shop_orders WHERE id = ? FOR UPDATE");
                $st->execute([$orderId]);
                $status = (string) $st->fetchColumn();
                $stCount = $pdo->prepare("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'shop_order' AND reference_id = ? AND type = 'debit'");
                $stCount->execute([$orderId]);
                $debitCount = (int) $stCount->fetchColumn();
                if ($status !== 'pending' || $debitCount > 0) {
                    $pdo->commit();
                    flash('success', 'Order already processed.');
                    redirect('/?page=shop_order&id=' . $orderId);
                }
                $order = ShopOrder::find($orderId);
                $total = (float) $order['total_price'];
                if (!Ewallet::debitInternal(Auth::id(), $total, $orderId, 'shop_order', "Shop order {$order['order_no']}")) {
                    throw new RuntimeException('Insufficient e-wallet balance for ' . fmt_money($total) . '.');
                }

                // Phase 6.1: the paid transition now runs INSIDE this
                // transaction rather than after it. It used to be called after
                // $pdo->commit(), so any failure inside markPaid() — a stock
                // conflict at reservation, a database error — left the money
                // debited and its ledger row committed against a `pending`
                // order, with the cart never cleared. The catch block below
                // could not undo it: rolling back an already-committed
                // transaction is a no-op, so the buyer saw "Could not place the
                // order" while their balance was already reduced.
                //
                // transition() is transaction-aware (Phase 6.1) and joins this
                // unit instead of opening its own.
                if (!ShopOrder::markPaid($orderId, 'system', null)) {
                    throw new RuntimeException('Could not confirm the order payment. No funds have been taken.');
                }
                Cart::clear((int) $cart['id']);
                $pdo->commit();
                $this->maybeSaveCheckoutAddress($addr);
                flash('success', 'Order placed and paid. Thank you!');
                redirect('/?page=shop_order&id=' . $orderId);
            }

            $pdo->commit();
            $this->maybeSaveCheckoutAddress($addr);
            flash('success', 'Order placed. Upload your payment proof to continue.');
            redirect('/?page=shop_order&id=' . $orderId);
        } catch (InvalidArgumentException | RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Phase 8.7: every one of these failures used to redirect to a blank
            // checkout form, discarding the address the member had just typed.
            shop_checkout_remember_input($_POST);
            flash('error', $e->getMessage());
            redirect('/?page=checkout');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            shop_checkout_remember_input($_POST);
            flash('error', 'Could not place the order. Please try again.');
            redirect('/?page=checkout');
        }
    }

    /**
     * Optionally store the address the buyer just used, after a successful
     * order. Best-effort: the order is already committed, so a failure here
     * must never surface as an order error.
     */
    private function maybeSaveCheckoutAddress(array $addr): void
    {
        if (($_POST['save_address'] ?? '') !== '1') {
            return;
        }
        $uid = Auth::id();
        if (MemberAddress::countForMember($uid) >= MemberAddress::MAX_ADDRESSES) {
            return;
        }
        try {
            MemberAddress::create($uid, [
                'label'          => trim((string) ($_POST['address_label'] ?? '')),
                'recipient_name' => $addr['shipping_name'],
                'phone'          => $addr['shipping_phone'],
                'address_line'   => $addr['shipping_address'],
                'barangay'       => $addr['shipping_barangay'] ?? '',
                'city'           => $addr['shipping_city'],
                'province'       => $addr['shipping_province'],
                'region'         => $addr['shipping_region'] ?? '',
                'postal'         => $addr['shipping_postal'],
                'country'        => 'PH',
                'region_code'    => $addr['region_code'] ?? '',
                'province_code'  => $addr['province_code'] ?? '',
                'city_code'      => $addr['city_code'] ?? '',
                'barangay_code'  => $addr['barangay_code'] ?? '',
                'billing_name'   => $addr['billing_name'] ?? '',
                'billing_phone'  => $addr['billing_phone'] ?? '',
                'notes_member'   => $addr['notes_member'] ?? '',
            ]);
        } catch (Throwable $e) {
            // Ignore: the address book is a convenience, not part of the order.
        }
    }

    public function submitShopProof(): void
    {
        Auth::guard('member');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order = ShopOrder::find($orderId);
        if (!$order || (int) $order['member_id'] !== Auth::id()) {
            flash('error', 'Order not found.');
            redirect('/?page=shop_orders');
        }

        try {
            $path = upload_image($_FILES['proof_image'] ?? [], 'shop_proofs', 'proof_' . Auth::id());
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage() === 'type'
                ? 'Payment proof must be a JPG, PNG, GIF, or WebP image.'
                : 'Payment proof is too large (max 5 MB).');
            redirect('/?page=shop_order&id=' . $orderId);
        } catch (RuntimeException $e) {
            flash('error', 'Upload failed. Please try again.');
            redirect('/?page=shop_order&id=' . $orderId);
        }
        if (!$path) {
            flash('error', 'Please attach a payment proof image.');
            redirect('/?page=shop_order&id=' . $orderId);
        }

        $ok = ShopOrder::submitProof($orderId, Auth::id(), $path, [
            'reference_no'  => trim((string) ($_POST['reference_no'] ?? '')),
            'amount'        => (float) ($_POST['amount_sent'] ?? 0),
            'transfer_date' => ($_POST['transfer_date'] ?? '') !== '' ? $_POST['transfer_date'] : null,
        ]);
        flash($ok ? 'success' : 'error', $ok
            ? 'Proof submitted. We will verify your payment shortly.'
            : 'Proof upload refused — the window may have closed or the attempt cap was reached.');
        redirect('/?page=shop_order&id=' . $orderId);
    }

    public function cancelShopOrder(): void
    {
        Auth::guard('member');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $ok = ShopOrder::cancelByCustomer($orderId, Auth::id(), trim((string) ($_POST['note'] ?? '')));
        flash($ok ? 'success' : 'error', $ok
            ? 'Order cancelled.'
            : 'This order can no longer be cancelled.');
        redirect('/?page=shop_order&id=' . $orderId);
    }

    public function confirmShopReceipt(): void
    {
        Auth::guard('member');
        csrf_verify();

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $ok = ShopOrder::confirmReceipt($orderId, Auth::id());
        flash($ok ? 'success' : 'error', $ok
            ? 'Thank you! Your order is now complete.'
            : 'Receipt could not be confirmed (an open refund may be blocking it).');
        redirect('/?page=shop_order&id=' . $orderId);
    }
}
