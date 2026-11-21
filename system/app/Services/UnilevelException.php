<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelException — a repeat-purchase rule was broken (17C)
 *
 *  Same shape as OrderException: a machine-readable `reason` plus a
 *  message the caller may render as-is. The engine throws only for
 *  structural failures (a missing order); an order that is simply not
 *  paid is a normal "nothing due" answer, not an exception
 *  (plan §14.3).
 *
 *      not_found · not_paid · state
 * ============================================================== */

final class UnilevelException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'unilevel'
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('That order could not be found for the repeat-purchase bonus.', 'not_found');
    }

    public static function notPaid(string $status): self
    {
        return new self(
            'The repeat-purchase bonus is paid only on a paid order; this one is ' . $status . '.',
            'not_paid'
        );
    }

    public static function state(string $message): self
    {
        return new self($message, 'state');
    }
}
