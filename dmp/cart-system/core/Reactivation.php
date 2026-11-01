<?php

/**
 * @file   core/Reactivation.php
 * @brief  Reactivation Service — Phase 4 Implementation
 *
 * Handles member-initiated account reactivation after hitting the lifetime cap.
 * Supports two flows:
 *   1. E-Wallet: immediate debit + reset (no admin confirmation)
 *   2. External (GCash/Maya/USDT): pending → admin confirms → reset
 */
class Reactivation
{
    /**
     * Validate reactivation eligibility and return available payment options.
     *
     * @param int $userId User ID
     * @return array ['ok' => bool, 'fee' => float, 'days_remaining' => int, ...]
     */
    public static function requestReactivation(int $userId): array
    {
        $capStatus = CapEngine::getCapStatus($userId);

        // 1. Must be capped
        if ($capStatus['cap_status'] !== 'capped') {
            return ['ok' => false, 'error' => 'Your account is not currently capped.'];
        }

        // 2. Check if permanently inactive
        $pdo = db();
        $st = $pdo->prepare("SELECT cap_status FROM users WHERE id = ?");
        $st->execute([$userId]);
        $currentStatus = $st->fetchColumn();
        if ($currentStatus === 'perminact') {
            return ['ok' => false, 'error' => 'Your account is permanently inactive. Reactivation is no longer possible.'];
        }

        // 3. Check no pending reactivation already
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM reactivations WHERE user_id = {$userId} AND status = 'pending'")->fetchColumn();
        if ($pending > 0) {
            return ['ok' => false, 'error' => 'You already have a pending reactivation request.'];
        }

        // 4. Check reactivation window
        $cappedAt   = $capStatus['capped_at'];
        $windowDays = $capStatus['reactivation_window'];
        $fee        = $capStatus['reactivation_fee'];

        $deadline = null;
        $daysRemaining = $windowDays;

        if ($cappedAt) {
            $deadline = date('Y-m-d H:i:s', strtotime($cappedAt . " +{$windowDays} days"));
            if (time() > strtotime($deadline)) {
                return ['ok' => false, 'error' => 'Your reactivation window has expired.'];
            }
            $daysRemaining = max(0, ceil((strtotime($deadline) - time()) / 86400));
        }

        // 5. Check e-wallet balance
        $ewalletBalance = Ewallet::balance($userId);
        $canUseEwallet  = $fee > 0 && $ewalletBalance >= $fee;

        return [
            'ok'              => true,
            'fee'             => $fee,
            'window_days'     => $windowDays,
            'days_remaining'  => $daysRemaining,
            'capped_at'       => $cappedAt,
            'deadline'        => $deadline,
            'ewallet_balance' => $ewalletBalance,
            'can_use_ewallet' => $canUseEwallet,
            'payment_methods' => array_values(array_filter([
                $canUseEwallet ? 'ewallet' : null,
                'gcash',
                'maya',
                'usdt_trc20',
                'usdt_bep20',
            ])),
        ];
    }

