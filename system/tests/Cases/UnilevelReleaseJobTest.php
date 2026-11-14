<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelReleaseJobTest — the release CLI (plan §17, §23.2 T29/T30)
 *
 *  A subprocess cannot see the harness's rolled-back transaction, so
 *  each case builds a real committed fixture on the scratch schema
 *  (`tool_a`, the same slot P17C-T32 uses) and runs the real command
 *  against it — exactly the way cron will.
 *
 *  Covers: the dry run, the doctor, the real run's idempotency and log
 *  line, the kill switch, a held lock, the --order diagnostic and the
 *  JSON payload. The money rules themselves are UnilevelEscrowTest's.
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/** A scratch schema in the app's real shape, plus the settings seed. */
$scratch = static function () use ($appRoot): array {
    [$pdo, $database] = test_scratch_database('tool_a');

    test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
    test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

    $pdo->exec("UPDATE settings SET value = '7' WHERE key_name = 'unilevel_hold_days'");
    $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'unilevel_release_enabled'");

    return [$pdo, $database];
};

/**
 * One delivered, due order with a pending award, plus an undelivered one.
 *
 * @return array{recipient:int, buyer:int, due:int, undelivered:int, award:int, undelivered_award:int}
 */
$fixture = static function (PDO $pdo, string $tag = 'a'): array {
    $users = $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active)
         VALUES (?, ?, 'x', ?, 'member', 'active', 1)"
    );

    $users->execute(['p18_' . $tag . '_recipient', 'p18_' . $tag . '_r@test.local', 'Recipient']);
    $recipientId = (int) $pdo->lastInsertId();

    $users->execute(['p18_' . $tag . '_buyer', 'p18_' . $tag . '_b@test.local', 'Buyer']);
    $buyerId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO products (sku, name, slug, price, pv, unilevel_enabled, stock)
         VALUES ('P18-CLI-{$tag}', 'Release product {$tag}', 'p18-release-{$tag}', 100.00, 100.00, 1, 10)"
    );
    $productId = (int) $pdo->lastInsertId();

    $orders = $pdo->prepare(
        "INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, total, billing_name,
            shipping_name, shipping_address, paid_at, delivered_at, unilevel_processed_at,
            unilevel_total, terms_accepted_at)
         VALUES (?, ?, ?, 'ewallet', 100.00, 100.00, 'Buyer', 'Buyer', 'Address',
                 NOW(), ?, NOW(), 25.00, NOW())"
    );

    $orders->execute(['EWR-' . strtoupper($tag) . '-DUE', $buyerId, 'delivered', date('Y-m-d H:i:s', time() - 86400)]);
    $dueOrderId = (int) $pdo->lastInsertId();

    $orders->execute(['EWR-' . strtoupper($tag) . '-WAIT', $buyerId, 'shipped', null]);
    $waitOrderId = (int) $pdo->lastInsertId();

    $items = $pdo->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, quantity, unit_price, line_total,
            unit_base, base_total, reservation_state)
         VALUES (?, ?, 'Release product', 1, 100.00, 100.00, 100.00, 100.00, 'committed')"
    );

    $items->execute([$dueOrderId, $productId]);
    $dueItemId = (int) $pdo->lastInsertId();

    $items->execute([$waitOrderId, $productId]);
    $waitItemId = (int) $pdo->lastInsertId();

    $awards = $pdo->prepare(
        "INSERT INTO unilevel_awards
           (order_id, order_item_id, product_id, buyer_id, user_id, level,
            base_amount, base_source, rate_pct, amount, hold_days, status, paid_at)
         VALUES (?, ?, ?, ?, ?, 1, 100.00, 'line', 25.000, 25.00, 0, 'pending', NOW())"
    );

    $awards->execute([$dueOrderId, $dueItemId, $productId, $buyerId, $recipientId]);
    $dueAwardId = (int) $pdo->lastInsertId();

    $awards->execute([$waitOrderId, $waitItemId, $productId, $buyerId, $recipientId]);
    $waitAwardId = (int) $pdo->lastInsertId();

    return [
        'recipient'         => $recipientId,
        'buyer'             => $buyerId,
        'due'               => $dueOrderId,
        'undelivered'       => $waitOrderId,
        'award'             => $dueAwardId,
        'undelivered_award' => $waitAwardId,
    ];
};

/** A throw-away log folder for one case. */
$logDir = static function (): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecowealth-release-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);

    return $dir;
};

