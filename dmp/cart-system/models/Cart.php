<?php
class Cart
{
    public static function getActive(int $memberId): ?array
    {
        $st = db()->prepare("SELECT * FROM carts WHERE member_id = ? AND status = 'active' LIMIT 1");
        $st->execute([$memberId]);
        return $st->fetch() ?: null;
    }
    public static function getOrCreate(int $memberId): array
    {
        $existing = self::getActive($memberId);
        if ($existing) return $existing;
        $pdo = db();
        $pdo->prepare("INSERT INTO carts (member_id, status) VALUES (?, 'active')")->execute([$memberId]);
        $id = (int)$pdo->lastInsertId();
        return ['id' => $id, 'member_id' => $memberId, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    }
    public static function addItem(int $cartId, int $productId, int $quantity): bool
    {
        $product = Product::find($productId);
        if (!$product || $product['status'] !== 'active') throw new InvalidArgumentException('Product not found or inactive.');
        if ($quantity < 1) throw new InvalidArgumentException('Quantity must be at least 1.');
        $available = Product::availableStock($productId);
        $existing = self::getItem($cartId, $productId);
        $newQty = ($existing ? (int)$existing['quantity'] : 0) + $quantity;
        if ($newQty > $available) throw new InvalidArgumentException('Insufficient stock. Requested ' . $newQty . ', only ' . $available . ' available.');
        $pdo = db();
        $unitPrice = (float)$product['price'];
        if ($existing) {
            $st = $pdo->prepare("UPDATE cart_items SET quantity = ?, unit_price = ?, updated_at = NOW() WHERE cart_id = ? AND product_id = ?");
            return $st->execute([$newQty, $unitPrice, $cartId, $productId]);
        }
        $st = $pdo->prepare("INSERT INTO cart_items (cart_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        return $st->execute([$cartId, $productId, $quantity, $unitPrice]);
    }
    public static function getItem(int $cartId, int $productId): ?array
    {
        $st = db()->prepare("SELECT ci.*, p.name AS product_name, p.image_url, p.price AS current_price, p.stock, p.status AS product_status FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE ci.cart_id = ? AND ci.product_id = ? LIMIT 1");
        $st->execute([$cartId, $productId]);
        return $st->fetch() ?: null;
    }
    public static function getItems(int $cartId): array
    {
        $st = db()->prepare("SELECT ci.*, p.name AS product_name, p.sku AS product_sku, p.image_url, p.price AS current_price, p.stock, p.status AS product_status FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE ci.cart_id = ? ORDER BY ci.added_at ASC");
        $st->execute([$cartId]);
        return $st->fetchAll();
    }
    public static function getTotals(int $cartId): array
    {
        $st = db()->prepare("SELECT COALESCE(SUM(quantity * unit_price), 0) AS total_price, SUM(quantity) AS total_items FROM cart_items WHERE cart_id = ?");
        $st->execute([$cartId]);
        return $st->fetch() ?: ['total_price' => 0, 'total_items' => 0];
    }
    public static function isEmpty(int $cartId): bool
    {
        $totals = self::getTotals($cartId);
        return (int)$totals['total_items'] === 0;
    }
    public static function abandon(int $cartId): bool
    {
        $st = db()->prepare("UPDATE carts SET status = 'abandoned', updated_at = NOW() WHERE id = ? AND status = 'active'");
        return $st->execute([$cartId]);
    }
    public static function markConverted(int $cartId): bool
    {
        $st = db()->prepare("UPDATE carts SET status = 'converted', updated_at = NOW() WHERE id = ? AND status = 'active'");
        return $st->execute([$cartId]);
    }
    public static function clear(int $cartId): bool
    {
        $st = db()->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        return $st->execute([$cartId]);
    }
    /**
     * Set a line's quantity. Scoped to $cartId — see removeItemById().
     *
     * Returns false when nothing matched, meaning the item does not exist or
     * belongs to a different cart. Callers treat both as "refused".
     */
    public static function updateItemQuantity(int $itemId, int $quantity, int $cartId): bool
    {
        if ($quantity < 0) throw new InvalidArgumentException('Quantity cannot be negative.');
        if ($quantity === 0) return self::removeItemById($itemId, $cartId);
        $st = db()->prepare("SELECT ci.id, ci.cart_id, ci.product_id, p.stock FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE ci.id = ? AND ci.cart_id = ? LIMIT 1");
        $st->execute([$itemId, $cartId]);
        $item = $st->fetch();
        if (!$item) throw new InvalidArgumentException('Cart item not found.');
        $available = Product::availableStock((int)$item['product_id']);
        if ($quantity > $available) throw new InvalidArgumentException('Insufficient stock. Requested ' . $quantity . ', only ' . $available . ' available.');
        $st = db()->prepare("UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE id = ? AND cart_id = ?");
        $st->execute([$quantity, $itemId, $cartId]);
        return $st->rowCount() > 0;
    }
    /**
     * Delete a line. SCOPED TO THE CALLER'S CART — $cartId is mandatory.
     *
     * This was `WHERE id = ?` with no cart filter, so any authenticated
     * session could delete or rewrite ANY member's cart line by walking the
     * primary key (defect C3, tmp/shop/remedy.md). The id alone proves
     * nothing: cart_items.id is a sequential AUTO_INCREMENT shared by every
     * member, and Auth::guard('member') also admits admins.
     *
     * Returns false when the id does not exist inside $cartId.
     */
    public static function removeItemById(int $itemId, int $cartId): bool
    {
        $st = db()->prepare("DELETE FROM cart_items WHERE id = ? AND cart_id = ?");
        $st->execute([$itemId, $cartId]);
        return $st->rowCount() > 0;
    }
    public static function itemCountForMember(int $memberId): int
    {
        $cart = self::getActive($memberId);
        if (!$cart) return 0;
        $totals = self::getTotals((int)$cart['id']);
        return (int)$totals['total_items'];
    }
    public static function validateStock(int $cartId): array
    {
        $errors = [];
        $items = self::getItems($cartId);
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $req = (int)$item['quantity'];
            $avail = Product::availableStock($pid);
            if ($req > $avail) {
                $errors[] = $item['product_name'] . ': requested ' . $req . ', only ' . $avail . ' available.';
            }
        }
        return $errors;
    }
    public static function refreshPrices(int $cartId): void
    {
        $st = db()->prepare("UPDATE cart_items ci JOIN products p ON p.id = ci.product_id SET ci.unit_price = p.price WHERE ci.cart_id = ?");
        $st->execute([$cartId]);
    }
}
