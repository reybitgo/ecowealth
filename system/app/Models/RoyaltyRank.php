<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyRank — the derived five-rank ladder (Phase 19B)
 *
 *  Ranks live in the sponsor tree; nothing is stored. This model is
 *  the one place that turns the `users.sponsor_id` forest into a
 *  rank per member, and it is deliberately read-only: it never
 *  writes a period, an award or a wallet row (plan §6, 19B).
 *
 *  The ladder (plan §12.2, Appendix A):
 *
 *      Member      rank 0   automatic
 *      Leader      rank 1   >= leader_directs direct members
 *      Supervisor  rank 2   Leader + >= supervisor_leaders downline Leaders
 *      Manager     rank 3   Supervisor + >= manager_supervisors downline Supervisors
 *      Director    rank 4   Manager + >= director_managers downline Managers
 *
 *  The ranks are self-prerequisite, so the derivations are one
 *  bottom-up pass:
 *
 *      SL(i) = L(i) + Σ child.SL      S(i) = L(i) && (SL(i)-1) >= supervisor_leaders
 *      SS(i) = S(i) + Σ child.SS      M(i) = S(i) && (SS(i)-1) >= manager_supervisors
 *      SM(i) = M(i) + Σ child.SM      D(i) = M(i) && (SM(i)-1) >= director_managers
 *
 *  "Downline" means any depth: there is no 10-level cap here (plan
 *  §12.2 rule 1, §6 rule 4). SponsorService::MAX_LEVELS is not used.
 *
 *  Only `role = 'member'` accounts are loaded. A staff account never
 *  holds a rank and never counts toward one; a member sponsored by a
 *  staff account (or by nobody, or by a missing id) is a root of the
 *  member forest. A self-sponsored account and every account inside a
 *  sponsor cycle are corrupt: they are excluded from the pass, hold no
 *  rank and contribute no counts or volume (plan §12.3).
 * ============================================================== */

final class RoyaltyRank
{
    public const MEMBER     = 0;
    public const LEADER     = 1;
    public const SUPERVISOR = 2;
    public const MANAGER    = 3;
    public const DIRECTOR   = 4;

    /** Rank level => display name (Appendix A). */
    public const NAMES = [
        self::MEMBER     => 'Member',
        self::LEADER     => 'Leader',
        self::SUPERVISOR => 'Supervisor',
        self::MANAGER    => 'Manager',
        self::DIRECTOR   => 'Director',
    ];

    /** Rank level => lowercase slug (count keys, CSS hooks). */
    public const SLUGS = [
        self::MEMBER     => 'member',
        self::LEADER     => 'leader',
        self::SUPERVISOR => 'supervisor',
        self::MANAGER    => 'manager',
        self::DIRECTOR   => 'director',
    ];

    /**
     * The only ranks Royalty pays. Leader unlocks the Supervisor path
     * but earns nothing itself (plan §12.2, §12.5).
     *
     * @var list<int>
     */
    public const PAYING = [self::SUPERVISOR, self::MANAGER, self::DIRECTOR];

    /**
     * Badge presentation per rank: the label is NAMES[level]; the tone
     * is a Bootstrap `bg-label-*` suffix and the icon a Boxicons name.
     *
     * @var array<int, array{tone: string, icon: string}>
     */
    public const LABELS = [
        self::MEMBER     => ['tone' => 'secondary', 'icon' => 'bx-user'],
        self::LEADER     => ['tone' => 'info',      'icon' => 'bx-user-check'],
        self::SUPERVISOR => ['tone' => 'primary',   'icon' => 'bx-star'],
        self::MANAGER    => ['tone' => 'warning',   'icon' => 'bx-medal'],
        self::DIRECTOR   => ['tone' => 'success',   'icon' => 'bx-crown'],
    ];

