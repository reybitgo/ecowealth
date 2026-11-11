<?php
declare(strict_types=1);

/* ==============================================================
 *  DbResetTest — the reset engine (plan IR-5-T12, §7)
 *
 *  Every case recreates the pre-provisioned scratch schema
 *  ecowealth_sneat_tool_b_test from schema.sql + seeds 001–005, so the
 *  service starts from a realistic QA database (5 members, 2 staff,
 *  2 packages, 3 demo codes, 3 ledger rows). No case targets
 *  ecowealth_sneat or ecowealth_sneat_test (D-IR-16).
 *
 *  The cases prove the whole reset contract:
 *    preview/dry run   counts, no writes, no open transaction
 *    default reset     a staff-only database, every business row gone
 *    staff reset       identity/password kept, counters zeroed
 *    audit             kept (+1 row) or cleared (only the reset row)
 *    catalogue         kept by default; reset reproduces the seeds
 *    codes             unused, package-bound, priced, unique
 *    invalid options   refused before any delete
 *    idempotency       a second reset deletes nothing
 *    rollback          a forced failure leaves every row in place
 *    uploads           proof files removed, keepers kept
 *    live revisions    existing bumped, standard surfaces completed
 *    auto-increment    empty tables at 1, users never touched
 *    verification      four reconciliations clean
 *    safety gates      wrong confirmation / no staff / missing table /
 *                      production
 *    report            documented shape, codes list, warnings array
 *    commission fix    reconcile() reads the injected PDO's baseline
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/** The seed files that make a QA-shaped database. */
$seedFiles = [
    'database/schema.sql',
    'database/seeds/001_settings.sql',
    'database/seeds/002_accounts.sql',
    'database/seeds/003_products.sql',
    'database/seeds/004_packages.sql',
    'database/seeds/005_demo_tree.sql',
];

/**
 * Recreate tool_b_test from the schema + all five seeds.
 *
 * @return array{0: PDO, 1: string}
 */
$fresh = static function () use ($appRoot, $seedFiles): array {
    [$pdo, $database] = test_scratch_database('tool_b');

    foreach ($seedFiles as $file) {
        test_run_sql_file($pdo, $appRoot . '/' . $file);
    }

    return [$pdo, $database];
};

/**
 * A service bound to the scratch schema.
 *
 * @param array<string,mixed> $options
 * @return DatabaseResetService
 */
$service = static function (PDO $pdo, string $database, array $options = [], ?callable $onStep = null): DatabaseResetService {
    return new DatabaseResetService(
        $pdo,
        $database,
        $options + ['confirm' => $database, 'tool' => 'cli'],
        $onStep
    );
};

/** The 27 table names the counts helper reads. */
$allTables = [
    'users', 'remember_tokens', 'settings', 'audit_log', 'products', 'packages',
    'package_levels', 'package_products', 'carts', 'cart_items', 'ewallet_ledger',
    'ewallet_admin_topups', 'ewallet_transfers', 'payout_requests', 'addresses',
    'shop_orders', 'shop_order_items', 'shop_order_events', 'shop_payment_proofs',
    'shop_shipments', 'shop_invoices', 'shop_invoice_items', 'reg_codes',
    'activations', 'commissions', 'impersonation_log', 'live_revisions',
];

