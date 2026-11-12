<?php
declare(strict_types=1);

/* ==============================================================
 *  InstallSqlTest — the production bundle matches the real install
 *  (plan IR-2-T7)
 *
 *  The bundle is imported through SqlScript into the pre-provisioned
 *  scratch schema ecowealth_sneat_tool_a_test, then compared with the
 *  harness's fresh DB_NAME (schema.sql + all five seeds):
 *
 *      build parity   committed bytes == a fresh build (--check)
 *      schema parity  tables, columns, indexes, foreign keys
 *      data parity    settings, catalogue by natural key, staff
 *      safety         a second import is refused by the guard
 *
 *  Row counts are compared through natural keys (sku, slug, level),
 *  never ids: an import and a reset may legitimately use different id
 *  sequences (D-IR-15).
 * ============================================================== */

$appRoot     = dirname(__DIR__, 2);
$databaseDir = $appRoot . '/database/';
$installPath = $databaseDir . 'install.sql';

require_once $appRoot . '/bin/build_install_sql.php';

/** Import the bundle into the scratch slot and return [pdo, schema, result]. */
$importBundle = static function () use ($installPath): array {
    [$pdo, $database] = test_scratch_database('tool_a');
    $result           = SqlScript::fromFile($installPath)->run($pdo);

    return [$pdo, $database, $result];
};

/** Two-column result as key => value. */
$keyPairs = static function (PDO $pdo, string $sql): array {
    return $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
};

