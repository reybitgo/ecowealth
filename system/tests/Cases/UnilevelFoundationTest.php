<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelFoundationTest — Phase 17A (plan §5)
 *
 *  Proves the foundation phase on its own: the Phase 17 schema,
 *  the settings, the wallet vocabulary and the install/reset tool
 *  expectations. Nothing in this file pays money — 17A deliberately
 *  ships no reader, so the cases assert that the new objects are
 *  inert as well as correct.
 *
 *      migration   the four tables, four altered tables, two order
 *                  indexes, the extended enum and the six settings
 *      idempotency a second run is a no-op and keeps operator values
 *      bundle      install.sql matches its sources and mirrors the
 *                  migration's objects
 *      tools       DatabaseInstaller / DatabaseResetService agree with
 *                  the schema (31 tables, 54 settings, 3 money tables)
 *      vocabulary  WalletService::REF_TYPES, the labels and the icons
 *      defaults    a migrated and a fresh database agree; everything off
 *      guards      the cascades, the unique keys and the CHECKs
 *      inert       no controller, view, route or model reads the tables
 *
 *  Run this group alone with:  php tests/run.php UnilevelFoundation
 * ============================================================== */

$appRoot      = dirname(__DIR__, 2);
$migrationDir = $appRoot . '/database/migrations/';
$fixtureFile  = __DIR__ . '/../fixtures/legacy_phase0_schema.sql';

/** The migrations a p17-start database has: 001 … 019, in order. */
$startMigrations = static function () use ($migrationDir): array {
    $files = [];

    foreach (glob($migrationDir . '*.sql') ?: [] as $file) {
        $name = basename($file);

        if (preg_match('/^0(0[1-9]|1[0-9])_/', $name) === 1) {
            $files[] = $file;
        }
    }

    sort($files, SORT_STRING);

    return $files;
};

/** Build a p17-start database (the legacy fixture + migrations 001–019). */
$buildStart = static function () use ($startMigrations, $fixtureFile): PDO {
    $pdo = test_pdo();
    test_recreate_database($pdo, TEST_MIGRATION_DB);
    test_run_sql_file($pdo, $fixtureFile);

    foreach ($startMigrations() as $migration) {
        test_run_sql_file($pdo, $migration);
    }

    return $pdo;
};

/** Apply Phase 17's migration 020 to that database. */
$buildFoundation = static function () use ($buildStart, $migrationDir): PDO {
    $pdo = $buildStart();
    test_run_sql_file($pdo, $migrationDir . '020_unilevel_repeat_purchase.sql');

    return $pdo;
};

/** The value of one settings key, or null. */
$setting = static function (PDO $pdo, string $key): ?string {
    $statement = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (string) $value;
};

/** One named index from the bootstrap's index helper. */
$index = static function (PDO $pdo, string $table, string $name): ?array {
    foreach (test_index_list($pdo)[$table] ?? [] as $candidate) {
        if ($candidate['name'] === $name) {
            return $candidate;
        }
    }

    return null;
};

/** The six Phase 17 settings, in the order the migration writes them. */
$newSettings = [
    'unilevel_enabled',
    'unilevel_max_levels',
    'unilevel_requires_active_upline',
    'unilevel_active_threshold',
    'unilevel_reversal_enabled',
    'unilevel_ledger_since',
];

