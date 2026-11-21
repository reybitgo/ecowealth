#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — command-line installer (plan IR-3)
 *
 *      php bin/db_install.php --check
 *      php bin/db_install.php --database=ecowealth_sneat \
 *        --admin-user=admin --admin-email=owner@example.com --admin-password-stdin \
 *        --superadmin-user=superadmin --superadmin-email=owner+super@example.com \
 *        --superadmin-password-stdin --site-name="EcoWealth" --force
 *
 *  This file is a front-end only: every database decision (preflight,
 *  guard, bundle import, credential policy, verification, the lock file)
 *  lives in app/Services/DatabaseInstaller.php. This script owns flags,
 *  prompts, progress, exit codes and JSON — nothing else.
 *
 *  Safety rules it keeps (plan §5.7, §6.8, §5.10):
 *    • CLI only: any web request gets a 404, never a half-usable installer.
 *    • --force skips the interactive confirmation, never the preflight.
 *    • Passwords come from --…-password, --…-password-stdin (one line each,
 *      admin first) or an echoed interactive prompt with a warning. They are
 *      never printed, logged or written to the report.
 *    • --json prints exactly one JSON document on STDOUT; everything else is
 *      progress on STDOUT and errors on STDERR (the CI contract).
 *
 *  Exit codes: 0 installed + verified, 1 preflight/verify failed,
 *              2 usage, 3 refused/locked.
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

function db_install_usage(mixed $stream): void
{
    fwrite($stream, <<<'TXT'
Usage: php bin/db_install.php [options]

  (no mode flag)              Install into an empty database (asks for confirmation).
  --check                     Preflight only: print every check, write nothing, create nothing.
  --database=NAME             Target schema (default: DB_NAME from app/config.php).
  --create-database           Create the schema when it does not exist.
  --admin-user=NAME           Admin username (default: admin).
  --admin-email=EMAIL         Admin email (default: the address seeded by 002_accounts.sql).
  --admin-password=PASS       Admin password (visible in the shell history).
  --admin-password-stdin      Read the admin password as one line from STDIN.
  --superadmin-user=NAME      Superadmin username (default: superadmin).
  --superadmin-email=EMAIL    Superadmin email (default: the seeded address).
  --superadmin-password=PASS  Superadmin password.
  --superadmin-password-stdin Read the superadmin password as one line from STDIN.
  --site-name=NAME            settings.site_name, 1–80 characters (default: EcoWealth).
  --with-demo                 Also seed the demo tree (development/QA only; never production).
  --key=KEY                   INSTALL_KEY; required when APP_ENV is production.
  --force                     Skip the interactive confirmation only — never the preflight.
  --json                      Print one JSON report on STDOUT and nothing else.
  --help, -h                  This help.

Exit: 0 installed+verified, 1 preflight/verify failed, 2 usage, 3 refused/locked.

Passwords are 12–72 characters and must not be a published default. When both
--…-password-stdin flags are given, the admin password is the first line and the
superadmin password the second:

  printf '%s\n%s\n' "$ADMIN_PW" "$SUPER_PW" | php bin/db_install.php --force \
    --admin-password-stdin --superadmin-password-stdin

Examples:
  php bin/db_install.php --check --database=ecowealth_sneat
  php bin/db_install.php --database=ecowealth_sneat --create-database --force \
    --admin-password-stdin --superadmin-password-stdin

The full report shape is documented in app/Services/DatabaseInstaller.php.

TXT);
}

/**
 * Parse `--flag`, `--flag=value` and `-h`.
 *
 * Unknown or malformed arguments are an error; the caller prints usage and
 * exits 2. Values must be attached with `=` so a missing value is caught
 * here instead of being read as the next flag.
 *
 * @param list<string> $argv
 * @return array{options:array<string, mixed>, error:?string}
 */
