#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — build the production install bundle (plan IR-2)
 *
 *      php bin/build_install_sql.php            # write database/install.sql
 *      php bin/build_install_sql.php --check    # compare committed bytes
 *      php bin/build_install_sql.php --stdout   # print, do not write
 *      php bin/build_install_sql.php --help
 *
 *  database/install.sql is generated from
 *
 *      database/install/header.sql
 *      database/schema.sql
 *      database/seeds/001_settings.sql
 *      database/seeds/002_accounts.sql
 *      database/seeds/003_products.sql
 *      database/seeds/004_packages.sql
 *      database/install/footer.sql
 *
 *  so there is one editable source of truth (D-IR-01). The output is
 *  deterministic: LF endings, no build timestamp, one trailing newline
 *  and a `-- source: <path> sha256=<hash>` provenance line per section.
 *  `--check` is the drift gate: edit a source without rebuilding and
 *  `php tests/run.php` fails (tests/Cases/InstallSqlTest.php).
 *
 *  The builder never connects to MySQL and never runs the SQL it reads:
 *  it validates each source through SqlScript (no executable
 *  CREATE DATABASE/USE, no DELIMITER, a non-empty required seed) and
 *  writes bytes.
 *
 *  Exit codes: 0 built/matches, 1 stale or bad source, 2 usage.
 * ============================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once __DIR__ . '/../app/autoload.php';

/** Where the bundle and its sources live, relative to the app root. */
function build_install_sql_sources(): array
{
    return [
        ['key' => 'header',   'path' => 'database/install/header.sql',    'section' => null],
        ['key' => 'schema',   'path' => 'database/schema.sql',            'section' => 'SCHEMA'],
        ['key' => 'settings', 'path' => 'database/seeds/001_settings.sql', 'section' => 'SETTINGS'],
        ['key' => 'accounts', 'path' => 'database/seeds/002_accounts.sql', 'section' => 'STAFF ACCOUNTS'],
        ['key' => 'products', 'path' => 'database/seeds/003_products.sql', 'section' => 'STARTER PRODUCTS'],
        ['key' => 'packages', 'path' => 'database/seeds/004_packages.sql', 'section' => 'PACKAGES'],
        ['key' => 'footer',   'path' => 'database/install/footer.sql',     'section' => 'INSTALL METADATA'],
    ];
}

/** Absolute app root with forward slashes (stable on Windows and Linux). */
function build_install_sql_root(?string $appRoot = null): string
{
    $root = $appRoot ?? dirname(__DIR__);

    $resolved = realpath($root);
    $root     = $resolved === false ? $root : $resolved;

    return rtrim(str_replace('\\', '/', $root), '/');
}

/**
 * Read and normalise one source.
 *
 * LF endings, no BOM, exactly one trailing newline. Everything else
 * (including the UTF-8 text of the seed descriptions) is kept verbatim,
 * so a rebuild is byte-identical on every machine.
 */
function build_install_sql_read_source(string $root, string $path): string
{
    $absolute = $root . '/' . $path;

    if (!is_file($absolute) || !is_readable($absolute)) {
        throw DatabaseToolException::usage(
            'The install source is not readable: ' . $path,
            ['source' => $path],
            'Run the builder from the application folder and keep database/install/ next to database/.'
        );
    }

    $sql = file_get_contents($absolute);

    if ($sql === false) {
        throw DatabaseToolException::usage(
            'The install source could not be read: ' . $path,
            ['source' => $path]
        );
    }

    if (str_starts_with($sql, "\xEF\xBB\xBF")) {
        $sql = substr($sql, 3);
    }

    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $sql = rtrim($sql, "\n");

    if (trim($sql) === '') {
        throw DatabaseToolException::usage(
            'The install source is empty: ' . $path,
            ['source' => $path],
            'A required section cannot be empty; restore the file or remove it from the bundle.'
        );
    }

    return $sql . "\n";
}

/** Reject an executable CREATE DATABASE / USE using the comment-aware runner. */
function build_install_sql_assert_no_database_switch(string $sql, string $path): void
{
    if (SqlScript::fromString($sql)->containsDatabaseSwitch()) {
        throw DatabaseToolException::refused(
            $path . ' contains an executable CREATE DATABASE or USE statement.',
            ['source' => $path],
            'The bundle must not choose a schema: the caller selects it (D-IR-02). '
                . 'Move the statement out of the bundle.'
        );
    }
}

