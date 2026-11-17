<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestAward — the General Harvest money ledger
 *  (plan §6 Phase 21C, §12.6, §13.4, §14.2, §14.3)
 *
 *  ONE ROW PER `(node, level)` DECISION. There is no type column, no
 *  period, no order, no product and no rate: the node and the level are
 *  the whole story. `UNIQUE (seq, level)` is the money anchor, so a
 *  replayed settlement can never pay the same block twice.
 *
 *  The two statuses consume the anchor:
 *
 *      credited   the block completed after go-live: the withdrawable
 *                 wallet was credited and `ledger_id` names the movement
 *      baseline   the block was already full at the go-live arm: the
 *                 amount is recorded, `ledger_id` stays NULL and it is
 *                 never paid (D-06)
 *
 *  21C ships the read API and the baseline write. 21D extends it with
 *  `create()` / `linkLedger()` / `writeCredited()` — the credited path —
 *  plus the read helpers `levelMap()`, `paidLevels()` and `gridFor()`.
 *  Nothing here can write money by mistake: `writeBaseline()` always
 *  stores `status = 'baseline'` and `ledger_id = NULL`, and `create()`
 *  refuses a zero amount.
 *
 *  The model never opens a transaction of its own. It runs inside its
 *  caller's — the arm's one transaction — so the ledger and the board
 *  row commit together or not at all (§14.3).
 *
 *  Money never travels as a binary float on its way into the ledger:
 *  every amount is normalised to an exact `DECIMAL(12,2)` string in
 *  integer centavos first (§12.7), and the reads shape it back to a
 *  float for the pages and the CSV.
 * ============================================================== */

final class GeneralHarvestAward
{
    /** `general_harvest_awards.status` values (§13.4). */
    public const STATUS_CREDITED = 'credited';
    public const STATUS_BASELINE = 'baseline';
    public const STATUSES        = [self::STATUS_CREDITED, self::STATUS_BASELINE];

    /** Admin and member labels (the two stored states read the same). */
    public const STATUS_LABELS = [
        self::STATUS_CREDITED => 'Paid',
        self::STATUS_BASELINE => 'Before go-live',
    ];

    public const STATUS_BADGES = [
        self::STATUS_CREDITED => ['Paid', 'success'],
        self::STATUS_BASELINE => ['Before go-live', 'secondary'],
    ];

    /** The wallet reference 21D's credits will carry (§13.5, D-11). */
    public const REF_TYPE = 'general_harvest';

    /**
     * The ledger filters 21E's tab and 21F's statement share. A value
     * that does not parse falls back to "no filter" (§9 Rule 9).
     */
    public const FILTER_DEFAULTS = [
        'user_id' => 0,
        'level'   => '',
        'status'  => '',
        'from'    => '',
        'to'      => '',
        'q'       => '',
    ];

    /** The most rows one CSV export returns (plan §15.1). */
    public const EXPORT_LIMIT = 5000;

