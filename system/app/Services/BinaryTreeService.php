<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryTreeService — the product-binary genealogy tree data (22G-S1)
 *  (plan §11.1, §11.4, §11.5)
 *
 *  The server owns the data; the client only draws it. Every read comes
 *  from here, every privacy check is enforced server-side, and the
 *  sponsor genealogy branch is never touched.
 *
 *  One query per depth level, never per node: all subtree rows are
 *  fetched with a single `path LIKE` scan and the tree is assembled in
 *  PHP. Nodes ≤ 500 per payload, depth ∈ [1, 5].
 *
 *  Privacy:
 *    • a member may request only their own subtree (their own node
 *      included); anything else is refused;
 *    • an admin may request any node;
 *    • search is scoped the same way;
 *    • `name` is null for a non-admin viewer.
 * ============================================================== */

final class BinaryTreeService
{
    /** Hard depth cap for a single payload (§11.5 rule 4). */
    public const MAX_DEPTH = 5;

    /** Hard node cap for a single payload (§11.5 rule 4). */
    public const MAX_NODES = 500;

    /** Default first-paint depth. */
    public const DEFAULT_DEPTH = 3;

    private const TABLE = 'binary_nodes';

    /* ----------------------------------------------------------
     *  Public API
     * ---------------------------------------------------------- */

