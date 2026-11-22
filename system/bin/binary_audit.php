<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — Product binary points audit (Phase 22H-S2)
 *
 *  Does every cached balance equal the append-only ledger? Does every
 *  pair award have exactly its two consumption rows? Does every award's
 *  amount match its formula? Is the tree well-formed?
 *
 *  Read-only by default: it reports drift, never repairs.
 *
 *      php bin/binary_audit.php              # --check : full scan, exit 0/1
 *      php bin/binary_audit.php --fix        # recompute caches, re-check, exit 0/1
 *      php bin/binary_audit.php --check --node=123   # one node only
 *      php bin/binary_audit.php --fix --limit=200 --json   # machine output
 *      php bin/binary_audit.php --help
 *
 *  Exit codes: 0 clean · 1 drift found (even if --fix would repair) ·
 *              2 runtime error · 2 bad flags
 *
 *  Access (web): loopback only, ?key= matching CRON_KEY. The CLI is
 *  always allowed.
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
 *   fix:bool, check:bool, json:bool, help:bool, quiet:bool,
 *   node:int, limit:int, log-dir:?string
 * }
 */
function binary_audit_options(bool $isCli): array
{
    $options = [
        'fix'     => false,
        'check'   => true,
        'json'    => false,
        'help'    => false,
        'quiet'   => false,
        'node'    => 0,
        'limit'   => 200,
        'log-dir' => null,
    ];

    if ($isCli) {
        $parsed = getopt('', ['fix', 'check', 'json', 'help', 'quiet', 'node:', 'limit:', 'log-dir:']);

        if (!is_array($parsed)) {
            return $options;
        }

        $options['fix']   = isset($parsed['fix']);
        $options['check'] = !$options['fix'] || isset($parsed['check']);
        $options['json']  = isset($parsed['json']);
        $options['help']  = isset($parsed['help']);
        $options['quiet'] = isset($parsed['quiet']);

        if (isset($parsed['node']) && is_string($parsed['node'])) {
            $options['node'] = max(0, (int) $parsed['node']);
        }

        if (isset($parsed['limit'])) {
            $options['limit'] = max(1, min(1000, (int) $parsed['limit']));
        }

        if (isset($parsed['log-dir']) && is_string($parsed['log-dir']) && trim($parsed['log-dir']) !== '') {
            $options['log-dir'] = trim($parsed['log-dir']);
        }

        return $options;
    }

    foreach (['fix', 'check', 'json', 'help', 'quiet'] as $flag) {
        $value = $_GET[$flag] ?? null;

        if ($value !== null
            && !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off', ''], true)) {
            $options[$flag] = true;
        }
    }

    if (isset($_GET['node'])) {
        $options['node'] = max(0, (int) $_GET['node']);
    }

    if (isset($_GET['limit'])) {
        $options['limit'] = max(1, min(1000, (int) $_GET['limit']));
    }

    return $options;
}

function binary_audit_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth product-binary points audit',
        '',
        'Usage: php bin/binary_audit.php [flags]',
        '',
        '  --check         verify every invariant, change nothing (default)',
        '  --fix           recompute cached columns from the ledger, then re-check',
        '  --node=ID       audit one node only (implies --check unless --fix)',
        '  --limit=N       bound the scan (1-1000, default 200)',
        '  --json          machine-readable output',
        '  --quiet         no stdout (the log still records the run)',
        '  --log-dir=<dir> CLI only; where the monthly log is written',
        '  --help          this text',
        '',
        'Exit codes: 0 clean · 1 drift found · 2 runtime error',
        '',
        'Web: /system/cron/binary_audit.php?check=1&json=1',
    ]);
}

/* --------------------------------------------------------------
 *  Access, output and reporting helpers
 * -------------------------------------------------------------- */

function binary_audit_deny_http(): never
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
function binary_audit_emit(bool $isCli, array $options, array $lines, array $payload): void
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

