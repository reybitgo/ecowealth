<?php
declare(strict_types=1);

/* ==============================================================
 *  OrderEvent — append-only order status trail (plan §4.4, Phase 6)
 *
 *  One row per transition: from → to, who did it, why and when.
 *  There is no update/delete path; an order's history is the union
 *  of its rows (P6-T12).
 * ============================================================== */

final class OrderEvent
{
    public const ACTOR_TYPES = ['member', 'admin', 'system'];

    /**
     * Append one event.
     *
     * @param string      $actorType member | admin | system
     * @param string|null $fromStatus null for the first event
     * @param string      $toStatus   the status after the transition
     * @param string      $reasonCode short machine code (order_placed, paid, member_cancelled, …)
     */
    public static function log(
        int $orderId,
        ?string $fromStatus,
        string $toStatus,
        string $actorType,
        ?int $actorId = null,
        string $reasonCode = '',
        string $note = '',
        ?PDO $pdo = null
    ): int {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('An order event needs an order id.');
        }

        $actorType = in_array($actorType, self::ACTOR_TYPES, true) ? $actorType : 'system';
        $toStatus  = mb_substr(trim($toStatus), 0, 20);
        $from      = $fromStatus === null ? null : mb_substr(trim($fromStatus), 0, 20);
        $reason    = trim($reasonCode) === '' ? null : mb_substr(trim($reasonCode), 0, 40);
        $note      = trim($note) === '' ? null : mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? $note), 0, 500);

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            'INSERT INTO shop_order_events
               (order_id, from_status, to_status, actor_type, actor_id, reason_code, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, $from, $toStatus, $actorType, $actorId, $reason, $note]);

        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public static function forOrder(int $orderId, ?PDO $pdo = null): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT e.*, u.username AS actor_username
               FROM shop_order_events e
               LEFT JOIN users u ON u.id = e.actor_id
              WHERE e.order_id = ?
              ORDER BY e.id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }
}
