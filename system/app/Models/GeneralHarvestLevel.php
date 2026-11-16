<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestLevel — the ten configured amounts and the whole
 *  shared-board arithmetic (plan §6 Phase 21B, §12.2–§12.4,
 *  §12.9, §13.3, Appendix A, Appendix F)
 *
 *  The one place that turns `general_harvest_levels` into money and
 *  the one place that derives the 3-ary board's structure. Every
 *  formula in §12.2–§12.6 lives here and nowhere else — a view, a
 *  job or a report never types a threshold of its own (Rule 7):
 *
 *      slots(L)      = 3^L                       3, 9, 27 … 59,049
 *      start(q,L)    = 3^L·q + (3^L − 1)/2        0-based first slot
 *      block(q,L)    = positions [start+1 … start+3^L]
 *      filled(q,L)   = clamp(N − start, 0, 3^L)
 *      complete(q,L) ⇔ N ≥ 3^L·q + (3^(L+1) − 1)/2
 *      paid(L,N)     = max(0, floor((N − (3^L − 1)/2) / 3^L))
 *      boardTotal(N) = Σ_L amount(L) · paid(L,N)
 *
 *  Only the **amounts** and the payable **depth** are configurable
 *  (D-18); the slots, the blocks and the thresholds are structural
 *  facts and no setting may move them.
 *
 *  `N` is the only variable. No purchase, rank, month, team size or
 *  sponsor enters any formula here (§12.10); a node's block is filled
 *  by whoever holds the next positions in the queue.
 *
 *  Reads are zero-filled exactly like TeamHarvestLevel: the table has
 *  ten rows from the moment migration 025 runs, but a missing row
 *  reads `0.00` rather than failing, so a truncated ladder pays
 *  nothing instead of paying at a default nobody chose.
 *
 *  Writes happen in exactly one method (`save()`), which rewrites all
 *  ten rows — never nine (Rule 1, D-11) — inside the caller's
 *  transaction and records the actor on each row. The ten amounts are
 *  **frozen from the arm** (D-23): while `general_harvest_board` holds
 *  a baseline, a save that changes one is refused, because a shared
 *  board's whole liability is a closed form over one ladder.
 *
 *  Nothing here writes an award, a queue row, a wallet movement or the
 *  board row: 21B is configuration and arithmetic, 21C arms the board
 *  and 21D pays it.
 * ============================================================== */

final class GeneralHarvestLevel
{
    /** First ladder level (`general_harvest_levels.level`). */
    public const LEVEL_MIN = 1;

    /** Last ladder level; the board is unbounded above it but only
     *  these ten pay, per node (D-18). */
    public const LEVEL_MAX = 10;

    /** Per-level ceiling in pesos (Rule 2, §12.9). */
    public const MAX_AMOUNT = 10000000.0;

    /** Full-ladder ceiling in pesos (Rule 3, §12.9) — a typo guard. */
    public const MAX_TOTAL = 25000000.0;

    /** The payable depth's range (`general_harvest_max_levels`, Rule 5). */
    public const DEPTH_MIN = 1;
    public const DEPTH_MAX = 10;
    public const DEPTH_DEFAULT = 10;

    /** The card's ten amount fields: `general_harvest_amounts[1]` … `[10]`. */
    public const AMOUNT_FIELD = 'general_harvest_amounts';

    /** The payable depth's settings key (a field on the card). */
    public const DEPTH_KEY = 'general_harvest_max_levels';

    /** The switch's settings key (the card's one toggle, D-10). */
    public const ENABLED_KEY = 'general_harvest_enabled';

    /**
     * The one rule every amount obeys: nullable (blank means 0), numeric,
     * 0 – 10,000,000, at most two decimals. The card's field catalogue
     * and this model share it so the two can never disagree.
     */
    public const AMOUNT_RULE = 'nullable|numeric|gte:0|lte:10000000|decimal:2';

    /** The cross-field sum error's key (`general_harvest_amounts.sum`). */
    public const SUM_KEY = 'general_harvest_amounts.sum';

