<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryVolume — the product-binary points ledger (22D-S1)
 *  (plan §15.4 `binary_volume`, §14.3, §14.7, §16.2)
 *
 *  One row per point movement: signed `points`, a `kind` whose sign is
 *  fixed by the schema (`own`/`team` positive, `pair`/`reversal`
 *  negative, `pair_return`/`adjustment` either). Append-only: nothing
 *  here is ever updated or deleted, only negated by a reversal row.
 *
 *  The node's `left_points`/`right_points` are a cache of this ledger
 *  and `BinaryService::audit()` proves they agree (INV-2).
 *
 *  Writes live here and are called by BinaryService only, inside the
 *  caller's transaction. Reads are shared by the member page (22F),
 *  the admin ledger (22E) and the audit CLI (22H).
 *
 *  The unique key (node, item, side, kind) guarantees a replay cannot
 *  double-count a line (plan §14.7 rule 4).
 * ============================================================== */

final class BinaryVolume
{
    /** Every kind the schema accepts, with the sign each kind must carry. */
    public const KINDS = ['own', 'team', 'pair', 'pair_return', 'reversal', 'adjustment'];

    /** Kinds that must be positive. */
    public const POSITIVE_KINDS = ['own', 'team', 'pair_return', 'adjustment'];

    /** Kinds that must be negative. */
    public const NEGATIVE_KINDS = ['pair', 'reversal'];

    /** The two sides a point can sit on. */
    public const SIDES = ['left', 'right'];

    private const TABLE = 'binary_volume';

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every volume row for one node, optionally filtered by side and kind.
     *
     * @return list<array<string, mixed>>
     */
    public static function forNode(
        int $userId,
        ?string $side = null,
        ?string $kind = null,
        ?PDO $pdo = null
    ): array {
        if ($userId <= 0) {
            return [];
        }

        $pdo = $pdo ?? db();
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE node_user_id = ?';
        $params = [$userId];

        if ($side !== null) {
            $sql .= ' AND side = ?';
            $params[] = $side;
        }

        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }

        $sql .= ' ORDER BY id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Every volume row one order placed, item order then side. @return list<array<string, mixed>> */
    public static function forOrder(int $orderId, ?PDO $pdo = null): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE order_id = ? ORDER BY order_item_id ASC, side ASC, id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /** Every volume row tied to one award (its pair consumption rows). @return list<array<string, mixed>> */
    public static function forAward(int $awardId, ?PDO $pdo = null): array
    {
        if ($awardId <= 0) {
            return [];
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE award_id = ? ORDER BY side ASC, id ASC'
        );
        $stmt->execute([$awardId]);

        return $stmt->fetchAll();
    }

    /** The unmatched points on one side of one node: the ledger sum. */
    public static function sumBySide(int $userId, string $side, ?PDO $pdo = null): int
    {
        if (!in_array($side, self::SIDES, true) || $userId <= 0) {
            return 0;
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(points), 0) FROM ' . self::TABLE . ' WHERE node_user_id = ? AND side = ?'
        );
        $stmt->execute([$userId, $side]);

