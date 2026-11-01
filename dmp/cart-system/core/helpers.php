<?php

/**
 * @file   core/helpers.php
 * @brief  Helper functions for the application
 */

// ── Output / Escaping ────────────────────────────────────────────────────────

// ── User Helper Functions ────────────────────────────────────────────────────

/**
 * Mask a payout account for display (e.g. 0917****3210, or T***...***xYZ).
 */
function mask_account(string $account): string
{
    $len = strlen($account);
    if ($len <= 6) return $account;
    // Show first 4 and last 4
    return substr($account, 0, 4) . str_repeat('*', max(2, $len - 8)) . substr($account, -4);
}

/**
 * Get a user by ID.
 * Returns the full user row as associative array, or null if not found.
 */
function getUserById(int $id): ?array
{
    $pdo = db();  // Uses your existing db() helper

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

/**
 * HTML-escape a value for safe output.
 */
function e(mixed $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Encode several values as a JavaScript argument list, safe inside an
 * attribute. Use when a handler takes more than one argument:
 *     onclick="payoutAction(<?= jsargs(['approve', $id, $user, $amount]) ?>)"
 *
 * Composes js() per element, so the escaping rules stay in one place.
 */
function jsargs(array $args): string
{
    return implode(',', array_map('js', $args));
}

/**
 * Escape a value for embedding INSIDE an already-quoted JavaScript string
 * that sits in an HTML attribute, while KEEPING markup live.
 *
 * Use js() when the value is a whole argument. Use jsstr() only when the
 * surrounding literal is already in the template and the value is part of a
 * larger string that intentionally carries HTML — e.g. showConfirm() messages,
 * which reach the DOM through insertAdjacentHTML.
 *
 * Order matters: JS-escape first (a backslash escape cannot itself be
 * re-escaped), then HTML-escape the result for the attribute.
 */
function jsstr(mixed $val): string
{
    $s = (string) $val;
    $s = str_replace(
        ['\\', "'", "\n", "\r", '<', '>', '&'],
        ['\\\\', "\\'", '\\n', '\\r', '\\u003C', '\\u003E', '\\u0026'],
        $s
    );
return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Encode a value as a complete JavaScript literal, safe inside an HTML
 * attribute.
 *
 * WHY NOT e(): e() is an HTML escaper. The browser HTML-decodes an attribute
 * value BEFORE handing it to the JS parser, so e()'s &#039; becomes ' again
 * and the string literal breaks out. A value like
 *     ');alert(document.cookie);//
 * stored in an admin-controlled column therefore executed in the admin's
 * browser (defect C5, tmp/shop/remedy.md).
 *
 * WHY NOT BARE json_encode(): its output is wrapped in raw " characters, which
 * would terminate a double-quoted attribute before the JS parser ever sees it.
 *
 * Two layers, both needed:
 *   1. JSON_HEX_* turns every quote, apostrophe, angle bracket and ampersand
 *      inside the VALUE into \uXXXX, so nothing in the payload can end the
 *      literal or open a tag.
 *   2. htmlspecialchars escapes the enclosing quotes json_encode adds, so the
 *      whole expression is also safe to sit inside an HTML attribute. The
 *      browser decodes &quot; back to " for the JS parser, which is exactly
 *      what we want.
 *
 * Usage:  onclick="copyText(<?= js($value) ?>)"      (no manual quotes)
 *         <a onclick="showConfirm(<?= js($title) ?>)">
 */
function js(mixed $val): string
{
    $json = json_encode(
        $val,
        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return htmlspecialchars((string) $json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format a number as Philippine Peso.
 */
function fmt_money(float $n, bool $showSign = false): string
{
    $formatted = '₱' . number_format(abs($n), 2);
    if ($showSign && $n < 0) return '-' . $formatted;
    if ($showSign && $n > 0) return '+' . $formatted;
    return $formatted;
}

/**
 * Short number format (1.2K, 3.4M).
 */
function fmt_short(float $n): string
{
    if ($n >= 1_000_000) return number_format($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000)     return number_format($n / 1_000, 1) . 'K';
    return (string)(int)$n;
}

/**
 * Format a timestamp as human-readable date.
 */
function fmt_date(?string $ts, string $format = 'M d, Y'): string
{
    if (!$ts) return '—';
    return date($format, strtotime($ts));
}

function fmt_datetime(?string $ts): string
{
    return fmt_date($ts, 'M d, Y h:i A');
}

// ── Seat Limit Helpers ──────────────────────────────────────────────────────

function seatsRemaining(): int
{
    // Read directly from DB to avoid stale cache
    $row = db()->prepare("SELECT value FROM settings WHERE key_name = 'seat_limit'");
    $row->execute();
    $limit = (int) ($row->fetchColumn() ?: '1000');
    $count = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
    return max(0, $limit - $count);
}

function isSeatLimitReached(): bool
{
    return seatsRemaining() <= 0;
}

// ── Navigation ────────────────────────────────────────────────────────────────

function redirect(string $path): never
{
    // Ensure session data is written before redirecting
    // Prevents flash messages from being lost on same-page redirects
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Impersonated sessions ride on a URL token — preserve it across
    // POST-handler redirects so the member stays inside their session.
    // (logon/slogout boundaries and logout keep the plain URL.)
    if (
        is_imp_session()
        && str_starts_with($path, '/')
        && !str_contains($path, 'imp=')
        && !preg_match('#/?page=(slogin|logout)(&|$)#', $path)
    ) {
        $path .= (str_contains($path, '?') ? '&' : '?') . 'imp=' . session_id();
    }

    // $path should start with / (relative to APP_URL) or be a full URL
    if (str_starts_with($path, 'http')) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . APP_URL . $path);
    }
    exit;
}

function current_page(): string
{
    return $_GET['page'] ?? 'login';
}

function is_page(string $page): bool
{
    return current_page() === $page;
}

// ── Super-Login (Impersonation) URL Building ──────────────────────────────────

/**
 * True when the current request runs inside an impersonated member session
 * (a superadmin S-Login tab). Normal members and superadmin's own tabs
 * never match.
 */
function is_imp_session(): bool
{
    return !empty($_SESSION['imp_session']);
}

/**
 * Build an app URL. $page may be a plain page token (e.g. 'dashboard') or a
 * query-style string (e.g. 'genealogy&view=binary'); $params are additional
 * overrides. When running impersonated, the URL token is appended so every
 * navigation/AJAX call stays inside the member session.
 */
function link_to(string $page, array $params = []): string
{
    $query = [];

    $segments = str_contains($page, '&') ? explode('&', $page) : [$page];
    foreach ($segments as $i => $seg) {
        if ($i === 0) {
            $query['page'] = $seg;
            continue;
        }
        $kv = explode('=', $seg, 2);
        $query[$kv[0]] = $kv[1] ?? '';
    }

    foreach ($params as $k => $v) {
        $query[$k] = $v;
    }

    if (is_imp_session()) {
        $query['imp'] = session_id();
    }

    return APP_URL . '/?' . http_build_query($query);
}

// ── Flash Messages ────────────────────────────────────────────────────────────

/**
 * Set or get a flash message.
 * flash('success', 'Saved!') → sets
 * flash('success')           → gets and clears
 */
function flash(string $key, string $msg = ''): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($msg !== '') {
        $_SESSION['flash'][$key] = $msg;
        return '';
    }
    $val = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $val;
}

/**
 * Render all flash messages as HTML.
 */
function render_flash(): string
{
    // Map flash key → Bootstrap 5 alert class + icon
    $types = [
        'success' => ['alert-success', '✓'],
        'error'   => ['alert-danger',  ''],
        'info'    => ['alert-info',    'ℹ'],
        'warning' => ['alert-warning', '⚠'],
    ];
    $html = '';
    foreach ($types as $key => [$bsClass, $icon]) {
        $msg = flash($key);
        if ($msg) {
            $iconHtml = $icon !== '' ? sprintf('<span>%s</span>', $icon) : '';
            $html .= sprintf(
                '<div class="alert flash-alert %s d-flex align-items-center gap-2 mb-3" role="alert">' .
                    '%s<span>%s</span>' .
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>',
                $bsClass,
                $iconHtml,
                e($msg)
            );
        }
    }
    return $html;
}

// ── CSRF Protection ───────────────────────────────────────────────────────────

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Invalid security token. Please go back and try again.');
    }
}

// ── Registration Code Generator ───────────────────────────────────────────────

/**
 * Generate a registration code like ABCD-EFGH-JKLM.
 * Uses an unambiguous charset (no 0/O/1/I confusion).
 */
function generate_code(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len   = strlen($chars);
    $parts = [];
    for ($i = 0; $i < 3; $i++) {
        $part = '';
        for ($j = 0; $j < 4; $j++) {
            $part .= $chars[random_int(0, $len - 1)];
        }
        $parts[] = $part;
    }
    return implode('-', $parts);
}

// ── Input Sanitization ────────────────────────────────────────────────────────

function sanitize_username(string $u): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $u));
}

