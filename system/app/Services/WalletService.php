<?php
declare(strict_types=1);

/* ==============================================================
 *  WalletService — every money movement in one auditable place
 *  (plan §4.4, §5.8, §7 Phase 5; reference models/Ewallet.php)
 *
 *  Two buckets, one source of truth:
 *
 *    ewallet_balance       total spendable money
 *    withdrawable_balance  the subset that may be paid out
 *
 *  credit()          adds to the total; adds to withdrawable only
 *                    when the movement is earnings (default).
 *  refund()          returns spent money; the withdrawable portion is
 *                    restored only as far as it was originally spent
 *                    (Phase 7 order refunds).
 *  debit()           removes from both buckets; refuses unless the
 *                    withdrawable bucket covers the amount (payout
 *                    holds, Phase 13).
 *  debitInternal()   spends non-withdrawable money first, then
 *                    withdrawable (purchases, registrations, fees).
 *
 *  Invariants this class owns (plan §4.3, §4.4):
 *
 *    • ewallet_balance >= withdrawable_balance >= 0, always
 *    • every mutation locks the account row (SELECT … FOR UPDATE)
 *      and writes one immutable `ewallet_ledger` row carrying
 *      `balance_after` and `withdrawable_after`
 *    • a failed mutation writes nothing (savepoint when the caller
 *      already owns a transaction)
 *    • system-driven movements are idempotent by
 *      (user_id, ref_type, ref_id, type); a replay returns the
 *      original movement instead of moving money twice
 *    • admin top-ups are always a new row (audit by design)
 *
 *  There is no update/delete path for a ledger row: corrections are
 *  new `refund` / `adjustment` movements.
 * ============================================================== */

final class WalletService
{
    /**
     * Every value `ewallet_ledger.ref_type` accepts (plan §4.4).
     *
     * SINGLE SOURCE OF TRUTH. It must stay identical to the ENUM in
     * database/schema.sql and database/migrations/005_wallets.sql.
     * P5-T8 fails when a value is accepted here but missing there.
     */
    public const REF_TYPES = [
        'commission',
        'payout',
        'transfer',
        'topup',
        'registration',
        'shop_order',
        'adjustment',
        'refund',
    ];

    /** DECIMAL(12,2) upper bound. */
    public const MAX_AMOUNT = 9999999999.99;

