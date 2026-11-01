<?php

/**
 * @file   config/db.php
 * @brief  Database configuration
 */

// ── Database Configuration ──────────────────────────────────────────────────
// Copy this file and fill in your real credentials.
// Never commit real credentials to version control.
//
// Each constant is defined only when not already present, so a harness (see
// tests/bootstrap.php) can pre-define DB_NAME and friends to point the whole
// application at a throwaway schema without this file being edited. In normal
// operation nothing pre-defines them and the literals below are used as-is.

// ── Timezone ──────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_PORT') || define('DB_PORT', '3306');
defined('DB_NAME') || define('DB_NAME', 'u938213108_altaslife_db');
defined('DB_USER') || define('DB_USER', 'u938213108_altaslife_admin');
defined('DB_PASS') || define('DB_PASS', '2v$J#?M^&F:');

// ── Application Configuration ───────────────────────────────────────────────
define('APP_URL',  'http://localhost/altaslife');  // No trailing slash
define('APP_NAME', 'Altas Farm Livelihood');
define('APP_ENV',  'development');            // 'development' | 'production'

// ── Member Maintenance Banner ────────────────────────────────────────────────
// true → persistent warning banner on all member pages (rendered in topbar.php).
// Independent of maintenance_mode in index.php (hard site-wide 503 block).
define('MEMBER_MAINTENANCE_BANNER', false);

// ── Error display ────────────────────────────────────────────────────────────
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
}

/**
 * Returns a singleton PDO connection.
 * Usage: db()->prepare(...)
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4;CLIENT_FOUND_ROWS=true',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            if (APP_ENV === 'development') {
                die('<pre>Database connection failed: ' . $e->getMessage() . '</pre>');
            }
            die('Service temporarily unavailable. Please try again later.');
        }
    }

    return $pdo;
}
