<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — Shared bootstrap (required by every page)
 *
 *  Order matters: config → helpers → autoload → database → auth
 *  (plan §3.2). The front controller defines APP_BASE_URL before
 *  this file runs; standalone pages (login/register/dashboard)
 *  rely on helpers.php deriving it from the request.
 *
 *  require_once so the front controller can pre-load helpers.php to
 *  compute APP_BASE_URL without redefining its functions.
 * ============================================================== */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

/* Response hardening first, so even a failure later in this file still
 * answers with the shared headers (plan §3.7, Phase 15). */
SecurityHeaders::send();

/* Production never renders a PHP error to the browser (plan §3.7);
 * the detail still reaches error_log through the front controller's
 * Throwable handler. Development and the test harness keep them on. */
if (!APP_DEBUG) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
}

@ini_set('log_errors', '1');

date_default_timezone_set(APP_TIMEZONE);

auth_start_session();

/* Expire idle sessions (keeps the "remember me" cookie working) */
auth_expire_if_idle();

/* Try a "remember me" auto-login when there is no active session.
 * Never inside an S-Login request: that session is opened from the URL
 * token, must not inherit the superadmin's cookie identity, and an
 * invalid token must end at the console, not become a cookie login. */
if (auth_imp_request_token() === '' && empty($_SESSION['user_id']) && !empty($_COOKIE[REMEMBER_COOKIE])) {
    $remembered = remember_token_consume();
    if ($remembered !== null) {
        session_regenerate_id(true);
        $_SESSION['user_id']      = $remembered['user_id'];
        $_SESSION['logged_in_at'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['auth_via']     = 'remember-me';
        auth_cache_reset();
        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([$remembered['user_id']]);

        Live::touch('admin.dashboard');
        Live::publish('admin.dashboard');
    }
}
