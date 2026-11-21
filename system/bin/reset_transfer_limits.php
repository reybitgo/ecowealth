<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — transfer-limit reset job (plan §5.8, §7 Phase 12)
 *
 *  One implementation, three entry points:
 *
 *    CLI      php bin/reset_transfer_limits.php [flags]     (cron, SSH)
 *    Web      /system/cron/reset_transfer_limits.php[?flags]
 *    Windows  bin\reset_transfer_limits.cmd [flags]         (Task Scheduler)
 *
 *  What it does (reference cron/fund_transfer_limit_reset.php):
 *
 *    daily    → users.ewallet_sent_today = 0 for every account that has
 *               a non-zero counter, on every run;
 *    weekly   → users.ewallet_sent_this_week = 0 as well, on Mondays
 *               only (the app time zone decides the weekday).
 *
 *  It is safe to run repeatedly: a second run zeroes nothing and the
 *  log records "0 counters". Nothing is written to `audit_log` — a
 *  daily job would otherwise grow it forever; the monthly cron log is
 *  the record of each run.
 *
 *  Flags (CLI: --x=…, web: ?x=…):
 *    --week            force the weekly reset (whatever the weekday)
 *    --daily-only      never reset the weekly counter (even on Monday)
 *    --dry-run         report what would be zeroed, change nothing
 *    --json            machine-readable output
 *    --doctor          environment / counters / lock / log report
 *    --log-dir=<dir>   CLI only; default <storage>/logs
 *    --quiet           CLI only; no stdout (the log still gets a line)
 *    --help            usage
 *
 *  Access (web): a loopback request is trusted, so a local browser tab
 *  just works. Any other caller needs ?key= matching CRON_KEY in
 *  app/config.local.php. The CLI is always allowed.
 *
 *  Schedule it once a day just after midnight (see docs/cron.md):
 *    5 0 * * * /usr/bin/php /path/system/bin/reset_transfer_limits.php
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

/** @return array<string, bool|string|null> */
function limit_reset_options(bool $isCli): array
{
    $options = [
        'week'       => false,
        'daily-only' => false,
        'dry-run'    => false,
        'json'       => false,
        'doctor'     => false,
        'quiet'      => false,
        'help'       => false,
        'log-dir'    => null,
    ];

    $flags = ['week', 'daily-only', 'dry-run', 'json', 'doctor', 'quiet', 'help'];

    if ($isCli) {
        $parsed = getopt('', ['week', 'weekly', 'daily-only', 'dry-run', 'json', 'doctor', 'quiet', 'log-dir:', 'help']);

        if (!is_array($parsed)) {
            return $options;
        }

        if (isset($parsed['weekly'])) {
            $parsed['week'] = false;
        }

        foreach ($flags as $flag) {
            $options[$flag] = isset($parsed[$flag]);
        }

        if (isset($parsed['log-dir']) && is_string($parsed['log-dir']) && trim($parsed['log-dir']) !== '') {
            $options['log-dir'] = trim($parsed['log-dir']);
        }

        return $options;
    }

    foreach ($flags as $flag) {
        $value = $_GET[$flag] ?? null;

        if ($value !== null
            && !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off', ''], true)) {
            $options[$flag] = true;
        }
    }

    return $options;
}

function limit_reset_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth transfer limit reset',
        '',
        'Usage: php bin/reset_transfer_limits.php [flags]',
        '',
        '  --week            also reset the weekly counters (Mondays do this automatically)',
        '  --daily-only      never reset the weekly counters',
        '  --dry-run         show what would be zeroed, change nothing',
        '  --json            machine-readable output',
        '  --doctor          environment / counters / lock / log report',
        '  --log-dir=<dir>   where the monthly log is written',
        '  --quiet           no stdout (the log still records the run)',
        '  --help            this text',
        '',
        'Web: /system/cron/reset_transfer_limits.php?dry-run=1&json=1',
    ]);
}

/* --------------------------------------------------------------
 *  Output helpers
 * -------------------------------------------------------------- */

