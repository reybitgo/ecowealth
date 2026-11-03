<?php
declare(strict_types=1);

/* ==============================================================
 *  StockService — the one place that knows what "available" means
 *  (plan §5.1, Phase 2)
 *
 *      physical stock   products.stock
 *      reserved         quantities of non-terminal order lines whose
 *                       reservation_state is reserved or committed
 *      available        max(0, stock − reserved)
 *
 *  `available` is the only number shown to members and the only
 *  number the cart will enforce (Phase 4). Order tables arrive in
 *  Phase 6; until then the reservation side counts as zero, and
 *  this class starts using them automatically once they exist.
 * ============================================================== */

final class StockService
{
    /** At or below this the storefront shows "N left". */
    public const LOW_STOCK_THRESHOLD = 5;

    /** Cached "does shop_order_items exist yet?" answer. */
    private static ?bool $hasReservations = null;

    /** Available units for one product. */
    public static function available(int $productId): int
    {
        $product = Product::find($productId);

        if ($product === null) {
            return 0;
        }

        return max(0, (int) $product['stock'] - (self::reservationsFor([$productId])[$productId] ?? 0));
    }

    /**
     * Available units for many products in two queries (defect M7).
     *
     * @param list<int|string> $productIds
     * @return array<int, int> product id => available (missing ids omitted)
     */
    public static function availableMap(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = db()->prepare('SELECT id, stock FROM products WHERE id IN (' . $marks . ')');
        $stmt->execute($ids);

        $stockById = [];
        foreach ($stmt->fetchAll() as $row) {
            $stockById[(int) $row['id']] = (int) $row['stock'];
        }

        $held = self::reservationsFor($ids);

        $available = [];
        foreach ($stockById as $id => $stock) {
            $available[$id] = max(0, $stock - ($held[$id] ?? 0));
        }

        return $available;
    }

    /**
     * Storefront badge for an available count.
     *
     * @return array{key: string, label: string, tone: string}
     */
    public static function label(int $available): array
    {
        if ($available <= 0) {
            return ['key' => 'out', 'label' => 'Sold out', 'tone' => 'secondary'];
        }

        if ($available <= self::LOW_STOCK_THRESHOLD) {
            return ['key' => 'low', 'label' => $available . ' left', 'tone' => 'warning'];
        }

        return ['key' => 'in', 'label' => 'In stock', 'tone' => 'success'];
    }

    /** Test seam: forget the cached table-existence answer. */
    public static function forgetSchemaCache(): void
    {
        self::$hasReservations = null;
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * Quantity held by non-terminal orders, per product.
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    private static function reservationsFor(array $productIds): array
    {
        if (!self::hasReservationTable() || $productIds === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $stmt  = db()->prepare(
            'SELECT oi.product_id AS pid, COALESCE(SUM(oi.quantity), 0) AS held
               FROM shop_order_items oi
               JOIN shop_orders o ON o.id = oi.order_id
              WHERE oi.product_id IN (' . $marks . ')
                AND oi.reservation_state IN (\'reserved\', \'committed\')
                AND o.status NOT IN (\'cancelled\', \'completed\')
              GROUP BY oi.product_id'
        );
        $stmt->execute($productIds);

        $held = [];
        foreach ($stmt->fetchAll() as $row) {
            $held[(int) $row['pid']] = (int) $row['held'];
        }

        return $held;
    }

    /** Do the Phase 6 order tables exist yet? Checked once per request. */
    private static function hasReservationTable(): bool
    {
        if (self::$hasReservations !== null) {
            return self::$hasReservations;
        }

        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('shop_order_items', 'shop_orders')"
        );
        $stmt->execute();

        return self::$hasReservations = (int) $stmt->fetchColumn() === 2;
    }
}
