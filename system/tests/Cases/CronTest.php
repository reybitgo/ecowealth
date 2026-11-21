<?php
declare(strict_types=1);

/* ==============================================================
 *  CronTest — the shop-expiry job's supporting pieces
 *  (plan §5.4, §7 Phase 7 follow-up)
 *
 *  Covers what the shell-level smoke checks cannot: the access
 *  decision for the web entry, the exclusive lock, the monthly log,
 *  the kill switch and the `--from-settings` recomputation.
 *
 *  Each case runs inside the harness transaction, so its orders and
 *  setting changes roll back; scratch log/lock files are removed here.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Cron Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'CRN-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('cron_' . bin2hex(random_bytes(4)));
};

$placeManual = static function (int $userId, int $productId): int {
    (new CartService())->add($userId, $productId, 1);

    return (new OrderService())->placeFromCart($userId, [
        'billing_name'      => 'Juan Dela Cruz',
        'shipping_name'     => 'Juan Dela Cruz',
        'shipping_address'  => '12 Mabini Street',
        'shipping_city'     => 'Makati',
        'payment_method'    => 'gcash',
        'idempotency_key'   => bin2hex(random_bytes(16)),
        'terms'             => '1',
    ])['order_id'];
};

$submitProof = static function (int $userId, int $orderId): void {
    $tmp = (string) tempnam(sys_get_temp_dir(), 'cronproof');
    file_put_contents($tmp, TEST_PNG_BYTES);

    (new PaymentService())->submitProof($userId, $orderId, [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ], [
        'amount_sent'  => '25.00',
        'reference_no' => 'CRON-' . strtoupper(bin2hex(random_bytes(3))),
    ]);
};

/** Run a case with temporary settings, restoring them afterwards. */
$withSettings = static function (array $values, callable $case): void {
    $before = [];

    foreach ($values as $key => $value) {
        $before[$key] = (string) setting($key, '');
        setting_set($key, (string) $value);
    }

    setting_cache_clear();

    try {
        $case();
    } finally {
        foreach ($before as $key => $value) {
            setting_set($key, $value);
        }

        setting_cache_clear();
    }
};

/* --------------------------------------------------------------
 *  Phase 17F — the unilevel month close (plan §10, §16.5)
 *
 *  The job is a CLI script with an exit code, so its cases run the
 *  real command against a scratch schema, exactly as P17C-T32 does:
 *  a subprocess cannot see the harness's rolled-back transaction.
 * -------------------------------------------------------------- */

$appRoot = dirname(__DIR__, 2);

/** A scratch schema in the app's real shape, plus the settings seed. */
$closeScratch = static function () use ($appRoot): array {
    [$pdo, $database] = test_scratch_database('tool_b');

    test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
    test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

    return [$pdo, $database];
};

/**
 * One month of activity: a paid buyer (₱600, qualifies) and an unpaid
 * buyer (₱900 pending, never counted). The unpaid order is what proves
 * `paid_at` decides the month.
 *
 * @return array{paid:int, unpaid:int}
 */
$closeFixture = static function (PDO $pdo, string $period, string $tag = 'a'): array {
    $users = $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active)
         VALUES (?, ?, ?, ?, \'member\', \'active\', 1)'
    );

    $users->execute(['p17f_' . $tag . '_paid', 'p17f_' . $tag . '_paid@test.local', 'x', 'Paid Buyer']);
    $paidId = (int) $pdo->lastInsertId();

    $users->execute(['p17f_' . $tag . '_unpaid', 'p17f_' . $tag . '_unpaid@test.local', 'x', 'Unpaid Buyer']);
    $unpaidId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO products (sku, name, slug, price, pv, unilevel_enabled, stock, status)
         VALUES (?, ?, ?, 300.00, 250.00, 1, 100, \'active\')'
    )->execute(['P17F-' . strtoupper($tag), 'Close fixture ' . $tag, 'p17f-close-' . $tag]);

    $productId = (int) $pdo->lastInsertId();

    $order = $pdo->prepare(
        'INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, total, billing_name,
            shipping_name, shipping_address, paid_at, terms_accepted_at)
         VALUES (?, ?, ?, \'ewallet\', ?, ?, \'Buyer\', \'Buyer\', \'Address\', ?, NOW())'
    );

    $item = $pdo->prepare(
        'INSERT INTO shop_order_items
           (order_id, product_id, product_name, quantity, unit_price, line_total,
            unit_base, base_total, reservation_state)
         VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)'
    );

    $put = static function (
        string $orderNo, int $userId, string $status, string $total, ?string $paidAt
    ) use ($pdo, $order, $item, $productId): int {
        $order->execute([$orderNo, $userId, $status, $total, $total, $paidAt]);
        $orderId = (int) $pdo->lastInsertId();

        $item->execute([
            $orderId, $productId, 'Close fixture', $total, $total, $total, $total,
            $status === 'paid' ? 'committed' : 'reserved',
        ]);

        return $orderId;
    };

    $put('P17F-' . strtoupper($tag) . '-PAID', $paidId, 'paid', '600.00', $period . '-15 10:00:00');
    $put('P17F-' . strtoupper($tag) . '-UNPAID', $unpaidId, 'pending', '900.00', null);

    return ['paid' => $paidId, 'unpaid' => $unpaidId];
};