    /**
     * The frozen-ladder error's key (Rule 14, D-23). It is not a field on
     * the card: it names the arm instant and the one way back (a business
     * reset), so it belongs to the whole ladder, not to level 3.
     */
    public const ARMED_KEY = 'armed';

    /**
     * The reference ladder (Appendix A), in level order. It is the
     * migration's seeded value and the card's **Load reference ladder**
     * preset — never an automatic default: a save that changes nothing
     * leaves whatever the operator stored.
     */
    private const REFERENCE_AMOUNTS = [
        2000.0,       // level 1
        4000.0,       // level 2
        8000.0,       // level 3
        12000.0,      // level 4
        25000.0,      // level 5
        100000.0,     // level 6
        250000.0,     // level 7
        480000.0,     // level 8
        2500000.0,    // level 9
        5000000.0,    // level 10
    ];

    /* ----------------------------------------------------------
     *  Reads — the only way a later phase may learn an amount
     * ---------------------------------------------------------- */

    /**
     * The ten rows, keyed 1 … 10, zero-filled.
     *
     * A missing row is not an error: it is ₱0.00 with no actor stamp.
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
            'SELECT level, amount, updated_at, updated_by FROM general_harvest_levels ORDER BY level'
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
     * @return array<int, float>
     */
    public static function map(?PDO $pdo = null): array
    {
        $map = self::blank();

        $pdo ??= db();

        $statement = $pdo->query('SELECT level, amount FROM general_harvest_levels ORDER BY level');

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
     * 21C, 21D, 21E and 21F may read a level amount **only** through this
     * method or `amount()` (plan §6 Hand-off to 21C).
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
     * have shows nothing, not an error.
     */
    public static function amount(int $level, ?PDO $pdo = null): float
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            return 0.0;
        }

