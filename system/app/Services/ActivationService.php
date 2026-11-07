<?php
declare(strict_types=1);

/* ==============================================================
 *  ActivationService — turning a pending member into an associate
 *  (plan §4.4, §5.10, §6.2, §7 Phase 9; reference User::activate)
 *
 *  Paths
 *    code      a registration code determines the package; redeemed
 *              and activated in one transaction
 *    ewallet   the member's own wallet pays packages.price; the
 *              primary admin is credited as revenue (reference rule)
 *    manual    GCash / Maya / bank proof, status pending_review until
 *              an admin approves it
 *
 *  Registration-time payments (RegistrationService) reuse complete(),
 *  so every activation — immediate or reviewed — runs the same steps:
 *
 *    1. lock the member row, then the activation row
 *    2. bail when commission_processed_at is already set
 *    3. set users.package_id / member_status = active / activated_at
 *    4. run CommissionService::onActivation() (one call, same txn)
 *    5. stamp commission_processed_at
 *
 *  Rules (plan §7 Phase 9)
 *    • one package at a time; only from pending / inactive
 *    • one open request per member
 *    • amount is packages.price, read under a lock
 *    • rejection returns the member to pending with a reason
 *    • double activation and double approval are refused
 * ============================================================== */

final class ActivationService
{
    /** Savepoints must be unique when calls nest. */
    private static int $savepointSeq = 0;

