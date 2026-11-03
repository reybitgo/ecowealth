<?php

declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — Application Configuration
 *
 *  Defaults only: nothing secret is committed here. Local
 *  credentials and flags live in config.local.php (git-ignored),
 *  loaded first, and every constant falls back to a safe default.
 * ============================================================== */

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

/* ---------- Database (MySQL) ---------- */
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_NAME') || define('DB_NAME', 'ecowealth_sneat');
defined('DB_USER') || define('DB_USER', 'ecowealth');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

/* MySQL session time zone (Asia/Manila, UTC+8) */
defined('DB_TIMEZONE') || define('DB_TIMEZONE', '+08:00');

/* ---------- Application ---------- */
defined('APP_NAME') || define('APP_NAME', 'EcoWealth');
defined('APP_TAGLINE') || define('APP_TAGLINE', 'Membership, shop and e-wallet');

/* PHP display timezone (Asia/Manila) */
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Manila');

/* ---------- URL routing -------------------------------------------------------
 * The app auto-detects where it is installed:
 *
 *   • Document root (vhost / php -S)   →  /login, /register, /dashboard
 *   • Sub-folder (e.g. Laragon www)    →  /ecowealth/system/login …
 *
 * Clean URLs on Apache are enabled by public/.htaccess (shipped).
 * ----------------------------------------------------------------------------- */

/* ---------- Sessions ---------- */
defined('SESSION_NAME') || define('SESSION_NAME', 'ecowealth_sid');
defined('SESSION_IDLE_MAX') || define('SESSION_IDLE_MAX', 30 * 60);   // 30 minutes of inactivity

/* ---------- "Remember me" ---------- */
defined('REMEMBER_COOKIE') || define('REMEMBER_COOKIE', 'ecowealth_remember');
defined('REMEMBER_LIFETIME') || define('REMEMBER_LIFETIME', 60 * 60 * 24 * 30);   // 30 days
