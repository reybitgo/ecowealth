<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestFoundationTest — Phase 21A (plan §5)
 *
 *  Proves the foundation phase on its own: the Phase 21 schema,
 *  the two settings, the ten seeded ladder amounts, the board
 *  singleton, the wallet vocabulary and the install/reset tool
 *  expectations. Nothing in this file pays money — 21A deliberately
 *  ships no reader, so the cases assert that the new objects are
 *  inert as well as correct.
 *
 *      migration   the three tables, their anchors, foreign keys and
 *                  CHECKs; the extended enum; the two settings, the
 *                  ten reference amounts and the unarmed board row
 *      idempotency a second run is a no-op, keeps an operator's
 *                  amount and setting, and never re-arms a board
 *      parity      a migrated database and a fresh install agree
 *                  (the full comparison is MigrationTest's P21A-T3)
 *      seed        seed 001 carries the two keys, the ten ladder rows
 *                  and the board row, exactly like the migration
 *      bundle      install.sql matches its sources and mirrors the
 *                  migration's objects (39 tables, 79 settings)
 *      tools       DatabaseInstaller / DatabaseResetService agree
 *                  with the schema (39 tables; the award ledger is
 *                  transactional, the ladder and the board are not)
 *      vocabulary  WalletService::REF_TYPES, the label and the icon
 *      defaults    off and unarmed; the ladder is catalogue; no
 *                  backfill of any kind
 *      guards      the singleton, the unique money anchor and the
 *                  CHECKs
 *      inert       nothing reads or writes the tables in 21A
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestFoundation
 * ============================================================== */

$appRoot      = dirname(__DIR__, 2);
$migrationDir = $appRoot . '/database/migrations/';
$fixtureFile  = __DIR__ . '/../fixtures/legacy_phase0_schema.sql';

/** The migrations a p21-start database has: 001 … 024, in order. */
$startMigrations = static function () use ($migrationDir): array {
    $files = [];

    foreach (glob($migrationDir . '*.sql') ?: [] as $file) {
        $name = basename($file);

        if (preg_match('/^0(0[1-9]|1[0-9]|2[0-4])_/', $name) === 1) {
            $files[] = $file;
        }
    }

    sort($files, SORT_STRING);

    return $files;
};

/** Build a p21-start database (the legacy fixture + migrations 001–024). */
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
 * Build once and keep the connection: a full 001–025 replay is the most
 * expensive fixture in the suite, and every case only reads it (T2 and
 * T12 restore what they touch).
 */
$foundation = null;
$buildFoundation = static function () use (&$foundation, $buildStart, $migrationDir): PDO {
    if ($foundation instanceof PDO) {
        return $foundation;
    }

    $pdo = $buildStart();
    test_run_sql_file($pdo, $migrationDir . '025_general_harvest.sql');
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

/** One foreign key of one table, or null. */
$foreignKey = static function (PDO $pdo, string $table, string $name): ?array {
    foreach (test_foreign_key_list($pdo)[$table] ?? [] as $candidate) {
        if ($candidate['name'] === $name) {
            return $candidate;
        }
    }

    return null;
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

/** A bare member row for the guard/cascade cases. */
$makeMember = static function (PDO $pdo, string $username): int {
    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, referral_code, is_active)
         VALUES (?, ?, ?, ?, 'member', 'active', ?, 1)"
    )->execute([
        $username,
        $username . '@p21a.test',
        password_hash('P21aMember@123', PASSWORD_BCRYPT),
        ucfirst($username) . ' Test',
        strtoupper(bin2hex(random_bytes(4))),
    ]);

    return (int) $pdo->lastInsertId();
};

/**
 * Insert one `general_harvest_awards` row, valid unless an override breaks a rule.
 *
 * @param array<string,string|int|null> $overrides
 */
$insertAward = static function (PDO $pdo, int $userId, array $overrides = []): int {
    $row = $overrides + [
        'seq'         => 0,
        'level'       => 1,
        'threshold_n' => 4,
        'n_at_award'  => 4,
        'amount'      => '2000.00',
        'status'      => 'credited',
        'ledger_id'   => null,
        'credited_at' => '2026-11-16 09:00:00',
    ];

    $pdo->prepare(
        'INSERT INTO general_harvest_awards
           (seq, user_id, level, threshold_n, n_at_award, amount, status, ledger_id, credited_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int) $row['seq'],
        $userId,
        (int) $row['level'],
        (int) $row['threshold_n'],
        (int) $row['n_at_award'],
        (string) $row['amount'],
        (string) $row['status'],
        $row['ledger_id'] === null ? null : (int) $row['ledger_id'],
        (string) $row['credited_at'],
    ]);

    return (int) $pdo->lastInsertId();
};

/** The two Phase 21 settings and their documented defaults. */
$newSettings = [
    'general_harvest_enabled'    => '0',
    'general_harvest_max_levels' => '10',
];

/** The ten reference amounts, level => amount (plan §12.4, Appendix A). */
$ladder = [
    1 => '2000.00',
    2 => '4000.00',
    3 => '8000.00',
    4 => '12000.00',
    5 => '25000.00',
    6 => '100000.00',
    7 => '250000.00',
    8 => '480000.00',
    9 => '2500000.00',
    10 => '5000000.00',
];

/** The unarmed board state, as one comparable array. */
$unarmed = static fn (PDO $pdo): array => (static function (array|false $row): array {
    if ($row === false) {
        return [];
    }

    return [
        'id'         => (int) $row['id'],
        'baseline_n' => $row['baseline_n'] === null ? null : (int) $row['baseline_n'],
        'armed_at'   => $row['armed_at'],
        'armed_by'   => $row['armed_by'] === null ? null : (int) $row['armed_by'],
        'settled_n'  => (int) $row['settled_n'],
    ];
})($pdo->query('SELECT id, baseline_n, armed_at, armed_by, settled_n FROM general_harvest_board WHERE id = 1')->fetch());

