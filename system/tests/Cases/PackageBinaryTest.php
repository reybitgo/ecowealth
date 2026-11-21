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
];
