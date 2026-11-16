<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberDashboardLiveTest — the member's own dashboard
 *
 *  The dashboard is a per-member live surface (`member.dashboard:{id}`)
 *  with eight regions: the header status pill, the KPI row, the E-wallet
 *  card, the membership card, the recent-orders card, the earnings card
 *  and (Phase 19E) the Royalty tile, plus (Phase 20F) the Team Harvest
 *  tile.
 *
 *  A wallet movement, an order write for this buyer, an activation
 *  request/decision, a status change and a new downline line must all
 *  reach an open dashboard without a reload. Admins at /dashboard keep
 *  the admin.dashboard surface.
 * ============================================================== */

$harvest = require __DIR__ . '/../fixtures/harvest_money.php';

$makeMember = static function (string $username = ''): array {
    return test_user($username !== '' ? $username : 'md_' . bin2hex(random_bytes(4)));
};

/** A pending member with no package, ready to request an activation. */
$pendingMember = static function (): array {
    $username = 'mdl_' . bin2hex(random_bytes(4));

    $result = (new RegistrationService())->register([
        'mode'             => 'pending',
        'username'         => $username,
        'email'            => $username . '@test.local',
        'full_name'        => 'Dashboard Live Member',
        'phone'            => '',
        'password'         => 'Member@1234',
        'password_confirm' => 'Member@1234',
        'sponsor'          => 'juan',
        'package_id'       => '',
        'reg_code'         => '',
        'terms'            => true,
    ], null);

    return Member::find((int) $result['user_id']) ?? [];
};

$proofFile = static function (): array {
    $tmp = (string) sys_get_temp_dir() . '/eco-mdl-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($tmp, TEST_PNG_BYTES);

    return [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ];
};

