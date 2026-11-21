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
            'INSERT INTO shop_orders
               (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
                billing_name, shipping_name, shipping_address, shipping_country, terms_version, terms_accepted_at)
             VALUES (?, ?, \'paid\', \'ewallet\', 0, 0, 0, \'Test\', \'Test\', \'Test addr\', \'PH\', \'v1\', NOW())'
        )->execute(['TEST-VOL-' . $buyerAId, $buyerAId]);
        $orderId = (int) db()->lastInsertId();

        db()->prepare(
            'INSERT INTO binary_volume (node_user_id, side, kind, points, order_id, source, created_at)
             VALUES (?, \'left\', \'adjustment\', 1000, ?, \'order\', NOW())'
        )->execute([$buyerAId, $orderId]);

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

    /* ==========================================================
     *  P22C-T16 — MAX_DEPTH
     * ========================================================== */

    'P22C-T16 placement refuses beyond MAX_DEPTH (100)' => function () use ($buildFixtureTree, $service, $createUser, $insertNode): void {
        $tree = $buildFixtureTree();
        $service->ensureRoot();

        /* Build a 100-deep chain under d (already at depth 2, path LR),
         * so a placement at depth 101 would exceed MAX_DEPTH. */
        $previous = (int) $tree['d']['id'];
        $path     = 'LR';

        for ($depth = 3; $depth <= 100; $depth++) {
            $member = $createUser('deep_' . $depth);
            $side   = $depth % 2 === 1 ? 'left' : 'right';
            $path   .= $side === 'left' ? 'L' : 'R';

            $insertNode((int) $member['id'], $previous, $side, $depth, $path);
            $previous = (int) $member['id'];
        }

        /* The 101st placement would make depth 101 > MAX_DEPTH. */
        $e = assert_throws(
            function () use ($service, $previous, $createUser): void {
                $service->placeNew($createUser('depth_101')['id'], $previous, 'left');
            },
            BinaryException::class
        );
        assert_same('state', $e->reason);

        /* The chain itself is untouched. */
        $leaf = BinaryNode::find($previous);
        assert_not_null($leaf);
        assert_same(100, (int) ($leaf['depth'] ?? 0));
    },

    /* ==========================================================
     *  P22C-T39 — T46: backfill via BinaryPlacementService::backfill()
     * ========================================================== */

    'P22C-T39 backfill() check-only reports without writing' => function () use ($service, $createUser): void {
        $service->ensureRoot();

        /* Two unplaced members. */
        $m1 = $createUser('bf_member_a');
        $m2 = $createUser('bf_member_b');

        $report = $service->backfill(false);

        assert_same('integer', gettype($report['placed']));
        assert_true($report['dry_run']);
        assert_same(2, $report['placed']);
        assert_null(BinaryNode::forUser((int) $m1['id']), 'no node written in check mode');
        assert_null(BinaryNode::forUser((int) $m2['id']), 'no node written in check mode');
    },

    'P22C-T40 backfill() apply places members under root slots and is idempotent' => function () use ($service, $createUser): void {
        $service->ensureRoot();

        /* Two unplaced members with no sponsor. Root's left is taken by
         * juan (demo seed), so only root right is free. Exactly one
         * member gets placed. */
        $m1 = $createUser('bf_det_a');
        $m2 = $createUser('bf_det_b');

        $first = $service->backfill(true, 2);

        assert_same(1, $first['placed']);
        assert_same(0, $first['floating']);

        /* Exactly one of them got a node. */
        $hasNode1 = BinaryNode::forUser((int) $m1['id']) !== null;
        $hasNode2 = BinaryNode::forUser((int) $m2['id']) !== null;
        assert_true($hasNode1 xor $hasNode2, 'exactly one member placed under root');

        /* The placed member sits under root's right (left is juan's). */
        $placed = $hasNode1 ? $m1 : $m2;
        $node = BinaryNode::forUser((int) $placed['id']);
        assert_same('right', (string) ($node['side'] ?? ''));
        assert_same('R', (string) ($node['path'] ?? ''));

        /* A second apply changes nothing. */
        $second = $service->backfill(true, 2);
        assert_same(0, $second['placed']);
        assert_same(0, $second['floating']);
    },

    'P22C-T41 backfill() apply twice yields identical tree' => function () use ($service, $createUser): void {
        $service->ensureRoot();

        $m1 = $createUser('bf_idem_a');
        $m2 = $createUser('bf_idem_b');

        $service->backfill(true, 2);
        $firstPaths = [
            BinaryNode::forUser((int) $m1['id'])['path'] ?? null,
            BinaryNode::forUser((int) $m2['id'])['path'] ?? null,
        ];
        sort($firstPaths);

        $service->backfill(true, 2);
        $secondPaths = [
            BinaryNode::forUser((int) $m1['id'])['path'] ?? null,
            BinaryNode::forUser((int) $m2['id'])['path'] ?? null,
        ];
        sort($secondPaths);

        assert_same($firstPaths, $secondPaths);
    },

    'P22C-T42 backfill() places under root when the sponsor is staff' => function () use ($service, $createUser, $insertNode): void {
        $service->ensureRoot();

        /* A staff account gets its own root node, but a member with no
         * sponsor falls back to the company root, not the staff root. */
        $staff = $createUser('bf_staff', 'admin');
        $insertNode((int) $staff['id'], null, null, 0, '', 'root');

        $member = $createUser('bf_staff_member');

        $report = $service->backfill(true, 1);

        assert_same(1, $report['placed']);
        assert_same(0, $report['floating']);

        $node = BinaryNode::forUser((int) $member['id']);
        assert_not_null($node);

        /* The member lands under the company root (primary admin), not
         * under the staff's separate root. */
        $companyRoot = BinaryNode::root();
        assert_same((int) $companyRoot['user_id'], (int) ($node['upline_id'] ?? 0));
    },

    'P22C-T43 backfill() never loops on a corrupted sponsor cycle' => function () use ($service, $createUser): void {
        $service->ensureRoot();

        $a = $createUser('bf_cycle_a');
        $b = $createUser('bf_cycle_b');

        /* Corrupt: a sponsors b and b sponsors a. */
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $b['id'], (int) $a['id']]);
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $a['id'], (int) $b['id']]);

        /* Neither has a placed sponsor ancestor, so both go under root. */
        $report = $service->backfill(true);

        assert_same(2, $report['placed']);
        assert_same(0, $report['floating']);

        $nodeA = BinaryNode::forUser((int) $a['id']);
        $nodeB = BinaryNode::forUser((int) $b['id']);
        assert_not_null($nodeA);
        assert_not_null($nodeB);
        assert_not_same((string) ($nodeA['path'] ?? ''), (string) ($nodeB['path'] ?? ''));
    },

    'P22C-T44 backfill() --json shape' => function () use ($service, $createUser): void {
        $service->ensureRoot();

        $m1 = $createUser('bf_json_a');

        $report = $service->backfill(false);

        assert_true(array_key_exists('placed', $report));
        assert_true(array_key_exists('root', $report));
        assert_true(array_key_exists('max_depth', $report));
        assert_true(array_key_exists('floating', $report));
        assert_true(array_key_exists('levels', $report));
        assert_true(array_key_exists('dry_run', $report));
        assert_same('integer', gettype($report['placed']));
        assert_same('boolean', gettype($report['dry_run']));
    },

    'P22C-T45 demoPlacement() builds the fixed demo tree' => function () use ($service, $createUser): void {
        $service->ensureRoot();

        /* Create the five demo members (the seed normally does this). */
        $admin = Member::findByUsername('admin');
        assert_not_null($admin);

        $createUser('juan');
        $createUser('maria');
        $createUser('peter');
        $createUser('sarah');
        $createUser('david');

        $service->demoPlacement();

        $root = BinaryNode::root();
        assert_not_null($root);
        assert_same((int) $admin['id'], (int) $root['user_id']);

        $juan = Member::findByUsername('juan');
        $maria = Member::findByUsername('maria');
        $peter = Member::findByUsername('peter');
        $sarah = Member::findByUsername('sarah');
        $david = Member::findByUsername('david');

        $juanNode = BinaryNode::forUser((int) $juan['id']);
        $mariaNode = BinaryNode::forUser((int) $maria['id']);
        $peterNode = BinaryNode::forUser((int) $peter['id']);
        $sarahNode = BinaryNode::forUser((int) $sarah['id']);
        $davidNode = BinaryNode::forUser((int) $david['id']);

        assert_not_null($juanNode);
        assert_same('left', (string) ($juanNode['side'] ?? ''));
        assert_same('L', (string) ($juanNode['path'] ?? ''));

        assert_not_null($mariaNode);
        assert_same('left', (string) ($mariaNode['side'] ?? ''));
        assert_same('LL', (string) ($mariaNode['path'] ?? ''));

        assert_not_null($peterNode);
        assert_same('left', (string) ($peterNode['side'] ?? ''));
        assert_same('LLL', (string) ($peterNode['path'] ?? ''));

        assert_not_null($sarahNode);
        assert_same('right', (string) ($sarahNode['side'] ?? ''));
        assert_same('LLR', (string) ($sarahNode['path'] ?? ''));

        assert_not_null($davidNode);
        assert_same('right', (string) ($davidNode['side'] ?? ''));
        assert_same('LR', (string) ($davidNode['path'] ?? ''));
    },

    'P22C-T46 demoPlacement() skips members that already have a node' => function () use ($service, $createUser): void {
        $service->ensureRoot();

        $createUser('juan');

        /* Run once: juan goes under admin left. */
        $service->demoPlacement();

        $juan = Member::findByUsername('juan');
        $firstPath = (string) BinaryNode::forUser((int) $juan['id'])['path'];
        assert_same('L', $firstPath);

        /* Run again: juan already has a node, so nothing changes. */
        $service->demoPlacement();

        $secondPath = (string) BinaryNode::forUser((int) $juan['id'])['path'];
        assert_same($firstPath, $secondPath);
    },

];