function limit_reset_deny_http(): never
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

/**
 * @param list<string> $lines
 * @param array<string, mixed> $payload
 * @param array<string, bool|string|null> $options
 */
function limit_reset_emit(bool $isCli, array $options, array $lines, array $payload): void
{
    if ($isCli && $options['quiet'] === true) {
        return;
    }

    if ($options['json'] === true) {
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
function limit_reset_doctor(TransferService $service, CronLock $lock, CronLog $log, bool $weekly, bool $isMonday): array
{
    $counters = ['daily' => 'unavailable', 'weekly' => 'unavailable', 'accounts' => 'unavailable'];

    try {
        $pending  = $service->pendingResets();
        $counters = [
            'daily'    => $pending['daily'] . ' account(s) with daily usage',
            'weekly'   => $pending['weekly'] . ' account(s) with weekly usage',
            'accounts' => $pending['accounts'] . ' account(s)',
        ];
    } catch (Throwable $e) {
        error_log('[ecowealth] limit reset doctor: ' . $e->getMessage());
    }

    $lockState = $lock->status();
    $lockText  = $lockState['state'] . ' · ' . $lock->path();

    if ($lockState['state'] === 'unavailable' && $lockState['detail'] !== '') {
        $lockText .= ' — ' . $lockState['detail'];
    }

    return [
        'php'            => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'timezone'       => date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s P'),
        'database'       => DB_NAME . ' @ ' . DB_HOST,
        'weekday'        => date('l') . ($isMonday ? ' — weekly reset is due' : ' — daily reset only'),
        'weekly_reset'   => $weekly ? 'yes' : 'no',
        'daily_counters' => $counters['daily'],
        'weekly_counters' => $counters['weekly'],
        'accounts'       => $counters['accounts'],
        'lock'           => $lockText,
        'log'            => $log->fileFor() . ($log->isWritable() ? ' (writable)' : ' (NOT writable)'),
        'cron_key'       => CronAccess::configuredKey() === null ? 'not set (loopback only)' : 'set',
        'storage'        => (string) STORAGE_PATH,
    ];
}

/** @param array<string, string> $report @return list<string> */
function limit_reset_format_doctor(array $report): array
{
    $lines = ['EcoWealth transfer limit reset — doctor'];
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
 *  HTTP guard — after config so CRON_KEY is available
 * -------------------------------------------------------------- */

$options = limit_reset_options($isCli);
$trigger = $isCli ? 'cli' : 'http';

if (!$isCli) {
    $allowed = CronAccess::allowed(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        isset($_GET['key']) ? (string) $_GET['key'] : null,
        CronAccess::configuredKey()
    );

    if (!$allowed) {
        limit_reset_deny_http();
    }

    ini_set('display_errors', '0');

    if (!headers_sent()) {
        header('Content-Type: ' . ($options['json'] === true ? 'application/json' : 'text/plain') . '; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
    }
}

$isMonday = TransferService::isMonday();
$weekly   = (bool) $options['week'] || ($isMonday && $options['daily-only'] !== true);

$lock = new CronLock('reset_transfer_limits');
$log  = new CronLog($isCli ? $options['log-dir'] : null, 'reset_transfer_limits');

$started  = microtime(true);
$exitCode = 0;
$lines    = [];
$payload  = [
    'ok'      => true,
    'trigger' => $trigger,
    'is_monday' => $isMonday,
    'weekly'  => $weekly,
];

/* --------------------------------------------------------------
 *  Run (no exit() inside the try, so the lock is always released)
 * -------------------------------------------------------------- */

try {
    $service = new TransferService();

    if ($options['help'] === true) {
        $lines   = [limit_reset_usage()];
        $payload = ['ok' => true, 'usage' => limit_reset_usage()];
    } elseif ($options['doctor'] === true) {
        $report  = limit_reset_doctor($service, $lock, $log, $weekly, $isMonday);
        $lines   = limit_reset_format_doctor($report);
        $payload = ['ok' => true, 'doctor' => $report];
    } elseif ($options['dry-run'] === true) {
        $pending = $service->pendingResets();

        $lines = [
            'EcoWealth transfer limit reset (dry run — nothing is changed)',
            '  database:     ' . DB_NAME . ' (' . DB_HOST . ')',
            '  day:          ' . date('l, d M Y'),
            '  weekly reset: ' . ($weekly ? 'yes' : 'no (daily only)'),
            '  daily:        ' . $pending['daily'] . ' counter(s) would be zeroed',
            '  weekly:       ' . ($weekly ? $pending['weekly'] . ' counter(s) would be zeroed' : 'skipped'),
        ];

        $payload = [
            'ok'           => true,
            'trigger'      => $trigger,
            'dry_run'      => true,
            'is_monday'    => $isMonday,
            'weekly'       => $weekly,
            'daily'        => $pending['daily'],
            'weekly_count' => $weekly ? $pending['weekly'] : 0,
        ];

        $log->write('INFO', sprintf(
            'dry-run trigger=%s day=%s weekly=%s daily=%d weekly_counters=%d',
            $trigger,
            date('D'),
            $weekly ? 'yes' : 'no',
            $pending['daily'],
            $pending['weekly']
        ));
    } elseif (!$lock->acquire()) {
        $lines = [
            'EcoWealth transfer limit reset',
            '  skipped: another run is already in progress.',
        ];

        $payload = [
            'ok'      => true,
            'trigger' => $trigger,
            'skipped' => 'locked',
        ];

        $log->write('WARN', 'skipped: another run holds ' . $lock->path());
    } else {
        $result   = $service->resetLimits($weekly);
        $duration = (int) round((microtime(true) - $started) * 1000);

        $lines = [
            'EcoWealth transfer limit reset',
            '  database:     ' . DB_NAME . ' (' . DB_HOST . ')',
            '  trigger:      ' . $trigger,
            '  day:          ' . date('l, d M Y'),
            '  weekly reset: ' . ($weekly ? 'yes' : 'no (daily only)'),
            '  daily:        ' . $result['daily_zeroed'] . ' counter(s) zeroed',
            '  weekly:       ' . ($weekly ? $result['weekly_zeroed'] . ' counter(s) zeroed' : 'skipped'),
            '  accounts:     ' . $result['checked'],
            '  duration:     ' . $duration . 'ms',
            '  log:          ' . $log->fileFor(),
        ];

        $payload = [
            'ok'            => true,
            'trigger'       => $trigger,
            'is_monday'     => $isMonday,
            'weekly'        => $weekly,
            'daily_zeroed'  => $result['daily_zeroed'],
            'weekly_zeroed' => $result['weekly_zeroed'],
            'checked'       => $result['checked'],
            'duration_ms'   => $duration,
            'log'           => $log->fileFor(),
        ];

        $log->write(
            ($result['daily_zeroed'] + $result['weekly_zeroed']) > 0 ? 'OK' : 'INFO',
            sprintf(
                'trigger=%s day=%s weekly=%s daily_zeroed=%d weekly_zeroed=%d accounts=%d duration=%dms',
                $trigger,
                date('D'),
                $weekly ? 'yes' : 'no',
                $result['daily_zeroed'],
                $result['weekly_zeroed'],
                $result['checked'],
                $duration
            )
        );
    }
} catch (Throwable $e) {
    $exitCode = 1;
    $log->write('ERROR', 'run failed: ' . $e->getMessage());

    $lines = [
        'Transfer limit reset FAILED',
        '  The job could not run. See ' . $log->fileFor() . ' for the error.',
    ];

    if ($isCli) {
        fwrite(STDERR, 'Transfer limit reset failed: ' . $e->getMessage() . PHP_EOL);
    }

    $payload = [
        'ok'      => false,
        'trigger' => $trigger,
        'error'   => 'The reset job failed. See the log.',
    ];
} finally {
    $lock->release();
}

limit_reset_emit($isCli, $options, $lines, $payload);

exit($exitCode);
