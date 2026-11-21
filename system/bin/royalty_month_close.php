<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — Royalty month close (plan Phase 19C §12.6 §14.10;
 *  Phase 19F adds the doctor, the web entry and the operations payload)
 *
 *  One implementation, three entry points:
 *
 *    CLI      php bin/royalty_month_close.php [flags]       (Hostinger cron, SSH)
 *    Web      /system/cron/royalty_month_close.php[?flags]  (local testing, URL cron)
 *    Windows  bin\royalty_month_close.cmd [flags]           (Task Scheduler)
 *
 *  The one job that moves Royalty money.
 *
 *  What it does, in order:
 *
 *    1. resume every `evaluated` period — pay what is still pending;
 *    2. close every completed month from the baseline through last
 *       month that has no header, oldest first: evaluate, then pay.
 *
 *  A month is evaluated at its own period-end cutoff and its own
 *  volume window, so a catch-up run produces exactly the awards an
 *  on-time run would have produced (plan §12.6.6). Evaluation writes
 *  no wallet money; payment is the only creditor, in batches, and a
 *  crash mid-period resumes from the first pending award.
 *
 *  Flags (CLI: --x=…, web: ?x=…):
 *    --period=YYYY-MM    close (or resume/pay) exactly that month
 *    --dry-run           compute and print; write nothing (no rows, no
 *                        wallet, no audit)
 *    --evaluate-only     write the award rows and the header; do not pay
 *    --pay-only          pay an already-evaluated month; resume a
 *                        partial payment
 *    --limit=N           payment batch size, 1–5000 (default 500)
 *    --max-batches=N     stop after N batches (default 20) and leave the
 *                        month `evaluated`; the next run continues
 *    --json              machine-readable output
 *    --doctor            environment / settings / health report
 *    --quiet             no stdout (the log still records the run; CLI only)
 *    --log-dir=<dir>     where the monthly log is written (CLI only)
 *    --help              usage
 *
 *  Access (web): a loopback request from the same machine is trusted, so
 *  a local browser tab just works. Any other caller needs ?key= matching
 *  CRON_KEY in app/config.local.php. Without a configured key, remote web
 *  callers are refused. The CLI is always allowed.
 *
 *  Exit codes: 0 success / no-op / busy / paused; 1 processing or
 *  validation error; 2 environment (database down, missing tables).
 *  The web entry answers HTTP 200 for exit 0 and 500 for 1 and 2, and
 *  keeps the exit code in the JSON payload.
 *
 *  Cron (plan §10 Rule 10; see docs/cron.md §7):
 *    30 0 1 * * /usr/bin/php /path/system/bin/royalty_month_close.php --max-batches=20
 *
 *  This file also serves as the library: the web entry requires it, and
 *  the test harness (ROYALTY_CLOSE_LIBRARY) loads the functions without
 *  running a job.
 * ============================================================== */

$root = dirname(__DIR__);

require_once $root . '/app/config.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/autoload.php';
require_once $root . '/app/database.php';

date_default_timezone_set(APP_TIMEZONE);

/* The web entry defines ROYALTY_CLOSE_HTTP (see public/cron/…): the file
 * is the same, the way in is not. A request that reaches bin/ over HTTP
 * without that marker is still treated as a web request, never as the
 * trusted CLI. */
$isCli = !defined('ROYALTY_CLOSE_HTTP') && PHP_SAPI === 'cli';

/* --------------------------------------------------------------
 *  Constants
 * -------------------------------------------------------------- */

/* The sixteen settings a close reads and the two tables it owns are
 * listed once, in RoyaltyOps (the doctor, the health check and the
 * operations panel read the same list). */

/* --------------------------------------------------------------
 *  Options and helpers
 * -------------------------------------------------------------- */

/**
 * @return array{
 *   period:?string, dry-run:bool, evaluate-only:bool, pay-only:bool,
 *   json:bool, quiet:bool, help:bool, doctor:bool, limit:int,
 *   max-batches:int, log-dir:?string
 * }
 */
function royalty_close_options(bool $isCli): array
{
    $options = [
        'period'        => null,
        'dry-run'       => false,
        'evaluate-only' => false,
        'pay-only'      => false,
        'json'          => false,
        'quiet'         => false,
        'help'          => false,
        'doctor'        => false,
        'limit'         => 500,
        'max-batches'   => 20,
        'log-dir'       => null,
    ];

    $booleans = ['dry-run', 'evaluate-only', 'pay-only', 'json', 'quiet', 'help', 'doctor'];

    if ($isCli) {
        $parsed = getopt('', [
            'period:', 'dry-run', 'evaluate-only', 'pay-only', 'json', 'quiet',
            'help', 'doctor', 'limit:', 'max-batches:', 'log-dir:',
        ]);

        if (!is_array($parsed)) {
            return $options;
        }

        foreach ($booleans as $flag) {
            $options[$flag] = isset($parsed[$flag]);
        }

        foreach (['period', 'log-dir'] as $flag) {
            if (isset($parsed[$flag]) && is_string($parsed[$flag]) && trim($parsed[$flag]) !== '') {
                $options[$flag] = trim($parsed[$flag]);
            }
        }

        if (isset($parsed['limit']) && is_string($parsed['limit'])) {
            $options['limit'] = (int) $parsed['limit'];
        }

        if (isset($parsed['max-batches']) && is_string($parsed['max-batches'])) {
            $options['max-batches'] = (int) $parsed['max-batches'];
        }
    } else {
        /* Web: every flag is a query parameter. A boolean is on unless it
         * is explicitly one of the "off" spellings — the same rule the
         * other cron entries use. */
        foreach ($booleans as $flag) {
            $value = $_GET[$flag] ?? null;

            if ($value !== null
                && !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off', ''], true)) {
                $options[$flag] = true;
            }
        }

        foreach (['period', 'limit', 'max-batches'] as $flag) {
            if (isset($_GET[$flag]) && is_scalar($_GET[$flag]) && trim((string) $_GET[$flag]) !== '') {
                if ($flag === 'period') {
                    $options['period'] = trim((string) $_GET[$flag]);
                } else {
                    $options[$flag] = (int) $_GET[$flag];
                }
            }
        }

        /* A web caller never chooses the log path. */
        $options['log-dir'] = null;
    }

    /* A malformed or out-of-range batch size is clamped, never trusted
     * (plan §15.1). */
    $options['limit']       = max(1, min(5000, (int) $options['limit']));
    $options['max-batches'] = max(1, min(1000, (int) $options['max-batches']));

    if ($options['pay-only'] && $options['evaluate-only']) {
        $options['pay-only'] = false;
    }

    return $options;
}

