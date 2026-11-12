<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelEngineTest — Phase 17C (plan §7)
 *
 *  Proves the money engine on its own, with no page from 17D/17E:
 *
 *      awards      the ladder on a paid line, both payment paths,
 *                  the base rules, depth caps, two lines, two refs
 *      eligibility the company account, disabled/suspended members,
 *                  the monthly Active gate and no compression
 *      integrity   the four idempotency layers, the stamp, the unique
 *                  anchor, the product guard and the reconcile
 *      reversal    full and partial clawback, the disabled switch, an
 *                  unpaid cancellation
 *      previews    quoteLine reads ten levels and writes nothing
 *
 *  Fixture tree (plan §16.2, adapted to this database's seeded ids):
 *  every case creates its own buyer and sponsors, links them with
 *  `users.sponsor_id` and lets the chain end at NULL. The company
 *  account is always `Member::primaryAdmin()` (the seeded admin), so
 *  the plan's "id 1" rule holds without depending on an id.
 *
 *  Product P1: price ₱300 / P.V. ₱250 / base auto / 5·5·5·3·3·2·2·2·1·1.
 *  Product P2: price ₱500 / P.V. 0 / level 1 = 10%, levels 2–10 = 0%.
 *
 *  Every case runs inside the harness transaction and rolls back. The
 *  one exception is P17C-T32, which builds a scratch schema, commits a
 *  fixture there and runs the real CLI against it: a subprocess cannot
 *  see the harness's uncommitted transaction.
 *
 *  Run this group alone with:  php tests/run.php UnilevelEngine
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/* --------------------------------------------------------------
 *  Products
 * -------------------------------------------------------------- */

/** A repeat-purchase product through the same call the admin form uses. */
$makeProduct = static function (array $overrides = [], ?array $levels = null): int {
    return Product::save($overrides + [
        'name'              => 'P17C Product ' . bin2hex(random_bytes(3)),
        'sku'               => 'P17C-' . strtoupper(bin2hex(random_bytes(3))),
        'price'             => '300.00',
        'pv'                => '250.00',
        'unilevel_enabled'  => '1',
        'unilevel_base'     => 'auto',
        'stock'             => '100',
        'status'            => 'active',
    ], null, $levels ?? ProductUnilevel::referencePreset());
};

/** P1 — the reference product (price 300, P.V. 250, 29%). */
$p1 = static fn (array $overrides = []): int => $makeProduct($overrides);

/** P2 — price 500, no P.V., level 1 10% and nothing deeper. */
$p2 = static function () use ($makeProduct): int {
    $levels = array_fill(ProductUnilevel::LEVEL_MIN, ProductUnilevel::LEVEL_MAX, 0.0);
    $levels[1] = 10.0;

    return $makeProduct(['price' => '500.00', 'pv' => '0.00'], $levels);
};

/** A ladder with exactly one non-zero rate. */
$ladderWith = static function (int $level, float $rate): array {
    $levels = array_fill(ProductUnilevel::LEVEL_MIN, ProductUnilevel::LEVEL_MAX, 0.0);
    $levels[$level] = $rate;

    return $levels;
};

/* --------------------------------------------------------------
 *  Members and orders
 * -------------------------------------------------------------- */

/** One fresh member account. */
$makeUser = static function (string $prefix = 'p17c', string $role = 'member'): array {
    return test_user($prefix . '_' . bin2hex(random_bytes(4)), $role);
};

/**
 * A chain of `$depth` sponsors above a fresh buyer.
 *
 * `buyer → L1 → L2 … → Ldepth → NULL`
 *
 * @return array{buyer: array<string, mixed>, levels: array<int, array<string, mixed>>}
 */
$makeChain = static function (int $depth, string $prefix = 'p17c') use ($makeUser): array {
    $buyer  = $makeUser($prefix . '_buyer');
    $levels = [];
    $child  = $buyer;

    for ($level = 1; $level <= $depth; $level++) {
        $upline = $makeUser($prefix . '_u' . $level);

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $upline['id'], (int) $child['id']]);

        $levels[$level] = $upline;
        $child          = $upline;
    }

    return ['buyer' => $buyer, 'levels' => $levels];
};

/** Insert one priced line on a fixture order and return its id. */
$insertLine = static function (
    int $orderId,
    int $productId,
    int $quantity,
    float $unitPrice,
    ?float $unitBase,
    ?float $baseTotal,
    string $name = 'Fixture product'
): int {
    $lineTotal = round($unitPrice * $quantity, 2);

    db()->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total,
            unit_base, base_total, reservation_state)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 'committed')"
    )->execute([
        $orderId,
        $productId,
        $name,
        $quantity,
        number_format($unitPrice, 2, '.', ''),
        number_format($lineTotal, 2, '.', ''),
        $unitBase === null ? null : number_format($unitBase, 2, '.', ''),
        $baseTotal === null ? null : number_format($baseTotal, 2, '.', ''),
    ]);

    return (int) db()->lastInsertId();
};

/**
 * A paid order fixture with real prices and the base snapshot.
 *
 * Each line: `product`, `quantity`, `unit_price`, `unit_base`,
 * `base_total` (null for a legacy line), optionally `name`.
 *
 * @param list<array<string, mixed>> $lines
 */
