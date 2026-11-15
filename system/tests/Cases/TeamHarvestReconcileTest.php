<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestReconcileTest — Phase 20D, the proofs behind the money
 *  (plan §14.13, §13.8)
 *
 *  A payout a finance team cannot verify is not shippable, so the
 *  reconciler ships with the engine, not after it:
 *
 *      T27  a paid network reconciles: awards = wallet = 25,000.00,
 *           zero counter drift
 *      T28  a hand-deleted wallet movement is reported with its award
 *           and the CLI exits non-zero
 *      T29  a hand-edited counter is reported as drift and fails the
 *           verdict
 *      T30  `bin/wallet_reconcile.php` exits 0 on a clean build and
 *           prints its Team Harvest section
 *      T31  the business reset's integrity pack runs the Team Harvest
 *           reconciliation and passes with everything emptied
 *
 *  The CLI cases run against a scratch schema because a subprocess
 *  cannot see the harness's transaction; the in-process cases run on the
 *  test schema and are rolled back.
 *
 *  Run this group alone with:  php tests/run.php TeamHarvestReconcile
 * ============================================================== */

$fixtures = require __DIR__ . '/../fixtures/harvest_money.php';

$clean  = $fixtures['clean'];
$arm    = $fixtures['arm'];
$grow   = $fixtures['grow'];
$count  = $fixtures['count'];

$appRoot = dirname(__DIR__, 2);

