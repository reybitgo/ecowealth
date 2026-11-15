<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyPeriod — the monthly payout header (Phase 19C)
 *  (plan §12.6, §13.2 `royalty_periods`, §14.2)
 *
 *  A Royalty period is a calendar month in Asia/Manila, written
 *  `YYYY-MM`, with half-open bounds `[start, end)`. This model owns
 *  the calendar arithmetic and the reads of `royalty_periods`; it
 *  writes nothing. `RoyaltyService` is the only writer of the header
 *  (plan §13.1, D-07).
 *
 *  The month helpers deliberately duplicate `UnilevelCycle`'s
 *  (§19C deliverable: the two calendar implementations are tested
 *  against each other) rather than sharing one, because the two
 *  streams must be able to change independently.
 *
 *  The baseline (plan §12.6.6):
 *
 *      royalty_ledger_since = 2026-11-15 10:00:00  → first payable 2026-12
 *      royalty_ledger_since = 2026-12-01 00:00:00  → first payable 2026-12
 *
 *  i.e. the month of the baseline when it is exactly the first
 *  instant of that month, otherwise the month after it. Volume before
 *  the baseline is never paid (D-15). A missing or malformed baseline
 *  is conservatively treated as "this month", so nothing before the
 *  current month is payable until the operator fixes the setting —
 *  a broken baseline must never trigger a retroactive payout.
 *
 *  States (plan §12.6.2):
 *
 *      evaluated ──pay pending──▶ paid
 *      skipped   (stream off at evaluation; final, no award rows)
 *
 *  There is no reopen, no void and no recalculate (plan §2.5).
 * ============================================================== */

final class RoyaltyPeriod
{
    /** Values `royalty_periods.status` accepts. */
    public const STATUSES = ['evaluated', 'paid', 'skipped'];

    /** The statuses a month can never leave (plan §12.6.2). */
    public const FINAL = ['paid', 'skipped'];

    /** Admin labels (plan Appendix C.8). */
    public const STATUS_LABELS = [
        'evaluated' => 'Evaluated — awaiting payment',
        'paid'      => 'Paid',
        'skipped'   => 'Skipped (stream off)',
    ];

    /** Badge overrides for status_badge() (plan Appendix C.8). */
    public const STATUS_BADGES = [
        'evaluated' => ['Evaluated', 'info'],
        'paid'      => ['Paid', 'success'],
        'skipped'   => ['Skipped', 'secondary'],
    ];

    /** The period note written when the stream is off (plan §12.6.5). */
    public const NOTE_STREAM_OFF = 'royalty stream off';

    /** The furthest a pendingPeriods() walk will go (10 years of months). */
    private const MAX_WALK = 240;

    /* ----------------------------------------------------------
     *  Calendar arithmetic
     * ---------------------------------------------------------- */

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

    /** The calendar month after `$period`, as 'YYYY-MM'. */
    public static function next(string $period): string
    {
        $period = self::assertPeriod($period);

        return (new DateTimeImmutable($period . '-01 00:00:00', new DateTimeZone(APP_TIMEZONE)))
            ->modify('+1 month')
            ->format('Y-m');
    }

    /**
     * The half-open bounds of a period: [start, end), in APP_TIMEZONE.
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

    /** Is this string a calendar month written YYYY-MM? */
    public static function isPeriod(string $period): bool
    {
        return preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', trim($period)) === 1;
    }

    /**
     * The first payable month, derived from `royalty_ledger_since`
     * (plan §12.6.6, D-15). Read through the caller's connection so a
     * reset or a scratch database is never resolved from the app's own
     * settings cache.
     */
    public static function baseline(?PDO $pdo = null): string
    {
        $pdo ??= db();

        $value = '';

        try {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
            $stmt->execute(['royalty_ledger_since']);
            $value = trim((string) ($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            error_log('[ecowealth] royalty ledger baseline unavailable: ' . $e->getMessage());
        }

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])-\d{2} \d{2}:\d{2}:\d{2}$/', $value, $match) !== 1) {
            /* No usable baseline: pay nothing before the current month.
             * The doctor and the QA walk surface the missing key; a
             * retroactive payout is the one failure mode to avoid. */
            return self::periodOf();
        }