    /**
     * Build the full JSON subtree payload for one root node.
     *
     * @return array{
     *   ok:bool, root:int, viewer:int, is_admin:bool, depth:int,
     *   nodes:int, truncated:bool, can_register:bool, etag:string,
     *   tree:array<string, mixed>
     * }
     * @throws BinaryException when the viewer is not allowed to see the root
     */
    public function subtree(int $rootId, int $depth, int $viewerId, bool $isAdmin): array
    {
        $depth   = max(1, min(self::MAX_DEPTH, (int) $depth));
        $viewer  = (int) $viewerId;

        /* Privacy: a member may only look at their own subtree. */
        if (!$isAdmin) {
            $viewerNode = BinaryNode::forUser($viewer);
            if ($viewerNode === null) {
                throw BinaryException::notPlaced($viewer);
            }
            $viewerPath = (string) ($viewerNode['path'] ?? '');
            $viewerDepth= (int) ($viewerNode['depth'] ?? 0);

            $rootNode = BinaryNode::find($rootId);
            if ($rootNode === null) {
                throw BinaryException::notPlaced($rootId);
            }

            $rootPath = (string) ($rootNode['path'] ?? '');
            $rootDepth= (int) ($rootNode['depth'] ?? 0);

            /* The root must be the viewer's own node or a descendant of it. */
            $inSubtree = $rootId === $viewer
                || ($rootPath !== '' && $viewerPath !== ''
                    && str_starts_with($rootPath, $viewerPath)
                    && $rootDepth >= $viewerDepth);

            if (!$inSubtree) {
                throw BinaryException::notPlaced($rootId);
            }
        } else {
            $rootNode = BinaryNode::find($rootId);
            if ($rootNode === null) {
                throw BinaryException::notPlaced($rootId);
            }
        }

        /* One query for every row in the subtree (shape read, never per
         * node). Build parent → children and id → row maps. */
        $rootPath   = (string) ($rootNode['path'] ?? '');
        $rootDepth  = (int) ($rootNode['depth'] ?? 0);
        $rootUserId = (int) ($rootNode['user_id'] ?? 0);

        /* Enrich the root row with member facts (username, status, role)
         * so the root card is rendered identically to every other node
         * (§11.4). fetchSubtreeRows() joins users for the descendants, but
         * the root row itself comes from BinaryNode::find() which carries
         * none of those columns. */
        $rootMember = Member::find($rootUserId);
        if ($rootMember !== null) {
            $rootNode['username']      = (string) ($rootMember['username'] ?? '');
            $rootNode['full_name']     = (string) ($rootMember['full_name'] ?? '');
            $rootNode['referral_code'] = (string) ($rootMember['referral_code'] ?? '');
            $rootNode['is_active']     = (int) ($rootMember['is_active'] ?? 0);
            $rootNode['member_status'] = (string) ($rootMember['member_status'] ?? '');
            $rootNode['role']          = (string) ($rootMember['role'] ?? 'member');
            $rootNode['joined']        = substr((string) ($rootMember['created_at'] ?? ''), 0, 10);
            $rootNode['package_id']    = (int) ($rootMember['package_id'] ?? 0);

            if ((int) ($rootNode['package_id'] ?? 0) > 0) {
                $rootNode['package_binary_enabled'] = Package::binaryEnabled((int) $rootNode['package_id']) ? 1 : 0;
                $rootPackage = Package::find((int) $rootNode['package_id']);
                if ($rootPackage !== null) {
                    $rootNode['package_name'] = (string) ($rootPackage['name'] ?? '');
                }
            }
        }

        $all = $this->fetchSubtreeRows($rootPath, $rootUserId);

        $byId     = [];
        $children = [];
        foreach ($all as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            $byId[$id] = $row;
            $upline  = (int) ($row['upline_id'] ?? 0);
            $children[$upline][] = $row;
        }

        $kSort = static function (array $rows): array {
            usort($rows, static fn (array $a, array $b): int =>
                strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? '')));
            return $rows;
        };
        foreach ($children as &$list) {
            $list = $kSort($list);
        }
        unset($list);

        $count     = 0;
        $truncated = false;

        $tree = $this->buildNode(
            $rootNode, $children, $rootDepth, $depth, 1, $viewer, $isAdmin,
            $count, $truncated
        );

        return [
            'ok'           => true,
            'root'         => $rootUserId,
            'viewer'       => $viewer,
            'is_admin'     => $isAdmin,
            'depth'        => $depth,
            'nodes'        => $count,
            'truncated'    => $truncated,
            'can_register' => $this->canRegister($viewer, $rootUserId, 'left')
                || $this->canRegister($viewer, $rootUserId, 'right'),
            'etag'         => $this->etag($rootId, $depth, $viewer, $isAdmin),
            'tree'         => $tree,
        ];
    }

    /**
     * One node's card data (used by the tree and by node-level lookups).
     *
     * @return array<string, mixed>|null
     */
    public function node(int $userId): ?array
    {
        $node = BinaryNode::find((int) $userId);
        if ($node === null) {
            return null;
        }

        $user   = auth_user();
        $viewer = $user ? (int) ($user['id'] ?? 0) : 0;
        $isAdmin = is_admin();

        /* Enrich with username and membership facts from users. */
        $member = Member::find((int) $userId);
        if ($member !== null) {
            $node['username']    = (string) ($member['username'] ?? '');
            $node['full_name']   = (string) ($member['full_name'] ?? '');
            $node['is_active']   = (int) ($member['is_active'] ?? 0);
            $node['member_status'] = (string) ($member['member_status'] ?? '');
            $node['role']        = (string) ($member['role'] ?? 'member');
        }

        return $this->nodeCard($node, $viewer, $isAdmin, (int) ($node['depth'] ?? 0), [
            'children'  => [],
            'has_more'  => false,
            'can_move'  => false,
        ]);
    }

    /** The materialised path of a node, or '' when absent. */
    public function path(int $userId): string
    {
        $node = BinaryNode::find((int) $userId);
        return $node === null ? '' : (string) ($node['path'] ?? '');
    }

    /**
     * Search members by username inside the viewer's scope.
     *
     * A member finds only nodes in their own subtree; an admin finds any
     * node. A member outside the scope is never named or counted
     * (§17.4 rule 3, §11.5 rule 3).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, int $viewerId, bool $isAdmin): array
    {
        $term   = trim((string) $term);
        $viewer = (int) $viewerId;

        if ($term === '') {
            return [];
        }

        $like = '%' . $term . '%';

        if ($isAdmin) {
            $stmt = db()->prepare(
                'SELECT n.*, u.username, u.full_name, u.member_status, u.is_active, u.role,
                        u.package_id, p.name AS package_name, DATE(u.created_at) AS joined,
                        n.left_points, n.right_points, n.left_credited, n.right_credited,
                        n.personal_points, n.pairs_paid, n.depth, n.path, n.user_id, n.side
                   FROM ' . self::TABLE . ' n
                   JOIN users u ON u.id = n.user_id
                   LEFT JOIN packages p ON p.id = u.package_id
                  WHERE u.username LIKE ?
                  ORDER BY n.path
                  LIMIT 50'
            );
            $stmt->execute([$like]);
        } else {
            $viewerNode = BinaryNode::forUser($viewer);
            if ($viewerNode === null) {
                return [];
            }

            $viewerPath = (string) ($viewerNode['path'] ?? '');

            $stmt = db()->prepare(
                'SELECT n.*, u.username, u.full_name, u.member_status, u.is_active, u.role,
                        u.package_id, p.name AS package_name, DATE(u.created_at) AS joined,
                        n.left_points, n.right_points, n.left_credited, n.right_credited,
                        n.personal_points, n.pairs_paid, n.depth, n.path, n.user_id, n.side
                   FROM ' . self::TABLE . ' n
                   JOIN users u ON u.id = n.user_id
                   LEFT JOIN packages p ON p.id = u.package_id
                  WHERE n.path LIKE ? AND n.path <> ?
                  ORDER BY n.path
                  LIMIT 50'
            );
            $stmt->execute([$viewerPath . '%', $viewerPath]);
        }

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $out[] = [
                'id'        => $userId,
                'username'  => (string) ($row['username'] ?? ''),
                'name'      => $isAdmin ? ((string) ($row['full_name'] ?? null)) : null,
                'side'      => (string) ($row['side'] ?? null),
                'depth'     => (int) ($row['depth'] ?? 0),
                'path'      => (string) ($row['path'] ?? ''),
                'package'   => (string) ($row['package_name'] ?? ''),
                'joined'    => (string) ($row['joined'] ?? ''),
                'is_you'    => $userId === $viewer,
                'points'    => [
                    'left'          => (int) ($row['left_points'] ?? 0),
                    'right'         => (int) ($row['right_points'] ?? 0),
                    'left_lifetime' => (int) ($row['left_credited'] ?? 0),
                    'right_lifetime'=> (int) ($row['right_credited'] ?? 0),
                    'personal'      => (int) ($row['personal_points'] ?? 0),
                    'pairs'         => (int) ($row['pairs_paid'] ?? 0),
                    'pairs_today'   => BinaryService::pairsToday($userId, date('Y-m-d H:i:s')),
                    'pair_points'   => (int) setting_int('binary_pair_points', 1000),
                ],
            ];
        }

        return $out;
    }

    /**
     * Flat, indentation-tagged rows for the no-JS list (§11.1).
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(int $rootId, int $depth, int $viewerId): array
    {
        $depth = max(1, min(self::MAX_DEPTH, (int) $depth));
        $root  = BinaryNode::find((int) $rootId);
        if ($root === null) {
            return [];
        }

        $rootPath   = (string) ($root['path'] ?? '');
        $rootDepth  = (int) ($root['depth'] ?? 0);
        $rootUserId = (int) ($root['user_id'] ?? 0);

        $all = $this->fetchSubtreeRows($rootPath, $rootUserId);

        $children = [];
        foreach ($all as $row) {
            $upline = (int) ($row['upline_id'] ?? 0);
            $children[$upline][] = $row;
        }

        $kSort = static function (array $rows): array {
            usort($rows, static fn (array $a, array $b): int =>
                strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? '')));
            return $rows;
        };
        foreach ($children as &$list) {
            $list = $kSort($list);
        }
        unset($list);

        $rows = [];
        $this->walkRows($root, $children, $rootDepth, $depth, 1, $rows);

        return $rows;
    }

    /** Tree-wide counts for one root. */
    public function counts(int $rootId): array
    {
        $root = BinaryNode::find((int) $rootId);
        if ($root === null) {
            return ['nodes' => 0, 'max_depth' => 0];
        }

        $rootPath = (string) ($root['path'] ?? '');
        $stmt     = db()->prepare(
            'SELECT COUNT(*) AS nodes, MAX(depth) AS max_depth
               FROM ' . self::TABLE . '
              WHERE path LIKE ? AND path <> ?'
        );
        $stmt->execute([$rootPath . '%', $rootPath]);
        $row = $stmt->fetch();

        return [
            'nodes'     => (int) ($row['nodes'] ?? 0),
            'max_depth' => max(0, (int) ($row['max_depth'] ?? 0) - (int) ($root['depth'] ?? 0)),
        ];
    }

    /**
     * Whether `$viewerId` may register into `($uplineId, $side)`.
     *
     * Read-only: a free slot, an eligible upline, no cycle, inside the
     * viewer's subtree for members and anywhere for admins.
     */
    public function canRegister(int $viewerId, int $uplineId, string $side): bool
    {
        if (!in_array($side, BinaryNode::SIDES, true)) {
            return false;
        }

        /* Slot must be free. */
        if (BinaryNode::slotChild((int) $uplineId, $side) !== null) {
            return false;
        }

        $uplineNode = BinaryNode::find((int) $uplineId);
        if ($uplineNode === null || (string) ($uplineNode['state'] ?? '') !== 'placed') {
            return false;
        }

        $uplineUser = Member::find((int) $uplineId);
        if ($uplineUser === null || (int) ($uplineUser['is_active'] ?? 0) !== 1) {
            return false;
        }

        $status = (string) ($uplineUser['member_status'] ?? '');
        if ($status !== 'active') {
            return false;
        }

        /* Staff accounts are not in the tree; the company root excepted. */
        if (!is_admin() && (string) ($uplineUser['role'] ?? '') !== 'member') {
            return false;
        }

        /* Depth cap. */
        if ((int) ($uplineNode['depth'] ?? 0) + 1 > BinaryNode::MAX_DEPTH) {
            return false;
        }

        /* Privacy: a member may only register inside their own subtree. */
        if (!is_admin()) {
            $viewerNode = BinaryNode::forUser((int) $viewerId);
            if ($viewerNode === null) {
                return false;
            }
            if ((int) $uplineId !== (int) $viewerId
                && !BinaryNode::isAncestor((int) $viewerId, (int) $uplineId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ETag scope for the binary tree JSON: query keys + viewer, plus the
     * shared genealogy-team revision (shape) and the binary revision
     * (points/awards) for the viewer (§11.5 rule 10).
     */
    public function etagScope(int $rootId, int $depth, int $viewerId, bool $isAdmin): string
    {
        return Live::scope(
            ['view' => 'binary', 'format' => 'json', 'root' => (string) $rootId, 'depth' => (string) $depth],
            (int) $viewerId
        )
        . '|team:' . Live::revisionOrZero(Live::GENEALOGY_TEAM)
        . '|binary:' . Live::revisionOrZero(Live::binarySurface((int) $viewerId));
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** Every row in one subtree (root excluded), ordered by path. */
    private function fetchSubtreeRows(string $rootPath, int $rootUserId): array
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT n.*, u.username, u.full_name, u.member_status, u.is_active, u.role,
                    u.referral_code, u.package_id, p.name AS package_name, p.binary_enabled AS package_binary_enabled, DATE(u.created_at) AS joined,
                    n.left_points, n.right_points, n.left_credited, n.right_credited,
                    n.personal_points, n.pairs_paid, n.depth, n.path, n.user_id, n.side
               FROM ' . self::TABLE . ' n
               JOIN users u ON u.id = n.user_id
               LEFT JOIN packages p ON p.id = u.package_id
              WHERE n.path LIKE ? AND n.path <> ?
              ORDER BY n.path'
        );
        $stmt->execute([$rootPath . '%', $rootPath]);

        return $stmt->fetchAll();
    }

    /**
     * Recursively build one node and its children, respecting the depth
     * and node caps.
     *
     * @param array<int, list<array>> $children upline_id => rows
     * @param int                     $nodeDepth depth of the subtree root
     * @param int                     $limit   max depth below rootNode
     * @param int                     $level   current level below rootNode (1-based)
     * @param int                     $count   running node count (updated by reference)
     * @param bool                    $truncated running truncation flag (updated by reference)
     * @return array<string, mixed>
     */
    private function buildNode(
        array $rootNode,
        array $children,
        int $nodeDepth,
        int $limit,
        int $level,
        int $viewer,
        bool $isAdmin,
        int &$count,
        bool &$truncated
    ): array {
        if ($count >= self::MAX_NODES) {
            $truncated = true;

            return $this->nodeCard($rootNode, 0, $isAdmin, (int) ($rootNode['depth'] ?? 0), [
                'children'  => [],
                'has_more'  => true,
                'can_move'  => false,
            ]);
        }

        $count++;

        $userId     = (int) ($rootNode['user_id'] ?? 0);
        $depth      = (int) ($rootNode['depth'] ?? 0);
        $nodePath   = (string) ($rootNode['path'] ?? '');
        $nodeChildren = isset($children[$userId]) ? $children[$userId] : [];

        $hasMore = false;
        $childCards = [];

        if ($level <= $limit && $nodeChildren !== []) {
            foreach ($nodeChildren as $child) {
                if ($count >= self::MAX_NODES) {
                    $truncated = true;
                    $hasMore   = true;
                    break;
                }

                $childCards[] = $this->buildNode(
                    $child, $children, $depth, $limit, $level + 1,
                    $viewer, $isAdmin, $count, $truncated
                );
            }

            if (count($childCards) < count($nodeChildren)) {
                $hasMore = true;
                if ($count >= self::MAX_NODES) {
                    $truncated = true;
                }
            }
        } elseif ($nodeChildren !== []) {
            $hasMore = true;
        }

        return $this->nodeCard($rootNode, $viewer, $isAdmin, $depth, [
            'children'  => $childCards,
            'has_more'  => $hasMore,
            'can_move'  => $isAdmin && $nodeChildren === [],
        ]);
    }

    /** One node's card data shape (§11.4). */
    private function nodeCard(
        array $node,
        int $viewer,
        bool $isAdmin,
        int $depth,
        array $shape
    ): array {
        $userId = (int) ($node['user_id'] ?? 0);

        $points = [
            'left'          => (int) ($node['left_points'] ?? 0),
            'right'         => (int) ($node['right_points'] ?? 0),
            'left_lifetime' => (int) ($node['left_credited'] ?? 0),
            'right_lifetime'=> (int) ($node['right_credited'] ?? 0),
            'personal'      => (int) ($node['personal_points'] ?? 0),
            'pairs'         => (int) ($node['pairs_paid'] ?? 0),
            'pairs_today'   => BinaryService::pairsToday($userId, date('Y-m-d H:i:s')),
            'pair_points'   => (int) setting_int('binary_pair_points', 1000),
        ];

        $slots = [];
        foreach (BinaryNode::SIDES as $side) {
            $child = BinaryNode::slotChild($userId, $side);
            $slots[$side] = [
                'free' => $child === null,
                'user' => $child === null ? null : (int) ($child['user_id'] ?? null),
            ];
        }

        return [
            'id'                   => $userId,
            'username'             => (string) ($node['username'] ?? ''),
            'name'                 => $isAdmin ? ((string) ($node['full_name'] ?? null)) : null,
            'referral_code'        => (string) ($node['referral_code'] ?? ''),
            'side'                 => (string) ($node['side'] ?? null),
            'path'                 => (string) ($node['path'] ?? ''),
            'depth'                => $depth,
            'status'               => (string) (($node['is_active'] ?? 0) === 1 ? 'active' : 'inactive'),
            'member_status'        => (string) ($node['member_status'] ?? ''),
            'package'              => (string) ($node['package_name'] ?? ''),
            'package_binary_enabled' => (int) ($node['package_binary_enabled'] ?? 0) === 1,
            'joined'               => (string) ($node['joined'] ?? ''),
            'is_you'               => $userId === $viewer,
            'points'               => $points,
            'slots'                => $slots,
            'can_move'             => $shape['can_move'] ?? false,
            'has_more'             => $shape['has_more'] ?? false,
            'children'             => $shape['children'] ?? [],
        ];
    }

    /** Walk the tree depth-first, tagging each row with its indent level. */
    private function walkRows(
        array $node,
        array $children,
        int $nodeDepth,
        int $limit,
        int $level,
        array &$rows
    ): void {
        if ($level > $limit) {
            return;
        }

        $userId = (int) ($node['user_id'] ?? 0);
        $rows[] = [
            'id'                   => $userId,
            'username'             => (string) ($node['username'] ?? ''),
            'side'                 => (string) ($node['side'] ?? null),
            'depth'                => (int) ($node['depth'] ?? 0),
            'indent'               => max(0, (int) ($node['depth'] ?? 0) - $nodeDepth),
            'package'              => (string) ($node['package_name'] ?? ''),
            'package_binary_enabled' => (int) ($node['package_binary_enabled'] ?? 0) === 1,
            'points'               => [
                'left'  => (int) ($node['left_points'] ?? 0),
                'right' => (int) ($node['right_points'] ?? 0),
            ],
        ];

        $childList = isset($children[$userId]) ? $children[$userId] : [];
        foreach ($childList as $child) {
            $this->walkRows($child, $children, $nodeDepth, $limit, $level + 1, $rows);
        }
    }

    /** The ETag for one subtree payload. */
    private function etag(int $rootId, int $depth, int $viewerId, bool $isAdmin): string
    {
        return Live::etag(
            'member.binary:' . $rootId,
            0,
            $this->etagScope($rootId, $depth, $viewerId, $isAdmin)
        );
    }
}
