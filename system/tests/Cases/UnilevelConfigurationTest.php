<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelConfigurationTest — Phase 17B (plan §6)
 *
 *  Proves the configuration phase on its own: the per-product ladder
 *  model, the P.V. and base rules, the atomic save and its audit
 *  entry, the product form, the list badge and filter, and the
 *  repeat-purchase block on /admin/settings.
 *
 *      ladder      ten rows, zero-filled, blanks = 0.000, presets
 *      rules       per-level bounds, three decimals, sum <= 100,
 *                  switch-off-or-a-rate, P.V. base needs a P.V.
 *      atomic      a refused ladder writes nothing; a failed product
 *                  write leaves no ladder behind
 *      audit       one `product.unilevel.update` with before/after,
 *                  only when something changed
 *      reads       levelsMap / totalsMap / rowsFor, baseFor, no N+1
 *      views       the card (no direct/indirect control), the badge,
 *                  the ?unilevel= filter, the settings sub-block
 *      inert       a paid order still writes no award row (17C pays)
 *
 *  Nothing in this file moves money. The POST actions of the product
 *  form cannot run in-process (they end in redirect()), so the cases
 *  drive the same services the controller calls — the smoke suite
 *  covers the HTTP round trip.
 *
 *  Run this group alone with:  php tests/run.php UnilevelConfiguration
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

/** A product row shaped like the catalogue table, for view tests. */
$productRow = static fn (array $overrides = []): array => $overrides + [
    'id'                => 1,
    'sku'               => 'EW-COFFEE',
    'name'              => 'Coffee',
    'slug'              => 'coffee',
    'price'             => '300.00',
    'pv'                => '250.00',
    'stock'             => 50,
    'image_url'         => null,
    'short_description' => 'Roasted coffee blend.',
    'description'       => 'Longer copy.',
    'status'            => 'active',
    'sort_order'        => 10,
    'unilevel_enabled'  => 0,
    'unilevel_base'     => 'auto',
    'created_at'        => '2026-11-12 10:00:00',
    'updated_at'        => '2026-11-12 10:00:00',
];

/**
 * Create a product through the same call the controller makes.
 *
 * @param array<string, mixed>      $overrides
 * @param array<int, float|string>|null $levels
 */
$makeProduct = static function (array $overrides = [], ?array $levels = null, ?array $actor = null): int {
    return Product::save($overrides + [
        'name'              => 'P17B Product ' . bin2hex(random_bytes(3)),
        'sku'               => 'P17B-' . strtoupper(bin2hex(random_bytes(3))),
        'price'             => '300.00',
        'stock'             => '10',
        'status'            => 'active',
    ], null, $levels, $actor);
};

/** The ladder rows exactly as stored (level => percent string). */
$storedRows = static function (int $productId): array {
    $stmt = db()->prepare('SELECT level, percent FROM product_unilevel_levels WHERE product_id = ? ORDER BY level');
    $stmt->execute([$productId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(int) $row['level']] = (string) $row['percent'];
    }

    return $rows;
};

/** The audit entries for the repeat-purchase action, oldest first. */
$auditRows = static function (int $afterId = 0): array {
    $stmt = db()->prepare(
        "SELECT * FROM audit_log WHERE action = 'product.unilevel.update' AND id > ? ORDER BY id ASC"
    );
    $stmt->execute([$afterId]);

    return $stmt->fetchAll();
};

/** Every audit entry written after a marker, newest last. */
$allAudit = static function (int $afterId = 0): array {
    $stmt = db()->prepare('SELECT * FROM audit_log WHERE id > ? ORDER BY id ASC');
    $stmt->execute([$afterId]);

    return $stmt->fetchAll();
};

