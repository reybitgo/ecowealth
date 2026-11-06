<?php
declare(strict_types=1);

/* ==============================================================
 *  FulfilmentService — pack, ship, deliver, complete (plan §5.5,
 *  §4.4, §7 Phase 8; reference ShopOrder::startPacking/ship/
 *  markDelivered/confirmReceipt + ShopShipment)
 *
 *  The paid half of the order machine:
 *
 *      paid ──(pack)──▶ packing ──(ready)──▶ ready_to_ship
 *           ──(ship)──▶ shipped ──(deliver)──▶ delivered
 *           ──(member confirms | auto-complete)──▶ completed
 *
 *  Every step locks the order row, re-checks the status and writes
 *  the status, its timestamp, the shipment facts and the event row in
 *  one transaction. Shipping is the point where the reservation
 *  becomes physical: `products.stock` is decremented and the lines
 *  become `deducted` in the same unit, so `stock − reserved` stays
 *  continuous across the shipment (P8-T3).
 *
 *  An order that has shipped can no longer be cancelled here or by
 *  PaymentService while it is cancelled/released only before
 *  shipment; a returns/RTS flow is deferred (P8-T8).
 * ============================================================== */

final class FulfilmentService
{
    /** Courier suggestions for the ship form (free text is allowed too). */
    public const COURIERS = [
        'J&T Express',
        'LBC Express',
        'Ninja Van',
        'Lalamove',
        'Grab Express',
        '2GO',
        'DHL',
        'Others',
    ];

    private static int $savepointSeq = 0;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /* ----------------------------------------------------------
     *  Configuration
     * ---------------------------------------------------------- */

    /** `shop_autocomplete_enabled` — the auto-complete kill switch. */
    public static function autoCompleteEnabled(): bool
    {
        return setting_bool('shop_autocomplete_enabled', true);
    }

    /** `shop_autocomplete_days` (1–365; 0 disables it). */
    public static function autoCompleteDays(): int
    {
        return max(0, min(365, setting_int('shop_autocomplete_days', 7)));
    }

    /** Does the auto-complete job do anything in this configuration? */
    public static function autoCompleteActive(): bool
    {
        return self::autoCompleteEnabled() && self::autoCompleteDays() >= 1;
    }

    /* ----------------------------------------------------------
     *  Validation
     * ---------------------------------------------------------- */

    /**
     * The ship form: courier and tracking are both required (P8-T2).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validateShipment(array $input): array
    {
        return Validator::check($input, [
            'courier'         => 'required|string|min:2|max:40',
            'tracking_number' => 'required|string|min:4|max:80|regex:/^[A-Za-z0-9][A-Za-z0-9 .\-]{3,79}$/',
            'notes'           => 'nullable|string|max:255',
        ], [
            'courier'         => 'Courier',
            'tracking_number' => 'Tracking number',
            'notes'           => 'Shipping note',
        ]);
    }

    /**
     * The deliver form: the proof-of-delivery note is optional.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validateDelivery(array $input): array
    {
        return Validator::check($input, [
            'note' => 'nullable|string|max:200',
        ], [
            'note' => 'Delivery note',
        ]);
    }

    /* ----------------------------------------------------------
     *  Admin — pack and ready
     * ---------------------------------------------------------- */

    /**
     * paid → packing (P8-T1).
     *
     * @return array{order_id:int, status:string}
     */
    public function pack(int $adminId, int $orderId, string $note = ''): array
    {
        return $this->advance(
            $adminId,
            $orderId,
            'paid',
            'packing',
            'packing_started',
            'Packing started' . $this->suffix($note),
            'packed'
        );
    }

    /**
     * packing → ready_to_ship (P8-T1).
     *
     * @return array{order_id:int, status:string}
     */
    public function ready(int $adminId, int $orderId, string $note = ''): array
    {
        return $this->advance(
            $adminId,
            $orderId,
            'packing',
            'ready_to_ship',
            'ready_to_ship',
            'Packed and ready for the courier' . $this->suffix($note),
            'marked ready to ship'
        );
    }

