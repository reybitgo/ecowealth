<?php
declare(strict_types=1);

/* ==============================================================
 *  CommissionIndirectTest — the unilevel ladder (plan §6.2–6.6,
 *  §7 Phase 11, tests P11-T1 … P11-T12)
 *
 *  Every award is produced by a real ActivationService::complete(),
 *  never by inserting a commission row directly, so these cases prove
 *  the walk, the package matrix and the wallet movement together.
 *
 *  A fixture builds a chain top-down: `byLevel[1]` is the direct
 *  sponsor, `byLevel[depth]` is the top of the chain. The source member
 *  sits below level 1 and is activated through the shared service.
 * ============================================================== */

/** @return array<string, mixed> */
$makeActive = static function (string $username, array $package, ?int $sponsorId = null): array {
    $user = test_user($username);

    db()->prepare(
        "UPDATE users
            SET package_id = ?, sponsor_id = ?, member_status = 'active', is_active = 1
          WHERE id = ?"
    )->execute([(int) $package['id'], $sponsorId, (int) $user['id']]);

    $member = Member::find((int) $user['id']);
    assert_not_null($member);

    return $member;
};

/** @return array<string, mixed> */
$makePending = static function (string $username, ?int $sponsorId): array {
    $user = test_user($username);

    db()->prepare(
        "UPDATE users
            SET package_id = NULL, sponsor_id = ?, member_status = 'pending', is_active = 1
          WHERE id = ?"
    )->execute([$sponsorId, (int) $user['id']]);

    $member = Member::find((int) $user['id']);
    assert_not_null($member);

    return $member;
};

/**
 * Build an active sponsor chain and one pending source below it.
 *
 * @return array{source:array<string, mixed>, byLevel:array<int, array<string, mixed>>, top:array<string, mixed>}
 */
$buildChain = static function (int $depth, array $package, string $prefix = 'p11') use ($makeActive, $makePending): array {
    $run      = bin2hex(random_bytes(3));
    $byLevel  = [];
    $parentId = null;

    for ($level = $depth; $level >= 1; $level--) {
        $member           = $makeActive($prefix . '_' . $run . '_l' . $level, $package, $parentId);
        $byLevel[$level]  = $member;
        $parentId         = (int) $member['id'];
    }

    return [
        'source'  => $makePending($prefix . '_' . $run . '_src', $parentId),
        'byLevel' => $byLevel,
        'top'     => $byLevel[$depth],
    ];
};

/** Complete an approved activation for the member. */
$activate = static function (array $member, array $package, string $method = 'code'): array {
    $activationId = Activation::create([
        'user_id'        => (int) $member['id'],
        'package_id'     => (int) $package['id'],
        'amount'         => (float) $package['price'],
        'payment_method' => $method,
        'status'         => 'approved',
    ]);

    $summary = (new ActivationService())->complete($activationId);

    return $summary + ['activation_id' => $activationId];
};

/** @return array<int, array<string, mixed>> keyed by level */
$rowsByLevel = static function (array $rows): array {
    $map = [];

    foreach ($rows as $row) {
        $map[(int) $row['level']] = $row;
    }

    return $map;
};

/** @return array<int, float> */
$balances = static function (array $members): array {
    $wallet = new WalletService();
    $out    = [];

    foreach ($members as $level => $member) {
        $out[(int) $level] = $wallet->balance((int) $member['id']);
    }

    return $out;
};

$wallet  = static fn (): WalletService => new WalletService();
$pkgA    = static fn (): array => Package::findBySlug('package-a') ?? [];
$pkgB    = static fn (): array => Package::findBySlug('package-b') ?? [];
$expected = static fn (): array => [
    1 => 500.0, 2 => 100.0, 3 => 50.0, 4 => 40.0, 5 => 30.0,
    6 => 20.0, 7 => 10.0, 8 => 5.0, 9 => 5.0, 10 => 5.0,
];

