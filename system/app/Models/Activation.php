<?php
declare(strict_types=1);

/* ==============================================================
 *  Activation — one package purchase attempt (plan §4.4, §5.10)
 *
 *  States
 *      pending_payment → approved      (wallet debit / code redemption)
 *      pending_payment → pending_review → approved | rejected
 *      pending_*        → cancelled    (member gives up)
 *
 *  Registration paid by code or e-wallet writes an already-approved
 *  row inside the same transaction, so every commission in Phases
 *  10–11 can point at the activation that produced it (plan §6.2).
 *
 *  `commission_processed_at` is the idempotency anchor: stamped in the
 *  transaction that runs the commission hook, and checked again by
 *  ActivationService before any second approval can proceed.
 *
 *  Money and member-state changes live in ActivationService; this
 *  model reads rows and writes the review fields only.
 * ============================================================== */

final class Activation
{
    /** Values `activations.status` accepts. */
    public const STATUSES = ['pending_payment', 'pending_review', 'approved', 'rejected', 'cancelled'];

    /** A request the member has not finished yet. */
    public const OPEN = ['pending_payment', 'pending_review'];

    /** Values `activations.payment_method` accepts. */
    public const METHODS = ['ewallet', 'code', 'gcash', 'maya', 'bank'];

    /** Manual (proof-backed) methods. */
    public const MANUAL = ['gcash', 'maya', 'bank'];

    /** The admin review queue. */
    public const REVIEW = ['pending_review'];

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

