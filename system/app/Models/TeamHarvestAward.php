<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestAward — the Team Harvest money ledger (Phase 20D)
 *  (plan §8, §12.7, §13.4, §14.2, §14.5, §14.13)
 *
 *  ONE ROW PER `(member, level)` DECISION. There is no type column, no
 *  period, no order, no product and no rate: the level is the whole
 *  story (plan §3.5). `UNIQUE (user_id, level)` is the money anchor
 *  (D-06), so a replayed evaluation can never pay the same level twice.
 *
 *  The two statuses consume the anchor:
 *
 *      credited   the level completed after go-live: the withdrawable
 *                 wallet was credited and `ledger_id` names the movement
 *      baseline   the level was already complete at the go-live seed:
 *                 the amount is recorded, `ledger_id` stays NULL and it
 *                 is never paid (D-07)
 *
 *  There is no `voided` status in v1 (D-12). A row is written once and
 *  then never edited again: the **only** update this class performs is
 *  `linkLedger()`, which sets `ledger_id` on the row the engine just
 *  inserted, inside the same transaction as its wallet credit, so a
 *  half-committed award/credit pair is impossible (plan §14.7).
 *
 *  Money never travels as a binary float on its way into the ledger:
 *  every amount is normalised to an exact `DECIMAL(12,2)` string in
 *  integer centavos first (plan §12.9), and the reads shape it back to
 *  a float for the pages and the CSV.
 *
 *  This file also ships the read API 20E and 20F render — `forMember()`,
 *  `select()`, `totals()`, `byLevel()`, `byMonth()`, `exportRows()` — so
 *  neither later phase invents a second ledger. It writes nothing except
 *  through `create()` and `linkLedger()`, both called by
 *  TeamHarvestService only.
 * ============================================================== */

final class TeamHarvestAward
{
    /** `team_harvest_awards.status` values (§13.4). */
    public const STATUS_CREDITED = 'credited';
    public const STATUS_BASELINE = 'baseline';
    public const STATUSES        = [self::STATUS_CREDITED, self::STATUS_BASELINE];

    /** Admin labels (plan Appendix C.7). */
    public const STATUS_LABELS = [
        self::STATUS_CREDITED => 'Paid',
        self::STATUS_BASELINE => 'Before go-live',
    ];

    /** Member labels — identical for the two stored states. */
    public const MEMBER_STATUS_LABELS = [
        self::STATUS_CREDITED => 'Paid',
        self::STATUS_BASELINE => 'Before go-live',
    ];

    /** Badge overrides for status_badge() (plan Appendix C.7). */
    public const STATUS_BADGES = [
        self::STATUS_CREDITED => ['Paid', 'success'],
        self::STATUS_BASELINE => ['Before go-live', 'secondary'],
    ];

    /** The wallet reference this ledger credits (§13.5, D-11). */
    public const REF_TYPE = 'team_harvest';

