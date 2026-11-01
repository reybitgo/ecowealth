<?php

/**
 * reset.php — Database Reset Utility for Testing
 *
 * Resets the database to a clean state while preserving:
 *   - All packages (and their indirect level configs)
 *   - System settings
 *   - Admin account
 *
 * Clears:
 *   - All member accounts
 *   - All registration codes (regenerates demo codes)
 *   - All commissions
 *   - All e-wallet ledger entries
 *   - All payout requests
 *
 * ACCESS: Delete or disable this file before going live.
 */

// ── Timezone ──────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/config/db.php';

function generate_code(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len   = strlen($chars);
    $parts = [];
    for ($i = 0; $i < 3; $i++) {
        $part = '';
        for ($j = 0; $j < 4; $j++) $part .= $chars[random_int(0, $len - 1)];
        $parts[] = $part;
    }
    return implode('-', $parts);
}

// ── Handle POST (actual reset) ─────────────────────────────────────────────
$result  = null;
$error   = null;
$logs    = [];
$newCodes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {

    $keepPackages = isset($_POST['keep_packages']);
    $keepAdmin    = true; // always keep admin
    $newCodeQty   = max(1, min(50, (int)($_POST['code_qty'] ?? 5)));
    $newCodePkg   = (int)($_POST['code_pkg'] ?? 1);

    try {
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        // 1. Clear all member accounts
        $del = $pdo->exec("DELETE FROM users WHERE role = 'member'");
        $logs[] = ['ok', "Deleted {$del} member account(s)"];

        // 2. Clear financial tables
        $pdo->exec("DELETE FROM commissions");
        $logs[] = ['ok', 'Cleared commissions table'];

        $pdo->exec("DELETE FROM ewallet_ledger");
        $logs[] = ['ok', 'Cleared e-wallet ledger'];

        $pdo->exec("DELETE FROM payout_requests");
        $logs[] = ['ok', 'Cleared payout requests'];

        // v2: Clear capping & DFI tables
        $pdo->exec("DELETE FROM reactivations");
        $logs[] = ['ok', 'Cleared reactivations table'];

        $pdo->exec("DELETE FROM daily_fixed_income_log");
        $logs[] = ['ok', 'Cleared DFI log table'];

        $pdo->exec("DELETE FROM ewallet_transfers");
        $logs[] = ['ok', 'Cleared e-wallet transfers'];

        $pdo->exec("DELETE FROM ewallet_admin_topups");
        $logs[] = ['ok', 'Cleared admin top-ups'];

        // CD tables
        $pdo->exec("DELETE FROM cd_ledger");
        $logs[] = ['ok', 'Cleared CD ledger'];

        $pdo->exec("DELETE FROM user_cd_status");
        $logs[] = ['ok', 'Cleared CD status records'];

        // v2: Clear uploaded reactivation proof images
        $proofDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'reactivation_proofs';
        if (is_dir($proofDir)) {
            $cleared = 0;
            foreach (glob($proofDir . '/*') as $f) {
                if (is_file($f)) {
                    unlink($f);
                    $cleared++;
                }
            }
            $logs[] = ['ok', "Cleared {$cleared} reactivation proof image(s)"];
        }

        // 3. Clear all registration codes
        $pdo->exec("DELETE FROM reg_codes");
        $logs[] = ['ok', 'Cleared all registration codes'];

        // 4. Reset admin account to clean install state
        $pdo->exec("UPDATE users SET
            package_id            = NULL,
            reg_code_id           = NULL,
            reg_payment_method    = 'code',
            reg_paid_by           = NULL,
            sponsor_id            = NULL,
            binary_parent_id      = NULL,
            binary_position       = NULL,
            ewallet_balance       = 0.00,
            withdrawable_balance  = 0.00,
            left_count            = 0,
            left_count_paid       = 0,
            right_count           = 0,
            right_count_paid      = 0,
            pairs_paid            = 0,
            pairs_flushed         = 0,
            pairs_paid_today      = 0,
            left_pair_volume      = 0.00,
            right_pair_volume     = 0.00,
            pairs_volume_paid     = 0.00,
            pairs_volume_flushed  = 0.00,
            pairs_volume_today    = 0.00,
            lifetime_earned       = 0.00,
            cap_status            = 'active',
            capping_bypass        = 0,
            daily_cap_bypass      = 0,
            capped_at             = NULL,
            last_reactivation_at  = NULL,
            dfi_days_used         = 0,
            dfi_active            = 1,
            cd_active             = 0,
            ewallet_sent_today      = 0.00,
            ewallet_sent_this_week  = 0.00,
            last_login            = NULL
            WHERE role IN ('admin', 'superadmin')
        ");
        $logs[] = ['ok', 'Reset admin accounts (admin + sadmin) to clean install state (counters, balances, tree placement, package all cleared)'];

        // 5. Reset auto-increment counters
        foreach (['users', 'commissions', 'ewallet_ledger', 'payout_requests', 'reg_codes', 'reactivations', 'daily_fixed_income_log', 'ewallet_transfers', 'ewallet_admin_topups', 'cd_ledger', 'user_cd_status', 'impersonation_log'] as $tbl) {
            $pdo->exec("ALTER TABLE {$tbl} AUTO_INCREMENT = 1");
        }
        $logs[] = ['ok', 'Reset auto-increment counters'];

        // 6. Optionally clear packages
        if (!$keepPackages) {
            $pdo->exec("DELETE FROM package_indirect_levels");
            $pdo->exec("DELETE FROM packages");
            $pdo->exec("ALTER TABLE packages AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE package_indirect_levels AUTO_INCREMENT = 1");
            $logs[] = ['ok', 'Cleared all packages'];

            // Re-seed default Starter package (v2 defaults)
            $pdo->exec("INSERT INTO packages (
                id, name, entry_fee, pairing_bonus, daily_pair_cap, direct_ref_bonus,
                lifetime_cap_multiplier, reactivation_fee, reactivation_window_days,
                daily_fixed_income, daily_fixed_income_days, status
            ) VALUES (
                1, 'Starter', 10000.00, 2000.00, 3, 500.00,
                3.00, 10000.00, 15,
                100.00, 90, 'active'
            )");
            $pdo->exec("INSERT INTO package_indirect_levels (package_id, level, bonus) VALUES
                (1,1,300),(1,2,200),(1,3,150),(1,4,100),(1,5,100),
                (1,6,50),(1,7,50),(1,8,50),(1,9,50),(1,10,50)");
            $logs[] = ['ok', 'Re-seeded default Starter package'];
            $newCodePkg = 1;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // 7. Generate fresh registration codes
        $adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
        $pkgExists = (int)$pdo->query("SELECT COUNT(*) FROM packages WHERE id={$newCodePkg}")->fetchColumn();
        if (!$pkgExists) $newCodePkg = (int)$pdo->query("SELECT id FROM packages LIMIT 1")->fetchColumn();

        $pkgName = $pdo->query("SELECT name FROM packages WHERE id={$newCodePkg}")->fetchColumn();
        $st = $pdo->prepare("INSERT INTO reg_codes (code, package_id, price, created_by, code_type) VALUES (?,?,?,?,'registration')");

        for ($i = 0; $i < $newCodeQty; $i++) {
            do {
                $code = generate_code();
                $exists = $pdo->query("SELECT COUNT(*) FROM reg_codes WHERE code='{$code}'")->fetchColumn();
            } while ($exists);
            $price = (float)$pdo->query("SELECT entry_fee + 0 FROM packages WHERE id={$newCodePkg}")->fetchColumn();
            $st->execute([$code, $newCodePkg, $price, $adminId]);
            $newCodes[] = $code;
        }
        $logs[] = ['ok', "Generated {$newCodeQty} fresh registration code(s) for package: {$pkgName}"];

        // 8. Reset last_reset setting
        $pdo->exec("UPDATE settings SET value='' WHERE key_name='last_reset'");
        $logs[] = ['ok', 'Reset last_reset timestamp'];

        $result = 'success';
    } catch (\Exception $e) {
        try {
            db()->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Exception $ignored) {
        }
        $error  = $e->getMessage();
        $result = 'error';
        $logs[] = ['fail', 'ERROR: ' . $e->getMessage()];
    }
}

// ── Load packages for the form ─────────────────────────────────────────────
$packages = [];
$dbOk     = false;
try {
    $packages = db()->query("SELECT id, name, entry_fee FROM packages ORDER BY entry_fee")->fetchAll();
    $memberCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='member'")->fetchColumn();
    $codeCount   = (int)db()->query("SELECT COUNT(*) FROM reg_codes WHERE status='unused'")->fetchColumn();
    $dbOk = true;
} catch (\Exception $e) {
    $error = "Cannot connect to database: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Reset — <?= APP_NAME ?></title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #0f1728;
            color: #dde4f0;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem 4rem;
        }

        .container {
            width: 100%;
            max-width: 640px;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .header .danger-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            background: rgba(224, 52, 52, .15);
            border: 1px solid rgba(224, 52, 52, .35);
            color: #f87171;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            padding: .3rem .9rem;
            border-radius: 999px;
            margin-bottom: .875rem;
        }

        .header h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: .3rem;
        }

        .header p {
            font-size: .82rem;
            color: #6b7a99;
        }

        /* Card */
        .card {
            background: #0e1220;
            border: 1px solid #1e2a45;
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .875rem 1.25rem;
            border-bottom: 1px solid #1e2a45;
            font-size: .8rem;
            font-weight: 700;
            color: #9ca8c0;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Current state stats */
        .stats-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .75rem;
            margin-bottom: 1rem;
        }

        .stat-box {
            background: #151c2e;
            border: 1px solid #1e2a45;
            border-radius: 8px;
            padding: .75rem 1rem;
            text-align: center;
        }

        .stat-box .val {
            font-size: 1.6rem;
            font-weight: 800;
            font-family: monospace;
            color: #fff;
        }

        .stat-box .lbl {
            font-size: .7rem;
            color: #6b7a99;
            margin-top: .2rem;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        /* Form elements */
        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            font-size: .78rem;
            font-weight: 600;
            color: #9ca8c0;
            margin-bottom: .4rem;
            letter-spacing: .3px;
        }

        select,
        input[type=number] {
            width: 100%;
            background: #151c2e;
            border: 1px solid #1e2a45;
            border-radius: 7px;
            color: #dde4f0;
            font-family: inherit;
            font-size: .875rem;
            padding: .6rem .875rem;
            outline: none;
            transition: border-color .15s;
        }

        select:focus,
        input:focus {
            border-color: #3b6ff0;
        }

        select option {
            background: #151c2e;
        }

        /* Checkbox toggle */
        .check-row {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .75rem .875rem;
            background: #151c2e;
            border: 1px solid #1e2a45;
            border-radius: 7px;
            cursor: pointer;
            transition: border-color .15s;
        }

        .check-row:hover {
            border-color: #3b6ff0;
        }

        .check-row input[type=checkbox] {
            width: 15px;
            height: 15px;
            accent-color: #3b6ff0;
            cursor: pointer;
            flex-shrink: 0;
        }

        .check-row .check-label {
            font-size: .82rem;
            color: #dde4f0;
            flex: 1;
        }

        .check-row .check-hint {
            font-size: .72rem;
            color: #5a6a88;
            margin-top: 2px;
        }

        /* What will be cleared list */
        .will-clear {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .will-clear li {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .45rem 0;
            border-bottom: 1px solid #1a2236;
            font-size: .82rem;
        }

        .will-clear li:last-child {
            border-bottom: none;
        }

        .will-clear .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .dot-red {
            background: #e03434;
        }

        .dot-yellow {
            background: #d97706;
        }

        .dot-green {
            background: #12a05c;
        }

        .text-red {
            color: #f87171;
        }

        .text-yellow {
            color: #fbbf24;
        }

        .text-green {
            color: #4ade80;
        }

        /* Confirm input */
        .confirm-group {
            margin-bottom: 1rem;
        }

        .confirm-group label {
            color: #f87171;
            font-size: .78rem;
        }

        #confirmInput {
            width: 100%;
            background: rgba(224, 52, 52, .07);
            border: 1px solid rgba(224, 52, 52, .3);
            border-radius: 7px;
            color: #dde4f0;
            font-family: monospace;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 2px;
            padding: .65rem 1rem;
            text-align: center;
            outline: none;
            transition: border-color .15s;
        }

        #confirmInput:focus {
            border-color: #e03434;
        }

        #confirmInput.ok {
            border-color: #12a05c;
            background: rgba(18, 160, 92, .07);
        }

        /* Submit button */
        .btn-reset {
            width: 100%;
            padding: .875rem;
            background: #e03434;
            color: #fff;
            font-family: inherit;
            font-size: .9rem;
            font-weight: 700;
            letter-spacing: .5px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background .15s, opacity .15s;
            opacity: .4;
            pointer-events: none;
        }

        .btn-reset.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .btn-reset.enabled:hover {
            background: #c02020;
        }

        .btn-reset.enabled:active {
            transform: scale(.99);
        }

        /* Result / logs */
        .result-card {
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .result-success {
            background: rgba(18, 160, 92, .1);
            border: 1px solid rgba(18, 160, 92, .3);
        }

        .result-error {
            background: rgba(224, 52, 52, .1);
            border: 1px solid rgba(224, 52, 52, .3);
        }

        .result-header {
            padding: .875rem 1.25rem;
            font-weight: 700;
            font-size: .9rem;
        }

        .result-success .result-header {
            color: #4ade80;
        }

        .result-error .result-header {
            color: #f87171;
        }

        .log-list {
            padding: .875rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: .35rem;
        }

        .log-item {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
            font-size: .78rem;
            font-family: monospace;
        }

        .log-ok {
            color: #4ade80;
        }

        .log-fail {
            color: #f87171;
        }

        .log-icon {
            flex-shrink: 0;
        }

        /* New codes display */
        .codes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: .5rem;
            padding: .875rem 1.25rem;
        }

        .code-chip {
            background: rgba(59, 111, 240, .12);
            border: 1px solid rgba(59, 111, 240, .25);
            border-radius: 7px;
            padding: .5rem .75rem;
            font-family: monospace;
            font-size: .85rem;
            font-weight: 700;
            color: #8fb4ff;
            letter-spacing: 1.5px;
            text-align: center;
            cursor: pointer;
            transition: background .15s;
            user-select: all;
        }

        .code-chip:hover {
            background: rgba(59, 111, 240, .22);
        }

        /* Back link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            color: #5a6a88;
            font-size: .78rem;
            text-decoration: none;
            transition: color .15s;
        }

        .back-link:hover {
            color: #dde4f0;
        }

        .divider {
            border: none;
            border-top: 1px solid #1e2a45;
            margin: 1.25rem 0;
        }

        .warning-box {
            background: rgba(217, 119, 6, .08);
            border: 1px solid rgba(217, 119, 6, .25);
            border-radius: 8px;
            padding: .75rem 1rem;
            font-size: .78rem;
            color: #fbbf24;
            line-height: 1.65;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- Header -->
        <div class="header">
            <div class="danger-badge">⚠ Development Tool</div>
            <h1>Database Reset</h1>
            <p><?= htmlspecialchars(APP_NAME) ?> · <span style="font-family:monospace;font-size:.78rem;"><?= htmlspecialchars(DB_NAME) ?></span></p>
        </div>

        <!-- Result banner -->
        <?php if ($result === 'success'): ?>
            <div class="result-card result-success" style="margin-bottom:1rem;">
                <div class="result-header">✅ Reset Completed Successfully</div>
                <div class="log-list">
                    <?php foreach ($logs as [$type, $msg]): ?>
                        <div class="log-item <?= $type === 'ok' ? 'log-ok' : 'log-fail' ?>">
                            <span class="log-icon"><?= $type === 'ok' ? '✓' : '✗' ?></span>
                            <span><?= htmlspecialchars($msg) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($newCodes)): ?>
                    <hr style="border:none;border-top:1px solid rgba(18,160,92,.2);margin:0 1.25rem;">
                    <div style="padding:.75rem 1.25rem .5rem;font-size:.72rem;color:#4ade80;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">
                        🎟️ Fresh Registration Codes — Click to copy
                    </div>
                    <div class="codes-grid">
                        <?php foreach ($newCodes as $c): ?>
                            <div class="code-chip" onclick="copyCode(this, '<?= htmlspecialchars($c) ?>')" title="Click to copy">
                                <?= htmlspecialchars($c) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="padding:.5rem 1.25rem .875rem;font-size:.72rem;color:#5a6a88;">
                        Login: <strong style="color:#9ca8c0;">admin</strong> / <strong style="color:#9ca8c0;">Admin@1234</strong>
                    </div>
                <?php endif; ?>
            </div>

            <div style="text-align:center;margin-bottom:1.5rem;">
                <a href="<?= APP_URL ?>" class="back-link">← Back to site</a>
                &nbsp;&nbsp;
                <a href="reset.php" style="font-size:.78rem;color:#3b6ff0;text-decoration:none;">⟳ Reset again</a>
            </div>

        <?php elseif ($result === 'error'): ?>
            <div class="result-card result-error" style="margin-bottom:1rem;">
                <div class="result-header">✕ Reset Failed</div>
                <div class="log-list">
                    <?php foreach ($logs as [$type, $msg]): ?>
                        <div class="log-item log-fail">
                            <span class="log-icon">✗</span>
                            <span><?= htmlspecialchars($msg) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($result !== 'success'): ?>

            <?php if (!$dbOk): ?>
                <div class="result-card result-error">
                    <div class="result-header">✕ Database Connection Error</div>
                    <div class="log-list">
                        <div class="log-item log-fail"><span>✗</span><span><?= htmlspecialchars($error) ?></span></div>
                    </div>
                </div>
            <?php else: ?>

                <!-- Current State -->
                <div class="card">
                    <div class="card-header">📊 Current Database State</div>
                    <div class="card-body">
                        <div class="stats-row">
                            <div class="stat-box">
                                <div class="val" style="color:<?= $memberCount > 0 ? '#f87171' : '#4ade80' ?>;"><?= $memberCount ?></div>
                                <div class="lbl">Member Accounts</div>
                            </div>
                            <div class="stat-box">
                                <div class="val" style="color:#8fb4ff;"><?= $codeCount ?></div>
                                <div class="lbl">Unused Codes</div>
                            </div>
                        </div>
                        <div class="warning-box">
                            ⚠️ This tool is for <strong>development and testing only</strong>. It permanently deletes member data. Remove <code>reset.php</code> from the server before going live.
                        </div>
                    </div>
                </div>

                <!-- Reset Options Form -->
                <form method="POST" action="reset.php" id="resetForm">
                    <input type="hidden" name="action" value="reset">

                    <div class="card">
                        <div class="card-header">🗑️ What Will Be Cleared</div>
                        <div class="card-body">
                            <ul class="will-clear">
                                <li><span class="dot dot-red"></span><span class="text-red">All member accounts</span></li>
                                <li><span class="dot dot-red"></span><span class="text-red">All commissions &amp; earnings</span></li>
                                <li><span class="dot dot-red"></span><span class="text-red">All e-wallet ledger entries</span></li>
                                <li><span class="dot dot-red"></span><span class="text-red">All payout requests</span></li>
                                <li><span class="dot dot-red"></span><span class="text-red">All reactivation records</span></li>
                                <li><span class="dot dot-red"></span><span class="text-red">All DFI payout logs</span></li>
                                <li><span class="dot dot-red"></span><span class="text-red">All uploaded proof-of-payment images</span></li>
                                <li><span class="dot dot-red"></span><span class="text-red">All CD status records &amp; ledger entries</span></li>
                                <li><span class="dot dot-red"></span><span class="text-red">All registration codes</span></li>
                                <li><span class="dot dot-yellow"></span><span class="text-yellow">Admin balance &amp; counters reset to zero</span></li>
                                <li><span class="dot dot-green"></span><span class="text-green">Admin account &amp; password kept</span></li>
                                <li><span class="dot dot-green"></span><span class="text-green">System settings kept</span></li>
                            </ul>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">⚙️ Reset Options</div>
                        <div class="card-body">

                            <div class="form-group">
                                <label for="confirmInput" style="color: #f87171;">
                                    Type <strong>RESET</strong> to confirm
                                </label>
                                <input type="text" id="confirmInput" placeholder="RESET" autocomplete="off" spellcheck="false">
                            </div>

                            <hr class="divider">

                            <div class="form-group">
                                <label>Packages</label>
                                <label class="check-row">
                                    <input type="checkbox" name="keep_packages" checked id="keepPkg">
                                    <div>
                                        <div class="check-label">Keep existing packages</div>
                                        <div class="check-hint">Uncheck to delete all packages and re-seed the default Starter package</div>
                                    </div>
                                </label>
                            </div>

                            <div class="form-group">
                                <label for="code_pkg">Generate codes for package</label>
                                <select name="code_pkg" id="code_pkg">
                                    <?php foreach ($packages as $pkg): ?>
                                        <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['name']) ?> — entry ₱<?= number_format($pkg['entry_fee'], 2) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="code_qty">How many fresh codes to generate</label>
                                <input type="number" name="code_qty" id="code_qty" min="1" max="50" value="5">
                            </div>

                            <button type="submit" class="btn-reset" id="resetBtn" disabled>
                                🗑️ Reset Database
                            </button>

                        </div>
                    </div>

                </form>

            <?php endif; ?>
        <?php endif; ?>

        <div style="text-align:center;margin-top:1.5rem;">
            <a href="<?= APP_URL ?>" class="back-link">← Back to <?= htmlspecialchars(APP_NAME) ?></a>
        </div>

    </div><!-- .container -->

    <!-- Toast -->
    <div id="toast" style="display:none;position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:#1e2a45;border:1px solid #3b6ff0;color:#8fb4ff;font-size:.8rem;font-weight:600;padding:.5rem 1.25rem;border-radius:999px;font-family:monospace;letter-spacing:.5px;z-index:9999;"></div>

    <script>
        // ── Confirm input unlock ───────────────────────────────────────────────────
        const confirmInput = document.getElementById('confirmInput');
        const resetBtn = document.getElementById('resetBtn');

        if (confirmInput && resetBtn) {
            confirmInput.addEventListener('input', function() {
                const ok = this.value.trim().toUpperCase() === 'RESET';
                this.classList.toggle('ok', ok);
                resetBtn.classList.toggle('enabled', ok);
                resetBtn.disabled = !ok;
            });
        }

        // ── Packages toggle — update package select visibility ────────────────────
        const keepPkg = document.getElementById('keepPkg');
        const pkgSel = document.getElementById('code_pkg');
        if (keepPkg && pkgSel) {
            keepPkg.addEventListener('change', function() {
                pkgSel.disabled = !this.checked;
                if (!this.checked) pkgSel.style.opacity = '.4';
                else pkgSel.style.opacity = '1';
            });
        }

        // ── Copy code chip ────────────────────────────────────────────────────────
        function copyCode(el, code) {
            navigator.clipboard.writeText(code).then(() => {
                showToast('Copied: ' + code);
                el.style.background = 'rgba(18,160,92,.2)';
                el.style.borderColor = 'rgba(18,160,92,.4)';
                el.style.color = '#4ade80';
                setTimeout(() => {
                    el.style.background = '';
                    el.style.borderColor = '';
                    el.style.color = '';
                }, 1500);
            });
        }

        function showToast(msg) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.style.display = 'block';
            clearTimeout(t._timer);
            t._timer = setTimeout(() => {
                t.style.display = 'none';
            }, 2000);
        }

        // ── Prevent accidental double-submit ─────────────────────────────────────
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('resetBtn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = '⏳ Resetting…';
                btn.style.background = '#1e2a45';
                btn.style.color = '#6b7a99';
            }
        });
    </script>
</body>

</html>