$paidOrder = static function (int $buyerId, array $lines, string $paidAt) use ($insertLine): int {
    $total = 0.0;

    foreach ($lines as $line) {
        $total += round((float) $line['unit_price'] * (int) $line['quantity'], 2);
    }

    $orderId = test_order($buyerId, [], 'paid', [
        'paid_at'  => $paidAt,
        'subtotal' => number_format($total, 2, '.', ''),
        'total'    => number_format($total, 2, '.', ''),
    ]);

    foreach ($lines as $line) {
        $insertLine(
            $orderId,
            (int) $line['product'],
            (int) $line['quantity'],
            (float) $line['unit_price'],
            $line['unit_base'] === null ? null : (float) $line['unit_base'],
            $line['base_total'] === null ? null : (float) $line['base_total'],
            (string) ($line['name'] ?? 'Fixture product')
        );
    }

    return $orderId;
};

/* --------------------------------------------------------------
 *  The two real payment paths
 * -------------------------------------------------------------- */

$checkoutInput = static function (array $overrides = []): array {
    return $overrides + [
        'billing_name'      => 'Juan Dela Cruz',
        'billing_phone'     => '0917 000 0000',
        'shipping_name'     => 'Juan Dela Cruz',
        'shipping_phone'    => '0917 000 0000',
        'shipping_address'  => '12 Mabini Street',
        'shipping_barangay' => 'Poblacion',
        'shipping_city'     => 'Makati',
        'shipping_province' => 'Metro Manila',
        'shipping_postal'   => '1200',
        'notes_member'      => '',
        'payment_method'    => 'ewallet',
        'idempotency_key'   => bin2hex(random_bytes(16)),
        'terms'             => '1',
    ];
};

$fund = static function (int $userId, string $amount = '1000.00'): void {
    (new WalletService())->credit(
        $userId,
        $amount,
        'commission',
        random_int(1000000, 9999999),
        'Test funds'
    );
};

/**
 * Place a real order through the cart and checkout.
 *
 * @param list<array{product:int, quantity:int}> $lines
 */
$place = static function (int $userId, array $lines, string $method = 'ewallet') use ($checkoutInput): int {
    foreach ($lines as $line) {
        (new CartService())->add($userId, (int) $line['product'], (int) $line['quantity']);
    }

    return (new OrderService())->placeFromCart(
        $userId,
        $checkoutInput(['payment_method' => $method])
    )['order_id'];
};

/* --------------------------------------------------------------
 *  Assertions and small readers
 * -------------------------------------------------------------- */

$run = static fn (int $orderId): array => (new UnilevelService())->onOrderPaid($orderId);

$settings = static function (array $values): void {
    foreach ($values as $key => $value) {
        setting_set((string) $key, (string) $value);
    }
};

$enable = static function (int $maxLevels = 10, bool $requiresActive = true) use ($settings): void {
    $settings([
        'unilevel_enabled'                => 1,
        'unilevel_max_levels'             => $maxLevels,
        'unilevel_requires_active_upline' => $requiresActive ? 1 : 0,
        'unilevel_active_threshold'       => 500,
        'unilevel_reversal_enabled'       => 1,
    ]);
};

/** @return list<array<string, mixed>> */
$awards = static fn (int $orderId): array => UnilevelAward::forOrder($orderId);

/** The credited awards' amount, per level (1-based). @return array<int, float> */
$levelAmounts = static function (array $rows): array {
    $amounts = [];

    foreach ($rows as $row) {
        if ((string) $row['status'] === 'credited') {
            $amounts[(int) $row['level']] = round((float) $row['amount'], 2);
        }
    }

    ksort($amounts);

    return $amounts;
};

$sumAmounts = static function (array $rows): float {
    $sum = 0.0;

    foreach ($rows as $row) {
        if ((string) $row['status'] === 'credited') {
            $sum += (float) $row['amount'];
        }
    }

    return round($sum, 2);
};

$withdrawable = static fn (int $userId): float => (new WalletService())->withdrawable($userId);

$balance = static fn (int $userId): float => (new WalletService())->balance($userId);

$repeatLedger = static function (int $orderId = 0, string $type = 'unilevel'): array {
    $sql    = "SELECT * FROM ewallet_ledger WHERE ref_type = ?";
    $params = [$type];

    if ($orderId > 0) {
        $sql .= ' AND ref_id IN (SELECT id FROM unilevel_awards WHERE order_id = ?)';
        $params[] = $orderId;
    }

    $stmt = db()->prepare($sql . ' ORDER BY id ASC');
    $stmt->execute($params);

    return $stmt->fetchAll();
};

$questions = static function (string $name = 'Com_select'): int {
    $row = db()->query("SHOW SESSION STATUS LIKE '" . $name . "'")->fetch();

    return (int) ($row['Value'] ?? 0);
};

