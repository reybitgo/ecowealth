<?php
declare(strict_types=1);

/* ==============================================================
 *  Member — the membership record behind a user account
 *  (plan §4.4, §5.10, §7 Phase 9)
 *
 *  The sponsor chain is the ONLY upline structure in this build:
 *  `users.sponsor_id` → `users.id`. There is no binary tree, no
 *  left/right leg, no placement mode (plan §1.3, §2.3).
 *
 *  Status machine (plan §4.4):
 *
 *      pending ──(first package approved)──▶ active
 *      active  ──(admin suspend)──────────▶ suspended
 *      suspended/inactive ──(admin restore)──▶ active
 *
 *  Suspend/restore writes `is_active` as well as `member_status`, so
 *  a suspended member cannot sign in (auth_user() drops the row) and
 *  the admin list still shows why. Every status change is audited.
 *
 *  Reads only otherwise — money lives in WalletService, package
 *  changes in ActivationService.
 * ============================================================== */

final class Member
{
    /** Values `users.member_status` accepts (plan §4.4). */
    public const STATUSES = ['pending', 'active', 'inactive', 'suspended'];

    /** Statuses an admin may set from the member page. */
    public const ASSIGNABLE = ['active', 'inactive', 'pending', 'suspended'];

    /**
     * Member row + its package and sponsor (the shape pages render).
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::baseSelect() . ' WHERE u.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function findByUsername(string $username, ?PDO $pdo = null): ?array
    {
        $username = trim($username);

        if ($username === '') {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::baseSelect() . ' WHERE u.username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function findByReferralCode(string $code, ?PDO $pdo = null): ?array
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::baseSelect() . ' WHERE u.referral_code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function findByEmail(string $email, ?PDO $pdo = null): ?array
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(self::baseSelect() . ' WHERE u.email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public static function usernameExists(string $username, ?int $ignoreId = null, ?PDO $pdo = null): bool
    {
        return self::exists('username', trim($username), $ignoreId, $pdo);
    }

    public static function emailExists(string $email, ?int $ignoreId = null, ?PDO $pdo = null): bool
    {
        return self::exists('email', strtolower(trim($email)), $ignoreId, $pdo);
    }

    public static function referralCodeExists(string $code, ?PDO $pdo = null): bool
    {
        return self::exists('referral_code', strtoupper(trim($code)), null, $pdo);
    }

    /**
     * The one active admin account that receives company revenue
     * (registration e-wallet payments, Phase 12 fees). Mirrors the
     * reference `Auth::actingAdminId()` primary-admin rule.
     *
     * @return array<string, mixed>|null
     */
    public static function primaryAdmin(?PDO $pdo = null): ?array
    {
        $pdo ??= db();

        $stmt = $pdo->query(
            "SELECT id, username, role FROM users
              WHERE role = 'admin' AND is_active = 1
              ORDER BY id ASC
              LIMIT 1"
        );
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /* ----------------------------------------------------------
     *  Admin directory
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
        if ($package === 'none') {
            $filters['package'] = 'none';
        } elseif (ctype_digit($package) && (int) $package > 0) {
            $filters['package'] = (string) (int) $package;
        }

        return $filters;
    }

    /**
     * WHERE clause shared by the count and the page.
     *
     * @param array{q:string, status:string, package:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(array $filters): array
    {
        $where  = ["u.role = 'member'"];
        $params = [];

        if ($filters['q'] !== '') {
            $like     = '%' . like_escape($filters['q']) . '%';
            $where[]  = '(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?'
                . ' OR u.referral_code LIKE ? OR sp.username LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($filters['status'] !== '') {
            $where[]  = 'u.member_status = ?';
            $params[] = $filters['status'];
        }

        if ($filters['package'] === 'none') {
            $where[] = 'u.package_id IS NULL';
        } elseif ($filters['package'] !== '') {
            $where[]  = 'u.package_id = ?';
            $params[] = (int) $filters['package'];
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array{q:string, status:string, package:string} $filters */
    public static function countAdmin(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM users u LEFT JOIN users sp ON sp.id = u.sponsor_id WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of the member directory.
     *
     * @param array{q:string, status:string, package:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $sql = 'SELECT u.id, u.username, u.email, u.full_name, u.phone, u.member_status,
                       u.is_active, u.referral_code, u.reg_payment_method, u.activated_at,
                       u.ewallet_balance, u.withdrawable_balance, u.last_login_at, u.created_at,
                       p.name AS package_name,
                       sp.username AS sponsor_username
                  FROM users u
                  LEFT JOIN packages p ON p.id = u.package_id
                  LEFT JOIN users sp ON sp.id = u.sponsor_id
                 WHERE ' . $where . '
                 ORDER BY u.created_at DESC, u.id DESC
                 LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Counters for the directory header.
     *
     * @return array{total:int, active:int, pending:int, suspended:int, with_package:int, new_week:int}
     */
    public static function stats(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COUNT(CASE WHEN member_status = 'active' THEN 1 END) AS active,
                    COUNT(CASE WHEN member_status = 'pending' THEN 1 END) AS pending,
                    COUNT(CASE WHEN member_status = 'suspended' THEN 1 END) AS suspended,
                    COUNT(CASE WHEN package_id IS NOT NULL THEN 1 END) AS with_package,
                    COUNT(CASE WHEN created_at >= CURDATE() - INTERVAL 6 DAY THEN 1 END) AS new_week
               FROM users
              WHERE role = 'member'"
        )->fetch();

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'active'       => (int) ($row['active'] ?? 0),
            'pending'      => (int) ($row['pending'] ?? 0),
            'suspended'    => (int) ($row['suspended'] ?? 0),
            'with_package' => (int) ($row['with_package'] ?? 0),
            'new_week'     => (int) ($row['new_week'] ?? 0),
        ];
    }

    /**
     * Directory by package (for the package filter and stats).
     *
     * @return list<array{id:int, name:string, members:int}>
     */
    public static function packageBreakdown(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $rows = $pdo->query(
            "SELECT p.id, p.name, COUNT(u.id) AS members
               FROM packages p
               LEFT JOIN users u ON u.package_id = p.id AND u.role = 'member'
              GROUP BY p.id, p.name
              ORDER BY p.sort_order ASC, p.price ASC"
        )->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'members' => (int) $row['members'],
            ],
            $rows
        );
    }

    /* ----------------------------------------------------------
     *  Admin status changes (audited)
     * ---------------------------------------------------------- */

    /**
     * Suspend or restore a member (plan §7 Phase 9).
     *
     * `suspended` also clears `is_active`, which is what stops the
     * login and drops an existing session on the next request.
     * Restoring picks the status the member would have without the
     * suspension: `active` when a package was paid for (or the
     * account joined as standard), otherwise `pending`.
     *
     * @param array<string, mixed>|null $actor the signed-in admin
     * @return array<string, mixed> the refreshed member row
     */
    public static function setStatus(int $memberId, string $status, ?array $actor = null, string $reason = '', ?PDO $pdo = null): array
    {
        if (!in_array($status, self::ASSIGNABLE, true)) {
            throw new InvalidArgumentException('Unknown member status: ' . $status);
        }

        $pdo  ??= db();
        $owns   = !$pdo->inTransaction();

        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT id, username, role, package_id, reg_payment_method, member_status, is_active
                   FROM users
                  WHERE id = ?
                  LIMIT 1
                  FOR UPDATE'
            );
            $stmt->execute([$memberId]);
            $member = $stmt->fetch();

            if ($member === false) {
                throw new InvalidArgumentException('That member could not be found.');
            }

            if (in_array((string) $member['role'], ['admin', 'superadmin'], true)) {
                throw new InvalidArgumentException('Staff accounts are managed from the account settings, not the member list.');
            }

            if ($status === 'active'
                && (string) $member['member_status'] === 'suspended'
                && $member['package_id'] === null
                && (string) $member['reg_payment_method'] !== 'standard') {
                /* A suspended pending member restores to pending, not active. */
                $status = 'pending';
            }

            $isActive = $status === 'suspended' ? 0 : 1;

            $pdo->prepare('UPDATE users SET member_status = ?, is_active = ? WHERE id = ?')
                ->execute([$status, $isActive, $memberId]);

            AuditLog::record([
                'action'       => 'member.status',
                'actor_id'     => $actor === null ? null : (int) ($actor['id'] ?? 0),
                'actor_role'   => $actor === null ? null : (string) ($actor['role'] ?? ''),
                'subject_type' => 'user',
                'subject_id'   => $memberId,
                'meta'         => [
                    'from'     => (string) $member['member_status'],
                    'to'       => $status,
                    'is_active' => $isActive,
                    'reason'   => mb_substr(trim($reason), 0, 160),
                ],
            ], $pdo);

            Live::touch('admin.dashboard');

            if ($owns) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $member = self::find($memberId, $pdo);

        if ($member === null) {
            throw new RuntimeException('The member row disappeared after the status change.');
        }

        return $member;
    }

    /**
     * Flip a member between suspended and restored (reference
     * `admin_toggle_user`). Uses setStatus() so the audit trail and the
     * login flag always move together.
     *
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public static function toggleStatus(int $memberId, ?array $actor = null, ?PDO $pdo = null): array
    {
        $member = self::find($memberId, $pdo);

        if ($member === null) {
            throw new InvalidArgumentException('That member could not be found.');
        }

        $next = (string) $member['member_status'] === 'suspended' ? 'active' : 'suspended';

        return self::setStatus($memberId, $next, $actor, 'Toggle', $pdo);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private static function baseSelect(): string
    {
        return 'SELECT u.*, p.name AS package_name, p.slug AS package_slug,
                       p.price AS package_price, p.direct_referral_bonus,
                       sp.username AS sponsor_username,
                       sp.referral_code AS sponsor_referral_code
                  FROM users u
                  LEFT JOIN packages p ON p.id = u.package_id
                  LEFT JOIN users sp ON sp.id = u.sponsor_id';
    }

    private static function exists(string $column, string $value, ?int $ignoreId, ?PDO $pdo): bool
    {
        if ($value === '') {
            return false;
        }

        $pdo ??= db();

        $sql    = 'SELECT COUNT(*) FROM users WHERE `' . $column . '` = ?';
        $params = [$value];

        if ($ignoreId !== null && $ignoreId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }
}