/**
 * Require at least one INSERT into a table, so a gutted seed is caught
 * at build time instead of producing an install with missing data.
 *
 * @param list<array{sql:string, line:int}> $statements
 */
function build_install_sql_requires_insert(array $statements, string $table, string $path): void
{
    $pattern = '/\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?' . preg_quote($table, '/') . '`?\b/i';

    foreach ($statements as $statement) {
        if (preg_match($pattern, $statement['sql']) === 1) {
            return;
        }
    }

    throw DatabaseToolException::usage(
        $path . ' contains no INSERT into ' . $table . '.',
        ['source' => $path, 'table' => $table],
        'Restore the seed statement: the install bundle must seed ' . $table . '.'
    );
}

/**
 * Read every source, validate it, and compose the bundle.
 *
 * @return array{
 *     sql:string,
 *     bytes:int,
 *     sha256:string,
 *     sources:list<array{path:string,sha256:string,bytes:int}>,
 *     sections:list<string>
 * }
 */
function build_install_sql_bundle(?string $appRoot = null): array
{
    $root    = build_install_sql_root($appRoot);
    $sources = build_install_sql_sources();
    $loaded  = [];
    $hashes  = [];

    foreach ($sources as $source) {
        $sql                    = build_install_sql_read_source($root, $source['path']);
        $loaded[$source['key']] = $sql;
        $hashes[$source['key']] = ['path' => $source['path'], 'sha256' => hash('sha256', $sql), 'bytes' => strlen($sql)];

        build_install_sql_assert_no_database_switch($sql, $source['path']);
    }

    /* The header must carry the guard: a re-import has to fail loudly. */
    if (!str_contains($loaded['header'], 'ecowealth_install_requires_an_empty_database')
        || !str_contains($loaded['header'], 'PREPARE')) {
        throw DatabaseToolException::usage(
            'database/install/header.sql does not contain the empty-database guard.',
            ['source' => 'database/install/header.sql'],
            'The guard is what makes a re-import fail immediately; restore it from plan §6.4.'
        );
    }

    /* The schema must create tables; a truncated file must not build. */
    $schemaStatements = SqlScript::fromString($loaded['schema'])->statements();
    $creates          = 0;

    foreach ($schemaStatements as $statement) {
        if (preg_match('/^\s*CREATE\s+TABLE\b/i', $statement['sql']) === 1) {
            $creates++;
        }
    }

    if ($creates === 0) {
        throw DatabaseToolException::usage(
            'database/schema.sql contains no CREATE TABLE statement.',
            ['source' => 'database/schema.sql'],
            'The bundle would create no tables; restore the schema before rebuilding.'
        );
    }

    /* Every production seed section must still seed its tables. */
    $required = [
        'settings' => ['settings'],
        'accounts' => ['users'],
        'products' => ['products'],
        'packages' => ['packages', 'package_levels', 'package_products'],
    ];

    foreach ($required as $key => $tables) {
        $statements = SqlScript::fromString($loaded[$key])->statements();

        foreach ($tables as $table) {
            build_install_sql_requires_insert($statements, $table, 'database/seeds/' . [
                'settings' => '001_settings.sql',
                'accounts' => '002_accounts.sql',
                'products' => '003_products.sql',
                'packages' => '004_packages.sql',
            ][$key]);
        }
    }

    /* The footer is two sections: metadata, then the verification pack. */
    $footer = $loaded['footer'];
    $marker = '-- @@VERIFICATION@@';

    if (substr_count($footer, $marker) !== 1) {
        throw DatabaseToolException::usage(
            'database/install/footer.sql must contain exactly one ' . $marker . ' marker.',
            ['source' => 'database/install/footer.sql'],
            'The marker separates the INSTALL METADATA section from VERIFICATION in the bundle.'
        );
    }

    [$metadataBody, $verificationBody] = explode($marker, $footer, 2);
    $metadataBody     = rtrim($metadataBody, "\n") . "\n";
    $verificationBody = ltrim($verificationBody, "\n");

    if (trim($verificationBody) === '') {
        throw DatabaseToolException::usage(
            'database/install/footer.sql has no verification statements after the marker.',
            ['source' => 'database/install/footer.sql']
        );
    }

    /* Compose: header, then six banners, then the two footer sections. */
    $sections = [
        ['section' => null,                'path' => $sources[0]['path'], 'key' => 'header',  'body' => $loaded['header']],
        ['section' => 'SCHEMA',            'path' => $sources[1]['path'], 'key' => 'schema',  'body' => $loaded['schema']],
        ['section' => 'SETTINGS',          'path' => $sources[2]['path'], 'key' => 'settings','body' => $loaded['settings']],
        ['section' => 'STAFF ACCOUNTS',    'path' => $sources[3]['path'], 'key' => 'accounts','body' => $loaded['accounts']],
        ['section' => 'STARTER PRODUCTS',  'path' => $sources[4]['path'], 'key' => 'products','body' => $loaded['products']],
        ['section' => 'PACKAGES',          'path' => $sources[5]['path'], 'key' => 'packages','body' => $loaded['packages']],
        ['section' => 'INSTALL METADATA',  'path' => $sources[6]['path'], 'key' => 'footer',  'body' => $metadataBody],
        ['section' => 'VERIFICATION',      'path' => $sources[6]['path'], 'key' => 'footer',  'body' => $verificationBody],
    ];

    $total = count($sections) - 1;   // the header is not a numbered section
    $out   = $sections[0]['body'] . "\n";
    $index = 0;
    $names = [];

    foreach (array_slice($sections, 1) as $section) {
        $index++;
        $names[] = (string) $section['section'];
        $out    .= build_install_sql_banner(
            (string) $section['section'],
            (string) $section['path'],
            $hashes[$section['key']]['sha256'],
            $index,
            $total
        );
        $out .= "\n" . $section['body'] . "\n";
    }

    $out = rtrim($out, "\n") . "\n";

    return [
        'sql'      => $out,
        'bytes'    => strlen($out),
        'sha256'   => hash('sha256', $out),
        'sources'  => array_values($hashes),
        'sections' => $names,
    ];
}

