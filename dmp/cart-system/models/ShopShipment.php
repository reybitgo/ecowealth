<?php
class ShopShipment
{
    /**
     * Shipments hold facts (courier, tracking, POD); the order's STATUS moves
     * only through ShopOrder::transition() — no method here may write
     * shop_orders.status (plan §2.5).
     */

    /** The only shipment row that may move the order. */
    public static function activeFor(int $orderId): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_shipments WHERE order_id = ? AND state = 'active' ORDER BY seq DESC LIMIT 1");
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_shipments WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function byOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_shipments WHERE order_id = ? ORDER BY seq ASC, id ASC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }

    /** Latest shipment regardless of state — reship linkage after RTS closed it. */
    public static function latestFor(int $orderId): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_shipments WHERE order_id = ? ORDER BY seq DESC, id DESC LIMIT 1");
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }

    public static function create(int $orderId, array $data = []): int
    {
        $st = db()->prepare("INSERT INTO shop_shipments (order_id, seq, reship_of, state) VALUES (?, ?, ?, 'active')");
        $st->execute([
            $orderId,
            $data['seq'] ?? self::nextSeq($orderId),
            $data['reship_of'] ?? null,
        ]);
        return (int) db()->lastInsertId();
    }

    public static function touch(int $id, array $data): void
    {
        $allowed = ['courier', 'tracking_number', 'expected_delivery_at', 'out_for_delivery_at',
                    'delivered_at', 'handoff_at', 'response_deadline', 'report_window_end',
                    'pod_receiver', 'pod_image', 'fail_reason', 'fault'];
        $sets = [];
        $vals = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[] = "{$k} = ?";
                $vals[] = $v;
            }
        }
        if (!$sets) return;
        $vals[] = $id;
        db()->prepare("UPDATE shop_shipments SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
    }

    public static function close(int $id, string $state = 'closed'): void
    {
        db()->prepare("UPDATE shop_shipments SET state = ?, closed_at = NOW() WHERE id = ? AND state = 'active'")
            ->execute([$state, $id]);
    }

    /**
     * Open-or-update the active shipment's tracking. Idempotent by
     * order+seq: re-saving the same courier/tracking updates the row, a
     * refused handoff leaves nothing half-created (the caller voids it).
     */
    public static function upsertTracking(int $orderId, string $courier, string $tracking, ?string $expectedAt = null): int
    {
        // Boundary hardening: an empty-string datetime from any caller would
        // otherwise hit MySQL as '' and throw 22007 — treat it as absent so
        // both the INSERT and touch()/UPDATE paths write NULL.
        $expectedAt = is_string($expectedAt) && trim($expectedAt) === '' ? null : $expectedAt;
        $pdo = db();
        $active = self::activeFor($orderId);
        if ($active) {
            self::touch((int) $active['id'], [
                'courier'              => $courier,
                'tracking_number'      => $tracking,
                'expected_delivery_at' => $expectedAt,
            ]);
            return (int) $active['id'];
        }
        $seq = self::nextSeq($orderId);
        $st  = $pdo->prepare("INSERT INTO shop_shipments (order_id, seq, courier, tracking_number, expected_delivery_at, state) VALUES (?, ?, ?, ?, ?, 'active')");
        $st->execute([$orderId, $seq, $courier, $tracking, $expectedAt]);
        return (int) $pdo->lastInsertId();
    }

    public static function recordAttempt(int $id, string $fault, string $reason): void
    {
        db()->prepare("UPDATE shop_shipments SET attempts = attempts + 1, fault = ?, fail_reason = ? WHERE id = ?")
            ->execute([$fault, mb_substr($reason, 0, 160), $id]);
    }

    public static function savePod(int $id, array $pod): void
    {
        self::touch($id, $pod);
    }

    public static function nextSeq(int $orderId): int
    {
        $st = db()->prepare("SELECT COALESCE(MAX(seq), 0) + 1 FROM shop_shipments WHERE order_id = ?");
        $st->execute([$orderId]);
        return (int) $st->fetchColumn();
    }
}
