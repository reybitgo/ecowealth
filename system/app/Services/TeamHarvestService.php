<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestService — the Team Harvest money engine (Phase 20D)
 *  (plan §8, §12.7, §12.9, §13.8, §14 in full)
 *
 *  THE ONLY WRITER of Team Harvest money. One public entry point moves
 *  it — `evaluate()` — and every other caller reaches it through
 *  `onQualified()` (the registration hook) or `evaluateAll()` (the
 *  on-demand catch-up job).
 *
 *  The award rule (plan §12.7, §14.5):
 *
 *      for L = 1 .. max_levels:
 *          n < threshold(L)                 → break  (nothing deeper is complete)
 *          threshold(L) <= baseline_n       → write a `baseline` row, never pay (D-07)
 *          an award row exists for (m, L)   → skip   (a level pays once, ever)
 *          amount(L) = 0                    → skip   (a zero level is a switch, §12.6)
 *          otherwise                        → insert `credited`, credit the
 *                                             withdrawable wallet, link the movement
 *
 *  The evaluation is **state-based, not event-based** (D-19): it compares
 *  the maintained counter `n` with the ledger, so a missed event, a
 *  rolled-back transaction, a repaired counter or a late enablement all
 *  converge to the same answer on the next run. Idempotency has three
 *  layers (§14.6): the `has()` pre-check, `UNIQUE (user_id, level)` and
 *  the wallet's own `(user_id, ref_type, ref_id, type)` anchor.
 *
 *  The transaction boundary is the caller's (§14.7): inside a
 *  registration the engine joins the registration's transaction (a
 *  failure rolls the whole registration back); standalone it opens one
 *  transaction per evaluation batch. Nothing is swallowed — a wallet
 *  failure, a missing member or a CHECK violation propagates.
 *
 *  Recipients are sorted ascending before any wallet lock is taken, so
 *  two batches that share a member cannot deadlock (§14.8), and the
 *  counter has already moved before any money is written.
 *
 *  This file also ships the read APIs the later phases render:
 *  `statusFor()` (the card payload, §14.12), `openLiability()` (the
 *  number every operator surface shows) and `reconcile()` (§14.13).
 * ============================================================== */

final class TeamHarvestService
{
    /** The one switch (D-09). */
    public const ENABLED_KEY = 'team_harvest_enabled';

    /** The audit action the catch-up job writes (§15.4). */
    public const AUDIT_CATCHUP = 'team_harvest.catchup';

    /**
     * The per-level states `statusFor()` reports (plan Appendix C.7
     * plus the one window the engine creates itself, `complete`).
     *
     *      paid           a `credited` row exists
     *      baseline       a `baseline` row exists (complete before go-live)
     *      complete       the block is full and payable, but no row has
     *                     been written yet — the stream was off, or the
     *                     catch-up has not run since it was switched on
     *      beyond_depth   complete, but above `team_harvest_max_levels`
     *      no_amount      complete but the level's amount is zero
     *      in_progress    the block is not full yet
     */
    public const LEVEL_PAID         = 'paid';
    public const LEVEL_BASELINE     = 'baseline';
    public const LEVEL_COMPLETE     = 'complete';
    public const LEVEL_BEYOND_DEPTH = 'beyond_depth';
    public const LEVEL_NO_AMOUNT    = 'no_amount';
    public const LEVEL_IN_PROGRESS  = 'in_progress';

    /** Admin labels (plan Appendix C.7). */
    public const LEVEL_LABELS = [
        self::LEVEL_PAID         => 'Paid',
        self::LEVEL_BASELINE     => 'Before go-live',
        self::LEVEL_COMPLETE     => 'Complete — not paid yet',
        self::LEVEL_BEYOND_DEPTH => 'Complete — beyond the configured depth',
        self::LEVEL_NO_AMOUNT    => 'Complete — no amount configured',
        self::LEVEL_IN_PROGRESS  => 'In progress',
    ];

    /** Member labels: the same five ideas, without the admin vocabulary. */
    public const LEVEL_MEMBER_LABELS = [
        self::LEVEL_PAID         => 'Paid',
        self::LEVEL_BASELINE     => 'Before go-live',
        self::LEVEL_COMPLETE     => 'Complete — payment pending',
        self::LEVEL_BEYOND_DEPTH => 'Complete — not paying at this depth',
        self::LEVEL_NO_AMOUNT    => 'Complete — no amount configured',
        self::LEVEL_IN_PROGRESS  => 'In progress',
    ];

    /**
     * The two exclusions every member matrix card states, verbatim and in
     * one place (20F Rule 3, Appendix C.3). The admin panel explains the
     * rules in its own words; this sentence belongs to the member card.
     */
    public const EXCLUSIONS_COPY = 'Only members who qualify after you count. '
        . 'Direct members who are not qualified do not count.';

