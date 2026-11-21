<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryProductTest — Phase 22B-S2 (plan §6 Phase 22B)
 *
 *  The product binary flag: `products.binary_enabled` validation,
 *  server-side save, the P.V.-to-points readout, the list badge and
 *  the `?binary=on|off` filter. No money moves — the switch is inert
 *  until 22D.
 *
 *  Run this group alone with:  php tests/run.php BinaryProduct
 * ============================================================== */

/* --------------------------------------------------------------
 *  Render the admin product form template standalone.
 * -------------------------------------------------------------- */
$renderForm = static function (?array $product, array $current): string {
    ob_start();

    View::page('admin', 'admin/products/form', [
        'page_title'  => 'Product',
        'current_nav' => 'admin.products',
        'product'     => $product,
        'current'     => $current,
        'unilevel'    => [],
        'page_css'    => [],
        'page_js'     => [],
    ]);

    return (string) ob_get_clean();
};

return [
    /* ---------- T1: flag saves and re-reads ------------------------ */

    'P22B-T1 binary_enabled = 1 with pv = 250 saves and re-reads on' => function (): void {
        $id = Product::save([
            'name'           => 'Binary Coffee',
            'price'          => '300.00',
            'pv'             => '250.00',
            'stock'          => '10',
            'status'         => 'active',
            'binary_enabled' => '1',
        ]);

        $product = Product::find($id);
        assert_not_null($product);
        assert_same(1, (int) $product['binary_enabled']);
        assert_same('250.00', (string) $product['pv']);

        $map = Product::binaryStatusMap([$id]);
        assert_same(1, $map[$id]['binary_enabled']);
        assert_same(250.0, $map[$id]['pv']);
        assert_same(250, $map[$id]['points']);
    },

    /* ---------- T2: binary on with pv = 0 is refused --------------- */

    'P22B-T2 binary on with pv = 0 is refused on binary_enabled' => function (): void {
        $errors = Product::validate([
            'name'           => 'No Points',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '0',
            'binary_enabled' => '1',
        ]);

        assert_true(isset($errors['binary_enabled']), 'binary_enabled must fail');
        assert_contains('Give the product a P.V. or turn its binary switch off.', $errors['binary_enabled']);
    },

    /* ---------- T3: binary off with pv = 0 is accepted ------------- */

    'P22B-T3 binary off with pv = 0 is accepted' => function (): void {
        $errors = Product::validate([
            'name'           => 'Plain',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '0',
            'binary_enabled' => '0',
        ]);

        assert_same([], $errors);

        $id = Product::save([
            'name'           => 'Plain',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '0',
            'binary_enabled' => '0',
        ]);

        $product = Product::find($id);
        assert_not_null($product);
        assert_same(0, (int) $product['binary_enabled']);
    },

    /* ---------- T4: pv rounds to whole points ---------------------- */

    'P22B-T4 pv = 99.6 reads as 100 points per unit' => function (): void {
        $id = Product::save([
            'name'           => 'Rounding',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '99.6',
            'binary_enabled' => '1',
        ]);

        $map = Product::binaryStatusMap([$id]);
        assert_same(1, $map[$id]['binary_enabled']);
        assert_same(100, $map[$id]['points'], 'ROUND(99.6) = 100');
    },

    /* ---------- T5: pv above 1,000,000 is refused ------------------ */

    'P22B-T5 pv above 1,000,000 is refused on pv' => function (): void {
        $errors = Product::validate([
            'name'           => 'Too Much',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '1000000.01',
            'binary_enabled' => '1',
        ]);

        assert_true(isset($errors['pv']), 'pv must fail above 1,000,000');
    },

    /* ---------- T12: list badge ----------------------------------- */

    'P22B-T12 badge reads Binary 250 pts when on with pv' => function (): void {
        $on = Product::save([
            'name'           => 'On With Points',
            'price'          => '300.00',
            'pv'             => '250.00',
            'stock'          => '1',
            'status'         => 'active',
            'binary_enabled' => '1',
        ]);
        $zero = Product::save([
            'name'           => 'On Zero Points',
            'price'          => '10.00',
            'pv'             => '0',
            'stock'          => '1',
            'status'         => 'active',
            'binary_enabled' => '1',
        ]);
        $off = Product::save([
            'name'           => 'Off',
            'price'          => '10.00',
            'pv'             => '250.00',
            'stock'          => '1',
            'status'         => 'active',
            'binary_enabled' => '0',
        ]);

        $map = Product::binaryStatusMap([$on, $zero, $off]);
        assert_same(250, $map[$on]['points']);
        assert_same(0, $map[$zero]['points']);
        assert_same(1, $map[$on]['binary_enabled']);
        assert_same(0, $map[$off]['binary_enabled']);
    },

    /* ---------- T13: ?binary=on|off filter ------------------------ */

    'P22B-T13 admin list filters by binary on and off' => function (): void {
        Product::save(['name' => 'Bin On', 'price' => '10.00', 'stock' => '1', 'status' => 'active', 'binary_enabled' => '1', 'pv' => '10.00']);
        Product::save(['name' => 'Bin Off', 'price' => '10.00', 'stock' => '1', 'status' => 'active', 'binary_enabled' => '0', 'pv' => '10.00']);

        $onRows  = Product::adminList(['binary' => 'on'], new Paginator(0, 20, 1));
        $offRows = Product::adminList(['binary' => 'off'], new Paginator(0, 20, 1));

        $onNames  = array_column($onRows, 'name');
        $offNames = array_column($offRows, 'name');

        assert_true(in_array('Bin On', $onNames, true));
        assert_false(in_array('Bin Off', $onNames, true));
        assert_true(in_array('Bin Off', $offNames, true));
        assert_false(in_array('Bin On', $offNames, true));
    },

    /* ---------- T14: binaryStatusMap batches, missing reads off --- */

    'P22B-T14 binaryStatusMap returns one row per id, missing reads off' => function (): void {
        $a = Product::save(['name' => 'A', 'price' => '10.00', 'stock' => '1', 'status' => 'active', 'binary_enabled' => '1', 'pv' => '50.00']);
        $b = Product::save(['name' => 'B', 'price' => '10.00', 'stock' => '1', 'status' => 'active', 'binary_enabled' => '0', 'pv' => '50.00']);

        $map = Product::binaryStatusMap([$a, $b, 999999]);

        assert_same(1, $map[$a]['binary_enabled']);
        assert_same(50, $map[$a]['points']);
        assert_same(0, $map[$b]['binary_enabled']);
        assert_false(array_key_exists(999999, $map), 'a missing product is simply absent');
    },

    /* ---------- T17: product.binary.update audit ------------------ */

    'P22B-T17 audit written only when switch or pv changed' => function (): void {
        $id = Product::save([
            'name'           => 'Audited',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '100.00',
            'binary_enabled' => '0',
        ]);

        $before = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        /* Same values: nothing changes, no audit row. */
        Product::save([
            'name'           => 'Audited',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '100.00',
            'binary_enabled' => '0',
        ], $id);

        assert_same($before, (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn(), 'unchanged config must not audit');

        /* Switch flips on: audit row with before/after. */
        Product::save([
            'name'           => 'Audited',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '100.00',
            'binary_enabled' => '1',
        ], $id);

        $after = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
        assert_true($after > $before, 'switch flip must audit');

        $stmt = db()->prepare("SELECT subject_type, subject_id, meta_json FROM audit_log WHERE action = 'product.binary.update' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        assert_not_null($row);
        assert_same('product', (string) $row['subject_type']);
        assert_same($id, (int) $row['subject_id']);
        $meta = json_decode((string) $row['meta_json'], true);
        assert_same(0, $meta['before']['enabled']);
        assert_same('100.00', $meta['before']['pv']);
        assert_same(1, $meta['after']['enabled']);
        assert_same('100.00', $meta['after']['pv']);
        assert_same(0, $meta['changes']['enabled']['from']);
        assert_same(1, $meta['changes']['enabled']['to']);
        assert_same('Audited', $meta['product']);
    },

    /* ---------- T26: delete with switch on and no binary rows ----- */

    'P22B-T26 delete with binary switch on and no binary rows is allowed' => function (): void {
        $id = Product::save([
            'name'           => 'Deletable',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '250.00',
            'binary_enabled' => '1',
        ]);

        $result = Product::delete($id);

        assert_true($result['ok']);
        assert_null(Product::find($id));
    },

    /* ---------- Views --------------------------------------------- */

    'P22B-T15 product form renders the binary card, no per-product rates' => function () use ($renderForm): void {
        $html = $renderForm(null, [
            'name'           => 'New',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '',
            'binary_enabled' => '0',
        ]);

        assert_contains('Product binary points', $html);
        assert_contains('name="binary_enabled"', $html);
        assert_contains('Points per unit', $html);
        assert_contains('P.V.', $html);
        assert_contains('Pricing &amp; stock', $html);
        /* No per-product pair-size, pair-rate or self-rate input. */
        assert_false(str_contains($html, 'name="binary_pair'), 'no per-product pair input');
        assert_false(str_contains($html, 'name="binary_self'), 'no per-product self input');
    },

    'P22B-T16 product form after a validation error keeps the typed values' => function () use ($renderForm): void {
        errors_set(['binary_enabled' => 'Give the product a P.V. or turn its binary switch off.']);
        old_set(['binary_enabled' => '1', 'pv' => '0', 'name' => 'X', 'price' => '10.00', 'stock' => '1', 'status' => 'active']);
        old_payload_set(['input' => ['binary_enabled' => '1', 'pv' => '0', 'name' => 'X', 'price' => '10.00', 'stock' => '1', 'status' => 'active'], 'unilevel' => []]);

        $html = $renderForm(null, [
            'name'           => 'X',
            'price'          => '10.00',
            'stock'          => '1',
            'status'         => 'active',
            'pv'             => '0',
            'binary_enabled' => '1',
        ]);

        assert_contains('Give the product a P.V. or turn its binary switch off.', $html);
        assert_contains('checked', $html);
        assert_contains('value="0"', $html);
    },
];
