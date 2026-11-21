<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageBinaryTest — Phase 23B Configuration and readiness
 *  (plan §6 Phase 23B-S1: package-level controls and list visibility)
 *  Tests P23B-T1 … T10, T12, T18
 * ============================================================== */

/** Standard package payload for create/update cases. */
$packageData = static fn (array $overrides = []): array => $overrides + [
    'name'                  => 'Test Package',
    'price'                 => '1000.00',
    'product_value'         => '1200.00',
    'direct_referral_bonus' => '100.00',
    'description'           => 'A test package.',
    'perks'                 => '',
    'status'                => 'active',
    'sort_order'            => '0',
    'indirect_enabled'      => true,
];

/** Nine level amounts (2–10). */
$nineLevels = static fn (array $overrides = []): array => $overrides + [
    2  => '100', 3  => '50',  4  => '40', 5  => '30',
    6  => '20',  7  => '10', 8  => '5',  9  => '5', 10 => '5',
];

return [

    /* ---------- Validation (P23B-T1 … T5) ---------- */

    'P23B-T1 binary fields validate and persist' => function () use ($packageData, $nineLevels): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '1000',
        ]));
        assert_same([], $errors, 'valid binary config passes validation');

        $id = Package::save($packageData([
            'name'           => 'Binary Package',
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ]), $nineLevels(), []);

        $saved = Package::find($id);
        assert_not_null($saved);
        assert_same(1, (int) $saved['binary_enabled']);
        assert_same(1000, (int) $saved['binary_points']);
    },

    'P23B-T2 switch on with zero points is refused' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '0',
        ]));
        assert_true(isset($errors['binary_points']), 'zero points with switch on must fail');
        assert_contains('Set points above 0', $errors['binary_points']);
    },

    'P23B-T3 switch off with zero points is accepted' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '0',
            'binary_points'   => '0',
        ]));
        assert_same([], $errors, 'switch off with zero points is a valid state');
    },

    'P23B-T4 decimal points are refused' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '1000.5',
        ]));
        assert_true(isset($errors['binary_points']), 'decimal points must fail');
    },

    'P23B-T5 points over one million are refused' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '1000001',
        ]));
        assert_true(isset($errors['binary_points']), 'over-cap points must fail');
    },

    'P23B-T5 points under zero are refused' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '-5',
        ]));
        assert_true(isset($errors['binary_points']), 'negative points must fail');
    },

    /* ---------- Save persistence (P23B-T1 continued) ---------- */

    'P23B-T1 save writes both binary columns' => function () use ($packageData, $nineLevels): void {
        $id = Package::save($packageData([
            'name'           => 'Persist Probe',
            'binary_enabled' => '1',
            'binary_points'  => '2500',
        ]), $nineLevels(), []);

        $saved = Package::find($id);
        assert_not_null($saved);
        assert_same(1, (int) $saved['binary_enabled']);
        assert_same(2500, (int) $saved['binary_points']);

        /* Re-read via all() — SELECT * must include the new columns. */
        $all = Package::all();
        $row = null;
        foreach ($all as $candidate) {
            if ((int) $candidate['id'] === $id) {
                $row = $candidate;
                break;
            }
        }
        assert_not_null($row);
        assert_same(1, (int) $row['binary_enabled']);
        assert_same(2500, (int) $row['binary_points']);
    },

    'P23B-T1 edit that only changes the name writes no binary change' => function () use ($packageData, $nineLevels): void {
        $id = Package::save($packageData([
            'name'           => 'Rename Probe',
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ]), $nineLevels(), []);

        /* Capture the audit count before the rename. */
        $stmt = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'package.binary.update' AND subject_id = ?");
        $stmt->execute([$id]);
        $before = (int) $stmt->fetchColumn();

        Package::save($packageData([
            'name'           => 'Renamed Probe',
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ]), $nineLevels(), [], $id);

        $stmt->execute([$id]);
        $after = (int) $stmt->fetchColumn();
        assert_same($before, $after, 'a rename-only edit must not add a binary audit row');
    },

    /* ---------- Audit (P23B-T7, T18) ---------- */

    'P23B-T7 audit records before/after when binary changes' => function () use ($packageData, $nineLevels): void {
        $id = Package::save($packageData([
            'name'           => 'Audit Probe',
            'binary_enabled' => '0',
            'binary_points'  => '0',
        ]), $nineLevels(), []);

        Package::save($packageData([
            'name'           => 'Audit Probe',
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ]), $nineLevels(), [], $id);

        $stmt = db()->prepare(
            "SELECT meta_json FROM audit_log
             WHERE action = 'package.binary.update' AND subject_id = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$id]);
        $meta = json_decode((string) $stmt->fetchColumn(), true);

        assert_not_null($meta);
        assert_same(0, (int) $meta['before']['binary_enabled']);
        assert_same(0, (int) $meta['before']['binary_points']);
        assert_same(1, (int) $meta['after']['binary_enabled']);
        assert_same(1000, (int) $meta['after']['binary_points']);
        assert_same(1, (int) $meta['changes']['binary_enabled']);
        assert_same(1000, (int) $meta['changes']['binary_points']);
    },

    /* ---------- binaryStatusMap (P23B-T10) ---------- */

    'P23B-T10 binaryStatusMap returns one row per package' => function () use ($packageData, $nineLevels): void {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = Package::save($packageData([
                'name'           => 'Map Package ' . $i,
                'binary_enabled' => $i % 2 === 0 ? '1' : '0',
                'binary_points'  => $i === 0 ? '1000' : '0',
            ]), $nineLevels(), []);
        }

        $map = Package::binaryStatusMap($ids);
        assert_count(3, $map);
        assert_same(['binary_enabled' => 1, 'binary_points' => 1000], $map[$ids[0]]);
        assert_same(['binary_enabled' => 0, 'binary_points' => 0],    $map[$ids[1]]);
        assert_same(['binary_enabled' => 1, 'binary_points' => 0],    $map[$ids[2]]);

        assert_same([], Package::binaryStatusMap([]));
    },

    /* ---------- List badge + filter (P23B-T8, T9) ---------- */

    'P23B-T8 list badge shows on/off/zero correctly' => function () use ($packageData, $nineLevels): void {
        $onId  = Package::save($packageData(['name' => 'Badge On',  'binary_enabled' => '1', 'binary_points' => '1000']), $nineLevels(), []);
        $offId = Package::save($packageData(['name' => 'Badge Off', 'binary_enabled' => '0', 'binary_points' => '0']),    $nineLevels(), []);
        $zeroId= Package::save($packageData(['name' => 'Badge Zero','binary_enabled' => '1', 'binary_points' => '0']),    $nineLevels(), []);

        $map = Package::binaryStatusMap([$onId, $offId, $zeroId]);
        assert_same(['binary_enabled' => 1, 'binary_points' => 1000], $map[$onId]);
        assert_same(['binary_enabled' => 0, 'binary_points' => 0],    $map[$offId]);
        assert_same(['binary_enabled' => 1, 'binary_points' => 0],    $map[$zeroId]);
    },

    'P23B-T9 ?binary=on|off filter narrows the admin list' => function () use ($packageData, $nineLevels): void {
        Package::save($packageData(['name' => 'Filter On',  'binary_enabled' => '1', 'binary_points' => '1000']), $nineLevels(), []);
        Package::save($packageData(['name' => 'Filter Off', 'binary_enabled' => '0', 'binary_points' => '0']),    $nineLevels(), []);

        $on  = Package::adminList(['binary' => 'on'],  new Paginator(1, 20, 1));
        $off = Package::adminList(['binary' => 'off'], new Paginator(1, 20, 1));
        $all = Package::adminList(['binary' => ''],    new Paginator(1, 20, 1));

        $onNames  = array_column($on,  'name');
        $offNames = array_column($off, 'name');

        assert_true(in_array('Filter On',  $onNames, true),  'on filter includes flagged packages');
        assert_false(in_array('Filter Off', $onNames, true), 'on filter excludes unflagged packages');
        assert_true(in_array('Filter Off', $offNames, true), 'off filter includes unflagged packages');
        assert_false(in_array('Filter On',  $offNames, true), 'off filter excludes flagged packages');

        /* Combines with ?status= */
        $combo = Package::adminList(['binary' => 'on', 'status' => 'active'], new Paginator(1, 20, 1));
        assert_count(1, $combo, 'binary filter combines with status filter');
        assert_true(in_array('Filter On', array_column($combo, 'name'), true));
    },

    /* ---------- Form HTML (P23B-T6, T12) ---------- */

    'P23B-T6 create form defaults: switch off, points suggest ROUND(product_value)' => function () use ($packageData): void {
        $current = $packageData([]);
        unset($current['binary_enabled'], $current['binary_points']);

        $html = View::capture('admin/packages/_binary', [
            'current' => $current,
            'isEdit'  => false,
            'id'      => 0,
        ]);

        assert_not_contains('checked', $html, 'create form switch is off by default');
        assert_contains((string) max(0, round((float) $packageData([])['product_value'])), $html, 'points suggest ROUND(product_value)');
    },

    'P23B-T12 form HTML has switch and points, no pair/rate/cap/hold inputs' => function () use ($packageData): void {
        $current = $packageData([]) + ['binary_enabled' => '0', 'binary_points' => ''];

        $html = View::capture('admin/packages/_binary', [
            'current' => $current,
            'isEdit'  => false,
            'id'      => 0,
        ]);

        assert_contains('name="binary_enabled"', $html, 'switch input present');
        assert_contains('name="binary_points"',  $html, 'points input present');
        assert_not_contains('pair_rate', $html, 'no pair-rate input');
        assert_not_contains('daily_cap', $html, 'no cap input');
        assert_not_contains('hold_days', $html, 'no hold input');
        assert_not_contains('binary_pair', strtolower($html), 'no per-package pair rate field');
    },

];
