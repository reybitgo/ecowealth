<?php
declare(strict_types=1);

/* ==============================================================
 *  CartService — every cart rule in one place (plan §5.2, Phase 4)
 *
 *  Rules this class owns:
 *
 *    • one `active` cart per member, created on the first add
 *      (re-checked under a row lock so two concurrent first-adds
 *      cannot create two carts)
 *    • add / update / remove are always scoped by the caller's cart
 *      id — a posted item id alone never authorises a write (C3)
 *    • quantities are capped by `StockService::available()`, the
 *      only number the cart trusts (stock − reserved)
 *    • totals are computed from the live catalogue price, never
 *      from the line snapshot or from client input
 *    • a mutation is one transaction: lock, check, write, re-read
 *
 *  The optional PDO lets a caller share its transaction (and lets
 *  the concurrency tests drive two connections).
 * ============================================================== */

final class CartService
{
    /** Hard ceiling for one line — keeps the integer column sane. */
    public const MAX_QUANTITY = 999;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public function active(int $userId): ?array
    {
        return Cart::active($userId, $this->pdo);
    }

    /**
     * The cart page state: lines decorated with availability, the
     * live totals and the stock problems blocking checkout.
     *
     * When $refreshPrices is true the line snapshot is rewritten
     * from the catalogue first (§5.2: refreshed on view).
     *
     * @return array{
     *   cart: array<string, mixed>|null,
     *   items: list<array<string, mixed>>,
     *   totals: array{items: int, quantity: int, subtotal: float},
     *   stock_errors: list<string>
     * }
     */
    public function state(int $userId, bool $refreshPrices = false): array
    {
        $cart = $this->active($userId);

        if ($cart === null) {
            return [
                'cart'         => null,
                'items'        => [],
                'totals'       => ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0],
                'stock_errors' => [],
            ];
        }

        $cartId = (int) $cart['id'];

        if ($refreshPrices) {
            $this->refreshPrices($cartId);
        }

        $items = Cart::items($cartId, $this->pdo);

        $availableMap = StockService::availableMap(
            array_map(static fn (array $row): int => (int) $row['product_id'], $items),
            $this->pdo
        );

        foreach ($items as &$row) {
            $available = (int) ($availableMap[(int) $row['product_id']] ?? 0);

            $row['available']      = $available;
            $row['stock_badge']    = StockService::label($available);
            $row['current_price']  = round((float) $row['current_price'], 2);
            $row['line_total']     = round((float) $row['current_price'] * (int) $row['quantity'], 2);
            $row['over_available'] = (int) $row['quantity'] > $available;
            $row['is_inactive']    = (string) ($row['product_status'] ?? '') !== 'active';
        }
        unset($row);