return [
    /* ==========================================================
     *  Schema (P17A-T1)
     * ========================================================== */
    'P17A-T1 the migration adds the four tables, four altered tables and two indexes' => function () use ($buildFoundation, $index): void {
        $pdo    = $buildFoundation();
        $tables = test_table_list($pdo);

        foreach ([
            'product_unilevel_levels',
            'unilevel_awards',
            'unilevel_activity',
            'unilevel_periods',
        ] as $table) {
            assert_true(in_array($table, $tables, true), $table . ' is missing');
        }

        /* The four altered tables carry their new columns, in the
         * documented position (plan §13.3). */
        $columns = static function (PDO $pdo, string $table): array {
            return array_map(
                static fn (array $row): string => (string) $row['Field'],
                $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll()
            );
        };

        assert_same(
            ['pv', 'unilevel_enabled', 'unilevel_base', 'stock'],
            array_slice($columns($pdo, 'products'), 5, 4),
            'products.unilevel_* sit directly after pv'
        );

        assert_same(
            ['line_total', 'unit_base', 'base_total', 'reservation_state'],
            array_slice($columns($pdo, 'shop_order_items'), 7, 4),
            'shop_order_items.unit_base/base_total sit after line_total'
        );

        assert_same(
            ['paid_at', 'unilevel_processed_at', 'unilevel_total', 'packed_at'],
            array_slice($columns($pdo, 'shop_orders'), 26, 4),
            'shop_orders.unilevel_* sit after paid_at'
        );

        /* The two order indexes exist with the documented columns. */
        $unilevelIndex = $index($pdo, 'shop_orders', 'idx_orders_unilevel');
        assert_not_null($unilevelIndex, 'idx_orders_unilevel is missing');
        assert_same(['unilevel_processed_at'], array_column($unilevelIndex['columns'], 'column'));

        $userPaidIndex = $index($pdo, 'shop_orders', 'idx_orders_user_paid');
        assert_not_null($userPaidIndex, 'idx_orders_user_paid is missing');
        assert_same(['user_id', 'paid_at'], array_column($userPaidIndex['columns'], 'column'));

        /* The ladder and the money ledger carry their unique anchors. */
        $ladder = $index($pdo, 'product_unilevel_levels', 'uq_product_unilevel');
        assert_not_null($ladder, 'uq_product_unilevel is missing');
        assert_true($ladder['unique'], 'the ladder key is unique');
        assert_same(['product_id', 'level'], array_column($ladder['columns'], 'column'));

        $anchor = $index($pdo, 'unilevel_awards', 'uq_unilevel_award');
        assert_not_null($anchor, 'uq_unilevel_award is missing');
        assert_true($anchor['unique'], 'the award anchor is unique');
        assert_same(['order_item_id', 'user_id', 'level'], array_column($anchor['columns'], 'column'));

        /* The natural primary keys document that only the awards have a
         * sequence (plan §13.7). */
        assert_same(['period', 'user_id'], array_column(
            $index($pdo, 'unilevel_activity', 'PRIMARY')['columns'],
            'column'
        ));
        assert_same(['period'], array_column($index($pdo, 'unilevel_periods', 'PRIMARY')['columns'], 'column'));
    },

    'P17A-T1 the migration extends the ledger enum and seeds six settings, every stream off' => function () use ($buildFoundation, $setting): void {
        $pdo = $buildFoundation();

        /* The enum accepts the two new movement kinds, in order. */
        $enum = (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'];
        preg_match_all("/'([^']+)'/", $enum, $matches);

        assert_same(
            ['commission', 'payout', 'transfer', 'topup', 'registration', 'shop_order', 'adjustment',
                'refund', 'unilevel', 'unilevel_reversal'],
            $matches[1],
            'the enum keeps its order and appends the two repeat-purchase kinds'
        );

        /* The six settings exist with the documented defaults (plan §13.5). */
        assert_same('0', $setting($pdo, 'unilevel_enabled'), 'the global master starts off (D-11)');
        assert_same('10', $setting($pdo, 'unilevel_max_levels'));
        assert_same('1', $setting($pdo, 'unilevel_requires_active_upline'));
        assert_same('500', $setting($pdo, 'unilevel_active_threshold'));
        assert_same('1', $setting($pdo, 'unilevel_reversal_enabled'));
        assert_same(
            1,
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $setting($pdo, 'unilevel_ledger_since')),
            'unilevel_ledger_since is the migration instant'
        );

        /* No backfill: an existing order keeps a null stamp and a zero
         * total, so nothing paid before the migration can be awarded. */
        $pdo->exec(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, billing_name, shipping_name, shipping_address,
                subtotal, total, paid_at, terms_accepted_at)
             VALUES ('EWT-17A-1', 1, 'paid', 'ewallet', 'Buyer', 'Buyer', 'Address',
                     10.00, 10.00, NOW(), NOW())"
        );

        $order = $pdo->query("SELECT unilevel_processed_at, unilevel_total FROM shop_orders WHERE order_no = 'EWT-17A-1'")->fetch();
        assert_null($order['unilevel_processed_at'], 'a historical order is never stamped by the migration');
        assert_same('0.00', (string) $order['unilevel_total']);
    },

    /* ==========================================================
     *  Idempotency (P17A-T2)
     * ========================================================== */
    'P17A-T2 a second run of the migration is a no-op' => function () use ($buildFoundation, $migrationDir, $setting): void {
        $pdo = $buildFoundation();

        $snapshot = static fn (PDO $pdo): array => [
            'tables'   => test_table_list($pdo),
            'columns'  => test_column_list($pdo),
            'indexes'  => test_index_list($pdo),
            'foreign'  => test_foreign_key_list($pdo),
            'settings' => (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
        ];

        $before = $snapshot($pdo);

        /* An operator's values must survive the re-run. */
        $pdo->exec("UPDATE settings SET value = '6' WHERE key_name = 'unilevel_max_levels'");
        $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'unilevel_enabled'");

        test_run_sql_file($pdo, $migrationDir . '020_unilevel_repeat_purchase.sql');

        assert_same($before, $snapshot($pdo), 'a re-run changes no object and adds no setting');
        assert_same('6', $setting($pdo, 'unilevel_max_levels'), 'a re-run never overwrites an operator value');
        assert_same('1', $setting($pdo, 'unilevel_enabled'));

        /* A lost table is recreated by the same guarded file. */
        $pdo->exec('DROP TABLE IF EXISTS unilevel_awards');
        test_run_sql_file($pdo, $migrationDir . '020_unilevel_repeat_purchase.sql');

        assert_true(
            in_array('unilevel_awards', test_table_list($pdo), true),
            'a re-run recreates a missing table (CREATE TABLE IF NOT EXISTS)'
        );
        assert_same(
            6,
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'unilevel%'")->fetchColumn(),
            'the settings insert stays idempotent'
        );
    },

    /* ==========================================================
     *  The bundle (P17A-T5)
     * ========================================================== */
    'P17A-T5 the committed install bundle matches its sources and carries the six settings' => function () use ($appRoot): void {
        require_once $appRoot . '/bin/build_install_sql.php';

        $result = build_install_sql_check($appRoot);
        assert_true(
            $result['ok'],
            'database/install.sql is stale'
                . ($result['line'] === null ? '' : ' (first difference at line ' . $result['line'] . ')')
        );

        $bundle = (string) file_get_contents($appRoot . '/database/install.sql');

        foreach ([
            'unilevel_enabled',
            'unilevel_max_levels',
            'unilevel_requires_active_upline',
            'unilevel_active_threshold',
            'unilevel_reversal_enabled',
            'unilevel_ledger_since',
        ] as $key) {
            assert_contains("'" . $key . "'", $bundle, 'the bundle seeds settings.' . $key);
        }

        /* The bundle creates every new table, and nothing runs a database
         * switch (D-IR-02). */
        $script = SqlScript::fromFile($appRoot . '/database/install.sql');
        assert_false($script->containsDatabaseSwitch());

        $statements = $script->statements();
        $creates    = 0;
        $created    = [];

        foreach ($statements as $statement) {
            if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?/i', $statement['sql'], $match) === 1) {
                $creates++;
                $created[] = strtolower($match[1]);
            }
        }

        assert_same(31, $creates, 'the bundle creates 31 tables');

        foreach (['product_unilevel_levels', 'unilevel_awards', 'unilevel_activity', 'unilevel_periods'] as $table) {
            assert_true(in_array($table, $created, true), 'the bundle creates ' . $table);
        }
    },

    /* ==========================================================
     *  The install/reset tools (P17A-T6)
     * ========================================================== */
    'P17A-T6 the installer and reset expectations match the schema' => function (): void {
        assert_same(31, DatabaseInstaller::EXPECTED['tables_created']);
        assert_same(54, DatabaseInstaller::EXPECTED['settings_rows']);
        assert_same(2, DatabaseInstaller::EXPECTED['staff_rows']);
        assert_same(7, DatabaseInstaller::EXPECTED['products_rows']);
        assert_same(2, DatabaseInstaller::EXPECTED['packages_rows']);
        assert_same(18, DatabaseInstaller::EXPECTED['levels_rows']);
        assert_same(10, DatabaseInstaller::EXPECTED['contents_rows']);

        /* A fresh install must fail verification if any money table has a
         * row; the catalogue ladder is not a money table. */
        foreach (['unilevel_awards', 'unilevel_activity', 'unilevel_periods'] as $table) {
            assert_true(
                in_array($table, DatabaseInstaller::TRANSACTIONAL_TABLES, true),
                'the zero pack covers ' . $table
            );
        }

        assert_false(in_array('product_unilevel_levels', DatabaseInstaller::TRANSACTIONAL_TABLES, true));
        assert_same(['ewallet_ledger', 'reg_codes'], DatabaseInstaller::DEMO_TABLES, 'the demo seed writes no bonus row');

        /* The reset allow-list is exactly the schema's table list. */
        $schemaTables = test_table_list(test_pdo(['db' => DB_NAME]));
        $allowList    = DatabaseResetService::ALL_TABLES;
        sort($allowList, SORT_STRING);

        assert_same($schemaTables, $allowList, 'ALL_TABLES must name every schema table exactly once');

        foreach (['unilevel_awards', 'unilevel_activity', 'unilevel_periods'] as $table) {
            assert_true(in_array($table, DatabaseResetService::ZERO_TABLES, true), 'a reset must zero ' . $table);
        }

        assert_true(in_array('product_unilevel_levels', DatabaseResetService::ALL_TABLES, true), 'the ladder is a known table');
        assert_false(in_array('product_unilevel_levels', DatabaseResetService::ZERO_TABLES, true), 'the ladder is catalogue, not money');

        /* Only the award ledger has a sequence; the other two use natural
         * primary keys (plan §13.7). */
        assert_true(in_array('unilevel_awards', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_false(in_array('unilevel_activity', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_false(in_array('unilevel_periods', DatabaseResetService::AUTO_INCREMENT_TABLES, true));

        /* The delete groups empty the money before its parent orders. */
        $groups = (new ReflectionClassConstant(DatabaseResetService::class, 'DELETE_GROUPS'))->getValue();
        $keys   = array_column($groups, 'key');

        $repeatIndex = array_search('unilevel', $keys, true);
        $orderIndex  = array_search('orders', $keys, true);

        assert_true($repeatIndex !== false, 'the reset has a repeat-purchase delete group');
        assert_true($orderIndex !== false, 'the reset still has its orders group');
        assert_true($repeatIndex < $orderIndex, 'the awards are deleted before their order items');
    },

    /* ==========================================================
     *  The wallet vocabulary (P17A-T9, P17A-T10)
     * ========================================================== */
    'P17A-T9 WalletService::REF_TYPES and the live enum agree in both directions' => function (): void {
        $column = db()->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch();
        assert_not_null($column, 'the ledger table must exist');

        preg_match_all("/'([^']+)'/", (string) $column['Type'], $matches);
        $dbValues = $matches[1];

        $php = WalletService::REF_TYPES;

        assert_count(10, $php, 'ten movement kinds after Phase 17');
        assert_same($php, array_values(array_unique($php)), 'no duplicate movement kind');

        foreach (['unilevel', 'unilevel_reversal'] as $refType) {
            assert_true(in_array($refType, $php, true), 'REF_TYPES carries ' . $refType);
            assert_true(in_array($refType, $dbValues, true), 'the enum carries ' . $refType);
        }

        sort($dbValues);
        sort($php);

        assert_same($dbValues, $php, 'the list and the column must agree in both directions');

        /* An unknown ref type is refused before any write: the vocabulary
         * stays closed even though the column now accepts two more values. */
        $error = assert_throws(
            static fn (): array => (new WalletService())->credit(1, '1.00', 'unilevel_bonus', 1),
            WalletException::class
        );

        assert_same('ref_type', $error->reason);
    },

    'P17A-T10 the two ref types carry Repeat-purchase labels and icons' => function (): void {
        assert_same('Repeat-purchase bonus', wallet_ref_label('unilevel'));
        assert_same('Repeat-purchase reversal', wallet_ref_label('unilevel_reversal'));

        assert_same('bx bx-gift', wallet_ref_icon('unilevel'));
        assert_same('bx bx-undo', wallet_ref_icon('unilevel_reversal'));

        /* Every value in the vocabulary has a label and a bx- icon: the
         * wallet statement renders one filter option per entry. */
        foreach (WalletService::REF_TYPES as $refType) {
            assert_true(wallet_ref_label($refType) !== '', $refType . ' has a label');
            assert_same(1, preg_match('/^bx bx-[a-z0-9-]+$/', wallet_ref_icon($refType)), $refType . ' has a bx- icon');
        }
    },

    /* ==========================================================
     *  The defaults (P17A-T11)
     * ========================================================== */
    'P17A-T11 a migrated database and a fresh install carry the same six defaults' => function () use ($buildFoundation, $newSettings, $setting): void {
        $migrated = $buildFoundation();
        $fresh    = test_pdo(['db' => DB_NAME]);

        foreach ($newSettings as $key) {
            if ($key === 'unilevel_ledger_since') {
                continue;   // written with NOW() on each side; the shape is checked below
            }

            assert_same(
                $setting($fresh, $key),
                $setting($migrated, $key),
                'settings.' . $key . ' must agree between a migrated and a fresh database'
            );
        }

        assert_same('0', $setting($migrated, 'unilevel_enabled'), 'D-11: the migrated master starts off');
        assert_same('0', $setting($fresh, 'unilevel_enabled'), 'D-11: the fresh master starts off');

        foreach ([$migrated, $fresh] as $pdo) {
            assert_same(
                0,
                (int) $pdo->query('SELECT COUNT(*) FROM products WHERE unilevel_enabled <> 0')->fetchColumn(),
                'no product is switched on out of the box'
            );
            assert_same(
                0,
                (int) $pdo->query("SELECT COUNT(*) FROM products WHERE unilevel_base <> 'auto'")->fetchColumn(),
                'every product keeps the automatic base'
            );

            /* Nothing was backfilled: a fresh or migrated database has no
             * award, no activity snapshot and no closed period. */
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn());
        }
    },

    /* ==========================================================
     *  The guards (P17A-T12)
     * ========================================================== */
    'P17A-T12 a product ladder row cascades on delete and stays unique per level' => function (): void {
        $pdo = db();

        $pdo->exec(
            "INSERT INTO products (sku, name, slug, price, stock)
             VALUES ('P17A-CASCADE', 'Phase 17 cascade probe', 'phase-17-cascade-probe', 100.00, 5)"
        );
        $productId = (int) $pdo->lastInsertId();

        $insert = static function (PDO $pdo, int $productId, int $level, string $percent): void {
            $pdo->exec(
                "INSERT INTO product_unilevel_levels (product_id, level, percent)
                 VALUES ({$productId}, {$level}, {$percent})"
            );
        };

        for ($level = 1; $level <= 10; $level++) {
            $insert($pdo, $productId, $level, '5.000');
        }

        assert_same(
            10,
            (int) $pdo->query('SELECT COUNT(*) FROM product_unilevel_levels WHERE product_id = ' . $productId)->fetchColumn()
        );

        /* One row per level, level 1–10, percent 0–100. */
        assert_throws(static fn () => $insert($pdo, $productId, 1, '6.000'), PDOException::class, 'duplicate level refused');
        assert_throws(static fn () => $insert($pdo, $productId, 0, '5.000'), PDOException::class, 'level 0 refused');
        assert_throws(static fn () => $insert($pdo, $productId, 11, '5.000'), PDOException::class, 'level 11 refused');
        assert_throws(static fn () => $insert($pdo, $productId, 2, '-0.001'), PDOException::class, 'a negative rate refused');
        assert_throws(static fn () => $insert($pdo, $productId, 2, '100.001'), PDOException::class, 'a rate above 100 refused');

        /* Deleting the product takes its ladder with it (no orphan rows). */
        $pdo->exec('DELETE FROM products WHERE id = ' . $productId);

        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM product_unilevel_levels WHERE product_id = ' . $productId)->fetchColumn(),
            'the ladder cascaded'
        );
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM products WHERE sku = 'P17A-CASCADE'")->fetchColumn());
    },

    'P17A-T12 an award cascades with its order and protects its product' => function (): void {
        $pdo = db();

        $buyer  = test_user('p17a_buyer', 'member');
        $upline = test_user('p17a_upline', 'member');

        /* A dedicated product, so the only thing that can protect it from a
         * delete is the award's RESTRICT foreign key. */
        $pdo->exec(
            "INSERT INTO products (sku, name, slug, price, stock)
             VALUES ('P17A-AWARD', 'Phase 17 award probe', 'phase-17-award-probe', 100.00, 5)"
        );
        $productId = (int) $pdo->lastInsertId();

        $orderId = test_order((int) $buyer['id'], [['product_id' => $productId]]);
        $itemId  = (int) $pdo->query('SELECT id FROM shop_order_items WHERE order_id = ' . $orderId . ' LIMIT 1')->fetchColumn();

        $award = static function (int $orderId, int $itemId, int $level = 1) use ($pdo, $productId, $buyer, $upline): void {
            $pdo->prepare(
                'INSERT INTO unilevel_awards
                   (order_id, order_item_id, product_id, buyer_id, user_id, level,
                    base_amount, rate_pct, amount, paid_at)
                 VALUES (?, ?, ?, ?, ?, ?, 100.00, 5.000, 5.00, NOW())'
            )->execute([$orderId, $itemId, $productId, (int) $buyer['id'], (int) $upline['id'], $level]);
        };

        $award($orderId, $itemId);
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards WHERE order_id = ' . $orderId)->fetchColumn());

        /* The money anchor: the same line, recipient and level cannot be
         * awarded twice; a different level is a different decision. */
        assert_throws(static fn () => $award($orderId, $itemId), PDOException::class, 'a replayed award is refused');
        $award($orderId, $itemId, 2);
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards WHERE order_id = ' . $orderId)->fetchColumn());

        /* A product that paid an award cannot be deleted. */
        assert_throws(
            static fn () => $pdo->exec('DELETE FROM products WHERE id = ' . $productId),
            PDOException::class,
            'the product is protected by fk_ua_product'
        );

        /* Deleting the order takes its awards with it. */
        test_delete_order($orderId);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards WHERE order_id = ' . $orderId)->fetchColumn());

        /* Deleting the recipient takes the remaining awards too. */
        $secondOrder = test_order((int) $buyer['id'], [['product_id' => $productId]]);
        $secondItem  = (int) $pdo->query('SELECT id FROM shop_order_items WHERE order_id = ' . $secondOrder . ' LIMIT 1')->fetchColumn();

        $award($secondOrder, $secondItem);

        $pdo->exec('DELETE FROM users WHERE id = ' . (int) $upline['id']);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards WHERE order_id = ' . $secondOrder)->fetchColumn());
    },

    /* ==========================================================
     *  Inert (P17A-T13)
     * ========================================================== */
    'P17A-T13 the stream stays off by default and adds no route or controller' => function () use ($appRoot): void {
        /* 17A's promise was "nothing reads the new objects". 17B started
         * reading the configuration, 17C added the money engine, 17D/17E
         * added the read-only surfaces and 17F ships the month-close job,
         * so the enduring half is narrower but sharper: the engine exists,
         * there is no controller and no route, no page writes the ledger,
         * the job itself writes no money row, the stream is off on a fresh
         * database, and nothing has paid. */
        foreach ([
            '/app/Models/UnilevelAward.php',
            '/app/Models/UnilevelCycle.php',
            '/app/Services/UnilevelService.php',
            '/app/Services/UnilevelException.php',
        ] as $file) {
            assert_true(is_file($appRoot . $file), $file . ' is the 17C engine');
        }

        assert_false(
            is_file($appRoot . '/app/Controllers/UnilevelController.php'),
            'no controller serves the stream (D-14)'
        );

        /* 17F ships the job; what stays true is that it pays nothing: the
         * only money write is inside UnilevelCycle::close(), and the job's
         * own source carries no wallet or award write. */
        assert_true(is_file($appRoot . '/bin/unilevel_month_close.php'), '17F ships the month-close job');

        $job = (string) file_get_contents($appRoot . '/bin/unilevel_month_close.php');

        assert_same(
            0,
            preg_match(
                '/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(ewallet_ledger|unilevel_awards|users)/i',
                $job
            ),
            'the month close writes no money row'
        );
        assert_same(0, preg_match('/WalletService|reverseForOrder/', $job), 'the month close never pays or reverses');

        /* 17D is read-only: neither a controller nor a view may write a
         * Phase 17 table. The engine's writes live in the models and the
         * service (plan §8 Rule 1). */
        $writePath = '/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(unilevel_(awards|activity|periods)|product_unilevel_levels)/i';

        foreach (['/app/Controllers', '/app/views'] as $folder) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(rtrim($appRoot, '/') . $folder, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                assert_same(
                    0,
                    preg_match($writePath, (string) file_get_contents($file->getPathname())),
                    str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot)))
                        . ' is read-only over the Phase 17 ledger'
                );
            }
        }

        /* No route knows the stream yet (D-14: no phase adds a route). */
        foreach (['/app/routes.php', '/app/routes_inventory.php'] as $file) {
            $source = (string) file_get_contents($appRoot . $file);
            assert_same(
                0,
                preg_match('/unilevel[-_](awards|activity|periods|repeat|levels)/i', $source),
                $file . ' has no repeat-purchase route'
            );
        }

        /* The feature is off on a fresh database: both masters, every
         * product, and the two money tables. Only 17C's tests turn it
         * on; this case proves the shipping default. */
        assert_same('0', setting('unilevel_enabled'), 'D-11: the global master starts off');
        assert_same(
            0,
            (int) db()->query('SELECT COUNT(*) FROM products WHERE unilevel_enabled <> 0')->fetchColumn(),
            'no product is switched on out of the box'
        );
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn());
        assert_same(
            0,
            (int) db()->query(
                "SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type IN ('unilevel', 'unilevel_reversal')"
            )->fetchColumn()
        );
    },
];
