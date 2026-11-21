<?php
declare(strict_types=1);

/* ==============================================================
 *  TransferService — member-to-member wallet transfers with a fee
 *  and daily/weekly limits (plan §5.8, §7 Phase 12; reference
 *  models/Ewallet.php::transfer(), MemberController::doEwalletTransfer,
 *  cron/fund_transfer_limit_reset.php)
 *
 *  The rules this class owns:
 *
 *    • the sender re-enters their password; a wrong password refuses
 *      the transfer before any row is locked or written;
 *    • the recipient must exist, be usable, and not be the sender;
 *    • amount > 0 and >= settings.ewallet_min_transfer;
 *    • members pay settings.ewallet_transfer_fee, admin/superadmin pay
 *      nothing and are exempt from both limits;
 *    • limit usage is tracked on the sender row
 *      (users.ewallet_sent_today / ewallet_sent_this_week) and checked
 *      inside the same lock that guards the balance;
 *    • one transaction writes: the transfer row, the sender debit
 *      (non-withdrawable first), the recipient credit (non-withdrawable),
 *      the admin fee credit (non-withdrawable), the counter increment
 *      and the audit entry. A refusal writes nothing at all.
 *
 *  Two deliberate interpretations of the plan's wording:
 *
 *    1. "Recipient must be active" means the account is enabled and
 *       not suspended (is_active = 1, member_status <> 'suspended').
 *       A pending or inactive member still owns a wallet — a sponsor
 *       may fund a recruit before activation — so only suspension and
 *       deactivation refuse a transfer.
 *    2. A limit setting of 0 (or a non-positive value) means
 *       *unlimited*: an operator who blanks the field must not lock
 *       every member out of sending money.
 *
 *  Superadmin attribution (reference `actingAdminId()`): the money comes
 *  out of the primary admin's wallet and the ledger records the primary
 *  admin as the sender. The superadmin's own password still authorises
 *  the request, and the audit row keeps both identities.
 *
 *  Idempotency: a transfer is user-initiated, so it is not collapsed
 *  like a system movement. The ledger's
 *  (user_id, ref_type, ref_id, type) unique key still protects each leg;
 *  the fee leg falls back to ref_id = NULL when the primary admin is
 *  also the recipient (two credits for one account and one transfer id
 *  would collide), with the transfer id carried in the note.
 * ============================================================== */

final class TransferService
{
    /** DECIMAL(12,2) upper bound for a single transfer (and its fee). */
    public const MAX_AMOUNT = 9999999999.99;

    /** Roles that transfer without a fee and without limits. */
    public const STAFF_ROLES = ['admin', 'superadmin'];

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

    /** settings.ewallet_min_transfer — the smallest allowed transfer. */
    public static function minTransfer(): float
    {
        return round(max(0.0, setting_float('ewallet_min_transfer', 50.0)), 2);
    }

    /** settings.ewallet_transfer_fee — what a member pays per transfer. */
    public static function transferFee(): float
    {
        return round(max(0.0, setting_float('ewallet_transfer_fee', 0.0)), 2);
    }

    /** settings.ewallet_transfer_daily_limit — 0 means unlimited. */
    public static function dailyLimit(): float
    {
        return round(max(0.0, setting_float('ewallet_transfer_daily_limit', 5000.0)), 2);
    }

    /** settings.ewallet_transfer_weekly_limit — 0 means unlimited. */
    public static function weeklyLimit(): float
    {
        return round(max(0.0, setting_float('ewallet_transfer_weekly_limit', 20000.0)), 2);
    }

    /** Is this role free of fees and limits? */
    public static function isStaffRole(string $role): bool
    {
        return in_array($role, self::STAFF_ROLES, true);
    }

    /** The fee this role pays for one transfer. */
    public static function feeForRole(string $role): float
    {
        return self::isStaffRole($role) ? 0.0 : self::transferFee();
    }

    /** Is today (in the app time zone) a Monday? */
    public static function isMonday(?int $timestamp = null): bool
    {
        return date('N', $timestamp ?? time()) === '1';
    }

    /* ----------------------------------------------------------
     *  Limit usage
     * ---------------------------------------------------------- */

