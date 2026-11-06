#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — shop order expiry command (plan §5.4, §7 Phase 7)
 *
 *      php bin/shop_expire.php [--limit=200] [--dry-run] [--json]
 *
 *  Cancels `pending` and `payment_failed` orders whose applicable
 *  deadline has passed and releases their reserved stock. A proof
 *  already under review (`payment_review`) is never expired: a member
 *  who paid and submitted evidence keeps the order open for the admin.
 *
 *  Idempotent — a second run in the same minute finds nothing to do.
 *  Each order moves through one transaction with its row locked, so a
 *  proof uploaded at the same moment wins and the timer skips that
 *  order.
 *
 *  Cron (every 15 minutes — the shortest window it acts on is hours):
 *
 *      15,45 * * * * /usr/bin/php /path/to/system/bin/shop_expire.php >> /var/log/ecowealth/shop_expire.log 2>&1
 *
 *  Exit codes: 0 = sweep completed, 1 = the sweep could not run.
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

$options = getopt('', ['limit:', 'dry-run', 'json', 'help']);

if (isset($options['help'])) {
    echo "Usage: php bin/shop_expire.php [--limit=200] [--dry-run] [--json]\n\n";
    echo "Cancels unpaid orders past their payment (or correction) deadline and\n";
    echo "releases their reserved stock. Orders in payment review are exempt.\n";
    echo "Exit 0 when the sweep completes, 1 when it cannot run.\n";
    exit(0);
}

$limit  = isset($options['limit']) ? (int) $options['limit'] : 200;
$dryRun = isset($options['dry-run']);
$json   = isset($options['json']);

try {
    $service = new PaymentService();

    if ($dryRun) {
        $ids    = $service->overdueOrderIds($limit);
        $result = ['expired' => 0, 'ids' => $ids];
    } else {
        $result = $service->expireOverdue($limit);
    }
} catch (Throwable $e) {
    if ($json) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]) . PHP_EOL;
    } else {
        fwrite(STDERR, 'Shop expiry failed: ' . $e->getMessage() . PHP_EOL);
    }

    exit(1);
}

if ($json) {
    echo json_encode([
        'ok'      => true,
        'database' => DB_NAME,
        'dry_run' => $dryRun,
        'expired' => $result['expired'],
        'ids'     => $result['ids'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit(0);
}

printf(
    "EcoWealth shop expiry%s%s",
    $dryRun ? ' (dry run — nothing is changed)' : '',
    PHP_EOL
);
printf("  database: %s (%s)%s", DB_NAME, DB_HOST, PHP_EOL);

if ($result['ids'] === []) {
    echo 'No order is past its payment window.', PHP_EOL;
    exit(0);
}

if ($dryRun) {
    printf(
        "%d order(s) would be cancelled: %s%s",
        count($result['ids']),
        implode(', ', $result['ids']),
        PHP_EOL
    );
    exit(0);
}

printf(
    "%d order(s) cancelled and their stock released: %s%s",
    $result['expired'],
    implode(', ', $result['ids']),
    PHP_EOL
);
exit(0);
