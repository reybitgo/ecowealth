#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — database reset CLI (plan IR-5, Appendix C)
 *
 *      php bin/db_reset.php --dry-run
 *      php bin/db_reset.php --force --confirm=ecowealth_sneat --codes=5 --code-package=1
 *      php bin/db_reset.php --force --confirm=ecowealth_sneat --reset-catalogue
 *      php bin/db_reset.php --force --confirm=ecowealth_sneat --clear-audit --json
 *
 *  This file is a front-end only: every delete, the transaction, the
 *  staff reset, the catalogue re-seed, the audit row, the live bump,
 *  the code batch and the verification live in
 *  app/Services/DatabaseResetService.php. This script owns flags,
 *  preview output, the typed confirmation, progress, exit codes and
 *  JSON — nothing else.
 *
 *  Safety rules it keeps (plan 5.7, 7.15):
 *    • CLI only: a web request gets a 404, never a half-usable reset.
 *    • The confirmation is always required: --confirm=<database>, or the
 *      typed database name at the prompt. --force never skips it; it only
 *      lets a non-interactive run pass the value on the command line.
 *    • APP_ENV=production additionally requires --allow-production.
 *    • --json prints exactly one JSON document on STDOUT; progress goes
 *      to STDERR. Human mode prints the preview, one line per step, the
 *      verification checklist and the fresh codes (if any).
 *
 *  Exit codes: 0 reset + verified, 1 reset committed but verification
 *              failed, 2 usage/preflight, 3 aborted/refused.
 * ============================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$root = dirname(__DIR__);

require_once $root . '/app/config.php';
require_once $root . '/app/autoload.php';

date_default_timezone_set(APP_TIMEZONE);

/* --------------------------------------------------------------
 *  Flags
 * -------------------------------------------------------------- */

function db_reset_usage(mixed $stream): void
{
    fwrite($stream, <<<'TXT'
Usage: php bin/db_reset.php [options]

  (no mode flag)              Preview, ask for the typed database name, reset.
  --dry-run                   Preview + statement plan. Writes nothing.
  --force                     Non-interactive: --confirm=<database> is mandatory.
  --confirm=NAME              The database name; the only accepted confirmation.
  --keep-catalogue            Keep products/packages (default).
  --reset-catalogue           Delete and re-seed the starter catalogue (003/004).
  --codes=N                   Generate 0–50 unused registration codes (default 0).
  --code-package=ID           Package bound to the generated codes (default: first active).
  --demo                      Seed the demo tree (not available yet — plan phase IR-7).
  --keep-audit                Keep the audit log (default); the reset row is appended.
  --clear-audit               Delete the audit log before appending the reset row.
  --keep-uploads              Leave proof images on disk (the rows are still deleted).
  --allow-production          Required when APP_ENV=production.
  --json                      Print one JSON report on STDOUT and nothing else.
  --help, -h                  This help.

Exit: 0 reset+verified, 1 reset committed but verification failed,
      2 usage/preflight, 3 aborted/refused.

The target is DB_NAME from app/config.php (or the ECO_DB_NAME environment
variable). A reset keeps both staff accounts and their passwords; it deletes
every member and every business row, and keeps the settings.

Examples:
  php bin/db_reset.php --dry-run
  php bin/db_reset.php --force --confirm=ecowealth_sneat --codes=5 --code-package=1
  php bin/db_reset.php --force --confirm=ecowealth_sneat --reset-catalogue
  php bin/db_reset.php --allow-production --confirm=u123456_ecowealth

The full report shape is documented in app/Services/DatabaseResetService.php.

TXT);
}

/**
 * Parse `--flag`, `--flag=value` and `-h`.
 *
 * @param list<string> $argv
 * @return array{options:array<string, mixed>, error:?string}
 */
