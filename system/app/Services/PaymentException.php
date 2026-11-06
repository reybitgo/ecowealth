<?php
declare(strict_types=1);

/* ==============================================================
 *  PaymentException — a manual-payment rule was broken (Phase 7)
 *
 *  Carries a machine-readable `reason` and, for validation
 *  failures, the field => message map so the controller can render
 *  the form exactly like any other invalid submission:
 *
 *      not_found · status · expired · attempts · validation ·
 *      amount · reference · proof · self_review · reason · stock ·
 *      refund
 * ============================================================== */

final class PaymentException extends RuntimeException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        string $message,
        public readonly string $reason = 'payment',
        public readonly array $fieldErrors = []
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors): self
    {
        $first = $errors === [] ? 'Please check the payment details.' : (string) reset($errors);

        return new self($first, 'validation', $errors);
    }

    public static function notFound(): self
    {
        return new self('That order could not be found.', 'not_found');
    }

    /**
     * The proof window is closed. The message explains which rule
     * stopped the upload (status, deadline or attempt cap).
     *
     * @param array{open:bool, reason:string, used:int, left:int, max:int, deadline:?string} $window
     */
    public static function window(array $window): self
    {
        return match ($window['reason'] ?? '') {
            'review'   => new self('Your payment proof is already with us. We will review it shortly.', 'status'),
            'status'   => new self('This order is not waiting for a payment proof.', 'status'),
            'expired'  => new self('The payment window for this order has closed. Please place a new order.', 'expired'),
            'attempts' => new self(
                sprintf(
                    'You have used all %d payment-proof attempt%s for this order. Please contact support.',
                    $window['max'],
                    $window['max'] === 1 ? '' : 's'
                ),
                'attempts'
            ),
            default    => new self('A payment proof cannot be uploaded for this order right now.', 'status'),
        };
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

    public static function amount(float $claimed, float $total): self
    {
        return new self(
            sprintf(
                'The amount received (%s) does not match the order total (%s).',
                money($claimed),
                money($total)
            ),
            'amount'
        );
    }

    public static function reference(string $reference): self
    {
        return new self(
            sprintf('The reference “%s” is already used by another order.', $reference),
            'reference'
        );
    }

    public static function proof(): self
    {
        return new self('There is no pending payment proof on this order to review.', 'proof');
    }

    public static function stock(string $productName, int $requested, int $available): self
    {
        return new self(
            sprintf(
                '“%s” is no longer available in the quantity you ordered (you asked for %d, only %d left). Please contact support or cancel the order.',
                $productName,
                $requested,
                $available
            ),
            'stock'
        );
    }

    public static function selfReview(): self
    {
        return new self(
            'You placed this order. An administrator cannot verify, reject or cancel their own order.',
            'self_review'
        );
    }

    public static function reason(string $action): self
    {
        return new self(sprintf('Choose a reason before you %s this order.', $action), 'reason');
    }

    public static function refund(string $message): self
    {
        return new self($message, 'refund');
    }

    public static function userMissing(): self
    {
        return new self('Your account could not be loaded. Please sign in again.', 'not_found');
    }
}