function db_install_parse_args(array $argv): array
{
    $options = [
        'help'                   => false,
        'check'                  => false,
        'json'                   => false,
        'force'                  => false,
        'create_database'        => false,
        'with_demo'              => false,
        'database'               => null,
        'key'                    => null,
        'site_name'              => null,
        'admin_username'         => null,
        'admin_email'            => null,
        'admin_password'         => null,
        'admin_password_stdin'   => false,
        'superadmin_username'    => null,
        'superadmin_email'       => null,
        'superadmin_password'    => null,
        'superadmin_password_stdin' => false,
    ];

    /* Value flags: --name → options key */
    $valued = [
        'database'          => 'database',
        'key'               => 'key',
        'site-name'         => 'site_name',
        'admin-user'        => 'admin_username',
        'admin-email'       => 'admin_email',
        'admin-password'    => 'admin_password',
        'superadmin-user'   => 'superadmin_username',
        'superadmin-email'  => 'superadmin_email',
        'superadmin-password' => 'superadmin_password',
    ];

    /* Boolean flags: --name → options key */
    $flags = [
        'check'                    => 'check',
        'json'                     => 'json',
        'force'                    => 'force',
        'create-database'          => 'create_database',
        'with-demo'                => 'with_demo',
        'admin-password-stdin'     => 'admin_password_stdin',
        'superadmin-password-stdin' => 'superadmin_password_stdin',
        'help'                     => 'help',
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

    return ['options' => $options, 'error' => null];
}

/* --------------------------------------------------------------
 *  Terminal input
 * -------------------------------------------------------------- */

function db_install_is_interactive(): bool
{
    if (!defined('STDIN')) {
        return false;
    }

    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }

    return false;
}

/** One line from STDIN, without its line ending. */
function db_install_read_stdin(string $what): string
{
    $line = fgets(STDIN);

    if ($line === false) {
        throw DatabaseToolException::usage(
            'No input was available for the ' . $what . '.',
            ['input' => $what],
            'Pipe the value on standard input, or pass it as a flag.'
        );
    }

    return rtrim($line, "\r\n");
}

/**
 * Prompt for a password.
 *
 * Hiding the echo portably (stty on Linux, a console API on Windows) is not
 * worth the fragility: the plan's policy is an "echoed prompt with a warning"
 * (plan §5.10), with --…-password-stdin as the non-echoing path.
 */
function db_install_prompt_password(string $role): string
{
    fwrite(STDOUT, PHP_EOL . 'The ' . $role . ' password is echoed by the terminal; '
        . 'use --' . $role . '-password-stdin to type it without echo.' . PHP_EOL);
    fwrite(STDOUT, ucfirst($role) . ' password: ');

    $line = fgets(STDIN);

    if ($line === false) {
        throw DatabaseToolException::usage(
            'No input was available for the ' . $role . ' password.',
            ['role' => $role],
            'Pass --' . $role . '-password-stdin and pipe the value on standard input.'
        );
    }

    return rtrim($line, "\r\n");
}

/**
 * Ask the operator to type the database name.
 *
 * Not a yes/no: typing the target makes an accidental Enter harmless and
 * mirrors the reset console's typed confirmation.
 */
function db_install_confirm(string $database): bool
{
    fwrite(STDOUT, PHP_EOL . 'About to install a fresh EcoWealth database into "'
        . db_install_label($database) . '".' . PHP_EOL);
    fwrite(STDOUT, 'Type the database name to continue, or press Enter to abort: ');

    $line = fgets(STDIN);

    if ($line === false) {
        return false;
    }

    return trim($line) === $database;
}

/* --------------------------------------------------------------
 *  Credentials
 * -------------------------------------------------------------- */

/**
 * The two credential blocks the service validates.
 *
 * Defaults for the usernames and emails mirror database/seeds/002_accounts.sql
 * (plan Appendix C: "admin / seed email"). Only the passwords are mandatory:
 * a fresh install must never keep a published default.
 *
 * @param array<string, mixed> $cli
 * @return array{admin:array<string,string>, superadmin:array<string,string>}
 */
