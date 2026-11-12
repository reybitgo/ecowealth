<?php
declare(strict_types=1);

/* ==============================================================
 *  Product — catalogue model (plan §5.1, Phase 2)
 *
 *  Owns the product rows and the delete guards. Stock maths lives
 *  in StockService so `stock` (physical) and `available` (stock
 *  minus reservations) are never confused.
 *
 *  Phase 17B adds the repeat-purchase configuration to the same row:
 *  the point value (`pv`), the master switch (`unilevel_enabled`) and
 *  the bonus base (`unilevel_base`), saved atomically with the ten-row
 *  ladder in ProductUnilevel (plan §6 Rule 7).
 *
 *  Reference: dmp/cart-system/models/Product.php — the batched
 *  available-stock / reference-map queries are kept (defects M7,
 *  C-series: no N+1, no uncaught FK 500s).
 * ============================================================== */

final class Product
{
    public const STATUSES = ['active', 'inactive'];

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = db()->prepare('SELECT * FROM products WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param bool $activeOnly Only rows the storefront may show
     * @return list<array<string, mixed>>
     */
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM products'
            . ($activeOnly ? " WHERE status = 'active'" : '')
            . ' ORDER BY sort_order ASC, name ASC';

        return db()->query($sql)->fetchAll();
    }

