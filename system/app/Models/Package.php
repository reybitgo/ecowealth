<?php
declare(strict_types=1);

/* ==============================================================
 *  Package — associate packages (plan §4.2, §6, Phase 3)
 *
 *  A package owns:
 *    • the entry fee, product value and direct referral bonus
 *    • the indirect referral ladder for levels 2–10
 *    • its contents (products + quantities)
 *
 *  Level 1 is the direct referral bonus. It lives on the package
 *  row only; package_levels never stores it, so the two can never
 *  drift apart (P3-T5).
 *
 *  Packages are never deleted — deactivation is the supported path
 *  (plan §7 Phase 3), so nothing historical is destroyed and the
 *  Phase 9 member-reference check has nothing to guard yet.
 * ============================================================== */

final class Package
{
    public const STATUSES = ['active', 'inactive'];

    /** First indirect level stored in package_levels. */
    public const LEVEL_MIN = 2;

    /** Last indirect level stored in package_levels. */
    public const LEVEL_MAX = 10;

    /** Plan §7: no single level amount may exceed this. */
    public const MAX_LEVEL_AMOUNT = 1000000.00;

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = db()->prepare('SELECT * FROM packages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = db()->prepare('SELECT * FROM packages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param bool $activeOnly Only packages that may be offered
     * @return list<array<string, mixed>>
     */
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM packages'
            . ($activeOnly ? " WHERE status = 'active'" : '')
            . ' ORDER BY sort_order ASC, price ASC';

        return db()->query($sql)->fetchAll();
    }

    /**
     * @param array{q?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager): array
    {
        [$where, $args] = self::filterSql($filters);

        $sql = 'SELECT * FROM packages' . $where
            . ' ORDER BY sort_order ASC, price ASC'
            . ' LIMIT ' . $pager->perPage . ' OFFSET ' . $pager->offset;

        $stmt = db()->prepare($sql);
        $stmt->execute($args);

        return $stmt->fetchAll();
    }

    /** @param array{q?: string, status?: string} $filters */
    public static function countAdmin(array $filters): int
    {
        [$where, $args] = self::filterSql($filters);

        $stmt = db()->prepare('SELECT COUNT(*) FROM packages' . $where);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }

    /** @param array{q?: string, status?: string} $filters @return array{0: string, 1: list<string>} */
    private static function filterSql(array $filters): array
    {
        $where = [];
        $args  = [];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'status = ?';
            $args[]  = $status;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like    = '%' . like_escape($q) . '%';
            $where[] = '(name LIKE ? OR slug LIKE ?)';
            array_push($args, $like, $like);
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
    }

    /** @return array{total:int, active:int, inactive:int} */
    public static function stats(): array
    {
        $row = db()->query(
            "SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'inactive') AS inactive
               FROM packages"
        )->fetch() ?: [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
        ];
    }

    /* ----------------------------------------------------------
     *  Relations
     * ---------------------------------------------------------- */

    /**
     * Indirect amounts for one package, always 9 keys (2 … 10),
     * zero-filled so a missing row behaves like ₱0.
     *
     * @return array<int, float>
     */
    public static function levels(int $packageId): array
    {
        $levels = array_fill(self::LEVEL_MIN, self::LEVEL_MAX - self::LEVEL_MIN + 1, 0.0);

        if ($packageId <= 0) {
            return $levels;
        }

        $stmt = db()->prepare('SELECT level, amount FROM package_levels WHERE package_id = ? ORDER BY level');
        $stmt->execute([$packageId]);

        foreach ($stmt->fetchAll() as $row) {
            $level = (int) $row['level'];
            if ($level >= self::LEVEL_MIN && $level <= self::LEVEL_MAX) {
                $levels[$level] = (float) $row['amount'];
            }
        }

        return $levels;
    }

    /**
     * @param list<int|string> $packageIds
     * @return array<int, array<int, float>>
     */
    public static function levelsMap(array $packageIds): array
    {
        $ids = self::intIds($packageIds);
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = array_fill(self::LEVEL_MIN, self::LEVEL_MAX - self::LEVEL_MIN + 1, 0.0);
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = db()->prepare(
            'SELECT package_id, level, amount FROM package_levels
              WHERE package_id IN (' . $marks . ') ORDER BY level'
        );
        $stmt->execute($ids);

        foreach ($stmt->fetchAll() as $row) {
            $packageId = (int) $row['package_id'];
            $level     = (int) $row['level'];

            if ($level >= self::LEVEL_MIN && $level <= self::LEVEL_MAX) {
                $map[$packageId][$level] = (float) $row['amount'];
            }
        }

        return $map;
    }