function db_reset_parse_args(array $argv): array
{
    $options = [
        'help'             => false,
        'dry_run'          => false,
        'force'            => false,
        'json'             => false,
        'keep_catalogue'   => false,
        'reset_catalogue'  => false,
        'keep_audit'       => false,
        'clear_audit'      => false,
        'keep_uploads'     => false,
        'demo'             => false,
        'allow_production' => false,
        'codes'            => null,
        'code_package'     => null,
        'confirm'          => null,
    ];

    $flags = [
        'dry-run'          => 'dry_run',
        'force'            => 'force',
        'json'             => 'json',
        'keep-catalogue'   => 'keep_catalogue',
        'reset-catalogue'  => 'reset_catalogue',
        'keep-audit'       => 'keep_audit',
        'clear-audit'      => 'clear_audit',
        'keep-uploads'     => 'keep_uploads',
        'demo'             => 'demo',
        'allow-production' => 'allow_production',
        'help'             => 'help',
    ];

    $valued = [
        'codes'        => 'codes',
        'code-package' => 'code_package',
        'confirm'      => 'confirm',
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '-h') {
            $options['help'] = true;

            continue;
        }

        if ($argument === '' || !str_starts_with($argument, '--')) {
            return ['options' => $options, 'error' => 'Unknown argument: ' . $argument];
        }

        $body   = substr($argument, 2);
        $equals = strpos($body, '=');
        $name   = $equals === false ? $body : substr($body, 0, $equals);
        $value  = $equals === false ? null : substr($body, $equals + 1);

        if (isset($flags[$name])) {
            if ($value !== null) {
                return ['options' => $options, 'error' => '--' . $name . ' does not take a value.'];
            }

            $options[$flags[$name]] = true;

            continue;
        }

        if (isset($valued[$name])) {
            if ($value === null) {
                return ['options' => $options, 'error' => '--' . $name . ' needs a value: --' . $name . '=…'];
            }

            $options[$valued[$name]] = $value;

            continue;
        }

        return ['options' => $options, 'error' => 'Unknown option: --' . $name];
    }

    if ($options['keep_catalogue'] && $options['reset_catalogue']) {
        return ['options' => $options, 'error' => '--keep-catalogue and --reset-catalogue are mutually exclusive.'];
    }

    if ($options['keep_audit'] && $options['clear_audit']) {
        return ['options' => $options, 'error' => '--keep-audit and --clear-audit are mutually exclusive.'];
    }

    if ($options['codes'] !== null && preg_match('/^\s*[+-]?\d+\s*$/', (string) $options['codes']) !== 1) {
        return ['options' => $options, 'error' => '--codes needs a whole number between 0 and 50.'];
    }

    if ($options['code_package'] !== null && preg_match('/^\s*[1-9]\d*\s*$/', (string) $options['code_package']) !== 1) {
        return ['options' => $options, 'error' => '--code-package needs a positive package id.'];
    }

    return ['options' => $options, 'error' => null];
}

/* --------------------------------------------------------------
 *  Terminal input
 * -------------------------------------------------------------- */

function db_reset_is_interactive(): bool
{
    if (!defined('STDIN') || !function_exists('stream_isatty')) {
        return false;
    }

    return @stream_isatty(STDIN);
}

/**
 * Ask for the typed confirmation and return what was typed.
 *
 * Unlike a yes/no, typing the database name makes an accidental Enter
 * harmless and cannot be satisfied by a pasted `y`.
 */
function db_reset_prompt(string $database): ?string
{
    fwrite(STDOUT, PHP_EOL . 'Type "' . db_reset_label($database) . '" to continue, or Ctrl+C to abort: ');

    $line = fgets(STDIN);

    return $line === false ? null : trim($line);
}

/* --------------------------------------------------------------
 *  Output
 * -------------------------------------------------------------- */

/** A value as it may appear in a terminal line. */
function db_reset_label(string $value, int $max = 64): string
{
    $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $value);

    return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
}

/** @return list<string> */
function db_reset_wrap(string $text, int $width = 96): array
{
    $wrapped = wordwrap($text, max(40, $width), "\n", false);

    return array_values(array_filter(explode("\n", $wrapped), static fn (string $line): bool => $line !== ''));
}

function db_reset_status(string $status): string
{
    return match ($status) {
        'ok'   => ' OK ',
        'skip' => 'SKIP',
        default => 'FAIL',
    };
}

/**
 * Print the preview block (plan 7.13).
 *
 * @param array<string, mixed> $data preview() or dryRun()
 */
