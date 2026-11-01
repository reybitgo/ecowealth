<?php

/**
 * @file   cron/test_cron.php
 * @brief  Test cron job
 */

/**
 * TEST CRON — Every Minute
 * Crontab: * * * * * /usr/bin/php /var/www/html/altasfarm/cron/test_cron.php
 *
 * Purpose:
 *   Verify that the cron scheduler is running correctly.
 *   Logs a heartbeat every minute with system info and DB connectivity check.
 *
 * Delete or disable this file after confirming cron is working.
 */

// ── Timezone ──────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/db.php';

// ── Log setup ─────────────────────────────────────────────────────────────────
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/test_cron_' . date('Y-m-d') . '.log';  // one file per day

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
$runNumber = 'RUN #' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT);
$startTime = microtime(true);

log_info('────────────────────────────────────────────────────────────', $logFile);
log_info("{$runNumber} — Test cron heartbeat", $logFile);
log_info('Time      : ' . date('D, d M Y h:i:s A T'), $logFile);
log_info('Script    : ' . basename(__FILE__), $logFile);
log_info('PHP       : ' . PHP_VERSION, $logFile);
log_info('Host      : ' . gethostname(), $logFile);

// ── 1. DB connectivity check ──────────────────────────────────────────────────
try {
    $pdo = db();
    $pdo->query('SELECT 1');
    log_ok('DB connection : OK (' . DB_NAME . '@' . DB_HOST . ')', $logFile);

    // Quick stats to prove DB is live
    $memberCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member'")->fetchColumn();
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='active'")->fetchColumn();
    $codeCount   = (int)$pdo->query("SELECT COUNT(*) FROM reg_codes WHERE status='unused'")->fetchColumn();
    log_info("Members       : {$memberCount} total, {$activeCount} active", $logFile);
    log_info("Unused codes  : {$codeCount}", $logFile);
} catch (\Exception $e) {
    log_error('DB connection FAILED: ' . $e->getMessage(), $logFile);
}

// ── 2. System health snapshot ─────────────────────────────────────────────────
$memUsage = round(memory_get_usage(true) / 1024, 1);
$memPeak  = round(memory_get_peak_usage(true) / 1024, 1);
log_info("Memory        : {$memUsage} KB (peak {$memPeak} KB)", $logFile);

// ── 3. Disk space (log dir) ───────────────────────────────────────────────────
$diskFree  = disk_free_space($logDir);
$diskTotal = disk_total_space($logDir);
if ($diskFree !== false && $diskTotal !== false) {
    $diskFreeMB  = round($diskFree  / 1024 / 1024, 1);
    $diskTotalMB = round($diskTotal / 1024 / 1024, 1);
    $diskPct     = round((1 - $diskFree / $diskTotal) * 100, 1);
    log_info("Disk          : {$diskFreeMB} MB free of {$diskTotalMB} MB ({$diskPct}% used)", $logFile);

    if ($diskPct > 90) {
        log_warn("Disk usage above 90%! Consider cleaning old logs.", $logFile);
    }
}

// ── 4. Log file size check ────────────────────────────────────────────────────
$logSizeKB = file_exists($logFile) ? round(filesize($logFile) / 1024, 1) : 0;
log_info("Log file size : {$logSizeKB} KB ({$logFile})", $logFile);

if ($logSizeKB > 500) {
    log_warn("Log file exceeds 500 KB — consider archiving or rotating.", $logFile);
}

// ── 5. Duration & exit ────────────────────────────────────────────────────────
$elapsed = round((microtime(true) - $startTime) * 1000, 2);
log_ok("{$runNumber} complete. Duration: {$elapsed}ms", $logFile);

exit(0);
