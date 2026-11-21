<?php
declare(strict_types=1);

/* ==============================================================
 *  ProductUnilevel — the per-product repeat-purchase ladder
 *  (plan §6 Phase 17B, §12.3–12.4, §12.9, §13.2)
 *
 *  One ladder per product: levels 1 … 10, each a percentage of the
 *  line's bonus base (`baseFor()`). The shape is deliberately flat:
 *
 *    • Level 1 is the buyer's direct sponsor and is simply the first
 *      row. There is no direct/indirect split, no separate direct
 *      field, no read-only row and no `type` column anywhere (D-17).
 *    • A missing row means 0%. `levels()` zero-fills exactly like
 *      Package::levels(), so a migrated product reads 0 at every
 *      level and an unconfigured product pays nothing.
 *    • Zero means "no award, level consumed" for the engine (17C):
 *      the level still counts, the next upline keeps its own number.
 *    • The ten rates must add up to at most 100% — a field error,
 *      never a silent clamp (D-18).
 *
 *  Nothing here moves money: 17B only stores and validates the
 *  configuration. The engine that reads it is 17C.
 * ============================================================== */

final class ProductUnilevel
{
    /** First level stored in product_unilevel_levels. */
    public const LEVEL_MIN = 1;

    /** Last level stored in product_unilevel_levels. */
    public const LEVEL_MAX = 10;

    /** No single level may exceed 100%. */
    public const MAX_RATE = 100.0;

    /** The whole ladder may not exceed 100%. */
    public const MAX_SUM = 100.0;

    /** The per-product bonus-base choices (`products.unilevel_base`). */
    public const BASES = ['auto', 'price', 'pv'];

    /**
     * The reference ladder, in level order: 5/5/5/3/3/2/2/2/1/1 = 29%
     * (plan §12.4, Appendix A). It is a **preset**, not a default: a
     * new product is pre-filled with it but its switch stays off
     * (D-11), and a migrated product reads 0 everywhere.
     */
    private const REFERENCE_RATES = [5.0, 5.0, 5.0, 3.0, 3.0, 2.0, 2.0, 2.0, 1.0, 1.0];

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /**
     * The ten rates for one product, always 10 keys (1 … 10),
     * zero-filled so a missing row behaves like 0.000%.
     *
     * @return array<int, float>
     */
    public static function levels(int $productId, ?PDO $pdo = null): array
    {
        $levels = self::blank();

        if ($productId <= 0) {
            return $levels;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            'SELECT level, percent FROM product_unilevel_levels WHERE product_id = ? ORDER BY level'
        );
        $stmt->execute([$productId]);

        foreach ($stmt->fetchAll() as $row) {
            $level = (int) $row['level'];

            if ($level >= self::LEVEL_MIN && $level <= self::LEVEL_MAX) {
                $levels[$level] = (float) $row['percent'];
            }
        }

        return $levels;
    }

    /**
     * The ladder for many products in one query (no N+1).
     *
     * @param list<int|string> $productIds
     * @return array<int, array<int, float>>
     */
    public static function levelsMap(array $productIds, ?PDO $pdo = null): array
    {
        $ids = self::intIds($productIds);

        if ($ids === []) {
            return [];
        }

        $pdo ??= db();

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = self::blank();
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $pdo->prepare(
            'SELECT product_id, level, percent FROM product_unilevel_levels
              WHERE product_id IN (' . $marks . ') ORDER BY level'
        );
        $stmt->execute($ids);

        foreach ($stmt->fetchAll() as $row) {
            $productId = (int) $row['product_id'];
            $level     = (int) $row['level'];

            if ($level >= self::LEVEL_MIN && $level <= self::LEVEL_MAX) {
                $map[$productId][$level] = (float) $row['percent'];
            }
        }

        return $map;
    }

    /**
     * The ladder total per product in one query — the number the list
     * badge and the form header show. Both read this map, so the badge
     * can never disagree with the saved rows.
     *
     * @param list<int|string> $productIds
     * @return array<int, float>
     */
    public static function totalsMap(array $productIds, ?PDO $pdo = null): array
    {
        $ids = self::intIds($productIds);

        if ($ids === []) {
            return [];
        }

        $pdo ??= db();

        $totals = [];
        foreach ($ids as $id) {
            $totals[$id] = 0.0;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $pdo->prepare(
            'SELECT product_id, SUM(percent) AS total FROM product_unilevel_levels
              WHERE product_id IN (' . $marks . ') GROUP BY product_id'
        );
        $stmt->execute($ids);

        foreach ($stmt->fetchAll() as $row) {
            $totals[(int) $row['product_id']] = (float) $row['total'];
        }

        return $totals;
    }

    /**
     * The sum of one ladder, rounded to the column's precision.
     *
     * @param array<int, float> $levels
     */
    public static function total(array $levels): float
    {
        $sum = 0.0;

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $sum += (float) ($levels[$level] ?? 0);
        }

        return round($sum, 3);
    }

