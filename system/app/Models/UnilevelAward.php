<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelAward — the repeat-purchase money ledger (17C)
 *  (plan §13.2 `unilevel_awards`, §14.2, §14.3, §14.11)
 *
 *  One row per (order line, recipient, level) decision. There is no
 *  `type` column: the level (1–10) is the whole story (D-17). The
 *  buyer is never a recipient (D-09), so `buyer_id` is the source of
 *  the purchase and `user_id` is the upline who was paid.
 *
 *  Status machine (Phase 18 adds the escrow states):
 *
 *      pending    earned at payment, not in the wallet yet. No ledger
 *                 row, no wallet movement; the money is non-spendable
 *                 and non-withdrawable until release (plan ESC-09)
 *      credited   released: the wallet was credited (ledger_id and
 *                 credited_at are set)
 *      skipped    the award was due but the recipient was ineligible
 *                 (account disabled, membership, not Active, company
 *                 account); audited, never paid
 *      cancelled  the order was cancelled before release; the award
 *                 will never pay. No ledger row, no shortfall
 *      voided     paid, then reversed after the order was cancelled;
 *                 the money columns never change — only the reversal
 *                 columns do (plan §14.9)
 *
 *  `hold_days` is snapshotted at payment: a later setting change cannot
 *  move an award's promised release date (plan ESC-04).
 *
 *  Writes live here and are called by UnilevelService only, inside the
 *  caller's transaction. Reads are shared by the member page (17E), the
 *  admin ledger (17D) and the escrow presentation (18); this file ships
 *  the whole read API they need so no later phase invents a second
 *  implementation.
 *
 *  The unique key (order_item_id, user_id, level) is the money anchor:
 *  the same line cannot pay the same upline at the same level twice.
 * ============================================================== */

final class UnilevelAward
{
    /** First ladder level (the buyer's direct sponsor). */
    public const LEVEL_MIN = 1;

    /** Last ladder level. */
    public const LEVEL_MAX = 10;

    /** Values `unilevel_awards.status` accepts. */
    public const STATUSES = ['pending', 'credited', 'skipped', 'voided', 'cancelled'];

    /** The statuses that count as awarded money (plan ESC-08). */
    public const AWARDED_STATUSES = ['pending', 'credited'];

    /** Values `unilevel_awards.base_source` accepts. */
    public const BASE_SOURCES = ['line', 'product'];

    /** Human labels for the status pill. */
    public const STATUS_LABELS = [
        'pending'   => 'Pending release',
        'credited'  => 'Credited',
        'skipped'   => 'Skipped',
        'voided'    => 'Voided',
        'cancelled' => 'Cancelled',
    ];

    /** The label overrides the repeat views pass to status_badge(). */
    public const STATUS_BADGES = [
        'pending'   => ['Pending release', 'warning'],
        'cancelled' => ['Cancelled before release', 'secondary'],
    ];