/** The rule between sections, with the source and its content hash. */
function build_install_sql_banner(string $title, string $path, string $sha256, int $index, int $total): string
{
    $bar = str_repeat('=', 75);

    return '-- ' . $bar . "\n"
        . '--  SECTION ' . $index . '/' . $total . ' — ' . $title . "\n"
        . '--  source: ' . $path . ' sha256=' . $sha256 . "\n"
        . '-- ' . $bar . "\n";
}

/**
 * Compare the committed bundle with a fresh build.
 *
 * @return array{
 *     ok:bool,
 *     reason:?string,
 *     path:string,
 *     line:?int,
 *     expected:?string,
 *     actual:?string,
 *     bytes_expected:int,
 *     bytes_actual:?int,
 *     crlf:bool
 * }
 */
function build_install_sql_check(?string $appRoot = null): array
{
    $root     = build_install_sql_root($appRoot);
    $built    = build_install_sql_bundle($root);
    $path     = $root . '/database/install.sql';

    $result = [
        'ok'             => false,
        'reason'         => null,
        'path'           => 'database/install.sql',
        'line'           => null,
        'expected'       => null,
        'actual'         => null,
        'bytes_expected' => $built['bytes'],
        'bytes_actual'   => null,
        'crlf'           => false,
    ];

    if (!is_file($path) || !is_readable($path)) {
        $result['reason'] = 'missing';

        return $result;
    }

    $actual = file_get_contents($path);

    if ($actual === false) {
        $result['reason'] = 'missing';

        return $result;
    }

    $result['bytes_actual'] = strlen($actual);
    $result['crlf']         = str_contains($actual, "\r\n");

    if ($actual === $built['sql']) {
        $result['ok'] = true;

        return $result;
    }

    $expectedLines = explode("\n", $built['sql']);
    $actualLines   = explode("\n", $actual);
    $count         = max(count($expectedLines), count($actualLines));

    for ($i = 0; $i < $count; $i++) {
        $expected = $expectedLines[$i] ?? null;
        $found    = $actualLines[$i] ?? null;

        if ($expected !== $found) {
            $result['line']     = $i + 1;
            $result['expected'] = build_install_sql_excerpt($expected);
            $result['actual']   = build_install_sql_excerpt($found);
            $result['reason']   = 'stale';

            return $result;
        }
    }

    $result['reason'] = 'stale';

    return $result;
}

