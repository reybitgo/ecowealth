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
    if (isset($GLOBALS['__base_url_cache'])) {
        return $GLOBALS['__base_url_cache'];
    }

    /* The front controller / router.php pin the base before bootstrap. */
    if (defined('APP_BASE_URL')) {
        return $GLOBALS['__base_url_cache'] = APP_BASE_URL;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    /* Directory request ('/', '/sneat-auth/public/') → the folder itself */
    if ($path === '' || str_ends_with($path, '/')) {
        return $GLOBALS['__base_url_cache'] = ($path === '' ? '/' : $path);
    }

    /* Page request ('/login', '/sneat-auth/public/login.php') → its folder */
    $dir  = dirname($path);                       // '/' | '/sneat-auth/public'
    $base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '/' : rtrim($dir, '/') . '/';

    return $GLOBALS['__base_url_cache'] = $base;
}

/** Forget the cached base URL (tests switch between request shapes). */
function base_url_reset(): void
{
    unset($GLOBALS['__base_url_cache']);
}

/**
 * Installation prefix derived from the executing script.
 *
 * public/index.php lives one level below the app root, and system/.htaccess
 * forwards /system/x → public/x, so two dirname() calls give the prefix the
 * browser sees: "/" at a document root, "/ecowealth/system/" in a sub-folder.
 */
function detect_app_base_url(string $scriptName): string
{
    $script = str_replace('\\', '/', $scriptName);
    $dir    = str_replace('\\', '/', dirname($script));
    $dir    = rtrim($dir, '/');
    $parent = $dir === '' ? '' : rtrim(str_replace('\\', '/', dirname($dir)), '/');

    if ($parent === '' || $parent === '.') {
        return '/';
    }

    return $parent . '/';
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

/* ==============================================================
 *  Super-login transport (plan §7 Phase 14)
 * ============================================================== */

/**
 * The nonce of the live impersonation session, or '' when this is a
 * normal request.
 *
 * The value is a 64-character hex string stored in the impersonation
 * session by ImpersonationService::bind(); it is the same nonce as the
 * request's `?imp=` token and the PHP session id of that tab. Reading it
 * from the session (rather than from the query) means every URL built
 * while impersonating — links, form actions, redirects — keeps the
 * token, even on a POST whose body is the only input.
 */
function imp_token(): string
{
    $token = $_SESSION['imp_token'] ?? '';

    return is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1 ? $token : '';
}

/** `imp=<nonce>` for the current page, or '' outside an S-Login session. */
function imp_query(): string
{
    $token = imp_token();

    return $token === '' ? '' : 'imp=' . $token;
}

/**
 * Build an application URL.
 *
 *   url('login')             → '/login'                       (docroot)
 *                             → '/sneat-auth/public/login'    (sub-folder)
 *                             → '/login?XTransformPort=8080'  (demo gateway)
 *   url('/')                 → app root
 *   url('assets/css/x.css')  → static assets, same rules
 *
 * While a superadmin is viewing a member through S-Login, every app URL
 * carries the tab's `?imp=<nonce>` token; otherwise the next request
 * would fall back to the cookie session. Static assets and URLs that
 * leave the app ($withImp = false: asset_url(), absolute_url()) never
 * carry it, so the bearer token cannot leak into a shared referral link
 * or a cache key.
 */
function url(string $path = '/', bool $withImp = true): string
{
    $path = ltrim($path, '/');
    $link = base_url() . $path;

    $query = [];
    $gw    = gateway_query();
    if ($gw !== '') {
        $query[] = $gw;
    }

    /* Media paths are served by the web server, not by a routed page:
     * an imp token there would be a cache/history artefact, not a session. */
    $static = str_starts_with($path, 'assets/') || str_starts_with($path, 'uploads/');

    if ($withImp && !$static && preg_match('/(?:^|[?&])imp=/', $link) !== 1) {
        $imp = imp_query();
        if ($imp !== '') {
            $query[] = $imp;
        }
    }

    if ($query !== []) {
        $link .= (str_contains($link, '?') ? '&' : '?') . implode('&', $query);
    }

    return $link;
}

/**
 * Origin (scheme://host[:port]) used by absolute_url().
 *
 * APP_URL (config.php / config.local.php / env) pins the canonical origin:
 *
 *   APP_URL = 'http://localhost'              → development
 *   APP_URL = 'https://www.ecowealth.com'     → production
 *
 * With an empty APP_URL the origin is derived from the current request,
 * which already carries the host the visitor typed (and the proxy scheme
 * when X-Forwarded-Proto is set). The install prefix stays in url(), so
 * the final link keeps its /ecowealth/system/ segment where present.
 */
function app_origin(): string
{
    $configured = defined('APP_URL') ? trim((string) APP_URL) : '';

    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    if ($host !== '' && preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) === 1) {
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || $proto === 'https';

        return ($https ? 'https' : 'http') . '://' . $host;
    }

    return 'http://localhost';
}

