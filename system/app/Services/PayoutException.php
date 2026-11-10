<?php
declare(strict_types=1);

/* ==============================================================
 *  PayoutException — a payout rule was broken (plan §5.9,
 *  Phase 13)
 *
 *  Carries a machine-readable `reason` so the controllers (and the
 *  tests) can branch without parsing the message:
 *
 *      amount          zero, negative, non-numeric, over the column cap
 *      minimum         below settings.min_payout
 *      withdrawable    the withdrawable bucket cannot cover the amount
 *      open_request    the member already has a pending/approved request
 *      method          the method is not one of the four channels
 *      method_disabled the admin switched that channel off
 *      account         the payout account is missing or malformed
 *      rate            a USDT payout was requested before usdt_rate was set
 *      status          the request is not in a state that allows the action
 *      not_found       the request does not exist
 *      self            a member tried to process (or approve) their own payout
 *      admin           the actor is not an active admin/superadmin
 *      reason          a rejection arrived without a reason
 *      user_missing    the account does not exist any more
 *
 *  Extends InvalidArgumentException so callers that only care that the
 *  request was refused keep working (same shape as WalletException and
 *  TransferException).
 * ============================================================== */

final class PayoutException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $reason = 'payout')
    {
        parent::__construct($message);
    }

    public static function invalidAmount(string $detail = 'Enter an amount greater than zero.'): self
    {
        return new self($detail, 'amount');
    }

    public static function amountPrecision(): self
    {
        return new self('Amount may have at most two decimal places.', 'amount');
    }

    public static function amountTooLarge(): self
    {
        return new self('That amount is larger than a payout request allows.', 'amount');
    }

    public static function belowMinimum(float $minimum, float $amount): self
    {
        return new self(
            sprintf('The minimum payout is %s (you entered %s).', money($minimum), money($amount)),
            'minimum'
        );
    }

    public static function aboveWithdrawable(float $amount, float $withdrawable): self
    {
        return new self(
            sprintf(
                'You can withdraw up to %s — the withdrawable part of your balance. %s is more than that.',
                money($withdrawable),
                money($amount)
            ),
            'withdrawable'
        );
    }

    public static function openRequest(string $status = 'pending'): self
    {
        return new self(
            sprintf(
                'You already have a %s payout request. Wait for it to be processed before requesting another.',
                $status === 'approved' ? 'approved' : 'pending'
            ),
            'open_request'
        );
    }

    public static function unknownMethod(string $method): self
    {
        return new self(sprintf('“%s” is not a payout method.', $method), 'method');
    }

    public static function methodDisabled(string $label): self
    {
        return new self(sprintf('%s payouts are not available right now.', $label), 'method_disabled');
    }

    public static function accountRequired(string $label, string $hint): self
    {
        return new self(sprintf('Enter the %s %s.', $label, $hint), 'account');
    }

    public static function accountInvalid(string $label, string $hint): self
    {
        return new self(sprintf('Enter a valid %s — %s.', $label, $hint), 'account');
    }

    public static function rateMissing(): self
    {
        return new self(
            'USDT payouts are unavailable until an administrator sets the ₱ per USDT rate.',
            'rate'
        );
    }

    public static function wrongStatus(string $status, string $action): self
    {
        return new self(
            sprintf('This request is %s, so it cannot be %s.', $status, $action),
            'status'
        );
    }

    public static function notFound(): self
    {
        return new self('That payout request could not be found.', 'not_found');
    }

    public static function ownPayout(): self
    {
        return new self('You cannot process your own payout request.', 'self');
    }

    public static function notStaff(): self
    {
        return new self('Only an administrator can process payout requests.', 'admin');
    }

    public static function rejectionNeedsReason(): self
    {
        return new self('Enter the reason for rejecting this request — the member sees it.', 'reason');
    }

    public static function userMissing(): self
    {
        return new self('That account could not be found.', 'user_missing');
    }
}
