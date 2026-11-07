<?php
declare(strict_types=1);

/* ==============================================================
 *  RegistrationService — every way a member joins (plan §5.10,
 *  §7 Phase 9; reference AuthController::doRegister / User::register)
 *
 *  Four modes, one sponsor link, no binary placement anywhere:
 *
 *    standard  free; active, no package (shopping only)
 *    code      free-form signup paid with a registration code; the
 *              code decides the package; active immediately
 *    ewallet   a LOGGED-IN registrar pays packages.price from their
 *              wallet; the primary admin is credited as revenue;
 *              active immediately
 *    pending   free referral signup; member_status='pending'; the
 *              member activates later with a code, wallet or proof
 *
 *  Rules
 *    • sponsor required (decision D3) — username or referral code
 *    • standard / pending follow `free_registration_enabled`
 *    • e-wallet requires a signed-in registrar (P9-T5)
 *    • self-sponsorship and cycles refused
 *    • an 8-character referral code is generated for every account
 *    • the whole signup is one transaction: wallet debit, code
 *      redemption, activation row and commission hook all roll back
 *      together if anything fails
 * ============================================================== */

final class RegistrationService
{
    /** The four supported registration modes (plan §5.10). */
    public const MODES = ['standard', 'code', 'ewallet', 'pending'];

    /** Modes that need a package chosen or supplied up front. */
    public const PAID_MODES = ['code', 'ewallet'];

