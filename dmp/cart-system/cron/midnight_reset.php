<?php

/**
 * @file   cron/midnight_reset.php
 * @brief  Midnight reset cron job (v2)
 */

/**
 * MIDNIGHT RESET CRON (v2)
 * Crontab: 0 0 * * * /usr/bin/php /var/www/html/altasfarm/cron/midnight_reset.php
 *
 * v2 jobs:
 *   1. Reset pairs_paid_today = 0 for all active members.
 *   2. Expire capped members who missed reactivation window.
 *   3. Trigger Daily Fixed Income payout (if Phase 3 deployed).
 *
 * All commission calculations are real-time and happen during registration.
 * This script handles scheduled daily batch operations only.
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

// ── v2: Conditionally load CapEngine & Reactivation (Phase 2/4) ─────────────
$capEngineAvailable = false;
$capEnginePath = __DIR__ . '/../core/CapEngine.php';
if (file_exists($capEnginePath)) {
    require_once $capEnginePath;
    $capEngineAvailable = true;
}

$reactivationAvailable = false;
$reactivationPath = __DIR__ . '/../core/Reactivation.php';
if (file_exists($reactivationPath)) {
    require_once $reactivationPath;
    $reactivationAvailable = true;
}

// ── v3: Load DailyFixedIncome (Phase 3 deployed) ──────────────────────────────
$dfiAvailable = false;
$dfiPath = __DIR__ . '/../core/DailyFixedIncome.php';
if (file_exists($dfiPath)) {
    require_once $dfiPath;
    $dfiAvailable = true;
} else {
    // Fallback stub (should not happen after Phase 3 deploy)
    class DailyFixedIncome
    {
        public static function processDailyPayout(): array
        {
            return ['processed' => 0, 'paid' => 0.00, 'skipped' => 0];
        }
    }
}

// ── Log helpers ───────────────────────────────────────────────────────────────
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/reset_' . date('Y-m') . '.log';  // one file per month

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

log_info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', $logFile);
log_info('Midnight reset started — ' . APP_NAME, $logFile);
log_info('Environment : ' . APP_ENV,  $logFile);
log_info('Database    : ' . DB_NAME,  $logFile);
log_info('Server time : ' . date('D, d M Y h:i:s A T'), $logFile);

try {
    $pdo = db();

    // ── 1. Verify DB connection ───────────────────────────────────────────────
    $pdo->query('SELECT 1');
    log_ok('Database connection established.', $logFile);

    // ── 2. Snapshot before reset ──────────────────────────────────────────────
    $totalMembers    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
    $activeMembers   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active'")->fetchColumn();
    $nonZeroMembers  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND pairs_paid_today > 0")->fetchColumn();
    $totalPairsToday = (int)$pdo->query("SELECT COALESCE(SUM(pairs_paid_today), 0) FROM users WHERE role = 'member'")->fetchColumn();
    $nonZeroVol     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND pairs_volume_today > 0")->fetchColumn();
    $totalVolToday  = (float)$pdo->query("SELECT COALESCE(SUM(pairs_volume_today), 0) FROM users WHERE role = 'member'")->fetchColumn();

    log_info("Members (total)         : {$totalMembers}", $logFile);
    log_info("Members (active)        : {$activeMembers}", $logFile);
    log_info("Members with pairs today  : {$nonZeroMembers}", $logFile);
    log_info("Total pairs today       : {$totalPairsToday}", $logFile);
    log_info("Members with volume today : {$nonZeroVol}", $logFile);
    log_info("Total matched volume today: " . number_format($totalVolToday, 2), $logFile);

    // ── 3. Perform pairs_paid_today reset (count + volume) ──────────────────
    $affected = $pdo->exec("UPDATE users SET pairs_paid_today = 0, pairs_volume_today = 0 WHERE role = 'member'");
    log_ok("pairs_paid_today / pairs_volume_today reset to 0. Rows updated: {$affected}", $logFile);

    // ── 4. Verify reset applied ───────────────────────────────────────────────
    $remaining = (int)$pdo->query("SELECT COALESCE(SUM(pairs_paid_today), 0) FROM users WHERE role = 'member'")->fetchColumn();
    $remainingVol = (float)$pdo->query("SELECT COALESCE(SUM(pairs_volume_today), 0) FROM users WHERE role = 'member'")->fetchColumn();
    if ($remaining === 0 && $remainingVol == 0) {
        log_ok('Verification passed — all daily pairing counters confirmed at 0.', $logFile);
    } else {
        log_warn("Verification warning — {$remaining} total pairs and " . number_format($remainingVol, 2) . " matched volume remain non-zero after reset.", $logFile);
    }

    // ── 5. Update last_reset timestamp ────────────────────────────────────────
    $resetTs = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE settings SET value = ? WHERE key_name = 'last_reset'")
        ->execute([$resetTs]);
    log_ok("last_reset timestamp updated: {$resetTs}", $logFile);

    // ══════════════════════════════════════════════════════════════════════════
    //  v2 ADDITIONS
    // ══════════════════════════════════════════════════════════════════════════

    // ── 6. v4: Expire capped members who missed reactivation window ───────────
    if ($reactivationAvailable && $capEngineAvailable) {
        $expired = Reactivation::expireOldCappedUsers();
        if ($expired > 0) {
            log_ok("Cap expiration: {$expired} member(s) moved from 'capped' to 'perminact'.", $logFile);
        } else {
            log_info('Cap expiration: No members past reactivation window.', $logFile);
        }
    } else {
        if (!$capEngineAvailable) {
            log_warn('CapEngine not available — skipping cap expiration (deploy Phase 2 core/CapEngine.php).', $logFile);
        }
        if (!$reactivationAvailable) {
            log_warn('Reactivation model not available — skipping cap expiration (deploy Phase 4 core/Reactivation.php).', $logFile);
        }
    }

    // ── 7. v3: Daily Fixed Income payout ──────────────────────────────────────
    // DFI is skipped entirely once the member seat limit is reached: no new
    // joins are possible, so the company stops accruing new fixed-income
    // liability (matches the sim's max-members cutoff). Members simply do not
    // receive a payout on these nights; dfi_days_used is not advanced because
    // the increment happens only inside processDailyPayout() when paid.
    if (isSeatLimitReached()) {
        $dfiResult = ['processed' => 0, 'paid' => 0.00, 'skipped' => 0, 'reason' => 'seat_limit'];
        log_warn('DFI payout: SKIPPED — member seat limit reached (no new joins possible).', $logFile);
    } else {
        $dfiResult = DailyFixedIncome::processDailyPayout();
        if ($dfiAvailable) {
            if (($dfiResult['reason'] ?? '') === 'disabled') {
                log_warn('DFI payout: Globally disabled via settings.', $logFile);
            } else {
                log_ok("DFI payout: {$dfiResult['paid']} paid to {$dfiResult['processed']} member(s), {$dfiResult['skipped']} skipped.", $logFile);
            }
        } else {
            log_warn('DFI payout: DailyFixedIncome.php missing — check deployment.', $logFile);
        }
    }

    // ── 8. Summary ────────────────────────────────────────────────────────────
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
