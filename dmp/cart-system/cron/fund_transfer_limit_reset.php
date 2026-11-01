<?php

/**
 * @file   cron/fund_transfer_limit_reset.php
 * @brief  E-Wallet Transfer Limit Reset Cron
 *
 * Resets member transfer limit tracking counters:
 *   - Daily:  ewallet_sent_today      → 0 (every day at midnight)
 *   - Weekly: ewallet_sent_this_week  → 0 (every Monday at midnight)
 *
 * Crontab (daily at 00:00):
 *   0 0 * * * /usr/bin/php /var/www/html/altasfarm/cron/fund_transfer_limit_reset.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';

// ── Log setup ─────────────────────────────────────────────────────────────────
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/transfer_limit_reset_' . date('Y-m') . '.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function log_line(string $level, string $message, string $logFile): void
{
    $ts   = date('Y-m-d H:i:s T');
    $line = "[{$ts}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

function log_info(string $m, string $f): void
{
    log_line('INFO ', $m, $f);
}
function log_ok(string $m,   string $f): void
{
    log_line('OK   ', $m, $f);
}
function log_warn(string $m, string $f): void
{
    log_line('WARN ', $m, $f);
}
function log_error(string $m, string $f): void
{
    log_line('ERROR', $m, $f);
}

// ── Run ───────────────────────────────────────────────────────────────────────
$startTime = microtime(true);
$isMonday  = (date('N') === '1'); // 1 = Monday

log_info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', $logFile);
log_info('Transfer limit reset started — ' . APP_NAME, $logFile);
log_info('Date      : ' . date('D, d M Y'), $logFile);
log_info('Is Monday : ' . ($isMonday ? 'YES (weekly reset will run)' : 'NO (daily reset only)'), $logFile);

try {
    $pdo = db();
    $pdo->query('SELECT 1');
    log_ok('Database connection established.', $logFile);

    // ── 1. Snapshot before reset ──────────────────────────────────────────────
    $totalMembers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
    $withDaily    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND ewallet_sent_today > 0")
        ->fetchColumn();
    $withWeekly   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND ewallet_sent_this_week > 0")
        ->fetchColumn();

    log_info("Members (total)              : {$totalMembers}", $logFile);
    log_info("Members with daily usage     : {$withDaily}", $logFile);
    log_info("Members with weekly usage    : {$withWeekly}", $logFile);

    // ── 2. Daily reset (always) ───────────────────────────────────────────────
    $affectedDaily = $pdo->exec("UPDATE users SET ewallet_sent_today = 0.00 WHERE role = 'member'");
    log_ok("Daily limit reset. Rows updated: {$affectedDaily}", $logFile);

    // ── 3. Weekly reset (Mondays only) ────────────────────────────────────────
    if ($isMonday) {
        $affectedWeekly = $pdo->exec("UPDATE users SET ewallet_sent_this_week = 0.00 WHERE role = 'member'");
        log_ok("Weekly limit reset (Monday). Rows updated: {$affectedWeekly}", $logFile);
    } else {
        log_info('Weekly limit reset skipped (not Monday).', $logFile);
    }

    // ── 4. Verify ─────────────────────────────────────────────────────────────
    $remainingDaily = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND ewallet_sent_today > 0")
        ->fetchColumn();
    $remainingWeekly = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND ewallet_sent_this_week > 0")
        ->fetchColumn();

    if ($remainingDaily === 0) {
        log_ok('Verification passed — all daily counters confirmed at 0.', $logFile);
    } else {
        log_warn("Verification warning — {$remainingDaily} member(s) still have non-zero daily counters.", $logFile);
    }

    if ($isMonday && $remainingWeekly === 0) {
        log_ok('Verification passed — all weekly counters confirmed at 0.', $logFile);
    } elseif ($isMonday) {
        log_warn("Verification warning — {$remainingWeekly} member(s) still have non-zero weekly counters.", $logFile);
    }

    // ── 5. Summary ────────────────────────────────────────────────────────────
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    log_info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", $logFile);
    log_ok("Reset complete. Duration: {$elapsed}ms", $logFile);
    log_info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", $logFile);

    exit(0);
} catch (\Exception $e) {
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    log_error("Reset FAILED after {$elapsed}ms", $logFile);
    log_error('Exception : ' . $e->getMessage(), $logFile);
    log_error('File      : ' . $e->getFile() . ':' . $e->getLine(), $logFile);
    log_info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', $logFile);
    exit(1);
}
