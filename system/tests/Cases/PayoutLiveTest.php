<?php
declare(strict_types=1);

/* ==============================================================
 *  PayoutLiveTest — the live contract of the payout page and the
 *  admin queue (plan §7 Phase 13, docs/live-updates.md)
 *
 *  Two surfaces:
 *    · `member.payouts:{id}` — the member's page (four fragments);
 *    · `admin.payouts`       — the queue, shared by every admin tab.
 *
 *  These cases pin that a request or a decision bumps both sides, that a
 *  refusal bumps nothing, that the page's ETag also follows the wallet
 *  revision (the withdrawable card), and that the request region is held
 *  while the member has unsaved input.
 * ============================================================== */

$makeMember = static function (): array {
    return test_user('pl_' . bin2hex(random_bytes(4)));
};

$fund = static function (int $userId, string $amount): void {
    (new WalletService())->credit($userId, $amount, 'commission', null, 'Live fixture');
};

$withSettings = static function (array $values, callable $case): void {
    $before = [];

    foreach ($values as $key => $value) {
        $before[$key] = (string) setting($key, '');
        setting_set($key, (string) $value);
    }

    setting_cache_clear();

    try {
        $case();
    } finally {
        foreach ($before as $key => $value) {
            setting_set($key, $value);
        }

        setting_cache_clear();
    }
};

$defaults = [
    'min_payout'          => '500',
    'service_fee_gcash'   => '0',
    'usdt_rate'           => '0',
    'payout_gcash_enabled' => '1',
    'payout_maya_enabled' => '1',
];

