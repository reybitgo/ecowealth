<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestCatchupTest — Phase 21D slice S4 (plan §6, §14.11,
 *  §16.3)
 *
 *  Proves the CLI catch-up job on its own, against a real scratch
 *  database (the job runs as a separate process and cannot see the
 *  case's transaction):
 *
 *      P21D-T41   --dry-run on a backlog: reports N, settled_n, the
 *                 per-level missing rows and the open liability;
 *                 writes nothing
 *      P21D-T42   --doctor on a clean build: every line green, exit 0
 *      P21D-T43   --limit=1: settles one position per transaction
 *      P21D-T44   unarmed board: exit 1, names the arm job, writes
 *                 nothing
 *
 *  Each case that runs the real CLI uses `test_scratch_database()` and
 *  `exec(PHP_BINARY ...)`, exactly as `HarvestQueueTest` proves the seed
 *  job (P20C-T27). All setup (schema, seed, queue, arm, settings) runs
 *  on the scratch database's `$pdo` so the CLI subprocess (a separate
 *  process, connected via `ECO_DB_NAME`) sees it.
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestCatchup
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/**
 * Run the real General Harvest catch-up CLI against one database.
 *
 * @param string $database
 * @param list<string> $arguments
 * @return array{exit:int, output:string, json:?array}
 */
$run = static function (string $database, array $arguments) use ($appRoot): array {
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($appRoot . '/bin/general_harvest_catchup.php');

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

/**
 * Build a scratch schema with the Phase 21 tables, a seeded queue,
 * the reference ladder and (optionally) the arm already applied.
 * Every write goes through `$pdo` so the CLI subprocess (a separate
 * process, connected via `ECO_DB_NAME`) sees it.
 *
 * @param PDO $pdo
 * @param bool $armed whether to arm the board after building the queue
 * @return void
 */
$build = static function (PDO $pdo, bool $armed = true): void {
    /* The reference ladder, exactly as a fresh install carries it (21A). */
    $reference = GeneralHarvestLevel::referencePreset();
    $update    = $pdo->prepare('UPDATE general_harvest_levels SET amount = ? WHERE level = ?');

    foreach ($reference as $level => $amount) {
        $update->execute([number_format($amount, 2, '.', ''), (int) $level]);
    }

    /* The queue must be seeded before the arm can run (D-07). */
    $pdo->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = ?'
    )->execute([
        'team_harvest_ledger_since', '2026-11-01 00:00:00', '2026-11-01 00:00:00',
    ]);

    /* Build fixture C (h = 3): 121 accounts, 40 qualified. Each account
     * is a member with three directs at the leaf level; qualification
     * order is BFS: root, then each generation. */
    $insertUser = $pdo->prepare(
        'INSERT INTO users
           (username, email, password_hash, full_name, role, member_status,
            sponsor_id, referral_code, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );

    $add = static function (string $username, ?int $sponsorId) use ($insertUser, $pdo): int {
        $insertUser->execute([
            $username,
            $username . '@p21d_t4x.test',
            password_hash('P21dMember@123', PASSWORD_BCRYPT),
            ucfirst(str_replace('_', ' ', $username)),
            'member',
            'active',
            $sponsorId,
            strtoupper(bin2hex(random_bytes(4))),
        ]);

        return (int) $pdo->lastInsertId();
    };

    /* h = 3: root (level 0), 3 children (level 1), 9 grandchildren
     * (level 2), 27 great-grandchildren (level 3) = 40 qualified, with
     * 3 non-qualifying leaf directs under each level-3 member = 81 more
     * accounts = 121 total. */
    $root    = $add('p21d_t4x_root', null);
    $level1  = [];
    $level2  = [];
    $level3  = [];

    foreach ([1, 2, 3] as $i) {
        $level1[] = $add('p21d_t4x_l1_' . $i, $root);
    }

    foreach ($level1 as $parent) {
        foreach ([1, 2, 3] as $i) {
            $level2[] = $add('p21d_t4x_l2_' . $parent . '_' . $i, $parent);
        }
    }

    foreach ($level2 as $parent) {
        foreach ([1, 2, 3] as $i) {
            $level3[] = $add('p21d_t4x_l3_' . $parent . '_' . $i, $parent);
        }
    }

    /* Leaf directs: three non-qualifying directs under each level-3
     * member (they never reach 3 directs so they never qualify). */
    foreach ($level3 as $parent) {
        foreach ([1, 2, 3] as $i) {
            $add('p21d_t4x_x_' . $parent . '_' . $i, $parent);
        }
    }

    /* Qualified members in BFS order: root, level 1, level 2, level 3. */
    $qualified = array_merge([$root], $level1, $level2, $level3);
    assert_same(40, count($qualified), 'fixture C: 40 qualified members');

    /* Write the queue with explicit ids 1 … 40, but arm at N₀ = 13 first
     * so positions 14 … 40 become the backlog (fixture F, §16.2). The
     * arm reads COUNT(*), so only the first 13 queue rows may exist
     * when it runs. */
    $insertQueue = $pdo->prepare(
        "INSERT INTO harvest_queue
           (id, user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source)
         VALUES (?, ?, ?, 3, 0, 0, 'seed')"
    );

    /* The first 13 qualified members (root + level 1 + level 2 = 1 + 3 +
     * 9) are queued before the arm. */
    $first13 = array_slice($qualified, 0, 13);
    assert_same(13, count($first13), 'fixture F: the first 13 members queue before the arm');

    foreach ($first13 as $index => $userId) {
        $insertQueue->execute([
            $index + 1,
            (int) $userId,
            date('Y-m-d H:i:s', strtotime('2026-11-01 00:00:00') + $index * 60),
        ]);
    }

    /* Optionally arm at N₀ = 13: baseline rows for levels 1–2 (5 rows),
     * settled_n = 13. Positions 14 … 40 become the backlog. */
    if ($armed) {
        $armResult = (new GeneralHarvestService($pdo))->arm(false, null);
        assert_true((bool) ($armResult['armed'] ?? false), 'the arm succeeded: ' . json_encode($armResult));
        assert_same(13, (int) $armResult['n'], 'N₀ = 13');
    }

    /* Now queue the remaining 27 members (level 3) so the backlog is
     * positions 14 … 40. */
    $rest = array_slice($qualified, 13);
    assert_same(27, count($rest), 'fixture F: 27 members queue after the arm');

    foreach ($rest as $index => $userId) {
        $insertQueue->execute([
            14 + $index,
            (int) $userId,
            date('Y-m-d H:i:s', strtotime('2026-11-01 00:00:00') + (13 + $index) * 60),
        ]);
    }

    /* Turn the stream on. */
    $pdo->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = ?'
    )->execute([
        'general_harvest_enabled', '1', '1',
    ]);
};

