<?php
declare(strict_types=1);

/* ==============================================================
 *  Report — read-only aggregate queries for the Phase 15
 *  dashboards and reports (plan §7 Phase 15, §6.8)
 *
 *  Every method here is a SELECT; nothing writes and nothing
 *  repairs. The services own the shape and the CSV assembly
 *  (ReportService) and the invariants (HealthService).
 *
 *  Money is returned as floats rounded to two decimals at the SQL
 *  boundary, matching every other read model in the app.
 * ============================================================== */

final class Report
{
    /**
     * Order statuses that count as realised sales.
     *
     * `cancelled` is deliberately absent: refunds are deferred, so a
     * paid-then-cancelled order must not inflate revenue. Everything
     * from `paid` onward has money received.
     */
    public const REVENUE_STATUSES = ['paid', 'packing', 'ready_to_ship', 'shipped', 'delivered', 'completed'];

    /** SQL list for the statuses above, already quoted. */
    private static function revenueIn(): string
    {
        return "'" . implode("', '", self::REVENUE_STATUSES) . "'";
    }

    /**
     * Sales inside a paid-at window (inclusive start, exclusive end).
     *
     * @return array{orders:int, amount:float, average:float}
     */
    public static function paidBetween(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS amount
               FROM shop_orders
              WHERE status IN (' . self::revenueIn() . ')
                AND paid_at >= ? AND paid_at < ?'
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        return self::moneyRow($stmt->fetch() ?: []);
    }

    /**
     * Sales paid today (Asia/Manila; the connection's day).
     *
     * @return array{orders:int, amount:float, average:float}
     */
    public static function salesToday(?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->query(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS amount
               FROM shop_orders
              WHERE status IN (' . self::revenueIn() . ')
                AND paid_at >= CURDATE() AND paid_at < CURDATE() + INTERVAL 1 DAY'
        );

        return self::moneyRow($stmt->fetch() ?: []);
    }

    /**
     * Sales paid in the last 30 days.
     *
     * @return array{orders:int, amount:float, average:float}
     */
    public static function salesLast30(?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->query(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS amount
               FROM shop_orders
              WHERE status IN (' . self::revenueIn() . ')
                AND paid_at >= NOW() - INTERVAL 30 DAY AND paid_at <= NOW()'
        );

        return self::moneyRow($stmt->fetch() ?: []);
    }

    /**
     * All-time realised sales.
     *
     * @return array{orders:int, amount:float, average:float}
     */
    public static function salesAllTime(?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->query(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS amount
               FROM shop_orders
              WHERE status IN (' . self::revenueIn() . ')'
        );

        return self::moneyRow($stmt->fetch() ?: []);
    }

    /**
     * One row per day in the window, zero-filled, oldest first.
     *
     * @return list<array{day:string, orders:int, amount:float}>
     */
    public static function salesByDay(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            'SELECT DATE(paid_at) AS day, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS amount
               FROM shop_orders
              WHERE status IN (' . self::revenueIn() . ')
                AND paid_at >= ? AND paid_at < ?
              GROUP BY DATE(paid_at)
              ORDER BY day'
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $found = [];

        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = [
                'orders' => (int) $row['orders'],
                'amount' => round((float) $row['amount'], 2),
            ];
        }

        $days = [];
        $cursor = strtotime($from . ' 00:00:00');
        $end    = strtotime($to . ' 00:00:00');

        while ($cursor !== false && $end !== false && $cursor <= $end) {
            $day = date('Y-m-d', $cursor);
            $days[] = [
                'day'    => $day,
                'orders' => (int) ($found[$day]['orders'] ?? 0),
                'amount' => (float) ($found[$day]['amount'] ?? 0),
            ];
            $cursor = strtotime('+1 day', $cursor);
        }