function db_install_credentials(array $cli, bool $json): array
{
    $defaults = [
        'admin'      => ['admin', 'admin@sneat.dev'],
        'superadmin' => ['superadmin', 'superadmin@sneat.dev'],
    ];

    $credentials = [];

    foreach (['admin', 'superadmin'] as $role) {
        $username = $cli[$role . '_username'] ?? $defaults[$role][0];
        $email    = $cli[$role . '_email'] ?? $defaults[$role][1];

        $password = null;

        if (array_key_exists($role . '_password', $cli) && $cli[$role . '_password'] !== null) {
            $password = (string) $cli[$role . '_password'];
        } elseif (!empty($cli[$role . '_password_stdin'])) {
            $password = db_install_read_stdin($role . ' password');
        } elseif (!$json && db_install_is_interactive()) {
            $password = db_install_prompt_password($role);
        } else {
            throw DatabaseToolException::usage(
                'A password for the ' . $role . ' account is required.',
                ['role' => $role],
                'Pass --' . $role . '-password=… or --' . $role
                    . '-password-stdin (one line on standard input) so the install never keeps a default password.'
            );
        }

        $credentials[$role] = [
            'username' => (string) $username,
            'email'    => (string) $email,
            'password' => $password,
        ];
    }

    return $credentials;
}

/* --------------------------------------------------------------
 *  Output
 * -------------------------------------------------------------- */

/**
 * A value as it may appear in a terminal line.
 *
 * The database name comes from a flag or an environment variable; control
 * characters would let it rewrite the progress output. JSON output keeps the
 * raw value (the encoder escapes it); the human output shows this form.
 */
function db_install_label(string $value, int $max = 64): string
{
    $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $value);

    return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
}

/** Split a long hint into indented lines (no external wordwrap dependency). */
function db_install_wrap(string $text, int $width = 96): array
{
    $wrapped = wordwrap($text, max(40, $width), "\n", false);

    return array_values(array_filter(explode("\n", $wrapped), static fn (string $line): bool => $line !== ''));
}

function db_install_status(bool $ok, ?string $status = null): string
{
    if ($status === 'skip') {
        return 'SKIP';
    }

    return $ok ? 'OK' : 'FAIL';
}

/** @param array{ok:bool, database:string, checks:list<array<string,mixed>>} $preflight */
function db_install_print_preflight(array $preflight, string $database): void
{
    $width = 12;

    foreach ($preflight['checks'] as $check) {
        $width = max($width, strlen((string) $check['label']));
    }

    $width = min($width, 30);

    fwrite(STDOUT, PHP_EOL . 'Preflight' . PHP_EOL);

    foreach ($preflight['checks'] as $check) {
        printf(
            "  %-4s  %-{$width}s  %s\n",
            db_install_status((bool) $check['ok']),
            (string) $check['label'],
            (string) $check['detail']
        );

        if (!$check['ok'] && (string) $check['hint'] !== '') {
            foreach (db_install_wrap((string) $check['hint']) as $line) {
                fwrite(STDOUT, '        ' . $line . PHP_EOL);
            }
        }
    }

    $failed = 0;

    foreach ($preflight['checks'] as $check) {
        $failed += $check['ok'] ? 0 : 1;
    }

    fwrite(
        STDOUT,
        PHP_EOL . ($failed === 0
            ? 'Preflight passed — ' . db_install_label($database) . ' can be installed.'
            : sprintf('Preflight failed — %d check%s need%s attention.', $failed, $failed === 1 ? '' : 's', $failed === 1 ? 's' : ''))
        . PHP_EOL
    );
}

