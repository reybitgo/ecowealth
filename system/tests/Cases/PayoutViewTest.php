<?php
declare(strict_types=1);

/* ==============================================================
 *  PayoutViewTest — the member payout page and the admin queue
 *  (plan §5.9, §7 Phase 13)
 *
 *  Renders the real templates through View::page() with the data the
 *  controllers build, and asserts the parts a browser would see: the
 *  withdrawable card, the request form with its four channels and the
 *  fee/USDT preview, the open-request card, the history, the admin
 *  queue with its tabs and actions, escaping and the empty states.
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
    return test_user('pv_' . bin2hex(random_bytes(4)), $role);
};

/** Put money in a wallet the way a commission would. */
$fund = static function (int $userId, string $amount, bool $withdrawable = true): void {
    (new WalletService())->credit($userId, $amount, 'commission', null, 'Payout fixture', $withdrawable);
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
    'min_payout'             => '500',
    'service_fee_gcash'      => '0',
    'service_fee_usdt_trc20' => '5',
    'usdt_rate'              => '0',
    'usdt_trc20_gas_fee'     => '2.50',
    'payout_gcash_enabled'   => '1',
    'payout_maya_enabled'    => '1',
    'payout_usdt_trc20_enabled' => '1',
    'payout_usdt_bep20_enabled' => '1',
];

$gcash = '09171234567';

/** View data for the member payout page, as PayoutController builds it. */
$memberPage = static function (array $account): array {
    return (new PayoutController())->payoutContext($account) + [
        'page_title'  => 'Payouts',
        'current_nav' => 'payouts',
        'page_css'    => ['assets/css/wallet.css'],
        'page_js'     => ['assets/js/app-payout.js'],
    ];
};

/** View data for the admin queue, as AdminPayoutController builds it. */
$queuePage = static function (string $tab = 'pending'): array {
    return (new AdminPayoutController())->queueContext($tab) + [
        'page_title'  => 'Payouts',
        'current_nav' => 'admin.payouts',
        'page_css'    => ['assets/css/wallet.css'],
        'page_js'     => ['assets/js/admin-live.js'],
    ];
};

