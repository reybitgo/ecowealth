<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — General Harvest catch-up and doctor (plan Phase 21D §14.11)
 *
 *      php bin/general_harvest_catchup.php [flags]
 *      bin\general_harvest_catchup.cmd [flags]        (Windows launcher)
 *
 *  The on-demand entry point of a **state-based** engine. There is no
 *  cron entry and no monthly close (D-08, §14.15): the stream pays on a
 *  queue append, and this job exists for the three moments a
 *  state-based engine needs a human to press go —
 *
 *    • after enabling the stream (settle the backlog the off switch
 *      created: §14.14 steps 7–8),
 *    • after a repair or a counter-drift alarm,
 *    • after a deploy that fixed a bug.
 *
 *  It is also the **doctor**: a read-only report of the stream's health
 *  (clocks, tables, settings, armed state, gapless, queue, settled,
 *  backlog, per-level frontier, paid, baseline, open and the reconcile
 *  verdict). The doctor is what an operator runs before deciding to
 *  enable the stream and after any alarm.
 *
 *  Flags:
 *    --dry-run       settle nothing; report N, settled_n, the per-level
 *                    missing rows, the open liability and the amount
 *    --member=ID     settle one member's node only — a single position,
 *                    for a dispute drill
 *    --doctor        report the stream's health without settling
 *    --json          machine-readable output
 *    --limit=N       positions per transaction, 1–5000 (default 500)
 *    --quiet         only the one-line summary
 *    --help          this text
 *
 *  Safety:
 *    • one run at a time (`storage/locks/general_harvest_catchup.lock`);
 *    • the switch off is not a failure: it writes nothing and reports
 *      `off` (D-10);
 *    • an unarmed board is a refusal (exit 1) — run bin/general_harvest_arm.php
 *      first (§14.3 Rule 1);
 *    • a batch is one transaction: a failure rolls the batch back and
 *      the next run retries the same positions (§14.7);
 * *    the run audits `general_harvest.catchup` with what it settled and
 *      what it paid (§15.4). A dry run and the doctor write nothing.
 *
 *  Exit codes: 0 clean / nothing to do / busy; 1 refused (unarmed,
 *  disabled) or a problem found (drift, reconcile mismatch, doctor red);
 *  2 environment (database unreachable, missing tables, another run holds
 *  the lock file and cannot be read).
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

/** @return array{dry-run:bool, doctor:bool, json:bool, quiet:bool, help:bool,
 *               member:int, limit:int} */
function general_harvest_catchup_options(): array
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

function general_harvest_catchup_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth General Harvest catch-up and doctor',
        '',
        'Usage: php bin/general_harvest_catchup.php [flags]',
        '',
        '  --dry-run     settle nothing; report the open liability and the per-level counts',
        '  --member=ID   settle one member\'s node only — a single position, for a dispute drill',
        '  --doctor      report the stream\'s health without settling',
        '  --json        machine-readable output',
        '  --limit=N     positions per transaction, 1–5000 (default 500)',
        '  --quiet       only the one-line summary',
        '  --help        this text',
        '',
        'A completed block pays once, into the withdrawable e-wallet, with an',
        'award row and a wallet movement written in one transaction. Running the',
        'job again evaluates the same queue against the same ledger and writes',
        'nothing new: the engine is state-based, not event-based (plan D-19).',
        '',
        'Typical use (docs/runbook.md > "General Harvest award and reconcile drill"):',
        '',
        '  php bin/general_harvest_catchup.php --dry-run     read the liability',
        '  php bin/general_harvest_catchup.php              settle the backlog',
        '  php bin/general_harvest_catchup.php --doctor     prove the stream is healthy',
        '',
        'There is no cron entry for this job: General Harvest has no month and no',
        'schedule (plan D-08, §14.15).',
    ]);
}

/**
 * @param array<string, mixed> $options
 * @param list<string> $lines
 * @param array<string, mixed> $payload
 */
