<?php

/**
 * @file   midnight_reset.php (Web Version)
 * @brief  Midnight reset — accessible via HTTP for Hostinger cron or manual trigger
 *
 * Access:  https://altasfarm.com/midnight_reset.php?key=YOUR_SECRET_KEY
 * Cron:    wget -q -O - "https://altasfarm.com/midnight_reset.php?key=YOUR_SECRET_KEY"
 *          OR curl -s "https://altasfarm.com/midnight_reset.php?key=YOUR_SECRET_KEY"
 *
 * SECURITY: Change $cronKey below to a long random string. Never share it.
 */

// ── Security Key ────────────────────────────────────────────────────────────
// CHANGE THIS to a long random string (e.g. 32+ chars from random.org)
$cronKey = 'secret12345678';

// ── Maintenance switch ───────────────────────────────────────────────────────
// Set to false to completely disable this cron job (maintenance mode).
// When false, the script exits without touching the database.
$midnightResetEnabled = true;

// ── Validate key ────────────────────────────────────────────────────────────
$providedKey = $_GET['key'] ?? '';
if ($providedKey !== $cronKey) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('Forbidden: invalid or missing key. Access denied.\n');
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/helpers.php';

// ── Autoload models & controllers ───────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    foreach (['models/', 'controllers/'] as $dir) {
        $file = __DIR__ . '/' . $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ── v2: Conditionally load CapEngine & Reactivation (Phase 2/4) ───────────
$capEngineAvailable = false;
$capEnginePath = __DIR__ . '/core/CapEngine.php';
if (file_exists($capEnginePath)) {
    require_once $capEnginePath;
    $capEngineAvailable = true;
}

$reactivationAvailable = false;
$reactivationPath = __DIR__ . '/core/Reactivation.php';
if (file_exists($reactivationPath)) {
    require_once $reactivationPath;
    $reactivationAvailable = true;
}

// ── v3: Load DailyFixedIncome (Phase 3 deployed) ──────────────────────────
$dfiAvailable = false;
$dfiPath = __DIR__ . '/core/DailyFixedIncome.php';
if (file_exists($dfiPath)) {
    require_once $dfiPath;
    $dfiAvailable = true;
} else {
    class DailyFixedIncome
    {
        public static function processDailyPayout(): array
        {
            return ['processed' => 0, 'paid' => 0.00, 'skipped' => 0];
        }
    }
}

// ── Log helpers ─────────────────────────────────────────────────────────────
$logDir  = __DIR__ . '/cron/logs';
$logFile = $logDir . '/reset_' . date('Y-m') . '.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function log_line(string $level, string $message, string $logFile): void
{
    $ts   = date('Y-m-d H:i:s T');
    $line = "[{$ts}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function log_info(string $m, string $f): void  { log_line('INFO ', $m, $f); }
function log_ok(string $m,   string $f): void  { log_line('OK   ', $m, $f); }
function log_warn(string $m, string $f): void  { log_line('WARN ', $m, $f); }
function log_error(string $m, string $f): void { log_line('ERROR', $m, $f); }

// ── Maintenance gate ─────────────────────────────────────────────────────────
if (!$midnightResetEnabled) {
    log_warn('Midnight reset DISABLED via $midnightResetEnabled — maintenance mode, skipping.', $logFile);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Midnight reset disabled (maintenance mode). Nothing was run.\n";
    exit(0);
}

// ── Run ─────────────────────────────────────────────────────────────────────
$startTime = microtime(true);
$results   = [];  // collect for HTTP response

log_info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', $logFile);
log_info('Midnight reset started — ' . APP_NAME, $logFile);
log_info('Environment : ' . APP_ENV,  $logFile);
log_info('Database    : ' . DB_NAME,  $logFile);
log_info('Server time : ' . date('D, d M Y h:i:s A T'), $logFile);
log_info('Triggered by: ' . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), $logFile);