return [
    'the committed bundle matches a fresh build byte for byte' => function () use ($appRoot, $installPath): void {
        $result = build_install_sql_check($appRoot);

        assert_true(
            $result['ok'],
            'database/install.sql is stale'
                . ($result['line'] === null ? '' : ' (first difference at line ' . $result['line'] . ')')
                . '; rebuild with: php bin/build_install_sql.php'
        );

        assert_same('database/install.sql', $result['path']);

        $bytes = filesize($installPath);
        assert_true($bytes !== false && $bytes > 40 * 1024, 'the bundle carries the schema and seeds');
        assert_true($bytes !== false && $bytes < 100 * 1024, 'the bundle stays under 100 KB');

        assert_false(SqlScript::fromFile($installPath)->containsDatabaseSwitch());
    },

    'the import matches the fresh schema, table by table' => function () use ($importBundle): void {
        [$pdo, $database, $result] = $importBundle();

        assert_true($result['statements'] >= 40, 'the bundle ran every section');

        $harness = test_pdo(['db' => DB_NAME]);

        $tables = test_table_list($pdo, $database);
        assert_count(31, $tables);
        assert_same(test_table_list($harness, DB_NAME), $tables, 'table list');

        assert_same(
            test_column_list($harness, DB_NAME),
            test_column_list($pdo, $database),
            'every table has the same columns, types, defaults and collation'
        );

        assert_same(
            test_index_list($harness, DB_NAME),
            test_index_list($pdo, $database),
            'every table has the same indexes'
        );

        assert_same(
            test_foreign_key_list($harness, DB_NAME),
            test_foreign_key_list($pdo, $database),
            'every foreign key has the same columns, target and delete rule'
        );
    },

    'the import carries every setting and the three install_* keys' => function () use ($importBundle, $keyPairs): void {
        [$pdo] = $importBundle();

        $harness = $keyPairs(test_pdo(['db' => DB_NAME]), 'SELECT key_name, value FROM settings ORDER BY key_name');
        $import  = $keyPairs($pdo, 'SELECT key_name, value FROM settings ORDER BY key_name');

        assert_true(count($harness) >= 51, 'the harness fresh install carries at least 51 settings');
        assert_count(54, $import, '51 seed keys + 3 install metadata keys');

        foreach ($harness as $key => $value) {
            assert_true(array_key_exists($key, $import), 'the import carries settings.' . $key);

            if ($key === 'commission_ledger_since' || $key === 'unilevel_ledger_since') {
                continue;   // inserted with NOW() on each side; compare the shape only
            }

            assert_same($value, $import[$key], 'settings.' . $key);
        }

        assert_same(
            1,
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $import['commission_ledger_since']),
            'commission_ledger_since is a MySQL datetime (F-01)'
        );
        assert_same(
            1,
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $import['unilevel_ledger_since']),
            'unilevel_ledger_since is a MySQL datetime baseline'
        );

        assert_same('1.0.0', $import['install_version']);
        assert_same('install.sql', $import['install_channel']);
        assert_same(
            1,
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $import['install_completed_at']),
            'install_completed_at is a MySQL datetime'
        );

        /* No fourth metadata key, no stray setting: the bundle adds exactly
         * the three install_* rows on top of the seed keys. */
        $extra = array_diff(
            array_keys($import),
            array_keys($harness),
            ['install_version', 'install_channel', 'install_completed_at']
        );

        assert_same([], array_values($extra), 'the bundle adds no unexpected settings');
    },

    'the catalogue matches by natural key (7 / 2 / 18 / 10)' => function () use ($importBundle): void {
        [$pdo] = $importBundle();

        $harness = test_pdo(['db' => DB_NAME]);

        $products = static fn (PDO $connection): array => $connection->query(
            'SELECT sku, name, slug, price, stock, status, sort_order
               FROM products ORDER BY sku'
        )->fetchAll();

        $importProducts = $products($pdo);

        assert_count(7, $importProducts, 'seven starter SKUs (six catalogue rows + EW-COLONICS)');
        assert_same($products($harness), $importProducts, 'products by SKU');

        assert_same(
            ['EW-COFFEE', 'EW-COLONICS', 'EW-IMMUNE', 'EW-KOJIC', 'EW-SERPENTINA', 'EW-SPIRULINA', 'EW-STONE'],
            array_column($importProducts, 'sku')
        );

        $packages = static fn (PDO $connection): array => $connection->query(
            'SELECT slug, name, price, product_value, direct_referral_bonus,
                    indirect_enabled, status, sort_order
               FROM packages ORDER BY slug'
        )->fetchAll();

        $importPackages = $packages($pdo);

        assert_count(2, $importPackages);
        assert_same($packages($harness), $importPackages, 'packages by slug');
        assert_same(['package-a', 'package-b'], array_column($importPackages, 'slug'));

        $levels = static fn (PDO $connection): array => $connection->query(
            'SELECT p.slug, l.level, l.amount
               FROM package_levels AS l
               JOIN packages AS p ON p.id = l.package_id
              ORDER BY p.slug, l.level'
        )->fetchAll();

        $importLevels = $levels($pdo);

        assert_count(18, $importLevels, 'nine indirect levels per package');
        assert_same($levels($harness), $importLevels, 'ladder by package slug + level');

        $contents = static fn (PDO $connection): array => $connection->query(
            'SELECT p.slug, pr.sku, pp.quantity
               FROM package_products AS pp
               JOIN packages AS p ON p.id = pp.package_id
               JOIN products AS pr ON pr.id = pp.product_id
              ORDER BY p.slug, pr.sku'
        )->fetchAll();

        $importContents = $contents($pdo);

        assert_count(10, $importContents, 'five contents per package');
        assert_same($contents($harness), $importContents, 'contents by package slug + SKU');

        /* The two seeded staff rows are not members and carry no wallet. */
        $members = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
        assert_same(0, $members, 'the production bundle seeds no demo members');

        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM reg_codes')->fetchColumn(),
            'the production bundle seeds no registration codes'
        );
    },

    'the import seeds exactly two active staff accounts and no demo rows' => function () use ($importBundle): void {
        [$pdo] = $importBundle();

        $users = $pdo->query(
            'SELECT username, role, member_status, is_active, package_id, sponsor_id,
                    last_login_at, ewallet_balance, withdrawable_balance
               FROM users ORDER BY id'
        )->fetchAll();

        assert_count(2, $users);

        assert_same('admin', $users[0]['username']);
        assert_same('admin', $users[0]['role']);
        assert_same('superadmin', $users[1]['username']);
        assert_same('superadmin', $users[1]['role']);

        foreach ($users as $user) {
            assert_same('active', $user['member_status'], $user['username'] . ' is active (F-09)');
            assert_same(1, (int) $user['is_active'], $user['username'] . ' can sign in');
            assert_null($user['package_id'], $user['username'] . ' has no package');
            assert_null($user['sponsor_id'], $user['username'] . ' has no sponsor');
            assert_null($user['last_login_at'], $user['username'] . ' has never signed in');
            assert_same(0.0, (float) $user['ewallet_balance']);
            assert_same(0.0, (float) $user['withdrawable_balance']);
        }

        /* Demo leakage check (F-07) and the transactional zero-pack. */
        $demoUsers = (int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE username IN ('juan','maria','peter','sarah','david')"
        )->fetchColumn();
        assert_same(0, $demoUsers, 'no demo tree in the production bundle');

        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM reg_codes WHERE code LIKE 'DEMO-%'")->fetchColumn());

        foreach ([
            'addresses', 'activations', 'audit_log', 'cart_items', 'carts', 'commissions',
            'ewallet_admin_topups', 'ewallet_ledger', 'ewallet_transfers', 'impersonation_log',
            'live_revisions', 'payout_requests', 'reg_codes', 'remember_tokens',
            'shop_invoice_items', 'shop_invoices', 'shop_order_events', 'shop_order_items',
            'shop_orders', 'shop_payment_proofs', 'shop_shipments',
        ] as $table) {
            assert_same(
                0,
                (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(),
                $table . ' is empty after a fresh install'
            );
        }
    },

    'a second import is refused by the guard and changes nothing' => function () use ($installPath): void {
        [$pdo, $database] = test_scratch_database('tool_a');

        SqlScript::fromFile($installPath)->run($pdo);

        $error = assert_throws(
            static fn (): array => SqlScript::fromFile($installPath)->run($pdo),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_SQL, $error->kind);
        assert_same(1146, (int) $error->context['driver_code'], 'the guard names a missing table');
        assert_contains('statement', $error->getMessage());

        /* Nothing after the guard ran: still 31 tables, 54 settings, 2 staff. */
        assert_count(31, test_table_list($pdo, $database));
        assert_same(54, (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

        $probe = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
        );
        $probe->execute([$database, 'probe']);
        assert_same(0, (int) $probe->fetchColumn());
    },
];