    /**
     * The four settings that carry the thresholds (plan §13.4). The
     * keys are fixed at 19A; 19B only reads them.
     */
    public const THRESHOLD_KEYS = [
        'leader'     => 'royalty_leader_directs',
        'supervisor' => 'royalty_supervisor_leaders',
        'manager'    => 'royalty_manager_supervisors',
        'director'   => 'royalty_director_managers',
    ];

    /** The reference defaults, used when a key is missing. */
    public const THRESHOLD_DEFAULTS = [
        'leader'     => 10,
        'supervisor' => 10,
        'manager'    => 10,
        'director'   => 10,
    ];

    /** The three paying ranks, keyed by the ladder's short slug. */
    public const PAYING_SLUGS = ['supervisor', 'manager', 'director'];

    /* ----------------------------------------------------------
     *  The configured ladder
     * ---------------------------------------------------------- */

    /**
     * The four thresholds, in members, read from the settings.
     *
     * @return array{leader:int, supervisor:int, manager:int, director:int}
     */
    public static function thresholds(): array
    {
        $thresholds = [];

        foreach (self::THRESHOLD_KEYS as $rank => $key) {
            $thresholds[$rank] = setting_int($key, self::THRESHOLD_DEFAULTS[$rank]);
        }

        return $thresholds;
    }

    /**
     * The complete configured ladder: switches, thresholds, quotas and
     * rates, plus a ready-to-render row per rank.
     *
     * 19C snapshots `thresholds`, `quotas` and `rates` onto
     * `royalty_periods` when it evaluates a month; the settings card
     * renders `ranks` as its read-only ladder table. One call reads the
     * settings cache only — no query and no side effect.
     *
     * @return array{
     *   enabled: bool,
     *   pay_enabled: bool,
     *   thresholds: array{leader:int, supervisor:int, manager:int, director:int},
     *   quotas: array{supervisor:float, manager:float, director:float},
     *   rates: array<string, array{group:float, personal:float}>,
     *   own_pct: array<string, float>,
     *   ranks: array<int, array<string, mixed>>
     * }
     */
    public static function ladder(): array
    {
        $thresholds = self::thresholds();
        $quotas     = [];
        $rates      = [];
        $own        = [];

        foreach (self::PAYING_SLUGS as $slug) {
            $quotas[$slug] = (float) setting('royalty_' . $slug . '_quota', 0);
            $rates[$slug]  = [
                'group'    => (float) setting('royalty_' . $slug . '_group_pct', 0),
                'personal' => (float) setting('royalty_' . $slug . '_personal_pct', 0),
            ];
            $own[$slug] = $rates[$slug]['group'] + $rates[$slug]['personal'];
        }

        return [
            'enabled'     => setting_bool('royalty_enabled', false),
            'pay_enabled' => setting_bool('royalty_pay_enabled', true),
            'thresholds'  => $thresholds,
            'quotas'      => $quotas,
            'rates'       => $rates,
            'own_pct'     => $own,
            'ranks'       => self::ladderRows($thresholds, $quotas, $rates, $own),
        ];
    }