        return [
            'cart'         => $cart,
            'items'        => $items,
            'totals'       => Cart::totals($cartId, $this->pdo),
            'stock_errors' => $this->stockErrorsFrom($items),
        ];
    }

    /**
     * Human-readable reasons checkout is blocked (plan §5.2).
     *
     * @return list<string>
     */
    public function validateStock(int $userId): array
    {
        return $this->state($userId)['stock_errors'];
    }

    /**
     * Totals for the member's active cart (plan §5.2).
     *
     * @return array{items: int, quantity: int, subtotal: float}
     */
    public function totals(int $userId): array
    {
        $cart = $this->active($userId);

        if ($cart === null) {
            return ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0];
        }

        return Cart::totals((int) $cart['id'], $this->pdo);
    }

    /**
     * Badge numbers for the cart endpoints.
     *
     * @return array{count: int, items: int, subtotal: float}
     */
    public function summary(int $userId): array
    {
        $totals = $this->totals($userId);

        return [
            'count'    => $totals['quantity'],
            'items'    => $totals['items'],
            'subtotal' => $totals['subtotal'],
        ];
    }

    /* ----------------------------------------------------------
     *  Writes
     * ---------------------------------------------------------- */

    /**
     * The member's active cart, created when missing.
     *
     * @return array<string, mixed>
     */
    public function getOrCreate(int $userId): array
    {
        return $this->withTransaction(fn (): array => $this->lockCart($userId, true));
    }

    /**
     * Add units of a product to the caller's cart.
     *
     * @return array<string, mixed> the refreshed cart state + the touched line
     */
    public function add(int $userId, int $productId, int $quantity = 1): array
    {
        $quantity = $this->normaliseQuantity($quantity);

        $product = Product::find($productId);

        if ($product === null) {
            throw CartException::productMissing();
        }

        if ((string) $product['status'] !== 'active') {
            throw CartException::productInactive((string) $product['name']);
        }

        $result = $this->withTransaction(function () use ($userId, $productId, $quantity): array {
            $cart   = $this->lockCart($userId, true);
            $cartId = (int) $cart['id'];

            $stmt = $this->pdo->prepare(
                'SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? FOR UPDATE'
            );
            $stmt->execute([$cartId, $productId]);
            $existing = $stmt->fetch();

            $requested = ($existing === false ? 0 : (int) $existing['quantity']) + $quantity;
            $available = StockService::available($productId, $this->pdo);

            if ($requested > $available) {
                throw CartException::stock($requested, $available);
            }

            $price = $this->currentPrice($productId);

            if ($existing === false) {
                $this->pdo->prepare(
                    'INSERT INTO cart_items (cart_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
                )->execute([$cartId, $productId, $requested, $price]);

                $itemId = (int) $this->pdo->lastInsertId();
            } else {
                $itemId = (int) $existing['id'];
                $this->pdo->prepare(
                    'UPDATE cart_items SET quantity = ?, unit_price = ? WHERE id = ? AND cart_id = ?'
                )->execute([$requested, $price, $itemId, $cartId]);
            }

            return $this->mutationResult($userId, $cartId, $itemId, false);
        });

        /* The member's open cart page re-renders from these rows; an open
         * checkout summary re-prices from the same cart. */
        Live::touchCart($userId);

        return $result;
    }

    /**
     * Set a line's quantity. Quantity 0 removes the line (§5.2).
     *
     * @return array<string, mixed>
     */
    public function updateQuantity(int $userId, int $itemId, int $quantity): array
    {
        $quantity = (int) $quantity;

        if ($quantity < 0) {
            throw CartException::quantity();
        }

        if ($quantity > self::MAX_QUANTITY) {
            throw CartException::quantityTooLarge(self::MAX_QUANTITY);
        }

        $result = $this->withTransaction(function () use ($userId, $itemId, $quantity): array {
            $cart   = $this->lockCart($userId, false);
            $cartId = (int) $cart['id'];

            return $this->applyQuantity($userId, $cartId, $this->lockItem($cartId, $itemId), $quantity);
        });

        Live::touchCart($userId);

        return $result;
    }

    /**
     * Step a line's quantity by a delta (+1 / −1), reading the current
     * quantity under the same lock. This is what the cart page's +/−
     * buttons post when JavaScript is unavailable; the JS path keeps
     * using updateQuantity() with the input's value.
     *
     * @return array<string, mixed>
     */
    public function adjustQuantity(int $userId, int $itemId, int $delta): array
    {
        $delta = (int) $delta;

        if ($delta === 0) {
            throw CartException::quantity();
        }

        $result = $this->withTransaction(function () use ($userId, $itemId, $delta): array {
            $cart   = $this->lockCart($userId, false);
            $cartId = (int) $cart['id'];
            $item   = $this->lockItem($cartId, $itemId);

            $quantity = max(0, min(self::MAX_QUANTITY, (int) $item['quantity'] + $delta));

            return $this->applyQuantity($userId, $cartId, $item, $quantity);
        });

        Live::touchCart($userId);

        return $result;
    }

    /**
     * Apply an already-decided quantity to a locked line.
     *
     * A zero quantity is the remove gesture — allowed even when the product
     * has since been deactivated (the member must be able to clear it).
     * Raising a quantity still enforces the catalogue status and stock.
     *
     * @param array<string, mixed> $item row from lockItem()
     * @return array<string, mixed>
     */
    private function applyQuantity(int $userId, int $cartId, array $item, int $quantity): array
    {
        $itemId = (int) $item['id'];

        if ($quantity === 0) {
            $this->pdo->prepare('DELETE FROM cart_items WHERE id = ? AND cart_id = ?')
                ->execute([$itemId, $cartId]);

            return $this->mutationResult($userId, $cartId, null, true);
        }

        if ((string) ($item['product_status'] ?? '') !== 'active') {
            throw CartException::productInactive((string) $item['product_name']);
        }

        $available = StockService::available((int) $item['product_id'], $this->pdo);

        if ($quantity > $available) {
            throw CartException::stock($quantity, $available);
        }

        $this->pdo->prepare(
            'UPDATE cart_items SET quantity = ?, unit_price = ? WHERE id = ? AND cart_id = ?'
        )->execute([$quantity, $this->currentPrice((int) $item['product_id']), $itemId, $cartId]);

        return $this->mutationResult($userId, $cartId, $itemId, false);
    }

    /**
     * Remove one line from the caller's cart.
     *
     * @return array<string, mixed>
     */
    public function remove(int $userId, int $itemId): array
    {
        $result = $this->withTransaction(function () use ($userId, $itemId): array {
            $cart   = $this->lockCart($userId, false);
            $cartId = (int) $cart['id'];

            $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE id = ? AND cart_id = ?');
            $stmt->execute([$itemId, $cartId]);

            if ($stmt->rowCount() === 0) {
                throw CartException::itemMissing();
            }

            return $this->mutationResult($userId, $cartId, null, true);
        });

        Live::touchCart($userId);

        return $result;
    }

    /** Delete every line in the member's active cart. */
    public function clear(int $userId): bool
    {
        $result = $this->withTransaction(function () use ($userId): bool {
            $cart = $this->lockCart($userId, false);

            $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([(int) $cart['id']]);

            return true;
        });

        Live::touchCart($userId);

        return $result;
    }

    /**
     * Close the active cart because it became an order (Phase 6).
     * The next add creates a fresh cart.
     */
    public function convert(int $userId): bool
    {
        $result = $this->withTransaction(function () use ($userId): bool {
            $stmt = $this->pdo->prepare(
                "UPDATE carts SET status = 'converted' WHERE user_id = ? AND status = 'active'"
            );
            $stmt->execute([$userId]);

            return $stmt->rowCount() > 0;
        });

        if ($result) {
            Live::touchCart($userId);
        }

        return $result;
    }

    /** Refresh line snapshots from the live catalogue (§5.2). */
    public function refreshPrices(int $cartId): void
    {
        if ($cartId <= 0) {
            return;
        }

        $this->pdo->prepare(
            'UPDATE cart_items ci
               JOIN products p ON p.id = ci.product_id
                SET ci.unit_price = p.price
              WHERE ci.cart_id = ?'
        )->execute([$cartId]);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function lockCart(int $userId, bool $create): array
    {
        if ($userId <= 0) {
            throw CartException::userMissing();
        }

        /* Serialise cart creation per member: lock the account row first,
         * then the active cart. Two concurrent first-adds therefore queue,
         * and the loser sees the winner's cart instead of creating a second
         * one (§4.3). */
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);

        if ($stmt->fetchColumn() === false) {
            throw CartException::userMissing();
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM carts WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$userId]);
        $cart = $stmt->fetch();

        if ($cart !== false) {
            return $cart;
        }

        if (!$create) {
            throw CartException::cartMissing();
        }

        $this->pdo->prepare("INSERT INTO carts (user_id, status) VALUES (?, 'active')")->execute([$userId]);

        return [
            'id'     => (int) $this->pdo->lastInsertId(),
            'user_id' => $userId,
            'status' => 'active',
        ];
    }

    /**
     * Lock and read one line of the caller's cart. A line that exists in
     * another cart is invisible here — the IDOR guard (P4-T5/T6).
     *
     * @return array<string, mixed>
     */
    private function lockItem(int $cartId, int $itemId): array
    {
        if ($itemId <= 0) {
            throw CartException::itemMissing();
        }

        $stmt = $this->pdo->prepare(
            'SELECT ci.*, p.name AS product_name, p.price AS current_price, p.status AS product_status
               FROM cart_items ci
               JOIN products p ON p.id = ci.product_id
              WHERE ci.id = ? AND ci.cart_id = ?
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$itemId, $cartId]);
        $item = $stmt->fetch();

        if ($item === false) {
            throw CartException::itemMissing();
        }

        return $item;
    }

    /**
     * Post-mutation payload: the touched line, the new totals and the
     * full state the offcanvas / cart page re-render from.
     *
     * @return array<string, mixed>
     */
    private function mutationResult(int $userId, int $cartId, ?int $itemId, bool $removed): array
    {
        $totals = Cart::totals($cartId, $this->pdo);
        $item   = $itemId === null ? null : Cart::item($cartId, $itemId, $this->pdo);

        if ($item !== null) {
            $item['current_price']           = round((float) $item['current_price'], 2);
            $item['line_total']              = round((float) $item['current_price'] * (int) $item['quantity'], 2);
            $item['current_price_formatted'] = money($item['current_price']);
            $item['line_total_formatted']    = money($item['line_total']);
        }

        return [
            'cart_id'  => $cartId,
            'item'     => $item,
            'removed'  => $removed,
            'count'    => $totals['quantity'],
            'items'    => $totals['items'],
            'subtotal' => $totals['subtotal'],
        ];
    }

    private function currentPrice(int $productId): string
    {
        $stmt = $this->pdo->prepare('SELECT price FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $price = $stmt->fetchColumn();

        return number_format((float) ($price === false ? 0 : $price), 2, '.', '');
    }

    private function normaliseQuantity(int $quantity): int
    {
        if ($quantity < 1) {
            throw CartException::quantity();
        }

        if ($quantity > self::MAX_QUANTITY) {
            throw CartException::quantityTooLarge(self::MAX_QUANTITY);
        }

        return $quantity;
    }

    /**
     * @param list<array<string, mixed>> $items decorated cart lines
     * @return list<string>
     */
    private function stockErrorsFrom(array $items): array
    {
        $errors = [];

        foreach ($items as $item) {
            $name = (string) $item['product_name'];

            if (($item['is_inactive'] ?? false) === true) {
                $errors[] = sprintf('“%s” is no longer available. Remove it before checking out.', $name);
                continue;
            }

            if (($item['over_available'] ?? false) === true) {
                $errors[] = sprintf(
                    '%s: requested %d, only %d available.',
                    $name,
                    (int) $item['quantity'],
                    (int) $item['available']
                );
            }
        }

        return $errors;
    }

    /**
     * Run a cart mutation atomically. When the caller already owns a
     * transaction (the test harness) the savepoint is theirs; the locks
     * still apply until their transaction ends.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $operation();

            if ($owns) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