/** @param array<string, mixed> $report */
function db_install_print_report(array $report): void
{
    fwrite(STDOUT, PHP_EOL . 'Installing' . PHP_EOL);

    $width = 16;

    foreach ($report['steps'] as $step) {
        $width = max($width, strlen((string) $step['key']));
    }

    $width = min($width, 22);

    foreach ($report['steps'] as $step) {
        printf(
            "  %-4s  %-{$width}s  %s (%d ms)\n",
            db_install_status($step['status'] === 'ok', (string) $step['status']),
            (string) $step['key'],
            (string) $step['label'],
            (int) $step['ms']
        );

        if (isset($step['error'])) {
            foreach (db_install_wrap((string) $step['error']) as $line) {
                fwrite(STDOUT, '        ' . $line . PHP_EOL);
            }
        }
    }

    fwrite(STDOUT, PHP_EOL . 'Verification' . PHP_EOL);

    $width = 12;

    foreach ($report['checks'] as $check) {
        $width = max($width, strlen((string) $check['label']));
    }

    $width = min($width, 24);

    foreach ($report['checks'] as $check) {
        printf(
            "  %-4s  %-{$width}s  %s\n",
            db_install_status((bool) $check['ok']),
            (string) $check['label'],
            (string) $check['detail']
        );

        if (!$check['ok'] && (string) $check['hint'] !== '') {
            foreach (db_install_wrap((string) $check['hint']) as $line) {
                fwrite(STDOUT, '        ' . $line . PHP_EOL);
            }
        }
    }

    $counts = (array) $report['counts'];

    fwrite(STDOUT, PHP_EOL . sprintf(
        "  counts: tables %d · settings %d · staff %d · members %d · products %d · packages %d"
            . " · levels %d · contents %d · codes %d\n",
        (int) ($counts['tables_created'] ?? 0),
        (int) ($counts['settings_rows'] ?? 0),
        (int) ($counts['staff_rows'] ?? 0),
        (int) ($counts['members_rows'] ?? 0),
        (int) ($counts['products_rows'] ?? 0),
        (int) ($counts['packages_rows'] ?? 0),
        (int) ($counts['levels_rows'] ?? 0),
        (int) ($counts['contents_rows'] ?? 0),
        (int) ($counts['codes_rows'] ?? 0)
    ));

    foreach ($report['warnings'] as $warning) {
        foreach (db_install_wrap((string) $warning) as $line) {
            fwrite(STDERR, 'warning: ' . $line . PHP_EOL);
        }
    }

    if ($report['ok']) {
        fwrite(STDOUT, PHP_EOL . 'Installed and verified.' . PHP_EOL);

        if (!empty($report['lock']['written'])) {
            fwrite(STDOUT, '  lock:    ' . (string) $report['lock']['path'] . PHP_EOL);
        }

        foreach ($report['staff']['accounts'] as $account) {
            fwrite(STDOUT, '  sign in: ' . (string) $account['username'] . ' (' . (string) $account['role'] . ')' . PHP_EOL);
        }

        if (!empty($report['demo']['installed'])) {
            fwrite(STDOUT, '  demo:    5 members · 3 codes · 3 ledger rows — '
                . 'sign in as juan / ' . (string) $report['demo']['credentials'] . PHP_EOL);
        }

        fwrite(
            STDOUT,
            '  next:    change both staff passwords, then delete bin/db_install.php and database/install.sql'
                . ' before going live.' . PHP_EOL
        );

        return;
    }

    $lockFailed = false;

    foreach ($report['checks'] as $check) {
        if ((string) $check['key'] === 'lock' && !$check['ok']) {
            $lockFailed = true;
            break;
        }
    }

    fwrite(STDERR, PHP_EOL . ($lockFailed
        ? 'Installed and verified, but the install lock could not be written — create it by hand with the JSON '
            . 'from "lock.contents" so no previous run can install over this one.'
        : 'Installed, but verification failed — do not sign in until it is fixed.'
    ) . PHP_EOL);
}

/** One JSON document, trailing newline, no other output. */
function db_install_json(array $data): string
{
    /* The checks travel once, under verification.checks (plan Appendix D).
     * The PHP report keeps the top-level alias the front-ends read; the JSON
     * does not repeat ~10 rows of the same data. */
    unset($data['checks']);

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        $json = '{"tool":"db_install","version":1,"ok":false,"error":{"kind":"json","message":"The report could not be encoded."}}';
    }

    return $json . PHP_EOL;
}

/** The same top-level keys as a successful report (plan Appendix D). */
function db_install_error_report(Throwable $e, string $database, string $mode): array
{
    $kind    = $e instanceof DatabaseToolException ? $e->kind : 'error';
    $hint    = $e instanceof DatabaseToolException ? $e->hint : null;
    $context = $e instanceof DatabaseToolException ? $e->context : [];

    return [
        'tool'         => 'db_install',
        'version'      => DatabaseInstaller::REPORT_VERSION,
        'ok'           => false,
        'mode'         => $mode,
        'database'     => $database,
        'started_at'   => date('c'),
        'duration_ms'  => 0,
        'options'      => [],
        'steps'        => [],
        'counts'       => [],
        'staff'        => ['reset' => false, 'usernames' => [], 'accounts' => []],
        'lock'         => ['path' => null, 'written' => false, 'contents' => null, 'error' => null],
        'install_sql'  => [],
        'demo'         => ['installed' => false, 'members' => 0, 'codes' => 0, 'ledger_rows' => 0, 'wallets' => [], 'credentials' => []],
        'verification' => ['ok' => false, 'checks' => []],
        'checks'       => [],
        'warnings'     => [],
        'error'        => [
            'kind'    => $kind,
            'message' => $e->getMessage(),
            'hint'    => $hint,
            'context' => $context,
        ],
    ];
}

