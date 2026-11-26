<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — test bootstrap (plan §8.2)
 *
 *  Run with:  php tests/run.php  [filter]
 *
 *  Creates a throwaway schema (ecowealth_sneat_test) from
 *  database/schema.sql + the seeds, points the app at it, and
 *  exposes the assertion helpers the cases use.
 *
 *  It also exposes the IR-1 database-tool helpers: two pre-provisioned
 *  scratch schemas (test_scratch_database) and information_schema
 *  introspection used to compare an import with a fresh schema
 *  (test_table_list / test_column_list / test_index_list /
 *  test_foreign_key_list).
 *
 *  A test run never touches the development database: the schema
 *  is dropped and rebuilt, and the name must end in "_test".
 * ============================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

/* APP_ENV=test makes config.php pick ecowealth_sneat_test. The base URL
 * and upload root are pinned so url()/Upload behave like the real app
 * without writing into the repository (the harness stays read-only). */
define('APP_ENV', 'test');
define('APP_DEBUG', true);
define('APP_BASE_URL', '/ecowealth/system/');
define('TEST_MIGRATION_DB', 'ecowealth_sneat_migration_test');

/* Laragon points PHP's sessions at C:/laragon/tmp, which a sandboxed CLI
 * run cannot write. Keep test sessions in the system temp folder. */
ini_set('session.save_path', sys_get_temp_dir());

if (!defined('UPLOAD_ROOT')) {
    define('UPLOAD_ROOT', sys_get_temp_dir() . '/ecowealth-test-uploads/');
}

/* Cron logs and locks also go to scratch space: a test run must not
 * write into system/storage (the harness stays read-only). */
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', sys_get_temp_dir() . '/ecowealth-test-storage/');
}

/* config.php only declares constants; the rest of the app is loaded
 * after the test schema exists, so db() never touches a dropped schema. */
require_once dirname(__DIR__) . '/app/config.php';

/* --------------------------------------------------------------
 *  Assertions
 * -------------------------------------------------------------- */

final class TestFailure extends RuntimeException
{
}

function fail(string $message): never
{
    throw new TestFailure($message);
}

function assert_true(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        fail($message);
    }
}

function assert_false(bool $condition, string $message = 'Expected false'): void
{
    if ($condition) {
        fail($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ' — ' : '')
            . 'expected ' . test_export($expected) . ', got ' . test_export($actual));
    }
}

function assert_not_same(mixed $unexpected, mixed $actual, string $message = ''): void
{
    if ($unexpected === $actual) {
        fail(($message !== '' ? $message . ' — ' : '') . 'did not expect ' . test_export($unexpected));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if ($needle === '' || !str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ' — ' : '') . 'expected to find "' . $needle . '"');
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if ($needle !== '' && str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ' — ' : '') . 'did not expect to find "' . $needle . '"');
    }
}

function assert_count(int $expected, array|Countable $items, string $message = ''): void
{
    $actual = count($items);
    if ($actual !== $expected) {
        fail(($message !== '' ? $message . ' — ' : '') . "expected $expected items, got $actual");
    }
}

function assert_null(mixed $value, string $message = 'Expected null'): void
{
    if ($value !== null) {
        fail($message . ' — got ' . test_export($value));
    }
}

function assert_not_null(mixed $value, string $message = 'Expected a value'): void
{
    if ($value === null) {
        fail($message);
    }
}

/** @return Throwable The exception that was thrown. */
function assert_throws(callable $fn, string $class, string $message = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            fail(($message !== '' ? $message . ' — ' : '')
                . 'expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
        }

        return $e;
    }

    fail(($message !== '' ? $message . ' — ' : '') . 'expected ' . $class . ' to be thrown');
}

function test_export(mixed $value): string
{
    if (is_string($value)) {
        return strlen($value) > 200 ? '"' . substr($value, 0, 200) . '…"' : '"' . $value . '"';
    }

    if (is_object($value)) {
        return $value::class;
    }

    return var_export($value, true);
}

/* --------------------------------------------------------------
 *  Database: rebuild the throwaway schema
 * -------------------------------------------------------------- */

/**
 * @param array{db?: string, sql?: list<string>} $options
 */
