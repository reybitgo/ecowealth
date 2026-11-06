<?php
declare(strict_types=1);

/* ==============================================================
 *  Order — read layer for `shop_orders` (plan §4.4, §5.3; Phase 6)
 *
 *  Reads are always scoped: member queries include `user_id = ?`
 *  and a posted order id alone never authorises access (P6-T8).
 *  Writes live in OrderService (placement, member cancel) and
 *  PaymentService (proof review, admin cancel — Phase 7); the event
 *  rows live in OrderEvent.
 *
 *  Statuses (plan §4.4):
 *      pending → payment_review → paid → packing → ready_to_ship
 *              → shipped → delivered → completed
 *      pending / payment_failed → cancelled
 * ============================================================== */

final class Order
{
    public const STATUSES = [
        'pending',
        'payment_review',
        'payment_failed',
        'paid',
        'packing',
        'ready_to_ship',
        'shipped',
        'delivered',
        'completed',
        'cancelled',
    ];

    public const PAYMENT_METHODS = ['ewallet', 'gcash', 'maya', 'bank'];

    /** Statuses a member may cancel themselves (plan §4.4). */
    public const MEMBER_CANCELLABLE = ['pending', 'payment_failed'];

    /** Statuses that accept a payment proof (plan §4.4, §5.4; Phase 7). */
    public const PROOF_SUBMITTABLE = ['pending', 'payment_failed'];

    /** Statuses an administrator may cancel — everything before shipment. */
    public const ADMIN_CANCELLABLE = [
        'pending',
        'payment_review',
        'payment_failed',
        'paid',
        'packing',
        'ready_to_ship',
    ];

    /** Statuses whose cancellation refunds a wallet-paid order. */
    public const REFUNDABLE = ['paid', 'packing', 'ready_to_ship'];

    /** @var array{type:string, q:string, status:string, method:string, from:string, to:string} */
    public const FILTER_DEFAULTS = [
        'type'   => '',
        'q'      => '',
        'status' => '',
        'method' => '',
        'from'   => '',
        'to'     => '',
    ];

    /* ----------------------------------------------------------
     *  Single-order reads
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare('SELECT * FROM shop_orders WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findForUser(int $id, int $userId, ?PDO $pdo = null): ?array
    {
        if ($id <= 0 || $userId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM shop_orders WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The order a repeated checkout submission should resolve to.
     *
     * @return array<string, mixed>|null
     */
    public static function findByIdempotencyKey(
        string $key,
        int $userId,
        bool $forUpdate = false,
        ?PDO $pdo = null
    ): ?array {
        $key = trim($key);

        if ($key === '' || $userId <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM shop_orders WHERE idempotency_key = ? AND user_id = ? LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '');

        $stmt = ($pdo ?? db())->prepare($sql);
        $stmt->execute([$key, $userId]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function items(int $orderId, ?PDO $pdo = null): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public static function events(int $orderId, ?PDO $pdo = null): array
    {
        return OrderEvent::forOrder($orderId, $pdo);
    }

    /* ----------------------------------------------------------
     *  Rules
     * ---------------------------------------------------------- */

    /** May the member cancel this order online? */
    public static function canMemberCancel(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), self::MEMBER_CANCELLABLE, true);
    }

