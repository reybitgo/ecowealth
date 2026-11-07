<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberDashboardLiveTest — the member's own dashboard
 *
 *  The dashboard is a per-member live surface (`member.dashboard:{id}`)
 *  whose only live regions are the E-wallet KPI and the E-wallet card.
 *  A wallet movement by anyone — an admin top-up, a commission, a
 *  refund — must bump it so an open member tab refreshes without a
 *  reload. Admins at /dashboard keep the admin.dashboard surface.
 * ============================================================== */

$makeMember = static function (string $username = ''): array {
    return test_user($username !== '' ? $username : 'md_' . bin2hex(random_bytes(4)));
};

/** View data for the member dashboard, as DashboardController builds it. */
$dashboardData = static function (array $member): array {
    $id     = (int) $member['id'];
    $wallet = new WalletService();

    return [
        'page_title'       => 'Dashboard',
        'current_nav'      => 'dashboard',
        'page_css'         => ['assets/css/membership.css', 'assets/css/orders.css'],
        'member'           => Member::find($id),
        'balance'          => $wallet->balance($id),
        'withdrawable'     => $wallet->withdrawable($id),
        'orders'           => [],
        'order_stats'      => ['total' => 0, 'open' => 0, 'completed' => 0, 'spent' => 0.0],
        'commissions'      => ['count' => 0, 'credited' => 0.0, 'this_month' => 0.0, 'last_at' => null],
        'team'             => ['direct' => 0, 'team' => 0, 'levels' => 0],
        'open_activation'  => null,
        'last_activation'  => null,
        'activation_count' => 0,
        'referral_code'    => (string) $member['referral_code'],
        'referral_link'    => absolute_url('register?ref=' . rawurlencode((string) $member['referral_code'])),
        'recent_history'   => [],
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
    'the member dashboard surface is per user and bumps on wallet movements' => function () use ($makeMember): void {
        $member = $makeMember();
        $other  = $makeMember();
        $admin  = test_user('md_admin', 'admin');
        $id     = (int) $member['id'];
        $otherId = (int) $other['id'];
        $adminId = (int) $admin['id'];
        $wallet  = new WalletService();

        assert_same('member.dashboard:' . $id, Live::memberDashboardSurface($id));

        $before = Live::revision(Live::memberDashboardSurface($id));
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
    },

    'the member dashboard feed renders both wallet regions' => function (): void {
        $account = [
            'id'                   => 77,
            'username'             => 'feed_member',
            'ewallet_balance'      => '1250.50',
            'withdrawable_balance' => '1000.00',
        ];

        $payload = (new DashboardController())->feedPayload(
            7,
            '"member.dashboard:77:7:abc"',
            $account
        );

        assert_true($payload['ok']);
        assert_same('member.dashboard:77', $payload['surface']);
        assert_same(7, $payload['revision']);
        assert_same(
            ['dashboard-wallet-kpi', 'dashboard-wallet'],
            array_keys($payload['fragments'])
        );

        $kpi  = $payload['fragments']['dashboard-wallet-kpi'];
        $card = $payload['fragments']['dashboard-wallet'];

        assert_contains('data-live-region="dashboard-wallet-kpi"', $kpi);
        assert_contains('E-wallet', $kpi);
        assert_contains(money(1250.5), $kpi);
        assert_contains(money(1000) . ' withdrawable', $kpi);

        assert_contains('data-live-region="dashboard-wallet"', $card);
        assert_contains('Total balance', $card);
        assert_contains(money(1250.5), $card);
        assert_contains(money(1000), $card);
        assert_contains(money(250.5) . ' of your balance is spend-only', $card);
        assert_contains('href="' . url('wallet') . '"', $card);
        assert_not_contains('name="_csrf"', $card, 'the wallet card renders no form');
        assert_same(url('dashboard'), $payload['meta']['canonical']);
    },

    'the member dashboard page declares its live surface and wallet regions' => function () use ($dashboardData, $render, $makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet = new WalletService();
        $wallet->credit($id, '300.00', 'commission', 8901, 'Earnings');
        $wallet->credit($id, '50.00', 'topup', 8902, 'Spend-only', false);

        test_signed_in_as($member);

        $html = $render('member', 'member/dashboard', $dashboardData($member) + [
            'live' => Live::meta(Live::memberDashboardSurface($id), 'page'),
        ]);

        assert_contains('data-live-surface="member.dashboard:' . $id . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="dashboard-wallet-kpi"', $html);
        assert_contains('data-live-region="dashboard-wallet"', $html);
        assert_contains('data-live-status', $html);

        /* The static KPI cards still render in order around the live one. */
        assert_contains('Membership', $html);
        assert_contains('E-wallet', $html);
        assert_contains('Commissions', $html);
        assert_contains('Team', $html);
        assert_contains(money(350), $html);
        assert_contains(money(50) . ' of your balance is spend-only', $html);
        assert_contains('href="' . url('wallet') . '"', $html);
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
