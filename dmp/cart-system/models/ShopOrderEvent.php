<?php
class ShopOrderEvent
{
    /**
     * Append-only audit writer (plan §2.4). There is intentionally NO update()
     * and NO delete(): event history is immutable, so do not add cleanup
     * methods later without revisiting the plan's audit contract first.
     */
    public static function log(int $orderId, ?string $from, string $to, string $actorType, ?int $actorId = null, array $opts = []): int
    {
        $pdo = db();
        $st = $pdo->prepare("INSERT INTO shop_order_events (order_id, from_status, to_status, actor_type, actor_id, source, reason_code, note, meta_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $meta = $opts['meta_json'] ?? null;
        if (is_array($meta)) {
            $meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
        }
        $st->execute([
            $orderId,
            $from,
            $to,
            $actorType,
            $actorId,
            $opts['source'] ?? 'ui',
            $opts['reason_code'] ?? null,
            $opts['note'] ?? null,
            $meta,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function forOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_order_events WHERE order_id = ? ORDER BY created_at DESC, id DESC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }

    public static function recent(int $limit = 50): array
    {
        $st = db()->prepare("SELECT * FROM shop_order_events ORDER BY created_at DESC, id DESC LIMIT ?");
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
