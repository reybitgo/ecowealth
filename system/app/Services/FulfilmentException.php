<?php
declare(strict_types=1);

/* ==============================================================
 *  FulfilmentException — a shipping/completion rule was broken
 *  (Phase 8)
 *
 *  Carries a machine-readable `reason` and, for validation failures,
 *  the field => message map so the admin form behaves like every
 *  other form (inline errors + retained input):
 *
 *      not_found · status · validation · stock · nothing_to_ship
 * ============================================================== */

final class FulfilmentException extends RuntimeException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        string $message,
        public readonly string $reason = 'fulfilment',
        public readonly array $fieldErrors = []
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors): self
    {
        $first = $errors === [] ? 'Please check the shipping details.' : (string) reset($errors);

        return new self($first, 'validation', $errors);
    }

    public static function notFound(): self
    {
        return new self('That order could not be found.', 'not_found');
    }

    public static function status(string $action, string $status): self
    {
        return new self(
            sprintf(
                'This order is “%s” now, so it cannot be %s. Reload the page and try again.',
                ucfirst(str_replace('_', ' ', $status)),
                $action
            ),
            'status'
        );
    }

    public static function nothingToShip(): self
    {
        return new self(
            'This order has no reserved units left to ship. Check its lines before trying again.',
            'nothing_to_ship'
        );
    }

    public static function stock(string $productName, int $needed, int $onHand): self
    {
        return new self(
            sprintf(
                '“%s” has only %d on hand but this order needs %d. Adjust the stock before shipping.',
                $productName,
                $onHand,
                $needed
            ),
            'stock'
        );
    }
}
