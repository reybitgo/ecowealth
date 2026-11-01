<?php
declare(strict_types=1);

/* ==============================================================
 *  Sneat Auth — View, URL & form helpers
 * ============================================================== */

/* ==============================================================
 *  URLs
 * ============================================================== */

/**
 * Base URL of the application (ends with '/').
 *
 * Derived from the current request so the app works BOTH at the
 * document root (e.g. php -S, vhost) AND inside a sub-folder
 * (e.g. http://localhost/sneat-auth/public/login.php).
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    /* router.php can pin the base (used for its 404 page) */
    if (defined('APP_BASE_URL')) {
        $base = APP_BASE_URL;
        return $base;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    /* Directory request ('/', '/sneat-auth/public/') → the folder itself */
    if ($path === '' || str_ends_with($path, '/')) {
        $base = $path === '' ? '/' : $path;
        return $base;
    }

    /* Page request ('/login', '/sneat-auth/public/login.php') → its folder */
    $dir  = dirname($path);                       // '/' | '/sneat-auth/public'
    $base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '/' : rtrim($dir, '/') . '/';
    return $base;
}

/**
 * Query string that keeps requests flowing through the demo gateway,
 * but ONLY when the current request itself arrived that way.
 *
 * On a normal host (XAMPP, LAMP, production) this returns '' —
 * URLs stay clean and no configuration is required.
 */
function gateway_query(): string
{
    static $query = null;
    if ($query !== null) {
        return $query;
    }
    $port = $_GET['XTransformPort'] ?? '';
    $query = is_string($port) && $port !== ''
        ? 'XTransformPort=' . rawurlencode($port)
        : '';
    return $query;
}

/**
 * Build an application URL.
 *
 *   url('login')             → '/login'                       (docroot)
 *                             → '/sneat-auth/public/login'    (sub-folder)
 *                             → '/login?XTransformPort=8080'  (demo gateway)
 *   url('/')                 → app root
 *   url('assets/css/x.css')  → static assets, same rules
 */
function url(string $path = '/'): string
{
    $path = ltrim($path, '/');
    $link = base_url() . $path;

    $gw = gateway_query();
    if ($gw !== '') {
        $link .= (str_contains($link, '?') ? '&' : '?') . $gw;
    }

    return $link;
}

/** HTML-escape a value for safe output. */
function e(null|string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL for a static asset with a cache-busting version stamp.
 *
 *   asset_url('assets/css/fonts.css')  →  '/assets/css/fonts.css?v=1762…'
 *
 * Keeps browsers from serving a stale stylesheet after the file changes.
 */
function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $file = dirname(__DIR__) . '/public/' . $path;
    $link = url($path);

    if (is_file($file)) {
        $link .= (str_contains($link, '?') ? '&' : '?') . 'v=' . filemtime($file);
    }

    return $link;
}

/** Redirect inside the app and stop execution. */
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/** Is the current request a POST form submission? */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ==============================================================
 *  Flash messages
 * ============================================================== */

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_all(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

/** Render Bootstrap dismissible alerts for every pending flash message. */
function flash_render(): string
{
    $map = [
        'success' => ['alert-success', 'bx-check-circle'],
        'danger'  => ['alert-danger',  'bx-x-circle'],
        'warning' => ['alert-warning', 'bx-error'],
        'info'    => ['alert-info',    'bx-info-circle'],
    ];

    $html = '';
    foreach (flash_all() as $flash) {
        [$class, $icon] = $map[$flash['type']] ?? $map['info'];
        $html .= '<div class="alert ' . $class . ' d-flex align-items-start justify-content-between mb-6" role="alert">'
            . '<div class="d-flex align-items-center gap-2">'
            . '<i class="icon-base bx ' . $icon . ' fs-4 me-1"></i>'
            . '<span>' . e($flash['message']) . '</span>'
            . '</div>'
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
            . '</div>';
    }
    return $html;
}

/* ==============================================================
 *  Old form input (re-populate fields after a failed submit)
 * ============================================================== */

function old(string $key, string $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}

function old_set(array $values): void
{
    $_SESSION['_old'] = array_map(
        static fn ($v) => is_scalar($v) ? (string) $v : '',
        $values
    );
}

function old_clear(): void
{
    unset($_SESSION['_old']);
}

/* ==============================================================
 *  CSRF protection
 * ============================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(null|string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $token);
}

/* ==============================================================
 *  Formatting helpers
 * ============================================================== */

/** Format a MySQL datetime for display, e.g. "Nov 1, 2026 2:05 PM". */
function format_datetime(?string $value): string
{
    if ($value === null || $value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('M j, Y g:i A', $ts);
}

/** Uppercase initials (max 2 letters) derived from a username or full name. */
function initials(string $name): string
{
    $parts = preg_split('/[\s._-]+/', trim($name)) ?: [];
    $first = mb_strtoupper(mb_substr($parts[0] ?? 'U', 0, 1));
    $second = isset($parts[1]) ? mb_strtoupper(mb_substr($parts[1], 0, 1)) : '';
    return $first . $second;
}

/** Short human-friendly "time ago" label. */
function time_ago(?string $value): string
{
    if ($value === null || $value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff / 60) . ' min ago';
    if ($diff < 86400)   return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800)  return floor($diff / 86400) . ' day(s) ago';
    return date('M j, Y', $ts);
}
