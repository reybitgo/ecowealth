<?php
class ShopPaymentProof
{
    /** Append-only; outcome lifecycle lives on each row (plan §2.6). */
    public static function latestFor(int $orderId): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_payment_proofs WHERE order_id = ? ORDER BY attempt_no DESC, id DESC LIMIT 1");
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }

    public static function byOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_payment_proofs WHERE order_id = ? ORDER BY attempt_no ASC, id ASC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }

    /** One row per upload attempt; attempt_no is derived, never trusted from input. */
    public static function add(int $orderId, string $filePath, array $opts = []): int
    {
        $pdo = db();
        $latest    = self::latestFor($orderId);
        $attempt   = $latest ? ((int) $latest['attempt_no'] + 1) : 1;
        $st = $pdo->prepare(
            "INSERT INTO shop_payment_proofs
                (order_id, attempt_no, proof_image, reference_no, amount_sent, transfer_date, uploaded_by, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $orderId,
            $attempt,
            $filePath,
            $opts['reference_no'] ?? null,
            $opts['amount'] ?? null,
            $opts['transfer_date'] ?? null,
            $opts['uploaded_by'] ?? null,
            $opts['ip'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function markVerified(int $proofId, int $adminId): void
    {
        db()->prepare("UPDATE shop_payment_proofs SET status = 'verified', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$adminId, $proofId]);
    }

    public static function markRejected(int $proofId, int $adminId, string $reason): void
    {
        db()->prepare("UPDATE shop_payment_proofs SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), reject_reason = ? WHERE id = ?")
            ->execute([$adminId, mb_substr($reason, 0, 160), $proofId]);
    }

    public static function attemptCount(int $orderId): int
    {
        $st = db()->prepare("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = ?");
        $st->execute([$orderId]);
        return (int) $st->fetchColumn();
    }

    /** Find one proof belonging to an order (for the streamed viewer). */
    public static function findForOrder(int $proofId, int $orderId): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_payment_proofs WHERE id = ? AND order_id = ?");
        $st->execute([$proofId, $orderId]);
        return $st->fetch() ?: null;
    }
}