    /** Savepoint names must be unique when calls nest. */
    private static int $savepointSeq = 0;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** Total spendable balance — reads the database, never a cache. */
    public function balance(int $userId): float
    {
        $stmt = $this->pdo->prepare('SELECT ewallet_balance FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0.0 : round((float) $value, 2);
    }

    /** The part of the balance that may be paid out. */
    public function withdrawable(int $userId): float
    {
        $stmt = $this->pdo->prepare('SELECT withdrawable_balance FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0.0 : round((float) $value, 2);
    }

    /** @return array<string, mixed>|null Both buckets plus the account row. */
    public function account(int $userId): ?array
    {
        return Wallet::account($userId, $this->pdo);
    }

    /**
     * One page of one member's statement with its filtered totals
     * (plan §7 Phase 5: statement filters + running balance).
     *
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   pager: Paginator,
     *   totals: array{rows:int, credits:float, debits:float, net:float}
     * }
     */
    public function statement(int $userId, array $filters = [], int $page = 1, int $perPage = Paginator::DEFAULT_PER_PAGE): array
    {
        $filters = Wallet::normaliseFilters($filters);
        $pager   = Paginator::make(Wallet::ledgerCount($userId, $filters, $this->pdo), $page, $perPage);

        return [
            'rows'   => Wallet::ledger($userId, $filters, $pager, $this->pdo),
            'pager'  => $pager,
            'totals' => Wallet::ledgerTotals($userId, $filters, $this->pdo),
        ];
    }

    /* ----------------------------------------------------------
     *  Money movements
     * ---------------------------------------------------------- */

    /**
     * Credit a wallet and write the ledger row.
     *
     * @param float|int|string $amount
     * @param bool $withdrawable true for earnings (also raises the
     *                           withdrawable bucket), false for money
     *                           that may only be spent (top-ups)
     * @return array<string, mixed> movement payload
     */
    public function credit(
        int $userId,
        float|int|string $amount,
        string $refType,
        ?int $refId = null,
        string $note = '',
        bool $withdrawable = true
    ): array {
        $amount = $this->amount($amount);
        $note   = $this->note($note);

        return $this->withTransaction(
            fn (): array => $this->apply('credit', $userId, $amount, $refType, $refId, $note, $withdrawable)
        );
    }

    /**
     * Return spent money to the wallet (order refund, Phase 7).
     *
     * A refund restores the purchase's original split: the caller passes
     * how much of the amount came out of the withdrawable bucket (derived
     * from the original debit's ledger snapshots), and only that portion
     * raises the withdrawable bucket again. Without it a top-up purchase
     * could be washed into withdrawable money by buying and cancelling.
     *
     * @param float $withdrawablePortion 0 … amount
     * @return array<string, mixed> movement payload
     */
    public function refund(
        int $userId,
        float|int|string $amount,
        ?int $refId = null,
        string $note = '',
        float $withdrawablePortion = 0.0
    ): array {
        $amount  = $this->amount($amount);
        $note    = $this->note($note);
        $portion = round(max(0.0, min($amount, $withdrawablePortion)), 2);

        return $this->withTransaction(
            fn (): array => $this->apply('credit', $userId, $amount, 'refund', $refId, $note, true, false, $portion)
        );
    }

    /**
     * Debit both buckets (payout hold). Refuses when the total cannot
     * cover the amount, and when the withdrawable bucket cannot.
     *
     * @return array<string, mixed> movement payload
     */
    public function debit(
        int $userId,
        float|int|string $amount,
        string $refType,
        ?int $refId = null,
        string $note = '',
        bool $withdrawable = true
    ): array {
        $amount = $this->amount($amount);
        $note   = $this->note($note);

        return $this->withTransaction(
            fn (): array => $this->apply('debit', $userId, $amount, $refType, $refId, $note, $withdrawable)
        );
    }

    /**
     * Internal debit (purchases, registrations, transfer fees): spends
     * non-withdrawable money first, then withdrawable money, so earnings
     * stay withdrawable while wallet-funded spending still works.
     *
     * @return array<string, mixed> movement payload
     */
    public function debitInternal(
        int $userId,
        float|int|string $amount,
        string $refType,
        ?int $refId = null,
        string $note = ''
    ): array {
        $amount = $this->amount($amount);
        $note   = $this->note($note);

        return $this->withTransaction(
            fn (): array => $this->apply('debit', $userId, $amount, $refType, $refId, $note, false, true)
        );
    }

    /**
     * Admin-created credit (plan §5.8): credits the NON-withdrawable
     * bucket, records `ewallet_admin_topups`, writes the ledger row and
     * an audit entry — all in one transaction.
     *
     * @return array<string, mixed>
     */
    public function adminTopUp(int $adminId, int $userId, float|int|string $amount, string $note = ''): array
    {
        $amount = $this->amount($amount);
        $note   = $this->note($note);

        return $this->withTransaction(function () use ($adminId, $userId, $amount, $note): array {
            $admin = $this->account($adminId);

            if ($admin === null) {
                throw WalletException::userMissing();
            }

            if (!in_array((string) $admin['role'], ['admin', 'superadmin'], true)) {
                throw WalletException::forbidden();
            }

            $recipient = $this->account($userId);

            if ($recipient === null) {
                throw WalletException::userMissing();
            }

            if ($userId === $adminId) {
                /* Allowed (an admin may fund their own shopping), but the
                 * audit row records it like any other credit. */
                error_log(sprintf('[ecowealth] admin #%d topped up their own wallet', $adminId));
            }

            $this->pdo->prepare(
                'INSERT INTO ewallet_admin_topups (admin_id, recipient_id, amount, note) VALUES (?, ?, ?, ?)'
            )->execute([$adminId, $userId, $this->decimal($amount), $note === '' ? null : $note]);

            $topupId = (int) $this->pdo->lastInsertId();

            $movement = $this->apply(
                'credit',
                $userId,
                $amount,
                'topup',
                $topupId,
                'Admin top-up by @' . (string) $admin['username'] . ($note === '' ? '' : ' — ' . $note),
                false
            );

            AuditLog::record([
                'action'       => 'wallet.topup',
                'actor_id'     => $adminId,
                'actor_role'   => (string) $admin['role'],
                'subject_type' => 'user',
                'subject_id'   => $userId,
                'meta'         => [
                    'topup_id' => $topupId,
                    'amount'   => $this->decimal($amount),
                    'note'     => $note,
                ],
            ], $this->pdo);

            return $movement + [
                'topup_id'        => $topupId,
                'admin_id'        => $adminId,
                'recipient_id'    => $userId,
                'recipient_name'  => (string) $recipient['username'],
            ];
        });
    }

    /* ----------------------------------------------------------
     *  Reconciliation
     * ---------------------------------------------------------- */

    /**
     * Recompute both buckets from the ledger and report mismatches
     * (plan §7 Phase 5; used by bin/wallet_reconcile.php, the tests and
     * the Phase 15 health widget).
     *
     * Three checks per account:
     *   1. SUM(credits) − SUM(debits) equals `ewallet_balance`
     *   2. the newest row's `balance_after` equals `ewallet_balance`
     *   3. the newest row's `withdrawable_after` equals
     *      `withdrawable_balance`
     * plus the two-bucket invariant itself.
     *
     * Read-only: it never repairs. A mismatch means someone wrote a
     * balance outside WalletService (or a ledger row was tampered with).
     *
     * @return array{
     *   ok: bool,
     *   checked: int,
     *   ledger_users: int,
     *   mismatches: list<array<string, mixed>>,
     *   violations: list<array<string, mixed>>
     * }
     */
    public function reconcile(): array
    {
        $users = $this->pdo->query(
            'SELECT id, username, ewallet_balance, withdrawable_balance FROM users ORDER BY id'
        )->fetchAll();

        /** @var array<int, float> $sums */
        $sums = [];
        foreach ($this->pdo->query(
            "SELECT user_id, SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) AS ledger_total
               FROM ewallet_ledger
              GROUP BY user_id"
        ) as $row) {
            $sums[(int) $row['user_id']] = round((float) $row['ledger_total'], 2);
        }

        /** @var array<int, array<string, mixed>> $latest */
        $latest = [];
        foreach ($this->pdo->query(
            'SELECT l.user_id, l.balance_after, l.withdrawable_after
               FROM ewallet_ledger l
               JOIN (SELECT user_id, MAX(id) AS max_id FROM ewallet_ledger GROUP BY user_id) m
                 ON m.max_id = l.id'
        ) as $row) {
            $latest[(int) $row['user_id']] = $row;
        }

        $mismatches = [];
        $violations = [];
        $seen       = [];

        foreach ($users as $user) {
            $id       = (int) $user['id'];
            $seen[]   = $id;
            $username = (string) $user['username'];
            $balance  = round((float) $user['ewallet_balance'], 2);
            $withdraw = round((float) $user['withdrawable_balance'], 2);

            if ($balance < 0 || $withdraw < 0 || $withdraw > $balance) {
                $violations[] = [
                    'user_id'      => $id,
                    'username'     => $username,
                    'balance'      => $balance,
                    'withdrawable' => $withdraw,
                    'reason'       => 'ewallet_balance >= withdrawable_balance >= 0 is broken',
                ];
            }

            if (!array_key_exists($id, $sums)) {
                if ($this->decimal($balance) !== '0.00' || $this->decimal($withdraw) !== '0.00') {
                    $mismatches[] = [
                        'user_id'      => $id,
                        'username'     => $username,
                        'kind'         => 'missing_ledger',
                        'expected'     => 0.0,
                        'actual'       => $balance,
                        'expected_withdrawable' => 0.0,
                        'actual_withdrawable'   => $withdraw,
                    ];
                }

                continue;
            }

            if ($this->decimal($sums[$id]) !== $this->decimal($balance)) {
                $mismatches[] = [
                    'user_id'      => $id,
                    'username'     => $username,
                    'kind'         => 'balance_sum',
                    'expected'     => $sums[$id],
                    'actual'       => $balance,
                    'expected_withdrawable' => null,
                    'actual_withdrawable'   => $withdraw,
                ];
            }

            if (isset($latest[$id])) {
                $snapshotBalance = round((float) $latest[$id]['balance_after'], 2);
                if ($this->decimal($snapshotBalance) !== $this->decimal($balance)) {
                    $mismatches[] = [
                        'user_id'      => $id,
                        'username'     => $username,
                        'kind'         => 'balance_snapshot',
                        'expected'     => $snapshotBalance,
                        'actual'       => $balance,
                        'expected_withdrawable' => null,
                        'actual_withdrawable'   => $withdraw,
                    ];
                }

                $snapshotWithdrawable = round((float) $latest[$id]['withdrawable_after'], 2);
                if ($this->decimal($snapshotWithdrawable) !== $this->decimal($withdraw)) {
                    $mismatches[] = [
                        'user_id'      => $id,
                        'username'     => $username,
                        'kind'         => 'withdrawable_snapshot',
                        'expected'     => null,
                        'actual'       => $balance,
                        'expected_withdrawable' => $snapshotWithdrawable,
                        'actual_withdrawable'   => $withdraw,
                    ];
                }
            }
        }

        /* A ledger row whose account vanished would be an FK violation; the
         * check is cheap insurance against a future schema change. */
        foreach (array_keys($sums) as $userId) {
            if (!in_array($userId, $seen, true)) {
                $mismatches[] = [
                    'user_id'      => $userId,
                    'username'     => '(missing account)',
                    'kind'         => 'orphan_ledger',
                    'expected'     => $sums[$userId],
                    'actual'       => null,
                    'expected_withdrawable' => null,
                    'actual_withdrawable'   => null,
                ];
            }
        }

        return [
            'ok'           => $mismatches === [] && $violations === [],
            'checked'      => count($users),
            'ledger_users' => count($sums),
            'mismatches'   => $mismatches,
            'violations'   => $violations,
        ];
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * Apply one movement inside the caller's transaction.
     *
     * @return array<string, mixed>
     */
    private function apply(
        string $type,
        int $userId,
        float $amount,
        string $refType,
        ?int $refId,
        string $note,
        bool $withdrawable,
        bool $spendNonWithdrawableFirst = false,
        ?float $withdrawablePortion = null
    ): array {
        $this->assertRefType($refType);

        if ($userId <= 0) {
            throw WalletException::userMissing();
        }

        $refId = ($refId !== null && $refId > 0) ? $refId : null;

        $locked = $this->lockUser($userId);

        if ($locked === null) {
            throw WalletException::userMissing();
        }

        /* Idempotency: a system movement may be replayed (double-clicked
         * checkout, retried cron). Return the original instead of moving
         * money again. Admin top-ups always carry a fresh top-up id, so
         * they are never collapsed (audit by design). */
        if ($refId !== null) {
            $existing = $this->existingMovement($userId, $refType, $refId, $type);

            if ($existing !== null) {
                return [
                    'ok'                => true,
                    'replayed'          => true,
                    'type'              => $type,
                    'amount'            => round((float) $existing['amount'], 2),
                    'ledger_id'         => (int) $existing['id'],
                    'balance_after'     => round((float) $locked['ewallet_balance'], 2),
                    'withdrawable_after' => round((float) $locked['withdrawable_balance'], 2),
                    'user_id'           => $userId,
                    'ref_type'          => $refType,
                    'ref_id'            => $refId,
                ];
            }
        }

        $total       = round((float) $locked['ewallet_balance'], 2);
        $withdrawableBalance = round((float) $locked['withdrawable_balance'], 2);

        if ($type === 'credit') {
            $newTotal = round($total + $amount, 2);

            if ($withdrawablePortion !== null) {
                /* An explicit split (refund): only the part that was
                 * originally withdrawable raises the bucket again. */
                $portion         = round(max(0.0, min($amount, $withdrawablePortion)), 2);
                $newWithdrawable = round($withdrawableBalance + $portion, 2);
            } else {
                $newWithdrawable = $withdrawable
                    ? round($withdrawableBalance + $amount, 2)
                    : $withdrawableBalance;
            }
        } else {
            if ($total < $amount) {
                throw WalletException::insufficient($amount, $total);
            }

            if ($spendNonWithdrawableFirst) {
                $nonWithdrawable = round($total - $withdrawableBalance, 2);
                $fromWithdrawable = max(0.0, round($amount - min($amount, $nonWithdrawable), 2));
            } elseif ($withdrawable) {
                if ($withdrawableBalance < $amount) {
                    throw WalletException::notWithdrawable($amount, $withdrawableBalance);
                }
                $fromWithdrawable = $amount;
            } else {
                $fromWithdrawable = 0.0;
            }

            $newTotal        = round($total - $amount, 2);
            $newWithdrawable = round($withdrawableBalance - $fromWithdrawable, 2);
        }

        /* The invariant is checked before anything is written. */
        if ($newTotal < 0 || $newWithdrawable < 0 || $newWithdrawable > $newTotal + 0.00001) {
            throw WalletException::invariant(
                sprintf(
                    'result would be total %s / withdrawable %s',
                    $this->decimal($newTotal),
                    $this->decimal($newWithdrawable)
                )
            );
        }

        $this->pdo->prepare(
            'UPDATE users SET ewallet_balance = ?, withdrawable_balance = ? WHERE id = ?'
        )->execute([$this->decimal($newTotal), $this->decimal($newWithdrawable), $userId]);

        $this->pdo->prepare(
            'INSERT INTO ewallet_ledger
               (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $type,
            $this->decimal($amount),
            $this->decimal($newTotal),
            $this->decimal($newWithdrawable),
            $refType,
            $refId,
            $note === '' ? null : $note,
        ]);

        /* Capture the ledger id before any other statement runs: the live
         * revision bump below inserts into `live_revisions` and would
         * otherwise become the connection's lastInsertId. */
        $ledgerId = (int) $this->pdo->lastInsertId();

        /* Both buckets and the monitor's movement list changed. A replayed
         * movement returned above without touching anything. */
        Live::touchWallet($userId);

        /* The member's own dashboard shows the same two buckets. */
        Live::touchMemberDashboard($userId);

        /* The member directory and the member page show both buckets too. */
        Live::touchMember($userId);

        /* The membership page shows the e-wallet balance before an
         * activation; the member's own wallet page reads the same rows. */
        Live::touchMembership($userId);

        return [
            'ok'                 => true,
            'replayed'           => false,
            'type'               => $type,
            'amount'             => $amount,
            'ledger_id'          => $ledgerId,
            'balance_after'      => $newTotal,
            'withdrawable_after' => $newWithdrawable,
            'user_id'            => $userId,
            'ref_type'           => $refType,
            'ref_id'             => $refId,
        ];
    }

    /** @return array<string, mixed>|null */
    private function lockUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, ewallet_balance, withdrawable_balance
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
    private function existingMovement(int $userId, string $refType, int $refId, string $type): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, amount FROM ewallet_ledger
              WHERE user_id = ? AND ref_type = ? AND ref_id = ? AND type = ?
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$userId, $refType, $refId, $type]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @throws WalletException when the value is not in the DB enum. */
    private function assertRefType(string $refType): void
    {
        if (!in_array($refType, self::REF_TYPES, true)) {
            throw WalletException::unknownRefType($refType);
        }
    }

    /** Normalise and validate an amount; strings must be 2-decimal money. */
    private function amount(float|int|string $amount): float
    {
        if (is_string($amount)) {
            $raw = trim($amount);

            if ($raw === '') {
                throw WalletException::invalidAmount('Enter an amount.');
            }

            if (preg_match('/^\d+(\.\d+)?$/', $raw) !== 1) {
                throw WalletException::invalidAmount('Enter a valid amount greater than zero.');
            }

            if (preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
                throw WalletException::amountPrecision();
            }

            $amount = (float) $raw;
        }

        if (!is_finite((float) $amount)) {
            throw WalletException::invalidAmount('Enter a valid amount.');
        }

        $value = round((float) $amount, 2);

        if ($value <= 0) {
            throw WalletException::invalidAmount();
        }

        if ($value > self::MAX_AMOUNT) {
            throw WalletException::amountTooLarge();
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
     * One mutation, one transaction. When a caller already owns a
     * transaction (checkout, activation, the test harness) a savepoint
     * keeps a failed movement from poisoning the outer work.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'wallet_sp_' . (++self::$savepointSeq);
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
