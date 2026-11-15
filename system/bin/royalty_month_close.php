#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — Royalty month close (plan Phase 19C, §12.6, §14.10)
 *
 *      php bin/royalty_month_close.php [flags]
 *
 *  The one job that moves Royalty money. It is deliberately headless:
 *  19C adds no page and no route (D-16), so this command is the
 *  observation surface.
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
 *  Flags:
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
 *    --quiet             no stdout (the log still records the run)
 *    --log-dir=<dir>     where the monthly log is written
 *    --help              usage
 *
 *  Exit codes: 0 success / no-op / busy / paused; 1 processing or
 *  validation error; 2 environment (database down, missing tables).
 *
 *  Cron (19F schedules it; the recommended slot is 00:30 on the 1st,
 *  after the unilevel close at 00:15 — order is for log readability):
 *    30 0 1 * * /usr/bin/php /path/system/bin/royalty_month_close.php
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

/* --------------------------------------------------------------
 *  Options and helpers
 * -------------------------------------------------------------- */

/**
 * @return array{
 *   period:?string, dry-run:bool, evaluate-only:bool, pay-only:bool,
 *   json:bool, quiet:bool, help:bool, limit:int, max-batches:int,
 *   log-dir:?string
 * }
 */
function royalty_close_options(): array
{
    $options = [
        'period'        => null,
        'dry-run'       => false,
        'evaluate-only' => false,
        'pay-only'      => false,
        'json'          => false,
        'quiet'         => false,
        'help'          => false,
        'limit'         => 500,
        'max-batches'   => 20,
        'log-dir'       => null,
    ];

    $parsed = getopt('', [
        'period:', 'dry-run', 'evaluate-only', 'pay-only', 'json', 'quiet',
        'help', 'limit:', 'max-batches:', 'log-dir:',
    ]);

    if (!is_array($parsed)) {
        return $options;
    }

    foreach (['dry-run', 'evaluate-only', 'pay-only', 'json', 'quiet', 'help'] as $flag) {
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
        '  --quiet           no stdout (the log still records the run)',
        '  --log-dir=<dir>   where the monthly log is written',
        '  --help            this text',
        '',
        'Without --period it resumes every evaluated month, then closes every',
        'completed month from the baseline through last month, oldest first.',
        'The current month is refused; a final month is never recalculated.',
    ]);
}

