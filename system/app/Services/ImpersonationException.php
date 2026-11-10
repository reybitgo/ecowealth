<?php
declare(strict_types=1);

/* ==============================================================
 *  ImpersonationException — a refused S-Login action (plan §7
 *  Phase 14)
 *
 *  `$reason` is a stable code the controller and the tests key off;
 *  the message is the sentence shown to the superadmin.
 *
 *    admin          caller is not a superadmin
 *    username       missing / malformed target username
 *    not_found      no account with that username
 *    not_member     target is an admin or superadmin
 *    suspended      target is suspended or deactivated
 *    rate_limited   too many failed attempts for this target
 *    self           target is the caller
 *    missing        request has no impersonation session
 *    missing_log    session has no / unknown audit row
 *    tampered       token does not match the stored hash
 *    expired        TTL passed (or the row was already expired)
 *    logged_out     the session was ended by Exit / Sign out
 *    ip / ua        request origin changed
 *    target         target can no longer be impersonated
 * ============================================================== */

final class ImpersonationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message
    ) {
        parent::__construct($message);
    }

    public static function make(string $reason, string $message): self
    {
        return new self($reason, $message);
    }

    /** The refusal message for a validation failure code. */
    public static function forCheckReason(string $reason): self
    {
        $message = match ($reason) {
            'missing', 'missing_log', 'tampered' => 'That super-login link is not valid. Open a new member session from the console.',
            'expired'                            => 'That super-login session has expired. Open a new member session from the console.',
            'logged_out'                         => 'That super-login session was already ended.',
            'ip', 'ua'                           => 'That super-login session was opened from a different browser or network, so it was ended.',
            'target'                             => 'That member account can no longer be viewed.',
            default                              => 'That super-login session is not valid.',
        };

        return new self($reason, $message);
    }
}
