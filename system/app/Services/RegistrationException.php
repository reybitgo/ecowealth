<?php
declare(strict_types=1);

/* ==============================================================
 *  RegistrationException — a registration rule was broken (Phase 9)
 *
 *  Carries a machine-readable `reason` and the field => message map
 *  the register form re-renders:
 *
 *      validation · login_required · closed · duplicate ·
 *      sponsor · wallet · code
 * ============================================================== */

final class RegistrationException extends RuntimeException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        string $message,
        public readonly string $reason = 'registration',
        public readonly array $fieldErrors = []
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors): self
    {
        $first = $errors === [] ? 'Please check the registration details.' : (string) reset($errors);

        return new self($first, 'validation', $errors);
    }

    public static function loginRequired(): self
    {
        return new self(
            'Please sign in to register someone with the e-wallet payment.',
            'login_required'
        );
    }

    public static function closed(): self
    {
        return new self(
            'Free registration is currently closed. Join with a registration code or ask an active member to register you.',
            'closed',
            ['mode' => 'Free registration is currently closed.']
        );
    }

    public static function duplicate(string $field, string $message): self
    {
        return new self($message, 'duplicate', [$field => $message]);
    }

    public static function sponsor(string $message): self
    {
        return new self($message, 'sponsor', ['sponsor' => $message]);
    }

    public static function wallet(string $message): self
    {
        return new self($message, 'wallet', ['mode' => $message]);
    }

    /** @param array<string, string> $fieldErrors */
    public static function code(string $message, array $fieldErrors = []): self
    {
        return new self($message, 'code', $fieldErrors === [] ? ['reg_code' => $message] : $fieldErrors);
    }
}