        return (int) $stmt->fetchColumn();
    }

    /** The lifetime total of one kind on one node (signed, so pairs reduce). */
    public static function sumByKind(int $userId, string $kind, ?PDO $pdo = null): int
    {
        if (!in_array($kind, self::KINDS, true) || $userId <= 0) {
            return 0;
        }

        $pdo = $pdo ?? db();

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(points), 0) FROM ' . self::TABLE . ' WHERE node_user_id = ? AND kind = ?'
        );
        $stmt->execute([$userId, $kind]);

        return (int) $stmt->fetchColumn();
    }

    /** The available points on each side: `left` and `right` sums. @return array{left:int, right:int} */
    public static function balance(int $userId, ?PDO $pdo = null): array
    {
        return [
            'left'  => self::sumBySide($userId, 'left', $pdo),
            'right' => self::sumBySide($userId, 'right', $pdo),
        ];
    }

    /* ----------------------------------------------------------
     *  Filters and pages (shared by 22E and 22F)
     * ---------------------------------------------------------- */

    /** @return array{side:string, kind:string, from:string, to:string, q:string} */
    public static function filterDefaults(): array
    {
        return [
            'side' => '',
            'kind' => '',
            'from' => '',
            'to'   => '',
            'q'    => '',
        ];
    }

    /**
     * Keep only the filters the queries understand; an unknown value
     * falls back to "no filter".
     *
     * @param array<string, mixed> $raw
     * @return array{side:string, kind:string, from:string, to:string, q:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $side = strtolower(trim((string) ($raw['side'] ?? '')));
        if (in_array($side, self::SIDES, true)) {
            $filters['side'] = $side;
        }

        $kind = strtolower(trim((string) ($raw['kind'] ?? '')));
        if (in_array($kind, self::KINDS, true)) {
            $filters['kind'] = $kind;
        }

        $from = self::date((string) ($raw['from'] ?? ''));
        if ($from !== '') {
            $filters['from'] = $from;
        }

        $to = self::date((string) ($raw['to'] ?? ''));
        if ($to !== '') {
            $filters['to'] = $to;
        }

        $filters['q'] = trim((string) ($raw['q'] ?? ''));

        return $filters;
    }

    /** A valid YYYY-MM-DD date, or '' when the input is not one. */
    private static function date(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return $value;
    }

    /**
     * WHERE clause shared by every read.
     *
     * @param array{side:string, kind:string, from:string, to:string, q:string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereFor(?int $userId, array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($userId !== null) {
            $where[]  = 'v.node_user_id = ?';
            $params[] = $userId;
        }

        if (($filters['side'] ?? '') !== '') {
            $where[]  = 'v.side = ?';
            $params[] = $filters['side'];
        }

        if (($filters['kind'] ?? '') !== '') {
            $where[]  = 'v.kind = ?';
            $params[] = $filters['kind'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[]  = 'v.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]  = 'v.created_at < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        $query = trim((string) ($filters['q'] ?? ''));

        if ($query !== '') {
            $like    = '%' . like_escape($query) . '%';
            $where[] = '(u.username LIKE ? OR b.username LIKE ? OR o.order_no LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $filters */
    public static function countForUser(int $userId, array $filters, ?PDO $pdo = null): int
    {
        return self::countWhere($userId, $filters, $pdo);
    }

    /** @param array<string, mixed> $filters */
    public static function countAdmin(array $filters, ?PDO $pdo = null): int
    {
        return self::countWhere(null, $filters, $pdo);
    }

    /** @param array<string, mixed> $filters */
    private static function countWhere(?int $userId, array $filters, ?PDO $pdo): int
    {
        $pdo = $pdo ?? db();
        [$where, $params] = self::whereFor($userId, $filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM ' . self::TABLE . ' v
               JOIN users u ON u.id = v.node_user_id
               JOIN users b ON b.id = v.buyer_id
               LEFT JOIN shop_orders o ON o.id = v.order_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of one member's volume rows, newest first.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function pageForUser(int $userId, array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere($userId, $filters, $pager, $pdo);
    }

    /**
     * One page across every node (admin view).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        return self::pageWhere(null, $filters, $pager, $pdo);
    }

    /** @param array<string, mixed> $filters */
    private static function pageWhere(?int $userId, array $filters, Paginator $pager, ?PDO $pdo): array
    {
        $pdo = $pdo ?? db();
        [$where, $params] = self::whereFor($userId, $filters);

        $sql = self::select()
            . ' WHERE ' . $where
            . ' ORDER BY v.id DESC'
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Every volume row matching the filters, newest first — the CSV feed. */
    public static function exportRows(?int $userId, array $filters, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        [$where, $params] = self::whereFor($userId, $filters);

        $stmt = $pdo->prepare(
            self::select() . ' WHERE ' . $where . ' ORDER BY v.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------
     *  CSV
     * ---------------------------------------------------------- */

    /** The CSV columns, in the documented order. @return list<string> */
    public static function csvHeader(): array
    {
        return [
            'Row', 'Date', 'Node', 'Side', 'Kind', 'Points', 'Order', 'Item',
            'Buyer', 'Award', 'Note',
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<int, string>> */
    public static function csvRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                (string) $row['id'],
                (string) ($row['created_at'] ?? ''),
                (string) ($row['node_username'] ?? ''),
                (string) ($row['side'] ?? ''),
                (string) ($row['kind'] ?? ''),
                (string) $row['points'],
                (string) ($row['order_no'] ?? ''),
                (string) ($row['order_item_id'] ?? ''),
                (string) ($row['buyer_username'] ?? ''),
                (string) ($row['award_id'] ?? ''),
                (string) ($row['note'] ?? ''),
            ];
        }

        return $out;
    }

    /* ----------------------------------------------------------
     *  Writes — called by BinaryService only
     * ---------------------------------------------------------- */

    /**
     * Insert one volume row.
     *
     * The caller owns the transaction: the row and the order stamp must
     * commit or roll back together. A zero `points` is refused — every
     * row moves a non-zero number of points (INV-1). The sign must match
     * the kind: `own`/`team`/`pair_return`/`adjustment` are positive,
     * `pair`/`reversal` are negative (§15.4's CHECK constraints enforce
     * this too, but we fail fast with a clear message).
     *
     * @param array{
     *   node_user_id:int, side:string, kind:string, points:int,
     *   order_id?:int|string, order_item_id?:int|string, buyer_id?:int|string,
     *   award_id?:int|string, note?:string, created_by?:int|string
     * } $data
     * @return int the new row id
     * @throws InvalidArgumentException when the side/kind/sign combination is invalid
     */
    public static function create(array $data, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?? db();

        $side   = (string) ($data['side'] ?? '');
        $kind   = (string) ($data['kind'] ?? '');
        $points = (int) ($data['points'] ?? 0);

        if (!in_array($side, self::SIDES, true)) {
            throw new InvalidArgumentException('A binary volume row needs side "left" or "right".');
        }

        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown binary volume kind: ' . $kind);
        }

        if ($points === 0) {
            throw new InvalidArgumentException('A binary volume row must move a non-zero number of points.');
        }

        $isPositive = in_array($kind, self::POSITIVE_KINDS, true);

        if ($isPositive && $points < 0) {
            throw new InvalidArgumentException(
                'Kind "' . $kind . '" must be positive, got ' . $points . '.'
            );
        }

        if (! $isPositive && $points > 0) {
            throw new InvalidArgumentException(
                'Kind "' . $kind . '" must be negative, got ' . $points . '.'
            );
        }

        $nodeUserId = (int) ($data['node_user_id'] ?? 0);
        if ($nodeUserId <= 0) {
            throw new InvalidArgumentException('A binary volume row needs a node user.');
        }

        $orderId   = isset($data['order_id'])   && (int) $data['order_id']   > 0 ? (int) $data['order_id']   : null;
        $orderItem = isset($data['order_item_id']) && (int) $data['order_item_id'] > 0 ? (int) $data['order_item_id'] : null;
        $buyerId   = isset($data['buyer_id'])   && (int) $data['buyer_id']   > 0 ? (int) $data['buyer_id']   : null;
        $awardId   = isset($data['award_id'])   && (int) $data['award_id']   > 0 ? (int) $data['award_id']   : null;
        $createdBy = isset($data['created_by']) && (int) $data['created_by'] > 0 ? (int) $data['created_by'] : null;
        $note      = trim((string) ($data['note'] ?? ''));

        $pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
               (node_user_id, side, kind, points, order_id, order_item_id, buyer_id, award_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $nodeUserId,
            $side,
            $kind,
            $points,
            $orderId,
            $orderItem,
            $buyerId,
            $awardId,
            $note === '' ? null : mb_substr($note, 0, 160),
            $createdBy,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    private static function select(): string
    {
        return 'SELECT v.*,
                       u.username  AS node_username,
                       b.username  AS buyer_username,
                       o.order_no  AS order_no
                  FROM ' . self::TABLE . ' v
                  JOIN users u  ON u.id = v.node_user_id
                  JOIN users b  ON b.id = v.buyer_id
                  LEFT JOIN shop_orders o ON o.id = v.order_id';
    }

    /** Does this connection's schema still carry the Phase 22A table? */
    public static function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['binary_volume']);

        return (int) $stmt->fetchColumn() > 0;
    }
}
