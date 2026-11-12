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
 *    verification      five reconciliations clean
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

/** The 31 table names the counts helper reads. */
$allTables = [
    'users', 'remember_tokens', 'settings', 'audit_log', 'products', 'packages',
    'package_levels', 'package_products', 'carts', 'cart_items', 'ewallet_ledger',
    'ewallet_admin_topups', 'ewallet_transfers', 'payout_requests', 'addresses',
    'shop_orders', 'shop_order_items', 'shop_order_events', 'shop_payment_proofs',
    'shop_shipments', 'shop_invoices', 'shop_invoice_items', 'reg_codes',
    'activations', 'commissions', 'impersonation_log', 'live_revisions',
    'product_unilevel_levels', 'unilevel_awards', 'unilevel_activity', 'unilevel_periods',
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

/**
 * Build one scratch schema from an explicit list of source files.
 *
 * @param list<string> $files paths relative to the application root
 * @return array{0: PDO, 1: string}
 */
$build = static function (string $slot, array $files) use ($appRoot): array {
    [$pdo, $database] = test_scratch_database($slot);

    foreach ($files as $file) {
        test_run_sql_file($pdo, $appRoot . '/' . $file);
    }

    return [$pdo, $database];
};

/**
 * A natural-key snapshot of everything a reset must reproduce.
 *
 * Ids, timestamps and the excluded tables are deliberately absent: the
 * parity cases compare sku/slug/level/username/code, exactly like a
 * human comparing two screens (plan IR-7-T4, D-IR-15). `audit_log` and
 * `live_revisions` are excluded because a reset appends an audit row and
 * bumps 18 surfaces while a fresh build has neither; `last_reset_*` and
 * the dynamic `commission_ledger_since` are excluded from settings for
 * the same reason.
 *
 * @param bool $withDemo include the demo members, codes and ledger rows
 * @return array<string, array<string, mixed>>
 */
$naturalSnapshot = static function (PDO $pdo, bool $withDemo = false): array {
    $money = static fn (mixed $value): string => number_format((float) $value, 2, '.', '');

    $staff = [];

    foreach ($pdo->query(
        "SELECT username, role, email, full_name, member_status, is_active, reg_payment_method,
                package_id, sponsor_id, referral_code, last_login_at,
                ewallet_balance, withdrawable_balance, ewallet_sent_today, ewallet_sent_this_week
           FROM users WHERE role IN ('admin','superadmin') ORDER BY username"
    )->fetchAll() as $row) {
        $staff[(string) $row['username']] = [
            'role'             => (string) $row['role'],
            'email'            => (string) $row['email'],
            'full_name'        => (string) $row['full_name'],
            'member_status'    => (string) $row['member_status'],
            'is_active'        => (int) $row['is_active'],
            'payment_method'   => (string) $row['reg_payment_method'],
            'package_id'       => $row['package_id'] === null ? null : (int) $row['package_id'],
            'sponsor_id'       => $row['sponsor_id'] === null ? null : (int) $row['sponsor_id'],
            'referral_code'    => $row['referral_code'] === null ? null : (string) $row['referral_code'],
            'last_login_at'    => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
            'balance'          => $money($row['ewallet_balance']),
            'withdrawable'     => $money($row['withdrawable_balance']),
            'sent_today'       => $money($row['ewallet_sent_today']),
            'sent_this_week'   => $money($row['ewallet_sent_this_week']),
        ];
    }

    $settings = $pdo->query('SELECT key_name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach (['last_reset_at', 'last_reset_by', 'commission_ledger_since', 'unilevel_ledger_since'] as $dynamic) {
        unset($settings[$dynamic]);
    }

    ksort($settings, SORT_STRING);
    $settings = array_map(static fn (mixed $value): string => (string) $value, $settings);

    $products = [];

    foreach ($pdo->query(
        'SELECT sku, name, price, pv, stock, status, sort_order FROM products ORDER BY sku'
    )->fetchAll() as $row) {
        $products[(string) $row['sku']] = [
            'name'       => (string) $row['name'],
            'price'      => $money($row['price']),
            'pv'         => $money($row['pv']),
            'stock'      => (int) $row['stock'],
            'status'     => (string) $row['status'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    $packages = [];

    foreach ($pdo->query(
        'SELECT slug, name, price, product_value, direct_referral_bonus, indirect_enabled, status, sort_order
           FROM packages ORDER BY slug'
    )->fetchAll() as $row) {
        $packages[(string) $row['slug']] = [
            'name'                   => (string) $row['name'],
            'price'                  => $money($row['price']),
            'product_value'          => $money($row['product_value']),
            'direct_referral_bonus'  => $money($row['direct_referral_bonus']),
            'indirect_enabled'       => (int) $row['indirect_enabled'],
            'status'                 => (string) $row['status'],
            'sort_order'             => (int) $row['sort_order'],
        ];
    }

    $levels = [];

    foreach ($pdo->query(
        'SELECT p.slug AS package_slug, l.level, l.amount
           FROM package_levels l JOIN packages p ON p.id = l.package_id
          ORDER BY p.slug, l.level'
    )->fetchAll() as $row) {
        $levels[(string) $row['package_slug'] . '#L' . (int) $row['level']] = $money($row['amount']);
    }

    $contents = [];

    foreach ($pdo->query(
        'SELECT p.slug AS package_slug, pr.sku, pp.quantity
           FROM package_products pp
           JOIN packages p ON p.id = pp.package_id
           JOIN products pr ON pr.id = pp.product_id
          ORDER BY p.slug, pr.sku'
    )->fetchAll() as $row) {
        $contents[(string) $row['package_slug'] . '#' . (string) $row['sku']] = (int) $row['quantity'];
    }

    $countTables = [
        'addresses', 'activations', 'cart_items', 'carts', 'commissions',
        'ewallet_admin_topups', 'ewallet_transfers', 'impersonation_log',
        'payout_requests', 'remember_tokens', 'shop_invoice_items', 'shop_invoices',
        'shop_order_events', 'shop_order_items', 'shop_orders', 'shop_payment_proofs',
        'shop_shipments', 'reg_codes', 'ewallet_ledger',
    ];

    $counts = [
        'members' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn(),
    ];

    foreach ($countTables as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    $snapshot = [
        'staff'    => $staff,
        'settings' => $settings,
        'products' => $products,
        'packages' => $packages,
        'levels'   => $levels,
        'contents' => $contents,
        'counts'   => $counts,
    ];

    if (!$withDemo) {
        return $snapshot;
    }

    $demoMembers = [];

    foreach ($pdo->query(
        "SELECT u.username, u.role, u.member_status, u.is_active, u.referral_code,
                sponsor.username AS sponsor_username, pkg.slug AS package_slug,
                u.ewallet_balance, u.withdrawable_balance
           FROM users u
           LEFT JOIN users sponsor ON sponsor.id = u.sponsor_id
           LEFT JOIN packages pkg ON pkg.id = u.package_id
          WHERE u.role = 'member'
          ORDER BY u.username"
    )->fetchAll() as $row) {
        $demoMembers[(string) $row['username']] = [
            'role'          => (string) $row['role'],
            'member_status' => (string) $row['member_status'],
            'is_active'     => (int) $row['is_active'],
            'referral_code' => $row['referral_code'] === null ? null : (string) $row['referral_code'],
            'sponsor'       => $row['sponsor_username'] === null ? null : (string) $row['sponsor_username'],
            'package'       => $row['package_slug'] === null ? null : (string) $row['package_slug'],
            'balance'       => $money($row['ewallet_balance']),
            'withdrawable'  => $money($row['withdrawable_balance']),
        ];
    }

    $demoCodes = [];

    foreach ($pdo->query(
        "SELECT c.code, c.status, c.code_type, p.slug AS package_slug, c.price,
                CASE WHEN c.expires_at IS NULL THEN 'none'
                     WHEN c.expires_at < CURDATE() THEN 'past'
                     ELSE 'future' END AS expiry
           FROM reg_codes c JOIN packages p ON p.id = c.package_id
          ORDER BY c.code"
    )->fetchAll() as $row) {
        $demoCodes[(string) $row['code']] = [
            'status'   => (string) $row['status'],
            'type'     => (string) $row['code_type'],
            'package'  => (string) $row['package_slug'],
            'price'    => $money($row['price']),
            'expiry'   => (string) $row['expiry'],
        ];
    }

    $demoLedger = [];

    foreach ($pdo->query(
        'SELECT u.username, l.type, l.amount, l.balance_after, l.withdrawable_after, l.ref_type, l.ref_id
           FROM ewallet_ledger l JOIN users u ON u.id = l.user_id
          ORDER BY u.username, l.ref_id'
    )->fetchAll() as $row) {
        $demoLedger[] = [
            'user'          => (string) $row['username'],
            'type'          => (string) $row['type'],
            'amount'        => $money($row['amount']),
            'balance_after' => $money($row['balance_after']),
            'withdrawable'  => $money($row['withdrawable_after']),
            'ref_type'      => (string) $row['ref_type'],
            'ref_id'        => (int) $row['ref_id'],
        ];
    }

    $snapshot['demo_members'] = $demoMembers;
    $snapshot['demo_codes']   = $demoCodes;
    $snapshot['demo_ledger']  = $demoLedger;

    return $snapshot;
};

/**
 * Compare two snapshots section by section and print the differences.
 *
 * A bare "arrays differ" is useless for a parity gate; the failure names
 * the section and the expected/actual natural keys (plan IR-7-T4).
 *
 * @param array<string, array<string, mixed>> $fresh
 * @param array<string, array<string, mixed>> $reset
 */
$compareSnapshots = static function (array $fresh, array $reset, string $label): void {
    foreach ($fresh as $section => $expected) {
        $actual = $reset[$section] ?? null;

        if ($actual === $expected) {
            continue;
        }

        if (!is_array($actual)) {
            fail($label . ': the reset build has no "' . $section . '" section');
        }

        $diff = [];
        $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));

        foreach ($keys as $key) {
            $hasExpected = array_key_exists($key, $expected);
            $hasActual   = array_key_exists($key, $actual);

            if (!$hasExpected) {
                $diff[] = 'extra "' . $key . '" = ' . json_encode($actual[$key]);
                continue;
            }

            if (!$hasActual) {
                $diff[] = 'missing "' . $key . '" (expected ' . json_encode($expected[$key]) . ')';
                continue;
            }

            if ($expected[$key] !== $actual[$key]) {
                $diff[] = '"' . $key . '": expected ' . json_encode($expected[$key])
                    . ', got ' . json_encode($actual[$key]);
            }
        }

        fail(
            $label . ' — section "' . $section . '" differs ('
                . count($diff) . ' difference' . (count($diff) === 1 ? '' : 's') . '): '
                . implode('; ', array_slice($diff, 0, 6))
                . (count($diff) > 6 ? ' …' : '')
        );
    }
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
        assert_same(20, $plan['auto_increment']['statements']);
        assert_same(0, $plan['catalogue']['statements'], 'the catalogue is kept by default');
    },

    /* ==========================================================
     *  3. Default reset
     * ========================================================== */
    'default reset leaves a verified staff-only database' => function () use ($fresh, $service, $populate, $tableCounts, $byKey): void {
        [$pdo, $database] = $fresh();
        $populate($pdo);

        /* Phase 17: a configured ladder row must survive the reset. */
        $pdo->exec(
            "INSERT INTO product_unilevel_levels (product_id, level, percent)
             SELECT id, 1, 5.000 FROM products ORDER BY sku LIMIT 1"
        );

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

        /* Phase 17: the three money tables are emptied; the ladder is
         * catalogue and survives (plan §5 Rule 8). */
        assert_same(0, $after['unilevel_awards']);
        assert_same(0, $after['unilevel_activity']);
        assert_same(0, $after['unilevel_periods']);
        assert_same(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM product_unilevel_levels')->fetchColumn(),
            'the per-product ladder survives a business reset'
        );

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
     *  4b. Phase 17A: the repeat-purchase tables (plan §5 Rule 8)
     * ========================================================== */
    'P17A-T8 a business reset empties the repeat-purchase money and keeps the ladder' => function () use ($fresh, $service, $byKey): void {
        [$pdo, $database] = $fresh();

        $productId = (int) $pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
        $juan      = (int) $pdo->query("SELECT id FROM users WHERE username = 'juan'")->fetchColumn();
        $maria     = (int) $pdo->query("SELECT id FROM users WHERE username = 'maria'")->fetchColumn();

        /* A configured ladder (catalogue, must survive), one award, one
         * activity snapshot and one closed period (money, must be emptied). */
        $pdo->exec(
            "INSERT INTO product_unilevel_levels (product_id, level, percent)
             VALUES ({$productId}, 1, 5.000), ({$productId}, 2, 3.000)"
        );

        $pdo->exec(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, billing_name, shipping_name, shipping_address,
                subtotal, total, paid_at, unilevel_processed_at, unilevel_total, terms_accepted_at)
             VALUES ('ORD-P17A-1', {$juan}, 'paid', 'ewallet', 'Juan', 'Juan', 'Address',
                     300.00, 300.00, NOW(), NOW(), 15.00, NOW())"
        );
        $orderId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total, unit_base, base_total)
             VALUES ({$orderId}, {$productId}, 'Coffee', 1, 300.00, 300.00, 300.00, 300.00)"
        );
        $itemId = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO unilevel_awards
               (order_id, order_item_id, product_id, buyer_id, user_id, level,
                base_amount, rate_pct, amount, paid_at)
             VALUES ({$orderId}, {$itemId}, {$productId}, {$juan}, {$maria}, 1, 300.00, 5.000, 15.00, NOW())"
        );

        $pdo->exec(
            "INSERT INTO unilevel_activity (period, user_id, personal_purchases, qualified, evaluated_at)
             VALUES ('2026-10', {$maria}, 750.00, 1, NOW())"
        );
        $pdo->exec(
            "INSERT INTO unilevel_periods (period, closed_at, closed_by, threshold, members_total, qualified_count, award_total)
             VALUES ('2026-10', NOW(), NULL, 500.00, 1, 1, 15.00)"
        );

        $report = $service($pdo, $database)->run();
        assert_true($report['ok'], json_encode($report['verification']['checks']));

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_awards')->fetchColumn(), 'the awards are emptied');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(), 'the activity snapshot is emptied');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(), 'the closed period is emptied');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn(), 'the order is deleted');

        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM product_unilevel_levels')->fetchColumn(), 'the ladder survives');
        assert_same(
            6,
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'unilevel%'")->fetchColumn(),
            'the six settings survive'
        );

        $checks = $byKey($report['verification']['checks']);
        assert_true($checks['zero_transactional']['ok']);

        /* The reset's own step log names the new group. */
        $steps = [];

        foreach ($report['steps'] as $step) {
            $steps[(string) $step['key']] = $step;
        }

        assert_true(isset($steps['unilevel']), 'the reset reports a repeat-purchase step');
        assert_true((int) $steps['unilevel']['deleted'] >= 3, 'the step deleted the award, the snapshot and the period');

        /* A catalogue reset also clears the ladder (it is catalogue). */
        $second = $service($pdo, $database, ['reset_catalogue' => true])->run();
        assert_true($second['ok'], json_encode($second['verification']['checks']));
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM product_unilevel_levels')->fetchColumn());
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
            'impersonation', 'unilevel', 'orders', 'carts', 'addresses', 'wallet', 'payouts',
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
        assert_same(20, $report['auto_increment']['reset']);
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
    'verification runs the five reconciliations on the injected PDO' => function () use ($fresh, $service, $byKey): void {
        [$pdo, $database] = $fresh();
        $service($pdo, $database)->run();

        $verify = $service($pdo, $database)->verify();
        assert_true($verify['ok'], json_encode($verify['checks']));

        $checks = $byKey($verify['checks']);

        foreach (['members', 'zero_transactional', 'staff', 'catalogue', 'codes', 'audit', 'wallets', 'commissions', 'transfers', 'payouts', 'unilevel', 'live', 'auto_increment'] as $key) {
            assert_true(isset($checks[$key]), 'verify() carries "' . $key . '"');
            assert_true($checks[$key]['ok'], '"' . $key . '" must pass');
        }

        assert_contains('WalletService::reconcile clean', $checks['wallets']['detail']);
        assert_contains('CommissionService::reconcile clean', $checks['commissions']['detail']);
        assert_contains('TransferService::reconcile clean', $checks['transfers']['detail']);
        assert_contains('PayoutService::reconcile clean', $checks['payouts']['detail']);
        assert_contains('UnilevelService::reconcile clean', $checks['unilevel']['detail']);
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

    /* ==========================================================
     *  23. Demo reset (IR-7)
     * ========================================================== */
    'a demo reset installs the documented tree, wallets and codes' => function () use ($fresh, $service, $byKey): void {
        [$pdo, $database] = $fresh();

        /* The QA fixture already holds the demo tree: the reset deletes
         * it with every other member row and installs a fresh copy. That
         * is the routine the QA box needs between runs. */
        $report = $service($pdo, $database, ['demo' => true])->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));

        assert_same(true, $report['demo']['installed']);
        assert_same(DemoDataService::EXPECTED_MEMBERS, $report['demo']['members']);
        assert_same(DemoDataService::EXPECTED_CODES, $report['demo']['codes']);
        assert_same(DemoDataService::EXPECTED_LEDGER_ROWS, $report['demo']['ledger_rows']);
        assert_same(DemoDataService::PASSWORD, $report['demo']['credentials']);
        assert_same(
            ['balance' => '2000.00', 'withdrawable' => '1500.00'],
            $report['demo']['wallets']['juan']
        );
        assert_same(true, $report['options']['demo']);

        assert_same(5, $report['counts_after']['members']);
        assert_same(3, $report['counts_after']['reg_codes']);
        assert_same(3, $report['counts_after']['ewallet_ledger']);

        /* The step log carries the demo step. */
        $steps = [];

        foreach ($report['steps'] as $step) {
            $steps[(string) $step['key']] = $step;
        }

        assert_same('ok', $steps['demo']['status']);
        assert_same(11, (int) $steps['demo']['rows']);

        /* The database itself: tree, wallets, codes and the pending user. */
        $members = $pdo->query(
            "SELECT username, role, member_status FROM users WHERE role = 'member' ORDER BY username"
        )->fetchAll();

        assert_same(
            ['david', 'juan', 'maria', 'peter', 'sarah'],
            array_column($members, 'username')
        );

        $david = $pdo->query("SELECT member_status, package_id FROM users WHERE username = 'david'")->fetch();
        assert_same('pending', (string) $david['member_status']);
        assert_null($david['package_id']);

        foreach (DemoDataService::WALLETS as $username => [$balance, $withdrawable]) {
            $wallet = $pdo->query(
                'SELECT ewallet_balance, withdrawable_balance FROM users WHERE username = ' . $pdo->quote($username)
            )->fetch();

            assert_same($balance, number_format((float) $wallet['ewallet_balance'], 2, '.', ''), $username);
            assert_same($withdrawable, number_format((float) $wallet['withdrawable_balance'], 2, '.', ''), $username);
        }

        $codes = $pdo->query('SELECT code, status FROM reg_codes ORDER BY code')->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_same(
            [
                'DEMO-EXPD-4C9M' => 'expired',
                'DEMO-PKGA-2A7K' => 'unused',
                'DEMO-PKGB-3B8L' => 'unused',
            ],
            $codes
        );

        /* The demo verification check exists and passes. */
        $checks = $byKey($report['verification']['checks']);
        assert_true(isset($checks['demo']), 'verification carries the demo check');
        assert_true($checks['demo']['ok'], 'the demo check passes');

        /* The audit row records that demo data was requested. */
        $meta = json_decode((string) $pdo->query(
            "SELECT meta_json FROM audit_log WHERE action = 'system.db_reset' ORDER BY id DESC LIMIT 1"
        )->fetchColumn(), true);

        assert_same(true, $meta['demo']);
    },

    /* ==========================================================
     *  24. Demo + codes coexist, and a later plain reset clears it
     * ========================================================== */
    'demo codes and generated codes coexist and a plain reset clears both' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        $report = $service($pdo, $database, ['demo' => true, 'code_qty' => 2])->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));
        assert_same(2, $report['codes']['generated']);
        assert_same(2, count($report['codes']['list']));
        assert_same(5, (int) $pdo->query('SELECT COUNT(*) FROM reg_codes')->fetchColumn());

        $demoCodes = (int) $pdo->query("SELECT COUNT(*) FROM reg_codes WHERE code LIKE 'DEMO-%'")->fetchColumn();
        assert_same(3, $demoCodes, 'the three demo codes survive the generated batch');

        foreach ($report['codes']['list'] as $code) {
            assert_same(1, (int) $pdo->query(
                'SELECT COUNT(*) FROM reg_codes WHERE code = ' . $pdo->quote($code)
            )->fetchColumn());
        }

        /* A plain reset afterwards removes the demo tree too: reset ->
         * demo -> reset leaves no demo row (IR-7-T5). */
        $plain = $service($pdo, $database)->run();

        assert_true($plain['ok'], json_encode($plain['verification']['checks']));
        assert_same(false, $plain['demo']['installed']);
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM reg_codes')->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username IN ('juan','maria','peter','sarah','david')")->fetchColumn());
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM reg_codes WHERE code LIKE 'DEMO-%'")->fetchColumn());
    },

    /* ==========================================================
     *  25. Demo refusals write nothing
     * ========================================================== */
    'a demo reset is refused in production before any delete' => function () use ($fresh, $service, $tableCounts): void {
        [$pdo, $database] = $fresh();

        $before = $tableCounts($pdo);

        $error = assert_throws(
            static fn (): array => $service($pdo, $database, [
                'demo'             => true,
                'env'              => 'production',
                'allow_production' => true,
            ])->run(),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_REFUSED, $error->kind);
        assert_contains('production', $error->getMessage());
        assert_same($before, $tableCounts($pdo), 'nothing may change when demo data is refused');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    },

    'a demo reset is refused when the default packages are missing' => function () use ($fresh, $service): void {
        [$pdo, $database] = $fresh();

        /* Keep the catalogue option and remove package-b (its codes must
         * go first — fk_reg_codes_package is RESTRICT). */
        $pdo->exec('DELETE FROM reg_codes');
        $pdo->exec("DELETE FROM packages WHERE slug = 'package-b'");

        $before = [
            'members' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn(),
            'ledger'  => (int) $pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
            'packages'=> (int) $pdo->query('SELECT COUNT(*) FROM packages')->fetchColumn(),
        ];

        $error = assert_throws(
            static fn (): array => $service($pdo, $database, ['demo' => true])->run(),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_REFUSED, $error->kind);
        assert_contains('package-b', $error->getMessage());
        assert_same($before, [
            'members' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn(),
            'ledger'  => (int) $pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
            'packages'=> (int) $pdo->query('SELECT COUNT(*) FROM packages')->fetchColumn(),
        ], 'a refused demo request deletes nothing');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());

        /* DemoDataService refuses the same catalogue on its own, so the
         * installer path cannot write active members with a NULL package. */
        $availability = (new DemoDataService())->available($pdo, false, false);

        assert_false($availability['ok']);
        assert_contains('package-b', $availability['reason']);
    },

    /* ==========================================================
     *  26. Parity: reset == fresh production install (IR-7-T4)
     * ========================================================== */
    'reset parity: a default reset equals a fresh production install' => function () use ($build, $service, $naturalSnapshot, $compareSnapshots): void {
        $productionSeeds = [
            'database/schema.sql',
            'database/seeds/001_settings.sql',
            'database/seeds/002_accounts.sql',
            'database/seeds/003_products.sql',
            'database/seeds/004_packages.sql',
        ];

        [$pdoFresh, $freshDb] = $build('tool_a', $productionSeeds);
        [$pdoReset, $resetDb] = $build('tool_b', $productionSeeds);

        assert_same('ecowealth_sneat_tool_a_test', $freshDb);
        assert_same('ecowealth_sneat_tool_b_test', $resetDb);

        $before = $naturalSnapshot($pdoReset);

        /* The reset must change nothing the natural keys can see. */
        assert_same(
            0,
            $before['counts']['members'],
            'the fresh production install starts without members'
        );

        $report = $service($pdoReset, $resetDb)->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));

        $fresh = $naturalSnapshot($pdoFresh);
        $after = $naturalSnapshot($pdoReset);

        $compareSnapshots($fresh, $after, 'reset vs fresh production install');

        /* Every transactional table is empty in the reset build; the
         * excluded audit_log/live_revisions are not part of the snapshot. */
        foreach ($after['counts'] as $table => $count) {
            assert_same(0, $count, $table . ' must be empty after a reset without demo data');
        }
    },

    /* ==========================================================
     *  27. Parity: reset(catalogue+demo) == fresh QA install
     * ========================================================== */
    'reset parity: a catalogue+demo reset equals a fresh QA install' => function () use ($build, $service, $naturalSnapshot, $compareSnapshots): void {
        [$pdoFresh, $freshDb] = $build('tool_a', [
            'database/schema.sql',
            'database/seeds/001_settings.sql',
            'database/seeds/002_accounts.sql',
            'database/seeds/003_products.sql',
            'database/seeds/004_packages.sql',
            'database/seeds/005_demo_tree.sql',
        ]);

        [$pdoReset, $resetDb] = $build('tool_b', [
            'database/schema.sql',
            'database/seeds/001_settings.sql',
            'database/seeds/002_accounts.sql',
            'database/seeds/003_products.sql',
            'database/seeds/004_packages.sql',
        ]);

        $report = $service($pdoReset, $resetDb, [
            'reset_catalogue' => true,
            'demo'            => true,
        ])->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));
        assert_same(true, $report['demo']['installed']);

        $fresh = $naturalSnapshot($pdoFresh, true);
        $after = $naturalSnapshot($pdoReset, true);

        $compareSnapshots($fresh, $after, 'reset(catalogue+demo) vs fresh QA install');

        assert_same(5, $after['counts']['members']);
        assert_same(3, $after['counts']['reg_codes']);
        assert_same(3, $after['counts']['ewallet_ledger']);
        assert_same(0, $after['counts']['shop_orders'], 'the demo seed creates no shop order');
        assert_same(0, $after['counts']['commissions'], 'the demo seed creates no commission');
    },
];
