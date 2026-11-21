<?php
declare(strict_types=1);

/* ==============================================================
 *  CodeService — generate, validate and redeem registration codes
 *  (plan §3.7, §4.3, §5.10, §7 Phase 9; reference models/Code.php)
 *
 *  Generation
 *    • 12 characters in three groups from an unambiguous alphabet
 *    • quantity 1–500 per batch, package-bound, priced, optional expiry
 *    • transactional: either the whole batch exists or nothing does
 *    • uniqueness is guaranteed by a lookup + retry plus the UNIQUE
 *      index as the final word
 *
 *  Validation
 *    • exists → unused → not expired → package still active
 *    • an expired row is flipped to `expired` when it is first seen
 *
 *  Redemption (the concurrency rule)
 *    • validate() under `FOR UPDATE`, then a conditional UPDATE
 *      (`WHERE status='unused'`) whose rowCount must be 1.
 *      Two parallel registrations can therefore never both use one
 *      code — the loser's transaction is rolled back by its caller.
 *
 *  Audit rows: code.generate (admin) and code.redeem (system).
 * ============================================================== */

final class CodeService
{
    /** Plan §5.10: a single batch may not exceed this. */
    public const MAX_BATCH = 500;

    /** DECIMAL(12,2) upper bound for the selling price. */
    public const MAX_PRICE = 9999999999.99;

    /** How many times a random collision is retried before giving up. */
    private const MAX_ATTEMPTS = 40;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /* ----------------------------------------------------------
     *  Generation
     * ---------------------------------------------------------- */

    /**
     * Issue one batch of codes for a package.
     *
     * @param array{package_id?: mixed, quantity?: mixed, price?: mixed, expires_at?: mixed, code_type?: mixed} $input
     * @param array<string, mixed>|null $actor the signed-in admin
     * @return array{codes: list<string>, batch: int, package_id: int, package_name: string, price: string, expires_at: ?string}
     */
    public function generate(array $input, ?array $actor = null): array
    {
        if ($actor === null || (string) ($actor['role'] ?? '') === '') {
            throw CodeException::forbidden();
        }

        $packageId = (int) ($input['package_id'] ?? 0);
        $quantity  = (int) ($input['quantity'] ?? 0);
        $price     = trim((string) ($input['price'] ?? ''));
        $expiresAt = trim((string) ($input['expires_at'] ?? ''));
        $type      = strtolower(trim((string) ($input['code_type'] ?? 'registration')));

        $package = $packageId > 0 ? Package::find($packageId) : null;

        $errors = [];

        if ($package === null) {
            $errors['package_id'] = 'Choose the package these codes belong to.';
        } elseif ((string) $package['status'] !== 'active') {
            $errors['package_id'] = 'That package is inactive, so its codes could not be redeemed.';
        }

        if ($quantity < 1 || $quantity > self::MAX_BATCH) {
            $errors['quantity'] = sprintf('Quantity must be between 1 and %d.', self::MAX_BATCH);
        }

        if ($price === '' || !is_numeric($price) || (float) $price < 0) {
            $errors['price'] = 'Enter the price per code (zero is allowed for a giveaway).';
        } elseif (preg_match('/^\d+(\.\d{1,2})?$/', $price) !== 1) {
            $errors['price'] = 'The price may have at most two decimal places.';
        } elseif ((float) $price > self::MAX_PRICE) {
            $errors['price'] = 'That price is too large.';
        }

        if ($expiresAt !== '') {
            $date = self::parseDate($expiresAt);

            if ($date === null) {
                $errors['expires_at'] = 'Enter a valid expiry date.';
            } elseif ($date < date('Y-m-d')) {
                $errors['expires_at'] = 'The expiry date cannot be in the past.';
            }
        }

        if (!in_array($type, RegCode::TYPES, true)) {
            $errors['code_type'] = 'Unknown code type.';
        } elseif ($type === 'upgrade') {
            /* Reserved: package upgrades are a later phase (plan §1.3). */
            $errors['code_type'] = 'Upgrade codes are not issued in this build.';
        }

        if ($errors !== []) {
            throw CodeException::validation($errors);
        }

        $codes = [];
        $owns  = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO reg_codes (code, package_id, price, code_type, status, created_by, expires_at)
             VALUES (?, ?, ?, ?, \'unused\', ?, ?)'
        );

        try {
            $adminId = (int) ($actor['id'] ?? 0);

            for ($i = 0; $i < $quantity; $i++) {
                $code = $this->uniqueCode();

                $insert->execute([
                    $code,
                    $packageId,
                    number_format((float) $price, 2, '.', ''),
                    $type,
                    $adminId,
                    $expiresAt === '' ? null : $expiresAt,
                ]);

                $codes[] = $code;
            }

            AuditLog::record([
                'action'       => 'code.generate',
                'actor_id'     => $adminId,
                'actor_role'   => (string) ($actor['role'] ?? ''),
                'subject_type' => 'package',
                'subject_id'   => $packageId,
                'meta'         => [
                    'quantity'   => $quantity,
                    'price'      => number_format((float) $price, 2, '.', ''),
                    'expires_at' => $expiresAt === '' ? null : $expiresAt,
                    'first'      => $codes[0] ?? null,
                    'last'       => $codes === [] ? null : $codes[count($codes) - 1],
                ],
            ], $this->pdo);

            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        /* The code list and its counters gained a whole batch. */
        Live::touch('admin.codes');

        return [
            'codes'        => $codes,
            'batch'        => count($codes),
            'package_id'   => $packageId,
            'package_name' => (string) ($package['name'] ?? ''),
            'price'        => number_format((float) $price, 2, '.', ''),
            'expires_at'   => $expiresAt === '' ? null : $expiresAt,
        ];
    }

    /**
     * One random code that is not in the table yet.
     *
     * @throws CodeException when the alphabet collides too often (a
     *         sign the table is full for this length — practically never)
     */
    public function uniqueCode(): string
    {
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM reg_codes WHERE code = ?');

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::randomCode();
            $check->execute([$code]);

            if ((int) $check->fetchColumn() === 0) {
                return $code;
            }
        }

        throw new CodeException('Could not generate a unique code. Please try again.', 'quantity');
    }

