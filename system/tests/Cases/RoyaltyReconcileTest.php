<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyReconcileTest — Phase 19C finance checks (plan §14.12)
 *
 *  Proves that the Royalty ledger can be audited: a clean month
 *  reconciles, and every way of corrupting it (a missing ledger row,
 *  an orphan movement, an amount drift, a header drift, a pending
 *  award in a paid month) is reported as a mismatch, while an
 *  evaluated month waiting for finalisation is only a warning.
 *
 *  Also proves the operator-facing path: bin/wallet_reconcile.php has
 *  a Royalty section and fails only on mismatches (P19C-T46), and a
 *  reset runs the same service and leaves both tables empty
 *  (P19C-T47).
 *
 *  Run this group alone with:  php tests/run.php RoyaltyReconcile
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);
$period  = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());
$day     = substr(RoyaltyPeriod::bounds($period)[0], 0, 10);
$start   = RoyaltyPeriod::bounds($period)[0];

/** One member account created before the period end. */
$member = static function (string $username, ?int $sponsor = null) use ($day): int {
    db()->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status,
                            sponsor_id, is_active, created_at)
         VALUES (?, ?, 'x', ?, 'member', 'active', ?, 1, ?)"
    )->execute([$username, $username . '@p19c.rec', ucfirst($username), $sponsor, $day . ' 09:00:00']);

    return (int) db()->lastInsertId();
};

/** The 2/2/2/2 ladder, stream on, paid from the period start. */
$configure = static function (array $overrides = []) use ($start): void {
    $settings = $overrides + [
        'royalty_enabled'                 => '1',
        'royalty_pay_enabled'             => '1',
        'royalty_leader_directs'          => '2',
        'royalty_supervisor_leaders'      => '2',
        'royalty_manager_supervisors'     => '2',
        'royalty_director_managers'       => '2',
        'royalty_supervisor_quota'        => '500000',
        'royalty_manager_quota'           => '1000000',
        'royalty_director_quota'          => '5000000',
        'royalty_supervisor_group_pct'    => '3.000',
        'royalty_supervisor_personal_pct' => '5.000',
        'royalty_manager_group_pct'       => '5.000',
        'royalty_manager_personal_pct'    => '10.000',
        'royalty_director_group_pct'      => '10.000',
        'royalty_director_personal_pct'   => '15.000',
        'royalty_ledger_since'            => $start,
    ];

    foreach ($settings as $key => $value) {
        setting_set($key, (string) $value);
    }

    setting_cache_clear();
    setting_all();
};

/** One paid order with a real line total. */
$order = static function (int $userId, float $amount) use ($day): void {
    db()->prepare(
        "INSERT INTO products (sku, name, slug, price, pv, stock)
         VALUES ('RTY-REC', 'Royalty reconcile product', 'royalty-reconcile-product', 1.00, 1.00, 1000)"
    )->execute();

    $productId = (int) db()->lastInsertId();

    db()->prepare(
        "INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, total, billing_name,
            shipping_name, shipping_address, paid_at)
         VALUES (?, ?, 'paid', 'ewallet', ?, ?, 'Buyer', 'Buyer', 'Address', ?)"
    )->execute([
        'REC-' . strtoupper(bin2hex(random_bytes(5))),
        $userId,
        number_format($amount, 2, '.', ''),
        number_format($amount, 2, '.', ''),
        $day . ' 12:00:00',
    ]);

    $orderId = (int) db()->lastInsertId();

    db()->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, quantity, unit_price, line_total, reservation_state)
         VALUES (?, ?, 'Royalty reconcile product', 1, ?, ?, 'committed')"
    )->execute([
        $orderId,
        $productId,
        number_format($amount, 2, '.', ''),
        number_format($amount, 2, '.', ''),
    ]);
};

/**
 * The standard fixture: one Supervisor at the quota, evaluated and paid.
 *
 * @return int the supervisor id
 */
$closedMonth = static function () use ($configure, $member, $order): int {
    $configure();

    $top = $member('rec_sup');

    for ($l = 1; $l <= 2; $l++) {
        $leader = $member('rec_leader_' . $l, $top);

        for ($d = 1; $d <= 2; $d++) {
            $member('rec_direct_' . $l . '_' . $d, $leader);
        }
    }

    $order($top, 500000.0);

    $service = new RoyaltyService();
    $service->evaluate(RoyaltyPeriod::previous(RoyaltyPeriod::periodOf()));
    $service->pay(RoyaltyPeriod::previous(RoyaltyPeriod::periodOf()));

    return $top;
};

