<?php
declare(strict_types=1);

/* ==============================================================
 *  DbTarget — one safe way to reach MySQL as the installation target
 *  (plan IR-1, §5.5)
 *
 *  The installers, the reset engine and the tool tests all need the
 *  same three things: a PDO connection to the server *or* to one
 *  schema, a way to ask whether a schema exists / how many tables it
 *  has, and a single, tested place where an operator-chosen database
 *  name becomes an identifier. This class owns those and nothing else:
 *  it renders no HTML, reads no superglobal and runs no business SQL.
 *
 *      $target = DbTarget::fromConfig();
 *      $pdo    = $target->connect();            // server-level (no dbname)
 *      $pdo    = $target->connect('my_db');     // one schema selected
 *      $target->exists('my_db');                // true/false
 *      $target->create('my_db');                // CREATE DATABASE
 *      $target->tables('my_db');                // sorted list<string>
 *
 *  Error policy: a driver failure is mapped to an operator hint (bad
 *  credentials, missing database, unreachable server, missing CREATE
 *  privilege) and thrown as DatabaseToolException. The raw MySQL text,
 *  which can embed the user name or DSN, is written to error_log() only.
 * ============================================================== */

final class DbTarget
{
    /** The only shape allowed to become a DSN database or a DDL identifier. */
    public const NAME_PATTERN = '/^[A-Za-z0-9_]{1,64}$/';

    /** Connect timeout in seconds: a shared host must fail fast, not hang a page. */
    private const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly string $host = DB_HOST,
        private readonly int $port = DB_PORT,
        private readonly string $user = DB_USER,
        private readonly string $pass = DB_PASS,
        private readonly string $charset = DB_CHARSET,
        private readonly string $timezone = DB_TIMEZONE
    ) {
    }

    /** The connection described by app/config.php (or config.local.php). */
    public static function fromConfig(): self
    {
        return new self();
    }

    /**
     * PDO bound to one database, or to the server when $database is null.
     *
     * The session is set up the same way app/database.php sets it up
     * (utf8mb4 + the display time zone), so a value written through this
     * connection has the same shape as one written by the application.
     */
    public function connect(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $this->host,
            $this->port,
            $this->charset
        );

        if ($database !== null) {
            $database = $this->assertSafeDatabaseName($database);

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->host,
                $this->port,
                $database,
                $this->charset
            );
        }

        try {
            $pdo = new PDO($dsn, $this->user, $this->pass, self::options());
            $pdo->exec('SET NAMES ' . $this->charset);
            $pdo->exec('SET time_zone = ' . $pdo->quote($this->timezone));
        } catch (PDOException $e) {
            throw $this->failure($e, 'connect', $database);
        }

        return $pdo;
    }

    /** Does the schema exist on the server? */
    public function exists(string $database): bool
    {
        $database = $this->assertSafeDatabaseName($database);

        $statement = $this->connect()->prepare(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
        );
        $statement->execute([$database]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Create one schema (utf8mb4 / utf8mb4_unicode_ci).
     *
     * Only the installers call this, and only when the operator asked
     * for it: shared hosting usually grants ALL on one prefixed schema
     * but not the global CREATE DATABASE privilege. The failure message
     * says so instead of leaking the driver text.
     */
    public function create(string $database): void
    {
        $database = $this->assertSafeDatabaseName($database);

        try {
            $this->connect()->exec(sprintf(
                'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $database
            ));
        } catch (PDOException $e) {
            throw $this->failure($e, 'create', $database);
        }
    }

    /**
     * Every base table in the schema, sorted.
     *
     * Views are excluded on purpose: the installer and the reset engine
     * reason about tables, and information_schema is used instead of
     * SHOW so the output is identical across MySQL/MariaDB versions.
     *
     * @return list<string>
     */
    public function tables(string $database): array
    {
        $database = $this->assertSafeDatabaseName($database);

        $statement = $this->connect()->prepare(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE'"
        );
        $statement->execute([$database]);

        $tables = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($tables, SORT_STRING);

        return $tables;
    }

    public function tableCount(string $database): int
    {
        $database = $this->assertSafeDatabaseName($database);

        $statement = $this->connect()->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE'"
        );
        $statement->execute([$database]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Validate a database name before it can reach a DSN or DDL.
     *
     * This is the only place a database name becomes an identifier;
     * every public method above calls it first, so an unsafe name is
     * rejected before any SQL is sent.
     */
    public function assertSafeDatabaseName(string $database): string
    {
        if (preg_match(self::NAME_PATTERN, $database) !== 1) {
            throw DatabaseToolException::invalidName($database);
        }

        return $database;
    }

    /** @return array<int, mixed> */
    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => self::CONNECT_TIMEOUT,
        ];
    }

    /**
     * Map a driver failure to an operator-facing message + hint.
     *
     * The raw message goes to error_log() only: it can contain the user
     * name, the host and (on some drivers) the DSN.
     */
    private function failure(PDOException $e, string $operation, ?string $database): DatabaseToolException
    {
        $sqlstate   = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        /* PDO leaves errorInfo empty for some connection-level failures;
         * the driver code is still present in the bracketed message. */
        if ($driverCode === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $match) === 1) {
            $driverCode = (int) $match[1];
        }

        error_log(sprintf(
            '[ecowealth] db target %s%s failed: %s',
            $operation,
            $database === null ? '' : ' (' . $database . ')',
            $e->getMessage()
        ));

        $where = $this->host . ':' . $this->port;

        [$message, $hint] = match (true) {
            $driverCode === 1045 => [
                'MySQL refused the credentials for this connection.',
                'Check DB_USER and DB_PASS in app/config.local.php (MySQL error 1045: access denied).',
            ],
            $driverCode === 1049 => [
                sprintf('The database "%s" does not exist.', (string) $database),
                'The schema does not exist — create it first, or let the installer create it '
                    . 'with --create-database (MySQL error 1049).',
            ],
            in_array($driverCode, [2002, 2003, 1130], true) => [
                sprintf('MySQL is not reachable at %s.', $where),
                sprintf(
                    'Is the MySQL server running and accepting connections on %s? '
                        . 'On shared hosting, the host may refuse a connection from this machine (MySQL error %d).',
                    $where,
                    $driverCode
                ),
            ],
            $driverCode === 1044 => $operation === 'create'
                ? [
                    sprintf('The MySQL user is not allowed to create the database "%s".', (string) $database),
                    'Shared hosting usually grants ALL on one prefixed database — create it in the hosting panel '
                        . '(hPanel → Databases) instead of granting the global CREATE DATABASE privilege (MySQL error 1044).',
                ]
                : [
                    sprintf('The MySQL user has no access to the database "%s".', (string) $database),
                    'Check DB_NAME and DB_USER in app/config.local.php: the MySQL user needs ALL on that schema '
                        . '(MySQL error 1044).',
                ],
            $driverCode === 1007 => [
                sprintf('The database "%s" already exists.', (string) $database),
                'The installer never reuses an existing schema; choose another name or drop the old one first (MySQL error 1007).',
            ],
            default => [
                'Could not connect to the MySQL server.',
                'Check the database settings in app/config.local.php and that the MySQL server is running.',
            ],
        };

        return DatabaseToolException::preflight(
            $message,
            [
                'operation'   => $operation,
                'database'    => $database,
                'host'        => $this->host,
                'port'        => $this->port,
                'sqlstate'    => $sqlstate,
                'driver_code' => $driverCode,
            ],
            $hint
        );
    }
}
