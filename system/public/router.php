<?php
/**
 * Router for the PHP built-in development server:
 *     php -S 127.0.0.1:8080 -t <public> router.php
 *
 * - Existing static files (assets) are served as-is.
 * - Clean URLs are mapped to their page scripts.
 * - Trailing slashes are tolerated ('/dashboard/' === '/dashboard').
 */
declare(strict_types=1);

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$decoded = rawurldecode($rawPath);   // '%40form-validation' → '@form-validation'

/* 1) Real, non-PHP files -> let the built-in server stream them */
if ($decoded !== '/' && is_file(__DIR__ . $decoded) && pathinfo($decoded, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

/* 2) Tolerate trailing slashes: '/dashboard/' → '/dashboard' */
$path = $rawPath === '/' ? '/' : rtrim($rawPath, '/');
if ($path === '') {
    $path = '/';
}

/*
 * The built-in server always serves this app from the document root,
 * so pin the base URL for every page script — keeps url() stable even
 * when the request arrived with a trailing slash.
 */
define('APP_BASE_URL', '/');

/* 3) Clean URLs */
$routes = [
    '/'           => 'index.php',
    '/login'      => 'login.php',
    '/register'   => 'register.php',
    '/dashboard'  => 'dashboard.php',
    '/logout'     => 'logout.php',
];

if (isset($routes[$path])) {
    require __DIR__ . '/' . $routes[$path];
    return true;
}

/* 4) Direct /something.php access (trailing slash tolerated too) */
if (preg_match('#^/([A-Za-z0-9_-]+)\.php$#', $path, $m) && is_file(__DIR__ . '/' . $m[1] . '.php')) {
    require __DIR__ . '/' . $m[1] . '.php';
    return true;
}

/* 5) Everything else -> 404 */
http_response_code(404);
require __DIR__ . '/404.php';
return true;
