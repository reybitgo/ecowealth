<?php
declare(strict_types=1);

/* ==============================================================
 *  Product — catalogue model (plan §5.1, Phase 2)
 *
 *  Owns the product rows and the delete guards. Stock maths lives
 *  in StockService so `stock` (physical) and `available` (stock
 *  minus reservations) are never confused.
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
     * @param array{q?: string, status?: string} $filters
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

    /** @param array{q?: string, status?: string} $filters */
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
     * @param array{q?: string, status?: string} $filters
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
            'stock'             => 'required|integer|min:0|max:1000000',
            'sort_order'        => 'nullable|integer|min:0|max:1000000',
            'short_description' => 'nullable|string|max:255',
            'description'       => 'nullable|string|max:5000',
            'status'            => 'required|in:active,inactive',
        ], [
            'name'              => 'Product name',
            'sku'               => 'SKU',
            'price'             => 'Price',
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

        return $errors;
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
     * @param array<string, mixed> $data
     */
    public static function save(array $data, ?int $id = null): int
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

            db()->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);

            return $id;
        }

        $record['slug'] = self::slugFor($record['name']);

        $columns = array_keys($record);
        $marks   = implode(', ', array_fill(0, count($columns), '?'));

        db()->prepare(
            'INSERT INTO products (' . implode(', ', $columns) . ') VALUES (' . $marks . ')'
        )->execute(array_values($record));

        return (int) db()->lastInsertId();
    }

    /** Flip a product between active and inactive. */
    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown product status: ' . $status);
        }

        if (self::find($id) === null) {
            return false;
        }

        db()->prepare('UPDATE products SET status = ? WHERE id = ?')->execute([$status, $id]);

        return true;
    }

    /**
     * Delete a product unless something still references it.
     *
     * Order lines are permanent records; cart lines block too,
     * because silently emptying a member's cart is a side effect
     * nobody asked for (reference behaviour, defect C-series).
     *
     * @return array{ok: bool, reason: ?string, orders: int, carts: int}
     *         reason: null | missing | orders | cart | orders_and_cart
     */
    public static function delete(int $id): array
    {
        $product = self::find($id);

        if ($product === null) {
            return ['ok' => false, 'reason' => 'missing', 'orders' => 0, 'carts' => 0];
        }

        $refs = self::referencesMap([$id])[$id] ?? ['orders' => 0, 'carts' => 0];

        if ($refs['orders'] > 0 || $refs['carts'] > 0) {
            return [
                'ok'     => false,
                'reason' => match (true) {
                    $refs['orders'] > 0 && $refs['carts'] > 0 => 'orders_and_cart',
                    $refs['orders'] > 0 => 'orders',
                    default => 'cart',
                },
                'orders' => $refs['orders'],
                'carts'  => $refs['carts'],
            ];
        }

        if (!empty($product['image_url'])) {
            UploadService::remove((string) $product['image_url']);
        }

        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);

        return ['ok' => true, 'reason' => null, 'orders' => 0, 'carts' => 0];
    }

    /**
     * Reference counts for many products at once (no N+1).
     * Tables that belong to later phases simply count as zero.
     *
     * @param list<int|string> $ids
     * @return array<int, array{orders: int, carts: int}>
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
            $out[$id] = ['orders' => 0, 'carts' => 0];
        }

        $tables = [
            'orders' => 'shop_order_items',
            'carts'  => 'cart_items',
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