$cleanup = static function (string $dir): void {
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
$runJob = static function (string $database, array $arguments = [], ?string $dir = null) use ($appRoot): array {
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($appRoot . '/bin/unilevel_release.php');

        if ($dir !== null) {
            $command .= ' --log-dir=' . escapeshellarg($dir);
        }

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

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

$logFile = static fn (string $dir): string => $dir . DIRECTORY_SEPARATOR
    . 'unilevel_release-' . date('Y-m') . '.log';

/** @return list<string> */
$logLines = static function (string $dir) use ($logFile): array {
    $file = $logFile($dir);

    if (!is_file($file)) {
        return [];
    }

    return array_values(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
};

$status = static function (PDO $pdo, int $awardId): string {
    return (string) $pdo->query('SELECT status FROM unilevel_awards WHERE id = ' . $awardId)->fetchColumn();
};

return [
    'P18-T29 --dry-run lists the due award and changes nothing' => function () use ($scratch, $fixture, $runJob, $logDir, $cleanup, $status): void {
        [$pdo, $database] = $scratch();
        $data = $fixture($pdo);

        $dir = $logDir();

        try {
            $run = $runJob($database, ['--dry-run', '--json'], $dir);
            $lines = (int) $pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_not_null($run['json'], $run['output']);
        assert_same(true, $run['json']['ok']);
        assert_same(true, $run['json']['dry_run']);
        assert_same(1, (int) $run['json']['candidates']);
        assert_same([$data['due']], $run['json']['ids']);
        assert_same([$data['award']], $run['json']['awards']);

        assert_same('pending', $status($pdo, $data['award']), 'the dry run changes no status');
        assert_same(0, $lines, 'the dry run writes no ledger row');
        assert_same(0.0, (float) $pdo->query('SELECT ewallet_balance FROM users WHERE id = ' . $data['recipient'])->fetchColumn());
    },

    'P18-T30 --doctor reports the switch, the hold, the pending picture and the lock' => function () use ($scratch, $fixture, $runJob, $logDir, $cleanup): void {
        [$pdo, $database] = $scratch();
        $fixture($pdo, 'doc');

        $dir = $logDir();

        try {
            $run = $runJob($database, ['--doctor'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);

        foreach ([
            'repeat-purchase release — doctor',
            'php',
            'timezone',
            APP_TIMEZONE,
            'db now',
            'release enabled',
            'yes',
            'hold days',
            'pending',
            'oldest pending',
            'due now',
            'undelivered',
            'stuck shipped',
            'cancelled due',
            '0 (good)',
            'lock',
            'log',
            'writable',
        ] as $needle) {
            assert_contains($needle, $run['output']);
        }

        assert_same(
            2,
            (int) $pdo->query("SELECT COUNT(*) FROM unilevel_awards WHERE status = 'pending'")->fetchColumn(),
            'the doctor changes nothing'
        );
    },

    'P18-T31 the real run releases the due award once and logs one line' => function () use ($scratch, $fixture, $runJob, $logDir, $cleanup, $logLines, $logFile, $status): void {
        [$pdo, $database] = $scratch();
        $data = $fixture($pdo, 'run');

        $dir = $logDir();

        try {
            $first = $runJob($database, ['--json'], $dir);
            $second = $runJob($database, ['--json'], $dir);
            $lines = $logLines($dir);
            $file = $logFile($dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $first['exit'], $first['output']);
        assert_same(1, (int) $first['json']['released_orders']);
        assert_same(1, (int) $first['json']['released_awards']);
        assert_same(25.0, (float) $first['json']['amount']);
        assert_same([$data['due']], $first['json']['ids']);

        assert_same(0, $second['exit'], $second['output']);
        assert_same(0, (int) $second['json']['released_orders'], 'a second run releases nothing');

        assert_same('credited', $status($pdo, $data['award']));
        assert_same('pending', $status($pdo, $data['undelivered_award']), 'an undelivered order is never paid');
        assert_same(25.0, (float) $pdo->query(
            'SELECT ewallet_balance FROM users WHERE id = ' . $data['recipient']
        )->fetchColumn(), 'the wallet is credited exactly once');
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'unilevel'")->fetchColumn());
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'unilevel.release'")->fetchColumn());

        assert_count(2, $lines, 'one line per run');
        assert_contains('released_awards=1', $lines[0]);
        assert_true(str_ends_with($file, 'unilevel_release-' . date('Y-m') . '.log'));
    },

    'P18-T32 the release switch off skips with exit 0 and moves no money' => function () use ($scratch, $fixture, $runJob, $logDir, $cleanup, $logLines, $status): void {
        [$pdo, $database] = $scratch();
        $data = $fixture($pdo, 'off');

        $pdo->exec("UPDATE settings SET value = '0' WHERE key_name = 'unilevel_release_enabled'");

        $dir = $logDir();

        try {
            $run = $runJob($database, ['--json'], $dir);
            $lines = $logLines($dir);
        } finally {
            $cleanup($dir);
        }

        /* Off is an intentional pause, not a failure: the job exits 0 so
         * a monitoring system does not page for an operator decision. */
        assert_same(0, $run['exit'], $run['output']);
        assert_same(false, $run['json']['ok']);
        assert_same('disabled', $run['json']['skipped']);
        assert_same(2, (int) $run['json']['pending'], 'the pending awards are still reported');

        assert_same('pending', $status($pdo, $data['award']));
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());
        assert_count(1, $lines);
        assert_contains('skipped: disabled by settings', $lines[0]);
    },

    'P18-T33 a held lock skips without releasing' => function () use ($appRoot, $scratch, $fixture, $runJob, $logDir, $cleanup, $logLines, $status): void {
        [$pdo, $database] = $scratch();
        $data = $fixture($pdo, 'lock');

        $dir   = $logDir();
        $lock  = new CronLock('unilevel_release', $appRoot . '/storage/locks');
        $path  = $lock->path();

        assert_true($lock->acquire(), 'the test holds the job lock');

        try {
            $run   = $runJob($database, ['--json'], $dir);
            $lines = $logLines($dir);
        } finally {
            $lock->release();
            @unlink($path);
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], 'a busy lock is not an error: ' . $run['output']);
        assert_same('locked', $run['json']['skipped']);
        assert_same('pending', $status($pdo, $data['award']));
        assert_count(1, $lines);
        assert_contains('skipped: another run holds', $lines[0]);
    },

    'P18-T34 --order releases exactly that order' => function () use ($scratch, $fixture, $runJob, $logDir, $cleanup, $status): void {
        [$pdo, $database] = $scratch();
        $data = $fixture($pdo, 'one');

        /* A second due order, so "one order only" is provable. */
        $pdo->exec("UPDATE shop_orders SET status = 'delivered', delivered_at = NOW() WHERE id = " . $data['undelivered']);
        $pdo->exec("UPDATE unilevel_awards SET hold_days = 0 WHERE id = " . $data['undelivered_award']);

        $dir = $logDir();

        try {
            $run = $runJob($database, ['--order=' . $data['due'], '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same($data['due'], (int) $run['json']['order']);
        assert_same(1, (int) $run['json']['released_awards']);
        assert_same('credited', $status($pdo, $data['award']));
        assert_same('pending', $status($pdo, $data['undelivered_award']), 'the other due order is untouched');
    },

    'P18-T35 --order on an undelivered order reports the refusal' => function () use ($scratch, $fixture, $runJob, $logDir, $cleanup, $status): void {
        [$pdo, $database] = $scratch();
        $data = $fixture($pdo, 'wait');

        $dir = $logDir();

        try {
            $run = $runJob($database, ['--order=' . $data['undelivered'], '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same(0, (int) $run['json']['released_awards']);
        assert_same('not delivered', $run['json']['reason']);
        assert_same('pending', $status($pdo, $data['undelivered_award']));
    },

    'P18-T36 the log line carries the same numbers as --json' => function () use ($scratch, $fixture, $runJob, $logDir, $cleanup, $logLines): void {
        [$pdo, $database] = $scratch();
        $fixture($pdo, 'log');

        $dir = $logDir();

        try {
            $run   = $runJob($database, ['--json'], $dir);
            $lines = $logLines($dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_count(1, $lines);

        preg_match_all('/([a-z_]+)=(\S+)/', $lines[0], $matches, PREG_SET_ORDER);
        $fields = [];

        foreach ($matches as $match) {
            $fields[$match[1]] = $match[2];
        }

        assert_same('cli', $fields['trigger'] ?? null);
        assert_same((string) $run['json']['candidates'], $fields['candidates'] ?? null);
        assert_same((string) $run['json']['released_orders'], $fields['released_orders'] ?? null);
        assert_same((string) $run['json']['released_awards'], $fields['released_awards'] ?? null);
        assert_same(
            number_format((float) $run['json']['amount'], 2, '.', ''),
            $fields['amount'] ?? null
        );
        assert_same(implode(',', $run['json']['ids']), $fields['ids'] ?? null);
        assert_same((string) $run['json']['duration_ms'], rtrim($fields['duration'] ?? '', 'ms'));
    },
];