function db_install_exit_code(Throwable $e): int
{
    if (!$e instanceof DatabaseToolException) {
        return 1;
    }

    return match ($e->kind) {
        DatabaseToolException::KIND_USAGE,
        DatabaseToolException::KIND_INVALID_NAME => 2,
        DatabaseToolException::KIND_LOCKED,
        DatabaseToolException::KIND_REFUSED,
        DatabaseToolException::KIND_NOT_EMPTY => 3,
        default => 1,
    };
}

/** 0 pass, 3 when the target is locked or non-empty, 2 for a bad name, else 1. */
function db_install_preflight_exit(array $preflight): int
{
    if (!empty($preflight['ok'])) {
        return 0;
    }

    $existing = [];

    foreach ($preflight['checks'] as $check) {
        $existing[(string) $check['key']] = (bool) $check['ok'];
    }

    if (($existing['database_name'] ?? true) === false) {
        return 2;
    }

    if (($existing['install_lock'] ?? true) === false) {
        return 3;
    }

    /* A missing schema is a precondition failure (exit 1); a schema that
     * exists but is not empty is a refusal (exit 3). */
    if (($existing['database_empty'] ?? true) === false && ($existing['database_exists'] ?? false) === true) {
        return 3;
    }

    return 1;
}

/* --------------------------------------------------------------
 *  Command
 * -------------------------------------------------------------- */

