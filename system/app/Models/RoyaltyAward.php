<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyAward — the monthly money ledger (Phase 19C)
 *  (plan §12.5, §13.3 `royalty_awards`, §14.2, §14.5)
 *
 *  One row per member per evaluated month. `UNIQUE (period, user_id)`
 *  is the money anchor (D-08), so a retried close is a no-op even if a
 *  caller races the service.
 *
 *  Only Supervisor, Manager and Director get a row (rank_level 2–4):
 *  Members and Leaders earn no Royalty and have no zero row (D-01,
 *  plan §12.2). A due member who cannot be paid gets a `skipped` row
 *  with a stable reason, so the ledger explains missing money
 *  (plan §13.3).
 *
 *  Status machine (plan §12.6.2):
 *
 *      pending    evaluated, not in a wallet yet (no ledger_id)
 *      paid       the withdrawable wallet was credited (ledger_id and
 *                 credited_at are set by markPaid())
 *      skipped    due but never paid (reason set, amount 0.00)
 *
 *  The only update ever applied is `pending → paid` plus `ledger_id`
 *  and `credited_at`. Amount, rank, GS, PS, quota and rates are
 *  immutable after the insert (plan §15.2, D-13): a closed month is a
 *  financial record and there is no reopen.
 *
 *  Reads are shared by 19D (the admin ledger), 19E (the member
 *  statement) and `RoyaltyService::reconcile()`; this file ships the
 *  whole read API they need so no later phase invents a second one.
 * ============================================================== */

final class RoyaltyAward
{
    /** Values `royalty_awards.status` accepts. */
    public const STATUSES = ['pending', 'paid', 'skipped'];

    /** Admin labels (plan Appendix C.8). */
    public const STATUS_LABELS = [
        'pending' => 'Awaiting payment',
        'paid'    => 'Paid',
        'skipped' => 'Skipped',
    ];

    /** Member-facing labels (plan Appendix C.8). */
    public const MEMBER_STATUS_LABELS = [
        'pending' => 'Awaiting payment',
        'paid'    => 'Paid',
        'skipped' => 'Not paid',
    ];

    /** Badge overrides for status_badge() (plan Appendix C.8). */
    public const STATUS_BADGES = [
        'pending' => ['Awaiting payment', 'warning'],
        'paid'    => ['Paid', 'success'],
        'skipped' => ['Not paid', 'secondary'],
    ];

    /** The stable skipped reasons (plan §13.3, Appendix C.7). */
    public const REASON_QUOTA      = 'group sales below quota';
    public const REASON_INACTIVE   = 'account is not active';
    public const REASON_MEMBERSHIP = 'membership is not active';
    public const REASON_ZERO       = 'zero amount';

    public const REASONS = [
        self::REASON_QUOTA,
        self::REASON_INACTIVE,
        self::REASON_MEMBERSHIP,
        self::REASON_ZERO,
    ];

    /** The shortest display name of each paying rank, for the ledger note. */
    private const RANK_NOTE = [
        RoyaltyRank::SUPERVISOR => 'Supervisor',
        RoyaltyRank::MANAGER    => 'Manager',
        RoyaltyRank::DIRECTOR   => 'Director',
    ];

    /** The default filters the 19D ledger and the 19E statement share. */
    public const FILTER_DEFAULTS = [
        'period' => '',
        'status' => '',
        'rank'   => '',
        'q'      => '',
    ];

    /** The most rows one CSV export returns (plan §15.1). */
    public const EXPORT_LIMIT = 5000;

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /**
     * One award row, or null.
     *
     * @return array<string, mixed>|null
     */
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
     * Every award row of one period, highest rank then highest amount
     * first — the order the admin ledger renders (plan Appendix D).
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsForPeriod(string $period, ?PDO $pdo = null): array
    {
        if (!RoyaltyPeriod::isPeriod($period)) {
            return [];
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            self::select()
            . ' WHERE a.period = ? ORDER BY a.rank_level DESC, a.amount DESC, a.user_id ASC'
        );
        $stmt->execute([trim($period)]);

        return $stmt->fetchAll();
    }

