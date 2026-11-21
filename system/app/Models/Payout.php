<?php
declare(strict_types=1);

/* ==============================================================
 *  Payout — read queries for withdrawal requests (plan §4.2, §5.9;
 *  Phase 13)
 *
 *  This model only reads. Every request and every status change is a
 *  PayoutService call, which takes the row lock, moves the money
 *  through WalletService and writes the audit entry in one transaction.
 *
 *  Filters shared by the admin queue:
 *
 *      status    pending | approved | completed | rejected ('' = all)
 *      q         member username/full name, payout account or admin note
 *      from/to   YYYY-MM-DD on requested_at (inclusive, Asia/Manila)
 * ============================================================== */

final class Payout
{
    /** The four states of plan §4.4, in workflow order. */
    public const STATUSES = ['pending', 'approved', 'completed', 'rejected'];

    /** A request still holding the member's money. */
    public const OPEN_STATUSES = ['pending', 'approved'];

    /** @return array{status:string, q:string, from:string, to:string} */
    public static function filterDefaults(): array
    {
        return ['status' => '', 'q' => '', 'from' => '', 'to' => ''];
    }

    /**
     * Keep only the filters the queries understand; unknown values fall
     * back to "no filter", so a hand-edited query string is harmless.
     *
     * @param array<string, mixed> $raw
     * @return array{status:string, q:string, from:string, to:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $status = strtolower(trim((string) ($raw['status'] ?? '')));

        if (in_array($status, self::STATUSES, true)) {
            $filters['status'] = $status;
        }

        $filters['q'] = trim((string) ($raw['q'] ?? ''));

        $from = self::date((string) ($raw['from'] ?? ''));
        if ($from !== '') {
            $filters['from'] = $from;
        }

        $to = self::date((string) ($raw['to'] ?? ''));
        if ($to !== '') {
            $filters['to'] = $to;
        }

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
     * WHERE clause shared by the queue count, rows and totals.
     *
     * @param array{status:string, q:string, from:string, to:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (($filters['status'] ?? '') !== '') {
            $where[]  = 'p.status = ?';
            $params[] = $filters['status'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[]  = 'p.requested_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]  = 'p.requested_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like     = '%' . like_escape($query) . '%';
            $where[]  = '(u.username LIKE ? OR u.full_name LIKE ? OR p.payout_account LIKE ? OR p.admin_note LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /* ----------------------------------------------------------
     *  Single request
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::rowSelect() . ' WHERE p.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The member's open request, if any (pending or approved).
     *
     * The service re-checks this under the account lock; the page uses it
     * to explain why the form is closed before the member types anything.
     *
     * @return array<string, mixed>|null
     */
    public static function openFor(int $userId, ?PDO $pdo = null): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            self::rowSelect() . "
             WHERE p.user_id = ? AND p.status IN ('pending','approved')
             ORDER BY p.id DESC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /* ----------------------------------------------------------
     *  One member's requests
     * ---------------------------------------------------------- */