    /** The ladder's level range, asked once so the model and the model
     *  that owns the arithmetic cannot disagree (§13.3). */
    private const LEVEL_MIN = GeneralHarvestLevel::LEVEL_MIN;
    private const LEVEL_MAX = GeneralHarvestLevel::LEVEL_MAX;

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /**
     * One award row, shaped, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(self::selectSql() . ' WHERE a.id = ? LIMIT 1');
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : self::shape($row);
    }

    /**
     * Has this block been decided — credited or baselined? The money
     * engine's pre-check (§14.5, §14.6 layer 1).
     */
    public static function has(int $seq, int $level, ?PDO $pdo = null): bool
    {
        if ($seq < 0 || $level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            return false;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare('SELECT 1 FROM general_harvest_awards WHERE seq = ? AND level = ? LIMIT 1');
        $stmt->execute([$seq, $level]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The decided row for one `(seq, level)`, shaped, or null (§14.12).
     *
     * @return array<string, mixed>|null
     */
    public static function findBySeqLevel(int $seq, int $level, ?PDO $pdo = null): ?array
    {
        if ($seq < 0 || $level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            return null;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(self::selectSql() . ' WHERE a.seq = ? AND a.level = ? LIMIT 1');
        $stmt->execute([$seq, $level]);

        $row = $stmt->fetch();

        return $row === false ? null : self::shape($row);
    }

    /**
     * Every decided level of one node holder, level order — the member's
     * statement and the admin member panel (§14.2).
     *
     * @return list<array<string, mixed>>
     */
    public static function forMember(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(self::selectSql() . ' WHERE a.user_id = ? ORDER BY a.seq, a.level');
        $stmt->execute([$userId]);

        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /**
     * The newest decisions network-wide, for a dashboard card or a
     * "what just paid" list.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 20, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(100, $limit));

        $stmt = $pdo->query(
            self::selectSql() . ' ORDER BY a.credited_at DESC, a.id DESC LIMIT ' . $limit
        );

        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /**
     * Rows per level, keyed 1 … 10 and zero-filled. The frontier read
     * and the admin breakdown both start here (§14.2, §14.13).
     *
     * @return array<int, int>
     */
    public static function countByLevel(?PDO $pdo = null): array
    {
        $counts = array_fill(self::LEVEL_MIN, self::LEVEL_MAX - self::LEVEL_MIN + 1, 0);
        $pdo    = $pdo ?? db();

        foreach ($pdo->query(
            'SELECT level, COUNT(*) AS written FROM general_harvest_awards GROUP BY level'
        )->fetchAll() as $row) {
            $level = (int) $row['level'];

            if (isset($counts[$level])) {
                $counts[$level] = (int) $row['written'];
            }
        }

        return $counts;
    }

    /**
     * The prefix evidence for every level: how many rows exist and the
     * smallest and largest `seq`. A level with no rows reads
     * `{rows: 0, min: null, max: null}` so a reader always sees ten
     * levels (§14.13's contiguous-prefix check).
     *
     * @return array<int, array{level:int, rows:int, min:?int, max:?int}>
     */
    public static function minMaxByLevel(?PDO $pdo = null): array
    {
        $rows = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $rows[$level] = ['level' => $level, 'rows' => 0, 'min' => null, 'max' => null];
        }

        $pdo = $pdo ?? db();

        foreach ($pdo->query(
            'SELECT level, COUNT(*) AS written, MIN(seq) AS min_seq, MAX(seq) AS max_seq
               FROM general_harvest_awards
              GROUP BY level'
        )->fetchAll() as $row) {
            $level = (int) $row['level'];

            if (!isset($rows[$level])) {
                continue;
            }

            $rows[$level] = [
                'level' => $level,
                'rows'  => (int) $row['written'],
                'min'   => $row['min_seq'] === null ? null : (int) $row['min_seq'],
                'max'   => $row['max_seq'] === null ? null : (int) $row['max_seq'],
            ];
        }

        return $rows;
    }

    /** Counts by status, keyed by status (both keys always present). */
    public static function countByStatus(?PDO $pdo = null): array
    {
        $counts = [self::STATUS_CREDITED => 0, self::STATUS_BASELINE => 0];
        $pdo    = $pdo ?? db();

        foreach ($pdo->query('SELECT status, COUNT(*) AS written FROM general_harvest_awards GROUP BY status')->fetchAll() as $row) {
            $status = (string) $row['status'];

            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['written'];
            }
        }

        return $counts;
    }

    /* ----------------------------------------------------------
     *  Filters, pages, totals and exports (built for 21E/21F)
     * ---------------------------------------------------------- */

    /** @return array{user_id:int, level:string, status:string, from:string, to:string, q:string} */
    public static function filterDefaults(): array
    {
        return self::FILTER_DEFAULTS;
    }

    /**
     * Validate and normalise the ledger filters: anything that does not
     * parse means "no filter", never an error (§9 Rule 9).
     *
     * @param array<string, mixed> $raw
     * @return array{user_id:int, level:string, status:string, from:string, to:string, q:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::FILTER_DEFAULTS;

        $filters['user_id'] = max(0, (int) ($raw['user_id'] ?? 0));

        $level = (int) ($raw['level'] ?? 0);

        if ($level >= self::LEVEL_MIN && $level <= self::LEVEL_MAX) {
            $filters['level'] = (string) $level;
        }

        $status = strtolower(trim((string) ($raw['status'] ?? '')));

        if (in_array($status, self::STATUSES, true)) {
            $filters['status'] = $status;
        }

        foreach (['from', 'to'] as $bound) {
            $date = trim((string) ($raw[$bound] ?? ''));

            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1 && strtotime($date) !== false) {
                $filters[$bound] = $date;
            }
        }

        $filters['q'] = mb_substr(trim((string) ($raw['q'] ?? '')), 0, 80);

        return $filters;
    }

    /** How many rows the filters match (the pager's total). */
    public static function count(array $filters = [], ?PDO $pdo = null): int
    {
        $filters = self::normaliseFilters($filters);
        $pdo     = $pdo ?? db();

        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM general_harvest_awards a
               JOIN users u ON u.id = a.user_id' . ($where === '' ? '' : ' WHERE ' . $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of the filtered ledger, newest decision first — the admin
     * tab (21E) and the member statement (21F). One query and no N+1.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function select(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $filters = self::normaliseFilters($filters);
        $pdo     = $pdo ?? db();

        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            self::selectSql()
            . ($where === '' ? '' : ' WHERE ' . $where)
            . ' ORDER BY a.credited_at DESC, a.id DESC'
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset
        );
        $stmt->execute($params);

        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /**
     * The filtered totals, one query. `credited` is money; `baseline` is
     * recorded and never paid (§12.6 Rule 4), so the two are reported
     * apart — exactly like Team Harvest's ledger.
     *
     * @param array<string, mixed> $filters
     * @return array{rows:int, credited_rows:int, credited_total:float,
     *               baseline_rows:int, baseline_total:float, recipients:int, total:float}
     */
    public static function totals(array $filters = [], ?PDO $pdo = null): array
    {
        $filters = self::normaliseFilters($filters);
        $pdo     = $pdo ?? db();

        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS written,
                    COUNT(CASE WHEN a.status = 'credited' THEN 1 END) AS credited_rows,
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN a.status = 'baseline' THEN 1 END) AS baseline_rows,
                    COALESCE(SUM(CASE WHEN a.status = 'baseline' THEN a.amount ELSE 0 END), 0) AS baseline_total,
                    COUNT(DISTINCT CASE WHEN a.status = 'credited' THEN a.user_id END) AS recipients
               FROM general_harvest_awards a
               JOIN users u ON u.id = a.user_id"
            . ($where === '' ? '' : ' WHERE ' . $where)
        );
        $stmt->execute($params);

        $row = $stmt->fetch() ?: [];

        return [
            'rows'           => (int) ($row['written'] ?? 0),
            'credited_rows'  => (int) ($row['credited_rows'] ?? 0),
            'credited_total' => round((float) ($row['credited_total'] ?? 0), 2),
            'baseline_rows'  => (int) ($row['baseline_rows'] ?? 0),
            'baseline_total' => round((float) ($row['baseline_total'] ?? 0), 2),
            'recipients'     => (int) ($row['recipients'] ?? 0),
            'total'          => round((float) ($row['credited_total'] ?? 0) + (float) ($row['baseline_total'] ?? 0), 2),
        ];
    }

    /**
     * The ten-row level distribution — **always ten rows**, including
     * the levels nobody has completed (§9 Rule 11, §14.13).
     *
     * @return array<int, array{level:int, amount:float, awards:int, credited:int,
     *                          credited_total:float, baseline:int, baseline_total:float}>
     */
    public static function byLevel(?PDO $pdo = null): array
    {
        $pdo     = $pdo ?? db();
        $amounts = GeneralHarvestLevel::amounts($pdo);
        $rows    = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $rows[$level] = [
                'level'          => $level,
                'amount'         => $amounts[$level] ?? 0.0,
                'awards'         => 0,
                'credited'       => 0,
                'credited_total' => 0.0,
                'baseline'       => 0,
                'baseline_total' => 0.0,
            ];
        }

        foreach ($pdo->query(
            "SELECT level,
                    COUNT(*) AS awards,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total
               FROM general_harvest_awards
              GROUP BY level"
        )->fetchAll() as $row) {
            $level = (int) $row['level'];

            if (!isset($rows[$level])) {
                continue;
            }

            $rows[$level]['awards']         = (int) $row['awards'];
            $rows[$level]['credited']       = (int) $row['credited'];
            $rows[$level]['credited_total'] = round((float) $row['credited_total'], 2);
            $rows[$level]['baseline']       = (int) $row['baseline'];
            $rows[$level]['baseline_total'] = round((float) $row['baseline_total'], 2);
        }

        return $rows;
    }

    /**
     * Money by calendar month of the decision — the report card and the
     * dashboard KPI. Keyed `YYYY-MM`, newest first.
     *
     * @return list<array{month:string, awards:int, credited:int, credited_total:float,
     *                    baseline:int, baseline_total:float}>
     */
    public static function byMonth(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        return array_map(static fn (array $row): array => [
            'month'          => (string) $row['month'],
            'awards'         => (int) $row['awards'],
            'credited'       => (int) $row['credited'],
            'credited_total' => round((float) $row['credited_total'], 2),
            'baseline'       => (int) $row['baseline'],
            'baseline_total' => round((float) $row['baseline_total'], 2),
        ], $pdo->query(
            "SELECT DATE_FORMAT(credited_at, '%Y-%m') AS month,
                    COUNT(*) AS awards,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total
               FROM general_harvest_awards
              GROUP BY month
              ORDER BY month DESC"
        )->fetchAll());
    }

    /**
     * Every row a CSV export needs, newest decision first, capped at
     * EXPORT_LIMIT. `$userId` scopes the export **in the model** so a
     * hand-edited URL cannot widen it (21F Rule 11); null exports the
     * filtered ledger (the admin tab).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function exportRows(array $filters = [], ?PDO $pdo = null, ?int $userId = null): array
    {
        $filters = self::normaliseFilters($filters);
        $pdo     = $pdo ?? db();

        if ($userId !== null) {
            $filters['user_id'] = max(0, $userId);
        }

        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            self::selectSql()
            . ($where === '' ? '' : ' WHERE ' . $where)
            . ' ORDER BY a.credited_at DESC, a.id DESC'
            . ' LIMIT ' . self::EXPORT_LIMIT
        );
        $stmt->execute($params);

        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /* ----------------------------------------------------------
     *  Writes — 21C ships the baseline only
     * ---------------------------------------------------------- */

    /**
     * Insert one `baseline` decision inside the caller's transaction.
     *
     * The snapshot carries the node holder, the level, the threshold and
     * queue length in force, the amount and the arm instant. `status` is
     * always `baseline` and `ledger_id` is always NULL — there is no
     * parameter that could turn this write into a credit (§12.6 Rule 7).
     *
     * @param array{user_id:int, level:int, threshold_n?:int, n_at_award?:int,
     *              amount?:float|int|string, credited_at?:string} $snapshot
     * @return int the new award id
     */
    public static function writeBaseline(int $seq, array $snapshot, ?PDO $pdo = null): int
    {
        if ($seq < 0) {
            throw new InvalidArgumentException('A General Harvest node is 0 or above; got ' . $seq . '.');
        }

        $level = (int) ($snapshot['level'] ?? 0);

        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException(
                'A General Harvest award is a level ' . self::LEVEL_MIN . '–' . self::LEVEL_MAX . '; got ' . $level . '.'
            );
        }

        $userId     = (int) ($snapshot['user_id'] ?? 0);
        $threshold  = max(0, (int) ($snapshot['threshold_n'] ?? GeneralHarvestLevel::threshold($seq, $level)));
        $nAtAward   = max(0, (int) ($snapshot['n_at_award'] ?? $threshold));
        $amount     = self::decimal($snapshot['amount'] ?? GeneralHarvestLevel::amount($level));
        $creditedAt = trim((string) ($snapshot['credited_at'] ?? ''));

        if ($userId <= 0) {
            throw new InvalidArgumentException('A General Harvest award needs a positive user_id.');
        }

        if ($creditedAt === '' || strtotime($creditedAt) === false) {
            throw new InvalidArgumentException('A General Harvest baseline row needs the instant it was recorded.');
        }

        /* The schema's chk_gha_n is the final word; refusing here gives a
         * message instead of a PDOException. */
        if ($nAtAward < $threshold) {
            throw new InvalidArgumentException(sprintf(
                'A General Harvest award cannot record n = %d below its threshold of %d.',
                $nAtAward,
                $threshold
            ));
        }

        $pdo = $pdo ?? db();

        $pdo->prepare(
            'INSERT INTO general_harvest_awards
               (seq, user_id, level, threshold_n, n_at_award, amount, status, ledger_id, credited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
        )->execute([
            $seq,
            $userId,
            $level,
            $threshold,
            $nAtAward,
            $amount,
            self::STATUS_BASELINE,
            $creditedAt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert one level's whole baseline prefix (`seq = 0 … $qFor`) in
     * **one multi-row INSERT** (§14.3). The rows are a pure function of
     * `N₀`, the level and the queue order; `$ownerBySeq` is the queue
     * prefix already read by the arm.
     *
     * The values are integers internally and the two strings are quoted
     * through the connection, so a 100,000-row level does not hit the
     * prepared-statement placeholder ceiling.
     *
     * @param array<int, int> $ownerBySeq seq => user_id
     * @return int rows inserted (0 when no block is full)
     */
    public static function writeBaselineLevel(
        int $level,
        int $qFor,
        int $n,
        array $ownerBySeq,
        string $creditedAt,
        array $amounts,
        ?PDO $pdo = null
    ): int {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException(
                'A General Harvest award is a level ' . self::LEVEL_MIN . '–' . self::LEVEL_MAX . '; got ' . $level . '.'
            );
        }

        if ($qFor < 0) {
            return 0;
        }

        $pdo = $pdo ?? db();

        if ($creditedAt === '' || strtotime($creditedAt) === false) {
            throw new InvalidArgumentException('A General Harvest baseline row needs the instant it was recorded.');
        }

        $amount = self::decimal($amounts[$level] ?? 0);
        $values = [];

        for ($seq = 0; $seq <= $qFor; $seq++) {
            $userId = (int) ($ownerBySeq[$seq] ?? 0);

            if ($userId <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'The Harvest queue has no holder for node %d, so the baseline cannot be written.',
                    $seq
                ));
            }

            $values[] = sprintf(
                '(%d, %d, %d, %d, %d, %s, %s, NULL, %s)',
                $seq,
                $userId,
                $level,
                GeneralHarvestLevel::threshold($seq, $level),
                max(0, $n),
                $pdo->quote($amount),
                $pdo->quote(self::STATUS_BASELINE),
                $pdo->quote($creditedAt)
            );
        }

        return (int) $pdo->exec(
            'INSERT INTO general_harvest_awards
               (seq, user_id, level, threshold_n, n_at_award, amount, status, ledger_id, credited_at)
             VALUES ' . implode(', ', $values)
        );
    }

    /* ----------------------------------------------------------
     *  Writes — 21D adds the credited path
     * ---------------------------------------------------------- */

    /**
     * Insert one `credited` decision inside the caller's transaction.
     *
     * The snapshot carries the node holder, the level, the threshold and
     * queue length in force, the amount and the decision instant. `status`
     * is always `credited` and `ledger_id` starts NULL: `linkLedger()`
     * names the wallet movement in the same transaction, so the award and
     * its credit commit together or not at all (§14.7 Rule 3).
     *
     * The caller is responsible for the baseline check
     * (`$seq <= qFor(L, baseline_n)` → `baseline`, never `credited`) and
     * for the depth/amount checks; this method only refuses a row it
     * cannot write (a bad level, a missing holder, a zero amount).
     *
     * @param array{user_id:int, level:int, threshold_n:int, n_at_award:int,
     *              amount:float|int|string, credited_at:string} $snapshot
     * @return int the new award id
     */
    public static function create(int $seq, array $snapshot, ?PDO $pdo = null): int
    {
        if ($seq < 0) {
            throw new InvalidArgumentException('A General Harvest node is 0 or above; got ' . $seq . '.');
        }

        $level = (int) ($snapshot['level'] ?? 0);

        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException(
                'A General Harvest award is a level ' . self::LEVEL_MIN . '–' . self::LEVEL_MAX . '; got ' . $level . '.'
            );
        }

        $userId     = (int) ($snapshot['user_id'] ?? 0);
        $threshold  = max(0, (int) ($snapshot['threshold_n'] ?? GeneralHarvestLevel::threshold($seq, $level)));
        $nAtAward   = max(0, (int) ($snapshot['n_at_award'] ?? $threshold));
        $amount     = self::decimal($snapshot['amount'] ?? GeneralHarvestLevel::amount($level));
        $creditedAt = trim((string) ($snapshot['credited_at'] ?? ''));

        if ($userId <= 0) {
            throw new InvalidArgumentException('A General Harvest award needs a positive user_id.');
        }

        if ($creditedAt === '' || strtotime($creditedAt) === false) {
            throw new InvalidArgumentException('A General Harvest credited row needs the instant it was decided.');
        }

        if (self::cents($amount) <= 0) {
            throw new InvalidArgumentException(sprintf(
                'A credited General Harvest award must be greater than zero; level %d at node %d is unpriced.',
                $level,
                $seq
            ));
        }

        /* The schema's chk_gha_n is the final word; refusing here gives a
         * message instead of a PDOException. */
        if ($nAtAward < $threshold) {
            throw new InvalidArgumentException(sprintf(
                'A General Harvest award cannot record n = %d below its threshold of %d.',
                $nAtAward,
                $threshold
            ));
        }

        $pdo = $pdo ?? db();

        /* The award row must exist before the wallet credit (its ref_id
         * is the award id), so ledger_id starts NULL and is linked in the
         * same transaction (§14.7). */
        $pdo->prepare(
            'INSERT INTO general_harvest_awards
               (seq, user_id, level, threshold_n, n_at_award, amount, status, ledger_id, credited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
        )->execute([
            $seq,
            $userId,
            $level,
            $threshold,
            $nAtAward,
            $amount,
            self::STATUS_CREDITED,
            $creditedAt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Name the wallet movement a credited award created.
     *
     * The only UPDATE in this class, and it runs on the row the engine
     * just inserted, inside the transaction that also wrote the credit —
     * so the pair commits together or not at all (§14.7). It refuses to
     * overwrite an existing reference, which keeps an award from ever
     * pointing at a second movement.
     *
     * @return bool true when the row was linked, false when nothing matched
     */
    public static function linkLedger(int $awardId, int $ledgerId, ?PDO $pdo = null): bool
    {
        if ($awardId <= 0 || $ledgerId <= 0) {
            return false;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            "UPDATE general_harvest_awards
                SET ledger_id = ?
              WHERE id = ? AND status = 'credited' AND ledger_id IS NULL"
        );
        $stmt->execute([$ledgerId, $awardId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * One credited decision, award row plus its wallet movement, in one
     * transaction (the caller's, which this joins). Returns the award id
     * or throws — nothing is half-written.
     *
     * @param array{user_id:int, level:int, threshold_n:int, n_at_award:int,
     *              amount:float|int|string, credited_at:string} $snapshot
     * @return array{award_id:int, ledger_id:int}
     */
    public static function writeCredited(int $seq, array $snapshot, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        $owns = !$pdo->inTransaction();

        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            $awardId = self::create($seq, $snapshot, $pdo);

            $movement = (new WalletService($pdo))->credit(
                (int) $snapshot['user_id'],
                $snapshot['amount'],
                self::REF_TYPE,
                $awardId,
                'General Harvest level ' . (int) $snapshot['level'] . ' bonus',
                true
            );

            $ledgerId = (int) ($movement['ledger_id'] ?? 0);

            if ($ledgerId <= 0 || !self::linkLedger($awardId, $ledgerId, $pdo)) {
                throw new InvalidArgumentException(sprintf(
                    'Award #%d for node %d level %d could not be linked to its wallet movement.',
                    $awardId,
                    $seq,
                    (int) $snapshot['level']
                ));
            }

            if ($owns) {
                $pdo->commit();
            }

            return ['award_id' => $awardId, 'ledger_id' => $ledgerId];
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** One member's decided levels as `level => status`. */
    public static function levelMap(int $userId, ?PDO $pdo = null): array
    {
        return self::levelMapFor([$userId], $pdo)[$userId] ?? [];
    }

    /**
     * Several members' decided levels in ONE query — the batch read of
     * §14.9.
     *
     * @param list<int> $userIds
     * @return array<int, array<int, string>> user_id => level => status
     */
    public static function levelMapFor(array $userIds, ?PDO $pdo = null): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($userIds === []) {
            return [];
        }

        $pdo   = $pdo ?? db();
        $marks = implode(', ', array_fill(0, count($userIds), '?'));

        $stmt = $pdo->prepare(
            'SELECT seq, level, status FROM general_harvest_awards
              WHERE user_id IN (' . $marks . ')
              ORDER BY user_id, level'
        );
        $stmt->execute($userIds);

        $map = [];

        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['user_id']][(int) $row['level']] = (string) $row['status'];
        }

        return $map;
    }

    /** The member's credited levels, ascending — what has been paid. */
    public static function paidLevels(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            "SELECT level FROM general_harvest_awards
              WHERE user_id = ? AND status = 'credited'
              ORDER BY level"
        );
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Node `$seq`'s ten-cell board card: every level with its state, the
     * amount it pays and whether a row already decided it.
     *
     * A level's status reads the stored row first: `credited` (paid) or
     * `baseline` (before go-live). With no row yet it derives the state
     * from completeness and depth: `complete` when the block is full and
     * nothing decided it, `progress` when it is not full yet.
     *
     * @return array<int, array{level:int, slots:int, from:int, to:int,
     *                          threshold:int, filled:int, complete:bool,
     *                          amount:float, status:string, paid:bool}>
     */
    public static function gridFor(int $seq, int $n, ?PDO $pdo = null): array
    {
        $amounts = GeneralHarvestLevel::amounts($pdo);
        $depth   = GeneralHarvestLevel::maxLevels();
        $decided = [];

        foreach ($pdo->query(
            'SELECT level, status FROM general_harvest_awards WHERE seq = ? ORDER BY level',
            [$seq]
        ) as $row) {
            $decided[(int) $row['level']] = (string) $row['status'];
        }

        $rows = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $filled   = GeneralHarvestLevel::filled($seq, $level, $n);
            $complete = GeneralHarvestLevel::complete($seq, $level, $n);
            $amount   = (float) ($amounts[$level] ?? 0.0);
            $status   = $decided[$level] ?? '';

            if ($status === '') {
                if ($complete) {
                    $status = $level > $depth || $amount <= 0
                        ? 'complete'
                        : 'complete';
                } else {
                    $status = 'progress';
                }
            }

            $rows[$level] = [
                'level'     => $level,
                'slots'     => GeneralHarvestLevel::slots($level),
                'from'      => GeneralHarvestLevel::blockFrom($seq, $level),
                'to'        => GeneralHarvestLevel::blockTo($seq, $level),
                'threshold' => GeneralHarvestLevel::threshold($seq, $level),
                'filled'    => $filled,
                'complete'  => $complete,
                'amount'    => $amount,
                'status'    => $status,
                'paid'      => $status === self::STATUS_CREDITED,
            ];
        }

        return $rows;
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** The base select every read shares: the row, its node and its member. */
    private static function selectSql(): string
    {
        return 'SELECT a.id, a.seq, a.user_id, a.level, a.threshold_n, a.n_at_award, a.amount,
                       a.status, a.ledger_id, a.credited_at, a.created_at,
                       u.username, a.seq + 1 AS position
                  FROM general_harvest_awards a
                  JOIN users u ON u.id = a.user_id';
    }

    /**
     * The WHERE clause and its parameters for a normalised filter set.
     *
     * @param array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(array $filters): array
    {
        $where  = [];
        $params = [];

        if ((int) $filters['user_id'] > 0) {
            $where[]  = 'a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }

        if ($filters['level'] !== '') {
            $where[]  = 'a.level = ?';
            $params[] = (int) $filters['level'];
        }

        if ($filters['status'] !== '') {
            $where[]  = 'a.status = ?';
            $params[] = $filters['status'];
        }

        if ($filters['from'] !== '') {
            $where[]  = 'a.credited_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if ($filters['to'] !== '') {
            /* Inclusive of the whole `to` day, exclusive of the next. */
            $where[]  = 'a.credited_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        if ($filters['q'] !== '') {
            $where[]  = 'u.username LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        return [$where === [] ? '' : implode(' AND ', $where), $params];
    }

    /**
     * One row as the pages read it: integers as integers, money as a
     * float (the pages format it with money(), never with the raw column).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'seq'         => (int) $row['seq'],
            'position'    => (int) $row['position'],
            'user_id'     => (int) $row['user_id'],
            'username'    => (string) ($row['username'] ?? ''),
            'level'       => (int) $row['level'],
            'threshold_n' => (int) $row['threshold_n'],
            'n_at_award'  => (int) $row['n_at_award'],
            'amount'      => round((float) $row['amount'], 2),
            'status'      => (string) $row['status'],
            'ledger_id'   => $row['ledger_id'] === null ? null : (int) $row['ledger_id'],
            'credited_at' => (string) $row['credited_at'],
            'created_at'  => (string) $row['created_at'],
        ];
    }

    /** A `DECIMAL(12,2)` argument, never float notation. */
    public static function decimal(float|int|string|null $amount): string
    {
        return number_format(self::cents($amount) / 100, 2, '.', '');
    }

    /** Pesos → integer centavos (plan §12.7). */
    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }
}
