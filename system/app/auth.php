<?php
declare(strict_types=1);

/* ==============================================================
 *  Sneat Auth — Authentication logic
 *  (login, registration, remember-me tokens, logout)
 * ============================================================== */

/* --------------------------------------------------------------
 *  Session management
 * -------------------------------------------------------------- */

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // The app may sit behind the HTTPS gateway — reflect that on the cookie.
    $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) ? 'https' : 'http');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,                 // browser-session cookie
        'path'     => '/',
        'domain'   => '',
        'secure'   => $scheme === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** Reset the per-request user cache (after login state changes). */
function auth_cache_reset(): void
{
    $GLOBALS['__auth_user_cache'] = false;
}

/** Currently authenticated user row (fresh from MySQL), or null. */
function auth_user(): ?array
{
    if (!array_key_exists('__auth_user_cache', $GLOBALS)) {
        $GLOBALS['__auth_user_cache'] = false; // "not fetched yet"
    }
    if ($GLOBALS['__auth_user_cache'] !== false) {
        return $GLOBALS['__auth_user_cache'];
    }

    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare(
            'SELECT id, username, email, full_name, role, is_active, last_login_at, created_at
             FROM users
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();

        if ($row && (int) $row['is_active'] === 1) {
            $user = $row;
        } else {
            // Account disappeared / deactivated mid-session -> drop the session
            unset($_SESSION['user_id'], $_SESSION['logged_in_at']);
        }
    }

    $GLOBALS['__auth_user_cache'] = $user;
    return $user;
}

function auth_check(): bool
{
    return auth_user() !== null;
}

/** Guard for protected pages. */
function require_login(): void
{
    if (!auth_check()) {
        flash_set('warning', 'Please sign in to continue.');
        redirect('login');
    }
}

/** Guard for guest-only pages (login / register). */
function require_guest(): void
{
    if (auth_check()) {
        redirect('dashboard');
    }
}

/** Expire the session after too much inactivity. */
function auth_expire_if_idle(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $last = (int) ($_SESSION['last_activity'] ?? time());
    $_SESSION['last_activity'] = time();

    if ($last > 0 && (time() - $last) > SESSION_IDLE_MAX) {
        // Destroy the session but keep the "remember me" cookie alive
        $_SESSION = [];
        session_regenerate_id(true);
    }
}

/* --------------------------------------------------------------
 *  Login (with a light brute-force throttle)
 * -------------------------------------------------------------- */

/** Remaining failed attempts before the temporary lockout. */
function login_attempts_left(): int
{
    $window = 15 * 60; // 15 minutes
    $attempts = array_values(array_filter(
        $_SESSION['_login_attempts'] ?? [],
        static fn (int $ts): bool => (time() - $ts) < $window
    ));
    $_SESSION['_login_attempts'] = $attempts;
    return max(0, 5 - count($attempts));
}

function login_record_failure(): void
{
    $_SESSION['_login_attempts'][] = time();
}

/**
 * Try to sign a user in with email OR username + password.
 * Locks the form out for 15 minutes after 5 failed attempts.
 */
function auth_login(string $identifier, string $password, bool $remember): bool
{
    if (login_attempts_left() <= 0) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT id, username, email, password_hash, role, is_active
         FROM users
         WHERE email = ? OR username = ?
         LIMIT 1'
    );
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    $valid = $user
        && (int) $user['is_active'] === 1
        && password_verify($password, $user['password_hash']);

    if (!$valid) {
        login_record_failure();
        return false;
    }

    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']      = (int) $user['id'];
    $_SESSION['logged_in_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['auth_via']     = 'password';
    unset($_SESSION['_login_attempts'], $_SESSION['_login_attempts_count']);
    auth_cache_reset();

    // Transparently upgrade the hash if PHP's default cost changed
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);
    }

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([$user['id']]);

    if ($remember) {
        remember_token_issue((int) $user['id']);
    }

    return true;
}

/* --------------------------------------------------------------
 *  Registration
 * -------------------------------------------------------------- */

/**
 * Validate and create a new account.
 *
 * @return string[] List of error messages — empty array means success.
 */
function auth_register(string $username, string $email, string $password, string $confirm): array
{
    $errors  = [];
    $username = trim($username);
    $email    = strtolower(trim($email));

    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3–50 characters and contain only letters, numbers, dot, underscore or dash.';
    }

    if ($email === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if ($errors !== []) {
        return $errors;
    }

    // Uniqueness check (username + email)
    $stmt = db()->prepare('SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$username, $email]);
    if (($existing = $stmt->fetch()) !== false) {
        $errors[] = strcasecmp((string) $existing['username'], $username) === 0
            ? 'This username is already taken.'
            : 'An account with this email already exists.';
        return $errors;
    }

    db()->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, "user")')
        ->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT)]);

    return [];
}

/* --------------------------------------------------------------
 *  "Remember me" — selector : validator token pair
 * -------------------------------------------------------------- */

function remember_token_issue(int $userId): void
{
    try {
        $selector  = bin2hex(random_bytes(12));          // 24 hex chars
        $validator = bin2hex(random_bytes(32));          // 64 hex chars

        // One active token per user
        db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
        db()->prepare(
            'INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
        )->execute([$userId, $selector, hash('sha256', $validator)]);

        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) ? 'https' : 'http');
        setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires'  => time() + REMEMBER_LIFETIME,
            'path'     => '/',
            'secure'   => $scheme === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (Throwable $e) {
        error_log('[sneat-auth] remember_token_issue: ' . $e->getMessage());
    }
}

/** Validate the remember-me cookie (if any) and log the user back in. */
function remember_token_consume(): ?array
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($cookie) || !preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $m)) {
        return null;
    }
    [, $selector, $validator] = $m;

    $stmt = db()->prepare(
        'SELECT user_id, token_hash, expires_at FROM remember_tokens WHERE selector = ? LIMIT 1'
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch();

    if ($row === false
        || !hash_equals((string) $row['token_hash'], hash('sha256', $validator))
        || strtotime((string) $row['expires_at']) < time()) {
        // Invalid or expired — drop the record so it can't be replayed
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
        return null;
    }

    // Rotate the token (single-use)
    remember_token_issue((int) $row['user_id']);

    return ['user_id' => (int) $row['user_id']];
}

function remember_token_clear(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])
        && is_string($_COOKIE[REMEMBER_COOKIE])
        && preg_match('/^([0-9a-f]{24}):/', $_COOKIE[REMEMBER_COOKIE], $m)) {
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$m[1]]);
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

/* --------------------------------------------------------------
 *  Logout
 * -------------------------------------------------------------- */

function auth_logout(): void
{
    remember_token_clear();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
