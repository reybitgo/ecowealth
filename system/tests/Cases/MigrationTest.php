<?php
declare(strict_types=1);

/* ==============================================================
 *  MigrationTest — database/migrations/001_settings_roles.sql
 *
 *  Builds a pre-Phase-1 database from the fixture, applies every
 *  phase migration in order, and checks that the result matches a
 *  fresh install of database/schema.sql. Uses its own schema
 *  (ecowealth_sneat_migration_test) so it never disturbs the app
 *  schema.
 * ============================================================== */

$migrationDir  = dirname(__DIR__, 2) . '/database/migrations/';
$migrations    = [
    $migrationDir . '001_settings_roles.sql',
    $migrationDir . '002_products.sql',
    $migrationDir . '003_packages.sql',
    $migrationDir . '004_carts.sql',
    $migrationDir . '005_wallets.sql',
    $migrationDir . '006_orders.sql',
    $migrationDir . '007_payout_accounts.sql',
    $migrationDir . '008_payment_proofs.sql',
    $migrationDir . '009_shop_expiry.sql',
    $migrationDir . '010_fulfilment.sql',
    $migrationDir . '011_membership.sql',
    $migrationDir . '012_commissions.sql',
    $migrationDir . '013_live_revisions.sql',
    $migrationDir . '014_indirect_commissions.sql',
    $migrationDir . '015_package_b_premium_ladder.sql',
    $migrationDir . '016_transfers.sql',
    $migrationDir . '017_payouts.sql',
    $migrationDir . '018_impersonation.sql',
    $migrationDir . '019_audit_and_reporting.sql',
    $migrationDir . '020_unilevel_repeat_purchase.sql',
];
$fixtureFile   = __DIR__ . '/../fixtures/legacy_phase0_schema.sql';

/** Fresh legacy database with every phase migration applied in order. */
$buildMigrated = static function () use ($migrations, $fixtureFile): PDO {
    $pdo = test_pdo();
    test_recreate_database($pdo, TEST_MIGRATION_DB);

    test_run_sql_file($pdo, $fixtureFile);

    foreach ($migrations as $migration) {
        test_run_sql_file($pdo, $migration);
    }

    return $pdo;
};

