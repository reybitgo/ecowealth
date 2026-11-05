<?php
declare(strict_types=1);

/* ==============================================================
 *  OrderException — a checkout / order rule was broken (Phase 6)
 *
 *  Carries a machine-readable `reason` and, for validation
 *  failures, the field => message map so the controller can render
 *  the form exactly like any other invalid submission:
 *
 *      empty_cart · stock · wallet · payment_method · product_missing
 *      product_inactive · validation · not_found · not_cancellable
 *      shop_closed · invalid_total · user_missing · duplicate
 * ============================================================== */

final class OrderException extends RuntimeException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        string $message,
        public readonly string $reason = 'order',
        public readonly array $fieldErrors = []
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors): self
    {
        $first = $errors === [] ? 'Please check the checkout form.' : (string) reset($errors);

        return new self($first, 'validation', $errors);
    }

    public static function emptyCart(): self
    {
        return new self('Your cart is empty. Add a product before checking out.', 'empty_cart');
    }

    public static function shopClosed(): self
    {
        return new self('The shop is currently closed. Please try again later.', 'shop_closed');
    }

    public static function userMissing(): self
    {
        return new self('Your account could not be loaded. Please sign in again.', 'user_missing');
    }

    public static function stock(string $productName, int $requested, int $available): self
    {
        return new self(
            sprintf('Stock changed: “%s” only has %d left (you asked for %d). Adjust your cart and try again.', $productName, $available, $requested),
            'stock'
        );
    }

    public static function productMissing(string $productName): self
    {
        return new self(sprintf('“%s” is no longer available. Remove it from your cart.', $productName), 'product_missing');
    }

    public static function productInactive(string $productName): self
    {
        return new self(sprintf('“%s” is no longer available. Remove it from your cart.', $productName), 'product_inactive');
    }

    public static function walletShort(float $balance, float $total): self
    {
        return new self(
            sprintf(
                'Your e-wallet balance (%s) is not enough for this order (%s). Choose manual payment or top up first.',
                money($balance),
                money($total)
            ),
            'wallet'
        );
    }

    public static function wallet(string $message): self
    {
        return new self($message, 'wallet');
    }

    public static function invalidTotal(): self
    {
        return new self('This order has no payable total. Please check your cart.', 'invalid_total');
    }

    public static function notFound(): self
    {
        return new self('That order could not be found.', 'not_found');
    }

    public static function notCancellable(string $status): self
    {
        return new self(
            $status === 'cancelled'
                ? 'This order has already been cancelled.'
                : 'This order can no longer be cancelled online. Please contact support.',
            'not_cancellable'
        );
    }
}
