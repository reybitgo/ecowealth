<?php

declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — Application Configuration
 *
 *  Defaults only: nothing secret is committed here. Local
 *  credentials and flags live in config.local.php (git-ignored),
 *  loaded first, and every constant falls back to a safe default.
 *
 *  Every constant is declared with `defined() || define()` so
 *  config.local.php, the front controller and the test harness
 *  (tests/bootstrap.php) can each pin a value before this file
 *  runs — plan §3.2, §8.2, §9.2.
 * ============================================================== */

/* Local overrides (DB credentials, flags) — loaded first so a local
 * APP_ENV can steer the environment defaults below. */
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

/* ---------- Environment ------------------------------------------------------
 * development → debug errors, roadmap nav items visible
 * production  → friendly error pages, technical detail only in error_log
 * test        → used by tests/bootstrap.php (picks the *_test database)
 * ------------------------------------------------------------------------- */
defined('APP_ENV') || define('APP_ENV', (string) (getenv('APP_ENV') ?: 'production'));
defined('APP_DEBUG') || define('APP_DEBUG', APP_ENV !== 'production');

/* Phase currently under construction — controls which menu items render. */
defined('APP_PHASE') || define('APP_PHASE', 7);

/* ---------- Database (MySQL) ---------- */
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', 3306);

/* The test harness runs against a throwaway schema of the same shape.
 * ECO_DB_NAME wins when set (CI / one-off runs); config.local.php should
 * NOT define DB_NAME — see docs/phase-log.md (Phase 1). */
defined('DB_NAME') || define('DB_NAME', (string) (
    getenv('ECO_DB_NAME') ?: (APP_ENV === 'test' ? 'ecowealth_sneat_test' : 'ecowealth_sneat')
));
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

/* ---------- Money ---------- */
defined('CURRENCY_SYMBOL') || define('CURRENCY_SYMBOL', '₱');
defined('CURRENCY_CODE') || define('CURRENCY_CODE', 'PHP');

/* ---------- Feature switches ----------
 * Code-level kill switches; per-admin toggles live in `settings`. */
defined('SHOP_ENABLED') || define('SHOP_ENABLED', true);
defined('COMMISSIONS_ENABLED') || define('COMMISSIONS_ENABLED', true);

/* ---------- Uploads ---------- */
defined('UPLOAD_MAX_BYTES') || define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);   // 5 MB

/* ---------- Runtime storage (cron logs + locks) -------------------------------
 * Outside public/, so nothing stored here is web-readable. The test harness
 * pins its own temp folder before this file is loaded (tests/bootstrap.php). */
defined('STORAGE_PATH') || define('STORAGE_PATH', dirname(__DIR__) . '/storage');

/* ---------- URL routing -------------------------------------------------------
 * The app auto-detects where it is installed:
 *
 *   • Document root (vhost / php -S)   →  /login, /register, /dashboard
 *   • Sub-folder (e.g. Laragon www)    →  /ecowealth/system/login …
 *
 * public/index.php defines APP_BASE_URL before bootstrapping the app;
 * helpers.php honours it. Clean URLs on Apache are enabled by the
 * .htaccess pair (system/.htaccess → public/).
 * ----------------------------------------------------------------------------- */

/* ---------- Sessions ---------- */
defined('SESSION_NAME') || define('SESSION_NAME', 'ecowealth_sid');
defined('SESSION_IDLE_MAX') || define('SESSION_IDLE_MAX', 30 * 60);   // 30 minutes of inactivity

/* ---------- "Remember me" ---------- */
defined('REMEMBER_COOKIE') || define('REMEMBER_COOKIE', 'ecowealth_remember');
defined('REMEMBER_LIFETIME') || define('REMEMBER_LIFETIME', 60 * 60 * 24 * 30);   // 30 days