function royalty_close_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth Royalty month close',
        '',
        'Usage: php bin/royalty_month_close.php [flags]',
        '',
        '  --period=YYYY-MM  close (or resume/pay) exactly that completed month',
        '  --dry-run         compute and print; write nothing (no rows, no wallet, no audit)',
        '  --evaluate-only   write awards and the header; do not pay',
        '  --pay-only        pay an already-evaluated month; resume a partial payment',
        '  --limit=N         payment batch size, 1–5000 (default 500)',
        '  --max-batches=N   stop after N batches (default 20) and leave the month evaluated',
        '  --json            machine-readable output',
        '  --doctor          environment / settings / health report',
        '  --quiet           no stdout (the log still records the run)',
        '  --log-dir=<dir>   where the monthly log is written',
        '  --help            this text',
        '',
        'Without --period it resumes every evaluated month, then closes every',
        'completed month from the baseline through last month, oldest first.',
        'The current month is refused; a final month is never recalculated.',
        '',
        'Web: /system/cron/royalty_month_close.php?doctor=1&json=1',
    ]);
}

/** @param array<string, mixed> $options @param list<string> $lines @param array<string, mixed> $payload */
function royalty_close_emit(bool $isCli, array $options, array $lines, array $payload): void
{
    /* --quiet is a CLI flag: a browser always gets its body. */
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

/* --------------------------------------------------------------
 *  HTTP access (plan §10 Rules 1 and 7)
 * -------------------------------------------------------------- */

/**
 * May this HTTP request run the job?
 *
 * Loopback is trusted; everything else needs the configured CRON_KEY.
 * Pure, so the decision is testable without a web server.
 *
 * @return array{status:int, body:string, run:bool}
 */
function royalty_close_http_gate(string $remoteAddr, ?string $providedKey, ?string $configuredKey): array
{
    if (CronAccess::allowed($remoteAddr, $providedKey, $configuredKey)) {
        return ['status' => 200, 'body' => '', 'run' => true];
    }

    return ['status' => 403, 'body' => 'Forbidden', 'run' => false];
}

/** The HTTP status a finished run answers with (plan §10 Rule 7). */
function royalty_close_http_status(int $exitCode): int
{
    return $exitCode === 0 ? 200 : 500;
}

/** Send the cron response headers once (never a page, never cached). */
function royalty_close_http_headers(int $status, bool $json): void
{
    if (headers_sent()) {
        return;
    }

    http_response_code($status);
    header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex');
    header('X-Content-Type-Options: nosniff');
}

/* --------------------------------------------------------------
 *  Which periods may this run touch?
 * -------------------------------------------------------------- */

/**
 * One rule for the dry run and the real run, so a preview cannot
 * promise something the run refuses.
 *
 * @return array{
 *   refused:?string, reason:?string, already:?string,
 *   resume:list<string>, pending:list<string>, targets:list<string>
 * }
 */
function royalty_close_resolve(?string $requested): array
{
    if ($requested === null) {
        $resume  = RoyaltyPeriod::evaluatedPeriods();
        $pending = RoyaltyPeriod::pendingPeriods();

        return [
            'refused' => null,
            'reason'  => null,
            'already' => null,
            'resume'  => $resume,
            'pending' => $pending,
            'targets' => array_merge($resume, $pending),
        ];
    }

    $refused = static fn (string $reason, string $message): array => [
        'refused' => $message,
        'reason'  => $reason,
        'already' => null,
        'resume'  => [],
        'pending' => [],
        'targets' => [],
    ];

    if (!RoyaltyPeriod::isPeriod($requested)) {
        return $refused(
            'invalid',
            'The period "' . $requested . '" is not a calendar month written YYYY-MM.'
        );
    }

    $current = RoyaltyPeriod::periodOf();

    if ($requested > $current) {
        return $refused(
            'future',
            'The period ' . $requested . ' is in the future (the current month is '
                . $current . '); only a completed month can be closed.'
        );
    }

    if ($requested === $current) {
        return $refused(
            'current',
            'The period ' . $requested . ' has not finished yet (the current month is '
                . $current . '); only a completed month can be closed.'
        );
    }

    $baseline = RoyaltyPeriod::baseline();

    if ($requested < $baseline) {
        return $refused(
            'baseline',
            'The period ' . $requested . ' is before the Royalty ledger baseline (' . $baseline
                . '); months before the baseline are never paid (plan D-15).'
        );
    }

    $status = RoyaltyPeriod::status($requested);

    if ($status === null) {
        return [
            'refused' => null,
            'reason'  => null,
            'already' => null,
            'resume'  => [],
            'pending' => [$requested],
            'targets' => [$requested],
        ];
    }

    if ($status === 'evaluated') {
        return [
            'refused' => null,
            'reason'  => null,
            'already' => null,
            'resume'  => [$requested],
            'pending' => [],
            'targets' => [$requested],
        ];
    }

    return [
        'refused' => null,
        'reason'  => null,
        'already' => $requested,
        'resume'  => [],
        'pending' => [],
        'targets' => [],
    ];
}

/** Why a run may have nothing to do, stated truthfully. */
function royalty_close_pending_note(): string
{
    $first = RoyaltyPeriod::baseline();
    $last  = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());

    return match (true) {
        $first > $last => 'nothing to close — the ledger baseline (' . $first
            . ') is after last month (' . $last . ')',
        $first === $last => 'nothing to close — ' . $last . ' is already final',
        default => 'nothing to close — every month from ' . $first
            . ' through ' . $last . ' is already final',
    };
}

