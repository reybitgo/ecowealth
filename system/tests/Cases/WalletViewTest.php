<?php
declare(strict_types=1);

/* ==============================================================
 *  WalletViewTest — member statement, admin monitor and top-up form
 *  (plan §5.8, §7 Phase 5; supports P5-T7 / P5-T9)
 *
 *  The tests render the real templates through View::page(), with the
 *  same data shape the controllers build, and assert the parts a
 *  browser would see: both buckets, the ledger rows, escaping, the
 *  filters, the tabs and the CSRF-protected top-up form.
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
    return test_user('wv_' . bin2hex(random_bytes(4)), $role);
};

/** View data for the member statement, as WalletController builds it. */
$memberPage = static function (array $account, array $filters = [], int $page = 1): array {
    $statement = (new WalletService())->statement((int) $account['id'], $filters, $page);

    return [
        'page_title'      => 'E-wallet',
        'current_nav'     => 'wallet',
        'page_css'        => ['assets/css/wallet.css'],
        'account'         => $account,
        'limits'          => (new TransferService())->usageFor($account),
        'ledger'          => $statement['rows'],
        'pager'           => $statement['pager'],
        'totals'          => $statement['totals'],
        'filters'         => $filters,
        'statement_url'   => url('wallet'),
        'statement_title' => 'Statement',
        'show_member'     => false,
    ];
};

/** View data for the admin monitor, as AdminWalletController::index builds it. */
$monitorPage = static function (string $tab = 'members', string $query = '', string $status = ''): array {
    $totals = Wallet::monitorTotals() + Transfer::monitorTotals();

    $members      = [];
    $membersPager = new Paginator(0);
    $transfers    = [];
    $transfersPager = new Paginator(0);
    $transferFilters = Transfer::filterDefaults();
    $topups       = [];
    $topupsPager  = new Paginator(0);
    $ledger       = [];
    $ledgerPager  = new Paginator(0);
    $ledgerTotals = ['rows' => 0, 'credits' => 0.0, 'debits' => 0.0, 'net' => 0.0];

    if ($tab === 'members') {
        $filters      = ['q' => $query, 'funded' => '', 'status' => $status];
        $membersPager = Paginator::fromRequest(Wallet::memberCount($filters), 20);
        $members      = Wallet::members($filters, $membersPager);
    } elseif ($tab === 'transfers') {
        $transfersPager = Paginator::fromRequest(Transfer::allCount($transferFilters), 20);
        $transfers      = Transfer::all($transferFilters, $transfersPager);
    } elseif ($tab === 'topups') {
        $filters     = ['q' => $query];
        $topupsPager = Paginator::fromRequest(Wallet::allTopupCount($filters), 20);
        $topups      = Wallet::allTopups($filters, $topupsPager);
    } else {
        $filters     = Wallet::filterDefaults();
        $ledgerPager = Paginator::fromRequest(Wallet::ledgerCount(null, $filters), 20);
        $ledger      = Wallet::ledger(null, $filters, $ledgerPager);
        $ledgerTotals = Wallet::ledgerTotals(null, $filters);
    }

    return [
        'page_title'     => 'E-wallet monitor',
        'current_nav'    => 'admin.wallets',
        'page_css'       => ['assets/css/wallet.css'],
        'totals'         => $totals,
        'tab'            => $tab,
        'tab_counts'     => [
            'members'   => Wallet::memberCount(['q' => '', 'funded' => '']),
            'transfers' => Transfer::allCount(Transfer::filterDefaults()),
            'topups'    => Wallet::allTopupCount(['q' => '']),
            'ledger'    => Wallet::ledgerCount(null, Wallet::filterDefaults()),
        ],
        'query'          => $query,
        'funded'         => '',
        'status'         => $status,
        'members'        => $members,
        'members_pager'  => $membersPager,
        'transfers'      => $transfers,
        'transfers_pager' => $transfersPager,
        'transfer_filters' => $transferFilters,
        'topups'         => $topups,
        'topups_pager'   => $topupsPager,
        'ledger'         => $ledger,
        'ledger_pager'   => $ledgerPager,
        'ledger_totals'  => $ledgerTotals,
        'ledger_filters' => $filters,
    ];
};

