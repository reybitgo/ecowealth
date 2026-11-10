<?php
declare(strict_types=1);

/* ==============================================================
 *  PayoutService — withdrawal requests with a hold, a fee and a
 *  USDT conversion (plan §5.9, §7 Phase 13; reference
 *  models/Payout.php, MemberController::requestPayout,
 *  AdminController::payoutAction)
 *
 *  The rules this class owns:
 *
 *    • amount > 0, at most two decimals, >= settings.min_payout;
 *    • amount <= the member's withdrawable bucket — not their total
 *      balance (that is the whole point of the second bucket);
 *    • at most one pending/approved request per member;
 *    • the method is one of the four channels and its
 *      payout_<method>_enabled switch is on;
 *    • the payout account (number or address) is required and is
 *      validated with the same rules the profile form uses;
 *    • fee = settings.service_fee_<method> percent of the amount;
 *      net = amount − fee;
 *    • USDT: net_usdt = (net − gas_fee_usdt × usdt_rate) / usdt_rate,
 *      computed from settings only, and stored on the request with the
 *      rate and the gas fee that produced it. Without a rate the
 *      channel is refused — the browser never supplies it (plan §2.3);
 *    • the funds are HELD at request time (D17): one transaction
 *      inserts the request, debits the wallet (`payout`, both buckets)
 *      and writes the audit row. Rejection refunds the hold; completion
 *      moves no money at all;
 *    • only an active admin/superadmin can approve, reject or complete,
 *      and never their own request;
 *    • every status change is a conditional UPDATE whose row count must
 *      be 1, so a double-clicked approve cannot approve twice.
 *
 *  Money notation: DECIMAL strings at every boundary, floats only for
 *  arithmetic, and `decimal()` for the column values (never scientific
 *  notation).
 * ============================================================== */

final class PayoutService
{
    /** The four channels of plan §5.9, in display order. */
    public const METHODS = ['gcash', 'maya', 'usdt_trc20', 'usdt_bep20'];

    /** The channels that pay in USDT and convert at the ₱/USDT rate. */
    public const USDT_METHODS = ['usdt_trc20', 'usdt_bep20'];

    /** Roles that may process a request. */
    public const STAFF_ROLES = ['admin', 'superadmin'];

    /** DECIMAL(12,2) upper bound for a request amount. */
    public const MAX_AMOUNT = 9999999999.99;

    /** DECIMAL(12,4) upper bound for the stored ₱/USDT rate. */
    public const MAX_RATE = 99999999.9999;

    /** DECIMAL(10,4) upper bound for a stored gas fee. */
    public const MAX_GAS = 999999.9999;

    /** DECIMAL(5,2) upper bound for the stored fee percentage. */
    public const MAX_FEE_PCT = 999.99;

    /** `admin_note` column width. */
    public const NOTE_MAX = 500;

    public const LABELS = [
        'gcash'      => 'GCash',
        'maya'       => 'Maya',
        'usdt_trc20' => 'USDT (TRC20)',
        'usdt_bep20' => 'USDT (BEP20)',
    ];

    /** The `users` column that holds each channel's saved account. */
    public const ACCOUNT_FIELDS = [
        'gcash'      => 'gcash_number',
        'maya'       => 'maya_number',
        'usdt_trc20' => 'usdt_trc20_address',
        'usdt_bep20' => 'usdt_bep20_address',
    ];

    /** Savepoint names must be unique when calls nest. */
    private static int $savepointSeq = 0;

