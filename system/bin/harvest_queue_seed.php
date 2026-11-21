<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — Team Harvest queue seed (plan Phase 20C §14.10, D-04)
 *
 *      php bin/harvest_queue_seed.php [flags]
 *      bin\harvest_queue_seed.cmd [flags]        (Windows launcher)
 *
 *  The **one-time, on-demand** go-live job. It reconstructs the Harvest
 *  queue for the tree that already exists, records each member's
 *  baseline (`baseline_n`, the `n` they hold at that instant) and only
 *  then switches the live hook on by writing
 *  `team_harvest_ledger_since` — which is why it is not a cron entry and
 *  why the go-live order is deploy → dry run → seed → enable (D-04).
 *
 *  What it does, in one transaction (§14.10):
 *
 *    1. take the `harvest_queue_seed` advisory lock;
 *    2. read the gate and refuse anything outside 1–10;
 *    3. reconstruct the positions: the gate-th direct's (created_at, id)
 *       — or the member's own id with --by-id;
 *    4. refuse a queue that already has rows (or is already seeded)
 *       unless --force, which appends only the members that are missing;
 *    5. insert the rows, recount every member's `n`, write `teammates`
 *       and `baseline_n = teammates` for the rows it creates;
 *    6. write the baseline instant and the `harvest.seed` audit row.
 *
 *  `--rebuild` is the second half of the job: it recounts every counter,
 *  reports the drift and the gate holders with no row, and repairs
 *  nothing unless `--apply` (which appends only, never renumbers — Rule
 *  10, §14.4).
 *
 *  Flags:
 *    --dry-run       compute and print everything, write nothing. Allowed
 *                    on a non-empty queue, where it also reports how the
 *                    would-be order differs from the positions assigned.
 *    --by-id         order by the member's own id instead of the third
 *                    direct's (created_at, id) — the fallback for
 *                    imported or backdated data.
 *    --force         required to run on a non-empty queue; appends only
 *                    the missing members, after the existing positions.
 *    --rebuild       recount instead of seeding; reports drift, violations
 *                    and members that hold the gate but have no row.
 *    --apply         with --rebuild: repair the counters and append the
 *                    missing members (dry run otherwise).
 *    --json          machine-readable summary.
 *    --quiet         only the one-line summary.
 *    --help          this text.
 *
 *  Exit codes: 0 success / nothing to do; 1 refused (already seeded, a
 *  non-empty queue without --force, an invalid gate, drift found by a
 *  rebuild dry run); 2 environment (database unreachable, tables missing).
 * ============================================================== */

$root = dirname(__DIR__);

require_once $root . '/app/config.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/autoload.php';
require_once $root . '/app/database.php';

date_default_timezone_set(APP_TIMEZONE);

/* --------------------------------------------------------------
 *  Options
 * -------------------------------------------------------------- */

/**
 * @return array{dry-run:bool, by-id:bool, force:bool, rebuild:bool, apply:bool,
 *               json:bool, quiet:bool, help:bool}
 */
function harvest_seed_options(): array
{
    $options = [
        'dry-run' => false,
        'by-id'   => false,
        'force'   => false,
        'rebuild' => false,
        'apply'   => false,
        'json'    => false,
        'quiet'   => false,
        'help'    => false,
    ];

    $parsed = getopt('', ['dry-run', 'by-id', 'force', 'rebuild', 'apply', 'json', 'quiet', 'help']);

    if (!is_array($parsed)) {
        return $options;
    }

    foreach (array_keys($options) as $flag) {
        $options[$flag] = isset($parsed[$flag]);
    }

    return $options;
}

