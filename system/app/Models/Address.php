<?php
declare(strict_types=1);

/* ==============================================================
 *  Address — read layer for the member's address book (Phase 6)
 *
 *  Every read is scoped by the signed-in member, exactly like the
 *  cart: a posted address id alone is never proof of ownership.
 *  Writes and the one-default rule live in AddressService.
 * ============================================================== */

final class Address
{
    /** @return array<string, mixed>|null */
    public static function findOwned(int $id, int $userId, ?PDO $pdo = null): ?array
    {
        if ($id <= 0 || $userId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM addresses WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function forUser(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function defaultFor(int $userId, ?PDO $pdo = null): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM addresses WHERE user_id = ? AND is_default = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    public static function countFor(int $userId, ?PDO $pdo = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = ($pdo ?? db())->prepare('SELECT COUNT(*) FROM addresses WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }
}
