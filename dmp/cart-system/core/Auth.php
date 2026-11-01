<?php

/**
 * @file   core/Auth.php
 * @brief  Authentication management class
 */
class Auth
{
    /**
     * Roles that may access the admin area / own a held order.
     *
     * Single source of truth for the staff role set: Auth::isAdmin() and the
     * hold-owner picker (User::assignableStaff()) both derive from it, so a
     * future staff role only has to be added here.
     */
    public const ADMIN_ROLES = ['admin', 'superadmin'];

    // ── Session Bootstrap ─────────────────────────────────────────────────────

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_strict_mode', 1);
            if (APP_ENV === 'production') {
                ini_set('session.cookie_secure', 1);
            }
            session_name('mlm_sess');
            session_start();
        }
    }

    // ── Super-Login (Impersonation) Sessions ───────────────────────────────────
    //
    // S-Login sessions are separate from the normal cookie session (mlm_sess).
    // Each impersonated member tab carries its own URL-token (`?imp=<nonce>`)
    // which is used as the PHP session id directly. No session cookie is issued,
    // so the superadmin's own cookie session is never disturbed.

    public const IMP_SESSION_TTL = 28800; // 8 hours

    /**
     * Configure PHP to open the session identified by $nonce (URL token).
     * Must be called BEFORE session_start().
     */
    public static function startImpSession(string $nonce): void
    {
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.use_strict_mode', '0');
        ini_set('session.gc_maxlifetime', (string) self::IMP_SESSION_TTL);
        session_name('mlm_imp');
        session_id($nonce);
    }

    public static function isImpSession(): bool
    {
        return self::check() && !empty($_SESSION['imp_session']);
    }

    /**
     * Validate every URL-token request. Invalid/impaired impersonation
     * sessions are destroyed and the tab is bounced back to ?page=slogin.
     * Called from index.php right after session_start().
     */
    public static function validateImpSession(): void
    {
        if (!self::isImpSession()) {
            self::endImpSession('expired', null);
        }

        if (empty($_SESSION['imp_expires']) || (int) $_SESSION['imp_expires'] < time()) {
            self::endImpSession('expired', null);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        if ((string) ($_SESSION['imp_ip'] ?? '') !== $ip || (string) ($_SESSION['imp_ua'] ?? '') !== $ua) {
            self::endImpSession('expired', null);
        }

        $u = self::user();
        if (!$u || ($u['role'] ?? '') !== 'member' || in_array($u['status'] ?? '', ['suspended', 'deactivated'], true)) {
            self::endImpSession('expired', null);
        }
    }

    private static function endImpSession(string $status, ?string $msg): void
    {
        if (!empty($_SESSION['imp_log_id'])) {
            ImpLog::mark((int) $_SESSION['imp_log_id'], $status);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
        redirect('/?page=slogin');
    }

    // ── Login / Logout ────────────────────────────────────────────────────────

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['username']  = $user['username'];

        // Update last_login
        db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
            ->execute([$user['id']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
        redirect('/');
    }

    // ── Status Checks ─────────────────────────────────────────────────────────

    public static function check(): bool
    {
        return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    }

    public static function isAdmin(): bool
    {
        return self::check() && in_array($_SESSION['user_role'], self::ADMIN_ROLES, true);
    }

    public static function isSuperadmin(): bool
    {
        return self::check() && $_SESSION['user_role'] === 'superadmin';
    }

    public static function isMember(): bool
    {
        return self::check() && $_SESSION['user_role'] === 'member';
    }

    public static function id(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    /**
     * Effective admin account id for admin-attributed operations.
     *
     * Superadmin actions are attributed to the primary admin account
     * (first user with role = 'admin'), so the superadmin's own wallet is
     * never debited and every transaction record points to the admin.
     */
    public static function actingAdminId(): int
    {
        if (self::isSuperadmin()) {
            $id = (int) db()->query(
                "SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
            )->fetchColumn();
            return $id > 0 ? $id : self::id();
        }
        return self::id();
    }

    /**
     * Effective user row for wallet/fund display.
     *
     * Superadmins act as an admin proxy: wallet displays, payment sources and
     * transaction attribution resolve to the primary admin account. Everyone
     * else gets their own row.
     */
    public static function actingUser(): array
    {
        if (self::isSuperadmin()) {
            static $acting = null;
            if ($acting === null) {
                $id = self::actingAdminId();
                $acting = $id === self::id() ? self::user() : (User::find($id) ?: self::user());
            }
            return $acting;
        }
        return self::user();
    }

    // ── Current User ──────────────────────────────────────────────────────────

    /**
     * Returns the full user row, cached for the request.
     */
    public static function user(): array
    {
        if (!self::check()) return [];

        static $user = null;
        if ($user === null) {
            $st = db()->prepare(
                'SELECT u.*, p.name AS package_name, p.pairing_bonus, p.daily_pair_cap,
                        p.direct_ref_bonus,
                        sp.username AS sponsor_username,
                        bp.username AS binary_parent_username
                 FROM   users u
                 LEFT JOIN packages p  ON p.id  = u.package_id
                 LEFT JOIN users sp    ON sp.id = u.sponsor_id
                 LEFT JOIN users bp    ON bp.id = u.binary_parent_id
                 WHERE  u.id = ?'
            );
            $st->execute([self::id()]);
            $user = $st->fetch() ?: [];
        }
        return $user;
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    /**
     * Ensure current request is authenticated.
     * $role: 'member' | 'admin' | 'any' | 'guest'
     */
    public static function guard(string $role = 'member'): void
    {
        if ($role === 'guest') {
            if (self::check()) {
                redirect(self::isAdmin() ? '/?page=admin' : '/?page=dashboard');
            }
            return;
        }

        if (!self::check()) {
            flash('error', 'Please log in to continue.');
            redirect('/?page=login');
        }

        if ($role === 'admin' && !self::isAdmin()) {
            flash('error', 'Access denied.');
            redirect('/?page=dashboard');
        }

        // Check account status
        $u = self::user();
        if (($u['status'] ?? '') === 'suspended') {
            self::logout(); // clears session
        }
    }
}
