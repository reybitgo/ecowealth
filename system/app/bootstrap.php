<?php
declare(strict_types=1);

/* ==============================================================
 *  Sneat Auth — Shared bootstrap (required by every page)
 * ============================================================== */

require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/database.php';
require __DIR__ . '/auth.php';

date_default_timezone_set(APP_TIMEZONE);

auth_start_session();

/* Expire idle sessions (keeps the "remember me" cookie working) */
auth_expire_if_idle();

/* Try a "remember me" auto-login when there is no active session */
if (empty($_SESSION['user_id']) && !empty($_COOKIE[REMEMBER_COOKIE])) {
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
    }
}