    private PDO $pdo;
    private CodeService $codes;
    private WalletService $wallet;
    private CommissionService $commissions;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo         = $pdo ?? db();
        $this->codes       = new CodeService($this->pdo);
        $this->wallet      = new WalletService($this->pdo);
        $this->commissions = new CommissionService();
    }

    /* ----------------------------------------------------------
     *  Member — request an activation
     * ---------------------------------------------------------- */

    /**
     * Create and (where payment is immediate) complete an activation.
     *
     * @param array<string, mixed> $input method, reg_code, package_id, channel, reference_no
     * @param array<string, mixed>|null $file one entry from $_FILES (manual proof)
     * @return array<string, mixed> summary of the new activation
     */
    public function request(int $userId, array $input, ?array $file = null): array
    {
        $errors = self::validateRequest($input);

        if ($errors !== []) {
            throw ActivationException::validation($errors);
        }

        $method  = (string) ($input['method'] ?? '');
        $channel = strtolower(trim((string) ($input['channel'] ?? '')));

        $member = Member::find($userId, $this->pdo);

        if ($member === null) {
            throw ActivationException::notFound();
        }

        $this->assertCanActivate($member);

        if (Activation::latestOpen($userId, $this->pdo) !== null) {
            throw ActivationException::open();
        }

        /* A manual proof is written before the transaction starts (an
         * upload must not hold row locks) and removed again when the
         * request is refused, so a failed attempt leaves no orphan. */
        $path = null;

        if ($method === 'manual') {
            $upload = Upload::store($file ?? [], 'proofs');

            if (!$upload['ok']) {
                throw ActivationException::proof((string) ($upload['error'] ?? 'Please attach the payment proof.'));
            }

            $path = (string) $upload['path'];
        }

        try {
            return $this->withTransaction(function () use ($userId, $method, $channel, $input, $path): array {
                $locked = $this->lockMember($userId);

                if ($locked === null) {
                    throw ActivationException::notFound();
                }

                $this->assertCanActivate($locked);

                if (Activation::latestOpen($userId, $this->pdo) !== null) {
                    throw ActivationException::open();
                }

                $codeRow   = null;
                $packageId = (int) ($input['package_id'] ?? 0);

                if ($method === 'code') {
                    $codeRow   = $this->codes->lockForRedemption((string) ($input['reg_code'] ?? ''));
                    $packageId = (int) $codeRow['package_id'];
                }

                $package = $this->lockPackage($packageId);

                if ($package === null || (string) $package['status'] !== 'active') {
                    throw ActivationException::package();
                }

                $amount = round((float) $package['price'], 2);

                if ($method === 'code') {
                    $activationId = Activation::create([
                        'user_id'        => $userId,
                        'package_id'     => $packageId,
                        'amount'         => $amount,
                        'payment_method' => 'code',
                        'status'         => 'pending_payment',
                        'reg_code_id'    => (int) $codeRow['id'],
                    ], $this->pdo);

                    $this->codes->redeem((int) $codeRow['id'], $userId);
                } elseif ($method === 'ewallet') {
                    $activationId = $this->walletPurchase(
                        $userId,
                        $userId,
                        $package,
                        $amount,
                        'Package activation — ' . (string) $package['name']
                    );
                } else {
                    $activationId = Activation::create([
                        'user_id'        => $userId,
                        'package_id'     => $packageId,
                        'amount'         => $amount,
                        'payment_method' => $channel,
                        'status'         => 'pending_review',
                        'reference_no'   => (string) ($input['reference_no'] ?? ''),
                        'proof_path'     => $path,
                    ], $this->pdo);

                    AuditLog::record([
                        'action'       => 'activation.request',
                        'actor_id'     => $userId,
                        'actor_role'   => 'member',
                        'subject_type' => 'activation',
                        'subject_id'   => $activationId,
                        'meta'         => [
                            'package_id' => $packageId,
                            'amount'     => $amount,
                            'channel'    => $channel,
                        ],
                    ], $this->pdo);

                    return [
                        'activation_id' => $activationId,
                        'status'        => 'pending_review',
                        'method'        => 'manual',
                        'package_name'  => (string) $package['name'],
                        'amount'        => $amount,
                    ];
                }

                Activation::markApproved($activationId, null, $this->pdo);
                $summary = $this->complete($activationId, null);

                return $summary + [
                    'status'       => 'approved',
                    'method'       => $method,
                    'package_name' => (string) $package['name'],
                ];
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                UploadService::remove($path);
            }

            if ($e instanceof WalletException) {
                throw ActivationException::wallet($e->getMessage());
            }

            if ($e instanceof CodeException) {
                throw new ActivationException(
                    $e->getMessage(),
                    $e->reason,
                    $e->fieldErrors === [] ? ['reg_code' => $e->getMessage()] : $e->fieldErrors
                );
            }

            throw $e;
        }
    }

    /* ----------------------------------------------------------
     *  Admin — approve / reject
     * ---------------------------------------------------------- */

    /**
     * Approve a manual activation: member active, package set, the
     * commission hook runs once, audited.
     *
     * @param array<string, mixed>|null $actor the reviewing admin
     * @return array<string, mixed>
     */
    public function approve(int $activationId, ?array $actor = null): array
    {
        return $this->withTransaction(function () use ($activationId, $actor): array {
            $activation = Activation::find($activationId, $this->pdo);

            if ($activation === null) {
                throw ActivationException::notFound();
            }

            $member = $this->lockMember((int) $activation['user_id']);

            if ($member === null) {
                throw ActivationException::notFound();
            }

            $locked = $this->lockActivation($activationId);

            if ($locked === null) {
                throw ActivationException::notFound();
            }

            if ((string) $locked['status'] !== 'pending_review') {
                throw ActivationException::status('approved', (string) $locked['status']);
            }

            if ($locked['commission_processed_at'] !== null) {
                throw ActivationException::processed();
            }

            $reviewerId = $actor === null ? null : (int) ($actor['id'] ?? 0);

            Activation::markApproved($activationId, $reviewerId, $this->pdo);

            $summary = $this->complete($activationId, $reviewerId);

            AuditLog::record([
                'action'       => 'activation.approve',
                'actor_id'     => $reviewerId,
                'actor_role'   => $actor === null ? null : (string) ($actor['role'] ?? ''),
                'subject_type' => 'activation',
                'subject_id'   => $activationId,
                'meta'         => [
                    'user_id'    => (int) $locked['user_id'],
                    'package_id' => (int) $locked['package_id'],
                    'amount'     => (float) $locked['amount'],
                    'channel'    => (string) $locked['payment_method'],
                ],
            ], $this->pdo);

            return $summary + [
                'status'       => 'approved',
                'username'     => (string) ($activation['username'] ?? ''),
                'package_name' => (string) ($activation['package_name'] ?? ''),
            ];
        });
    }

    /**
     * Reject a manual activation with a reason. The member goes back to
     * pending; no money moves (a manual proof never debited anything).
     *
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function reject(int $activationId, ?array $actor, string $reason): array
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ActivationException::validation([
                'reject_reason' => 'Enter a short reason (at least 3 characters) so the member knows what to fix.',
            ]);
        }

        return $this->withTransaction(function () use ($activationId, $actor, $reason): array {
            $activation = Activation::find($activationId, $this->pdo);

            if ($activation === null) {
                throw ActivationException::notFound();
            }

            $locked = $this->lockActivation($activationId);

            if ($locked === null) {
                throw ActivationException::notFound();
            }

            if ((string) $locked['status'] !== 'pending_review') {
                throw ActivationException::status('rejected', (string) $locked['status']);
            }

            $reviewerId = $actor === null ? null : (int) ($actor['id'] ?? 0);

            Activation::markRejected($activationId, (int) ($reviewerId ?? 0), $reason, $this->pdo);

            AuditLog::record([
                'action'       => 'activation.reject',
                'actor_id'     => $reviewerId,
                'actor_role'   => $actor === null ? null : (string) ($actor['role'] ?? ''),
                'subject_type' => 'activation',
                'subject_id'   => $activationId,
                'meta'         => [
                    'user_id' => (int) $locked['user_id'],
                    'reason'  => $reason,
                ],
            ], $this->pdo);

            return [
                'activation_id' => $activationId,
                'status'        => 'rejected',
                'user_id'       => (int) $locked['user_id'],
                'amount'        => (float) $locked['amount'],
            ];
        });
    }

    /* ----------------------------------------------------------
     *  The shared completion step (plan §6.2)
     * ---------------------------------------------------------- */

    /**
     * Set the member active with the activation's package, run the
     * commission hook exactly once and stamp the anchor.
     *
     * Always called inside a transaction. The row locks are taken
     * member-first so every activation path uses the same lock order
     * (this is also the order WalletService uses).
     *
     * @return array<string, mixed>
     */
    public function complete(int $activationId, ?int $reviewerId = null): array
    {
        $locked = $this->lockActivation($activationId);

        if ($locked === null) {
            throw ActivationException::notFound();
        }

        if ((string) $locked['status'] !== 'approved') {
            throw ActivationException::status('completed', (string) $locked['status']);
        }

        if ($locked['commission_processed_at'] !== null) {
            throw ActivationException::processed();
        }

        $userId = (int) $locked['user_id'];
        $member = $this->lockMember($userId);

        if ($member === null) {
            throw ActivationException::notFound();
        }

        if ((string) $member['member_status'] === 'suspended') {
            throw ActivationException::suspended();
        }

        $packageId = (int) $locked['package_id'];
        $amount    = round((float) $locked['amount'], 2);

        /* Only the code / e-wallet paths change reg_payment_method; a
         * manual channel stays on the activation row (the ENUM on
         * users.reg_payment_method does not carry gcash/maya/bank). */
        $regMethod = match ((string) $locked['payment_method']) {
            'code'    => 'code',
            'ewallet' => 'ewallet',
            default   => null,
        };

        $this->pdo->prepare(
            "UPDATE users
                SET package_id = ?,
                    member_status = 'active',
                    activated_at = NOW(),
                    reg_code_id = COALESCE(?, reg_code_id),
                    reg_payment_method = COALESCE(?, reg_payment_method)
              WHERE id = ?"
        )->execute([
            $packageId,
            isset($locked['reg_code_id']) && (int) $locked['reg_code_id'] > 0 ? (int) $locked['reg_code_id'] : null,
            $regMethod,
            $userId,
        ]);

        /* The one and only award call: Phases 10–11 credit the sponsor
         * chain inside this transaction. */
        $commissions = $this->commissions->onActivation($activationId, $this->pdo);

        Activation::markCommissionProcessed($activationId, $this->pdo);

        AuditLog::record([
            'action'       => 'member.activate',
            'actor_id'     => $reviewerId,
            'actor_role'   => $reviewerId === null ? 'system' : 'admin',
            'subject_type' => 'user',
            'subject_id'   => $userId,
            'meta'         => [
                'activation_id' => $activationId,
                'package_id'    => $packageId,
                'amount'        => $amount,
                'method'        => (string) $locked['payment_method'],
            ],
        ], $this->pdo);

        Live::touch('admin.dashboard');

        return [
            'activation_id' => $activationId,
            'user_id'       => $userId,
            'package_id'    => $packageId,
            'amount'        => $amount,
            'member_status' => 'active',
            /* What the compensation hook did, so the caller can flash it
             * and the tests can assert the award without re-reading rows. */
            'commission_credited' => round((float) ($commissions['credited'] ?? 0.0), 2),
            'commission_skipped'  => (int) ($commissions['skipped'] ?? 0),
        ];
    }

    /* ----------------------------------------------------------
     *  Validation
     * ---------------------------------------------------------- */

    /**
     * Field errors for an activation request (no database access).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validateRequest(array $input): array
    {
        $errors = [];
        $method = strtolower(trim((string) ($input['method'] ?? '')));

        if (!in_array($method, ['code', 'ewallet', 'manual'], true)) {
            $errors['method'] = 'Choose how you want to pay for the package.';
        }

        if ($method === 'code') {
            $code = RegCode::normalise((string) ($input['reg_code'] ?? ''));

            if ($code === '') {
                $errors['reg_code'] = 'Enter a complete code (XXXX-XXXX-XXXX).';
            }
        }

        if ($method === 'ewallet' || $method === 'manual') {
            $packageId = (int) ($input['package_id'] ?? 0);

            if ($packageId <= 0) {
                $errors['package_id'] = 'Choose the package you want to activate.';
            }
        }

        if ($method === 'manual') {
            $channel = strtolower(trim((string) ($input['channel'] ?? '')));

            if (!in_array($channel, Activation::MANUAL, true)) {
                $errors['channel'] = 'Choose GCash, Maya or bank transfer.';
            }

            $reference = trim((string) ($input['reference_no'] ?? ''));

            if ($reference === '') {
                $errors['reference_no'] = 'Enter the reference number of your payment.';
            } elseif (mb_strlen($reference) < 3 || mb_strlen($reference) > 40) {
                $errors['reference_no'] = 'The reference number must be 3–40 characters.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $member
     * @throws ActivationException
     */
    private function assertCanActivate(array $member): void
    {
        $status = (string) ($member['member_status'] ?? '');

        if ($status === 'suspended') {
            throw ActivationException::suspended();
        }

        if ($status === 'active') {
            if ($member['package_id'] !== null) {
                throw ActivationException::alreadyActive(
                    isset($member['package_name']) ? (string) $member['package_name'] : null
                );
            }

            throw ActivationException::standard();
        }
    }

    /* ----------------------------------------------------------
     *  Wallet + locks
     * ---------------------------------------------------------- */

    /**
     * A wallet-funded package purchase (plan §5.10, §6.2).
     *
     * Debits the payer, credits the primary admin as company revenue
     * and writes the activation row for the beneficiary — all in the
     * caller's transaction. Used by both paths:
     *
     *   member activation   payer = beneficiary
     *   registration        payer = the logged-in registrar
     *
     * The caller then marks the activation approved and calls
     * complete(), so every wallet purchase follows the same steps.
     *
     * @param array<string, mixed> $package the locked package row
     */
    public function walletPurchase(int $payerId, int $beneficiaryId, array $package, float $amount, string $note): int
    {
        /* Re-read the package under a lock: the price and the status are
         * the authoritative ones, even if an admin edited them between
         * the form render and this call. */
        $locked  = $this->lockPackage((int) ($package['id'] ?? 0));
        $package = $locked ?? $package;

        if ($locked === null || (string) $locked['status'] !== 'active') {
            throw ActivationException::package();
        }

        $amount = round((float) $locked['price'], 2);

        $balance = $this->wallet->balance($payerId);

        if ($amount > 0 && $balance < $amount) {
            throw ActivationException::wallet(sprintf(
                'The e-wallet balance (%s) does not cover the package price (%s). Top up or pay by code / proof.',
                money($balance),
                money($amount)
            ));
        }

        $activationId = Activation::create([
            'user_id'        => $beneficiaryId,
            'package_id'     => (int) $locked['id'],
            'amount'         => $amount,
            'payment_method' => 'ewallet',
            'status'         => 'pending_payment',
        ], $this->pdo);

        if ($amount <= 0) {
            return $activationId;
        }

        $this->wallet->debitInternal($payerId, $amount, 'registration', $activationId, $note);

        $admin = Member::primaryAdmin($this->pdo);

        if ($admin !== null) {
            $this->wallet->credit(
                (int) $admin['id'],
                $amount,
                'registration',
                $activationId,
                'Package revenue — ' . (string) ($package['name'] ?? 'package'),
                true
            );
        } else {
            error_log('[ecowealth] package paid by wallet but no primary admin exists to receive the revenue');
        }

        return $activationId;
    }

    /** @return array<string, mixed>|null */
    private function lockMember(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, p.name AS package_name
               FROM users u
               LEFT JOIN packages p ON p.id = u.package_id
              WHERE u.id = ?
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function lockActivation(int $activationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM activations WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$activationId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function lockPackage(int $packageId): ?array
    {
        if ($packageId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM packages WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$packageId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * One activation action, one transaction. A caller that already owns
     * a transaction (RegistrationService) gets a savepoint instead, so a
     * refused activation never poisons the outer work.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'activation_sp_' . (++self::$savepointSeq);
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
                    /* The outer transaction is already unusable. */
                }
            }

            throw $e;
        }
    }
}