        $stmt = $pdo->prepare(self::select() . ' WHERE a.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Activation history for one member, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(self::select() . ' WHERE a.user_id = ? ORDER BY a.id DESC');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** How many activation requests a member has ever made. */
    public static function countForUser(int $userId, ?PDO $pdo = null): int
    {
        $pdo ??= db();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM activations WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of a member's activation history, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function pageForUser(int $userId, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $sql = self::select()
            . ' WHERE a.user_id = ?'
            . ' ORDER BY a.id DESC'
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * The member's unfinished request, if any (one at a time).
     *
     * @return array<string, mixed>|null
     */
    public static function latestOpen(int $userId, ?PDO $pdo = null): ?array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(
            self::select() . " WHERE a.user_id = ? AND a.status IN ('pending_payment','pending_review')
                                ORDER BY a.id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Has this member ever been approved (a package activation exists)?
     */
    public static function hasApproved(int $userId, ?PDO $pdo = null): bool
    {
        $pdo ??= db();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activations WHERE user_id = ? AND status = 'approved'");
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /* ----------------------------------------------------------
     *  Writes
     * ---------------------------------------------------------- */

    /**
     * Insert one activation row.
     *
     * @param array{
     *   user_id:int, package_id:int, amount:float|string, payment_method:string,
     *   status?:string, reg_code_id?:int|null, reference_no?:string|null,
     *   proof_path?:string|null
     * } $data
     */
    public static function create(array $data, ?PDO $pdo = null): int
    {
        $pdo ??= db();

        $status = (string) ($data['status'] ?? 'pending_payment');

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown activation status: ' . $status);
        }

        $method = (string) ($data['payment_method'] ?? '');

        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Unknown activation payment method: ' . $method);
        }

        $pdo->prepare(
            'INSERT INTO activations
               (user_id, package_id, amount, payment_method, status, reg_code_id, reference_no, proof_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $data['user_id'],
            (int) $data['package_id'],
            number_format((float) $data['amount'], 2, '.', ''),
            $method,
            $status,
            isset($data['reg_code_id']) && (int) $data['reg_code_id'] > 0 ? (int) $data['reg_code_id'] : null,
            self::trimOrNull((string) ($data['reference_no'] ?? '')),
            self::trimOrNull((string) ($data['proof_path'] ?? '')),
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function markApproved(int $id, ?int $reviewerId, ?PDO $pdo = null): void
    {
        $pdo ??= db();

        $pdo->prepare(
            "UPDATE activations
                SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), reject_reason = NULL
              WHERE id = ?"
        )->execute([$reviewerId !== null && $reviewerId > 0 ? $reviewerId : null, $id]);
    }

    public static function markRejected(int $id, int $reviewerId, string $reason, ?PDO $pdo = null): void
    {
        $pdo ??= db();

        $pdo->prepare(
            "UPDATE activations
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), reject_reason = ?
              WHERE id = ?"
        )->execute([
            $reviewerId > 0 ? $reviewerId : null,
            mb_substr(trim($reason), 0, 160),
            $id,
        ]);
    }

    /** Stamp the commission hook so a second approval cannot pay twice. */
    public static function markCommissionProcessed(int $id, ?PDO $pdo = null): void
    {
        $pdo ??= db();

        $pdo->prepare('UPDATE activations SET commission_processed_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /* ----------------------------------------------------------
     *  Admin queue
     * ---------------------------------------------------------- */

    /** @return array{status:string, method:string, q:string} */
    public static function filterDefaults(): array
    {
        return ['status' => '', 'method' => '', 'q' => ''];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{status:string, method:string, q:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $status = strtolower(trim((string) ($raw['status'] ?? '')));
        if (in_array($status, self::STATUSES, true)) {
            $filters['status'] = $status;
        }

        $method = strtolower(trim((string) ($raw['method'] ?? '')));
        if (in_array($method, self::METHODS, true)) {
            $filters['method'] = $method;
        }

        $filters['q'] = trim((string) ($raw['q'] ?? ''));

        return $filters;
    }

    /**
     * @param array{status:string, method:string, q:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($filters['status'] !== '') {
            $where[]  = 'a.status = ?';
            $params[] = $filters['status'];
        }

        if ($filters['method'] !== '') {
            $where[]  = 'a.payment_method = ?';
            $params[] = $filters['method'];
        }

        if ($filters['q'] !== '') {
            $like     = '%' . like_escape($filters['q']) . '%';
            $where[]  = '(u.username LIKE ? OR u.full_name LIKE ? OR p.name LIKE ? OR a.reference_no LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array{status:string, method:string, q:string} $filters */
    public static function countAdmin(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM activations a
               JOIN users u ON u.id = a.user_id
               JOIN packages p ON p.id = a.package_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of the activation queue, newest first.
     *
     * @param array{status:string, method:string, q:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();
        [$where, $params] = self::whereFor($filters);

        $sql = self::select()
            . ' WHERE ' . $where
            . ' ORDER BY FIELD(a.status, \'pending_review\', \'pending_payment\') DESC, a.id DESC'
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Counters for the queue header.
     *
     * @return array{total:int, review:int, approved:int, rejected:int, amount_review:float, amount_approved:float}
     */
    public static function stats(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COUNT(CASE WHEN status = 'pending_review' THEN 1 END) AS review_count,
                    COUNT(CASE WHEN status = 'approved'       THEN 1 END) AS approved_count,
                    COUNT(CASE WHEN status = 'rejected'       THEN 1 END) AS rejected_count,
                    COALESCE(SUM(CASE WHEN status = 'pending_review' THEN amount ELSE 0 END), 0) AS amount_review,
                    COALESCE(SUM(CASE WHEN status = 'approved'       THEN amount ELSE 0 END), 0) AS amount_approved
               FROM activations"
        )->fetch();

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'review'         => (int) ($row['review_count'] ?? 0),
            'approved'       => (int) ($row['approved_count'] ?? 0),
            'rejected'       => (int) ($row['rejected_count'] ?? 0),
            'amount_review'  => round((float) ($row['amount_review'] ?? 0), 2),
            'amount_approved' => round((float) ($row['amount_approved'] ?? 0), 2),
        ];
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private static function select(): string
    {
        return 'SELECT a.*, u.username, u.full_name AS user_name, u.email,
                       p.name AS package_name, p.slug AS package_slug,
                       r.username AS reviewer_username
                  FROM activations a
                  JOIN users u ON u.id = a.user_id
                  JOIN packages p ON p.id = a.package_id
                  LEFT JOIN users r ON r.id = a.reviewed_by';
    }

    private static function trimOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