    /**
     * Admin table page.
     *
     * @param array{q?: string, status?: string, unilevel?: string} $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager): array
    {
        [$where, $args] = self::filterSql($filters, false);

        $sql = 'SELECT * FROM products' . $where
            . ' ORDER BY sort_order ASC, name ASC'
            . ' LIMIT ' . $pager->perPage . ' OFFSET ' . $pager->offset;

        $stmt = db()->prepare($sql);
        $stmt->execute($args);

        return $stmt->fetchAll();
    }

    /** @param array{q?: string, status?: string, unilevel?: string} $filters */
    public static function countAdmin(array $filters): int
    {
        [$where, $args] = self::filterSql($filters, false);

        $stmt = db()->prepare('SELECT COUNT(*) FROM products' . $where);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Storefront page — active products only, regardless of stock
     * (sold-out rows stay visible with a "Sold out" badge).
     *
     * @param array{q?: string} $filters
     * @return list<array<string, mixed>>
     */
    public static function catalogList(array $filters, Paginator $pager): array
    {
        [$where, $args] = self::filterSql($filters, true);

        $sql = 'SELECT * FROM products' . $where
            . ' ORDER BY sort_order ASC, name ASC'
            . ' LIMIT ' . $pager->perPage . ' OFFSET ' . $pager->offset;

        $stmt = db()->prepare($sql);
        $stmt->execute($args);

        return $stmt->fetchAll();
    }

    /** @param array{q?: string} $filters */
    public static function countCatalog(array $filters): int
    {
        [$where, $args] = self::filterSql($filters, true);

        $stmt = db()->prepare('SELECT COUNT(*) FROM products' . $where);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Shared WHERE builder.
     *
     * `unilevel` is the repeat-purchase filter: `on` shows products whose
     * bonus switch is on, `off` the rest. Any other value is ignored, so a
     * hand-edited URL can never produce a surprising list (plan §6).
     *
     * @param array{q?: string, status?: string, unilevel?: string} $filters
     * @return array{0: string, 1: list<string>}
     */
    private static function filterSql(array $filters, bool $catalogOnly): array
    {
        $where = [];
        $args  = [];

        if ($catalogOnly) {
            $where[] = "status = 'active'";
        } else {
            $status = (string) ($filters['status'] ?? '');
            if (in_array($status, self::STATUSES, true)) {
                $where[] = 'status = ?';
                $args[]  = $status;
            }

            $unilevel = (string) ($filters['unilevel'] ?? '');
            if ($unilevel === 'on' || $unilevel === 'off') {
                $where[] = 'unilevel_enabled = ?';
                $args[]  = $unilevel === 'on' ? 1 : 0;
            }
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like    = '%' . like_escape($q) . '%';
            $where[] = '(name LIKE ? OR sku LIKE ? OR short_description LIKE ?)';
            array_push($args, $like, $like, $like);
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
    }

    /** Counts for the admin header cards. @return array{total:int, active:int, inactive:int, out_of_stock:int} */
    public static function stats(): array
    {
        $row = db()->query(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'active') AS active,
                SUM(status = 'inactive') AS inactive,
                SUM(stock = 0) AS out_of_stock
             FROM products"
        )->fetch() ?: [];

        return [
            'total'         => (int) ($row['total'] ?? 0),
            'active'        => (int) ($row['active'] ?? 0),
            'inactive'      => (int) ($row['inactive'] ?? 0),
            'out_of_stock'  => (int) ($row['out_of_stock'] ?? 0),
        ];
    }

    /* ----------------------------------------------------------
     *  Validation
     * ---------------------------------------------------------- */

    /**
     * @param array<string, mixed> $data
     * @return array<string, string> field => message
     */
    public static function validate(array $data, ?int $id = null): array
    {
        $errors = Validator::check($data, [
            'name'              => 'required|string|min:2|max:160',
            'sku'               => 'nullable|string|max:64|regex:/^[A-Za-z0-9._-]+$/',
            'price'             => 'required|numeric|gt:0|decimal:2',
            'pv'                => 'nullable|numeric|gte:0|lte:1000000|decimal:2',
            'unilevel_base'     => 'nullable|in:auto,price,pv',
            'stock'             => 'required|integer|min:0|max:1000000',
            'sort_order'        => 'nullable|integer|min:0|max:1000000',
            'short_description' => 'nullable|string|max:255',
            'description'       => 'nullable|string|max:5000',
            'status'            => 'required|in:active,inactive',
        ], [
            'name'              => 'Product name',
            'sku'               => 'SKU',
            'price'             => 'Price',
            'pv'                => 'Point value (P.V.)',
            'unilevel_base'     => 'Bonus base',
            'stock'             => 'Stock',
            'sort_order'        => 'Sort order',
            'short_description' => 'Short description',
            'description'       => 'Description',
            'status'            => 'Status',
        ]);

        /* SKU is optional, but unique when set. NULLs are allowed to repeat. */
        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku !== '' && !isset($errors['sku'])) {
            $sql  = 'SELECT COUNT(*) FROM products WHERE sku = ?' . ($id !== null ? ' AND id <> ?' : '');
            $args = $id !== null ? [$sku, $id] : [$sku];
            $stmt = db()->prepare($sql);
            $stmt->execute($args);

            if ((int) $stmt->fetchColumn() > 0) {
                $errors['sku'] = 'This SKU is already used by another product.';
            }
        }

        /* A ladder priced on the point value is meaningless without one
         * (plan §6 Rule 6): 0 means "not set", so refuse the pair rather
         * than silently pay nothing. */
        $base = (string) ($data['unilevel_base'] ?? 'auto');
        $pv   = (float) ($data['pv'] ?? 0);

        if ($base === 'pv' && $pv <= 0 && !isset($errors['pv']) && !isset($errors['unilevel_base'])) {
            $errors['unilevel_base'] = 'A P.V. base needs a point value above 0 — set the P.V. or choose Auto.';
        }

        return $errors;
    }

    /* ----------------------------------------------------------
     *  Repeat-purchase reads (Phase 17B)
     * ---------------------------------------------------------- */

    /**
     * The product columns the repeat-purchase engine needs, for many
     * products in one query (17C reads this instead of N × find()).
     *
     * Missing ids are simply absent from the map; the caller decides
     * what a product that disappeared mid-checkout means.
     *
     * @param list<int|string> $ids
     * @return array<int, array{id:int, name:string, price:string, pv:string, unilevel_enabled:int, unilevel_base:string}>
     */
    public static function rowsFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = db()->prepare(
            'SELECT id, name, price, pv, unilevel_enabled, unilevel_base
               FROM products WHERE id IN (' . $marks . ')'
        );
        $stmt->execute($ids);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = [
                'id'               => (int) $row['id'],
                'name'             => (string) $row['name'],
                'price'            => (string) $row['price'],
                'pv'               => (string) $row['pv'],
                'unilevel_enabled' => (int) $row['unilevel_enabled'],
                'unilevel_base'    => (string) $row['unilevel_base'],
            ];
        }

