<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyCronTest — the Royalty close's operations surface
 *  (plan §9 Phase 19F, §14.10, §16.5)
 *
 *  Proves the phase's sixteen cases end to end:
 *
 *      T1–T3   the web entry: 403 for a remote caller without the key,
 *              200 on loopback with the CLI's numbers, and CRON_KEY
 *      T4      the Windows launcher runs the same job and flags
 *      T5–T7   the doctor: clean exit 0, a missing table exits 1, and a
 *              clock skew over 2 s exits 1
 *      T8      --dry-run writes nothing
 *      T9      a catch-up closes two months oldest first, one log line
 *              per run
 *      T10     a held lock prints [SKIP ] and exits 0
 *      T11     --max-batches leaves the month evaluated and resumes
 *      T12     the log line carries the periods, counts, totals and the
 *              paused flag
 *      T13     the operations panel renders the open month, last final,
 *              pending, evaluated-unpaid and the switches
 *      T14     the health check sees the tables/settings and warns (but
 *              does not fail) on a stale evaluated month
 *      T15     the 0/1/2 exit codes
 *      T16     docs/cron.md documents the job
 *
 *  A subprocess cannot see the harness's rolled-back transaction, so the
 *  job cases build a real committed fixture on the scratch schema (the
 *  P19C convention). The web cases run the *real* URL entry from a tiny
 *  wrapper script that sets the request superglobals — the same file the
 *  web server executes, with the same access rules. The doctor's clock
 *  check is proved in-process with an injected "now", because a test
 *  must never change the host clock.
 *
 *  Run this group alone with:  php tests/run.php RoyaltyCron
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

$current = RoyaltyPeriod::periodOf();
$last    = RoyaltyPeriod::previous($current);
$prior   = RoyaltyPeriod::previous($last);
$oldest  = RoyaltyPeriod::previous($prior);

/* Load the one implementation as a library: the HTTP gate, the injected
 * clock and the dispatcher are library functions, and loading them must
 * never start a run (that is what ROYALTY_CLOSE_LIBRARY is for). */
if (!defined('ROYALTY_CLOSE_LIBRARY')) {
    define('ROYALTY_CLOSE_LIBRARY', true);
    require_once $appRoot . '/bin/royalty_month_close.php';
}

/* --------------------------------------------------------------
 *  Fixtures
 * -------------------------------------------------------------- */

/** A scratch schema in the app's real shape, plus the settings seed. */
$scratch = static function () use ($appRoot): array {
    [$pdo, $database] = test_scratch_database('tool_a');

    test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
    test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

    return [$pdo, $database];
};

/** Overwrite settings. @param array<string, string> $settings */
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
    )->execute([$username, $username . '@p19f.job', ucfirst($username), $sponsor, $createdAt]);

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
        'EWF-' . strtoupper(bin2hex(random_bytes(5))),
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
         VALUES (?, ?, 'Royalty cron product', 1, ?, ?, 'committed')"
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
 * directs. Every account exists before the month starts.
 *
 * @return array{top:int, leaders:list<int>, members:list<int>}
 */
$supervisorTree = static function (PDO $pdo, string $tag, string $createdAt) use ($member): array {
    $top     = $member($pdo, 'cron_' . $tag . '_sup', null, $createdAt);
    $leaders = [];
    $members = [];

    for ($l = 1; $l <= 2; $l++) {
        $leader    = $member($pdo, 'cron_' . $tag . '_leader_' . $l, $top, $createdAt);
        $leaders[] = $leader;

        for ($d = 1; $d <= 2; $d++) {
            $members[] = $member($pdo, 'cron_' . $tag . '_direct_' . $l . $d, $leader, $createdAt);
        }
    }

    return ['top' => $top, 'leaders' => $leaders, 'members' => $members];
};

/** One product for the fixture orders. */
$product = static function (PDO $pdo): int {
    $pdo->exec(
        "INSERT INTO products (sku, name, slug, price, pv, stock)
         VALUES ('RTY-CRON', 'Royalty cron product', 'royalty-cron-product', 1.00, 1.00, 100000)"
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

/**
 * The standard fixture: one Supervisor at the quota in `$month`
 * (₱500,000 self-funded → 3% + 5% = ₱40,000).
 *
 * @return array{pdo:PDO, database:string, top:int, period:string, amount:float}
 */
$fixture = static function (string $month, float $amount = 500000.0) use ($scratch, $ladder, $supervisorTree, $order, $product): array {
    [$pdo, $database] = $scratch();

    [$start] = RoyaltyPeriod::bounds($month);

    $ladder($pdo, $start);

    $productId = $product($pdo);
    $tree      = $supervisorTree($pdo, 'main', substr($start, 0, 10) . ' 09:00:00');

    $order($pdo, $productId, $tree['top'], $amount, substr($start, 0, 10) . ' 12:00:00');

    return [
        'pdo'      => $pdo,
        'database' => $database,
        'top'      => $tree['top'],
        'period'   => $month,
        'amount'   => 40000.0,
    ];
};

/** A throw-away log folder for one case. */
$logDir = static function (): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecowealth-royalty-cron-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);

    return $dir;
};

$cleanup = static function (string $dir): void {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    @rmdir($dir);
};

