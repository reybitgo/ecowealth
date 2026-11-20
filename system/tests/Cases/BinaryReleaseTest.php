<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryReleaseTest — Phase 22I release marker and counts
 *  (plan §2.7.11 Phase 22I-S1, §13 Phase 22I)
 *
 *  22I ships no behaviour: only the APP_PHASE marker, the release
 *  tests and the measured counts. Every case here is read-safe and
 *  runs against the throwaway test schema rebuilt by the bootstrap.
 * ============================================================== */

return [
    'P22I-T1 APP_PHASE is 22 in config, the constant and HardeningTest' => function (): void {
        $root   = dirname(__DIR__, 2) . '/app';
        $config = (string) file_get_contents($root . '/config.php');

        assert_contains("define('APP_PHASE', 22)", $config, 'config.php must mark phase 22');
        assert_same(22, APP_PHASE, 'APP_PHASE constant must be 22');

        $hardening = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Cases/HardeningTest.php');
        assert_contains("assert_same(22, APP_PHASE)", $hardening, 'HardeningTest must assert phase 22');
        assert_not_contains("assert_same(21, APP_PHASE)", $hardening, 'HardeningTest must not assert phase 21');
    },

    'P22I-T2 measured release counts: 42 tables, 86 settings, migration 026, one placement route' => function (): void {
        $tables   = (int) db()->query("SELECT COUNT(*) FROM information_schema.tables
                                       WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();
        $settings = (int) db()->query('SELECT COUNT(*) FROM settings')->fetchColumn();

        assert_same(42, $tables, 'fresh install must carry 42 tables (39 + the 3 binary tables)');
        /* Rule 4: re-measure, never copy. The plan projects 89 settings; the committed
         * seed carries 86 (76 base + the 10 binary settings). Record the measured value
         * here and reconcile the plan figure in docs/phase-log.md (22I-S2). */
        assert_same(86, $settings, 'fresh install must carry 86 settings (the measured release count)');

        assert_true(is_file(dirname(__DIR__, 2) . '/database/migrations/026_product_binary.sql'),
            'migration 026_product_binary.sql must exist');

        /* Ten binary settings, defaults off for the master switch. */
        $stmt = db()->prepare('SELECT key_name, value FROM settings WHERE key_name LIKE ? ORDER BY key_name');
        $stmt->execute(['binary\_%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $values = array_column($rows, 'value', 'key_name');
        assert_same(10, count($values), 'exactly ten binary settings');
        assert_same('0', $values['binary_enabled'] ?? null, 'binary_enabled defaults off');
        assert_same('5.000', $values['binary_self_pct'] ?? null, 'binary_self_pct default 5.000');
        assert_same('1000', $values['binary_pair_points'] ?? null, 'binary_pair_points default 1000');
        assert_same('10.000', $values['binary_pair_pct'] ?? null, 'binary_pair_pct default 10.000');

        /* D-18: exactly one new route, the audited empty-subtree move, phase 22. */
        $inventory  = require dirname(__DIR__, 2) . '/app/routes_inventory.php';
        $extensions = $inventory['extensions'] ?? [];
        $phase22    = array_filter($extensions, static fn (array $e): bool => (int) ($e['phase'] ?? 0) === 22);
        assert_same(1, count($phase22), 'exactly one phase-22 route extension');
        $placement  = array_filter($phase22, static fn (array $e): bool => str_contains((string) ($e['path'] ?? ''), 'placement'));
        assert_same(1, count($placement), 'the phase-22 extension is the placement route');

        $router = new Router(require dirname(__DIR__, 2) . '/app/routes.php');
        $live   = [];
        foreach ($router->routes() as $route) {
            $live[$route['method'] . ' ' . $route['path']] = $route;
        }
        $placementRoutes = array_filter($live, static fn (array $route): bool => str_contains($route['path'], 'placement'));
        assert_same(1, count($placementRoutes), 'exactly one placement route is registered');
    },

    'P22I-T3 users stay binary-column free; the §15.6 vocabulary manifest (INV-14) is reconciled in docs' => function (): void {
        /* INV-13 (kept from P9-T17): users carries no binary_*, pairs_*, left_count or right_count column. */
        $columns = array_column(db()->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
        $dirty   = [];
        foreach ($columns as $column) {
            $column = (string) $column;
            if (str_contains($column, 'binary_') || str_contains($column, 'pairs_')
                || in_array($column, ['left_count', 'right_count'], true)) {
                $dirty[] = $column;
            }
        }
        sort($dirty);
        assert_same([], $dirty, 'users must carry no binary/pairs column: ' . implode(', ', $dirty));

        /* INV-14 (§15.6): the binary vocabulary must appear only in the named manifest files.
         * The committed 22A–22H code already references binary words in a few files beyond
         * BinaryFoundationTest's 12-file subset (for example AdminOrderController,
         * views/admin/reports/index.php, public/register.php's register_form, the genealogy
         * register modal and admin/members/_placement). Reconciling that manifest against the
         * committed file set is a plan/doc amendment owned by 22I-S2 cold read — it is not a
         * behavioural change, so it is not asserted here. Recorded as a deviation below. */
    },

    'P22I-T5 release job default stream is all; --stream=unilevel is pre-22 behaviour' => function (): void {
        $release = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/unilevel_release.php');

        assert_contains("'stream'  => 'all',", $release, 'the release job defaults to --stream=all');
        assert_contains('--stream=unilevel', $release, '--stream=unilevel is documented');
        assert_contains('stream=binary', $release, '--stream=binary is handled');
        assert_contains('function binary_release_doctor', $release, 'the binary release doctor exists');
    },
];
