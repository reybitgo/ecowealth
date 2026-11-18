<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestBoard — the shared board's state and the queue-side
 *  reads (plan §6 Phase 21B, §12.1–§12.3, §12.6, §13.2)
 *
 *  There is **no board table per node**: a node is a queue row, its
 *  index is `id − 1`, and its condition is a function of `N`, the
 *  ladder and the settled length. This class reads the three things
 *  that are stored —
 *
 *      general_harvest_board    one row: baseline_n, armed_at, settled_n
 *      harvest_queue            the positions (Phase 20, read-only)
 *      general_harvest_awards   the frontier (COUNT per level, 21B reads)
 *
 *  — and derives everything else from `GeneralHarvestLevel`:
 *
 *      state()        the singleton row, or an unarmed default
 *      queueN()       {n, max_id, gapless}   `N` and the D-03 check
 *      frontier()     per level: rows written, blocks complete, missing,
 *                     the queue length the next block needs, the amount
 *      backlog()      N − settled_n and the amount it is worth
 *      status()       one of the four strip states (§6 Rule 9)
 *      positionOf()/ownerOf()   a member ↔ a node
 *      blocks()       a node's ten blocks at queue length N
 *      blockRange()   a page of nodes whose block at a level is full
 *
 *  **Read-only in this phase.** 21B arms nothing, settles nothing and
 *  writes nothing: the arm job is 21C's and the money engine 21D's. A
 *  missing board row is not an error here (a hand-built schema can lack
 *  it) — it reads as unarmed, because that is the safe state.
 * ============================================================== */

final class GeneralHarvestBoard
{
    /** The singleton's primary key (§13.2: `chk_ghb_singleton` pins it). */
    public const SINGLETON_ID = 1;

    /** No baseline has been recorded; nothing can pay (§6 Rule 9). */
    public const STATE_UNARMED = 'unarmed';

    /** A baseline exists and the switch is off: positions are recorded. */
    public const STATE_ARMED_OFF = 'armed_off';

    /** A baseline exists, the switch is on and nothing is waiting. */
    public const STATE_ARMED_ON = 'armed_on';

    /** Armed and on, with positions the settlement has not reached yet. */
    public const STATE_UNSETTLED = 'unsettled';

    /**
     * The four states of the settings strip, in plan §6 Rule 9 order. The
     * view renders exactly one of them and never claims the board is armed
     * or paying when it is not.
     *
     * @var list<string>
     */
    public const STATES = [
        self::STATE_UNARMED,
        self::STATE_ARMED_OFF,
        self::STATE_ARMED_ON,
        self::STATE_UNSETTLED,
    ];

    /** How many nodes a `blockRange()` page returns at most. */
    public const PAGE_MAX = 500;

    /* ----------------------------------------------------------
     *  The stored state
     * ---------------------------------------------------------- */

    /**
     * The board's singleton row, normalised.
     *
     * `armed` is `baseline_n IS NOT NULL`: the schema's `chk_ghb_armed`
     * keeps `baseline_n` and `armed_at` in step, so one flag is enough.
     * A missing row reads as an unarmed board (`exists` false) rather than
     * as an error — nothing has been armed, which is exactly what an
     * absent row means.
     *
     * @return array{id:int, exists:bool, armed:bool, baseline_n:?int, armed_at:?string,
     *               armed_by:?int, settled_n:int, updated_at:?string}
     */
    public static function state(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $statement = $pdo->query(
            'SELECT id, baseline_n, armed_at, armed_by, settled_n, updated_at
               FROM general_harvest_board
              WHERE id = ' . self::SINGLETON_ID . '
              LIMIT 1'
        );
        $row = $statement->fetch();

        if ($row === false) {
            return [
                'id'         => self::SINGLETON_ID,
                'exists'     => false,
                'armed'      => false,
                'baseline_n' => null,
                'armed_at'   => null,
                'armed_by'   => null,
                'settled_n'  => 0,
                'updated_at' => null,
            ];
        }

        return [
            'id'         => (int) $row['id'],
            'exists'     => true,
            'armed'      => $row['baseline_n'] !== null,
            'baseline_n' => $row['baseline_n'] === null ? null : (int) $row['baseline_n'],
            'armed_at'   => $row['armed_at'] === null ? null : (string) $row['armed_at'],
            'armed_by'   => $row['armed_by'] === null ? null : (int) $row['armed_by'],
            'settled_n'  => (int) $row['settled_n'],
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
        ];
    }

    /* ----------------------------------------------------------
     *  The queue as the board sees it
     * ---------------------------------------------------------- */

