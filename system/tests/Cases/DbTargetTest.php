<?php
declare(strict_types=1);

/* ==============================================================
 *  DbTargetTest — connection/probing rules for the install/reset tools
 *  (plan IR-1-T5, §5.5)
 *
 *  The database-backed cases use the pre-provisioned scratch schema
 *  ecowealth_sneat_tool_a_test (bootstrap.php :: test_scratch_database),
 *  recreated at the start of each case. No case targets the harness
 *  schema for a destructive operation, and none targets
 *  ecowealth_sneat (D-IR-16).
 * ============================================================== */

return [
    'fromConfig connects at server level and to one schema' => function (): void {
        $target = DbTarget::fromConfig();

        /* Server-level: no database selected. */
        $server = $target->connect();
        assert_null($server->query('SELECT DATABASE()')->fetchColumn(), 'no dbname in the server-level DSN');

        $pdo = $target->connect(DB_NAME);
        assert_same(DB_NAME, $pdo->query('SELECT DATABASE()')->fetchColumn());

        /* The session matches app/database.php: utf8mb4 + the display zone. */
        assert_same('utf8mb4', $pdo->query('SELECT @@character_set_client')->fetchColumn());
        assert_same('+08:00', $pdo->query('SELECT @@session.time_zone')->fetchColumn());
    },

    'exists reports a real schema and a missing one' => function (): void {
        $target = DbTarget::fromConfig();

        assert_true($target->exists(DB_NAME), 'the harness schema exists');
        assert_false($target->exists('ecowealth_sneat_no_such_schema_test'));
    },

    'tables and tableCount read information_schema in sorted order' => function (): void {
        $target = DbTarget::fromConfig();

        $tables = $target->tables(DB_NAME);
        assert_same(test_table_list(test_pdo(['db' => DB_NAME])), $tables);
        assert_same(31, $target->tableCount(DB_NAME));
        assert_same($tables, $target->tables(DB_NAME), 'stable across calls');

        /* An explicit schema argument is read, not the connection default. */
        [$other, $otherDb] = test_scratch_database('tool_b');
        $other->exec('CREATE TABLE marker (id INT NOT NULL PRIMARY KEY)');

        assert_same(['marker'], $target->tables($otherDb));
        assert_same(1, $target->tableCount($otherDb));

        /* Leave tool_b empty for the next phase's parity runs. */
        $other->exec('DROP TABLE marker');
    },

    'the information_schema helpers describe the harness schema' => function (): void {
        $pdo = test_pdo(['db' => DB_NAME]);

        $tables = test_table_list($pdo);
        assert_count(31, $tables);
        assert_same('activations', $tables[0], 'tables are sorted');
        assert_same('users', $tables[30]);

        $columns = test_column_list($pdo);
        assert_count(31, $columns);
        assert_same('id', $columns['users'][0]['name'], 'columns are in ordinal order');
        assert_same('int unsigned', $columns['users'][0]['type']);
        assert_same('NO', $columns['users'][0]['nullable']);
        assert_null($columns['users'][0]['default']);
        assert_same('auto_increment', $columns['users'][0]['extra']);
        assert_same('utf8mb4_unicode_ci', $columns['users'][1]['collation'], 'username collation');

        $indexes = test_index_list($pdo);
        assert_same('PRIMARY', $indexes['users'][0]['name'], 'indexes are sorted by name');
        assert_true($indexes['users'][0]['unique']);
        assert_same('id', $indexes['users'][0]['columns'][0]['column']);

        $foreignKeys = test_foreign_key_list($pdo);
        $orderUserFk = null;
        foreach ($foreignKeys['shop_orders'] as $foreignKey) {
            if ($foreignKey['referenced_table'] === 'users') {
                $orderUserFk = $foreignKey;
                break;
            }
        }

        assert_not_null($orderUserFk, 'shop_orders must keep its users foreign key');
        assert_same('fk_orders_user', $orderUserFk['name']);
        assert_same(['user_id'], $orderUserFk['columns']);
        assert_same(['id'], $orderUserFk['referenced_columns']);
        assert_same('RESTRICT', $orderUserFk['delete_rule']);
    },

    'a pre-provisioned scratch schema is recreated empty and then listed' => function (): void {
        [$pdo, $db] = test_scratch_database('tool_a');
        $target     = DbTarget::fromConfig();

        assert_same($db, (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        assert_true($target->exists($db));
        assert_same([], $target->tables($db));
        assert_same(0, $target->tableCount($db));

        $pdo->exec('CREATE TABLE zebra (id INT NOT NULL PRIMARY KEY)');
        $pdo->exec('CREATE TABLE alpha (id INT NOT NULL PRIMARY KEY)');

        assert_same(['alpha', 'zebra'], $target->tables($db));
        assert_same(2, $target->tableCount($db));
    },

    'recreating the scratch schema removes the previous case tables' => function (): void {
        [$pdo, $db] = test_scratch_database('tool_a');
        $pdo->exec('CREATE TABLE leftover (id INT NOT NULL PRIMARY KEY)');
        assert_count(1, DbTarget::fromConfig()->tables($db));

        /* Close the old connection before the schema is dropped. */
        unset($pdo);

        [$fresh, $name] = test_scratch_database('tool_a');

        assert_same($db, $name);
        assert_same([], DbTarget::fromConfig()->tables($db));
        assert_same(0, (int) $fresh->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
        )->fetchColumn());
    },

    'create makes a missing schema and leaves it empty' => function (): void {
        [$pdo, $db] = test_scratch_database('tool_a');

        $pdo->exec('DROP DATABASE `' . $db . '`');
        unset($pdo);

        $target = DbTarget::fromConfig();
        assert_false($target->exists($db), 'the schema is gone before create()');

        $target->create($db);

        assert_true($target->exists($db));
        assert_same([], $target->tables($db));
        assert_same(0, $target->tableCount($db));
    },

    'create maps an existing schema and a refused name to hints' => function (): void {
        $target = DbTarget::fromConfig();

        $e = assert_throws(static function () use ($target): void {
            $target->create(DB_NAME);
        }, DatabaseToolException::class);
        assert_same(DatabaseToolException::KIND_PREFLIGHT, $e->kind);
        assert_same(1007, $e->context['driver_code']);
        assert_contains('already exists', $e->getMessage());

        /* A name the MySQL user has no grant on: the plan's shared-hosting case. */
        $e = assert_throws(static function () use ($target): void {
            $target->create('ecowealth_sneat_tool_not_granted_test');
        }, DatabaseToolException::class);
        assert_same(DatabaseToolException::KIND_PREFLIGHT, $e->kind);
        assert_same(1044, $e->context['driver_code']);
        assert_same('create', $e->context['operation']);
        assert_contains('hosting panel', (string) $e->hint);
        assert_contains('CREATE DATABASE', (string) $e->hint);
    },

    'unsafe database names are rejected before any SQL' => function (): void {
        $target = DbTarget::fromConfig();

        $unsafe = [
            '',
            'bad name',
            'bad-name',
            'bad.name',
            'bad`name',
            "x; DROP DATABASE other",
            "x' OR '1'='1",
            str_repeat('a', 65),
        ];

        foreach ($unsafe as $name) {
            $e = assert_throws(
                static fn (): PDO => $target->connect($name),
                DatabaseToolException::class,
                'connect(' . var_export($name, true) . ')'
            );
            assert_same(DatabaseToolException::KIND_INVALID_NAME, $e->kind, $name);
            assert_contains('not allowed', $e->getMessage());

            assert_same(
                DatabaseToolException::KIND_INVALID_NAME,
                assert_throws(static fn (): bool => $target->exists($name), DatabaseToolException::class)->kind,
                'exists() must validate too'
            );
            assert_same(
                DatabaseToolException::KIND_INVALID_NAME,
                assert_throws(static function () use ($target, $name): void {
                    $target->create($name);
                }, DatabaseToolException::class)->kind,
                'create() must validate too'
            );
        }

        /* The boundary the regex accepts: 64 characters. */
        assert_same(str_repeat('a', 64), $target->assertSafeDatabaseName(str_repeat('a', 64)));
        assert_same('ecowealth_sneat', $target->assertSafeDatabaseName('ecowealth_sneat'));
    },

    'an unknown database maps to a create-it-first hint and leaks nothing' => function (): void {
        [$pdo, $db] = test_scratch_database('tool_a');
        $target     = DbTarget::fromConfig();

        /* Drop a schema the MySQL user *is* granted, so the driver reports
         * 1049 rather than the 1044 "no access" error. */
        $pdo->exec('DROP DATABASE `' . $db . '`');
        unset($pdo);

        $e = assert_throws(static fn (): PDO => $target->connect($db), DatabaseToolException::class);

        assert_same(DatabaseToolException::KIND_PREFLIGHT, $e->kind);
        assert_same(1049, $e->context['driver_code']);
        assert_same('connect', $e->context['operation']);
        assert_contains('create it first', (string) $e->hint);

        /* Operator-facing text carries no DSN and no password. */
        $visible = $e->getMessage() . ' ' . (string) $e->hint . ' ' . (string) json_encode($e->context);
        assert_not_contains('mysql:host=', $visible);
        assert_not_contains('dbname=', $visible);
        assert_false(isset($e->context['pass']), 'the context must not contain a password');

        /* Leave the scratch slot provisioned: the tests recreate it, they
         * never remove it (the grant belongs to the schema). */
        $target->create($db);
        assert_true($target->exists($db));
    },

    'bad credentials map to a 1045 hint and never echo the password' => function (): void {
        $secret = 'S3cr3t-' . bin2hex(random_bytes(6));
        $target = new DbTarget(DB_HOST, DB_PORT, 'ecowealth_no_such_user', $secret, DB_CHARSET, DB_TIMEZONE);

        $e = assert_throws(static fn (): PDO => $target->connect(), DatabaseToolException::class);

        assert_same(DatabaseToolException::KIND_PREFLIGHT, $e->kind);
        assert_same(1045, $e->context['driver_code']);
        assert_contains('DB_PASS', (string) $e->hint);

        $visible = $e->getMessage() . ' ' . (string) $e->hint . ' ' . (string) json_encode($e->context);
        assert_not_contains($secret, $visible);
    },

    'an unreachable server maps to a not-reachable hint' => function (): void {
        /* Port 1 is closed on a normal machine: a fast connection refusal. */
        $target = new DbTarget('127.0.0.1', 1, DB_USER, DB_PASS, DB_CHARSET, DB_TIMEZONE);

        $e = assert_throws(static fn (): PDO => $target->connect(), DatabaseToolException::class);

        assert_same(DatabaseToolException::KIND_PREFLIGHT, $e->kind);
        assert_same(2002, $e->context['driver_code']);
        assert_contains('not reachable', $e->getMessage());
        assert_contains('127.0.0.1:1', $e->getMessage());
        assert_contains('127.0.0.1:1', (string) $e->hint);
    },
];
