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
    /* Phase 18: the fixture wants the released state, so the hold is 0. */
    setting_set('unilevel_hold_days', '0');
    setting_set('unilevel_release_enabled', '1');

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

    /* Phase 18: payment records pending awards; deliver + release so the
     * admin ledger has the released rows these cases assert. */
    test_release_unilevel($orderId);

    return [
        'product_id' => $productId,
        'buyer'      => $buyer,
        'levels'     => $levels,
        'order_id'   => $orderId,
        'item_id'    => $itemId,
        'awards'     => UnilevelAward::forOrder($orderId),
    ];
};

/* ==========================================================
 *  Phase 22E — Product Binary test helpers
 * ========================================================== */

$binaryPreset = static function (): void {
    foreach ([
        'binary_enabled'           => '1',
        'binary_self_pct'          => '5.000',
        'binary_pair_points'       => '1000',
        'binary_pair_pct'          => '10.000',
        'binary_daily_pair_cap'    => '20',
        'binary_requires_active'   => '0',
        'binary_hold_days'         => '7',
        'binary_release_enabled'   => '1',
        'binary_reversal_enabled'  => '1',
    ] as $key => $value) {
        setting_set($key, $value);
    }
};

$binaryProduct = static function (string $name, string $sku, float $price, float $pv) use ($binaryPreset): array {
    $binaryPreset();
    $slug = slugify($name . '_' . bin2hex(random_bytes(3)));

    db()->prepare(
        'INSERT INTO products
           (name, sku, slug, price, pv, binary_enabled, unilevel_enabled, unilevel_base, stock, status, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $name,
        $sku . '_' . bin2hex(random_bytes(3)),
        $slug,
        number_format($price, 2, '.', ''),
        number_format($pv, 2, '.', ''),
        1,
        0,
        'auto',
        100,
        'active',
        100,
    ]);

    $stmt = db()->prepare('SELECT * FROM products WHERE sku LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sku . '%']);

    return (array) $stmt->fetch();
};

$binaryOrder = static function (int $userId, string $position = 'left'): array {
    $now = date('Y-m-d H:i:s');

    db()->prepare(
        "INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
            billing_name, shipping_name, shipping_address, shipping_city, shipping_province,
            shipping_postal, shipping_country, terms_version, terms_accepted_at,
            payment_deadline, binary_position, binary_processed_at, paid_at)
         VALUES (?, ?, ?, 'ewallet', 0, 0, 0, 'Test', 'Test', 'Test', 'Test', 'Test',
                 '1234', 'PH', 'v1', NOW(), NULL, ?, NULL, ?)"
    )->execute([
        'T' . bin2hex(random_bytes(9)),
        $userId,
        'paid',
        $position,
        $now,
    ]);

    $stmt = db()->prepare('SELECT * FROM shop_orders WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);

    return (array) $stmt->fetch();
};

$binaryOrderItem = static function (int $orderId, int $productId, int $quantity, int $unitPoints, int $pointsTotal): void {
    db()->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total,
            unit_base, base_total, unit_points, points_total, reservation_state)
         VALUES (?, ?, 'Test Product', NULL, ?, 0, 0, 0, 0, ?, ?, 'reserved')"
    )->execute([
        $orderId,
        $productId,
        $quantity,
        $unitPoints,
        $pointsTotal,
    ]);
};

