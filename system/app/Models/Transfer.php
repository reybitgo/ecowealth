<?php
declare(strict_types=1);

/* ==============================================================
 *  Transfer — read queries for member-to-member wallet transfers
 *  (plan §4.2, §5.8; Phase 12)
 *
 *  This model only reads. Every transfer is written by
 *  TransferService, which locks both accounts and moves the money
 *  through WalletService in one transaction.
 *
 *  Filters shared by the member's recent list and the admin monitor:
 *
 *      q         sender/recipient username or full name, or the note
 *      from/to   YYYY-MM-DD (inclusive, Asia/Manila)
 * ============================================================== */

final class Transfer
{
    /** @return array{q:string, from:string, to:string} */
    public static function filterDefaults(): array
    {
        return ['q' => '', 'from' => '', 'to' => ''];
    }

    /**
     * Keep only the filters the queries understand; unknown values fall
     * back to "no filter", so a hand-edited query string is harmless.
     *
     * @param array<string, mixed> $raw
     * @return array{q:string, from:string, to:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

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
     * WHERE clause shared by count, rows and totals.
     *
     * @param array{q:string, from:string, to:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (($filters['from'] ?? '') !== '') {
            $where[]  = 't.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]  = 't.created_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like     = '%' . like_escape($query) . '%';
            $where[]  = '(s.username LIKE ? OR s.full_name LIKE ? OR r.username LIKE ? OR r.full_name LIKE ? OR t.note LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::rowSelect() . ' WHERE t.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /* ----------------------------------------------------------
     *  One member's transfers (both directions)
     * ---------------------------------------------------------- */

    public static function countFor(int $userId, ?PDO $pdo = null): int
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ewallet_transfers WHERE sender_id = ? OR recipient_id = ?'
        );
        $stmt->execute([$userId, $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Recent transfers sent or received by one member, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forMember(int $userId, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $sql = self::rowSelect() . '
                 WHERE t.sender_id = ? OR t.recipient_id = ?
                 ORDER BY t.id DESC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Sent/received totals for one member (the headline under the form).
     *
     * @return array{sent:int, sent_total:float, received:int, received_total:float, fees_total:float}
     */
    public static function memberTotals(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(
            "SELECT COUNT(CASE WHEN sender_id = ? THEN 1 END) AS sent,
                    COALESCE(SUM(CASE WHEN sender_id = ? THEN amount ELSE 0 END), 0) AS sent_total,
                    COUNT(CASE WHEN recipient_id = ? THEN 1 END) AS received,
                    COALESCE(SUM(CASE WHEN recipient_id = ? THEN amount ELSE 0 END), 0) AS received_total,
                    COALESCE(SUM(CASE WHEN sender_id = ? THEN fee ELSE 0 END), 0) AS fees_total
               FROM ewallet_transfers
              WHERE sender_id = ? OR recipient_id = ?"
        );
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'sent'           => (int) ($row['sent'] ?? 0),
            'sent_total'     => round((float) ($row['sent_total'] ?? 0), 2),
            'received'       => (int) ($row['received'] ?? 0),
            'received_total' => round((float) ($row['received_total'] ?? 0), 2),
            'fees_total'     => round((float) ($row['fees_total'] ?? 0), 2),
        ];
    }

    /* ----------------------------------------------------------
     *  Admin monitor
     * ---------------------------------------------------------- */

    /**
     * @param array{q:string, from:string, to:string} $filters
     */
    public static function allCount(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM ewallet_transfers t
               JOIN users s ON s.id = t.sender_id
               JOIN users r ON r.id = t.recipient_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function all(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $sql = self::rowSelect() . '
                 WHERE ' . $where . '
                 ORDER BY t.id DESC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Totals for the filtered set (footer + stat cards).
     *
     * @param array{q:string, from:string, to:string} $filters
     * @return array{rows:int, volume:float, fees:float}
     */
    public static function totals(array $filters, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS rows_count,
                    COALESCE(SUM(t.amount), 0) AS volume,
                    COALESCE(SUM(t.fee), 0) AS fees
               FROM ewallet_transfers t
               JOIN users s ON s.id = t.sender_id
               JOIN users r ON r.id = t.recipient_id
              WHERE ' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['rows_count' => 0, 'volume' => 0, 'fees' => 0];

        return [
            'rows'   => (int) $row['rows_count'],
            'volume' => round((float) $row['volume'], 2),
            'fees'   => round((float) $row['fees'], 2),
        ];
    }

    /**
     * Lifetime transfer figures for the monitor's stat cards.
     *
     * @return array{transfers:int, transfer_volume:float, transfer_fees:float, transfers_today:int, transfer_today_volume:float}
     */
    public static function monitorTotals(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COUNT(*) AS transfer_count,
                    COALESCE(SUM(amount), 0) AS transfer_volume,
                    COALESCE(SUM(fee), 0) AS transfer_fees,
                    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS transfers_today,
                    COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS transfer_today_volume
               FROM ewallet_transfers
              WHERE status = 'completed'"
        )->fetch() ?: [];

        return [
            'transfers'             => (int) ($row['transfer_count'] ?? 0),
            'transfer_volume'       => round((float) ($row['transfer_volume'] ?? 0), 2),
            'transfer_fees'         => round((float) ($row['transfer_fees'] ?? 0), 2),
            'transfers_today'       => (int) ($row['transfers_today'] ?? 0),
            'transfer_today_volume' => round((float) ($row['transfer_today_volume'] ?? 0), 2),
        ];
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private static function rowSelect(): string
    {
        return 'SELECT t.id, t.sender_id, t.recipient_id, t.amount, t.fee, t.net_amount,
                       t.note, t.status, t.created_at,
                       s.username AS sender_username,
                       s.full_name AS sender_name,
                       r.username AS recipient_username,
                       r.full_name AS recipient_name
                  FROM ewallet_transfers t
                  JOIN users s ON s.id = t.sender_id
                  JOIN users r ON r.id = t.recipient_id';
    }
}
