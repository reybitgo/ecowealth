<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — Team Harvest catch-up and doctor (plan Phase 20D §14.11)
 *
 *      php bin/team_harvest_catchup.php [flags]
 *      bin\team_harvest_catchup.cmd [flags]        (Windows launcher)
 *
 *  The on-demand entry point of a **state-based** engine. There is no
 *  cron entry and no monthly close (D-08, §14.15): the stream pays on a
 *  qualification, and this job exists for the three moments a
 *  state-based engine needs a human to press go —
 *
 *    • after enabling the stream (settle the backlog the off switch
 *      created: §14.14 steps 5–8),
 *    • after a repair or a counter-drift alarm,
 *    • after a deploy that fixed a bug.
 *
 *  It is also the **doctor**: a read-only report of the stream's health
 *  (clocks, tables, settings, seed state, baseline, queue, drift, award
 *  totals and the reconcile verdict). The doctor is what an operator
 *  runs before deciding to enable the stream and after any alarm.
 *
 *  Flags:
 *    --dry-run       evaluate nothing; report the open liability (levels,
 *                    members, amount) and the per-level counts
 *    --member=ID     evaluate one member only
 *    --doctor        report the stream's health without evaluating
 *    --json          machine-readable output
 *    --limit=N       evaluation batch size, 1–5000 (default 500)
 *    --quiet         only the one-line summary
 *    --help          this text
 *
 *  Safety:
 *    • one run at a time (`storage/locks/team_harvest_catchup.lock`);
 *    • unseeded is a hard refusal (exit 1) — without a baseline nobody
 *      can tell a pre-go-live level from a payable one (D-04);
 *    • the switch off is not a failure: it writes nothing and reports
 *      `off` (D-09);
 *    • a batch is one transaction: a failure rolls the batch back and
 *      the next run retries the same levels (§14.7);
 *    • the run audits `team_harvest.catchup` with what it evaluated and
 *      what it paid (§15.4). A dry run and the doctor write nothing.
 *
 *  Exit codes: 0 clean / nothing to do / busy; 1 refused (unseeded) or
 *  a problem found (unpaid backlog after a run, drift, reconcile
 *  mismatch, doctor red); 2 environment (database unreachable, missing
 *  tables, another run holds the lock file and cannot be read).
 * ============================================================== */

$root = dirname(__DIR__);

require_once $root . '/app/config.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/autoload.php';
require_once $root . '/app/database.php';

date_default_timezone_set(APP_TIMEZONE);

/* --------------------------------------------------------------
 *  Options and output
 * -------------------------------------------------------------- */

/**
 * @return array{dry-run:bool, doctor:bool, json:bool, quiet:bool, help:bool,
 *               member:int, limit:int}
 */
function team_harvest_catchup_options(): array
{
    $options = [
        'dry-run' => false,
        'doctor'  => false,
        'json'    => false,
        'quiet'   => false,
        'help'    => false,
        'member'  => 0,
        'limit'   => 500,
    ];

    $parsed = getopt('', ['dry-run', 'doctor', 'json', 'quiet', 'help', 'member:', 'limit:']);

    if (!is_array($parsed)) {
        return $options;
    }

    foreach (['dry-run', 'doctor', 'json', 'quiet', 'help'] as $flag) {
        $options[$flag] = isset($parsed[$flag]);
    }

    if (isset($parsed['member']) && is_string($parsed['member'])) {
        $options['member'] = max(0, (int) $parsed['member']);
    }

    if (isset($parsed['limit']) && is_string($parsed['limit'])) {
        /* A malformed or out-of-range batch size is clamped, never
         * trusted (plan §15.1). */
        $options['limit'] = max(1, min(5000, (int) $parsed['limit']));
    }

    return $options;
}