/** @param list<string> $argv */
function db_install_cli(array $argv): int
{
    $parsed = db_install_parse_args($argv);

    if ($parsed['error'] !== null) {
        fwrite(STDERR, 'db_install: ' . $parsed['error'] . PHP_EOL);
        db_install_usage(STDERR);

        return 2;
    }

    /** @var array<string, mixed> $cli */
    $cli = $parsed['options'];

    if ($cli['help']) {
        db_install_usage(STDOUT);

        return 0;
    }

    $json     = (bool) $cli['json'];
    $database = $cli['database'] !== null ? (string) $cli['database'] : DB_NAME;
    $mode     = $cli['check'] ? 'check' : 'install';
    $key      = $cli['key'] !== null ? (string) $cli['key'] : (string) (getenv('ECO_INSTALL_KEY') ?: '');

    try {
        $installer = new DatabaseInstaller(DbTarget::fromConfig());

        if (!$json) {
            fwrite(STDOUT, 'EcoWealth database installer' . PHP_EOL);
            fwrite(STDOUT, '  database: ' . db_install_label($database) . ' at ' . DB_HOST . ':' . DB_PORT . PHP_EOL);
            fwrite(STDOUT, '  channel:  cli' . PHP_EOL);
            fwrite(STDOUT, '  mode:     ' . ($cli['check'] ? 'preflight only (no writes)' : 'install') . PHP_EOL);
        }

        /* ---- --check: preflight only -------------------------------- */
        if ($cli['check']) {
            $preflight = $installer->preflight(
                $database,
                true,
                $key,
                (bool) $cli['create_database']
            );

            if ($json) {
                fwrite(STDOUT, db_install_json([
                    'tool'         => 'db_install',
                    'version'      => DatabaseInstaller::REPORT_VERSION,
                    'ok'           => (bool) $preflight['ok'],
                    'mode'         => 'check',
                    'database'     => $preflight['database'],
                    'started_at'   => date('c'),
                    'duration_ms'  => 0,
                    'options'      => [
                        'database'        => $database,
                        'create_database' => (bool) $cli['create_database'],
                        'install_key_set' => $key !== '',
                    ],
                    'steps'        => [],
                    'counts'       => [],
                    'staff'        => ['reset' => false, 'usernames' => [], 'accounts' => []],
                    'lock'         => ['path' => null, 'written' => false, 'contents' => null, 'error' => null],
                    'install_sql'  => [],
                    'demo'         => ['installed' => false, 'members' => 0, 'codes' => 0, 'ledger_rows' => 0, 'wallets' => [], 'credentials' => []],
                    'verification' => ['ok' => (bool) $preflight['ok'], 'checks' => $preflight['checks']],
                    'checks'       => $preflight['checks'],
                    'warnings'     => [],
                ]));
            } else {
                db_install_print_preflight($preflight, (string) $preflight['database']);
            }

            return db_install_preflight_exit($preflight);
        }

        /* ---- Confirm the target before touching anything ------------ */
        if (!$cli['force']) {
            if ($json || !db_install_is_interactive()) {
                throw DatabaseToolException::usage(
                    'The install needs an interactive confirmation, but this run has no terminal.',
                    ['database' => $database],
                    'Pass --force for a non-interactive run (it skips only the confirmation, never the preflight).'
                );
            }

            if (!db_install_confirm($database)) {
                fwrite(STDERR, 'db_install: aborted — nothing was written.' . PHP_EOL);

                return 3;
            }
        }

        /* ---- Credentials (prompted only when interactive) ----------- */
        $credentials = db_install_credentials($cli, $json);

        /* ---- Preflight, then install -------------------------------- */
        $preflight = $installer->preflight($database, true, $key, (bool) $cli['create_database']);

        if (!$json) {
            db_install_print_preflight($preflight, (string) $preflight['database']);
        }

        if (!$preflight['ok']) {
            if ($json) {
                fwrite(STDOUT, db_install_json([
                    'tool'         => 'db_install',
                    'version'      => DatabaseInstaller::REPORT_VERSION,
                    'ok'           => false,
                    'mode'         => 'install',
                    'database'     => $preflight['database'],
                    'started_at'   => date('c'),
                    'duration_ms'  => 0,
                    'options'      => ['database' => $database, 'create_database' => (bool) $cli['create_database']],
                    'steps'        => [],
                    'counts'       => [],
                    'staff'        => ['reset' => false, 'usernames' => [], 'accounts' => []],
                    'lock'         => ['path' => null, 'written' => false, 'contents' => null, 'error' => null],
                    'install_sql'  => [],
                    'demo'         => ['installed' => false, 'members' => 0, 'codes' => 0, 'ledger_rows' => 0, 'wallets' => [], 'credentials' => []],
                    'verification' => ['ok' => false, 'checks' => $preflight['checks']],
                    'checks'       => $preflight['checks'],
                    'warnings'     => [],
                    'error'        => [
                        'kind'    => 'preflight',
                        'message' => 'The preflight failed; nothing was written.',
                        'hint'    => 'Fix the failed checks and run again.',
                        'context' => [],
                    ],
                ]));
            } else {
                fwrite(STDERR, 'db_install: preflight failed — nothing was written.' . PHP_EOL);
            }

            return db_install_preflight_exit($preflight);
        }

        $report = $installer->install([
            'database'        => $database,
            'create_database' => (bool) $cli['create_database'],
            'admin'           => $credentials['admin'],
            'superadmin'      => $credentials['superadmin'],
            'site_name'       => $cli['site_name'] !== null ? (string) $cli['site_name'] : APP_NAME,
            'with_demo'       => (bool) $cli['with_demo'],
            'channel'         => 'cli',
            'install_key'     => $key,
        ]);

        if ($json) {
            fwrite(STDOUT, db_install_json($report));
        } else {
            db_install_print_report($report);
        }

        return $report['ok'] ? 0 : 1;
    } catch (DatabaseToolException $e) {
        if ($json) {
            fwrite(STDOUT, db_install_json(db_install_error_report($e, $database, $mode)));
        } else {
            fwrite(STDERR, 'db_install: ' . $e->getMessage() . PHP_EOL);

            if ($e->hint !== null && $e->hint !== '') {
                foreach (db_install_wrap($e->hint) as $line) {
                    fwrite(STDERR, '  hint: ' . $line . PHP_EOL);
                }
            }
        }

        return db_install_exit_code($e);
    } catch (Throwable $e) {
        if ($json) {
            fwrite(STDOUT, db_install_json(db_install_error_report($e, $database, $mode)));
        } else {
            fwrite(STDERR, 'db_install: ' . $e->getMessage() . PHP_EOL);
        }

        return 1;
    }
}

/* Only run when this file is the entry point; a test may include it for
 * the parser and the report helpers. */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(db_install_cli($argv ?? []));
}