function test_pdo(array $options = []): PDO
{
    $database = $options['db'] ?? null;

    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    if (is_string($database) && $database !== '') {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, $database, DB_CHARSET);
    }

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/** Split a .sql file into executable statements (comments dropped). */
function test_sql_statements(string $sql): array
{
    $statements = [];
    $buffer     = '';

    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }

        $buffer .= $line . "\n";

        if (str_ends_with(rtrim($line), ';')) {
            $statements[] = rtrim(trim($buffer), ';');
            $buffer       = '';
        }
    }

    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function test_run_sql_file(PDO $pdo, string $file): void
{
    if (!is_file($file)) {
        fail('SQL file not found: ' . $file);
    }

    foreach (test_sql_statements((string) file_get_contents($file)) as $statement) {
        $pdo->exec($statement);
    }
}

/** Create (or recreate) a throwaway database and select it. */
function test_recreate_database(PDO $pdo, string $database): void
{
    if (!str_ends_with($database, '_test')) {
        fail('Refusing to drop a database whose name does not end in "_test": ' . $database);
    }

    $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $database . '`');
}

/* --------------------------------------------------------------
 *  Scratch schemas for the database tools (plan IR-1-T4)
 *
 *  The application user has ALL on a few named schemas but no global
 *  CREATE DATABASE, so the install/reset tests cannot make a schema of
 *  their own. Two schemas are provisioned once by an admin account
 *  (ecowealth_sneat_tool_a_test / _tool_b_test) and recreated by the
 *  helper below; they are never dropped at shutdown, because that would
 *  remove the grants and break the next run.
 * -------------------------------------------------------------- */

/**
 * Recreate one of the two pre-provisioned scratch schemas.
 *
 * @param string $slot tool_a | tool_b
 * @return array{0: PDO, 1: string} the connection (already USE-d) and the schema name
 */
function test_scratch_database(string $slot = 'tool_a'): array
{
    if (!in_array($slot, ['tool_a', 'tool_b'], true)) {
        fail('Unknown scratch slot: ' . $slot);
    }

    $name = 'ecowealth_sneat_' . $slot . '_test';
    $pdo  = test_pdo();

    try {
        test_recreate_database($pdo, $name);
    } catch (PDOException $e) {
        fail(
            'Cannot recreate ' . $name . ': ' . $e->getMessage() . "\n"
            . 'Provision it once (see plan IR-1-T4) and grant ALL to ' . DB_USER . '.'
        );
    }

    return [$pdo, $name];
}

/** The schema the connection currently has selected. */
function test_current_database(PDO $pdo): string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();

    if (!is_string($database) || $database === '') {
        fail('The connection has no database selected; pass $database explicitly.');
    }

    return $database;
}

/**
 * Base tables in a schema, sorted.
 *
 * information_schema is used instead of SHOW TABLE STATUS so the output
 * is identical on MySQL and MariaDB minor versions.
 *
 * @return list<string>
 */
function test_table_list(PDO $pdo, ?string $database = null): array
{
    $database ??= test_current_database($pdo);

    $statement = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = ? AND table_type = 'BASE TABLE'"
    );
    $statement->execute([$database]);

    $tables = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    sort($tables, SORT_STRING);

    return $tables;
}

/**
 * Every column in the schema, keyed by table, in ordinal order.
 *
 * @return array<string, list<array{
 *     name:string, type:string, nullable:string, default:?string,
 *     extra:string, collation:?string
 * }>>
 */
function test_column_list(PDO $pdo, ?string $database = null): array
{
    $database ??= test_current_database($pdo);

    /* MySQL 8 returns information_schema labels in upper case unless they
     * are aliased; alias every column so the arrays are identical on MySQL
     * and MariaDB. */
    $statement = $pdo->prepare(
        'SELECT table_name AS table_name, column_name AS column_name,
                column_type AS column_type, is_nullable AS is_nullable,
                column_default AS column_default, extra AS extra,
                collation_name AS collation_name
           FROM information_schema.columns
          WHERE table_schema = ?
          ORDER BY table_name, ordinal_position'
    );
    $statement->execute([$database]);

    $columns = [];

    foreach ($statement->fetchAll() as $row) {
        $columns[(string) $row['table_name']][] = [
            'name'      => (string) $row['column_name'],
            'type'      => (string) $row['column_type'],
            'nullable'  => (string) $row['is_nullable'],
            'default'   => $row['column_default'] === null ? null : (string) $row['column_default'],
            'extra'     => (string) $row['extra'],
            'collation' => $row['collation_name'] === null ? null : (string) $row['collation_name'],
        ];
    }

    ksort($columns, SORT_STRING);

    return $columns;
}

