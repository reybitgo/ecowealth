<?php
declare(strict_types=1);

/* ==============================================================
 *  TransferViewTest — the send-funds page and the monitor's
 *  transfers tab (plan §5.8, §7 Phase 12)
 *
 *  Renders the real templates through View::page() with the data the
 *  controllers build, and asserts the parts a browser would see: the
 *  password-confirmed form, the fee and minimum, the limit meters, the
 *  sent/received history, the staff exemption, escaping, and the admin
 *  transfer log with its filters.
 * ============================================================== */

/**
 * Render a page through View::page() and return the HTML.
 *
 * @param array<string, mixed> $data
 */
$render = static function (string $layout, string $template, array $data = []): string {
    ob_start();

    try {
        View::page($layout, $template, $data);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/** The sidebar block from a rendered page (empty when there is no menu). */
$sidebar = static function (string $html): string {
    $start = strpos($html, '<ul class="menu-inner py-1">');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, '</ul>', $start);

    return $end === false ? '' : substr($html, $start, $end - $start);
};

$makeMember = static function (string $role = 'member'): array {
    return test_user('tv_' . bin2hex(random_bytes(4)), $role);
};

/** Put money in a wallet the way a commission would. */
$fund = static function (int $userId, string $amount, bool $withdrawable = true): void {
    (new WalletService())->credit($userId, $amount, 'commission', null, 'Transfer fixture', $withdrawable);
};

/** View data for the member transfer page, as TransferController builds it. */
$memberPage = static function (array $account): array {
    return (new TransferController())->transferContext($account) + [
        'page_title'  => 'Send funds',
        'current_nav' => 'transfer',
        'page_css'    => ['assets/css/wallet.css'],
        'page_js'     => ['assets/js/app-transfer.js'],
    ];
};

/** View data for the admin monitor, as AdminWalletController builds it. */
$monitorPage = static function (string $tab = 'transfers'): array {
    return (new AdminWalletController())->monitorContext($tab) + [
        'page_title'  => 'E-wallet monitor',
        'current_nav' => 'admin.wallets',
        'page_css'    => ['assets/css/wallet.css'],
        'page_js'     => ['assets/js/admin-live.js'],
    ];
};

return [
    'P12-T1 the transfer page carries the password-confirmed form, fee and minimum' => function () use ($render, $sidebar, $makeMember, $fund, $memberPage): void {
        $member  = $makeMember();
        $id      = (int) $member['id'];

        $fund($id, '2000.00');

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/transfer';

        $html = $render('member', 'member/transfer', $memberPage(Wallet::account($id)));

        assert_contains('Send funds', $html);
        assert_contains('data-live-surface="member.transfer:' . $id . '"', $html);

        /* The form: every field, the CSRF token and its target. */
        assert_contains('action="' . url('wallet/transfer') . '"', $html);
        assert_contains('name="_csrf"', $html);
        assert_contains('name="recipient"', $html);
        assert_contains('name="amount"', $html);
        assert_contains('name="note"', $html);
        assert_contains('name="password"', $html);
        assert_contains('Confirm with your password', $html);

        /* The cost of the transfer is visible before it is sent. */
        assert_contains('Transfer fee', $html);
        assert_contains('Minimum transfer', $html);
        assert_contains(money(50), $html, 'the seeded minimum');
        assert_contains(money(2000), $html, 'the wallet balance');
        assert_contains('Most you can send now', $html);
        assert_contains('data-fee="0"', $html, 'the seeded fee is zero');

        /* Both limit meters. */
        assert_contains('Transfer limits', $html);
        assert_contains('Sent today', $html);
        assert_contains('Sent this week', $html);
        assert_contains('Limits reset daily at midnight', $html);

        /* No history yet. */
        assert_contains('No transfers yet', $html);

        /* The sidebar marks the new item, not E-wallet. */
        $nav = $sidebar($html);
        assert_contains('Send funds', $nav);
        assert_contains('menu-item active', $nav);
        assert_not_contains('E-wallet monitor', $nav);
    },

    'P12-T1 the page lists sent and received transfers with their direction' => function () use ($render, $makeMember, $fund, $memberPage): void {
        $sender    = $makeMember();
        $recipient = $makeMember();
        $other     = $makeMember();
        $senderId  = (int) $sender['id'];

        $fund($senderId, '500.00');
        $fund((int) $other['id'], '500.00');

        $service = new TransferService();

        /* One transfer out (counterparty: the recipient) and one in
         * (counterparty: a different member) so the page shows both
         * directions without repeating a name. */
        $service->send($senderId, (string) $recipient['username'], '120.00', 'Lunch', 'Member@123');
        $service->send((int) $other['id'], (string) $sender['username'], '60.00', 'Thanks', 'Member@123');

        test_signed_in_as($sender);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/transfer';

        $html = $render('member', 'member/transfer', $memberPage(Wallet::account($senderId)));

        assert_contains('data-live-region="member-transfer-recent"', $html);
        assert_contains('Sent', $html);
        assert_contains('Received', $html);
        assert_contains('@' . $recipient['username'], $html);
        assert_contains('@' . $other['username'], $html);
        assert_contains(money(120, true), $html);
        assert_contains(money(60, true), $html);
        assert_contains('Lunch', $html);
        assert_contains('Thanks', $html);
        assert_contains('1 sent', $html);
        assert_contains('1 received', $html);
        assert_contains('(₱60.00)', $html, 'the received total');
        assert_not_contains('No transfers yet', $html);

        /* The pagination region closes the list. */
        assert_contains('data-live-region="member-transfer-pagination"', $html);
        assert_contains('Transfers', $html);
    },

    'P12-T1 the transfer page escapes hostile names and notes' => function () use ($render, $makeMember, $fund, $memberPage): void {
        $sender = $makeMember();
        $senderId = (int) $sender['id'];
        $bad    = test_user('<b>evil</b>' . bin2hex(random_bytes(3)));

        $fund($senderId, '500.00');

        (new TransferService())->send(
            $senderId,
            (string) $bad['username'],
            '100.00',
            '<script>alert(8)</script>',
            'Member@123'
        );

        test_signed_in_as($sender);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/transfer';

        $html = $render('member', 'member/transfer', $memberPage(Wallet::account($senderId)));

        assert_not_contains('<script>alert(8)</script>', $html);
        assert_contains('&lt;script&gt;alert(8)&lt;/script&gt;', $html);
        assert_not_contains('<b>evil</b>', $html);
        assert_contains('&lt;b&gt;evil&lt;/b&gt;', $html);
    },

    'P12-T1 the form echoes a refusal and keeps the submitted values' => function () use ($render, $makeMember, $memberPage): void {
        $member = $makeMember();

        test_signed_in_as($member);

        errors_set(['amount' => 'Your balance cannot cover that amount.']);
        old_set(['recipient' => 'peter', 'amount' => '99999.00', 'note' => 'Rent for March']);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/transfer';

        $html = $render('member', 'member/transfer', $memberPage(Wallet::account((int) $member['id'])));

        assert_contains('is-invalid', $html);
        assert_contains('Your balance cannot cover that amount.', $html);
        assert_contains('value="99999.00"', $html);
        assert_contains('value="Rent for March"', $html);
        assert_contains('value="peter"', $html);

        /* The password is never echoed back. */
        assert_not_contains('value="Member@123"', $html);
    },

    'P12-T1 the ?to= query prefills the recipient' => function () use ($render, $makeMember, $memberPage): void {
        $member = $makeMember();

        test_signed_in_as($member);

        $_GET = ['to' => 'peter'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/transfer?to=peter';

        $html = $render('member', 'member/transfer', $memberPage(Wallet::account((int) $member['id'])));

        assert_contains('value="peter"', $html);
    },

    'P12-T12 an admin sees the staff exemption on the transfer page' => function () use ($render, $makeMember, $fund, $memberPage): void {
        $admin   = $makeMember('admin');
        $adminId = (int) $admin['id'];

        $fund($adminId, '5000.00');

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/transfer';

        $html = $render('admin', 'member/transfer', $memberPage(Wallet::account($adminId)));

        assert_contains('Staff transfer — no fee, no limit', $html);
        assert_contains('Exempt — staff account', $html);
        assert_contains('Waived (staff)', $html);
        assert_contains('No limit applies to this account.', $html);

        /* The admin layout marks its own copy of the item. */
        assert_contains('data-live-surface="member.transfer:' . $adminId . '"', $html);
    },

    'P12-T2 the monitor transfers tab lists the log with links, fees and filters' => function () use ($render, $makeMember, $fund, $monitorPage): void {
        $admin     = test_user('tv_monitor_admin', 'admin');
        $sender    = $makeMember();
        $recipient = $makeMember();
        $senderId  = (int) $sender['id'];
        $recipientId = (int) $recipient['id'];

        $fund($senderId, '1000.00');

        $feeBefore = (string) setting('ewallet_transfer_fee', '0');
        setting_set('ewallet_transfer_fee', '10');
        setting_cache_clear();

        try {
            (new TransferService())->send(
                $senderId,
                (string) $recipient['username'],
                '250.00',
                '<script>alert(3)</script>',
                'Member@123'
            );
        } finally {
            setting_set('ewallet_transfer_fee', $feeBefore);
            setting_cache_clear();
        }

        test_signed_in_as($admin);

        $_GET = ['tab' => 'transfers'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/wallets?tab=transfers';

        $html = $render('admin', 'admin/wallets/index', $monitorPage('transfers'));

        assert_contains('Member-to-member movements', $html);
        assert_contains('data-live-region="wallet-transfers"', $html);
        assert_contains('data-live-region="wallet-transfers-pagination"', $html);

        /* Both sides of the transfer link to their wallet pages. */
        assert_contains(url('admin/wallets/' . $senderId), $html);
        assert_contains(url('admin/wallets/' . $recipientId), $html);
        assert_contains('@' . $sender['username'], $html);
        assert_contains('@' . $recipient['username'], $html);

        /* The amount, the fee and the note (escaped). */
        assert_contains(money(250, true), $html);
        assert_contains(money(10), $html);
        assert_contains('&lt;script&gt;alert(3)&lt;/script&gt;', $html);
        assert_not_contains('<script>alert(3)</script>', $html);

        /* The filter form stays outside the live regions. */
        assert_contains('name="from"', $html);
        assert_contains('name="to"', $html);
        assert_contains('name="q"', $html);
        assert_contains('name="tab" value="transfers"', $html);

        /* The shared stat cards now include the transfer figures. */
        assert_contains('Transfers today', $html);
        assert_contains('Withdrawable', $html);
    },

    'P12-T10 the monitor transfers tab searches by member and date' => function () use ($render, $makeMember, $fund, $monitorPage): void {
        $admin = test_user('tv_filter_admin', 'admin');

        $alice = $makeMember();
        $bob   = $makeMember();
        $carol = $makeMember();
        $dave  = $makeMember();

        $fund((int) $alice['id'], '500.00');
        $fund((int) $carol['id'], '500.00');

        $service = new TransferService();
        $service->send((int) $alice['id'], (string) $bob['username'], '100.00', 'Alice to Bob', 'Member@123');
        $service->send((int) $carol['id'], (string) $dave['username'], '200.00', 'Carol to Dave', 'Member@123');

        test_signed_in_as($admin);

        $_GET = ['tab' => 'transfers', 'q' => (string) $alice['username']];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/admin/wallets?tab=transfers&q=' . rawurlencode((string) $alice['username']);

        $html = $render('admin', 'admin/wallets/index', $monitorPage('transfers'));

        assert_contains('@' . $alice['username'], $html);
        assert_contains('@' . $bob['username'], $html);
        assert_not_contains('@' . $carol['username'], $html);
        assert_not_contains('@' . $dave['username'], $html);
        assert_contains('value="' . e((string) $alice['username']) . '"', $html);

        /* A date range that excludes everything shows the empty state. */
        $_GET = ['tab' => 'transfers', 'from' => '2030-01-01', 'to' => '2030-12-31'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/admin/wallets?tab=transfers&from=2030-01-01&to=2030-12-31';

        $empty = $render('admin', 'admin/wallets/index', $monitorPage('transfers'));

        assert_contains('No transfers yet', $empty);
        assert_contains('value="2030-01-01"', $empty);
        assert_contains('Clear', $empty);
    },

    'P12-T1 an empty transfer log explains itself' => function () use ($render, $makeMember, $monitorPage): void {
        $admin = test_user('tv_empty_admin', 'admin');

        test_signed_in_as($admin);

        $_GET = ['tab' => 'transfers'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/wallet/admin/wallets?tab=transfers';

        $html = $render('admin', 'admin/wallets/index', $monitorPage('transfers'));

        assert_contains('No transfers yet', $html);
        assert_contains('Members send money to each other', $html);
        assert_contains('name="tab" value="transfers"', $html);
    },
];
