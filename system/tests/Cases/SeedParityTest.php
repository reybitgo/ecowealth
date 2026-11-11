<?php
declare(strict_types=1);

/* ==============================================================
 *  SeedParityTest — migrations and seeds must agree (plan IR-2-T6)
 *
 *  Static, fast, no database. It parses the SQL sources with the same
 *  quote/comment-aware runner the installers use, so a key inside a
 *  comment or a string value is never mistaken for configuration.
 *
 *  The history this locks down:
 *    F-01  migration 012 adds settings.commission_ledger_since, but a
 *          fresh install (schema.sql + seeds) did not have it, so a new
 *          database and an upgraded one diverged in data.
 *    F-09  seed 002 left the staff member_status at the schema default
 *          'pending' while a reset returns staff to 'active'.
 *
 *  Run this group alone with:  php tests/run.php SeedParity
 * ============================================================== */

$databaseDir = dirname(__DIR__, 2) . '/database/';

/**
 * Every settings key a file inserts.
 *
 * Handles both shapes used in the project:
 *   INSERT INTO settings (...) VALUES ('key', ...), (...)
 *   INSERT INTO settings (...) SELECT 'key', ... WHERE NOT EXISTS (...)
 *
 * @return list<string>
 */
$settingsKeys = static function (string $file): array {
    $keys = [];

    foreach (SqlScript::fromFile($file)->statements() as $statement) {
        $sql = $statement['sql'];

        if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?settings`?\b/i', $sql) !== 1) {
            continue;
        }

        /* VALUES form: the first quoted value of each row is the key. */
        if (preg_match('/\bVALUES\b/i', $sql, $match, PREG_OFFSET_CAPTURE) === 1) {
            $values = substr($sql, $match[0][1] + strlen($match[0][0]));
            $parts  = preg_split('/\bON\s+DUPLICATE\b/i', $values, 2);
            $values = $parts[0] ?? $values;

            if (preg_match_all("/\(\s*'((?:[^']|'')+)'\s*,/", $values, $rows) > 0) {
                foreach ($rows[1] as $key) {
                    $keys[] = str_replace("''", "'", $key);
                }
            }
        }

        /* SELECT form: INSERT ... SELECT 'key', <value> ... */
        if (preg_match_all("/\bSELECT\s+'((?:[^']|'')+)'/i", $sql, $rows) > 0) {
            foreach ($rows[1] as $key) {
                $keys[] = str_replace("''", "'", $key);
            }
        }
    }

    return $keys;
};

$seedKeys = static fn (): array => $settingsKeys($databaseDir . 'seeds/001_settings.sql');

return [
    'every settings key a migration introduces exists in seed 001 (F-01)' => function () use ($databaseDir, $settingsKeys, $seedKeys): void {
        $introduced = [];

        foreach (glob($databaseDir . 'migrations/*.sql') ?: [] as $migration) {
            foreach ($settingsKeys($migration) as $key) {
                $introduced[$key] = basename($migration);
            }
        }

        /* The migrations really do carry settings; an empty parse would make
         * the assertion below vacuous. */
        assert_true(count($introduced) >= 15, 'the migration settings keys are found (got ' . count($introduced) . ')');

        $keys   = $seedKeys();
        $missing = [];

        foreach ($introduced as $key => $migration) {
            if (!in_array($key, $keys, true)) {
                $missing[] = $key . ' (' . $migration . ')';
            }
        }

        assert_same(
            [],
            $missing,
            'seed 001 is missing migration settings keys: ' . implode(', ', $missing)
        );

        assert_true(
            in_array('commission_ledger_since', $keys, true),
            'F-01: seed 001 must carry commission_ledger_since (migration 012 parity)'
        );
    },

    'seed 001 has no duplicate settings key' => function () use ($seedKeys): void {
        $keys = $seedKeys();

        assert_true(count($keys) >= 45, 'seed 001 seeds at least 45 settings (got ' . count($keys) . ')');

        $duplicates = array_keys(array_filter(
            array_count_values($keys),
            static fn (int $count): bool => $count > 1
        ));

        assert_same([], $duplicates, 'duplicate settings keys: ' . implode(', ', $duplicates));
    },

    'seed 002 marks both staff accounts active (F-09)' => function () use ($databaseDir): void {
        $statement = null;

        foreach (SqlScript::fromFile($databaseDir . 'seeds/002_accounts.sql')->statements() as $candidate) {
            if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?users`?\b/i', $candidate['sql']) === 1) {
                $statement = $candidate['sql'];
                break;
            }
        }

        assert_not_null($statement, 'seed 002 inserts the staff accounts');

        assert_same(
            1,
            preg_match('/\bmember_status\b/i', (string) $statement),
            'seed 002 writes member_status explicitly (schema default is pending)'
        );
        assert_same(
            2,
            substr_count((string) $statement, "'active'"),
            'both seeded staff rows are active, matching the reset state'
        );
    },

    'the install bundle carries every seed key and parses cleanly' => function () use ($databaseDir, $settingsKeys, $seedKeys): void {
        $install = $databaseDir . 'install.sql';

        assert_true(is_file($install), 'database/install.sql is generated and committed');

        $script = SqlScript::fromFile($install);

        assert_false($script->containsDatabaseSwitch(), 'the bundle contains no executable CREATE DATABASE/USE');

        $statements = $script->statements();
        assert_true(count($statements) >= 40, 'the bundle carries the schema and every seed (got ' . count($statements) . ' statements)');

        foreach ($statements as $statement) {
            assert_same(
                0,
                preg_match('/^DELIMITER\b/i', $statement['sql']),
                'DELIMITER is not part of the bundle'
            );
        }

        $bundleKeys = $settingsKeys($install);

        foreach ($seedKeys() as $key) {
            assert_true(in_array($key, $bundleKeys, true), 'the bundle carries settings.' . $key);
        }

        foreach (['install_version', 'install_channel', 'install_completed_at'] as $key) {
            assert_true(in_array($key, $bundleKeys, true), 'the footer adds settings.' . $key);
        }

        $sql = (string) file_get_contents($install);

        assert_contains('GENERATED FILE', $sql);
        assert_contains('ecowealth_install_requires_an_empty_database', $sql);
        assert_contains('SECTION 7/7', $sql);
        assert_not_contains('DEMO-PKGA', $sql, 'F-07: the production bundle carries no demo codes');
        assert_not_contains("'juan'", $sql, 'F-07: the production bundle carries no demo members');

        $bytes = filesize($install);
        assert_true($bytes !== false && $bytes < 100 * 1024, 'the bundle stays under phpMyAdmin\'s default import limit');
    },
];
