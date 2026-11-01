<?php

/**
 * @file   core/DailyFixedIncome.php
 * @brief  Daily Fixed Income Engine — Phase 3 Implementation
 *
 * Processes daily fixed income payouts for all eligible members.
 * Respects lifetime income cap and pauses DFI when capped.
 *
 * DFI & Cap Interaction Rules:
 *   - Active, under cap, under day limit  → Full DFI paid, counts toward cap
 *   - Active, near cap                   → Partial DFI paid (only up to cap), cap triggered
 *   - Capped                             → No DFI, days counter PAUSED (does not increment)
 *   - Permanently inactive               → No DFI, days counter frozen
 *   - Reactivates                        → dfi_days_used resets to 0, fresh DFI cycle starts
 */
class DailyFixedIncome
{
    /**
     * Process daily DFI payout for all eligible members.
     *
     * Called by midnight cron or manual trigger.
     *
     * @return array ['processed' => int, 'paid' => float, 'skipped' => int, 'reason' => ?string]
     */
    public static function processDailyPayout(): array
    {
        if (function_exists('setting')) {
            if (setting('commissions_enabled', '1') !== '1' || setting('commission_dfi_enabled', '1') !== '1') {
                return ['processed' => 0, 'paid' => 0.0, 'skipped' => 0, 'reason' => 'disabled'];
            }
        }
        $pdo = db();

        $processed = 0;
        $paid      = 0.00;
        $skipped   = 0;

        // ── 2. Fetch eligible members ─────────────────────────────────────────
        // Criteria:
        //   - role = 'member'
        //   - cap_status = 'active'          (capped / perminact = skipped)
        //   - dfi_active = 1                 (reset on reactivation)
        //   - dfi_days_used < max days       (under day limit)
        //   - package.daily_fixed_income > 0 (DFI-enabled package)
        //   - package.dfi_enabled = 1        (package-level DFI toggle)
        $st = $pdo->query("
            SELECT
                u.id,
                u.lifetime_earned,
                u.dfi_days_used,
                p.daily_fixed_income,
                p.daily_fixed_income_days,
                p.entry_fee,
                p.lifetime_cap_multiplier
            FROM users u
            JOIN packages p ON p.id = u.package_id
            WHERE u.role = 'member'
              AND u.status = 'active'
              AND u.cap_status = 'active'
              AND u.dfi_active = 1
              AND u.cd_active = 0
              AND u.dfi_days_used < p.daily_fixed_income_days
              AND p.daily_fixed_income > 0
              AND p.dfi_enabled = 1
            ORDER BY u.id
        ");

        // ── 3. Process each member ────────────────────────────────────────────
        while ($m = $st->fetch()) {
            $userId    = (int)$m['id'];
            $dailyRate = (float)$m['daily_fixed_income'];
            $daysUsed  = (int)$m['dfi_days_used'];
            $dayNumber = $daysUsed + 1;

            // 3a. Lifetime cap check
            $capCheck = CapEngine::canEarn($userId, $dailyRate);
            $allowed  = $capCheck['allowed'];
            $blocked  = $capCheck['blocked'];

            // 3b. Fully blocked → skip, days PAUSED
            if ($allowed <= 0) {
                $skipped++;
                continue;
            }

            // 3c. Credit DFI (atomic transaction)
            try {
                $pdo->beginTransaction();

                // Commission record
                $pdo->prepare("
                    INSERT INTO commissions
                      (user_id, type, amount, cap_deduction, description, status)
                    VALUES (?, 'daily_fixed_income', ?, ?, ?, 'credited')
                ")->execute([
                    $userId,
                    $allowed,
                    $blocked,
                    "Daily Fixed Income — Day {$dayNumber}"
                ]);
                $commId = (int)$pdo->lastInsertId();

                // E-wallet credit
                Ewallet::credit(
                    $userId,
                    $allowed,
                    $commId,
                    'commission',
                    "Daily Fixed Income — Day {$dayNumber}"
                );

                // Record against lifetime cap (triggers applyCap() if limit reached)
                CapEngine::recordEarning($userId, $allowed, 'daily_fixed_income');

                // Increment days used
                $pdo->prepare("
                    UPDATE users
                    SET dfi_days_used = dfi_days_used + 1
                    WHERE id = ?
                ")->execute([$userId]);

                // Log to DFI history
                $lifetimeCap = (float)$m['entry_fee'] * (float)$m['lifetime_cap_multiplier'];
                $remaining   = max(0, $lifetimeCap - (float)$m['lifetime_earned'] - $allowed);

                $pdo->prepare("
                    INSERT INTO daily_fixed_income_log
                      (user_id, amount, day_number, cap_status_at_payout, cap_remaining)
                    VALUES (?, ?, ?, 'active', ?)
                ")->execute([
                    $userId,
                    $allowed,
                    $dayNumber,
                    $remaining,
                ]);

                $pdo->commit();

                $processed++;
                $paid += $allowed;

            } catch (\Exception $e) {
                $pdo->rollBack();
                $skipped++;
                // Silent fail for this member — cron continues with others.
                // In production, consider logging to error_log.
            }
        }

        return [
            'processed' => $processed,
            'paid'      => round($paid, 2),
            'skipped'   => $skipped,
        ];
    }