    /**
     * One member's row for one period, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function forMemberPeriod(int $userId, string $period, ?PDO $pdo = null): ?array
    {
        if ($userId <= 0 || !RoyaltyPeriod::isPeriod($period)) {
            return null;
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(self::select() . ' WHERE a.user_id = ? AND a.period = ? LIMIT 1');
        $stmt->execute([$userId, trim($period)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * One member's recent rows, newest period first (the member
     * statement; plan §14.11 `history`).
     *
     * @return list<array<string, mixed>>
     */
    public static function historyForUser(int $userId, int $limit = 12, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo   = $pdo ?? db();
        $limit = max(1, min(120, $limit));

        $stmt = $pdo->prepare(
            self::select() . ' WHERE a.user_id = ? ORDER BY a.period DESC, a.id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * The pending awards of one period, locked, in the wallet-lock
     * order: ascending `user_id`, then `id` (plan §14.8).
     *
     * A plain read of `royalty_awards` (no join): `FOR UPDATE` must lock
     * the award rows only, never the member rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingForPeriod(string $period, int $limit = 500, ?PDO $pdo = null): array
    {
        if (!RoyaltyPeriod::isPeriod($period)) {
            return [];
        }

        $pdo   = $pdo ?? db();
        $limit = max(1, min(5000, $limit));

        $stmt = $pdo->prepare(
            "SELECT * FROM royalty_awards
              WHERE period = ? AND status = 'pending'
              ORDER BY user_id ASC, id ASC
              LIMIT " . $limit . '
              FOR UPDATE'
        );
        $stmt->execute([trim($period)]);

        return $stmt->fetchAll();
    }

    /** How many awards of one period are still pending? */
    public static function pendingCount(string $period, ?PDO $pdo = null): int
    {
        if (!RoyaltyPeriod::isPeriod($period)) {
            return 0;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM royalty_awards WHERE period = ? AND status = 'pending'"
        );
        $stmt->execute([trim($period)]);

        return (int) $stmt->fetchColumn();
    }

    /* ----------------------------------------------------------
     *  Totals (plan Appendix D)
     * ---------------------------------------------------------- */

    /**
     * The header's own arithmetic, recomputed from the rows.
     *
     * `award_total` is pending + paid (the money the month awarded);
     * `paid_total` is what already reached a wallet.
     *
     * @return array{
     *   rows:int, paid_rows:int, pending_rows:int, skipped_rows:int,
     *   paid_total:float, pending_total:float, award_total:float
     * }
     */
    public static function totalsForPeriod(string $period, ?PDO $pdo = null): array
    {
        $empty = [
            'rows'          => 0,
            'paid_rows'     => 0,
            'pending_rows'  => 0,
            'skipped_rows'  => 0,
            'paid_total'    => 0.0,
            'pending_total' => 0.0,
            'award_total'   => 0.0,
        ];

        if (!RoyaltyPeriod::isPeriod($period)) {
            return $empty;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS rows_,
                    COUNT(CASE WHEN status = 'paid'    THEN 1 END) AS paid_rows,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_rows,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped_rows,
                    COALESCE(SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END), 0) AS paid_total,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_total,
                    COALESCE(SUM(CASE WHEN status IN ('pending','paid') THEN amount ELSE 0 END), 0) AS award_total
               FROM royalty_awards
              WHERE period = ?"
        );
        $stmt->execute([trim($period)]);
        $row = $stmt->fetch() ?: [];

        return [
            'rows'          => (int) ($row['rows_'] ?? 0),
            'paid_rows'     => (int) ($row['paid_rows'] ?? 0),
            'pending_rows'  => (int) ($row['pending_rows'] ?? 0),
            'skipped_rows'  => (int) ($row['skipped_rows'] ?? 0),
            'paid_total'    => round((float) ($row['paid_total'] ?? 0), 2),
            'pending_total' => round((float) ($row['pending_total'] ?? 0), 2),
            'award_total'   => round((float) ($row['award_total'] ?? 0), 2),
        ];
    }

    /**
     * One member's lifetime totals (the member card; plan §14.11).
     *
     * @return array{
     *   rows:int, paid_rows:int, pending_rows:int, skipped_rows:int,
     *   paid_total:float, pending_total:float, last_paid:?array{period:string, amount:float}
     * }
     */
    public static function totalsForUser(int $userId, ?PDO $pdo = null): array
    {
        $empty = [
            'rows'          => 0,
            'paid_rows'     => 0,
            'pending_rows'  => 0,
            'skipped_rows'  => 0,
            'paid_total'    => 0.0,
            'pending_total' => 0.0,
            'last_paid'     => null,
        ];

        if ($userId <= 0) {
            return $empty;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS rows_,
                    COUNT(CASE WHEN status = 'paid'    THEN 1 END) AS paid_rows,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_rows,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped_rows,
                    COALESCE(SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END), 0) AS paid_total,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_total
               FROM royalty_awards
              WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        $last = $pdo->prepare(
            "SELECT period, amount FROM royalty_awards
              WHERE user_id = ? AND status = 'paid'
              ORDER BY period DESC LIMIT 1"
        );
        $last->execute([$userId]);
        $lastRow = $last->fetch();

        return [
            'rows'          => (int) ($row['rows_'] ?? 0),
            'paid_rows'     => (int) ($row['paid_rows'] ?? 0),
            'pending_rows'  => (int) ($row['pending_rows'] ?? 0),
            'skipped_rows'  => (int) ($row['skipped_rows'] ?? 0),
            'paid_total'    => round((float) ($row['paid_total'] ?? 0), 2),
            'pending_total' => round((float) ($row['pending_total'] ?? 0), 2),
            'last_paid'     => $lastRow === false
                ? null
                : ['period' => (string) $lastRow['period'], 'amount' => round((float) $lastRow['amount'], 2)],
        ];
    }

    /**
     * One period's exclusive rank breakdown (the admin card).
     *
     * @return array<int, array{rank:int, name:string, rows:int, paid:int, skipped:int, amount:float}>
     */
    public static function rankBreakdown(string $period, ?PDO $pdo = null): array
    {
        $breakdown = [];

        foreach (RoyaltyRank::PAYING as $level) {
            $breakdown[$level] = [
                'rank'    => $level,
                'name'    => RoyaltyRank::NAMES[$level],
                'rows'    => 0,
                'paid'    => 0,
                'skipped' => 0,
                'amount'  => 0.0,
            ];
        }

        if (!RoyaltyPeriod::isPeriod($period)) {
            return $breakdown;
        }

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare(
            "SELECT rank_level,
                    COUNT(*) AS rows_,
                    COUNT(CASE WHEN status = 'paid'    THEN 1 END) AS paid,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped,
                    COALESCE(SUM(CASE WHEN status IN ('pending','paid') THEN amount ELSE 0 END), 0) AS amount
               FROM royalty_awards
              WHERE period = ?
              GROUP BY rank_level"
        );
        $stmt->execute([trim($period)]);

        foreach ($stmt->fetchAll() as $row) {
            $level = (int) $row['rank_level'];

            if (!isset($breakdown[$level])) {
                continue;
            }

            $breakdown[$level]['rows']    = (int) $row['rows_'];
            $breakdown[$level]['paid']    = (int) $row['paid'];
            $breakdown[$level]['skipped'] = (int) $row['skipped'];
            $breakdown[$level]['amount']  = round((float) $row['amount'], 2);
        }

        return $breakdown;
    }

    /* ----------------------------------------------------------
     *  Filters, pages and exports (built for 19D/19E)
     * ---------------------------------------------------------- */

    /** @return array{period:string, status:string, rank:string, q:string} */
    public static function filterDefaults(): array
    {
        return self::FILTER_DEFAULTS;
    }

    /**
     * Validate and normalise the ledger filters: a malformed month,
     * status or rank is dropped rather than trusted.
     *
     * @param array<string, mixed> $raw
     * @return array{period:string, status:string, rank:string, q:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::FILTER_DEFAULTS;

        $period = trim((string) ($raw['period'] ?? ''));

        if (RoyaltyPeriod::isPeriod($period)) {
            $filters['period'] = $period;
        }

        $status = strtolower(trim((string) ($raw['status'] ?? '')));

        if (in_array($status, self::STATUSES, true)) {
            $filters['status'] = $status;
        }

        $rank = (int) ($raw['rank'] ?? 0);

        if (in_array($rank, RoyaltyRank::PAYING, true)) {
            $filters['rank'] = (string) $rank;
        }

        $filters['q'] = mb_substr(trim((string) ($raw['q'] ?? '')), 0, 80);

        return $filters;
    }

    /** Awards of one period, filtered, paged (the admin ledger). */
    public static function countForPeriod(string $period, array $filters = [], ?PDO $pdo = null): int
    {
        return self::countWhere($period, $filters, null, $pdo);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function pageForPeriod(string $period, array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere($period, $filters, null, $pager, $pdo);
    }

    /** One member's awards, filtered, counted (the member statement). */
    public static function countForUser(int $userId, array $filters = [], ?PDO $pdo = null): int
    {
        return self::countWhere(null, $filters, $userId, $pdo);
    }

    /**
     * The closed months one member has a row for, newest first — the
     * member statement's period filter (19E).
     *
     * Deliberately narrower than `RoyaltyPeriod::periods()`: a member
     * only ever filters among months they were judged in, so the
     * statement never lists the network's whole calendar.
     *
     * @return list<string>
     */
    public static function periodsForUser(int $userId, int $limit = 36, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo   = $pdo ?? db();
        $limit = max(1, min(120, $limit));

        $stmt = $pdo->prepare(
            'SELECT DISTINCT period FROM royalty_awards WHERE user_id = ?'
            . ' ORDER BY period DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): string => (string) $row['period'],
            $stmt->fetchAll()
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function pageForUser(int $userId, array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere(null, $filters, $userId, $pager, $pdo);
    }

    /**
     * Every row a CSV export needs, newest period and highest amount
     * first, capped at EXPORT_LIMIT. `$filters['period']` narrows it to
     * one month; a null/absent period exports the whole history.
     *
     * `$userId` scopes the export to one member: 19D's admin ledger
     * passes nothing (the whole filtered ledger), while 19E's member
     * statement passes the session member so a hand-edited URL can never
     * export another member's rows (plan §9 Rule 1).
     *
     * @return list<array<string, mixed>>
     */
    public static function exportRows(array $filters = [], ?PDO $pdo = null, ?int $userId = null): array
    {
        $filters = self::normaliseFilters($filters);
        $pdo     = $pdo ?? db();

        /* A zero user id is not "no filter": it is a member who owns no
         * row, so the where-clause stays and the export is empty. */
        [$where, $params] = self::whereFor(null, $filters, $userId);
        $sql = self::select()
            . ($where === '' ? '' : ' WHERE ' . $where)
            . ' ORDER BY a.period DESC, a.rank_level DESC, a.amount DESC, a.user_id ASC'
            . ' LIMIT ' . self::EXPORT_LIMIT;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Writes (called by RoyaltyService only)
     * ---------------------------------------------------------- */

    /**
     * Insert one award row, inside the caller's transaction.
     *
     * A `pending`/`paid` row must carry positive money; a `skipped` row
     * must carry a reason and 0.00 (plan §13.3). The unique anchor
     * `(period, user_id)` is the last line of defence against a double
     * award.
     *
     * @param array<string, mixed> $draft
     * @return int the new award id
     */
    public static function create(array $draft, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?? db();

        $period = trim((string) ($draft['period'] ?? ''));

        if (!RoyaltyPeriod::isPeriod($period)) {
            throw new InvalidArgumentException('A Royalty award needs a YYYY-MM period, got "' . $period . '".');
        }

        $userId = (int) ($draft['user_id'] ?? 0);

        if ($userId <= 0) {
            throw new InvalidArgumentException('A Royalty award needs a positive user_id.');
        }

        $rank = (int) ($draft['rank_level'] ?? 0);

        if (!in_array($rank, RoyaltyRank::PAYING, true)) {
            throw new InvalidArgumentException(
                'A Royalty award is only paid to Supervisor, Manager or Director; got rank ' . $rank . '.'
            );
        }

        $status = (string) ($draft['status'] ?? 'pending');

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown Royalty award status: ' . $status);
        }

        /* Money never passes through a binary float on its way into the
         * ledger: every value is scaled to an exact decimal string in
         * integer centavos (or milli-percent) first (plan §12.8). */
        $amount      = self::money($draft['amount'] ?? 0, 2);
        $groupVolume = self::money($draft['group_volume'] ?? 0, 2);
        $personal    = self::money($draft['personal_volume'] ?? 0, 2);
        $quota       = self::money($draft['quota'] ?? 0, 2);
        $groupPct    = self::money($draft['group_pct'] ?? 0, 3);
        $personalPct = self::money($draft['personal_pct'] ?? 0, 3);
        $reason      = mb_substr(trim((string) ($draft['reason'] ?? '')), 0, 120);

        if (str_starts_with($amount, '-') || str_starts_with($groupVolume, '-')
            || str_starts_with($personal, '-') || str_starts_with($quota, '-')
            || str_starts_with($groupPct, '-') || str_starts_with($personalPct, '-')) {
            throw new InvalidArgumentException('A Royalty award cannot carry a negative money field.');
        }

        if ($status !== 'skipped' && $amount === '0.00') {
            throw new InvalidArgumentException('A pending or paid Royalty award must be greater than zero.');
        }

        if ($status === 'skipped' && $reason === '') {
            throw new InvalidArgumentException('A skipped Royalty award needs a reason.');
        }

        if ($status === 'skipped' && $amount !== '0.00') {
            throw new InvalidArgumentException('A skipped Royalty award stores 0.00.');
        }

        if (self::compareUnsigned(self::scale($personal, 2), self::scale($groupVolume, 2)) > 0) {
            throw new InvalidArgumentException('A Royalty award needs 0 <= personal_volume <= group_volume.');
        }

        if (self::compareUnsigned(self::scale($groupPct, 3), '100000') > 0
            || self::compareUnsigned(self::scale($personalPct, 3), '100000') > 0
            || self::compareUnsigned(self::scale($quota, 2), '99999999999') > 0) {
            throw new InvalidArgumentException('A Royalty award has an out-of-range quota or rate.');
        }

        $pdo->prepare(
            'INSERT INTO royalty_awards
               (period, user_id, rank_level, directs, downline_rank_count, team_size,
                group_volume, personal_volume, quota, group_pct, personal_pct,
                amount, status, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $period,
            $userId,
            $rank,
            max(0, (int) ($draft['directs'] ?? 0)),
            max(0, (int) ($draft['downline_rank_count'] ?? 0)),
            max(0, (int) ($draft['team_size'] ?? 0)),
            $groupVolume,
            $personal,
            $quota,
            $groupPct,
            $personalPct,
            $amount,
            $status,
            $reason === '' ? null : $reason,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * The only update path: point one pending award at the wallet
     * movement that paid it (plan §13.3, §14.5).
     *
     * The `AND status = 'pending'` guard makes a replayed payment a
     * no-op and gives a racing second payer zero rows (the caller
     * treats that as the one-winner condition).
     *
     * @return bool true when this call moved the row to `paid`
     */
    public static function markPaid(int $id, int $ledgerId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            "UPDATE royalty_awards
                SET status = 'paid', ledger_id = ?, credited_at = NOW()
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([max(1, $ledgerId), $id]);

        return $stmt->rowCount() === 1;
    }

    /* ----------------------------------------------------------
     *  Presentation helpers
     * ---------------------------------------------------------- */

    /** The wallet-ledger note for one award, e.g. "Royalty bonus — 2026-10 — Supervisor". */
    public static function walletNote(array $award): string
    {
        $rank = self::RANK_NOTE[(int) ($award['rank_level'] ?? 0)] ?? 'Royalty';

        return mb_substr(
            'Royalty bonus — ' . (string) ($award['period'] ?? '') . ' — ' . $rank,
            0,
            255
        );
    }

    /**
     * The member-facing reason (plan Appendix C.7). `$quota` is the
     * award's stored quota, so the copy explains the exact number it
     * was judged on.
     */
    public static function reasonLabel(string $reason, float|int|string|null $quota = null): string
    {
        return match ($reason) {
            self::REASON_QUOTA      => 'Group sales below the ' . money($quota) . ' quota',
            self::REASON_INACTIVE   => 'Your account is not active',
            self::REASON_MEMBERSHIP => 'Your membership is not active',
            self::REASON_ZERO       => 'The configured rates produced a zero award',
            default                 => $reason === '' ? '' : ucfirst($reason),
        };
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * A value as an exact decimal string with `$decimals` places,
     * rounded half-up, without ever building a binary float
     * (plan §12.8: "No float is used for a stored amount").
     *
     * The service passes centavo/milli-percent integers as strings, so
     * the common path is a pad, not a conversion.
     */
    public static function money(mixed $value, int $decimals): string
    {
        $scaled = self::scale($value, $decimals);

        $negative = str_starts_with($scaled, '-');
        $digits   = $negative ? substr($scaled, 1) : $scaled;

        if ($decimals === 0) {
            return ($negative ? '-' : '') . $digits;
        }

        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '')
            . substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals);
    }

    /** `$value × 10^$decimals` as a signed integer string (half-up). */
    private static function scale(mixed $value, int $decimals): string
    {
        $decimals = max(0, min(6, $decimals));
        $string   = trim((string) $value);

        if (preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $string, $match) !== 1) {
            /* A scientific-notation or locale string is already a
             * programming error; normalise it once and carry on. */
            $string = number_format((float) $value, $decimals, '.', '');

            if (preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $string, $match) !== 1) {
                throw new InvalidArgumentException('Not a decimal money value: "' . (string) $value . '".');
            }
        }

        $fraction = $match[3] ?? '';
        $digits   = $match[2] . str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        if (strlen($fraction) > $decimals && (int) $fraction[$decimals] >= 5) {
            $digits = self::bump($digits);
        }

        $digits = ltrim($digits, '0');

        return ($match[1] === '-' && $digits !== '' ? '-' : '') . ($digits === '' ? '0' : $digits);
    }

    /** Add one to a non-negative integer string ("199" → "200"). */
    private static function bump(string $digits): string
    {
        $index = strlen($digits) - 1;

        while ($index >= 0) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return $digits;
            }

            $digits[$index] = '0';
            $index--;
        }

        return '1' . $digits;
    }

    /**
     * Compare two non-negative integer strings without converting them
     * to floats or to a possibly overflowing int.
     */
    private static function compareUnsigned(string $left, string $right): int
    {
        $left  = ltrim($left, '0');
        $right = ltrim($right, '0');
        $left  = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;

        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return $left <=> $right;
    }

    /** The shared select, with the display joins the pages need. */
    private static function select(): string
    {
        return 'SELECT a.*, u.username, u.full_name, p.status AS period_status, p.paid_at AS period_paid_at
                  FROM royalty_awards a
                  LEFT JOIN users u ON u.id = a.user_id
                  LEFT JOIN royalty_periods p ON p.period = a.period';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(?string $period, array $filters, ?int $userId): array
    {
        $clauses = [];
        $params  = [];

        if ($period !== null) {
            $clauses[] = 'a.period = ?';
            $params[]  = $period;
        }

        if ($userId !== null) {
            $clauses[] = 'a.user_id = ?';
            $params[]  = $userId;
        }

        if ($filters['period'] !== '') {
            $clauses[] = 'a.period = ?';
            $params[]  = (string) $filters['period'];
        }

        if ($filters['status'] !== '') {
            $clauses[] = 'a.status = ?';
            $params[]  = (string) $filters['status'];
        }

        if ($filters['rank'] !== '') {
            $clauses[] = 'a.rank_level = ?';
            $params[]  = (int) $filters['rank'];
        }

        if ($filters['q'] !== '') {
            $clauses[] = '(u.username LIKE ? OR u.full_name LIKE ?)';
            $like      = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']) . '%';
            $params[]  = $like;
            $params[]  = $like;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private static function countWhere(?string $period, array $filters, ?int $userId, ?PDO $pdo = null): int
    {
        $pdo     = $pdo ?? db();
        $filters = self::normaliseFilters($filters);
        [$where, $params] = self::whereFor($period, $filters, $userId);

        $sql = 'SELECT COUNT(*)
                  FROM royalty_awards a
                  LEFT JOIN users u ON u.id = a.user_id'
            . ($where === '' ? '' : ' WHERE ' . $where);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pageWhere(?string $period, array $filters, ?int $userId, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo     = $pdo ?? db();
        $filters = self::normaliseFilters($filters);
        [$where, $params] = self::whereFor($period, $filters, $userId);

        $sql = self::select()
            . ($where === '' ? '' : ' WHERE ' . $where)
            . ' ORDER BY a.period DESC, a.rank_level DESC, a.amount DESC, a.user_id ASC'
            . ' LIMIT ' . $pager->perPage . ' OFFSET ' . $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