    /**
     * The five display rows of the ladder table (Member … Director).
     *
     * @param array{leader:int, supervisor:int, manager:int, director:int} $thresholds
     * @param array<string, float>                                        $quotas
     * @param array<string, array{group:float, personal:float}>           $rates
     * @param array<string, float>                                        $own
     * @return array<int, array<string, mixed>>
     */
    private static function ladderRows(array $thresholds, array $quotas, array $rates, array $own): array
    {
        $rows = [];

        $rows[self::MEMBER] = [
            'level'         => self::MEMBER,
            'name'          => self::NAMES[self::MEMBER],
            'slug'          => self::SLUGS[self::MEMBER],
            'paying'        => false,
            'requirement'   => 'Automatic on registration',
            'threshold'     => null,
            'threshold_key' => null,
            'quota'         => null,
            'quota_cents'   => null,
            'group_pct'     => null,
            'personal_pct'  => null,
            'own_pct'       => null,
        ];

        $requirements = [
            self::LEADER     => static fn (int $n): string => sprintf('%s direct member%s', self::number($n), $n === 1 ? '' : 's'),
            self::SUPERVISOR => static fn (int $n): string => sprintf('Leader + %s downline Leader%s', self::number($n), $n === 1 ? '' : 's'),
            self::MANAGER    => static fn (int $n): string => sprintf('Supervisor + %s downline Supervisor%s', self::number($n), $n === 1 ? '' : 's'),
            self::DIRECTOR   => static fn (int $n): string => sprintf('Manager + %s downline Manager%s', self::number($n), $n === 1 ? '' : 's'),
        ];

        $thresholdFor = [
            self::LEADER     => $thresholds['leader'],
            self::SUPERVISOR => $thresholds['supervisor'],
            self::MANAGER    => $thresholds['manager'],
            self::DIRECTOR   => $thresholds['director'],
        ];

        $thresholdKeyFor = [
            self::LEADER     => self::THRESHOLD_KEYS['leader'],
            self::SUPERVISOR => self::THRESHOLD_KEYS['supervisor'],
            self::MANAGER    => self::THRESHOLD_KEYS['manager'],
            self::DIRECTOR   => self::THRESHOLD_KEYS['director'],
        ];

        $slugFor = [
            self::LEADER     => 'leader',
            self::SUPERVISOR => 'supervisor',
            self::MANAGER    => 'manager',
            self::DIRECTOR   => 'director',
        ];

        foreach ([self::LEADER, self::SUPERVISOR, self::MANAGER, self::DIRECTOR] as $level) {
            $threshold = $thresholdFor[$level];
            $slug      = $slugFor[$level];
            $paying    = in_array($level, self::PAYING, true);

            $rows[$level] = [
                'level'         => $level,
                'name'          => self::NAMES[$level],
                'slug'          => self::SLUGS[$level],
                'paying'        => $paying,
                'requirement'   => $requirements[$level]($threshold),
                'threshold'     => $threshold,
                'threshold_key' => $thresholdKeyFor[$level],
                'quota'         => $paying ? $quotas[$slug] : null,
                'quota_cents'   => $paying ? (int) round($quotas[$slug] * 100) : null,
                'group_pct'     => $paying ? $rates[$slug]['group'] : null,
                'personal_pct'  => $paying ? $rates[$slug]['personal'] : null,
                'own_pct'       => $paying ? $own[$slug] : null,
            ];
        }

        return $rows;
    }

    /* ----------------------------------------------------------
     *  The rank pass
     * ---------------------------------------------------------- */

