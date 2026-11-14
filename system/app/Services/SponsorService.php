<?php
declare(strict_types=1);

/* ==============================================================
 *  SponsorService — the sponsor chain, and nothing else
 *  (plan §2.3, §4.3, §5.10, §7 Phase 9)
 *
 *  The V3.0 plan keeps ONE upline link: `users.sponsor_id`. There is
 *  no binary tree, no left/right leg and no placement mode, so every
 *  helper here walks `sponsor_id` upward (upline chain) or downward
 *  (direct recruits / team).
 *
 *  Rules this service owns:
 *    • a sponsor is required at registration (D3) and may be entered
 *      as a username OR as a referral code
 *    • a suspended / deactivated account cannot sponsor
 *    • self-sponsorship and cycles are refused
 *    • referral codes are 8 random characters, unique
 * ============================================================== */

final class SponsorService
{
    /** Plan §7 Phase 9: referral codes are 8 characters. */
    public const REFERRAL_LENGTH = 8;

    /** Upline walk limit (plan §6.4: indirect levels stop at 10). */
    public const MAX_LEVELS = 10;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /**
     * Resolve a typed sponsor: username first, then referral code.
     * Case-insensitive either way (the column collation is _ci).
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $identifier): ?array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $member = Member::findByUsername($identifier, $this->pdo);

        if ($member !== null) {
            return $member;
        }

        return Member::findByReferralCode($identifier, $this->pdo);
    }

    /** May this account sponsor a new member right now? */
    public function isEligible(array $sponsor): bool
    {
        return (int) ($sponsor['is_active'] ?? 0) === 1
            && (string) ($sponsor['member_status'] ?? '') !== 'suspended';
    }

    /**
     * Would making `$sponsorId` the sponsor of `$memberId` create a
     * cycle or a self-reference?
     *
     * Registration never has a member id yet (only self-sponsorship,
     * caught separately), so this mainly protects an admin correction
     * and the tests. The walk is bounded by MAX_LEVELS and keeps a
     * visited set, so a corrupted chain cannot loop.
     */
    public function createsCycle(int $sponsorId, int $memberId): bool
    {
        if ($sponsorId <= 0 || $memberId <= 0) {
            return false;
        }

        if ($sponsorId === $memberId) {
            return true;
        }

        $visited = [];
        $current = $sponsorId;

        for ($hop = 0; $hop < 100 && $current > 0; $hop++) {
            if (isset($visited[$current])) {
                return true;   // the stored chain already loops: refuse
            }

            $visited[$current] = true;

            if ($current === $memberId) {
                return true;
            }

            $current = (int) ($this->sponsorOf($current) ?? 0);
        }

        return false;
    }

    /**
     * The company's default sponsor for a referral-less signup.
     *
     * Free registration is open to everyone, so the form's sponsor field
     * is optional: a blank field means "join under the company account".
     * That account is the primary admin (the first active `admin`, the
     * same account that receives registration revenue), falling back to
     * the lowest-id active account if no admin is usable.
     *
     * @return array<string, mixed>|null
     */
    public function defaultSponsor(?PDO $pdo = null): ?array
    {
        $pdo ??= $this->pdo;

        $admin = Member::primaryAdmin($pdo);

        if ($admin !== null) {
            $row = Member::find((int) $admin['id'], $pdo);

            if ($row !== null && $this->isEligible($row)) {
                return $row;
            }
        }

        $stmt = $pdo->query(
            "SELECT id FROM users
              WHERE is_active = 1 AND member_status <> 'suspended'
              ORDER BY id ASC
              LIMIT 1"
        );
        $fallbackId = (int) ($stmt->fetchColumn() ?: 0);

        if ($fallbackId <= 0) {
            return null;
        }

        $row = Member::find($fallbackId, $pdo);

        return $row !== null && $this->isEligible($row) ? $row : null;
    }

    /**
     * The upline chain, nearest first: level 1 is the direct sponsor,
     * level 2 their sponsor, and so on up to MAX_LEVELS.
     *
     * @return list<array{level:int, id:int, username:string, member:array<string, mixed>}>
     */
    public function chain(int $userId, int $maxLevels = self::MAX_LEVELS): array
    {
        $maxLevels = max(1, min(self::MAX_LEVELS, $maxLevels));
        $chain     = [];
        $visited   = [$userId => true];
        $current   = (int) ($this->sponsorOf($userId) ?? 0);

        for ($level = 1; $level <= $maxLevels && $current > 0; $level++) {
            if (isset($visited[$current])) {
                break;   // defensive: a cycle ends the walk
            }

            $visited[$current] = true;
            $member            = Member::find($current, $this->pdo);

            if ($member === null) {
                break;
            }

            $chain[] = [
                'level'    => $level,
                'id'       => $current,
                'username' => (string) $member['username'],
                'member'   => $member,
            ];

            $current = (int) ($member['sponsor_id'] ?? 0);
        }

        return $chain;
    }

