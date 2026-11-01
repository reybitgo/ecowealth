<?php
declare(strict_types=1);

/* ==============================================================
 *  Logout — POST + CSRF protected
 * ============================================================== */
require __DIR__ . '/../app/bootstrap.php';

if (is_post()) {
    if (csrf_verify($_POST['_csrf'] ?? null)) {
        auth_logout();
        session_start();          // fresh session so the flash message survives
        flash_set('success', 'You have been signed out successfully.');
    }
} else {
    flash_set('info', 'Please use the Sign Out button to log out.');
}

redirect('login');
