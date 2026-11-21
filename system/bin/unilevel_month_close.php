<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — unilevel month close (plan §10 Phase 17F, §12.7, §14.8)
 *
 *  One implementation, three entry points:
 *
 *    CLI      php bin/unilevel_month_close.php [flags]       (Hostinger cron, SSH)
 *    Web      /system/cron/unilevel_month_close.php[?flags]  (local testing, URL cron)
 *    Windows  bin\unilevel_month_close.cmd [flags]           (Task Scheduler)
 *
 *  What it does: closes every completed month that is still open,
 *  oldest first — from the month of `unilevel_ledger_since` through
 *  last month. One close writes one `unilevel_activity` row per
 *  account and one `unilevel_periods` header, in one transaction
 *  (`UnilevelCycle::close()`, plan §14.8). A closed period is
 *  authoritative and immutable; there is no reopen.
 *
 *  It pays no money. The Active resolution already works without any
 *  close (the live fallback of §12.7); this job makes the snapshot
 *  explicit and auditable, and it runs even when the stream is off
 *  (`unilevel_enabled = 0`), because the periods must be ready the
 *  moment an operator turns it on.
 *
 *  Flags (CLI: --x=…, web: ?x=…):
 *    --period=YYYY-MM   close exactly that month (a completed month only)
 *    --dry-run          list what would close, change nothing
 *    --doctor           environment / settings / health report
 *    --json             machine-readable output
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
 *    15 0 1 * * /usr/bin/php /path/system/bin/unilevel_month_close.php
 *    15 0 1 * * curl -s "https://site/system/cron/unilevel_month_close.php?key=…"
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
 *   period:?string, dry-run:bool, json:bool, doctor:bool,
 *   quiet:bool, help:bool, log-dir:?string
 * }
 */
function close_options(bool $isCli): array
{
    $options = [
        'period'  => null,
        'dry-run' => false,
        'json'    => false,
        'doctor'  => false,
        'quiet'   => false,
        'help'    => false,
        'log-dir' => null,
    ];

    if ($isCli) {
        $parsed = getopt('', ['period:', 'dry-run', 'json', 'doctor', 'quiet', 'log-dir:', 'help']);

        if (!is_array($parsed)) {
            return $options;
        }

        foreach (['dry-run', 'json', 'doctor', 'quiet', 'help'] as $flag) {
            $options[$flag] = isset($parsed[$flag]);
        }

        if (isset($parsed['period']) && is_string($parsed['period'])) {
            $options['period'] = trim($parsed['period']);
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

    if (isset($_GET['period'])) {
        $options['period'] = trim((string) $_GET['period']);
    }

    return $options;
}

function close_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth unilevel month close',
        '',
        'Usage: php bin/unilevel_month_close.php [flags]',
        '',
        '  --period=YYYY-MM  close exactly that completed month',
        '  --dry-run         list what would close, change nothing',
        '  --json            machine-readable output',
        '  --doctor          environment / settings / health report',
        '  --log-dir=<dir>   where the monthly log is written',
        '  --quiet           no stdout (the log still records the run)',
        '  --help            this text',
        '',
        'Without --period it closes every open month from unilevel_ledger_since',
        'through last month, oldest first. A closed month is never rewritten.',
        '',
        'Web: /system/cron/unilevel_month_close.php?dry-run=1&json=1',
    ]);
}

/* --------------------------------------------------------------
 *  Access, output and reporting helpers
 * -------------------------------------------------------------- */

function close_deny_http(): never
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
function close_emit(bool $isCli, array $options, array $lines, array $payload): void
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
 * Which periods may this run touch? One rule for the dry run and the
 * real run, so the preview cannot promise something the run refuses.
 *
 * @return array{refused:?string, reason:?string, periods:list<string>, already:?string}
 */
