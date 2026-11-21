<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryException — a product-binary placement rule was broken (22C)
 *
 *  Same shape as UnilevelException: a machine-readable `reason` plus a
 *  message the caller may render as-is. The service throws only for
 *  structural failures (taken slot, cycle, immutability); a caller that
 *  is not allowed to place is a normal authorization answer, not an
 *  exception (that is handled at the HTTP layer in 22C-S4).
 *
     *      not_placed · slot_taken · immutable · cycle · state
     *      not_paid · position_required
     * ============================================================== */

final class BinaryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'binary'
    ) {
        parent::__construct($message);
    }

    /** The upline or target member has no binary node. */
    public static function notPlaced(int $userId): self
    {
        return new self(
            'Member #' . $userId . ' has no binary node. Place them first.',
            'not_placed'
        );
    }

    /** The requested (upline, side) slot is already occupied. */
    public static function slotTaken(int $uplineId, string $side): self
    {
        return new self(
            'The ' . $side . ' slot under member #' . $uplineId . ' is already taken.',
            'slot_taken'
        );
    }

    /** The subtree cannot be moved because it already carries volume or awards. */
    public static function immutable(int $userId, string $reason): self
    {
        return new self(
            'Member #' . $userId . ' cannot be moved: ' . $reason . '.',
            'immutable'
        );
    }

    /** The placement would make the upline a descendant of the placed member (a cycle). */
    public static function cycle(int $userId, int $uplineId): self
    {
        return new self(
            'Placing member #' . $userId . ' under #' . $uplineId . ' would create a cycle.',
            'cycle'
        );
    }

    /** A general placement-state failure (e.g. no usable company root). */
    public static function state(string $message): self
    {
        return new self($message, 'state');
    }

    /** The order is not paid yet, so no binary work can run. */
    public static function notPaid(): self
    {
        return new self('The order is not paid; binary points and awards are written on payment only.', 'not_paid');
    }

    /** The cart carries points but no binary position was chosen at checkout. */
    public static function positionRequired(): self
    {
        return new self('This order places points — choose left or right before placing it.', 'position_required');
    }
}
