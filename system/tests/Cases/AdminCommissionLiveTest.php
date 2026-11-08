<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminCommissionLiveTest — the company-wide commission ledger
 *
 *  One live surface (`admin.commissions`). These cases pin:
 *
 *    · a credited award and a skipped award bump the ledger;
 *    · the feed returns the card heading, counters, rows and pagination,
 *      keeps the filter form out of the rows and escapes every value;
 *    · the page declares its live surface and regions.
 * ============================================================== */

$activationFixture = static function (int $sourceId): array {
    $packageId = (int) Package::findBySlug('package-a')['id'];

    $activationId = Activation::create([
        'user_id'        => $sourceId,
        'package_id'     => $packageId,
        'amount'         => 4498.00,
        'payment_method' => 'gcash',
        'status'         => 'approved',
        'reference_no'   => 'GC-COM-' . bin2hex(random_bytes(3)),
    ]);

    return Activation::find($activationId) ?? [];
};

return [
    'a credited award bumps the commission ledger' => function () use ($activationFixture): void {
        $source    = test_user('cml_source_' . bin2hex(random_bytes(3)));
        $recipient = test_user('cml_recipient_' . bin2hex(random_bytes(3)));

        $activation = $activationFixture((int) $source['id']);
        $package    = Package::findBySlug('package-a');

        $ledgerBefore = Live::revision('admin.commissions');
        $memberBefore = Live::revision(Live::memberSurface((int) $recipient['id']));

        (new CommissionService())->award(
            $activation,
            $package,
            (int) $recipient['id'],
            Commission::LEVEL_DIRECT,
            500.00,
            'direct_referral'
        );

        assert_same($ledgerBefore + 1, Live::revision('admin.commissions'));

        /* The wallet credit also moves the recipient's member page. */
        assert_same($memberBefore + 1, Live::revision(Live::memberSurface((int) $recipient['id'])));
    },

    'a skipped award bumps the ledger without moving money' => function () use ($activationFixture): void {
        $source    = test_user('cml_skip_source_' . bin2hex(random_bytes(3)));
        $recipient = test_user('cml_skip_recipient_' . bin2hex(random_bytes(3)));

        $activation = $activationFixture((int) $source['id']);
        $package    = Package::findBySlug('package-a');
        $wallet     = new WalletService();

        $balanceBefore = $wallet->balance((int) $recipient['id']);
        $ledgerBefore  = Live::revision('admin.commissions');

        (new CommissionService())->skip(
            $activation,
            $package,
            (int) $recipient['id'],
            Commission::LEVEL_DIRECT,
            500.00,
            'recipient has no package',
            'direct_referral'
        );

        assert_same($ledgerBefore + 1, Live::revision('admin.commissions'));
        assert_same($balanceBefore, $wallet->balance((int) $recipient['id']));
    },

    'the ledger feed renders header, counters, rows and pagination' => function () use ($activationFixture): void {
        $admin     = test_user('cml_feed_admin', 'admin');
        $package   = Package::findBySlug('package-a');
        $packageId = (int) $package['id'];
        $hostile   = test_user('cml_<b>q</b>');

        $source = test_user('cml_feed_source');
        $activation = $activationFixture((int) $source['id']);

        /* Six credited rows from six sources plus one skipped row. */
        for ($i = 1; $i <= 6; $i++) {
            $from = test_user('cml_feed_source_' . $i . '_' . bin2hex(random_bytes(2)));

            Commission::create([
                'user_id'        => (int) $hostile['id'],
                'type'           => 'direct_referral',
                'amount'         => 500.00,
                'level'          => Commission::LEVEL_DIRECT,
                'source_user_id' => (int) $from['id'],
                'package_id'     => $packageId,
                'activation_id'  => (int) $activation['id'],
                'status'         => 'credited',
            ]);
        }

        Commission::create([
            'user_id'        => (int) $hostile['id'],
            'type'           => 'direct_referral',
            'amount'         => 500.00,
            'level'          => Commission::LEVEL_DIRECT,
            'source_user_id' => (int) $source['id'],
            'package_id'     => $packageId,
            'activation_id'  => (int) $activation['id'],
            'status'         => 'skipped',
            'reason'         => '<em>no package</em>',
        ]);

        test_signed_in_as($admin);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?per_page=5';

        $controller = new AdminCommissionController();
        $payload    = $controller->feedPayload(3, '"admin.commissions:3:abc"', $controller->listContext());

        assert_true($payload['ok']);
        assert_same('admin.commissions', $payload['surface']);
        assert_same(
            [
                'commission-header',
                'commission-stats',
                'commission-rows',
                'commission-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $header = $payload['fragments']['commission-header'];
        $stats  = $payload['fragments']['commission-stats'];
        $rows   = $payload['fragments']['commission-rows'];
        $pages  = $payload['fragments']['commission-pagination'];

        assert_contains('data-live-region="commission-header"', $header);
        assert_contains('Commission ledger', $header);
        assert_contains('rows', $header);

        assert_contains('data-live-region="commission-stats"', $stats);
        assert_contains('Total credited', $stats);
        assert_contains('This month', $stats);
        assert_contains('Skipped', $stats);

        assert_contains('data-live-region="commission-rows"', $rows);
        assert_contains('data-live-key=', $rows);
        assert_contains('data-live-focus="member"', $rows);
        assert_contains('data-live-focus="activation"', $rows);
        assert_contains('data-live-focus="detail"', $rows);
        assert_contains('&lt;b&gt;q&lt;/b&gt;', $rows);
        assert_not_contains('<b>q</b>', $rows);
        assert_contains('&lt;em&gt;no package&lt;/em&gt;', $rows);
        assert_not_contains('<em>no package</em>', $rows);
        assert_contains('data-commission=', $rows);
        assert_not_contains('commission-filters', $rows, 'the filter form never travels in a row fragment');

        assert_contains('data-live-region="commission-pagination"', $pages);
        assert_contains('admin/commissions?per_page=5&amp;page=2', $pages);
        assert_not_contains('/feed', $pages);

        assert_contains('admin/commissions?per_page=5', $payload['meta']['canonical']);
    },

    'the ledger feed renders the filtered empty state' => function (): void {
        test_signed_in_as(test_user('cml_empty_admin', 'admin'));

        $_GET = ['q' => 'no-such-recipient-anywhere'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?q=no-such-recipient-anywhere';

        $controller = new AdminCommissionController();
        $payload    = $controller->feedPayload(2, '"admin.commissions:2:def"', $controller->listContext());

        $rows = $payload['fragments']['commission-rows'];

        assert_contains('No commissions match these filters', $rows);
        assert_contains('Clear filters', $rows);
        assert_contains('data-live-region="commission-rows"', $rows);
    },

    'the commission page declares its live surface and regions' => function () use ($activationFixture): void {
        $admin   = test_user('cml_page_admin', 'admin');
        $source  = test_user('cml_page_source');
        $package = Package::findBySlug('package-a');

        $activation = $activationFixture((int) $source['id']);

        Commission::create([
            'user_id'        => (int) $admin['id'],
            'type'           => 'direct_referral',
            'amount'         => 500.00,
            'level'          => Commission::LEVEL_DIRECT,
            'source_user_id' => (int) $source['id'],
            'package_id'     => (int) $package['id'],
            'activation_id'  => (int) $activation['id'],
            'status'         => 'credited',
        ]);

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions';

        $controller = new AdminCommissionController();
        $data       = $controller->listContext();

        ob_start();

        try {
            View::page('admin', 'admin/commissions/index', $data + [
                'page_title'  => 'Commissions',
                'current_nav' => 'admin.commissions',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta('admin.commissions', 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.commissions"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="commission-header"', $html);
        assert_contains('data-live-region="commission-stats"', $html);
        assert_contains('data-live-region="commission-rows"', $html);
        assert_contains('data-live-region="commission-pagination"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('commission-filters', $html, 'the filter form is still on the page');
        assert_not_contains('data-live-stale', $html, 'no form region is held on this page');
    },
];
