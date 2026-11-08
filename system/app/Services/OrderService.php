<?php
declare(strict_types=1);

/* ==============================================================
 *  OrderService — the one path from cart to order (plan §5.3, Phase 6)
 *
 *  Place an order (one transaction):
 *    1. lock the member, re-check the idempotency key under that lock
 *    2. lock the active cart and its product rows (`FOR UPDATE`)
 *    3. re-price from the catalogue — posted prices are never read
 *    4. re-check stock as `stock − reserved` while holding the locks
 *    5. insert the order (temporary number → EW order number), snapshot
 *       every line and reserve it
 *    6. e-wallet: debit the member inside the same transaction, mark
 *       the order paid and commit the reservation
 *       manual: stay `pending` with the configured payment deadline
 *    7. convert the cart, log `order_placed` (+ `paid`), commit
 *
 *  Any failure rolls the whole unit back: no order, no reservation,
 *  no wallet movement, cart intact (P6-T4/T5).
 *
 *  Idempotency: `shop_orders.idempotency_key` is UNIQUE and the
 *  replay lookup runs again under the member lock, so a double submit
 *  (or a lost unique race) returns the original order (P6-T2).
 * ============================================================== */

final class OrderService
{
    public const TERMS_VERSION = 'v1';

    /** Phase 6 ships free shipping; courier pricing arrives in Phase 8. */
    public const SHIPPING_FEE = 0.00;

    private static int $savepointSeq = 0;

