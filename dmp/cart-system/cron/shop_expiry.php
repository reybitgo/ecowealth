<?php

/**
 * @file   cron/shop_expiry.php
 * @brief  Shop order expiry cron (plan Phase 6, tmp/shop/CART_AND_ADMIN_PRODUCTS_PLAN_REVISED.md)
 *
 * Crontab (documented in the plan; install on the server):
 *   *\/15 * * * * /usr/bin/php /var/www/html/altaslive/cron/shop_expiry.php >> /var/www/html/altaslive/cron/logs/shop_expiry_$(date +\%Y-\%m).log 2>&1
 *
 * Runs every 15 minutes: the shortest deadline it acts on is hours-scale, so
 * a quarter-hour cadence keeps timers tight without meaningful load.
 *
 * Jobs (plan §3 Phase 6.1):
 *   1. ShopOrder::expireOverdue(200)  — pending/payment_failed past deadline
 *      → cancelled (reason payment_expired), lines released. NEVER touches
 *      payment_review (§0.5 T7): a submitted proof is exempt from expiry.
 *   2. ShopOrder::autoComplete(200)   — delivered past completion_due_at with
 *      no open refund → completed (§0.5 T24).
 *   3. Stale holds are deliberately NOT expired here: a hold without
 *      resolution is an admin decision (§6.1 item 3), not a timer decision.
 *
 * Every expiry moves through ShopOrder::transition() — never a raw UPDATE —
 * and every resulting event row carries source='timer', actor_type='system'.
 */

// ── Timezone ──────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';

// ── Autoload models & controllers (same as index.php) ───────────────────────
spl_autoload_register(function (string $class): void {
    foreach (['models/', 'controllers/'] as $dir) {
        $file = __DIR__ . '/../' . $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ── Log helpers (same style as midnight_reset.php) ───────────────────────────
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/shop_expiry_' . date('Y-m') . '.log';  // one file per month

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function shop_log_line(string $level, string $message, string $logFile): void
{
    $ts   = date('Y-m-d H:i:s T');
    $line = "[{$ts}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

function shop_log_info(string $m, string $f): void
{
    shop_log_line('INFO ', $m, $f);
}
function shop_log_ok(string $m, string $f): void
{
    shop_log_line('OK   ', $m, $f);
}
function shop_log_warn(string $m, string $f): void
{
    shop_log_line('WARN ', $m, $f);
}
function shop_log_error(string $m, string $f): void
{
    shop_log_line('ERROR', $m, $f);
}

// ── Run ───────────────────────────────────────────────────────────────────────
$startTime = microtime(true);

shop_log_info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', $logFile);
shop_log_info('Shop expiry started — ' . APP_NAME, $logFile);
shop_log_info('Database    : ' . DB_NAME, $logFile);
shop_log_info('Server time : ' . date('D, d M Y h:i:s A T'), $logFile);

try {
    $pdo = db();

    // ── 1. Verify DB connection ───────────────────────────────────────────────
    $pdo->query('SELECT 1');
    shop_log_ok('Database connection established.', $logFile);

    // ── 2. Shop master switch ─────────────────────────────────────────────────
    // When the storefront is closed the timers keep running: closing the shop
    // must not strand buyers' pending orders past their deadlines.
    shop_log_info('Shop master switch (shop_enabled): ' . setting('shop_enabled', '1'), $logFile);

    // ── 3. Leg 1: expire overdue pending / payment_failed orders ──────────────
    $expired = ShopOrder::expireOverdue(200);
    if ($expired > 0) {
        shop_log_ok("Expiry: {$expired} order(s) cancelled (reason payment_expired), lines released.", $logFile);
    } else {
        shop_log_info('Expiry: no orders past their payment/correction deadline.', $logFile);
    }

    // ── 4. Leg 2: auto-complete delivered orders past the report window ───────
    $completed = ShopOrder::autoComplete(200);
    if ($completed > 0) {
        shop_log_ok("Auto-complete: {$completed} delivered order(s) closed as completed.", $logFile);
    } else {
        shop_log_info('Auto-complete: no delivered orders past their report window.', $logFile);
    }

    // ── 5. Overdue hold alert (surfaces in the log; the admin desk shows it) ──
    $overdueHolds = (int) $pdo->query(
        "SELECT COUNT(*) FROM shop_orders
          WHERE status = 'on_hold' AND hold_deadline IS NOT NULL AND hold_deadline < NOW()"
    )->fetchColumn();
    if ($overdueHolds > 0) {
        shop_log_warn("{$overdueHolds} hold(s) past their deadline — admin decision required (T8: close as customer_unreachable).", $logFile);
    }

    // ── 6. Summary ────────────────────────────────────────────────────────────
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    shop_log_ok("Shop expiry complete. Expired: {$expired}, auto-completed: {$completed}. Duration: {$elapsed}ms", $logFile);
    shop_log_info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', $logFile);

    exit(0);
} catch (\Exception $e) {
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    shop_log_error("Shop expiry FAILED after {$elapsed}ms", $logFile);
    shop_log_error('Exception : ' . $e->getMessage(), $logFile);
    shop_log_error('File      : ' . $e->getFile() . ':' . $e->getLine(), $logFile);
    shop_log_info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', $logFile);
    exit(1);
}