function team_harvest_catchup_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth Team Harvest catch-up and doctor',
        '',
        'Usage: php bin/team_harvest_catchup.php [flags]',
        '',
        '  --dry-run     evaluate nothing; report the open liability and the per-level counts',
        '  --member=ID   evaluate one member only',
        '  --doctor      report the stream\'s health without evaluating',
        '  --json        machine-readable output',
        '  --limit=N     evaluation batch size, 1–5000 (default 500)',
        '  --quiet       only the one-line summary',
        '  --help        this text',
        '',
        'A completed level pays once, into the withdrawable e-wallet, with an',
        'award row and a wallet movement written in one transaction. Running the',
        'job again evaluates the same counters against the same ledger and writes',
        'nothing new: the engine is state-based, not event-based (plan D-19).',
        '',
        'Typical use (docs/runbook.md > "Team Harvest award and reconcile drill"):',
        '',
        '  php bin/team_harvest_catchup.php --dry-run     read the liability',
        '  php bin/team_harvest_catchup.php              settle the backlog',
        '  php bin/team_harvest_catchup.php --doctor     prove the stream is healthy',
        '',
        'There is no cron entry for this job: Team Harvest has no month and no',
        'schedule (plan D-08, §14.15).',
    ]);
}

/**
 * @param array<string, mixed> $options
 * @param list<string> $lines
 * @param array<string, mixed> $payload
 */
