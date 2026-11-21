<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberWalletLiveTest — the member's own e-wallet statement
 *
 *  The statement is a per-member live surface (`member.wallet:{id}`)
 *  whose regions are the three bucket cards and the statement card.
 *  These cases pin:
 *
 *    · every balance movement bumps it (a replay does not), and another
 *      member's statement is untouched;
 *    · the admin wallet surfaces and the member statement are separate:
 *      a directory/profile change does not refresh the statement;
 *    · the feed returns the two regions, keeps the filter form out of
 *      the payload, escapes notes and clamps an out-of-range page;
 *    · the page declares its live surface, regions and stale banner.
 * ============================================================== */

$makeMember = static function (string $username = ''): array {
    return test_user($username !== '' ? $username : 'mwl_' . bin2hex(random_bytes(4)));
};

return [
    'the member wallet surface is per user and bumps on every movement' => function () use ($makeMember): void {
        $member  = $makeMember();
        $other   = $makeMember();
        $admin   = test_user('mwl_admin', 'admin');
        $id      = (int) $member['id'];
        $otherId = (int) $other['id'];
        $adminId = (int) $admin['id'];
        $wallet  = new WalletService();
        $ref     = 6401;

        assert_same('member.wallet:' . $id, Live::memberWalletSurface($id));

        $before      = Live::revision(Live::memberWalletSurface($id));
        $otherBefore = Live::revision(Live::memberWalletSurface($otherId));

        $wallet->credit($id, '500.00', 'commission', $ref, 'Direct referral');

        assert_same($before + 1, Live::revision(Live::memberWalletSurface($id)), 'commission');
        assert_same($otherBefore, Live::revision(Live::memberWalletSurface($otherId)), 'another member is untouched');

        /* A replayed system movement moves no money and must not bump. */
        $before = Live::revision(Live::memberWalletSurface($id));
        $replay = $wallet->credit($id, '500.00', 'commission', $ref, 'Direct referral');

        assert_true($replay['replayed']);
        assert_same($before, Live::revision(Live::memberWalletSurface($id)));

        $bump = static function (callable $write, string $label) use ($id): void {
            $before = Live::revision(Live::memberWalletSurface($id));

            $write();

            assert_same($before + 1, Live::revision(Live::memberWalletSurface($id)), $label);
        };

        $bump(static function () use ($wallet, $id): void {
            $wallet->credit($id, '200.00', 'topup', 6402, 'Spend-only credit', false);
        }, 'non-withdrawable credit');

        $bump(static function () use ($wallet, $id): void {
            $wallet->debitInternal($id, '50.00', 'shop_order', 6403, 'Order EW-1');
        }, 'internal debit');

        $bump(static function () use ($wallet, $id): void {
            $wallet->debit($id, '25.00', 'payout', 6404, 'Payout hold');
        }, 'withdrawable debit');

        $bump(static function () use ($wallet, $id): void {
            $wallet->refund($id, '50.00', 6405, 'Order refund');
        }, 'refund');

        $bump(static function () use ($wallet, $adminId, $id): void {
            $wallet->adminTopUp($adminId, $id, '75.00', 'Audited top-up');
        }, 'admin top-up');

        /* The admin monitor/detail surfaces do not share the member
         * statement: a status or profile-name change must not bump it. */
        $before = Live::revision(Live::memberWalletSurface($id));
        Live::touchWallet($id);
        Live::touchMember($id);

        assert_same($before, Live::revision(Live::memberWalletSurface($id)), 'admin surfaces are separate');
    },

    'the wallet feed renders the buckets and the statement' => function () use ($makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];
        $wallet = new WalletService();

        $wallet->credit($id, '1000.00', 'commission', 6501, 'Direct referral');
        $wallet->credit($id, '250.00', 'topup', 6502, '<script>alert(7)</script>', false);
        $wallet->debitInternal($id, '150.00', 'shop_order', 6503, 'Order EW-77');

        for ($i = 1; $i <= 3; $i++) {
            $wallet->credit($id, '1.00', 'adjustment', 6510 + $i, 'Tiny credit ' . $i);
        }

        test_signed_in_as($member);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet?per_page=5';

        $controller = new WalletController();
        $data       = $controller->walletContext(Wallet::account($id));
        $payload    = $controller->feedPayload(8, '"member.wallet:' . $id . ':8:abc"', $data);

        assert_true($payload['ok']);
        assert_same('member.wallet:' . $id, $payload['surface']);
        assert_same(8, $payload['revision']);
        assert_same(
            ['member-wallet-buckets', 'member-wallet-transfer', 'member-wallet-payout', 'member-wallet-statement'],
            array_keys($payload['fragments'])
        );

        $buckets   = $payload['fragments']['member-wallet-buckets'];
        $sendCard  = $payload['fragments']['member-wallet-transfer'];
        $payoutCard = $payload['fragments']['member-wallet-payout'];
        $statement = $payload['fragments']['member-wallet-statement'];

        assert_contains('data-live-region="member-wallet-buckets"', $buckets);
        assert_contains('Total balance', $buckets);
        assert_contains(money(1103), $buckets, '1000 + 250 - 150 + 3');
        assert_contains('Withdrawable', $buckets);
        assert_contains(money(1003), $buckets, 'the top-up covered the purchase');
        assert_contains('Non-withdrawable', $buckets);
        assert_contains(money(100), $buckets);

        /* The send-funds card carries the transfer allowance (Phase 12). */
        assert_contains('data-live-region="member-wallet-transfer"', $sendCard);
        assert_contains('Send money to another member', $sendCard);
        assert_contains(url('wallet/transfer'), $sendCard);
        assert_contains('sent today', $sendCard);

        /* The withdraw card points at the payout page (Phase 13). */
        assert_contains('data-live-region="member-wallet-payout"', $payoutCard);
        assert_contains('Withdraw earnings', $payoutCard);
        assert_contains(url('payouts'), $payoutCard);

        assert_contains('data-live-region="member-wallet-statement"', $statement);
        assert_contains('data-live-hold="dirty"', $statement);
        assert_contains('data-live-region="member-wallet-statement"', $statement);
        assert_contains('Commission', $statement);
        assert_contains('Admin top-up', $statement);
        assert_contains('Order EW-77', $statement);
        assert_contains('&lt;script&gt;alert(7)&lt;/script&gt;', $statement);
        assert_not_contains('<script>alert(7)</script>', $statement);
        assert_contains('action="' . url('wallet') . '"', $statement);
        assert_contains('name="type"', $statement);
        assert_contains('name="ref_type"', $statement);
        assert_contains('wallet?per_page=5&amp;page=2', $statement);
        assert_not_contains('/feed', $statement);

        assert_contains('wallet?per_page=5', $payload['meta']['canonical']);

        /* An out-of-range page is clamped to the last real page. */
        $_GET = ['page' => '99', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet?page=99&per_page=5';

        $clamped = $controller->feedPayload(
            9,
            '"member.wallet:' . $id . ':9:def"',
            $controller->walletContext(Wallet::account($id))
        );

        assert_same(2, $clamped['meta']['page']);
        assert_contains('page=2', (string) $clamped['meta']['canonical']);
    },

    'the wallet feed renders the empty statement' => function () use ($makeMember): void {
        $member = $makeMember();

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet';

        $controller = new WalletController();
        $payload    = $controller->feedPayload(
            1,
            '"member.wallet:' . (int) $member['id'] . ':1:abc"',
            $controller->walletContext(Wallet::account((int) $member['id']))
        );

        $buckets   = $payload['fragments']['member-wallet-buckets'];
        $statement = $payload['fragments']['member-wallet-statement'];

        assert_contains(money(0), $buckets);
        assert_contains('No wallet movements yet', $statement);
        assert_contains('Money movements appear here as they happen', $statement);
        assert_same(0, $payload['meta']['total']);
        assert_same(url('wallet'), $payload['meta']['canonical']);
    },

    'the wallet page declares its live surface and regions' => function () use ($makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        (new WalletService())->credit($id, '55.00', 'commission', 6601, 'Earnings');

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet';

        $controller = new WalletController();
        $data       = $controller->walletContext(Wallet::account($id));

        ob_start();

        try {
            View::page('member', 'member/wallet', $data + [
                'page_title'  => 'E-wallet',
                'current_nav' => 'wallet',
                'page_css'    => ['assets/css/wallet.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta(Live::memberWalletSurface($id), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.wallet:' . $id . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="member-wallet-buckets"', $html);
        assert_contains('data-live-region="member-wallet-statement"', $html);
        assert_contains('data-live-hold="dirty"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_contains('wallet-page', $html);
        assert_contains('name="type"', $html);
        assert_contains(money(55), $html);
    },
];
