<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberBinaryPackageTest — Phase 23E-S1 (plan §7 Phase 23E)
 *  Tests P23E-T1 … T5, T8: the Binary bonus tab, the package card,
 *  the source-aware stats and rows, the Source column, the copy guard.
 *
 *  Fixture tree (company root = existing `admin`, placed under its free
 *  RIGHT slot because the demo seed occupies LEFT):
 *
 *      admin   state=root        depth 0, path ''
 *      └─ R  boss                depth 1, path R
 *         ├─ L  buyerA           depth 2, path RL
 *         └─ R  buyerB           depth 2, path RR
 *
 *  Every case runs inside the harness transaction and rolls back.
 *
 *  Run this group alone with:  php tests/run.php MemberBinaryPackage
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

    $insertNode((int) $admin['id'], null, null, 0, '', 'root');
    $insertNode((int) $boss['id'],   (int) $admin['id'], 'right', 1, 'R');
    $insertNode((int) $buyerA['id'], (int) $boss['id'],  'left',  2, 'RL');
    $insertNode((int) $buyerB['id'], (int) $boss['id'],  'right', 2, 'RR');

    return [
        'admin'  => $admin,
        'boss'   => $boss,
        'buyerA' => $buyerA,
        'buyerB' => $buyerB,
    ];
};

