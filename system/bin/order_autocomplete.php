<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — order auto-complete job (plan §5.5, §7 Phase 8)
 *
 *  Delivered orders complete themselves after `shop_autocomplete_days`
 *  (default 7) when the member never confirms receipt.
 *
 *  One implementation, three entry points:
 *
 *    CLI      php bin/order_autocomplete.php [flags]      (Hostinger cron, SSH)
 *    Web      /system/cron/order_autocomplete.php[?flags] (local testing, URL cron)
 *    Windows  bin\order_autocomplete.cmd [flags]          (Task Scheduler)
 *
 *  Every completion locks the order, re-checks `delivered` and the
 *  delivered_at stamp, then writes the status, `completed_at` and an
 *  `auto_completed` event (actor: system). Running it twice changes
 *  nothing the second time.
 *
 *  Flags (CLI: --x=…, web: ?x=…):
 *    --limit=200   how many candidates one run may complete
 *    --dry-run     list what would be completed, change nothing
 *    --json        machine-readable output
 *    --doctor      environment/settings/health report
 *    --log-dir=<dir>  CLI only; default <storage>/logs
 *    --quiet       CLI only; no stdout (the log still gets a line)
 *    --help        usage
 *
 *  The kill switch is `shop_autocomplete_enabled` on /admin/settings
 *  (0 skips every run). `shop_autocomplete_days = 0` also disables it.
 *
 *  Access (web): a loopback request from the same machine is trusted, so
 *  a local browser tab just works. Any other caller needs ?key= matching
 *  CRON_KEY in app/config.local.php. The CLI is always allowed.
 *
 *  Cron examples (see docs/cron.md):
 *    0 * * * * /usr/bin/php /path/system/bin/order_autocomplete.php --limit=200
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
 * @return array{limit:int, dry-run:bool, json:bool, doctor:bool, quiet:bool, help:bool, log-dir:?string}
 */
function autocomplete_options(bool $isCli): array
{
    $options = [
        'limit'   => 200,
        'dry-run' => false,
        'json'    => false,
        'doctor'  => false,
        'quiet'   => false,
        'help'    => false,
        'log-dir' => null,
    ];

    if ($isCli) {
        $parsed = getopt('', ['limit:', 'dry-run', 'json', 'doctor', 'quiet', 'log-dir:', 'help']);

        if (!is_array($parsed)) {
            return $options;
        }

        if (isset($parsed['limit'])) {
            $options['limit'] = (int) $parsed['limit'];
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

    return $options;
}

function autocomplete_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth order auto-complete',
        '',
        'Usage: php bin/order_autocomplete.php [flags]',
        '',
        '  --limit=200       candidates per run (1-1000)',
        '  --dry-run         list what would be completed, change nothing',
        '  --json            machine-readable output',
        '  --doctor          environment / settings / health report',
        '  --log-dir=<dir>   where the monthly log is written',
        '  --quiet           no stdout (the log still records the run)',
        '  --help            this text',
        '',
        'Web: /system/cron/order_autocomplete.php?dry-run=1&json=1',
    ]);
}

/* --------------------------------------------------------------
 *  Access, output and reporting helpers
 * -------------------------------------------------------------- */

function autocomplete_deny_http(): never
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
function autocomplete_emit(bool $isCli, array $options, array $lines, array $payload): void
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
function autocomplete_doctor(FulfilmentService $service, CronLock $lock, CronLog $log, int $limit): array
{
    $dbNow      = 'unavailable';
    $dbTz       = 'unavailable';
    $candidates = 'unavailable';

    try {
        $dbNow = (string) db()->query('SELECT NOW()')->fetchColumn();
        $dbTz  = (string) db()->query('SELECT @@session.time_zone')->fetchColumn();
        $candidates = (string) count($service->dueOrderIds($limit));
    } catch (Throwable $e) {
        error_log('[ecowealth] auto-complete doctor: ' . $e->getMessage());
    }

    $lockState = $lock->status();
    $lockText  = $lockState['state'] . ' · ' . $lock->path();

    if ($lockState['state'] === 'unavailable' && $lockState['detail'] !== '') {
        $lockText .= ' — ' . $lockState['detail'];
    }

    return [
        'php'        => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'timezone'   => date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s P'),
        'database'   => DB_NAME . ' @ ' . DB_HOST,
        'db_now'     => $dbNow . ' (session ' . $dbTz . ')',
        'days'       => (string) FulfilmentService::autoCompleteDays(),
        'enabled'    => FulfilmentService::autoCompleteEnabled() ? 'yes' : 'NO — the sweep is skipped',
        'candidates' => $candidates,
        'lock'       => $lockText,
        'log'        => $log->fileFor() . ($log->isWritable() ? ' (writable)' : ' (NOT writable)'),
        'cron_key'   => CronAccess::configuredKey() === null ? 'not set (loopback only)' : 'set',
        'storage'    => (string) STORAGE_PATH,
    ];
}