    /**
     * Package contents, joined with product details.
     *
     * @return list<array{product_id:int, name:string, sku:?string, quantity:int, price:string, status:string}>
     */
    public static function contents(int $packageId): array
    {
        return self::contentsMap([$packageId])[$packageId] ?? [];
    }

    /**
     * @param list<int|string> $packageIds
     * @return array<int, list<array{product_id:int, name:string, sku:?string, quantity:int, price:string, status:string}>>
     */
    public static function contentsMap(array $packageIds): array
    {
        $ids = self::intIds($packageIds);
        if ($ids === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = db()->prepare(
            'SELECT pp.package_id, pp.product_id, pp.quantity,
                    p.name, p.sku, p.price, p.status
               FROM package_products pp
               JOIN products p ON p.id = pp.product_id
              WHERE pp.package_id IN (' . $marks . ')
              ORDER BY p.name ASC'
        );
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['package_id']][] = [
                'product_id' => (int) $row['product_id'],
                'name'       => (string) $row['name'],
                'sku'        => $row['sku'] !== null ? (string) $row['sku'] : null,
                'quantity'   => (int) $row['quantity'],
                'price'      => (string) $row['price'],
                'status'     => (string) $row['status'],
            ];
        }

        return $map;
    }

    /* ----------------------------------------------------------
     *  Validation
     * ---------------------------------------------------------- */

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function validate(array $data): array
    {
        return Validator::check($data, [
            'name'                  => 'required|string|min:2|max:80',
            'price'                 => 'required|numeric|gt:0|decimal:2',
            'product_value'         => 'nullable|numeric|gte:0|decimal:2',
            'direct_referral_bonus' => 'required|numeric|gte:0|decimal:2|lte:1000000',
            'sort_order'            => 'nullable|integer|min:0|max:1000000',
            'description'           => 'nullable|string|max:5000',
            'perks'                 => 'nullable|string|max:5000',
            'status'                => 'required|in:active,inactive',
        ], [
            'name'                  => 'Package name',
            'price'                 => 'Price',
            'product_value'         => 'Product value',
            'direct_referral_bonus' => 'Direct referral bonus',
            'sort_order'            => 'Sort order',
            'description'           => 'Description',
            'perks'                 => 'Perks',
            'status'                => 'Status',
        ]);
    }

    /**
     * Validate levels 2–10: numeric, ≥ 0, at most 2 decimals,
     * at most ₱1,000,000. Blank is allowed and means ₱0.
     *
     * @param array<int|string, mixed> $levels
     * @return array<string, string> field => message ("level_3" …)
     */
    public static function validateLevels(array $levels): array
    {
        $rules  = [];
        $values = [];
        $labels = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $key            = 'level_' . $level;
            $rules[$key]    = 'nullable|numeric|gte:0|lte:1000000|decimal:2';
            $values[$key]   = $levels[$level] ?? '';
            $labels[$key]   = 'Level ' . $level;
        }

        return Validator::check($values, $rules, $labels);
    }

    /**
     * Normalise the contents repeater: drop blank rows, merge duplicate
     * products (quantities add up), and check each product exists,
     * carries a sensible quantity and is still active unless it is
     * already part of this package.
     *
     * @param array<int|string, mixed> $input rows of ['product_id' => …, 'quantity' => …]
     * @return array{rows: list<array{product_id:int, quantity:int}>, errors: array<string, string>}
     */
    public static function normalizeContents(array $input, ?int $packageId = null): array
    {
        $errors = [];
        $merged = [];

        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }

            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $quantity = (int) ($row['quantity'] ?? 0);
            if ($quantity < 1) {
                $errors['contents'] = 'Every package content needs a quantity of at least 1.';

                continue;
            }

            if ($quantity > 1000) {
                $errors['contents'] = 'A package content quantity cannot exceed 1,000.';

                continue;
            }

            $merged[$productId] = ($merged[$productId] ?? 0) + $quantity;
        }

        if ($merged === []) {
            return ['rows' => [], 'errors' => $errors];
        }

        $alreadyLinked = [];
        if ($packageId !== null) {
            foreach (self::contents($packageId) as $content) {
                $alreadyLinked[$content['product_id']] = true;
            }
        }

        $marks  = implode(',', array_fill(0, count($merged), '?'));
        $stmt   = db()->prepare('SELECT id, name, status FROM products WHERE id IN (' . $marks . ')');
        $stmt->execute(array_keys($merged));

        $products = [];
        foreach ($stmt->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        $rows = [];

        foreach ($merged as $productId => $quantity) {
            if (!isset($products[$productId])) {
                $errors['contents'] = 'One of the selected products no longer exists.';
                continue;
            }

            $isActive = (string) $products[$productId]['status'] === 'active';
            if (!$isActive && !isset($alreadyLinked[$productId])) {
                $errors['contents'] = '“' . $products[$productId]['name'] . '” is inactive — only active products can be added.';
                continue;
            }

            $rows[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /* ----------------------------------------------------------
     *  Writes
     * ---------------------------------------------------------- */

    /**
     * Create or update a package with its ladder and contents in one
     * transaction (P3-T3: a failure leaves nothing half-written).
     *
     * @param array<string, mixed>                        $data     package fields
     * @param array<int|string, mixed>                    $levels   level 2–10 => amount
     * @param list<array{product_id:int, quantity:int}>   $contents normalised rows
     */
    public static function save(array $data, array $levels, array $contents, ?int $id = null): int
    {
        $existing = $id !== null ? self::find($id) : null;
        $status   = (string) ($data['status'] ?? 'active');
        $image    = trim((string) ($data['image'] ?? ''));

        $record = [
            'name'                  => trim((string) ($data['name'] ?? '')),
            'image'                 => $image === '' ? null : $image,
            'price'                 => number_format((float) ($data['price'] ?? 0), 2, '.', ''),
            'product_value'         => number_format((float) ($data['product_value'] ?? 0), 2, '.', ''),
            'direct_referral_bonus' => number_format((float) ($data['direct_referral_bonus'] ?? 0), 2, '.', ''),
            'indirect_enabled'      => !empty($data['indirect_enabled']) ? 1 : 0,
            'description'           => trim((string) ($data['description'] ?? '')) ?: null,
            'perks'                 => trim((string) ($data['perks'] ?? '')) ?: null,
            'status'                => in_array($status, self::STATUSES, true) ? $status : 'active',
            'sort_order'            => max(0, (int) ($data['sort_order'] ?? 0)),
        ];

        $pdo = db();

        /* Only own the transaction when there is no ambient one (the test
         * harness opens one per case). Either way, the writes below are
         * atomic from the caller's point of view. */
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

                $pdo->prepare('UPDATE packages SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
                $packageId = $id;
            } else {
                $record['slug'] = self::slugFor($record['name']);

                $columns = array_keys($record);
                $marks   = implode(', ', array_fill(0, count($columns), '?'));

                $pdo->prepare(
                    'INSERT INTO packages (' . implode(', ', $columns) . ') VALUES (' . $marks . ')'
                )->execute(array_values($record));

                $packageId = (int) $pdo->lastInsertId();
            }

            /* Ladder: rewrite levels 2–10 (level 1 is the direct bonus). */
            $pdo->prepare('DELETE FROM package_levels WHERE package_id = ?')->execute([$packageId]);

            $insertLevel = $pdo->prepare(
                'INSERT INTO package_levels (package_id, level, amount) VALUES (?, ?, ?)'
            );

            for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
                $amount = (float) ($levels[$level] ?? 0);
                $amount = max(0.0, min(self::MAX_LEVEL_AMOUNT, $amount));

                $insertLevel->execute([$packageId, $level, number_format($amount, 2, '.', '')]);
            }

            /* Contents: rewrite the bundle. */
            $pdo->prepare('DELETE FROM package_products WHERE package_id = ?')->execute([$packageId]);

            $insertContent = $pdo->prepare(
                'INSERT INTO package_products (package_id, product_id, quantity) VALUES (?, ?, ?)'
            );

            foreach ($contents as $row) {
                $insertContent->execute([$packageId, (int) $row['product_id'], (int) $row['quantity']]);
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

        return $packageId;
    }

    /** Flip a package between active and inactive. */
    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown package status: ' . $status);
        }

        if (self::find($id) === null) {
            return false;
        }

        db()->prepare('UPDATE packages SET status = ? WHERE id = ?')->execute([$status, $id]);

        return true;
    }

    /** Unique slug derived from the name (adds -2, -3, … on collision). */
    public static function slugFor(string $name, ?int $ignoreId = null): string
    {
        $base = slugify($name);
        if ($base === '') {
            $base = 'package';
        }

        $base = mb_substr($base, 0, 90);
        $slug = $base;

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $sql  = 'SELECT COUNT(*) FROM packages WHERE slug = ?' . ($ignoreId !== null ? ' AND id <> ?' : '');
            $args = $ignoreId !== null ? [$slug, $ignoreId] : [$slug];

            $stmt = db()->prepare($sql);
            $stmt->execute($args);

            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }

            $tail = '-' . $suffix;
            $slug = mb_substr($base, 0, 90 - strlen($tail)) . $tail;
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * @param list<int|string> $ids
     * @return list<int>
     */
    private static function intIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }
}
