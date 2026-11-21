<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberEarningsLiveTest — the member's own commission statement
 *
 *  The statement is a per-member live surface (`member.earnings:{id}`)
 *  whose regions are the summary cards, the skipped-award notice, the
 *  card heading, the award rows and the pagination. These cases pin:
 *
 *    · a credited or skipped award bumps it (a replayed award does not)
 *      and another member's statement is untouched;
 *    · the feed renders every region, keeps the filter form out of the
 *      payload, escapes source names and clamps an out-of-range page;
 *    · a skipped award appears live and never claims money;
 *    · the repeat tab revalidates when the member's team changes;
 *    · the page declares its live surface and regions;
 *    · (Phase 20F) the Team Harvest tab rides the same surface, and the
 *      award that opens a member's first level bumps it (P20F-T17).
 * ============================================================== */

$harvest = require __DIR__ . '/../fixtures/harvest_money.php';

$makeMember = static function (string $username = ''): array {
    return test_user($username !== '' ? $username : 'mel_' . bin2hex(random_bytes(4)));
};

$packageA = static function (): array {
    $package = Package::findBySlug('package-a');
    assert_not_null($package);

    return $package;
};

/** Give a test member the active membership + package an award needs. */
$qualify = static function (int $userId, array $package): void {
    db()->prepare("UPDATE users SET member_status = 'active', is_active = 1, package_id = ? WHERE id = ?")
        ->execute([(int) $package['id'], $userId]);
};

/** A new active member placed under the given sponsor. */
$sourceUnder = static function (int $sponsorId): array {
    $source = test_user('mel_src_' . bin2hex(random_bytes(4)));

    db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
        ->execute([$sponsorId, (int) $source['id']]);

    $source['sponsor_id'] = $sponsorId;

    return $source;
};

/** An approved activation row, ready for CommissionService::onActivation(). */
$approvedActivation = static function (int $userId, array $package): array {
    $id = Activation::create([
        'user_id'        => $userId,
        'package_id'     => (int) $package['id'],
        'amount'         => (float) $package['price'],
        'payment_method' => 'code',
        'status'         => 'approved',
    ]);

    $row = Activation::find($id);
    assert_not_null($row);

    return $row;
};

