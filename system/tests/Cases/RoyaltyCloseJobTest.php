<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyCloseJobTest — the Royalty close CLI (plan §14.10)
 *
 *  A subprocess cannot see the harness's rolled-back transaction, so
 *  each case builds a real committed fixture on the scratch schema
 *  (`tool_a`, the same slot the other job tests use) and runs the real
 *  command against it — exactly the way cron will on the 1st.
 *
 *  Covers: the dry run, --evaluate-only, --pay-only, the catch-up over
 *  two missed months (each with its own cutoff), the current/future
 *  refusals, a held lock and the JSON + log contract.
 *
 *  Run this group alone with:  php tests/run.php RoyaltyCloseJob
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

$current = RoyaltyPeriod::periodOf();
$last    = RoyaltyPeriod::previous($current);
$month   = RoyaltyPeriod::previous($last);

/** A scratch schema in the app's real shape, plus the settings seed. */
$scratch = static function () use ($appRoot): array {
    [$pdo, $database] = test_scratch_database('tool_a');

    test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
    test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

    return [$pdo, $database];
};

/** Overwrite the settings a close reads. @param array<string, string> $settings */
$setSettings = static function (PDO $pdo, array $settings): void {
    $stmt = $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?');

    foreach ($settings as $key => $value) {
        $stmt->execute([$value, $key]);
    }
};

/** One member account created at a fixed instant. */
$member = static function (PDO $pdo, string $username, ?int $sponsor, string $createdAt): int {
    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status,
                            sponsor_id, is_active, created_at)
         VALUES (?, ?, 'x', ?, 'member', 'active', ?, 1, ?)"
    )->execute([$username, $username . '@p19c.job', ucfirst($username), $sponsor, $createdAt]);

    return (int) $pdo->lastInsertId();
};

/** One paid order with a real line total, at a fixed instant. */
$order = static function (PDO $pdo, int $productId, int $userId, float $amount, string $paidAt): int {
    $pdo->prepare(
        "INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, total, billing_name,
            shipping_name, shipping_address, paid_at, terms_accepted_at)
         VALUES (?, ?, 'paid', 'ewallet', ?, ?, 'Buyer', 'Buyer', 'Address', ?, ?)"
    )->execute([
        'EWJ-' . strtoupper(bin2hex(random_bytes(5))),
        $userId,
        number_format($amount, 2, '.', ''),
        number_format($amount, 2, '.', ''),
        $paidAt,
        $paidAt,
    ]);

    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, quantity, unit_price, line_total, reservation_state)
         VALUES (?, ?, 'Royalty job product', 1, ?, ?, 'committed')"
    )->execute([
        $orderId,
        $productId,
        number_format($amount, 2, '.', ''),
        number_format($amount, 2, '.', ''),
    ]);

    return $orderId;
};

/**
 * A Supervisor subtree (thresholds 2/2/2/2): two Leaders, each with two
 * directs. Every account is created before the month starts.
 *
 * @return array{top:int, leaders:list<int>, members:list<int>}
 */
$supervisorTree = static function (PDO $pdo, string $tag, string $createdAt) use ($member): array {
    $top     = $member($pdo, 'job_' . $tag . '_sup', null, $createdAt);
    $leaders = [];
    $members = [];

    for ($l = 1; $l <= 2; $l++) {
        $leader    = $member($pdo, 'job_' . $tag . '_leader_' . $l, $top, $createdAt);
        $leaders[] = $leader;

        for ($d = 1; $d <= 2; $d++) {
            $members[] = $member($pdo, 'job_' . $tag . '_direct_' . $l . $d, $leader, $createdAt);
        }
    }

    return ['top' => $top, 'leaders' => $leaders, 'members' => $members];
};

/** One product for the fixture orders. */
$product = static function (PDO $pdo): int {
    $pdo->exec(
        "INSERT INTO products (sku, name, slug, price, pv, stock)
         VALUES ('RTY-JOB', 'Royalty job product', 'royalty-job-product', 1.00, 1.00, 100000)"
    );

    return (int) $pdo->lastInsertId();
};

/** The standard 2/2/2/2 ladder, stream on, paid from `$since`. */
$ladder = static function (PDO $pdo, string $since) use ($setSettings): void {
    $setSettings($pdo, [
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
        'royalty_ledger_since'            => $since,
    ]);
};