function is_valid_username(string $u): bool
{
    return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,39}$/', $u);
}

function is_valid_mobile(string $m): bool
{
    return (bool) preg_match('/^(09|\+639)\d{9}$/', $m);
}

// ── JSON Response ─────────────────────────────────────────────────────────────

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Rate Limiting (session-based) ─────────────────────────────────────────────

function rate_limit_check(string $key, int $maxAttempts = 5, int $windowSeconds = 900): bool
{
    $attempts = $_SESSION['rl'][$key]['attempts'] ?? 0;
    $since    = $_SESSION['rl'][$key]['since']    ?? 0;

    // Reset window if expired
    if ((time() - $since) > $windowSeconds) {
        $_SESSION['rl'][$key] = ['attempts' => 0, 'since' => time()];
        return true; // allowed
    }

    return $attempts < $maxAttempts;
}

function rate_limit_hit(string $key): void
{
    if (!isset($_SESSION['rl'][$key])) {
        $_SESSION['rl'][$key] = ['attempts' => 0, 'since' => time()];
    }
    $_SESSION['rl'][$key]['attempts']++;
}

function rate_limit_clear(string $key): void
{
    unset($_SESSION['rl'][$key]);
}

// ── System Setting ────────────────────────────────────────────────────────────

function setting(string $key, string $default = ''): string
{
    $cache = &setting_cache();
    if (!array_key_exists($key, $cache)) {
        $st = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        // fetchColumn() returns false on no row; ?? only checks null, so convert false to null
        $cache[$key] = ($value === false ? null : $value) ?? $default;
    }
    return $cache[$key];
}

