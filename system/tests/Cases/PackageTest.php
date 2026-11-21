<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageTest — associate packages, ladder and contents
 *  (plan §7 Phase 3, tests P3-T1 … P3-T6)
 * ============================================================== */

/** Standard package payload for create/update cases. */
$packageData = static fn (array $overrides = []): array => $overrides + [
    'name'                  => 'Test Package',
    'price'                 => '1000.00',
    'product_value'         => '1200.00',
    'direct_referral_bonus' => '100.00',
    'description'           => 'A test package.',
    'perks'                 => "Perk one\nPerk two",
    'status'                => 'active',
    'sort_order'            => '99',
    'indirect_enabled'      => true,
];

/** The plan's 2–10 ladder. */
$nineLevels = static fn (array $overrides = []): array => $overrides + [
    2  => '100',
    3  => '50',
    4  => '40',
    5  => '30',
    6  => '20',
    7  => '10',
    8  => '5',
    9  => '5',
    10 => '5',
];

$coffeeId = static fn (): int => (int) (Product::findBySlug('coffee')['id'] ?? 0);
$immuneId = static fn (): int => (int) (Product::findBySlug('immune-booster')['id'] ?? 0);

return [
    /* ---------- Seed + reads --------------------------------------- */

    'P3-T1 the demo seed loads Package A and Package B' => function (): void {
        $a = Package::findBySlug('package-a');
        $b = Package::findBySlug('package-b');

        assert_not_null($a);
        assert_not_null($b);
        assert_same('4498.00', $a['price']);
        assert_same('4750.00', $a['product_value']);
        assert_same('500.00', $a['direct_referral_bonus']);
        assert_same('6998.00', $b['price']);
        assert_same('600.00', $b['direct_referral_bonus'], 'Package B carries the premium direct bonus');
        assert_same(1, (int) $a['indirect_enabled']);

        $levels = Package::levels((int) $a['id']);
        assert_count(9, $levels);
        assert_same(100.0, $levels[2]);
        assert_same(50.0, $levels[3]);
        assert_same(5.0, $levels[10]);

        $bLevels = Package::levels((int) $b['id']);
        assert_same(120.0, $bLevels[2], 'Package B pays 20% more at level 2');
        assert_same(60.0, $bLevels[3]);
        assert_same(6.0, $bLevels[10]);

        $contents = Package::contents((int) $a['id']);
        assert_count(5, $contents);

        $quantities = array_column($contents, 'quantity', 'name');
        assert_same(1, $quantities['Coffee']);
        assert_same(1, $quantities['Immune Booster']);
        assert_same(2, $quantities['Spirulina']);
        assert_same(1, $quantities['Serpentina']);
        assert_same(2, $quantities['Kojic Soap']);

        /* Package B bundles the service product. */
        $bContents = array_column(Package::contents((int) $b['id']), 'quantity', 'name');
        assert_same(1, $bContents['Colonics Session']);
    },

    'P3-T1 levelsMap batches the ladder for a page' => function (): void {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            Package::all()
        );

        $map = Package::levelsMap($ids);

        assert_count(2, $map);
        foreach ($ids as $id) {
            assert_count(9, $map[$id]);
        }

        $a = Package::findBySlug('package-a');
        $b = Package::findBySlug('package-b');
        assert_not_null($a);
        assert_not_null($b);
        assert_same(100.0, $map[(int) $a['id']][2]);
        assert_same(120.0, $map[(int) $b['id']][2], 'the batch map keeps the Package B premium');

        assert_same([], Package::levelsMap([]));
    },

    'P3-T1 contentsMap joins product details' => function (): void {
        $a = Package::findBySlug('package-a');
        assert_not_null($a);

        $map = Package::contentsMap([(int) $a['id']]);

        assert_count(1, $map);
        $first = $map[(int) $a['id']][0];
        assert_true(isset($first['name'], $first['quantity'], $first['status']));
        assert_same('active', $first['status']);
    },

    'P3-T1 stats count packages by status' => function (): void {
        $stats = Package::stats();

        assert_same(2, $stats['total']);
        assert_same(2, $stats['active']);
        assert_same(0, $stats['inactive']);
    },

    'P3-T1 the admin list filters by search and status' => function (): void {
        $all = Package::adminList([], new Paginator(2, 20, 1));
        assert_count(2, $all);

        $search = Package::adminList(['q' => 'package-b'], new Paginator(1, 20, 1));
        assert_count(1, $search);
        assert_same('Package B', $search[0]['name']);

        assert_same(2, Package::countAdmin([]));
        assert_same(0, Package::countAdmin(['q' => 'nope']));
    },

    /* ---------- Create / update ------------------------------------ */

    'P3-T1 creating a package writes the ladder and contents' => function () use ($packageData, $nineLevels, $coffeeId, $immuneId): void {
        $contents = Package::normalizeContents([
            ['product_id' => $coffeeId(), 'quantity' => 2],
            ['product_id' => $immuneId(), 'quantity' => 1],
        ]);
        assert_same([], $contents['errors']);
        assert_count(2, $contents['rows']);

        $id = Package::save($packageData(['name' => 'Created Package']), $nineLevels(), $contents['rows']);
        $package = Package::find($id);

        assert_not_null($package);
        assert_same('created-package', $package['slug']);
        assert_same('1000.00', $package['price']);
        assert_same(9, count(Package::levels($id)));
        assert_same(100.0, Package::levels($id)[2]);
        assert_count(2, Package::contents($id));
    },

    'P3-T2 editing a level persists and keeps the rest' => function () use ($packageData, $nineLevels): void {
        $a = Package::findBySlug('package-a');
        assert_not_null($a);
        $id = (int) $a['id'];

        $levels = Package::levels($id);
        $levels[3] = 50.0;

        Package::save($packageData([
            'name'                  => (string) $a['name'],
            'price'                 => (string) $a['price'],
            'product_value'         => (string) $a['product_value'],
            'direct_referral_bonus' => (string) $a['direct_referral_bonus'],
            'sort_order'            => (string) $a['sort_order'],
        ]), $levels, []);

        $saved = Package::levels($id);
        assert_same(50.0, $saved[3], 'level 3 keeps the edited amount');
        assert_same(100.0, $saved[2]);
        assert_same(5.0, $saved[10]);
        assert_count(9, $saved);
    },

    'P3-T5 level 1 is the direct bonus and never stored as a level row' => function () use ($packageData): void {
        $id = Package::save($packageData([
            'name'                  => 'Level One Probe',
            'direct_referral_bonus' => '750.00',
        ]), [], []);

        $stmt = db()->prepare('SELECT COUNT(*) FROM package_levels WHERE package_id = ? AND level = 1');
        $stmt->execute([$id]);
        assert_same(0, (int) $stmt->fetchColumn(), 'level 1 must not be stored in package_levels');

        $stmt = db()->prepare('SELECT COUNT(*) FROM package_levels WHERE package_id = ?');
        $stmt->execute([$id]);
        assert_same(9, (int) $stmt->fetchColumn(), 'the ladder holds levels 2–10 only');

        $ladder = PackageService::ladder(
            ['direct_referral_bonus' => '750.00'],
            Package::levels($id)
        );

        assert_count(10, $ladder);
        assert_same(750.0, $ladder[1]['amount']);
        assert_true($ladder[1]['read_only']);
        assert_same('Direct referral', $ladder[1]['label']);
    },

    'P3-T1 slugs stay unique across packages' => function () use ($packageData): void {
        $first  = Package::save($packageData(['name' => 'Twin Package', 'price' => '10.00']), [], []);
        $second = Package::save($packageData(['name' => 'Twin Package', 'price' => '20.00']), [], []);

        assert_same('twin-package', Package::find($first)['slug']);
        assert_same('twin-package-2', Package::find($second)['slug']);
    },

    'P3-T3 invalid package fields are rejected' => function (): void {
        $errors = Package::validate([
            'name'                  => 'X',
            'price'                 => '0',
            'direct_referral_bonus' => '-5',
            'product_value'         => 'abc',
            'status'                => 'bogus',
        ]);

        assert_true(isset($errors['name']), 'short name must fail');
        assert_true(isset($errors['price']), 'zero price must fail');
        assert_true(isset($errors['direct_referral_bonus']), 'negative bonus must fail');
        assert_true(isset($errors['product_value']), 'non-numeric product value must fail');
        assert_true(isset($errors['status']), 'unknown status must fail');
    },

    'P3-T3 level amounts reject negatives, 3 decimals and over the cap' => function (): void {
        $negative = Package::validateLevels([2 => '-5']);
        assert_true(isset($negative['level_2']));

        $decimals = Package::validateLevels([3 => '1.234']);
        assert_true(isset($decimals['level_3']));

        $tooLarge = Package::validateLevels([4 => '1000000.01']);
        assert_true(isset($tooLarge['level_4']));

        $notNumeric = Package::validateLevels([5 => 'lots']);
        assert_true(isset($notNumeric['level_5']));

        assert_same([], Package::validateLevels([2 => '', 3 => '0', 4 => '1000000']));
    },

    'P3-T3 a failed save writes nothing' => function () use ($packageData, $nineLevels): void {
        /* The harness opens a transaction per case; this case needs its own
         * so Package::save() owns the transaction it rolls back. */
        if (db()->inTransaction()) {
            db()->commit();
        }

        $before = (int) db()->query('SELECT COUNT(*) FROM packages')->fetchColumn();

        assert_throws(
            static fn () => Package::save(
                $packageData(['name' => 'Rollback Probe']),
                $nineLevels(),
                [['product_id' => 999999, 'quantity' => 1]]   // FK violation
            ),
            PDOException::class
        );

        $after = (int) db()->query('SELECT COUNT(*) FROM packages')->fetchColumn();
        assert_same($before, $after, 'the failed package insert must be rolled back');

        $stmt = db()->prepare('SELECT COUNT(*) FROM packages WHERE name = ?');
        $stmt->execute(['Rollback Probe']);
        assert_same(0, (int) $stmt->fetchColumn());

        $orphanLevels = db()->query(
            'SELECT COUNT(*) FROM package_levels pl
              LEFT JOIN packages p ON p.id = pl.package_id
             WHERE p.id IS NULL'
        )->fetchColumn();
        assert_same(0, (int) $orphanLevels, 'no orphan ladder rows after a rollback');
    },

    /* ---------- Contents (P3-T4) ----------------------------------- */

    'P3-T4 blank rows are dropped and duplicates merged' => function () use ($coffeeId): void {
        $result = Package::normalizeContents([
            ['product_id' => $coffeeId(), 'quantity' => 2],
            ['product_id' => 0, 'quantity' => 1],
            ['product_id' => $coffeeId(), 'quantity' => 3],
        ]);

        assert_same([], $result['errors']);
        assert_count(1, $result['rows']);
        assert_same($coffeeId(), $result['rows'][0]['product_id']);
        assert_same(5, $result['rows'][0]['quantity']);
    },

    'P3-T4 inactive products cannot be added to contents' => function () use ($packageData): void {
        $productId = Product::save([
            'name'   => 'Retired Product',
            'price'  => '10.00',
            'stock'  => '1',
            'status' => 'inactive',
        ]);

        $result = Package::normalizeContents([['product_id' => $productId, 'quantity' => 1]]);

        assert_true(isset($result['errors']['contents']));
        assert_contains('inactive', $result['errors']['contents']);
        assert_same([], $result['rows']);
    },

    'P3-T4 an already-linked inactive product may stay in its package' => function (): void {
        $coffee = Product::findBySlug('coffee');
        $a      = Package::findBySlug('package-a');
        assert_not_null($coffee);
        assert_not_null($a);

        Product::setStatus((int) $coffee['id'], 'inactive');

        try {
            $result = Package::normalizeContents(
                [['product_id' => (int) $coffee['id'], 'quantity' => 1]],
                (int) $a['id']
            );

            assert_same([], $result['errors']);
            assert_count(1, $result['rows']);
        } finally {
            Product::setStatus((int) $coffee['id'], 'active');
        }
    },

    'P3-T4 content quantities must be between 1 and 1000' => function () use ($coffeeId): void {
        $zero = Package::normalizeContents([['product_id' => $coffeeId(), 'quantity' => 0]]);
        assert_true(isset($zero['errors']['contents']));

        $tooMany = Package::normalizeContents([['product_id' => $coffeeId(), 'quantity' => 1001]]);
        assert_true(isset($tooMany['errors']['contents']));
    },

    'P3-T4 content options are the active catalogue plus linked products' => function (): void {
        $options = PackageService::contentOptions();

        $names = array_column($options, 'name');
        assert_true(in_array('Coffee', $names, true));
        assert_false(in_array('Retired Product', $names, true));

        $linked = PackageService::contentOptions((int) (Package::findBySlug('package-a')['id'] ?? 0));
        assert_true($linked !== []);
    },

    /* ---------- Status + public cards (P3-T6) ---------------------- */

    'P3-T6 setStatus toggles packages and refuses unknown statuses' => function (): void {
        $a = Package::findBySlug('package-a');
        assert_not_null($a);

        assert_true(Package::setStatus((int) $a['id'], 'inactive'));
        assert_same('inactive', Package::find((int) $a['id'])['status']);

        assert_true(Package::setStatus((int) $a['id'], 'active'));
        assert_same('active', Package::find((int) $a['id'])['status']);

        assert_throws(static fn () => Package::setStatus((int) $a['id'], 'archived'), InvalidArgumentException::class);
        assert_false(Package::setStatus(999999, 'active'));
    },

    'P3-T6 deactivated packages disappear from the public cards' => function (): void {
        $b = Package::findBySlug('package-b');
        assert_not_null($b);

        Package::setStatus((int) $b['id'], 'inactive');

        $cards = PackageService::publicCards();

        assert_count(1, $cards);
        assert_same('Package A', $cards[0]['name']);
        assert_count(1, Package::all(true));
    },

    /* ---------- Service composition -------------------------------- */

    'P3-T8 public cards expose the ladder, totals and contents' => function (): void {
        $cards = PackageService::publicCards();
        assert_count(2, $cards);

        $a = $cards[0];
        assert_same('Package A', $a['name']);
        assert_count(10, $a['ladder']);
        assert_same(10, $a['funded_levels']);
        assert_same(500.0, $a['ladder'][1]['amount']);
        assert_same(765.0, $a['ladder_total']);   // 500 + 250 across levels 2–10
        assert_count(5, $a['contents']);
    },

    'P3-T8 levelsFromInput normalises the posted matrix' => function (): void {
        $levels = PackageService::levelsFromInput([2 => '100', 3 => '', 4 => ' 5.50 ', 11 => '999']);

        assert_count(9, $levels);
        assert_same('100', $levels[2]);
        assert_same('', $levels[3]);
        assert_same('5.50', $levels[4]);
        assert_true(!array_key_exists(11, $levels));
    },

    'P3-T8 contentsFromInput zips the repeater arrays' => function (): void {
        $rows = PackageService::contentsFromInput([5, 7, ''], [2, 1]);

        assert_count(3, $rows);
        assert_same(['product_id' => 5, 'quantity' => 2], $rows[0]);
        assert_same(['product_id' => 7, 'quantity' => 1], $rows[1]);
        assert_same(['product_id' => '', 'quantity' => 0], $rows[2]);
    },
];
