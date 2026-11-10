<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminActivationLiveTest — the activation review queue
 *
 *  One live surface (`admin.activations`) for the whole queue. These cases
 *  pin:
 *
 *    · a manual request, an approval and a rejection bump the queue plus
 *      the member page whose history changed;
 *    · the feed returns the heading, counters, tabs, rows and pagination;
 *    · the rows are a held region because the rejection reason is typed
 *      directly into the table;
 *    · the page declares its live surface and loads the shared banner.
 * ============================================================== */

$pendingMember = static function (): array {
    $username = 'alv_' . bin2hex(random_bytes(4));

    $result = (new RegistrationService())->register([
        'mode'             => 'pending',
        'username'         => $username,
        'email'            => $username . '@test.local',
        'full_name'        => 'Activation Live Member',
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
    $tmp = (string) sys_get_temp_dir() . '/eco-alv-' . bin2hex(random_bytes(4)) . '.png';
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
        'reference_no' => 'GC-LIVE-' . bin2hex(random_bytes(3)),
    ], $proofFile);
};

return [
    'a manual request bumps the queue and the member page' => function () use ($pendingMember, $proofFile, $manualRequest): void {
        $member = $pendingMember();

        $queueBefore  = Live::revision('admin.activations');
        $memberBefore = Live::revision(Live::memberSurface((int) $member['id']));

        $result = $manualRequest($member, $proofFile());

        assert_same('pending_review', $result['status']);
        assert_same($queueBefore + 1, Live::revision('admin.activations'));
        assert_same($memberBefore + 1, Live::revision(Live::memberSurface((int) $member['id'])));
    },

    'an approval bumps the queue, the directory and the member page' => function () use ($pendingMember, $proofFile, $manualRequest): void {
        $member = $pendingMember();
        $admin  = test_user('alv_admin', 'admin');

        $request = $manualRequest($member, $proofFile());

        $queueBefore  = Live::revision('admin.activations');
        $listBefore   = Live::revision('admin.members');
        $memberBefore = Live::revision(Live::memberSurface((int) $member['id']));

        (new ActivationService())->approve((int) $request['activation_id'], $admin);

        assert_same($queueBefore + 1, Live::revision('admin.activations'));
        assert_same($memberBefore + 1, Live::revision(Live::memberSurface((int) $member['id'])));

        /* The directory moved twice: the activated member's row and the
         * sponsor's wallet balance (the commission credit). */
        assert_same($listBefore + 2, Live::revision('admin.members'));
    },

    'a rejection bumps the queue and the member page' => function () use ($pendingMember, $proofFile, $manualRequest): void {
        $member = $pendingMember();
        $admin  = test_user('alv_admin', 'admin');

        $request = $manualRequest($member, $proofFile());

        $queueBefore  = Live::revision('admin.activations');
        $memberBefore = Live::revision(Live::memberSurface((int) $member['id']));

        (new ActivationService())->reject((int) $request['activation_id'], $admin, 'The reference does not match.');

        assert_same($queueBefore + 1, Live::revision('admin.activations'));
        assert_same($memberBefore + 1, Live::revision(Live::memberSurface((int) $member['id'])));
    },

    'the queue feed renders header, counters, tabs, rows and pagination' => function (): void {
        $admin = test_user('alv_feed_admin', 'admin');
        $packageId = (int) Package::findBySlug('package-a')['id'];

        /* Six review rows so the pagination has a second page. */
        for ($i = 1; $i <= 6; $i++) {
            $member = test_user('alv_row_' . $i . '_' . bin2hex(random_bytes(2)));

            Activation::create([
                'user_id'        => (int) $member['id'],
                'package_id'     => $packageId,
                'amount'         => 4498.00,
                'payment_method' => 'gcash',
                'status'         => 'pending_review',
                'reference_no'   => 'GC-FEED-' . $i,
            ]);
        }

        $hostile = test_user('alv_<b>y</b>');

        Activation::create([
            'user_id'        => (int) $hostile['id'],
            'package_id'     => $packageId,
            'amount'         => 4498.00,
            'payment_method' => 'maya',
            'status'         => 'pending_review',
            'reference_no'   => 'MY-FEED-1',
        ]);

        test_signed_in_as($admin);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/activations?per_page=5';

        $controller = new AdminActivationController();
        $payload    = $controller->feedPayload(3, '"admin.activations:3:abc"', $controller->listContext());

        assert_true($payload['ok']);
        assert_same('admin.activations', $payload['surface']);
        assert_same(
            [
                'activation-header',
                'activation-stats',
                'activation-tabs',
                'activation-rows',
                'activation-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $header  = $payload['fragments']['activation-header'];
        $stats   = $payload['fragments']['activation-stats'];
        $tabs    = $payload['fragments']['activation-tabs'];
        $rows    = $payload['fragments']['activation-rows'];
        $pages   = $payload['fragments']['activation-pagination'];

        assert_contains('data-live-region="activation-header"', $header);
        assert_contains('awaiting review', $header);

        assert_contains('data-live-region="activation-stats"', $stats);
        assert_contains('Awaiting review', $stats);
        assert_contains('Approved', $stats);
        assert_contains('Rejected', $stats);

        assert_contains('data-live-region="activation-tabs"', $tabs);
        assert_contains('admin/activations?status=pending_review', $tabs);
        assert_contains('Awaiting review', $tabs);

        assert_contains('data-live-region="activation-rows"', $rows);
        assert_not_contains('data-live-hold', $rows, 'the reason lives in the confirmation modal');
        assert_contains('&lt;b&gt;y&lt;/b&gt;', $rows);
        assert_not_contains('<b>y</b>', $rows);
        assert_contains(money(4498), $rows);
        assert_contains('/approve', $rows);
        assert_contains('/reject', $rows);
        assert_contains('name="_csrf"', $rows);
        assert_contains('name="reason"', $rows, 'the hidden field the modal fills');
        assert_contains('data-confirm-title="Approve this activation?"', $rows);
        assert_contains('data-confirm-title="Reject this activation?"', $rows);
        assert_contains('data-confirm-prompt="reason"', $rows);
        assert_contains('data-confirm-prompt-label="Rejection reason"', $rows);
        assert_contains('data-confirm-prompt-max="160"', $rows);
        assert_not_contains('placeholder="Rejection reason"', $rows, 'the reason is typed in the modal');
        assert_not_contains('membership-filters', $rows, 'the filter form never travels in a row fragment');

        assert_contains('data-live-region="activation-pagination"', $pages);
        assert_contains('admin/activations?per_page=5&amp;page=2', $pages);
        assert_not_contains('/feed', $pages);

        assert_contains('admin/activations?per_page=5', $payload['meta']['canonical']);
    },

    'the queue feed renders its filtered empty state' => function (): void {
        test_signed_in_as(test_user('alv_empty_admin', 'admin'));

        $_GET = ['status' => 'rejected'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/activations?status=rejected';

        $controller = new AdminActivationController();
        $payload    = $controller->feedPayload(2, '"admin.activations:2:def"', $controller->listContext());

        $rows = $payload['fragments']['activation-rows'];

        assert_contains('No activations match these filters', $rows);
        assert_contains('Clear filters', $rows);
    },

    'the activation page declares its live surface and regions' => function (): void {
        $admin = test_user('alv_page_admin', 'admin');

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/activations';

        $controller = new AdminActivationController();
        $data       = $controller->listContext();

        ob_start();

        try {
            View::page('admin', 'admin/activations/index', $data + [
                'page_title'  => 'Activations',
                'current_nav' => 'admin.activations',
                'page_css'    => [
                    'assets/css/membership.css',
                    'assets/vendor/libs/sweetalert2/sweetalert2.css',
                ],
                'page_js'     => [
                    'assets/vendor/libs/sweetalert2/sweetalert2.js',
                    'assets/js/app-confirm.js',
                ],
                'live'        => Live::meta('admin.activations', 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.activations"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="activation-header"', $html);
        assert_contains('data-live-region="activation-stats"', $html);
        assert_contains('data-live-region="activation-tabs"', $html);
        assert_contains('data-live-region="activation-rows"', $html);
        assert_contains('data-live-region="activation-pagination"', $html);
        assert_not_contains('data-live-hold', $html);
        assert_not_contains('data-live-stale', $html, 'there is no unsaved inline input to announce');
        assert_contains('data-live-status', $html);
        assert_contains('sweetalert2.js', $html);
        assert_contains('app-confirm.js', $html);
        assert_not_contains('admin-live.js', $html);
    },
];
