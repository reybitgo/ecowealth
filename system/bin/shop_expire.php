<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — shop order expiry job (plan §5.4, §7 Phase 7)
 *
 *  One implementation, three entry points:
 *
 *    CLI      php bin/shop_expire.php [flags]        (Hostinger cron, SSH)
 *    Web      /system/cron/shop_expire.php[?flags]   (local testing, URL cron)
 *    Windows  bin\shop_expire.cmd [flags]            (Task Scheduler)
 *
 *  What it does (default: the stamps written from the admin settings):
 *    pending        → cancelled when payment_deadline passed
 *    payment_failed → cancelled when correction_deadline passed
 *    payment_review is exempt — a submitted proof is an admin decision.
 *  Every cancellation locks the order, re-checks it, releases the stock
 *  and writes a `payment_expired` event (actor: system). It is safe to
 *  run repeatedly.
 *
 *  Flags (CLI: --x=…, web: ?x=…):
 *    --limit=200        how many candidates one run may process
 *    --dry-run          list what would be cancelled, change nothing
 *    --from-settings    recompute the window from the current settings
 *                       (pending: created_at + payment window;
 *                        payment_failed: last rejection + correction
 *                        window) instead of the stored stamp
 *    --json             machine-readable output
 *    --doctor           environment/settings/health report
 *    --log-dir=<dir>    CLI only; default <storage>/logs
 *    --quiet            CLI only; no stdout (the log still gets a line)
 *    --help             usage
 *
 *  Access (web): a loopback request from the same machine is trusted, so
 *  a local browser tab just works. Any other caller needs ?key= matching
 *  CRON_KEY in app/config.local.php. Without a configured key, remote
 *  web callers are refused. The CLI is always allowed.
 *
 *  Cron examples (see docs/cron.md):
 *    15,45 * * * * /usr/bin/php /path/system/bin/shop_expire.php --limit=200
 *    15,45 * * * * curl -s "https://site/system/cron/shop_expire.php?key=…"
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
 *   limit:int, dry-run:bool, json:bool, doctor:bool, from-settings:bool,
 *   quiet:bool, help:bool, log-dir:?string
 * }
 */
function cron_options(bool $isCli): array
{
    $options = [
        'limit'         => 200,
        'dry-run'       => false,
        'json'          => false,
        'doctor'        => false,
        'from-settings' => false,
        'quiet'         => false,
        'help'          => false,
        'log-dir'       => null,
    ];

    if ($isCli) {
        $parsed = getopt('', ['limit:', 'dry-run', 'json', 'doctor', 'from-settings', 'quiet', 'log-dir:', 'help']);

        if (!is_array($parsed)) {
            return $options;
        }

        if (isset($parsed['limit'])) {
            $options['limit'] = (int) $parsed['limit'];
        }

        foreach (['dry-run', 'json', 'doctor', 'from-settings', 'quiet', 'help'] as $flag) {
            $options[$flag] = isset($parsed[$flag]);
        }

        if (isset($parsed['log-dir']) && is_string($parsed['log-dir']) && trim($parsed['log-dir']) !== '') {
            $options['log-dir'] = trim($parsed['log-dir']);
        }

        return $options;
    }

    foreach (['dry-run', 'json', 'doctor', 'from-settings', 'quiet', 'help'] as $flag) {
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

function cron_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth shop expiry',
        '',
        'Usage: php bin/shop_expire.php [flags]',
        '',
        '  --limit=200       candidates per run (1-1000)',
        '  --dry-run         list what would be cancelled, change nothing',
        '  --from-settings   recompute deadlines from the current settings',
        '  --json            machine-readable output',
        '  --doctor          environment / settings / health report',
        '  --log-dir=<dir>   where the monthly log is written',
        '  --quiet           no stdout (the log still records the run)',
        '  --help            this text',
        '',
        'Web: /system/cron/shop_expire.php?dry-run=1&from-settings=1&json=1',
    ]);
}

/* --------------------------------------------------------------
 *  Access, output and reporting helpers
 * -------------------------------------------------------------- */

function cron_deny_http(): never
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
function cron_emit(bool $isCli, array $options, array $lines, array $payload): void
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
function cron_doctor(PaymentService $service, CronLock $lock, CronLog $log, bool $fromSettings, int $limit): array
{
    $dbNow      = 'unavailable';
    $dbTz       = 'unavailable';
    $candidates = 'unavailable';

    try {
        $dbNow = (string) db()->query('SELECT NOW()')->fetchColumn();
        $dbTz  = (string) db()->query('SELECT @@session.time_zone')->fetchColumn();
        $candidates = (string) count($service->overdueOrderIds($limit, $fromSettings));
    } catch (Throwable $e) {
        error_log('[ecowealth] expiry doctor: ' . $e->getMessage());
    }

    $lockState = $lock->status();
    $lockText  = $lockState['state'] . ' · ' . $lock->path();

    if ($lockState['state'] === 'unavailable' && $lockState['detail'] !== '') {
        $lockText .= ' — ' . $lockState['detail'];
    }

    return [
        'php'               => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'timezone'          => date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s P'),
        'database'          => DB_NAME . ' @ ' . DB_HOST,
        'db_now'            => $dbNow . ' (session ' . $dbTz . ')',
        'mode'              => $fromSettings ? 'settings (recomputed)' : 'stamps (stored deadlines)',
        'payment_window'    => (string) PaymentService::paymentDeadlineHours() . ' hours',
        'correction_window' => (string) PaymentService::correctionWindowHours() . ' hours',
        'proof_attempts'    => (string) PaymentService::maxAttempts(),
        'expiry_enabled'    => PaymentService::expiryEnabled() ? 'yes' : 'NO — the sweep is skipped',
        'candidates'        => $candidates,
        'lock'              => $lockText,
        'log'               => $log->fileFor() . ($log->isWritable() ? ' (writable)' : ' (NOT writable)'),
        'cron_key'          => CronAccess::configuredKey() === null ? 'not set (loopback only)' : 'set',
        'storage'           => (string) STORAGE_PATH,
    ];
}