        return self::map($pdo)[$level];
    }

    /* ----------------------------------------------------------
     *  The board arithmetic (structural, never configurable)
     * ---------------------------------------------------------- */

    /**
     * Level L's block holds `3^L` positions (§12.2): 3, 9, 27 … 59,049.
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
     * The 0-based index of node `$seq`'s first level-`$level` slot
     * (§12.2): `3^L·q + (3^L − 1)/2`.
     *
     * For the apex level 1 it is 1, so the block is positions #2–#4: the
     * first money is paid at `N = 4`, not at `N = 3`.
     */
    public static function blockStart(int $seq, int $level): int
    {
        self::assertSeq($seq);

        $slots = self::slots($level);

        return $slots * $seq + intdiv($slots - 1, 2);
    }

    /**
     * The 0-based bound after node `$seq`'s level-`$level` block: the
     * block's last position, which is also the queue length at which the
     * block is full (§12.2: `complete ⇔ N ≥ start + 3^L`).
     */
    public static function blockEnd(int $seq, int $level): int
    {
        return self::blockStart($seq, $level) + self::slots($level);
    }

    /** The block's first queue position, 1-based, for display (§12.2). */
    public static function blockFrom(int $seq, int $level): int
    {
        return self::blockStart($seq, $level) + 1;
    }

    /** The block's last queue position, 1-based, for display (§12.2). */
    public static function blockTo(int $seq, int $level): int
    {
        return self::blockEnd($seq, $level);
    }

    /**
     * `N_min(q, L) = 3^L·q + (3^(L+1) − 1)/2` — the queue length at which
     * node `$seq`'s level-`$level` block is full (§12.3).
     *
     * The apex's ten thresholds are the reference's staircase: 4, 13, 40,
     * 121, 364, 1,093, 3,280, 9,841, 29,524, 88,573.
     */
    public static function threshold(int $seq, int $level): int
    {
        return self::blockEnd($seq, $level);
    }

    /** The apex's level-`$level` threshold (`seq = 0`, §12.3). */
    public static function apexThreshold(int $level): int
    {
        return self::threshold(0, $level);
    }

    /**
     * `clamp(N − start, 0, 3^L)` — how many of the block's positions are
     * occupied (§12.2). Information only: a partial block pays nothing.
     */
    public static function filled(int $seq, int $level, int $n): int
    {
        $slots = self::slots($level);
        $start = self::blockStart($seq, $level);

        return max(0, min($slots, max(0, $n) - $start));
    }

    /** Is node `$seq`'s level-`$level` block full at queue length `$n`? */
    public static function complete(int $seq, int $level, int $n): bool
    {
        return max(0, $n) >= self::threshold($seq, $level);
    }

    /**
     * The levels of node `$seq` that are complete at queue length `$n`,
     * shallowest first — the structural answer, with no depth and no
     * money applied.
     *
     * @return list<int>
     */
    public static function levelsFor(int $seq, int $n): array
    {
        $levels = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            if (self::complete($seq, $level, $n)) {
                $levels[] = $level;
            }
        }

        return $levels;
    }

    /**
     * `paid(L, N) = max(0, floor((N − (3^L − 1)/2) / 3^L))` — the number
     * of nodes whose level-`L` block is full at queue length `$n` (§12.3).
     *
     * They are exactly the nodes `0 … paid−1`: completion is monotone in
     * the node index, which is the prefix property the ledger relies on.
     */
    public static function paid(int $level, int $n): int
    {
        self::assertLevel($level);

        $half = intdiv(self::slots($level) - 1, 2);

        return max(0, intdiv(max(0, $n) - $half, self::slots($level)));
    }

    /**
     * `qFor(L, N) = paid(L, N) − 1` — the highest node whose level-`L`
     * block is full, or **−1** when none is (§12.3).
     *
     * This is the settle loop's `Q` and the frontier's closed-form
     * expectation, so it is the single most load-bearing number here.
     */
    public static function qFor(int $level, int $n): int
    {
        return self::paid($level, $n) - 1;
    }

    /**
     * What node `$seq`'s ladder has earned by queue length `$n` — the
     * **pure function** over the amounts, with no tree, no ledger and no
     * database round trip of its own (§12.8 G, Appendix F):
     *
     *      earnedBy(0, 40)     = 14,000.00   levels 1–3
     *      earnedBy(1, 40)     = 6,000.00    levels 1–2
     *      earnedBy(13, 40)    = 0.00        level 1 needs 43
     *      earnedBy(0, 88,573) = 8,381,000.00
     *
     * `$maxLevels` caps the payable depth exactly like the setting; null
     * means all ten levels, which is what the pure-function cases assert
     * (a case that sets the depth calls the engine, not this function).
     *
     * @param array<int, float|int|string>|null $amounts
     */
    public static function earnedBy(
        int $seq,
        int $n,
        ?array $amounts = null,
        ?int $maxLevels = null,
        ?PDO $pdo = null
    ): float {
        $ladder = $amounts ?? self::amounts($pdo);
        $depth  = $maxLevels === null
            ? self::LEVEL_MAX
            : self::clamp($maxLevels, self::LEVEL_MIN, self::LEVEL_MAX);
        $cents  = 0;

        for ($level = self::LEVEL_MIN; $level <= $depth; $level++) {
            if (!self::complete($seq, $level, $n)) {
                continue;
            }

            $cents += self::toCents($ladder[$level] ?? 0);
        }

        return (float) ($cents / 100);
    }

    /**
     * The queue length at which node `$seq` completes level `$level` —
     * `threshold()` under the name the card and the jobs read (§12.3).
     */
    public static function nextN(int $seq, int $level): int
    {
        return self::threshold($seq, $level);
    }

    /**
     * When each level's next block completes, keyed 1 … 10 (§14.2's
     * "what is the queue waiting for" read):
     *
     *      n = 1,204  → level 1 needs 1,207, level 2 1,210, level 3 1,228
     *
     * `node` is the node whose block is next — `qFor(L, n) + 1` — and
     * `next_n` is `threshold(node, L)`. The closed form is monotone in
     * both directions, so no row has to be read.
     *
     * @return array<int, array{level:int, node:int, next_n:int}>
     */
    public static function nextCompletion(int $n): array
    {
        $next = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $node = self::qFor($level, $n) + 1;

            $next[$level] = [
                'level'  => $level,
                'node'   => $node,
                'next_n' => self::threshold($node, $level),
            ];
        }

        return $next;
    }

    /**
     * The whole board's liability at queue length `$n` — the closed form
     * of §12.3/§12.7:
     *
     *      boardTotal(N) = Σ_{L=1..10} amount(L) · paid(L,N)
     *
     * 2,000 at N = 4 · 176,000 at N = 121 · 190,208,000 at N = 88,573.
     * Every report, reconcile check and test compares an observed ledger
     * with this number (D-21), so it is computed in centavos.
     *
     * @param array<int, float|int|string>|null $amounts
     */
    public static function boardTotal(int $n, ?array $amounts = null, ?PDO $pdo = null): float
    {
        $ladder = $amounts ?? self::amounts($pdo);
        $cents  = 0;

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $rows = self::paid($level, $n);

            if ($rows === 0) {
                continue;
            }

            $cents += self::toCents($ladder[$level] ?? 0) * $rows;
        }

        return (float) ($cents / 100);
    }

    /**
     * How many awards the whole board has earned at queue length `$n` —
     * one per completed block (§12.3):
     *
     *      awardCount(1,204) = 543     awardCount(88,573) = 44,281
     */
    public static function awardCount(int $n): int
    {
        $count = 0;

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $count += self::paid($level, $n);
        }

        return $count;
    }

    /**
     * The full ladder's amount: the per-node maximum of this stream
     * (§12.4 Rule 2, Appendix A). Reads the stored ladder unless the
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
     * what a node that completed levels 1 … L has earned.
     *
     * @param array<int, float|int|string>|null $amounts
     * @return array<int, float>
     */
    public static function cumulative(?array $amounts = null, ?PDO $pdo = null): array
    {
        $ladder     = $amounts ?? self::amounts($pdo);
        $cents      = 0;
        $cumulative = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $cents += self::toCents($ladder[$level] ?? 0);
            $cumulative[$level] = (float) ($cents / 100);
        }

        return $cumulative;
    }

    /* ----------------------------------------------------------
     *  The two knobs (plan §12.4 Rule 4, §13.5, D-10/D-18)
     * ---------------------------------------------------------- */

    /** The deepest level that may pay (1 … 10); levels above it are shown
     *  as complete but not paid, and their amounts are kept. */
    public static function maxLevels(): int
    {
        return self::clamp(
            setting_int(self::DEPTH_KEY, self::DEPTH_DEFAULT),
            self::DEPTH_MIN,
            self::DEPTH_MAX
        );
    }

    /** The one switch (D-10). Off writes no award; the queue keeps growing. */
    public static function enabled(): bool
    {
        return setting_bool(self::ENABLED_KEY, false);
    }

    /* ----------------------------------------------------------
     *  Validation (plan §12.9, Rules 2–5, 14)
     * ---------------------------------------------------------- */

    /**
     * Validate the ten amounts and the four cross-field rules:
     *
     *    • the ten amounts may not total more than ₱25,000,000.00 — a
     *      `general_harvest_amounts.sum` error, never a silent clamp (Rule 3);
     *    • a payable level must be positive: the switch cannot go on, and
     *      the depth cannot rise, unless every level up to the depth pays
     *      something (Rule 4, D-17) — the error names the level;
     *    • the ten amounts are frozen while the board is armed (Rule 14,
     *      D-23) — a change is refused with an `armed` error naming the
     *      arm instant.
     *
     * `$amounts` is keyed 1 … 10; blank and 0 are the same value (Rule 1).
     * The per-level messages are the card's own, because both read
     * `AMOUNT_RULE`.
     *
     * `$board` and `$storedMaxLevels` default to the live state; the tests
     * and the card pass them explicitly so a rule can be proved without
     * writing a setting or a board row.
     *
     * @param array<int|string, mixed> $amounts
     * @param array<string, mixed>|null $board GeneralHarvestBoard::state()
     * @return array<string, string> field => message
     */
    public static function validate(
        array $amounts,
        int $maxLevels,
        bool $enabled,
        ?array $board = null,
        ?int $storedMaxLevels = null
    ): array {
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

        /* The cross-field rules are only meaningful when every value is a
         * number; a per-level error already tells the operator what to fix. */
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

        if (!$numeric) {
            return $errors;
        }

        if ($cents > self::toCents(self::MAX_TOTAL)) {
            $errors[self::SUM_KEY] = sprintf(
                'The ten amounts total %s — the full ladder cannot exceed %s. Lower a level instead of saving this ladder.',
                money($cents / 100),
                money(self::MAX_TOTAL)
            );
        }

        /* The two depth rules only speak when every per-level value is a
         * number a money input could hold; otherwise the field error already
         * names the fix, and a second message on the switch would be noise. */
        if ($errors !== []) {
            return self::withFreeze($errors, $values, $board);
        }

        $depth = self::clamp($maxLevels, self::LEVEL_MIN, self::LEVEL_MAX);

        if ($enabled) {
            $unpriced = self::firstUnpriced($values, self::LEVEL_MIN, $depth);

            if ($unpriced !== null) {
                $errors[self::ENABLED_KEY] = sprintf(
                    'Level %d has no amount. The bonus can only be on while every level 1–%d pays something: give level %d an amount, or turn the switch off.',
                    $unpriced,
                    $depth,
                    $unpriced
                );
            }
        }

        /* Raising the depth exposes levels that may still be blank, which is
         * exactly what D-17 forbids: a zero level below the depth can never
         * be paid, and the settlement would skip it silently. */
        $stored = self::clamp($storedMaxLevels ?? self::maxLevels(), self::LEVEL_MIN, self::LEVEL_MAX);

        if ($maxLevels > $stored) {
            $unpriced = self::firstUnpriced($values, $stored + 1, self::clamp($maxLevels, self::LEVEL_MIN, self::LEVEL_MAX));

            if ($unpriced !== null) {
                $errors[self::DEPTH_KEY] = sprintf(
                    'Level %d has no amount, so the depth cannot rise to %d: give levels %d–%d an amount, or keep the depth at %d.',
                    $unpriced,
                    $maxLevels,
                    $stored + 1,
                    $maxLevels,
                    $stored
                );
            }
        }

        return self::withFreeze($errors, $values, $board);
    }

    /**
     * Add the frozen-ladder error when the board is armed and a posted
     * amount differs from the stored one (Rule 14, D-23).
     *
     * @param array<string, string> $errors
     * @param array<string, string> $values keyed `general_harvest_amounts.N`
     * @param array<string, mixed>|null $board
     * @return array<string, string>
     */
    private static function withFreeze(array $errors, array $values, ?array $board): array
    {
        $board ??= GeneralHarvestBoard::state();

        if (!($board['armed'] ?? false)) {
            return $errors;
        }

        $posted = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $raw = (string) ($values[self::AMOUNT_FIELD . '.' . $level] ?? '');

            if ($raw !== '' && !is_numeric($raw)) {
                return $errors;   // the per-level rule already refuses this value
            }

            $posted[$level] = self::centsToMoney($raw);
        }

        if (self::differs($posted, self::amounts())) {
            $errors[self::ARMED_KEY] = self::armedMessage((string) ($board['armed_at'] ?? ''));
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
     * restores it by itself.
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
     * (Rule 1, D-11). Runs inside the caller's transaction when there is
     * one, so the card's switch, its depth and its amounts are atomic; a
     * refusal anywhere leaves both stores untouched.
     *
     * The freeze is enforced here as well as in `validate()` (D-23), so a
     * direct caller cannot move an amount on an armed board: the ten
     * amounts decide a closed-form liability, and a mid-stream change
     * would make `Σ credited = boardTotal(settled_n) − boardTotal(baseline_n)`
     * a fiction.
     *
     * `$actor` (the signed-in admin, when there is one) is stamped on
     * every row's `updated_by`. The audit row itself belongs to the card
     * (`general_harvest.ladder.update`), because only the caller knows the
     * switch and the depth that belong in the same before/after payload.
     *
     * @param array<int|string, mixed> $amounts keyed 1 … 10 (blank = 0)
     * @param array<string, mixed>|null $actor
     * @return array{before: array<int, string>, after: array<int, string>, changes: array<string, array{from:string, to:string}>}
     */
    public static function save(array $amounts, ?array $actor = null, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $values  = self::normalise($amounts);
        $before  = self::snapshot($pdo);
        $after   = self::snapshotOf($values);
        $changes = self::diff($before, $after);
        $result  = ['before' => $before, 'after' => $after, 'changes' => $changes];

        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            /* The freeze is read inside the transaction so an arm that lands
             * between this save's validation and its write cannot slip past
             * (D-23). */
            $board = GeneralHarvestBoard::state($pdo);

            if (($board['armed'] ?? false) && self::differs($values, self::amounts($pdo))) {
                throw new InvalidArgumentException(self::armedMessage((string) ($board['armed_at'] ?? '')));
            }

            $pdo->exec('DELETE FROM general_harvest_levels');

            $insert = $pdo->prepare(
                'INSERT INTO general_harvest_levels (level, amount, updated_by) VALUES (?, ?, ?)'
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
     * The first level in `$from … $to` whose posted amount is zero or
     * blank, or null when every level in the range is priced.
     *
     * @param array<string, string> $values keyed `general_harvest_amounts.N`
     */
    private static function firstUnpriced(array $values, int $from, int $to): ?int
    {
        for ($level = max(self::LEVEL_MIN, $from); $level <= min(self::LEVEL_MAX, $to); $level++) {
            $raw = trim((string) ($values[self::AMOUNT_FIELD . '.' . $level] ?? ''));

            if ($raw === '' || self::toCents($raw) <= 0) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Do two ladders differ by a centavo?
     *
     * @param array<int, float> $amounts
     * @param array<int, float> $stored
     */
    private static function differs(array $amounts, array $stored): bool
    {
        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            if (self::toCents($amounts[$level] ?? 0) !== self::toCents($stored[$level] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /** The one sentence a frozen save is refused with (Rule 14, D-23). */
    public static function armedMessage(string $armedAt): string
    {
        $instant = $armedAt === '' ? 'the board is armed' : 'the board was armed on ' . $armedAt;

        return sprintf(
            '%s, so the ten amounts are frozen: a shared board\'s whole liability is one ladder. A business reset is the only way to change them, and it is safe only while nothing has been paid.',
            ucfirst($instant)
        );
    }

    /**
     * The stored ladder as money strings, for the audit's before/after.
     *
     * @return array<int, string>
     */
    private static function snapshot(?PDO $pdo = null): array
    {
        return self::snapshotOf(self::amounts($pdo));
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
                'A General Harvest level is %d–%d; got %d.',
                self::LEVEL_MIN,
                self::LEVEL_MAX,
                $level
            ));
        }
    }

    /**
     * A negative node index is a programming error: `seq = position − 1`
     * and a position starts at 1, so the board has no node −1.
     */
    private static function assertSeq(int $seq): void
    {
        if ($seq < 0) {
            throw new InvalidArgumentException('A board node is 0 or above; got ' . $seq . '.');
        }
    }

    /**
     * One row amount (or a posted value) as pesos rounded to the centavo.
     * Money never travels as a float past this point in a snapshot.
     */
    private static function centsToMoney(float|int|string|null $value): float
    {
        return (float) (self::toCents($value) / 100);
    }

    /** Pesos → integer centavos, the engine's unit (§12.7). */
    private static function toCents(float|int|string|null $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    /** The `DECIMAL(12,2)` storage shape: 2000 → "2000.00". */
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

        return (float) ($cents / 100);
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