    /**
     * Direct recruits (level 1 of the downline), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function directRecruits(int $userId, ?PDO $pdo = null, ?Paginator $pager = null): array
    {
        $pdo ??= $this->pdo;

        $sql = 'SELECT u.id, u.username, u.full_name, u.email, u.member_status, u.is_active,
                       u.referral_code, u.activated_at, u.created_at,
                       p.name AS package_name
                  FROM users u
                  LEFT JOIN packages p ON p.id = u.package_id
                 WHERE u.sponsor_id = ? AND u.role = ?
                 ORDER BY u.created_at DESC, u.id DESC';

        if ($pager !== null) {
            $sql .= ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, 'member']);

        return $stmt->fetchAll();
    }

    /** How many members this account has personally sponsored. */
    public function countDirectRecruits(int $userId, ?PDO $pdo = null): int
    {
        $pdo ??= $this->pdo;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND role = ?');
        $stmt->execute([$userId, 'member']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The whole downline up to `$maxLevels`, breadth-first so the
     * result is ordered by level (level 1 first, then level 2, …).
     *
     * One query per level (`WHERE sponsor_id IN (…frontier…)`), never
     * one per member, and a visited set so a corrupted chain cannot
     * duplicate or loop. Each row carries its sponsor's username
     * (`sponsor_username`) as well, so the Team by level card can label
     * a row "@member → @sponsor" without a query per row.
     *
     * @return list<array<string, mixed>> each row carries `level` and `sponsor_username`
     */
    public function downline(int $userId, int $maxLevels = self::MAX_LEVELS): array
    {
        $maxLevels = max(1, min(self::MAX_LEVELS, $maxLevels));
        $visited   = [$userId => true];
        $frontier  = [$userId];
        $rows      = [];

        for ($level = 1; $level <= $maxLevels && $frontier !== []; $level++) {
            $ids          = array_values(array_map('intval', $frontier));
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $params       = $ids;
            $params[]     = 'member';

            $stmt = $this->pdo->prepare(
                'SELECT u.id, u.username, u.full_name, u.member_status, u.is_active,
                        u.referral_code, u.activated_at, u.created_at,
                        p.name AS package_name,
                        sp.username AS sponsor_username
                   FROM users u
                   LEFT JOIN packages p ON p.id = u.package_id
                   LEFT JOIN users sp ON sp.id = u.sponsor_id
                  WHERE u.sponsor_id IN (' . $placeholders . ') AND u.role = ?
                  ORDER BY u.created_at ASC, u.id ASC'
            );
            $stmt->execute($params);
            $next = [];

            foreach ($stmt->fetchAll() as $row) {
                $id = (int) $row['id'];

                if (isset($visited[$id])) {
                    continue;
                }

                $visited[$id] = true;
                $row['level'] = $level;
                $rows[]       = $row;
                $next[]       = $id;
            }

            $frontier = $next;
        }

        return $rows;
    }

    /**
     * Team counters for the genealogy header.
     *
     * @return array{direct:int, team:int, levels:int}
     */
    public function teamStats(int $userId, ?PDO $pdo = null): array
    {
        $rows   = $this->downline($userId);
        $levels = [];

        foreach ($rows as $row) {
            $levels[(int) $row['level']] = true;
        }

        return [
            'direct' => count($this->directRecruits($userId, $pdo)),
            'team'   => count($rows),
            'levels' => count($levels),
        ];
    }

    /* ----------------------------------------------------------
     *  Referral codes
     * ---------------------------------------------------------- */

    /**
     * A fresh 8-character referral code that no account uses yet.
     *
     * The unique index on `users.referral_code` is the final guard; the
     * retry loop just keeps a rare collision from failing a signup.
     */
    public function generateReferralCode(): string
    {
        $alphabet = RegCode::ALPHABET;
        $max      = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::REFERRAL_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }

            if (!Member::referralCodeExists($code, $this->pdo)) {
                return $code;
            }
        }

        /* Deterministic fallback: the id is not known yet, so use time +
         * randomness. Practically unreachable (32^8 combinations). */
        return strtoupper(substr(bin2hex(random_bytes(4)) . dechex(time()), 0, self::REFERRAL_LENGTH));
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** The sponsor id of one account (null when unsponsored). */
    private function sponsorOf(int $userId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT sponsor_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
