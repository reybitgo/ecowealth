<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestFoundationTest — Phase 20A (plan §5)
 *
 *  Proves the foundation phase on its own: the Phase 20 schema,
 *  the four settings, the ten seeded ladder amounts, the wallet
 *  vocabulary and the install/reset tool expectations. Nothing in
 *  this file pays money — 20A deliberately ships no reader, so the
 *  cases assert that the new objects are inert as well as correct.
 *
 *      migration   the three tables, their anchors, foreign keys and
 *                  CHECKs; the extended enum; the four settings and
 *                  the ten reference amounts
 *      idempotency a second run is a no-op, keeps an operator's
 *                  amount and setting, and recreates a lost table
 *      parity      a migrated database and a fresh install agree
 *      bundle      install.sql matches its sources and mirrors the
 *                  migration's objects (39 tables, 79 settings)
 *      tools       DatabaseInstaller / DatabaseResetService agree
 *                  with the schema (39 tables; queue and awards are
 *                  transactional, the ladder is catalogue)
 *      vocabulary  WalletService::REF_TYPES, the label and the icon
 *      defaults    off and unseeded; no backfill; the ladder is kept
 *      guards      the cascades, the unique anchor and the CHECKs
 *      inert       nothing reads or writes the tables in 20A
 *
 *  Run this group alone with:  php tests/run.php TeamHarvestFoundation
 * ============================================================== */

$appRoot      = dirname(__DIR__, 2);
$migrationDir = $appRoot . '/database/migrations/';
$fixtureFile  = __DIR__ . '/../fixtures/legacy_phase0_schema.sql';

/** The migrations a p20-start database has: 001 … 023, in order. */
$startMigrations = static function () use ($migrationDir): array {
    $files = [];

    foreach (glob($migrationDir . '*.sql') ?: [] as $file) {
        $name = basename($file);

        if (preg_match('/^0(0[1-9]|1[0-9]|2[0-3])_/', $name) === 1) {
            $files[] = $file;
        }
    }

    sort($files, SORT_STRING);

    return $files;
};

/** Build a p20-start database (the legacy fixture + migrations 001–023). */
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
 * expensive fixture in the suite, and every case only reads it (T2, T12
 * and T13 restore what they touch).
 */
$foundation = null;
$buildFoundation = static function () use (&$foundation, $buildStart, $migrationDir): PDO {
    if ($foundation instanceof PDO) {
        return $foundation;
    }

    $pdo = $buildStart();
    test_run_sql_file($pdo, $migrationDir . '024_team_harvest.sql');
    /* Phase 21A appends 025 to the same chain: this file compares a migrated
     * database with the current fresh install, and schema.sql now carries
     * the three General Harvest tables. */
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
        $username . '@p20a.test',
        password_hash('P20aMember@123', PASSWORD_BCRYPT),
        ucfirst($username) . ' Test',
        strtoupper(bin2hex(random_bytes(4))),
    ]);

    return (int) $pdo->lastInsertId();
};

/**
 * Insert one `harvest_queue` row, valid unless an override breaks a rule.
 *
 * @param array<string,string|int> $overrides
 */
$insertQueue = static function (PDO $pdo, int $userId, array $overrides = []): int {
    $row = $overrides + [
        'qualified_at' => '2026-11-01 09:00:00',
        'directs'      => 3,
        'teammates'    => 0,
        'baseline_n'   => 0,
        'source'       => 'seed',
    ];

    $pdo->prepare(
        'INSERT INTO harvest_queue
           (user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        (string) $row['qualified_at'],
        (int) $row['directs'],
        (int) $row['teammates'],
        (int) $row['baseline_n'],
        (string) $row['source'],
    ]);

    return (int) $pdo->lastInsertId();
};

/**
 * Insert one `team_harvest_awards` row, valid unless an override breaks a rule.
 *
 * @param array<string,string|int|null> $overrides
 */
