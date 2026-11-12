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
     * unreturned shortfall — plus all-time and this-month totals.
     *
     * A `skipped` row is an audit note, never money (plan §8 Rule 5).
     *
     * @return array{
     *   amount:float, count:int, skipped:int, voided:int, unrecovered:float,
     *   recipients:int, all_time:float, this_month:float
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
                    COUNT(DISTINCT CASE WHEN status = 'credited' THEN user_id END) AS recipients
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
            'amount'       => round((float) ($row['amount'] ?? 0), 2),
            'count'        => (int) ($row['credited'] ?? 0),
            'skipped'      => (int) ($row['skipped'] ?? 0),
            'voided'       => (int) ($row['voided'] ?? 0),
            'unrecovered'  => round((float) ($row['unrecovered'] ?? 0), 2),
            'recipients'   => (int) ($row['recipients'] ?? 0),
            'all_time'     => round((float) ($totals['all_time'] ?? 0), 2),
            'this_month'   => round((float) ($totals['this_month'] ?? 0), 2),
        ];
    }

    /**
     * Credited awards per ladder level (1 … 10), skips included.
     *
     * @return list<array{level:int, count:int, amount:float, skipped:int}>
     */
    public static function unilevelByLevel(string $from, string $to, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $stmt   = $pdo->prepare(
            "SELECT level,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped
               FROM unilevel_awards
              WHERE created_at >= ? AND created_at < ?
              GROUP BY level
              ORDER BY level"
        );
        $stmt->execute([$from . ' 00:00:00', self::dayAfter($to) . ' 00:00:00']);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'level'   => (int) $row['level'],
                'count'   => (int) $row['credited'],
                'amount'  => round((float) $row['amount'], 2),
                'skipped' => (int) $row['skipped'],
            ];
        }

        return $rows;
    }

    /**
     * Repeat-purchase money per product, biggest first.
     *
     * @return list<array{product_id:int, product:string, count:int, amount:float, skipped:int}>
     */
    public static function unilevelByProduct(string $from, string $to, int $limit = 8, ?PDO $pdo = null): array
    {
        $pdo  ??= db();
        $limit   = max(1, min(50, $limit));

        $stmt = $pdo->prepare(
            "SELECT a.product_id, p.name AS product,
                    COUNT(CASE WHEN a.status = 'credited' THEN 1 END) AS credited,
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS amount,
                    COUNT(CASE WHEN a.status = 'skipped' THEN 1 END) AS skipped
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
                'product_id' => (int) $row['product_id'],
                'product'    => (string) $row['product'],
                'count'      => (int) $row['credited'],
                'amount'     => round((float) $row['amount'], 2),
                'skipped'    => (int) $row['skipped'],
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
}