function harvest_seed_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth Team Harvest queue seed',
        '',
        'Usage: php bin/harvest_queue_seed.php [flags]',
        '',
        '  --dry-run   compute and print everything; write nothing',
        '  --by-id     order by member id instead of the third direct\'s timestamp',
        '  --force     run on a non-empty queue; append only the missing members',
        '  --rebuild   recount the counters instead of seeding',
        '  --apply     with --rebuild: repair the drift and append the missing members',
        '  --json      machine-readable summary',
        '  --quiet     only the one-line summary',
        '  --help      this text',
        '',
        'Run it once, at go-live, before team_harvest_enabled is turned on:',
        '',
        '  php bin/harvest_queue_seed.php --dry-run',
        '  php bin/harvest_queue_seed.php',
        '',
        'It reconstructs the queue from the tree, records every member\'s',
        'baseline and writes team_harvest_ledger_since, which is what makes the',
        'registration hook live. It moves no money: levels already complete at',
        'the baseline are recorded by the award engine (Phase 20D) as',
        '`baseline` rows and are never paid (D-07).',
        '',
        'The rebuild drill:',
        '',
        '  php bin/harvest_queue_seed.php --rebuild            (report only)',
        '  php bin/harvest_queue_seed.php --rebuild --apply    (repair)',
    ]);
}

/* --------------------------------------------------------------
 *  Output
 * -------------------------------------------------------------- */

/**
 * @param array<string, mixed> $options
 * @param list<string> $lines
 * @param array<string, mixed> $payload
 */
