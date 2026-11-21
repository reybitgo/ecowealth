<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestCatchupTest — Phase 20D, the on-demand job (plan §14.11)
 *
 *  The state-based engine must be runnable on demand: after enabling
 *  the stream, after a repair, after a counter alarm and after a deploy.
 *  This file proves the job's contract as a **real command**:
 *
 *      liability     what the engine would pay, to the peso, and the
 *                    per-level counts (T18)
 *      catch-up      the backlog pays exactly once; a second run writes
 *                    nothing (T20)
 *      unseeded      a hard refusal: the exception and the job's exit 1
 *                    (T21)
 *      cli           --help, exit 2 on an unreachable database, a real
 *                    run, a refused unseeded run (T37)
 *      --member=ID   one member only; the rest stays liable (T38)
 *      --doctor      the health report: green on a clean build, red on
 *                    drift (T39)
 *
 *  The CLI cases run against a **scratch schema** (`ecowealth_sneat_
 *  tool_b_test`) because the harness's own transaction is invisible to a
 *  subprocess; the in-process cases run on the test schema. `ECO_DB_NAME`
 *  points the subprocess at the scratch database and never at the
 *  development copy.
 *
 *  Run this group alone with:  php tests/run.php TeamHarvestCatchup
 * ============================================================== */

$fixtures = require __DIR__ . '/../fixtures/harvest_money.php';

$clean     = $fixtures['clean'];
$arm       = $fixtures['arm'];
$enable    = $fixtures['enable'];
$grow      = $fixtures['grow'];
$account   = $fixtures['account'];
$count     = $fixtures['count'];

$appRoot = dirname(__DIR__, 2);

/** Run the real CLI against one database (`ECO_DB_NAME` outranks the config). */
$run = static function (string $database, array $arguments) use ($appRoot): array {
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($appRoot . '/bin/team_harvest_catchup.php');

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $lines = [];
        exec($command . ' 2>&1', $lines, $code);
        $output = implode("\n", $lines);
        $json   = json_decode($output, true);
    } finally {
        if ($previous === false) {
            putenv('ECO_DB_NAME');
        } else {
            putenv('ECO_DB_NAME=' . $previous);
        }
    }

    return ['exit' => $code, 'output' => $output, 'json' => is_array($json) ? $json : null];
};

/** A fresh committed scratch schema with the settings seed applied. */
$scratch = static function (string $slot, array $settings = []) use ($appRoot): array {
    [$pdo, $database] = test_scratch_database($slot);
    test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
    test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

    $update = $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?');

    foreach ($settings as $key => $value) {
        $update->execute([(string) $value, (string) $key]);
    }

    return [$pdo, $database];
};

/** One committed member account on a scratch schema. */
$addUser = static function (PDO $pdo, string $name, ?int $sponsor, string $at): int {
    $pdo->prepare(
        "INSERT INTO users
           (username, email, password_hash, role, member_status, sponsor_id, referral_code, is_active, created_at)
         VALUES (?, ?, 'x', 'member', 'active', ?, ?, 1, ?)"
    )->execute([$name, $name . '@p20d.scratch', $sponsor, strtoupper(bin2hex(random_bytes(4))), $at]);

    return (int) $pdo->lastInsertId();
};

/**
 * Build one tree that will pay after go-live, in the **two phases a
 * real network has**: first the top member and its three directs (the
 * top qualifies at the seed, holding `n = 0`), then the directs' own
 * three members each (which qualify *after* the seed, so the top's
 * level 1 completes after the baseline and becomes payable).
 *
 * @return array{top:int, directs:list<int>}
 */
$topTree = static function (PDO $pdo, string $prefix, string $day) use ($addUser): array {
    $top     = $addUser($pdo, $prefix . '_top', null, $day . ' 08:00:00');
    $directs = [];

    foreach (['a', 'b', 'c'] as $index => $suffix) {
        $directs[] = $addUser($pdo, $prefix . '_' . $suffix, $top, $day . ' 09:0' . $index . ':00');
    }

    return ['top' => $top, 'directs' => $directs];
};

/** The second phase: three member accounts under each direct. */
$growDirects = static function (PDO $pdo, array $tree, string $day) use ($addUser): int {
    $added = 0;

    foreach ($tree['directs'] as $index => $direct) {
        for ($i = 1; $i <= 3; $i++) {
            $addUser($pdo, 'm' . $direct . '_' . $i, $direct, $day . ' 10:0' . $i . ':00');
            $added++;
        }
    }

    return $added;
};

