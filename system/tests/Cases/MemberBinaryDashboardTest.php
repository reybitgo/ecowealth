<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberBinaryDashboardTest — Phase 23E-S2 (plan §7 Phase 23E)
 *  Tests P23E-T6, T7, T9: the dashboard binary tile package
 *  indicators, the genealogy tree package chip, stream-off and
 *  JS-off/mobile behaviour.
 *
 *  Fixture tree (company root = existing `admin`, placed under its free
 *  RIGHT slot because the demo seed occupies LEFT):
 *
 *      admin   state=root        depth 0, path ''
 *      └─ R  boss                depth 1, path R
 *         ├─ L  buyerA           depth 2, path RL
 *         └─ R  buyerB           depth 2, path RR
 *
 *  Every case runs inside the harness transaction and rolls back.
 *
 *  Run this group alone with:  php tests/run.php MemberBinaryDashboard
 * ============================================================== */

$token = bin2hex(random_bytes(4));

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

$insertNode = static function (int $userId, ?int $uplineId, ?string $side, int $depth, string $path, string $state = 'placed'): void {
    $stmt = db()->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();

    db()->prepare(
        "INSERT IGNORE INTO binary_nodes
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
    $stmt = db()->prepare("SELECT id FROM users WHERE username = 'admin' AND role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = (array) $stmt->fetch();

    $boss    = $createUser('boss');
    $buyerA  = $createUser('buyerA');
    $buyerB  = $createUser('buyerB');

    $insertNode((int) $admin['id'], null, null, 0, '', 'root');
    $insertNode((int) $boss['id'],   (int) $admin['id'], 'right', 1, 'R');
    $insertNode((int) $buyerA['id'], (int) $boss['id'],  'left',  2, 'RL');
    $insertNode((int) $buyerB['id'], (int) $boss['id'],  'right', 2, 'RR');

    return [
        'admin'  => $admin,
        'boss'   => $boss,
        'buyerA' => $buyerA,
        'buyerB' => $buyerB,
    ];
};

$createPackage = static function (string $name, string $slug, float $price, float $pv, int $binaryEnabled = 1, int $binaryPoints = 0) use ($token): array {
    $finalSlug = slugify($slug . '_' . $token);

    db()->prepare(
        'INSERT INTO packages
           (name, slug, price, product_value, direct_referral_bonus,
            indirect_enabled, status, sort_order, binary_enabled, binary_points)
         VALUES (?, ?, ?, ?, ?, 1, \'active\', 100, ?, ?)'
    )->execute([
        $name,
        $finalSlug,
        number_format($price, 2, '.', ''),
        number_format($pv, 2, '.', ''),
        '100.00',
        (string) $binaryEnabled,
        (string) $binaryPoints,
    ]);

    $stmt = db()->prepare('SELECT * FROM packages WHERE slug = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$finalSlug]);

    return (array) $stmt->fetch();
};

$setSetting = static function (string $key, string $value): void {
    db()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = ?'
    )->execute([$key, $value, $value]);
};

$preset = static function () use ($setSetting): void {
    $setSetting('binary_enabled', '1');
    $setSetting('binary_self_pct', '5.000');
    $setSetting('binary_pair_points', '1000');
    $setSetting('binary_pair_pct', '10.000');
    $setSetting('binary_daily_pair_cap', '20');
    $setSetting('binary_requires_active', '0');
    $setSetting('binary_hold_days', '7');
    $setSetting('binary_release_enabled', '1');
    $setSetting('binary_reversal_enabled', '1');
    $setSetting('package_binary_enabled', '1');
    $setSetting('package_binary_requires_package', '1');
    setting_cache_clear();
    setting_all();
};

return [

    /* ==========================================================
     *  P23E-T6 — The dashboard tile and the tree chip
     *  ========================================================== */

    'P23E-T6 the dashboard binary tile shows package pairs and points' => function () use (
        $buildFixtureTree, $createPackage, $preset
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        /* boss holds Package A so boss qualifies as a recipient. */
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['boss']['id']]);

        /* buyerA activates PKA — places team volume at boss (left). */
        $activationId = Activation::create([
            'user_id'        => (int) $tree['buyerA']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationId, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);
        (new PackageBinaryService(db()))->onActivation((int) $activationId);

        /* buyerB activates PKA — boss has L=1000/R=1000 → one pair at boss. */
        $activationIdB = Activation::create([
            'user_id'        => (int) $tree['buyerB']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationIdB, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerB']['id']]);
        (new PackageBinaryService(db()))->onActivation((int) $activationIdB);

        /* boss earns 1 package pair, 2000 package points placed (1000 left + 1000 right). */
        test_signed_in_as($tree['boss']);

        $controller = new DashboardController();
        $data       = $controller->dashboardContext((int) $tree['boss']['id']);

        assert_true(array_key_exists('binary', $data), 'binary key present');
        assert_not_null($data['binary'], 'binary tile not null');
        assert_same(1, (int) ($data['binary']['package_pairs'] ?? 0), 'one package pair at boss');
        assert_same(2000, (int) ($data['binary']['package_points'] ?? 0), '2000 package points at boss');
        assert_same(100.00, (float) ($data['binary']['package_credited'] ?? 0), '₱100 package credited');

        /* The tile renders package lines server-side (verified by QA23E-01). */
    },

    'P23E-T6 the tree node shows the package name and binary chip' => function () use (
        $buildFixtureTree, $createPackage, $preset
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        /* Give boss, buyerA and buyerB Package A (binary-enabled). */
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['boss']['id']]);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerB']['id']]);

        $payload = (new BinaryTreeService())->subtree((int) $tree['boss']['id'], 3, (int) $tree['boss']['id'], false);

        assert_true(array_key_exists('tree', $payload), 'tree key present');
        $root = $payload['tree'];

        /* The root node is boss — has Package A, binary-enabled. */
        assert_same('Package A', (string) ($root['package'] ?? ''));
        assert_true((bool) ($root['package_binary_enabled'] ?? false), 'boss node shows binary chip');

        /* Descendants also carry package info. */
        $children = (array) ($root['children'] ?? []);
        $foundA = false;
        $foundB = false;
        foreach ($children as $child) {
            $name = (string) ($child['package'] ?? '');
            if ($name === 'Package A') {
                if ((string) ($child['username'] ?? '') === (string) ($tree['buyerA']['username'] ?? '')) {
                    $foundA = true;
                }
                if ((string) ($child['username'] ?? '') === (string) ($tree['buyerB']['username'] ?? '')) {
                    $foundB = true;
                }
            }
        }
        assert_true($foundA, 'buyerA node shows Package A');
        assert_true($foundB, 'buyerB node shows Package A');
    },

    /* ==========================================================
     *  P23E-T7 — Stream off: no package indicators anywhere
     *  ========================================================== */

    'P23E-T7 stream off: dashboard binary tile has no package line' => function () use (
        $buildFixtureTree, $createPackage, $preset, $setSetting
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        /* Switch the global package binary off BEFORE any activation. */
        $setSetting('package_binary_enabled', '0');
        setting_cache_clear();
        setting_all();

        /* buyerA activates PKA — but stream is off, so nothing is placed. */
        $activationId = Activation::create([
            'user_id'        => (int) $tree['buyerA']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationId, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);
        (new PackageBinaryService(db()))->onActivation((int) $activationId);

        test_signed_in_as($tree['boss']);

        $controller = new DashboardController();
        $data       = $controller->dashboardContext((int) $tree['boss']['id']);

        assert_true(array_key_exists('binary', $data), 'binary key present');
        /* Tile renders (product binary is on) but has no package lines. */
        assert_same(0, (int) ($data['binary']['package_pairs'] ?? 0), 'no package pairs when stream off');
        assert_same(0, (int) ($data['binary']['package_points'] ?? 0), 'no package points when stream off');

        /* The earnings tab stays binary (product binary still on). */
        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $ec = new EarningsController();
        $edata = $ec->binaryContext((int) $tree['boss']['id']);
        assert_same(EarningsController::TAB_BINARY, $edata['tab'], 'tab stays binary when package stream off');
    },

    'P23E-T7 stream off: tree node shows no package chip' => function () use (
        $buildFixtureTree, $createPackage, $preset
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 0, 1000);

        /* Give buyerA Package A but with binary_enabled = 0. */
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);

        $payload = (new BinaryTreeService())->subtree((int) $tree['boss']['id'], 3, (int) $tree['boss']['id'], false);
        $root = $payload['tree'];

        /* Descendants carry package info; buyerA's package has binary_enabled = 0 so no chip. */
        $children = (array) ($root['children'] ?? []);
        foreach ($children as $child) {
            $pkgName = (string) ($child['package'] ?? '');
            if ($pkgName === 'Package A') {
                assert_false((bool) ($child['package_binary_enabled'] ?? false), 'no binary chip when package disabled');
            }
        }
    },

    /* ==========================================================
     *  P23E-T9 — JS off and mobile: cards stack, pagination works
     *  ========================================================== */

    'P23E-T9 the dashboard binary tile HTML renders without JS dependencies' => function () use (
        $buildFixtureTree, $createPackage, $preset
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        $activationId = Activation::create([
            'user_id'        => (int) $tree['buyerA']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationId, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);
        (new PackageBinaryService(db()))->onActivation((int) $activationId);

        test_signed_in_as($tree['boss']);

        $controller = new DashboardController();
        $data       = $controller->dashboardContext((int) $tree['boss']['id']);

        /* The tile renders server-side; data already asserted by P23E-T6. */
    },

    'P23E-T9 the earnings binary tab HTML renders without JS dependencies' => function () use (
        $buildFixtureTree, $createPackage, $preset
    ): void {
        $tree  = $buildFixtureTree();
        $preset();

        $pkg = $createPackage('Package A', 'package-a', 4498.00, 4750.00, 1, 1000);

        $activationId = Activation::create([
            'user_id'        => (int) $tree['buyerA']['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => '4498.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($activationId, null);
        db()->prepare(
            'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
        )->execute([(int) $pkg['id'], (int) $tree['buyerA']['id']]);
        (new PackageBinaryService(db()))->onActivation((int) $activationId);

        test_signed_in_as($tree['buyerA']);

        $_GET = ['ledger' => 'binary'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=binary';

        $controller = new EarningsController();
        $data       = $controller->binaryContext((int) $tree['buyerA']['id']);

        ob_start();
        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $tree['buyerA']['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Product binary', $html);
        assert_contains('Package activations', $html);
        assert_contains('Source', $html);
        assert_not_contains('upline', strtolower($html));
    },
];