function harvest_seed_emit(array $options, array $lines, array $payload): void
{
    if ($options['quiet']) {
        echo harvest_seed_summary_line($payload) . PHP_EOL;

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
function harvest_seed_summary_line(array $payload): string
{
    if (($payload['ok'] ?? false) !== true) {
        return '[FAIL ] ' . (string) ($payload['error'] ?? $payload['message'] ?? 'the job refused');
    }

    if (($payload['rebuild'] ?? false) === true) {
        return sprintf(
            '[%s] rebuild queued=%d drift=%d violations=%d missing=%d repaired=%d appended=%d',
            ($payload['applied'] ?? false) === true ? 'OK   ' : 'CHECK',
            (int) ($payload['queued'] ?? 0),
            count((array) ($payload['drift'] ?? [])),
            count((array) ($payload['violations'] ?? [])),
            count((array) ($payload['missing'] ?? [])),
            count((array) ($payload['repaired'] ?? [])),
            count((array) ($payload['appended'] ?? []))
        );
    }

    return sprintf(
        '[%s] seed=%s inserted=%d members=%d baseline=%s',
        ($payload['dry_run'] ?? false) === true ? 'DRY  ' : 'OK   ',
        (string) ($payload['seeded'] ?? 'no'),
        (int) ($payload['inserted'] ?? 0),
        (int) ($payload['members'] ?? 0),
        (string) ($payload['instant'] ?? '—')
    );
}

/* --------------------------------------------------------------
 *  The human report
 * -------------------------------------------------------------- */

/**
 * @param array<string, mixed> $result the model's seed() answer
 * @return list<string>
 */
function harvest_seed_format(array $result): array
{
    $lines = ['EcoWealth Team Harvest queue seed'];

    if (($result['ok'] ?? false) !== true) {
        $lines[] = '  refused:   ' . (string) ($result['message'] ?? 'the seed refused to run.');
        $lines[] = '  nothing was written.';

        return $lines;
    }

    if (($result['dry_run'] ?? false) === true) {
        $lines[0] .= ' (dry run — nothing is written)';
    }

    $lines[] = '  database:  ' . DB_NAME . ' (' . DB_HOST . ')';
    $lines[] = '  gate:      ' . (int) $result['gate'] . ' direct member account(s)';
    $lines[] = '  order:     ' . ((string) $result['order'] === 'id'
        ? 'member id (--by-id)'
        : 'qualified_at — the gate-th direct\'s created_at');
    $lines[] = '  members:   ' . (int) $result['members'] . ' member(s) hold the gate';
    $lines[] = '  queued:    ' . (int) $result['queued'] . ' position(s) already assigned · '
        . (int) $result['skipped'] . ' candidate(s) skipped';

    if (($result['dry_run'] ?? false) === true) {
        $lines[] = '  would add: ' . count((array) ($result['positions'] ?? [])) - (int) $result['skipped']
            . ' position(s)';
    } else {
        $lines[] = '  inserted:  ' . (int) $result['inserted'] . ' position(s)';
    }

    $diff = (array) ($result['diff'] ?? []);

    if (($diff['changed'] ?? 0) > 0) {
        $lines[] = '  drift vs the assigned order: ' . (int) $diff['changed'] . ' position(s) would differ'
            . ' (this order is NOT applied — positions are never renumbered):';

        foreach ((array) ($diff['rows'] ?? []) as $row) {
            $lines[] = sprintf(
                '     @%s  current #%d  would be #%d',
                (string) $row['username'],
                (int) $row['current'],
                (int) $row['would_be']
            );
        }
    }

    $baseline = (array) ($result['baseline'] ?? []);
    $lines[] = '  baseline:  ' . ((string) $result['instant'] === ''
        ? 'not written'
        : (string) $result['instant'] . ' (team_harvest_ledger_since)');
    $lines[] = '  excludes:  ' . (int) ($baseline['levels'] ?? 0) . ' level(s) already complete at the baseline'
        . ' · ' . money((float) ($baseline['amount'] ?? 0))
        . ' over ' . (int) ($baseline['members'] ?? 0) . ' member(s) — recorded as baseline rows by 20D,'
        . ' never credited (D-07)';

    $positions = (array) ($result['preview'] ?? []);

    if ($positions !== []) {
        $lines[] = '';

        if (($result['dry_run'] ?? false) === true) {
            $lines[] = 'The first ' . count($positions) . ' position(s) this run would assign:';
        } else {
            $lines[] = 'The first ' . count($positions) . ' position(s) of the queue:';
        }

        foreach ($positions as $position) {
            $lines[] = sprintf(
                '  #%-4d @%-20s qualified %s  directs %d  %s',
                (int) $position['position'],
                (string) $position['username'],
                (string) $position['qualified_at'],
                (int) $position['directs'],
                (string) $position['source']
            );
        }
    }

    if (($result['truncated'] ?? false) === true) {
        $lines[] = '  … the report lists the first ' . HarvestQueue::POSITION_LIMIT
            . ' positions; the database has all ' . (int) $result['members'];
    }

    if (($result['dry_run'] ?? false) === true) {
        $lines[] = '';
        $lines[] = 'Nothing was written. Run again without --dry-run to reconstruct the queue.';
    } else {
        $lines[] = '';
        $lines[] = 'The registration hook is now live (team_harvest_ledger_since is set).';
        $lines[] = 'The stream is still switched off: turn on Team Harvest in /admin/settings → Commissions.';
    }

    return $lines;
}

/**
 * @param array<string, mixed> $result the model's rebuild() answer
 * @return list<string>
 */
function harvest_rebuild_format(array $result): array
{
    $lines = ['EcoWealth Team Harvest queue rebuild'];

    if (($result['applied'] ?? false) !== true) {
        $lines[0] .= ' (report only — nothing is written; add --apply to repair)';
    }

    $lines[] = '  database:  ' . DB_NAME . ' (' . DB_HOST . ')';
    $lines[] = '  gate:      ' . (string) ($result['gate'] === null ? 'invalid' : (string) (int) $result['gate']);
    $lines[] = '  queued:    ' . (int) $result['queued'] . ' position(s)';

    $drift = (array) ($result['drift'] ?? []);
    $lines[] = '  drift:     ' . count($drift) . ' counter(s) disagree with a from-scratch recount';

    foreach ($drift as $row) {
        $lines[] = sprintf(
            '     #%-4d @%-20s stored %d  recount %d   (baseline %d)',
            (int) $row['position'],
            (string) $row['username'],
            (int) $row['stored'],
            (int) $row['recount'],
            (int) $row['baseline_n']
        );
    }

    $violations = (array) ($result['violations'] ?? []);

    if ($violations !== []) {
        $lines[] = '  violations: ' . count($violations) . ' counter(s) a repair refuses to lower:';

        foreach ($violations as $row) {
            $lines[] = '     ' . (string) $row['message'];
        }
    }

    $missing = (array) ($result['missing'] ?? []);
    $lines[] = '  missing:   ' . count($missing) . ' member(s) hold the gate but have no position';

    foreach ($missing as $row) {
        $lines[] = sprintf(
            '     @%-20s qualified %s  directs %d',
            (string) $row['username'],
            (string) $row['qualified_at'],
            (int) $row['directs']
        );
    }

    if (($result['applied'] ?? false) === true) {
        $lines[] = '  repaired:  ' . count((array) ($result['repaired'] ?? [])) . ' counter(s)';
        $lines[] = '  appended:  ' . count((array) ($result['appended'] ?? [])) . ' position(s), after the existing ones';
    }

    $lines[] = '';
    $lines[] = ($result['ok'] ?? false) === true
        ? 'Every counter equals a full recount: the queue is clean.'
        : 'The queue is NOT clean — read the drift, violations and missing members above.';

    return $lines;
}

/* --------------------------------------------------------------
 *  The run
 * -------------------------------------------------------------- */

/**
 * @param array<string, mixed> $options
 * @return array{0:int, 1:array<string, mixed>} exit code and payload
 */
function harvest_seed_run(array $options): array
{
    $engine = new HarvestQueue();

    if ($options['rebuild']) {
        $result  = $engine->rebuild(!$options['apply'], $options['apply']);
        $payload = ['ok' => $result['ok'], 'rebuild' => true] + $result;
        $exit    = ($result['ok'] ?? false) === true ? 0 : 1;
        $lines   = harvest_rebuild_format($result);
    } else {
        $result = $engine->seed(
            $options['dry-run'],
            $options['by-id'],
            $options['force']
        );

        $payload = $result;
        $exit    = ($result['ok'] ?? false) === true ? 0 : 1;
        $lines   = harvest_seed_format($result);
    }

    harvest_seed_emit($options, $lines, $payload);

    return [$exit, $payload];
}

/* --------------------------------------------------------------
 *  Dispatch
 * -------------------------------------------------------------- */

$options = harvest_seed_options();

if ($options['help']) {
    echo harvest_seed_usage() . PHP_EOL;
    exit(0);
}

try {
    /* Probe the connection before db() is touched: db() answers a failed
     * connection with an HTML page and exit 0, which a go-live run cannot
     * tell from success (plan §14.10: an unreachable database exits 2).
     * The outer catch turns this PDOException into that exit code. */
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

        if ($options['json'] !== true) {
            fwrite(STDERR, $message . PHP_EOL);
        }

        harvest_seed_emit(
            $options,
            ['Team Harvest queue seed FAILED', '  ' . $message],
            ['ok' => false, 'error' => $message]
        );
        exit(2);
    }

    [$exit, $payload] = harvest_seed_run($options);
} catch (PDOException $e) {
    $message = 'The Team Harvest queue seed could not reach the database: ' . $e->getMessage();

    if ($options['json'] !== true) {
        fwrite(STDERR, $message . PHP_EOL);
    }

    harvest_seed_emit(
        $options,
        ['Team Harvest queue seed FAILED', '  ' . $message],
        ['ok' => false, 'error' => $message]
    );
    exit(2);
} catch (Throwable $e) {
    $message = 'The Team Harvest queue seed failed: ' . $e->getMessage();

    if ($options['json'] !== true) {
        fwrite(STDERR, $message . PHP_EOL);
    }

    harvest_seed_emit(
        $options,
        ['Team Harvest queue seed FAILED', '  ' . $message],
        ['ok' => false, 'error' => $message]
    );
    exit(1);
}

exit($exit);
