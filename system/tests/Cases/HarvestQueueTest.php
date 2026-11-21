<?php
declare(strict_types=1);

/* ==============================================================
 *  HarvestQueueTest — Phase 20C (plan §7)
 *
 *  Proves the structural phase on its own. Nothing here pays: the whole
 *  fixture leaves `team_harvest_awards` empty and every wallet balance
 *  untouched (P20C-T22), because 20C stores qualification and 20D is the
 *  money engine.
 *
 *      hook        the gate, the position, the staff/suspended rules,
 *                  the inert-before-seed rule and the registration
 *                  transaction (T1–T9, T23)
 *      seed        the reconstruction order, the baseline, the dry run,
 *                  idempotency and the --force guard (T10–T15)
 *      counters    the any-depth walk, sibling isolation, the "qualified
 *                  after me" exclusion and the recount (T16–T19, T24)
 *      rebuild     drift, --apply, the baseline that never moves
 *                  (T20–T21)
 *      matrix      the 3-ary slot arithmetic and the placement
 *                  (T25, T29)
 *      summary     stats(), map(), verify() (T26, T28, T30)
 *      cli         the real job as a subprocess (T27)
 *
 *  Fixture A (height 3) is the plan's 40-qualified / 121-account tree;
 *  fixture B (height 4) is the 121 / 364 tree (§16.2). Both are grown
 *  through the **real hook** — an insert plus `qualifySponsor()` after
 *  each account — so the fixture exercises production code, never a
 *  shortcut.
 *
 *  Two harness facts shape every case:
 *
 *    • `$clean()` removes the demo seed's five members before a fixture,
 *      because `juan → maria → peter/sarah/david` already puts maria over
 *      the gate and would appear in every reconstruction. The delete runs
 *      inside the case's transaction, so the next case sees the demo tree
 *      again.
 *    • A rolled-back INSERT still advances `AUTO_INCREMENT` in MySQL, so a
 *      position is asserted **relative** to the case's first position
 *      (`$firstPosition()`), never as a literal `1`. The contract being
 *      proved is the order and the contiguity, not the arithmetic of a
 *      table counter that a test run keeps nudging.
 *
 *  Run this group alone with:  php tests/run.php HarvestQueue
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/** Arm the live hook: a registration counts only once the queue is seeded (Rule 2). */
$arm = static function (string $instant = '2026-11-01 00:00:00', ?int $gate = null): void {
    setting_set('team_harvest_ledger_since', $instant);

    if ($gate !== null) {
        setting_set('team_harvest_qualify_directs', (string) $gate);
    }

    setting_cache_clear();
    setting_all();
};

/** Leave the queue unseeded, optionally changing the gate. */
$disarm = static function (?int $gate = null): void {
    setting_set('team_harvest_ledger_since', '');

    if ($gate !== null) {
        setting_set('team_harvest_qualify_directs', (string) $gate);
    }

    setting_cache_clear();
    setting_all();
};

/**
 * Start the case from a member tree that holds nothing: the demo seed's
 * five members are removed (cascading their queue rows) inside the case's
 * transaction, so no demo member can hold the gate.
 */
$clean = static function (): void {
    db()->exec("DELETE FROM users WHERE role = 'member'");
    setting_set('team_harvest_qualify_directs', '3');
    setting_set('team_harvest_ledger_since', '');
    setting_cache_clear();
    setting_all();
};

/**
 * One account, at a fixed instant, with a sponsor.
 *
 * Deliberately not the harness's `test_user()`: that helper takes no
 * sponsor and no timestamp, and both are the subject of this phase.
 */
$account = static function (
    string $username,
    ?int $sponsorId = null,
    string $role = 'member',
    string $createdAt = '',
    string $status = 'active'
): int {
    db()->prepare(
        'INSERT INTO users
           (username, email, password_hash, full_name, role, member_status,
            sponsor_id, referral_code, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        $username,
        $username . '@p20c.test',
        password_hash('P20cMember@123', PASSWORD_BCRYPT),
        ucfirst(str_replace('_', ' ', $username)),
        $role,
        $status,
        $sponsorId,
        strtoupper(bin2hex(random_bytes(4))),
        $createdAt === '' ? date('Y-m-d H:i:s') : $createdAt,
    ]);

    return (int) db()->lastInsertId();
};

/** A queue row, or null. */
$rowOf = static fn (int $userId): ?array => (new HarvestQueue())->row($userId);

