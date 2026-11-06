<?php
declare(strict_types=1);

/* ==============================================================
 *  ActivationException — a membership activation rule was broken
 *  (Phase 9)
 *
 *  Carries a machine-readable `reason` and, for form submissions, the
 *  field => message map so the membership page renders errors exactly
 *  like any other invalid form:
 *
 *      validation · not_found · already_active · open · status ·
 *      package · processed · wallet · proof · suspended
 * ============================================================== */

final class ActivationException extends RuntimeException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        string $message,
        public readonly string $reason = 'activation',
        public readonly array $fieldErrors = []
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors): self
    {
        $first = $errors === [] ? 'Please check the activation details.' : (string) reset($errors);

        return new self($first, 'validation', $errors);
    }

    public static function notFound(): self
    {
        return new self('That activation request could not be found.', 'not_found');
    }

    public static function alreadyActive(?string $packageName = null): self
    {
        return new self(
            $packageName === null || $packageName === ''
                ? 'Your membership is already active.'
                : sprintf('Your membership is already active with %s.', $packageName),
            'already_active'
        );
    }

    public static function standard(): self
    {
        return new self(
            'Your standard membership is already active. Self-service activation applies to pending accounts — contact support to add an associate package.',
            'standard'
        );
    }

    public static function open(): self
    {
        return new self(
            'You already have an activation request in progress. Wait for the review, or contact support.',
            'open'
        );
    }

    public static function suspended(): self
    {
        return new self(
            'Your account is suspended, so it cannot be activated. Please contact support.',
            'suspended'
        );
    }

    public static function status(string $action, string $status): self
    {
        return new self(
            sprintf(
                'This activation is “%s” now, so it cannot be %s. Reload the page and try again.',
                ucfirst(str_replace('_', ' ', $status)),
                $action
            ),
            'status'
        );
    }

    public static function package(): self
    {
        return new self(
            'Choose an active package to activate.',
            'package',
            ['package_id' => 'Choose an active package to activate.']
        );
    }

    public static function processed(): self
    {
        return new self(
            'This activation has already been completed. Nothing was changed.',
            'processed'
        );
    }

    public static function wallet(string $message): self
    {
        return new self($message, 'wallet', ['method' => $message]);
    }

    public static function proof(string $message): self
    {
        return new self($message, 'proof', ['proof_image' => $message]);
    }
}