return [
    'migration renames user → member and adds superadmin' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        $roles = $pdo->query('SELECT username, role FROM users ORDER BY id')->fetchAll();
        assert_count(2, $roles);
        assert_same('member', $roles[0]['role'], 'legacy "user" row');
        assert_same('admin', $roles[1]['role'], 'legacy admin row');

        $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        assert_contains("'member'", $column['Type']);
        assert_contains("'admin'", $column['Type']);
        assert_contains("'superadmin'", $column['Type']);
        assert_not_contains("'user'", $column['Type'], 'the old role value must be gone');
    },

    'migration keeps every account and its password hash' => function () use ($buildMigrated, $fixtureFile): void {
        /* Capture the fixture rows before the upgrade… */
        $legacy = test_pdo();
        test_recreate_database($legacy, TEST_MIGRATION_DB);
        test_run_sql_file($legacy, $fixtureFile);

        $before = $legacy->query('SELECT id, username, email, password_hash FROM users ORDER BY id')->fetchAll();
        assert_count(2, $before);

        /* …then compare with the same data after the migration. */
        $after = $buildMigrated()
            ->query('SELECT id, username, email, password_hash FROM users ORDER BY id')
            ->fetchAll();

        assert_same($before, $after, 'accounts must survive the migration untouched');
    },

    'migration creates settings, audit_log and the catalogue tables' => function () use ($buildMigrated): void {
        $pdo    = $buildMigrated();
        $tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);

        foreach ([
            'settings', 'audit_log', 'products', 'packages', 'package_levels', 'package_products',
            'carts', 'cart_items', 'ewallet_ledger', 'ewallet_admin_topups',
            'addresses', 'shop_orders', 'shop_order_items', 'shop_order_events',
            'shop_payment_proofs', 'shop_shipments', 'shop_invoices', 'shop_invoice_items',
            'reg_codes', 'activations', 'commissions', 'ewallet_transfers', 'live_revisions',
            'payout_requests',
            'impersonation_log',
        ] as $table) {
            assert_true(in_array($table, $tables, true), $table . ' table missing');
        }

        $pdo->exec("INSERT INTO settings (key_name, value) VALUES ('probe', '1')");
        assert_same('1', $pdo->query("SELECT value FROM settings WHERE key_name = 'probe'")->fetchColumn());

        $pdo->exec(
            "INSERT INTO products (sku, name, slug, price, stock)
             VALUES ('MIG-1', 'Migrated product', 'migrated-product', 10.00, 5)"
        );
        assert_same('Migrated product', $pdo->query("SELECT name FROM products WHERE sku = 'MIG-1'")->fetchColumn());

        $pdo->exec(
            "INSERT INTO packages (name, slug, price, direct_referral_bonus)
             VALUES ('Migrated package', 'migrated-package', 100.00, 10.00)"
        );
        $packageId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO package_levels (package_id, level, amount) VALUES ({$packageId}, 2, 5.00)");
        $pdo->exec(
            "INSERT INTO package_products (package_id, product_id, quantity)
             VALUES ({$packageId}, (SELECT id FROM products WHERE sku = 'MIG-1'), 2)"
        );

        assert_same(5.0, (float) $pdo->query("SELECT amount FROM package_levels WHERE package_id = {$packageId}")->fetchColumn());
        assert_same(2, (int) $pdo->query("SELECT quantity FROM package_products WHERE package_id = {$packageId}")->fetchColumn());

        /* Phase 4: a migrated cart accepts a line against the migrated product. */
        $userId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();
        $pdo->exec("INSERT INTO carts (user_id, status) VALUES ({$userId}, 'active')");
        $cartId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO cart_items (cart_id, product_id, quantity, unit_price)
             VALUES ({$cartId}, (SELECT id FROM products WHERE sku = 'MIG-1'), 3, 10.00)"
        );

        assert_same(3, (int) $pdo->query("SELECT quantity FROM cart_items WHERE cart_id = {$cartId}")->fetchColumn());

        /* Phase 5: both buckets start at zero on existing accounts, and the
         * migrated ledger + top-up tables accept a movement. */
        $buckets = $pdo->query("SELECT ewallet_balance, withdrawable_balance FROM users WHERE id = {$userId}")->fetch();
        assert_same('0.00', (string) $buckets['ewallet_balance']);
        assert_same('0.00', (string) $buckets['withdrawable_balance']);

        $adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_admin'")->fetchColumn();

        $pdo->exec("UPDATE users SET ewallet_balance = 25.00, withdrawable_balance = 10.00 WHERE id = {$userId}");
        $pdo->exec(
            "INSERT INTO ewallet_ledger
               (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
             VALUES ({$userId}, 'credit', 25.00, 25.00, 10.00, 'topup', 1, 'migration probe')"
        );
        $pdo->exec(
            "INSERT INTO ewallet_admin_topups (admin_id, recipient_id, amount, note)
             VALUES ({$adminId}, {$userId}, 25.00, 'migration probe')"
        );

        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = {$userId}")->fetchColumn());
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM ewallet_admin_topups WHERE recipient_id = {$userId}")->fetchColumn());

        /* Phase 6: the migrated order tables accept an address, an order,
         * a line and its event. */
        $pdo->exec(
            "INSERT INTO addresses (user_id, recipient_name, address_line)
             VALUES ({$userId}, 'Legacy Member', '1 Migration Street')"
        );
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM addresses WHERE user_id = {$userId}")->fetchColumn());

        $pdo->exec(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, billing_name, shipping_name, shipping_address,
                subtotal, total, terms_accepted_at)
             VALUES ('EWT-MIG-1', {$userId}, 'pending', 'gcash', 'Legacy Member', 'Legacy Member',
                     '1 Migration Street', 10.00, 10.00, NOW())"
        );
        $orderId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total, reservation_state)
             VALUES ({$orderId}, (SELECT id FROM products WHERE sku = 'MIG-1'), 'Migrated product', 2, 5.00, 10.00, 'reserved')"
        );
        $pdo->exec(
            "INSERT INTO shop_order_events (order_id, from_status, to_status, actor_type, actor_id, reason_code)
             VALUES ({$orderId}, NULL, 'pending', 'member', {$userId}, 'order_placed')"
        );

        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM shop_order_items WHERE order_id = {$orderId}")->fetchColumn());
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM shop_order_events WHERE order_id = {$orderId}")->fetchColumn());

        /* Phase 6/13: the migrated users table accepts the payout accounts. */
        $pdo->exec(
            "UPDATE users
                SET gcash_number = '09171234567',
                    maya_number = '09179876543',
                    usdt_trc20_address = 'T111111111111111111111111111111111',
                    usdt_bep20_address = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
              WHERE id = {$userId}"
        );

        $payout = $pdo->query(
            "SELECT gcash_number, maya_number, usdt_trc20_address, usdt_bep20_address
               FROM users WHERE id = {$userId}"
        )->fetch();

        assert_same('09171234567', $payout['gcash_number']);
        assert_same('09179876543', $payout['maya_number']);
        assert_same('T111111111111111111111111111111111', $payout['usdt_trc20_address']);
        assert_same('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $payout['usdt_bep20_address']);
    },

    'migration adds the role index' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        $index = $pdo->query(
            "SHOW INDEX FROM users WHERE Key_name = 'idx_users_role'"
        )->fetchAll();

        assert_true($index !== [], 'idx_users_role missing');
    },

    'migration is idempotent' => function () use ($buildMigrated, $migrations): void {
        $pdo = $buildMigrated();

        /* Re-running must be safe (CREATE ... IF NOT EXISTS, guarded index). */
        foreach ($migrations as $migration) {
            test_run_sql_file($pdo, $migration);
        }

        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        assert_same('member', $pdo->query("SELECT role FROM users WHERE username = 'legacy_user'")->fetchColumn());
    },

    'migrated schema matches a fresh install' => function () use ($buildMigrated): void {
        $migrated = $buildMigrated();
        $fresh    = test_pdo(['db' => DB_NAME]);

        $tableList = static function (PDO $pdo): array {
            $tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
            sort($tables);

            return $tables;
        };

        assert_same($tableList($fresh), $tableList($migrated), 'table list differs from schema.sql');

        $columnList = static function (PDO $pdo, string $table): array {
            $columns = [];
            foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll() as $column) {
                $columns[] = $column['Field'] . ' ' . $column['Type'] . ' ' . $column['Null'] . ' ' . $column['Default'] . ' ' . $column['Extra'];
            }

            return $columns;
        };

        assert_same($columnList($fresh, 'users'), $columnList($migrated, 'users'), 'users columns differ from schema.sql');
        assert_same($columnList($fresh, 'products'), $columnList($migrated, 'products'), 'products columns differ from schema.sql');
        assert_same($columnList($fresh, 'packages'), $columnList($migrated, 'packages'), 'packages columns differ from schema.sql');
        assert_same($columnList($fresh, 'package_levels'), $columnList($migrated, 'package_levels'), 'package_levels columns differ from schema.sql');
        assert_same($columnList($fresh, 'package_products'), $columnList($migrated, 'package_products'), 'package_products columns differ from schema.sql');
        assert_same($columnList($fresh, 'carts'), $columnList($migrated, 'carts'), 'carts columns differ from schema.sql');
        assert_same($columnList($fresh, 'cart_items'), $columnList($migrated, 'cart_items'), 'cart_items columns differ from schema.sql');
        assert_same($columnList($fresh, 'ewallet_ledger'), $columnList($migrated, 'ewallet_ledger'), 'ewallet_ledger columns differ from schema.sql');
        assert_same($columnList($fresh, 'ewallet_admin_topups'), $columnList($migrated, 'ewallet_admin_topups'), 'ewallet_admin_topups columns differ from schema.sql');
        assert_same($columnList($fresh, 'addresses'), $columnList($migrated, 'addresses'), 'addresses columns differ from schema.sql');
        assert_same($columnList($fresh, 'shop_orders'), $columnList($migrated, 'shop_orders'), 'shop_orders columns differ from schema.sql');
        assert_same($columnList($fresh, 'shop_order_items'), $columnList($migrated, 'shop_order_items'), 'shop_order_items columns differ from schema.sql');
        assert_same($columnList($fresh, 'shop_order_events'), $columnList($migrated, 'shop_order_events'), 'shop_order_events columns differ from schema.sql');
        assert_same($columnList($fresh, 'shop_payment_proofs'), $columnList($migrated, 'shop_payment_proofs'), 'shop_payment_proofs columns differ from schema.sql');
        assert_same($columnList($fresh, 'shop_shipments'), $columnList($migrated, 'shop_shipments'), 'shop_shipments columns differ from schema.sql');
        assert_same($columnList($fresh, 'shop_invoices'), $columnList($migrated, 'shop_invoices'), 'shop_invoices columns differ from schema.sql');
        assert_same($columnList($fresh, 'shop_invoice_items'), $columnList($migrated, 'shop_invoice_items'), 'shop_invoice_items columns differ from schema.sql');
        assert_same($columnList($fresh, 'reg_codes'), $columnList($migrated, 'reg_codes'), 'reg_codes columns differ from schema.sql');
        assert_same($columnList($fresh, 'activations'), $columnList($migrated, 'activations'), 'activations columns differ from schema.sql');
        assert_same($columnList($fresh, 'commissions'), $columnList($migrated, 'commissions'), 'commissions columns differ from schema.sql');
        assert_same($columnList($fresh, 'ewallet_transfers'), $columnList($migrated, 'ewallet_transfers'), 'ewallet_transfers columns differ from schema.sql');
        assert_same($columnList($fresh, 'payout_requests'), $columnList($migrated, 'payout_requests'), 'payout_requests columns differ from schema.sql');
        assert_same($columnList($fresh, 'impersonation_log'), $columnList($migrated, 'impersonation_log'), 'impersonation_log columns differ from schema.sql');
        assert_same($columnList($fresh, 'live_revisions'), $columnList($migrated, 'live_revisions'), 'live_revisions columns differ from schema.sql');
        assert_same($columnList($fresh, 'audit_log'), $columnList($migrated, 'audit_log'), 'audit_log columns differ from schema.sql');
        assert_same($columnList($fresh, 'product_unilevel_levels'), $columnList($migrated, 'product_unilevel_levels'), 'product_unilevel_levels columns differ from schema.sql');
        assert_same($columnList($fresh, 'unilevel_awards'), $columnList($migrated, 'unilevel_awards'), 'unilevel_awards columns differ from schema.sql');
        assert_same($columnList($fresh, 'unilevel_activity'), $columnList($migrated, 'unilevel_activity'), 'unilevel_activity columns differ from schema.sql');
        assert_same($columnList($fresh, 'unilevel_periods'), $columnList($migrated, 'unilevel_periods'), 'unilevel_periods columns differ from schema.sql');
    },

    'migration 019 adds the reporting indexes and re-runs cleanly' => function () use ($buildMigrated, $migrationDir): void {
        $pdo   = $buildMigrated();
        $fresh = test_pdo(['db' => DB_NAME]);

        $indexNames = static function (PDO $pdo, string $table): array {
            $rows = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll();

            return array_values(array_unique(array_map(
                static fn (array $row): string => (string) $row['Key_name'],
                $rows
            )));
        };

        foreach ([
            'audit_log'      => ['idx_audit_action', 'idx_audit_created'],
            'shop_orders'    => ['idx_orders_created', 'idx_orders_paid'],
            'ewallet_ledger' => ['idx_ewallet_created'],
            'commissions'    => ['idx_commissions_level'],
            'reg_codes'      => ['idx_reg_codes_created'],
        ] as $table => $expected) {
            $migratedNames = $indexNames($pdo, $table);
            $freshNames    = $indexNames($fresh, $table);

            foreach ($expected as $index) {
                assert_true(
                    in_array($index, $migratedNames, true),
                    $table . '.' . $index . ' missing after the migration'
                );
                assert_true(
                    in_array($index, $freshNames, true),
                    $table . '.' . $index . ' missing from schema.sql'
                );
            }
        }

        /* Applying 019 a second time is a no-op (plan §9.3). */
        test_run_sql_file($pdo, $migrationDir . '019_audit_and_reporting.sql');
        assert_true(in_array('idx_orders_created', $indexNames($pdo, 'shop_orders'), true));

        /* The audit desk can read what every audited write records. */
        $pdo->exec(
            "INSERT INTO audit_log (actor_id, actor_role, action, subject_type, subject_id, meta_json, ip)
             VALUES (NULL, 'admin', 'settings.update', 'settings', NULL, '{\"section\":\"shop\",\"changes\":{}}', '127.0.0.1')"
        );

        $row = $pdo->query("SELECT action, meta_json FROM audit_log WHERE action = 'settings.update'")->fetch();
        assert_same('settings.update', $row['action']);

        /* MySQL normalises a JSON column, so decode it rather than
         * compare the raw text. */
        $meta = json_decode((string) $row['meta_json'], true);
        assert_same('shop', $meta['section'] ?? null);
    },

    'P17A-T3 migration 020 adds the repeat-purchase objects, defaults off and re-runs cleanly' => function () use ($buildMigrated, $migrationDir): void {
        $pdo   = $buildMigrated();
        $fresh = test_pdo(['db' => DB_NAME]);

        /* ---- 1. the four tables and their columns -------------------- */
        $tables = test_table_list($pdo);

        foreach ([
            'product_unilevel_levels',
            'unilevel_awards',
            'unilevel_activity',
            'unilevel_periods',
        ] as $table) {
            assert_true(in_array($table, $tables, true), $table . ' is missing after migration 020');
            assert_same(
                test_column_list($fresh, DB_NAME)[$table],
                test_column_list($pdo)[$table],
                $table . ' columns differ from schema.sql'
            );
            assert_same(
                test_index_list($fresh, DB_NAME)[$table],
                test_index_list($pdo)[$table],
                $table . ' indexes differ from schema.sql'
            );
            assert_same(
                test_foreign_key_list($fresh, DB_NAME)[$table],
                test_foreign_key_list($pdo)[$table],
                $table . ' foreign keys differ from schema.sql'
            );
        }

        /* ---- 2. the four altered tables ------------------------------ */
        $columns = static function (PDO $pdo, string $table): array {
            return array_column($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
        };

        foreach ([
            'products'         => ['unilevel_enabled', 'unilevel_base'],
            'shop_orders'      => ['unilevel_processed_at', 'unilevel_total'],
            'shop_order_items' => ['unit_base', 'base_total'],
        ] as $table => $expected) {
            $actual = $columns($pdo, $table);

            foreach ($expected as $column) {
                assert_true(in_array($column, $actual, true), $table . '.' . $column . ' is missing');
            }
        }

        /* The two new order indexes exist on both sides. */
        $orderIndexes = array_map(
            static fn (array $row): string => (string) $row['Key_name'],
            $pdo->query('SHOW INDEX FROM shop_orders')->fetchAll()
        );
        $freshIndexes = array_map(
            static fn (array $row): string => (string) $row['Key_name'],
            $fresh->query('SHOW INDEX FROM shop_orders')->fetchAll()
        );

        foreach (['idx_orders_unilevel', 'idx_orders_user_paid'] as $index) {
            assert_true(in_array($index, $orderIndexes, true), 'shop_orders.' . $index . ' missing after the migration');
            assert_true(in_array($index, $freshIndexes, true), 'shop_orders.' . $index . ' missing from schema.sql');
        }

        /* ---- 3. the extended ledger enum ----------------------------- */
        $enum = (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'];
        assert_contains("'unilevel'", $enum);
        assert_contains("'unilevel_reversal'", $enum);
        assert_same(
            (string) $fresh->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'],
            $enum,
            'the migrated enum must equal the fresh-install enum'
        );

        /* ---- 4. the six settings, off by default --------------------- */
        assert_same(6, (int) $pdo->query(
            "SELECT COUNT(*) FROM settings WHERE key_name LIKE 'unilevel%'"
        )->fetchColumn());

        foreach ([
            'unilevel_enabled'                => '0',
            'unilevel_max_levels'             => '10',
            'unilevel_requires_active_upline' => '1',
            'unilevel_active_threshold'       => '500',
            'unilevel_reversal_enabled'       => '1',
        ] as $key => $value) {
            assert_same(
                $value,
                (string) $pdo->query("SELECT value FROM settings WHERE key_name = '{$key}'")->fetchColumn(),
                'settings.' . $key
            );
        }

        assert_same(
            1,
            preg_match(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                (string) $pdo->query("SELECT value FROM settings WHERE key_name = 'unilevel_ledger_since'")->fetchColumn()
            ),
            'unilevel_ledger_since is a MySQL datetime'
        );

        /* Every existing product keeps the master switch off and the
         * default base (D-11: a migration never starts paying). */
        $products = $pdo->query('SELECT unilevel_enabled, unilevel_base FROM products')->fetchAll();

        foreach ($products as $product) {
            assert_same(0, (int) $product['unilevel_enabled'], 'every migrated product starts switched off');
            assert_same('auto', (string) $product['unilevel_base']);
        }

        /* ---- 5. applying 020 again is a no-op ------------------------ */
        $before = [
            'tables'   => test_table_list($pdo),
            'settings' => (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
            'columns'  => test_column_list($pdo),
        ];

        /* An operator's edit must survive the re-run. */
        $pdo->exec("UPDATE settings SET value = '7' WHERE key_name = 'unilevel_max_levels'");

        test_run_sql_file($pdo, $migrationDir . '020_unilevel_repeat_purchase.sql');

        assert_same($before['tables'], test_table_list($pdo), 'a re-run creates no table');
        assert_same($before['settings'], (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn(), 'a re-run adds no setting');
        assert_same($before['columns'], test_column_list($pdo), 'a re-run changes no column');
        assert_same(
            '7',
            (string) $pdo->query("SELECT value FROM settings WHERE key_name = 'unilevel_max_levels'")->fetchColumn(),
            'a re-run never overwrites an operator value'
        );

        /* ---- 6. the schema guards ----------------------------------- */
        $pdo->exec(
            "INSERT INTO products (sku, name, slug, price, stock)
             VALUES ('MIG-1', 'Migrated product', 'migrated-product', 10.00, 5)"
        );
        $productId = (int) $pdo->lastInsertId();
        assert_true($productId > 0, 'the migration probe product exists');

        /* A ladder row is one per (product, level) and level is 1–10. */
        $insertLevel = static function (PDO $pdo, int $productId, int $level, string $percent): void {
            $pdo->exec(
                "INSERT INTO product_unilevel_levels (product_id, level, percent)
                 VALUES ({$productId}, {$level}, {$percent})"
            );
        };

        $insertLevel($pdo, $productId, 1, '5.000');
        assert_throws(static fn () => $insertLevel($pdo, $productId, 1, '6.000'), PDOException::class, 'the ladder is unique per level');
        assert_throws(static fn () => $insertLevel($pdo, $productId, 0, '5.000'), PDOException::class, 'level 0 is refused');
        assert_throws(static fn () => $insertLevel($pdo, $productId, 11, '5.000'), PDOException::class, 'level 11 is refused');
        assert_throws(static fn () => $insertLevel($pdo, $productId, 2, '100.500'), PDOException::class, 'a percent above 100 is refused');

        /* Deleting the product cascades its ladder. */
        $pdo->exec("DELETE FROM products WHERE id = {$productId}");
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM product_unilevel_levels WHERE product_id = {$productId}")->fetchColumn(),
            'the ladder is deleted with its product'
        );

        /* An award needs its order, line, product and both users. */
        $buyerId  = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();
        $uplineId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_admin'")->fetchColumn();

        $pdo->exec(
            "INSERT INTO products (sku, name, slug, price, stock)
             VALUES ('MIG-2', 'Awarded product', 'awarded-product', 10.00, 5)"
        );
        $awardProductId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, billing_name, shipping_name, shipping_address,
                subtotal, total, paid_at, terms_accepted_at)
             VALUES ('EWT-MIG-20', {$buyerId}, 'paid', 'ewallet', 'Buyer', 'Buyer', 'Address',
                     10.00, 10.00, NOW(), NOW())"
        );
        $orderId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total, unit_base, base_total)
             VALUES ({$orderId}, {$awardProductId}, 'Awarded product', 1, 10.00, 10.00, 10.00, 10.00)"
        );
        $itemId = (int) $pdo->lastInsertId();

        $insertAward = static function (PDO $pdo, int $orderId, int $itemId, int $productId, int $buyerId, int $userId, int $level = 1, string $amount = '0.50'): void {
            $pdo->exec(
                "INSERT INTO unilevel_awards
                   (order_id, order_item_id, product_id, buyer_id, user_id, level,
                    base_amount, rate_pct, amount, paid_at)
                 VALUES ({$orderId}, {$itemId}, {$productId}, {$buyerId}, {$userId}, {$level},
                         10.00, 5.000, {$amount}, NOW())"
            );
        };

        $insertAward($pdo, $orderId, $itemId, $awardProductId, $buyerId, $uplineId);
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn());

        /* The unique key is the money anchor: a replayed line/person/level
         * cannot create a second row. A different level can. */
        assert_throws(
            static fn () => $insertAward($pdo, $orderId, $itemId, $awardProductId, $buyerId, $uplineId),
            PDOException::class,
            'a replayed award is refused'
        );
        $insertAward($pdo, $orderId, $itemId, $awardProductId, $buyerId, $uplineId, 2);
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn());

        /* A product that paid an award cannot be deleted out from under it. */
        assert_throws(
            static fn () => $pdo->exec("DELETE FROM products WHERE id = {$awardProductId}"),
            PDOException::class,
            'a product referenced by an award is protected'
        );

        /* Deleting the order takes its awards with it. */
        $pdo->exec("DELETE FROM shop_orders WHERE id = {$orderId}");
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn());

        /* The activity snapshot is keyed by (period, user) and validates
         * the period shape. */
        $insertActivity = static function (PDO $pdo, string $period, int $userId): void {
            $pdo->exec(
                "INSERT INTO unilevel_activity (period, user_id, personal_purchases, qualified, evaluated_at)
                 VALUES ('{$period}', {$userId}, 500.00, 1, NOW())"
            );
        };

        $insertActivity($pdo, '2026-10', $buyerId);
        assert_throws(static fn () => $insertActivity($pdo, '2026-10', $buyerId), PDOException::class, 'one row per member per period');
        assert_throws(static fn () => $insertActivity($pdo, '2026-13', $buyerId), PDOException::class, 'month 13 is refused');
        assert_throws(static fn () => $insertActivity($pdo, '2026-1', $buyerId), PDOException::class, 'a short period is refused');

        /* Deleting the member removes their activity row. */
        $pdo->exec("DELETE FROM users WHERE id = {$buyerId}");
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn());

        /* The closed-month header accepts a full row and keeps the closer
         * when the account goes away (SET NULL, not CASCADE). */
        $pdo->exec(
            "INSERT INTO unilevel_periods
               (period, closed_at, closed_by, threshold, members_total, qualified_count, award_total)
             VALUES ('2026-10', NOW(), {$uplineId}, 500.00, 1, 1, 0.50)"
        );

        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn());

        $pdo->exec("DELETE FROM users WHERE id = {$uplineId}");
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(), 'the period survives its closer');
        assert_null($pdo->query("SELECT closed_by FROM unilevel_periods WHERE period = '2026-10'")->fetchColumn());
    },

    'migration guards the ledger enum and the idempotency key' => function () use ($buildMigrated): void {
        $pdo    = $buildMigrated();
        $userId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();

        $insert = static function (PDO $pdo, int $userId, string $refType, int $refId): void {
            $pdo->exec(
                "INSERT INTO ewallet_ledger
                   (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id)
                 VALUES ({$userId}, 'credit', 1.00, 1.00, 1.00, '{$refType}', {$refId})"
            );
        };

        $insert($pdo, $userId, 'commission', 77);

        /* Replaying the same (user, ref_type, ref_id, type) is refused by the
         * unique key — the database half of the Phase 5 idempotency anchor. */
        assert_throws(static fn () => $insert($pdo, $userId, 'commission', 77), PDOException::class);

        /* A ref_type outside the DB enum is refused, so a PHP list that drifts
         * from the schema fails loudly instead of truncating. */
        assert_throws(static fn () => $insert($pdo, $userId, 'not_a_movement', 78), PDOException::class);

        /* Different reference kinds are independent: a refund under the same
         * source id and a second commission are both new rows. */
        $insert($pdo, $userId, 'refund', 77);
        $insert($pdo, $userId, 'commission', 78);
        assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = {$userId}")->fetchColumn());
    },

    'migration guards the order idempotency key and status enum' => function () use ($buildMigrated): void {
        $pdo    = $buildMigrated();
        $userId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();

        $insert = static function (PDO $pdo, int $userId, string $orderNo, string $key, string $status): void {
            $pdo->exec(
                "INSERT INTO shop_orders
                   (order_no, user_id, status, payment_method, billing_name, shipping_name, shipping_address,
                    idempotency_key, subtotal, total, terms_accepted_at)
                 VALUES ('{$orderNo}', {$userId}, '{$status}', 'ewallet', 'Buyer', 'Buyer', 'Address',
                         '{$key}', 10.00, 10.00, NOW())"
            );
        };

        $insert($pdo, $userId, 'EWT-MIG-2', 'idem-key-1', 'paid');
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE idempotency_key = 'idem-key-1'")->fetchColumn());

        /* A second order may not reuse the idempotency key. */
        assert_throws(
            static fn () => $insert($pdo, $userId, 'EWT-MIG-3', 'idem-key-1', 'pending'),
            PDOException::class
        );

        /* The 10-status lifecycle is enforced by the column. */
        assert_throws(
            static fn () => $insert($pdo, $userId, 'EWT-MIG-4', 'idem-key-2', 'delivery_failed'),
            PDOException::class
        );

        /* The order number is unique. */
        assert_throws(
            static fn () => $insert($pdo, $userId, 'EWT-MIG-2', 'idem-key-3', 'pending'),
            PDOException::class
        );
    },

    'migration guards the payment-proof attempt and status enum' => function () use ($buildMigrated): void {
        $pdo    = $buildMigrated();
        $userId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();

        $pdo->exec(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, billing_name, shipping_name, shipping_address,
                subtotal, total, terms_accepted_at)
             VALUES ('EWT-MIG-9', {$userId}, 'pending', 'gcash', 'Buyer', 'Buyer', 'Address',
                     10.00, 10.00, NOW())"
        );

        $orderId = (int) $pdo->lastInsertId();

        $insert = static function (PDO $pdo, int $orderId, int $attempt, string $status): void {
            $pdo->exec(
                "INSERT INTO shop_payment_proofs (order_id, attempt_no, file_path, status)
                 VALUES ({$orderId}, {$attempt}, 'uploads/proofs/proof.png', '{$status}')"
            );
        };

        $insert($pdo, $orderId, 1, 'pending');
        $insert($pdo, $orderId, 2, 'rejected');

        assert_same(
            2,
            (int) $pdo->query("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = {$orderId}")->fetchColumn()
        );

        /* Only the three review states exist in this build. */
        assert_throws(
            static fn () => $insert($pdo, $orderId, 3, 'reviewing'),
            PDOException::class
        );

        /* A deleted order takes its proof rows with it (no orphan evidence). */
        $pdo->exec("DELETE FROM shop_orders WHERE id = {$orderId}");
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = {$orderId}")->fetchColumn()
        );
    },

    'migration guards the shipment and invoice snapshots' => function () use ($buildMigrated): void {
        $pdo    = $buildMigrated();
        $userId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();

        $pdo->exec(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, billing_name, shipping_name, shipping_address,
                subtotal, total, terms_accepted_at, paid_at)
             VALUES ('EWT-MIG-10', {$userId}, 'shipped', 'gcash', 'Buyer', 'Buyer', 'Address',
                     10.00, 10.00, NOW(), NOW())"
        );
        $orderId = (int) $pdo->lastInsertId();

        /* The shipment is one row per order, with the courier/tracking facts. */
        $pdo->exec(
            "INSERT INTO shop_shipments (order_id, courier, tracking_number, notes)
             VALUES ({$orderId}, 'J&T Express', 'JT-0001', 'Two boxes')"
        );
        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM shop_shipments WHERE order_id = {$orderId}")->fetchColumn()
        );
        assert_same(
            'J&T Express|JT-0001',
            $pdo->query("SELECT CONCAT(courier, '|', tracking_number) FROM shop_shipments WHERE order_id = {$orderId}")->fetchColumn()
        );

        /* The invoice header + its frozen lines. */
        $pdo->exec(
            "INSERT INTO shop_invoices
               (order_id, invoice_no, status_at_issue, seller_name, billing_name, shipping_name,
                shipping_address, payment_method, subtotal, shipping_fee, total)
             VALUES ({$orderId}, 'INV-260101-00001', 'paid', 'EcoWealth', 'Buyer', 'Buyer',
                     'Address', 'gcash', 10.00, 0.00, 10.00)"
        );
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO shop_invoice_items (invoice_id, line_no, description, quantity, unit_price, amount)
             VALUES ({$invoiceId}, 1, 'Migrated product', 2, 5.00, 10.00)"
        );

        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM shop_invoice_items WHERE invoice_id = {$invoiceId}")->fetchColumn()
        );

        /* The invoice number is unique. */
        assert_throws(
            static fn () => $pdo->exec(
                "INSERT INTO shop_invoices
                   (order_id, invoice_no, status_at_issue, seller_name, billing_name, shipping_name,
                    shipping_address, payment_method, subtotal, shipping_fee, total)
                 VALUES ({$orderId}, 'INV-260101-00001', 'paid', 'EcoWealth', 'Buyer', 'Buyer',
                         'Address', 'gcash', 10.00, 0.00, 10.00)"
            ),
            PDOException::class
        );

        /* Deleting the order cascades both documents. */
        $pdo->exec("DELETE FROM shop_orders WHERE id = {$orderId}");
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM shop_shipments WHERE order_id = {$orderId}")->fetchColumn()
        );
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM shop_invoices WHERE order_id = {$orderId}")->fetchColumn()
        );
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM shop_invoice_items WHERE invoice_id = {$invoiceId}")->fetchColumn()
        );

        /* The auto-complete kill switch arrives with its default. */
        assert_same(
            '1',
            $pdo->query("SELECT value FROM settings WHERE key_name = 'shop_autocomplete_enabled'")->fetchColumn()
        );
    },

    'migration adds membership columns, backfills and guards codes' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        $user = $pdo->query(
            "SELECT id, username, member_status, referral_code, package_id, sponsor_id,
                    reg_payment_method, phone, activated_at
               FROM users WHERE username = 'legacy_user'"
        )->fetch();

        /* Existing accounts are working accounts: active, and every one
         * receives an 8-character referral code (P9-T6 / D3). */
        assert_same('active', $user['member_status']);
        assert_same(8, strlen((string) $user['referral_code']), 'legacy referral code is backfilled');
        assert_same('standard', $user['reg_payment_method']);
        assert_null($user['package_id']);
        assert_null($user['sponsor_id']);

        /* The code is unique across accounts. */
        assert_throws(
            static fn () => $pdo->exec(
                "UPDATE users SET referral_code = '{$user['referral_code']}' WHERE username = 'legacy_admin'"
            ),
            PDOException::class,
            'two accounts may not share a referral code'
        );

        /* A registration code is one row, bound to a package and creator. */
        $adminId   = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_admin'")->fetchColumn();

        $pdo->exec(
            "INSERT INTO packages (name, slug, price, direct_referral_bonus)
             VALUES ('Membership probe', 'membership-probe', 100.00, 10.00)"
        );
        $packageId = (int) $pdo->lastInsertId();

        $insert = static function (PDO $pdo, int $adminId, int $packageId, string $code, string $status, string $type = 'registration'): void {
            $pdo->exec(
                "INSERT INTO reg_codes (code, package_id, price, code_type, status, created_by)
                 VALUES ('{$code}', {$packageId}, 100.00, '{$type}', '{$status}', {$adminId})"
            );
        };

        $insert($pdo, $adminId, $packageId, 'AAAA-BBBB-2C3D', 'unused');

        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM reg_codes WHERE code = 'AAAA-BBBB-2C3D'")->fetchColumn()
        );

        /* The code is unique, and the status/type enums are closed. */
        assert_throws(static fn () => $insert($pdo, $adminId, $packageId, 'AAAA-BBBB-2C3D', 'unused'), PDOException::class);
        assert_throws(static fn () => $insert($pdo, $adminId, $packageId, 'BBBB-CCCC-3D4E', 'redeemed'), PDOException::class);
        assert_throws(static fn () => $insert($pdo, $adminId, $packageId, 'CCCC-DDDD-4E5F', 'unused', 'trial'), PDOException::class);

        /* A package with codes is protected from deletion... */
        assert_throws(
            static fn () => $pdo->exec("DELETE FROM packages WHERE id = {$packageId}"),
            PDOException::class,
            'a package referenced by a code cannot be deleted'
        );

        /* ...and an activation row accepts an approved membership record. */
        $userId = (int) $user['id'];

        $pdo->exec(
            "INSERT INTO activations (user_id, package_id, amount, payment_method, status)
             VALUES ({$userId}, {$packageId}, 100.00, 'code', 'approved')"
        );
        $activationId = (int) $pdo->lastInsertId();

        $pdo->exec("UPDATE users SET package_id = {$packageId}, member_status = 'active' WHERE id = {$userId}");

        assert_same('active', $pdo->query("SELECT member_status FROM users WHERE id = {$userId}")->fetchColumn());
        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM activations WHERE id = {$activationId}")->fetchColumn()
        );

        /* Only the five activation states exist in this build. */
        assert_throws(
            static fn () => $pdo->exec(
                "INSERT INTO activations (user_id, package_id, amount, payment_method, status)
                 VALUES ({$userId}, {$packageId}, 100.00, 'code', 'paid')"
            ),
            PDOException::class
        );

        /* Deleting the member cascades their activation history. */
        $pdo->exec("DELETE FROM users WHERE id = {$userId}");
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM activations WHERE id = {$activationId}")->fetchColumn()
        );
    },

    'migration adds the indirect commission settings' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        assert_same(
            '1',
            $pdo->query("SELECT value FROM settings WHERE key_name = 'commission_indirect_enabled'")->fetchColumn()
        );
        assert_same(
            '10',
            $pdo->query("SELECT value FROM settings WHERE key_name = 'commission_indirect_max_levels'")->fetchColumn()
        );
        assert_same(
            '0',
            $pdo->query("SELECT value FROM settings WHERE key_name = 'commission_compression'")->fetchColumn()
        );

        /* A re-run must not overwrite an operator's choice. */
        $pdo->exec("UPDATE settings SET value = '3' WHERE key_name = 'commission_indirect_max_levels'");
        test_run_sql_file($pdo, dirname(__DIR__, 2) . '/database/migrations/014_indirect_commissions.sql');

        assert_same(
            '3',
            $pdo->query("SELECT value FROM settings WHERE key_name = 'commission_indirect_max_levels'")->fetchColumn()
        );
    },

    'migration raises Package B to the premium ladder only' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        /* The legacy fixture has no demo packages, so build the old
         * Package B exactly as Phase 3 seeded it. */
        $pdo->exec(
            "INSERT INTO packages (name, slug, price, product_value, direct_referral_bonus)
             VALUES ('Package B', 'package-b', 6998.00, 8300.00, 500.00)"
        );
        $packageId = (int) $pdo->lastInsertId();

        $insertLevel = $pdo->prepare('INSERT INTO package_levels (package_id, level, amount) VALUES (?, ?, ?)');

        foreach ([2 => 100.00, 3 => 50.00, 4 => 40.00, 5 => 30.00, 6 => 20.00, 7 => 10.00, 8 => 5.00, 9 => 5.00, 10 => 5.00] as $level => $amount) {
            $insertLevel->execute([$packageId, $level, number_format($amount, 2, '.', '')]);
        }

        test_run_sql_file($pdo, dirname(__DIR__, 2) . '/database/migrations/015_package_b_premium_ladder.sql');

        assert_same('600.00', (string) $pdo->query("SELECT direct_referral_bonus FROM packages WHERE id = {$packageId}")->fetchColumn());
        assert_same(
            '120.00|60.00|48.00|36.00|24.00|12.00|6.00|6.00|6.00',
            $pdo->query(
                "SELECT GROUP_CONCAT(amount ORDER BY level SEPARATOR '|') FROM package_levels WHERE package_id = {$packageId}"
            )->fetchColumn()
        );

        /* An admin-edited value is left alone on a re-run. */
        $pdo->exec("UPDATE package_levels SET amount = 125.00 WHERE package_id = {$packageId} AND level = 2");
        test_run_sql_file($pdo, dirname(__DIR__, 2) . '/database/migrations/015_package_b_premium_ladder.sql');

        assert_same(
            '125.00',
            $pdo->query("SELECT amount FROM package_levels WHERE package_id = {$packageId} AND level = 2")->fetchColumn()
        );
    },

    'migration guards the commission event key and its cascades' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        $adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_admin'")->fetchColumn();
        $userId  = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();

        $pdo->exec(
            "INSERT INTO packages (name, slug, price, direct_referral_bonus)
             VALUES ('Commission probe', 'commission-probe', 100.00, 500.00)"
        );
        $packageId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO activations (user_id, package_id, amount, payment_method, status)
             VALUES ({$userId}, {$packageId}, 100.00, 'code', 'approved')"
        );
        $activationId = (int) $pdo->lastInsertId();

        $insert = static function (
            PDO $pdo,
            int $recipientId,
            int $sourceId,
            int $packageId,
            int $activationId,
            string $type = 'direct_referral',
            int $level = 1,
            string $status = 'credited'
        ): void {
            $pdo->exec(
                "INSERT INTO commissions
                   (user_id, type, amount, level, source_user_id, package_id, activation_id, status)
                 VALUES ({$recipientId}, '{$type}', 500.00, {$level}, {$sourceId}, {$packageId}, {$activationId}, '{$status}')"
            );
        };

        /* One award per (type, source, recipient, level) — the idempotency
         * anchor the whole plan relies on (plan §6.5). */
        $insert($pdo, $adminId, $userId, $packageId, $activationId);
        assert_throws(
            static fn () => $insert($pdo, $adminId, $userId, $packageId, $activationId),
            PDOException::class,
            'a replayed award must be refused by the unique key'
        );

        /* A different level is a different award. */
        $insert($pdo, $adminId, $userId, $packageId, $activationId, 'indirect_referral', 2);

        /* The status and type enums are closed in this build. */
        assert_throws(
            static fn () => $insert($pdo, $adminId, $userId, $packageId, $activationId, 'direct_referral', 3, 'paid'),
            PDOException::class
        );
        assert_throws(
            static fn () => $insert($pdo, $adminId, $userId, $packageId, $activationId, 'residual', 3),
            PDOException::class
        );

        /* A package that priced an award cannot be deleted out from under it. */
        assert_throws(
            static fn () => $pdo->exec("DELETE FROM packages WHERE id = {$packageId}"),
            PDOException::class,
            'a package referenced by an award cannot be deleted'
        );

        /* Deleting the activation removes its awards. */
        $pdo->exec("DELETE FROM activations WHERE id = {$activationId}");
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM commissions WHERE activation_id = {$activationId}")->fetchColumn()
        );
    },

    'migration guards the payout status machine and its cascades' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        $userId  = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();
        $adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_admin'")->fetchColumn();

        $insert = static function (PDO $pdo, int $userId, string $status, string $method = 'gcash'): void {
            $pdo->exec(
                "INSERT INTO payout_requests (user_id, amount, payout_method, payout_account, status)
                 VALUES ({$userId}, 500.00, '{$method}', '09171234567', '{$status}')"
            );
        };

        /* A pending request is the default state, with every derived
         * number defaulting to zero until the service fills them in. */
        $pdo->exec(
            "INSERT INTO payout_requests (user_id, amount, payout_account)
             VALUES ({$userId}, 800.00, '09171234567')"
        );
        $payoutId = (int) $pdo->lastInsertId();

        $row = $pdo->query("SELECT * FROM payout_requests WHERE id = {$payoutId}")->fetch();

        assert_same('pending', $row['status']);
        assert_same('gcash', $row['payout_method']);
        assert_same('0.00', (string) $row['service_fee_amount']);
        assert_same('0.0000', (string) $row['usdt_amount']);
        assert_null($row['processed_by']);
        assert_null($row['processed_at']);

        /* Only the four states and four methods of plan §4.4/§5.9 exist. */
        assert_throws(static fn () => $insert($pdo, $userId, 'paid'), PDOException::class);
        assert_throws(static fn () => $insert($pdo, $userId, 'pending', 'bank'), PDOException::class);

        /* The admin who processed a request is recorded, and losing that
         * account must not lose the request (SET NULL, not CASCADE). */
        $pdo->exec(
            "UPDATE payout_requests
                SET status = 'completed', processed_by = {$adminId}, processed_at = NOW()
              WHERE id = {$payoutId}"
        );

        assert_same('completed', $pdo->query("SELECT status FROM payout_requests WHERE id = {$payoutId}")->fetchColumn());
        assert_same(
            $adminId,
            (int) $pdo->query("SELECT processed_by FROM payout_requests WHERE id = {$payoutId}")->fetchColumn()
        );

        $pdo->exec("DELETE FROM users WHERE id = {$adminId}");

        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM payout_requests WHERE id = {$payoutId}")->fetchColumn(),
            'the request survives its processor'
        );
        assert_null($pdo->query("SELECT processed_by FROM payout_requests WHERE id = {$payoutId}")->fetchColumn());

        /* Deleting the member takes their requests with them. */
        $pdo->exec("DELETE FROM users WHERE id = {$userId}");

        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM payout_requests WHERE id = {$payoutId}")->fetchColumn()
        );
    },

    'migration guards the impersonation audit and its hashed nonce' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        $memberId = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_user'")->fetchColumn();
        $adminId  = (int) $pdo->query("SELECT id FROM users WHERE username = 'legacy_admin'")->fetchColumn();

        $nonce = str_repeat('ab', 32);
        $hash  = hash('sha256', $nonce);

        $insert = static function (PDO $pdo, int $adminId, int $memberId, string $hash, string $status = 'active'): void {
            $pdo->exec(
                "INSERT INTO impersonation_log
                   (superadmin_id, superadmin_name, target_user_id, target_username,
                    nonce_hash, ip, user_agent, status, expires_at)
                 VALUES ({$adminId}, 'legacy_admin', {$memberId}, 'legacy_user',
                         '{$hash}', '127.0.0.1', 'MigrationTest/1.0', '{$status}', DATE_ADD(NOW(), INTERVAL 8 HOUR))"
            );
        };

        $insert($pdo, $adminId, $memberId, $hash);
        $logId = (int) $pdo->lastInsertId();

        $row = $pdo->query("SELECT * FROM impersonation_log WHERE id = {$logId}")->fetch();

        assert_same('active', $row['status']);
        assert_same($hash, $row['nonce_hash']);
        assert_same(64, strlen((string) $row['nonce_hash']));
        assert_not_contains($nonce, (string) json_encode($row), 'the raw nonce must never be stored');

        /* The nonce hash is the unique anchor: one row per URL token. */
        assert_throws(static fn () => $insert($pdo, $adminId, $memberId, $hash), PDOException::class);

        /* Only the three plan §4.4 states exist. */
        assert_throws(static fn () => $insert($pdo, $adminId, $memberId, str_repeat('cd', 32), 'closed'), PDOException::class);

        /* Deleting the viewed member removes their sessions. */
        $pdo->exec("DELETE FROM users WHERE id = {$memberId}");
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM impersonation_log WHERE id = {$logId}")->fetchColumn());

        /* Deleting the superadmin removes their sessions too. */
        $pdo->exec(
            "INSERT INTO users (username, email, password_hash, role)
             VALUES ('fallback_member', 'fallback@test.local', 'x', 'member')"
        );
        $fallbackId = (int) $pdo->lastInsertId();

        $insert($pdo, $adminId, $fallbackId, str_repeat('ef', 32));
        $secondId = (int) $pdo->lastInsertId();

        $pdo->exec("DELETE FROM users WHERE id = {$adminId}");
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM impersonation_log WHERE id = {$secondId}")->fetchColumn());
    },

    'migrated schema rejects the old user role value' => function () use ($buildMigrated): void {        $pdo = $buildMigrated();
        assert_throws(
            static fn () => $pdo->exec(
                "INSERT INTO users (username, email, password_hash, role)
                 VALUES ('probe_user', 'probe@test.local', 'x', 'user')"
            ),
            PDOException::class,
            'the old role value must not be insertable'
        );

        $pdo->exec(
            "INSERT INTO users (username, email, password_hash, role)
             VALUES ('probe_member', 'probe.member@test.local', 'x', 'member')"
        );

        assert_same('member', $pdo->query("SELECT role FROM users WHERE username = 'probe_member'")->fetchColumn());
    },
];
