<?php
declare(strict_types=1);

/* ==============================================================
 *  Commission — the compensation ledger (plan §4.2, §6, Phase 10)
 *
 *  One row per award. The table is the money trail: it says WHO was
 *  paid, WHICH activation caused it, WHICH package priced it and at
 *  WHICH level (1 = direct referral, 2–10 = the indirect ladder).
 *
 *  Reads live here. Money moves only through CommissionService, which
 *  inserts the row and credits the wallet inside one transaction
 *  (plan §6.5).
 *
 *  Status machine (plan §4.4, §6.3)
 *
 *      credited   the wallet was credited (ledger_id is set)
 *      skipped    the award was due but the recipient was ineligible;
 *                 audited, never paid
 *      voided     reserved for a later reversal (not written yet)
 *
 *  The unique key (type, source_user_id, user_id, level) makes a
 *  second award for the same event, recipient and level impossible:
 *  it is the idempotency anchor for the whole plan (plan §6.5).
 *
 *  Filters shared by the member earnings page and the admin ledger:
 *
 *      type    direct_referral | indirect_referral
 *      level   1 … 10
 *      status  credited | skipped | voided
 *      from/to YYYY-MM-DD (inclusive)
 *      q       member / source / package search (admin; the member
 *              page searches its own sources only)
 * ============================================================== */

final class Commission
{
    /** Values `commissions.type` accepts. */
    public const TYPES = ['direct_referral', 'indirect_referral'];

    /** Values `commissions.status` accepts. */
    public const STATUSES = ['credited', 'skipped', 'voided'];

    /** Level 1 is the direct referral bonus. */
    public const LEVEL_DIRECT = 1;

    /** Last indirect level (plan §6.1, §6.4). */
    public const LEVEL_MAX = 10;

    /** Human labels for the two legs. */
    public const TYPE_LABELS = [
        'direct_referral'   => 'Direct referral',
        'indirect_referral' => 'Indirect referral',
    ];

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::select() . ' WHERE c.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every award one activation produced, level order.
     *
     * @return list<array<string, mixed>>
     */
    public static function forActivation(int $activationId, ?PDO $pdo = null): array
    {
        if ($activationId <= 0) {
            return [];
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::select() . ' WHERE c.activation_id = ? ORDER BY c.level ASC, c.id ASC');
        $stmt->execute([$activationId]);

        return $stmt->fetchAll();
    }

