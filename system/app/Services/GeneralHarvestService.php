<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestService — the shared board's decisions
 *  (plan §6 Phase 21C, §12.6, §14.2, §14.3, §14.10)
 *
 *  Phase 21C owns exactly two decisions: **arm** (record the go-live
 *  baseline, once) and **state** (what the board is now). Phase 21D
 *  extends this class with the settlement; nothing here writes money —
 *  there is not one credited row, not one wallet movement, after 21C.
 *
 *      enabled()        is the stream switched on? (the arm interlock)
 *      armed()          does a baseline exist?
 *      baseline()       N₀, or null while the board is unarmed
 *      state()          the board's condition, with the depth and the
 *                       backlog, for the strip and the jobs
 *      excluded()       the baseline's rows and amount, level by level
 *      assertArmable()  throw the exact refusal, or return
 *      arm($dryRun)     the one-time baseline
 *
 *  **Arm is one transaction and one act.** The rows are a pure function
 *  of `N₀` and the queue order (§12.6), so two runs on the same queue
 *  write the same set; `general_harvest_board.armed_at` is the flag that
 *  makes a second arm impossible (there is no `--force`), and
 *  `GET_LOCK('general_harvest_arm', 5)` serialises two runs that arrive
 *  together (§14.3, P21C-T14). A dry run computes and prints exactly the
 *  same numbers and writes nothing: it is the decision surface, and the
 *  real run is the only write.
 *
 *  **The refusals are the design** (§12.6 Rule 5, §14.3 Rules 3–5):
 *
 *      enabled         the stream is on — switch it off and arm
 *      armed           a baseline exists; only a business reset re-arms
 *      unseeded        Phase 20's queue has no history, so N₀ is meaningless
 *      invalid_ladder  a level is priced at zero and the arm freezes it
 *      not_gapless     COUNT(*) <> MAX(id): a hole would shift every node
 *
 *  Every one of them fires **before** the transaction opens, so a refused
 *  arm writes nothing at all — not a lock row, not a board field, not an
 *  audit entry.
 *
 *  The ten amounts are frozen from the arm (D-23): `GeneralHarvestLevel`
 *  refuses a save while `armed_at` is set, and `excluded()` is the
 *  evidence the freeze exists for — one ladder, one closed-form liability.
 * ============================================================== */

final class GeneralHarvestService
{
    /** The audit action the arm writes, once (§15.4). */
    public const AUDIT_ARM = 'general_harvest.arm';

    /** The audit action every catch-up run writes (§15.4). */
    public const AUDIT_CATCHUP = 'general_harvest.catchup';

    /** The advisory lock that serialises two arm runs (§14.3). */
    public const ARM_LOCK = 'general_harvest_arm';

    /** Seconds GET_LOCK waits before it reports the other run (§14.3). */
    public const ARM_LOCK_TIMEOUT = 5;

    /**
     * Phase 20's seed marker. The arm refuses an empty or unparseable
     * value: without the seed the queue has no history and `N₀` would be
     * a number about nothing (§14.3 Rule 4).
     */
    public const SEED_KEY = 'team_harvest_ledger_since';

    /** How many baseline positions the dry run and the report print. */
    public const PREVIEW_LIMIT = 20;

    /** The board singleton's subject in the audit trail. */
    public const SUBJECT = 'general_harvest_board';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /* ----------------------------------------------------------
     *  The board's standing
     * ---------------------------------------------------------- */