/**
 * Every index in the schema, keyed by table and sorted by index name.
 *
 * @return array<string, list<array{
 *     name:string, unique:bool, type:string,
 *     columns:list<array{column:string, prefix:?int}>
 * }>>
 */
function test_index_list(PDO $pdo, ?string $database = null): array
{
    $database ??= test_current_database($pdo);

    $statement = $pdo->prepare(
        'SELECT table_name AS table_name, index_name AS index_name,
                non_unique AS non_unique, seq_in_index AS seq_in_index,
                column_name AS column_name, sub_part AS sub_part,
                index_type AS index_type
           FROM information_schema.statistics
          WHERE table_schema = ?
          ORDER BY table_name, index_name, seq_in_index'
    );
    $statement->execute([$database]);

    /** @var array<string, array<string, array{name:string, unique:bool, type:string, columns:list<array{column:string, prefix:?int}>}>> $indexes */
    $indexes = [];

    foreach ($statement->fetchAll() as $row) {
        $table = (string) $row['table_name'];
        $name  = (string) $row['index_name'];

        $indexes[$table][$name] ??= [
            'name'    => $name,
            'unique'  => (int) $row['non_unique'] === 0,
            'type'    => (string) $row['index_type'],
            'columns' => [],
        ];

        $indexes[$table][$name]['columns'][] = [
            'column' => (string) $row['column_name'],
            'prefix' => $row['sub_part'] === null ? null : (int) $row['sub_part'],
        ];
    }

    $result = [];

    foreach ($indexes as $table => $byName) {
        ksort($byName, SORT_STRING);
        $result[(string) $table] = array_values($byName);
    }

    ksort($result, SORT_STRING);

    return $result;
}

/**
 * Every foreign key in the schema, keyed by table and sorted by name.
 *
 * @return array<string, list<array{
 *     name:string, columns:list<string>, referenced_table:string,
 *     referenced_columns:list<string>, delete_rule:string, update_rule:string
 * }>>
 */
function test_foreign_key_list(PDO $pdo, ?string $database = null): array
{
    $database ??= test_current_database($pdo);

    $statement = $pdo->prepare(
        'SELECT k.table_name AS table_name, k.constraint_name AS constraint_name,
                k.column_name AS column_name, k.referenced_table_name AS referenced_table_name,
                k.referenced_column_name AS referenced_column_name,
                r.delete_rule AS delete_rule, r.update_rule AS update_rule
           FROM information_schema.key_column_usage AS k
           JOIN information_schema.referential_constraints AS r
             ON r.constraint_schema = k.constraint_schema
            AND r.constraint_name   = k.constraint_name
          WHERE k.table_schema = ?
            AND k.referenced_table_name IS NOT NULL
          ORDER BY k.table_name, k.constraint_name, k.ordinal_position'
    );
    $statement->execute([$database]);

    /** @var array<string, array<string, array{name:string, columns:list<string>, referenced_table:string, referenced_columns:list<string>, delete_rule:string, update_rule:string}>> $keys */
    $keys = [];

    foreach ($statement->fetchAll() as $row) {
        $table = (string) $row['table_name'];
        $name  = (string) $row['constraint_name'];

        $keys[$table][$name] ??= [
            'name'               => $name,
            'columns'            => [],
            'referenced_table'   => (string) $row['referenced_table_name'],
            'referenced_columns' => [],
            'delete_rule'        => (string) $row['delete_rule'],
            'update_rule'        => (string) $row['update_rule'],
        ];

        $keys[$table][$name]['columns'][]            = (string) $row['column_name'];
        $keys[$table][$name]['referenced_columns'][] = (string) $row['referenced_column_name'];
    }

    $result = [];

    foreach ($keys as $table => $byName) {
        ksort($byName, SORT_STRING);
        $result[(string) $table] = array_values($byName);
    }

    ksort($result, SORT_STRING);

    return $result;
}

function test_reset_app_database(): void
{
    $pdo = test_pdo();
    test_recreate_database($pdo, DB_NAME);

    test_run_sql_file($pdo, dirname(__DIR__) . '/database/schema.sql');
    test_run_seed_files($pdo);

    unset($GLOBALS['__settings_cache']);
}