        return $days;
    }

    /**
     * Counts and values per order status, in the plan's lifecycle order.
     *
     * @return list<array{status:string, count:int, amount:float}>
     */
    public static function ordersByStatus(): array
    {
        $order = [
            'pending', 'payment_review', 'payment_failed', 'paid', 'packing',
            'ready_to_ship', 'shipped', 'delivered', 'completed', 'cancelled',
        ];

        $rows = [];

        foreach (db()->query(
            'SELECT status, COUNT(*) AS count, COALESCE(SUM(total), 0) AS amount
               FROM shop_orders
              GROUP BY status'
        )->fetchAll() as $row) {
            $rows[(string) $row['status']] = [
                'status' => (string) $row['status'],
                'count'  => (int) $row['count'],
                'amount' => round((float) $row['amount'], 2),
            ];
        }

        $ordered = [];

        foreach ($order as $status) {
            $ordered[] = $rows[$status] ?? ['status' => $status, 'count' => 0, 'amount' => 0.0];
            unset($rows[$status]);
        }

        /* A status added later still shows up rather than disappearing. */
        foreach ($rows as $row) {
            $ordered[] = $row;
        }

        return $ordered;
    }

    /**
     * Open orders (anything from payment_review through shipped).
     */
    public static function openOrders(?PDO $pdo = null): int
    {
        $pdo ??= db();

        return (int) $pdo->query(
            "SELECT COUNT(*) FROM shop_orders
              WHERE status IN ('payment_review', 'paid', 'packing', 'ready_to_ship', 'shipped')"
        )->fetchColumn();
    }

    /**
     * Best sellers in a paid-at window.
     *
     * @return list<array{name:string, sku:?string, units:int, amount:float}>
     */
    public static function topProducts(string $from, string $to, int $limit = 8, ?PDO $pdo = null): array
    {
        $pdo   ??= db();
        $limit   = max(1, min(50, $limit));

        $stmt = $pdo->prepare(
            'SELECT oi.product_name AS name, oi.product_sku AS sku,
                    SUM(oi.quantity) AS units, COALESCE(SUM(oi.line_total), 0) AS amount
               FROM shop_order_items oi
               JOIN shop_orders o ON o.id = oi.order_id
              WHERE o.status IN (' . self::revenueIn() . ')
                AND o.paid_at >= ? AND o.paid_at < ?
              GROUP BY oi.product_name, oi.product_sku
              ORDER BY units DESC, amount DESC, oi.product_name
              LIMIT ' . $limit
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'name'   => (string) $row['name'],
                'sku'    => $row['sku'] === null ? null : (string) $row['sku'],
                'units'  => (int) $row['units'],
                'amount' => round((float) $row['amount'], 2),
            ];
        }

        return $rows;
    }

    /**
     * Paid sales per method in a window (for the sales card breakdown).
     *
     * @return list<array{method:string, orders:int, amount:float}>
     */
    public static function salesByMethod(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            'SELECT payment_method AS method, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS amount
               FROM shop_orders
              WHERE status IN (' . self::revenueIn() . ')
                AND paid_at >= ? AND paid_at < ?
              GROUP BY payment_method
              ORDER BY amount DESC'
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'method' => (string) $row['method'],
                'orders' => (int) $row['orders'],
                'amount' => round((float) $row['amount'], 2),
            ];
        }

        return $rows;
    }

    /* ----------------------------------------------------------
     *  Commissions
     * ---------------------------------------------------------- */

    /**
     * Credited awards grouped by level and type, plus the skipped
     * count per level (plan §6.4: a skipped level is an audit note,
     * never money).
     *
     * @return list<array{level:int, type:string, count:int, amount:float, skipped:int}>
     */
    public static function commissionsByLevel(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            "SELECT level, type,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped
               FROM commissions
              WHERE created_at >= ? AND created_at < ?
              GROUP BY level, type
              ORDER BY level, type"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'level'   => (int) $row['level'],
                'type'    => (string) $row['type'],
                'count'   => (int) $row['credited'],
                'amount'  => round((float) $row['amount'], 2),
                'skipped' => (int) $row['skipped'],
            ];
        }

        return $rows;
    }

    /**
     * Commission counters for a window: awarded money, awards, skipped,
     * distinct recipients and the all-time paid total.
     *
     * @return array{
     *   amount:float, count:int, skipped:int, recipients:int,
     *   all_time:float, this_month:float
     * }
     */
    public static function commissionSummary(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COUNT(CASE WHEN status = 'skipped'  THEN 1 END) AS skipped,
                    COUNT(DISTINCT CASE WHEN status = 'credited' THEN user_id END) AS recipients
               FROM commissions
              WHERE created_at >= ? AND created_at < ?"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $row = $stmt->fetch() ?: [];

        $totals = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS all_time,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month
               FROM commissions"
        )->fetch() ?: [];

        return [
            'amount'     => round((float) ($row['amount'] ?? 0), 2),
            'count'      => (int) ($row['credited'] ?? 0),
            'skipped'    => (int) ($row['skipped'] ?? 0),
            'recipients' => (int) ($row['recipients'] ?? 0),
            'all_time'   => round((float) ($totals['all_time'] ?? 0), 2),
            'this_month' => round((float) ($totals['this_month'] ?? 0), 2),
        ];
    }

    /* ----------------------------------------------------------
     *  Repeat-purchase bonus (Phase 17D)
     *
     *  The window is `unilevel_awards.created_at`, the same key the
     *  admin ledger filters use, so `/admin/reports?from=…&to=…` and
     *  `/admin/commissions?ledger=repeat&from=…` always agree.
     * ---------------------------------------------------------- */

    /**
     * Repeat-purchase counters for a window: credited money, awards,
     * skipped and voided decisions, distinct recipients and the
     * unreturned shortfall — plus the escrow figures (pending release
     * and cancelled-before-release) and all-time / this-month totals.
     *
     * `amount` / `count` remain released money (the wallet was credited
     * at release); `pending_amount` / `pending_count` are earned but not
     * in a wallet, and `cancelled_count` was never paid at all. A
     * `skipped` row is an audit note, never money (plan §8 Rule 5,
     * §19.6).
     *
     * @return array{
     *   amount:float, count:int, skipped:int, voided:int, unrecovered:float,
     *   recipients:int, all_time:float, this_month:float,
     *   pending_amount:float, pending_count:int, cancelled_count:int
     * }
     */
    public static function unilevelSummary(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COUNT(CASE WHEN status = 'skipped'  THEN 1 END) AS skipped,
                    COUNT(CASE WHEN status = 'voided'   THEN 1 END) AS voided,
                    COALESCE(SUM(CASE WHEN status = 'voided'
                                      THEN amount - reversed_amount ELSE 0 END), 0) AS unrecovered,
                    COUNT(DISTINCT CASE WHEN status = 'credited' THEN user_id END) AS recipients,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_count,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled_count
               FROM unilevel_awards
              WHERE created_at >= ? AND created_at < ?"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $row = $stmt->fetch() ?: [];

        $totals = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS all_time,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month
               FROM unilevel_awards"
        )->fetch() ?: [];

        return [
            'amount'          => round((float) ($row['amount'] ?? 0), 2),
            'count'           => (int) ($row['credited'] ?? 0),
            'skipped'         => (int) ($row['skipped'] ?? 0),
            'voided'          => (int) ($row['voided'] ?? 0),
            'unrecovered'     => round((float) ($row['unrecovered'] ?? 0), 2),
            'recipients'      => (int) ($row['recipients'] ?? 0),
            'all_time'        => round((float) ($totals['all_time'] ?? 0), 2),
            'this_month'      => round((float) ($totals['this_month'] ?? 0), 2),
            'pending_amount'  => round((float) ($row['pending_amount'] ?? 0), 2),
            'pending_count'   => (int) ($row['pending_count'] ?? 0),
            'cancelled_count' => (int) ($row['cancelled_count'] ?? 0),
        ];
    }

    /**
     * Credited awards per ladder level (1 … 10), skips included, with
     * the pending escrow figures beside the released ones.
     *
     * @return list<array{
     *   level:int, count:int, amount:float, skipped:int,
     *   pending_count:int, pending_amount:float
     * }>
     */
    public static function unilevelByLevel(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            "SELECT level,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_count,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount
               FROM unilevel_awards
              WHERE created_at >= ? AND created_at < ?
              GROUP BY level
              ORDER BY level"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'level'          => (int) $row['level'],
                'count'          => (int) $row['credited'],
                'amount'         => round((float) $row['amount'], 2),
                'skipped'        => (int) $row['skipped'],
                'pending_count'  => (int) $row['pending_count'],
                'pending_amount' => round((float) $row['pending_amount'], 2),
            ];
        }

        return $rows;
    }

    /**
     * Repeat-purchase money per product, biggest first, with the pending
     * escrow figures beside the released ones.
     *
     * @return list<array{
     *   product_id:int, product:string, count:int, amount:float, skipped:int,
     *   pending_count:int, pending_amount:float
     * }>
     */
    public static function unilevelByProduct(string $from, string $to, int $limit = 8, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $limit   = max(1, min(50, $limit));

        $stmt = $pdo->prepare(
            "SELECT a.product_id, p.name AS product,
                    COUNT(CASE WHEN a.status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN a.status = 'skipped' THEN 1 END) AS skipped,
                    COUNT(CASE WHEN a.status = 'pending' THEN 1 END) AS pending_count,
                    COALESCE(SUM(CASE WHEN a.status = 'pending' THEN a.amount ELSE 0 END), 0) AS pending_amount
               FROM unilevel_awards a
               JOIN products p ON p.id = a.product_id
              WHERE a.created_at >= ? AND a.created_at < ?
              GROUP BY a.product_id, p.name
              ORDER BY amount DESC, credited DESC, p.name ASC
              LIMIT " . $limit
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'product_id'     => (int) $row['product_id'],
                'product'        => (string) $row['product'],
                'count'          => (int) $row['credited'],
                'amount'         => round((float) $row['amount'], 2),
                'skipped'        => (int) $row['skipped'],
                'pending_count'  => (int) $row['pending_count'],
                'pending_amount' => round((float) $row['pending_amount'], 2),
            ];
        }

        return $rows;
    }

    /**
     * Closed months, newest first — the period history card.
     *
     * `unilevel_periods` is written only by the close, so a month that
     * is not listed has either never been closed or does not exist yet
     * (the engine's live fallback covers the gap, plan §12.7).
     *
     * @return list<array<string, mixed>>
     */
    public static function unilevelByPeriod(int $limit = 6, ?PDO $pdo = null): array
    {
        return UnilevelCycle::periods($limit, $pdo);
    }

    /* ----------------------------------------------------------
     *  Royalty bonus (Phase 19D)
     *
     *  Royalty is awarded once per closed calendar month, so a window
     *  is read as whole award **periods**: every `period` whose
     *  `YYYY-MM` falls inside the window's months. The month is the
     *  unit of the ledger, so a window that starts mid-month still
     *  reads that period in full — there is no pro-rata.
     * ---------------------------------------------------------- */

    /**
     * Royalty counters for a window of award periods.
     *
     * `awarded` is paid + pending (the money the months awarded);
     * `paid` is what reached a wallet; `skipped` rows were judged and
     * paid nothing (plan §13.3). `average` is per paid award.
     * `paid_this_month` ignores the window: it is the money credited
     * during the current calendar month, the dashboard tile's
     * definition (plan §8 Rule 10).
     *
     * `payout_ratio` is paid royalty as a percentage of the paid sales
     * in the same date window, one decimal (plan §17 risk 1).
     *
     * @return array{
     *   paid:float, pending:float, awarded:float, paid_rows:int, pending_rows:int,
     *   recipients:int, paid_recipients:int, skipped:int, average:float,
     *   periods:int, paid_sales:float, payout_ratio:float, paid_this_month:float
     * }
     */
    public static function royaltySummary(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        [$start, $end] = self::royaltyMonths($from, $to);

        $stmt = $pdo->prepare(
            "SELECT COUNT(CASE WHEN status = 'paid'    THEN 1 END) AS paid_rows,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_rows,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped,
                    COALESCE(SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN status IN ('paid','pending') THEN amount ELSE 0 END), 0) AS awarded,
                    COUNT(DISTINCT CASE WHEN status IN ('paid','pending') THEN user_id END) AS recipients,
                    COUNT(DISTINCT CASE WHEN status = 'paid' THEN user_id END) AS paid_recipients
               FROM royalty_awards
              WHERE period >= ? AND period <= ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch() ?: [];

        $periods = $pdo->prepare('SELECT COUNT(*) FROM royalty_periods WHERE period >= ? AND period <= ?');
        $periods->execute([$start, $end]);

        /* Independent of the window: the dashboard tile's "this month"
         * is the money credited in the current calendar month. */
        $paidThisMonth = round((float) $pdo->query(
            "SELECT COALESCE(SUM(amount), 0)
               FROM royalty_awards
              WHERE status = 'paid'
                AND credited_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        )->fetchColumn(), 2);

        $paidRows = (int) ($row['paid_rows'] ?? 0);
        $paid     = round((float) ($row['paid'] ?? 0), 2);
        $sales    = self::paidBetween($from, $to, $pdo)['amount'];

        return [
            'paid'            => $paid,
            'pending'         => round((float) ($row['pending'] ?? 0), 2),
            'awarded'         => round((float) ($row['awarded'] ?? 0), 2),
            'paid_rows'       => $paidRows,
            'pending_rows'    => (int) ($row['pending_rows'] ?? 0),
            'recipients'      => (int) ($row['recipients'] ?? 0),
            'paid_recipients' => (int) ($row['paid_recipients'] ?? 0),
            'skipped'         => (int) ($row['skipped'] ?? 0),
            'average'         => $paidRows === 0 ? 0.0 : round($paid / $paidRows, 2),
            'periods'         => (int) $periods->fetchColumn(),
            'paid_sales'      => $sales,
            'payout_ratio'    => $sales <= 0.0 ? 0.0 : round($paid * 100 / $sales, 1),
            'paid_this_month' => $paidThisMonth,
        ];
    }

    /**
     * Royalty money per paying rank for a window.
     *
     * Every paying rank is listed, zero-filled: a rank that paid
     * nothing in this window is information (plan §17 risk 2).
     *
     * @return list<array{
     *   rank:int, name:string, recipients:int, paid_recipients:int,
     *   paid:float, pending:float, skipped:int, average:float
     * }>
     */
    public static function royaltyByRank(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        [$start, $end] = self::royaltyMonths($from, $to);

        $stmt = $pdo->prepare(
            "SELECT rank_level,
                    COUNT(DISTINCT CASE WHEN status IN ('paid','pending') THEN user_id END) AS recipients,
                    COUNT(DISTINCT CASE WHEN status = 'paid' THEN user_id END) AS paid_recipients,
                    COUNT(CASE WHEN status = 'paid'    THEN 1 END) AS paid_rows,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped,
                    COALESCE(SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending
               FROM royalty_awards
              WHERE period >= ? AND period <= ?
              GROUP BY rank_level"
        );
        $stmt->execute([$start, $end]);

        $found = [];

        foreach ($stmt->fetchAll() as $row) {
            $found[(int) $row['rank_level']] = $row;
        }

        $rows = [];

        foreach ([RoyaltyRank::DIRECTOR, RoyaltyRank::MANAGER, RoyaltyRank::SUPERVISOR] as $rank) {
            $row      = $found[$rank] ?? [];
            $paidRows = (int) ($row['paid_rows'] ?? 0);
            $paid     = round((float) ($row['paid'] ?? 0), 2);

            $rows[] = [
                'rank'            => $rank,
                'name'            => RoyaltyRank::NAMES[$rank],
                'recipients'      => (int) ($row['recipients'] ?? 0),
                'paid_recipients' => (int) ($row['paid_recipients'] ?? 0),
                'paid'            => $paid,
                'pending'         => round((float) ($row['pending'] ?? 0), 2),
                'skipped'         => (int) ($row['skipped'] ?? 0),
                'average'         => $paidRows === 0 ? 0.0 : round($paid / $paidRows, 2),
            ];
        }

        return $rows;
    }

    /**
     * The closed-month history, newest first — the royalty period card.
     *
     * @return list<array{
     *   period:string, status:string, award_count:int, paid_count:int,
     *   skipped_count:int, award_total:float, paid_total:float
     * }>
     */
    public static function royaltyByPeriod(int $limit = 6, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(120, $limit));

        $rows = $pdo->query(
            'SELECT period, status, award_count, paid_count, skipped_count, award_total, paid_total
               FROM royalty_periods
              ORDER BY period DESC
              LIMIT ' . $limit
        )->fetchAll();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'period'        => (string) $row['period'],
                'status'        => (string) $row['status'],
                'award_count'   => (int) $row['award_count'],
                'paid_count'    => (int) $row['paid_count'],
                'skipped_count' => (int) $row['skipped_count'],
                'award_total'   => round((float) $row['award_total'], 2),
                'paid_total'    => round((float) $row['paid_total'], 2),
            ];
        }

        return $out;
    }

    /**
     * Royalty paid as a percentage of the paid sales in the same
     * window, one decimal (plan §17 risk 1).
     *
     * @return array{royalty:float, sales:float, ratio:float}
     */
    public static function royaltyPayoutRatio(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        [$start, $end] = self::royaltyMonths($from, $to);

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
               FROM royalty_awards
              WHERE status = 'paid' AND period >= ? AND period <= ?"
        );
        $stmt->execute([$start, $end]);
        $royalty = round((float) $stmt->fetchColumn(), 2);

        $sales = self::paidBetween($from, $to, $pdo)['amount'];

        return [
            'royalty' => $royalty,
            'sales'   => $sales,
            'ratio'   => $sales <= 0.0 ? 0.0 : round($royalty * 100 / $sales, 1),
        ];
    }

    /* ----------------------------------------------------------
     *  Team Harvest bonus (Phase 20E)
     *
     *  Team Harvest has no month and no order (D-08), so a window is
     *  read on `team_harvest_awards.credited_at` — the instant a level
     *  was decided. The ledger tab filters on the same key, so
     *  `/admin/reports?from=…&to=…` and
     *  `/admin/commissions?ledger=team_harvest&from=…` always agree.
     *
     *  `credited` is money in a wallet and `baseline` is money recorded
     *  at go-live and never paid (D-07); the two are reported apart in
     *  every method here (Rule 4 of §12.7).
     * ---------------------------------------------------------- */

    /**
     * Team Harvest counters for a window of award decisions.
     *
     * `all_time` and `this_month` are independent of the window: the
     * dashboard tile and the ledger header read them.
     *
     * @return array{
     *   credited:float, count:int, recipients:int, baseline:float,
     *   baseline_rows:int, awards:int, average:float,
     *   all_time:float, this_month:float
     * }
     */
    public static function teamHarvestSummary(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_rows,
                    COUNT(DISTINCT CASE WHEN status = 'credited' THEN user_id END) AS recipients,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline_rows,
                    COUNT(*) AS awards
               FROM team_harvest_awards
              WHERE credited_at >= ? AND credited_at < ?"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $row = $stmt->fetch() ?: [];

        $totals = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS all_time,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND credited_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month
               FROM team_harvest_awards"
        )->fetch() ?: [];

        $creditedRows = (int) ($row['credited_rows'] ?? 0);
        $credited     = round((float) ($row['credited'] ?? 0), 2);

        return [
            'credited'      => $credited,
            'count'         => $creditedRows,
            'recipients'    => (int) ($row['recipients'] ?? 0),
            'baseline'      => round((float) ($row['baseline'] ?? 0), 2),
            'baseline_rows' => (int) ($row['baseline_rows'] ?? 0),
            'awards'        => (int) ($row['awards'] ?? 0),
            'average'       => $creditedRows === 0 ? 0.0 : round($credited / $creditedRows, 2),
            'all_time'      => round((float) ($totals['all_time'] ?? 0), 2),
            'this_month'    => round((float) ($totals['this_month'] ?? 0), 2),
        ];
    }

    /**
     * The ledger's headline counters in **one** query: everything the
     * `/admin/commissions?ledger=team_harvest` tiles need — credited money
     * and rows, recipients, the go-live baseline (recorded, never paid) and
     * the money credited in the current calendar month.
     *
     * The page asks once so a twenty-five-row page stays inside its
     * statement budget (P20E-T18); the windowed `teamHarvestSummary()`
     * above is the reports desk's read.
     *
     * @return array{credited_total:float, credited_rows:int, recipients:int,
     *               baseline_total:float, baseline_rows:int, awards:int, this_month:float}
     */
    public static function teamHarvestTotals(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $row = $pdo->query(
            "SELECT COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_rows,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(DISTINCT CASE WHEN status = 'credited' THEN user_id END) AS recipients,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline_rows,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND credited_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month,
                    COUNT(*) AS awards
               FROM team_harvest_awards"
        )->fetch() ?: [];

        return [
            'credited_total' => round((float) ($row['credited_total'] ?? 0), 2),
            'credited_rows'  => (int) ($row['credited_rows'] ?? 0),
            'recipients'     => (int) ($row['recipients'] ?? 0),
            'baseline_total' => round((float) ($row['baseline_total'] ?? 0), 2),
            'baseline_rows'  => (int) ($row['baseline_rows'] ?? 0),
            'awards'         => (int) ($row['awards'] ?? 0),
            'this_month'     => round((float) ($row['this_month'] ?? 0), 2),
        ];
    }

    /**
     * The ladder's ten levels with their windowed decisions.
     *
     * Every level is listed, zero-filled, with the configured amount and
     * structural threshold beside the counts: a level nobody completed is
     * the shape of the plan, not missing data (Rule 11 of §9).
     *
     * @return list<array{
     *   level:int, amount:float, threshold:int, slots:int,
     *   credited:int, credited_total:float, baseline:int, baseline_total:float
     * }>
     */
    public static function teamHarvestByLevel(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT level,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total
               FROM team_harvest_awards
              WHERE credited_at >= ? AND credited_at < ?
              GROUP BY level"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $found = [];

        foreach ($stmt->fetchAll() as $row) {
            $found[(int) $row['level']] = $row;
        }

        $amounts = TeamHarvestLevel::amounts($pdo);
        $rows    = [];

        for ($level = TeamHarvestLevel::LEVEL_MIN; $level <= TeamHarvestLevel::LEVEL_MAX; $level++) {
            $row = $found[$level] ?? [];

            $rows[] = [
                'level'          => $level,
                'amount'         => round((float) ($amounts[$level] ?? 0), 2),
                'threshold'      => TeamHarvestLevel::threshold($level),
                'slots'          => TeamHarvestLevel::slots($level),
                'credited'       => (int) ($row['credited'] ?? 0),
                'credited_total' => round((float) ($row['credited_total'] ?? 0), 2),
                'baseline'       => (int) ($row['baseline'] ?? 0),
                'baseline_total' => round((float) ($row['baseline_total'] ?? 0), 2),
            ];
        }

        return $rows;
    }

    /**
     * Team Harvest decisions by calendar month of the decision, newest
     * first — the report card's history strip.
     *
     * @return list<array{month:string, awards:int, credited:int, credited_total:float,
     *                    baseline:int, baseline_total:float}>
     */
    public static function teamHarvestByMonth(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(credited_at, '%Y-%m') AS month,
                    COUNT(*) AS awards,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total
               FROM team_harvest_awards
              WHERE credited_at >= ? AND credited_at < ?
              GROUP BY month
              ORDER BY month DESC"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'month'          => (string) $row['month'],
                'awards'         => (int) $row['awards'],
                'credited'       => (int) $row['credited'],
                'credited_total' => round((float) $row['credited_total'], 2),
                'baseline'       => (int) $row['baseline'],
                'baseline_total' => round((float) $row['baseline_total'], 2),
            ];
        }

        return $rows;
    }

    /**
     * General Harvest bonus (Phase 21E, plan §9).
     *
     * General Harvest has no month and no order (D-08): a window is read on
     * `general_harvest_awards.credited_at` — the instant a block was decided.
     * The ledger tab filters on the same key, so
     * `/admin/reports?from=…&to=…` and
     * `/admin/commissions?ledger=general_harvest&from=…` always agree.
     *
     * `credited` is money in a wallet and `baseline` is money recorded at
     * go-live and never paid (D-06); the two are reported apart in every
     * method here (Rule 4 of §12.7).
     * ---------------------------------------------------------- */

    /**
     * General Harvest counters for a window of award decisions.
     *
     * `all_time` and `this_month` are independent of the window: the
     * dashboard tile and the ledger header read them.
     *
     * @return array{
     *   credited:float, count:int, recipients:int, baseline:float,
     *   baseline_rows:int, awards:int, average:float,
     *   all_time:float, this_month:float
     * }
     */
    public static function generalHarvestSummary(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_rows,
                    COUNT(DISTINCT CASE WHEN status = 'credited' THEN user_id END) AS recipients,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline_rows,
                    COUNT(*) AS awards
               FROM general_harvest_awards
              WHERE credited_at >= ? AND credited_at < ?"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $row = $stmt->fetch() ?: [];

        $totals = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS all_time,
                        COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND credited_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month
               FROM general_harvest_awards"
        )->fetch() ?: [];

        $creditedRows = (int) ($row['credited_rows'] ?? 0);
        $credited     = round((float) ($row['credited'] ?? 0), 2);

        return [
            'credited'      => $credited,
            'count'         => $creditedRows,
            'recipients'    => (int) ($row['recipients'] ?? 0),
            'baseline'      => round((float) ($row['baseline'] ?? 0), 2),
            'baseline_rows' => (int) ($row['baseline_rows'] ?? 0),
            'awards'        => (int) ($row['awards'] ?? 0),
            'average'       => $creditedRows === 0 ? 0.0 : round($credited / $creditedRows, 2),
            'all_time'      => round((float) ($totals['all_time'] ?? 0), 2),
            'this_month'    => round((float) ($totals['this_month'] ?? 0), 2),
        ];
    }

    /**
     * General Harvest headline counters in one query: everything the
     * `/admin/commissions?ledger=general_harvest` tiles need — credited
     * money and rows, recipients, the go-live baseline (recorded, never
     * paid) and the money credited in the current calendar month.
     *
     * @return array{credited_total:float, credited_rows:int, recipients:int,
     *               baseline_total:float, baseline_rows:int, awards:int, this_month:float}
     */
    public static function generalHarvestTotals(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $row = $pdo->query(
            "SELECT COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_rows,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(DISTINCT CASE WHEN status = 'credited' THEN user_id END) AS recipients,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline_rows,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND credited_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month,
                    COUNT(*) AS awards
               FROM general_harvest_awards"
        )->fetch() ?: [];

        return [
            'credited_total' => round((float) ($row['credited_total'] ?? 0), 2),
            'credited_rows'  => (int) ($row['credited_rows'] ?? 0),
            'recipients'     => (int) ($row['recipients'] ?? 0),
            'baseline_total' => round((float) ($row['baseline_total'] ?? 0), 2),
            'baseline_rows'  => (int) ($row['baseline_rows'] ?? 0),
            'awards'         => (int) ($row['awards'] ?? 0),
            'this_month'     => round((float) ($row['this_month'] ?? 0), 2),
        ];
    }

    /**
     * The ladder's ten levels with their windowed decisions.
     *
     * Every level is listed, zero-filled, with the configured amount and
     * structural threshold beside the counts: a level nobody completed is
     * the shape of the plan, not missing data (Rule 11 of §9).
     *
     * @return list<array{
     *   level:int, amount:float, threshold:int, slots:int,
     *   credited:int, credited_total:float, baseline:int, baseline_total:float
     * }>
     */
    public static function generalHarvestByLevel(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT level,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total
               FROM general_harvest_awards
              WHERE credited_at >= ? AND credited_at < ?
              GROUP BY level"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $found = [];

        foreach ($stmt->fetchAll() as $row) {
            $found[(int) $row['level']] = $row;
        }

        $amounts = GeneralHarvestLevel::amounts($pdo);
        $rows    = [];

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $row = $found[$level] ?? [];

            $rows[] = [
                'level'          => $level,
                'amount'         => round((float) ($amounts[$level] ?? 0), 2),
                'threshold'      => GeneralHarvestLevel::threshold(0, $level),
                'slots'          => GeneralHarvestLevel::slots($level),
                'credited'       => (int) ($row['credited'] ?? 0),
                'credited_total' => round((float) ($row['credited_total'] ?? 0), 2),
                'baseline'       => (int) ($row['baseline'] ?? 0),
                'baseline_total' => round((float) ($row['baseline_total'] ?? 0), 2),
            ];
        }

        return $rows;
    }

    /**
     * General Harvest decisions by calendar month of the decision, newest
     * first — the report card's history strip.
     *
     * @return list<array{month:string, awards:int, credited:int, credited_total:float,
     *                    baseline:int, baseline_total:float}>
     */
    public static function generalHarvestByMonth(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(credited_at, '%Y-%m') AS month,
                    COUNT(*) AS awards,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total
               FROM general_harvest_awards
              WHERE credited_at >= ? AND credited_at < ?
              GROUP BY month
              ORDER BY month DESC"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'month'          => (string) $row['month'],
                'awards'         => (int) $row['awards'],
                'credited'       => (int) $row['credited'],
                'credited_total' => round((float) $row['credited_total'], 2),
                'baseline'       => (int) $row['baseline'],
                'baseline_total' => round((float) $row['baseline_total'], 2),
            ];
        }

        return $rows;
    }

    /**
     * General Harvest money per pay band — how much the stream pays to
     * each slice of the queue. The bands are the closed form's natural
     * breaks: 1–3, 4–12, 13–39, 40–120, 121–363, 364–1092, 1093–3279,
     * 3280–9840, 9841–29523, 29524–88572 and 88573+.
     *
     * @return list<array{
     *   band:string, from:int, to:int, nodes:int, paid:float,
     *   credited:int, baseline:int
     * }>
     */
    public static function generalHarvestBands(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        $n   = GeneralHarvestBoard::queueN($pdo)['n'];

        /* Band boundaries: (start of band, end of band, label). */
        $bands = [
            [1, 3,     'Positions #1–#3'],
            [4, 12,    'Positions #4–#12'],
            [13, 39,   'Positions #13–#39'],
            [40, 120,  'Positions #40–#120'],
            [121, 363, 'Positions #121–#363'],
            [364, 1092, 'Positions #364–#1,092'],
            [1093, 3279, 'Positions #1,093–#3,279'],
            [3280, 9840, 'Positions #3,280–#9,840'],
            [9841, 29523, 'Positions #9,841–#29,523'],
            [29524, 88572, 'Positions #29,524–#88,572'],
            [88573, null, 'Position #88,573 and beyond'],
        ];

        $amounts = GeneralHarvestLevel::amounts($pdo);
        $rows    = [];

        foreach ($bands as $band) {
            [$bandFrom, $bandTo, $label] = $band;
            $bandTo = $bandTo === null ? $n : min($bandTo, $n);

            if ($bandFrom > $n) {
                $rows[] = [
                    'band'   => $label,
                    'from'   => $bandFrom,
                    'to'     => $bandTo,
                    'nodes'  => 0,
                    'paid'   => 0.0,
                    'credited' => 0,
                    'baseline' => 0,
                ];
                continue;
            }

            $paid   = 0.0;
            $credited = 0;
            $baseline = 0;

            for ($q = $bandFrom - 1; $q < $bandTo; $q++) {
                $owner = GeneralHarvestBoard::ownerOf($q, $pdo);
                if ($owner === null) {
                    continue;
                }

                $paidLevels = GeneralHarvestAward::paidLevels($owner['user_id'], $pdo);
                $levelTotal = 0.0;

                foreach ($paidLevels as $level) {
                    $row = GeneralHarvestAward::findBySeqLevel($q, $level, $pdo);
                    if ($row === null) {
                        continue;
                    }

                    $levelTotal += (float) $row['amount'];
                    if ($row['status'] === GeneralHarvestAward::STATUS_CREDITED) {
                        $credited++;
                    } else {
                        $baseline++;
                    }
                }

                $paid += $levelTotal;
            }

            $rows[] = [
                'band'      => $label,
                'from'      => $bandFrom,
                'to'        => $bandTo,
                'nodes'     => $bandTo - $bandFrom + 1,
                'paid'      => round($paid, 2),
                'credited'  => $credited,
                'baseline'  => $baseline,
            ];
        }

        return $rows;
    }

    /**
     * The General Harvest board's own block — what the reports desk needs
     * beside the sales and commissions cards: the state strip, the
     * frontier, the backlog and the open liability.
     *
     * @return array{
     *   state:array<string, mixed>, frontier:list<array<string, mixed>>,
     *   backlog:array<string, mixed>, open:array<string, mixed>
     * }
     */
    public static function generalHarvestBoard(?PDO $pdo = null): array
    {
        $service = new GeneralHarvestService($pdo ?? db());

        return [
            'state'    => $service->state(),
            'frontier' => array_values(GeneralHarvestBoard::frontier($pdo)),
            'backlog'  => $service->backlog(),
            'open'     => $service->openLiability(),
        ];
    }

    /**
     * The queue's shape and its first positions — the queue card on
     * `/admin/members` and the queue block of the reports desk.
     *
     * The counters are `HarvestQueue::stats()`'s (one definition of the
     * position sequence); the award figures are read here so the card can
     * say how much of the queue has been paid without a second page.
     *
     * @return array{
     *   queued:int, positions:int, next:int, deepest:int, seeded:bool,
     *   baseline:?string, awards:int, credited_awards:int, baseline_awards:int,
     *   credited_total:float, baseline_total:float,
     *   rows:list<array{position:int, user_id:int, username:string, qualified_at:string,
     *                   teammates:int, baseline_n:int, source:string,
     *                   credited_levels:int, credited_total:float, baseline_levels:int}>
     * }
     */
    public static function teamHarvestQueue(int $limit = 10, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(50, $limit));
        $stats = (new HarvestQueue($pdo))->stats();

        $awards = $pdo->query(
            "SELECT COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_awards,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline_awards,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total
               FROM team_harvest_awards"
        )->fetch() ?: [];

        $rows = $pdo->query(
            "SELECT q.id AS position, q.user_id, q.qualified_at, q.teammates, q.baseline_n, q.source,
                    u.username,
                    COUNT(CASE WHEN a.status = 'credited' THEN 1 END) AS credited_levels,
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN a.status = 'baseline' THEN 1 END) AS baseline_levels
               FROM harvest_queue q
               JOIN users u ON u.id = q.user_id
               LEFT JOIN team_harvest_awards a ON a.user_id = q.user_id
              GROUP BY q.id, q.user_id, q.qualified_at, q.teammates, q.baseline_n, q.source, u.username
              ORDER BY q.id
              LIMIT " . $limit
        )->fetchAll();

        return [
            'queued'           => (int) $stats['queued'],
            'positions'        => (int) $stats['positions'],
            'next'             => (int) $stats['next'],
            'deepest'          => (int) $stats['deepest'],
            'seeded'           => (bool) $stats['seeded'],
            'baseline'         => $stats['baseline'],
            'awards'           => (int) $stats['awards'],
            'credited_awards'  => (int) ($awards['credited_awards'] ?? 0),
            'baseline_awards'  => (int) ($awards['baseline_awards'] ?? 0),
            'credited_total'   => round((float) ($awards['credited_total'] ?? 0), 2),
            'baseline_total'   => round((float) ($awards['baseline_total'] ?? 0), 2),
            'rows'             => array_map(static fn (array $row): array => [
                'position'         => (int) $row['position'],
                'user_id'          => (int) $row['user_id'],
                'username'         => (string) $row['username'],
                'qualified_at'     => (string) $row['qualified_at'],
                'teammates'        => (int) $row['teammates'],
                'baseline_n'       => (int) $row['baseline_n'],
                'source'           => (string) $row['source'],
                'credited_levels'  => (int) $row['credited_levels'],
                'credited_total'   => round((float) $row['credited_total'], 2),
                'baseline_levels'  => (int) $row['baseline_levels'],
            ], $rows),
        ];
    }

    /* ----------------------------------------------------------
     *  Wallets
     * ---------------------------------------------------------- */

    /**
     * The wallet float: what the company owes across both buckets.
     *
     * @return array{
     *   accounts:int, float:float, withdrawable:float,
     *   credited_today:float, debited_today:float, movements_today:int
     * }
     */
    public static function walletFloat(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $wallet = $pdo->query(
            'SELECT COUNT(*) AS accounts,
                    COALESCE(SUM(ewallet_balance), 0) AS float_total,
                    COALESCE(SUM(withdrawable_balance), 0) AS withdrawable
               FROM users'
        )->fetch() ?: [];

        $today = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credited,
                    COALESCE(SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END), 0) AS debited,
                    COUNT(*) AS movements
               FROM ewallet_ledger
              WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY"
        )->fetch() ?: [];

        return [
            'accounts'       => (int) ($wallet['accounts'] ?? 0),
            'float'          => round((float) ($wallet['float_total'] ?? 0), 2),
            'withdrawable'   => round((float) ($wallet['withdrawable'] ?? 0), 2),
            'credited_today' => round((float) ($today['credited'] ?? 0), 2),
            'debited_today'  => round((float) ($today['debited'] ?? 0), 2),
            'movements_today' => (int) ($today['movements'] ?? 0),
        ];
    }

    /**
     * Wallet statement rows across every account, oldest first.
     *
     * Used by the CSV export only; a screen uses the paginated
     * Wallet model. The join keeps the member's username beside the
     * movement so finance can reconcile offline.
     *
     * @return list<array<string, mixed>>
     */
    public static function walletRows(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            'SELECT l.id, l.created_at, u.id AS user_id, u.username,
                    l.type, l.amount, l.balance_after, l.withdrawable_after,
                    l.ref_type, l.ref_id, l.note
               FROM ewallet_ledger l
               JOIN users u ON u.id = l.user_id
              WHERE l.created_at >= ? AND l.created_at < ?
              ORDER BY l.id'
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Payouts
     * ---------------------------------------------------------- */

    /**
     * Withdrawal counters for a window, by status and by method.
     *
     * @return array{
     *   by_status: list<array{status:string, count:int, amount:float, fees:float}>,
     *   by_method: list<array{method:string, count:int, amount:float}>,
     *   held: float, held_requests: int,
     *   completed_amount: float, completed_fees: float
     * }
     */
    public static function payoutSummary(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            'SELECT status, COUNT(*) AS count,
                    COALESCE(SUM(amount), 0) AS amount,
                    COALESCE(SUM(service_fee_amount), 0) AS fees
               FROM payout_requests
              WHERE requested_at >= ? AND requested_at < ?
              GROUP BY status
              ORDER BY FIELD(status, \'pending\', \'approved\', \'completed\', \'rejected\')'
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $byStatus = [];

        foreach ($stmt->fetchAll() as $row) {
            $byStatus[] = [
                'status' => (string) $row['status'],
                'count'  => (int) $row['count'],
                'amount' => round((float) $row['amount'], 2),
                'fees'   => round((float) $row['fees'], 2),
            ];
        }

        $methodStmt = $pdo->prepare(
            'SELECT payout_method AS method, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount
               FROM payout_requests
              WHERE requested_at >= ? AND requested_at < ?
              GROUP BY payout_method
              ORDER BY amount DESC'
        );
        $methodStmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $byMethod = [];

        foreach ($methodStmt->fetchAll() as $row) {
            $byMethod[] = [
                'method' => (string) $row['method'],
                'count'  => (int) $row['count'],
                'amount' => round((float) $row['amount'], 2),
            ];
        }

        $held = $pdo->query(
            "SELECT COUNT(*) AS requests, COALESCE(SUM(amount), 0) AS amount
               FROM payout_requests
              WHERE status IN ('pending', 'approved')"
        )->fetch() ?: [];

        $completed = $pdo->query(
            "SELECT COALESCE(SUM(amount), 0) AS amount,
                    COALESCE(SUM(service_fee_amount), 0) AS fees
               FROM payout_requests
              WHERE status = 'completed'"
        )->fetch() ?: [];

        return [
            'by_status'        => $byStatus,
            'by_method'        => $byMethod,
            'held'             => round((float) ($held['amount'] ?? 0), 2),
            'held_requests'    => (int) ($held['requests'] ?? 0),
            'completed_amount' => round((float) ($completed['amount'] ?? 0), 2),
            'completed_fees'   => round((float) ($completed['fees'] ?? 0), 2),
        ];
    }

    /**
     * The full payout register for the CSV export.
     *
     * @return list<array<string, mixed>>
     */
    public static function payoutRows(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            'SELECT p.id, p.requested_at, p.processed_at, p.status, p.payout_method,
                    p.payout_account, p.amount, p.service_fee_pct, p.service_fee_amount,
                    p.usdt_rate, p.usdt_gas_fee, p.usdt_amount, p.admin_note,
                    u.username, u.full_name,
                    a.username AS processed_by_username
               FROM payout_requests p
               JOIN users u ON u.id = p.user_id
               LEFT JOIN users a ON a.id = p.processed_by
              WHERE p.requested_at >= ? AND p.requested_at < ?
              ORDER BY p.id'
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Registration codes
     * ---------------------------------------------------------- */

    /**
     * Registration-code revenue by package: issued, redeemed, open,
     * expired and the peso value each represents.
     *
     * @return list<array{
     *   package_id:int, package:string, price:float,
     *   issued:int, used:int, unused:int, expired:int,
     *   issued_value:float, redeemed_value:float
     * }>
     */
    public static function codeRevenue(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $rows = $pdo->query(
            "SELECT p.id AS package_id, p.name AS package, p.price,
                    COUNT(c.id) AS issued,
                    COUNT(CASE WHEN c.status = 'used'    THEN 1 END) AS used,
                    COUNT(CASE WHEN c.status = 'unused'  THEN 1 END) AS unused,
                    COUNT(CASE WHEN c.status = 'expired' THEN 1 END) AS expired,
                    COALESCE(SUM(c.price), 0) AS issued_value,
                    COALESCE(SUM(CASE WHEN c.status = 'used' THEN c.price ELSE 0 END), 0) AS redeemed_value
               FROM packages p
               LEFT JOIN reg_codes c ON c.package_id = p.id AND c.code_type = 'registration'
              GROUP BY p.id, p.name, p.price, p.sort_order
              ORDER BY p.sort_order, p.id"
        )->fetchAll();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'package_id'     => (int) $row['package_id'],
                'package'        => (string) $row['package'],
                'price'          => round((float) $row['price'], 2),
                'issued'         => (int) $row['issued'],
                'used'           => (int) $row['used'],
                'unused'         => (int) $row['unused'],
                'expired'        => (int) $row['expired'],
                'issued_value'   => round((float) $row['issued_value'], 2),
                'redeemed_value' => round((float) $row['redeemed_value'], 2),
            ];
        }

        return $out;
    }

    /**
     * Code rows for the CSV export.
     *
     * @return list<array<string, mixed>>
     */
    public static function codeRows(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            'SELECT c.id, c.code, c.price, c.status, c.created_at, c.used_at, c.expires_at,
                    p.name AS package, cb.username AS created_by, ub.username AS used_by
               FROM reg_codes c
               JOIN packages p ON p.id = c.package_id
               JOIN users cb ON cb.id = c.created_by
               LEFT JOIN users ub ON ub.id = c.used_by
              WHERE c.code_type = \'registration\'
                AND c.created_at >= ? AND c.created_at < ?
              ORDER BY c.id'
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Member / catalogue counters used by the dashboards
     * ---------------------------------------------------------- */

    /**
     * Member totals by member_status, plus the recently registered count.
     *
     * @return array{total:int, members:int, active:int, pending:int, suspended:int, new_30d:int}
     */
    public static function memberCounts(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COUNT(CASE WHEN role = 'member' THEN 1 END) AS members,
                    COUNT(CASE WHEN member_status = 'active'    THEN 1 END) AS active,
                    COUNT(CASE WHEN member_status = 'pending'   THEN 1 END) AS pending,
                    COUNT(CASE WHEN member_status = 'suspended' THEN 1 END) AS suspended,
                    COUNT(CASE WHEN created_at >= NOW() - INTERVAL 30 DAY THEN 1 END) AS new_30d
               FROM users"
        )->fetch() ?: [];

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'members'   => (int) ($row['members'] ?? 0),
            'active'    => (int) ($row['active'] ?? 0),
            'pending'   => (int) ($row['pending'] ?? 0),
            'suspended' => (int) ($row['suspended'] ?? 0),
            'new_30d'   => (int) ($row['new_30d'] ?? 0),
        ];
    }

    /* ----------------------------------------------------------
     *  Product binary bonus (Phase 22E)
     *
     *  The award window is `binary_awards.created_at`, the same key the
     *  admin ledger filters use, so `/admin/reports?from=…&to=…` and
     *  `/admin/commissions?ledger=binary&from=…` always agree. Points
     *  placed is read from `binary_volume` (kind 'own'/'team'), never
     *  from the cached node balances — the ledger is the source of
     *  truth (plan D-23). Points and money are shown side by side and
     *  never added together (plan Rule 4).
     * ---------------------------------------------------------- */

    /**
     * Product binary headline counters for a window of awards.
     *
     * `amount` / `count` are released money (in a wallet); `pending_amount` /
     * `pending_count` are earned but not in a wallet; `cancelled` was never
     * paid; `unrecovered` is the shortfall of a voided award after a
     * post-release reversal.
     *
     * @return array{
     *   amount:float, count:int, cancelled:int, cancelled_total:float,
     *   voided:int, voided_total:float, unrecovered:float, recipients:int,
     *   all_time:float, this_month:float, pending_amount:float,
     *   pending_count:int, self_total:float, pair_total:float,
     *   points_left:int, points_right:int
     * }
     */
    public static function binarySummary(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT COUNT(CASE WHEN status = 'credited' THEN 1 END) AS count,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_count,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled,
                    COALESCE(SUM(CASE WHEN status = 'cancelled' THEN amount ELSE 0 END), 0) AS cancelled_total,
                    COUNT(CASE WHEN status = 'voided' THEN 1 END) AS voided,
                    COALESCE(SUM(CASE WHEN status = 'voided' THEN amount ELSE 0 END), 0) AS voided_total,
                    COALESCE(SUM(CASE WHEN status = 'voided'
                                       THEN amount - reversed_amount ELSE 0 END), 0) AS unrecovered,
                    COUNT(DISTINCT CASE WHEN status IN ('credited','pending') THEN user_id END) AS recipients,
                    COALESCE(SUM(CASE WHEN kind = 'self'  AND status IN ('pending','credited') THEN amount ELSE 0 END), 0) AS self_total,
                    COALESCE(SUM(CASE WHEN kind = 'pair'  AND status IN ('pending','credited') THEN amount ELSE 0 END), 0) AS pair_total
               FROM binary_awards
              WHERE created_at >= ? AND created_at < ?"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $row = $stmt->fetch() ?: [];

        $totals = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS all_time,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month
               FROM binary_awards"
        )->fetch() ?: [];

        $points = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN side = 'left'  THEN points ELSE 0 END), 0) AS points_left,
                    COALESCE(SUM(CASE WHEN side = 'right' THEN points ELSE 0 END), 0) AS points_right
               FROM binary_volume
              WHERE kind IN ('own','team') AND created_at >= ? AND created_at < ?"
        );
        $points->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $pts = $points->fetch() ?: [];

        return [
            'amount'          => round((float) ($row['amount'] ?? 0), 2),
            'count'           => (int) ($row['count'] ?? 0),
            'cancelled'       => (int) ($row['cancelled'] ?? 0),
            'cancelled_total' => round((float) ($row['cancelled_total'] ?? 0), 2),
            'voided'          => (int) ($row['voided'] ?? 0),
            'voided_total'    => round((float) ($row['voided_total'] ?? 0), 2),
            'unrecovered'     => round((float) ($row['unrecovered'] ?? 0), 2),
            'recipients'      => (int) ($row['recipients'] ?? 0),
            'all_time'        => round((float) ($totals['all_time'] ?? 0), 2),
            'this_month'      => round((float) ($totals['this_month'] ?? 0), 2),
            'pending_amount'  => round((float) ($row['pending_amount'] ?? 0), 2),
            'pending_count'   => (int) ($row['pending_count'] ?? 0),
            'self_total'      => round((float) ($row['self_total'] ?? 0), 2),
            'pair_total'      => round((float) ($row['pair_total'] ?? 0), 2),
            'points_left'     => (int) ($pts['points_left'] ?? 0),
            'points_right'    => (int) ($pts['points_right'] ?? 0),
        ];
    }

    /**
     * Product binary awards and points placed per day in a window,
     * zero-filled, oldest first.
     *
     * @return list<array{day:string, awards:int, amount:float, pending_amount:float, points_left:int, points_right:int}>
     */
    public static function binaryByDay(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT DATE(a.created_at) AS day,
                    COUNT(*) AS awards,
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS amount,
                    COALESCE(SUM(CASE WHEN a.status = 'pending' THEN a.amount ELSE 0 END), 0) AS pending_amount
               FROM binary_awards a
              WHERE a.created_at >= ? AND a.created_at < ?
              GROUP BY day
              ORDER BY day"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = [
                'awards'          => (int) $row['awards'],
                'amount'          => round((float) $row['amount'], 2),
                'pending_amount'  => round((float) $row['pending_amount'], 2),
                'points_left'     => 0,
                'points_right'    => 0,
            ];
        }

        $points = $pdo->prepare(
            "SELECT DATE(v.created_at) AS day,
                    COALESCE(SUM(CASE WHEN v.side = 'left'  THEN v.points ELSE 0 END), 0) AS points_left,
                    COALESCE(SUM(CASE WHEN v.side = 'right' THEN v.points ELSE 0 END), 0) AS points_right
               FROM binary_volume v
              WHERE v.kind IN ('own','team') AND v.created_at >= ? AND v.created_at < ?
              GROUP BY day"
        );
        $points->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        foreach ($points->fetchAll() as $row) {
            $day = (string) $row['day'];
            if (isset($found[$day])) {
                $found[$day]['points_left']  = (int) $row['points_left'];
                $found[$day]['points_right'] = (int) $row['points_right'];
            }
        }

        $days = [];
        $cursor = strtotime($from . ' 00:00:00');
        $end    = strtotime($to . ' 00:00:00');

        while ($cursor !== false && $end !== false && $cursor <= $end) {
            $day = date('Y-m-d', $cursor);
            $days[] = array_merge([
                'day'          => $day,
                'awards'       => 0,
                'amount'       => 0.0,
                'pending_amount'=> 0.0,
                'points_left'  => 0,
                'points_right' => 0,
            ], $found[$day] ?? []);
            $cursor = strtotime('+1 day', $cursor);
        }

        return $days;
    }

    /**
     * Product binary awards split by kind (self vs pair) in a window.
     *
     * @return list<array{kind:string, count:int, amount:float, recipients:int, credited:int, pending:int}>
     */
    public static function binaryByKind(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT kind,
                    COUNT(*) AS count,
                    COALESCE(SUM(amount), 0) AS amount,
                    COUNT(DISTINCT user_id) AS recipients,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending
               FROM binary_awards
              WHERE created_at >= ? AND created_at < ?
              GROUP BY kind
              ORDER BY kind"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'kind'       => (string) $row['kind'],
                'count'      => (int) $row['count'],
                'amount'     => round((float) $row['amount'], 2),
                'recipients' => (int) $row['recipients'],
                'credited'   => (int) $row['credited'],
                'pending'    => (int) $row['pending'],
            ];
        }

        return $rows;
    }

    /**
     * Product binary points placed and awards per product in a window,
     * biggest points first.
     *
     * Points placed is read from `binary_volume` alone (never joined to
     * awards, so an order's points are not multiplied by its award count).
     * An award counts toward a product when its order placed that
     * product's points.
     *
     * @return list<array{product_id:int, product:string, points_placed:int, awards:int, self_total:float, pair_total:float}>
     */
    public static function binaryByProduct(string $from, string $to, int $limit = 8, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(50, $limit));

        $fromTs = $from . ' 00:00:00';
        $toTs   = self::dayAfter($to) . ' 00:00:00';

        /* Points placed per product, from the volume ledger alone. */
        $points = [];
        $stmt = $pdo->prepare(
            "SELECT oi.product_id AS product_id, COALESCE(SUM(v.points), 0) AS points_placed
               FROM binary_volume v
               JOIN shop_order_items oi ON oi.id = v.order_item_id
              WHERE v.kind IN ('own','team') AND v.created_at >= ? AND v.created_at < ?
              GROUP BY oi.product_id"
        );
        $stmt->execute([$fromTs, $toTs]);
        foreach ($stmt->fetchAll() as $row) {
            $points[(int) $row['product_id']] = (int) $row['points_placed'];
        }

        /* Awards per product: an award counts toward a product when its
         * order placed that product's points. */
        $awards = [];
        $stmt = $pdo->prepare(
            "SELECT oi.product_id AS product_id, COUNT(DISTINCT a.id) AS awards
               FROM binary_awards a
               JOIN shop_order_items oi ON oi.order_id = a.order_id
              WHERE a.created_at >= ? AND a.created_at < ?
              GROUP BY oi.product_id"
        );
        $stmt->execute([$fromTs, $toTs]);
        foreach ($stmt->fetchAll() as $row) {
            $pid = (int) $row['product_id'];
            if (isset($points[$pid])) {
                $awards[$pid] = (int) $row['awards'];
            }
        }

        $rows = [];
        foreach ($points as $productId => $pointsPlaced) {
            $rows[] = [
                'product_id'  => $productId,
                'product'     => '',
                'points_placed' => $pointsPlaced,
                'awards'      => $awards[$productId] ?? 0,
                'self_total'  => 0.0,
                'pair_total'  => 0.0,
            ];
        }

        if ($rows !== []) {
            $names = [];
            foreach ($pdo->query('SELECT id, name FROM products')->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $name) {
                $names[(int) $id] = (string) $name;
            }
            foreach ($rows as &$row) {
                $row['product'] = $names[$row['product_id']] ?? 'Product';
            }
            unset($row);
        }

        usort($rows, static fn (array $a, array $b): int =>
            $b['points_placed'] <=> $a['points_placed']
            ?: ($b['awards'] <=> $a['awards'])
            ?: strcmp($a['product'], $b['product']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * The top nodes by personal (own) points placed, biggest first.
     *
     * @return list<array{user_id:int, username:string, personal_points:int, left_points:int, right_points:int, pairs_paid:int}>
     */
    public static function binaryTopNodes(int $limit = 8, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(50, $limit));

        $rows = $pdo->query(
            "SELECT n.user_id, u.username, n.personal_points,
                    n.left_points, n.right_points, n.pairs_paid
               FROM binary_nodes n
               JOIN users u ON u.id = n.user_id
              WHERE n.state = 'placed'
              ORDER BY n.personal_points DESC, n.user_id ASC
              LIMIT " . $limit
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'user_id'       => (int) $row['user_id'],
                'username'      => (string) $row['username'],
                'personal_points' => (int) $row['personal_points'],
                'left_points'   => (int) $row['left_points'],
                'right_points'  => (int) $row['right_points'],
                'pairs_paid'    => (int) $row['pairs_paid'],
            ];
        }

        return $out;
    }

    /**
     * Readiness counts for the Product binary stream: products flagged
     * with no P.V., products with the switch off, members without a node,
     * and whether the global master is on.
     *
     * @return array{
     *   stream_on:bool, products_on_no_points:int, products_off:int,
     *   members_unplaced:int, members_total:int
     * }
     */
    public static function binaryReadiness(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $streamOn = setting_bool('binary_enabled', false);

        $row = $pdo->query(
            "SELECT COUNT(CASE WHEN binary_enabled = 1 AND pv = 0 THEN 1 END) AS products_on_no_points,
                    COUNT(CASE WHEN binary_enabled = 0 THEN 1 END) AS products_off
               FROM products"
        )->fetch() ?: [];

        $unplaced = $pdo->query(
            "SELECT COUNT(*) AS members_unplaced
               FROM users u
              WHERE u.role = 'member'
                AND NOT EXISTS (SELECT 1 FROM binary_nodes n WHERE n.user_id = u.id)"
        )->fetch() ?: [];

        $total = $pdo->query("SELECT COUNT(*) AS members_total FROM users WHERE role = 'member'")->fetch() ?: [];

        return [
            'stream_on'          => (bool) $streamOn,
            'products_on_no_points'=> (int) ($row['products_on_no_points'] ?? 0),
            'products_off'       => (int) ($row['products_off'] ?? 0),
            'members_unplaced'   => (int) ($unplaced['members_unplaced'] ?? 0),
            'members_total'      => (int) ($total['members_total'] ?? 0),
        ];
    }

    /**
     * Package binary headline counters for a window of activation-sourced awards.
     *
     * Reads `binary_awards` where `source = 'activation'` and `kind = 'pair'`,
     * plus `binary_volume` where `source = 'activation'` and `kind = 'team'`.
     * Points and money are shown side by side, never added together (plan Rule 4).
     *
     * @return array{
     *   amount:float, count:int, cancelled:int, cancelled_total:float,
     *   voided:int, voided_total:float, unrecovered:float, recipients:int,
     *   all_time:float, this_month:float, pending_amount:float,
     *   pending_count:int, points_placed:int, activations:int
     * }
     */
    public static function packageBinarySummary(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS count,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_count,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled,
                    COALESCE(SUM(CASE WHEN status = 'cancelled' THEN amount ELSE 0 END), 0) AS cancelled_total,
                    COUNT(CASE WHEN status = 'voided' THEN 1 END) AS voided,
                    COALESCE(SUM(CASE WHEN status = 'voided' THEN amount ELSE 0 END), 0) AS voided_total,
                    COALESCE(SUM(CASE WHEN status = 'voided'
                                       THEN amount - reversed_amount ELSE 0 END), 0) AS unrecovered,
                    COUNT(DISTINCT user_id) AS recipients,
                    COUNT(DISTINCT activation_id) AS activations
               FROM binary_awards
              WHERE source = 'activation' AND kind = 'pair'
                AND created_at >= ? AND created_at < ?"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $row = $stmt->fetch() ?: [];

        $totals = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS all_time,
                    COALESCE(SUM(CASE WHEN status = 'credited'
                                       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount ELSE 0 END), 0) AS this_month
               FROM binary_awards
              WHERE source = 'activation' AND kind = 'pair'"
        )->fetch() ?: [];

        $points = $pdo->prepare(
            "SELECT COALESCE(SUM(points), 0) AS points_placed
               FROM binary_volume
              WHERE source = 'activation' AND kind = 'team'
                AND created_at >= ? AND created_at < ?"
        );
        $points->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);
        $pts = $points->fetch() ?: [];

        return [
            'amount'           => round((float) ($row['amount'] ?? 0), 2),
            'count'            => (int) ($row['count'] ?? 0),
            'cancelled'        => (int) ($row['cancelled'] ?? 0),
            'cancelled_total'  => round((float) ($row['cancelled_total'] ?? 0), 2),
            'voided'           => (int) ($row['voided'] ?? 0),
            'voided_total'     => round((float) ($row['voided_total'] ?? 0), 2),
            'unrecovered'      => round((float) ($row['unrecovered'] ?? 0), 2),
            'recipients'       => (int) ($row['recipients'] ?? 0),
            'all_time'         => round((float) ($totals['all_time'] ?? 0), 2),
            'this_month'       => round((float) ($totals['this_month'] ?? 0), 2),
            'pending_amount'   => round((float) ($row['pending_amount'] ?? 0), 2),
            'pending_count'    => (int) ($row['pending_count'] ?? 0),
            'points_placed'    => (int) ($pts['points_placed'] ?? 0),
            'activations'      => (int) ($row['activations'] ?? 0),
        ];
    }

    /**
     * Package binary per package in a window: points placed, pairs paid,
     * money credited, activations — biggest points first.
     *
     * @return list<array{
     *   package_id:int, package:string, activations:int, points_placed:int,
     *   pairs:int, amount:float, recipients:int
     * }>
     */
    public static function packageBinaryByPackage(string $from, string $to, int $limit = 8, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(50, $limit));

        $fromTs = $from . ' 00:00:00';
        $toTs   = self::dayAfter($to) . ' 00:00:00';

        /* Points placed per package, from the volume ledger joined to activations. */
        $points = [];
        $stmt = $pdo->prepare(
            "SELECT a.package_id AS package_id, p.name AS package,
                    COALESCE(SUM(v.points), 0) AS points_placed,
                    COUNT(DISTINCT a.id) AS activations
               FROM binary_volume v
               JOIN activations a ON a.id = v.activation_id
               JOIN packages p ON p.id = a.package_id
              WHERE v.source = 'activation' AND v.kind = 'team'
                AND v.created_at >= ? AND v.created_at < ?
              GROUP BY a.package_id, p.name"
        );
        $stmt->execute([$fromTs, $toTs]);
        foreach ($stmt->fetchAll() as $row) {
            $pid = (int) $row['package_id'];
            $points[$pid] = [
                'package_id'   => $pid,
                'package'      => (string) $row['package'],
                'points_placed'=> (int) $row['points_placed'],
                'activations'  => (int) $row['activations'],
                'pairs'        => 0,
                'amount'       => 0.0,
                'recipients'   => 0,
            ];
        }

        /* Awards per package, joined through activations. */
        $stmt = $pdo->prepare(
            "SELECT a.package_id AS package_id,
                    COUNT(*) AS pairs,
                    COALESCE(SUM(CASE WHEN aw.status = 'credited' THEN aw.amount ELSE 0 END), 0) AS amount,
                    COUNT(DISTINCT aw.user_id) AS recipients
               FROM binary_awards aw
               JOIN activations a ON a.id = aw.activation_id
              WHERE aw.source = 'activation' AND aw.kind = 'pair'
                AND aw.created_at >= ? AND aw.created_at < ?
              GROUP BY a.package_id"
        );
        $stmt->execute([$fromTs, $toTs]);
        foreach ($stmt->fetchAll() as $row) {
            $pid = (int) $row['package_id'];
            if (isset($points[$pid])) {
                $points[$pid]['pairs']   = (int) $row['pairs'];
                $points[$pid]['amount']  = round((float) $row['amount'], 2);
                $points[$pid]['recipients'] = (int) $row['recipients'];
            }
        }

        $rows = array_values($points);
        usort($rows, static fn (array $a, array $b): int =>
            $b['points_placed'] <=> $a['points_placed']
            ?: ($b['pairs'] <=> $a['pairs'])
            ?: strcmp($a['package'], $b['package']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Package binary per activation in a window: points placed, pairs paid,
     * money credited — newest first.
     *
     * @return list<array{
     *   activation_id:int, user_id:int, username:string, package:string,
     *   points:int, pairs:int, amount:float, status:string, processed_at:?string
     * }>
     */
    public static function packageBinaryByActivation(string $from, string $to, int $limit = 8, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(50, $limit));

        $rows = $pdo->prepare(
            "SELECT a.id AS activation_id, a.user_id AS user_id, u.username AS username,
                    p.name AS package, a.status AS status, a.binary_processed_at AS processed_at,
                    COALESCE(SUM(CASE WHEN v.kind = 'team' THEN v.points ELSE 0 END), 0) AS points,
                    COUNT(DISTINCT CASE WHEN aw.kind = 'pair' AND aw.status IN ('pending','credited') THEN aw.id END) AS pairs,
                    COALESCE(SUM(CASE WHEN aw.kind = 'pair' AND aw.status = 'credited' THEN aw.amount ELSE 0 END), 0) AS amount
               FROM activations a
               JOIN users u ON u.id = a.user_id
               JOIN packages p ON p.id = a.package_id
               LEFT JOIN binary_volume v ON v.activation_id = a.id AND v.source = 'activation' AND v.kind = 'team'
               LEFT JOIN binary_awards aw ON aw.activation_id = a.id AND aw.source = 'activation' AND aw.kind = 'pair'
              WHERE a.binary_processed_at IS NOT NULL
                AND a.binary_processed_at >= ? AND a.binary_processed_at < ?
              GROUP BY a.id, a.user_id, u.username, p.name, a.status, a.binary_processed_at
              ORDER BY a.binary_processed_at DESC, a.id DESC
              LIMIT " . $limit
        );
        $rows->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $out = [];
        foreach ($rows->fetchAll() as $row) {
            $out[] = [
                'activation_id' => (int) $row['activation_id'],
                'user_id'       => (int) $row['user_id'],
                'username'      => (string) $row['username'],
                'package'       => (string) $row['package'],
                'points'        => (int) $row['points'],
                'pairs'         => (int) $row['pairs'],
                'amount'        => round((float) $row['amount'], 2),
                'status'        => (string) $row['status'],
                'processed_at'  => $row['processed_at'] === null ? null : (string) $row['processed_at'],
            ];
        }

        return $out;
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @param array<string, mixed> $row */
    private static function moneyRow(array $row): array
    {
        $orders = (int) ($row['orders'] ?? 0);
        $amount = round((float) ($row['amount'] ?? 0), 2);

        return [
            'orders'  => $orders,
            'amount'  => $amount,
            'average' => $orders === 0 ? 0.0 : round($amount / $orders, 2),
        ];
    }

    /** Y-m-d after the given day (the exclusive end of a day window). */
    private static function dayAfter(string $day): string
    {
        $ts = strtotime($day . ' 00:00:00');

        return $ts === false ? $day : date('Y-m-d', strtotime('+1 day', $ts));
    }

    /**
     * The inclusive month window of a date range, as 'YYYY-MM' keys.
     *
     * A reversed range is swapped rather than trusted; the report
     * service normalises its windows, but a direct caller may not.
     *
     * @return array{0: string, 1: string}
     */
    private static function royaltyMonths(string $from, string $to): array
    {
        $start = substr($from, 0, 7);
        $end   = substr($to, 0, 7);

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }
}
