<?php
declare(strict_types=1);

/* ==============================================================
 *  Request — thin, testable wrapper around the PHP request globals
 *  (plan §3.3). Keeps base-path maths in one place so every
 *  route, redirect and asset URL agrees.
 * ============================================================== */

final class Request
{
    /** HTTP method, with HEAD folded into GET. */
    public static function method(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        return $method === 'HEAD' ? 'GET' : $method;
    }

    /**
     * Application path for the current request, e.g. '/admin/products/9/edit'.
     * Always starts with '/', never ends with one (except the root).
     *
     * @param string|null $base Installation prefix; defaults to base_url()
     */
    public static function path(?string $base = null): string
    {
        return self::pathFrom((string) ($_SERVER['REQUEST_URI'] ?? '/'), $base ?? base_url());
    }

    /** Pure path resolution — no globals, so it stays unit-testable. */
    public static function pathFrom(string $uri, string $base): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $path = rawurldecode($path);

        if ($base !== '/' && str_starts_with($path, $base)) {
            /* Drop the installation prefix but keep the leading slash. */
            $path = substr($path, strlen($base) - 1);
        } elseif ($base !== '/' && rtrim($path, '/') === rtrim($base, '/')) {
            $path = '/';
        }

        $path = '/' . trim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /** Path of the current request relative to a route (used by views). */
    public static function isAjax(): bool
    {
        return strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
    }

    /** Expects JSON (AJAX or an explicit Accept header)? */
    public static function wantsJson(): bool
    {
        if (self::isAjax()) {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        return str_contains($accept, 'application/json');
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return $ip === '' ? '0.0.0.0' : substr($ip, 0, 45);
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /** One request value: POST wins over GET. */
    public static function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }

        return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Trimmed string input ('' when absent). */
    public static function string(string $key, string $default = ''): string
    {
        $value = self::input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @return array<string, mixed> POST + GET merged (POST wins). */
    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }
}