    /**
     * The purchases footnote, said once on the matrix card and nowhere
     * else (20F Rule 6, Appendix C.3). It is the question every member
     * asks first, so it is answered plainly and not repeated.
     */
    public const PURCHASES_COPY = 'This bonus does not depend on any purchase — yours or your teammates\'.';

    /**
     * The all-or-nothing sentence (20F Rule 5, §12.5 Rule 4): a progress
     * bar is information, never a pro-rata promise.
     */
    public const ALL_OR_NOTHING_COPY = 'A level pays its amount once, when its block is full — '
        . 'a partial block pays nothing.';

    /**
     * The go-live footnote a member with a pre-go-live level reads
     * (20F Rule 8). `%s` is the start date, `%s` again the start date is
     * not repeated: one sentence, one date.
     */
    public const BASELINE_COPY = 'The plan started paying on %s. A level that was already complete then '
        . 'is recorded as Before go-live and is not paid.';

    /** How many roster rows statusFor() embeds when a caller asks for them. */
    public const STATUS_ROSTER_LIMIT = 100;

    private PDO $pdo;
    private WalletService $wallet;
    private HarvestQueue $queue;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo    = $pdo ?? db();
        $this->wallet = new WalletService($this->pdo);
        $this->queue  = new HarvestQueue($this->pdo);
    }

    /* ----------------------------------------------------------
     *  State (plan §14.2)
     * ---------------------------------------------------------- */

    /** Is the stream allowed to pay? Off writes nothing; the queue grows (D-09). */
    public static function enabled(): bool
    {
        return TeamHarvestLevel::enabled();
    }

    /** Has the queue been seeded? An empty baseline is a hard refusal (D-04). */
    public function seeded(): bool
    {
        return $this->queue->seeded();
    }

    /** The go-live instant (`team_harvest_ledger_since`), or null while unseeded. */
    public function baseline(): ?string
    {
        return $this->queue->baseline();
    }

    /* ----------------------------------------------------------
     *  The registration hook (plan §8, §12.7, §14.3)
     * ---------------------------------------------------------- */

    /**
     * One registration: qualify the sponsor when this event opens their
     * gate, then evaluate every member whose counter moved.
     *
     * The newly qualified sponsor is evaluated too (on the live path
     * their `n` is 0, so it is a cheap no-op, but the engine is
     * state-based and never assumes it). Every **queued ancestor** with
     * a smaller position had its counter incremented by
     * `HarvestQueue::qualifySponsor()` and is therefore exactly the set
     * that can have just completed a level (§1.2's "evaluate every A").
     *
     * Runs inside the registration's transaction: a failure propagates
     * and the registration rolls back (Rule 1, §14.7).
     *
     * @return array<string, mixed> `qualified`, `position`, `replayed` plus evaluate()'s result
     */
    public function onQualified(int $sponsorId): array
    {
        $position = $this->queue->qualifySponsor($sponsorId);

        if ($position === null) {
            /* Nothing qualified and no counter moved: there is nothing to
             * evaluate. `replayed` says so — a second call for the same
             * qualification writes nothing and throws nothing (P20D-T12). */
            return [
                'qualified' => false,
                'position'  => null,
                'replayed'  => true,
                'off'       => !self::enabled(),
                'paid'      => 0,
                'baseline'  => 0,
                'written'   => 0,
                'amount'    => 0.0,
                'members'   => 0,
                'levels'    => [],
            ];
        }

        $members = [$sponsorId];

        foreach ($this->queue->ancestorsOf($sponsorId) as $ancestor) {
            if ((int) $ancestor['position'] < $position) {
                $members[] = (int) $ancestor['user_id'];
            }
        }

        return ['qualified' => true, 'position' => $position, 'replayed' => false]
            + $this->evaluate($members);
    }

    /* ----------------------------------------------------------
     *  Evaluate (plan §14.5)
     * ---------------------------------------------------------- */

    /**
     * Decide and pay every level that just became payable for these
     * members.
     *
     * Order of the guards is the plan's: the switch first (off is a
     * normal answer, `off = true`), then the baseline (unseeded is a
     * hard refusal), then the recipients. The ids are sorted ascending
     * **before** any lock, which is the deadlock rule of §14.8.
     *
     * @param list<int> $userIds
     * @return array{off:bool, paid:int, baseline:int, written:int, amount:float,
     *               members:int, skipped:int, levels:list<array<string, mixed>>}
     */
    public function evaluate(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));

        sort($userIds, SORT_NUMERIC);

        if (!self::enabled()) {
            return self::evaluation(off: true);
        }

        if (!$this->seeded()) {
            throw TeamHarvestException::unseeded();
        }

        if ($userIds === []) {
            return self::evaluation();
        }

        $owns = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $this->evaluateBatch($userIds);

            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        /* After the commit (or after the batch's writes, when we joined a
         * caller's transaction): the surfaces that show a new award. A
         * live bump can never throw, so it can never roll money back
         * (§14.7). A replay writes nothing and moves no revision — an
         * open tab must not redraw for a change that did not happen. */
        if ($result['written'] > 0) {
            Live::touchTeamHarvestAdmin();

            foreach (array_keys($result['touched']) as $userId) {
                Live::touchTeamHarvestMember((int) $userId);
            }
        }

        unset($result['touched']);

        return $result;
    }

    /**
     * Evaluate every queued member, `$limit` at a time, one transaction
     * per batch (§14.7). Used by `bin/team_harvest_catchup.php`.
     *
     * @return array{off:bool, paid:int, baseline:int, written:int, amount:float,
     *               members:int, skipped:int, batches:int, levels:list<array<string, mixed>>}
     */
    public function evaluateAll(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));

        if (!self::enabled()) {
            return self::evaluation(off: true) + ['batches' => 0];
        }

        if (!$this->seeded()) {
            throw TeamHarvestException::unseeded();
        }

        $ids = array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->pdo->query('SELECT user_id FROM harvest_queue ORDER BY id')->fetchAll()
        );

        $total = self::evaluation();
        $batches = 0;

        foreach (array_chunk($ids, $limit) as $chunk) {
            $result = $this->evaluate($chunk);
            $batches++;

            $total['paid']     += $result['paid'];
            $total['baseline'] += $result['baseline'];
            $total['written']  += $result['written'];
            $total['amount']    = round($total['amount'] + $result['amount'], 2);
            $total['members']  += $result['members'];
            $total['skipped']  += $result['skipped'];
            $total['levels']    = array_merge($total['levels'], $result['levels']);
        }

        $total['batches'] = $batches;

        return $total;
    }

    /* ----------------------------------------------------------
     *  The member status payload (plan §14.12, built for 20E/20F)
     * ---------------------------------------------------------- */

    /**
     * Everything a member card or an admin panel needs, in one call:
     * the position, the matrix's ten levels with their state, the next
     * level and its progress, the paid and baselined totals, and what
     * the engine would pay for this member if it ran now.
     *
     * `$rosterLimit` embeds a page of the teammate roster (one extra
     * query). It defaults to 0 because the plan's budget for this call
     * is ≤ 5 queries; a card that renders the roster asks for it
     * explicitly.
     *
     * @return array<string, mixed>
     */
    public function statusFor(int $userId, int $rosterLimit = 0, int $rosterOffset = 0): array
    {
        $amounts    = TeamHarvestLevel::amounts($this->pdo);
        $maxLevels  = TeamHarvestLevel::maxLevels();
        $enabled    = self::enabled();
        $seeded     = $this->seeded();
        $baseline   = $this->baseline();
        $row        = $userId > 0 ? $this->queue->row($userId) : null;
        $decided    = $userId > 0 ? TeamHarvestAward::levelMap($userId, $this->pdo) : [];
        $n          = $row === null ? 0 : (int) $row['teammates'];
        $baselineN  = $row === null ? 0 : (int) $row['baseline_n'];
        $paidCents  = 0;
        $baseCents  = 0;
        $openCents  = 0;
        $levels     = [];
        $paidLevels = 0;
        $baseLevels = 0;

        for ($level = TeamHarvestLevel::LEVEL_MIN; $level <= TeamHarvestLevel::LEVEL_MAX; $level++) {
            $slots     = TeamHarvestLevel::slots($level);
            $threshold = TeamHarvestLevel::threshold($level);
            $start     = $threshold - $slots;
            $amount    = round((float) ($amounts[$level] ?? 0), 2);
            $filled    = max(0, min($slots, $n - $start));
            $complete  = $n >= $threshold;
            $status    = (string) ($decided[$level] ?? '');
            $state     = self::LEVEL_IN_PROGRESS;

            if ($status === TeamHarvestAward::STATUS_CREDITED) {
                $state = self::LEVEL_PAID;
                $paidLevels++;
                $paidCents += self::cents($amount);
            } elseif ($status === TeamHarvestAward::STATUS_BASELINE) {
                $state = self::LEVEL_BASELINE;
                $baseLevels++;
                $baseCents += self::cents($amount);
            } elseif ($complete && $level > $maxLevels) {
                $state = self::LEVEL_BEYOND_DEPTH;
            } elseif ($complete && self::cents($amount) <= 0) {
                $state = self::LEVEL_NO_AMOUNT;
            } elseif ($complete && $threshold > $baselineN) {
                $state = self::LEVEL_COMPLETE;
                $openCents += self::cents($amount);
            }

            $levels[$level] = [
                'level'     => $level,
                'slots'     => $slots,
                'start'     => $start,
                'threshold' => $threshold,
                'amount'    => $amount,
                'filled'    => $filled,
                'remaining' => max(0, $threshold - $n),
                'complete'  => $complete,
                'paid'      => $status === TeamHarvestAward::STATUS_CREDITED,
                'baseline'  => $status === TeamHarvestAward::STATUS_BASELINE,
                'beyond_depth' => $complete && $level > $maxLevels,
                'status'    => $state,
                'label'     => self::LEVEL_LABELS[$state],
                'progress'  => $slots > 0 ? round(100 * $filled / $slots, 1) : 0.0,
            ];
        }

        /* A member who never qualified has no matrix to progress: `next`
         * is null until the queue holds them (20F's "not qualified" card
         * shows the gate, not a promise). */
        $next = $row === null ? [] : TeamHarvestLevel::statusForNext($n, $maxLevels);

        if ($next !== []) {
            $next['amount'] = round((float) ($amounts[$next['level']] ?? 0), 2);
        } else {
            $next = null;
        }

        $roster = [];

        if ($row !== null && $rosterLimit > 0) {
            $roster = $this->queue->teamRoster($userId, $rosterLimit, max(0, $rosterOffset));
        }

        return [
            'queued'                    => $row !== null,
            'position'                  => $row === null ? null : (int) $row['position'],
            'qualified_at'              => $row === null ? null : (string) $row['qualified_at'],
            'directs_at_qualification'  => $row === null ? null : (int) $row['directs_at_qualification'],
            'directs'                   => $this->queue->directs($userId),
            'teammates'                 => $n,
            'baseline_n'                => $baselineN,
            'seeded'                    => $seeded,
            'baseline'                  => $baseline,
            'enabled'                   => $enabled,
            'max_levels'                => $maxLevels,
            'gate'                      => TeamHarvestLevel::qualifyDirects(),
            'levels'                    => $levels,
            'next'                      => $next,
            'paid_total'                => self::decimal($paidCents),
            'paid_amount'               => round($paidCents / 100, 2),
            'credited_levels'           => $paidLevels,
            'baseline_total'            => self::decimal($baseCents),
            'baseline_amount'           => round($baseCents / 100, 2),
            'baseline_levels'           => $baseLevels,
            'open'                      => self::decimal($openCents),
            'matrix'                    => [
                'positions'   => $n,
                'roster_page' => $roster,
            ],
        ];
    }

    /* ----------------------------------------------------------
     *  The matrix roster (plan §12.5, 20F's card)
     * ---------------------------------------------------------- */

    /**
     * One page of the member's private 3×10 matrix: the teammates who
     * occupy the slots, with each slot's level and the member it is
     * placed under (§12.5).
     *
     * The 20F card formats this and never computes it (Rule 9): the
     * arithmetic — `floor(log3(2r+3))` for the level, `r / 3 - 1` for the
     * placement — lives in `HarvestQueue::matrix()`, the one place the
     * plan names.
     *
     * A member who is not queued has no matrix and reads an empty page;
     * the card shows the gate instead (T6).
     *
     * `$teammates` and `$position` are `statusFor()`'s own values when the
     * caller already has them: passing them saves the two selects the
     * queue model would otherwise repeat for the same row (§14.9).
     *
     * @return list<array{slot:int, level:int, start:int, slots:int, position:int,
     *                    user_id:int, username:string, qualified_at:string,
     *                    placed_under:array{user_id:int, username:string, position:int}}>
     */
    public function matrixFor(
        int $userId,
        int $offset = 0,
        int $limit = 100,
        ?int $teammates = null,
        ?int $position = null
    ): array {
        if ($userId <= 0) {
            return [];
        }

        $row = $teammates === null || $position === null
            ? null
            : ['teammates' => max(0, $teammates), 'position' => max(0, $position)];

        return $this->queue->matrix($userId, max(0, $offset), $limit, $row);
    }

    /* ----------------------------------------------------------
     *  The open liability (plan §14.12)
     * ---------------------------------------------------------- */

    /**
     * What the engine would pay if every outstanding level of every
     * queued member were evaluated right now: complete, above the
     * baseline, within the configured depth, amount above zero, and no
     * award row yet. Derived on read and never stored, because it moves
     * with every qualification.
     *
     * @return array{levels:int, members:int, amount:float,
     *               by_level:array<int, array{level:int, count:int, amount:float}>}
     */
    public function openLiability(): array
    {
        $amounts   = TeamHarvestLevel::amounts($this->pdo);
        $maxLevels = TeamHarvestLevel::maxLevels();
        $rows      = $this->pdo->query('SELECT user_id, teammates, baseline_n FROM harvest_queue')->fetchAll();
        $decided   = [];

        foreach ($this->pdo->query('SELECT user_id, level FROM team_harvest_awards')->fetchAll() as $award) {
            $decided[(int) $award['user_id']][(int) $award['level']] = true;
        }

        $byLevel = [];

        for ($level = TeamHarvestLevel::LEVEL_MIN; $level <= TeamHarvestLevel::LEVEL_MAX; $level++) {
            $byLevel[$level] = ['level' => $level, 'count' => 0, 'amount' => 0.0];
        }

        $members = [];
        $lines   = 0;
        $cents   = 0;

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $n      = (int) $row['teammates'];
            $base   = (int) $row['baseline_n'];

            for ($level = 1; $level <= $maxLevels; $level++) {
                if ($n < TeamHarvestLevel::threshold($level)) {
                    break;
                }

                if (TeamHarvestLevel::threshold($level) <= $base) {
                    continue;   // complete before go-live: recorded, never paid (D-07)
                }

                if (isset($decided[$userId][$level])) {
                    continue;
                }

                $amountCents = self::cents($amounts[$level] ?? 0);

                if ($amountCents <= 0) {
                    continue;   // a zero level is a switch, not a payable row (§12.6)
                }

                $lines++;
                $cents += $amountCents;
                $members[$userId] = true;
                $byLevel[$level]['count']++;
                $byLevel[$level]['amount'] = round(($byLevel[$level]['amount'] * 100 + $amountCents) / 100, 2);
            }
        }

        return [
            'levels'   => $lines,
            'members'  => count($members),
            'amount'   => round($cents / 100, 2),
            'by_level' => $byLevel,
        ];
    }

    /* ----------------------------------------------------------
     *  Reconciliation (plan §14.13)
     * ---------------------------------------------------------- */

    /**
     * Finance reconciliation: read-only, reports every disagreement,
     * never repairs. `bin/wallet_reconcile.php` folds `ok` into its exit
     * code and the reset's integrity pack runs it after a reset.
     *
     * @return array<string, mixed>
     */
    public function reconcile(): array
    {
        $pdo = $this->pdo;

        if (!self::tableExists($pdo, 'team_harvest_awards')) {
            return [
                'ok'             => true,
                'awards'         => 0,
                'credited_rows'  => 0,
                'credited_total' => 0.0,
                'baseline_rows'  => 0,
                'baseline_total' => 0.0,
                'ledger_rows'    => 0,
                'ledger_total'   => 0.0,
                'queued'         => 0,
                'drift'          => 0,
                'violations'     => 0,
                'mismatches'     => [],
                'warnings'       => [],
            ];
        }

        $awards = $pdo->query(
            "SELECT COUNT(*) AS awards,
                    COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_rows,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline_rows,
                    COALESCE(SUM(CASE WHEN status = 'baseline' THEN amount ELSE 0 END), 0) AS baseline_total
               FROM team_harvest_awards"
        )->fetch() ?: [];

        $ledger = $pdo->query(
            "SELECT COUNT(*) AS ledger_rows, COALESCE(SUM(amount), 0) AS ledger_total
               FROM ewallet_ledger
              WHERE ref_type = 'team_harvest' AND type = 'credit'"
        )->fetch() ?: [];

        $creditedTotal = round((float) ($awards['credited_total'] ?? 0), 2);
        $ledgerTotal   = round((float) ($ledger['ledger_total'] ?? 0), 2);
        $mismatches    = [];
        $warnings      = [];

        $sameMoney = static fn (float $left, float $right): bool
            => number_format($left, 2, '.', '') === number_format($right, 2, '.', '');

        /* 1. Σ credited awards = Σ team_harvest credits (§13.8 I-07). */
        if (!$sameMoney($creditedTotal, $ledgerTotal)) {
            $mismatches[] = [
                'kind'     => 'total',
                'expected' => $creditedTotal,
                'actual'   => $ledgerTotal,
                'message'  => 'credited Team Harvest awards differ from the team_harvest wallet credits',
            ];
        }

        /* 2. Every credited award names a live movement (§13.8 I-06). */
        foreach ($pdo->query(
            "SELECT a.id, a.user_id, a.level, a.amount, a.ledger_id, u.username
               FROM team_harvest_awards a
               LEFT JOIN users u ON u.id = a.user_id
               LEFT JOIN ewallet_ledger l ON l.id = a.ledger_id AND l.ref_type = 'team_harvest'
              WHERE a.status = 'credited'
                AND (a.ledger_id IS NULL OR l.id IS NULL)
              ORDER BY a.id"
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'missing_ledger',
                'award'    => (int) $row['id'],
                'user_id'  => (int) $row['user_id'],
                'username' => (string) ($row['username'] ?? ''),
                'level'    => (int) $row['level'],
                'expected' => (float) $row['amount'],
                'actual'   => null,
                'message'  => 'a credited Team Harvest award has no wallet movement',
            ];
        }

        /* 3. No baseline row was paid (§13.8 I-06, the exit-gate assertion). */
        foreach ($pdo->query(
            "SELECT a.id, a.user_id, a.level, a.amount, a.ledger_id, u.username
               FROM team_harvest_awards a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.status = 'baseline' AND a.ledger_id IS NOT NULL
              ORDER BY a.id"
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'baseline_paid',
                'award'    => (int) $row['id'],
                'user_id'  => (int) $row['user_id'],
                'username' => (string) ($row['username'] ?? ''),
                'level'    => (int) $row['level'],
                'expected' => null,
                'actual'   => (float) $row['amount'],
                'message'  => 'a baseline (pre-go-live) award carries a wallet movement',
            ];
        }

        /* 4. Every team_harvest movement belongs to an award. */
        foreach ($pdo->query(
            "SELECT l.id, l.user_id, l.ref_id, l.amount, u.username
               FROM ewallet_ledger l
               LEFT JOIN users u ON u.id = l.user_id
               LEFT JOIN team_harvest_awards a ON a.id = l.ref_id
              WHERE l.ref_type = 'team_harvest'
                AND (l.ref_id IS NULL OR a.id IS NULL)
              ORDER BY l.id"
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'orphan_ledger',
                'ledger'   => (int) $row['id'],
                'user_id'  => (int) $row['user_id'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => null,
                'actual'   => (float) $row['amount'],
                'message'  => 'a team_harvest wallet movement belongs to no award',
            ];
        }

        /* 5. The movement and its award agree to the centavo. */
        foreach ($pdo->query(
            "SELECT a.id, a.user_id, a.level, a.amount AS award_amount, l.amount AS ledger_amount, u.username
               FROM team_harvest_awards a
               JOIN ewallet_ledger l ON l.id = a.ledger_id AND l.ref_type = 'team_harvest'
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.status = 'credited' AND l.amount <> a.amount
              ORDER BY a.id"
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'amount',
                'award'    => (int) $row['id'],
                'user_id'  => (int) $row['user_id'],
                'username' => (string) ($row['username'] ?? ''),
                'level'    => (int) $row['level'],
                'expected' => (float) $row['award_amount'],
                'actual'   => (float) $row['ledger_amount'],
                'message'  => 'the Team Harvest award and its wallet movement disagree',
            ];
        }

        /* 6. One row per (member, level) — impossible by the unique key,
         *    checked anyway (§14.13). */
        foreach ($pdo->query(
            'SELECT user_id, level, COUNT(*) AS rows_
               FROM team_harvest_awards
              GROUP BY user_id, level
             HAVING COUNT(*) > 1
              LIMIT 50'
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'duplicate',
                'user_id'  => (int) $row['user_id'],
                'level'    => (int) $row['level'],
                'expected' => 1,
                'actual'   => (int) $row['rows_'],
                'message'  => 'a member holds more than one award for one level',
            ];
        }

        /* 7. No award above the ladder's ten levels — the schema's CHECK
         *    forbids it, so a row here means a damaged table. An award
         *    above the *configured depth* is a warning, not a failure: a
         *    row paid while the depth allowed it stays a fact when an
         *    operator later lowers the depth (the depth caps what may be
         *    written, never what was written — §12.6 Rule 3). */
        /* The settings are read through this service's own connection: a
         * reset constructs it on the injected target, and the global
         * setting() would read the application database instead (the same
         * rule RoyaltyService::reconcile() follows). */
        $maxLevels = (int) ($this->settingFrom('team_harvest_max_levels', (string) TeamHarvestLevel::DEPTH_DEFAULT) ?? TeamHarvestLevel::DEPTH_DEFAULT);

        if ($maxLevels < TeamHarvestLevel::DEPTH_MIN || $maxLevels > TeamHarvestLevel::DEPTH_MAX) {
            $maxLevels = TeamHarvestLevel::DEPTH_DEFAULT;
        }

        foreach ($pdo->query(
            'SELECT id, user_id, level, amount FROM team_harvest_awards WHERE level > '
                . (int) TeamHarvestLevel::LEVEL_MAX . ' LIMIT 50'
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'impossible_level',
                'award'    => (int) $row['id'],
                'user_id'  => (int) $row['user_id'],
                'level'    => (int) $row['level'],
                'expected' => TeamHarvestLevel::LEVEL_MAX,
                'actual'   => (int) $row['level'],
                'message'  => 'a Team Harvest award exists above the ten-level ladder',
            ];
        }

        if ($maxLevels < TeamHarvestLevel::LEVEL_MAX) {
            $above = (int) $pdo->query(
                'SELECT COUNT(*) FROM team_harvest_awards WHERE level > ' . (int) $maxLevels
            )->fetchColumn();

            if ($above > 0) {
                $warnings[] = [
                    'kind'    => 'above_depth',
                    'count'   => $above,
                    'message' => $above . ' award row(s) are above the configured depth of ' . $maxLevels
                        . '; they were paid while a deeper ladder was configured and stay valid',
                ];
            }
        }

        /* 8. The counters equal a from-scratch recount, and no counter is
         *    below its baseline (§13.8 I-03/I-04). */
        $queued = $pdo->query('SELECT id, user_id, teammates, baseline_n FROM harvest_queue ORDER BY id')->fetchAll();
        $counts = (new HarvestQueue($pdo))->recountAll();
        $drift  = 0;
        $violations = 0;

        foreach ($queued as $row) {
            $userId = (int) $row['user_id'];
            $stored = (int) $row['teammates'];
            $count  = $counts[$userId] ?? 0;

            if ($count !== $stored) {
                $drift++;
                $mismatches[] = [
                    'kind'     => 'counter_drift',
                    'position' => (int) $row['id'],
                    'user_id'  => $userId,
                    'expected' => $count,
                    'actual'   => $stored,
                    'message'  => 'harvest_queue.teammates differs from a from-scratch recount',
                ];
            }

            if ($stored < (int) $row['baseline_n']) {
                $violations++;
                $mismatches[] = [
                    'kind'     => 'baseline_violation',
                    'position' => (int) $row['id'],
                    'user_id'  => $userId,
                    'expected' => (int) $row['baseline_n'],
                    'actual'   => $stored,
                    'message'  => 'a queue counter is below its immutable baseline_n',
                ];
            }
        }

        /* 9. The ladder has ten rows, none negative (§13.3). */
        $ladder = $pdo->query('SELECT COUNT(*) AS rows_, COALESCE(MIN(amount), 0) AS lowest FROM team_harvest_levels')->fetch() ?: [];
        $ladderRows = (int) ($ladder['rows_'] ?? 0);

        if ($ladderRows !== TeamHarvestLevel::LEVEL_MAX || (float) ($ladder['lowest'] ?? 0) < 0) {
            $mismatches[] = [
                'kind'     => 'ladder',
                'expected' => TeamHarvestLevel::LEVEL_MAX,
                'actual'   => $ladderRows,
                'message'  => 'team_harvest_levels must hold ten non-negative rows',
            ];
        }

        /* Warnings never fail a reconciliation (§14.13). */
        $baseline = $this->settingFrom('team_harvest_ledger_since', '') ?? '';
        $enabled  = in_array(
            strtolower(trim($this->settingFrom('team_harvest_enabled', '0') ?? '0')),
            ['1', 'true', 'yes', 'on'],
            true
        );

        if (trim($baseline) === '') {
            $warnings[] = [
                'kind'    => 'unseeded',
                'message' => 'the queue is not seeded; nothing can be evaluated until bin/harvest_queue_seed.php runs',
            ];
        }

        if (!$enabled) {
            $warnings[] = [
                'kind'    => 'stream_off',
                'message' => 'the Team Harvest bonus is switched off (team_harvest_enabled = 0)',
            ];
        }

        return [
            'ok'             => $mismatches === [],
            'awards'         => (int) ($awards['awards'] ?? 0),
            'credited_rows'  => (int) ($awards['credited_rows'] ?? 0),
            'credited_total' => $creditedTotal,
            'baseline_rows'  => (int) ($awards['baseline_rows'] ?? 0),
            'baseline_total' => round((float) ($awards['baseline_total'] ?? 0), 2),
            'ledger_rows'    => (int) ($ledger['ledger_rows'] ?? 0),
            'ledger_total'   => $ledgerTotal,
            'queued'         => count($queued),
            'drift'          => $drift,
            'violations'     => $violations,
            'mismatches'     => $mismatches,
            'warnings'       => $warnings,
        ];
    }

    /* ----------------------------------------------------------
     *  Evaluation internals
     * ---------------------------------------------------------- */

    /**
     * One batch: pre-load the accounts, the queue rows, the ladder and
     * every decided level (four reads), then decide and pay in ascending
     * member order (§14.8, §14.9).
     *
     * @param list<int> $userIds sorted ascending
     * @return array<string, mixed>
     */
    private function evaluateBatch(array $userIds): array
    {
        $marks = implode(', ', array_fill(0, count($userIds), '?'));

        /* 1. The recipients: a non-member (or a deleted account) is
         *    refused rather than paid (§14.5 Rule 12). */
        $accounts = $this->pdo->prepare('SELECT id, role FROM users WHERE id IN (' . $marks . ')');
        $accounts->execute($userIds);

        $roles = [];

        foreach ($accounts->fetchAll() as $row) {
            $roles[(int) $row['id']] = (string) $row['role'];
        }

        foreach ($userIds as $userId) {
            if (($roles[$userId] ?? '') !== 'member') {
                throw TeamHarvestException::notMember($userId);
            }
        }

        /* 2. The queue rows: a member who is not queued earns nothing and
         *    is not an error (§14.5). */
        $queue = $this->pdo->prepare(
            'SELECT user_id, teammates, baseline_n, id FROM harvest_queue WHERE user_id IN (' . $marks . ')'
        );
        $queue->execute($userIds);

        $rows = [];

        foreach ($queue->fetchAll() as $row) {
            $rows[(int) $row['user_id']] = $row;
        }

        /* 3. The ladder and the depth are read once per batch. */
        $amounts   = TeamHarvestLevel::amounts($this->pdo);
        $maxLevels = TeamHarvestLevel::maxLevels();
        $baseline  = (string) $this->baseline();

        /* 4. The ledger's already-decided levels, in one query (§14.6 layer 1). */
        $decided = TeamHarvestAward::levelMapFor(array_keys($rows), $this->pdo);

        $result = self::evaluation();
        $now    = date('Y-m-d H:i:s');
        $touched = [];

        foreach ($userIds as $userId) {
            if (!isset($rows[$userId])) {
                $result['skipped']++;
                continue;
            }

            $result['members']++;

            $n      = (int) $rows[$userId]['teammates'];
            $base   = (int) $rows[$userId]['baseline_n'];
            $userDecided = $decided[$userId] ?? [];

            for ($level = 1; $level <= $maxLevels; $level++) {
                $threshold = TeamHarvestLevel::threshold($level);

                if ($n < $threshold) {
                    break;   // thresholds ascend: nothing deeper can be complete
                }

                $has = isset($userDecided[$level]);

                if ($threshold <= $base) {
                    /* Complete before go-live: recorded once, never paid
                     * (D-07). A zero amount is still recorded, so raising
                     * the ladder can never pay a pre-go-live level. */
                    if ($has) {
                        continue;
                    }

                    TeamHarvestAward::create($userId, $level, [
                        'threshold_n' => $threshold,
                        'n_at_award'  => $n,
                        'amount'      => number_format((float) ($amounts[$level] ?? 0), 2, '.', ''),
                        'status'      => TeamHarvestAward::STATUS_BASELINE,
                        'credited_at' => $baseline,
                    ], $this->pdo);

                    $userDecided[$level]   = TeamHarvestAward::STATUS_BASELINE;
                    $result['baseline']++;
                    $result['written']++;
                    $result['levels'][]    = [
                        'user_id' => $userId,
                        'level'   => $level,
                        'status'  => TeamHarvestAward::STATUS_BASELINE,
                        'amount'  => round((float) ($amounts[$level] ?? 0), 2),
                        'n'       => $n,
                    ];
                    $touched[$userId]      = true;

                    continue;
                }

                if ($has) {
                    continue;   // paid (or baselined) already: a level pays once, ever
                }

                $amountCents = self::cents($amounts[$level] ?? 0);

                if ($amountCents <= 0) {
                    continue;   // a zero level writes no row (§12.6 Rule 2)
                }

                $amount  = number_format($amountCents / 100, 2, '.', '');
                $awardId = TeamHarvestAward::create($userId, $level, [
                    'threshold_n' => $threshold,
                    'n_at_award'  => $n,
                    'amount'      => $amount,
                    'status'      => TeamHarvestAward::STATUS_CREDITED,
                    'credited_at' => $now,
                ], $this->pdo);

                /* The award is written before the credit, and the credit's
                 * ref_id is the award id (§14.7 Rule 3). */
                $movement = $this->wallet->credit(
                    $userId,
                    $amount,
                    TeamHarvestAward::REF_TYPE,
                    $awardId,
                    'Team Harvest level ' . $level . ' bonus',
                    true
                );

                $ledgerId = (int) ($movement['ledger_id'] ?? 0);

                if ($ledgerId <= 0 || !TeamHarvestAward::linkLedger($awardId, $ledgerId, $this->pdo)) {
                    throw TeamHarvestException::state(
                        'Team Harvest award #' . $awardId . ' could not be linked to its wallet movement;'
                            . ' the evaluation was rolled back.'
                    );
                }

                $userDecided[$level] = TeamHarvestAward::STATUS_CREDITED;
                $result['paid']++;
                $result['written']++;
                $result['amount'] = round($result['amount'] + $amountCents / 100, 2);
                $result['levels'][] = [
                    'user_id'  => $userId,
                    'level'    => $level,
                    'status'   => TeamHarvestAward::STATUS_CREDITED,
                    'amount'   => round($amountCents / 100, 2),
                    'n'        => $n,
                    'award_id' => $awardId,
                    'ledger_id' => $ledgerId,
                ];
                $touched[$userId] = true;
            }
        }

        $result['touched'] = $touched;

        return $result;
    }

    /** The empty evaluation result, with every key the callers read. */
    private static function evaluation(bool $off = false): array
    {
        return [
            'off'      => $off,
            'paid'     => 0,
            'baseline' => 0,
            'written'  => 0,
            'amount'   => 0.0,
            'members'  => 0,
            'skipped'  => 0,
            'levels'   => [],
        ];
    }

    /** Does one table exist? Used so reconcile() survives a partial deploy. */
    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One setting read through this service's connection, or `$default`.
     *
     * The pages and the job run on the application database, where the
     * global `setting()` cache is the right read; `reconcile()` must work
     * on an **injected** schema (the business reset's integrity pack), so
     * it reads the row it is actually checking (the same rule
     * `RoyaltyService::reconcile()` follows).
     */
    private function settingFrom(string $key, ?string $default = null): ?string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();

            return $value === false ? $default : (string) $value;
        } catch (Throwable) {
            return $default;
        }
    }

    /** Pesos → integer centavos (plan §12.9). */
    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    /** Integer centavos → the `DECIMAL(12,2)` shape the cards read. */
    private static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
