<?php
declare(strict_types=1);

/* ==============================================================
 *  Shipment — courier + tracking rows for shop orders (plan §4.2,
 *  §5.5; Phase 8)
 *
 *  One row is written when an order leaves `ready_to_ship`; the
 *  stock is deducted in the same transaction (FulfilmentService),
 *  so a shipment row and the physical movement always agree.
 *
 *      created  → delivery is in progress  (delivered_at NULL)
 *      updated  → the parcel was delivered (delivered_at stamped)
 *
 *  Rows are append-only in this build (no reshipment / RTS flow);
 *  `FulfilmentService` is the only writer.
 * ============================================================== */

final class Shipment
{
    /**
     * Insert the shipment for an order.
     *
     * @param array{courier?:string|null, tracking_number?:string|null, notes?:string|null} $data
     */
    public static function create(int $orderId, array $data = [], ?PDO $pdo = null): int
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('A shipment needs an order id.');
        }

        $pdo = $pdo ?? db();

        $pdo->prepare(
            'INSERT INTO shop_shipments (order_id, courier, tracking_number, notes)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $orderId,
            self::nullable($data['courier'] ?? null, 40),
            self::nullable($data['tracking_number'] ?? null, 80),
            self::nullable($data['notes'] ?? null, 255),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare('SELECT * FROM shop_shipments WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null The newest shipment of an order. */
    public static function latestFor(int $orderId, ?PDO $pdo = null): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM shop_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> Oldest first (reshipment history later). */
    public static function forOrder(int $orderId, ?PDO $pdo = null): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM shop_shipments WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /**
     * Stamp delivery on the shipment row. The optional POD note is
     * appended to the shipping notes (never replacing them).
     */
    public static function markDelivered(int $shipmentId, ?string $note = null, ?PDO $pdo = null): void
    {
        if ($shipmentId <= 0) {
            return;
        }

        $pdo  = $pdo ?? db();
        $note = trim((string) ($note ?? ''));

        $stmt = $pdo->prepare('SELECT notes FROM shop_shipments WHERE id = ? LIMIT 1');
        $stmt->execute([$shipmentId]);
        $existing = $stmt->fetchColumn();

        $notes = $existing === false ? '' : trim((string) $existing);

        if ($note !== '') {
            $notes = $notes === '' ? 'POD: ' . $note : $notes . ' · POD: ' . $note;
        }

        $pdo->prepare(
            'UPDATE shop_shipments SET delivered_at = NOW(), notes = ? WHERE id = ?'
        )->execute([
            $notes === '' ? null : mb_substr($notes, 0, 255),
            $shipmentId,
        ]);
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
