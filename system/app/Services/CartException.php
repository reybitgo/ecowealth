<?php
declare(strict_types=1);

/* ==============================================================
 *  CartException — a cart rule was broken (plan §5.2, Phase 4)
 *
 *  Carries a machine-readable `reason` so the controller can pick
 *  the HTTP status / flash tone without parsing the message:
 *
 *      product_missing   the id is not a product
 *      product_inactive  the product is hidden from the shop
 *      quantity          qty < 1 (or 0 where the line must stay)
 *      stock             qty exceeds available stock
 *      item_missing      the line is not in the caller's cart (IDOR)
 *      cart_missing      the caller has no active cart
 *      user_missing      the caller's account vanished mid-request
 *
 *  Extends InvalidArgumentException so callers that only care that
 *  the request was refused keep working (reference behaviour).
 * ============================================================== */

final class CartException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $reason = 'cart')
    {
        parent::__construct($message);
    }

    public static function productMissing(): self
    {
        return new self('That product could not be found.', 'product_missing');
    }

    public static function productInactive(string $name): self
    {
        return new self(sprintf('“%s” is no longer available.', $name), 'product_inactive');
    }

    public static function quantity(): self
    {
        return new self('Quantity must be at least 1.', 'quantity');
    }

    public static function quantityTooLarge(int $max): self
    {
        return new self(sprintf('Quantity cannot be more than %d per product.', $max), 'quantity');
    }

    public static function stock(int $requested, int $available): self
    {
        return new self(
            sprintf('Insufficient stock. Requested %d, only %d available.', $requested, $available),
            'stock'
        );
    }

    public static function itemMissing(): self
    {
        return new self('That item is no longer in your cart.', 'item_missing');
    }

    public static function cartMissing(): self
    {
        return new self('Your cart is empty.', 'cart_missing');
    }

    public static function userMissing(): self
    {
        return new self('Your account could not be loaded. Please sign in again.', 'user_missing');
    }
}