return [
    'P5-T1 the member statement shows both buckets, rows and totals' => function () use ($render, $sidebar, $makeMember, $memberPage): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet = new WalletService();
        $wallet->credit($id, '1000.00', 'commission', 4001, 'Direct referral — maria');
        $wallet->credit($id, '250.00', 'topup', 4002, 'Welcome credit', false);
        $wallet->debitInternal($id, '150.00', 'shop_order', 4003, 'Order EW-2601');

        test_signed_in_as($member);

        $html = $render('member', 'member/wallet', $memberPage(Wallet::account($id)));

        assert_contains('E-wallet', $html);
        assert_contains('Total balance', $html);
        assert_contains('Withdrawable', $html);
        assert_contains('Non-withdrawable', $html);
        assert_contains(money(1100), $html, 'total = 1000 + 250 - 150');
        assert_contains(money(1000), $html, 'withdrawable is untouched while the top-up covers the debit');
        assert_contains(money(100), $html, 'locked = the rest of the top-up');
        assert_contains('How the two buckets work', $html);

        assert_contains('Statement', $html);
        assert_contains('Commission', $html);
        assert_contains('Admin top-up', $html);
        assert_contains('Shop order', $html);
        assert_contains('Direct referral — maria', $html);
        assert_contains('EW-2601', $html);
        assert_contains('3 movement', $html);
        assert_contains('Credits', $html);
        assert_contains('Debits', $html);
        assert_contains('Net', $html);
        assert_contains('withdrawable', $html);

        /* The sidebar marks E-wallet, and a member never sees the monitor. */
        $nav = $sidebar($html);
        assert_contains('E-wallet', $nav);
        assert_contains('menu-item active', $nav);
        assert_not_contains('E-wallet monitor', $nav);
        assert_not_contains('Credit this wallet', $html);
        assert_not_contains('All wallet movements', $html);
    },

    'P5-T1 an empty statement explains where money comes from' => function () use ($render, $makeMember, $memberPage): void {
        $member = $makeMember();
        test_signed_in_as($member);

        $html = $render('member', 'member/wallet', $memberPage(Wallet::account((int) $member['id'])));

        assert_contains('No wallet movements yet', $html);
        assert_contains('Money movements appear here as they happen', $html);
        assert_contains(money(0), $html);
        assert_not_contains('No movements match these filters', $html);
    },

    'P5-T9 filters keep their values, narrow the rows and offer Clear' => function () use ($render, $makeMember, $memberPage): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet = new WalletService();
        $wallet->credit($id, '500.00', 'commission', 4101, 'Direct referral');
        $wallet->debitInternal($id, '50.00', 'shop_order', 4102, 'Order EW-9');

        test_signed_in_as($member);

        $html = $render('member', 'member/wallet', $memberPage(Wallet::account($id), [
            'type' => 'credit', 'ref_type' => 'commission', 'from' => '', 'to' => '', 'q' => '',
        ]));

        assert_contains('option value="credit" selected', $html);
        assert_contains('option value="commission" selected', $html);
        assert_contains('Clear filters', $html);
        assert_contains(money(500), $html);
        assert_contains('Direct referral', $html);
        assert_not_contains('Order EW-9', $html, 'the debit is filtered out');
        assert_not_contains('No wallet movements yet', $html);
    },

    'P5-T9 a filter that matches nothing shows the filtered empty state' => function () use ($render, $makeMember, $memberPage): void {
        $member = $makeMember();
        test_signed_in_as($member);

        $html = $render('member', 'member/wallet', $memberPage(Wallet::account((int) $member['id']), [
            'type' => 'debit', 'ref_type' => '', 'from' => '2030-01-01', 'to' => '2030-12-31', 'q' => '',
        ]));

        assert_contains('No wallet movements yet', $html);
        assert_contains('value="2030-01-01"', $html);
        assert_contains('Clear filters', $html);
    },

    'P5-T7 the statement escapes a hostile ledger note' => function () use ($render, $makeMember, $memberPage): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        (new WalletService())->credit($id, '10.00', 'adjustment', 4201, '<script>alert(1)</script>');

        test_signed_in_as($member);

        $html = $render('member', 'member/wallet', $memberPage(Wallet::account($id)));

        assert_not_contains('<script>alert(1)</script>', $html);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    },

    'P5-T7 the admin monitor shows the float, both buckets and the directory' => function () use ($render, $sidebar, $makeMember, $monitorPage): void {
        $admin  = test_user('wv_admin', 'admin');
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet = new WalletService();
        $wallet->credit($id, '1000.00', 'commission', 4301, 'Earnings');
        $wallet->credit($id, '250.00', 'topup', 4302, 'Welcome credit', false);

        /* The monitor shows the member status, not the (always "Member") role. */
        db()->prepare("UPDATE users SET member_status = 'pending' WHERE id = ?")->execute([$id]);

        test_signed_in_as($admin);

        $html = $render('admin', 'admin/wallets/index', $monitorPage('members'));

        assert_contains('E-wallet monitor', $html);
        assert_contains('Total float', $html);
        assert_contains('Withdrawable', $html);
        assert_contains('Non-withdrawable', $html);
        assert_contains('Top-ups today', $html);
        assert_contains('Members', $html);
        assert_contains('Top-ups', $html);
        assert_contains('All movements', $html);

        assert_contains('<th scope="col">Status</th>', $html);
        assert_not_contains('<th scope="col">Role</th>', $html);
        assert_contains('bg-label-warning">Pending', $html, 'the pending member is flagged in the monitor');

        assert_contains((string) $member['username'], $html);
        assert_contains(money(1250), $html, 'the member total');
        assert_contains(money(1000), $html, 'the member withdrawable');
        assert_contains(money(250), $html, 'the member locked');
        assert_contains(url('admin/wallets/' . $id), $html);

        $nav = $sidebar($html);
        assert_contains('E-wallet monitor', $nav);
        assert_contains('My e-wallet', $nav);
        assert_contains('menu-item active', $nav);
        assert_not_contains('Payouts', $nav, 'Phase 13 is still hidden');
    },

    'P5-T7 the monitor filters members by status' => function () use ($render, $makeMember, $monitorPage): void {
        $admin   = test_user('wv_admin', 'admin');
        $active  = $makeMember();
        $pending = $makeMember();

        db()->prepare("UPDATE users SET member_status = 'pending' WHERE id = ?")
            ->execute([(int) $pending['id']]);

        $wallet = new WalletService();
        $wallet->credit((int) $pending['id'], '100.00', 'topup', 4401, 'Fund', false);
        $wallet->credit((int) $active['id'], '50.00', 'topup', 4402, 'Fund', false);

        test_signed_in_as($admin);

        $html = $render('admin', 'admin/wallets/index', $monitorPage('members', '', 'pending'));

        assert_contains('value="pending" selected', $html, 'the status filter keeps its selection');
        assert_contains((string) $pending['username'], $html);
        assert_not_contains((string) $active['username'], $html, 'active members are filtered out');
        assert_contains('Clear', $html, 'a status filter offers Clear');
    },

    'P5-T7 the admin top-up tab and the all-movements tab render' => function () use ($render, $makeMember, $monitorPage): void {
        $admin  = test_user('wv_admin', 'admin');
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet = new WalletService();
        $wallet->adminTopUp((int) $admin['id'], $id, '250.00', 'Welcome credit');

        test_signed_in_as($admin);

        $topups = $render('admin', 'admin/wallets/index', $monitorPage('topups'));
        assert_contains('Admin top-ups', $topups);
        assert_contains((string) $member['username'], $topups);
        assert_contains((string) $admin['username'], $topups);
        assert_contains(money(250), $topups);
        assert_contains('Welcome credit', $topups);

        $ledger = $render('admin', 'admin/wallets/index', $monitorPage('ledger'));
        assert_contains('All wallet movements', $ledger);
        assert_contains('Member', $ledger, 'the cross-user ledger shows who moved the money');
        assert_contains((string) $member['username'], $ledger);
        assert_contains('Admin top-up', $ledger);
        assert_contains(url('admin/wallets?tab=ledger'), $ledger);
        assert_contains('name="tab" value="ledger"', $ledger, 'filtering stays on the ledger tab');
    },

    'P5-T7 the admin wallet page carries the CSRF top-up form and history' => function () use ($render, $makeMember): void {
        $admin  = test_user('wv_admin', 'admin');
        $member = $makeMember();
        $id     = (int) $member['id'];

        /* Pending members can sign in, so the header badge must read the
         * member status rather than is_active (Phase 9 follow-up). */
        db()->prepare("UPDATE users SET member_status = 'pending' WHERE id = ?")->execute([$id]);

        $wallet = new WalletService();
        $wallet->adminTopUp((int) $admin['id'], $id, '99.50', 'First credit');

        $statement   = $wallet->statement($id, Wallet::filterDefaults(), 1);
        $topupsPager = Paginator::fromRequest(Wallet::topupCountFor($id), 10);

        assert_same('pending', (string) Wallet::account($id)['member_status']);

        test_signed_in_as($admin);

        $html = $render('admin', 'admin/wallets/show', [
            'page_title'      => 'Wallet — ' . $member['username'],
            'current_nav'     => 'admin.wallets',
            'page_css'        => ['assets/css/wallet.css'],
            'member'          => Wallet::account($id),
            'ledger'          => $statement['rows'],
            'pager'           => $statement['pager'],
            'totals'          => $statement['totals'],
            'filters'         => Wallet::filterDefaults(),
            'statement_url'   => url('admin/wallets/' . $id),
            'statement_title' => 'Wallet statement',
            'show_member'     => false,
            'topups'          => Wallet::topupsFor($id, $topupsPager),
            'topups_pager'    => $topupsPager,
        ]);

        assert_contains('@' . $member['username'], $html);
        assert_contains('Credit this wallet', $html);
        assert_contains('non-withdrawable', $html);
        assert_contains('action="' . url('admin/wallets/' . $id . '/credit') . '"', $html);
        assert_contains('name="_csrf"', $html);
        assert_contains('name="amount"', $html);
        assert_contains('name="note"', $html);
        assert_contains('Top-up history', $html);
        assert_contains(money(99.5), $html);
        assert_contains('First credit', $html);
        assert_contains('Wallet statement', $html);
        assert_contains('Admin top-up', $html);
        assert_contains('Back to monitor', $html);
        assert_contains('bg-label-warning">Pending', $html, 'the header shows the member status, not is_active');
    },

    'P5-T7 the top-up form echoes field errors and the submitted value' => function () use ($render, $makeMember): void {
        $admin  = test_user('wv_admin', 'admin');
        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($admin);

        errors_set(['amount' => 'Top-up amount must be greater than 0.']);
        old_set(['amount' => '-5', 'note' => 'Bad input']);

        $statement = (new WalletService())->statement($id, Wallet::filterDefaults(), 1);

        $html = $render('admin', 'admin/wallets/show', [
            'page_title'      => 'Wallet — ' . $member['username'],
            'current_nav'     => 'admin.wallets',
            'member'          => Wallet::account($id),
            'ledger'          => $statement['rows'],
            'pager'           => $statement['pager'],
            'totals'          => $statement['totals'],
            'filters'         => Wallet::filterDefaults(),
            'statement_url'   => url('admin/wallets/' . $id),
            'statement_title' => 'Wallet statement',
            'show_member'     => false,
            'topups'          => [],
            'topups_pager'    => new Paginator(0),
        ]);

        assert_contains('Top-up amount must be greater than 0.', $html);
        assert_contains('value="-5"', $html);
        assert_contains('is-invalid', $html);
        assert_contains('Bad input', $html);
        assert_contains('No top-ups yet.', $html);
    },
];