$kinds = static function (array $result): array {
    return array_map(
        static fn (array $mismatch): string => (string) $mismatch['kind'],
        $result['mismatches']
    );
};

return [
    /* ==========================================================
     *  A clean month (P19C-T32)
     * ========================================================== */

    'P19C-T32 a clean month reconciles' => function () use ($closedMonth, $period): void {
        $closedMonth();

        $result = (new RoyaltyService())->reconcile();

        assert_same(true, $result['ok'], json_encode($result['mismatches']));
        assert_same([], $result['mismatches']);
        assert_same(1, $result['periods']);
        assert_same(1, $result['paid_periods']);
        assert_same(1, $result['paid_rows']);
        assert_same(0, $result['pending_rows']);
        assert_same(40000.0, $result['paid_total']);
        assert_same(40000.0, $result['ledger_total']);
        assert_same(0, $result['cycle_members']);
        assert_same(0, $result['legacy_rows']);

        /* The plan's documented shape carries every key a caller reads. */
        foreach ([
            'ok', 'periods', 'paid_rows', 'paid_total', 'pending_rows', 'pending_total',
            'skipped_rows', 'ledger_total', 'cycle_members', 'legacy_rows',
            'mismatches', 'warnings',
        ] as $key) {
            assert_true(array_key_exists($key, $result), 'the reconcile result carries ' . $key);
        }
    },

    /* ==========================================================
     *  Corrupt ledgers (P19C-T33 … T36)
     * ========================================================== */

    'P19C-T33 a paid award without its wallet movement is a mismatch' => function () use ($closedMonth, $kinds): void {
        $closedMonth();

        db()->exec('UPDATE royalty_awards SET ledger_id = NULL');

        $result = (new RoyaltyService())->reconcile();

        assert_same(false, $result['ok']);
        assert_true(in_array('missing_ledger', $kinds($result), true), json_encode($kinds($result)));
    },

    'P19C-T34 a royalty movement with no award is a mismatch' => function () use ($closedMonth, $kinds): void {
        $closedMonth();

        db()->exec("UPDATE ewallet_ledger SET ref_id = 9999999 WHERE ref_type = 'royalty'");

        $result = (new RoyaltyService())->reconcile();

        assert_same(false, $result['ok']);
        assert_true(in_array('orphan_ledger', $kinds($result), true), json_encode($kinds($result)));
    },

    'P19C-T35 a movement whose amount differs from its award is a mismatch' => function () use ($closedMonth, $kinds): void {
        $closedMonth();

        db()->exec("UPDATE ewallet_ledger SET amount = amount + 1.00 WHERE ref_type = 'royalty'");

        $result = (new RoyaltyService())->reconcile();

        assert_same(false, $result['ok']);
        assert_true(in_array('amount', $kinds($result), true), json_encode($kinds($result)));
    },

    'P19C-T36 a period header that disagrees with its rows is a mismatch' => function () use ($closedMonth, $kinds, $period): void {
        $closedMonth();

        /* award_total is the header's own snapshot; inflating it keeps
         * the CHECKs happy but no longer matches the rows. */
        db()->prepare('UPDATE royalty_periods SET award_total = award_total + 1.00 WHERE period = ?')
            ->execute([$period]);

        $result = (new RoyaltyService())->reconcile();

        assert_same(false, $result['ok']);
        assert_true(in_array('period_total', $kinds($result), true), json_encode($kinds($result)));
    },

    /* ==========================================================
     *  Period state (P19C-T37)
     * ========================================================== */

    'P19C-T37 period state: a pending award in a paid month fails, an unfinalised month warns' => function () use ($closedMonth, $kinds, $period): void {
        $closedMonth();

        /* A paid month with a pending award is a books problem. */
        db()->exec("UPDATE royalty_awards SET status = 'pending'");

        $result = (new RoyaltyService())->reconcile();

        assert_same(false, $result['ok']);
        assert_true(in_array('period_state', $kinds($result), true), json_encode($kinds($result)));

        /* A crash after the last batch leaves an evaluated month with
         * no pending award: a warning, not a failure (plan §14.12). */
        db()->exec("UPDATE royalty_awards SET status = 'paid'");
        db()->prepare("UPDATE royalty_periods SET status = 'evaluated', paid_at = NULL WHERE period = ?")
            ->execute([$period]);

        $result = (new RoyaltyService())->reconcile();

        assert_same(true, $result['ok'], json_encode($result['mismatches']));
        assert_same(1, count($result['warnings']));
        assert_same('awaiting_finalisation', $result['warnings'][0]['kind']);
        assert_same($period, $result['warnings'][0]['period']);
    },

    /* ==========================================================
     *  The operator path (P19C-T46)
     * ========================================================== */

    'P19C-T46 wallet_reconcile has a Royalty section and fails only on mismatches' => function () use ($appRoot, $period, $day): void {
        [$pdo, $database] = test_scratch_database('tool_a');

        test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

        $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?')->execute(['1', 'royalty_enabled']);
        $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?')->execute(['2', 'royalty_leader_directs']);
        $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?')->execute(['2', 'royalty_supervisor_leaders']);
        $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?')->execute([$day . ' 00:00:00', 'royalty_ledger_since']);

        $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role, member_status,
                                sponsor_id, is_active, created_at)
             VALUES (?, ?, 'x', 'Sup', 'member', 'active', NULL, 1, ?)"
        )->execute(['rec46_sup', 'rec46_sup@test.local', $day . ' 09:00:00']);
        $top = (int) $pdo->lastInsertId();

        for ($l = 1; $l <= 2; $l++) {
            $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, full_name, role, member_status,
                                    sponsor_id, is_active, created_at)
                 VALUES (?, ?, 'x', 'Leader', 'member', 'active', ?, 1, ?)"
            )->execute(['rec46_leader_' . $l, 'rec46_leader_' . $l . '@test.local', $top, $day . ' 09:00:00']);
            $leader = (int) $pdo->lastInsertId();

            for ($d = 1; $d <= 2; $d++) {
                $pdo->prepare(
                    "INSERT INTO users (username, email, password_hash, full_name, role, member_status,
                                        sponsor_id, is_active, created_at)
                     VALUES (?, ?, 'x', 'Direct', 'member', 'active', ?, 1, ?)"
                )->execute([
                    'rec46_direct_' . $l . '_' . $d,
                    'rec46_direct_' . $l . '_' . $d . '@test.local',
                    $leader,
                    $day . ' 09:00:00',
                ]);
            }
        }

        $pdo->exec(
            "INSERT INTO products (sku, name, slug, price, pv, stock)
             VALUES ('RTY-REC46', 'Royalty reconcile product', 'royalty-reconcile-46', 1.00, 1.00, 1000)"
        );
        $productId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, subtotal, total, billing_name,
                shipping_name, shipping_address, paid_at)
             VALUES ('REC-46-ORDER', ?, 'paid', 'ewallet', 500000.00, 500000.00, 'Buyer',
                     'Buyer', 'Address', ?)"
        )->execute([$top, $day . ' 12:00:00']);
        $orderId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total, reservation_state)
             VALUES (?, ?, 'Royalty reconcile product', 1, 500000.00, 500000.00, 'committed')"
        )->execute([$orderId, $productId]);

        /* Close the month through the real CLI so the ledger, the award
         * and the header are the engine's own rows. */
        $previous = getenv('ECO_DB_NAME');
        putenv('ECO_DB_NAME=' . $database);

        try {
            $command = escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($appRoot . '/bin/royalty_month_close.php')
                . ' --period=' . escapeshellarg($period)
                . ' --log-dir=' . escapeshellarg(sys_get_temp_dir());

            exec($command . ' 2>&1', $lines, $code);

            $reconcileCommand = escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($appRoot . '/bin/wallet_reconcile.php') . ' --json';

            exec($reconcileCommand . ' 2>&1', $jsonLines, $jsonCode);

            $textCommand = escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($appRoot . '/bin/wallet_reconcile.php');

            exec($textCommand . ' 2>&1', $textLines, $textCode);

            /* Corrupt the ledger, then the same command must fail. */
            $pdo->exec('UPDATE royalty_awards SET ledger_id = NULL');

            exec($reconcileCommand . ' 2>&1', $brokenLines, $brokenCode);
        } finally {
            if ($previous === false) {
                putenv('ECO_DB_NAME');
            } else {
                putenv('ECO_DB_NAME=' . $previous);
            }
        }

        assert_same(0, $code, implode("\n", $lines));

        $payload = json_decode(implode("\n", $jsonLines), true);

        assert_true(is_array($payload), implode("\n", $jsonLines));
        assert_same(true, $payload['ok']);
        assert_same(true, $payload['royalty']['ok']);
        assert_same(1, (int) $payload['royalty']['paid_rows']);
        assert_same(40000.0, (float) $payload['royalty']['ledger_total']);
        assert_same(0, $jsonCode);

        $text = implode("\n", $textLines);
        assert_contains('Royalty', $text);
        assert_contains('royalty ledger', $text);
        assert_same(0, $textCode);

        $broken = json_decode(implode("\n", $brokenLines), true);

        assert_true(is_array($broken), implode("\n", $brokenLines));
        assert_same(false, $broken['ok']);
        assert_same(false, $broken['royalty']['ok']);
        assert_same(1, $brokenCode, 'a Royalty mismatch fails the reconcile command');
    },

    /* ==========================================================
     *  Reset integrity (P19C-T47)
     * ========================================================== */

    'P19C-T47 a reset runs the Royalty reconcile and leaves both tables empty' => function () use ($appRoot): void {
        [$pdo, $database] = test_scratch_database('tool_b');

        foreach ([
            'database/schema.sql',
            'database/seeds/001_settings.sql',
            'database/seeds/002_accounts.sql',
            'database/seeds/003_products.sql',
            'database/seeds/004_packages.sql',
            'database/seeds/005_demo_tree.sql',
        ] as $file) {
            test_run_sql_file($pdo, $appRoot . '/' . $file);
        }

        $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role, member_status,
                                referral_code, is_active)
             VALUES ('rec47_sup', 'rec47_sup@p19c.test', 'x', 'Sup', 'member', 'active', 'REC47', 1)"
        )->execute();
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO royalty_periods
               (period, status, enabled, evaluated_at, as_of,
                leader_directs, supervisor_leaders, manager_supervisors, director_managers,
                quota_supervisor, quota_manager, quota_director,
                group_pct_supervisor, personal_pct_supervisor,
                group_pct_manager, personal_pct_manager,
                group_pct_director, personal_pct_director,
                members_count, supervisors_count, award_count, award_total)
             VALUES ('2026-10', 'evaluated', 1, '2026-11-01 00:05:00', '2026-11-01 00:00:00',
                     10, 10, 10, 10, 500000.00, 1000000.00, 5000000.00,
                     3.000, 5.000, 5.000, 10.000, 10.000, 15.000,
                     1, 1, 1, 40000.00)"
        )->execute();

        $pdo->prepare(
            "INSERT INTO royalty_awards
               (period, user_id, rank_level, group_volume, personal_volume, quota,
                group_pct, personal_pct, amount, status)
             VALUES ('2026-10', ?, 2, 500000.00, 500000.00, 500000.00, 3.000, 5.000, 40000.00, 'pending')"
        )->execute([$userId]);

        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());

        $before = (new RoyaltyService($pdo))->reconcile();
        assert_same(true, $before['ok'], 'the fixture is a clean ledger before the reset');
        assert_same(40000.0, $before['pending_total']);

        $report = (new DatabaseResetService(
            $pdo,
            $database,
            ['confirm' => $database, 'tool' => 'cli']
        ))->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));

        $checks = [];

        foreach ($report['verification']['checks'] as $check) {
            $checks[(string) $check['key']] = $check;
        }

        assert_true(isset($checks['royalty']), 'the reset verification carries the Royalty check');
        assert_true($checks['royalty']['ok'], (string) $checks['royalty']['detail']);
        assert_contains('RoyaltyService::reconcile clean', (string) $checks['royalty']['detail']);

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());

        $after = (new RoyaltyService($pdo))->reconcile();
        assert_same(true, $after['ok']);
        assert_same(0, $after['periods']);
        assert_same(0, $after['paid_rows']);
        assert_same([], $after['mismatches']);
    },
];
