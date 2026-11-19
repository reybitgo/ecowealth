<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryAward — the product-binary money ledger (22D-S1)
 *  (plan §15.4 `binary_awards`, §14.8, §14.9, §16.2)
 *
 *  One row per (order, recipient, kind) decision: `self` (one per
 *  order, paid to the buyer) or `pair` (one per node per order,
 *  `pair_count` pairs at once). Escrowed exactly like the unilevel
 *  stream: `pending` at payment, `credited` after delivery +
 *  `hold_days`, cancelled on an undelivered cancellation, voided + best-
 *  effort clawback on a delivered cancellation.
 *
 *  Status machine (Phase 18's escrow contract, reused verbatim):
 *
 *      pending    earned at payment, not in the wallet yet. No ledger
 *                 row, no wallet movement; non-spendable, non-withdrawable
 *                 until release (§16.8)
 *      credited   released: the wallet was credited (ledger_id and
 *                 credited_at are set)
 *      cancelled  the order was cancelled before release; the award
 *                 will never pay. No ledger row, no shortfall
 *      voided     paid, then reversed after cancellation; the money
 *                 columns never change — only the reversal columns do
 *
 *  `hold_days` and the rate/pair size are snapshotted at payment: a
 *  later setting change moves neither an award's release date nor its
 *  amount (plan ESC-04, D-10).
 *
 *  Writes live here and are called by BinaryService only, inside the
 *  caller's transaction. Reads are shared by the member page (22F),
 *  the admin ledger (22E) and the release job (22H).
 *
 *  The unique key (order_id, user_id, kind) is the idempotency anchor:
 *  the same order cannot pay the same kind to the same recipient twice
 *  (plan D-10).
 * ============================================================== */

final class BinaryAward
{
    /** The two money award kinds (D-10). */
    public const KINDS = ['self', 'pair'];

    /** Values `binary_awards.status` accepts. */
    public const STATUSES = ['pending', 'credited', 'voided', 'cancelled'];

    /** The statuses that count as awarded money. */
    public const AWARDED_STATUSES = ['pending', 'credited'];

    /** Human labels for the status pill. */
    public const STATUS_LABELS = [
        'pending'   => 'Pending release',
        'credited'  => 'Credited',
        'voided'    => 'Voided',
        'cancelled' => 'Cancelled',
    ];

    /** Badge tone per status (member card and admin pill copy). */
    public const STATUS_BADGES = [
        'pending'   => ['Pending release', 'warning'],
        'cancelled' => ['Cancelled before release', 'secondary'],
    ];

    /** Filter keys shared by the member tab (22F) and the admin tab (22E). */
    public const FILTER_KEYS = ['kind', 'status', 'from', 'to', 'q'];

    /** @var array{kind:string, status:string, from:string, to:string, q:string} */
    public const FILTER_DEFAULTS = [
        'kind'  => '',
        'status' => '',
        'from'  => '',
        'to'    => '',
        'q'     => '',
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

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(self::select() . ' WHERE a.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every award one order produced, in kind order then id order.
     *
     * @return list<array<string, mixed>>
     */
    public static function forOrder(int $orderId, ?PDO $pdo = null): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            self::select() . ' WHERE a.order_id = ? ORDER BY a.kind ASC, a.id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /** Every award for one recipient, newest first. @return list<array<string, mixed>> */
    public static function forUser(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            self::select() . ' WHERE a.user_id = ? ORDER BY a.id DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** Every award for one buyer (the purchases that paid awards). @return list<array<string, mixed>> */
    public static function forBuyer(int $buyerId, ?PDO $pdo = null): array
    {
        if ($buyerId <= 0) {
            return [];
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            self::select() . ' WHERE a.buyer_id = ? ORDER BY a.id DESC'
        );
        $stmt->execute([$buyerId]);

        return $stmt->fetchAll();
    }

    /**
     * The row for one (order, recipient, kind) — the unique-key shape.
     *
     * Layer 3 of the idempotency chain (plan §16.4): a retried
     * in-transaction write returns the decision that already exists
     * instead of inserting a second row.
     *
     * @return array<string, mixed>|null
     */
    public static function forEvent(int $orderId, int $userId, string $kind, ?PDO $pdo = null): ?array
    {
        if ($orderId <= 0 || $userId <= 0 || !in_array($kind, self::KINDS, true)) {
            return null;
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            'SELECT * FROM binary_awards
              WHERE order_id = ? AND user_id = ? AND kind = ?
              LIMIT 1'
        );
        $stmt->execute([$orderId, $userId, $kind]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /* ----------------------------------------------------------
     *  Filters and pages (shared by 22E and 22F)
     * ---------------------------------------------------------- */

    /** @return array{kind:string, status:string, from:string, to:string, q:string} */
    public static function filterDefaults(): array
    {
        return self::FILTER_DEFAULTS;
    }

    /**
     * Keep only the filters the queries understand; an unknown value
     * falls back to "no filter".
     *
     * @param array<string, mixed> $raw
     * @return array{kind:string, status:string, from:string, to:string, q:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $kind = strtolower(trim((string) ($raw['kind'] ?? '')));
        if (in_array($kind, self::KINDS, true)) {
            $filters['kind'] = $kind;
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
     * @param array{kind:string, status:string, from:string, to:string, q:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(?int $userId, array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($userId !== null) {
            $where[]  = 'a.user_id = ?';
            $params[] = $userId;
        }

        if (($filters['kind'] ?? '') !== '') {
            $where[]  = 'a.kind = ?';
            $params[] = $filters['kind'];
        }

        if (($filters['status'] ?? '') !== '') {
            $where[]  = 'a.status = ?';
            $params[] = $filters['status'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[]  = 'a.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]  = 'a.created_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        $query = trim((string) ($filters['q'] ?? ''));

        if ($query !== '') {
            $like    = '%' . like_escape($query) . '%';
            $where[] = '(u.username LIKE ? OR b.username LIKE ? OR o.order_no LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $filters */
    public static function countForUser(int $userId, array $filters, ?PDO $pdo = null): int
    {
        return self::countWhere($userId, $filters, $pdo);
    }

    /** @param array<string, mixed> $filters */
    public static function countAdmin(array $filters, ?PDO $pdo = null): int
    {
        return self::countWhere(null, $filters, $pdo);
    }

    /** @param array<string, mixed> $filters */
    private static function countWhere(?int $userId, array $filters, ?PDO $pdo): int
    {
        $pdo = $pdo ?? db();
        [$where, $params] = self::whereFor($userId, $filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM binary_awards a
               JOIN users u ON u.id = a.user_id
               JOIN users b ON b.id = a.buyer_id
               JOIN shop_orders o ON o.id = a.order_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of one member's awards, newest first (22F).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function pageForUser(int $userId, array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere($userId, $filters, $pager, $pdo);
    }

    /**
     * One page across every recipient (admin view, 22E).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere(null, $filters, $pager, $pdo);
    }

    /** @param array<string, mixed> $filters */
    private static function pageWhere(?int $userId, array $filters, Paginator $pager, ?PDO $pdo): array
    {
        $pdo = $pdo ?? db();
        [$where, $params] = self::whereFor($userId, $filters);

        $sql = self::select()
            . ' WHERE ' . $where
            . ' ORDER BY a.id DESC'
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Every award matching the filters, newest first — the CSV feed. */
    public static function exportRows(?int $userId, array $filters, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        [$where, $params] = self::whereFor($userId, $filters);

        $stmt = $pdo->prepare(
            self::select() . ' WHERE ' . $where . ' ORDER BY a.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Totals and breakdowns
     * ---------------------------------------------------------- */

    /**
     * Totals for one member: credited, pending, self, pair, points
     * placed split by side.
     *
     * @return array{
     *   count:int, credited:float, pending:float, pending_count:int,
     *   cancelled:int, cancelled_total:float, voided:int, voided_total:float,
     *   self_total:float, pair_total:float, self_count:int, pair_count:int,
     *   total_awarded:float, next_release_at:?string
     * }
     */
    public static function totalsForUser(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return self::emptyTotals();
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total_count,
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS credited,
                    COALESCE(SUM(CASE WHEN a.status = 'pending' THEN a.amount ELSE 0 END), 0) AS pending,
                    COUNT(CASE WHEN a.status = 'pending' THEN 1 END) AS pending_count,
                    COUNT(CASE WHEN a.status = 'cancelled' THEN 1 END) AS cancelled_count,
                    COALESCE(SUM(CASE WHEN a.status = 'cancelled' THEN a.amount ELSE 0 END), 0) AS cancelled_total,
                    COUNT(CASE WHEN a.status = 'voided' THEN 1 END) AS voided_count,
                    COALESCE(SUM(CASE WHEN a.status = 'voided' THEN a.amount ELSE 0 END), 0) AS voided_total,
                    COALESCE(SUM(CASE WHEN a.kind = 'self' AND a.status IN ('pending','credited') THEN a.amount ELSE 0 END), 0) AS self_total,
                    COALESCE(SUM(CASE WHEN a.kind = 'pair' AND a.status IN ('pending','credited') THEN a.amount ELSE 0 END), 0) AS pair_total,
                    COUNT(CASE WHEN a.kind = 'self' THEN 1 END) AS self_count,
                    COUNT(CASE WHEN a.kind = 'pair' THEN 1 END) AS pair_count,
                    MAX(CASE WHEN a.status = 'credited' THEN a.created_at END) AS last_at,
                    MIN(CASE WHEN a.status = 'pending'
                              AND o.status IN ('delivered','completed')
                              AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
                             THEN DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                                           INTERVAL a.hold_days DAY)
                         END) AS next_release_at
               FROM binary_awards a
               LEFT JOIN shop_orders o ON o.id = a.order_id
              WHERE a.user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        $credited = round((float) ($row['credited'] ?? 0), 2);
        $pending  = round((float) ($row['pending'] ?? 0), 2);

        return [
            'count'            => (int) ($row['total_count'] ?? 0),
            'credited'         => $credited,
            'pending'          => $pending,
            'pending_count'    => (int) ($row['pending_count'] ?? 0),
            'cancelled'        => (int) ($row['cancelled_count'] ?? 0),
            'cancelled_total'  => round((float) ($row['cancelled_total'] ?? 0), 2),
            'voided'           => (int) ($row['voided_count'] ?? 0),
            'voided_total'     => round((float) ($row['voided_total'] ?? 0), 2),
            'self_total'       => round((float) ($row['self_total'] ?? 0), 2),
            'pair_total'       => round((float) ($row['pair_total'] ?? 0), 2),
            'self_count'       => (int) ($row['self_count'] ?? 0),
            'pair_count'       => (int) ($row['pair_count'] ?? 0),
            'last_at'          => ($row['last_at'] ?? null) === null ? null : (string) $row['last_at'],
            'total_awarded'    => round($credited + $pending, 2),
            'next_release_at'  => ($row['next_release_at'] ?? null) === null
                ? null
                : (string) $row['next_release_at'],
        ];
    }

    private static function emptyTotals(): array
    {
        return [
            'count'            => 0,
            'credited'         => 0.0,
            'pending'          => 0.0,
            'pending_count'    => 0,
            'cancelled'        => 0,
            'cancelled_total'  => 0.0,
            'voided'           => 0,
            'voided_total'     => 0.0,
            'self_total'       => 0.0,
            'pair_total'       => 0.0,
            'self_count'       => 0,
            'pair_count'       => 0,
            'last_at'          => null,
            'total_awarded'    => 0.0,
            'next_release_at'  => null,
        ];
    }

    /**
     * Per-side breakdown for the member's Left/Right team cards (22F):
     * how many pairs each side has taken part in and the points on each
     * side.
     *
     * @param int|null $userId null for the whole ledger (admin view)
     * @return array{left:array{points:int, pairs:int, amount:float},
     *               right:array{points:int, pairs:int, amount:float}}
     */
    public static function sideBreakdown(?int $userId = null, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $out = [
            'left'  => ['points' => 0, 'pairs' => 0, 'amount' => 0.0],
            'right' => ['points' => 0, 'pairs' => 0, 'amount' => 0.0],
        ];

        $sql = "SELECT bv.side AS side,
                       COALESCE(SUM(bv.points), 0) AS points,
                       COALESCE(SUM(CASE WHEN bv.kind = 'pair' THEN -bv.points / ba.pair_points END), 0) AS pairs,
                       COALESCE(SUM(CASE WHEN ba.kind = 'pair' THEN ba.amount ELSE 0 END), 0) AS amount
                  FROM binary_volume bv
                  JOIN binary_awards ba ON ba.id = bv.award_id AND ba.kind = 'pair'
                 WHERE bv.kind = 'pair'";
        $params = [];

        if ($userId !== null) {
            $sql .= ' AND ba.user_id = ?';
            $params[] = $userId;
        }

        $sql .= ' GROUP BY bv.side';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $row) {
            $side = (string) ($row['side'] ?? '');
            if (!isset($out[$side])) {
                continue;
            }

            $out[$side] = [
                'points' => (int) $row['points'],
                'pairs'  => (int) $row['pairs'],
                'amount' => round((float) $row['amount'], 2),
            ];
        }

        return $out;
    }

    /**
     * Counters for the admin ledger header (22E).
     *
     * @return array{
     *   credited_total:float, this_month:float, credited:int,
     *   voided:int, unrecovered:float, recipients:int, average:float,
     *   pending_total:float, pending:int, cancelled:int,
     *   cancelled_total:float, total_awarded:float,
     *   next_release_at:?string, pending_recipients:int,
     *   self_total:float, pair_total:float
     * }
     */
    public static function stats(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $row = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS credited_total,
                    COALESCE(SUM(CASE WHEN a.status = 'credited'
                                       AND a.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN a.amount ELSE 0 END), 0) AS this_month,
                    COUNT(CASE WHEN a.status = 'credited' THEN 1 END) AS credited_count,
                    COUNT(CASE WHEN a.status = 'voided' THEN 1 END) AS voided_count,
                    COALESCE(SUM(CASE WHEN a.status = 'voided'
                                       THEN a.amount - a.reversed_amount ELSE 0 END), 0) AS unrecovered,
                    COUNT(DISTINCT CASE WHEN a.status = 'credited' THEN a.user_id END) AS recipients,
                    COALESCE(SUM(CASE WHEN a.status = 'pending' THEN a.amount ELSE 0 END), 0) AS pending_total,
                    COUNT(CASE WHEN a.status = 'pending' THEN 1 END) AS pending_count,
                    COUNT(CASE WHEN a.status = 'cancelled' THEN 1 END) AS cancelled_count,
                    COALESCE(SUM(CASE WHEN a.status = 'cancelled' THEN a.amount ELSE 0 END), 0) AS cancelled_total,
                    COUNT(DISTINCT CASE WHEN a.status = 'pending' THEN a.user_id END) AS pending_recipients,
                    MIN(CASE WHEN a.status = 'pending'
                              AND o.status IN ('delivered','completed')
                              AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
                             THEN DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                                           INTERVAL a.hold_days DAY)
                         END) AS next_release_at,
                    COALESCE(SUM(CASE WHEN a.kind = 'self' AND a.status IN ('pending','credited') THEN a.amount ELSE 0 END), 0) AS self_total,
                    COALESCE(SUM(CASE WHEN a.kind = 'pair' AND a.status IN ('pending','credited') THEN a.amount ELSE 0 END), 0) AS pair_total
               FROM binary_awards a
               LEFT JOIN shop_orders o ON o.id = a.order_id"
        )->fetch() ?: [];

        $credited = round((float) ($row['credited_total'] ?? 0), 2);
        $pending  = round((float) ($row['pending_total'] ?? 0), 2);
        $count    = (int) ($row['credited_count'] ?? 0);

        return [
            'credited_total'    => $credited,
            'this_month'        => round((float) ($row['this_month'] ?? 0), 2),
            'credited'          => $count,
            'voided'            => (int) ($row['voided_count'] ?? 0),
            'unrecovered'       => round((float) ($row['unrecovered'] ?? 0), 2),
            'recipients'        => (int) ($row['recipients'] ?? 0),
            'average'           => $count === 0 ? 0.0 : round($credited / $count, 2),
            'pending_total'     => $pending,
            'pending'           => (int) ($row['pending_count'] ?? 0),
            'cancelled'         => (int) ($row['cancelled_count'] ?? 0),
            'cancelled_total'   => round((float) ($row['cancelled_total'] ?? 0), 2),
            'total_awarded'     => round($credited + $pending, 2),
            'next_release_at'   => ($row['next_release_at'] ?? null) === null
                ? null
                : (string) $row['next_release_at'],
            'pending_recipients'=> (int) ($row['pending_recipients'] ?? 0),
            'self_total'        => round((float) ($row['self_total'] ?? 0), 2),
            'pair_total'        => round((float) ($row['pair_total'] ?? 0), 2),
        ];
    }

    /** Everything the earnings tab needs about one member's escrow (22F). */
    public static function pendingSummaryForUser(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [
                'total'           => 0.0,
                'count'           => 0,
                'next_release_at' => null,
                'undelivered'     => 0,
                'overdue'         => 0,
                'release_enabled' => setting_bool('binary_release_enabled', true),
            ];
        }

        $pdo = $pdo ?? db();

        if (!self::tableExists($pdo)) {
            return [
                'total'           => 0.0,
                'count'           => 0,
                'next_release_at' => null,
                'undelivered'     => 0,
                'overdue'         => 0,
                'release_enabled' => setting_bool('binary_release_enabled', true),
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS pending_count,
                    COALESCE(SUM(a.amount), 0) AS pending_total,
                    MIN(CASE
                          WHEN o.status IN ('delivered','completed')
                           AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
                          THEN DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                                        INTERVAL a.hold_days DAY)
                        END) AS next_release_at,
                    COALESCE(SUM(CASE
                          WHEN o.status NOT IN ('delivered','completed')
                            OR COALESCE(o.delivered_at, o.completed_at) IS NULL
                          THEN 1 ELSE 0
                        END), 0) AS undelivered,
                    COALESCE(SUM(CASE
                          WHEN o.status IN ('delivered','completed')
                           AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                                        INTERVAL a.hold_days DAY) < NOW()
                          THEN 1 ELSE 0
                        END), 0) AS overdue
               FROM binary_awards a
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.user_id = ? AND a.status = 'pending'"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'total'           => round((float) ($row['pending_total'] ?? 0), 2),
            'count'           => (int) ($row['pending_count'] ?? 0),
            'next_release_at' => ($row['next_release_at'] ?? null) === null
                ? null
                : (string) $row['next_release_at'],
            'undelivered'     => (int) ($row['undelivered'] ?? 0),
            'overdue'         => (int) ($row['overdue'] ?? 0),
            'release_enabled' => setting_bool('binary_release_enabled', true),
        ];
    }

    /* ----------------------------------------------------------
     *  CSV
     * ---------------------------------------------------------- */

    /** The CSV columns, in the documented order. @return list<string> */
    public static function csvHeader(): array
    {
        return [
            'Award', 'Date', 'Order', 'Buyer', 'Recipient', 'Kind', 'Side',
            'Points', 'Pair count', 'Pair points', 'Rate %', 'Amount',
            'Status', 'Hold days', 'Released at', 'Reversed',
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<int, string>> */
    public static function csvRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                (string) $row['id'],
                (string) ($row['created_at'] ?? ''),
                (string) ($row['order_no'] ?? ''),
                (string) ($row['buyer_username'] ?? ''),
                (string) ($row['recipient_username'] ?? ''),
                (string) $row['kind'],
                (string) ($row['side'] ?? ''),
                (string) $row['points'],
                (string) $row['pair_count'],
                (string) $row['pair_points'],
                number_format((float) $row['rate_pct'], 3, '.', ''),
                number_format((float) $row['amount'], 2, '.', ''),
                (string) $row['status'],
                (string) (int) ($row['hold_days'] ?? 0),
                (string) ($row['credited_at'] ?? ''),
                number_format((float) $row['reversed_amount'], 2, '.', ''),
            ];
        }

        return $out;
    }

    /* ----------------------------------------------------------
     *  Writes — called by BinaryService only
     * ---------------------------------------------------------- */

    /**
     * Insert one award row.
     *
     * The caller owns the transaction: the row and the order stamp must
     * commit or roll back together. A zero amount is refused — the
     * engine never writes a `0.00` row (§14.11 rule 5). A `self` award
     * needs `points > 0` and `pair_count = 0`; a `pair` award needs
     * `pair_count > 0`, `pair_points > 0` and `points = 2 × pair_count
     * × pair_points` (schema CHECK enforces this too).
     *
     * @param array{
     *   kind:string, order_id:int, buyer_id:int, user_id:int,
     *   side?:string, points?:int|string, pair_count?:int|string,
     *   pair_points?:int|string, rate_pct?:float|string, amount:float|string,
     *   status?:string, hold_days?:int|string, paid_at?:string
     * } $data
     * @return int the new award id
     * @throws InvalidArgumentException when the row shape is invalid
     */
    public static function create(array $data, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?? db();

        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown binary award kind: ' . $kind);
        }

        foreach (['order_id', 'buyer_id', 'user_id'] as $key) {
            if ((int) ($data[$key] ?? 0) <= 0) {
                throw new InvalidArgumentException('A binary award needs a positive ' . $key . '.');
            }
        }

        $status = (string) ($data['status'] ?? 'pending');
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown binary award status: ' . $status);
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('A binary award must be greater than zero.');
        }

        $points   = (int) ($data['points'] ?? 0);
        $pairCount = (int) ($data['pair_count'] ?? 0);
        $pairPoints = (int) ($data['pair_points'] ?? 0);
        $rate     = round((float) ($data['rate_pct'] ?? 0), 3);
        $side     = (string) ($data['side'] ?? '');
        $holdDays = max(0, min(365, (int) ($data['hold_days'] ?? 0)));

        if ($kind === 'self') {
            if ($points <= 0) {
                throw new InvalidArgumentException('A self award needs positive points.');
            }
            if ($pairCount !== 0) {
                throw new InvalidArgumentException('A self award has no pairs.');
            }
            if ($side === '') {
                $side = null;
            }
        } else {
            if ($pairCount <= 0) {
                throw new InvalidArgumentException('A pair award needs pair_count above zero.');
            }
            if ($pairPoints <= 0) {
                throw new InvalidArgumentException('A pair award needs pair_points above zero.');
            }
            if ($points !== 2 * $pairCount * $pairPoints) {
                throw new InvalidArgumentException(
                    'Pair points must equal 2 × pair_count × pair_points.'
                );
            }
            if (!in_array($side, ['left', 'right'], true)) {
                throw new InvalidArgumentException('A pair award needs side "left" or "right".');
            }
        }

        if ($rate < 0 || $rate > 100) {
            throw new InvalidArgumentException('Binary rate out of range: ' . $rate);
        }

        $paidAt = trim((string) ($data['paid_at'] ?? ''));
        if ($paidAt === '') {
            $paidAt = date('Y-m-d H:i:s');
        }

        $pdo->prepare(
            'INSERT INTO binary_awards
               (kind, order_id, buyer_id, user_id, side, points, pair_count,
                pair_points, rate_pct, amount, status, hold_days, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $kind,
            (int) $data['order_id'],
            (int) $data['buyer_id'],
            (int) $data['user_id'],
            $side === '' ? null : $side,
            $points,
            $pairCount,
            $pairPoints,
            number_format($rate, 3, '.', ''),
            number_format($amount, 2, '.', ''),
            $status,
            $holdDays,
            $paidAt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Point an award at the wallet movement that credited it. */
    public static function linkLedger(int $awardId, int $ledgerId, ?PDO $pdo = null): void
    {
        $pdo = $pdo ?? db();

        $pdo->prepare('UPDATE binary_awards SET ledger_id = ? WHERE id = ?')
            ->execute([max(1, $ledgerId), $awardId]);
    }

    /**
     * Release one pending award: point it at the wallet movement that
     * paid it and stamp the release date.
     *
     * The `AND status = 'pending'` guard makes a replayed release a
     * no-op (plan §16.4 layer 4).
     *
     * @return bool true when this call released the row
     */
    public static function markCredited(int $awardId, int $ledgerId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "UPDATE binary_awards
                SET status = 'credited', ledger_id = ?, credited_at = NOW()
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([max(1, $ledgerId), $awardId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Cancel a pending award before any money moved.
     *
     * `reversed_amount` stays 0 and `reversal_ledger_id` stays NULL:
     * nothing was paid, so there is nothing to recover. The guard
     * `AND status = 'pending'` means a cancelled award can never be
     * marked paid afterwards.
     *
     * @return bool true when this call cancelled the row
     */
    public static function cancel(int $awardId, string $reason, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? db();

        $reason = trim($reason);

        $stmt = $pdo->prepare(
            "UPDATE binary_awards
                SET status = 'cancelled', voided_at = NOW(), void_reason = ?
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([
            $reason === '' ? 'Order cancelled before release' : mb_substr($reason, 0, 160),
            $awardId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Void a credited award and record its reversal.
     *
     * Only the reversal columns change: `amount`, `kind`, `rate_pct`
     * and `ledger_id` stay as history. The guard `AND status = 'credited'`
     * makes a second call a no-op.
     *
     * @param array{reversal_ledger_id?:?int, reversed_amount?:float|string, void_reason?:string} $data
     * @return bool true when this call voided the row
     */
    public static function void(int $awardId, array $data = [], ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? db();

        $reversalLedgerId = isset($data['reversal_ledger_id']) && (int) $data['reversal_ledger_id'] > 0
            ? (int) $data['reversal_ledger_id']
            : null;

        $reversed = round((float) ($data['reversed_amount'] ?? 0), 2);
        if ($reversed < 0) {
            $reversed = 0.0;
        }

        $reason = trim((string) ($data['void_reason'] ?? ''));

        $stmt = $pdo->prepare(
            "UPDATE binary_awards
                SET status = 'voided', voided_at = NOW(), void_reason = ?,
                    reversal_ledger_id = ?, reversed_amount = ?
              WHERE id = ? AND status = 'credited'"
        );
        $stmt->execute([
            $reason === '' ? null : mb_substr($reason, 0, 160),
            $reversalLedgerId,
            number_format($reversed, 2, '.', ''),
            $awardId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Keep `shop_orders.binary_self_total` / `binary_pair_total` /
     * `binary_total` in step with the ledger after a cancellation or
     * reversal (plan §16.9 step 4).
     *
     * The total is the **awarded** figure: pending + credited. A
     * released award and a pending one both count, so a release job run
     * never changes the order card's headline; after a full cancellation
     * the total is 0.
     */
    public static function recomputeOrderTotal(int $orderId, ?PDO $pdo = null): void
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN kind = 'self' AND status IN ('pending','credited') THEN amount ELSE 0 END), 0) AS self_total,
                COALESCE(SUM(CASE WHEN kind = 'pair' AND status IN ('pending','credited') THEN amount ELSE 0 END), 0) AS pair_total
               FROM binary_awards WHERE order_id = ?"
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch() ?: [];

        $selfTotal  = round((float) ($row['self_total'] ?? 0), 2);
        $pairTotal  = round((float) ($row['pair_total'] ?? 0), 2);
        $total      = round($selfTotal + $pairTotal, 2);

        $pdo->prepare(
            'UPDATE shop_orders
               SET binary_self_total = ?, binary_pair_total = ?, binary_total = ?
             WHERE id = ?'
        )->execute([
            number_format($selfTotal, 2, '.', ''),
            number_format($pairTotal, 2, '.', ''),
            number_format($total, 2, '.', ''),
            $orderId,
        ]);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private static function select(): string
    {
        return 'SELECT a.*,
                       u.username       AS recipient_username,
                       u.full_name      AS recipient_name,
                       u.member_status  AS recipient_membership,
                       b.username       AS buyer_username,
                       b.full_name      AS buyer_name,
                       o.order_no       AS order_no,
                       o.status         AS order_status,
                       o.delivered_at   AS order_delivered_at,
                       o.completed_at   AS order_completed_at,
                       l.balance_after      AS wallet_balance_after,
                       l.withdrawable_after AS wallet_withdrawable_after
                  FROM binary_awards a
                  JOIN users u       ON u.id = a.user_id
                  JOIN users b       ON b.id = a.buyer_id
                  JOIN shop_orders o ON o.id = a.order_id
                  LEFT JOIN ewallet_ledger l ON l.id = a.ledger_id';
    }

    /** Does this connection's schema still carry the Phase 22A table? */
    public static function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['binary_awards']);

        return (int) $stmt->fetchColumn() > 0;
    }
}
