<?php

/**
 * @file   models/Code.php
 * @brief  Registration codes management model
 */
class Code
{
    /**
     * Generate $qty unique registration codes and insert them.
     * Returns the generated code strings.
     */
    public static function generate(
        int $packageId,
        int $qty,
        float $price,
        ?string $expiresAt,
        int $adminId,
        string $codeType = 'registration'
    ): array {
        $pdo   = db();
        $codes = [];
        $st    = $pdo->prepare("
            INSERT INTO reg_codes (code, package_id, price, expires_at, created_by, code_type)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        for ($i = 0; $i < $qty; $i++) {
            // Guarantee uniqueness
            do {
                $code = generate_code();
                if ($codeType === 'cd') {
                    $code = 'CD-' . $code;
                } elseif ($codeType === 'upgrade') {
                    $code = 'UP-' . $code;
                }
                $exists = $pdo->query("SELECT COUNT(*) FROM reg_codes WHERE code = '{$code}'")->fetchColumn();
            } while ($exists);

            $st->execute([$code, $packageId, $price, $expiresAt ?: null, $adminId, $codeType]);
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Validate a code for registration.
     * Returns the code row + package info, or null if invalid.
     */
    public static function validate(string $code): ?array
    {
        $code = strtoupper(trim($code));
        $st   = db()->prepare("
            SELECT r.*, p.name AS package_name, p.entry_fee,
                   p.pairing_bonus, p.daily_pair_cap, p.direct_ref_bonus
            FROM   reg_codes r
            JOIN   packages  p ON p.id = r.package_id
            WHERE  r.code = ? AND r.status = 'unused'
        ");
        $st->execute([$code]);
        $row = $st->fetch();

        if (!$row) return null;

        // Check expiry
        if ($row['expires_at'] && strtotime($row['expires_at']) < strtotime('today')) {
            db()->prepare("UPDATE reg_codes SET status='expired' WHERE id=?")->execute([$row['id']]);
            return null;
        }

        return $row;
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare("
            SELECT r.*, p.name AS package_name,
                   u.username AS used_by_username,
                   a.username AS created_by_username
            FROM   reg_codes r
            JOIN   packages  p ON p.id  = r.package_id
            LEFT JOIN users  u ON u.id  = r.used_by
            LEFT JOIN users  a ON a.id  = r.created_by
            WHERE  r.id = ?
        ");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Paginated list for admin.
     */
    public static function all(int $page = 1, string $status = '', int $pkgId = 0, int $perPage = 10): array
    {
        $where  = '1=1';
        $params = [];
        if ($status) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        if ($pkgId) {
            $where .= ' AND r.package_id = ?';
            $params[] = $pkgId;
        }

        return paginate(
            "SELECT r.*, p.name AS package_name,
                    u.username AS used_by_username
             FROM   reg_codes r
             JOIN   packages  p ON p.id = r.package_id
             LEFT JOIN users  u ON u.id = r.used_by
             WHERE  {$where}
             ORDER BY r.created_at DESC",
            $params,
            $page,
            $perPage
        );
    }

    /**
     * Export filtered codes to CSV (streams directly).
     */
    public static function exportCSV(string $status = '', int $pkgId = 0): void
    {
        $where  = '1=1';
        $params = [];
        if ($status) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        if ($pkgId) {
            $where .= ' AND r.package_id = ?';
            $params[] = $pkgId;
        }

        $st = db()->prepare("
            SELECT r.code, p.name AS package, r.price,
                   r.status, r.code_type, r.created_at, r.expires_at,
                   u.username AS used_by
            FROM   reg_codes r
            JOIN   packages  p ON p.id = r.package_id
            LEFT JOIN users  u ON u.id = r.used_by
            WHERE  {$where}
            ORDER BY r.created_at DESC
        ");
        $st->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reg_codes_' . date('Y-m-d') . '.csv"');
        $f = fopen('php://output', 'w');
        fputcsv($f, ['Code', 'Package', 'Price', 'Status', 'Type', 'Created', 'Expires', 'Used By']);
        while ($row = $st->fetch()) {
            fputcsv($f, [
                $row['code'],
                $row['package'],
                number_format($row['price'], 2),
                $row['status'],
                $row['code_type'],
                $row['created_at'],
                $row['expires_at'] ?? '',
                $row['used_by'] ?? '',
            ]);
        }
        fclose($f);
        exit;
    }

    public static function stats(): array
    {
        return db()->query("
            SELECT
              COUNT(*)                                                AS total,
              SUM(CASE WHEN status='unused'  THEN 1 ELSE 0 END)      AS unused,
              SUM(CASE WHEN status='used'    THEN 1 ELSE 0 END)       AS used,
              SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END)       AS expired,
              SUM(CASE WHEN status='used'    THEN price ELSE 0 END)   AS revenue
            FROM reg_codes
        ")->fetch();
    }
}