    /* ----------------------------------------------------------
     *  Admin — ship
     * ---------------------------------------------------------- */

    /**
     * ready_to_ship → shipped, deducting the stock (P8-T1/T2/T3).
     *
     * @param array<string, mixed> $input courier / tracking_number / notes
     * @return array{order_id:int, status:string, shipment_id:int, courier:string, tracking_number:string}
     */
    public function ship(int $adminId, int $orderId, array $input): array
    {
        $errors = self::validateShipment($input);

        if ($errors !== []) {
            throw FulfilmentException::validation($errors);
        }

        $courier  = mb_substr(trim((string) $input['courier']), 0, 40);
        $tracking = mb_substr(trim((string) $input['tracking_number']), 0, 80);
        $notes    = trim((string) ($input['notes'] ?? ''));

        return $this->withTransaction(function () use ($adminId, $orderId, $courier, $tracking, $notes): array {
            $order = $this->lockOrder($orderId);

            if ($order === null) {
                throw FulfilmentException::notFound();
            }

            if ((string) $order['status'] !== 'ready_to_ship') {
                throw FulfilmentException::status('shipped', (string) $order['status']);
            }

            $this->deductStock($orderId);

            $shipmentId = Shipment::create($orderId, [
                'courier'         => $courier,
                'tracking_number' => $tracking,
                'notes'           => $notes === '' ? null : $notes,
            ], $this->pdo);

            $this->pdo->prepare(
                "UPDATE shop_orders SET status = 'shipped', shipped_at = NOW() WHERE id = ?"
            )->execute([$orderId]);

            OrderEvent::log(
                $orderId,
                'ready_to_ship',
                'shipped',
                'admin',
                $adminId,
                'shipped',
                sprintf('Shipped via %s · tracking %s%s', $courier, $tracking, $this->suffix($notes)),
                $this->pdo
            );

            return [
                'order_id'        => $orderId,
                'status'          => 'shipped',
                'shipment_id'     => $shipmentId,
                'courier'         => $courier,
                'tracking_number' => $tracking,
            ];
        });
    }

    /* ----------------------------------------------------------
     *  Admin — deliver
     * ---------------------------------------------------------- */

