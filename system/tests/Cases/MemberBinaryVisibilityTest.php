<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberBinaryVisibilityTest — Phase 22F-S3 (plan §7 Phase 22F)
 *
 *  Proves the member-facing checkout, order, product and dashboard
 *  surfaces: the position choice and quote card at checkout, the buyer's
 *  own outcome on the order page, the product detail binary panel, and
 *  the dashboard tile. All copy is downward-only (plan §14.12, D-22).
 *
 *  Fixture tree (company root = existing `admin`, placed under its free
 *  RIGHT slot because the demo seed occupies LEFT):
 *
 *      admin   state=root        depth 0, path ''
 *      └─ R  boss                depth 1, path R
 *         ├─ L  buyerA           depth 2, path RL
 *         └─ R  buyerB           depth 2, path RR
 *
 *  buyerA buys 4 × P1 (P.V. 250, binary on) left → self ₱50.00 pending,
 *  volume L 1000 at buyerA, boss and admin. buyerB buys 4 × P1 right →
 *  self ₱50.00 pending, volume R 1000 at buyerB, boss and admin; boss
 *  then has L 1000 R 1000 → one pair ₱100.00.
 *
 *  Every case runs inside the harness transaction and rolls back.
 *
 *  Run this group alone with:  php tests/run.php MemberBinaryVisibility
 * ============================================================== */

$token = bin2hex(random_bytes(4));