try {
    $pdo = db();

    // 1. Verify DB connection
    $pdo->query('SELECT 1');
    log_ok('Database connection established.', $logFile);

    // 2. Snapshot before reset
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

    $results['snapshot'] = [
        'total_members'       => $totalMembers,
        'active_members'      => $activeMembers,
        'members_with_pairs'  => $nonZeroMembers,
        'total_pairs_today'   => $totalPairsToday,
        'members_with_volume' => $nonZeroVol,
        'total_volume_today'  => $totalVolToday,
    ];

    // 3. Perform pairs_paid_today reset (count + volume)
    $affected = $pdo->exec("UPDATE users SET pairs_paid_today = 0, pairs_volume_today = 0 WHERE role = 'member'");
    log_ok("pairs_paid_today / pairs_volume_today reset to 0. Rows updated: {$affected}", $logFile);
    $results['pairs_reset'] = $affected;

    // 4. Verify reset applied
    $remaining = (int)$pdo->query("SELECT COALESCE(SUM(pairs_paid_today), 0) FROM users WHERE role = 'member'")->fetchColumn();
    $remainingVol = (float)$pdo->query("SELECT COALESCE(SUM(pairs_volume_today), 0) FROM users WHERE role = 'member'")->fetchColumn();
    if ($remaining === 0 && $remainingVol == 0) {
        log_ok('Verification passed — all daily pairing counters confirmed at 0.', $logFile);
        $results['verification'] = 'passed';
    } else {
        log_warn("Verification warning — {$remaining} total pairs and " . number_format($remainingVol, 2) . " matched volume remain non-zero after reset.", $logFile);
        $results['verification'] = "warning: {$remaining} pairs remaining";
    }

    // 5. Update last_reset timestamp
    $resetTs = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE settings SET value = ? WHERE key_name = 'last_reset'")
        ->execute([$resetTs]);
    log_ok("last_reset timestamp updated: {$resetTs}", $logFile);
    $results['last_reset'] = $resetTs;

    // ════════════════════════════════════════════════════════════════════════
    //  v2 ADDITIONS
    // ════════════════════════════════════════════════════════════════════════

    // 6. v4: Expire capped members who missed reactivation window
    $results['cap_expiration'] = ['expired' => 0, 'status' => 'skipped'];
    if ($reactivationAvailable && $capEngineAvailable) {
        $expired = Reactivation::expireOldCappedUsers();
        if ($expired > 0) {
            log_ok("Cap expiration: {$expired} member(s) moved from 'capped' to 'perminact'.", $logFile);
            $results['cap_expiration'] = ['expired' => $expired, 'status' => 'ok'];
        } else {
            log_info('Cap expiration: No members past reactivation window.', $logFile);
            $results['cap_expiration'] = ['expired' => 0, 'status' => 'none'];
        }
    } else {
        if (!$capEngineAvailable) {
            log_warn('CapEngine not available — skipping cap expiration (deploy Phase 2 core/CapEngine.php).', $logFile);
        }
        if (!$reactivationAvailable) {
            log_warn('Reactivation model not available — skipping cap expiration (deploy Phase 4 core/Reactivation.php).', $logFile);
        }
        $results['cap_expiration']['status'] = 'skipped_missing_deps';
    }

    // 7. v3: Daily Fixed Income payout
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
    $results['dfi'] = $dfiResult;

    // 8. Summary
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    log_info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", $logFile);
    log_ok("Reset complete. Duration: {$elapsed}ms", $logFile);
    log_info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", $logFile);

    $results['status']     = 'success';
    $results['duration_ms'] = $elapsed;

    // ── HTTP Response ─────────────────────────────────────────────────────────
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false || ($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($results, JSON_PRETTY_PRINT);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "✅ Midnight Reset Complete\n";
        echo "Duration: {$elapsed}ms\n";
        echo "Pairs reset: {$results['pairs_reset']} rows\n";
        echo "DFI: {$dfiResult['paid']} paid to {$dfiResult['processed']} members\n";
        echo "Cap expired: {$results['cap_expiration']['expired']} members\n";
        echo "Timestamp: {$resetTs}\n";
    }
    exit(0);

} catch (\Exception $e) {
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    log_error("Reset FAILED after {$elapsed}ms", $logFile);
    log_error('Exception : ' . $e->getMessage(), $logFile);
    log_error('File      : ' . $e->getFile() . ':' . $e->getLine(), $logFile);
    log_info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", $logFile);

    $results['status']   = 'error';
    $results['error']    = $e->getMessage();
    $results['duration_ms'] = $elapsed;

    http_response_code(500);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false || ($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($results, JSON_PRETTY_PRINT);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "❌ Reset FAILED\n";
        echo "Error: {$e->getMessage()}\n";
        echo "Duration: {$elapsed}ms\n";
    }
    exit(1);
}