return [

    /* ==========================================================
     *  P21D-T41 — --dry-run on a backlog: reports, writes nothing
     * ========================================================== */

    'P21D-T41 --dry-run reports the backlog and writes nothing' => function () use (
        $run, $appRoot, $build
    ): void {
        [$pdo, $database] = test_scratch_database('tool_b');

        test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

        $build($pdo, true);

        $before = (int) $pdo->query(
            'SELECT COUNT(*) FROM general_harvest_awards'
        )->fetchColumn();
        $settledBefore = (int) $pdo->query(
            'SELECT settled_n FROM general_harvest_board WHERE id = 1'
        )->fetchColumn();

        $dry = $run($database, ['--dry-run', '--json']);

        assert_same(0, $dry['exit'], 'a dry run exits 0: ' . $dry['output']);
        assert_not_null($dry['json'], 'the dry run answers in JSON: ' . $dry['output']);
        assert_true((bool) ($dry['json']['dry_run'] ?? false), 'the payload says dry_run');
        /* openLiability().positions is the backlog: N − settled_n = 40 − 13 = 27. */
        assert_same(27, (int) ($dry['json']['liability']['positions'] ?? -1), '27 positions are waiting');
        assert_true((int) ($dry['json']['liability']['amount'] ?? -1) > 0, 'the open liability is positive');

        /* A dry run writes nothing. */
        assert_same($before, (int) $pdo->query(
            'SELECT COUNT(*) FROM general_harvest_awards'
        )->fetchColumn(), 'no award row from a dry run');
        assert_same($settledBefore, (int) $pdo->query(
            'SELECT settled_n FROM general_harvest_board WHERE id = 1'
        )->fetchColumn(), 'settled_n unchanged by a dry run');
    },

    /* ==========================================================
     *  P21D-T42 — --doctor on a clean build: every line green, exit 0
     * ========================================================== */

    'P21D-T42 --doctor proves a healthy stream, exit 0' => function () use (
        $run, $appRoot, $build
    ): void {
        [$pdo, $database] = test_scratch_database('tool_b');

        test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

        $build($pdo, true);

        $doctor = $run($database, ['--doctor', '--json']);

        assert_same(0, $doctor['exit'], 'the doctor exits 0 on a clean build: ' . $doctor['output']);
        assert_not_null($doctor['json'], 'the doctor answers in JSON: ' . $doctor['output']);
        assert_true((bool) ($doctor['json']['ok'] ?? false), 'the doctor is green: ' . $doctor['output']);
        $d = $doctor['json']['doctor'] ?? [];
        assert_same(40, (int) ($d['queue_n'] ?? -1), 'the queue reports N = 40');
        assert_same(13, (int) ($d['settled_n'] ?? -1), 'settled_n = 13 after the arm');
        assert_true((bool) ($d['gapless'] ?? false), 'the queue is gapless');
        assert_true((bool) ($d['armed'] ?? false), 'the board is armed');
    },

    /* ==========================================================
     *  P21D-T43 — --limit=1 settles one position per transaction
     * ========================================================== */

    'P21D-T43 --limit=1 settles one position per transaction' => function () use (
        $run, $appRoot, $build
    ): void {
        [$pdo, $database] = test_scratch_database('tool_b');

        test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

        $build($pdo, true);

        $before = (int) $pdo->query(
            'SELECT COUNT(*) FROM general_harvest_awards'
        )->fetchColumn();

        $first = $run($database, ['--limit=1', '--json']);

        assert_same(0, $first['exit'], '--limit=1 settles one position: ' . $first['output']);
        assert_not_null($first['json'], 'the run answers in JSON: ' . $first['output']);
        assert_true((bool) ($first['json']['ok'] ?? false), 'the run succeeded: ' . $first['output']);

        /* One position settles at most one block per level. With N = 40
         * and settled_n = 13, position 14 (seq 13) completes no block on
         * its own — node 4's level-1 block (#14–#16) completes at
         * position 16. So the first two runs write nothing and only
         * advance settled_n. */
        assert_same(14, (int) ($first['json']['result']['settled_n'] ?? -1), 'settled_n advances to 14');
        assert_same(0, (int) ($first['json']['result']['written'] ?? -1), 'position 14 writes nothing');

        $after = (int) $pdo->query(
            'SELECT COUNT(*) FROM general_harvest_awards'
        )->fetchColumn();
        assert_same($before, $after, 'no award row from position 14');

        /* Second run: position 15, still nothing. */
        $second = $run($database, ['--limit=1', '--json']);
        assert_same(0, $second['exit'], 'the second --limit=1 run also settles: ' . $second['output']);
        assert_same(15, (int) ($second['json']['result']['settled_n'] ?? -1), 'settled_n advances to 15');
        assert_same(0, (int) ($second['json']['result']['written'] ?? -1), 'position 15 writes nothing');

        /* Third run: position 16 completes node 4's level-1 block (#14-#16),
         * so one award is written. */
        $third = $run($database, ['--limit=1', '--json']);
        assert_same(0, $third['exit'], 'the third --limit=1 run settles position 16: ' . $third['output']);
        assert_same(16, (int) ($third['json']['result']['settled_n'] ?? -1), 'settled_n advances to 16');
        assert_same(1, (int) ($third['json']['result']['written'] ?? -1), 'position 16 writes one award');

        $final = (int) $pdo->query(
            'SELECT COUNT(*) FROM general_harvest_awards'
        )->fetchColumn();
        assert_same($before + 1, $final, 'exactly one award row after three positions');
    },

    /* ==========================================================
     *  P21D-T44 — unarmed board: exit 1, names the arm job
     * ========================================================== */

    'P21D-T44 catch-up against an unarmed board is refused, exit 1' => function () use (
        $run, $appRoot, $build
    ): void {
        [$pdo, $database] = test_scratch_database('tool_b');

        test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

        $build($pdo, false);

        /* Disarm: the build with $armed = false never armed, but make
         * sure settled_n = 0 and there are no award rows. */
        $pdo->exec(
            "UPDATE general_harvest_board
               SET baseline_n = NULL, armed_at = NULL, armed_by = NULL, settled_n = 0
             WHERE id = 1"
        );
        $pdo->exec('DELETE FROM general_harvest_awards');

        $refused = $run($database, ['--json']);

        assert_same(1, $refused['exit'], 'an unarmed board refuses: ' . $refused['output']);
        assert_not_null($refused['json'], 'the refusal answers in JSON: ' . $refused['output']);
        assert_false((bool) ($refused['json']['ok'] ?? true), 'the payload reports failure');

        /* The human-readable message names the arm command. Run without
         * --json to capture it. */
        $human = $run($database, []);
        assert_contains('general_harvest_arm', strtolower($human['output']), 'the message names the arm command');

        /* Nothing was written. */
        assert_same(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM general_harvest_awards'
        )->fetchColumn(), 'no award row when the board is unarmed');
        assert_same(0, (int) $pdo->query(
            'SELECT settled_n FROM general_harvest_board WHERE id = 1'
        )->fetchColumn(), 'settled_n stays 0');
    },

];