return [
    /* ==========================================================
     *  Awards (P17C-T1, T3 … T16)
     * ========================================================== */

    'P17C-T1 an e-wallet order pays the four-deep ladder and credits each wallet once' => function () use ($enable, $p1, $makeChain, $fund, $place, $run, $awards, $sumAmounts, $withdrawable, $repeatLedger): void {
        $enable();
        $product = $p1();
        $chain   = $makeChain(4, 'p17c_t1');
        $buyerId = (int) $chain['buyer']['id'];

        $fund($buyerId, '1000.00');

        $orderId = $place($buyerId, [['product' => $product, 'quantity' => 2]]);
        $order   = Order::find($orderId);

        assert_same('paid', $order['status'], 'the e-wallet path pays at checkout');
        assert_not_null($order['unilevel_processed_at'], 'the order is stamped');
        assert_same('90.00', number_format((float) $order['unilevel_total'], 2, '.', ''));

        $rows = $awards($orderId);
        assert_count(4, $rows, 'four chain hops pay four awards');

        $expected = [
            1 => [(int) $chain['levels'][1]['id'], 25.00],
            2 => [(int) $chain['levels'][2]['id'], 25.00],
            3 => [(int) $chain['levels'][3]['id'], 25.00],
            4 => [(int) $chain['levels'][4]['id'], 15.00],
        ];

        foreach ($rows as $row) {
            $level = (int) $row['level'];
            assert_same('credited', $row['status'], 'level ' . $level . ' is credited');
            assert_same($expected[$level][0], (int) $row['user_id'], 'level ' . $level . ' recipient');
            assert_same($expected[$level][1], round((float) $row['amount'], 2), 'level ' . $level . ' amount');
            assert_same('500.00', number_format((float) $row['base_amount'], 2, '.', ''), 'the line base');
            assert_same('line', $row['base_source'], 'the base came from the placement snapshot');
            assert_not_null($row['ledger_id']);
        }

        assert_same(90.0, $sumAmounts($rows));

        foreach ($expected as $level => [$userId, $amount]) {
            assert_same($amount, $withdrawable($userId), 'the level-' . $level . ' wallet is withdrawable');
        }

        $ledger = $repeatLedger($orderId);
        assert_count(4, $ledger, 'one unilevel ledger row per award');

        foreach ($ledger as $movement) {
            assert_same('credit', $movement['type']);
            assert_same('unilevel', $movement['ref_type']);
        }
    },

    'P17C-T3 a ten-deep chain pays all ten levels (₱145)' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $levelAmounts): void {
        $enable();
        $chain  = $makeChain(10, 'p17c_t3');
        $order  = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(10, $rows);
        assert_same(145.0, $sumAmounts($rows));
        assert_same(
            [1 => 25.0, 25.0, 25.0, 15.0, 15.0, 10.0, 10.0, 10.0, 5.0, 5.0],
            $levelAmounts($rows)
        );
    },

    'P17C-T4 a ₱555 base rounds per level to ₱160.95' => function () use ($enable, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $levelAmounts): void {
        $enable();
        $product = Product::save([
            'name' => 'P17C 555', 'sku' => 'P17C-555-' . bin2hex(random_bytes(3)),
            'price' => '555.00', 'pv' => '0.00', 'unilevel_enabled' => '1',
            'unilevel_base' => 'auto', 'stock' => '100', 'status' => 'active',
        ], null, ProductUnilevel::referencePreset());

        $chain = $makeChain(10, 'p17c_t4');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $product, 'quantity' => 1, 'unit_price' => 555.00,
            'unit_base' => 555.00, 'base_total' => 555.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(10, $rows);
        assert_same(160.95, $sumAmounts($rows));
        assert_same(
            [1 => 27.75, 27.75, 27.75, 16.65, 16.65, 11.10, 11.10, 11.10, 5.55, 5.55],
            $levelAmounts($rows)
        );
    },

    'P17C-T5 a ₱555.55 base pinpoints the per-level rounding drift (₱161.13)' => function () use ($enable, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $levelAmounts): void {
        $enable();
        $product = Product::save([
            'name' => 'P17C 555.55', 'sku' => 'P17C-55555-' . bin2hex(random_bytes(3)),
            'price' => '555.55', 'pv' => '0.00', 'unilevel_enabled' => '1',
            'unilevel_base' => 'auto', 'stock' => '100', 'status' => 'active',
        ], null, ProductUnilevel::referencePreset());

        $chain = $makeChain(10, 'p17c_t5');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $product, 'quantity' => 1, 'unit_price' => 555.55,
            'unit_base' => 555.55, 'base_total' => 555.55,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(10, $rows);
        assert_same(161.13, $sumAmounts($rows), 'the rounded levels total ₱161.13, not the nominal ₱161.11');
        assert_same(
            [1 => 27.78, 27.78, 27.78, 16.67, 16.67, 11.11, 11.11, 11.11, 5.56, 5.56],
            $levelAmounts($rows)
        );
    },

    'P17C-T6 base=price pays on ₱600 and not on the P.V.' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $levelAmounts): void {
        $enable();
        $product = $p1(['unilevel_base' => 'price']);
        $chain   = $makeChain(4, 'p17c_t6');
        $order   = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $product, 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 300.00, 'base_total' => 600.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_same(600.0, (float) $rows[0]['base_amount']);
        assert_same(108.0, $sumAmounts($rows), '30 + 30 + 30 + 18');
        assert_same([1 => 30.0, 2 => 30.0, 3 => 30.0, 4 => 18.0], $levelAmounts($rows));
    },

    'P17C-T7 a legacy line without a snapshot falls back to the product base' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable();
        $chain = $makeChain(4, 'p17c_t7');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => null, 'base_total' => null,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(4, $rows);
        assert_same('500.00', number_format((float) $rows[0]['base_amount'], 2, '.', ''), 'P.V. ₱250 × 2');
        assert_same('product', $rows[0]['base_source']);
        assert_same(90.0, $sumAmounts($rows));
    },

    'P17C-T8 the company account is a skipped row and never paid' => function () use ($enable, $p1, $makeUser, $paidOrder, $run, $awards, $repeatLedger): void {
        $enable();
        $company = Member::primaryAdmin();
        $buyer   = $makeUser('p17c_t8_buyer');

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $company['id'], (int) $buyer['id']]);

        $order = $paidOrder((int) $buyer['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $summary = $run($order);
        $rows    = $awards($order);

        assert_count(1, $rows);
        assert_same('skipped', $rows[0]['status']);
        assert_same('company account is not paid', $rows[0]['reason']);
        assert_same(25.0, (float) $rows[0]['amount'], 'the row records what was due');
        assert_same(0.0, $summary['credited']);
        assert_same(1, $summary['skipped']);
        assert_count(0, $repeatLedger($order), 'a skipped row moves no money');
        assert_same('0.00', number_format((float) Order::find($order)['unilevel_total'], 2, '.', ''));
    },

    'P17C-T9 a buyer with no sponsor gets no rows and a zero stamp' => function () use ($enable, $p1, $makeUser, $paidOrder, $run, $awards): void {
        $enable();
        $buyer = $makeUser('p17c_t9_buyer');
        $order = $paidOrder((int) $buyer['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $summary = $run($order);

        assert_count(0, $awards($order));
        assert_true($summary['processed']);
        assert_same(0.0, $summary['credited']);
        assert_not_null(Order::find($order)['unilevel_processed_at']);
        assert_same('0.00', number_format((float) Order::find($order)['unilevel_total'], 2, '.', ''));
    },

    'P17C-T10 max levels 3 pays three levels (₱75)' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable(3);
        $chain = $makeChain(4, 'p17c_t10');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(3, $rows);
        assert_same(75.0, $sumAmounts($rows));
    },

    'P17C-T11 max levels 2 pays two levels (₱50)' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable(2);
        $chain = $makeChain(4, 'p17c_t11');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(2, $rows);
        assert_same(50.0, $sumAmounts($rows));
    },

    'P17C-T12 max levels 1 pays only the direct sponsor (₱25)' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable(1);
        $chain = $makeChain(4, 'p17c_t12');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(1, $rows);
        assert_same(1, (int) $rows[0]['level']);
        assert_same(25.0, $sumAmounts($rows));
    },

    'P17C-T13 an eleven-deep chain pays levels 1–10 only' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable();
        $chain   = $makeChain(11, 'p17c_t13');
        $eleventh = (int) $chain['levels'][11]['id'];
        $order   = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(10, $rows);
        assert_same(145.0, $sumAmounts($rows));

        foreach ($rows as $row) {
            assert_not_same($eleventh, (int) $row['user_id'], 'the eleventh sponsor is never paid');
        }
    },

    'P17C-T14 two products on one order pay two independent ladders (₱140)' => function () use ($enable, $p1, $p2, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable();
        $chain = $makeChain(4, 'p17c_t14');
        $order = $paidOrder((int) $chain['buyer']['id'], [
            ['product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00, 'unit_base' => 250.00, 'base_total' => 500.00],
            ['product' => $p2(), 'quantity' => 1, 'unit_price' => 500.00, 'unit_base' => 500.00, 'base_total' => 500.00],
        ], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(5, $rows, 'four Coffee awards plus one Booster award');
        assert_same(140.0, $sumAmounts($rows));

        $levelOne = (int) $chain['levels'][1]['id'];
        $direct   = 0.0;

        foreach ($rows as $row) {
            if ((int) $row['user_id'] === $levelOne && (string) $row['status'] === 'credited') {
                $direct += (float) $row['amount'];
            }
        }

        assert_same(75.0, round($direct, 2), 'the level-1 upline earns ₱25 on each line');
    },

    'P17C-T15 three lines on a ten-deep chain stay batched (no N+1)' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $questions): void {
        $enable();
        $chain   = $makeChain(10, 'p17c_t15');
        $product = $p1();
        $line    = ['product' => $product, 'quantity' => 2, 'unit_price' => 300.00, 'unit_base' => 250.00, 'base_total' => 500.00];
        $order   = $paidOrder((int) $chain['buyer']['id'], [$line, $line, $line], date('Y-m-d H:i:s'));

        $before = $questions();
        $run($order);
        $after = $questions();

        $rows = $awards($order);

        assert_count(30, $rows, 'three lines × ten levels');
        assert_same(435.0, $sumAmounts($rows));

        /* The chain is walked once (10 hops × 2 reads = 20 selects), not
         * once per line. The budget also covers one wallet idempotency
         * read per credited award; a per-line walk would add 40 more. */
        $selects = $after - $before;
        assert_true($selects <= 160, 'the three-line order used ' . $selects . ' selects (expected ≤ 160)');
    },

    'P17C-T16 the buyer is never a recipient and owns no award' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $balance): void {
        $enable();
        $chain   = $makeChain(4, 'p17c_t16');
        $buyerId = (int) $chain['buyer']['id'];
        $order   = $paidOrder($buyerId, [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);

        foreach ($awards($order) as $row) {
            assert_not_same($buyerId, (int) $row['user_id'], 'D-09: the buyer earns nothing');
            assert_same($buyerId, (int) $row['buyer_id']);
        }

        assert_same(0.0, $balance($buyerId), 'the buyer wallet is untouched by a direct hook');
    },

    /* ==========================================================
     *  Eligibility and switches (T17 … T27)
     * ========================================================== */

    'P17C-T17 an inactive level 2 is skipped without compression' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $withdrawable): void {
        $enable();
        $chain = $makeChain(4, 'p17c_t17');
        $b     = (int) $chain['levels'][2]['id'];

        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$b]);

        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $summary = $run($order);
        $rows    = $awards($order);

        assert_count(4, $rows, 'the skipped level keeps its row');
        assert_same('skipped', $rows[1]['status']);
        assert_same('recipient account is disabled', $rows[1]['reason']);
        assert_same(3, (int) $rows[2]['level'], 'a is still level 3');
        assert_same(65.0, $sumAmounts($rows));
        assert_same(1, $summary['skipped']);
        assert_same(0.0, $withdrawable($b), 'the skipped upline is not paid');
        assert_same(25.0, $withdrawable((int) $chain['levels'][3]['id']), 'a pays at level 3, not level 2');
    },

    'P17C-T18 a disabled level 1 is skipped and the walk continues' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable();
        $chain = $makeChain(4, 'p17c_t18');
        $c     = (int) $chain['levels'][1]['id'];

        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$c]);

        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_same('skipped', $rows[0]['status']);
        assert_same('recipient account is disabled', $rows[0]['reason']);
        assert_same(65.0, $sumAmounts($rows), 'levels 2–4 still pay');
    },

    'P17C-T19 a suspended level 1 writes the membership reason' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable();
        $chain = $makeChain(4, 'p17c_t19');

        db()->prepare("UPDATE users SET member_status = 'suspended' WHERE id = ?")
            ->execute([(int) $chain['levels'][1]['id']]);

        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_same('skipped', $rows[0]['status']);
        assert_same('recipient membership is suspended', $rows[0]['reason']);
        assert_same(65.0, $sumAmounts($rows));
    },

    'P17C-T20 requires_active_upline=0 pays a monthly-inactive level 1' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $insertLine, $settings): void {
        $enable(10, true);
        $chain = $makeChain(4, 'p17c_t20');
        $c     = (int) $chain['levels'][1]['id'];

        /* c bought ₱450 in October: below the ₱500 threshold. */
        $october = test_order($c, [], 'paid', [
            'paid_at' => '2026-10-15 10:00:00', 'subtotal' => '450.00', 'total' => '450.00',
        ]);
        $insertLine($october, $p1(), 1, 450.00, 450.00, 450.00);

        UnilevelCycle::close('2026-10');

        /* The October snapshot is authoritative: c is not Active for
         * November while the monthly rule is on. */
        assert_false(UnilevelCycle::isActive($c, '2026-11'));

        $settings(['unilevel_requires_active_upline' => 0]);

        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], '2026-11-05 10:00:00');

        $run($order);
        $rows = $awards($order);

        assert_same('credited', $rows[0]['status'], 'the monthly gate is relaxed, the other checks still apply');
        assert_same(90.0, $sumAmounts($rows));
    },

    'P17C-T21 a staff account as an upline is paid like any other member' => function () use ($enable, $p1, $makeUser, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable();
        $buyer = $makeUser('p17c_t21_buyer');
        $staff = $makeUser('p17c_t21_staff', 'admin');
        $a     = $makeUser('p17c_t21_u2');
        $b     = $makeUser('p17c_t21_u3');

        assert_not_same((int) Member::primaryAdmin()['id'], (int) $staff['id'], 'this staff member is not the company account');

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $staff['id'], (int) $buyer['id']]);
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $a['id'], (int) $staff['id']]);
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $b['id'], (int) $a['id']]);

        $order = $paidOrder((int) $buyer['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(3, $rows);
        assert_same('credited', $rows[0]['status']);
        assert_same((int) $staff['id'], (int) $rows[0]['user_id']);
        assert_same(75.0, $sumAmounts($rows));
    },

    'P17C-T22 a self-sponsor cycle stops the walk with no duplicate recipient' => function () use ($enable, $p1, $makeUser, $paidOrder, $run, $awards): void {
        $enable();
        $buyer = $makeUser('p17c_t22_buyer');
        $c     = $makeUser('p17c_t22_c');

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $c['id'], (int) $buyer['id']]);
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $c['id'], (int) $c['id']]);

        $order = $paidOrder((int) $buyer['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(1, $rows, 'c is paid once and the cycle stops the walk');
        assert_same((int) $c['id'], (int) $rows[0]['user_id']);
        assert_same(25.0, (float) $rows[0]['amount']);
    },

    'P17C-T23 a zero rate at level 1 consumes the level with no row' => function () use ($enable, $makeProduct, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $levelAmounts): void {
        $enable();
        $reference = ProductUnilevel::referencePreset();
        $reference[1] = 0.0;
        $product = $makeProduct([], $reference);
        $chain   = $makeChain(4, 'p17c_t23');
        $order   = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $product, 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(3, $rows, 'no level-1 row');
        assert_same([2 => 25.0, 3 => 25.0, 4 => 15.0], $levelAmounts($rows));
        assert_same(65.0, $sumAmounts($rows));
    },

    'P17C-T24 a zero rate at level 2 still pays level 3 at its own number' => function () use ($enable, $makeProduct, $makeChain, $paidOrder, $run, $awards, $sumAmounts, $levelAmounts): void {
        $enable();
        $reference = ProductUnilevel::referencePreset();
        $reference[2] = 0.0;
        $product = $makeProduct([], $reference);
        $chain   = $makeChain(4, 'p17c_t24');
        $order   = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $product, 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(3, $rows);
        assert_same([1 => 25.0, 3 => 25.0, 4 => 15.0], $levelAmounts($rows));
        assert_same(65.0, $sumAmounts($rows));
    },

    'P17C-T25 the global master off writes no award but stamps the order' => function () use ($p1, $makeChain, $paidOrder, $run, $awards): void {
        setting_set('unilevel_enabled', '0');

        $chain = $makeChain(4, 'p17c_t25');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $summary = $run($order);

        assert_count(0, $awards($order));
        assert_true($summary['processed']);
        assert_same('disabled', $summary['reason']);
        assert_not_null(Order::find($order)['unilevel_processed_at'], 'the decision is auditable');
        assert_same('0.00', number_format((float) Order::find($order)['unilevel_total'], 2, '.', ''));
    },

    'P17C-T26 the product master off writes no award but stamps the order' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards): void {
        $enable();
        $product = $p1(['unilevel_enabled' => '0']);

        $chain = $makeChain(4, 'p17c_t26');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $product, 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $summary = $run($order);

        assert_count(0, $awards($order));
        assert_true($summary['processed']);
        assert_not_null(Order::find($order)['unilevel_processed_at']);
        assert_same('0.00', number_format((float) Order::find($order)['unilevel_total'], 2, '.', ''));
    },

    'P17C-T27 both masters on pays the ladder (control for T25/T26)' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable();

        $chain = $makeChain(4, 'p17c_t27');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(4, $rows);
        assert_same(90.0, $sumAmounts($rows));
    },

    /* ==========================================================
     *  Integrity (T28 … T35)
     * ========================================================== */

    'P17C-T28 a second onOrderPaid is a replay and moves no money' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards, $withdrawable): void {
        $enable();
        $chain = $makeChain(4, 'p17c_t28');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $first  = $run($order);
        $before = $withdrawable((int) $chain['levels'][1]['id']);
        $count  = count($awards($order));

        $second = $run($order);

        assert_true($first['processed']);
        assert_same(false, $second['processed']);
        assert_same(true, $second['replayed']);
        assert_same($count, count($awards($order)), 'no second row');
        assert_same($before, $withdrawable((int) $chain['levels'][1]['id']), 'no second credit');
    },

    'P17C-T29 the unique key refuses a duplicate (line, recipient, level) award' => function () use ($enable, $p1, $makeChain, $paidOrder, $run, $awards): void {
        $enable();
        $chain = $makeChain(4, 'p17c_t28b');
        $product = $p1();
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $product, 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $row = $awards($order)[0];

        $error = assert_throws(
            static fn (): int => UnilevelAward::create([
                'order_id'      => $order,
                'order_item_id' => (int) $row['order_item_id'],
                'product_id'    => (int) $row['product_id'],
                'buyer_id'      => (int) $row['buyer_id'],
                'user_id'       => (int) $row['user_id'],
                'level'         => (int) $row['level'],
                'base_amount'   => 500.00,
                'rate_pct'      => 5.000,
                'amount'        => 999.00,
                'paid_at'       => date('Y-m-d H:i:s'),
            ]),
            PDOException::class,
            'the money anchor must hold'
        );

        assert_same('23000', (string) $error->getCode());
    },

    'P17C-T30 an unpaid order is never stamped and pays nothing' => function () use ($enable, $p1, $makeUser, $insertLine, $run, $awards): void {
        $enable();
        $buyer = $makeUser('p17c_t30_buyer');

        foreach (['pending', 'payment_review'] as $status) {
            $order = test_order((int) $buyer['id'], [], $status);
            $insertLine($order, $p1(), 2, 300.00, 250.00, 500.00);

            $summary = $run($order);

            assert_same(false, $summary['processed'], $status . ' is not the money event');
            assert_same('not paid', $summary['reason']);
            assert_count(0, $awards($order));
            assert_null(Order::find($order)['unilevel_processed_at'], $status . ' must stay retryable');
        }
    },

    'P17C-T31 the reconcile is clean after real awards' => function () use ($enable, $p1, $makeChain, $paidOrder, $run): void {
        $enable();
        $chain = $makeChain(4, 'p17c_t31');
        $order = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);

        $reconcile = (new UnilevelService())->reconcile();

        assert_true($reconcile['ok'], json_encode($reconcile['mismatches']));
        assert_same(4, $reconcile['credited_rows']);
        assert_same(90.0, $reconcile['credited_total']);
        assert_same(90.0, $reconcile['ledger_total']);
        assert_same([], $reconcile['mismatches']);
    },

    'P17C-T32 a deleted ledger row fails the reconcile and the CLI exit code' => function () use ($appRoot): void {
        /* A subprocess cannot see this case's transaction, so build a
         * real committed fixture on a scratch schema and run the real
         * command against it. */
        [$pdo, $database] = test_scratch_database('tool_a');

        test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

        $pdo->exec(
            "INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active,
                                ewallet_balance, withdrawable_balance)
             VALUES ('p17c_recipient', 'recipient@test.local', 'x', 'Recipient', 'member', 'active', 1, 25.00, 25.00),
                    ('p17c_buyer', 'buyer@test.local', 'x', 'Buyer', 'member', 'active', 1, 0.00, 0.00)"
        );
        $recipientId = (int) $pdo->query("SELECT id FROM users WHERE username = 'p17c_recipient'")->fetchColumn();
        $buyerId     = (int) $pdo->query("SELECT id FROM users WHERE username = 'p17c_buyer'")->fetchColumn();

        $pdo->exec(
            "INSERT INTO products (sku, name, slug, price, pv, unilevel_enabled, stock)
             VALUES ('P17C-CLI', 'CLI product', 'p17c-cli-product', 100.00, 100.00, 1, 10)"
        );
        $productId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, subtotal, total, billing_name,
                shipping_name, shipping_address, paid_at, unilevel_processed_at, unilevel_total,
                terms_accepted_at)
             VALUES ('EWT-CLI-1', {$buyerId}, 'paid', 'ewallet', 100.00, 100.00, 'Buyer',
                     'Buyer', 'Address', NOW(), NOW(), 25.00, NOW())"
        );
        $orderId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total,
                unit_base, base_total, reservation_state)
             VALUES ({$orderId}, {$productId}, 'CLI product', 1, 100.00, 100.00, 100.00, 100.00, 'committed')"
        );
        $itemId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO unilevel_awards
               (order_id, order_item_id, product_id, buyer_id, user_id, level,
                base_amount, base_source, rate_pct, amount, status, paid_at)
             VALUES ({$orderId}, {$itemId}, {$productId}, {$buyerId}, {$recipientId}, 1,
                     100.00, 'line', 25.000, 25.00, 'credited', NOW())"
        );
        $awardId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO ewallet_ledger
               (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
             VALUES ({$recipientId}, 'credit', 25.00, 25.00, 25.00, 'unilevel', {$awardId}, 'CLI fixture')"
        );
        $ledgerId = (int) $pdo->lastInsertId();

        $pdo->exec('UPDATE unilevel_awards SET ledger_id = ' . $ledgerId . ' WHERE id = ' . $awardId);

        $run = static function (string $database) use ($appRoot): array {
            $previous = getenv('ECO_DB_NAME');
            putenv('ECO_DB_NAME=' . $database);

            try {
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($appRoot . '/bin/wallet_reconcile.php') . ' --json';

                exec($command, $lines, $code);
            } finally {
                if ($previous === false) {
                    putenv('ECO_DB_NAME');
                } else {
                    putenv('ECO_DB_NAME=' . $previous);
                }
            }

            $output = implode("\n", $lines);
            $json   = json_decode($output, true);

            return ['exit' => $code, 'json' => is_array($json) ? $json : null, 'output' => $output];
        };

        $clean = $run($database);

        assert_same(0, $clean['exit'], 'a clean repeat-purchase fixture exits 0: ' . $clean['output']);
        assert_not_null($clean['json']);
        assert_same(true, $clean['json']['ok']);
        assert_same(true, $clean['json']['unilevel']['ok']);
        assert_same(25.0, (float) $clean['json']['unilevel']['ledger_total']);

        $pdo->exec('DELETE FROM ewallet_ledger WHERE id = ' . $ledgerId);

        $broken = $run($database);

        assert_same(1, $broken['exit'], 'a missing ledger row exits 1');
        assert_not_null($broken['json']);
        assert_same(false, $broken['json']['ok']);
        assert_same(false, $broken['json']['unilevel']['ok']);

        $kinds = array_column($broken['json']['unilevel']['mismatches'], 'kind');
        assert_true(in_array('missing_ledger', $kinds, true), 'the missing movement is reported');
    },

    'P17C-T33 a product with an award cannot be deleted' => function () use ($enable, $p1, $makeChain, $paidOrder, $run): void {
        $enable();
        $product = $p1();
        $chain   = $makeChain(4, 'p17c_t33');
        $order   = $paidOrder((int) $chain['buyer']['id'], [[
            'product' => $product, 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);

        $refs = Product::referencesMap([$product])[$product];
        assert_true($refs['awards'] > 0, 'the award count is visible to the delete guard');

        $result = Product::delete($product);

        assert_same(false, $result['ok']);
        assert_same('referenced', $result['reason']);
        assert_true($result['awards'] > 0);
        assert_not_null(Product::find($product), 'the product is still there');
    },

    'P17C-T35 quoteLine reads ten levels and writes nothing' => function () use ($enable, $p1): void {
        $enable();
        $product = $p1();

        $before = [
            (int) db()->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
        ];

        $quote = (new UnilevelService())->quoteLine($product, 2);

        assert_count(10, $quote['levels']);
        assert_same(500.0, $quote['base']);
        assert_same('pv', $quote['base_source']);
        assert_same(145.0, $quote['total']);
        assert_same([25.0, 25.0, 25.0, 15.0, 15.0, 10.0, 10.0, 10.0, 5.0, 5.0], array_column($quote['levels'], 'amount'));
        assert_same('Your sponsor', $quote['levels'][0]['recipient']);

        $after = [
            (int) db()->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
        ];

        assert_same($before, $after, 'the preview writes nothing');
    },

    /* ==========================================================
     *  Reversal (T36 … T39)
     * ========================================================== */

    'P17C-T36 cancelling a paid order voids every award and debits the wallets' => function () use ($enable, $p1, $makeChain, $fund, $place, $run, $awards, $withdrawable, $balance, $repeatLedger): void {
        $enable();
        $chain   = $makeChain(4, 'p17c_t36');
        $buyerId = (int) $chain['buyer']['id'];

        $fund($buyerId, '1000.00');

        $orderId = $place($buyerId, [['product' => $p1(), 'quantity' => 2]]);
        assert_same(90.0, (float) Order::find($orderId)['unilevel_total']);

        $adminId = (int) Member::primaryAdmin()['id'];
        (new PaymentService())->cancelByAdmin($adminId, $orderId, 'admin_cancel');

        $order = Order::find($orderId);
        assert_same('cancelled', $order['status']);
        assert_same('0.00', number_format((float) $order['unilevel_total'], 2, '.', ''));

        foreach ($awards($orderId) as $row) {
            assert_same('voided', $row['status']);
            assert_same((float) $row['amount'], (float) $row['reversed_amount']);
            assert_not_null($row['reversal_ledger_id']);
            assert_not_null($row['voided_at']);
        }

        foreach ($chain['levels'] as $upline) {
            assert_same(0.0, $withdrawable((int) $upline['id']), 'the wallet is debited back to zero');
        }

        assert_same(1000.0, $balance($buyerId), 'the buyer is refunded the order total');

        $reversals = $repeatLedger($orderId, 'unilevel_reversal');
        assert_count(4, $reversals);

        $reconcile = (new UnilevelService())->reconcile();
        assert_true($reconcile['ok'], json_encode($reconcile['mismatches']));
        assert_same(0.0, $reconcile['credited_total']);
        assert_same(90.0, $reconcile['ever_credited_total']);
        assert_same(90.0, $reconcile['reversal_total']);
    },

    'P17C-T37 a partly spent wallet reverses only what it still holds' => function () use ($enable, $p1, $makeChain, $makeUser, $fund, $place, $run, $awards, $withdrawable, $balance): void {
        $enable();
        $chain   = $makeChain(4, 'p17c_t37');
        $buyerId = (int) $chain['buyer']['id'];
        $cId     = (int) $chain['levels'][1]['id'];

        $fund($buyerId, '1000.00');
        $orderId = $place($buyerId, [['product' => $p1(), 'quantity' => 2]]);

        assert_same(25.0, $withdrawable($cId));

        /* c spends ₱10 of the award on a real e-wallet order. */
        $tiny = Product::save([
            'name' => 'P17C Tiny ' . bin2hex(random_bytes(3)),
            'sku'  => 'P17C-TINY-' . strtoupper(bin2hex(random_bytes(3))),
            'price' => '10.00', 'stock' => '10', 'status' => 'active',
        ]);

        $place($cId, [['product' => $tiny, 'quantity' => 1]]);
        assert_same(15.0, $withdrawable($cId));

        $adminId = (int) Member::primaryAdmin()['id'];
        (new PaymentService())->cancelByAdmin($adminId, $orderId, 'admin_cancel');

        $cAward = null;

        foreach ($awards($orderId) as $row) {
            if ((int) $row['user_id'] === $cId) {
                $cAward = $row;
            }
        }

        assert_not_null($cAward);
        assert_same('voided', $cAward['status']);
        assert_same(15.0, (float) $cAward['reversed_amount'], 'only the withdrawable balance came back');
        assert_same(10.0, round((float) $cAward['amount'] - (float) $cAward['reversed_amount'], 2), 'the shortfall is recorded');
        assert_same(0.0, $withdrawable($cId));
        assert_same(0.0, $balance($cId), 'no negative balance');
    },

    'P17C-T38 the reversal switch off leaves the awards credited' => function () use ($enable, $p1, $makeChain, $fund, $place, $run, $awards, $settings, $withdrawable, $repeatLedger): void {
        $enable();
        $chain   = $makeChain(4, 'p17c_t38');
        $buyerId = (int) $chain['buyer']['id'];

        $fund($buyerId, '1000.00');
        $orderId = $place($buyerId, [['product' => $p1(), 'quantity' => 2]]);

        $settings(['unilevel_reversal_enabled' => 0]);

        (new PaymentService())->cancelByAdmin((int) Member::primaryAdmin()['id'], $orderId, 'admin_cancel');

        foreach ($awards($orderId) as $row) {
            assert_same('credited', $row['status']);
            assert_same(0.0, (float) $row['reversed_amount']);
        }

        assert_same(25.0, $withdrawable((int) $chain['levels'][1]['id']), 'no debit');
        assert_count(0, $repeatLedger($orderId, 'unilevel_reversal'));
        assert_same(90.0, (float) Order::find($orderId)['unilevel_total'], 'the order total is untouched');
    },

    'P17C-T39 cancelling an unpaid order has nothing to reverse' => function () use ($enable, $p1, $makeChain, $place, $awards, $repeatLedger): void {
        $enable();
        $chain   = $makeChain(4, 'p17c_t39');
        $buyerId = (int) $chain['buyer']['id'];

        $orderId = $place($buyerId, [['product' => $p1(), 'quantity' => 2]], 'gcash');

        assert_same('pending', Order::find($orderId)['status']);

        (new OrderService())->cancelByMember($buyerId, $orderId, 'Changed my mind');

        assert_same('cancelled', Order::find($orderId)['status']);
        assert_count(0, $awards($orderId));

        $reverse = (new UnilevelService())->reverseForOrder($orderId, 'test');
        assert_same(0, $reverse['voided']);
        assert_count(0, $repeatLedger($orderId, 'unilevel_reversal'));
    },

    /* ==========================================================
     *  The buyer as a staff account (T51)
     * ========================================================== */

    'P17C-T51 a staff buyer earns nothing and the chain above is paid' => function () use ($enable, $p1, $makeUser, $paidOrder, $run, $awards, $sumAmounts): void {
        $enable();
        $buyer = $makeUser('p17c_t51_buyer', 'admin');
        $l1    = $makeUser('p17c_t51_l1');
        $l2    = $makeUser('p17c_t51_l2');

        assert_not_same((int) Member::primaryAdmin()['id'], (int) $buyer['id'], 'not the company account');

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $l1['id'], (int) $buyer['id']]);
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $l2['id'], (int) $l1['id']]);

        $order = $paidOrder((int) $buyer['id'], [[
            'product' => $p1(), 'quantity' => 2, 'unit_price' => 300.00,
            'unit_base' => 250.00, 'base_total' => 500.00,
        ]], date('Y-m-d H:i:s'));

        $run($order);
        $rows = $awards($order);

        assert_count(2, $rows);
        assert_same(50.0, $sumAmounts($rows));

        foreach ($rows as $row) {
            assert_not_same((int) $buyer['id'], (int) $row['user_id']);
        }
    },
];