function close_resolve(?string $requested): array
{
    if ($requested === null) {
        return [
            'refused' => null,
            'reason'  => null,
            'periods' => UnilevelCycle::pendingPeriods(),
            'already' => null,
        ];
    }

    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) !== 1) {
        return [
            'refused' => 'The period "' . $requested . '" is not a calendar month written YYYY-MM.',
            'reason'  => 'invalid',
            'periods' => [],
            'already' => null,
        ];
    }

    /* A closed period is immutable and there is no reopen (§10 Rule 7). */
    if (UnilevelCycle::isClosed($requested)) {
        return ['refused' => null, 'reason' => null, 'periods' => [], 'already' => $requested];
    }

    /* Only a completed month has a month's worth of paid_at
     * (§10 Rules 2 and 8). */
    $current = UnilevelCycle::periodOf();

    if ($requested >= $current) {
        return [
            'refused' => 'The period ' . $requested . ' has not finished yet'
                . ($requested > $current ? ' — it is in the future' : '')
                . ' (the current month is ' . $current . '); only a completed month can be closed.',
            'reason'  => 'not-complete',
            'periods' => [],
            'already' => null,
        ];
    }

    return ['refused' => null, 'reason' => null, 'periods' => [$requested], 'already' => null];
}

/**
 * Why a run may have nothing to do, stated truthfully.
 *
 * A fresh install's baseline is this month, so "everything through last
 * month is closed" would be wrong — nothing was ever open (plan §10
 * Rule 2).
 */
function close_pending_note(): string
{
    $since = trim((string) setting('unilevel_ledger_since', ''));
    $first = preg_match('/^\d{4}-\d{2}/', $since) === 1
        ? substr($since, 0, 7)
        : UnilevelCycle::previous(UnilevelCycle::periodOf());
    $last = UnilevelCycle::previous(UnilevelCycle::periodOf());

    return match (true) {
        $first > $last => 'nothing to close — the ledger baseline (' . $first
            . ') is after last month (' . $last . ')',
        $first === $last => 'nothing to close — ' . $last . ' is already closed',
        default => 'nothing to close — every month from ' . $first
            . ' through ' . $last . ' is already closed',
    };
}

/**
 * What one period would write, without writing it (dry run).
 *
 * The counts are the same aggregate `UnilevelCycle::close()` writes,
 * so a dry run cannot disagree with the real run.
 *
 * @return array{period:string, closed_already:bool, members:int, qualified:int, award_total:float}
 */
function close_project(string $period): array
{
    [$start, $end] = UnilevelCycle::bounds($period);
    $threshold     = UnilevelCycle::threshold();

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS members,
                COALESCE(SUM(CASE WHEN COALESCE(t.spent, 0.00) >= ? THEN 1 ELSE 0 END), 0) AS qualified
           FROM users u
           LEFT JOIN (
                 SELECT o.user_id, SUM(oi.line_total) AS spent
                   FROM shop_orders o
                   JOIN shop_order_items oi ON oi.order_id = o.id
                  WHERE o.paid_at >= ? AND o.paid_at < ?
                    AND o.status <> \'cancelled\'
                  GROUP BY o.user_id
           ) t ON t.user_id = u.id'
    );
    $stmt->execute([$threshold, $start, $end]);
    $row = $stmt->fetch() ?: [];

    $awardStmt = db()->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM unilevel_awards
          WHERE status = 'credited' AND paid_at >= ? AND paid_at < ?"
    );
    $awardStmt->execute([$start, $end]);

    return [
        'period'         => $period,
        'closed_already' => false,
        'members'        => (int) ($row['members'] ?? 0),
        'qualified'      => (int) ($row['qualified'] ?? 0),
        'award_total'    => round((float) $awardStmt->fetchColumn(), 2),
    ];
}

/**
 * The terminal "already closed" answer: exit 0, nothing written.
 *
 * @return array{lines:list<string>, payload:array<string, mixed>}
 */
function close_already(string $trigger, string $period, CronLog $log): array
{
    $log->write(
        'INFO',
        'trigger=' . $trigger . ' closed=0 period=' . $period . ' reason=already-closed'
    );

    return [
        'lines' => [
            'EcoWealth unilevel month close',
            '  period:  ' . $period,
            '  result:  already closed — a closed period is never rewritten.',
            '  nothing was written.',
        ],
        'payload' => [
            'ok'      => true,
            'trigger' => $trigger,
            'period'  => $period,
            'periods' => [$period],
            'closed'  => 0,
            'reason'  => 'already closed',
        ],
    ];
}

