#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — wallet reconciliation command (plan §7 Phase 5)
 *
 *      php bin/wallet_reconcile.php [--json]
 *
 *  Recomputes both wallet buckets from `ewallet_ledger` and reports
 *  every mismatch. Read-only: it never repairs anything, because a
 *  mismatch means a balance was written outside WalletService and
 *  needs a human looking at it.
 *
 *  Exit codes:  0 = clean, 1 = mismatches or invariant violations,
 *               2 = the command could not run.
 * ============================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$root = dirname(__DIR__);

require_once $root . '/app/config.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/autoload.php';
require_once $root . '/app/database.php';

date_default_timezone_set(APP_TIMEZONE);

$options = getopt('', ['json', 'help']);

if (isset($options['help'])) {
    echo "Usage: php bin/wallet_reconcile.php [--json]\n\n";
    echo "Recomputes ewallet_balance / withdrawable_balance from the ledger and\n";
    echo "reports mismatches. Read-only. Exit 0 when clean, 1 when not.\n";
    exit(0);
}

try {
    $report = (new WalletService())->reconcile();
} catch (Throwable $e) {
    fwrite(STDERR, 'Wallet reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$format = static fn (mixed $value): string => $value === null
    ? '—'
    : number_format((float) $value, 2, '.', ',');

if (isset($options['json'])) {
    echo json_encode(
        ['database' => DB_NAME] + $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;

    exit($report['ok'] ? 0 : 1);
}

echo 'EcoWealth wallet reconciliation', PHP_EOL;
printf("  database:          %s (%s)%s", DB_NAME, DB_HOST, PHP_EOL);
printf("  accounts checked:  %d%s", $report['checked'], PHP_EOL);
printf("  accounts with ledger: %d%s", $report['ledger_users'], PHP_EOL);
echo PHP_EOL;

if ($report['ok']) {
    echo 'OK — every wallet matches its ledger on both buckets.', PHP_EOL;
    exit(0);
}

$problems = count($report['mismatches']) + count($report['violations']);
printf("FAILED — %d problem(s) found.%s", $problems, PHP_EOL);

foreach ($report['mismatches'] as $mismatch) {
    $parts = [];

    if (($mismatch['expected'] ?? null) !== null || ($mismatch['actual'] ?? null) !== null) {
        $parts[] = 'total ledger ' . $format($mismatch['expected'] ?? null)
            . ' vs wallet ' . $format($mismatch['actual'] ?? null);
    }

    if (($mismatch['expected_withdrawable'] ?? null) !== null || ($mismatch['actual_withdrawable'] ?? null) !== null) {
        $parts[] = 'withdrawable ledger ' . $format($mismatch['expected_withdrawable'] ?? null)
            . ' vs wallet ' . $format($mismatch['actual_withdrawable'] ?? null);
    }

    printf(
        "  - user #%d @%s [%s]: %s%s",
        (int) $mismatch['user_id'],
        (string) $mismatch['username'],
        (string) $mismatch['kind'],
        implode('; ', $parts),
        PHP_EOL
    );
}

foreach ($report['violations'] as $violation) {
    printf(
        "  - user #%d @%s [invariant]: %s (total %s / withdrawable %s)%s",
        (int) $violation['user_id'],
        (string) $violation['username'],
        (string) $violation['reason'],
        $format($violation['balance']),
        $format($violation['withdrawable']),
        PHP_EOL
    );
}

echo PHP_EOL;
echo 'No automatic repair was attempted. Inspect these accounts before touching money.', PHP_EOL;
exit(1);
