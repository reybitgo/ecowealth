<?php
declare(strict_types=1);

/* ==============================================================
 *  DbToolAccessTest — the gates the web installer relies on
 *  (plan IR-4-T5 and IR-4-T7; the reset matrix joins this file in IR-6)
 *
 *  These cases are pure: no database, no browser, no config file. They
 *  pin the decisions the page must not reinvent:
 *
 *      production only  → INSTALL_KEY is required
 *      empty expected   → never matches, not even an empty submission
 *      typed phrase     → INSTALL, trimmed and case-insensitive
 *      five failures    → the key gate throttles for fifteen minutes
 *
 *  The session-scoped throttle is exercised through Throttle's own
 *  session bucket, which tests/bootstrap.php clears before every case.
 * ============================================================== */

return [
    'INSTALL_KEY is required only in production' => function (): void {
        assert_true(DbToolAccess::installKeyRequired('production'));
        assert_true(DbToolAccess::installKeyRequired(' Production '), 'the check trims and ignores case');

        foreach (['development', 'test', 'staging', 'local', ''] as $env) {
            assert_false(DbToolAccess::installKeyRequired($env), '"' . $env . '" does not require a key');
        }
    },

    'an empty configured key never matches, not even an empty submission' => function (): void {
        assert_false(DbToolAccess::installKeyMatches('', ''), 'empty/empty must not pass');
        assert_false(DbToolAccess::installKeyMatches('', 'anything'), 'an empty expected key never matches');
        assert_false(DbToolAccess::installKeyMatches('a-long-random-key', ''), 'an empty submission never matches');
    },

    'the install key comparison is exact' => function (): void {
        $key = 'ir4-install-key-' . str_repeat('x', 32);

        assert_true(DbToolAccess::installKeyMatches($key, $key));
        assert_false(DbToolAccess::installKeyMatches($key, $key . 'x'), 'a longer submission does not match');
        assert_false(DbToolAccess::installKeyMatches($key, substr($key, 0, -1)), 'a shorter submission does not match');
        assert_false(DbToolAccess::installKeyMatches($key, strtoupper($key)), 'the comparison is case-sensitive');
        assert_false(DbToolAccess::installKeyMatches($key, ' ' . $key), 'no trimming is done by the comparison');
    },

    'the typed confirmation is INSTALL, trimmed and case-insensitive' => function (): void {
        assert_same('INSTALL', DbToolAccess::installPhrase());

        foreach (['INSTALL', ' install ', 'Install', "INSTALL\n"] as $typed) {
            assert_true(DbToolAccess::installPhraseMatches($typed), '"' . trim($typed) . '" is accepted');
        }

        foreach (['', '   ', 'RESET', 'INSTALL NOW', 'INSTAL', 'INSTALL!'] as $typed) {
            assert_false(DbToolAccess::installPhraseMatches($typed), '"' . $typed . '" is refused');
        }
    },

    'the installer owns a dedicated session name' => function (): void {
        assert_same('ecowealth_install', DbToolAccess::INSTALL_SESSION_NAME);
        assert_not_same(SESSION_NAME, DbToolAccess::INSTALL_SESSION_NAME, 'the app session is never reused');
    },

    'five failed key attempts throttle the gate for fifteen minutes' => function (): void {
        DbToolAccess::clearInstallKeyFailures();

        assert_same(DbToolAccess::KEY_MAX_ATTEMPTS, DbToolAccess::installKeyAttemptsLeft());
        assert_same(5, DbToolAccess::KEY_MAX_ATTEMPTS);
        assert_same(900, DbToolAccess::KEY_ATTEMPT_WINDOW);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            assert_true(
                DbToolAccess::recordInstallKeyFailure(),
                'attempt ' . $attempt . ' is recorded inside the allowance'
            );
        }

        assert_same(0, DbToolAccess::installKeyAttemptsLeft(), 'the allowance is exhausted');
        assert_false(
            DbToolAccess::recordInstallKeyFailure(),
            'the attempt that would exceed the limit is not recorded'
        );
        assert_same(0, DbToolAccess::installKeyAttemptsLeft(), 'a blocked attempt changes nothing');

        DbToolAccess::clearInstallKeyFailures();
        assert_same(5, DbToolAccess::installKeyAttemptsLeft(), 'clearing restores the allowance');
    },

    'the key bucket is namespaced away from application forms' => function (): void {
        assert_same('db_tool.install_key', DbToolAccess::KEY_ATTEMPT_BUCKET);
        assert_false(str_starts_with(DbToolAccess::KEY_ATTEMPT_BUCKET, 'auth.'), 'not an auth bucket');
        assert_false(str_starts_with(DbToolAccess::KEY_ATTEMPT_BUCKET, 'wallet.'), 'not a money bucket');
    },
];
