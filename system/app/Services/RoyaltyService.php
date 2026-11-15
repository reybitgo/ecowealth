<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyService — the Royalty money engine (Phase 19C)
 *  (plan §12.4–§12.10, §14.1–§14.13; the ONLY writer of this
 *   stream's money)
 *
 *  Two methods move the month, and only one of them moves money:
 *
 *      evaluate($period)   writes `royalty_periods` + `royalty_awards`
 *                          rows. NO wallet movement, ever (Rule 1).
 *      pay($period)        credits the pending awards to the
 *                          withdrawable e-wallet, in batches, and is
 *                          resumable after a crash.
 *      close($period)      evaluate() then pay() until final; an
 *                          already-evaluated month is resumed with
 *                          pay() and is never recalculated.
 *
 *  The month (plan §12.4, §12.5):
 *
 *      PS(m)  = Σ paid order line totals of m in [start, end)
 *      GS(m)  = PS(m) + Σ PS(d) for every member descendant d
 *      quota  = the rank's all-or-nothing monthly GS gate
 *      amount = round_once(group% × GS + personal% × PS)
 *
 *  Ranks are derived as of the **period end** (`users.created_at <
 *  end`, plan D-06), so a late or catch-up close reproduces the ranks
 *  an on-time run would have produced. Only Supervisor, Manager and
 *  Director get a row; a Leader is a rank, not a royalty rank (D-01).
 *
 *  Invariants this service owns:
 *
 *    • evaluation writes no wallet money; pay() is the only creditor
 *    • one award per member per period (`UNIQUE (period, user_id)`)
 *    • one rank, one triple, one rounding, no stacking (D-10, D-11)
 *    • the quota gate is all-or-nothing, in integer centavos (D-09)
 *    • the stream switch is read at evaluation, the payment switch at
 *      payment (plan §12.6.5)
 *    • no retroactive pay: the first payable month is the first full
 *      month after `royalty_ledger_since` (D-15)
 *    • a closed month is immutable: no reopen, no void, no clawback
 *      (D-13, plan §2.5)
 *    • idempotency at four layers (plan §14.6): the period PK, the
 *      award unique key, the conditional pending → paid update and the
 *      wallet's own (user_id, ref_type, ref_id, type) anchor
 *
 *  This file also ships the member status API (19E) and the finance
 *  reconciliation used by bin/wallet_reconcile.php and the reset
 *  integrity pack. No controller and no view is added (D-16).
 * ============================================================== */

final class RoyaltyService
{
    /** 100 (percent) × 1,000 (milli-percent): the one-rounding denominator. */
    private const DENOMINATOR = 100000;

    /** The largest volume in centavos whose product with a 100% rate fits int64. */
    private const MAX_SAFE_VOLUME_CENTS = 9000000000000;   // ₱90 billion

    /** Monotonic suffix for the per-call savepoint names. */
    private static int $savepointSeq = 0;

    private PDO $pdo;
    private WalletService $wallet;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo    = $pdo ?? db();
        $this->wallet = new WalletService($this->pdo);
    }

    /* ----------------------------------------------------------
     *  Switches and the ladder (plan §12.6.5, §14.2)
     * ---------------------------------------------------------- */

    /** Is the stream allowed to earn? (`royalty_enabled`, off by default) */
    public static function enabled(): bool
    {
        return setting_bool('royalty_enabled', false);
    }

    /** Is payment allowed? (`royalty_pay_enabled`, on by default) */
    public static function paymentsEnabled(): bool
    {
        return setting_bool('royalty_pay_enabled', true);
    }

    /**
     * The ladder as it is configured right now — the snapshot source
     * for a period header and every award row (D-17).
     *
     * @return array<string, mixed>
     */
    public static function ladder(): array
    {
        return RoyaltyRank::ladder();
    }

    /* ----------------------------------------------------------
     *  Preview (plan §14.11)
     * ---------------------------------------------------------- */

    /**
     * Compute a period exactly as evaluate() would, and write nothing.
     *
     * The current month may be previewed (it is the "if this month
     * closed now" panel); a future month is refused. A period that
     * already has a header returns the **stored** header and rows and
     * is never recomputed, so a preview can never disagree with the
     * ledger (plan §14.11).
     *
     * @return array<string, mixed>
     */
    public function preview(string $period): array
    {
        $period = $this->assertPeriod($period);

        $header = RoyaltyPeriod::find($period, $this->pdo);

        if ($header !== null) {
            return $this->storedResult($header);
        }

        $current = RoyaltyPeriod::periodOf();

        if ($period > $current) {
            throw RoyaltyException::future($period);
        }

        /* An open month uses the current tree; a completed month uses
         * its own end as the rank cutoff (plan §14.11). */
        $computed = $this->collect($period, $period < $current);

        return $this->computedResult($period, $computed);
    }

    /* ----------------------------------------------------------
     *  Evaluate (plan §12.6.3, §14.4)
     * ---------------------------------------------------------- */

    /**
     * Evaluate one completed month: write the header and one award row
     * per Supervisor/Manager/Director. Writes no wallet money.
     *
     * Refuses the current month, a future month and a month that
     * already has a header (a closed period is never recalculated).
     *
     * @return array<string, mixed>
     */
    public function evaluate(string $period, ?int $actorId = null): array
    {
        $period  = $this->assertPeriod($period);
        $current = RoyaltyPeriod::periodOf();

        if ($period > $current) {
            throw RoyaltyException::future($period);
        }

        if ($period === $current) {
            throw RoyaltyException::current($period);
        }

        $baseline = RoyaltyPeriod::baseline($this->pdo);

        if ($period < $baseline) {
            /* D-15: volume before the ledger baseline is never paid, and
             * a month that was never in pendingPeriods() has no header. */
            throw RoyaltyException::state(
                'The Royalty period ' . $period . ' is before the ledger baseline (' . $baseline
                    . '); months before the baseline are never paid (plan D-15).'
            );
        }

        if (RoyaltyPeriod::exists($period, $this->pdo)) {
            throw RoyaltyException::closed($period);
        }

        $ladder  = RoyaltyRank::ladder();
        $enabled = (bool) $ladder['enabled'];
        [, $end] = RoyaltyPeriod::bounds($period);

        if (!$enabled) {
            /* One transaction: a final `skipped` header, no award rows,
             * no money (plan §12.6.3 step 3, D-15). */
            $this->withTransaction(function () use ($period, $end, $ladder, $actorId): void {
                $this->writeHeader($this->headerRow(
                    period: $period,
                    status: 'skipped',
                    enabled: false,
                    actorId: $actorId,
                    asOf: $end,
                    ladder: $ladder,
                    counts: ['member' => 0, 'leader' => 0, 'supervisor' => 0, 'manager' => 0, 'director' => 0, 'total' => 0],
                    awardCount: 0,
                    paidCount: 0,
                    skippedCount: 0,
                    awardTotal: '0.00',
                    paidTotal: '0.00',
                    note: RoyaltyPeriod::NOTE_STREAM_OFF
                ));
            });

            AuditLog::record([
                'action'       => 'royalty.period.skip',
                'subject_type' => 'royalty_period',
                'subject_id'   => null,
                'actor_id'     => $actorId !== null && $actorId > 0 ? $actorId : null,
                'meta'         => [
                    'period'  => $period,
                    'enabled' => false,
                    'note'    => RoyaltyPeriod::NOTE_STREAM_OFF,
                ],
            ], $this->pdo);

            /* Phase 19D: the admin ledger shows skipped months too, so
             * writing the header must bump the surface `pay()` bumps. */
            Live::touch(['admin.commissions', 'admin.dashboard']);

            return [
                'period'        => $period,
                'as_of'         => $end,
                'enabled'       => false,
                'eligible'      => 0,
                'paid_members'  => 0,
                'skipped'       => 0,
                'award_total'   => 0.0,
                'paid_total'    => 0.0,
                'pending'       => 0,
                'pending_total' => 0.0,
                'rank_counts'   => $this->rankCountsFrom([]),
                'awards'        => [],
                'header'        => RoyaltyPeriod::find($period, $this->pdo),
                'status'        => 'skipped',
            ];
        }

        $computed = $this->collect($period, true);
        $drafts   = $computed['drafts'];
        $counts   = $computed['counts'];
        $ladder   = $computed['ladder'];

        $awardCount   = count($drafts);
        $skippedCount = 0;
        $awardCents   = 0;

        foreach ($drafts as $draft) {
            if ((string) $draft['status'] === 'skipped') {
                $skippedCount++;
                continue;
            }

            $awardCents += self::centsOf($draft['amount']);
        }

        $headerRow = $this->headerRow(
            period: $period,
            status: 'evaluated',
            enabled: true,
            actorId: $actorId,
            asOf: $end,
            ladder: $ladder,
            counts: $counts,
            awardCount: $awardCount,
            paidCount: 0,
            skippedCount: $skippedCount,
            awardTotal: self::decimalOf($awardCents),
            paidTotal: '0.00',
            note: null
        );

        try {
            $this->withTransaction(function () use ($headerRow, $drafts, $period, $actorId): void {
                /* Header first: `royalty_awards.period` is a foreign key,
                 * so the pair is atomic inside this transaction (plan
                 * §12.6.3 step 5). */
                $this->writeHeader($headerRow);

                foreach ($drafts as $draft) {
                    RoyaltyAward::create($draft, $this->pdo);
                }

                if (RoyaltyAward::pendingCount($period, $this->pdo) === 0) {
                    $this->finalise($period, $actorId);
                }
            });
        } catch (PDOException $e) {
            /* A concurrent evaluation lost the primary-key race; the
             * month is closed by the winner, not by us. */
            if ((string) $e->getCode() === '23000') {
                throw RoyaltyException::closed($period);
            }

            throw $e;
        }

        $header = RoyaltyPeriod::find($period, $this->pdo);
        $status = $header === null ? 'evaluated' : (string) $header['status'];

        AuditLog::record([
            'action'       => 'royalty.period.evaluate',
            'subject_type' => 'royalty_period',
            'subject_id'   => null,
            'actor_id'     => $actorId !== null && $actorId > 0 ? $actorId : null,
            'meta'         => [
                'period'        => $period,
                'enabled'       => true,
                'members'       => (int) $counts['total'],
                'leaders'       => (int) $counts['leader'],
                'supervisors'   => (int) $counts['supervisor'],
                'managers'      => (int) $counts['manager'],
                'directors'     => (int) $counts['director'],
                'awards'        => $awardCount,
                'skipped'       => $skippedCount,
                'award_total'   => self::decimalOf($awardCents),
                'status'        => $status,
                'ladder'        => [
                    'thresholds' => $ladder['thresholds'],
                    'quotas'     => $ladder['quotas'],
                    'rates'      => $ladder['rates'],
                ],
            ],
        ], $this->pdo);

        /* Phase 19D: the admin ledger lists evaluated months and their
         * pending rows, so an evaluation must revalidate that surface
         * even when it is not followed by a payment in this call. */
        Live::touch(['admin.commissions', 'admin.dashboard']);

        return [
            'period'        => $period,
            'as_of'         => $end,
            'enabled'       => true,
            'eligible'      => $awardCount,
            'paid_members'  => $awardCount - $skippedCount,
            'skipped'       => $skippedCount,
            'award_total'   => round($awardCents / 100, 2),
            'paid_total'    => 0.0,
            'pending'       => RoyaltyAward::pendingCount($period, $this->pdo),
            'pending_total' => round($awardCents / 100, 2),
            'rank_counts'   => $this->rankCountsFrom($counts),
            'awards'        => RoyaltyAward::rowsForPeriod($period, $this->pdo),
            'header'        => $header,
            'status'        => $status,
        ];
    }

    /* ----------------------------------------------------------
     *  Pay (plan §12.6.4, §14.5)
     * ---------------------------------------------------------- */

    /**
     * Credit the period's pending awards to the withdrawable e-wallet,
     * in ascending member order and in batches.
     *
     * One transaction per batch: a failure rolls the batch back, keeps
     * the previous batches paid and leaves the header `evaluated`, so
     * the next run resumes from the first pending row.
     *
     * @return array<string, mixed>
     */
    public function pay(string $period, int $limit = 500, int $maxBatches = 20, ?int $actorId = null): array
    {
        $period     = $this->assertPeriod($period);
        $limit      = max(1, min(5000, $limit));
        $maxBatches = max(1, min(1000, $maxBatches));

        $header = RoyaltyPeriod::find($period, $this->pdo);

        if ($header === null) {
            throw RoyaltyException::notFound($period);
        }

        $result = [
            'period'          => $period,
            'paid'            => 0,
            'amount'          => 0.0,
            'pending_left'    => RoyaltyAward::pendingCount($period, $this->pdo),
            'status'          => (string) $header['status'],
            'paused'          => false,
            'resume_required' => false,
            'batches'         => 0,
            'final'           => in_array((string) $header['status'], RoyaltyPeriod::FINAL, true),
        ];

        if ($result['status'] !== 'evaluated') {
            return $result;   // a final month is never paid again
        }

        if ($result['pending_left'] === 0) {
            /* A crash after the last batch but before finalise is healed
             * here, even when payments are paused now: no money is left
             * to move (plan §14.5). */
            $this->finalise($period, $actorId);

            AuditLog::record([
                'action'       => 'royalty.period.pay',
                'subject_type' => 'royalty_period',
                'actor_id'     => $actorId !== null && $actorId > 0 ? $actorId : null,
                'meta'         => [
                    'period'  => $period,
                    'rows'    => 0,
                    'amount'  => '0.00',
                    'pending' => 0,
                    'final'   => true,
                ],
            ], $this->pdo);

            $result['status'] = 'paid';
            $result['final']  = true;

            return $result;
        }

        if (!self::paymentsEnabled()) {
            /* The operational pause: the awards stay pending, no wallet
             * row is written (plan §12.6.5, D-12). */
            $result['paused'] = true;

            return $result;
        }

        $paid    = 0;
        $amount  = 0;   // integer centavos
        $batches = 0;

        while ($batches < $maxBatches) {
            $batch = $this->withTransaction(function () use ($period, $limit): array {
                /* Header first: one payer per period (plan §14.8). */
                $header = $this->lockHeader($period);

                if ($header === null || (string) $header['status'] !== 'evaluated') {
                    return [];
                }

                $awards = RoyaltyAward::pendingForPeriod($period, $limit, $this->pdo);

                if ($awards === []) {
                    return [];
                }

                $rows    = 0;
                $sum     = 0;
                $members = [];

                foreach ($awards as $award) {
                    /* The wallet credit is written first because its
                     * ledger id is what the award points at. If the
                     * award cannot be marked, throw: the batch rolls the
                     * credit back, so wallet and ledger never disagree
                     * (plan §14.5). */
                    $movement = $this->wallet->credit(
                        (int) $award['user_id'],
                        (float) $award['amount'],
                        'royalty',
                        (int) $award['id'],
                        RoyaltyAward::walletNote($award),
                        true
                    );

                    if (!RoyaltyAward::markPaid((int) $award['id'], (int) $movement['ledger_id'], $this->pdo)) {
                        throw new RuntimeException(sprintf(
                            'Royalty award #%d could not be marked paid; the batch was rolled back.',
                            (int) $award['id']
                        ));
                    }

                    $rows++;
                    $sum += self::centsOf($award['amount']);
                    $members[(int) $award['user_id']] = true;
                }

                /* Recompute from the rows so a resumed run cannot drift. */
                $this->refreshPaidTotals($period);

                return [
                    'rows'    => $rows,
                    'cents'   => $sum,
                    'members' => array_keys($members),
                ];
            });

            if ($batch === []) {
                break;
            }

            $batches++;
            $paid   += (int) $batch['rows'];
            $amount += (int) $batch['cents'];

            /* After the commit only: a live-touch failure never rolls
             * money back (plan §14.5). */
            $this->touch($batch['members']);

            /* One audit row per batch (plan §15.4). */
            AuditLog::record([
                'action'       => 'royalty.period.pay',
                'subject_type' => 'royalty_period',
                'actor_id'     => $actorId !== null && $actorId > 0 ? $actorId : null,
                'meta'         => [
                    'period'  => $period,
                    'batch'   => $batches,
                    'rows'    => (int) $batch['rows'],
                    'amount'  => self::decimalOf((int) $batch['cents']),
                    'pending' => RoyaltyAward::pendingCount($period, $this->pdo),
                    'final'   => false,
                ],
            ], $this->pdo);
        }

        $pending = RoyaltyAward::pendingCount($period, $this->pdo);

        $result['paid']         = $paid;
        $result['amount']       = round($amount / 100, 2);
        $result['pending_left'] = $pending;
        $result['batches']      = $batches;

        if ($pending > 0) {
            /* The batch ceiling was reached: the header stays
             * `evaluated` and the next run resumes (plan §14.5). */
            $result['resume_required'] = true;

            return $result;
        }

        $this->finalise($period, $actorId);

        AuditLog::record([
            'action'       => 'royalty.period.pay',
            'subject_type' => 'royalty_period',
            'actor_id'     => $actorId !== null && $actorId > 0 ? $actorId : null,
            'meta'         => [
                'period'  => $period,
                'batch'   => $batches,
                'rows'    => $paid,
                'amount'  => self::decimalOf($amount),
                'pending' => 0,
                'final'   => true,
            ],
        ], $this->pdo);

        $result['status'] = 'paid';
        $result['final']  = true;

        Live::touch(['admin.commissions', 'admin.dashboard']);

        return $result;
    }

    /* ----------------------------------------------------------
     *  Close (evaluate + pay; plan §14.5)
     * ---------------------------------------------------------- */

    /**
     * Close one month: evaluate it when it has no header, then pay it
     * until final. An already-`evaluated` month is resumed with pay()
     * and is never re-evaluated.
     *
     * @return array{
     *   period:string, evaluated:bool, evaluation:?array<string, mixed>,
     *   payment:?array<string, mixed>, status:string
     * }
     */
    public function close(string $period, int $limit = 500, int $maxBatches = 20, ?int $actorId = null): array
    {
        $period   = $this->assertPeriod($period);
        $status   = RoyaltyPeriod::status($period, $this->pdo);
        $evaluated = false;
        $evaluation = null;

        if ($status === null) {
            $evaluation = $this->evaluate($period, $actorId);
            $evaluated  = true;
            $status     = (string) $evaluation['status'];
        }

        if ($status === 'skipped') {
            return [
                'period'     => $period,
                'evaluated'  => $evaluated,
                'evaluation' => $evaluation,
                'payment'    => null,
                'status'     => 'skipped',
            ];
        }

        $payment = $this->pay($period, $limit, $maxBatches, $actorId);

        return [
            'period'     => $period,
            'evaluated'  => $evaluated,
            'evaluation' => $evaluation,
            'payment'    => $payment,
            'status'     => (string) $payment['status'],
        ];
    }

    /* ----------------------------------------------------------
     *  Member status (plan §14.11, built for 19E)
     * ---------------------------------------------------------- */

    /**
     * Rank, month-to-date PS/GS, quota, rates, projection and history
     * for one member.
     *
     * Uses the same `RoyaltyRank::map()` / `withVolumes()` / `awardCents()`
     * as the engine, so the member page cannot promise an amount the
     * close would not pay. When the period already has an award row,
     * the stored row wins over any recomputation.
     *
     * @return array<string, mixed>
     */
    public function statusFor(int $userId, ?string $period = null): array
    {
        $period ??= RoyaltyPeriod::periodOf();
        $period   = $this->assertPeriod($period);

        $current = RoyaltyPeriod::periodOf();
        $cutoff  = $period < $current ? RoyaltyPeriod::bounds($period)[1] : null;
        [$start, $end] = RoyaltyPeriod::bounds($period);

        $ladder  = RoyaltyRank::ladder();
        $enabled = (bool) $ladder['enabled'];
        $header  = RoyaltyPeriod::find($period, $this->pdo);

        $map = RoyaltyRank::map($cutoff, $this->pdo);
        $map = RoyaltyRank::withVolumes($map, $this->volumes($start, $end));

        $row  = $map[$userId] ?? null;
        $rank = $row === null ? RoyaltyRank::MEMBER : (int) $row['rank'];

        $triple       = $ladder['ranks'][$rank] ?? null;
        $quotaCents   = $triple !== null && $triple['quota_cents'] !== null ? (int) $triple['quota_cents'] : 0;
        $groupPct     = $triple !== null && $triple['group_pct'] !== null ? (float) $triple['group_pct'] : 0.0;
        $personalPct  = $triple !== null && $triple['personal_pct'] !== null ? (float) $triple['personal_pct'] : 0.0;

        $personalCents = $row === null ? 0 : max(0, (int) $row['volume_cents']);
        $groupCents    = $row === null ? 0 : max(0, (int) $row['group_volume_cents']);

        $stored = $header !== null ? RoyaltyAward::forMemberPeriod($userId, $period, $this->pdo) : null;

        if ($stored !== null) {
            $quotaCents    = self::centsOf($stored['quota']);
            $groupPct      = (float) $stored['group_pct'];
            $personalPct   = (float) $stored['personal_pct'];
            $personalCents = self::centsOf($stored['personal_volume']);
            $groupCents    = self::centsOf($stored['group_volume']);
        }

        $eligible = $rank >= RoyaltyRank::SUPERVISOR
            && $row !== null
            && (int) $row['is_active'] === 1
            && (string) $row['member_status'] === 'active';

        $projectedCents = 0;

        if ($stored !== null) {
            /* The stored row is the truth for a closed month: a skipped
             * row was judged and paid nothing, whatever the tree looks
             * like now (D-13). */
            $eligible       = (string) $stored['status'] !== 'skipped';
            $projectedCents = self::centsOf($stored['amount']);
        } elseif ($enabled && $eligible && $groupCents >= $quotaCents) {
            $projectedCents = self::awardCents($groupCents, $personalCents, $groupPct, $personalPct);
        }

        $step = $row === null ? null : RoyaltyRank::requirements($row);

        $progress = $quotaCents <= 0
            ? ($rank >= RoyaltyRank::SUPERVISOR && $groupCents > 0 ? 100 : 0)
            : (int) max(0, min(100, intdiv($groupCents * 100, $quotaCents)));

        $totals = RoyaltyAward::totalsForUser($userId, $this->pdo);

        return [
            'period'           => $period,
            'enabled'          => $enabled,
            'payments_enabled' => self::paymentsEnabled(),
            'rank'             => $rank,
            'rank_name'        => RoyaltyRank::NAMES[$rank],
            'directs'          => $row === null ? 0 : (int) $row['directs'],
            'downline'         => [
                'leaders'     => $row === null ? 0 : (int) $row['downline_leaders'],
                'supervisors' => $row === null ? 0 : (int) $row['downline_supervisors'],
                'managers'    => $row === null ? 0 : (int) $row['downline_managers'],
            ],
            'next'             => $step === null ? null : [
                'rank' => $step['next_rank'],
                'name' => $step['next_name'],
                'need' => $step['items'][0]['required'] ?? 0,
                'have' => $step['items'][0]['current'] ?? 0,
            ],
            'personal'         => round($personalCents / 100, 2),
            'group'            => round($groupCents / 100, 2),
            'quota'            => round($quotaCents / 100, 2),
            'group_pct'        => $groupPct,
            'personal_pct'     => $personalPct,
            'progress'         => $progress,
            'eligible'         => $eligible,
            'projected'        => round($projectedCents / 100, 2),
            'award'            => $stored,
            'paid_all_time'    => $totals['paid_total'],
            'last_paid'        => $totals['last_paid'],
            'history'          => RoyaltyAward::historyForUser($userId, 12, $this->pdo),
        ];
    }

    /* ----------------------------------------------------------
     *  Volume (plan §12.4, §14.4)
     * ---------------------------------------------------------- */

    /**
     * The period's paid line totals, one row per buyer, in centavos.
     *
     * `paid_at` decides the month; a cancelled order never counts;
     * shipping is on the order and is never included; activations,
     * top-ups and transfers are not shop order lines (plan §12.4).
     *
     * @return array<int, int> user id => own volume in cents
     */
    public function volumes(string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.user_id, COALESCE(SUM(oi.line_total), 0) AS volume
               FROM shop_orders o
               JOIN shop_order_items oi ON oi.order_id = o.id
              WHERE o.paid_at >= ? AND o.paid_at < ?
                AND o.status <> 'cancelled'
              GROUP BY o.user_id"
        );
        $stmt->execute([$start, $end]);

        $volumes = [];

        foreach ($stmt->fetchAll() as $row) {
            $volumes[(int) $row['user_id']] = max(0, self::centsOf($row['volume']));
        }

        return $volumes;
    }

    /* ----------------------------------------------------------
     *  The one rounding (plan §12.8, Appendix F)
     * ---------------------------------------------------------- */

    /**
     * `round_half_up((group_milli × GS_cents + personal_milli × PS_cents) / 100,000)`.
     *
     * One rounding on the combined amount: the group and personal
     * components are never rounded separately (D-10). Both factors are
     * non-negative, so half-up is half-away-from-zero.
     */
    public static function awardCents(
        int $groupCents,
        int $personalCents,
        float|int|string $groupPct,
        float|int|string $personalPct
    ): int {
        $groupCents    = max(0, $groupCents);
        $personalCents = max(0, $personalCents);

        if ($groupCents > self::MAX_SAFE_VOLUME_CENTS || $personalCents > self::MAX_SAFE_VOLUME_CENTS) {
            throw RoyaltyException::state(
                'The month volume is too large to price safely; check the period order totals.'
            );
        }

        $groupMilli    = self::milli($groupPct);
        $personalMilli = self::milli($personalPct);

        if ($groupMilli <= 0 && $personalMilli <= 0) {
            return 0;
        }

        $numerator = $groupMilli * $groupCents + $personalMilli * $personalCents;

        return intdiv($numerator + intdiv(self::DENOMINATOR, 2), self::DENOMINATOR);
    }

    /* ----------------------------------------------------------
     *  Reconciliation (plan §14.12)
     * ---------------------------------------------------------- */

    /**
     * Finance reconciliation, same contract as
     * `UnilevelService::reconcile()`: read-only, reports mismatches,
     * never repairs.
     *
     *   total          Σ paid awards against `ref_type='royalty'`
     *   missing ledger a paid award with no (or a dead) ledger row
     *   orphan ledger  a royalty movement with no award
     *   drift          a movement whose amount differs from its award
     *   period totals  the header's counters against its rows
     *   period state   a paid period with a pending award, a skipped
     *                  period with rows, an award without a header
     *                  (mismatches); an evaluated period with no pending
     *                  award (a warning: the next run finalises it)
     *   rank sanity    a paid award with no money, or PS > GS
     *   cycle warning  member accounts unreachable from a root
     *   legacy         royalty movements before `royalty_ledger_since`
     *                  are reported, not failed
     *
     * Warnings never turn a clean reconciliation into a failure
     * (plan §14.12).
     *
     * @return array<string, mixed>
     */
    public function reconcile(): array
    {
        $pdo = $this->pdo;

        $empty = [
            'ok'                => true,
            'periods'           => 0,
            'paid_periods'      => 0,
            'evaluated_periods' => 0,
            'skipped_periods'   => 0,
            'award_rows'        => 0,
            'paid_rows'         => 0,
            'pending_rows'      => 0,
            'skipped_rows'      => 0,
            'paid_total'        => 0.0,
            'pending_total'     => 0.0,
            'award_total'       => 0.0,
            'ledger_total'      => 0.0,
            'legacy_rows'       => 0,
            'legacy_total'      => 0.0,
            'cycle_members'     => 0,
            'mismatches'        => [],
            'warnings'          => [],
        ];

        if (!self::tableExists($pdo)) {
            return $empty;
        }

        /* The baseline is read through this service's own connection: a
         * reset constructs it on the injected target, and the global
         * setting() would read the application database instead. */
        $since = '';

        try {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
            $stmt->execute(['royalty_ledger_since']);
            $since = trim((string) ($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            error_log('[ecowealth] royalty ledger baseline unavailable: ' . $e->getMessage());
        }

        $sinceArgs = $since === '' ? [] : [$since];

        $periods = $pdo->query(
            "SELECT COUNT(*) AS periods,
                    COUNT(CASE WHEN status = 'paid'      THEN 1 END) AS paid_periods,
                    COUNT(CASE WHEN status = 'evaluated' THEN 1 END) AS evaluated_periods,
                    COUNT(CASE WHEN status = 'skipped'   THEN 1 END) AS skipped_periods
               FROM royalty_periods"
        )->fetch() ?: [];

        $awards = $pdo->query(
            "SELECT COUNT(*) AS award_rows,
                    COUNT(CASE WHEN status = 'paid'    THEN 1 END) AS paid_rows,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_rows,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped_rows,
                    COALESCE(SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END), 0) AS paid_total,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_total,
                    COALESCE(SUM(CASE WHEN status IN ('pending','paid') THEN amount ELSE 0 END), 0) AS award_total
               FROM royalty_awards"
        )->fetch() ?: [];

        $ledgerStmt = $pdo->prepare(
            "SELECT COUNT(*) AS ledger_rows, COALESCE(SUM(amount), 0) AS ledger_total
               FROM ewallet_ledger
              WHERE ref_type = 'royalty'" . ($since === '' ? '' : ' AND created_at >= ?')
        );
        $ledgerStmt->execute($sinceArgs);
        $ledger = $ledgerStmt->fetch() ?: [];

        $legacy = ['legacy_rows' => 0, 'legacy_total' => 0.0];

        if ($since !== '') {
            $legacyStmt = $pdo->prepare(
                "SELECT COUNT(*) AS legacy_rows, COALESCE(SUM(amount), 0) AS legacy_total
                   FROM ewallet_ledger
                  WHERE ref_type = 'royalty' AND created_at < ?"
            );
            $legacyStmt->execute([$since]);
            $legacy = $legacyStmt->fetch() ?: $legacy;
        }

        $paidTotal    = round((float) ($awards['paid_total'] ?? 0), 2);
        $pendingTotal = round((float) ($awards['pending_total'] ?? 0), 2);
        $ledgerTotal  = round((float) ($ledger['ledger_total'] ?? 0), 2);

        $mismatches = [];
        $warnings   = [];

        $sameMoney = static fn (float $left, float $right): bool
            => number_format($left, 2, '.', '') === number_format($right, 2, '.', '');

        /* 1. The total: paid awards = royalty movements (D-19, §13.7). */
        if (!$sameMoney($paidTotal, $ledgerTotal)) {
            $mismatches[] = [
                'kind'     => 'total',
                'expected' => $paidTotal,
                'actual'   => $ledgerTotal,
                'message'  => 'paid Royalty awards differ from the royalty ledger total',
            ];
        }

        /* 2. Every paid award has a live movement. */
        foreach ($pdo->query(
            "SELECT a.id, a.period, a.user_id, a.amount, a.ledger_id, u.username
               FROM royalty_awards a
               LEFT JOIN users u ON u.id = a.user_id
               LEFT JOIN ewallet_ledger l ON l.id = a.ledger_id AND l.ref_type = 'royalty'
              WHERE a.status = 'paid'
                AND (a.ledger_id IS NULL OR l.id IS NULL)
              ORDER BY a.id"
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'missing_ledger',
                'award'    => (int) $row['id'],
                'period'   => (string) $row['period'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => (float) $row['amount'],
                'actual'   => null,
                'message'  => 'a paid Royalty award has no wallet movement',
            ];
        }

        /* 3. Every royalty movement belongs to an award. */
        $orphanStmt = $pdo->prepare(
            "SELECT l.id, l.ref_id, l.amount, u.username
               FROM ewallet_ledger l
               LEFT JOIN users u ON u.id = l.user_id
               LEFT JOIN royalty_awards a ON a.id = l.ref_id
              WHERE l.ref_type = 'royalty'
                AND (l.ref_id IS NULL OR a.id IS NULL)"
            . ($since === '' ? '' : ' AND l.created_at >= ?') . '
              ORDER BY l.id'
        );
        $orphanStmt->execute($sinceArgs);

        foreach ($orphanStmt->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'orphan_ledger',
                'ledger'   => (int) $row['id'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => null,
                'actual'   => (float) $row['amount'],
                'message'  => 'a royalty ledger row belongs to no award',
            ];
        }

        /* 4. The movement and the award agree to the centavo. */
        foreach ($pdo->query(
            "SELECT a.id, a.period, a.amount AS award_amount, l.amount AS ledger_amount, u.username
               FROM royalty_awards a
               JOIN ewallet_ledger l ON l.id = a.ledger_id AND l.ref_type = 'royalty'
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.status = 'paid'
                AND l.amount <> a.amount
              ORDER BY a.id"
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'amount',
                'award'    => (int) $row['id'],
                'period'   => (string) $row['period'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => (float) $row['award_amount'],
                'actual'   => (float) $row['ledger_amount'],
                'message'  => 'the Royalty award and its ledger row disagree',
            ];
        }

        /* 5. The header's counters and totals equal its rows. */
        $byPeriod = [];

        foreach ($pdo->query(
            "SELECT period,
                    COUNT(*) AS rows_,
                    COUNT(CASE WHEN status = 'paid'    THEN 1 END) AS paid_rows,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped_rows,
                    COALESCE(SUM(CASE WHEN status IN ('pending','paid') THEN amount ELSE 0 END), 0) AS award_total,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid_total
               FROM royalty_awards
              GROUP BY period"
        )->fetchAll() as $row) {
            $byPeriod[(string) $row['period']] = $row;
        }

        foreach ($pdo->query('SELECT * FROM royalty_periods ORDER BY period')->fetchAll() as $header) {
            $period = (string) $header['period'];
            $rows   = $byPeriod[$period] ?? [];

            $actual = [
                'award_count'   => (int) ($rows['rows_'] ?? 0),
                'paid_count'    => (int) ($rows['paid_rows'] ?? 0),
                'skipped_count' => (int) ($rows['skipped_rows'] ?? 0),
                'award_total'   => round((float) ($rows['award_total'] ?? 0), 2),
                'paid_total'    => round((float) ($rows['paid_total'] ?? 0), 2),
            ];

            foreach (['award_count', 'paid_count', 'skipped_count'] as $column) {
                if ((int) $header[$column] !== $actual[$column]) {
                    $mismatches[] = [
                        'kind'     => 'period_count',
                        'period'   => $period,
                        'column'   => $column,
                        'expected' => $actual[$column],
                        'actual'   => (int) $header[$column],
                        'message'  => 'the period header ' . $column . ' differs from its award rows',
                    ];
                }
            }

            foreach (['award_total', 'paid_total'] as $column) {
                if (!$sameMoney(round((float) $header[$column], 2), $actual[$column])) {
                    $mismatches[] = [
                        'kind'     => 'period_total',
                        'period'   => $period,
                        'column'   => $column,
                        'expected' => $actual[$column],
                        'actual'   => round((float) $header[$column], 2),
                        'message'  => 'the period header ' . $column . ' differs from its award rows',
                    ];
                }
            }

            /* 6. Period state (plan §14.12). */
            $status = (string) $header['status'];

            if ($status === 'paid' && $actual['award_count'] !== $actual['paid_count'] + $actual['skipped_count']) {
                $mismatches[] = [
                    'kind'     => 'period_state',
                    'period'   => $period,
                    'expected' => 0,
                    'actual'   => $actual['award_count'] - $actual['paid_count'] - $actual['skipped_count'],
                    'message'  => 'a paid period still has an award that is neither paid nor skipped',
                ];
            }

            if ($status === 'skipped' && $actual['award_count'] > 0) {
                $mismatches[] = [
                    'kind'     => 'period_state',
                    'period'   => $period,
                    'expected' => 0,
                    'actual'   => $actual['award_count'],
                    'message'  => 'a skipped period (stream off) has award rows',
                ];
            }

            if ($status === 'evaluated' && RoyaltyAward::pendingCount($period, $pdo) === 0) {
                $warnings[] = [
                    'kind'    => 'awaiting_finalisation',
                    'period'  => $period,
                    'message' => 'an evaluated period has no pending award; the next run finalises it',
                ];
            }
        }

        /* 7. An award cannot exist without its month header. */
        foreach ($pdo->query(
            "SELECT a.id, a.period FROM royalty_awards a
               LEFT JOIN royalty_periods p ON p.period = a.period
              WHERE p.period IS NULL
              LIMIT 50"
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'orphan_award',
                'award'    => (int) $row['id'],
                'period'   => (string) $row['period'],
                'expected' => null,
                'actual'   => null,
                'message'  => 'a Royalty award has no period header',
            ];
        }

        /* 8. Rank sanity: a paid row carries money, PS never exceeds GS. */
        foreach ($pdo->query(
            "SELECT id, period, status, amount, group_volume, personal_volume, rank_level
               FROM royalty_awards
              WHERE (status = 'paid' AND amount <= 0)
                 OR personal_volume > group_volume
                 OR rank_level NOT BETWEEN 2 AND 4
              LIMIT 50"
        )->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'rank_sanity',
                'award'    => (int) $row['id'],
                'period'   => (string) $row['period'],
                'expected' => null,
                'actual'   => (float) $row['amount'],
                'message'  => 'a Royalty award has an impossible status, volume pair or rank',
            ];
        }

        /* 9. Corrupt sponsor links: unreachable member accounts (a
         *    warning, never a money mismatch; plan §12.3). */
        $cycleMembers = 0;

        try {
            $memberCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
            $cycleMembers = max(0, $memberCount - count(RoyaltyRank::map(null, $pdo)));
        } catch (Throwable $e) {
            error_log('[ecowealth] royalty cycle check unavailable: ' . $e->getMessage());
        }

        if ($cycleMembers > 0) {
            $warnings[] = [
                'kind'    => 'cycle_members',
                'count'   => $cycleMembers,
                'message' => $cycleMembers . ' member account(s) are unreachable from a root '
                    . '(self-sponsored or in a sponsor cycle)',
            ];
        }

        return [
            'ok'                => $mismatches === [],
            'periods'           => (int) ($periods['periods'] ?? 0),
            'paid_periods'      => (int) ($periods['paid_periods'] ?? 0),
            'evaluated_periods' => (int) ($periods['evaluated_periods'] ?? 0),
            'skipped_periods'   => (int) ($periods['skipped_periods'] ?? 0),
            'award_rows'        => (int) ($awards['award_rows'] ?? 0),
            'paid_rows'         => (int) ($awards['paid_rows'] ?? 0),
            'pending_rows'      => (int) ($awards['pending_rows'] ?? 0),
            'skipped_rows'      => (int) ($awards['skipped_rows'] ?? 0),
            'paid_total'        => $paidTotal,
            'pending_total'     => $pendingTotal,
            'award_total'       => round((float) ($awards['award_total'] ?? 0), 2),
            'ledger_total'      => $ledgerTotal,
            'legacy_rows'       => (int) ($legacy['legacy_rows'] ?? 0),
            'legacy_total'      => round((float) ($legacy['legacy_total'] ?? 0), 2),
            'cycle_members'     => $cycleMembers,
            'mismatches'        => $mismatches,
            'warnings'          => $warnings,
        ];
    }

    /* ----------------------------------------------------------
     *  Evaluation internals
     * ---------------------------------------------------------- */

    /**
     * The one computation preview() and evaluate() share.
     *
     * @return array{
     *   period:string, as_of:?string, cutoff:bool, enabled:bool,
     *   ladder:array<string, mixed>, counts:array<string, int>,
     *   drafts:list<array<string, mixed>>
     * }
     */
    private function collect(string $period, bool $cutoff): array
    {
        [$start, $end] = RoyaltyPeriod::bounds($period);
        $asOf          = $cutoff ? $end : null;

        $ladder = RoyaltyRank::ladder();

        /* The engine reads one query for the tree and one for the
         * volume, whatever the size of the network (plan §14.9). */
        $map = RoyaltyRank::map($asOf, $this->pdo);
        $map = RoyaltyRank::withVolumes($map, $this->volumes($start, $end));

        $counts = RoyaltyRank::counts($map);
        $drafts = [];

        foreach ($map as $row) {
            $rank = (int) $row['rank'];

            /* Leader and Member are ranks, not royalty ranks (D-01,
             * plan §12.2): no row, not even a zero one. */
            if (!in_array($rank, RoyaltyRank::PAYING, true)) {
                continue;
            }

            $triple = $ladder['ranks'][$rank] ?? null;

            if ($triple === null || $triple['quota_cents'] === null) {
                error_log('[ecowealth] royalty: rank ' . $rank . ' has no configured ladder row');
                continue;
            }

            $groupCents    = max(0, (int) $row['group_volume_cents']);
            $personalCents = max(0, (int) $row['volume_cents']);
            $quotaCents    = (int) $triple['quota_cents'];
            $groupPct      = (float) $triple['group_pct'];
            $personalPct   = (float) $triple['personal_pct'];

            $reason = '';

            if ((int) $row['is_active'] !== 1) {
                $reason = RoyaltyAward::REASON_INACTIVE;
            } elseif ((string) $row['member_status'] !== 'active') {
                $reason = RoyaltyAward::REASON_MEMBERSHIP;
            } elseif ($groupCents < $quotaCents) {
                /* All-or-nothing: one centavo short pays nothing,
                 * including the personal percentage (D-09). */
                $reason = RoyaltyAward::REASON_QUOTA;
            }

            $amountCents = $reason === ''
                ? self::awardCents($groupCents, $personalCents, $groupPct, $personalPct)
                : 0;

            if ($reason === '' && $amountCents <= 0) {
                $reason = RoyaltyAward::REASON_ZERO;
            }

            $drafts[] = [
                'period'              => $period,
                'user_id'             => (int) $row['id'],
                'rank_level'          => $rank,
                'directs'             => (int) $row['directs'],
                'downline_rank_count' => self::requirementCount($row, $rank),
                'team_size'           => (int) $row['team_size'],
                'group_volume'        => self::decimalOf($groupCents),
                'personal_volume'     => self::decimalOf($personalCents),
                'quota'               => self::decimalOf($quotaCents),
                'group_pct'           => RoyaltyAward::money($groupPct, 3),
                'personal_pct'        => RoyaltyAward::money($personalPct, 3),
                'amount'              => self::decimalOf($amountCents),
                'status'              => $reason === '' ? 'pending' : 'skipped',
                'reason'              => $reason === '' ? null : $reason,
            ];
        }

        return [
            'period'  => $period,
            'as_of'   => $asOf,
            'cutoff'  => $cutoff,
            'enabled' => (bool) $ladder['enabled'],
            'ladder'  => $ladder,
            'counts'  => $counts,
            'drafts'  => $drafts,
        ];
    }

    /**
     * The documented preview/evaluate result for an unwritten period.
     *
     * @param array<string, mixed> $computed
     * @return array<string, mixed>
     */
    private function computedResult(string $period, array $computed): array
    {
        $drafts       = $computed['drafts'];
        $skipped      = 0;
        $awardCents   = 0;

        foreach ($drafts as $draft) {
            if ((string) $draft['status'] === 'skipped') {
                $skipped++;
                continue;
            }

            $awardCents += self::centsOf($draft['amount']);
        }

        $enabled = (bool) $computed['enabled'];

        if (!$enabled) {
            /* A preview of a month that would close while the stream is
             * off: no awards, a skipped month (D-15). */
            return [
                'period'        => $period,
                'as_of'         => $computed['as_of'],
                'enabled'       => false,
                'eligible'      => 0,
                'paid_members'  => 0,
                'skipped'       => 0,
                'award_total'   => 0.0,
                'paid_total'    => 0.0,
                'pending'       => 0,
                'pending_total' => 0.0,
                'rank_counts'   => $this->rankCountsFrom($computed['counts']),
                'awards'        => [],
                'header'        => null,
                'status'        => 'skipped',
            ];
        }

        return [
            'period'        => $period,
            'as_of'         => $computed['as_of'],
            'enabled'       => true,
            'eligible'      => count($drafts),
            'paid_members'  => count($drafts) - $skipped,
            'skipped'       => $skipped,
            'award_total'   => round($awardCents / 100, 2),
            'paid_total'    => 0.0,
            'pending'       => count($drafts) - $skipped,
            'pending_total' => round($awardCents / 100, 2),
            'rank_counts'   => $this->rankCountsFrom($computed['counts']),
            'awards'        => $drafts,
            'header'        => null,
            'status'        => (count($drafts) - $skipped) === 0 ? 'paid' : 'evaluated',
        ];
    }

    /**
     * The documented result for a period that already has a header:
     * the stored rows, never a recomputation (plan §14.11).
     *
     * @param array<string, mixed> $header
     * @return array<string, mixed>
     */
    private function storedResult(array $header): array
    {
        $period = (string) $header['period'];
        $totals = RoyaltyAward::totalsForPeriod($period, $this->pdo);

        $counts = [
            'member'     => max(0, (int) $header['members_count']
                - (int) $header['leaders_count'] - (int) $header['supervisors_count']
                - (int) $header['managers_count'] - (int) $header['directors_count']),
            'leader'     => (int) $header['leaders_count'],
            'supervisor' => (int) $header['supervisors_count'],
            'manager'    => (int) $header['managers_count'],
            'director'   => (int) $header['directors_count'],
            'total'      => (int) $header['members_count'],
        ];

        return [
            'period'        => $period,
            'as_of'         => (string) $header['as_of'],
            'enabled'       => (int) $header['enabled'] === 1,
            'eligible'      => $totals['rows'],
            'paid_members'  => $totals['paid_rows'] + $totals['pending_rows'],
            'skipped'       => $totals['skipped_rows'],
            'award_total'   => $totals['award_total'],
            'paid_total'    => $totals['paid_total'],
            'pending'       => $totals['pending_rows'],
            'pending_total' => $totals['pending_total'],
            'rank_counts'   => $this->rankCountsFrom($counts),
            'awards'        => RoyaltyAward::rowsForPeriod($period, $this->pdo),
            'header'        => $header,
            'status'        => (string) $header['status'],
        ];
    }

    /**
     * The header row for one period (column => value), with the ladder
     * snapshot the plan requires (D-17).
     *
     * @param array<string, int>   $counts
     * @param array<string, mixed> $ladder
     * @return array<string, mixed>
     */
    private function headerRow(
        string $period,
        string $status,
        bool $enabled,
        ?int $actorId,
        string $asOf,
        array $ladder,
        array $counts,
        int $awardCount,
        int $paidCount,
        int $skippedCount,
        string $awardTotal,
        string $paidTotal,
        ?string $note
    ): array {
        $thresholds = $ladder['thresholds'];
        $quotas     = $ladder['quotas'];
        $rates      = $ladder['rates'];

        return [
            'period'                  => $period,
            'status'                  => $status,
            'enabled'                 => $enabled ? 1 : 0,
            'evaluated_at'            => date('Y-m-d H:i:s'),
            'evaluated_by'            => $actorId !== null && $actorId > 0 ? $actorId : null,
            'as_of'                   => $asOf,
            'leader_directs'          => (int) $thresholds['leader'],
            'supervisor_leaders'      => (int) $thresholds['supervisor'],
            'manager_supervisors'     => (int) $thresholds['manager'],
            'director_managers'       => (int) $thresholds['director'],
            'quota_supervisor'        => RoyaltyAward::money($quotas['supervisor'], 2),
            'quota_manager'           => RoyaltyAward::money($quotas['manager'], 2),
            'quota_director'          => RoyaltyAward::money($quotas['director'], 2),
            'group_pct_supervisor'    => RoyaltyAward::money($rates['supervisor']['group'], 3),
            'personal_pct_supervisor' => RoyaltyAward::money($rates['supervisor']['personal'], 3),
            'group_pct_manager'       => RoyaltyAward::money($rates['manager']['group'], 3),
            'personal_pct_manager'    => RoyaltyAward::money($rates['manager']['personal'], 3),
            'group_pct_director'      => RoyaltyAward::money($rates['director']['group'], 3),
            'personal_pct_director'   => RoyaltyAward::money($rates['director']['personal'], 3),
            'members_count'           => (int) ($counts['total'] ?? 0),
            'leaders_count'           => (int) ($counts['leader'] ?? 0),
            'supervisors_count'       => (int) ($counts['supervisor'] ?? 0),
            'managers_count'          => (int) ($counts['manager'] ?? 0),
            'directors_count'         => (int) ($counts['director'] ?? 0),
            'award_count'             => $awardCount,
            'paid_count'              => $paidCount,
            'skipped_count'           => $skippedCount,
            'award_total'             => $awardTotal,
            'paid_total'              => $paidTotal,
            'note'                    => $note,
        ];
    }

    /**
     * Insert one header row. The caller builds the row, so every
     * identifier stays a hard-coded constant.
     *
     * @param array<string, mixed> $row
     */
    private function writeHeader(array $row): void
    {
        $columns = array_keys($row);
        $marks   = implode(', ', array_fill(0, count($columns), '?'));

        $this->pdo->prepare(
            'INSERT INTO royalty_periods (' . implode(', ', $columns) . ') VALUES (' . $marks . ')'
        )->execute(array_values($row));
    }

    /** Lock the period header first (plan §14.8). @return array<string, mixed>|null */
    private function lockHeader(string $period): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM royalty_periods WHERE period = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$period]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Make a month final: `paid` + the payment stamp. Only when no
     * pending award remains, and only from `evaluated`, so a second
     * call is a no-op (plan §14.5).
     */
    private function finalise(string $period, ?int $actorId): bool
    {
        if (RoyaltyAward::pendingCount($period, $this->pdo) > 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE royalty_periods
                SET status = 'paid', paid_at = NOW(), paid_by = ?
              WHERE period = ? AND status = 'evaluated'"
        );
        $stmt->execute([$actorId !== null && $actorId > 0 ? $actorId : null, $period]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Recompute `paid_count`/`paid_total` from the persisted rows, so a
     * resumed run cannot drift (plan §14.5).
     */
    private function refreshPaidTotals(string $period): void
    {
        $this->pdo->prepare(
            "UPDATE royalty_periods p
                SET paid_count = (SELECT COUNT(*) FROM royalty_awards a
                                   WHERE a.period = p.period AND a.status = 'paid'),
                    paid_total = (SELECT COALESCE(SUM(a.amount), 0) FROM royalty_awards a
                                   WHERE a.period = p.period AND a.status = 'paid')
              WHERE p.period = ?"
        )->execute([$period]);
    }

    /** Refresh every surface a payment changed (after the commit). @param list<int> $userIds */
    private function touch(array $userIds): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId <= 0) {
                continue;
            }

            Live::touchEarnings($userId);
            Live::touchMemberDashboard($userId);
            Live::touchMemberWallet($userId);
            Live::touchMembership($userId);
        }
    }

    /* ----------------------------------------------------------
     *  Static helpers
     * ---------------------------------------------------------- */

    /** The requirement count that satisfied a rank (plan §13.3). */
    private static function requirementCount(array $row, int $rank): int
    {
        return match ($rank) {
            RoyaltyRank::SUPERVISOR => (int) ($row['downline_leaders'] ?? 0),
            RoyaltyRank::MANAGER    => (int) ($row['downline_supervisors'] ?? 0),
            RoyaltyRank::DIRECTOR   => (int) ($row['downline_managers'] ?? 0),
            default                 => 0,
        };
    }

    /**
     * The documented rank_counts block: five **exclusive** counts
     * (plan §13.2). `counts()` also carries `ranked` and `total`, which
     * the header derives differently.
     *
     * @param array<string, int> $counts
     * @return array{member:int, leader:int, supervisor:int, manager:int, director:int}
     */
    private function rankCountsFrom(array $counts): array
    {
        return [
            'member'     => (int) ($counts['member'] ?? 0),
            'leader'     => (int) ($counts['leader'] ?? 0),
            'supervisor' => (int) ($counts['supervisor'] ?? 0),
            'manager'    => (int) ($counts['manager'] ?? 0),
            'director'   => (int) ($counts['director'] ?? 0),
        ];
    }

    /** An integer centavo amount as an exact peso decimal string. */
    private static function decimalOf(int $cents): string
    {
        $negative = $cents < 0;
        $digits   = str_pad((string) abs($cents), 3, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '') . substr($digits, 0, -2) . '.' . substr($digits, -2);
    }

    /** A DECIMAL money value or integer centavos as integer centavos. */
    private static function centsOf(mixed $value): int
    {
        $scaled = RoyaltyAward::money($value, 2);

        return (int) str_replace('.', '', $scaled);
    }

    /** A rate as integer milli-percent (3.125% → 3125). */
    private static function milli(mixed $percent): int
    {
        $scaled = (int) str_replace('.', '', RoyaltyAward::money($percent, 3));

        return max(0, min(100000, $scaled));
    }

    /** Validate 'YYYY-MM' or throw (a bad period is a programming error). */
    private function assertPeriod(string $period): string
    {
        $period = trim($period);

        if (!RoyaltyPeriod::isPeriod($period)) {
            throw new InvalidArgumentException(
                'A Royalty period must be a calendar month written YYYY-MM, got "' . $period . '".'
            );
        }

        return $period;
    }

    /**
     * One transaction, or a savepoint when the caller already owns one
     * (the same pattern WalletService and UnilevelService use). The
     * harness runs every case inside a transaction, and the job calls
     * these methods outside one.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'royalty_sp_' . (++self::$savepointSeq);
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();

            if ($owns) {
                $this->pdo->commit();
            } elseif ($savepoint !== null) {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $e) {
            if ($owns) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } elseif ($savepoint !== null) {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    /* The outer transaction is already unusable; let the
                     * original exception surface. */
                }
            }

            throw $e;
        }
    }

    /** Are the phase's two tables present? (a half-migrated database) */
    private static function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('royalty_periods', 'royalty_awards')"
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 2;
    }
}