/** An operator-sized excerpt of one line for the --check failure. */
function build_install_sql_excerpt(?string $line, int $max = 160): string
{
    if ($line === null) {
        return '(end of file)';
    }

    return strlen($line) > $max ? substr($line, 0, $max) . '…' : $line;
}

function build_install_sql_usage(mixed $stream): void
{
    fwrite($stream, <<<'TXT'
Usage: php bin/build_install_sql.php [--check|--stdout]

  (no flag)   Rebuild database/install.sql from the sources.
  --check     Compare the committed bundle byte-for-byte; exit 1 when stale.
  --stdout    Print the bundle to STDOUT instead of writing the file.
  --help      Show this help.

Exit: 0 built/matches, 1 stale or bad source, 2 usage.

The editable sources are database/install/header.sql, database/schema.sql,
database/seeds/001_settings.sql … 004_packages.sql and
database/install/footer.sql. Never edit database/install.sql by hand.

TXT);
}

/** @param list<string> $argv */
function build_install_sql_cli(array $argv): int
{
    $check  = false;
    $stdout = false;

    foreach (array_slice($argv, 1) as $argument) {
        switch ($argument) {
            case '--check':
                $check = true;
                break;
            case '--stdout':
                $stdout = true;
                break;
            case '--help':
            case '-h':
                build_install_sql_usage(STDOUT);
                return 0;
            default:
                fwrite(STDERR, 'Unknown argument: ' . $argument . PHP_EOL);
                build_install_sql_usage(STDERR);
                return 2;
        }
    }

    if ($check && $stdout) {
        fwrite(STDERR, '--check and --stdout cannot be combined.' . PHP_EOL);
        return 2;
    }

    try {
        if ($check) {
            $result = build_install_sql_check();

            if ($result['ok']) {
                return 0;
            }

            if ($result['reason'] === 'missing') {
                fwrite(STDERR, 'database/install.sql is missing.' . PHP_EOL);
            } else {
                fwrite(STDERR, sprintf(
                    "database/install.sql is stale (first difference at line %s).%s\n",
                    (string) $result['line'],
                    $result['crlf'] ? ' The committed file uses CRLF; .gitattributes pins *.sql to LF.' : ''
                ));
                fwrite(STDERR, '  expected: ' . (string) $result['expected'] . PHP_EOL);
                fwrite(STDERR, '  found:    ' . (string) $result['actual'] . PHP_EOL);
            }

            fwrite(STDERR, 'Rebuild it with: php bin/build_install_sql.php' . PHP_EOL);
            return 1;
        }

        $bundle = build_install_sql_bundle();

        if ($stdout) {
            fwrite(STDOUT, $bundle['sql']);
            return 0;
        }

        $path = build_install_sql_root() . '/database/install.sql';
        $put  = file_put_contents($path, $bundle['sql'], LOCK_EX);

        if ($put === false) {
            throw DatabaseToolException::usage(
                'Could not write database/install.sql.',
                ['path' => $path],
                'Check the file permissions on system/database/.'
            );
        }

        fwrite(STDOUT, sprintf(
            "Built database/install.sql (%s bytes, sha256 %s).\n",
            number_format(strlen($bundle['sql'])),
            $bundle['sha256']
        ));

        return 0;
    } catch (DatabaseToolException $e) {
        fwrite(STDERR, 'build_install_sql: ' . $e->getMessage() . PHP_EOL);

        if ($e->hint !== null && $e->hint !== '') {
            fwrite(STDERR, '  hint: ' . $e->hint . PHP_EOL);
        }

        return 1;
    } catch (Throwable $e) {
        fwrite(STDERR, 'build_install_sql: ' . $e->getMessage() . PHP_EOL);
        return 1;
    }
}

/* Only run the command when this file is the entry point; tests include
 * it for build_install_sql_bundle() / build_install_sql_check(). */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(build_install_sql_cli($argv ?? []));
}
