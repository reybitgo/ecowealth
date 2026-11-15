<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyRankTest — Phase 19B (plan §6, §12.2–§12.3, §14.3)
 *
 *  Proves the rank engine on its own: a derived five-rank ladder
 *  over the real `users.sponsor_id` forest. Nothing in this file
 *  writes a period, an award, a wallet row or an audit row.
 *
 *      ladder      the four predicates, bottom-up, tested at the
 *                  threshold and one below it
 *      defaults    10/10/10/10 with the 111-member reference tree
 *      depth       any depth counts; SponsorService::MAX_LEVELS is
 *                  not the rank pass's limit
 *      people      suspended/pending members count; staff never do
 *      cutoff      created_at < period end excludes a later account
 *      corrupt     a cycle and a self-sponsor terminate and are unranked
 *      shape       the documented row fields, deterministic id order
 *
 *  Run this group alone with:  php tests/run.php RoyaltyRank
 * ============================================================== */

/** One member account, returning its id. */
$insertMember = static function (array $row): int {
    $columns = array_keys($row);
    $marks   = implode(', ', array_fill(0, count($columns), '?'));

    db()->prepare('INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . $marks . ')')
        ->execute(array_values($row));

    return (int) db()->lastInsertId();
};

/**
 * Create one member account.
 *
 * The password hash is a fixed valid bcrypt string: the rank pass never
 * reads it and hashing 111 accounts per case would dominate the run.
 */
$member = static function (string $username, ?int $sponsor = null, array $overrides = []) use ($insertMember): int {
    $row = $overrides + [
        'username'      => $username,
        'email'         => $username . '@rank.test',
        'password_hash' => '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
        'full_name'     => ucfirst($username) . ' Rank',
        'role'          => 'member',
        'member_status' => 'active',
        'sponsor_id'    => $sponsor,
        'is_active'     => 1,
        'created_at'    => date('Y-m-d H:i:s'),
    ];

    return $insertMember($row);
};

/** Overwrite the four thresholds and drop the settings cache. */
$setThresholds = static function (array $thresholds): void {
    foreach ($thresholds as $rank => $value) {
        setting_set(RoyaltyRank::THRESHOLD_KEYS[$rank], (string) $value);
    }

    setting_cache_clear();
};

/**
 * The reference fixture B (plan §16.2): `sup` with ten Leaders, each
 * with ten directs — 111 members. Returns the sponsor's id, its ten
 * Leader ids and the id of the first Leader's last direct.
 *
 * @return array{sup:int, leaders:list<int>, last_direct:int}
 */
$referenceTree = static function () use ($member): array {
    $sup     = $member('ref_sup');
    $leaders = [];
    $last    = 0;

    for ($l = 1; $l <= 10; $l++) {
        $leader    = $member('ref_leader_' . $l, $sup);
        $leaders[] = $leader;

        for ($d = 1; $d <= 10; $d++) {
            $direct = $member('ref_direct_' . $l . '_' . $d, $leader);
            $last   = $direct;
        }
    }

    return ['sup' => $sup, 'leaders' => $leaders, 'last_direct' => $last];
};

/**
 * Small-threshold ladder: `leader_directs=1`, `supervisor_leaders=2`,
 * `manager_supervisors=2`. Returns the Manager and its two
 * Supervisors (each with two Leaders, each with one direct).
 *
 * @return array{mgr:int, supervisors:list<int>, leaders:list<int>}
 */
$managerTree = static function () use ($member): array {
    $mgr          = $member('mgr_small');
    $supervisors  = [];
    $leaders      = [];

    foreach (['a', 'b'] as $branch) {
        $sup = $member('mgr_sup_' . $branch, $mgr);

        foreach (['x', 'y'] as $leg) {
            $leader    = $member('mgr_leader_' . $branch . $leg, $sup);
            $leaders[] = $leader;
            $member('mgr_direct_' . $branch . $leg, $leader);
        }

        $supervisors[] = $sup;
    }

    return ['mgr' => $mgr, 'supervisors' => $supervisors, 'leaders' => $leaders];
};