    /**
     * The one switch (D-10), read from **this** connection so a job that
     * was handed a database cannot consult another one. The value is
     * normalised exactly like `setting_bool()`.
     */
    public function enabled(): bool
    {
        return in_array(
            strtolower(trim($this->settingValue(GeneralHarvestLevel::ENABLED_KEY, '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    /** Has a baseline been recorded? `armed_at` and `baseline_n` move together. */
    public function armed(): bool
    {
        return GeneralHarvestBoard::state($this->pdo)['armed'];
    }

    /** `N₀`, the queue length at the arm, or null while the board is unarmed. */
    public function baseline(): ?int
    {
        return GeneralHarvestBoard::state($this->pdo)['baseline_n'];
    }

    /**
     * The board's whole condition for the strip, the jobs and the tests:
     * the board model's `status()` (state, armed, enabled, baseline_n,
     * armed_at, settled_n, `N`, gapless, backlog, amounts) plus the
     * backlog's money value and the configured depth.
     *
     * The board model stays the only reader of its own row; this method
     * adds the two derived numbers 21C's callers need.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $status  = GeneralHarvestBoard::status($this->pdo);
        $backlog = GeneralHarvestBoard::backlog($this->pdo);

        return $status + [
            'backlog_amount' => $backlog['amount'],
            'max_levels'     => GeneralHarvestLevel::maxLevels(),
        ];
    }

    /**
     * The baseline's rows and amount, level by level — what the network
     * had already earned when the stream was armed and what it will never
     * be paid (§12.6 Rule 4, Rule 7).
     *
     * `board_total` is the closed form over `baseline_n`, read beside the
     * ledger's own sum: the two must agree, and `excluded()` is where a
     * later phase (the 21D reconcile and the doctor) compares them.
     *
     * @return array{armed:bool, baseline_n:?int, rows:int, amount:float,
     *               board_total:float,
     *               per_level:array<int, array{level:int, rows:int, amount:float}>}
     */
    public function excluded(): array
    {
        $board = GeneralHarvestBoard::state($this->pdo);
        $rows  = [];

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $rows[$level] = ['level' => $level, 'rows' => 0, 'amount' => 0.0];
        }

        if ($board['armed']) {
            foreach ($this->pdo->query(
                "SELECT level, COUNT(*) AS written, COALESCE(SUM(amount), 0) AS total
                   FROM general_harvest_awards
                  WHERE status = 'baseline'
                  GROUP BY level"
            )->fetchAll() as $row) {
                $level = (int) $row['level'];

                if (!isset($rows[$level])) {
                    continue;
                }

                $rows[$level]['rows']   = (int) $row['written'];
                $rows[$level]['amount'] = round((float) $row['total'], 2);
            }
        }

        $count  = 0;
        $amount = 0.0;

        foreach ($rows as $row) {
            $count  += $row['rows'];
            $amount += $row['amount'];
        }

        return [
            'armed'       => $board['armed'],
            'baseline_n'  => $board['baseline_n'],
            'rows'        => $count,
            'amount'      => round($amount, 2),
            'board_total' => $board['baseline_n'] === null
                ? 0.0
                : GeneralHarvestLevel::boardTotal($board['baseline_n'], null, $this->pdo),
            'per_level'   => $rows,
        ];
    }

    /**
     * The length the board has been settled to — a position count, not a
     * money count (§12.6 Rule 9). Always `≥ baseline_n` and `≤ N`.
     */
    public function settledN(): int
    {
        return (int) GeneralHarvestBoard::state($this->pdo)['settled_n'];
    }

    /**
     * What the queue has done since the last settlement: `N − settled_n`
     * positions and what they are worth as the closed-form difference.
     * Delegates to the board model's `backlog()` (§12.1, §12.7).
     *
     * @return array{n:int, settled_n:int, positions:int, amount:float, armed:bool}
     */
    public function backlog(): array
    {
        return GeneralHarvestBoard::backlog($this->pdo);
    }

    /**
     * The open liability the engine would pay if settled right now:
     * `boardTotal(N) − boardTotal(settled_n)`, plus the per-level pair
     * counts the doctor prints (§12.8, §14.11).
     *
     * @return array{positions:int, pairs:array<int, array{level:int, missing:int, amount:float}>, amount:float}
     */
    public function openLiability(): array
    {
        $board = GeneralHarvestBoard::state($this->pdo);
        $n     = GeneralHarvestBoard::queueN($this->pdo)['n'];

        if (!$board['armed']) {
            return ['positions' => 0, 'pairs' => [], 'amount' => 0.0];
        }

        $positions = max(0, $n - (int) $board['settled_n']);
        $amounts   = GeneralHarvestLevel::amounts($this->pdo);
        $depth     = GeneralHarvestLevel::maxLevels();
        $pairs     = [];

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $complete = GeneralHarvestLevel::paid($level, $n);
            $rows     = (int) ($this->countByLevel($level) ?? 0);
            $missing  = max(0, $complete - $rows);

            if ($level > $depth || $missing === 0) {
                continue;
            }

            $pairs[$level] = [
                'level'   => $level,
                'missing' => $missing,
                'amount'  => (float) ($amounts[$level] ?? 0.0),
            ];
        }

        $total = 0.0;

        foreach ($pairs as $pair) {
            $total += $pair['missing'] * $pair['amount'];
        }

        return [
            'positions' => $positions,
            'pairs'     => array_values($pairs),
            'amount'    => round(
                max(
                    0.0,
                    GeneralHarvestLevel::boardTotal($n, $amounts, $this->pdo)
                        - GeneralHarvestLevel::boardTotal((int) $board['settled_n'], $amounts, $this->pdo)
                ),
                2
            ),
        ];
    }

    /**
     * State-based settlement (§14.4). Compares the queue length with the
     * board's `settled_n` and writes every newly-complete block, one
     * position at a time up to `$maxNewPositions`.
     *
     * Returns `{state: 'off'}` / `{state: 'unarmed'}` / `{state: 'replayed'}`
     * / `{state: 'settled', target, written, amount, unpriced, per_level}`.
     * Configuration states return, never throw (§14.7 Rule 4).
     *
     * @param int $maxNewPositions how many positions this call may settle (1 inline)
     * @param array<string, mixed>|null $actor the actor, when a test passes one
     * @return array<string, mixed>
     * @throws GeneralHarvestException::state for structural anomalies
     */
    public function settle(int $maxNewPositions = 1, ?array $actor = null): array
    {
        if (!$this->enabled()) {
            return ['state' => 'off'];
        }

        if (!$this->armed()) {
            return ['state' => 'unarmed'];
        }

        $maxNewPositions = max(1, (int) $maxNewPositions);

        $owns = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            /* The board row is the mutex: two settlements cannot compute
             * overlapping targets (§14.8). */
            $this->pdo->prepare(
                'SELECT id, baseline_n, settled_n FROM general_harvest_board WHERE id = ' . GeneralHarvestBoard::SINGLETON_ID . ' FOR UPDATE'
            )->execute();

            $board = GeneralHarvestBoard::state($this->pdo);
            $queue = GeneralHarvestBoard::queueN($this->pdo);

            if (!$queue['gapless']) {
                throw GeneralHarvestException::notGapless($queue['n'], $queue['max_id']);
            }

            $n         = (int) $queue['n'];
            $settledN  = (int) $board['settled_n'];
            $baselineN = $board['baseline_n'] ?? null;

            $amounts  = GeneralHarvestLevel::amounts($this->pdo);
            $depth    = GeneralHarvestLevel::maxLevels();

            if ($n < $settledN) {
                throw GeneralHarvestException::queueShrank($n, $settledN);
            }

            $target = min($n, $settledN + $maxNewPositions);

            /* When settling new positions, the ledger's prefix must already
             * cover every priced block up to settled_n. A hand-edited
             * settled_n that runs ahead of the rows is corruption, not an
             * interrupted settlement (§14.12). When target === settled_n
             * (a replay or a depth change), the replay block below handles
             * missing rows instead of throwing. */
            if ($target > $settledN) {
                $counts = [];

                foreach ($this->pdo->query(
                    'SELECT level, COUNT(*) AS written FROM general_harvest_awards GROUP BY level'
                )->fetchAll() as $row) {
                    $counts[(int) $row['level']] = (int) $row['written'];
                }

                for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= $depth; $level++) {
                    $qFor = GeneralHarvestLevel::qFor($level, $settledN);

                    if ($qFor < 0) {
                        continue;
                    }

                    $have = $counts[$level] ?? 0;

                    if ($have < $qFor + 1 && (float) ($amounts[$level] ?? 0.0) > 0) {
                        throw GeneralHarvestException::ledgerBehindSettled($level, $have, $qFor + 1);
                    }
                }
            }

            /* No new positions: only a depth change (or an interrupted
             * settlement) can leave work behind. If every priced level
             * ≤ depth is already a complete prefix at settled_n, this is
             * a pure replay (§14.6 layer 1). Otherwise settle the missing
             * rows at the current settled_n (P21D-T16). */
            $isReplay = $target === $settledN;

            if ($isReplay) {
                $counts = [];

                foreach ($this->pdo->query(
                    'SELECT level, COUNT(*) AS written FROM general_harvest_awards GROUP BY level'
                )->fetchAll() as $row) {
                    $counts[(int) $row['level']] = (int) $row['written'];
                }

                $missing = false;

                for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= $depth; $level++) {
                    $qFor = GeneralHarvestLevel::qFor($level, $settledN);

                    if ($qFor < 0) {
                        continue;
                    }

                    $have = $counts[$level] ?? 0;

                    if ($have < $qFor + 1 && (float) ($amounts[$level] ?? 0.0) > 0) {
                        $missing = true;
                        break;
                    }
                }

                if (!$missing) {
                    if ($owns) {
                        $this->pdo->commit();
                    }

                    return ['state' => 'replayed', 'target' => $settledN, 'written' => 0, 'amount' => 0.0];
                }
            }
            $rows    = [];
            $unpriced = [];

            for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
                $qFor = GeneralHarvestLevel::qFor($level, $target);

                if ($qFor < 0) {
                    continue;
                }

                $have = $counts[$level] ?? 0;

                if ($have > $qFor + 1) {
                    throw GeneralHarvestException::ledgerAheadOfQueue($level, $have, $qFor + 1);
                }

                /* Levels above the configured depth are complete but not
                 * paid — baseline rows already cover the prefix (§14.4). */
                if ($level > $depth) {
                    continue;
                }

                $amount = (float) ($amounts[$level] ?? 0.0);

                for ($qq = $have; $qq <= $qFor; $qq++) {
                    /* Defensive: the arm wrote baseline rows for every
                     * seq ≤ qFor(L, baseline_n), so a healthy board never
                     * reaches here; the branch keeps a missing baseline
                     * row from being credited instead. */
                    if ($baselineN !== null && $qq <= GeneralHarvestLevel::qFor($level, $baselineN)) {
                        $rows[] = [
                            'seq'          => $qq,
                            'level'        => $level,
                            'status'       => GeneralHarvestAward::STATUS_BASELINE,
                            'amount'       => $amount,
                            'ledger_id'    => null,
                        ];
                        continue;
                    }

                    if ($amount <= 0) {
                        $unpriced[] = $level;
                        break; /* other levels still pay (§14.4 note) */
                    }

                    $owner = GeneralHarvestBoard::ownerOf($qq, $this->pdo);

                    if ($owner === null || (string) ($owner['role'] ?? '') !== 'member') {
                        throw GeneralHarvestException::notAMember((int) ($owner['user_id'] ?? 0));
                    }

                    $rows[] = [
                        'seq'          => $qq,
                        'user_id'      => (int) $owner['user_id'],
                        'level'        => $level,
                        'status'       => GeneralHarvestAward::STATUS_CREDITED,
                        'amount'       => $amount,
                        'threshold_n'  => GeneralHarvestLevel::threshold($qq, $level),
                        'n_at_award'   => $target,
                        'ledger_id'    => null,
                        'credited_at'  => date('Y-m-d H:i:s'),
                    ];
                }
            }

            /* Sort before writing so two batches sharing a node holder take
             * their wallet locks in the same order (§14.8). */
            usort($rows, static function (array $a, array $b): int {
                if (($a['user_id'] ?? 0) !== ($b['user_id'] ?? 0)) {
                    return ($a['user_id'] ?? 0) <=> ($b['user_id'] ?? 0);
                }

                if ($a['level'] !== $b['level']) {
                    return ($a['level']) <=> ($b['level']);
                }

                return ($a['seq']) <=> ($b['seq']);
            });

            $written    = 0;
            $amountTotal = 0.0;

            foreach ($rows as $row) {
                $awardId = GeneralHarvestAward::create((int) $row['seq'], [
                    'user_id'     => (int) $row['user_id'],
                    'level'       => (int) $row['level'],
                    'threshold_n' => (int) $row['threshold_n'],
                    'n_at_award'  => (int) $row['n_at_award'],
                    'amount'      => $row['amount'],
                    'credited_at' => $row['credited_at'],
                ], $this->pdo);

                if ($row['status'] === GeneralHarvestAward::STATUS_CREDITED) {
                    $movement = (new WalletService($this->pdo))->credit(
                        (int) $row['user_id'],
                        $row['amount'],
                        GeneralHarvestAward::REF_TYPE,
                        $awardId,
                        'General Harvest level ' . (int) $row['level'] . ' bonus',
                        true
                    );

                    $ledgerId = (int) ($movement['ledger_id'] ?? 0);

                    if ($ledgerId <= 0 || !GeneralHarvestAward::linkLedger($awardId, $ledgerId, $this->pdo)) {
                        throw new InvalidArgumentException(sprintf(
                            'Award #%d for node %d level %d could not be linked to its wallet movement.',
                            $awardId,
                            (int) $row['seq'],
                            (int) $row['level']
                        ));
                    }

                    $written++;
                    $amountTotal += $row['amount'];
                }
            }

            $this->pdo->prepare(
                'UPDATE general_harvest_board SET settled_n = ? WHERE id = ' . GeneralHarvestBoard::SINGLETON_ID
            )->execute([$target]);

            if ($owns) {
                $this->pdo->commit();
            }

            return [
                'state'      => 'settled',
                'target'     => $target,
                'written'    => $written,
                'amount'     => round($amountTotal, 2),
                'unpriced'   => $unpriced,
                'per_level'  => $counts,
                'settled_n'  => $target,
            ];
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        } finally {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    /**
     * Settlement for one batch: `settle()` inside its own transaction,
     * committed whole (§14.7). Used by the catch-up job and by suite
     * cases that call `settleAll()` directly.
     */
    public function settleAll(int $batch = 500): array
    {
        return $this->settle(max(1, (int) $batch));
    }

    /**
     * The registration hook (§14.1): settle exactly one position inside
     * the caller's transaction, after Phase 20's qualification call.
     *
     * Structural anomalies (`not gapless`, `queue shrank`, `ledger ahead`)
     * are caught, logged as `general_harvest.anomaly` and returned as
     * `state`, so the registration succeeds and no money moves
     * (§14.7 Rule 5). Money failures propagate and roll the registration
     * back (§14.7 Rule 2).
     *
     * @return array<string, mixed> the settle result, or `{state: 'anomaly', ...}`
     */
    public function onQueueChanged(): array
    {
        try {
            return $this->settle(1);
        } catch (GeneralHarvestException $e) {
            try {
                AuditLog::record([
                    'action'       => 'general_harvest.anomaly',
                    'actor_id'     => null,
                    'actor_role'   => 'system',
                    'subject_type' => GeneralHarvestService::SUBJECT,
                    'subject_id'   => GeneralHarvestBoard::SINGLETON_ID,
                    'meta'         => [
                        'reason'  => $e->reason ?? 'state',
                        'message' => $e->getMessage(),
                    ],
                ], $this->pdo);
            } catch (Throwable) {
                /* The audit trail must never break a registration. */
            }

            return ['state' => 'anomaly', 'reason' => $e->reason ?? 'state', 'message' => $e->getMessage()];
        }
    }

    /**
     * One level's award-row count (§14.4's prefix read).
     */
    private function countByLevel(int $level): int
    {
        $row = $this->pdo->prepare(
            'SELECT COUNT(*) AS written FROM general_harvest_awards WHERE level = ?'
        );
        $row->execute([$level]);

        return (int) $row->fetchColumn();
    }

    /**
     * The card's whole payload (§14.12): position, node, settled state,
     * the ten levels with block ranges and fill, paid levels, next level,
     * remaining, baseline state, paid total and the open amount for one
     * node.
     *
     * @return array<string, mixed>
     */
    public function statusFor(int $userId): array
    {
        $queue = GeneralHarvestBoard::queueN($this->pdo);
        $board = GeneralHarvestBoard::state($this->pdo);
        $depth = GeneralHarvestLevel::maxLevels();

        $position = GeneralHarvestBoard::positionOf($userId, $this->pdo);
        $seq      = $position === null ? null : (int) $position - 1;

        $armed      = (bool) $board['armed'];
        $enabled    = $this->enabled();
        $baselineN  = $board['baseline_n'];
        $settledN   = (int) $board['settled_n'];
        $queueN     = (int) $queue['n'];
        $backlog    = max(0, $queueN - $settledN);

        $amounts = GeneralHarvestLevel::amounts($this->pdo);
        $paid    = GeneralHarvestAward::paidLevels($userId, $this->pdo);
        $paidSet = array_flip($paid);

        $levels = [];
        $paidTotal = 0.0;
        $baselineTotal = 0.0;

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $complete = $seq !== null && GeneralHarvestLevel::complete($seq, $level, $queueN);
            $filled   = $seq !== null ? GeneralHarvestLevel::filled($seq, $level, $queueN) : 0;
            $slots    = GeneralHarvestLevel::slots($level);
            $amount   = (float) ($amounts[$level] ?? 0.0);
            $threshold = $seq !== null ? GeneralHarvestLevel::threshold($seq, $level) : 0;
            $paidLevel = isset($paidSet[$level]);
            $rowStatus = $seq !== null && GeneralHarvestAward::has($seq, $level, $this->pdo)
                ? GeneralHarvestAward::findBySeqLevel($seq, $level, $this->pdo)['status']
                : '';

            $paidAt = null;

            if ($paidLevel) {
                $row = GeneralHarvestAward::findBySeqLevel($seq, $level, $this->pdo);
                $paidAt = $row['credited_at'] ?? null;
            }

            if ($paidLevel) {
                $status = GeneralHarvestAward::STATUS_CREDITED;
                $paidTotal += $amount;
            } elseif ($rowStatus === GeneralHarvestAward::STATUS_BASELINE) {
                $status = GeneralHarvestAward::STATUS_BASELINE;
                $baselineTotal += $amount;
            } elseif ($seq === null) {
                $status = 'waiting';
            } elseif (!$complete) {
                $status = 'progress';
            } elseif ($level > $depth) {
                $status = 'complete-beyond-depth';
            } elseif ($amount <= 0) {
                $status = 'unpriced';
            } else {
                $status = 'complete';
            }

            $levels[$level] = [
                'level'         => $level,
                'slots'         => $slots,
                'from'          => $seq !== null ? GeneralHarvestLevel::blockFrom($seq, $level) : 0,
                'to'            => $seq !== null ? GeneralHarvestLevel::blockTo($seq, $level) : 0,
                'filled'        => $filled,
                'complete'      => $complete,
                'status'        => $status,
                'amount'        => $amount,
                'threshold_n'   => $threshold,
                'next_n'        => $threshold,
                'progress'      => $slots > 0 ? round(100 * $filled / $slots, 1) : 0.0,
                'paid_at'       => $paidAt,
            ];
        }

        /* The next level the queue is waiting for (§14.12): the shallowest
         * incomplete level at this node. */
        $next = null;

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            if (!isset($levels[$level]['complete']) || $levels[$level]['complete']) {
                continue;
            }

            $filled   = $levels[$level]['filled'];
            $slots    = $levels[$level]['slots'];
            $from     = $levels[$level]['from'];
            $to       = $levels[$level]['to'];
            $amount   = $levels[$level]['amount'];

            $next = [
                'level'   => $level,
                'from'    => $from,
                'to'      => $to,
                'filled'  => $filled,
                'slots'   => $slots,
                'remaining' => $slots - $filled,
                'percent' => $slots > 0 ? round(100 * $filled / $slots, 1) : 0.0,
                'amount'  => $amount,
                'needs_n' => $levels[$level]['next_n'],
            ];

            break;
        }

