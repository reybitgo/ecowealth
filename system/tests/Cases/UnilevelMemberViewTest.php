<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelMemberViewTest — Phase 17E (plan §9)
 *
 *  Proves the member-facing half of the repeat-purchase stream:
 *
 *      product     the Team chain ladder panel, all ten levels, the
 *                  per-unit amounts, the "up to" total and the monthly
 *                  minimum note
 *      catalogue   the one-query "Up to 29% repeat bonus" badge
 *      checkout    the batched "up to ₱… is paid to the members above
 *                  you" line, the Team chain pivot, paid-when-paid copy,
 *                  absent when nothing pays
 *      order       the order's total only — no per-recipient amounts
 *      earnings    the ?ledger=repeat tab: counters without an
 *                  "as buyer" figure, the two-clock status panel, the
 *                  1–10 team breakdown (rosters and per-member money),
 *                  filters, CSV and session scoping
 *      dashboard   the repeat tile with the current Active status
 *      membership  the paragraph, with no action and no form
 *      phones      the responsive hooks at 390 px
 *
 *  Nothing in this file moves money: the one fixture that needs a paid
 *  order calls the real 17C engine, exactly as checkout does.
 *
 *  Run this group alone with:  php tests/run.php UnilevelMemberView
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

/** The five settings the engine and the preview read. */
$enable = static function (): void {
    setting_set('unilevel_enabled', '1');
    setting_set('unilevel_max_levels', '10');
    setting_set('unilevel_requires_active_upline', '1');
    setting_set('unilevel_active_threshold', '500');
    setting_set('unilevel_reversal_enabled', '1');
};

/** A configured product: price ₱300, P.V. ₱250, base auto. */
$makeProduct = static function (array $ladder = [], string $switch = '1', string $base = 'auto'): int {
    return Product::save([
        'name'             => 'P17E Product ' . bin2hex(random_bytes(3)),
        'sku'              => 'P17E-' . strtoupper(bin2hex(random_bytes(3))),
        'price'            => '300.00',
        'pv'               => '250.00',
        'unilevel_enabled' => $switch,
        'unilevel_base'    => $base,
        'stock'            => '100',
        'status'           => 'active',
    ], null, $ladder === [] ? ProductUnilevel::referencePreset() : $ladder);
};

/**
 * A paid order that pays the reference ladder to a four-deep chain.
 *
 * Buyer → u1 → u2 → u3 → u4. Line: 2 × product → base ₱500. Under the
 * reference ladder the order pays u1 ₱25, u2 ₱25, u3 ₱25 and u4 ₱15.
 *
 * @return array<string, mixed>
 */
$paidOrder = static function (int $productId, ?array $buyer = null): array {
    $buyer  = $buyer ?? test_user('p17e_buyer_' . bin2hex(random_bytes(3)));
    $levels = [];
    $child  = $buyer;

    for ($level = 1; $level <= 4; $level++) {
        $upline = test_user('p17e_u' . $level . '_' . bin2hex(random_bytes(3)));

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $upline['id'], (int) $child['id']]);

        $levels[$level] = $upline;
        $child          = $upline;
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
    )->execute(['P17E Coffee', 'P17E-1', $itemId]);

    (new UnilevelService())->onOrderPaid($orderId);

    return [
        'product_id' => $productId,
        'buyer'      => $buyer,
        'levels'     => $levels,
        'order_id'   => $orderId,
        'item_id'    => $itemId,
        'order'      => Order::find($orderId),
        'awards'     => UnilevelAward::forOrder($orderId),
    ];
};

/** How many prepared statements this session has executed so far. */
$statements = static function (): int {
    $row = db()->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch();

    return (int) ($row['Value'] ?? 0);
};

$measure = static function (callable $work) use ($statements): int {
    $before = $statements();
    $work();

    return $statements() - $before;
};