return [
    /* ==========================================================
     *  The ladder and its defaults (P19B-T12, T13, T26 helper)
     * ========================================================== */

    'P19B-T12 a member with no directs is Rank 0' => function () use ($member): void {
        $id = $member('rank_t12_plain');

        $map = RoyaltyRank::map();

        assert_true(isset($map[$id]), 'the member is in the map');
        assert_same(RoyaltyRank::MEMBER, $map[$id]['rank']);
        assert_same('Member', $map[$id]['rank_name']);
        assert_same(0, $map[$id]['directs']);
        assert_same(0, $map[$id]['team_size']);

        RoyaltyRank::assertReadable($map);
    },

    'P19B-T13 exactly leader_directs makes a Leader and one fewer does not' => function () use ($member): void {
        assert_same(10, RoyaltyRank::thresholds()['leader'], 'the reference default is ten');

        $leader = $member('rank_t13_leader');
        $almost = $member('rank_t13_almost');

        for ($i = 1; $i <= 10; $i++) {
            $member('rank_t13_leader_d' . $i, $leader);
        }

        for ($i = 1; $i <= 9; $i++) {
            $member('rank_t13_almost_d' . $i, $almost);
        }

        $map = RoyaltyRank::map();

        assert_same(10, $map[$leader]['directs']);
        assert_same(RoyaltyRank::LEADER, $map[$leader]['rank']);
        assert_same('Leader', $map[$leader]['rank_name']);

        assert_same(9, $map[$almost]['directs']);
        assert_same(RoyaltyRank::MEMBER, $map[$almost]['rank'], 'one direct short is still a Member');

        /* Leader is not a paying rank (plan §12.2). */
        assert_false(in_array(RoyaltyRank::LEADER, RoyaltyRank::PAYING, true));
        assert_same([RoyaltyRank::SUPERVISOR, RoyaltyRank::MANAGER, RoyaltyRank::DIRECTOR], RoyaltyRank::PAYING);
    },

    /* ==========================================================
     *  Supervisor (P19B-T14, T17) — the 111-member reference tree
     * ========================================================== */

    'P19B-T14 ten downline Leaders make a Supervisor; nine do not' => function () use ($referenceTree): void {
        $fixture = $referenceTree();
        $sup     = $fixture['sup'];

        $map = RoyaltyRank::map();

        assert_same(10, $map[$sup]['downline_leaders'], 'ten Leaders below the candidate');
        assert_same(RoyaltyRank::SUPERVISOR, $map[$sup]['rank']);
        assert_same('Supervisor', $map[$sup]['rank_name']);
        assert_same(110, $map[$sup]['team_size'], 'the ten Leaders and their hundred directs');

        /* Exactly one of the ten Leaders loses a direct: it stops being a
         * Leader, so the candidate falls back to Leader (it still has the
         * ten direct members the Leader predicate needs). */
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$fixture['last_direct']]);

        $after = RoyaltyRank::map();

        assert_same(9, $after[$sup]['downline_leaders']);
        assert_same(RoyaltyRank::LEADER, $after[$sup]['rank'], 'nine Leaders is one short of Supervisor');
    },

    'P19B-T17 the counted members exclude the candidate itself' => function () use ($referenceTree): void {
        $fixture = $referenceTree();
        $sup     = $fixture['sup'];

        $map = RoyaltyRank::map();

        assert_same(10, $map[$sup]['directs'], 'ten direct members');
        assert_same(10, $map[$sup]['downline_leaders'], 'the ten Leaders below, not ten plus the candidate');

        foreach ($fixture['leaders'] as $leader) {
            assert_same(0, $map[$leader]['downline_leaders'], 'a Leader has no Leader below it in this tree');
            assert_same(RoyaltyRank::LEADER, $map[$leader]['rank']);
        }

        /* The Supervisor is itself a Leader; its own predicate must not
         * be part of the count that made it a Supervisor. */
        $sl = $map[$sup]['downline_leaders'] + 1;
        assert_same(11, $sl, 'SL including self is ten downline plus the candidate');
    },

    /* ==========================================================
     *  Manager and Director (P19B-T15, T16) — small thresholds
     * ========================================================== */

    'P19B-T15 two downline Supervisors make a Manager; one does not' => function () use ($managerTree, $setThresholds): void {
        $setThresholds(['leader' => 1, 'supervisor' => 2, 'manager' => 2]);

        $fixture = $managerTree();
        $mgr     = $fixture['mgr'];

        $map = RoyaltyRank::map();

        assert_same(2, $map[$mgr]['directs']);
        assert_same(2, $map[$mgr]['downline_supervisors']);
        assert_same(RoyaltyRank::MANAGER, $map[$mgr]['rank']);
        assert_same('Manager', $map[$mgr]['rank_name']);

        foreach ($fixture['supervisors'] as $sup) {
            assert_same(RoyaltyRank::SUPERVISOR, $map[$sup]['rank'], 'each branch head is a Supervisor');
        }

        /* Remove one Supervisor branch: the candidate keeps its Supervisor
         * predicate but drops to Supervisor (one downline Supervisor). */
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$fixture['supervisors'][1]]);

        $after = RoyaltyRank::map();

        assert_same(1, $after[$mgr]['downline_supervisors']);
        assert_same(RoyaltyRank::SUPERVISOR, $after[$mgr]['rank'], 'one downline Supervisor is one short of Manager');
    },

    'P19B-T16 two downline Managers make a Director; one does not' => function () use ($member, $setThresholds): void {
        $setThresholds(['leader' => 1, 'supervisor' => 1, 'manager' => 2, 'director' => 2]);

        $director = $member('dir_small');
        $managers = [];

        foreach (['a', 'b'] as $branch) {
            $manager    = $member('dir_manager_' . $branch, $director);
            $managers[] = $manager;

            foreach (['x', 'y'] as $leg) {
                $sup    = $member('dir_sup_' . $branch . $leg, $manager);
                $leader = $member('dir_leader_' . $branch . $leg, $sup);
                $member('dir_direct_' . $branch . $leg, $leader);
            }
        }

        $map = RoyaltyRank::map();

        assert_same(2, $map[$director]['downline_managers']);
        assert_same(RoyaltyRank::DIRECTOR, $map[$director]['rank']);
        assert_same('Director', $map[$director]['rank_name']);

        foreach ($managers as $manager) {
            assert_same(RoyaltyRank::MANAGER, $map[$manager]['rank'], 'each branch head is a Manager');
        }

        /* Remove one Manager branch: the candidate falls to Manager. */
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$managers[1]]);

        $after = RoyaltyRank::map();

        assert_same(1, $after[$director]['downline_managers']);
        assert_same(RoyaltyRank::MANAGER, $after[$director]['rank'], 'one downline Manager is one short of Director');
    },

    /* ==========================================================
     *  Scope: depth, staff, status, cutoff (T18–T21)
     * ========================================================== */

    'P19B-T18 a Leader more than ten levels down still counts' => function () use ($member, $setThresholds): void {
        $setThresholds(['leader' => 2, 'supervisor' => 2]);

        $candidate = $member('deep_candidate');

        /* Two branches of twelve levels; the branch bottom is a Leader
         * with two directs, i.e. twelve levels below the candidate. */
        $deepLeaders = [];

        foreach (['a', 'b'] as $branch) {
            $node = $member('deep_' . $branch . '_0', $candidate);

            for ($level = 1; $level <= 10; $level++) {
                $node = $member('deep_' . $branch . '_' . $level, $node);
            }

            $leader        = $member('deep_' . $branch . '_leader', $node);
            $deepLeaders[] = $leader;
            $member('deep_' . $branch . '_d1', $leader);
            $member('deep_' . $branch . '_d2', $leader);
        }

        $map = RoyaltyRank::map();

        assert_same(10, SponsorService::MAX_LEVELS, 'the sponsor walk stays capped at ten');

        foreach ($deepLeaders as $leader) {
            assert_same(RoyaltyRank::LEADER, $map[$leader]['rank'], 'the branch bottom is a Leader');
        }

        assert_same(2, $map[$candidate]['downline_leaders'], 'the twelve-level-down Leaders are counted');
        assert_same(RoyaltyRank::SUPERVISOR, $map[$candidate]['rank'], 'the rank pass has no ten-level cap');
        assert_same(28, $map[$candidate]['team_size'], 'two branches of eleven plus a Leader and two directs each');
    },

    'P19B-T19 staff accounts are never ranked and never counted' => function () use ($member): void {
        $admin = test_user('rank_t19_admin', 'admin');
        $adminId = (int) $admin['id'];

        $upline = $member('rank_t19_upline');

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([$upline, $adminId]);

        for ($i = 1; $i <= 10; $i++) {
            $member('rank_t19_direct_' . $i, $adminId);
        }

        $map = RoyaltyRank::map();

        assert_false(isset($map[$adminId]), 'the admin account is not in the member map');
        assert_same(0, $map[$upline]['directs'], 'the admin is not a member direct of its sponsor');
        assert_same(RoyaltyRank::MEMBER, $map[$upline]['rank']);

        /* Every direct of the admin is a root of the member forest. */
        $stmt = db()->prepare(
            "SELECT id FROM users WHERE sponsor_id = ? AND role = 'member' ORDER BY id"
        );
        $stmt->execute([$adminId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $directId) {
            assert_null($map[(int) $directId]['parent_id'], 'a staff sponsor makes the member a root');
        }
    },

    'P19B-T20 a suspended member still counts for the upline rank' => function () use ($member): void {
        $leader = $member('rank_t20_leader');

        for ($i = 1; $i <= 9; $i++) {
            $member('rank_t20_active_' . $i, $leader);
        }

        $suspended = $member('rank_t20_suspended', $leader, [
            'member_status' => 'suspended',
            'is_active'     => 0,
        ]);

        $map = RoyaltyRank::map();

        assert_same(10, $map[$leader]['directs'], 'a suspended member still counts as a person');
        assert_same(RoyaltyRank::LEADER, $map[$leader]['rank']);
        assert_same('suspended', $map[$suspended]['member_status']);
        assert_same(RoyaltyRank::MEMBER, $map[$suspended]['rank'], 'the suspended account is still ranked Member');
    },

    'P19B-T21 the cutoff keeps an account created after the period end out' => function () use ($member): void {
        $sponsor = $member('rank_t21_sponsor', null, ['created_at' => '2026-01-01 00:00:00']);

        for ($i = 1; $i <= 9; $i++) {
            $member('rank_t21_early_' . $i, $sponsor, ['created_at' => '2026-05-0' . ($i % 9 + 1) . ' 10:00:00']);
        }

        $late = $member('rank_t21_late', $sponsor, ['created_at' => '2026-07-01 09:00:00']);

        $before = RoyaltyRank::map('2026-06-01 00:00:00');

        assert_false(isset($before[$late]), 'the member created after the cutoff is not loaded');
        assert_same(9, $before[$sponsor]['directs'], 'its sponsor loses one direct');
        assert_same(RoyaltyRank::MEMBER, $before[$sponsor]['rank'], 'nine directs is one short');

        $after = RoyaltyRank::map('2026-08-01 00:00:00');

        assert_true(isset($after[$late]));
        assert_same(10, $after[$sponsor]['directs']);
        assert_same(RoyaltyRank::LEADER, $after[$sponsor]['rank']);

        /* The live map and a far-future cutoff both see the full tree. */
        assert_true(isset(RoyaltyRank::map('2030-01-01 00:00:00')[$late]));
    },

    /* ==========================================================
     *  Corrupt trees (T22)
     * ========================================================== */

    'P19B-T22 a sponsor cycle terminates and places every account in it outside the map' => function () use ($member): void {
        $normal = $member('rank_t22_normal');

        $self = $member('rank_t22_self', null);
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([$self, $self]);

        $a = $member('rank_t22_a', null);
        $b = $member('rank_t22_b', $a);
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([$b, $a]);

        /* A member hanging off the cycle is unreachable from every root;
         * the documented pass leaves it out too. */
        $downstream = $member('rank_t22_downstream', $a);

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $map = RoyaltyRank::map();
        } finally {
            restore_error_handler();
        }

        assert_same([], $warnings, 'the pass raises no PHP warning');
        assert_true(isset($map[$normal]));
        assert_false(isset($map[$self]), 'a self-sponsored account is unranked');
        assert_false(isset($map[$a]), 'a cycle member is unranked');
        assert_false(isset($map[$b]), 'a cycle member is unranked');
        assert_false(isset($map[$downstream]), 'an account below a cycle is unreachable and excluded');

        RoyaltyRank::assertReadable($map);
    },

    /* ==========================================================
     *  The row shape and volume injection (19C hand-off)
     * ========================================================== */

    'P19B-T27 the map row carries every documented field and withVolumes rolls PS up to GS' => function () use ($member): void {
        $top    = $member('rank_vol_top');
        $middle = $member('rank_vol_middle', $top);
        $leaf   = $member('rank_vol_leaf', $middle);

        $map = RoyaltyRank::map();

        assert_same(
            [
                'id', 'username', 'member_status', 'is_active', 'parent_id', 'rank', 'rank_name',
                'directs', 'downline_leaders', 'downline_supervisors', 'downline_managers',
                'team_size', 'volume_cents', 'group_volume_cents',
            ],
            array_keys($map[$top])
        );

        assert_same($middle, $map[$leaf]['parent_id']);
        assert_null($map[$top]['parent_id']);

        $withVolume = RoyaltyRank::withVolumes($map, [
            $top    => 10000,
            $middle => 2500,
            $leaf   => 750,
        ]);

        assert_same(10000, $withVolume[$top]['volume_cents']);
        assert_same(13250, $withVolume[$top]['group_volume_cents']);
        assert_same(2500, $withVolume[$middle]['volume_cents']);
        assert_same(3250, $withVolume[$middle]['group_volume_cents']);
        assert_same(750, $withVolume[$leaf]['volume_cents']);
        assert_same(750, $withVolume[$leaf]['group_volume_cents']);

        /* No second query and no mutation of the caller's map. */
        assert_same(0, $map[$top]['group_volume_cents']);
        assert_same(13250, RoyaltyRank::withVolumes($map, [$top => 13250])[$top]['group_volume_cents']);

        /* counts() is exclusive and marks Supervisor and above. */
        $counts = RoyaltyRank::counts($map);
        assert_same(count($map), $counts['total']);
        assert_same($counts['member'] + $counts['leader'] + $counts['supervisor']
            + $counts['manager'] + $counts['director'], $counts['total']);
        assert_same($counts['supervisor'] + $counts['manager'] + $counts['director'], $counts['ranked']);
    },

    'P19B-T28 requirements names the next rank and its remaining gap' => function () use ($member, $managerTree, $setThresholds): void {
        $setThresholds(['leader' => 1, 'supervisor' => 2, 'manager' => 2, 'director' => 2]);

        $fixture = $managerTree();
        $map     = RoyaltyRank::map();

        $step = RoyaltyRank::requirements($map[$fixture['mgr']]);
        assert_same(RoyaltyRank::MANAGER, $step['rank']);
        assert_same(RoyaltyRank::DIRECTOR, $step['next_rank']);
        assert_same('Director', $step['next_name']);
        assert_false($step['complete']);
        assert_same(1, count($step['items']));
        assert_same('Downline Managers', $step['items'][0]['label']);
        assert_same(0, $step['items'][0]['current']);
        assert_same(2, $step['items'][0]['required']);
        assert_false($step['items'][0]['met']);
        assert_same(2, $step['items'][0]['remaining']);

        $plain = $member('rank_req_plain');
        $again = RoyaltyRank::map();
        $first = RoyaltyRank::requirements($again[$plain]);
        assert_same(RoyaltyRank::MEMBER, $first['rank']);
        assert_same(RoyaltyRank::LEADER, $first['next_rank']);
        assert_same('Direct members', $first['items'][0]['label']);
        assert_same(1, $first['items'][0]['required']);

        $director = $again[$fixture['mgr']];
        $top      = array_merge($director, ['rank' => RoyaltyRank::DIRECTOR]);
        assert_true(RoyaltyRank::requirements($top)['complete']);
        assert_null(RoyaltyRank::requirements($top)['next_rank']);
    },
];