/**
 * Absolute URL for links that leave the app — referral links, copy buttons,
 * emails. The path follows url() (so the install prefix and gateway query
 * are kept); only the origin is added.
 *
 *   absolute_url('register?ref=ABC12345')
 *     → 'http://localhost/ecowealth/system/register?ref=ABC12345'
 */
function absolute_url(string $path = '/'): string
{
    $path = (string) $path;

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path) === 1) {
        return $path;
    }

    return app_origin() . url($path, false);
}

/** HTML-escape a value for safe output. */
function e(null|string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Normalise a Boxicons class list for markup.
 *
 * Menu config, KPI cards and empty states store the icon without the
 * base class ("bx-home-smile"), while Boxicons needs both classes on the
 * element ("bx bx-home-smile") before it paints the glyph. Accepting
 * both shapes here keeps the call sites short without ever losing the
 * icons again.
 */
function icon_class(string $icon, string $extra = ''): string
{
    $icon = trim($icon);

    if ($icon === '') {
        $icon = 'bx';
    } elseif (preg_match('/(^|\s)bx(\s|$)/', $icon) !== 1) {
        $icon = 'bx ' . $icon;
    }

    return trim($icon . ' ' . $extra);
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
    $link = url($path, false);

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
 *  Nested form payload (repeaters: level matrices, contents rows)
 * ============================================================== */

/**
 * Preserve a whole nested form payload across a redirect.
 * old()/old_set() only carry scalars; payloads that contain arrays
 * (package levels, package contents) use this instead.
 */
function old_payload_set(array $payload): void
{
    $_SESSION['_old_payload'] = $payload;
    $GLOBALS['__old_payload']  = $payload;
}

/** Read and consume the preserved payload (empty array when none). */
function old_payload(): array
{
    if (isset($GLOBALS['__old_payload'])) {
        return $GLOBALS['__old_payload'];
    }

    $payload = $_SESSION['_old_payload'] ?? [];
    unset($_SESSION['_old_payload']);

    return $GLOBALS['__old_payload'] = is_array($payload) ? $payload : [];
}

function old_payload_clear(): void
{
    unset($_SESSION['_old_payload'], $GLOBALS['__old_payload']);
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
 *  Pagination helpers
 * ============================================================== */

/**
 * Hidden `per_page` field for a filter form (plan §3.6).
 *
 * Filtering a table must not silently reset the rows-per-page the
 * member chose, so every GET filter form carries the current size.
 * Renders nothing when the request has no valid `per_page`, which
 * keeps plain URLs clean.
 */
function per_page_hidden(): string
{
    $requested = Request::query('per_page');

    if (!is_scalar($requested)) {
        return '';
    }

    $size = Paginator::perPageFromRequest();

    if ((int) $requested !== $size) {
        return '';
    }

    return '<input type="hidden" name="per_page" value="' . e((string) $size) . '">';
}

/* ==============================================================
 *  Formatting helpers
 * ============================================================== */

/**
 * URL-safe slug from a display name (used by products, packages, …).
 * Accents are transliterated, everything else collapses to dashes.
 */
function slugify(string $text, int $maxLength = 170): string
{
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (is_string($ascii) && $ascii !== '') {
        $text = $ascii;
    }

    /* Windows iconv marks accented letters with ASCII punctuation
     * ("Café" → "Caf'e", "Crème" → "Cr`eme", "über" → `"uber`), so
     * drop those markers before anything becomes a dash. */
    $text = str_replace(["'", '`', '^', '"'], '', $text);

    /* Other iconv builds leave a combining accent behind the base
     * letter; drop those and anything still non-ASCII. */
    $text = preg_replace('/[\x{0300}-\x{036F}]/u', '', $text) ?? $text;
    $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;

    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');

    return mb_substr($text, 0, $maxLength);
}

/** LIKE-pattern escaping for user-supplied search terms (MySQL default escape). */
function like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

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

/**
 * A compact device label for audit tables (full string stays in the
 * title attribute): "Chrome · Windows", "Safari · iOS", …
 *
 * The raw user agent is kept in the audit row; this is only how the
 * table shows it without a wall of text.
 */
function user_agent_label(string $userAgent): string
{
    $ua = trim($userAgent);

    if ($ua === '') {
        return 'Unknown device';
    }

    $browser = match (true) {
        str_contains($ua, 'Edg/')                    => 'Edge',
        str_contains($ua, 'OPR/')                    => 'Opera',
        str_contains($ua, 'Chrome/')                 => 'Chrome',
        str_contains($ua, 'Firefox/')                => 'Firefox',
        str_contains($ua, 'Safari/')                 => 'Safari',
        str_contains($ua, 'curl/')                   => 'curl',
        default                                      => 'Browser',
    };

    $os = match (true) {
        str_contains($ua, 'Windows NT')              => 'Windows',
        str_contains($ua, 'Android')                 => 'Android',
        str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Mac OS X')                => 'macOS',
        str_contains($ua, 'Linux')                   => 'Linux',
        default                                      => '',
    };

    return $os === '' ? $browser : $browser . ' · ' . $os;
}

/* ==============================================================
 *  Money (plan §3.6)
 * ============================================================== */

/**
 * Format an amount as pesos: money(1234.5) → "₱1,234.50".
 * With $withSign the sign is always shown: "+₱1,234.50" / "-₱10.00".
 */
function money(float|int|string|null $amount, bool $withSign = false): string
{
    $value = (float) ($amount ?? 0);
    $sign  = '';

    if ($withSign) {
        $sign = $value < 0 ? '-' : '+';
    } elseif ($value < 0) {
        $sign = '-';
    }

    return $sign . CURRENCY_SYMBOL . number_format(abs($value), 2, '.', ',');
}

/**
 * Plain decimal string for form inputs: money_amount(1234.5) → "1234.50".
 * Money is displayed with money(); this is the editable shape.
 */
function money_amount(float|int|string|null $amount): string
{
    return number_format((float) ($amount ?? 0), 2, '.', '');
}

/**
 * Status pill for tables and cards.
 *
 * $overrides lets a page restyle a status without touching the defaults:
 *   status_badge('paid', ['paid' => ['Settled', 'info']])
 *
 * @param array<string, array{0: string, 1: string}> $overrides label + Bootstrap tone
 */
function status_badge(string $status, array $overrides = []): string
{
    $map = [
        /* Orders */
        'pending'          => ['Pending', 'warning'],
        'payment_review'   => ['Payment review', 'info'],
        'payment_failed'   => ['Payment failed', 'danger'],
        'paid'             => ['Paid', 'primary'],
        'packing'          => ['Packing', 'info'],
        'ready_to_ship'    => ['Ready to ship', 'info'],
        'shipped'          => ['Shipped', 'primary'],
        'delivered'        => ['Delivered', 'success'],
        'completed'        => ['Completed', 'success'],
        'cancelled'        => ['Cancelled', 'secondary'],
        /* Activations */
        'pending_payment'  => ['Pending payment', 'warning'],
        'approved'         => ['Approved', 'success'],
        'rejected'         => ['Rejected', 'danger'],
        /* Registration codes */
        'unused'           => ['Unused', 'success'],
        'used'             => ['Used', 'secondary'],
        'expired'          => ['Expired', 'danger'],
        /* Members / products / packages */
        'active'           => ['Active', 'success'],
        'inactive'         => ['Inactive', 'secondary'],
        'suspended'        => ['Suspended', 'danger'],
        /* Wallet / commission / proof lifecycles */
        'credited'         => ['Credited', 'success'],
        'skipped'          => ['Skipped', 'secondary'],
        'voided'           => ['Voided', 'secondary'],
        'failed'           => ['Failed', 'danger'],
        'verified'         => ['Verified', 'success'],
        'logged_out'       => ['Logged out', 'secondary'],
    ];

    $map = $overrides + $map;

    [$label, $tone] = $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'secondary'];

    return '<span class="badge bg-label-' . e($tone) . '">' . e($label) . '</span>';
}

/* ==============================================================
 *  E-wallet presentation (plan §4.4, §5.8; Phase 5)
 * ============================================================== */

/** Human label for an `ewallet_ledger.ref_type` value. */
function wallet_ref_label(string $refType): string
{
    $map = [
        'commission'   => 'Commission',
        'payout'       => 'Payout',
        'transfer'     => 'Transfer',
        'topup'        => 'Admin top-up',
        'registration' => 'Registration',
        'shop_order'   => 'Shop order',
        'adjustment'   => 'Adjustment',
        'refund'       => 'Refund',
    ];

    return $map[$refType] ?? ucfirst(str_replace('_', ' ', $refType));
}

/** Boxicons class for an `ewallet_ledger.ref_type` value. */
function wallet_ref_icon(string $refType): string
{
    $map = [
        'commission'   => 'bx-line-chart',
        'payout'       => 'bx-money-withdraw',
        'transfer'     => 'bx-transfer',
        'topup'        => 'bx-plus-circle',
        'registration' => 'bx-id-card',
        'shop_order'   => 'bx-cart',
        'adjustment'   => 'bx-slider-alt',
        'refund'       => 'bx-undo',
    ];

    return 'bx ' . ($map[$refType] ?? 'bx-wallet');
}

/* ==============================================================
 *  Commissions (plan §6; Phase 10)
 * ============================================================== */

/** Human label for a `commissions.type` value. */
function commission_type_label(string $type): string
{
    return Commission::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/**
 * Level label used on both statements.
 *
 *   commission_level_label(1)  → "Level 1 · Direct"
 *   commission_level_label(3)  → "Level 3"
 */
function commission_level_label(int $level, bool $withDirect = true): string
{
    if ($level === Commission::LEVEL_DIRECT) {
        return $withDirect ? 'Level 1 · Direct' : 'Level 1';
    }

    return 'Level ' . $level;
}

/** Boxicons class for a `commissions.type` value. */
function commission_type_icon(string $type): string
{
    return 'bx ' . ($type === 'indirect_referral' ? 'bx-git-branch' : 'bx-user-plus');
}

/* ==============================================================
 *  Orders & payments (plan §5.3–5.6; Phase 6)
 * ============================================================== */

/** Human label for `shop_orders.payment_method`. */
function payment_method_label(string $method): string
{
    return [
        'ewallet' => 'E-wallet',
        'gcash'   => 'GCash',
        'maya'    => 'Maya',
        'bank'    => 'Bank transfer',
    ][$method] ?? ucfirst(str_replace('_', ' ', $method));
}

/** Boxicons class for a payment method. */
function payment_method_icon(string $method): string
{
    return 'bx ' . [
        'ewallet' => 'bx-wallet',
        'gcash'   => 'bx-mobile-alt',
        'maya'    => 'bx-mobile',
        'bank'    => 'bx-credit-card',
    ][$method] ?? 'bx-credit-card';
}

/**
 * Is the payment method settled when the order is placed (no proof)?
 * Only the e-wallet is immediate in this build.
 */
function payment_method_is_instant(string $method): bool
{
    return $method === 'ewallet';
}

/* ==============================================================
 *  Settings (plan §4.2) — key/value rows in `settings`
 * ============================================================== */

/**
 * Read a setting. The whole table is loaded once per request.
 * Missing tables/rows fall back to the caller's default (and are
 * logged) so the app stays usable during a migration.
 */
function setting(string $key, mixed $default = null): mixed
{
    if (!isset($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'] = [];

        try {
            foreach (db()->query('SELECT key_name, value FROM settings') as $row) {
                $GLOBALS['__settings_cache'][(string) $row['key_name']] = (string) $row['value'];
            }
        } catch (PDOException $e) {
            error_log('[ecowealth] settings unavailable: ' . $e->getMessage());
            $GLOBALS['__settings_cache'] = [];
        }
    }

    return array_key_exists($key, $GLOBALS['__settings_cache'])
        ? $GLOBALS['__settings_cache'][$key]
        : $default;
}

function setting_bool(string $key, bool $default = false): bool
{
    $value = setting($key, null);
    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function setting_int(string $key, int $default = 0): int
{
    $value = setting($key, null);

    return $value === null ? $default : (int) $value;
}

function setting_float(string $key, float $default = 0.0): float
{
    $value = setting($key, null);

    return $value === null ? $default : (float) $value;
}

/** All settings as key => value (for the admin settings page, Phase 15). */
function setting_all(): array
{
    setting('__warm_cache__');

    return $GLOBALS['__settings_cache'];
}

/** Drop the per-request cache (call after writing a setting). */
function setting_cache_clear(): void
{
    unset($GLOBALS['__settings_cache']);
}

/** Persist one setting. Callers are responsible for authorisation + audit. */
function setting_set(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = ?'
    )->execute([$key, $value, $value]);

    $GLOBALS['__settings_cache'][$key] = $value;
}

/* ==============================================================
 *  Form errors (paired with old()/old_set()/old_clear())
 * ============================================================== */

/** @param array<string, string> $errors field => message */
function errors_set(array $errors): void
{
    $_SESSION['_errors'] = $errors;
    unset($GLOBALS['__form_errors']);
}

/** @return array<string, string> */
function errors_all(): array
{
    if (isset($GLOBALS['__form_errors'])) {
        return $GLOBALS['__form_errors'];
    }

    $errors = $_SESSION['_errors'] ?? [];
    unset($_SESSION['_errors']);

    return $GLOBALS['__form_errors'] = is_array($errors) ? $errors : [];
}

function error_for(string $field): string
{
    return (string) (errors_all()[$field] ?? '');
}

function has_error(string $field): bool
{
    return error_for($field) !== '';
}

/** Bootstrap's is-invalid class for an input. */
function invalid_class(string $field, string $extra = ''): string
{
    return has_error($field) ? trim('is-invalid ' . $extra) : $extra;
}

/* ==============================================================
 *  JSON + redirect helpers
 * ============================================================== */

/** Emit a JSON response and end the request (plan §3.6 AJAX contract). */
function json_response(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Redirect to the same-origin referer (app-relative) or a fallback.
 * Used after a failed POST so the user lands back on the form.
 */
function redirect_back(string $fallback = '/'): never
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host    = (string) ($_SERVER['HTTP_HOST'] ?? '');

    if ($referer !== '' && $host !== '') {
        $refHost = parse_url($referer, PHP_URL_HOST);
        $ourHost = parse_url('http://' . $host, PHP_URL_HOST);

        if (is_string($refHost) && is_string($ourHost) && strcasecmp($refHost, $ourHost) === 0) {
            $path  = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
            $query = parse_url($referer, PHP_URL_QUERY);

            $base = base_url();
            if ($base !== '/' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base) - 1);
            }

            $target = ltrim($path, '/') . ($query ? '?' . $query : '');

            redirect($target === '' ? $fallback : $target);
        }
    }

    redirect($fallback);
}

/* ==============================================================
 *  Navigation (plan §3.5) — config lives in app/layout/nav.php
 * ============================================================== */

/** @return array<string, array{title: string, sections: array}> */
function nav_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/layout/nav.php';
    }

    return $config;
}