    /**
     * Both counters, both limits and what they leave available.
     *
     * @return array{
     *   user_id:int, username:string, role:string, staff:bool,
     *   fee:float, min:float, max_send:float,
     *   balance:float, withdrawable:float, non_withdrawable:float,
     *   sent_today:float, sent_this_week:float,
     *   daily_limit:float, weekly_limit:float,
     *   daily_remaining:?float, weekly_remaining:?float,
     *   daily_percent:float, weekly_percent:float,
     *   exempt:bool
     * }
     *
     * @throws TransferException when the account does not exist
     */
    public function limits(int $userId): array
    {
        $account = $this->account($userId);

        if ($account === null) {
            throw TransferException::userMissing();
        }

        return $this->usageFor($account);
    }

    /**
     * The same shape as limits(), derived from an already-loaded row.
     *
     * Pure: no query, so a page that already has the account row (the
     * member's own transfer form, the admin monitor) never pays for a
     * second read.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function usageFor(array $account): array
    {
        $role  = (string) ($account['role'] ?? 'member');
        $staff = self::isStaffRole($role);
        $fee   = $staff ? 0.0 : self::transferFee();

        $balance     = round((float) ($account['ewallet_balance'] ?? 0), 2);
        $withdrawable = round((float) ($account['withdrawable_balance'] ?? 0), 2);

        $sentToday = round((float) ($account['ewallet_sent_today'] ?? 0), 2);
        $sentWeek  = round((float) ($account['ewallet_sent_this_week'] ?? 0), 2);

        $daily  = self::dailyLimit();
        $weekly = self::weeklyLimit();

        /* The largest amount the balance can carry: the total debit is
         * amount + fee, so the amount itself is capped one fee lower. */
        $maxSend = round(max(0.0, $balance - $fee), 2);

        $dailyRemaining  = $staff || $daily <= 0 ? null : round(max(0.0, $daily - $sentToday), 2);
        $weeklyRemaining = $staff || $weekly <= 0 ? null : round(max(0.0, $weekly - $sentWeek), 2);

