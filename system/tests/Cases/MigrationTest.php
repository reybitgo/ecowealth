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
            'reg_codes', 'activations',
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
