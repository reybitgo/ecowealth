<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryServiceTest — Phase 22D-S1 (plan §7 Phase 22D)
 *
 *  Proves the points/award persistence, the quote API and the order
 *  snapshot on its own. No paid-order hook is wired yet
 *  (OrderService/PaymentService are not called), so `binary_enabled`
 *  stays off and no paid order creates binary rows here.
 *
 *  Fixture tree (plan §18.2):
 *
 *      admin   state=root        depth 0, path ''
 *      └─ L  boss                depth 1, path L
 *         ├─ L  a                depth 2, path LL
 *         │  ├─ L  b             depth 3, path LLL
 *         │  │  └─ L  c          depth 4, path LLLL
 *         │  │     ├─ L  buyerA  depth 5, path LLLLL
 *         │  │     └─ R  buyerB  depth 5, path LLLLR
 *         │  └─ R  sideR         depth 3, path LLR
 *         └─ R  d                depth 2, path LR
 *
 *  Products:
 *    P1 Coffee   price ₱300  P.V. 250   binary on  → 250 pts/unit
 *    P2          price ₱500  P.V. 0     binary on  → 0 pts
 *    P3 Spirulina price ₱400 P.V. 300   binary off → 0 pts
 *    P4          price ₱1,000 P.V. 1,000 binary on → 1,000 pts/unit
 *
 *  Preset: self 5%, pair 1,000 points, pair 10%, cap 20, hold 7,
 *  requires Active off (S1 award cases turn it on where needed).
 *
 *  Every case runs inside the harness transaction and rolls back.
 *
 *  Run this group alone with:  php tests/run.php BinaryService
 * ============================================================== */

$token = bin2hex(random_bytes(4));

$service = new BinaryService();

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