        return [
            'user_id'           => (int) ($account['id'] ?? 0),
            'username'          => (string) ($account['username'] ?? ''),
            'role'              => $role,
            'staff'             => $staff,
            'exempt'            => $staff,
            'fee'               => $fee,
            'min'               => self::minTransfer(),
            'max_send'          => $maxSend,
            'balance'           => $balance,
            'withdrawable'      => $withdrawable,
            'non_withdrawable'  => round($balance - $withdrawable, 2),
            'sent_today'        => $sentToday,
            'sent_this_week'    => $sentWeek,
            'daily_limit'       => $daily,
            'weekly_limit'      => $weekly,
            'daily_remaining'   => $dailyRemaining,
            'weekly_remaining'  => $weeklyRemaining,
            'daily_percent'     => $this->percent($sentToday, $daily),
            'weekly_percent'    => $this->percent($sentWeek, $weekly),
        ];
    }

    /* ----------------------------------------------------------
     *  Send
     * ---------------------------------------------------------- */

    /**
     * Send money from one wallet to another.
     *
     * @param float|int|string $amount what the recipient receives
     * @return array<string, mixed> the completed transfer
     *
     * @throws TransferException when a rule refuses the transfer
     */
    public function send(
        int $senderId,
        string $recipientUsername,
        float|int|string $amount,
        string $note = '',
        string $password = ''
    ): array {
        $amount = $this->amount($amount);
        $note   = $this->note($note);

        $actor = $this->account($senderId);

        if ($actor === null) {
            throw TransferException::userMissing();
        }

        /* 1. Password confirmation — before any lock, so a wrong password
         *    never touches a row (plan §5.8). */
        if ($password === '') {
            throw TransferException::passwordRequired();
        }

        if (!password_verify($password, (string) $actor['password_hash'])) {
            throw TransferException::password();
        }

        if ((int) $actor['is_active'] !== 1) {
            throw TransferException::userMissing();
        }

        /* 2. Which wallet pays? A superadmin acts for the primary admin
         *    (reference `actingAdminId()`). */
        $role            = (string) $actor['role'];
        $staff           = self::isStaffRole($role);
        $walletOwnerId   = $senderId;
        $primaryAdmin    = Member::primaryAdmin($this->pdo);
        $primaryAdminId  = $primaryAdmin === null ? null : (int) $primaryAdmin['id'];

        if ($role === 'superadmin' && $primaryAdminId !== null) {
            $walletOwnerId = $primaryAdminId;
        }

        /* 3. Amount floor and ceiling. */
        $minimum = self::minTransfer();

        if ($minimum > 0 && $amount < $minimum) {
            throw TransferException::belowMinimum($minimum, $amount);
        }

        $fee        = $staff ? 0.0 : self::transferFee();
        $totalDebit = round($amount + $fee, 2);

        if ($totalDebit > self::MAX_AMOUNT) {
            throw TransferException::amountTooLarge();
        }

        /* A fee must land somewhere: refuse rather than destroy it. */
        if ($fee > 0 && $primaryAdminId === null) {
            throw TransferException::feeAccountMissing();
        }

        /* 4. Recipient. */
        $username = trim($recipientUsername);

        if ($username === '') {
            throw TransferException::recipientRequired();
        }

        $recipient = Member::findByUsername($username, $this->pdo);

        if ($recipient === null) {
            throw TransferException::recipientMissing($username);
        }

        $recipientId = (int) $recipient['id'];

        if ($recipientId === $senderId || $recipientId === $walletOwnerId) {
            throw TransferException::selfTransfer();
        }

        if (!$this->canReceive($recipient)) {
            throw TransferException::recipientInactive($username);
        }

        /* 5. One transaction, both rows locked in id order (two members
         *    paying each other at the same moment cannot deadlock). */
        return $this->withTransaction(function () use (
            $actor,
            $senderId,
            $walletOwnerId,
            $recipientId,
            $username,
            $amount,
            $fee,
            $totalDebit,
            $note,
            $staff,
            $primaryAdminId
        ): array {
            $locked = $this->lockAccounts([$walletOwnerId, $recipientId]);

            $owner = $locked[$walletOwnerId] ?? null;

            if ($owner === null) {
                throw TransferException::userMissing();
            }

            $balance = round((float) $owner['ewallet_balance'], 2);

            if ($balance < $totalDebit) {
                throw TransferException::insufficient($totalDebit, $balance);
            }

            /* 6. Limits re-checked against the locked row: the pre-check
             *    spent no lock, so this is the authoritative one. */
            if (!$staff) {
                $sentToday = round((float) $owner['ewallet_sent_today'], 2);
                $sentWeek  = round((float) $owner['ewallet_sent_this_week'], 2);

                $daily  = self::dailyLimit();
                $weekly = self::weeklyLimit();

                if ($daily > 0 && round($sentToday + $amount, 2) > $daily) {
                    throw TransferException::dailyLimit($daily, $sentToday, $amount);
                }

                if ($weekly > 0 && round($sentWeek + $amount, 2) > $weekly) {
                    throw TransferException::weeklyLimit($weekly, $sentWeek, $amount);
                }
            }

            /* 7. The transfer row first: the three ledger rows point at it. */
            $this->pdo->prepare(
                'INSERT INTO ewallet_transfers
                   (sender_id, recipient_id, amount, fee, net_amount, note, status)
                 VALUES (?, ?, ?, ?, ?, ?, \'completed\')'
            )->execute([
                $walletOwnerId,
                $recipientId,
                $this->decimal($amount),
                $this->decimal($fee),
                $this->decimal($amount),
                $note === '' ? null : $note,
            ]);

            $transferId = (int) $this->pdo->lastInsertId();

            /* 8. The three legs. Sender spends non-withdrawable money
             *    first; the recipient and the fee are non-withdrawable. */
            $senderUsername = (string) $owner['username'];
            $noteSuffix     = $note === '' ? '' : ' — ' . $note;

            $debit = $this->wallet->debitInternal(
                $walletOwnerId,
                $totalDebit,
                'transfer',
                $transferId,
                'Transfer to @' . $username . $noteSuffix
            );

            $this->wallet->credit(
                $recipientId,
                $amount,
                'transfer',
                $transferId,
                'Transfer from @' . $senderUsername . $noteSuffix,
                false
            );

            if ($fee > 0 && $primaryAdminId !== null) {
                $this->creditFee(
                    $fee,
                    $transferId,
                    $senderUsername,
                    $username,
                    $recipientId,
                    $primaryAdminId
                );
            }

            /* 9. Limit usage travels with the money, inside the lock. */
            if (!$staff) {
                $this->pdo->prepare(
                    'UPDATE users
                        SET ewallet_sent_today = ewallet_sent_today + ?,
                            ewallet_sent_this_week = ewallet_sent_this_week + ?
                      WHERE id = ?'
                )->execute([$this->decimal($amount), $this->decimal($amount), $walletOwnerId]);
            }

            /* 10. Audit: the signed-in actor, even when the money came
             *     from the primary admin's wallet. */
            AuditLog::record([
                'action'       => 'wallet.transfer',
                'actor_id'     => $senderId,
                'actor_role'   => (string) $actor['role'],
                'subject_type' => 'user',
                'subject_id'   => $recipientId,
                'meta'         => [
                    'transfer_id'   => $transferId,
                    'sender_id'     => $walletOwnerId,
                    'actor_id'      => $senderId,
                    'recipient_id'  => $recipientId,
                    'amount'        => $this->decimal($amount),
                    'fee'           => $this->decimal($fee),
                    'total_debit'   => $this->decimal($totalDebit),
                    'note'          => $note,
                ],
            ], $this->pdo);

            /* Both members' transfer pages carry their recent list and
             * limit usage. The wallet surfaces are bumped by WalletService
             * with each movement. */
            Live::touchTransfer($senderId);

            if ($recipientId !== $senderId) {
                Live::touchTransfer($recipientId);
            }

            return [
                'ok'                 => true,
                'transfer_id'        => $transferId,
                'actor_id'           => $senderId,
                'sender_id'          => $walletOwnerId,
                'sender_username'    => $senderUsername,
                'recipient_id'       => $recipientId,
                'recipient_username' => $username,
                'amount'             => $amount,
                'fee'                => $fee,
                'net_amount'         => $amount,
                'total_debit'        => $totalDebit,
                'note'               => $note,
                'staff'              => $staff,
                'balance_after'      => round((float) $debit['balance_after'], 2),
                'withdrawable_after' => round((float) $debit['withdrawable_after'], 2),
            ];
        });
    }

    /**
     * Credit the transfer fee to the primary admin.
     *
     * Two credits for one account under one (ref_type, ref_id) would
     * collide with the ledger's idempotency key, which can only happen
     * when the primary admin is also the recipient. In that case the
     * fee row keeps ref_id = NULL and names the transfer in its note.
     */
    private function creditFee(
        float $fee,
        int $transferId,
        string $senderUsername,
        string $recipientUsername,
        int $recipientId,
        int $adminId
    ): void {
        $note = sprintf(
            'Transfer fee from @%s to @%s (#%d)',
            $senderUsername,
            $recipientUsername,
            $transferId
        );

        $this->wallet->credit(
            $adminId,
            $fee,
            'transfer',
            $adminId === $recipientId ? null : $transferId,
            $note,
            false
        );
    }

    /* ----------------------------------------------------------
     *  Limit reset (bin/reset_transfer_limits.php)
     * ---------------------------------------------------------- */

    /**
     * How many accounts currently carry a non-zero counter.
     *
     * Used by the reset job's `--dry-run` and `--doctor` so a preview
     * reads exactly the same rows the real run would update.
     *
     * @return array{daily:int, weekly:int, accounts:int}
     */
    public function pendingResets(): array
    {
        $row = $this->pdo->query(
            'SELECT COUNT(CASE WHEN ewallet_sent_today <> 0.00 THEN 1 END) AS daily,
                    COUNT(CASE WHEN ewallet_sent_this_week <> 0.00 THEN 1 END) AS weekly,
                    COUNT(*) AS accounts
               FROM users'
        )->fetch() ?: ['daily' => 0, 'weekly' => 0, 'accounts' => 0];

        return [
            'daily'    => (int) $row['daily'],
            'weekly'   => (int) $row['weekly'],
            'accounts' => (int) $row['accounts'],
        ];
    }

    /**
     * Zero the transfer counters.
     *
     * Daily counters always; weekly counters only when the caller says
     * so (the CLI passes "today is Monday"). Idempotent: a second run
     * zeroes nothing and reports zero rows. No audit row — the run is
     * recorded in the cron log instead, so a daily job does not grow the
     * audit table forever.
     *
     * @return array{
     *   ok:bool, weekly_reset:bool, is_monday:bool,
     *   daily_zeroed:int, weekly_zeroed:int, checked:int
     * }
     */
    public function resetLimits(bool $includeWeekly = false): array
    {
        $checked = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $daily = $this->pdo->prepare('UPDATE users SET ewallet_sent_today = 0.00 WHERE ewallet_sent_today <> 0.00');
        $daily->execute();

        $weeklyZeroed = 0;

        if ($includeWeekly) {
            $weekly = $this->pdo->prepare(
                'UPDATE users SET ewallet_sent_this_week = 0.00 WHERE ewallet_sent_this_week <> 0.00'
            );
            $weekly->execute();
            $weeklyZeroed = $weekly->rowCount();
        }

        return [
            'ok'            => true,
            'weekly_reset'  => $includeWeekly,
            'is_monday'     => $includeWeekly,
            'daily_zeroed'  => $daily->rowCount(),
            'weekly_zeroed' => $weeklyZeroed,
            'checked'       => $checked,
        ];
    }

    /* ----------------------------------------------------------
     *  Reconciliation (bin/wallet_reconcile.php)
     * ---------------------------------------------------------- */

    /**
     * Check every transfer against its ledger legs.
     *
     * Three things must hold per completed transfer:
     *
     *   1. the sender has exactly one debit of amount + fee;
     *   2. the recipient has exactly one credit of amount;
     *   3. the fee (when there is one) was credited to the primary admin
     *      — either as a third leg on the transfer id, or (only when the
     *      primary admin is also the recipient) as a `ref_id = NULL` row
     *      that names the transfer in its note.
     *
     * Plus one informational figure: ledger legs whose transfer row no
     * longer exists (`orphans`). That is *not* a failure: deleting an
     * account cascades its transfer rows away while the counterparty's
     * and the admin's real money movements must stay in the ledger, and
     * the wallet reconciliation still proves those balances right. The
     * back office wants to see the number, not be blocked by it.
     *
     * Read-only; it never repairs.
     *
     * @return array{ok:bool, checked:int, orphans:int, mismatches:list<array<string, mixed>>}
     */
    public function reconcile(): array
    {
        $rows = $this->pdo->query(
            "SELECT t.id, t.sender_id, t.recipient_id, t.amount, t.fee, t.status,
                    COUNT(CASE WHEN l.type = 'debit'  AND l.user_id = t.sender_id    THEN 1 END) AS debit_rows,
                    COALESCE(SUM(CASE WHEN l.type = 'debit'  AND l.user_id = t.sender_id    THEN l.amount END), 0) AS debit_total,
                    COUNT(CASE WHEN l.type = 'credit' AND l.user_id = t.recipient_id THEN 1 END) AS credit_rows,
                    COALESCE(SUM(CASE WHEN l.type = 'credit' AND l.user_id = t.recipient_id THEN l.amount END), 0) AS credit_total,
                    COALESCE(SUM(CASE WHEN l.type = 'credit' THEN l.amount END), 0) AS all_credit_total
               FROM ewallet_transfers t
               LEFT JOIN ewallet_ledger l ON l.ref_type = 'transfer' AND l.ref_id = t.id
              GROUP BY t.id
              ORDER BY t.id"
        )->fetchAll();

        $mismatches = [];
        $checked    = 0;

        foreach ($rows as $row) {
            $checked++;

            $transferId = (int) $row['id'];
            $amount     = round((float) $row['amount'], 2);
            $fee        = round((float) $row['fee'], 2);
            $expected   = round($amount + $fee, 2);

            if ((string) $row['status'] !== 'completed') {
                continue;
            }

            if ((int) $row['debit_rows'] !== 1 || $this->decimal((float) $row['debit_total']) !== $this->decimal($expected)) {
                $mismatches[] = [
                    'transfer_id' => $transferId,
                    'kind'        => 'sender_debit',
                    'message'     => sprintf(
                        'sender debit rows=%d total=%s, expected one row of %s',
                        (int) $row['debit_rows'],
                        $this->decimal((float) $row['debit_total']),
                        $this->decimal($expected)
                    ),
                ];
            }

            if ((int) $row['credit_rows'] !== 1 || $this->decimal((float) $row['credit_total']) !== $this->decimal($amount)) {
                $mismatches[] = [
                    'transfer_id' => $transferId,
                    'kind'        => 'recipient_credit',
                    'message'     => sprintf(
                        'recipient credit rows=%d total=%s, expected one row of %s',
                        (int) $row['credit_rows'],
                        $this->decimal((float) $row['credit_total']),
                        $this->decimal($amount)
                    ),
                ];
            }

            if ($fee > 0 && $this->decimal((float) $row['all_credit_total']) !== $this->decimal($expected)) {
                /* The fee is only unlinked when the primary admin is the
                 * recipient; accept a NULL-ref fee row that names us. */
                if (!$this->hasUnlinkedFee($transferId, $fee)) {
                    $mismatches[] = [
                        'transfer_id' => $transferId,
                        'kind'        => 'fee_credit',
                        'message'     => sprintf(
                            'fee credit missing: credits on the transfer total %s, expected %s',
                            $this->decimal((float) $row['all_credit_total']),
                            $this->decimal($expected)
                        ),
                    ];
                }
            }
        }

        $orphans = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ewallet_ledger l
              WHERE l.ref_type = 'transfer'
                AND l.ref_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM ewallet_transfers t WHERE t.id = l.ref_id)"
        )->fetchColumn();

        return [
            'ok'         => $mismatches === [],
            'checked'    => $checked,
            'orphans'    => $orphans,
            'mismatches' => $mismatches,
        ];
    }

    /** Is there a `ref_id = NULL` fee row naming this transfer? */
    private function hasUnlinkedFee(int $transferId, float $fee): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger
              WHERE ref_type = 'transfer'
                AND ref_id IS NULL
                AND type = 'credit'
                AND note LIKE ?"
        );
        $stmt->execute(['%#' . $transferId . ')%']);

        return $this->decimal((float) $stmt->fetchColumn()) === $this->decimal($fee);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    private function account(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, member_status, is_active,
                    ewallet_balance, withdrawable_balance,
                    ewallet_sent_today, ewallet_sent_this_week,
                    password_hash
               FROM users
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Lock the two accounts in ascending id order.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> id => locked row
     */
    private function lockAccounts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (int $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        )));

        sort($ids);

        $locked = [];

        foreach ($ids as $id) {
            $stmt = $this->pdo->prepare(
                'SELECT id, username, role, member_status, is_active,
                        ewallet_balance, withdrawable_balance,
                        ewallet_sent_today, ewallet_sent_this_week
                   FROM users
                  WHERE id = ?
                  LIMIT 1
                  FOR UPDATE'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if ($row !== false) {
                $locked[$id] = $row;
            }
        }

        return $locked;
    }

    /** @param array<string, mixed> $recipient */
    private function canReceive(array $recipient): bool
    {
        if ((int) ($recipient['is_active'] ?? 0) !== 1) {
            return false;
        }

        return (string) ($recipient['member_status'] ?? '') !== 'suspended';
    }

    private function percent(float $sent, float $limit): float
    {
        if ($limit <= 0) {
            return 0.0;
        }

        return round(min(100.0, max(0.0, ($sent / $limit) * 100)), 1);
    }

    /** Normalise and validate an amount; strings must be 2-decimal money. */
    private function amount(float|int|string $amount): float
    {
        if (is_string($amount)) {
            $raw = trim($amount);

            if ($raw === '') {
                throw TransferException::invalidAmount('Enter an amount.');
            }

            if (preg_match('/^\d+(\.\d+)?$/', $raw) !== 1) {
                throw TransferException::invalidAmount('Enter a valid amount greater than zero.');
            }

            if (preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
                throw TransferException::invalidAmount('Amount may have at most two decimal places.');
            }

            $amount = (float) $raw;
        }

        if (!is_finite((float) $amount)) {
            throw TransferException::invalidAmount('Enter a valid amount.');
        }

        $value = round((float) $amount, 2);

        if ($value <= 0) {
            throw TransferException::invalidAmount();
        }

        if ($value > self::MAX_AMOUNT) {
            throw TransferException::amountTooLarge();
        }

        return $value;
    }

    private function note(string $note): string
    {
        $note = trim(preg_replace('/\s+/u', ' ', $note) ?? $note);

        return mb_substr($note, 0, 255);
    }

    /** Format a money value for a DECIMAL column (never float notation). */
    private function decimal(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * One transfer, one transaction — or a savepoint when the caller
     * already owns one (the checkout-style callers and the test harness).
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'transfer_sp_' . (++self::$savepointSeq);
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
