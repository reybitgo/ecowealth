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

    /* ==========================================================
     *  IR-6 — reset console gates
     * ========================================================== */

    'the reset console is visible outside production, and only enabled in production' => function (): void {
        foreach (['development', 'test', 'staging', 'local', ''] as $env) {
            assert_true(
                DbToolAccess::resetVisible($env, false),
                '"' . $env . '" sees the console without DB_RESET_ENABLED'
            );
        }

        assert_false(DbToolAccess::resetVisible('production', false), 'production is hidden by default');
        assert_true(DbToolAccess::resetVisible(' Production ', true), 'production is visible when enabled');
        assert_true(DbToolAccess::resetVisible('production', true));
        assert_true(DbToolAccess::resetVisible('development', false), 'the flag is irrelevant outside production');
    },

    'the reset actor is admin in development and superadmin in production' => function (): void {
        $admin = ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
        $super = ['id' => 2, 'username' => 'superadmin', 'role' => 'superadmin'];
        $member = ['id' => 3, 'username' => 'juan', 'role' => 'member'];

        assert_false(DbToolAccess::resetActorAllowed(null, 'development'), 'a guest is refused');
        assert_false(DbToolAccess::resetActorAllowed(null, 'production'), 'a guest is refused in production');
        assert_false(DbToolAccess::resetActorAllowed($member, 'development'), 'a member is never allowed');
        assert_false(DbToolAccess::resetActorAllowed($member, 'production'), 'a member is never allowed in production');
        assert_true(DbToolAccess::resetActorAllowed($admin, 'development'), 'an admin may reset a dev database');
        assert_false(DbToolAccess::resetActorAllowed($admin, 'production'), 'production needs a superadmin');
        assert_true(DbToolAccess::resetActorAllowed($super, 'development'), 'a superadmin may reset a dev database');
        assert_true(DbToolAccess::resetActorAllowed($super, 'production'), 'a superadmin may reset production when enabled');
        assert_true(DbToolAccess::resetActorAllowed($admin, 'test'), 'the test environment behaves like development');
    },

    'the reset phrase is RESET in development and the database name in production' => function (): void {
        assert_same('RESET', DbToolAccess::RESET_PHRASE);
        assert_same('RESET', DbToolAccess::confirmationPhrase('development', 'ecowealth_sneat'));
        assert_same('RESET', DbToolAccess::confirmationPhrase('test', 'ecowealth_sneat'));
        assert_same('RESET', DbToolAccess::confirmationPhrase('staging', 'u123_ecowealth'));
        assert_same('ecowealth_sneat', DbToolAccess::confirmationPhrase('production', 'ecowealth_sneat'));
        assert_same('u123_ecowealth', DbToolAccess::confirmationPhrase(' Production ', ' u123_ecowealth '));
        assert_same('', DbToolAccess::confirmationPhrase('production', ' '), 'an empty production phrase is empty');
    },

    'the reset phrase matcher is forgiving in development and exact in production' => function (): void {
        foreach (['RESET', ' reset ', 'Reset', "RESET\n"] as $typed) {
            assert_true(
                DbToolAccess::resetPhraseMatches('development', 'ecowealth_sneat', $typed),
                '"' . trim($typed) . '" is accepted in development'
            );
        }

        foreach (['', '   ', 'INSTALL', 'RESET NOW', 'RESE'] as $typed) {
            assert_false(
                DbToolAccess::resetPhraseMatches('development', 'ecowealth_sneat', $typed),
                '"' . $typed . '" is refused in development'
            );
        }

        assert_true(DbToolAccess::resetPhraseMatches('production', 'ecowealth_sneat', 'ecowealth_sneat'));
        assert_true(DbToolAccess::resetPhraseMatches('production', 'ecowealth_sneat', " ecowealth_sneat\n"));
        assert_false(DbToolAccess::resetPhraseMatches('production', 'ecowealth_sneat', 'ECOWEALTH_SNEAT'));
        assert_false(DbToolAccess::resetPhraseMatches('production', 'ecowealth_sneat', 'RESET'));
        assert_false(DbToolAccess::resetPhraseMatches('production', '', ''));
        assert_false(DbToolAccess::resetPhraseMatches('development', 'ecowealth_sneat', ''));
    },

    'an S-Login tab can never open the reset console' => function (): void {
        assert_false(DbToolAccess::isImpersonating(), 'a normal request is not impersonating');

        $_SESSION['imp_token'] = str_repeat('a', 64);

        assert_true(DbToolAccess::isImpersonating(), 'the helper reads the S-Login transport token');
        assert_false(
            DbToolAccess::resetActorAllowed(['id' => 2, 'username' => 'superadmin', 'role' => 'superadmin'], 'development'),
            'even a superadmin is refused while impersonating'
        );
        assert_false(
            DbToolAccess::resetActorAllowed(['id' => 2, 'username' => 'superadmin', 'role' => 'superadmin'], 'production'),
            'the impersonation refusal crosses environments'
        );

        unset($_SESSION['imp_token']);
    },
];