    /** @var array{level:string, status:string, product_id:string, from:string, to:string, q:string} */
    public const FILTER_DEFAULTS = [
        'level'      => '',
        'status'     => '',
        'product_id' => '',
        'from'       => '',
        'to'         => '',
        'q'          => '',
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
     * Every award one order produced, line order then level order.
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
            self::select() . ' WHERE a.order_id = ? ORDER BY a.order_item_id ASC, a.level ASC, a.id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /**
     * Every award one order line produced (optionally one level).
     *
     * @return list<array<string, mixed>>
     */
    public static function forItem(int $orderItemId, ?int $level = null, ?PDO $pdo = null): array
    {
        if ($orderItemId <= 0) {
            return [];
        }

        $pdo    = $pdo ?? db();
        $sql    = self::select() . ' WHERE a.order_item_id = ?';
        $params = [$orderItemId];

        if ($level !== null && $level >= self::LEVEL_MIN && $level <= self::LEVEL_MAX) {
            $sql     .= ' AND a.level = ?';
            $params[] = $level;
        }

        $stmt = $pdo->prepare($sql . ' ORDER BY a.level ASC, a.id ASC');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * The row for one (line, recipient, level) — the unique-key shape.
     *
     * Layer 2 of the idempotency chain (plan §14.4): a retried
     * in-transaction write returns the row that already exists instead
     * of inserting a second one.
     *
     * @return array<string, mixed>|null
     */
    public static function forEvent(int $orderItemId, int $userId, int $level, ?PDO $pdo = null): ?array
    {
        if ($orderItemId <= 0 || $userId <= 0 || $level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            return null;
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            'SELECT * FROM unilevel_awards
              WHERE order_item_id = ? AND user_id = ? AND level = ?
              LIMIT 1'
        );
        $stmt->execute([$orderItemId, $userId, $level]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Awards for one buyer (the purchases that paid their uplines).
     *
     * @return list<array<string, mixed>>
     */
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

    /* ----------------------------------------------------------
     *  Filters and pages (shared by 17D and 17E)
     * ---------------------------------------------------------- */

    /** @return array{level:string, status:string, product_id:string, from:string, to:string, q:string} */
    public static function filterDefaults(): array
    {
        return self::FILTER_DEFAULTS;
    }

    /**
     * Keep only the filters the queries understand; an unknown value
     * falls back to "no filter", so a hand-edited query string can
     * never break a page (plan §8 Rule 7).
     *
     * @param array<string, mixed> $raw
     * @return array{level:string, status:string, product_id:string, from:string, to:string, q:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $level = trim((string) ($raw['level'] ?? ''));
        if (ctype_digit($level) && (int) $level >= self::LEVEL_MIN && (int) $level <= self::LEVEL_MAX) {
            $filters['level'] = (string) (int) $level;
        }

        $status = strtolower(trim((string) ($raw['status'] ?? '')));
        if (in_array($status, self::STATUSES, true)) {
            $filters['status'] = $status;
        }

        $productId = trim((string) ($raw['product_id'] ?? ''));
        if (ctype_digit($productId) && (int) $productId > 0) {
            $filters['product_id'] = (string) (int) $productId;
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
     * @param array{level:string, status:string, product_id:string, from:string, to:string, q:string} $filters
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

        if (($filters['level'] ?? '') !== '') {
            $where[]  = 'a.level = ?';
            $params[] = (int) $filters['level'];
        }

        if (($filters['status'] ?? '') !== '') {
            $where[]  = 'a.status = ?';
            $params[] = $filters['status'];
        }

        if (($filters['product_id'] ?? '') !== '') {
            $where[]  = 'a.product_id = ?';
            $params[] = (int) $filters['product_id'];
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
            $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR b.username LIKE ?'
                . ' OR p.name LIKE ? OR o.order_no LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
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
               FROM unilevel_awards a
               JOIN users u    ON u.id = a.user_id
               JOIN users b    ON b.id = a.buyer_id
               JOIN products p ON p.id = a.product_id
               JOIN shop_orders o ON o.id = a.order_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of one member's awards, newest first (17E).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function pageForUser(int $userId, array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere($userId, $filters, $pager, $pdo);
    }

    /**
     * One page across every member (17D).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere(null, $filters, $pager, $pdo);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
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

    /**
     * Every award matching the filters, newest first — the CSV feed.
     * Deliberately unpaginated (an export is the whole filtered set).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
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
     * Totals for one member: what was credited, what is still pending
     * release, when the next release is due, and what was skipped or
     * cancelled.
     *
     * `credited` / `count` / `this_month` keep their Phase 17 meaning:
     * released money. `pending` is the escrow figure (earned, not in
     * the wallet); `total_awarded` is the presentation sum the member's
     * pages show as "earned" (plan ESC-08, §15.6).
     *
     * @return array{
     *   count:int, credited:float, this_month:float, skipped:int, last_at:?string,
     *   pending:float, pending_count:int, cancelled:int,
     *   next_release_at:?string, total_awarded:float, earned_this_month:float
     * }
     */
    public static function totalsForUser(int $userId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT COUNT(CASE WHEN a.status = 'credited' THEN 1 END) AS total_count,
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS credited,
                    COALESCE(SUM(CASE WHEN a.status = 'credited'
                                       AND a.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN a.amount ELSE 0 END), 0) AS this_month,
                    COUNT(CASE WHEN a.status = 'skipped' THEN 1 END) AS skipped_count,
                    MAX(CASE WHEN a.status = 'credited' THEN a.created_at END) AS last_at,
                    COALESCE(SUM(CASE WHEN a.status = 'pending' THEN a.amount ELSE 0 END), 0) AS pending,
                    COUNT(CASE WHEN a.status = 'pending' THEN 1 END) AS pending_count,
                    COUNT(CASE WHEN a.status = 'cancelled' THEN 1 END) AS cancelled_count,
                    COALESCE(SUM(CASE WHEN a.status IN ('pending','credited')
                                       AND a.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN a.amount ELSE 0 END), 0) AS earned_this_month,
                    MIN(CASE WHEN a.status = 'pending'
                              AND o.status IN ('delivered','completed')
                              AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
                             THEN DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                                           INTERVAL a.hold_days DAY)
                        END) AS next_release_at
               FROM unilevel_awards a
               LEFT JOIN shop_orders o ON o.id = a.order_id
              WHERE a.user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        $credited = round((float) ($row['credited'] ?? 0), 2);
        $pending  = round((float) ($row['pending'] ?? 0), 2);

        return [
            'count'             => (int) ($row['total_count'] ?? 0),
            'credited'          => $credited,
            'this_month'        => round((float) ($row['this_month'] ?? 0), 2),
            'skipped'           => (int) ($row['skipped_count'] ?? 0),
            'last_at'           => ($row['last_at'] ?? null) === null ? null : (string) $row['last_at'],
            'pending'           => $pending,
            'pending_count'     => (int) ($row['pending_count'] ?? 0),
            'cancelled'         => (int) ($row['cancelled_count'] ?? 0),
            'next_release_at'   => ($row['next_release_at'] ?? null) === null
                ? null
                : (string) $row['next_release_at'],
            'total_awarded'     => round($credited + $pending, 2),
            'earned_this_month' => round((float) ($row['earned_this_month'] ?? 0), 2),
        ];
    }

    /**
     * Per-recipient earnings split by the buying team member and by
     * level (17E follow-up, extended in 18).
     *
     * The earnings tab shows the member's team by level: a level-1 row
     * is a direct recruit, a level-2 row is one of their recruits, and
     * so on. Each row needs the money that member's purchases credited
     * to the session member at that depth, so this read groups the
     * ledger by `(level, buyer_id, status)` in one query instead of one
     * query per team row.
     *
     * `amount` / `count` keep their Phase 17 meaning (released money);
     * `pending_amount` / `pending_count` are the escrow figure, so the
     * team card can show both without a second query. A buyer with no
     * award never appears in the result and is rendered at ₱0.00 by the
     * caller.
     *
     * @return array<int, array<int, array{
     *   amount:float, count:int, pending_amount:float, pending_count:int
     * }>> level => buyer id => released + pending figures
     */
    public static function creditedByBuyer(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT level, buyer_id, status, COUNT(*) AS award_count, COALESCE(SUM(amount), 0) AS amount
               FROM unilevel_awards
              WHERE user_id = ? AND status IN ('pending','credited')
              GROUP BY level, buyer_id, status"
        );
        $stmt->execute([$userId]);

        $earned = [];

        foreach ($stmt->fetchAll() as $row) {
            $level = (int) $row['level'];
            $buyer = (int) $row['buyer_id'];

            if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX || $buyer <= 0) {
                continue;
            }

            $earned[$level][$buyer] ??= [
                'amount'         => 0.0,
                'count'          => 0,
                'pending_amount' => 0.0,
                'pending_count'  => 0,
            ];

            if ((string) $row['status'] === 'pending') {
                $earned[$level][$buyer]['pending_amount'] = round((float) $row['amount'], 2);
                $earned[$level][$buyer]['pending_count']  = (int) $row['award_count'];
            } else {
                $earned[$level][$buyer]['amount'] = round((float) $row['amount'], 2);
                $earned[$level][$buyer]['count']  = (int) $row['award_count'];
            }
        }

        return $earned;
    }

    /**
     * Per-level totals (1 … 10) for the breakdown card.
     *
     * `amount` / `count` keep their Phase 17 meaning (released money);
     * `pending_amount` / `pending_count` are the escrow figure, so the
     * card can show both without adding a second query.
     *
     * @param int|null $userId null for the whole ledger (admin view)
     * @return array<int, array{amount:float, count:int, pending_amount:float, pending_count:int}>
     */
    public static function levelBreakdown(?int $userId = null, ?PDO $pdo = null): array
    {
        $pdo    = $pdo ?? db();
        $levels = [];

        for ($level = self::LEVEL_MIN; $level <= self::LEVEL_MAX; $level++) {
            $levels[$level] = [
                'amount'         => 0.0,
                'count'          => 0,
                'pending_amount' => 0.0,
                'pending_count'  => 0,
            ];
        }

        $sql    = "SELECT level, status, COUNT(*) AS rows_count, COALESCE(SUM(amount), 0) AS amount
                     FROM unilevel_awards
                    WHERE status IN ('pending','credited')";
        $params = [];

        if ($userId !== null) {
            $sql     .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $stmt = $pdo->prepare($sql . ' GROUP BY level, status');
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $row) {
            $level = (int) $row['level'];

            if (!isset($levels[$level])) {
                continue;
            }

            if ((string) $row['status'] === 'pending') {
                $levels[$level]['pending_amount'] = round((float) $row['amount'], 2);
                $levels[$level]['pending_count']  = (int) $row['rows_count'];
            } else {
                $levels[$level]['amount'] = round((float) $row['amount'], 2);
                $levels[$level]['count']  = (int) $row['rows_count'];
            }
        }

        return $levels;
    }

    /**
     * Counters for the admin ledger header (17D, extended in 18).
     *
     * `unrecovered` counts ONLY `voided` awards: a `cancelled` award was
     * never paid, so it is not a shortfall (plan §15.7). `recipients`
     * and `average` stay based on released money; `pending_recipients`
     * is the separate escrow count.
     *
     * @return array{
     *   credited_total:float, this_month:float, credited:int, skipped:int,
     *   recipients:int, average:float, top_level:int, voided:int, unrecovered:float,
     *   pending_total:float, pending:int, cancelled:int, cancelled_total:float,
     *   total_awarded:float, next_release_at:?string, pending_recipients:int
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
                    COUNT(CASE WHEN a.status = 'skipped' THEN 1 END) AS skipped_count,
                    COUNT(CASE WHEN a.status = 'voided' THEN 1 END) AS voided_count,
                    COALESCE(SUM(CASE WHEN a.status = 'voided'
                                      THEN a.amount - a.reversed_amount ELSE 0 END), 0) AS unrecovered,
                    COUNT(DISTINCT CASE WHEN a.status = 'credited' THEN a.user_id END) AS recipients,
                    MAX(CASE WHEN a.status = 'credited' THEN a.level END) AS top_level,
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
                        END) AS next_release_at
               FROM unilevel_awards a
               LEFT JOIN shop_orders o ON o.id = a.order_id"
        )->fetch() ?: [];

        $credited = round((float) ($row['credited_total'] ?? 0), 2);
        $pending  = round((float) ($row['pending_total'] ?? 0), 2);
        $count    = (int) ($row['credited_count'] ?? 0);

        return [
            'credited_total'     => $credited,
            'this_month'         => round((float) ($row['this_month'] ?? 0), 2),
            'credited'           => $count,
            'skipped'            => (int) ($row['skipped_count'] ?? 0),
            'voided'             => (int) ($row['voided_count'] ?? 0),
            'unrecovered'        => round((float) ($row['unrecovered'] ?? 0), 2),
            'recipients'         => (int) ($row['recipients'] ?? 0),
            'average'            => $count === 0 ? 0.0 : round($credited / $count, 2),
            'top_level'          => (int) ($row['top_level'] ?? 0),
            'pending_total'      => $pending,
            'pending'            => (int) ($row['pending_count'] ?? 0),
            'cancelled'          => (int) ($row['cancelled_count'] ?? 0),
            'cancelled_total'    => round((float) ($row['cancelled_total'] ?? 0), 2),
            'total_awarded'      => round($credited + $pending, 2),
            'next_release_at'    => ($row['next_release_at'] ?? null) === null
                ? null
                : (string) $row['next_release_at'],
            'pending_recipients' => (int) ($row['pending_recipients'] ?? 0),
        ];
    }

    /**
     * The CSV columns, in the documented order (17D/17E/18).
     *
     * The two Phase 18 columns are appended so an existing export
     * consumer keeps its positional columns (plan §26 #19).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<int, string>>
     */
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
                (string) ($row['product_name'] ?? ''),
                (string) $row['level'],
                number_format((float) $row['base_amount'], 2, '.', ''),
                number_format((float) $row['rate_pct'], 3, '.', ''),
                number_format((float) $row['amount'], 2, '.', ''),
                (string) $row['status'],
                (string) ($row['reason'] ?? ''),
                number_format((float) $row['reversed_amount'], 2, '.', ''),
                (string) (int) ($row['hold_days'] ?? 0),
                (string) ($row['credited_at'] ?? ''),
            ];
        }

        return $out;
    }

    /** The header row matching csvRows(). @return list<string> */
    public static function csvHeader(): array
    {
        return [
            'Award', 'Date', 'Order', 'Buyer', 'Recipient', 'Product', 'Level',
            'Base', 'Rate %', 'Amount', 'Status', 'Reason', 'Recovered',
            'Hold days', 'Released at',
        ];
    }

    /* ----------------------------------------------------------
     *  Writes — called by UnilevelService only
     * ---------------------------------------------------------- */

    /**
     * Insert one award row.
     *
     * The caller owns the transaction: the row and (from Phase 18 on)
     * the order stamp must commit or roll back together. A zero amount
     * is refused here — the engine never writes a `0.00` row
     * (plan §12.8); a zero rate/base means "no row", not "a zero row".
     *
     * A new award defaults to `pending`: it is earned at payment and the
     * wallet is credited later by the release job (plan ESC-02). The
     * caller may still pass `status` (a `skipped` decision, or the
     * legacy `credited` shape a test fixture wants).
     *
     * @param array{
     *   order_id:int, order_item_id:int, product_id:int, buyer_id:int,
     *   user_id:int, level:int, base_amount?:float|string, base?:float|string,
     *   base_source?:string, rate_pct?:float|string, rate?:float|string,
     *   amount:float|string, status?:string, reason?:?string,
     *   hold_days?:int|string, paid_at?:string
     * } $data
     * @return int the new award id
     */
    public static function create(array $data, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?? db();

        foreach (['order_id', 'order_item_id', 'product_id', 'buyer_id', 'user_id'] as $key) {
            if ((int) ($data[$key] ?? 0) <= 0) {
                throw new InvalidArgumentException('A repeat-purchase award needs a positive ' . $key . '.');
            }
        }

        $level = (int) ($data['level'] ?? 0);

        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException('Repeat-purchase level out of range: ' . $level);
        }

        $status = (string) ($data['status'] ?? 'pending');

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown repeat-purchase status: ' . $status);
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('A repeat-purchase award must be greater than zero.');
        }

        $base = round((float) ($data['base_amount'] ?? $data['base'] ?? 0), 2);

        if ($base < 0) {
            throw new InvalidArgumentException('A repeat-purchase base cannot be negative.');
        }

        $baseSource = (string) ($data['base_source'] ?? 'line');

        if (!in_array($baseSource, self::BASE_SOURCES, true)) {
            $baseSource = 'line';
        }

        $rate = round((float) ($data['rate_pct'] ?? $data['rate'] ?? 0), 3);

        if ($rate < 0 || $rate > 100) {
            throw new InvalidArgumentException('Repeat-purchase rate out of range: ' . $rate);
        }

        /* The hold is snapshotted per award (plan ESC-04): clamp to the
         * column's documented range instead of trusting a caller. */
        $holdDays = max(0, min(365, (int) ($data['hold_days'] ?? 0)));

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($status === 'skipped' && $reason === '') {
            $reason = 'not eligible';
        }

        $paidAt = trim((string) ($data['paid_at'] ?? ''));

        if ($paidAt === '') {
            $paidAt = date('Y-m-d H:i:s');
        }

        $pdo->prepare(
            'INSERT INTO unilevel_awards
               (order_id, order_item_id, product_id, buyer_id, user_id, level,
                base_amount, base_source, rate_pct, amount, hold_days,
                status, reason, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $data['order_id'],
            (int) $data['order_item_id'],
            (int) $data['product_id'],
            (int) $data['buyer_id'],
            (int) $data['user_id'],
            $level,
            number_format($base, 2, '.', ''),
            $baseSource,
            number_format($rate, 3, '.', ''),
            number_format($amount, 2, '.', ''),
            $holdDays,
            $status,
            $reason === '' ? null : mb_substr($reason, 0, 120),
            $paidAt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Point an award at the wallet movement that credited it. */
    public static function linkLedger(int $awardId, int $ledgerId, ?PDO $pdo = null): void
    {
        $pdo = $pdo ?? db();

        $pdo->prepare('UPDATE unilevel_awards SET ledger_id = ? WHERE id = ?')
            ->execute([max(1, $ledgerId), $awardId]);
    }

    /**
     * Release one pending award: point it at the wallet movement that
     * paid it and stamp the release date (plan §15.3, Appendix B).
     *
     * The `AND status = 'pending'` guard makes a replayed release a
     * no-op, even if the caller's order lock somehow allowed it.
     *
     * @return bool true when this call released the row
     */
    public static function markCredited(int $awardId, int $ledgerId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "UPDATE unilevel_awards
                SET status = 'credited', ledger_id = ?, credited_at = NOW()
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([max(1, $ledgerId), $awardId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Cancel a pending award before any money moved (plan §15.3, §8).
     *
     * `reversed_amount` stays 0 and `reversal_ledger_id` stays NULL:
     * nothing was paid, so there is nothing to recover and no
     * shortfall. The `AND status = 'pending'` guard means a cancelled
     * award can never be marked paid afterwards.
     *
     * @return bool true when this call cancelled the row
     */
    public static function cancel(int $awardId, string $reason, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? db();

        $reason = trim($reason);

        $stmt = $pdo->prepare(
            "UPDATE unilevel_awards
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
     * Only the reversal columns change: `amount`, `level`, `rate_pct`
     * and `ledger_id` stay as history (plan §15.2 Rule 2). The guard
     * `AND status = 'credited'` makes a second call a no-op.
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
            "UPDATE unilevel_awards
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
     * Keep `shop_orders.unilevel_total` in step with the ledger after a
     * cancellation or reversal (plan §13.7 #4, §14.9).
     *
     * The total is the **awarded** figure: pending + credited
     * (plan ESC-08). A released award and a pending one both count, so
     * a release job run never changes the order card's headline; after
     * a full cancellation the total is 0.
     */
    public static function recomputeOrderTotal(int $orderId, ?PDO $pdo = null): float
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM unilevel_awards
              WHERE order_id = ? AND status IN ('pending','credited')"
        );
        $stmt->execute([$orderId]);
        $total = round((float) $stmt->fetchColumn(), 2);

        $pdo->prepare('UPDATE shop_orders SET unilevel_total = ? WHERE id = ?')
            ->execute([number_format($total, 2, '.', ''), $orderId]);

        return $total;
    }

    /* ----------------------------------------------------------
     *  Escrow presentation (Phase 18 A++)
     * ---------------------------------------------------------- */

    /**
     * Everything the A++ pages need about one member's escrowed money
     * (plan §15.11):
     *
     *   total / count     pending release amount and rows
     *   next_release_at   earliest promised date among awards on a
     *                     delivered/completed order (null when every
     *                     pending award is still undelivered)
     *   undelivered       pending rows whose order has not been
     *                     delivered/completed yet
     *   overdue           pending rows already due (the release job has
     *                     not paid them yet)
     *   release_enabled   the operator's release switch
     *
     * Guarded by a table-exists check, so an old scratch schema (or a
     * reset target before Phase 17) returns zeroes instead of throwing
     * (plan §26 #12).
     *
     * @return array{
     *   total:float, count:int, next_release_at:?string,
     *   undelivered:int, overdue:int, release_enabled:bool
     * }
     */
    public static function pendingSummaryForUser(int $userId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $empty = [
            'total'           => 0.0,
            'count'           => 0,
            'next_release_at' => null,
            'undelivered'     => 0,
            'overdue'         => 0,
            'release_enabled' => setting_bool('unilevel_release_enabled', true),
        ];

        if ($userId <= 0 || !self::tableExists($pdo)) {
            return $empty;
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
               FROM unilevel_awards a
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
            'release_enabled' => setting_bool('unilevel_release_enabled', true),
        ];
    }

    /** Does this connection's schema still carry the Phase 17 table? */
    private static function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['unilevel_awards']);

        return (int) $stmt->fetchColumn() > 0;
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
                       p.name           AS product_name,
                       p.sku            AS product_sku,
                       o.order_no       AS order_no,
                       o.status         AS order_status,
                       o.delivered_at   AS order_delivered_at,
                       o.completed_at   AS order_completed_at,
                       l.balance_after      AS wallet_balance_after,
                       l.withdrawable_after AS wallet_withdrawable_after
                  FROM unilevel_awards a
                  JOIN users u       ON u.id = a.user_id
                  JOIN users b       ON b.id = a.buyer_id
                  JOIN products p    ON p.id = a.product_id
                  JOIN shop_orders o ON o.id = a.order_id
                  LEFT JOIN ewallet_ledger l ON l.id = a.ledger_id';
    }
}
