<?php
declare(strict_types=1);

/* ==============================================================
 *  Throttle — a small sliding-window rate limiter for form actions
 *  (plan §3.7: "rate-limit login (exists), checkout/proof
 *  submissions, transfers and payout requests")
 *
 *  Login already has its own per-session brute-force throttle in
 *  auth.php. This class covers the money forms: it counts *attempts*
 *  (not only failures) inside a window, per session bucket, and the
 *  caller decides what to do when the limit is reached — a form gets
 *  a flash and a redirect back; an XHR gets JSON 429.
 *
 *  It is intentionally per session, matching the reference's
 *  approach: a signed-in member cannot flood one endpoint from one
 *  tab, while tests and the CLI can drive the same code path. Every
 *  refusal is cheap — no database write, no money touched.
 *
 *  Buckets are keyed by action + actor, so checkout and transfers
 *  never consume each other's allowance.
 * ============================================================== */

final class Throttle
{
    /** Default window: 15 minutes. */
    public const WINDOW = 900;

    /**
     * Record one attempt. Returns false when the limit is already met.
     *
     * The attempt that would exceed the limit is NOT recorded, so a
     * blocked caller can try once more when the window slides.
     */
    public static function hit(string $key, int $max, int $window = self::WINDOW): bool
    {
        $key    = trim($key);
        $max    = max(1, $max);
        $window = max(1, $window);

        if ($key === '') {
            return true;
        }

        $now    = time();
        $cutoff = $now - $window;
        $bucket = self::bucket();
        $list   = array_values(array_filter(
            array_map('intval', (array) ($bucket[$key] ?? [])),
            static fn (int $at): bool => $at > $cutoff
        ));

        if (count($list) >= $max) {
            $bucket[$key] = $list;
            self::store($bucket);

            return false;
        }

        $list[]       = $now;
        $bucket[$key] = $list;
        self::store($bucket);

        return true;
    }

    /** Attempts left in the current window (never negative). */
    public static function remaining(string $key, int $max, int $window = self::WINDOW): int
    {
        $cutoff = time() - max(1, $window);
        $bucket = self::bucket();
        $list   = array_values(array_filter(
            array_map('intval', (array) ($bucket[$key] ?? [])),
            static fn (int $at): bool => $at > $cutoff
        ));

        return max(0, $max - count($list));
    }

    /** Forget one bucket (tests, or after a successful high-value action). */
    public static function clear(string $key): void
    {
        $bucket = self::bucket();
        unset($bucket[$key]);
        self::store($bucket);
    }

    /** @return array<string, list<int>> */
    private static function bucket(): array
    {
        if (session_status() === PHP_SESSION_ACTIVE && is_array($_SESSION['_throttle'] ?? null)) {
            return $_SESSION['_throttle'];
        }

        $global = $GLOBALS['__throttle'] ?? [];

        return is_array($global) ? $global : [];
    }

    /** @param array<string, list<int>> $bucket */
    private static function store(array $bucket): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_throttle'] = $bucket;

            return;
        }

        $GLOBALS['__throttle'] = $bucket;
    }
}
