<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryPlacementServiceTest — Phase 22C-S2 (plan §7 Phase 22C)
 *
 *  Proves the placement write path on its own: placing a node, the
 *  slot/cycle/eligibility/depth guards, the empty-subtree move, and the
 *  immutability rule. No registration form, no admin route, no money —
 *  those arrive in 22C-S3 / 22C-S4 / 22D.
 *
 *  Fixture tree (plan §18.2):
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
 *  `d` is a leaf with both slots free, so it is the placement target for
 *  the place tests. Fresh buyer users are placed on top of it.
 *  Every case runs inside the harness transaction and rolls back.
 *
 *  Run this group alone with:  php tests/run.php BinaryPlacementService
 * ============================================================== */

$token = bin2hex(random_bytes(4));

$service = new BinaryPlacementService();

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

    $stmt = db()->prepare('SELECT id, username, role, member_status, is_active FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$name]);

    return (array) $stmt->fetch();
};

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

    $insertNode((int) $admin['id'], null, null, 0, '', 'root');
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

    'P22C-T2 placeNew() places under an eligible upline on a free slot' => function () use ($buildFixtureTree, $service, $createUser): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $buyer = $createUser('place_buyer');

        $node = $service->placeNew((int) $buyer['id'], (int) $tree['d']['id'], 'left');

        assert_same((int) $tree['d']['id'], (int) ($node['upline_id'] ?? 0));
        assert_same('left', (string) ($node['side'] ?? ''));
        assert_same(3, (int) ($node['depth'] ?? 0));
        assert_same('LRL', (string) ($node['path'] ?? ''));
        assert_same('placed', (string) ($node['state'] ?? ''));
        assert_not_null($node['placed_at']);
        assert_not_null($node['placed_by']);

        $again = BinaryNode::forUser((int) $buyer['id']);
        assert_same('left', (string) ($again['side'] ?? ''));
        assert_same('LRL', (string) ($again['path'] ?? ''));
    },

    'P22C-T2 placeNew() derives depth/path from the upline' => function () use ($buildFixtureTree, $service, $createUser): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $buyer = $createUser('place_depth');

        $service->placeNew((int) $buyer['id'], (int) $tree['buyerA']['id'], 'right');

        $node = BinaryNode::forUser((int) $buyer['id']);
        assert_same(6, (int) ($node['depth'] ?? 0));
        assert_same('LLLLLR', (string) ($node['path'] ?? ''));
    },

    'P22C-T3 placeNew() refuses a taken slot' => function () use ($buildFixtureTree, $service, $createUser): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $service->placeNew($createUser('taken_first')['id'], (int) $tree['d']['id'], 'left');

        $e = assert_throws(
            function () use ($service, $tree, $createUser): void {
                $service->placeNew($createUser('taken_second')['id'], (int) $tree['d']['id'], 'left');
            },
            BinaryException::class
        );
        assert_same('slot_taken', $e->reason);

        assert_count(1, BinaryNode::children((int) $tree['d']['id']));
    },

    'P22C-T4 placeNew() accepts the other side of the same upline' => function () use ($buildFixtureTree, $service, $createUser): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $service->placeNew($createUser('side_first')['id'], (int) $tree['d']['id'], 'left');
        $node = $service->placeNew($createUser('side_second')['id'], (int) $tree['d']['id'], 'right');

        assert_same('right', (string) ($node['side'] ?? ''));
        assert_count(2, BinaryNode::children((int) $tree['d']['id']));
    },

    'P22C-T5 placeNew() refuses an upline with no node' => function () use ($buildFixtureTree, $service, $createUser): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $floating = $createUser('floating');

        $e = assert_throws(
            function () use ($service, $floating, $createUser): void {
                $service->placeNew($createUser('buyer')['id'], (int) $floating['id'], 'left');
            },
            BinaryException::class
        );
        assert_same('not_placed', $e->reason);
    },

    'P22C-T6 placeNew() refuses a suspended upline' => function () use ($buildFixtureTree, $service, $createUser, $insertNode): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $suspended = $createUser('suspended_user');
        $insertNode((int) $suspended['id'], (int) $tree['d']['id'], 'left', 3, 'LRL');
        db()->prepare('UPDATE users SET member_status = \'suspended\' WHERE id = ?')
            ->execute([(int) $suspended['id']]);

        $e = assert_throws(
            function () use ($service, $suspended, $createUser): void {
                $service->placeNew($createUser('buyer')['id'], (int) $suspended['id'], 'left');
            },
            BinaryException::class
        );
        assert_same('state', $e->reason);
    },

    'P22C-T7 placeNew() refuses an inactive upline' => function () use ($buildFixtureTree, $service, $createUser, $insertNode): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $inactive = $createUser('inactive_user');
        $insertNode((int) $inactive['id'], (int) $tree['d']['id'], 'left', 3, 'LRL');
        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')
            ->execute([(int) $inactive['id']]);

        $e = assert_throws(
            function () use ($service, $inactive, $createUser): void {
                $service->placeNew($createUser('buyer')['id'], (int) $inactive['id'], 'left');
            },
            BinaryException::class
        );
        assert_same('state', $e->reason);
    },

    'P22C-T8 placeNew() refuses a staff upline' => function () use ($buildFixtureTree, $service, $createUser, $insertNode): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $staff = $createUser('staff_user', 'admin');
        $insertNode((int) $staff['id'], (int) $tree['d']['id'], 'left', 3, 'LRL');

        $e = assert_throws(
            function () use ($service, $staff, $createUser): void {
                $service->placeNew($createUser('buyer')['id'], (int) $staff['id'], 'left');
            },
            BinaryException::class
        );
        assert_same('state', $e->reason);
    },

    'P22C-T9 placeNew() refuses placing a member under themselves' => function () use ($buildFixtureTree, $service, $createUser, $insertNode): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $buyer = $createUser('self_buyer');
        $insertNode((int) $buyer['id'], (int) $tree['d']['id'], 'left', 3, 'LRL');

        $e = assert_throws(
            function () use ($service, $buyer): void {
                $service->placeNew((int) $buyer['id'], (int) $buyer['id'], 'left');
            },
            BinaryException::class
        );
        assert_same('cycle', $e->reason);

        $node = BinaryNode::find((int) $buyer['id']);
        assert_same('LRL', (string) ($node['path'] ?? ''));
    },

    'P22C-T10 placeNew() refuses an ancestor under a descendant' => function () use ($buildFixtureTree, $service): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $e = assert_throws(
            function () use ($service, $tree): void {
                $service->placeNew((int) $tree['boss']['id'], (int) $tree['b']['id'], 'left');
            },
            BinaryException::class
        );
        assert_same('cycle', $e->reason);

        $node = BinaryNode::find((int) $tree['boss']['id']);
        assert_same('L', (string) ($node['path'] ?? ''));
    },

    'P22C-T32 move() moves an empty node to a free slot and recomputes paths' => function () use ($buildFixtureTree, $service): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $before = BinaryNode::find((int) $tree['buyerA']['id']);
        assert_same('LLLLL', (string) ($before['path'] ?? ''));
        assert_same(5, (int) ($before['depth'] ?? 0));

        $result = $service->move((int) $tree['buyerA']['id'], (int) $tree['d']['id'], 'left', (int) $tree['admin']['id']);

        assert_same('left', (string) ($result['node']['side'] ?? ''));
        assert_same('LRL', (string) ($result['node']['path'] ?? ''));
        assert_same(3, (int) ($result['node']['depth'] ?? 0));
        assert_same(1, (int) ($result['node']['move_count'] ?? 0));
        assert_not_null($result['node']['moved_at']);

        $after = BinaryNode::forUser((int) $tree['buyerA']['id']);
        assert_same('LRL', (string) ($after['path'] ?? ''));
        assert_same('left', BinaryNode::sideOf((int) $tree['d']['id'], (int) $tree['buyerA']['id']));

        assert_true(array_key_exists('before', $result));
        assert_true(array_key_exists('after', $result));
    },

    'P22C-T33 move() refuses when the subtree has volume' => function () use ($buildFixtureTree, $service): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $buyerAId = (int) $tree['buyerA']['id'];
        db()->prepare(
            'INSERT INTO binary_volume (node_user_id, side, kind, points, created_at)
             VALUES (?, \'left\', \'adjustment\', 1000, NOW())'
        )->execute([$buyerAId]);

        $e = assert_throws(
            function () use ($service, $tree): void {
                $service->move((int) $tree['buyerA']['id'], (int) $tree['d']['id'], 'left', (int) $tree['admin']['id']);
            },
            BinaryException::class
        );
        assert_same('immutable', $e->reason);

        $node = BinaryNode::find((int) $tree['buyerA']['id']);
        assert_same('LLLLL', (string) ($node['path'] ?? ''));
    },

    'P22C-T34 move() refuses when the subtree has an award' => function () use ($buildFixtureTree, $service, $token): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $buyerAId = (int) $tree['buyerA']['id'];

        db()->prepare(
            'INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
                billing_name, shipping_name, shipping_address, shipping_country, terms_version, terms_accepted_at)
             VALUES (?, ?, \'paid\', \'ewallet\', 0, 0, 0, \'Test\', \'Test\', \'Test addr\', \'PH\', \'v1\', NOW())'
        )->execute(['TEST-AWARD-' . $token, $buyerAId]);

        $orderId = (int) db()->lastInsertId();

        db()->prepare(
            'INSERT INTO binary_awards
               (kind, order_id, buyer_id, user_id, side, points, pair_count, pair_points, rate_pct, amount, status, paid_at)
             VALUES (\'pair\', ?, ?, ?, \'left\', 2000, 1, 1000, 10.000, 100.00, \'pending\', NOW())'
        )->execute([$orderId, $buyerAId, $buyerAId]);

        $e = assert_throws(
            function () use ($service, $tree): void {
                $service->move((int) $tree['buyerA']['id'], (int) $tree['d']['id'], 'left', (int) $tree['admin']['id']);
            },
            BinaryException::class
        );
        assert_same('immutable', $e->reason);

        $node = BinaryNode::find((int) $tree['buyerA']['id']);
        assert_same('LLLLL', (string) ($node['path'] ?? ''));
    },

    'P22C-T35 move() refuses moving a node into its own subtree' => function () use ($buildFixtureTree, $service): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $e = assert_throws(
            function () use ($service, $tree): void {
                $service->move((int) $tree['c']['id'], (int) $tree['buyerA']['id'], 'left', (int) $tree['admin']['id']);
            },
            BinaryException::class
        );
        assert_same('cycle', $e->reason);

        $node = BinaryNode::find((int) $tree['c']['id']);
        assert_same('LLLL', (string) ($node['path'] ?? ''));
    },

    'P22C-T36 move() refuses moving onto a taken slot' => function () use ($buildFixtureTree, $service): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        $e = assert_throws(
            function () use ($service, $tree): void {
                $service->move((int) $tree['sideR']['id'], (int) $tree['boss']['id'], 'left', (int) $tree['admin']['id']);
            },
            BinaryException::class
        );
        assert_same('slot_taken', $e->reason);

        $node = BinaryNode::find((int) $tree['sideR']['id']);
        assert_same('LLR', (string) ($node['path'] ?? ''));
    },

];