function db_reset_print_preview(array $data, bool $dryRun): void
{
    $counts = (array) $data['counts'];
    $options = (array) $data['options'];

    fwrite(STDOUT, 'EcoWealth database reset' . PHP_EOL);
    fwrite(STDOUT, '  target : ' . db_reset_label((string) $data['database']) . ' at '
        . DB_HOST . ':' . DB_PORT . ' (' . (string) ($data['server_version'] ?? 'unknown') . ')' . PHP_EOL);
    fwrite(STDOUT, '  mode   : ' . ($dryRun ? 'dry run — no writes' : 'preview, then the typed confirmation') . PHP_EOL);
    fwrite(STDOUT, sprintf(
        '  rows   : %d members · %d staff · %d orders · %d ledger · %d codes' . PHP_EOL,
        (int) ($counts['members'] ?? 0),
        (int) ($counts['users'] ?? 0) - (int) ($counts['members'] ?? 0),
        (int) ($counts['shop_orders'] ?? 0),
        (int) ($counts['ewallet_ledger'] ?? 0),
        (int) ($counts['reg_codes'] ?? 0)
    ));

    fwrite(STDOUT, sprintf(
        '  catalogue: %d products / %d packages (%s)' . PHP_EOL,
        (int) ($data['catalogue']['products'] ?? 0),
        (int) ($data['catalogue']['packages'] ?? 0),
        !empty($options['reset_catalogue']) ? 'delete and re-seed' : 'kept'
    ));

    fwrite(STDOUT, sprintf(
        '  options: audit %s (%d rows) · codes %d%s · uploads %s · demo %s' . PHP_EOL,
        !empty($options['clear_audit']) ? 'cleared' : 'kept',
        (int) ($counts['audit_log'] ?? 0),
        (int) ($options['code_qty'] ?? 0),
        !empty($options['code_qty']) ? ' (package #' . (string) ($options['code_package'] ?? 'auto') . ')' : '',
        !empty($options['keep_uploads']) ? 'kept' : 'proofs deleted',
        'no'
    ));

    $lastResetAt = $data['last_reset_at'] ?? null;

    fwrite(
        STDOUT,
        $lastResetAt === null
            ? '  last reset: never' . PHP_EOL
            : '  last reset: ' . (string) $lastResetAt . ' by '
                . (string) ($data['last_reset_by'] ?? '?') . PHP_EOL
    );

    if (isset($data['packages']) && is_array($data['packages']) && $data['packages'] !== []) {
        $packages = [];

        foreach ($data['packages'] as $package) {
            $packages[] = '#' . (int) $package['id'] . ' ' . (string) $package['name']
                . ' (' . (string) $package['status'] . ')';
        }

        fwrite(STDOUT, '  packages: ' . implode(' · ', $packages) . PHP_EOL);
    }

    foreach ($data['warnings'] as $warning) {
        foreach (db_reset_wrap((string) $warning) as $line) {
            fwrite(STDERR, 'warning: ' . $line . PHP_EOL);
        }
    }
}

/**
 * Print the dry-run statement plan.
 *
 * @param list<array<string, mixed>> $plan
 */
function db_reset_print_plan(array $plan, int $statements): void
{
    fwrite(STDOUT, PHP_EOL . 'Plan (' . $statements . ' statement(s), 0 writes)' . PHP_EOL);

    foreach ($plan as $step) {
        printf(
            "  %-20s  %-52s %d\n",
            (string) $step['key'],
            (string) $step['label'],
            (int) $step['statements']
        );
    }
}

