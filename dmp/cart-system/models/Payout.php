<?php

/**
 * @file   models/Payout.php
 * @brief  Payout management model
 */
class Payout
{
    public static function request(int $userId, float $amount, string $method = 'gcash', string $account = '', float $usdtRate = 0): array
    {
        $minPayout = (float)setting('min_payout', '500');
        $balance   = Ewallet::balance($userId);
        $withdrawable = (float) db()->query("SELECT withdrawable_balance FROM users WHERE id = {$userId}")->fetchColumn();

        if ($amount < $minPayout) {
            return ['ok' => false, 'error' => "Minimum payout is " . fmt_money($minPayout)];
        }
        if ($amount > $withdrawable) {
            return ['ok' => false, 'error' => "Withdrawable balance insufficient. You can withdraw up to " . fmt_money($withdrawable) . "."];
        }
        if ($amount > $balance) {
            return ['ok' => false, 'error' => "Insufficient balance. Available: " . fmt_money($balance)];
        }

        // Check no pending request already
        $pending = db()->query("
            SELECT COUNT(*) FROM payout_requests
            WHERE user_id = {$userId} AND status = 'pending'
        ")->fetchColumn();
        if ($pending > 0) {
            return ['ok' => false, 'error' => 'You already have a pending payout request.'];
        }

        // Calculate service fee for this method
        $feePct    = (float)setting('service_fee_' . $method, '0');
        $feeAmount = round($amount * $feePct / 100, 2);
        $netAmount = $amount - $feeAmount;

        // Calculate USDT amount for USDT payouts
        $gasFeeUsdt = 0.0;
        $usdtAmount = 0.0;
        if (($method === 'usdt_trc20' || $method === 'usdt_bep20') && $usdtRate > 0) {
            $gasFeeKey  = $method === 'usdt_bep20' ? 'usdt_bep20_gas_fee' : 'usdt_trc20_gas_fee';
            $gasFeeUsdt = (float)setting($gasFeeKey, $method === 'usdt_bep20' ? '0.05' : '2.50');
            $netPhp     = $netAmount - ($gasFeeUsdt * $usdtRate);
            $usdtAmount = $netPhp > 0 ? round($netPhp / $usdtRate, 4) : 0;
        }

        db()->prepare("
            INSERT INTO payout_requests
              (user_id, amount, payout_method, payout_account,
               service_fee_pct, service_fee_amount,
               usdt_trc20_rate, usdt_trc20_gas_fee, usdt_trc20_amount,
               usdt_bep20_rate, usdt_bep20_gas_fee, usdt_bep20_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $userId,
            $amount,
            $method,
            $account,
            $feePct,
            $feeAmount,
            $method === 'usdt_trc20' ? $usdtRate : 0,
            $method === 'usdt_trc20' ? $gasFeeUsdt : 0,
            $method === 'usdt_trc20' ? $usdtAmount : 0,
            $method === 'usdt_bep20' ? $usdtRate : 0,
            $method === 'usdt_bep20' ? $gasFeeUsdt : 0,
            $method === 'usdt_bep20' ? $usdtAmount : 0,
        ]);

        return ['ok' => true, 'id' => (int)db()->lastInsertId()];
    }

    public static function approve(int $payoutId, int $adminId): bool
    {
        $st = db()->prepare("
            UPDATE payout_requests
            SET status = 'approved', processed_by = ?, processed_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $st->execute([$adminId, $payoutId]);
        return $st->rowCount() > 0;
    }

    public static function reject(int $payoutId, int $adminId, string $reason): bool
    {
        $st = db()->prepare("
            UPDATE payout_requests
            SET status = 'rejected', processed_by = ?, admin_note = ?, processed_at = NOW()
            WHERE id = ? AND status IN ('pending','approved')
        ");
        $st->execute([$adminId, $reason, $payoutId]);
        return $st->rowCount() > 0;
    }

    /**
     * Admin marks as completed AFTER manually sending money via GCash.
     * This is when the e-wallet balance is deducted.
     */
    public static function complete(int $payoutId, int $adminId, string $note = ''): array
    {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $req = $pdo->query(
                "SELECT * FROM payout_requests WHERE id = {$payoutId}"
            )->fetch();

            if (!$req) throw new RuntimeException('Payout request not found.');
            if ($req['status'] !== 'approved') {
                throw new RuntimeException('Only approved requests can be marked complete.');
            }

            $ok = Ewallet::debit(
                (int)$req['user_id'],
                (float)$req['amount'],
                $payoutId,
                'payout',
                'Payout via ' . strtoupper($req['payout_method'] ?? 'GCash') . ' ' . $req['payout_account']
            );
            if (!$ok) throw new RuntimeException('Insufficient e-wallet balance.');

            $pdo->prepare("
                UPDATE payout_requests
                SET status = 'completed', processed_by = ?, admin_note = ?, processed_at = NOW()
                WHERE id = ?
            ")->execute([$adminId, $note, $payoutId]);

            $pdo->commit();
            return ['ok' => true];
        } catch (\Exception $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare("
            SELECT pr.*, u.username, u.full_name,
                   a.username AS admin_username
            FROM   payout_requests pr
            JOIN   users u ON u.id = pr.user_id
            LEFT JOIN users a ON a.id = pr.processed_by
            WHERE  pr.id = ?
        ");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function forUser(int $userId, int $page = 1, int $perPage = 10): array
    {
        return paginate(
            "SELECT * FROM payout_requests WHERE user_id = ? ORDER BY requested_at DESC",
            [$userId],
            $page,
            $perPage
        );
    }

    public static function all(int $page = 1, string $status = '', int $perPage = 10): array
    {
        $where  = '1=1';
        $params = [];
        if ($status) {
            $where .= ' AND pr.status = ?';
            $params[] = $status;
        }

        return paginate(
            "SELECT pr.*, u.username, u.full_name, a.username AS admin_username
             FROM   payout_requests pr
             JOIN   users u ON u.id = pr.user_id
             LEFT JOIN users a ON a.id = pr.processed_by
             WHERE  {$where}
             ORDER BY pr.requested_at DESC",
            $params,
            $page,
            $perPage
        );
    }

    public static function pendingTotal(): float
    {
        return (float)db()->query(
            "SELECT COALESCE(SUM(amount),0) FROM payout_requests WHERE status='pending'"
        )->fetchColumn();
    }

    public static function totalPaid(): float
    {
        return (float)db()->query(
            "SELECT COALESCE(SUM(amount),0) FROM payout_requests WHERE status='completed'"
        )->fetchColumn();
    }
}
