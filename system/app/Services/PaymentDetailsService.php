<?php
declare(strict_types=1);

/* ==============================================================
 *  PaymentDetailsService — the member's own payout accounts
 *  (reference member profile; plan §4 users columns, §5.9 payouts)
 *
 *  The company pays withdrawals to these accounts, so they are
 *  validated here rather than trusted from the profile form:
 *
 *      gcash_number        PH mobile, 09XXXXXXXXX or +639XXXXXXXXX
 *      maya_number         same
 *      usdt_trc20_address  T + 33 base58 characters
 *      usdt_bep20_address  0x + 40 hex characters
 *
 *  An empty field is allowed and clears the saved value. Only the
 *  signed-in member's row is ever written.
 * ============================================================== */

final class PaymentDetailsService
{
    public const FIELDS = ['gcash_number', 'maya_number', 'usdt_trc20_address', 'usdt_bep20_address'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /**
     * The member's saved payout accounts ('' for unset fields).
     *
     * @return array{gcash_number:string, maya_number:string, usdt_trc20_address:string, usdt_bep20_address:string}
     */
    public function forUser(int $userId): array
    {
        $empty = array_fill_keys(self::FIELDS, '');

        if ($userId <= 0) {
            return $empty;
        }

        $stmt = $this->pdo->prepare(
            'SELECT gcash_number, maya_number, usdt_trc20_address, usdt_bep20_address
               FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return $empty;
        }

        foreach (self::FIELDS as $field) {
            $empty[$field] = (string) ($row[$field] ?? '');
        }

        return $empty;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> field => message (empty means valid)
     */
    public function validate(array $input): array
    {
        $errors = [];

        foreach (['gcash_number' => 'GCash', 'maya_number' => 'Maya'] as $field => $label) {
            $value = $this->phone((string) ($input[$field] ?? ''));

            if ($value !== '' && preg_match('/^(09\d{9}|\+639\d{9})$/', $value) !== 1) {
                $errors[$field] = sprintf(
                    'Enter a %s number like 0917 123 4567 (or +63 917 123 4567).',
                    $label
                );
            }
        }

        $trc20 = trim((string) ($input['usdt_trc20_address'] ?? ''));
        if ($trc20 !== '' && preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $trc20) !== 1) {
            $errors['usdt_trc20_address'] = 'Enter a TRC20 address: T followed by 33 letters or digits.';
        }

        $bep20 = trim((string) ($input['usdt_bep20_address'] ?? ''));
        if ($bep20 !== '' && preg_match('/^0x[a-fA-F0-9]{40}$/', $bep20) !== 1) {
            $errors['usdt_bep20_address'] = 'Enter a BEP20 address: 0x followed by 40 hex characters.';
        }

        return $errors;
    }

    /**
     * Persist the four fields, normalising phone numbers.
     *
     * @param array<string, mixed> $input
     * @return array<string, string> the stored values
     */
    public function save(int $userId, array $input): array
    {
        $errors = $this->validate($input);

        if ($errors !== []) {
            throw new InvalidArgumentException((string) reset($errors));
        }

        if ($userId <= 0) {
            throw new InvalidArgumentException('Your account could not be loaded.');
        }

        $values = [
            'gcash_number'       => $this->nullable($this->phone((string) ($input['gcash_number'] ?? ''))),
            'maya_number'        => $this->nullable($this->phone((string) ($input['maya_number'] ?? ''))),
            'usdt_trc20_address' => $this->nullable(trim((string) ($input['usdt_trc20_address'] ?? ''))),
            'usdt_bep20_address' => $this->nullable(trim((string) ($input['usdt_bep20_address'] ?? ''))),
        ];

        $this->pdo->prepare(
            'UPDATE users
                SET gcash_number = ?, maya_number = ?, usdt_trc20_address = ?, usdt_bep20_address = ?
              WHERE id = ?'
        )->execute([
            $values['gcash_number'],
            $values['maya_number'],
            $values['usdt_trc20_address'],
            $values['usdt_bep20_address'],
            $userId,
        ]);

        /* The profile's payout card reads these four columns. */
        Live::touchProfile($userId);

        return array_map(static fn (?string $value): string => (string) $value, $values);
    }

    /** Strip the punctuation people naturally type in a phone number. */
    private function phone(string $value): string
    {
        return (string) preg_replace('/[\s\-().]/', '', trim($value));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : mb_substr($value, 0, 100);
    }
}
