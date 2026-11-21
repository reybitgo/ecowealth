<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestBaselineTest — Phase 21C (plan §6, §12.6, §14.3,
 *  §14.10, §13.8)
 *
 *  Proves the go-live baseline on its own:
 *
 *      arm         the rows for every block already full at N₀, at all
 *                  ten levels (including levels above the depth), one
 *                  board row, one audit row, one transaction
 *      refusals    enabled · armed · unseeded · invalid_ladder ·
 *                  not_gapless · the advisory lock
 *      determinism two dry runs print the same numbers; a dry run
 *                  writes nothing at all
 *      shape       every row is `baseline`, `ledger_id NULL`,
 *                  `n_at_award = N₀`, the closed-form threshold and the
 *                  configured amount; per level the rows are exactly
 *                  `0 … qFor(L, N₀)`
 *      state       after arming `baseline_n = settled_n = N₀`, the
 *                  backlog is `N − N₀`, and the ladder is frozen
 *      no money    not one credited row, not one wallet movement, the
 *                  wallet untouched — the phase's whole gate (T9/T21)
 *      cli         --help, --dry-run, --json, exit codes, a fresh
 *                  install's refusal and the real reset → re-arm cycle
 *
 *  The in-process cases run on the test schema inside the harness
 *  transaction. The CLI cases run against a **scratch schema**
 *  (`ecowealth_sneat_tool_b_test`) because a subprocess cannot see the
 *  harness's transaction; `ECO_DB_NAME` points the subprocess at the
 *  scratch database and never at the development copy.
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestBaseline
 * ============================================================== */

$fixtures = require __DIR__ . '/../fixtures/general_harvest.php';

$clean     = $fixtures['clean'];
$grow      = $fixtures['grow'];
$qualified = $fixtures['qualified'];
$queue     = $fixtures['queue'];
$seed      = $fixtures['seedMarker'];
$arm       = $fixtures['arm'];
$dryRun    = $fixtures['dryRun'];
$count     = $fixtures['count'];
$awards    = $fixtures['awards'];

$appRoot = dirname(__DIR__, 2);

/** Run the real arm CLI against one database (`ECO_DB_NAME` outranks the config). */
$cli = static function (string $database, array $arguments) use ($appRoot): array {
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($appRoot . '/bin/general_harvest_arm.php');

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
$scratch = static function (array $seeds = ['database/seeds/001_settings.sql']) use ($appRoot): array {
    [$pdo, $database] = test_scratch_database('tool_b');
    test_run_sql_file($pdo, $appRoot . '/database/schema.sql');

    foreach ($seeds as $seedFile) {
        test_run_sql_file($pdo, $appRoot . '/' . $seedFile);
    }

    return [$pdo, $database];
};

/**
 * Committed member accounts on a scratch schema, the first `$queue` of
 * them also given the queue positions `1 … $queue`.
 *
 * @return list<int>
 */
$scratchMembers = static function (PDO $pdo, int $members, string $prefix, int $queue = 0): array {
    $insert = $pdo->prepare(
        "INSERT INTO users
           (username, email, password_hash, full_name, role, member_status, referral_code, is_active)
         VALUES (?, ?, 'x', ?, 'member', 'active', ?, 1)"
    );
    $position = $pdo->prepare(
        "INSERT INTO harvest_queue
           (id, user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source)
         VALUES (?, ?, '2026-11-01 00:00:00', 3, 0, 0, 'seed')"
    );

    $ids = [];

    for ($i = 1; $i <= $members; $i++) {
        $insert->execute([$prefix . $i, $prefix . $i . '@p21c.scratch', 'Member ' . $i, strtoupper(bin2hex(random_bytes(4)))]);
        $id    = (int) $pdo->lastInsertId();
        $ids[] = $id;

        if ($i <= $queue) {
            $position->execute([$i, $id]);
        }
    }

    return $ids;
};

/** Point a scratch schema at a live queue (Phase 20's seed marker). */
$scratchSeed = static function (PDO $pdo, string $instant = '2026-11-01 00:00:00'): void {
    $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?')
        ->execute([$instant, 'team_harvest_ledger_since']);
};

/** The board row of a scratch schema, comparable. */
$scratchBoard = static fn (PDO $pdo): string => (string) $pdo->query(
    "SELECT CONCAT_WS('|', COALESCE(baseline_n, 'NULL'), COALESCE(armed_at, 'NULL'),
                       COALESCE(armed_by, 'NULL'), settled_n)
       FROM general_harvest_board WHERE id = 1"
)->fetchColumn();