return [
    /* ==========================================================
     *  Schema (P21A-T1)
     * ========================================================== */
    'P21A-T1 migration 025 adds the three tables, their anchors, foreign keys and checks' => function () use ($buildStart, $migrationDir, $index, $checks, $foreignKey, $setting, $unarmed): void {
        /* Before 025 the feature does not exist. */
        $start = $buildStart();
        assert_same(
            [],
            array_values(array_filter(
                test_table_list($start),
                static fn (string $table): bool => str_starts_with($table, 'general_harvest')
            )),
            'a p21-start database carries no General Harvest table'
        );

        $pdo = $buildStart();
        test_run_sql_file($pdo, $migrationDir . '025_general_harvest.sql');

        $tables = test_table_list($pdo);

        foreach (['general_harvest_levels', 'general_harvest_board', 'general_harvest_awards'] as $table) {
            assert_true(in_array($table, $tables, true), $table . ' is missing after migration 025');
        }

        /* general_harvest_levels: a natural primary key, ten rows, no counter. */
        assert_same(['level'], array_column($index($pdo, 'general_harvest_levels', 'PRIMARY')['columns'], 'column'));

        /* general_harvest_board: the singleton is its primary key. */
        assert_same(['id'], array_column($index($pdo, 'general_harvest_board', 'PRIMARY')['columns'], 'column'));

        /* general_harvest_awards: the id is the wallet ref_id and the money
         * anchor is (node, level) — the node, not the member (D-04). */
        $anchor = $index($pdo, 'general_harvest_awards', 'uq_gha_seq_level');
        assert_not_null($anchor, 'uq_gha_seq_level is missing');
        assert_true($anchor['unique'], 'the money anchor is unique');
        assert_same(['seq', 'level'], array_column($anchor['columns'], 'column'));

        foreach (['idx_gha_user', 'idx_gha_level', 'idx_gha_credited', 'idx_gha_status', 'idx_gha_ledger'] as $name) {
            assert_not_null($index($pdo, 'general_harvest_awards', $name), $name . ' is missing');
        }

        /* The award id must fit the wallet's ref_id (INT UNSIGNED). */
        $awardId = null;

        foreach ($pdo->query('SHOW COLUMNS FROM general_harvest_awards')->fetchAll() as $column) {
            if ($column['Field'] === 'id') {
                $awardId = $column;
            }
        }

        assert_not_null($awardId, 'general_harvest_awards.id is missing');
        assert_same('int unsigned', $awardId['Type']);
        assert_same('auto_increment', $awardId['Extra']);

        /* The 21A manual gate's column lists (QA21A-02). */
        assert_same(
            ['level', 'amount', 'updated_at', 'updated_by'],
            array_column($pdo->query('SHOW COLUMNS FROM general_harvest_levels')->fetchAll(), 'Field')
        );
        assert_same(
            ['id', 'baseline_n', 'armed_at', 'armed_by', 'settled_n', 'updated_at'],
            array_column($pdo->query('SHOW COLUMNS FROM general_harvest_board')->fetchAll(), 'Field')
        );
        assert_same(
            ['id', 'seq', 'user_id', 'level', 'threshold_n', 'n_at_award', 'amount',
                'status', 'ledger_id', 'credited_at', 'created_at'],
            array_column($pdo->query('SHOW COLUMNS FROM general_harvest_awards')->fetchAll(), 'Field')
        );

        /* Foreign keys: the award and the arm actor follow their account;
         * the ladder references no user at all (it is catalogue). */
        assert_same('CASCADE', $foreignKey($pdo, 'general_harvest_awards', 'fk_gha_user')['delete_rule']);
        assert_same('users', $foreignKey($pdo, 'general_harvest_awards', 'fk_gha_user')['referenced_table']);
        assert_same('SET NULL', $foreignKey($pdo, 'general_harvest_board', 'fk_ghb_armed_by')['delete_rule']);
        assert_same('users', $foreignKey($pdo, 'general_harvest_board', 'fk_ghb_armed_by')['referenced_table']);
        assert_null($foreignKey($pdo, 'general_harvest_levels', 'fk_ghl_user'), 'the ladder references no account');
        assert_null($foreignKey($pdo, 'general_harvest_awards', 'fk_gha_ledger'), 'ledger_id is deliberately not an FK');

        /* The CHECKs the plan names (§13.2–§13.4). */
        assert_same(['chk_ghl_amount', 'chk_ghl_level'], $checks($pdo, 'general_harvest_levels'));
        assert_same(['chk_ghb_armed', 'chk_ghb_settled', 'chk_ghb_singleton'], $checks($pdo, 'general_harvest_board'));
        assert_same(
            ['chk_gha_amount', 'chk_gha_baseline', 'chk_gha_level', 'chk_gha_n'],
            $checks($pdo, 'general_harvest_awards')
        );

        /* No backfill (Rule 5): the ladder and the board are seeded, the
         * ledger is empty and no user gained a row. */
        assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn(), 'no award backfill');
        assert_same(
            ['id' => 1, 'baseline_n' => null, 'armed_at' => null, 'armed_by' => null, 'settled_n' => 0],
            $unarmed($pdo),
            'the board is one unarmed row'
        );
        assert_same('0', $setting($pdo, 'general_harvest_enabled'));
        assert_same('10', $setting($pdo, 'general_harvest_max_levels'));

        /* The enum appends the new movement last (Rule 7). */
        $refType = (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'];
        assert_contains("'general_harvest'", $refType);
        assert_true(str_ends_with($refType, "'general_harvest')"), 'general_harvest is the last enum value');
        assert_same(1, substr_count($refType, "'general_harvest'"), 'the enum names general_harvest exactly once');
    },

    /* ==========================================================
     *  Idempotency (P21A-T2)
     * ========================================================== */
    'P21A-T2 a second run of migration 025 is a no-op and never re-arms a board' => function () use ($buildFoundation, $migrationDir, $setting, $unarmed): void {
        $pdo = $buildFoundation();

        $snapshot = static fn (PDO $pdo): array => [
            'tables'   => test_table_list($pdo),
            'columns'  => test_column_list($pdo),
            'indexes'  => test_index_list($pdo),
            'foreign'  => test_foreign_key_list($pdo),
            'settings' => (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
            'ladder'   => $pdo->query('SELECT level, amount FROM general_harvest_levels ORDER BY level')
                ->fetchAll(PDO::FETCH_KEY_PAIR),
            'board'    => $pdo->query('SELECT baseline_n, armed_at, armed_by, settled_n FROM general_harvest_board')
                ->fetchAll(),
            'enum'     => (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'],
        ];

        $before = $snapshot($pdo);

        /* An operator's edit and an armed board must survive a redeploy
         * (Rules 1, 9 and the board-insert design). */
        $pdo->exec("UPDATE general_harvest_levels SET amount = '99000.00' WHERE level = 1");
        $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'general_harvest_enabled'");
        $pdo->exec("UPDATE settings SET value = '4' WHERE key_name = 'general_harvest_max_levels'");
        $pdo->exec(
            "UPDATE general_harvest_board
                SET baseline_n = 40, armed_at = '2026-11-16 10:00:00', settled_n = 40
              WHERE id = 1"
        );

        try {
            test_run_sql_file($pdo, $migrationDir . '025_general_harvest.sql');

            $after = $snapshot($pdo);

            assert_same($before['tables'], $after['tables'], 'a re-run creates no table');
            assert_same($before['columns'], $after['columns'], 'a re-run changes no column');
            assert_same($before['indexes'], $after['indexes'], 'a re-run changes no index');
            assert_same($before['foreign'], $after['foreign'], 'a re-run changes no foreign key');
            assert_same($before['settings'], $after['settings'], 'a re-run adds no setting');
            assert_same($before['enum'], $after['enum'], 'a re-run does not disturb the enum');

            assert_same(
                '99000.00',
                (string) $pdo->query('SELECT amount FROM general_harvest_levels WHERE level = 1')->fetchColumn(),
                'a re-run never overwrites an operator amount'
            );
            assert_same('1', $setting($pdo, 'general_harvest_enabled'), 'a re-run never overwrites an operator setting');
            assert_same('4', $setting($pdo, 'general_harvest_max_levels'));

            $armed = $unarmed($pdo);
            assert_same(40, $armed['baseline_n'], 'a re-run never re-arms a board');
            assert_same(40, $armed['settled_n']);
            assert_same('2026-11-16 10:00:00', (string) $armed['armed_at']);

            assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn());
            assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_board')->fetchColumn());
            assert_same(
                2,
                (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'general\\_harvest\\_%'")->fetchColumn(),
                'the settings insert stays idempotent'
            );

            /* A lost table is recreated by the same guarded file. */
            $pdo->exec('DROP TABLE IF EXISTS general_harvest_awards');
            test_run_sql_file($pdo, $migrationDir . '025_general_harvest.sql');

            assert_true(
                in_array('general_harvest_awards', test_table_list($pdo), true),
                'a re-run recreates a missing table (CREATE TABLE IF NOT EXISTS)'
            );
        } finally {
            /* Leave the shared fixture at its documented defaults. */
            $pdo->exec("UPDATE general_harvest_levels SET amount = '2000.00' WHERE level = 1");
            $pdo->exec("UPDATE settings SET value = '0' WHERE key_name = 'general_harvest_enabled'");
            $pdo->exec("UPDATE settings SET value = '10' WHERE key_name = 'general_harvest_max_levels'");
            $pdo->exec(
                'UPDATE general_harvest_board
                    SET baseline_n = NULL, armed_at = NULL, armed_by = NULL, settled_n = 0
                  WHERE id = 1'
            );
        }
    },

    /* ==========================================================
     *  Seed parity (P21A-T4)
     * ========================================================== */
    'P21A-T4 seed 001 carries the two keys, the ten ladder rows and the board row' => function () use ($appRoot, $newSettings, $ladder, $unarmed): void {
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

        $harvestKeys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => str_starts_with($key, 'general_harvest_')
        ));

        assert_count(2, $harvestKeys, 'seed 001 carries exactly the two Phase 21 keys');

        foreach (array_keys($newSettings) as $key) {
            assert_true(in_array($key, $keys, true), 'seed 001 carries ' . $key);
        }

        assert_same(count($keys), count(array_unique($keys)), 'seed 001 has no duplicate key');

        /* The ladder insert: ten rows with the reference amounts. */
        $rows = [];

        foreach (SqlScript::fromFile($appRoot . '/database/seeds/001_settings.sql')->statements() as $statement) {
            if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?general_harvest_levels`?\b/i', $statement['sql']) !== 1) {
                continue;
            }

            if (preg_match_all('/\(\s*(\d+)\s*,\s*([0-9.]+)\s*\)/', $statement['sql'], $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $rows[(int) $match[1]] = number_format((float) $match[2], 2, '.', '');
                }
            }
        }

        assert_same($ladder, $rows, 'seed 001 carries the ten reference amounts, level by level');

        /* The board insert: exactly one unarmed row. */
        $boardInsert = null;

        foreach (SqlScript::fromFile($appRoot . '/database/seeds/001_settings.sql')->statements() as $statement) {
            if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?general_harvest_board`?\b/i', $statement['sql']) === 1) {
                $boardInsert = $statement['sql'];
            }
        }

        assert_not_null($boardInsert, 'seed 001 creates the board singleton');
        assert_contains('VALUES (1, NULL, NULL, NULL, 0)', (string) $boardInsert, 'the board row ships unarmed');
        assert_contains('ON DUPLICATE KEY UPDATE id = id', (string) $boardInsert, 'a re-run never disturbs it');

        /* The seed and the live fresh install agree. */
        $fresh = test_pdo(['db' => DB_NAME]);

        foreach ($newSettings as $key => $expected) {
            $statement = $fresh->prepare('SELECT value FROM settings WHERE key_name = ?');
            $statement->execute([$key]);

            assert_same($expected, (string) $statement->fetchColumn(), 'the fresh install carries ' . $key);
        }

        $live = $fresh->query('SELECT level, amount FROM general_harvest_levels ORDER BY level')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $live = array_map(static fn (mixed $amount): string => number_format((float) $amount, 2, '.', ''), $live);

        assert_same($ladder, $live, 'the fresh install carries the ten reference amounts');

        assert_same(
            ['id' => 1, 'baseline_n' => null, 'armed_at' => null, 'armed_by' => null, 'settled_n' => 0],
            $unarmed($fresh),
            'the fresh install carries the unarmed board row'
        );
    },

    /* ==========================================================
     *  The bundle (P21A-T5)
     * ========================================================== */
    'P21A-T5 the committed install bundle matches its sources and carries 39 tables and 79 settings' => function () use ($appRoot): void {
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

        assert_contains('39 tables', $header);
        assert_contains('79 settings', $header);
        assert_contains('10 General Harvest level amounts', $header);
        assert_contains('tables_created = 39', $footer);
        assert_contains('settings_rows = 79', $footer);
        assert_contains('general_harvest_levels_rows = 10', $footer);
        assert_contains('general_harvest_board_rows = 1', $footer);
        assert_contains('39 tables', $bundle);
        assert_contains('79 settings', $bundle);

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

        assert_same(39, $creates, 'the bundle creates 39 tables');

        foreach (['general_harvest_levels', 'general_harvest_board', 'general_harvest_awards'] as $table) {
            assert_true(in_array($table, $created, true), 'the bundle creates ' . $table);
        }

        /* The bundle seeds the ladder and the board through the settings
         * section, and prints the board state in the verification pack. */
        assert_contains('INSERT INTO general_harvest_levels', $bundle);
        assert_contains('INSERT INTO general_harvest_board', $bundle);
        assert_contains('FROM general_harvest_board', $bundle);
    },

    /* ==========================================================
     *  Fresh install (P21A-T6, P21A-T7)
     * ========================================================== */
    'P21A-T6 a fresh install reports 39 tables, 79 settings, ten ladder rows and one unarmed board row' => function (): void {
        assert_same(39, DatabaseInstaller::EXPECTED['tables_created']);
        assert_same(79, DatabaseInstaller::EXPECTED['settings_rows']);
        assert_same(10, DatabaseInstaller::EXPECTED['team_harvest_levels_rows']);
        assert_same(10, DatabaseInstaller::EXPECTED['general_harvest_levels_rows']);
        assert_same(1, DatabaseInstaller::EXPECTED['general_harvest_board_rows']);
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
                'site_name'  => 'EcoWealth GeneralHarvestFoundationTest',
                'channel'    => 'cli',
            ]);

            assert_true(
                $report['ok'],
                'the install reports ok: ' . json_encode($report['verification']['checks'] ?? [])
            );

            assert_same(39, $report['counts']['tables_created']);
            assert_same(79, $report['counts']['settings_rows']);
            assert_same(10, $report['counts']['general_harvest_levels_rows']);
            assert_same(1, $report['counts']['general_harvest_board_rows']);

            /* The database itself, not just the report. */
            assert_count(39, test_table_list($pdo, $database));
            assert_same(79, (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
            assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn());

            /* The verification carries the board check, unarmed. */
            $checks = [];

            foreach ($report['verification']['checks'] as $check) {
                $checks[(string) $check['key']] = $check;
            }

            assert_true(isset($checks['general_harvest']), 'the verification carries the General Harvest check');
            assert_true($checks['general_harvest']['ok']);
            assert_contains('unarmed', (string) $checks['general_harvest']['detail']);

            /* Off and unarmed out of the box (Rule 4). */
            assert_same('0', (string) $pdo->query(
                "SELECT value FROM settings WHERE key_name = 'general_harvest_enabled'"
            )->fetchColumn());
            assert_same('10', (string) $pdo->query(
                "SELECT value FROM settings WHERE key_name = 'general_harvest_max_levels'"
            )->fetchColumn());

            $board = $pdo->query('SELECT baseline_n, armed_at, armed_by, settled_n FROM general_harvest_board')->fetch();
            assert_null($board['baseline_n']);
            assert_null($board['armed_at']);
            assert_null($board['armed_by']);
            assert_same(0, (int) $board['settled_n']);
        } finally {
            if (is_file($lockPath)) {
                @unlink($lockPath);
            }
        }
    },

    'P21A-T7 the transactional guard fails with the award ledger named' => function (): void {
        $lockPath = DatabaseInstaller::lockPath();

        if (is_file($lockPath)) {
            @unlink($lockPath);
        }

        [$pdo, $database] = test_scratch_database('tool_b');

        try {
            $installer = new DatabaseInstaller(DbTarget::fromConfig(), null, APP_ENV);
            $report    = $installer->install([
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
                'channel'    => 'cli',
            ]);

            assert_true($report['ok'], 'the fixture install is clean');

            /* One award row is enough to make the zero pack fail. */
            $staffId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
            $pdo->exec(
                'INSERT INTO general_harvest_awards
                   (seq, user_id, level, threshold_n, n_at_award, amount, status, credited_at)
                 VALUES (0, ' . $staffId . ", 1, 4, 4, 2000.00, 'baseline', '2026-11-16 09:00:00')"
            );

            $verify = $installer->verify($pdo, ['database' => $database]);
            $checks = [];

            foreach ($verify['checks'] as $check) {
                $checks[(string) $check['key']] = $check;
            }

            assert_false($verify['ok'], 'an award row fails verification');
            assert_true(isset($checks['transactional']), 'the verification carries the transactional check');
            assert_false($checks['transactional']['ok']);
            assert_contains('general_harvest_awards=1', (string) $checks['transactional']['detail']);

            /* The ladder and the board are not transactional: filling them
             * is legal and the install still verifies (once the ledger is
             * emptied again). */
            $pdo->exec('DELETE FROM general_harvest_awards');
            $pdo->exec("UPDATE general_harvest_levels SET amount = '1234.00' WHERE level = 1");
            $pdo->exec("UPDATE general_harvest_board SET baseline_n = 1, armed_at = NOW(), settled_n = 1 WHERE id = 1");

            /* ... but an ARMED board must fail its own check (21A Rule 4). */
            $armed = $installer->verify($pdo, ['database' => $database]);
            $armedChecks = [];

            foreach ($armed['checks'] as $check) {
                $armedChecks[(string) $check['key']] = $check;
            }

            assert_false($armed['ok'], 'an armed board fails verification');
            assert_false($armedChecks['general_harvest']['ok']);
            assert_contains('not unarmed', (string) $armedChecks['general_harvest']['detail']);

            $pdo->exec('UPDATE general_harvest_board SET baseline_n = NULL, armed_at = NULL, settled_n = 0 WHERE id = 1');

            $again = $installer->verify($pdo, ['database' => $database]);
            assert_true($again['ok'], 'the ladder and the unarmed board stay non-transactional');
        } finally {
            if (is_file($lockPath)) {
                @unlink($lockPath);
            }
        }
    },

    /* ==========================================================
     *  Reset parity (P21A-T8)
     * ========================================================== */
    'P21A-T8 a business reset empties the ledger and returns the board to unarmed' => function () use ($appRoot, $insertAward, $nextId, $unarmed): void {
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

        $juan = (int) $pdo->query("SELECT id FROM users WHERE username = 'juan'")->fetchColumn();
        assert_true($juan > 0, 'the demo tree is present');

        $insertAward($pdo, $juan, ['seq' => 0, 'level' => 1]);
        $insertAward($pdo, $juan, [
            'seq' => 0, 'level' => 2, 'threshold_n' => 13, 'n_at_award' => 13,
            'status' => 'baseline', 'amount' => '4000.00',
        ]);

        $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'general_harvest_enabled'");
        $pdo->exec("UPDATE general_harvest_levels SET amount = '2500.00' WHERE level = 1");
        $pdo->exec(
            "UPDATE general_harvest_board
                SET baseline_n = 40, armed_at = '2026-11-16 10:00:00',
                    armed_by = (SELECT id FROM users WHERE role = 'admin' LIMIT 1),
                    settled_n = 121
              WHERE id = 1"
        );

        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn());

        $settingsBefore = $pdo->query('SELECT key_name, value FROM settings ORDER BY key_name')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $report = (new DatabaseResetService(
            $pdo,
            $database,
            ['confirm' => $database, 'tool' => 'cli']
        ))->run();

        assert_true($report['ok'], 'the reset verifies: ' . json_encode($report['verification']['checks']));

        /* Rule 8: the ledger is emptied, the board is unarmed and the ten
         * ladder rows plus the two settings survive (I-12). */
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn(), 'the ledger is emptied');
        assert_same(
            ['id' => 1, 'baseline_n' => null, 'armed_at' => null, 'armed_by' => null, 'settled_n' => 0],
            $unarmed($pdo),
            'the board row is reset, not deleted'
        );
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_board')->fetchColumn());
        assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn(), 'the ladder is kept');
        assert_same(
            '2500.00',
            number_format((float) $pdo->query('SELECT amount FROM general_harvest_levels WHERE level = 1')->fetchColumn(), 2, '.', ''),
            'an operator amount survives a reset'
        );

        $settingsAfter = $pdo->query('SELECT key_name, value FROM settings ORDER BY key_name')->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($settingsBefore as $key => $value) {
            assert_true(array_key_exists($key, $settingsAfter), 'setting "' . $key . '" is kept');
            assert_same((string) $value, (string) $settingsAfter[$key], 'setting "' . $key . '" is unchanged');
        }

        assert_same(
            2,
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'general\\_harvest\\_%'")->fetchColumn(),
            'the two settings survive a business reset'
        );

        /* The reset reports the new delete group and the board step. */
        $steps = [];

        foreach ($report['steps'] as $step) {
            $steps[(string) $step['key']] = $step;
        }

        assert_true(isset($steps['general_harvest']), 'the reset reports a General Harvest step');
        assert_same(2, (int) $steps['general_harvest']['deleted'], 'the step deleted both awards');
        assert_same('ok', (string) $steps['general_harvest_board']['status'], 'the board step ran');
        assert_same(1, (int) $steps['general_harvest_board']['rows'], 'exactly one board row was reset');

        $checks = [];

        foreach ($report['verification']['checks'] as $check) {
            $checks[(string) $check['key']] = $check;
        }

        assert_true($checks['zero_transactional']['ok'], 'the zero pack is clean');
        assert_same(0, $report['auto_increment']['failed']);

        /* The counter restarted: the next award is 1. */
        $afterAward = $nextId($pdo, 'general_harvest_awards');

        assert_true($afterAward === null || $afterAward === 1, 'the award counter is reset, got ' . var_export($afterAward, true));

        assert_same(39, count(DatabaseResetService::ALL_TABLES), 'ALL_TABLES names 39 tables');
        assert_true(in_array('general_harvest_awards', DatabaseResetService::ZERO_TABLES, true));
        assert_true(in_array('general_harvest_awards', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_false(in_array('general_harvest_board', DatabaseResetService::ZERO_TABLES, true), 'the board is state, not money');
        assert_false(in_array('general_harvest_board', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_false(in_array('general_harvest_levels', DatabaseResetService::ZERO_TABLES, true), 'the ladder is catalogue');
    },

    /* ==========================================================
     *  The wallet vocabulary (P21A-T9, P21A-T10)
     * ========================================================== */
    'P21A-T9 the ledger enum and WalletService::REF_TYPES agree in both directions' => function (): void {
        $column = db()->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch();
        assert_not_null($column, 'the ledger table must exist');

        preg_match_all("/'([^']+)'/", (string) $column['Type'], $matches);
        $dbValues = $matches[1];

        $php = WalletService::REF_TYPES;

        assert_count(13, $php, 'thirteen movement kinds after Phase 21 (21A adds general_harvest only)');
        assert_same($php, array_values(array_unique($php)), 'no duplicate movement kind');
        assert_same('general_harvest', end($php), 'general_harvest is appended last, after team_harvest');
        assert_same('general_harvest', end($dbValues), 'the enum appends general_harvest last');
        assert_same(
            1,
            count(array_keys($dbValues, 'general_harvest', true)),
            'the enum names general_harvest exactly once'
        );
        assert_false(in_array('general_harvest_reversal', $dbValues, true), 'D-13: there is no reversal type');

        sort($dbValues);
        sort($php);

        assert_same($dbValues, $php, 'the PHP list and the column must agree in both directions');

        /* The vocabulary stays closed: an unknown value is refused before
         * any write, exactly like a forward stub. */
        $error = assert_throws(
            static fn (): array => (new WalletService())->credit(1, '1.00', 'general_harvest_reversal', 1),
            WalletException::class
        );

        assert_same('ref_type', $error->reason);
    },

    'P21A-T10 general_harvest renders as General Harvest bonus with a grid icon' => function (): void {
        assert_same('General Harvest bonus', wallet_ref_label('general_harvest'));
        assert_same('bx bx-grid-alt', wallet_ref_icon('general_harvest'));

        /* The label ships before the money: a row written by 21D must not
         * render as ucfirst('general_harvest'). */
        assert_not_same('General harvest', wallet_ref_label('general_harvest'));

        /* Every value in the vocabulary has a label and a bx- icon: the
         * wallet statement renders one filter option per entry (Rule 6). */
        foreach (WalletService::REF_TYPES as $refType) {
            assert_true(wallet_ref_label($refType) !== '', $refType . ' has a label');
            assert_same(1, preg_match('/^bx bx-[a-z0-9-]+$/', wallet_ref_icon($refType)), $refType . ' has a bx- icon');
        }

        /* The filter is the only visible change of this phase: no row can
         * match it until 21D. */
        assert_same(
            0,
            (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest'")->fetchColumn(),
            'nothing has ever paid a General Harvest movement'
        );
    },

    /* ==========================================================
     *  The defaults and the ladder (P21A-T11, P21A-T12)
     * ========================================================== */
    'P21A-T11 the two settings and the board defaults on a migrated and a fresh database' => function () use ($buildFoundation, $newSettings, $setting, $unarmed): void {
        $fresh    = test_pdo(['db' => DB_NAME]);
        $migrated = $buildFoundation();

        foreach ($newSettings as $key => $expected) {
            assert_same($expected, $setting($fresh, $key), 'a fresh install seeds ' . $key);
            assert_same($expected, $setting($migrated, $key), 'a migrated database seeds ' . $key);
        }

        foreach ([$fresh, $migrated] as $label => $pdo) {
            assert_same(
                2,
                (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'general\\_harvest\\_%'")->fetchColumn(),
                'exactly two General Harvest settings — no hidden stub, no baseline key'
            );

            assert_same(
                ['id' => 1, 'baseline_n' => null, 'armed_at' => null, 'armed_by' => null, 'settled_n' => 0],
                $unarmed($pdo),
                'the board ships unarmed (' . ($label === 0 ? 'fresh' : 'migrated') . ')'
            );
        }

        /* Rule 4: the stream is off and the board is unarmed, not merely
         * empty. No DATE_FORMAT(NOW()) may appear in the migration. */
        setting_cache_clear();
        assert_same('0', setting('general_harvest_enabled'), 'D-10: the stream ships off');
        assert_false(setting_bool('general_harvest_enabled', true), 'a missing/zero switch resolves to off');
        assert_same('10', setting('general_harvest_max_levels'));
        assert_null(setting('general_harvest_baseline_n'), 'there is deliberately no baseline key');

        $migration = SqlScript::fromFile(
            dirname(__DIR__, 2) . '/database/migrations/025_general_harvest.sql'
        );

        foreach ($migration->statements() as $statement) {
            assert_not_contains(
                'DATE_FORMAT(NOW()',
                $statement['sql'],
                'a migration must never pretend to have a go-live baseline (line ' . $statement['line'] . ')'
            );
            assert_not_contains(
                'FROM users',
                $statement['sql'],
                'no backfill reads the member table (line ' . $statement['line'] . ')'
            );
            assert_same(
                0,
                preg_match('/^\s*UPDATE\b/i', $statement['sql']),
                'the migration starts no UPDATE statement (line ' . $statement['line'] . ')'
            );
        }
    },

    'P21A-T12 the ten seeded amounts total 8,381,000.00 and an operator edit survives' => function () use ($buildFoundation, $migrationDir, $ladder): void {
        foreach ([test_pdo(['db' => DB_NAME]), $buildFoundation()] as $pdo) {
            $rows = $pdo->query('SELECT level, amount FROM general_harvest_levels ORDER BY level')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            $rows = array_map(
                static fn (mixed $amount): string => number_format((float) $amount, 2, '.', ''),
                $rows
            );

            assert_same($ladder, $rows, 'the ten reference amounts, level by level');

            $total = (float) $pdo->query('SELECT SUM(amount) FROM general_harvest_levels')->fetchColumn();
            assert_same(
                '8381000.00',
                number_format($total, 2, '.', ''),
                'the full ladder totals ₱8,381,000.00 per node'
            );
        }

        /* The reference ladder's arithmetic is pinned, not just its sum. */
        $expectedTotal = 0.0;

        foreach ($ladder as $amount) {
            $expectedTotal += (float) $amount;
        }

        assert_same(8381000.0, $expectedTotal);

        /* Rule 9: migration 025 is the only writer, and a re-run never
         * overwrites an operator's amount (P21A-T2 covers the settings). */
        $migrated = $buildFoundation();
        $migrated->exec("UPDATE general_harvest_levels SET amount = '7777.00' WHERE level = 4");

        try {
            test_run_sql_file($migrated, $migrationDir . '025_general_harvest.sql');
            assert_same(
                '7777.00',
                (string) $migrated->query('SELECT amount FROM general_harvest_levels WHERE level = 4')->fetchColumn(),
                'a deploy never overwrites a configured amount'
            );
        } finally {
            $migrated->exec("UPDATE general_harvest_levels SET amount = '12000.00' WHERE level = 4");
        }
    },

    /* ==========================================================
     *  The board and the guards (P21A-T13, P21A-T14)
     * ========================================================== */
    'P21A-T13 a second board row is refused by the primary key and by the singleton check' => function () use ($makeMember): void {
        $pdo = db();

        /* id = 2 is a free primary key, so only chk_ghb_singleton can stop
         * it — which is the point: there is exactly one board. */
        assert_throws(
            static fn (): int => $pdo->exec(
                'INSERT INTO general_harvest_board (id, baseline_n, armed_at, armed_by, settled_n)
                 VALUES (2, NULL, NULL, NULL, 0)'
            ),
            PDOException::class,
            'chk_ghb_singleton refuses a second board row'
        );

        /* id = 1 again is refused by the primary key. */
        assert_throws(
            static fn (): int => $pdo->exec(
                'INSERT INTO general_harvest_board (id, baseline_n, armed_at, armed_by, settled_n)
                 VALUES (1, NULL, NULL, NULL, 0)'
            ),
            PDOException::class,
            'the primary key refuses a duplicate singleton'
        );

        /* The armed/settled invariant: both columns move together (§13.2). */
        assert_throws(
            static fn (): int => $pdo->exec(
                "UPDATE general_harvest_board SET baseline_n = 10, armed_at = NULL WHERE id = 1"
            ),
            PDOException::class,
            'chk_ghb_armed refuses a baseline without an arm instant'
        );
        assert_throws(
            static fn (): int => $pdo->exec(
                "UPDATE general_harvest_board SET baseline_n = 10, armed_at = NOW(), settled_n = 3 WHERE id = 1"
            ),
            PDOException::class,
            'chk_ghb_settled refuses a settled length below the baseline'
        );

        /* A realistic arm round-trip is accepted, then undone. */
        $pdo->exec("UPDATE general_harvest_board SET baseline_n = 10, armed_at = NOW(), settled_n = 10 WHERE id = 1");
        assert_same(10, (int) $pdo->query('SELECT baseline_n FROM general_harvest_board')->fetchColumn());
        $pdo->exec('UPDATE general_harvest_board SET baseline_n = NULL, armed_at = NULL, settled_n = 0 WHERE id = 1');

        /* Deleting the arming staff member leaves the board armed but
         * unattributed (fk_ghb_armed_by ON DELETE SET NULL). */
        $staff = $makeMember($pdo, 'p21a_arm_staff');
        $pdo->exec(
            "UPDATE general_harvest_board
                SET baseline_n = 10, armed_at = NOW(), armed_by = {$staff}, settled_n = 10
              WHERE id = 1"
        );
        $pdo->exec("DELETE FROM users WHERE id = {$staff}");

        assert_null(
            $pdo->query('SELECT armed_by FROM general_harvest_board WHERE id = 1')->fetchColumn() ?: null,
            'the actor is cleared, the board stays armed'
        );
        assert_same(10, (int) $pdo->query('SELECT baseline_n FROM general_harvest_board')->fetchColumn());

        $pdo->exec('UPDATE general_harvest_board SET baseline_n = NULL, armed_at = NULL, settled_n = 0 WHERE id = 1');
    },

    'P21A-T14 deleting a member who holds an award cascades the ledger' => function () use ($makeMember, $insertAward): void {
        $pdo    = db();
        $member = $makeMember($pdo, 'p21a_cascade_member');

        $insertAward($pdo, $member, ['seq' => 0, 'level' => 1]);
        $insertAward($pdo, $member, ['seq' => 0, 'level' => 2, 'threshold_n' => 13, 'n_at_award' => 13, 'amount' => '4000.00']);

        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards WHERE user_id = ' . $member)->fetchColumn());

        $pdo->exec('DELETE FROM users WHERE id = ' . $member);

        assert_same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards WHERE user_id = ' . $member)->fetchColumn(),
            'the awards cascaded with their node holder'
        );

        /* The money anchor keeps working after the cascade: a new award at
         * the same (node, level) is accepted because the old row is gone. */
        $other = $makeMember($pdo, 'p21a_cascade_member2');
        assert_true($insertAward($pdo, $other, ['seq' => 0, 'level' => 1]) > 0);
    },

    /* ==========================================================
     *  One manifest, nothing reading it (P21A-T15, P21A-T16)
     * ========================================================== */
    'P21A-T15 no application file outside the manifest names the Phase 21 objects' => function () use ($appRoot): void {
        /* 21A ships tables with no writer on purpose, and each later phase
         * adds to the manifest (plan 21A Rule 10, P21A-T15). 21B adds the
         * configuration card and the pure arithmetic: two models, the
         * settings controller and the three settings views. **21C** adds the
         * ledger model, the service and the exception; **21D** the money
         * engine, **21E** the admin surfaces and **21F** the member
         * surfaces. Only the CLI job is outside /app. */
        $allowList = [
            /* 21A — foundation: the wallet vocabulary, the enum, the
             * installer and the reset service. */
            '/app/Services/WalletService.php',
            '/app/helpers.php',
            '/app/Services/DatabaseInstaller.php',
            '/app/Services/DatabaseResetService.php',
            /* 21B — the configuration card and the board arithmetic. */
            '/app/Models/GeneralHarvestLevel.php',
            '/app/Models/GeneralHarvestBoard.php',
            '/app/Controllers/AdminSettingsController.php',
            '/app/views/admin/settings/_general_harvest.php',
            '/app/views/admin/settings/_commissions.php',
            '/app/views/admin/settings/index.php',
            /* 21C — the ledger (read + baseline only), the service and
             * the refusal type the arm job throws. */
            '/app/Models/GeneralHarvestAward.php',
            '/app/Services/GeneralHarvestService.php',
            '/app/Services/GeneralHarvestException.php',
            /* 21D — the money engine: the registration hook and the
             * live-touch surface registration. */
            '/app/Controllers/EarningsController.php',
            '/app/Controllers/DashboardController.php',
            /* 21E — admin visibility: ledger, member panel, reports. */
            '/app/Controllers/AdminCommissionController.php',
            '/app/Controllers/AdminMemberController.php',
            '/app/Controllers/AdminReportController.php',
            '/app/Models/Member.php',
            '/app/Models/Report.php',
            '/app/Services/ReportService.php',
            /* 21E views — admin commission tab, member board card,
             * report cards and index pages. */
            '/app/views/admin/commissions/_general_harvest_header.php',
            '/app/views/admin/commissions/_general_harvest_levels.php',
            '/app/views/admin/commissions/_general_harvest_rows.php',
            '/app/views/admin/commissions/_general_harvest_stats.php',
            '/app/views/admin/commissions/index.php',
            '/app/views/admin/members/_general_harvest.php',
            '/app/views/admin/members/_general_harvest_board_card.php',
            '/app/views/admin/members/index.php',
            '/app/views/admin/members/show.php',
            '/app/views/admin/reports/_general_harvest.php',
            '/app/views/admin/reports/index.php',
            /* 21F views — member earnings tab, dashboard tile, genealogy. */
            '/app/views/member/dashboard.php',
            '/app/views/member/dashboard/_general_harvest.php',
            '/app/views/member/earnings.php',
            '/app/views/member/earnings/_general_blocks.php',
            '/app/views/member/earnings/_general_header.php',
            '/app/views/member/earnings/_general_pagination.php',
            '/app/views/member/earnings/_general_progress.php',
            '/app/views/member/earnings/_general_roster.php',
            '/app/views/member/earnings/_general_rows.php',
            '/app/views/member/earnings/_general_stats.php',
        ];

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appRoot . '/app', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot)));
            $source   = (string) file_get_contents($file->getPathname());

            if (preg_match('/general_harvest/i', $source) === 1) {
                $found[] = $relative;
            }
        }

        sort($found, SORT_STRING);
        sort($allowList, SORT_STRING);

        assert_same(
            $allowList,
            $found,
            'only the 21A/21B manifest names the General Harvest objects'
        );

        /* The later phases' files do not exist yet. */
        foreach ([
            '/app/Controllers/GeneralHarvestController.php',
        ] as $file) {
            assert_false(is_file($appRoot . $file), $file . ' is not part of 21C');
        }

        assert_true(is_file($appRoot . '/bin/general_harvest_arm.php'), 'the arm job is 21C');
        assert_true(is_file($appRoot . '/bin/general_harvest_catchup.php'), 'the catch-up job is 21D');
    },

    'P21A-T16 the phase adds no route and no page of its own: ParityTest and RouterTest stay green' => function () use ($appRoot): void {
        /* D-15: this stream adds no route. The route files and the route
         * inventory must not mention the feature, and after 21B the only
         * screens that render it are the settings card's three files: no
         * member page, no other admin page (§21E/21F own those). */
        foreach (['/app/routes.php', '/app/routes_inventory.php'] as $file) {
            assert_same(
                0,
                preg_match('/general_harvest/i', (string) file_get_contents($appRoot . $file)),
                $file . ' has no General Harvest route'
            );
        }

        $naming = [];
        $views  = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appRoot . '/app/views', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($views as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            if (preg_match('/general_harvest/i', (string) file_get_contents($file->getPathname())) === 1) {
                $naming[] = str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot)));
            }
        }

        sort($naming, SORT_STRING);

        assert_same(
            [
                /* Sorted to match the recursive directory scan order. */
                '/app/views/admin/commissions/_general_harvest_header.php',
                '/app/views/admin/commissions/_general_harvest_levels.php',
                '/app/views/admin/commissions/_general_harvest_rows.php',
                '/app/views/admin/commissions/_general_harvest_stats.php',
                '/app/views/admin/commissions/index.php',
                '/app/views/admin/members/_general_harvest.php',
                '/app/views/admin/members/_general_harvest_board_card.php',
                '/app/views/admin/members/index.php',
                '/app/views/admin/members/show.php',
                '/app/views/admin/reports/_general_harvest.php',
                '/app/views/admin/reports/index.php',
                '/app/views/admin/settings/_commissions.php',
                '/app/views/admin/settings/_general_harvest.php',
                '/app/views/admin/settings/index.php',
                '/app/views/member/dashboard.php',
                '/app/views/member/dashboard/_general_harvest.php',
                '/app/views/member/earnings.php',
                '/app/views/member/earnings/_general_blocks.php',
                '/app/views/member/earnings/_general_header.php',
                '/app/views/member/earnings/_general_pagination.php',
                '/app/views/member/earnings/_general_progress.php',
                '/app/views/member/earnings/_general_roster.php',
                '/app/views/member/earnings/_general_rows.php',
                '/app/views/member/earnings/_general_stats.php',
            ],
            $naming,
            'only the settings card, admin pages and member pages render the feature'
        );

        /* The phase is inert: a fresh install carries no award, no position
         * and no General Harvest wallet movement, and the stream is off. */
        $pdo = db();

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest'")->fetchColumn()
        );

        setting_cache_clear();
        assert_false(setting_bool('general_harvest_enabled', true));

        /* The suite's own count guard: the migration list ends at 025 and
         * the reset list, the schema and the bundle all agree at 39. */
        $migrations = array_map('basename', glob($appRoot . '/database/migrations/*.sql') ?: []);
        sort($migrations, SORT_STRING);

        assert_same('025_general_harvest.sql', end($migrations), '025 is the newest migration');
        assert_same(39, count(test_table_list(test_pdo(['db' => DB_NAME]))));
        assert_same(39, count(DatabaseResetService::ALL_TABLES));
        assert_same(39, DbTarget::fromConfig()->tableCount(DB_NAME));
    },
];