    /**
     * Get DFI status for a single member.
     *
     * @param int $userId User ID
     * @return array [
     *   'total_dfi_earned' => float,
     *   'days_used'        => int,
     *   'days_remaining'   => int,
     *   'daily_rate'       => float,
     *   'next_payout_date' => ?string,
     *   'status'           => string,   // active | paused | capped | perminact | completed | disabled
     * ]
     */
    public static function getMemberDFIStatus(int $userId): array
    {
        $pdo = db();

        $st = $pdo->prepare("
            SELECT
                u.dfi_days_used,
                u.dfi_active,
                u.cap_status,
                p.daily_fixed_income,
                p.daily_fixed_income_days,
                p.dfi_enabled
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
        ");
        $st->execute([$userId]);
        $row = $st->fetch();

        if (!$row) {
            return [
                'total_dfi_earned' => 0.00,
                'days_used'        => 0,
                'days_remaining'   => 0,
                'daily_rate'       => 0.00,
                'next_payout_date' => null,
                'status'           => 'disabled',
            ];
        }

        $dailyRate = (float)$row['daily_fixed_income'];
        $daysUsed  = (int)$row['dfi_days_used'];
        $maxDays   = (int)$row['daily_fixed_income_days'];
        $capStatus = $row['cap_status'];
        $dfiActive = (int)$row['dfi_active'];

        // Total DFI ever earned
        $earnedSt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM daily_fixed_income_log
            WHERE user_id = ?
        ");
        $earnedSt->execute([$userId]);
        $totalEarned = (float)$earnedSt->fetchColumn();

        // Determine visual status
        $status = 'disabled';
        if ((int)$row['dfi_enabled'] !== 1) {
            $status = 'disabled';
        } elseif ($dailyRate <= 0) {
            $status = 'disabled';
        } elseif ($capStatus === 'capped') {
            $status = 'capped';
        } elseif ($capStatus === 'perminact') {
            $status = 'perminact';
        } elseif (!$dfiActive) {
            $status = 'paused';
        } elseif ($daysUsed >= $maxDays) {
            $status = 'completed';
        } else {
            $status = 'active';
        }

        // Next payout is tomorrow at midnight (Manila)
        $nextPayout = null;
        if ($status === 'active' && (int)$row['dfi_enabled'] === 1) {
            $nextPayout = date('Y-m-d 00:00:00', strtotime('+1 day'));
        }

        return [
            'total_dfi_earned' => $totalEarned,
            'days_used'        => $daysUsed,
            'days_remaining'   => max(0, $maxDays - $daysUsed),
            'daily_rate'       => $dailyRate,
            'next_payout_date' => $nextPayout,
            'status'           => $status,
        ];
    }

    /**
     * Get paginated DFI history for a member.
     *
     * @param int $userId User ID
     * @param int $page   Page number (1-based)
     * @return array Paginated result with data, total, page, per_page, total_pages
     */
    public static function getDFIHistory(int $userId, int $page = 1, int $perPage = 10): array
    {
        return paginate(
            "SELECT * FROM daily_fixed_income_log WHERE user_id = ? ORDER BY created_at DESC",
            [$userId],
            $page,
            $perPage
        );
    }

    /**
     * Admin: Global DFI statistics.
     *
     * @return array ['today' => float, 'total' => float, 'members' => int]
     */
    public static function adminStats(): array
    {
        $pdo = db();

        $today = (float)$pdo->query("
            SELECT COALESCE(SUM(amount), 0)
            FROM daily_fixed_income_log
            WHERE DATE(created_at) = CURDATE()
        ")->fetchColumn();

        $total = (float)$pdo->query("
            SELECT COALESCE(SUM(amount), 0)
            FROM daily_fixed_income_log
        ")->fetchColumn();

        $members = (int)$pdo->query("
            SELECT COUNT(DISTINCT user_id)
            FROM daily_fixed_income_log
        ")->fetchColumn();

        return [
            'today'   => $today,
            'total'   => $total,
            'members' => $members,
        ];
    }
}
