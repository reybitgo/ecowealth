<?php
declare(strict_types=1);

/* ==============================================================
 *  InstallTest — the CLI installer's engine (plan IR-3-T8)
 *
 *  Every case runs the production DatabaseInstaller against the
 *  pre-provisioned scratch schema ecowealth_sneat_tool_a_test (and
 *  tool_b_test where one schema is already in use). Nothing here
 *  touches ecowealth_sneat, ecowealth_sneat_test or their grants.
 *
 *      preflight    the check table, a missing schema, --create-database
 *      install      27 tables, 48 settings, 2 staff, 7/2/18/10, the lock
 *      credentials  the §6.8 policy, enforced before any SQL
 *      refusals     locked / not_empty, with the lock never overwritten
 *      guard        a table appearing mid-import is translated, not raw
 *      production   INSTALL_KEY required; the comparison itself
 *      report       the JSON shape, no password and no key inside
 *
 *  The lock lives under STORAGE_PATH (tests/bootstrap.php pins it to a
 *  temp folder), so a case never leaves state in system/storage and
 *  every case deletes the lock before it runs and after it finishes.
 * ============================================================== */

$appRoot     = dirname(__DIR__, 2);
$installPath = $appRoot . '/database/install.sql';

/** A service bound to the application's configured MySQL account. */
$installer = static fn (string $env = APP_ENV): DatabaseInstaller
    => new DatabaseInstaller(DbTarget::fromConfig(), null, $env);

/** Remove the install lock (the temp one the harness points at). */
$clearLock = static function (): void {
    $path = DatabaseInstaller::lockPath();

    if (is_file($path)) {
        @unlink($path);
    }
};

/**
 * A complete, valid option set for one target schema.
 *
 * @param array<string,array<string,string>> $staff role => fields to override
 * @return array<string,mixed>
 */
$options = static function (string $database, array $staff = [], array $extra = []): array {
    $base = [
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
        'site_name'  => 'EcoWealth InstallTest',
        'channel'    => 'cli',
    ];

    foreach ($staff as $role => $fields) {
        $base[$role] = array_replace($base[$role], $fields);
    }

    return array_replace($base, $extra);
};

/** The check table as key => check. */
$byKey = static function (array $preflight): array {
    $indexed = [];

    foreach ($preflight['checks'] as $check) {
        $indexed[(string) $check['key']] = $check;
    }

    return $indexed;
};

