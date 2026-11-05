<?php
declare(strict_types=1);

/* ==============================================================
 *  AddressService — validation and writes for the address book
 *  (plan §5.3, Phase 6; reference MemberAddress)
 *
 *  Rules this class owns:
 *    • every write is scoped by the signed-in member (IDOR guard)
 *    • at most one default per member — the others are cleared in
 *      the same transaction
 *    • the first address a member saves becomes the default, and
 *      deleting the default promotes another one
 *    • checkout can save the typed address for next time without
 *      ever failing the order (the controller calls it after commit
 *      and only logs a failure)
 * ============================================================== */

final class AddressService
{
    private static int $savepointSeq = 0;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return Address::forUser($userId, $this->pdo);
    }

    /** @return array<string, mixed>|null */
    public function defaultFor(int $userId): ?array
    {
        return Address::defaultFor($userId, $this->pdo);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $userId): ?array
    {
        return Address::findOwned($id, $userId, $this->pdo);
    }

    public function countFor(int $userId): int
    {
        return Address::countFor($userId, $this->pdo);
    }

    /* ----------------------------------------------------------
     *  Validation
     * ---------------------------------------------------------- */

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> field => message (empty means valid)
     */
    public function validate(array $input): array
    {
        return Validator::check($input, [
            'label'          => 'nullable|string|max:40',
            'recipient_name' => 'required|string|min:2|max:120',
            'phone'          => 'nullable|string|max:40',
            'address_line'   => 'required|string|min:5|max:255',
            'barangay'       => 'nullable|string|max:120',
            'city'           => 'nullable|string|max:80',
            'province'       => 'nullable|string|max:80',
            'postal'         => 'nullable|string|regex:/^[A-Za-z0-9][A-Za-z0-9 \-]{0,19}$/',
            'billing_name'   => 'nullable|string|max:120',
            'billing_phone'  => 'nullable|string|max:40',
            'notes'          => 'nullable|string|max:500',
        ], [
            'recipient_name' => 'Recipient name',
            'address_line'   => 'Delivery address',
            'label'          => 'Label',
            'phone'          => 'Phone',
            'billing_name'   => 'Billing name',
            'billing_phone'  => 'Billing phone',
            'notes'          => 'Delivery notes',
        ]);
    }

    /* ----------------------------------------------------------
     *  Writes
     * ---------------------------------------------------------- */

    /**
     * Create or update one address.
     *
     * @param array<string, mixed> $input
     * @return int the address id
     */
    public function save(int $userId, array $input, ?int $id = null): int
    {
        $errors = $this->validate($input);

        if ($errors !== []) {
            throw new InvalidArgumentException((string) reset($errors));
        }

        $payload = $this->normalise($input);

        return $this->withTransaction(function () use ($userId, $payload, $id): int {
            $this->lockMember($userId);

            $existing = $id !== null && $id > 0 ? Address::findOwned($id, $userId, $this->pdo) : null;

            if ($id !== null && $id > 0 && $existing === null) {
                throw new InvalidArgumentException('That address could not be found.');
            }

            $first      = Address::countFor($userId, $this->pdo) === 0;
            $makeDefault = ($payload['is_default'] === 1) || $first;
            $wasDefault  = $existing !== null && (int) $existing['is_default'] === 1;

            if ($makeDefault) {
                $this->pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ? AND is_default = 1')
                    ->execute([$userId]);
            }

            $payload['is_default'] = $makeDefault ? 1 : 0;

            if ($existing === null) {
                $this->pdo->prepare(
                    'INSERT INTO addresses
                       (user_id, label, recipient_name, phone, address_line, barangay, city, province,
                        postal, country, billing_name, billing_phone, notes, is_default)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $userId,
                    $payload['label'],
                    $payload['recipient_name'],
                    $payload['phone'],
                    $payload['address_line'],
                    $payload['barangay'],
                    $payload['city'],
                    $payload['province'],
                    $payload['postal'],
                    $payload['country'],
                    $payload['billing_name'],
                    $payload['billing_phone'],
                    $payload['notes'],
                    $payload['is_default'],
                ]);

                $addressId = (int) $this->pdo->lastInsertId();
            } else {
                $addressId = (int) $id;
                $this->pdo->prepare(
                    'UPDATE addresses
                        SET label = ?, recipient_name = ?, phone = ?, address_line = ?, barangay = ?,
                            city = ?, province = ?, postal = ?, country = ?, billing_name = ?,
                            billing_phone = ?, notes = ?, is_default = ?
                      WHERE id = ? AND user_id = ?'
                )->execute([
                    $payload['label'],
                    $payload['recipient_name'],
                    $payload['phone'],
                    $payload['address_line'],
                    $payload['barangay'],
                    $payload['city'],
                    $payload['province'],
                    $payload['postal'],
                    $payload['country'],
                    $payload['billing_name'],
                    $payload['billing_phone'],
                    $payload['notes'],
                    $payload['is_default'],
                    $addressId,
                    $userId,
                ]);
            }

            /* Editing or deleting the default must never leave the
             * member without one (unless the book is empty). */
            if (($wasDefault && !$makeDefault) || !$this->hasDefault($userId)) {
                $this->promoteDefault($userId);
            }

            return $addressId;
        });
    }

    /**
     * Save the address typed at checkout for next time. The member's
     * first address becomes the default; later ones stay non-default.
     *
     * @param array<string, mixed> $checkout checkout input (shipping_* and billing_* keys)
     */
    public function saveFromCheckout(int $userId, array $checkout): int
    {
        return $this->save($userId, [
            'label'          => '',
            'recipient_name' => (string) ($checkout['shipping_name'] ?? ''),
            'phone'          => (string) ($checkout['shipping_phone'] ?? ''),
            'address_line'   => (string) ($checkout['shipping_address'] ?? ''),
            'barangay'       => (string) ($checkout['shipping_barangay'] ?? ''),
            'city'           => (string) ($checkout['shipping_city'] ?? ''),
            'province'       => (string) ($checkout['shipping_province'] ?? ''),
            'postal'         => (string) ($checkout['shipping_postal'] ?? ''),
            'billing_name'   => (string) ($checkout['billing_name'] ?? ''),
            'billing_phone'  => (string) ($checkout['billing_phone'] ?? ''),
            'notes'          => (string) ($checkout['notes_member'] ?? ''),
            'is_default'     => 0,
        ]);
    }

    /** Delete one of the member's addresses. */
    public function remove(int $id, int $userId): bool
    {
        return $this->withTransaction(function () use ($id, $userId): bool {
            $this->lockMember($userId);

            $address = Address::findOwned($id, $userId, $this->pdo);

            if ($address === null) {
                return false;
            }

            $this->pdo->prepare('DELETE FROM addresses WHERE id = ? AND user_id = ?')->execute([$id, $userId]);

            if ((int) $address['is_default'] === 1) {
                $this->promoteDefault($userId);
            }

            return true;
        });
    }

    /** Mark an address as the member's default (clearing the old one). */
    public function setDefault(int $id, int $userId): bool
    {
        return $this->withTransaction(function () use ($id, $userId): bool {
            $this->lockMember($userId);

            if (Address::findOwned($id, $userId, $this->pdo) === null) {
                return false;
            }

            $this->pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?')
                ->execute([$id, $userId]);

            return true;
        });
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @param array<string, mixed> $input */
    private function normalise(array $input): array
    {
        $text = static function (string $key, int $max = 255) use ($input): ?string {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value === '') {
                return null;
            }

            return mb_substr($value, 0, $max);
        };

        return [
            'label'          => $text('label', 40),
            'recipient_name' => (string) $text('recipient_name', 120),
            'phone'          => $text('phone', 40),
            'address_line'   => (string) $text('address_line', 255),
            'barangay'       => $text('barangay', 120),
            'city'           => $text('city', 80),
            'province'       => $text('province', 80),
            'postal'         => $text('postal', 20),
            'country'        => strtoupper(substr(trim((string) ($input['country'] ?? 'PH')), 0, 2)) ?: 'PH',
            'billing_name'   => $text('billing_name', 120),
            'billing_phone'  => $text('billing_phone', 40),
            'notes'          => $text('notes', 500),
            'is_default'     => (int) ($input['is_default'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    private function lockMember(int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);

        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Your account could not be loaded.');
        }
    }

    private function hasDefault(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM addresses WHERE user_id = ? AND is_default = 1 LIMIT 1');
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Make the newest remaining address the default (no-op when empty). */
    private function promoteDefault(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM addresses WHERE user_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return;
        }

        $this->pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?')
            ->execute([(int) $id, $userId]);
    }

    /** Share a caller's transaction, or protect the outer one with a savepoint. */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'address_sp_' . (++self::$savepointSeq);
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
                    /* outer transaction already unusable */
                }
            }

            throw $e;
        }
    }
}