/**
 * The terminal "refused" answer: exit 1, nothing written.
 *
 * @return array{lines:list<string>, payload:array<string, mixed>}
 */
function close_refused(string $trigger, string $reason, string $message, ?string $period, CronLog $log): array
{
    $log->write('ERROR', sprintf(
        'trigger=%s refused period=%s reason=%s',
        $trigger,
        $period ?? '-',
        $reason
    ));

    return [
        'lines' => [
            'EcoWealth unilevel month close',
            '  refused: ' . $message,
            '  nothing was written.',
        ],
        'payload' => [
            'ok'      => false,
            'trigger' => $trigger,
            'period'  => $period,
            'refused' => true,
            'reason'  => $reason,
            'error'   => $message,
        ],
    ];
}

/**
 * Write the run's one log line (plan §10 Rule 11) and return nothing.
 *
 * `period` is the comma-separated list of the months the run touched;
 * `members`/`qualified` are the snapshot rows it wrote, summed across
 * a catch-up run, so the numbers equal the JSON payload's.
 *
 * @param array{periods:list<string>, closed:int, members:int, qualified:int,
 *              award_total:float, duration:int} $summary
 */
function close_write_log(CronLog $log, string $trigger, array $summary): void
{
    $log->write(
        $summary['closed'] > 0 ? 'OK' : 'INFO',
        sprintf(
            'trigger=%s closed=%d period=%s members=%d qualified=%d award_total=%.2f duration=%dms',
            $trigger,
            $summary['closed'],
            $summary['periods'] === [] ? '-' : implode(',', $summary['periods']),
            $summary['members'],
            $summary['qualified'],
            $summary['award_total'],
            $summary['duration']
        )
    );
}

