<?php
declare(strict_types=1);

/* ==============================================================
 *  Logout — POST + CSRF protected
 *
 *  Inside a super-login (impersonation) tab, Sign out ends the
 *  impersonation instead: the audit row is marked logged_out and the
 *  superadmin lands back on the console with their own session intact
 *  (plan §7 Phase 14).
 * ============================================================== */
require __DIR__ . '/../app/bootstrap.php';

if (is_post()) {
    if (csrf_verify($_POST['_csrf'] ?? null)) {
        if (auth_is_imp()) {
            try {
                (new ImpersonationService())->exit();
            } catch (Throwable $e) {
                error_log('[ecowealth] S-Login exit failed: ' . $e->getMessage());
                auth_end_imp_session('logged_out');
            }

            redirect('slogin?exited=1');
        }

        auth_logout();
        session_start();          // fresh session so the flash message survives
        flash_set('success', 'You have been signed out successfully.');
    }
} else {
    flash_set('info', 'Please use the Sign Out button to log out.');
}

redirect('login');
