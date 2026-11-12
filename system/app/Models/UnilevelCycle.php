<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelCycle — the monthly Active clock (17C)
 *  (plan §12.7, §13.2 `unilevel_activity` / `unilevel_periods`,
 *   §14.2, §14.8)
 *
 *  The reference model's two-clock rule:
 *
 *      The flag used during month M is decided from the personal
 *      purchases of month M−1. A member with no flag yet is Active.
 *
 *  The snapshot is written by close(): one `unilevel_activity` row per
 *  account for the period, and one `unilevel_periods` header that
 *  makes the period authoritative and immutable. Nothing else ever
 *  writes those two tables — a purchase never does.
 *
 *  Resolution order at award time (isActive() / activeMap()):
 *
 *      period closed + activity row           → that row's qualified
 *      period closed + no row (joined later)   → Active
 *      period open   + order activity          → live aggregate
 *      period open   + no order activity       → Active
 *
 *  The live fallback is what makes the 00:00–00:15 window (and a missed
 *  cron) correct without a second implementation: the close writes the
 *  same aggregate this method computes (plan §12.7).
 *
 *  The scheduled job that calls close() is 17F; the method here is
 *  complete and tested by 17C (P17C-T44 … T47).
 * ============================================================== */

final class UnilevelCycle
{
    /** The calendar month a datetime belongs to, in the app timezone. */
    public static function periodOf(?string $datetime = null): string
    {
        $zone = new DateTimeZone(APP_TIMEZONE);

        try {
            $date = new DateTimeImmutable($datetime === null ? 'now' : $datetime, $zone);
        } catch (Throwable) {
            $date = new DateTimeImmutable('now', $zone);
        }

        return $date->format('Y-m');
    }

    /** The calendar month before `$period`, as 'YYYY-MM'. */
    public static function previous(string $period): string
    {
        $period = self::assertPeriod($period);

        return (new DateTimeImmutable($period . '-01 00:00:00', new DateTimeZone(APP_TIMEZONE)))
            ->modify('-1 month')
            ->format('Y-m');
    }

    /**
     * The half-open bounds of a period: [start, end).
     *
     * @return array{0: string, 1: string} 'Y-m-d H:i:s' strings
     */
    public static function bounds(string $period): array
    {
        $period = self::assertPeriod($period);

        $start = $period . '-01 00:00:00';
        $end   = (new DateTimeImmutable($start, new DateTimeZone(APP_TIMEZONE)))
            ->modify('+1 month')
            ->format('Y-m-d H:i:s');

        return [$start, $end];
    }