    private PDO $pdo;
    private WalletService $wallet;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo    = $pdo ?? db();
        $this->wallet = new WalletService($this->pdo);
    }

    /* ----------------------------------------------------------
     *  Settings (static: views render them without a service)
     * ---------------------------------------------------------- */

    /** settings.min_payout — the smallest request allowed. */
    public static function minPayout(): float
    {
        return round(max(0.0, setting_float('min_payout', 500.0)), 2);
    }

    /** settings.service_fee_<method> — a percentage, clamped to 0–100. */
    public static function serviceFeePct(string $method): float
    {
        if (!in_array($method, self::METHODS, true)) {
            return 0.0;
        }

        return round(min(100.0, max(0.0, setting_float('service_fee_' . $method, 0.0))), 2);
    }

    /** settings.usdt_rate — ₱ per 1 USDT; 0 means "not configured". */
    public static function usdtRate(): float
    {
        return round(min(self::MAX_RATE, max(0.0, setting_float('usdt_rate', 0.0))), 4);
    }

    /**
     * settings.usdt_trc20_gas_fee / settings.usdt_bep20_gas_fee — the
     * network fee in USDT (0 for the peso channels).
     */
    public static function gasFee(string $method): float
    {
        if (!in_array($method, self::USDT_METHODS, true)) {
            return 0.0;
        }

        $key     = $method === 'usdt_bep20' ? 'usdt_bep20_gas_fee' : 'usdt_trc20_gas_fee';
        $default = $method === 'usdt_bep20' ? 0.05 : 2.50;

        return round(min(self::MAX_GAS, max(0.0, setting_float($key, $default))), 4);
    }

    /** Is the channel configured as `payout_<method>_enabled = 1`? */
    public static function methodEnabled(string $method): bool
    {
        return in_array($method, self::METHODS, true)
            && setting_bool('payout_' . $method . '_enabled', true);
    }

    /**
     * A short fingerprint of the settings both payout pages print.
     *
     * `/payouts` and `/admin/payouts` render the floor, each channel's
     * switch and fee, and the ₱/USDT rate with its network fees. The
     * fingerprint is mixed into both pages' ETags so a settings change
     * revalidates an open tab on its next poll — including one written by
     * a migration, a CLI job or a direct database edit, which never calls
     * `Live::touch()` and so never moves the revision table.
     *
     * The *effective* values are hashed (clamped and rounded the way the
     * page formats them), so a cosmetic save — "5" written as "5.00" —
     * does not churn every open payout page.
     */
    public static function settingsFingerprint(): string
    {
        $values = ['min' => number_format(self::minPayout(), 2, '.', '')];

        foreach (self::METHODS as $method) {
            $values[$method] = implode(':', [
                self::methodEnabled($method) ? '1' : '0',
                number_format(self::serviceFeePct($method), 2, '.', ''),
                number_format(self::gasFee($method), 4, '.', ''),
            ]);
        }

        $values['usdt_rate'] = number_format(self::usdtRate(), 4, '.', '');

        $encoded = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return substr(hash('sha256', $encoded === false ? '' : $encoded), 0, 10);
    }

    public static function isUsdt(string $method): bool
    {
        return in_array($method, self::USDT_METHODS, true);
    }

    public static function label(string $method): string
    {
        return self::LABELS[$method] ?? ucfirst(str_replace('_', ' ', $method));
    }

    /** The `users` column holding this channel's saved account. */
    public static function accountField(string $method): string
    {
        return self::ACCOUNT_FIELDS[$method] ?? '';
    }

    public static function isStaffRole(string $role): bool
    {
        return in_array($role, self::STAFF_ROLES, true);
    }

    /** What each channel's account must look like, in a few words. */
    public static function hint(string $method): string
    {
        return match ($method) {
            'gcash'      => 'GCash number (09XXXXXXXXX)',
            'maya'       => 'Maya number (09XXXXXXXXX)',
            'usdt_trc20' => 'TRC20 address (T followed by 33 characters)',
            'usdt_bep20' => 'BEP20 address (0x followed by 40 hex characters)',
            default      => 'payout account',
        };
    }

    /** Boxicons class for a channel. */
    public static function icon(string $method): string
    {
        return match ($method) {
            'gcash'      => 'bx-mobile-alt',
            'maya'       => 'bx-mobile',
            'usdt_trc20' => 'bx-dollar-circle',
            'usdt_bep20' => 'bx-coin-stack',
            default      => 'bx-money-withdraw',
        };
    }

    /* ----------------------------------------------------------
     *  Quoting — the one fee/USDT calculation
     * ---------------------------------------------------------- */

    /**
     * Everything a request costs and pays, from settings only.
     *
     * Used by request() to decide what to store, by the member page to
     * explain it before the member submits, and by the tests to pin the
     * arithmetic (P13-T5, P13-T6).
     *
     * @return array{
     *   method:string, usdt:bool, amount:float,
     *   fee_pct:float, fee_amount:float, net_amount:float,
     *   usdt_rate:float, usdt_gas_fee:float, usdt_amount:float
     * }
     */
    public function quote(string $method, float|int|string $amount): array
    {
        $method = $this->method($method);
        $amount = $this->amount($amount);

        $pct = self::serviceFeePct($method);
        $fee = round($amount * $pct / 100, 2);
        $net = round($amount - $fee, 2);

        $rate       = 0.0;
        $gas        = 0.0;
        $usdtAmount = 0.0;

        if (self::isUsdt($method)) {
            $rate = self::usdtRate();
            $gas  = self::gasFee($method);

            if ($rate > 0) {
                $netPhp     = round($net - ($gas * $rate), 2);
                $usdtAmount = $netPhp > 0 ? round($netPhp / $rate, 4) : 0.0;
            }
        }

        return [
            'method'       => $method,
            'usdt'         => self::isUsdt($method),
            'amount'       => $amount,
            'fee_pct'      => $pct,
            'fee_amount'   => $fee,
            'net_amount'   => $net,
            'usdt_rate'    => $rate,
            'usdt_gas_fee' => $gas,
            'usdt_amount'  => $usdtAmount,
        ];
    }

    /**
     * The channels a member can use, with their saved account, fee and
     * availability — the data behind the request form.
     *
     * @param array<string, mixed> $account a `users` row (the caller's)
     * @return list<array<string, mixed>>
     */
    public function methodsFor(array $account): array
    {
        $rate = self::usdtRate();
        $out  = [];

        foreach (self::METHODS as $method) {
            $enabled = self::methodEnabled($method);
            $usdt    = self::isUsdt($method);
            $field   = self::accountField($method);
            $gas     = $usdt ? self::gasFee($method) : 0.0;
            $reason  = '';

            if (!$enabled) {
                $reason = 'This channel is switched off.';
            } elseif ($usdt && $rate <= 0) {
                $reason = 'Unavailable until the ₱ per USDT rate is set.';
            }

            $out[] = [
                'key'           => $method,
                'label'         => self::label($method),
                'icon'          => self::icon($method),
                'enabled'       => $enabled,
                'available'     => $enabled && (!$usdt || $rate > 0),
                'usdt'          => $usdt,
                'account_field' => $field,
                'account'       => (string) ($account[$field] ?? ''),
                'account_hint'  => self::hint($method),
                'fee_pct'       => self::serviceFeePct($method),
                'gas_fee'       => $gas,
                'usdt_rate'     => $usdt ? $rate : 0.0,
                'reason'        => $reason,
            ];
        }

        return $out;
    }

    /**
     * Everything the member's payout page renders, derived from an
     * already-loaded account row.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function optionsFor(array $account): array
    {
        $userId = (int) ($account['id'] ?? 0);
        $open   = Payout::openFor($userId, $this->pdo);
        $min    = self::minPayout();

        $balance      = round((float) ($account['ewallet_balance'] ?? 0), 2);
        $withdrawable = round((float) ($account['withdrawable_balance'] ?? 0), 2);

        $methods   = $this->methodsFor($account);
        $available = count(array_filter($methods, static fn (array $m): bool => $m['available'] === true));

        return [
            'user_id'          => $userId,
            'username'         => (string) ($account['username'] ?? ''),
            'balance'          => $balance,
            'withdrawable'     => $withdrawable,
            'non_withdrawable' => round($balance - $withdrawable, 2),
            'min'              => $min,
            'max_amount'       => $withdrawable,
            'methods'          => $methods,
            'channels_open'    => $available,
            'open'             => $open,
            /* The form is offered when there is money to take out, a
             * channel to take it through, and no request already holding
             * the member's funds. */
            'can_request'      => $open === null
                && $available > 0
                && $withdrawable > 0
                && ($min <= 0 || $withdrawable >= $min),
            'usdt_rate'        => self::usdtRate(),
        ];
    }

    /**
     * optionsFor() for a user id, throwing when the account is gone.
     *
     * @throws PayoutException
     */
    public function options(int $userId): array
    {
        $account = $this->account($userId);

        if ($account === null) {
            throw PayoutException::userMissing();
        }

        return $this->optionsFor($account);
    }

    /* ----------------------------------------------------------
     *  Request — the hold
     * ---------------------------------------------------------- */

    /**
     * Create a withdrawal request and hold the funds.
     *
     * @param float|int|string $amount gross the member asks for
     * @param string $account the number/address the company pays to
     * @return array<string, mixed> the request row plus its quote
     *
     * @throws PayoutException when a rule refuses the request
     */
    public function request(int $userId, string $method, string $account, float|int|string $amount): array
    {
        $method = $this->method($method);

        if (!self::methodEnabled($method)) {
            throw PayoutException::methodDisabled(self::label($method));
        }

        /* A USDT payout needs the server-side rate before any money moves. */
        if (self::isUsdt($method) && self::usdtRate() <= 0) {
            throw PayoutException::rateMissing();
        }

        $amount  = $this->amount($amount);
        $account = $this->payoutAccount($method, $account);
        $quote   = $this->quote($method, $amount);

        $minimum = self::minPayout();

        if ($minimum > 0 && $amount < $minimum) {
            throw PayoutException::belowMinimum($minimum, $amount);
        }

        if ($quote['net_amount'] <= 0) {
            throw PayoutException::invalidAmount(
                'The service fee leaves nothing to pay out. Increase the amount.'
            );
        }

        if (self::isUsdt($method) && $quote['usdt_amount'] <= 0) {
            throw PayoutException::invalidAmount(
                'The gas fee is larger than what this payout would send. Increase the amount.'
            );
        }

        $actor = $this->account($userId);

        if ($actor === null) {
            throw PayoutException::userMissing();
        }

        if ((int) $actor['is_active'] !== 1) {
            throw PayoutException::userMissing();
        }

        return $this->withTransaction(function () use ($actor, $userId, $method, $account, $amount, $quote): array {
            $locked = $this->lockAccount($userId);

            if ($locked === null) {
                throw PayoutException::userMissing();
            }

            /* 1. The withdrawable bucket — the authoritative check, taken
             *    under the same row lock WalletService will debit. */
            $withdrawable = round((float) $locked['withdrawable_balance'], 2);

            if ($amount > $withdrawable) {
                throw PayoutException::aboveWithdrawable($amount, $withdrawable);
            }

            /* 2. One open request at a time. The account row is locked, so
             *    two concurrent submits serialise here. */
            $open = Payout::openFor($userId, $this->pdo);

            if ($open !== null) {
                throw PayoutException::openRequest((string) $open['status']);
            }

            /* 3. The request row first: the hold points at its id. */
            $this->pdo->prepare(
                'INSERT INTO payout_requests
                   (user_id, amount, payout_method, payout_account,
                    service_fee_pct, service_fee_amount,
                    usdt_rate, usdt_gas_fee, usdt_amount,
                    status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\')'
            )->execute([
                $userId,
                $this->decimal($amount),
                $method,
                $account,
                $this->decimal($quote['fee_pct']),
                $this->decimal($quote['fee_amount']),
                number_format((float) $quote['usdt_rate'], 4, '.', ''),
                number_format((float) $quote['usdt_gas_fee'], 4, '.', ''),
                number_format((float) $quote['usdt_amount'], 4, '.', ''),
            ]);

            $payoutId = (int) $this->pdo->lastInsertId();

            /* 4. The hold: both buckets, refused unless the withdrawable
             *    part covers it — the payout-hold wording of
             *    WalletService::debit(). */
            $movement = $this->wallet->debit(
                $userId,
                $amount,
                'payout',
                $payoutId,
                'Payout request #' . $payoutId . ' — ' . self::label($method)
            );

            AuditLog::record([
                'action'       => 'payout.request',
                'actor_id'     => $userId,
                'actor_role'   => (string) $actor['role'],
                'subject_type' => 'payout',
                'subject_id'   => $payoutId,
                'meta'         => [
                    'payout_id'          => $payoutId,
                    'user_id'            => $userId,
                    'method'             => $method,
                    'account'            => $account,
                    'amount'             => $this->decimal($amount),
                    'fee_pct'            => $this->decimal($quote['fee_pct']),
                    'fee_amount'         => $this->decimal($quote['fee_amount']),
                    'net_amount'         => $this->decimal($quote['net_amount']),
                    'usdt_rate'          => number_format((float) $quote['usdt_rate'], 4, '.', ''),
                    'usdt_gas_fee'       => number_format((float) $quote['usdt_gas_fee'], 4, '.', ''),
                    'usdt_amount'        => number_format((float) $quote['usdt_amount'], 4, '.', ''),
                    'balance_after'      => $this->decimal((float) $movement['balance_after']),
                    'withdrawable_after' => $this->decimal((float) $movement['withdrawable_after']),
                ],
            ], $this->pdo);

            Live::touchPayout($userId);

            return [
                'ok'                 => true,
                'payout_id'          => $payoutId,
                'user_id'            => $userId,
                'username'           => (string) $locked['username'],
                'method'             => $method,
                'method_label'       => self::label($method),
                'account'            => $account,
                'amount'             => $amount,
                'fee_pct'            => $quote['fee_pct'],
                'fee_amount'         => $quote['fee_amount'],
                'net_amount'         => $quote['net_amount'],
                'usdt'               => $quote['usdt'],
                'usdt_rate'          => $quote['usdt_rate'],
                'usdt_gas_fee'       => $quote['usdt_gas_fee'],
                'usdt_amount'        => $quote['usdt_amount'],
                'status'             => 'pending',
                'ledger_id'          => (int) $movement['ledger_id'],
                'balance_after'      => round((float) $movement['balance_after'], 2),
                'withdrawable_after' => round((float) $movement['withdrawable_after'], 2),
            ];
        });
    }

    /* ----------------------------------------------------------
     *  Queue decisions
     * ---------------------------------------------------------- */

    /**
     * pending → approved. The money was held at request time, so this
     * only records the decision.
     *
     * @return array<string, mixed>
     *
     * @throws PayoutException
     */
    public function approve(int $payoutId, int $actorId, string $note = ''): array
    {
        return $this->decide($payoutId, $actorId, 'approve', $note);
    }

    /**
     * pending/approved → rejected, refunding the hold exactly once.
     *
     * @return array<string, mixed>
     *
     * @throws PayoutException
     */
    public function reject(int $payoutId, int $actorId, string $reason): array
    {
        $reason = $this->note($reason);

        if ($reason === '') {
            throw PayoutException::rejectionNeedsReason();
        }

        return $this->decide($payoutId, $actorId, 'reject', $reason);
    }

    /**
     * approved → completed. Staff sent the funds off-platform; the wallet
     * was already debited when the request was made, so nothing moves.
     *
     * @return array<string, mixed>
     *
     * @throws PayoutException
     */
    public function complete(int $payoutId, int $actorId, string $note = ''): array
    {
        return $this->decide($payoutId, $actorId, 'complete', $note);
    }

    /**
     * One transaction for all three decisions, so the status change, the
     * refund and the audit row commit together.
     *
     * @return array<string, mixed>
     *
     * @throws PayoutException
     */
    private function decide(int $payoutId, int $actorId, string $action, string $note): array
    {
        $actor = $this->account($actorId);

        if ($actor === null || !self::isStaffRole((string) $actor['role']) || (int) $actor['is_active'] !== 1) {
            throw PayoutException::notStaff();
        }

        $note = $this->note($note);
        $verb = ['approve' => 'approved', 'reject' => 'rejected', 'complete' => 'completed'][$action];

        return $this->withTransaction(function () use ($payoutId, $actor, $actorId, $action, $verb, $note): array {
            $request = $this->lockRequest($payoutId);

            if ($request === null) {
                throw PayoutException::notFound();
            }

            /* Never your own money: the queue is a second pair of eyes. */
            if ((int) $request['user_id'] === $actorId) {
                throw PayoutException::ownPayout();
            }

            $status = (string) $request['status'];
            $amount = round((float) $request['amount'], 2);

            $update = $this->pdo->prepare(
                'UPDATE payout_requests
                    SET status = ?, processed_by = ?, processed_at = NOW(), admin_note = ?
                  WHERE id = ? AND status IN (' . $this->statusListFor($action) . ')'
            );

            if ($action === 'approve') {
                if ($status !== 'pending') {
                    throw PayoutException::wrongStatus($status, $verb);
                }

                $update->execute(['approved', $actorId, $note === '' ? null : $note, $payoutId]);
                $result = ['status' => 'approved', 'refunded' => false];
            } elseif ($action === 'reject') {
                if (!in_array($status, Payout::OPEN_STATUSES, true)) {
                    throw PayoutException::wrongStatus($status, $verb);
                }

                $update->execute(['rejected', $actorId, $note, $payoutId]);

                /* The hold comes back. `refund` restores the withdrawable
                 * split the hold took (all of it — a payout belongs to that
                 * bucket), and the ledger's (user, ref_type, ref_id, type)
                 * key means a second rejection can never refund twice.
                 *
                 * `refund` is shared with Phase 7's order refunds, whose
                 * ids come from a different sequence: if this member
                 * already has an order refund under the same numeric id,
                 * the payout refund keeps ref_id = NULL and names the
                 * payout in its note (the same fallback TransferService
                 * uses for a fee leg). */
                $refund = $this->wallet->refund(
                    (int) $request['user_id'],
                    $amount,
                    $this->refundRefId((int) $request['user_id'], $payoutId),
                    'Payout request #' . $payoutId . ' rejected — ' . $note,
                    $amount
                );

                $result = [
                    'status'             => 'rejected',
                    'refunded'           => true,
                    'ledger_id'          => (int) $refund['ledger_id'],
                    'balance_after'      => round((float) $refund['balance_after'], 2),
                    'withdrawable_after' => round((float) $refund['withdrawable_after'], 2),
                ];
            } else {
                if ($status !== 'approved') {
                    throw PayoutException::wrongStatus($status, $verb);
                }

                $update->execute(['completed', $actorId, $note === '' ? null : $note, $payoutId]);
                $result = ['status' => 'completed', 'refunded' => false];
            }

            if ($update->rowCount() !== 1) {
                /* The row moved between the lock and the update — only
                 * possible if something updated it outside this service. */
                throw PayoutException::wrongStatus($status, $verb);
            }

            AuditLog::record([
                'action'       => 'payout.' . $action,
                'actor_id'     => $actorId,
                'actor_role'   => (string) $actor['role'],
                'subject_type' => 'payout',
                'subject_id'   => $payoutId,
                'meta'         => [
                    'payout_id' => $payoutId,
                    'user_id'   => (int) $request['user_id'],
                    'method'    => (string) $request['payout_method'],
                    'amount'    => $this->decimal($amount),
                    'from'      => $status,
                    'to'        => $result['status'],
                    'note'      => $note,
                ],
            ], $this->pdo);

            Live::touchPayout((int) $request['user_id']);

            return [
                'ok'        => true,
                'action'    => $action,
                'payout_id' => $payoutId,
                'user_id'   => (int) $request['user_id'],
                'username'  => (string) $request['member_username'],
                'amount'    => $amount,
                'from'      => $status,
                'note'      => $note,
            ] + $result;
        });
    }

    /* ----------------------------------------------------------
     *  Reconciliation (bin/wallet_reconcile.php, P13-T12)
     * ---------------------------------------------------------- */

    /**
     * Check every request against its wallet legs.
     *
     * Two things must hold:
     *
     *   1. every request has exactly one `payout` debit of its amount —
     *      the hold was taken when the request was made (D17);
     *   2. a rejected request has exactly one `refund` credit of the same
     *      amount, and every other status has none.
     *
     * `refund` is shared with Phase 7's shop-order refunds, and the two
     * domains' ids overlap, so a payout refund is recognised by the note
     * the service writes (`Payout request #<id> rejected — …`) rather
     * than by `ref_id` alone. That is also why a payout refund can carry
     * `ref_id = NULL`: when the member already has an order refund under
     * the same numeric id, the ledger's (user_id, ref_type, ref_id, type)
     * key would otherwise refuse (or silently skip) the payout refund.
     *
     * `orphans` counts payout legs whose request row no longer exists
     * (deleting an account cascades its requests away, while the wallet
     * movement must stay in the ledger). Informational, not a failure —
     * the same rule the transfer reconciliation uses.
     *
     * Read-only; it never repairs.
     *
     * @return array{ok:bool, checked:int, orphans:int, held:float, mismatches:list<array<string, mixed>>}
     */
    public function reconcile(): array
    {
        $requests = $this->pdo->query(
            'SELECT id, user_id, amount, status FROM payout_requests ORDER BY id'
        )->fetchAll();

        $legs = $this->pdo->query(
            "SELECT ref_id, user_id, type, ref_type, amount, note
               FROM ewallet_ledger
              WHERE ref_type = 'payout'
                 OR (ref_type = 'refund' AND note LIKE 'Payout request #%')
              ORDER BY id"
        )->fetchAll();

        /** @var array<int, list<array<string, mixed>>> $byRequest payout id => legs */
        $byRequest = [];

        /* Orphan payout legs: a `payout` debit whose request vanished, or
         * a payout-named refund whose request vanished. */
        $orphans = 0;

        $knownIds = [];

        foreach ($requests as $request) {
            $knownIds[(int) $request['id']] = true;
        }

        foreach ($legs as $leg) {
            $payoutId = $this->legPayoutId($leg);

            if ($payoutId === null) {
                continue;
            }

            if (!isset($knownIds[$payoutId])) {
                $orphans++;
                continue;
            }

            $byRequest[$payoutId][] = $leg;
        }

        $mismatches = [];
        $held       = 0.0;

        foreach ($requests as $request) {
            $payoutId = (int) $request['id'];
            $userId   = (int) $request['user_id'];
            $amount   = round((float) $request['amount'], 2);
            $status   = (string) $request['status'];

            $hold = $this->sumLegs($byRequest[$payoutId] ?? [], 'payout', 'debit');
            $back = $this->sumLegs($byRequest[$payoutId] ?? [], 'refund', 'credit', $userId);

            if ($hold['rows'] !== 1 || $this->decimal($hold['total']) !== $this->decimal($amount)) {
                $mismatches[] = [
                    'payout_id' => $payoutId,
                    'kind'      => 'hold',
                    'message'   => sprintf(
                        'hold rows=%d total=%s, expected one debit of %s',
                        $hold['rows'],
                        $this->decimal($hold['total']),
                        $this->decimal($amount)
                    ),
                ];
            }

            if ($status === 'rejected') {
                if ($back['rows'] !== 1 || $this->decimal($back['total']) !== $this->decimal($amount)) {
                    $mismatches[] = [
                        'payout_id' => $payoutId,
                        'kind'      => 'refund',
                        'message'   => sprintf(
                            'refund rows=%d total=%s, expected one credit of %s',
                            $back['rows'],
                            $this->decimal($back['total']),
                            $this->decimal($amount)
                        ),
                    ];
                }
            } elseif ($back['rows'] !== 0) {
                $mismatches[] = [
                    'payout_id' => $payoutId,
                    'kind'      => 'unexpected_refund',
                    'message'   => sprintf('%d refund row(s) on a %s request', $back['rows'], $status),
                ];
            }

            if (in_array($status, Payout::OPEN_STATUSES, true)) {
                $held += $amount;
            }
        }

        return [
            'ok'         => $mismatches === [],
            'checked'    => count($requests),
            'orphans'    => $orphans,
            'held'       => round($held, 2),
            'mismatches' => $mismatches,
        ];
    }

    /**
     * The `ref_id` a payout refund can safely carry.
     *
     * Phase 7 writes order refunds under the same `refund` ref_type with
     * an order id. When that id is already taken for this member, the
     * payout refund is written unlinked (NULL) and named in its note, so
     * the ledger's unique key can never mistake one for the other — which
     * would otherwise make the payout refund look like a replay and skip
     * it, leaving the member's money held.
     */
    private function refundRefId(int $userId, int $payoutId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT note FROM ewallet_ledger
              WHERE user_id = ? AND ref_type = 'refund' AND ref_id = ? AND type = 'credit'
              LIMIT 1"
        );
        $stmt->execute([$userId, $payoutId]);
        $note = $stmt->fetchColumn();

        if ($note === false) {
            return $payoutId;
        }

        return str_starts_with((string) $note, 'Payout request #' . $payoutId . ' ')
            ? $payoutId
            : null;
    }

    /**
     * Which payout request a ledger leg belongs to.
     *
     * A hold carries the id in `ref_id`; a payout refund is recognised by
     * the note, because `refund` is shared with order refunds and may
     * carry `ref_id = NULL` when the id namespace collides.
     *
     * @param array<string, mixed> $leg
     */
    private function legPayoutId(array $leg): ?int
    {
        if ((string) $leg['ref_type'] === 'payout') {
            return $leg['ref_id'] === null ? null : (int) $leg['ref_id'];
        }

        if (preg_match('/^Payout request #(\d+) /', (string) ($leg['note'] ?? ''), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Count and total the legs of one request for one ref_type/type (and,
     * for a refund, the member the money went back to).
     *
     * @param list<array<string, mixed>> $legs
     * @return array{rows:int, total:float}
     */
    private function sumLegs(array $legs, string $refType, string $type, ?int $userId = null): array
    {
        $rows  = 0;
        $total = 0.0;

        foreach ($legs as $leg) {
            if ((string) $leg['ref_type'] !== $refType || (string) $leg['type'] !== $type) {
                continue;
            }

            if ($userId !== null && (int) $leg['user_id'] !== $userId) {
                continue;
            }

            $rows++;
            $total += (float) $leg['amount'];
        }

        return ['rows' => $rows, 'total' => round($total, 2)];
    }

    /* ----------------------------------------------------------
     *  Reporting
     * ---------------------------------------------------------- */

    /**
     * Lifetime payout figures for the queue's stat cards (plan §7
     * Phase 13 deliverables: `request()`, `approve()`, `reject()`,
     * `complete()`, `totals()`).
     *
     * The query lives in the read model; the service exposes it so a
     * controller never has to reach into `Payout` for money totals.
     *
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        return Payout::totals($this->pdo);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** The SQL status list a decision accepts. */
    private function statusListFor(string $action): string
    {
        return $action === 'approve' ? "'pending'" : "'pending','approved'";
    }

    /** @return array<string, mixed>|null */
    private function account(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, member_status, is_active,
                    ewallet_balance, withdrawable_balance,
                    gcash_number, maya_number, usdt_trc20_address, usdt_bep20_address
               FROM users
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function lockAccount(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, is_active, ewallet_balance, withdrawable_balance
               FROM users
              WHERE id = ?
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function lockRequest(int $payoutId): ?array
    {
        if ($payoutId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.user_id, p.amount, p.payout_method, p.payout_account,
                    p.service_fee_amount, p.status,
                    u.username AS member_username
               FROM payout_requests p
               JOIN users u ON u.id = p.user_id
              WHERE p.id = ?
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$payoutId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Validate a method name.
     *
     * @throws PayoutException
     */
    private function method(string $method): string
    {
        $method = strtolower(trim($method));

        if (!in_array($method, self::METHODS, true)) {
            throw PayoutException::unknownMethod($method === '' ? '(none)' : $method);
        }

        return $method;
    }

    /**
     * Validate the payout account for a channel.
     *
     * The formats are PaymentDetailsService's, so the profile form and
     * the payout form can never disagree about what a valid GCash number
     * or USDT address looks like.
     *
     * @throws PayoutException
     */
    private function payoutAccount(string $method, string $raw): string
    {
        $field = self::accountField($method);
        $label = self::label($method);
        $value = trim($raw);

        if ($field === '') {
            throw PayoutException::unknownMethod($method);
        }

        /* Strip the punctuation people naturally type in a phone number,
         * exactly like PaymentDetailsService does before validating. */
        if (!self::isUsdt($method)) {
            $value = (string) preg_replace('/[\s\-().]/', '', $value);
        }

        if ($value === '') {
            throw PayoutException::accountRequired($label, self::hint($method));
        }

        $probe         = array_fill_keys(PaymentDetailsService::FIELDS, '');
        $probe[$field] = $value;

        $errors = (new PaymentDetailsService($this->pdo))->validate($probe);

        if (isset($errors[$field])) {
            throw new PayoutException($errors[$field], 'account');
        }

        return mb_substr($value, 0, 120);
    }

    /** Normalise and validate an amount; strings must be 2-decimal money. */
    private function amount(float|int|string $amount): float
    {
        if (is_string($amount)) {
            $raw = trim($amount);

            if ($raw === '') {
                throw PayoutException::invalidAmount('Enter an amount.');
            }

            if (preg_match('/^\d+(\.\d+)?$/', $raw) !== 1) {
                throw PayoutException::invalidAmount('Enter a valid amount greater than zero.');
            }

            if (preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
                throw PayoutException::amountPrecision();
            }

            $amount = (float) $raw;
        }

        if (!is_finite((float) $amount)) {
            throw PayoutException::invalidAmount('Enter a valid amount.');
        }

        $value = round((float) $amount, 2);

        if ($value <= 0) {
            throw PayoutException::invalidAmount();
        }

        if ($value > self::MAX_AMOUNT) {
            throw PayoutException::amountTooLarge();
        }

        return $value;
    }

    private function note(string $note): string
    {
        $note = trim(preg_replace('/\s+/u', ' ', $note) ?? $note);

        return mb_substr($note, 0, self::NOTE_MAX);
    }

    /** Format a money value for a DECIMAL column (never float notation). */
    private function decimal(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * One decision, one transaction — or a savepoint when the caller
     * already owns one (the test harness).
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'payout_sp_' . (++self::$savepointSeq);
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();

            if ($owns) {
                $this->pdo->commit();
            } elseif ($savepoint !== null) {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $e) {
            if ($owns) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } elseif ($savepoint !== null) {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    /* The outer transaction is already unusable; let the
                     * original exception surface. */
                }
            }

            throw $e;
        }
    }
}