    /**
     * shipped → delivered, stamping the shipment (P8-T4).
     *
     * @return array{order_id:int, status:string, shipment_id:?int}
     */
    public function deliver(int $adminId, int $orderId, string $note = ''): array
    {
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? $note), 0, 200);

        return $this->withTransaction(function () use ($adminId, $orderId, $note): array {
            $order = $this->lockOrder($orderId);

            if ($order === null) {
                throw FulfilmentException::notFound();
            }

            if ((string) $order['status'] !== 'shipped') {
                throw FulfilmentException::status('delivered', (string) $order['status']);
            }

            $shipment   = Shipment::latestFor($orderId, $this->pdo);
            $shipmentId = $shipment === null ? null : (int) $shipment['id'];

            if ($shipmentId !== null) {
                Shipment::markDelivered($shipmentId, $note === '' ? null : $note, $this->pdo);
            }

            $this->pdo->prepare(
                "UPDATE shop_orders SET status = 'delivered', delivered_at = NOW() WHERE id = ?"
            )->execute([$orderId]);

            OrderEvent::log(
                $orderId,
                'shipped',
                'delivered',
                'admin',
                $adminId,
                'delivered',
                'Delivered' . ($note === '' ? '' : ' · POD: ' . $note),
                $this->pdo
            );

            return ['order_id' => $orderId, 'status' => 'delivered', 'shipment_id' => $shipmentId];
        });
    }

    /* ----------------------------------------------------------
     *  Member — confirm receipt
     * ---------------------------------------------------------- */

    /**
     * delivered → completed (P8-T4).
     *
     * The order is scoped to the caller, so another member's id is a
     * plain 404 rather than a leak (P8-T7 pattern).
     *
     * @return array{order_id:int, status:string}
     */
    public function confirmByMember(int $userId, int $orderId, string $note = ''): array
    {
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? $note), 0, 200);

        return $this->withTransaction(function () use ($userId, $orderId, $note): array {
            $order = $this->lockOrder($orderId, $userId);

            if ($order === null) {
                throw FulfilmentException::notFound();
            }

            if ((string) $order['status'] !== 'delivered') {
                throw FulfilmentException::status('confirmed as received', (string) $order['status']);
            }

            $this->pdo->prepare(
                "UPDATE shop_orders SET status = 'completed', completed_at = NOW() WHERE id = ?"
            )->execute([$orderId]);

            OrderEvent::log(
                $orderId,
                'delivered',
                'completed',
                'member',
                $userId,
                'receipt_confirmed',
                'Receipt confirmed by the member' . $this->suffix($note),
                $this->pdo
            );

            return ['order_id' => $orderId, 'status' => 'completed'];
        });
    }

    /* ----------------------------------------------------------
     *  System — the auto-complete pass (bin/order_autocomplete.php)
     * ---------------------------------------------------------- */

    /**
     * Delivered orders old enough to complete on their own.
     * Read-only: `--dry-run` and the tests use it too.
     *
     * @return list<int>
     */
    public function dueOrderIds(int $limit = 200): array
    {
        $days = self::autoCompleteDays();

        if (!self::autoCompleteActive() || $days < 1) {
            return [];
        }

        $limit = max(1, min(1000, $limit));

        $stmt = $this->pdo->prepare(
            "SELECT id FROM shop_orders
              WHERE status = 'delivered'
                AND delivered_at IS NOT NULL
                AND delivered_at <= DATE_SUB(NOW(), INTERVAL {$days} DAY)
              ORDER BY delivered_at ASC
              LIMIT {$limit}"
        );
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Complete delivered orders after `shop_autocomplete_days`
     * (P8-T9). Idempotent: a completed order is never touched again,
     * and the delivered_at/status re-check under the row lock makes a
     * concurrent manual confirmation and this job agree.
     *
     * @return array{completed:int, ids:list<int>, candidates:int, skipped:?string, days:int}
     */
    public function autoComplete(int $limit = 200): array
    {
        $days = self::autoCompleteDays();
        $base = ['completed' => 0, 'ids' => [], 'candidates' => 0, 'skipped' => null, 'days' => $days];

        if (!self::autoCompleteEnabled()) {
            $base['skipped'] = 'disabled';

            return $base;
        }

        if ($days < 1) {
            $base['skipped'] = 'disabled';

            return $base;
        }

        $ids    = $this->dueOrderIds($limit);
        $base['candidates'] = count($ids);
        $done   = [];

        foreach ($ids as $orderId) {
            try {
                $ok = $this->withTransaction(function () use ($orderId, $days): ?int {
                    $order = $this->lockOrder($orderId);

                    if ($order === null || (string) $order['status'] !== 'delivered') {
                        return null;
                    }

                    $deliveredAt = $order['delivered_at'] === null ? null : strtotime((string) $order['delivered_at']);

                    if ($deliveredAt === null || $deliveredAt === false) {
                        return null;
                    }

                    if ($deliveredAt > time() - $days * 86400) {
                        return null;
                    }

                    $this->pdo->prepare(
                        "UPDATE shop_orders SET status = 'completed', completed_at = NOW() WHERE id = ?"
                    )->execute([$orderId]);

                    OrderEvent::log(
                        $orderId,
                        'delivered',
                        'completed',
                        'system',
                        null,
                        'auto_completed',
                        sprintf(
                            'Completed automatically %d day%s after delivery',
                            $days,
                            $days === 1 ? '' : 's'
                        ),
                        $this->pdo
                    );

                    return $orderId;
                });

                if ($ok !== null) {
                    $done[] = $ok;
                }
            } catch (Throwable $e) {
                /* One bad candidate must never abort the sweep. */
                error_log(sprintf('[ecowealth] order #%d auto-complete failed: %s', $orderId, $e->getMessage()));
            }
        }

        return [
            'completed'  => count($done),
            'ids'        => $done,
            'candidates' => count($ids),
            'skipped'    => null,
            'days'       => $days,
        ];
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * One admin status step (pack / ready), with the same lock and
     * re-check every other transition uses.
     *
     * @return array{order_id:int, status:string}
     */
    private function advance(
        int $adminId,
        int $orderId,
        string $from,
        string $to,
        string $reasonCode,
        string $note,
        string $action
    ): array {
        return $this->withTransaction(function () use ($adminId, $orderId, $from, $to, $reasonCode, $note, $action): array {
            $order = $this->lockOrder($orderId);

            if ($order === null) {
                throw FulfilmentException::notFound();
            }

            if ((string) $order['status'] !== $from) {
                throw FulfilmentException::status($action, (string) $order['status']);
            }

            $stamp = $to === 'packing' ? ', packed_at = NOW()' : '';

            $this->pdo->prepare(
                'UPDATE shop_orders SET status = ?' . $stamp . ' WHERE id = ?'
            )->execute([$to, $orderId]);

            OrderEvent::log(
                $orderId,
                $from,
                $to,
                'admin',
                $adminId,
                $reasonCode,
                $note,
                $this->pdo
            );

            return ['order_id' => $orderId, 'status' => $to];
        });
    }

    /**
     * Turn the committed reservation into a physical deduction.
     *
     * Products are locked in ascending id order (the same lock order
     * the checkout and re-reserve paths use), the physical count is
     * verified, then `products.stock` is decremented and every held
     * line becomes `deducted`. The order row is already locked by the
     * caller, so one shipment can never deduct twice.
     */
    private function deductStock(int $orderId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT product_id, SUM(quantity) AS qty
               FROM shop_order_items
              WHERE order_id = ? AND reservation_state IN ('reserved', 'committed')
              GROUP BY product_id
              ORDER BY product_id ASC"
        );
        $stmt->execute([$orderId]);
        $lines = $stmt->fetchAll();

        if ($lines === []) {
            throw FulfilmentException::nothingToShip();
        }

        $productIds = array_map(static fn (array $line): int => (int) $line['product_id'], $lines);
        sort($productIds);
        $marks = implode(',', array_fill(0, count($productIds), '?'));

        $locked = $this->pdo->prepare(
            'SELECT id, name, stock FROM products WHERE id IN (' . $marks . ') ORDER BY id ASC FOR UPDATE'
        );
        $locked->execute($productIds);

        $products = [];
        foreach ($locked->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $quantity  = (int) $line['qty'];
            $product   = $products[$productId] ?? null;

            if ($product === null) {
                throw FulfilmentException::stock('A product on this order', $quantity, 0);
            }

            if ($quantity < 1 || $quantity > (int) $product['stock']) {
                throw FulfilmentException::stock((string) $product['name'], $quantity, (int) $product['stock']);
            }

            $this->pdo->prepare(
                'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
            )->execute([$quantity, $productId, $quantity]);
        }

        $this->pdo->prepare(
            "UPDATE shop_order_items
                SET reservation_state = 'deducted'
              WHERE order_id = ? AND reservation_state IN ('reserved', 'committed')"
        )->execute([$orderId]);
    }

    /** @return array<string, mixed>|null The order row, locked for update. */
    private function lockOrder(int $orderId, ?int $userId = null): ?array
    {
        $sql    = 'SELECT * FROM shop_orders WHERE id = ?' . ($userId === null ? '' : ' AND user_id = ?') . ' FOR UPDATE';
        $stmt   = $this->pdo->prepare($sql);
        $stmt->execute($userId === null ? [$orderId] : [$orderId, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function suffix(string $note): string
    {
        $note = trim(preg_replace('/\s+/u', ' ', $note) ?? $note);

        return $note === '' ? '' : ' · ' . mb_substr($note, 0, 200);
    }

    /**
     * One fulfilment action, one transaction. Adopts a caller's
     * transaction with a savepoint so a refused step never poisons
     * outer work.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'fulfilment_sp_' . (++self::$savepointSeq);
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();

            if ($owns) {
                $this->pdo->commit();
            } elseif ($savepoint !== null) {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $e) {
            if ($owns) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } elseif ($savepoint !== null) {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    /* The outer transaction is already unusable. */
                }
            }

            throw $e;
        }
    }
}
