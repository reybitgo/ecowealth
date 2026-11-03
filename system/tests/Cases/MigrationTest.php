<?php
declare(strict_types=1);

/* ==============================================================
 *  MigrationTest — database/migrations/001_settings_roles.sql
 *
 *  Builds a pre-Phase-1 database from the fixture, applies the
 *  migration, and checks that the result matches a fresh install
 *  of database/schema.sql. Uses its own schema
 *  (ecowealth_sneat_migtest) so it never disturbs the app schema.
 * ============================================================== */

$migrationFile = dirname(__DIR__, 2) . '/database/migrations/001_settings_roles.sql';
$fixtureFile   = __DIR__ . '/../fixtures/legacy_phase0_schema.sql';

/** Fresh legacy database with the migration applied. */
$buildMigrated = static function () use ($migrationFile, $fixtureFile): PDO {
    $pdo = test_pdo();
    test_recreate_database($pdo, TEST_MIGRATION_DB);

    test_run_sql_file($pdo, $fixtureFile);
    test_run_sql_file($pdo, $migrationFile);

    return $pdo;
};

return [
    'migration renames user → member and adds superadmin' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        $roles = $pdo->query('SELECT username, role FROM users ORDER BY id')->fetchAll();
        assert_count(2, $roles);
        assert_same('member', $roles[0]['role'], 'legacy "user" row');
        assert_same('admin', $roles[1]['role'], 'legacy admin row');

        $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        assert_contains("'member'", $column['Type']);
        assert_contains("'admin'", $column['Type']);
        assert_contains("'superadmin'", $column['Type']);
        assert_not_contains("'user'", $column['Type'], 'the old role value must be gone');
    },

    'migration keeps every account and its password hash' => function () use ($buildMigrated, $fixtureFile, $migrationFile): void {
        /* Capture the fixture rows before the upgrade… */
        $legacy = test_pdo();
        test_recreate_database($legacy, TEST_MIGRATION_DB);
        test_run_sql_file($legacy, $fixtureFile);

        $before = $legacy->query('SELECT id, username, email, password_hash FROM users ORDER BY id')->fetchAll();
        assert_count(2, $before);

        /* …then compare with the same data after the migration. */
        $after = $buildMigrated()
            ->query('SELECT id, username, email, password_hash FROM users ORDER BY id')
            ->fetchAll();

        assert_same($before, $after, 'accounts must survive the migration untouched');
    },

    'migration creates settings and audit_log' => function () use ($buildMigrated): void {
        $pdo    = $buildMigrated();
        $tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);

        assert_true(in_array('settings', $tables, true), 'settings table missing');
        assert_true(in_array('audit_log', $tables, true), 'audit_log table missing');

        $pdo->exec("INSERT INTO settings (key_name, value) VALUES ('probe', '1')");
        assert_same('1', $pdo->query("SELECT value FROM settings WHERE key_name = 'probe'")->fetchColumn());
    },

    'migration adds the role index' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        $index = $pdo->query(
            "SHOW INDEX FROM users WHERE Key_name = 'idx_users_role'"
        )->fetchAll();

        assert_true($index !== [], 'idx_users_role missing');
    },

    'migration is idempotent' => function () use ($buildMigrated, $migrationFile): void {
        $pdo = $buildMigrated();

        /* Re-running must be safe (CREATE ... IF NOT EXISTS, guarded index). */
        test_run_sql_file($pdo, $migrationFile);

        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        assert_same('member', $pdo->query("SELECT role FROM users WHERE username = 'legacy_user'")->fetchColumn());
    },

    'migrated schema matches a fresh install' => function () use ($buildMigrated): void {
        $migrated = $buildMigrated();
        $fresh    = test_pdo(['db' => DB_NAME]);

        $tableList = static function (PDO $pdo): array {
            $tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
            sort($tables);

            return $tables;
        };

        assert_same($tableList($fresh), $tableList($migrated), 'table list differs from schema.sql');

        $columnList = static function (PDO $pdo): array {
            $columns = [];
            foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $column) {
                $columns[] = $column['Field'] . ' ' . $column['Type'] . ' ' . $column['Null'] . ' ' . $column['Default'] . ' ' . $column['Extra'];
            }

            return $columns;
        };

        assert_same($columnList($fresh), $columnList($migrated), 'users columns differ from schema.sql');
    },

    'migrated schema rejects the old user role value' => function () use ($buildMigrated): void {
        $pdo = $buildMigrated();

        assert_throws(
            static fn () => $pdo->exec(
                "INSERT INTO users (username, email, password_hash, role)
                 VALUES ('probe_user', 'probe@test.local', 'x', 'user')"
            ),
            PDOException::class,
            'the old role value must not be insertable'
        );

        $pdo->exec(
            "INSERT INTO users (username, email, password_hash, role)
             VALUES ('probe_member', 'probe.member@test.local', 'x', 'member')"
        );

        assert_same('member', $pdo->query("SELECT role FROM users WHERE username = 'probe_member'")->fetchColumn());
    },
];