/** A throw-away log folder for one case. */
$logDir = static function (): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecowealth-royalty-' . bin2hex(random_bytes(4));
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
            . escapeshellarg($appRoot . '/bin/royalty_month_close.php');

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

/** The month's log lines. @return list<string> */
$logLines = static function (string $dir): array {
    $file = $dir . DIRECTORY_SEPARATOR . 'royalty_month_close-' . date('Y-m') . '.log';

    if (!is_file($file)) {
        return [];
    }

    return array_values(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
};

$header = static function (PDO $pdo, string $period): ?array {
    $stmt = $pdo->prepare('SELECT * FROM royalty_periods WHERE period = ? LIMIT 1');
    $stmt->execute([$period]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
};

$counts = static function (PDO $pdo): array {
    return [
        'periods' => (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn(),
        'awards'  => (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn(),
        'ledger'  => (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn(),
        'audit'   => (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'royalty.%'")->fetchColumn(),
    ];
};

/* --------------------------------------------------------------
 *  The shared fixture: one Supervisor at the quota in the last month
 * -------------------------------------------------------------- */

/**
 * Build the standard fixture and return what a close should pay.
 *
 * @return array{
 *   pdo:PDO, database:string, product:int, tree:array<string, mixed>,
 *   period:string, start:string, end:string, amount:float
 * }
 */
$fixture = static function (string $period) use ($scratch, $ladder, $supervisorTree, $order, $product): array {
    [$pdo, $database] = $scratch();

    [$start, $end] = RoyaltyPeriod::bounds($period);

    $ladder($pdo, $start);

    $productId = $product($pdo);
    $tree      = $supervisorTree($pdo, 'main', substr($start, 0, 10) . ' 09:00:00');

    /* The Supervisor self-funds the quota: 3% + 5% of 500,000 = 40,000. */
    $order($pdo, $productId, $tree['top'], 500000.0, substr($start, 0, 10) . ' 12:00:00');

    return [
        'pdo'      => $pdo,
        'database' => $database,
        'product'  => $productId,
        'tree'     => $tree,
        'period'   => $period,
        'start'    => $start,
        'end'      => $end,
        'amount'   => 40000.0,
    ];
};

return [
    /* ==========================================================
     *  --dry-run (P19C-T38)
     * ========================================================== */

    'P19C-T38 --dry-run prints the preview and writes nothing' => function () use ($fixture, $last, $runJob, $logDir, $cleanup, $counts, $logLines): void {
        $data = $fixture($last);
        $dir  = $logDir();

        try {
            $run = $runJob($data['database'], ['--period=' . $last, '--dry-run', '--json'], $dir);
            $log = $logLines($dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_not_null($run['json'], $run['output']);
        assert_same(true, $run['json']['ok']);
        assert_same(true, $run['json']['dry_run']);
        assert_same([$last], $run['json']['periods']);
        assert_same(1, (int) $run['json']['award']);
        assert_same(1, (int) $run['json']['paid']);
        assert_same(40000.0, (float) $run['json']['amount']);

        $state = $counts($data['pdo']);

        assert_same(0, $state['periods'], 'the dry run writes no header');
        assert_same(0, $state['awards'], 'the dry run writes no award');
        assert_same(0, $state['ledger'], 'the dry run moves no money');
        assert_same(0, $state['audit'], 'the dry run writes no audit row');

        assert_count(1, $log);
        assert_contains('dry-run', $log[0]);
    },

    /* ==========================================================
     *  --evaluate-only (P19C-T39)
     * ========================================================== */

    'P19C-T39 --evaluate-only writes the awards and the header but no wallet' => function () use ($fixture, $last, $runJob, $logDir, $cleanup, $counts, $header): void {
        $data = $fixture($last);
        $dir  = $logDir();

        try {
            $run = $runJob($data['database'], ['--period=' . $last, '--evaluate-only', '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same(false, $run['json']['dry_run']);
        assert_same(true, $run['json']['evaluate_only']);
        assert_same(1, (int) $run['json']['award']);

        $stored = $header($data['pdo'], $last);

        assert_not_null($stored);
        assert_same('evaluated', $stored['status']);
        assert_same(1, (int) $stored['award_count']);
        assert_same(0, (int) $stored['paid_count']);
        assert_same('40000.00', $stored['award_total']);
        assert_same('0.00', $stored['paid_total']);

        $state = $counts($data['pdo']);

        assert_same(1, $state['awards']);
        assert_same(0, $state['ledger'], 'evaluation writes no wallet money');
        assert_same(1, $state['audit'], 'one royalty.period.evaluate row');

        $award = $data['pdo']->query('SELECT * FROM royalty_awards ORDER BY id LIMIT 1')->fetch();
        assert_same('pending', $award['status']);
        assert_null($award['ledger_id']);
    },

    /* ==========================================================
     *  --pay-only (P19C-T40)
     * ========================================================== */

    'P19C-T40 --pay-only pays an evaluated month and refuses an absent or paid one' => function () use ($fixture, $last, $runJob, $logDir, $cleanup, $counts, $header): void {
        $data = $fixture($last);
        $dir  = $logDir();

        try {
            /* Nothing evaluated yet: an explicit refusal, exit 1. */
            $absent = $runJob($data['database'], ['--pay-only', '--json'], $dir);
            assert_same(1, $absent['exit'], $absent['output']);
            assert_same(false, $absent['json']['ok']);
            assert_same('not_found', $absent['json']['reason']);

            $runJob($data['database'], ['--period=' . $last, '--evaluate-only'], $dir);

            $paid = $runJob($data['database'], ['--period=' . $last, '--pay-only', '--json'], $dir);

            assert_same(0, $paid['exit'], $paid['output']);
            assert_same(1, (int) $paid['json']['paid']);
            assert_same(40000.0, (float) $paid['json']['amount']);
            assert_same('paid', $paid['json']['status']);

            assert_same('paid', $header($data['pdo'], $last)['status']);
            assert_same(1, $counts($data['pdo'])['ledger']);

            /* A second --pay-only on a final month is an error, not a
             * silent second payment. */
            $again = $runJob($data['database'], ['--period=' . $last, '--pay-only', '--json'], $dir);

            assert_same(1, $again['exit'], $again['output']);
            assert_same('state', $again['json']['reason']);
            assert_same(1, $counts($data['pdo'])['ledger'], 'no second credit');
        } finally {
            $cleanup($dir);
        }
    },

    /* ==========================================================
     *  Catch-up (P19C-T41)
     * ========================================================== */

    'P19C-T41 a default run closes two missed months oldest first with their own cutoffs' => function () use ($scratch, $ladder, $supervisorTree, $order, $product, $month, $last, $runJob, $logDir, $cleanup, $header, $counts): void {
        [$pdo, $database] = $scratch();

        [$firstStart, $firstEnd]   = RoyaltyPeriod::bounds($month);
        [$secondStart, $secondEnd] = RoyaltyPeriod::bounds($last);

        /* The baseline is the first month, so both months are payable. */
        $ladder($pdo, $firstStart);

        $productId = $product($pdo);
        $tree      = $supervisorTree($pdo, 'catch', substr($firstStart, 0, 10) . ' 09:00:00');

        $order($pdo, $productId, $tree['top'], 500000.0, substr($firstStart, 0, 10) . ' 12:00:00');
        $order($pdo, $productId, $tree['top'], 1000000.0, substr($secondStart, 0, 10) . ' 12:00:00');

        $dir = $logDir();

        try {
            $run = $runJob($database, ['--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same([$month, $last], $run['json']['periods'], 'oldest first');
        assert_same(2, (int) $run['json']['closed']);
        assert_same(2, (int) $run['json']['paid']);
        assert_same(120000.0, (float) $run['json']['amount']);

        $first  = $header($pdo, $month);
        $second = $header($pdo, $last);

        assert_same($firstEnd, $first['as_of'], 'the September cutoff is its own month end');
        assert_same($secondEnd, $second['as_of']);
        assert_same('paid', $first['status']);
        assert_same('paid', $second['status']);
        assert_same('40000.00', $first['paid_total']);
        assert_same('80000.00', $second['paid_total']);
        assert_same(2, $counts($pdo)['ledger']);

        /* A second run has nothing to close. */
        $dir = $logDir();

        try {
            $again = $runJob($database, ['--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $again['exit'], $again['output']);
        assert_same(0, (int) $again['json']['closed']);
        assert_same([], $again['json']['periods']);
        assert_same(2, $counts($pdo)['ledger'], 'a second run pays nothing twice');
    },

    /* ==========================================================
     *  Refusals (P19C-T42, T43)
     * ========================================================== */

    'P19C-T42 the current month is refused with exit 1 and no writes' => function () use ($fixture, $current, $runJob, $logDir, $cleanup, $counts): void {
        $data = $fixture($current);
        $dir  = $logDir();

        try {
            $run = $runJob($data['database'], ['--period=' . $current, '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(1, $run['exit'], $run['output']);
        assert_same(false, $run['json']['ok']);
        assert_same('current', $run['json']['reason']);
        assert_contains('current month', (string) $run['json']['error']);

        assert_same(0, $counts($data['pdo'])['periods']);
        assert_same(0, $counts($data['pdo'])['awards']);
        assert_same(0, $counts($data['pdo'])['ledger']);
    },

    'P19C-T43 a future month is refused with exit 1 and no writes' => function () use ($fixture, $current, $runJob, $logDir, $cleanup, $counts): void {
        $data   = $fixture($current);
        $future = RoyaltyPeriod::next($current);
        $dir    = $logDir();

        try {
            $run = $runJob($data['database'], ['--period=' . $future, '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(1, $run['exit'], $run['output']);
        assert_same('future', $run['json']['reason']);
        assert_same(0, $counts($data['pdo'])['periods']);
        assert_same(0, $counts($data['pdo'])['ledger']);

        /* The invalid shape is refused too. */
        $dir = $logDir();

        try {
            $bad = $runJob($data['database'], ['--period=2026-13', '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(1, $bad['exit']);
        assert_same('invalid', $bad['json']['reason']);
    },

    /* ==========================================================
     *  The lock and the log (P19C-T44, T45)
     * ========================================================== */

    'P19C-T44 a held lock prints SKIP, exits 0 and pays nothing' => function () use ($appRoot, $fixture, $last, $runJob, $logDir, $cleanup, $counts, $logLines): void {
        $data = $fixture($last);
        $dir  = $logDir();
        $lock = new CronLock('royalty_month_close', $appRoot . '/storage/locks');
        $path = $lock->path();

        assert_true($lock->acquire(), 'the test holds the Royalty close lock');

        try {
            $run = $runJob($data['database'], ['--json'], $dir);
            $log = $logLines($dir);
        } finally {
            $lock->release();
            @unlink($path);
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], 'a busy lock is not an error: ' . $run['output']);
        assert_same('locked', $run['json']['skipped']);
        assert_same(0, $counts($data['pdo'])['ledger']);
        assert_same(0, $counts($data['pdo'])['periods']);
        assert_count(1, $log);
        assert_contains('skipped: another run holds', $log[0]);
    },

    'P19C-T45 the log line carries the same numbers as --json' => function () use ($fixture, $last, $runJob, $logDir, $cleanup, $logLines): void {
        $data = $fixture($last);
        $dir  = $logDir();

        try {
            $run    = $runJob($data['database'], ['--json'], $dir);
            $log    = $logLines($dir);
            $logDir = $dir;
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same(1, (int) $run['json']['closed']);
        assert_same('paid', $run['json']['status']);
        assert_same($logDir . DIRECTORY_SEPARATOR . 'royalty_month_close-' . date('Y-m') . '.log', $run['json']['log']);

        assert_count(1, $log);

        preg_match_all('/([a-z_]+)=(\S+)/', $log[0], $matches, PREG_SET_ORDER);

        $fields = [];

        foreach ($matches as $match) {
            $fields[$match[1]] = $match[2];
        }

        assert_same('cli', $fields['trigger'] ?? null);
        assert_same((string) $run['json']['closed'], $fields['closed'] ?? null);
        assert_same(implode(',', $run['json']['periods']), $fields['periods'] ?? null);
        assert_same((string) $run['json']['award'], $fields['award'] ?? null);
        assert_same((string) $run['json']['paid'], $fields['paid'] ?? null);
        assert_same(number_format((float) $run['json']['amount'], 2, '.', ''), $fields['amount'] ?? null);
        assert_same((string) $run['json']['skipped'], $fields['skipped'] ?? null);
        assert_same((string) $run['json']['status'], $fields['status'] ?? null);
        assert_same('0', $fields['paused'] ?? null);
    },
];