/** @return array<string, string> */
function close_doctor(CronLock $lock, CronLog $log): array
{
    $dbNow      = 'unavailable';
    $dbTz       = 'unavailable';
    $clocks     = 'unavailable';
    $since      = 'unavailable';
    $lastClosed = 'unavailable';
    $pending    = 'unavailable';
    $members    = 'unavailable';

    try {
        $dbNow  = (string) db()->query('SELECT NOW()')->fetchColumn();
        $dbTz   = (string) db()->query('SELECT @@session.time_zone')->fetchColumn();
        $since  = (string) (setting('unilevel_ledger_since', '') ?: '—');
        $closed = UnilevelCycle::lastClosed();
        $list   = UnilevelCycle::pendingPeriods();

        /* The one silent failure mode: PHP and MySQL disagree about
         * "now" (§17 risk 11). Compare the epochs, not the strings. */
        $drift  = (int) db()->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn() - time();
        $clocks = abs($drift) <= 5
            ? 'agree (' . ($drift > 0 ? '+' : '') . $drift . 's)'
            : $drift . 's apart — check DB_TIMEZONE and the host clock';

        $lastClosed = $closed ?? 'none yet';
        $pending    = $list === [] ? 'none (' . close_pending_note() . ')' : implode(', ', $list) . ' (' . count($list) . ')';
        $members    = (string) (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
        error_log('[ecowealth] unilevel close doctor: ' . $e->getMessage());
    }

    $lockState = $lock->status();
    $lockText  = $lockState['state'] . ' · ' . $lock->path();

    if ($lockState['state'] === 'unavailable' && $lockState['detail'] !== '') {
        $lockText .= ' — ' . $lockState['detail'];
    }

    return [
        'php'          => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'timezone'     => date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s P'),
        'database'     => DB_NAME . ' @ ' . DB_HOST,
        'db_now'       => $dbNow . ' (session ' . $dbTz . ')',
        'clocks'       => $clocks,
        'period_now'   => UnilevelCycle::periodOf() . ' — the current month is never closed implicitly',
        'threshold'    => money(UnilevelCycle::threshold()) . ' personal purchases',
        'ledger_since' => $since,
        'last_closed'  => $lastClosed,
        'pending'      => $pending,
        'members'      => $members . ' accounts get a snapshot row',
        'enabled'      => setting_bool('unilevel_enabled', false)
            ? 'yes'
            : 'no — periods still close (the switch gates payouts, not the close)',
        'lock'         => $lockText,
        'log'          => $log->fileFor() . ($log->isWritable() ? ' (writable)' : ' (NOT writable)'),
        'cron_key'     => CronAccess::configuredKey() === null ? 'not set (loopback only)' : 'set',
        'storage'      => (string) STORAGE_PATH,
    ];
}

/** @param array<string, string> $report @return list<string> */
function close_format_doctor(array $report): array
{
    $lines = ['EcoWealth unilevel month close — doctor'];
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

$options = close_options($isCli);

if ($options['period'] === '') {
    $options['period'] = null;
}

$trigger = $isCli ? 'cli' : 'http';

if (!$isCli) {
    $allowed = CronAccess::allowed(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        isset($_GET['key']) ? (string) $_GET['key'] : null,
        CronAccess::configuredKey()
    );

    if (!$allowed) {
        close_deny_http();
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

$lock  = new CronLock('unilevel_month_close');
$log   = new CronLog($isCli ? $options['log-dir'] : null, 'unilevel_month_close');

$started  = microtime(true);
$exitCode = 0;
$lines    = [];
$payload  = ['ok' => true, 'trigger' => $trigger];

/* --------------------------------------------------------------
 *  Run (no exit() inside the try, so the lock is always released)
 * -------------------------------------------------------------- */

try {
    if ($options['help']) {
        $lines   = [close_usage()];
        $payload = ['ok' => true, 'usage' => close_usage()];
    } elseif ($options['doctor']) {
        $report  = close_doctor($lock, $log);
        $lines   = close_format_doctor($report);
        $payload = ['ok' => true, 'trigger' => $trigger, 'doctor' => $report];
    } elseif ($options['dry-run']) {
        $resolved = close_resolve($options['period']);

        if ($resolved['refused'] !== null) {
            $exitCode = 1;
            $answer   = close_refused($trigger, (string) $resolved['reason'], $resolved['refused'], $options['period'], $log);
            $lines    = $answer['lines'];
            $payload  = $answer['payload'];

            /* --quiet asked for no stdout, so a refusal still reaches the
             * operator's cron mail through stderr. */
            if ($isCli && $options['quiet']) {
                fwrite(STDERR, $resolved['refused'] . PHP_EOL);
            }
        } elseif ($resolved['already'] !== null) {
            $answer  = close_already($trigger, (string) $resolved['already'], $log);
            $lines   = $answer['lines'];
            $payload = $answer['payload'];
            $payload['dry_run'] = true;
        } else {
            $projected = [];

            foreach ($resolved['periods'] as $period) {
                $projected[] = close_project($period);
            }

            $lines = [
                'EcoWealth unilevel month close (dry run — nothing is written)',
                '  database:    ' . DB_NAME . ' (' . DB_HOST . ')',
                '  threshold:   ' . money(UnilevelCycle::threshold()) . ' personal purchases',
                '  would close: ' . ($projected === []
                    ? close_pending_note()
                    : (string) count($projected)),
            ];

            foreach ($projected as $row) {
                $lines[] = sprintf(
                    '  %s  members %d  qualified %d  award_total %s',
                    $row['period'],
                    $row['members'],
                    $row['qualified'],
                    money($row['award_total'])
                );
            }

            $payload = [
                'ok'          => true,
                'trigger'     => $trigger,
                'dry_run'     => true,
                'would_close' => count($projected),
                'periods'     => array_column($projected, 'period'),
                'projected'   => $projected,
                'threshold'   => UnilevelCycle::threshold(),
            ];

            $log->write('INFO', sprintf(
                'dry-run trigger=%s would_close=%d periods=%s',
                $trigger,
                count($projected),
                $projected === [] ? '-' : implode(',', array_column($projected, 'period'))
            ));
        }
    } elseif (!$lock->acquire()) {
        $lines = [
            'EcoWealth unilevel month close',
            '  skipped: another run is already in progress.',
        ];

        $payload = [
            'ok'      => true,
            'trigger' => $trigger,
            'skipped' => 'locked',
        ];

        $log->write('SKIP', 'trigger=' . $trigger . ' skipped: another run holds ' . $lock->path());
    } else {
        $resolved = close_resolve($options['period']);

        if ($resolved['refused'] !== null) {
            $exitCode = 1;
            $answer   = close_refused($trigger, (string) $resolved['reason'], $resolved['refused'], $options['period'], $log);
            $lines    = $answer['lines'];
            $payload  = $answer['payload'];

            if ($isCli && $options['quiet']) {
                fwrite(STDERR, $resolved['refused'] . PHP_EOL);
            }
        } elseif ($resolved['already'] !== null) {
            $answer  = close_already($trigger, (string) $resolved['already'], $log);
            $lines   = $answer['lines'];
            $payload = $answer['payload'];
        } else {
            /* One transaction per period, oldest first (plan §10 Rules 2
             * and 3). close() is the whole snapshot; this job only
             * sequences the months and reports them. */
            $closed  = 0;
            $rows    = 0;
            $qual    = 0;
            $award   = 0.0;
            $done    = [];
            $results = [];

            foreach ($resolved['periods'] as $period) {
                $result    = UnilevelCycle::close($period);
                $results[] = $result;

                if ((int) $result['closed'] !== 1) {
                    continue;
                }

                $closed++;
                $rows  += (int) $result['members'];
                $qual  += (int) $result['qualified'];
                $award += (float) $result['award_total'];
                $done[] = $period;
            }

            $duration = (int) round((microtime(true) - $started) * 1000);

            $lines = [
                'EcoWealth unilevel month close',
                '  database:    ' . DB_NAME . ' (' . DB_HOST . ')',
                '  trigger:     ' . $trigger,
                '  threshold:   ' . money(UnilevelCycle::threshold()) . ' personal purchases',
                '  closed:      ' . $closed . ($closed === 0 ? ' — nothing was pending' : ''),
                '  periods:     ' . ($done === [] ? '—' : implode(', ', $done)),
            ];

            foreach ($results as $result) {
                if ((int) $result['closed'] !== 1) {
                    continue;
                }

                $lines[] = sprintf(
                    '    %s  members %d  qualified %d  award_total %s',
                    (string) $result['period'],
                    (int) $result['members'],
                    (int) $result['qualified'],
                    money((float) $result['award_total'])
                );
            }

            $lines[] = sprintf(
                '  snapshot:    %d rows · %d qualified · %s',
                $rows,
                $qual,
                money($award)
            );
            $lines[] = '  duration:    ' . $duration . 'ms';
            $lines[] = '  log:         ' . $log->fileFor();
            $lines[] = $closed > 0
                ? '  closed ' . $closed . ' period(s) · ' . $rows . ' members · '
                    . $qual . ' qualified · ' . money($award) . ' in ' . $duration . 'ms'
                : '  ' . close_pending_note();

            $payload = [
                'ok'          => true,
                'trigger'     => $trigger,
                'dry_run'     => false,
                'closed'      => $closed,
                'periods'     => $done,
                'members'     => $rows,
                'qualified'   => $qual,
                'award_total' => round($award, 2),
                'threshold'   => UnilevelCycle::threshold(),
                'duration_ms' => $duration,
                'log'         => $log->fileFor(),
                'results'     => $results,
            ];

            close_write_log($log, $trigger, [
                'periods'     => $done,
                'closed'      => $closed,
                'members'     => $rows,
                'qualified'   => $qual,
                'award_total' => $award,
                'duration'    => $duration,
            ]);
        }
    }
} catch (Throwable $e) {
    $exitCode = 1;

    $log->write('ERROR', 'trigger=' . $trigger . ' run failed: ' . $e->getMessage());

    $lines = [
        'Unilevel month close FAILED',
        '  The job could not run. See ' . $log->fileFor() . ' for the error.',
    ];

    if ($isCli) {
        fwrite(STDERR, 'Unilevel month close failed: ' . $e->getMessage() . PHP_EOL);
    }

    $payload = [
        'ok'      => false,
        'trigger' => $trigger,
        'error'   => 'The month close job failed. See the log.',
    ];
} finally {
    $lock->release();
}

close_emit($isCli, $options, $lines, $payload);

exit($exitCode);