/** The production seed files every database build (and every reset) runs. */
function test_run_seed_files(PDO $pdo): void
{
    $databaseDir = dirname(__DIR__) . '/database/';

    foreach ([
        'seeds/001_settings.sql',
        'seeds/002_accounts.sql',
        'seeds/003_products.sql',
        'seeds/004_packages.sql',
        'seeds/005_demo_tree.sql',
    ] as $seed) {
        test_run_sql_file($pdo, $databaseDir . $seed);
    }
}

/* --------------------------------------------------------------
 *  Fixtures
 * -------------------------------------------------------------- */

/**
 * Create (or fetch) a user for guard/member tests.
 *
 * @return array<string, mixed>
 */
function test_user(string $username = 'member', string $role = 'member', string $password = 'Member@123'): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existing = $stmt->fetch();

    if ($existing !== false) {
        return $existing;
    }

    db()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, member_status,
                            referral_code, is_active)
         VALUES (?, ?, ?, ?, ?, \'active\', ?, 1)'
    )->execute([
        $username,
        $username . '@test.local',
        password_hash($password, PASSWORD_BCRYPT),
        ucfirst($username) . ' Test',
        $role,
        strtoupper(bin2hex(random_bytes(4))),
    ]);

    $stmt->execute([$username]);

    return (array) $stmt->fetch();
}

/**
 * Create a real order fixture for reservation / reference tests.
 *
 * The order tables exist from Phase 6 onward, so cases no longer need
 * local DDL (which would commit the harness transaction).
 *
 * @param list<array{product_id:int, quantity?:int, reservation_state?:string}> $lines
 * @param array<string, mixed> $overrides optional column => value overrides
 * @return int the order id
 */
function test_order(int $userId, array $lines = [], string $status = 'pending', array $overrides = []): int
{
    $orderNo = 'TEST-' . strtoupper(bin2hex(random_bytes(5)));
    $values  = [
        'order_no'         => $orderNo,
        'user_id'          => $userId,
        'status'           => $status,
        'payment_method'   => 'ewallet',
        'billing_name'     => 'Test Buyer',
        'shipping_name'    => 'Test Buyer',
        'shipping_address' => 'Test address',
        'terms_accepted_at' => date('Y-m-d H:i:s'),
    ] + $overrides;

    $columns = array_keys($values);
    $marks   = implode(', ', array_fill(0, count($columns), '?'));

    db()->prepare(
        'INSERT INTO shop_orders (' . implode(', ', $columns) . ') VALUES (' . $marks . ')'
    )->execute(array_values($values));

    $orderId = (int) db()->lastInsertId();
    $stmt    = db()->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total, reservation_state)
         VALUES (?, ?, 'Test product', NULL, ?, 0.00, 0.00, ?)"
    );

    foreach ($lines as $line) {
        $stmt->execute([
            $orderId,
            (int) $line['product_id'],
            (int) ($line['quantity'] ?? 1),
            (string) ($line['reservation_state'] ?? 'reserved'),
        ]);
    }

    return $orderId;
}

/** Remove one fixture order (items and events cascade). */
function test_delete_order(int $orderId): void
{
    db()->prepare('DELETE FROM shop_orders WHERE id = ?')->execute([$orderId]);
}

/**
 * Mark a fixture order delivered (the Phase 18 release precondition).
 *
 * Only a paid/packing/ready/shipped order is moved; an unpaid or already
 * cancelled fixture keeps its state, so a caller can hand every order to
 * this helper without changing a "not paid" case's meaning.
 */
function test_deliver_order(int $orderId, ?string $at = null): void
{
    db()->prepare(
        "UPDATE shop_orders
            SET status = 'delivered', delivered_at = ?
          WHERE id = ? AND status IN ('paid','packing','ready_to_ship','shipped','delivered')"
    )->execute([$at ?? date('Y-m-d H:i:s'), $orderId]);
}

/**
 * Deliver an order and run the Phase 18 release for it.
 *
 * A paid order now writes `pending` awards and moves no money; most page
 * fixtures want the post-release state (the wallet is credited). A case
 * that needs the pre-release state calls the service directly instead.
 *
 * @return array{order_id:int, released:int, awards:list<int>, amount:float, skipped:int, reason?:string}
 */
