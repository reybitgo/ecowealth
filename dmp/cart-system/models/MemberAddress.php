<?php
/**
 * @file   models/MemberAddress.php
 * @brief  The member's saved delivery addresses (address book). One row may be
 *         flagged default; the checkout page uses it to prefill the Delivery
 *         details form. Every read and write is member-scoped.
 */
class MemberAddress
{
    /** Cap per member — a saved-address list is a convenience, not storage. */
    public const MAX_ADDRESSES = 10;

    /** Allow-list of columns a caller may set, with their max lengths. */
    private const FIELDS = [
        'label'          => 40,
        'recipient_name' => 120,
        'phone'          => 40,
        'address_line'   => 255,
        'barangay'       => 120,
        'city'           => 80,
        'province'       => 80,
        'region'         => 80,
        'postal'         => 20,
        'country'        => 2,
        'region_code'    => 20,
        'province_code'  => 20,
        'city_code'      => 20,
        'barangay_code'  => 20,
        'billing_name'   => 120,
        'billing_phone'  => 40,
        'notes_member'   => 500,
    ];

    /** Default address first, then most recent. */
    public static function forMember(int $memberId): array
    {
        $st = db()->prepare(
            "SELECT * FROM member_addresses WHERE member_id = ?
             ORDER BY is_default DESC, created_at DESC, id DESC"
        );
        $st->execute([$memberId]);
        return $st->fetchAll();
    }

    /** One address, only if it belongs to the member. */
    public static function find(int $id, int $memberId): ?array
    {
        $st = db()->prepare("SELECT * FROM member_addresses WHERE id = ? AND member_id = ?");
        $st->execute([$id, $memberId]);
        return $st->fetch() ?: null;
    }

    public static function defaultFor(int $memberId): ?array
    {
        $st = db()->prepare(
            "SELECT * FROM member_addresses WHERE member_id = ? AND is_default = 1
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $st->execute([$memberId]);
        return $st->fetch() ?: null;
    }

    public static function countForMember(int $memberId): int
    {
        $st = db()->prepare("SELECT COUNT(*) FROM member_addresses WHERE member_id = ?");
        $st->execute([$memberId]);
        return (int) $st->fetchColumn();
    }

    /** @return int the new row id */
    public static function create(int $memberId, array $data): int
    {
        $pdo       = db();
        $fields    = self::normalise($data);
        $isFirst   = self::countForMember($memberId) === 0;
        $isDefault = !empty($data['is_default']) || $isFirst;

        if ($isDefault) {
            self::clearDefaults($memberId);
        }

        $st = $pdo->prepare(
            "INSERT INTO member_addresses
                (member_id, label, recipient_name, phone, address_line, barangay, city, province, region, postal, country, region_code, province_code, city_code, barangay_code, billing_name, billing_phone, notes_member, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $memberId,
            $fields['label'],
            $fields['recipient_name'],
            $fields['phone'],
            $fields['address_line'],
            $fields['barangay'],
            $fields['city'],
            $fields['province'],
            $fields['region'],
            $fields['postal'],
            $fields['country'],
            $fields['region_code'],
            $fields['province_code'],
            $fields['city_code'],
            $fields['barangay_code'],
            $fields['billing_name'],
            $fields['billing_phone'],
            $fields['notes_member'],
            $isDefault ? 1 : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, int $memberId, array $data): bool
    {
        if (!self::find($id, $memberId)) {
            return false;
        }
        $fields    = self::normalise($data);
        $isDefault = !empty($data['is_default']);
        if ($isDefault) {
            self::clearDefaults($memberId);
        }

        $st = db()->prepare(
            "UPDATE member_addresses SET
                label = ?, recipient_name = ?, phone = ?, address_line = ?,
                barangay = ?, city = ?, province = ?, region = ?,
                postal = ?, country = ?,
                region_code = ?, province_code = ?, city_code = ?, barangay_code = ?,
                billing_name = ?, billing_phone = ?, notes_member = ?,
                is_default = ?
             WHERE id = ? AND member_id = ?"
        );
        return $st->execute([
            $fields['label'],
            $fields['recipient_name'],
            $fields['phone'],
            $fields['address_line'],
            $fields['barangay'],
            $fields['city'],
            $fields['province'],
            $fields['region'],
            $fields['postal'],
            $fields['country'],
            $fields['region_code'],
            $fields['province_code'],
            $fields['city_code'],
            $fields['barangay_code'],
            $fields['billing_name'],
            $fields['billing_phone'],
            $fields['notes_member'],
            $isDefault ? 1 : 0,
            $id,
            $memberId,
        ]);
    }

    public static function delete(int $id, int $memberId): bool
    {
        $own = self::find($id, $memberId);
        if (!$own) {
            return false;
        }
        db()->prepare("DELETE FROM member_addresses WHERE id = ? AND member_id = ?")->execute([$id, $memberId]);

        // Deleting the default promotes the newest remaining so a member who
        // still has addresses always keeps a prefill source.
        if ((int) $own['is_default'] === 1) {
            $rows = self::forMember($memberId);
            if (!empty($rows[0]['id'])) {
                self::setDefault((int) $rows[0]['id'], $memberId);
            }
        }
        return true;
    }

    public static function setDefault(int $id, int $memberId): bool
    {
        if (!self::find($id, $memberId)) {
            return false;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            self::clearDefaults($memberId);
            $pdo->prepare("UPDATE member_addresses SET is_default = 1 WHERE id = ? AND member_id = ?")
                ->execute([$id, $memberId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return true;
    }

    private static function clearDefaults(int $memberId): void
    {
        db()->prepare("UPDATE member_addresses SET is_default = 0 WHERE member_id = ?")->execute([$memberId]);
    }

    /** Trim, truncate to column width, and fill missing keys. */
    private static function normalise(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $name => $max) {
            $out[$name] = mb_substr(trim((string) ($data[$name] ?? '')), 0, $max);
        }
        if ($out['country'] === '') {
            $out['country'] = 'PH';
        }
        return $out;
    }
}
