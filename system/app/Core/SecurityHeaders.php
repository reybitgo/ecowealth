<?php
declare(strict_types=1);

/* ==============================================================
 *  SecurityHeaders — the response hardening the whole app shares
 *  (plan §3.7, Phase 15 "headers")
 *
 *  Sent once from the bootstrap, so every PHP response carries them:
 *  the routed pages, the standalone login/register/logout files, the
 *  404/403/500 pages, the CSV exports and the streamed proofs.
 *
 *  A Content-Security-Policy that forbids inline script/style is
 *  deliberately NOT set: the EcoWealth layout ships inline theme
 *  bootstrap and page-level `<script>` payloads, so a strict policy
 *  would take the UI down. The directives that are safe for a
 *  non-inline-aware app are sent instead (frame-ancestors, base-uri,
 *  form-action), which is also what OWASP recommends as the first
 *  pass; a nonce-based policy is a later frontend task.
 * ============================================================== */

final class SecurityHeaders
{
    /**
     * Send the shared headers. Idempotent and safe to call on any
     * SAPI: CLI has no response headers, and a second call is a no-op.
     */
    public static function send(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        static $sent = false;

        if ($sent) {
            return;
        }

        $sent = true;

        /* Never let a browser second-guess a content type. */
        header('X-Content-Type-Options: nosniff');

        /* The app is never framed; frame-ancestors also covers browsers
         * that ignore the legacy header. */
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

        /* Referrals leak the bearer `imp` token if sent cross-origin, and
         * the referral links themselves are shared by copy, not by referer. */
        header('Referrer-Policy: strict-origin-when-cross-origin');

        /* No page in this build uses a device API. */
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');

        /* A private back office: search engines get the header as well as
         * the meta tag. */
        header('X-Robots-Tag: noindex, nofollow');

        /* HSTS only over TLS — sending it on http://localhost would pin
         * the scheme for a host that has no certificate. */
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Is this request already served over TLS? */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

        return $proto === 'https';
    }
}