function test_release_unilevel(int $orderId, ?string $at = null): array
{
    test_deliver_order($orderId, $at);

    return (new UnilevelService())->releaseForOrder($orderId);
}

/**
 * Reproduce the Phase 17 shape on a paid, still-cancellable order: every
 * pending award of the order is credited immediately.
 *
 * Used by cases that prove the post-release reversal path (plan §6.4),
 * which only legacy rows can reach now that cancellation stops at
 * `ready_to_ship` and release starts at `delivered`.
 */
function test_legacy_credit_unilevel(int $orderId): void
{
    $wallet = new WalletService();

    foreach (UnilevelAward::forOrder($orderId) as $row) {
        if ((string) $row['status'] !== 'pending') {
            continue;
        }

        $movement = $wallet->credit(
            (int) $row['user_id'],
            (float) $row['amount'],
            'unilevel',
            (int) $row['id'],
            'Legacy repeat-purchase award',
            true
        );

        UnilevelAward::markCredited((int) $row['id'], (int) $movement['ledger_id']);
    }
}

/**
 * Open or close the order-cancellation kill switch for a case.
 *
 * `shop_cancel_enabled` is off in the production seed and in this test
 * schema, because that is the shipped default. Cases that exercise the
 * legacy cancellation/refund path (or a page that offers the Cancel order
 * form) opt in explicitly; the default-off cases live in
 * `CancelSettingTest`. The setting change rolls back with the case.
 */
function test_enable_order_cancel(bool $enabled = true): void
{
    setting_set('shop_cancel_enabled', $enabled ? '1' : '0');
    setting_cache_clear();

    /* Reload the full table immediately. Without this, the next
     * setting_set() in a case would create a cache containing only the
     * keys that case writes, and a later setting() for
     * shop_cancel_enabled would miss the row while the raw database has
     * it (the setting would read off even though it was turned on). */
    setting_all();
}

/** Sign the harness in as the given user row (for layout/guard tests). */
function test_signed_in_as(?array $user): void{
    /* Never let an S-Login fixture leak between cases: the transport
     * keys are part of the session, not of the user row. */
    foreach ([
        'imp_session', 'imp_token', 'imp_log_id', 'imp_created_by',
        'imp_created_by_username', 'imp_target_username', 'imp_expires',
        'imp_ip', 'imp_ua', 'auth_via', '_slogin_attempts',
    ] as $key) {
        unset($_SESSION[$key]);
    }

    if ($user === null) {
        unset($_SESSION['user_id'], $_SESSION['logged_in_at']);

        // Forget the per-request cache as well.
        auth_cache_reset();

        return;
    }

    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['logged_in_at']  = time();
    $_SESSION['last_activity'] = time();
    auth_cache_reset();
}

/**
 * Row counts of every base table in the harness schema.
 *
 * The suite's isolation is one transaction per case, which a case that
 * commits (concurrency and CLI fixtures must) or that runs DDL silently
 * ends. Comparing these counts to the seeded baseline detects rows that
 * survived the rollback, whether they were written by the case's own
 * connection or by a second one it opened.
 *
 * @return array<string, int>
 */