/** The seed job, so a scratch tree has a real queue and baseline. */
$seedScratch = static function (string $database, bool $force = false) use ($appRoot): array {
    /* The seed lives in bin/harvest_queue_seed.php, not this file's job:
     * run it through the same subprocess mechanism. */
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($appRoot . '/bin/harvest_queue_seed.php') . ' --json'
            . ($force ? ' --force' : '');
        $lines = [];
        exec($command . ' 2>&1', $lines, $code);
        $output = implode("\n", $lines);
        $json   = json_decode($output, true);
    } finally {
        if ($previous === false) {
            putenv('ECO_DB_NAME');
        } else {
            putenv('ECO_DB_NAME=' . $previous);
        }
    }

    return ['exit' => $code, 'output' => $output, 'json' => is_array($json) ? $json : null];
};

return [

    /* ==========================================================
     *  The open liability (T18)
     * ========================================================== */

    'P20D-T18 openLiability equals every payable-but-unwritten level, to the peso' => function () use ($clean, $arm, $enable, $grow, $count): void {
        $clean();
        /* A seeded queue with the switch OFF: every completed level is
         * unfunded, so the whole ladder is open liability. */
        $arm('2026-11-01 00:00:00', null, false);
        $service = new TeamHarvestService();
        $tree    = $grow(3, 't18', $service);

        assert_same(0, $count('team_harvest_awards'), 'the switch was off: nothing is written');

        $liability = $service->openLiability();

        assert_same(18, $liability['levels'], 'the same 18 level-lines fixture A pays');
        assert_same(13, $liability['members']);
        assert_same(25000.0, $liability['amount']);

        assert_same(13, $liability['by_level'][1]['count']);
        assert_same(13000.0, $liability['by_level'][1]['amount']);
        assert_same(4, $liability['by_level'][2]['count']);
        assert_same(8000.0, $liability['by_level'][2]['amount']);
        assert_same(1, $liability['by_level'][3]['count']);
        assert_same(4000.0, $liability['by_level'][3]['amount']);
        assert_same(0, $liability['by_level'][4]['count']);

        /* The same number per member: statusFor()'s `open` must sum to it. */
        $sum = 0.0;

        foreach (array_keys((new HarvestQueue())->map()) as $userId) {
            $sum += (float) $service->statusFor((int) $userId)['open'];
        }

        assert_same(25000.0, round($sum, 2), 'the per-member opens sum to the network liability');

        /* Once the engine pays, nothing is open any more. */
        $enable(true);
        $service->evaluateAll(500);

        $after = $service->openLiability();
        assert_same(0, $after['levels']);
        assert_same(0, $after['members']);
        assert_same(0.0, $after['amount']);

        assert_same(25000.0, round((float) db()->query(
            "SELECT COALESCE(SUM(amount), 0) FROM team_harvest_awards WHERE status = 'credited'"
        )->fetchColumn(), 2));
    },

    /* ==========================================================
     *  The catch-up (T20)
     * ========================================================== */

    'P20D-T20 enabling the stream and running the catch-up pays the backlog once' => function () use ($clean, $arm, $enable, $grow, $count, $fixtures): void {
        $clean();
        $arm('2026-11-01 00:00:00', null, false);
        $service = new TeamHarvestService();
        $grow(3, 't20', $service);

        assert_same(0, $count('team_harvest_awards'), 'no money while the switch is off');

        $enable(true);

        $first = $service->evaluateAll(500);

        assert_same(18, $first['paid']);
        assert_same(25000.0, $first['amount']);
        assert_same(0, $first['baseline']);
        assert_same(40, $first['members'], 'forty queued members were evaluated');
        assert_same(1, $first['batches']);

        $second = $service->evaluateAll(500);

        assert_same(0, $second['paid'], 'a second run pays nothing');
        assert_same(0, $second['written']);
        assert_same(0.0, $second['amount']);

        assert_same(18, $count('team_harvest_awards'));
        assert_same(18, $count('ewallet_ledger', "ref_type = 'team_harvest'"));
        assert_same(25000.0, (new TeamHarvestService())->reconcile()['credited_total']);
    },

    /* ==========================================================
     *  Unseeded is a hard refusal (T21)
     * ========================================================== */

    'P20D-T21 an unseeded queue refuses the engine and exits 1 from the job' => function () use ($clean, $enable, $account, $fixtures, $run): void {
        $clean();
        $enable(true);

        /* An enabled stream with no baseline: nothing can be judged. */
        $service = new TeamHarvestService();
        assert_same(false, $service->seeded());

        $member = $account('t21_member');

        $error = assert_throws(
            static fn () => $service->evaluate([$member]),
            TeamHarvestException::class
        );

        assert_same('unseeded', $error->reason);
        assert_same(0, $fixtures['count']('team_harvest_awards'));
        assert_same(0, $fixtures['count']('ewallet_ledger', "ref_type = 'team_harvest'"));

        /* The real job refuses the same state with exit 1 and a reason,
         * not with a crash and not with a 0. */
        [$pdo, $database] = test_scratch_database('tool_b');
        test_run_sql_file($pdo, dirname(__DIR__, 2) . '/database/schema.sql');
        test_run_sql_file($pdo, dirname(__DIR__, 2) . '/database/seeds/001_settings.sql');
        $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'team_harvest_enabled'");
        $pdo->exec("UPDATE settings SET value = '' WHERE key_name = 'team_harvest_ledger_since'");

        $result = $run($database, ['--json']);

        assert_same(1, $result['exit'], 'an unseeded run exits 1: ' . $result['output']);
        assert_not_null($result['json'], 'and answers in JSON: ' . $result['output']);
        assert_same(false, (bool) $result['json']['ok']);
        assert_same('unseeded', (string) $result['json']['error']);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());

        $dry = $run($database, ['--dry-run', '--json']);
        assert_same(1, $dry['exit'], 'a dry run cannot report a liability without a baseline');
        assert_same('unseeded', (string) $dry['json']['error']);
    },

    /* ==========================================================
     *  The CLI (T37–T39)
     * ========================================================== */

    'P20D-T37 the job is a real command: help, exit codes, a run and a replay' => function () use ($run, $scratch, $topTree, $growDirects, $seedScratch): void {
        /* 1. --help exits 0 and names every flag, before touching a database. */
        $help = $run('ecowealth_sneat_test', ['--help']);

        assert_same(0, $help['exit']);
        foreach (['--dry-run', '--member', '--doctor', '--json', '--limit', '--quiet', '--help'] as $flag) {
            assert_contains($flag, $help['output']);
        }

        /* 2. An unreachable database exits 2, never 0. */
        $none = $run('ecowealth_sneat_no_such_db_test', ['--json']);

        assert_same(2, $none['exit'], 'environment failures exit 2: ' . $none['output']);
        assert_not_null($none['json']);
        assert_same(false, (bool) $none['json']['ok']);

        /* 3. A real end-to-end run on a committed scratch schema:
         *    seed the top, grow the network, force-seed the new
         *    qualifiers, then evaluate. */
        [$pdo, $database] = $scratch('tool_b', [
            'team_harvest_enabled'         => '1',
            'team_harvest_qualify_directs' => '3',
            'team_harvest_max_levels'      => '10',
        ]);

        $tree = $topTree($pdo, 'cli1', '2026-10-01');

        $seed = $seedScratch($database);

        assert_same(0, $seed['exit'], 'the seed runs: ' . $seed['output']);
        assert_same(1, (int) $seed['json']['inserted'], 'only the top holds the gate at go-live');
        assert_same(0, (int) $pdo->query("SELECT teammates FROM harvest_queue WHERE user_id = " . $tree['top'])->fetchColumn(), 'the top starts at n = 0, baseline 0 — level 1 is still open');

        $growDirects($pdo, $tree, '2026-10-03');

        $forced = $seedScratch($database, true);

        assert_same(0, $forced['exit'], 'the forced seed appends the new qualifiers: ' . $forced['output']);
        assert_same(3, (int) $forced['json']['inserted']);
        assert_same(4, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());

        $doctor = $run($database, ['--doctor', '--json']);

        assert_same(0, $doctor['exit'], 'the doctor is green after a seed: ' . $doctor['output']);
        assert_same(true, (bool) $doctor['json']['doctor']['ok']);
        $keys = array_column($doctor['json']['doctor']['checks'], 'key');

        foreach (['clock', 'tables', 'settings', 'seed', 'baseline', 'queue', 'awards', 'drift', 'reconcile'] as $key) {
            assert_true(in_array($key, $keys, true), 'the doctor reports ' . $key);
        }

        $dry = $run($database, ['--dry-run', '--json']);

        assert_same(0, $dry['exit'], 'the dry run succeeds: ' . $dry['output']);
        assert_same(true, (bool) $dry['json']['dry_run']);
        assert_same(1000.0, (float) $dry['json']['liability']['amount'], 'the top completed level 1 after go-live');
        assert_same(1, (int) $dry['json']['liability']['levels']);
        assert_same(1, (int) $dry['json']['liability']['members']);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn(), 'a dry run writes nothing');

        $real = $run($database, ['--json']);

        assert_same(0, $real['exit'], 'the run succeeds: ' . $real['output']);
        assert_same(1, (int) $real['json']['result']['paid']);
        assert_same(1000.0, (float) $real['json']['result']['amount']);
        assert_same(4, (int) $real['json']['members'], 'four queued members were evaluated');
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());
        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'team_harvest.catchup'")->fetchColumn(),
            'the run audits itself'
        );

        $again = $run($database, ['--json']);
        assert_same(0, $again['exit']);
        assert_same(0, (int) $again['json']['result']['paid'], 'a second run writes no money');
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());
        assert_same(
            2,
            (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'team_harvest.catchup'")->fetchColumn(),
            'but it does audit that the operator ran it'
        );
    },

    'P20D-T38 --member evaluates one member and leaves the rest liable' => function () use ($run, $scratch, $topTree, $growDirects, $seedScratch): void {
        [$pdo, $database] = $scratch('tool_b', [
            'team_harvest_enabled'         => '1',
            'team_harvest_qualify_directs' => '3',
            'team_harvest_max_levels'      => '10',
        ]);

        $first  = $topTree($pdo, 'cli2a', '2026-10-01');
        $second = $topTree($pdo, 'cli2b', '2026-10-02');

        assert_same(0, $seedScratch($database)['exit']);
        $growDirects($pdo, $first, '2026-10-03');
        $growDirects($pdo, $second, '2026-10-04');
        assert_same(0, $seedScratch($database, true)['exit']);

        $one = $run($database, ['--member=' . $first['top'], '--json']);

        assert_same(0, $one['exit'], 'one member evaluates: ' . $one['output']);
        assert_same(1, (int) $one['json']['result']['paid']);
        assert_same($first['top'], (int) $one['json']['member']);
        assert_same(1, (int) $one['json']['members'], 'the member filter narrows the batch');

        $rows = $pdo->query('SELECT user_id FROM team_harvest_awards')->fetchAll(PDO::FETCH_COLUMN);
        assert_same([(string) $first['top']], array_map('strval', $rows), 'only the named member was paid');

        $dry = $run($database, ['--dry-run', '--json']);
        assert_same(1000.0, (float) $dry['json']['liability']['amount'], 'the other tree is still liable');

        $all = $run($database, ['--json']);
        assert_same(0, $all['exit']);
        assert_same(1, (int) $all['json']['result']['paid'], 'the rest of the network is now paid');
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());
        assert_same(0.0, (float) $run($database, ['--dry-run', '--json'])['json']['liability']['amount']);
    },

    'P20D-T39 --doctor is green on a clean build and red on counter drift' => function () use ($run, $scratch, $topTree, $growDirects, $seedScratch): void {
        [$pdo, $database] = $scratch('tool_b', [
            'team_harvest_enabled'         => '1',
            'team_harvest_qualify_directs' => '3',
            'team_harvest_max_levels'      => '10',
        ]);

        $tree = $topTree($pdo, 'cli3', '2026-10-01');
        assert_same(0, $seedScratch($database)['exit']);
        $growDirects($pdo, $tree, '2026-10-03');
        assert_same(0, $seedScratch($database, true)['exit']);

        $clean = $run($database, ['--doctor', '--json']);

        assert_same(0, $clean['exit'], 'a clean build is green: ' . $clean['output']);
        assert_same(true, (bool) $clean['json']['doctor']['ok']);

        /* The doctor reports the seeded state and the queue. */
        $byKey = [];

        foreach ($clean['json']['doctor']['checks'] as $check) {
            $byKey[(string) $check['key']] = $check;
        }

        assert_same(true, (bool) $byKey['seed']['ok']);
        assert_contains('4 position', (string) $byKey['queue']['detail']);
        assert_contains('seeded', (string) $byKey['seed']['detail']);

        /* Hand-edit one counter: the recount disagrees. */
        $pdo->prepare('UPDATE harvest_queue SET teammates = teammates + 5 WHERE user_id = ?')
            ->execute([$tree['top']]);

        $drifted = $run($database, ['--doctor', '--json']);

        assert_same(1, $drifted['exit'], 'drift fails the doctor: ' . $drifted['output']);
        assert_same(false, (bool) $drifted['json']['doctor']['ok']);

        $checks = [];

        foreach ($drifted['json']['doctor']['checks'] as $check) {
            $checks[(string) $check['key']] = $check;
        }

        assert_same(false, (bool) $checks['drift']['ok']);
        assert_contains('1 counter', (string) $checks['drift']['detail']);
        assert_same(false, (bool) $drifted['json']['doctor']['reconcile']['ok'], 'the reconcile agrees with it');

        /* A counter edited back is green again; the doctor never repairs
         * anything itself. */
        $pdo->prepare('UPDATE harvest_queue SET teammates = teammates - 5 WHERE user_id = ?')
            ->execute([$tree['top']]);

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn(), 'the doctor paid nothing');

        $restored = $run($database, ['--doctor', '--json']);
        assert_same(0, $restored['exit'], 'the clean state is green again: ' . $restored['output']);
    },
];
