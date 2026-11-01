<?php

/**
 * @file   models/Ewallet.php
 * @brief  E-wallet management model
 */
class Ewallet
{
    /**
     * Every value ewallet_ledger.ref_type accepts.
     *
     * SINGLE SOURCE OF TRUTH. Both credit() and debit() validate against this,
     * and it must stay identical to the ENUM in install.sql / the migrations.
     *
     * Two defects came from two hand-maintained lists drifting away from the
     * column in opposite directions:
     *   C2 — 'shop_order' was in the ENUM but missing from both lists, so a
     *        shop refund could never be credited.
     *   C7 — 'upgrade' was in credit()'s list but NOT in the ENUM, so
     *        Ewallet::credit(…, 'upgrade', …) died with
     *        "Data truncated for column 'ref_type'" and rolled back every
     *        e-wallet package upgrade (models/User.php:434).
     *
     * tests/SchemaDriftTest.php asserts this constant and the live ENUM agree,
     * in both directions. Adding a value here without the migration, or the
     * reverse, fails the suite.
     */
    public const VALID_REF_TYPES = [
        'commission', 'payout', 'reactivation', 'transfer', 'topup',
        'registration', 'upgrade', 'shop_order',
    ];

    /** @throws InvalidArgumentException when $refType is not a ledger value. */
    private static function assertRefType(string $refType): void
    {
        if (!in_array($refType, self::VALID_REF_TYPES, true)) {
            throw new InvalidArgumentException("Invalid ref_type: {$refType}");
        }
    }