$createUser = static function (string $username, string $role = 'member') use ($token): array {
    $name = $username . '_' . $token;

    $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    if ($row !== false) {
        return $row;
    }

    db()->prepare(
        'INSERT INTO users
           (username, email, password_hash, full_name, role, member_status, referral_code, is_active)
         VALUES (?, ?, ?, ?, ?, \'active\', ?, 1)'
    )->execute([
        $name,
        $name . '@test.local',
        password_hash('Member@123', PASSWORD_BCRYPT),
        ucfirst($name) . ' Test',
        $role,
        strtoupper(bin2hex(random_bytes(4))),
    ]);

    $stmt = db()->prepare('SELECT id, username, role, member_status, is_active FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$name]);

    return (array) $stmt->fetch();
};

$insertNode = static function (int $userId, ?int $uplineId, ?string $side, int $depth, string $path, string $state = 'placed'): void {
    $stmt = db()->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();

    db()->prepare(
        "INSERT IGNORE INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
    )->execute([
        $userId,
        $uplineId,
        $side,
        $depth === 0 ? (int) ($admin['id'] ?? 0) : null,
        $depth,
        $path,
        $state,
        (int) ($admin['id'] ?? 0),
    ]);
};

$buildFixtureTree = static function () use ($createUser, $insertNode): array {
    $stmt = db()->prepare("SELECT id FROM users WHERE username = 'admin' AND role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = (array) $stmt->fetch();

    $boss    = $createUser('boss');
    $buyerA  = $createUser('buyerA');
    $buyerB  = $createUser('buyerB');
    $unplaced = $createUser('unplaced');

    $insertNode((int) $admin['id'], null, null, 0, '', 'root');
    $insertNode((int) $boss['id'],   (int) $admin['id'], 'right', 1, 'R');
    $insertNode((int) $buyerA['id'], (int) $boss['id'],  'left',  2, 'RL');
    $insertNode((int) $buyerB['id'], (int) $boss['id'],  'right', 2, 'RR');

    return [
        'admin'   => $admin,
        'boss'    => $boss,
        'buyerA'  => $buyerA,
        'buyerB'  => $buyerB,
        'unplaced'=> $unplaced,
    ];
};

$createProduct = static function (string $name, string $sku, float $price, float $pv, int $binaryEnabled = 1) use ($token): array {
    $slug = slugify($name . '_' . $token);

    db()->prepare(
        'INSERT INTO products
           (name, sku, slug, price, pv, binary_enabled, unilevel_enabled, unilevel_base, stock, status, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, 0, \'auto\', 100, \'active\', 100)'
    )->execute([
        $name,
        $sku . '_' . bin2hex(random_bytes(4)),
        $slug,
        number_format($price, 2, '.', ''),
        number_format($pv, 2, '.', ''),
        $binaryEnabled,
    ]);

    $stmt = db()->prepare('SELECT * FROM products WHERE sku LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sku . '%']);

    return (array) $stmt->fetch();
};

$setSetting = static function (string $key, string $value): void {
    db()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = ?'
    )->execute([$key, $value, $value]);
};

$preset = static function () use ($setSetting): void {
    $setSetting('binary_enabled', '1');
    $setSetting('binary_self_pct', '5.000');
    $setSetting('binary_pair_points', '1000');
    $setSetting('binary_pair_pct', '10.000');
    $setSetting('binary_daily_pair_cap', '20');
    $setSetting('binary_requires_active', '0');
    $setSetting('binary_hold_days', '7');
    $setSetting('binary_release_enabled', '1');
    $setSetting('binary_reversal_enabled', '1');
    setting_cache_clear();
    setting_all();
};

$addToCart = static function (int $userId, int $productId, int $quantity): void {
    (new CartService())->add($userId, $productId, $quantity);
};

$cartState = static function (int $userId): array {
    return (new CartService())->state($userId, true);
};

$checkoutHtml = static function (array $user, array $state, array $extra = []): string {
    $walletRow = Wallet::account((int) $user['id']) ?? [];
    $balance   = round((float) ($walletRow['ewallet_balance'] ?? 0), 2);
    $total     = round((float) ($state['totals']['subtotal'] ?? 0), 2);

    $paymentMethods = [
        'ewallet' => [
            'label'     => 'E-wallet',
            'icon'      => 'bx-wallet',
            'balance'   => $balance,
            'hint'      => $balance >= $total
                ? 'Pay immediately from your wallet balance.'
                : 'Not enough for this order — choose another method or top up.',
            'available' => $balance >= $total,
        ],
        'gcash' => [
            'label'     => 'GCash',
            'icon'      => 'bx-mobile-alt',
            'hint'      => 'Send the payment, then upload the proof from the order page.',
            'available' => true,
        ],
        'maya' => [
            'label'     => 'Maya',
            'icon'      => 'bx-mobile',
            'hint'      => 'Send the payment, then upload the proof from the order page.',
            'available' => true,
        ],
        'bank' => [
            'label'     => 'Bank transfer',
            'icon'      => 'bx-credit-card',
            'hint'      => 'Transfer to the company account, then upload the proof from the order page.',
            'available' => true,
        ],
    ];
    ob_start();
    try {
        View::page('member', 'shop/checkout', [
            'page_title'      => 'Checkout',
            'current_nav'     => 'cart',
            'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
            'page_js'         => ['assets/js/app-checkout.js', 'assets/js/admin-live.js'],
            'idempotency_key' => bin2hex(random_bytes(16)),
            'shop_open'       => true,
            'state'           => $state,
            'addresses'       => [],
            'selected'        => null,
            'selected_id'     => 0,
            'prefill'         => [],
            'wallet'          => ['balance' => $balance, 'withdrawable' => $balance],
            'payment_methods' => $paymentMethods,
            'terms_version'   => OrderService::TERMS_VERSION,
            'posted'          => [],
            'bonus_preview'   => null,
            'binary_preview'  => $extra['binary_preview'] ?? null,
            'binary_position' => $extra['binary_position'] ?? '',
            'is_placed'       => $extra['is_placed'] ?? false,
            'stream_on'       => $extra['stream_on'] ?? false,
            'live'            => Live::meta(Live::checkoutSurface((int) $user['id']), 'page'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

return [

    /* ==========================================================
     *  P22F-T9 — checkout shows position choice when cart has points
     *  ========================================================== */

    'P22F-T9 checkout shows the left/right position choice when the cart has points' => function () use (
        $buildFixtureTree, $createProduct, $preset, $addToCart, $cartState, $checkoutHtml
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $addToCart((int) $tree['buyerA']['id'], (int) $p1['id'], 4);

        $state = $cartState((int) $tree['buyerA']['id']);
        $quote = (new BinaryService())->quoteCart([
            ['product_id' => (int) $p1['id'], 'quantity' => 4],
        ]);

        test_signed_in_as($tree['buyerA']);

        $html = $checkoutHtml($tree['buyerA'], $state, [
            'binary_preview' => $quote,
            'binary_position' => '',
            'is_placed' => true,
            'stream_on' => true,
        ]);

        assert_contains('Place this purchase', $html);
        assert_contains('Choose the side of your binary tree', $html);
        assert_contains('name="binary_position"', $html);
        assert_contains('value="left"', $html);
        assert_contains('value="right"', $html);
        assert_contains('1000 pts', $html);
        assert_contains(money(50.00), $html);
        assert_contains('The position is final once the order is placed.', $html);

        /* Downward-only copy: no upline, no sponsor, no Team chain. */
        assert_not_contains('upline', $html);
        assert_not_contains('sponsor', $html);
        assert_not_contains('Team chain', $html);
    },

    /* ==========================================================
     *  P22F-T10 — checkout requires position when points exist
     *  ========================================================== */

    'P22F-T10 checkout refuses a paid order without a position when points exist' => function () use (
        $buildFixtureTree, $createProduct, $preset, $addToCart
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $addToCart((int) $tree['buyerA']['id'], (int) $p1['id'], 4);

        test_signed_in_as($tree['buyerA']);

        /* Wallet balance covers the order. */
        (new WalletService())->credit((int) $tree['buyerA']['id'], '1000.00', 'commission', 1, 'Test funds');

        try {
            (new OrderService())->placeFromCart(
                (int) $tree['buyerA']['id'],
                [
                    'billing_name'      => 'Test',
                    'billing_phone'     => '',
                    'shipping_name'     => 'Test',
                    'shipping_phone'    => '',
                    'shipping_address'  => '123 Test Street',
                    'shipping_barangay' => '',
                    'shipping_city'     => 'Test',
                    'shipping_province' => 'Test',
                    'shipping_postal'   => '',
                    'notes_member'      => '',
                    'payment_method'    => 'ewallet',
                    'idempotency_key'   => bin2hex(random_bytes(16)),
                    'binary_position'   => '',
                    'terms'             => '1',
                    'save_address'      => false,
                ]
            );
            assert_true(false, 'expected OrderException for missing position');
        } catch (OrderException $e) {
            $errors = $e->fieldErrors;
            assert_true(is_array($errors) && isset($errors['binary_position']), 'binary_position field error');
            assert_contains('Choose left or right for this order.', $errors['binary_position']);
        }
    },

    /* ==========================================================
     *  P22F-T11 — checkout does not require position when no points
     *  ========================================================== */

    'P22F-T11 checkout accepts an order with no points and no position' => function () use (
        $buildFixtureTree, $createProduct, $preset, $addToCart
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        /* Product with pv = 0: eligible but zero points. */
        $p1 = $createProduct('Freebie', 'FREE', 10.00, 0.00);
        $addToCart((int) $tree['buyerA']['id'], (int) $p1['id'], 1);

        test_signed_in_as($tree['buyerA']);

        (new WalletService())->credit((int) $tree['buyerA']['id'], '1000.00', 'commission', 1, 'Test funds');

        $order = (new OrderService())->placeFromCart(
            (int) $tree['buyerA']['id'],
            [
                'billing_name'      => 'Test',
                'billing_phone'     => '',
                'shipping_name'     => 'Test',
                'shipping_phone'    => '',
                'shipping_address'  => '123 Test Street',
                'shipping_barangay' => '',
                'shipping_city'     => 'Test',
                'shipping_province' => 'Test',
                'shipping_postal'   => '',
                'notes_member'      => '',
                'payment_method'    => 'ewallet',
                'idempotency_key'   => bin2hex(random_bytes(16)),
                'binary_position'   => '',
                'terms'             => '1',
                'save_address'      => false,
            ]
        );

        assert_not_null($order);
        assert_null($order['binary_position'] ?? null, 'no position when no points');
    },

    /* ==========================================================
     *  P22F-T12 — checkout unplaced member sees honest notice
     *  ========================================================== */

    'P22F-T12 checkout unplaced member shows the honest notice, no position choice' => function () use (
        $buildFixtureTree, $createProduct, $preset, $addToCart, $cartState, $checkoutHtml
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $addToCart((int) $tree['unplaced']['id'], (int) $p1['id'], 4);

        $state = $cartState((int) $tree['unplaced']['id']);
        $quote = (new BinaryService())->quoteCart([
            ['product_id' => (int) $p1['id'], 'quantity' => 4],
        ]);

        test_signed_in_as($tree['unplaced']);

        $html = $checkoutHtml($tree['unplaced'], $state, [
            'binary_preview' => $quote,
            'binary_position' => '',
            'is_placed' => false,
            'stream_on' => true,
        ]);

        assert_contains('You are not placed in the binary tree yet', $html);
        assert_contains('this order will not place points', $html);
        assert_not_contains('name="binary_position"', $html, 'no radio choice when unplaced');
    },

    /* ==========================================================
     *  P22F-T13 — checkout stream off: no binary card
     *  ========================================================== */

    'P22F-T13 checkout stream off shows no binary card' => function () use (
        $buildFixtureTree, $createProduct, $addToCart, $cartState, $checkoutHtml
    ): void {
        $tree  = $buildFixtureTree();
        /* Do NOT call $preset(): binary_enabled stays '0'. */

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $addToCart((int) $tree['buyerA']['id'], (int) $p1['id'], 4);

        $state = $cartState((int) $tree['buyerA']['id']);
        $quote = (new BinaryService())->quoteCart([
            ['product_id' => (int) $p1['id'], 'quantity' => 4],
        ]);

        test_signed_in_as($tree['buyerA']);

        assert_false(BinaryService::enabled());

        $html = $checkoutHtml($tree['buyerA'], $state, [
            'binary_preview' => $quote,
            'binary_position' => '',
            'is_placed' => true,
            'stream_on' => false,
        ]);

        assert_not_contains('Place this purchase', $html);
        assert_not_contains('name="binary_position"', $html);
    },

    /* ==========================================================
     *  P22F-T14 — checkout quote card shows per-side pairs
     *  ========================================================== */

    'P22F-T14 checkout quote card shows points, self and per-side pairs' => function () use (
        $buildFixtureTree, $createProduct, $preset, $addToCart, $cartState, $checkoutHtml
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $addToCart((int) $tree['buyerA']['id'], (int) $p1['id'], 4);

        $state = $cartState((int) $tree['buyerA']['id']);
        $quote = (new BinaryService())->quoteCart([
            ['product_id' => (int) $p1['id'], 'quantity' => 4],
        ]);

        test_signed_in_as($tree['buyerA']);

        $html = $checkoutHtml($tree['buyerA'], $state, [
            'binary_preview' => $quote,
            'binary_position' => '',
            'is_placed' => true,
            'stream_on' => true,
        ]);

        assert_contains('1000 pts', $html);
        assert_contains(money(50.00), $html);
        assert_contains('you earn', $html);
        assert_contains('pair', $html);
        assert_contains('now', $html);

        /* buyerA has no existing volume, so 0 pairs on either side. */
        assert_contains('0 pair', $html);
    },

    /* ==========================================================
     *  P22F-T15 — order page shows buyer's own binary outcome
     *  ========================================================== */

    'P22F-T15 order page shows the buyer\'s own side, points, self and pairs' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        test_signed_in_as($tree['buyerA']);

        $orderRow = Order::find((int) $order['id']);
        assert_not_null($orderRow);
        assert_same('left', (string) ($orderRow['binary_position'] ?? ''));
        assert_same(1000, (int) ($orderRow['binary_points'] ?? 0));

        ob_start();
        try {
            View::page('member', 'shop/order', [
                'order'           => $orderRow,
                'lines'           => Order::items((int) $order['id']),
                'events'          => Order::events((int) $order['id']),
                'proofs'          => [],
                'latest_proof'    => null,
                'window'          => ['open' => false, 'reason' => 'status', 'deadline' => null, 'used' => 0, 'left' => 0, 'max' => 3],
                'shipment'        => null,
                'invoice'         => null,
                'can_cancel'      => false,
                'cancel_enabled'  => false,
                'cancel_disabled' => false,
                'can_confirm'     => false,
                'auto_days'       => 0,
                'status'          => 'paid',
                'order_id'        => (int) $order['id'],
                'overdue'         => false,
                'window_open'     => false,
                'window_deadline' => null,
                'is_manual'       => false,
                'instructions'    => [],
                'full_address'    => '',
                'tracking'        => '',
                'courier'         => '',
                'has_invoice'     => false,
                'invoice_url'     => url('orders/' . (int) $order['id'] . '/invoice'),
                'page_title'      => 'Order ' . (string) ($orderRow['order_no'] ?? ''),
                'current_nav'     => 'orders',
                'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
                'page_js'         => [],
                'live'            => Live::meta(Live::memberOrderSurface((int) $order['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Product binary', $html);
        assert_contains('Left', $html);
        assert_contains('1000 pts', $html);
        assert_contains(money(50.00), $html);
        assert_contains('You earn', $html);
        assert_contains('See your Product binary statement', $html);

        /* Downward-only copy. */
        assert_not_contains('upline', $html);
        assert_not_contains('sponsor', $html);
        assert_not_contains('Team chain', $html);
    },

    /* ==========================================================
     *  P22F-T16 — order page pending: "places points when paid"
     *  ========================================================== */

    'P22F-T16 order page pending shows the honest before-payment notice' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'pending', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        db()->prepare('UPDATE shop_orders SET binary_points = 1000 WHERE id = ?')
            ->execute([(int) $order['id']]);

        $orderRow = Order::find((int) $order['id']);
        assert_null($orderRow['binary_processed_at'] ?? null, 'not yet processed');
        assert_same('left', (string) ($orderRow['binary_position'] ?? ''));
        assert_same(1000, (int) ($orderRow['binary_points'] ?? 0));

        ob_start();
        try {
            View::page('member', 'shop/order', [
                'order'           => $orderRow,
                'lines'           => Order::items((int) $order['id']),
                'events'          => Order::events((int) $order['id']),
                'proofs'          => [],
                'latest_proof'    => null,
                'window'          => ['open' => false, 'reason' => 'status', 'deadline' => null, 'used' => 0, 'left' => 0, 'max' => 3],
                'shipment'        => null,
                'invoice'         => null,
                'can_cancel'      => true,
                'cancel_enabled'  => true,
                'cancel_disabled' => false,
                'can_confirm'     => false,
                'auto_days'       => 0,
                'status'          => 'pending',
                'order_id'        => (int) $order['id'],
                'overdue'         => false,
                'window_open'     => false,
                'window_deadline' => null,
                'is_manual'       => true,
                'instructions'    => [],
                'full_address'    => '',
                'tracking'        => '',
                'courier'         => '',
                'has_invoice'     => false,
                'invoice_url'     => url('orders/' . (int) $order['id'] . '/invoice'),
                'page_title'      => 'Order ' . (string) ($orderRow['order_no'] ?? ''),
                'current_nav'     => 'orders',
                'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
                'page_js'         => [],
                'live'            => Live::meta(Live::memberOrderSurface((int) $order['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('This order places points when it is paid.', $html);
    },

    /* ==========================================================
     *  P22F-T17 — product page shows binary panel when on
     *  ========================================================== */

    'P22F-T17 product page shows the binary points panel when stream and product are on' => function () use (
        $createProduct, $preset
    ): void {
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);

        ob_start();
        try {
            View::page('member', 'shop/product', [
                'product'   => $p1,
                'available' => 100,
                'stock'     => ['key' => 'in', 'label' => 'In stock', 'tone' => 'success'],
                'shop_open' => true,
                'bonus'     => null,
                'stream_on' => true,
                'page_title' => (string) $p1['name'],
                'current_nav' => 'shop',
                'nav_search_placeholder' => 'Search the shop…',
                'nav_search_url' => url('shop'),
                'page_css' => ['assets/css/shop.css'],
                'page_js' => ['assets/js/admin-live.js'],
                'live' => Live::meta(Live::shopProductSurface((int) $p1['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Product binary points', $html);
        assert_contains('per unit', $html);
        assert_contains('5%', $html);
        assert_contains('You earn', $html);
        assert_contains('place it left or right', $html);
    },

    /* ==========================================================
     *  P22F-T18 — product page absent when stream off
     *  ========================================================== */

    'P22F-T18 product page shows no binary panel when the stream is off' => function () use (
        $createProduct
    ): void {
        /* Do NOT call $preset(): binary_enabled stays '0'. */

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);

        ob_start();
        try {
            View::page('member', 'shop/product', [
                'product'   => $p1,
                'available' => 100,
                'stock'     => ['key' => 'in', 'label' => 'In stock', 'tone' => 'success'],
                'shop_open' => true,
                'bonus'     => null,
                'stream_on' => false,
                'page_title' => (string) $p1['name'],
                'current_nav' => 'shop',
                'nav_search_placeholder' => 'Search the shop…',
                'nav_search_url' => url('shop'),
                'page_css' => ['assets/css/shop.css'],
                'page_js' => ['assets/js/admin-live.js'],
                'live' => Live::meta(Live::shopProductSurface((int) $p1['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_not_contains('Product binary points', $html);
        assert_not_contains('250 pts per unit', $html);
    },

    /* ==========================================================
     *  P22F-T25 — dashboard tile shows left/right points and earned
     *  ========================================================== */

    'P22F-T25 dashboard tile shows left/right points, next pair and earned' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        test_signed_in_as($tree['buyerA']);

        $totals = (new BinaryService())->totalsFor((int) $tree['buyerA']['id']);

        ob_start();
        try {
            View::page('member', 'member/dashboard', [
                'member'           => $tree['buyerA'],
                'balance'          => 0.0,
                'withdrawable'     => 0.0,
                'orders'           => [],
                'order_stats'      => ['total' => 0, 'open' => 0, 'completed' => 0, 'spent' => 0.0],
                'commissions'      => ['count' => 0, 'credited' => 0.0, 'this_month' => 0.0, 'last_at' => null],
                'team'             => ['direct' => 0, 'team' => 0, 'levels' => 0],
                'open_activation'  => null,
                'last_activation'  => null,
                'activation_count' => 0,
                'recent_history'   => [],
                'unilevel'         => null,
                'pending_repeat'   => ['total' => 0.0, 'count' => 0, 'next_release_at' => null, 'undelivered' => 0, 'overdue' => 0, 'release_enabled' => true],
                'royalty'          => null,
                'team_harvest'     => null,
                'general_harvest'  => null,
                'binary'           => $totals,
                'referral_code'    => (string) ($tree['buyerA']['referral_code'] ?? ''),
                'referral_link'    => absolute_url('register?ref=' . rawurlencode((string) ($tree['buyerA']['referral_code'] ?? ''))),
                'page_title'  => 'Dashboard',
                'current_nav' => 'dashboard',
                'page_css'    => ['assets/css/membership.css', 'assets/css/orders.css'],
                'live'        => Live::meta(Live::memberDashboardSurface((int) $tree['buyerA']['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Product binary', $html);
        assert_contains('1000 pts left', $html);
        assert_contains('0 pts right', $html);
        assert_contains(money(50.00), $html);
        assert_contains('View statement', $html);

        /* Downward-only copy within the binary tile. */
        assert_not_contains('upline', $html);
    },

];
