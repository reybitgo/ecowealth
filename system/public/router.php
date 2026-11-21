<?php
declare(strict_types=1);

/**
 * Router for the PHP built-in development server:
 *     php -S 127.0.0.1:8080 -t public public/router.php
 *
 * Parity with public/.htaccess (plan §3.3):
 *   - existing static files are streamed as-is,
 *   - the four standalone pages keep their own scripts,
 *   - every other path goes through the front controller.
 */

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$decoded = rawurldecode($rawPath);

/* 1) Real, non-PHP files (assets, uploads) -> let the built-in server stream them */
if ($decoded !== '/'
    && is_file(__DIR__ . $decoded)
    && strtolower((string) pathinfo($decoded, PATHINFO_EXTENSION)) !== 'php') {
    return false;
}

/* 2) Tolerate trailing slashes: '/dashboard/' → '/dashboard' */
$path = $rawPath === '/' ? '/' : rtrim($rawPath, '/');
if ($path === '') {
    $path = '/';
}

/* The built-in server always serves the app from the document root. */
define('APP_BASE_URL', '/');

/* 3) Standalone pages kept outside the router in this build */
$standalone = [
    '/'          => 'index.php',
    '/login'     => 'login.php',
    '/register'  => 'register.php',
    '/logout'    => 'logout.php',
];

if (isset($standalone[$path])) {
    require __DIR__ . '/' . $standalone[$path];
    return true;
}

/* 4) Direct /something.php access (trailing slash tolerated too) */
if (preg_match('#^/([A-Za-z0-9_-]+)\.php$#', $path, $m) && is_file(__DIR__ . '/' . $m[1] . '.php')) {
    require __DIR__ . '/' . $m[1] . '.php';
    return true;
}

/* 5) Everything else -> the front controller */
require __DIR__ . '/index.php';
return true;
