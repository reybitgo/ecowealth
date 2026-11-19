<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryNode — the product-binary placement tree (Phase 22C)
 *
 *  Phase 22B shipped the vocabulary (KINDS, STATUSES, SIDES, MAX_DEPTH,
 *  POINTS_MAX, STATUS_LABELS, STATUS_BADGES, SIDE_LABELS). Phase 22C
 *  adds the read/query and derived-structure methods: root/node lookup,
 *  children, ancestors/descendants, subtree membership, side/path/depth
 *  derivation, free slots, stats, roster and read-only summaries.
 *
 *  `path` and `depth` are derived, never typed:
 *
 *      depth = upline.depth + 1
 *      path  = upline.path . ('L' | 'R')     (root: depth 0, path '')
 *
 *  Every method is read-only except `ensureRoot()` (the company-root
 *  bootstrap). No method writes placement or money data; `place()` and
 *  `move()` arrive in 22C-S2.
 *
 *  All tree walks use the materialised `path` column (a `LIKE` prefix
 *  scan) so a deep tree never recurses in SQL (plan §14.2 rule 4,
 *  §18.3).
 * ============================================================== */

final class BinaryNode
{
    /** One row per member: the user's own node is the primary key. */
    public const KINDS = ['own', 'team', 'pair', 'pair_return', 'reversal', 'adjustment'];

    /** The two money award kinds (D-10). */
    public const AWARD_KINDS = ['self', 'pair'];

    /** Node life cycle: root (the company), placed, floating (legacy only). */
    public const STATUSES = ['root', 'placed', 'floating'];

    /** A child sits on one side of its upline; (upline_id, side) is unique. */
    public const SIDES = ['left', 'right'];

    /** Depth is bounded so a path never outgrows its column (100 chars). */
    public const MAX_DEPTH = 100;

    /** One product line can place at most one million points (D-05). */
    public const POINTS_MAX = 1_000_000;

    /** Human labels for the node statuses (settings card and badge copy). */
    public const STATUS_LABELS = [
        'root'     => 'Company root',
        'placed'   => 'Placed',
        'floating' => 'Floating',
    ];

    /** Badge tone per node status (settings card and member panel copy). */
    public const STATUS_BADGES = [
        'root'     => 'bg-label-secondary',
        'placed'   => 'bg-label-success',
        'floating' => 'bg-label-warning',
    ];

    /** Side labels (member cards, admin console, settings copy). */
    public const SIDE_LABELS = [
        'left'  => 'Left',
        'right' => 'Right',
    ];

    private const TABLE = 'binary_nodes';

    /* ----------------------------------------------------------
     *  Reads
     * ---------------------------------------------------------- */

    /** One row for a member, or null when the member is not placed. */
    public static function find(int $userId, ?PDO $pdo = null): ?array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    /** Alias for `find()`, kept for the placement and money phases. */
    public static function forUser(int $userId, ?PDO $pdo = null): ?array
    {
        return self::find($userId, $pdo);
    }

    /** The company root node (`state = 'root'`), or null when absent. */
    public static function root(?PDO $pdo = null): ?array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE state = ? LIMIT 1');
        $stmt->execute(['root']);

