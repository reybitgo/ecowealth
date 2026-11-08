<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberDashboardLiveTest — the member's own dashboard
 *
 *  The dashboard is a per-member live surface (`member.dashboard:{id}`)
 *  with six regions: the header status pill, the KPI row, the E-wallet card,
 *  the membership card, the recent-orders card and the earnings card.
 *
 *  A wallet movement, an order write for this buyer, an activation
 *  request/decision, a status change and a new downline line must all
 *  reach an open dashboard without a reload. Admins at /dashboard keep
 *  the admin.dashboard surface.
 * ============================================================== */

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

    'the member dashboard feed renders the six regions' => function () use ($makeMember): void {
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
            ],
            array_keys($payload['fragments'])
        );

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

    'the member dashboard page declares its surface and six regions' => function () use ($makeMember, $render): void {
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
