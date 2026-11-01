<?php

/**
 * @file   core/CapEngine.php
 * @brief  Lifetime Income Capping Engine — Phase 2 Implementation
 *
 * Centralized cap checking for ALL commission types:
 *   - Binary pairing bonuses
 *   - Direct referral bonuses
 *   - Indirect (unilevel) referral bonuses
 *   - Daily fixed income (DFI)
 *
 * Cap = entry_fee × lifetime_cap_multiplier (configured per package)
 * Once cumulative earnings reach this limit, account becomes 'capped'.
 */
class CapEngine
{
    // ── Core Cap Checking ─────────────────────────────────────────────────

    /**
     * Check if a user can earn a given amount under their lifetime cap.
     *
     * @param int   $userId User ID
     * @param float $amount Amount they want to earn
     * @return array ['allowed' => float, 'blocked' => float, 'status' => string]
     */
    public static function canEarn(int $userId, float $amount): array
    {
        $status = self::getCapStatus($userId);

        // Standard accounts have no capping applied and earn nothing
        $pm = db()->query("SELECT reg_payment_method FROM users WHERE id = {$userId}")->fetchColumn();
        if ($pm === 'standard') {
            return [
                'allowed' => 0.00,
                'blocked' => $amount,
                'status'  => 'standard',
            ];
        }

        // Deactivated users earn nothing
        $userStatus = db()->query("SELECT status FROM users WHERE id = {$userId}")->fetchColumn();
        if ($userStatus === 'deactivated') {
            return [
                'allowed' => 0.00,
                'blocked' => $amount,
                'status'  => 'deactivated',
            ];
        }

        // VIP bypass — unlimited lifetime earnings
        if (!empty($status['capping_bypass'])) {
            return [
                'allowed' => round($amount, 2),
                'blocked' => 0.00,
                'status'  => 'vip',
            ];
        }

        if ($status['cap_status'] !== 'active') {
            return [
                'allowed' => 0.00,
                'blocked' => $amount,
                'status'  => $status['cap_status'],
            ];
        }

        $remaining = $status['remaining'];

        if ($remaining <= 0) {
            // Cap reached — trigger it now
            self::applyCap($userId);
            return [
                'allowed' => 0.00,
                'blocked' => $amount,
                'status'  => 'capped',
            ];
        }

        $allowed = min($amount, $remaining);
        $blocked = $amount - $allowed;

        return [
            'allowed' => round($allowed, 2),
            'blocked' => round($blocked, 2),
            'status'  => 'active',
        ];
    }