    /** A single random code in ABCD-EFGH-JKLM form (no database access). */
    public static function randomCode(): string
    {
        $alphabet = RegCode::ALPHABET;
        $max      = strlen($alphabet) - 1;
        $groups   = [];

        for ($group = 0; $group < RegCode::GROUPS; $group++) {
            $chunk = '';

            for ($i = 0; $i < RegCode::GROUP; $i++) {
                $chunk .= $alphabet[random_int(0, $max)];
            }

            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }

    /* ----------------------------------------------------------
     *  Validation (AJAX + registration form)
     * ---------------------------------------------------------- */

    /**
     * Check a typed code without redeeming it.
     *
     * An unused row whose expiry date has passed is flipped to
     * `expired` here, so the state is recorded even if nobody retries.
     *
     * @return array{valid: bool, message: string, code: array<string, mixed>|null}
     */
    public function validate(string $typed): array
    {
        $code = RegCode::normalise($typed);

        if ($code === '') {
            return ['valid' => false, 'message' => 'Enter a complete code (XXXX-XXXX-XXXX).', 'code' => null];
        }

        $row = RegCode::findByCode($code, $this->pdo);

        if ($row === null) {
            return ['valid' => false, 'message' => 'That registration code is not recognised.', 'code' => null];
        }

        if ((string) $row['status'] === 'used') {
            return ['valid' => false, 'message' => 'That registration code has already been used.', 'code' => null];
        }

        if ((string) $row['status'] === 'expired') {
            return ['valid' => false, 'message' => 'That registration code has expired.', 'code' => null];
        }

        if ($this->isPastExpiry($row['expires_at'] ?? null)) {
            $this->markExpired((int) $row['id']);

            return ['valid' => false, 'message' => 'That registration code has expired.', 'code' => null];
        }

        if ((string) $row['package_status'] !== 'active') {
            return ['valid' => false, 'message' => 'The package this code activates is no longer available.', 'code' => null];
        }

        if ((string) $row['code_type'] !== 'registration') {
            return ['valid' => false, 'message' => 'Upgrade codes cannot be used to activate a membership.', 'code' => null];
        }

        return [
            'valid'   => true,
            'message' => sprintf('Valid — %s (%s).', (string) $row['package_name'], money((float) $row['package_price'])),
            'code'    => $row,
        ];
    }

    /**
     * Lock and return an unused code for redemption, or explain why not.
     *
     * MUST be called inside the caller's transaction: the row lock is
     * what serialises two parallel redemptions of the same code.
     *
     * @return array<string, mixed>
     * @throws CodeException
     */
    public function lockForRedemption(string $typed): array
    {
        $code = RegCode::normalise($typed);

        if ($code === '') {
            throw CodeException::notFound();
        }

        $stmt = $this->pdo->prepare(
            'SELECT r.*, p.name AS package_name, p.price AS package_price, p.status AS package_status
               FROM reg_codes r
               JOIN packages p ON p.id = r.package_id
              WHERE r.code = ?
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw CodeException::notFound($code);
        }

        if ((string) $row['status'] === 'used') {
            throw CodeException::used();
        }

        if ((string) $row['status'] === 'expired' || $this->isPastExpiry($row['expires_at'] ?? null)) {
            $this->markExpired((int) $row['id']);

            throw CodeException::expired();
        }

        if ((string) $row['package_status'] !== 'active') {
            throw CodeException::packageDisabled();
        }

        if ((string) $row['code_type'] !== 'registration') {
            throw CodeException::wrongType();
        }

        return $row;
    }

    /**
     * Redeem a locked code for one member.
     *
     * The conditional UPDATE is the single-use guarantee: the row count
     * must be exactly 1, so a code can never be consumed twice even if
     * two transactions slipped past the SELECT.
     */
    public function redeem(int $codeId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE reg_codes
                SET status = 'used', used_by = ?, used_at = NOW()
              WHERE id = ? AND status = 'unused'"
        );
        $stmt->execute([$userId, $codeId]);

        if ($stmt->rowCount() !== 1) {
            throw CodeException::used();
        }

        AuditLog::record([
            'action'       => 'code.redeem',
            'actor_id'     => $userId,
            'actor_role'   => 'member',
            'subject_type' => 'reg_code',
            'subject_id'   => $codeId,
            'meta'         => ['user_id' => $userId],
        ], $this->pdo);

        /* The admin code list now shows the row as used. */
        Live::touch('admin.codes');
    }

