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

    'migrated schema rejects the old user role value' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

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
