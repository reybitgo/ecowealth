<?php
declare(strict_types=1);

/* ==============================================================
 *  WalletException — a wallet rule was broken (plan §5.8, Phase 5)
 *
 *  Carries a machine-readable `reason` so controllers and callers in
 *  later phases can branch without parsing the message:
 *
 *      amount        zero, negative, non-numeric or over the column cap
 *      insufficient  the total bucket cannot cover the debit
 *      withdrawable  the withdrawable bucket cannot cover the debit
 *      ref_type      unknown ref_type (schema-drift guard)
 *      user_missing  the account does not exist
 *      forbidden     the actor may not perform this movement
 *      invariant     a mutation would break ewallet >= withdrawable >= 0
 *
 *  Extends InvalidArgumentException so callers that only care that the
 *  request was refused keep working.
 * ============================================================== */

final class WalletException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $reason = 'wallet')
    {
        parent::__construct($message);
    }

    public static function invalidAmount(string $detail = 'Amount must be greater than zero.'): self
    {
        return new self($detail, 'amount');
    }

    public static function amountTooLarge(): self
    {
        return new self('Amount is larger than the wallet can hold.', 'amount');
    }

    public static function amountPrecision(): self
    {
        return new self('Amount may have at most two decimal places.', 'amount');
    }

    public static function insufficient(float $requested, float $available): self
    {
        return new self(
            sprintf('Insufficient wallet balance. Needed %s, available %s.', money($requested), money($available)),
            'insufficient'
        );
    }

    public static function notWithdrawable(float $requested, float $available): self
    {
        return new self(
            sprintf('Only %s of this wallet is withdrawable (needed %s).', money($available), money($requested)),
            'withdrawable'
        );
    }

    public static function unknownRefType(string $refType): self
    {
        return new self('Unknown wallet movement type: ' . $refType, 'ref_type');
    }

    public static function userMissing(): self
    {
        return new self('That account could not be found.', 'user_missing');
    }

    public static function forbidden(): self
    {
        return new self('You do not have permission to change this wallet.', 'forbidden');
    }

    public static function invariant(string $detail): self
    {
        return new self('The wallet could not be updated safely: ' . $detail, 'invariant');
    }
}