/** Move the ledger baseline, which decides what "pending" means. */
$closeBaseline = static function (PDO $pdo, string $period): void {
    $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?')
        ->execute([$period . '-01 00:00:00', 'unilevel_ledger_since']);
};

/** A throw-away log folder for one case. */
$closeLogDir = static function (): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecowealth-close-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);

    return $dir;
};

/** Remove a throw-away log folder (and whatever the run wrote in it). */
$closeCleanup = static function (string $dir): void {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($dir);
};

/**
 * Run the real command against a scratch schema.
 *
 * @param list<string> $arguments
 * @return array{exit:int, output:string, json:?array}
 */
$runClose = static function (string $database, array $arguments = [], ?string $logDir = null) use ($appRoot): array {
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($appRoot . '/bin/unilevel_month_close.php');

        if ($logDir !== null) {
            $command .= ' --log-dir=' . escapeshellarg($logDir);
        }

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        /* 2>&1 so a refusal's stderr is part of the transcript. */
        exec($command . ' 2>&1', $lines, $code);
    } finally {
        if ($previous === false) {
            putenv('ECO_DB_NAME');
        } else {
            putenv('ECO_DB_NAME=' . $previous);
        }
    }

    $output = implode("\n", $lines);
    $json   = json_decode($output, true);

    return ['exit' => $code, 'output' => $output, 'json' => is_array($json) ? $json : null];
};

/** The current month's log file name under a run's `--log-dir`. */
$closeLogFile = static fn (string $dir): string => $dir . DIRECTORY_SEPARATOR
    . 'unilevel_month_close-' . date('Y-m') . '.log';