    /**
     * The row for one (event, recipient, level) — the unique-key shape.
     *
     * @return array<string, mixed>|null
     */
    public static function forEvent(string $type, int $sourceUserId, int $userId, int $level, ?PDO $pdo = null): ?array
    {
        if (!in_array($type, self::TYPES, true) || $sourceUserId <= 0 || $userId <= 0) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            'SELECT * FROM commissions
              WHERE type = ? AND source_user_id = ? AND user_id = ? AND level = ?
              LIMIT 1'
        );
        $stmt->execute([$type, $sourceUserId, $userId, $level]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /* ----------------------------------------------------------
     *  Member statement
     * ---------------------------------------------------------- */

    /** @return array{type:string, level:string, status:string, from:string, to:string, q:string} */
    public static function filterDefaults(): array
    {
        return ['type' => '', 'level' => '', 'status' => '', 'from' => '', 'to' => '', 'q' => ''];
    }

    /**
     * Keep only the filters the queries understand; an unknown value is
     * ignored (it falls back to "no filter"), so a hand-edited query
     * string can never break a page or leak SQL.
     *
     * @param array<string, mixed> $raw
     * @return array{type:string, level:string, status:string, from:string, to:string, q:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $type = strtolower(trim((string) ($raw['type'] ?? '')));
        if (in_array($type, self::TYPES, true)) {
            $filters['type'] = $type;
        }

        $level = trim((string) ($raw['level'] ?? ''));
        if (ctype_digit($level) && (int) $level >= self::LEVEL_DIRECT && (int) $level <= self::LEVEL_MAX) {
            $filters['level'] = (string) (int) $level;
        }

        $status = strtolower(trim((string) ($raw['status'] ?? '')));
        if (in_array($status, self::STATUSES, true)) {
            $filters['status'] = $status;
        }

        $from = self::date((string) ($raw['from'] ?? ''));
        if ($from !== '') {
            $filters['from'] = $from;
        }

        $to = self::date((string) ($raw['to'] ?? ''));
        if ($to !== '') {
            $filters['to'] = $to;
        }

        $filters['q'] = trim((string) ($raw['q'] ?? ''));

        return $filters;
    }

    /** A valid YYYY-MM-DD date, or '' when the input is not one. */
    private static function date(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return $value;
    }

    /**
     * WHERE clause shared by every read.
     *
     * @param array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(?int $userId, array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($userId !== null) {
            $where[]  = 'c.user_id = ?';
            $params[] = $userId;
        }

        if (($filters['type'] ?? '') !== '') {
            $where[]  = 'c.type = ?';
            $params[] = $filters['type'];
        }

        if (($filters['level'] ?? '') !== '') {
            $where[]  = 'c.level = ?';
            $params[] = (int) $filters['level'];
        }

        if (($filters['status'] ?? '') !== '') {
            $where[]  = 'c.status = ?';
            $params[] = $filters['status'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[]  = 'c.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]  = 'c.created_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        $query = trim((string) ($filters['q'] ?? ''));

        if ($query !== '') {
            $like = '%' . like_escape($query) . '%';

            /* The member page searches its own sources; the admin ledger
             * also matches the recipient. Both shapes are one clause. */
            $where[] = $userId === null
                ? '(u.username LIKE ? OR u.full_name LIKE ? OR s.username LIKE ? OR p.name LIKE ?)'
                : '(s.username LIKE ? OR s.full_name LIKE ? OR p.name LIKE ?)';

            $params[] = $like;
            $params[] = $like;
            $params[] = $like;

            if ($userId === null) {
                $params[] = $like;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Number of award rows matching the filters.
     *
     * @param array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
     */
    public static function countForUser(int $userId, array $filters, ?PDO $pdo = null): int
    {
        return self::countWhere($userId, $filters, $pdo);
    }

    /** Same count across every member (admin ledger). */
    public static function countAdmin(array $filters, ?PDO $pdo = null): int
    {
        return self::countWhere(null, $filters, $pdo);
    }

    private static function countWhere(?int $userId, array $filters, ?PDO $pdo): int
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($userId, $filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM commissions c
               JOIN users u ON u.id = c.user_id
               JOIN users s ON s.id = c.source_user_id
               JOIN packages p ON p.id = c.package_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of one member's awards, newest first.
     *
     * @param array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function pageForUser(int $userId, array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere($userId, $filters, $pager, $pdo);
    }

    /**
     * One page across every member (admin ledger).
     *
     * @param array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere(null, $filters, $pager, $pdo);
    }

    /**
     * @param array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
     * @return list<array<string, mixed>>
     */
    private static function pageWhere(?int $userId, array $filters, Paginator $pager, ?PDO $pdo): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($userId, $filters);

        $sql = self::select()
            . ' WHERE ' . $where
            . ' ORDER BY c.id DESC'
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Totals for one member: what was credited, when, and which leg.
     *
     * Only `credited` rows count as money — a skipped row is an audit
     * note, not a payment (plan §6.3).
     *
     * @return array{
     *   count:int, credited:float, this_month:float, direct:float, indirect:float,
     *   skipped:int, last_at:?string
     * }
     */
    public static function totalsForUser(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(
            "SELECT COUNT(CASE WHEN status = 'credited' THEN 1 END) AS total_count,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month,
                    COALESCE(SUM(CASE WHEN status = 'credited' AND type = 'direct_referral'
                                      THEN amount ELSE 0 END), 0) AS direct,
                    COALESCE(SUM(CASE WHEN status = 'credited' AND type = 'indirect_referral'
                                      THEN amount ELSE 0 END), 0) AS indirect,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped_count,
                    MAX(CASE WHEN status = 'credited' THEN created_at END) AS last_at
               FROM commissions
              WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'count'      => (int) ($row['total_count'] ?? 0),
            'credited'   => round((float) ($row['credited'] ?? 0), 2),
            'this_month' => round((float) ($row['this_month'] ?? 0), 2),
            'direct'     => round((float) ($row['direct'] ?? 0), 2),
            'indirect'   => round((float) ($row['indirect'] ?? 0), 2),
            'skipped'    => (int) ($row['skipped_count'] ?? 0),
            'last_at'    => ($row['last_at'] ?? null) === null ? null : (string) $row['last_at'],
        ];
    }

    /**
     * Per-level totals (1 … 10) for the earnings breakdown.
     *
     * @param int|null $userId null for the whole ledger (admin view)
     * @return array<int, array{amount:float, count:int}>
     */
    public static function levelBreakdown(?int $userId = null, ?PDO $pdo = null): array
    {
        $pdo    = $pdo ?? db();
        $levels = [];

        for ($level = self::LEVEL_DIRECT; $level <= self::LEVEL_MAX; $level++) {
            $levels[$level] = ['amount' => 0.0, 'count' => 0];
        }

        $sql    = "SELECT level, COUNT(*) AS rows_count, COALESCE(SUM(amount), 0) AS amount
                     FROM commissions
                    WHERE status = 'credited'";
        $params = [];

        if ($userId !== null) {
            $sql     .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $stmt = $pdo->prepare($sql . ' GROUP BY level');
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $row) {
            $level = (int) $row['level'];

            if (isset($levels[$level])) {
                $levels[$level] = [
                    'amount' => round((float) $row['amount'], 2),
                    'count'  => (int) $row['rows_count'],
                ];
            }
        }

        return $levels;
    }

    /**
     * Counters for the admin ledger header.
     *
     * @return array{
     *   credited_total:float, this_month:float, credited:int, skipped:int,
     *   recipients:int, average:float, top_level:int
     * }
     */
    public static function stats(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_count,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped_count,
                    COUNT(DISTINCT CASE WHEN status = 'credited' THEN user_id END) AS recipients,
                    MAX(CASE WHEN status = 'credited' THEN level END) AS top_level
               FROM commissions"
        )->fetch() ?: [];

        $credited = round((float) ($row['credited_total'] ?? 0), 2);
        $count    = (int) ($row['credited_count'] ?? 0);

        return [
            'credited_total' => $credited,
            'this_month'     => round((float) ($row['this_month'] ?? 0), 2),
            'credited'       => $count,
            'skipped'        => (int) ($row['skipped_count'] ?? 0),
            'recipients'     => (int) ($row['recipients'] ?? 0),
            'average'        => $count === 0 ? 0.0 : round($credited / $count, 2),
            'top_level'      => (int) ($row['top_level'] ?? 0),
        ];
    }

    /* ----------------------------------------------------------
     *  Writes — called by CommissionService only
     * ---------------------------------------------------------- */

    /**
     * Insert one award row.
     *
     * The caller owns the transaction: the row and the wallet credit
     * must commit (or roll back) together (plan §6.5).
     *
     * @param array{
     *   user_id:int, type:string, amount:float|string, level:int,
     *   source_user_id:int, package_id:int, activation_id:int,
     *   status?:string, reason?:string|null
     * } $data
     * @return int the new commission id
     */
    public static function create(array $data, ?PDO $pdo = null): int
    {
        $pdo ??= db();

        $type = (string) ($data['type'] ?? '');

        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown commission type: ' . $type);
        }

        $status = (string) ($data['status'] ?? 'credited');

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown commission status: ' . $status);
        }

        $level = (int) ($data['level'] ?? self::LEVEL_DIRECT);

        if ($level < self::LEVEL_DIRECT || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException('Commission level out of range: ' . $level);
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        $pdo->prepare(
            'INSERT INTO commissions
               (user_id, type, amount, level, source_user_id, package_id, activation_id, status, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $data['user_id'],
            $type,
            number_format((float) $data['amount'], 2, '.', ''),
            $level,
            (int) $data['source_user_id'],
            (int) $data['package_id'],
            (int) $data['activation_id'],
            $status,
            $reason === '' ? null : mb_substr($reason, 0, 120),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Point an award at the wallet movement it credited. */
    public static function linkLedger(int $commissionId, int $ledgerId, ?PDO $pdo = null): void
    {
        $pdo ??= db();

        $pdo->prepare('UPDATE commissions SET ledger_id = ? WHERE id = ?')
            ->execute([max(1, $ledgerId), $commissionId]);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private static function select(): string
    {
        return 'SELECT c.*,
                       u.username  AS recipient_username,
                       u.full_name AS recipient_name,
                       u.member_status AS recipient_status,
                       s.username  AS source_username,
                       s.full_name AS source_name,
                       p.name      AS package_name,
                       p.slug      AS package_slug,
                       a.status         AS activation_status,
                       a.payment_method AS activation_method,
                       a.created_at     AS activation_created_at,
                       l.balance_after      AS wallet_balance_after,
                       l.withdrawable_after AS wallet_withdrawable_after
                  FROM commissions c
                  JOIN users u ON u.id = c.user_id
                  JOIN users s ON s.id = c.source_user_id
                  JOIN packages p ON p.id = c.package_id
                  JOIN activations a ON a.id = c.activation_id
                  LEFT JOIN ewallet_ledger l ON l.id = c.ledger_id';
    }
}
