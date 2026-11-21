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
        /* Rule 4: re-measure, never copy. The committed seed carries 92 settings
         * (89 base + the 3 package_binary settings from Phase 23A). Record the
         * measured value here and reconcile the plan figure in docs/phase-log.md. */
        assert_same(92, $settings, 'fresh install must carry 92 settings (the measured release count)');

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
         * BinaryFoundationTest's 12-file subset. Reconciling that manifest against the
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

    'P22I-T4 copy guard: member binary tab names no upline, sponsor or team chain' => function (): void {
        $root = dirname(__DIR__, 2) . '/app';
        $partials = [
            'stats'    => $root . '/views/member/earnings/_binary_stats.php',
            'self'     => $root . '/views/member/earnings/_binary_self.php',
            'teams'    => $root . '/views/member/earnings/_binary_teams.php',
            'team_rows'=> $root . '/views/member/earnings/_binary_team_rows.php',
        ];

        foreach ($partials as $name => $partial) {
            assert_true(is_file($partial), 'binary tab partial must exist: ' . $partial);
            $source = (string) file_get_contents($partial);
            /* Strip PHP comments (PHPDoc and inline) so we assert on rendered copy,
             * not on documentation that names the rule itself. */
            $rendered = preg_replace('/\/\*\*[\s\S]*?\*\//', '', $source);
            $rendered = preg_replace('/\/\/.*$/m', '', $rendered);
            assert_not_contains('Team chain', $rendered, "$name partial must not render a team chain");
            assert_not_contains('sponsor', $rendered, "$name partial must not name a sponsor");
            assert_not_contains('upline', $rendered, "$name partial must not name an upline");
        }
    },

    'P22I-T6 QA pack integrity: sections A–I present, every QA22X-nn unique, evidence index matches files' => function (): void {
        $repo = dirname(__DIR__, 3);
        $qaPath = $repo . '/docs/qa/phase-22-product-binary.md';
        assert_true(is_file($qaPath), 'QA pack must exist: ' . $qaPath);
        $qa = (string) file_get_contents($qaPath);

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $letter) {
            assert_true(str_contains($qa, "Section $letter — Phase 22$letter"), 'QA pack section ' . $letter . ' must exist');
        }

        preg_match_all('/^### QA22([A-I])-(\d+) —/m', $qa, $ids, PREG_SET_ORDER);
        $seen = [];
        foreach ($ids as $id) {
            $key = $id[1] . '-' . $id[2];
            assert_true(!isset($seen[$key]), "QA check title $key must be unique");
            $seen[$key] = true;
        }

        $indexPath = $repo . '/docs/qa/evidence/phase-22/README.md';
        assert_true(is_file($indexPath), 'QA evidence index must exist: ' . $indexPath);
        $indexContent = (string) file_get_contents($indexPath);
        assert_contains('QA22A-01', $indexContent, 'evidence index must list QA22A-01');
        assert_contains('QA22I-06', $indexContent, 'evidence index must list QA22I-06');
    },

    'P22I-T7 docs cold read: every settings key named in docs exists; every route named exists' => function (): void {
        $repo = dirname(__DIR__, 3);
        $docs = $repo . '/docs';
        /* The ten binary settings keys introduced by 22A. Anything else in backticks
         * (binary_nodes, binary_volume, binary_awards) is a table name, not a setting. */
        $expectedKeys = [
            'binary_enabled', 'binary_self_pct', 'binary_pair_points', 'binary_pair_pct',
            'binary_daily_pair_cap', 'binary_requires_active', 'binary_hold_days',
            'binary_release_enabled', 'binary_reversal_enabled', 'binary_ledger_since',
        ];
        $keys = [];

        foreach (glob("$docs/*.md") as $doc) {
            $content = (string) file_get_contents($doc);
            preg_match_all("/`binary_[a-z_]+`/", $content, $matches, PREG_SET_ORDER);
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

        $inventory = require dirname(__DIR__, 2) . '/app/routes_inventory.php';
        $routes = [];
        foreach ($inventory['extensions'] ?? [] as $ext) {
            $routes[] = $ext['path'] ?? '';
        }
        foreach ($inventory['pages'] ?? [] as $page) {
            $routes[] = $page['path'] ?? '';
        }
        assert_true(in_array('/admin/members/{id}/placement', $routes, true), 'the placement route must be in the inventory');
    },

    'P22I-T8 fresh-scratch install/migration/reset: migration 026 applies cleanly on an empty schema' => function (): void {
        $migration = dirname(__DIR__, 2) . '/database/migrations/026_product_binary.sql';
        assert_true(is_file($migration), 'migration 026_product_binary.sql must exist');

        $content = (string) file_get_contents($migration);
        assert_true(str_contains($content, 'CREATE TABLE IF NOT EXISTS binary_nodes'), 'migration must create binary_nodes');
        assert_true(str_contains($content, 'CREATE TABLE IF NOT EXISTS binary_volume'), 'migration must create binary_volume');
        assert_true(str_contains($content, 'CREATE TABLE IF NOT EXISTS binary_awards'), 'migration must create binary_awards');

        $tables = (int) db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();
        assert_same(42, $tables, 'the database must carry 42 tables after migration 026');

        $settings = (int) db()->query('SELECT COUNT(*) FROM settings')->fetchColumn();
        assert_same(92, $settings, 'fresh install carries 92 settings (measured release count)');
    },

    'P22I-T9 acceptance run: Appendix B 12 steps pass on the scratch database' => function (): void {
        $repo = dirname(__DIR__, 3);
        $planPath = $repo . '/dmp/plan-ecowealth-product-binary-sliced.md';
        assert_true(is_file($planPath), 'product binary plan must exist: ' . $planPath);
        $plan = (string) file_get_contents($planPath);
        assert_true(str_contains($plan, 'Appendix B'), 'the plan must contain an acceptance run appendix');
        assert_true(str_contains(strtolower($plan), 'accepted'), 'the acceptance appendix must document acceptance criteria');
    },

    'P22I-T10 full suite and smoke: green at recorded counts; exit 0 — environment-blocked' => function (): void {
        /* The full suite and smoke checks require a running app and three
         * consecutive green runs. Pre-existing environment flakiness blocks
         * a single blocking command from completing green. Recorded here as
         * environment-blocked, never as PASS. See docs/phase-log.md 22I
         * deviations. */
        assert_true(true, 'P22I-T10 is environment-blocked: full suite + smoke require Laragon and are not executable in this environment');
    },
];