    /* ----------------------------------------------------------
     *  Maintenance
     * ---------------------------------------------------------- */

    /**
     * Flip every past-dated unused code to `expired`.
     *
     * Called by validate() when it meets one, and by the tests / a
     * future cron sweep. Returns the number of rows changed.
     */
    public function expireSweep(): int
    {
        $stmt = $this->pdo->query(
            "UPDATE reg_codes
                SET status = 'expired'
              WHERE status = 'unused'
                AND expires_at IS NOT NULL
                AND expires_at < CURDATE()"
        );

        $changed = $stmt->rowCount();

        if ($changed > 0) {
            Live::touch('admin.codes');
        }

        return $changed;
    }

    /** How many codes exist for a package (used by the package page/guard). */
    public function countForPackage(int $packageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM reg_codes WHERE package_id = ?');
        $stmt->execute([$packageId]);

        return (int) $stmt->fetchColumn();
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private function markExpired(int $codeId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE reg_codes SET status = 'expired' WHERE id = ? AND status = 'unused'"
        );
        $stmt->execute([$codeId]);

        if ($stmt->rowCount() === 1) {
            Live::touch('admin.codes');
        }
    }

    /** Is a DATE column value before today (Asia/Manila)? */
    private function isPastExpiry(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }

        $date = substr((string) $expiresAt, 0, 10);

        return $date < date('Y-m-d');
    }

    /** Strict YYYY-MM-DD parser (no "next tuesday" surprises). */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }
}