/** @param array<string, mixed> $report */
function db_reset_print_report(array $report): void
{
    fwrite(STDOUT, PHP_EOL . 'Reset' . PHP_EOL);

    $width = 16;

    foreach ($report['steps'] as $step) {
        $width = max($width, strlen((string) $step['key']));
    }

    $width = min($width, 22);

    foreach ($report['steps'] as $step) {
        $rows = $step['deleted'] ?? $step['rows'] ?? 0;

        printf(
            "  %s  %-{$width}s  %-46s %5d %s (%d ms)\n",
            db_reset_status((string) $step['status']),
            (string) $step['key'],
            (string) $step['label'],
            (int) $rows,
            isset($step['deleted']) ? 'deleted' : 'rows',
            (int) $step['ms']
        );

        if (isset($step['error'])) {
            foreach (db_reset_wrap((string) $step['error']) as $line) {
                fwrite(STDOUT, '        ' . $line . PHP_EOL);
            }
        }
    }

    fwrite(STDOUT, PHP_EOL . 'Verification' . PHP_EOL);

    $width = 16;

    foreach ($report['verification']['checks'] as $check) {
        $width = max($width, strlen((string) $check['label']));
    }

    $width = min($width, 28);

    foreach ($report['verification']['checks'] as $check) {
        printf(
            "  %s  %-{$width}s  %s\n",
            (bool) $check['ok'] ? ' OK ' : 'FAIL',
            (string) $check['label'],
            (string) $check['detail']
        );
    }

    $countsBefore = (array) $report['counts_before'];
    $countsAfter  = (array) $report['counts_after'];
    $deleted      = 0;

    foreach ($report['steps'] as $step) {
        $deleted += isset($step['deleted']) ? (int) $step['deleted'] : 0;
    }

    fwrite(STDOUT, PHP_EOL . sprintf(
        '  deleted: %d rows · members %d → %d · orders %d → %d · ledger %d → %d · codes %d → %d' . PHP_EOL,
        $deleted,
        (int) ($countsBefore['members'] ?? 0),
        (int) ($countsAfter['members'] ?? 0),
        (int) ($countsBefore['shop_orders'] ?? 0),
        (int) ($countsAfter['shop_orders'] ?? 0),
        (int) ($countsBefore['ewallet_ledger'] ?? 0),
        (int) ($countsAfter['ewallet_ledger'] ?? 0),
        (int) ($countsBefore['reg_codes'] ?? 0),
        (int) ($countsAfter['reg_codes'] ?? 0)
    ));

    fwrite(STDOUT, sprintf(
        '  catalogue: %d products / %d packages · audit %d rows · live %d surfaces · counters reset %d'
            . PHP_EOL,
        (int) ($countsAfter['products'] ?? 0),
        (int) ($countsAfter['packages'] ?? 0),
        (int) ($countsAfter['audit_log'] ?? 0),
        (int) ($countsAfter['live_revisions'] ?? 0),
        (int) ($report['auto_increment']['reset'] ?? 0)
    ));

    if (($report['codes']['list'] ?? []) !== []) {
        fwrite(STDOUT, PHP_EOL . 'Registration codes (' . (string) $report['codes']['package'] . ')' . PHP_EOL);

        foreach (array_chunk($report['codes']['list'], 4) as $chunk) {
            fwrite(STDOUT, '  ' . implode('   ', $chunk) . PHP_EOL);
        }
    }

    foreach ($report['warnings'] as $warning) {
        foreach (db_reset_wrap((string) $warning) as $line) {
            fwrite(STDERR, 'warning: ' . $line . PHP_EOL);
        }
    }

    if ($report['ok']) {
        fwrite(STDOUT, PHP_EOL . 'Reset complete and verified.' . PHP_EOL);

        foreach ($report['staff']['usernames'] as $username) {
            fwrite(STDOUT, '  sign in: ' . (string) $username . PHP_EOL);
        }

        fwrite(
            STDOUT,
            '  next:    sign in as admin, confirm the empty member/order lists, then delete '
                . 'bin/db_reset.php on a production host.' . PHP_EOL
        );

        return;
    }

    fwrite(
        STDERR,
        PHP_EOL . 'Reset committed, but verification failed — read the failing checks above and run '
            . 'php bin/wallet_reconcile.php before letting anyone sign in.' . PHP_EOL
    );
}

/** One JSON document, trailing newline, no other output. */
function db_reset_json(array $data): string
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        $json = '{"tool":"db_reset","version":1,"ok":false,"error":{"kind":"json","message":"The report could not be encoded."}}';
    }

    return $json . PHP_EOL;
}

/**
 * The same top-level keys as a successful report (plan Appendix D).
 *
 * @return array<string, mixed>
 */