/** @param array<string, mixed> $options @param list<string> $lines @param array<string, mixed> $payload */
function royalty_close_emit(array $options, array $lines, array $payload): void
{
    if ($options['quiet']) {
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
 * Which periods may this run touch, and in what order?
 *
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
function royalty_close_already(string $period, CronLog $log): array
{
    $header = RoyaltyPeriod::find($period);
    $status = $header === null ? 'paid' : (string) $header['status'];
    $paid   = $header === null ? 0 : (int) $header['paid_count'];
    $total  = $header === null ? 0.0 : (float) $header['paid_total'];

    $log->write('INFO', 'trigger=cli closed=0 period=' . $period . ' reason=already-final status=' . $status);

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
            'trigger'    => 'cli',
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
function royalty_close_refused(string $reason, string $message, ?string $period, CronLog $log): array
{
    $log->write('ERROR', sprintf(
        'trigger=cli refused period=%s reason=%s',
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
            'trigger' => 'cli',
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
function royalty_close_write_log(CronLog $log, string $level, array $summary): void
{
    $log->write($level, sprintf(
        'trigger=cli closed=%d periods=%s award=%d paid=%d amount=%.2f skipped=%d status=%s paused=%d',
        $summary['closed'],
        $summary['periods'] === [] ? '-' : implode(',', $summary['periods']),
        $summary['award'],
        $summary['paid'],
        $summary['amount'],
        $summary['skipped'],
        $summary['status'],
        $summary['paused'] ? 1 : 0
    ));
}

/* --------------------------------------------------------------
 *  Bind the connection, then run
 * -------------------------------------------------------------- */

$options = royalty_close_options();

if ($options['help']) {
    royalty_close_emit($options, [royalty_close_usage()], ['ok' => true, 'usage' => royalty_close_usage()]);
    exit(0);
}

$started  = microtime(true);
$exitCode = 0;
$lines    = [];
$payload  = ['ok' => true, 'trigger' => 'cli'];
$lock     = null;
$log      = null;

try {
    /* Environment checks come first, so an unreachable database, a
     * missing table or an unwritable storage folder exits 2 rather
     * than 1 (plan §14.10). */
    try {
        new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        fwrite(STDERR, 'The Royalty close cannot reach the database: ' . $e->getMessage() . PHP_EOL);
        exit(2);
    }

    $tables = (int) db()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('royalty_periods', 'royalty_awards')"
    )->fetchColumn();

    if ($tables !== 2) {
        fwrite(STDERR, 'The Royalty tables are missing; apply migration 023_royalty_bonus.sql.' . PHP_EOL);
        exit(2);
    }

    $lock = new CronLock('royalty_month_close');
    $log  = new CronLog($options['log-dir'], 'royalty_month_close');

    try {
        $acquired = $lock->acquire();
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'The Royalty close cannot take its lock: ' . $e->getMessage() . PHP_EOL);
        exit(2);
    }

    if (!$acquired) {
        $lines   = ['EcoWealth Royalty month close', '  [SKIP ] busy — another run already holds the lock.'];
        $payload = ['ok' => true, 'trigger' => 'cli', 'skipped' => 'locked'];
        $log->write('SKIP', 'trigger=cli skipped: another run holds ' . $lock->path());
    } else {
        $resolved = royalty_close_resolve($options['period']);

        if ($resolved['refused'] !== null) {
            $exitCode = 1;
            $answer   = royalty_close_refused(
                (string) $resolved['reason'],
                (string) $resolved['refused'],
                $options['period'],
                $log
            );
            $lines   = $answer['lines'];
            $payload = $answer['payload'];
            if ($options['quiet']) {
                fwrite(STDERR, $resolved['refused'] . PHP_EOL);
            }
        } elseif ($options['pay-only']) {
            /* --pay-only is an explicit request: an absent, skipped or
             * already-paid month is an error, not a no-op. */
            $header = $options['period'] === null
                ? null
                : RoyaltyPeriod::find($options['period']);

            if ($header === null) {
                $exitCode = 1;
                $message  = '--pay-only needs an evaluated month; there is no Royalty period'
                    . ($options['period'] === null ? ' (pass --period=YYYY-MM)' : ' ' . $options['period']) . '.';
                $answer   = royalty_close_refused('not_found', $message, $options['period'], $log);
                $lines    = $answer['lines'];
                $payload  = $answer['payload'];
                if ($options['quiet']) {
                    fwrite(STDERR, $message . PHP_EOL);
                }
            } elseif ((string) $header['status'] !== 'evaluated') {
                $exitCode = 1;
                $message  = '--pay-only needs an evaluated month; ' . (string) $header['period']
                    . ' is ' . (string) $header['status'] . '.';
                $answer   = royalty_close_refused('state', $message, (string) $header['period'], $log);
                $lines    = $answer['lines'];
                $payload  = $answer['payload'];
                if ($options['quiet']) {
                    fwrite(STDERR, $message . PHP_EOL);
                }
            } else {
                $targets = [$options['period']];
                $resolved['resume']  = $targets;
                $resolved['pending'] = [];
                $resolved['targets'] = $targets;
                [$lines, $payload] = royalty_close_run($options, $resolved, $log, $started);
            }
        } elseif ($resolved['already'] !== null) {
            $answer  = royalty_close_already((string) $resolved['already'], $log);
            $lines   = $answer['lines'];
            $payload = $answer['payload'];

            if ($options['dry-run']) {
                $payload['dry_run'] = true;
                $preview            = (new RoyaltyService())->preview((string) $resolved['already']);
                $lines[]            = '  stored:   ' . $preview['status']
                    . ' · ' . $preview['eligible'] . ' row(s) · ' . money((float) $preview['award_total']);
            }
        } else {
            [$lines, $payload] = royalty_close_run($options, $resolved, $log, $started);
        }
    }
} catch (PDOException $e) {
    $exitCode = 2;
    $payload  = ['ok' => false, 'trigger' => 'cli', 'error' => 'The Royalty close could not reach the database.'];
    $lines    = ['Royalty month close FAILED', '  ' . $e->getMessage()];
    fwrite(STDERR, 'Royalty month close failed: ' . $e->getMessage() . PHP_EOL);
} catch (Throwable $e) {
    $exitCode = 1;
    $payload  = ['ok' => false, 'trigger' => 'cli', 'error' => 'The Royalty close job failed. See the log.'];
    $lines    = ['Royalty month close FAILED', '  The job could not run. ' . $e->getMessage()];

    if ($log instanceof CronLog) {
        $log->write('ERROR', 'trigger=cli run failed: ' . $e->getMessage());
    }

    fwrite(STDERR, 'Royalty month close failed: ' . $e->getMessage() . PHP_EOL);
} finally {
    $lock?->release();
}

royalty_close_emit($options, $lines, $payload);

exit($exitCode);

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
function royalty_close_run(array $options, array $resolved, CronLog $log, float $started): array
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
            'dry-run trigger=cli periods=%s award=%d paid=%d skipped=%d amount=%.2f',
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
                'trigger'     => 'cli',
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

            if ((string) $evaluation['status'] === 'skipped') {
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

    royalty_close_write_log($log, $closed > 0 ? 'OK' : 'INFO', $summary);

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
            'trigger'         => 'cli',
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