/** May the current viewer see this nav item? */
function nav_can_see(array $item, ?array $user = null): bool
{
    return match ($item['guard'] ?? 'member') {
        'any'        => true,
        'member'     => $user !== null,
        'admin'      => is_admin($user),
        'superadmin' => is_superadmin($user),
        default      => false,
    };
}

/** Has the phase that builds this item already shipped? */
function nav_item_live(array $item): bool
{
    return (int) ($item['phase'] ?? 1) <= (int) APP_PHASE;
}

/**
 * Which nav item is active, derived from the current request path.
 * The longest matching path wins, so /admin/products beats /admin.
 */
function nav_current_key(string $layout, ?array $user = null): string
{
    $path = Request::path();
    $best = '';
    $key  = '';

    foreach (nav_config()[$layout]['sections'] ?? [] as $section) {
        foreach ($section['items'] ?? [] as $item) {
            if (!nav_can_see($item, $user)) {
                continue;
            }

            $itemPath = '/' . trim((string) ($item['path'] ?? ''), '/');
            if ($itemPath === '/') {
                continue;
            }

            if ($path === $itemPath || str_starts_with($path, $itemPath . '/')) {
                if (strlen($itemPath) > strlen($best)) {
                    $best = $itemPath;
                    $key  = (string) ($item['key'] ?? '');
                }
            }
        }
    }

    return $key;
}