/** An armed fixture B: 40 accounts, N = 13, the reference ladder. */
$fixtureB = static function () use ($clean, $grow, $qualified, $queue, $seed): array {
    $clean();
    $tree = $grow(2, 'p21c_b_' . bin2hex(random_bytes(3)));
    $queue($qualified($tree));
    $seed();

    return $tree;
};

/** An armed fixture C: 121 accounts, N = 40. */
$fixtureC = static function () use ($clean, $grow, $qualified, $queue, $seed): array {
    $clean();
    $tree = $grow(3, 'p21c_c_' . bin2hex(random_bytes(3)));
    $queue($qualified($tree));
    $seed();

    return $tree;
};

return [

    /* ==========================================================
     *  The arm and its row set (P21C-T1, T7, T10, T11, T19)
     * ========================================================== */

    'P21C-T1 arm fixture B (40 accounts, N = 13) writes five baseline rows and ₱12,000.00' => function () use ($fixtureB, $arm, $awards): void {
        $fixtureB();

        assert_same(13, GeneralHarvestBoard::queueN()['n'], 'the queue is at N = 13');

        $result = $arm();

        assert_true((bool) $result['armed'], 'the board is armed');
        assert_false((bool) $result['dry_run']);
        assert_same(13, (int) $result['n'], 'N0 is the queue length');
        assert_same(5, (int) $result['rows'], 'level 1 nodes 0–3 and level 2 node 0');
        assert_same(12000.0, (float) $result['excluded'], '4 × ₱2,000 + 1 × ₱4,000');
        assert_same(
            [1 => 4, 2 => 1, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0],
            $result['per_level'],
            'the per-level row counts'
        );

        $board = GeneralHarvestBoard::state();
        assert_true($board['armed']);
        assert_same(13, $board['baseline_n']);
        assert_same(13, $board['settled_n']);
        assert_not_null($board['armed_at']);
        assert_null($board['armed_by'], 'the CLI arms without a session');

        $rows = $awards();
        assert_count(5, $rows);

        $expected = [
            [0, 1, 4, 2000.0],
            [1, 1, 7, 2000.0],
            [2, 1, 10, 2000.0],
            [3, 1, 13, 2000.0],
            [0, 2, 13, 4000.0],
        ];

        foreach ($expected as $index => [$seq, $level, $threshold, $amount]) {
            assert_same($seq, (int) $rows[$index]['seq'], 'row ' . $index . ' seq');
            assert_same($level, (int) $rows[$index]['level'], 'row ' . $index . ' level');
            assert_same($threshold, (int) $rows[$index]['threshold_n'], 'row ' . $index . ' threshold');
            assert_same($amount, round((float) $rows[$index]['amount'], 2), 'row ' . $index . ' amount');
            assert_same(13, (int) $rows[$index]['n_at_award'], 'row ' . $index . ' n_at_award');
        }

        assert_same(
            12000.0,
            round((float) db()->query('SELECT SUM(amount) FROM general_harvest_awards')->fetchColumn(), 2),
            'the ledger totals the excluded amount'
        );
    },

    'P21C-T7 arm fixture C (121 accounts, N = 40) writes 18 rows and ₱50,000.00' => function () use ($fixtureC, $arm): void {
        $fixtureC();

        $result = $arm();

        assert_same(40, (int) $result['n']);
        assert_same(18, (int) $result['rows'], '13 + 4 + 1');
        assert_same(50000.0, (float) $result['excluded'], '₱26,000 + ₱16,000 + ₱8,000');
        assert_same([1 => 13, 2 => 4, 3 => 1], array_slice($result['per_level'], 0, 3, true), 'the per-level split');
        assert_same(
            50000.0,
            GeneralHarvestLevel::boardTotal(40),
            'the ledger equals the closed form at N0'
        );
        assert_same(18, (int) db()->query("SELECT COUNT(*) FROM general_harvest_awards WHERE status = 'baseline'")->fetchColumn());
    },

    'P21C-T10 every baseline row is status baseline, ledger NULL, at N0, with its closed-form threshold' => function () use ($fixtureB, $arm, $awards): void {
        $fixtureB();
        $arm();

        $amounts = GeneralHarvestLevel::amounts();

        foreach ($awards() as $row) {
            $seq   = (int) $row['seq'];
            $level = (int) $row['level'];

            assert_same('baseline', (string) $row['status'], 'a baseline row');
            assert_null($row['ledger_id'], 'no wallet reference');
            assert_same(13, (int) $row['n_at_award'], 'the arm instant length');
            assert_same(
                GeneralHarvestLevel::threshold($seq, $level),
                (int) $row['threshold_n'],
                'threshold of node ' . $seq . ' level ' . $level
            );
            assert_same(
                $amounts[$level],
                round((float) $row['amount'], 2),
                'the configured amount of level ' . $level
            );
            assert_true(GeneralHarvestAward::has($seq, $level), 'the (seq, level) anchor is taken');
        }

        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM general_harvest_awards WHERE status = 'credited'")->fetchColumn(), 'no credited row exists');
        assert_false(GeneralHarvestAward::has(4, 1), 'node 4 level 1 completes at N = 16, so it has no row');
        assert_true(GeneralHarvestAward::has(3, 1), 'node 3 level 1 is the last full block at 13');
    },

    'P21C-T11 per level the rows are exactly 0 … qFor, with no gap and no duplicate' => function () use ($fixtureC, $arm): void {
        $fixtureC();
        $arm();

        $minMax = GeneralHarvestAward::minMaxByLevel();

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $qFor = GeneralHarvestLevel::qFor($level, 40);

            assert_same($qFor + 1, $minMax[$level]['rows'], 'level ' . $level . ' row count');

            if ($qFor < 0) {
                assert_null($minMax[$level]['min'], 'level ' . $level . ' has no row');
                continue;
            }

            assert_same(0, $minMax[$level]['min'], 'level ' . $level . ' starts at node 0');
            assert_same($qFor, $minMax[$level]['max'], 'level ' . $level . ' ends at qFor');
            assert_same(
                $qFor + 1,
                (int) db()->query('SELECT COUNT(DISTINCT seq) FROM general_harvest_awards WHERE level = ' . $level)->fetchColumn(),
                'level ' . $level . ' has no duplicate node'
            );
        }
    },

    'P21C-T19 arm after the ladder was configured at depth 3 still records level 4 (D-23, Rule 6)' => function () use ($clean, $grow, $qualified, $queue, $seed, $arm, $fixtures): void {
        $clean();
        $tree = $grow(4, 'p21c_t19_' . bin2hex(random_bytes(3)));
        $queue($qualified($tree));
        $fixtures['depth'](3);
        $seed();

        assert_same(121, GeneralHarvestBoard::queueN()['n'], 'fixture h = 4 is at N = 121');
        assert_same(3, GeneralHarvestLevel::maxLevels(), 'the payable depth is 3');

        $result = $arm();

        assert_same(58, (int) $result['rows'], 'levels 1–4 are complete at 121');
        assert_same(176000.0, (float) $result['excluded'], 'the whole board total at 121');
        assert_same([1 => 40, 2 => 13, 3 => 4, 4 => 1], array_slice($result['per_level'], 0, 4, true));

        $level4 = db()->query('SELECT seq, amount, status FROM general_harvest_awards WHERE level = 4')->fetchAll();
        assert_count(1, $level4, 'level 4 is above the depth and still written');
        assert_same(0, (int) $level4[0]['seq']);
        assert_same('baseline', (string) $level4[0]['status']);
        assert_same(12000.0, round((float) $level4[0]['amount'], 2));

        $level5 = (int) db()->query('SELECT COUNT(*) FROM general_harvest_awards WHERE level >= 5')->fetchColumn();
        assert_same(0, $level5, 'no block is full above level 4 at 121');
    },

    /* ==========================================================
     *  Arm is a one-time act and refuses four other states
     *  (P21C-T2, T3, T4, T5, T12, T14)
     * ========================================================== */

    'P21C-T2 arm again is refused armed, writes nothing and leaves the board alone' => function () use ($fixtureB, $arm, $count, $fixtures): void {
        $fixtureB();
        $arm();

        $board = $fixtures['board']();
        $rows  = $count('general_harvest_awards');

        $error = assert_throws(static fn (): array => $arm(), GeneralHarvestException::class);

        assert_same('armed', $error->reason);
        assert_contains('already armed', $error->getMessage());
        assert_contains('no --force', $error->getMessage());
        assert_same($rows, $count('general_harvest_awards'), 'the ledger is untouched');
        assert_same($board, $fixtures['board'](), 'the board is untouched');
    },

    'P21C-T3 arm with the stream switched on is refused enabled' => function () use ($fixtureB, $arm, $count, $fixtures): void {
        $fixtureB();
        $fixtures['switch'](true);

        $error = assert_throws(static fn (): array => $arm(), GeneralHarvestException::class);

        assert_same('enabled', $error->reason);
        assert_contains('switch the stream off before arming', $error->getMessage());
        assert_same(0, $count('general_harvest_awards'), 'nothing was written');
        assert_same('NULL|NULL|NULL|0', $fixtures['board'](), 'the board is still unarmed');
    },

    'P21C-T4 arm with an unseeded queue is refused unseeded' => function () use ($clean, $grow, $qualified, $queue, $arm, $count, $fixtures): void {
        $clean();
        $tree = $grow(2, 'p21c_t4_' . bin2hex(random_bytes(3)));
        $queue($qualified($tree));

        $error = assert_throws(static fn (): array => $arm(), GeneralHarvestException::class);
        assert_same('unseeded', $error->reason);
        assert_contains('not live', $error->getMessage());
        assert_contains('harvest_queue_seed', $error->getMessage());

        setting_set('team_harvest_ledger_since', 'not-a-date');
        setting_cache_clear();

        $error = assert_throws(static fn (): array => $arm(), GeneralHarvestException::class);
        assert_same('unseeded', $error->reason);
        assert_contains('not a valid datetime', $error->getMessage());

        assert_same(0, $count('general_harvest_awards'), 'nothing was written');
        assert_same('NULL|NULL|NULL|0', $fixtures['board'](), 'the board is still unarmed');
    },

    'P21C-T5 arm with an unpriced level is refused invalid_ladder and names the level' => function () use ($fixtureB, $arm, $count, $fixtures): void {
        $fixtureB();
        $fixtures['depth'](3);
        $fixtures['ladder']([9 => 0.0]);

        $error = assert_throws(static fn (): array => $arm(), GeneralHarvestException::class);

        assert_same('invalid_ladder', $error->reason);
        assert_contains('Level 9', $error->getMessage(), 'the level above the depth is named');
        assert_contains('D-23', $error->getMessage());
        assert_same(0, $count('general_harvest_awards'), 'nothing was written');
        assert_same('NULL|NULL|NULL|0', $fixtures['board'](), 'the board is still unarmed');

        /* A level inside the depth fails the same way, naming level 1. */
        $fixtures['ladder']([1 => 0.0, 9 => 2500000.0]);

        $error = assert_throws(static fn (): array => $arm(), GeneralHarvestException::class);
        assert_same('invalid_ladder', $error->reason);
        assert_contains('Level 1', $error->getMessage());
    },

    'P21C-T12 arm on a queue with a hole is refused not_gapless, naming N and MAX(id)' => function () use ($fixtureB, $arm, $count, $fixtures): void {
        $fixtureB();

        db()->exec('DELETE FROM harvest_queue WHERE id = 5');

        $error = assert_throws(static fn (): array => $arm(), GeneralHarvestException::class);

        assert_same('not_gapless', $error->reason);
        assert_contains('COUNT(*) = 12', $error->getMessage());
        assert_contains('MAX(id) = 13', $error->getMessage());
        assert_same(0, $count('general_harvest_awards'), 'nothing was written');
        assert_same('NULL|NULL|NULL|0', $fixtures['board'](), 'the board is still unarmed');
    },

    'P21C-T14 two concurrent arm runs: the lock refuses the second and one baseline set exists' => function () use ($fixtureB, $arm, $count): void {
        $fixtureB();

        /* A second connection holds the arm's lock, exactly as a run that
         * started a second earlier would (§14.3). */
        $other = test_pdo(['db' => DB_NAME]);
        $held  = (int) $other->query("SELECT GET_LOCK('general_harvest_arm', 1)")->fetchColumn();
        assert_same(1, $held, 'the test holds the advisory lock');

        try {
            $error = assert_throws(static fn (): array => $arm(), GeneralHarvestException::class);

            assert_same('state', $error->reason);
            assert_contains('holds the general_harvest_arm lock', $error->getMessage());
            assert_same(0, $count('general_harvest_awards'), 'the refused run wrote nothing');
        } finally {
            $other->query("SELECT RELEASE_LOCK('general_harvest_arm')");
        }

        /* The winner then arms: the ledger holds exactly one set. */
        $result = $arm();

        assert_same(5, (int) $result['rows']);
        assert_same(5, $count('general_harvest_awards'));
        assert_same(5, (int) db()->query('SELECT COUNT(*) FROM general_harvest_awards WHERE status = \'baseline\'')->fetchColumn());
    },

    /* ==========================================================
     *  The dry run, the empty queue and determinism (T6, T8)
     * ========================================================== */

    'P21C-T6 arm on an empty queue writes no row and arms the board at N0 = 0' => function () use ($clean, $seed, $arm): void {
        $clean();
        $seed();

        $result = $arm();

        assert_true((bool) $result['armed']);
        assert_same(0, (int) $result['n']);
        assert_same(0, (int) $result['rows']);
        assert_same(0.0, (float) $result['excluded']);
        assert_same([], $result['preview']);

        $board = GeneralHarvestBoard::state();
        assert_same(0, $board['baseline_n']);
        assert_same(0, $board['settled_n']);
        assert_not_null($board['armed_at']);
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn());

        $excluded = (new GeneralHarvestService())->excluded();
        assert_true($excluded['armed']);
        assert_same(0, $excluded['rows']);
        assert_same(0.0, $excluded['amount']);
        assert_same(0.0, $excluded['board_total']);
    },

    'P21C-T8 two dry runs on fixture C are identical and write nothing' => function () use ($fixtureC, $dryRun, $count, $fixtures): void {
        $fixtureC();

        $board = $fixtures['board']();
        $first  = $dryRun();
        $second = $dryRun();

        assert_false((bool) $first['armed'], 'a dry run arms nothing');
        assert_true((bool) $first['dry_run']);
        assert_same(40, (int) $first['n'], 'N0 = 40');
        assert_same(18, (int) $first['rows'], '18 rows');
        assert_same(50000.0, (float) $first['excluded'], '₱50,000.00');

        foreach (['n', 'rows', 'excluded', 'per_level', 'preview'] as $key) {
            assert_same($first[$key], $second[$key], 'the dry run is deterministic: ' . $key);
        }

        assert_count(18, $first['preview'], 'the first 20 rows are all 18 of them');

        $preview = $first['preview'];
        assert_same(0, (int) $preview[0]['seq']);
        assert_same(1, (int) $preview[0]['level']);
        assert_same(4, (int) $preview[0]['threshold_n']);
        assert_same(2000.0, (float) $preview[0]['amount']);
        assert_same(0, (int) $preview[17]['seq'], 'the last preview row is node 0');
        assert_same(3, (int) $preview[17]['level']);
        assert_same(40, (int) $preview[17]['threshold_n']);
        assert_same(8000.0, (float) $preview[17]['amount']);

        assert_same(0, $count('general_harvest_awards'), 'a dry run writes no row');
        assert_same($board, $fixtures['board'](), 'a dry run leaves the board alone');
    },

    /* ==========================================================
     *  Nothing pays (T9) and the audit row (T13)
     * ========================================================== */

    'P21C-T9 the whole fixture moves no money: no credited row, no wallet movement' => function () use ($fixtureC, $arm, $count, $fixtures): void {
        $fixtureC();

        $walletBefore = $fixtures['walletSums']();

        $arm();

        /* A live registration after the arm still queues and pays nothing:
         * 21C ships no settlement, and the switch is off. */
        $sponsor = test_user('p21c_t9_sponsor', 'member');

        for ($i = 1; $i <= 3; $i++) {
            $username = 'p21c_t9_direct_' . $i . '_' . bin2hex(random_bytes(3));

            $created = (new RegistrationService())->register([
                'mode'             => 'pending',
                'username'         => $username,
                'email'            => $username . '@test.local',
                'full_name'        => 'Harvest Direct ' . $i,
                'phone'            => '',
                'password'         => 'Member@1234',
                'password_confirm' => 'Member@1234',
                'sponsor'          => (string) $sponsor['username'],
                'package_id'       => '',
                'reg_code'         => '',
                'terms'            => true,
                'binary_position'  => 'right',
            ], null);

            assert_true((int) ($created['user_id'] ?? 0) > 0, 'registration ' . $i . ' succeeded');
        }

        assert_same(41, GeneralHarvestBoard::queueN()['n'], 'the sponsor is queued after the arm');
        assert_same(40, GeneralHarvestBoard::state()['settled_n'], 'the settlement has not moved');
        assert_same(1, GeneralHarvestBoard::backlog()['positions'], 'one position is waiting');

        assert_same(0, $count('general_harvest_awards', "status = 'credited'"), 'not one credited row');
        assert_same(0, $count('ewallet_ledger', "ref_type = 'general_harvest'"), 'not one wallet movement');
        assert_same($walletBefore, $fixtures['walletSums'](), 'every wallet balance is unchanged');
        assert_same(18, $count('general_harvest_awards'), 'the baseline rows are all there is');
    },

    'P21C-T13 the audit row carries N0, the row count, the excluded amount and the actor' => function () use ($fixtureB, $arm): void {
        $fixtureB();

        $admin  = test_user('p21c_t13_admin', 'admin');
        $result = $arm($admin);

        assert_same((int) $admin['id'], (int) GeneralHarvestBoard::state()['armed_by'], 'the actor is on the board');

        $entry = db()->query(
            "SELECT * FROM audit_log WHERE action = 'general_harvest.arm' ORDER BY id DESC LIMIT 1"
        )->fetch();

        assert_true($entry !== false, 'the arm is audited');
        assert_same((int) $admin['id'], (int) $entry['actor_id']);
        assert_same('admin', (string) $entry['actor_role']);
        assert_same('general_harvest_board', (string) $entry['subject_type']);
        assert_same(1, (int) $entry['subject_id']);

        $meta = AuditLog::meta((string) $entry['meta_json']);

        assert_same(13, (int) $meta['n']);
        assert_same(5, (int) $meta['rows']);
        assert_same('12000.00', (string) $meta['excluded']);
        assert_same((int) $admin['id'], (int) $meta['armed_by']);
        assert_same(5, array_sum(array_map('intval', (array) $meta['per_level'])), 'the per-level counts add up to the rows');
    },

    /* ==========================================================
     *  The board's schema and the armed state (T15, T18)
     * ========================================================== */

    'P21C-T15 the board is a singleton whose baseline_n and armed_at move together' => function () use ($fixtureB, $arm): void {
        $fixtureB();

        $second = 'INSERT INTO general_harvest_board (id, baseline_n, armed_at, settled_n)
                   VALUES (2, NULL, NULL, 0)';
        assert_throws(static fn (): int => (int) db()->exec($second), PDOException::class, 'a second board row is refused');

        $half = "UPDATE general_harvest_board SET baseline_n = 13 WHERE id = 1";
        assert_throws(static fn (): int => (int) db()->exec($half), PDOException::class, 'baseline_n without armed_at is refused');

        $behind = "UPDATE general_harvest_board
                      SET baseline_n = 13, armed_at = '2026-11-01 00:00:00', settled_n = 12
                    WHERE id = 1";
        assert_throws(static fn (): int => (int) db()->exec($behind), PDOException::class, 'settled_n below baseline_n is refused');

        $arm();

        $board = db()->query('SELECT baseline_n, armed_at FROM general_harvest_board WHERE id = 1')->fetch();
        assert_not_null($board['baseline_n']);
        assert_not_null($board['armed_at'], 'the pair is set together');
        assert_same(1, (int) db()->query('SELECT COUNT(*) FROM general_harvest_board')->fetchColumn(), 'still a singleton');
    },

    'P21C-T18 after arming, state() reads N0 and the backlog, and a ladder change is refused' => function () use ($fixtureB, $arm, $fixtures): void {
        $fixtureB();
        $arm();

        $service = new GeneralHarvestService();
        $state   = $service->state();

        assert_true((bool) $state['armed']);
        assert_false((bool) $state['enabled'], 'the phase never turns the switch on');
        assert_same(13, (int) $state['baseline_n']);
        assert_same(13, (int) $state['settled_n']);
        assert_same(0, (int) $state['backlog']);
        assert_same(10, (int) $state['max_levels']);
        assert_same('armed_off', (string) $state['state']);

        /* Three positions arrive after the arm: the backlog is N − N0. */
        for ($i = 1; $i <= 3; $i++) {
            $fixtures['append']($fixtures['account']('p21c_t18_extra_' . $i . '_' . bin2hex(random_bytes(3))));
        }

        $state = $service->state();

        assert_same(16, (int) $state['n']);
        assert_same(3, (int) $state['backlog']);
        assert_same(
            round(GeneralHarvestLevel::boardTotal(16) - GeneralHarvestLevel::boardTotal(13), 2),
            (float) $state['backlog_amount'],
            'the backlog is the closed-form difference'
        );
        assert_same(13, (int) $state['baseline_n'], 'the baseline never moves');
        assert_same(13, (int) $state['settled_n'], 'and neither does the settled length, yet');

        /* D-23: the ten amounts are frozen from this instant. */
        $stored = GeneralHarvestLevel::amounts();
        $edited = $stored;
        $edited[1] = 2500.0;

        $error = assert_throws(static fn (): array => GeneralHarvestLevel::save($edited), InvalidArgumentException::class);

        assert_contains('frozen', $error->getMessage());
        assert_same($stored, GeneralHarvestLevel::amounts(), 'the ladder is unchanged');
        assert_same($state['baseline_n'], (new GeneralHarvestService())->baseline());
        assert_true((new GeneralHarvestService())->armed());
    },

    /* ==========================================================
     *  The CLI: a fresh install, the JSON document, the real run,
     *  and reset → re-arm (T16, T20, T17)
     * ========================================================== */

    'P21C-T16 a fresh install has no queue, so the CLI arm refuses unseeded and writes nothing' => function () use ($scratch, $cli, $scratchBoard): void {
        [$pdo, $database] = $scratch();

        assert_same(
            'NULL|NULL|NULL|0',
            $scratchBoard($pdo),
            'the migrate seed ships an unarmed board'
        );

        $run = $cli($database, ['--json']);

        assert_same(1, $run['exit'], 'a refused arm exits 1');
        assert_same('unseeded', (string) ($run['json']['reason'] ?? ''), $run['output']);
        assert_same('NULL|NULL|NULL|0', $scratchBoard($pdo), 'the board is untouched');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn());

        $dry = $cli($database, ['--dry-run', '--json']);
        assert_same(1, $dry['exit'], 'the dry run refuses the same way');
    },

    'P21C-T20 the CLI prints the dry run, then --json emits the six documented keys exactly' => function () use ($scratch, $cli, $scratchMembers, $scratchSeed, $scratchBoard): void {
        [$pdo, $database] = $scratch();
        $scratchMembers($pdo, 40, 'p21c_t20_', 40);
        $scratchSeed($pdo);

        /* The human dry run: N0, the row count, the excluded amount, the
         * first positions and the per-level amounts — and no write. */
        $dry = $cli($database, ['--dry-run']);

        assert_same(0, $dry['exit'], $dry['output']);
        assert_contains('N0 = 40', $dry['output']);
        assert_contains('18 row(s)', $dry['output']);
        assert_contains('50,000.00', $dry['output']);
        assert_contains('The first 18 baseline position(s)', $dry['output']);
        assert_contains('@p21c_t20_1', $dry['output']);
        assert_contains('level 4', $dry['output']);
        assert_contains('Nothing was written', $dry['output']);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn(), 'the dry run wrote nothing');
        assert_same('NULL|NULL|NULL|0', $scratchBoard($pdo), 'the dry run left the board alone');

        /* The documented JSON document, in order, and nothing else. */
        $real = $cli($database, ['--json']);

        assert_same(0, $real['exit'], $real['output']);
        assert_not_null($real['json'], 'the job prints one JSON document');

        assert_same(
            ['armed', 'n', 'rows', 'excluded', 'per_level', 'dry_run'],
            array_keys($real['json']),
            'the six documented keys only'
        );
        assert_true((bool) $real['json']['armed']);
        assert_same(40, (int) $real['json']['n']);
        assert_same(18, (int) $real['json']['rows']);
        assert_same(50000.0, (float) $real['json']['excluded']);
        assert_same(13, (int) $real['json']['per_level']['1']);
        assert_same(4, (int) $real['json']['per_level']['2']);
        assert_same(1, (int) $real['json']['per_level']['3']);
        assert_false((bool) $real['json']['dry_run']);

        assert_same(18, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn());
        assert_same(18, (int) $pdo->query("SELECT COUNT(*) FROM general_harvest_awards WHERE status = 'baseline'")->fetchColumn());
        assert_contains('40', $scratchBoard($pdo), 'the board is armed at 40');

        /* A second real run: "already armed", exit 1, nothing written. */
        $again = $cli($database, ['--json']);

        assert_same(1, $again['exit'], $again['output']);
        assert_same('armed', (string) ($again['json']['reason'] ?? ''));
        assert_same(18, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn(), 'still one baseline set');
    },

    'P21C-T17 a business reset unarms the board and the next arm uses the new N' => function () use ($appRoot, $scratch, $cli, $scratchMembers, $scratchSeed, $scratchBoard): void {
        [$pdo, $database] = $scratch([
            'database/seeds/001_settings.sql',
            'database/seeds/002_accounts.sql',
            'database/seeds/003_products.sql',
            'database/seeds/004_packages.sql',
            'database/seeds/005_demo_tree.sql',
        ]);

        $scratchMembers($pdo, 13, 'p21c_t17_a_', 13);
        $scratchSeed($pdo);

        $first = $cli($database, ['--json']);

        assert_same(0, $first['exit'], $first['output']);
        assert_same(5, (int) $first['json']['rows']);
        assert_same(13, (int) $first['json']['n']);

        $report = (new DatabaseResetService(
            $pdo,
            $database,
            ['confirm' => $database, 'tool' => 'cli']
        ))->run();

        assert_true($report['ok'], 'the reset verifies');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn(), 'the ledger is empty');
        assert_same('NULL|NULL|NULL|0', $scratchBoard($pdo), 'the board is unarmed again');
        assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn(), 'the ladder survives');

        /* The reset restores the shipped settings, so Phase 20's queue is
         * unseeded again: the arm refuses until the new network is seeded,
         * which is exactly the go-live order (Rule 4). */
        assert_same(
            '',
            (string) $pdo->query("SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since'")->fetchColumn(),
            'the reset returns the seed marker to the shipped default'
        );
        assert_same(1, $cli($database, ['--json'])['exit'], 'an unseeded new network cannot be armed');

        /* The new network: a different queue length, a fresh baseline. */
        $scratchMembers($pdo, 40, 'p21c_t17_b_', 40);
        $scratchSeed($pdo);

        $second = $cli($database, ['--json']);

        assert_same(0, $second['exit'], $second['output']);
        assert_same(40, (int) $second['json']['n'], 'the second arm uses the new N');
        assert_same(18, (int) $second['json']['rows']);
        assert_same(50000.0, (float) $second['json']['excluded']);
        assert_same(18, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn());
    },

    /* ==========================================================
     *  The phase's final proof (T21)
     * ========================================================== */

    'P21C-T21 the phase ships the whole manifest and moves no money' => function () use ($appRoot, $fixtureC, $arm, $fixtures): void {
        foreach ([
            '/app/Models/GeneralHarvestAward.php',
            '/app/Services/GeneralHarvestService.php',
            '/app/Services/GeneralHarvestException.php',
            '/bin/general_harvest_arm.php',
            '/bin/general_harvest_arm.cmd',
        ] as $file) {
            assert_true(is_file($appRoot . $file), $file . ' ships with 21C');
        }

        assert_true(is_file($appRoot . '/bin/general_harvest_catchup.php'), 'the catch-up job is 21D');

        $fixtureC();

        $walletBefore = $fixtures['walletSums']();
        $arm();

        $service  = new GeneralHarvestService();
        $excluded = $service->excluded();

        assert_same(18, $excluded['rows']);
        assert_same(50000.0, $excluded['amount']);
        assert_same(50000.0, $excluded['board_total'], 'the ledger equals the closed form');
        assert_same(13, $excluded['per_level'][1]['rows']);
        assert_same(26000.0, $excluded['per_level'][1]['amount']);

        assert_same(['credited' => 0, 'baseline' => 18], GeneralHarvestAward::countByStatus());
        assert_same(0, $fixtures['count']('ewallet_ledger', "ref_type = 'general_harvest'"));
        assert_same($walletBefore, $fixtures['walletSums'](), 'the whole phase moves no money');
        assert_same(40, (int) $service->baseline(), 'the board is armed at N0');
    },
];