    private PDO $pdo;
    private WalletService $wallet;
    private CartService $carts;
    private InvoiceService $invoices;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo      = $pdo ?? db();
        $this->wallet   = new WalletService($this->pdo);
        $this->carts    = new CartService($this->pdo);
        $this->invoices = new InvoiceService($this->pdo);
    }

    /* ----------------------------------------------------------
     *  Validation
     * ---------------------------------------------------------- */

    /**
     * Checkout field validation (P6-T6). The controller renders these
     * as field errors; the service refuses with the same map.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validateCheckout(array $input): array
    {
        $errors = Validator::check($input, [
            'billing_name'      => 'required|string|min:2|max:120',
            'billing_phone'     => 'nullable|string|max:40',
            'shipping_name'     => 'required|string|min:2|max:120',
            'shipping_phone'    => 'nullable|string|max:40',
            'shipping_address'  => 'required|string|min:5|max:255',
            'shipping_barangay' => 'nullable|string|max:120',
            'shipping_city'     => 'nullable|string|max:80',
            'shipping_province' => 'nullable|string|max:80',
            'shipping_postal'   => 'nullable|string|regex:/^[A-Za-z0-9][A-Za-z0-9 \-]{0,19}$/',
            'notes_member'      => 'nullable|string|max:500',
            'payment_method'    => 'required|in:ewallet,gcash,maya,bank',
            'idempotency_key'   => 'nullable|string|max:64',
        ], [
            'billing_name'      => 'Billing name',
            'billing_phone'     => 'Billing phone',
            'shipping_name'     => 'Recipient name',
            'shipping_phone'    => 'Recipient phone',
            'shipping_address'  => 'Delivery address',
            'shipping_barangay' => 'Barangay',
            'shipping_city'     => 'City',
            'shipping_province' => 'Province',
            'shipping_postal'   => 'Postal code',
            'notes_member'      => 'Delivery notes',
            'payment_method'    => 'Payment method',
        ]);

        if (trim((string) ($input['terms'] ?? '')) !== '1') {
            $errors['terms'] = 'Please accept the terms to place your order.';
        }

        return $errors;
    }

    /* ----------------------------------------------------------
     *  Placement
     * ---------------------------------------------------------- */

    /**
     * Turn the member's active cart into an order.
     *
     * @param array<string, mixed> $input checkout form fields
     * @return array{order_id:int, order_no:string, status:string, total:float, replayed:bool}
     */
    public function placeFromCart(int $userId, array $input): array
    {
        $errors = self::validateCheckout($input);

        if ($errors !== []) {
            throw OrderException::validation($errors);
        }

        $paymentMethod = strtolower(trim((string) $input['payment_method']));
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));

        /* Fast replay path: no locks for the common double-submit. */
        if ($idempotencyKey !== '') {
            $existing = Order::findByIdempotencyKey($idempotencyKey, $userId, false, $this->pdo);

            if ($existing !== null) {
                return $this->replay($existing);
            }
        }

        try {
            return $this->withTransaction(function () use ($userId, $input, $paymentMethod, $idempotencyKey): array {
                $member = $this->lockMember($userId);

                if ($member === null) {
                    throw OrderException::userMissing();
                }

                /* The member lock serialises this member's checkouts, so the
                 * replay decision below is race-free even for a double-click. */
                if ($idempotencyKey !== '') {
                    $existing = Order::findByIdempotencyKey($idempotencyKey, $userId, true, $this->pdo);

                    if ($existing !== null) {
                        return $this->replay($existing);
                    }
                }

                if (!setting_bool('shop_enabled', true)) {
                    throw OrderException::shopClosed();
                }

                $cart = $this->lockCart($userId);

                if ($cart === null) {
                    throw OrderException::emptyCart();
                }

                $cartId = (int) $cart['id'];
                $items  = Cart::items($cartId, $this->pdo);

                if ($items === []) {
                    throw OrderException::emptyCart();
                }

                $productIds = array_values(array_unique(array_map(
                    static fn (array $item): int => (int) $item['product_id'],
                    $items
                )));

                $products = $this->lockProducts($productIds);
                $reserved = $this->reservedFor($productIds);

                $lines    = [];
                $subtotal = 0.0;

                foreach ($items as $item) {
                    $productId = (int) $item['product_id'];
                    $product   = $products[$productId] ?? null;
                    $name      = (string) ($product['name'] ?? $item['product_name'] ?? 'Product');

                    if ($product === null) {
                        throw OrderException::productMissing($name);
                    }

                    if ((string) $product['status'] !== 'active') {
                        throw OrderException::productInactive($name);
                    }

                    $quantity  = (int) $item['quantity'];
                    $available = max(0, (int) $product['stock'] - (int) ($reserved[$productId] ?? 0));

                    if ($quantity < 1 || $quantity > $available) {
                        throw OrderException::stock($name, $quantity, $available);
                    }

                    $unitPrice = round((float) $product['price'], 2);
                    $lineTotal = round($unitPrice * $quantity, 2);
                    $subtotal  = round($subtotal + $lineTotal, 2);

                    $lines[] = [
                        'product_id'   => $productId,
                        'product_name' => mb_substr((string) $product['name'], 0, 160),
                        'product_sku'  => $product['sku'] === null ? null : mb_substr((string) $product['sku'], 0, 64),
                        'quantity'     => $quantity,
                        'unit_price'   => $unitPrice,
                        'line_total'   => $lineTotal,
                    ];
                }

                if ($lines === [] || $subtotal <= 0) {
                    throw OrderException::invalidTotal();
                }

                $shippingFee = self::SHIPPING_FEE;
                $total       = round($subtotal + $shippingFee, 2);

                if ($paymentMethod === 'ewallet') {
                    $balance = round((float) $member['ewallet_balance'], 2);

                    if ($balance < $total) {
                        throw OrderException::walletShort($balance, $total);
                    }
                }

                $deadline = $paymentMethod === 'ewallet'
                    ? null
                    : date('Y-m-d H:i:s', time() + max(1, setting_int('shop_payment_deadline_hours', 24)) * 3600);

                $orderId = $this->insertOrder(
                    $userId,
                    $paymentMethod,
                    $idempotencyKey,
                    $subtotal,
                    $shippingFee,
                    $total,
                    $input,
                    $deadline
                );

                $orderNo = OrderNumber::make($orderId);

                $this->pdo->prepare('UPDATE shop_orders SET order_no = ? WHERE id = ?')
                    ->execute([$orderNo, $orderId]);

                $this->insertItems($orderId, $lines);

                OrderEvent::log(
                    $orderId,
                    null,
                    'pending',
                    'member',
                    $userId,
                    'order_placed',
                    'Order placed via ' . payment_method_label($paymentMethod),
                    $this->pdo
                );

                $status = 'pending';

                if ($paymentMethod === 'ewallet') {
                    try {
                        $this->wallet->debitInternal(
                            $userId,
                            $total,
                            'shop_order',
                            $orderId,
                            'Order ' . $orderNo
                        );
                    } catch (WalletException $e) {
                        /* Maps insufficient / forbidden / invariant to a field-level
                         * checkout message; the rollback also undoes the inserts. */
                        throw OrderException::wallet($e->getMessage());
                    }

                    $this->pdo->prepare(
                        "UPDATE shop_orders SET status = 'paid', paid_at = NOW(), payment_deadline = NULL WHERE id = ?"
                    )->execute([$orderId]);

                    $this->pdo->prepare(
                        "UPDATE shop_order_items SET reservation_state = 'committed' WHERE order_id = ?"
                    )->execute([$orderId]);

                    OrderEvent::log(
                        $orderId,
                        'pending',
                        'paid',
                        'system',
                        null,
                        'paid',
                        'Paid from the e-wallet',
                        $this->pdo
                    );

                    /* The invoice is frozen inside the same transaction as
                     * the payment, so "paid" and "invoiced" cannot drift
                     * (plan §5.7, Phase 8). */
                    $this->invoices->issueForPaidOrder($orderId, $this->pdo);

                    $status = 'paid';
                }

                $this->carts->convert($userId);

                /* The product list's "available" column is derived from
                 * order reservations, not just products.stock. */
                Live::touch('admin.products');
                Live::touchShop($productIds);

                /* A new order changes the order desk's rows and counters. */
                Live::touchOrder($orderId, $userId);

                /* The member page shows how many orders the account placed
                 * and what has been paid to the shop. */
                Live::touchMember($userId);

                return [
                    'order_id' => $orderId,
                    'order_no' => $orderNo,
                    'status'   => $status,
                    'total'    => $total,
                    'replayed' => false,
                ];
            });
        } catch (PDOException $e) {
            /* A UNIQUE race on the idempotency key means the other request won:
             * return its order rather than surfacing a database error. */
            if ($idempotencyKey !== '' && (int) $e->getCode() === 23000) {
                $existing = Order::findByIdempotencyKey($idempotencyKey, $userId, false, $this->pdo);

                if ($existing !== null) {
                    return $this->replay($existing);
                }
            }

            throw $e;
        }
    }

    /**
     * Member cancellation of an unpaid order (P6-T11).
     *
     * Releases the reserved units and logs the transition. A paid order
     * is deliberately not cancellable online: an administrator cancels
     * it through PaymentService::cancelByAdmin(), which refunds a
     * wallet-paid order and releases the stock (Phase 7, P7-T12).
     *
     * @return array{order_id:int, status:string, reason:string}
     */
    public function cancelByMember(int $userId, int $orderId, string $reason = ''): array
    {
        $reason = mb_substr(
            trim(preg_replace('/\s+/u', ' ', $reason) ?? $reason),
            0,
            160
        );

        return $this->withTransaction(function () use ($userId, $orderId, $reason): array {
            $stmt = $this->pdo->prepare('SELECT * FROM shop_orders WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch();

            if ($order === false) {
                throw OrderException::notFound();
            }

            $status = (string) $order['status'];

            if (!in_array($status, Order::MEMBER_CANCELLABLE, true)) {
                throw OrderException::notCancellable($status);
            }

            $productIds = $this->productIdsForOrder($orderId);

            $this->pdo->prepare(
                "UPDATE shop_orders
                    SET status = 'cancelled', cancelled_at = NOW(), cancelled_reason = ?
                  WHERE id = ?"
            )->execute([$reason === '' ? null : $reason, $orderId]);

            $this->pdo->prepare(
                "UPDATE shop_order_items
                    SET reservation_state = 'released'
                  WHERE order_id = ? AND reservation_state IN ('reserved', 'committed')"
            )->execute([$orderId]);

            OrderEvent::log(
                $orderId,
                $status,
                'cancelled',
                'member',
                $userId,
                'member_cancelled',
                $reason === '' ? 'Cancelled by the member' : 'Cancelled by the member: ' . $reason,
                $this->pdo
            );

            Live::touch('admin.products');
            Live::touchShop($productIds);
            Live::touchOrder($orderId, $userId);

            return ['order_id' => $orderId, 'status' => 'cancelled', 'reason' => $reason];
        });
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    private function lockMember(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, ewallet_balance, withdrawable_balance FROM users WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function lockCart(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM carts WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Product ids on one order, for the storefront live surfaces.
     *
     * @return list<int>
     */
    private function productIdsForOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT product_id FROM shop_order_items WHERE order_id = ? ORDER BY product_id ASC'
        );
        $stmt->execute([$orderId]);

        return array_map(static fn (mixed $id): int => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Lock every product row in ascending id order (one lock order for
     * all checkouts, so two buyers cannot deadlock each other).
     *
     * @param list<int> $productIds
     * @return array<int, array<string, mixed>>
     */
    private function lockProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        sort($productIds);
        $marks = implode(',', array_fill(0, count($productIds), '?'));

        $stmt = $this->pdo->prepare(
            'SELECT id, name, sku, price, stock, status
               FROM products
              WHERE id IN (' . $marks . ')
              ORDER BY id ASC
              FOR UPDATE'
        );
        $stmt->execute($productIds);

        $products = [];
        foreach ($stmt->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        return $products;
    }

    /**
     * Units already held by non-terminal orders, per product (the same
     * definition as StockService, read on this connection).
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    private function reservedFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $stmt  = $this->pdo->prepare(
            "SELECT oi.product_id AS pid, COALESCE(SUM(oi.quantity), 0) AS held
               FROM shop_order_items oi
               JOIN shop_orders o ON o.id = oi.order_id
              WHERE oi.product_id IN (" . $marks . ")
                AND oi.reservation_state IN ('reserved', 'committed')
                AND o.status NOT IN ('cancelled', 'completed')
              GROUP BY oi.product_id"
        );
        $stmt->execute($productIds);

        $held = [];
        foreach ($stmt->fetchAll() as $row) {
            $held[(int) $row['pid']] = (int) $row['held'];
        }

        return $held;
    }

    /** @param array<string, mixed> $input */
    private function insertOrder(
        int $userId,
        string $paymentMethod,
        string $idempotencyKey,
        float $subtotal,
        float $shippingFee,
        float $total,
        array $input,
        ?string $paymentDeadline
    ): int {
        $country = strtoupper(substr(trim((string) ($input['shipping_country'] ?? 'PH')), 0, 2)) ?: 'PH';

        $this->pdo->prepare(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, idempotency_key, subtotal, shipping_fee, total,
                billing_name, billing_phone, shipping_name, shipping_phone, shipping_address,
                shipping_barangay, shipping_city, shipping_province, shipping_postal, shipping_country,
                notes_member, terms_version, terms_accepted_at, payment_deadline)
             VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
        )->execute([
            'T' . bin2hex(random_bytes(9)),
            $userId,
            $paymentMethod,
            $idempotencyKey === '' ? null : $idempotencyKey,
            $this->decimal($subtotal),
            $this->decimal($shippingFee),
            $this->decimal($total),
            $this->text((string) ($input['billing_name'] ?? ''), 120),
            $this->nullable((string) ($input['billing_phone'] ?? ''), 40),
            $this->text((string) ($input['shipping_name'] ?? ''), 120),
            $this->nullable((string) ($input['shipping_phone'] ?? ''), 40),
            $this->text((string) ($input['shipping_address'] ?? ''), 255),
            $this->nullable((string) ($input['shipping_barangay'] ?? ''), 120),
            $this->nullable((string) ($input['shipping_city'] ?? ''), 80),
            $this->nullable((string) ($input['shipping_province'] ?? ''), 80),
            $this->nullable((string) ($input['shipping_postal'] ?? ''), 20),
            $country,
            $this->nullable((string) ($input['notes_member'] ?? ''), 500),
            self::TERMS_VERSION,
            $paymentDeadline,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<array<string, mixed>> $lines */
    private function insertItems(int $orderId, array $lines): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total, reservation_state)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'reserved')"
        );

        foreach ($lines as $line) {
            $stmt->execute([
                $orderId,
                (int) $line['product_id'],
                (string) $line['product_name'],
                $line['product_sku'],
                (int) $line['quantity'],
                $this->decimal((float) $line['unit_price']),
                $this->decimal((float) $line['line_total']),
            ]);
        }
    }

    /** @return array{order_id:int, order_no:string, status:string, total:float, replayed:bool} */
    private function replay(array $order): array
    {
        return [
            'order_id' => (int) $order['id'],
            'order_no' => (string) $order['order_no'],
            'status'   => (string) $order['status'],
            'total'    => round((float) $order['total'], 2),
            'replayed' => true,
        ];
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function text(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    private function nullable(string $value, int $max): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * One placement, one transaction. When a caller already owns a
     * transaction (tests, a future admin flow) a savepoint keeps a
     * refused order from poisoning the outer work.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'order_sp_' . (++self::$savepointSeq);
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