/**
 * The terminal "already final" answer: exit 0, nothing written.
 *
 * @return array{lines:list<string>, payload:array<string, mixed>}
 */
function royalty_close_already(string $trigger, string $period, CronLog $log): array
{
    $header = RoyaltyPeriod::find($period);
    $status = $header === null ? 'paid' : (string) $header['status'];
    $paid   = $header === null ? 0 : (int) $header['paid_count'];
    $total  = $header === null ? 0.0 : (float) $header['paid_total'];

    $log->write('INFO', 'trigger=' . $trigger . ' closed=0 period=' . $period
        . ' reason=already-final status=' . $status);

    return [
        'lines' => [
            'EcoWealth Royalty month close',
            '  period:   ' . $period,
            '  result:   already final (' . RoyaltyPeriod::label($status) . ') — a closed month is never rewritten.',
            '  paid:     ' . $paid . ' award(s) · ' . money($total),
            '  nothing was written.',
        ],
        'payload' => [
            'ok'         => true,
            'trigger'    => $trigger,
            'period'     => $period,
            'periods'    => [$period],
            'closed'     => 0,
            'status'     => $status,
            'paid'       => 0,
            'amount'     => 0.0,
            'award'      => 0,
            'skipped'    => 0,
            'reason'     => 'already final',
            'final'      => true,
        ],
    ];
}

/**
 * The terminal "refused" answer: exit 1, nothing written.
 *
 * @return array{lines:list<string>, payload:array<string, mixed>}
 */