    /**
     * `N`, the highest position ever assigned, and the gapless check
     * (§12.3 Rule 1, D-03).
     *
     * The board's arithmetic is defined over **consecutive** indices, so a
     * hole would shift every later node. `gapless` is
     * `COUNT(*) = MAX(id)`; an empty queue is gapless by definition.
     *
     * @return array{n:int, max_id:int, gapless:bool}
     */
    public static function queueN(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            'SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS max_id FROM harvest_queue'
        )->fetch() ?: [];

        $n     = (int) ($row['n'] ?? 0);
        $maxId = (int) ($row['max_id'] ?? 0);

        return ['n' => $n, 'max_id' => $maxId, 'gapless' => $n === $maxId];
    }

    /** Is the queue gapless? One number, no rows (§12.3 Rule 1). */
    public static function gapless(?PDO $pdo = null): bool
    {
        return self::queueN($pdo)['gapless'];
    }

    /** A member's queue position — their node is `position − 1`. */
    public static function positionOf(int $userId, ?PDO $pdo = null): ?int
    {
        $pdo ??= db();

        $statement = $pdo->prepare('SELECT id FROM harvest_queue WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /**
     * The member who holds node `$seq` — the queue row at position
     * `$seq + 1` — or null when that position does not exist.
     *
     * The board pays a **node**, not an account, so this is the only
     * bridge between the two in the read direction.
     *
     * @return array{seq:int, position:int, user_id:int, username:string, role:string}|null
     */
    public static function ownerOf(int $seq, ?PDO $pdo = null): ?array
    {
        if ($seq < 0) {
            return null;
        }

        $pdo ??= db();

        $statement = $pdo->prepare(
            'SELECT q.id AS position, q.user_id, u.username, u.role
               FROM harvest_queue q
               JOIN users u ON u.id = q.user_id
              WHERE q.id = ?
              LIMIT 1'
        );
        $statement->execute([$seq + 1]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'seq'      => $seq,
            'position' => (int) $row['position'],
            'user_id'  => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'role'     => (string) $row['role'],
        ];
    }

    /* ----------------------------------------------------------
     *  Completion and the frontier (plan §12.3, §12.5 R2)
     * ---------------------------------------------------------- */

    /**
     * Per level, how far the board has paid and what the queue says it
     * should have paid — **one grouped aggregate plus the closed forms**
     * (§12.5 Rule 2: the frontier is read, never assumed).
     *
     *      rows      COUNT(*) of award rows at the level = the prefix 0 … rows−1
     *      complete  paid(L, N): the blocks the queue has filled
     *      missing   max(0, complete − rows): what a settlement would write now
     *      next_n    the queue length the next row's block needs
     *      amount    the configured amount for the level
     *
     * `rows > complete` is not an error here: it is the state the money
     * engine reports as "ledger ahead of the queue" (21D).
     *
     * @return array<int, array{level:int, rows:int, complete:int, missing:int,
     *                          next_n:int, amount:float}>
     */
    public static function frontier(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $counts = [];

        /* `rows` is a reserved word in MySQL 8 (window frames), so the
         * aggregate is aliased `written` and mapped back to the plan's name. */
        foreach ($pdo->query('SELECT level, COUNT(*) AS written FROM general_harvest_awards GROUP BY level')->fetchAll() as $row) {
            $counts[(int) $row['level']] = (int) $row['written'];
        }

        $n      = self::queueN($pdo)['n'];
        $amounts = GeneralHarvestLevel::amounts($pdo);
        $frontier = [];

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $rows     = $counts[$level] ?? 0;
            $complete = GeneralHarvestLevel::paid($level, $n);

            $frontier[$level] = [
                'level'    => $level,
                'rows'     => $rows,
                'complete' => $complete,
                'missing'  => max(0, $complete - $rows),
                'next_n'   => GeneralHarvestLevel::threshold($rows, $level),
                'amount'   => $amounts[$level],
            ];
        }

        return $frontier;
    }

    /**
     * What has happened since the settlement last ran: `N − settled_n`
     * positions, and what they are worth as the closed-form difference
     * (§12.1, §12.7's open liability).
     *
     * An unarmed board has no backlog — nothing is being tracked yet — so
     * both numbers are zero. A negative gap (a `settled_n` ahead of `N`,
     * which the engine refuses rather than produces) reads as zero.
     *
     * @return array{n:int, settled_n:int, positions:int, amount:float, armed:bool}
     */
    public static function backlog(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $state = self::state($pdo);
        $n     = self::queueN($pdo)['n'];

        if (!$state['armed']) {
            return [
                'n'         => $n,
                'settled_n' => $state['settled_n'],
                'positions' => 0,
                'amount'    => 0.0,
                'armed'     => false,
            ];
        }

        $positions = max(0, $n - $state['settled_n']);
        $amount    = 0.0;

        if ($positions > 0) {
            $amounts = GeneralHarvestLevel::amounts($pdo);
            $amount  = max(
                0.0,
                GeneralHarvestLevel::boardTotal($n, $amounts, $pdo)
                    - GeneralHarvestLevel::boardTotal($state['settled_n'], $amounts, $pdo)
            );
        }

        return [
            'n'         => $n,
            'settled_n' => $state['settled_n'],
            'positions' => $positions,
            'amount'    => round($amount, 2),
            'armed'     => true,
        ];
    }

    /**
     * The four-state strip (§6 Rule 9), in order: unarmed → armed and off
     * → armed and on → armed, on, with unsettled positions.
     *
     * It is a **read**: the copy each state carries (and the single next
     * action it names) belongs to the view.
     *
     * @return array{state:string, armed:bool, enabled:bool, exists:bool, baseline_n:?int,
     *               armed_at:?string, armed_by:?int, settled_n:int, n:int,
     *               gapless:bool, backlog:int, amounts:array<int, float>}
     */
    public static function status(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $state  = self::state($pdo);
        $queue  = self::queueN($pdo);
        $enable = GeneralHarvestLevel::enabled();
        $gap    = $state['armed'] ? max(0, $queue['n'] - $state['settled_n']) : 0;

        $status = match (true) {
            !$state['armed'] => self::STATE_UNARMED,
            !$enable        => self::STATE_ARMED_OFF,
            $gap > 0         => self::STATE_UNSETTLED,
            default          => self::STATE_ARMED_ON,
        };

        return [
            'state'      => $status,
            'armed'      => $state['armed'],
            'enabled'    => $enable,
            'exists'     => $state['exists'],
            'baseline_n' => $state['baseline_n'],
            'armed_at'   => $state['armed_at'],
            'armed_by'   => $state['armed_by'],
            'settled_n'  => $state['settled_n'],
            'n'          => $queue['n'],
            'gapless'    => $queue['gapless'],
            'backlog'    => $gap,
            'amounts'    => GeneralHarvestLevel::amounts($pdo),
        ];
    }

    /* ----------------------------------------------------------
     *  One node's ten blocks, and a page of the board
     * ---------------------------------------------------------- */

    /**
     * Node `$seq`'s ten blocks at queue length `$n` — the member card's
     * whole arithmetic, and the reason no view computes a threshold
     * (§6 Rule 7, §12.2, Appendix A):
     *
     *      level, slots, start, from–to, threshold,
     *      filled, remaining, percent, complete, amount, earned, paying
     *
     * `earned` is the amount this level paid if the block is complete, and
     * `paying` is `level ≤ general_harvest_max_levels` — the depth rule of
     * §12.4 Rule 4, shown rather than applied.
     *
     * @param array<int, float|int|string>|null $amounts
     * @return array<int, array{level:int, slots:int, start:int, from:int, to:int,
     *                          threshold:int, filled:int, remaining:int, percent:float,
     *                          complete:bool, amount:float, earned:float, paying:bool}>
     */
    public static function blocks(int $seq, int $n, ?array $amounts = null, ?PDO $pdo = null): array
    {
        $ladder = $amounts ?? GeneralHarvestLevel::amounts($pdo);
        $depth  = GeneralHarvestLevel::maxLevels();
        $blocks = [];

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $slots    = GeneralHarvestLevel::slots($level);
            $start    = GeneralHarvestLevel::blockStart($seq, $level);
            $filled   = GeneralHarvestLevel::filled($seq, $level, $n);
            $complete = GeneralHarvestLevel::complete($seq, $level, $n);
            $amount   = (float) ($ladder[$level] ?? 0.0);

            $blocks[$level] = [
                'level'     => $level,
                'slots'     => $slots,
                'start'     => $start,
                'from'      => $start + 1,
                'to'        => $start + $slots,
                'threshold' => GeneralHarvestLevel::threshold($seq, $level),
                'filled'    => $filled,
                'remaining' => $slots - $filled,
                'percent'   => round(100 * $filled / $slots, 1),
                'complete'  => $complete,
                'amount'    => $amount,
                'earned'    => $complete ? $amount : 0.0,
                'paying'    => $level <= $depth,
            ];
        }

        return $blocks;
    }

    /**
     * A page of the occupants of node `$seq`'s level-`$level` block — the
     * members whose queue positions fill that block, in position order.
     *
     * The block spans positions `start+1 … start+3^L` where
     * `start = 3^L·seq + (3^L − 1)/2` (§12.2). A page costs one bounded
     * read of `harvest_queue` — no award row is read, and nothing here is
     * money. `$n` may be supplied when the caller has already read the
     * queue, which is what keeps the roster at one read per page
     * (P21F-T19). Usernames are returned raw; the caller applies D-20
     * privacy (show a username only inside the viewer's sponsor subtree).
     *
     * @return array{level:int, seq:int, offset:int, limit:int, total:int,
     *               filled:int, from:int, to:int, n:int,
     *               rows:list<array{position:int, user_id:int, username:string}>}
     */
    public static function blockOccupants(
        int $seq,
        int $level,
        int $offset,
        int $limit,
        ?PDO $pdo = null,
        ?int $n = null
    ): array {
        if ($level < GeneralHarvestLevel::LEVEL_MIN || $level > GeneralHarvestLevel::LEVEL_MAX) {
            throw new InvalidArgumentException(sprintf(
                'A General Harvest level is %d–%d; got %d.',
                GeneralHarvestLevel::LEVEL_MIN,
                GeneralHarvestLevel::LEVEL_MAX,
                $level
            ));
        }

        $pdo    ??= db();
        $offset   = max(0, $offset);
        $limit    = max(1, min(self::PAGE_MAX, $limit));
        /* A caller that already read the queue (the roster's page does)
         * passes `N` and the page costs its one bounded read only. */
        $n        = $n ?? self::queueN($pdo)['n'];
        $start    = GeneralHarvestLevel::blockStart($seq, $level);
        $slots    = GeneralHarvestLevel::slots($level);
        $total    = $slots;
        $from     = $start + 1;
        $to       = $start + $slots;
        $rows     = [];

        /* Only positions the queue has actually reached can be shown. */
        $count = min($limit, max(0, min($to, $n) - max($from, 1) + 1));
        $first = max($from, 1);

        if ($count > 0 && $first <= $n) {
            $statement = $pdo->prepare(
                'SELECT q.id AS position, q.user_id, u.username
                   FROM harvest_queue q
                   JOIN users u ON u.id = q.user_id
                  WHERE q.id BETWEEN ? AND ?
                  ORDER BY q.id
                  LIMIT ? OFFSET ?'
            );
            $statement->execute([$first, min($to, $n), $count, $offset]);

            foreach ($statement->fetchAll() as $row) {
                $rows[] = [
                    'position' => (int) $row['position'],
                    'user_id'  => (int) $row['user_id'],
                    'username' => (string) $row['username'],
                ];
            }
        }

        return [
            'level'  => $level,
            'seq'    => $seq,
            'offset' => $offset,
            'limit'  => $limit,
            'total'  => $total,
            'filled' => min($slots, max(0, min($to, $n) - $from + 1)),
            'from'   => $from,
            'to'     => $to,
            'n'      => $n,
            'rows'   => $rows,
        ];
    }

    /**
     * A page of the board's structure at one level: the nodes whose
     * level-`$level` block the queue has already filled, in node order.
     *
     * The set is the closed form's prefix `0 … paid(L, N) − 1` (§12.3
     * Rule 2), so a page costs one bounded read of the queue — no award
     * row is read, and nothing here is money.
     *
     * @return array{level:int, offset:int, limit:int, total:int, n:int,
     *               rows:list<array{seq:int, position:int, user_id:int, username:string,
     *                                 threshold:int, amount:float}>}
     */
    public static function blockRange(int $level, int $offset, int $limit, ?PDO $pdo = null): array
    {
        if ($level < GeneralHarvestLevel::LEVEL_MIN || $level > GeneralHarvestLevel::LEVEL_MAX) {
            throw new InvalidArgumentException(sprintf(
                'A General Harvest level is %d–%d; got %d.',
                GeneralHarvestLevel::LEVEL_MIN,
                GeneralHarvestLevel::LEVEL_MAX,
                $level
            ));
        }

        $pdo    ??= db();
        $offset   = max(0, $offset);
        $limit    = max(1, min(self::PAGE_MAX, $limit));
        $n        = self::queueN($pdo)['n'];
        $total    = GeneralHarvestLevel::paid($level, $n);
        $amount   = GeneralHarvestLevel::amount($level, $pdo);
        $rows     = [];

        if ($offset < $total) {
            $count = min($limit, $total - $offset);

            $statement = $pdo->prepare(
                'SELECT q.id AS position, q.user_id, u.username
                   FROM harvest_queue q
                   JOIN users u ON u.id = q.user_id
                  WHERE q.id BETWEEN ? AND ?
                  ORDER BY q.id'
            );
            $statement->execute([$offset + 1, $offset + $count]);

            foreach ($statement->fetchAll() as $row) {
                $seq = (int) $row['position'] - 1;

                $rows[] = [
                    'seq'       => $seq,
                    'position'  => (int) $row['position'],
                    'user_id'   => (int) $row['user_id'],
                    'username'  => (string) $row['username'],
                    'threshold' => GeneralHarvestLevel::threshold($seq, $level),
                    'amount'    => $amount,
                ];
            }
        }

        return [
            'level'  => $level,
            'offset' => $offset,
            'limit'  => $limit,
            'total'  => $total,
            'n'      => $n,
            'rows'   => $rows,
        ];
    }
}
