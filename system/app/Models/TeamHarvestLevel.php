<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestLevel — the ten configured amounts and the 3×10
 *  matrix arithmetic (plan §6 Phase 20B, §12.6, §13.3, Appendix A)
 *
 *  The one place that turns `team_harvest_levels` into money and the
 *  one place that derives the matrix's structure:
 *
 *      slots(L)     = 3^L                    3, 9, 27 … 59,049
 *      threshold(L) = (3^(L+1) − 3) / 2      3, 12, 39 … 88,572
 *
 *  Only the **amounts** are configurable (D-18); the slots and the
 *  thresholds are structural facts and no setting may move them.
 *
 *  Reads are zero-filled exactly like ProductUnilevel: the table has
 *  ten rows from the moment migration 024 runs, but a missing row
 *  reads `0.00` rather than failing, so a truncated ladder pays
 *  nothing instead of paying at a default nobody chose.
 *
 *  Writes happen in exactly one method (`save()`), which rewrites all
 *  ten rows — never nine (Rule 1, D-10) — inside the caller's
 *  transaction and records the actor on each row. A save with nothing
 *  changed writes nothing and returns an empty change set, so 20B's
 *  card can audit only what actually moved.
 *
 *  Nothing here writes an award, a queue row or a wallet movement:
 *  20B is configuration and 20D is the money engine.
 * ============================================================== */

final class TeamHarvestLevel
{
    /** First ladder level (`team_harvest_levels.level`). */
    public const LEVEL_MIN = 1;

    /** Last ladder level (`team_harvest_levels.level`); the matrix keeps
     *  growing in theory but only these ten pay (D-18). */
    public const LEVEL_MAX = 10;

    /** Per-level ceiling in pesos (Rule 2, §12.10). */
    public const MAX_AMOUNT = 10000000.0;

    /**
     * Full-ladder ceiling in pesos (Rule 3, §12.10).
     *
     * A **typo guard**, not a payout cap: the reference ladder totals
     * ₱5,095,000.00, so ₱25,000,000.00 is five times the plan and still
     * small enough that a slipped zero is refused instead of saved.
     */
    public const MAX_TOTAL = 25000000.0;

    /** The qualification gate's range (§12.2, Rule 5). */
    public const GATE_MIN = 1;
    public const GATE_MAX = 10;
    public const GATE_DEFAULT = 3;

    /** The payable depth's range (§12.6 Rule 3, Rule 5). */
    public const DEPTH_MIN = 1;
    public const DEPTH_MAX = 10;
    public const DEPTH_DEFAULT = 10;

    /** The card's ten amount fields: `team_harvest_amounts[1]` … `[10]`. */
    public const AMOUNT_FIELD = 'team_harvest_amounts';

    /**
     * The one rule every amount obeys: nullable (blank means 0), numeric,
     * 0 – 10,000,000, at most two decimals. The card's field catalogue
     * and this model share it so the two can never disagree.
     */
    public const AMOUNT_RULE = 'nullable|numeric|gte:0|lte:10000000|decimal:2';

    /** The cross-field sum error's key (`team_harvest_amounts.sum`). */
    public const SUM_KEY = 'team_harvest_amounts.sum';

    /**
     * The reference ladder (Appendix A), in level order. It is the
     * migration's seeded value and the card's **Load reference ladder**
     * preset — never an automatic default: a save that changes nothing
     * leaves whatever the operator stored.
     */
    private const REFERENCE_AMOUNTS = [
        1000.0,       // level 1
        2000.0,       // level 2
        4000.0,       // level 3
        6000.0,       // level 4
        12000.0,      // level 5
        50000.0,      // level 6
        120000.0,     // level 7
        200000.0,     // level 8
        1200000.0,    // level 9
        3500000.0,    // level 10
    ];

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /**
     * The ten rows, keyed 1 … 10, zero-filled.
     *
     * A missing row is not an error: it is ₱0.00 with no actor stamp.
     * The card's ladder table and the audit snapshot read this shape.
     *
     * @return array<int, array{level:int, amount:float, updated_at:?string, updated_by:?int}>
     */
    public static function rows(?PDO $pdo = null): array
    {
        $rows = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $rows[$level] = [
                'level'      => $level,
                'amount'     => 0.0,
                'updated_at' => null,
                'updated_by' => null,
            ];
        }