/** @param array<string, string> $report @return list<string> */
function autocomplete_format_doctor(array $report): array
{
    $lines = ['EcoWealth order auto-complete — doctor'];
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

$options = autocomplete_options($isCli);
$trigger = $isCli ? 'cli' : 'http';

if (!$isCli) {
    $allowed = CronAccess::allowed(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        isset($_GET['key']) ? (string) $_GET['key'] : null,
        CronAccess::configuredKey()
    );

    if (!$allowed) {
        autocomplete_deny_http();
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

$lock = new CronLock('order_autocomplete');
$log  = new CronLog($isCli ? $options['log-dir'] : null, 'order_autocomplete');

$started  = microtime(true);
$exitCode = 0;
$lines    = [];
$payload  = ['ok' => true, 'trigger' => $trigger];

/* --------------------------------------------------------------
 *  Run (no exit() inside the try, so the lock is always released)
 * -------------------------------------------------------------- */

try {
    $service = new FulfilmentService();

    if ($options['help']) {
        $lines   = [autocomplete_usage()];
        $payload = ['ok' => true, 'usage' => autocomplete_usage()];
    } elseif ($options['doctor']) {
        $report  = autocomplete_doctor($service, $lock, $log, $limit);
        $lines   = autocomplete_format_doctor($report);
        $payload = ['ok' => true, 'doctor' => $report];
    } elseif ($options['dry-run']) {
        $ids     = $service->dueOrderIds($limit);
        $enabled = FulfilmentService::autoCompleteActive();
        $days    = FulfilmentService::autoCompleteDays();

        $lines = [
            'EcoWealth order auto-complete (dry run — nothing is changed)',
            '  database: ' . DB_NAME . ' (' . DB_HOST . ')',
            '  days:     ' . $days,
            '  enabled:  ' . ($enabled ? 'yes' : 'no — the real run would skip'),
            sprintf('  candidates: %d%s', count($ids), $ids === [] ? '' : ' → ' . implode(', ', $ids)),
        ];

        $payload = [
            'ok'         => true,
            'trigger'    => $trigger,
            'dry_run'    => true,
            'days'       => $days,
            'enabled'    => $enabled,
            'candidates' => count($ids),
            'ids'        => $ids,
        ];

        $log->write('INFO', sprintf(
            'dry-run trigger=%s limit=%d days=%d candidates=%d ids=%s',
            $trigger,
            $limit,
            $days,
            count($ids),
            implode(',', $ids)
        ));
    } elseif (!$lock->acquire()) {
        $lines = [
            'EcoWealth order auto-complete',
            '  skipped: another run is already in progress.',
        ];

        $payload = ['ok' => true, 'trigger' => $trigger, 'skipped' => 'locked'];

        $log->write('WARN', 'skipped: another run holds ' . $lock->path());
    } else {
        $result  = $service->autoComplete($limit);
        $skipped = $result['skipped'] ?? null;

        if ($skipped === 'disabled') {
            $lines = [
                'EcoWealth order auto-complete',
                '  skipped: auto-complete is disabled in the admin settings.',
            ];

            $payload = [
                'ok'        => true,
                'trigger'   => $trigger,
                'skipped'   => 'disabled',
                'completed' => 0,
                'ids'       => [],
                'days'      => $result['days'],
            ];

            $log->write('WARN', 'skipped: disabled by settings (shop_autocomplete_enabled=0 or days=0)');
        } else {
            $duration = (int) round((microtime(true) - $started) * 1000);
            $ids      = $result['ids'];

            $lines = [
                'EcoWealth order auto-complete',
                '  database:   ' . DB_NAME . ' (' . DB_HOST . ')',
                '  trigger:    ' . $trigger,
                '  days:       ' . $result['days'],
                '  candidates: ' . (int) $result['candidates'],
                '  completed:  ' . $result['completed'],
                '  ids:        ' . ($ids === [] ? '—' : implode(', ', $ids)),
                '  duration:   ' . $duration . 'ms',
                '  log:        ' . $log->fileFor(),
            ];

            $payload = [
                'ok'          => true,
                'trigger'     => $trigger,
                'days'        => $result['days'],
                'candidates'  => (int) $result['candidates'],
                'completed'   => $result['completed'],
                'ids'         => $ids,
                'duration_ms' => $duration,
                'log'         => $log->fileFor(),
            ];

            $log->write(
                $result['completed'] > 0 ? 'OK' : 'INFO',
                sprintf(
                    'trigger=%s limit=%d days=%d candidates=%d completed=%d duration=%dms ids=%s',
                    $trigger,
                    $limit,
                    $result['days'],
                    (int) $result['candidates'],
                    $result['completed'],
                    $duration,
                    implode(',', $ids)
                )
            );
        }
    }
} catch (Throwable $e) {
    $exitCode = 1;
    $log->write('ERROR', 'run failed: ' . $e->getMessage());

    $lines = [
        'Order auto-complete FAILED',
        '  The job could not run. See ' . $log->fileFor() . ' for the error.',
    ];

    if ($isCli) {
        fwrite(STDERR, 'Order auto-complete failed: ' . $e->getMessage() . PHP_EOL);
    }

    $payload = ['ok' => false, 'trigger' => $trigger, 'error' => 'The auto-complete job failed. See the log.'];
} finally {
    $lock->release();
}

autocomplete_emit($isCli, $options, $lines, $payload);

exit($exitCode);
