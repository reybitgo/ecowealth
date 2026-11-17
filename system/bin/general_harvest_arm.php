<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — General Harvest arm (plan Phase 21C §14.10, §12.6)
 *
 *      php bin/general_harvest_arm.php [flags]
 *      bin\general_harvest_arm.cmd [flags]        (Windows launcher)
 *
 *  The **one-time, on-demand** go-live job of the shared board. It
 *  records `N₀` — the queue length at that instant — and one `baseline`
 *  row for every block that was already full, at **all ten levels**,
 *  then arms the board. Nothing is paid: every row is `baseline` with
 *  `ledger_id NULL`, and the wallet is untouched (P21C-T9).
 *
 *  It is a CLI-only act on purpose (Rule 2): there is no web entry and
 *  no settings-page button, so there is no URL to authenticate and no
 *  stale form that could arm an old queue. The order the refusals
 *  enforce is **configure → arm → enable**:
 *
 *      enabled         switch the stream off before arming (Rule 3)
 *      armed           one baseline per network; there is no --force
 *      unseeded        run bin/harvest_queue_seed.php first (Rule 4)
 *      invalid_ladder  all ten amounts must be positive (Rule 5, D-23)
 *      not_gapless     COUNT(*) <> MAX(id) (Rule 11, D-03)
 *
 *  Flags:
 *    --dry-run       compute and print everything; write nothing. The
 *                    dry run is the decision surface (§12.6 Rule 4) and
 *                    prints the same numbers the real run commits.
 *    --json          the documented summary only: `armed`, `n`, `rows`,
 *                    `excluded`, `per_level`, `dry_run`. A refusal uses
 *                    the shared `{ok:false, error, reason}` shape instead.
 *    --quiet         only the one-line summary.
 *    --help          this text.
 *
 *  Exit codes: 0 success (or a clean dry run); 1 refused (already armed,
 *  enabled, unseeded, invalid ladder, not gapless, another run holds the
 *  lock); 2 environment (database unreachable, tables missing).
 *
 *  Rollback: the rows and the board may stay — they are a correct record
 *  of what was already earned at go-live, and nothing was paid. To
 *  abandon the stream, `UPDATE general_harvest_board SET baseline_n =
 *  NULL, armed_at = NULL, armed_by = NULL, settled_n = 0` and truncate
 *  `general_harvest_awards`; there is no money to reverse.
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

/** @return array{dry-run:bool, json:bool, quiet:bool, help:bool} */
function general_harvest_arm_options(): array
{
    $options = [
        'dry-run' => false,
        'json'    => false,
        'quiet'   => false,
        'help'    => false,
    ];

    $parsed = getopt('', ['dry-run', 'json', 'quiet', 'help']);

    if (!is_array($parsed)) {
        return $options;
    }

    foreach (array_keys($options) as $flag) {
        $options[$flag] = isset($parsed[$flag]);
    }

    return $options;
}

