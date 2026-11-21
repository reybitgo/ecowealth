<?php
declare(strict_types=1);

/* ==============================================================
 *  Impersonation — S-Login sessions and their audit trail
 *  (plan §4.2, §4.4, §7 Phase 14)
 *
 *  One row per superadmin impersonation, written when the session
 *  opens. `nonce_hash` is the SHA-256 of the URL token; the raw nonce
 *  is never persisted (and therefore can never be replayed from a
 *  database dump).
 *
 *  This model only reads and appends/marks. Opening, validating and
 *  ending a session is ImpersonationService's job.
 *
 *  Status machine:
 *
 *      active ──(Exit / sign-out)────────▶ logged_out
 *      active ──(TTL / IP-UA / target)───▶ expired
 *
 *  `mark()` only moves a row out of `active`, so a terminal status can
 *  never be overwritten by a later request.
 * ============================================================== */

final class Impersonation
{
    /** The three states of plan §4.4, in display order. */
    public const STATUSES = ['active', 'expired', 'logged_out'];

    /** A row in one of these states is finished. */
    public const TERMINAL = ['expired', 'logged_out'];

    /* ----------------------------------------------------------
     *  Writes
     * ---------------------------------------------------------- */

    /**
     * Record a newly opened session.
     *
     * @param string $nonceHash SHA-256 of the URL token (64 hex)
     * @param string $expiresAt `Y-m-d H:i:s` in the app timezone
     */
    public static function record(
        int $superadminId,
        string $superadminName,
        int $targetUserId,
        string $targetUsername,
        string $nonceHash,
        string $ip,
        string $userAgent,
        string $expiresAt,
        ?PDO $pdo = null
    ): int {
        if ($superadminId <= 0 || $targetUserId <= 0) {
            throw new InvalidArgumentException('An S-Login row needs both accounts.');
        }

        if (preg_match('/\A[0-9a-f]{64}\z/', $nonceHash) !== 1) {
            throw new InvalidArgumentException('An S-Login nonce hash must be 64 lowercase hex characters.');
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            'INSERT INTO impersonation_log
               (superadmin_id, superadmin_name, target_user_id, target_username,
                nonce_hash, ip, user_agent, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
        );
        $stmt->execute([
            $superadminId,
            mb_substr($superadminName, 0, 50),
            $targetUserId,
            mb_substr($targetUsername, 0, 50),
            $nonceHash,
            mb_substr($ip, 0, 45),
            mb_substr($userAgent, 0, 255),
            $expiresAt,
        ]);

        /* Read the id before touching the console: the revision upsert
         * runs on the same connection and would move lastInsertId(). */
        $id = (int) $pdo->lastInsertId();

        /* A new active row moves the console's active list and counts. */
        Live::touchSLogin();

        return $id;
    }

    /**
     * Move an active row to a terminal status.
     *
     * Returns true only when this call performed the transition; an
     * already-ended session stays at its first terminal status.
     */
    public static function mark(int $id, string $status, ?PDO $pdo = null): bool
    {
        if ($id <= 0 || !in_array($status, self::TERMINAL, true)) {
            return false;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            "UPDATE impersonation_log SET status = ? WHERE id = ? AND status = 'active'"
        );
        $stmt->execute([$status, $id]);

        if ($stmt->rowCount() !== 1) {
            /* Already terminal: the row did not move, so an open console
             * keeps the payload it already has. */
            return false;
        }

        /* The session left the active list and changed its recent label. */
        Live::touchSLogin();

        return true;
    }

    /**
     * Mark every active row whose TTL has passed as expired.
     *
     * Called when the console / audit list is opened: a session that
     * was never used again would otherwise still read `active` in the
     * trail even though its URL is dead. Idempotent: only a sweep that
     * moved at least one row bumps the console.
     */
    public static function expireOverdue(?PDO $pdo = null): int
    {
        $pdo ??= db();

        $swept = (int) $pdo->exec(
            "UPDATE impersonation_log SET status = 'expired' WHERE status = 'active' AND expires_at <= NOW()"
        );

        if ($swept > 0) {
            /* An open console must drop the expired tab on its next poll
             * even though no request of that tab wrote the row itself. */
            Live::touchSLogin();
        }

        return $swept;
    }

    /* ----------------------------------------------------------
     *  Single row
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::rowSelect() . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function findByNonceHash(string $hash, ?PDO $pdo = null): ?array
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::rowSelect() . ' WHERE nonce_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /* ----------------------------------------------------------
     *  Console lists
     * ---------------------------------------------------------- */

    /** Is the row past its absolute expiry? */
    public static function isPastDue(array $row, ?int $now = null): bool
    {
        $expires = strtotime((string) ($row['expires_at'] ?? ''));

        return $expires === false || $expires <= ($now ?? time());
    }

    /**
     * Sessions that are active and still inside their TTL.
     *
     * @return list<array<string, mixed>>
     */
    public static function active(int $limit = 20, ?PDO $pdo = null): array
    {
        $pdo   ??= db();
        $limit   = max(1, min(100, $limit));

        $stmt = $pdo->query(
            self::rowSelect() . "
             WHERE status = 'active' AND expires_at > NOW()
             ORDER BY id DESC
             LIMIT " . $limit
        );

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 10, ?PDO $pdo = null): array
    {
        $pdo   ??= db();
        $limit   = max(1, min(100, $limit));

        $stmt = $pdo->query(self::rowSelect() . ' ORDER BY id DESC LIMIT ' . $limit);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public static function forSuperadmin(int $superadminId, int $limit = 10, ?PDO $pdo = null): array
    {
        $pdo   ??= db();
        $limit   = max(1, min(100, $limit));

        $stmt = $pdo->prepare(
            self::rowSelect() . ' WHERE superadmin_id = ? ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$superadminId]);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Audit list filters / queries
     * ---------------------------------------------------------- */

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

        foreach (['from', 'to'] as $key) {
            $date = self::date((string) ($raw[$key] ?? ''));

            if ($date !== '') {
                $filters[$key] = $date;
            }
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
     * @param array{status:string, q:string, from:string, to:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (($filters['status'] ?? '') !== '') {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[]  = 'created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]  = 'created_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like     = '%' . like_escape($query) . '%';
            $where[]  = '(superadmin_name LIKE ? OR target_username LIKE ? OR ip LIKE ? OR user_agent LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array{status:string, q:string, from:string, to:string} $filters */
    public static function queueCount(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM impersonation_log WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{status:string, q:string, from:string, to:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function queue(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            self::rowSelect() . '
             WHERE ' . $where . '
             ORDER BY id DESC
             LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * How many sessions sit in each state (the tab badges).
     *
     * @return array{active:int, expired:int, logged_out:int, all:int, overdue:int}
     */
    public static function statusCounts(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $counts = ['active' => 0, 'expired' => 0, 'logged_out' => 0, 'all' => 0, 'overdue' => 0];

        foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM impersonation_log GROUP BY status') as $row) {
            $status = (string) $row['status'];

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['n'];
                $counts['all']  += (int) $row['n'];
            }
        }

        $counts['overdue'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM impersonation_log WHERE status = 'active' AND expires_at <= NOW()"
        )->fetchColumn();

        return $counts;
    }

    /**
     * Lifetime figures for the audit header.
     *
     * @return array{sessions:int, active:int, expired:int, logged_out:int, superadmins:int, targets:int, today:int}
     */
    public static function totals(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COUNT(*) AS sessions,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) AS active,
                    COUNT(CASE WHEN status = 'expired' THEN 1 END) AS expired,
                    COUNT(CASE WHEN status = 'logged_out' THEN 1 END) AS logged_out,
                    COUNT(DISTINCT superadmin_id) AS superadmins,
                    COUNT(DISTINCT target_user_id) AS targets,
                    COUNT(CASE WHEN created_at >= CURDATE() THEN 1 END) AS today
               FROM impersonation_log"
        )->fetch() ?: [];

        return [
            'sessions'   => (int) ($row['sessions'] ?? 0),
            'active'     => (int) ($row['active'] ?? 0),
            'expired'    => (int) ($row['expired'] ?? 0),
            'logged_out' => (int) ($row['logged_out'] ?? 0),
            'superadmins' => (int) ($row['superadmins'] ?? 0),
            'targets'    => (int) ($row['targets'] ?? 0),
            'today'      => (int) ($row['today'] ?? 0),
        ];
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private static function rowSelect(): string
    {
        return 'SELECT id, superadmin_id, superadmin_name, target_user_id, target_username,
                       nonce_hash, ip, user_agent, status, created_at, expires_at
                  FROM impersonation_log';
    }
}