    /** May an administrator cancel this order (before it ships)? */
    public static function canAdminCancel(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), self::ADMIN_CANCELLABLE, true);
    }

    /** Does cancelling this order owe the member a wallet refund? */
    public static function isRefundable(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), self::REFUNDABLE, true);
    }

    /* ----------------------------------------------------------
     *  Lists and filters
     * ---------------------------------------------------------- */

    /**
     * @param array<string, mixed> $raw
     * @return array{type:string, q:string, status:string, method:string, from:string, to:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::FILTER_DEFAULTS;

        $status = strtolower(trim((string) ($raw['status'] ?? '')));
        if (in_array($status, self::STATUSES, true)) {
            $filters['status'] = $status;
        }

        $method = strtolower(trim((string) ($raw['method'] ?? '')));
        if (in_array($method, self::PAYMENT_METHODS, true)) {
            $filters['method'] = $method;
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
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(?int $userId, array $filters, bool $admin): array
    {
        $where  = $admin ? ['1 = 1'] : ['o.user_id = ?'];
        $params = $admin ? [] : [$userId];

        if (($filters['status'] ?? '') !== '') {
            $where[]  = 'o.status = ?';
            $params[] = $filters['status'];
        }

        if (($filters['method'] ?? '') !== '') {
            $where[]  = 'o.payment_method = ?';
            $params[] = $filters['method'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[]  = 'o.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]  = 'o.created_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . like_escape($query) . '%';

            if ($admin) {
                $where[]  = '(o.order_no LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            } else {
                $where[]  = 'o.order_no LIKE ?';
                $params[] = $like;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    public static function countForUser(int $userId, array $filters, ?PDO $pdo = null): int
    {
        [$where, $params] = self::whereFor($userId, $filters, false);

        $stmt = ($pdo ?? db())->prepare(
            'SELECT COUNT(*) FROM shop_orders o WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function listForUser(int $userId, array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        [$where, $params] = self::whereFor($userId, $filters, false);

        $sql = 'SELECT o.*,
                       (SELECT COUNT(*) FROM shop_order_items oi WHERE oi.order_id = o.id) AS item_count
                  FROM shop_orders o
                 WHERE ' . $where . '
                 ORDER BY o.id DESC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = ($pdo ?? db())->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function countAdmin(array $filters, ?PDO $pdo = null): int
    {
        [$where, $params] = self::whereFor(null, $filters, true);

        $stmt = ($pdo ?? db())->prepare(
            'SELECT COUNT(*) FROM shop_orders o JOIN users u ON u.id = o.user_id WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        [$where, $params] = self::whereFor(null, $filters, true);

        $sql = 'SELECT o.*, u.username, u.full_name AS user_name, u.email,
                       (SELECT COUNT(*) FROM shop_order_items oi WHERE oi.order_id = o.id) AS item_count
                  FROM shop_orders o
                  JOIN users u ON u.id = o.user_id
                 WHERE ' . $where . '
                 ORDER BY o.id DESC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = ($pdo ?? db())->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Admin dashboard numbers for the order desk.
     *
     * @return array{
     *   total:int, awaiting_payment:int, in_review:int, in_progress:int,
     *   completed:int, cancelled:int, revenue:float, today_count:int, today_total:float
     * }
     */
    public static function stats(?PDO $pdo = null): array
    {
        $row = ($pdo ?? db())->query(
            "SELECT COUNT(*) AS total,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS awaiting_payment,
                    COUNT(CASE WHEN status = 'payment_review' THEN 1 END) AS in_review,
                    COUNT(CASE WHEN status IN ('paid','packing','ready_to_ship','shipped','delivered') THEN 1 END) AS in_progress,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled,
                    COALESCE(SUM(CASE WHEN paid_at IS NOT NULL AND status <> 'cancelled' THEN total ELSE 0 END), 0) AS revenue,
                    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS today_count,
                    COALESCE(SUM(CASE WHEN paid_at IS NOT NULL AND status <> 'cancelled'
                                       AND DATE(paid_at) = CURDATE() THEN total ELSE 0 END), 0) AS today_total
               FROM shop_orders"
        )->fetch() ?: [];

        return [
            'total'            => (int) ($row['total'] ?? 0),
            'awaiting_payment' => (int) ($row['awaiting_payment'] ?? 0),
            'in_review'        => (int) ($row['in_review'] ?? 0),
            'in_progress'      => (int) ($row['in_progress'] ?? 0),
            'completed'        => (int) ($row['completed'] ?? 0),
            'cancelled'        => (int) ($row['cancelled'] ?? 0),
            'revenue'          => round((float) ($row['revenue'] ?? 0), 2),
            'today_count'      => (int) ($row['today_count'] ?? 0),
            'today_total'      => round((float) ($row['today_total'] ?? 0), 2),
        ];
    }
}
