<?php
declare(strict_types=1);

/* ==============================================================
 *  TransferLiveTest — the live contract of the send-funds page and
 *  the monitor's transfers tab (plan §7 Phase 12, docs/live-updates.md)
 *
 *  Two surfaces:
 *    · `member.transfer:{id}` — the member's page (fragments only);
 *    · `admin.wallets`        — the monitor, shared with the other tabs.
 *
 *  These cases pin that a completed transfer bumps both participants and
 *  the monitor, that a refused one bumps nothing, that the page's ETag
 *  also follows the wallet revision (the balance card), and that the
 *  form never travels in a fragment.
 * ============================================================== */

$makeMember = static function (): array {
    return test_user('tl_' . bin2hex(random_bytes(4)));
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

return [
    'the transfer surface is per member and bumps for both parties' => function () use ($makeMember, $fund, $withSettings): void {
        $withSettings([
            'ewallet_transfer_fee'          => '0',
            'ewallet_min_transfer'          => '50',
            'ewallet_transfer_daily_limit'  => '5000',
            'ewallet_transfer_weekly_limit' => '20000',
        ], function () use ($makeMember, $fund): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];
            $recipientId = (int) $recipient['id'];

            $fund($senderId, '500.00');

            assert_same('member.transfer:' . $senderId, Live::transferSurface($senderId));

            $senderBefore    = Live::revision(Live::transferSurface($senderId));
            $recipientBefore = Live::revision(Live::transferSurface($recipientId));
            $monitorBefore   = Live::revision('admin.wallets');
            $walletBefore    = Live::revision(Live::memberWalletSurface($senderId));

            (new TransferService())->send($senderId, (string) $recipient['username'], '100.00', '', 'Member@123');

            assert_same($senderBefore + 1, Live::revision(Live::transferSurface($senderId)));
            assert_same($recipientBefore + 1, Live::revision(Live::transferSurface($recipientId)), 'the recipient list changed too');
            assert_true(
                Live::revision('admin.wallets') > $monitorBefore,
                'the monitor shows the new transfer (one bump per ledger leg)'
            );
            assert_true(Live::revision(Live::memberWalletSurface($senderId)) > $walletBefore, 'the wallet surfaces moved with the money');

            /* Another member's page is untouched. */
            $bystander = $makeMember();
            assert_same(0, Live::revision(Live::transferSurface((int) $bystander['id'])));
        });
    },

    'a refused transfer bumps no surface' => function () use ($makeMember, $fund, $withSettings): void {
        $withSettings([
            'ewallet_transfer_fee'          => '0',
            'ewallet_min_transfer'          => '50',
            'ewallet_transfer_daily_limit'  => '5000',
            'ewallet_transfer_weekly_limit' => '20000',
        ], function () use ($makeMember, $fund): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];

            $fund($senderId, '100.00');

            $senderBefore    = Live::revision(Live::transferSurface($senderId));
            $recipientBefore = Live::revision(Live::transferSurface((int) $recipient['id']));
            $monitorBefore   = Live::revision('admin.wallets');

            $refusals = [
                ['500.00', 'Member@123'],   /* over the balance */
                ['10.00', 'Member@123'],    /* below the minimum */
                ['100.00', 'wrong'],        /* wrong password */
            ];

            foreach ($refusals as [$amount, $password]) {
                try {
                    (new TransferService())->send($senderId, (string) $recipient['username'], $amount, '', $password);
                } catch (TransferException) {
                    /* expected */
                }
            }

            assert_same($senderBefore, Live::revision(Live::transferSurface($senderId)));
            assert_same($recipientBefore, Live::revision(Live::transferSurface((int) $recipient['id'])));
            assert_same($monitorBefore, Live::revision('admin.wallets'));
        });
    },

    'the transfer feed renders the four regions and keeps the form out of them' => function () use ($makeMember, $fund): void {
        $member  = $makeMember();
        $other   = $makeMember();
        $id      = (int) $member['id'];

        $fund($id, '1000.00');

        (new TransferService())->send($id, (string) $other['username'], '200.00', '<script>alert(6)</script>', 'Member@123');

        test_signed_in_as($member);

        $_GET = ['page' => '1', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/transfer?page=1&per_page=5';

        $controller = new TransferController();
        $context    = $controller->transferContext(Wallet::account($id));
        $payload    = $controller->feedPayload(4, '"member.transfer:' . $id . ':4:abc"', $context);

        assert_true($payload['ok']);
        assert_same('member.transfer:' . $id, $payload['surface']);
        assert_same(4, $payload['revision']);
        assert_same(
            [
                'member-transfer-balance',
                'member-transfer-limits',
                'member-transfer-recent',
                'member-transfer-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $balance = $payload['fragments']['member-transfer-balance'];
        $limits  = $payload['fragments']['member-transfer-limits'];
        $recent  = $payload['fragments']['member-transfer-recent'];
        $pages   = $payload['fragments']['member-transfer-pagination'];

        assert_contains('data-live-region="member-transfer-balance"', $balance);
        assert_contains('Wallet balance', $balance);
        assert_contains(money(800), $balance, '1000 funded − 200 sent');

        assert_contains('data-live-region="member-transfer-limits"', $limits);
        assert_contains('Sent today', $limits);
        assert_contains(money(200), $limits);

        assert_contains('data-live-region="member-transfer-recent"', $recent);
        assert_contains('@' . $other['username'], $recent);
        assert_contains(money(200, true), $recent);
        assert_contains('&lt;script&gt;alert(6)&lt;/script&gt;', $recent);
        assert_not_contains('<script>alert(6)</script>', $recent);

        /* The form (and its password field) is never replaced. */
        foreach ($payload['fragments'] as $fragment) {
            assert_not_contains('name="password"', $fragment);
            assert_not_contains('name="recipient"', $fragment);
            assert_not_contains('name="amount"', $fragment);
        }

        assert_contains('data-live-region="member-transfer-pagination"', $pages);
        assert_contains('Transfers', $pages);
        assert_not_contains('/feed', $pages);
    },

    'the transfer page scope follows the wallet revision as well' => function () use ($makeMember, $fund): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $_GET = [];

        $controller = new TransferController();

        $scopeBefore = $controller->pageScope($id);
        $transferBefore = Live::revision(Live::transferSurface($id));

        /* A commission is not a transfer: the transfer surface stays put,
         * but the balance card must still revalidate. */
        $fund($id, '75.00');

        $scopeAfter = $controller->pageScope($id);

        assert_same($transferBefore, Live::revision(Live::transferSurface($id)), 'no transfer happened');
        assert_not_same($scopeBefore, $scopeAfter, 'the wallet revision is mixed into the scope');

        $etagBefore = Live::etag(Live::transferSurface($id), $transferBefore, $scopeBefore);
        $etagAfter  = Live::etag(Live::transferSurface($id), $transferBefore, $scopeAfter);

        assert_not_same($etagBefore, $etagAfter, 'a changed wallet must change the ETag');
    },

    'the monitor transfers feed renders the tab and its rows' => function () use ($makeMember, $fund): void {
        $admin     = test_user('tl_admin', 'admin');
        $sender    = $makeMember();
        $recipient = $makeMember();
        $senderId  = (int) $sender['id'];

        $fund($senderId, '500.00');

        (new TransferService())->send($senderId, (string) $recipient['username'], '150.00', '<script>alert(2)</script>', 'Member@123');

        test_signed_in_as($admin);

        $_GET = ['tab' => 'transfers', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets?tab=transfers&per_page=5';

        $controller = new AdminWalletController();
        $payload    = $controller->monitorFeedPayload(
            7,
            '"admin.wallets:7:xyz"',
            $controller->monitorContext('transfers')
        );

        assert_same(
            [
                'wallet-metrics',
                'wallet-stats',
                'wallet-tabs',
                'wallet-transfers',
                'wallet-transfers-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $rows  = $payload['fragments']['wallet-transfers'];
        $pages = $payload['fragments']['wallet-transfers-pagination'];
        $stats = $payload['fragments']['wallet-stats'];

        assert_contains('data-live-region="wallet-transfers"', $rows);
        assert_contains('data-live-key=', $rows);
        assert_contains('@' . $sender['username'], $rows);
        assert_contains('@' . $recipient['username'], $rows);
        assert_contains(money(150, true), $rows);
        assert_contains('&lt;script&gt;alert(2)&lt;/script&gt;', $rows);
        assert_not_contains('<script>alert(2)</script>', $rows);

        /* The search form is outside the regions. */
        assert_not_contains('name="q"', $rows);
        assert_not_contains('wallet-search', $rows);

        assert_contains('data-live-region="wallet-transfers-pagination"', $pages);
        assert_contains('name="tab" value="transfers"', $pages);

        assert_contains('Transfers today', $stats);
        assert_contains(money(150), $stats);

        assert_contains('admin/wallets?tab=transfers&per_page=5', $payload['meta']['canonical']);
    },

    'the transfer page declares its surface and loads the form script' => function () use ($makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/transfer';

        $controller = new TransferController();
        $context    = $controller->transferContext(Wallet::account($id));
        $live       = Live::meta(Live::transferSurface($id), $controller->pageScope($id));

        ob_start();

        try {
            View::page('member', 'member/transfer', $context + [
                'page_title'  => 'Send funds',
                'current_nav' => 'transfer',
                'page_css'    => ['assets/css/wallet.css'],
                'page_js'     => ['assets/js/app-transfer.js'],
                'live'        => $live,
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_true($live['enabled']);

        assert_contains('data-live-surface="member.transfer:' . $id . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-revision="' . (int) $live['revision'] . '"', $html);
        assert_contains('data-live-etag="' . e((string) $live['etag']) . '"', $html);
        assert_contains('app-transfer.js', $html, 'the fee preview script is loaded');
        assert_contains('data-transfer-form', $html);
        assert_contains('data-transfer-password-toggle', $html);
    },
];