/**
 * Shared cache store for setting(). Exposed by reference so code that writes
 * the settings table in-process (tests, one-off CLI scripts) can invalidate a
 * stale entry with setting_forget() instead of reading the old value back.
 */
function &setting_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Drop one cached setting, or the entire cache when $key is null. */
function setting_forget(?string $key = null): void
{
    $cache = &setting_cache();
    if ($key === null) {
        $cache = [];
        return;
    }
    unset($cache[$key]);
}

/**
 * Should the registration UI offer binary placement controls?
 *
 * Placement is only meaningful when at least one package is live and the
 * Binary Pairing compensation is switched on (master + per-type toggle).
 * Packages disabled or Binary Pairing off ⇒ no upline/position fields.
 *
 * @param array $packages Active packages (Package::all(true)).
 */
function binary_placement_ui_enabled(array $packages): bool
{
    return !empty($packages)
        && setting('packages_disabled', '0') !== '1'
        && setting('commissions_enabled', '1') === '1'
        && setting('commission_binary_enabled', '1') === '1';
}

// ── Pagination ────────────────────────────────────────────────────────────────

function paginate(string $query, array $params, int $page, int $perPage = 20): array
{
    $pdo = db();

    // Count
    $countSql = preg_replace('/SELECT .+? FROM /is', 'SELECT COUNT(*) FROM ', $query, 1);
    $countSql = preg_replace('/ORDER BY .+$/is', '', $countSql);
    $st = $pdo->prepare($countSql);
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;

    $st = $pdo->prepare($query . " LIMIT $perPage OFFSET $offset");
    $st->execute($params);

    return [
        'data'        => $st->fetchAll(),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
    ];
}

/**
 * Render Bootstrap 5 pagination links as HTML.
 */