        return $stmt->fetch() ?: null;
    }

    /** Create the company root idempotently. Returns the root row. */
    public static function ensureRoot(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $admin = Member::primaryAdmin($pdo);
        if ($admin === null) {
            throw new RuntimeException('The binary tree needs an active admin account to root it.');
        }

        $existing = self::root($pdo);
        if ($existing !== null) {
            return $existing;
        }

        $pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
               (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
             VALUES (?, NULL, NULL, ?, 0, \'\', \'root\', NOW(), ?)'
        )->execute([(int) $admin['id'], (int) $admin['id'], (int) $admin['id']]);

        $row = self::find((int) $admin['id'], $pdo);

        return $row !== null ? $row : ['user_id' => (int) $admin['id']];
    }

    /** The children of a node, left slot first, then right. */
    public static function children(int $uplineId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE upline_id = ?
             ORDER BY FIELD(side, \'left\', \'right\'), user_id'
        );
        $stmt->execute([$uplineId]);

        return $stmt->fetchAll();
    }

    /** The child occupying one slot under a node, or null when the slot is free. */
    public static function slotChild(int $uplineId, string $side, ?PDO $pdo = null): ?array
    {
        if (!in_array($side, self::SIDES, true)) {
            throw new InvalidArgumentException('Side must be "left" or "right".');
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE upline_id = ? AND side = ? LIMIT 1'
        );
        $stmt->execute([$uplineId, $side]);

        return $stmt->fetch() ?: null;
    }

    /** The ancestors of a node, parent first and the root last. */
    public static function ancestors(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $node = self::find($userId, $pdo);
        if ($node === null) {
            return [];
        }

        $depth = (int) $node['depth'];
        if ($depth === 0) {
            return [];
        }

        $path = (string) $node['path'];

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE depth < ? AND ? LIKE CONCAT(path, \'%\')
             ORDER BY depth DESC'
        );
        $stmt->execute([$depth, $path]);

        return $stmt->fetchAll();
    }

    /** The descendants of a node (exclusive), in path order. */
    public static function descendants(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $node = self::find($userId, $pdo);
        if ($node === null) {
            return [];
        }

        $path = (string) $node['path'];
        $prefix = $path . '%';

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE path LIKE ? AND path <> ?
             ORDER BY path'
        );
        $stmt->execute([$prefix, $path]);

        return $stmt->fetchAll();
    }

    /** The user ids of a node's subtree (exclusive), in path order. */
    public static function subtreeIds(int $userId, ?PDO $pdo = null): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['user_id'],
            self::descendants($userId, $pdo)
        );
    }

    /** The side a child occupies under its upline, or null when unattached. */
    public static function sideOf(int $uplineId, int $userId, ?PDO $pdo = null): ?string
    {
        $pdo ??= db();

        $upline = self::find($uplineId, $pdo);
        $node   = self::find($userId, $pdo);
        if ($upline === null || $node === null) {
            return null;
        }

        $uplineDepth = (int) $upline['depth'];
        $path        = (string) $node['path'];

        /* The side at which the node branches under the upline: the letter of
         * the node's path at the upline's depth (plan §14.7). For a direct
         * child this equals the side column itself; for a deeper descendant it
         * is the first letter of the path segment below the upline, so
         * sideOf(root, node) is the correct first letter at any depth. */
        $letter = $path === '' ? '' : (string) substr($path, $uplineDepth, 1);

        return $letter === 'L' ? 'left' : ($letter === 'R' ? 'right' : null);
    }

    /** Whether one node is a strict ancestor of another. */
    public static function isAncestor(int $ancestorId, int $descendantId, ?PDO $pdo = null): bool
    {
        $pdo ??= db();

        $ancestor = self::find($ancestorId, $pdo);
        $descendant = self::find($descendantId, $pdo);
        if ($ancestor === null || $descendant === null) {
            return false;
        }

        $ancestorPath = (string) $ancestor['path'];
        $descendantPath = (string) $descendant['path'];

        return $ancestorPath !== '' && str_starts_with($descendantPath, $ancestorPath);
    }

    /** The materialised path of a node (the side at every ancestor), or null. */
    public static function pathFor(int $userId, ?PDO $pdo = null): ?string
    {
        $node = self::find($userId, $pdo);

        return $node === null ? null : (string) $node['path'];
    }

    /** Every free slot under a node: `[['side' => 'left', 'free' => true], …]`. */
    public static function freeSlots(int $uplineId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $slots = [];
        foreach (self::SIDES as $side) {
            $slots[] = [
                'side' => $side,
                'free' => self::slotChild($uplineId, $side, $pdo) === null,
            ];
        }

        return $slots;
    }

    /** Tree-wide summary counts: nodes, roots, floating, max depth, placed this month. */
    public static function stats(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            'SELECT
                COUNT(*) AS nodes,
                SUM(state = \'root\') AS roots,
                SUM(state = \'floating\') AS floating,
                MAX(depth) AS max_depth
             FROM ' . self::TABLE
        )->fetch();

        $placedStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . '
             WHERE state = \'placed\' AND placed_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')'
        );
        $placedStmt->execute();
        $placedThisMonth = (int) $placedStmt->fetchColumn();

        return [
            'nodes'             => (int) ($row['nodes'] ?? 0),
            'roots'             => (int) ($row['roots'] ?? 0),
            'floating'          => (int) ($row['floating'] ?? 0),
            'max_depth'         => (int) ($row['max_depth'] ?? 0),
            'placed_this_month' => $placedThisMonth,
        ];
    }

    /** One page of a node's team (its descendants), oldest path first. */
    public static function teamRoster(int $userId, int $page = 1, int $perPage = 20, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $offset = ($page - 1) * $perPage;

        $node = self::find($userId, $pdo);
        if ($node === null) {
            return [
                'rows'   => [],
                'pager'  => ['page' => $page, 'per_page' => $perPage, 'total' => 0],
            ];
        }

        $path = (string) $node['path'];
        $prefix = $path . '%';

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE path LIKE ? AND path <> ?'
        );
        $countStmt->execute([$prefix, $path]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT n.*, u.username, u.member_status, u.role
               FROM ' . self::TABLE . ' n
               JOIN users u ON u.id = n.user_id
              WHERE n.path LIKE ? AND n.path <> ?
              ORDER BY n.path
              LIMIT ? OFFSET ?'
        );
        $stmt->execute([$prefix, $path, $perPage, $offset]);

        return [
            'rows'   => $stmt->fetchAll(),
            'pager'  => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    /** The number of direct children a node has on one side (0 or 1). */
    public static function countSide(int $uplineId, string $side, ?PDO $pdo = null): int
    {
        if (!in_array($side, self::SIDES, true)) {
            throw new InvalidArgumentException('Side must be "left" or "right".');
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE upline_id = ? AND side = ?'
        );
        $stmt->execute([$uplineId, $side]);

        return (int) $stmt->fetchColumn();
    }

    /** Nodes in (and including) a subtree whose member cannot currently receive a pair. */
    public static function blockedPairs(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        $node = self::find($userId, $pdo);
        if ($node === null) {
            return [];
        }

        $path = (string) $node['path'];
        $prefix = $path . '%';

        $stmt = $pdo->prepare(
            'SELECT n.user_id, u.username, u.member_status, u.is_active, n.side, n.depth, n.path,
                    n.left_points, n.right_points
               FROM ' . self::TABLE . ' n
               JOIN users u ON u.id = n.user_id
              WHERE (n.path LIKE ? AND n.path <> ?) OR n.user_id = ?
              ORDER BY n.path'
        );
        $stmt->execute([$prefix, $path, $userId]);

        $blocked = [];
        foreach ($stmt->fetchAll() as $row) {
            $eligible = (string) $row['member_status'] === 'active' && (int) $row['is_active'] === 1;
            if ($eligible) {
                continue;
            }

            $blocked[] = [
                'user_id'      => (int) $row['user_id'],
                'username'     => (string) $row['username'],
                'side'         => (string) ($row['side'] ?? null),
                'depth'        => (int) $row['depth'],
                'path'         => (string) $row['path'],
                'left_points'  => (int) $row['left_points'],
                'right_points' => (int) $row['right_points'],
                'reason'       => (string) $row['member_status'] !== 'active'
                    ? 'membership is ' . (string) $row['member_status']
                    : 'account is disabled',
            ];
        }

        return $blocked;
    }

    /* ----------------------------------------------------------
     *  Writes (called by BinaryPlacementService inside a
     *  transaction; this model never opens one)
     * ---------------------------------------------------------- */

    /**
     * Place a member under an upline on one side.
     *
     * depth/path are derived from the upline. The caller must have
     * already verified the slot is free and the placement is legal.
     */
    public static function place(
        int $userId,
        int $uplineId,
        string $side,
        int $placedBy,
        ?PDO $pdo = null
    ): void {
        $pdo ??= db();

        $upline = self::find($uplineId, $pdo);
        if ($upline === null) {
            throw new InvalidArgumentException('Upline #' . $uplineId . ' has no binary node.');
        }

        $root = self::root($pdo);
        if ($root === null) {
            throw new RuntimeException('The binary tree has no company root.');
        }

        $depth  = (int) $upline['depth'] + 1;
        $path   = (string) $upline['path'] . ($side === 'left' ? 'L' : 'R');

        $pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
               (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
             VALUES (?, ?, ?, ?, ?, ?, \'placed\', NOW(), ?)'
        )->execute([
            $userId,
            $uplineId,
            $side,
            (int) $root['user_id'],
            $depth,
            $path,
            $placedBy,
        ]);
    }

    /**
     * Move one node (and its whole subtree) to a new (upline, side).
     *
     * depth/path are recomputed for the moved node and every descendant
     * in a single statement per level. The caller must have verified the
     * subtree is empty of money, the slot is free, and no cycle results.
     */
    public static function move(
        int $userId,
        int $newUplineId,
        string $newSide,
        int $movedBy,
        ?PDO $pdo = null
    ): void {
        $pdo ??= db();

        $node     = self::find($userId, $pdo);
        $newUpline = self::find($newUplineId, $pdo);

        if ($node === null) {
            throw new InvalidArgumentException('Member #' . $userId . ' has no binary node.');
        }
        if ($newUpline === null) {
            throw new InvalidArgumentException('Upline #' . $newUplineId . ' has no binary node.');
        }

        $oldPath  = (string) $node['path'];
        $oldDepth = (int) $node['depth'];
        $newPath  = (string) $newUpline['path'] . ($newSide === 'left' ? 'L' : 'R');
        $newDepth = (int) $newUpline['depth'] + 1;

        /* The moved node and every descendant share the old path prefix,
         * so one UPDATE rewrites the whole subtree: upline_id/side change
         * only for the moved node itself, depth shifts by the delta, and
         * path swaps the old prefix for the new one. */
        $pdo->prepare(
            'UPDATE ' . self::TABLE . '
               SET upline_id   = CASE WHEN user_id = ? THEN ? ELSE upline_id END,
                   side        = CASE WHEN user_id = ? THEN ? ELSE side END,
                   depth       = ? + (depth - ?),
                   path        = CONCAT(?, SUBSTRING(path, ? + 1)),
                   moved_at    = NOW(),
                   moved_by    = ?,
                   move_count  = move_count + 1,
                   updated_at  = NOW()
             WHERE path LIKE CONCAT(?, \'%\')'
        )->execute([
            $userId,   $newUplineId,
            $userId,   $newSide,
            $newDepth, $oldDepth,
            $newPath,  (int) strlen($oldPath),
            $movedBy,
            $oldPath,
        ]);
    }

    /**
     * Recompute one node's depth/path from its current upline (a single
     * node, not its subtree). Used to repair a cached path.
     */
    public static function recomputePath(int $userId, ?PDO $pdo = null): void
    {
        $pdo ??= db();

        $node = self::find($userId, $pdo);
        if ($node === null || (string) $node['state'] === 'root') {
            return;
        }

        $uplineId = (int) ($node['upline_id'] ?? 0);
        if ($uplineId <= 0) {
            return;
        }

        $upline = self::find($uplineId, $pdo);
        if ($upline === null) {
            return;
        }

        $depth = (int) $upline['depth'] + 1;
        $path  = (string) $upline['path'] . ((string) ($node['side'] ?? 'left') === 'left' ? 'L' : 'R');

        $pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET depth = ?, path = ?, updated_at = NOW() WHERE user_id = ?'
        )->execute([$depth, $path, $userId]);
    }
}
