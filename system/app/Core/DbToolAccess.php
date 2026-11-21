<?php
declare(strict_types=1);

/* ==============================================================
 *  DbToolAccess — the gates both database tool front-ends share
 *  (plan IR-4-T5; the reset gates join this class in IR-6)
 *
 *  The installer and the reset console are standalone PHP files, but
 *  they must not each invent their own meaning of "production", "the
 *  install key" or "the typed confirmation". This class owns those
 *  decisions so they have one implementation and one test file:
 *
 *      DbToolAccess::installKeyRequired(APP_ENV);        // production only
 *      DbToolAccess::installKeyMatches($expected, $given); // false when expected is ''
 *      DbToolAccess::installPhraseMatches($typed);       // the typed INSTALL
 *      DbToolAccess::installKeyAttemptsLeft();           // 5 attempts / 15 min
 *
 *      DbToolAccess::resetVisible(APP_ENV, DB_RESET_ENABLED);
 *      DbToolAccess::resetActorAllowed($user, APP_ENV);
 *      DbToolAccess::confirmationPhrase(APP_ENV, $database); // RESET | database
 *      DbToolAccess::resetPhraseMatches(APP_ENV, $database, $typed);
 *      DbToolAccess::isImpersonating();
 *
 *  What it must not do (plan §5.1): query business data, render HTML,
 *  read a password or decide *where* a key comes from. The caller reads
 *  INSTALL_KEY and passes the values in, which is what keeps this class
 *  unit-testable without a database, a browser or a config file.
 *
 *  The attempt counter is session-scoped (Throttle) rather than a
 *  database table: the page works before the schema exists, so it
 *  cannot rely on a table and must not create one.
 * ============================================================== */

final class DbToolAccess
{
    /** The session the installer owns; never the application session. */
    public const INSTALL_SESSION_NAME = 'ecowealth_install';

    /** The phrase the operator types into the install form (plan IR-4-T3). */
    public const INSTALL_PHRASE = 'INSTALL';

    /** The phrase the operator types into the development reset console. */
    public const RESET_PHRASE = 'RESET';

    /** Failed install-key attempts allowed inside the window. */
    public const KEY_MAX_ATTEMPTS = 5;

    /** Sliding window for those attempts, in seconds (15 minutes). */
    public const KEY_ATTEMPT_WINDOW = 900;

    /** Throttle bucket: never shared with an application form. */
    public const KEY_ATTEMPT_BUCKET = 'db_tool.install_key';

    /**
     * Does this environment demand an INSTALL_KEY?
     *
     * Only production does. Development and the test harness accept an
     * empty key so a local install stays one click; the production gate
     * is the control that matters (D-IR-12).
     */
    public static function installKeyRequired(string $env): bool
    {
        return strtolower(trim($env)) === 'production';
    }

    /**
     * Does the submitted key match the configured one?
     *
     * An empty expected key never matches — not even an empty
     * submission. Accepting that pair would silently turn an unwritten
     * INSTALL_KEY into "no key required" in production, which is exactly
     * the mistake the gate exists to prevent.
     */
    public static function installKeyMatches(string $expected, string $given): bool
    {
        return $expected !== '' && hash_equals($expected, $given);
    }

    /** The phrase shown to the operator ("INSTALL"). */
    public static function installPhrase(): string
    {
        return self::INSTALL_PHRASE;
    }

    /**
     * Is the typed confirmation correct?
     *
     * Case-insensitive and trimmed: the phrase is a deliberate act, not
     * a spelling test, and a mobile keyboard loves to capitalise words.
     * The value is still required, so an empty field never passes.
     */
    public static function installPhraseMatches(string $given): bool
    {
        return strcasecmp(trim($given), self::INSTALL_PHRASE) === 0;
    }

    /** How many key attempts are left in the current window. */
    public static function installKeyAttemptsLeft(): int
    {
        return Throttle::remaining(
            self::KEY_ATTEMPT_BUCKET,
            self::KEY_MAX_ATTEMPTS,
            self::KEY_ATTEMPT_WINDOW
        );
    }

    /**
     * Record one failed key attempt.
     *
     * Returns false when the attempt that would exceed the limit is not
     * recorded, i.e. the caller is already throttled. The page counts a
     * failure only when the key was actually compared and rejected;
     * CSRF and validation failures must not consume the allowance.
     */
    public static function recordInstallKeyFailure(): bool
    {
        return Throttle::hit(
            self::KEY_ATTEMPT_BUCKET,
            self::KEY_MAX_ATTEMPTS,
            self::KEY_ATTEMPT_WINDOW
        );
    }

    /** Forget the failures (a correct key, or a fresh test). */
    public static function clearInstallKeyFailures(): void
    {
        Throttle::clear(self::KEY_ATTEMPT_BUCKET);
    }

    /* ==========================================================
     *  Reset console gates (plan IR-6-T2, §7.15)
     * ========================================================== */

    /**
     * Is the reset console reachable in this environment?
     *
     * Development and QA see it by default; production only when
     * DB_RESET_ENABLED was explicitly turned on. Anything that is not
     * the production environment is treated as a development surface:
     * the tool is a QA utility, not a production feature (D-IR-11).
     */
    public static function resetVisible(string $env, bool $enabled): bool
    {
        return strtolower(trim($env)) !== 'production' || $enabled;
    }

    /**
     * May this signed-in account open the console?
     *
     * admin or superadmin in development; superadmin only in production;
     * never a member, never a guest, never an impersonated session. The
     * role is read from the row the caller already authenticated; this
     * class does not query the database.
     *
     * @param array<string, mixed>|null $user
     */
    public static function resetActorAllowed(?array $user, string $env): bool
    {
        if ($user === null || self::isImpersonating()) {
            return false;
        }

        $role = (string) ($user['role'] ?? '');

        if (strtolower(trim($env)) === 'production') {
            return $role === 'superadmin';
        }

        return $role === 'admin' || $role === 'superadmin';
    }

    /**
     * The phrase the operator must type (plan 7.14).
     *
     * "RESET" in development/QA; the database name in production, so the
     * destructive target has to be named explicitly even after the
     * constant was enabled.
     */
    public static function confirmationPhrase(string $env, string $database): string
    {
        return strtolower(trim($env)) === 'production'
            ? trim($database)
            : self::RESET_PHRASE;
    }

    /**
     * Does the typed phrase match the environment's phrase?
     *
     * Development is trimmed and case-insensitive (a mobile keyboard
     * capitalises); production is exact after trimming, because a
     * database name is the confirmation and is not a spelling test.
     * An empty expected phrase never matches.
     */
    public static function resetPhraseMatches(string $env, string $database, string $given): bool
    {
        $expected = self::confirmationPhrase($env, $database);

        if ($expected === '') {
            return false;
        }

        if (strtolower(trim($env)) === 'production') {
            return hash_equals($expected, trim($given));
        }

        return strcasecmp(trim($given), $expected) === 0;
    }

    /**
     * Is this request running inside an S-Login session?
     *
     * The installer loads this class without the application's auth
     * helpers, so the check is optional: outside a booted application
     * there is no impersonation session to find.
     */
    public static function isImpersonating(): bool
    {
        return function_exists('imp_token') && imp_token() !== '';
    }
}
