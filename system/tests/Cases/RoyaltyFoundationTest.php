<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyFoundationTest — Phase 19A (plan §5)
 *
 *  Proves the foundation phase on its own: the Phase 19 schema,
 *  the sixteen settings, the wallet vocabulary and the
 *  install/reset tool expectations. Nothing in this file pays
 *  money — 19A deliberately ships no reader, so the cases assert
 *  that the new objects are inert as well as correct.
 *
 *      migration   the two tables, their unique anchor, foreign
 *                  keys and CHECKs; the extended enum; the sixteen
 *                  settings with the reference defaults
 *      idempotency a second run is a no-op and keeps an operator's
 *                  value
 *      parity      a migrated database and a fresh install agree
 *      bundle      install.sql matches its sources and mirrors the
 *                  migration's objects (33 tables, 73 settings)
 *      tools       DatabaseInstaller / DatabaseResetService agree
 *                  with the schema (33 tables; two money tables)
 *      vocabulary  WalletService::REF_TYPES, the label and the icon
 *      defaults    everything off; no backfill; a datetime baseline
 *      guards      the cascades, the unique keys and the CHECKs
 *      inert       no model, service, controller, view or route
 *                  reads the tables (P19A-T13)
 *
 *  Run this group alone with:  php tests/run.php Royalty
 * ============================================================== */

$appRoot      = dirname(__DIR__, 2);
$migrationDir = $appRoot . '/database/migrations/';
$fixtureFile  = __DIR__ . '/../fixtures/legacy_phase0_schema.sql';

/** The migrations a p19-start database has: 001 … 022, in order. */
$startMigrations = static function () use ($migrationDir): array {
    $files = [];

    foreach (glob($migrationDir . '*.sql') ?: [] as $file) {
        $name = basename($file);

        if (preg_match('/^0(0[1-9]|1[0-9]|2[0-2])_/', $name) === 1) {
            $files[] = $file;
        }
    }

    sort($files, SORT_STRING);

    return $files;
};

/** Build a p19-start database (the legacy fixture + migrations 001–022). */
$buildStart = static function () use ($startMigrations, $fixtureFile): PDO {
    $pdo = test_pdo();
    test_recreate_database($pdo, TEST_MIGRATION_DB);
    test_run_sql_file($pdo, $fixtureFile);

    foreach ($startMigrations() as $migration) {
        test_run_sql_file($pdo, $migration);
    }

    return $pdo;
};

/**
 * Build once and keep the connection: a full 001–023 replay is the most
 * expensive fixture in the suite, and every case only reads it (T2 and
 * T7 restore what they touch).
 */
$foundation = null;
$buildFoundation = static function () use (&$foundation, $buildStart, $migrationDir): PDO {
    if ($foundation instanceof PDO) {
        return $foundation;
    }

    $pdo = $buildStart();
    test_run_sql_file($pdo, $migrationDir . '023_royalty_bonus.sql');
    $foundation = $pdo;

    return $pdo;
};

/** The value of one settings key, or null. */
$setting = static function (PDO $pdo, string $key): ?string {
    $statement = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (string) $value;
};

/** One named index from the bootstrap's index helper. */
$index = static function (PDO $pdo, string $table, string $name): ?array {
    foreach (test_index_list($pdo)[$table] ?? [] as $candidate) {
        if ($candidate['name'] === $name) {
            return $candidate;
        }
    }

    return null;
};

/** The CHECK constraint names of one table, sorted. */
$checks = static function (PDO $pdo, string $table): array {
    $statement = $pdo->prepare(
        "SELECT constraint_name FROM information_schema.table_constraints
          WHERE table_schema = ? AND table_name = ? AND constraint_type = 'CHECK'
          ORDER BY constraint_name"
    );
    $statement->execute([test_current_database($pdo), $table]);

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
};

/** The AUTO_INCREMENT value of one table, or null when it has no counter. */
$nextId = static function (PDO $pdo, string $table): ?int {
    $statement = $pdo->prepare(
        'SELECT auto_increment FROM information_schema.tables
          WHERE table_schema = ? AND table_name = ? LIMIT 1'
    );
    $statement->execute([test_current_database($pdo), $table]);
    $value = $statement->fetchColumn();

    return $value === false || $value === null ? null : (int) $value;
};

/**
 * Insert one `royalty_periods` header with the required snapshots.
 *
 * @param array<string,string|int> $overrides
 */