    /**
     * Record an earning against the lifetime cap.
     * Updates lifetime_earned and checks if cap is reached.
     *
     * @param int    $userId User ID
     * @param float  $amount Amount actually earned (after cap check)
     * @param string $type   Commission type for logging
     */
    public static function recordEarning(int $userId, float $amount, string $type): void
    {
        if ($amount <= 0) return;
        $pm = db()->query("SELECT reg_payment_method FROM users WHERE id = {$userId}")->fetchColumn();
        if ($pm === 'standard') {
            return;
        }

        $pdo = db();

        // Atomically increment lifetime_earned
        $pdo->prepare("
            UPDATE users
            SET lifetime_earned = lifetime_earned + :amt
            WHERE id = :id AND cap_status = 'active'
        ")->execute([':amt' => $amount, ':id' => $userId]);

        // Check if cap now reached
        $st = $pdo->prepare("
            SELECT u.lifetime_earned, (p.entry_fee * p.lifetime_cap_multiplier) AS cap
            FROM users u
            JOIN packages p ON p.id = u.package_id
            WHERE u.id = ? AND u.cap_status = 'active'
        ");
        $st->execute([$userId]);
        $row = $st->fetch();

        if ($row && (float)$row['lifetime_earned'] >= (float)$row['cap']) {
            // Skip capping for VIP members
            $bypass = (int)db()->query("SELECT capping_bypass FROM users WHERE id = {$userId}")->fetchColumn();
            if (!$bypass) {
                self::applyCap($userId);
            }
        }
    }

    /**
     * Get full cap status for a user.
     *
     * @param int $userId User ID
     * @return array Full cap state
     */
    public static function getCapStatus(int $userId): array
    {
        $pdo = db();
        $st = $pdo->prepare("
            SELECT
                u.lifetime_earned,
                u.cap_status,
                u.capping_bypass,
                u.daily_cap_bypass,
                u.capped_at,
                u.dfi_days_used,
                u.dfi_active,
                (p.entry_fee * p.lifetime_cap_multiplier) AS lifetime_cap,
                p.reactivation_fee,
                p.reactivation_window_days
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
        ");
        $st->execute([$userId]);
        $row = $st->fetch();

        if (!$row) {
            return [
                'lifetime_earned' => 0.00,
                'lifetime_cap'    => 0.00,
                'remaining'       => 0.00,
                'cap_status'      => 'perminact',
                'capped_at'       => null,
                'dfi_days_used'   => 0,
                'dfi_active'      => 0,
            ];
        }

        $earned = (float)$row['lifetime_earned'];
        $cap    = (float)$row['lifetime_cap'];
        $remaining = max(0, $cap - $earned);

        return [
            'lifetime_earned'      => $earned,
            'lifetime_cap'         => $cap,
            'remaining'            => $remaining,
            'cap_status'           => $row['cap_status'],
            'capping_bypass'       => (int)$row['capping_bypass'],
            'daily_cap_bypass'     => (int)$row['daily_cap_bypass'],
            'capped_at'            => $row['capped_at'],
            'dfi_days_used'        => (int)$row['dfi_days_used'],
            'dfi_active'           => (int)$row['dfi_active'],
            'reactivation_fee'     => (float)$row['reactivation_fee'],
            'reactivation_window'  => (int)$row['reactivation_window_days'],
        ];
    }

    /**
     * Check if user is active for binary pair counting.
     * Capped and permanently inactive members are SKIPPED.
     *
     * @param int $userId User ID
     * @return bool True if active (can earn pairs)
     */
    public static function isActiveForPairs(int $userId): bool
    {
        $pdo = db();
        $st = $pdo->prepare("SELECT status, cap_status FROM users WHERE id = ?");
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) return false;
        if ($row['status'] === 'deactivated') return false;
        return $row['cap_status'] === 'active';
    }

    /**
     * Apply cap when limit is reached.
     * Sets cap_status='capped', capped_at=NOW().
     *
     * @param int $userId User ID
     */
    public static function applyCap(int $userId): void
    {
        $pdo = db();
        $pdo->prepare("
            UPDATE users
            SET cap_status = 'capped',
                capped_at = NOW(),
                dfi_active = 0
            WHERE id = ? AND cap_status = 'active'
        ")->execute([$userId]);
    }

    // ── Batch / Utility Methods ────────────────────────────────────────────

    /**
     * Expire capped users who missed the reactivation window.
     * Called by midnight cron.
     *
     * @return int Number of users expired
     */
    public static function expireOldCappedUsers(): int
    {
        $pdo = db();
        $st = $pdo->prepare("
            UPDATE users
            SET cap_status = 'perminact'
            WHERE cap_status = 'capped'
              AND capped_at IS NOT NULL
              AND capped_at < DATE_SUB(NOW(), INTERVAL (
                  SELECT reactivation_window_days
                  FROM packages
                  WHERE packages.id = users.package_id
              ) DAY)
        ");
        $st->execute();
        return $st->rowCount();
    }

    /**
     * Get a summary of cap states for admin dashboard.
     *
     * @return array ['active' => int, 'capped' => int, 'perminact' => int]
     */
    public static function adminStats(): array
    {
        $pdo = db();
        $result = [
            'active'    => 0,
            'capped'    => 0,
            'perminact' => 0,
        ];

        $st = $pdo->query("
            SELECT cap_status, COUNT(*) AS cnt
            FROM users
            WHERE role = 'member'
            GROUP BY cap_status
        ");
        while ($row = $st->fetch()) {
            $result[$row['cap_status']] = (int)$row['cnt'];
        }

        return $result;
    }
}