    public static function countFor(int $userId, ?PDO $pdo = null): int
    {
        $pdo ??= db();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payout_requests WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * A member's withdrawal history, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(
            self::rowSelect() . '
             WHERE p.user_id = ?
             ORDER BY p.id DESC
             LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * A member's lifetime withdrawal figures (the headline under the form).
     *
     * `held` is money currently promised to an open request — the amount
     * that has already left the wallet but has not been paid out or
     * refunded yet.
     *
     * @return array{requests:int, requested:float, held:float, paid:int, paid_total:float, net_total:float, fees_total:float, rejected:int, rejected_total:float}
     */
    public static function memberTotals(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS requests,
                    COALESCE(SUM(amount), 0) AS requested,
                    COALESCE(SUM(CASE WHEN status IN ('pending','approved') THEN amount ELSE 0 END), 0) AS held,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) AS paid,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS paid_total,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount - service_fee_amount ELSE 0 END), 0) AS net_total,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN service_fee_amount ELSE 0 END), 0) AS fees_total,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) AS rejected,
                    COALESCE(SUM(CASE WHEN status = 'rejected' THEN amount ELSE 0 END), 0) AS rejected_total
               FROM payout_requests
              WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'requests'        => (int) ($row['requests'] ?? 0),
            'requested'       => round((float) ($row['requested'] ?? 0), 2),
            'held'            => round((float) ($row['held'] ?? 0), 2),
            'paid'            => (int) ($row['paid'] ?? 0),
            'paid_total'      => round((float) ($row['paid_total'] ?? 0), 2),
            'net_total'       => round((float) ($row['net_total'] ?? 0), 2),
            'fees_total'      => round((float) ($row['fees_total'] ?? 0), 2),
            'rejected'        => (int) ($row['rejected'] ?? 0),
            'rejected_total'  => round((float) ($row['rejected_total'] ?? 0), 2),
        ];
    }

    /* ----------------------------------------------------------
     *  Admin queue
     * ---------------------------------------------------------- */

    /** @param array{status:string, q:string, from:string, to:string} $filters */
    public static function queueCount(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM payout_requests p
               JOIN users u ON u.id = p.user_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function queue(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            self::rowSelect() . '
             WHERE ' . $where . '
             ORDER BY p.id DESC
             LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Totals for the filtered set (the footer line above the table).
     *
     * @param array{status:string, q:string, from:string, to:string} $filters
     * @return array{rows:int, amount:float, fees:float, net:float}
     */
    public static function queueTotals(array $filters, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS rows_count,
                    COALESCE(SUM(p.amount), 0) AS amount,
                    COALESCE(SUM(p.service_fee_amount), 0) AS fees
               FROM payout_requests p
               JOIN users u ON u.id = p.user_id
              WHERE ' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['rows_count' => 0, 'amount' => 0, 'fees' => 0];

        $amount = round((float) $row['amount'], 2);
        $fees   = round((float) $row['fees'], 2);

        return [
            'rows'   => (int) $row['rows_count'],
            'amount' => $amount,
            'fees'   => $fees,
            'net'    => round($amount - $fees, 2),
        ];
    }

    /**
     * How many requests sit in each state (the tab badges).
     *
     * @return array{pending:int, approved:int, completed:int, rejected:int, all:int}
     */
    public static function statusCounts(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $counts = ['pending' => 0, 'approved' => 0, 'completed' => 0, 'rejected' => 0, 'all' => 0];

        foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM payout_requests GROUP BY status') as $row) {
            $status = (string) $row['status'];

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['n'];
                $counts['all']  += (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * Lifetime payout figures for the queue's stat cards.
     *
     * `held` is the money sitting in open requests; `paid` is what has
     * left the company. Both are gross of the service fee.
     *
     * @return array{
     *   pending:int, pending_amount:float,
     *   approved:int, approved_amount:float,
     *   completed:int, completed_amount:float, completed_net:float,
     *   rejected:int, rejected_amount:float,
     *   held:int, held_amount:float, fees_earned:float, usdt_paid:float
     * }
     */
    public static function totals(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COUNT(CASE WHEN status = 'pending'   THEN 1 END) AS pending,
                    COALESCE(SUM(CASE WHEN status = 'pending'   THEN amount ELSE 0 END), 0) AS pending_amount,
                    COUNT(CASE WHEN status = 'approved'  THEN 1 END) AS approved,
                    COALESCE(SUM(CASE WHEN status = 'approved'  THEN amount ELSE 0 END), 0) AS approved_amount,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS completed_amount,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount - service_fee_amount ELSE 0 END), 0) AS completed_net,
                    COUNT(CASE WHEN status = 'rejected'  THEN 1 END) AS rejected,
                    COALESCE(SUM(CASE WHEN status = 'rejected'  THEN amount ELSE 0 END), 0) AS rejected_amount,
                    COUNT(CASE WHEN status IN ('pending','approved') THEN 1 END) AS held,
                    COALESCE(SUM(CASE WHEN status IN ('pending','approved') THEN amount ELSE 0 END), 0) AS held_amount,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN service_fee_amount ELSE 0 END), 0) AS fees_earned,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN usdt_amount ELSE 0 END), 0) AS usdt_paid
               FROM payout_requests"
        )->fetch() ?: [];

        return [
            'pending'          => (int) ($row['pending'] ?? 0),
            'pending_amount'   => round((float) ($row['pending_amount'] ?? 0), 2),
            'approved'         => (int) ($row['approved'] ?? 0),
            'approved_amount'  => round((float) ($row['approved_amount'] ?? 0), 2),
            'completed'        => (int) ($row['completed'] ?? 0),
            'completed_amount' => round((float) ($row['completed_amount'] ?? 0), 2),
            'completed_net'    => round((float) ($row['completed_net'] ?? 0), 2),
            'rejected'         => (int) ($row['rejected'] ?? 0),
            'rejected_amount'  => round((float) ($row['rejected_amount'] ?? 0), 2),
            'held'             => (int) ($row['held'] ?? 0),
            'held_amount'      => round((float) ($row['held_amount'] ?? 0), 2),
            'fees_earned'      => round((float) ($row['fees_earned'] ?? 0), 2),
            'usdt_paid'        => round((float) ($row['usdt_paid'] ?? 0), 4),
        ];
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private static function rowSelect(): string
    {
        return 'SELECT p.id, p.user_id, p.amount, p.payout_method, p.payout_account,
                       p.service_fee_pct, p.service_fee_amount,
                       p.usdt_rate, p.usdt_gas_fee, p.usdt_amount,
                       p.status, p.admin_note, p.processed_by,
                       p.requested_at, p.processed_at,
                       u.username AS member_username,
                       u.full_name AS member_name,
                       u.member_status AS member_status,
                       u.gcash_number, u.maya_number,
                       u.usdt_trc20_address, u.usdt_bep20_address,
                       a.username AS admin_username,
                       a.full_name AS admin_name
                  FROM payout_requests p
                  JOIN users u ON u.id = p.user_id
                  LEFT JOIN users a ON a.id = p.processed_by';
    }
}