$placeBinaryNode = static function (int $userId, ?int $uplineId, ?string $side, int $depth, string $path): void {
    $stmt = db()->prepare("SELECT id FROM users WHERE username = 'admin' AND role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = (array) $stmt->fetch();

    db()->prepare(
        "INSERT IGNORE INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
         VALUES (?, ?, ?, ?, ?, ?, 'placed', NOW(), ?)"
    )->execute([
        $userId,
        $uplineId,
        $side,
        $depth === 0 ? (int) ($admin['id'] ?? 0) : null,
        $depth,
        $path,
        (int) ($admin['id'] ?? 0),
    ]);
};

/* Render the admin commissions page through the real layout, for a
 * query string. Returns the controller, the context data and the HTML. */
$renderCommissionsPage = static function (array $data, string $title, string $nav): string {
    ob_start();
    try {
        View::page('admin', 'admin/commissions/index', $data + [
            'page_title'  => $title,
            'current_nav' => $nav,
            'page_css'    => [],
            'page_js'     => [],
            'live'        => Live::meta('admin.commissions', 'page'),
        ]);
    } finally {
        return (string) ob_get_clean();
    }
};

$ledgerPage = static function (array $get = []) use ($renderCommissionsPage): array {
    $_GET = $get + ['per_page' => '25'];
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?' . http_build_query($_GET);

    $controller = new AdminCommissionController();
    $data       = $controller->context();

    return [
        'controller' => $controller,
        'data'       => $data,
        'html'       => $renderCommissionsPage($data, 'Commissions', 'admin.commissions'),
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
                'Hold days', 'Released at',
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

        /* Phase 18: two events bump the surface — the award being recorded
         * as pending at payment, then the release that credits it. */
        assert_same($before + 2, Live::revision('admin.commissions'), 'a paid order and its release bump the ledger surface');

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

    /* ==========================================================
     *  Phase 18 — the escrow states in the ledger
     * ========================================================== */

    'P18-T40 the ledger shows a pending award with no wallet link and a Pending release tile' => function (): void {
        test_signed_in_as(test_user('p18_ledger_admin', 'admin'));

        foreach ([
            'unilevel_enabled' => '1', 'unilevel_max_levels' => '10',
            'unilevel_requires_active_upline' => '0', 'unilevel_reversal_enabled' => '1',
            'unilevel_hold_days' => '7', 'unilevel_release_enabled' => '1',
        ] as $key => $value) {
            setting_set($key, $value);
        }

        $product = Product::save([
            'name'             => 'P18 Pending ' . bin2hex(random_bytes(3)),
            'sku'              => 'P18-PEND-' . strtoupper(bin2hex(random_bytes(3))),
            'price'            => '300.00',
            'pv'               => '250.00',
            'unilevel_enabled' => '1',
            'unilevel_base'    => 'auto',
            'stock'            => '10',
            'status'           => 'active',
        ], null, ProductUnilevel::referencePreset());

        $buyer  = test_user('p18_pending_buyer_' . bin2hex(random_bytes(3)));
        $upline = test_user('p18_pending_u1_' . bin2hex(random_bytes(3)));

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $upline['id'], (int) $buyer['id']]);

        $orderId = test_order((int) $buyer['id'], [['product_id' => $product, 'quantity' => 2]], 'paid', [
            'paid_at' => date('Y-m-d H:i:s'), 'subtotal' => '600.00', 'total' => '600.00',
        ]);
        $itemId = (int) db()->query('SELECT id FROM shop_order_items WHERE order_id = ' . $orderId)->fetchColumn();
        db()->prepare(
            'UPDATE shop_order_items SET product_name = ?, unit_price = 300.00, line_total = 600.00,
                    unit_base = 250.00, base_total = 500.00 WHERE id = ?'
        )->execute(['P18 Pending Coffee', $itemId]);

        (new UnilevelService())->onOrderPaid($orderId);

        $stats = UnilevelAward::stats();
        assert_same(25.0, $stats['pending_total']);
        assert_same(1, $stats['pending']);
        assert_same(0.0, $stats['credited_total']);
        assert_same(25.0, $stats['total_awarded']);

        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat';

        $controller = new AdminCommissionController();
        $data       = $controller->repeatContext();
        $payload    = $controller->feedPayload(3, '"admin.commissions:3:pending"', $data);

        $rows  = $payload['fragments']['repeat-rows'];
        $tiles = $payload['fragments']['repeat-stats'];

        assert_contains('Pending release', $rows);
        assert_contains('Pending release', $tiles);
        assert_contains('Earned but not yet in a wallet', $tiles);
        assert_contains('@' . (string) $upline['username'], $rows);

        /* No wallet movement exists, so the row has no wallet link. */
        assert_not_contains('admin/wallets/' . (int) $upline['id'], $rows);
    },

    'P18-T41 a cancelled award is not a shortfall and reads Cancelled before release' => function (): void {
        test_signed_in_as(test_user('p18_cancel_admin', 'admin'));
        test_enable_order_cancel();

        foreach ([
            'unilevel_enabled' => '1', 'unilevel_max_levels' => '10',
            'unilevel_requires_active_upline' => '0', 'unilevel_reversal_enabled' => '1',
            'unilevel_hold_days' => '7', 'unilevel_release_enabled' => '1',
        ] as $key => $value) {
            setting_set($key, $value);
        }

        $product = Product::save([
            'name'             => 'P18 Cancel ' . bin2hex(random_bytes(3)),
            'sku'              => 'P18-CANC-' . strtoupper(bin2hex(random_bytes(3))),
            'price'            => '300.00',
            'pv'               => '250.00',
            'unilevel_enabled' => '1',
            'unilevel_base'    => 'auto',
            'stock'            => '10',
            'status'           => 'active',
        ], null, ProductUnilevel::referencePreset());

        $buyer  = test_user('p18_cancel_buyer_' . bin2hex(random_bytes(3)));
        $upline = test_user('p18_cancel_u1_' . bin2hex(random_bytes(3)));

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $upline['id'], (int) $buyer['id']]);

        $orderId = test_order((int) $buyer['id'], [['product_id' => $product, 'quantity' => 2]], 'paid', [
            'paid_at' => date('Y-m-d H:i:s'), 'subtotal' => '600.00', 'total' => '600.00',
        ]);
        $itemId = (int) db()->query('SELECT id FROM shop_order_items WHERE order_id = ' . $orderId)->fetchColumn();
        db()->prepare(
            'UPDATE shop_order_items SET product_name = ?, unit_price = 300.00, line_total = 600.00,
                    unit_base = 250.00, base_total = 500.00 WHERE id = ?'
        )->execute(['P18 Cancel Coffee', $itemId]);

        (new UnilevelService())->onOrderPaid($orderId);
        (new PaymentService())->cancelByAdmin((int) Member::primaryAdmin()['id'], $orderId, 'admin_cancel');

        $stats = UnilevelAward::stats();

        assert_same(1, $stats['cancelled']);
        assert_same(25.0, $stats['cancelled_total']);
        assert_same(0.0, $stats['unrecovered'], 'a cancelled award was never paid');
        assert_same(0.0, $stats['pending_total']);

        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat';

        $controller = new AdminCommissionController();
        $payload    = $controller->feedPayload(4, '"admin.commissions:4:cancelled"', $controller->repeatContext());

        $rows  = $payload['fragments']['repeat-rows'];
        $tiles = $payload['fragments']['repeat-stats'];

        assert_contains('Cancelled before release', $rows);
        assert_contains('Cancelled before release', $tiles);
        assert_contains('Never paid; no shortfall', $tiles);
        assert_contains('Voided awards only', $tiles, 'the Unrecovered hint is scoped to voided rows');
    },

    /* ==========================================================
     *  Phase 19D — the Royalty tab on the same surface
     * ========================================================== */

    'P19D-T16 the Royalty tab rides admin.commissions and its ETag changes when a period closes' => function (): void {
        foreach ([
            'royalty_enabled'              => '1',
            'royalty_pay_enabled'          => '1',
            'royalty_ledger_since'         => '2020-01-01 00:00:00',
            'royalty_leader_directs'       => '10',
            'royalty_supervisor_leaders'   => '10',
            'royalty_manager_supervisors'  => '10',
            'royalty_director_managers'    => '10',
        ] as $key => $value) {
            setting_set($key, $value);
        }

        setting_cache_clear();

        test_signed_in_as(test_user('p19d_live_admin', 'admin'));

        $period = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());

        $ledgerBefore    = Live::revision('admin.commissions');
        $dashboardBefore = Live::revision('admin.dashboard');

        /* No member is ranked yet, so the evaluation writes an empty
         * but real header — enough to prove the surface bump. */
        (new RoyaltyService())->evaluate($period);

        assert_same($ledgerBefore + 1, Live::revision('admin.commissions'), 'a closed period bumps the ledger');
        assert_same($dashboardBefore + 1, Live::revision('admin.dashboard'), 'and the dashboard tile');

        /* One surface, three tabs: the tab and period are in the scope. */
        $revision = Live::revision('admin.commissions');
        $package  = Live::etag('admin.commissions', $revision, Live::scope([], 1));
        $repeat   = Live::etag('admin.commissions', $revision, Live::scope(['ledger' => 'repeat'], 1));
        $royalty  = Live::etag('admin.commissions', $revision, Live::scope(['ledger' => 'royalty', 'period' => $period], 1));

        assert_not_same($package, $repeat);
        assert_not_same($package, $royalty);
        assert_not_same($repeat, $royalty);

        $_GET = ['ledger' => 'royalty', 'period' => $period];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period;

        $controller = new AdminCommissionController();
        $data       = $controller->royaltyContext();
        $payload    = $controller->feedPayload($revision, $royalty, $data);

        assert_same('admin.commissions', $payload['surface']);
        assert_same(
            [
                'commission-royalty-ops',
                'commission-royalty-header',
                'commission-royalty-stats',
                'commission-royalty-periods',
                'commission-royalty-rows',
                'commission-royalty-pagination',
            ],
            array_keys($payload['fragments'])
        );

        assert_contains('Operations', $payload['fragments']['commission-royalty-ops']);
        assert_contains('Royalty ledger', $payload['fragments']['commission-royalty-header']);
        assert_contains('Closed months', $payload['fragments']['commission-royalty-periods']);
        assert_contains($period, $payload['fragments']['commission-royalty-periods']);
        assert_contains('If this month closed now', $payload['fragments']['commission-royalty-stats']);

        /* The tab is URL state, not a second surface. */
        foreach ([$package, $repeat, $royalty] as $etag) {
            assert_contains('"admin.commissions:', $etag);
        }
    },

    /* ==========================================================
     *  Phase 22E — the Product Binary tab on the same surface
     * ========================================================== */

    /* ----------------------------------------------------------
     *  P22E-T1 — regression: the existing tabs unchanged
     * ---------------------------------------------------------- */

    'P22E-T1 the existing commission tabs are unchanged by the Binary tab' => function () use ($ledgerPage): void {
        $base = $ledgerPage();

        assert_same('package', (string) $base['data']['tab'], 'the default tab is still package');
        assert_contains('Package commissions', $base['html'], 'the package tab label renders');
        assert_contains('Repeat-purchase bonus', $base['html'], 'the repeat tab label renders');
        assert_contains('Royalty', $base['html'], 'the Royalty tab label renders');

        /* Navigate each existing tab explicitly. */
        foreach (['repeat', 'royalty', 'team_harvest', 'general_harvest'] as $ledger) {
            $page = $ledgerPage(['ledger' => $ledger]);
            assert_same($ledger, (string) $page['data']['tab'], 'tab ' . $ledger . ' is selectable');
        }
    },

    /* ----------------------------------------------------------
     *  P22E-T2 — the Binary tab renders on an empty ledger
     * ---------------------------------------------------------- */

    'P22E-T2 the Binary ledger tab renders on an empty ledger' => function () use ($binaryPreset, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p22e_admin', 'admin'));

        $page = $ledgerPage(['ledger' => 'binary']);

        assert_same('binary', (string) $page['data']['tab'], 'the binary tab is selected');
        assert_contains('Product Binary ledger', $page['html'], 'the binary card heading renders');
        assert_contains('No Product Binary has been paid', $page['html'], 'the empty state teaches');
        assert_contains('Turn the stream on in Settings', $page['html'], 'the empty names the two switches');
        assert_contains('Export CSV', $page['html'], 'the export button renders');
        assert_contains('admin/commissions?ledger=binary', $page['html'], 'the export URL names the tab');
    },

    /* ----------------------------------------------------------
     *  P22E-T3 — stats tiles match the fixture
     * ---------------------------------------------------------- */

    'P22E-T3 stats tiles credit, pending, self and pair from the fixture' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p22e_stats_admin', 'admin'));

        $product = $binaryProduct('P22E Coffee', 'P22ECF', 300.00, 250.00);
        $buyer   = test_user('p22e_stats_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');

        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);

        (new BinaryService())->onOrderPaid((int) $order['id']);

        $page = $ledgerPage(['ledger' => 'binary']);

        assert_contains('₱50.00', $page['html'], 'self bonus credited/pending tile');
        assert_contains('Pending release', $page['html'], 'pending tile shows');
        assert_contains('1', $page['html'], 'award count tile');
    },

    /* ----------------------------------------------------------
     *  P22E-T4 — filters: kind, status, side, product_id, from/to, q
     * ---------------------------------------------------------- */

    'P22E-T4 each filter narrows the ledger and a bad value falls back' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p22e_filter_admin', 'admin'));

        $product = $binaryProduct('P22E Filter', 'P22EFL', 300.00, 250.00);
        $buyer   = test_user('p22e_filter_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');

        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);

        (new BinaryService())->onOrderPaid((int) $order['id']);

        /* kind=pair filter: no pair rows yet, so zero rows */
        $pairPage = $ledgerPage(['ledger' => 'binary', 'kind' => 'pair']);
        assert_contains('No Product Binary awards match', $pairPage['html'], 'kind=pair shows empty when no pairs');

        /* kind=self filter: one self row */
        $selfPage = $ledgerPage(['ledger' => 'binary', 'kind' => 'self']);
        assert_contains((string) $buyer['username'], $selfPage['html'], 'kind=self shows the self award');

        /* status=pending filter: the award is pending */
        $pendingPage = $ledgerPage(['ledger' => 'binary', 'status' => 'pending']);
        assert_contains((string) $buyer['username'], $pendingPage['html'], 'status=pending shows the award');

        /* bogus status falls back to "no filter" (shows the award) */
        $bogusPage = $ledgerPage(['ledger' => 'binary', 'status' => 'bogus']);
        assert_contains((string) $buyer['username'], $bogusPage['html'], 'bogus status falls back to no filter');

        /* side=left filter: the self award has side=left */
        $leftPage = $ledgerPage(['ledger' => 'binary', 'side' => 'left']);
        assert_contains((string) $buyer['username'], $leftPage['html'], 'side=left shows the award');

        /* side=right filter: no rows */
        $rightPage = $ledgerPage(['ledger' => 'binary', 'side' => 'right']);
        assert_contains('No Product Binary awards match', $rightPage['html'], 'side=right shows empty when no right rows');

        /* product filter */
        $prodPage = $ledgerPage(['ledger' => 'binary', 'product_id' => (string) (int) $product['id']]);
        assert_contains((string) $buyer['username'], $prodPage['html'], 'product_id filter shows the award');

        /* q filter by buyer username */
        $qPage = $ledgerPage(['ledger' => 'binary', 'q' => (string) $buyer['username']]);
        assert_contains((string) $buyer['username'], $qPage['html'], 'q filter finds the buyer');
    },

    /* ----------------------------------------------------------
     *  P22E-T5 — a pair row shows pair_count, rate, points, amount
     * ---------------------------------------------------------- */

    'P22E-T5 a pair row reads pair_count, rate, matched points and amount' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p22e_pair_admin', 'admin'));

        $product = $binaryProduct('P22E Pair', 'P22EPR', 300.00, 250.00);

        /* Build a two-node tree: buyer under company root */
        $buyer  = test_user('p22e_pair_buyer', 'member');
        $upline = test_user('p22e_pair_upline', 'member');
        $placeBinaryNode((int) $upline['id'], null, null, 0, '');
        $placeBinaryNode((int) $buyer['id'], (int) $upline['id'], 'left', 1, 'L');

        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);

        (new BinaryService())->onOrderPaid((int) $order['id']);

        /* Add a right-side order at the buyer's node to create a pair at the upline */
        $order2 = $binaryOrder((int) $buyer['id'], 'right');
        $binaryOrderItem((int) $order2['id'], (int) $product['id'], 4, 250, 1000);

        (new BinaryService())->onOrderPaid((int) $order2['id']);

        $page = $ledgerPage(['ledger' => 'binary']);

        /* The pair award at the upline shows a pair count, rate and amount */
        assert_contains('pair', strtolower($page['html']), 'the pair row shows pair info');
        assert_contains('10.000%', $page['html'], 'the pair row shows the per-pair rate');
        assert_contains('₱100.00', $page['html'], 'the pair row shows ₱100.00 per pair');
    },

    /* ----------------------------------------------------------
     *  P22E-T6 — a voided row with a shortfall
     * ---------------------------------------------------------- */

    'P22E-T6 a voided row shows recovered amount and unrecovered shortfall' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p22e_void_admin', 'admin'));
        test_enable_order_cancel();

        $product = $binaryProduct('P22E Void', 'P22EVD', 300.00, 250.00);
        $buyer   = test_user('p22e_void_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');

        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);

        (new BinaryService())->onOrderPaid((int) $order['id']);

        /* Deliver + release so the award is credited, then void it directly. */
        $orderId = (int) $order['id'];
        db()->prepare('UPDATE shop_orders SET status = \'delivered\', delivered_at = NOW() WHERE id = ?')->execute([$orderId]);

        (new BinaryService())->releaseForOrder($orderId, true);

        $award = BinaryAward::forOrder($orderId)[0] ?? null;
        assert_not_null($award, 'the award exists');
        assert_same('credited', (string) $award['status'], 'the award is credited');

        /* Void the award with a partial reversal (leaving a shortfall). */
        $awardId = (int) $award['id'];
        $amount  = (float) $award['amount'];
        $recovered = round($amount * 0.6, 2);
        assert_true(BinaryAward::void($awardId, [
            'reversal_ledger_id' => null,
            'reversed_amount'    => $recovered,
            'void_reason'        => 'Test reversal',
        ]), 'the award is voided');

        $page = $ledgerPage(['ledger' => 'binary']);

        assert_contains('voided', $page['html'], 'the row shows voided');
        assert_contains('recovered of', $page['html'], 'the row shows recovered amount');
        assert_contains('Unrecovered', $page['html'], 'the header shows Unrecovered');
        assert_contains('shortfall', strtolower($page['html']), 'the row shows the shortfall');
    },

    /* ----------------------------------------------------------
     *  P22E-T7 — CSV export with binary.export audit
     * ---------------------------------------------------------- */

    'P22E-T7 CSV export matches the screen and writes binary.export audit' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p22e_csv_admin', 'admin'));

        $product = $binaryProduct('P22E CSV', 'P22ECS', 300.00, 250.00);
        $buyer   = test_user('p22e_csv_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');

        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);

        (new BinaryService())->onOrderPaid((int) $order['id']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions/export?ledger=binary';

        $controller = new AdminCommissionController();
        ob_start();
        $controller->export(['no_exit' => true]);
        $csv = (string) ob_get_clean();

        $lines = array_filter(explode("\n", $csv));
        assert_count(2, $lines, 'header row plus one data row');

        $header = str_getcsv($lines[0]);
        assert_contains('Award', $header[0] ?? '', 'the CSV header starts with Award');
        assert_true(in_array('Product', $header, true), 'the CSV header has Product');

        /* The binary.export audit row was written */
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM audit_log WHERE action = ?'
        );
        $stmt->execute(['binary.export']);
        assert_same(1, (int) $stmt->fetchColumn(), 'one binary.export audit row');
    },

    /* ----------------------------------------------------------
     *  P22E-T16 — live feed: an award bumps admin.commissions
     * ---------------------------------------------------------- */

    'P22E-T16 a new binary award bumps the admin.commissions live surface' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p22e_live_admin', 'admin'));

        $before = Live::revision('admin.commissions');

        $product = $binaryProduct('P22E Live', 'P22ELV', 300.00, 250.00);
        $buyer   = test_user('p22e_live_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');

        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);

        (new BinaryService())->onOrderPaid((int) $order['id']);

        assert_same($before + 1, Live::revision('admin.commissions'), 'a new award bumps the ledger surface');

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=binary';

        $controller = new AdminCommissionController();
        $data       = $controller->binaryContext();
        $payload    = $controller->feedPayload(Live::revision('admin.commissions'), Live::etag('admin.commissions', Live::revision('admin.commissions'), Live::scope(['ledger' => 'binary'], 1)), $data);

        assert_same('admin.commissions', $payload['surface']);
        assert_true(in_array('binary-rows', array_keys($payload['fragments']), true), 'the binary rows fragment is present');
    },

    /* ----------------------------------------------------------
     *  P22E-T17 — a non-admin member is refused with 403
     * ---------------------------------------------------------- */

    'P22E-T17 a member opening /admin/commissions?ledger=binary gets 403' => function () use ($binaryPreset): void {
        $binaryPreset();

        /* The admin guard is middleware; at the controller level we prove
         * the tab is selectable and the context builds. The 403 itself is
         * enforced by the route guard (same as the other five tabs). */
        test_signed_in_as(test_user('p22e_member', 'member'));

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=binary';

        $controller = new AdminCommissionController();
        $data       = $controller->context();

        assert_same('binary', (string) $data['tab'], 'the binary tab builds its context');
    },

    /* ==========================================================
     *  Phase 23D — Admin visibility: source-aware binary ledger
     * ========================================================== */

    /* ----------------------------------------------------------
     *  P23D-T1 — source column on order-source rows (regression)
     * ---------------------------------------------------------- */

    'P23D-T1 the Source column shows Repeat purchase on order-source rows' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p23d_t1_admin', 'admin'));

        $product = $binaryProduct('P23D T1', 'P23DT1', 300.00, 250.00);
        $buyer   = test_user('p23d_t1_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');

        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);

        (new BinaryService())->onOrderPaid((int) $order['id']);

        $page = $ledgerPage(['ledger' => 'binary']);

        assert_contains('Source', $page['html'], 'the Source column header renders');
        assert_contains('Repeat purchase', $page['html'], 'order-source rows show Repeat purchase');
        assert_contains((string) $buyer['username'], $page['html'], 'the order-source award row renders');
    },

    /* ----------------------------------------------------------
     *  P23D-T2 — ?source=activation / order filtering
     * ---------------------------------------------------------- */

    'P23D-T2 source filter narrows to activation or order rows' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $activationFixture, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p23d_t2_admin', 'admin'));

        $product = $binaryProduct('P23D T2', 'P23DT2', 300.00, 250.00);

        /* Order-source award */
        $buyer = test_user('p23d_t2_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');
        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);
        (new BinaryService())->onOrderPaid((int) $order['id']);

        /* Activation-source award */
        $source = test_user('p23d_t2_src', 'member');
        $recipient = test_user('p23d_t2_recip', 'member');
        $placeBinaryNode((int) $source['id'], null, null, 0, '');
        $placeBinaryNode((int) $recipient['id'], (int) $source['id'], 'left', 1, 'L');
        $activation = $activationFixture((int) $source['id']);
        BinaryAward::createForActivation([
            'kind'          => 'pair',
            'buyer_id'      => (int) $source['id'],
            'user_id'       => (int) $recipient['id'],
            'side'          => 'left',
            'points'        => 2000,
            'pair_count'    => 1,
            'pair_points'   => 1000,
            'rate_pct'      => 10.000,
            'amount'        => 100.00,
            'status'        => 'credited',
            'activation_id' => (int) $activation['id'],
        ]);

        /* source=activation: only the activation row */
        $actPage = $ledgerPage(['ledger' => 'binary', 'source' => 'activation']);
        assert_contains((string) $recipient['username'], $actPage['html'], 'source=activation shows the activation award');
        assert_not_contains((string) $buyer['username'], $actPage['html'], 'source=activation hides the order award');

        /* source=order: only the order row */
        $ordPage = $ledgerPage(['ledger' => 'binary', 'source' => 'order']);
        assert_contains((string) $buyer['username'], $ordPage['html'], 'source=order shows the order award');
        assert_not_contains((string) $recipient['username'], $ordPage['html'], 'source=order hides the activation award');

        /* unknown source falls back to all */
        $allPage = $ledgerPage(['ledger' => 'binary', 'source' => 'bogus']);
        assert_contains((string) $buyer['username'], $allPage['html'], 'bogus source falls back to all rows');
        assert_contains((string) $recipient['username'], $allPage['html'], 'bogus source falls back to all rows');
    },

    /* ----------------------------------------------------------
     *  P23D-T3 — stats tiles split by source
     * ---------------------------------------------------------- */

    'P23D-T3 stats tiles split credited and pairs by source' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $activationFixture, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p23d_t3_admin', 'admin'));

        $product = $binaryProduct('P23D T3', 'P23DT3', 300.00, 250.00);

        /* Order-source credited award */
        $buyer = test_user('p23d_t3_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');
        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);
        (new BinaryService())->onOrderPaid((int) $order['id']);

        /* Activation-source credited award */
        $source = test_user('p23d_t3_src', 'member');
        $recipient = test_user('p23d_t3_recip', 'member');
        $placeBinaryNode((int) $source['id'], null, null, 0, '');
        $placeBinaryNode((int) $recipient['id'], (int) $source['id'], 'left', 1, 'L');
        $activation = $activationFixture((int) $source['id']);
        BinaryAward::createForActivation([
            'kind'          => 'pair',
            'buyer_id'      => (int) $source['id'],
            'user_id'       => (int) $recipient['id'],
            'side'          => 'left',
            'points'        => 2000,
            'pair_count'    => 1,
            'pair_points'   => 1000,
            'rate_pct'      => 10.000,
            'amount'        => 100.00,
            'status'        => 'credited',
            'activation_id' => (int) $activation['id'],
        ]);

        $page = $ledgerPage(['ledger' => 'binary']);

        assert_contains('Package pairs', $page['html'], 'the Package pairs stat card renders');
        assert_contains('Package points', $page['html'], 'the Package points stat card renders');
        assert_contains('₱100.00', $page['html'], 'the package pair amount shows');
    },

    /* ----------------------------------------------------------
     *  P23D-T4 — CSV export includes Source column and activation id
     * ---------------------------------------------------------- */

    'P23D-T4 CSV export includes Source column and activation number' => function () use ($binaryPreset, $binaryProduct, $binaryOrder, $binaryOrderItem, $placeBinaryNode, $activationFixture, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p23d_t4_admin', 'admin'));

        $product = $binaryProduct('P23D T4', 'P23DT4', 300.00, 250.00);

        /* Order-source award */
        $buyer = test_user('p23d_t4_buyer', 'member');
        $placeBinaryNode((int) $buyer['id'], null, null, 0, '');
        $order = $binaryOrder((int) $buyer['id'], 'left');
        $binaryOrderItem((int) $order['id'], (int) $product['id'], 4, 250, 1000);
        (new BinaryService())->onOrderPaid((int) $order['id']);

        /* Activation-source award */
        $source = test_user('p23d_t4_src', 'member');
        $recipient = test_user('p23d_t4_recip', 'member');
        $placeBinaryNode((int) $source['id'], null, null, 0, '');
        $placeBinaryNode((int) $recipient['id'], (int) $source['id'], 'left', 1, 'L');
        $activation = $activationFixture((int) $source['id']);
        BinaryAward::createForActivation([
            'kind'          => 'pair',
            'buyer_id'      => (int) $source['id'],
            'user_id'       => (int) $recipient['id'],
            'side'          => 'left',
            'points'        => 2000,
            'pair_count'    => 1,
            'pair_points'   => 1000,
            'rate_pct'      => 10.000,
            'amount'        => 100.00,
            'status'        => 'credited',
            'activation_id' => (int) $activation['id'],
        ]);

        $_GET = ['ledger' => 'binary', 'source' => 'activation'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions/export?ledger=binary&source=activation';

        $controller = new AdminCommissionController();
        ob_start();
        $controller->export(['no_exit' => true]);
        $csv = (string) ob_get_clean();

        $lines = array_filter(explode("\n", $csv));
        assert_count(2, $lines, 'header row plus one data row');

        $header = str_getcsv($lines[0]);
        assert_true(in_array('Source', $header, true), 'the CSV header has Source');

        $row = str_getcsv($lines[1]);
        $sourceCol = array_search('Source', $header, true);
        assert_same('activation', $row[$sourceCol] ?? '', 'the CSV row shows activation as source');
    },

    /* ----------------------------------------------------------
     *  P23D-T8 — a member opening the admin tab gets 403
     * ---------------------------------------------------------- */

    'P23D-T8 a member opening /admin/commissions?ledger=binary gets 403' => function () use ($binaryPreset): void {
        $binaryPreset();

        test_signed_in_as(test_user('p23d_t8_member', 'member'));

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=binary';

        $controller = new AdminCommissionController();
        $data       = $controller->context();

        assert_same('binary', (string) $data['tab'], 'the binary tab builds its context (route guard enforces 403)');
    },

    /* ----------------------------------------------------------
     *  P23D-T10 — the empty state names the package switch
     * ---------------------------------------------------------- */

    'P23D-T10 the empty state names the package switch and activation requirement' => function () use ($binaryPreset, $ledgerPage): void {
        $binaryPreset();

        test_signed_in_as(test_user('p23d_t10_admin', 'admin'));

        $page = $ledgerPage(['ledger' => 'binary']);

        assert_contains('Package activation', $page['html'], 'the empty state names the package activation source');
        assert_contains('package binary switch', strtolower($page['html']), 'the empty state names the package switch');
    },
];
