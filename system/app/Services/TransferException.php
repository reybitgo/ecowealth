<?php
declare(strict_types=1);

/* ==============================================================
 *  TransferException — a transfer rule was broken (plan §5.8,
 *  Phase 12)
 *
 *  Carries a machine-readable `reason` so the controller (and the
 *  tests) can branch without parsing the message:
 *
 *      password        the re-entered password did not match
 *      amount          zero, negative, non-numeric, over the column cap
 *      minimum         below settings.ewallet_min_transfer
 *      balance         the total debit (amount + fee) does not fit
 *      self            sender and recipient are the same account
 *      recipient       the recipient username is unknown
 *      inactive        the recipient account is not active
 *      daily_limit     settings.ewallet_transfer_daily_limit exceeded
 *      weekly_limit    settings.ewallet_transfer_weekly_limit exceeded
 *      user_missing    the sender account does not exist any more
 *
 *  Extends InvalidArgumentException so callers that only care that the
 *  request was refused keep working (same shape as WalletException).
 * ============================================================== */

final class TransferException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $reason = 'transfer')
    {
        parent::__construct($message);
    }

    public static function password(): self
    {
        return new self('Your password is not correct. The transfer was not sent.', 'password');
    }

    public static function passwordRequired(): self
    {
        return new self('Enter your password to confirm the transfer.', 'password');
    }

    public static function invalidAmount(string $detail = 'Enter an amount greater than zero.'): self
    {
        return new self($detail, 'amount');
    }

    public static function amountTooLarge(): self
    {
        return new self('That amount is larger than a wallet transfer allows.', 'amount');
    }

    public static function belowMinimum(float $minimum, float $amount): self
    {
        return new self(
            sprintf('The minimum transfer is %s (you entered %s).', money($minimum), money($amount)),
            'minimum'
        );
    }

    public static function insufficient(float $needed, float $available): self
    {
        return new self(
            sprintf(
                'Insufficient wallet balance. The transfer needs %s (amount + fee); you have %s.',
                money($needed),
                money($available)
            ),
            'balance'
        );
    }

    public static function selfTransfer(): self
    {
        return new self('You cannot transfer money to your own account.', 'self');
    }

    public static function recipientRequired(): self
    {
        return new self('Enter the username of the member you want to pay.', 'recipient');
    }

    public static function recipientMissing(string $username): self
    {
        return new self(sprintf('No member named @%s was found.', $username), 'recipient');
    }

    public static function recipientInactive(string $username): self
    {
        return new self(sprintf('@%s cannot receive money right now — the account is not active.', $username), 'inactive');
    }

    public static function dailyLimit(float $limit, float $sent, float $amount): self
    {
        return new self(
            sprintf(
                'Daily transfer limit reached: %s of %s already sent today, so %s more is not allowed.',
                money($sent),
                money($limit),
                money($amount)
            ),
            'daily_limit'
        );
    }

    public static function weeklyLimit(float $limit, float $sent, float $amount): self
    {
        return new self(
            sprintf(
                'Weekly transfer limit reached: %s of %s already sent this week, so %s more is not allowed.',
                money($sent),
                money($limit),
                money($amount)
            ),
            'weekly_limit'
        );
    }

    public static function userMissing(): self
    {
        return new self('That account could not be found.', 'user_missing');
    }

    public static function feeAccountMissing(): self
    {
        return new self(
            'Transfers with a fee are unavailable until an admin account exists to receive it.',
            'config'
        );
    }
}
