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
}
