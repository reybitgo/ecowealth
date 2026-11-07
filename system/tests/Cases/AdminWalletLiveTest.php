<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminWalletLiveTest — the admin.wallets monitor and one wallet
 *
 *  Two live surfaces: `admin.wallets` for the three-tab monitor and
 *  `admin.wallets:{id}` for one member's wallet. These cases pin:
 *
 *    · every balance movement bumps both surfaces (a replay does not);
 *    · a member status change bumps the directory and the detail page;
 *    · the monitor feed returns the shared regions plus the active tab,
 *      keeps search filters out of the result fragments and keeps the
 *      tab/pagination URLs;
 *    · the detail feed renders the buckets, statement, top-up form and
 *      history, escapes every text value and marks the form cards for
 *      the dirty-input guard;
 *    · the pages declare their live surfaces and load the shared banner.
 * ============================================================== */

$makeMember = static function (string $username = ''): array {
    return test_user($username !== '' ? $username : 'wlv_' . bin2hex(random_bytes(4)));
};

$ledgerId = static function (int $id): int {
    $stmt = db()->prepare('SELECT id FROM ewallet_ledger WHERE id = ?');
    $stmt->execute([$id]);

    return (int) $stmt->fetchColumn();
};

return [
    'wallet surfaces use the list + detail keys and bump together' => function (): void {
        $listBefore   = Live::revision('admin.wallets');
        $detailBefore = Live::revision('admin.wallets:4242');

        Live::touchWallet(4242);

        assert_same('admin.wallets:4242', Live::walletSurface(4242));
        assert_same($listBefore + 1, Live::revision('admin.wallets'));
        assert_same($detailBefore + 1, Live::revision('admin.wallets:4242'));

        $meta = Live::meta('admin.wallets', 'user:1');

        assert_true($meta['enabled']);
        assert_same($listBefore + 1, $meta['revision']);
        assert_contains('"admin.wallets:' . ($listBefore + 1) . ':', $meta['etag']);
    },

    'every wallet movement bumps the monitor and its detail page' => function () use ($makeMember, $ledgerId): void {
        $member  = $makeMember();
        $admin   = test_user('wlv_admin', 'admin');
        $id      = (int) $member['id'];
        $adminId = (int) $admin['id'];
        $wallet  = new WalletService();

        $bump = static function (callable $write, string $label) use ($id): void {
            $listBefore   = Live::revision('admin.wallets');
            $detailBefore = Live::revision(Live::walletSurface($id));

            $write();

            assert_same($listBefore + 1, Live::revision('admin.wallets'), $label . ' (monitor)');
            assert_same($detailBefore + 1, Live::revision(Live::walletSurface($id)), $label . ' (detail)');
        };

        $movement = null;

        $bump(static function () use ($wallet, $id, &$movement): void {
            $movement = $wallet->credit($id, '500.00', 'commission', 7001, 'Direct referral');
        }, 'withdrawable credit');

        /* The movement payload still carries the real ledger id even
         * though the live touch inserts another row. */
        assert_true((int) $movement['ledger_id'] > 0);
        assert_same((int) $movement['ledger_id'], $ledgerId((int) $movement['ledger_id']));

        $bump(static function () use ($wallet, $id): void {
            $wallet->credit($id, '200.00', 'topup', 7002, 'Welcome credit', false);
        }, 'non-withdrawable credit');

        $bump(static function () use ($wallet, $id): void {
            $wallet->debitInternal($id, '50.00', 'shop_order', 7003, 'Order EW-1');
        }, 'internal debit');

        $bump(static function () use ($wallet, $id): void {
            $wallet->debit($id, '25.00', 'payout', 7004, 'Payout hold');
        }, 'withdrawable debit');

        $bump(static function () use ($wallet, $id): void {
            $wallet->refund($id, '50.00', 7005, 'Order refund');
        }, 'refund');

        $bump(static function () use ($wallet, $adminId, $id): void {
            $wallet->adminTopUp($adminId, $id, '75.00', 'Audited top-up');
        }, 'admin top-up');

        /* A replayed system movement moves no money and must not bump. */
        $listBefore   = Live::revision('admin.wallets');
        $detailBefore = Live::revision(Live::walletSurface($id));

        $replay = $wallet->credit($id, '500.00', 'commission', 7001, 'Direct referral');

        assert_true($replay['replayed']);
        assert_same($listBefore, Live::revision('admin.wallets'));
        assert_same($detailBefore, Live::revision(Live::walletSurface($id)));
    },

    'member status changes bump the wallet monitor and detail page' => function () use ($makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $listBefore   = Live::revision('admin.wallets');
        $detailBefore = Live::revision(Live::walletSurface($id));

        Member::setStatus($id, 'suspended');

        assert_same($listBefore + 1, Live::revision('admin.wallets'));
        assert_same($detailBefore + 1, Live::revision(Live::walletSurface($id)));
    },

    'the monitor feed renders the common regions and the members tab' => function () use ($makeMember): void {
        $admin   = test_user('wlv_feed_admin', 'admin');
        $member  = $makeMember('<script>alert(9)</script>');

        (new WalletService())->credit((int) $member['id'], '321.00', 'commission', 7101, 'Earnings');

        test_signed_in_as($admin);

        $_GET = ['tab' => 'members', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets?tab=members&per_page=5';

        $controller = new AdminWalletController();
        $payload    = $controller->monitorFeedPayload(
            6,
            '"admin.wallets:6:abc"',
            $controller->monitorContext('members')
        );

        assert_true($payload['ok']);
        assert_same('admin.wallets', $payload['surface']);
        assert_same(6, $payload['revision']);
        assert_same(
            [
                'wallet-metrics',
                'wallet-stats',
                'wallet-tabs',
                'wallet-members',
                'wallet-members-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $metrics = $payload['fragments']['wallet-metrics'];
        $stats   = $payload['fragments']['wallet-stats'];
        $tabs    = $payload['fragments']['wallet-tabs'];
        $rows    = $payload['fragments']['wallet-members'];
        $pages   = $payload['fragments']['wallet-members-pagination'];

        assert_contains('data-live-region="wallet-metrics"', $metrics);
        assert_contains('Credits today', $metrics);
        assert_contains('Debits today', $metrics);

        assert_contains('data-live-region="wallet-stats"', $stats);
        assert_contains('Total float', $stats);
        assert_contains('Withdrawable', $stats);
        assert_contains('Non-withdrawable', $stats);
        assert_contains('Top-ups today', $stats);

        assert_contains('data-live-region="wallet-tabs"', $tabs);
        assert_contains('admin/wallets?tab=ledger', $tabs);
        assert_contains('wallet-tabs', $tabs);

        assert_contains('data-live-region="wallet-members"', $rows);
        assert_contains('data-live-key="' . (int) $member['id'] . '"', $rows);
        assert_contains('data-live-focus="member"', $rows);
        assert_contains('data-live-focus="open"', $rows);
        assert_contains(money(321), $rows);
        assert_contains('&lt;script&gt;alert(9)&lt;/script&gt;', $rows);
        assert_not_contains('<script>alert(9)</script>', $rows);

        /* The search form is outside the regions: it must not travel in
         * a fragment and overwrite what the administrator is typing. */
        assert_not_contains('name="q"', $rows);
        assert_not_contains('wallet-search', $rows);

        assert_contains('data-live-region="wallet-members-pagination"', $pages);
        assert_contains('admin/wallets?tab=members&amp;per_page=5&amp;page=2', $pages);
        assert_not_contains('/feed', $pages);

        assert_contains('admin/wallets?tab=members&per_page=5', $payload['meta']['canonical']);
    },

    'the monitor feed renders the top-ups tab' => function () use ($makeMember): void {
        $admin   = test_user('wlv_topup_admin', 'admin');
        $member  = $makeMember();
        $adminId = (int) $admin['id'];

        (new WalletService())->adminTopUp($adminId, (int) $member['id'], '44.25', '<script>alert(4)</script>');

        test_signed_in_as($admin);

        $_GET = ['tab' => 'topups', 'q' => (string) $member['username']];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets?tab=topups&q=' . rawurlencode((string) $member['username']);

        $controller = new AdminWalletController();
        $payload    = $controller->monitorFeedPayload(
            3,
            '"admin.wallets:3:x"',
            $controller->monitorContext('topups')
        );

        assert_same(
            [
                'wallet-metrics',
                'wallet-stats',
                'wallet-tabs',
                'wallet-topups',
                'wallet-topups-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $rows  = $payload['fragments']['wallet-topups'];
        $pages = $payload['fragments']['wallet-topups-pagination'];

        assert_contains('data-live-region="wallet-topups"', $rows);
        assert_contains('data-live-key=', $rows);
        assert_contains('data-live-focus="recipient"', $rows);
        assert_contains((string) $member['username'], $rows);
        assert_contains((string) $admin['username'], $rows);
        assert_contains(money(44.25, true), $rows);
        assert_contains('&lt;script&gt;alert(4)&lt;/script&gt;', $rows);
        assert_not_contains('<script>alert(4)</script>', $rows);
        assert_not_contains('name="q"', $rows);

        assert_contains('data-live-region="wallet-topups-pagination"', $pages);
        assert_contains('name="tab" value="topups"', $pages);
        assert_not_contains('/feed', $pages);
    },

    'the monitor feed renders the ledger tab with a held statement' => function () use ($makeMember): void {
        $admin  = test_user('wlv_ledger_admin', 'admin');
        $member = $makeMember();

        (new WalletService())->credit(
            (int) $member['id'],
            '17.00',
            'adjustment',
            7201,
            '<script>alert(5)</script>'
        );

        test_signed_in_as($admin);

        $_GET = ['tab' => 'ledger', 'type' => 'credit'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets?tab=ledger&type=credit';

        $controller = new AdminWalletController();
        $payload    = $controller->monitorFeedPayload(
            2,
            '"admin.wallets:2:y"',
            $controller->monitorContext('ledger')
        );

        assert_same(
            ['wallet-metrics', 'wallet-stats', 'wallet-tabs', 'wallet-ledger'],
            array_keys($payload['fragments'])
        );

        $ledger = $payload['fragments']['wallet-ledger'];

        assert_contains('data-live-region="wallet-ledger"', $ledger);
        assert_contains('data-live-hold="dirty"', $ledger);
        assert_contains('All wallet movements', $ledger);
        assert_contains('name="tab" value="ledger"', $ledger);
        assert_contains('option value="credit" selected', $ledger);
        assert_contains((string) $member['username'], $ledger);
        assert_contains('&lt;script&gt;alert(5)&lt;/script&gt;', $ledger);
        assert_not_contains('<script>alert(5)</script>', $ledger);
        assert_contains(url('admin/wallets?tab=ledger'), $ledger);
        assert_contains('admin/wallets?tab=ledger&type=credit', $payload['meta']['canonical']);
    },

    'the wallet detail feed renders every card and keeps escaping' => function () use ($makeMember): void {
        $admin   = test_user('wlv_detail_admin', 'admin');
        $member  = $makeMember('<b>wlv detail</b>');
        $id      = (int) $member['id'];
        $adminId = (int) $admin['id'];

        db()->prepare('UPDATE users SET full_name = ? WHERE id = ?')
            ->execute(['<b>Wallet Owner</b>', $id]);

        $wallet = new WalletService();
        $wallet->credit($id, '1000.00', 'commission', 7301, 'Direct referral');
        $wallet->credit($id, '250.00', 'topup', 7302, '<script>alert(6)</script>', false);
        $wallet->debitInternal($id, '150.00', 'shop_order', 7303, 'Order EW-77');
        $wallet->adminTopUp($adminId, $id, '99.50', 'First credit');

        /* Enough top-ups for the history to have a second page. */
        for ($i = 1; $i <= 5; $i++) {
            $wallet->adminTopUp($adminId, $id, '10.00', 'Extra top-up ' . $i);
        }

        test_signed_in_as($admin);

        $_GET = ['page' => '2', 'per_page' => '5', 'topup_page' => '2'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets/' . $id . '?page=2&per_page=5&topup_page=2';

        $controller = new AdminWalletController();
        $data       = $controller->walletContext(Wallet::account($id));
        $payload    = $controller->detailFeedPayload(5, '"admin.wallets:' . $id . ':5:z"', $data);

        assert_same('admin.wallets:' . $id, $payload['surface']);
        assert_same(
            [
                'wallet-heading',
                'wallet-badges',
                'wallet-buckets',
                'wallet-statement',
                'wallet-topup-form',
                'wallet-topup-history',
            ],
            array_keys($payload['fragments'])
        );

        $fragments = $payload['fragments'];
        $heading   = $fragments['wallet-heading'];
        $badges    = $fragments['wallet-badges'];
        $buckets   = $fragments['wallet-buckets'];
        $statement = $fragments['wallet-statement'];
        $form      = $fragments['wallet-topup-form'];
        $history   = $fragments['wallet-topup-history'];

        assert_contains('data-live-region="wallet-heading"', $heading);
        assert_contains('&lt;b&gt;wlv detail&lt;/b&gt;', $heading);
        assert_contains('&lt;b&gt;Wallet Owner&lt;/b&gt;', $heading);
        assert_not_contains('<b>Wallet Owner</b>', $heading);

        assert_contains('data-live-region="wallet-badges"', $badges);
        assert_contains('Member', $badges);
        assert_contains('Active', $badges);

        assert_contains('data-live-region="wallet-buckets"', $buckets);
        assert_contains('Total balance', $buckets);
        assert_contains(money(1249.5), $buckets, 'total = 1000 + 250 - 150 + 99.50 + 5 × 10');
        assert_contains('Withdrawable', $buckets);
        assert_contains(money(1000), $buckets, 'the top-up covered the purchase');
        assert_contains('Non-withdrawable', $buckets);

        assert_contains('data-live-region="wallet-statement"', $statement);
        assert_contains('data-live-hold="dirty"', $statement);
        assert_contains('Wallet statement', $statement);
        assert_contains('Commission', $statement);
        assert_contains('Admin top-up', $statement);
        assert_contains('Order EW-77', $statement);
        assert_contains('&lt;script&gt;alert(6)&lt;/script&gt;', $statement);
        assert_not_contains('<script>alert(6)</script>', $statement);
        assert_contains('action="' . url('admin/wallets/' . $id) . '"', $statement);

        assert_contains('data-live-region="wallet-topup-form"', $form);
        assert_contains('data-live-hold="dirty"', $form);
        assert_contains('Credit this wallet', $form);
        assert_contains('action="' . url('admin/wallets/' . $id . '/credit') . '"', $form);
        assert_contains('name="_csrf"', $form);
        assert_contains('name="amount"', $form);
        assert_contains('name="note"', $form);

        assert_contains('data-live-region="wallet-topup-history"', $history);
        assert_contains('Top-up history', $history);
        assert_contains('First credit', $history);
        assert_contains((string) $admin['username'], $history);
        assert_contains('topup_page=1', $history, 'the history paginates on its own key');

        assert_contains(
            'admin/wallets/' . $id . '?per_page=5&page=2&topup_page=2',
            (string) $payload['meta']['canonical']
        );
    },

    'the wallet pages declare their live surfaces and regions' => function () use ($makeMember): void {
        $admin  = test_user('wlv_page_admin', 'admin');
        $member = $makeMember();
        $id     = (int) $member['id'];

        (new WalletService())->credit($id, '55.00', 'commission', 7401, 'Earnings');

        test_signed_in_as($admin);

        $_GET = ['tab' => 'members'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets?tab=members';

        $controller = new AdminWalletController();
        $data       = $controller->monitorContext('members');

        ob_start();

        try {
            View::page('admin', 'admin/wallets/index', $data + [
                'page_title'  => 'E-wallet monitor',
                'current_nav' => 'admin.wallets',
                'page_css'    => ['assets/css/wallet.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta('admin.wallets', 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.wallets"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="wallet-metrics"', $html);
        assert_contains('data-live-region="wallet-stats"', $html);
        assert_contains('data-live-region="wallet-tabs"', $html);
        assert_contains('data-live-region="wallet-members"', $html);
        assert_contains('data-live-region="wallet-members-pagination"', $html);
        assert_contains('data-live-key="' . $id . '"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_contains('wallet-search', $html);

        $_GET = ['tab' => 'ledger'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets?tab=ledger';

        $ledgerData = $controller->monitorContext('ledger');

        ob_start();

        try {
            View::page('admin', 'admin/wallets/index', $ledgerData + [
                'page_title'  => 'E-wallet monitor',
                'current_nav' => 'admin.wallets',
                'page_css'    => ['assets/css/wallet.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta('admin.wallets', 'page'),
            ]);
        } finally {
            $ledgerHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-region="wallet-ledger"', $ledgerHtml);
        assert_contains('data-live-hold="dirty"', $ledgerHtml);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets/' . $id;

        $detail = $controller->walletContext(Wallet::account($id));

        ob_start();

        try {
            View::page('admin', 'admin/wallets/show', $detail + [
                'page_title'  => 'Wallet — ' . (string) $member['username'],
                'current_nav' => 'admin.wallets',
                'page_css'    => ['assets/css/wallet.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta('admin.wallets:' . $id, 'page'),
            ]);
        } finally {
            $detailHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.wallets:' . $id . '"', $detailHtml);
        assert_contains('data-live-region="wallet-heading"', $detailHtml);
        assert_contains('data-live-region="wallet-badges"', $detailHtml);
        assert_contains('data-live-region="wallet-buckets"', $detailHtml);
        assert_contains('data-live-region="wallet-statement"', $detailHtml);
        assert_contains('data-live-region="wallet-topup-form"', $detailHtml);
        assert_contains('data-live-region="wallet-topup-history"', $detailHtml);
        assert_contains('data-live-hold="dirty"', $detailHtml);
        assert_contains('data-live-status', $detailHtml);
        assert_contains('data-live-stale', $detailHtml);
        assert_contains('admin-live.js', $detailHtml);
        assert_contains('action="' . url('admin/wallets/' . $id . '/credit') . '"', $detailHtml);
    },
];