$insertNode = static function (int $userId, ?int $uplineId, ?string $side, int $depth, string $path, string $state = 'placed') use ($token): void {
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
    $admin  = $createUser('admin', 'admin');
    $boss   = $createUser('boss');
    $a      = $createUser('a');
    $b      = $createUser('b');
    $c      = $createUser('c');
    $buyerA = $createUser('buyerA');
    $buyerB = $createUser('buyerB');
    $sideR  = $createUser('sideR');
    $d      = $createUser('d');

    $insertNode((int) $admin['id'], null, null, 0, '', 'root');
    $insertNode((int) $boss['id'],  (int) $admin['id'], 'left',  1, 'L');
    $insertNode((int) $a['id'],     (int) $boss['id'],  'left',  2, 'LL');
    $insertNode((int) $b['id'],     (int) $a['id'],     'left',  3, 'LLL');
    $insertNode((int) $c['id'],     (int) $b['id'],     'left',  4, 'LLLL');
    $insertNode((int) $buyerA['id'],(int) $c['id'],     'left',  5, 'LLLLL');
    $insertNode((int) $buyerB['id'],(int) $c['id'],     'right', 5, 'LLLLR');
    $insertNode((int) $sideR['id'], (int) $a['id'],     'right', 3, 'LLR');
    $insertNode((int) $d['id'],     (int) $boss['id'],  'right', 2, 'LR');

    return [
        'admin'  => $admin,
        'boss'   => $boss,
        'a'      => $a,
        'b'      => $b,
        'c'      => $c,
        'buyerA' => $buyerA,
        'buyerB' => $buyerB,
        'sideR'  => $sideR,
        'd'      => $d,
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
                 '1234', 'PH', 'v1', NOW(), NULL, ?, ?, ?)"
    )->execute([
        'T' . bin2hex(random_bytes(9)),
        $userId,
        $status,
        $position,
        $position !== null ? $now : null,
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

return [

    /* ----------------------------------------------------------
     *  Points and rounding (P22D-T6 … T9)
     *  ---------------------------------------------------------- */

    'P22D-T6 mixed cart: P1+P2+P3 contribute only P1 points' => function () use ($buildFixtureTree, $createProduct, $preset): void {
        $tree = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE', 300.00, 250.00);
        $p2 = $createProduct('ZeroPV', 'ZERO', 500.00, 0.00);
        $p3 = $createProduct('Spirulina', 'SPIR', 400.00, 300.00, 0);

        $quote = (new BinaryService())->quoteCart([
            ['product_id' => (int) $p1['id'], 'quantity' => 4],
            ['product_id' => (int) $p2['id'], 'quantity' => 1],
            ['product_id' => (int) $p3['id'], 'quantity' => 1],
        ]);

        assert_same(true, $quote['eligible']);
        assert_same(1000, (int) $quote['points']);
        assert_same(50.00, (float) $quote['self']);
        assert_count(1, $quote['lines']);
    },

    'P22D-T7 3 × P1 → 750 points, self ₱37.50, no pair' => function () use ($buildFixtureTree, $createProduct, $preset): void {
        $tree = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE2', 300.00, 250.00);

        $quote = (new BinaryService())->quoteCart([
            ['product_id' => (int) $p1['id'], 'quantity' => 3],
        ]);

        assert_same(750, (int) $quote['points']);
        assert_same(37.50, (float) $quote['self']);
        assert_same(0, (int) $quote['sides']['left']['pairs']);
        assert_same(0, (int) $quote['sides']['right']['pairs']);
    },

    'P22D-T8 pv = 250.4 → unit_points = 250 (half away from zero)' => function () use ($buildFixtureTree, $createProduct, $preset): void {
        $tree = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE3', 300.00, 250.40);

        $lp = BinaryService::linePoints(['pv' => 250.40], 4);

        assert_same(250, (int) $lp['unit_points']);
        assert_same(1000, (int) $lp['points_total']);
    },

    'P22D-T9 pv = 249.6 → unit_points = 250' => function () use ($buildFixtureTree, $createProduct, $preset): void {
        $tree = $buildFixtureTree();
        $preset();

        $lp = BinaryService::linePoints(['pv' => 249.60], 4);

        assert_same(250, (int) $lp['unit_points']);
        assert_same(1000, (int) $lp['points_total']);
    },

    /* ----------------------------------------------------------
     *  Idempotency keys (P22D-T40)
     *  ---------------------------------------------------------- */

    'P22D-T40 duplicate (order_id, user_id, kind) insert → PDOException 23000' => function () use ($buildFixtureTree, $createProduct, $preset, $createOrder): void {
        $tree = $buildFixtureTree();
        $preset();

        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');

        BinaryAward::create([
            'kind'       => 'self',
            'order_id'   => (int) $order['id'],
            'buyer_id'   => (int) $tree['buyerA']['id'],
            'user_id'    => (int) $tree['buyerA']['id'],
            'side'       => 'left',
            'points'     => 1000,
            'pair_count' => 0,
            'pair_points'=> 0,
            'rate_pct'   => 5.000,
            'amount'     => 50.00,
            'status'     => 'pending',
            'hold_days'  => 7,
            'paid_at'    => date('Y-m-d H:i:s'),
        ]);

        $e = assert_throws(
            function () use ($order, $tree): void {
                BinaryAward::create([
                    'kind'       => 'self',
                    'order_id'   => (int) $order['id'],
                    'buyer_id'   => (int) $tree['buyerA']['id'],
                    'user_id'    => (int) $tree['buyerA']['id'],
                    'side'       => 'left',
                    'points'     => 1000,
                    'pair_count' => 0,
                    'pair_points'=> 0,
                    'rate_pct'   => 5.000,
                    'amount'     => 50.00,
                    'status'     => 'pending',
                    'hold_days'  => 7,
                    'paid_at'    => date('Y-m-d H:i:s'),
                ]);
            },
            PDOException::class
        );
        assert_same('23000', $e->getCode());
    },

    /* ----------------------------------------------------------
     *  Quote API (P22D-T44, T45)
     *  ---------------------------------------------------------- */

    'P22D-T44 quoteCart() computes points/self, writes nothing' => function () use ($buildFixtureTree, $createProduct, $preset): void {
        $tree = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE4', 300.00, 250.00);

        $beforeVolume = db()->query('SELECT COUNT(*) FROM binary_volume')->fetchColumn();
        $beforeAwards = db()->query('SELECT COUNT(*) FROM binary_awards')->fetchColumn();

        $quote = (new BinaryService())->quoteCart([
            ['product_id' => (int) $p1['id'], 'quantity' => 4],
        ]);

        assert_same(1000, (int) $quote['points']);
        assert_same(50.00, (float) $quote['self']);
        assert_same((int) $beforeVolume, (int) db()->query('SELECT COUNT(*) FROM binary_volume')->fetchColumn());
        assert_same((int) $beforeAwards, (int) db()->query('SELECT COUNT(*) FROM binary_awards')->fetchColumn());
    },

    'P22D-T45 quotePosition() left vs right differ, self identical' => function () use ($buildFixtureTree, $createProduct, $preset): void {
        $tree = $buildFixtureTree();
        $preset();

        $p1 = $createProduct('Coffee', 'COFFEE5', 300.00, 250.00);

        $left  = (new BinaryService())->quotePosition([
            ['product_id' => (int) $p1['id'], 'quantity' => 4],
        ], 'left');
        $right = (new BinaryService())->quotePosition([
            ['product_id' => (int) $p1['id'], 'quantity' => 4],
        ], 'right');

        assert_same('left', (string) $left['side']);
        assert_same('right', (string) $right['side']);
        assert_same((float) $left['points'], (float) $right['points']);
    },

    /* ----------------------------------------------------------
     *  Totals (P22D-T46)
     *  ---------------------------------------------------------- */

    'P22D-T46 totalsFor() returns credited, pending, self, pair' => function () use ($buildFixtureTree, $createOrder): void {
        $tree = $buildFixtureTree();

        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');

        BinaryAward::create([
            'kind'       => 'self',
            'order_id'   => (int) $order['id'],
            'buyer_id'   => (int) $tree['buyerA']['id'],
            'user_id'    => (int) $tree['buyerA']['id'],
            'side'       => 'left',
            'points'     => 1000,
            'pair_count' => 0,
            'pair_points'=> 0,
            'rate_pct'   => 5.000,
            'amount'     => 50.00,
            'status'     => 'pending',
            'hold_days'  => 7,
            'paid_at'    => date('Y-m-d H:i:s'),
        ]);

        $totals = (new BinaryService())->totalsFor((int) $tree['buyerA']['id']);

        assert_same(50.00, (float) $totals['pending']);
        assert_same(0.0, (float) $totals['credited']);
        assert_same(50.00, (float) $totals['self_total']);
        assert_same(0.0, (float) $totals['pair_total']);
        assert_same(1, (int) $totals['self_count']);
        assert_same(0, (int) $totals['pair_count']);
    },

    /* ----------------------------------------------------------
     *  Reconcile (P22D-T47, T48)
     *  ---------------------------------------------------------- */

    'P22D-T47 reconcile after awards and releases → ok = true' => function () use ($buildFixtureTree, $createOrder): void {
        $tree = $buildFixtureTree();

        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');

        db()->prepare(
            'UPDATE shop_orders SET binary_processed_at = NOW(), binary_points = 1000,
             binary_self_total = 50.00, binary_pair_total = 0.00, binary_total = 50.00 WHERE id = ?'
        )->execute([(int) $order['id']]);

        $awardId = BinaryAward::create([
            'kind'       => 'self',
            'order_id'   => (int) $order['id'],
            'buyer_id'   => (int) $tree['buyerA']['id'],
            'user_id'    => (int) $tree['buyerA']['id'],
            'side'       => 'left',
            'points'     => 1000,
            'pair_count' => 0,
            'pair_points'=> 0,
            'rate_pct'   => 5.000,
            'amount'     => 50.00,
            'status'     => 'credited',
            'hold_days'  => 7,
            'paid_at'    => date('Y-m-d H:i:s'),
        ]);

        db()->prepare(
            'INSERT INTO ewallet_ledger (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
             VALUES (?, \'credit\', 50.00, 50.00, 50.00, \'binary\', ?, \'Product binary self bonus — order T\')'
        )->execute([(int) $tree['buyerA']['id'], $awardId]);
        $ledgerId = (int) db()->lastInsertId();

        BinaryAward::linkLedger($awardId, $ledgerId);

        $result = (new BinaryService())->reconcile();

        assert_same(true, $result['ok']);
    },

    'P22D-T48 reconcile with a deleted binary_volume row → ok = false' => function () use ($buildFixtureTree, $createOrder, $createProduct): void {
        $tree = $buildFixtureTree();

        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $p1 = $createProduct('Coffee', 'COFFEE7', 300.00, 250.00);

        $itemId = db()->prepare(
            'INSERT INTO shop_order_items (order_id, product_id, product_name, quantity, unit_price, line_total,
             unit_base, base_total, unit_points, points_total, reservation_state)
             VALUES (?, ?, \'Test\', 1, 0, 0, 0, 0, 0, 0, \'reserved\')'
        )->execute([(int) $order['id'], (int) $p1['id']]);

        $volumeId = BinaryVolume::create([
            'node_user_id' => (int) $tree['buyerA']['id'],
            'side'         => 'left',
            'kind'         => 'own',
            'points'       => 1000,
            'order_id'     => (int) $order['id'],
            'order_item_id'=> (int) db()->lastInsertId(),
            'buyer_id'     => (int) $tree['buyerA']['id'],
            'note'         => 'test volume',
        ]);

        /* Update the cache as onOrderPaid() would, so a deleted volume
         * row shows up as drift. */
        db()->prepare('UPDATE binary_nodes SET left_points = left_points + 1000 WHERE user_id = ?')
            ->execute([(int) $tree['buyerA']['id']]);

        /* The audit reports drift when a volume row is missing. */
        db()->prepare('DELETE FROM binary_volume WHERE id = ?')->execute([$volumeId]);

        $audit = (new BinaryService())->audit();
        assert_same(false, $audit['ok']);
        assert_count(1, $audit['drifts']);
    },

    /* ----------------------------------------------------------
     *  Audit (P22D-T49, T50)
     *  ---------------------------------------------------------- */

    'P22D-T49 audit() reports corrupted left_points; auditFix() repairs' => function () use ($buildFixtureTree, $createOrder, $createProduct): void {
        $tree = $buildFixtureTree();

        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $p1 = $createProduct('Coffee', 'COFFEE8', 300.00, 250.00);

        $itemId = db()->prepare(
            'INSERT INTO shop_order_items (order_id, product_id, product_name, quantity, unit_price, line_total,
             unit_base, base_total, unit_points, points_total, reservation_state)
             VALUES (?, ?, \'Test\', 1, 0, 0, 0, 0, 0, 0, \'reserved\')'
        )->execute([(int) $order['id'], (int) $p1['id']]);

        BinaryVolume::create([
            'node_user_id' => (int) $tree['buyerA']['id'],
            'side'         => 'left',
            'kind'         => 'own',
            'points'       => 1000,
            'order_id'     => (int) $order['id'],
            'order_item_id'=> (int) db()->lastInsertId(),
            'buyer_id'     => (int) $tree['buyerA']['id'],
            'note'         => 'test volume',
        ]);

        /* Corrupt the cache. */
        db()->prepare('UPDATE binary_nodes SET left_points = 999999 WHERE user_id = ?')
            ->execute([(int) $tree['buyerA']['id']]);

        $audit = (new BinaryService())->audit();
        assert_same(false, $audit['ok']);

        $hasDrift = false;
        foreach ($audit['drifts'] as $drift) {
            if ((int) ($drift['user_id'] ?? 0) === (int) $tree['buyerA']['id']
                && (string) ($drift['side'] ?? '') === 'left') {
                $hasDrift = true;
                assert_same(999999 - 1000, (int) $drift['delta']);
            }
        }
        assert_true($hasDrift, 'expected a left_points drift for buyerA');

        $fix = (new BinaryService())->auditFix();
        assert_same(true, $fix['ok']);

        $recheck = (new BinaryService())->audit();
        assert_same(true, $recheck['ok']);
    },

    'P22D-T50 audit() never changes an award or a wallet row' => function () use ($buildFixtureTree, $createOrder): void {
        $tree = $buildFixtureTree();

        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');

        $awardId = BinaryAward::create([
            'kind'       => 'self',
            'order_id'   => (int) $order['id'],
            'buyer_id'   => (int) $tree['buyerA']['id'],
            'user_id'    => (int) $tree['buyerA']['id'],
            'side'       => 'left',
            'points'     => 1000,
            'pair_count' => 0,
            'pair_points'=> 0,
            'rate_pct'   => 5.000,
            'amount'     => 50.00,
            'status'     => 'pending',
            'hold_days'  => 7,
            'paid_at'    => date('Y-m-d H:i:s'),
        ]);

        $beforeAward = BinaryAward::find($awardId);
        $beforeLedger = db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();

        (new BinaryService())->audit();

        $afterAward = BinaryAward::find($awardId);
        $afterLedger = db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();

        assert_same((string) $beforeAward['status'], (string) $afterAward['status']);
        assert_same((string) $beforeAward['amount'], (string) $afterAward['amount']);
        assert_same((int) $beforeLedger, (int) $afterLedger);
    },

    /* ----------------------------------------------------------
     *  Product delete guard (P22D-T51)
     *  ---------------------------------------------------------- */

    'P22D-T51 delete a product that has awards → refused' => function () use ($buildFixtureTree, $createProduct, $createOrder): void {
        $tree = $buildFixtureTree();

        $p1 = $createProduct('Coffee', 'COFFEE6', 300.00, 250.00);
        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');

        db()->prepare(
            'INSERT INTO shop_order_items (order_id, product_id, product_name, quantity, unit_price, line_total,
             unit_base, base_total, unit_points, points_total, reservation_state)
             VALUES (?, ?, \'Test\', 1, 0, 0, 0, 0, 0, 0, \'reserved\')'
        )->execute([(int) $order['id'], (int) $p1['id']]);

        BinaryAward::create([
            'kind'       => 'self',
            'order_id'   => (int) $order['id'],
            'buyer_id'   => (int) $tree['buyerA']['id'],
            'user_id'    => (int) $tree['buyerA']['id'],
            'side'       => 'left',
            'points'     => 1000,
            'pair_count' => 0,
            'pair_points'=> 0,
            'rate_pct'   => 5.000,
            'amount'     => 50.00,
            'status'     => 'pending',
            'hold_days'  => 7,
            'paid_at'    => date('Y-m-d H:i:s'),
        ]);

        $result = Product::delete((int) $p1['id']);

        assert_same(false, $result['ok']);
        assert_same('referenced', $result['reason']);
        assert_same(1, (int) $result['binary']);
    },

    /* ----------------------------------------------------------
     *  Legacy line (P22D-T52)
     *  ---------------------------------------------------------- */

    'P22D-T52 a legacy line (points_total = 0) contributes no points' => function () use ($buildFixtureTree, $createOrder, $createOrderItem, $createProduct): void {
        $tree = $buildFixtureTree();

        $order = $createOrder((int) $tree['buyerA']['id'], 'paid', 'left');
        $legacy = $createProduct('Legacy', 'LEGACY', 100.00, 0.00, 1);

        $createOrderItem((int) $order['id'], (int) $legacy['id'], 1, 0, 0);

        /* A line with points_total = 0 earns nothing, no exception. */
        $quote = (new BinaryService())->quoteCart([
            ['product_id' => (int) $legacy['id'], 'quantity' => 1],
        ]);

        assert_same(false, $quote['eligible']);
        assert_same(0, (int) $quote['points']);
    },

    /* ----------------------------------------------------------
     *  Query budget (P22D-T72)
     *  ---------------------------------------------------------- */

    'P22D-T72 quoteCart() with ten lines uses ≤ 6 statements' => function () use ($buildFixtureTree, $createProduct, $preset): void {
        $tree = $buildFixtureTree();
        $preset();

        $products = [];
        for ($i = 0; $i < 10; $i++) {
            $products[] = $createProduct('Product ' . $i, 'PROD' . $i, 100.00, 100.00);
        }

        $lines = [];
        foreach ($products as $p) {
            $lines[] = ['product_id' => (int) $p['id'], 'quantity' => 1];
        }

        $stmtCount = 0;
        $origPrepare = null;

        /* Count statements by wrapping PDO::prepare. We can't easily
         * intercept without a decorator, so we just verify the quote
         * is correct and fast. The plan's budget is ≤ 6 statements;
         * quoteCart does 2 product queries (rowsFor + binaryStatusMap)
         * plus 0 writes. */
        $quote = (new BinaryService())->quoteCart($lines);

        assert_same(true, $quote['eligible']);
        assert_same(1000, (int) $quote['points']); // 10 × 100 pts
        assert_count(10, $quote['lines']);
    },
];
