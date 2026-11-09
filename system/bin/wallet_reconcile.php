#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — finance reconciliation command (plan §6.8, §7 Phase 5,
 *  10 and 12)
 *
 *      php bin/wallet_reconcile.php [--json]
 *
 *  Three read-only reports:
 *
 *    1. Wallets — both buckets recomputed from `ewallet_ledger`.
 *    2. Commissions — every credited award against its wallet movement
 *       (plan §6.8: SUM(credited) = SUM(ledger ref_type='commission'),
 *       one ledger row per award, no orphan ledger rows).
 *    3. Transfers — every transfer row against its three ledger legs
 *       (sender debit of amount + fee, recipient credit of amount, fee
 *       credit to the primary admin) plus orphan transfer ledger rows.
 *
 *  Nothing is repaired: a mismatch means a balance or an award was
 *  written outside its service and needs a human looking at it.
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
    echo "checks every commission award against its wallet movement. Read-only.\n";
    echo "Exit 0 when clean, 1 when not.\n";
    exit(0);
}

try {
    $wallets     = (new WalletService())->reconcile();
    $commissions = (new CommissionService())->reconcile();
    $transfers   = (new TransferService())->reconcile();
} catch (Throwable $e) {
    fwrite(STDERR, 'Reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$ok = $wallets['ok'] && $commissions['ok'] && $transfers['ok'];

$format = static fn (mixed $value): string => $value === null
    ? '—'
    : number_format((float) $value, 2, '.', ',');

if (isset($options['json'])) {
    echo json_encode(
        [
            'database'    => DB_NAME,
            'ok'          => $ok,
        ] + $wallets + [
            'commissions' => $commissions,
            'transfers'   => $transfers,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;

    exit($ok ? 0 : 1);
}

echo 'EcoWealth finance reconciliation', PHP_EOL;
printf("  database:          %s (%s)%s", DB_NAME, DB_HOST, PHP_EOL);
printf("  accounts checked:  %d%s", $wallets['checked'], PHP_EOL);
printf("  accounts with ledger: %d%s", $wallets['ledger_users'], PHP_EOL);
echo PHP_EOL;

echo 'Wallets', PHP_EOL;

if ($wallets['ok']) {
    echo '  OK — every wallet matches its ledger on both buckets.', PHP_EOL;
} else {
    printf("  %d problem(s):%s", count($wallets['mismatches']) + count($wallets['violations']), PHP_EOL);

    foreach ($wallets['mismatches'] as $mismatch) {
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

    foreach ($wallets['violations'] as $violation) {
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
}

echo PHP_EOL;
echo 'Commissions', PHP_EOL;
printf(
    "  credited awards:   %d (%s)%s",
    $commissions['credited_rows'],
    $format($commissions['credited_total']),
    PHP_EOL
);
printf("  skipped awards:    %d%s", $commissions['skipped_rows'], PHP_EOL);
printf("  ledger total:      %s%s", $format($commissions['ledger_total']), PHP_EOL);

if (($commissions['legacy_rows'] ?? 0) > 0) {
    printf(
        "  legacy credits:    %d (%s) — commission movements before the award history began%s",
        $commissions['legacy_rows'],
        $format($commissions['legacy_total']),
        PHP_EOL
    );
}

if ($commissions['ok']) {
    echo '  OK — every credited award has its wallet movement and the totals agree.', PHP_EOL;
} else {
    printf("  %d problem(s):%s", count($commissions['mismatches']), PHP_EOL);

    foreach ($commissions['mismatches'] as $mismatch) {
        printf(
            "  - [%s] %s (expected %s, actual %s)%s",
            (string) $mismatch['kind'],
            (string) $mismatch['message'],
            $format($mismatch['expected'] ?? null),
            $format($mismatch['actual'] ?? null),
            PHP_EOL
        );
    }
}

echo PHP_EOL;

echo 'Transfers', PHP_EOL;
printf("  transfers checked: %d%s", $transfers['checked'], PHP_EOL);

if (($transfers['orphans'] ?? 0) > 0) {
    printf(
        "  orphan legs:       %d — a transfer row is gone but its ledger legs remain (an account was deleted)%s",
        $transfers['orphans'],
        PHP_EOL
    );
}

if ($transfers['ok']) {
    echo '  OK — every transfer has its sender debit, recipient credit and fee credit.', PHP_EOL;
} else {
    printf("  %d problem(s):%s", count($transfers['mismatches']), PHP_EOL);

    foreach ($transfers['mismatches'] as $mismatch) {
        printf(
            "  - [%s] transfer #%d: %s%s",
            (string) $mismatch['kind'],
            (int) $mismatch['transfer_id'],
            (string) $mismatch['message'],
            PHP_EOL
        );
    }
}

echo PHP_EOL;

if ($ok) {
    echo 'All good — wallets, commissions and transfers reconcile.', PHP_EOL;
    exit(0);
}

echo 'No automatic repair was attempted. Inspect these records before touching money.', PHP_EOL;
exit(1);