/** @return list<string> the log's non-empty lines */
$closeLogLines = static function (string $dir) use ($closeLogFile): array {
    $file = $closeLogFile($dir);

    if (!is_file($file)) {
        return [];
    }

    return array_values(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
};

/** Parse one `key=value key=value` cron log line. */
$closeFields = static function (string $line): array {
    preg_match_all('/([a-z_]+)=(\S+)/', $line, $matches, PREG_SET_ORDER);

    $fields = [];

    foreach ($matches as $match) {
        $fields[$match[1]] = $match[2];
    }

    return $fields;
};

return [
    /* ---------- Access decision (web entry) ------------------------ */

    'the web cron is trusted on loopback and needs the key elsewhere' => function (): void {
        /* Loopback is the local developer's browser / the server itself. */
        assert_true(CronAccess::allowed('127.0.0.1', null, null));
        assert_true(CronAccess::allowed('::1', null, null));
        assert_true(CronAccess::allowed('127.0.0.1', 'whatever', 'secret'));

        /* Anywhere else needs the exact configured key. */
        assert_false(CronAccess::allowed('203.0.113.9', null, null));
        assert_false(CronAccess::allowed('203.0.113.9', 'secret', null));
        assert_false(CronAccess::allowed('203.0.113.9', 'wrong', 'secret'));
        assert_false(CronAccess::allowed('203.0.113.9', '', 'secret'));
        assert_true(CronAccess::allowed('203.0.113.9', 'secret', 'secret'));
    },

    /* ---------- Lock ------------------------------------------------- */

    'the cron lock allows one run at a time' => function (): void {
        $name = 'test-lock-' . bin2hex(random_bytes(4));
        $first  = new CronLock($name);
        $second = new CronLock($name);

        assert_same(CronLock::defaultDirectory(), dirname($first->path()));
        assert_contains($name . '.lock', $first->path());
        assert_true($first->isFree(), 'a fresh lock is free');

        assert_true($first->acquire());
        assert_false($second->acquire(), 'a second handle cannot take the lock');
        assert_false($first->isFree());

        $first->release();

        assert_true($second->acquire(), 'the lock is available after the first release');
        $second->release();
        assert_true($first->isFree());
    },

    /* ---------- Log -------------------------------------------------- */

    'the cron log writes one monthly line per run' => function (): void {
        $prefix = 'cron_test_' . bin2hex(random_bytes(3));
        $log    = new CronLog(null, $prefix);
        $file   = $log->fileFor();

        assert_true(str_starts_with($file, CronLog::defaultDirectory()));
        assert_true(str_ends_with($file, '-' . date('Y-m') . '.log'));
        assert_true($log->isWritable());

        assert_true($log->write('OK', "expired=2 ids=57,58\nsecond line flattened"));
        assert_true(is_file($file));

        $line = (string) $log->latestLine();
        assert_contains('[OK   ]', $line);
        assert_contains('expired=2 ids=57,58 second line flattened', $line);
        assert_false(str_contains($line, PHP_EOL), 'one run is one line');

        /* Unknown levels are still recorded, lower-cased input is fixed. */
        assert_true($log->write('warn', 'skipped: disabled'));
        assert_contains('[WARN ]', (string) $log->latestLine());
        assert_contains('skipped: disabled', (string) $log->latestLine());

        assert_false($log->write('INFO', '   '), 'blank messages are ignored');
        @unlink($file);
    },

    /* ---------- Kill switch ----------------------------------------- */

    'the expiry sweep obeys the admin kill switch' => function () use ($makeProduct, $makeMember, $placeManual, $withSettings): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);
        $orderId = $placeManual($userId, $product);

        db()->prepare('UPDATE shop_orders SET payment_deadline = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 3600), $orderId]);

        $withSettings(['shop_expiry_enabled' => '0'], function () use ($orderId): void {
            assert_false(PaymentService::expiryEnabled());

            $result = (new PaymentService())->expireOverdue();

            assert_same('disabled', $result['skipped']);
            assert_same(0, $result['expired']);
            assert_same('pending', Order::find($orderId)['status'], 'nothing is cancelled while paused');
        });

        $withSettings(['shop_expiry_enabled' => '1'], function () use ($orderId): void {
            assert_true(PaymentService::expiryEnabled());

            $result = (new PaymentService())->expireOverdue();

            assert_same(null, $result['skipped']);
            assert_true(in_array($orderId, $result['ids'], true));
            assert_same('cancelled', Order::find($orderId)['status']);
        });
    },

    /* ---------- Settings mode --------------------------------------- */

    'the settings mode recomputes a pending order from the payment window' => function () use ($makeProduct, $makeMember, $placeManual, $withSettings): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);
        $orderId = $placeManual($userId, $product);

        /* The stored stamp is 24h out; make the order look 3h old. */
        db()->prepare('UPDATE shop_orders SET created_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE id = ?')
            ->execute([$orderId]);

        $service = new PaymentService();

        $withSettings(['shop_payment_deadline_hours' => '24'], function () use ($service, $orderId): void {
            assert_false(
                in_array($orderId, $service->overdueOrderIds(200, true), true),
                'still inside the 24h window'
            );
            assert_false(in_array($orderId, $service->overdueOrderIds(200), true));
        });

        $withSettings(['shop_payment_deadline_hours' => '1'], function () use ($service, $orderId): void {
            assert_true(
                in_array($orderId, $service->overdueOrderIds(200, true), true),
                'past the recomputed 1h window'
            );
            assert_false(
                in_array($orderId, $service->overdueOrderIds(200), true),
                'the stored 24h stamp still governs the default mode'
            );

            $result = $service->expireOverdue(200, true);

            assert_same('settings', $result['mode']);
            assert_true(in_array($orderId, $result['ids'], true));
            assert_same('cancelled', Order::find($orderId)['status']);

            $events = Order::events($orderId);
            $last   = end($events);
            assert_same('payment_expired', $last['reason_code']);

            $again = $service->expireOverdue(200, true);
            assert_same(0, $again['expired']);
        });
    },

    'the settings mode measures a rejected order from its rejection' => function () use ($makeProduct, $makeMember, $placeManual, $submitProof, $withSettings): void {
        $member   = $makeMember();
        $userId   = (int) $member['id'];
        $product  = $makeProduct(['stock' => '5']);
        $orderId  = $placeManual($userId, $product);

        $submitProof($userId, $orderId);
        (new PaymentService())->reject((int) test_user('cron_admin', 'admin')['id'], $orderId, 'unclear_proof', 'Try again');
        assert_same('payment_failed', Order::find($orderId)['status']);

        /* Rejection happened 5h ago, but the stored stamp is +24h. */
        db()->prepare(
            "UPDATE shop_order_events SET created_at = DATE_SUB(NOW(), INTERVAL 5 HOUR)
              WHERE order_id = ? AND reason_code = 'payment_rejected'"
        )->execute([$orderId]);

        $service = new PaymentService();

        $withSettings(['shop_correction_window_hours' => '1'], function () use ($service, $orderId): void {
            assert_true(in_array($orderId, $service->overdueOrderIds(200, true), true));
            assert_false(in_array($orderId, $service->overdueOrderIds(200), true));

            $result = $service->expireOverdue(200, true);

            assert_true(in_array($orderId, $result['ids'], true));
            assert_same('cancelled', Order::find($orderId)['status']);
            assert_same('released', Order::items($orderId)[0]['reservation_state']);
        });
    },

    'the settings mode keeps a fresh order alive' => function () use ($makeProduct, $makeMember, $placeManual, $withSettings): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);
        $orderId = $placeManual($userId, $product);

        $withSettings(['shop_payment_deadline_hours' => '24'], function () use ($orderId): void {
            $service = new PaymentService();

            assert_false(in_array($orderId, $service->overdueOrderIds(200, true), true));
            assert_same(0, $service->expireOverdue(200, true)['expired']);
            assert_same('pending', Order::find($orderId)['status']);
        });
    },

    /* ---------- Return shape --------------------------------------- */

    'the sweep reports candidates, mode and expiry state' => function () use ($makeProduct, $makeMember, $placeManual): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);
        $orderId = $placeManual($userId, $product);

        db()->prepare('UPDATE shop_orders SET payment_deadline = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 3600), $orderId]);

        $result = (new PaymentService())->expireOverdue();

        assert_same(1, $result['candidates']);
        assert_same(1, $result['expired']);
        assert_same([$orderId], $result['ids']);
        assert_same('stamps', $result['mode']);
        assert_same(null, $result['skipped']);
    },

    /* ---------- 17F: the unilevel month close ---------------------- */

    'P17F-T1 the dry run lists the months it would close and writes nothing' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());

        $closeFixture($pdo, $period);
        $closeBaseline($pdo, $period);

        $logDir = $closeLogDir();

        try {
            $run = $runClose($database, ['--dry-run', '--json'], $logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_not_null($run['json'], 'the dry run answers JSON: ' . $run['output']);
        assert_same(true, $run['json']['dry_run']);
        assert_same(1, $run['json']['would_close']);
        assert_same([$period], $run['json']['periods']);

        $projected = $run['json']['projected'][0];
        assert_same($period, $projected['period']);
        assert_same(2, (int) $projected['members'], 'every account is projected a row');
        assert_same(1, (int) $projected['qualified'], 'the unpaid order never qualifies');
        assert_same(0.0, (float) $projected['award_total']);

        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
            'a dry run writes no header'
        );
        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
            'a dry run writes no snapshot'
        );
    },

    'P17F-T2 a real run closes last month and the counts agree with the aggregate' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());
        $ids    = $closeFixture($pdo, $period);

        $closeBaseline($pdo, $period);

        $logDir = $closeLogDir();

        try {
            $run = $runClose($database, ['--json'], $logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same(1, $run['json']['closed']);
        assert_same([$period], $run['json']['periods']);
        assert_same(2, $run['json']['members']);
        assert_same(1, $run['json']['qualified']);
        assert_same(0.0, (float) $run['json']['award_total']);

        $header = $pdo->query('SELECT * FROM unilevel_periods')->fetch();
        assert_not_null($header, 'the header row exists');
        assert_same($period, $header['period']);
        assert_same(2, (int) $header['members_total']);
        assert_same(1, (int) $header['qualified_count']);
        assert_same(500.0, (float) $header['threshold'], 'the threshold is stored for audit');
        assert_same(0.0, (float) $header['award_total']);

        $mark = $pdo->quote($period);
        assert_same(
            2,
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity WHERE period = ' . $mark)->fetchColumn(),
            'one snapshot row per account'
        );

        $find = static function (int $userId) use ($pdo, $mark): array {
            $stmt = $pdo->prepare(
                'SELECT personal_purchases, qualified FROM unilevel_activity
                  WHERE period = ' . $mark . ' AND user_id = ?'
            );
            $stmt->execute([$userId]);

            return (array) $stmt->fetch();
        };

        $paid = $find($ids['paid']);
        assert_same(600.0, (float) $paid['personal_purchases']);
        assert_same(1, (int) $paid['qualified'], '₱600 clears the ₱500 threshold');

        $unpaid = $find($ids['unpaid']);
        assert_same(0.0, (float) $unpaid['personal_purchases'], 'an unpaid order is never counted');
        assert_same(0, (int) $unpaid['qualified']);
    },

    'P17F-T3 a second run is idempotent: closed=0 and no new row' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());

        $closeFixture($pdo, $period);
        $closeBaseline($pdo, $period);

        $logDir = $closeLogDir();

        try {
            $first = $runClose($database, ['--json'], $logDir);

            $before = [
                (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
                (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
            ];

            $again = $runClose($database, ['--json'], $logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(1, $first['json']['closed']);
        assert_same(0, $again['exit'], $again['output']);
        assert_same(0, $again['json']['closed']);
        assert_same([], $again['json']['periods']);
        assert_same($before, [
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
        ], 'the second run writes nothing');
    },

    'P17F-T4 two missed months catch up in one run, oldest first' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup): void {
        [$pdo, $database] = $closeScratch();

        $last  = UnilevelCycle::previous(UnilevelCycle::periodOf());
        $first = UnilevelCycle::previous($last);

        $closeFixture($pdo, $first, 'old');
        $closeFixture($pdo, $last, 'new');
        $closeBaseline($pdo, $first);

        $logDir = $closeLogDir();

        try {
            $run = $runClose($database, ['--json'], $logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same(2, $run['json']['closed']);
        assert_same([$first, $last], $run['json']['periods'], 'oldest first, in one run');

        $closed = $pdo->query('SELECT period FROM unilevel_periods ORDER BY period')->fetchAll(PDO::FETCH_COLUMN);
        assert_same([$first, $last], $closed);

        foreach ([$first, $last] as $period) {
            assert_same(
                4,
                (int) $pdo->query(
                    'SELECT COUNT(*) FROM unilevel_activity WHERE period = ' . $pdo->quote($period)
                )->fetchColumn(),
                'each snapshot covers every account that exists at close time'
            );
        }

        assert_same(8, (int) $run['json']['members']);
        assert_same(2, (int) $run['json']['qualified'], 'one qualified buyer per month');
    },

    'P17F-T5 a future period is refused and nothing is written' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());

        $closeFixture($pdo, $period);
        $closeBaseline($pdo, $period);

        $future = (new DateTimeImmutable('first day of this month', new DateTimeZone(APP_TIMEZONE)))
            ->modify('+1 month')
            ->format('Y-m');

        $logDir = $closeLogDir();

        try {
            $run = $runClose($database, ['--period=' . $future, '--json'], $logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(1, $run['exit'], 'a refused period exits 1: ' . $run['output']);
        assert_not_null($run['json'], $run['output']);
        assert_same(false, $run['json']['ok']);
        assert_same('not-complete', $run['json']['reason']);
        assert_contains('future', $run['output']);

        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn()
        );
        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn()
        );
    },

    'P17F-T6 --period on a closed month is an exit-0 no-op' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());

        $closeFixture($pdo, $period);
        $closeBaseline($pdo, $period);

        $logDir = $closeLogDir();

        try {
            $runClose($database, [], $logDir);

            $before = [
                (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
                (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
            ];

            $again = $runClose($database, ['--period=' . $period], $logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(0, $again['exit'], $again['output']);
        assert_contains('already closed', $again['output']);
        assert_same($before, [
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
        ], 'a closed period is never rewritten');
    },

    'P17F-T7 a held lock skips with [SKIP ] and exit 0' => function () use ($appRoot, $closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup, $closeLogLines): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());

        $closeFixture($pdo, $period);
        $closeBaseline($pdo, $period);

        $logDir = $closeLogDir();

        /* The subprocess resolves its lock from STORAGE_PATH, so the test
         * holds the real lock the job will look for. */
        $lock = new CronLock('unilevel_month_close', $appRoot . '/storage/locks');
        $lockPath = $lock->path();

        assert_true($lock->acquire(), 'the test holds the job lock');

        try {
            $run = $runClose($database, [], $logDir);
            $logLines = $closeLogLines($logDir);
        } finally {
            $lock->release();
            @unlink($lockPath);
            $closeCleanup($logDir);
        }

        assert_same(0, $run['exit'], 'a busy lock is not an error: ' . $run['output']);
        assert_contains('another run is already in progress', $run['output']);

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn());

        assert_count(1, $logLines);
        assert_contains('[SKIP ]', $logLines[0]);
        assert_contains('skipped: another run holds', $logLines[0]);
    },

    'P17F-T8 the log is one line per run with the counters' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup, $closeLogFile, $closeLogLines, $closeFields): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());

        $closeFixture($pdo, $period);
        $closeBaseline($pdo, $period);

        $logDir = $closeLogDir();

        try {
            $run = $runClose($database, [], $logDir);

            $file  = $closeLogFile($logDir);
            $lines = $closeLogLines($logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_true(str_ends_with($file, 'unilevel_month_close-' . date('Y-m') . '.log'));
        assert_count(1, $lines, 'one run is one line');
        assert_contains('[OK   ]', $lines[0]);

        $fields = $closeFields($lines[0]);

        assert_same('cli', $fields['trigger'] ?? null);
        assert_same('1', $fields['closed'] ?? null);
        assert_same($period, $fields['period'] ?? null);
        assert_same('2', $fields['members'] ?? null);
        assert_same('1', $fields['qualified'] ?? null);
        assert_same('0.00', $fields['award_total'] ?? null);
        assert_true(isset($fields['duration']) && str_ends_with($fields['duration'], 'ms'));
    },

    'P17F-T9 --json carries the numbers of the log line' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup, $closeLogLines, $closeFields): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());

        $closeFixture($pdo, $period);
        $closeBaseline($pdo, $period);

        $logDir = $closeLogDir();

        try {
            $run   = $runClose($database, ['--json'], $logDir);
            $lines = $closeLogLines($logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_count(1, $lines);

        $fields = $closeFields($lines[0]);
        $json   = $run['json'];

        assert_same((string) $json['closed'], $fields['closed'] ?? null);
        assert_same((string) $json['members'], $fields['members'] ?? null);
        assert_same((string) $json['qualified'], $fields['qualified'] ?? null);
        assert_same(number_format((float) $json['award_total'], 2, '.', ''), $fields['award_total'] ?? null);
        assert_same(implode(',', $json['periods']), $fields['period'] ?? null);
        assert_same((string) $json['duration_ms'], rtrim($fields['duration'] ?? '', 'ms'));
    },

    'P17F-T10 --doctor reports both clocks, the threshold and the pending months' => function () use ($closeScratch, $closeFixture, $closeBaseline, $runClose, $closeLogDir, $closeCleanup): void {
        [$pdo, $database] = $closeScratch();
        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());

        $closeFixture($pdo, $period);
        $closeBaseline($pdo, $period);

        $logDir = $closeLogDir();

        try {
            $run = $runClose($database, ['--doctor'], $logDir);
        } finally {
            $closeCleanup($logDir);
        }

        assert_same(0, $run['exit'], $run['output']);

        foreach ([
            'EcoWealth unilevel month close — doctor',
            'php',
            'timezone',
            APP_TIMEZONE,
            'db now',
            'clocks',
            'agree',
            'period now',
            'threshold',
            '₱500.00',
            'ledger since',
            $period . '-01',
            'pending',
            $period,
            'members',
            '2 accounts',
            'lock',
            'log',
            'writable',
        ] as $needle) {
            assert_contains($needle, $run['output']);
        }

        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
            'the doctor changes nothing'
        );
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn());
    },
];
