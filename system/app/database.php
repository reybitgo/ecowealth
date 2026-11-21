<?php
declare(strict_types=1);

/* ==============================================================
 *  Sneat Auth — PDO / MySQL connection
 * ============================================================== */

/**
 * Shared PDO connection (lazy, one per request).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        /* Keep MySQL session times aligned with the display timezone.
         *
         * This used to be PDO::MYSQL_ATTR_INIT_COMMAND, which PHP 8.5
         * deprecates in favour of Pdo\Mysql::ATTR_INIT_COMMAND. Running
         * the statement normally keeps the same behaviour on every PHP
         * version the app supports (8.3+) and avoids the deprecated
         * driver constant entirely. */
        $pdo->exec('SET time_zone = ' . $pdo->quote((string) DB_TIMEZONE));
    } catch (PDOException $e) {
        error_log('[sneat-auth] DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit(
            '<!doctype html><html><body style="font-family:sans-serif;padding:3rem">'
            . '<h2>Database connection failed</h2>'
            . '<p>The MySQL server is not reachable. Please try again in a moment.</p>'
            . '</body></html>'
        );
    }

    return $pdo;
}