return [
    /* ==========================================================
     *  P17E-T1 — the product panel
     * ========================================================== */

    'P17E-T1 the product panel lists levels 1–10, the per-unit amounts, the 29.000% total and the monthly minimum' => function () use (
        $enable,
        $makeProduct,
        $render
    ): void {
        $enable();
        $id = $makeProduct();

        $product = Product::find($id);
        assert_not_null($product);

        $controller = new ShopController();
        $data       = $controller->productContext($product);

        assert_not_null($data['bonus'], 'a configured product gets a panel');
        assert_same(250.0, (float) $data['bonus']['base'], 'the base is the P.V. (auto → pv when pv > 0)');
        assert_same('pv', (string) $data['bonus']['base_source']);
        assert_same(29.0, (float) $data['bonus']['total_percent']);
        assert_same(10, (int) $data['bonus']['paying_levels']);
        assert_same(72.5, (float) $data['bonus']['total'], '29% of ₱250.00 per unit');

        $levels = $data['bonus']['levels'];
        assert_same(10, count($levels), 'ten levels, not nine');
        assert_same(1, (int) $levels[0]['level']);
        assert_same(5.0, (float) $levels[0]['rate']);
        assert_same(12.5, (float) $levels[0]['amount'], 'level 1 is 5% of ₱250.00');
        assert_same(10, (int) $levels[9]['level']);
        assert_same(2.5, (float) $levels[9]['amount'], 'level 10 is 1% of ₱250.00');

        /* The live feed fragment is the same panel the page renders. */
        $payload = $controller->productFeedPayload(3, '"shop.product:' . $id . ':3:abc"', $data);
        $card    = $payload['fragments']['shop-product-card'];

        assert_contains('data-bonus-panel', $card);
        assert_contains('Repeat-purchase bonus', $card);
        assert_contains('Bonus base', $card);
        assert_contains(money(250), $card, 'the base is shown per unit');
        assert_contains('Level 1', $card);
        assert_contains('Level 10', $card);
        assert_contains('Your direct recruit', $card);
        assert_contains('Your Team chain', $card);
        assert_contains('5.000%', $card);
        assert_contains(money(12.5), $card);
        assert_contains('Total up to 29.000%', $card);
        assert_contains(money(72.5), $card);
        assert_contains('Your own purchase does not pay you', $card);
        assert_contains('monthly minimum', $card);
        assert_contains(money(500), $card, 'the monthly minimum amount is stated');
        assert_contains('when that order is paid', $card);
        assert_not_contains('sponsor chain', $card);
        assert_not_contains('direct bonus', $card);
        assert_not_contains('indirect ladder', $card);
        assert_not_contains('cashback', $card);

        /* The page renders the same panel through _card.php. */
        $html = $render('member', 'shop/product', $data + [
            'page_title'  => (string) $product['name'],
            'current_nav' => 'shop',
            'page_css'    => ['assets/css/shop.css'],
        ]);

        assert_contains('data-bonus-panel', $html);
        assert_contains('Level 10', $html);
        assert_contains('product-bonus-grid', $html, 'the responsive grid carries the panel on a phone');
    },

    'P17E-T2 an unconfigured product renders no panel at all' => function () use ($enable, $makeProduct, $render): void {
        $enable();

        /* Switch off, reference ladder still stored. */
        $offId = $makeProduct([], '0');
        $off   = Product::find($offId);
        assert_not_null($off);

        $controller = new ShopController();
        $data       = $controller->productContext($off);

        assert_null($data['bonus'], 'the product switch is off');

        $payload = $controller->productFeedPayload(1, '"x"', $data);
        assert_not_contains('data-bonus-panel', $payload['fragments']['shop-product-card']);

        /* Switch on but every rate is 0: a promise that cannot pay. */
        $zeroId = Product::save([
            'name'             => 'P17E Zero ' . bin2hex(random_bytes(3)),
            'sku'              => 'P17E-Z-' . strtoupper(bin2hex(random_bytes(3))),
            'price'            => '300.00',
            'pv'               => '0',
            'unilevel_enabled' => '1',
            'unilevel_base'    => 'price',
            'stock'            => '10',
            'status'           => 'active',
        ], null, array_fill(1, 10, 0));

        $zero = Product::find($zeroId);
        assert_not_null($zero);
        assert_null($controller->productContext($zero)['bonus']);

        /* A rate-0 level inside a paying ladder reads "—", not 0.000%. */
        $partialId = $makeProduct([1 => '5', 2 => '0', 3 => '3']);
        $partial   = Product::find($partialId);
        assert_not_null($partial);

        $partialData = $controller->productContext($partial);
        assert_not_null($partialData['bonus']);
        $card = $controller->productFeedPayload(2, '"y"', $partialData)['fragments']['shop-product-card'];

        assert_contains('&mdash;', $card, 'a level with no rate reads a dash');
        assert_not_contains('0.000%', $card);
        assert_contains('Total up to 8.000%', $card);
        assert_same(2, (int) $partialData['bonus']['paying_levels']);

        $html = $render('member', 'shop/product', $data + [
            'page_title'  => 'Off',
            'current_nav' => 'shop',
        ]);
        assert_not_contains('data-bonus-panel', $html);
        assert_not_contains('Repeat-purchase bonus', $html);
    },

    /* ==========================================================
     *  P17E-T3 — the catalogue badge
     * ========================================================== */

    'P17E-T3 the catalogue badge shows Up to 29% for a configured product in one batched query' => function () use (
        $enable,
        $makeProduct,
        $measure,
        $render
    ): void {
        $enable();

        $first  = $makeProduct();
        $second = $makeProduct();

        $controller = new ShopController();
        $context    = $controller->catalogContext();

        assert_true(isset($context['bonus_totals'][$first]), 'the configured product carries a total');
        assert_same(29.0, (float) $context['bonus_totals'][$first]);

        $html = $render('member', 'shop/catalog', $context + [
            'page_title'  => 'Shop',
            'current_nav' => 'shop',
            'page_css'    => ['assets/css/shop.css'],
        ]);

        assert_contains('Up to 29% repeat bonus', $html);
        assert_contains('product-card-bonus', $html);

        /* One batched ladder query: the page's statement cost does not
         * grow when more configured products join the grid. */
        $cost = $measure(static fn () => $controller->catalogContext());

        for ($i = 0; $i < 4; $i++) {
            $makeProduct();
        }

        $more = $measure(static fn () => $controller->catalogContext());

        assert_true($more <= $cost, 'the badge costs no extra query per product (first=' . $cost . ', more=' . $more . ')');

        /* The global master off removes every badge. */
        setting_set('unilevel_enabled', '0');

        $off = $render('member', 'shop/catalog', $controller->catalogContext() + [
            'page_title'  => 'Shop',
            'current_nav' => 'shop',
        ]);

        assert_not_contains('Up to 29% repeat bonus', $off);
        assert_not_contains('product-card-bonus', $off);
    },

    /* ==========================================================
     *  P17E-T4 / T5 / T6 — the checkout preview
     * ========================================================== */

    'P17E-T4 the checkout summary says up to ₱145.00 is paid to the members above you and pivots to the Team chain' => function () use (
        $enable,
        $makeProduct
    ): void {
        $enable();
        $productId = $makeProduct();

        $buyer = test_user('p17e_checkout_' . bin2hex(random_bytes(3)));
        (new CartService())->add((int) $buyer['id'], $productId, 2);

        test_signed_in_as($buyer);
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/checkout';

        $controller = new CheckoutController();
        $data       = $controller->checkoutContext($buyer);

        assert_not_null($data['bonus_preview'], 'the cart carries a ladder');
        assert_same(145.0, (float) $data['bonus_preview']['total'], '2 units × ₱72.50 = ₱145.00');

        $payload = $controller->feedPayload(4, '"checkout:4:abc"', $data);
        $summary = $payload['fragments']['checkout-summary'];

        assert_contains('data-bonus-preview', $summary);
        assert_contains('Up to ' . money(145), $summary);
        assert_contains('is paid to the members above you', $summary);
        assert_contains('when this order is paid', $summary);
        assert_contains('<strong>Team chain</strong>', $summary);
        assert_contains('monthly minimum', $summary);
        assert_not_contains('sponsor chain', $summary);
        assert_not_contains('rebate', $summary);
        assert_not_contains('cashback', $summary);
        assert_not_contains('you will earn', $summary);

        /* Rendered on the page itself, not only in the feed. */
        $html = (static function () use ($controller, $data): string {
            ob_start();

            try {
                View::page('member', 'shop/checkout', $data + [
                    'page_title'      => 'Checkout',
                    'current_nav'     => 'cart',
                    'page_css'        => ['assets/css/shop.css'],
                    'idempotency_key' => 'abc',
                ]);
            } finally {
                return (string) ob_get_clean();
            }
        })();

        assert_contains('data-bonus-preview', $html);
        assert_contains('Up to ' . money(145), $html);
    },

    'P17E-T5 a cart with no configured product renders no preview line' => function () use ($enable): void {
        $enable();

        /* A normal product with no ladder rows. */
        $productId = Product::save([
            'name'             => 'P17E Plain ' . bin2hex(random_bytes(3)),
            'sku'              => 'P17E-P-' . strtoupper(bin2hex(random_bytes(3))),
            'price'            => '150.00',
            'pv'               => '0',
            'unilevel_enabled' => '0',
            'unilevel_base'    => 'auto',
            'stock'            => '50',
            'status'           => 'active',
        ]);

        $buyer = test_user('p17e_checkout_plain_' . bin2hex(random_bytes(3)));
        (new CartService())->add((int) $buyer['id'], $productId, 1);

        test_signed_in_as($buyer);
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/checkout';

        $controller = new CheckoutController();
        $data       = $controller->checkoutContext($buyer);

        assert_null($data['bonus_preview']);

        $payload = $controller->feedPayload(5, '"checkout:5:abc"', $data);

        assert_not_contains('data-bonus-preview', $payload['fragments']['checkout-summary']);
        assert_not_contains('is paid to the members above you', $payload['fragments']['checkout-summary']);
    },

    'P17E-T6 the checkout feed carries bonus_preview in the refreshed summary and matches the first render' => function () use (
        $enable,
        $makeProduct
    ): void {
        $enable();
        $productId = $makeProduct();

        $buyer = test_user('p17e_checkout_feed_' . bin2hex(random_bytes(3)));
        (new CartService())->add((int) $buyer['id'], $productId, 2);

        test_signed_in_as($buyer);
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/checkout';

        $controller = new CheckoutController();
        $data       = $controller->checkoutContext($buyer);

        $first  = $controller->feedPayload(7, '"checkout:7:abc"', $data);
        $second = $controller->feedPayload(8, '"checkout:8:def"', $controller->checkoutContext($buyer));

        assert_same(
            $first['fragments']['checkout-summary'],
            $second['fragments']['checkout-summary'],
            'the refreshed summary is byte-identical to the first render'
        );
        assert_contains('data-bonus-preview', $second['fragments']['checkout-summary']);

        /* The preview travels with the fragment, not the whole page. */
        assert_not_contains('data-bonus-preview', (string) ($first['meta']['canonical'] ?? ''));
    },

    'P17E-T16 the checkout follows the shared config revision so the master switch reaches an open tab' => function () use (
        $enable,
        $makeProduct
    ): void {
        $enable();
        $productId = $makeProduct();

        $buyer = test_user('p17e_checkout_config_' . bin2hex(random_bytes(3)));
        (new CartService())->add((int) $buyer['id'], $productId, 2);

        test_signed_in_as($buyer);
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/checkout';

        $controller = new CheckoutController();
        $surface    = Live::checkoutSurface((int) $buyer['id']);
        $scope      = $controller->checkoutScope((int) $buyer['id']);

        assert_contains(
            '|config:' . Live::revisionOrZero(Live::CONFIG),
            $scope,
            'the scope follows the shared configuration revision'
        );

        $onEtag = Live::etag($surface, 3, $scope);
        $on     = $controller->feedPayload(3, $onEtag, $controller->checkoutContext($buyer));

        assert_contains('data-bonus-preview', $on['fragments']['checkout-summary']);
        assert_true(Live::matchesIfNoneMatch($onEtag, $onEtag), 'the first render answers 304 when unchanged');

        /* The administrator saves the master switch off on /admin/settings:
         * the save bumps the shared revision, so the checkout tab's next
         * poll answers 200 and the preview line disappears. */
        setting_set('unilevel_enabled', '0');
        Live::touchSettings();

        $offEtag = Live::etag($surface, 3, $controller->checkoutScope((int) $buyer['id']));
        $off     = $controller->feedPayload(3, $offEtag, $controller->checkoutContext($buyer));

        assert_not_same($onEtag, $offEtag, 'a settings save moves the checkout ETag');
        assert_false(Live::matchesIfNoneMatch($onEtag, $offEtag), 'the old ETag is a 200, not a 304');
        assert_true(Live::matchesIfNoneMatch($offEtag, $offEtag), 'the current ETag answers 304');
        assert_not_contains('data-bonus-preview', $off['fragments']['checkout-summary']);

        /* Saving the switch back on restores it the same way. */
        setting_set('unilevel_enabled', '1');
        Live::touchSettings();

        $backEtag = Live::etag($surface, 3, $controller->checkoutScope((int) $buyer['id']));
        $back     = $controller->feedPayload(3, $backEtag, $controller->checkoutContext($buyer));

        assert_not_same($offEtag, $backEtag);
        assert_contains('data-bonus-preview', $back['fragments']['checkout-summary']);
    },

    /* ==========================================================
     *  P17E-T7 — the order page
     * ========================================================== */

    'P17E-T7 the order page shows the total paid to the members above and no per-recipient amounts' => function () use (
        $enable,
        $makeProduct,
        $paidOrder
    ): void {
        $enable();
        $fixture = $paidOrder($makeProduct());
        $order   = $fixture['order'];
        assert_not_null($order);
        assert_same('90.00', number_format((float) $order['unilevel_total'], 2, '.', ''));

        $html = View::capture('shop/order/_bonus', ['order' => $order]);

        assert_contains('data-live-region="member-order-bonus"', $html);
        assert_contains('Repeat-purchase bonus', $html);
        assert_contains(money(90), $html, 'the order total');
        assert_contains('was paid to the members above you', $html);
        assert_contains('Your <strong>Team chain</strong>', $html);
        assert_contains('monthly minimum', $html);
        assert_contains('earnings?ledger=repeat', $html, 'the card links to the Team chain statement');
        assert_not_contains('sponsor chain', $html);

        /* Which member above got which amount is admin-only. */
        foreach ($fixture['levels'] as $level => $upline) {
            assert_not_contains((string) $upline['username'], $html, 'level ' . $level . ' is not named');
        }

        assert_not_contains(money(25), $html);
        assert_not_contains(money(15), $html);
        assert_not_contains('Upline', $html);

        /* An order with no awards shows nothing. */
        $plainOrderId = test_order((int) $fixture['buyer']['id'], [], 'paid', [
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        $plain = Order::find($plainOrderId);
        assert_not_null($plain);

        $empty = View::capture('shop/order/_bonus', ['order' => $plain]);

        assert_contains('data-live-region="member-order-bonus"', $empty, 'the region exists so a live update can fill it');
        assert_not_contains('Repeat-purchase bonus', $empty);
        assert_not_contains(money(0), $empty);

        /* The order feed carries the same region. */
        $controller = new OrderController();
        $payload    = $controller->detailFeedPayload(2, '"order:2:abc"', $controller->orderContext($order));

        assert_true(isset($payload['fragments']['member-order-bonus']));
        assert_contains(money(90), $payload['fragments']['member-order-bonus']);
    },

    /* ==========================================================
     *  P17E-T8 / T9 / T10 / T11 — the earnings tab
     * ========================================================== */

    'P17E-T8 the repeat tab shows the counters, the two-clock panel, the 1–10 breakdown and the rows without an as-buyer stat' => function () use (
        $enable,
        $makeProduct,
        $paidOrder,
        $render
    ): void {
        $enable();
        $fixture = $paidOrder($makeProduct());

        $member = $fixture['levels'][1];
        $userId = (int) $member['id'];

        test_signed_in_as($member);
        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat';

        $controller = new EarningsController();
        $data       = $controller->context($userId);

        assert_same('repeat', $data['tab']);
        assert_same(25.0, (float) $data['totals']['credited']);
        assert_same(1, (int) $data['totals']['count']);
        assert_same(0, (int) $data['totals']['skipped']);
        assert_true(!array_key_exists('direct', $data['totals']), 'no package direct figure on this tab');
        assert_true(!array_key_exists('indirect', $data['totals']));

        $status = $data['status'];
        foreach (['period', 'previous', 'previous_purchases', 'current_purchases', 'threshold', 'active', 'next_active'] as $key) {
            assert_true(array_key_exists($key, $status), 'memberStatus carries ' . $key);
        }

        assert_same(500.0, (float) $status['threshold']);

        $payload = $controller->feedPayload(9, '"member.earnings:' . $userId . ':9:abc"', $data);

        assert_same(
            [
                'earnings-repeat-header',
                'earnings-repeat-stats',
                'earnings-repeat-status',
                'earnings-repeat-levels',
                'earnings-repeat-rows',
                'earnings-repeat-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $stats = $payload['fragments']['earnings-repeat-stats'];
        assert_contains('Total credited', $stats);
        assert_contains(money(25), $stats);
        assert_contains('Not paid', $stats);
        assert_not_contains('As buyer', $stats);

        $statusPanel = $payload['fragments']['earnings-repeat-status'];
        assert_contains('Your Active status', $statusPanel);
        assert_contains('purchases', $statusPanel);
        assert_contains('Threshold ' . money(500), $statusPanel);
        assert_contains('Counts toward', $statusPanel);
        assert_contains('they never pay you a repeat-purchase bonus', $statusPanel);
        assert_contains('Team chain', $statusPanel);
        assert_not_contains('sponsor chain', $statusPanel);
        assert_not_contains('rebate', $statusPanel);

        $levels = $payload['fragments']['earnings-repeat-levels'];
        assert_contains('Repeat-purchase by level', $levels);
        assert_contains('Level 1', $levels);
        assert_contains('Level 10', $levels);
        assert_contains(money(25), $levels);
        assert_not_contains('Direct', $levels);
        assert_not_contains('Indirect', $levels);

        $rows = $payload['fragments']['earnings-repeat-rows'];
        assert_contains('data-live-region="earnings-repeat-rows"', $rows);
        assert_contains('@' . (string) $fixture['buyer']['username'], $rows);
        assert_contains('L1', $rows);
        assert_contains(money(25), $rows);
        assert_contains('Credited', $rows);

        $html = $render('member', 'member/earnings', $data + [
            'tab'         => 'repeat',
            'page_title'  => 'Earnings',
            'current_nav' => 'earnings',
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
        ]);

        assert_contains('Repeat-purchase bonus', $html);
        assert_contains('Package commissions', $html);
        assert_contains('aria-current="page"', $html);
        assert_contains('name="ledger" value="repeat"', $html);
        assert_contains('data-live-region="earnings-repeat-status"', $html);
        assert_contains('data-live-region="earnings-repeat-rows"', $html);
        assert_contains('data-live-config="' . Live::GENEALOGY_TEAM . '"', $html, 'a team change wakes the repeat tab');
        assert_contains('name="level"', $html);
        assert_contains('name="product_id"', $html);
        assert_not_contains('As buyer', $html);
        assert_not_contains('as buyer', $html);
    },

    'P17E-T9 the repeat filters narrow the rows and the CSV carries the documented columns and own rows only' => function () use (
        $enable,
        $makeProduct,
        $paidOrder
    ): void {
        $enable();
        $fixture = $paidOrder($makeProduct());

        $member = $fixture['levels'][1];
        $userId = (int) $member['id'];

        test_signed_in_as($member);
        $_GET = ['ledger' => 'repeat', 'level' => '1', 'status' => 'credited'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat&level=1&status=credited';

        $controller = new EarningsController();
        $data       = $controller->context($userId);

        assert_same('1', $data['filters']['level']);
        assert_same('credited', $data['filters']['status']);
        assert_same(1, (int) $data['pager']->total);
        assert_contains('ledger=repeat', $data['export_url']);
        assert_contains('level=1', $data['export_url']);
        assert_contains('status=credited', $data['export_url']);

        /* The CSV the controller writes: the documented 13 columns and
         * only this member's rows. */
        $rows = UnilevelAward::exportRows($userId, $data['filters']);
        assert_same(1, count($rows));

        $header = UnilevelAward::csvHeader();
        assert_same(
            ['Award', 'Date', 'Order', 'Buyer', 'Recipient', 'Product', 'Level', 'Base', 'Rate %', 'Amount', 'Status', 'Reason', 'Recovered'],
            $header
        );

        $csv = UnilevelAward::csvRows($rows);
        assert_same(13, count($csv[0]));
        assert_same((string) $userId, (string) $rows[0]['user_id']);
        assert_same('25.00', $csv[0][9]);
        assert_same('1', $csv[0][6]);

        /* A bad filter value falls back to "no filter" and never errors. */
        $_GET = ['ledger' => 'repeat', 'level' => '99', 'from' => 'yesterday'];
        $fallback = (new EarningsController())->context($userId);

        assert_same('', $fallback['filters']['level']);
        assert_same('', $fallback['filters']['from']);
        assert_same(1, (int) $fallback['pager']->total);
    },

    'P17E-T10 the package tab is unchanged and renders no repeat region' => function () use (
        $enable,
        $makeProduct,
        $paidOrder,
        $render
    ): void {
        $enable();
        $fixture = $paidOrder($makeProduct());
        $member  = $fixture['levels'][1];
        $userId  = (int) $member['id'];

        test_signed_in_as($member);
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings';

        $controller = new EarningsController();
        $data       = $controller->context($userId);

        assert_same('package', $data['tab']);

        $payload = $controller->feedPayload(4, '"member.earnings:' . $userId . ':4:abc"', $data);

        assert_same(
            [
                'earnings-header',
                'earnings-stats',
                'earnings-levels',
                'earnings-skipped',
                'earnings-rows',
                'earnings-pagination',
            ],
            array_keys($payload['fragments']),
            'the Phase 10 fragment set is untouched'
        );
        assert_not_contains('earnings-repeat', implode('', $payload['fragments']));

        $html = $render('member', 'member/earnings', $data + [
            'page_title'  => 'Earnings',
            'current_nav' => 'earnings',
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
        ]);

        assert_contains('data-live-region="earnings-stats"', $html);
        assert_contains('data-live-region="earnings-levels"', $html);
        assert_contains('data-live-region="earnings-rows"', $html);
        assert_contains('Unilevel breakdown', $html);
        assert_not_contains('data-live-region="earnings-repeat-', $html);
        assert_not_contains('data-live-config', $html, 'the package tab does not depend on the team revision');
        assert_not_contains('Repeat-purchase by level', $html);
        assert_contains('Package commissions', $html);
        assert_contains('Repeat-purchase bonus', $html, 'the pill is the only new copy on this tab');
    },

    'P17E-T11 the repeat tab ignores a user_id parameter and stays scoped to the session member' => function () use (
        $enable,
        $makeProduct,
        $paidOrder
    ): void {
        $enable();

        $productId = $makeProduct();
        $first     = $paidOrder($productId);
        $second    = $paidOrder($productId);

        $member = $first['levels'][1];
        $userId = (int) $member['id'];

        test_signed_in_as($member);
        $_GET = ['ledger' => 'repeat', 'user_id' => (string) (int) $second['levels'][1]['id']];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat&user_id=' . (int) $second['levels'][1]['id'];

        $controller = new EarningsController();
        $data       = $controller->context($userId);

        assert_same($userId, (int) $data['user_id']);
        assert_same(1, count($data['awards']), 'only the session member\'s award');

        foreach ($data['awards'] as $award) {
            assert_same($userId, (int) $award['user_id']);
            assert_not_same((int) $second['order_id'], (int) $award['order_id']);
        }

        $payload = $controller->feedPayload(5, '"member.earnings:' . $userId . ':5:abc"', $data);
        $rows    = $payload['fragments']['earnings-repeat-rows'];

        assert_contains('@' . (string) $first['buyer']['username'], $rows);
        assert_not_contains('@' . (string) $second['buyer']['username'], $rows);
        assert_not_contains('@' . (string) $second['levels'][1]['username'], $rows);
    },

    /* ==========================================================
     *  P17E-T12 — the dashboard tile and the membership paragraph
     * ========================================================== */

    'P17E-T12 the dashboard tile and the membership paragraph read the current Active status without an action' => function () use (
        $enable,
        $makeProduct,
        $paidOrder,
        $render
    ): void {
        $enable();
        $fixture = $paidOrder($makeProduct());
        $member  = $fixture['levels'][1];
        $userId  = (int) $member['id'];

        test_signed_in_as($member);
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/dashboard';

        $dashboard = new DashboardController();
        $data      = $dashboard->dashboardContext($userId);

        assert_true(is_array($data['unilevel']));
        assert_same(25.0, (float) $data['unilevel']['totals']['credited']);
        assert_true((bool) $data['unilevel']['enabled']);

        $payload = $dashboard->feedPayload(6, '"member.dashboard:' . $userId . ':6:abc"', $data);
        $tile    = $payload['fragments']['dashboard-earnings'];

        assert_contains('Repeat-purchase bonus', $tile);
        assert_contains(money(25), $tile);
        assert_contains('Active', $tile);
        assert_contains('for ' . date('F Y', (int) strtotime(UnilevelCycle::periodOf() . '-01')), $tile);
        assert_contains('earnings?ledger=repeat', $tile);
        assert_not_contains('<form', $tile, 'the tile offers no action');
        assert_not_contains('<input', $tile);

        $dashboardHtml = $render('member', 'member/dashboard', $data + [
            'page_title'  => 'Dashboard',
            'current_nav' => 'dashboard',
            'page_css'    => ['assets/css/membership.css'],
        ]);

        assert_contains('data-live-region="dashboard-earnings"', $dashboardHtml);
        assert_contains('Repeat-purchase bonus', $dashboardHtml);
        assert_contains('Active for ', $dashboardHtml);

        /* Membership: the paragraph, a status pill and no form. */
        $membership = new MembershipController();
        $memberRow  = Member::find($userId);
        assert_not_null($memberRow);

        $context = $membership->membershipContext($memberRow);
        assert_true(is_array($context['unilevel']));
        assert_same($userId, (int) $context['member']['id']);

        $membershipPayload = $membership->feedPayload(7, '"member.membership:' . $userId . ':7:abc"', $context);

        assert_true(isset($membershipPayload['fragments']['membership-repeat']));
        $paragraph = $membershipPayload['fragments']['membership-repeat'];

        assert_contains('data-live-region="membership-repeat"', $paragraph);
        assert_contains('Repeat-purchase bonus', $paragraph);
        assert_contains('Active for ', $paragraph);
        assert_contains('Team chain', $paragraph);
        assert_contains('keep you Active', $paragraph);
        assert_not_contains('sponsor chain', $paragraph);
        assert_not_contains('<form', $paragraph);
        assert_not_contains('<input', $paragraph);
        assert_not_contains('<button', $paragraph);

        $membershipHtml = $render('member', 'member/membership', $context + [
            'page_title'  => 'Membership',
            'current_nav' => 'membership',
            'page_css'    => ['assets/css/membership.css'],
        ]);

        assert_contains('data-live-region="membership-repeat"', $membershipHtml);
        assert_contains('Repeat-purchase bonus', $membershipHtml);
    },

    /* ==========================================================
     *  P17E-T13 — the responsive hooks
     * ========================================================== */

    'P17E-T13 the new panels carry the responsive hooks used at 390 px' => function () use (
        $enable,
        $makeProduct,
        $paidOrder,
        $render
    ): void {
        $enable();
        $fixture = $paidOrder($makeProduct());
        $product = Product::find($fixture['product_id']);
        assert_not_null($product);

        $productData = (new ShopController())->productContext($product);
        $productHtml = $render('member', 'shop/product', $productData + [
            'page_title'  => 'Product',
            'current_nav' => 'shop',
        ]);

        assert_contains('product-bonus-grid', $productHtml, 'the panel wraps into columns');
        assert_contains('product-bonus-item', $productHtml);

        $shopCss = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/shop.css');
        assert_contains('.product-bonus-grid', $shopCss);
        assert_contains('grid-template-columns: repeat(2, minmax(0, 1fr))', $shopCss, 'two columns on a phone');
        assert_contains('@media (max-width: 575.98px)', $shopCss);

        /* The earnings table lives in the shared responsive wrapper and
         * the ladder columns step aside below 768 px. */
        $member = $fixture['levels'][1];
        test_signed_in_as($member);
        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat';

        $controller = new EarningsController();
        $html       = $render('member', 'member/earnings', $controller->context((int) $member['id']) + [
            'tab'         => 'repeat',
            'page_title'  => 'Earnings',
            'current_nav' => 'earnings',
            'page_css'    => ['assets/css/commissions.css'],
        ]);

        assert_contains('table-responsive', $html);
        assert_contains('commission-meta-mobile', $html, 'the buyer cell carries the facts on a phone');
        assert_contains('commission-col-base', $html);
        assert_contains('commission-col-rate', $html);
        assert_contains('commission-col-order', $html);

        $commissionsCss = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/commissions.css');
        assert_contains('.commission-col-base', $commissionsCss);
        assert_contains('@media (max-width: 767.98px)', $commissionsCss);
    },

    /* ==========================================================
     *  P17E-T14 / T15 — the breakdown lists the Team chain
     *  (17E follow-up)
     * ========================================================== */

    'P17E-T14 the repeat card lists the team at each level with the money each member paid' => function () use (
        $enable,
        $makeProduct,
        $paidOrder
    ): void {
        $enable();
        $fixture = $paidOrder($makeProduct());

        $member = $fixture['levels'][1];
        $userId = (int) $member['id'];
        $buyer  = $fixture['buyer'];

        test_signed_in_as($member);
        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat';

        $controller = new EarningsController();
        $data       = $controller->context($userId);
        $levels     = $data['levels'];

        /* The model read behind the rows groups the ledger by the buyer
         * and the level in one query. */
        $credited = UnilevelAward::creditedByBuyer($userId);
        assert_same(25.0, (float) $credited[1][(int) $buyer['id']]['amount']);
        assert_same(1, (int) $credited[1][(int) $buyer['id']]['count']);

        /* Level 1 is the direct recruit roster, with the ₱25 that
         * member's purchase credited at this depth. */
        assert_same(25.0, (float) $levels[1]['amount']);
        assert_same(1, (int) $levels[1]['count']);
        assert_same(1, (int) $levels[1]['team_count']);
        assert_same(1, $levels[1]['pager']->total);
        assert_count(1, $levels[1]['members']);
        assert_same((int) $buyer['id'], (int) $levels[1]['members'][0]['id']);
        assert_same((string) $buyer['username'], (string) $levels[1]['members'][0]['username']);
        assert_same(25.0, (float) $levels[1]['members'][0]['amount']);
        assert_same(1, (int) $levels[1]['members'][0]['count']);

        /* The ladder still renders every level, empty ones included. A
         * deeper level with no recruit is a roster of zero, never a
         * missing card. */
        assert_same(0, (int) $levels[2]['team_count']);
        assert_count(0, $levels[2]['members']);
        assert_same(0, (int) $levels[10]['team_count']);
        assert_count(0, $levels[10]['members']);

        $payload = $controller->feedPayload(11, '"member.earnings:' . $userId . ':11:abc"', $data);
        $html    = $payload['fragments']['earnings-repeat-levels'];

        assert_contains('data-live-region="earnings-repeat-levels"', $html);
        assert_contains('@' . (string) $buyer['username'], $html, 'the member row names the team member');
        assert_contains('Level total', $html);
        assert_contains(money(25), $html);
        assert_contains('Team chain', $html);
        assert_contains('members at this level yet.', $html);
        assert_contains('Level 10', $html);

        /* One member is one page, and the page still offers its pager:
         * the genealogy "Team by level" card shows the rows control and
         * the summary even when a level fits on a single page, and this
         * card now does the same. A level with no member stays quiet. */
        assert_contains('data-pagination-anchor', $html, 'each level card anchors its own pager');
        assert_contains('Rows per page', $html);
        assert_contains('name="level1_per_page"', $html, 'the rows control is namespaced to level 1');
        assert_contains('Showing 1–1 of 1', $html);
        assert_not_contains('aria-label="Level 1 pagination"', $html, 'one page needs no page links');
        assert_not_contains('name="level2_per_page"', $html, 'an empty level has no pager');

        /* The card explains the Team chain, never the old sponsor chain. */
        assert_not_contains("buyer's sponsor", $html);
        assert_not_contains("sponsor's sponsor", $html);
        assert_not_contains('sponsor chain', $html);
        assert_not_contains('up the chain', $html);
        assert_not_contains('Direct', $html);
        assert_not_contains('Indirect', $html);

        /* The same ladder read from the level-2 member: their direct
         * recruit is u1 at ₱0.00, and the buyer sits at level 2 with the
         * ₱25 award. The amount follows the level in the ledger, never
         * the buyer's username alone. */
        $upline = $fixture['levels'][2];
        test_signed_in_as($upline);
        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat';

        $second = (new EarningsController())->context((int) $upline['id']);
        $secondLevels = $second['levels'];

        assert_same(1, (int) $secondLevels[1]['team_count']);
        assert_same((int) $fixture['levels'][1]['id'], (int) $secondLevels[1]['members'][0]['id']);
        assert_same(0.0, (float) $secondLevels[1]['members'][0]['amount']);

        assert_same(1, (int) $secondLevels[2]['team_count']);
        assert_same((int) $buyer['id'], (int) $secondLevels[2]['members'][0]['id']);
        assert_same(25.0, (float) $secondLevels[2]['members'][0]['amount']);
        assert_same(1, (int) $secondLevels[2]['members'][0]['count']);

        /* The roster is user-controlled text: it must escape. */
        db()->prepare('UPDATE users SET username = ? WHERE id = ?')
            ->execute(['<b>Hostile Team</b>', (int) $buyer['id']]);

        $_GET = ['ledger' => 'repeat'];

        $hostileData = $controller->repeatContext($userId);
        $hostile     = $controller->feedPayload(
            13,
            '"member.earnings:' . $userId . ':13:abc"',
            $hostileData
        );
        $hostileLevels = $hostile['fragments']['earnings-repeat-levels'];

        assert_contains('&lt;b&gt;Hostile Team&lt;/b&gt;', $hostileLevels);
        assert_not_contains('<b>Hostile Team</b>', $hostileLevels);
    },

    'P17E-T15 the team list shows ten members per level and pages each level separately' => function () use (
        $enable,
        $makeProduct,
        $paidOrder
    ): void {
        $enable();
        $fixture = $paidOrder($makeProduct());

        $member = $fixture['levels'][1];
        $userId = (int) $member['id'];

        /* Twelve direct recruits: the paid buyer plus eleven accounts
         * with no orders. The roster counts all twelve; the card shows
         * the first ten and offers a second page. */
        for ($i = 0; $i < 11; $i++) {
            $extra = test_user('p17e_team_' . $i . '_' . bin2hex(random_bytes(3)));

            db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
                ->execute([$userId, (int) $extra['id']]);
        }

        test_signed_in_as($member);
        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat';

        $controller = new EarningsController();
        $first      = $controller->context($userId);

        assert_same(12, (int) $first['levels'][1]['team_count']);
        assert_same(12, $first['levels'][1]['pager']->total);
        assert_same(2, $first['levels'][1]['pager']->pages);
        assert_same(1, $first['levels'][1]['pager']->page);
        assert_count(10, $first['levels'][1]['members']);

        /* The other levels did not move: level 2 still has no recruit
         * and its pager stays on page 1. */
        assert_same(0, (int) $first['levels'][2]['team_count']);
        assert_same(1, $first['levels'][2]['pager']->page);

        $firstHtml = $controller
            ->feedPayload(11, '"member.earnings:' . $userId . ':11:abc"', $first)['fragments']['earnings-repeat-levels'];

        assert_contains('Showing 1–10 of 12', $firstHtml, 'the first page shows its pager');
        assert_contains('level1_page=2', $firstHtml, 'the pager links to the second page');
        assert_not_contains('level2_page', $firstHtml, 'the empty level 2 has no page links');

        $_GET = ['ledger' => 'repeat', 'level1_page' => '2'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=repeat&level1_page=2';

        $second = (new EarningsController())->context($userId);

        assert_same(2, $second['levels'][1]['pager']->page);
        assert_count(2, $second['levels'][1]['members']);
        assert_same(1, $second['levels'][2]['pager']->page);

        $payload = $controller->feedPayload(12, '"member.earnings:' . $userId . ':12:abc"', $second);
        $html    = $payload['fragments']['earnings-repeat-levels'];

        assert_contains('data-pagination=', $html, 'the second page offers the shared pager');
        assert_contains('Showing 11–12 of 12', $html);
    },

    /* ==========================================================
     *  The two-clock helper (plan §12.7)
     * ========================================================== */

    'P17E-T8 memberStatus resolves both clocks from live orders and a closed snapshot' => function () use ($enable, $makeProduct): void {
        $enable();

        $member = test_user('p17e_clock_' . bin2hex(random_bytes(3)));
        $userId = (int) $member['id'];

        /* Nothing bought: no flag means Active. */
        $status = UnilevelCycle::memberStatus($userId);

        assert_true($status['active'], 'a member with no order history is Active');
        assert_same(0.0, $status['previous_purchases']);
        assert_same('live', $status['previous_source']);
        assert_same(UnilevelCycle::periodOf(), $status['period']);
        assert_same(UnilevelCycle::previous($status['period']), $status['previous']);

        /* A paid order last month: the live aggregate decides. */
        $productId = $makeProduct();
        $previous  = UnilevelCycle::previous(UnilevelCycle::periodOf());
        [$start]   = UnilevelCycle::bounds($previous);

        $orderId = test_order($userId, [['product_id' => $productId, 'quantity' => 1]], 'paid', [
            'paid_at'  => $start,
            'subtotal' => '600.00',
            'total'    => '600.00',
        ]);

        db()->prepare('UPDATE shop_order_items SET unit_price = 600.00, line_total = 600.00 WHERE order_id = ?')
            ->execute([$orderId]);

        $status = UnilevelCycle::memberStatus($userId);

        assert_same(600.0, $status['previous_purchases']);
        assert_true($status['active'], '₱600 last month clears the ₱500 threshold');
        assert_same(0.0, $status['current_purchases']);
        assert_false($status['next_active']);
        assert_same(500.0, $status['remaining']);
        assert_same(0, $status['progress']);

        /* Closing the month makes the snapshot authoritative. */
        UnilevelCycle::close($previous);

        $closed = UnilevelCycle::memberStatus($userId);

        assert_same('snapshot', $closed['previous_source']);
        assert_true($closed['active']);
        assert_same(600.0, $closed['previous_purchases']);

        /* The snapshot can say not-qualified while the live sum would not. */
        db()->prepare('UPDATE unilevel_activity SET qualified = 0 WHERE period = ? AND user_id = ?')
            ->execute([$previous, $userId]);

        $blocked = UnilevelCycle::memberStatus($userId);

        assert_false($blocked['active'], 'the closed snapshot wins over the live aggregate');
        assert_same('snapshot', $blocked['previous_source']);
    },
];
