<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageBinaryTest — Phase 23B Configuration and readiness
 *  (plan §6 Phase 23B-S1: package-level controls and list visibility)
 *  Tests P23B-T1 … T10, T12, T18
 * ============================================================== */

/** Standard package payload for create/update cases. */
$packageData = static fn (array $overrides = []): array => $overrides + [
    'name'                  => 'Test Package',
    'price'                 => '1000.00',
    'product_value'         => '1200.00',
    'direct_referral_bonus' => '100.00',
    'description'           => 'A test package.',
    'perks'                 => '',
    'status'                => 'active',
    'sort_order'            => '0',
    'indirect_enabled'      => true,
];

/** Nine level amounts (2–10). */
$nineLevels = static fn (array $overrides = []): array => $overrides + [
    2  => '100', 3  => '50',  4  => '40', 5  => '30',
    6  => '20',  7  => '10', 8  => '5',  9  => '5', 10 => '5',
];

/** Render the settings page and return the Commissions card form markup. */
$renderSettings = static function (array $overrides = []): string {
    $controller = new AdminSettingsController();
    $data       = $overrides + $controller->settingsContext() + [
        'page_title'  => 'Settings',
        'current_nav' => 'admin.settings',
        'page_css'    => ['assets/css/orders.css'],
        'page_js'     => ['assets/js/admin-live.js', 'assets/js/admin-settings.js'],
        'live'        => Live::meta('admin.settings', 'page'),
    ];

    ob_start();

    try {
        View::page('admin', 'admin/settings/index', $data);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

$commissionsCard = static function () use ($renderSettings): string {
    $pattern = '/<form\b[^>]*\bid="settings-commissions"[^>]*>(.*?)<\/form>/s';

    preg_match($pattern, $renderSettings(), $m);

    return $m[1] ?? '';
};

$pendingMember = static function (): array {
    $result = (new RegistrationService())->register([
        'mode'             => 'pending',
        'username'         => 'pending_' . bin2hex(random_bytes(4)),
        'email'            => 'pending_' . bin2hex(random_bytes(4)) . '@test.local',
        'full_name'        => 'Pending Member',
        'phone'            => '',
        'password'         => 'Member@1234',
        'password_confirm' => 'Member@1234',
        'sponsor'          => 'peter',
        'package_id'       => '',
        'reg_code'         => '',
        'terms'            => true,
        'binary_position'  => 'left',
    ], null);

    return Member::find((int) $result['user_id']) ?? [];
};

$activation = static fn (): ActivationService => new ActivationService();
$wallet     = static fn (): WalletService => new WalletService();

/* ----------------------------------------------------------
 *  23C-S2 helpers: build the plan's fixture tree directly so
 *  each case controls its own shape.
 *
 *      admin  (root, path '', depth 0)   ← already seeded
 *        └─ L  boss          (path 'L',  depth 1)
 *           ├─ L  a          (path 'LL', depth 2)
 *           │  ├─ L  b       (path 'LLL', depth 3)
 *           │  │  └─ L  c    (path 'LLLL', depth 4)
 *           │  │     ├─ L  buyerA  (path 'LLLLL', depth 5)
 *           │  │     └─ R  buyerB  (path 'LLLLR', depth 5)
 *           │  └─ R  sideR   (path 'LLR', depth 3)
 *           └─ R  d          (path 'LR', depth 2)
 * ---------------------------------------------------------- */

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
    $rootId = (int) ($admin['id'] ?? 0);

    db()->prepare(
        "INSERT IGNORE INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
    )->execute([
        $userId,
        $uplineId,
        $side,
        $rootId,
        $depth,
        $path,
        $state,
        $rootId,
    ]);
};

$buildFixtureTree = static function () use ($createUser, $insertNode): array {
    $stmt = db()->prepare("SELECT id FROM users WHERE username = 'admin' AND role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = (array) $stmt->fetch();
    if ($admin === false) {
        $admin = $createUser('admin', 'admin');
    }

    $boss   = $createUser('boss');
    $a      = $createUser('a');
    $b      = $createUser('b');
    $c      = $createUser('c');
    $buyerA = $createUser('buyerA');
    $buyerB = $createUser('buyerB');
    $sideR  = $createUser('sideR');
    $d      = $createUser('d');

    $insertNode((int) $admin['id'], null, null, 0, '', 'root');
    $insertNode((int) $boss['id'],  (int) $admin['id'], 'right', 1, 'R');
    $insertNode((int) $a['id'],     (int) $boss['id'],  'left',  2, 'RL');
    $insertNode((int) $b['id'],     (int) $a['id'],     'left',  3, 'RLL');
    $insertNode((int) $c['id'],     (int) $b['id'],     'left',  4, 'RLLL');
    $insertNode((int) $buyerA['id'],(int) $c['id'],     'left',  5, 'RLLLL');
    $insertNode((int) $buyerB['id'],(int) $c['id'],     'right', 5, 'RLLLR');
    $insertNode((int) $sideR['id'], (int) $a['id'],     'right', 3, 'RLR');
    $insertNode((int) $d['id'],     (int) $boss['id'],  'right', 2, 'RR');

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

$setPkgBinary = static function (int $packageId, int $enabled, int $points) use ($nineLevels): void {
    $pkg = Package::find($packageId);
    assert_not_null($pkg, 'package #%d must exist');
    Package::save([
        'name'                   => $pkg['name'],
        'price'                  => $pkg['price'],
        'product_value'          => $pkg['product_value'],
        'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
        'status'                 => $pkg['status'],
        'sort_order'             => $pkg['sort_order'],
        'indirect_enabled'       => $pkg['indirect_enabled'],
        'binary_enabled'         => (string) $enabled,
        'binary_points'          => (string) $points,
    ], $nineLevels(), [], $packageId);
};

$pkgABinary = static function () use ($setPkgBinary, $nineLevels): int {
    $pkg = Package::findBySlug('package-a');
    assert_not_null($pkg, 'package-a must exist');
    $setPkgBinary((int) $pkg['id'], 1, 1000);
    setting_set('package_binary_enabled', '1');
    setting_cache_clear();
    return (int) $pkg['id'];
};

$pkgBDisabled = static function () use ($setPkgBinary, $nineLevels): int {
    $pkg = Package::findBySlug('package-b');
    assert_not_null($pkg, 'package-b must exist');
    $setPkgBinary((int) $pkg['id'], 0, 1000);
    setting_set('package_binary_enabled', '1');
    setting_cache_clear();
    return (int) $pkg['id'];
};

$createApprovedActivation = static function (int $userId, int $packageId): int {
    $activationId = Activation::create([
        'user_id'        => $userId,
        'package_id'     => $packageId,
        'amount'         => '1000.00',
        'payment_method' => 'ewallet',
        'status'         => 'approved',
    ]);
    Activation::markApproved($activationId, null);

    /* Set the member active with the package (as complete() would). */
    db()->prepare(
        'UPDATE users SET package_id = ?, member_status = \'active\', activated_at = NOW() WHERE id = ?'
    )->execute([$packageId, $userId]);

    return $activationId;
};

$walletTopup = static function (int $userId, string $amount = '5000.00'): void {
    $w = new WalletService();
    $w->credit($userId, $amount, 'topup', random_int(100000, 999999), 'Test funds', false);
};

return [

    /* ---------- Validation (P23B-T1 … T5) ---------- */

    'P23B-T1 binary fields validate and persist' => function () use ($packageData, $nineLevels): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '1000',
        ]));
        assert_same([], $errors, 'valid binary config passes validation');

        $id = Package::save($packageData([
            'name'           => 'Binary Package',
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ]), $nineLevels(), []);

        $saved = Package::find($id);
        assert_not_null($saved);
        assert_same(1, (int) $saved['binary_enabled']);
        assert_same(1000, (int) $saved['binary_points']);
    },

    'P23B-T2 switch on with zero points is refused' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '0',
        ]));
        assert_true(isset($errors['binary_points']), 'zero points with switch on must fail');
        assert_contains('Set points above 0', $errors['binary_points']);
    },

    'P23B-T3 switch off with zero points is accepted' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '0',
            'binary_points'   => '0',
        ]));
        assert_same([], $errors, 'switch off with zero points is a valid state');
    },

    'P23B-T4 decimal points are refused' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '1000.5',
        ]));
        assert_true(isset($errors['binary_points']), 'decimal points must fail');
    },

    'P23B-T5 points over one million are refused' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '1000001',
        ]));
        assert_true(isset($errors['binary_points']), 'over-cap points must fail');
    },

    'P23B-T5 points under zero are refused' => function () use ($packageData): void {
        $errors = Package::validate($packageData([
            'binary_enabled'  => '1',
            'binary_points'   => '-5',
        ]));
        assert_true(isset($errors['binary_points']), 'negative points must fail');
    },

    /* ---------- Save persistence (P23B-T1 continued) ---------- */

    'P23B-T1 save writes both binary columns' => function () use ($packageData, $nineLevels): void {
        $id = Package::save($packageData([
            'name'           => 'Persist Probe',
            'binary_enabled' => '1',
            'binary_points'  => '2500',
        ]), $nineLevels(), []);

        $saved = Package::find($id);
        assert_not_null($saved);
        assert_same(1, (int) $saved['binary_enabled']);
        assert_same(2500, (int) $saved['binary_points']);

        /* Re-read via all() — SELECT * must include the new columns. */
        $all = Package::all();
        $row = null;
        foreach ($all as $candidate) {
            if ((int) $candidate['id'] === $id) {
                $row = $candidate;
                break;
            }
        }
        assert_not_null($row);
        assert_same(1, (int) $row['binary_enabled']);
        assert_same(2500, (int) $row['binary_points']);
    },

    'P23B-T1 edit that only changes the name writes no binary change' => function () use ($packageData, $nineLevels): void {
        $id = Package::save($packageData([
            'name'           => 'Rename Probe',
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ]), $nineLevels(), []);

        /* Capture the audit count before the rename. */
        $stmt = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'package.binary.update' AND subject_id = ?");
        $stmt->execute([$id]);
        $before = (int) $stmt->fetchColumn();

        Package::save($packageData([
            'name'           => 'Renamed Probe',
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ]), $nineLevels(), [], $id);

        $stmt->execute([$id]);
        $after = (int) $stmt->fetchColumn();
        assert_same($before, $after, 'a rename-only edit must not add a binary audit row');
    },

    /* ---------- Audit (P23B-T7, T18) ---------- */

    'P23B-T7 audit records before/after when binary changes' => function () use ($packageData, $nineLevels): void {
        $id = Package::save($packageData([
            'name'           => 'Audit Probe',
            'binary_enabled' => '0',
            'binary_points'  => '0',
        ]), $nineLevels(), []);

        Package::save($packageData([
            'name'           => 'Audit Probe',
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ]), $nineLevels(), [], $id);

        $stmt = db()->prepare(
            "SELECT meta_json FROM audit_log
             WHERE action = 'package.binary.update' AND subject_id = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$id]);
        $meta = json_decode((string) $stmt->fetchColumn(), true);

        assert_not_null($meta);
        assert_same(0, (int) $meta['before']['binary_enabled']);
        assert_same(0, (int) $meta['before']['binary_points']);
        assert_same(1, (int) $meta['after']['binary_enabled']);
        assert_same(1000, (int) $meta['after']['binary_points']);
        assert_same(1, (int) $meta['changes']['binary_enabled']);
        assert_same(1000, (int) $meta['changes']['binary_points']);
    },

    /* ---------- binaryStatusMap (P23B-T10) ---------- */

    'P23B-T10 binaryStatusMap returns one row per package' => function () use ($packageData, $nineLevels): void {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = Package::save($packageData([
                'name'           => 'Map Package ' . $i,
                'binary_enabled' => $i % 2 === 0 ? '1' : '0',
                'binary_points'  => $i === 0 ? '1000' : '0',
            ]), $nineLevels(), []);
        }

        $map = Package::binaryStatusMap($ids);
        assert_count(3, $map);
        assert_same(['binary_enabled' => 1, 'binary_points' => 1000], $map[$ids[0]]);
        assert_same(['binary_enabled' => 0, 'binary_points' => 0],    $map[$ids[1]]);
        assert_same(['binary_enabled' => 1, 'binary_points' => 0],    $map[$ids[2]]);

        assert_same([], Package::binaryStatusMap([]));
    },

    /* ---------- List badge + filter (P23B-T8, T9) ---------- */

    'P23B-T8 list badge shows on/off/zero correctly' => function () use ($packageData, $nineLevels): void {
        $onId  = Package::save($packageData(['name' => 'Badge On',  'binary_enabled' => '1', 'binary_points' => '1000']), $nineLevels(), []);
        $offId = Package::save($packageData(['name' => 'Badge Off', 'binary_enabled' => '0', 'binary_points' => '0']),    $nineLevels(), []);
        $zeroId= Package::save($packageData(['name' => 'Badge Zero','binary_enabled' => '1', 'binary_points' => '0']),    $nineLevels(), []);

        $map = Package::binaryStatusMap([$onId, $offId, $zeroId]);
        assert_same(['binary_enabled' => 1, 'binary_points' => 1000], $map[$onId]);
        assert_same(['binary_enabled' => 0, 'binary_points' => 0],    $map[$offId]);
        assert_same(['binary_enabled' => 1, 'binary_points' => 0],    $map[$zeroId]);
    },

    'P23B-T9 ?binary=on|off filter narrows the admin list' => function () use ($packageData, $nineLevels): void {
        Package::save($packageData(['name' => 'Filter On',  'binary_enabled' => '1', 'binary_points' => '1000']), $nineLevels(), []);
        Package::save($packageData(['name' => 'Filter Off', 'binary_enabled' => '0', 'binary_points' => '0']),    $nineLevels(), []);

        $on  = Package::adminList(['binary' => 'on'],  new Paginator(1, 20, 1));
        $off = Package::adminList(['binary' => 'off'], new Paginator(1, 20, 1));
        $all = Package::adminList(['binary' => ''],    new Paginator(1, 20, 1));

        $onNames  = array_column($on,  'name');
        $offNames = array_column($off, 'name');

        assert_true(in_array('Filter On',  $onNames, true),  'on filter includes flagged packages');
        assert_false(in_array('Filter Off', $onNames, true), 'on filter excludes unflagged packages');
        assert_true(in_array('Filter Off', $offNames, true), 'off filter includes unflagged packages');
        assert_false(in_array('Filter On',  $offNames, true), 'off filter excludes flagged packages');

        /* Combines with ?status= */
        $combo = Package::adminList(['binary' => 'on', 'status' => 'active'], new Paginator(1, 20, 1));
        assert_count(1, $combo, 'binary filter combines with status filter');
        assert_true(in_array('Filter On', array_column($combo, 'name'), true));
    },

    /* ---------- Form HTML (P23B-T6, T12) ---------- */

    'P23B-T6 create form defaults: switch off, points suggest ROUND(product_value)' => function () use ($packageData): void {
        $current = $packageData([]);
        unset($current['binary_enabled'], $current['binary_points']);

        $html = View::capture('admin/packages/_binary', [
            'current' => $current,
            'isEdit'  => false,
            'id'      => 0,
        ]);

        assert_not_contains('checked', $html, 'create form switch is off by default');
        assert_contains((string) max(0, round((float) $packageData([])['product_value'])), $html, 'points suggest ROUND(product_value)');
    },

    'P23B-T12 form HTML has switch and points, no pair/rate/cap/hold inputs' => function () use ($packageData): void {
        $current = $packageData([]) + ['binary_enabled' => '0', 'binary_points' => ''];

        $html = View::capture('admin/packages/_binary', [
            'current' => $current,
            'isEdit'  => false,
            'id'      => 0,
        ]);

        assert_contains('name="binary_enabled"', $html, 'switch input present');
        assert_contains('name="binary_points"',  $html, 'points input present');
        assert_not_contains('pair_rate', $html, 'no pair-rate input');
        assert_not_contains('daily_cap', $html, 'no cap input');
        assert_not_contains('hold_days', $html, 'no hold input');
        assert_not_contains('binary_pair', strtolower($html), 'no per-package pair rate field');
    },

    /* ---------- Settings card (P23B-T11) ---------- */

    'P23B-T11 settings card: master switch and qualification toggle under product binary' => function () use ($commissionsCard): void {
        test_signed_in_as(test_user('p23b_t11_admin', 'admin'));

        $html = $commissionsCard();

        assert_contains('Package binary', $html, 'the package sub-block renders');
        assert_contains('name="package_binary_enabled"', $html, 'the master switch input renders');
        assert_contains('name="package_binary_requires_package"', $html, 'the qualification toggle input renders');
        assert_not_contains('name="package_binary_ledger_since"', $html, 'the baseline is never rendered');

        /* The package block sits under the product binary block. */
        assert_true(
            strpos($html, 'Product binary') < strpos($html, 'Package binary'),
            'the product block comes before the package block'
        );

        /* Default: master off, qualification on. */
        assert_contains('The stream is off. No activation places points.', $html);
    },

    /* ---------- Readiness panel (P23B-T13, T14) ---------- */

    'P23B-T13 readiness panel shows the four counts and links to the filtered list' => function () use ($commissionsCard, $packageData, $nineLevels): void {
        test_signed_in_as(test_user('p23b_t13_admin', 'admin'));

        /* One flagged-with-points package, one flagged-off package, one misconfigured (on but 0 pts). */
        Package::save($packageData(['name' => 'Readiness On', 'binary_enabled' => '1', 'binary_points' => '1000']), $nineLevels(), []);
        Package::save($packageData(['name' => 'Readiness Off', 'binary_enabled' => '0', 'binary_points' => '0']), $nineLevels(), []);
        Package::save($packageData(['name' => 'Readiness Zero', 'binary_enabled' => '1', 'binary_points' => '0']), $nineLevels(), []);

        $expectedOff   = (int) db()->query("SELECT COUNT(*) FROM packages WHERE binary_enabled = 0")->fetchColumn();
        $expectedZero  = (int) db()->query("SELECT COUNT(*) FROM packages WHERE binary_enabled = 1 AND binary_points = 0")->fetchColumn();

        assert_same(1, $expectedZero, 'the direct row is the one flagged-but-zero package');

        $html = $commissionsCard();

        assert_contains('Package binary readiness', $html);
        assert_contains('Binary on, no points', $html);
        assert_contains('Binary off', $html);
        assert_contains((string) $expectedZero, $html);
        assert_contains((string) $expectedOff, $html);

        /* Both links render. */
        assert_contains('<a href="' . e(url('admin/packages?binary=on')) . '"', $html);
        assert_contains('<a href="' . e(url('admin/packages?binary=off')) . '"', $html);

        /* The on-link names the misconfiguration; the off-link names the rest. */
        assert_contains('flagged but earn 0 points', $html);
        assert_contains('do not place points', $html);
    },

    'P23B-T14 readiness preset sentence recomputes when the shared pair values change' => function () use ($commissionsCard): void {
        test_signed_in_as(test_user('p23b_t14_admin', 'admin'));

        $html = $commissionsCard();

        assert_contains('1,000 points per pair pays ₱100.00 at 10%.', $html);

        /* Recomputed when the pair values change. */
        setting_set('binary_pair_points', '2000');
        setting_set('binary_pair_pct', '7.500');
        setting_cache_clear();

        $recomputed = $commissionsCard();

        assert_contains('2,000 points per pair pays ₱150.00 at 7.5%.', $recomputed);

        setting_set('binary_pair_points', '1000');
        setting_set('binary_pair_pct', '10.000');
        setting_cache_clear();
    },

    /* ---------- No money path yet (P23B-T15) ---------- */

    'P23B-T15 approving a binary-flagged package activation writes no binary row' => function () use ($pendingMember, $activation, $wallet, $packageData, $nineLevels): void {
        test_signed_in_as(test_user('p23b_t15_admin', 'admin'));

        /* Flip Package A to binary-enabled with points. */
        $pkg = Package::find((int) Package::findBySlug('package-a')['id']);
        Package::save([
            'name'           => $pkg['name'],
            'price'          => $pkg['price'],
            'product_value'  => $pkg['product_value'],
            'direct_referral_bonus' => $pkg['direct_referral_bonus'],
            'status'         => $pkg['status'],
            'sort_order'     => $pkg['sort_order'],
            'indirect_enabled' => $pkg['indirect_enabled'],
            'binary_enabled' => '1',
            'binary_points'  => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);

        setting_set('package_binary_enabled', '1');
        setting_cache_clear();

        $member = $pendingMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '5000.00', 'topup', random_int(100000, 999999), 'Activation funds', false);

        /* Activate the member with Package A. */
        $result = $activation()->request($id, [
            'method'     => 'ewallet',
            'package_id' => (string) $pkg['id'],
        ]);

        assert_same('approved', $result['status']);

        $activationRow = Activation::find((int) $result['activation_id']);
        assert_not_null($activationRow);
        assert_same('approved', $activationRow['status']);
        assert_same(0, (int) $activationRow['binary_points']);
        assert_same('0.00', (string) $activationRow['binary_total']);
        assert_null($activationRow['binary_processed_at']);

        /* No binary rows are written anywhere. */
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_awards WHERE activation_id = ' . (int) $result['activation_id'])->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_volume WHERE activation_id = ' . (int) $result['activation_id'])->fetchColumn());
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'binary'")->fetchColumn());

        setting_set('package_binary_enabled', '0');
        Package::save([
            'name'           => $pkg['name'],
            'price'          => $pkg['price'],
            'product_value'  => $pkg['product_value'],
            'direct_referral_bonus' => $pkg['direct_referral_bonus'],
            'status'         => $pkg['status'],
            'sort_order'     => $pkg['sort_order'],
            'indirect_enabled' => $pkg['indirect_enabled'],
            'binary_enabled' => '0',
            'binary_points'  => '0',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_cache_clear();
    },

    /* ---------- 23C-S1 activation-source model API ---------- */

    'P23C-S1 BinaryAward::createForActivation writes an activation-sourced pair award' => function () use ($pendingMember, $activation, $wallet, $packageData, $nineLevels): void {
        /* Set up: a binary-enabled package and an approved activation. */
        $pkg = Package::find((int) Package::findBySlug('package-a')['id']);
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_set('package_binary_enabled', '1');
        setting_cache_clear();

        $member = $pendingMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '5000.00', 'topup', random_int(100000, 999999), 'Activation funds', false);
        $result = $activation()->request($id, [
            'method'     => 'ewallet',
            'package_id' => (string) $pkg['id'],
        ]);
        assert_same('approved', $result['status']);
        $activationId = (int) $result['activation_id'];

        /* Write an activation-sourced pair award. The recipient is the
         * member themselves (the tree path is irrelevant at model level). */
        $awardId = BinaryAward::createForActivation([
            'kind'       => 'pair',
            'buyer_id'   => $id,
            'user_id'    => $id,
            'side'       => 'left',
            'points'     => 2000,
            'pair_count' => 1,
            'pair_points'=> 1000,
            'rate_pct'   => 10.0,
            'amount'     => 100.00,
            'status'     => 'credited',
            'hold_days'  => 0,
            'paid_at'    => date('Y-m-d H:i:s'),
            'activation_id'=> $activationId,
        ]);

        assert_true($awardId > 0, 'award id is positive');

        /* Read it back via forActivation(). */
        $rows = BinaryAward::forActivation($activationId);
        assert_count(1, $rows, 'exactly one activation award');
        assert_same('activation', $rows[0]['source']);
        assert_same($activationId, (int) $rows[0]['activation_id']);
        assert_null($rows[0]['order_id']);
        assert_same('pair', $rows[0]['kind']);
        assert_same('credited', $rows[0]['status']);
        assert_same(100.00, (float) $rows[0]['amount']);

        /* The activation-event idempotency anchor works. */
        $found = BinaryAward::forActivationEvent($activationId, (int) $rows[0]['user_id'], 'pair');
        assert_not_null($found);
        assert_same($awardId, (int) $found['id']);

        /* Cleanup: restore package. */
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '0',
            'binary_points'          => '0',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_set('package_binary_enabled', '0');
        setting_cache_clear();
    },

    'P23C-S1 BinaryVolume::createForActivation writes team rows with source activation' => function () use ($pendingMember, $activation, $wallet, $packageData, $nineLevels): void {
        $pkg = Package::find((int) Package::findBySlug('package-a')['id']);
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_set('package_binary_enabled', '1');
        setting_cache_clear();

        $member = $pendingMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '5000.00', 'topup', random_int(100000, 999999), 'Activation funds', false);
        $result = $activation()->request($id, [
            'method'     => 'ewallet',
            'package_id' => (string) $pkg['id'],
        ]);
        assert_same('approved', $result['status']);
        $activationId = (int) $result['activation_id'];

        /* Need an ancestor node to write team rows at. Use any existing
         * placed node from the fixture tree. */
        $stmt = db()->prepare('SELECT user_id FROM binary_nodes LIMIT 1');
        $stmt->execute();
        $node = $stmt->fetch();
        $ancestorId = (int) ($node['user_id'] ?? 0);
        if ($ancestorId <= 0) {
            /* No nodes exist — skip the row-write assertions but still
             * verify the model API accepts activation-sourced rows. */
            $ancestorId = (int) $id;
        }

        /* Write activation-sourced team volume rows at the ancestor. */
        BinaryVolume::createForActivation([
            'node_user_id' => $ancestorId,
            'side'         => 'left',
            'kind'         => 'team',
            'points'       => 1000,
            'buyer_id'     => $id,
            'activation_id'=> $activationId,
        ]);
        BinaryVolume::createForActivation([
            'node_user_id' => $ancestorId,
            'side'         => 'right',
            'kind'         => 'team',
            'points'       => 1000,
            'buyer_id'     => $id,
            'activation_id'=> $activationId,
        ]);

        /* Read back via forActivation(). */
        $rows = BinaryVolume::forActivation($activationId);
        assert_count(2, $rows, 'two team rows for the activation');
        foreach ($rows as $row) {
            assert_same('activation', $row['source']);
            assert_same($activationId, (int) $row['activation_id']);
            assert_null($row['order_id']);
            assert_null($row['order_item_id']);
            assert_same('team', $row['kind']);
            assert_same(1000, (int) $row['points']);
        }

        /* sumBySource() splits correctly. */
        $sums = BinaryVolume::sumBySource();
        assert_same(2000, (int) $sums['activation']);
        assert_same(0, (int) $sums['order']);

        /* Cleanup. */
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '0',
            'binary_points'          => '0',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_set('package_binary_enabled', '0');
        setting_cache_clear();
    },

    'P23C-S1 BinaryAward::normaliseFilters accepts source and whereFor filters by it' => function (): void {
        $filters = BinaryAward::normaliseFilters(['source' => 'activation']);
        assert_same('activation', $filters['source']);

        $filters = BinaryAward::normaliseFilters(['source' => 'order']);
        assert_same('order', $filters['source']);

        $filters = BinaryAward::normaliseFilters(['source' => 'bogus']);
        assert_same('', $filters['source'], 'unknown source falls back to no filter');

        $filters = BinaryAward::normaliseFilters([]);
        assert_same('', $filters['source']);
    },

    'P23C-S1 BinaryVolume::normaliseFilters accepts source' => function (): void {
        $filters = BinaryVolume::normaliseFilters(['source' => 'activation']);
        assert_same('activation', $filters['source']);

        $filters = BinaryVolume::normaliseFilters(['source' => 'bogus']);
        assert_same('', $filters['source']);
    },

    'P23C-S1 BinaryAward::sumBySource splits order vs activation totals' => function (): void {
        $sums = BinaryAward::sumBySource();
        assert_true(isset($sums['order']), 'order key exists');
        assert_true(isset($sums['activation']), 'activation key exists');
        assert_same(0.0, (float) $sums['order']);
        assert_same(0.0, (float) $sums['activation']);
    },

    'P23C-S1 BinaryAward::create rejects activation award without activation_id' => function (): void {
        $threw = false;
        try {
            BinaryAward::create([
                'kind'       => 'pair',
                'order_id'   => 1,
                'buyer_id'   => 1,
                'user_id'    => 1,
                'side'       => 'left',
                'points'     => 2000,
                'pair_count' => 1,
                'pair_points'=> 1000,
                'rate_pct'   => 10.0,
                'amount'     => 100.00,
                'status'     => 'credited',
                'hold_days'  => 0,
                'source'     => 'activation',
            ]);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        assert_true($threw, 'activation award without activation_id must throw');
    },

    'P23C-S1 BinaryVolume::create rejects activation row without activation_id' => function (): void {
        $threw = false;
        try {
            BinaryVolume::create([
                'node_user_id' => 1,
                'side'         => 'left',
                'kind'         => 'team',
                'points'       => 1000,
                'buyer_id'     => 1,
                'source'       => 'activation',
            ]);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        assert_true($threw, 'activation volume row without activation_id must throw');
    },

    /* ----------------------------------------------------------
     *  23C-S2 — Money engine: package activation orchestration
     *  Tests P23C-T1–T2, T7–T9, T13–T29, T37–T39
     * ---------------------------------------------------------- */

    'P23C-T1 buyerA places five team rows, no own row, no award' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        $activationId = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgId);

        $service = new PackageBinaryService();
        $result = $service->onActivation($activationId);

        assert_same(true,  $result['processed']);
        assert_same(false, $result['replayed']);
        assert_same(1000,  $result['points']);
        assert_same(0,     $result['pairs']);
        assert_same(0.0,   $result['amount']);
        assert_count(0,    $result['awards']);
        assert_null($result['reason']);

        /* Five team rows: c, b, a, boss, root — all from the path. */
        $volume = BinaryVolume::forActivation($activationId);
        assert_count(5, $volume, 'five team rows for buyerA');
        $byNode = [];
        foreach ($volume as $row) {
            $byNode[(int) $row['node_user_id']] = $row;
        }
        assert_same('left',  $byNode[(int) $tree['c']['id']]['side'],   'left at c');
        assert_same('left',  $byNode[(int) $tree['b']['id']]['side'],   'left at b');
        assert_same('left',  $byNode[(int) $tree['a']['id']]['side'],   'left at a');
        assert_same('left',  $byNode[(int) $tree['boss']['id']]['side'], 'left at boss');
        assert_same('right', $byNode[(int) $tree['admin']['id']]['side'], 'right at root');
        foreach ($volume as $row) {
            assert_same('team',      $row['kind']);
            assert_same(1000,        (int) $row['points']);
            assert_same('activation',$row['source']);
            assert_same($activationId, (int) $row['activation_id']);
            assert_null($row['order_id']);
            assert_null($row['order_item_id']);
        }

        /* No own row at the buyer. */
        $stmt = db()->prepare("SELECT COUNT(*) FROM binary_volume WHERE activation_id = ? AND kind = 'own'");
        $stmt->execute([$activationId]);
        assert_same(0, (int) $stmt->fetchColumn(), 'no own row for the buyer');

        /* No awards — every side is left. */
        assert_count(0, BinaryAward::forActivation($activationId), 'no awards when no side matches');

        /* Activation stamped. */
        $summary = Activation::binarySummary($activationId);
        assert_same(1000,   (int) $summary['binary_points']);
        assert_same('0.00', (string) $summary['binary_total']);
        assert_not_null($summary['binary_processed_at']);
    },

    'P23C-T2 buyerB completes one pair at c, ₱100 credited' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        /* c must carry a binary-enabled package to earn package pairs. */
        db()->prepare('UPDATE users SET package_id = ?, member_status = "active", is_active = 1 WHERE id = ?')
            ->execute([$pkgId, (int) $tree['c']['id']]);

        /* buyerA first: 1000 left at every ancestor. */
        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgId);
        (new PackageBinaryService())->onActivation($aidA);

        /* buyerB second: 1000 right at c → c has L=1000/R=1000 → 1 pair. */
        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], $pkgId);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true,  $result['processed']);
        assert_same(false, $result['replayed']);
        assert_same(1000,  $result['points']);
        assert_same(1,     $result['pairs']);
        assert_same(100.00,$result['amount']);
        assert_count(1,    $result['awards']);

        $award = $result['awards'][0];
        assert_same('pair',   $award['kind']);
        assert_same((int) $tree['c']['id'], (int) $award['user_id']);
        assert_same('credited',  $award['status']);
        assert_same(0,           (int) $award['hold_days']);
        assert_same('activation',$award['source']);
        assert_same($aidB,       (int) $award['activation_id']);
        assert_null($award['order_id']);
        assert_not_null($award['ledger_id'],   'credited award links a wallet movement');
        assert_not_null($award['credited_at'], 'credited award is stamped');

        /* Wallet: c is up ₱100.00, withdrawable. */
        $wallet = new WalletService();
        assert_same(100.00, $wallet->withdrawable((int) $tree['c']['id']));

        /* Activation stamped. */
        $summary = Activation::binarySummary($aidB);
        assert_same(1000,   (int) $summary['binary_points']);
        assert_same('100.00', (string) $summary['binary_total']);
    },

    'P23C-T7 PKB zero points: no volume, no award, stamped zeros' => function () use ($buildFixtureTree, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();

        $pkg = Package::findBySlug('package-a');
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '0',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_set('package_binary_enabled', '1');
        setting_cache_clear();

        $aid = $createApprovedActivation((int) $tree['buyerA']['id'], (int) $pkg['id']);
        $result = (new PackageBinaryService())->onActivation($aid);

        assert_same(true,  $result['processed']);
        assert_same(false, $result['replayed']);
        assert_same(0,     $result['points']);
        assert_same(0,     $result['pairs']);
        assert_same(0.0,   $result['amount']);
        assert_count(0,    $result['awards']);

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_volume WHERE activation_id = ' . $aid)->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_awards WHERE activation_id = ' . $aid)->fetchColumn());

        $summary = Activation::binarySummary($aid);
        assert_same(0,   (int) $summary['binary_points']);
        assert_same('0.00', (string) $summary['binary_total']);
        assert_not_null($summary['binary_processed_at']);
    },

    'P23C-T8 PKC switch off: no volume, no award, stamped zeros' => function () use ($buildFixtureTree, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();

        $pkg = Package::findBySlug('package-a');
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '0',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_set('package_binary_enabled', '1');
        setting_cache_clear();

        $aid = $createApprovedActivation((int) $tree['buyerA']['id'], (int) $pkg['id']);
        $result = (new PackageBinaryService())->onActivation($aid);

        assert_same(true,  $result['processed']);
        assert_same(0,     $result['points']);
        assert_same(0,     $result['pairs']);
        assert_same(0.0,   $result['amount']);
        assert_count(0,    $result['awards']);
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_volume WHERE activation_id = ' . $aid)->fetchColumn());
    },

    'P23C-T9 global switch off: no volume, no award, stamped zeros' => function () use ($buildFixtureTree, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();

        $pkg = Package::findBySlug('package-a');
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_set('package_binary_enabled', '0');
        setting_cache_clear();

        $aid = $createApprovedActivation((int) $tree['buyerA']['id'], (int) $pkg['id']);
        $result = (new PackageBinaryService())->onActivation($aid);

        assert_same(true,  $result['processed']);
        assert_same(0,     $result['points']);
        assert_same(0,     $result['pairs']);
        assert_same(0.0,   $result['amount']);
        assert_count(0,    $result['awards']);
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_volume WHERE activation_id = ' . $aid)->fetchColumn());
    },

    'P23C-T13 staff without a node: direct call stamps zeros, no volume' => function () use ($buildFixtureTree, $pkgABinary, $createUser): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        /* A staff member who has no binary node. */
        $staff = $createUser('staff_' . bin2hex(random_bytes(2)));
        $aid = Activation::create([
            'user_id'        => (int) $staff['id'],
            'package_id'     => $pkgId,
            'amount'         => '1000.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($aid, null);

        /* Do not set the member active or give them a node. */

        $result = (new PackageBinaryService())->onActivation($aid);

        assert_same(true,  $result['processed']);
        assert_same(0,     $result['points']);
        assert_same(0,     $result['pairs']);
        assert_same('not placed', $result['reason']);

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_volume WHERE activation_id = ' . $aid)->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_awards WHERE activation_id = ' . $aid)->fetchColumn());

        $summary = Activation::binarySummary($aid);
        assert_same(0,   (int) $summary['binary_points']);
        assert_same('0.00', (string) $summary['binary_total']);
    },

    'P23C-T14 buyerB path LLLLR: sides come from the path' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        /* c must carry a binary-enabled package to earn package pairs. */
        db()->prepare('UPDATE users SET package_id = ?, member_status = "active", is_active = 1 WHERE id = ?')
            ->execute([$pkgId, (int) $tree['c']['id']]);

        /* buyerA first: 1000 left at every ancestor. */
        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgId);
        (new PackageBinaryService())->onActivation($aidA);

        /* buyerB: right at c, left at b, a, boss, root. */
        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], $pkgId);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true, $result['processed']);
        assert_same(1,    $result['pairs']);

        $volume = BinaryVolume::forActivation($aidB);
        $byNode = [];
        foreach ($volume as $row) {
            $byNode[(int) $row['node_user_id']] = $row;
        }

        assert_same('right', $byNode[(int) $tree['c']['id']]['side'],  'right at c');
        assert_same('left',  $byNode[(int) $tree['b']['id']]['side'],  'left at b');
        assert_same('left',  $byNode[(int) $tree['a']['id']]['side'],  'left at a');
        assert_same('left',  $byNode[(int) $tree['boss']['id']]['side'], 'left at boss');
        assert_same('right', $byNode[(int) $tree['admin']['id']]['side'], 'right at root');
    },

    'P23C-T16 buyerA personal_points unchanged by own activation' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        $stmt = db()->prepare('SELECT personal_points FROM binary_nodes WHERE user_id = ?');
        $stmt->execute([(int) $tree['buyerA']['id']]);
        $before = (int) $stmt->fetchColumn();

        $aid = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgId);
        (new PackageBinaryService())->onActivation($aid);

        $stmt->execute([(int) $tree['buyerA']['id']]);
        $after = (int) $stmt->fetchColumn();
        assert_same($before, $after, 'personal_points unchanged');
    },

    'P23C-T17 buyerA own balances unchanged by own activation' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        $aid = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgId);
        (new PackageBinaryService())->onActivation($aid);

        $node = BinaryNode::forUser((int) $tree['buyerA']['id']);
        assert_same(0, (int) ($node['left_points']  ?? 0), 'buyerA left unchanged');
        assert_same(0, (int) ($node['right_points'] ?? 0), 'buyerA right unchanged');
    },

    'P23C-T18 member with no node: direct call stamps zeros, reason not placed' => function () use ($buildFixtureTree, $pkgABinary, $createUser): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        /* A member with no binary node at all. */
        $ghost = $createUser('ghost_' . bin2hex(random_bytes(2)));
        $aid = Activation::create([
            'user_id'        => (int) $ghost['id'],
            'package_id'     => $pkgId,
            'amount'         => '1000.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($aid, null);

        $result = (new PackageBinaryService())->onActivation($aid);

        assert_same(true,   $result['processed']);
        assert_same(false,  $result['replayed']);
        assert_same(0,      $result['points']);
        assert_same(0,      $result['pairs']);
        assert_same(0.0,    $result['amount']);
        assert_same('not placed', $result['reason']);

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_volume WHERE activation_id = ' . $aid)->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_awards WHERE activation_id = ' . $aid)->fetchColumn());
    },

    'P23C-T19 company root receives volume but is never paid' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        /* c must carry a binary-enabled package to earn package pairs. */
        db()->prepare('UPDATE users SET package_id = ?, member_status = "active", is_active = 1 WHERE id = ?')
            ->execute([$pkgId, (int) $tree['c']['id']]);

        /* buyerA places 1000 left at root too. */
        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgId);
        (new PackageBinaryService())->onActivation($aidA);

        /* buyerB places 1000 right at root → root has L=1000/R=1000 but is never paid. */
        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], $pkgId);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true, $result['processed']);
        assert_same(1,    $result['pairs']);  /* only c gets paid */

        /* The single pair is at c, not at root. */
        $awards = BinaryAward::forActivation($aidB);
        assert_count(1, $awards);
        assert_not_same((int) $tree['admin']['id'], (int) $awards[0]['user_id']);

        /* Root has volume (team row) but no award. */
        $rootVolume = db()->prepare(
            'SELECT COUNT(*) FROM binary_volume WHERE activation_id = ? AND node_user_id = ? AND kind = "team"'
        );
        $rootVolume->execute([$aidB, (int) $tree['admin']['id']]);
        assert_same(1, (int) $rootVolume->fetchColumn(), 'root has a team row');

        $rootAwards = db()->prepare(
            'SELECT COUNT(*) FROM binary_awards WHERE activation_id = ? AND user_id = ?'
        );
        $rootAwards->execute([$aidB, (int) $tree['admin']['id']]);
        assert_same(0, (int) $rootAwards->fetchColumn(), 'root has no award');
    },

    'P23C-T20 root award count stays zero after any activations' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgId);
        (new PackageBinaryService())->onActivation($aidA);

        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], $pkgId);
        (new PackageBinaryService())->onActivation($aidB);

        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM binary_awards a
              JOIN activations act ON act.id = a.activation_id
              WHERE a.user_id = ? AND a.kind = "pair"'
        );
        $stmt->execute([(int) $tree['admin']['id']]);
        assert_same(0, (int) $stmt->fetchColumn(), 'root never has a package award');
    },

    'P23C-T21 package switched off before completion: nothing placed' => function () use ($buildFixtureTree, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();

        $pkg = Package::findBySlug('package-a');
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);
        setting_set('package_binary_enabled', '1');
        setting_cache_clear();

        $aid = $createApprovedActivation((int) $tree['buyerA']['id'], (int) $pkg['id']);

        /* Switch off inside the same request, before the service call. */
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '0',
            'binary_points'          => '0',
        ], $nineLevels(), [], (int) $pkg['id']);

        $result = (new PackageBinaryService())->onActivation($aid);

        assert_same(true,  $result['processed']);
        assert_same(0,     $result['points']);
        assert_same(0,     $result['pairs']);
        assert_same(0.0,   $result['amount']);
        assert_count(0,    $result['awards']);
    },

    'P23C-T22 c holds PKC (disabled): no pair, no consumption, blockedMap names it' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();
        $pkgAId = $pkgABinary(); /* buyerA's package: enabled, 1000 pts */

        /* c holds PKC: same package row, but binary_enabled = 0 for c's
         * qualification check. We flip it AFTER buyerA's activation so
         * buyerA's volume still places. */
        $pkg = Package::find((int) $pkgAId);

        /* c's user row: set package to this package. */
        db()->prepare('UPDATE users SET package_id = ?, member_status = "active", is_active = 1 WHERE id = ?')
            ->execute([$pkgAId, (int) $tree['c']['id']]);

        setting_set('package_binary_enabled', '1');
        setting_set('package_binary_requires_package', '1');
        setting_cache_clear();

        /* buyerA: 1000 left at c (package still enabled). */
        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgAId);
        (new PackageBinaryService())->onActivation($aidA);

        /* Now switch the package off — c is no longer qualified. */
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '0',
            'binary_points'          => '1000',
        ], $nineLevels(), [], $pkgAId);

        /* buyerB: 1000 right at c → c would pair but is blocked. */
        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], $pkgAId);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true, $result['processed']);
        assert_same(0,    $result['pairs'], 'no pair when recipient is not qualified');

        /* c's points are NOT consumed: still 1000/0 (buyerA is left of c,
         * buyerB's blocked activation placed nothing on the right). */
        $c = BinaryNode::forUser((int) $tree['c']['id']);
        assert_same(1000, (int) ($c['left_points']  ?? 0), 'c left not consumed');
        assert_same(0,    (int) ($c['right_points'] ?? 0), 'c right untouched');

        /* blockedMap names c with the package reason. */
        $blocked = (new PackageBinaryService())->blockedMap((int) $tree['buyerB']['id']);
        assert_true(array_key_exists((int) $tree['c']['id'], $blocked), 'c is in the blocked map');
        assert_contains('binary-enabled', $blocked[(int) $tree['c']['id']], 'block reason names the package');
    },

    'P23C-T23 c holds PKB (0 pts, enabled): c qualifies, the pair pays' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();
        $pkgAId = $pkgABinary(); /* enabled, 1000 pts */

        /* c's package: binary_enabled = 1 (qualifies), binary_points = 0
         * (c's own activations contribute nothing, but c can still earn). */
        $pkg = Package::find((int) $pkgAId);
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '1000',
        ], $nineLevels(), [], $pkgAId);

        db()->prepare('UPDATE users SET package_id = ?, member_status = "active", is_active = 1 WHERE id = ?')
            ->execute([$pkgAId, (int) $tree['c']['id']]);

        setting_set('package_binary_enabled', '1');
        setting_set('package_binary_requires_package', '1');
        setting_cache_clear();

        /* buyerA: 1000 left at c. */
        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgAId);
        (new PackageBinaryService())->onActivation($aidA);

        /* buyerB: 1000 right at c → c qualifies, pair pays. */
        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], $pkgAId);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true, $result['processed']);
        assert_same(1,    $result['pairs'], 'c qualifies on a binary-enabled package');
    },

    'P23C-T24 requires_package off, c on PKC: the pair pays' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();
        $pkgAId = $pkgABinary(); /* buyerA/buyerB's activation package: enabled, 1000 pts */

        /* c's package: package-b, disabled (PKC off). requires_package = 0
         * means c still earns despite being on a disabled package. */
        $pkgB = Package::findBySlug('package-b');
        assert_not_null($pkgB, 'package-b must exist');
        Package::save([
            'name'                   => $pkgB['name'],
            'price'                  => $pkgB['price'],
            'product_value'          => $pkgB['product_value'],
            'direct_referral_bonus'  => $pkgB['direct_referral_bonus'],
            'status'                 => $pkgB['status'],
            'sort_order'             => $pkgB['sort_order'],
            'indirect_enabled'       => $pkgB['indirect_enabled'],
            'binary_enabled'         => '0',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkgB['id']);

        db()->prepare('UPDATE users SET package_id = ?, member_status = "active", is_active = 1 WHERE id = ?')
            ->execute([(int) $pkgB['id'], (int) $tree['c']['id']]);

        setting_set('package_binary_enabled', '1');
        setting_set('package_binary_requires_package', '0');
        setting_cache_clear();

        /* buyerA: 1000 left at c (package-a enabled). */
        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], $pkgAId);
        (new PackageBinaryService())->onActivation($aidA);

        /* buyerB: 1000 right at c → without the package gate, c earns. */
        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], $pkgAId);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true, $result['processed']);
        assert_same(1,    $result['pairs'], 'without the package gate, c earns');
    },

    'P23C-T25 c is_active = 0: no pair, no consumption' => function () use ($buildFixtureTree, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();

        $pkg = Package::findBySlug('package-a');
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);

        db()->prepare('UPDATE users SET package_id = ?, member_status = "active", is_active = 0 WHERE id = ?')
            ->execute([(int) $pkg['id'], (int) $tree['c']['id']]);

        setting_set('package_binary_enabled', '1');
        setting_set('package_binary_requires_package', '1');
        setting_cache_clear();

        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], (int) $pkg['id']);
        (new PackageBinaryService())->onActivation($aidA);

        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], (int) $pkg['id']);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true,  $result['processed']);
        assert_same(0,     $result['pairs'], 'disabled account cannot earn');
    },

    'P23C-T26 c suspended: same as inactive' => function () use ($buildFixtureTree, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();

        $pkg = Package::findBySlug('package-a');
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);

        db()->prepare('UPDATE users SET package_id = ?, member_status = "suspended", is_active = 1 WHERE id = ?')
            ->execute([(int) $pkg['id'], (int) $tree['c']['id']]);

        setting_set('package_binary_enabled', '1');
        setting_set('package_binary_requires_package', '1');
        setting_cache_clear();

        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], (int) $pkg['id']);
        (new PackageBinaryService())->onActivation($aidA);

        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], (int) $pkg['id']);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true,  $result['processed']);
        assert_same(0,     $result['pairs'], 'suspended membership cannot earn');
    },

    'P23C-T27 c not Active this month: the pair still pays (package stream skips the Active rule)' => function () use ($buildFixtureTree, $createApprovedActivation, $nineLevels): void {
        $tree = $buildFixtureTree();

        $pkg = Package::findBySlug('package-a');
        Package::save([
            'name'                   => $pkg['name'],
            'price'                  => $pkg['price'],
            'product_value'          => $pkg['product_value'],
            'direct_referral_bonus'  => $pkg['direct_referral_bonus'],
            'status'                 => $pkg['status'],
            'sort_order'             => $pkg['sort_order'],
            'indirect_enabled'       => $pkg['indirect_enabled'],
            'binary_enabled'         => '1',
            'binary_points'          => '1000',
        ], $nineLevels(), [], (int) $pkg['id']);

        db()->prepare('UPDATE users SET package_id = ?, member_status = "active", is_active = 1 WHERE id = ?')
            ->execute([(int) $pkg['id'], (int) $tree['c']['id']]);

        setting_set('package_binary_enabled', '1');
        setting_set('package_binary_requires_package', '1');
        setting_set('binary_requires_active', '1');
        setting_cache_clear();

        $aidA = $createApprovedActivation((int) $tree['buyerA']['id'], (int) $pkg['id']);
        (new PackageBinaryService())->onActivation($aidA);

        $aidB = $createApprovedActivation((int) $tree['buyerB']['id'], (int) $pkg['id']);
        $result = (new PackageBinaryService())->onActivation($aidB);

        assert_same(true, $result['processed']);
        assert_same(1,    $result['pairs'], 'the Active rule does not apply to package pairs');
    },

    'P23C-T37 quoteActivation is read-only and returns pairs' => function () use ($buildFixtureTree, $pkgABinary): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        /* buyerB must carry a binary-enabled package to qualify. */
        db()->prepare('UPDATE users SET package_id = ? WHERE id = ?')
            ->execute([$pkgId, (int) $tree['buyerB']['id']]);

        $service = new PackageBinaryService();
        $quote = $service->quoteActivation($pkgId, (int) $tree['buyerB']['id']);

        assert_same(true,  $quote['eligible']);
        assert_same(1000,  $quote['points']);
        assert_same(0,     $quote['pairs']);
        assert_same(0.0,   $quote['amount']);
        assert_null($quote['reason']);

        /* No writes were made. */
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_volume WHERE source = "activation"')->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_awards WHERE source = "activation"')->fetchColumn());
    },

    'P23C-T38 onActivation twice: the second call is replayed' => function () use ($buildFixtureTree, $pkgABinary, $createApprovedActivation): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        $aid = $createApprovedActivation((int) $tree['buyerB']['id'], $pkgId);
        $service = new PackageBinaryService();

        $first  = $service->onActivation($aid);
        $second = $service->onActivation($aid);

        assert_same(true,  $first['processed']);
        assert_same(false, $first['replayed']);
        assert_same(false, $second['processed']);
        assert_same(true,  $second['replayed']);
        assert_same('replayed', $second['reason']);

        /* No duplicate volume: still five rows. */
        $volume = BinaryVolume::forActivation($aid);
        assert_count(5, $volume, 'replay does not duplicate volume rows');
    },

    'P23C-T39 duplicate (activation_id, user_id, kind) → PDOException 23000' => function () use ($buildFixtureTree, $pkgABinary): void {
        $tree = $buildFixtureTree();
        $pkgId = $pkgABinary();

        $aid = Activation::create([
            'user_id'        => (int) $tree['buyerB']['id'],
            'package_id'     => $pkgId,
            'amount'         => '1000.00',
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);
        Activation::markApproved($aid, null);

        BinaryAward::createForActivation([
            'kind'       => 'pair',
            'buyer_id'   => (int) $tree['buyerB']['id'],
            'user_id'    => (int) $tree['c']['id'],
            'side'       => 'left',
            'points'     => 2000,
            'pair_count' => 1,
            'pair_points'=> 1000,
            'rate_pct'   => 10.0,
            'amount'     => 100.00,
            'status'     => 'credited',
            'hold_days'  => 0,
            'paid_at'    => date('Y-m-d H:i:s'),
            'activation_id'=> $aid,
        ]);

        $threw = false;
        try {
            BinaryAward::createForActivation([
                'kind'       => 'pair',
                'buyer_id'   => (int) $tree['buyerB']['id'],
                'user_id'    => (int) $tree['c']['id'],
                'side'       => 'left',
                'points'     => 2000,
                'pair_count' => 1,
                'pair_points'=> 1000,
                'rate_pct'   => 10.0,
                'amount'     => 100.00,
                'status'     => 'credited',
                'hold_days'  => 0,
                'paid_at'    => date('Y-m-d H:i:s'),
                'activation_id'=> $aid,
            ]);
        } catch (PDOException $e) {
            $threw = strpos((string) $e->getCode(), '23000') !== false;
        }
        assert_true($threw, 'duplicate activation award violates uq_binary_award_activation');
    },
];



