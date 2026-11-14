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
 *  One implementation, three entry points:
 *
 *    CLI      php bin/unilevel_release.php [flags]      (Hostinger cron, SSH)
 *    Web      /system/cron/unilevel_release.php[?flags] (local testing, URL cron)
 *    Windows  bin\unilevel_release.cmd [flags]          (Task Scheduler)
 *
 *  Flags (CLI: --x=…, web: ?x=…):
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
 *  first). `unilevel_release_enabled = 0` pauses it without losing a
 *  pending award; cancelling an order still cancels its pending awards.
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
 *   limit:int, order:int, dry-run:bool, json:bool, doctor:bool,
 *   quiet:bool, help:bool, log-dir:?string
 * }
 */
function unilevel_release_options(bool $isCli): array
{
    $options = [
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
        $parsed = getopt('', ['limit:', 'order:', 'dry-run', 'json', 'doctor', 'quiet', 'log-dir:', 'help']);

        if (!is_array($parsed)) {
            return $options;
        }

        if (isset($parsed['limit'])) {
            $options['limit'] = (int) $parsed['limit'];
        }

        if (isset($parsed['order'])) {
            $options['order'] = (int) $parsed['order'];
        }

        foreach (['dry-run', 'json', 'doctor', 'quiet', 'help'] as $flag) {
            $options[$flag] = isset($parsed[$flag]);
        }

        if (isset($parsed['log-dir']) && is_string($parsed['log-dir']) && trim($parsed['log-dir']) !== '') {
            $options['log-dir'] = trim($parsed['log-dir']);
        }

        return $options;
    }

    foreach (['dry-run', 'json', 'doctor', 'quiet', 'help'] as $flag) {
        $value = $_GET[$flag] ?? null;

        if ($value !== null
            && !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off', ''], true)) {
            $options[$flag] = true;
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
        'EcoWealth repeat-purchase release',
        '',
        'Usage: php bin/unilevel_release.php [flags]',
        '',
        '  --limit=200       due orders per run (1-1000)',
        '  --order=ID        try one order only (still respects the hold)',
        '  --dry-run         list what would be released, change nothing',
        '  --json            machine-readable output',
        '  --doctor          environment / settings / health report',
        '  --log-dir=<dir>   where the monthly log is written',
        '  --quiet           no stdout (the log still records the run)',
        '  --help            this text',
        '',
        'Web: /system/cron/unilevel_release.php?dry-run=1&json=1',
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

/* --------------------------------------------------------------
 *  HTTP guard — run after config so CRON_KEY is available
 * -------------------------------------------------------------- */

$options = unilevel_release_options($isCli);
$trigger = $isCli ? 'cli' : 'http';

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
    $service = new UnilevelService();

    if ($options['help']) {
        $lines   = [unilevel_release_usage()];
        $payload = ['ok' => true, 'usage' => unilevel_release_usage()];
    } elseif ($options['doctor']) {
        $report  = unilevel_release_doctor($service, $lock, $log, $limit);
        $lines   = unilevel_release_format_doctor($report);
        $payload = ['ok' => true, 'doctor' => $report];
    } elseif ($options['dry-run']) {
        $doctor = $service->releaseStatus();
        $ids    = $service->dueOrderIds($limit);
        $rows   = $service->dueCandidates($limit);

        $lines = [
            'EcoWealth repeat-purchase release (dry run — nothing is changed)',
            '  database:  ' . DB_NAME . ' (' . DB_HOST . ')',
            '  enabled:   ' . (UnilevelService::releaseEnabled() ? 'yes' : 'NO — the real run would skip'),
            '  hold days: ' . UnilevelService::holdDays(),
            '  pending:   ' . $doctor['pending_count'] . ' award(s) · ' . money($doctor['pending_total']),
            '  candidates: ' . count($ids) . ($ids === [] ? '' : ' → orders ' . implode(', ', $ids)),
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '    order %s (#%d) → award #%d · %s · level %d · %s',
                (string) ($row['order_no'] ?? ''),
                (int) $row['order_id'],
                (int) $row['id'],
                (string) ($row['recipient_username'] ?? ''),
                (int) $row['level'],
                money((float) $row['amount'])
            );
        }

        $payload = [
            'ok'         => true,
            'trigger'    => $trigger,
            'dry_run'    => true,
            'enabled'    => UnilevelService::releaseEnabled(),
            'hold_days'  => UnilevelService::holdDays(),
            'pending'    => $doctor['pending_count'],
            'pending_amount' => (float) $doctor['pending_total'],
            'candidates' => count($ids),
            'ids'        => $ids,
            'awards'     => array_map(static fn (array $row): int => (int) $row['id'], $rows),
        ];

        $log->write('INFO', sprintf(
            'dry-run trigger=%s limit=%d hold=%d pending=%d candidates=%d ids=%s',
            $trigger,
            $limit,
            UnilevelService::holdDays(),
            (int) $doctor['pending_count'],
            count($ids),
            implode(',', $ids)
        ));
    } elseif (!$lock->acquire()) {
        $lines = [
            'EcoWealth repeat-purchase release',
            '  skipped: another run is already in progress.',
        ];

        $payload = ['ok' => true, 'trigger' => $trigger, 'skipped' => 'locked'];

        $log->write('WARN', 'skipped: another run holds ' . $lock->path());
    } elseif (!UnilevelService::releaseEnabled()) {
        $doctor = $service->releaseStatus();

        $lines = [
            'EcoWealth repeat-purchase release',
            '  skipped: releases are paused in the admin settings.',
            '  pending:  ' . $doctor['pending_count'] . ' award(s) · ' . money($doctor['pending_total'])
                . ' — they stay pending.',
        ];

        $payload = [
            'ok'         => false,
            'trigger'    => $trigger,
            'skipped'    => 'disabled',
            'released_orders' => 0,
            'released_awards' => 0,
            'amount'     => 0.0,
            'ids'        => [],
            'pending'    => (int) $doctor['pending_count'],
            'pending_amount' => (float) $doctor['pending_total'],
        ];

        $log->write('WARN', 'skipped: disabled by settings (unilevel_release_enabled=0)');
    } elseif ($order > 0) {
        /* Targeted diagnostic: one order, normal due rule, no force. */
        $result  = $service->releaseForOrder($order);
        $reason  = (string) ($result['reason'] ?? '');
        $ids     = $result['released'] > 0 ? [$order] : [];

        $lines = [
            'EcoWealth repeat-purchase release — one order',
            '  order:    #' . $order,
            '  released: ' . $result['released'] . ' award(s) · ' . money($result['amount']),
            '  skipped:  ' . $result['skipped'],
            '  reason:   ' . ($reason === '' ? '—' : $reason),
        ];

        $payload = [
            'ok'              => true,
            'trigger'         => $trigger,
            'order'           => $order,
            'released_orders' => $result['released'] > 0 ? 1 : 0,
            'released_awards' => $result['released'],
            'amount'          => $result['amount'],
            'skipped'         => $result['skipped'],
            'reason'          => $reason,
            'awards'          => $result['awards'],
            'ids'             => $ids,
        ];

        $log->write($result['released'] > 0 ? 'OK' : 'INFO', sprintf(
            'order=%d trigger=%s released=%d amount=%s skipped=%d reason=%s',
            $order,
            $trigger,
            $result['released'],
            number_format((float) $result['amount'], 2, '.', ''),
            $result['skipped'],
            $reason === '' ? '-' : $reason
        ));
    } else {
        $result   = $service->releaseDue($limit);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $ids      = $result['ids'];

        $lines = [
            'EcoWealth repeat-purchase release',
            '  database:   ' . DB_NAME . ' (' . DB_HOST . ')',
            '  trigger:    ' . $trigger,
            '  hold days:  ' . UnilevelService::holdDays(),
            '  candidates: ' . (int) $result['candidates'],
            '  released:   ' . $result['released_orders'] . ' order(s) · '
                . $result['released_awards'] . ' award(s) · ' . money($result['amount']),
            '  skipped:    ' . $result['skipped'],
            '  errors:     ' . $result['errors'],
            '  ids:        ' . ($ids === [] ? '—' : implode(', ', $ids)),
            '  duration:   ' . $duration . 'ms',
            '  log:        ' . $log->fileFor(),
        ];

        $payload = [
            'ok'              => $result['errors'] === 0,
            'trigger'         => $trigger,
            'hold_days'       => UnilevelService::holdDays(),
            'candidates'      => (int) $result['candidates'],
            'released_orders' => $result['released_orders'],
            'released_awards' => $result['released_awards'],
            'amount'          => $result['amount'],
            'skipped'         => $result['skipped'],
            'errors'          => $result['errors'],
            'ids'             => $ids,
            'duration_ms'     => $duration,
            'log'             => $log->fileFor(),
        ];

        if ($result['errors'] > 0) {
            $exitCode = 1;
        }

        $log->write(
            $result['errors'] > 0 ? 'ERROR' : ($result['released_awards'] > 0 ? 'OK' : 'INFO'),
            sprintf(
                'trigger=%s limit=%d hold=%d candidates=%d released_orders=%d released_awards=%d amount=%s skipped=%d errors=%d duration=%dms ids=%s',
                $trigger,
                $limit,
                UnilevelService::holdDays(),
                (int) $result['candidates'],
                $result['released_orders'],
                $result['released_awards'],
                number_format((float) $result['amount'], 2, '.', ''),
                $result['skipped'],
                $result['errors'],
                $duration,
                implode(',', $ids)
            )
        );
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

    $payload = ['ok' => false, 'trigger' => $trigger, 'error' => 'The release job failed. See the log.'];
} finally {
    $lock->release();
}

unilevel_release_emit($isCli, $options, $lines, $payload);

exit($exitCode);
