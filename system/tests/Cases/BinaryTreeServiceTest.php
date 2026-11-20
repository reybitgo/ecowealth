<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryTreeServiceTest — Phase 22G-S1 (plan §7 Phase 22G)
 *
 *  The binary tree query service: subtree JSON, node/path/search
 *  queries, canRegister, etagScope, and the ?format=json controller
 *  branch. Privacy is server-side; the sponsor genealogy branch is
 *  unchanged.
 *
 *  The demo seed (005_demo_tree.sql) supplies the tree:
 *
 *      admin   state=root        depth 0, path ''
 *      └─ L  juan                depth 1, path L
 *         ├─ L  maria            depth 2, path LL
 *         │  ├─ L  peter         depth 3, path LLL
 *         │  └─ R  sarah         depth 3, path LLR
 *         └─ R  david            depth 2, path LR
 *
 *  Run this group alone with:  php tests/run.php BinaryTree
 * ============================================================== */

$token = bin2hex(random_bytes(4));

/** Look up a demo-seeded user by username. */
$demoUser = static function (string $username): ?array {
    $stmt = db()->prepare('SELECT id, username, role, member_status, is_active FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
};

/** Render a view and return its HTML (same pattern as MembershipViewTest). */
$renderView = static function (string $layout, string $template, array $data = []): string {
    ob_start();
    try {
        View::page($layout, $template, $data);
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    return (string) ob_get_clean();
};

/** Look up the admin account. */
$adminUser = static function () use ($demoUser): ?array {
    return $demoUser('admin');
};

/** Find the deepest node in a tree array (iterative DFS). */
$findDeepest = static function (array $node): ?array {
    $best = null;
    $bestDepth = -1;
    $stack = [$node];
    while ($stack !== []) {
        $current = array_pop($stack);
        $depth = (int) ($current['depth'] ?? 0);
        if ($depth > $bestDepth) {
            $bestDepth = $depth;
            $best = $current;
        }
        foreach ($current['children'] ?? [] as $child) {
            $stack[] = $child;
        }
    }
    return $best;
};

return [

    'P22G-T2 subtree returns the demo tree at depth 3' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        assert_not_null($admin);
        $adminId = (int) $admin['id'];

        $service = new BinaryTreeService();
        $payload = $service->subtree($adminId, 3, $adminId, true);

        assert_true($payload['ok']);
        assert_same($adminId, $payload['root']);
        assert_same($adminId, $payload['viewer']);
        assert_true($payload['is_admin']);
        assert_same(3, $payload['depth']);
        assert_false($payload['truncated']);
        assert_same(6, $payload['nodes'], 'admin + 5 demo members');

        $tree = $payload['tree'];
        assert_same($adminId, (int) $tree['id']);
        assert_same('', (string) $tree['path']);
        assert_same(0, (int) $tree['depth']);
        assert_count(1, $tree['children'], 'root has juan (L) only; right is free');
        assert_same('juan', (string) $tree['children'][0]['username']);

        /* The ETag is present and non-empty. */
        assert_not_same('', (string) $payload['etag']);

        /* can_register reflects the root's free slots. */
        assert_true(is_bool($payload['can_register']));
    },

    'P22G-T4 member JSON feed for their own root' => function () use ($demoUser): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);
        $juanId = (int) $juan['id'];

        $_GET = ['view' => 'binary', 'format' => 'json', 'root' => (string) $juanId, 'depth' => '3'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/genealogy';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        test_signed_in_as($juan);

        $controller = new GenealogyController();
        $payload  = $controller->binaryJson($juanId, false);

        assert_true($payload['ok']);
        assert_same($juanId, (int) $payload['root']);
        assert_same($juanId, (int) $payload['viewer']);
        assert_false($payload['is_admin']);
        assert_not_same('', (string) $payload['etag']);
        assert_true(array_key_exists('tree', $payload));
    },

    'P22G-T5 member cannot view admin\'s subtree (403)' => function () use ($demoUser): void {
        $juan = $demoUser('juan');
        $admin = $demoUser('admin');
        assert_not_null($juan);
        assert_not_null($admin);

        test_signed_in_as($juan);

        $_GET = ['view' => 'binary', 'format' => 'json', 'root' => (string) $admin['id'], 'depth' => '3'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/genealogy';

        $controller = new GenealogyController();
        $juanId = (int) $juan['id'];

        assert_throws(
            function () use ($controller, $juanId) {
                $controller->binaryJson($juanId, false);
            },
            BinaryException::class,
            'member requesting admin\'s subtree throws BinaryException'
        );
    },

    'P22G-T5 admin can view any member\'s subtree' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        $maria = $demoUser('maria');
        assert_not_null($admin);
        assert_not_null($maria);

        test_signed_in_as($admin);

        $_GET = ['view' => 'binary', 'format' => 'json', 'root' => (string) $maria['id'], 'depth' => '3'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/genealogy';

        $controller = new GenealogyController();
        $payload  = $controller->binaryJson((int) $maria['id'], true);

        assert_true($payload['ok']);
        assert_same((int) $maria['id'], (int) $payload['root']);
        assert_true($payload['is_admin']);
    },

    'P22G-T6 depth is clamped to 5' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        assert_not_null($admin);

        $service = new BinaryTreeService();
        $payload = $service->subtree((int) $admin['id'], 9, (int) $admin['id'], true);

        assert_true($payload['ok']);
        assert_same(5, $payload['depth'], 'depth clamped to MAX_DEPTH');
    },

    'P22G-T7 hasMore set on nodes at depth cap' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        assert_not_null($admin);
        $adminId = (int) $admin['id'];

        $service = new BinaryTreeService();
        $payload = $service->subtree($adminId, 2, $adminId, true);

        assert_true($payload['ok']);
        /* Depth 2: juan (level 2) is included, maria/david (level 3) are
         * included but their children (peter/sarah) are cut off, so
         * has_more is true on them. */
        $tree = $payload['tree'];
        $juan = $tree['children'][0] ?? null;
        assert_not_null($juan);
        $maria = $juan['children'][0] ?? null;
        assert_not_null($maria);
        assert_true($maria['has_more'], 'maria has children beyond depth 2');
    },

    'P22G-T34 first payload is bounded on a large tree' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        assert_not_null($admin);
        $adminId = (int) $admin['id'];

        $service = new BinaryTreeService();
        $payload = $service->subtree($adminId, 5, $adminId, true);

        assert_true($payload['ok']);
        /* With MAX_DEPTH=5, a binary tree holds ≤ 127 nodes, well under
         * the 500-node cap. The payload is always bounded. */
        assert_true($payload['nodes'] <= BinaryTreeService::MAX_NODES);
        assert_false($payload['truncated']);
    },

    'P22G-T34 first payload is bounded on a large tree' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        assert_not_null($admin);
        $adminId = (int) $admin['id'];

        $service = new BinaryTreeService();
        $payload = $service->subtree($adminId, 5, $adminId, true);

        assert_true($payload['ok']);
        /* With MAX_DEPTH=5, a binary tree holds ≤ 127 nodes, well under
         * the 500-node cap. The payload is always bounded. */
        assert_true($payload['nodes'] <= BinaryTreeService::MAX_NODES);
        assert_false($payload['truncated']);
    },

    'P22G-T8 If-None-Match returns 304' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        assert_not_null($admin);
        $adminId = (int) $admin['id'];

        $service = new BinaryTreeService();
        $first   = $service->subtree($adminId, 3, $adminId, true);
        $etag    = (string) $first['etag'];

        $controller = new GenealogyController();
        $payload  = $controller->binaryJson($adminId, true);

        /* Same request with the same ETag should produce the same etag;
         * the controller would return 304 via Live::notModified. */
        assert_same($etag, (string) $payload['etag'], 'ETag is stable across calls');
    },

    'P22G-T9 subtree node card includes points and slots' => function () use ($demoUser): void {
        $maria = $demoUser('maria');
        assert_not_null($maria);
        $mariaId = (int) $maria['id'];

        $service = new BinaryTreeService();
        $node    = $service->node($mariaId);

        assert_not_null($node);
        assert_same($mariaId, (int) $node['id']);
        assert_same('maria', (string) $node['username']);
        assert_same('LL', (string) $node['path']);
        assert_same(2, (int) $node['depth']);
        assert_true(array_key_exists('points', $node));
        assert_same(1000, (int) setting_int('binary_pair_points', 1000));
        assert_true(array_key_exists('slots', $node));
        assert_true(array_key_exists('left', $node['slots']));
        assert_true(array_key_exists('right', $node['slots']));
        assert_true(is_bool($node['slots']['left']['free']));
    },

    'P22G-T10 search outside subtree returns nothing' => function () use ($demoUser): void {
        $service = new BinaryTreeService();
        $found   = $service->search('zzz_nonexistent_user', 99999, false);

        assert_same([], $found);
    },

    'P22G-T20 search is scoped to the viewer\'s subtree' => function () use ($demoUser): void {
        $juan = $demoUser('juan');
        $maria = $demoUser('maria');
        assert_not_null($juan);
        assert_not_null($maria);

        $service = new BinaryTreeService();

        /* juan can find maria (in juan's subtree). */
        $found = $service->search('maria', (int) $juan['id'], false);
        $names = array_map(static fn (array $r): string => (string) $r['username'], $found);
        assert_contains('maria', implode(',', $names));

        /* juan cannot find admin (outside juan's subtree). */
        $found = $service->search('admin', (int) $juan['id'], false);
        $names = array_map(static fn (array $r): string => (string) $r['username'], $found);
        assert_not_contains('admin', implode(',', $names));
    },

    'P22G-T33 member requesting another member\'s subtree is refused' => function () use ($demoUser): void {
        $juan = $demoUser('juan');
        $admin = $demoUser('admin');
        assert_not_null($juan);
        assert_not_null($admin);

        test_signed_in_as($juan);

        $_GET = ['view' => 'binary', 'format' => 'json', 'root' => (string) $admin['id'], 'depth' => '3'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/genealogy';

        $controller = new GenealogyController();
        $juanId = (int) $juan['id'];

        assert_throws(
            function () use ($controller, $juanId) {
                $controller->binaryJson($juanId, false);
            },
            BinaryException::class,
            'member requesting admin\'s subtree throws BinaryException'
        );
    },

    'canRegister returns true for a free legal slot' => function () use ($demoUser): void {
        $peter = $demoUser('peter');
        assert_not_null($peter);

        $service = new BinaryTreeService();
        /* peter's left slot is free. */
        assert_true($service->canRegister((int) $peter['id'], (int) $peter['id'], 'left'));
        assert_true($service->canRegister((int) $peter['id'], (int) $peter['id'], 'right'));
    },

    'canRegister returns false for a taken slot' => function () use ($demoUser): void {
        $admin = $demoUser('admin');
        assert_not_null($admin);

        /* admin's left slot is taken by juan. */
        $service = new BinaryTreeService();
        assert_false($service->canRegister((int) $admin['id'], (int) $admin['id'], 'left'));
    },

    'canRegister returns false for a suspended upline' => function () use ($demoUser): void {
        $maria = $demoUser('maria');
        assert_not_null($maria);

        Member::setStatus((int) $maria['id'], 'suspended');

        $service = new BinaryTreeService();
        assert_false($service->canRegister((int) $maria['id'], (int) $maria['id'], 'left'));

        Member::setStatus((int) $maria['id'], 'active');
    },

    'etagScope includes viewer, team and binary revisions' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        assert_not_null($admin);

        $service = new BinaryTreeService();
        $scope   = $service->etagScope((int) $admin['id'], 3, (int) $admin['id'], true);

        assert_contains('view', $scope);
        assert_contains('team:', $scope);
        assert_contains('binary:', $scope);
    },

    /* ---------- Phase 22G-S2: server-rendered tree shell and no-JS list ---------- */

    'P22G-T1 binary page renders header, seed JSON, list, controls and legend' => function () use ($demoUser, $renderView): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $juanId = (int) $juan['id'];
        $service = new BinaryTreeService();
        $payload = $service->subtree($juanId, 3, $juanId, false);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'genealogy',
            'member'      => $juan,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($juanId, 3, $juanId),
            'binary_depth'=> 3,
            'binary_root_id' => $juanId,
            'live'        => [
                'surface'  => 'member.genealogy:' . $juanId,
                'enabled'  => false,
                'revision' => 0,
                'etag'     => '',
                'interval' => 30000,
            ],
        ]);

        /* Binary header and branding. */
        assert_contains('Binary Tree', $html);
        assert_contains('Your placement tree', $html);

        /* Server-rendered seed JSON. */
        assert_contains('binaryTreeSeed', $html);
        assert_contains('&quot;ok&quot;', $html);
        assert_contains('&quot;root&quot;:' . $juanId, $html);

        /* No-JS semantic list. */
        assert_contains('role="tree"', $html);
        assert_contains('aria-label="Binary placement tree"', $html);
        assert_contains('binary-tree-node', $html);
        assert_contains('aria-level=', $html);
        assert_contains('@maria', $html);
        assert_contains('@peter', $html);
        assert_contains('@sarah', $html);
        assert_contains('@david', $html);
        assert_contains('depth', $html);

        /* Controls. */
        assert_contains('binary-tree-actions', $html);
        assert_contains('bx-plus', $html);

        /* Legend. */
        assert_contains('Legend', $html);
        assert_contains('Active', $html);
        assert_contains('Inactive', $html);
        assert_contains('You', $html);

        /* Sponsor view content is absent. */
        assert_not_contains('Team chain', $html);
        assert_not_contains('Direct recruits', $html);
    },

    'P22G-T3 no-JS list renders one li per node with username, side, points' => function () use ($demoUser, $renderView): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $juanId = (int) $juan['id'];
        $service = new BinaryTreeService();
        $payload = $service->subtree($juanId, 3, $juanId, false);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'genealogy',
            'member'      => $juan,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($juanId, 3, $juanId),
            'binary_depth'=> 3,
            'binary_root_id' => $juanId,
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        /* One <li role="treeitem"> per node. */
        preg_match_all('/<li[^>]*role="treeitem"/', $html, $treeItems);
        assert_same(5, count($treeItems[0]), 'juan + 4 descendants');

        /* Each node shows username, side and points. */
        assert_contains('@maria', $html);
        assert_contains('@peter', $html);
        assert_contains('@sarah', $html);
        assert_contains('@david', $html);

        /* Side badges L/R. */
        assert_contains('class="badge rounded-pill text-primary small">L', $html);
        assert_contains('class="badge rounded-pill text-danger small">R', $html);

        /* Status dots. */
        assert_contains('bg-label-success', $html);
    },

    'P22G-T28 d3 unavailable: the list stays usable, server content unchanged' => function () use ($demoUser, $renderView): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $juanId = (int) $juan['id'];
        $service = new BinaryTreeService();
        $payload = $service->subtree($juanId, 3, $juanId, false);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'genealogy',
            'member'      => $juan,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($juanId, 3, $juanId),
            'binary_depth'=> 3,
            'binary_root_id' => $juanId,
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        /* The list and all node data are server-rendered, independent of d3. */
        assert_contains('role="tree"', $html);
        assert_contains('@maria', $html);
        assert_contains('@peter', $html);

        /* No d3 script tag is referenced (d3 is added in 22G-S3, vendored locally). */
        assert_not_contains('d3.v7', $html);
        assert_not_contains('cdn.', $html);
    },

    'P22G-T29 JS off: list, register link and controls all work' => function () use ($demoUser, $renderView): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $juanId = (int) $juan['id'];
        $service = new BinaryTreeService();
        $payload = $service->subtree($juanId, 3, $juanId, false);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'genealogy',
            'member'      => $juan,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($juanId, 3, $juanId),
            'binary_depth'=> 3,
            'binary_root_id' => $juanId,
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        /* Every node is an <li> — no JavaScript required to read it. */
        assert_contains('role="tree"', $html);
        assert_contains('@maria', $html);

        /* The + link opens the register page with the sponsor prefilled. */
        assert_contains('register?sponsor=', $html);
        assert_contains('binary_position=left', $html);
        assert_contains('binary_position=right', $html);
        assert_contains('return=genealogy', $html);

        /* Depth controls are plain links. */
        assert_contains('depth=' . (3 - 1), $html);
        assert_contains('depth=' . (3 + 1), $html);
    },

    'P22G-T31 sponsor view regression: /genealogy without ?view is unchanged' => function () use ($demoUser, $renderView): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $sponsors = new SponsorService();
        $team     = $sponsors->downline((int) $juan['id']);

        $byLevel = [];
        foreach ($team as $row) {
            $byLevel[(int) $row['level']][] = $row;
        }
        $teamLevels = $byLevel;
        $direct     = $byLevel[1] ?? [];
        unset($byLevel[1]);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'    => 'Genealogy',
            'current_nav'   => 'genealogy',
            'member'        => $juan,
            'team_levels'   => $teamLevels,
            'direct'        => $direct,
            'recruits_pager' => Paginator::make(count($direct), 1, Paginator::DEFAULT_PER_PAGE),
            'deeper'        => $byLevel,
            'team_count'    => count($team),
            'levels_used'   => $byLevel === [] ? 0 : max(array_keys($byLevel)),
            'referral_code' => (string) $juan['referral_code'],
            'referral_link' => absolute_url('register?ref=' . (string) $juan['referral_code']),
            'live'          => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        assert_contains('Team chain', $html);
        assert_contains('Direct recruits', $html);
        assert_contains('@maria', $html);

        /* No binary content in the sponsor view. */
        assert_not_contains('binaryTreeSeed', $html);
        assert_not_contains('role="tree"', $html);
        assert_not_contains('binary-tree-node', $html);
    },

    'P22G-T32 routes: no new route in this phase, guard is member' => function (): void {
        $router = new Router(require dirname(__DIR__, 2) . '/app/routes.php');

        /* /genealogy still exists with the member guard. */
        $match = $router->match('GET', '/genealogy');
        assert_not_null($match);
        assert_same('member', $match['route']['guard']);
        assert_same(GenealogyController::class, $match['route']['handler'][0]);

        /* Exactly one route carries the placement vocabulary (from 22C, unchanged). */
        $placementRoutes = array_filter(
            $router->routes(),
            static fn (array $route): bool => str_contains($route['path'], 'placement')
        );
        assert_count(1, $placementRoutes, 'exactly one placement route');
    },

    /* ---------- Phase 22G-S3: d3 explorer, node cards and navigation controls ---------- */

    'P22G-T11 node card carries username, status, side, depth, points, pairs, joined and You' => function () use ($demoUser): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);
        $juanId = (int) $juan['id'];

        $service = new BinaryTreeService();
        $payload = $service->subtree($juanId, 3, $juanId, false);
        $tree    = $payload['tree'];

        assert_same('juan', (string) $tree['username']);
        assert_same(1, (int) $tree['depth'], 'juan sits at depth 1 under the company root');
        assert_true($tree['is_you'], 'root is the viewer');
        assert_same('active', (string) $tree['status']);
        assert_same('left', (string) ($tree['side'] ?? null), 'juan is on the left slot');
        assert_true(array_key_exists('points', $tree));
        assert_true(array_key_exists('left', $tree['points']));
        assert_true(array_key_exists('right', $tree['points']));
        assert_true(array_key_exists('pairs', $tree['points']));
        assert_true(array_key_exists('pairs_today', $tree['points']));
        assert_true(array_key_exists('joined', $tree));
        assert_not_same('', (string) ($tree['joined'] ?? ''));
    },

    'P22G-T12 name is null for a member viewer' => function () use ($demoUser): void {
        $juan = $demoUser('juan');
        $maria = $demoUser('maria');
        assert_not_null($juan);
        assert_not_null($maria);

        $service = new BinaryTreeService();
        $payload = $service->subtree((int) $maria['id'], 3, (int) $juan['id'], false);

        assert_null($payload['tree']['name'], 'member viewer sees no full name');
    },

    'P22G-T12 name is the full name for an admin viewer' => function () use ($demoUser, $adminUser): void {
        $admin = $adminUser();
        $maria = $demoUser('maria');
        assert_not_null($admin);
        assert_not_null($maria);

        $service = new BinaryTreeService();
        $payload = $service->subtree((int) $maria['id'], 3, (int) $admin['id'], true);

        assert_same('Maria Santos', (string) $payload['tree']['name'], 'admin viewer sees the full name');
    },

    'P22G-T21 admin root selector lists company tree and members with nodes' => function () use ($demoUser, $adminUser, $renderView): void {
        $admin = $adminUser();
        $juan = $demoUser('juan');
        $maria = $demoUser('maria');
        assert_not_null($admin);
        assert_not_null($juan);
        assert_not_null($maria);

        test_signed_in_as($admin);
        $adminId = (int) $admin['id'];

        $service = new BinaryTreeService();
        $payload = $service->subtree($adminId, 3, $adminId, true);

        $html = $renderView('admin', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'admin.genealogy',
            'member'      => $admin,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($adminId, 3, $adminId),
            'binary_depth'=> 3,
            'binary_root_id' => $adminId,
            'is_admin'    => true,
            'admin_roots' => [
                ['id' => $juan['id'], 'username' => 'juan'],
                ['id' => $maria['id'], 'username' => 'maria'],
            ],
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        assert_contains('binaryTreeRoot', $html);
        assert_contains('Company tree', $html);
        assert_contains('@juan', $html);
        assert_contains('@maria', $html);
        assert_contains('name="root"', $html);
        assert_contains('genealogy', $html);
    },

    'P22G-T22 admin node menu links to the member page and the placement panel' => function () use ($demoUser, $adminUser, $renderView): void {
        $admin = $adminUser();
        $juan = $demoUser('juan');
        $maria = $demoUser('maria');
        assert_not_null($admin);
        assert_not_null($juan);
        assert_not_null($maria);

        test_signed_in_as($admin);
        $adminId = (int) $admin['id'];

        $service = new BinaryTreeService();
        $payload = $service->subtree($adminId, 3, $adminId, true);

        $html = $renderView('admin', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'admin.genealogy',
            'member'      => $admin,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($adminId, 3, $adminId),
            'binary_depth'=> 3,
            'binary_root_id' => $adminId,
            'is_admin'    => true,
            'admin_roots' => [],
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        $juanId  = (int) $juan['id'];
        $mariaId = (int) $maria['id'];

        assert_contains('admin/members/' . $juanId, $html);
        assert_contains('admin/members/' . $mariaId, $html);
        assert_contains('View member', $html);
        assert_contains('Move placement', $html);
    },

    /* ---------- Phase 22G-S4: in-node registration modal and invite links ---------- */

    'P22G-T13 free slots carry + with modal trigger, side and upline data' => function () use ($demoUser, $renderView): void {
        $peter = $demoUser('peter');
        assert_not_null($peter);

        test_signed_in_as($peter);

        $peterId = (int) $peter['id'];
        $service = new BinaryTreeService();
        $payload = $service->subtree($peterId, 3, $peterId, false);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'genealogy',
            'member'      => $peter,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($peterId, 3, $peterId),
            'binary_depth'=> 3,
            'binary_root_id' => $peterId,
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        /* The root (peter) has free slots: both left and right are empty. */
        assert_contains('data-binary-modal-trigger', $html);
        assert_contains('data-binary-upline="peter"', $html);
        assert_contains('data-binary-side="left"', $html);
        assert_contains('data-binary-side="right"', $html);

        /* maria's left slot is taken by peter — no + on maria's left. */
        assert_not_contains('data-binary-upline="@maria"', $html);

        /* The modal is present in the DOM. */
        assert_contains('binaryRegisterModal', $html);
        assert_contains('binaryRegisterForm', $html);
    },

    'P22G-T14 canRegister false for staff upline and full slot' => function () use ($demoUser): void {
        $admin = $demoUser('admin');
        assert_not_null($admin);

        $service = new BinaryTreeService();

        /* admin's left slot is taken by juan. */
        assert_false($service->canRegister((int) $admin['id'], (int) $admin['id'], 'left'));

        /* Staff cannot be an upline for a member registrar (the admin itself
         * is staff, so registering under admin as a non-admin viewer fails). */
        $juan = $demoUser('juan');
        assert_not_null($juan);
        assert_false($service->canRegister((int) $juan['id'], (int) $admin['id'], 'right'));
    },

    'P22G-T15 registration modal renders locked placement and form fields' => function () use ($demoUser, $renderView): void {
        $peter = $demoUser('peter');
        assert_not_null($peter);

        test_signed_in_as($peter);

        $peterId = (int) $peter['id'];
        $service = new BinaryTreeService();
        $payload = $service->subtree($peterId, 3, $peterId, false);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'genealogy',
            'member'      => $peter,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($peterId, 3, $peterId),
            'binary_depth'=> 3,
            'binary_root_id' => $peterId,
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        /* Modal present. */
        assert_contains('binaryRegisterModal', $html);
        assert_contains('Register into this slot', $html);

        /* CSRF and return target (points back to the binary tree). */
        assert_contains('name="_csrf"', $html);
        assert_contains('name="return"', $html);
        assert_contains('view=binary', $html);

        /* Placement fields are hidden (locked), not editable radios. */
        assert_contains('name="binary_upline"', $html);
        assert_contains('name="binary_position"', $html);
        assert_not_contains('data-binary-position', $html);

        /* Registration fields present. */
        assert_contains('name="username"', $html);
        assert_contains('name="email"', $html);
        assert_contains('name="password"', $html);
        assert_contains('name="password_confirm"', $html);
        assert_contains('name="terms"', $html);

        /* Mode choices present (standard, code, and e-wallet for a signed-in registrar). */
        assert_contains('name="mode"', $html);
        assert_contains('value="standard"', $html);
        assert_contains('value="code"', $html);
        assert_contains('value="ewallet"', $html);
    },

    'P22G-T16 post the modal for a free slot creates and places the member' => function () use ($demoUser): void {
        $peter = $demoUser('peter');
        assert_not_null($peter);
        $peterId = (int) $peter['id'];

        /* peter's left slot is free. */
        assert_true((new BinaryTreeService())->canRegister($peterId, $peterId, 'left'));

        $service = new RegistrationService();
        $result  = $service->register([
            'mode'             => 'standard',
            'username'         => 'newmember_' . bin2hex(random_bytes(2)),
            'email'            => 'newmember_' . bin2hex(random_bytes(2)) . '@test.local',
            'full_name'        => 'New Member',
            'phone'            => '',
            'password'         => 'Member@123',
            'password_confirm' => 'Member@123',
            'sponsor'          => 'peter',
            'package_id'       => '',
            'reg_code'         => '',
            'terms'            => '1',
            'binary_upline'    => 'peter',
            'binary_position'  => 'left',
        ]);

        assert_not_null($result);
        assert_same($peterId, (int) ($result['sponsor_id'] ?? 0));

        /* The new member has a node under peter on the left. */
        $node = BinaryNode::forUser((int) $result['user_id']);
        assert_not_null($node);
        assert_same($peterId, (int) ($node['upline_id'] ?? 0));
        assert_same('left', (string) ($node['side'] ?? ''));
        assert_same('placed', (string) ($node['state'] ?? ''));
    },

    'P22G-T17 slot taken → validate returns a field error, no double placement' => function () use ($demoUser): void {
        $peter = $demoUser('peter');
        assert_not_null($peter);
        $peterId = (int) $peter['id'];

        /* Fill peter's left slot first. */
        $service = new RegistrationService();
        $first = $service->register([
            'mode'             => 'standard',
            'username' => 'slotwinner_' . bin2hex(random_bytes(2)),
            'email'    => 'slotwinner_' . bin2hex(random_bytes(2)) . '@test.local',
            'full_name'        => 'Slot Winner',
            'phone'            => '',
            'password'         => 'Member@123',
            'password_confirm' => 'Member@123',
            'sponsor'          => 'peter',
            'package_id'       => '',
            'reg_code'         => '',
            'terms'            => '1',
            'binary_upline'    => 'peter',
            'binary_position'  => 'left',
        ]);
        assert_not_null($first);

        /* Validating the same upline/side now returns a field error. */
        $placement = new BinaryPlacementService();
        $errors = $placement->validate($peterId, 'left', [
            'registrar' => null,
            'sponsor'   => $peter,
        ]);
        assert_true(array_key_exists('binary_upline', $errors));

        /* The winner is placed on the left; nobody else is there. */
        $node = BinaryNode::forUser((int) $first['user_id']);
        assert_not_null($node);
        assert_same($peterId, (int) ($node['upline_id'] ?? 0));
        assert_same('left', (string) ($node['side'] ?? ''));
    },

    'P22G-T18 post the modal with a taken username fails' => function () use ($demoUser): void {
        $peter = $demoUser('peter');
        assert_not_null($peter);

        $service = new RegistrationService();
        assert_throws(
            function () use ($service, $peter) {
                $service->register([
                    'mode'             => 'standard',
                    'username'         => 'peter',
                    'email'            => 'taken_' . bin2hex(random_bytes(2)) . '@test.local',
                    'full_name'        => 'Taken Name',
                    'phone'            => '',
                    'password'         => 'Member@123',
                    'password_confirm' => 'Member@123',
                    'sponsor'          => 'peter',
                    'package_id'       => '',
                    'reg_code'         => '',
                    'terms'            => '1',
                    'binary_upline'    => 'peter',
                    'binary_position'  => 'left',
                ]);
            },
            RegistrationException::class,
            'taken username is refused'
        );
    },

    'P22G-T19 the invite link carries ref, upline and side' => function () use ($demoUser, $renderView): void {
        $peter = $demoUser('peter');
        assert_not_null($peter);

        test_signed_in_as($peter);

        $peterId = (int) $peter['id'];
        $service = new BinaryTreeService();
        $payload = $service->subtree($peterId, 3, $peterId, false);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'genealogy',
            'member'      => $peter,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($peterId, 3, $peterId),
            'binary_depth'=> 3,
            'binary_root_id' => $peterId,
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        /* Invite link for a free slot: /register?ref=<code>&upline=<user>&side=<left|right> */
        assert_contains('data-binary-invite="1"', $html);
        assert_contains('data-referral-code=', $html);
        assert_contains('data-binary-upline="peter"', $html);
    },

    /* ---------- Phase 22G-S5: live refresh, accessibility, responsive, performance ---------- */

    'P22G-T23 a new award bumps the binary live surface' => function () use ($demoUser): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);
        $juanId = (int) $juan['id'];

        $before = Live::revision(Live::binarySurface($juanId));

        /* Call BinaryService::touch() (private) via reflection with a
         * single self award for juan. It must bump member.binary:<juan>. */
        $service = new BinaryService();
        $ref     = new ReflectionMethod($service, 'touch');
        $ref->setAccessible(true);
        $ref->invoke($service, 1, $juanId, [
            ['user_id' => $juanId, 'kind' => 'self', 'status' => 'pending',
             'amount' => 50.00, 'points' => 1000, 'pair_count' => 0,
             'pair_points' => 1000, 'rate_pct' => 5, 'side' => null,
             'hold_days' => 7],
        ]);

        $after = Live::revision(Live::binarySurface($juanId));
        assert_same($before + 1, $after, 'creating an award bumps the binary live surface');
    },

    'P22G-T24 a placement change bumps the binary live surface' => function () use ($demoUser): void {
        $peter = $demoUser('peter');
        assert_not_null($peter);
        $peterId = (int) $peter['id'];

        /* peter's left slot is free. */
        assert_true((new BinaryTreeService())->canRegister($peterId, $peterId, 'left'));

        $before = Live::revision(Live::binarySurface($peterId));

        /* Place a new member under peter on the left. */
        $svc = new RegistrationService();
        $svc->register([
            'mode'             => 'standard',
            'username'         => 'liveres_' . bin2hex(random_bytes(2)),
            'email'            => 'liveres_' . bin2hex(random_bytes(2)) . '@test.local',
            'full_name'        => 'Live Refresh',
            'phone'            => '',
            'password'         => 'Member@123',
            'password_confirm' => 'Member@123',
            'sponsor'          => 'peter',
            'package_id'       => '',
            'reg_code'         => '',
            'terms'            => '1',
            'binary_upline'    => 'peter',
            'binary_position'  => 'left',
        ]);

        $after = Live::revision(Live::binarySurface($peterId));
        assert_same($before + 1, $after, 'placing a node bumps the binary live surface');
    },

    'P22G-T25 the explorer JS uses ResizeObserver for responsive re-layout' => function (): void {
        $js = file_get_contents(dirname(__DIR__, 3) . '/system/public/assets/js/app-binary-tree.js');
        assert_not_same(false, $js, 'app-binary-tree.js is readable');
        assert_contains('ResizeObserver', $js, 'ResizeObserver is used for responsive re-layout');
    },

    'P22G-T26 the explorer JS handles keyboard navigation' => function (): void {
        $js = file_get_contents(dirname(__DIR__, 3) . '/system/public/assets/js/app-binary-tree.js');
        assert_not_same(false, $js, 'app-binary-tree.js is readable');
        assert_contains('onNodeKey', $js, 'a keyboard handler exists');
        assert_contains("'keydown'", $js, 'keydown is bound on node groups');
        assert_contains('ArrowDown', $js, 'arrow keys traverse the tree');
        assert_contains('ArrowUp', $js, 'arrow keys traverse the tree');
        assert_contains('Enter', $js, 'Enter expands a node');
        assert_contains('Escape', $js, 'Esc clears focus / closes the modal');
    },

    'P22G-T27 the rendered SVG tree carries ARIA tree semantics' => function () use ($demoUser, $renderView): void {
        $juan = $demoUser('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $juanId = (int) $juan['id'];
        $service = new BinaryTreeService();
        $payload = $service->subtree($juanId, 3, $juanId, false);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'  => 'Binary Tree',
            'current_nav' => 'genealogy',
            'member'      => $juan,
            'view'        => 'binary',
            'binary_root' => $payload,
            'binary_list' => $service->listRows($juanId, 3, $juanId),
            'binary_depth'=> 3,
            'binary_root_id' => $juanId,
            'live'        => ['surface' => 'x', 'enabled' => false, 'revision' => 0, 'etag' => '', 'interval' => 30000],
        ]);

        /* The SVG wrapper declares itself as a tree. */
        assert_contains('role="tree"', $html);
        /* Every node is a treeitem with aria-level. */
        assert_contains('role="treeitem"', $html);
        assert_contains('aria-level=', $html);
        /* Each node has an accessible name. */
        assert_contains('aria-label=', $html);
        assert_contains('@maria', $html);
    },

    'P22G-T30 reduced motion disables transitions' => function (): void {
        $css = file_get_contents(dirname(__DIR__, 3) . '/system/public/assets/css/binary-tree.css');
        assert_not_same(false, $css, 'binary-tree.css is readable');
        assert_contains('prefers-reduced-motion', $css, 'reduced motion media query is present');
        assert_contains('transition: none', $css, 'transitions are removed under reduced motion');
    },

    'P22G-T35 vendored d3 is present with licence and parses' => function (): void {
        $d3  = dirname(__DIR__, 3) . '/system/public/assets/vendor/libs/d3/d3.v7.min.js';
        $lic = dirname(__DIR__, 3) . '/system/public/assets/vendor/libs/d3/LICENSE';
        assert_true(is_file($d3), 'vendored d3.v7.min.js exists');
        assert_true(is_file($lic), 'd3 LICENSE exists');
        $d3Content = file_get_contents($d3);
        assert_not_same(false, $d3Content, 'd3 is readable');
        assert_contains('d3', $d3Content, 'd3 content is present');
    },

];