function pagination_links(array $p, string $baseUrl): string
{
    $totalPages = (int) ($p['total_pages'] ?? 1);
    $page       = (int) ($p['page'] ?? 1);
    if ($totalPages <= 1) return '';

    $pages = _pagination_page_range($page, $totalPages);

    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center mb-0">';

    // Previous
    if ($page > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&pg=' . ($page - 1) . '" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link" aria-label="Previous"><span aria-hidden="true">&laquo;</span></span></li>';
    }

    // Page numbers with ellipsis for gaps
    $lastShown = 0;
    foreach ($pages as $i) {
        if ($i - $lastShown > 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        if ($i === $page) {
            $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&pg=' . $i . '">' . $i . '</a></li>';
        }
        $lastShown = $i;
    }

    // Next
    if ($page < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&pg=' . ($page + 1) . '" aria-label="Next"><span aria-hidden="true">&raquo;</span></a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link" aria-label="Next"><span aria-hidden="true">&raquo;</span></span></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

/**
 * Build a compact page-number array for Bootstrap pagination.
 * Always includes first/last pages and pages around the current page.
 */
function _pagination_page_range(int $current, int $total): array
{
    if ($total <= 7) {
        return range(1, $total);
    }

    $pages = [1, 2];

    $start = max(3, $current - 1);
    $end   = min($total - 2, $current + 1);

    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }

    $pages[] = $total - 1;
    $pages[] = $total;

    // Remove duplicates while preserving order
    $seen = [];
    $out  = [];
    foreach ($pages as $i) {
        if (!isset($seen[$i])) {
            $out[] = $i;
            $seen[$i] = true;
        }
    }
    return $out;
}

/** rows per page */
/**
 * Normalise a buyer- or admin-declared payment reference (defect, Phase 7).
 *
 * Defence in depth behind the C5/C5b escaping fixes. A GCash reference is
 * alphanumeric, a USDT transaction hash is hex, so the safe character set is
 * narrow and known. Truncating to the column width here means an oversized or
 * hostile value cannot reach the database as a surprise - the previous code had
 * only a client-side maxlength, which is not a constraint.
 *
 * @return string empty when the input carries no usable characters
 */
function clean_reference(mixed $raw, int $max = 40): string
{
    $s = strtoupper(trim((string) $raw));
    $s = (string) preg_replace('/[^A-Z0-9_-]/', '', $s);
    return mb_substr($s, 0, max(1, $max));
}

/**
 * Remember what the buyer typed at checkout so a failed attempt does not throw
 * it away (Phase 8.7).
 *
 * placeOrder() validates server-side and redirects back to the checkout page on
 * failure. Every field the member had filled in — the delivery address, the
 * contact numbers — was simply gone, so fixing one validation error meant
 * retyping the lot. Stashed for exactly one render, then consumed.
 *
 * Only the whitelisted address fields are kept. Nothing is read from the array
 * that is not listed here, so this cannot become a general-purpose "echo the
 * POST back" hole.
 *
 * @return array<string,string>
 */
function shop_checkout_old_input(): array
{
    $allowed = [
        'billing_name', 'billing_phone',
        'shipping_name', 'shipping_phone', 'shipping_address',
        'shipping_barangay', 'shipping_city', 'shipping_province', 'shipping_region', 'shipping_postal',
        'shipping_region_code', 'shipping_province_code', 'shipping_city_code', 'shipping_barangay_code',
        'notes_member', 'payment_method',
    ];

    $old = $_SESSION['shop_checkout_old'] ?? [];
    unset($_SESSION['shop_checkout_old']);   // one render only
    if (!is_array($old)) return [];

    $clean = [];
    foreach ($allowed as $k) {
        if (isset($old[$k]) && is_scalar($old[$k])) {
            $clean[$k] = (string) $old[$k];
        }
    }
    return $clean;
}

/**
 * Stash the checkout form so a redirect back can repopulate it. See
 * shop_checkout_old_input().
 */
function shop_checkout_remember_input(array $post): void
{
    $allowed = [
        'billing_name', 'billing_phone',
        'shipping_name', 'shipping_phone', 'shipping_address',
        'shipping_barangay', 'shipping_city', 'shipping_province', 'shipping_region', 'shipping_postal',
        'shipping_region_code', 'shipping_province_code', 'shipping_city_code', 'shipping_barangay_code',
        'notes_member', 'payment_method',
    ];
    $clean = [];
    foreach ($allowed as $k) {
        if (isset($post[$k]) && is_scalar($post[$k])) {
            $clean[$k] = (string) $post[$k];
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['shop_checkout_old'] = $clean;
    }
}

/**
 * Rows per page, from an ALLOW-LIST (decision D5).
 *
 * This reads a user-supplied query parameter straight into a SQL LIMIT.
 * The previous version was `max($min, (int)($_GET['per_page'] ?? $default))`
 * with no ceiling, so ?per_page=99999999 produced an unbounded SELECT on any
 * list page (defect H5). A bare ceiling is looser than it needs to be: an
 * allow-list guarantees the page links can only ever emit a legal value, and a
 * typo degrades to the default instead of to some arbitrary number.
 *
 * (The version before that was `(int)(['per_page'] ?? $default)`, which cast a
 * literal array to 1 and therefore always returned the floor of 5.)
 */
function per_page(int $default = 10, int $min = 5): int
{
    static $allowed = [10, 25, 50, 100];
    $val = (int) ($_GET['per_page'] ?? $default);
    if (in_array($val, $allowed, true)) {
        return $val;
    }
    return in_array($default, $allowed, true) ? $default : $allowed[0];
}

/** upload */
function upload_image(array $file, string $subDir, string $prefix, ?string $oldPath = null, int $maxBytes = 5242880): ?string { if (empty($file['tmp_name']) || (int)($file['error']??0)===UPLOAD_ERR_NO_FILE) return null; if ((int)$file['error']!==UPLOAD_ERR_OK) throw new RuntimeException('upload'); $mime=mime_content_type($file['tmp_name']); $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp']; if (!isset($allowed[$mime])) throw new InvalidArgumentException('type'); if ((int)$file['size']>$maxBytes) throw new InvalidArgumentException('size'); $root=dirname(__DIR__).DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR; $dir=$root.str_replace(['/','\\\\'],DIRECTORY_SEPARATOR,$subDir).DIRECTORY_SEPARATOR; if(!is_dir($dir)) mkdir($dir,0755,true); $name=$prefix.'_'.time().'.'.$allowed[$mime]; if(!move_uploaded_file($file['tmp_name'], $dir.$name)) throw new RuntimeException('save'); if($oldPath) delete_uploaded_file($oldPath); return $subDir.'/'.$name; }

/** delete */
function delete_uploaded_file(?string $p): void { if(!$p)return; $root=dirname(__DIR__).DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR; $path=$root.str_replace(['/','\\\\'],DIRECTORY_SEPARATOR,$p); if(file_exists($path)) @unlink($path); }

// ── Shop status helpers (plan §3.1 Phase 5.7) ────────────────────────────────

/** The 14 statuses → human label. Single source for member + admin badges. */
function shop_status_label(string $status): string
{
    return match ($status) {
        'pending'            => 'Pending payment',
        'payment_review'     => 'Payment under review',
        'payment_failed'     => 'Payment rejected',
        'paid'               => 'Paid',
        'packing'            => 'Packing',
        'ready_to_ship'      => 'Ready to ship',
        'shipped'            => 'Shipped',
        'out_for_delivery'   => 'Out for delivery',
        'delivery_failed'    => 'Delivery failed',
        'returned_to_sender' => 'Returned to sender',
        'delivered'          => 'Delivered',
        'completed'          => 'Completed',
        'cancelled'          => 'Cancelled',
        'on_hold'            => 'On hold',
        default              => 'Unknown',
    };
}

/** The 14 statuses → Bootstrap tone, so member and admin badges always agree. */
function shop_status_tone(string $status): string
{
    return match ($status) {
        'pending'            => 'warning',
        'payment_review'     => 'info',
        'payment_failed'     => 'danger',
        'paid', 'delivered'  => 'success',
        'packing'            => 'secondary',
        'ready_to_ship'      => 'primary',
        'shipped'            => 'primary',
        'out_for_delivery'   => 'primary',
        'delivery_failed'    => 'danger',
        'returned_to_sender' => 'dark',
        'completed'          => 'success',
        'cancelled'          => 'secondary',
        'on_hold'            => 'dark',
        default              => 'secondary',
    };
}

/** Rendered status pill, optionally with a timestamp under it. */
function shop_status_badge(string $status, ?string $stamp = null): string
{
    $label = e(shop_status_label($status));
    $tone  = shop_status_tone($status);
    $small = $stamp ? '<small class="text-muted d-block" style="font-size:.68rem;">' . e(fmt_datetime($stamp)) . '</small>' : '';
    return '<span class="badge bg-' . $tone . '">' . $label . '</span>' . $small;
}

/**
 * Next-action hint for the admin fulfilment queue, derived from the matrix
 * (§5.15). Lives here rather than in views/admin/shop_orders.php, where a
 * function declaration at file scope means a second include anywhere is a
 * fatal "Cannot redeclare" (defect M14).
 *
 * @return string|null null for statuses with no queued action (terminal ones).
 */
function shop_next_action(string $status): ?string
{
    return match ($status) {
        'payment_review'     => 'Verify or reject the proof',
        'payment_failed'     => 'Waiting for buyer re-upload',
        'paid'               => 'Start packing',
        'packing'            => 'Mark ready to ship',
        'ready_to_ship'      => 'Hand off to the courier',
        'shipped'            => 'Track until delivery',
        'out_for_delivery'   => 'Awaiting POD',
        'delivery_failed'    => 'Retry or return to sender',
        'returned_to_sender' => 'Reship or resolve',
        'delivered'          => 'Awaiting receipt / window',
        'on_hold'            => 'Resolve the hold',
        default              => null,
    };
}

/** Public order number: AL + yymmdd + zero-padded id (plan §2.3 note). */
function shop_public_order_no(int $id, string $createdAt): string
{
    return 'AL' . date('ymd', strtotime($createdAt)) . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

/**
 * One-line copy for the admin order desk's confirm modal, keyed by target
 * status. Lives here rather than in views/admin/shop_order.php for the same
 * M14 reason as shop_next_action(): a file-scope function in a view is a
 * fatal redeclare on a second include.
 *
 * The copy is plain text: it travels through a data-CONFIRM attribute and
 * is assigned to a data attribute in JS. HTML entities would show as
 * literal text there, so no markup is sent.
 *
 * @return string|null null = no specific copy; the requester gets a default.
 */
function shop_order_confirm_copy(string $to): ?string
{
    return match ($to) {
        'payment_failed'     => 'Reject this proof? The buyer gets a correction window to re-upload.',
        'payment_review'     => 'Move this PAID order back to payment review? This reopens the proof loop.',
        'cancelled'          => 'CANCEL this order? Its stock is released and a refund task may open.',
        'returned_to_sender' => 'Mark this parcel as RETURNED TO SENDER?',
        default              => null,
    };
}

/**
 * Seller identity printed on invoices and refund receipts. Settings-driven so
 * the documents carry real legal details (name, address, TIN) rather than just
 * the platform brand. Falls back to site_name so a fresh install still prints.
 */
function shop_seller_identity(): array
{
    $name = trim(setting('invoice_company_name'));
    if ($name === '') {
        $name = setting('site_name', APP_NAME);
    }
    return [
        'name'    => $name,
        'address' => setting('invoice_address'),
        'tin'     => setting('invoice_tin'),
        'phone'   => setting('invoice_phone'),
        'email'   => setting('contact_email'),
        'footer'  => setting('invoice_footer', 'This document is computer-generated and valid without a signature.'),
        'vat'     => setting('invoice_vat_note', 'Prices are inclusive of applicable taxes.'),
        'currency'=> setting('invoice_currency', 'PHP'),
    ];
}

/**
 * Refund credit-note / receipt number. Deterministic from the order number and
 * refund id so it can be regenerated identically wherever it is displayed, and
 * stored once in shop_refunds.receipt_ref so it is immutable after issue.
 */
function shop_credit_note_no(string $orderNo, int $refundId): string
{
    $prefix = setting('invoice_credit_prefix', 'CN');
    return $prefix . '-' . $orderNo . '-' . str_pad((string) $refundId, 2, '0', STR_PAD_LEFT);
}

/** Human label for a shop_orders.payment_method value. */
function shop_payment_method_label(string $method): string
{
    return match ($method) {
        'ewallet'    => 'E-wallet',
        'gcash'      => 'GCash',
        'maya'       => 'Maya',
        'usdt_trc20' => 'USDT (TRC20)',
        'usdt_bep20' => 'USDT (BEP20)',
        default      => ucfirst(str_replace('_', ' ', $method)),
    };
}

/** Human label for a shop_refunds.method value. */
function shop_refund_method_label(string $method): string
{
    return match ($method) {
        'ewallet' => 'E-wallet credit',
        'cash'    => 'Cash',
        'bank'    => 'Bank transfer',
        'manual'  => 'GCash / other (manual)',
        default   => ucfirst(str_replace('_', ' ', $method)),
    };
}

/** Human label for a shop_refunds.cause value. */
function shop_refund_cause_label(string $cause): string
{
    return ucwords(str_replace('_', ' ', $cause));
}