    /**
     * Credit amount to user's e-wallet and log to ledger.
     *
     * @param bool $withdrawable If true, also increments withdrawable_balance.
     */
    public static function credit(
        int $userId,
        float $amount,
        int $refId,
        string $refType,
        string $note = '',
        bool $withdrawable = true
    ): void {
        self::assertRefType($refType);
        $pdo = db();

        if ($withdrawable) {
            $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance + ?, withdrawable_balance = withdrawable_balance + ? WHERE id = ?')
                ->execute([$amount, $amount, $userId]);
        } else {
            $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance + ? WHERE id = ?')
                ->execute([$amount, $userId]);
        }

        $bal = (float) $pdo->query("SELECT ewallet_balance FROM users WHERE id = {$userId}")
            ->fetchColumn();

        $pdo->prepare("
            INSERT INTO ewallet_ledger
              (user_id, type, amount, reference_id, ref_type, balance_after, note)
            VALUES (?, 'credit', ?, ?, ?, ?, ?)
        ")->execute([$userId, $amount, $refId, $refType, $bal, $note]);
    }

    /**
     * Debit amount from user's e-wallet.
     * Returns false if balance is insufficient.
     *
     * @param bool $withdrawable If true, also decrements withdrawable_balance.
     */
    public static function debit(
        int $userId,
        float $amount,
        int $refId,
        string $refType,
        string $note = '',
        bool $withdrawable = true
    ): bool {
        self::assertRefType($refType);
        $pdo = db();

        // Lock the row and check balance atomically
        $st = $pdo->prepare('SELECT ewallet_balance, withdrawable_balance FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);
        $row = $st->fetch();
        $bal = (float) ($row['ewallet_balance'] ?? 0);

        if ($bal < $amount) return false;

        if ($withdrawable) {
            $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance - ?, withdrawable_balance = withdrawable_balance - ? WHERE id = ?')
                ->execute([$amount, $amount, $userId]);
        } else {
            $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance - ? WHERE id = ?')
                ->execute([$amount, $userId]);
        }

        $newBal = $bal - $amount;

        $pdo->prepare("
            INSERT INTO ewallet_ledger
              (user_id, type, amount, reference_id, ref_type, balance_after, note)
            VALUES (?, 'debit', ?, ?, ?, ?, ?)
        ")->execute([$userId, $amount, $refId, $refType, $newBal, $note]);

        return true;
    }

    /**
     * Debit for internal transactions (reactivation, transfer out, etc.).
     * Spends non-withdrawable funds first, then withdrawable if needed.
     * Returns false if total balance is insufficient.
     */
    public static function debitInternal(int $userId, float $amount, int $refId, string $refType, string $note = ''): bool
    {
        $pdo = db();

        $st = $pdo->prepare('SELECT ewallet_balance, withdrawable_balance FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);
        $row = $st->fetch();

        $total = (float) ($row['ewallet_balance'] ?? 0);
        $withdrawable = (float) ($row['withdrawable_balance'] ?? 0);

        if ($total < $amount) return false;

        $nonWithdrawable = $total - $withdrawable;
        $fromNonWithdrawable = min($amount, $nonWithdrawable);
        $fromWithdrawable = $amount - $fromNonWithdrawable;

        $pdo->prepare("
            UPDATE users
            SET ewallet_balance = ewallet_balance - :amt,
                withdrawable_balance = withdrawable_balance - :wamt
            WHERE id = :id
        ")->execute([':amt' => $amount, ':wamt' => $fromWithdrawable, ':id' => $userId]);

        $newBal = $total - $amount;

        $pdo->prepare("
            INSERT INTO ewallet_ledger
              (user_id, type, amount, reference_id, ref_type, balance_after, note)
            VALUES (?, 'debit', ?, ?, ?, ?, ?)
        ")->execute([$userId, $amount, $refId, $refType, $newBal, $note]);

        return true;
    }

    /**
     * Get paginated ledger entries for a user.
     */
    public static function ledger(int $userId, int $page = 1, int $perPage = 20): array
    {
        return paginate(
            "SELECT * FROM ewallet_ledger WHERE user_id = ? ORDER BY created_at DESC",
            [$userId],
            $page,
            $perPage
        );
    }

    /**
     * Current balance — always reads fresh from DB.
     */
    public static function balance(int $userId): float
    {
        return (float) db()
            ->query("SELECT ewallet_balance FROM users WHERE id = {$userId}")
            ->fetchColumn();
    }

    /**
     * Transfer e-wallet funds from sender to recipient.
     * Members pay a flat fee; admins transfer for free.
     * Respects daily/weekly limits for members.
     *
     * @return array ['ok' => bool, 'error' => string|null, 'transfer_id' => int|null]
     */
    public static function transfer(int $senderId, int $recipientId, float $amount, string $note = ''): array
    {
        $amount = round(max(0, $amount), 2);
        $minTransfer = (float) setting('ewallet_min_transfer', '50.00');

        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'Invalid amount.', 'transfer_id' => null];
        }
        if ($amount < $minTransfer) {
            return ['ok' => false, 'error' => 'Minimum transfer is ' . fmt_money($minTransfer) . '.', 'transfer_id' => null];
        }

        $sender    = User::find($senderId);
        $recipient = User::find($recipientId);

        // Superadmin actions are attributed to the primary admin account —
        // funds physically come from the admin's wallet.
        if (($sender['role'] ?? '') === 'superadmin') {
            $resolvedAdminId = (int) db()->query(
                "SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
            )->fetchColumn();
            if ($resolvedAdminId > 0) {
                $senderId = $resolvedAdminId;
                $sender   = User::find($senderId);
            }
        }

        if ($senderId === (int) $recipientId) {
            return ['ok' => false, 'error' => 'You cannot transfer to yourself.', 'transfer_id' => null];
        }

        // Fee: only members pay
        $isAdmin = in_array($sender['role'] ?? '', ['admin', 'superadmin'], true);
        $fee = $isAdmin ? 0.00 : round((float) setting('ewallet_transfer_fee', '0.00'), 2);
        $totalDebit = $amount + $fee;

        // Limits: only members are checked
        if (!$isAdmin) {
            $dailyLimit  = (float) setting('ewallet_transfer_daily_limit', '5000.00');
            $weeklyLimit = (float) setting('ewallet_transfer_weekly_limit', '20000.00');

            // Read cached tracking counters (reset daily by cron/fund_transfer_limit_reset.php)
            $limits = db()->query("
                SELECT ewallet_sent_today, ewallet_sent_this_week
                FROM users WHERE id = {$senderId}
            ")->fetch();
            $sentToday    = (float) ($limits['ewallet_sent_today'] ?? 0);
            $sentThisWeek = (float) ($limits['ewallet_sent_this_week'] ?? 0);

            if (($sentToday + $amount) > $dailyLimit) {
                return ['ok' => false, 'error' => 'Daily transfer limit exceeded.', 'transfer_id' => null];
            }
            if (($sentThisWeek + $amount) > $weeklyLimit) {
                return ['ok' => false, 'error' => 'Weekly transfer limit exceeded.', 'transfer_id' => null];
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock sender and check balance
            $st = $pdo->prepare('SELECT ewallet_balance, withdrawable_balance FROM users WHERE id = ? FOR UPDATE');
            $st->execute([$senderId]);
            $senderRow = $st->fetch();
            $bal = (float) ($senderRow['ewallet_balance'] ?? 0);
            $withdrawableBal = (float) ($senderRow['withdrawable_balance'] ?? 0);

            if ($bal < $totalDebit) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Insufficient balance.', 'transfer_id' => null];
            }

            // Debit sender: non-withdrawable funds are spent first, then withdrawable.
            // Transferred funds remain non-withdrawable for the recipient.
            $fromWithdrawable = min($totalDebit, $withdrawableBal);

            // Build update fields — include limit tracking for members
            $limitFields = '';
            $limitParams = [];
            if (!$isAdmin) {
                $limitFields = ', ewallet_sent_today = ewallet_sent_today + :sentToday, ewallet_sent_this_week = ewallet_sent_this_week + :sentWeek';
                $limitParams = [':sentToday' => $amount, ':sentWeek' => $amount];
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET ewallet_balance = ewallet_balance - :amt,
                    withdrawable_balance = withdrawable_balance - :wamt
                    {$limitFields}
                WHERE id = :id
            ");
            $executeParams = array_merge([':amt' => $totalDebit, ':wamt' => $fromWithdrawable, ':id' => $senderId], $limitParams);
            $stmt->execute($executeParams);

            // Credit recipient (non-withdrawable)
            $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance + ? WHERE id = ?')
                ->execute([$amount, $recipientId]);

            // Credit fee to primary admin account (first admin in users table) — non-withdrawable
            if ($fee > 0) {
                $adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($adminId) {
                    $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance + ? WHERE id = ?')
                        ->execute([$fee, $adminId]);
                }
            }

            // Record transfer
            $pdo->prepare("
                INSERT INTO ewallet_transfers
                  (sender_id, recipient_id, amount, fee, net_amount, note)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$senderId, $recipientId, $amount, $fee, $amount, $note]);
            $transferId = (int) $pdo->lastInsertId();

            // Ledger: sender debit
            $senderBal = (float) $pdo->query("SELECT ewallet_balance FROM users WHERE id = {$senderId}")->fetchColumn();
            $pdo->prepare("
                INSERT INTO ewallet_ledger
                  (user_id, type, amount, reference_id, ref_type, balance_after, note)
                VALUES (?, 'debit', ?, ?, 'transfer', ?, ?)
            ")->execute([$senderId, $totalDebit, $transferId, $senderBal,
                "Transfer to @" . ($recipient['username'] ?? $recipientId) . ($note ? " — {$note}" : '')
            ]);

            // Ledger: recipient credit
            $recipientBal = (float) $pdo->query("SELECT ewallet_balance FROM users WHERE id = {$recipientId}")->fetchColumn();
            $pdo->prepare("
                INSERT INTO ewallet_ledger
                  (user_id, type, amount, reference_id, ref_type, balance_after, note)
                VALUES (?, 'credit', ?, ?, 'transfer', ?, ?)
            ")->execute([$recipientId, $amount, $transferId, $recipientBal,
                "Transfer from @" . ($sender['username'] ?? $senderId) . ($note ? " — {$note}" : '')
            ]);

            // Ledger: fee credit to admin
            if ($fee > 0 && !empty($adminId)) {
                $adminBal = (float) $pdo->query("SELECT ewallet_balance FROM users WHERE id = {$adminId}")
                    ->fetchColumn();
                $pdo->prepare("
                    INSERT INTO ewallet_ledger
                      (user_id, type, amount, reference_id, ref_type, balance_after, note)
                    VALUES (?, 'credit', ?, ?, 'transfer', ?, ?)
                ")->execute([$adminId, $fee, $transferId, $adminBal,
                    "Transfer fee from @" . ($sender['username'] ?? $senderId) . " to @" . ($recipient['username'] ?? $recipientId)
                ]);
            }

            $pdo->commit();
            return ['ok' => true, 'error' => null, 'transfer_id' => $transferId];
        } catch (\Exception $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Transfer failed. Please try again.', 'transfer_id' => null];
        }
    }

    /**
     * Admin top-up: credit recipient e-wallet from thin air.
     *
     * @return array ['ok' => bool, 'error' => string|null, 'topup_id' => int|null]
     */
    public static function adminTopUp(int $adminId, int $recipientId, float $amount, string $note = ''): array
    {
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'Invalid amount.', 'topup_id' => null];
        }

        $admin = User::find($adminId);
        if (!$admin || !in_array($admin['role'], ['admin', 'superadmin'], true)) {
            return ['ok' => false, 'error' => 'Unauthorized.', 'topup_id' => null];
        }

        $recipient = User::find($recipientId);
        if (!$recipient) {
            return ['ok' => false, 'error' => 'Recipient not found.', 'topup_id' => null];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Credit recipient (no debit — funds created, non-withdrawable)
            $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance + ? WHERE id = ?')
                ->execute([$amount, $recipientId]);

            // Record top-up
            $pdo->prepare("
                INSERT INTO ewallet_admin_topups (admin_id, recipient_id, amount, note)
                VALUES (?, ?, ?, ?)
            ")->execute([$adminId, $recipientId, $amount, $note]);
            $topupId = (int) $pdo->lastInsertId();

            // Ledger: recipient credit
            $recipientBal = (float) $pdo->query("SELECT ewallet_balance FROM users WHERE id = {$recipientId}")
                ->fetchColumn();
            $pdo->prepare("
                INSERT INTO ewallet_ledger
                  (user_id, type, amount, reference_id, ref_type, balance_after, note)
                VALUES (?, 'credit', ?, ?, 'topup', ?, ?)
            ")->execute([$recipientId, $amount, $topupId, $recipientBal,
                "Admin top-up by @" . ($admin['username'] ?? $adminId) . ($note ? " — {$note}" : '')
            ]);

            $pdo->commit();
            return ['ok' => true, 'error' => null, 'topup_id' => $topupId];
        } catch (\Exception $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Top-up failed.', 'topup_id' => null];
        }
    }
}
