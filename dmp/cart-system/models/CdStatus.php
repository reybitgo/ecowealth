<?php

/**
 * @file   models/CdStatus.php
 * @brief  Commission-Deduct (CD) status model
 */
class CdStatus
{
    /**
     * Get the active CD status for a user, or null if none.
     */
    public static function getActive(int $userId): ?array
    {
        $st = db()->prepare('
            SELECT id, user_id, target_amount, filled_amount, status, assigned_at
            FROM user_cd_status
            WHERE user_id = ? AND status = "active"
            LIMIT 1
        ');
        $st->execute([$userId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Get CD history for a user (all statuses).
     */
    public static function history(int $userId): array
    {
        $st = db()->prepare('
            SELECT * FROM user_cd_status WHERE user_id = ? ORDER BY assigned_at DESC
        ');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /**
     * Assign a new CD status to an active user.
     */
    public static function assign(int $userId, float $targetAmount, int $assignedBy): array
    {
        $pdo = db();

        // Only one active CD at a time
        if (self::getActive($userId)) {
            throw new RuntimeException('User already has an active CD.');
        }

        $user = User::find($userId);
        if (!$user || $user['status'] !== 'active') {
            throw new RuntimeException('CD can only be assigned to active users.');
        }

        $pdo->prepare('
            INSERT INTO user_cd_status (user_id, target_amount, assigned_by)
            VALUES (?, ?, ?)
        ')->execute([$userId, $targetAmount, $assignedBy]);

        $pdo->prepare('UPDATE users SET cd_active = 1 WHERE id = ?')->execute([$userId]);

        return self::getActive($userId);
    }

    /**
     * Complete an active CD (manual or auto).
     */
    public static function complete(int $cdStatusId, int $userId): void
    {
        $pdo = db();
        $pdo->prepare('
            UPDATE user_cd_status
            SET status = "completed", completed_at = NOW()
            WHERE id = ? AND user_id = ? AND status = "active"
        ')->execute([$cdStatusId, $userId]);

        $pdo->prepare('UPDATE users SET cd_active = 0 WHERE id = ?')->execute([$userId]);
    }

    /**
     * Cancel an active CD. Already-filled amount is forfeited.
     */
    public static function cancel(int $cdStatusId, int $userId, string $notes = ''): void
    {
        $pdo = db();
        $pdo->prepare('
            UPDATE user_cd_status
            SET status = "cancelled", cancelled_at = NOW(), notes = ?
            WHERE id = ? AND user_id = ? AND status = "active"
        ')->execute([$notes, $cdStatusId, $userId]);

        $pdo->prepare('UPDATE users SET cd_active = 0 WHERE id = ?')->execute([$userId]);
    }

    /**
     * Update target amount when package changes.
     */
    public static function updateTarget(int $userId, float $newTarget): void
    {
        $pdo = db();
        $pdo->prepare('
            UPDATE user_cd_status
            SET target_amount = ?
            WHERE user_id = ? AND status = "active"
        ')->execute([$newTarget, $userId]);
    }

    /**
     * Record a CD ledger entry.
     */
    public static function recordLedger(
        int $userId,
        int $cdStatusId,
        ?int $commissionId,
        string $type,
        float $grossAmount,
        float $cdAmount,
        float $withdrawableAmount,
        ?int $sourceUserId = null
    ): void {
        db()->prepare('
            INSERT INTO cd_ledger
              (user_id, cd_status_id, commission_id, type, gross_amount, cd_amount, withdrawable_amount, source_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $userId, $cdStatusId, $commissionId, $type,
            $grossAmount, $cdAmount, $withdrawableAmount, $sourceUserId
        ]);
    }

    /**
     * Get CD ledger entries for a user.
     */
    public static function ledger(int $userId, int $cdStatusId): array
    {
        $st = db()->prepare('
            SELECT l.*, u.username AS source_username
            FROM cd_ledger l
            LEFT JOIN users u ON u.id = l.source_user_id
            WHERE l.user_id = ? AND l.cd_status_id = ?
            ORDER BY l.created_at DESC
        ');
        $st->execute([$userId, $cdStatusId]);
        return $st->fetchAll();
    }

    /**
     * Add amount to the CD bucket and check completion.
     * Returns ['cd' => float, 'wallet' => float, 'completed' => bool].
     */
    public static function fillBucket(int $userId, float $grossAmount): array
    {
        $pdo = db();

        // Fast path
        $st = $pdo->prepare('SELECT cd_active FROM users WHERE id = ?');
        $st->execute([$userId]);
        if (!$st->fetchColumn()) {
            return ['cd' => 0.00, 'wallet' => $grossAmount, 'completed' => false];
        }

        $st = $pdo->prepare('
            SELECT id, target_amount, filled_amount
            FROM user_cd_status
            WHERE user_id = ? AND status = "active"
            FOR UPDATE
        ');
        $st->execute([$userId]);
        $cd = $st->fetch();

        if (!$cd) {
            // Stale flag
            $pdo->prepare('UPDATE users SET cd_active = 0 WHERE id = ?')->execute([$userId]);
            return ['cd' => 0.00, 'wallet' => $grossAmount, 'completed' => false];
        }

        $remaining = (float)$cd['target_amount'] - (float)$cd['filled_amount'];
        if ($remaining <= 0) {
            self::complete((int)$cd['id'], $userId);
            return ['cd' => 0.00, 'wallet' => $grossAmount, 'completed' => true];
        }

        $cdPortion     = min($grossAmount, $remaining);
        $walletPortion = $grossAmount - $cdPortion;
        $newFilled     = (float)$cd['filled_amount'] + $cdPortion;
        $isCompleted   = $newFilled >= (float)$cd['target_amount'];

        $pdo->prepare('
            UPDATE user_cd_status
            SET filled_amount = filled_amount + ?
            WHERE id = ?
        ')->execute([$cdPortion, $cd['id']]);

        if ($isCompleted) {
            self::complete((int)$cd['id'], $userId);
        }

        return [
            'cd'         => $cdPortion,
            'wallet'     => $walletPortion,
            'completed'  => $isCompleted,
            'cd_status_id' => (int)$cd['id'],
        ];
    }
}