function team_harvest_catchup_emit(array $options, array $lines, array $payload): void
{
    if ($options['quiet']) {
        echo team_harvest_catchup_summary_line($payload) . PHP_EOL;

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

/** @param array<string, mixed> $payload */
function team_harvest_catchup_summary_line(array $payload): string
{
    if (($payload['ok'] ?? false) !== true) {
        return '[FAIL ] ' . (string) ($payload['error'] ?? 'the job refused');
    }

    if (($payload['doctor'] ?? null) !== null) {
        return sprintf(
            '[%s] doctor checks=%d failed=%d',
            ($payload['ok'] ?? false) === true ? 'OK   ' : 'CHECK',
            count((array) ($payload['doctor']['checks'] ?? [])),
            count(array_filter(
                (array) ($payload['doctor']['checks'] ?? []),
                static fn (array $check): bool => ($check['ok'] ?? false) !== true
            ))
        );
    }

    if (($payload['dry_run'] ?? false) === true) {
        return sprintf(
            '[DRY  ] liability levels=%d members=%d amount=%s',
            (int) ($payload['liability']['levels'] ?? 0),
            (int) ($payload['liability']['members'] ?? 0),
            money((float) ($payload['liability']['amount'] ?? 0))
        );
    }

    return sprintf(
        '[%s] evaluated=%d paid=%d baseline=%d amount=%s',
        ($payload['result']['off'] ?? false) === true ? 'OFF  ' : 'OK   ',
        (int) ($payload['members'] ?? 0),
        (int) ($payload['result']['paid'] ?? 0),
        (int) ($payload['result']['baseline'] ?? 0),
        money((float) ($payload['result']['amount'] ?? 0))
    );
}

/* --------------------------------------------------------------
 *  The human reports
 * -------------------------------------------------------------- */

/**
 * The dry-run report: what the engine would pay, level by level.
 *
 * @param array<string, mixed> $liability
 * @return list<string>
 */
function team_harvest_catchup_format_dry(array $liability): array
{
    $lines = [
        'EcoWealth Team Harvest catch-up (dry run — nothing is evaluated and nothing is written)',
        '  database:   ' . DB_NAME . ' (' . DB_HOST . ')',
        '  liability:  ' . money((float) ($liability['amount'] ?? 0))
            . ' across ' . (int) ($liability['levels'] ?? 0) . ' level(s) of '
            . (int) ($liability['members'] ?? 0) . ' member(s)',
        '  levels:',
    ];

    $any = false;

    foreach ((array) ($liability['by_level'] ?? []) as $row) {
        if ((int) ($row['count'] ?? 0) === 0) {
            continue;
        }

        $any = true;
        $lines[] = sprintf(
            '     level %-2d  %-4d level(s)  %s',
            (int) $row['level'],
            (int) $row['count'],
            money((float) $row['amount'])
        );
    }

    if (!$any) {
        $lines[] = '     none — no level is complete above the baseline without an award row';
    }

    $lines[] = '';
    $lines[] = 'Run again without --dry-run to evaluate and pay exactly these levels.';

    return $lines;
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $payload
 * @return list<string>
 */
function team_harvest_catchup_format_run(array $result, array $payload): array
{
    $lines = ['EcoWealth Team Harvest catch-up'];
    $lines[] = '  database:   ' . DB_NAME . ' (' . DB_HOST . ')';
    $lines[] = '  evaluated:  ' . (int) ($payload['members'] ?? 0) . ' member(s)'
        . (($payload['member'] ?? 0) > 0 ? ' (member filter #' . (int) $payload['member'] . ')' : '');
    $lines[] = '  paid:       ' . (int) ($result['paid'] ?? 0) . ' level(s) · '
        . money((float) ($result['amount'] ?? 0));
    $lines[] = '  baseline:   ' . (int) ($result['baseline'] ?? 0) . ' level(s) recorded as'
        . ' complete before go-live (never paid)';

    if (($result['off'] ?? false) === true) {
        $lines[] = '';
        $lines[] = 'The stream is switched off (team_harvest_enabled = 0): nothing was evaluated.'
            . ' The queue keeps recording positions (plan D-09).';
    } elseif ((int) ($result['written'] ?? 0) === 0) {
        $lines[] = '';
        $lines[] = 'Nothing to do: every completed level already has its award row.';
    }

    return $lines;
}

/**
 * @param array<string, mixed> $report
 * @return list<string>
 */
function team_harvest_catchup_format_doctor(array $report): array
{
    $lines = ['EcoWealth Team Harvest — doctor'];
    $width = 0;

    foreach ((array) ($report['checks'] ?? []) as $check) {
        $width = max($width, strlen((string) $check['key']));
    }

    foreach ((array) ($report['checks'] ?? []) as $check) {
        $lines[] = sprintf(
            '  %s  %-' . $width . 's  %s',
            ($check['ok'] ?? false) === true ? '[ OK ]' : '[FAIL]',
            (string) $check['key'],
            (string) $check['detail']
        );
    }

    foreach ((array) ($report['warnings'] ?? []) as $warning) {
        $lines[] = '  warning: ' . (string) $warning;
    }

    $lines[] = '';
    $lines[] = ($report['ok'] ?? false) === true
        ? 'The doctor is green.'
        : 'The doctor is NOT green — fix the failures above.';

    return $lines;
}

/* --------------------------------------------------------------
 *  The doctor
 * -------------------------------------------------------------- */

/**
 * A read-only health report: clocks, tables, settings, seed state, the
 * baseline, the queue, the counters, the award totals and the reconcile
 * verdict (plan §14.11).
 *
 * @return array{ok:bool, checks:list<array{key:string, label:string, ok:bool, detail:string}>,
 *               warnings:list<string>, reconcile:array<string, mixed>}
 */
function team_harvest_catchup_doctor(): array
{
    $checks   = [];
    $warnings = [];

    $check = static function (string $key, string $label, bool $ok, string $detail) use (&$checks): void {
        $checks[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    };

    /* 1. The clocks: PHP and MySQL must agree about "now", or a
     *    `credited_at` and a baseline can be compared wrongly. */
    $offset = null;

    try {
        $offset = abs(time() - (int) db()->query('SELECT UNIX_TIMESTAMP()')->fetchColumn());
        $check('clock', 'PHP and MySQL clocks', $offset <= 60, $offset . ' s apart (tolerance 60 s)');
    } catch (Throwable $e) {
        $check('clock', 'PHP and MySQL clocks', false, 'could not read the MySQL clock: ' . $e->getMessage());
    }

    /* 2. The three tables. */
    $present = [];
    $tableError = '';

    try {
        $present = array_map('strval', db()->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('harvest_queue', 'team_harvest_levels', 'team_harvest_awards')"
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        $tableError = $e->getMessage();
    }

    $missing = array_values(array_diff(['harvest_queue', 'team_harvest_levels', 'team_harvest_awards'], $present));
    $check(
        'tables',
        'The three Team Harvest tables',
        $missing === [],
        $missing === []
            ? 'harvest_queue, team_harvest_levels and team_harvest_awards exist'
            : ('missing: ' . implode(', ', $missing) . ($tableError === '' ? '' : ' (' . $tableError . ')'))
    );

    /* 3. The settings. */
    $settingValues = [];

    try {
        $settingValues = db()->query(
            "SELECT key_name, value FROM settings WHERE key_name LIKE 'team\\_harvest\\_%'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $check('settings', 'The four Team Harvest settings', false, 'could not read settings: ' . $e->getMessage());
    }

    $absent = array_values(array_diff(
        ['team_harvest_enabled', 'team_harvest_qualify_directs', 'team_harvest_max_levels', 'team_harvest_ledger_since'],
        array_keys($settingValues)
    ));
    $gate  = trim((string) ($settingValues['team_harvest_qualify_directs'] ?? ''));
    $depth = trim((string) ($settingValues['team_harvest_max_levels'] ?? ''));
    $gateOk  = preg_match('/\A\d+\z/', $gate) === 1
        && (int) $gate >= TeamHarvestLevel::GATE_MIN && (int) $gate <= TeamHarvestLevel::GATE_MAX;
    $depthOk = preg_match('/\A\d+\z/', $depth) === 1
        && (int) $depth >= TeamHarvestLevel::DEPTH_MIN && (int) $depth <= TeamHarvestLevel::DEPTH_MAX;

    $check(
        'settings',
        'The four Team Harvest settings',
        $absent === [] && $gateOk && $depthOk,
        ($absent === [] ? 'all four present' : 'missing: ' . implode(', ', $absent))
            . ' · gate ' . ($gateOk ? $gate : 'INVALID (' . $gate . ')')
            . ' · depth ' . ($depthOk ? $depth : 'INVALID (' . $depth . ')')
    );

    /* 4/5. Seeded and the baseline instant. */
    $queue    = new HarvestQueue();
    $service  = new TeamHarvestService();
    $baseline = $queue->baseline();
    $seeded   = $baseline !== null;
    $enabled  = TeamHarvestService::enabled();

    $check(
        'seed',
        'The queue is seeded',
        $seeded || !$enabled,
        $seeded
            ? 'seeded (team_harvest_ledger_since = ' . $baseline . ')'
            : ($enabled
                ? 'NOT seeded, but the stream is enabled: nothing can pay (plan D-04)'
                : 'not seeded — the switch is off, so nothing can pay (plan D-04)')
    );

    if (!$seeded) {
        $warnings[] = 'The queue is not seeded: run bin/harvest_queue_seed.php before enabling the stream.';
    }

    $check('baseline', 'The go-live baseline', true, $baseline ?? 'not written yet');

    /* 6. The queue. */
    $check(
        'queue',
        'The queue',
        true,
        $queue->stats()['queued'] . ' position(s) · next #' . $queue->stats()['next']
            . ' · deepest n ' . $queue->stats()['deepest']
    );

    /* 7. The awards. */
    $awardRows = 0;
    $credited  = 0.0;
    $baseRows  = 0;

    try {
        $aggregate = db()->query(
            "SELECT COUNT(*) AS rows_,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited,
                    COUNT(CASE WHEN status = 'baseline' THEN 1 END) AS baseline_rows
               FROM team_harvest_awards"
        )->fetch() ?: [];
        $awardRows = (int) ($aggregate['rows_'] ?? 0);
        $credited  = round((float) ($aggregate['credited'] ?? 0), 2);
        $baseRows  = (int) ($aggregate['baseline_rows'] ?? 0);
    } catch (Throwable $e) {
        $warnings[] = 'The award totals could not be read: ' . $e->getMessage();
    }

    $check(
        'awards',
        'The award ledger',
        true,
        $awardRows . ' row(s) · ' . money($credited) . ' credited · ' . $baseRows . ' baseline'
    );

    /* 8. Counter drift: the counter against a from-scratch recount. */
    $drift = 0;
    $violations = 0;

    try {
        $stored = [];
        $baselines = [];

        foreach (db()->query('SELECT user_id, teammates, baseline_n FROM harvest_queue')->fetchAll() as $row) {
            $stored[(int) $row['user_id']]    = (int) $row['teammates'];
            $baselines[(int) $row['user_id']] = (int) $row['baseline_n'];
        }

        foreach ($queue->recountAll() as $userId => $count) {
            if (($stored[$userId] ?? 0) !== $count) {
                $drift++;
            }
        }

        foreach ($baselines as $userId => $baselineN) {
            if (($stored[$userId] ?? 0) < $baselineN) {
                $violations++;
            }
        }
    } catch (Throwable $e) {
        $drift = -1;
        $warnings[] = 'The counter recount could not run: ' . $e->getMessage();
    }

    $check(
        'drift',
        'The teammate counters',
        $drift === 0 && $violations === 0,
        $drift < 0
            ? 'could not recount'
            : $drift . ' counter(s) disagree with a recount · ' . $violations . ' below their baseline'
    );

    /* 9. The reconcile verdict. */
    $reconcile = ['ok' => false, 'mismatches' => [], 'warnings' => []];

    try {
        $reconcile = $service->reconcile();
    } catch (Throwable $e) {
        $reconcile = ['ok' => false, 'mismatches' => [['message' => $e->getMessage()]], 'warnings' => []];
    }

    $check(
        'reconcile',
        'The Team Harvest reconciliation',
        (bool) ($reconcile['ok'] ?? false),
        (bool) ($reconcile['ok'] ?? false)
            ? 'awards = wallet movements = ' . money((float) ($reconcile['credited_total'] ?? 0))
                . ', counters clean'
            : count((array) ($reconcile['mismatches'] ?? [])) . ' mismatch(es)'
    );

    if (!$enabled) {
        $warnings[] = 'The stream is switched off (team_harvest_enabled = 0); the queue still records positions.';
    }

    $ok = true;

    foreach ($checks as $row) {
        if (($row['ok'] ?? false) !== true) {
            $ok = false;
        }
    }

    return ['ok' => $ok, 'checks' => $checks, 'warnings' => $warnings, 'reconcile' => $reconcile];
}

/* --------------------------------------------------------------
 *  The run
 * -------------------------------------------------------------- */

/**
 * @param array<string, mixed> $options
 * @return array{0:int, 1:list<string>, 2:array<string, mixed>} exit code, lines, payload
 */
function team_harvest_catchup_run(array $options): array
{
    $service = new TeamHarvestService();

    if ($options['doctor']) {
        $report = team_harvest_catchup_doctor();
        $lines  = team_harvest_catchup_format_doctor($report);

        return [
            ($report['ok'] ?? false) === true ? 0 : 1,
            $lines,
            ['ok' => (bool) ($report['ok'] ?? false), 'doctor' => $report],
        ];
    }

    if (!$service->seeded()) {
        return [
            1,
            [
                'EcoWealth Team Harvest catch-up FAILED',
                '  refused: the queue is not seeded (team_harvest_ledger_since is empty).',
                '  Run bin/harvest_queue_seed.php first: without a baseline a level complete',
                '  before go-live cannot be told from a payable one (plan D-04).',
            ],
            ['ok' => false, 'error' => 'unseeded', 'seeded' => false],
        ];
    }

    if ($options['dry-run']) {
        $liability = $service->openLiability();

        return [
            0,
            team_harvest_catchup_format_dry($liability),
            ['ok' => true, 'dry_run' => true, 'liability' => $liability],
        ];
    }

    $member = (int) $options['member'];
    $result = $member > 0
        ? $service->evaluate([$member])
        : $service->evaluateAll((int) $options['limit']);

    $members = $member > 0 ? 1 : (int) ($result['members'] ?? 0);

    $payload = [
        'ok'      => true,
        'trigger' => 'cli',
        'member'  => $member,
        'members' => $members,
        'result'  => $result,
        'limit'   => (int) $options['limit'],
    ];

    /* Not audited: a dry run. Audited: every real evaluation, even one
     * that paid nothing, because "the operator ran it" is part of the
     * trail (§15.4). */
    if (($result['off'] ?? false) !== true) {
        AuditLog::record([
            'action'       => TeamHarvestService::AUDIT_CATCHUP,
            'actor_role'   => 'system',
            'subject_type' => 'team_harvest_awards',
            'subject_id'   => null,
            'meta'         => [
                'trigger'  => 'cli',
                'dry_run'  => false,
                'member'   => $member > 0 ? $member : null,
                'members'  => $members,
                'paid'     => (int) ($result['paid'] ?? 0),
                'baseline' => (int) ($result['baseline'] ?? 0),
                'amount'   => number_format((float) ($result['amount'] ?? 0), 2, '.', ''),
                'limit'    => (int) $options['limit'],
            ],
        ]);
    }

    return [0, team_harvest_catchup_format_run($result, $payload), $payload];
}

/* --------------------------------------------------------------
 *  Dispatch
 * -------------------------------------------------------------- */

$options = team_harvest_catchup_options();

if ($options['help']) {
    echo team_harvest_catchup_usage() . PHP_EOL;
    exit(0);
}

$log = null;

try {
    /* Probe the connection before db() is touched: db() answers a failed
     * connection with an HTML page and exit 0, which a run cannot tell
     * from success (plan §14.11: an unreachable database exits 2). */
    new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $tables = (int) db()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('harvest_queue', 'team_harvest_levels', 'team_harvest_awards')"
    )->fetchColumn();

    if ($tables !== 3) {
        $message = 'The Team Harvest tables are missing; apply migration 024_team_harvest.sql.';

        if (!$options['json']) {
            fwrite(STDERR, $message . PHP_EOL);
        }

        team_harvest_catchup_emit($options, ['Team Harvest catch-up FAILED', '  ' . $message], [
            'ok' => false, 'error' => $message,
        ]);
        exit(2);
    }

    if (!$options['doctor']) {
        /* The doctor is read-only and never takes the lock, so it can
         * always answer, even while a run is in progress. An evaluation
         * refuses to overlap another evaluation: two runs of the same
         * member would fight over the same wallet lock for no benefit. */
        $lock = new CronLock('team_harvest_catchup');

        if (!$lock->acquire()) {
            $payload = ['ok' => true, 'busy' => true, 'message' => 'another run holds the catch-up lock'];
            team_harvest_catchup_emit(
                $options,
                ['EcoWealth Team Harvest catch-up', '  busy: another run holds the lock; nothing was evaluated.'],
                $payload
            );
            exit(0);
        }

        $log = new CronLog(null, 'team_harvest_catchup');

        try {
            [$exit, $lines, $payload] = team_harvest_catchup_run($options);
        } finally {
            $lock->release();
        }
    } else {
        [$exit, $lines, $payload] = team_harvest_catchup_run($options);
    }

    $level = $exit === 0 ? 'OK' : 'WARN';

    if ($log instanceof CronLog) {
        $log->write($level, sprintf(
            'trigger=cli dry_run=%d member=%d members=%d paid=%d baseline=%d amount=%.2f',
            $options['dry-run'] ? 1 : 0,
            (int) $options['member'],
            (int) ($payload['members'] ?? 0),
            (int) ($payload['result']['paid'] ?? 0),
            (int) ($payload['result']['baseline'] ?? 0),
            (float) ($payload['result']['amount'] ?? 0)
        ));
    }

    team_harvest_catchup_emit($options, $lines, $payload);
} catch (PDOException $e) {
    $message = 'The Team Harvest catch-up could not run: ' . $e->getMessage();

    if (!$options['json']) {
        fwrite(STDERR, $message . PHP_EOL);
    }

    team_harvest_catchup_emit($options, ['Team Harvest catch-up FAILED', '  ' . $message], [
        'ok' => false, 'error' => $message,
    ]);
    exit(2);
} catch (Throwable $e) {
    $message = 'The Team Harvest catch-up failed: ' . $e->getMessage();

    if (!$options['json']) {
        fwrite(STDERR, $message . PHP_EOL);
    }

    if ($log instanceof CronLog) {
        $log->write('ERROR', 'run failed: ' . $e->getMessage());
    }

    team_harvest_catchup_emit($options, ['Team Harvest catch-up FAILED', '  ' . $message], [
        'ok' => false, 'error' => $message, 'reason' => $e instanceof TeamHarvestException ? $e->reason : 'error',
    ]);
    exit(1);
}

exit($exit);
