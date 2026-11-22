<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageBinaryReleaseTest — Phase 23G release marker and counts
 *  (plan §2.7.8 Phase 23G-S1, §13 Phase 23G)
 *
 *  23G ships no behaviour: only the APP_PHASE marker, the release
 *  tests and the measured counts. Every case here is read-safe and
 *  runs against the throwaway test schema rebuilt by the bootstrap.
 * ============================================================== */

return [
    'P23G-T1 APP_PHASE is 23 in config, the suite header and HardeningTest' => function (): void {
        $root   = dirname(__DIR__, 2) . '/app';
        $config = (string) file_get_contents($root . '/config.php');

        assert_contains("define('APP_PHASE', 23)", $config, 'config.php must mark phase 23');
        assert_same(23, APP_PHASE, 'APP_PHASE constant must be 23');

        $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/run.php');
        assert_contains("' · phase '", $runner, 'the suite header must print the phase');

        $hardening = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Cases/HardeningTest.php');
        assert_contains("assert_same(23, APP_PHASE)", $hardening, 'HardeningTest must assert phase 23');
        assert_not_contains("assert_same(22, APP_PHASE)", $hardening, 'HardeningTest must not assert phase 22');
        assert_not_contains("assert_same(21, APP_PHASE)", $hardening, 'HardeningTest must not assert phase 21');
    },

    'P23G-T2 measured release counts: 42 tables, 89 seed settings, migration 027, source columns, no phase-23 route' => function (): void {
        $tables   = (int) db()->query("SELECT COUNT(*) FROM information_schema.tables
                                       WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();
        $settings = (int) db()->query('SELECT COUNT(*) FROM settings')->fetchColumn();

        assert_same(42, $tables, 'fresh install must carry 42 tables (no new table in 23G)');
        /* Rule 4: re-measure, never copy. db() queries DB_NAME which is built by
         * test_reset_app_database() from schema.sql + seeds only (no install
         * footer), so it carries 89 settings (86 base + the 3 package-binary
         * settings added by 23A). The +3 install-time footer settings
         * (install_version, install_channel, install_completed_at) bring a
         * full install to the plan's 92. */
        assert_same(89, $settings, 'DB_NAME carries 89 settings (seed only, no install footer)');

        assert_true(is_file(dirname(__DIR__, 2) . '/database/migrations/027_package_binary.sql'),
            'migration 027_package_binary.sql must exist');

        /* The three package-binary settings, defaults off for the master switch. */
        $stmt = db()->prepare('SELECT key_name, value FROM settings WHERE key_name LIKE ? ORDER BY key_name');
        $stmt->execute(['package\_binary\_%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $values = array_column($rows, 'value', 'key_name');
        assert_same(3, count($values), 'exactly three package_binary settings');
        assert_same('0', $values['package_binary_enabled'] ?? null, 'package_binary_enabled defaults off');
        assert_same('1', $values['package_binary_requires_package'] ?? null, 'package_binary_requires_package defaults on');
        assert_true(is_string($values['package_binary_ledger_since'] ?? null) && $values['package_binary_ledger_since'] !== '', 'package_binary_ledger_since must be set');

        /* Source columns on both ledgers (23A amendment, INV-P1). */
        $awardCols = array_column(db()->query('SHOW COLUMNS FROM binary_awards')->fetchAll(), 'Field');
        assert_true(in_array('source', $awardCols, true), 'binary_awards.source must exist');
        assert_true(in_array('activation_id', $awardCols, true), 'binary_awards.activation_id must exist');
        $volumeCols = array_column(db()->query('SHOW COLUMNS FROM binary_volume')->fetchAll(), 'Field');
        assert_true(in_array('source', $volumeCols, true), 'binary_volume.source must exist');
        assert_true(in_array('activation_id', $volumeCols, true), 'binary_volume.activation_id must exist');

        /* D-14: no new route in Phase 23. The extension inventory gains no
         * phase-23 entry; the phase-22 placement route remains the only
         * phase-≥22 extension. */
        $inventory  = require dirname(__DIR__, 2) . '/app/routes_inventory.php';
        $extensions = $inventory['extensions'] ?? [];
        $late       = array_filter($extensions, static fn (array $e): bool => (int) ($e['phase'] ?? 0) >= 22);
        assert_same(1, count($late), 'exactly one phase-≥22 route extension (the 22 placement route)');
        $phase23 = array_filter($extensions, static fn (array $e): bool => (int) ($e['phase'] ?? 0) === 23);
        assert_same(0, count($phase23), 'no phase-23 route extension (D-14: no new route)');

        /* The seven phase deliverables are present as files. */
        $appRoot = dirname(__DIR__, 2);
        $files = [
            'database/migrations/027_package_binary.sql',   // 23A foundation
            'app/Services/PackageBinaryService.php',        // 23C money engine
            'bin/package_binary_reverse.php',               // 23C reversal CLI
            'bin/binary_audit.php',                         // 23F operations audit
            'app/Services/HealthService.php',               // 23F health pack
            'app/config.php',                               // 23G release marker
        ];
        foreach ($files as $file) {
            assert_true(is_file($appRoot . '/' . $file), 'phase deliverable must exist: ' . $file);
        }
    },

    'P23G-T3 docs cold read: every settings key named in docs exists; every phase-≤23 route exists' => function (): void {
        $repo = dirname(__DIR__, 3);
        $docs = $repo . '/docs';
        /* Every package-binary settings key named in docs must exist in the settings table. */
        $expectedKeys = [
            'package_binary_enabled', 'package_binary_requires_package', 'package_binary_ledger_since',
        ];
        $keys = [];
        foreach (glob("$docs/*.md") as $doc) {
            $content = (string) file_get_contents($doc);
            preg_match_all("/`package_binary_[a-z_]+`/", $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $key = trim($m[0], '`');
                if (in_array($key, $expectedKeys, true)) {
                    $keys[] = $key;
                }
            }
        }
        $keys = array_unique($keys);
        $settings = [];
        foreach (db()->query('SELECT key_name FROM settings') as $row) {
            $settings[] = $row['key_name'];
        }
        foreach ($keys as $key) {
            assert_true(in_array($key, $settings, true), "settings key $key named in docs must exist in the settings table");
        }

        /* Every route due at or below APP_PHASE (23) is registered; none is phased beyond the build. */
        $inventory = require dirname(__DIR__, 2) . '/app/routes_inventory.php';
        $router = new Router(require dirname(__DIR__, 2) . '/app/routes.php');
        $live = [];
        foreach ($router->routes() as $route) {
            $live[$route['method'] . ' ' . $route['path']] = $route;
        }
        foreach ($inventory['extensions'] ?? [] as $ext) {
            if ((int) ($ext['phase'] ?? 0) <= 23) {
                assert_true(
                    isset($live[$ext['method'] . ' ' . $ext['path']]),
                    'route ' . $ext['method'] . ' ' . $ext['path'] . ' (phase ' . $ext['phase'] . ') must be registered'
                );
            }
        }
    },

    'P23G-T6 QA pack integrity: sections A–G present, every QA23X-nn unique, evidence index matches files' => function (): void {
        $repo = dirname(__DIR__, 3);
        $qaPath = $repo . '/docs/qa/phase-23-package-binary.md';
        assert_true(is_file($qaPath), 'QA pack must exist: ' . $qaPath);
        $qa = (string) file_get_contents($qaPath);

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $letter) {
            assert_true(str_contains($qa, 'QA23' . $letter . '-01'), 'QA pack section ' . $letter . ' must exist');
        }

        preg_match_all('/^### QA23([A-G])-(\d+) /m', $qa, $ids, PREG_SET_ORDER);
        $seen = [];
        foreach ($ids as $id) {
            $key = $id[1] . '-' . $id[2];
            assert_true(!isset($seen[$key]), "QA check title $key must be unique");
            $seen[$key] = true;
        }

        $counts = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $letter) {
            preg_match_all('/^### QA23' . $letter . '-(\d+) /m', $qa, $m, PREG_SET_ORDER);
            $counts[$letter] = count($m);
        }
        assert_same(6,  $counts['A'], 'section A must have 6 checks');
        assert_same(6,  $counts['B'], 'section B must have 6 checks');
        assert_same(8,  $counts['C'], 'section C must have 8 checks');
        assert_same(7,  $counts['D'], 'section D must have 7 checks');
        assert_same(6,  $counts['E'], 'section E must have 6 checks');
        assert_same(5,  $counts['F'], 'section F must have 5 checks');
        assert_same(4,  $counts['G'], 'section G must have 4 checks');

        $indexPath = $repo . '/docs/qa/evidence/phase-23/README.md';
        assert_true(is_file($indexPath), 'QA evidence index must exist: ' . $indexPath);
        $indexContent = (string) file_get_contents($indexPath);
        assert_contains('QA23A-01', $indexContent, 'evidence index must list QA23A-01');
        assert_contains('QA23F-05', $indexContent, 'evidence index must list QA23F-05');
        assert_contains('QA23G-01', $indexContent, 'evidence index must list QA23G-01');
        assert_contains('QA23G-04', $indexContent, 'evidence index must list QA23G-04');
    },
];
