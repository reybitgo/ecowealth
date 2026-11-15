<?php
declare(strict_types=1);

/* ==============================================================
 *  HarvestQueue — qualification, positions, teammate counters, the
 *  3×10 matrix and the two structure jobs (plan §7 Phase 20C)
 *
 *  The queue is the **irreversible** part of the Team Harvest bonus:
 *
 *      harvest_queue.id                 the position (1-based, frozen)
 *      harvest_queue.teammates          n — the maintained counter
 *      harvest_queue.baseline_n         n at the go-live instant
 *
 *  Three writers, and no others (plan §12.3, D-01/D-03/D-04):
 *
 *      registration  qualifySponsor() from RegistrationService, inside
 *                    the registration's own transaction (P20C-T23)
 *      seed          seed() — the one-time go-live reconstruction
 *                    (bin/harvest_queue_seed.php, §14.10)
 *      repair        rebuild(apply: true) — appends a gate holder that
 *                    has no row and repairs a counter, **after** the
 *                    existing positions and never renumbering one
 *
 *  The two definitions everything else is derived from (§12.4–§12.5):
 *
 *      teammate(d, i) ⇔ i is a proper sponsor descendant of d
 *                       ∧ i is queued ∧ position(i) > position(d)
 *      n(d)           = |{ i : teammate(d, i) }|
 *
 *  `n` is a **counter**, so it needs a proof: recount() computes the
 *  definition, rebuild() compares every counter with it and reports the
 *  drift, and verify() reports a corrupt tree. A disagreement is a
 *  defect, never "close enough" (§14.4).
 *
 *  Nothing here pays. This class writes no `team_harvest_awards` row, no
 *  `ewallet_ledger` row and no wallet balance (Rule 11, P20C-T22); it
 *  never echoes and never reads a request. The one place it touches
 *  money is `baselineReport()`, a read-only projection of the ladder the
 *  seed prints so an operator can see what the baseline excludes (D-07);
 *  it writes nothing and decides nothing — the award rule is 20D's.
 *
 *  Depth is unbounded: the sponsor tree is walked with an explicit
 *  visited set, never with SponsorService's ten-level cap (Rule 6/7,
 *  P20C-T16).
 * ============================================================== */

final class HarvestQueue
{
    /** The advisory lock the seed holds against a concurrent run (§14.8, §14.10). */
    public const SEED_LOCK = 'harvest_queue_seed';

    /** How long the seed waits for that lock, in seconds. */
    public const SEED_LOCK_TIMEOUT = 5;

    /** The three `harvest_queue.source` values the schema allows (§13.2). */
    public const SOURCE_REGISTRATION = 'registration';
    public const SOURCE_SEED         = 'seed';
    public const SOURCE_REPAIR       = 'repair';
    public const SOURCES             = [self::SOURCE_REGISTRATION, self::SOURCE_SEED, self::SOURCE_REPAIR];

    /** How many positions a seed/rebuild summary carries before it truncates. */
    public const POSITION_LIMIT = 500;

    /** How many positions the seed's human output prints (§14.10). */
    public const PREVIEW_LIMIT = 20;

    /** The seed's and the rebuild's audit actions (§14.4, §14.10). */
    public const AUDIT_SEED   = 'harvest.seed';
    public const AUDIT_REPAIR = 'harvest.queue.repair';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /* ==========================================================
     *  Reads — the only way a later phase may learn a standing
     * ========================================================== */

    /**
     * One queue row, or null when the member is not queued.
     *
     * `position` is `id`: the column the arithmetic and every later
     * phase read (D-03).
     *
     * @return array{position:int, user_id:int, qualified_at:string, directs_at_qualification:int,
     *               teammates:int, baseline_n:int, source:string, created_at:string}|null
     */
    public function row(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source, created_at
               FROM harvest_queue
              WHERE user_id = ?
              LIMIT 1'
        );
        $statement->execute([$userId]);

        $row = $statement->fetch();