function general_harvest_catchup_emit(array $options, array $lines, array $payload): void
{
    if ($options['quiet']) {
        echo general_harvest_catchup_summary_line($payload) . PHP_EOL;

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
function general_harvest_catchup_summary_line(array $payload): string
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
            '[DRY  ] liability levels=%d pairs=%d amount=%s',
            (int) ($payload['liability']['levels'] ?? 0),
            (int) ($payload['liability']['pairs'] ?? 0),
            money((float) ($payload['liability']['amount'] ?? 0))
        );
    }

    return sprintf(
        '[%s] settled=%d written=%d amount=%s unpriced=%s',
        ($payload['result']['off'] ?? false) === true ? 'OFF  ' : 'OK   ',
        (int) ($payload['positions'] ?? 0),
        (int) ($payload['result']['written'] ?? 0),
        money((float) ($payload['result']['amount'] ?? 0)),
        implode(',', (array) ($payload['result']['unpriced'] ?? []))
    );
}

/* --------------------------------------------------------------
 *  The human reports
 * -------------------------------------------------------------- */

/**
 * The dry-run report: what the engine would settle, level by level.
 *
 * @param array<string, mixed> $liability
 * @return list<string>
 */
function general_harvest_catchup_format_dry(array $liability): array
{
    $lines = [
        'EcoWealth General Harvest catch-up (dry run — nothing is settled and nothing is written)',
        '  database:   ' . DB_NAME . ' (' . DB_HOST . ')',
        '  liability:  ' . money((float) ($liability['amount'] ?? 0))
            . ' across ' . (int) ($liability['levels'] ?? 0) . ' level(s) of '
            . (int) ($liability['positions'] ?? 0) . ' position(s)',
        '  levels:',
    ];

    $any = false;

    foreach ((array) ($liability['pairs'] ?? []) as $row) {
        if ((int) ($row['missing'] ?? 0) === 0) {
            continue;
        }

        $any = true;
        $lines[] = sprintf(
            '     level %-2d  %-4d block(s)  %s',
            (int) $row['level'],
            (int) $row['missing'],
            money((float) $row['amount'])
        );
    }

    if (!$any) {
        $lines[] = '     none — no complete block above the baseline lacks an award row';
    }

    $lines[] = '';
    $lines[] = 'Run again without --dry-run to settle exactly these positions.';

    return $lines;
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $payload
 * @return list<string>
 */
function general_harvest_catchup_format_run(array $result, array $payload): array
{
    $lines = ['EcoWealth General Harvest catch-up'];
    $lines[] = '  database:   ' . DB_NAME . ' (' . DB_HOST . ')';
    $lines[] = '  settled:    ' . (int) ($result['settled_n'] ?? 0) . ' position(s)'
        . (($payload['member'] ?? 0) > 0 ? ' (member filter #' . (int) $payload['member'] . ')' : '');
    $lines[] = '  written:    ' . (int) ($result['written'] ?? 0) . ' award(s) · '
        . money((float) ($result['amount'] ?? 0));

    $unpriced = (array) ($result['unpriced'] ?? []);

    if ($unpriced !== []) {
        $lines[] = '  unpriced:   level(s) ' . implode(', ', $unpriced)
            . ' — complete but have no amount; skipped, never paid';
    }

    if (($result['off'] ?? false) === true) {
        $lines[] = '';
        $lines[] = 'The stream is switched off (general_harvest_enabled = 0): nothing was settled.'
            . ' The queue keeps recording positions (plan D-10).';
    } elseif ((int) ($result['written'] ?? 0) === 0) {
        $lines[] = '';
        $lines[] = 'Nothing to do: every complete block already has its award row.';
    }

    return $lines;
}

/**
 * @param array<string, mixed> $report
 * @return list<string>
 */
function general_harvest_catchup_format_doctor(array $report): array
{
    $lines = ['EcoWealth General Harvest — doctor'];
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
 * A read-only health report: clock, tables, settings, armed state,
 * gapless, queue count, settled, backlog, per-level frontier, paid,
 * baseline, open and the reconcile verdict (plan §14.11).
 *
 * @return array{ok:bool, checks:list<array{key:string, label:string, ok:bool, detail:string}>,
 *               warnings:list<string>, reconcile:array<string, mixed>}
 */
function general_harvest_catchup_doctor(): array
{
    $checks   = [];
    $warnings = [];

    $check = static function (string $key, string $label, bool $ok, string $detail) use (&$checks): void {
        $checks[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    };

    /* 1. The clock: PHP and MySQL must agree about "now", or a
     *    `credited_at` and a baseline can be compared wrongly. */
    $offset = null;

    try {
        $offset = abs(time() - (int) db()->query('SELECT UNIX_TIMESTAMP()')->fetchColumn());
        $check('clock', 'PHP and MySQL clocks', $offset <= 60, $offset . ' s apart (tolerance 60 s)');
    } catch (Throwable $e) {
        $check('clock', 'PHP and MySQL clocks', false, 'could not read the MySQL clock: ' . $e->getMessage());
    }

    /* 2. The four tables. */
    $present = [];
    $tableError = '';

    try {
        $present = array_map('strval', db()->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('harvest_queue', 'general_harvest_levels',
                                   'general_harvest_board', 'general_harvest_awards')"
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        $tableError = $e->getMessage();
    }

    $missing = array_values(array_diff(['harvest_queue', 'general_harvest_levels', 'general_harvest_board', 'general_harvest_awards'], $present));
    $check(
        'tables',
        'The four General Harvest tables',
        $missing === [],
        $missing === []
            ? 'harvest_queue, general_harvest_levels, general_harvest_board and general_harvest_awards exist'
            : ('missing: ' . implode(', ', $missing) . ($tableError === '' ? '' : ' (' . $tableError . ')'))
    );

    /* 3. The settings. */
    $settingValues = [];

    try {
        $settingValues = db()->query(
            "SELECT key_name, value FROM settings WHERE key_name LIKE 'general\\_harvest\\_%'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $check('settings', 'The two General Harvest settings', false, 'could not read settings: ' . $e->getMessage());
    }

    $absent = array_values(array_diff(
        ['general_harvest_enabled', 'general_harvest_max_levels'],
        array_keys($settingValues)
    ));
    $switch = trim((string) ($settingValues['general_harvest_enabled'] ?? ''));
    $depth  = trim((string) ($settingValues['general_harvest_max_levels'] ?? ''));
    $switchOk = in_array(strtolower($switch), ['0', '1'], true);
    $depthOk  = preg_match('/\A\d+\z/', $depth) === 1
        && (int) $depth >= GeneralHarvestLevel::LEVEL_MIN && (int) $depth <= GeneralHarvestLevel::LEVEL_MAX;

    $check(
        'settings',
        'The two General Harvest settings',
        $absent === [] && $switchOk && $depthOk,
        ($absent === [] ? 'both present' : 'missing: ' . implode(', ', $absent))
            . ' · switch ' . ($switchOk ? $switch : 'INVALID (' . $switch . ')')
            . ' · depth ' . ($depthOk ? $depth : 'INVALID (' . $depth . ')')
    );

    /* 4. The queue. */
    $queue   = GeneralHarvestBoard::queueN();
    $board   = GeneralHarvestBoard::state();
    $service = new GeneralHarvestService();

    $check(
        'queue',
        'The Harvest queue',
        true,
        $queue['n'] . ' position(s) · gapless ' . ($queue['gapless'] ? 'yes' : 'no')
            . ' · next #' . ($queue['max_id'] + 1)
    );

    /* 5. Armed state and the switch. */
    $armed  = $service->armed();
    $enabled = $service->enabled();

    $check(
        'armed',
        'The board is armed',
        $armed,
        $armed ? 'baseline_n = ' . ($service->baseline() ?? '—') : 'not armed — run bin/general_harvest_arm.php'
    );

    if (!$armed) {
        $warnings[] = 'The board is not armed: nothing can pay until bin/general_harvest_arm.php runs.';
    }

    if (!$enabled) {
        $warnings[] = 'The stream is switched off (general_harvest_enabled = 0); the queue still records positions.';
    }

    /* 6. The backlog and the open liability. */
    $backlog = $service->backlog();
    $open    = $service->openLiability();

    $check(
        'backlog',
        'The unsettled positions',
        true,
        $backlog['positions'] . ' position(s) · worth ' . money((float) $backlog['amount'])
    );

    $check(
        'open',
        'The open liability',
        true,
        money((float) $open['amount']) . ' across ' . count((array) $open['pairs']) . ' level(s)'
    );

    /* 7. The awards and the baseline. */
    $paid = GeneralHarvestAward::countByStatus();

    $check(
        'awards',
        'The award ledger',
        true,
        $paid['credited'] . ' credited · ' . $paid['baseline'] . ' baseline (before go-live, never paid)'
    );

    /* 8. The reconcile verdict. */
    $reconcile = ['ok' => false, 'checks' => []];

    try {
        $reconcile = $service->reconcile();
    } catch (Throwable $e) {
        $reconcile = ['ok' => false, 'checks' => [['message' => $e->getMessage()]]];
    }

    $check(
        'reconcile',
        'The General Harvest reconciliation',
        (bool) ($reconcile['ok'] ?? false),
        (bool) ($reconcile['ok'] ?? false)
            ? 'credits = wallet = closed form'
            : count((array) ($reconcile['checks'] ?? [])) . ' mismatch(es)'
    );

    $ok = true;

    foreach ($checks as $row) {
        if (($row['ok'] ?? false) !== true) {
            $ok = false;
        }
    }

    return [
        'ok'      => $ok,
        'queue_n' => (int) $queue['n'],
        'settled_n' => (int) $board['settled_n'],
        'gapless' => (bool) $queue['gapless'],
        'armed'   => (bool) $board['armed'],
        'enabled' => $enabled,
        'checks'  => $checks,
        'warnings'=> $warnings,
        'reconcile' => $reconcile,
    ];
}

/* --------------------------------------------------------------
 *  The run
 * -------------------------------------------------------------- */

/**
 * @param array<string, mixed> $options
 * @return array{0:int, 1:list<string>, 2:array<string, mixed>} exit code, lines, payload
 */
function general_harvest_catchup_run(array $options): array
{
    $service = new GeneralHarvestService();

    if ($options['doctor']) {
        $report = general_harvest_catchup_doctor();
        $lines  = general_harvest_catchup_format_doctor($report);

        return [
            ($report['ok'] ?? false) === true ? 0 : 1,
            $lines,
            ['ok' => (bool) ($report['ok'] ?? false), 'doctor' => $report],
        ];
    }

    /* The switch off is not a failure — nothing is settled, but the
     * queue keeps recording positions (D-10). */
    if (!$service->enabled()) {
        return [
            1,
            [
                'EcoWealth General Harvest catch-up FAILED',
                '  refused: the stream is switched off (general_harvest_enabled = 0).',
                '  Turn it on first — nothing is settled while it is off (plan D-10).',
            ],
            ['ok' => false, 'error' => 'disabled', 'result' => ['off' => true]],
        ];
    }

    if (!$service->armed()) {
        return [
            1,
            [
                'EcoWealth General Harvest catch-up FAILED',
                '  refused: the board is not armed.',
                '  Run bin/general_harvest_arm.php first — nothing can pay until the baseline exists (plan §14.3 Rule 1).',
            ],
            ['ok' => false, 'error' => 'unarmed'],
        ];
    }

    if ($options['dry-run']) {
        $liability = $service->openLiability();

        return [
            0,
            general_harvest_catchup_format_dry($liability),
            ['ok' => true, 'dry_run' => true, 'liability' => $liability],
        ];
    }

    $member = (int) $options['member'];

    if ($member > 0) {
        /* One member's node only — a single position, for a dispute drill.
         * Settle exactly one position: the member's node. */
        $position = GeneralHarvestBoard::positionOf($member);

        if ($position === null) {
            return [
                1,
                [
                    'EcoWealth General Harvest catch-up FAILED',
                    '  refused: member #' . $member . ' is not on the Harvest queue.',
                    '  Only queued members hold a node and can be settled (plan §12.2).',
                ],
                ['ok' => false, 'error' => 'not_queued', 'member' => $member],
            ];
        }

        /* One position settles at most ten blocks (one per level). settle(1)
         * is enough: the prefix frontier means a single position completes
         * at most one block per level, and the board row lock keeps it
         * serialised with every other run (§14.8). */
        $result = $service->settle(1);

        $payload = [
            'ok'      => true,
            'trigger' => 'cli',
            'member'  => $member,
            'members' => 1,
            'result'  => $result,
            'positions' => (int) ($result['target'] ?? 0) - (int) ($result['settled_n'] ?? 0),
            'limit'   => (int) $options['limit'],
        ];

        AuditLog::record([
            'action'       => GeneralHarvestService::AUDIT_CATCHUP,
            'actor_id'     => null,
            'actor_role'   => 'system',
            'subject_type' => 'general_harvest_awards',
            'subject_id'   => null,
            'meta'         => [
                'trigger'  => 'cli',
                'dry_run'  => false,
                'member'   => $member,
                'members'  => 1,
                'paid'     => (int) ($result['written'] ?? 0),
                'amount'   => number_format((float) ($result['amount'] ?? 0), 2, '.', ''),
                'limit'    => (int) $options['limit'],
            ],
        ]);

        return [0, general_harvest_catchup_format_run($result, $payload), $payload];
    }

    $result = $service->settleAll((int) $options['limit']);

    $payload = [
        'ok'      => true,
        'trigger' => 'cli',
        'member'  => 0,
        'members' => 0,
        'result'  => $result,
        'positions' => (int) ($result['target'] ?? 0) - (int) ($result['settled_n'] ?? 0),
        'limit'   => (int) $options['limit'],
    ];

    /* Not audited: a dry run. Audited: every real evaluation, even one
     * that paid nothing, because "the operator ran it" is part of the
     * trail (§15.4). */
    AuditLog::record([
        'action'       => GeneralHarvestService::AUDIT_CATCHUP,
        'actor_id'     => null,
        'actor_role'   => 'system',
        'subject_type' => 'general_harvest_awards',
        'subject_id'   => null,
        'meta'         => [
            'trigger'  => 'cli',
            'dry_run'  => false,
            'member'   => null,
            'members'  => 0,
            'paid'     => (int) ($result['written'] ?? 0),
            'baseline' => 0,
            'amount'   => number_format((float) ($result['amount'] ?? 0), 2, '.', ''),
            'limit'    => (int) $options['limit'],
        ],
    ]);

    return [0, general_harvest_catchup_format_run($result, $payload), $payload];
}

/* --------------------------------------------------------------
 *  Dispatch
 * -------------------------------------------------------------- */

$options = general_harvest_catchup_options();

if ($options['help']) {
    echo general_harvest_catchup_usage() . PHP_EOL;
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
            AND TABLE_NAME IN ('harvest_queue', 'general_harvest_levels',
                               'general_harvest_board', 'general_harvest_awards')"
    )->fetchColumn();

    if ($tables !== 4) {
        $message = 'The General Harvest tables are missing; apply migration 025_general_harvest.sql.';

        if (!$options['json']) {
            fwrite(STDERR, $message . PHP_EOL);
        }

        general_harvest_catchup_emit($options, ['General Harvest catch-up FAILED', '  ' . $message], [
            'ok' => false, 'error' => $message,
        ]);
        exit(2);
    }

    if (!$options['doctor']) {
        $lock = new CronLock('general_harvest_catchup');

        if (!$lock->acquire()) {
            $payload = ['ok' => true, 'busy' => true, 'message' => 'another run holds the catch-up lock'];
            general_harvest_catchup_emit(
                $options,
                ['EcoWealth General Harvest catch-up', '  busy: another run holds the lock; nothing was settled.'],
                $payload
            );
            exit(0);
        }

        $log = new CronLog(null, 'general_harvest_catchup');

        try {
            [$exit, $lines, $payload] = general_harvest_catchup_run($options);
        } finally {
            $lock->release();
        }
    } else {
        [$exit, $lines, $payload] = general_harvest_catchup_run($options);
    }

    $level = $exit === 0 ? 'OK' : 'WARN';

    if ($log instanceof CronLog) {
        $log->write($level, sprintf(
            'trigger=cli dry_run=%d member=%d members=%d written=%d amount=%.2f',
            $options['dry-run'] ? 1 : 0,
            (int) $options['member'],
            (int) ($payload['members'] ?? 0),
            (int) ($payload['result']['written'] ?? 0),
            (float) ($payload['result']['amount'] ?? 0)
        ));
    }

    general_harvest_catchup_emit($options, $lines, $payload);
} catch (PDOException $e) {
    $message = 'The General Harvest catch-up could not run: ' . $e->getMessage();

    if (!$options['json']) {
        fwrite(STDERR, $message . PHP_EOL);
    }

    general_harvest_catchup_emit($options, ['General Harvest catch-up FAILED', '  ' . $message], [
        'ok' => false, 'error' => $message,
    ]);
    exit(2);
} catch (Throwable $e) {
    $message = 'The General Harvest catch-up failed: ' . $e->getMessage();

    if (!$options['json']) {
        fwrite(STDERR, $message . PHP_EOL);
    }

    if ($log instanceof CronLog) {
        $log->write('ERROR', 'run failed: ' . $e->getMessage());
    }

    general_harvest_catchup_emit($options, ['General Harvest catch-up FAILED', '  ' . $message], [
        'ok' => false, 'error' => $message, 'reason' => $e instanceof GeneralHarvestException ? $e->reason : 'error',
    ]);
    exit(1);
}

exit($exit);
