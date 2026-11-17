<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestEngineTest — Phase 21D slice S1 (plan §6, §12.5,
 *  §14.2, §14.5, §14.7)
 *
 *  Proves the credited award path on its own, before the settlement
 *  engine wires registration:
 *
 *      P21D-T8   an award's wallet row — one General Harvest bonus
 *                credit, ref_type = 'general_harvest', ref_id =
 *                the award id, withdrawable bucket up by the amount
 *      P21D-T9   an award row — status credited, ledger_id set,
 *                credited_at/created_at set, amount = the ladder amount
 *      P21D-T28  duplicate (seq, level) insert — PDOException 23000
 *
 *  Each case runs inside the harness's rolled-back transaction, so the
 *  award and the credit commit together or vanish together (§14.7).
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestEngine
 * ============================================================== */

$fixtures = require __DIR__ . '/../fixtures/general_harvest.php';

$clean  = $fixtures['clean'];
$seed   = $fixtures['seedMarker'];
$switch = $fixtures['switch'];
$arm    = $fixtures['arm'];
$account= $fixtures['account'];
$queue  = $fixtures['queue'];
$append = $fixtures['append'];
$depth  = $fixtures['depth'];
$ladder = $fixtures['ladder'];

return [

    /* ==========================================================
     *  P21D-T8 / T9 — create() + linkLedger() write an award and its
     *  wallet movement atomically (21D-S1)
     * ========================================================== */

    'P21D-T8 an award carries one General Harvest wallet credit, ref_id = the award id' => function () use (
        $clean,
        $seed,
        $switch,
        $arm
    ): void {
        $clean();

        /* Arm at N = 0: the board is armed but no baseline row exists, so
         * the credited path below writes on a clean (seq, level) key. The
         * settlement engine in 21D-S2 owns the baseline check; here we
         * prove the write primitives in isolation. */
        $seed();
        $result = $arm();
        $switch(true);

        assert_true((bool) $result['armed'], 'the board is armed at N = 0');
        assert_same(0, (int) $result['n'], 'N0 is zero on an empty queue');

        $holder = test_user('p21d_s1_t8');

        /* Node 0 level 1 completes at N = 4; nothing was baselined for it,
         * so a credited row is legal here. */
        $snapshot = [
            'user_id'     => (int) $holder['id'],
            'level'       => 1,
            'threshold_n' => 4,
            'n_at_award'  => 4,
            'amount'      => 2000.00,
            'credited_at' => date('Y-m-d H:i:s'),
        ];

        $written = GeneralHarvestAward::writeCredited(0, $snapshot);

        assert_true(array_key_exists('award_id', $written), 'writeCredited returns the award id');
        assert_true(array_key_exists('ledger_id', $written), 'writeCredited returns the ledger id');
        assert_true($written['award_id'] > 0, 'a positive award id');
        assert_true($written['ledger_id'] > 0, 'a positive ledger id');

        /* P21D-T9 — the award row: credited, linked, snapshotted. */
        $row = GeneralHarvestAward::find((int) $written['award_id']);

        assert_not_null($row, 'the award row exists');
        assert_same(0, (int) $row['seq'], 'node 0');
        assert_same(1, (int) $row['level'], 'level 1');
        assert_same('credited', (string) $row['status'], 'a credited row');
        assert_same((int) $written['ledger_id'], (int) $row['ledger_id'], 'ledger_id names the movement');
        assert_same(4, (int) $row['threshold_n'], 'the closed-form threshold');
        assert_same(4, (int) $row['n_at_award'], 'the queue length at award');
        assert_same(2000.0, round((float) $row['amount'], 2), 'the ladder amount, snapshotted');
        assert_not_null($row['credited_at'], 'credited_at is set');
        assert_not_null($row['created_at'], 'created_at is set');

        /* P21D-T8 — the wallet row: one General Harvest credit, ref_id =
         * the award id, withdrawable bucket up by the amount. */
        $ledger = db()->prepare(
            "SELECT id, user_id, type, amount, ref_type, ref_id, note
               FROM ewallet_ledger
              WHERE ref_type = 'general_harvest' AND ref_id = ?
               LIMIT 1"
        );
        $ledger->execute([(int) $written['award_id']]);
        $movement = $ledger->fetch();

        assert_not_null($movement, 'the wallet movement exists');
        assert_same((int) $holder['id'], (int) $movement['user_id'], 'the node holder is credited');
        assert_same('credit', (string) $movement['type'], 'a credit movement');
        assert_same('general_harvest', (string) $movement['ref_type'], 'the General Harvest ref type');
        assert_same((int) $written['award_id'], (int) $movement['ref_id'], 'ref_id is the award id');
        assert_same(2000.0, round((float) $movement['amount'], 2), 'the same amount as the award');

        $balance = db()->prepare(
            'SELECT withdrawable_balance FROM users WHERE id = ? LIMIT 1'
        );
        $balance->execute([(int) $holder['id']]);
        assert_same(2000.0, round((float) $balance->fetchColumn(), 2), 'the withdrawable bucket rose by the award');
    },

    'P21D-T9 a second award for the same node writes its own movement' => function () use (
        $clean,
        $seed,
        $switch,
        $arm
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $holder = test_user('p21d_s1_t9');

        $first = GeneralHarvestAward::writeCredited(0, [
            'user_id'     => (int) $holder['id'],
            'level'       => 1,
            'threshold_n' => 4,
            'n_at_award'  => 4,
            'amount'      => 2000.00,
            'credited_at' => date('Y-m-d H:i:s'),
        ]);

        /* A second level for the same node: distinct award and movement. */
        $second = GeneralHarvestAward::writeCredited(0, [
            'user_id'     => (int) $holder['id'],
            'level'       => 2,
            'threshold_n' => 13,
            'n_at_award'  => 13,
            'amount'      => 4000.00,
            'credited_at' => date('Y-m-d H:i:s'),
        ]);

        assert_not_same($first['award_id'], $second['award_id'], 'two different awards');
        assert_not_same($first['ledger_id'], $second['ledger_id'], 'two different movements');

        $rows = GeneralHarvestAward::forMember((int) $holder['id']);
        assert_count(2, $rows, 'the member holds both levels');

        $ledger = db()->query(
            "SELECT COUNT(*) FROM ewallet_ledger
              WHERE ref_type = 'general_harvest' AND ref_id IN ({$first['award_id']}, {$second['award_id']})"
        )->fetchColumn();
        assert_same(2, (int) $ledger, 'one movement per award');
    },

    /* ==========================================================
     *  P21D-T28 — the unique anchor holds (21D-S1)
     * ========================================================== */

    'P21D-T28 a duplicate (seq, level) insert is refused with PDOException 23000' => function () use (
        $clean,
        $seed,
        $switch,
        $arm
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $holder = test_user('p21d_s1_t28');
        $now    = date('Y-m-d H:i:s');

        GeneralHarvestAward::writeCredited(0, [
            'user_id'     => (int) $holder['id'],
            'level'       => 1,
            'threshold_n' => 4,
            'n_at_award'  => 4,
            'amount'      => 2000.00,
            'credited_at' => $now,
        ]);

        /* Re-deciding the same block (same seq, same level): the UNIQUE
         * (seq, level) anchor rejects it. */
        $exception = assert_throws(
            static fn () => GeneralHarvestAward::writeCredited(0, [
                'user_id'     => (int) $holder['id'],
                'level'       => 1,
                'threshold_n' => 4,
                'n_at_award'  => 4,
                'amount'      => 2000.00,
                'credited_at' => $now,
            ]),
            PDOException::class,
            'the duplicate insert throws'
        );

        assert_same('23000', $exception->getCode(), 'the unique-violation SQLSTATE');
    },

    /* ==========================================================
     *  S1 manifest — the write primitives ship with 21D
     * ========================================================== */

    'P21D-S1 the 21D write primitives exist on GeneralHarvestAward' => function (): void {
        foreach ([
            'create',
            'linkLedger',
            'writeCredited',
            'levelMap',
            'paidLevels',
            'gridFor',
        ] as $method) {
            assert_true(method_exists(GeneralHarvestAward::class, $method), 'GeneralHarvestAward::' . $method);
        }
    },

    /* ==========================================================
     *  P21D-T1 — Fixture h = 1 (13 accounts, N = 4): one award
     * ========================================================== */

    'P21D-T1 fixture h = 1 pays node 0 level 1 at N = 4' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree   = $account('p21d_t1_root', null);
        $users  = [$tree];

        /* Grow a perfect h = 1 tree: root + 3 children. */
        for ($i = 1; $i <= 3; $i++) {
            $users[] = $account('p21d_t1_n1_' . $i, $tree);
        }

        $n = $queue($users);

        /* N = 4: only node 0's level 1 block (positions #2–#4) is full. */
        assert_same(4, $n, 'N = 4 after the h = 1 queue');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(1, (int) $result['written'], 'one award written');
        assert_same(2000.0, (float) $result['amount'], '₱2,000.00 paid');

        $awards = GeneralHarvestAward::forMember((int) $tree);
        assert_count(1, $awards, 'node 0 holds one award');
        assert_same(1, (int) $awards[0]['level'], 'level 1');
        assert_same('credited', (string) $awards[0]['status'], 'credited');
        assert_same(4, (int) $awards[0]['threshold_n'], 'threshold N = 4');
        assert_same(4, (int) $awards[0]['n_at_award'], 'n_at_award = N');

        /* boardTotal(4) = 1 × 2000 = ₱2,000.00 */
        assert_same(2000.0, round((float) GeneralHarvestLevel::boardTotal(4), 2), 'the closed form agrees');
    },

    /* ==========================================================
     *  P21D-T2 — Fixture h = 2 (40 accounts, N = 13): 5 awards
     * ========================================================== */

    'P21D-T2 fixture h = 2 pays 5 awards worth ₱12,000 at N = 13' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t2_root', null);

        /* h = 2 in BFS order: root, 3 children, 9 grandchildren = 13. */
        $users = [$tree];
        $levelParents = [$tree];

        for ($depth = 1; $depth <= 2; $depth++) {
            $level = [];

            foreach ($levelParents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t2_d' . $depth . '_p' . $parent . '_' . $i, $parent);
                    $level[] = $child;
                    $users[] = $child;
                }
            }

            $levelParents = $level;
        }

        $n = $queue($users);
        assert_same(13, $n, 'N = 13 after the h = 2 queue');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(5, (int) $result['written'], '5 awards: node 0 L1+L2, nodes 1–3 L1');
        assert_same(12000.0, (float) $result['amount'], '₱12,000.00 total');

        /* node 0: L1 ₱2,000 + L2 ₱4,000 = ₱6,000 */
        $node0 = GeneralHarvestAward::paidLevels((int) $tree);
        assert_same([1, 2], $node0, 'node 0 paid levels 1 and 2');

        /* nodes 1–3 (the children, seq 1–3): level 1 each */
        foreach (array_slice($users, 1, 3) as $child) {
            assert_same([1], GeneralHarvestAward::paidLevels((int) $child), 'a child of the root paid level 1 only');
        }

        assert_same(12000.0, round((float) GeneralHarvestLevel::boardTotal(13), 2), 'the closed form agrees');
    },

    /* ==========================================================
     *  P21D-T3 — Fixture h = 3 (121 accounts, N = 40): 18 awards
     * ========================================================== */

    'P21D-T3 fixture h = 3 pays 18 awards worth ₱50,000 at N = 40' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t3_root', null);

        /* h = 3 in BFS order: root + 3 children + 9 grandchildren + 27 great-grandchildren = 40. */
        $users = [$tree];
        $levelParents = [$tree];

        for ($depth = 1; $depth <= 3; $depth++) {
            $level = [];

            foreach ($levelParents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t3_d' . $depth . '_p' . $parent . '_' . $i, $parent);
                    $level[] = $child;
                    $users[] = $child;
                }
            }

            $levelParents = $level;
        }

        $n = $queue($users);
        assert_same(40, $n, 'N = 40 after the h = 3 queue');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(18, (int) $result['written'], '18 awards');
        assert_same(50000.0, (float) $result['amount'], '₱50,000.00 total');

        /* node 0: L1+L2+L3 = 2000+4000+8000 = ₱14,000 */
        assert_same([1, 2, 3], GeneralHarvestAward::paidLevels((int) $tree), 'node 0 paid levels 1–3');

        /* nodes 1–3 (the children, seq 1–3): L1+L2 = ₱6,000 each */
        foreach (array_slice($users, 1, 3) as $child) {
            assert_same([1, 2], GeneralHarvestAward::paidLevels((int) $child), 'a child of the root paid levels 1–2');
        }

        /* nodes 4–12 (the grandchildren, seq 4–12): L1 = ₱2,000 each */
        foreach (array_slice($users, 4, 9) as $grandchild) {
            assert_same([1], GeneralHarvestAward::paidLevels((int) $grandchild), 'a grandchild paid level 1 only');
        }

        assert_same(50000.0, round((float) GeneralHarvestLevel::boardTotal(40), 2), 'the closed form agrees');
    },

    /* ==========================================================
     *  P21D-T4 — Fixture h = 4 (364 accounts, N = 121): 58 awards
     * ========================================================== */

    'P21D-T4 fixture h = 4 pays 58 awards worth ₱176,000 at N = 121' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t4_root', null);

        /* h = 4 in BFS order. */
        $users = [$tree];
        $levelParents = [$tree];

        for ($depth = 1; $depth <= 4; $depth++) {
            $level = [];

            foreach ($levelParents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t4_d' . $depth . '_p' . $parent . '_' . $i, $parent);
                    $level[] = $child;
                    $users[] = $child;
                }
            }

            $levelParents = $level;
        }

        $n = $queue($users);
        assert_same(121, $n, 'N = 121 after the h = 4 queue');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(58, (int) $result['written'], '58 awards');
        assert_same(176000.0, (float) $result['amount'], '₱176,000.00 total');
        assert_same(176000.0, round((float) GeneralHarvestLevel::boardTotal(121), 2), 'the closed form agrees');
    },

    /* ==========================================================
     *  P21D-T5 — Fixture h = 5 (1,093 accounts, N = 364): 179 awards
     * ========================================================== */

    'P21D-T5 fixture h = 5 pays 179 awards worth ₱579,000 at N = 364' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t5_root', null);

        /* h = 5 in BFS order. */
        $users = [$tree];
        $levelParents = [$tree];

        for ($depth = 1; $depth <= 5; $depth++) {
            $level = [];

            foreach ($levelParents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t5_d' . $depth . '_p' . $parent . '_' . $i, $parent);
                    $level[] = $child;
                    $users[] = $child;
                }
            }

            $levelParents = $level;
        }

        $n = $queue($users);
        assert_same(364, $n, 'N = 364 after the h = 5 queue');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(179, (int) $result['written'], '179 awards');
        assert_same(579000.0, (float) $result['amount'], '₱579,000.00 total');
        assert_same(579000.0, round((float) GeneralHarvestLevel::boardTotal(364), 2), 'the closed form agrees');
    },

    /* ==========================================================
     *  P21D-T6 — The 20-member stopped tree: 7 awards, ₱16,000
     * ========================================================== */

    'P21D-T6 the 20-member stopped tree pays 7 awards worth ₱16,000 at N = 20' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t6_root', null);

        /* 20 members in queue order (positions #1–#20); the awards depend
           only on position, not on the tree shape. */
        $users = [$tree];

        for ($i = 1; $i <= 19; $i++) {
            $users[] = $account('p21d_t6_m' . $i, $tree);
        }

        $n = $queue($users);
        assert_same(20, $n, 'N = 20');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(7, (int) $result['written'], '7 awards: node 0 L1+L2, nodes 1–5 L1');
        assert_same(16000.0, (float) $result['amount'], '₱16,000.00 total');

        /* node 0: L1 + L2 = ₱6,000 */
        assert_same([1, 2], GeneralHarvestAward::paidLevels((int) $tree), 'node 0 paid levels 1–2');

        /* nodes 1–5: level 1 each = ₱2,000 */
        foreach (array_slice($users, 1, 5) as $child) {
            assert_same([1], GeneralHarvestAward::paidLevels((int) $child), 'a level-1 child paid level 1 only');
        }

        /* node 6 needs N = 22 for level 1, so nothing at N = 20. */
        assert_same([], GeneralHarvestAward::paidLevels((int) $users[6]), 'node 6 is not complete yet');

        assert_same(16000.0, round((float) GeneralHarvestLevel::boardTotal(20), 2), 'the closed form agrees');
    },

    /* ==========================================================
     *  P21D-T7 — earnedBy() and boardTotal() match the pure table
     * ========================================================== */

    'P21D-T7 earnedBy and boardTotal match §16.5 to the peso' => function () use (
        $clean,
        $seed,
        $arm,
        $switch
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        /* boardTotal(4) = 2,000 · paid(1,4) = 2,000 · 1 = ₱2,000 */
        assert_same(2000.0, round((float) GeneralHarvestLevel::boardTotal(4), 2), 'boardTotal(4)');
        /* boardTotal(13) = 2,000·4 + 4,000·1 = ₱12,000 */
        assert_same(12000.0, round((float) GeneralHarvestLevel::boardTotal(13), 2), 'boardTotal(13)');
        /* boardTotal(40) = 2,000·13 + 4,000·4 + 8,000·1 = ₱50,000 */
        assert_same(50000.0, round((float) GeneralHarvestLevel::boardTotal(40), 2), 'boardTotal(40)');
        /* boardTotal(88,573) = ₱190,208,000 */
        assert_same(190208000.0, round((float) GeneralHarvestLevel::boardTotal(88573), 2), 'boardTotal(88,573)');

        /* earnedBy(0, 40) = 14,000 (levels 1–3) */
        assert_same(14000.0, round((float) GeneralHarvestLevel::earnedBy(0, 40), 2), 'earnedBy(0, 40)');
        /* earnedBy(1, 40) = 6,000 (levels 1–2) */
        assert_same(6000.0, round((float) GeneralHarvestLevel::earnedBy(1, 40), 2), 'earnedBy(1, 40)');
        /* earnedBy(13, 40) = 0 (level 1 needs N = 43) */
        assert_same(0.0, round((float) GeneralHarvestLevel::earnedBy(13, 40), 2), 'earnedBy(13, 40)');
    },

    /* ==========================================================
     *  P21D-T11 — Baseline fixture F: arm at N = 13, grow to N = 40
     * ========================================================== */

    'P21D-T11 baseline F: arm at N = 13, settle to N = 40 credits 13 blocks worth ₱38,000' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue,
        $append
    ): void {
        $clean();

        $seed();

        $tree = $account('p21d_t11_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($depth = 1; $depth <= 2; $depth++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t11_d' . $depth . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        /* N₀ = 13 from the h = 2 queue, then the arm. */
        $n13 = $queue($users);
        assert_same(13, $n13, 'N₀ = 13 at the arm');

        $arm(); /* baseline rows for levels 1–2 at N₀ = 13 */
        $switch(true);

        /* Grow to N = 40: 27 more members, appended after the arm. */
        for ($i = 0; $i < 27; $i++) {
            $users[] = $account('p21d_t11_extra_' . $i, $tree);
            $append((int) $users[array_key_last($users)]);
        }

        $n40 = (int) GeneralHarvestBoard::queueN()['n'];
        assert_same(40, $n40, 'N = 40 after growth');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(13, (int) $result['written'], '13 credited blocks');
        assert_same(38000.0, (float) $result['amount'], '₱38,000.00 credited');

        /* node 0 receives only L3 ₱8,000 (L1–L2 are baseline) */
        $node0 = GeneralHarvestAward::forMember((int) $tree);
        $credited = array_values(array_filter(
            $node0,
            static fn (array $row): bool => $row['status'] === 'credited'
        ));
        assert_count(1, $credited, 'node 0 has one credited row');
        assert_same(3, (int) $credited[0]['level'], 'only level 3 is credited for node 0');
        assert_same(8000.0, round((float) $credited[0]['amount'], 2), '₱8,000.00 for level 3');

        /* nodes 1–3: L2 credited = ₱4,000 each */
        foreach (array_slice($users, 1, 3) as $child) {
            $rows = GeneralHarvestAward::paidLevels((int) $child);
            assert_same([2], $rows, 'a child of the root paid level 2 only');
        }

        /* nodes 4–12: L1 credited = ₱2,000 each */
        foreach (array_slice($users, 4, 9) as $grandchild) {
            assert_same([1], GeneralHarvestAward::paidLevels((int) $grandchild), 'a grandchild paid level 1 only');
        }

        assert_same(38000.0, round((float) GeneralHarvestLevel::boardTotal(40) - GeneralHarvestLevel::boardTotal(13), 2), 'the closed-form difference agrees');
    },

    /* ==========================================================
     *  P21D-T12 — node 0 wallet holds exactly ₱8,000 in fixture F
     * ========================================================== */

    'P21D-T12 node 0 wallet holds exactly ₱8,000 in fixture F' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue,
        $append
    ): void {
        $clean();

        $seed();

        $tree = $account('p21d_t12_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($depth = 1; $depth <= 2; $depth++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t12_d' . $depth . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N₀ = 13 */
        assert_same(13, (int) GeneralHarvestBoard::queueN()['n'], 'N₀ = 13 at the arm');

        $arm(); /* baseline rows for levels 1–2 at N₀ = 13 */
        $switch(true);

        for ($i = 0; $i < 27; $i++) {
            $users[] = $account('p21d_t12_extra_' . $i, $tree);
            $append((int) $users[array_key_last($users)]);
        }

        assert_same(40, (int) GeneralHarvestBoard::queueN()['n'], 'N = 40 after growth');

        (new GeneralHarvestService())->settleAll();

        $balance = db()->prepare(
            'SELECT withdrawable_balance FROM users WHERE id = ? LIMIT 1'
        );
        $balance->execute([(int) $tree]);
        assert_same(8000.0, round((float) $balance->fetchColumn(), 2), 'exactly ₱8,000.00, never ₱14,000.00');
    },

    /* ==========================================================
     *  P21D-T13 — baseline_n = 40, settle credits nothing
     * ========================================================== */

    'P21D-T13 arm at N = 40 then settle credits nothing' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();

        $tree = $account('p21d_t13_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($depth = 1; $depth <= 3; $depth++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t13_d' . $depth . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $n = $queue($users);
        assert_same(40, $n, 'N = 40 before the arm');

        $arm(); /* N₀ = 40 */
        $switch(true);

        assert_same(40, (int) (new GeneralHarvestService())->settledN(), 'settled_n = N₀ = 40');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('replayed', (string) $result['state'], 'nothing new to settle');
        assert_same(0, (int) $result['written'], 'no new award');
        assert_same(0, (int) GeneralHarvestAward::count(['status' => 'credited']), 'no credited row anywhere');
    },

    /* ==========================================================
     *  P21D-T14 — a node queued after the arm credits normally
     * ========================================================== */

    'P21D-T14 a node queued after the arm credits its completed levels' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue,
        $append
    ): void {
        $clean();

        $seed();
        $arm(); /* N₀ = 13 */
        $switch(true);

        $tree = $account('p21d_t14_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($depth = 1; $depth <= 2; $depth++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t14_d' . $depth . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N₀ = 13: node 0's L1+L2 baselined */

        /* A new member queued after the arm at position #14 → seq 13.
           Its level 1 block needs N = 43, so nothing completes yet. */
        $late = $account('p21d_t14_late', $tree);
        $append($late);

        /* Keep appending until N = 43 so seq 13's level 1 block (positions
           #14–#16... actually start(13,1) = 3·13+1 = 40, positions #41–#43)
           completes at N = 43. */
        while ((int) GeneralHarvestBoard::queueN()['n'] < 43) {
            $append($account('p21d_t14_filler_' . uniqid(), $tree));
        }

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same([1], GeneralHarvestAward::paidLevels((int) $late), 'the late node credited level 1');
    },

    /* ==========================================================
     *  P21D-T15 — max_levels = 2 at N = 40: level 3 not paid
     * ========================================================== */

    'P21D-T15 max_levels = 2 at N = 40 pays 17 blocks worth ₱42,000' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $depth,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);
        $depth(2);

        $tree = $account('p21d_t15_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($depthx = 1; $depthx <= 3; $depthx++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t15_d' . $depthx . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $n = $queue($users);
        assert_same(40, $n, 'N = 40');

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(17, (int) $result['written'], '17 awards (levels 1–2 only)');
        assert_same(42000.0, (float) $result['amount'], '₱42,000.00');

        /* node 0 completed level 3 but it is not paid at this depth */
        assert_same([1, 2], GeneralHarvestAward::paidLevels((int) $tree), 'node 0 paid levels 1–2 only');
    },

    /* ==========================================================
     *  P21D-T16 — raise the depth to 3, then settle pays level 3 once
     * ========================================================== */

    'P21D-T16 raising depth to 3 pays node 0 level 3 once, ₱8,000' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $depth,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);
        $depth(2);

        $tree = $account('p21d_t16_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 3; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t16_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 40 */
        (new GeneralHarvestService())->settleAll(); /* levels 1–2 paid */

        $depth(3); /* raise the depth */
        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(1, (int) $result['written'], 'exactly one new award');
        assert_same(8000.0, (float) $result['amount'], '₱8,000.00');
        assert_same([1, 2, 3], GeneralHarvestAward::paidLevels((int) $tree), 'node 0 now paid levels 1–3');
    },

    /* ==========================================================
     *  P21D-T17 — level 5 at 0.00 is unpriced, then paid when restored
     * ========================================================== */

    'P21D-T17 an unpriced level writes nothing; restoring it pays once' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $ladder,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t17_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 5; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t17_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 364 */

        /* Level 5 hand-edited to 0.00 while armed. */
        $ladder([5 => 0.0]);

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_true(in_array(5, (array) $result['unpriced'], true), 'level 5 reported unpriced');

        $node0Credited = array_values(array_filter(
            GeneralHarvestAward::forMember((int) $tree),
            static fn (array $row): bool => $row['status'] === 'credited' && (int) $row['level'] === 5
        ));
        assert_count(0, $node0Credited, 'level 5 writes no row and no money');

        /* Restore level 5 to ₱25,000 and settle again: one row, ₱25,000. */
        $ladder([5 => 25000.0]);
        $result = (new GeneralHarvestService())->settleAll();

        assert_same(1, (int) $result['written'], 'one new award after restore');
        assert_same(25000.0, (float) $result['amount'], '₱25,000.00');
        assert_same([1, 2, 3, 4, 5], GeneralHarvestAward::paidLevels((int) $tree), 'node 0 paid levels 1–5');
    },

    /* ==========================================================
     *  P21D-T18 — stream off: settle returns off, nothing paid
     * ========================================================== */

    'P21D-T18 general_harvest_enabled = 0: settle returns off and pays nothing' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        /* switch stays off */
        $switch(false);

        $tree = $account('p21d_t18_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 3; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t18_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 40 */

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('off', (string) $result['state'], 'settle returns off');
        assert_same(0, (int) $result['written'] ?? 0, 'no award written');
        assert_same(0, (int) GeneralHarvestAward::count(['status' => 'credited']), 'no credited row anywhere');
    },

    /* ==========================================================
     *  P21D-T19 — turn it on, catch-up pays the backlog once
     * ========================================================== */

    'P21D-T19 enable then catch-up pays the backlog exactly once' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        /* switch off while the queue grows to N = 40 */
        $switch(false);

        $tree = $account('p21d_t19_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 3; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t19_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 40, backlog builds while off */

        $switch(true); /* turn it on */
        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the catch-up settled');
        assert_same(18, (int) $result['written'], 'the backlog pays once');
        assert_same(50000.0, (float) $result['amount'], '₱50,000.00');

        /* A second run pays nothing (idempotent). */
        $again = (new GeneralHarvestService())->settleAll();
        assert_same('replayed', (string) $again['state'], 'the second catch-up is a replay');
        assert_same(0, (int) $again['written'], 'nothing written the second time');
    },

    /* ==========================================================
     *  P21D-T20 — unarmed board: settle returns unarmed
     * ========================================================== */

    'P21D-T20 unarmed board: settle returns unarmed and pays nothing' => function () use (
        $clean,
        $seed,
        $switch,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        /* never arm: baseline_n IS NULL */
        $switch(true);

        $tree = $account('p21d_t20_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 2; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t20_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 13 */

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('unarmed', (string) $result['state'], 'settle returns unarmed');
        assert_same(0, (int) GeneralHarvestAward::count(['status' => 'credited']), 'no credited row');
    },

    /* ==========================================================
     *  P21D-T21 — a suspended node holder is still credited (D-14)
     * ========================================================== */

    'P21D-T21 a suspended node holder is still credited' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t21_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 2; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t21_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        /* Suspend the root (the node holder) — still credited (D-14). */
        db()->prepare("UPDATE users SET member_status = 'suspended' WHERE id = ?")->execute([(int) $tree]);

        $queue($users); /* N = 13 */

        $result = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $result['state'], 'the settlement ran');
        assert_same(5, (int) $result['written'], '5 awards even though the root is suspended');
        assert_same([1, 2], GeneralHarvestAward::paidLevels((int) $tree), 'the suspended holder is credited');
    },

    /* ==========================================================
     *  P21D-T23 — openLiability() equals boardTotal(N) − boardTotal(settled_n)
     * ========================================================== */

    'P21D-T23 openLiability equals the closed-form difference' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t23_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 3; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t23_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 40, settled_n = 0 */

        $liability = (new GeneralHarvestService())->openLiability();
        $expected = round(
            GeneralHarvestLevel::boardTotal(40) - GeneralHarvestLevel::boardTotal(0),
            2
        );

        assert_same($expected, (float) $liability['amount'], 'open liability equals boardTotal(N) − boardTotal(settled_n)');
        assert_same(40, (int) $liability['positions'], '40 positions in the backlog');
    },

    /* ==========================================================
     *  P21D-T24 — statusFor for a node
     * ========================================================== */

    'P21D-T24 statusFor reports exact levels, baseline state and paid total' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue,
        $append
    ): void {
        $clean();

        $seed();

        /* Build the h = 3 tree (40 members) but queue only the first 13 for the arm. */
        $tree = $account('p21d_t24_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 3; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t24_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $n13 = $queue(array_slice($users, 0, 13));
        assert_same(13, $n13, 'N₀ = 13 at the arm');

        $arm(); /* baseline N₀ = 13 */
        $switch(true);

        /* Append the remaining 27 members to reach N = 40. */
        for ($i = 13; $i < 40; $i++) {
            $append((int) $users[$i]);
        }

        assert_same(40, (int) GeneralHarvestBoard::queueN()['n'], 'N = 40 after growth');

        (new GeneralHarvestService())->settleAll();

        $status = (new GeneralHarvestService())->statusFor((int) $tree);

        assert_true((bool) $status['queued'], 'the node is queued');
        assert_same(1, (int) $status['position'], 'position #1');
        assert_same(0, (int) $status['seq'], 'seq 0');
        assert_same(40, (int) $status['queue_n'], 'N = 40');
        assert_same(13, (int) $status['baseline_n'], 'baseline N₀ = 13');
        assert_same(40, (int) $status['settled_n'], 'settled_n = N');
        assert_same(1, (int) $status['paid_levels'], 'node 0 paid level 3 only (L1–L2 are baseline)');
        assert_same(8000.0, (float) $status['credited_amount'], '₱8,000.00 credited (level 3 only)');

        $byLevel = [];

        foreach ($status['levels'] as $level) {
            $byLevel[(int) $level['level']] = $level;
        }

        /* L1–L2 are baseline, L3 is credited */
        assert_same('baseline', (string) $byLevel[1]['status'], 'level 1 is baseline (pre-go-live)');
        assert_same('baseline', (string) $byLevel[2]['status'], 'level 2 is baseline (pre-go-live)');
        assert_same('credited', (string) $byLevel[3]['status'], 'level 3 is credited');
        assert_same('progress', (string) $byLevel[4]['status'], 'level 4 is not complete (needs N = 121)');
    },

    /* ==========================================================
     *  P21D-T25 — changing an amount after arm is refused (D-23)
     * ========================================================== */

    'P21D-T25 changing an amount after arm is refused' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t25_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 3; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t25_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 40 */
        (new GeneralHarvestService())->settleAll();

        $before = GeneralHarvestLevel::amount(3);

        /* Attempt to change level 3 from ₱8,000 to ₱10,000 while armed. */
        $exception = assert_throws(
            static fn () => GeneralHarvestLevel::save(
                ['3' => 10000.0] + array_fill(1, 10, 0.0),
                null
            ),
            InvalidArgumentException::class,
            'an armed ladder save is refused'
        );

        $after = GeneralHarvestLevel::amount(3);
        assert_same($before, $after, 'the ladder is unchanged');
    },

    /* ==========================================================
     *  P21D-T26 — settle() twice on the same N is idempotent
     * ========================================================== */

    'P21D-T26 settle() twice on the same N writes nothing the second time' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t26_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 2; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t26_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 13 */

        $first  = (new GeneralHarvestService())->settleAll();
        $second = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $first['state'], 'the first settlement ran');
        assert_same(5, (int) $first['written'], '5 awards on the first run');
        assert_same('replayed', (string) $second['state'], 'the second run is a replay');
        assert_same(0, (int) $second['written'], 'nothing written the second time');
        assert_same(5, (int) GeneralHarvestAward::count(['status' => 'credited']), 'still 5 credited rows');
    },

    /* ==========================================================
     *  P21D-T29 — settle(1) repeated 27 times equals settle(27)
     * ========================================================== */

    'P21D-T29 settle(1) repeated equals one settle(27)' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue,
        $append
    ): void {
        $clean();

        $seed();

        /* Build the h = 3 tree (40 qualified members) and queue the first 13. */
        $tree = $account('p21d_t29_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 3; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t29_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $n13 = $queue(array_slice($users, 0, 13));
        assert_same(13, $n13, 'N₀ = 13 at the arm');

        $arm(); /* settled_n = 13 */
        $switch(true);

        /* Append the remaining 27 members one position at a time. */
        for ($i = 13; $i < 40; $i++) {
            $append((int) $users[$i]);
        }

        assert_same(40, (int) GeneralHarvestBoard::queueN()['n'], 'N = 40 after growth');

        /* settle(1) 27 times, one position per call. */
        for ($i = 0; $i < 27; $i++) {
            $result = (new GeneralHarvestService())->settle(1);
            assert_same('settled', (string) $result['state'], 'intermediate settlement ' . ($i + 1));
        }

        $ledgerA = GeneralHarvestAward::countByLevel();
        $settledNA = (int) (new GeneralHarvestService())->settledN();

        assert_same(40, $settledNA, 'settled_n reached N = 40');
        assert_same(18, array_sum($ledgerA), '18 awards total after 27 incremental settlements');
        assert_same(50000.0, round((float) GeneralHarvestLevel::boardTotal(40) - GeneralHarvestLevel::boardTotal(0), 2), 'the closed form matches');
    },

    /* ==========================================================
     *  P21D-T30 — sequential settlements of one node write once
     * ========================================================== */

    'P21D-T30 three sequential settlements write one set of rows' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t30_root', null);

        $users = [$tree];
        $parents = [$tree];

        for ($d = 1; $d <= 2; $d++) {
            $next = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account('p21d_t30_d' . $d . '_p' . $parent . '_' . $i, $parent);
                    $users[] = $child;
                    $next[] = $child;
                }
            }

            $parents = $next;
        }

        $queue($users); /* N = 13 */

        $r1 = (new GeneralHarvestService())->settleAll();
        $r2 = (new GeneralHarvestService())->settleAll();
        $r3 = (new GeneralHarvestService())->settleAll();

        assert_same('settled', (string) $r1['state'], 'first settlement');
        assert_same('replayed', (string) $r2['state'], 'second settlement is a replay');
        assert_same('replayed', (string) $r3['state'], 'third settlement is a replay');
        assert_same(5, (int) GeneralHarvestAward::count(['status' => 'credited']), 'one set of rows');
    },

    /* ==========================================================
     *  P21D-T22 — a staff account as the node holder is not paid
     * ========================================================== */

    'P21D-T22 a staff node holder is not a member and is not paid' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue,
        $append
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        /* A staff (admin) account cannot be queued by Phase 20, but we
           place it at node 1 (seq 1) directly to prove the engine refuses it. */
        $staff = (int) db()->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();

        $tree = $account('p21d_t22_root', null);

        /* seq 0 = member root, seq 1 = staff, then member fillers. */
        $users = [$tree, $staff];

        for ($i = 1; $i <= 3; $i++) {
            $users[] = $account('p21d_t22_n1_' . $i, $tree);
        }

        $n = $queue(array_slice($users, 0, 4)); /* positions #1–#4, N = 4 */

        /* Grow to N = 7 so staff's level-1 block (seq 1, positions #5–#7)
           completes at seq 2, 3, 4 — the three member children. */
        for ($i = 4; $i < 7; $i++) {
            $users[] = $account('p21d_t22_filler_' . $i, $tree);
            $append((int) $users[array_key_last($users)]);
        }

        assert_same(7, (int) GeneralHarvestBoard::queueN()['n'], 'N = 7');

        /* Node 0 completes L1 at N = 4 and is paid; staff at seq 1 completes
           L1 at N = 7 but is not a member, so the engine throws. */
        $exception = assert_throws(
            static fn () => (new GeneralHarvestService())->settleAll(),
            GeneralHarvestException::class,
            'settling a staff node throws not a member'
        );

        assert_same('not a member', $exception->reason, 'the anomaly is not a member');
        assert_same(0, (int) GeneralHarvestAward::count(['status' => 'credited']), 'nothing was credited');
    },

    /* ==========================================================
     *  P21D-T27 — onQueueChanged() called twice for one registration
     * ========================================================== */

    'P21D-T27 onQueueChanged called twice returns replayed the second time' => function () use (
        $clean,
        $seed,
        $switch,
        $arm,
        $account,
        $queue,
        $append
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $tree = $account('p21d_t27_root', null);
        $users = [$tree];

        for ($i = 1; $i <= 3; $i++) {
            $users[] = $account('p21d_t27_n1_' . $i, $tree);
        }

        /* Queue the first 3 members (N = 3, no block completes yet) and
           settle so settled_n = 3. */
        $n3 = $queue(array_slice($users, 0, 3));
        assert_same(3, $n3, 'N = 3 before the triggering registration');
        (new GeneralHarvestService())->settleAll();

        /* The 4th member completes node 0's level-1 block at N = 4. This is
           the single registration whose hook runs twice. */
        $append((int) $users[3]);

        $service = new GeneralHarvestService();

        $first  = $service->onQueueChanged();
        $second = $service->onQueueChanged();

        assert_same('settled', (string) $first['state'], 'the first hook settled position 4');
        assert_same(1, (int) $first['written'], 'one award written for node 0 level 1');
        assert_same('replayed', (string) $second['state'], 'the second hook is a replay');
        assert_same(0, (int) $second['written'], 'nothing written the second time');
        assert_same(1, (int) GeneralHarvestAward::count(['status' => 'credited']), 'one set of rows');
        assert_same([1], GeneralHarvestAward::paidLevels((int) $tree), 'node 0 paid level 1');
    },

];