function royalty_close_refused(string $trigger, string $reason, string $message, ?string $period, CronLog $log): array
{
    $log->write('ERROR', sprintf(
        'trigger=%s refused period=%s reason=%s',
        $trigger,
        $period ?? '-',
        $reason
    ));

    return [
        'lines' => [
            'EcoWealth Royalty month close',
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
 * One log line per run (plan §14.10), with the same numbers the JSON
 * payload carries.
 *
 * @param array{closed:int, periods:list<string>, award:int, paid:int,
 *              amount:float, skipped:int, status:string, paused:bool} $summary
 */
function royalty_close_write_log(CronLog $log, string $trigger, string $level, array $summary): void
{
    $written = $log->write($level, sprintf(
        'trigger=%s closed=%d periods=%s award=%d paid=%d amount=%.2f skipped=%d status=%s paused=%d',
        $trigger,
        $summary['closed'],
        $summary['periods'] === [] ? '-' : implode(',', $summary['periods']),
        $summary['award'],
        $summary['paid'],
        $summary['amount'],
        $summary['skipped'],
        $summary['status'],
        $summary['paused'] ? 1 : 0
    ));

    if ($written) {
        return;
    }

    /* A read-only host must not fail the run, but it must not be silent
     * either (plan §10: "the run continues; stderr warns"). error_log
     * reaches stderr on the CLI and PHP's error log under Apache, so the
     * same line serves both entries and stays testable. */
    error_log('[ecowealth] [WARN ] the Royalty log could not be written to '
        . $log->fileFor() . '; the run continued.');
}

/* --------------------------------------------------------------
 *  The doctor (plan §14.10, Phase 19F §10 Rule 6)
 * -------------------------------------------------------------- */

/**
 * Does the PHP clock agree with MySQL's? Compared as epochs, never as
 * strings — the one silent failure mode is PHP and MySQL disagreeing
 * about "now" (plan §14.10).
 *
 * @return array{ok:bool, text:string}
 */
function royalty_close_clock_state(int $phpNow, int $dbEpoch): array
{
    $drift = $dbEpoch - $phpNow;

    if (abs($drift) <= 2) {
        return ['ok' => true, 'text' => 'agree (' . ($drift > 0 ? '+' : '') . $drift . 's)'];
    }

    return [
        'ok'   => false,
        'text' => $drift . 's apart — check DB_TIMEZONE and the host clock',
    ];
}

/**
 * Is the configured ladder usable? Returns the first problem, or null.
 *
 * The settings form validates a save; the doctor re-checks the stored
 * values, because a hand-edited row must not reach a payout.
 */
function royalty_close_ladder_problem(): ?string
{
    $ladder = RoyaltyRank::ladder();

    foreach (['leader', 'supervisor', 'manager', 'director'] as $rank) {
        if ((int) $ladder['thresholds'][$rank] < 1) {
            $label = ucfirst($rank);

            return 'the ' . $label . ' requirement is below 1 member';
        }
    }

    foreach (RoyaltyRank::PAYING_SLUGS as $slug) {
        if ((float) $ladder['quotas'][$slug] < 0) {
            return ucfirst($slug) . ' quota is negative';
        }

        foreach (['group', 'personal'] as $kind) {
            $rate = (float) $ladder['rates'][$slug][$kind];

            if ($rate < 0 || $rate > 100) {
                return ucfirst($slug) . ' ' . $kind . ' rate is outside 0–100%';
            }
        }

        if ((float) $ladder['own_pct'][$slug] > 100) {
            return ucfirst($slug) . ' group + personal rate exceeds 100%';
        }
    }

    if ($ladder['quotas']['manager'] < $ladder['quotas']['supervisor']) {
        return 'the Manager quota is below the Supervisor quota';
    }

    if ($ladder['quotas']['director'] < $ladder['quotas']['manager']) {
        return 'the Director quota is below the Manager quota';
    }

    return null;
}

/**
 * The environment / settings / health report (plan §14.10).
 *
 * $phpNow is injectable so a test can prove the clock-skew rule without
 * touching the host clock; every other check reads the real database.
 *
 * @return array{
 *   ok:bool, status:string,
 *   checks:list<array{key:string, value:string, state:string}>,
 *   errors:list<string>, warnings:list<string>
 * }
 */
function royalty_close_doctor(CronLock $lock, CronLog $log, ?int $phpNow = null): array
{
    $phpNow   = $phpNow ?? time();
    $checks   = [];
    $errors   = [];
    $warnings = [];

    /**
     * @param 'ok'|'warn'|'fail' $state
     */
    $add = static function (string $key, string $value, string $state = 'ok', string $message = '') use (&$checks, &$errors, &$warnings): void {
        $checks[] = ['key' => $key, 'value' => $value, 'state' => $state];

        if ($state === 'fail' && $message !== '') {
            $errors[] = $message;
        }

        if ($state === 'warn' && $message !== '') {
            $warnings[] = $message;
        }
    };

    $add('php', PHP_VERSION . ' (' . PHP_SAPI . ')');

    /* --- the database and its clock ------------------------------ */
    $dbNow   = null;
    $dbEpoch = null;
    $dbTz    = 'unavailable';

    try {
        $dbNow   = (string) db()->query('SELECT NOW()')->fetchColumn();
        $dbTz    = (string) db()->query('SELECT @@session.time_zone')->fetchColumn();
        $dbEpoch = (int) db()->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn();
        $version = (string) db()->query('SELECT VERSION()')->fetchColumn();

        $add('mysql', $version . ' (session ' . $dbTz . ')');
    } catch (Throwable $e) {
        $add('mysql', 'unavailable — ' . $e->getMessage(), 'fail', 'MySQL is unreachable: ' . $e->getMessage());
    }

    if ($dbEpoch !== null) {
        $clock = royalty_close_clock_state($phpNow, $dbEpoch);
        $add('clocks', $clock['text'], $clock['ok'] ? 'ok' : 'fail',
            $clock['ok'] ? '' : 'the PHP and MySQL clocks disagree by more than 2 seconds');

        $add('db_now', (string) $dbNow . ' (session ' . $dbTz . ')');
    }

    $zone  = date_default_timezone_get();
    $sqlTz = (string) $dbTz;
    $tzOk  = $zone === 'Asia/Manila' && $sqlTz === '+08:00';

    $add(
        'timezone',
        $zone . ' · ' . date('P') . ' (MySQL session ' . $sqlTz . ')',
        $tzOk ? 'ok' : 'fail',
        $tzOk ? '' : 'the time zone is not Asia/Manila (PHP) / +08:00 (MySQL): PHP is ' . $zone
            . ', the MySQL session is ' . $sqlTz
    );

    /* --- the tables, the settings and the ladder ----------------- */
    $tables = [];

    try {
        $tables = db()->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('royalty_periods', 'royalty_awards')"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $tables = [];
    }

    $missing = array_values(array_diff(RoyaltyOps::TABLES, array_map('strval', $tables)));
    $add(
        'tables',
        $missing === []
            ? 'royalty_periods, royalty_awards present'
            : 'MISSING: ' . implode(', ', $missing),
        $missing === [] ? 'ok' : 'fail',
        $missing === [] ? '' : 'the Royalty table(s) ' . implode(', ', $missing)
            . ' are missing; apply migration 023_royalty_bonus.sql'
    );

    if ($missing === []) {
        $present = [];

        try {
            $rows = db()->query(
                "SELECT key_name FROM settings WHERE key_name LIKE 'royalty\\_%'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $present = array_map('strval', $rows);
        } catch (Throwable $e) {
            $present = [];
        }

        $absent = array_values(array_diff(RoyaltyOps::SETTING_KEYS, $present));

        if ($absent !== []) {
            $add('settings', count($absent) . ' missing: ' . implode(', ', $absent), 'fail',
                'missing Royalty setting(s): ' . implode(', ', $absent));
        } else {
            $problem = royalty_close_ladder_problem();

            $add(
                'settings',
                '16/16 present; ' . ($problem === null ? 'ladder valid' : 'ladder INVALID — ' . $problem),
                $problem === null ? 'ok' : 'fail',
                $problem === null ? '' : 'the Royalty ladder is invalid: ' . $problem
            );
        }

        /* --- the baseline ---------------------------------------- */
        $since = trim((string) setting('royalty_ledger_since', ''));
        $validSince = preg_match(
            '/^(\d{4})-(0[1-9]|1[0-2])-\d{2} \d{2}:\d{2}:\d{2}$/',
            $since
        ) === 1;

        $add(
            'ledger since',
            $since === ''
                ? 'MISSING — nothing before the current month is payable'
                : $since . ' → first payable ' . RoyaltyPeriod::baseline(),
            $validSince ? 'ok' : 'fail',
            $validSince ? '' : 'royalty_ledger_since is missing or malformed; nothing before the current month is payable'
        );

        /* --- the switches ---------------------------------------- */
        $enabled = RoyaltyService::enabled();
        $paying  = RoyaltyService::paymentsEnabled();

        $add(
            'enabled',
            'royalty_enabled=' . ($enabled ? '1' : '0') . ' · royalty_pay_enabled=' . ($paying ? '1' : '0'),
            $paying ? 'ok' : 'warn',
            $paying ? '' : 'payments are paused (royalty_pay_enabled = 0); awards stay pending until the switch is on'
        );

        /* --- the period pipeline --------------------------------- */
        $periods = RoyaltyPeriod::periods(120);
        $paid    = [];
        $final   = [];
        $evaluated = [];

        foreach ($periods as $row) {
            $status = (string) $row['status'];

            if ($status === 'paid') {
                $paid[] = $row;
            }

            if (in_array($status, RoyaltyPeriod::FINAL, true)) {
                $final[] = $row;
            }

            if ($status === 'evaluated') {
                $evaluated[] = $row;
            }
        }

        $lastPaid = $paid === [] ? null : $paid[0];

        $add(
            'last paid',
            $lastPaid === null
                ? 'none yet'
                : (string) $lastPaid['period'] . ' (paid ' . (string) ($lastPaid['paid_at'] ?? '—') . ')',
            'ok'
        );

        $lastFinal = $final === [] ? null : $final[0];
        $add(
            'last final',
            $lastFinal === null
                ? 'none yet'
                : (string) $lastFinal['period'] . ' — ' . RoyaltyPeriod::label((string) $lastFinal['status'])
                    . ' (evaluated ' . (string) $lastFinal['evaluated_at'] . ')',
            'ok'
        );

        $pending = RoyaltyPeriod::pendingPeriods();

        $add(
            'pending',
            $pending === [] ? 'none (' . royalty_close_pending_note() . ')' : implode(', ', $pending) . ' (' . count($pending) . ')',
            $pending === [] ? 'ok' : 'warn',
            $pending === [] ? '' : count($pending) . ' completed month(s) have no Royalty period yet; the next run closes them'
        );

        $evaluatedLines = [];

        foreach ($evaluated as $row) {
            $rows    = RoyaltyAward::pendingCount((string) $row['period']);
            $age     = royalty_close_age_hours((string) $row['evaluated_at'], $phpNow);
            $evaluatedLines[] = (string) $row['period'] . ' (' . $rows . ' pending'
                . ($age === null ? '' : ', ' . $age . 'h old') . ')';
        }

        $add(
            'evaluated',
            $evaluatedLines === [] ? 'none' : implode(', ', $evaluatedLines),
            $evaluatedLines === [] ? 'ok' : 'warn',
            $evaluatedLines === [] ? '' : count($evaluated)
                . ' evaluated month(s) are awaiting payment; run --pay-only to finish them'
        );

        /* Rule 6: a paid period must never still have a pending award. */
        $stuck = [];

        foreach ($paid as $row) {
            if (RoyaltyAward::pendingCount((string) $row['period']) > 0) {
                $stuck[] = (string) $row['period'];
            }
        }

        $add(
            'paid awards',
            $stuck === [] ? 'every paid month has no pending award' : 'PENDING in ' . implode(', ', $stuck),
            $stuck === [] ? 'ok' : 'fail',
            $stuck === [] ? '' : 'paid month(s) ' . implode(', ', $stuck) . ' still have a pending award'
        );

        /* --- counts ---------------------------------------------- */
        $members = 0;
        $awards  = 0;

        try {
            $members = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
            $awards  = (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn();
        } catch (Throwable $e) {
            /* the tables check already reported the cause */
        }

        $add('counts', $members . ' member(s) · ' . $awards . ' award row(s) · ' . count($periods) . ' period(s)');
    }

    /* --- the lock, the log and the storage ---------------------- */
    $lockState = $lock->status();
    $lockText  = $lockState['state'] . ' · ' . $lock->path();

    if ($lockState['state'] === 'unavailable' && $lockState['detail'] !== '') {
        $lockText .= ' — ' . $lockState['detail'];
    }

    $add(
        'lock',
        $lockText,
        $lockState['state'] === 'unavailable' ? 'warn' : 'ok',
        $lockState['state'] === 'unavailable' ? 'the lock folder is not usable: ' . $lockState['detail'] : ''
    );

    $logWritable = $log->isWritable();
    $add(
        'log',
        $log->fileFor() . ($logWritable ? ' (writable)' : ' (NOT writable)'),
        $logWritable ? 'ok' : 'warn',
        $logWritable ? '' : 'the log folder is not writable; runs continue and warn on stderr'
    );

    /* --- the money reconciliation ------------------------------- */
    if ($missing === []) {
        try {
            $reconcile = (new RoyaltyService())->reconcile();
            $mismatches = $reconcile['mismatches'];
            $first = $mismatches === [] ? '' : (string) ($mismatches[0]['message'] ?? 'mismatch');
            $more  = count($mismatches) > 1 ? ' (+' . (count($mismatches) - 1) . ' more)' : '';

            $add(
                'wallet',
                ($reconcile['ok'] === true ? 'Royalty reconcile clean' : count($mismatches) . ' mismatch(es)')
                    . ' · ' . (int) $reconcile['paid_rows'] . ' paid row(s) · ' . money((float) $reconcile['paid_total'])
                    . ($mismatches === [] ? '' : ' — ' . $first . $more),
                $reconcile['ok'] === true ? 'ok' : 'fail',
                $reconcile['ok'] === true ? '' : 'the Royalty ledger does not reconcile: ' . $first . $more
            );

            foreach ($reconcile['warnings'] as $warning) {
                $warnings[] = (string) ($warning['message'] ?? 'reconcile warning');
            }
        } catch (Throwable $e) {
            $add('wallet', 'unavailable — ' . $e->getMessage(), 'warn',
                'the Royalty reconcile could not run: ' . $e->getMessage());
        }
    }

    $add('cron key', CronAccess::configuredKey() === null ? 'not set (loopback only)' : 'set');
    $add('storage', (string) STORAGE_PATH);

    return [
        'ok'       => $errors === [],
        'status'   => $errors !== [] ? 'fail' : ($warnings === [] ? 'ok' : 'warn'),
        'checks'   => $checks,
        'errors'   => $errors,
        'warnings' => $warnings,
    ];
}

/** Age of a stored datetime in whole hours, or null when unreadable. */
function royalty_close_age_hours(string $datetime, int $now): ?int
{
    $at = strtotime($datetime);

    return $at === false ? null : max(0, (int) floor(($now - $at) / 3600));
}

/** @param array<string, mixed> $report @return list<string> */
function royalty_close_format_doctor(array $report): array
{
    $lines = ['EcoWealth Royalty month close — doctor'];
    $width = 0;

    foreach ($report['checks'] as $check) {
        $width = max($width, strlen($check['key']));
    }

    foreach ($report['checks'] as $check) {
        $mark = match ($check['state']) {
            'fail'  => '  FAIL ',
            'warn'  => '  WARN ',
            default => '       ',
        };

        $lines[] = $mark . str_pad($check['key'], $width) . '   ' . $check['value'];
    }

    if ($report['warnings'] !== []) {
        $lines[] = '';
        $lines[] = 'Warnings (' . count($report['warnings']) . '):';

        foreach ($report['warnings'] as $warning) {
            $lines[] = '  - ' . $warning;
        }
    }

    if ($report['errors'] !== []) {
        $lines[] = '';
        $lines[] = 'Failures (' . count($report['errors']) . '):';

        foreach ($report['errors'] as $error) {
            $lines[] = '  - ' . $error;
        }
    }

    $lines[] = '';
    $lines[] = $report['ok']
        ? 'The doctor is green.'
        : 'The doctor is NOT green — fix the failures above.';

    return $lines;
}

/* --------------------------------------------------------------
 *  The run itself
 * -------------------------------------------------------------- */

/**
 * Execute the resolved plan and report it.
 *
 * @param array<string, mixed> $options
 * @param array<string, mixed> $resolved
 * @return array{0: list<string>, 1: array<string, mixed>}
 */
function royalty_close_run(array $options, array $resolved, string $trigger, CronLog $log, float $started): array
{
    $service = new RoyaltyService();

    $closed    = 0;
    $periods   = [];
    $awardRows = 0;
    $paidRows  = 0;
    $paidCents = 0;
    $skipped   = 0;
    $paused    = false;
    $resume    = false;
    $status    = 'none';
    $results   = [];

    if ($options['dry-run']) {
        $lines = [
            'EcoWealth Royalty month close (dry run — nothing is written)',
            '  database:  ' . DB_NAME . ' (' . DB_HOST . ')',
            '  enabled:   ' . (RoyaltyService::enabled() ? 'yes' : 'no — a close would record a skipped month'),
            '  payments:  ' . (RoyaltyService::paymentsEnabled() ? 'on' : 'paused — awards would stay pending'),
            '  periods:   ' . ($resolved['targets'] === [] ? royalty_close_pending_note() : (string) count($resolved['targets'])),
        ];

        foreach ($resolved['targets'] as $period) {
            $preview  = $service->preview($period);
            $results[] = $preview;

            $lines[] = sprintf(
                '  %s  status %s  awards %d  paid %d  skipped %d  award_total %s  paid_total %s',
                (string) $preview['period'],
                (string) $preview['status'],
                (int) $preview['eligible'],
                (int) $preview['paid_members'],
                (int) $preview['skipped'],
                money((float) $preview['award_total']),
                money((float) $preview['paid_total'])
            );

            $awardRows += (int) $preview['eligible'];
            $paidRows  += (int) $preview['paid_members'];
            $skipped   += (int) $preview['skipped'];
            $paidCents += (int) round((float) $preview['award_total'] * 100);
            $periods[]  = (string) $preview['period'];
            $status     = (string) $preview['status'];
        }

        $log->write('INFO', sprintf(
            'dry-run trigger=%s periods=%s award=%d paid=%d skipped=%d amount=%.2f',
            $trigger,
            $periods === [] ? '-' : implode(',', $periods),
            $awardRows,
            $paidRows,
            $skipped,
            $paidCents / 100
        ));

        return [
            $lines,
            [
                'ok'          => true,
                'trigger'     => $trigger,
                'dry_run'     => true,
                'periods'     => $periods,
                'closed'      => 0,
                'award'       => $awardRows,
                'paid'        => $paidRows,
                'amount'      => $paidCents / 100,
                'skipped'     => $skipped,
                'status'      => $status,
                'paused'      => false,
                'results'     => $results,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'log'         => $log->fileFor(),
            ],
        ];
    }

    /* 1. Resume the evaluated months: pay what is still pending. */
    foreach ($resolved['resume'] as $period) {
        if ($options['evaluate-only']) {
            $results[] = ['period' => $period, 'skipped' => 'already evaluated — never re-evaluated'];
            continue;
        }

        $payment = $service->pay(
            $period,
            (int) $options['limit'],
            (int) $options['max-batches']
        );

        $results[]  = $payment;
        $periods[]  = $period;
        $paidRows  += (int) $payment['paid'];
        $paidCents += (int) round((float) $payment['amount'] * 100);
        $paused     = $paused || (bool) $payment['paused'];
        $resume     = $resume || (bool) $payment['resume_required'];
        $status     = (string) $payment['status'];

        if ((string) $payment['status'] === 'paid') {
            $closed++;
        }
    }

    /* 2. Close the months that have no header yet, oldest first. */
    foreach ($resolved['pending'] as $period) {
        if ($options['evaluate-only']) {
            $evaluation = $service->evaluate($period);

            $results[]  = $evaluation;
            $periods[]  = $period;
            $awardRows += (int) $evaluation['eligible'];
            $skipped   += (int) $evaluation['skipped'];
            $status     = (string) $evaluation['status'];

            /* A month can reach a final status at evaluation time: a
             * skipped period (the stream was off) and an enabled month
             * whose every award row was skipped both finalise here. */
            if (in_array($status, RoyaltyPeriod::FINAL, true)) {
                $closed++;
            }

            continue;
        }

        $result = $service->close(
            $period,
            (int) $options['limit'],
            (int) $options['max-batches']
        );

        $results[] = $result;
        $periods[] = $period;

        $evaluation = $result['evaluation'];
        $payment    = $result['payment'];

        if (is_array($evaluation)) {
            $awardRows += (int) $evaluation['eligible'];
            $skipped   += (int) $evaluation['skipped'];
        }

        if (is_array($payment)) {
            $paidRows  += (int) $payment['paid'];
            $paidCents += (int) round((float) $payment['amount'] * 100);
            $paused     = $paused || (bool) $payment['paused'];
            $resume     = $resume || (bool) $payment['resume_required'];
        }

        $status = (string) $result['status'];

        if (in_array($status, RoyaltyPeriod::FINAL, true)) {
            $closed++;
        }
    }

    $duration = (int) round((microtime(true) - $started) * 1000);
    $amount   = $paidCents / 100;

    $summary = [
        'closed'  => $closed,
        'periods' => $periods,
        'award'   => $awardRows,
        'paid'    => $paidRows,
        'amount'  => $amount,
        'skipped' => $skipped,
        'status'  => $status,
        'paused'  => $paused,
    ];

    royalty_close_write_log($log, $trigger, $closed > 0 ? 'OK' : 'INFO', $summary);

    $note = match (true) {
        $options['evaluate-only'] && $awardRows > 0
            => ' — evaluated only; payment was not run (--evaluate-only)',
        $paused => ' — evaluated; payment is paused (royalty_pay_enabled = 0)',
        $resume => ' — payment incomplete; run --pay-only to continue',
        $closed === 0 => ' — ' . royalty_close_pending_note(),
        default       => '',
    };

    $lines = [
        'EcoWealth Royalty month close',
        '  database:  ' . DB_NAME . ' (' . DB_HOST . ')',
        '  enabled:   ' . (RoyaltyService::enabled() ? 'yes' : 'no'),
        '  payments:  ' . (RoyaltyService::paymentsEnabled() ? 'on' : 'paused'),
        '  closed:    ' . $closed . $note,
        '  periods:   ' . ($periods === [] ? '—' : implode(', ', $periods)),
        '  awards:    ' . $awardRows . ' written · ' . $skipped . ' skipped',
        '  paid:      ' . $paidRows . ' award(s) · ' . money($amount),
    ];

    if ($paused) {
        $lines[] = '  paused:    payments are off (royalty_pay_enabled = 0) — the awards stay pending.';
    }

    if ($resume) {
        $lines[] = '  resume:    the batch ceiling was reached; run --pay-only to continue.';
    }

    $lines[] = '  duration:  ' . $duration . 'ms';
    $lines[] = '  log:       ' . $log->fileFor();

    return [
        $lines,
        [
            'ok'              => true,
            'trigger'         => $trigger,
            'dry_run'         => false,
            'evaluate_only'   => (bool) $options['evaluate-only'],
            'pay_only'        => (bool) $options['pay-only'],
            'closed'          => $closed,
            'periods'         => $periods,
            'award'           => $awardRows,
            'paid'            => $paidRows,
            'amount'          => $amount,
            'skipped'         => $skipped,
            'status'          => $status,
            'paused'          => $paused,
            'resume_required' => $resume,
            'results'         => $results,
            'duration_ms'     => $duration,
            'log'             => $log->fileFor(),
        ],
    ];
}

/* --------------------------------------------------------------
 *  Dispatch — the one path every entry point takes
 * -------------------------------------------------------------- */

/**
 * Run the job for one entry point.
 *
 * Emits the body (and, over HTTP, the headers) and returns the process
 * exit code with the HTTP status the web entry answers with. Nothing
 * exits here, so the lock is always released and a caller can assert
 * the result.
 *
 * @return array{exit:int, http:int}
 */
function royalty_close_dispatch(bool $isCli): array
{
    $trigger = $isCli ? 'cli' : 'http';
    $options = royalty_close_options($isCli);

    /* --- the web guard comes before any work --------------------- */
    if (!$isCli) {
        $gate = royalty_close_http_gate(
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            isset($_GET['key']) ? (string) $_GET['key'] : null,
            CronAccess::configuredKey()
        );

        if (!$gate['run']) {
            /* A cron response is data, never a page: no debug output, no
             * caching, no indexing. */
            ini_set('display_errors', '0');
            royalty_close_http_headers($gate['status'], $options['json']);

            if ($options['json']) {
                echo json_encode([
                    'ok'          => false,
                    'trigger'     => $trigger,
                    'error'       => 'Forbidden',
                    'http_status' => $gate['status'],
                    'exit_code'   => 1,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            } else {
                echo $gate['body'] . PHP_EOL;
            }

            return ['exit' => 1, 'http' => $gate['status']];
        }

        ini_set('display_errors', '0');
        royalty_close_http_headers(200, $options['json']);
    }

    [$exitCode, $lines, $payload] = royalty_close_execute($isCli, $options);

    $payload['exit_code'] = $exitCode;
    $http = $isCli ? 0 : royalty_close_http_status($exitCode);

    if (!$isCli) {
        $payload['http_status'] = $http;

        if ($http !== 200) {
            royalty_close_http_headers($http, $options['json']);
        }
    }

    royalty_close_emit($isCli, $options, $lines, $payload);

    return ['exit' => $exitCode, 'http' => $http];
}

/**
 * The job without any HTTP concern: options in, body out.
 *
 * @param array<string, mixed> $options
 * @return array{0:int, 1:list<string>, 2:array<string, mixed>}
 */
function royalty_close_execute(bool $isCli, array $options): array
{
    $trigger = $isCli ? 'cli' : 'http';
    $started = microtime(true);
    $lines   = [];
    $payload = ['ok' => true, 'trigger' => $trigger];
    $lock    = null;
    $log     = null;
    $exit    = 0;

    if ($options['help']) {
        $usage = royalty_close_usage();

        return [0, [$usage], ['ok' => true, 'trigger' => $trigger, 'usage' => $usage]];
    }

    try {
        /* Environment checks come first, so an unreachable database or a
         * missing table exits 2 rather than 1 (plan §14.10) — except for
         * the doctor, whose job is to report them. */
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $lines = ['EcoWealth Royalty month close', '  cannot reach the database: ' . $e->getMessage()];

            if ($isCli) {
                fwrite(STDERR, 'The Royalty close cannot reach the database: ' . $e->getMessage() . PHP_EOL);
            }

            return [2, $lines, ['ok' => false, 'trigger' => $trigger, 'error' => 'The Royalty close could not reach the database.']];
        }

        $lock = new CronLock('royalty_month_close');
        $log  = new CronLog($isCli ? $options['log-dir'] : null, 'royalty_month_close');

        if ($options['doctor']) {
            $report = royalty_close_doctor($lock, $log);
            $lines  = royalty_close_format_doctor($report);

            return [
                $report['ok'] ? 0 : 1,
                $lines,
                [
                    'ok'       => $report['ok'],
                    'trigger'  => $trigger,
                    'doctor'   => $report,
                ],
            ];
        }

        $tables = (int) db()->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('royalty_periods', 'royalty_awards')"
        )->fetchColumn();

        if ($tables !== 2) {
            $message = 'The Royalty tables are missing; apply migration 023_royalty_bonus.sql.';

            if ($isCli) {
                fwrite(STDERR, $message . PHP_EOL);
            }

            return [2, ['Royalty month close FAILED', '  ' . $message],
                ['ok' => false, 'trigger' => $trigger, 'error' => $message]];
        }

        try {
            $acquired = $lock->acquire();
        } catch (RuntimeException $e) {
            $message = 'The Royalty close cannot take its lock: ' . $e->getMessage();

            if ($isCli) {
                fwrite(STDERR, $message . PHP_EOL);
            }

            return [2, ['Royalty month close FAILED', '  ' . $message],
                ['ok' => false, 'trigger' => $trigger, 'error' => $message]];
        }

        if (!$acquired) {
            $log->write('SKIP', 'trigger=' . $trigger . ' skipped: another run holds ' . $lock->path());

            return [
                0,
                ['EcoWealth Royalty month close', '  [SKIP ] busy — another run already holds the lock.'],
                ['ok' => true, 'trigger' => $trigger, 'skipped' => 'locked'],
            ];
        }

        $resolved = royalty_close_resolve($options['period']);

        if ($resolved['refused'] !== null) {
            $exit   = 1;
            $answer = royalty_close_refused(
                $trigger,
                (string) $resolved['reason'],
                (string) $resolved['refused'],
                $options['period'],
                $log
            );
            $lines   = $answer['lines'];
            $payload = $answer['payload'];

            if ($isCli && $options['quiet']) {
                fwrite(STDERR, $resolved['refused'] . PHP_EOL);
            }
        } elseif ($options['pay-only']) {
            /* --pay-only is an explicit request: an absent, skipped or
             * already-paid month is an error, not a no-op. */
            $header = $options['period'] === null
                ? null
                : RoyaltyPeriod::find($options['period']);

            if ($header === null) {
                $exit    = 1;
                $message = '--pay-only needs an evaluated month; there is no Royalty period'
                    . ($options['period'] === null ? ' (pass --period=YYYY-MM)' : ' ' . $options['period']) . '.';
                $answer  = royalty_close_refused($trigger, 'not_found', $message, $options['period'], $log);
                $lines   = $answer['lines'];
                $payload = $answer['payload'];

                if ($isCli && $options['quiet']) {
                    fwrite(STDERR, $message . PHP_EOL);
                }
            } elseif ((string) $header['status'] !== 'evaluated') {
                $exit    = 1;
                $message = '--pay-only needs an evaluated month; ' . (string) $header['period']
                    . ' is ' . (string) $header['status'] . '.';
                $answer  = royalty_close_refused($trigger, 'state', $message, (string) $header['period'], $log);
                $lines   = $answer['lines'];
                $payload = $answer['payload'];

                if ($isCli && $options['quiet']) {
                    fwrite(STDERR, $message . PHP_EOL);
                }
            } else {
                $targets = [$options['period']];
                $resolved['resume']  = $targets;
                $resolved['pending'] = [];
                $resolved['targets'] = $targets;
                [$lines, $payload] = royalty_close_run($options, $resolved, $trigger, $log, $started);
            }
        } elseif ($resolved['already'] !== null) {
            $answer  = royalty_close_already($trigger, (string) $resolved['already'], $log);
            $lines   = $answer['lines'];
            $payload = $answer['payload'];

            if ($options['dry-run']) {
                $payload['dry_run'] = true;
                $preview            = (new RoyaltyService())->preview((string) $resolved['already']);
                $lines[]            = '  stored:   ' . $preview['status']
                    . ' · ' . $preview['eligible'] . ' row(s) · ' . money((float) $preview['award_total']);
            }
        } else {
            [$lines, $payload] = royalty_close_run($options, $resolved, $trigger, $log, $started);
        }
    } catch (PDOException $e) {
        $exit    = 2;
        $payload = ['ok' => false, 'trigger' => $trigger, 'error' => 'The Royalty close could not reach the database.'];
        $lines   = ['Royalty month close FAILED', '  ' . $e->getMessage()];

        if ($isCli) {
            fwrite(STDERR, 'Royalty month close failed: ' . $e->getMessage() . PHP_EOL);
        }
    } catch (Throwable $e) {
        $exit    = 1;
        $payload = ['ok' => false, 'trigger' => $trigger, 'error' => 'The Royalty close job failed. See the log.'];
        $lines   = ['Royalty month close FAILED', '  The job could not run. ' . $e->getMessage()];

        if ($log instanceof CronLog) {
            $log->write('ERROR', 'trigger=' . $trigger . ' run failed: ' . $e->getMessage());
        }

        if ($isCli) {
            fwrite(STDERR, 'Royalty month close failed: ' . $e->getMessage() . PHP_EOL);
        }
    } finally {
        $lock?->release();
    }

    /* The JSON payload carries the operations picture of the stream
     * (plan §10 Rule 10, 19F): the open month, the closed months, the
     * pending and evaluated periods, the switches and the last close.
     * Read-only, and only when something was asked — a busy or refused
     * run needed none of it. */
    if ($options['json'] && isset($payload['closed']) && ($payload['ok'] ?? false) === true) {
        try {
            $payload['operations'] = RoyaltyOps::snapshot();
        } catch (Throwable $e) {
            error_log('[ecowealth] royalty operations snapshot unavailable: ' . $e->getMessage());
        }
    }

    return [$exit, $lines, $payload];
}

/* --------------------------------------------------------------
 *  Entry
 * -------------------------------------------------------------- */

/* The web entry requires this file, so the bottom must run the job in
 * both modes. ROYALTY_CLOSE_LIBRARY lets a caller load the functions
 * (the test harness) without starting a run. */
if (!defined('ROYALTY_CLOSE_LIBRARY')) {
    exit(royalty_close_dispatch($isCli)['exit']);
}