        /* What the engine would pay for this node if settled now — its
         * complete, priced, unpaid levels up to the depth. */
        $open = 0.0;

        if ($seq !== null && $armed && $enabled) {
            for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= $depth; $level++) {
                if (isset($paidSet[$level])) {
                    continue;
                }

                $row = GeneralHarvestAward::findBySeqLevel($seq, $level, $this->pdo);

                if ($row !== null && $row['status'] === GeneralHarvestAward::STATUS_BASELINE) {
                    continue;
                }

                if ($seq !== null && GeneralHarvestLevel::complete($seq, $level, $queueN)
                    && (float) ($amounts[$level] ?? 0.0) > 0) {
                    $open += (float) ($amounts[$level] ?? 0.0);
                }
            }
        }

        $reachable = false;

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= $depth; $level++) {
            if (GeneralHarvestLevel::paid($level, $queueN) > 0) {
                $reachable = true;
                break;
            }
        }

        $earned = 0.0;

        if ($seq !== null) {
            $earned = GeneralHarvestLevel::earnedBy($seq, $queueN, $amounts, $depth, $this->pdo);
        }

        return [
            'queued'         => $position !== null,
            'position'       => $position,
            'seq'            => $seq,
            'queue_n'        => $queueN,
            'armed'          => $armed,
            'enabled'        => $enabled,
            'baseline_n'     => $baselineN,
            'settled_n'      => $settledN,
            'backlog'        => $backlog,
            'max_levels'     => $depth,
            'total'          => number_format(GeneralHarvestLevel::total($amounts, $this->pdo), 2, '.', ''),
            'earned'         => number_format($earned, 2, '.', ''),
            'baseline_earned'=> number_format($baselineTotal, 2, '.', ''),
            'levels'         => array_values($levels),
            'next'           => $next,
            'reachable'      => $reachable,
            'paid_levels'    => count($paid),
            'baseline_levels'=> count(array_filter($levels, static fn (array $l): bool => $l['status'] === GeneralHarvestAward::STATUS_BASELINE)),
            'credited_amount'=> number_format($paidTotal, 2, '.', ''),
            'roster'         => null,
            'open'           => number_format($open, 2, '.', ''),
        ];
    }

    /**
     * Prove the ledger, the wallet and the closed form agree (§14.13).
     * Returns `ok` plus the checks it ran. Never repairs.
     *
     * @return array<string, mixed>
     */
    public function reconcile(): array
    {
        $checks = [];
        $ok     = true;

        $fail = static function (string $key, string $detail) use (&$checks, &$ok): void {
            $checks[$key] = ['ok' => false, 'detail' => $detail];
            $ok = false;
        };

        $pass = static function (string $key, string $detail) use (&$checks): void {
            $checks[$key] = ['ok' => true, 'detail' => $detail];
        };

        $board   = GeneralHarvestBoard::state($this->pdo);
        $queue   = GeneralHarvestBoard::queueN($this->pdo);
        $amounts = GeneralHarvestLevel::amounts($this->pdo);
        $depth   = GeneralHarvestLevel::maxLevels();

        $settledN = (int) $board['settled_n'];
        $baselineN = $board['baseline_n'] ?? null;
        $queueN   = (int) $queue['n'];

        /* Σ credited = Σ general_harvest ledger credits */
        $awardedCents   = (int) round((float) $this->pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM general_harvest_awards WHERE status = 'credited'"
        )->fetchColumn() * 100);
        $ledgeredCents  = (int) round((float) $this->pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger WHERE ref_type = 'general_harvest' AND type = 'credit'"
        )->fetchColumn() * 100);

        if ($awardedCents !== $ledgeredCents) {
            $fail('ledger_vs_wallet', sprintf(
                'credited awards total %s but general_harvest wallet credits total %s',
                money($awardedCents / 100),
                money($ledgeredCents / 100)
            ));
        } else {
            $pass('ledger_vs_wallet', sprintf(
                'credited awards and wallet credits both total %s',
                money($awardedCents / 100)
            ));
        }

        /* Σ credited = boardTotal(settled_n) − boardTotal(baseline_n) */
        $expected = max(
            0.0,
            GeneralHarvestLevel::boardTotal($settledN, $amounts, $this->pdo)
                - ($baselineN === null ? 0.0 : GeneralHarvestLevel::boardTotal($baselineN, $amounts, $this->pdo))
        );

        if ((int) round($expected * 100) !== $awardedCents) {
            $fail('ledger_vs_closed_form', sprintf(
                'credited awards total %s but the closed form expects %s',
                money($awardedCents / 100),
                money($expected)
            ));
        } else {
            $pass('ledger_vs_closed_form', sprintf(
                'credited awards total %s equals boardTotal(settled_n) − boardTotal(baseline_n)',
                money($expected)
            ));
        }

        /* Σ baseline = boardTotal(baseline_n) and every baseline row has ledger_id IS NULL */
        $baselineRows = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM general_harvest_awards WHERE status = 'baseline'"
        )->fetchColumn();
        $baselineCents = (int) round((float) $this->pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM general_harvest_awards WHERE status = 'baseline'"
        )->fetchColumn() * 100);
        $baselineLedgerId = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM general_harvest_awards WHERE status = 'baseline' AND ledger_id IS NOT NULL"
        )->fetchColumn();

        $expectedBaseline = $baselineN === null
            ? 0.0
            : GeneralHarvestLevel::boardTotal($baselineN, $amounts, $this->pdo);

        if ($baselineN !== null && (int) round($expectedBaseline * 100) !== $baselineCents) {
            $fail('baseline_vs_closed_form', sprintf(
                'baseline rows total %s but boardTotal(baseline_n) expects %s',
                money($baselineCents / 100),
                money($expectedBaseline)
            ));
        } else {
            $pass('baseline_vs_closed_form', sprintf(
                'baseline rows total %s equals boardTotal(baseline_n)',
                money($baselineCents / 100)
            ));
        }

        if ($baselineLedgerId > 0) {
            $fail('baseline_no_ledger_id', "$baselineLedgerId baseline row(s) carry a ledger_id; baseline rows are never paid");
        } else {
            $pass('baseline_no_ledger_id', 'every baseline row has ledger_id NULL');
        }

        /* Every credited award has a ledger_id that exists and credits the same user */
        $dangling = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM general_harvest_awards a
               LEFT JOIN ewallet_ledger l ON l.id = a.ledger_id AND l.ref_type = 'general_harvest' AND l.ref_id = a.id
              WHERE a.status = 'credited' AND (a.ledger_id IS NULL OR l.id IS NULL)"
        )->fetchColumn();

        if ($dangling > 0) {
            $fail('credit_link', "$dangling credited award(s) lack a matching wallet movement");
        } else {
            $pass('credit_link', 'every credited award carries its wallet movement');
        }

        /* Every general_harvest ledger row has an award (ref_id) */
        $orphans = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ewallet_ledger l
               LEFT JOIN general_harvest_awards a ON a.id = l.ref_id
              WHERE l.ref_type = 'general_harvest' AND a.id IS NULL"
        )->fetchColumn();

        if ($orphans > 0) {
            $fail('orphan_credit', "$orphans general_harvest credit(s) have no award row");
        } else {
            $pass('orphan_credit', 'every general_harvest credit names an award');
        }

        /* Per level: contiguous prefix, and for a priced level ≤ depth equal qFor(L, settled_n)+1 */
        $prefixOk = true;

        foreach ($this->pdo->query(
            'SELECT level, COUNT(*) AS written, MIN(seq) AS min_seq, MAX(seq) AS max_seq
               FROM general_harvest_awards
              GROUP BY level'
        )->fetchAll() as $row) {
            $level = (int) $row['level'];
            $rows  = (int) $row['written'];
            $min   = $row['min_seq'] === null ? null : (int) $row['min_seq'];
            $max   = $row['max_seq'] === null ? null : (int) $row['max_seq'];

            if ($min !== 0 || $max !== $rows - 1) {
                $prefixOk = false;
                $fail("prefix_level_$level", sprintf(
                    'level %d rows are not a contiguous prefix: rows = %d, min seq = %s, max seq = %s',
                    $level,
                    $rows,
                    $min === null ? 'NULL' : (string) $min,
                    $max === null ? 'NULL' : (string) $max
                ));
                continue;
            }

            if ($level <= $depth && (float) ($amounts[$level] ?? 0.0) > 0) {
                $expectedRows = GeneralHarvestLevel::qFor($level, $settledN) + 1;

                if ($rows !== $expectedRows) {
                    $prefixOk = false;
                    $fail("prefix_level_$level", sprintf(
                        'level %d has %d rows but qFor(L, settled_n) + 1 = %d',
                        $level,
                        $rows,
                        $expectedRows
                    ));
                }
            }
        }

        if ($prefixOk) {
            $pass('per_level_prefix', 'every level is a contiguous prefix matching settled_n');
        }

        /* COUNT(*) = MAX(id) on the queue */
        if (!$queue['gapless']) {
            $fail('queue_gapless', sprintf(
                'the queue is not gapless: COUNT(*) = %d, MAX(id) = %d',
                $queue['n'],
                $queue['max_id']
            ));
        } else {
            $pass('queue_gapless', 'the queue is gapless');
        }

        /* baseline_n ≤ settled_n ≤ N */
        if ($baselineN !== null && ($settledN < $baselineN || $settledN > $queueN)) {
            $fail('board_bounds', sprintf(
                'board bounds broken: baseline_n = %d, settled_n = %d, N = %d',
                $baselineN,
                $settledN,
                $queueN
            ));
        } else {
            $pass('board_bounds', 'baseline_n ≤ settled_n ≤ N');
        }

        /* Ladder: ten rows, each ≥ 0, every level ≤ max_levels > 0, not changed since arm */
        $ladderRows = (int) $this->pdo->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn();
        $zeroLevels = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM general_harvest_levels WHERE amount <= 0"
        )->fetchColumn();
        $unpricedBelowDepth = 0;

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= $depth; $level++) {
            if ((float) ($amounts[$level] ?? 0.0) <= 0) {
                $unpricedBelowDepth++;
            }
        }

        $ladderChanged = false;

        if ($board['armed_at'] !== null) {
            $changed = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM general_harvest_levels
                  WHERE updated_at > (SELECT armed_at FROM general_harvest_board WHERE id = " . GeneralHarvestBoard::SINGLETON_ID . ")"
            )->fetchColumn();
            $ladderChanged = $changed > 0;
        }

        if ($ladderRows !== GeneralHarvestLevel::LEVEL_MAX) {
            $fail('ladder_rows', "the ladder has $ladderRows rows, not " . GeneralHarvestLevel::LEVEL_MAX);
        } elseif ($zeroLevels > 0) {
            $fail('ladder_zero', "$zeroLevels ladder level(s) are priced at zero");
        } elseif ($unpricedBelowDepth > 0) {
            $fail('ladder_unpriced', "$unpricedBelowDepth level(s) at or below max_levels are unpriced");
        } elseif ($ladderChanged) {
            $fail('ladder_frozen', 'a ladder level changed after the board was armed (D-23)');
        } else {
            $pass('ladder_ok', 'the ladder has ten priced rows and has not changed since the arm');
        }

        /* Σ credited awards and wallet credits — captured for the report */
        $creditedRows   = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM general_harvest_awards WHERE status = 'credited'"
        )->fetchColumn();
        $creditedTotal  = (float) $this->pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM general_harvest_awards WHERE status = 'credited'"
        )->fetchColumn();
        $baselineRows   = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM general_harvest_awards WHERE status = 'baseline'"
        )->fetchColumn();
        $baselineTotal  = (float) $this->pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM general_harvest_awards WHERE status = 'baseline'"
        )->fetchColumn();
        $ledgerTotal    = (float) $this->pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger
               WHERE ref_type = 'general_harvest' AND type = 'credit'"
        )->fetchColumn();

        $warnings = [];

        if (!$board['armed']) {
            $warnings[] = [
                'kind'    => 'unarmed',
                'message' => 'the board is unarmed; nothing can pay until bin/general_harvest_arm.php runs',
            ];
        }

        if (!$this->enabled()) {
            $warnings[] = [
                'kind'    => 'disabled',
                'message' => 'the stream is switched off (general_harvest_enabled = 0); the queue still records positions',
            ];
        }

        return [
            'ok'               => $ok,
            'checks'           => $checks,
            'credited_rows'    => $creditedRows,
            'credited_total'   => round($creditedTotal, 2),
            'baseline_rows'    => $baselineRows,
            'baseline_total'   => round($baselineTotal, 2),
            'ledger_rows'      => $creditedRows,
            'ledger_total'     => round($ledgerTotal, 2),
            'mismatches'       => array_values(array_filter(
                $checks,
                static fn (array $check): bool => ($check['ok'] ?? false) !== true
            )),
            'warnings'         => $warnings,
        ];
    }

    /**
     * The doctor's health report (§14.11): clock, tables, settings, armed
     * state, gapless, queue count, settled, backlog, per-level frontier,
     * paid, baseline, open and the reconcile verdict.
     *
     * @return array<string, mixed>
     */
    public function doctor(): array
    {
        $board   = GeneralHarvestBoard::state($this->pdo);
        $queue   = GeneralHarvestBoard::queueN($this->pdo);
        $frontier = GeneralHarvestBoard::frontier($this->pdo);
        $backlog  = $this->backlog();
        $open     = $this->openLiability();
        $reconcile = $this->reconcile();

        return [
            'queue_n'     => (int) $queue['n'],
            'max_id'      => (int) $queue['max_id'],
            'gapless'     => (bool) $queue['gapless'],
            'armed'       => (bool) $board['armed'],
            'baseline_n'  => $board['baseline_n'],
            'armed_at'    => $board['armed_at'],
            'settled_n'   => (int) $board['settled_n'],
            'enabled'     => $this->enabled(),
            'max_levels'  => GeneralHarvestLevel::maxLevels(),
            'backlog'     => $backlog,
            'open'        => $open,
            'frontier'    => array_values($frontier),
            'paid'        => GeneralHarvestAward::countByStatus($this->pdo),
            'baseline'    => $this->excluded(),
            'reconcile'   => $reconcile,
            'ok'          => $reconcile['ok'] && $queue['gapless'] && $backlog['armed'],
        ];
    }

    /* ----------------------------------------------------------
     *  Arm — the one-time baseline (§14.3)
     * ---------------------------------------------------------- */

    /**
     * The refusals, in the plan's order, without writing anything.
     *
     * The job calls `arm()`, which calls this; a caller that only wants
     * to know whether the board *could* be armed (a preflight) calls it
     * directly. Nothing here prints or mutates.
     *
     * @throws GeneralHarvestException enabled | armed | unseeded | invalid_ladder | not_gapless
     */
    public function assertArmable(): void
    {
        $this->plan();
    }

    /**
     * Record the go-live baseline.
     *
     * A dry run returns the same shape with `armed = false` and writes
     * nothing at all. The real run, in **one transaction**:
     *
     *    1. one multi-row INSERT per level, `seq = 0 … qFor(L, N₀)`;
     *    2. the board row: baseline_n = N₀, armed_at = now, settled_n = N₀;
     *    3. the `general_harvest.arm` audit row.
     *
     * `settled_n` is set to `N₀` because every block up to the baseline is
     * **recorded** — as `baseline`, not as paid — so `settled_n` is a
     * position count and not a money count (§12.6 Rule 9).
     *
     * The CLI is the only entry point (Rule 2): there is no web action and
     * no settings-page button, so there is no URL to authenticate and no
     * stale form that could arm an old queue.
     *
     * @param array<string, mixed>|null $actor the staff row, when a test passes one;
     *                                         the CLI has no session, so it arms as the system
     * @return array{armed:bool, dry_run:bool, n:int, rows:int, excluded:float,
     *               per_level:array<int, int>, amounts:array<int, float>,
     *               preview:list<array<string, mixed>>, armed_at:?string, audit_id:?int}
     */
    public function arm(bool $dryRun = false, ?array $actor = null): array
    {
        if (PHP_SAPI !== 'cli') {
            throw GeneralHarvestException::state(
                'The General Harvest arm is a CLI job (php bin/general_harvest_arm.php);'
                    . ' it has no web entry and no settings-page button (plan §14.3 Rule 2).'
            );
        }

        if (!$this->acquireLock()) {
            throw GeneralHarvestException::state(
                'Another General Harvest arm run holds the ' . self::ARM_LOCK . ' lock; nothing was written.'
            );
        }

        try {
            $plan = $this->plan();

            $answer = [
                'armed'     => false,
                'dry_run'   => $dryRun,
                'n'         => $plan['n'],
                'rows'      => $plan['rows'],
                'excluded'  => $plan['excluded'],
                'per_level' => $plan['per_level'],
                'amounts'   => $plan['amounts'],
                'preview'   => $plan['preview'],
                'armed_at'  => null,
                'audit_id'  => null,
            ];

            if ($dryRun) {
                return $answer;
            }

            $owns = !$this->pdo->inTransaction();

            if ($owns) {
                $this->pdo->beginTransaction();
            }

            try {
                $instant = date('Y-m-d H:i:s');
                $written = 0;

                foreach ($plan['per_level'] as $level => $count) {
                    if ($count <= 0) {
                        continue;
                    }

                    $written += GeneralHarvestAward::writeBaselineLevel(
                        $level,
                        $count - 1,
                        $plan['n'],
                        $plan['owner_ids'],
                        $instant,
                        $plan['amounts'],
                        $this->pdo
                    );
                }

                $this->writeBoard($plan['n'], $instant, $actor);

                $auditId = AuditLog::record([
                    'action'       => self::AUDIT_ARM,
                    'actor_id'     => $this->actorId($actor),
                    'actor_role'   => $actor === null ? 'system' : (string) ($actor['role'] ?? 'admin'),
                    'subject_type' => self::SUBJECT,
                    'subject_id'   => GeneralHarvestBoard::SINGLETON_ID,
                    'meta'         => [
                        'trigger'   => 'cli',
                        'dry_run'   => false,
                        'n'         => $plan['n'],
                        'rows'      => $written,
                        'excluded'  => number_format($plan['excluded'], 2, '.', ''),
                        'per_level' => $plan['per_level'],
                        'armed_by'  => $this->actorId($actor),
                    ],
                ], $this->pdo);

                if ($owns) {
                    $this->pdo->commit();
                }
            } catch (Throwable $e) {
                if ($owns && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }

            $answer['armed']    = true;
            $answer['armed_at'] = $instant;
            $answer['audit_id'] = $auditId;

            return $answer;
        } finally {
            $this->releaseLock();
        }
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * Validate and compute the whole arm in one read: `N₀`, the rows each
     * level writes, the excluded amount, the queue owners and the preview.
     *
     * Every refusal lives here, in the plan's order, so a dry run and a
     * real run can never disagree about whether the board may be armed
     * (§12.6 Rule 8: the baseline is deterministic).
     *
     * @return array{n:int, rows:int, excluded:float, per_level:array<int, int>,
     *               amounts:array<int, float>, owners:array<int, array{user_id:int, username:string}>,
     *               owner_ids:array<int, int>, preview:list<array<string, mixed>>}
     */
    private function plan(): array
    {
        if ($this->enabled()) {
            throw GeneralHarvestException::enabled();
        }

        $board = GeneralHarvestBoard::state($this->pdo);

        if ($board['armed']) {
            throw GeneralHarvestException::armed($board['armed_at']);
        }

        $seed = trim($this->settingValue(self::SEED_KEY, ''));

        if ($seed === '' || strtotime($seed) === false) {
            throw GeneralHarvestException::unseeded($seed);
        }

        /* Rule 5, D-23: **all ten** levels must be priced. The amounts
         * freeze at this instant, so a zero level could never be paid even
         * if the depth were raised later; the dry run prints the whole
         * commitment, level by level, before anything is written. */
        $amounts    = GeneralHarvestLevel::amounts($this->pdo);
        $totalCents = 0;

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $cents = (int) round(((float) ($amounts[$level] ?? 0)) * 100);

            if ($cents <= 0) {
                throw GeneralHarvestException::invalidLadder($level, GeneralHarvestLevel::total($amounts, $this->pdo));
            }

            $totalCents += $cents;
        }

        if ($totalCents <= 0) {
            throw GeneralHarvestException::invalidLadder(0, 0.0);
        }

        $queue = GeneralHarvestBoard::queueN($this->pdo);

        if (!$queue['gapless']) {
            throw GeneralHarvestException::notGapless($queue['n'], $queue['max_id']);
        }

        $n            = $queue['n'];
        $perLevel     = [];
        $rows         = 0;
        $excludedCents = 0;

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            /* `paid()` is `qFor() + 1`, so this is exactly the prefix
             * `seq = 0 … qFor(L, N₀)` — including the levels above the
             * configured depth (§12.6 Rule 6). */
            $count = GeneralHarvestLevel::paid($level, $n);

            $perLevel[$level]  = $count;
            $rows             += $count;
            $excludedCents    += $count * (int) round(((float) $amounts[$level]) * 100);
        }

        $owners = [];
        $ids    = [];

        if ($n > 0) {
            /* The queue is gapless, so positions 1 … N₀ are exactly the
             * rows N₀ holds; one bounded read and no per-row query. */
            $statement = $this->pdo->prepare(
                'SELECT q.id AS position, q.user_id, u.username
                   FROM harvest_queue q
                   JOIN users u ON u.id = q.user_id
                  WHERE q.id <= ?
                  ORDER BY q.id'
            );
            $statement->execute([$n]);

            foreach ($statement->fetchAll() as $row) {
                $seq     = (int) $row['position'] - 1;
                $owners[$seq] = ['user_id' => (int) $row['user_id'], 'username' => (string) $row['username']];
                $ids[$seq]    = (int) $row['user_id'];
            }
        }

        $preview = [];
        $amountsOut = [];

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $amountsOut[$level] = (float) ($amounts[$level] ?? 0.0);

            for ($seq = 0; $seq < $perLevel[$level] && count($preview) < self::PREVIEW_LIMIT; $seq++) {
                $preview[] = [
                    'seq'         => $seq,
                    'position'    => $seq + 1,
                    'username'    => (string) ($owners[$seq]['username'] ?? ''),
                    'level'       => $level,
                    'threshold_n' => GeneralHarvestLevel::threshold($seq, $level),
                    'amount'      => (float) ($amounts[$level] ?? 0.0),
                ];
            }
        }

        return [
            'n'         => $n,
            'rows'      => $rows,
            'excluded'  => (float) ($excludedCents / 100),
            'per_level' => $perLevel,
            'amounts'   => $amountsOut,
            'owners'    => $owners,
            'owner_ids' => $ids,
            'preview'   => $preview,
        ];
    }

    /**
     * Set the singleton: `N₀`, the arm instant, the actor and `settled_n`.
     *
     * The row is normally created by migration 025; a hand-built schema may
     * lack it, and a board that cannot be armed because a convenience row
     * is absent would be a worse answer than writing it here (the
     * singleton's CHECKs still apply).
     *
     * @param array<string, mixed>|null $actor
     */
    private function writeBoard(int $n, string $instant, ?array $actor): void
    {
        $actorId = $this->actorId($actor);

        if (GeneralHarvestBoard::state($this->pdo)['exists']) {
            $this->pdo->prepare(
                'UPDATE general_harvest_board
                    SET baseline_n = ?, armed_at = ?, armed_by = ?, settled_n = ?
                  WHERE id = ' . GeneralHarvestBoard::SINGLETON_ID
            )->execute([$n, $instant, $actorId, $n]);

            return;
        }

        $this->pdo->prepare(
            'INSERT INTO general_harvest_board (id, baseline_n, armed_at, armed_by, settled_n)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([GeneralHarvestBoard::SINGLETON_ID, $n, $instant, $actorId, $n]);
    }

    /** The signed-in staff member's id, or null when the CLI armed it (D-05). */
    private function actorId(?array $actor): ?int
    {
        $id = $actor === null ? 0 : (int) ($actor['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * One setting, read through this service's connection (never the
     * global cache: a job handed a database must read that database's
     * switch and seed marker).
     */
    private function settingValue(string $key, string $default): string
    {
        try {
            $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
            $statement->execute([$key]);
            $value = $statement->fetchColumn();

            return $value === false || $value === null ? $default : (string) $value;
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Take the arm's advisory lock; false when another run holds it.
     *
     * A server without `GET_LOCK` must not refuse the job: the
     * `armed_at` flag is the real guard, and it is read inside the same
     * transaction that writes it (§12.6 Rule 1, §14.3).
     */
    private function acquireLock(): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $statement->execute([self::ARM_LOCK, self::ARM_LOCK_TIMEOUT]);

            return (int) $statement->fetchColumn() === 1;
        } catch (Throwable) {
            return true;
        }
    }

    /** Release the arm's lock (best effort: it dies with the connection). */
    private function releaseLock(): void
    {
        try {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::ARM_LOCK]);
        } catch (Throwable) {
            /* nothing to do: the lock belongs to the connection */
        }
    }
}