    /**
     * The bonus base for one order line (plan §12.3).
     *
     *      auto  → pv when pv > 0, else price
     *      price → price
     *      pv    → pv
     *
     * `unit` is per unit, `total` is × quantity, both to the centavo.
     * `basis` records which column was used, so the engine (17C) can
     * explain a zero base. This is the **only** place a base is
     * derived — the engine must not re-implement the choice.
     *
     * @param array<string, mixed> $product needs price, pv, unilevel_base
     * @return array{unit: float, total: float, basis: string}
     */
    public static function baseFor(array $product, int $quantity = 1): array
    {
        $price = round((float) ($product['price'] ?? 0), 2);
        $pv    = round((float) ($product['pv'] ?? 0), 2);
        $base  = (string) ($product['unilevel_base'] ?? 'auto');

        [$unit, $basis] = match (true) {
            $base === 'price' => [$price, 'price'],
            $base === 'pv'    => [$pv, 'pv'],
            default           => [$pv > 0 ? $pv : $price, $pv > 0 ? 'pv' : 'price'],
        };

        $unit  = max(0.0, $unit);
        $total = round($unit * max(0, $quantity), 2);

        return ['unit' => $unit, 'total' => $total, 'basis' => $basis];
    }

    /* ----------------------------------------------------------
     *  Validation
     * ---------------------------------------------------------- */

    /**
     * Validate levels 1–10 from the admin form: numeric, ≥ 0, at most
     * 100 and at most three decimals. Blank is allowed and means 0.
     *
     * @param array<int|string, mixed> $input
     * @return array<string, string> field => message ("unilevel_levels.3" …)
     */
    public static function validateLevels(array $input): array
    {
        $rules  = [];
        $values = [];
        $labels = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $key            = 'unilevel_levels.' . $level;
            $rules[$key]    = 'nullable|numeric|gte:0|lte:' . self::MAX_RATE . '|decimal:3';
            $values[$key]   = $input[$level] ?? '';
            $labels[$key]   = 'Level ' . $level;
        }