/** Run the real reconciler against one database. */
$runReconcile = static function (string $database, array $arguments = []) use ($appRoot): array {
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($appRoot . '/bin/wallet_reconcile.php');

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

return [

    /* ==========================================================
     *  A clean network (T27, T30)
     * ========================================================== */

    'P20D-T27 reconcile proves fixture A: 25,000 paid, zero drift' => function () use ($clean, $arm, $grow): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $grow(3, 't27', $service);

        $report = $service->reconcile();

        assert_same(true, $report['ok'], 'a clean network reconciles: ' . json_encode($report['mismatches']));
        assert_same([], $report['mismatches']);
        assert_same(18, $report['awards']);
        assert_same(18, $report['credited_rows']);
        assert_same(25000.0, $report['credited_total']);
        assert_same(18, $report['ledger_rows']);
        assert_same(25000.0, $report['ledger_total']);
        assert_same(0, $report['baseline_rows']);
        assert_same(40, $report['queued']);
        assert_same(0, $report['drift'], 'every counter equals a from-scratch recount');
        assert_same(0, $report['violations']);
    },

    'P20D-T30 wallet_reconcile exits 0 and prints the Team Harvest section' => function () use ($runReconcile): void {
        /* The harness's own transaction is invisible to the subprocess,
         * so this runs against the clean committed test schema. */
        $result = $runReconcile('ecowealth_sneat_test');

        assert_same(0, $result['exit'], 'a clean build reconciles: ' . $result['output']);
        assert_contains('Team Harvest', $result['output'], 'the seventh section exists');
        assert_contains('credited awards:   0', $result['output']);
        assert_contains('counter drift:     0', $result['output']);
        assert_contains('every credited award has its wallet movement', $result['output']);

        $json = $runReconcile('ecowealth_sneat_test', ['--json']);

        assert_same(0, $json['exit']);
        assert_same(true, (bool) $json['json']['team_harvest']['ok']);
        assert_same(0, (int) $json['json']['team_harvest']['credited_rows']);
        assert_same(0.0, (float) $json['json']['team_harvest']['credited_total']);
        assert_same(0, (int) $json['json']['team_harvest']['queued']);
        assert_same([], $json['json']['team_harvest']['mismatches']);
    },

    /* ==========================================================
     *  What the reconciler must catch (T28, T29)
     * ========================================================== */

    'P20D-T28 a deleted wallet movement is reported with its award' => function () use ($clean, $arm, $grow, $count, $runReconcile, $appRoot): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't28', $service);
        $root    = $tree['root'];

        assert_same(true, $service->reconcile()['ok']);

        /* Remove one movement outside every service: exactly what the
         * reconciler exists for. */
        $ledgerId = (int) db()->query(
            "SELECT ledger_id FROM team_harvest_awards WHERE user_id = {$root} ORDER BY level LIMIT 1"
        )->fetchColumn();
        $awardId = (int) db()->query(
            "SELECT id FROM team_harvest_awards WHERE user_id = {$root} ORDER BY level LIMIT 1"
        )->fetchColumn();

        db()->prepare('DELETE FROM ewallet_ledger WHERE id = ?')->execute([$ledgerId]);

        $report = $service->reconcile();

        assert_same(false, $report['ok']);
        $kinds = array_column($report['mismatches'], 'kind');

        assert_true(in_array('missing_ledger', $kinds, true), 'the award with no movement is named');
        assert_true(in_array('total', $kinds, true), 'and the totals no longer agree');

        $missing = array_values(array_filter(
            $report['mismatches'],
            static fn (array $row): bool => (string) $row['kind'] === 'missing_ledger'
        ));

        assert_same($awardId, (int) $missing[0]['award'], 'the report names the award id');
        assert_same(1000.0, (float) $missing[0]['expected']);

        /* The same defect makes the real command exit non-zero. The
         * harness rolls its delete back, so the CLI case uses a scratch
         * schema with one committed orphan movement. */
        [$pdo, $database] = test_scratch_database('tool_a');
        test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');
        test_run_sql_file($pdo, $appRoot . '/database/seeds/002_accounts.sql');

        $adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        assert_true($adminId > 0);

        /* A `team_harvest` movement whose award does not exist; the wallet
         * buckets are moved with it so only the Team Harvest check fails. */
        $pdo->beginTransaction();
        $pdo->prepare(
            'UPDATE users SET ewallet_balance = ewallet_balance + 500, withdrawable_balance = withdrawable_balance + 500
              WHERE id = ?'
        )->execute([$adminId]);
        $pdo->prepare(
            "INSERT INTO ewallet_ledger
               (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
             SELECT id, 'credit', 500.00, ewallet_balance, withdrawable_balance, 'team_harvest', 999999, 'orphan'
               FROM users WHERE id = ?"
        )->execute([$adminId]);
        $pdo->commit();

        $result = $runReconcile($database, ['--json']);

        assert_same(1, $result['exit'], 'a broken ledger fails the build: ' . $result['output']);
        assert_not_null($result['json']);
        assert_same(false, (bool) $result['json']['team_harvest']['ok']);

        $orphans = array_values(array_filter(
            $result['json']['team_harvest']['mismatches'],
            static fn (array $row): bool => (string) $row['kind'] === 'orphan_ledger'
        ));

        assert_count(1, $orphans, 'the orphan movement is reported');
        assert_same(500.0, (float) $orphans[0]['actual']);
    },

    'P20D-T29 a hand-edited counter is reported and fails the verdict' => function () use ($clean, $arm, $grow): void {
        $clean();
        $arm();
        $service = new TeamHarvestService();
        $tree    = $grow(2, 't29', $service);
        $root    = $tree['root'];

        db()->prepare('UPDATE harvest_queue SET teammates = teammates + 7 WHERE user_id = ?')->execute([$root]);

        $report = $service->reconcile();

        assert_same(false, $report['ok']);
        assert_same(1, $report['drift'], 'one counter disagrees with the recount');

        $drift = array_values(array_filter(
            $report['mismatches'],
            static fn (array $row): bool => (string) $row['kind'] === 'counter_drift'
        ));

        assert_count(1, $drift);
        assert_same($root, (int) $drift[0]['user_id']);
        assert_same(12, (int) $drift[0]['expected'], 'the recount is the authority');
        assert_same(19, (int) $drift[0]['actual'], 'the stored counter is what it was edited to');

        /* Restoring the counter (the rebuild's job) makes it clean again. */
        db()->prepare('UPDATE harvest_queue SET teammates = teammates - 7 WHERE user_id = ?')->execute([$root]);

        assert_same(true, (new TeamHarvestService())->reconcile()['ok']);
    },

    /* ==========================================================
     *  The reset's integrity pack (T31)
     * ========================================================== */

    'P20D-T31 the business reset runs and passes the Team Harvest reconciliation' => function () use ($appRoot): void {
        [$pdo, $database] = test_scratch_database('tool_a');

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

        $service = new DatabaseResetService($pdo, $database, ['confirm' => $database, 'tool' => 'cli']);
        $report  = $service->run();

        assert_true((bool) $report['ok'], 'the reset verifies: ' . json_encode($report['verification']['checks']));

        $checks = [];

        foreach ($report['verification']['checks'] as $check) {
            $checks[(string) $check['key']] = $check;
        }

        assert_true(isset($checks['team_harvest']), 'the integrity pack carries a Team Harvest check');
        assert_true((bool) $checks['team_harvest']['ok'], 'and it passes');
        assert_contains('TeamHarvestService::reconcile clean', (string) $checks['team_harvest']['detail']);

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());
        assert_same(
            '',
            (string) $pdo->query("SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since'")->fetchColumn(),
            'the baseline is cleared with the queue'
        );
        assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn(), 'the ladder survives');
    },
];
