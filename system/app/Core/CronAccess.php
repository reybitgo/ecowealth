<?php
declare(strict_types=1);

/* ==============================================================
 *  CronAccess — who may trigger a cron job over HTTP (Phase 7
 *  follow-up; plan §5.4, §7 Phase 7)
 *
 *  A cron script must run from:
 *    • the CLI (the cron daemon / SSH), always;
 *    • a loopback request from the same server, so a developer can
 *      simply open the URL while working locally (D-shop_expiry);
 *    • any other caller only with the shared key.
 *
 *  The key lives in app/config.local.php (git-ignored) as CRON_KEY:
 *
 *      define('CRON_KEY', 'a-long-random-string');
 *
 *  It is never stored in the database and never rendered on a page.
 *  This class is pure so the decision is unit-testable.
 * ============================================================== */

final class CronAccess
{
    /** Addresses that only the server itself can connect from. */
    public const LOOPBACK = ['127.0.0.1', '::1'];

    /** The configured key, or null when config.local.php has none. */
    public static function configuredKey(): ?string
    {
        if (!defined('CRON_KEY')) {
            return null;
        }

        $key = trim((string) constant('CRON_KEY'));

        return $key === '' ? null : $key;
    }

    public static function isLoopback(string $ip): bool
    {
        return in_array($ip, self::LOOPBACK, true);
    }

    /**
     * May a request from $remoteAddr with $providedKey run the job?
     *
     * Loopback is trusted (local testing with no setup). Everything
     * else needs the exact configured key; when no key is configured,
     * remote HTTP callers are refused.
     */
    public static function allowed(string $remoteAddr, ?string $providedKey, ?string $configuredKey): bool
    {
        if (self::isLoopback($remoteAddr)) {
            return true;
        }

        $configured = trim((string) $configuredKey);
        $provided   = (string) ($providedKey ?? '');

        if ($configured === '' || $provided === '') {
            return false;
        }

        return hash_equals($configured, $provided);
    }
}