    /** Has the month already been closed? (immutable once true) */
    public static function isClosed(string $period, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM unilevel_periods WHERE period = ?');
        $stmt->execute([trim($period)]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /* ----------------------------------------------------------
     *  The Active resolution (plan §12.7)
     * ---------------------------------------------------------- */

    /**
     * Is one account Active for `$period`?
     *
     * A missing row means Active; an open previous month is computed
     * live from the same aggregate the close writes.
     */
    public static function isActive(int $userId, string $period, ?PDO $pdo = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $map = self::activeMap([$userId], $period, $pdo);

        return (bool) ($map[$userId] ?? true);
    }

    /**
     * The same answer for many accounts with no N+1: a closed previous
     * period is one query (header + snapshots), an open one adds a
     * single aggregate. Absent accounts are Active.
     *
     * @param list<int|string> $userIds
     * @return array<int, bool>
     */
    public static function activeMap(array $userIds, string $period, ?PDO $pdo = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $pdo ??= db();

        $previous  = self::previous($period);
        $threshold = self::threshold();

        /* One query answers both "was the previous month closed?" and
         * "what did the snapshot say for each of these accounts?". When
         * the period is not closed, the left join returns one row with
         * both sides null and the live aggregate below decides. */
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $pdo->prepare(
            'SELECT p.period AS closed_period, a.user_id AS activity_user, a.qualified
               FROM (SELECT ? AS period) x
               LEFT JOIN unilevel_periods p ON p.period = x.period
               LEFT JOIN unilevel_activity a
                      ON a.period = x.period AND a.user_id IN (' . $marks . ')'
        );
        $stmt->execute(array_merge([$previous], $ids));
        $rows = $stmt->fetchAll();

        $closed     = false;
        $snapshots  = [];

        foreach ($rows as $row) {
            if (($row['closed_period'] ?? null) !== null) {
                $closed = true;
            }

            if (($row['activity_user'] ?? null) !== null) {
                $snapshots[(int) $row['activity_user']] = (bool) ((int) $row['qualified'] === 1);
            }
        }

        $map = array_fill_keys($ids, true);

        if ($closed) {
            foreach ($ids as $id) {
                $map[$id] = $snapshots[$id] ?? true;
            }

            return $map;
        }

        /* The previous month is open: compute its personal purchases
         * live, exactly as close() would. A member with no order rows
         * at all stays Active (a missing flag means Active). */
        [$start, $end] = self::bounds($previous);

        $spentStmt = $pdo->prepare(
            'SELECT o.user_id, SUM(oi.line_total) AS spent
               FROM shop_orders o
               JOIN shop_order_items oi ON oi.order_id = o.id
              WHERE o.user_id IN (' . $marks . ')
                AND o.paid_at >= ? AND o.paid_at < ?
                AND o.status <> \'cancelled\'
              GROUP BY o.user_id'
        );
        $spentStmt->execute(array_merge($ids, [$start, $end]));

        foreach ($spentStmt->fetchAll() as $row) {
            $map[(int) $row['user_id']] = round((float) $row['spent'], 2) >= $threshold;
        }

        return $map;
    }

    /** The Active threshold in force right now (₱500 by default). */
    public static function threshold(): float
    {
        return round(max(0.0, setting_float('unilevel_active_threshold', 500.0)), 2);
    }

    /**
     * One member's paid personal purchases in `$period`.
     *
     * Shipping and the bonus itself are excluded by construction: the
     * total is the sum of the order lines, and cancelled orders never
     * count (plan §12.7).
     */
    public static function personalPurchases(int $userId, string $period, ?PDO $pdo = null): float
    {
        $value = self::personalPurchasesOrNull($userId, $period, $pdo);

        return $value === null ? 0.0 : $value;
    }

    /** @return float|null null when the member placed no counted order */
    private static function personalPurchasesOrNull(int $userId, string $period, ?PDO $pdo = null): ?float
    {
        if ($userId <= 0) {
            return null;
        }

        $pdo ??= db();
        [$start, $end] = self::bounds($period);

        $stmt = $pdo->prepare(
            "SELECT SUM(oi.line_total)
               FROM shop_orders o
               JOIN shop_order_items oi ON oi.order_id = o.id
              WHERE o.user_id = ?
                AND o.paid_at >= ? AND o.paid_at < ?
                AND o.status <> 'cancelled'"
        );
        $stmt->execute([$userId, $start, $end]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * The member's two-clock summary for the member pages (17E, §12.7).
     *
     * Both lines travel together, because the member page explains
     * them together and must never show one without the other:
     *
     *      this month's flag  ← last month's purchases
     *      next month's flag  ← this month's purchases
     *
     * `active` is the same resolution the engine uses (§12.7), so the
     * page cannot promise a status the payout path disagrees with.
     * `previous_source` says where last month's line came from:
     * `snapshot` (a closed month's stored row), `missing` (a closed
     * month without a row for this account → Active), or `live` (the
     * month is still open, so the aggregate is computed live).
     *
     * @return array{
     *   period:string, previous:string, next:string, active:bool,
     *   previous_purchases:float, previous_seen:bool, previous_source:string,
     *   current_purchases:float, threshold:float, next_active:bool,
     *   progress:int, remaining:float, requires_active:bool
     * }
     */
    public static function memberStatus(int $userId, ?PDO $pdo = null): array
    {
        $pdo       = $pdo ?? db();
        $period    = self::periodOf();
        $previous  = self::previous($period);
        $threshold = self::threshold();

        $map    = self::activeMap([$userId], $period, $pdo);
        $active = (bool) ($map[$userId] ?? true);

        $closed   = self::isClosed($previous, $pdo);
        $snapshot = null;

        if ($userId > 0) {
            $stmt = $pdo->prepare(
                'SELECT qualified FROM unilevel_activity WHERE period = ? AND user_id = ? LIMIT 1'
            );
            $stmt->execute([$previous, $userId]);
            $value = $stmt->fetchColumn();

            if ($value !== false) {
                $snapshot = (int) $value === 1;
            }
        }

        $lastMonth  = self::personalPurchasesOrNull($userId, $previous, $pdo);
        $thisMonth  = self::personalPurchasesOrNull($userId, $period, $pdo);
        $previousSeen = $lastMonth !== null;
        $lastMonth    = $lastMonth ?? 0.0;
        $thisMonth    = $thisMonth ?? 0.0;
        $nextActive   = $thisMonth >= $threshold;

        $progress = $threshold <= 0
            ? 100
            : (int) max(0, min(100, floor(($thisMonth / $threshold) * 100)));

        $source = match (true) {
            !$closed           => 'live',
            $snapshot === null => 'missing',
            default            => 'snapshot',
        };

        return [
            'period'             => $period,
            'previous'           => $previous,
            'next'               => self::next($period),
            'active'             => $active,
            'previous_purchases' => round($lastMonth, 2),
            'previous_seen'      => $previousSeen,
            'previous_source'    => $source,
            'current_purchases'  => round($thisMonth, 2),
            'threshold'          => $threshold,
            'next_active'        => $nextActive,
            'progress'           => $progress,
            'remaining'          => round(max(0.0, $threshold - $thisMonth), 2),
            'requires_active'    => setting_bool('unilevel_requires_active_upline', true),
        ];
    }

    /* ----------------------------------------------------------
     *  The close (plan §14.8)
     * ---------------------------------------------------------- */

    /**
     * Close one month: snapshot every account, then write the header.
     *
     * One transaction. A closed period is immutable and a second run
     * writes nothing (`closed = 0`). A future period is refused with an
     * InvalidArgumentException. The award total is a reporting
     * snapshot; it never drives a payout.
     *
     * @return array{
     *   closed:int, skipped:bool, reason:?string, period:string,
     *   members:int, qualified:int, award_total:float, threshold:float
     * }
     */
    public static function close(string $period, ?int $closedBy = null, ?PDO $pdo = null): array
    {
        $period = self::assertPeriod($period);
        $pdo    = $pdo ?? db();

        $empty = [
            'closed'      => 0,
            'skipped'     => true,
            'reason'      => null,
            'period'      => $period,
            'members'     => 0,
            'qualified'   => 0,
            'award_total' => 0.0,
            'threshold'   => self::threshold(),
        ];

        if (self::isClosed($period, $pdo)) {
            $empty['reason'] = 'already closed';

            return $empty;
        }

        if ($period > self::periodOf()) {
            throw new InvalidArgumentException(
                'The period ' . $period . ' is in the future; only a completed month can be closed.'
            );
        }

        [$start, $end] = self::bounds($period);
        $threshold     = self::threshold();

        $owns = !$pdo->inTransaction();

        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            /* All accounts get a row, including members with zero
             * purchases: after the first close every account has an
             * explicit flag, so "missing = Active" only covers a member
             * who joined later (plan §14.8). */
            $pdo->prepare(
                'INSERT INTO unilevel_activity
                    (period, user_id, personal_purchases, qualified, evaluated_at)
                 SELECT ?, u.id, COALESCE(t.spent, 0.00),
                        CASE WHEN COALESCE(t.spent, 0.00) >= ? THEN 1 ELSE 0 END,
                        NOW()
                   FROM users u
                   LEFT JOIN (
                         SELECT o.user_id, SUM(oi.line_total) AS spent
                           FROM shop_orders o
                           JOIN shop_order_items oi ON oi.order_id = o.id
                          WHERE o.paid_at >= ? AND o.paid_at < ?
                            AND o.status <> \'cancelled\'
                          GROUP BY o.user_id
                   ) t ON t.user_id = u.id'
            )->execute([$period, $threshold, $start, $end]);

            $counts = $pdo->prepare(
                'SELECT COUNT(*) AS members, COALESCE(SUM(qualified), 0) AS qualified
                   FROM unilevel_activity WHERE period = ?'
            );
            $counts->execute([$period]);
            $row = $counts->fetch() ?: [];

            $awardStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM unilevel_awards
                  WHERE status = 'credited' AND paid_at >= ? AND paid_at < ?"
            );
            $awardStmt->execute([$start, $end]);
            $awardTotal = round((float) $awardStmt->fetchColumn(), 2);

            $members   = (int) ($row['members'] ?? 0);
            $qualified = (int) ($row['qualified'] ?? 0);

            /* The header goes last: the period becomes closed only when
             * its rows exist (one primary key, no upsert — one winner). */
            $pdo->prepare(
                'INSERT INTO unilevel_periods
                    (period, closed_at, closed_by, threshold, members_total, qualified_count, award_total)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?)'
            )->execute([
                $period,
                $closedBy !== null && $closedBy > 0 ? $closedBy : null,
                number_format($threshold, 2, '.', ''),
                $members,
                $qualified,
                number_format($awardTotal, 2, '.', ''),
            ]);

            if ($owns) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        Live::touch(['admin.commissions', 'admin.dashboard']);

        return [
            'closed'      => 1,
            'skipped'     => false,
            'reason'      => null,
            'period'      => $period,
            'members'     => $members,
            'qualified'   => $qualified,
            'award_total' => $awardTotal,
            'threshold'   => $threshold,
        ];
    }

    /* ----------------------------------------------------------
     *  History (17D / 17F)
     * ---------------------------------------------------------- */

    /**
     * Every month from the ledger baseline through last month that has
     * not been closed, oldest first.
     *
     * A fresh install whose baseline is this month returns [] — the job
     * never tries to close years of empty months (plan §10 Rule 2).
     *
     * @return list<string>
     */
    public static function pendingPeriods(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();

        $since = '';
        $stmt  = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
        $stmt->execute(['unilevel_ledger_since']);
        $since = trim((string) ($stmt->fetchColumn() ?: ''));

        $first = preg_match('/^\d{4}-\d{2}/', $since) === 1
            ? substr($since, 0, 7)
            : self::previous(self::periodOf());

        $last = self::previous(self::periodOf());

        if ($first > $last) {
            return [];
        }

        $closed = [];
        foreach ($pdo->query('SELECT period FROM unilevel_periods')->fetchAll(PDO::FETCH_COLUMN) as $period) {
            $closed[(string) $period] = true;
        }

        $pending = [];
        $cursor  = $first;
        $guard   = 0;

        while ($cursor <= $last && $guard++ < 240) {
            if (!isset($closed[$cursor])) {
                $pending[] = $cursor;
            }

            $cursor = self::next($cursor);
        }

        return $pending;
    }

    /** The most recently closed period, or null. */
    public static function lastClosed(?PDO $pdo = null): ?string
    {
        $pdo = $pdo ?? db();

        $value = $pdo->query('SELECT MAX(period) FROM unilevel_periods')->fetchColumn();

        return $value === false || $value === null || (string) $value === '' ? null : (string) $value;
    }

    /**
     * Closed periods, newest first (the admin period card).
     *
     * @return list<array<string, mixed>>
     */
    public static function periods(int $limit = 6, ?PDO $pdo = null): array
    {
        $pdo   = $pdo ?? db();
        $limit = max(1, min(120, $limit));

        return $pdo->query(
            'SELECT * FROM unilevel_periods ORDER BY period DESC LIMIT ' . $limit
        )->fetchAll();
    }

    /**
     * One member's recent snapshots, newest first. `closed` tells the
     * page whether the row is authoritative or an open-period fallback
     * has not been written yet.
     *
     * @return list<array<string, mixed>>
     */
    public static function activityFor(int $userId, int $months = 3, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo    = $pdo ?? db();
        $months = max(1, min(36, $months));

        $stmt = $pdo->prepare(
            'SELECT a.*, (p.period IS NOT NULL) AS is_closed
               FROM unilevel_activity a
               LEFT JOIN unilevel_periods p ON p.period = a.period
              WHERE a.user_id = ?
              ORDER BY a.period DESC
              LIMIT ' . $months
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** Validate 'YYYY-MM' or throw (a bad period is a programming error). */
    private static function assertPeriod(string $period): string
    {
        $period = trim($period);

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException('A period must be a calendar month written YYYY-MM, got "' . $period . '".');
        }

        return $period;
    }

    /** The calendar month after `$period`, as 'YYYY-MM'. */
    private static function next(string $period): string
    {
        return (new DateTimeImmutable($period . '-01 00:00:00', new DateTimeZone(APP_TIMEZONE)))
            ->modify('+1 month')
            ->format('Y-m');
    }
}
