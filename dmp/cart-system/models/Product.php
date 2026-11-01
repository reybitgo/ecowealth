<?php
class Product {
    public static function find(int $id): ?array {
        $st = db()->prepare("SELECT * FROM products WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    public static function all(bool $activeOnly = false): array {
        $sql = "SELECT * FROM products";
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= " ORDER BY name ASC";
        return db()->query($sql)->fetchAll();
    }
    public static function allPaginated(int $page = 1, int $perPage = 25): array {
        return paginate("SELECT * FROM products ORDER BY name ASC", [], $page, $perPage);
    }
    public static function active(): array {
        return self::all(true);
    }
    public static function reservedStock(int $productId): int {
        $st = db()->prepare("SELECT COALESCE(SUM(oi.quantity), 0) FROM shop_order_items oi JOIN shop_orders o ON o.id = oi.order_id WHERE oi.product_id = ? AND oi.reservation_state IN ('reserved','committed') AND o.status IN ('pending','payment_review','payment_failed','paid','packing','ready_to_ship','on_hold')");
        $st->execute([$productId]);
        return (int)$st->fetchColumn();
    }
    /**
     * Why a product cannot be deleted, for many products at once.
     *
     * Returns product_id => ['orders' => int, 'carts' => int], only for
     * products that have at least one reference. Two queries for the whole
     * page — calling delete()'s checks per row instead would be the N+1 that
     * availableStockMap() was added to remove.
     *
     * 'orders' counts shop_order_items, 'carts' counts cart_items. They are
     * reported separately because the admin remedy differs: an order is a
     * permanent record, a cart line can be emptied by the member.
     */
    public static function referenceMap(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $out = [];

        $orders = db()->prepare("SELECT product_id, COUNT(*) AS n FROM shop_order_items WHERE product_id IN ({$ph}) GROUP BY product_id");
        $orders->execute($ids);
        foreach ($orders->fetchAll() as $r) {
            $out[(int) $r['product_id']]['orders'] = (int) $r['n'];
        }

        $carts = db()->prepare("SELECT product_id, COUNT(*) AS n FROM cart_items WHERE product_id IN ({$ph}) GROUP BY product_id");
        $carts->execute($ids);
        foreach ($carts->fetchAll() as $r) {
            $out[(int) $r['product_id']]['carts'] = (int) $r['n'];
        }

        foreach ($out as $id => $counts) {
            $out[$id] = ['orders' => $counts['orders'] ?? 0, 'carts' => $counts['carts'] ?? 0];
        }
        return $out;
    }

    /**
     * Flip a product between active and inactive.
     *
     * Disabling is the reversible half of deleting: the product leaves the
     * storefront (Product::active() filters on status) and Cart::addItem()
     * refuses it, while existing orders keep their line items untouched —
     * the historical order is not edited by a catalog change.
     *
     * The status is validated here rather than at the call site so the enum
     * can never be handed a value MySQL would truncate, which is the same
     * failure shape as defect H4.
     */
    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Unknown product status: ' . $status);
        }
        if (!self::find($id)) return false;

        $st = db()->prepare('UPDATE products SET status = ? WHERE id = ? AND status <> ?');
        $st->execute([$status, $id, $status]);
        return true;
    }

    public static function availableStock(int $productId): int {
        $product = self::find($productId);
        if (!$product) return 0;
        return max(0, (int)$product['stock'] - self::reservedStock($productId));
    }
    /**
     * Available stock for many products in ONE query each, instead of two per
     * product. The storefront renders a card per catalog row and used to call
     * availableStock() inside that loop: a find() plus a three-table join per
     * product, so a 100-product catalog issued ~201 queries on page load
     * (defect M7).
     *
     * Returns product_id => available, omitting products that do not exist.
     */
    public static function availableStockMap(array $productIds): array {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $stock = db()->prepare("SELECT id, stock FROM products WHERE id IN ({$ph})");
        $stock->execute($ids);
        $stockById = [];
        foreach ($stock->fetchAll() as $row) {
            $stockById[(int) $row['id']] = (int) $row['stock'];
        }

        // One query for every product's held quantity, rather than one per
        // product. The status list is the same defensive second filter
        // reservedStock() uses: reservation_state is the contract, the status
        // list only makes a stale reservation on a cancelled order harmless.
        $res = db()->prepare(
            "SELECT oi.product_id AS pid, COALESCE(SUM(oi.quantity), 0) AS held
               FROM shop_order_items oi
               JOIN shop_orders o ON o.id = oi.order_id
              WHERE oi.product_id IN ({$ph})
                AND oi.reservation_state IN ('reserved','committed')
                AND o.status IN ('pending','payment_review','payment_failed','paid','packing','ready_to_ship','on_hold')
              GROUP BY oi.product_id"
        );
        $res->execute($ids);
        $heldById = [];
        foreach ($res->fetchAll() as $row) {
            $heldById[(int) $row['pid']] = (int) $row['held'];
        }

        $out = [];
        foreach ($stockById as $id => $absolute) {
            $out[$id] = max(0, $absolute - ($heldById[$id] ?? 0));
        }
        return $out;
    }
    public static function save(array $data, ?int $id = null): int {
        $pdo = db();
        // Explicit field list (plan §0.2): the two inert PV columns are never
        // read, written, or rendered by PHP, so a POSTed PV key is silently
        // dropped here by design. Do not add them to this list.
        $fields = [
            'sku' => trim($data['sku'] ?? ''),
            'name' => trim($data['name'] ?? ''),
            'product_type' => 'physical',
            'price' => (float)($data['price'] ?? 0),
            'stock' => (int)($data['stock'] ?? 0),
            'image_url' => $data['image_url'] ?? null,
            'short_description' => trim($data['short_description'] ?? ''),
            'description' => trim($data['description'] ?? ''),
            'status' => $data['status'] ?? 'active',
        ];
        if ($fields['sku'] === '') $fields['sku'] = null;
        if ($fields['image_url'] === '') $fields['image_url'] = null;
        if ($id) {
            $sets = []; $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = $k . ' = ?';
                $vals[] = $v;
            }
            $vals[] = $id;
            $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        } else {
            $cols = array_keys($fields);
            $ph = array_fill(0, count($cols), '?');
            $pdo->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')')->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
        }
        return $id;
    }
    /**
     * Delete a product, unless anything still references it.
     *
     * Both referencing tables are checked. Previously only shop_order_items
     * was, so a product sitting in anyone's cart made the DELETE fail on the
     * cart_items foreign key and surface as an uncaught PDOException — a 500
     * on the admin products page (defect, Phase 7).
     *
     * A cart line is disposable: clearing the referencing carts' items here
     * would be a side effect the caller did not ask for, so cart lines block
     * the delete instead. The admin removes the item from the cart first.
     */
    public static function delete(int $id): bool {
        $st = db()->prepare(
            "SELECT
               (SELECT COUNT(*) FROM shop_order_items WHERE product_id = ?) AS orders,
               (SELECT COUNT(*) FROM cart_items    WHERE product_id = ?) AS carts"
        );
        $st->execute([$id, $id]);
        $row = $st->fetch() ?: ['orders' => 0, 'carts' => 0];
        if ((int) $row['orders'] > 0 || (int) $row['carts'] > 0) return false;

        $product = self::find($id);
        if (!$product) return false;
        if (!empty($product['image_url'])) {
            delete_uploaded_file($product['image_url']);
        }
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        return true;
    }
}