function db_reset_error_report(Throwable $e, string $database, string $mode): array
{
    $kind    = $e instanceof DatabaseToolException ? $e->kind : 'error';
    $hint    = $e instanceof DatabaseToolException ? $e->hint : null;
    $context = $e instanceof DatabaseToolException ? $e->context : [];

    return [
        'tool'           => 'db_reset',
        'version'        => DatabaseResetService::REPORT_VERSION,
        'ok'             => false,
        'mode'           => $mode,
        'database'       => $database,
        'server_version' => null,
        'started_at'     => date('c'),
        'duration_ms'    => 0,
        'options'        => [],
        'steps'          => [],
        'counts_before'  => [],
        'counts_after'   => [],
        'staff'          => ['reset' => 0, 'usernames' => []],
        'audit'          => ['kept' => true, 'rows_before' => 0, 'cleared' => false, 'rows_after' => 0],
        'auto_increment' => ['reset' => 0, 'failed' => 0, 'tables' => []],
        'uploads'        => ['deleted' => 0, 'failed' => 0, 'directories' => []],
        'codes'          => ['generated' => 0, 'expected' => 0, 'package' => null, 'package_id' => null, 'list' => []],
        'demo'           => ['installed' => false, 'members' => 0, 'codes' => 0, 'credentials' => []],
        'verification'   => ['ok' => false, 'checks' => []],
        'warnings'       => [],
        'error'          => [
            'kind'    => $kind,
            'message' => $e->getMessage(),
            'hint'    => $hint,
            'context' => $context,
        ],
    ];
}

function db_reset_exit_code(Throwable $e): int
{
    if (!$e instanceof DatabaseToolException) {
        return 1;
    }

    return match ($e->kind) {
        DatabaseToolException::KIND_USAGE,
        DatabaseToolException::KIND_INVALID_NAME,
        DatabaseToolException::KIND_PREFLIGHT => 2,
        DatabaseToolException::KIND_LOCKED,
        DatabaseToolException::KIND_REFUSED => 3,
        default => 1,
    };
}

/* --------------------------------------------------------------
 *  Command
 * -------------------------------------------------------------- */