function general_harvest_arm_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth General Harvest arm — the go-live baseline',
        '',
        'Usage: php bin/general_harvest_arm.php [flags]',
        '',
        '  --dry-run   compute and print everything; write nothing',
        '  --json      the documented summary only (armed, n, rows, excluded, per_level, dry_run)',
        '  --quiet     only the one-line summary',
        '  --help      this text',
        '',
        'Run it once, at go-live, after the ladder is configured and after',
        'Phase 20\'s queue is seeded, and BEFORE the stream is switched on:',
        '',
        '  php bin/general_harvest_arm.php --dry-run',
        '  php bin/general_harvest_arm.php',
        '',
        'The dry run prints N0, the number of baseline rows, the excluded amount',
        '(boardTotal(N0)) and the first positions, level by level. Read it: it is',
        'the whole commitment the arm freezes. The real run writes exactly those',
        'rows, in one transaction, and one general_harvest.arm audit row.',
        '',
        'It moves no money. Every row it writes is `baseline` with no wallet',
        'reference: a block that was already full when the stream was armed is',
        'recorded for ever and never paid (D-06). A second arm is refused — the',
        'baseline is irreversible, and only a business reset can unarm the board.',
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
function general_harvest_arm_emit(array $options, array $lines, array $payload): void
{
    if ($options['quiet']) {
        echo general_harvest_arm_summary_line($payload) . PHP_EOL;

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
function general_harvest_arm_summary_line(array $payload): string
{
    if (($payload['ok'] ?? true) !== true) {
        return '[FAIL ] ' . (string) ($payload['error'] ?? 'the job refused');
    }

    return sprintf(
        '[%s] %s n=%d rows=%d excluded=%s',
        ($payload['dry_run'] ?? false) === true ? 'DRY  ' : 'OK   ',
        ($payload['armed'] ?? false) === true ? 'armed' : 'not armed',
        (int) ($payload['n'] ?? 0),
        (int) ($payload['rows'] ?? 0),
        money((float) ($payload['excluded'] ?? 0))
    );
}

/* --------------------------------------------------------------
 *  The human report
 * -------------------------------------------------------------- */

/**
 * @param array<string, mixed> $result the service's arm() answer
 * @return list<string>
 */
function general_harvest_arm_format(array $result): array
{
    $dry = ($result['dry_run'] ?? false) === true;

    $lines = [
        'EcoWealth General Harvest arm' . ($dry ? ' (dry run — nothing is written)' : ''),
    ];

    $lines[] = '  database:   ' . DB_NAME . ' (' . DB_HOST . ')';
    $lines[] = '  queue:      N0 = ' . (int) $result['n'] . ' position(s) · gapless (COUNT(*) = MAX(id))';
    $lines[] = '  baseline:   ' . (int) $result['rows'] . ' row(s) across '
        . count(array_filter((array) $result['per_level'], static fn (int $count): bool => $count > 0))
        . ' level(s)';
    $lines[] = '  excluded:   ' . money((float) $result['excluded'])
        . ' — what the network had already earned; recorded and never paid';

    if ($dry) {
        $lines[] = '  board:      unarmed (a dry run writes nothing)';
    } else {
        $lines[] = '  board:      armed ' . (string) ($result['armed_at'] ?? '')
            . ' · baseline_n = ' . (int) $result['n'] . ' · settled_n = ' . (int) $result['n'];
        $lines[] = '  audit:      #' . (int) ($result['audit_id'] ?? 0) . ' ' . GeneralHarvestService::AUDIT_ARM;
    }

    /* The whole ladder, level by level: the dry run must show every
     * amount it freezes, including the levels with no complete block. */
    $lines[] = '';
    $lines[] = 'All ten levels (the amounts freeze at this instant, D-23):';

    foreach ((array) $result['per_level'] as $level => $count) {
        $level  = (int) $level;
        $amount = (float) ((array) $result['amounts'])[$level];

        if ($count <= 0) {
            $lines[] = sprintf('  level %-2d  %s  no complete block at this N0', $level, money($amount));
            continue;
        }

        $lines[] = sprintf(
            '  level %-2d  %-4d row(s)  seq 0–%-6d %s each  %s',
            $level,
            $count,
            $count - 1,
            money($amount),
            money($amount * $count)
        );
    }

    $preview = (array) $result['preview'];

    if ($preview !== []) {
        $lines[] = '';
        $lines[] = 'The first ' . count($preview) . ' baseline position(s):';
        $lines[] = '     node   member                level   threshold N   amount';

        foreach ($preview as $row) {
            $lines[] = sprintf(
                '     %-5d  %-20s  %-6d  %-12d  %s',
                (int) $row['seq'],
                '@' . (string) $row['username'],
                (int) $row['level'],
                (int) $row['threshold_n'],
                money((float) $row['amount'])
            );
        }
    }

    $lines[] = '';

    if ($dry) {
        $lines[] = 'Nothing was written. Run again without --dry-run to record the baseline.';
    } else {
        $lines[] = 'Nothing was paid: every row is `baseline` with a NULL wallet reference.';
        $lines[] = 'The board is armed. Configure the ladder (it is now frozen) and turn the';
        $lines[] = 'stream on — the settlement itself is Phase 21D.';
    }

    return $lines;
}

/* --------------------------------------------------------------
 *  The run
 * -------------------------------------------------------------- */

/**
 * @param array<string, mixed> $options
 * @return array{0:int, 1:list<string>, 2:array<string, mixed>} exit code, lines, payload
 */
function general_harvest_arm_run(array $options): array
{
    $service = new GeneralHarvestService();
    $result  = $service->arm((bool) $options['dry-run']);

    $payload = [
        'armed'     => (bool) $result['armed'],
        'n'         => (int) $result['n'],
        'rows'      => (int) $result['rows'],
        'excluded'  => (float) $result['excluded'],
        'per_level' => (array) $result['per_level'],
        'dry_run'   => (bool) $result['dry_run'],
    ];

    return [0, general_harvest_arm_format($result), $payload];
}

/* --------------------------------------------------------------
 *  Dispatch
 * -------------------------------------------------------------- */

$options = general_harvest_arm_options();

if ($options['help']) {
    echo general_harvest_arm_usage() . PHP_EOL;
    exit(0);
}

try {
    /* Probe the connection before db() is touched: db() answers a failed
     * connection with an HTML page and exit 0, which a go-live run cannot
     * tell from success (plan §14.10: an unreachable database exits 2). */
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

        general_harvest_arm_emit($options, ['General Harvest arm FAILED', '  ' . $message], [
            'ok' => false, 'error' => $message,
        ]);
        exit(2);
    }

    [$exit, $lines, $payload] = general_harvest_arm_run($options);
} catch (PDOException $e) {
    $message = 'The General Harvest arm could not reach the database: ' . $e->getMessage();

    if (!$options['json']) {
        fwrite(STDERR, $message . PHP_EOL);
    }

    general_harvest_arm_emit($options, ['General Harvest arm FAILED', '  ' . $message], [
        'ok' => false, 'error' => $message,
    ]);
    exit(2);
} catch (Throwable $e) {
    $reason  = $e instanceof GeneralHarvestException ? $e->reason : 'error';
    $message = $e->getMessage();

    if (!$options['json']) {
        fwrite(STDERR, 'General Harvest arm refused (' . $reason . '): ' . $message . PHP_EOL);
    }

    general_harvest_arm_emit(
        $options,
        [
            'EcoWealth General Harvest arm — REFUSED (' . $reason . ')',
            '  ' . $message,
            '',
            'Nothing was written. Fix the cause above and run it again.',
        ],
        ['ok' => false, 'error' => $message, 'reason' => $reason]
    );
    exit(1);
}

general_harvest_arm_emit($options, $lines, $payload);

exit($exit);