/** @param array<string, string> $report @return list<string> */
function cron_format_doctor(array $report): array
{
    $lines = ['EcoWealth shop expiry — doctor'];
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

$options = cron_options($isCli);
$trigger = $isCli ? 'cli' : 'http';

if (!$isCli) {
    $allowed = CronAccess::allowed(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        isset($_GET['key']) ? (string) $_GET['key'] : null,
        CronAccess::configuredKey()
    );

    if (!$allowed) {
        cron_deny_http();
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

$fromSettings = (bool) $options['from-settings'];
$mode         = $fromSettings ? 'settings' : 'stamps';
$limit        = (int) $options['limit'];

$lock = new CronLock('shop_expire');
$log  = new CronLog($isCli ? $options['log-dir'] : null, 'shop_expire');

$started  = microtime(true);
$exitCode = 0;
$lines    = [];
$payload  = ['ok' => true, 'trigger' => $trigger, 'mode' => $mode];

/* --------------------------------------------------------------
 *  Run (no exit() inside the try, so the lock is always released)
 * -------------------------------------------------------------- */

try {
    $service = new PaymentService();

    if ($options['help']) {
        $lines   = [cron_usage()];
        $payload = ['ok' => true, 'usage' => cron_usage()];
    } elseif ($options['doctor']) {
        $report  = cron_doctor($service, $lock, $log, $fromSettings, $limit);
        $lines   = cron_format_doctor($report);
        $payload = ['ok' => true, 'doctor' => $report];
    } elseif ($options['dry-run']) {
        $ids     = $service->overdueOrderIds($limit, $fromSettings);
        $enabled = PaymentService::expiryEnabled();

        $lines = [
            'EcoWealth shop expiry (dry run — nothing is changed)',
            '  database: ' . DB_NAME . ' (' . DB_HOST . ')',
            '  mode:     ' . $mode,
            '  enabled:  ' . ($enabled ? 'yes' : 'no — the real run would skip'),
            sprintf('  candidates: %d%s', count($ids), $ids === [] ? '' : ' → ' . implode(', ', $ids)),
        ];

        $payload = [
            'ok'         => true,
            'trigger'    => $trigger,
            'mode'       => $mode,
            'dry_run'    => true,
            'enabled'    => $enabled,
            'candidates' => count($ids),
            'ids'        => $ids,
        ];

        $log->write('INFO', sprintf(
            'dry-run mode=%s trigger=%s limit=%d candidates=%d ids=%s',
            $mode,
            $trigger,
            $limit,
            count($ids),
            implode(',', $ids)
        ));
    } elseif (!$lock->acquire()) {
        $lines = [
            'EcoWealth shop expiry',
            '  skipped: another run is already in progress.',
        ];

        $payload = [
            'ok'      => true,
            'trigger' => $trigger,
            'mode'    => $mode,
            'skipped' => 'locked',
        ];

        $log->write('WARN', 'skipped: another run holds ' . $lock->path());
    } else {
        $result  = $service->expireOverdue($limit, $fromSettings);
        $skipped = $result['skipped'] ?? null;

        if ($skipped === 'disabled') {
            $lines = [
                'EcoWealth shop expiry',
                '  skipped: expiry is disabled in the admin settings (shop_expiry_enabled = 0).',
            ];

            $payload = [
                'ok'      => true,
                'trigger' => $trigger,
                'mode'    => $mode,
                'skipped' => 'disabled',
                'expired' => 0,
                'ids'     => [],
            ];

            $log->write('WARN', 'skipped: disabled by settings (shop_expiry_enabled=0)');
        } else {
            $duration = (int) round((microtime(true) - $started) * 1000);
            $ids      = $result['ids'];

            $lines = [
                'EcoWealth shop expiry',
                '  database:   ' . DB_NAME . ' (' . DB_HOST . ')',
                '  mode:       ' . $mode,
                '  trigger:    ' . $trigger,
                '  candidates: ' . (int) $result['candidates'],
                '  expired:    ' . $result['expired'],
                '  ids:        ' . ($ids === [] ? '—' : implode(', ', $ids)),
                '  duration:   ' . $duration . 'ms',
                '  log:        ' . $log->fileFor(),
            ];

            $payload = [
                'ok'          => true,
                'trigger'     => $trigger,
                'mode'        => $mode,
                'candidates'  => (int) $result['candidates'],
                'expired'     => $result['expired'],
                'ids'         => $ids,
                'duration_ms' => $duration,
                'log'         => $log->fileFor(),
            ];

            $log->write(
                $result['expired'] > 0 ? 'OK' : 'INFO',
                sprintf(
                    'mode=%s trigger=%s limit=%d candidates=%d expired=%d duration=%dms ids=%s',
                    $mode,
                    $trigger,
                    $limit,
                    (int) $result['candidates'],
                    $result['expired'],
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
        'Shop expiry FAILED',
        '  The job could not run. See ' . $log->fileFor() . ' for the error.',
    ];

    if ($isCli) {
        fwrite(STDERR, 'Shop expiry failed: ' . $e->getMessage() . PHP_EOL);
    }

    $payload = [
        'ok'      => false,
        'trigger' => $trigger,
        'mode'    => $mode,
        'error'   => 'The expiry job failed. See the log.',
    ];
} finally {
    $lock->release();
}

cron_emit($isCli, $options, $lines, $payload);

exit($exitCode);
