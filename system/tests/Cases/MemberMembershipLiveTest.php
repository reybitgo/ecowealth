<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberMembershipLiveTest — the member's own /membership page
 *
 *  One live surface per member (`member.membership:{id}`). These cases pin:
 *
 *    · activation requests, approvals, status changes and wallet movements
 *      bump that member's page (and only that member's surface);
 *    · the feed renders the heading, current membership, the three
 *      activation paths (held while typed into) and the history;
 *    · the open-request, active-associate, standard and suspended states
 *      replace the forms correctly;
 *    · the page declares its live surface and loads the shared banner.
 * ============================================================== */

$pendingMember = static function (): array {
    $username = 'mmlv_' . bin2hex(random_bytes(4));

    $result = (new RegistrationService())->register([
        'mode'             => 'pending',
        'username'         => $username,
        'email'            => $username . '@test.local',
        'full_name'        => 'Membership Live Member',
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
    $tmp = (string) sys_get_temp_dir() . '/eco-mmlv-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($tmp, TEST_PNG_BYTES);

    return [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ];
};

$manualRequest = static function (array $member, array $proofFile): array {
    return (new ActivationService())->request((int) $member['id'], [
        'method'       => 'manual',
        'package_id'   => (string) Package::findBySlug('package-a')['id'],
        'channel'      => 'gcash',
        'reference_no' => 'GC-MMLV-' . bin2hex(random_bytes(3)),
    ], $proofFile);
};

return [
    'membership surfaces are per member and bump together' => function (): void {
        $before = Live::revision('member.membership:4242');

        Live::touchMembership(4242);

        assert_same('member.membership:4242', Live::membershipSurface(4242));
        assert_same($before + 1, Live::revision('member.membership:4242'));

        $mine   = Live::meta('member.membership:4242', 'user:1');
        $theirs = Live::meta('member.membership:4242', 'user:2');

        assert_true($mine['enabled']);
        assert_not_same($mine['etag'], $theirs['etag'], 'the ETag is request/member specific');
    },

    'activation, status and wallet writes bump the membership page' => function () use ($pendingMember, $proofFile, $manualRequest): void {
        $member = $pendingMember();
        $admin  = test_user('mmlv_admin', 'admin');
        $id     = (int) $member['id'];

        $before = Live::revision(Live::membershipSurface($id));

        $request = $manualRequest($member, $proofFile());

        assert_same($before + 1, Live::revision(Live::membershipSurface($id)), 'manual request');

        $before = Live::revision(Live::membershipSurface($id));

        (new ActivationService())->approve((int) $request['activation_id'], $admin);

        assert_same($before + 1, Live::revision(Live::membershipSurface($id)), 'approval');

        $before = Live::revision(Live::membershipSurface($id));

        (new WalletService())->credit($id, '75.00', 'topup', 9101, 'Membership probe', false);

        assert_same($before + 1, Live::revision(Live::membershipSurface($id)), 'wallet movement');

        $before = Live::revision(Live::membershipSurface($id));

        Member::setStatus($id, 'suspended');

        assert_same($before + 1, Live::revision(Live::membershipSurface($id)), 'status change');
    },

    'the membership feed renders the activation paths for a pending member' => function () use ($pendingMember): void {
        $member = $pendingMember();
        $id     = (int) $member['id'];

        db()->prepare('UPDATE users SET full_name = ? WHERE id = ?')
            ->execute(['<b>Membership Owner</b>', $id]);

        $member = Member::find($id);
        assert_not_null($member);

        (new WalletService())->credit($id, '100.00', 'topup', 9201, 'Activation funds', false);

        $packageId = (int) Package::findBySlug('package-a')['id'];

        /* Six past requests so the history lands on page 2 (5 per page). */
        for ($i = 1; $i <= 6; $i++) {
            Activation::create([
                'user_id'        => $id,
                'package_id'     => $packageId,
                'amount'         => 4498.00,
                'payment_method' => 'gcash',
                'status'         => 'approved',
                'reference_no'   => 'GC-HIST-' . $i,
            ]);
        }

        $_GET = ['page' => '2', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/membership?page=2&per_page=5';

        $controller = new MembershipController();
        $payload    = $controller->feedPayload(7, '"member.membership:' . $id . ':7:abc"', $controller->membershipContext($member));

        assert_same('member.membership:' . $id, $payload['surface']);
        assert_same(
            [
                'membership-header',
                'membership-current',
                'membership-repeat',
                'membership-paths',
                'membership-history',
            ],
            array_keys($payload['fragments'])
        );

        $header  = $payload['fragments']['membership-header'];
        $current = $payload['fragments']['membership-current'];
        $paths   = $payload['fragments']['membership-paths'];
        $history = $payload['fragments']['membership-history'];

        /* 17E adds the repeat-purchase paragraph as its own region, with
         * no form and no action (plan §9 Rule 12 / QA17E-08). */
        $repeat = $payload['fragments']['membership-repeat'];
        assert_contains('data-live-region="membership-repeat"', $repeat);
        assert_not_contains('<form', $repeat);

        assert_contains('data-live-region="membership-header"', $header);
        assert_contains('Membership', $header);
        assert_contains('Pending', $header);

        assert_contains('data-live-region="membership-current"', $current);
        assert_contains('&lt;b&gt;Membership Owner&lt;/b&gt;', $current);
        assert_not_contains('<b>Membership Owner</b>', $current);
        assert_contains('Package', $current);
        assert_contains('None', $current);

        assert_contains('data-live-region="membership-paths"', $paths);
        assert_contains('data-live-hold="dirty"', $paths);
        assert_contains('name="method" value="code"', $paths);
        assert_contains('name="method" value="ewallet"', $paths);
        assert_contains('name="method" value="manual"', $paths);
        assert_contains('name="proof"', $paths);
        assert_contains('name="_csrf"', $paths);
        assert_contains(money(100), $paths, 'the e-wallet path shows the current balance');
        assert_contains('Company receiving accounts', $paths);

        assert_contains('data-live-region="membership-history"', $history);
        assert_contains('Activation history', $history);
        assert_contains('Approved', $history);
        assert_contains('Rows per page', $history);
        assert_contains('page=1', $history);

        assert_contains('membership?per_page=5&page=2', $payload['meta']['canonical']);
    },

    'the feed replaces the paths with the open-request notice' => function () use ($pendingMember, $proofFile, $manualRequest): void {
        $member = $pendingMember();
        $id     = (int) $member['id'];

        $manualRequest($member, $proofFile());

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/membership';

        $controller = new MembershipController();
        $payload    = $controller->feedPayload(2, '"member.membership:' . $id . ':2:def"', $controller->membershipContext(Member::find($id)));

        $paths   = $payload['fragments']['membership-paths'];
        $history = $payload['fragments']['membership-history'];

        assert_contains('Activation in progress', $paths);
        assert_contains('pending review', $paths);
        assert_not_contains('name="method" value="code"', $paths, 'no second request while one is open');
        assert_contains('Pending review', $history);
    },

    'the feed shows the active associate state and its history' => function () use ($pendingMember, $proofFile, $manualRequest): void {
        $member = $pendingMember();
        $id     = (int) $member['id'];
        $admin  = test_user('mmlv_active_admin', 'admin');

        $request = $manualRequest($member, $proofFile());

        (new ActivationService())->approve((int) $request['activation_id'], $admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/membership';

        $controller = new MembershipController();
        $member     = Member::find($id);
        assert_not_null($member);

        $payload = $controller->feedPayload(3, '"member.membership:' . $id . ':3:ghi"', $controller->membershipContext($member));

        $header  = $payload['fragments']['membership-header'];
        $current = $payload['fragments']['membership-current'];
        $paths   = $payload['fragments']['membership-paths'];
        $history = $payload['fragments']['membership-history'];

        assert_contains('Active associate', $header);
        assert_contains('Package A', $current);
        assert_contains('Active associate', $paths);
        assert_not_contains('name="method" value="ewallet"', $paths);
        assert_contains('Approved', $history);
    },

    'the feed renders the standard and suspended notices' => function (): void {
        $standard = test_user('mmlv_standard_' . bin2hex(random_bytes(3)));
        $suspended = test_user('mmlv_suspended_' . bin2hex(random_bytes(3)));

        Member::setStatus((int) $suspended['id'], 'suspended');

        $controller = new MembershipController();

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/membership';

        $standardPayload = $controller->feedPayload(
            1,
            '"member.membership:' . (int) $standard['id'] . ':1:std"',
            $controller->membershipContext(Member::find((int) $standard['id']))
        );

        $standardPaths = $standardPayload['fragments']['membership-paths'];

        assert_contains('Standard membership.', $standardPaths);
        assert_not_contains('name="method" value="code"', $standardPaths);

        $suspendedPayload = $controller->feedPayload(
            1,
            '"member.membership:' . (int) $suspended['id'] . ':1:sus"',
            $controller->membershipContext(Member::find((int) $suspended['id']))
        );

        $suspendedPaths = $suspendedPayload['fragments']['membership-paths'];

        assert_contains('This account is suspended', $suspendedPaths);
        assert_not_contains('name="method" value="manual"', $suspendedPaths);
        assert_contains('Suspended', $suspendedPayload['fragments']['membership-header']);
    },

    'the membership page declares its live surface and regions' => function () use ($pendingMember): void {
        $member = $pendingMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/membership';

        $controller = new MembershipController();
        $data       = $controller->membershipContext(Member::find($id));

        ob_start();

        try {
            View::page('member', 'member/membership', $data + [
                'page_title'  => 'Membership',
                'current_nav' => 'membership',
                'page_css'    => ['assets/css/membership.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta(Live::membershipSurface($id), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.membership:' . $id . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="membership-header"', $html);
        assert_contains('data-live-region="membership-current"', $html);
        assert_contains('data-live-region="membership-paths"', $html);
        assert_contains('data-live-region="membership-history"', $html);
        assert_contains('data-live-hold="dirty"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_contains('Referral link', $html);
    },
];