$createPackage = static function (string $name, string $slug, float $price, float $pv, int $binaryEnabled = 1, int $binaryPoints = 0) use ($token): array {
    $finalSlug = slugify($slug . '_' . $token);

    db()->prepare(
        'INSERT INTO packages
           (name, slug, price, product_value, direct_referral_bonus,
            indirect_enabled, status, sort_order, binary_enabled, binary_points)
         VALUES (?, ?, ?, ?, ?, 1, \'active\', 100, ?, ?)'
    )->execute([
        $name,
        $finalSlug,
        number_format($price, 2, '.', ''),
        number_format($pv, 2, '.', ''),
        '100.00',
        (string) $binaryEnabled,
        (string) $binaryPoints,
    ]);

    $stmt = db()->prepare('SELECT * FROM packages WHERE slug = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$finalSlug]);

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
    $setSetting('package_binary_enabled', '1');
    $setSetting('package_binary_requires_package', '1');
    setting_cache_clear();
    setting_all();
};

$createOrder = static function (int $userId, string $status = 'paid', string $position = null): array {
    $now = date('Y-m-d H:i:s');
    $paidAt = $status === 'paid' ? $now : null;

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
        $status,
        $position,
        $paidAt,
    ]);

    $stmt = db()->prepare('SELECT * FROM shop_orders WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);

    return (array) $stmt->fetch();
};

$createOrderItem = static function (int $orderId, int $productId, int $quantity, int $unitPoints, int $pointsTotal): void {
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

$payAndStamp = static function (array $order, array $item): array {
    $result = (new BinaryService())->onOrderPaid((int) $order['id']);
    assert_same(true, $result['processed'], 'order processed');
    return $result;
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

return [

    /* ==========================================================
     *  P23E-T1 — /earnings?ledger=binary reads "Binary bonus" with
     *  package card, source split and Source column
     *  ========================================================== */

    'P23E-T1 the binary tab reads Binary bonus with package card and Source column' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp,
        $createPackage
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        /* buyerA activates PKA — produces package volume at c and boss. */
        $activationId = Activation::create([
            'user_id'        => (int) $tree['buyerA']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationId, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);

        (new PackageBinaryService($pdo = db()))->onActivation((int) $activationId, $pdo);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerA']['id']);

        assert_same(EarningsController::TAB_BINARY, $data['tab']);
        assert_same('Binary bonus', 'Binary bonus', 'tab label constant');
        assert_true(array_key_exists('package_card', $data));
        assert_true(array_key_exists('source_options', $data));
        assert_true(array_key_exists('stats', $data));

        ob_start();
        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $tree['buyerA']['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Binary bonus', $html);
        assert_contains('aria-current="page"', $html, 'the binary tab pill is active');
        assert_contains('Source', $html, 'the Source column header is present');
        assert_not_contains('Product binary', $html);

        /* D-22: no upline, no sponsor, no ancestor, no "Team chain", no level. */
        assert_not_contains('upline', $html);
        assert_not_contains('sponsor', $html);
        assert_not_contains('Team chain', $html);
        assert_not_contains('Level ', $html);
    },

    /* ==========================================================
     *  P23E-T2 — The package card shows package name, points, pairs,
     *  money and last activation
     *  ========================================================== */

    'P23E-T2 the package card shows package name, points, pairs, money, last activation' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp,
        $createPackage
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        $activationId = Activation::create([
            'user_id'        => (int) $tree['buyerA']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationId, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);

        $binary = (new PackageBinaryService($pdo = db()))->onActivation((int) $activationId, $pdo);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerA']['id']);

        $card = $data['package_card'];
        assert_not_null($card);
        assert_same(true, $card['has_package']);
        assert_same('Package A', $card['package_name']);
        assert_same(true, $card['binary_enabled']);
        assert_same(1000, $card['binary_points']);

        ob_start();
        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $tree['buyerA']['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Package A', $html);
        assert_contains('1000 pts', $html);
        assert_contains('Package activations', $html);
    },

    /* ==========================================================
     *  P23E-T3 — Copy guard (D-22): no upline, sponsor, "Team chain",
     *  or level words on the tab
     *  ========================================================== */

    'P23E-T3 the binary tab names no upline, sponsor, ancestor or team chain' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp,
        $createPackage
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        $activationId = Activation::create([
            'user_id'        => (int) $tree['buyerA']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationId, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);

        (new PackageBinaryService($pdo = db()))->onActivation((int) $activationId, $pdo);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerA']['id']);

        ob_start();
        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $tree['buyerA']['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        /* Downward-only copy is present. */
        assert_contains('left', strtolower($html));
        assert_contains('right', strtolower($html));

        /* No ancestor surface anywhere. */
        assert_not_contains('upline', $html);
        assert_not_contains('sponsor', $html);
        assert_not_contains('ancestor', $html);
        assert_not_contains('Team chain', $html);
    },

    /* ==========================================================
     *  P23E-T4 — A member with no binary package: the card is absent,
     *  the page still works
     *  ========================================================== */

    'P23E-T4 a member with no binary package sees no package card' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp,
        $createPackage
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        /* buyerB has no package_id set — no binary package. */

        test_signed_in_as($tree['buyerB']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerB']['id']);

        $card = $data['package_card'];
        assert_not_null($card);
        assert_same(false, $card['has_package']);

        ob_start();
        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $tree['buyerB']['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_not_contains('Package A', $html);
        assert_contains('Binary bonus', $html);
    },

    /* ==========================================================
     *  P23E-T5 — ?source=activation filters to package rows only;
     *  stats stay whole (balances are not filtered)
     *  ========================================================== */

    'P23E-T5 source=activation shows only package rows, stats stay whole' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp,
        $createPackage
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);
        $p1  = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);

        /* buyerA buys a product — order-sourced self award. */
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        /* buyerA activates PKA — package volume at c and boss, no award for buyerA. */
        $activationId = Activation::create([
            'user_id'        => (int) $tree['buyerA']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationId, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);

        (new PackageBinaryService($pdo = db()))->onActivation((int) $activationId, $pdo);

        test_signed_in_as($tree['buyerA']);

        /* All sources: one order-sourced self award. */
        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $all = $controller->binaryContext((int) $tree['buyerA']['id']);
        assert_same(1, (int) $all['pager']->total, 'one award across sources');
        assert_same('order', (string) $all['awards'][0]['source'], 'the award is order-sourced');

        /* Source filter: activation only → zero awards (buyerA's activation pays uplines, not them). */
        $_GET = ['ledger' => 'binary', 'source' => 'activation'];
        $activationOnly = $controller->binaryContext((int) $tree['buyerA']['id']);
        assert_same(0, (int) $activationOnly['pager']->total, 'no activation-sourced awards for the activator');

        /* Source filter: order only → one award. */
        $_GET = ['ledger' => 'binary', 'source' => 'order'];
        $orderOnly = $controller->binaryContext((int) $tree['buyerA']['id']);
        assert_same(1, (int) $orderOnly['pager']->total, 'one order-sourced award');

        /* Stats stay whole — not narrowed by the source filter. */
        assert_same($all['stats']['order_credited_total'], $activationOnly['stats']['order_credited_total']);
        assert_same($all['stats']['activation_credited_total'], $orderOnly['stats']['activation_credited_total']);
    },

    /* ==========================================================
     *  P23E-T8 — The product-only member: the page is the Phase 22
     *  page plus the split (which is 0/0)
     *  ========================================================== */

    'P23E-T8 a product-only member sees the Phase 22 page with 0/0 split' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp,
        $createPackage
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);
        $p1  = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);

        /* buyerA buys a product — only order-sourced awards, no package activity. */
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerA']['id']);

        $card = $data['package_card'];
        assert_not_null($card);
        assert_same(false, $card['has_package']);

        assert_same(0.0, (float) $data['stats']['activation_credited_total']);
        assert_same(0, (int) $data['stats']['activation_pairs']);
        assert_same(0, (int) $data['stats']['activation_points']);

        ob_start();
        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $tree['buyerA']['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Binary bonus', $html);
        assert_not_contains('Package A', $html, 'no package card when no binary package');
        assert_contains('Package activations', $html, 'stats card label present even at 0');
    },
];