    /**
     * Process a reactivation payment.
     *
     * E-Wallet: immediate debit + atomic reset.
     * External (GCash/Maya/USDT): creates pending record, admin confirms later.
     *
     * @param int    $userId        User ID
     * @param string $paymentMethod 'ewallet' | 'gcash' | 'maya' | 'usdt_trc20' | 'usdt_bep20' | 'admin'
     * @param string $proofImage    Optional file path for proof image (external payments)
     * @return array ['ok' => bool, 'message' => string, 'pending' => bool, ...]
     */
    public static function processReactivation(int $userId, string $paymentMethod, string $proofImage = ''): array
    {
        $capStatus = CapEngine::getCapStatus($userId);

        // 1. Must be capped
        if ($capStatus['cap_status'] !== 'capped') {
            return ['ok' => false, 'error' => 'Your account is not currently capped.'];
        }

        // 2. Check window
        $cappedAt   = $capStatus['capped_at'];
        $windowDays = $capStatus['reactivation_window'];
        $fee        = $capStatus['reactivation_fee'];

        if ($cappedAt) {
            $deadline = date('Y-m-d H:i:s', strtotime($cappedAt . " +{$windowDays} days"));
            if (time() > strtotime($deadline)) {
                return ['ok' => false, 'error' => 'Your reactivation window has expired.'];
            }
        }

        // 3. Validate payment method
        $allowed = ['ewallet', 'gcash', 'maya', 'usdt_trc20', 'usdt_bep20', 'admin'];
        if (!in_array($paymentMethod, $allowed, true)) {
            return ['ok' => false, 'error' => 'Invalid payment method.'];
        }

        // 4. Check no pending request already
        $pdo = db();
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM reactivations WHERE user_id = {$userId} AND status = 'pending'")->fetchColumn();
        if ($pending > 0) {
            return ['ok' => false, 'error' => 'You already have a pending reactivation request.'];
        }

        // 5. External payments require proof image
        if ($paymentMethod !== 'ewallet' && empty($proofImage)) {
            return ['ok' => false, 'error' => 'Please upload proof of payment.'];
        }

        // Get current state
        $user = User::find($userId);
        if (!$user) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        $packageId      = (int)$user['package_id'];
        $previousEarned = (float)$user['lifetime_earned'];

        // ═══════════════════════════════════════════════════════════════════
        //  E-WALLET PATH — Immediate
        // ═══════════════════════════════════════════════════════════════════
        if ($paymentMethod === 'ewallet') {
            $balance = Ewallet::balance($userId);
            if ($balance < $fee) {
                return ['ok' => false, 'error' => 'Insufficient e-wallet balance.'];
            }

            $pdo->beginTransaction();
            try {
                // Record reactivation
                $pdo->prepare("
                    INSERT INTO reactivations
                      (user_id, amount_paid, previous_earned, package_id, payment_method, status, proof_image)
                    VALUES (?, ?, ?, ?, ?, 'completed', ?)
                ")->execute([
                    $userId,
                    $fee,
                    $previousEarned,
                    $packageId,
                    $paymentMethod,
                    $proofImage,
                ]);
                $reactivationId = (int)$pdo->lastInsertId();

                // Debit e-wallet
                if ($fee > 0) {
                    $debitOk = Ewallet::debitInternal(
                        $userId,
                        $fee,
                        $reactivationId,
                        'reactivation',
                        'Account reactivation fee'
                    );
                    if (!$debitOk) {
                        throw new RuntimeException('E-wallet debit failed during reactivation.');
                    }
                }

                // Reset cap state
                $pdo->prepare("
                    UPDATE users
                    SET cap_status = 'active',
                        lifetime_earned = 0,
                        capped_at = NULL,
                        dfi_days_used = 0,
                        dfi_active = 1,
                        last_reactivation_at = NOW()
                    WHERE id = ?
                ")->execute([$userId]);

                $pdo->commit();

                return [
                    'ok'      => true,
                    'message' => 'Account reactivated successfully. Your new earning cycle has started.',
                    'pending' => false,
                    'fee'     => $fee,
                ];
            } catch (\Exception $e) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Reactivation failed: ' . $e->getMessage()];
            }
        }