        $month = $match[1] . '-' . $match[2];

        return $value === $month . '-01 00:00:00' ? $month : self::next($month);
    }

    /* ----------------------------------------------------------
     *  Reads (plan §14.2)
     * ---------------------------------------------------------- */

    /** Has the month a header already? (a closed month is immutable) */
    public static function exists(string $period, ?PDO $pdo = null): bool
    {
        return self::find($period, $pdo) !== null;
    }

    /** `evaluated` | `paid` | `skipped`, or null when no header exists. */
    public static function status(string $period, ?PDO $pdo = null): ?string
    {
        $header = self::find($period, $pdo);

        return $header === null ? null : (string) $header['status'];
    }

    /**
     * One period header, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $period, ?PDO $pdo = null): ?array
    {
        if (!self::isPeriod($period)) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare('SELECT * FROM royalty_periods WHERE period = ? LIMIT 1');
        $stmt->execute([trim($period)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Period headers, newest first (the admin period card).
     *
     * @return list<array<string, mixed>>
     */
    public static function periods(int $limit = 12, ?PDO $pdo = null): array
    {
        $pdo   ??= db();
        $limit   = max(1, min(120, $limit));

        return $pdo->query(
            'SELECT * FROM royalty_periods ORDER BY period DESC LIMIT ' . $limit
        )->fetchAll();
    }

    /**
     * Every month from the baseline through **last month** that has no
     * header yet, oldest first (plan §12.6.6).
     *
     * A fresh install whose baseline is this month returns [] — the job
     * never tries to close the partial install month (D-15).
     *
     * @return list<string>
     */
    public static function pendingPeriods(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $first = self::baseline($pdo);
        $last  = self::previous(self::periodOf());

        if ($first > $last) {
            return [];
        }

        $closed = [];

        foreach ($pdo->query('SELECT period FROM royalty_periods')->fetchAll(PDO::FETCH_COLUMN) as $period) {
            $closed[(string) $period] = true;
        }

        $pending = [];
        $cursor  = $first;
        $guard   = 0;

        while ($cursor <= $last && $guard++ < self::MAX_WALK) {
            if (!isset($closed[$cursor])) {
                $pending[] = $cursor;
            }

            $cursor = self::next($cursor);
        }

        return $pending;
    }

    /**
     * Months whose header is `evaluated` (a payment to resume or
     * finish), oldest first.
     *
     * @return list<string>
     */
    public static function evaluatedPeriods(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $rows = $pdo->query(
            "SELECT period FROM royalty_periods WHERE status = 'evaluated' ORDER BY period ASC"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    /**
     * Everything a run must touch, oldest first: the evaluated months
     * (payment to resume) and the months with no header at all.
     *
     * @return list<string>
     */
    public static function duePeriods(?PDO $pdo = null): array
    {
        $periods = array_merge(self::evaluatedPeriods($pdo), self::pendingPeriods($pdo));

        $unique = array_values(array_unique($periods));
        sort($unique, SORT_STRING);

        return $unique;
    }

    /** The most recently paid month, or null (plan §14.10 doctor). */
    public static function lastPaid(?PDO $pdo = null): ?string
    {
        $pdo ??= db();

        $value = $pdo->query(
            "SELECT MAX(period) FROM royalty_periods WHERE status = 'paid'"
        )->fetchColumn();

        return $value === false || $value === null || (string) $value === '' ? null : (string) $value;
    }

    /** Human label for a status (the admin card; safe with a null). */
    public static function label(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'Not evaluated';
        }

        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** Validate 'YYYY-MM' or throw (a bad period is a programming error). */
    private static function assertPeriod(string $period): string
    {
        $period = trim($period);

        if (!self::isPeriod($period)) {
            throw new InvalidArgumentException(
                'A Royalty period must be a calendar month written YYYY-MM, got "' . $period . '".'
            );
        }

        return $period;
    }
}
