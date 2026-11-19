<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryNodeTest — Phase 22C-S1 (plan §7 Phase 22C)
 *
 *  The binary node read model: root/node lookup, children,
 *  ancestors/descendants, subtree membership, side/path/depth
 *  derivation, free slots, stats and read-only summaries. No
 *  placement write (place/move) and no money — those arrive in
 *  22C-S2 / 22D.
 *
 *  The fixture tree is §18.2, rebuilt inside every case that needs it
 *  (usernames are token-suffixed, ids are looked up, never hard-coded):
 *
 *      admin   state=root        depth 0, path ''
 *      └─ L  boss                depth 1, path L
 *         ├─ L  a                depth 2, path LL
 *         │  ├─ L  b             depth 3, path LLL
 *         │  │  └─ L  c          depth 4, path LLLL
 *         │  │     ├─ L  buyerA  depth 5, path LLLLL
 *         │  │     └─ R  buyerB  depth 5, path LLLLR
 *         │  └─ R  sideR         depth 3, path LLR
 *         └─ R  d                depth 2, path LR
 *
 *  Run this group alone with:  php tests/run.php BinaryNode
 * ============================================================== */

$token = bin2hex(random_bytes(4));

/** Create a fixture user with a token-suffixed username. */
$createUser = static function (string $username, string $role = 'member') use ($token): array {
    $name = $username . '_' . $token;

    $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    if ($row !== false) {
        return $row;
    }

    db()->prepare(
        'INSERT INTO users
           (username, email, password_hash, full_name, role, member_status, referral_code, is_active)
         VALUES (?, ?, ?, ?, ?, \'active\', ?, 1)'
    )->execute([
        $name,
        $name . '@test.local',
        password_hash('Member@123', PASSWORD_BCRYPT),
        ucfirst($name) . ' Test',
        $role,
        strtoupper(bin2hex(random_bytes(4))),
    ]);

    $stmt = db()->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$name]);

    return $stmt->fetch();
};

/** Insert one placed node (depth/path derived by the caller). */
$insertNode = static function (int $userId, ?int $uplineId, ?string $side, int $depth, string $path, string $state = 'placed') use ($token): void {
    $stmt = db()->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();

    db()->prepare(
        "INSERT INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
    )->execute([
        $userId,
        $uplineId,
        $side,
        $depth === 0 ? (int) ($admin['id'] ?? 0) : null,
        $depth,
        $path,
        $state,
        (int) ($admin['id'] ?? 0),
    ]);
};

/** Build the §18.2 fixture tree and return users keyed by name. */
$buildFixtureTree = static function () use ($createUser, $insertNode): array {
    $admin  = $createUser('admin', 'admin');
    $boss   = $createUser('boss');
    $a      = $createUser('a');
    $b      = $createUser('b');
    $c      = $createUser('c');
    $buyerA = $createUser('buyerA');
    $buyerB = $createUser('buyerB');
    $sideR  = $createUser('sideR');
    $d      = $createUser('d');

    /* Company root. */
    $insertNode((int) $admin['id'], null, null, 0, '', 'root');

    /* The tree, upline first so every FK target exists. */
    $insertNode((int) $boss['id'],  (int) $admin['id'], 'left',  1, 'L');
    $insertNode((int) $a['id'],     (int) $boss['id'],  'left',  2, 'LL');
    $insertNode((int) $b['id'],     (int) $a['id'],     'left',  3, 'LLL');
    $insertNode((int) $c['id'],     (int) $b['id'],     'left',  4, 'LLLL');
    $insertNode((int) $buyerA['id'],(int) $c['id'],     'left',  5, 'LLLLL');
    $insertNode((int) $buyerB['id'],(int) $c['id'],     'right', 5, 'LLLLR');
    $insertNode((int) $sideR['id'], (int) $a['id'],     'right', 3, 'LLR');
    $insertNode((int) $d['id'],     (int) $boss['id'],  'right', 2, 'LR');

    return [
        'admin'  => $admin,
        'boss'   => $boss,
        'a'      => $a,
        'b'      => $b,
        'c'      => $c,
        'buyerA' => $buyerA,
        'buyerB' => $buyerB,
        'sideR'  => $sideR,
        'd'      => $d,
    ];
};