        return Validator::check($values, $rules, $labels);
    }

    /**
     * The whole ladder (per-level errors plus the two cross-field
     * rules of plan §12.9):
     *
     *    • the ten rates may not add up to more than 100%;
     *    • a product may not be switched on with every level at 0.
     *
     * @param array<int|string, mixed> $input
     * @return array<string, string> field => message
     */
    public static function validate(array $input, bool $enabled = false): array
    {
        $errors = self::validateLevels($input);

        /* The sum is only meaningful when every value is numeric; a
         * per-level error already tells the operator what to fix. */
        $sum     = 0.0;
        $numeric = true;

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $value = $input[$level] ?? '';

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            if (!is_numeric($value)) {
                $numeric = false;
                continue;
            }

            $sum += (float) $value;
        }

        $sum = round($sum, 3);

        if ($numeric && $sum > self::MAX_SUM) {
            $errors['unilevel_levels.sum'] = sprintf(
                'The ladder totals %s%% — the ten levels cannot add up to more than %s%%.',
                self::percent($sum, 3),
                self::percent(self::MAX_SUM, 3)
            );
        }

        if ($numeric && $enabled && $sum <= 0) {
            $errors['unilevel_enabled'] = 'Turn the switch off or give at least one level a rate above 0.';
        }

        return $errors;
    }

    /* ----------------------------------------------------------
     *  Form shape
     * ---------------------------------------------------------- */

    /**
     * The 1 … 10 form values for a stored ladder: 0 renders as an
     * empty input, so "not set" and "0.000%" look the same on screen
     * (plan §6 Rule 2).
     *
     * @param array<int, float|string> $levels
     * @return array<int, string>
     */
    public static function forForm(array $levels): array
    {
        $form = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $value = (float) ($levels[$level] ?? 0);
            $form[$level] = $value > 0 ? self::percent($value, 3) : '';
        }

        return $form;
    }

    /**
     * Levels 1–10 from the admin form, as strings so blank inputs stay
     * blank when the form is re-rendered after a validation error.
     *
     * @param array<int|string, mixed> $input
     * @return array<int, string>
     */
    public static function fromInput(array $input): array
    {
        $levels = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $value = $input[$level] ?? '';
            $levels[$level] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $levels;
    }

    /**
     * The reference ladder (5/5/5/3/3/2/2/2/1/1, 29%), keyed 1 … 10.
     *
     * @return array<int, float>
     */
    public static function referencePreset(): array
    {
        return array_combine(range(self::LEVEL_MIN, self::LEVEL_MAX), self::REFERENCE_RATES);
    }

    /* ----------------------------------------------------------
     *  Audit snapshot
     * ---------------------------------------------------------- */

    /**
     * The part of a product the ladder configuration consists of, as
     * strings, so a before/after audit payload is exact and JSON-safe.
     *
     * @param array<string, mixed>|null $product products row (null on create)
     * @param array<int, float|string>  $levels  levels 1 … 10
     * @return array{pv: string, enabled: int, base: string, levels: array<int, string>}
     */
    public static function snapshot(?array $product, array $levels): array
    {
        $stored = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $stored[$level] = self::fixed((float) ($levels[$level] ?? 0));
        }

        return [
            'pv'      => number_format((float) ($product['pv'] ?? 0), 2, '.', ''),
            'enabled' => !empty($product['unilevel_enabled']) ? 1 : 0,
            'base'    => (string) ($product['unilevel_base'] ?? 'auto'),
            'levels'  => $stored,
        ];
    }

    /**
     * What actually changed between two snapshots, keyed for the audit
     * payload (`pv`, `enabled`, `base`, `levels.4`, …). An empty array
     * means the ladder configuration is untouched.
     *
     * @param array{pv: string, enabled: int, base: string, levels: array<int, string>} $before
     * @param array{pv: string, enabled: int, base: string, levels: array<int, string>} $after
     * @return array<string, array{from: int|string, to: int|string}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach (['pv', 'enabled', 'base'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[$key] = ['from' => $before[$key] ?? '', 'to' => $after[$key] ?? ''];
            }
        }

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $from = (string) ($before['levels'][$level] ?? '0.000');
            $to   = (string) ($after['levels'][$level] ?? '0.000');

            if ($from !== $to) {
                $changes['levels.' . $level] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * Record that a product's repeat-purchase configuration changed.
     *
     * Written inside the caller's transaction, so an audit entry and the
     * rows it describes can never disagree (plan §6 Rule 7). Only the
     * keys that moved are in `changes`; `before` and `after` are the
     * complete snapshots, which is what finance reads when a ladder is
     * changed after money has been paid on it.
     *
     * @param array<string, array{from: int|string, to: int|string}> $changes
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed>|null $actor the signed-in admin, if any
     */
    public static function audit(
        int $productId,
        string $name,
        array $before,
        array $after,
        array $changes,
        ?array $actor = null,
        ?PDO $pdo = null
    ): void {
        AuditLog::record([
            'action'       => 'product.unilevel.update',
            'actor_id'     => $actor === null ? null : (int) ($actor['id'] ?? 0),
            'actor_role'   => $actor === null ? null : (string) ($actor['role'] ?? ''),
            'subject_type' => 'product',
            'subject_id'   => $productId,
            'meta'         => [
                'product' => $name,
                'changes' => $changes,
                'before'  => $before,
                'after'   => $after,
            ],
        ], $pdo);
    }

    /* ----------------------------------------------------------
     *  Writes
     * ---------------------------------------------------------- */

    /**
     * Rewrite one product's ladder: **ten** rows, levels 1 … 10, never
     * nine (plan §6 Rule 2). Levels that are absent or 0 are stored as
     * 0.000, so "blank" and "0" are the same value and a later read
     * needs no special case.
     *
     * Runs inside the caller's transaction when there is one (the
     * product-row write and the ladder are atomic — plan §6 Rule 7).
     *
     * @param array<int, float|string> $levels
     */
    public static function save(int $productId, array $levels, ?PDO $pdo = null): void
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('A ladder needs a product id.');
        }

        $pdo ??= db();

        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $pdo->prepare('DELETE FROM product_unilevel_levels WHERE product_id = ?')->execute([$productId]);

            $insert = $pdo->prepare(
                'INSERT INTO product_unilevel_levels (product_id, level, percent) VALUES (?, ?, ?)'
            );

            for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
                $value = (float) ($levels[$level] ?? 0);
                $value = max(0.0, min(self::MAX_RATE, $value));

                $insert->execute([$productId, $level, number_format($value, 3, '.', '')]);
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
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @return array<int, float> */
    private static function blank(): array
    {
        return array_fill(self::LEVEL_MIN, self::LEVEL_MAX - self::LEVEL_MIN + 1, 0.0);
    }

    /**
     * A rate without trailing zeros: 29.0 → "29", 12.5 → "12.5",
     * 0.125 → "0.125", 0.0 → "0". Used for input values and messages.
     */
    private static function percent(float $value, int $decimals): string
    {
        $text = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * A rate in the column's storage shape: 5.0 → "5.000", 0.0 → "0.000".
     * Audit snapshots use this so before/after compare cleanly.
     */
    private static function fixed(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

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