return [
    'the earnings surface is per user and bumps on award and skip' => function () use (
        $makeMember,
        $packageA,
        $qualify,
        $sourceUnder,
        $approvedActivation
    ): void {
        $package   = $packageA();
        $recipient = $makeMember();
        $other     = $makeMember();
        $id        = (int) $recipient['id'];
        $otherId   = (int) $other['id'];

        $qualify($id, $package);

        $source        = $sourceUnder($id);
        $activation    = $approvedActivation((int) $source['id'], $package);
        $commissions   = new CommissionService();

        assert_same('member.earnings:' . $id, Live::earningsSurface($id));

        $before      = Live::revision(Live::earningsSurface($id));
        $otherBefore = Live::revision(Live::earningsSurface($otherId));

        $award = $commissions->onActivation((int) $activation['id']);

        assert_same(1, count($award['awards']), 'one direct award');
        assert_same(500.0, (float) $award['credited']);
        assert_same($before + 1, Live::revision(Live::earningsSurface($id)), 'award');
        assert_same($otherBefore, Live::revision(Live::earningsSurface($otherId)), 'another member is untouched');

        /* A replayed activation pays nothing and must not bump. */
        $before = Live::revision(Live::earningsSurface($id));
        $replay = $commissions->onActivation((int) $activation['id']);

        assert_same(0.0, (float) $replay['credited']);
        assert_same($before, Live::revision(Live::earningsSurface($id)));

        /* A skipped award still writes an audit row on the member's page. */
        $skipRecipient = $makeMember();
        $skipSource    = $sourceUnder((int) $skipRecipient['id']);
        $skipActivation = $approvedActivation((int) $skipSource['id'], $package);

        $skipBefore      = Live::revision(Live::earningsSurface((int) $skipRecipient['id']));
        $recipientBefore = Live::revision(Live::earningsSurface($id));

        $skip = $commissions->onActivation((int) $skipActivation['id']);

        assert_same(1, $skip['skipped']);
        assert_same($skipBefore + 1, Live::revision(Live::earningsSurface((int) $skipRecipient['id'])), 'skip');
        assert_same($recipientBefore, Live::revision(Live::earningsSurface($id)), 'the credited member is untouched');

        $skipRow = Commission::find((int) $skip['awards'][0]['id']);
        assert_not_null($skipRow);
        assert_same('skipped', $skipRow['status']);
    },

    'the earnings feed renders the stats, rows and pagination' => function () use (
        $makeMember,
        $packageA,
        $qualify,
        $sourceUnder,
        $approvedActivation
    ): void {
        $package   = $packageA();
        $recipient = $makeMember();
        $id        = (int) $recipient['id'];

        $qualify($id, $package);

        $commissions = new CommissionService();
        $sources     = [];

        /* Six awards make a second page at the smallest offered size. */
        for ($i = 1; $i <= 6; $i++) {
            $source    = $sourceUnder($id);
            $sources[] = $source;

            $commissions->onActivation((int) $approvedActivation((int) $source['id'], $package)['id']);
        }

        /* The row joins the source live; a hostile name must be escaped. */
        db()->prepare('UPDATE users SET username = ? WHERE id = ?')
            ->execute(['<b>Hostile Source</b>', (int) $sources[5]['id']]);

        test_signed_in_as($recipient);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?per_page=5';

        $controller = new EarningsController();
        $payload    = $controller->feedPayload(
            4,
            '"member.earnings:' . $id . ':4:abc"',
            $controller->earningsContext($id)
        );

        assert_true($payload['ok']);
        assert_same('member.earnings:' . $id, $payload['surface']);
        assert_same(4, $payload['revision']);
        assert_same(
            [
                'earnings-header',
                'earnings-stats',
                'earnings-levels',
                'earnings-skipped',
                'earnings-rows',
                'earnings-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $header     = $payload['fragments']['earnings-header'];
        $stats      = $payload['fragments']['earnings-stats'];
        $levels     = $payload['fragments']['earnings-levels'];
        $skipped    = $payload['fragments']['earnings-skipped'];
        $rows       = $payload['fragments']['earnings-rows'];
        $pagination = $payload['fragments']['earnings-pagination'];

        assert_contains('data-live-region="earnings-header"', $header);
        assert_contains('6 awards', $header);

        assert_contains('data-live-region="earnings-stats"', $stats);
        assert_contains('Total credited', $stats);
        assert_contains(money(3000), $stats, 'six ₱500 awards');
        assert_contains('This month', $stats);
        assert_contains('Direct referral', $stats);
        assert_contains('Indirect referral', $stats);

        assert_contains('data-live-region="earnings-levels"', $levels);
        assert_contains('Unilevel breakdown', $levels);
        assert_contains('Level 1 · Direct', $levels);
        assert_contains('Level 10', $levels);
        assert_contains('6 awards', $levels, 'the direct level carries all six awards');

        assert_contains('data-live-region="earnings-skipped"', $skipped);
        assert_contains('earnings-skipped" hidden', $skipped);
        assert_not_contains('was skipped', $skipped);

        assert_contains('data-live-region="earnings-rows"', $rows);
        assert_contains('data-live-key=', $rows);
        assert_contains('data-live-focus="details"', $rows);
        assert_contains('&lt;b&gt;Hostile Source&lt;/b&gt;', $rows);
        assert_not_contains('<b>Hostile Source</b>', $rows);
        assert_contains('Package A', $rows);
        assert_contains('Credited', $rows);
        assert_contains(money(500, true), $rows);
        assert_contains('data-commission="{', $rows);
        assert_not_contains('name="type"', $rows, 'the filter form never travels in a row fragment');

        assert_contains('data-live-region="earnings-pagination"', $pagination);
        assert_contains('earnings?per_page=5&amp;page=2', $pagination);
        assert_not_contains('/feed', $pagination);

        assert_contains('earnings?per_page=5', $payload['meta']['canonical']);

        /* An out-of-range page is clamped to the last real page. */
        $_GET = ['page' => '99', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?page=99&per_page=5';

        $clamped = $controller->feedPayload(
            5,
            '"member.earnings:' . $id . ':5:def"',
            $controller->earningsContext($id)
        );

        assert_same(2, $clamped['meta']['page']);
        assert_contains('page=2', (string) $clamped['meta']['canonical']);
    },

    'the earnings feed surfaces a skipped award without claiming money' => function () use (
        $makeMember,
        $packageA,
        $sourceUnder,
        $approvedActivation
    ): void {
        $package   = $packageA();
        $recipient = $makeMember();
        $id        = (int) $recipient['id'];

        /* Active but no package: the award is due but never paid. */
        $source     = $sourceUnder($id);
        $activation = $approvedActivation((int) $source['id'], $package);

        (new CommissionService())->onActivation((int) $activation['id']);

        test_signed_in_as($recipient);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings';

        $controller = new EarningsController();
        $payload    = $controller->feedPayload(
            1,
            '"member.earnings:' . $id . ':1:abc"',
            $controller->earningsContext($id)
        );

        $stats   = $payload['fragments']['earnings-stats'];
        $skipped = $payload['fragments']['earnings-skipped'];
        $rows    = $payload['fragments']['earnings-rows'];

        assert_contains('data-live-region="earnings-skipped"', $skipped);
        assert_not_contains('earnings-skipped" hidden', $skipped);
        assert_contains('1 award was skipped', $skipped);
        assert_contains('never paid later', $skipped);

        assert_contains('data-live-key=', $rows);
        assert_contains('Skipped', $rows);
        assert_contains('not paid', $rows);
        assert_contains('recipient has no package', $rows);
        assert_contains(money(0), $stats, 'a skipped award is not money');
    },

    'the earnings page declares its live surface and regions' => function () use ($makeMember): void {        $member = $makeMember();

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings';

        $controller = new EarningsController();
        $data       = $controller->earningsContext((int) $member['id']);

        $payload = $controller->feedPayload(
            2,
            '"member.earnings:' . (int) $member['id'] . ':2:abc"',
            $data
        );

        /* An empty statement still carries every region, so the first
         * award can appear live without a full reload. */
        $rows = $payload['fragments']['earnings-rows'];

        assert_contains('data-live-region="earnings-rows"', $rows);
        assert_contains('No commissions yet', $rows);
        assert_contains('direct referral lands here', $rows);
        assert_contains('href="' . url('wallet') . '"', $rows);
        assert_contains('earnings-skipped" hidden', $payload['fragments']['earnings-skipped']);
        assert_contains('data-live-region="earnings-header"', $payload['fragments']['earnings-header']);
        assert_same(url('earnings'), $payload['meta']['canonical']);

        ob_start();

        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $member['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.earnings:' . (int) $member['id'] . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="earnings-header"', $html);
        assert_contains('data-live-region="earnings-stats"', $html);
        assert_contains('data-live-region="earnings-levels"', $html);
        assert_contains('data-live-region="earnings-skipped"', $html);
        assert_contains('data-live-region="earnings-rows"', $html);
        assert_contains('data-live-region="earnings-pagination"', $html);
        assert_contains('data-live-status', $html);
        assert_not_contains('data-live-stale', $html, 'the filter form is static');
        assert_contains('name="type"', $html);
        assert_contains('id="commissionDetail"', $html);
        assert_contains('app-commissions.js', $html);
        assert_contains('No commissions yet', $html);
    },

    /* ==========================================================
     *  P19E-T15 — the third tab and its live revision
     * ========================================================== */

    'P19E-T15 the Royalty tab is the third member tab and a close bumps its surface' => function (): void {
        /* The small 2/2 ladder with a Supervisor at the closed month's
         * quota, so a real close writes this member an award row. */
        foreach ([
            'royalty_enabled'                 => '1',
            'royalty_pay_enabled'             => '1',
            'royalty_leader_directs'          => '2',
            'royalty_supervisor_leaders'      => '2',
            'royalty_manager_supervisors'     => '2',
            'royalty_director_managers'       => '2',
            'royalty_supervisor_quota'        => '500000',
            'royalty_manager_quota'           => '1000000',
            'royalty_director_quota'          => '5000000',
            'royalty_supervisor_group_pct'    => '3.000',
            'royalty_supervisor_personal_pct' => '5.000',
            'royalty_manager_group_pct'       => '5.000',
            'royalty_manager_personal_pct'    => '10.000',
            'royalty_director_group_pct'      => '10.000',
            'royalty_director_personal_pct'   => '15.000',
            'royalty_ledger_since'            => '2020-01-01 00:00:00',
        ] as $key => $value) {
            setting_set($key, (string) $value);
        }

        setting_cache_clear();
        setting_all();

        /* Registered before any period, so both the live tree and the
         * period-end cutoff see the fixture. */
        $place = static function (string $username, ?int $sponsor): array {
            $row = test_user($username);

            db()->prepare('UPDATE users SET sponsor_id = ?, created_at = ? WHERE id = ?')
                ->execute([$sponsor, '2020-01-02 09:00:00', (int) $row['id']]);

            $row['sponsor_id'] = $sponsor;

            return $row;
        };

        $buy = static function (int $userId, float $amount, string $paidAt): void {
            $product = db()->query("SELECT id FROM products WHERE sku = 'MEL-RTY' LIMIT 1")->fetchColumn();

            if ($product === false) {
                db()->prepare(
                    "INSERT INTO products (sku, name, slug, price, pv, stock)
                     VALUES ('MEL-RTY', 'Earnings royalty product', 'earnings-royalty-product', 1.00, 1.00, 1000)"
                )->execute();
                $product = (int) db()->lastInsertId();
            }

            db()->prepare(
                "INSERT INTO shop_orders
                   (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
                    billing_name, shipping_name, shipping_address, paid_at, terms_accepted_at)
                 VALUES (?, ?, 'paid', 'ewallet', ?, 0.00, ?, 'Live buyer', 'Live buyer', 'Live address', ?, ?)"
            )->execute([
                'MEL-' . strtoupper(bin2hex(random_bytes(5))),
                $userId,
                number_format($amount, 2, '.', ''),
                number_format($amount, 2, '.', ''),
                $paidAt,
                $paidAt,
            ]);

            $orderId = (int) db()->lastInsertId();

            db()->prepare(
                "INSERT INTO shop_order_items
                   (order_id, product_id, product_name, quantity, unit_price, line_total, reservation_state)
                 VALUES (?, ?, 'Earnings royalty product', 1, ?, ?, 'committed')"
            )->execute([$orderId, (int) $product, number_format($amount, 2, '.', ''), number_format($amount, 2, '.', '')]);
        };

        $prev    = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());
        [$start] = RoyaltyPeriod::bounds($prev);

        $sup = $place('mel_rty_sup', null);
        $l1  = $place('mel_rty_l1', (int) $sup['id']);
        $l2  = $place('mel_rty_l2', (int) $sup['id']);
        $m1  = $place('mel_rty_m1', (int) $l1['id']);
        $m2  = $place('mel_rty_m2', (int) $l1['id']);
        $place('mel_rty_m3', (int) $l2['id']);
        $place('mel_rty_m4', (int) $l2['id']);

        $buy((int) $sup['id'], 60000.0, $start);
        $buy((int) $m1['id'], 225000.0, $start);
        $buy((int) $m2['id'], 225000.0, $start);

        /* The tab is a query parameter only: `royalty` selects it and an
         * unknown value falls back to the package statement (D-16). */
        $_GET = ['ledger' => 'royalty'];
        assert_same(EarningsController::TAB_ROYALTY, EarningsController::tab());

        $_GET = ['ledger' => 'something-else'];
        assert_same(EarningsController::TAB_PACKAGE, EarningsController::tab());

        $_GET = [];
        assert_same(EarningsController::TAB_PACKAGE, EarningsController::tab());

        /* The tab rides the member's existing earnings surface — no new
         * surface is created for it (plan §9 Rule 8). */
        $id = (int) $sup['id'];

        test_signed_in_as(test_user('mel_rty_sup'));

        $_GET = ['ledger' => 'royalty'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=royalty';

        assert_same('member.earnings:' . $id, Live::earningsSurface($id));

        $controller = new EarningsController();
        $before     = Live::revision(Live::earningsSurface($id));
        $pageBefore = Live::revision(Live::memberDashboardSurface($id));

        /* A real close: evaluate writes the row and must revalidate the
         * member's surfaces; pay credits it and bumps them again. */
        $engine     = new RoyaltyService();
        $evaluation = $engine->evaluate($prev, null);

        assert_true((float) $evaluation['award_total'] > 0, 'the month evaluated an award');
        assert_same($before + 1, Live::revision(Live::earningsSurface($id)), 'evaluate bumps the earnings tab');
        assert_same($pageBefore + 1, Live::revision(Live::memberDashboardSurface($id)), 'evaluate bumps the dashboard');

        $afterEvaluate = Live::revision(Live::earningsSurface($id));
        $payment       = $engine->pay($prev, 500, 20, null);

        assert_true((int) $payment['paid'] >= 1);
        assert_same($afterEvaluate + 1, Live::revision(Live::earningsSurface($id)), 'pay bumps the earnings tab again');

        /* The row is on the tab now. */
        $data    = $controller->royaltyContext($id);
        $payload = $controller->feedPayload(Live::revision(Live::earningsSurface($id)), '"x"', $data);

        assert_same(
            [
                'earnings-royalty-header',
                'earnings-royalty-rank',
                'earnings-royalty-stats',
                'earnings-royalty-rows',
                'earnings-royalty-pagination',
            ],
            array_keys($payload['fragments'])
        );
        assert_contains('data-live-region="earnings-royalty-rank"', $payload['fragments']['earnings-royalty-rank']);
        assert_contains(money(18300.0), $payload['fragments']['earnings-royalty-rows']);
        assert_contains('Paid', $payload['fragments']['earnings-royalty-rows']);

        /* The three tabs never share an ETag, and the Royalty scope
         * carries the team, the ladder configuration and the month. */
        $scope = new ReflectionMethod(EarningsController::class, 'earningsScope');
        $scope->setAccessible(true);

        $_GET = ['ledger' => 'royalty'];
        $royaltyScope = (string) $scope->invoke($controller, $id);

        $_GET = ['ledger' => 'repeat'];
        $repeatScope = (string) $scope->invoke($controller, $id);

        $_GET = [];
        $packageScope = (string) $scope->invoke($controller, $id);

        assert_contains('month:', $royaltyScope);
        assert_contains('team:', $royaltyScope);
        assert_contains('config:', $royaltyScope);
        assert_not_same($royaltyScope, $repeatScope);
        assert_not_same($royaltyScope, $packageScope);
    },

    'the repeat tab revalidates when the member\'s team changes' => function () use ($makeMember): void {        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat';

        $controller = new EarningsController();
        $before     = $controller->repeatContext($id);

        assert_same(0, (int) $before['levels'][1]['team_count']);

        /* The scope is private; the test reads it through reflection
         * because the point is the ETag the browser will revalidate. */
        $scope = new ReflectionMethod(EarningsController::class, 'earningsScope');
        $scope->setAccessible(true);

        $beforeScope = (string) $scope->invoke($controller, $id);
        $beforeEtag  = Live::etag(
            Live::earningsSurface($id),
            Live::revision(Live::earningsSurface($id)),
            $beforeScope
        );

        /* A registration under the member is a team change: the row is
         * written and the shared genealogy revision moves. The earnings
         * surface itself is not bumped — that is exactly why the scope
         * has to carry the team revision. */
        $recruit = test_user('mel_team_' . bin2hex(random_bytes(4)));

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([$id, (int) $recruit['id']]);
        Live::touchGenealogy();

        $afterScope = (string) $scope->invoke($controller, $id);
        $afterEtag  = Live::etag(
            Live::earningsSurface($id),
            Live::revision(Live::earningsSurface($id)),
            $afterScope
        );

        assert_contains('team:', $afterScope, 'the repeat scope carries the team revision');
        assert_not_same($beforeScope, $afterScope);
        assert_not_same($beforeEtag, $afterEtag, 'a team change revalidates the repeat tab');

        $after = $controller->repeatContext($id);

        assert_same(1, (int) $after['levels'][1]['team_count']);
        assert_same((int) $recruit['id'], (int) $after['levels'][1]['members'][0]['id']);

        $payload = $controller->feedPayload(6, $afterEtag, $after);
        $levels  = $payload['fragments']['earnings-repeat-levels'];

        assert_contains('data-live-region="earnings-repeat-levels"', $levels);
        assert_contains('@' . (string) $recruit['username'], $levels);
    },

    /* ==========================================================
     *  P20F-T17 — the fourth tab and its live revision
     * ========================================================== */

    'P20F-T17 an award bumps member.earnings and the Team Harvest tab refreshes' => function () use ($harvest): void {
        $clean   = $harvest['clean'];
        $arm     = $harvest['arm'];
        $account = $harvest['account'];

        $clean();
        $arm();

        /* The member qualifies on their third direct and holds no
         * teammates yet. */
        $root    = $account('mel_p20f_root');
        $directs = [];

        for ($i = 1; $i <= 3; $i++) {
            $directs[$i] = $account('mel_p20f_d' . $i, $root);
        }

        (new TeamHarvestService())->onQualified($root);

        /* Two qualified teammates: n = 2, so level 1 is still open. */
        $qualify = static function (string $prefix, int $sponsor) use ($account): void {
            $member = $account($prefix, $sponsor);

            for ($j = 1; $j <= 3; $j++) {
                $account($prefix . '_d' . $j, $member);
            }

            (new TeamHarvestService())->onQualified($member);
        };

        $qualify('mel_p20f_m1', $directs[1]);
        $qualify('mel_p20f_m2', $directs[1]);

        $service = new TeamHarvestService();
        $status  = $service->statusFor($root);

        assert_same(2, (int) $status['teammates']);
        assert_same(0, (int) $status['credited_levels'], 'no level has paid yet');

        test_signed_in_as(test_user('mel_p20f_root'));

        $_GET = ['ledger' => 'team_harvest'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=team_harvest';

        $controller = new EarningsController();
        $before     = [
            'earnings'  => Live::revision(Live::earningsSurface($root)),
            'dashboard' => Live::revision(Live::memberDashboardSurface($root)),
            'wallet'    => Live::revision(Live::memberWalletSurface($root)),
            'genealogy' => Live::revision(Live::genealogySurface($root)),
        ];

        $otherBefore = Live::revision(Live::earningsSurface($directs[2]));

        /* The third qualified teammate fills level 1: the engine credits
         * the wallet and bumps every Team Harvest surface of the member. */
        $qualify('mel_p20f_m3', $directs[1]);

        assert_same($before['earnings'] + 1, Live::revision(Live::earningsSurface($root)), 'the earnings tab refreshes');
        assert_same($before['genealogy'] + 1, Live::revision(Live::genealogySurface($root)), 'the genealogy badge refreshes');

        /* The wallet credit bumps the dashboard and the statement too (the
         * wallet's own touch), so those two move at least once — the
         * Team Harvest touch would bump them on its own as well. */
        assert_true(
            Live::revision(Live::memberDashboardSurface($root)) > $before['dashboard'],
            'the dashboard tile refreshes'
        );
        assert_true(
            Live::revision(Live::memberWalletSurface($root)) > $before['wallet'],
            'the wallet statement refreshes'
        );
        assert_same($otherBefore, Live::revision(Live::earningsSurface($directs[2])), 'another member is untouched');

        /* The open tab redraws with the paid level. */
        $data    = $controller->teamHarvestContext($root);
        $payload = $controller->feedPayload(Live::revision(Live::earningsSurface($root)), '"x"', $data);

        assert_same(1, $data['pager']->total, 'one level paid');
        assert_same(1000.0, (float) $data['awards'][0]['amount'], 'level 1 is ₱1,000.00');

        assert_same(
            [
                'earnings-team-stats',
                'earnings-team-progress',
                'earnings-team-matrix',
                'earnings-team-header',
                'earnings-team-rows',
                'earnings-team-pagination',
            ],
            array_keys($payload['fragments'])
        );
        assert_contains('data-live-region="earnings-team-matrix"', $payload['fragments']['earnings-team-matrix']);
        assert_contains(money(1000.0), $payload['fragments']['earnings-team-rows']);
        assert_contains('Paid', $payload['fragments']['earnings-team-rows']);
        assert_contains('3 of 3', $payload['fragments']['earnings-team-matrix']);

        /* A replay writes nothing and bumps nothing. */
        $beforeReplay = Live::revision(Live::earningsSurface($root));
        (new TeamHarvestService())->onQualified($root);

        assert_same($beforeReplay, Live::revision(Live::earningsSurface($root)), 'a replay moves no revision');

        /* The other three tabs are still built by the same controller and
         * still carry their own regions — the fourth tab added a branch,
         * not a second page. */
        $_GET = [];
        $package = $controller->earningsContext($root);

        assert_same(EarningsController::TAB_PACKAGE, $package['tab']);
        assert_same(url('earnings/export'), $package['export_url']);

        $_GET = ['ledger' => 'repeat'];
        assert_same(EarningsController::TAB_REPEAT, $controller->repeatContext($root)['tab']);

        $_GET = ['ledger' => 'royalty'];
        assert_same(EarningsController::TAB_ROYALTY, $controller->royaltyContext($root)['tab']);
    },
];