        $pdo ??= db();

        $statement = $pdo->query(
            'SELECT level, amount, updated_at, updated_by FROM team_harvest_levels ORDER BY level'
        );

        foreach ($statement->fetchAll() as $row) {
            $level = (int) $row['level'];

            if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
                continue;
            }

            $rows[$level] = [
                'level'      => $level,
                'amount'     => self::centsToMoney($row['amount']),
                'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
                'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
            ];
        }

        return $rows;
    }

    /**
     * The ten amounts in one query, keyed 1 … 10 (a missing row is 0.0).
     *
     * This is the bulk read (the shape RoyaltyRank::map() established):
     * a caller that needs several levels asks once.
     *
     * @return array<int, float>
     */
    public static function map(?PDO $pdo = null): array
    {
        $map = self::blank();

        $pdo ??= db();

        $statement = $pdo->query('SELECT level, amount FROM team_harvest_levels ORDER BY level');

        foreach ($statement->fetchAll() as $row) {
            $level = (int) $row['level'];

            if ($level >= self::LEVEL_MIN && $level <= self::LEVEL_MAX) {
                $map[$level] = self::centsToMoney($row['amount']);
            }
        }

        return $map;
    }

    /**
     * The configured ladder, keyed 1 … 10.
     *
     * 20C and 20D may read a level amount **only** through this method or
     * `amount()` (plan §6 Hand-off to 20C).
     *
     * @return array<int, float>
     */
    public static function amounts(?PDO $pdo = null): array
    {
        return self::map($pdo);
    }

    /**
     * One level's amount. A level outside 1 … 10 reads ₱0.00 rather than
     * throwing: a card or a report that lists a level the ladder does not
     * have shows nothing, not an error (P20B-T12).
     */
    public static function amount(int $level, ?PDO $pdo = null): float
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            return 0.0;
        }

        return self::map($pdo)[$level];
    }

    /* ----------------------------------------------------------
     *  The matrix arithmetic (structural, never configurable)
     * ---------------------------------------------------------- */

    /**
     * Level L holds `3^L` teammates (plan §12.5, §12.6).
     *
     * A level outside 1 … 10 is a programming error: the ladder has ten
     * levels and a caller asking for an eleventh has a bug, not a value.
     */
    public static function slots(int $level): int
    {
        self::assertLevel($level);

        return 3 ** $level;
    }

    /**
     * Level L completes at `(3^(L+1) − 3) / 2` teammates: 3, 12, 39, 120
     * … 88,572 (plan §12.5, Appendix A).
     *
     * Not a setting (D-18). A level outside 1 … 10 is refused with an
     * `InvalidArgumentException`, because a threshold of 0 would make a
     * level pay on an empty matrix (P20B-T12).
     */
    public static function threshold(int $level): int
    {
        self::assertLevel($level);

        return intdiv(3 ** ($level + 1) - 3, 2);
    }

    /**
     * The full ladder's amount: the per-member maximum of this stream
     * (plan §12.6 Rule 4, §12.8 E). Reads the stored ladder unless the
     * caller passes one (the card's preview and the tests do).
     *
     * @param array<int, float|int|string>|null $amounts
     */
    public static function total(?array $amounts = null, ?PDO $pdo = null): float
    {
        return self::sum($amounts ?? self::amounts($pdo));
    }

    /**
     * The running total after each level, keyed 1 … 10: `cumulative(L)` is
     * what a member who completed levels 1 … L has earned.
     *
     * @param array<int, float|int|string>|null $amounts
     * @return array<int, float>
     */
    public static function cumulative(?array $amounts = null, ?PDO $pdo = null): array
    {
        $ladder = $amounts ?? self::amounts($pdo);
        $cents  = 0;
        $result = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $cents += self::toCents($ladder[$level] ?? 0);
            $result[$level] = $cents / 100;
        }

        return $result;
    }

    /**
     * What a member with `n` teammates has earned from the ladder — the
     * **pure function** over the amounts, with no tree, no ledger and no
     * database round trip of its own (plan §12.8 E, §16.5).
     *
     *      earnedFor(88,572) = 5,095,000.00     (level 10 complete)
     *      earnedFor(88,571) = 1,595,000.00     (the last teammate is missing)
     *      earnedFor(39)     = 7,000.00
     *      earnedFor(2)      = 0.00
     *
     * `$maxLevels` caps the payable depth exactly like the setting; null
     * means all ten levels, which is what the pure-function cases assert
     * (a case that sets the depth calls the engine, not this function).
     *
     * @param array<int, float|int|string>|null $amounts
     */
    public static function earnedFor(int $n, ?array $amounts = null, ?int $maxLevels = null, ?PDO $pdo = null): float
    {
        $ladder = $amounts ?? self::amounts($pdo);
        $depth  = $maxLevels === null
            ? self::LEVEL_MAX
            : self::clamp($maxLevels, self::LEVEL_MIN, self::LEVEL_MAX);
        $cents  = 0;

        for ($level = self::LEVEL_MIN; $level <= $depth; $level++) {
            if ($n < self::threshold($level)) {
                break;
            }

            $cents += self::toCents($ladder[$level] ?? 0);
        }

        return $cents / 100;
    }

    /**
     * The next level a member with `n` teammates can complete, and how
     * far into its block they are — the progress card's whole payload
     * (plan §12.5, §14.2, P20F-T2):
     *
     *      n = 1  → level 1, needed 3,   filled 1/3,   remaining 2,  33.3 %
     *      n = 5  → level 2, needed 12,  filled 2/9,   remaining 7,  22.2 %
     *      n = 39 → level 4, needed 120, filled 0/81,  remaining 81,  0.0 %
     *
     * Returns an empty array when every payable level is already
     * complete (a member at the top of the configured depth).
     *
     * @param array<int, float|int|string>|null $amounts
     * @return array{level:int, threshold:int, needed:int, filled:int, slots:int,
     *               remaining:int, percent:float, amount:float}|array{}
     */
    public static function statusForNext(int $n, ?int $maxLevels = null, ?array $amounts = null, ?PDO $pdo = null): array
    {
        $depth  = $maxLevels === null
            ? self::maxLevels()
            : self::clamp($maxLevels, self::LEVEL_MIN, self::LEVEL_MAX);
        $ladder = $amounts ?? self::amounts($pdo);

        for ($level = self::LEVEL_MIN; $level <= $depth; $level++) {
            $threshold = self::threshold($level);

            if ($n < $threshold) {
                $slots  = self::slots($level);
                $start  = $threshold - $slots;
                $filled = max(0, min($slots, $n - $start));

                return [
                    'level'     => $level,
                    'threshold' => $threshold,
                    'needed'    => $threshold,
                    'filled'    => $filled,
                    'slots'     => $slots,
                    'remaining' => $threshold - $n,
                    'percent'   => round(100 * $filled / $slots, 1),
                    'amount'    => self::centsToMoney($ladder[$level] ?? 0),
                ];
            }
        }

        return [];
    }

    /* ----------------------------------------------------------
     *  The three knobs (plan §12.2, §12.6 Rule 3, D-09)
     * ---------------------------------------------------------- */

    /** The direct members a member needs to enter the queue (1 … 10). */
    public static function qualifyDirects(): int
    {
        return self::clamp(
            setting_int('team_harvest_qualify_directs', self::GATE_DEFAULT),
            self::GATE_MIN,
            self::GATE_MAX
        );
    }

    /** The deepest level that may pay (1 … 10); levels above it are shown
     *  as complete but not paid, and their amounts are kept. */
    public static function maxLevels(): int
    {
        return self::clamp(
            setting_int('team_harvest_max_levels', self::DEPTH_DEFAULT),
            self::DEPTH_MIN,
            self::DEPTH_MAX
        );
    }

    /** The one switch (D-09). Off writes no award; the queue keeps growing. */
    public static function enabled(): bool
    {
        return setting_bool('team_harvest_enabled', false);
    }

    /* ----------------------------------------------------------
     *  Validation (plan §12.10)
     * ---------------------------------------------------------- */

    /**
     * Validate the ten amounts, and the two cross-field rules:
     *
     *    • the ten amounts may not total more than ₱25,000,000.00 — a
     *      `team_harvest_amounts.sum` error, never a silent clamp (Rule 3);
     *    • an enabled stream may not hold an all-zero ladder — an error on
     *      `team_harvest_enabled` (Rule 4).
     *
     * `$amounts` is keyed 1 … 10; blank and 0 are the same value (Rule 1).
     * The per-level messages are the card's own, because both read
     * `AMOUNT_RULE`.
     *
     * @param array<int|string, mixed> $amounts
     * @return array<string, string> field => message
     */
    public static function validate(array $amounts, bool $enabled = false): array
    {
        $values = [];
        $rules  = [];
        $labels = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $field = self::AMOUNT_FIELD . '.' . $level;
            $raw   = $amounts[$level] ?? '';

            $values[$field] = is_scalar($raw) ? trim((string) $raw) : '';
            $rules[$field]  = self::AMOUNT_RULE;
            $labels[$field] = 'Level ' . $level . ' amount';
        }

        $errors = Validator::check($values, $rules, $labels);

        /* The sum is only meaningful when every value is a number; a
         * per-level error already tells the operator what to fix. */
        $cents   = 0;
        $numeric = true;

        foreach ($values as $raw) {
            if ($raw === '') {
                continue;
            }

            if (!is_numeric($raw)) {
                $numeric = false;
                break;
            }

            $cents += self::toCents($raw);
        }

        if ($numeric) {
            $ceiling = self::toCents(self::MAX_TOTAL);

            if ($cents > $ceiling) {
                $errors[self::SUM_KEY] = sprintf(
                    'The ten amounts total %s — the full ladder cannot exceed %s. Lower a level instead of saving this ladder.',
                    money($cents / 100),
                    money(self::MAX_TOTAL)
                );
            }

            if ($enabled && $cents <= 0) {
                $errors['team_harvest_enabled'] = 'Turn the switch off or give at least one level an amount above zero.';
            }
        }

        return $errors;
    }

    /* ----------------------------------------------------------
     *  Form shape
     * ---------------------------------------------------------- */

    /**
     * The ten amounts as the card's input values: 0 renders as a blank
     * input, so "not set" and "₱0.00" look the same on screen (Rule 1).
     *
     * @param array<int, float|int|string> $amounts
     * @return array<int, string>
     */
    public static function forForm(array $amounts): array
    {
        $form = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $value = self::centsToMoney($amounts[$level] ?? 0);
            $form[$level] = $value > 0 ? number_format($value, 2, '.', '') : '';
        }

        return $form;
    }

    /**
     * Levels 1 … 10 from a posted form, as strings so a blank stays blank
     * when the card re-renders after a refused save.
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
     * The reference ladder (Appendix A), keyed 1 … 10. The seeded preset;
     * the card offers it behind **Load reference ladder** and never
     * restores it by itself (Rule 9).
     *
     * @return array<int, float>
     */
    public static function referencePreset(): array
    {
        return array_combine(range(self::LEVEL_MIN, self::LEVEL_MAX), self::REFERENCE_AMOUNTS);
    }

    /* ----------------------------------------------------------
     *  The one write
     * ---------------------------------------------------------- */

    /**
     * Rewrite the ladder: **ten** rows, levels 1 … 10, never nine
     * (Rule 1, D-10). Runs inside the caller's transaction when there is
     * one, so the card's switches and its amounts are atomic; a refusal
     * anywhere leaves both stores untouched (P20B-T14).
     *
     * A save rewrites all ten rows even when the values did not move, exactly
     * as Rule 1 asks ("ten rows, always"); the *audit* is what is written
     * only when something changed, and the caller owns that decision because
     * it also owns the three knobs (§15.4, plan §6 Rule 3).
     *
     * `$actor` (the signed-in admin, when there is one) is stamped on
     * every row's `updated_by`. The audit row itself belongs to the card
     * (`team_harvest.ladder.update`, written by the caller in the same
     * transaction), because only the caller knows the three knobs that
     * belong in the same before/after payload (§15.4).
     *
     * @param array<int|string, mixed> $amounts keyed 1 … 10 (blank = 0)
     * @param array<string, mixed>|null $actor
     * @return array{before: array<int, string>, after: array<int, string>, changes: array<string, array{from:string, to:string}>}
     */
    public static function save(array $amounts, ?array $actor = null, ?PDO $pdo = null): array
    {
        $values  = self::normalise($amounts);
        $before  = self::snapshot();
        $after   = self::snapshotOf($values);
        $changes = self::diff($before, $after);
        $result  = ['before' => $before, 'after' => $after, 'changes' => $changes];

        $pdo ??= db();

        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $pdo->exec('DELETE FROM team_harvest_levels');

            $insert = $pdo->prepare(
                'INSERT INTO team_harvest_levels (level, amount, updated_by) VALUES (?, ?, ?)'
            );

            $actorId = $actor === null ? null : (int) ($actor['id'] ?? 0);
            $actorId = $actorId !== null && $actorId > 0 ? $actorId : null;

            for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
                $insert->execute([
                    $level,
                    number_format($values[$level], 2, '.', ''),
                    $actorId,
                ]);
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

        return $result;
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
     * The ten amounts normalised to floats, refusing anything the
     * database's `DECIMAL(12,2)` or the plan's ceiling could not hold.
     * The card validates first; this is the guard for a direct call.
     *
     * @param array<int|string, mixed> $amounts
     * @return array<int, float>
     */
    private static function normalise(array $amounts): array
    {
        $values = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $raw = $amounts[$level] ?? '';

            if (is_string($raw) && trim($raw) === '') {
                $values[$level] = 0.0;
                continue;
            }

            if (!is_numeric($raw)) {
                throw new InvalidArgumentException('Level ' . $level . ' amount must be a number.');
            }

            $value = self::centsToMoney($raw);

            if ($value < 0 || $value > self::MAX_AMOUNT) {
                throw new InvalidArgumentException(sprintf(
                    'Level %d amount must be between %s and %s.',
                    $level,
                    money(0),
                    money(self::MAX_AMOUNT)
                ));
            }

            $values[$level] = $value;
        }

        if (self::sum($values) > self::MAX_TOTAL) {
            throw new InvalidArgumentException(sprintf(
                'The ten amounts total %s; the full ladder cannot exceed %s.',
                money(self::sum($values)),
                money(self::MAX_TOTAL)
            ));
        }

        return $values;
    }

    /**
     * The stored ladder as money strings, for the audit's before/after.
     *
     * @return array<int, string>
     */
    private static function snapshot(): array
    {
        $snapshot = [];

        foreach (self::amounts() as $level => $amount) {
            $snapshot[$level] = self::fixed($amount);
        }

        return $snapshot;
    }

    /**
     * A ladder as money strings, for the audit's before/after.
     *
     * @param array<int, float> $amounts
     * @return array<int, string>
     */
    private static function snapshotOf(array $amounts): array
    {
        $snapshot = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $snapshot[$level] = self::fixed($amounts[$level] ?? 0);
        }

        return $snapshot;
    }

    /**
     * Only the levels that moved, keyed `levels.N` (the shape the settings
     * card's audit rows use for a nested change set).
     *
     * @param array<int, string> $before
     * @param array<int, string> $after
     * @return array<string, array{from:string, to:string}>
     */
    private static function diff(array $before, array $after): array
    {
        $changes = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $from = (string) ($before[$level] ?? '0.00');
            $to   = (string) ($after[$level] ?? '0.00');

            if ($from !== $to) {
                $changes['levels.' . $level] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /** A level outside 1 … 10 is a programming error, not a value. */
    private static function assertLevel(int $level): void
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException(sprintf(
                'A Team Harvest level is %d–%d; got %d.',
                self::LEVEL_MIN,
                self::LEVEL_MAX,
                $level
            ));
        }
    }

    /**
     * One row amount (or a posted value) as pesos rounded to the centavo.
     * Money never travels as a float past this point in a snapshot.
     */
    private static function centsToMoney(float|int|string|null $value): float
    {
        return self::toCents($value) / 100;
    }

    /** Pesos → integer centavos, the engine's unit (§12.9). */
    private static function toCents(float|int|string|null $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    /** The `DECIMAL(12,2)` storage shape: 1000 → "1000.00". */
    private static function fixed(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** @param array<int, float> $amounts */
    private static function sum(array $amounts): float
    {
        $cents = 0;

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $cents += self::toCents($amounts[$level] ?? 0);
        }

        return $cents / 100;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