$insertPeriod = static function (PDO $pdo, string $period, array $overrides = []): void {
    $row = $overrides + [
        'status'     => 'evaluated',
        'enabled'    => 1,
        'evaluated'  => '2026-09-01 00:05:00',
        'as_of'      => '2026-09-30 23:59:59',
        'leader'     => 10,
        'supervisor' => 10,
        'manager'    => 10,
        'director'   => 10,
        'q_sup'      => '500000.00',
        'q_mgr'      => '1000000.00',
        'q_dir'      => '5000000.00',
        'g_sup'      => '3.000',
        'p_sup'      => '5.000',
        'g_mgr'      => '5.000',
        'p_mgr'      => '10.000',
        'g_dir'      => '10.000',
        'p_dir'      => '15.000',
    ];

    $pdo->prepare(
        'INSERT INTO royalty_periods
           (period, status, enabled, evaluated_at, as_of,
            leader_directs, supervisor_leaders, manager_supervisors, director_managers,
            quota_supervisor, quota_manager, quota_director,
            group_pct_supervisor, personal_pct_supervisor,
            group_pct_manager, personal_pct_manager,
            group_pct_director, personal_pct_director)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $period, (string) $row['status'], (int) $row['enabled'], (string) $row['evaluated'],
        (string) $row['as_of'], (int) $row['leader'], (int) $row['supervisor'],
        (int) $row['manager'], (int) $row['director'], (string) $row['q_sup'],
        (string) $row['q_mgr'], (string) $row['q_dir'], (string) $row['g_sup'],
        (string) $row['p_sup'], (string) $row['g_mgr'], (string) $row['p_mgr'],
        (string) $row['g_dir'], (string) $row['p_dir'],
    ]);
};

/**
 * Insert one `royalty_awards` row, valid unless an override breaks a rule.
 *
 * @param array<string,string|int|null> $overrides
 */
$insertAward = static function (PDO $pdo, string $period, int $userId, array $overrides = []): void {
    $row = $overrides + [
        'rank_level'      => 2,
        'group_volume'    => '1000000.00',
        'personal_volume' => '1000.00',
        'quota'           => '500000.00',
        'group_pct'       => '3.000',
        'personal_pct'    => '5.000',
        'amount'          => '30050.00',
        'status'          => 'pending',
        'reason'          => null,
    ];

    $pdo->prepare(
        'INSERT INTO royalty_awards
           (period, user_id, rank_level, group_volume, personal_volume,
            quota, group_pct, personal_pct, amount, status, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $period, $userId, (int) $row['rank_level'], (string) $row['group_volume'],
        (string) $row['personal_volume'], (string) $row['quota'], (string) $row['group_pct'],
        (string) $row['personal_pct'], (string) $row['amount'], (string) $row['status'],
        $row['reason'] === null ? null : (string) $row['reason'],
    ]);
};

/** A bare member row for the cascade/guard cases. */
$makeMember = static function (PDO $pdo, string $username): int {
    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, referral_code, is_active)
         VALUES (?, ?, ?, ?, 'member', 'active', ?, 1)"
    )->execute([
        $username,
        $username . '@p19a.test',
        password_hash('P19aMember@123', PASSWORD_BCRYPT),
        ucfirst($username) . ' Test',
        strtoupper(bin2hex(random_bytes(4))),
    ]);

    return (int) $pdo->lastInsertId();
};

/** The sixteen Phase 19 settings, in the order the migration writes them. */
$newSettings = [
    'royalty_enabled'                 => '0',
    'royalty_pay_enabled'             => '1',
    'royalty_leader_directs'          => '10',
    'royalty_supervisor_leaders'      => '10',
    'royalty_manager_supervisors'     => '10',
    'royalty_director_managers'       => '10',
    'royalty_supervisor_quota'        => '500000',
    'royalty_manager_quota'           => '1000000',
    'royalty_director_quota'          => '5000000',
    'royalty_supervisor_group_pct'    => '3.000',
    'royalty_supervisor_personal_pct' => '5.000',
    'royalty_manager_group_pct'       => '5.000',
    'royalty_manager_personal_pct'    => '10.000',
    'royalty_director_group_pct'      => '10.000',
    'royalty_director_personal_pct'   => '15.000',
];