        return $row === false ? null : self::shape($row);
    }

    /** A member's position, or null when they are not queued. */
    public function positionOf(int $userId): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM harvest_queue WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /** Is this member in the queue at all? One row per member, for ever. */
    public function isQueued(int $userId): bool
    {
        return $this->positionOf($userId) !== null;
    }

    /**
     * Every queued member's position in one query, keyed by user id.
     *
     * The bulk read (§14.9): a caller that needs several members' places
     * asks once. Ordered by position, so a plain foreach is the queue.
     *
     * @return array<int, int> user_id => position
     */
    public function map(): array
    {
        $statement = $this->pdo->query('SELECT id, user_id FROM harvest_queue ORDER BY id');

        $map = [];

        foreach ($statement->fetchAll() as $row) {
            $map[(int) $row['user_id']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * The member's stored teammate counter `n` (D-05).
     *
     * 20C's own readers use recount() when they need the authority;
     * 20D reads this one (§14.4 Rule 1).
     */
    public function teammatesOf(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT teammates FROM harvest_queue WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? 0 : (int) $value;
    }

    /** The member's `baseline_n`, or 0 when they are not queued (or queued after go-live). */
    public function baselineN(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT baseline_n FROM harvest_queue WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? 0 : (int) $value;
    }

    /**
     * The member's **direct member accounts** — never staff, never the
     * company (§12.2 Rule 1/4).
     *
     * Status is not read here on purpose (D-02): a pending, inactive or
     * suspended direct counts exactly like an active one.
     */
    public function directs(int $userId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND role = 'member'");
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * The go-live baseline instant: `team_harvest_ledger_since`, or null
     * while the queue is unseeded.
     *
     * It is the proof that the queue was seeded — not a first-payable
     * date — so an empty value means "the seed has not run" (D-04).
     */
    public function baseline(): ?string
    {
        $value = trim((string) setting('team_harvest_ledger_since', ''));

        return $value === '' ? null : $value;
    }

    /** Has the seed run? The live hook is inert until it has (Rule 2). */
    public function seeded(): bool
    {
        return $this->baseline() !== null;
    }

    /**
     * The queue's shape, for the seed output and the settings card
     * (§14.2). `positions` is the highest position ever assigned;
     * `next` is the position the sequence will hand out next.
     *
     * `awards` is read (never written) so a summary can say "0 awards" —
     * that is the whole of 20C's interest in the ledger table.
     *
     * @return array{queued:int, positions:int, next:int, deepest:int, seeded:bool,
     *               baseline:?string, awards:int}
     */
    public function stats(): array
    {
        $row = $this->pdo->query(
            'SELECT COUNT(*) AS queued,
                    COALESCE(MAX(id), 0) AS positions,
                    COALESCE(MAX(teammates), 0) AS deepest
               FROM harvest_queue'
        )->fetch() ?: [];

        $positions = (int) ($row['positions'] ?? 0);

        return [
            'queued'    => (int) ($row['queued'] ?? 0),
            'positions' => $positions,
            'next'      => $positions + 1,
            'deepest'   => (int) ($row['deepest'] ?? 0),
            'seeded'    => $this->seeded(),
            'baseline'  => $this->baseline(),
            'awards'    => (int) $this->pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn(),
        ];
    }

    /* ==========================================================
     *  Ancestors and teammates
     * ========================================================== */

    /**
     * The member's queued ancestors, nearest first (§12.4 Rule 4).
     *
     * Every entry is a queue row plus its `level` (hops above the member:
     * 1 = the direct sponsor). A non-queued ancestor is skipped, and the
     * walk is cycle-safe, so a corrupt `sponsor_id` loop terminates and
     * is reported by verify() instead of hanging a registration.
     *
     * @return list<array{level:int, position:int, user_id:int, username:string,
     *                    teammates:int, baseline_n:int, source:string}>
     */
    public function ancestorsOf(int $userId): array
    {
        $chain = $this->sponsorChain($userId);

        if ($chain === []) {
            return [];
        }

        $rows = $this->queueRowsFor($chain);
        $ancestors = [];

        foreach ($chain as $index => $ancestorId) {
            if (!isset($rows[$ancestorId])) {
                continue;
            }

            $row = $rows[$ancestorId];

            $ancestors[] = [
                'level'      => $index + 1,
                'position'   => (int) $row['id'],
                'user_id'    => $ancestorId,
                'username'   => (string) ($row['username'] ?? ''),
                'teammates'  => (int) $row['teammates'],
                'baseline_n' => (int) $row['baseline_n'],
                'source'     => (string) $row['source'],
            ];
        }

        return $ancestors;
    }

    /**
     * The member's **teammates** in position order — the roster the
     * matrix is filled from (§12.4, §12.5 Rule 2).
     *
     * Only proper descendants, only queued ones, only positions greater
     * than the member's own — the definition, computed from a children
     * map built once (the RoyaltyRank::map() idiom, no recursion).
     *
     * `$limit`/`$offset` slice the roster **after** it is ordered, so a
     * page of it costs the same walk and one query.
     *
     * @return list<array{position:int, user_id:int, username:string, qualified_at:string,
     *                    teammates:int, member_status:string}>
     */
    public function teamRoster(int $userId, int $limit = 0, int $offset = 0): array
    {
        $members = $this->teammatePositions($userId);

        if ($members === []) {
            return [];
        }

        $offset = max(0, $offset);
        $slice  = array_slice($members, $offset, $limit > 0 ? $limit : null);
        $ids    = array_values($slice);   // user ids, in position order

        if ($ids === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($ids), '?'));

        $statement = $this->pdo->prepare(
            'SELECT q.id AS position, q.user_id, q.qualified_at, q.teammates,
                    u.username, u.member_status
               FROM harvest_queue q
               JOIN users u ON u.id = q.user_id
              WHERE q.user_id IN (' . $marks . ')
              ORDER BY q.id'
        );
        $statement->execute(array_map('intval', $ids));

        return array_map(static fn (array $row): array => [
            'position'      => (int) $row['position'],
            'user_id'       => (int) $row['user_id'],
            'username'      => (string) $row['username'],
            'qualified_at'  => (string) $row['qualified_at'],
            'teammates'     => (int) $row['teammates'],
            'member_status' => (string) $row['member_status'],
        ], $statement->fetchAll());
    }

    /**
     * The authoritative `n`, computed from the tree instead of read from
     * the counter (§14.4 Rule 1).
     *
     * One query for the forest's children map and one for the positions
     * — never the counter it is checking.
     */
    public function recount(int $userId): int
    {
        $position = $this->positionOf($userId);

        if ($position === null) {
            return 0;
        }

        $descendants = $this->subtreeIds($userId, $this->childrenMap());

        if ($descendants === []) {
            return 0;
        }

        return $this->countTeammates($descendants, $this->map(), $position);
    }

    /**
     * Every queued member's recount in one pass, keyed by user id.
     *
     * The same definition as recount() walked the other way — each queued
     * member credits every queued ancestor with a smaller position — so
     * the whole network costs two queries instead of one per member
     * (§14.9's O(members) pass).
     *
     * @return array<int, int> user_id => n
     */
    public function recountAll(): array
    {
        return $this->recountFrom($this->map());
    }

    /**
     * The same pass over an explicit `user_id => position` map, so the
     * seed can project a queue that does not exist yet (its dry run).
     *
     * @param array<int, int> $byUser
     * @return array<int, int> user_id => n
     */
    private function recountFrom(array $byUser): array
    {
        $parents = $this->parentMap();

        if ($byUser === []) {
            return [];
        }

        $counts = array_fill_keys(array_keys($byUser), 0);

        foreach ($byUser as $userId => $position) {
            $visited = [$userId => true];
            $current = (int) ($parents[$userId] ?? 0);

            while ($current > 0 && !isset($visited[$current])) {
                $visited[$current] = true;

                if (isset($byUser[$current]) && $byUser[$current] < $position) {
                    $counts[$current]++;
                }

                $current = (int) ($parents[$current] ?? 0);
            }
        }

        return $counts;
    }

    /* ==========================================================
     *  The matrix (plan §12.5)
     * ========================================================== */

    /**
     * A page of the member's private 3×10 matrix (§12.5).
     *
     * Slot `r` is 0-based; the level of a slot is `floor(log3(2r+3))`,
     * which the slot walk below computes without a float. Teammates fill
     * the slots in position order and `placed_under(r)` is
     * `r < 3 ? "you" : teammate[r/3 − 1]` — arithmetic, never
     * sponsorship (Rule 1).
     *
     * Rows returned = `min(n − $offset, $limit)`: the matrix has exactly
     * `n` filled slots, so a partial block ends the list (§12.5 Rule 4).
     *
     * @return list<array{slot:int, level:int, start:int, slots:int, position:int,
     *                    user_id:int, username:string, qualified_at:string,
     *                    placed_under:array{user_id:int, username:string, position:int}}>
     */
    public function matrix(int $userId, int $offset = 0, int $limit = 100): array
    {
        $offset = max(0, $offset);
        $limit  = max(1, min(1000, $limit));
        $row    = $this->row($userId);

        if ($row === null) {
            return [];
        }

        $n = (int) $row['teammates'];

        if ($offset >= $n) {
            return [];
        }

        $count = min($n - $offset, $limit);

        /* The roster is only needed as far as the last slot this page
         * shows: the occupant of slot r is roster[r], and every
         * `placed_under` index is smaller than r. */
        $roster = $this->teamRoster($userId, min($n, $offset + $limit));
        $member = $this->accountRow($userId);
        $self   = [
            'user_id'  => $userId,
            'username' => (string) ($member['username'] ?? ''),
            'position' => (int) $row['position'],
        ];

        $slots = [];

        for ($index = 0; $index < $count; $index++) {
            $slot = $offset + $index;

            if (!isset($roster[$slot])) {
                break;   // defensive: the counter and the roster disagree, which rebuild() reports
            }

            $level   = self::levelOfSlot($slot);
            $under   = $slot < 3 ? $self : $roster[intdiv($slot, 3) - 1];
            $slots[] = [
                'slot'       => $slot,
                'level'      => $level,
                'start'      => self::startOfLevel($level),
                'slots'      => self::slotsOfLevel($level),
                'position'   => $roster[$slot]['position'],
                'user_id'    => $roster[$slot]['user_id'],
                'username'   => $roster[$slot]['username'],
                'qualified_at' => $roster[$slot]['qualified_at'],
                'placed_under' => [
                    'user_id'  => (int) $under['user_id'],
                    'username' => (string) $under['username'],
                    'position' => (int) $under['position'],
                ],
            ];
        }

        return $slots;
    }

    /**
     * The level a 0-based slot belongs to: 1 for slots 0–2, 2 for 3–11,
     * 3 for 12–38, and so on (§12.5).
     */
    public static function levelOfSlot(int $slot): int
    {
        $slot  = max(0, $slot);
        $start = 0;
        $level = 1;

        while ($level < 60) {
            $slots = self::slotsOfLevel($level);

            if ($slot < $start + $slots) {
                return $level;
            }

            $start += $slots;
            $level++;
        }

        return $level;
    }

    /**
     * `3^L` — the slots in level L (§12.5).
     *
     * Levels 1–10 are the plan's ladder and read through
     * TeamHarvestLevel, so there is one definition of the arithmetic the
     * money depends on. The matrix itself is unbounded (Rule 3), so a
     * deeper slot falls back to the same formula here.
     */
    public static function slotsOfLevel(int $level): int
    {
        $level = max(1, $level);

        return $level <= TeamHarvestLevel::LEVEL_MAX
            ? TeamHarvestLevel::slots($level)
            : 3 ** $level;
    }

    /**
     * `(3^L − 3)/2` — the first slot of level L: 0, 3, 12, 39, 120 …
     *
     * For levels 1–10 it is `threshold(L) − slots(L)`, again through
     * TeamHarvestLevel (§12.5).
     */
    public static function startOfLevel(int $level): int
    {
        $level = max(1, $level);

        return $level <= TeamHarvestLevel::LEVEL_MAX
            ? TeamHarvestLevel::threshold($level) - TeamHarvestLevel::slots($level)
            : intdiv(3 ** ($level + 1) - 3, 2) - 3 ** $level;
    }

    /* ==========================================================
     *  The registration hook (plan §12.2, §14.3)
     * ========================================================== */

    /**
     * Check one sponsor after a member account has been created.
     *
     * Returns the new position when this event opened the member's gate,
     * and null in every other case — not seeded, the account is not a
     * member, already queued, or below the gate. Null is the normal
     * answer: it is what keeps the hook free (§14.3).
     *
     * Runs inside the caller's transaction (the registration's own), and
     * opens one only when the caller has none. A failure propagates, so a
     * registration that cannot record its qualification rolls back
     * (Rule 3, P20C-T23).
     */
    public function qualifySponsor(int $sponsorId): ?int
    {
        if ($sponsorId <= 0 || !$this->seeded()) {
            return null;   // Rule 2: the hook is inert until the seed has run
        }

        $owns = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            /* The locked read serialises two simultaneous registrations
             * under the same sponsor: the second one waits, then sees the
             * queue row the first wrote and returns null (P20C-T2). */
            $statement = $this->pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $statement->execute([$sponsorId]);
            $sponsor = $statement->fetch();

            if ($sponsor === false || (string) $sponsor['role'] !== 'member') {
                return $this->finish($owns, null);   // Rule 4: staff and the company never qualify
            }

            if ($this->isQueued($sponsorId)) {
                return $this->finish($owns, null);
            }

            $gate    = TeamHarvestLevel::qualifyDirects();
            $directs = $this->directRows($sponsorId);

            if (count($directs) < $gate) {
                return $this->finish($owns, null);
            }

            /* Rule 12: the qualification instant is the gate-th direct's
             * created_at — the same rule the seed reconstructs with, so
             * a live row and a seeded row can never disagree about what
             * "qualified at" means. */
            $qualifiedAt = (string) $directs[$gate - 1]['created_at'];

            try {
                $position = $this->insert($sponsorId, $qualifiedAt, count($directs), self::SOURCE_REGISTRATION);
            } catch (PDOException $e) {
                /* uq_queue_user: a parallel transaction queued the same
                 * member between the check and the insert. It owns the
                 * counter increments; this event simply did not qualify
                 * anybody. A failure anywhere else still propagates. */
                if ((string) $e->getCode() === '23000') {
                    return $this->finish($owns, null);
                }

                throw $e;
            }

            $this->incrementAncestors($sponsorId, $position);

            return $this->finish($owns, $position);
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Append one queue row and return its position.
     *
     * `id` is the position: assigned by auto-increment, never computed,
     * never renumbered (D-03). The caller owns the transaction — and the
     * ancestor increments that go with the row.
     *
     * `$directs` is the direct count that opened the gate: the observed
     * count on the live path, and the gate itself on a reconstructed row
     * (at that instant the member held exactly the gate — §13.2).
     */
    public function insert(
        int $userId,
        string $qualifiedAt,
        int $directs,
        string $source = self::SOURCE_REGISTRATION,
        int $teammates = 0,
        int $baselineN = 0
    ): int {
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unknown harvest_queue source: ' . $source);
        }

        $this->pdo->prepare(
            'INSERT INTO harvest_queue
               (user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $qualifiedAt,
            max(1, $directs),
            max(0, $teammates),
            max(0, $baselineN),
            $source,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Move every **queued ancestor** of a newly qualified member by one.
     *
     * The reference's `counts()` walk with no level cap (Rule 7): the
     * chain is walked from the member's sponsor upwards, an ancestor is
     * touched only when it is queued **and** its position is smaller
     * than the newcomer's (the "qualified after me" rule in SQL, §14.3),
     * and the whole set moves in one statement.
     *
     * Returns the user ids that moved.
     *
     * @return list<int>
     */
    public function incrementAncestors(int $userId, int $position): array
    {
        $chain = $this->sponsorChain($userId);

        if ($chain === []) {
            return [];
        }

        $rows    = $this->queueRowsFor($chain);
        $touched = [];

        foreach ($chain as $ancestorId) {
            if (isset($rows[$ancestorId]) && (int) $rows[$ancestorId]['id'] < $position) {
                $touched[] = $ancestorId;
            }
        }

        if ($touched === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($touched), '?'));

        $this->pdo->prepare(
            'UPDATE harvest_queue
                SET teammates = teammates + 1
              WHERE user_id IN (' . $marks . ') AND id < ?'
        )->execute([...array_map('intval', $touched), $position]);

        return $touched;
    }

    /* ==========================================================
     *  The one-time seed (plan §14.10, D-04)
     * ========================================================== */

    /**
     * Reconstruct the queue for the tree that already exists.
     *
     * Deterministic — the order is the gate-th direct's `(created_at, id)`
     * (or the member's own id with `$byId`) — and idempotent: a real run
     * on a seeded queue is refused, `--force` appends only what is
     * missing, and a second run writes nothing (Rule 8, P20C-T13).
     *
     * `$dryRun` computes and reports everything and writes nothing; it is
     * allowed on a non-empty queue, where it also reports the diff
     * against the positions already assigned (P20C-T12).
     *
     * One transaction covers the rows, the counters, the baseline_n of
     * the rows it creates and the baseline instant; the audit row is part
     * of it, so a crash leaves no half-seeded queue (§14.8).
     *
     * @return array<string, mixed>
     */
    public function seed(bool $dryRun = false, bool $byId = false, bool $force = false): array
    {
        $started = microtime(true);
        $gate    = $this->rawGate();

        if ($gate === null) {
            return $this->seedAnswer($dryRun, [
                'refused' => 'gate',
                'message' => 'team_harvest_qualify_directs is missing or outside 1–10; nothing was written.',
            ], $started);
        }

        if (!$this->acquireSeedLock()) {
            return $this->seedAnswer($dryRun, [
                'refused' => 'locked',
                'message' => 'Another seed run holds the ' . self::SEED_LOCK . ' lock; nothing was written.',
            ], $started);
        }

        try {
            $queued    = (int) $this->pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn();
            $candidates = $this->candidates($gate, $byId);

            if (!$dryRun && !$force) {
                if ($this->seeded()) {
                    return $this->seedAnswer($dryRun, [
                        'refused'  => 'already',
                        'seeded'   => 'already',
                        'gate'     => $gate,
                        'queued'   => $queued,
                        'members'  => count($candidates),
                        'message'  => 'The queue is already seeded (team_harvest_ledger_since = '
                            . (string) $this->baseline() . ') and holds ' . $queued
                            . ' position(s); use --dry-run to preview or --force to append the '
                            . (count($candidates) - $queued) . ' member(s) that are missing.',
                    ], $started);
                }

                if ($queued > 0) {
                    return $this->seedAnswer($dryRun, [
                        'refused'  => 'not_empty',
                        'seeded'   => 'already',
                        'gate'     => $gate,
                        'queued'   => $queued,
                        'members'  => count($candidates),
                        'message'  => 'The queue already holds ' . $queued . ' position(s); a seed refuses a'
                            . ' non-empty queue without --force (use --dry-run to preview, --force to append'
                            . ' only the members that are missing).',
                    ], $started);
                }
            }

            $existing = $this->map();
            $missing  = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => !isset($existing[$candidate['user_id']])
            ));

            /* A fresh seed numbers the queue 1 … Q in reconstruction
             * order; `--force` appends after the positions already
             * assigned (Rule 8/10 — never before, never renumbered). */
            $assigned = $existing;
            $number   = $existing === [] ? 1 : max($existing) + 1;
            $rank     = [];

            foreach ($candidates as $index => $candidate) {
                $rank[$candidate['user_id']] = $index + 1;
            }

            foreach ($missing as $candidate) {
                $assigned[$candidate['user_id']] = $number++;
            }

            $positions = [];

            foreach ($candidates as $candidate) {
                $positions[] = $candidate + [
                    'position' => $assigned[$candidate['user_id']],
                    'source'   => isset($existing[$candidate['user_id']]) ? 'already queued' : 'seed',
                ];
            }

            $summary = [
                'gate'     => $gate,
                'order'    => $byId ? 'id' : 'qualified_at',
                'members'  => count($candidates),
                'queued'   => $queued,
                'inserted' => 0,
                'skipped'  => count($candidates) - count($missing),
                'positions' => array_slice($positions, 0, self::POSITION_LIMIT),
                'truncated' => count($positions) > self::POSITION_LIMIT,
                'preview'  => array_slice($positions, 0, self::PREVIEW_LIMIT),
                'instant'  => null,
                'baseline' => $this->baselineReport($missing, $assigned),
                'diff'     => $this->seedDiff($candidates, $rank, $existing),
            ];

            if ($dryRun) {
                return $this->seedAnswer(true, ['ok' => true, 'seeded' => 'preview'] + $summary, $started);
            }

            $owns = !$this->pdo->inTransaction();

            if ($owns) {
                $this->pdo->beginTransaction();
            }

            try {
                $inserted = 0;

                foreach ($missing as $candidate) {
                    $this->insert(
                        $candidate['user_id'],
                        $candidate['qualified_at'],
                        $gate,
                        self::SOURCE_SEED
                    );
                    $inserted++;
                }

                /* The counters and the baseline: a full recount over the
                 * rows just written, then `baseline_n = teammates` for the
                 * rows this run created. An existing row keeps the
                 * baseline it was written with (Rule 9, P20C-T21). */
                $counts  = $this->recountAll();
                $update  = $this->pdo->prepare('UPDATE harvest_queue SET teammates = ? WHERE user_id = ?');
                $baseline = $this->pdo->prepare(
                    'UPDATE harvest_queue SET baseline_n = ? WHERE user_id = ?'
                );

                foreach ($counts as $userId => $n) {
                    $update->execute([$n, $userId]);
                }

                foreach ($missing as $candidate) {
                    $baseline->execute([$counts[$candidate['user_id']] ?? 0, $candidate['user_id']]);
                }

                /* The real positions the sequence assigned. A preview
                 * predicts from MAX(id)+1; the run reports what the rows
                 * actually hold, because a position is a fact (D-03). */
                $actual = $this->map();

                foreach ($positions as $index => $position) {
                    $positions[$index]['position'] = $actual[$position['user_id']] ?? $position['position'];
                }

                $summary['positions'] = array_slice($positions, 0, self::POSITION_LIMIT);
                $summary['preview']   = array_slice($positions, 0, self::PREVIEW_LIMIT);

                $instant = date('Y-m-d H:i:s');

                if (!$this->seeded() || $force) {
                    $this->writeBaseline($instant);
                }

                $summary['instant']  = $this->baseline();
                $summary['inserted'] = $inserted;

                AuditLog::record([
                    'action'       => self::AUDIT_SEED,
                    'actor_role'   => 'system',
                    'subject_type' => 'harvest_queue',
                    'subject_id'   => null,
                    'meta'         => [
                        'gate'      => $gate,
                        'order'     => $summary['order'],
                        'inserted'  => $inserted,
                        'members'   => $summary['members'],
                        'skipped'   => $summary['skipped'],
                        'forced'    => $force,
                        'instant'   => $summary['instant'],
                        'baseline'  => [
                            'levels'  => $summary['baseline']['levels'],
                            'amount'  => $summary['baseline']['amount'],
                            'members' => $summary['baseline']['members'],
                        ],
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

            $summary['baseline'] = $this->baselineReport($missing, $this->map());

            return $this->seedAnswer(false, ['ok' => true, 'seeded' => 'ran'] + $summary, $started);
        } finally {
            $this->releaseSeedLock();
        }
    }

    /* ==========================================================
     *  The rebuild / repair drill (plan §14.4, Rule 10)
     * ========================================================== */

    /**
     * Compare every stored counter with a recount, and optionally repair.
     *
     * Reports three things and writes nothing unless `$apply`:
     *
     *   drift       a counter the recount disagrees with (`stored` vs
     *               `recount`) — a defect, never a variant
     *   violations  a recount **below** `baseline_n`, which the schema's
     *               CHECK forbids: that can only mean a descendant left
     *               the tree, so it is refused rather than applied
     *   missing     a member who holds the gate but has no row; `--apply`
     *               **appends** them after the existing positions with
     *               `source = 'repair'`, and never renumbers one
     *
     * `$dryRun` wins over `$apply`, so a preview cannot write.
     *
     * @return array<string, mixed>
     */
    public function rebuild(bool $dryRun = true, bool $apply = false): array
    {
        $started = microtime(true);
        $apply   = $apply && !$dryRun;
        $gate    = $this->rawGate();

        $rows   = $this->queueRows();
        $counts = $this->recountAll();
        $forest = $this->forest();

        $drift      = [];
        $violations = [];

        foreach ($rows as $row) {
            $userId  = (int) $row['user_id'];
            $stored  = (int) $row['teammates'];
            $count   = $counts[$userId] ?? 0;

            if ($count === $stored) {
                continue;
            }

            $entry = [
                'position'   => (int) $row['id'],
                'user_id'    => $userId,
                'username'   => (string) ($forest['usernames'][$userId] ?? ''),
                'stored'     => $stored,
                'recount'    => $count,
                'baseline_n' => (int) $row['baseline_n'],
            ];

            if ($count < (int) $row['baseline_n']) {
                $violations[] = $entry + [
                    'message' => 'A recount of ' . $count . ' is below the stored baseline of '
                        . (int) $row['baseline_n'] . '; the counter is refused rather than lowered'
                        . ' (chk_hq_baseline).',
                ];

                continue;
            }

            $drift[] = $entry;
        }

        $missing  = $gate === null ? [] : $this->missingCandidates($forest, $gate, $this->userIds($rows));
        $appended = [];
        $repaired = [];

        if ($apply) {
            $owns = !$this->pdo->inTransaction();

            if ($owns) {
                $this->pdo->beginTransaction();
            }

            try {
                foreach ($missing as $candidate) {
                    $position  = $this->insert(
                        $candidate['user_id'],
                        $candidate['qualified_at'],
                        $gate ?? 1,
                        self::SOURCE_REPAIR
                    );
                    $this->incrementAncestors($candidate['user_id'], $position);
                    $appended[] = $candidate + ['position' => $position];
                }

                /* Appending moves counters, so the repairs are applied from
                 * a recount taken after the appends. */
                if ($drift !== []) {
                    $counts = $this->recountAll();
                    $update = $this->pdo->prepare('UPDATE harvest_queue SET teammates = ? WHERE user_id = ?');

                    foreach ($drift as $entry) {
                        $count = $counts[$entry['user_id']] ?? 0;

                        if ($count < $entry['baseline_n']) {
                            $violations[] = $entry + [
                                'recount' => $count,
                                'message' => 'A recount of ' . $count . ' is below the stored baseline of '
                                    . $entry['baseline_n'] . '; the counter was not repaired.',
                            ];

                            continue;
                        }

                        $update->execute([$count, $entry['user_id']]);
                        $repaired[] = $entry + ['after' => $count];
                    }
                }

                if ($appended !== [] || $repaired !== []) {
                    AuditLog::record([
                        'action'       => self::AUDIT_REPAIR,
                        'actor_role'   => 'system',
                        'subject_type' => 'harvest_queue',
                        'subject_id'   => null,
                        'meta'         => [
                            'appended' => array_map(
                                static fn (array $row): array => [
                                    'user_id'  => $row['user_id'],
                                    'position' => $row['position'],
                                ],
                                $appended
                            ),
                            'repaired' => array_map(
                                static fn (array $row): array => [
                                    'user_id'  => $row['user_id'],
                                    'before'   => $row['stored'],
                                    'after'    => $row['after'],
                                ],
                                $repaired
                            ),
                        ],
                    ], $this->pdo);
                }

                if ($owns) {
                    $this->pdo->commit();
                }
            } catch (Throwable $e) {
                if ($owns && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        return [
            'ok'          => $apply ? $violations === [] : ($drift === [] && $violations === [] && $missing === []),
            'dry_run'     => !$apply,
            'applied'     => $apply,
            'queued'      => count($rows),
            'gate'        => $gate,
            'drift'       => $drift,
            'violations'  => $violations,
            'missing'     => $missing,
            'appended'    => $appended,
            'repaired'    => $repaired,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Structural integrity of the queue and the tree it is derived from.
     *
     * Reports the corrupt rows nothing else can see: a self-sponsor, an
     * account inside a sponsor cycle (unreachable from any root), a queue
     * row whose account is not a member, and every counter the recount
     * disagrees with.
     *
     * @return array{ok:bool, members:int, queued:int, self_sponsored:list<array<string,mixed>>,
     *               cycles:list<array<string,mixed>>, non_members:list<array<string,mixed>>,
     *               drift:list<array<string,mixed>>}
     */
    public function verify(): array
    {
        $forest    = $this->forest();
        $children  = [];
        $roots     = [];
        $self      = [];

        foreach ($forest['parents'] as $id => $sponsor) {
            if ($sponsor === $id) {
                $self[]  = ['user_id' => $id, 'username' => (string) ($forest['usernames'][$id] ?? '')];
                $roots[] = $id;   // its own root: reported once, as a self-sponsor

                continue;
            }

            if ($sponsor > 0 && isset($forest['parents'][$sponsor])) {
                $children[$sponsor][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $seen  = [];
        $stack = array_reverse($roots);

        while ($stack !== []) {
            $id = (int) array_pop($stack);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($children[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        $cycles = [];

        foreach ($forest['parents'] as $id => $sponsor) {
            if (!isset($seen[$id])) {
                $cycles[] = [
                    'user_id'    => $id,
                    'username'   => (string) ($forest['usernames'][$id] ?? ''),
                    'sponsor_id' => $sponsor,
                ];
            }
        }

        $rows       = $this->queueRows();
        $nonMembers = [];

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];

            if (($forest['roles'][$userId] ?? '') !== 'member') {
                $nonMembers[] = [
                    'position' => (int) $row['id'],
                    'user_id'  => $userId,
                    'username' => (string) ($forest['usernames'][$userId] ?? ''),
                    'role'     => (string) ($forest['roles'][$userId] ?? 'missing'),
                ];
            }
        }

        $counts = $this->recountAll();
        $drift  = [];

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $count  = $counts[$userId] ?? 0;

            if ($count !== (int) $row['teammates']) {
                $drift[] = [
                    'position' => (int) $row['id'],
                    'user_id'  => $userId,
                    'username' => (string) ($forest['usernames'][$userId] ?? ''),
                    'stored'   => (int) $row['teammates'],
                    'recount'  => $count,
                ];
            }
        }

        return [
            'ok'             => $self === [] && $cycles === [] && $nonMembers === [] && $drift === [],
            'members'        => count($forest['parents']),
            'queued'         => count($rows),
            'self_sponsored' => $self,
            'cycles'         => $cycles,
            'non_members'    => $nonMembers,
            'drift'          => $drift,
        ];
    }

    /* ==========================================================
     *  Internals — the tree
     * ========================================================== */

    /**
     * The whole sponsor tree in one query: parents, roles, usernames and
     * registration instants (§14.9's single forest read).
     *
     * @return array{parents:array<int,int>, roles:array<int,string>, usernames:array<int,string>,
     *               created:array<int,string>}
     */
    private function forest(): array
    {
        $statement = $this->pdo->query('SELECT id, sponsor_id, role, username, created_at FROM users');

        $forest = ['parents' => [], 'roles' => [], 'usernames' => [], 'created' => []];

        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];

            $forest['parents'][$id]   = (int) ($row['sponsor_id'] ?? 0);
            $forest['roles'][$id]     = (string) $row['role'];
            $forest['usernames'][$id] = (string) $row['username'];
            $forest['created'][$id]   = (string) $row['created_at'];
        }

        return $forest;
    }

    /** @return array<int, int> user_id => sponsor_id (0 for a root) */
    private function parentMap(): array
    {
        $parents = [];

        foreach ($this->pdo->query('SELECT id, sponsor_id FROM users') as $row) {
            $parents[(int) $row['id']] = (int) ($row['sponsor_id'] ?? 0);
        }

        return $parents;
    }

    /** @return array<int, list<int>> sponsor_id => child ids (a self-sponsor is never its own child) */
    private function childrenMap(): array
    {
        $children = [];

        foreach ($this->pdo->query('SELECT id, sponsor_id FROM users') as $row) {
            $id      = (int) $row['id'];
            $sponsor = (int) ($row['sponsor_id'] ?? 0);

            if ($sponsor > 0 && $sponsor !== $id) {
                $children[$sponsor][] = $id;
            }
        }

        return $children;
    }

    /** The member's direct member accounts, oldest first. */
    private function directRows(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, created_at
               FROM users
              WHERE sponsor_id = ? AND role = 'member'
              ORDER BY created_at, id"
        );
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    /**
     * The sponsor chain above a member: nearest first, cycle-safe, with no
     * depth cap (Rule 6/7).
     *
     * Walks one hop at a time rather than loading every account, because
     * this runs inside the registration transaction (§14.9's budget).
     *
     * @return list<int>
     */
    private function sponsorChain(int $userId): array
    {
        $chain   = [];
        $visited = [$userId => true];
        $read    = $this->pdo->prepare('SELECT sponsor_id FROM users WHERE id = ? LIMIT 1');
        $read->execute([$userId]);
        $current = (int) ($read->fetchColumn() ?: 0);

        while ($current > 0 && !isset($visited[$current])) {
            $visited[$current] = true;
            $chain[]           = $current;

            $read->execute([$current]);
            $current = (int) ($read->fetchColumn() ?: 0);
        }

        return $chain;
    }

    /**
     * Every proper descendant of a member, iterative and cycle-safe.
     *
     * @param array<int, list<int>> $children
     * @return list<int>
     */
    private function subtreeIds(int $userId, array $children): array
    {
        $ids     = [];
        $visited = [$userId => true];
        $stack   = array_reverse($children[$userId] ?? []);

        while ($stack !== []) {
            $id = (int) array_pop($stack);

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            $ids[]        = $id;

            foreach (array_reverse($children[$id] ?? []) as $kid) {
                $stack[] = $kid;
            }
        }

        return $ids;
    }

    /**
     * A member's teammates as `position => user_id`, in position order, or
     * an empty list when they are not queued.
     *
     * @return array<int, int> position => user_id, ordered ascending
     */
    private function teammatePositions(int $userId): array
    {
        $position = $this->positionOf($userId);

        if ($position === null) {
            return [];
        }

        $descendants = $this->subtreeIds($userId, $this->childrenMap());

        if ($descendants === []) {
            return [];
        }

        $positions = $this->map();
        $teammates = [];

        foreach ($descendants as $id) {
            if (isset($positions[$id]) && $positions[$id] > $position) {
                $teammates[$positions[$id]] = $id;
            }
        }

        ksort($teammates, SORT_NUMERIC);

        return $teammates;
    }

    /**
     * Count the queued positions in `$ids` that are greater than one.
     *
     * @param list<int> $ids
     * @param array<int, int> $positions user_id => position
     */
    private function countTeammates(array $ids, array $positions, int $position): int
    {
        $count = 0;

        foreach ($ids as $id) {
            if (isset($positions[$id]) && $positions[$id] > $position) {
                $count++;
            }
        }

        return $count;
    }

    /* ==========================================================
     *  Internals — the queue rows
     * ========================================================== */

    /** @return list<array<string, mixed>> every queue row, in position order */
    private function queueRows(): array
    {
        return $this->pdo->query(
            'SELECT id, user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source, created_at
               FROM harvest_queue
              ORDER BY id'
        )->fetchAll();
    }

    /**
     * Queue rows for a set of user ids, keyed by user id, with usernames.
     *
     * @param list<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private function queueRowsFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($userIds), '?'));

        $statement = $this->pdo->prepare(
            'SELECT q.id, q.user_id, q.teammates, q.baseline_n, q.source, u.username
               FROM harvest_queue q
               JOIN users u ON u.id = q.user_id
              WHERE q.user_id IN (' . $marks . ')'
        );
        $statement->execute(array_map('intval', $userIds));

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(int) $row['user_id']] = $row;
        }

        return $rows;
    }

    /** One account's display columns, or an empty array when it is gone. */
    private function accountRow(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$userId]);

        $row = $statement->fetch();

        return $row === false ? [] : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{position:int, user_id:int, qualified_at:string, directs_at_qualification:int,
     *               teammates:int, baseline_n:int, source:string, created_at:string}
     */
    private static function shape(array $row): array
    {
        return [
            'position'                  => (int) $row['id'],
            'user_id'                   => (int) $row['user_id'],
            'qualified_at'              => (string) $row['qualified_at'],
            'directs_at_qualification'  => (int) $row['directs_at_qualification'],
            'teammates'                 => (int) $row['teammates'],
            'baseline_n'                => (int) $row['baseline_n'],
            'source'                    => (string) $row['source'],
            'created_at'                => (string) $row['created_at'],
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<int> */
    private function userIds(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
    }

    /* ==========================================================
     *  Internals — the seed
     * ========================================================== */

    /**
     * The gate as stored, refusing anything outside 1–10 (§14.10 step 2).
     *
     * `TeamHarvestLevel::qualifyDirects()` clamps, which is right for a
     * page and wrong for a job: a hand-edited setting must stop the seed
     * rather than silently seed at 1 or 10.
     */
    private function rawGate(): ?int
    {
        $raw = setting('team_harvest_qualify_directs', null);

        if ($raw === null || preg_match('/\A\d+\z/', trim((string) $raw)) !== 1) {
            return null;
        }

        $gate = (int) trim((string) $raw);

        return $gate >= TeamHarvestLevel::GATE_MIN && $gate <= TeamHarvestLevel::GATE_MAX ? $gate : null;
    }

    /**
     * Every member who holds the gate, in reconstruction order:
     * `(qualified_at, id)` — the gate-th direct's instant — or `id` with
     * `$byId` (§12.3, Rule 8).
     *
     * @return list<array{user_id:int, username:string, qualified_at:string, directs:int}>
     */
    private function candidates(int $gate, bool $byId): array
    {
        $forest = $this->forest();

        return $this->candidatesFrom($forest, $gate, $byId);
    }

    /**
     * @param array{parents:array<int,int>, roles:array<int,string>, usernames:array<int,string>,
     *               created:array<int,string>} $forest
     * @return list<array{user_id:int, username:string, qualified_at:string, directs:int}>
     */
    private function candidatesFrom(array $forest, int $gate, bool $byId): array
    {
        if ($gate < 1) {
            return [];
        }

        $directs = [];

        foreach ($forest['parents'] as $id => $sponsor) {
            if (($forest['roles'][$id] ?? '') !== 'member') {
                continue;   // Rule 4: staff never count as a direct
            }

            if ($sponsor <= 0 || $sponsor === $id) {
                continue;
            }

            $directs[$sponsor][] = $id;
        }

        $candidates = [];

        foreach ($directs as $sponsor => $ids) {
            if (count($ids) < $gate) {
                continue;
            }

            /* Rule 4: a staff account (and therefore the company) is never
             * queued, however many member directs it holds. */
            if (($forest['roles'][$sponsor] ?? '') !== 'member') {
                continue;
            }

            usort($ids, static fn (int $a, int $b): int => [$forest['created'][$a], $a] <=> [$forest['created'][$b], $b]);

            $qualifying = $ids[$gate - 1];

            $candidates[] = [
                'user_id'      => (int) $sponsor,
                'username'     => (string) ($forest['usernames'][$sponsor] ?? ''),
                'qualified_at' => (string) ($forest['created'][$qualifying] ?? ''),
                'directs'      => count($ids),
            ];
        }

        usort($candidates, $byId
            ? static fn (array $a, array $b): int => $a['user_id'] <=> $b['user_id']
            : static fn (array $a, array $b): int => [$a['qualified_at'], $a['user_id']] <=> [$b['qualified_at'], $b['user_id']]);

        return $candidates;
    }

    /**
     * The members a rebuild would append: they hold the gate and have no
     * row. Positions are never taken from an existing member, and the
     * order is the seed's (Rule 10).
     *
     * @param list<int> $queuedUserIds
     * @return list<array{user_id:int, username:string, qualified_at:string, directs:int}>
     */
    private function missingCandidates(array $forest, int $gate, array $queuedUserIds): array
    {
        $queued = array_fill_keys(array_map('intval', $queuedUserIds), true);

        return array_values(array_filter(
            $this->candidatesFrom($forest, $gate, false),
            static fn (array $candidate): bool => !isset($queued[$candidate['user_id']])
        ));
    }

    /**
     * What the baseline excludes, in money: for the members a seed is
     * about to queue (or has just queued), how many levels are already
     * complete at their `n` and what those levels are worth (D-07).
     *
     * A pure projection of the ladder — no award row, no wallet credit
     * and no decision. 20D owns the award rule (§14.5).
     *
     * `$byUser` is the whole queue as a `user_id => position` map, so a
     * dry run can project a queue that does not exist yet.
     *
     * @param list<array<string,mixed>> $missing
     * @param array<int, int> $byUser
     * @return array{levels:int, amount:float, members:int, rows:list<array{user_id:int, n:int, levels:list<int>, amount:float}>}
     */
    private function baselineReport(array $missing, array $byUser): array
    {
        $amounts = TeamHarvestLevel::amounts();
        $max     = TeamHarvestLevel::maxLevels();
        $counts  = $this->recountFrom($byUser);

        $rows    = [];
        $levels  = 0;
        $cents   = 0;

        foreach ($missing as $candidate) {
            $n       = $counts[$candidate['user_id']] ?? 0;
            $complete = [];
            $worth   = 0;

            for ($level = 1; $level <= $max; $level++) {
                if ($n < TeamHarvestLevel::threshold($level)) {
                    break;
                }

                $complete[] = $level;
                $worth     += (int) round(($amounts[$level] ?? 0.0) * 100);
            }

            if ($complete === []) {
                continue;
            }

            $levels += count($complete);
            $cents  += $worth;
            $rows[]  = [
                'user_id' => (int) $candidate['user_id'],
                'n'       => $n,
                'levels'  => $complete,
                'amount'  => $worth / 100,
            ];
        }

        return [
            'levels'  => $levels,
            'amount'  => $cents / 100,
            'members' => count($rows),
            'rows'    => $rows,
        ];
    }

    /**
     * How a would-be order differs from the positions already assigned, so
     * a `--by-id` dry run can show what it would change (P20C-T12).
     *
     * The comparison is the candidate's **rank** in the reconstruction
     * order against the position the queue already gave them — never
     * against a position the run would not change.
     *
     * @param list<array<string,mixed>> $candidates
     * @param array<int, int> $rank user_id => 1-based rank in the order
     * @param array<int, int> $existing user_id => position
     * @return array{changed:int, rows:list<array<string,mixed>>, missing:int, truncated:bool}
     */
    private function seedDiff(array $candidates, array $rank, array $existing): array
    {
        $changed = [];

        foreach ($candidates as $candidate) {
            $userId  = (int) $candidate['user_id'];
            $current = $existing[$userId] ?? null;

            if ($current === null || $current === $rank[$userId]) {
                continue;
            }

            $changed[] = [
                'user_id'  => $userId,
                'username' => (string) $candidate['username'],
                'current'  => $current,
                'would_be' => $rank[$userId],
            ];
        }

        return [
            'changed'   => count($changed),
            'rows'      => array_slice($changed, 0, self::PREVIEW_LIMIT),
            'missing'   => count(array_filter(
                $candidates,
                static fn (array $candidate): bool => !isset($existing[$candidate['user_id']])
            )),
            'truncated' => count($changed) > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function seedAnswer(bool $dryRun, array $answer, float $started): array
    {
        return $answer + [
            'ok'          => false,
            'dry_run'     => $dryRun,
            'seeded'      => 'no',
            'refused'     => null,
            'message'     => null,
            'gate'        => null,
            'order'       => 'qualified_at',
            'members'     => 0,
            'queued'      => 0,
            'inserted'    => 0,
            'skipped'     => 0,
            'positions'   => [],
            'truncated'   => false,
            'preview'     => [],
            'instant'     => null,
            'baseline'    => ['levels' => 0, 'amount' => 0.0, 'members' => 0, 'rows' => []],
            'diff'        => ['changed' => 0, 'rows' => [], 'missing' => 0, 'truncated' => false],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** Write the baseline instant (and keep the settings cache honest). */
    private function writeBaseline(string $instant): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (key_name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = ?'
        )->execute(['team_harvest_ledger_since', $instant, $instant]);

        setting_cache_clear();
    }

    /** Take the seed's advisory lock; false when another run holds it. */
    private function acquireSeedLock(): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $statement->execute([self::SEED_LOCK, self::SEED_LOCK_TIMEOUT]);

            return (int) $statement->fetchColumn() === 1;
        } catch (Throwable) {
            /* A server without advisory locks must not refuse the job: the
             * "already seeded" guard still protects the queue. */
            return true;
        }
    }

    /** Release the seed's advisory lock (best effort). */
    private function releaseSeedLock(): void
    {
        try {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::SEED_LOCK]);
        } catch (Throwable) {
            /* nothing to do: the lock dies with the connection */
        }
    }

    /** Commit or roll back the transaction this call opened. */
    private function finish(bool $owns, ?int $position): ?int
    {
        if ($owns && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        return $position;
    }
}