/** One account's stored created_at. */
$createdAt = static function (int $userId): string {
    $statement = db()->prepare('SELECT created_at FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);

    return (string) $statement->fetchColumn();
};

/** The lowest position the queue holds — the first one this case assigned. */
$firstPosition = static fn (): int => (int) db()->query('SELECT COALESCE(MIN(id), 0) FROM harvest_queue')->fetchColumn();

/** How many queue rows exist right now. */
$queueCount = static fn (): int => (int) db()->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn();

/**
 * Grow the plan's perfect 3-ary tree through the real hook.
 *
 * `$height` 3 is fixture A (40 qualified + 81 leaf directs = 121
 * accounts); 4 is fixture B (121 + 243 = 364). Every account is created
 * through `$account()` and followed by `qualifySponsor()` on its parent,
 * so each parent is queued by the third of its three children, exactly as
 * a real registration would.
 *
 * @return array{root:int, levels:array<int, list<int>>, accounts:int, leaves:int}
 */
$grow = static function (int $height, string $prefix) use ($account): array {
    $queue   = new HarvestQueue();
    $root    = $account($prefix . '_root');
    $created = 1;
    $levels  = [];
    $parents = [$root];

    for ($depth = 1; $depth <= $height; $depth++) {
        $level = [];

        foreach ($parents as $parent) {
            for ($i = 1; $i <= 3; $i++) {
                $child = $account($prefix . '_n' . $depth . '_' . $parent . '_' . $i, $parent);
                $created++;
                $queue->qualifySponsor($parent);
                $level[] = $child;
            }
        }

        $levels[$depth] = $level;
        $parents        = $level;
    }

    $leaves = 0;

    foreach ($parents as $parent) {
        for ($i = 1; $i <= 3; $i++) {
            $account($prefix . '_x' . $parent . '_' . $i, $parent);
            $created++;
            $queue->qualifySponsor($parent);   // already queued: a no-op
            $leaves++;
        }
    }

    return ['root' => $root, 'levels' => $levels, 'accounts' => $created, 'leaves' => $leaves];
};

return [

    /* ==========================================================
     *  The hook (plan §12.2, §14.3)
     * ========================================================== */

    'P20C-T1 three member directs put the sponsor in the queue' => function () use ($clean, $arm, $account, $rowOf, $createdAt, $firstPosition, $queueCount): void {
        $clean();
        $arm();
        $queue   = new HarvestQueue();
        $sponsor = $account('t1_sponsor');

        assert_null($queue->qualifySponsor($sponsor), 'a sponsor with no directs does not qualify');
        assert_null($queue->positionOf($sponsor), 'and it holds no position');

        $account('t1_d1', $sponsor);
        assert_null($queue->qualifySponsor($sponsor), 'one direct is below the gate');
        $account('t1_d2', $sponsor);
        assert_null($queue->qualifySponsor($sponsor), 'two directs are below the gate');

        $third    = $account('t1_d3', $sponsor);
        $position = $queue->qualifySponsor($sponsor);

        assert_not_null($position, 'the third member direct opens the gate');
        assert_same($position, $queue->positionOf($sponsor), 'the row holds the position the hook returned');
        assert_true($queue->isQueued($sponsor));
        assert_same(1, $queueCount(), 'one row and no more');

        /* It is the first position this queue ever assigned. */
        assert_same($position, $firstPosition());
        assert_same($position, $queue->stats()['positions']);
        assert_same($position + 1, $queue->stats()['next']);

        $row = $rowOf($sponsor);
        assert_not_null($row);
        assert_same($position, $row['position']);
        assert_same(3, $row['directs_at_qualification'], 'the direct count that opened the gate');
        assert_same(0, $row['teammates'], 'nobody is below the very first member');
        assert_same(0, $row['baseline_n'], 'a live row is not a baseline row');
        assert_same('registration', $row['source']);
        assert_same($createdAt($third), $row['qualified_at'], 'Rule 12: the third direct\'s created_at');

        /* The gate is a count, not a schedule: nothing is deferred. */
        assert_same('2026-11-01 00:00:00', (string) setting('team_harvest_ledger_since', ''), 'the baseline is untouched');
    },

    'P20C-T2 a fourth direct writes no second row and keeps the position' => function () use ($clean, $arm, $account, $rowOf): void {
        $clean();
        $arm();
        $queue   = new HarvestQueue();
        $sponsor = $account('t2_sponsor');

        foreach (['t2_d1', 't2_d2', 't2_d3'] as $name) {
            $account($name, $sponsor);
            $queue->qualifySponsor($sponsor);
        }

        $before = $rowOf($sponsor);
        assert_not_null($before);

        $account('t2_d4', $sponsor);
        assert_null($queue->qualifySponsor($sponsor), 'an already queued member is not re-queued');

        $after = $rowOf($sponsor);
        assert_not_null($after);
        assert_same($before['position'], $after['position'], 'the position is frozen');
        assert_same(3, $after['directs_at_qualification'], 'the audit fact is never re-read');
        assert_same('registration', $after['source']);
        assert_same(1, (int) db()->query('SELECT COUNT(*) FROM harvest_queue WHERE user_id = ' . $sponsor)->fetchColumn());
    },

    'P20C-T3 two members qualify in the order their gates opened' => function () use ($clean, $arm, $account, $rowOf, $firstPosition): void {
        $clean();
        $arm();
        $queue = new HarvestQueue();

        $first  = $account('t3_first');
        $second = $account('t3_second');

        foreach (['t3_a1', 't3_a2', 't3_a3'] as $index => $name) {
            $account($name, $first, 'member', '2026-10-0' . ($index + 1) . ' 09:00:00');
            $queue->qualifySponsor($first);
        }

        foreach (['t3_b1', 't3_b2', 't3_b3'] as $index => $name) {
            $account($name, $second, 'member', '2026-10-0' . ($index + 5) . ' 09:00:00');
            $queue->qualifySponsor($second);
        }

        $rowA = $rowOf($first);
        $rowB = $rowOf($second);

        assert_not_null($rowA);
        assert_not_null($rowB);
        assert_same($firstPosition(), $rowA['position']);
        assert_same($firstPosition() + 1, $rowB['position']);
        assert_same('2026-10-03 09:00:00', $rowA['qualified_at'], 'the third direct\'s created_at');
        assert_same('2026-10-07 09:00:00', $rowB['qualified_at'], 'each member keeps their own instant');

        assert_same([$first, $second], array_keys($queue->map()), 'the queue is the insert order');
    },

    'P20C-T4 position is qualification order, not tree order' => function () use ($clean, $arm, $account, $firstPosition): void {
        $clean();
        $arm();
        $queue = new HarvestQueue();

        $ancestor   = $account('t4_ancestor');
        $descendant = $account('t4_descendant', $ancestor);

        /* The descendant qualifies first: three directs of its own. */
        foreach (['t4_x1', 't4_x2', 't4_x3'] as $name) {
            $account($name, $descendant);
            $queue->qualifySponsor($descendant);
        }

        /* Then the ancestor crosses its own gate (the descendant is one). */
        foreach (['t4_y1', 't4_y2'] as $name) {
            $account($name, $ancestor);
            $queue->qualifySponsor($ancestor);
        }

        assert_same($firstPosition(), $queue->positionOf($descendant), 'the downline member is position #1 of this case');
        assert_same($firstPosition() + 1, $queue->positionOf($ancestor), 'the sponsor comes second');

        /* D-17: the descendant qualified before the ancestor, so it is
         * permanently excluded from the ancestor's matrix. */
        assert_same(0, $queue->teammatesOf($ancestor), 'an earlier qualification never counts');
        assert_same(0, $queue->recount($ancestor), 'the definition agrees with the counter');
    },

    'P20C-T5 a staff direct never counts and is never queued' => function () use ($clean, $arm, $account, $queueCount): void {
        $clean();
        $arm();
        $queue   = new HarvestQueue();
        $sponsor = $account('t5_sponsor');

        $account('t5_m1', $sponsor);
        $account('t5_m2', $sponsor);
        $staff = $account('t5_staff', $sponsor, 'admin');

        assert_same(2, $queue->directs($sponsor), 'only role=member accounts are directs');
        assert_null($queue->qualifySponsor($sponsor), 'two members and one staff account is below the gate');
        assert_same(0, $queueCount());

        /* The staff account itself is never queued, however many member
         * directs it holds (Rule 4). */
        $account('t5_s1', $staff);
        $account('t5_s2', $staff);
        $account('t5_s3', $staff);

        assert_same(3, $queue->directs($staff), 'a staff account can sponsor members');
        assert_null($queue->qualifySponsor($staff));
        assert_same(0, $queueCount());
    },

    'P20C-T6 the company account never holds a position' => function () use ($clean, $arm, $account, $queueCount, $firstPosition): void {
        $clean();
        $arm();
        $queue   = new HarvestQueue();
        $company = $account('t6_company', null, 'admin');

        foreach (['t6_a', 't6_b', 't6_c'] as $name) {
            $account($name, $company);
            $queue->qualifySponsor($company);
        }

        assert_same(3, $queue->directs($company), 'the company holds three member directs');
        assert_null($queue->positionOf($company), 'a role=admin account is never in the queue');
        assert_null($queue->qualifySponsor($company), 'and the hook refuses to queue it');
        assert_same(0, $queueCount(), 'the company is the only sponsor, so nobody qualified');

        /* A member under the company can qualify on its own directs — the
         * company contributes nothing to the queue. */
        $member = (int) db()->query("SELECT id FROM users WHERE username = 't6_a'")->fetchColumn();

        foreach (['t6_m1', 't6_m2', 't6_m3'] as $name) {
            $account($name, $member);
            $queue->qualifySponsor($member);
        }

        assert_same($firstPosition(), $queue->positionOf($member));
        assert_null($queue->positionOf($company));
        assert_same(1, $queueCount());
    },

    'P20C-T7 a suspended and a pending direct both count' => function () use ($clean, $arm, $account, $rowOf, $firstPosition): void {
        $clean();
        $arm();
        $queue   = new HarvestQueue();
        $sponsor = $account('t7_sponsor');

        $account('t7_active', $sponsor, 'member', '', 'active');
        $account('t7_suspended', $sponsor, 'member', '', 'suspended');
        $account('t7_pending', $sponsor, 'member', '', 'pending');

        assert_same(3, $queue->directs($sponsor), 'status is not read anywhere in this phase (D-02)');
        assert_not_null($queue->qualifySponsor($sponsor), 'the third direct counts whoever they are');

        $row = $rowOf($sponsor);
        assert_not_null($row);
        assert_same(3, $row['directs_at_qualification']);

        /* A suspended member qualifies exactly like an active one. */
        $suspended = (int) db()->query("SELECT id FROM users WHERE username = 't7_suspended'")->fetchColumn();

        foreach (['t7_s1', 't7_s2', 't7_s3'] as $name) {
            $account($name, $suspended);
            $queue->qualifySponsor($suspended);
        }

        assert_same($firstPosition() + 1, $queue->positionOf($suspended), 'a suspended member can qualify');
        assert_same($firstPosition(), $queue->positionOf($sponsor));
    },

    'P20C-T8 the gate is configurable and a change is not retroactive' => function () use ($clean, $arm, $account, $firstPosition): void {
        $clean();
        $arm('2026-11-01 00:00:00', 2);
        $queue = new HarvestQueue();

        assert_same(2, TeamHarvestLevel::qualifyDirects());

        $sponsor = $account('t8_sponsor');
        $account('t8_d1', $sponsor);
        assert_null($queue->qualifySponsor($sponsor), 'one direct is below a gate of two');

        $account('t8_d2', $sponsor);
        assert_not_null($queue->qualifySponsor($sponsor), 'the second direct qualifies at gate 2');

        /* Raising the gate back to 3 never removes a queued member. */
        $arm('2026-11-01 00:00:00', 3);
        assert_same($firstPosition(), $queue->positionOf($sponsor), 'a position is a fact, not a policy');

        $other = $account('t8_other');
        $account('t8_o1', $other);
        $account('t8_o2', $other);
        assert_null($queue->qualifySponsor($other), 'the new gate applies to the next registration');
        $account('t8_o3', $other);
        assert_not_null($queue->qualifySponsor($other), 'and the third direct qualifies it');
        assert_same($firstPosition() + 1, $queue->positionOf($other));
    },

    'P20C-T9 the hook is inert until the queue has been seeded' => function () use ($clean, $disarm, $arm, $account, $queueCount, $firstPosition): void {
        $clean();
        $disarm();
        $queue   = new HarvestQueue();
        $sponsor = $account('t9_sponsor');

        foreach (['t9_d1', 't9_d2', 't9_d3'] as $name) {
            $account($name, $sponsor);
            assert_null($queue->qualifySponsor($sponsor), 'the hook returns null while unseeded');
        }

        assert_same(0, $queueCount(), 'nothing is queued before the seed has run (D-04)');
        assert_false($queue->seeded());
        assert_null($queue->baseline());

        /* After the seed the very next registration is recorded — and the
         * member who was already over the gate is caught up by it. */
        $arm();
        assert_true($queue->seeded());
        $account('t9_d4', $sponsor);

        assert_not_null($queue->qualifySponsor($sponsor), 'the next third direct writes a row');
        assert_same($firstPosition(), $queue->positionOf($sponsor));
        assert_same(1, $queueCount());
        assert_same(4, $queue->directs($sponsor));
    },

    /* ==========================================================
     *  The seed (plan §14.10)
     * ========================================================== */

    'P20C-T10 the seed dry run writes nothing and reports every position' => function () use ($clean, $disarm, $grow, $queueCount): void {
        $clean();
        $disarm();
        $tree  = $grow(3, 't10');
        $queue = new HarvestQueue();

        assert_same(121, $tree['accounts'], 'fixture A: 40 qualified + 81 leaf directs');
        assert_same(40, 1 + count($tree['levels'][1]) + count($tree['levels'][2]) + count($tree['levels'][3]));
        assert_same(0, $queueCount(), 'the tree was grown with the hook inert');

        $dry = $queue->seed(true);

        assert_true((bool) $dry['ok']);
        assert_true((bool) $dry['dry_run']);
        assert_same('preview', $dry['seeded']);
        assert_same(40, (int) $dry['members'], 'every member who holds the gate');
        assert_same(0, (int) $dry['inserted']);
        assert_count(40, $dry['positions']);
        assert_count(20, $dry['preview'], 'the first 20 positions');
        assert_same('seed', $dry['positions'][0]['source']);

        /* A preview numbers from MAX(id)+1 — 1 on the empty queue a go-live
         * seed actually sees (the harness's counter is ahead because
         * rolled-back inserts still advance it). */
        assert_same(range(1, 40), array_column($dry['positions'], 'position'), 'positions 1 … 40');
        assert_same(40, (int) $dry['diff']['missing'], 'every candidate is missing from an empty queue');

        assert_same(0, $queueCount(), 'a dry run writes no queue row');
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'harvest.seed'")->fetchColumn());
        assert_false($queue->seeded(), 'and it does not claim to have seeded');
        assert_same('', (string) setting('team_harvest_ledger_since', ''));

        /* Deterministic: a second dry run produces the very same order,
         * positions and excluded money (the plan's exit-gate item). */
        $again = $queue->seed(true);

        assert_same($dry['positions'], $again['positions'], 'two dry runs agree on every position');
        assert_same($dry['preview'], $again['preview']);
        assert_same($dry['baseline'], $again['baseline'], 'and on what the baseline would exclude');
        assert_same(0, $queueCount(), 'and neither writes anything');
    },

    'P20C-T11 the seed reconstructs positions, counters and the baseline' => function () use ($clean, $disarm, $grow, $queueCount, $firstPosition): void {
        $clean();
        $disarm();
        $tree  = $grow(3, 't11');
        $queue = new HarvestQueue();

        $result = $queue->seed();

        assert_true((bool) $result['ok']);
        assert_same('ran', $result['seeded']);
        assert_same(40, (int) $result['inserted']);
        assert_same(40, $queueCount());
        assert_same(40, (int) $result['members']);

        $first = $firstPosition();

        assert_same($first, $queue->positionOf($tree['root']), 'the first member ever to reach the gate');
        assert_same([$first + 1, $first + 2, $first + 3], array_map($queue->positionOf(...), $tree['levels'][1]));
        assert_same($first + 4, $queue->positionOf($tree['levels'][2][0]));
        assert_same($first + 39, $queue->positionOf($tree['levels'][3][26]));

        /* The seed's report names the positions the table assigned. */
        assert_same(
            range($first, $first + 39),
            array_column($result['positions'], 'position'),
            'the report matches the rows'
        );

        assert_same(39, $queue->teammatesOf($tree['root']), 'fixture A root: n = 39');
        assert_same(12, $queue->teammatesOf($tree['levels'][1][0]), 'a child: n = 12');
        assert_same(3, $queue->teammatesOf($tree['levels'][2][0]), 'a grandchild: n = 3');
        assert_same(0, $queue->teammatesOf($tree['levels'][3][0]), 'the leaves: n = 0');

        /* The queue is contiguous and the baseline is the seed instant. */
        $positions = array_map('intval', db()->query('SELECT id FROM harvest_queue ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        assert_same(range($first, $first + 39), $positions);

        $instant = $queue->baseline();
        assert_not_null($instant, 'team_harvest_ledger_since is written by the seed');
        assert_same(1, preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string) $instant), 'a DATETIME');

        /* baseline_n is the member's n at that instant, for every row. */
        foreach (db()->query('SELECT user_id, teammates, baseline_n, source FROM harvest_queue')->fetchAll() as $row) {
            assert_same((int) $row['teammates'], (int) $row['baseline_n'], 'baseline_n equals n at the seed instant');
            assert_same('seed', (string) $row['source'], 'a queue row the seed wrote says so');
        }

        /* Fixture A at the baseline: root 1–3, children 1–2, grandchildren
         * 1 = 18 levels worth ₱25,000.00 over 13 members (§16.3). */
        assert_same(18, (int) $result['baseline']['levels']);
        assert_same(25000.0, (float) $result['baseline']['amount'], 'fixture A is worth ₱25,000.00 at the baseline');
        assert_same(13, (int) $result['baseline']['members'], '13 members have a completed level');

        /* One audit row records the run. */
        $audit = db()->query("SELECT meta_json FROM audit_log WHERE action = 'harvest.seed'")->fetchAll();
        assert_count(1, $audit);
        $meta = json_decode((string) $audit[0]['meta_json'], true);
        assert_same(40, (int) $meta['inserted']);
        assert_same(3, (int) $meta['gate']);
        assert_same((string) $instant, (string) $meta['instant']);
    },

    'P20C-T12 --by-id orders by member id and the dry run reports the diff' => function () use ($clean, $disarm, $account, $rowOf, $firstPosition): void {
        $clean();
        $disarm();

        /* The member with the lower id qualifies last, so the two orders
         * genuinely disagree. */
        $older = $account('t12_older');
        $newer = $account('t12_newer');

        foreach (['t12_n1', 't12_n2', 't12_n3'] as $name) {
            $account($name, $newer, 'member', '2026-10-01 10:00:00');
        }

        foreach (['t12_o1', 't12_o2', 't12_o3'] as $name) {
            $account($name, $older, 'member', '2026-10-10 10:00:00');
        }

        $queue = new HarvestQueue();
        $ran   = $queue->seed();

        assert_true((bool) $ran['ok']);
        assert_same(2, (int) $ran['inserted']);
        assert_same($firstPosition(), $queue->positionOf($newer), 'the qualification order puts the earlier gate first');
        assert_same($firstPosition() + 1, $queue->positionOf($older));

        $dry = $queue->seed(true, true);

        assert_true((bool) $dry['ok']);
        assert_same('id', $dry['order']);
        assert_same(2, (int) $dry['diff']['changed'], 'both positions would differ');
        assert_count(2, $dry['diff']['rows']);

        /* The diff names the current position and the one the order would
         * give — it never claims the run would renumber anything. */
        $wouldBe = [];

        foreach ($dry['diff']['rows'] as $row) {
            $wouldBe[(int) $row['user_id']] = (int) $row['would_be'];
            assert_same($queue->positionOf((int) $row['user_id']), (int) $row['current']);
        }

        assert_same(1, $wouldBe[$older], '--by-id would put the older member first');
        assert_same(2, $wouldBe[$newer]);
        assert_same(0, (int) $dry['inserted']);

        /* A dry run never renumbers: the stored positions are untouched. */
        assert_same($firstPosition(), $queue->positionOf($newer));
        assert_same($firstPosition() + 1, $queue->positionOf($older));
        $olderRow = $rowOf($older);
        assert_not_null($olderRow);
        assert_same($firstPosition() + 1, $olderRow['position']);
    },

    'P20C-T13 a second seed writes nothing' => function () use ($clean, $disarm, $grow, $queueCount): void {
        $clean();
        $disarm();
        $grow(3, 't13');
        $queue = new HarvestQueue();

        $first = $queue->seed();
        assert_true((bool) $first['ok']);
        assert_same(1, (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'harvest.seed'")->fetchColumn(), 'the first run audits');

        $instant   = $queue->baseline();
        $positions = $queue->map();
        $baselines = db()->query('SELECT user_id, teammates, baseline_n FROM harvest_queue ORDER BY id')->fetchAll();

        $second = $queue->seed();

        assert_false((bool) $second['ok']);
        assert_same('already', $second['refused']);
        assert_same('already', $second['seeded']);
        assert_same(0, (int) $second['inserted']);
        assert_contains('already seeded', mb_strtolower((string) $second['message']));
        assert_same($instant, $queue->baseline(), 'the baseline instant is unchanged');
        assert_same(40, $queueCount(), 'no second row');
        assert_same($positions, $queue->map(), 'no position moved');
        assert_same($baselines, db()->query('SELECT user_id, teammates, baseline_n FROM harvest_queue ORDER BY id')->fetchAll());
        assert_same(1, (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'harvest.seed'")->fetchColumn(), 'a refusal audits nothing');
    },

    'P20C-T14 a non-empty queue is refused and --force only appends' => function () use ($clean, $disarm, $grow, $account, $firstPosition): void {
        $clean();
        $disarm();
        $tree  = $grow(3, 't14');
        $queue = new HarvestQueue();

        assert_true((bool) $queue->seed()['ok']);

        /* An imported member who already holds the gate but has no row:
         * written straight to the tables, never through the hook. */
        $imported = $account('t14_imported');
        $account('t14_i1', $imported);
        $account('t14_i2', $imported);
        $account('t14_i3', $imported);

        assert_null($queue->positionOf($imported));

        $refused = $queue->seed();

        assert_false((bool) $refused['ok']);
        assert_same('already', $refused['refused']);
        assert_contains('40', (string) $refused['message'], 'the message names the stored count');
        assert_null($queue->positionOf($imported), 'a refusal writes nothing');

        $forced = $queue->seed(false, false, true);

        assert_true((bool) $forced['ok']);
        assert_same(1, (int) $forced['inserted'], 'only the missing member is appended');
        assert_same($firstPosition() + 40, $queue->positionOf($imported), 'after the existing positions');
        assert_same($firstPosition(), $queue->positionOf($tree['root']), 'and never before them');
        assert_same(
            $firstPosition() + 39,
            (int) db()->query('SELECT MAX(id) FROM harvest_queue WHERE user_id <> ' . $imported)->fetchColumn()
        );
    },

    'P20C-T15 a tree with no gate holder seeds an empty but live queue' => function () use ($clean, $disarm, $account, $queueCount, $firstPosition): void {
        $clean();
        $disarm();
        $queue = new HarvestQueue();

        $root = $account('t15_root');
        $account('t15_a', $root);
        $account('t15_b', $root);

        $result = $queue->seed();

        assert_true((bool) $result['ok']);
        assert_same(0, (int) $result['inserted']);
        assert_same(0, $queueCount(), 'nobody holds the gate');
        assert_true($queue->seeded(), 'but the queue is live from now on');
        assert_not_null($queue->baseline());
        assert_same(0, (int) $result['baseline']['levels']);

        /* And the hook now records a qualification that happens later. */
        $account('t15_c', $root);
        assert_not_null($queue->qualifySponsor($root), 'the seed switched the hook on');
        assert_same($firstPosition(), $queue->positionOf($root));
    },

    /* ==========================================================
     *  The counters (plan §12.4, §14.4)
     * ========================================================== */

    'P20C-T16 a qualification twelve levels down moves all eleven queued ancestors' => function () use ($clean, $arm, $account, $queueCount, $firstPosition): void {
        $clean();
        $arm();
        $queue = new HarvestQueue();

        /* An unqualified root above the spine: it is an ancestor and it is
         * not queued, so it must never be touched (Rule 7). */
        $outside = $account('t16_outside');
        $spine   = [$account('t16_spine_1', $outside)];
        $queue->qualifySponsor($outside);

        /* Each spine member collects two leaf directs, then the next spine
         * member is its third direct — so every spine member is queued
         * before the one below it. */
        for ($level = 1; $level <= 11; $level++) {
            for ($leaf = 1; $leaf <= 2; $leaf++) {
                $account('t16_leaf_' . $level . '_' . $leaf, $spine[$level - 1]);
                $queue->qualifySponsor($spine[$level - 1]);
            }

            $next = $account('t16_spine_' . ($level + 1), $spine[$level - 1]);
            $queue->qualifySponsor($spine[$level - 1]);
            $spine[] = $next;
        }

        assert_count(12, $spine);
        assert_same(11, $queueCount(), 'the eleven upper spine members hold a position');
        assert_same($firstPosition(), $queue->positionOf($spine[0]));

        $before = [];

        foreach (array_slice($spine, 0, 11) as $member) {
            $before[$member] = $queue->teammatesOf($member);
        }

        assert_same(10, $before[$spine[0]], 'the top of the spine already counts the ten below it');

        /* The bottom of the spine now opens its own gate. */
        for ($leaf = 1; $leaf <= 3; $leaf++) {
            $account('t16_bottom_' . $leaf, $spine[11]);
            $queue->qualifySponsor($spine[11]);
        }

        assert_same($firstPosition() + 11, $queue->positionOf($spine[11]), 'the deepest spine member is position #12');

        foreach (array_slice($spine, 0, 11) as $index => $member) {
            assert_same(
                $before[$member] + 1,
                $queue->teammatesOf($member),
                'ancestor ' . ($index + 1) . ' moved by exactly one — an implementation capped at ten levels fails here'
            );
        }

        assert_null($queue->positionOf($outside), 'the non-queued ancestor holds no position');
        assert_same(1, $queue->directs($outside));

        /* The spine's own counts descend by one. */
        assert_same(11, $queue->teammatesOf($spine[0]));
        assert_same(1, $queue->teammatesOf($spine[10]));
        assert_same(0, $queue->teammatesOf($spine[11]));
    },

    'P20C-T17 a qualification under a sibling subtree moves nothing else' => function () use ($clean, $arm, $account, $firstPosition): void {
        $clean();
        $arm();
        $queue = new HarvestQueue();

        $root  = $account('t17_root');
        $left  = $account('t17_left', $root);
        $right = $account('t17_right', $root);

        foreach (['t17_l1', 't17_l2', 't17_l3'] as $name) {
            $account($name, $left);
            $queue->qualifySponsor($left);
        }

        foreach (['t17_r1', 't17_r2', 't17_r3'] as $name) {
            $account($name, $right);
            $queue->qualifySponsor($right);
        }

        assert_same($firstPosition(), $queue->positionOf($left));
        assert_same($firstPosition() + 1, $queue->positionOf($right));
        assert_same(0, $queue->teammatesOf($right));

        $rightBefore = $queue->teammatesOf($right);

        /* A new qualification deep under the left subtree. */
        $under = $account('t17_under', $left);

        foreach (['t17_u1', 't17_u2', 't17_u3'] as $name) {
            $account($name, $under);
            $queue->qualifySponsor($under);
        }

        assert_same(1, $queue->teammatesOf($left), 'the left ancestor moved');
        assert_same($rightBefore, $queue->teammatesOf($right), 'the sibling subtree did not');
        assert_same(0, $queue->recount($right));
        assert_same(1, $queue->recount($left));
        assert_null($queue->positionOf($root), 'the root has only two directs, so it is not queued');
    },

    'P20C-T18 a member who qualified after the newcomer is not retro-counted' => function () use ($clean, $arm, $account, $firstPosition): void {
        $clean();
        $arm();
        $queue = new HarvestQueue();

        /* The descendant qualifies at #1, its ancestor at #2. */
        $ancestor   = $account('t18_ancestor');
        $descendant = $account('t18_descendant', $ancestor);

        foreach (['t18_d1', 't18_d2', 't18_d3'] as $name) {
            $account($name, $descendant);
            $queue->qualifySponsor($descendant);
        }

        foreach (['t18_a1', 't18_a2'] as $name) {
            $account($name, $ancestor);
            $queue->qualifySponsor($ancestor);
        }

        assert_same($firstPosition(), $queue->positionOf($descendant));
        assert_same($firstPosition() + 1, $queue->positionOf($ancestor));
        assert_same(0, $queue->teammatesOf($ancestor), 'the earlier qualification is permanently excluded (D-17)');

        /* A later qualification below the descendant *does* count for the
         * ancestor: the exclusion is about order, not depth. */
        $grandchild = $account('t18_grandchild', $descendant);

        foreach (['t18_g1', 't18_g2', 't18_g3'] as $name) {
            $account($name, $grandchild);
            $queue->qualifySponsor($grandchild);
        }

        assert_same(1, $queue->teammatesOf($ancestor), 'a later qualification counts');
        assert_same(1, $queue->teammatesOf($descendant));
        assert_same(1, $queue->recount($ancestor), 'and the recount agrees');
        assert_same(1, $queue->recount($descendant));
    },

    'P20C-T19 every stored counter equals a from-scratch recount' => function () use ($clean, $disarm, $grow): void {
        $clean();
        $disarm();
        $grow(3, 't19');
        $queue = new HarvestQueue();

        assert_true((bool) $queue->seed()['ok']);

        $rows = db()->query('SELECT user_id, teammates FROM harvest_queue ORDER BY id')->fetchAll();
        assert_count(40, $rows);

        foreach ($rows as $row) {
            assert_same(
                (int) $row['teammates'],
                $queue->recount((int) $row['user_id']),
                'member ' . (int) $row['user_id'] . ' (n = ' . (int) $row['teammates'] . ')'
            );
        }

        /* The bulk pass agrees with the per-member one. */
        $counts = $queue->recountAll();

        foreach ($rows as $row) {
            assert_same((int) $row['teammates'], $counts[(int) $row['user_id']]);
        }

        $verify = $queue->verify();
        assert_true((bool) $verify['ok'], 'a freshly seeded queue verifies clean');
        assert_same([], $verify['drift']);
        assert_same([], $verify['cycles']);
        assert_same([], $verify['self_sponsored']);
        assert_same(40, (int) $verify['queued']);
    },

    /* ==========================================================
     *  The rebuild (plan §14.4 Rule 3)
     * ========================================================== */

    'P20C-T20 a counter edit is reported and repaired only with --apply' => function () use ($clean, $disarm, $grow, $firstPosition): void {
        $clean();
        $disarm();
        $tree  = $grow(3, 't20');
        $queue = new HarvestQueue();

        assert_true((bool) $queue->seed()['ok']);

        $root   = $tree['root'];
        $member = $tree['levels'][2][0];
        $stored = $queue->teammatesOf($member);

        db()->prepare('UPDATE harvest_queue SET teammates = ? WHERE user_id = ?')
            ->execute([$stored + 5, $member]);

        $report = $queue->rebuild();

        assert_false((bool) $report['ok']);
        assert_true((bool) $report['dry_run']);
        assert_count(1, $report['drift']);
        assert_same($member, $report['drift'][0]['user_id']);
        assert_same($stored + 5, $report['drift'][0]['stored']);
        assert_same($stored, $report['drift'][0]['recount']);
        assert_same([], $report['repaired'], 'a report-only rebuild writes nothing');
        assert_same($stored + 5, $queue->teammatesOf($member), 'the stored value is untouched');
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'harvest.queue.repair'")->fetchColumn());

        $applied = $queue->rebuild(false, true);

        assert_true((bool) $applied['applied']);
        assert_true((bool) $applied['ok']);
        assert_same([], $applied['violations']);
        assert_count(1, $applied['repaired']);
        assert_count(1, $applied['drift'], 'the report still names what was found');
        assert_same($stored, $queue->teammatesOf($member), 'the counter is repaired to the recount');
        assert_same($firstPosition(), $queue->positionOf($root), 'a repair never renumbers');
        assert_same($stored, $queue->recount($member));

        /* A second rebuild has nothing left to report. */
        $cleanReport = $queue->rebuild();
        assert_true((bool) $cleanReport['ok']);
        assert_same([], $cleanReport['drift']);

        /* One audit row names the before and after of every repaired row. */
        $audit = db()->query(
            "SELECT meta_json FROM audit_log WHERE action = 'harvest.queue.repair' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();

        assert_not_same(false, $audit);
        $meta = json_decode((string) $audit, true);
        assert_same($member, (int) $meta['repaired'][0]['user_id']);
        assert_same($stored + 5, (int) $meta['repaired'][0]['before']);
        assert_same($stored, (int) $meta['repaired'][0]['after']);
    },

    'P20C-T21 a rebuild never rewrites the baseline' => function () use ($clean, $disarm, $grow): void {
        $clean();
        $disarm();
        $grow(3, 't21');
        $queue = new HarvestQueue();

        assert_true((bool) $queue->seed()['ok']);

        $baselines = db()->query('SELECT user_id, baseline_n FROM harvest_queue ORDER BY id')->fetchAll();
        assert_count(40, $baselines);

        $report = $queue->rebuild();

        assert_true((bool) $report['ok'], 'a clean queue has nothing to repair');
        assert_same([], $report['drift']);
        assert_same([], $report['missing']);
        assert_same([], $report['violations']);
        assert_same($baselines, db()->query('SELECT user_id, baseline_n FROM harvest_queue ORDER BY id')->fetchAll());

        /* Even an applied rebuild leaves the baseline alone: it is
         * history, not a counter. */
        $queue->rebuild(false, true);
        assert_same($baselines, db()->query('SELECT user_id, baseline_n FROM harvest_queue ORDER BY id')->fetchAll());
    },

    'P20C-T22 the whole phase moves no money' => function () use ($clean, $disarm, $grow): void {
        $clean();
        $disarm();

        $wallet = static fn (): array => [
            'ledger'       => db()->query('SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger')->fetchColumn(),
            'balances'     => db()->query('SELECT COALESCE(SUM(ewallet_balance), 0) FROM users')->fetchColumn(),
            'withdrawable' => db()->query('SELECT COALESCE(SUM(withdrawable_balance), 0) FROM users')->fetchColumn(),
            'rows'         => db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
        ];

        $before = $wallet();
        $tree   = $grow(3, 't22');
        $queue  = new HarvestQueue();

        assert_true((bool) $queue->seed()['ok']);
        $queue->rebuild(false, true);

        assert_same($before, $wallet(), 'no wallet movement, no balance change');
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn(), '20C writes no award');
        assert_same(
            0,
            (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'team_harvest'")->fetchColumn()
        );

        $stats = $queue->stats();
        assert_same(40, $stats['queued']);
        assert_same(0, $stats['awards']);
        assert_same(39, $queue->teammatesOf($tree['root']));
    },

    'P20C-T23 the hook commits and rolls back with the registration' => function () use ($clean, $arm, $account, $firstPosition): void {
        $clean();
        $arm();
        $queue   = new HarvestQueue();
        $sponsor = $account('t23_sponsor');
        $account('t23_d1', $sponsor);
        $account('t23_d2', $sponsor);

        /* The harness has already opened a transaction, so register()
         * takes a savepoint and releases it. */
        assert_true(db()->inTransaction());

        $summary = (new RegistrationService())->register([
            'mode'             => 'standard',
            'username'         => 't23_newcomer',
            'email'            => 't23_newcomer@p20c.test',
            'password'         => 'P20cMember@123',
            'password_confirm' => 'P20cMember@123',
            'sponsor'          => 't23_sponsor',
            'terms'            => '1',
            'binary_position'  => 'right',
        ]);

        $newcomer = (int) $summary['user_id'];

        assert_same(1, (int) db()->query('SELECT COUNT(*) FROM users WHERE id = ' . $newcomer)->fetchColumn(), 'the account exists');
        assert_same($firstPosition(), $queue->positionOf($sponsor), 'and the sponsor holds the first position');
        assert_same(1, (int) db()->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());

        /* Roll the whole thing back: neither row may survive. */
        db()->rollBack();

        $find = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
        $find->execute([$newcomer]);
        assert_same(0, (int) $find->fetchColumn(), 'no user row survives the rollback');
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn(), 'no queue row either');
    },

    /* ==========================================================
     *  The matrix (plan §12.5)
     * ========================================================== */

    'P20C-T24 a sponsor cycle and a self-sponsor terminate and are reported' => function () use ($clean, $arm, $account, $queueCount): void {
        $clean();
        $arm();
        $queue = new HarvestQueue();

        $a = $account('t24_a');
        $b = $account('t24_b', $a);
        $c = $account('t24_c');

        /* Corrupt the tree by hand: A ⇄ B, and C sponsors itself. */
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([$b, $a]);
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([$c, $c]);

        /* Two queued members inside the cycle. */
        db()->prepare(
            "INSERT INTO harvest_queue (user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source)
             VALUES (?, '2026-11-01 09:00:00', 3, 0, 0, 'seed'), (?, '2026-11-01 09:00:01', 3, 0, 0, 'seed')"
        )->execute([$a, $b]);

        assert_same(2, $queueCount());
        assert_same([$a], array_column($queue->ancestorsOf($b), 'user_id'), 'the walk stops at the visited set');
        assert_same([$b], array_column($queue->ancestorsOf($a), 'user_id'), 'and it terminates in both directions');
        assert_same([], $queue->ancestorsOf($c), 'a self-sponsor has no ancestor chain');

        /* One qualification through the cycle moves each ancestor once. */
        $queue->incrementAncestors($b, $queue->stats()['next']);

        assert_same(1, $queue->teammatesOf($a), 'moved once, not twice');
        assert_same(0, $queue->teammatesOf($b), 'and never itself');

        $verify = $queue->verify();

        assert_false((bool) $verify['ok']);
        assert_same([$c], array_column($verify['self_sponsored'], 'user_id'), 'the self-sponsor is reported');
        assert_same([$a, $b], array_column($verify['cycles'], 'user_id'), 'both rows of the cycle are reported');
        assert_same([], $verify['non_members']);
        assert_same([], $verify['drift'], 'the counters still agree with the definition');
    },

    'P20C-T25 the matrix fills twelve teammates into the 3-ary layout' => function () use ($clean, $disarm, $grow): void {
        $clean();
        $disarm();
        $tree  = $grow(3, 't25');
        $queue = new HarvestQueue();

        assert_true((bool) $queue->seed()['ok']);

        $child = $tree['levels'][1][0];
        assert_same(12, $queue->teammatesOf($child));

        $matrix = $queue->matrix($child);

        assert_count(12, $matrix, 'the matrix has exactly n filled slots');
        assert_same(range(0, 11), array_column($matrix, 'slot'));
        assert_same([1, 1, 1, 2, 2, 2, 2, 2, 2, 2, 2, 2], array_column($matrix, 'level'));
        assert_same(
            [0, 0, 0, 3, 3, 3, 3, 3, 3, 3, 3, 3],
            array_column($matrix, 'start'),
            'level 2 starts at slot 3 = (3^2 − 3)/2'
        );
        assert_same([3, 3, 3, 9, 9, 9, 9, 9, 9, 9, 9, 9], array_column($matrix, 'slots'));

        /* Slots 0–2 sit under the member; the three level-2 triples sit
         * under teammates 0, 1 and 2 (integer division). */
        assert_same($child, $matrix[0]['placed_under']['user_id']);
        assert_same($child, $matrix[2]['placed_under']['user_id']);

        $roster = $queue->teamRoster($child);
        assert_count(12, $roster);
        assert_same($roster[0]['user_id'], $matrix[3]['placed_under']['user_id']);
        assert_same($roster[0]['user_id'], $matrix[5]['placed_under']['user_id']);
        assert_same($roster[1]['user_id'], $matrix[6]['placed_under']['user_id']);
        assert_same($roster[2]['user_id'], $matrix[9]['placed_under']['user_id']);
        assert_same($roster[11]['user_id'], $matrix[11]['user_id'], 'the last slot holds the last teammate');

        /* Position order, oldest qualification first (§12.5 Rule 2). */
        assert_same(array_column($roster, 'position'), array_column($matrix, 'position'));

        /* Pagination is a slice of the same matrix. */
        $page = $queue->matrix($child, 3, 4);
        assert_same([3, 4, 5, 6], array_column($page, 'slot'));
        assert_same(array_slice(array_column($matrix, 'user_id'), 3, 4), array_column($page, 'user_id'));

        /* A member with no teammates has an empty matrix, and an offset
         * past `n` returns nothing: a partial block pays nothing. */
        assert_same([], $queue->matrix((int) $tree['levels'][3][0]));
        assert_same([], $queue->matrix($child, 20));
    },

    /* ==========================================================
     *  Statistics, the CLI and the arithmetic (T26–T30)
     * ========================================================== */

    'P20C-T26 stats() reports fixture B: 121 qualified and a deepest of 120' => function () use ($clean, $disarm, $grow, $firstPosition): void {
        $clean();
        $disarm();
        $tree = $grow(4, 't26');

        assert_same(364, $tree['accounts'], 'fixture B: 121 qualified + 243 leaf directs');

        $queue  = new HarvestQueue();
        $result = $queue->seed();

        assert_true((bool) $result['ok']);
        assert_same(121, (int) $result['inserted']);

        $first = $firstPosition();
        $stats = $queue->stats();

        assert_same(121, $stats['queued'], '121 positions');
        assert_same($first + 120, $stats['positions']);
        assert_same($first + 121, $stats['next']);
        assert_same(120, $stats['deepest'], 'the root holds n = 120');
        assert_same(0, $stats['awards'], '20C writes no award');
        assert_true($stats['seeded']);
        assert_not_null($stats['baseline']);

        assert_same(120, $queue->teammatesOf($tree['root']));
        assert_same(39, $queue->teammatesOf($tree['levels'][1][0]));
        assert_same(12, $queue->teammatesOf($tree['levels'][2][0]));
        assert_same(3, $queue->teammatesOf($tree['levels'][3][0]));
        assert_same(0, $queue->teammatesOf($tree['levels'][4][0]));

        /* The whole fixture is worth ₱88,000.00 at the baseline (§16.3). */
        assert_same(88000.0, (float) $result['baseline']['amount']);
        assert_same(58, (int) $result['baseline']['levels']);
        assert_same(40, (int) $result['baseline']['members']);
    },

    'P20C-T27 the job is a real command: --help, exit codes and a full seed' => function () use ($clean, $appRoot): void {
        $clean();

        /**
         * Run the real CLI against one database.
         *
         * @param list<string> $arguments
         * @return array{exit:int, output:string, json:?array}
         */
        $run = static function (string $database, array $arguments) use ($appRoot): array {
            $previous = getenv('ECO_DB_NAME');
            putenv('ECO_DB_NAME=' . $database);

            try {
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($appRoot . '/bin/harvest_queue_seed.php');

                foreach ($arguments as $argument) {
                    $command .= ' ' . escapeshellarg($argument);
                }

                $lines = [];
                exec($command . ' 2>&1', $lines, $code);
                $output = implode("\n", $lines);
                $json   = json_decode($output, true);
            } finally {
                if ($previous === false) {
                    putenv('ECO_DB_NAME');
                } else {
                    putenv('ECO_DB_NAME=' . $previous);
                }
            }

            return ['exit' => $code, 'output' => $output, 'json' => is_array($json) ? $json : null];
        };

        /* 1. --help exits 0 and names every flag, before touching a database. */
        $help = $run('ecowealth_sneat_test', ['--help']);

        assert_same(0, $help['exit']);
        foreach (['--dry-run', '--by-id', '--force', '--rebuild', '--apply', '--json', '--help'] as $flag) {
            assert_contains($flag, $help['output']);
        }

        /* 2. An unreachable database exits 2, never 0. */
        $none = $run('ecowealth_sneat_no_such_db_test', ['--json']);

        assert_same(2, $none['exit'], 'environment failures exit 2: ' . $none['output']);
        assert_not_null($none['json'], 'and still answer in JSON: ' . $none['output']);
        assert_false((bool) $none['json']['ok']);

        /* 3. A real end-to-end seed on a committed scratch schema. */
        [$pdo, $database] = test_scratch_database('tool_b');
        test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');
        $pdo->exec("UPDATE settings SET value = '3' WHERE key_name = 'team_harvest_qualify_directs'");

        $insert = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, role, member_status, sponsor_id, referral_code,
                                is_active, created_at)
             VALUES (?, ?, 'x', 'member', 'active', ?, ?, 1, ?)"
        );

        $add = static function (string $name, ?int $sponsor, string $at) use ($insert, $pdo): int {
            $insert->execute([$name, $name . '@cli.p20c', $sponsor, strtoupper(bin2hex(random_bytes(4))), $at]);

            return (int) $pdo->lastInsertId();
        };

        $top = $add('cli_top', null, '2026-10-01 08:00:00');

        foreach (['cli_a1', 'cli_a2', 'cli_a3'] as $index => $name) {
            $add($name, $top, '2026-10-0' . ($index + 2) . ' 08:00:00');
        }

        $child = $add('cli_child', $top, '2026-10-01 09:00:00');

        foreach (['cli_b1', 'cli_b2', 'cli_b3'] as $index => $name) {
            $add($name, $child, '2026-10-05 08:0' . $index . ':00');
        }

        $dry = $run($database, ['--dry-run', '--json']);

        assert_same(0, $dry['exit'], 'a dry run succeeds: ' . $dry['output']);
        assert_true((bool) $dry['json']['dry_run']);
        assert_same(2, (int) $dry['json']['members']);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn(), 'a dry run writes nothing');

        $real = $run($database, ['--json']);

        assert_same(0, $real['exit'], 'the seed succeeds: ' . $real['output']);
        assert_same('ran', $real['json']['seeded']);
        assert_same(2, (int) $real['json']['inserted']);
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());
        assert_same(1, (int) $pdo->query('SELECT MIN(id) FROM harvest_queue')->fetchColumn(), 'a fresh table starts at position 1');

        $since = (string) $pdo->query(
            "SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since'"
        )->fetchColumn();

        assert_same($real['json']['instant'], $since, 'the baseline instant is stored');
        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'harvest.seed'")->fetchColumn(),
            'one audit row per real seed'
        );

        $again = $run($database, ['--json']);

        assert_same(1, $again['exit'], 'a second real seed is refused');
        assert_same('already', $again['json']['refused']);
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn(), 'and writes nothing');
    },

    'P20C-T28 a seeded row records the gate as its direct count and the third instant' => function () use ($clean, $disarm, $account, $rowOf, $firstPosition): void {
        $clean();
        $disarm();
        $queue = new HarvestQueue();

        $sponsor = $account('t28_sponsor');

        /* Five directs at known instants: the gate is three, so both the
         * position and the instant come from the third one. */
        foreach ([1, 2, 3, 4, 5] as $index) {
            $account('t28_d' . $index, $sponsor, 'member', '2026-09-0' . $index . ' 12:00:00');
        }

        $extra = $account('t28_extra');

        foreach (['t28_e1', 't28_e2', 't28_e3'] as $name) {
            $account($name, $extra, 'member', '2026-09-09 12:00:00');
        }

        assert_true((bool) $queue->seed()['ok']);

        $row = $rowOf($sponsor);
        assert_not_null($row);
        assert_same('2026-09-03 12:00:00', $row['qualified_at'], 'the third direct\'s created_at');
        assert_same(3, $row['directs_at_qualification'], 'the count at that instant, not today\'s five');
        assert_same('seed', $row['source']);
        assert_same($firstPosition() + 1, $queue->positionOf($extra));

        /* A live row records what the hook observed, and the two agree on
         * the instant by construction (Rule 12). */
        $live = $account('t28_live');
        $account('t28_l1', $live, 'member', '2026-09-20 12:00:00');
        $account('t28_l2', $live, 'member', '2026-09-21 12:00:00');
        $account('t28_l3', $live, 'member', '2026-09-22 12:00:00');

        assert_not_null($queue->qualifySponsor($live));

        $liveRow = $rowOf($live);
        assert_not_null($liveRow);
        assert_same('2026-09-22 12:00:00', $liveRow['qualified_at'], 'the third direct\'s created_at on the live path too');
        assert_same(3, $liveRow['directs_at_qualification']);
        assert_same('registration', $liveRow['source']);
        assert_same(0, $liveRow['baseline_n'], 'anyone queued after the seed has no baseline');
        assert_same($firstPosition() + 2, $liveRow['position']);
    },

    'P20C-T29 the 3-ary slot arithmetic is exact and structural' => function (): void {
        /* The level of a slot: floor(log3(2r+3)). */
        assert_same(1, HarvestQueue::levelOfSlot(0));
        assert_same(1, HarvestQueue::levelOfSlot(2));
        assert_same(2, HarvestQueue::levelOfSlot(3));
        assert_same(2, HarvestQueue::levelOfSlot(11));
        assert_same(3, HarvestQueue::levelOfSlot(12));
        assert_same(3, HarvestQueue::levelOfSlot(38));
        assert_same(4, HarvestQueue::levelOfSlot(39));
        assert_same(4, HarvestQueue::levelOfSlot(119));
        assert_same(5, HarvestQueue::levelOfSlot(120));
        assert_same(10, HarvestQueue::levelOfSlot(88571));
        assert_same(11, HarvestQueue::levelOfSlot(88572), 'the matrix is unbounded; only ten levels pay');

        /* Levels 1–10 are TeamHarvestLevel's arithmetic, so the board and
         * the money can never disagree. */
        for ($level = 1; $level <= 10; $level++) {
            assert_same(TeamHarvestLevel::slots($level), HarvestQueue::slotsOfLevel($level));
            assert_same(
                TeamHarvestLevel::threshold($level) - TeamHarvestLevel::slots($level),
                HarvestQueue::startOfLevel($level)
            );
            assert_same(
                TeamHarvestLevel::threshold($level),
                HarvestQueue::startOfLevel($level) + HarvestQueue::slotsOfLevel($level),
                'start + slots = the threshold that completes the level'
            );
        }

        assert_same(0, HarvestQueue::startOfLevel(1));
        assert_same(3, HarvestQueue::startOfLevel(2));
        assert_same(12, HarvestQueue::startOfLevel(3));
        assert_same(59049, HarvestQueue::slotsOfLevel(10));
        assert_same(88572, HarvestQueue::startOfLevel(11));
    },

    'P20C-T30 an invalid gate refuses the seed and a clean queue verifies' => function () use ($clean, $disarm, $account, $queueCount, $firstPosition): void {
        $clean();
        $disarm();
        $root = $account('t30_root');

        foreach (['t30_a', 't30_b', 't30_c'] as $name) {
            $account($name, $root);
        }

        $queue = new HarvestQueue();

        foreach (['abc', '', '0', '11', '-3', '2.5'] as $bad) {
            setting_set('team_harvest_qualify_directs', $bad);
            setting_cache_clear();
            setting_all();

            $refused = $queue->seed();

            assert_false((bool) $refused['ok'], 'the gate "' . $bad . '" is refused');
            assert_same('gate', $refused['refused']);
            assert_same(0, $queueCount(), 'nothing is written with an invalid gate');
            assert_false($queue->seeded(), 'and the queue does not claim to be seeded');
        }

        setting_set('team_harvest_qualify_directs', '3');
        setting_cache_clear();
        setting_all();

        assert_true((bool) $queue->seed()['ok']);
        assert_same($firstPosition(), $queue->positionOf($root));

        $verify = $queue->verify();
        assert_true((bool) $verify['ok'], 'the queue verifies clean after a seed');
        assert_same(1, (int) $verify['queued']);
        assert_same([], $verify['non_members']);
        assert_same([], $verify['drift']);
    },
];

