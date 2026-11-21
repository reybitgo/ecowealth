<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyException — a Royalty money rule was broken (19C)
 *
 *  Same shape as UnilevelException and OrderException: a
 *  machine-readable `reason` plus a message the caller may render
 *  as-is. The engine throws only for structural failures (a period
 *  that cannot be closed, paid or found); a month that is simply
 *  below quota is a normal `skipped` row, not an exception
 *  (plan §14.2).
 *
 *      not_found · closed · future · current · state · paused · empty
 *
 *  There is deliberately no repair path: a closed month is a
 *  financial record (plan §2.5, D-13), so nothing here reopens,
 *  recalculates or voids one.
 * ============================================================== */

final class RoyaltyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'royalty'
    ) {
        parent::__construct($message);
    }

    /** No `royalty_periods` header for that month. */
    public static function notFound(string $period): self
    {
        return new self(
            'There is no Royalty period ' . $period . '; evaluate the month first.',
            'not_found'
        );
    }

    /** A header already exists: a final month is never recalculated (D-13). */
    public static function closed(string $period): self
    {
        return new self(
            'The Royalty period ' . $period . ' already exists and is never recalculated (plan D-13).',
            'closed'
        );
    }

    /** The current month is not over, so its awards cannot be known. */
    public static function current(string $period): self
    {
        return new self(
            'The Royalty period ' . $period . ' is the current month; only a completed month can be closed.',
            'current'
        );
    }

    /** A month that has not happened yet. */
    public static function future(string $period): self
    {
        return new self(
            'The Royalty period ' . $period . ' is in the future; only a completed month can be closed.',
            'future'
        );
    }

    /** Any other lifecycle violation (a bad state transition). */
    public static function state(string $message): self
    {
        return new self($message, 'state');
    }

    /** `royalty_pay_enabled = 0`: awards stay pending, no wallet money. */
    public static function paused(string $period): self
    {
        return new self(
            'Royalty payments are paused (royalty_pay_enabled = 0); the awards of '
                . $period . ' stay pending.',
            'paused'
        );
    }

    /** Nothing to act on: no pending award and no open month. */
    public static function empty(string $period): self
    {
        return new self(
            'Nothing to do for Royalty period ' . $period . ': no award is pending.',
            'empty'
        );
    }
}