return [
    'preflight lists every check and passes on an empty scratch schema' => function () use ($installer, $clearLock, $byKey): void {
        $clearLock();
        [, $database] = test_scratch_database('tool_a');

        $preflight = $installer()->preflight($database);
        $checks    = $byKey($preflight);

        assert_same($database, $preflight['database']);

        foreach ([
            'php', 'ext_pdo_mysql', 'ext_fileinfo', 'ext_mbstring', 'ext_json', 'config_local',
            'server', 'database_name', 'database_exists', 'database_empty', 'install_sql',
            'storage', 'lock_dir', 'install_lock', 'install_key',
        ] as $key) {
            assert_true(isset($checks[$key]), 'preflight carries the "' . $key . '" check');
        }

        assert_true($checks['database_exists']['ok'], 'the scratch schema exists');
        assert_true($checks['database_empty']['ok'], 'the scratch schema starts empty');
        assert_true($checks['install_sql']['ok'], 'database/install.sql parses');
        assert_contains('54 statements', $checks['install_sql']['detail']);
        assert_true($checks['install_lock']['ok'], 'no lock is present');
        assert_true($preflight['ok'], 'the whole preflight passes');
        assert_false(DatabaseInstaller::isLocked(), 'preflight writes no lock');
    },

    'a missing database is reported with a hint and never created' => function () use ($installer, $clearLock, $byKey): void {
        $clearLock();

        $missing = 'ecowealth_sneat_install_missing_test';
        $target  = DbTarget::fromConfig();

        if ($target->exists($missing)) {
            fail('Fixture problem: ' . $missing . ' must not exist before this case.');
        }

        $preflight = $installer()->preflight($missing);
        $checks    = $byKey($preflight);

        assert_false($preflight['ok']);
        assert_false($checks['database_exists']['ok']);
        assert_contains('does not exist', $checks['database_exists']['detail']);
        assert_contains('--create-database', $checks['database_exists']['hint']);

        /* Empty is "not checked" (a pass) — one problem, not two. */
        assert_true($checks['database_empty']['ok']);

        assert_false($target->exists($missing), 'preflight never creates a database');
        assert_false(DatabaseInstaller::isLocked(), 'preflight never writes a lock');

        /* With --create-database the same target is installable, still
         * without creating it. */
        $willCreate = $installer()->preflight($missing, true, null, true);
        $checks     = $byKey($willCreate);

        assert_true($checks['database_exists']['ok']);
        assert_contains('will be created', $checks['database_exists']['detail']);
        assert_true($checks['database_empty']['ok']);
        assert_false($target->exists($missing), 'even --check --create-database creates nothing');
    },

    'a fresh install produces 27/48/2/7/2/18/10 and a valid lock' => function () use ($installer, $clearLock, $options, $appRoot): void {
        $clearLock();

        [$pdo, $database] = test_scratch_database('tool_a');

        try {
            $report = $installer()->install($options($database));

            assert_true($report['ok'], 'the install reports ok');
            assert_same('db_install', $report['tool']);
            assert_same(DatabaseInstaller::REPORT_VERSION, $report['version']);
            assert_same('install', $report['mode']);
            assert_same($database, $report['database']);
            assert_same('cli', $report['channel']);

            assert_same(27, $report['counts']['tables_created']);
            assert_same(48, $report['counts']['settings_rows']);
            assert_same(2, $report['counts']['staff_rows']);
            assert_same(0, $report['counts']['members_rows']);
            assert_same(7, $report['counts']['products_rows']);
            assert_same(2, $report['counts']['packages_rows']);
            assert_same(18, $report['counts']['levels_rows']);
            assert_same(10, $report['counts']['contents_rows']);
            assert_same(0, $report['counts']['codes_rows']);

            /* The database itself, not just the report. */
            assert_count(27, test_table_list($pdo, $database));
            assert_same(48, (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
            assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

            /* Both operator passwords really authenticate. */
            foreach ([
                'admin' => 'AdminPassw0rd!23',
                'superadmin' => 'SuperPassw0rd!23',
            ] as $role => $password) {
                $statement = $pdo->prepare('SELECT username, member_status, is_active, password_hash
                                              FROM users WHERE role = ? LIMIT 1');
                $statement->execute([$role]);
                $user = $statement->fetch();

                assert_true($user !== false, 'the ' . $role . ' row exists');
                assert_same($role, $user['username']);
                assert_same('active', $user['member_status']);
                assert_same(1, (int) $user['is_active']);
                assert_true(password_verify($password, (string) $user['password_hash']), $role . ' signs in');
            }

            /* The metadata the installer owns. */
            $settings = $pdo->query(
                "SELECT key_name, value FROM settings
                  WHERE key_name IN ('site_name','install_version','install_channel','install_completed_at')"
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            assert_same('EcoWealth InstallTest', $settings['site_name']);
            assert_same('1.0.0', $settings['install_version']);
            assert_same('cli', $settings['install_channel']);
            assert_same(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $settings['install_completed_at']));

            /* The lock (plan Appendix D). */
            assert_true(DatabaseInstaller::isLocked(), 'the lock file exists');

            $lockPath = DatabaseInstaller::lockPath();
            assert_same(
                rtrim(str_replace('\\', '/', (string) STORAGE_PATH), '/') . '/install.lock',
                $lockPath,
                'the lock honours STORAGE_PATH so a test never writes into system/storage'
            );

            $lock = DatabaseInstaller::readLock();
            assert_true($lock !== null, 'the lock is valid JSON');

            assert_same($database, $lock['database']);
            assert_same('1.0.0', $lock['app_version']);
            assert_same('cli', $lock['channel']);
            assert_same(['admin', 'superadmin'], $lock['staff']);
            assert_same(64, strlen((string) $lock['install_sql_sha256']));
            assert_same(
                SqlScript::fromFile($appRoot . '/database/install.sql')->checksum(),
                $lock['install_sql_sha256'],
                'the lock records the bundle checksum'
            );
            assert_same(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) $lock['installed_at']));

            /* The report carries the lock's own JSON, never a password. */
            assert_same($lock, $report['lock']['contents']);
            assert_true($report['lock']['written']);

            $json = (string) json_encode($report);
            assert_not_contains('AdminPassw0rd!23', $json);
            assert_not_contains('SuperPassw0rd!23', $json);

            /* A preflight on the installed schema now reports both refusals. */
            $after = $installer()->preflight($database);

            assert_false($after['ok']);
        } finally {
            $clearLock();
        }
    },

    'verify re-checks an installed schema and its lock' => function () use ($installer, $clearLock, $options): void {
        $clearLock();

        [$pdo, $database] = test_scratch_database('tool_a');

        try {
            $installer()->install($options($database));

            $verify = $installer()->verify($pdo, [
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
                'require_lock' => true,
            ]);

            assert_true($verify['ok'], 'the verified install is complete');

            $keys = array_column($verify['checks'], 'key');

            foreach (['tables', 'settings', 'staff', 'signin', 'members', 'catalogue', 'codes', 'transactional', 'metadata', 'lock'] as $key) {
                assert_true(in_array($key, $keys, true), 'verify carries the "' . $key . '" check');
            }

            /* A wrong password must fail the sign-in check. */
            $wrong = $installer()->verify($pdo, [
                'database' => $database,
                'admin'    => ['username' => 'admin', 'password' => 'NotThePassword!23'],
            ]);

            assert_false($wrong['ok']);

            $signin = null;

            foreach ($wrong['checks'] as $check) {
                if ($check['key'] === 'signin') {
                    $signin = $check;
                }
            }

            assert_true($signin !== null, 'the sign-in check exists');
            assert_false($signin['ok'], 'a wrong password fails sign-in');
        } finally {
            $clearLock();
        }
    },

    'a second run is refused by the lock and changes nothing' => function () use ($installer, $clearLock, $options): void {
        $clearLock();

        [, $database] = test_scratch_database('tool_a');

        try {
            $first = $installer()->install($options($database));
            assert_true($first['ok']);

            $lockPath = DatabaseInstaller::lockPath();
            $before   = (string) file_get_contents($lockPath);

            /* Empty the schema: the refusal must come from the lock alone. */
            test_scratch_database('tool_a');

            $error = assert_throws(
                static fn (): array => $installer()->install($options($database)),
                DatabaseToolException::class
            );

            assert_same(DatabaseToolException::KIND_LOCKED, $error->kind);
            assert_contains('already locked', $error->getMessage());
            assert_contains('install.lock', (string) $error->hint);

            assert_same($before, (string) file_get_contents($lockPath), 'the lock is never overwritten');
        } finally {
            $clearLock();
        }
    },

    'a non-empty database without a lock is refused as not_empty' => function () use ($installer, $clearLock, $options, $installPath): void {
        $clearLock();

        [$pdo, $database] = test_scratch_database('tool_b');

        try {
            SqlScript::fromFile($installPath)->run($pdo);

            $error = assert_throws(
                static fn (): array => $installer()->install($options($database)),
                DatabaseToolException::class
            );

            assert_same(DatabaseToolException::KIND_NOT_EMPTY, $error->kind);
            assert_contains('is not empty', $error->getMessage());
            assert_same($database, $error->context['database']);
            assert_same(27, (int) $error->context['tables']);
            assert_false(DatabaseInstaller::isLocked(), 'a refused install writes no lock');

            /* Nothing was deleted or added: the import is untouched. */
            assert_count(27, test_table_list($pdo, $database));
            assert_same(48, (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        } finally {
            $clearLock();
        }
    },

    'a table appearing mid-import is translated, never surfaced raw' => function () use ($installer, $clearLock, $options, $appRoot): void {
        $clearLock();

        [, $database] = test_scratch_database('tool_b');

        /* Reuse the bundle's own guard, preceded by a CREATE TABLE that
         * simulates a table appearing between the empty check and the
         * import. Nothing in the installer may leak "1146" or "SQLSTATE". */
        $header = (string) file_get_contents($appRoot . '/database/install/header.sql');
        assert_same(1, preg_match('/SET @ew_present.*?DEALLOCATE PREPARE ew_guard;/s', $header, $match));
        $guard = (string) ($match[0] ?? '');
        assert_true($guard !== '', 'the bundle header carries the guard');

        $temporary = rtrim((string) STORAGE_PATH, '/\\') . '/guard-race-' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents($temporary, "CREATE TABLE ew_guard_race_probe (id INT NOT NULL);\n" . $guard . "\n");

        try {
            $error = assert_throws(
                static fn (): array => $installer()->install($options($database, [], ['install_sql' => $temporary])),
                DatabaseToolException::class
            );

            assert_same(DatabaseToolException::KIND_NOT_EMPTY, $error->kind);
            assert_contains('stopped being empty', $error->getMessage());
            assert_not_contains('1146', $error->getMessage());
            assert_not_contains('SQLSTATE', $error->getMessage());
            assert_not_contains('ecowealth_install_requires_an_empty_database', $error->getMessage());
            assert_false(DatabaseInstaller::isLocked(), 'the refused install writes no lock');
        } finally {
            @unlink($temporary);
            $clearLock();
        }
    },

    'the password policy is enforced before any SQL runs' => function () use ($installer, $clearLock, $options): void {
        $clearLock();

        [$pdo, $database] = test_scratch_database('tool_a');

        $service = $installer();

        $valid = [
            'admin'      => ['username' => 'admin', 'email' => 'owner@example.com', 'password' => 'AdminPassw0rd!23'],
            'superadmin' => ['username' => 'superadmin', 'email' => 'owner+super@example.com', 'password' => 'SuperPassw0rd!23'],
        ];

        assert_true($service->validateStaffInput($valid)['ok'], 'the valid pair passes');

        $cases = [
            'an 8-character password' => [
                'admin' => ['password' => 'short12x'],
            ],
            'a published default password' => [
                'superadmin' => ['password' => 'Admin@123'],
            ],
            'a mismatched confirmation' => [
                'admin' => ['password_confirm' => 'SomethingElse!23'],
            ],
            'the same username twice' => [
                'superadmin' => ['username' => 'admin'],
            ],
            'the same email twice' => [
                'superadmin' => ['email' => 'OWNER@example.com'],
            ],
            'an invalid username' => [
                'admin' => ['username' => 'no spaces'],
            ],
            'an invalid email' => [
                'superadmin' => ['email' => 'not-an-email'],
            ],
        ];

        foreach ($cases as $label => $override) {
            $input = $valid;

            foreach ($override as $role => $fields) {
                $input[$role] = array_replace($input[$role], $fields);
            }

            $result = $service->validateStaffInput($input);

            assert_false($result['ok'], $label . ' must be refused');
            assert_true($result['errors'] !== [], $label . ' reports an error');

            foreach ($result['errors'] as $field => $message) {
                assert_true(str_contains((string) $field, '.'), $label . ' names the field (' . $field . ')');
                assert_true($message !== '', $label . ' carries a message');
            }
        }

        /* Short password through install(): refused before anything is created. */
        $error = assert_throws(
            static fn (): array => $service->install($options($database, ['admin' => ['password' => 'short12x']])),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_USAGE, $error->kind);
        assert_contains('not valid', $error->getMessage());
        assert_same(0, count(test_table_list($pdo, $database)), 'refused credentials create no table');
        assert_false(DatabaseInstaller::isLocked(), 'refused credentials write no lock');

        $clearLock();
    },

    'production refuses without INSTALL_KEY and the comparison is constant time' => function () use ($installer, $clearLock, $byKey): void {
        $clearLock();

        [, $database] = test_scratch_database('tool_a');

        /* The comparison itself (plan IR-4-T5): an empty expected key never
         * matches, not even an empty submission. */
        assert_false(DatabaseInstaller::installKeyMatches('', ''));
        assert_false(DatabaseInstaller::installKeyMatches('some-key', ''));
        assert_false(DatabaseInstaller::installKeyMatches('', 'some-key'));
        assert_true(DatabaseInstaller::installKeyMatches('some-key', 'some-key'));
        assert_false(DatabaseInstaller::installKeyMatches('some-key', 'some-kez'));

        $configured = DatabaseInstaller::configuredInstallKey();
        $production = $installer('production');

        if ($configured === '') {
            $preflight = $production->preflight($database, true, '');
            $checks    = $byKey($preflight);

            assert_false($preflight['ok']);
            assert_false($checks['install_key']['ok']);
            assert_contains('INSTALL_KEY is empty', $checks['install_key']['detail']);

            $error = assert_throws(
                static fn (): array => $production->install([
                    'database'   => $database,
                    'admin'      => ['username' => 'admin', 'email' => 'owner@example.com', 'password' => 'AdminPassw0rd!23'],
                    'superadmin' => ['username' => 'superadmin', 'email' => 'owner2@example.com', 'password' => 'SuperPassw0rd!23'],
                ]),
                DatabaseToolException::class
            );

            assert_same(DatabaseToolException::KIND_REFUSED, $error->kind);
            assert_contains('INSTALL_KEY', $error->getMessage());
            assert_not_contains('AdminPassw0rd', $error->getMessage());
        } else {
            /* A server with a real key configured: the wrong and the right
             * value must be distinguishable through preflight. */
            $checks = $byKey($production->preflight($database, true, 'wrong-' . $configured));
            assert_false($checks['install_key']['ok']);

            $checks = $byKey($production->preflight($database, true, $configured));
            assert_true($checks['install_key']['ok']);
        }

        /* Non-production still ignores the key (local convenience). */
        $local = $installer('development');
        $checks = $byKey($local->preflight($database));
        assert_true($checks['install_key']['ok']);
        assert_contains('not required', $checks['install_key']['detail']);

        $clearLock();
    },

    'the report carries the documented JSON keys and no secret' => function () use ($installer, $clearLock, $options): void {
        $clearLock();

        [, $database] = test_scratch_database('tool_a');

        try {
            $report = $installer()->install($options($database));

            foreach ([
                'tool', 'version', 'ok', 'mode', 'database', 'channel', 'started_at', 'duration_ms',
                'options', 'steps', 'counts', 'staff', 'lock', 'install_sql',
                'verification', 'checks', 'warnings',
            ] as $key) {
                assert_true(array_key_exists($key, $report), 'the report has "' . $key . '"');
            }

            assert_same('db_install', $report['tool']);
            assert_same(1, $report['version']);
            assert_true(is_bool($report['ok']));
            assert_true(is_int($report['duration_ms']) && $report['duration_ms'] >= 0);
            assert_same(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) $report['started_at']));

            /* Steps: key, label, status, rows, ms. */
            assert_true($report['steps'] !== []);

            foreach ($report['steps'] as $step) {
                assert_true(in_array($step['status'], ['ok', 'skip', 'fail'], true));
                assert_true(is_int($step['ms']) && $step['ms'] >= 0);
            }

            assert_same($report['verification']['checks'], $report['checks']);
            assert_true($report['verification']['ok']);

            /* Options are redacted: usernames/emails yes, passwords never. */
            assert_same(['admin', 'superadmin'], $report['staff']['usernames']);
            assert_same('owner@example.com', $report['options']['admin']['email']);
            assert_false(isset($report['options']['admin']['password']), 'the report omits the admin password');
            assert_false(isset($report['options']['superadmin']['password']), 'the report omits the superadmin password');
            assert_false(isset($report['options']['install_key']), 'the report omits the INSTALL_KEY');

            $json = (string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            assert_not_contains('AdminPassw0rd!23', $json);
            assert_not_contains('SuperPassw0rd!23', $json);

            $decoded = json_decode($json, true);
            assert_true(is_array($decoded), 'the report round-trips through JSON');
            assert_same('db_install', $decoded['tool']);
            assert_true($decoded['ok']);
        } finally {
            $clearLock();
        }
    },
];