        // ═══════════════════════════════════════════════════════════════════
        //  EXTERNAL PATH — Pending admin confirmation
        // ═══════════════════════════════════════════════════════════════════
        $pdo->prepare("
            INSERT INTO reactivations
              (user_id, amount_paid, previous_earned, package_id, payment_method, status, proof_image)
            VALUES (?, ?, ?, ?, ?, 'pending', ?)
        ")->execute([
            $userId,
            $fee,
            $previousEarned,
            $packageId,
            $paymentMethod,
            $proofImage,
        ]);

        return [
            'ok'      => true,
            'message' => 'Reactivation request submitted. Admin will review your payment proof and confirm shortly.',
            'pending' => true,
            'fee'     => $fee,
        ];
    }

    /**
     * Admin confirms an external reactivation payment and resets cap state.
     *
     * @param int    $reactivationId Reactivation record ID
     * @param int    $adminId        Admin user ID
     * @param string $note           Admin note
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function confirm(int $reactivationId, int $adminId, string $note = ''): array
    {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $st = $pdo->prepare("SELECT * FROM reactivations WHERE id = ? AND status = 'pending'");
            $st->execute([$reactivationId]);
            $record = $st->fetch();

            if (!$record) {
                throw new RuntimeException('Pending reactivation not found.');
            }

            $userId = (int)$record['user_id'];

            // Ensure user is still capped
            $userSt = $pdo->prepare("SELECT cap_status FROM users WHERE id = ?");
            $userSt->execute([$userId]);
            if ($userSt->fetchColumn() !== 'capped') {
                throw new RuntimeException('User is no longer capped. Cannot confirm reactivation.');
            }

            // Reset cap state — fresh cycle
            $pdo->prepare("
                UPDATE users
                SET cap_status = 'active',
                    lifetime_earned = 0,
                    capped_at = NULL,
                    dfi_days_used = 0,
                    dfi_active = 1,
                    last_reactivation_at = NOW()
                WHERE id = ?
            ")->execute([$userId]);

            // Mark reactivation as completed
            $pdo->prepare("
                UPDATE reactivations
                SET status = 'completed',
                    admin_note = ?,
                    processed_by = ?,
                    processed_at = NOW()
                WHERE id = ?
            ")->execute([$note, $adminId, $reactivationId]);

            $pdo->commit();

            return [
                'ok'      => true,
                'message' => 'Reactivation confirmed. Member cap state has been reset.',
            ];

        } catch (\Exception $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Admin rejects a pending reactivation request.
     *
     * @param int    $reactivationId Reactivation record ID
     * @param int    $adminId        Admin user ID
     * @param string $note           Rejection reason
     * @return bool True if rejected successfully
     */
    public static function reject(int $reactivationId, int $adminId, string $note = ''): bool
    {
        $st = db()->prepare("
            UPDATE reactivations
            SET status = 'rejected',
                admin_note = ?,
                processed_by = ?,
                processed_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $st->execute([$note, $adminId, $reactivationId]);
        return $st->rowCount() > 0;
    }

    /**
     * Expire capped users who missed the reactivation window.
     * Delegates to CapEngine for the actual query.
     *
     * @return int Number of users expired
     */
    public static function expireOldCappedUsers(): int
    {
        return CapEngine::expireOldCappedUsers();
    }

    /**
     * Get paginated reactivation history for a member.
     *
     * @param int $userId User ID
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return array Paginated reactivation records
     */
    public static function getReactivationHistory(int $userId, int $page = 1, int $perPage = 10): array
    {
        return paginate(
            "SELECT r.*, p.name AS package_name
             FROM reactivations r
             JOIN packages p ON p.id = r.package_id
             WHERE r.user_id = ?
             ORDER BY r.created_at DESC",
            [$userId],
            $page,
            $perPage
        );
    }

    // ── Admin Query Helpers (patterned after Payout.php) ────────────────────

    /**
     * Find a single reactivation record with member details.
     */
    public static function find(int $id): ?array
    {
        $st = db()->prepare("
            SELECT r.*, u.username, u.full_name,
                   a.username AS admin_username
            FROM reactivations r
            JOIN users u ON u.id = r.user_id
            LEFT JOIN users a ON a.id = r.processed_by
            WHERE r.id = ?
        ");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Get paginated reactivation records for admin.
     */
    public static function all(int $page = 1, string $status = '', int $perPage = 10): array
    {
        $where  = '1=1';
        $params = [];
        if ($status) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }

        return paginate(
            "SELECT r.*, u.username, u.full_name, a.username AS admin_username
             FROM reactivations r
             JOIN users u ON u.id = r.user_id
             LEFT JOIN users a ON a.id = r.processed_by
             WHERE {$where}
             ORDER BY r.created_at DESC",
            $params,
            $page,
            $perPage
        );
    }

    /**
     * Total pending reactivation fees.
     */
    public static function pendingTotal(): float
    {
        return (float)db()->query(
            "SELECT COALESCE(SUM(amount_paid),0) FROM reactivations WHERE status='pending'"
        )->fetchColumn();
    }

    /**
     * Total completed reactivation revenue.
     */
    public static function completedTotal(): float
    {
        return (float)db()->query(
            "SELECT COALESCE(SUM(amount_paid),0) FROM reactivations WHERE status='completed'"
        )->fetchColumn();
    }
}
