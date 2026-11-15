<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestEngineTest — Phase 20D, the money engine (plan §8)
 *
 *  Proves the award rule on the plan's own fixtures, through the real
 *  path: `TeamHarvestService::onQualified()` — the exact call
 *  `RegistrationService` makes — and `evaluate()` / `evaluateAll()`.
 *  Every fixture is built by `tests/fixtures/harvest_money.php`, so the
 *  three 20D files share one definition of tree A and tree B.
 *
 *      awards        the ladder, the all-or-nothing blocks, the network
 *                    totals and the wallet movement (T1–T10)
 *      idempotency   a replay, a re-qualification and the unique anchor
 *                    (T11–T13, T34)
 *      baseline      complete at go-live = recorded, never paid
 *                    (T14–T17, T19)
 *      depth         max_levels caps the pay; raising it pays once
 *                    (T22, T23)
 *      snapshot      a ladder edit never rewrites history (T24)
 *      eligibility   a suspended member still earns and still cannot
 *                    withdraw; a staff account is never paid (T25, T26)
 *      status        the card payload and the next-level arithmetic
 *                    (T32)
 *      ledger        filters, pages, totals, breakdowns (T33)
 *      switches      a zero amount is a switch (T35)
 *      invariants    the exit-gate sweep (§13.8) (T36)
 *
 *  Money is asserted to the centavo against §16.3, never "roughly".
 *
 *  Run this group alone with:  php tests/run.php TeamHarvestEngine
 * ============================================================== */

$fixtures = require __DIR__ . '/../fixtures/harvest_money.php';

$clean           = $fixtures['clean'];
$arm             = $fixtures['arm'];
$disarm          = $fixtures['disarm'];
$enable          = $fixtures['enable'];
$ladder          = $fixtures['ladder'];
$depth           = $fixtures['depth'];
$grow            = $fixtures['grow'];
$growPastBaseline = $fixtures['growPastBaseline'];
$account         = $fixtures['account'];
$awards          = $fixtures['awards'];
$credits         = $fixtures['credits'];
$withdrawable    = $fixtures['withdrawable'];
$balance         = $fixtures['balance'];
$count           = $fixtures['count'];
$firstPosition   = $fixtures['firstPosition'];

/** The credited rows only, level order. */
$credited = static fn (int $userId): array => array_values(array_filter(
    $awards($userId),
    static fn (array $row): bool => (string) $row['status'] === 'credited'
));

/** The network's credited money, as float. */
$networkTotal = static function (): float {
    return round((float) db()->query(
        "SELECT COALESCE(SUM(amount), 0) FROM team_harvest_awards WHERE status = 'credited'"
    )->fetchColumn(), 2);
};