$insertAward = static function (PDO $pdo, int $userId, array $overrides = []): int {
    $row = $overrides + [
        'level'       => 1,
        'threshold_n' => 3,
        'n_at_award'  => 3,
        'amount'      => '1000.00',
        'status'      => 'credited',
        'ledger_id'   => null,
        'credited_at' => '2026-11-01 09:00:00',
    ];

    $pdo->prepare(
        'INSERT INTO team_harvest_awards
           (user_id, level, threshold_n, n_at_award, amount, status, ledger_id, credited_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
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

/** The four Phase 20 settings and their documented defaults. */
$newSettings = [
    'team_harvest_enabled'         => '0',
    'team_harvest_qualify_directs' => '3',
    'team_harvest_max_levels'      => '10',
    'team_harvest_ledger_since'    => '',
];

/** The ten reference amounts, level => amount (plan §12.6, Appendix A). */
$ladder = [
    1 => '1000.00',
    2 => '2000.00',
    3 => '4000.00',
    4 => '6000.00',
    5 => '12000.00',
    6 => '50000.00',
    7 => '120000.00',
    8 => '200000.00',
    9 => '1200000.00',
    10 => '3500000.00',
];

return [
    /* ==========================================================
     *  Schema (P20A-T1)
     * ========================================================== */
    'P20A-T1 migration 024 adds the three tables, their anchors, foreign keys and checks' => function () use ($buildFoundation, $index, $checks): void {
        $pdo    = $buildFoundation();
        $fresh  = test_pdo(['db' => DB_NAME]);
        $tables = test_table_list($pdo);

        foreach (['harvest_queue', 'team_harvest_levels', 'team_harvest_awards'] as $table) {
            assert_true(in_array($table, $tables, true), $table . ' is missing after migration 024');
        }

        /* harvest_queue.id IS the position (D-03): a 1-based sequence,
         * unique per member, indexed by instant and by the counter. */
        assert_same(['id'], array_column($index($pdo, 'harvest_queue', 'PRIMARY')['columns'], 'column'));

        $user = $index($pdo, 'harvest_queue', 'uq_queue_user');
        assert_not_null($user, 'uq_queue_user is missing');
        assert_true($user['unique'], 'a member is queued at most once');
        assert_same(['user_id'], array_column($user['columns'], 'column'));

        $qualified = $index($pdo, 'harvest_queue', 'idx_queue_qualified');
        assert_not_null($qualified, 'idx_queue_qualified is missing');
        assert_same(['qualified_at'], array_column($qualified['columns'], 'column'));

        $teammates = $index($pdo, 'harvest_queue', 'idx_queue_teammates');
        assert_not_null($teammates, 'idx_queue_teammates is missing');
        assert_same(['teammates'], array_column($teammates['columns'], 'column'));

        /* team_harvest_levels has a natural primary key: ten rows, no
         * counter. team_harvest_awards has both a sequence (its id is the
         * wallet ref_id) and the money anchor on (member, level). */
        assert_same(['level'], array_column($index($pdo, 'team_harvest_levels', 'PRIMARY')['columns'], 'column'));

        $anchor = $index($pdo, 'team_harvest_awards', 'uq_tha_member_level');
        assert_not_null($anchor, 'uq_tha_member_level is missing');
        assert_true($anchor['unique'], 'the money anchor is unique');
        assert_same(['user_id', 'level'], array_column($anchor['columns'], 'column'));

        foreach (['idx_tha_level', 'idx_tha_credited', 'idx_tha_status', 'idx_tha_ledger'] as $name) {
            assert_not_null($index($pdo, 'team_harvest_awards', $name), $name . ' is missing');
        }

        /* The award id must fit the wallet's ref_id (INT UNSIGNED). */
        $awardId = null;

        foreach ($pdo->query('SHOW COLUMNS FROM team_harvest_awards')->fetchAll() as $column) {
            if ($column['Field'] === 'id') {
                $awardId = $column;
            }
        }

        assert_not_null($awardId, 'team_harvest_awards.id is missing');
        assert_same('int unsigned', $awardId['Type']);
        assert_same('auto_increment', $awardId['Extra']);

        $queueId = null;

        foreach ($pdo->query('SHOW COLUMNS FROM harvest_queue')->fetchAll() as $column) {
            if ($column['Field'] === 'id') {
                $queueId = $column;
            }
        }

        assert_not_null($queueId, 'harvest_queue.id is missing');
        assert_same('int unsigned', $queueId['Type']);
        assert_same('auto_increment', $queueId['Extra']);

        /* The 20A manual gate's column lists (QA20A-02). */
        assert_same(
            ['id', 'user_id', 'qualified_at', 'directs_at_qualification', 'teammates', 'baseline_n', 'source', 'created_at'],
            array_column($pdo->query('SHOW COLUMNS FROM harvest_queue')->fetchAll(), 'Field')
        );
        assert_same(
            ['id', 'user_id', 'level', 'threshold_n', 'n_at_award', 'amount', 'status', 'ledger_id', 'credited_at', 'created_at'],
            array_column($pdo->query('SHOW COLUMNS FROM team_harvest_awards')->fetchAll(), 'Field')
        );
        assert_same(
            ['level', 'amount', 'updated_at', 'updated_by'],
            array_column($pdo->query('SHOW COLUMNS FROM team_harvest_levels')->fetchAll(), 'Field')
        );

        /* Foreign keys: both money tables cascade with their member. The
         * ladder has no user reference at all (it is catalogue). */
        $foreign = test_foreign_key_list($pdo);

        $byName = static function (string $table, string $name) use ($foreign): ?array {
            foreach ($foreign[$table] ?? [] as $foreignKey) {
                if ($foreignKey['name'] === $name) {
                    return $foreignKey;
                }
            }

            return null;
        };

        assert_same('CASCADE', $byName('harvest_queue', 'fk_hq_user')['delete_rule']);
        assert_same('users', $byName('harvest_queue', 'fk_hq_user')['referenced_table']);
        assert_same('CASCADE', $byName('team_harvest_awards', 'fk_tha_user')['delete_rule']);
        assert_same('users', $byName('team_harvest_awards', 'fk_tha_user')['referenced_table']);
        assert_null($byName('team_harvest_levels', 'fk_thl_user'), 'the ladder references no account');

        /* The CHECKs the plan names (§13.2–§13.4), on both sides. */
        assert_same(['chk_hq_baseline', 'chk_hq_directs'], $checks($pdo, 'harvest_queue'));
        assert_same(['chk_thl_amount', 'chk_thl_level'], $checks($pdo, 'team_harvest_levels'));
        assert_same(['chk_tha_amount', 'chk_tha_level', 'chk_tha_n'], $checks($pdo, 'team_harvest_awards'));
        assert_same($checks($fresh, 'harvest_queue'), $checks($pdo, 'harvest_queue'));
        assert_same($checks($fresh, 'team_harvest_levels'), $checks($pdo, 'team_harvest_levels'));
        assert_same($checks($fresh, 'team_harvest_awards'), $checks($pdo, 'team_harvest_awards'));

        /* No backfill: the migration creates the structure only (Rule 5).
         * The queue and the awards are empty; the ladder is seeded. */
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn(), 'no queue backfill');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn(), 'no award backfill');
        assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn());
    },

    /* ==========================================================
     *  Idempotency (P20A-T2)
     * ========================================================== */
    'P20A-T2 a second run of migration 024 is a no-op and recreates a lost table' => function () use ($buildFoundation, $migrationDir, $setting): void {
        $pdo = $buildFoundation();

        $snapshot = static fn (PDO $pdo): array => [
            'tables'   => test_table_list($pdo),
            'columns'  => test_column_list($pdo),
            'indexes'  => test_index_list($pdo),
            'foreign'  => test_foreign_key_list($pdo),
            'settings' => (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
            'ladder'   => $pdo->query('SELECT level, amount FROM team_harvest_levels ORDER BY level')
                ->fetchAll(PDO::FETCH_KEY_PAIR),
            'enum'     => (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'],
        ];

        $before = $snapshot($pdo);

        /* An operator's edit must survive a redeploy (Rule 9). */
        $pdo->exec("UPDATE team_harvest_levels SET amount = '99000.00' WHERE level = 1");
        $pdo->exec("UPDATE settings SET value = '5' WHERE key_name = 'team_harvest_qualify_directs'");
        $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'team_harvest_enabled'");

        try {
            test_run_sql_file($pdo, $migrationDir . '024_team_harvest.sql');

            assert_same($before['tables'], test_table_list($pdo), 'a re-run creates no table');
            assert_same($before['columns'], test_column_list($pdo), 'a re-run changes no column');
            assert_same($before['indexes'], test_index_list($pdo), 'a re-run changes no index');
            assert_same($before['foreign'], test_foreign_key_list($pdo), 'a re-run changes no foreign key');
            assert_same($before['settings'], (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
            assert_same($before['enum'], (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type']);

            assert_same(
                '99000.00',
                (string) $pdo->query('SELECT amount FROM team_harvest_levels WHERE level = 1')->fetchColumn(),
                'a re-run never overwrites an operator amount'
            );
            assert_same('5', $setting($pdo, 'team_harvest_qualify_directs'), 'a re-run never overwrites an operator setting');
            assert_same('1', $setting($pdo, 'team_harvest_enabled'));

            /* A lost table is recreated by the same guarded file. */
            $pdo->exec('DROP TABLE IF EXISTS team_harvest_awards');
            test_run_sql_file($pdo, $migrationDir . '024_team_harvest.sql');

            assert_true(
                in_array('team_harvest_awards', test_table_list($pdo), true),
                'a re-run recreates a missing table (CREATE TABLE IF NOT EXISTS)'
            );
            assert_same(
                4,
                (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'team\\_harvest\\_%'")->fetchColumn(),
                'the settings insert stays idempotent'
            );
            assert_same(
                10,
                (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn(),
                'the ladder insert stays idempotent'
            );
        } finally {
            /* Leave the shared fixture at its documented defaults. */
            $pdo->exec("UPDATE team_harvest_levels SET amount = '1000.00' WHERE level = 1");
            $pdo->exec("UPDATE settings SET value = '3' WHERE key_name = 'team_harvest_qualify_directs'");
            $pdo->exec("UPDATE settings SET value = '0' WHERE key_name = 'team_harvest_enabled'");
        }
    },

    /* ==========================================================
     *  Migrated = fresh (P20A-T3)
     * ========================================================== */
    'P20A-T3 the migrated schema and a fresh install are equivalent' => function () use ($buildFoundation, $checks): void {
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

        $enum = static fn (PDO $pdo): string
            => (string) $pdo->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch()['Type'];

        assert_same($enum($fresh), $enum($migrated), 'the ledger enum must equal the fresh-install enum');

        /* The three new tables carry the same CHECKs, which the column and
         * index helpers do not read. */
        foreach (['harvest_queue', 'team_harvest_levels', 'team_harvest_awards'] as $table) {
            assert_same($checks($fresh, $table), $checks($migrated, $table), $table . ' CHECK constraints differ');
        }

        /* 024 really is in the ordered migration list (the plan's T3), and a
         * later phase appends after it (21A's 025) — so 024 is no longer
         * last, but it must still be present and ordered. */
        $list = array_map('basename', glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: []);
        sort($list, SORT_STRING);

        assert_true(in_array('024_team_harvest.sql', $list, true), 'migration 024 is part of the ordered list');

        if (in_array('025_general_harvest.sql', $list, true)) {
            assert_true(
                array_search('024_team_harvest.sql', $list, true) < array_search('025_general_harvest.sql', $list, true),
                '024 precedes the migration that followed it'
            );
        }
    },

    /* ==========================================================
     *  Seed parity (P20A-T4)
     * ========================================================== */
    'P20A-T4 seed 001 carries the four keys and the ten ladder rows' => function () use ($appRoot, $newSettings, $ladder): void {
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
            static fn (string $key): bool => str_starts_with($key, 'team_harvest_')
        ));

        assert_count(4, $harvestKeys, 'seed 001 carries exactly the four Phase 20 keys');

        foreach (array_keys($newSettings) as $key) {
            assert_true(in_array($key, $keys, true), 'seed 001 carries ' . $key);
        }

        assert_same(count($keys), count(array_unique($keys)), 'seed 001 has no duplicate key');

        /* The ladder insert: ten rows with the reference amounts. */
        $rows = [];

        foreach (SqlScript::fromFile($appRoot . '/database/seeds/001_settings.sql')->statements() as $statement) {
            if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?team_harvest_levels`?\b/i', $statement['sql']) !== 1) {
                continue;
            }

            if (preg_match_all('/\(\s*(\d+)\s*,\s*([0-9.]+)\s*\)/', $statement['sql'], $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $rows[(int) $match[1]] = number_format((float) $match[2], 2, '.', '');
                }
            }
        }

        assert_same($ladder, $rows, 'seed 001 carries the ten reference amounts, level by level');

        /* The seed and the live fresh install agree. */
        $fresh = test_pdo(['db' => DB_NAME]);

        foreach ($newSettings as $key => $expected) {
            $statement = $fresh->prepare('SELECT value FROM settings WHERE key_name = ?');
            $statement->execute([$key]);

            assert_same($expected, (string) $statement->fetchColumn(), 'the fresh install carries ' . $key);
        }

        $live = $fresh->query('SELECT level, amount FROM team_harvest_levels ORDER BY level')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $live = array_map(static fn (mixed $amount): string => number_format((float) $amount, 2, '.', ''), $live);

        assert_same($ladder, $live, 'the fresh install carries the ten reference amounts');
    },

    /* ==========================================================
     *  The bundle (P20A-T5)
     * ========================================================== */
    'P20A-T5 the committed install bundle matches its sources and carries 39 tables and 79 settings' => function () use ($appRoot): void {
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
        assert_contains('10 Team Harvest level amounts', $header);
        assert_contains('tables_created = 39', $footer);
        assert_contains('settings_rows = 79', $footer);
        assert_contains('team_harvest_levels_rows = 10', $footer);
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

        foreach (['harvest_queue', 'team_harvest_levels', 'team_harvest_awards'] as $table) {
            assert_true(in_array($table, $created, true), 'the bundle creates ' . $table);
        }

        /* The bundle seeds the ladder through the settings section. */
        assert_contains('INSERT INTO team_harvest_levels', $bundle);
    },

    /* ==========================================================
     *  Fresh install (P20A-T6, P20A-T7)
     * ========================================================== */
    'P20A-T6 a fresh install reports 39 tables, 79 settings and ten ladder rows' => function (): void {
        assert_same(39, DatabaseInstaller::EXPECTED['tables_created']);
        assert_same(79, DatabaseInstaller::EXPECTED['settings_rows']);
        assert_same(10, DatabaseInstaller::EXPECTED['team_harvest_levels_rows']);
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
                'site_name'  => 'EcoWealth TeamHarvestFoundationTest',
                'channel'    => 'cli',
            ]);

            assert_true(
                $report['ok'],
                'the install reports ok: ' . json_encode($report['verification']['checks'] ?? [])
            );

            assert_same(39, $report['counts']['tables_created']);
            assert_same(79, $report['counts']['settings_rows']);
            assert_same(10, $report['counts']['team_harvest_levels_rows']);

            /* The database itself, not just the report. */
            assert_count(39, test_table_list($pdo, $database));
            assert_same(79, (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
            assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());

            /* Off and unseeded out of the box (Rule 4). */
            assert_same('0', (string) $pdo->query(
                "SELECT value FROM settings WHERE key_name = 'team_harvest_enabled'"
            )->fetchColumn());
            assert_same('', (string) $pdo->query(
                "SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since'"
            )->fetchColumn());
        } finally {
            if (is_file($lockPath)) {
                @unlink($lockPath);
            }
        }
    },

    'P20A-T7 the transactional guard fails with the queue table named' => function (): void {
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

            /* One queued position is enough to make the zero pack fail. */
            $staffId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
            $pdo->exec(
                'INSERT INTO harvest_queue (user_id, qualified_at, directs_at_qualification)
                 VALUES (' . $staffId . ", '2026-11-01 09:00:00', 3)"
            );

            $verify = $installer->verify($pdo, ['database' => $database]);
            $checks = [];

            foreach ($verify['checks'] as $check) {
                $checks[(string) $check['key']] = $check;
            }

            assert_false($verify['ok'], 'a queued row fails verification');
            assert_true(isset($checks['transactional']), 'the verification carries the transactional check');
            assert_false($checks['transactional']['ok']);
            assert_contains('harvest_queue=1', (string) $checks['transactional']['detail']);

            /* The catalogue table is not transactional: filling it is
             * legal and the install still verifies (once the queue is
             * emptied again). */
            $pdo->exec('DELETE FROM harvest_queue');
            $pdo->exec("UPDATE team_harvest_levels SET amount = '1234.00' WHERE level = 1");

            $again = $installer->verify($pdo, ['database' => $database]);
            assert_true($again['ok'], 'the ladder stays catalogue, not money');
        } finally {
            if (is_file($lockPath)) {
                @unlink($lockPath);
            }
        }
    },

    /* ==========================================================
     *  Reset parity (P20A-T8)
     * ========================================================== */
    'P20A-T8 a business reset empties the queue and the awards and clears the baseline' => function () use ($appRoot, $insertQueue, $insertAward, $nextId): void {
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

        $insertQueue($pdo, $juan, ['teammates' => 12, 'baseline_n' => 12]);
        $insertAward($pdo, $juan, ['level' => 1, 'threshold_n' => 3, 'n_at_award' => 12]);
        $insertAward($pdo, $juan, [
            'level' => 2, 'threshold_n' => 12, 'n_at_award' => 12,
            'status' => 'baseline', 'amount' => '2000.00',
        ]);

        $pdo->exec("UPDATE settings SET value = '2026-11-15 00:00:00' WHERE key_name = 'team_harvest_ledger_since'");
        $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'team_harvest_enabled'");

        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());

        $settingsBefore = $pdo->query('SELECT key_name, value FROM settings ORDER BY key_name')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $report = (new DatabaseResetService(
            $pdo,
            $database,
            ['confirm' => $database, 'tool' => 'cli']
        ))->run();

        assert_true($report['ok'], 'the reset verifies: ' . json_encode($report['verification']['checks']));

        /* Rule 8: the structure is emptied, the baseline is cleared and the
         * configured ladder survives. */
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn(), 'the queue is emptied');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn(), 'the awards are emptied');
        assert_same(
            '',
            (string) $pdo->query("SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since'")->fetchColumn(),
            'the baseline instant is cleared with the queue'
        );
        assert_same(10, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn(), 'the ladder is kept');

        $settingsAfter = $pdo->query('SELECT key_name, value FROM settings ORDER BY key_name')->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($settingsBefore as $key => $value) {
            if ($key === 'team_harvest_ledger_since') {
                continue;
            }

            assert_true(array_key_exists($key, $settingsAfter), 'setting "' . $key . '" is kept');
            assert_same((string) $value, (string) $settingsAfter[$key], 'setting "' . $key . '" is unchanged');
        }

        assert_same(
            4,
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'team\\_harvest\\_%'")->fetchColumn(),
            'the four settings survive a business reset'
        );

        /* The reset reports the new delete group and a clean zero pack. */
        $steps = [];

        foreach ($report['steps'] as $step) {
            $steps[(string) $step['key']] = $step;
        }

        assert_true(isset($steps['harvest']), 'the reset reports a harvest step');
        assert_same(3, (int) $steps['harvest']['deleted'], 'the step deleted the two awards and the queue row');

        $checks = [];

        foreach ($report['verification']['checks'] as $check) {
            $checks[(string) $check['key']] = $check;
        }

        assert_true($checks['zero_transactional']['ok'], 'the zero pack is clean');
        assert_same(0, $report['auto_increment']['failed']);

        /* Both counters restarted: the next position is 1 and the next
         * award is 1 (functional checks, not metadata reads). */
        $afterQueue = $nextId($pdo, 'harvest_queue');
        $afterAward = $nextId($pdo, 'team_harvest_awards');

        assert_true($afterQueue === null || $afterQueue === 1, 'the queue counter is reset, got ' . var_export($afterQueue, true));
        assert_true($afterAward === null || $afterAward === 1, 'the award counter is reset, got ' . var_export($afterAward, true));

        assert_true(in_array('harvest_queue', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_true(in_array('team_harvest_awards', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_false(in_array('team_harvest_levels', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
    },

    /* ==========================================================
     *  The wallet vocabulary (P20A-T9, P20A-T10)
     * ========================================================== */
    'P20A-T9 the ledger enum and WalletService::REF_TYPES agree in both directions' => function (): void {
        $column = db()->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch();
        assert_not_null($column, 'the ledger table must exist');

        preg_match_all("/'([^']+)'/", (string) $column['Type'], $matches);
        $dbValues = $matches[1];

        $php = WalletService::REF_TYPES;

        assert_count(13, $php, 'thirteen movement kinds after Phase 21 (20A adds team_harvest, 21A general_harvest)');
        assert_same($php, array_values(array_unique($php)), 'no duplicate movement kind');
        assert_same('general_harvest', end($php), 'general_harvest is appended last, after team_harvest');
        assert_same('general_harvest', end($dbValues), 'the enum appends general_harvest last');
        assert_same(
            1,
            count(array_keys($dbValues, 'team_harvest', true)),
            'the enum names team_harvest exactly once'
        );
        assert_false(in_array('team_harvest_reversal', $dbValues, true), 'D-12: there is no reversal type');

        sort($dbValues);
        sort($php);

        assert_same($dbValues, $php, 'the PHP list and the column must agree in both directions');

        /* The vocabulary stays closed: an unknown value is refused before
         * any write, exactly like a forward stub. */
        $error = assert_throws(
            static fn (): array => (new WalletService())->credit(1, '1.00', 'team_harvest_reversal', 1),
            WalletException::class
        );

        assert_same('ref_type', $error->reason);
    },

    'P20A-T10 team_harvest renders as Team Harvest bonus with a layer icon' => function (): void {
        assert_same('Team Harvest bonus', wallet_ref_label('team_harvest'));
        assert_same('bx bx-layer', wallet_ref_icon('team_harvest'));

        /* The label ships before the money: a row written by 20D must not
         * render as ucfirst('team_harvest'). */
        assert_not_same('Team harvest', wallet_ref_label('team_harvest'));

        /* Every value in the vocabulary has a label and a bx- icon: the
         * wallet statement renders one filter option per entry (Rule 6). */
        foreach (WalletService::REF_TYPES as $refType) {
            assert_true(wallet_ref_label($refType) !== '', $refType . ' has a label');
            assert_same(1, preg_match('/^bx bx-[a-z0-9-]+$/', wallet_ref_icon($refType)), $refType . ' has a bx- icon');
        }

        /* The filter is the only visible change of this phase: no row can
         * match it until 20D. */
        assert_same(
            0,
            (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'team_harvest'")->fetchColumn(),
            'nothing has ever paid a Team Harvest movement'
        );
    },

    /* ==========================================================
     *  The defaults and the ladder (P20A-T11, P20A-T12)
     * ========================================================== */
    'P20A-T11 the four settings exist with the documented defaults' => function () use ($buildFoundation, $newSettings, $setting): void {
        $fresh    = test_pdo(['db' => DB_NAME]);
        $migrated = $buildFoundation();

        foreach ($newSettings as $key => $expected) {
            assert_same($expected, $setting($fresh, $key), 'a fresh install seeds ' . $key);
            assert_same($expected, $setting($migrated, $key), 'a migrated database seeds ' . $key);
        }

        foreach ([$fresh, $migrated] as $pdo) {
            assert_same(
                4,
                (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'team\\_harvest\\_%'")->fetchColumn(),
                'exactly four Team Harvest settings — no hidden stub'
            );
        }

        /* Rule 4: the stream is off, and the queue is not merely empty — it
         * is unseeded. No DATE_FORMAT(NOW()) may appear in the migration. */
        setting_cache_clear();
        assert_same('0', setting('team_harvest_enabled'), 'D-09: the stream ships off');
        assert_false(setting_bool('team_harvest_enabled', true), 'a missing/zero switch resolves to off');
        assert_same('', setting('team_harvest_ledger_since'), 'D-04: the queue is unseeded');
        assert_same('3', setting('team_harvest_qualify_directs'));
        assert_same('10', setting('team_harvest_max_levels'));

        /* Rule 4: the stream is off, and the queue is not merely empty — it
         * is unseeded. No executable DATE_FORMAT(NOW()) may appear in the
         * migration (the comment that says so is not a statement). */
        $migration = SqlScript::fromFile(
            dirname(__DIR__, 2) . '/database/migrations/024_team_harvest.sql'
        );

        foreach ($migration->statements() as $statement) {
            assert_not_contains(
                'DATE_FORMAT(NOW()',
                $statement['sql'],
                'a migration must never pretend the queue is seeded (line ' . $statement['line'] . ')'
            );
        }
    },

    'P20A-T12 the ten seeded amounts total 5,095,000.00 and an operator edit survives' => function () use ($buildFoundation, $migrationDir, $ladder): void {
        foreach ([test_pdo(['db' => DB_NAME]), $buildFoundation()] as $pdo) {
            $rows = $pdo->query('SELECT level, amount FROM team_harvest_levels ORDER BY level')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            $rows = array_map(
                static fn (mixed $amount): string => number_format((float) $amount, 2, '.', ''),
                $rows
            );

            assert_same($ladder, $rows, 'the ten reference amounts, level by level');

            $total = (float) $pdo->query('SELECT SUM(amount) FROM team_harvest_levels')->fetchColumn();
            assert_same(
                '5095000.00',
                number_format($total, 2, '.', ''),
                'the full ladder totals ₱5,095,000.00'
            );
        }

        /* The reference ladder's arithmetic is pinned, not just its sum. */
        $expectedTotal = 0.0;

        foreach ($ladder as $amount) {
            $expectedTotal += (float) $amount;
        }

        assert_same(5095000.0, $expectedTotal);

        /* Rule 9: migration 024 is the only writer, and a re-run never
         * overwrites an operator's amount (P20A-T2 covers the settings). */
        $migrated = $buildFoundation();
        $migrated->exec("UPDATE team_harvest_levels SET amount = '7777.00' WHERE level = 4");

        try {
            test_run_sql_file($migrated, $migrationDir . '024_team_harvest.sql');
            assert_same(
                '7777.00',
                (string) $migrated->query('SELECT amount FROM team_harvest_levels WHERE level = 4')->fetchColumn(),
                'a deploy never overwrites a configured amount'
            );
        } finally {
            $migrated->exec("UPDATE team_harvest_levels SET amount = '6000.00' WHERE level = 4");
        }
    },

    /* ==========================================================
     *  Cascades (P20A-T13, P20A-T14)
     * ========================================================== */
    'P20A-T13 deleting a member who is not queued is harmless' => function () use ($makeMember): void {
        $pdo    = db();
        $member = $makeMember($pdo, 'p20a_unqueued');

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue WHERE user_id = ' . $member)->fetchColumn());

        $pdo->exec('DELETE FROM users WHERE id = ' . $member);

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue WHERE user_id = ' . $member)->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards WHERE user_id = ' . $member)->fetchColumn());
    },

    'P20A-T14 deleting a queued member cascades the queue, the awards and the ledger' => function () use ($makeMember, $insertQueue, $insertAward): void {
        $pdo    = db();
        $member = $makeMember($pdo, 'p20a_cascade_member');

        $insertQueue($pdo, $member, ['teammates' => 12, 'baseline_n' => 12]);
        $award = $insertAward($pdo, $member, ['level' => 1, 'threshold_n' => 3, 'n_at_award' => 12]);

        /* A real credit through the real service: the new movement kind is
         * accepted end to end, and the ledger row is the wallet's record. */
        $movement = (new WalletService())->credit(
            $member,
            '1000.00',
            'team_harvest',
            $award,
            'Team Harvest bonus - level 1',
            true
        );

        assert_false($movement['replayed']);
        assert_same(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = {$member} AND ref_type = 'team_harvest'")->fetchColumn()
        );

        $pdo->exec('DELETE FROM users WHERE id = ' . $member);

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue WHERE user_id = ' . $member)->fetchColumn(), 'the queue row cascaded');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards WHERE user_id = ' . $member)->fetchColumn(), 'the award cascaded');
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = {$member}")->fetchColumn(),
            'the wallet ledger row cascaded (fk_ewallet_user)'
        );

        /* No orphan was left behind for the reconciler to find. */
        $reconcile = (new WalletService())->reconcile();
        assert_true((bool) ($reconcile['ok'] ?? false), 'the wallet reconciliation stays clean');
    },

    /* ==========================================================
     *  One manifest, nothing reading it (P20A-T15, P20A-T16)
     * ========================================================== */
    'P20A-T15 no application file outside the manifest names the Phase 20 objects' => function () use ($appRoot): void {
        /* 20A ships tables with no writer on purpose. The manifest is the
         * wallet vocabulary and the two tools that must know every table
         * (plan 20A Rule 10, P20A-T15); **20B** adds the configuration card,
         * which reads the ladder and writes nothing but its ten amounts;
         * **20C** adds the queue model, which owns qualification, the
         * positions and the counters; **20D** adds the money engine (the
         * ledger model, the service and its exception); **20E** adds the
         * admin visibility: the fourth ledger tab, the member directory's
         * queue card and column, the member panel, the reports block and the
         * dashboard KPI — all reads, no route and no page of its own;
         * **20F** adds the member visibility: the fourth earnings tab, its
         * six cards, the dashboard tile and the genealogy badge — again all
         * reads; **21B** adds the shared board's read model, which reads the
         * same queue as the source of `N` (plan §12.3). */
        $allowList = [
            '/app/Services/WalletService.php',
            '/app/helpers.php',
            '/app/Services/DatabaseInstaller.php',
            '/app/Services/DatabaseResetService.php',
            '/app/Models/TeamHarvestLevel.php',
            '/app/Models/HarvestQueue.php',
            '/app/Models/TeamHarvestAward.php',
            '/app/Models/GeneralHarvestBoard.php',
            '/app/Services/TeamHarvestService.php',
            '/app/Services/TeamHarvestException.php',
            '/app/Controllers/AdminSettingsController.php',
            '/app/views/admin/settings/index.php',
            '/app/views/admin/settings/_commissions.php',
            '/app/views/admin/settings/_team_harvest.php',
            /* 20E — the admin surfaces that read the queue and the ledger. */
            '/app/Models/Member.php',
            '/app/Models/Report.php',
            '/app/Services/ReportService.php',
            '/app/Controllers/AdminCommissionController.php',
            '/app/Controllers/AdminMemberController.php',
            '/app/Controllers/AdminDashboardController.php',
            '/app/views/admin/commissions/index.php',
            '/app/views/admin/commissions/_team_harvest_header.php',
            '/app/views/admin/commissions/_team_harvest_levels.php',
            '/app/views/admin/commissions/_team_harvest_rows.php',
            '/app/views/admin/members/_harvest.php',
            '/app/views/admin/members/_rows.php',
            '/app/views/admin/members/_team_harvest.php',
            '/app/views/admin/members/show.php',
            '/app/views/admin/reports/index.php',
            '/app/views/admin/reports/_team_harvest.php',
            '/app/views/admin/dashboard/_commerce.php',
            /* 20F — the member surfaces that read the queue and the ledger. */
            '/app/Controllers/DashboardController.php',
            '/app/Controllers/EarningsController.php',
            '/app/views/member/dashboard.php',
            '/app/views/member/dashboard/_team_harvest.php',
            '/app/views/member/earnings.php',
            '/app/views/member/earnings/_team_header.php',
            '/app/views/member/earnings/_team_matrix.php',
            '/app/views/member/earnings/_team_pagination.php',
            '/app/views/member/earnings/_team_progress.php',
            '/app/views/member/earnings/_team_rows.php',
            '/app/views/member/earnings/_team_stats.php',
            '/app/views/member/genealogy/_header.php',
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

            if (preg_match('/harvest_queue|team_harvest/i', $source) === 1) {
                $found[] = $relative;
            }
        }

        sort($found, SORT_STRING);
        sort($allowList, SORT_STRING);

        assert_same(
            $allowList,
            $found,
            'only the 20A manifest, the 20B card, the 20C queue, the 20D engine, the 20E admin surfaces'
                . ' and the 20F member surfaces name the Team Harvest objects'
        );

        /* 20B adds the configuration model, 20C the queue and 20D the
         * money engine; the page of a later phase still must not exist,
         * because this stream adds no route (D-14). */
        assert_true(is_file($appRoot . '/app/Models/TeamHarvestLevel.php'), '20B ships the ladder model');
        assert_true(is_file($appRoot . '/app/Models/HarvestQueue.php'), '20C ships the queue model');
        assert_true(is_file($appRoot . '/app/Models/TeamHarvestAward.php'), '20D ships the money ledger');
        assert_true(is_file($appRoot . '/app/Services/TeamHarvestService.php'), '20D ships the money engine');
        assert_false(
            is_file($appRoot . '/app/Controllers/TeamHarvestController.php'),
            'the stream still has no controller: it adds no route'
        );

        foreach (['/app/routes.php', '/app/routes_inventory.php'] as $file) {
            assert_same(
                0,
                preg_match('/team_harvest|harvest_queue/i', (string) file_get_contents($appRoot . $file)),
                $file . ' has no Team Harvest route'
            );
        }
    },

    'P20A-T16 the phase is inert: empty tables, no producer and no money reader' => function (): void {
        $pdo = db();

        /* A fresh install is empty, and no PHP file produces a Team Harvest
         * movement or reads the two money tables (D-09, Rule 10). */
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());
        assert_same(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'team_harvest'")->fetchColumn()
        );

        $appRoot  = dirname(__DIR__, 2);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appRoot . '/app', FilesystemIterator::SKIP_DOTS)
        );

        $writers = [];
        $readers = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot)));
            $source   = (string) file_get_contents($file->getPathname());

            /* The engine names the movement through its one constant
             * (`TeamHarvestAward::REF_TYPE`), so the guard accepts either
             * the literal or the constant — both mean one writer. */
            if (preg_match("/->credit\\([^;]*(?:'team_harvest'|TeamHarvestAward::REF_TYPE)/s", $source) === 1) {
                $writers[] = $relative;
            }

            if (preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+`?(harvest_queue|team_harvest_awards)/i', $source) === 1) {
                $readers[] = $relative;
            }
        }

        sort($writers, SORT_STRING);
        sort($readers, SORT_STRING);

        /* **20D** is the one phase that moves money, and its service is the
         * only file that names the `team_harvest` wallet reference. */
        assert_same(
            ['/app/Services/TeamHarvestService.php'],
            $writers,
            'the money engine is the only Team Harvest wallet writer'
        );

        /* The readers are the business-reset engine, which must know every
         * table it empties; **20B's** settings card, which reads the queue
         * and the awards to tell the operator the four-state truth (plan §6
         * Rules 6–7); **20C's** queue model, which owns them; **20D's**
         * ledger model and service, which write and prove them; and
         * **20E's** member-directory join (`Member::adminList()` reads the
         * stored position in the same query) and its report read model
         * (`Report::teamHarvest*()`). The views call those models — no page
         * queries either table directly (Rule 5). */

        assert_same(
            [
                '/app/Controllers/AdminSettingsController.php',
                '/app/Models/GeneralHarvestBoard.php',
                '/app/Models/HarvestQueue.php',
                '/app/Models/Member.php',
                '/app/Models/Report.php',
                '/app/Models/TeamHarvestAward.php',
                '/app/Services/DatabaseResetService.php',
                '/app/Services/TeamHarvestService.php',
            ],
            $readers,
            'the readers are the settings card, the queue, the directory join, the report model,'
                . ' the ledger, the engine and the reset engine'
        );

        /* The wallet statement filter is the only visible change of 20A
         * (Rule 6): the label resolves and the enum accepts the value. */
        assert_same('Team Harvest bonus', wallet_ref_label('team_harvest'));
        assert_false(setting_bool('team_harvest_enabled', true));
    },

    /* ==========================================================
     *  The counts (P20A-T17) and the guards (P20A-T18)
     * ========================================================== */
    'P20A-T17 the schema, the bundle and the reset tooling all report 39 tables' => function () use ($appRoot): void {
        $pdo    = test_pdo(['db' => DB_NAME]);
        $tables = test_table_list($pdo);

        assert_count(39, $tables);
        assert_same(39, DbTarget::fromConfig()->tableCount(DB_NAME));

        /* The reset allow-list is exactly the schema's table list. */
        $allowList = DatabaseResetService::ALL_TABLES;
        sort($allowList, SORT_STRING);
        assert_same($tables, $allowList, 'ALL_TABLES must name every schema table exactly once');

        $creates = static function (string $file): int {
            $count = 0;

            foreach (SqlScript::fromFile($file)->statements() as $statement) {
                if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?/i', $statement['sql']) === 1) {
                    $count++;
                }
            }

            return $count;
        };

        assert_same(39, $creates($appRoot . '/database/schema.sql'), 'schema.sql creates 39 tables');
        assert_same(39, $creates($appRoot . '/database/install.sql'), 'the bundle creates 39 tables');

        /* The queue and the awards are money: a reset empties them. The
         * ladder is catalogue: a reset keeps it. Every table is known. */
        foreach (['harvest_queue', 'team_harvest_awards'] as $table) {
            assert_true(in_array($table, DatabaseResetService::ZERO_TABLES, true), 'a reset must zero ' . $table);
            assert_true(in_array($table, DatabaseInstaller::TRANSACTIONAL_TABLES, true), 'the install must verify ' . $table);
        }

        assert_true(in_array('team_harvest_levels', DatabaseResetService::ALL_TABLES, true), 'the ladder is a known table');
        assert_false(in_array('team_harvest_levels', DatabaseResetService::ZERO_TABLES, true), 'the ladder is catalogue, not money');
        assert_false(in_array('team_harvest_levels', DatabaseResetService::AUTO_INCREMENT_TABLES, true));
        assert_false(in_array('team_harvest_levels', DatabaseInstaller::TRANSACTIONAL_TABLES, true));

        /* The delete group runs before the members group, because both
         * money tables reference users. */
        $groups = (new ReflectionClassConstant(DatabaseResetService::class, 'DELETE_GROUPS'))->getValue();
        $keys   = array_column($groups, 'key');

        $harvestIndex = array_search('harvest', $keys, true);
        $ordersIndex  = array_search('orders', $keys, true);

        assert_true($harvestIndex !== false, 'the reset has a harvest delete group');
        assert_true($ordersIndex !== false, 'the reset still has its orders group');
        assert_true($harvestIndex < $ordersIndex, 'the queue group is planned before the order and member groups');
    },

    'P20A-T18 the three tables refuse an impossible row' => function () use ($makeMember, $insertQueue, $insertAward): void {
        $pdo     = db();
        $member  = $makeMember($pdo, 'p20a_guard_member');
        $member2 = $makeMember($pdo, 'p20a_guard_member2');

        /* harvest_queue: a position has at least one direct, and the
         * counter can never fall below the baseline (§13.2). */
        assert_throws(
            static fn (): int => $insertQueue($pdo, $member, ['directs' => 0]),
            PDOException::class,
            'a queue row with zero directs is refused'
        );
        assert_throws(
            static fn (): int => $insertQueue($pdo, $member, ['teammates' => 1, 'baseline_n' => 2]),
            PDOException::class,
            'teammates below the baseline is refused'
        );
        assert_throws(
            static fn (): int => $insertQueue($pdo, $member, ['source' => 'manual']),
            PDOException::class,
            'an unknown source is refused'
        );

        $queueId = $insertQueue($pdo, $member, ['teammates' => 3]);
        assert_true($queueId > 0, 'a legal queue row inserts');

        /* One position per member, ever (D-03). */
        assert_throws(
            static fn (): int => $insertQueue($pdo, $member, ['qualified_at' => '2026-11-02 09:00:00']),
            PDOException::class,
            'a member cannot be queued twice'
        );

        /* team_harvest_levels: level 1–10 and a non-negative amount. */
        assert_throws(
            static fn (): int => $pdo->exec("UPDATE team_harvest_levels SET amount = '-1.00' WHERE level = 1"),
            PDOException::class,
            'a negative amount is refused'
        );
        assert_throws(
            static fn (): int => $pdo->exec('INSERT INTO team_harvest_levels (level, amount) VALUES (0, 100.00)'),
            PDOException::class,
            'level 0 is refused'
        );
        assert_throws(
            static fn (): int => $pdo->exec('INSERT INTO team_harvest_levels (level, amount) VALUES (11, 100.00)'),
            PDOException::class,
            'level 11 is refused'
        );

        /* team_harvest_awards: the level range, the amount, the threshold
         * snapshot and the money anchor (D-06, I-05, I-08). */
        foreach ([0, 11] as $level) {
            assert_throws(
                static fn (): int => $insertAward($pdo, $member, ['level' => $level]),
                PDOException::class,
                'award level ' . $level . ' is refused'
            );
        }

        assert_throws(
            static fn (): int => $insertAward($pdo, $member, ['amount' => '-0.01']),
            PDOException::class,
            'a negative award is refused'
        );
        assert_throws(
            static fn (): int => $insertAward($pdo, $member, ['threshold_n' => 12, 'n_at_award' => 3]),
            PDOException::class,
            'awarding a level the member has not completed is refused'
        );
        assert_throws(
            static fn (): int => $insertAward($pdo, $member, ['status' => 'voided']),
            PDOException::class,
            'D-12: there is no voided state in v1'
        );

        $awardId = $insertAward($pdo, $member);
        assert_true($awardId > 0, 'a legal award inserts');

        assert_throws(
            static fn (): int => $insertAward($pdo, $member, ['amount' => '2000.00']),
            PDOException::class,
            'a member is paid once per level, ever'
        );

        /* A second member may hold the same level; a baseline row can be
         * recorded next to nothing (I-05: one row per member and level). */
        assert_true($insertAward($pdo, $member2) > 0);

        /* The award id is the wallet ref_id: it fits INT UNSIGNED. */
        assert_same(
            1,
            (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = \'team_harvest_awards\'
                    AND column_name = \'id\' AND column_type = \'int unsigned\''
            )->fetchColumn()
        );
    },
];