/** The month's log lines under a `--log-dir`. @return list<string> */
$logLines = static function (string $dir): array {
    $file = $dir . DIRECTORY_SEPARATOR . 'royalty_month_close-' . date('Y-m') . '.log';

    if (!is_file($file)) {
        return [];
    }

    return array_values(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
};

/** Parse one `key=value key=value` cron log line. */
$logFields = static function (string $line): array {
    preg_match_all('/([a-z_]+)=(\S+)/', $line, $matches, PREG_SET_ORDER);

    $fields = [];

    foreach ($matches as $match) {
        $fields[$match[1]] = $match[2];
    }

    return $fields;
};

/** The fixture's row counts. @return array<string, int> */
$counts = static function (PDO $pdo): array {
    return [
        'periods' => (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn(),
        'awards'  => (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn(),
        'ledger'  => (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn(),
        'audit'   => (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'royalty.%'")->fetchColumn(),
    ];
};

/** One period header. */
$header = static function (PDO $pdo, string $period): ?array {
    $stmt = $pdo->prepare('SELECT * FROM royalty_periods WHERE period = ? LIMIT 1');
    $stmt->execute([$period]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
};

/* --------------------------------------------------------------
 *  Running the job
 * -------------------------------------------------------------- */

/**
 * Run the real CLI against a scratch schema.
 *
 * @param list<string> $arguments
 * @return array{exit:int, output:string, json:?array}
 */
$runCli = static function (string $database, array $arguments = [], ?string $dir = null) use ($appRoot): array {
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

/**
 * Run the real URL entry the way the web server would: a tiny wrapper
 * sets the request superglobals and requires
 * `public/cron/royalty_month_close.php`. The body is what the browser
 * would receive; the JSON payload carries the HTTP status the dispatcher
 * answered with.
 *
 * @param array<string, string> $query
 * @param array{remote:string, key:?string, cron_key:?string}|null $request
 * @return array{exit:int, output:string, json:?array}
 */
$runWeb = static function (string $database, array $query, ?array $request = null) use ($appRoot): array {
    $request ??= ['remote' => '127.0.0.1', 'key' => null, 'cron_key' => null];

    if (($request['key'] ?? null) !== null) {
        $query['key'] = (string) $request['key'];
    }

    $root    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecowealth-royalty-web-' . bin2hex(random_bytes(4));
    $storage = $root . DIRECTORY_SEPARATOR . 'storage';

    @mkdir($storage . DIRECTORY_SEPARATOR . 'logs', 0775, true);
    @mkdir($storage . DIRECTORY_SEPARATOR . 'locks', 0775, true);

    $script = $root . DIRECTORY_SEPARATOR . 'entry.php';

    $source = "<?php\ndeclare(strict_types=1);\n"
        . "define('STORAGE_PATH', " . var_export($storage, true) . ");\n"
        . (($request['cron_key'] ?? null) !== null
            ? "define('CRON_KEY', " . var_export((string) $request['cron_key'], true) . ");\n"
            : '')
        . "\$_SERVER['REMOTE_ADDR'] = " . var_export((string) $request['remote'], true) . ";\n"
        . "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "\$_GET = " . var_export($query, true) . ";\n"
        . 'require ' . var_export($appRoot . '/public/cron/royalty_month_close.php', true) . ";\n";

    file_put_contents($script, $source);

    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
    } finally {
        if ($previous === false) {
            putenv('ECO_DB_NAME');
        } else {
            putenv('ECO_DB_NAME=' . $previous);
        }

        foreach (glob($storage . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($script);
        @rmdir($storage . DIRECTORY_SEPARATOR . 'logs');
        @rmdir($storage . DIRECTORY_SEPARATOR . 'locks');
        @rmdir($storage);
        @rmdir($root);
    }

    $output = implode("\n", $lines);
    $json   = json_decode($output, true);

    return ['exit' => $code, 'output' => $output, 'json' => is_array($json) ? $json : null];
};

/**
 * The Windows launcher, when this suite runs on Windows.
 */
$runCmd = static function (string $database, array $arguments = [], ?string $dir = null) use ($appRoot): array {
    $previousDb  = getenv('ECO_DB_NAME');
    $previousExe = getenv('PHP_EXE');

    putenv('ECO_DB_NAME=' . $database);
    putenv('PHP_EXE=' . PHP_BINARY);

    try {
        $command = escapeshellarg($appRoot . '/bin/royalty_month_close.cmd');

        if ($dir !== null) {
            $command .= ' --log-dir=' . escapeshellarg($dir);
        }

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $lines, $code);
    } finally {
        putenv($previousDb === false ? 'ECO_DB_NAME' : 'ECO_DB_NAME=' . $previousDb);
        putenv($previousExe === false ? 'PHP_EXE' : 'PHP_EXE=' . $previousExe);
    }

    $output = implode("\n", $lines);
    $json   = json_decode($output, true);

    return ['exit' => $code, 'output' => $output, 'json' => is_array($json) ? $json : null];
};

/** Render a page through the admin layout. */
$renderPage = static function (string $template, array $data, string $title, string $nav): string {
    ob_start();

    try {
        View::page('admin', $template, $data + [
            'page_title'  => $title,
            'current_nav' => $nav,
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
            'live'        => Live::meta('admin.commissions', 'p19f'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/** Apply the fixture ladder to the harness connection (in-process cases). */
$configure = static function () use ($oldest): void {
    foreach ([
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
        'royalty_ledger_since'            => $oldest . '-01 00:00:00',
    ] as $key => $value) {
        setting_set($key, (string) $value);
    }

    setting_cache_clear();
};

return [
    /* ==========================================================
     *  T1 — the web entry refuses a remote caller without a key
     * ========================================================== */

    'P19F-T1 the web entry answers 403 to a remote caller without the key and writes nothing' => function () use ($fixture, $last, $runWeb, $counts): void {
        $data = $fixture($last);

        $run = $runWeb($data['database'], ['json' => '1'], ['remote' => '203.0.113.9', 'key' => null, 'cron_key' => null]);

        assert_same(1, $run['exit'], 'a refused request exits 1: ' . $run['output']);
        assert_not_null($run['json'], 'the refusal answers JSON: ' . $run['output']);
        assert_same(false, $run['json']['ok']);
        assert_same('Forbidden', $run['json']['error']);
        assert_same(403, (int) $run['json']['http_status'], 'the body maps to HTTP 403');
        assert_same('http', $run['json']['trigger']);

        $state = $counts($data['pdo']);

        assert_same(0, $state['periods'], 'a refused request writes no period');
        assert_same(0, $state['awards'], 'a refused request writes no award');
        assert_same(0, $state['ledger'], 'a refused request moves no money');
        assert_same(0, $state['audit'], 'a refused request writes no audit row');
    },

    /* ==========================================================
     *  T2 — loopback: 200 with the CLI's numbers
     * ========================================================== */

    'P19F-T2 the loopback web entry answers 200 with the same numbers as the CLI' => function () use ($fixture, $last, $runWeb, $runCli, $logDir, $cleanup): void {
        $data = $fixture($last);
        $dir  = $logDir();

        try {
            $web = $runWeb($data['database'], [
                'period'  => $last,
                'dry-run' => '1',
                'json'    => '1',
            ]);

            $cli = $runCli($data['database'], ['--period=' . $last, '--dry-run', '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $web['exit'], $web['output']);
        assert_not_null($web['json'], 'the loopback entry answers JSON: ' . $web['output']);
        assert_same(200, (int) $web['json']['http_status']);
        assert_same(0, (int) $web['json']['exit_code']);
        assert_same('http', $web['json']['trigger'], 'the payload names the way in');
        assert_same(true, $web['json']['dry_run']);

        assert_same(0, $cli['exit'], $cli['output']);

        foreach (['periods', 'closed', 'award', 'paid', 'amount', 'skipped', 'status'] as $key) {
            assert_same(
                $cli['json'][$key],
                $web['json'][$key],
                'the web entry and the CLI agree on "' . $key . '"'
            );
        }

        assert_same([$last], $web['json']['periods']);
        assert_same(1, (int) $web['json']['award']);
        assert_same(40000.0, (float) $web['json']['amount']);
    },

    /* ==========================================================
     *  T3 — CRON_KEY: the right key is allowed, a wrong one is not
     * ========================================================== */

    'P19F-T3 CRON_KEY lets a remote caller in and a wrong key is refused' => function () use ($fixture, $last, $runWeb, $counts): void {
        $data = $fixture($last);

        /* The decision itself, pure. */
        assert_same(403, royalty_close_http_gate('203.0.113.9', null, null)['status']);
        assert_same(403, royalty_close_http_gate('203.0.113.9', 'wrong', 'secret')['status']);
        assert_same(403, royalty_close_http_gate('203.0.113.9', 'secret', null)['status']);
        assert_same(200, royalty_close_http_gate('203.0.113.9', 'secret', 'secret')['status']);
        assert_same(200, royalty_close_http_gate('127.0.0.1', null, null)['status']);

        /* A wrong key over the real entry: refused, nothing written. */
        $wrong = $runWeb(
            $data['database'],
            ['doctor' => '1', 'json' => '1'],
            ['remote' => '203.0.113.9', 'key' => 'wrong', 'cron_key' => 'secret']
        );

        assert_same(1, $wrong['exit'], $wrong['output']);
        assert_same(403, (int) $wrong['json']['http_status']);
        assert_same('Forbidden', $wrong['json']['error']);

        /* The right key runs the doctor and answers 200. */
        $right = $runWeb(
            $data['database'],
            ['doctor' => '1', 'json' => '1'],
            ['remote' => '203.0.113.9', 'key' => 'secret', 'cron_key' => 'secret']
        );

        assert_same(0, $right['exit'], $right['output']);
        assert_same(200, (int) $right['json']['http_status']);
        assert_same(true, $right['json']['ok']);
        assert_same('http', $right['json']['trigger']);
        assert_same(0, $counts($data['pdo'])['periods'], 'a doctor writes no period');
    },

    /* ==========================================================
     *  T4 — the Windows launcher
     * ========================================================== */

    'P19F-T4 the .cmd launcher runs the CLI with the same flags and result' => function () use ($appRoot, $fixture, $last, $runCli, $runCmd, $logDir, $cleanup): void {
        $cmdPath = $appRoot . '/bin/royalty_month_close.cmd';

        assert_true(is_file($cmdPath), 'the Windows launcher exists');

        $source = (string) file_get_contents($cmdPath);

        assert_contains('royalty_month_close.php', $source);
        assert_contains('%*', $source, 'every flag is handed through');
        assert_contains('PHP_EXE', $source, 'the PHP path comes from the environment');

        if (PHP_OS_FAMILY !== 'Windows') {
            return;   // the launcher is a Windows artifact; the bytes above are the proof elsewhere
        }

        $data = $fixture($last);
        $dir  = $logDir();

        try {
            $cmd = $runCmd($data['database'], ['--period=' . $last, '--dry-run', '--json'], $dir);
            $cli = $runCli($data['database'], ['--period=' . $last, '--dry-run', '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $cmd['exit'], $cmd['output']);
        assert_not_null($cmd['json'], 'the launcher answers the CLI JSON: ' . $cmd['output']);
        assert_same($cli['json']['periods'], $cmd['json']['periods']);
        assert_same($cli['json']['award'], $cmd['json']['award']);
        assert_same($cli['json']['amount'], $cmd['json']['amount']);
        assert_same('cli', $cmd['json']['trigger']);
    },

    /* ==========================================================
     *  T5–T7 — the doctor
     * ========================================================== */

    'P19F-T5 the doctor is green on a clean install and exits 0' => function () use ($scratch, $runCli): void {
        [$pdo, $database] = $scratch();

        $run = $runCli($database, ['--doctor']);

        assert_same(0, $run['exit'], $run['output']);

        foreach ([
            'EcoWealth Royalty month close — doctor',
            'php',
            'mysql',
            'clocks',
            'agree',
            'Asia/Manila',
            'tables',
            'royalty_periods, royalty_awards present',
            'settings',
            '16/16 present; ladder valid',
            'ledger since',
            'first payable',
            'enabled',
            'royalty_enabled=0 · royalty_pay_enabled=1',
            'last paid',
            'none yet',
            'pending',
            'evaluated',
            'paid awards',
            'lock',
            'free',
            'wallet',
            'Royalty reconcile clean',
            'cron key',
            'not set (loopback only)',
            'The doctor is green.',
        ] as $needle) {
            assert_contains($needle, $run['output']);
        }

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn(),
            'the doctor writes no period');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn(),
            'the doctor writes no award');
    },

    'P19F-T6 the doctor fails with the missing table named' => function () use ($scratch, $runCli): void {
        [$pdo, $database] = $scratch();

        $pdo->exec('DROP TABLE royalty_awards');

        $run = $runCli($database, ['--doctor']);

        assert_same(1, $run['exit'], 'a missing table is a failure, not an environment exit: ' . $run['output']);
        assert_contains('MISSING: royalty_awards', $run['output']);
        assert_contains('royalty_awards', $run['output']);
        assert_contains('The doctor is NOT green', $run['output']);
        assert_contains('migration 023_royalty_bonus.sql', $run['output']);
    },

    'P19F-T7 a clock skew over two seconds fails the doctor' => function () use ($appRoot): void {
        /* The comparison itself, exact at the boundary. */
        assert_true(royalty_close_clock_state(1000, 1000)['ok']);
        assert_true(royalty_close_clock_state(1000, 1002)['ok'], '2 s still agrees');
        assert_true(royalty_close_clock_state(1000, 998)['ok'], '-2 s still agrees');
        assert_false(royalty_close_clock_state(1000, 1003)['ok'], '3 s is a skew');
        assert_false(royalty_close_clock_state(1000, 997)['ok']);
        assert_contains('apart', royalty_close_clock_state(1000, 1005)['text']);
        assert_contains('+2s', royalty_close_clock_state(1000, 1002)['text']);

        /* The doctor with an injected PHP clock (5 s behind MySQL's). */
        $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecowealth-royalty-clock-' . bin2hex(random_bytes(4));
        @mkdir($storage . DIRECTORY_SEPARATOR . 'logs', 0775, true);
        @mkdir($storage . DIRECTORY_SEPARATOR . 'locks', 0775, true);

        try {
            $lock = new CronLock('royalty_close_doctor_test', $storage . DIRECTORY_SEPARATOR . 'locks');
            $log  = new CronLog($storage . DIRECTORY_SEPARATOR . 'logs', 'royalty_month_close_doctor_test');

            $skewed = royalty_close_doctor($lock, $log, time() + 5);

            assert_false($skewed['ok'], 'a 5 s skew is not green');
            assert_same('fail', $skewed['status']);

            $clocks = null;

            foreach ($skewed['checks'] as $check) {
                if ($check['key'] === 'clocks') {
                    $clocks = $check;
                }
            }

            assert_not_null($clocks);
            assert_same('fail', $clocks['state']);
            assert_contains('apart', $clocks['value']);

            $agreeing = royalty_close_doctor($lock, $log, time());

            assert_true($agreeing['ok'], 'the same doctor with the real clock is green');

            $text = implode("\n", royalty_close_format_doctor($skewed));
            assert_contains('clocks', $text);
            assert_contains('apart', $text);
            assert_contains('The doctor is NOT green', $text);
        } finally {
            foreach (glob($storage . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }

            foreach (glob($storage . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($storage . DIRECTORY_SEPARATOR . 'logs');
            @rmdir($storage . DIRECTORY_SEPARATOR . 'locks');
            @rmdir($storage);
        }
    },

    /* ==========================================================
     *  T8 — --dry-run writes nothing
     * ========================================================== */

    'P19F-T8 a dry run writes no period, award, wallet or audit row' => function () use ($fixture, $last, $runCli, $logDir, $cleanup, $counts): void {
        $data = $fixture($last);
        $dir  = $logDir();

        try {
            $run = $runCli($data['database'], ['--period=' . $last, '--dry-run', '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same(true, $run['json']['dry_run']);
        assert_same([$last], $run['json']['periods']);
        assert_same(1, (int) $run['json']['award']);
        assert_same(40000.0, (float) $run['json']['amount']);

        assert_same(
            ['periods' => 0, 'awards' => 0, 'ledger' => 0, 'audit' => 0],
            $counts($data['pdo']),
            'a dry run writes nothing at all'
        );

        /* The operations block rides the JSON payload (19F). */
        assert_true(isset($run['json']['operations']), 'the dry-run JSON carries the operations picture');
        assert_same($last, $run['json']['operations']['pending'][0] ?? null);
    },

    /* ==========================================================
     *  T9 — the catch-up
     * ========================================================== */

    'P19F-T9 a catch-up closes two months oldest first with one log line per run' => function () use ($scratch, $ladder, $supervisorTree, $order, $product, $prior, $last, $runCli, $logDir, $cleanup, $logLines, $header): void {
        [$pdo, $database] = $scratch();

        [$firstStart]  = RoyaltyPeriod::bounds($prior);
        [$secondStart] = RoyaltyPeriod::bounds($last);

        $ladder($pdo, $firstStart);

        $productId = $product($pdo);
        $tree      = $supervisorTree($pdo, 'catch', substr($firstStart, 0, 10) . ' 09:00:00');

        $order($pdo, $productId, $tree['top'], 500000.0, substr($firstStart, 0, 10) . ' 12:00:00');
        $order($pdo, $productId, $tree['top'], 1000000.0, substr($secondStart, 0, 10) . ' 12:00:00');

        $dir = $logDir();

        try {
            $run = $runCli($database, ['--json'], $dir);
            $one = $logLines($dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_same([$prior, $last], $run['json']['periods'], 'oldest first');
        assert_same(2, (int) $run['json']['closed']);
        assert_same(2, (int) $run['json']['paid']);
        assert_same(120000.0, (float) $run['json']['amount']);
        assert_count(1, $one, 'one catch-up run writes one log line');
        assert_contains('periods=' . $prior . ',' . $last, $one[0]);

        assert_same('paid', $header($pdo, $prior)['status']);
        assert_same('paid', $header($pdo, $last)['status']);

        /* The second run is an exit-0 no-op with its own single line. */
        $dir = $logDir();

        try {
            $again = $runCli($database, ['--json'], $dir);
            $two   = $logLines($dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $again['exit'], $again['output']);
        assert_same(0, (int) $again['json']['closed']);
        assert_same([], $again['json']['periods']);
        assert_count(1, $two, 'the second run writes one line of its own');
        assert_contains('closed=0', $two[0]);
    },

    /* ==========================================================
     *  T10 — the lock
     * ========================================================== */

    'P19F-T10 a held lock prints [SKIP ] and exits 0' => function () use ($appRoot, $fixture, $last, $runCli, $logDir, $cleanup, $counts, $logLines): void {
        $data = $fixture($last);
        $dir  = $logDir();
        $lock = new CronLock('royalty_month_close', $appRoot . '/storage/locks');
        $path = $lock->path();

        assert_true($lock->acquire(), 'the test holds the Royalty close lock');

        try {
            $run = $runCli($data['database'], ['--json'], $dir);
            $log = $logLines($dir);
        } finally {
            $lock->release();
            @unlink($path);
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], 'a busy lock is never an error: ' . $run['output']);
        assert_same('locked', $run['json']['skipped']);

        $state = $counts($data['pdo']);
        assert_same(0, $state['periods']);
        assert_same(0, $state['ledger']);

        assert_count(1, $log);
        assert_contains('[SKIP ]', $log[0]);
        assert_contains('skipped: another run holds', $log[0]);
    },

    /* ==========================================================
     *  T11 — --max-batches bounds one run and the next resumes
     * ========================================================== */

    'P19F-T11 --max-batches leaves a large month evaluated and the next run resumes' => function () use ($scratch, $ladder, $supervisorTree, $order, $product, $last, $runCli, $logDir, $cleanup, $header, $counts): void {
        [$pdo, $database] = $scratch();

        [$start] = RoyaltyPeriod::bounds($last);

        $ladder($pdo, $start);

        $productId = $product($pdo);

        /* Two Supervisors at the quota: two awards, so one batch is not
         * the whole month. */
        foreach (['a', 'b'] as $tag) {
            $tree = $supervisorTree($pdo, 'batch_' . $tag, substr($start, 0, 10) . ' 09:00:00');
            $order($pdo, $productId, $tree['top'], 500000.0, substr($start, 0, 10) . ' 12:00:00');
        }

        $dir = $logDir();

        try {
            $first = $runCli($database, [
                '--period=' . $last, '--limit=1', '--max-batches=1', '--json',
            ], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $first['exit'], $first['output']);
        assert_same(1, (int) $first['json']['paid']);
        assert_same(true, $first['json']['resume_required']);
        assert_same('evaluated', $first['json']['status']);
        assert_same('evaluated', $header($pdo, $last)['status'], 'the month is left evaluated');
        assert_same(1, $counts($pdo)['ledger']);

        $dir = $logDir();

        try {
            $second = $runCli($database, ['--period=' . $last, '--pay-only', '--json'], $dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $second['exit'], $second['output']);
        assert_same(1, (int) $second['json']['paid']);
        assert_same(false, $second['json']['resume_required']);
        assert_same('paid', $header($pdo, $last)['status']);
        assert_same(2, $counts($pdo)['ledger'], 'both awards are paid once');
    },

    /* ==========================================================
     *  T12 — the log line
     * ========================================================== */

    'P19F-T12 the log line carries the periods, counts, totals and the paused flag' => function () use ($fixture, $last, $runCli, $logDir, $cleanup, $logLines, $logFields): void {
        $data = $fixture($last);

        /* Payments paused: the run evaluates but pays nothing, so the
         * line's paused flag and its zeros are the interesting part. */
        $data['pdo']->prepare("UPDATE settings SET value = '0' WHERE key_name = 'royalty_pay_enabled'")
            ->execute();

        $dir = $logDir();

        try {
            $run = $runCli($data['database'], ['--json'], $dir);
            $log = $logLines($dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_count(1, $log);

        $fields = $logFields($log[0]);

        assert_same('cli', $fields['trigger'] ?? null);
        assert_same('0', $fields['closed'] ?? null);
        assert_same($last, $fields['periods'] ?? null);
        assert_same('1', $fields['award'] ?? null);
        assert_same('0', $fields['paid'] ?? null);
        assert_same('0.00', $fields['amount'] ?? null);
        assert_same('0', $fields['skipped'] ?? null);
        assert_same('evaluated', $fields['status'] ?? null);
        assert_same('1', $fields['paused'] ?? null);

        /* The log and the JSON payload are the same numbers. */
        assert_same((string) $run['json']['award'], $fields['award'] ?? null);
        assert_same((string) $run['json']['paid'], $fields['paid'] ?? null);
        assert_same(number_format((float) $run['json']['amount'], 2, '.', ''), $fields['amount'] ?? null);
    },

    /* ==========================================================
     *  P19G-T12 — `closed` counts every final month, even with no money
     *
     *  An enabled month whose every award row is skipped finalises
     *  `paid` at evaluation time. `--evaluate-only` must count it as
     *  closed, because the log's own contract says "closed counts
     *  the months that reached a final status".
     * ========================================================== */

    'P19G-T12 --evaluate-only counts an all-skipped enabled month as closed' => function () use (
        $fixture,
        $last,
        $runCli,
        $logDir,
        $cleanup,
        $logLines,
        $logFields
    ): void {
        /* ₱100,000 of group sales is below the ₱500,000 quota: the one
         * award row is skipped and the month finalises at evaluation. */
        $data = $fixture($last, 100000.0);

        $dir = $logDir();

        try {
            $run = $runCli($data['database'], ['--period=' . $last, '--evaluate-only', '--json'], $dir);
            $log = $logLines($dir);
        } finally {
            $cleanup($dir);
        }

        assert_same(0, $run['exit'], $run['output']);
        assert_count(1, $log);

        $fields = $logFields($log[0]);

        assert_same('1', $fields['closed'] ?? null, 'a month that finalised without money is closed');
        assert_same('1', $fields['award'] ?? null);
        assert_same('0', $fields['paid'] ?? null);
        assert_same('1', $fields['skipped'] ?? null);
        assert_same('paid', $fields['status'] ?? null);

        $header = RoyaltyPeriod::find($last, $data['pdo']);

        assert_same('paid', (string) ($header['status'] ?? ''), 'all-skipped rows still finalise the month');
        assert_same('0.00', (string) ($header['paid_total'] ?? ''), 'nothing was paid');
    },

    /* ==========================================================
     *  T13 — the operations panel
     * ========================================================== */

    'P19F-T13 the operations panel shows the open month, last final, pending, evaluated and the switches' => function () use ($configure, $member, $order, $product, $renderPage, $oldest, $prior, $last, $current): void {
        $configure();

        /* A Supervisor who is at quota in the two completed months. */
        $user = test_user('p19f_ops_sup');
        $top  = (int) $user['id'];

        db()->prepare('UPDATE users SET created_at = ? WHERE id = ?')
            ->execute([$oldest . '-01 00:00:00', $top]);

        foreach (['a', 'b'] as $tag) {
            $leader = (int) test_user('p19f_ops_leader_' . $tag)['id'];
            db()->prepare('UPDATE users SET sponsor_id = ?, created_at = ? WHERE id = ?')
                ->execute([$top, $oldest . '-01 00:00:00', $leader]);

            for ($d = 1; $d <= 2; $d++) {
                $direct = (int) test_user('p19f_ops_direct_' . $tag . $d)['id'];
                db()->prepare('UPDATE users SET sponsor_id = ?, created_at = ? WHERE id = ?')
                    ->execute([$leader, $oldest . '-01 00:00:00', $direct]);
            }
        }

        $productId = Product::save([
            'name' => 'P19F ops product', 'sku' => 'P19F-OPS-' . bin2hex(random_bytes(3)),
            'price' => '1.00', 'stock' => '100000', 'status' => 'active',
        ]);

        foreach ([$oldest, $prior] as $month) {
            $order(db(), $productId, $top, 500000.0, $month . '-15 12:00:00');
        }

        /* One final month, one evaluated (payment paused), one pending. */
        (new RoyaltyService())->close($oldest);

        setting_set('royalty_pay_enabled', '0');
        setting_cache_clear();

        (new RoyaltyService())->evaluate($prior);

        test_signed_in_as(test_user('p19f_ops_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'period' => $oldest, 'per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $oldest;

        $controller = new AdminCommissionController();
        $data       = $controller->royaltyContext();

        assert_not_null($data['royalty_ops'], 'the context carries the operations snapshot');

        $ops = $data['royalty_ops'];

        assert_same($last, $ops['pending'][0] ?? null, 'the completed month with no header is pending');
        assert_same((string) $prior, (string) ($ops['evaluated'][0]['period'] ?? ''), 'the evaluated month is named');
        assert_same(1, (int) ($ops['evaluated'][0]['pending'] ?? 0), 'the Supervisor award is pending');
        assert_same(40000.0, (float) ($ops['evaluated'][0]['amount'] ?? 0));
        assert_same($oldest, (string) ($ops['last_final']['period'] ?? ''));
        assert_same(40000.0, (float) ($ops['last_final']['paid_total'] ?? 0));
        assert_same(false, (bool) $ops['switches']['pay'], 'payments are paused');
        assert_same(true, (bool) $ops['switches']['earn']);
        assert_same(['period' => $prior, 'action' => 'pay'], $ops['next'],
            'the next run resumes the evaluated month before closing a new one');

        $html = $renderPage('admin/commissions/index', $data, 'Commissions', 'admin.commissions');

        assert_contains('data-live-region="commission-royalty-ops"', $html);
        assert_contains('Operations', $html);
        assert_contains('Open month', $html);
        assert_contains(format_month($current), $html, 'the open month is labelled');
        assert_contains('not closable yet', $html);
        assert_contains('Last final month', $html);
        assert_contains('Pending periods', $html);
        assert_contains(format_month($last), $html, 'the pending month is named');
        assert_contains('Awaiting payment', $html);
        assert_contains('Royalty earning', $html);
        assert_contains('Payments:', $html);
        assert_contains('Paused', $html);
        assert_contains('Last close', $html);
        assert_contains('--pay-only --period=' . $prior, $html, 'the runbook command is shown');
        assert_contains('30 0 1 * *', $html, 'the documented schedule is shown');

        /* Read-only: the panel fragment carries no form, no button and
         * no close trigger (plan §10 Rule 9). */
        $payload  = $controller->feedPayload(4, '"admin.commissions:4:p19f"', $data);
        $fragment = $payload['fragments']['commission-royalty-ops'];

        assert_not_contains('<form', $fragment);
        assert_not_contains('<button', $fragment);
        assert_not_contains('Run close', $fragment);
        assert_not_contains('Run the close', $fragment);
        assert_contains('Read-only', $fragment);
    },

    /* ==========================================================
     *  T14 — the health check
     * ========================================================== */

    'P19F-T14 the health check proves the tables and settings and warns on a stale evaluated month' => function () use ($configure, $member, $order, $product, $renderPage, $oldest, $prior, $last): void {
        $configure();

        $health = (new HealthService())->checks();
        $royalty = null;

        foreach ($health['sections'] as $section) {
            if ($section['key'] === 'royalty') {
                $royalty = $section;
            }
        }

        assert_not_null($royalty, 'the Royalty section exists');
        assert_true($royalty['ok'], 'a fresh install passes the Royalty check');
        assert_contains('closed month', $royalty['summary']);
        assert_contains('admin/commissions?ledger=royalty', $royalty['href']);
        assert_same(0, $health['totals']['failing']);

        /* Build an evaluated month and age it past the 48-hour warning. */
        $user = test_user('p19f_health_sup');
        $top  = (int) $user['id'];

        db()->prepare('UPDATE users SET created_at = ? WHERE id = ?')
            ->execute([$prior . '-01 00:00:00', $top]);

        foreach (['a', 'b'] as $tag) {
            $leader = (int) test_user('p19f_health_leader_' . $tag)['id'];
            db()->prepare('UPDATE users SET sponsor_id = ?, created_at = ? WHERE id = ?')
                ->execute([$top, $prior . '-01 00:00:00', $leader]);

            for ($d = 1; $d <= 2; $d++) {
                $direct = (int) test_user('p19f_health_direct_' . $tag . $d)['id'];
                db()->prepare('UPDATE users SET sponsor_id = ?, created_at = ? WHERE id = ?')
                    ->execute([$leader, $prior . '-01 00:00:00', $direct]);
            }
        }

        $productId = Product::save([
            'name' => 'P19F health product', 'sku' => 'P19F-HLT-' . bin2hex(random_bytes(3)),
            'price' => '1.00', 'stock' => '100000', 'status' => 'active',
        ]);

        $order(db(), $productId, $top, 500000.0, $prior . '-15 12:00:00');

        (new RoyaltyService())->evaluate($prior);

        db()->prepare('UPDATE royalty_periods SET evaluated_at = ? WHERE period = ?')
            ->execute([date('Y-m-d H:i:s', time() - 50 * 3600), $prior]);

        $aged    = (new HealthService())->checks();
        $royalty = null;

        foreach ($aged['sections'] as $section) {
            if ($section['key'] === 'royalty') {
                $royalty = $section;
            }
        }

        assert_not_null($royalty);
        assert_true($royalty['ok'], 'a stale evaluated month is a warning, not a failure');
        assert_contains('older than 48 h', $royalty['summary']);
        assert_contains('still unpaid', implode(' ', $royalty['details']));
        assert_same(0, $aged['totals']['failing'], 'the warning never turns the health card red');

        /* The report page renders the section with its label. */
        test_signed_in_as(test_user('p19f_health_admin', 'admin'));

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports';

        $html = $renderPage('admin/reports/index', (new AdminReportController())->pageContext() + [
            'page_title'  => 'Reports',
            'current_nav' => 'admin.reports',
            'live'        => Live::meta('admin.reports', 'p19f'),
        ], 'Reports', 'admin.reports');

        assert_contains('Royalty stream', $html);
    },

    /* ==========================================================
     *  T15 — the exit codes
     * ========================================================== */

    'P19F-T15 the job exits 0, 1 and 2 as documented' => function () use ($fixture, $current, $last, $runCli, $logDir, $cleanup, $header): void {
        $data = $fixture($last);
        $dir  = $logDir();

        try {
            /* 0 — success. */
            $ok = $runCli($data['database'], ['--period=' . $last, '--json'], $dir);
            assert_same(0, $ok['exit'], $ok['output']);
            assert_same('paid', $header($data['pdo'], $last)['status']);

            /* 0 — a no-op on a final month. */
            $again = $runCli($data['database'], ['--period=' . $last, '--json'], $dir);
            assert_same(0, $again['exit'], $again['output']);
            assert_same('already final', $again['json']['reason']);

            /* 1 — a validation refusal (the current month). */
            $refused = $runCli($data['database'], ['--period=' . $current, '--json'], $dir);
            assert_same(1, $refused['exit'], $refused['output']);
            assert_same('current', $refused['json']['reason']);

            /* 1 — an explicit --pay-only on an absent month. */
            $absent = $runCli($data['database'], ['--pay-only', '--json'], $dir);
            assert_same(1, $absent['exit'], $absent['output']);
            assert_same('not_found', $absent['json']['reason']);

            /* 2 — the environment: no such database. */
            $missing = $runCli('ecowealth_sneat_p19f_missing_test', ['--json'], $dir);
            assert_same(2, $missing['exit'], $missing['output']);
            assert_contains('could not reach the database', $missing['output']);
        } finally {
            $cleanup($dir);
        }
    },

    /* ==========================================================
     *  T16 — the documentation
     * ========================================================== */

    'P19F-T16 docs/cron.md documents the job, its flags, logs and exit codes' => function () use ($appRoot): void {
        $doc = (string) file_get_contents($appRoot . '/../docs/cron.md');

        assert_contains('royalty_month_close', $doc);
        assert_contains('# 7. Royalty month close', $doc);
        assert_contains('--doctor', $doc);
        assert_contains('--max-batches', $doc);
        assert_contains('royalty_month_close-YYYY-MM.log', $doc);
        assert_contains('Exit codes', $doc);
        assert_contains('30 0 1 * *', $doc, 'the recommended schedule is stated');
        assert_contains('--pay-only', $doc);
        assert_contains('public/cron/royalty_month_close.php', $doc);
        assert_contains('royalty_month_close.cmd', $doc);
    },

    /* ==========================================================
     *  P19G-T11 — a log write failure warns, and the run continues
     *
     *  The plan's risk table asks for exactly this: "A log write
     *  fails on a read-only host | the run continues; stderr warns."
     * ========================================================== */

    'P19G-T11 a failed log write warns through error_log and never throws' => function (): void {
        /* A regular file used as the log *directory* cannot be created
         * or written into, on any host. */
        $blocked = (string) tempnam(sys_get_temp_dir(), 'p19g-log-');
        $capture = (string) tempnam(sys_get_temp_dir(), 'p19g-warn-');
        $log     = new CronLog($blocked, 'royalty_month_close');

        $previous = ini_set('error_log', $capture);

        try {
            royalty_close_write_log($log, 'cli', 'OK', [
                'closed'  => 1,
                'periods' => ['2026-10'],
                'award'   => 1,
                'paid'    => 1,
                'amount'  => 40000.0,
                'skipped' => 0,
                'status'  => 'paid',
                'paused'  => false,
            ]);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $warning = (string) file_get_contents($capture);

        assert_contains('could not be written to', $warning, 'the warning names the failure');
        assert_contains('the run continued', $warning, 'the warning says the run continued');

        @unlink($blocked);
        @unlink($capture);
    },
];
