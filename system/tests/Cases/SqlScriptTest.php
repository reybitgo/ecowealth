<?php
declare(strict_types=1);

/* ==============================================================
 *  SqlScriptTest — the SQL parser/runner behind every IR phase
 *  (plan IR-1-T5, §5.5)
 *
 *  Each parser rule in the plan has a case here. The database-backed
 *  cases use ecowealth_sneat_tool_a_test, one of the two pre-provisioned
 *  scratch schemas (bootstrap.php :: test_scratch_database), so they
 *  never touch the harness database or the development schema.
 * ============================================================== */

$databaseDir = dirname(__DIR__, 2) . '/database/';

/** @return list<string> */
$sqlOf = static fn (SqlScript $script): array => array_column($script->statements(), 'sql');

/** @return list<int> */
$linesOf = static fn (SqlScript $script): array => array_column($script->statements(), 'line');

return [
    'plain statements split with their starting line numbers' => function () use ($sqlOf, $linesOf): void {
        $script = SqlScript::fromString("SELECT 1;\nSELECT 2;\n\nSELECT 3;\n");

        assert_same(['SELECT 1', 'SELECT 2', 'SELECT 3'], $sqlOf($script));
        assert_same([1, 2, 4], $linesOf($script));

        /* A final statement does not need a trailing semicolon. */
        $script = SqlScript::fromString("SELECT 1;\nSELECT 2");
        assert_same(['SELECT 1', 'SELECT 2'], $sqlOf($script));
        assert_same([1, 2], $linesOf($script));

        /* Empty statements are dropped, not run. */
        $script = SqlScript::fromString("SELECT 1;;\n ;\n\nSELECT 2;");
        assert_same(['SELECT 1', 'SELECT 2'], $sqlOf($script));
        assert_same(4, $script->statements()[1]['line']);

        /* A comments-only script has no statements at all. */
        assert_same([], SqlScript::fromString("-- only a comment\n\n/* and a block */\n")->statements());
    },

    'a semicolon inside a quoted string never splits the statement' => function () use ($sqlOf, $linesOf): void {
        $sql = <<<'SQL'
INSERT INTO t (a, b) VALUES ('semi;colon', "double;quote");
SELECT `odd;name` FROM t;
SQL;

        $script = SqlScript::fromString($sql);

        assert_same(
            [
                'INSERT INTO t (a, b) VALUES (\'semi;colon\', "double;quote")',
                'SELECT `odd;name` FROM t',
            ],
            $sqlOf($script)
        );
        assert_same([1, 2], $linesOf($script));
    },

    'doubled quotes and backslash escapes stay inside the string' => function () use ($sqlOf): void {
        $script = SqlScript::fromString(<<<'SQL'
INSERT INTO t (a, b, c) VALUES ('plan''s', 'line\nbreak', "say ""hi""");
SQL);

        $statements = $script->statements();
        assert_count(1, $statements);
        assert_contains("'plan''s'", $statements[0]['sql']);
        assert_contains("'line\\nbreak'", $statements[0]['sql']);
        assert_contains('"say ""hi"""', $statements[0]['sql']);

        /* A doubled backtick is an escaped identifier, not a terminator. */
        $script = SqlScript::fromString('SELECT `a``b;c` FROM t;');
        assert_count(1, $script->statements());
        assert_contains('`a``b;c`', $sqlOf($script)[0]);

        /* A backslash before the closing quote is an escaped quote. */
        $script = SqlScript::fromString("SELECT 'it\\'s; fine';");
        assert_count(1, $script->statements());
        assert_contains("it\\'s; fine", $sqlOf($script)[0]);
    },

    '-- is a comment only when followed by whitespace' => function () use ($sqlOf, $linesOf): void {
        $script = SqlScript::fromString(
            "SELECT 5--3 AS eight;\n"
            . "SELECT 'a -- b' AS label;\n"
            . "-- a full-line comment; with a semicolon\n"
            . "SELECT 2;"
        );

        assert_same(
            ['SELECT 5--3 AS eight', "SELECT 'a -- b' AS label", 'SELECT 2'],
            $sqlOf($script)
        );
        assert_same([1, 2, 4], $linesOf($script));

        /* A trailing comment is replaced by whitespace, never by nothing:
         * "SELECT 1 -- note\nFROM t" must stay two tokens. */
        $script = SqlScript::fromString("SELECT 1 -- note\nFROM t;");
        assert_count(1, $script->statements());
        assert_contains('SELECT 1', $sqlOf($script)[0]);
        assert_contains('FROM t', $sqlOf($script)[0]);
        assert_not_contains('note', $sqlOf($script)[0]);
    },

    '# and block comments are stripped and keep the line numbers' => function () use ($sqlOf, $linesOf): void {
        $script = SqlScript::fromString(<<<'SQL'
# settings file
SELECT 1; /* block
comment; still a comment */
/*!40101 SET NAMES utf8mb4 */;
SELECT 2 /* inline */ + 3;
SQL);

        assert_same(['SELECT 1', 'SELECT 2  + 3'], $sqlOf($script));
        assert_same([2, 5], $linesOf($script));

        /* MySQL version comments are comments here, never fragments. */
        assert_not_contains('40101', implode(' ', $sqlOf($script)));
    },

    'a DELIMITER block is rejected with a clear message' => function (): void {
        $e = assert_throws(
            static fn (): array => SqlScript::fromString(
                "SELECT 1;\nDELIMITER $$\nCREATE PROCEDURE p() BEGIN SELECT 1; END$$\nDELIMITER ;\n"
            )->statements(),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_SQL, $e->kind);
        assert_contains('DELIMITER', $e->getMessage());
        assert_same(2, $e->context['line']);
        assert_same('delimiter', $e->context['reason']);
    },

    'a truncated script cannot end inside a string or a comment' => function (): void {
        $e = assert_throws(
            static fn (): array => SqlScript::fromString("SELECT 'unterminated;")->statements(),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_SQL, $e->kind);
        assert_contains('unterminated', $e->getMessage());
        assert_same('unterminated_string', $e->context['reason']);
        assert_same(1, $e->context['line']);

        $e = assert_throws(
            static fn (): array => SqlScript::fromString("SELECT 1;\n/* never closed\n")->statements(),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_SQL, $e->kind);
        assert_same('unterminated_comment', $e->context['reason']);
        assert_same(2, $e->context['line']);
    },

    'containsDatabaseSwitch sees statements, never comments or strings' => function (): void {
        assert_true(SqlScript::fromString('CREATE DATABASE other;')->containsDatabaseSwitch());
        assert_true(SqlScript::fromString('create schema other;')->containsDatabaseSwitch());
        assert_true(SqlScript::fromString('USE other;')->containsDatabaseSwitch());
        assert_true(SqlScript::fromString("use `other`;")->containsDatabaseSwitch());
        assert_true(SqlScript::fromString("SELECT 1;\nUSE other;")->containsDatabaseSwitch());

        /* Prose in the header, a string literal and a comment are not code. */
        assert_false(SqlScript::fromString(
            "-- CREATE DATABASE elsewhere is forbidden\n"
            . "-- usage: mysql < install.sql\n"
            . "SELECT 1;"
        )->containsDatabaseSwitch());
        assert_false(SqlScript::fromString(
            "SELECT 'CREATE DATABASE other', \"USE other\";"
        )->containsDatabaseSwitch());
        assert_false(SqlScript::fromString('SELECT 1; /* USE other; */')->containsDatabaseSwitch());
        assert_false(SqlScript::fromString('SELECT `use` FROM t;')->containsDatabaseSwitch());
    },

    'a failing statement names its index, line and driver code' => function (): void {
        [$pdo, $db] = test_scratch_database('tool_a');

        $script = SqlScript::fromString(
            "CREATE TABLE probe (id INT NOT NULL PRIMARY KEY);\n"
            . "SELECT * FROM definitely_missing_table;\n"
            . "INSERT INTO probe (id) VALUES (1);"
        );

        $e = assert_throws(static fn (): array => $script->run($pdo), DatabaseToolException::class);

        assert_same(DatabaseToolException::KIND_SQL, $e->kind);
        assert_same(2, $e->context['statement']);
        assert_same(2, $e->context['line']);
        assert_true($e->context['driver_code'] > 0, 'the driver code must be recorded');

        assert_contains('statement 2', $e->getMessage());
        assert_contains('line 2', $e->getMessage());
        assert_contains((string) $e->context['driver_code'], $e->getMessage());
        assert_contains('definitely_missing_table', $e->context['sql']);
        assert_true(strlen($e->context['sql']) <= 210, 'the snippet is capped near 200 characters');

        /* Nothing after the failing statement ran. */
        assert_same(['probe'], test_table_list($pdo, $db));
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn());

        /* Operator-facing text never carries a DSN or a password. */
        assert_not_contains('mysql:host=', $e->getMessage());
        assert_not_contains('dbname=', $e->getMessage());
    },

    'schema.sql imports through the runner into a scratch schema' => function () use ($databaseDir): void {
        [$pdo, $db] = test_scratch_database('tool_a');

        $script = SqlScript::fromFile($databaseDir . 'schema.sql');
        $result = $script->run($pdo);

        assert_true($result['statements'] >= 31, 'the schema has at least one statement per table');
        assert_true(is_int($result['elapsed_ms']));

        $expected = [
            'activations', 'addresses', 'audit_log', 'cart_items', 'carts', 'commissions',
            'ewallet_admin_topups', 'ewallet_ledger', 'ewallet_transfers', 'impersonation_log',
            'live_revisions', 'package_levels', 'package_products', 'packages', 'payout_requests',
            'product_unilevel_levels', 'products', 'reg_codes', 'remember_tokens', 'settings',
            'shop_invoice_items', 'shop_invoices', 'shop_order_events', 'shop_order_items',
            'shop_orders', 'shop_payment_proofs', 'shop_shipments', 'unilevel_activity',
            'unilevel_awards', 'unilevel_periods', 'users',
        ];
        sort($expected);

        assert_same($expected, test_table_list($pdo, $db));
        assert_same($script->statements(), $script->statements(), 'the parsed statements are cached and stable');
    },

    'the application SQL sources contain no executable database switch' => function () use ($databaseDir): void {
        $files = array_merge(
            [$databaseDir . 'schema.sql'],
            glob($databaseDir . 'seeds/*.sql') ?: [],
            glob($databaseDir . 'migrations/*.sql') ?: []
        );

        assert_true(count($files) >= 25, 'the sources are found');

        foreach ($files as $file) {
            assert_false(
                SqlScript::fromFile($file)->containsDatabaseSwitch(),
                basename($file) . ' contains an executable CREATE DATABASE/USE'
            );
        }
    },

    'checksum ignores line endings and trailing whitespace but not content' => function (): void {
        $base = SqlScript::fromString("SELECT 1;\nSELECT 2;\n");

        assert_same($base->checksum(), $base->checksum());
        assert_same(
            $base->checksum(),
            SqlScript::fromString("\xEF\xBB\xBFSELECT 1;\r\nSELECT 2;")->checksum(),
            'a BOM, CRLF endings and a missing trailing newline are normalised'
        );
        assert_same(
            $base->checksum(),
            SqlScript::fromString("SELECT 1;   \nSELECT 2;\n\n\n")->checksum(),
            'trailing spaces and blank lines are normalised'
        );
        assert_not_same(
            $base->checksum(),
            SqlScript::fromString("SELECT 1;\nSELECT 3;\n")->checksum(),
            'different content must not hash the same'
        );
        assert_same(64, strlen($base->checksum()), 'sha256 hex');
    },

    'run reports the statement count and calls the progress hook' => function (): void {
        [$pdo] = test_scratch_database('tool_a');

        $seen   = [];
        $script = SqlScript::fromString("SELECT 1;\n\nSELECT 2;");

        $result = $script->run($pdo, static function (int $index, string $sql, int $line) use (&$seen): void {
            $seen[] = [$index, $sql, $line];
        });

        assert_same(2, $result['statements']);
        assert_true($result['elapsed_ms'] >= 0);
        assert_same([[1, 'SELECT 1', 1], [2, 'SELECT 2', 3]], $seen);
    },

    'a missing SQL file is a usage error' => function (): void {
        $e = assert_throws(
            static fn (): SqlScript => SqlScript::fromFile(__DIR__ . '/does-not-exist.sql'),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_USAGE, $e->kind);
        assert_contains('not readable', $e->getMessage());
        assert_contains('does-not-exist.sql', $e->getMessage());
    },

    'the statement cap stops a runaway script' => function (): void {
        $e = assert_throws(
            static fn (): array => SqlScript::fromString(
                str_repeat("SELECT 1;\n", SqlScript::MAX_STATEMENTS + 1)
            )->statements(),
            DatabaseToolException::class
        );

        assert_same(DatabaseToolException::KIND_USAGE, $e->kind);
        assert_contains('more than ' . SqlScript::MAX_STATEMENTS, $e->getMessage());
    },
];
