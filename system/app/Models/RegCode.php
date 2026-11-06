<?php
declare(strict_types=1);

/* ==============================================================
 *  RegCode — registration codes (plan §4.2, §5.10; Phase 9)
 *
 *  Issued by an admin for ONE package, at the price the company or
 *  sponsor sells it for, optionally expiring. The code row is the
 *  auditable record of the sale; the member redeems it once.
 *
 *  States:  unused → used | expired
 *
 *  Format:  three groups of four characters from an unambiguous
 *           32-character set — ABCD-EFGH-JKLM. Stored uppercase;
 *           compared case-insensitively; dashes are optional on input
 *           (CodeService::normalise()).
 *
 *  Redemption is a conditional UPDATE (CodeService::redeem()) whose
 *  row count must be 1 — never a read-then-write race (plan §3.7,
 *  §4.3). This model only reads and writes the admin-visible fields.
 * ============================================================== */

final class RegCode
{
    /** Values `reg_codes.status` accepts. */
    public const STATUSES = ['unused', 'used', 'expired'];

    /** `registration` today; `upgrade` is reserved for a later phase. */
    public const TYPES = ['registration', 'upgrade'];

    /** Characters without look-alikes (no I, O, 0, 1). */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Characters per group and groups per code (ABCD-EFGH-JKLM). */
    public const GROUP = 4;
    public const GROUPS = 3;

    /** Stored/printed pattern. */
    public const PATTERN = '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/';

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::select() . ' WHERE r.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function findByCode(string $code, ?PDO $pdo = null): ?array
    {
        $code = self::normalise($code);

        if ($code === '') {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::select() . ' WHERE r.code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Codes redeemed by one member, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(self::select() . ' WHERE r.used_by = ? ORDER BY r.used_at DESC, r.id DESC');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  Admin list / filters
     * ---------------------------------------------------------- */

    /** @return array{q:string, status:string, package:string} */
    public static function filterDefaults(): array
    {
        return ['q' => '', 'status' => '', 'package' => ''];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{q:string, status:string, package:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $filters['q'] = trim((string) ($raw['q'] ?? ''));

        $status = strtolower(trim((string) ($raw['status'] ?? '')));
        if (in_array($status, self::STATUSES, true)) {
            $filters['status'] = $status;
        }

        $package = trim((string) ($raw['package'] ?? ''));
        if (ctype_digit($package) && (int) $package > 0) {
            $filters['package'] = (string) (int) $package;
        }

        return $filters;
    }

    /**
     * @param array{q:string, status:string, package:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($filters['status'] !== '') {
            $where[]  = 'r.status = ?';
            $params[] = $filters['status'];
        }

        if ($filters['package'] !== '') {
            $where[]  = 'r.package_id = ?';
            $params[] = (int) $filters['package'];
        }

        if ($filters['q'] !== '') {
            $like     = '%' . like_escape(strtoupper($filters['q'])) . '%';
            $where[]  = '(r.code LIKE ? OR u.username LIKE ? OR a.username LIKE ?)';
            $params[] = $like;
            $params[] = '%' . like_escape($filters['q']) . '%';
            $params[] = '%' . like_escape($filters['q']) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array{q:string, status:string, package:string} $filters */
    public static function countAdmin(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM reg_codes r
               LEFT JOIN users u ON u.id = r.used_by
               LEFT JOIN users a ON a.id = r.created_by
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of codes, newest first.
     *
     * @param array{q:string, status:string, package:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $sql = self::select()
            . ' WHERE ' . $where
            . ' ORDER BY r.created_at DESC, r.id DESC'
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Every matching code for the CSV export (no pagination).
     *
     * @param array{q:string, status:string, package:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function exportRows(array $filters, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            self::select() . ' WHERE ' . $where . ' ORDER BY r.created_at DESC, r.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Header counters (plan §5.10: total / unused / used / expired /
     * revenue from used codes).
     *
     * @return array{total:int, unused:int, used:int, expired:int, revenue:float}
     */
    public static function stats(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COUNT(CASE WHEN status = 'unused'  THEN 1 END) AS unused_count,
                    COUNT(CASE WHEN status = 'used'    THEN 1 END) AS used_count,
                    COUNT(CASE WHEN status = 'expired' THEN 1 END) AS expired_count,
                    COALESCE(SUM(CASE WHEN status = 'used' THEN price ELSE 0 END), 0) AS revenue
               FROM reg_codes"
        )->fetch();

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'unused'  => (int) ($row['unused_count'] ?? 0),
            'used'    => (int) ($row['used_count'] ?? 0),
            'expired' => (int) ($row['expired_count'] ?? 0),
            'revenue' => round((float) ($row['revenue'] ?? 0), 2),
        ];
    }

    /* ----------------------------------------------------------
     *  Code formatting helpers
     * ---------------------------------------------------------- */

    /**
     * Normalise a typed code to ABCD-EFGH-JKLM.
     *
     * Accepts lower case, spaces and an ungrouped 12-character value
     * ("abcdefghjklm"). Anything that is not 12 alphanumeric characters
     * becomes '' so callers can treat it as invalid without guessing.
     */
    public static function normalise(string $code): string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)) ?? '');

        if (strlen($value) !== self::GROUP * self::GROUPS) {
            return '';
        }

        return implode('-', str_split($value, self::GROUP));
    }

    /** Is this a structurally valid code (before it is looked up)? */
    public static function isValidFormat(string $code): bool
    {
        return preg_match(self::PATTERN, self::normalise($code)) === 1;
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private static function select(): string
    {
        return 'SELECT r.*, p.name AS package_name, p.slug AS package_slug,
                       p.price AS package_price, p.status AS package_status,
                       u.username AS used_by_username,
                       a.username AS created_by_username
                  FROM reg_codes r
                  JOIN packages p ON p.id = r.package_id
                  LEFT JOIN users u ON u.id = r.used_by
                  LEFT JOIN users a ON a.id = r.created_by';
    }
}