return [
    /* ---------- P11-T1: the full ten-level walk ------------------- */

    'P11-T1 a ten-deep chain pays levels 1–10 exactly per the package matrix' => function () use ($buildChain, $activate, $pkgA, $rowsByLevel, $balances, $wallet, $expected): void {
        $package = $pkgA();
        $chain   = $buildChain(10, $package);
        $before  = $balances($chain['byLevel']);
        $ledgerBefore = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();

        $run = $activate($chain['source'], $package);

        $rows = Commission::forActivation((int) $run['activation_id']);
        assert_count(10, $rows, 'direct + nine indirect levels');

        $byLevel = $rowsByLevel($rows);

        foreach ($expected() as $level => $amount) {
            assert_true(isset($byLevel[$level]), 'a row exists for level ' . $level);

            $row = $byLevel[$level];

            assert_same($level, (int) $row['level']);
            assert_same($level === 1 ? 'direct_referral' : 'indirect_referral', $row['type']);
            assert_same($amount, (float) $row['amount'], 'amount at level ' . $level);
            assert_same('credited', $row['status']);
            assert_same((int) $chain['byLevel'][$level]['id'], (int) $row['user_id'], 'recipient at level ' . $level);
            assert_same((int) $chain['source']['id'], (int) $row['source_user_id']);
            assert_same((int) $package['id'], (int) $row['package_id']);
            assert_not_null($row['ledger_id'], 'level ' . $level . ' has its wallet movement');
            assert_same($before[$level] + $amount, $wallet()->balance((int) $row['user_id']), 'wallet at level ' . $level);
            assert_same($before[$level] + $amount, $wallet()->withdrawable((int) $row['user_id']), 'credit is withdrawable');
        }

        /* The complete matrix is 500 + 100 + 50 + 40 + 30 + 20 + 10 + 5 + 5 + 5. */
        assert_same(765.0, (float) $run['commission_credited']);
        assert_same(0, (int) $run['commission_skipped']);
        assert_same($ledgerBefore + 10, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());

        assert_same('indirect_referral', (string) ($byLevel[2]['type'] ?? ''));
    },

    /* ---------- P11-T2: an eleventh level earns nothing ---------- */

    'P11-T2 a chain eleven deep stops at level 10' => function () use ($buildChain, $activate, $pkgA, $rowsByLevel, $wallet): void {
        $package = $pkgA();
        $chain   = $buildChain(11, $package);
        $top     = $chain['top'];
        $topBefore = $wallet()->balance((int) $top['id']);

        $run = $activate($chain['source'], $package);

        $rows = Commission::forActivation((int) $run['activation_id']);
        assert_count(10, $rows);

        $byLevel = $rowsByLevel($rows);
        assert_true(isset($byLevel[10]), 'level 10 is the last award');
        assert_false(isset($byLevel[11]), 'level 11 is out of scope');
        assert_same(0, Commission::countForUser((int) $top['id'], Commission::filterDefaults()));
        assert_same($topBefore, $wallet()->balance((int) $top['id']), 'the eleventh ancestor earns nothing');
        assert_same(765.0, (float) $run['commission_credited']);
    },

    /* ---------- P11-T3: no compression --------------------------- */

    'P11-T3 an ineligible upline consumes its level and deeper levels continue' => function () use ($buildChain, $activate, $pkgA, $rowsByLevel, $wallet): void {
        $package = $pkgA();
        $chain   = $buildChain(4, $package);
        $level2  = $chain['byLevel'][2];

        /* The level-2 upline is no longer an active associate. */
        db()->prepare("UPDATE users SET member_status = 'pending', package_id = NULL WHERE id = ?")
            ->execute([(int) $level2['id']]);

        $before = [
            1 => $wallet()->balance((int) $chain['byLevel'][1]['id']),
            2 => $wallet()->balance((int) $level2['id']),
            3 => $wallet()->balance((int) $chain['byLevel'][3]['id']),
            4 => $wallet()->balance((int) $chain['byLevel'][4]['id']),
        ];
        $ledgerBefore = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();

        $run  = $activate($chain['source'], $package);
        $rows = Commission::forActivation((int) $run['activation_id']);

        assert_count(4, $rows, 'the skipped level still occupies a row');
        assert_same(590.0, (float) $run['commission_credited']);
        assert_same(1, (int) $run['commission_skipped']);

        $byLevel = $rowsByLevel($rows);

        assert_same('credited', $byLevel[1]['status']);
        assert_same(500.0, (float) $byLevel[1]['amount']);
        assert_same($before[1] + 500.0, $wallet()->balance((int) $chain['byLevel'][1]['id']));

        assert_same('skipped', $byLevel[2]['status']);
        assert_same('indirect_referral', $byLevel[2]['type']);
        assert_same(100.0, (float) $byLevel[2]['amount'], 'the configured amount is recorded for audit');
        assert_null($byLevel[2]['ledger_id']);
        assert_contains('pending', (string) $byLevel[2]['reason']);
        assert_same($before[2], $wallet()->balance((int) $level2['id']), 'the skipped level moves no money');

        assert_same('credited', $byLevel[3]['status']);
        assert_same(50.0, (float) $byLevel[3]['amount'], 'the deeper level keeps its own amount');
        assert_same($before[3] + 50.0, $wallet()->balance((int) $chain['byLevel'][3]['id']));

        assert_same('credited', $byLevel[4]['status']);
        assert_same(40.0, (float) $byLevel[4]['amount']);
        assert_same($before[4] + 40.0, $wallet()->balance((int) $chain['byLevel'][4]['id']));

        assert_same($ledgerBefore + 3, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());
    },

    /* ---------- P11-T4: package B has the premium ladder ---------- */

    'P11-T4 package B pays a higher level matrix than package A' => function () use ($pkgA, $pkgB, $buildChain, $activate, $rowsByLevel): void {
        $a = $pkgA();
        $b = $pkgB();

        $aLevels = Package::levels((int) $a['id']);
        $bLevels = Package::levels((int) $b['id']);

        $expectedB = [2 => 120.0, 3 => 60.0, 4 => 48.0, 5 => 36.0, 6 => 24.0, 7 => 12.0, 8 => 6.0, 9 => 6.0, 10 => 6.0];

        assert_same(500.0, (float) $a['direct_referral_bonus']);
        assert_same(600.0, (float) $b['direct_referral_bonus'], 'Package B carries the 20% premium direct bonus');
        assert_same($expectedB, $bLevels, 'Package B levels 2–10 are 20% above Package A');
        assert_same(918.0, round(600.0 + array_sum($bLevels), 2), 'the full Package B ladder');

        foreach ($aLevels as $level => $amount) {
            assert_true($bLevels[$level] > $amount, 'Package B pays more at level ' . $level);
        }

        $chain = $buildChain(3, $b, 'p11b');
        $run   = $activate($chain['source'], $b);
        $rows  = Commission::forActivation((int) $run['activation_id']);
        $byLevel = $rowsByLevel($rows);

        assert_count(3, $rows);
        assert_same(600.0, (float) $byLevel[1]['amount']);
        assert_same(120.0, (float) $byLevel[2]['amount']);
        assert_same(60.0, (float) $byLevel[3]['amount']);
        assert_same('Package B', $byLevel[2]['package_name']);
        assert_same('indirect_referral', $byLevel[2]['type']);
    },

    /* ---------- P11-T5: switches and depth ------------------------ */

    'P11-T5 a package with indirect disabled pays only the direct award' => function () use ($pkgA, $buildChain, $activate, $rowsByLevel): void {
        $package = $pkgA();

        db()->prepare('UPDATE packages SET indirect_enabled = 0 WHERE id = ?')->execute([(int) $package['id']]);

        $chain = $buildChain(4, $package, 'p11off');
        $run   = $activate($chain['source'], $package);
        $rows  = Commission::forActivation((int) $run['activation_id']);

        assert_count(1, $rows, 'no indirect rows when the package switch is off');
        assert_same('credited', $rows[0]['status']);
        assert_same(500.0, (float) $rows[0]['amount']);
        assert_same(500.0, (float) $run['commission_credited']);
        assert_same(0, (int) $run['commission_skipped']);

        $byLevel = $rowsByLevel($rows);
        assert_false(isset($byLevel[2]));
        assert_false(isset($byLevel[3]));
    },

    'P11-T5 the global indirect switch turns the ladder off' => function () use ($pkgA, $buildChain, $activate, $rowsByLevel): void {
        $package = $pkgA();

        setting_set('commission_indirect_enabled', '0');

        try {
            $chain = $buildChain(3, $package, 'p11glob');
            $run   = $activate($chain['source'], $package);
            $rows  = Commission::forActivation((int) $run['activation_id']);
            $byLevel = $rowsByLevel($rows);

            assert_count(1, $rows);
            assert_same('credited', $byLevel[1]['status']);
            assert_false(isset($byLevel[2]));
            assert_same(500.0, (float) $run['commission_credited']);
        } finally {
            setting_set('commission_indirect_enabled', '1');
        }
    },

    'P11-T5 commission_indirect_max_levels caps the walk' => function () use ($pkgA, $buildChain, $activate, $rowsByLevel, $wallet): void {
        $package = $pkgA();
        $chain   = $buildChain(5, $package, 'p11max');
        $before4 = $wallet()->balance((int) $chain['byLevel'][4]['id']);
        $before5 = $wallet()->balance((int) $chain['byLevel'][5]['id']);

        setting_set('commission_indirect_max_levels', '3');

        try {
            $run  = $activate($chain['source'], $package);
            $rows = Commission::forActivation((int) $run['activation_id']);
            $byLevel = $rowsByLevel($rows);

            assert_count(3, $rows, 'levels 1–3 only');
            assert_true(isset($byLevel[1], $byLevel[2], $byLevel[3]));
            assert_false(isset($byLevel[4]));
            assert_false(isset($byLevel[5]));
            assert_same($before4, $wallet()->balance((int) $chain['byLevel'][4]['id']));
            assert_same($before5, $wallet()->balance((int) $chain['byLevel'][5]['id']));
        } finally {
            setting_set('commission_indirect_max_levels', '10');
        }
    },

    /* ---------- P11-T6: replay ------------------------------------ */

    'P11-T6 replaying the hook pays no level twice' => function () use ($pkgA, $buildChain, $activate, $wallet): void {
        $package = $pkgA();
        $chain   = $buildChain(3, $package, 'p11rep');
        $run     = $activate($chain['source'], $package);

        $rowsBefore   = Commission::forActivation((int) $run['activation_id']);
        $ledgerBefore = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();
        $balances     = [];

        foreach ($chain['byLevel'] as $level => $member) {
            $balances[$level] = $wallet()->balance((int) $member['id']);
        }

        $replay = (new CommissionService())->onActivation((int) $run['activation_id']);

        assert_same(0.0, (float) $replay['credited'], 'a replay credits nothing');
        assert_same(0, (int) $replay['skipped']);
        assert_count(3, $replay['awards'], 'the existing rows are reported');
        assert_count(3, Commission::forActivation((int) $run['activation_id']));
        assert_same($ledgerBefore, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());

        foreach ($chain['byLevel'] as $level => $member) {
            assert_same($balances[$level], $wallet()->balance((int) $member['id']), 'wallet level ' . $level . ' unchanged');
        }

        assert_throws(
            static fn () => (new ActivationService())->complete((int) $run['activation_id']),
            ActivationException::class
        );

        assert_same(
            count($rowsBefore),
            (int) db()->query(
                'SELECT COUNT(*) FROM commissions WHERE activation_id = ' . (int) $run['activation_id']
            )->fetchColumn()
        );
    },

    /* ---------- P11-T7: a zero level writes no row ---------------- */

    'P11-T7 a zero-amount level writes no row and keeps deeper levels' => function () use ($pkgA, $buildChain, $activate, $rowsByLevel, $wallet): void {
        $package = $pkgA();
        $chain   = $buildChain(4, $package, 'p11zero');
        $before4 = $wallet()->balance((int) $chain['byLevel'][4]['id']);

        db()->prepare('UPDATE package_levels SET amount = 0.00 WHERE package_id = ? AND level = 3')
            ->execute([(int) $package['id']]);

        $run  = $activate($chain['source'], $package);
        $rows = Commission::forActivation((int) $run['activation_id']);
        $byLevel = $rowsByLevel($rows);

        assert_count(3, $rows, 'levels 1, 2 and 4');
        assert_true(isset($byLevel[1], $byLevel[2], $byLevel[4]));
        assert_false(isset($byLevel[3]), 'a zero level has no row');
        assert_same(640.0, (float) $run['commission_credited']);
        assert_same($before4 + 40.0, $wallet()->balance((int) $chain['byLevel'][4]['id']), 'level 4 still pays');
    },

    /* ---------- P11-T8: wallet = awards -------------------------- */

    'P11-T8 wallet credits equal the credited commission rows' => function () use ($pkgA, $buildChain, $activate): void {
        $package = $pkgA();
        $chain   = $buildChain(10, $package, 'p11sum');
        $run     = $activate($chain['source'], $package);

        $rows    = Commission::forActivation((int) $run['activation_id']);
        $credits = array_filter($rows, static fn (array $row): bool => $row['status'] === 'credited');
        $awardTotal = round(array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $credits)), 2);

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $credits);
        $marks = implode(',', array_fill(0, count($ids), '?'));

        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger
              WHERE ref_type = 'commission' AND ref_id IN ($marks)"
        );
        $stmt->execute($ids);
        $ledgerTotal = round((float) $stmt->fetchColumn(), 2);

        assert_same(765.0, $awardTotal);
        assert_same($awardTotal, $ledgerTotal);
        assert_same($awardTotal, (float) $run['commission_credited']);

        $wallet = new WalletService();
        $moved  = 0.0;

        foreach ($chain['byLevel'] as $member) {
            $moved += $wallet->balance((int) $member['id']);
        }

        assert_same($awardTotal, round($moved, 2), 'the sum of the wallet credits equals the sum of the rows');
    },

    /* ---------- P11-T9: the earnings breakdown ------------------- */

    'P11-T9 the earnings page shows a per-level breakdown' => function () use ($pkgA, $buildChain, $activate, $rowsByLevel): void {
        $package = $pkgA();
        $chain   = $buildChain(6, $package, 'p11view');
        $top     = $chain['top'];

        /* Activate levels 1–5: each activation pays the top one more
         * level, so the top's breakdown has exactly one award at each
         * of levels 1–5. */
        for ($level = 1; $level <= 5; $level++) {
            $activate($chain['byLevel'][$level], $package);
        }

        $breakdown = Commission::levelBreakdown((int) $top['id']);

        $amounts = [1 => 500.0, 2 => 100.0, 3 => 50.0, 4 => 40.0, 5 => 30.0];

        foreach ($amounts as $level => $amount) {
            assert_same(1, (int) $breakdown[$level]['count'], 'one award at level ' . $level);
            assert_same($amount, (float) $breakdown[$level]['amount'], 'amount at level ' . $level);
        }

        for ($level = 6; $level <= 10; $level++) {
            assert_same(0, (int) $breakdown[$level]['count']);
            assert_same(0.0, (float) $breakdown[$level]['amount']);
        }

        assert_same(720.0, (float) Commission::totalsForUser((int) $top['id'])['credited']);

        test_signed_in_as($top);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings';

        $controller = new EarningsController();
        $context    = $controller->earningsContext((int) $top['id']);

        ob_start();

        try {
            View::page('member', 'member/earnings', $context + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $top['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Unilevel breakdown', $html);
        assert_contains('Level 1 · Direct', $html);
        assert_contains('Level 5', $html);
        assert_contains(money(500.0), $html);
        assert_contains(money(30.0), $html);
        assert_contains('data-live-region="earnings-levels"', $html);
        assert_contains('5 awards', $html);
        assert_contains('href="' . url('earnings/export') . '"', $html);

        $payload = $controller->feedPayload(3, '"member.earnings:' . (int) $top['id'] . ':3:abc"', $context);

        assert_same(
            ['earnings-header', 'earnings-stats', 'earnings-levels', 'earnings-skipped', 'earnings-rows', 'earnings-pagination'],
            array_keys($payload['fragments'])
        );
        assert_contains('data-live-region="earnings-levels"', $payload['fragments']['earnings-levels']);
    },

    /* ---------- P11-T10: admin filters and export ---------------- */

    'P11-T10 the admin ledger filters indirect awards by type and level' => function () use ($pkgA, $buildChain, $activate): void {
        $package = $pkgA();
        $chain   = $buildChain(4, $package, 'p11adm');
        $run     = $activate($chain['source'], $package);

        $indirect = Commission::normaliseFilters(['type' => 'indirect_referral']);
        assert_same(3, Commission::countAdmin($indirect));
        assert_same(1, Commission::countAdmin(Commission::normaliseFilters(['level' => '2'])));
        assert_same(1, Commission::countAdmin(Commission::normaliseFilters(['level' => '3'])));
        assert_same(1, Commission::countAdmin(Commission::normaliseFilters(['level' => '4'])));

        $level2 = Commission::adminList(
            Commission::normaliseFilters(['type' => 'indirect_referral', 'level' => '2']),
            Paginator::make(1, 1, 10)
        );

        assert_count(1, $level2);
        assert_same((int) $chain['byLevel'][2]['id'], (int) $level2[0]['user_id']);
        assert_same(100.0, (float) $level2[0]['amount']);
        assert_same('indirect_referral', $level2[0]['type']);
        assert_same('Package A', $level2[0]['package_name']);

        $stats = Commission::stats();
        assert_same(4, (int) $stats['top_level']);
        assert_same(4, (int) $stats['credited']);

        $export = Commission::exportRows(null, $indirect);
        assert_count(3, $export);
        assert_same((int) $chain['byLevel'][4]['id'], (int) $export[0]['user_id']);
        assert_same(2, (int) $export[2]['level']);
    },

    /* ---------- P11-T11: bounded walk ---------------------------- */

    'P11-T11 a ten-hop sponsor walk stays within twenty queries' => function () use ($pkgA, $buildChain): void {
        $package = $pkgA();
        $chain   = $buildChain(10, $package, 'p11perf');

        $questions = static function (): int {
            $row = db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch();

            return (int) ($row['Value'] ?? 0);
        };

        $before = $questions();
        $walk   = (new CommissionService())->sponsorChain((int) $chain['source']['id'], 10);
        $levels = Package::levels((int) $package['id']);
        $after  = $questions();

        assert_count(10, $walk, 'the whole chain is read');
        assert_count(9, $levels, 'levels 2–10 are read once, not per hop');

        $queries = $after - $before;
        assert_true($queries <= 20, 'the ten-hop walk used ' . $queries . ' queries');
    },

    /* ---------- P11-T12: the workflow example -------------------- */

    'P11-T12 worked Example B pays the full ladder' => function () use ($pkgA, $makeActive, $makePending, $activate): void {
        $package = $pkgA();
        $run     = bin2hex(random_bytes(3));

        /* juan ← maria ← peter, sarah, david; peter ← linda, miguel. */
        $juan   = $makeActive('p11ex_' . $run . '_juan', $package);
        $maria  = $makePending('p11ex_' . $run . '_maria', (int) $juan['id']);
        $peter  = $makePending('p11ex_' . $run . '_peter', (int) $maria['id']);
        $sarah  = $makePending('p11ex_' . $run . '_sarah', (int) $maria['id']);
        $david  = $makePending('p11ex_' . $run . '_david', (int) $maria['id']);
        $linda  = $makePending('p11ex_' . $run . '_linda', (int) $peter['id']);
        $miguel = $makePending('p11ex_' . $run . '_miguel', (int) $peter['id']);

        foreach ([$maria, $peter, $sarah, $david, $linda, $miguel] as $member) {
            $activate($member, $package);
        }

        $juanTotals  = Commission::totalsForUser((int) $juan['id']);
        $mariaTotals = Commission::totalsForUser((int) $maria['id']);
        $peterTotals = Commission::totalsForUser((int) $peter['id']);

        /* The plan's example: Juan ₱900, Peter ₱1,000. Maria's stated
         * ₱1,500 counts her directs (Peter/Sarah/David); the unilevel
         * ladder also pays her level 2 on Linda and Miguel (₱200), so
         * her correct full total is ₱1,700. The plan table omits those
         * two level-2 rows; the rules in §6.4 do not. */
        assert_same(900.0, (float) $juanTotals['credited'], 'Juan: 500 + 3×100 + 2×50');
        assert_same(1000.0, (float) $peterTotals['credited'], 'Peter: 2×500');
        assert_same(1700.0, (float) $mariaTotals['credited'], 'Maria: 3×500 + 2×100');
        assert_same(1500.0, (float) $mariaTotals['direct'], 'Maria’s direct total matches the plan table');
        assert_same(200.0, (float) $mariaTotals['indirect'], 'Maria’s two level-2 awards');
        assert_same(5, (int) $mariaTotals['count'], 'three direct plus two indirect rows');
    },
];