/** @param list<string> $argv */
function db_reset_cli(array $argv): int
{
    $parsed = db_reset_parse_args($argv);

    if ($parsed['error'] !== null) {
        fwrite(STDERR, 'db_reset: ' . $parsed['error'] . PHP_EOL);
        db_reset_usage(STDERR);

        return 2;
    }

    /** @var array<string, mixed> $cli */
    $cli = $parsed['options'];

    if ($cli['help']) {
        db_reset_usage(STDOUT);

        return 0;
    }

    $json     = (bool) $cli['json'];
    $dryRun   = (bool) $cli['dry_run'];
    $database = (string) DB_NAME;
    $mode     = $dryRun ? 'dry_run' : 'reset';

    if ($database === '') {
        fwrite(STDERR, 'db_reset: DB_NAME is empty; set ECO_DB_NAME or define DB_NAME in app/config.local.php.' . PHP_EOL);

        return 2;
    }

    try {
        /* ---- Production guard, before any connection ------------- */
        if (!$dryRun && APP_ENV === 'production' && !$cli['allow_production']) {
            throw DatabaseToolException::refused(
                'The target runs with APP_ENV=production and the reset needs --allow-production.',
                ['database' => $database, 'env' => APP_ENV],
                'A reset is a destructive maintenance operation. Confirm the change window and run the command '
                    . 'again with --allow-production and --confirm=' . $database . '.'
            );
        }

        $target = DbTarget::fromConfig();
        $pdo    = $target->connect($database);

        $service = new DatabaseResetService($pdo, $database, [
            'env'              => APP_ENV,
            'tool'             => 'cli',
            'confirm'          => $cli['confirm'] !== null ? (string) $cli['confirm'] : null,
            'code_qty'         => $cli['codes'] !== null ? (string) $cli['codes'] : 0,
            'code_package'     => $cli['code_package'] !== null ? (string) $cli['code_package'] : null,
            'reset_catalogue'  => (bool) $cli['reset_catalogue'],
            'keep_catalogue'   => (bool) $cli['keep_catalogue'],
            'clear_audit'      => (bool) $cli['clear_audit'],
            'keep_audit'       => (bool) $cli['keep_audit'],
            'keep_uploads'     => (bool) $cli['keep_uploads'],
            'demo'             => (bool) $cli['demo'],
            'allow_production' => (bool) $cli['allow_production'],
            'actor_role'       => 'system',
            'ip'               => '127.0.0.1',
        ]);

        /* ---- Dry run: read-only, no confirmation needed ---------- */
        if ($dryRun) {
            $plan = $service->dryRun();

            if ($json) {
                fwrite(STDOUT, db_reset_json($plan));
            } else {
                db_reset_print_preview($plan, true);
                db_reset_print_plan($plan['plan'], (int) $plan['statements']);
                fwrite(STDOUT, PHP_EOL . ($plan['ok']
                    ? 'Dry run complete — nothing was written.'
                    : 'Dry run cannot run: fix the warnings above first.') . PHP_EOL);
            }

            return $plan['ok'] ? 0 : 2;
        }

        /* ---- Preview -------------------------------------------- */
        $preview = $service->preview();

        if (!$json) {
            db_reset_print_preview($preview, false);
        }

        /* ---- Confirmation --------------------------------------- */
        $confirm = $cli['confirm'] !== null ? (string) $cli['confirm'] : null;

        if ($cli['force']) {
            if ($confirm === null) {
                throw DatabaseToolException::usage(
                    '--force is a non-interactive run and needs --confirm=<database>.',
                    ['database' => $database],
                    'Pass --confirm=' . $database . ' so the target is named explicitly, or drop --force '
                        . 'and type the name at the prompt.'
                );
            }
        } elseif ($confirm === null) {
            if (!db_reset_is_interactive() || $json) {
                throw DatabaseToolException::refused(
                    'The reset needs a typed confirmation, but this run has no terminal.',
                    ['database' => $database],
                    'Pass --force --confirm=' . $database . ' for a non-interactive run; --force skips only the '
                        . 'prompt, never the confirmation or the preflight.'
                );
            }

            $typed = db_reset_prompt($database);

            if ($typed === null || $typed !== $database) {
                fwrite(STDERR, 'db_reset: aborted — the typed name did not match; nothing was deleted.' . PHP_EOL);

                return 3;
            }

            $confirm = $typed;
        }

        /* Rebuild the service with the confirmed value (the preview
         * above never needed it). */
        $service = new DatabaseResetService($pdo, $database, [
            'env'              => APP_ENV,
            'tool'             => 'cli',
            'confirm'          => $confirm,
            'code_qty'         => $cli['codes'] !== null ? (string) $cli['codes'] : 0,
            'code_package'     => $cli['code_package'] !== null ? (string) $cli['code_package'] : null,
            'reset_catalogue'  => (bool) $cli['reset_catalogue'],
            'keep_catalogue'   => (bool) $cli['keep_catalogue'],
            'clear_audit'      => (bool) $cli['clear_audit'],
            'keep_audit'       => (bool) $cli['keep_audit'],
            'keep_uploads'     => (bool) $cli['keep_uploads'],
            'demo'             => (bool) $cli['demo'],
            'allow_production' => (bool) $cli['allow_production'],
            'actor_role'       => 'system',
            'ip'               => '127.0.0.1',
        ]);

        $report = $service->run();

        if ($json) {
            fwrite(STDOUT, db_reset_json($report));
        } else {
            db_reset_print_report($report);
        }

        return $report['ok'] ? 0 : 1;
    } catch (DatabaseToolException $e) {
        if ($json) {
            fwrite(STDOUT, db_reset_json(db_reset_error_report($e, $database, $mode)));
        } else {
            fwrite(STDERR, 'db_reset: ' . $e->getMessage() . PHP_EOL);

            if ($e->hint !== null && $e->hint !== '') {
                foreach (db_reset_wrap($e->hint) as $line) {
                    fwrite(STDERR, '  hint: ' . $line . PHP_EOL);
                }
            }
        }

        return db_reset_exit_code($e);
    } catch (Throwable $e) {
        if ($json) {
            fwrite(STDOUT, db_reset_json(db_reset_error_report($e, $database, $mode)));
        } else {
            fwrite(STDERR, 'db_reset: ' . $e->getMessage() . PHP_EOL);
        }

        return 1;
    }
}

/* Only run when this file is the entry point; a test may include it for
 * the parser and the report helpers. */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(db_reset_cli($argv ?? []));
}
