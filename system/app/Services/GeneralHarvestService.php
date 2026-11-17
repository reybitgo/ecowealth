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
