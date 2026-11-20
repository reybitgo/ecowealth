<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — repeat-purchase release job (Phase 18, plan §17)
 *
 *  A paid order no longer credits the upline's wallet: it writes a
 *  `pending` award with a snapshotted hold window. This job is what
 *  puts money in the wallet, once per award, after delivery:
 *
 *      released when  status = 'pending'
 *                and  order is delivered/completed and not cancelled
 *                and  COALESCE(delivered_at, completed_at)
 *                       + hold_days <= NOW()
 *
 *  Phase 22H additionally releases the Product binary awards from the
 *  same due orders, each stream in its own transaction:
 *
 *      php bin/unilevel_release.php              # --stream=all : unilevel then binary
 *      php bin/unilevel_release.php --stream=unilevel   # pre-22 behaviour, exactly
 *      php bin/unilevel_release.php --stream=binary     # binary awards only
 *
 *  One implementation, three entry points:
 *
 *    CLI      php bin/unilevel_release.php [flags]      (Hostinger cron, SSH)
 *    Web      /system/cron/unilevel_release.php[?flags] (local testing, URL cron)
 *    Windows  bin\unilevel_release.cmd [flags]          (Task Scheduler)
 *
 *  Flags (CLI: --x=…, web: ?x=…):
 *    --stream=unilevel|binary|all   which escrow to release (default all)
 *    --limit=200   how many due orders one run may release
 *    --order=ID    diagnostic: try one order only (still respects the hold)
 *    --dry-run     list what would be released, change nothing
 *    --json        machine-readable output
 *    --doctor      environment/settings/health report
 *    --log-dir=<dir>  CLI only; default <storage>/logs
 *    --quiet       CLI only; no stdout (the log still gets a line)
 *    --help        usage
 *
 *  The release window is day-granular, so hourly is plenty; the job is
 *  cheap and idempotent (one transaction per order, order row locked
 *  first). `unilevel_release_enabled = 0` pauses the repeat-purchase
 *  stream without losing a pending award; `binary_release_enabled = 0`
 *  does the same for Product binary. Cancelling an order still cancels
 *  its pending awards in both streams.
 *
 *  Access (web): a loopback request from the same machine is trusted, so
 *  a local browser tab just works. Any other caller needs ?key= matching
 *  CRON_KEY in app/config.local.php. The CLI is always allowed.
 *
 *  Cron example (see docs/cron.md):
 *    20 * * * * /usr/bin/php /path/system/bin/unilevel_release.php --limit=200
 * ============================================================== */

$root = dirname(__DIR__);

require_once $root . '/app/config.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/autoload.php';
require_once $root . '/app/database.php';

date_default_timezone_set(APP_TIMEZONE);

$isCli = PHP_SAPI === 'cli';

/* --------------------------------------------------------------
 *  Options
 * -------------------------------------------------------------- */

/**
 * @return array{
 *   stream:string, limit:int, order:int, dry-run:bool, json:bool,
 *   doctor:bool, quiet:bool, help:bool, log-dir:?string
 * }
 */
function unilevel_release_options(bool $isCli): array
{
    $options = [
        'stream'  => 'all',
        'limit'   => 200,
        'order'   => 0,
        'dry-run' => false,
        'json'    => false,
        'doctor'  => false,
        'quiet'   => false,
        'help'    => false,
        'log-dir' => null,
    ];

    if ($isCli) {
        $parsed = getopt('', ['limit:', 'order:', 'dry-run', 'json', 'doctor', 'quiet', 'log-dir:', 'stream:', 'help']);

        if (!is_array($parsed)) {
            return $options;
        }

        if (isset($parsed['limit'])) {
            $options['limit'] = (int) $parsed['limit'];
        }

        if (isset($parsed['order'])) {
            $options['order'] = (int) $parsed['order'];
        }

        if (isset($parsed['stream']) && is_string($parsed['stream']) && trim($parsed['stream']) !== '') {
            $options['stream'] = trim($parsed['stream']);
        }

        foreach (['dry-run', 'json', 'doctor', 'quiet', 'help'] as $flag) {
            $options[$flag] = isset($parsed[$flag]);
        }

        if (isset($parsed['log-dir']) && is_string($parsed['log-dir']) && trim($parsed['log-dir']) !== '') {
            $options['log-dir'] = trim($parsed['log-dir']);
        }

        return $options;
    }

    foreach (['stream', 'dry-run', 'json', 'doctor', 'quiet', 'help'] as $flag) {
        $value = $_GET[$flag] ?? null;

        if ($value !== null
            && !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off', ''], true)) {
            $options[$flag] = $flag === 'stream' ? strtolower(trim((string) $value)) : true;
        }
    }

    if (isset($_GET['limit'])) {
        $options['limit'] = (int) $_GET['limit'];
    }

    if (isset($_GET['order'])) {
        $options['order'] = (int) $_GET['order'];
    }

    return $options;
}