/** @return array<string,int> */
$tableCounts = static function (PDO $pdo) use ($allTables): array {
    $counts = [];

    foreach ($allTables as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    return $counts;
};

/** Index a verification/preflight check list by key. */
$byKey = static function (array $checks): array {
    $indexed = [];

    foreach ($checks as $check) {
        $indexed[(string) $check['key']] = $check;
    }

    return $indexed;
};

/** The AUTO_INCREMENT value of one table in the scratch schema. */
$nextId = static function (PDO $pdo, string $table): ?int {
    $statement = $pdo->prepare(
        'SELECT auto_increment FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $statement->execute([$table]);
    $value = $statement->fetchColumn();

    return $value === false || $value === null ? null : (int) $value;
};

/**
 * Make the database look like a busy QA box before a reset.
 *
 * Member and staff orders, an activation with a commission, a payout,
 * a transfer, an admin top-up, carts, addresses, proofs, invoices,
 * shipments, remember-me tokens and an S-Login session.
 */
$populate = static function (PDO $pdo): void {
    $exec = static function (string $sql) use ($pdo): void {
        $pdo->exec($sql);
    };

    $exec("SET @juan := (SELECT id FROM users WHERE username = 'juan' LIMIT 1)");
    $exec("SET @maria := (SELECT id FROM users WHERE username = 'maria' LIMIT 1)");
    $exec("SET @admin := (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1)");
    $exec("SET @super := (SELECT id FROM users WHERE role = 'superadmin' ORDER BY id LIMIT 1)");
    $exec("SET @pkg := (SELECT id FROM packages WHERE slug = 'package-a' LIMIT 1)");
    $exec("SET @prod := (SELECT id FROM products WHERE sku = 'EW-COFFEE' LIMIT 1)");

    /* A member order and a staff order: the reset is global (D-IR-06). */
    $exec("INSERT INTO shop_orders
             (order_no, user_id, status, payment_method, subtotal, total,
              billing_name, shipping_name, shipping_address, terms_version)
           VALUES ('ORD-IR5-1', @juan, 'paid', 'gcash', 300.00, 300.00,
                   'Juan', 'Juan', 'Address', 'v1'),
                  ('ORD-IR5-2', @admin, 'pending', 'ewallet', 50.00, 50.00,
                   'Admin', 'Admin', 'Address', 'v1')");

    $exec("INSERT INTO shop_order_items
             (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total)
           SELECT o.id, @prod, 'Coffee', 'EW-COFFEE', 1, 300.00, 300.00
             FROM shop_orders o WHERE o.order_no = 'ORD-IR5-1'");

    $exec("INSERT INTO shop_order_events (order_id, to_status, actor_type)
           SELECT o.id, 'paid', 'member' FROM shop_orders o WHERE o.order_no = 'ORD-IR5-1'");

    $exec("INSERT INTO shop_invoices
             (order_id, invoice_no, status_at_issue, seller_name, buyer_username,
              billing_name, shipping_name, shipping_address, payment_method)
           SELECT o.id, 'INV-IR5-1', 'paid', 'EcoWealth', 'juan', 'Juan', 'Juan', 'Address', 'gcash'
             FROM shop_orders o WHERE o.order_no = 'ORD-IR5-1'");

    $exec("INSERT INTO shop_invoice_items
             (invoice_id, line_no, description, sku, quantity, unit_price, amount)
           SELECT i.id, 1, 'Coffee', 'EW-COFFEE', 1, 300.00, 300.00
             FROM shop_invoices i WHERE i.invoice_no = 'INV-IR5-1'");

    $exec("INSERT INTO shop_payment_proofs
             (order_id, file_path, amount_claimed, reference_no)
           SELECT o.id, 'uploads/proofs/ir5-fixture.jpg', 300.00, 'REF-IR5'
             FROM shop_orders o WHERE o.order_no = 'ORD-IR5-1'");

    $exec("INSERT INTO shop_shipments (order_id, courier, tracking_number)
           SELECT o.id, 'J&T', 'TRK-IR5' FROM shop_orders o WHERE o.order_no = 'ORD-IR5-1'");

    $exec('INSERT INTO carts (user_id) VALUES (@maria)');

    $exec("INSERT INTO cart_items (cart_id, product_id, quantity, unit_price)
           SELECT c.id, @prod, 1, 300.00 FROM carts c
             JOIN users u ON u.id = c.user_id WHERE u.username = 'maria' LIMIT 1");

    $exec("INSERT INTO addresses (user_id, recipient_name, address_line, city)
           VALUES (@maria, 'Maria', 'Address', 'Manila')");

    $exec("INSERT INTO ewallet_transfers
             (sender_id, recipient_id, amount, fee, net_amount, note, status)
           VALUES (@juan, @maria, 50.00, 0.00, 50.00, 'fixture', 'completed')");

    $exec("INSERT INTO ewallet_admin_topups (admin_id, recipient_id, amount, note)
           VALUES (@admin, @juan, 100.00, 'fixture')");

    $exec("INSERT INTO payout_requests (user_id, amount, payout_method, payout_account)
           VALUES (@juan, 100.00, 'gcash', '09170000001')");

    $exec("INSERT INTO activations (user_id, package_id, amount, payment_method, status)
           VALUES (@juan, @pkg, 4498.00, 'gcash', 'approved')");

    $exec("INSERT INTO commissions
             (user_id, type, amount, level, source_user_id, package_id, activation_id, status)
           SELECT @admin, 'direct_referral', 500.00, 1, @juan, @pkg, a.id, 'skipped'
             FROM activations a WHERE a.user_id = @juan ORDER BY a.id LIMIT 1");

    $exec("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
           VALUES (@admin, 'SEL-IR5-ADMIN-00000001', REPEAT('a', 64), DATE_ADD(NOW(), INTERVAL 1 DAY)),
                  (@juan,  'SEL-IR5-JUAN-000000001', REPEAT('b', 64), DATE_ADD(NOW(), INTERVAL 1 DAY))");

    $exec("INSERT INTO impersonation_log
             (superadmin_id, superadmin_name, target_user_id, target_username,
              nonce_hash, ip, user_agent, expires_at)
           VALUES (@super, 'superadmin', @juan, 'juan', REPEAT('c', 64),
                   '127.0.0.1', 'fixture', DATE_ADD(NOW(), INTERVAL 1 HOUR))");
};

return [
    /* ==========================================================
     *  1. Preview
     * ========================================================== */
    'preview counts the QA database and writes nothing' => function () use ($fresh, $service, $tableCounts): void {
        [$pdo, $database] = $fresh();

        $before = $tableCounts($pdo);
        $preview = $service($pdo, $database)->preview();

        assert_same($database, $preview['database']);
        assert_same(5, $preview['members']);
        assert_same(3, $preview['counts']['ewallet_ledger']);
        assert_same(3, $preview['counts']['reg_codes']);
        assert_same(2, count($preview['staff']));
        assert_same('admin', $preview['staff'][0]['username']);
        assert_same(7, $preview['catalogue']['products']);
        assert_same(2, $preview['catalogue']['packages']);
        assert_same(18, $preview['catalogue']['levels']);
        assert_same(10, $preview['catalogue']['contents']);
        assert_null($preview['last_reset_at']);
        assert_same([], $preview['missing_tables']);
        assert_same([], $preview['warnings']);
        assert_same($before, $tableCounts($pdo), 'preview must not change a row');
    },

    /* ==========================================================
     *  2. Dry run
     * ========================================================== */
    'dry run plans the reset and writes nothing' => function () use ($fresh, $service, $tableCounts): void {
        [$pdo, $database] = $fresh();

        $before = $tableCounts($pdo);
        $dry    = $service($pdo, $database)->dryRun();

        assert_same(0, $dry['writes']);
        assert_same('dry_run', $dry['mode']);
        assert_true($dry['ok']);
        assert_true($dry['statements'] > 30);
        assert_same($before, $tableCounts($pdo), 'a dry run must not change a row');
        assert_false($pdo->inTransaction(), 'a dry run never starts a transaction');

        $plan = [];

        foreach ($dry['plan'] as $step) {
            $plan[(string) $step['key']] = $step;
        }

        foreach ([
            'lock', 'preflight', 'counts', 'orders', 'wallet', 'members', 'staff',
            'catalogue', 'audit', 'live', 'settings', 'auto_increment', 'uploads',
            'codes_generate', 'verify',
        ] as $key) {
            assert_true(isset($plan[$key]), 'the plan carries "' . $key . '"');
        }

        assert_same(7, $plan['orders']['statements']);
        assert_same(19, $plan['auto_increment']['statements']);
        assert_same(0, $plan['catalogue']['statements'], 'the catalogue is kept by default');
    },

    /* ==========================================================
     *  3. Default reset
     * ========================================================== */
    'default reset leaves a verified staff-only database' => function () use ($fresh, $service, $populate, $tableCounts, $byKey): void {
        [$pdo, $database] = $fresh();
        $populate($pdo);

        $settingsBefore = $pdo->query('SELECT key_name, value FROM settings ORDER BY key_name')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $productsBefore = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

        $report = $service($pdo, $database)->run();

        assert_true($report['ok'], 'the reset must verify: ' . json_encode($report['verification']['checks']));
        assert_same('db_reset', $report['tool']);
        assert_same('reset', $report['mode']);

        $after  = $tableCounts($pdo);
        $checks = $byKey($report['verification']['checks']);

        assert_same(0, $after['users'] - 2, 'only the two staff rows remain');
        assert_same(2, $after['users']);
        assert_same(0, $after['remember_tokens']);
        assert_same(0, $after['carts']);
        assert_same(0, $after['cart_items']);
        assert_same(0, $after['addresses']);
        assert_same(0, $after['shop_orders']);
        assert_same(0, $after['shop_order_items']);
        assert_same(0, $after['shop_order_events']);
        assert_same(0, $after['shop_payment_proofs']);
        assert_same(0, $after['shop_shipments']);
        assert_same(0, $after['shop_invoices']);
        assert_same(0, $after['shop_invoice_items']);
        assert_same(0, $after['ewallet_ledger']);
        assert_same(0, $after['ewallet_transfers']);
        assert_same(0, $after['ewallet_admin_topups']);
        assert_same(0, $after['payout_requests']);
        assert_same(0, $after['commissions']);
        assert_same(0, $after['activations']);
        assert_same(0, $after['reg_codes']);
        assert_same(0, $after['impersonation_log']);

        $settingsAfter = $pdo->query('SELECT key_name, value FROM settings ORDER BY key_name')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($settingsBefore as $key => $value) {
            assert_true(array_key_exists($key, $settingsAfter), 'setting "' . $key . '" is kept');
            assert_same((string) $value, (string) $settingsAfter[$key], 'setting "' . $key . '" is unchanged');
        }

        assert_true(array_key_exists('last_reset_at', $settingsAfter), 'last_reset_at is written');
        assert_true(array_key_exists('last_reset_by', $settingsAfter), 'last_reset_by is written');
        assert_same('cli', (string) $settingsAfter['last_reset_by']);
        assert_same(count($settingsBefore) + 2, count($settingsAfter), 'only the two reset keys are added');

        assert_same($productsBefore, $after['products'], 'the catalogue is kept by default');
        assert_same(1, $after['audit_log'], 'the reset row is the only audit row');
        assert_same(18, $after['live_revisions']);

        assert_same(2, $report['staff']['reset']);
        assert_same(['admin', 'superadmin'], $report['staff']['usernames']);
        assert_true($checks['members']['ok']);
        assert_true($checks['zero_transactional']['ok']);
        assert_true($checks['staff']['ok']);
        assert_true($checks['catalogue']['ok']);
        assert_true($checks['codes']['ok']);
        assert_true($checks['audit']['ok']);
        assert_true($checks['live']['ok']);
        assert_true($checks['auto_increment']['ok']);

        /* The step log proves the deletes really happened. */
        $steps = [];

        foreach ($report['steps'] as $step) {
            $steps[(string) $step['key']] = $step;
        }

        assert_true($steps['orders']['deleted'] >= 2, 'both fixtures orders and their children were deleted');
        assert_same(5, $steps['members']['deleted']);
        assert_true($steps['wallet']['deleted'] >= 1, 'the ledger/top-up/transfer rows were deleted');
        assert_same(3, $steps['codes']['deleted']);
        assert_same('skip', $steps['catalogue']['status']);
        assert_same('skip', $steps['codes_generate']['status']);
    },

    /* ==========================================================
     *  4. Staff reset
     * ========================================================== */
    'staff reset keeps identity and returns the state to install defaults' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $pdo->exec("SET @pkg := (SELECT id FROM packages WHERE slug = 'package-a')");
        $pdo->exec("SET @super := (SELECT id FROM users WHERE role = 'superadmin' LIMIT 1)");
        $pdo->exec("UPDATE users
                       SET package_id = @pkg,
                           sponsor_id = @super,
                           referral_code = 'ADMINREF1',
                           reg_payment_method = 'code',
                           activated_at = NOW(),
                           member_status = 'suspended',
                           is_active = 0,
                           ewallet_balance = 123.45,
                           withdrawable_balance = 100.00,
                           ewallet_sent_today = 5.00,
                           ewallet_sent_this_week = 7.00,
                           last_login_at = NOW()
                     WHERE role = 'admin'");

        $before = $pdo->query(
            "SELECT id, username, email, password_hash, full_name, role
               FROM users WHERE role IN ('admin','superadmin') ORDER BY id"
        )->fetchAll();

        $report = $service($pdo, $database)->run();
        assert_true($report['ok']);

        $after = $pdo->query(
            "SELECT id, username, email, password_hash, full_name, role, member_status, is_active,
                    package_id, sponsor_id, reg_code_id, reg_paid_by, reg_payment_method,
                    referral_code, activated_at, ewallet_balance, withdrawable_balance,
                    ewallet_sent_today, ewallet_sent_this_week, last_login_at
               FROM users WHERE role IN ('admin','superadmin') ORDER BY id"
        )->fetchAll();

        assert_same(count($before), count($after));

        foreach ($after as $index => $row) {
            /* Identity and access survive (D-IR-05). */
            assert_same((int) $before[$index]['id'], (int) $row['id']);
            assert_same((string) $before[$index]['username'], (string) $row['username']);
            assert_same((string) $before[$index]['email'], (string) $row['email']);
            assert_same((string) $before[$index]['password_hash'], (string) $row['password_hash']);
            assert_same((string) $before[$index]['role'], (string) $row['role']);

            /* Membership and money return to the install defaults. */
            assert_same('active', (string) $row['member_status']);
            assert_same(1, (int) $row['is_active']);
            assert_null($row['package_id']);
            assert_null($row['sponsor_id']);
            assert_null($row['reg_code_id']);
            assert_null($row['reg_paid_by']);
            assert_same('standard', (string) $row['reg_payment_method']);
            assert_null($row['referral_code']);
            assert_null($row['activated_at']);
            assert_same('0.00', number_format((float) $row['ewallet_balance'], 2, '.', ''));
            assert_same('0.00', number_format((float) $row['withdrawable_balance'], 2, '.', ''));
            assert_same('0.00', number_format((float) $row['ewallet_sent_today'], 2, '.', ''));
            assert_same('0.00', number_format((float) $row['ewallet_sent_this_week'], 2, '.', ''));
            assert_null($row['last_login_at']);
        }
    },

    /* ==========================================================
     *  5. Audit kept
     * ========================================================== */
    'a kept audit log grows by exactly one reset row' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $pdo->exec("INSERT INTO audit_log (actor_role, action, subject_type)
                    VALUES ('admin', 'settings.update', 'settings'),
                           ('admin', 'export.orders', 'report')");

        $before = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

        $report = $service($pdo, $database)->run();
        assert_true($report['ok']);
        assert_same(true, $report['audit']['kept']);
        assert_same(false, $report['audit']['cleared']);
        assert_same($before, $report['audit']['rows_before']);
        assert_same($before + 1, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());

        $row = $pdo->query(
            "SELECT action, actor_role, subject_type, meta_json, ip
               FROM audit_log ORDER BY id DESC LIMIT 1"
        )->fetch();

        assert_same('system.db_reset', (string) $row['action']);
        assert_same('system', (string) $row['actor_role']);
        assert_same('database', (string) $row['subject_type']);
        assert_same('127.0.0.1', (string) $row['ip']);

        $meta = json_decode((string) $row['meta_json'], true);
        assert_true(is_array($meta), 'the audit meta is JSON');

        foreach (['tool', 'database', 'options', 'counts_before', 'counts_after', 'catalogue_reset', 'codes', 'demo', 'audit_cleared'] as $key) {
            assert_true(array_key_exists($key, $meta), 'audit meta carries "' . $key . '"');
        }

        assert_same('cli', $meta['tool']);
        assert_same($database, $meta['database']);
        assert_same(5, (int) $meta['counts_before']['members']);
        assert_same(0, (int) $meta['counts_after']['members']);
    },

    /* ==========================================================
     *  6. Audit cleared
     * ========================================================== */
    'a cleared audit log starts with the reset row' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $pdo->exec("INSERT INTO audit_log (actor_role, action) VALUES
                      ('admin', 'a.one'), ('admin', 'a.two'), ('admin', 'a.three')");

        $report = $service($pdo, $database, ['clear_audit' => true])->run();

        assert_true($report['ok']);
        assert_same(false, $report['audit']['kept']);
        assert_same(true, $report['audit']['cleared']);
        assert_same(3, $report['audit']['rows_before']);
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        assert_same(
            'system.db_reset',
            (string) $pdo->query('SELECT action FROM audit_log ORDER BY id LIMIT 1')->fetchColumn()
        );

        /* Cleared audit + a code batch: the reset row is the only
         * pre-existing entry; the batch appends its own audit row. */
        $withCodes = $service($pdo, $database, [
            'clear_audit' => true,
            'code_qty'    => 2,
        ])->run();

        assert_true($withCodes['ok'], json_encode($withCodes['verification']['checks']));
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        assert_same(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'system.db_reset'"
        )->fetchColumn());
        assert_same(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'code.generate'"
        )->fetchColumn());
        assert_same(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_log
              WHERE action NOT IN ('system.db_reset', 'code.generate')"
        )->fetchColumn());
    },

    /* ==========================================================
     *  7. Catalogue reset
     * ========================================================== */
    'catalogue reset reproduces the seed state by natural key' => function () use ($fresh, $service, $byKey): void {
        [$pdo, $database] = $fresh();

        /* An operator's custom rows must not survive a catalogue reset. */
        $pdo->exec("INSERT INTO products (sku, name, slug, price)
                    VALUES ('EW-CUSTOM', 'Custom Product', 'custom-product', 123.00)");
        $pdo->exec("INSERT INTO packages (name, slug, price)
                    VALUES ('Custom Package', 'custom-package', 99.00)");

        $report = $service($pdo, $database, ['reset_catalogue' => true])->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));

        $skus = array_map(
            static fn (array $row): string => (string) $row['sku'],
            $pdo->query('SELECT sku FROM products ORDER BY sku')->fetchAll()
        );

        assert_same(
            ['EW-COFFEE', 'EW-COLONICS', 'EW-IMMUNE', 'EW-KOJIC', 'EW-SERPENTINA', 'EW-SPIRULINA', 'EW-STONE'],
            $skus
        );
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM products WHERE sku = 'EW-CUSTOM'")->fetchColumn());
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM packages WHERE slug = 'custom-package'")->fetchColumn());

        $packages = $pdo->query('SELECT slug, price FROM packages ORDER BY slug')->fetchAll();
        assert_same(['package-a', 'package-b'], array_column($packages, 'slug'));

        $packageA = $pdo->query("SELECT id, price FROM packages WHERE slug = 'package-a'")->fetch();
        assert_same('4498.00', number_format((float) $packageA['price'], 2, '.', ''));
        assert_same(18, (int) $pdo->query('SELECT COUNT(*) FROM package_levels')->fetchColumn());
        assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM package_products')->fetchColumn());
        assert_same(5, (int) $pdo->query(
            'SELECT COUNT(*) FROM package_products WHERE package_id = ' . (int) $packageA['id']
        )->fetchColumn());
        assert_same('100.00', number_format((float) $pdo->query(
            'SELECT amount FROM package_levels WHERE package_id = ' . (int) $packageA['id'] . ' AND level = 2'
        )->fetchColumn(), 2, '.', ''));

        $checks = $byKey($report['verification']['checks']);
        assert_true($checks['catalogue']['ok']);
        assert_same(7, $report['counts_after']['products']);
        assert_same(2, $report['counts_after']['packages']);
    },

    /* ==========================================================
     *  8. Codes
     * ========================================================== */
    'generated codes are unique unused package-bound and priced' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $report = $service($pdo, $database, ['code_qty' => 5])->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));
        assert_same(5, $report['codes']['generated']);
        assert_same('Package A', $report['codes']['package']);
        assert_same(5, count($report['codes']['list']));

        $rows = $pdo->query(
            'SELECT code, package_id, price, code_type, status, created_by, expires_at
               FROM reg_codes ORDER BY id'
        )->fetchAll();

        assert_same(5, count($rows));
        assert_same(5, count(array_unique(array_column($rows, 'code'))));
        assert_same($report['codes']['list'], array_column($rows, 'code'));

        $packageA = $pdo->query("SELECT id FROM packages WHERE slug = 'package-a'")->fetchColumn();
        $adminId  = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();

        foreach ($rows as $row) {
            assert_same((int) $packageA, (int) $row['package_id']);
            assert_same('4498.00', number_format((float) $row['price'], 2, '.', ''));
            assert_same('registration', (string) $row['code_type']);
            assert_same('unused', (string) $row['status']);
            assert_same((int) $adminId, (int) $row['created_by']);
            assert_null($row['expires_at']);
            assert_true((bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) $row['code']));
        }

        $audit = $pdo->query(
            "SELECT meta_json FROM audit_log WHERE action = 'code.generate' ORDER BY id DESC LIMIT 1"
        )->fetch();
        assert_true($audit !== false, 'the code batch is audited');
        $meta = json_decode((string) $audit['meta_json'], true);
        assert_same(5, (int) $meta['count']);
        assert_same('4498.00', (string) $meta['price']);
        assert_same($rows[0]['code'], (string) $meta['first_code']);
        assert_same($rows[4]['code'], (string) $meta['last_code']);
    },

    /* ==========================================================
     *  9. Invalid options
     * ========================================================== */
    'invalid options are refused before any delete' => function () use ($fresh, $service): void {
        foreach ([
            ['code_qty' => -1],
            ['code_qty' => 51],
            ['code_qty' => 2, 'code_package' => 999999],
        ] as $bad) {
            [$pdo, $database] = $fresh();

            try {
                $service($pdo, $database, $bad)->run();
                fail('Expected a refusal for ' . json_encode($bad));
            } catch (DatabaseToolException $e) {
                assert_same(DatabaseToolException::KIND_USAGE, $e->kind, json_encode($bad));
            }

            assert_same(5, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn());
            assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM reg_codes')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        }
    },

    /* ==========================================================
     *  10. Idempotency
     * ========================================================== */
    'a second reset deletes nothing and still verifies' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $first = $service($pdo, $database)->run();
        assert_true($first['ok']);

        $second = $service($pdo, $database)->run();
        assert_true($second['ok'], json_encode($second['verification']['checks']));

        $deleteKeys = [
            'impersonation', 'orders', 'carts', 'addresses', 'wallet', 'payouts',
            'commissions', 'codes', 'sessions', 'members',
        ];

        foreach ($second['steps'] as $step) {
            if (in_array((string) $step['key'], $deleteKeys, true)) {
                assert_same(0, (int) $step['deleted'], 'step "' . $step['key'] . '" must delete 0 rows');
            }
        }

        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn());
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.db_reset'")->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM reg_codes')->fetchColumn());
    },

    /* ==========================================================
     *  11. Rollback
     * ========================================================== */
    'a failure inside the transaction rolls every row back' => function () use ($fresh, $service, $populate, $tableCounts): void {
        [$pdo, $database] = $fresh();
        $populate($pdo);

        $before = $tableCounts($pdo);

        $hook = static function (int $index, array $step): void {
            if ((string) $step['key'] === 'members') {
                throw new RuntimeException('forced failure at the members step');
            }
        };

        try {
            $service($pdo, $database, [], $hook)->run();
            fail('Expected the injected failure to abort the reset');
        } catch (DatabaseToolException $e) {
            assert_same(DatabaseToolException::KIND_SQL, $e->kind);
            assert_same(true, $e->context['rolled_back'] ?? null);
            assert_same('members', $e->context['step'] ?? null);
        }

        assert_same($before, $tableCounts($pdo), 'every count must equal the pre-reset snapshot');
        assert_same(5, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn());
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.db_reset'")->fetchColumn());
        assert_false($pdo->inTransaction(), 'the failed transaction was closed');
    },

    /* ==========================================================
     *  12. Uploads
     * ========================================================== */
    'upload cleanup deletes proof files and keeps the keepers' => function () use ($fresh, $service): void {
        $root   = rtrim((string) UPLOAD_ROOT, '/\\') . '/proofs';
        $files  = ['ir5-proof-1.jpg', 'ir5-proof-2.jpg'];
        $keeper = ['.gitkeep', '.htaccess'];

        if (!is_dir($root)) {
            mkdir($root, 0775, true);
        }

        foreach (array_merge($files, $keeper) as $name) {
            file_put_contents($root . '/' . $name, 'fixture');
        }

        [$pdo, $database] = $fresh();
        $report = $service($pdo, $database)->run();

        assert_true($report['ok']);
        assert_true($report['uploads']['deleted'] >= 2, 'both fixture proof files are deleted');
        assert_same(0, $report['uploads']['failed']);

        foreach ($files as $name) {
            assert_false(is_file($root . '/' . $name), $name . ' must be deleted');
        }

        foreach ($keeper as $name) {
            assert_true(is_file($root . '/' . $name), $name . ' must be kept');
        }

        /* --keep-uploads leaves every file alone. */
        foreach ($files as $name) {
            file_put_contents($root . '/' . $name, 'fixture');
        }

        [$pdo2, $database2] = $fresh();
        $kept = $service($pdo2, $database2, ['keep_uploads' => true])->run();

        assert_true($kept['ok']);

        foreach ($files as $name) {
            assert_true(is_file($root . '/' . $name), $name . ' must survive --keep-uploads');
            @unlink($root . '/' . $name);
        }
    },

    /* ==========================================================
     *  13. Live revisions
     * ========================================================== */
    'live revisions increment existing surfaces and add the standard ones' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $pdo->exec("INSERT INTO live_revisions (surface, revision) VALUES
                      ('admin.dashboard', 1814),
                      ('member.wallet:5', 7)");

        $report = $service($pdo, $database)->run();
        assert_true($report['ok']);

        assert_same(1815, (int) $pdo->query(
            "SELECT revision FROM live_revisions WHERE surface = 'admin.dashboard'"
        )->fetchColumn());
        assert_same(8, (int) $pdo->query(
            "SELECT revision FROM live_revisions WHERE surface = 'member.wallet:5'"
        )->fetchColumn());

        $surfaces = $pdo->query('SELECT surface FROM live_revisions')->fetchAll(PDO::FETCH_COLUMN);
        assert_same(19, count($surfaces), '18 standard + the one per-member surface');

        foreach (DatabaseResetService::STANDARD_SURFACES as $surface) {
            assert_true(in_array($surface, $surfaces, true), 'surface "' . $surface . '" exists');
        }
    },

    /* ==========================================================
     *  14. AUTO_INCREMENT
     * ========================================================== */
    'AUTO_INCREMENT is reset on empty tables but never on users' => function () use ($fresh, $service, $populate, $nextId): void {
        [$pdo, $database] = $fresh();
        $populate($pdo);

        $usersBefore = $nextId($pdo, 'users');
        assert_true($usersBefore !== null && $usersBefore > 7);

        $report = $service($pdo, $database)->run();
        assert_true($report['ok']);
        assert_same(19, $report['auto_increment']['reset']);
        assert_same(0, $report['auto_increment']['failed']);

        foreach (['shop_orders', 'ewallet_ledger', 'reg_codes', 'activations', 'commissions'] as $table) {
            $next = $nextId($pdo, $table);
            assert_true($next === null || $next === 1, $table . ' next id is 1/NULL, got ' . var_export($next, true));
        }

        assert_same($usersBefore, $nextId($pdo, 'users'), 'users is never altered');
    },

    /* ==========================================================
     *  15. Verification
     * ========================================================== */
    'verification runs the four reconciliations on the injected PDO' => function () use ($fresh, $service, $byKey): void {
        [$pdo, $database] = $fresh();
        $service($pdo, $database)->run();

        $verify = $service($pdo, $database)->verify();
        assert_true($verify['ok'], json_encode($verify['checks']));

        $checks = $byKey($verify['checks']);

        foreach (['members', 'zero_transactional', 'staff', 'catalogue', 'codes', 'audit', 'wallets', 'commissions', 'transfers', 'payouts', 'live', 'auto_increment'] as $key) {
            assert_true(isset($checks[$key]), 'verify() carries "' . $key . '"');
            assert_true($checks[$key]['ok'], '"' . $key . '" must pass');
        }

        assert_contains('WalletService::reconcile clean', $checks['wallets']['detail']);
        assert_contains('CommissionService::reconcile clean', $checks['commissions']['detail']);
        assert_contains('TransferService::reconcile clean', $checks['transfers']['detail']);
        assert_contains('PayoutService::reconcile clean', $checks['payouts']['detail']);
    },

    /* ==========================================================
     *  16. Wrong confirmation
     * ========================================================== */
    'a wrong confirmation is refused and nothing changes' => function () use ($fresh, $service, $tableCounts): void {
        [$pdo, $database] = $fresh();
        $before = $tableCounts($pdo);

        try {
            $service($pdo, $database, ['confirm' => 'not-the-database'])->run();
            fail('Expected a refusal');
        } catch (DatabaseToolException $e) {
            assert_same(DatabaseToolException::KIND_REFUSED, $e->kind);
            assert_contains('does not match', $e->getMessage());
        }

        assert_same($before, $tableCounts($pdo));
    },

    /* ==========================================================
     *  17. No staff
     * ========================================================== */
    'no staff account is refused before deleting anything' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $pdo->exec("UPDATE users SET role = 'member' WHERE role IN ('admin','superadmin')");

        try {
            $service($pdo, $database)->run();
            fail('Expected a refusal');
        } catch (DatabaseToolException $e) {
            assert_same(DatabaseToolException::KIND_REFUSED, $e->kind);
            assert_contains('no staff', $e->getMessage());
        }

        assert_same(7, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn());
        assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM reg_codes')->fetchColumn());
    },

    /* ==========================================================
     *  18. Missing table
     * ========================================================== */
    'a missing table is refused before deleting anything' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $pdo->exec('DROP TABLE payout_requests');

        try {
            $service($pdo, $database)->run();
            fail('Expected a refusal');
        } catch (DatabaseToolException $e) {
            assert_same(DatabaseToolException::KIND_REFUSED, $e->kind);
            assert_contains('required tables', strtolower($e->getMessage()));
        }

        assert_same(5, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn());
        assert_same(7, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    },

    /* ==========================================================
     *  19. Production guard
     * ========================================================== */
    'production requires an explicit allow-production' => function () use ($fresh, $service, $tableCounts): void {
        [$pdo, $database] = $fresh();
        $before = $tableCounts($pdo);

        try {
            $service($pdo, $database, ['env' => 'production'])->run();
            fail('Expected a production refusal');
        } catch (DatabaseToolException $e) {
            assert_same(DatabaseToolException::KIND_REFUSED, $e->kind);
            assert_contains('--allow-production', (string) $e->hint);
        }

        assert_same($before, $tableCounts($pdo));

        $allowed = $service($pdo, $database, [
            'env'              => 'production',
            'allow_production' => true,
        ])->run();

        assert_true($allowed['ok'], json_encode($allowed['verification']['checks']));
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn());
    },

    /* ==========================================================
     *  20. Report shape
     * ========================================================== */
    'the report carries the documented keys and the generated codes' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $report = $service($pdo, $database, ['code_qty' => 2])->run();

        foreach ([
            'tool', 'version', 'ok', 'database', 'started_at', 'duration_ms', 'options',
            'steps', 'counts_before', 'counts_after', 'staff', 'audit', 'auto_increment',
            'codes', 'demo', 'verification', 'warnings',
        ] as $key) {
            assert_true(array_key_exists($key, $report), 'the report carries "' . $key . '"');
        }

        assert_same('db_reset', $report['tool']);
        assert_same(DatabaseResetService::REPORT_VERSION, $report['version']);
        assert_same($database, $report['database']);
        assert_same(5, (int) $report['counts_before']['members']);
        assert_same(0, (int) $report['counts_after']['members']);
        assert_same(2, $report['codes']['generated']);
        assert_same(2, count($report['codes']['list']));
        assert_same(2, $report['staff']['reset']);
        assert_same(false, $report['demo']['installed']);
        assert_same([], $report['warnings']);
        assert_true(is_array($report['steps']) && $report['steps'] !== []);
        assert_true($report['duration_ms'] >= 0);
        assert_true(is_string($report['started_at']));
        assert_same(true, $report['verification']['ok']);
    },

    /* ==========================================================
     *  21. CommissionService injected PDO (IR-5-T10)
     * ========================================================== */
    'CommissionService reads the commission baseline from the injected PDO' => function () use ($fresh): void {
        [$pdo, $database] = $fresh();

        /* A commission ledger row from the past, and a baseline far in
         * the future: the row is "legacy" and must be excluded. Reading
         * the application's setting through the global helper() would
         * include it and report an orphan. */
        $pdo->exec("UPDATE settings SET value = '2099-01-01 00:00:00'
                     WHERE key_name = 'commission_ledger_since'");

        $pdo->exec("INSERT INTO ewallet_ledger
                      (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
                    SELECT id, 'credit', 500.00, 2500.00, 2000.00, 'commission', 999, 'legacy fixture'
                      FROM users WHERE username = 'juan'");

        CommissionService::resetSchemaCache();

        $reconcile = (new CommissionService($pdo))->reconcile();

        assert_true($reconcile['ok'], 'the legacy row must not fail the reconciliation');
        assert_same(0, $reconcile['credited_rows']);
        assert_same(0.0, $reconcile['ledger_total']);
        assert_same(500.0, $reconcile['legacy_total']);
        assert_same(1, $reconcile['legacy_rows']);
        assert_same([], $reconcile['mismatches']);
    },

    /* ==========================================================
     *  22. Codes chosen before a catalogue reset
     * ========================================================== */
    'codes chosen before a catalogue reset follow the re-seeded package' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        /* The operator picks the package that exists *now*; a catalogue
         * reset deletes and re-seeds it, so the batch must follow the
         * re-seeded row (D-IR-15: ids continue, references use the new
         * ids) instead of failing on the old id. */
        $oldId = (int) $pdo->query("SELECT id FROM packages WHERE slug = 'package-a'")->fetchColumn();

        $report = $service($pdo, $database, [
            'reset_catalogue' => true,
            'code_qty'        => 3,
            'code_package'    => $oldId,
        ])->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));
        assert_same(3, $report['codes']['generated']);
        assert_same('Package A', $report['codes']['package']);

        $newId = (int) $pdo->query("SELECT id FROM packages WHERE slug = 'package-a'")->fetchColumn();
        assert_same($newId, (int) $report['codes']['package_id']);

        $bound = array_map(
            static fn (mixed $value): int => (int) $value,
            $pdo->query('SELECT DISTINCT package_id FROM reg_codes')->fetchAll(PDO::FETCH_COLUMN)
        );

        assert_same([$newId], $bound, 'every generated code is bound to the re-seeded package');
        assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM reg_codes')->fetchColumn());
    },
];
