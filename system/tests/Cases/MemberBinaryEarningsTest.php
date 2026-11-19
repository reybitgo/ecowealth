<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberBinaryEarningsTest — Phase 22F-S1 (plan §7 Phase 22F)
 *
 *  Proves the session-scoped `ledger=binary` earnings tab: the self /
 *  pair award statement, member totals, filters, statuses and CSV, all
 *  scoped to the signed-in member and free of any upline, sponsor or
 *  ancestor reference (plan §14.12, D-22).
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
 *  Run this group alone with:  php tests/run.php MemberBinaryEarnings
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

    /* The demo seed already rooted `admin`; attach the fixture under its
     * free RIGHT slot so `ancestors()` returns only this lineage. */
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

$releaseOrder = static function (array $order): void {
    test_deliver_order((int) $order['id'], date('Y-m-d H:i:s'));
    (new BinaryService())->releaseForOrder((int) $order['id']);
};

return [

    /* ==========================================================
     *  P22F-T1 — the binary tab renders for a placed member
     *  ========================================================== */

    'P22F-T1 /earnings?ledger=binary renders the binary tab active with stats, self card and awards' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        assert_same(EarningsController::TAB_BINARY, EarningsController::tab());
        assert_same('binary', EarningsController::TAB_BINARY);

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerA']['id']);

        assert_same(EarningsController::TAB_BINARY, $data['tab']);
        assert_same(1, (int) $data['totals']['self_count']);
        assert_same(50.00, (float) $data['totals']['self_total']);
        assert_same(1, (int) $data['pager']->total);
        assert_same(1, count($data['awards']));
        assert_same('self', (string) $data['awards'][0]['kind']);
        assert_same('pending', (string) $data['awards'][0]['status']);
        assert_same((int) $tree['buyerA']['id'], (int) $data['awards'][0]['user_id']);

        /* The full page: four tabs, the binary pill active, stats + self +
         * awards table rendered, no ancestor language. */
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

        assert_contains('Product binary', $html);
        assert_contains('aria-current="page"', $html, 'the binary tab pill is active');
        assert_not_contains('Package commissions'.' aria-current', $html);
        assert_contains('Your self bonus', $html);
        assert_contains(money(50.00), $html);
        assert_contains('You earn on your own purchase', $html);
        assert_contains('not yet in your wallet', $html);
        assert_contains((string) $tree['buyerA']['username'], $html);

        /* D-22: no upline, no sponsor, no ancestor, no "Team chain", no level. */
        assert_not_contains('upline', $html);
        assert_not_contains('sponsor', $html);
        assert_not_contains('Team chain', $html);
        assert_not_contains('Level ', $html);
    },

    /* ==========================================================
     *  P22F-T6 — copy guard (D-22)
     *  ========================================================== */

    'P22F-T6 the binary tab names no upline, sponsor, ancestor or team chain' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

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
     *  P22F-T7 — a member with no node sees the honest state
     *  ========================================================== */

    'P22F-T7 a member with no node sees the not-placed panel and can still shop' => function () use (
        $buildFixtureTree, $preset
    ): void {
        $tree = $buildFixtureTree();
        $preset();

        test_signed_in_as($tree['unplaced']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['unplaced']['id']);

        assert_same(EarningsController::TAB_BINARY, $data['tab']);
        assert_same(0, (int) $data['pager']->total);
        assert_same(0, count($data['awards']));
        assert_null(BinaryNode::forUser((int) $tree['unplaced']['id']));

        ob_start();
        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $tree['unplaced']['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('You are not placed in the binary tree yet', $html);
        assert_contains('Your purchases will not place points until you are placed', $html);
        assert_not_contains('Your self bonus', $html);
        assert_not_contains('data-live-region="earnings-binary-rows"', $html);
    },

    /* ==========================================================
     *  P22F-T8 — stream off: the tab pill is absent
     *  ========================================================== */

    'P22F-T8 with the stream off, ?ledger=binary falls back to the package tab' => function () use (
        $buildFixtureTree, $createProduct, $createOrder, $createOrderItem, $payAndStamp
    ): void {
        $tree  = $buildFixtureTree();
        /* Deliberately do NOT call $preset(): binary_enabled stays '0'. */

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        assert_false(BinaryService::enabled());
        assert_same(EarningsController::TAB_PACKAGE, EarningsController::tab());

        $controller = new EarningsController();
        $data       = $controller->context((int) $tree['buyerA']['id']);

        assert_same(EarningsController::TAB_PACKAGE, $data['tab']);
        assert_false(array_key_exists('binary_url', $data));
    },

    /* ==========================================================
     *  P22F-T19 — the earnings CSV has no upline column
     *  ========================================================== */

    'P22F-T19 the binary CSV lists one row per award with no upline column' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        $rows = BinaryAward::exportRows((int) $tree['buyerA']['id'], BinaryAward::filterDefaults());

        assert_count(1, $rows);
        assert_same('self', (string) $rows[0]['kind']);
        assert_same((int) $tree['buyerA']['id'], (int) $rows[0]['user_id']);

        $header = BinaryAward::csvHeader();
        $csv    = BinaryAward::csvRows($rows);

        /* One row per award, the documented columns. */
        assert_count(count($header), $csv[0]);
        assert_true(in_array('Award', $header, true));
        assert_true(in_array('Amount', $header, true));
        assert_true(in_array('Status', $header, true));

        /* No upline / sponsor / ancestor column. */
        foreach ($header as $column) {
            assert_false(str_contains((string) $column, 'upline'));
            assert_false(str_contains((string) $column, 'sponsor'));
            assert_false(str_contains((string) $column, 'ancestor'));
        }
    },

    /* ==========================================================
     *  P22F-T20 — filters: kind and status
     *  ========================================================== */

    'P22F-T20 kind and status filters narrow the member\'s own awards' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp,
        $releaseOrder, $setSetting
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);

        /* buyerA: one pending self award. */
        $orderA = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $orderA['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($orderA, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        /* buyerA: a second self award, delivered + released (credited). */
        $setSetting('binary_hold_days', '0');
        setting_cache_clear();
        setting_all();
        $orderB = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $orderB['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($orderB, ['product_id' => (int) $p1['id'], 'quantity' => 4]);
        $releaseOrder($orderB);

        /* buyerB: an award that must NOT appear on buyerA's tab. */
        $orderC = $createOrder((int) $tree['buyerB']['id'], 'paid', 'right');
        $createOrderItem((int) $orderC['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($orderC, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        $controller = new EarningsController();

        /* All of buyerA's awards: 2 self. */
        $all = $controller->binaryContext((int) $tree['buyerA']['id']);
        assert_same(2, (int) $all['pager']->total);
        assert_same(2, count($all['awards']));

        /* Kind filter: only self. */
        $_GET = ['ledger' => 'binary', 'kind' => 'self'];
        $selfOnly = $controller->binaryContext((int) $tree['buyerA']['id']);
        assert_same(2, (int) $selfOnly['pager']->total);

        /* Status filter: only pending. */
        $_GET = ['ledger' => 'binary', 'status' => 'pending'];
        $pendingOnly = $controller->binaryContext((int) $tree['buyerA']['id']);
        assert_same(1, (int) $pendingOnly['pager']->total);
        assert_same('pending', (string) $pendingOnly['awards'][0]['status']);
        assert_same((int) $orderA['id'], (int) $pendingOnly['awards'][0]['order_id']);

        /* buyerB's award never leaks into buyerA's statement. */
        $buyerBAwards = BinaryAward::forUser((int) $tree['buyerB']['id']);
        assert_count(1, $buyerBAwards);
        $found = false;
        foreach ($all['awards'] as $award) {
            if ((int) $award['order_id'] === (int) $orderC['id']) {
                $found = true;
            }
        }
        assert_false($found, 'buyerB\'s award must not appear on buyerA\'s tab');
    },

    /* ==========================================================
     *  P22F-T21 — the live surface returns binary fragments
     *  ========================================================== */

    'P22F-T21 the binary feed returns the binary regions on the earnings surface' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $order['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($order, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $id = (int) $tree['buyerA']['id'];
        assert_same('member.earnings:' . $id, Live::earningsSurface($id));

        $controller = new EarningsController();
        $data       = $controller->binaryContext($id);
        $payload    = $controller->feedPayload(
            Live::revision(Live::earningsSurface($id)),
            '"x"',
            $data
        );

        assert_true($payload['ok']);
        assert_same('member.earnings:' . $id, $payload['surface']);
        assert_same([
            'earnings-binary-header',
            'earnings-binary-stats',
            'earnings-binary-self',
            'earnings-binary-rows',
            'earnings-binary-pagination',
        ], array_keys($payload['fragments']));

        $stats = $payload['fragments']['earnings-binary-stats'];
        $self  = $payload['fragments']['earnings-binary-self'];
        $rows  = $payload['fragments']['earnings-binary-rows'];

        assert_contains('data-live-region="earnings-binary-stats"', $stats);
        assert_contains('Your self bonus', $stats);
        assert_contains(money(50.00), $stats);
        assert_contains('data-live-region="earnings-binary-self"', $self);
        assert_contains('You earn on your own purchase', $self);
        assert_contains('data-live-region="earnings-binary-rows"', $rows);
        assert_contains('Pending release', $rows);
        assert_contains((string) $tree['buyerA']['username'], $rows);
    },

    /* ==========================================================
     *  P22F-T23 — session-scoped: one member cannot read another's tab
     *  ========================================================== */

    'P22F-T23 the binary tab is session-scoped; another member\'s awards are hidden' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);

        /* buyerA earns a self award. */
        $orderA = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $orderA['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($orderA, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        /* buyerB earns a self award. */
        $orderB = $createOrder((int) $tree['buyerB']['id'], 'paid', 'right');
        $createOrderItem((int) $orderB['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($orderB, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        /* Sign in as buyerA: the statement carries buyerA's id, not buyerB's. */
        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerA']['id']);

        assert_same(1, (int) $data['pager']->total);
        assert_same((int) $tree['buyerA']['id'], (int) $data['awards'][0]['user_id']);
        assert_same((int) $orderA['id'], (int) $data['awards'][0]['order_id']);

        /* buyerB's award is absent. */
        $buyerBFound = false;
        foreach ($data['awards'] as $award) {
            if ((int) $award['order_id'] === (int) $orderB['id']) {
                $buyerBFound = true;
            }
        }
        assert_false($buyerBFound, 'buyerB\'s award must not appear on buyerA\'s tab');

        /* A `?user_id=` style widening has no parameter here; the model
         * forces the session member's id, so the statement stays private. */
        $allBuyerB = BinaryAward::forUser((int) $tree['buyerB']['id']);
        assert_count(1, $allBuyerB);
        assert_same((int) $tree['buyerB']['id'], (int) $allBuyerB[0]['user_id']);
        assert_same((int) $orderB['id'], (int) $allBuyerB[0]['order_id']);
    },

    /* ==========================================================
     *  P22F-T28 — pending and credited awards show in the stats
     *  ========================================================== */

    'P22F-T28 pending and credited awards both appear in the member stats' => function () use (
        $buildFixtureTree, $createProduct, $preset, $createOrder, $createOrderItem, $payAndStamp,
        $releaseOrder, $setSetting
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);

        /* First order: pending self award (not yet released). */
        $orderA = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $orderA['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($orderA, ['product_id' => (int) $p1['id'], 'quantity' => 4]);

        /* Second order: delivered + released → credited self award. */
        $setSetting('binary_hold_days', '0');
        setting_cache_clear();
        setting_all();
        $orderB = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $createOrderItem((int) $orderB['id'], (int) $p1['id'], 4, 250, 1000);
        $payAndStamp($orderB, ['product_id' => (int) $p1['id'], 'quantity' => 4]);
        $releaseOrder($orderB);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerA']['id']);

        assert_same(2, (int) $data['totals']['count']);
        assert_same(2, (int) $data['totals']['self_count']);
        assert_same(100.00, (float) $data['totals']['self_total']);
        assert_same(1, (int) $data['totals']['pending_count']);
        assert_same(50.00, (float) $data['totals']['pending']);
        assert_same(50.00, (float) $data['totals']['credited']);
        assert_same(100.00, (float) $data['totals']['total_awarded']);

        $statuses = array_map(
            static fn (array $a): string => (string) $a['status'],
            $data['awards']
        );
        assert_true(in_array('pending', $statuses, true));
        assert_true(in_array('credited', $statuses, true));

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

        assert_contains('Pending release', $html);
        assert_contains(money(50.00), $html);
        assert_contains('Credited', $html);
    },
];