return [
    'the payout surface is per member and bumps for every decision' => function () use ($makeMember, $fund, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($makeMember, $fund): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('pl_admin_' . bin2hex(random_bytes(4)), 'admin');

            $fund($memberId, '2000.00');

            assert_same('member.payouts:' . $memberId, Live::payoutSurface($memberId));

            $memberBefore = Live::revision(Live::payoutSurface($memberId));
            $adminBefore  = Live::revision(Live::ADMIN_PAYOUTS);

            $service = new PayoutService();
            $first   = $service->request($memberId, 'gcash', '09171234567', '600.00');

            assert_same($memberBefore + 1, Live::revision(Live::payoutSurface($memberId)), 'the request bumped the member page');
            assert_same($adminBefore + 1, Live::revision(Live::ADMIN_PAYOUTS), 'and the queue');

            $service->approve((int) $first['payout_id'], (int) $admin['id']);

            assert_same($memberBefore + 2, Live::revision(Live::payoutSurface($memberId)));
            assert_same($adminBefore + 2, Live::revision(Live::ADMIN_PAYOUTS));

            $service->complete((int) $first['payout_id'], (int) $admin['id']);

            assert_same($memberBefore + 3, Live::revision(Live::payoutSurface($memberId)));
            assert_same($adminBefore + 3, Live::revision(Live::ADMIN_PAYOUTS));

            /* A second request, then its rejection: five decisions in all. */
            $second = $service->request($memberId, 'gcash', '09171234567', '600.00');
            $service->reject((int) $second['payout_id'], (int) $admin['id'], 'Test');

            assert_same($memberBefore + 5, Live::revision(Live::payoutSurface($memberId)));
            assert_same($adminBefore + 5, Live::revision(Live::ADMIN_PAYOUTS));

            /* Another member's page is untouched. */
            $bystander = $makeMember();
            assert_same(0, Live::revision(Live::payoutSurface((int) $bystander['id'])));
        });
    },

    'a refused payout request bumps no surface' => function () use ($makeMember, $fund, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($makeMember, $fund): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '100.00');

            $memberBefore = Live::revision(Live::payoutSurface($memberId));
            $adminBefore  = Live::revision(Live::ADMIN_PAYOUTS);

            $refusals = [
                ['gcash', '09171234567', '50.00'],     /* below the minimum */
                ['gcash', '09171234567', '1000.00'],   /* above the withdrawable */
                ['gcash', '12345', '600.00'],          /* malformed account */
                ['palawan', '09171234567', '600.00'],  /* unknown channel */
            ];

            foreach ($refusals as [$method, $account, $amount]) {
                try {
                    (new PayoutService())->request($memberId, $method, $account, $amount);
                } catch (PayoutException) {
                    /* expected */
                }
            }

            assert_same($memberBefore, Live::revision(Live::payoutSurface($memberId)));
            assert_same($adminBefore, Live::revision(Live::ADMIN_PAYOUTS));
        });
    },

    'the payout feed renders the four regions and holds the request region' => function () use ($makeMember, $fund, $withSettings, $defaults): void {
        $withSettings(['service_fee_gcash' => '2'] + $defaults, function () use ($makeMember, $fund): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            $result = (new PayoutService())->request($memberId, 'gcash', '09171234567', '800.00');
            (new PayoutService())->reject((int) $result['payout_id'], (int) test_user('pl_admin2_' . bin2hex(random_bytes(4)), 'admin')['id'], 'Test');

            test_signed_in_as($member);

            $_GET = ['page' => '1', 'per_page' => '5'];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts?page=1&per_page=5';

            $controller = new PayoutController();
            $context    = $controller->payoutContext(Wallet::account($memberId));
            $payload    = $controller->feedPayload(3, '"member.payouts:' . $memberId . ':3:abc"', $context);

            assert_true($payload['ok']);
            assert_same('member.payouts:' . $memberId, $payload['surface']);
            assert_same(3, $payload['revision']);
            assert_same(
                ['payout-balance', 'payout-request', 'payout-history', 'payout-pagination'],
                array_keys($payload['fragments'])
            );

            $balance = $payload['fragments']['payout-balance'];
            $request = $payload['fragments']['payout-request'];
            $history = $payload['fragments']['payout-history'];
            $pages   = $payload['fragments']['payout-pagination'];

            assert_contains('data-live-region="payout-balance"', $balance);
            assert_contains('Withdrawable', $balance);
            assert_contains(money(2000), $balance, 'the rejection refunded the hold');

            /* The request region is held while it has unsaved input; with
             * no open request it renders the form itself. */
            assert_contains('data-live-region="payout-request"', $request);
            assert_contains('data-live-hold="dirty"', $request);
            assert_contains('action="' . url('payouts/request') . '"', $request);
            assert_contains('name="amount"', $request);

            /* The history shows the rejected request and its reason. */
            assert_contains('data-live-region="payout-history"', $history);
            assert_contains('Rejected', $history);
            assert_contains('Test', $history);
            assert_contains(money(800), $history);

            assert_contains('data-live-region="payout-pagination"', $pages);
            assert_contains('payouts?per_page=5', $payload['meta']['canonical']);
        });
    },

    'the payout page scope follows the wallet revision as well' => function () use ($makeMember, $fund): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $_GET = [];

        $controller = new PayoutController();

        $scopeBefore    = $controller->pageScope($id);
        $payoutBefore = Live::revision(Live::payoutSurface($id));

        /* A commission is not a payout: the payout surface stays put, but
         * the withdrawable card must still revalidate. */
        $fund($id, '75.00');

        $scopeAfter = $controller->pageScope($id);

        assert_same($payoutBefore, Live::revision(Live::payoutSurface($id)), 'no payout happened');
        assert_not_same($scopeBefore, $scopeAfter, 'the wallet revision is mixed into the scope');

        $etagBefore = Live::etag(Live::payoutSurface($id), $payoutBefore, $scopeBefore);
        $etagAfter  = Live::etag(Live::payoutSurface($id), $payoutBefore, $scopeAfter);

        assert_not_same($etagBefore, $etagAfter, 'a changed wallet must change the ETag');
    },

    'the payout page also revalidates when the saved payout accounts change' => function () use ($makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $_GET = [];

        $controller = new PayoutController();
        $before     = $controller->pageScope($id);

        /* The profile page saves the GCash/Maya/USDT accounts the request
         * form prefills, and it bumps `member.profile:{id}`. */
        Live::touchProfile($id);

        assert_not_same($before, $controller->pageScope($id), 'a saved payout account refreshes the prefilled form');
    },

    'the payout settings fingerprint reaches both payout surfaces' => function () use ($makeMember, $withSettings, $defaults): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $_GET = [];

        $withSettings($defaults, function () use ($id): void {
            $page  = new PayoutController();
            $queue = new AdminPayoutController();

            $fingerprint = PayoutService::settingsFingerprint();
            $pageBefore  = $page->pageScope($id);
            $queueBefore = $queue->queueScope(99);
            $etagBefore  = Live::etag(Live::payoutSurface($id), 0, $pageBefore);

            /* A setting the payout pages do not print must not churn them. */
            setting_set('seller_name', 'Other Seller');
            setting_cache_clear();

            assert_same($fingerprint, PayoutService::settingsFingerprint(), 'an unrelated setting is not in the fingerprint');
            assert_same($pageBefore, $page->pageScope($id));
            assert_same($queueBefore, $queue->queueScope(99));

            /* Every withdrawal rule moves it — the USDT ones included. */
            $changes = [
                'usdt_rate'               => '56',
                'min_payout'              => '250',
                'service_fee_usdt_trc20'  => '7.5',
                'usdt_trc20_gas_fee'      => '3',
                'payout_usdt_bep20_enabled' => '0',
            ];

            foreach ($changes as $key => $value) {
                $previous = PayoutService::settingsFingerprint();

                setting_set($key, $value);
                setting_cache_clear();

                assert_not_same($previous, PayoutService::settingsFingerprint(), $key . ' must move the fingerprint');
            }

            assert_not_same($pageBefore, $page->pageScope($id), 'the page ETag scope moved');
            assert_not_same($queueBefore, $queue->queueScope(99), 'the queue ETag scope moved');
            assert_not_same(
                $etagBefore,
                Live::etag(Live::payoutSurface($id), 0, $page->pageScope($id)),
                'the page ETag moved'
            );
        });
    },

    'a USDT settings change reaches the open payout page without a reload' => function () use ($makeMember, $fund, $withSettings, $defaults): void {
        $withSettings(['usdt_rate' => '0'] + $defaults, function () use ($makeMember, $fund): void {
            $member = $makeMember();
            $id     = (int) $member['id'];

            $fund($id, '2000.00');

            test_signed_in_as($member);

            $_GET = [];

            $controller = new PayoutController();
            $account    = Wallet::account($id);
            $closed     = $controller->feedPayload(0, '"x"', $controller->payoutContext($account));

            assert_contains(
                'Unavailable until the ₱ per USDT rate is set.',
                $closed['fragments']['payout-request'],
                'both USDT channels explain themselves while the rate is 0'
            );
            assert_not_contains('USDT rate', $closed['fragments']['payout-balance']);

            /* The admin sets the rate and a fee on the settings card. */
            setting_set('usdt_rate', '56');
            setting_set('service_fee_usdt_trc20', '5');
            setting_cache_clear();

            $open = $controller->feedPayload(0, '"x"', $controller->payoutContext($account));

            assert_not_contains('Unavailable until the ₱ per USDT rate is set.', $open['fragments']['payout-request']);
            assert_contains('data-fee-pct="5"', $open['fragments']['payout-request'], 'the new fee is in the form');
            assert_contains('USDT rate', $open['fragments']['payout-balance']);
            assert_contains('0.0179', $open['fragments']['payout-balance'], '₱1.00 = 1/56 USDT');
        });
    },

    'the queue feed renders the stats, tabs and rows' => function () use ($makeMember, $fund, $withSettings, $defaults): void {
        $withSettings(['service_fee_gcash' => '2'] + $defaults, function () use ($makeMember, $fund): void {
            $admin  = test_user('pl_queue_admin', 'admin');
            $member = $makeMember();

            $fund((int) $member['id'], '3000.00');

            (new PayoutService())->request((int) $member['id'], 'gcash', '09171234567', '800.00');

            test_signed_in_as($admin);

            $_GET = ['status' => 'pending', 'per_page' => '5'];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/payouts?status=pending&per_page=5';

            $controller = new AdminPayoutController();
            $payload    = $controller->feedPayload(7, '"admin.payouts:7:xyz"', $controller->queueContext('pending'));

            assert_same(
                ['payout-stats', 'payout-tabs', 'payout-rows', 'payout-pagination'],
                array_keys($payload['fragments'])
            );

            $stats  = $payload['fragments']['payout-stats'];
            $tabs   = $payload['fragments']['payout-tabs'];
            $rows   = $payload['fragments']['payout-rows'];
            $pages  = $payload['fragments']['payout-pagination'];

            assert_contains('data-live-region="payout-stats"', $stats);
            assert_contains('Held in open requests', $stats);
            assert_contains(money(800), $stats);

            assert_contains('data-live-region="payout-tabs"', $tabs);
            assert_contains('All requests', $tabs);

            assert_contains('data-live-region="payout-rows"', $rows);
            assert_contains('data-live-hold="dirty"', $rows);
            assert_contains('data-live-key=', $rows);
            assert_contains('@' . $member['username'], $rows);
            assert_contains(money(800), $rows);
            assert_contains(money(16), $rows);

            /* The search form is outside the regions. */
            assert_not_contains('name="q"', $rows);
            assert_not_contains('wallet-search', $rows);

            assert_contains('data-live-region="payout-pagination"', $pages);
            assert_contains('name="status" value="pending"', $pages);

            assert_contains('admin/payouts?status=pending&per_page=5', $payload['meta']['canonical']);
        });
    },

    'the payout page declares its surface and loads the form script' => function () use ($makeMember, $fund): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $fund($id, '2000.00');

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

        $controller = new PayoutController();
        $context    = $controller->payoutContext(Wallet::account($id));
        $live       = Live::meta(Live::payoutSurface($id), $controller->pageScope($id));

        ob_start();

        try {
            View::page('member', 'member/payouts', $context + [
                'page_title'  => 'Payouts',
                'current_nav' => 'payouts',
                'page_css'    => ['assets/css/wallet.css'],
                'page_js'     => ['assets/js/app-payout.js'],
                'live'        => $live,
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_true($live['enabled']);

        assert_contains('data-live-surface="member.payouts:' . $id . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-revision="' . (int) $live['revision'] . '"', $html);
        assert_contains('data-live-etag="' . e((string) $live['etag']) . '"', $html);
        assert_contains('data-live-config="config"', $html, 'the settings broadcast reaches this page');
        assert_contains('data-live-stale', $html, 'a held request card is announced');
        assert_contains('app-payout.js', $html, 'the fee preview script is loaded');
        assert_contains('data-payout-form', $html);
    },
];