return [

    /* ==========================================================
     *  Awards (T1–T10)
     * ========================================================== */

    'P20D-T1 a full level-1 block pays 1,000 once at n = 3' => function () use ($clean, $arm, $grow, $awards, $credits, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't1', $service);

        /* A depth-2 member holds exactly three qualified teammates: its
         * own three children, who qualified when they held three directs. */
        $member = $tree['levels'][2][0];
        $rows   = $awards($member);

        assert_count(1, $rows, 'level 1 only');
        assert_same(1, $rows[0]['level']);
        assert_same(3, $rows[0]['threshold_n'], 'the threshold in force when the row was written');
        assert_same(3, $rows[0]['n_at_award'], 'the counter at that instant');
        assert_same(1000.0, $rows[0]['amount']);
        assert_same('credited', $rows[0]['status']);
        assert_same(1000.0, $withdrawable($member));

        $ledger = $credits($member);
        assert_count(1, $ledger, 'one wallet movement per award');
        assert_same('team_harvest', $ledger[0]['ref_type']);
        assert_same((int) $rows[0]['id'], (int) $ledger[0]['ref_id'], 'ref_id is the award id');
        assert_same('1000.00', number_format((float) $ledger[0]['amount'], 2, '.', ''));
    },

    'P20D-T2 fixture A root completes levels 1-3 for 7,000' => function () use ($clean, $arm, $grow, $awards, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't2', $service);
        $root    = $tree['root'];

        $rows = $awards($root);

        assert_count(3, $rows);
        assert_same([1, 2, 3], array_column($rows, 'level'));
        assert_same([1000.0, 2000.0, 4000.0], array_column($rows, 'amount'));
        assert_same([3, 12, 39], array_column($rows, 'n_at_award'), 'the awards land exactly at the thresholds');
        assert_same([3, 12, 39], array_column($rows, 'threshold_n'));
        assert_same(7000.0, $withdrawable($root), '1,000 + 2,000 + 4,000');
    },

    'P20D-T3 fixture A children complete levels 1-2 for 3,000 each' => function () use ($clean, $arm, $grow, $awards, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't3', $service);

        foreach ($tree['levels'][1] as $child) {
            $rows = $awards($child);

            assert_count(2, $rows, 'levels 1 and 2');
            assert_same([1, 2], array_column($rows, 'level'));
            assert_same([3, 12], array_column($rows, 'n_at_award'), 'level 1 at three, level 2 at twelve');
            assert_same(3000.0, $withdrawable($child));
        }
    },

    'P20D-T4 fixture A grandchildren earn level 1 only, never a partial level 2' => function () use ($clean, $arm, $grow, $awards, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't4', $service);

        foreach ($tree['levels'][2] as $grandchild) {
            $rows = $awards($grandchild);

            assert_count(1, $rows, 'nothing deeper than level 1 is complete at n = 3');
            assert_same(1, $rows[0]['level']);
            assert_same(1000.0, $withdrawable($grandchild), 'a partial level-2 block pays nothing');
        }
    },

    'P20D-T5 a member with no teammates earns nothing and is not an error' => function () use ($clean, $arm, $grow, $awards, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't5', $service);

        $member = $tree['levels'][3][0];

        assert_same(0, (new HarvestQueue())->teammatesOf($member));
        assert_same([], $awards($member));
        assert_same(0.0, $withdrawable($member));

        $result = $service->evaluate([$member]);

        assert_same(false, $result['off']);
        assert_same(0, $result['paid']);
        assert_same(0, $result['written']);
        assert_same([], $awards($member), 'a member below the gate writes no row');
    },

    'P20D-T6 fixture B root completes levels 1-4 for 13,000' => function () use ($clean, $arm, $grow, $awards, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(4, 't6', $service);

        assert_same(364, $tree['accounts'], 'fixture B: 121 qualified + 243 leaf directs');

        $rows = $awards($tree['root']);

        assert_count(4, $rows);
        assert_same([1, 2, 3, 4], array_column($rows, 'level'));
        assert_same([1000.0, 2000.0, 4000.0, 6000.0], array_column($rows, 'amount'));
        assert_same([3, 12, 39, 120], array_column($rows, 'n_at_award'));
        assert_same(13000.0, $withdrawable($tree['root']));
    },

    'P20D-T7 fixture A pays 25,000 across 13 members in 18 rows' => function () use ($clean, $arm, $grow, $count, $networkTotal): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $grow(3, 't7', $service);

        $row = db()->query(
            "SELECT COUNT(*) AS rows_,
                    COALESCE(SUM(amount), 0) AS amount,
                    COUNT(DISTINCT user_id) AS members
               FROM team_harvest_awards
              WHERE status = 'credited'"
        )->fetch();

        assert_same(18, (int) $row['rows_'], '1 x 3 + 3 x 2 + 9 x 1');
        assert_same(25000.0, round((float) $row['amount'], 2), '7,000 + 3 x 3,000 + 9 x 1,000');
        assert_same(13, (int) $row['members'], '27 qualified members earn nothing');

        assert_same(0, $count('team_harvest_awards', "status = 'baseline'"), 'a live tree has no baseline rows');

        /* The level distribution is the ladder's shape. */
        $byLevel = TeamHarvestAward::byLevel();
        assert_same(13, $byLevel[1]['credited']);
        assert_same(4, $byLevel[2]['credited']);
        assert_same(1, $byLevel[3]['credited']);
        assert_same(13000.0, $byLevel[1]['credited_total']);
        assert_same(8000.0, $byLevel[2]['credited_total']);
        assert_same(4000.0, $byLevel[3]['credited_total']);
        assert_same(25000.0, $networkTotal());
    },

    'P20D-T8 earnedFor is the pure per-member ladder function' => function (): void {
        /* §16.5's table: the deep levels are proved on the pure function,
         * because building an 88,572-member tree is not a test. */
        $table = [
            2      => 0.0,
            3      => 1000.0,
            12     => 3000.0,
            39     => 7000.0,
            120    => 13000.0,
            362    => 13000.0,
            363    => 25000.0,
            1091   => 25000.0,
            1092   => 75000.0,
            3278   => 75000.0,
            3279   => 195000.0,
            9839   => 195000.0,
            9840   => 395000.0,
            29522  => 395000.0,
            29523  => 1595000.0,
            88571  => 1595000.0,
            88572  => 5095000.0,
        ];

        foreach ($table as $n => $expected) {
            assert_same($expected, TeamHarvestLevel::earnedFor($n), 'earnedFor(' . $n . ')');
        }

        assert_same(5095000.0, TeamHarvestLevel::total(), 'the full reference ladder');
        assert_same(3000.0, TeamHarvestLevel::earnedFor(39, null, 2), 'the depth cap applies to the pure function');

        /* The next-level payload (P20F-T2's exact numbers). */
        $cases = [
            1  => [1, 3, 1, 3, 2, 33.3],
            5  => [2, 12, 2, 9, 7, 22.2],
            20 => [3, 39, 8, 27, 19, 29.6],
            50 => [4, 120, 11, 81, 70, 13.6],
        ];

        foreach ($cases as $n => [$level, $needed, $filled, $slots, $remaining, $percent]) {
            $next = TeamHarvestLevel::statusForNext($n);

            assert_same($level, $next['level'], 'next level at n = ' . $n);
            assert_same($needed, $next['needed']);
            assert_same($filled, $next['filled']);
            assert_same($slots, $next['slots']);
            assert_same($remaining, $next['remaining']);
            assert_same($percent, $next['percent']);
        }
    },

    'P20D-T9 the wallet statement shows one Team Harvest credit per award' => function () use ($clean, $arm, $grow, $awards, $withdrawable, $balance): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't9', $service);
        $root    = $tree['root'];

        $wallet    = new WalletService();
        $statement = $wallet->statement($root, ['ref_type' => 'team_harvest'], 1, 25);

        $rows = $awards($root);
        assert_count(2, $rows);
        assert_count(2, $statement['rows'], 'the statement filter matches both movements');
        assert_same(3000.0, $withdrawable($root), '1,000 + 2,000');
        assert_same(3000.0, $balance($root), 'the credit raises both buckets');

        foreach ($statement['rows'] as $movement) {
            assert_same('credit', $movement['type']);
            assert_same('team_harvest', $movement['ref_type']);
            assert_contains('Team Harvest level', (string) $movement['note']);
        }

        $ids = array_map('intval', array_column($statement['rows'], 'ref_id'));
        sort($ids);

        assert_same(array_column($rows, 'id'), $ids, 'each movement points at its award');
    },

    'P20D-T10 every credited award carries its ledger reference and instants' => function () use ($clean, $arm, $grow): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $grow(2, 't10', $service);

        $rows = db()->query(
            "SELECT a.id, a.user_id, a.level, a.amount, a.status, a.ledger_id, a.credited_at, a.created_at
               FROM team_harvest_awards a
               ORDER BY a.id"
        )->fetchAll();

        assert_true($rows !== []);

        foreach ($rows as $row) {
            assert_same('credited', (string) $row['status']);
            assert_not_null($row['ledger_id'], 'a credited award always names its movement');
            assert_not_same('', (string) $row['credited_at']);
            assert_not_same('', (string) $row['created_at']);

            $ledger = db()->prepare('SELECT ref_type, ref_id, amount FROM ewallet_ledger WHERE id = ? LIMIT 1');
            $ledger->execute([(int) $row['ledger_id']]);
            $movement = $ledger->fetch();

            assert_not_same(false, $movement, 'the ledger row exists');
            assert_same('team_harvest', (string) $movement['ref_type']);
            assert_same((int) $row['id'], (int) $movement['ref_id']);
            assert_same(
                number_format((float) $row['amount'], 2, '.', ''),
                number_format((float) $movement['amount'], 2, '.', '')
            );
        }
    },

    /* ==========================================================
     *  Idempotency (T11–T13, T34)
     * ========================================================== */

    'P20D-T11 a second evaluation of the same member writes nothing' => function () use ($clean, $arm, $grow, $awards, $withdrawable, $enable): void {
        $clean();
        /* Grow with the switch off, so the levels are complete and unpaid
         * when the first evaluation runs. */
        $arm('2026-11-01 00:00:00', null, false);
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't11', $service);
        $root    = $tree['root'];

        assert_same(0, count($awards($root)), 'the stream was off: nothing paid');

        $enable(true);

        $first = $service->evaluate([$root]);

        assert_same(false, $first['off']);
        assert_same(2, $first['paid'], 'levels 1 and 2 are complete and above the baseline');
        assert_same(3000.0, $first['amount']);
        assert_same(3000.0, $withdrawable($root));

        $before = $awards($root);
        $second = $service->evaluate([$root]);

        assert_same(0, $second['paid']);
        assert_same(0, $second['written']);
        assert_same(0.0, $second['amount']);
        assert_same($before, $awards($root), 'the rows are unchanged');
        assert_same(3000.0, $withdrawable($root), 'the balance is unchanged');
        assert_count(2, $before);
    },

    'P20D-T12 a replayed qualification writes nothing and reports replayed' => function () use ($clean, $arm, $account, $count): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $sponsor = $account('t12_sponsor');

        foreach (['t12_d1', 't12_d2'] as $name) {
            $account($name, $sponsor);
            assert_same(null, $service->onQualified($sponsor)['position'] ?? null, 'below the gate returns no position');
        }

        $account('t12_d3', $sponsor);
        $first = $service->onQualified($sponsor);

        assert_same(true, $first['qualified']);
        assert_not_null($first['position']);
        assert_same(false, $first['replayed']);
        assert_same(1, $count('harvest_queue'));

        $second = $service->onQualified($sponsor);

        assert_same(false, $second['qualified']);
        assert_same(true, $second['replayed'], 'the replayed call says so');
        assert_same(null, $second['position']);
        assert_same(0, (int) $second['written']);
        assert_same(1, $count('harvest_queue'), 'still one position');
        assert_same(0, $count('team_harvest_awards'), 'and no award for a member with n = 0');
    },

    'P20D-T13 a duplicate (member, level) insert is refused by the unique anchor' => function () use ($clean, $arm, $grow, $awards): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't13', $service);
        $root    = $tree['root'];
        $row     = $awards($root)[0];

        $error = assert_throws(
            static fn () => TeamHarvestAward::create($root, 1, [
                'threshold_n' => 3,
                'n_at_award'  => 3,
                'amount'      => '1000.00',
                'status'      => 'credited',
                'credited_at' => date('Y-m-d H:i:s'),
            ]),
            PDOException::class
        );

        assert_same('23000', (string) $error->getCode(), 'the unique anchor raises an integrity violation');
        assert_same(1000.0, $row['amount'], 'the original row is untouched');
        assert_count(2, $awards($root), 'no third row was written');
    },

    /* ==========================================================
     *  The baseline (T14–T17, T19)
     * ========================================================== */

    'P20D-T14 levels complete at the baseline are recorded, never paid' => function () use ($clean, $disarm, $grow, $growPastBaseline, $enable, $awards, $withdrawable): void {
        $clean();
        $disarm();

        /* The tree that exists at go-live: root + 3 + 9 qualified (the
         * root holds n = 12), reconstructed by the real seed. */
        $tree = $grow(2, 't14', null);
        $seed = (new HarvestQueue())->seed();

        assert_true((bool) $seed['ok']);
        assert_same(13, (int) $seed['inserted']);

        $enable(true);
        $service = new TeamHarvestService();

        assert_same(12, (new HarvestQueue())->teammatesOf($tree['root']));
        assert_same(12, (new HarvestQueue())->baselineN($tree['root']), 'the seed fixed the baseline');

        /* Grow the same root from 12 to 39 after go-live. */
        $growPastBaseline($tree, 't14', $service);

        assert_same(39, (new HarvestQueue())->teammatesOf($tree['root']));

        $rows = $awards($tree['root']);

        assert_count(3, $rows);
        assert_same([1, 2, 3], array_column($rows, 'level'));
        assert_same(['baseline', 'baseline', 'credited'], array_column($rows, 'status'));
        assert_same([1000.0, 2000.0, 4000.0], array_column($rows, 'amount'), 'a baseline row still records its amount');
        assert_null($rows[0]['ledger_id'], 'a baseline row is never paid');
        assert_null($rows[1]['ledger_id']);
        assert_not_null($rows[2]['ledger_id'], 'level 3 completed after go-live');
        assert_same(4000.0, $withdrawable($tree['root']), 'the wallet holds the post-baseline level only');
    },

    'P20D-T15 the baseline member is paid 4,000, never 7,000' => function () use ($clean, $disarm, $grow, $growPastBaseline, $enable, $credits, $withdrawable, $balance): void {
        $clean();
        $disarm();
        $tree = $grow(2, 't15', null);
        assert_true((bool) (new HarvestQueue())->seed()['ok']);

        $enable(true);
        $service = new TeamHarvestService();
        $growPastBaseline($tree, 't15', $service);

        assert_same(4000.0, $withdrawable($tree['root']));
        assert_same(4000.0, $balance($tree['root']));

        $ledger = $credits($tree['root']);
        assert_count(1, $ledger, 'one credit: level 3 only');
        assert_same('4000.00', number_format((float) $ledger[0]['amount'], 2, '.', ''));
    },

    'P20D-T16 a member at the baseline of 39 has levels 1-3 baselined and pays nothing' => function () use ($clean, $disarm, $grow, $enable, $awards, $withdrawable, $balance): void {
        $clean();
        $disarm();
        $tree = $grow(3, 't16', null);
        assert_true((bool) (new HarvestQueue())->seed()['ok']);

        $enable(true);
        $service = new TeamHarvestService();

        assert_same(39, (new HarvestQueue())->baselineN($tree['root']));

        $result = $service->evaluate([$tree['root']]);

        assert_same(0, $result['paid']);
        assert_same(3, $result['baseline'], 'three levels complete at the baseline');
        assert_same(0.0, $result['amount']);

        $rows = $awards($tree['root']);
        assert_count(3, $rows);
        assert_same(['baseline', 'baseline', 'baseline'], array_column($rows, 'status'));
        assert_same([null, null, null], array_column($rows, 'ledger_id'));
        assert_same(0.0, $withdrawable($tree['root']));
        assert_same(0.0, $balance($tree['root']));

        $status = $service->statusFor($tree['root']);

        assert_same('baseline', $status['levels'][1]['status']);
        assert_same('Before go-live', $status['levels'][1]['label']);
        assert_same('baseline', $status['levels'][3]['status']);
        assert_same('0.00', $status['paid_total']);
        assert_same('7000.00', $status['baseline_total']);
        assert_same('0.00', $status['open'], 'a baselined level is never payable');
    },

    'P20D-T17 a member who qualified after the seed has no baseline and credits normally' => function () use ($clean, $arm, $grow, $awards, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't17', $service);

        $row = (new HarvestQueue())->row($tree['root']);

        assert_not_null($row);
        assert_same(0, $row['baseline_n'], 'queued after go-live: no baseline');
        assert_same('registration', $row['source']);

        $levels = array_column($awards($tree['root']), 'status');
        assert_same(['credited', 'credited'], $levels);
        assert_same(3000.0, $withdrawable($tree['root']));
    },

    'P20D-T19 the stream off writes no award and the queue still grows' => function () use ($clean, $arm, $grow, $awards, $withdrawable, $count): void {
        $clean();
        $arm('2026-11-01 00:00:00', null, false);
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't19', $service);

        assert_same(13, $count('harvest_queue'), 'the queue still records positions');
        assert_same(0, $count('team_harvest_awards'), 'no award is written while the switch is off');
        assert_same(0.0, $withdrawable($tree['root']));
        assert_same([], $awards($tree['root']));
        assert_same(12, (new HarvestQueue())->teammatesOf($tree['root']), 'the counter grew anyway');

        $result = $service->evaluate([$tree['root']]);

        assert_same(true, $result['off'], 'the evaluation reports the switch, not an error');
        assert_same(0, $result['paid']);
        assert_same(0, $count('team_harvest_awards'));
        assert_same(0, $count('ewallet_ledger', "ref_type = 'team_harvest'"));
    },

    /* ==========================================================
     *  Depth and the ladder snapshot (T22–T24)
     * ========================================================== */

    'P20D-T22 max_levels caps the pay and marks the deeper level beyond the depth' => function () use ($clean, $arm, $depth, $grow, $awards, $withdrawable): void {
        $clean();
        $depth(2);
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't22', $service);
        $root    = $tree['root'];

        $rows = $awards($root);

        assert_count(2, $rows, 'levels 1-2 only');
        assert_same(3000.0, $withdrawable($root));

        $status = $service->statusFor($root);

        assert_same(true, $status['levels'][3]['complete'], 'level 3 IS complete at n = 39');
        assert_same(false, $status['levels'][3]['paid']);
        assert_same('beyond_depth', $status['levels'][3]['status']);
        assert_same('Complete — beyond the configured depth', $status['levels'][3]['label']);
        assert_same('0.00', $status['open'], 'a level beyond the depth is not liability');
        assert_same(4000.0, $status['levels'][3]['amount'], 'the amount is kept, not deleted');
    },

    'P20D-T23 raising the depth pays the level once and never re-pays the earlier ones' => function () use ($clean, $arm, $depth, $grow, $awards, $withdrawable): void {
        $clean();
        $depth(2);
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't23', $service);
        $root    = $tree['root'];

        assert_same(3000.0, $withdrawable($root));

        $depth(3);

        $result = $service->evaluate([$root]);

        assert_same(1, $result['paid']);
        assert_same(4000.0, $result['amount']);
        assert_same(7000.0, $withdrawable($root));

        $rows = $awards($root);
        assert_count(3, $rows);
        assert_same([1, 2, 3], array_column($rows, 'level'));

        $again = $service->evaluate([$root]);
        assert_same(0, $again['paid'], 'levels 1-3 do not re-pay');
        assert_same(7000.0, $withdrawable($root));

        /* Lowering the depth afterwards never invalidates a paid row: the
         * depth caps what may be written, not what was written. The
         * reconcile warns and stays clean. */
        $depth(2);

        $report = $service->reconcile();

        assert_same(true, $report['ok'], 'a lowered depth must not turn the books red');
        assert_same(3, count($awards($root)), 'the level-3 row stays');

        $kinds = array_column($report['warnings'], 'kind');
        assert_true(in_array('above_depth', $kinds, true), 'and the warning names the historical rows');
    },

    'P20D-T24 a ladder edit never rewrites a paid award but prices new ones' => function () use ($clean, $arm, $ladder, $grow, $account, $awards, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't24', $service);
        $root    = $tree['root'];

        assert_same(3000.0, $withdrawable($root), 'fixture A root paid at the reference amounts');

        $ladder([1 => 1500.0]);

        $rows = $awards($root);
        assert_same(1000.0, $rows[0]['amount'], 'the paid row keeps its snapshot');
        assert_same(3000.0, $withdrawable($root), 'and its wallet credit');

        /* A member who completes level 1 after the edit is paid the new
         * amount. The three directs must qualify **after** the sponsor:
         * the sponsor is queued first, then each direct earns its own
         * three (the "qualified after me" rule, D-17). */
        $sponsor = $account('t24_sponsor');
        $directs = [];

        foreach (['t24_d1', 't24_d2', 't24_d3'] as $name) {
            $directs[] = $account($name, $sponsor);
            $service->onQualified($sponsor);
        }

        assert_same(0, (new HarvestQueue())->teammatesOf($sponsor), 'the directs are not qualified yet');

        foreach ($directs as $index => $direct) {
            for ($i = 1; $i <= 3; $i++) {
                $account('t24_d' . $index . '_c' . $i, $direct);
                $service->onQualified($direct);
            }
        }

        $newRows = array_values(array_filter(
            $awards($sponsor),
            static fn (array $row): bool => (int) $row['level'] === 1
        ));

        assert_count(1, $newRows);
        assert_same(1500.0, $newRows[0]['amount'], 'a new award pays the configured amount');
        assert_same(1500.0, $withdrawable($sponsor));
    },

    /* ==========================================================
     *  Eligibility (T25, T26)
     * ========================================================== */

    'P20D-T25 a suspended member still earns and still cannot withdraw' => function () use ($clean, $arm, $account, $awards, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $sponsor = $account('t25_sponsor');

        $directs = [];

        foreach (['t25_d1', 't25_d2', 't25_d3'] as $name) {
            $directs[] = $account($name, $sponsor);
            $service->onQualified($sponsor);
        }

        /* The account is suspended AFTER it qualified: D-13 says the
         * award is structural and is still paid. */
        Member::setStatus($sponsor, 'suspended', null, 'P20D-T25');

        foreach ($directs as $index => $direct) {
            for ($i = 1; $i <= 3; $i++) {
                $account('t25_c' . $index . '_' . $i, $direct);
                $service->onQualified($direct);
            }
        }

        assert_same(3, (new HarvestQueue())->teammatesOf($sponsor));

        $rows = $awards($sponsor);
        assert_count(1, $rows);
        assert_same('credited', $rows[0]['status']);
        assert_same(1000.0, $withdrawable($sponsor), 'the money is credited regardless of status');

        /* The withdrawal path is the existing payout rule and still
         * refuses a suspended account (is_active = 0). */
        $error = assert_throws(
            static fn () => (new PayoutService())->request($sponsor, 'gcash', '09171234567', 600.00),
            PayoutException::class
        );

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM payout_requests')->fetchColumn());
        assert_same(1000.0, $withdrawable($sponsor), 'the refused request moved no money');
    },

    'P20D-T26 a staff account is never queued and can never be paid' => function () use ($clean, $arm, $account, $count): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $queue   = new HarvestQueue();
        $staff   = $account('t26_admin', null, 'admin');

        for ($i = 1; $i <= 4; $i++) {
            $account('t26_d' . $i, $staff);
            assert_same(null, $queue->qualifySponsor($staff), 'a staff account is never queued');
        }

        assert_same(4, $queue->directs($staff), 'four member directs, and it still never qualifies');
        assert_same(0, $count('harvest_queue'));

        $error = assert_throws(
            static fn () => $service->evaluate([$staff]),
            TeamHarvestException::class
        );

        assert_same('not_member', $error->reason);
        assert_same(0, $count('team_harvest_awards'));
        assert_same(0, $count('ewallet_ledger', "ref_type = 'team_harvest'"));
    },

    /* ==========================================================
     *  The status payload (T32)
     * ========================================================== */

    'P20D-T32 statusFor is exact for n = 0, 3, 12 and 39' => function () use ($clean, $arm, $grow, $withdrawable): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't32', $service);

        $root       = $tree['root'];
        $child      = $tree['levels'][1][0];
        $grandchild = $tree['levels'][2][0];
        $leafy      = $tree['levels'][3][0];

        $cases = [
            [$root,       39, 4, 120, 0, 81, 0.0,  '7000.00'],
            [$child,      12, 3, 39,  0, 27, 0.0,  '3000.00'],
            [$grandchild, 3,  2, 12,  0, 9,  0.0,  '1000.00'],
            [$leafy,      0,  1, 3,   0, 3,  0.0,  '0.00'],
        ];

        foreach ($cases as [$member, $n, $level, $needed, $filled, $remaining, $percent, $paidTotal]) {
            $status = $service->statusFor($member);

            assert_same(true, $status['queued']);
            assert_same($n, $status['teammates'], 'n for the member');
            assert_same($level, $status['next']['level']);
            assert_same($needed, $status['next']['needed']);
            assert_same($filled, $status['next']['filled']);
            assert_same($remaining, $status['next']['remaining']);
            assert_same($percent, $status['next']['percent']);
            assert_same($paidTotal, $status['paid_total']);
            assert_same($n, $status['matrix']['positions'], 'the matrix has n filled slots');
        }

        /* The paid levels are labelled and counted. */
        $rootStatus = $service->statusFor($root);
        assert_same(3, $rootStatus['credited_levels']);
        assert_same('paid', $rootStatus['levels'][1]['status']);
        assert_same('Paid', $rootStatus['levels'][1]['label']);
        assert_same(3, $rootStatus['levels'][1]['filled']);
        assert_same(3, $rootStatus['levels'][1]['slots']);
        assert_same(100.0, $rootStatus['levels'][1]['progress']);
        assert_same('in_progress', $rootStatus['levels'][4]['status']);
        assert_same(0, $rootStatus['levels'][4]['filled']);
        assert_same(6000.0, $rootStatus['levels'][4]['amount']);

        /* An unqualified member has no position and no matrix. */
        $stranger = db()->query("SELECT id FROM users WHERE role = 'member' AND id NOT IN (SELECT user_id FROM harvest_queue) LIMIT 1")->fetchColumn();
        assert_not_same(false, $stranger);

        $status = $service->statusFor((int) $stranger);
        assert_same(false, $status['queued']);
        assert_same(null, $status['position']);
        assert_same(null, $status['next'], 'a member who never qualified has no next level');
        assert_same(0, $status['matrix']['positions']);
    },

    /* ==========================================================
     *  The ledger API (T33)
     * ========================================================== */

    'P20D-T33 the ledger filters, pages, totals and breakdowns are exact' => function () use ($clean, $arm, $grow): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't33', $service);
        $root    = $tree['root'];

        $all = TeamHarvestAward::totals();

        assert_same(18, $all['rows']);
        assert_same(18, $all['credited_rows']);
        assert_same(25000.0, $all['credited_total']);
        assert_same(0, $all['baseline_rows']);
        assert_same(13, $all['recipients']);

        $level3 = TeamHarvestAward::totals(['level' => '3']);
        assert_same(1, $level3['rows'], 'one level-3 award');
        assert_same(4000.0, $level3['credited_total']);

        assert_same(0, TeamHarvestAward::totals(['status' => 'baseline'])['rows']);
        assert_same(18, TeamHarvestAward::totals(['level' => '42'])['rows'], 'an unknown level means no filter');

        $page1 = TeamHarvestAward::select([], new Paginator(18, 10, 1));
        $page2 = TeamHarvestAward::select([], new Paginator(18, 10, 2));

        assert_count(10, $page1);
        assert_count(8, $page2);
        assert_same(18, TeamHarvestAward::count());

        foreach ($page1 as $row) {
            assert_not_null($row['position'], 'each row carries its member position');
            assert_not_same('', (string) $row['username']);
            assert_same('credited', $row['status']);
        }

        $rootRows = TeamHarvestAward::select(['user_id' => $root], new Paginator(3, 10, 1));
        assert_count(3, $rootRows, 'the member filter intersects');

        $search = TeamHarvestAward::select(['q' => '_root'], new Paginator(3, 10, 1));
        assert_count(3, $search);

        /* Two filters intersect; a bad value falls back to no filter. */
        assert_count(1, TeamHarvestAward::select(['level' => 3, 'q' => '_root'], new Paginator(1, 10, 1)));
        assert_count(3, TeamHarvestAward::select(['level' => 'nope', 'user_id' => $root], new Paginator(3, 10, 1)));

        $byLevel = TeamHarvestAward::byLevel();
        assert_count(10, $byLevel, 'ten rows always, including the levels nobody reached');
        assert_same(6000.0, $byLevel[4]['amount'], 'the configured amount, not zero');

        $byMonth = TeamHarvestAward::byMonth();
        assert_true($byMonth !== []);
        assert_same(25000.0, $byMonth[0]['credited_total'], 'all of it was decided in one month');

        $export = TeamHarvestAward::exportRows(['level' => '3']);
        assert_count(1, $export);
        assert_same(4000.0, $export[0]['amount']);
    },

    'P20D-T34 three sequential evaluations write one set of rows and throw nothing' => function () use ($clean, $arm, $grow, $enable, $awards, $withdrawable): void {
        $clean();
        $arm('2026-11-01 00:00:00', null, false);
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't34', $service);
        $root    = $tree['root'];

        $enable(true);

        $first  = (new TeamHarvestService())->evaluate([$root]);
        $second = (new TeamHarvestService())->evaluate([$root]);
        $third  = (new TeamHarvestService())->evaluate([$root]);

        assert_same(2, $first['paid'], 'the first evaluation pays levels 1-2');
        assert_same(0, $second['paid'], 'the losers write nothing');
        assert_same(0, $third['paid']);
        assert_same(0, $second['written']);
        assert_same(0, $third['written']);
        assert_same(3000.0, $withdrawable($root), 'the wallet was credited exactly once');
        assert_count(2, $awards($root), 'one row per level');
        assert_count(2, $awards($root), 'and no duplicates');
    },

    /* ==========================================================
     *  The zero amount and the invariants (T35–T36)
     * ========================================================== */

    'P20D-T35 a zero amount is a switch: no row, then it pays when raised' => function () use ($clean, $arm, $ladder, $grow, $awards, $withdrawable): void {
        $clean();
        $ladder([1 => 0]);
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't35', $service);
        $root    = $tree['root'];

        $rows = $awards($root);
        assert_count(1, $rows, 'level 1 completed but configured at zero writes no row');
        assert_same(2, $rows[0]['level']);
        assert_same(2000.0, $withdrawable($root));

        $status = $service->statusFor($root);
        assert_same(true, $status['levels'][1]['complete']);
        assert_same('no_amount', $status['levels'][1]['status']);
        assert_same('0.00', $status['open']);

        /* Raising the amount makes the level payable, once (§12.6 Rule 2). */
        $ladder([1 => 1000.0]);

        $result = $service->evaluate([$root]);
        assert_same(1, $result['paid']);
        assert_same(1000.0, $result['amount']);

        $rows = $awards($root);
        assert_count(2, $rows);
        assert_same([1, 2], array_column($rows, 'level'));
        assert_same(3000.0, $withdrawable($root));

        assert_same(0, $service->evaluate([$root])['paid'], 'and only once');
    },

    'P20D-T36 no baseline row is paid, every credit has its award, the totals agree' => function () use ($clean, $arm, $grow): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $grow(3, 't36', $service);

        /* §13.8 I-06: a baseline row never names a movement. */
        assert_same(
            0,
            (int) db()->query("SELECT COUNT(*) FROM team_harvest_awards WHERE status = 'baseline' AND ledger_id IS NOT NULL")->fetchColumn(),
            'no baseline award carries a ledger reference'
        );

        /* §13.8 I-06: a credited row always does, and it exists. */
        assert_same(
            0,
            (int) db()->query(
                "SELECT COUNT(*) FROM team_harvest_awards a
                   LEFT JOIN ewallet_ledger l ON l.id = a.ledger_id AND l.ref_type = 'team_harvest'
                  WHERE a.status = 'credited' AND (a.ledger_id IS NULL OR l.id IS NULL)"
            )->fetchColumn(),
            'every credited award has a live wallet movement'
        );

        /* §13.8 I-07 and I-08. */
        $awardTotal = (float) db()->query(
            "SELECT COALESCE(SUM(amount), 0) FROM team_harvest_awards WHERE status = 'credited'"
        )->fetchColumn();
        $ledgerTotal = (float) db()->query(
            "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger WHERE ref_type = 'team_harvest' AND type = 'credit'"
        )->fetchColumn();

        assert_same(25000.0, round($awardTotal, 2));
        assert_same(round($awardTotal, 2), round($ledgerTotal, 2), 'awards and credits agree to the centavo');

        assert_same(
            0,
            (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards WHERE level > 10')->fetchColumn(),
            'no award above the configured depth'
        );
        assert_same(
            0,
            (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards WHERE n_at_award < threshold_n')->fetchColumn(),
            'no award below its own threshold'
        );

        $report = $service->reconcile();
        assert_same(true, $report['ok'], json_encode($report['mismatches']));
        assert_same(25000.0, $report['credited_total']);
        assert_same(25000.0, $report['ledger_total']);
        assert_same(0, $report['drift']);
    },
];
