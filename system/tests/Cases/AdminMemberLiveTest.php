<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminMemberLiveTest — the member directory and one member page
 *
 *  Two live surfaces: `admin.members` for the directory and
 *  `admin.members:{id}` for one member. These cases pin:
 *
 *    · the list + detail keys bump together;
 *    · a wallet movement, a status change and a registration bump the
 *      surfaces that show their data (including the sponsor chain);
 *    · the directory feed returns the heading, counters, filter form, rows
 *      and pagination, keeps the typed search out of the rows and keeps
 *      the page/filter URLs;
 *    · the detail feed renders every card and escapes every text value;
 *    · both pages declare their live surfaces and load the shared banner.
 * ============================================================== */

return [
    'member surfaces use the list + detail keys and bump together' => function (): void {
        $listBefore   = Live::revision('admin.members');
        $detailBefore = Live::revision('admin.members:4242');

        Live::touchMember(4242);

        assert_same('admin.members:4242', Live::memberSurface(4242));
        assert_same($listBefore + 1, Live::revision('admin.members'));
        assert_same($detailBefore + 1, Live::revision('admin.members:4242'));

        $meta = Live::meta('admin.members', 'user:1');

        assert_true($meta['enabled']);
        assert_same($listBefore + 1, $meta['revision']);
        assert_contains('"admin.members:' . ($listBefore + 1) . ':', $meta['etag']);
    },

    'wallet movements and status changes bump the member surfaces' => function (): void {
        $member = test_user('mlv_money_member');
        $id     = (int) $member['id'];

        $listBefore   = Live::revision('admin.members');
        $detailBefore = Live::revision(Live::memberSurface($id));

        (new WalletService())->credit($id, '125.00', 'commission', 8101, 'Earnings');

        assert_same($listBefore + 1, Live::revision('admin.members'), 'wallet credit (directory)');
        assert_same($detailBefore + 1, Live::revision(Live::memberSurface($id)), 'wallet credit (member)');

        $listBefore   = Live::revision('admin.members');
        $detailBefore = Live::revision(Live::memberSurface($id));

        Member::setStatus($id, 'suspended');

        assert_same($listBefore + 1, Live::revision('admin.members'), 'status change (directory)');
        assert_same($detailBefore + 1, Live::revision(Live::memberSurface($id)), 'status change (member)');
    },

    'registration bumps the directory, the new member and the sponsor chain' => function (): void {
        $sponsor = Member::findByUsername('juan');
        assert_not_null($sponsor);

        $username = 'mlv_reg_' . bin2hex(random_bytes(4));

        $listBefore       = Live::revision('admin.members');
        $sponsorBefore    = Live::revision(Live::memberSurface((int) $sponsor['id']));

        $result = (new RegistrationService())->register([
            'mode'             => 'standard',
            'username'         => $username,
            'email'            => $username . '@test.local',
            'full_name'        => 'Registration Live Test',
            'phone'            => '',
            'password'         => 'Member@1234',
            'password_confirm' => 'Member@1234',
            'sponsor'          => 'juan',
            'package_id'       => '',
            'reg_code'         => '',
            'terms'            => true,
            'binary_position'  => 'left',
        ], null);

        $newId = (int) $result['user_id'];

        assert_same($listBefore + 1, Live::revision('admin.members'));
        assert_same(1, Live::revision(Live::memberSurface($newId)));
        assert_same($sponsorBefore + 1, Live::revision(Live::memberSurface((int) $sponsor['id'])));
    },

    'the directory feed renders stats, filters, rows and pagination' => function (): void {
        $admin   = test_user('mlv_feed_admin', 'admin');
        $hostile = test_user('mlv_<b>x</b>');

        (new WalletService())->credit((int) $hostile['id'], '88.50', 'topup', 8201, 'Welcome', false);

        test_signed_in_as($admin);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?per_page=5';

        $controller = new AdminMemberController();
        $payload    = $controller->feedPayload(4, '"admin.members:4:abc"', $controller->listContext());

        assert_true($payload['ok']);
        assert_same('admin.members', $payload['surface']);
        assert_same(4, $payload['revision']);
        assert_same(
            [
                'member-header',
                'member-stats',
                'member-filters',
                /* Phase 20E: the Team Harvest queue card is its own region. */
                'member-harvest',
                'member-rows',
                'member-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $header  = $payload['fragments']['member-header'];
        $stats   = $payload['fragments']['member-stats'];
        $filters = $payload['fragments']['member-filters'];
        $rows    = $payload['fragments']['member-rows'];
        $pages   = $payload['fragments']['member-pagination'];
        $harvest = $payload['fragments']['member-harvest'];

        assert_contains('data-live-region="member-header"', $header);
        assert_contains('joined this week', $header);

        assert_contains('data-live-region="member-stats"', $stats);
        assert_contains('With a package', $stats);

        /* Phase 19B: the stats strip carries the derived rank distribution. */
        assert_contains('data-rank-distribution', $stats);
        assert_contains('Rank distribution', $stats);
        assert_contains('data-rank-count="member"', $stats);
        assert_contains('data-rank-count="director"', $stats);
        assert_contains('ranked (Supervisor+)', $stats);

        assert_contains('data-live-region="member-filters"', $filters);
        assert_contains('data-live-hold="dirty"', $filters);
        assert_contains('name="q"', $filters);
        assert_contains('name="package"', $filters);
        assert_contains('No package', $filters);
        /* Phase 20E: the Team Harvest filter shares the form. */
        assert_contains('name="harvest"', $filters);
        assert_contains('Team Harvest: all', $filters);

        /* Phase 20E: the queue card explains its empty state. */
        assert_contains('data-live-region="member-harvest"', $harvest);
        assert_contains('Team Harvest queue', $harvest);

        assert_contains('data-live-region="member-rows"', $rows);
        assert_contains('data-live-key="' . (int) $hostile['id'] . '"', $rows);
        assert_contains('data-live-focus="member"', $rows);
        assert_contains('data-live-focus="status"', $rows);
        assert_contains('&lt;b&gt;x&lt;/b&gt;', $rows);
        assert_not_contains('<b>x</b>', $rows);
        assert_contains(money(88.5), $rows);
        assert_not_contains('name="q"', $rows, 'the filter form never travels in a row fragment');

        /* Phase 19B: each member row carries its derived rank badge. */
        assert_contains('data-live-key="' . (int) $hostile['id'] . '"', $rows);
        assert_contains('bg-label-secondary">Member<', $rows);

        assert_contains('data-live-region="member-pagination"', $pages);
        assert_contains('admin/members?per_page=5&amp;page=2', $pages);
        assert_not_contains('/feed', $pages);

        assert_contains('admin/members?per_page=5', $payload['meta']['canonical']);
    },

    'the directory feed renders its filtered empty state' => function (): void {
        test_signed_in_as(test_user('mlv_empty_admin', 'admin'));

        $_GET = ['q' => 'no-such-member-anywhere'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?q=no-such-member-anywhere';

        $controller = new AdminMemberController();
        $payload    = $controller->feedPayload(2, '"admin.members:2:def"', $controller->listContext());

        $rows = $payload['fragments']['member-rows'];

        assert_contains('No members match these filters', $rows);
        assert_contains('Clear filters', $rows);
        assert_contains('data-live-region="member-rows"', $rows);
    },

    'the member detail feed renders every card and keeps escaping' => function (): void {
        $admin = test_user('mlv_detail_admin', 'admin');
        $maria = Member::findByUsername('maria');
        assert_not_null($maria);

        $id = (int) $maria['id'];

        db()->prepare('UPDATE users SET full_name = ? WHERE id = ?')
            ->execute(['<b>Maria Owner</b>', $id]);

        $maria = Member::find($id);
        assert_not_null($maria);

        $wallet = new WalletService();
        $wallet->credit($id, '250.00', 'topup', 8301, 'Live detail top-up', false);

        Activation::create([
            'user_id'        => $id,
            'package_id'     => (int) Package::findBySlug('package-a')['id'],
            'amount'         => 4498.00,
            'payment_method' => 'gcash',
            'status'         => 'pending_review',
            'reference_no'   => 'QA-LIVE-1',
        ]);

        AuditLog::record([
            'action'       => 'member.status',
            'actor_id'     => (int) $admin['id'],
            'actor_role'   => 'admin',
            'subject_type' => 'user',
            'subject_id'   => $id,
            'meta'         => ['from' => 'active', 'to' => 'active'],
        ]);

        test_signed_in_as($admin);

        $_GET = ['page' => '2', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members/' . $id . '?page=2&per_page=5';

        $controller = new AdminMemberController();
        $payload    = $controller->detailFeedPayload(
            5,
            '"admin.members:' . $id . ':5:xyz"',
            $controller->memberContext($maria)
        );

        assert_same('admin.members:' . $id, $payload['surface']);
        assert_same(
            [
                'member-heading',
                'member-account',
                'member-membership',
                'member-wallet',
                'member-royalty',
                /* Phase 20E: the Team Harvest panel is its own region. */
                'member-team-harvest',
                /* Phase 21E: the General Harvest panel is its own region. */
                'member-general-harvest',
                'member-activations',
                'member-upline',
                'member-audit',
            ],
            array_keys($payload['fragments'])
        );

        $fragments = $payload['fragments'];
        $heading   = $fragments['member-heading'];
        $account   = $fragments['member-account'];
        $membership = $fragments['member-membership'];
        $walletCard = $fragments['member-wallet'];
        $activations = $fragments['member-activations'];
        $upline    = $fragments['member-upline'];
        $audit     = $fragments['member-audit'];

        assert_contains('data-live-region="member-heading"', $heading);
        assert_contains('&lt;b&gt;Maria Owner&lt;/b&gt;', $heading);
        assert_not_contains('<b>Maria Owner</b>', $heading);
        assert_contains('Suspend member', $heading);
        assert_contains('action="' . url('admin/members/' . $id . '/status') . '"', $heading);
        assert_contains('name="_csrf"', $heading);

        assert_contains('data-live-region="member-account"', $account);
        assert_contains('Referral code', $account);
        assert_contains('data-copy=', $account);

        assert_contains('data-live-region="member-membership"', $membership);
        assert_contains('Package A', $membership);
        assert_contains('@juan', $membership);
        assert_contains('Team', $membership);

        assert_contains('data-live-region="member-wallet"', $walletCard);
        assert_contains('Balance', $walletCard);
        assert_contains('Withdrawable', $walletCard);
        assert_contains('Paid to the shop', $walletCard);
        assert_contains('Commission credited', $walletCard);
        assert_contains('Activation requests', $walletCard);

        assert_contains('data-live-region="member-royalty"', $fragments['member-royalty']);

        /* Phase 20E: the member panel renders, qualified or not. */
        assert_contains('data-live-region="member-team-harvest"', $fragments['member-team-harvest']);
        assert_contains('Team Harvest', $fragments['member-team-harvest']);

        assert_contains('data-live-region="member-activations"', $activations);
        assert_contains('Activation history', $activations);
        assert_contains('Pending review', $activations);
        assert_contains('GCash', $activations);
        assert_contains('Rows per page', $activations);

        assert_contains('data-live-region="member-upline"', $upline);
        assert_contains('@juan', $upline);
        assert_contains('L1', $upline);

        assert_contains('data-live-region="member-audit"', $audit);
        assert_contains('member.status', $audit);

        assert_same(url('admin/members/' . $id . '?per_page=5'), $payload['meta']['canonical']);
    },

    'the member pages declare their live surfaces and regions' => function (): void {
        $admin = test_user('mlv_page_admin', 'admin');
        $maria = Member::findByUsername('maria');
        assert_not_null($maria);

        $controller = new AdminMemberController();

        test_signed_in_as($admin);

        $_GET = ['status' => 'active'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?status=active';

        $indexData = $controller->listContext();

        ob_start();

        try {
            View::page('admin', 'admin/members/index', $indexData + [
                'page_title'  => 'Members',
                'current_nav' => 'admin.members',
                'page_css'    => ['assets/css/membership.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta('admin.members', 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.members"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="member-header"', $html);
        assert_contains('data-live-region="member-stats"', $html);
        assert_contains('data-live-region="member-filters"', $html);
        assert_contains('data-live-region="member-rows"', $html);
        assert_contains('data-live-region="member-pagination"', $html);
        assert_contains('data-live-key="' . (int) $maria['id'] . '"', $html, 'the active filter emits a keyed row');
        assert_contains('data-live-focus="member"', $html);
        assert_contains('<th>Rank</th>', $html, 'the directory has the Rank column');
        assert_contains('data-rank-distribution', $html);
        assert_contains('data-live-hold="dirty"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_contains('membership-filters', $html);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members/' . (int) $maria['id'];

        $detailData = $controller->memberContext(Member::find((int) $maria['id']));

        ob_start();

        try {
            View::page('admin', 'admin/members/show', $detailData + [
                'page_title'  => 'Member — maria',
                'current_nav' => 'admin.members',
                'page_css'    => ['assets/css/membership.css'],
                'page_js'     => ['assets/js/app-confirm.js'],
                'live'        => Live::meta(Live::memberSurface((int) $maria['id']), 'page'),
            ]);
        } finally {
            $detailHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.members:' . (int) $maria['id'] . '"', $detailHtml);
        assert_contains('data-live-region="member-heading"', $detailHtml);
        assert_contains('data-live-region="member-account"', $detailHtml);
        assert_contains('data-live-region="member-membership"', $detailHtml);
        assert_contains('data-live-region="member-wallet"', $detailHtml);
        assert_contains('data-live-region="member-royalty"', $detailHtml);
        assert_contains('data-live-region="member-activations"', $detailHtml);
        assert_contains('data-live-region="member-upline"', $detailHtml);
        assert_contains('data-live-region="member-audit"', $detailHtml);
        assert_contains('data-live-status', $detailHtml);
        assert_contains('action="' . url('admin/members/' . (int) $maria['id'] . '/status') . '"', $detailHtml);
        assert_not_contains('data-live-stale', $detailHtml, 'the member page has no held form region');
    },

    'P18-T45 the member page shows the pending repeat-purchase outside the wallet balance' => function (): void {
        $admin  = test_user('p18_member_admin', 'admin');
        $member = test_user('p18_member_pending_' . bin2hex(random_bytes(3)));
        $buyer  = test_user('p18_member_buyer_' . bin2hex(random_bytes(3)));

        $productId = (int) db()->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
        $orderId = test_order((int) $buyer['id'], [['product_id' => $productId]], 'delivered', [
            'paid_at'      => date('Y-m-d H:i:s'),
            'delivered_at' => date('Y-m-d H:i:s'),
            'total'        => '100.00',
        ]);
        $itemId = (int) db()->query('SELECT id FROM shop_order_items WHERE order_id = ' . $orderId)->fetchColumn();

        UnilevelAward::create([
            'order_id'      => $orderId,
            'order_item_id' => $itemId,
            'product_id'    => $productId,
            'buyer_id'      => (int) $buyer['id'],
            'user_id'       => (int) $member['id'],
            'level'         => 1,
            'base_amount'   => 500.00,
            'rate_pct'      => 5.0,
            'amount'        => 25.00,
            'hold_days'     => 7,
            'status'        => 'pending',
            'paid_at'       => date('Y-m-d H:i:s'),
        ]);

        test_signed_in_as($admin);

        $controller = new AdminMemberController();
        $data       = $controller->memberContext(Member::find((int) $member['id']));

        assert_same(25.0, (float) $data['pending_repeat']['total']);
        assert_same(1, (int) $data['pending_repeat']['count']);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members/' . (int) $member['id'];

        ob_start();

        try {
            View::page('admin', 'admin/members/show', $data + [
                'page_title'  => 'Member',
                'current_nav' => 'admin.members',
                'live'        => Live::meta('admin.members:' . (int) $member['id'], 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Pending repeat-purchase', $html);
        assert_contains('Not in the wallet balance', $html);
        assert_contains(money(25), $html);
    },
];
