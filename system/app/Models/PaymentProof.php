<?php
declare(strict_types=1);

/* ==============================================================
 *  PaymentProof — manual payment evidence (plan §4.2, §5.4; Phase 7)
 *
 *  One row per upload attempt, belonging to exactly one order. The
 *  row is an audit record of what the member claimed (amount,
 *  reference, transfer date) and of the admin's decision:
 *
 *      pending ──(admin verifies)──▶ verified
 *      pending ──(admin rejects)───▶ rejected   (correction window)
 *
 *  Rows are never deleted or re-pointed: `attempt_no` is the only
 *  ordering, and the attempt cap in PaymentService reads it. Writes
 *  happen inside the caller's transaction — PaymentService owns the
 *  order lock, so the derived attempt number cannot race.
 * ============================================================== */

final class PaymentProof
{
    public const STATUSES = ['pending', 'verified', 'rejected'];

    /**
     * Insert one attempt.
     *
     * @param array{
     *   file_path: string,
     *   mime?: string|null,
     *   size_bytes?: int|null,
     *   amount_claimed?: float|null,
     *   reference_no?: string|null,
     *   transfer_date?: string|null
     * } $proof
     */
    public static function create(int $orderId, array $proof, ?PDO $pdo = null): int
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('A payment proof needs an order id.');
        }

        $pdo  = $pdo ?? db();
        $path = trim((string) ($proof['file_path'] ?? ''));

        if ($path === '') {
            throw new InvalidArgumentException('A payment proof needs a stored file.');
        }

        $attempt = self::nextAttempt($orderId, $pdo);

        $pdo->prepare(
            'INSERT INTO shop_payment_proofs
               (order_id, attempt_no, file_path, mime, size_bytes, amount_claimed,
                reference_no, transfer_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\')'
        )->execute([
            $orderId,
            $attempt,
            mb_substr($path, 0, 255),
            self::nullable($proof['mime'] ?? null, 40),
            isset($proof['size_bytes']) && (int) $proof['size_bytes'] > 0 ? (int) $proof['size_bytes'] : null,
            isset($proof['amount_claimed']) && $proof['amount_claimed'] !== null
                ? number_format((float) $proof['amount_claimed'], 2, '.', '')
                : null,
            self::nullable($proof['reference_no'] ?? null, 40),
            self::nullable($proof['transfer_date'] ?? null, 10),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Attempt number for the next upload (max + 1). */
    public static function nextAttempt(int $orderId, ?PDO $pdo = null): int
    {
        $stmt = ($pdo ?? db())->prepare(
            'SELECT COALESCE(MAX(attempt_no), 0) FROM shop_payment_proofs WHERE order_id = ?'
        );
        $stmt->execute([$orderId]);

        return max(1, (int) $stmt->fetchColumn() + 1);
    }

    /** How many uploads the order has (the attempt cap reads this). */
    public static function countFor(int $orderId, ?PDO $pdo = null): int
    {
        if ($orderId <= 0) {
            return 0;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = ?'
        );
        $stmt->execute([$orderId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> Oldest attempt first. */
    public static function forOrder(int $orderId, ?PDO $pdo = null): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT p.*, u.username AS reviewer_username
               FROM shop_payment_proofs p
               LEFT JOIN users u ON u.id = p.reviewed_by
              WHERE p.order_id = ?
              ORDER BY p.attempt_no ASC, p.id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null The newest attempt. */
    public static function latestFor(int $orderId, ?PDO $pdo = null): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM shop_payment_proofs WHERE order_id = ?
              ORDER BY attempt_no DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * One proof plus its order (the guarded streaming route needs the
     * order number for the download filename).
     *
     * @return array<string, mixed>|null
     */
    public static function findWithOrder(int $proofId, ?PDO $pdo = null): ?array
    {
        if ($proofId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT p.*, o.order_no, o.user_id AS order_user_id, o.status AS order_status
               FROM shop_payment_proofs p
               JOIN shop_orders o ON o.id = p.order_id
              WHERE p.id = ?
              LIMIT 1'
        );
        $stmt->execute([$proofId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Mark one attempt verified. */
    public static function markVerified(int $proofId, int $adminId, ?PDO $pdo = null): void
    {
        ($pdo ?? db())->prepare(
            "UPDATE shop_payment_proofs
                SET status = 'verified', reviewed_by = ?, reviewed_at = NOW(), reject_reason = NULL
              WHERE id = ? AND status = 'pending'"
        )->execute([$adminId > 0 ? $adminId : null, $proofId]);
    }

    /** Mark one attempt rejected with the reason the admin gave. */
    public static function markRejected(int $proofId, int $adminId, string $reason, ?PDO $pdo = null): void
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? $reason);

        ($pdo ?? db())->prepare(
            "UPDATE shop_payment_proofs
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), reject_reason = ?
              WHERE id = ? AND status = 'pending'"
        )->execute([
            $adminId > 0 ? $adminId : null,
            $reason === '' ? null : mb_substr($reason, 0, 160),
            $proofId,
        ]);
    }

    /**
     * Is this transfer reference already claimed by another order?
     *
     * One transfer can only pay one order, so the reference is checked
     * across both places it can live: a paid order's
     * `payment_reference` and any non-rejected proof on a different
     * order (a rejected attempt releases its reference again).
     */
    public static function referenceTakenByAnotherOrder(
        string $reference,
        int $excludeOrderId,
        ?PDO $pdo = null
    ): bool {
        $reference = trim($reference);

        if ($reference === '') {
            return false;
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM shop_orders
              WHERE payment_reference = ? AND id <> ? AND payment_reference IS NOT NULL'
        );
        $stmt->execute([$reference, $excludeOrderId]);

        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM shop_payment_proofs
              WHERE reference_no = ? AND order_id <> ? AND status <> 'rejected'"
        );
        $stmt->execute([$reference, $excludeOrderId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