/** @param array<string, string> $report @return list<string> */
function binary_audit_format_report(array $report, bool $fix): array
{
    $verb = $fix ? 'repaired and re-checked' : 'checked';
    $lines = ['EcoWealth product-binary points audit — ' . $verb];

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

$options = binary_audit_options($isCli);
$trigger = $isCli ? 'cli' : 'http';

if (!$isCli) {
    $allowed = CronAccess::allowed(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        isset($_GET['key']) ? (string) $_GET['key'] : null,
        CronAccess::configuredKey()
    );

    if (!$allowed) {
        binary_audit_deny_http();
    }

    ini_set('display_errors', '0');

    if (!headers_sent()) {
        header('Content-Type: ' . ($options['json'] ? 'application/json' : 'text/plain') . '; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
    }
}

if ($options['help']) {
    echo binary_audit_usage() . PHP_EOL;
    exit(0);
}

$log  = new CronLog($isCli ? $options['log-dir'] : null, 'binary_audit');
$node = (int) $options['node'];
$limit= (int) $options['limit'];

$started  = microtime(true);
$exitCode = 0;
$lines    = [];
$payload  = ['ok' => true, 'trigger' => $trigger];

/* --------------------------------------------------------------
 *  Run
 * -------------------------------------------------------------- */

try {
    $service = new BinaryService();

    if ($options['fix']) {
        $result = $service->auditFix();

        $fixed    = (int) ($result['fixed'] ?? 0);
        $ok       = (bool) ($result['ok'] ?? false);
        $drifts   = (array) ($result['drifts'] ?? []);
        $driftNum = count($drifts);

        $exitCode = $ok && $driftNum === 0 ? 0 : 1;

        $summaryLines = [
            'EcoWealth product-binary points audit — repaired and re-checked',
            '  database:  ' . DB_NAME . ' (' . DB_HOST . ')',
            '  fixed:     ' . $fixed . ' node(s)',
            '  drift:     ' . $driftNum . ' remaining',
            '  ok:        ' . ($ok ? 'yes' : 'NO — ' . $driftNum . ' drift(s) remain'),
            '  exit:      ' . $exitCode,
            '  duration:  ' . (int) round((microtime(true) - $started) * 1000) . 'ms',
        ];

        $lines = array_merge($lines, $summaryLines);

        foreach ($drifts as $drift) {
            $label = (string) ($drift['kind'] ?? 'drift');
            $target = (int) ($drift['user_id'] ?? $drift['activation'] ?? $drift['order'] ?? $drift['volume'] ?? $drift['award'] ?? 0);
            $lines[] = '    [' . $label . '] ' . (string) ($drift['message'] ?? '')
                . ($target > 0 ? ' (target #' . $target . ')' : '');
        }

        $log->write(
            $ok ? 'OK' : 'WARN',
            sprintf(
                'stream=binary audit=fix trigger=%s node=%d limit=%d fixed=%d drift=%d ok=%s duration=%dms',
                $trigger,
                $node,
                $limit,
                $fixed,
                $driftNum,
                $ok ? 'clean' : 'drift-remains',
                (int) round((microtime(true) - $started) * 1000)
            )
        );

        $payload = [
            'ok'         => $ok,
            'trigger'    => $trigger,
            'audit'      => 'fix',
            'fixed'      => $fixed,
            'drift'      => $driftNum,
            'exit_code'  => $exitCode,
            'drifts'     => $drifts,
            'duration_ms'=> (int) round((microtime(true) - $started) * 1000),
        ];
    } else {
        $audit = $service->audit();

        $drifts   = (array) ($audit['drifts'] ?? []);
        $warnings = (array) ($audit['warnings'] ?? []);

        if ($node > 0) {
            $drifts = array_values(array_filter($drifts, static function (array $d) use ($node): bool {
                return (int) ($d['user_id'] ?? 0) === $node
                    || (int) ($d['node_user_id'] ?? 0) === $node;
            }));
            $warnings = array_values(array_filter($warnings, static function (array $w) use ($node): bool {
                return (int) ($w['user_id'] ?? 0) === $node;
            }));
        }

        $driftNum = count($drifts);
        $ok       = (bool) ($audit['ok'] ?? false) && $driftNum === 0;

        $exitCode = $ok ? 0 : 1;

        $report = [
            'php'           => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'timezone'      => date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s P'),
            'database'      => DB_NAME . ' @ ' . DB_HOST,
            'db_now'        => date('Y-m-d H:i:s'),
            'ok'            => $ok ? 'yes' : 'NO — ' . $driftNum . ' drift(s)',
            'drift_count'   => (string) $driftNum,
            'warning_count' => (string) count($warnings),
            'node'          => $node > 0 ? (string) $node : 'all',
            'limit'         => (string) $limit,
        ];

        $lines = binary_audit_format_report($report, false);

        foreach ($drifts as $drift) {
            $label = (string) ($drift['kind'] ?? 'drift');
            $target = (int) ($drift['user_id'] ?? $drift['activation'] ?? $drift['order'] ?? $drift['volume'] ?? $drift['award'] ?? 0);
            $lines[] = '    [' . $label . '] ' . (string) ($drift['message'] ?? '')
                . ($target > 0 ? ' (target #' . $target . ')' : '');
        }

        $lines[] = '  exit:      ' . $exitCode;
        $lines[] = '  duration:  ' . (int) round((microtime(true) - $started) * 1000) . 'ms';

        $log->write(
            $ok ? 'OK' : 'WARN',
            sprintf(
                'stream=binary audit=check trigger=%s node=%d limit=%d drift=%d ok=%s duration=%dms',
                $trigger,
                $node,
                $limit,
                $driftNum,
                $ok ? 'clean' : 'drift',
                (int) round((microtime(true) - $started) * 1000)
            )
        );

        $payload = [
            'ok'         => $ok,
            'trigger'    => $trigger,
            'audit'      => 'check',
            'drift'      => $driftNum,
            'warning'    => count($warnings),
            'exit_code'  => $exitCode,
            'drifts'     => $drifts,
            'duration_ms'=> (int) round((microtime(true) - $started) * 1000),
        ];
    }
} catch (Throwable $e) {
    $exitCode = 2;
    $log->write('ERROR', 'audit failed: ' . $e->getMessage());

    $lines = [
        'EcoWealth product-binary points audit FAILED',
        '  The audit could not run. See ' . $log->fileFor() . ' for the error.',
    ];

    if ($isCli) {
        fwrite(STDERR, 'Binary audit failed: ' . $e->getMessage() . PHP_EOL);
    }

    $payload = ['ok' => false, 'trigger' => $trigger, 'audit' => 'check', 'error' => 'The audit failed. See the log.'];
}

binary_audit_emit($isCli, $options, $lines, $payload);

exit($exitCode);