$render = static function (string $layout, string $template, array $data = []): string {
    ob_start();

    try {
        View::page($layout, $template, $data);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

return [
    'the member dashboard surface is per user and bumps on wallet and order writes' => function () use ($makeMember): void {
        $member  = $makeMember();
        $other   = $makeMember();
        $admin   = test_user('md_admin', 'admin');
        $id      = (int) $member['id'];
        $otherId = (int) $other['id'];
        $adminId = (int) $admin['id'];
        $wallet  = new WalletService();

        assert_same('member.dashboard:' . $id, Live::memberDashboardSurface($id));

        $before      = Live::revision(Live::memberDashboardSurface($id));
        $otherBefore = Live::revision(Live::memberDashboardSurface($otherId));

        $wallet->credit($id, '100.00', 'commission', 8801, 'Direct referral');

        assert_same($before + 1, Live::revision(Live::memberDashboardSurface($id)), 'credit');
        assert_same($otherBefore, Live::revision(Live::memberDashboardSurface($otherId)), 'another member is untouched');

        /* A replayed movement moves no money and must not bump. */
        $before = Live::revision(Live::memberDashboardSurface($id));
        $replay = $wallet->credit($id, '100.00', 'commission', 8801, 'Direct referral');

        assert_true($replay['replayed']);
        assert_same($before, Live::revision(Live::memberDashboardSurface($id)));

        /* The reported case: an admin top-up is visible on the member tab. */
        $before = Live::revision(Live::memberDashboardSurface($id));
        $wallet->adminTopUp($adminId, $id, '25.00', 'Top-up');

        assert_same($before + 1, Live::revision(Live::memberDashboardSurface($id)), 'admin top-up');

        $before = Live::revision(Live::memberDashboardSurface($id));
        $wallet->refund($id, '10.00', 8802, 'Refund');

        assert_same($before + 1, Live::revision(Live::memberDashboardSurface($id)), 'refund');

        /* The recent-orders card reads the buyer's order rows: every order
         * write for this buyer bumps the dashboard. */
        $orderId = test_order($id);

        $before = Live::revision(Live::memberDashboardSurface($id));
        Live::touchOrder($orderId, $id);
        assert_same($before + 1, Live::revision(Live::memberDashboardSurface($id)), 'order write');

        /* Another buyer's order must not touch this dashboard. */
        $before = Live::revision(Live::memberDashboardSurface($id));
        Live::touchOrder($orderId, $otherId);
        assert_same($before, Live::revision(Live::memberDashboardSurface($id)), 'another buyer');

        /* The membership card and KPI follow the member status. */
        $before = Live::revision(Live::memberDashboardSurface($id));
        Member::setStatus($id, 'suspended');
        assert_same($before + 1, Live::revision(Live::memberDashboardSurface($id)), 'status change');
    },

    'activation requests and decisions bump the dashboard' => function () use ($pendingMember, $proofFile): void {
        $member = $pendingMember();
        $admin  = test_user('md_activation_admin', 'admin');
        $id     = (int) $member['id'];

        $before = Live::revision(Live::memberDashboardSurface($id));

        $request = (new ActivationService())->request($id, [
            'method'       => 'manual',
            'package_id'   => (string) Package::findBySlug('package-a')['id'],
            'channel'      => 'gcash',
            'reference_no' => 'GC-MDL-' . bin2hex(random_bytes(3)),
        ], $proofFile());

        assert_same($before + 1, Live::revision(Live::memberDashboardSurface($id)), 'activation request');

        $before = Live::revision(Live::memberDashboardSurface($id));

        (new ActivationService())->approve((int) $request['activation_id'], $admin);

        assert_same($before + 1, Live::revision(Live::memberDashboardSurface($id)), 'activation approval');
    },

    'a rejected activation bumps the dashboard' => function () use ($pendingMember, $proofFile): void {
        $member = $pendingMember();
        $admin  = test_user('md_reject_admin', 'admin');
        $id     = (int) $member['id'];

        $request = (new ActivationService())->request($id, [
            'method'       => 'manual',
            'package_id'   => (string) Package::findBySlug('package-a')['id'],
            'channel'      => 'gcash',
            'reference_no' => 'GC-MDJ-' . bin2hex(random_bytes(3)),
        ], $proofFile());

        $before = Live::revision(Live::memberDashboardSurface($id));

        (new ActivationService())->reject((int) $request['activation_id'], $admin, 'The reference does not match.');

        assert_same($before + 1, Live::revision(Live::memberDashboardSurface($id)), 'activation rejection');
    },

    'the member dashboard feed renders the eight regions' => function () use ($makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet = new WalletService();
        $wallet->credit($id, '1250.50', 'commission', 7801, 'Earnings');
        $wallet->credit($id, '250.00', 'topup', 7802, 'Spend-only', false);

        test_signed_in_as($member);

        $controller = new DashboardController();
        $payload    = $controller->feedPayload(
            7,
            '"member.dashboard:' . $id . ':7:abc"',
            $controller->dashboardContext($id)
        );

        assert_true($payload['ok']);
        assert_same('member.dashboard:' . $id, $payload['surface']);
        assert_same(7, $payload['revision']);
        assert_same(
            [
                'dashboard-header',
                'dashboard-kpis',
                'dashboard-wallet',
                'dashboard-membership',
                'dashboard-orders',
                'dashboard-earnings',
                'dashboard-royalty',
                'dashboard-team-harvest',
            ],
            array_keys($payload['fragments'])
        );

        /* The Royalty tile is hidden for a member with no history while
         * the stream is off — the region renders nothing (plan §9 Rule 5). */
        assert_same('', $payload['fragments']['dashboard-royalty'], 'no Royalty tile for a brand-new member');

        /* The Team Harvest tile follows the same rule: off, not queued and
         * no history means nothing to say yet (20F's tile rule). */
        assert_same('', $payload['fragments']['dashboard-team-harvest'], 'no Team Harvest tile for a brand-new member');

        $header = $payload['fragments']['dashboard-header'];
        assert_contains('data-live-region="dashboard-header"', $header);
        assert_contains('Standard member', $header);
        assert_contains('href="' . url('shop') . '"', $header);

        $kpis = $payload['fragments']['dashboard-kpis'];
        assert_contains('data-live-region="dashboard-kpis"', $kpis);
        assert_contains('Membership', $kpis);
        assert_contains('E-wallet', $kpis);
        assert_contains('Commissions', $kpis);
        assert_contains('Team', $kpis);
        assert_contains(money(1500.5), $kpis);
        assert_contains(money(1250.5) . ' withdrawable', $kpis);
        assert_not_contains('data-live-region="dashboard-wallet-kpi"', $kpis);

        $walletCard = $payload['fragments']['dashboard-wallet'];
        assert_contains('data-live-region="dashboard-wallet"', $walletCard);
        assert_contains('Total balance', $walletCard);
        assert_contains(money(1500.5), $walletCard);
        assert_contains(money(250) . ' of your balance is spend-only', $walletCard);
        assert_contains('href="' . url('wallet') . '"', $walletCard);
        assert_not_contains('name="_csrf"', $walletCard, 'the wallet card renders no form');

        $membership = $payload['fragments']['dashboard-membership'];
        assert_contains('data-live-region="dashboard-membership"', $membership);
        assert_contains('Member since', $membership);
        assert_contains('Activation requests', $membership);
        assert_contains('href="' . url('membership') . '"', $membership);

        $orders = $payload['fragments']['dashboard-orders'];
        assert_contains('data-live-region="dashboard-orders"', $orders);
        assert_contains('Recent orders', $orders);
        assert_contains('No orders yet', $orders);

        $earnings = $payload['fragments']['dashboard-earnings'];
        assert_contains('data-live-region="dashboard-earnings"', $earnings);
        assert_contains('Total credited', $earnings);
        assert_contains('No commissions yet', $earnings);

        assert_same(url('dashboard'), $payload['meta']['canonical']);
    },

    'the member dashboard feed shows the newest order and the open request' => function () use ($makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $orderId = test_order($id);
        $stmt    = db()->prepare('SELECT order_no FROM shop_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $orderNo = (string) $stmt->fetchColumn();

        db()->prepare("UPDATE users SET member_status = 'pending' WHERE id = ?")->execute([$id]);

        Activation::create([
            'user_id'        => $id,
            'package_id'     => (int) Package::findBySlug('package-a')['id'],
            'amount'         => 1000.00,
            'payment_method' => 'gcash',
            'status'         => 'pending_review',
            'reference_no'   => 'MD-REF',
        ]);

        $member = Member::find($id);
        assert_not_null($member);

        test_signed_in_as($member);

        $controller = new DashboardController();
        $payload    = $controller->feedPayload(
            8,
            '"member.dashboard:' . $id . ':8:abc"',
            $controller->dashboardContext($id)
        );

        $orders = $payload['fragments']['dashboard-orders'];
        assert_contains($orderNo, $orders);
        assert_contains('1 open', $orders);

        $membership = $payload['fragments']['dashboard-membership'];
        assert_contains('Your account is waiting for activation', $membership);
        assert_contains('pending review', $membership);

        /* The KPI row follows the status: no package yet. */
        assert_contains('Pending activation', $payload['fragments']['dashboard-kpis']);
    },

    'the member dashboard page declares its surface and regions' => function () use ($makeMember, $render): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $controller = new DashboardController();

        $html = $render('member', 'member/dashboard', $controller->dashboardContext($id) + [
            'live' => Live::meta(Live::memberDashboardSurface($id), 'page'),
        ]);

        assert_contains('data-live-surface="member.dashboard:' . $id . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="dashboard-header"', $html);
        assert_contains('data-live-region="dashboard-kpis"', $html);
        assert_contains('data-live-region="dashboard-wallet"', $html);
        assert_contains('data-live-region="dashboard-membership"', $html);
        assert_contains('data-live-region="dashboard-orders"', $html);
        assert_contains('data-live-region="dashboard-earnings"', $html);
        assert_contains('data-live-status', $html);

        /* Static parts stay outside the regions. */
        assert_contains('Welcome back', $html);
        assert_contains('Your referral link', $html);
        assert_contains('data-copy-tooltip="Copy link"', $html);
    },

    /* ==========================================================
     *  P19E-T13 — the Royalty tile
     * ========================================================== */

    'P19E-T13 the Royalty tile shows the rank, the month against the quota and the paid total' => function () use ($render): void {
        /* The small 2/2 ladder: a Supervisor without a 111-member tree
         * (RoyaltyMemberViewTest builds the reference one). */
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

        $place = static function (string $username, ?int $sponsor): array {
            $row = test_user($username);

            if ($sponsor !== null) {
                db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
                    ->execute([$sponsor, (int) $row['id']]);
                $row['sponsor_id'] = $sponsor;
            }

            return $row;
        };

        /** One paid order with a real line total, inside the open month. */
        $buy = static function (int $userId, float $amount): void {
            $product = db()->query("SELECT id FROM products WHERE sku = 'MDJ-RTY' LIMIT 1")->fetchColumn();

            if ($product === false) {
                db()->prepare(
                    "INSERT INTO products (sku, name, slug, price, pv, stock)
                     VALUES ('MDJ-RTY', 'Dashboard royalty product', 'dashboard-royalty-product', 1.00, 1.00, 1000)"
                )->execute();
                $product = (int) db()->lastInsertId();
            }

            db()->prepare(
                "INSERT INTO shop_orders
                   (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
                    billing_name, shipping_name, shipping_address, paid_at, terms_accepted_at)
                 VALUES (?, ?, 'paid', 'ewallet', ?, 0.00, ?, 'Tile buyer', 'Tile buyer', 'Tile address', ?, ?)"
            )->execute([
                'MDJ-' . strtoupper(bin2hex(random_bytes(5))),
                $userId,
                number_format($amount, 2, '.', ''),
                number_format($amount, 2, '.', ''),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]);

            $orderId = (int) db()->lastInsertId();

            db()->prepare(
                "INSERT INTO shop_order_items
                   (order_id, product_id, product_name, quantity, unit_price, line_total, reservation_state)
                 VALUES (?, ?, 'Dashboard royalty product', 1, ?, ?, 'committed')"
            )->execute([$orderId, (int) $product, number_format($amount, 2, '.', ''), number_format($amount, 2, '.', '')]);
        };

        $sup = $place('mdj_rty_sup', null);
        $l1  = $place('mdj_rty_l1', (int) $sup['id']);
        $l2  = $place('mdj_rty_l2', (int) $sup['id']);
        $m1  = $place('mdj_rty_m1', (int) $l1['id']);
        $m2  = $place('mdj_rty_m2', (int) $l1['id']);
        $place('mdj_rty_m3', (int) $l2['id']);
        $place('mdj_rty_m4', (int) $l2['id']);

        /* 510,000 of group sales, 60,000 of them the member's own. */
        $buy((int) $sup['id'], 60000.0);
        $buy((int) $m1['id'], 225000.0);
        $buy((int) $m2['id'], 225000.0);

        /* One paid month so the tile has an all-time figure and a "last
         * paid" label. */
        $prev        = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());
        [$start]     = RoyaltyPeriod::bounds($prev);

        db()->prepare(
            'INSERT INTO royalty_periods
               (period, status, enabled, evaluated_at, as_of, leader_directs, supervisor_leaders,
                manager_supervisors, director_managers, quota_supervisor, quota_manager, quota_director,
                group_pct_supervisor, personal_pct_supervisor, group_pct_manager, personal_pct_manager,
                group_pct_director, personal_pct_director, award_count, paid_count, award_total, paid_total)
             VALUES (?, \'paid\', 1, ?, ?, 2, 2, 2, 2, 500000.00, 1000000.00, 5000000.00,
                     3.000, 5.000, 5.000, 10.000, 10.000, 15.000, 1, 1, 18300.00, 18300.00)'
        )->execute([$prev, $start, $start]);

        $awardId = RoyaltyAward::create([
            'period'          => $prev,
            'user_id'         => (int) $sup['id'],
            'rank_level'      => RoyaltyRank::SUPERVISOR,
            'directs'         => 2,
            'downline_rank_count' => 2,
            'team_size'       => 7,
            'group_volume'    => 510000.0,
            'personal_volume' => 60000.0,
            'quota'           => 500000.0,
            'group_pct'       => 3.0,
            'personal_pct'    => 5.0,
            'amount'          => 18300.0,
            'status'          => 'pending',
        ]);

        RoyaltyAward::markPaid($awardId, 777001);

        test_signed_in_as(test_user('mdj_rty_sup'));

        $controller = new DashboardController();
        $data       = $controller->dashboardContext((int) $sup['id']);
        $royalty    = $data['royalty'];

        assert_not_null($royalty);
        assert_same(RoyaltyRank::SUPERVISOR, $royalty['rank']);
        assert_same('Supervisor', $royalty['rank_name']);
        assert_same(510000.0, $royalty['group']);
        assert_same(500000.0, $royalty['quota']);
        assert_same(18300.0, $royalty['paid_all_time']);
        assert_same(18300.0, $royalty['projected'], 'the same month, if it closed now');

        $tile = $controller->feedPayload(9, '"member.dashboard:' . (int) $sup['id'] . ':9:t13"', $data)['fragments']['dashboard-royalty'];

        assert_contains('data-live-region="dashboard-royalty"', $tile);
        assert_contains('Royalty', $tile);
        assert_contains('Supervisor', $tile);
        assert_contains(money(510000.0), $tile, 'this month’s group sales');
        assert_contains(money(500000.0), $tile, 'the quota');
        assert_contains('group this month', $tile);
        assert_contains('quota met', $tile);
        assert_contains('If this month closed now', $tile);
        assert_contains(money(18300.0), $tile);
        assert_contains('Paid all-time', $tile);
        assert_contains(date('M Y', (int) strtotime($prev . '-01')), $tile, 'the last paid month');
        assert_contains('href="' . url('earnings?ledger=royalty') . '"', $tile);

        /* The page renders the same region, outside the KPI row. */
        $html = $render('member', 'member/dashboard', $data + [
            'live' => Live::meta(Live::memberDashboardSurface((int) $sup['id']), 'page'),
        ]);

        assert_contains('data-live-region="dashboard-royalty"', $html);
        assert_contains('quota met', $html);

        /* Off with no history: the tile disappears; off with history: it
         * stays with the "off" note (plan §9 Rule 5). */
        setting_set('royalty_enabled', '0');
        setting_cache_clear();
        setting_all();

        $fresh = $place('mdj_rty_fresh', null);

        test_signed_in_as($fresh);

        $emptyPayload = $controller->feedPayload(10, '"x"', $controller->dashboardContext((int) $fresh['id']));

        assert_same('', $emptyPayload['fragments']['dashboard-royalty'], 'hidden when off and no history');

        $historyPayload = $controller->feedPayload(11, '"x"', $controller->dashboardContext((int) $sup['id']));
        $historyTile    = $historyPayload['fragments']['dashboard-royalty'];

        assert_contains('data-live-region="dashboard-royalty"', $historyTile);
        assert_contains('Royalty is currently off', $historyTile);
        assert_contains('href="' . url('earnings?ledger=royalty') . '"', $historyTile);
        assert_not_contains('If this month closed now', $historyTile, 'no projection while the stream is off');
    },

    /* ==========================================================
     *  P20F-T10 — the Team Harvest tile
     * ========================================================== */

    'P20F-T10 the Team Harvest tile shows the paid total and the next level without a form' => function () use ($harvest, $render): void {
        $clean   = $harvest['clean'];
        $arm     = $harvest['arm'];
        $grow    = $harvest['grow'];
        $enable  = $harvest['enable'];
        $account = $harvest['account'];

        $clean();
        $arm();

        $tree = $grow(3, 'mdj_p20f', new TeamHarvestService());
        $root = $tree['root'];

        test_signed_in_as(test_user('mdj_p20f_root'));

        $controller = new DashboardController();
        $data       = $controller->dashboardContext($root);

        assert_not_null($data['team_harvest']);
        assert_same(true, $data['team_harvest']['queued']);
        assert_same(39, (int) $data['team_harvest']['teammates']);
        assert_same(7000.0, (float) $data['team_harvest']['paid_amount']);
        assert_same(4, (int) $data['team_harvest']['next']['level']);
        assert_same(0, (int) $data['team_harvest']['next']['filled']);
        assert_same(81, (int) $data['team_harvest']['next']['slots']);

        $tile = $controller->feedPayload(
            10,
            '"member.dashboard:' . $root . ':10:t10"',
            $data
        )['fragments']['dashboard-team-harvest'];

        assert_contains('data-live-region="dashboard-team-harvest"', $tile);
        assert_contains('Team Harvest', $tile);
        assert_contains('Harvest position #' . (int) $data['team_harvest']['position'], $tile);
        assert_contains('Earned', $tile);
        assert_contains(money(7000.0), $tile, 'the paid total');
        assert_contains('Level 4', $tile, 'the next level');
        assert_contains('0 of 81', $tile, 'its progress');
        assert_contains(money(6000.0), $tile, 'its amount');
        assert_contains('href="' . url('earnings?ledger=team_harvest') . '"', $tile);

        /* A read-out: no form, no CSRF field, no button that posts. */
        assert_not_contains('<form', $tile);
        assert_not_contains('name="_csrf"', $tile);
        assert_not_contains('method="POST"', $tile);

        /* The page renders the same region. */
        $html = $render('member', 'member/dashboard', $data + [
            'live' => Live::meta(Live::memberDashboardSurface($root), 'page'),
        ]);

        assert_contains('data-live-region="dashboard-team-harvest"', $html);
        assert_contains(money(7000.0), $html);

        /* With the stream off and no position or history, the tile is
         * gone entirely (the Royalty tile's rule). */
        $enable(false);

        $fresh = $account('mdj_p20f_fresh');

        test_signed_in_as(test_user('mdj_p20f_fresh'));

        $empty = $controller->feedPayload(11, '"x"', $controller->dashboardContext($fresh));

        assert_same('', $empty['fragments']['dashboard-team-harvest'], 'hidden when off with no position');
    },

    'admins keep the admin dashboard surface at /dashboard' => function (): void {
        test_signed_in_as(test_user('md_admin', 'admin'));

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/dashboard';
        unset($_SERVER['HTTP_X_LIVE']);

        ob_start();

        try {
            (new DashboardController())->index();
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.dashboard"', $html);
        assert_not_contains('data-live-surface="member.dashboard:', $html);
    },
];