function test_table_counts_snapshot(): array
{
    $pdo    = db();
    $tables = $pdo->query(
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $counts = [];

    foreach ($tables as $table) {
        $counts[(string) $table] = (int) $pdo->query(
            'SELECT COUNT(*) FROM `' . (string) $table . '`'
        )->fetchColumn();
    }

    ksort($counts, SORT_STRING);

    return $counts;
}

/** Put the seeded baseline back after a case leaked rows or schema. */
function test_restore_baseline(): void
{
    $pdo            = db();
    $baselineTables = array_keys($GLOBALS['__test_baseline_counts'] ?? []);
    $tables         = array_keys(test_table_counts_snapshot());
    sort($tables, SORT_STRING);

    if ($tables === $baselineTables) {
        /* Same schema, leaked rows: empty the tables and reseed. Truncating
         * avoids the schema import, which is the slow half. */
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        test_run_seed_files($pdo);
    } else {
        /* A case dropped or added a table: rebuild the schema. */
        test_reset_app_database();

        /* test_reset_app_database() drops and recreates the schema through
         * its own connection; the app's connection must be pointed at it
         * again. */
        $pdo->exec('USE `' . DB_NAME . '`');
    }

    unset($GLOBALS['__settings_cache']);

    $GLOBALS['__test_baseline_counts'] = test_table_counts_snapshot();
}

/** Has anything survived the transaction since the baseline was taken? */
function test_schema_differs_from_baseline(): bool
{
    $baseline = $GLOBALS['__test_baseline_counts'] ?? null;

    if (!is_array($baseline)) {
        $GLOBALS['__test_baseline_counts'] = test_table_counts_snapshot();

        return false;
    }

    try {
        $counts = test_table_counts_snapshot();
    } catch (Throwable) {
        /* A case dropped a table or lost the schema outright. */
        return true;
    }

    return $counts !== $baseline;
}

/** Run a case inside a transaction that is rolled back afterwards. */
function test_run_isolated(callable $case): void
{
    $pdo = db();

    /* Isolation: registration under Phase 22C writes binary_nodes that
     * the outer rollback does not always undo (savepoint release).
     * Sweep any non-demo nodes so this case starts with a clean tree. */
    try {
        $pdo->exec("DELETE FROM binary_nodes WHERE user_id NOT IN (1,3,4,5,6,7)");
    } catch (Throwable) {
        /* Table may not exist in pre-22 phases; ignore. */
    }

    /* A case that left its transaction open must not hand it to this one. */
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable) {
            /* Nothing to undo. */
        }
    }

    $pdo->beginTransaction();

    /* Every case starts signed out, with no pending flash/old input and
     * no S-Login transport state left over from the previous case. */
    unset(
        $_SESSION['user_id'],
        $_SESSION['logged_in_at'],
        $_SESSION['_old'],
        $_SESSION['_errors'],
        $_SESSION['_flash'],
        $_SESSION['_old_payload'],
        $_SESSION['_live_publish'],
        $_SESSION['imp_session'],
        $_SESSION['imp_token'],
        $_SESSION['imp_log_id'],
        $_SESSION['imp_created_by'],
        $_SESSION['imp_created_by_username'],
        $_SESSION['imp_target_username'],
        $_SESSION['imp_expires'],
        $_SESSION['imp_ip'],
        $_SESSION['imp_ua'],
        $_SESSION['auth_via'],
        $_SESSION['_slogin_attempts'],
        $_SESSION['_throttle'],
        $GLOBALS['__form_errors'],
        $GLOBALS['__old_payload']
    );
    unset($GLOBALS['__throttle'], $GLOBALS['__live_publish']);
    auth_cache_reset();

    try {
        $case();
    } finally {
        $leaked = false;

        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            } else {
                /* The case committed or ran DDL: its transaction is gone. */
                $leaked = true;
            }
        } catch (Throwable) {
            $leaked = true;
        }

        /* Committed rows survive the rollback and a later case would inherit
         * them (the General Harvest group used to read a board an earlier
         * group had armed). Restore the seeded baseline before the next case. */
        if (!$leaked && test_schema_differs_from_baseline()) {
            $leaked = true;
        }

        if ($leaked) {
            test_restore_baseline();
        }

        /* Never let a rolled-back settings change linger in the cache. */
        unset($GLOBALS['__settings_cache']);
        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_URI'] = '/';
    }
}

/* --------------------------------------------------------------
 *  Boot the app against the fresh schema
 * -------------------------------------------------------------- */

test_reset_app_database();

require_once dirname(__DIR__) . '/app/bootstrap.php';

/* The baseline every case starts and ends on (see test_run_isolated()). */
$GLOBALS['__test_baseline_counts'] = test_table_counts_snapshot();

/* Upload fixture: a real 1×1 PNG, plus a scratch storage root. */
define('TEST_PNG_BYTES', base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
));

if (!is_dir((string) UPLOAD_ROOT)) {
    @mkdir((string) UPLOAD_ROOT . 'products', 0775, true);
    @mkdir((string) UPLOAD_ROOT . 'packages', 0775, true);
    @mkdir((string) UPLOAD_ROOT . 'proofs', 0775, true);
}

/* Cron scratch folders (logs + locks). */
@mkdir(rtrim((string) STORAGE_PATH, '/\\') . '/logs', 0775, true);
@mkdir(rtrim((string) STORAGE_PATH, '/\\') . '/locks', 0775, true);
