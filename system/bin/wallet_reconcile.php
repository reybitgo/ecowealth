#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — finance reconciliation command (plan §6.8, §7 Phase 5,
 *  10, 12 and 13)
 *
 *      php bin/wallet_reconcile.php [--json]
 *
 *  Five read-only reports:
 *
 *    1. Wallets — both buckets recomputed from `ewallet_ledger`.
 *    2. Commissions — every credited award against its wallet movement
 *       (plan §6.8: SUM(credited) = SUM(ledger ref_type='commission'),
 *       one ledger row per award, no orphan ledger rows).
 *    3. Transfers — every transfer row against its three ledger legs
 *       (sender debit of amount + fee, recipient credit of amount, fee
 *       credit to the primary admin) plus orphan transfer ledger rows.
 *    4. Payouts — every withdrawal request against its hold (one debit
 *       at request time) and, for a rejected request, its refund (one
 *       credit), plus orphan payout/refund ledger rows.
 *    5. Repeat-purchase — every credited/voided award against its
 *       `unilevel` credit, every reversal against `unilevel_reversal`,
 *       the order headline totals and the idempotency stamp
 *       (plan §14.11).
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
    $payouts     = (new PayoutService())->reconcile();
    $unilevel    = (new UnilevelService())->reconcile();
} catch (Throwable $e) {
    fwrite(STDERR, 'Reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$ok = $wallets['ok'] && $commissions['ok'] && $transfers['ok'] && $payouts['ok'] && $unilevel['ok'];

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
            'payouts'     => $payouts,
            'unilevel'    => $unilevel,
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

echo 'Payouts', PHP_EOL;
printf("  requests checked:  %d%s", $payouts['checked'], PHP_EOL);
printf("  held by open requests: %s%s", $format($payouts['held']), PHP_EOL);

if (($payouts['orphans'] ?? 0) > 0) {
    printf(
        "  orphan legs:       %d — a payout row is gone but its ledger legs remain (an account was deleted)%s",
        $payouts['orphans'],
        PHP_EOL
    );
}

if ($payouts['ok']) {
    echo '  OK — every request has its hold, and every rejection its refund.', PHP_EOL;
} else {
    printf("  %d problem(s):%s", count($payouts['mismatches']), PHP_EOL);

    foreach ($payouts['mismatches'] as $mismatch) {
        printf(
            "  - [%s] payout #%d: %s%s",
            (string) $mismatch['kind'],
            (int) $mismatch['payout_id'],
            (string) $mismatch['message'],
            PHP_EOL
        );
    }
}

echo PHP_EOL;

echo 'Repeat-purchase', PHP_EOL;
printf(
    "  credited awards:   %d (%s)%s",
    $unilevel['credited_rows'],
    $format($unilevel['credited_total']),
    PHP_EOL
);
printf("  skipped awards:    %d%s", $unilevel['skipped_rows'], PHP_EOL);
printf("  voided awards:     %d%s", $unilevel['voided_rows'], PHP_EOL);
printf("  unilevel ledger:   %s%s", $format($unilevel['ledger_total']), PHP_EOL);
printf("  reversals:         %s%s", $format($unilevel['reversal_total']), PHP_EOL);

if (($unilevel['legacy_rows'] ?? 0) > 0) {
    printf(
        "  legacy movements:  %d (%s) — repeat-purchase movements before the award history began%s",
        $unilevel['legacy_rows'],
        $format($unilevel['legacy_total']),
        PHP_EOL
    );
}

if ($unilevel['ok']) {
    echo '  OK — every award has its wallet movement, every reversal its debit, and the order totals agree.', PHP_EOL;
} else {
    printf("  %d problem(s):%s", count($unilevel['mismatches']), PHP_EOL);

    foreach ($unilevel['mismatches'] as $mismatch) {
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

if ($ok) {
    echo 'All good — wallets, commissions, transfers, payouts and repeat-purchase awards reconcile.', PHP_EOL;
    exit(0);
}

echo 'No automatic repair was attempted. Inspect these records before touching money.', PHP_EOL;
exit(1);