return [
    /* ==========================================================
     *  Schema (P19A-T1)
     * ========================================================== */
    'P19A-T1 migration 023 adds the two tables, their anchor, foreign keys and checks' => function () use ($buildFoundation, $index, $checks): void {
        $pdo    = $buildFoundation();
        $fresh  = test_pdo(['db' => DB_NAME]);
        $tables = test_table_list($pdo);

        foreach (['royalty_periods', 'royalty_awards'] as $table) {
            assert_true(in_array($table, $tables, true), $table . ' is missing after migration 023');
        }

        /* The natural primary key of the header and the money anchor of
         * the ledger (plan §13.2, §13.3). */
        assert_same(['period'], array_column($index($pdo, 'royalty_periods', 'PRIMARY')['columns'], 'column'));
        assert_same(
            ['status', 'paid_at'],
            array_column($index($pdo, 'royalty_periods', 'idx_royalty_periods_status')['columns'], 'column')
        );

        $anchor = $index($pdo, 'royalty_awards', 'uq_royalty_award');
        assert_not_null($anchor, 'uq_royalty_award is missing');
        assert_true($anchor['unique'], 'the money anchor is unique');
        assert_same(['period', 'user_id'], array_column($anchor['columns'], 'column'));

        $ledger = $index($pdo, 'royalty_awards', 'idx_royalty_ledger');
        assert_not_null($ledger, 'idx_royalty_ledger is missing');
        assert_same(['ledger_id'], array_column($ledger['columns'], 'column'));

        /* The ledger's only sequence is its primary key; the header has a
         * natural key and therefore no counter (plan §13.7). */
        $id = null;

        foreach ($pdo->query('SHOW COLUMNS FROM royalty_awards')->fetchAll() as $column) {
            if ($column['Field'] === 'id') {
                $id = $column;
            }
        }

        assert_not_null($id, 'royalty_awards.id is missing');
        assert_same('bigint unsigned', $id['Type']);
        assert_same('auto_increment', $id['Extra']);

        /* Foreign keys: the month header and the recipient cascade; the
         * two staff actors are nulled, never cascaded (plan §13.1). */
        $foreign = test_foreign_key_list($pdo);

        $byName = static function (string $table, string $name) use ($foreign): ?array {
            foreach ($foreign[$table] ?? [] as $foreignKey) {
                if ($foreignKey['name'] === $name) {
                    return $foreignKey;
                }
            }

            return null;
        };

        assert_same('CASCADE', $byName('royalty_awards', 'fk_ra_period')['delete_rule']);
        assert_same('royalty_periods', $byName('royalty_awards', 'fk_ra_period')['referenced_table']);
        assert_same('CASCADE', $byName('royalty_awards', 'fk_ra_user')['delete_rule']);
        assert_same('users', $byName('royalty_awards', 'fk_ra_user')['referenced_table']);
        assert_same('SET NULL', $byName('royalty_periods', 'fk_rp_evaluator')['delete_rule']);
        assert_same('SET NULL', $byName('royalty_periods', 'fk_rp_payer')['delete_rule']);

        /* The CHECKs the plan names (§13.2, §13.3), on both sides. */
        assert_same(
            ['chk_rp_period', 'chk_rp_ranks', 'chk_rp_totals'],
            $checks($pdo, 'royalty_periods')
        );
        assert_same(
            ['chk_ra_amount', 'chk_ra_rank', 'chk_ra_rates', 'chk_ra_volume'],
            $checks($pdo, 'royalty_awards')
        );
        assert_same($checks($fresh, 'royalty_periods'), $checks($pdo, 'royalty_periods'));
        assert_same($checks($fresh, 'royalty_awards'), $checks($pdo, 'royalty_awards'));

        /* The stream is a separate ledger (D-07), and the migration
         * backfills nothing. */
        assert_false(in_array('royalty_awards', ['commissions', 'unilevel_awards'], true));
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn(), 'no backfill');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn(), 'no backfill');
    },

    /* ==========================================================
     *  Idempotency (P19A-T2)
     * ========================================================== */
    'P19A-T2 a second run of the migration is a no-op and recreates a lost table' => function () use ($buildFoundation, $migrationDir): void {
        $pdo = $buildFoundation();

        $snapshot = static fn (PDO $pdo): array => [
            'tables'   => test_table_list($pdo),
            'columns'  => test_column_list($pdo),
            'indexes'  => test_index_list($pdo),
            'foreign'  => test_foreign_key_list($pdo),
            'settings' => (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
            'enum'     => (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'],
        ];

        $before = $snapshot($pdo);

        test_run_sql_file($pdo, $migrationDir . '023_royalty_bonus.sql');

        assert_same($before, $snapshot($pdo), 'a re-run changes no object, no setting and no enum value');

        /* A lost table is recreated by the same guarded file. */
        $pdo->exec('DROP TABLE IF EXISTS royalty_awards');
        test_run_sql_file($pdo, $migrationDir . '023_royalty_bonus.sql');

        assert_true(
            in_array('royalty_awards', test_table_list($pdo), true),
            'a re-run recreates a missing table (CREATE TABLE IF NOT EXISTS)'
        );
        assert_same(
            16,
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'royalty\\_%'")->fetchColumn(),
            'the settings insert stays idempotent'
        );
    },

    /* ==========================================================
     *  Migrated = fresh (P19A-T3)
     * ========================================================== */
    'P19A-T3 the migrated schema and a fresh install are equivalent' => function () use ($buildFoundation, $checks): void {
        $migrated = $buildFoundation();
        $fresh    = test_pdo(['db' => DB_NAME]);

        assert_same(
            test_table_list($fresh, DB_NAME),
            test_table_list($migrated),
            'the table list differs from schema.sql'
        );
        assert_same(
            test_column_list($fresh, DB_NAME),
            test_column_list($migrated),
            'a column, type, default or collation differs from schema.sql'
        );
        assert_same(
            test_index_list($fresh, DB_NAME),
            test_index_list($migrated),
            'an index differs from schema.sql'
        );
        assert_same(
            test_foreign_key_list($fresh, DB_NAME),
            test_foreign_key_list($migrated),
            'a foreign key differs from schema.sql'
        );

        /* The extended ledger enum is identical on both sides. */
        $enum = static fn (PDO $pdo): string
            => (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'];

        assert_same($enum($fresh), $enum($migrated), 'the ledger enum must equal the fresh-install enum');

        /* The two new tables carry the same CHECKs, which the column and
         * index helpers do not read. */
        foreach (['royalty_periods', 'royalty_awards'] as $table) {
            assert_same($checks($fresh, $table), $checks($migrated, $table), $table . ' CHECK constraints differ');
        }
    },

    /* ==========================================================
     *  The wallet vocabulary (P19A-T4, P19A-T5)
     * ========================================================== */
    'P19A-T4 the ledger enum and WalletService::REF_TYPES agree in both directions' => function (): void {
        $column = db()->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch();
        assert_not_null($column, 'the ledger table must exist');

        preg_match_all("/'([^']+)'/", (string) $column['Type'], $matches);
        $dbValues = $matches[1];

        $php = WalletService::REF_TYPES;

        assert_count(11, $php, 'eleven movement kinds after Phase 19 (D-19 adds royalty only)');
        assert_same($php, array_values(array_unique($php)), 'no duplicate movement kind');
        assert_same('royalty', end($php), 'royalty is appended last, after unilevel_reversal');
        assert_same('royalty', end($dbValues), 'the enum appends royalty last');

        assert_same(
            1,
            count(array_keys($dbValues, 'royalty', true)),
            'the enum names royalty exactly once'
        );
        assert_false(in_array('royalty_reversal', $dbValues, true), 'D-19: there is no reversal type');

        sort($dbValues);
        sort($php);

        assert_same($dbValues, $php, 'the PHP list and the column must agree in both directions');

        /* The vocabulary stays closed: a forward stub is refused before
         * any write, exactly like an unknown value. */
        $error = assert_throws(
            static fn (): array => (new WalletService())->credit(1, '1.00', 'royalty_reversal', 1),
            WalletException::class
        );

        assert_same('ref_type', $error->reason);
    },

    'P19A-T5 royalty renders as Royalty bonus with a crown icon' => function (): void {
        assert_same('Royalty bonus', wallet_ref_label('royalty'));
        assert_same('bx bx-crown', wallet_ref_icon('royalty'));

        /* The label ships before the money: a row written by 19C must not
         * render as ucfirst('royalty') (plan §5 Rule 8). */
        assert_not_same('Royalty', wallet_ref_label('royalty'));

        /* Every value in the vocabulary has a label and a bx- icon: the
         * wallet statement renders one filter option per entry. */
        foreach (WalletService::REF_TYPES as $refType) {
            assert_true(wallet_ref_label($refType) !== '', $refType . ' has a label');
            assert_same(1, preg_match('/^bx bx-[a-z0-9-]+$/', wallet_ref_icon($refType)), $refType . ' has a bx- icon');
        }
    },

    /* ==========================================================
     *  The settings (P19A-T6, P19A-T7, P19A-T8)
     * ========================================================== */
    'P19A-T6 the sixteen settings exist with the reference defaults' => function () use ($buildFoundation, $newSettings, $setting): void {
        $fresh    = test_pdo(['db' => DB_NAME]);
        $migrated = $buildFoundation();

        foreach ($newSettings as $key => $expected) {
            assert_same($expected, $setting($fresh, $key), 'a fresh install seeds ' . $key);
            assert_same($expected, $setting($migrated, $key), 'a migrated database seeds ' . $key);
        }

        foreach ([$fresh, $migrated] as $pdo) {
            assert_same(
                16,
                (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'royalty\\_%'")->fetchColumn(),
                'exactly sixteen royalty settings — no hidden stub'
            );
        }
    },

    'P19A-T7 re-running the settings insert never overwrites an operator value' => function () use ($buildFoundation, $migrationDir, $setting): void {
        $pdo = $buildFoundation();

        $pdo->exec("UPDATE settings SET value = '750000' WHERE key_name = 'royalty_supervisor_quota'");
        $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'royalty_enabled'");

        try {
            test_run_sql_file($pdo, $migrationDir . '023_royalty_bonus.sql');

            assert_same('750000', $setting($pdo, 'royalty_supervisor_quota'), 'an operator quota survives a re-run');
            assert_same('1', $setting($pdo, 'royalty_enabled'), 'an operator switch survives a re-run');
            assert_same(
                16,
                (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'royalty\\_%'")->fetchColumn()
            );
        } finally {
            /* Leave the shared fixture at its documented defaults. */
            $pdo->exec("UPDATE settings SET value = '500000' WHERE key_name = 'royalty_supervisor_quota'");
            $pdo->exec("UPDATE settings SET value = '0' WHERE key_name = 'royalty_enabled'");
        }
    },

    'P19A-T8 the earning switch is off and the payment switch is on by default' => function (): void {
        setting_cache_clear();

        assert_same('0', setting('royalty_enabled'), 'D-14: the stream ships off');
        assert_false(setting_bool('royalty_enabled', true), 'a missing/zero switch resolves to off');

        assert_same('1', setting('royalty_pay_enabled'), 'the payment pause ships on');
        assert_true(setting_bool('royalty_pay_enabled', false), 'payment is not paused out of the box');

        /* The stream is inert: no period, no award, no royalty ledger row. */
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
        assert_same(
            0,
            (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn(),
            'nothing has ever paid a Royalty movement'
        );
    },

    /* ==========================================================
     *  The bundle and the tools (P19A-T9, P19A-T10)
     * ========================================================== */
    'P19A-T9 the committed install bundle matches its sources and carries 33 tables and 73 settings' => function () use ($appRoot): void {
        require_once $appRoot . '/bin/build_install_sql.php';

        $result = build_install_sql_check($appRoot);
        assert_true(
            $result['ok'],
            'database/install.sql is stale'
                . ($result['line'] === null ? '' : ' (first difference at line ' . $result['line'] . ')')
                . '; rebuild with: php bin/build_install_sql.php'
        );

        $header = (string) file_get_contents($appRoot . '/database/install/header.sql');
        $footer = (string) file_get_contents($appRoot . '/database/install/footer.sql');
        $bundle = (string) file_get_contents($appRoot . '/database/install.sql');

        assert_contains('33 tables', $header);
        assert_contains('73 settings', $header);
        assert_contains('tables_created = 33', $footer);
        assert_contains('settings_rows = 73', $footer);
        assert_contains('33 tables', $bundle);
        assert_contains('73 settings', $bundle);

        $script = SqlScript::fromFile($appRoot . '/database/install.sql');
        assert_false($script->containsDatabaseSwitch());

        $creates = 0;
        $created = [];

        foreach ($script->statements() as $statement) {
            if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?/i', $statement['sql'], $match) === 1) {
                $creates++;
                $created[] = strtolower($match[1]);
            }
        }

        assert_same(33, $creates, 'the bundle creates 33 tables');

        foreach (['royalty_periods', 'royalty_awards'] as $table) {
            assert_true(in_array($table, $created, true), 'the bundle creates ' . $table);
        }
    },

    'P19A-T10 DatabaseInstaller expects 33 tables and 73 settings and installs them' => function (): void {
        assert_same(33, DatabaseInstaller::EXPECTED['tables_created']);
        assert_same(73, DatabaseInstaller::EXPECTED['settings_rows']);
        assert_same(2, DatabaseInstaller::EXPECTED['staff_rows']);
        assert_same(0, DatabaseInstaller::EXPECTED['members_rows']);
        assert_same(7, DatabaseInstaller::EXPECTED['products_rows']);
        assert_same(2, DatabaseInstaller::EXPECTED['packages_rows']);
        assert_same(18, DatabaseInstaller::EXPECTED['levels_rows']);
        assert_same(10, DatabaseInstaller::EXPECTED['contents_rows']);
        assert_same(0, DatabaseInstaller::EXPECTED['codes_rows']);

        $lockPath = DatabaseInstaller::lockPath();

        if (is_file($lockPath)) {
            @unlink($lockPath);
        }

        [$pdo, $database] = test_scratch_database('tool_a');

        try {
            $report = (new DatabaseInstaller(DbTarget::fromConfig(), null, APP_ENV))->install([
                'database'   => $database,
                'admin'      => [
                    'username' => 'admin',
                    'email'    => 'owner@example.com',
                    'password' => 'AdminPassw0rd!23',
                ],
                'superadmin' => [
                    'username' => 'superadmin',
                    'email'    => 'owner+super@example.com',
                    'password' => 'SuperPassw0rd!23',
                ],
                'site_name'  => 'EcoWealth RoyaltyFoundationTest',
                'channel'    => 'cli',
            ]);

            assert_true(
                $report['ok'],
                'the install reports ok: ' . json_encode($report['verification']['checks'] ?? [])
            );

            assert_same(33, $report['counts']['tables_created']);
            assert_same(73, $report['counts']['settings_rows']);
            assert_same(2, $report['counts']['staff_rows']);
            assert_same(0, $report['counts']['members_rows']);
            assert_same(7, $report['counts']['products_rows']);
            assert_same(2, $report['counts']['packages_rows']);
            assert_same(18, $report['counts']['levels_rows']);
            assert_same(10, $report['counts']['contents_rows']);
            assert_same(0, $report['counts']['codes_rows']);

            /* The database itself, not just the report. */
            assert_count(33, test_table_list($pdo, $database));
            assert_same(73, (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
        } finally {
            if (is_file($lockPath)) {
                @unlink($lockPath);
            }
        }
    },

    /* ==========================================================
     *  Reset parity (P19A-T11, P19A-T12)
     * ========================================================== */
    'P19A-T11 a reset empties both royalty tables and resets only the ledger counter' => function () use ($appRoot, $insertPeriod, $insertAward, $makeMember, $nextId): void {
        [$pdo, $database] = test_scratch_database('tool_b');

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

        $member = $makeMember($pdo, 'p19a_reset_member');

        $insertPeriod($pdo, '2026-10');
        $insertAward($pdo, '2026-10', $member);

        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());

        $report = (new DatabaseResetService(
            $pdo,
            $database,
            ['confirm' => $database, 'tool' => 'cli']
        ))->run();

        assert_true($report['ok'], json_encode($report['verification']['checks']));
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn(), 'the money ledger is emptied');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn(), 'the month header is emptied');

        $after = $nextId($pdo, 'royalty_awards');
        assert_true($after === null || $after === 1, 'the award counter is reset, got ' . var_export($after, true));
        assert_null($nextId($pdo, 'royalty_periods'), 'the header has no counter to touch');

        /* The sixteen settings survive a business reset. */
        assert_same(
            16,
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'royalty\\_%'")->fetchColumn(),
            'a reset keeps the settings'
        );

        /* The reset reports the new delete group. */
        $steps = [];

        foreach ($report['steps'] as $step) {
            $steps[(string) $step['key']] = $step;
        }

        assert_true(isset($steps['royalty']), 'the reset reports a royalty step');
        assert_true((int) $steps['royalty']['deleted'] >= 2, 'the step deleted the award and the header');

        /* Only the ledger has a sequence: the reset never ALTERs the
         * header, and no AUTO_INCREMENT statement fails. */
        assert_same(0, $report['auto_increment']['failed']);
        assert_false(in_array('royalty_periods', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_true(in_array('royalty_awards', DatabaseResetService::AUTO_INCREMENT_TABLES, true));

        /* The counter really restarted: the next award takes id 1. This is
         * a functional check, not a metadata read (MySQL 8 caches
         * information_schema.TABLES per session). */
        $again = $makeMember($pdo, 'p19a_reset_member_again');

        $insertPeriod($pdo, '2026-11');
        $insertAward($pdo, '2026-11', $again);

        assert_same(1, (int) $pdo->lastInsertId(), 'the award counter restarted at 1');
    },

    'P19A-T12 the reset preview names both royalty tables and plans the delete' => function () use ($appRoot, $insertPeriod, $insertAward, $makeMember): void {
        [$pdo, $database] = test_scratch_database('tool_b');

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

        $member = $makeMember($pdo, 'p19a_preview_member');

        $insertPeriod($pdo, '2026-10');
        $insertAward($pdo, '2026-10', $member);

        $service = new DatabaseResetService($pdo, $database, ['confirm' => $database, 'tool' => 'cli']);

        $preview = $service->preview();

        assert_same([], $preview['missing_tables'], 'both tables are known to a 33-table reset');
        assert_same(1, $preview['counts']['royalty_periods'], 'the preview counts the header');
        assert_same(1, $preview['counts']['royalty_awards'], 'the preview counts the award');
        assert_same([], $preview['warnings'], 'no missing-table warning');

        $dry  = $service->dryRun();
        $plan = [];

        foreach ($dry['plan'] as $step) {
            $plan[(string) $step['key']] = $step;
        }

        assert_true(isset($plan['royalty']), 'the dry run plans a royalty step');
        assert_same(2, $plan['royalty']['statements'], 'the award is deleted before the header');
        assert_true($dry['ok']);

        /* Nothing was written by either read. */
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
    },

    /* ==========================================================
     *  Inert (P19A-T13)
     * ========================================================== */
    'P19A-T13 no application code reads the royalty tables in 19A' => function () use ($appRoot): void {
        /* No class, model, service or controller exists yet. */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appRoot . '/app', FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        assert_true($files !== [], 'the application source was found');

        $relative = static fn (string $path): string
            => str_replace('\\', '/', substr($path, strlen($appRoot)));

        foreach ($files as $path) {
            assert_same(
                0,
                preg_match('/Royalty/', basename($path)),
                $relative($path) . ' is a Royalty class, which belongs to 19C'
            );
        }

        /* Rule 7: outside the label map and the wallet vocabulary, no app
         * file may name the feature. The reset tooling names the tables
         * (the phase's deliverable), so it is the one identifier allow-list
         * — and it carries no reader. */
        $allowList = [
            '/app/helpers.php',
            '/app/Services/WalletService.php',
            '/app/Services/DatabaseResetService.php',
        ];

        foreach ($files as $path) {
            $file = $relative($path);

            if (in_array($file, $allowList, true)) {
                continue;
            }

            assert_same(
                0,
                preg_match('/royalt/i', (string) file_get_contents($path)),
                $file . ' must not reference the Royalty feature in 19A'
            );
        }

        /* The ledger writer and the rank engine belong to 19C and 19B. */
        foreach ([
            '/app/Models/RoyaltyAward.php',
            '/app/Models/RoyaltyPeriod.php',
            '/app/Services/RoyaltyService.php',
            '/app/Models/RoyaltyRank.php',
            '/app/Controllers/RoyaltyController.php',
        ] as $file) {
            assert_false(is_file($appRoot . $file), $file . ' must not exist in 19A');
        }

        /* No route, controller, view or layout references the stream (D-16). */
        foreach (['/app/routes.php', '/app/routes_inventory.php'] as $file) {
            assert_same(
                0,
                preg_match('/royalt/i', (string) file_get_contents($appRoot . $file)),
                $file . ' has no royalty route'
            );
        }

        foreach (['/app/Controllers', '/app/views', '/app/layout'] as $folder) {
            $inner = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($appRoot . $folder, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($inner as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                assert_same(
                    0,
                    preg_match('/royalt/i', (string) file_get_contents($file->getPathname())),
                    $relative($file->getPathname()) . ' names no Royalty surface in 19A'
                );
            }
        }

        /* The tables are empty on a fresh database, and the stream is off. */
        setting_cache_clear();
        assert_same('0', setting('royalty_enabled'));
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
    },

    /* ==========================================================
     *  Guards (P19A-T14, P19A-T15)
     * ========================================================== */
    'P19A-T14 the award ledger refuses an impossible row' => function () use ($insertPeriod, $insertAward, $makeMember): void {
        $pdo     = db();
        $first   = $makeMember($pdo, 'p19a_guard_first');
        $second  = $makeMember($pdo, 'p19a_guard_second');
        $third   = $makeMember($pdo, 'p19a_guard_third');

        $insertPeriod($pdo, '2026-06');
        $insertAward($pdo, '2026-06', $first);

        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM royalty_awards WHERE period = '2026-06'")->fetchColumn());

        /* The unique anchor: one award per member per month (D-08). */
        assert_throws(
            static fn () => $insertAward($pdo, '2026-06', $first, ['amount' => '1.00']),
            PDOException::class,
            'a second award for the same member and month is refused'
        );

        /* rank_level is 2–4: Member (0), Leader (1) and a bad value have
         * no row (plan §13.3); Supervisor, Manager and Director do. */
        $insertPeriod($pdo, '2026-05');

        foreach ([0, 1, 5] as $level) {
            assert_throws(
                static fn () => $insertAward($pdo, '2026-05', $first, ['rank_level' => $level]),
                PDOException::class,
                'rank_level ' . $level . ' is refused'
            );
        }

        $insertAward($pdo, '2026-05', $first, ['rank_level' => 3]);
        $insertAward($pdo, '2026-05', $second, ['rank_level' => 4]);

        assert_same(
            2,
            (int) $pdo->query("SELECT COUNT(*) FROM royalty_awards WHERE period = '2026-05'")->fetchColumn()
        );

        /* Negative money and an inverted volume pair are refused. */
        $insertPeriod($pdo, '2026-04');

        assert_throws(
            static fn () => $insertAward($pdo, '2026-04', $first, ['amount' => '-0.01']),
            PDOException::class,
            'a negative amount is refused'
        );
        assert_throws(
            static fn () => $insertAward($pdo, '2026-04', $first, ['group_volume' => '100.00', 'personal_volume' => '200.00']),
            PDOException::class,
            'personal volume above group volume is refused'
        );
        assert_throws(
            static fn () => $insertAward($pdo, '2026-04', $first, ['group_volume' => '-1.00']),
            PDOException::class,
            'a negative volume is refused'
        );
        assert_throws(
            static fn () => $insertAward($pdo, '2026-04', $first, ['group_pct' => '100.001']),
            PDOException::class,
            'a rate above 100% is refused'
        );

        /* The period header refuses a malformed month and a rank count
         * above members_count. */
        assert_throws(
            static fn () => $insertPeriod($pdo, '2026-13'),
            PDOException::class,
            'a malformed period is refused'
        );

        $insertPeriod($pdo, '2026-03');

        assert_throws(
            static fn () => $pdo->exec("UPDATE royalty_periods SET leaders_count = 1 WHERE period = '2026-03'"),
            PDOException::class,
            'a rank count above members_count is refused'
        );

        /* The totals check: paid_total can never exceed award_total. */
        assert_throws(
            static fn () => $pdo->exec("UPDATE royalty_periods SET paid_total = 1.00 WHERE period = '2026-03'"),
            PDOException::class,
            'paid_total above award_total is refused'
        );

        /* A legal row still inserts. */
        $insertAward($pdo, '2026-04', $third);
        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM royalty_awards WHERE period = '2026-04'")->fetchColumn()
        );
    },

    'P19A-T15 deleting a period or a user cascades the award' => function () use ($insertPeriod, $insertAward, $makeMember): void {
        $pdo = db();

        $first  = $makeMember($pdo, 'p19a_cascade_first');
        $second = $makeMember($pdo, 'p19a_cascade_second');

        /* Deleting the month header takes its awards. */
        $insertPeriod($pdo, '2026-02');
        $insertAward($pdo, '2026-02', $first);

        $pdo->exec("DELETE FROM royalty_periods WHERE period = '2026-02'");
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM royalty_awards WHERE period = '2026-02'")->fetchColumn(),
            'the award cascaded with its period'
        );

        /* An award cannot point at a month without a header. */
        $insertPeriod($pdo, '2026-01');
        $insertAward($pdo, '2026-01', $first);

        assert_throws(
            static fn () => $pdo->exec("UPDATE royalty_awards SET period = '2026-00' WHERE period = '2026-01'"),
            PDOException::class,
            'an award cannot leave its month header'
        );

        /* Deleting a member takes their awards; the period survives. */
        $pdo->exec('DELETE FROM users WHERE id = ' . $first);
        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM royalty_awards WHERE user_id = ' . $first)->fetchColumn(),
            'the award cascaded with its recipient'
        );
        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM royalty_periods WHERE period = '2026-01'")->fetchColumn(),
            'the month header survives its recipient'
        );

        /* A staff actor is nulled, not deleted, when their account goes. */
        $pdo->exec('UPDATE royalty_periods SET evaluated_by = ' . $second . " WHERE period = '2026-01'");
        $pdo->exec('DELETE FROM users WHERE id = ' . $second);

        assert_null(
            $pdo->query("SELECT evaluated_by FROM royalty_periods WHERE period = '2026-01'")->fetchColumn(),
            'the evaluator reference is set to NULL'
        );
    },

    /* ==========================================================
     *  The baseline (P19A-T16)
     * ========================================================== */
    'P19A-T16 royalty_ledger_since is a non-empty MySQL datetime' => function () use ($buildFoundation, $setting): void {
        foreach ([test_pdo(['db' => DB_NAME]), $buildFoundation()] as $pdo) {
            $value = $setting($pdo, 'royalty_ledger_since');

            assert_true($value !== null && $value !== '', 'the baseline is written by the seed and the migration');
            assert_same(
                1,
                preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $value),
                'the baseline is a MySQL datetime, got ' . var_export($value, true)
            );
            assert_true(strtotime((string) $value) !== false, 'the baseline parses');
        }
    },

    /* ==========================================================
     *  Seed parity (P19A-T17)
     * ========================================================== */
    'P19A-T17 seed 001 carries all sixteen keys and the fresh install agrees' => function () use ($appRoot, $newSettings): void {
        $keys = [];

        foreach (SqlScript::fromFile($appRoot . '/database/seeds/001_settings.sql')->statements() as $statement) {
            $sql = $statement['sql'];

            if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?settings`?\b/i', $sql) !== 1) {
                continue;
            }

            if (preg_match('/\bVALUES\b/i', $sql, $match, PREG_OFFSET_CAPTURE) === 1) {
                $values = substr($sql, $match[0][1] + strlen($match[0][0]));
                $parts  = preg_split('/\bON\s+DUPLICATE\b/i', $values, 2);
                $values = $parts[0] ?? $values;

                if (preg_match_all("/\(\s*'((?:[^']|'')+)'\s*,/", $values, $rows) > 0) {
                    foreach ($rows[1] as $key) {
                        $keys[] = str_replace("''", "'", $key);
                    }
                }
            }
        }

        $royaltyKeys = array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'royalty_')));

        assert_count(16, $royaltyKeys, 'seed 001 carries exactly the sixteen Phase 19 keys');

        foreach (array_keys($newSettings) as $key) {
            assert_true(in_array($key, $keys, true), 'seed 001 carries ' . $key);
        }

        assert_same(count($keys), count(array_unique($keys)), 'seed 001 has no duplicate key');

        /* The seed and the live fresh install agree on every fixed value. */
        $fresh = test_pdo(['db' => DB_NAME]);

        foreach ($newSettings as $key => $expected) {
            $statement = $fresh->prepare('SELECT value FROM settings WHERE key_name = ?');
            $statement->execute([$key]);

            assert_same($expected, (string) $statement->fetchColumn(), 'the fresh install carries ' . $key);
        }
    },

    /* ==========================================================
     *  The count (P19A-T18)
     * ========================================================== */
    'P19A-T18 the schema, the bundle and the reset tooling all report 33 tables' => function () use ($appRoot): void {
        $pdo    = test_pdo(['db' => DB_NAME]);
        $tables = test_table_list($pdo);

        assert_count(33, $tables);
        assert_same(33, DbTarget::fromConfig()->tableCount(DB_NAME));

        /* The reset allow-list is exactly the schema's table list. */
        $allowList = DatabaseResetService::ALL_TABLES;
        sort($allowList, SORT_STRING);
        assert_same($tables, $allowList, 'ALL_TABLES must name every schema table exactly once');

        /* The fresh-install source of truth and the generated bundle both
         * create 33 tables. */
        $creates = static function (string $file): int {
            $count = 0;

            foreach (SqlScript::fromFile($file)->statements() as $statement) {
                if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?/i', $statement['sql']) === 1) {
                    $count++;
                }
            }

            return $count;
        };

        assert_same(33, $creates($appRoot . '/database/schema.sql'), 'schema.sql creates 33 tables');
        assert_same(33, $creates($appRoot . '/database/install.sql'), 'the bundle creates 33 tables');

        /* The two royalty tables are money: a reset empties them. */
        foreach (['royalty_awards', 'royalty_periods'] as $table) {
            assert_true(in_array($table, DatabaseResetService::ZERO_TABLES, true), 'a reset must zero ' . $table);
        }

        assert_true(in_array('royalty_awards', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_false(in_array('royalty_periods', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
    },
];
