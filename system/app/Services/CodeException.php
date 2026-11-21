<?php
declare(strict_types=1);

/* ==============================================================
 *  CodeException — a registration code rule was broken (Phase 9)
 *
 *  Carries a machine-readable `reason` and, for form submissions,
 *  the field => message map:
 *
 *      validation · not_found · used · expired · package ·
 *      type · quantity · forbidden
 * ============================================================== */

final class CodeException extends RuntimeException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        string $message,
        public readonly string $reason = 'code',
        public readonly array $fieldErrors = []
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors): self
    {
        $first = $errors === [] ? 'Please check the code details.' : (string) reset($errors);

        return new self($first, 'validation', $errors);
    }

    public static function notFound(string $code = ''): self
    {
        return new self(
            $code === ''
                ? 'That registration code is not recognised.'
                : sprintf('The code “%s” is not recognised.', $code),
            'not_found',
            ['reg_code' => 'That registration code is not recognised.']
        );
    }

    public static function used(): self
    {
        return new self(
            'That registration code has already been used.',
            'used',
            ['reg_code' => 'That registration code has already been used.']
        );
    }

    public static function expired(): self
    {
        return new self(
            'That registration code has expired.',
            'expired',
            ['reg_code' => 'That registration code has expired.']
        );
    }

    public static function packageDisabled(): self
    {
        return new self(
            'The package this code activates is no longer available.',
            'package',
            ['reg_code' => 'The package this code activates is no longer available.']
        );
    }

    public static function wrongType(): self
    {
        return new self(
            'Upgrade codes cannot be used to activate a membership.',
            'type',
            ['reg_code' => 'Upgrade codes cannot be used to activate a membership.']
        );
    }

    public static function forbidden(string $action = 'issue'): self
    {
        return new self(sprintf('You do not have permission to %s registration codes.', $action), 'forbidden');
    }
}
