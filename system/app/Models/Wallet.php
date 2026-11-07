<?php
declare(strict_types=1);

/* ==============================================================
 *  Wallet — read queries for the dual bucket, ledger and top-ups
 *  (plan §4.2, §5.8; Phase 5)
 *
 *  This model only reads. Every money mutation belongs to
 *  WalletService, which locks the account row and writes the
 *  immutable `ewallet_ledger` row (plan §3.7).
 *
 *  Filters shared by the member statement and the admin monitor:
 *
 *      type      credit | debit
 *      ref_type  one of WalletService::REF_TYPES
 *      from/to   YYYY-MM-DD (inclusive, Asia/Manila)
 *      q         note search (admin: also username / full name)
 * ============================================================== */

final class Wallet
{
    /** @var array<string, mixed> */
    public const LEDGER_COLUMNS = [
        'l.id',
        'l.user_id',
        'l.type',
        'l.amount',
        'l.balance_after',
        'l.withdrawable_after',
        'l.ref_type',
        'l.ref_id',
        'l.note',
        'l.created_at',
        'u.username',
        'u.full_name AS user_name',
    ];

    /** @return array<string, mixed>|null */
    public static function account(int $userId, ?PDO $pdo = null): ?array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(
            'SELECT id, username, email, full_name, role, member_status, is_active,
                    ewallet_balance, withdrawable_balance, last_login_at, created_at
               FROM users
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array{type:string, ref_type:string, from:string, to:string, q:string} */
    public static function filterDefaults(): array
    {
        return ['type' => '', 'ref_type' => '', 'from' => '', 'to' => '', 'q' => ''];
    }

    /**
     * Keep only the filters the queries understand. Unknown values are
     * ignored (they fall back to "no filter"), so a hand-edited query
     * string can never break the page or leak SQL.
     *
     * @param array<string, mixed> $raw
     * @return array{type:string, ref_type:string, from:string, to:string, q:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $type = strtolower(trim((string) ($raw['type'] ?? '')));
        if (in_array($type, ['credit', 'debit'], true)) {
            $filters['type'] = $type;
        }

        $refType = strtolower(trim((string) ($raw['ref_type'] ?? '')));
        if (in_array($refType, WalletService::REF_TYPES, true)) {
            $filters['ref_type'] = $refType;
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
     * WHERE clause shared by count, rows and totals.
     *
     * @param array{type:string, ref_type:string, from:string, to:string, q:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(?int $userId, array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($userId !== null) {
            $where[] = 'l.user_id = ?';
            $params[] = $userId;
        }

        if (($filters['type'] ?? '') !== '') {
            $where[] = 'l.type = ?';
            $params[] = $filters['type'];
        }

        if (($filters['ref_type'] ?? '') !== '') {
            $where[] = 'l.ref_type = ?';
            $params[] = $filters['ref_type'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[] = 'l.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[] = 'l.created_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . like_escape($query) . '%';
            if ($userId === null) {
                $where[] = '(l.note LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            } else {
                $where[] = 'l.note LIKE ?';
                $params[] = $like;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /** Number of ledger rows matching the filters (member or all users). */
    public static function ledgerCount(?int $userId, array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($userId, $filters);

        $sql = 'SELECT COUNT(*) FROM ewallet_ledger l JOIN users u ON u.id = l.user_id WHERE ' . $where;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of ledger rows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function ledger(?int $userId, array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($userId, $filters);

        $sql = 'SELECT ' . implode(', ', self::LEDGER_COLUMNS) . '
                  FROM ewallet_ledger l
                  JOIN users u ON u.id = l.user_id
                 WHERE ' . $where . '
                 ORDER BY l.id DESC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Totals for the whole filtered set — the footer under the table.
     *
     * @return array{rows:int, credits:float, debits:float, net:float}
     */
    public static function ledgerTotals(?int $userId, array $filters, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($userId, $filters);

        $sql = "SELECT COUNT(*) AS rows_count,
                       COALESCE(SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END), 0) AS credits,
                       COALESCE(SUM(CASE WHEN l.type = 'debit'  THEN l.amount ELSE 0 END), 0) AS debits
                  FROM ewallet_ledger l
                  JOIN users u ON u.id = l.user_id
                 WHERE " . $where;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['rows_count' => 0, 'credits' => 0, 'debits' => 0];

        $credits = round((float) $row['credits'], 2);
        $debits  = round((float) $row['debits'], 2);

        return [
            'rows'    => (int) $row['rows_count'],
            'credits' => $credits,
            'debits'  => $debits,
            'net'     => round($credits - $debits, 2),
        ];
    }

    /* ----------------------------------------------------------
     *  Admin top-ups
     * ---------------------------------------------------------- */

    public static function topupCountFor(int $userId, ?PDO $pdo = null): int
    {
        $pdo ??= db();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ewallet_admin_topups WHERE recipient_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Top-ups received by one member.
     *
     * @return list<array<string, mixed>>
     */
    public static function topupsFor(int $userId, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $sql = 'SELECT t.id, t.amount, t.note, t.created_at, a.username AS admin_username
                  FROM ewallet_admin_topups t
                  JOIN users a ON a.id = t.admin_id
                 WHERE t.recipient_id = ?
                 ORDER BY t.id DESC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * Top-up search across every member (admin monitor).
     *
     * @param array{q?: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function topupWhere(array $filters): array
    {
        $query  = trim((string) ($filters['q'] ?? ''));
        $where  = ['1 = 1'];
        $params = [];

        if ($query !== '') {
            $like     = '%' . like_escape($query) . '%';
            $where[]  = '(a.username LIKE ? OR r.username LIKE ? OR r.full_name LIKE ? OR t.note LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    public static function allTopupCount(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::topupWhere($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM ewallet_admin_topups t
               JOIN users a ON a.id = t.admin_id
               JOIN users r ON r.id = t.recipient_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function allTopups(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::topupWhere($filters);

        $sql = 'SELECT t.id, t.admin_id, t.recipient_id, t.amount, t.note, t.created_at,
                       a.username AS admin_username,
                       r.username AS recipient_username,
                       r.full_name AS recipient_name
                  FROM ewallet_admin_topups t
                  JOIN users a ON a.id = t.admin_id
                  JOIN users r ON r.id = t.recipient_id
                 WHERE ' . $where . '
                 ORDER BY t.id DESC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Admin monitor
     * ---------------------------------------------------------- */

    /**
     * @param array{q?: string, funded?: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function memberWhere(array $filters): array
    {
        $query  = trim((string) ($filters['q'] ?? ''));
        $where  = ['1 = 1'];
        $params = [];

        if ($query !== '') {
            $like     = '%' . like_escape($query) . '%';
            $where[]  = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ((string) ($filters['funded'] ?? '') === '1') {
            $where[] = 'ewallet_balance > 0';
        }

        return [implode(' AND ', $where), $params];
    }

    public static function memberCount(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::memberWhere($filters);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Members with their two buckets, richest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function members(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::memberWhere($filters);

        $sql = 'SELECT id, username, full_name, email, role, member_status, is_active,
                       ewallet_balance, withdrawable_balance,
                       (SELECT MAX(l.created_at) FROM ewallet_ledger l WHERE l.user_id = users.id) AS last_movement_at
                  FROM users
                 WHERE ' . $where . '
                 ORDER BY ewallet_balance DESC, username ASC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Totals for the monitor cards (plan §5.8: float, withdrawable vs
     * locked, plus today's movement).
     *
     * @return array{
     *   total: float, withdrawable: float, locked: float, funded_members: int,
     *   topups: int, topup_total: float, topups_today: int, topup_today_total: float,
     *   credits_today: float, debits_today: float
     * }
     */
    public static function monitorTotals(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $wallet = $pdo->query(
            "SELECT COALESCE(SUM(ewallet_balance), 0) AS total,
                    COALESCE(SUM(withdrawable_balance), 0) AS withdrawable,
                    COUNT(CASE WHEN ewallet_balance > 0 THEN 1 END) AS funded_members
               FROM users"
        )->fetch() ?: ['total' => 0, 'withdrawable' => 0, 'funded_members' => 0];

        $topups = $pdo->query(
            "SELECT COUNT(*) AS topup_count,
                    COALESCE(SUM(amount), 0) AS topup_total,
                    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS topups_today,
                    COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS topup_today_total
               FROM ewallet_admin_topups"
        )->fetch() ?: [
            'topup_count' => 0, 'topup_total' => 0, 'topups_today' => 0, 'topup_today_total' => 0,
        ];

        $today = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credits,
                    COALESCE(SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END), 0) AS debits
               FROM ewallet_ledger
              WHERE DATE(created_at) = CURDATE()"
        )->fetch() ?: ['credits' => 0, 'debits' => 0];

        $total        = round((float) $wallet['total'], 2);
        $withdrawable = round((float) $wallet['withdrawable'], 2);

        return [
            'total'             => $total,
            'withdrawable'      => $withdrawable,
            'locked'            => round($total - $withdrawable, 2),
            'funded_members'    => (int) $wallet['funded_members'],
            'topups'            => (int) $topups['topup_count'],
            'topup_total'       => round((float) $topups['topup_total'], 2),
            'topups_today'      => (int) $topups['topups_today'],
            'topup_today_total' => round((float) $topups['topup_today_total'], 2),
            'credits_today'     => round((float) $today['credits'], 2),
            'debits_today'      => round((float) $today['debits'], 2),
        ];
    }
}