return [
    /* ---------- The member page ---------------------------------- */

    'P13-T1 the payout page carries the withdrawable card and the request form' => function () use ($render, $sidebar, $makeMember, $fund, $memberPage, $withSettings, $defaults, $gcash): void {
        $withSettings($defaults, function () use ($render, $sidebar, $makeMember, $fund, $memberPage, $gcash): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            /* Saved payout accounts prefill the form. */
            db()->prepare(
                'UPDATE users SET gcash_number = ?, maya_number = ?, usdt_trc20_address = ?
                  WHERE id = ?'
            )->execute([$gcash, '09179876543', 'T' . str_repeat('1', 33), $memberId]);

            test_signed_in_as($member);

            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

            $html = $render('member', 'member/payouts', $memberPage(Wallet::account($memberId)));

            assert_contains('Payouts', $html);
            assert_contains('data-live-surface="member.payouts:' . $memberId . '"', $html);

            /* The withdrawable bucket, not the total, is the headline. */
            assert_contains('data-live-region="payout-balance"', $html);
            assert_contains('Withdrawable', $html);
            assert_contains(money(2000), $html);
            assert_contains(money(500), $html, 'the seeded minimum');

            /* The form: target, CSRF, amount, four channels, four boxes. */
            assert_contains('action="' . url('payouts/request') . '"', $html);
            assert_contains('name="_csrf"', $html);
            assert_contains('name="amount"', $html);
            assert_contains('novalidate', $html, 'the server owns every refusal');
            assert_contains('data-min="500"', $html);

            foreach (['gcash' => 'GCash', 'maya' => 'Maya', 'usdt_trc20' => 'USDT (TRC20)', 'usdt_bep20' => 'USDT (BEP20)'] as $key => $label) {
                assert_contains('name="payout_method"', $html);
                assert_contains('value="' . $key . '"', $html);
                assert_contains($label, $html);
                assert_contains('name="account_' . $key . '"', $html);
            }

            /* The saved accounts prefilled each box. */
            assert_contains('value="' . $gcash . '"', $html);
            assert_contains('value="09179876543"', $html);

            /* The preview the script fills in. */
            assert_contains('data-payout-preview', $html);
            assert_contains('Service fee', $html);
            assert_contains('You receive', $html);
            assert_contains('data-payout-preview-usdt-row', $html);

            /* No history yet. */
            assert_contains('No payout requests yet', $html);

            /* The sidebar marks Payouts. */
            $nav = $sidebar($html);
            assert_contains('Payouts', $nav);
            assert_contains('menu-item active', $nav);
        });
    },

    'P13-T6 the USDT channels are closed while the rate is unset' => function () use ($render, $makeMember, $fund, $memberPage, $withSettings, $defaults): void {
        $withSettings(['usdt_rate' => '0'] + $defaults, function () use ($render, $makeMember, $fund, $memberPage): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            test_signed_in_as($member);

            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

            $html = $render('member', 'member/payouts', $memberPage(Wallet::account($memberId)));

            assert_contains('Unavailable until the ₱ per USDT rate is set.', $html);
            assert_contains('disabled', $html, 'the closed channel cannot be selected');
            assert_contains('USDT rate', $html);
            assert_not_contains('data-usdt-rate="56"', $html);

            /* The peso channels stay open. */
            assert_contains('No service fee', $html);
        });
    },

    'P13-T7 an open request closes the form and shows what is held' => function () use ($render, $makeMember, $fund, $memberPage, $withSettings, $defaults, $gcash): void {
        $withSettings(['service_fee_gcash' => '2'] + $defaults, function () use ($render, $makeMember, $fund, $memberPage, $gcash): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            (new PayoutService())->request($memberId, 'gcash', $gcash, '800.00');

            test_signed_in_as($member);

            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

            $html = $render('member', 'member/payouts', $memberPage(Wallet::account($memberId)));

            assert_contains('Withdrawal in progress', $html);
            assert_contains('Pending review', $html);
            assert_contains(money(800), $html, 'the held amount');
            assert_contains(money(784), $html, 'net of the 2% fee');
            assert_contains(money(16), $html, 'the fee');
            assert_contains($gcash, $html);
            assert_contains('Held by an open request', $html);
            assert_contains(money(1200), $html, 'the rest is still withdrawable');

            /* No form while a request is open: one at a time. */
            assert_not_contains('action="' . url('payouts/request') . '"', $html);
            assert_not_contains('Request payout', $html);
        });
    },

    'P13-T2 a withdrawable balance below the minimum explains itself' => function () use ($render, $makeMember, $fund, $memberPage, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($render, $makeMember, $fund, $memberPage): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '200.00');

            test_signed_in_as($member);

            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

            $html = $render('member', 'member/payouts', $memberPage(Wallet::account($memberId)));

            assert_contains('The minimum payout is', $html);
            assert_contains(money(200), $html);
            assert_not_contains('action="' . url('payouts/request') . '"', $html);

            /* A member with nothing withdrawable gets the earning advice. */
            $empty = $makeMember();

            test_signed_in_as($empty);

            $emptyHtml = $render('member', 'member/payouts', $memberPage(Wallet::account((int) $empty['id'])));

            assert_contains('Nothing to withdraw yet', $emptyHtml);
            assert_not_contains('action="' . url('payouts/request') . '"', $emptyHtml);
        });
    },

    'P13-T13 the member history shows every request, its fee, net, USDT and reason' => function () use ($render, $makeMember, $fund, $memberPage, $withSettings, $defaults, $gcash): void {
        $withSettings([
            'usdt_rate'              => '50',
            'service_fee_gcash'      => '2',
            'service_fee_usdt_trc20' => '5',
        ] + $defaults, function () use ($render, $makeMember, $fund, $memberPage, $gcash): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('pv_admin_' . bin2hex(random_bytes(4)), 'admin');
            $service  = new PayoutService();

            $fund($memberId, '9000.00');

            /* One completed peso payout … */
            $completed = $service->request($memberId, 'gcash', $gcash, '1000.00');
            $service->approve((int) $completed['payout_id'], (int) $admin['id']);
            $service->complete((int) $completed['payout_id'], (int) $admin['id'], 'Sent via GCash');

            /* … one rejected … */
            $rejected = $service->request($memberId, 'gcash', $gcash, '500.00');
            $service->reject((int) $rejected['payout_id'], (int) $admin['id'], 'Wrong account name');

            /* … and one open USDT request. */
            $usdt = $service->request($memberId, 'usdt_trc20', 'T' . str_repeat('1', 33), '800.00');

            test_signed_in_as($member);

            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

            $html = $render('member', 'member/payouts', $memberPage(Wallet::account($memberId)));

            assert_contains('data-live-region="payout-history"', $html);

            /* The completed row: gross, fee (2%), net, status. */
            assert_contains(money(1000), $html);
            assert_contains(money(20), $html);
            assert_contains(money(980), $html);
            assert_contains('Completed', $html);

            /* The rejected row carries the reason. */
            assert_contains('Rejected', $html);
            assert_contains('Wrong account name', $html);

            /* The USDT row shows the USDT amount it will send. */
            $expectedUsdt = round(((800 - 40) - (2.5 * 50)) / 50, 4);

            assert_contains(number_format($expectedUsdt, 4, '.', ','), $html);
            assert_contains('USDT', $html);

            /* Three requests are counted in the header; the completed one
             * is the only money that has actually been paid out. */
            assert_contains('3 requests', $html);
            assert_contains(money(2300), $html, 'the requested total');
            assert_contains(money(1000), $html, 'the paid-out total');
            assert_contains(money(20), $html, 'the fee total');

            /* The open card still owns the form area. */
            assert_contains('Withdrawal in progress', $html);
            assert_not_contains('action="' . url('payouts/request') . '"', $html);

            assert_contains('data-live-key="payout-' . (int) $usdt['payout_id'] . '"', $html);
        });
    },

    'P13-T1 the payout page escapes hostile names, accounts and notes' => function () use ($render, $makeMember, $fund, $memberPage, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($render, $makeMember, $fund, $memberPage): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('pv_escape_' . bin2hex(random_bytes(4)), 'admin');

            $fund($memberId, '2000.00');

            $service = new PayoutService();
            $result  = $service->request($memberId, 'gcash', '09171234567', '600.00');

            $service->reject((int) $result['payout_id'], (int) $admin['id'], '<script>alert(9)</script>');

            test_signed_in_as($member);

            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

            $html = $render('member', 'member/payouts', $memberPage(Wallet::account($memberId)));

            assert_not_contains('<script>alert(9)</script>', $html);
            assert_contains('&lt;script&gt;alert(9)&lt;/script&gt;', $html);
        });
    },

    'P13-T3 the form echoes a refusal and keeps the submitted values' => function () use ($render, $makeMember, $fund, $memberPage, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($render, $makeMember, $fund, $memberPage): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '1000.00');

            test_signed_in_as($member);

            errors_set(['amount' => 'You can withdraw up to ₱1,000.00 — the withdrawable part of your balance.']);
            old_set(['amount' => '5000.00', 'payout_method' => 'maya', 'account_maya' => '09179998888']);

            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

            $html = $render('member', 'member/payouts', $memberPage(Wallet::account($memberId)));

            assert_contains('is-invalid', $html);
            assert_contains('You can withdraw up to', $html);
            assert_contains('value="5000.00"', $html);
            assert_contains('value="09179998888"', $html, 'the typed account is kept');

            /* The Maya radio is reselected. */
            assert_contains('id="method_maya"', $html);
            assert_contains('value="maya"', $html);
        });
    },

    'P13-T12 an admin sees their own payout page in the back-office layout' => function () use ($render, $sidebar, $makeMember, $fund, $memberPage, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($render, $sidebar, $makeMember, $fund, $memberPage): void {
            $admin   = $makeMember('admin');
            $adminId = (int) $admin['id'];

            $fund($adminId, '2000.00');

            test_signed_in_as($admin);

            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/payouts';

            /* PayoutController picks the back-office nav key for staff. */
            $data = $memberPage(Wallet::account($adminId));
            $data['current_nav'] = 'admin.my_payouts';

            $html = $render('admin', 'member/payouts', $data);

            assert_contains('data-live-surface="member.payouts:' . $adminId . '"', $html);
            assert_contains('href="' . url('admin/products') . '"', $html, 'the back-office menu renders');
            assert_contains('href="' . url('admin/payouts') . '"', $html, 'the queue stays in the menu');
            assert_contains('My payouts', $html);

            /* The member page marks "My payouts" — not the queue — active. */
            $nav = $sidebar($html);
            assert_contains('My payouts', $nav);
            assert_true(
                (bool) preg_match(
                    '/href="' . preg_quote(url('payouts'), '/') . '"[^>]*aria-current="page"/',
                    $nav
                ),
                'the "My payouts" item is the active one'
            );
        });
    },

    /* ---------- The admin queue ---------------------------------- */

    'P13-T13 the queue lists the pending requests with their actions' => function () use ($render, $makeMember, $fund, $queuePage, $withSettings, $defaults, $gcash): void {
        $withSettings(['service_fee_gcash' => '2'] + $defaults, function () use ($render, $makeMember, $fund, $queuePage, $gcash): void {
            $admin   = test_user('pv_queue_admin_' . bin2hex(random_bytes(4)), 'admin');
            $member  = $makeMember();

            $fund((int) $member['id'], '3000.00');

            $result = (new PayoutService())->request((int) $member['id'], 'gcash', $gcash, '800.00');

            test_signed_in_as($admin);

            $_GET = ['status' => 'pending'];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/payouts?status=pending';

            $html = $render('admin', 'admin/payouts/index', $queuePage('pending'));

            assert_contains('data-live-surface="admin.payouts"', $html);
            assert_contains('data-live-config="config"', $html, 'a settings save reaches the queue');
            assert_contains('data-live-stale', $html, 'held rows are announced');
            assert_contains('data-live-region="payout-stats"', $html);
            assert_contains('data-live-region="payout-tabs"', $html);
            assert_contains('data-live-region="payout-rows"', $html);
            assert_contains('data-live-region="payout-pagination"', $html);

            /* The stat cards. */
            assert_contains('Held in open requests', $html);
            assert_contains('Waiting for approval', $html);
            assert_contains('Approved — sending', $html);
            assert_contains('Paid out', $html);

            /* The tabs. */
            assert_contains('Pending', $html);
            assert_contains('All requests', $html);

            /* The row: member, method, account, gross, fee, net. */
            assert_contains('@' . $member['username'], $html);
            assert_contains('href="' . url('admin/members/' . (int) $member['id']) . '"', $html);
            assert_contains('GCash', $html);
            assert_contains($gcash, $html);
            assert_contains(money(800), $html);
            assert_contains(money(16), $html, 'the fee');
            assert_contains(money(784), $html, 'the net');
            assert_contains('Pending review', $html);
            assert_contains('#' . (int) $result['payout_id'], $html);

            /* The actions: approve and reject-with-a-reason. */
            assert_contains('action="' . url('admin/payouts/' . (int) $result['payout_id'] . '/approve') . '"', $html);
            assert_contains('action="' . url('admin/payouts/' . (int) $result['payout_id'] . '/reject') . '"', $html);
            assert_contains('name="note"', $html);
            assert_contains('Rejection reason', $html);
            assert_contains('Approve', $html);
            assert_contains('Reject', $html);
            assert_not_contains('/complete"', $html, 'a pending request cannot complete yet');

            /* The filter form is outside the regions. */
            assert_contains('name="from"', $html);
            assert_contains('name="to"', $html);
            assert_contains('name="q"', $html);
            assert_contains('name="status" value="pending"', $html);
        });
    },

    'P13-T13 an approved request offers complete, a completed one offers nothing' => function () use ($render, $makeMember, $fund, $queuePage, $withSettings, $defaults, $gcash): void {
        $withSettings([
            'usdt_rate' => '50',
            'service_fee_usdt_trc20' => '5',
        ] + $defaults, function () use ($render, $makeMember, $fund, $queuePage): void {
            $admin   = test_user('pv_queue_admin2_' . bin2hex(random_bytes(4)), 'admin');
            $adminId = (int) $admin['id'];
            $member  = $makeMember();
            $other   = $makeMember();

            $fund((int) $member['id'], '3000.00');
            $fund((int) $other['id'], '3000.00');

            $service = new PayoutService();

            $approved = $service->request((int) $member['id'], 'usdt_trc20', 'T' . str_repeat('1', 33), '1000.00');
            $service->approve((int) $approved['payout_id'], $adminId);

            $completed = $service->request((int) $other['id'], 'gcash', '09171234567', '600.00');
            $service->approve((int) $completed['payout_id'], $adminId);
            $service->complete((int) $completed['payout_id'], $adminId, 'Sent');

            test_signed_in_as($admin);

            /* The approved tab: mark complete, and still rejectable. */
            $_GET = ['status' => 'approved'];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/payouts?status=approved';

            $html = $render('admin', 'admin/payouts/index', $queuePage('approved'));

            assert_contains('Approved', $html);
            assert_contains('action="' . url('admin/payouts/' . (int) $approved['payout_id'] . '/complete') . '"', $html);
            assert_contains('Mark complete', $html);
            assert_contains('USDT', $html);
            assert_contains('gas', $html);
            assert_not_contains('/approve"', $html, 'an approved request cannot be approved again');

            /* The completed tab: a record, no actions. */
            $_GET = ['status' => 'completed'];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/payouts?status=completed';

            $done = $render('admin', 'admin/payouts/index', $queuePage('completed'));

            assert_contains('Completed', $done);
            assert_contains('@' . $other['username'], $done);
            assert_contains('Sent', $done);
            assert_not_contains('/approve"', $done);
            assert_not_contains('/reject"', $done);
            assert_not_contains('/complete"', $done);
            assert_contains('Member', $done, 'a settled row still links to the member');
        });
    },

    'P13-T13 the queue filters by member and date and escapes hostile input' => function () use ($render, $makeMember, $fund, $queuePage, $withSettings, $defaults, $gcash): void {
        $withSettings($defaults, function () use ($render, $makeMember, $fund, $queuePage, $gcash): void {
            $admin   = test_user('pv_filter_admin_' . bin2hex(random_bytes(4)), 'admin');
            $adminId = (int) $admin['id'];

            $alice = $makeMember();
            $bob   = $makeMember();

            $fund((int) $alice['id'], '2000.00');
            $fund((int) $bob['id'], '2000.00');

            $service = new PayoutService();
            $service->request((int) $alice['id'], 'gcash', $gcash, '600.00');

            /* The hostile text lands in a rejection reason, which the queue
             * renders in the status cell and the search filter reads. */
            $bobRow = $service->request((int) $bob['id'], 'gcash', $gcash, '600.00');
            $service->reject((int) $bobRow['payout_id'], $adminId, '<script>alert(5)</script>');

            test_signed_in_as($admin);

            $_GET = ['status' => '', 'q' => 'alert'];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/payouts?q=alert';

            $html = $render('admin', 'admin/payouts/index', $queuePage(''));

            assert_not_contains('<script>alert(5)</script>', $html);
            assert_contains('&lt;script&gt;alert(5)&lt;/script&gt;', $html);
            assert_contains('@' . $bob['username'], $html);
            assert_not_contains('@' . $alice['username'], $html, 'the search narrowed the rows');

            /* A date range that excludes everything shows the empty state. */
            $_GET = ['status' => 'pending', 'from' => '2030-01-01', 'to' => '2030-12-31'];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/payouts?status=pending&from=2030-01-01&to=2030-12-31';

            $empty = $render('admin', 'admin/payouts/index', $queuePage('pending'));

            assert_contains('No pending payout requests', $empty);
            assert_contains('Clear', $empty);
            assert_contains('value="2030-01-01"', $empty);
        });
    },

    'P13-T12 the queue shows the settings it is working with' => function () use ($render, $queuePage, $withSettings, $defaults): void {
        $withSettings([
            'usdt_rate'            => '56',
            'usdt_trc20_gas_fee'   => '2.50',
            'usdt_bep20_gas_fee'   => '0.05',
            'service_fee_usdt_bep20' => '5',
        ] + $defaults, function () use ($render, $queuePage): void {
            $admin = test_user('pv_facts_admin_' . bin2hex(random_bytes(4)), 'admin');

            test_signed_in_as($admin);

            $_GET = ['status' => 'pending'];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/payouts?status=pending';

            $html = $render('admin', 'admin/payouts/index', $queuePage('pending'));

            assert_contains('USDT rate', $html);
            assert_contains('= 0.0179 USDT', $html, '₱1 at ₱56/USDT');
            assert_contains('2.5000 USDT gas', $html);
            assert_contains('0.0500 USDT gas', $html);
            assert_contains(money(500), $html, 'the minimum');
        });
    },
];