        return $rows;
    }

    /* ----------------------------------------------------------
     *  Writes
     * ---------------------------------------------------------- */

    /**
     * Create or update a product. Returns its id.
     *
     * The slug is generated once from the name on create and kept
     * afterwards, so an existing link never breaks on a rename.
     *
     * `$unilevel` is the ladder (levels 1 … 10 => percent). When it is
     * given, the product row, its two repeat-purchase columns and the
     * ten ladder rows are written in **one transaction**, so a refused
     * ladder leaves the product untouched and vice versa (plan §6 Rule
     * 7). When it is null the ladder and the two columns are left
     * exactly as they are — a plain catalogue save never rewrites a
     * bonus configuration.
     *
     * When the configuration actually changed, `product.unilevel.update`
     * is recorded inside the same transaction, with the actor the caller
     * passes (null for a system write).
     *
     * @param array<string, mixed>     $data
     * @param array<int, float|string>|null $unilevel levels 1 … 10
     * @param array<string, mixed>|null $actor    the signed-in admin
     */
    public static function save(array $data, ?int $id = null, ?array $unilevel = null, ?array $actor = null): int
    {
        $existing = $id !== null ? self::find($id) : null;
        $sku      = trim((string) ($data['sku'] ?? ''));
        $image    = trim((string) ($data['image_url'] ?? ''));
        $status   = (string) ($data['status'] ?? 'active');

        $record = [
            'sku'               => $sku === '' ? null : $sku,
            'name'              => trim((string) ($data['name'] ?? '')),
            'price'             => number_format((float) ($data['price'] ?? 0), 2, '.', ''),
            'stock'             => max(0, (int) ($data['stock'] ?? 0)),
            'image_url'         => $image === '' ? null : $image,
            'short_description' => trim((string) ($data['short_description'] ?? '')) ?: null,
            'description'       => trim((string) ($data['description'] ?? '')) ?: null,
            'status'            => in_array($status, self::STATUSES, true) ? $status : 'active',
            'sort_order'        => max(0, (int) ($data['sort_order'] ?? 0)),
        ];

        /* P.V. is only written when the caller supplies it: the admin form
         * always does, and an older caller that does not must not wipe a
         * configuration it knows nothing about. */
        if (array_key_exists('pv', $data)) {
            $record['pv'] = number_format(max(0.0, (float) ($data['pv'] ?? 0)), 2, '.', '');
        }

        if ($unilevel !== null) {
            $base = (string) ($data['unilevel_base'] ?? 'auto');

            $record['unilevel_enabled'] = !empty($data['unilevel_enabled']) ? 1 : 0;
            $record['unilevel_base']    = in_array($base, ProductUnilevel::BASES, true) ? $base : 'auto';
        }

        /* The configuration as it stands before this write, and as it will
         * stand after it. `pv` falls back to the stored value because a
         * caller may save a ladder without touching the point value. */
        $before = $unilevel === null
            ? null
            : ProductUnilevel::snapshot($existing, $id !== null ? ProductUnilevel::levels($id) : []);

        $after = $unilevel === null ? null : ProductUnilevel::snapshot([
            'pv'               => $record['pv'] ?? ($existing['pv'] ?? 0),
            'unilevel_enabled' => $record['unilevel_enabled'] ?? 0,
            'unilevel_base'    => $record['unilevel_base'] ?? 'auto',
        ], $unilevel);

        $changes = $before === null || $after === null
            ? []
            : ProductUnilevel::diff($before, $after);

        $pdo = db();

        /* Only own the transaction when there is no ambient one (the test
         * harness opens one per case). Either way the row and the ladder
         * are atomic from the caller's point of view. */
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            if ($existing !== null) {
                $record['slug'] = (string) ($existing['slug'] ?? '') !== ''
                    ? (string) $existing['slug']
                    : self::slugFor($record['name'], $id);

                $sets = [];
                $args = [];
                foreach ($record as $column => $value) {
                    $sets[] = $column . ' = ?';
                    $args[] = $value;
                }
                $args[] = $id;

                $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);

                $productId = $id;
            } else {
                $record['slug'] = self::slugFor($record['name']);

                $columns = array_keys($record);
                $marks   = implode(', ', array_fill(0, count($columns), '?'));

                $pdo->prepare(
                    'INSERT INTO products (' . implode(', ', $columns) . ') VALUES (' . $marks . ')'
                )->execute(array_values($record));

                $productId = (int) $pdo->lastInsertId();
            }

            if ($unilevel !== null) {
                ProductUnilevel::save($productId, $unilevel, $pdo);

                if ($changes !== []) {
                    ProductUnilevel::audit(
                        $productId,
                        (string) $record['name'],
                        $before ?? [],
                        $after ?? [],
                        $changes,
                        $actor,
                        $pdo
                    );
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        Live::touch(['admin.products', 'admin.dashboard']);
        Live::touchShop($productId);

        return $productId;
    }

    /** Flip a product between active and inactive. */
    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown product status: ' . $status);
        }

        $product = self::find($id);

        if ($product === null) {
            return false;
        }

        db()->prepare('UPDATE products SET status = ? WHERE id = ?')->execute([$status, $id]);

        /* A no-op toggle must not invalidate every open product list. */
        if ((string) $product['status'] !== $status) {
            Live::touch(['admin.products', 'admin.dashboard']);
            Live::touchShop($id);
        }

        return true;
    }

    /**
     * Delete a product unless something still references it.
     *
     * Order lines are permanent records; cart lines block too,
     * because silently emptying a member's cart is a side effect
     * nobody asked for (reference behaviour, defect C-series).
     * Package contents block as well — deleting the product would
     * otherwise hit the package_products foreign key (Phase 3).
     * A repeat-purchase award blocks too: `unilevel_awards.product_id`
     * is `ON DELETE RESTRICT`, so the guard must refuse before the FK
     * does (Phase 17B). An awarded product always has order history,
     * so in practice the order guard fires first.
     *
     * @return array{ok: bool, reason: ?string, orders: int, carts: int, packages: int, awards: int}
     *         reason: null | missing | referenced
     */
    public static function delete(int $id): array
    {
        $product = self::find($id);

        if ($product === null) {
            return ['ok' => false, 'reason' => 'missing', 'orders' => 0, 'carts' => 0, 'packages' => 0, 'awards' => 0];
        }

        $refs = self::referencesMap([$id])[$id]
            ?? ['orders' => 0, 'carts' => 0, 'packages' => 0, 'awards' => 0];

        if ($refs['orders'] > 0 || $refs['carts'] > 0 || $refs['packages'] > 0 || $refs['awards'] > 0) {
            return [
                'ok'       => false,
                'reason'   => 'referenced',
                'orders'   => $refs['orders'],
                'carts'    => $refs['carts'],
                'packages' => $refs['packages'],
                'awards'   => $refs['awards'],
            ];
        }

        if (!empty($product['image_url'])) {
            UploadService::remove((string) $product['image_url']);
        }

        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);

        Live::touch(['admin.products', 'admin.dashboard']);
        Live::touchShop($id);

        return ['ok' => true, 'reason' => null, 'orders' => 0, 'carts' => 0, 'packages' => 0, 'awards' => 0];
    }

    /**
     * Reference counts for many products at once (no N+1).
     * Tables that belong to later phases simply count as zero.
     *
     * `awards` counts repeat-purchase award rows (Phase 17B reads the
     * table 17A created); it is empty until 17C pays anything.
     *
     * @param list<int|string> $ids
     * @return array<int, array{orders: int, carts: int, packages: int, awards: int}>
     */
    public static function referencesMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['orders' => 0, 'carts' => 0, 'packages' => 0, 'awards' => 0];
        }

        $tables = [
            'orders'   => 'shop_order_items',
            'carts'    => 'cart_items',
            'packages' => 'package_products',
            'awards'   => 'unilevel_awards',
        ];

        foreach ($tables as $key => $table) {
            if (!self::tableExists($table)) {
                continue;
            }

            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt  = db()->prepare(
                'SELECT product_id AS pid, COUNT(*) AS n FROM ' . $table
                . ' WHERE product_id IN (' . $marks . ') GROUP BY product_id'
            );
            $stmt->execute($ids);

            foreach ($stmt->fetchAll() as $row) {
                $out[(int) $row['pid']][$key] = (int) $row['n'];
            }
        }

        return $out;
    }

    /** Unique slug derived from the name (adds -2, -3, … on collision). */
    public static function slugFor(string $name, ?int $ignoreId = null): string
    {
        $base = slugify($name);
        if ($base === '') {
            $base = 'product';
        }

        $base = mb_substr($base, 0, 160);
        $slug = $base;

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $sql  = 'SELECT COUNT(*) FROM products WHERE slug = ?' . ($ignoreId !== null ? ' AND id <> ?' : '');
            $args = $ignoreId !== null ? [$slug, $ignoreId] : [$slug];

            $stmt = db()->prepare($sql);
            $stmt->execute($args);

            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }

            $tail = '-' . $suffix;
            $slug = mb_substr($base, 0, 160 - strlen($tail)) . $tail;
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    /** Does a table exist in the current schema? (later-phase references) */
    private static function tableExists(string $table): bool
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