/** The highest audit id right now (a marker for "what this case wrote"). */
$auditMark = static function (): int {
    return (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
};

/** How many prepared statements the server has executed on this session. */
$statements = static function (): int {
    $row = db()->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch();

    return (int) ($row['Value'] ?? 0);
};

/**
 * The statement cost of one callable.
 *
 * The probe is itself one statement (PDO::query() runs prepare + execute
 * when emulation is off), so a constant overhead is included on purpose:
 * the cases compare two costs against each other rather than against 1.
 */
$measureStatements = static function (callable $work) use ($statements): int {
    $before = $statements();
    $work();

    return $statements() - $before;
};

return [
    /* ==========================================================
     *  The ladder model (P17B-T1, T2, T11)
     * ========================================================== */

    'P17B-T11 referencePreset is the 29% reference ladder' => function (): void {
        $preset = ProductUnilevel::referencePreset();

        assert_same(
            [1 => 5.0, 5.0, 5.0, 3.0, 3.0, 2.0, 2.0, 2.0, 1.0, 1.0],
            $preset,
            'levels 1–10 are 5/5/5/3/3/2/2/2/1/1'
        );
        assert_same(29.0, ProductUnilevel::total($preset));
        assert_same(10, ProductUnilevel::LEVEL_MAX - ProductUnilevel::LEVEL_MIN + 1, 'ten levels, not nine');
        assert_same(0.0, ProductUnilevel::total([]), 'an empty ladder is zero, not an error');
    },

    'P17B-T1 a new product saves the reference ladder with the switch off' => function () use ($makeProduct, $storedRows): void {
        $id = $makeProduct(
            ['name' => 'Configured Coffee', 'pv' => '250', 'unilevel_base' => 'auto', 'unilevel_enabled' => '0'],
            ProductUnilevel::referencePreset()
        );

        $product = Product::find($id);
        assert_not_null($product);
        assert_same('250.00', number_format((float) $product['pv'], 2, '.', ''), 'the P.V. is stored as money');
        assert_same(0, (int) $product['unilevel_enabled'], 'nothing is enabled by default (D-11)');
        assert_same('auto', $product['unilevel_base']);

        assert_count(10, $storedRows($id), 'ten ladder rows, never nine');
        assert_same(
            [1 => '5.000', '5.000', '5.000', '3.000', '3.000', '2.000', '2.000', '2.000', '1.000', '1.000'],
            $storedRows($id)
        );

        /* The form re-reads the same numbers, with no trailing zeros. */
        assert_same(
            [1 => '5', '5', '5', '3', '3', '2', '2', '2', '1', '1'],
            ProductUnilevel::forForm(ProductUnilevel::levels($id))
        );
        assert_same(29.0, ProductUnilevel::totalsMap([$id])[$id]);
    },

    'P17B-T2 blanks are stored as 0.000 and shown blank again' => function () use ($makeProduct, $storedRows): void {
        $id = $makeProduct([], [
            1 => '5',
            2 => '',
            3 => '3',
            4 => '   ',
            5 => '0',
            6 => '0.000',
        ]);

        $rows = $storedRows($id);

        assert_count(10, $rows, 'a blank ladder still writes ten rows');
        assert_same('5.000', $rows[1]);
        assert_same('0.000', $rows[2], 'a blank level is stored as 0.000');
        assert_same('0.000', $rows[4]);
        assert_same('0.000', $rows[6]);
        assert_same('0.000', $rows[10]);

        $form = ProductUnilevel::forForm(ProductUnilevel::levels($id));
        assert_same('', $form[2], 'the form shows a blank for 0');
        assert_same('', $form[10]);
        assert_same('5', $form[1]);
        assert_same('3', $form[3]);

        /* A whole-ladder rewrite never leaves the old tenth row behind. */
        ProductUnilevel::save($id, [1 => '2.5']);
        assert_count(10, $storedRows($id));
        assert_same('2.500', $storedRows($id)[1]);
        assert_same('0.000', $storedRows($id)[3], 'the previous ladder is gone');
    },

    'P17B-T2 three decimals survive the round trip' => function () use ($makeProduct): void {
        $id = $makeProduct([], [1 => '0.125', 2 => '12.5', 3 => '100']);

        $stored = ProductUnilevel::levels($id);
        assert_same(0.125, $stored[1]);
        assert_same(12.5, $stored[2]);
        assert_same(100.0, $stored[3]);

        $form = ProductUnilevel::forForm($stored);
        assert_same('0.125', $form[1]);
        assert_same('12.5', $form[2]);
        assert_same('100', $form[3]);
    },

    /* ==========================================================
     *  Validation (P17B-T3 … T8)
     * ========================================================== */

    'P17B-T3 a ladder over 100% is refused with a sum error' => function () use ($makeProduct, $storedRows): void {
        $id = $makeProduct(['name' => 'Five Percent', 'pv' => '250', 'unilevel_base' => 'auto'], [1 => '5', 2 => '5', 3 => '5', 4 => '3']);

        $ladder = [1 => '20', 2 => '20', 3 => '20', 4 => '20', 5 => '20', 6 => '20'];
        $errors = ProductUnilevel::validate($ladder, false);

        assert_true(isset($errors['unilevel_levels.sum']), 'the sum error is keyed on the ladder');
        assert_contains('120%', $errors['unilevel_levels.sum']);
        assert_contains('more than 100%', $errors['unilevel_levels.sum']);
        assert_same(1, count($errors), 'one message, not ten');

        /* The controller stops on any error, before it writes; the stored
         * ladder is therefore untouched. */
        if ($errors === []) {
            Product::save(['name' => 'Five Percent', 'price' => '300.00', 'stock' => '10', 'status' => 'active'], $id, $ladder);
        }

        assert_same('3.000', $storedRows($id)[4], 'level 4 still holds the refused value');
        assert_count(4, array_filter($storedRows($id), static fn (string $v): bool => $v !== '0.000'));
    },

    'P17B-T3 a failed product write leaves no ladder behind' => function () use ($makeProduct): void {
        $before = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $name   = str_repeat('x', 200);

        /* The name overflows VARCHAR(160): the product INSERT fails, and
         * because the row and the ladder share one transaction no ladder
         * row survives it. */
        assert_throws(
            static fn () => Product::save(
                ['name' => $name, 'price' => '10.00', 'stock' => '1', 'status' => 'active', 'pv' => '5'],
                null,
                ProductUnilevel::referencePreset()
            ),
            PDOException::class,
            'an over-long product name is refused by the column'
        );

        assert_same($before, (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn());
        assert_same(
            0,
            (int) db()->query('SELECT COUNT(*) FROM product_unilevel_levels WHERE percent > 0')->fetchColumn(),
            'no half-written ladder'
        );
    },

    'P17B-T4 100% plus 1% is a sum error' => function (): void {
        $errors = ProductUnilevel::validate([1 => '100', 2 => '1'], false);

        assert_true(isset($errors['unilevel_levels.sum']));
        assert_contains('101%', $errors['unilevel_levels.sum']);

        /* Exactly 100% is allowed. */
        assert_same([], ProductUnilevel::validate([1 => '100', 2 => '0'], false));
        assert_same([], ProductUnilevel::validate([1 => '50', 2 => '50'], true));
    },

    'P17B-T5 a rate over 100 or with four decimals fails on its own level' => function (): void {
        $tooHigh = ProductUnilevel::validateLevels([1 => '100.001']);
        assert_true(isset($tooHigh['unilevel_levels.1']));
        assert_contains('Level 1', $tooHigh['unilevel_levels.1']);
        assert_contains('at most 100', $tooHigh['unilevel_levels.1']);

        $tooPrecise = ProductUnilevel::validateLevels([3 => '5.0001']);
        assert_true(isset($tooPrecise['unilevel_levels.3']));
        assert_contains('at most 3 decimal places', $tooPrecise['unilevel_levels.3']);

        $negative = ProductUnilevel::validateLevels([2 => '-1']);
        assert_true(isset($negative['unilevel_levels.2']));
        assert_contains('at least 0', $negative['unilevel_levels.2']);

        $text = ProductUnilevel::validateLevels([4 => 'five']);
        assert_true(isset($text['unilevel_levels.4']));

        /* The other nine levels stay clean, so only the bad row is marked. */
        assert_same(1, count($tooPrecise));
    },

    'P17B-T6 switching on an empty ladder is refused' => function (): void {
        $empty = ['1' => '', '2' => '0'];

        $errors = ProductUnilevel::validate($empty, true);
        assert_true(isset($errors['unilevel_enabled']));
        assert_contains('Turn the switch off', $errors['unilevel_enabled']);

        /* Off with an empty ladder is the shipped default and is fine. */
        assert_same([], ProductUnilevel::validate($empty, false));
        assert_same([], ProductUnilevel::validate([1 => '5'], true));
    },

    'P17B-T7 a P.V. base without a P.V. is refused' => function (): void {
        $errors = Product::validate([
            'name' => 'P.V. Product', 'price' => '300.00', 'stock' => '1', 'status' => 'active',
            'pv' => '0', 'unilevel_base' => 'pv',
        ]);

        assert_true(isset($errors['unilevel_base']));
        assert_contains('P.V.', $errors['unilevel_base']);

        /* A blank P.V. and a non-numeric one fail on the field itself. */
        assert_true(isset(Product::validate([
            'name' => 'P.V. Product', 'price' => '300.00', 'stock' => '1', 'status' => 'active',
            'pv' => '250.001', 'unilevel_base' => 'auto',
        ])['pv']), 'the P.V. keeps two decimals');

        assert_true(isset(Product::validate([
            'name' => 'P.V. Product', 'price' => '300.00', 'stock' => '1', 'status' => 'active',
            'pv' => '-1',
        ])['pv']));

        assert_true(isset(Product::validate([
            'name' => 'Base Product', 'price' => '300.00', 'stock' => '1', 'status' => 'active',
            'unilevel_base' => 'revenue',
        ])['unilevel_base']), 'the base is one of the three enum values');
    },

    'P17B-T8 a price base with no P.V. is accepted' => function () use ($makeProduct): void {
        $input = [
            'name' => 'Price Based', 'price' => '500.00', 'stock' => '5', 'status' => 'active',
            'pv' => '0', 'unilevel_base' => 'price', 'unilevel_enabled' => '1',
        ];

        assert_same([], Product::validate($input) + ProductUnilevel::validate([1 => '10'], true));

        $id = $makeProduct($input, [1 => '10']);
        $product = Product::find($id);
        assert_same('price', $product['unilevel_base']);
        assert_same(1, (int) $product['unilevel_enabled']);

        /* And Auto with no P.V. also falls back to the price. */
        $auto = ProductUnilevel::baseFor(['price' => '500.00', 'pv' => '0', 'unilevel_base' => 'auto'], 3);
        assert_same(500.0, $auto['unit']);
        assert_same(1500.0, $auto['total']);
        assert_same('price', $auto['basis']);
    },

    /* ==========================================================
     *  Audit (P17B-T9)
     * ========================================================== */

    'P17B-T9 the ladder change is audited once with before and after' => function () use ($makeProduct, $auditRows, $allAudit, $auditMark): void {
        $admin = test_user('p17b_audit_admin', 'admin');
        $start = $auditMark();

        $id = $makeProduct(
            ['name' => 'Audited Coffee', 'pv' => '250', 'unilevel_base' => 'auto', 'unilevel_enabled' => '0'],
            ProductUnilevel::referencePreset(),
            $admin
        );

        $rows = $auditRows($start);
        assert_count(1, $rows, 'creating a ladder is one audited change');

        $meta = json_decode((string) $rows[0]['meta_json'], true);
        assert_same('product', $rows[0]['subject_type']);
        assert_same($id, (int) $rows[0]['subject_id']);
        assert_same((int) $admin['id'], (int) $rows[0]['actor_id']);
        assert_same('Audited Coffee', $meta['product']);
        assert_same('0.00', $meta['before']['pv']);
        assert_same('0.000', $meta['before']['levels'][1]);
        assert_same('250.00', $meta['after']['pv']);
        assert_same('5.000', $meta['after']['levels'][1]);
        assert_same('1.000', $meta['after']['levels'][10]);
        assert_same(11, count($meta['changes']), 'ten levels plus the P.V. moved from nothing');
        assert_same(['pv'], array_slice(array_keys($meta['changes']), 0, 1), 'the P.V. is part of the configuration');
        assert_same('0.00', $meta['changes']['pv']['from']);
        assert_same('250.00', $meta['changes']['pv']['to']);

        /* Saving the same ladder again changes nothing and audits nothing. */
        Product::save(
            ['name' => 'Audited Coffee', 'price' => '300.00', 'stock' => '10', 'status' => 'active', 'pv' => '250', 'unilevel_base' => 'auto', 'unilevel_enabled' => '0'],
            $id,
            ProductUnilevel::referencePreset(),
            $admin
        );
        assert_count(1, $auditRows($start), 'a no-op save is not audited');

        /* Changing one level and the switch records exactly those keys. */
        $ladder = ProductUnilevel::referencePreset();
        $ladder[4] = 4.0;

        Product::save(
            ['name' => 'Audited Coffee', 'price' => '300.00', 'stock' => '10', 'status' => 'active', 'pv' => '250', 'unilevel_base' => 'auto', 'unilevel_enabled' => '1'],
            $id,
            $ladder,
            $admin
        );

        $rows = $auditRows($start);
        assert_count(2, $rows);

        $meta = json_decode((string) $rows[1]['meta_json'], true);
        assert_same(['enabled', 'levels.4'], array_keys($meta['changes']), 'only what moved is listed');
        assert_same(0, $meta['changes']['enabled']['from']);
        assert_same(1, $meta['changes']['enabled']['to']);
        assert_same('3.000', $meta['changes']['levels.4']['from']);
        assert_same('4.000', $meta['changes']['levels.4']['to']);
        assert_same('3.000', $meta['before']['levels'][4]);
        assert_same('4.000', $meta['after']['levels'][4]);

        /* This case wrote nothing but the two ladder entries. */
        $actions = array_values(array_unique(array_column($allAudit($start), 'action')));
        assert_same(['product.unilevel.update'], $actions);
    },

    /* ==========================================================
     *  Paying nothing yet (P17B-T10, T20)
     * ========================================================== */

    'P17B-T10 baseFor follows the base choice and the quantity' => function (): void {
        $auto  = ProductUnilevel::baseFor(['price' => '300.00', 'pv' => '250.00', 'unilevel_base' => 'auto'], 2);
        assert_same(250.0, $auto['unit'], 'auto prefers the P.V.');
        assert_same(500.0, $auto['total'], '× quantity');
        assert_same('pv', $auto['basis']);

        $price = ProductUnilevel::baseFor(['price' => '300.00', 'pv' => '250.00', 'unilevel_base' => 'price'], 2);
        assert_same(300.0, $price['unit']);
        assert_same(600.0, $price['total']);
        assert_same('price', $price['basis']);

        $pv = ProductUnilevel::baseFor(['price' => '300.00', 'pv' => '250.00', 'unilevel_base' => 'pv'], 1);
        assert_same(250.0, $pv['unit']);
        assert_same(250.0, $pv['total']);
        assert_same('pv', $pv['basis']);

        $fallback = ProductUnilevel::baseFor(['price' => '500.00', 'pv' => '0.00', 'unilevel_base' => 'auto'], 1);
        assert_same(500.0, $fallback['unit']);
        assert_same('price', $fallback['basis']);

        /* Two decimals, and never a negative base or quantity. */
        $odd = ProductUnilevel::baseFor(['price' => '99.995', 'pv' => '0', 'unilevel_base' => 'price'], 3);
        assert_same(100.0, $odd['unit'], 'the unit is rounded to the centavo');
        assert_same(300.0, $odd['total']);

        $none = ProductUnilevel::baseFor(['price' => '10.00', 'pv' => '0', 'unilevel_base' => 'auto'], -5);
        assert_same(0.0, $none['total']);
    },

    'P17B-T20 paying a configured product still writes no award' => function () use ($makeProduct): void {
        $buyer   = test_user('p17b_buyer_' . bin2hex(random_bytes(3)));
        $sponsor = test_user('p17b_sponsor_' . bin2hex(random_bytes(3)));

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $sponsor['id'], (int) $buyer['id']]);

        /* Everything an operator could configure after 17B: both switches on,
         * a P.V. base and the reference ladder. */
        setting_set('unilevel_enabled', '1');

        $productId = $makeProduct([
            'name' => 'Award Probe', 'price' => '300.00', 'pv' => '250', 'stock' => '5',
            'status' => 'active', 'unilevel_base' => 'auto', 'unilevel_enabled' => '1',
        ], ProductUnilevel::referencePreset());

        (new WalletService())->credit((int) $buyer['id'], '1000.00', 'commission', random_int(1000000, 9999999), 'P17B funds');
        (new CartService())->add((int) $buyer['id'], $productId, 2);

        $result = (new OrderService())->placeFromCart((int) $buyer['id'], [
            'billing_name'      => 'P17B Buyer',
            'billing_phone'     => '0917 000 0000',
            'shipping_name'     => 'P17B Buyer',
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
        ]);

        assert_same('paid', $result['status'], 'the order is paid, because 17B has no hook');

        $order = Order::find($result['order_id']);
        assert_null($order['unilevel_processed_at'], 'nothing stamped the order');
        assert_same('0.00', number_format((float) $order['unilevel_total'], 2, '.', ''));

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn());
        assert_same(
            0,
            (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type IN ('unilevel', 'unilevel_reversal')")->fetchColumn(),
            'no repeat-purchase movement in the wallet'
        );

        /* The order is otherwise a normal paid order: one debit, paid. */
        assert_same(400.0, (new WalletService())->balance((int) $buyer['id']), '1000 funded − 600 spent');
        assert_same(0.0, (new WalletService())->balance((int) $sponsor['id']));
    },

    /* ==========================================================
     *  Batched reads (P17B-T12)
     * ========================================================== */

    'P17B-T12 levelsMap and totalsMap read a page without N+1' => function () use ($makeProduct, $measureStatements): void {
        $ids = [];

        foreach ([29.0, 10.0, 0.0, 5.5, 0.0] as $index => $expected) {
            $ladder = $expected === 29.0
                ? ProductUnilevel::referencePreset()
                : ($expected > 0 ? [1 => (string) $expected] : []);

            $ids[] = $makeProduct(['name' => 'Batch ' . $index], $ladder);
        }

        $ids[] = 999999;   // a product that does not exist

        $map = ProductUnilevel::levelsMap($ids);
        assert_count(6, $map, 'every requested id is present, including the missing one');
        assert_same(5.0, $map[$ids[0]][1]);
        assert_same(1.0, $map[$ids[0]][10]);
        assert_same(0.0, $map[$ids[2]][1], 'a product without rows reads as a zero ladder');
        assert_same(5.5, $map[$ids[3]][1]);
        assert_same(
            [1 => 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            $map[999999],
            'a missing product is a zero ladder, not an error'
        );

        $totals = ProductUnilevel::totalsMap($ids);
        assert_same(29.0, $totals[$ids[0]]);
        assert_same(10.0, $totals[$ids[1]]);
        assert_same(0.0, $totals[$ids[2]], 'a product without rows totals 0%');
        assert_same(5.5, $totals[$ids[3]]);
        assert_same(0.0, $totals[999999], 'a missing product is 0%, not absent');

        /* The cost of a call must not grow with the number of products: a
         * per-product query would make the 20-id call five times dearer. */
        $two  = array_slice($ids, 0, 2);
        $many = $two;

        for ($i = 0; $i < 18; $i++) {
            $many[] = $makeProduct(['name' => 'Padding ' . $i]);
        }

        assert_same(
            $measureStatements(static fn () => ProductUnilevel::levelsMap($two)),
            $measureStatements(static fn () => ProductUnilevel::levelsMap($many)),
            'levelsMap costs the same for 2 products and 20'
        );
        assert_same(
            $measureStatements(static fn () => ProductUnilevel::totalsMap($two)),
            $measureStatements(static fn () => ProductUnilevel::totalsMap($many)),
            'totalsMap costs the same for 2 products and 20'
        );

        assert_same([], ProductUnilevel::levelsMap([]), 'no ids means no query');
        assert_same([], ProductUnilevel::totalsMap([]));
    },

    'P17B-T12 rowsFor returns the engine columns in one query' => function () use ($makeProduct, $measureStatements): void {
        $a = $makeProduct(['name' => 'Engine A', 'price' => '300.00', 'pv' => '250', 'unilevel_base' => 'pv', 'unilevel_enabled' => '1'], [1 => '5']);
        $b = $makeProduct(['name' => 'Engine B', 'price' => '99.50', 'pv' => '0', 'unilevel_base' => 'price']);

        $rows = Product::rowsFor([$a, $b, 999999]);

        assert_count(2, $rows, 'missing ids are omitted, not zero-filled');
        assert_same([
            'id'               => $a,
            'name'             => 'Engine A',
            'price'            => '300.00',
            'pv'               => '250.00',
            'unilevel_enabled' => 1,
            'unilevel_base'    => 'pv',
        ], $rows[$a]);
        assert_same(0, $rows[$b]['unilevel_enabled']);

        $many = [$a, $b];

        for ($i = 0; $i < 18; $i++) {
            $many[] = $makeProduct(['name' => 'Engine padding ' . $i]);
        }

        assert_same(
            $measureStatements(static fn () => Product::rowsFor([$a, $b])),
            $measureStatements(static fn () => Product::rowsFor($many)),
            'rowsFor costs the same for 2 products and 20'
        );
        assert_same([], Product::rowsFor([]));
    },

    /* ==========================================================
     *  Delete guards (P17B-T13)
     * ========================================================== */

    'P17B-T13 deleting a product cascades its ladder; an award blocks' => function () use ($makeProduct, $storedRows): void {
        $id = $makeProduct([], ProductUnilevel::referencePreset());
        assert_count(10, $storedRows($id));

        $result = Product::delete($id);
        assert_true($result['ok']);
        assert_null(Product::find($id));
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM product_unilevel_levels WHERE product_id = ' . (int) $id)->fetchColumn());

        /* With an award row the guard refuses before the FK does. */
        $awarded = $makeProduct(['name' => 'Awarded'], [1 => '5']);
        $buyer   = test_user('p17b_award_buyer_' . bin2hex(random_bytes(3)));
        $upline  = test_user('p17b_award_upline_' . bin2hex(random_bytes(3)));
        $orderId = test_order((int) $buyer['id'], [['product_id' => $awarded]]);
        $itemId  = (int) db()->query('SELECT id FROM shop_order_items WHERE order_id = ' . $orderId . ' LIMIT 1')->fetchColumn();

        db()->prepare(
            'INSERT INTO unilevel_awards
               (order_id, order_item_id, product_id, buyer_id, user_id, level, base_amount, rate_pct, amount, paid_at)
             VALUES (?, ?, ?, ?, ?, 1, 500.00, 5.000, 25.00, NOW())'
        )->execute([$orderId, $itemId, $awarded, (int) $buyer['id'], (int) $upline['id']]);

        $refs = Product::referencesMap([$awarded])[$awarded];
        assert_same(1, $refs['awards']);

        $blocked = Product::delete($awarded);
        assert_false($blocked['ok']);
        assert_same('referenced', $blocked['reason']);
        assert_same(1, $blocked['awards']);
        assert_not_null(Product::find($awarded), 'the awarded product survives');
    },

    /* ==========================================================
     *  The product form (P17B-T14, T15)
     * ========================================================== */

    'P17B-T14 the product form renders one ladder and no direct/indirect control' => function () use ($productRow, $render): void {
        test_signed_in_as(test_user('p17b_view_admin', 'admin'));

        /* Render through the controller so its pre-fill is exercised too. */
        ob_start();

        try {
            (new AdminProductController())->create();
        } finally {
            $created = (string) ob_get_clean();
        }

        assert_contains('Unilevel repeat-purchase bonus', $created);
        assert_contains('name="pv"', $created, 'the P.V. field lives in Pricing & stock');
        assert_contains('type="hidden" name="unilevel_enabled" value="0"', $created, 'the switch posts 0 when unticked');
        assert_contains('name="unilevel_base"', $created);
        assert_contains('<option value="auto" selected>', $created);
        assert_contains('assets/js/app-product-unilevel.js', $created, 'the preview script is linked');

        /* Ten rate inputs, all editable, named unilevel_levels[1..10]. */
        assert_same(10, preg_match_all('/name="unilevel_levels\[\d+\]"/', $created));

        preg_match_all('/<input\b[^>]*name="unilevel_levels\[\d+\]"[^>]*>/', $created, $matches);
        assert_count(10, $matches[0]);

        foreach ($matches[0] as $input) {
            assert_not_contains('readonly', $input, 'no level is read-only');
            assert_not_contains('disabled', $input, 'no level is disabled');
            assert_contains('min="0"', $input);
            assert_contains('max="100"', $input);
            assert_contains('step="0.001"', $input);
        }

        /* The switch is off and the ladder is the reference preset. */
        preg_match('/<input\b[^>]*id="productUnilevelEnabled"[^>]*>/', $created, $switch);
        assert_true($switch !== [], 'the switch renders');
        assert_not_contains('checked', $switch[0], 'a new product is not enabled');

        foreach ([1 => '5', 5 => '3', 10 => '1'] as $level => $value) {
            assert_contains('id="unilevelLevel' . $level . '"', $created);
            assert_contains('value="' . $value . '"', $created);
        }

        /* One card, after Details, in the left column (Rule 10). */
        assert_true(
            strpos($created, 'Unilevel repeat-purchase bonus') > strpos($created, 'Product name'),
            'the ladder card sits after the Details card'
        );

        /* No direct/indirect control exists anywhere (D-17). */
        foreach (['direct_unilevel', 'indirect_unilevel', 'unilevel_direct', 'unilevel_indirect', 'name="direct', 'name="indirect', 'data-leg'] as $forbidden) {
            assert_not_contains($forbidden, $created, $forbidden . ' must not exist in the product form');
        }
    },

    'P17B-T14 the edit form loads the stored ladder' => function () use ($makeProduct): void {
        test_signed_in_as(test_user('p17b_view_admin', 'admin'));

        $id = $makeProduct(
            ['name' => 'Stored Ladder', 'pv' => '250', 'unilevel_base' => 'pv', 'unilevel_enabled' => '1'],
            [1 => '4', 2 => '3.5', 10 => '0.125']
        );

        ob_start();

        try {
            (new AdminProductController())->edit(['id' => (string) $id]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('value="4"', $html);
        assert_contains('value="3.5"', $html);
        assert_contains('value="0.125"', $html);
        assert_contains('value="250.00"', $html, 'the stored P.V. is shown');

        preg_match('/<input\b[^>]*id="productUnilevelEnabled"[^>]*>/', $html, $switch);
        assert_true($switch !== [], 'the switch renders');
        assert_contains('checked', $switch[0], 'the stored switch is on');

        assert_contains('<option value="pv" selected>', $html, 'the stored base is selected');
        assert_contains('7.625%', $html, 'the card header totals the stored ladder');
    },

    'P17B-T15 a refused form keeps the posted values and its errors' => function () use ($render, $productRow): void {
        test_signed_in_as(test_user('p17b_view_admin', 'admin'));

        $message = 'The ladder totals 120.000% — the ten levels cannot add up to more than 100%.';
        errors_set(['unilevel_levels.sum' => $message, 'unilevel_levels.7' => 'Level 7 must be at most 100.']);

        $posted = ['1' => '20', '2' => '20', '3' => '20', '4' => '20', '5' => '20', '6' => '20', '7' => '100.001'];
        $ladder = [];

        for ($level = 1; $level <= 10; $level++) {
            $ladder[$level] = $posted[$level] ?? '';
        }

        $html = $render('admin', 'admin/products/form', [
            'product'  => null,
            'current'  => $productRow(['pv' => '250.00', 'unilevel_enabled' => '1', 'unilevel_base' => 'auto']),
            'unilevel' => $ladder,
        ]);

        assert_contains($message, $html, 'the sum error survives the redirect');
        assert_contains('Level 7 must be at most 100.', $html);
        assert_contains('value="20"', $html, 'the value as typed is re-rendered');
        assert_contains('value="100.001"', $html);

        /* The preview is server-rendered, so it is right without JS: the
         * six 20s plus the typed 100.001 is 220.001%, which the server
         * refuses on save. */
        assert_contains('220.001%', $html, 'the total reflects what was typed, not what is stored');
        assert_true(
            strpos($html, $message) < strpos($html, 'name="unilevel_levels[1]"'),
            'the sum error sits at the top of the card'
        );
    },

    /* ==========================================================
     *  The product list (P17B-T16, T17)
     * ========================================================== */

    'P17B-T16 the list badge tells the truth about the ladder' => function () use ($render, $productRow): void {
        test_signed_in_as(test_user('p17b_view_admin', 'admin'));

        $html = $render('admin', 'admin/products/_rows', [
            'products' => [
                $productRow(['id' => 1, 'name' => 'Configured', 'unilevel_enabled' => 1]),
                $productRow(['id' => 2, 'name' => 'Enabled But Empty', 'unilevel_enabled' => 1]),
                $productRow(['id' => 3, 'name' => 'Switched Off', 'unilevel_enabled' => 0]),
            ],
            'references'     => [
                1 => ['orders' => 0, 'carts' => 0, 'packages' => 0, 'awards' => 0],
                2 => ['orders' => 0, 'carts' => 0, 'packages' => 0, 'awards' => 0],
                3 => ['orders' => 0, 'carts' => 0, 'packages' => 0, 'awards' => 0],
            ],
            'available'      => [1 => 5, 2 => 5, 3 => 5],
            'filters'        => ['q' => '', 'status' => '', 'unilevel' => ''],
            'repeat_totals'  => [1 => 29.0, 2 => 0.0, 3 => 29.0],
        ]);

        assert_contains('Repeat bonus 29%', $html, 'on with a ladder');
        assert_contains('Repeat bonus 0%', $html, 'on with an empty ladder is a visible configuration error');
        assert_same(2, substr_count($html, 'Repeat bonus '), 'an off product shows no badge');
        assert_contains('bg-label-warning', $html, 'the empty ladder is flagged, not hidden');
    },

    'P17B-T17 the unilevel filter narrows the list and survives pagination' => function () use ($makeProduct): void {
        $on  = $makeProduct(['name' => 'Filter On', 'unilevel_enabled' => '1', 'pv' => '100'], [1 => '5']);
        $off = $makeProduct(['name' => 'Filter Off', 'unilevel_enabled' => '0']);

        /* One product is on; the seven seeded products and the off one are not. */
        assert_same(1, Product::countAdmin(['unilevel' => 'on']));
        assert_same(8, Product::countAdmin(['unilevel' => 'off']));

        $onRows = Product::adminList(['unilevel' => 'on'], new Paginator(1, 20, 1));
        $ids    = array_map(static fn (array $row): int => (int) $row['id'], $onRows);

        assert_same([$on], $ids);
        assert_false(in_array($off, $ids, true));

        /* Anything that is not on/off is ignored, never trusted. */
        assert_same(9, Product::countAdmin(['unilevel' => "on' OR 1=1 --"]));
        assert_same(9, Product::countAdmin(['unilevel' => 'yes']));

        /* The filter survives pagination because the pager keeps the query. */
        $_GET = ['unilevel' => 'on', 'per_page' => '5', 'page' => '1'];
        $pager = Paginator::fromTableRequest(Product::countAdmin(['unilevel' => 'on']));
        assert_contains('unilevel=on', $pager->url(2));
        assert_contains('page=2', $pager->url(2));

        /* The list page renders the filter with the current value selected. */
        test_signed_in_as(test_user('p17b_view_admin', 'admin'));

        $_GET = ['unilevel' => 'on'];

        ob_start();

        try {
            View::page('admin', 'admin/products/index', [
                'products'      => $onRows,
                'pager'         => new Paginator(1, 20, 1),
                'filters'       => ['q' => '', 'status' => '', 'unilevel' => 'on'],
                'stats'         => Product::stats(),
                'references'    => Product::referencesMap([$on]),
                'available'     => [],
                'repeat_totals' => ProductUnilevel::totalsMap([$on]),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('name="unilevel"', $html);
        assert_contains('<option value="on" selected>', $html);
        assert_contains('Repeat bonus — off', $html);
    },

    /* ==========================================================
     *  The settings card (P17B-T18, T19)
     * ========================================================== */

    'P17B-T18 the commissions card holds the five repeat-purchase keys' => function (): void {
        test_signed_in_as(test_user('p17b_settings_admin', 'admin'));

        $controller = new AdminSettingsController();
        $context    = $controller->settingsContext();

        ob_start();

        try {
            View::page('admin', 'admin/settings/index', $context + [
                'page_title'  => 'Settings',
                'current_nav' => 'admin.settings',
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        preg_match('/<form\b[^>]*\bid="settings-commissions"[^>]*>(.*?)<\/form>/s', $html, $match);
        $card = $match[0] ?? '';
        assert_true($card !== '', 'the commissions card renders');

        assert_contains('Repeat-purchase bonus', $card, 'the sub-block has its own heading');
        assert_contains('name="unilevel_max_levels"', $card);
        assert_contains('name="unilevel_active_threshold"', $card);
        assert_contains('name="unilevel_enabled"', $card);
        assert_contains('name="unilevel_requires_active_upline"', $card);
        assert_contains('name="unilevel_reversal_enabled"', $card);

        /* The depth is an integer 1–10, the threshold is money. */
        preg_match('/<input\b[^>]*name="unilevel_max_levels"[^>]*>/', $card, $depth);
        assert_true($depth !== []);
        assert_contains('min="1"', $depth[0]);
        assert_contains('max="10"', $depth[0]);
        assert_contains('step="1"', $depth[0]);

        preg_match('/<input\b[^>]*name="unilevel_active_threshold"[^>]*>/', $card, $threshold);
        assert_true($threshold !== []);
        assert_contains('step="0.01"', $threshold[0]);

        /* Five visible keys, and never the reconciliation baseline. */
        preg_match_all('/name="(unilevel_[a-z_]+)"/', $card, $names);
        assert_count(5, array_unique($names[1]), 'five repeat-purchase keys, no sixth');
        assert_not_contains('unilevel_ledger_since', $html, 'the sixth key is never rendered');
        assert_same(1, substr_count($card, 'Save settings'), 'the card still has one save button');
    },

    'P17B-T19 the settings card validates and round-trips its keys' => function (): void {
        $rulesMethod = new ReflectionMethod(AdminSettingsController::class, 'sectionRules');
        $labelsMethod = new ReflectionMethod(AdminSettingsController::class, 'sectionLabels');
        $rulesMethod->setAccessible(true);
        $labelsMethod->setAccessible(true);

        $rules  = $rulesMethod->invoke(null, 'commissions');
        $labels = $labelsMethod->invoke(null, 'commissions');

        assert_contains('gte:1', $rules['unilevel_max_levels'] ?? '');
        assert_contains('lte:10', $rules['unilevel_max_levels'] ?? '');
        assert_contains('decimal:2', $rules['unilevel_active_threshold'] ?? '');
        assert_same('required|in:0,1', $rules['unilevel_enabled'] ?? '');
        assert_same('required|in:0,1', $rules['unilevel_requires_active_upline'] ?? '');
        assert_same('required|in:0,1', $rules['unilevel_reversal_enabled'] ?? '');
        assert_false(array_key_exists('unilevel_ledger_since', $rules), 'the baseline is not editable');

        $input = static fn (string $depth, string $threshold = '500.00', string $enabled = '0'): array => [
            'commission_indirect_max_levels'    => '10',
            'commission_direct_enabled'         => '1',
            'commission_indirect_enabled'       => '1',
            'commission_requires_active_sponsor' => '1',
            'commission_compression'            => '0',
            'free_registration_enabled'         => '1',
            'unilevel_max_levels'               => $depth,
            'unilevel_active_threshold'         => $threshold,
            'unilevel_enabled'                  => $enabled,
            'unilevel_requires_active_upline'   => '1',
            'unilevel_reversal_enabled'         => '1',
        ];

        /* 0 and 11 are both refused, on the field itself. */
        foreach (['0', '11', ''] as $bad) {
            $errors = Validator::check($input($bad), array_intersect_key($rules, $input($bad)), $labels);
            assert_true(isset($errors['unilevel_max_levels']), 'depth ' . ($bad === '' ? '(blank)' : $bad) . ' is refused');
        }

        assert_same([], Validator::check($input('10'), array_intersect_key($rules, $input('10')), $labels));
        assert_same([], Validator::check($input('1'), array_intersect_key($rules, $input('1')), $labels));

        $errors = Validator::check($input('10', '500.001'), array_intersect_key($rules, $input('10', '500.001')), $labels);
        assert_true(isset($errors['unilevel_active_threshold']), 'the threshold keeps two decimals');

        /* Persistence is per key, exactly what the controller writes. The
         * `settings.update` audit entry is proved over HTTP by the smoke
         * suite, because save() ends in a redirect. */
        setting_set('unilevel_max_levels', '7');
        setting_set('unilevel_active_threshold', '250.00');
        setting_set('unilevel_enabled', '1');
        setting_set('unilevel_requires_active_upline', '0');
        setting_set('unilevel_reversal_enabled', '0');
        setting_cache_clear();

        assert_same('7', setting('unilevel_max_levels'));
        assert_same('250.00', setting('unilevel_active_threshold'));
        assert_same('1', setting('unilevel_enabled'));
        assert_same('0', setting('unilevel_requires_active_upline'));
        assert_same('0', setting('unilevel_reversal_enabled'));
        assert_same(7, setting_int('unilevel_max_levels'));
        assert_same(250.0, setting_float('unilevel_active_threshold'));
        assert_true(setting_bool('unilevel_enabled'));
    },
];