    /** Username shape kept from the existing register form. */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,50}$/';

    private PDO $pdo;
    private SponsorService $sponsors;
    private CodeService $codes;
    private ActivationService $activations;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo         = $pdo ?? db();
        $this->sponsors    = new SponsorService($this->pdo);
        $this->codes       = new CodeService($this->pdo);
        $this->activations = new ActivationService($this->pdo);
    }

    /**
     * Validate and create one account.
     *
     * @param array<string, mixed> $input  mode, username, email, full_name, phone,
     *                                     password, password_confirm, sponsor,
     *                                     package_id, reg_code, terms
     * @param array<string, mixed>|null $registrar the signed-in member registering
     *                                     someone else, or null for a guest
     * @return array<string, mixed> the created account summary
     * @throws RegistrationException
     */
    public function register(array $input, ?array $registrar = null): array
    {
        $mode = strtolower(trim((string) ($input['mode'] ?? 'standard')));

        /* The e-wallet mode is a logged-in action, so it is refused before
         * the form validation runs (P9-T5: the page sends the guest to
         * the login screen instead of showing a dead field error). */
        if ($mode === 'ewallet' && $registrar === null) {
            throw RegistrationException::loginRequired();
        }

        $errors = self::validate($input, $registrar);

        if ($errors !== []) {
            throw RegistrationException::validation($errors);
        }

        /* Early code check for a friendly error; the locked check inside
         * the transaction is the authoritative one (P9-T13). */
        if ($mode === 'code') {
            $check = $this->codes->validate((string) ($input['reg_code'] ?? ''));

            if (!$check['valid']) {
                throw RegistrationException::code((string) $check['message']);
            }
        }

        $package = null;

        if ($mode === 'ewallet') {
            $package = Package::find((int) ($input['package_id'] ?? 0));

            if ($package === null || (string) $package['status'] !== 'active') {
                throw RegistrationException::validation(['package_id' => 'Choose an active package.']);
            }
        }

        $typedSponsor = trim((string) ($input['sponsor'] ?? ''));

        /* Free registration is open, so the sponsor field is optional: a
         * blank field joins the member under the company account (the
         * default sponsor) instead of being refused. A typed sponsor must
         * still resolve to an active account. */
        $sponsor = $typedSponsor === ''
            ? $this->sponsors->defaultSponsor()
            : $this->sponsors->resolve($typedSponsor);

        if ($sponsor === null) {
            throw RegistrationException::sponsor(
                $typedSponsor === ''
                    ? 'Registration is not open yet — no sponsoring account exists. Please contact support.'
                    : 'Enter a valid sponsor username or referral code.'
            );
        }

        if (!$this->sponsors->isEligible($sponsor)) {
            throw RegistrationException::sponsor('That sponsor account is not active.');
        }

        $sponsorDefaulted = $typedSponsor === '';

        $username = trim((string) ($input['username'] ?? ''));
        $email    = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $phone    = trim((string) ($input['phone'] ?? ''));

        try {
            return $this->withTransaction(function () use (
                $mode,
                $input,
                $registrar,
                $sponsor,
                $sponsorDefaulted,
                $package,
                $username,
                $email,
                $password,
                $fullName,
                $phone
            ): array {
                /* Re-check under the transaction so a parallel signup
                 * waiting on the same name fails cleanly (the UNIQUE
                 * indexes are the final word). */
                if (Member::usernameExists($username, null, $this->pdo)) {
                    throw RegistrationException::duplicate('username', 'This username is already taken.');
                }

                if (Member::emailExists($email, null, $this->pdo)) {
                    throw RegistrationException::duplicate('email', 'An account with this email already exists.');
                }

                $isStandard = $mode === 'standard';
                $isPending  = $mode === 'pending';
                $registrarId = $registrar === null ? null : (int) ($registrar['id'] ?? 0);

                $memberStatus = $isStandard ? 'active' : 'pending';
                $paysWithWallet = $mode === 'ewallet';

                $this->pdo->prepare(
                    'INSERT INTO users
                       (username, email, password_hash, full_name, phone, role, member_status,
                        sponsor_id, referral_code, reg_payment_method, reg_paid_by, is_active)
                     VALUES (?, ?, ?, ?, ?, \'member\', ?, ?, ?, ?, ?, 1)'
                )->execute([
                    $username,
                    $email,
                    password_hash($password, PASSWORD_BCRYPT),
                    $fullName === '' ? null : $fullName,
                    $phone === '' ? null : $phone,
                    $memberStatus,
                    (int) $sponsor['id'],
                    $this->sponsors->generateReferralCode(),
                    $mode,
                    $paysWithWallet ? $registrarId : null,
                ]);

                $userId = (int) $this->pdo->lastInsertId();

                $summary = [
                    'user_id'       => $userId,
                    'username'      => $username,
                    'mode'          => $mode,
                    'member_status' => $memberStatus,
                    'package_id'    => null,
                    'package_name'  => null,
                    'activation_id' => null,
                    'sponsor_id'    => (int) $sponsor['id'],
                    'sponsor_username' => (string) ($sponsor['username'] ?? ''),
                    'sponsor_defaulted' => $sponsorDefaulted,
                ];

                if ($mode === 'code') {
                    $summary = array_merge($summary, $this->activateWithCode($userId, $username, (string) ($input['reg_code'] ?? '')));
                } elseif ($mode === 'ewallet') {
                    $summary = array_merge($summary, $this->activateWithWallet($userId, $username, $package, $registrar));
                }

                AuditLog::record([
                    'action'       => 'member.register',
                    'actor_id'     => $registrarId,
                    'actor_role'   => $registrar === null ? 'guest' : (string) ($registrar['role'] ?? 'member'),
                    'subject_type' => 'user',
                    'subject_id'   => $userId,
                    'meta'         => [
                        'mode'           => $mode,
                        'sponsor_id'     => (int) $sponsor['id'],
                        'sponsor_defaulted' => $sponsorDefaulted,
                        'activation_id'  => $summary['activation_id'],
                        'package_id'     => $summary['package_id'],
                    ],
                ], $this->pdo);

                Live::touch('admin.dashboard');

                return $summary;
            });
        } catch (PDOException $e) {
            /* 23000 = integrity constraint: a parallel signup took the
             * username/email/referral code between the checks and the
             * insert. Turn it into a field error, never a 500. */
            if ((string) $e->getCode() === '23000') {
                if (Member::usernameExists($username, null, $this->pdo)) {
                    throw RegistrationException::duplicate('username', 'This username is already taken.');
                }

                if (Member::emailExists($email, null, $this->pdo)) {
                    throw RegistrationException::duplicate('email', 'An account with this email already exists.');
                }
            }

            throw $e;
        } catch (CodeException $e) {
            throw RegistrationException::code($e->getMessage(), $e->fieldErrors);
        } catch (ActivationException $e) {
            throw new RegistrationException($e->getMessage(), $e->reason, $e->fieldErrors);
        }
    }

    /* ----------------------------------------------------------
     *  Field validation (no database access)
     * ---------------------------------------------------------- */

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $registrar
     * @return array<string, string>
     */
    public static function validate(array $input, ?array $registrar = null): array
    {
        $errors = [];
        $mode   = strtolower(trim((string) ($input['mode'] ?? 'standard')));

        if (!in_array($mode, self::MODES, true)) {
            $errors['mode'] = 'Choose how you want to join.';
        }

        if (in_array($mode, ['standard', 'pending'], true) && !setting_bool('free_registration_enabled', true)) {
            $errors['mode'] = 'Free registration is currently closed. Join with a registration code.';
        }

        if ($mode === 'ewallet' && $registrar === null) {
            return ['mode' => 'Sign in to pay for a registration from an e-wallet.'];
        }

        $username = trim((string) ($input['username'] ?? ''));
        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors['username'] = 'Username must be 3–50 characters: letters, numbers, dot, underscore or dash.';
        }

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        $fullName = trim((string) ($input['full_name'] ?? ''));
        if ($fullName !== '' && mb_strlen($fullName) > 100) {
            $errors['full_name'] = 'Full name must be at most 100 characters.';
        }

        $phone = trim((string) ($input['phone'] ?? ''));
        if ($phone !== '' && preg_match('/^[0-9+()\-\s]{7,30}$/', $phone) !== 1) {
            $errors['phone'] = 'Enter a valid contact number (digits, +, spaces or dashes).';
        }

        $password = (string) ($input['password'] ?? '');
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters long.';
        }

        if ($password !== (string) ($input['password_confirm'] ?? '')) {
            $errors['password_confirm'] = 'Password and confirmation do not match.';
        }

        /* The sponsor is optional: a blank field joins the member under
         * the company's default sponsor (the primary admin). A typed
         * value still may not be the member's own username. */
        $sponsor = trim((string) ($input['sponsor'] ?? ''));
        if ($sponsor !== '' && strcasecmp($sponsor, $username) === 0) {
            $errors['sponsor'] = 'You cannot sponsor yourself. Enter your sponsor’s username, or leave it blank.';
        }

        if ($mode === 'code' && RegCode::normalise((string) ($input['reg_code'] ?? '')) === '') {
            $errors['reg_code'] = 'Enter a complete registration code (XXXX-XXXX-XXXX).';
        }

        if ($mode === 'ewallet' && (int) ($input['package_id'] ?? 0) <= 0) {
            $errors['package_id'] = 'Choose the package to activate.';
        }

        if (empty($input['terms'])) {
            $errors['terms'] = 'You must agree to the privacy policy & terms.';
        }

        return $errors;
    }

    /* ----------------------------------------------------------
     *  Payment paths (inside the caller's transaction)
     * ---------------------------------------------------------- */

    /**
     * Code mode: redeem the code, activate the member, run the hook.
     *
     * @return array<string, mixed>
     */
    private function activateWithCode(int $userId, string $username, string $typedCode): array
    {
        $codeRow = $this->codes->lockForRedemption($typedCode);

        $this->codes->redeem((int) $codeRow['id'], $userId);

        $amount = round((float) $codeRow['package_price'], 2);

        $activationId = Activation::create([
            'user_id'        => $userId,
            'package_id'     => (int) $codeRow['package_id'],
            'amount'         => $amount,
            'payment_method' => 'code',
            'status'         => 'pending_payment',
            'reg_code_id'    => (int) $codeRow['id'],
        ], $this->pdo);

        Activation::markApproved($activationId, null, $this->pdo);

        $result = $this->activations->complete($activationId, null);

        return [
            'member_status' => 'active',
            'package_id'    => (int) $codeRow['package_id'],
            'package_name'  => (string) $codeRow['package_name'],
            'activation_id' => $activationId,
            'amount'        => $amount,
        ];
    }

    /**
     * E-wallet mode: the registrar pays, the company receives, the new
     * member becomes active immediately (P9-T4).
     *
     * @param array<string, mixed> $package
     * @param array<string, mixed>|null $registrar
     * @return array<string, mixed>
     */
    private function activateWithWallet(int $userId, string $username, array $package, ?array $registrar): array
    {
        if ($registrar === null) {
            throw RegistrationException::loginRequired();
        }

        $payerId = (int) ($registrar['id'] ?? 0);
        $amount  = round((float) $package['price'], 2);

        $activationId = $this->activations->walletPurchase(
            $payerId,
            $userId,
            $package,
            $amount,
            sprintf('Registration of @%s — %s', $username, (string) $package['name'])
        );

        Activation::markApproved($activationId, null, $this->pdo);

        $result = $this->activations->complete($activationId, null);

        return [
            'member_status' => 'active',
            'package_id'    => (int) $result['package_id'],
            'package_name'  => (string) $package['name'],
            'activation_id' => $activationId,
            'amount'        => (float) $result['amount'],
        ];
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * One signup, one transaction. A caller that already owns a
     * transaction (unlikely today) gets a savepoint.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'registration_sp_' . bin2hex(random_bytes(4));
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
