<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageBinaryException — a package-binary rule was broken (23C)
 *
 *  Same shape as UnilevelException: a machine-readable `reason` plus a
 *  message the caller may render as-is. The service throws only for
 *  structural failures; an activation that simply does not qualify is a
 *  normal "nothing placed" answer, not an exception
 *  (plan §14.3).
 *
 *      not_placed · not_approved · package_disabled · already_reversed
 * ============================================================== */

final class PackageBinaryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'package_binary'
    ) {
        parent::__construct($message);
    }

    public static function notPlaced(): self
    {
        return new self(
            'The member has no binary node. Place them in the tree before activating a binary-enabled package.',
            'not_placed'
        );
    }

    public static function notApproved(): self
    {
        return new self(
            'Only an approved activation places package binary points.',
            'not_approved'
        );
    }

    public static function packageDisabled(): self
    {
        return new self(
            'The package stream or this package is not binary-enabled, or it contributes zero points.',
            'package_disabled'
        );
    }

    public static function alreadyReversed(): self
    {
        return new self(
            'This activation has already been reversed. Nothing was changed.',
            'already_reversed'
        );
    }
}