function unilevel_release_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth release job — repeat-purchase and Product binary',
        '',
        'Usage: php bin/unilevel_release.php [flags]',
        '',
        '  --stream=unilevel|binary|all   which escrow to release (default all)',
        '  --limit=200       due orders per run (1-1000)',
        '  --order=ID        try one order only (still respects the hold)',
        '  --dry-run         list what would be released, change nothing',
        '  --json            machine-readable output',
        '  --doctor          environment / settings / health report',
        '  --log-dir=<dir>   where the monthly log is written',
        '  --quiet           no stdout (the log still records the run)',
        '  --help            this text',
        '',
        'Web: /system/cron/unilevel_release.php?dry-run=1&json=1&stream=binary',
    ]);
}

/* --------------------------------------------------------------
 *  Access, output and reporting helpers
 * -------------------------------------------------------------- */

function unilevel_release_deny_http(): never
{
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
    }

    echo 'Forbidden' . PHP_EOL;
    exit;
}

/** @param list<string> $lines @param array<string, mixed> $payload */
function unilevel_release_emit(bool $isCli, array $options, array $lines, array $payload): void
{
    if ($isCli && $options['quiet']) {
        return;
    }

    if ($options['json']) {
        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;

        return;
    }

    echo implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @return array<string, string>
 */
function unilevel_release_doctor(UnilevelService $service, CronLock $lock, CronLog $log, int $limit): array
{
    $dbNow    = 'unavailable';
    $dbTz     = 'unavailable';
    $doctor   = [];

    try {
        $dbNow  = (string) db()->query('SELECT NOW()')->fetchColumn();
        $dbTz   = (string) db()->query('SELECT @@session.time_zone')->fetchColumn();
        $doctor = $service->releaseStatus();
    } catch (Throwable $e) {
        error_log('[ecowealth] release doctor: ' . $e->getMessage());
    }

    $oldest = $doctor['oldest_pending'] ?? null;
    $age    = '—';

    if (is_string($oldest) && $oldest !== '') {
        $age = round((time() - (int) strtotime($oldest)) / 86400, 1) . ' days';
    }

    $lockState = $lock->status();
    $lockText  = $lockState['state'] . ' · ' . $lock->path();

    if ($lockState['state'] === 'unavailable' && $lockState['detail'] !== '') {
        $lockText .= ' — ' . $lockState['detail'];
    }

    return [
        'php'             => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'timezone'        => date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s P'),
        'database'        => DB_NAME . ' @ ' . DB_HOST,
        'db_now'          => $dbNow . ' (session ' . $dbTz . ')',
        'master'          => UnilevelService::enabled() ? 'on' : 'OFF — no new awards',
        'release_enabled' => UnilevelService::releaseEnabled()
            ? 'yes'
            : 'NO — pending stays pending until it is switched back on',
        'hold_days'       => (string) UnilevelService::holdDays(),
        'pending'         => ($doctor['pending_count'] ?? 0) . ' awards · '
            . money((float) ($doctor['pending_total'] ?? 0)),
        'oldest_pending'  => ($oldest === null ? 'none' : (string) $oldest . ' (' . $age . ')'),
        'due_now'         => ($doctor['due_orders'] ?? 0) . ' orders · '
            . ($doctor['due_awards'] ?? 0) . ' awards',
        'undelivered'     => (string) ($doctor['undelivered'] ?? 0)
            . ' pending award(s) waiting for delivery',
        'stuck_shipped'   => (string) ($doctor['stuck_shipped'] ?? 0)
            . ' pending award(s) on shipped orders older than 30 days',
        'cancelled_due'   => ($doctor['cancelled_due'] ?? 0) === 0
            ? '0 (good)'
            : (string) $doctor['cancelled_due'] . ' — MISMATCH, a cancelled order keeps a pending award',
        'limit'           => (string) $limit,
        'lock'            => $lockText,
        'log'             => $log->fileFor() . ($log->isWritable() ? ' (writable)' : ' (NOT writable)'),
        'cron_key'        => CronAccess::configuredKey() === null ? 'not set (loopback only)' : 'set',
        'storage'         => (string) STORAGE_PATH,
    ];
}

/**
 * The operational picture for the Product binary release doctor.
 *
 * @return array<string, string>
 */
function binary_release_doctor(BinaryService $service, CronLock $lock, CronLog $log, int $limit): array
{
    $dbNow    = 'unavailable';
    $dbTz     = 'unavailable';
    $doctor   = [];

    try {
        $dbNow  = (string) db()->query('SELECT NOW()')->fetchColumn();
        $dbTz   = (string) db()->query('SELECT @@session.time_zone')->fetchColumn();
        $doctor = $service->releaseStatus();
    } catch (Throwable $e) {
        error_log('[ecowealth] binary release doctor: ' . $e->getMessage());
    }

    $oldest = $doctor['oldest_pending'] ?? null;
    $age    = '—';

    if (is_string($oldest) && $oldest !== '') {
        $age = round((time() - (int) strtotime($oldest)) / 86400, 1) . ' days';
    }

    $lockState = $lock->status();
    $lockText  = $lockState['state'] . ' · ' . $lock->path();

    if ($lockState['state'] === 'unavailable' && $lockState['detail'] !== '') {
        $lockText .= ' — ' . $lockState['detail'];
    }

    return [
        'php'             => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'timezone'        => date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s P'),
        'database'        => DB_NAME . ' @ ' . DB_HOST,
        'db_now'          => $dbNow . ' (session ' . $dbTz . ')',
        'master'          => BinaryService::enabled() ? 'on' : 'OFF — no new awards',
        'release_enabled' => $doctor['release_enabled']
            ? 'yes'
            : 'NO — pending stays pending until it is switched back on',
        'hold_days'       => (string) $doctor['hold_days'],
        'pending'         => ($doctor['pending_count'] ?? 0) . ' awards · '
            . money((float) ($doctor['pending_total'] ?? 0)),
        'oldest_pending'  => ($oldest === null ? 'none' : (string) $oldest . ' (' . $age . ')'),
        'due_now'         => ($doctor['due_orders'] ?? 0) . ' orders · '
            . ($doctor['due_awards'] ?? 0) . ' awards',
        'undelivered'     => (string) ($doctor['undelivered'] ?? 0)
            . ' pending award(s) waiting for delivery',
        'limit'           => (string) $limit,
        'lock'            => $lockText,
        'log'             => $log->fileFor() . ($log->isWritable() ? ' (writable)' : ' (NOT writable)'),
        'storage'         => (string) STORAGE_PATH,
    ];
}

/** @param array<string, string> $report @return list<string> */
function unilevel_release_format_doctor(array $report): array
{
    $lines = ['EcoWealth repeat-purchase release — doctor'];
    $width = 0;

    foreach (array_keys($report) as $key) {
        $width = max($width, strlen($key));
    }

    foreach ($report as $key => $value) {
        $lines[] = '  ' . str_pad(str_replace('_', ' ', $key), $width) . '   ' . $value;
    }

    return $lines;
}

/** @param array<string, string> $report @return list<string> */
function binary_release_format_doctor(array $report): array
{
    $lines = ['EcoWealth product-binary release — doctor'];
    $width = 0;

    foreach (array_keys($report) as $key) {
        $width = max($width, strlen($key));
    }

    foreach ($report as $key => $value) {
        $lines[] = '  ' . str_pad(str_replace('_', ' ', $key), $width) . '   ' . $value;
    }

    return $lines;
}

/* --------------------------------------------------------------
 *  HTTP guard — run after config so CRON_KEY is available
 * -------------------------------------------------------------- */

$options = unilevel_release_options($isCli);
$trigger = $isCli ? 'cli' : 'http';

$stream = strtolower((string) $options['stream']);

if (!in_array($stream, ['unilevel', 'binary', 'all'], true)) {
    echo unilevel_release_usage() . PHP_EOL;
    exit(2);
}

$options['stream'] = $stream;

$runUnilevel = $stream !== 'binary';
$runBinary   = $stream !== 'unilevel';

if (!$isCli) {
    $allowed = CronAccess::allowed(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        isset($_GET['key']) ? (string) $_GET['key'] : null,
        CronAccess::configuredKey()
    );

    if (!$allowed) {
        unilevel_release_deny_http();
    }

    /* A cron response is data, never a page: no debug output, no caching,
     * no indexing, and the key never appears in the response. */
    ini_set('display_errors', '0');

    if (!headers_sent()) {
        header('Content-Type: ' . ($options['json'] ? 'application/json' : 'text/plain') . '; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
    }
}

$options['limit'] = max(1, min(1000, (int) $options['limit']));
$limit = (int) $options['limit'];
$order = max(0, (int) $options['order']);

$lock = new CronLock('unilevel_release');
$log  = new CronLog($isCli ? $options['log-dir'] : null, 'unilevel_release');

$started  = microtime(true);
$exitCode = 0;
$lines    = [];
$payload  = ['ok' => true, 'trigger' => $trigger];

/* --------------------------------------------------------------
 *  Run (no exit() inside the try, so the lock is always released)
 * -------------------------------------------------------------- */

try {
    $service  = $runUnilevel ? new UnilevelService() : null;
    $binary   = $runBinary   ? new BinaryService() : null;

    $uniPayload   = null;
    $binPayload   = null;
    $totalOrders  = 0;
    $totalAwards  = 0;
    $totalAmount  = 0.0;
    $totalSkipped = 0;
    $totalErrors  = 0;
    $totalCandidates = 0;
    $allIds       = [];

    if ($options['help']) {
        $lines   = [unilevel_release_usage()];
        $payload = ['ok' => true, 'usage' => unilevel_release_usage()];
    } elseif ($options['doctor']) {
        $lines = [];

        if ($runUnilevel) {
            $report  = unilevel_release_doctor($service, $lock, $log, $limit);
            $lines   = array_merge($lines, unilevel_release_format_doctor($report));
            $uniPayload = $report;
        }

        if ($runBinary) {
            $report  = binary_release_doctor($binary, $lock, $log, $limit);
            $lines   = array_merge($lines, binary_release_format_doctor($report));
            $binPayload = $report;
        }

        $payload = [
            'ok'      => true,
            'trigger' => $trigger,
            'stream'  => $stream,
            'unilevel' => $uniPayload,
            'binary'   => $binPayload,
        ];
    } elseif ($options['dry-run']) {
        $lines = [];

        if ($runUnilevel) {
            $doctor = $service->releaseStatus();
            $ids    = $service->dueOrderIds($limit);
            $rows   = $service->dueCandidates($limit);

            $uniLines = [
                'EcoWealth repeat-purchase release (dry run — nothing is changed)',
                '  database:  ' . DB_NAME . ' (' . DB_HOST . ')',
                '  enabled:   ' . (UnilevelService::releaseEnabled() ? 'yes' : 'NO — the real run would skip'),
                '  hold days: ' . UnilevelService::holdDays(),
                '  pending:   ' . $doctor['pending_count'] . ' award(s) · ' . money($doctor['pending_total']),
                '  candidates: ' . count($ids) . ($ids === [] ? '' : ' → orders ' . implode(', ', $ids)),
            ];

            foreach ($rows as $row) {
                $uniLines[] = sprintf(
                    '    order %s (#%d) → award #%d · %s · level %d · %s',
                    (string) ($row['order_no'] ?? ''),
                    (int) $row['order_id'],
                    (int) $row['id'],
                    (string) ($row['recipient_username'] ?? ''),
                    (int) $row['level'],
                    money((float) $row['amount'])
                );
            }

            $lines = array_merge($lines, $uniLines);

            $uniPayload = [
                'ok'            => true,
                'trigger'       => $trigger,
                'dry_run'       => true,
                'enabled'       => UnilevelService::releaseEnabled(),
                'hold_days'     => UnilevelService::holdDays(),
                'pending'       => $doctor['pending_count'],
                'pending_amount'=> (float) $doctor['pending_total'],
                'candidates'    => count($ids),
                'ids'           => $ids,
                'awards'        => array_map(static fn (array $row): int => (int) $row['id'], $rows),
            ];
        }

        if ($runBinary) {
            $doctor = $binary->releaseStatus();
            $ids    = $binary->dueOrderIds($limit);
            $rows   = $binary->dueCandidates($limit);

            $binLines = [
                'EcoWealth product-binary release (dry run — nothing is changed)',
                '  database:  ' . DB_NAME . ' (' . DB_HOST . ')',
                '  enabled:   ' . (BinaryService::enabled() ? 'yes' : 'NO — no new points'),
                '  hold days: ' . BinaryService::holdDays(),
                '  pending:   ' . $doctor['pending_count'] . ' award(s) · ' . money($doctor['pending_total']),
                '  candidates: ' . count($ids) . ($ids === [] ? '' : ' → orders ' . implode(', ', $ids)),
            ];

            foreach ($rows as $row) {
                $binLines[] = sprintf(
                    '    order %s (#%d) → award #%d · %s · %s · %s',
                    (string) ($row['order_no'] ?? ''),
                    (int) $row['order_id'],
                    (int) $row['id'],
                    (string) ($row['recipient_username'] ?? ''),
                    (string) ($row['kind'] ?? ''),
                    money((float) $row['amount'])
                );
            }

            $lines = array_merge($lines, $binLines);

            $binPayload = [
                'ok'            => true,
                'trigger'       => $trigger,
                'dry_run'       => true,
                'enabled'       => BinaryService::enabled(),
                'hold_days'     => BinaryService::holdDays(),
                'pending'       => $doctor['pending_count'],
                'pending_amount'=> (float) $doctor['pending_total'],
                'candidates'    => count($ids),
                'ids'           => $ids,
                'awards'        => array_map(static fn (array $row): int => (int) $row['id'], $rows),
            ];
        }

        $allIds = [];
        if ($uniPayload !== null) {
            $allIds = array_merge($allIds, $uniPayload['ids']);
        }
        if ($binPayload !== null) {
            $allIds = array_merge($allIds, $binPayload['ids']);
        }

        $payload = [
            'ok'         => true,
            'trigger'    => $trigger,
            'stream'     => $stream,
            'dry_run'    => true,
            'unilevel'   => $uniPayload,
            'binary'     => $binPayload,
            'enabled'    => [
                'unilevel' => $runUnilevel ? UnilevelService::releaseEnabled() : null,
                'binary'   => $runBinary   ? BinaryService::enabled() : null,
            ],
            'pending'    => (int) (($uniPayload['pending'] ?? 0) + ($binPayload['pending'] ?? 0)),
            'candidates' => (int) (($uniPayload['candidates'] ?? 0) + ($binPayload['candidates'] ?? 0)),
            'ids'        => $allIds,
            'awards'     => array_merge(
                $uniPayload['awards'] ?? [],
                $binPayload['awards'] ?? []
            ),
        ];

        $log->write('INFO', sprintf(
            'dry-run trigger=%s stream=%s limit=%d hold=%d pending=%d candidates=%d ids=%s',
            $trigger,
            $stream,
            $limit,
            ($runUnilevel ? UnilevelService::holdDays() : BinaryService::holdDays()),
            $payload['pending'],
            $payload['candidates'],
            implode(',', $allIds)
        ));
    } elseif (!$lock->acquire()) {
        $lines = [
            'EcoWealth release job',
            '  skipped: another run is already in progress.',
        ];

        $payload = ['ok' => true, 'trigger' => $trigger, 'stream' => $stream, 'skipped' => 'locked'];

        $log->write('WARN', 'skipped: another run holds ' . $lock->path());
    } else {
        if ($runUnilevel) {
            if (!UnilevelService::releaseEnabled()) {
                $doctor = $service->releaseStatus();

                $lines = array_merge($lines, [
                    'EcoWealth repeat-purchase release',
                    '  skipped: releases are paused in the admin settings.',
                    '  pending:  ' . $doctor['pending_count'] . ' award(s) · ' . money($doctor['pending_total'])
                        . ' — they stay pending.',
                ]);

                $uniPayload = [
                    'ok'             => false,
                    'trigger'        => $trigger,
                    'skipped'        => 'disabled',
                    'released_orders' => 0,
                    'released_awards' => 0,
                    'amount'         => 0.0,
                    'ids'            => [],
                    'pending'        => (int) $doctor['pending_count'],
                    'pending_amount' => (float) $doctor['pending_total'],
                ];

                $log->write('WARN', 'skipped: disabled by settings (unilevel_release_enabled=0)');
            } elseif ($order > 0) {
                /* Targeted diagnostic: one order, normal due rule, no force. */
                $result  = $service->releaseForOrder($order);
                $reason  = (string) ($result['reason'] ?? '');
                $uniReleased = $result['released'] > 0;

                $totalOrders  += $uniReleased ? 1 : 0;
                $totalAwards  += $result['released'];
                $totalAmount   = round($totalAmount + (float) $result['amount'], 2);
                $totalSkipped += ($result['skipped'] ?? 0);
                $allIds       = $uniReleased ? [$order] : [];

                $lines = array_merge($lines, [
                    'EcoWealth repeat-purchase release — one order',
                    '  order:    #' . $order,
                    '  released: ' . $result['released'] . ' award(s) · ' . money($result['amount']),
                    '  skipped:  ' . ($result['skipped'] ?? 0),
                    '  reason:   ' . ($reason === '' ? '—' : $reason),
                ]);

                $uniPayload = [
                    'ok'              => true,
                    'trigger'         => $trigger,
                    'order'           => $order,
                    'released_orders' => $uniReleased ? 1 : 0,
                    'released_awards' => $result['released'],
                    'amount'          => $result['amount'],
                    'skipped'         => ($result['skipped'] ?? 0),
                    'reason'          => $reason,
                    'awards'          => $result['awards'],
                    'ids'             => $uniReleased ? [$order] : [],
                ];

                $log->write($result['released'] > 0 ? 'OK' : 'INFO', sprintf(
                    'stream=unilevel order=%d trigger=%s released=%d amount=%s skipped=%d reason=%s',
                    $order,
                    $trigger,
                    $result['released'],
                    number_format((float) $result['amount'], 2, '.', ''),
                    ($result['skipped'] ?? 0),
                    $reason === '' ? '-' : $reason
                ));
            } else {
                $result   = $service->releaseDue($limit);
                $uniIds   = $result['ids'];
                $uniReleased = $result['released_orders'] > 0;

                $totalOrders  += $result['released_orders'];
                $totalAwards  += $result['released_awards'];
                $totalAmount   = round($totalAmount + $result['amount'], 2);
                $totalSkipped += ($result['skipped'] ?? 0);
                $totalErrors  += $result['errors'];
                $totalCandidates += $result['candidates'];
                $allIds       = array_merge($allIds, $uniIds);

                $duration = (int) round((microtime(true) - $started) * 1000);

                $lines = array_merge($lines, [
                    'EcoWealth repeat-purchase release',
                    '  database:   ' . DB_NAME . ' (' . DB_HOST . ')',
                    '  trigger:    ' . $trigger,
                    '  hold days:  ' . UnilevelService::holdDays(),
                    '  candidates: ' . (int) $result['candidates'],
                    '  released:   ' . $result['released_orders'] . ' order(s) · '
                        . $result['released_awards'] . ' award(s) · ' . money($result['amount']),
                    '  skipped:    ' . ($result['skipped'] ?? 0),
                    '  errors:     ' . $result['errors'],
                    '  ids:        ' . ($uniIds === [] ? '—' : implode(', ', $uniIds)),
                    '  duration:   ' . $duration . 'ms',
                    '  log:        ' . $log->fileFor(),
                ]);

                $uniPayload = [
                    'ok'              => $result['errors'] === 0,
                    'trigger'         => $trigger,
                    'hold_days'       => UnilevelService::holdDays(),
                    'candidates'      => (int) $result['candidates'],
                    'released_orders' => $result['released_orders'],
                    'released_awards' => $result['released_awards'],
                    'amount'          => $result['amount'],
                    'skipped'         => ($result['skipped'] ?? 0),
                    'errors'          => $result['errors'],
                    'ids'             => $uniIds,
                    'duration_ms'     => $duration,
                    'log'             => $log->fileFor(),
                ];

                $log->write(
                    $result['errors'] > 0 ? 'ERROR' : ($result['released_awards'] > 0 ? 'OK' : 'INFO'),
                    sprintf(
                        'stream=unilevel trigger=%s limit=%d hold=%d candidates=%d released_orders=%d released_awards=%d amount=%s skipped=%d errors=%d duration=%dms ids=%s',
                        $trigger,
                        $limit,
                        UnilevelService::holdDays(),
                        (int) $result['candidates'],
                        $result['released_orders'],
                        $result['released_awards'],
                        number_format((float) $result['amount'], 2, '.', ''),
                        ($result['skipped'] ?? 0),
                        $result['errors'],
                        $duration,
                        implode(',', $uniIds)
                    )
                );
            }
        }

        if ($runBinary) {
            $binaryReleaseEnabled = setting_bool('binary_release_enabled', true);

            if (!$binaryReleaseEnabled) {
                $doctor = $binary->releaseStatus();

                $lines = array_merge($lines, [
                    'EcoWealth product-binary release',
                    '  skipped: releases are paused in the admin settings.',
                    '  pending:  ' . $doctor['pending_count'] . ' award(s) · ' . money($doctor['pending_total'])
                        . ' — they stay pending.',
                ]);

                $binPayload = [
                    'ok'             => false,
                    'trigger'        => $trigger,
                    'skipped'        => 'disabled',
                    'released_orders' => 0,
                    'released_awards' => 0,
                    'amount'         => 0.0,
                    'ids'            => [],
                    'pending'        => (int) $doctor['pending_count'],
                    'pending_amount' => (float) $doctor['pending_total'],
                ];

                /* No log line: a disabled stream with no work is silent,
                 * like the unilevel stream's own disabled path. */
            } elseif ($order > 0) {
                $result  = $binary->releaseForOrder($order);
                $reason  = (string) ($result['reason'] ?? '');
                $binReleased = $result['released'] > 0;

                $totalOrders  += $binReleased ? 1 : 0;
                $totalAwards  += $result['released'];
                $totalAmount   = round($totalAmount + (float) $result['amount'], 2);
                $totalSkipped += ($result['skipped'] ?? 0);
                $allIds       = array_merge($allIds, $binReleased ? [$order] : []);

                $lines = array_merge($lines, [
                    'EcoWealth product-binary release — one order',
                    '  order:    #' . $order,
                    '  released: ' . $result['released'] . ' award(s) · ' . money($result['amount']),
                    '  skipped:  ' . ($result['skipped'] ?? 0),
                    '  reason:   ' . ($reason === '' ? '—' : $reason),
                ]);

                $binPayload = [
                    'ok'              => true,
                    'trigger'         => $trigger,
                    'order'           => $order,
                    'released_orders' => $binReleased ? 1 : 0,
                    'released_awards' => $result['released'],
                    'amount'          => $result['amount'],
                    'skipped'         => ($result['skipped'] ?? 0),
                    'reason'          => $reason,
                    'awards'          => $result['awards'],
                    'ids'             => $binReleased ? [$order] : [],
                ];

                $log->write($result['released'] > 0 ? 'OK' : 'INFO', sprintf(
                    'stream=binary order=%d trigger=%s released=%d amount=%s skipped=%d reason=%s',
                    $order,
                    $trigger,
                    $result['released'],
                    number_format((float) $result['amount'], 2, '.', ''),
                    ($result['skipped'] ?? 0),
                    $reason === '' ? '-' : $reason
                ));
            } else {
                $result   = $binary->releaseDue($limit);
                $binIds   = $result['ids'];
                $binReleased = $result['released_orders'] > 0;

                $totalOrders  += $result['released_orders'];
                $totalAwards  += $result['released_awards'];
                $totalAmount   = round($totalAmount + $result['amount'], 2);
                $totalSkipped += ($result['skipped'] ?? 0);
                $totalErrors  += $result['errors'];
                $totalCandidates += $result['candidates'];
                $allIds       = array_merge($allIds, $binIds);

                $duration = (int) round((microtime(true) - $started) * 1000);

                $lines = array_merge($lines, [
                    'EcoWealth product-binary release',
                    '  database:   ' . DB_NAME . ' (' . DB_HOST . ')',
                    '  trigger:    ' . $trigger,
                    '  hold days:  ' . BinaryService::holdDays(),
                    '  candidates: ' . (int) $result['candidates'],
                    '  released:   ' . $result['released_orders'] . ' order(s) · '
                        . $result['released_awards'] . ' award(s) · ' . money($result['amount']),
                    '  skipped:    ' . ($result['skipped'] ?? 0),
                    '  errors:     ' . $result['errors'],
                    '  ids:        ' . ($binIds === [] ? '—' : implode(', ', $binIds)),
                    '  duration:   ' . $duration . 'ms',
                    '  log:        ' . $log->fileFor(),
                ]);

                if ($result['candidates'] > 0 || $result['released_awards'] > 0) {
                    $log->write(
                        $result['errors'] > 0 ? 'ERROR' : ($result['released_awards'] > 0 ? 'OK' : 'INFO'),
                        sprintf(
                            'stream=binary trigger=%s limit=%d hold=%d candidates=%d released_orders=%d released_awards=%d amount=%s skipped=%d errors=%d duration=%dms ids=%s',
                            $trigger,
                            $limit,
                            BinaryService::holdDays(),
                            (int) $result['candidates'],
                            $result['released_orders'],
                            $result['released_awards'],
                            number_format((float) $result['amount'], 2, '.', ''),
                            ($result['skipped'] ?? 0),
                            $result['errors'],
                            $duration,
                            implode(',', $binIds)
                        )
                    );
                }

                $binPayload = [
                    'ok'              => $result['errors'] === 0,
                    'trigger'         => $trigger,
                    'hold_days'       => BinaryService::holdDays(),
                    'candidates'      => (int) $result['candidates'],
                    'released_orders' => $result['released_orders'],
                    'released_awards' => $result['released_awards'],
                    'amount'          => $result['amount'],
                    'skipped'         => ($result['skipped'] ?? 0),
                    'errors'          => $result['errors'],
                    'ids'             => $binIds,
                    'duration_ms'     => $duration,
                    'log'             => $log->fileFor(),
                ];
            }
        }

        $exitCode = $totalErrors > 0 ? 1 : 0;

        /* A run that did nothing because a stream was paused reports ok = false,
         * matching the pre-22 disabled-run payload; anything else is ok. */
        $anyDisabled = ($uniPayload !== null && isset($uniPayload['skipped']) && $uniPayload['skipped'] === 'disabled')
            || ($binPayload !== null && isset($binPayload['skipped']) && $binPayload['skipped'] === 'disabled');
        $ok = $totalErrors === 0 && ($totalAwards > 0 || !$anyDisabled);

        if ($stream === 'unilevel') {
            /* --stream=unilevel reproduces the pre-22 payload exactly. */
            if ($uniPayload !== null && isset($uniPayload['skipped']) && $uniPayload['skipped'] === 'disabled') {
                $payload = [
                    'ok'             => false,
                    'trigger'        => $trigger,
                    'skipped'        => 'disabled',
                    'released_orders' => 0,
                    'released_awards' => 0,
                    'amount'         => 0.0,
                    'ids'            => [],
                    'pending'        => $uniPayload['pending'],
                    'pending_amount' => $uniPayload['pending_amount'],
                ];
            } elseif ($uniPayload !== null && isset($uniPayload['order'])) {
                $payload = [
                    'ok'              => true,
                    'trigger'         => $trigger,
                    'order'           => $order,
                    'released_orders' => $uniPayload['released_orders'],
                    'released_awards' => $uniPayload['released_awards'],
                    'amount'          => $uniPayload['amount'],
                    'skipped'         => $uniPayload['skipped'],
                    'reason'          => $uniPayload['reason'],
                    'awards'          => $uniPayload['awards'],
                    'ids'             => $uniPayload['ids'],
                ];
            } else {
                $payload = [
                    'ok'              => $uniPayload['ok'] ?? true,
                    'trigger'         => $trigger,
                    'hold_days'       => $uniPayload['hold_days'],
                    'candidates'      => $uniPayload['candidates'],
                    'released_orders' => $uniPayload['released_orders'],
                    'released_awards' => $uniPayload['released_awards'],
                    'amount'          => $uniPayload['amount'],
                    'skipped'         => $uniPayload['skipped'],
                    'errors'          => $uniPayload['errors'],
                    'ids'             => $uniPayload['ids'],
                    'duration_ms'     => $uniPayload['duration_ms'],
                    'log'             => $uniPayload['log'],
                ];
            }
        } else {
            $payload = [
                'ok'              => $ok,
                'trigger'         => $trigger,
                'stream'          => $stream,
                'unilevel'        => $uniPayload,
                'binary'          => $binPayload,
                'released_orders' => $totalOrders,
                'released_awards' => $totalAwards,
                'amount'          => $totalAmount,
                'skipped'         => $anyDisabled ? 'disabled' : $totalSkipped,
                'pending'         => (int) ((($uniPayload['pending'] ?? 0) + ($binPayload['pending'] ?? 0))),
                'candidates'      => $totalCandidates,
                'errors'          => $totalErrors,
                'ids'             => $allIds,
                'duration_ms'     => $uniPayload['duration_ms'] ?? $binPayload['duration_ms'] ?? (int) round((microtime(true) - $started) * 1000),
                'log'             => $log->fileFor(),
            ];

            if ($order > 0) {
                $reasons = [];

                if ($uniPayload !== null && isset($uniPayload['reason']) && $uniPayload['reason'] !== '') {
                    $reasons[] = $uniPayload['reason'];
                }

                if ($binPayload !== null && isset($binPayload['reason']) && $binPayload['reason'] !== '') {
                    $reasons[] = $binPayload['reason'];
                }

                $payload['order'] = $order;
                $payload['reason'] = implode(' / ', array_unique($reasons));
            }
        }
    }
} catch (Throwable $e) {
    $exitCode = 1;
    $log->write('ERROR', 'run failed: ' . $e->getMessage());

    $lines = [
        'Repeat-purchase release FAILED',
        '  The job could not run. See ' . $log->fileFor() . ' for the error.',
    ];

    if ($isCli) {
        fwrite(STDERR, 'Repeat-purchase release failed: ' . $e->getMessage() . PHP_EOL);
    }

    $payload = ['ok' => false, 'trigger' => $trigger, 'stream' => $stream, 'error' => 'The release job failed. See the log.'];
} finally {
    $lock->release();
}

unilevel_release_emit($isCli, $options, $lines, $payload);

exit($exitCode);