    /**
     * The ledger filters 20E's tab and 20F's statement share. A value
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
    private const LEVEL_MIN = TeamHarvestLevel::LEVEL_MIN;
    private const LEVEL_MAX = TeamHarvestLevel::LEVEL_MAX;

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
     * Has this level been decided for this member — credited or
     * baselined? The engine's pre-check (plan §14.5, §14.6 layer 1).
     */
    public static function has(int $userId, int $level, ?PDO $pdo = null): bool
    {
        if ($userId <= 0 || $level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            return false;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare('SELECT 1 FROM team_harvest_awards WHERE user_id = ? AND level = ? LIMIT 1');
        $stmt->execute([$userId, $level]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * One member's decided levels as `level => status` — one query, the
     * shape the engine's batch loop and statusFor() both read.
     *
     * @return array<int, string>
     */
    public static function levelMap(int $userId, ?PDO $pdo = null): array
    {
        return self::levelMapFor([$userId], $pdo)[$userId] ?? [];
    }

    /**
     * Several members' decided levels in ONE query: the batch read of
     * §14.9 (≤ 3 queries per member + 1 per award becomes a handful for
     * a whole evaluation batch).
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
            'SELECT user_id, level, status FROM team_harvest_awards
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

    /**
     * Every decided level of one member, level order — the member's
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
        $stmt = $pdo->prepare(self::selectSql() . ' WHERE a.user_id = ? ORDER BY a.level');
        $stmt->execute([$userId]);

        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /**
     * The member's **credited** levels, ascending — the engine's "what has
     * actually been paid" read (§14.12).
     *
     * @return list<int>
     */
    public static function paidLevels(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            "SELECT level FROM team_harvest_awards
              WHERE user_id = ? AND status = 'credited'
              ORDER BY level"
        );
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The newest decisions network-wide, for a dashboard card or a
     * "what just paid" list.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 10, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(100, $limit));

        $stmt = $pdo->query(
            self::selectSql() . ' ORDER BY a.credited_at DESC, a.id DESC LIMIT ' . $limit
        );

        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /**
     * Counts by status, keyed by status (both keys always present).
     *
     * @return array{credited:int, baseline:int}
     */
    public static function countByStatus(?PDO $pdo = null): array
    {
        $counts = [self::STATUS_CREDITED => 0, self::STATUS_BASELINE => 0];

        $pdo = $pdo ?? db();

        foreach ($pdo->query('SELECT status, COUNT(*) AS rows_ FROM team_harvest_awards GROUP BY status')->fetchAll() as $row) {
            $status = (string) $row['status'];

            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['rows_'];
            }
        }

        return $counts;
    }

    /* ----------------------------------------------------------
     *  Filters, pages, totals and exports (built for 20E/20F)
     * ---------------------------------------------------------- */

    /** @return array{user_id:int, level:string, status:string, from:string, to:string, q:string} */
    public static function filterDefaults(): array
    {
        return self::FILTER_DEFAULTS;
    }

    /**
     * Validate and normalise the ledger filters: anything that does not
     * parse means "no filter", never an error (§9 Rule 9). `from`/`to`
     * are dates (`Y-m-d`), `level` is 1–10 and `status` is one of the
     * two stored values.
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
            'SELECT COUNT(*) FROM team_harvest_awards a
               JOIN users u ON u.id = a.user_id' . ($where === '' ? '' : ' WHERE ' . $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of the filtered ledger, newest decision first — the admin
     * tab (20E) and the member statement (20F). One query for the page
     * and no N+1: the member, the position and the wallet reference are
     * joined in the same select.
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
     * recorded and never paid (Rule 4 of §12.7), so the two are always
     * reported apart.
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
            "SELECT COUNT(*) AS rows_,
                    COUNT(CASE WHEN a.status = 'credited' THEN 1 END) AS credited_rows,
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN a.status = 'baseline' THEN 1 END) AS baseline_rows,
                    COALESCE(SUM(CASE WHEN a.status = 'baseline' THEN a.amount ELSE 0 END), 0) AS baseline_total,
                    COUNT(DISTINCT CASE WHEN a.status = 'credited' THEN a.user_id END) AS recipients
               FROM team_harvest_awards a
               JOIN users u ON u.id = a.user_id"
            . ($where === '' ? '' : ' WHERE ' . $where)
        );
        $stmt->execute($params);

        $row = $stmt->fetch() ?: [];

        return [
            'rows'           => (int) ($row['rows_'] ?? 0),
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
     * the levels nobody has completed, so a reader sees the shape of the
     * plan and not just the paid part (§9 Rule 11, §14.13).
     *
     * @return array<int, array{level:int, amount:float, awards:int, credited:int,
     *                          credited_total:float, baseline:int, baseline_total:float}>
     */
    public static function byLevel(?PDO $pdo = null): array
    {
        $pdo     = $pdo ?? db();
        $amounts = TeamHarvestLevel::amounts($pdo);
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
               FROM team_harvest_awards
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
     * admin dashboard KPI (§14.2). Keyed `YYYY-MM`, newest first.
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
               FROM team_harvest_awards
              GROUP BY month
              ORDER BY month DESC"
        )->fetchAll());
    }

    /**
     * Every row a CSV export needs, newest decision first, capped at
     * EXPORT_LIMIT. `$userId` scopes the export in the **model** so a
     * hand-edited URL cannot widen it (20F Rule 11); null exports the
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
     *  Writes — called by TeamHarvestService only
     * ---------------------------------------------------------- */

    /**
     * Insert one decision, inside the caller's transaction (plan §14.5).
     *
     * A `credited` row must carry positive money (a zero credit is a lie
     * about money and the wallet refuses it — §12.6 Rule 2); a
     * `baseline` row may carry `0.00`, because a zero-amount level that
     * was already complete at go-live must still be recorded so raising
     * the amount later can never pay it (§12.6 Rule 2).
     *
     * `ledger_id` is written as NULL here and linked by `linkLedger()`
     * once the wallet has credited it: the award row must exist before
     * the credit, because the credit's `ref_id` is the award id
     * (§14.7 Rule 3).
     *
     * @param array{threshold_n:int, n_at_award:int, amount:float|int|string,
     *              status:string, credited_at:string} $snapshot
     * @return int the new award id
     */
    public static function create(int $userId, int $level, array $snapshot, ?PDO $pdo = null): int
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('A Team Harvest award needs a positive user_id.');
        }

        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException(
                'A Team Harvest award is a level ' . self::LEVEL_MIN . '–' . self::LEVEL_MAX . '; got ' . $level . '.'
            );
        }

        $status = (string) ($snapshot['status'] ?? self::STATUS_CREDITED);

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown Team Harvest award status: ' . $status);
        }

        $threshold = max(0, (int) ($snapshot['threshold_n'] ?? 0));
        $nAtAward  = max(0, (int) ($snapshot['n_at_award'] ?? 0));
        $amount    = self::decimal($snapshot['amount'] ?? 0);
        $creditedAt = trim((string) ($snapshot['credited_at'] ?? ''));

        if ($creditedAt === '' || strtotime($creditedAt) === false) {
            throw new InvalidArgumentException('A Team Harvest award needs the instant it was decided.');
        }

        /* The schema's chk_tha_n is the final word; refusing here gives a
         * message instead of a PDOException. */
        if ($nAtAward < $threshold) {
            throw new InvalidArgumentException(sprintf(
                'A Team Harvest award cannot record n = %d below its threshold of %d.',
                $nAtAward,
                $threshold
            ));
        }

        if ($status === self::STATUS_CREDITED && self::cents($amount) <= 0) {
            throw new InvalidArgumentException('A credited Team Harvest award must be greater than zero.');
        }

        $pdo = $pdo ?? db();

        /* The award row must exist before the wallet credit (its ref_id
         * is the award id), so ledger_id starts NULL and is linked in the
         * same transaction (§14.7). */
        $pdo->prepare(
            'INSERT INTO team_harvest_awards
               (user_id, level, threshold_n, n_at_award, amount, status, ledger_id, credited_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
        )->execute([$userId, $level, $threshold, $nAtAward, $amount, $status, $creditedAt]);

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
     */
    public static function linkLedger(int $awardId, int $ledgerId, ?PDO $pdo = null): bool
    {
        if ($awardId <= 0 || $ledgerId <= 0) {
            return false;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            "UPDATE team_harvest_awards
                SET ledger_id = ?
              WHERE id = ? AND status = 'credited' AND ledger_id IS NULL"
        );
        $stmt->execute([$ledgerId, $awardId]);

        return $stmt->rowCount() === 1;
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** The base select every read shares: the row, its member and its position. */
    private static function selectSql(): string
    {
        return 'SELECT a.id, a.user_id, a.level, a.threshold_n, a.n_at_award, a.amount,
                       a.status, a.ledger_id, a.credited_at, a.created_at,
                       u.username, q.id AS position
                  FROM team_harvest_awards a
                  JOIN users u ON u.id = a.user_id
                  LEFT JOIN harvest_queue q ON q.user_id = a.user_id';
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
            'user_id'     => (int) $row['user_id'],
            'username'    => (string) ($row['username'] ?? ''),
            'position'    => $row['position'] === null ? null : (int) $row['position'],
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

    /**
     * The wallet note one award writes — the member statement shows it,
     * so it names the level and nothing else (no money is repeated in a
     * note the wallet already records).
     *
     * @param array<string, mixed> $row
     */
    public static function walletNote(array $row): string
    {
        return 'Team Harvest level ' . (int) ($row['level'] ?? 0) . ' bonus';
    }

    /** A `DECIMAL(12,2)` argument, never float notation. */
    public static function decimal(float|int|string|null $amount): string
    {
        return number_format(self::cents($amount) / 100, 2, '.', '');
    }

    /** Pesos → integer centavos (plan §12.9). */
    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }
}
