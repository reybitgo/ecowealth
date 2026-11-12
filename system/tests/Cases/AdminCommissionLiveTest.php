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

/**
 * One paid order that pays the reference ladder to a four-deep chain.
 *
 * Product: price ₱300, P.V. ₱250, base auto, 5/5/5/3/3/2/2/2/1/1.
 * Line: 2 × product → base ₱500 → c ₱25, b ₱25, a ₱25, boss ₱15.
 * The buyer is never a recipient.
 *
 * `$deactivateLevel2` disables the second upline before the engine runs,
 * so level 2 is `skipped` and levels 3–4 still pay at their own numbers.
 *
 * @return array<string, mixed>
 */
$repeatFixture = static function (bool $deactivateLevel2 = false): array {
    $productId = Product::save([
        'name'             => 'P17D Coffee ' . bin2hex(random_bytes(3)),
        'sku'              => 'P17D-' . strtoupper(bin2hex(random_bytes(3))),
        'price'            => '300.00',
        'pv'               => '250.00',
        'unilevel_enabled' => '1',
        'unilevel_base'    => 'auto',
        'stock'            => '100',
        'status'           => 'active',
    ], null, ProductUnilevel::referencePreset());

    setting_set('unilevel_enabled', '1');
    setting_set('unilevel_max_levels', '10');
    setting_set('unilevel_requires_active_upline', '1');
    setting_set('unilevel_active_threshold', '500');
    setting_set('unilevel_reversal_enabled', '1');

    $buyer  = test_user('p17d_buyer_' . bin2hex(random_bytes(3)));
    $levels = [];
    $child  = $buyer;

    for ($level = 1; $level <= 4; $level++) {
        $upline = test_user('p17d_u' . $level . '_' . bin2hex(random_bytes(3)));

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $upline['id'], (int) $child['id']]);

        $levels[$level] = $upline;
        $child          = $upline;
    }

    if ($deactivateLevel2) {
        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([(int) $levels[2]['id']]);
    }

    $orderId = test_order((int) $buyer['id'], [['product_id' => $productId, 'quantity' => 2]], 'paid', [
        'paid_at'  => date('Y-m-d H:i:s'),
        'subtotal' => '600.00',
        'total'    => '600.00',
    ]);

    $itemId = (int) db()->query(
        'SELECT id FROM shop_order_items WHERE order_id = ' . $orderId . ' LIMIT 1'
    )->fetchColumn();

    db()->prepare(
        'UPDATE shop_order_items
            SET product_name = ?, product_sku = ?, unit_price = 300.00, line_total = 600.00,
                unit_base = 250.00, base_total = 500.00
          WHERE id = ?'
    )->execute(['P17D Coffee', 'P17D-1', $itemId]);

    (new UnilevelService())->onOrderPaid($orderId);

    return [
        'product_id' => $productId,
        'buyer'      => $buyer,
        'levels'     => $levels,
        'order_id'   => $orderId,
        'item_id'    => $itemId,
        'awards'     => UnilevelAward::forOrder($orderId),
    ];
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

    /* ==========================================================
     *  Phase 17D — the repeat-purchase tab
     * ========================================================== */

    'P17D-T1 the package tab keeps its ledger, filters and export (regression)' => function (): void {
        test_signed_in_as(test_user('p17d_pkg_admin', 'admin'));

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions';

        $controller = new AdminCommissionController();
        $data       = $controller->listContext();
        $payload    = $controller->feedPayload(2, '"admin.commissions:2:pkg"', $data);

        /* The package tab still renders exactly its own four regions. */
        assert_same('package', $data['tab']);
        assert_same(
            ['commission-header', 'commission-stats', 'commission-rows', 'commission-pagination'],
            array_keys($payload['fragments']),
            'the repeat fragments never appear on the package tab'
        );
        assert_same(Commission::stats(), $data['stats']);
        assert_same(Commission::countAdmin($data['filters']), $data['pager']->total);
        assert_contains('Commission ledger', $payload['fragments']['commission-header']);
        assert_not_contains('Repeat-purchase ledger', $payload['fragments']['commission-header']);

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

        /* Every package column, filter and the detail drawer survive. */
        foreach (['Recipient', 'Source', 'Type', 'Activation', 'Awarded'] as $column) {
            assert_contains($column, $html, 'the package table keeps its ' . $column . ' column');
        }

        foreach (['name="q"', 'name="type"', 'name="level"', 'name="status"', 'name="from"', 'name="to"'] as $field) {
            assert_contains($field, $html, 'the package filter form keeps ' . $field);
        }

        assert_contains('id="commissionDetail"', $html, 'the award detail drawer is still on the page');
        assert_not_contains('data-live-region="repeat-', $html);
        assert_not_contains('Repeat-purchase ledger', $html);
        assert_not_contains('direct_unilevel', $html);
        assert_not_contains('indirect_unilevel', $html);
    },

    'P17D-T2 the repeat tab renders stats, filters, rows and the empty state' => function () use ($repeatFixture): void {
        $admin = test_user('p17d_admin', 'admin');
        test_signed_in_as($admin);

        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat';

        $controller = new AdminCommissionController();

        /* Empty first: the pages teaches the operator the two switches. */
        $empty = $controller->repeatContext();
        $emptyPayload = $controller->feedPayload(1, '"admin.commissions:1:empty"', $empty);

        assert_same(
            ['repeat-header', 'repeat-stats', 'repeat-periods', 'repeat-rows', 'repeat-pagination'],
            array_keys($emptyPayload['fragments'])
        );
        $emptyRows = $emptyPayload['fragments']['repeat-rows'];
        assert_contains('No repeat-purchase bonus has been paid', $emptyRows);
        assert_contains('Turn the repeat-purchase stream on in Settings', $emptyRows);
        assert_contains('give a product a ladder', $emptyRows);

        /* Then a four-deep ladder pays the reference ₱90. */
        $fixture = $repeatFixture();

        $_GET = ['ledger' => 'repeat'];
        $data    = $controller->repeatContext();
        $payload = $controller->feedPayload(2, '"admin.commissions:2:repeat"', $data);

        $rows = $payload['fragments']['repeat-rows'];
        assert_contains('data-live-region="repeat-rows"', $rows);
        assert_contains('data-live-key=', $rows);

        foreach ($fixture['levels'] as $level => $upline) {
            assert_contains('@' . (string) $upline['username'], $rows, 'level ' . $level . ' is listed');
            assert_contains('>L' . $level . '<', $rows, 'the Lvl column carries level ' . $level);
        }

        assert_contains('P17D Coffee', $rows);
        assert_contains('500.00', $rows, 'the base snapshot is shown');
        assert_contains('5.000%', $rows, 'the rate is shown with three decimals');
        assert_contains('Credited', $rows);
        assert_contains('Repeat-purchase ledger', $payload['fragments']['repeat-header']);

        /* The page carries the filters, the tab pills and no leg column. */
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
        assert_contains('aria-current="page"', $html, 'the repeat pill is the active one');
        assert_contains('Package commissions', $html);
        assert_contains('Repeat-purchase bonus', $html);
        assert_contains('<th scope="col">Lvl</th>', $html);
        assert_contains('name="product_id"', $html);
        assert_contains('name="ledger" value="repeat"', $html);
        assert_not_contains('<th scope="col">Type</th>', $html, 'the repeat table has no direct/indirect type');
        assert_not_contains('direct_unilevel', $html);
        assert_not_contains('indirect_unilevel', $html);
        assert_not_contains('Level 1 · Direct', $html, 'one ladder: level 1 is not a special leg');
    },

    'P17D-T3 the stat tiles read the ledger exactly (credited, month, awards, recipients, skipped, unrecovered)' => function () use ($repeatFixture): void {
        $fixture = $repeatFixture();

        $stats = UnilevelAward::stats();

        assert_same(90.0, $stats['credited_total']);
        assert_same(4, $stats['credited']);
        assert_same(4, $stats['recipients']);
        assert_same(0, $stats['skipped']);
        assert_same(0, $stats['voided']);
        assert_same(0.0, $stats['unrecovered']);
        assert_same(22.5, $stats['average']);

        /* Every fixture award happened "now", so this month is the same total. */
        assert_same(90.0, $stats['this_month']);

        test_signed_in_as(test_user('p17d_stats_admin', 'admin'));
        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat';

        $controller = new AdminCommissionController();
        $payload    = $controller->feedPayload(3, '"x"', $controller->repeatContext());
        $tiles      = $payload['fragments']['repeat-stats'];

        assert_contains('data-live-region="repeat-stats"', $tiles);
        assert_contains('Total credited', $tiles);
        assert_contains(money(90.0), $tiles);
        assert_contains('4 members paid', $tiles);
        assert_contains('This month', $tiles);
        assert_contains('Awards', $tiles);
        assert_contains('Recipients', $tiles);
        assert_contains('Skipped', $tiles);
        assert_contains('Unrecovered', $tiles);
    },

    'P17D-T4 the period card lists closed months newest first' => function (): void {
        db()->prepare(
            'INSERT INTO unilevel_periods
               (period, closed_at, closed_by, threshold, members_total, qualified_count, award_total)
             VALUES (?, NOW(), NULL, 500.00, ?, ?, ?)'
        )->execute(['2026-09', 12, 7, 1234.56]);
        db()->prepare(
            'INSERT INTO unilevel_periods
               (period, closed_at, closed_by, threshold, members_total, qualified_count, award_total)
             VALUES (?, NOW(), NULL, 500.00, ?, ?, ?)'
        )->execute(['2026-10', 15, 9, 2000.00]);

        test_signed_in_as(test_user('p17d_period_admin', 'admin'));
        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat';

        $controller = new AdminCommissionController();
        $data       = $controller->repeatContext();
        $payload    = $controller->feedPayload(4, '"x"', $data);
        $card       = $payload['fragments']['repeat-periods'];

        assert_same(['2026-10', '2026-09'], array_map(
            static fn (array $period): string => (string) $period['period'],
            $data['periods']
        ));
        assert_contains('data-live-region="repeat-periods"', $card);
        assert_contains('Oct 2026', $card);
        assert_contains('Sep 2026', $card);
        assert_contains('15', $card);
        assert_contains('9', $card);
        assert_contains(money(2000.00), $card);
        assert_contains('2 closed', $card);
        assert_not_contains('Close month', $card, 'the card is a history, never a button');
    },

    'P17D-T5 the repeat filters narrow, combine and fall back silently' => function () use ($repeatFixture): void {
        $fixture = $repeatFixture();

        test_signed_in_as(test_user('p17d_filter_admin', 'admin'));

        $_GET = ['ledger' => 'repeat', 'level' => '3'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat&level=3';
        $level = (new AdminCommissionController())->repeatContext();
        assert_same('3', $level['filters']['level']);
        assert_same(1, $level['pager']->total);
        assert_same(
            (int) $fixture['levels'][3]['id'],
            (int) $level['awards'][0]['user_id']
        );

        $_GET = ['ledger' => 'repeat', 'product_id' => (string) $fixture['product_id']];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat&product_id=' . $fixture['product_id'];
        $product = (new AdminCommissionController())->repeatContext();
        assert_same(4, $product['pager']->total);

        $_GET = ['ledger' => 'repeat', 'q' => (string) $fixture['buyer']['username']];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat&q=' . $fixture['buyer']['username'];
        $search = (new AdminCommissionController())->repeatContext();
        assert_same(4, $search['pager']->total);

        /* Today's window includes the fixture; a window in 2020 does not. */
        $_GET = ['ledger' => 'repeat', 'from' => date('Y-m-d'), 'to' => date('Y-m-d')];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat&from=' . date('Y-m-d') . '&to=' . date('Y-m-d');
        assert_same(4, (new AdminCommissionController())->repeatContext()['pager']->total);

        $_GET = ['ledger' => 'repeat', 'from' => '2020-01-01', 'to' => '2020-01-31'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat&from=2020-01-01&to=2020-01-31';
        assert_same(0, (new AdminCommissionController())->repeatContext()['pager']->total);

        /* Every unknown value falls back to "no filter" (plan §8 Rule 7). */
        $_GET = [
            'ledger' => 'repeat', 'level' => '99', 'status' => 'paid',
            'product_id' => 'abc', 'from' => 'not-a-date', 'to' => '2026-99-99',
        ];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat&level=99';
        $fallback = (new AdminCommissionController())->repeatContext();
        assert_same(UnilevelAward::filterDefaults(), $fallback['filters']);
        assert_same(4, $fallback['pager']->total);
    },

    'P17D-T6 a skipped row shows the due amount and reason but never counts as money' => function () use ($repeatFixture): void {
        /* First order: all four levels credited. Second order with level 2
         * disabled: the same ₱25 is due at level 2 but skipped (no
         * compression), and levels 3–4 still pay at their own numbers. */
        $first = $repeatFixture();
        $second = $repeatFixture(true);

        $stats = UnilevelAward::stats();
        assert_same(155.0, $stats['credited_total'], '90 + 65 credited; the skipped 25 is not money');
        assert_same(1, $stats['skipped']);
        assert_same(7, $stats['credited']);

        test_signed_in_as(test_user('p17d_skip_admin', 'admin'));
        $_GET = ['ledger' => 'repeat', 'status' => 'skipped'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat&status=skipped';

        $controller = new AdminCommissionController();
        $data       = $controller->repeatContext();
        $payload    = $controller->feedPayload(5, '"x"', $data);

        assert_same(1, $data['pager']->total);
        assert_same((int) $second['levels'][2]['id'], (int) $data['awards'][0]['user_id']);

        $rows = $payload['fragments']['repeat-rows'];
        assert_contains('Skipped', $rows);
        assert_contains(money(25.0), $rows, 'the amount that was due is shown');
        assert_contains('recipient account is disabled', $rows);
        assert_contains('not paid', $rows);
        assert_contains('7 members paid', $payload['fragments']['repeat-stats']);
    },

    'P17D-T7 a voided award shows what was recovered and the shortfall stays visible' => function () use ($repeatFixture): void {
        $fixture = $repeatFixture();

        /* Void level 1 only, as a cancellation might when the wallet had
         * already spent most of the credit (the reversal itself is 17C). */
        $level1 = UnilevelAward::forOrder($fixture['order_id'])[0];
        assert_same(1, (int) $level1['level']);
        UnilevelAward::void((int) $level1['id'], [
            'reversed_amount' => 10.00,
            'void_reason'     => 'QA17D reversal fixture',
        ]);

        $stats = UnilevelAward::stats();
        assert_same(65.0, $stats['credited_total']);
        assert_same(1, $stats['voided']);
        assert_same(15.0, $stats['unrecovered']);

        test_signed_in_as(test_user('p17d_void_admin', 'admin'));
        $_GET = ['ledger' => 'repeat', 'status' => 'voided'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat&status=voided';

        $controller = new AdminCommissionController();
        $payload    = $controller->feedPayload(6, '"x"', $controller->repeatContext());
        $rows       = $payload['fragments']['repeat-rows'];
        $tiles      = $payload['fragments']['repeat-stats'];

        assert_contains('Voided', $rows);
        assert_contains('recovered of', $rows);
        assert_contains(money(10.0), $rows);
        assert_contains(money(25.0), $rows);
        assert_contains(money(15.0) . ' shortfall', $rows);
        assert_contains('Unrecovered', $tiles);
        assert_contains(money(15.0), $tiles);
    },

    'P17D-T8 the CSV shape carries the screen columns and every filtered row' => function () use ($repeatFixture): void {
        $fixture = $repeatFixture();

        $filters = UnilevelAward::normaliseFilters(['level' => '2']);
        $rows    = UnilevelAward::exportRows(null, $filters);

        assert_same(1, count($rows));
        assert_same(
            [
                'Award', 'Date', 'Order', 'Buyer', 'Recipient', 'Product', 'Level',
                'Base', 'Rate %', 'Amount', 'Status', 'Reason', 'Recovered',
            ],
            UnilevelAward::csvHeader()
        );

        $csv = UnilevelAward::csvRows($rows);
        assert_count(1, $csv);
        assert_count(count(UnilevelAward::csvHeader()), $csv[0]);
        assert_same((string) $rows[0]['id'], $csv[0][0]);
        assert_same((string) $fixture['levels'][2]['username'], $csv[0][4]);
        assert_same('2', $csv[0][6]);
        assert_same('500.00', $csv[0][7]);
        assert_same('5.000', $csv[0][8]);
        assert_same('25.00', $csv[0][9]);
        assert_same('credited', $csv[0][10]);
    },

    'P17D-T14 an award bumps the surface and the repeat tab refreshes, the package tab unaffected' => function () use ($repeatFixture): void {
        test_signed_in_as(test_user('p17d_live_admin', 'admin'));

        $before = Live::revision('admin.commissions');

        $fixture = $repeatFixture();

        assert_same($before + 1, Live::revision('admin.commissions'), 'a paid order bumps the ledger surface');

        /* The two tabs never share an ETag: their query strings differ. */
        $revision = Live::revision('admin.commissions');
        $packageEtag = Live::etag('admin.commissions', $revision, Live::scope([], 1));
        $repeatEtag  = Live::etag('admin.commissions', $revision, Live::scope(['ledger' => 'repeat'], 1));
        assert_not_same($packageEtag, $repeatEtag);

        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat';

        $controller = new AdminCommissionController();
        $repeat     = $controller->feedPayload($revision, $repeatEtag, $controller->repeatContext());
        assert_contains((string) $fixture['levels'][1]['username'], $repeat['fragments']['repeat-rows']);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions';

        $package = $controller->feedPayload($revision, $packageEtag, $controller->listContext());
        assert_not_contains((string) $fixture['levels'][1]['username'], $package['fragments']['commission-rows']);
        assert_not_contains('repeat-rows', (string) json_encode(array_keys($package['fragments'])));
    },
];
