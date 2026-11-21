<?php
declare(strict_types=1);

/* ==============================================================
 *  Cart — read layer for the persistent cart (plan §5.2, Phase 4)
 *
 *  Every read is scoped by the cart id the caller resolved from the
 *  signed-in member, never by a posted item id alone (reference
 *  defect C3 — the sequential cart_items.id is not proof of
 *  ownership). Mutations live in CartService, which owns the locks
 *  and the domain errors.
 *
 *  Money: `cart_items.unit_price` is only a snapshot. Every total is
 *  computed from the CURRENT catalogue price (products.price), so an
 *  admin price change is visible on the next page load and a stale
 *  snapshot can never become a price at checkout (§5.2).
 *
 *  Reference: dmp/cart-system/models/Cart.php
 * ============================================================== */

final class Cart
{
    public const STATUSES = ['active', 'converted', 'abandoned'];

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare('SELECT * FROM carts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The member's active cart, or null when they have never added
     * anything (a page view must never INSERT a cart row — §5.2).
     *
     * @return array<string, mixed>|null
     */
    public static function active(int $userId, ?PDO $pdo = null): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            "SELECT * FROM carts WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Every line of a cart, joined to the live catalogue row.
     *
     * `current_price` is the authoritative price; `unit_price` is the
     * snapshot last written. `line_total` uses the current price.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(int $cartId, ?PDO $pdo = null): array
    {
        if ($cartId <= 0) {
            return [];
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT ci.id, ci.cart_id, ci.product_id, ci.quantity, ci.unit_price, ci.added_at, ci.updated_at,
                    p.name AS product_name, p.sku AS product_sku, p.slug AS product_slug,
                    p.price AS current_price, p.stock, p.image_url, p.status AS product_status,
                    ROUND(p.price * ci.quantity, 2) AS line_total
               FROM cart_items ci
               JOIN products p ON p.id = ci.product_id
              WHERE ci.cart_id = ?
              ORDER BY ci.added_at ASC, ci.id ASC'
        );
        $stmt->execute([$cartId]);

        return $stmt->fetchAll();
    }

    /**
     * One line, scoped to the cart. Returns null when the item is not
     * in THIS cart — the caller treats that as "refused" (P4-T5/T6).
     *
     * @return array<string, mixed>|null
     */
    public static function item(int $cartId, int $itemId, ?PDO $pdo = null): ?array
    {
        if ($cartId <= 0 || $itemId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT ci.*, p.name AS product_name, p.price AS current_price, p.status AS product_status
               FROM cart_items ci
               JOIN products p ON p.id = ci.product_id
              WHERE ci.id = ? AND ci.cart_id = ?
              LIMIT 1'
        );
        $stmt->execute([$itemId, $cartId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Cart totals from the live catalogue price.
     *
     * @return array{items: int, quantity: int, subtotal: float}
     */
    public static function totals(int $cartId, ?PDO $pdo = null): array
    {
        if ($cartId <= 0) {
            return ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0];
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT COUNT(*) AS line_count,
                    COALESCE(SUM(ci.quantity), 0) AS quantity,
                    COALESCE(SUM(p.price * ci.quantity), 0) AS subtotal
               FROM cart_items ci
               JOIN products p ON p.id = ci.product_id
              WHERE ci.cart_id = ?'
        );
        $stmt->execute([$cartId]);

        $row = $stmt->fetch() ?: [];

        return [
            'items'    => (int) ($row['line_count'] ?? 0),
            'quantity' => (int) ($row['quantity'] ?? 0),
            'subtotal' => round((float) ($row['subtotal'] ?? 0), 2),
        ];
    }

    /** Total units in the member's active cart (the navbar badge). */
    public static function countFor(int $userId, ?PDO $pdo = null): int
    {
        $cart = self::active($userId, $pdo);

        return $cart === null ? 0 : self::totals((int) $cart['id'], $pdo)['quantity'];
    }
}