return [

    /* ==========================================================
     *  P22C-T1 — ensureRoot() (the company-root bootstrap)
     * ========================================================== */

    'P22C-T1 ensureRoot() creates the company root once' => function (): void {
        $primary = Member::primaryAdmin();
        assert_not_null($primary);

        $root = BinaryNode::ensureRoot();
        assert_not_null($root);
        assert_same((int) $primary['id'], (int) $root['user_id']);
        assert_null($root['upline_id']);
        assert_null($root['side']);
        assert_same('root', (string) $root['state']);
        assert_same(0, (int) $root['depth']);
        assert_same('', (string) $root['path']);

        /* A second call writes nothing (idempotent). */
        $again = BinaryNode::ensureRoot();
        assert_same((int) $root['user_id'], (int) $again['user_id']);
    },

    /* ==========================================================
     *  P22C-T11 — descendants()
     * ========================================================== */

    'P22C-T11 descendants() of a returns the subtree in path order' => function () use ($buildFixtureTree): void {
        $tree = $buildFixtureTree();

        $rows = BinaryNode::descendants((int) $tree['a']['id']);

        assert_count(5, $rows);
        assert_same(
            [
                (int) $tree['b']['id'],
                (int) $tree['c']['id'],
                (int) $tree['buyerA']['id'],
                (int) $tree['buyerB']['id'],
                (int) $tree['sideR']['id'],
            ],
            array_column($rows, 'user_id'),
            'subtree ids in path order'
        );
        assert_same(
            count($rows),
            count(array_unique(array_column($rows, 'user_id'))),
            'no duplicates'
        );
    },

    'P22C-T11 descendants() of a leaf is empty' => function () use ($buildFixtureTree): void {
        $tree = $buildFixtureTree();

        assert_same([], BinaryNode::descendants((int) $tree['buyerA']['id']));
        assert_same([], BinaryNode::descendants((int) $tree['sideR']['id']));
    },

    'P22C-T11 descendants() of an unknown user is empty' => function (): void {
        assert_same([], BinaryNode::descendants(999999));
    },

    /* ==========================================================
     *  P22C-T12 — ancestors()
     * ========================================================== */

    'P22C-T12 ancestors() of buyerA are c, b, a, boss, root' => function () use ($buildFixtureTree): void {
        $tree = $buildFixtureTree();

        $rows = BinaryNode::ancestors((int) $tree['buyerA']['id']);

        assert_count(5, $rows);
        assert_same(
            [
                (int) $tree['c']['id'],
                (int) $tree['b']['id'],
                (int) $tree['a']['id'],
                (int) $tree['boss']['id'],
                (int) $tree['admin']['id'],
            ],
            array_column($rows, 'user_id'),
            'ancestors parent-first, root last'
        );
    },

    'P22C-T12 ancestors() of the root is empty' => function () use ($buildFixtureTree): void {
        $tree = $buildFixtureTree();

        assert_same([], BinaryNode::ancestors((int) $tree['admin']['id']));
    },

    'P22C-T12 ancestors() of an unplaced user is empty' => function () use ($createUser): void {
        $member = $createUser('unplaced');
        assert_same([], BinaryNode::ancestors((int) $member['id']));
    },

    /* ==========================================================
     *  P22C-T13 — freeSlots()
     * ========================================================== */

    'P22C-T13 freeSlots() of an empty root is left and right' => function () use ($createUser): void {
        $admin = $createUser('empty_root', 'admin');
        BinaryNode::ensureRoot();

        $slots = BinaryNode::freeSlots((int) $admin['id']);

        assert_count(2, $slots);
        assert_same('left', $slots[0]['side']);
        assert_true($slots[0]['free']);
        assert_same('right', $slots[1]['side']);
        assert_true($slots[1]['free']);
    },

    'P22C-T13 freeSlots() reports one free slot after a child' => function () use ($buildFixtureTree): void {
        $tree = $buildFixtureTree();

        $slots = BinaryNode::freeSlots((int) $tree['c']['id']);

        assert_count(2, $slots);
        $bySide = array_column($slots, 'free', 'side');
        assert_false($bySide['left'],  'c has a left child (buyerA)');
        assert_false($bySide['right'], 'c has a right child (buyerB)');
    },

    /* ==========================================================
     *  P22C-T14 — sideOf()
     * ========================================================== */

    'P22C-T14 sideOf() returns the slot of a direct child' => function () use ($buildFixtureTree): void {
        $tree = $buildFixtureTree();

        assert_same('left', BinaryNode::sideOf((int) $tree['admin']['id'], (int) $tree['boss']['id']));
        assert_same('left', BinaryNode::sideOf((int) $tree['boss']['id'], (int) $tree['a']['id']));
        assert_same('right', BinaryNode::sideOf((int) $tree['a']['id'], (int) $tree['sideR']['id']));
    },

    'P22C-T14 sideOf() returns the first path letter at any depth' => function () use ($buildFixtureTree): void {
        $tree = $buildFixtureTree();

        /* buyerA sits left at every ancestor level: path LLLLL. */
        assert_same('left', BinaryNode::sideOf((int) $tree['admin']['id'], (int) $tree['buyerA']['id']));
        /* buyerB: path LLLLR — right under c. */
        assert_same('right', BinaryNode::sideOf((int) $tree['c']['id'], (int) $tree['buyerB']['id']));
    },

    'P22C-T14 sideOf() of an unattached user is null' => function () use ($createUser): void {
        $member = $createUser('unattached');
        assert_null(BinaryNode::sideOf(999999, (int) $member['id']));
    },

    /* ==========================================================
     *  P22C-T15 — pathFor() (deep chain, no recursion in SQL)
     * ========================================================== */

    'P22C-T15 pathFor() on a 50-deep chain is correct' => function () use ($createUser, $insertNode): void {
        $admin = $createUser('chain_admin', 'admin');
        $insertNode((int) $admin['id'], null, null, 0, '');

        $previous = (int) $admin['id'];
        $path     = '';

        for ($depth = 1; $depth <= 50; $depth++) {
            $member = $createUser('chain_' . $depth);
            $side   = $depth % 2 === 1 ? 'left' : 'right';
            $path   .= $side === 'left' ? 'L' : 'R';

            $insertNode((int) $member['id'], $previous, $side, $depth, $path);
            $previous = (int) $member['id'];
        }

        $leaf = BinaryNode::find($previous);
        assert_not_null($leaf);
        assert_same(50, (int) $leaf['depth']);
        assert_same(str_repeat('LR', 25), (string) $leaf['path']);
        assert_same(str_repeat('LR', 25), BinaryNode::pathFor($previous));
    },

    /* ==========================================================
     *  P22C-T17 — stats()
     * ========================================================== */

    'P22C-T17 stats() reports nodes, roots, floating and max depth' => function () use ($buildFixtureTree): void {
        $buildFixtureTree();

        $stats = BinaryNode::stats();

        assert_same(9, $stats['nodes']);
        assert_same(1, $stats['roots']);
        assert_same(0, $stats['floating']);
        assert_same(5, $stats['max_depth']);
    },

    'P22C-T17 stats() on an empty tree is zeroed' => function () use ($createUser): void {
        $createUser('stats_admin', 'admin');
        BinaryNode::ensureRoot();

        $stats = BinaryNode::stats();

        assert_same(1, $stats['nodes']);
        assert_same(1, $stats['roots']);
        assert_same(0, $stats['floating']);
        assert_same(0, $stats['max_depth']);
        assert_same(0, $stats['placed_this_month']);
    },

    /* ==========================================================
     *  P22C-T18 — delete cascade (direct SQL, schema-level)
     * ========================================================== */

    'P22C-T18 deleting a node cascades to its subtree' => function () use ($buildFixtureTree): void {
        $tree = $buildFixtureTree();

        /* Sanity: descendants of b exclude b itself: c, buyerA, buyerB. */
        assert_same(
            [
                (int) $tree['c']['id'],
                (int) $tree['buyerA']['id'],
                (int) $tree['buyerB']['id'],
            ],
            array_column(BinaryNode::descendants((int) $tree['b']['id']), 'user_id'),
        );

        db()->prepare('DELETE FROM binary_nodes WHERE user_id = ?')
            ->execute([(int) $tree['b']['id']]);

        assert_same([], BinaryNode::descendants((int) $tree['b']['id']));
        assert_null(BinaryNode::find((int) $tree['b']['id']));
        assert_null(BinaryNode::find((int) $tree['c']['id']));
        assert_null(BinaryNode::find((int) $tree['buyerA']['id']));
        assert_null(BinaryNode::find((int) $tree['buyerB']['id']));

        /* The rest of the tree is untouched. */
        assert_not_null(BinaryNode::find((int) $tree['a']['id']));
        assert_not_null(BinaryNode::find((int) $tree['sideR']['id']));
    },

];