    /**
     * Derive the rank of every member account.
     *
     * One query over `users WHERE role = 'member'` (optionally with a
     * `created_at < $asOf` cutoff) and one in-memory bottom-up pass.
     * The result is keyed by user id, ordered by id.
     *
     * `$asOf` is a `Y-m-d H:i:s` period end for a close, or null for
     * the live tree (plan §12.3, §14.3).
     *
     * `$pdo` is the connection to read: the 19C reset integrity pack
     * runs the same pass against a scratch database, where the global
     * `db()` would point at the application instead (the same reason
     * `UnilevelService::reconcile()` reads its own baseline).
     *
     * @return array<int, array{
     *   id:int, username:string, member_status:string, is_active:int,
     *   parent_id:int|null, rank:int, rank_name:string, directs:int,
     *   downline_leaders:int, downline_supervisors:int, downline_managers:int,
     *   team_size:int, volume_cents:int, group_volume_cents:int
     * }>
     */
    public static function map(?string $asOf = null, ?PDO $pdo = null): array
    {
        $sql    = "SELECT id, sponsor_id, username, member_status, is_active, created_at
                     FROM users
                    WHERE role = 'member'";
        $params = [];

        if ($asOf !== null) {
            $sql     .= ' AND created_at < ?';
            $params[] = $asOf;
        }

        $sql .= ' ORDER BY id';

        $pdo  = $pdo ?? db();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        if ($rows === []) {
            return [];
        }

        $thresholds = self::thresholds();
        $children   = [];
        $roots      = [];

        /* 1. Children map and roots. A self-sponsor is corrupt and is
         *    excluded; a member whose sponsor is absent from the loaded
         *    set (company/staff/missing/deleted, or created after the
         *    cutoff) is a root. */
        foreach ($rows as $id => $row) {
            $sponsor = (int) ($row['sponsor_id'] ?? 0);

            if ($sponsor > 0 && $sponsor === $id) {
                continue;
            }

            if ($sponsor > 0 && isset($rows[$sponsor])) {
                $children[$sponsor][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        /* 2. Iterative pre-order over the forest. The visited set makes
         *    a corrupt cycle (unreachable from every root) terminate
         *    without double counting; those accounts are simply left
         *    out of the result. */
        $order = [];
        $seen  = [];
        $stack = array_reverse($roots);

        while ($stack !== []) {
            $id = (int) array_pop($stack);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $order[]   = $id;

            $kids = $children[$id] ?? [];

            for ($i = count($kids) - 1; $i >= 0; $i--) {
                $stack[] = $kids[$i];
            }
        }

        /* 3. Reversing the pre-order gives a post-order: every child is
         *    computed before its parent. */
        $data = [];

        for ($i = count($order) - 1; $i >= 0; $i--) {
            $id   = $order[$i];
            $kids = $children[$id] ?? [];

            $leader = count($kids) >= $thresholds['leader'];
            $sl     = $leader ? 1 : 0;
            $ss     = 0;
            $sm     = 0;
            $team   = 0;
            $belowLeaders = 0;
            $belowSupervisors = 0;
            $belowManagers = 0;

            foreach ($kids as $kid) {
                $sl   += $data[$kid]['sl'];
                $ss   += $data[$kid]['ss'];
                $sm   += $data[$kid]['sm'];
                $team += $data[$kid]['team'] + 1;

                /* Exact descendant counts: every child's rank-or-above
                 * subtree count is already "below" this member. (The
                 * plan writes SL−1; the two agree whenever this member
                 * holds the predicate, and this form never reports a
                 * negative count for a plain Member.) */
                $belowLeaders     += $data[$kid]['sl'];
                $belowSupervisors += $data[$kid]['ss'];
                $belowManagers    += $data[$kid]['sm'];
            }

            $supervisor = $leader && ($sl - 1) >= $thresholds['supervisor'];
            $ssTotal    = ($supervisor ? 1 : 0) + $ss;
            $manager    = $supervisor && ($ssTotal - 1) >= $thresholds['manager'];
            $smTotal    = ($manager ? 1 : 0) + $sm;
            $director   = $manager && ($smTotal - 1) >= $thresholds['director'];
            $rank       = $director
                ? self::DIRECTOR
                : ($manager ? self::MANAGER : ($supervisor ? self::SUPERVISOR : ($leader ? self::LEADER : self::MEMBER)));

            $data[$id] = [
                'sl'   => $sl,
                'ss'   => $ssTotal,
                'sm'   => $smTotal,
                'team' => $team,
                'rank' => $rank,
                'below_leaders'     => $belowLeaders,
                'below_supervisors' => $belowSupervisors,
                'below_managers'    => $belowManagers,
            ];
        }

        $map = [];

        foreach ($rows as $id => $row) {
            if (!isset($data[$id])) {
                continue;   // self-sponsor or inside an unreachable cycle
            }

            $sponsor  = (int) ($row['sponsor_id'] ?? 0);
            $parentId = ($sponsor > 0 && isset($rows[$sponsor]) && $sponsor !== $id) ? $sponsor : null;

            $map[$id] = [
                'id'                   => $id,
                'username'             => (string) $row['username'],
                'member_status'        => (string) $row['member_status'],
                'is_active'            => (int) $row['is_active'],
                'parent_id'            => $parentId,
                'rank'                 => $data[$id]['rank'],
                'rank_name'            => self::NAMES[$data[$id]['rank']],
                'directs'              => count($children[$id] ?? []),
                'downline_leaders'     => $data[$id]['below_leaders'],
                'downline_supervisors' => $data[$id]['below_supervisors'],
                'downline_managers'    => $data[$id]['below_managers'],
                'team_size'            => $data[$id]['team'],
                'volume_cents'         => 0,
                'group_volume_cents'   => 0,
            ];
        }

        ksort($map, SORT_NUMERIC);

        return $map;
    }

    /**
     * One member's rank row, or null when the account is not a member,
     * is corrupt, or was created after the cutoff.
     *
     * @return array<string, mixed>|null
     */
    public static function forMember(int $userId, ?string $asOf = null, ?PDO $pdo = null): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return self::map($asOf, $pdo)[$userId] ?? null;
    }

    /**
     * Exclusive counts per rank plus `ranked` (Supervisor and above)
     * and `total` (every ranked member account).
     *
     * @param array<int, array<string, mixed>> $map
     * @return array{member:int, leader:int, supervisor:int, manager:int, director:int, ranked:int, total:int}
     */
    public static function counts(array $map): array
    {
        $counts = [
            'member'     => 0,
            'leader'     => 0,
            'supervisor' => 0,
            'manager'    => 0,
            'director'   => 0,
            'ranked'     => 0,
            'total'      => 0,
        ];

        foreach ($map as $row) {
            $level = (int) ($row['rank'] ?? self::MEMBER);

            if (!isset(self::SLUGS[$level])) {
                continue;
            }

            $counts[self::SLUGS[$level]]++;
            $counts['total']++;

            if ($level >= self::SUPERVISOR) {
                $counts['ranked']++;
            }
        }

        return $counts;
    }

    /**
     * The next-rank requirement for one member row (19E's progress
     * card; 19C's evaluation uses the row directly).
     *
     * @param array<string, mixed> $row one row of map()
     * @return array{
     *   rank:int, rank_name:string, next_rank:int|null, next_name:string|null,
     *   complete:bool, items:list<array{label:string, current:int, required:int, met:bool, remaining:int}>
     * }
     */
    public static function requirements(array $row): array
    {
        $level      = self::clamp((int) ($row['rank'] ?? self::MEMBER));
        $thresholds = self::thresholds();

        if ($level >= self::DIRECTOR) {
            return [
                'rank'      => $level,
                'rank_name' => self::NAMES[$level],
                'next_rank' => null,
                'next_name' => null,
                'complete'  => true,
                'items'     => [],
            ];
        }

        $next = $level + 1;

        [$label, $current, $required] = match ($next) {
            self::LEADER     => ['Direct members',      (int) ($row['directs'] ?? 0),              $thresholds['leader']],
            self::SUPERVISOR => ['Downline Leaders',    (int) ($row['downline_leaders'] ?? 0),     $thresholds['supervisor']],
            self::MANAGER    => ['Downline Supervisors', (int) ($row['downline_supervisors'] ?? 0), $thresholds['manager']],
            default          => ['Downline Managers',   (int) ($row['downline_managers'] ?? 0),    $thresholds['director']],
        };

        $items = [];

        $items[] = [
            'label'     => $label,
            'current'   => $current,
            'required'  => $required,
            'met'       => $current >= $required,
            'remaining' => max(0, $required - $current),
        ];

        /* Every rank is self-prerequisite: a Supervisor candidate must
         * also stay a Leader, which its existing rank already proves. */
        return [
            'rank'      => $level,
            'rank_name' => self::NAMES[$level],
            'next_rank' => $next,
            'next_name' => self::NAMES[$next],
            'complete'  => false,
            'items'     => $items,
        ];
    }

    /**
     * Inject each member's own volume (in integer centavos) and roll it
     * up into every ancestor's group volume.
     *
     * Reuses the rows' `parent_id` links — there is no second query and
     * no stored state (plan §14.3). Volume of a corrupt account that
     * map() excluded is never counted.
     *
     * @param array<int, array<string, mixed>> $map          rows from map()
     * @param array<int, int>                  $volumesCents user id => own volume in cents
     * @return array<int, array<string, mixed>> the same map with `volume_cents` and `group_volume_cents`
     */
    public static function withVolumes(array $map, array $volumesCents): array
    {
        if ($map === []) {
            return [];
        }

        $children = [];
        $roots    = [];

        foreach ($map as $id => $row) {
            $id     = (int) $id;
            $parent = $row['parent_id'] ?? null;

            if ($parent !== null && isset($map[(int) $parent])) {
                $children[(int) $parent][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $order = [];
        $seen  = [];
        $stack = array_reverse($roots);

        while ($stack !== []) {
            $id = (int) array_pop($stack);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $order[]   = $id;

            $kids = $children[$id] ?? [];

            for ($i = count($kids) - 1; $i >= 0; $i--) {
                $stack[] = $kids[$i];
            }
        }

        $group = [];

        foreach ($map as $id => $row) {
            $group[(int) $id] = max(0, (int) ($volumesCents[(int) $id] ?? 0));
        }

        for ($i = count($order) - 1; $i >= 0; $i--) {
            $id = $order[$i];

            foreach ($children[$id] ?? [] as $kid) {
                $group[$id] += $group[$kid];
            }
        }

        foreach ($map as $id => $row) {
            $map[(int) $id]['volume_cents']       = max(0, (int) ($volumesCents[(int) $id] ?? 0));
            $map[(int) $id]['group_volume_cents'] = $group[(int) $id];
        }

        return $map;
    }

    /**
     * Defensive consistency checks for a map (plan §14.3): every row is
     * keyed by its own id, every parent exists in the map, there is no
     * cycle and no count is negative. Throws a LogicException when the
     * pass produced something it cannot have produced.
     *
     * @param array<int, array<string, mixed>> $map
     */
    public static function assertReadable(array $map): void
    {
        $total = count($map);

        foreach ($map as $id => $row) {
            $id = (int) $id;

            if ((int) ($row['id'] ?? -1) !== $id) {
                throw new LogicException('RoyaltyRank map: row ' . $id . ' carries a different id.');
            }

            $level = (int) ($row['rank'] ?? -1);

            if (!isset(self::NAMES[$level])) {
                throw new LogicException('RoyaltyRank map: row ' . $id . ' has an unknown rank ' . $level . '.');
            }

            $parent = $row['parent_id'] ?? null;

            if ($parent !== null) {
                if ((int) $parent === $id) {
                    throw new LogicException('RoyaltyRank map: row ' . $id . ' is its own parent.');
                }

                if (!isset($map[(int) $parent])) {
                    throw new LogicException('RoyaltyRank map: row ' . $id . ' has an orphan parent ' . (int) $parent . '.');
                }
            }

            foreach (['directs', 'downline_leaders', 'downline_supervisors', 'downline_managers', 'team_size'] as $field) {
                if ((int) ($row[$field] ?? 0) < 0) {
                    throw new LogicException('RoyaltyRank map: row ' . $id . ' has a negative ' . $field . '.');
                }
            }
        }

        /* Walk each parent chain; a chain longer than the map is a cycle. */
        foreach ($map as $id => $row) {
            $cursor = $row['parent_id'] ?? null;
            $hops   = 0;

            while ($cursor !== null) {
                $hops++;

                if ($hops > $total) {
                    throw new LogicException('RoyaltyRank map: row ' . (int) $id . ' sits in a cycle.');
                }

                $cursor = $map[(int) $cursor]['parent_id'] ?? null;
            }
        }
    }

    /** Clamp a level to the known ladder so a bad value cannot index it. */
    private static function clamp(int $level): int
    {
        return max(self::MEMBER, min(self::DIRECTOR, $level));
    }

    /** A threshold with thousands separators ("10", "1,000"). */
    private static function number(int $value): string
    {
        return number_format($value, 0, '.', ',');
    }
}
