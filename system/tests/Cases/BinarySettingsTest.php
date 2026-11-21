<?php
declare(strict_types=1);

/* ==============================================================
 *  BinarySettingsTest — Phase 22B-S1 (plan §14.4, §6 Phase 22B)
 *
 *  The Product binary settings block on /admin/settings: the master
 *  switch, the five money knobs, the four toggles, the readiness
 *  copy, server-side validation and the settings.update audit trail.
 *
 *  No money moves here: the switch is inert until 22D.
 *
 *  Run this group alone with:  php tests/run.php BinarySettings
 * ============================================================== */

$renderPage = static function (array $overrides = []): string {
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

/** The markup of the Commissions card form. */
$commissions = static function () use ($renderPage): string {
    $pattern = '/<form\b[^>]*\bid="settings-commissions"[^>]*>(.*?)<\/form>/s';

    preg_match($pattern, $renderPage(), $m);

    return $m[1] ?? '';
};

/** Call a private static method on the settings controller. */
$sectionMethod = static function (string $method, string $section): array {
    $reflection = new ReflectionMethod(AdminSettingsController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $section);
};

/** The last audit id, as the "did anything get audited?" probe. */
$lastAudit = static function (): int {
    return (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
};

/** Every key and toggle the Commissions card owns, with stored values. */
$baseline = static function () use ($sectionMethod): array {
    $settings = setting_all();
    $posted   = [];

    foreach ($sectionMethod('sectionKeys', 'commissions') as $key) {
        if (str_starts_with($key, 'team_harvest_amounts.')) {
            $level        = (int) substr($key, strlen('team_harvest_amounts.'));
            $posted[$key] = (string) (TeamHarvestLevel::forForm(TeamHarvestLevel::amounts())[$level] ?? '');
            continue;
        }

        if (str_starts_with($key, 'general_harvest_amounts.')) {
            $level        = (int) substr($key, strlen('general_harvest_amounts.'));
            $posted[$key] = (string) (GeneralHarvestLevel::forForm(GeneralHarvestLevel::amounts())[$level] ?? '');
            continue;
        }

        $posted[$key] = (string) ($settings[$key] ?? '');
    }

    foreach ($sectionMethod('sectionToggles', 'commissions') as $key) {
        $posted[$key] = (string) ($settings[$key] ?? '0');
    }

    return $posted;
};

/** Baseline + the submitted changes. */
$posted = static function (array $changes = []) use ($baseline): array {
    return array_merge($baseline(), $changes);
};

/** Validate one card payload exactly as applySection() does, without writing. */
$validate = static function (array $changes = []) use ($posted, $sectionMethod): array {
    $input = $posted($changes);

    $errors = Validator::check(
        $input,
        $sectionMethod('sectionRules', 'commissions'),
        $sectionMethod('sectionLabels', 'commissions')
    );

    $cross = new ReflectionMethod(AdminSettingsController::class, 'sectionCrossFieldErrors');
    $cross->setAccessible(true);

    return array_merge($errors, $cross->invoke(null, 'commissions', $input));
};

/** Apply one card payload through the real controller path. */
$apply = static function (array $changes = []) use ($posted): array {
    return (new AdminSettingsController())->applySection('commissions', $posted($changes));
};

return [
    /* ---------- The rendered card (P22B-T18) ---------- */

    'P22B-T18 the commissions card carries the nine binary keys, master first, no baseline' => function () use ($sectionMethod, $commissions): void {
        test_signed_in_as(test_user('p22b_card_admin', 'admin'));

        $keys  = $sectionMethod('sectionKeys', 'commissions');
        $html  = $commissions();

        $binaryKeys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => str_starts_with($key, 'binary_')
        ));

        assert_same(9, count($binaryKeys), 'nine visible binary keys');
        assert_same('binary_enabled', $binaryKeys[0], 'the master switch is first');
        assert_true(in_array('binary_self_pct', $binaryKeys, true));
        assert_true(in_array('binary_pair_points', $binaryKeys, true));
        assert_true(in_array('binary_pair_pct', $binaryKeys, true));
        assert_true(in_array('binary_daily_pair_cap', $binaryKeys, true));
        assert_true(in_array('binary_hold_days', $binaryKeys, true));
        assert_true(in_array('binary_requires_active', $binaryKeys, true));
        assert_true(in_array('binary_release_enabled', $binaryKeys, true));
        assert_true(in_array('binary_reversal_enabled', $binaryKeys, true));
        assert_false(in_array('binary_ledger_since', $binaryKeys, true), 'the baseline is never rendered');

        assert_contains('name="binary_enabled"', $html);
        assert_contains('name="binary_self_pct"', $html);
        assert_contains('name="binary_pair_points"', $html);
        assert_contains('name="binary_pair_pct"', $html);
        assert_contains('name="binary_daily_pair_cap"', $html);
        assert_contains('name="binary_hold_days"', $html);
        assert_contains('name="binary_requires_active"', $html);
        assert_contains('name="binary_release_enabled"', $html);
        assert_contains('name="binary_reversal_enabled"', $html);
        assert_not_contains('name="binary_ledger_since"', $html);

        /* The master switch renders before every other binary input. */
        assert_true(
            strpos($html, 'name="binary_enabled"') < strpos($html, 'name="binary_self_pct"'),
            'the master switch comes first'
        );
    },

    /* ---------- Validation (P22B-T7 … T10) ---------- */

    'P22B-T7 a pair size of 0 is refused on that field' => function () use ($validate): void {
        test_signed_in_as(test_user('p22b_pair_admin', 'admin'));

        $errors = $validate(['binary_pair_points' => '0']);

        assert_true(isset($errors['binary_pair_points']), 'the refusal lands on the pair size');
        assert_contains('at least 1', $errors['binary_pair_points']);

        /* 1 is the floor and is accepted. */
        assert_same([], $validate(['binary_pair_points' => '1']));
    },

    'P22B-T8 self % above 100 or with four decimals is refused' => function () use ($validate): void {
        test_signed_in_as(test_user('p22b_self_admin', 'admin'));

        /* Above 100 is refused on the range rule. */
        assert_true(isset($validate(['binary_self_pct' => '100.001'])['binary_self_pct']));
        assert_contains('at most 100', $validate(['binary_self_pct' => '100.001'])['binary_self_pct']);
        assert_true(isset($validate(['binary_self_pct' => '150'])['binary_self_pct']));

        /* Four decimals is refused on the precision rule while still in range. */
        assert_true(isset($validate(['binary_self_pct' => '5.0001'])['binary_self_pct']));
        assert_contains('at most 3 decimal places', $validate(['binary_self_pct' => '5.0001'])['binary_self_pct']);

        /* Text and negative are refused too. */
        assert_true(isset($validate(['binary_self_pct' => 'abc'])['binary_self_pct']));
        assert_true(isset($validate(['binary_self_pct' => '-0.5'])['binary_self_pct']));

        /* Exactly 100.000, zero and a normal value are accepted. */
        assert_same([], $validate(['binary_self_pct' => '100.000']));
        assert_same([], $validate(['binary_self_pct' => '0']));
        assert_same([], $validate(['binary_self_pct' => '5.000']));
        assert_same([], $validate(['binary_self_pct' => '99.999']));
    },

    'P22B-T9 the daily pair cap of 0 means unlimited and is accepted' => function () use ($validate): void {
        test_signed_in_as(test_user('p22b_cap_admin', 'admin'));

        assert_same([], $validate(['binary_daily_pair_cap' => '0']));
        assert_same([], $validate(['binary_daily_pair_cap' => '1000']));
        assert_true(isset($validate(['binary_daily_pair_cap' => '1001'])['binary_daily_pair_cap']));
    },

    'P22B-T10 hold days above 365 is refused' => function () use ($validate): void {
        test_signed_in_as(test_user('p22b_hold_admin', 'admin'));

        assert_true(isset($validate(['binary_hold_days' => '366'])['binary_hold_days']));
        assert_contains('at most 365', $validate(['binary_hold_days' => '366'])['binary_hold_days']);

        assert_same([], $validate(['binary_hold_days' => '0']));
        assert_same([], $validate(['binary_hold_days' => '365']));
    },

    /* ---------- Save + audit (P22B-T6) ---------- */

    'P22B-T6 saving the preset writes every value and one settings.update row' => function () use ($apply, $lastAudit): void {
        $admin = test_user('p22b_save_admin', 'admin');
        test_signed_in_as($admin);

        $auditBefore = $lastAudit();

        /* The preset values differ from nothing except the master switch,
         * which flips off → on so the save really moves. */
        $result = $apply([
            'binary_enabled'           => '1',
            'binary_self_pct'          => '5.000',
            'binary_pair_points'       => '1000',
            'binary_pair_pct'          => '10.000',
            'binary_daily_pair_cap'    => '20',
            'binary_hold_days'         => '7',
        ]);

        assert_same([], $result['errors'], 'the preset validates');
        assert_same('1', setting('binary_enabled'));
        assert_same('5.000', setting('binary_self_pct'));
        assert_same('1000', setting('binary_pair_points'));
        assert_same('10.000', setting('binary_pair_pct'));
        assert_same('20', setting('binary_daily_pair_cap'));
        assert_same('7', setting('binary_hold_days'));

        $stmt = db()->prepare(
            "SELECT action, actor_id, subject_type, meta_json FROM audit_log
              WHERE action = 'settings.update' AND id > ? ORDER BY id"
        );
        $stmt->execute([$auditBefore]);
        $rows = $stmt->fetchAll();

        assert_count(1, $rows, 'exactly one settings.update audit row');
        assert_same('settings', (string) $rows[0]['subject_type']);
        assert_same((int) $admin['id'], (int) $rows[0]['actor_id']);

        $meta = json_decode((string) $rows[0]['meta_json'], true);
        assert_same('commissions', $meta['section']);
        assert_true(isset($meta['changes']));
        assert_true(array_key_exists('binary_enabled', $meta['changes']));
        assert_same('0', $meta['changes']['binary_enabled']['from']);
        assert_same('1', $meta['changes']['binary_enabled']['to']);

        /* Saving the same values again writes no audit row. */
        $againBefore = $lastAudit();
        $again       = $apply([]);

        assert_same([], $again['changes'], 'nothing moved on a repeat save');
        assert_same($againBefore, $lastAudit(), 'no audit row for an unchanged save');
    },

    /* ---------- Off state (P22B-T11) ---------- */

    'P22B-T11 with the master off the page says so and nothing pays' => function () use ($apply, $commissions): void {
        test_signed_in_as(test_user('p22b_off_admin', 'admin'));

        $result = $apply([
            'binary_enabled'           => '0',
            'binary_self_pct'          => '5.000',
            'binary_pair_points'       => '1000',
            'binary_pair_pct'          => '10.000',
            'binary_daily_pair_cap'    => '20',
            'binary_hold_days'         => '7',
        ]);

        assert_same([], $result['errors']);
        assert_same('0', setting('binary_enabled'), 'the master stays off');

        $html = $commissions();

        assert_contains('The stream is off. Nothing is paid.', $html);
        assert_not_contains('Orders now place points and pay', $html);
    },

    /* ---------- Readiness copy (P22B-T20) ---------- */

    'P22B-T20 the readiness preset copy names the pair value' => function () use ($renderPage, $commissions): void {
        test_signed_in_as(test_user('p22b_ready_admin', 'admin'));

        $html = $commissions();

        assert_contains('Product binary readiness', $html);
        assert_contains('1,000 points per pair pays ₱100.00 at 10%.', $html);

        /* Recomputed when the pair values change. */
        setting_set('binary_pair_points', '2000');
        setting_set('binary_pair_pct', '7.500');
        setting_cache_clear();

        $recomputed = $commissions();

        assert_contains('2,000 points per pair pays ₱150.00 at 7.5%.', $recomputed);

        setting_set('binary_pair_points', '1000');
        setting_set('binary_pair_pct', '10.000');
        setting_cache_clear();
    },

    /* ---------- Live hint (P22B-T24) ---------- */

    'P22B-T24 with the stream on the card warns that orders pay' => function () use ($commissions): void {
        test_signed_in_as(test_user('p22b_on_admin', 'admin'));

        setting_set('binary_enabled', '1');
        setting_cache_clear();

        $html = $commissions();

        assert_contains('Orders now place points and pay awards when the money engine is live.', $html);
        assert_not_contains('The stream is off. Nothing is paid.', $html);

        setting_set('binary_enabled', '0');
        setting_cache_clear();
    },

    /* ---------- Readiness counts (P22B-T19) ---------- */

    'P22B-T19 readiness panel shows the four counts and links to the filtered list' => function () use ($renderPage, $commissions): void {
        test_signed_in_as(test_user('p22b_readiness_admin', 'admin'));

        /* One flagged-with-points product, one flagged-off product. */
        Product::save([
            'name'           => 'Readiness On',
            'price'          => '10.00',
            'pv'             => '250.00',
            'stock'          => '1',
            'status'         => 'active',
            'binary_enabled' => '1',
        ]);
        Product::save([
            'name'           => 'Readiness Off',
            'price'          => '10.00',
            'pv'             => '250.00',
            'stock'          => '1',
            'status'         => 'active',
            'binary_enabled' => '0',
        ]);

        /* A misconfigured product (flagged but P.V. 0) can only exist via a
         * direct row — validation refuses it through the form — and that is
         * exactly the state the readiness count surfaces. */
        $slug = 'ready-' . bin2hex(random_bytes(4));
        db()->prepare(
            'INSERT INTO products (sku, name, slug, price, pv, stock, status, binary_enabled)
             VALUES (?, ?, ?, 10.00, 0.00, 1, "active", 1)'
        )->execute(['READY-' . bin2hex(random_bytes(3)), 'Readiness Zero', $slug]);

        $expectedOff   = (int) db()->query("SELECT COUNT(*) FROM products WHERE binary_enabled = 0")->fetchColumn();
        $expectedZero  = (int) db()->query("SELECT COUNT(*) FROM products WHERE binary_enabled = 1 AND pv = 0")->fetchColumn();

        assert_same(1, $expectedZero, 'the direct row is the one flagged-but-zero product');

        $html = $commissions();

        assert_contains('Product binary readiness', $html);
        assert_contains('Binary on, no points', $html);
        assert_contains('Binary off', $html);
        assert_contains((string) $expectedZero, $html);
        assert_contains((string) $expectedOff, $html);

        /* Both links render: the on-link because a zero-PV flagged product exists,
         * the off-link because off products exist. */
        assert_contains('<a href="' . e(url('admin/products?binary=on')) . '"', $html);
        assert_contains('<a href="' . e(url('admin/products?binary=off')) . '"', $html);

        /* The on-link names the misconfiguration; the off-link names the rest. */
        assert_contains('flagged but earn 0 points', $html);
        assert_contains('do not place points', $html);
    },

    /* ---------- No money path yet (P22B-T22) ---------- */

    'P22B-T22 paying an order with the switch on writes no binary row' => function (): void {
        $buyer = test_user('p22b_t22_buyer', 'member');
        test_signed_in_as($buyer);

        /* Configure a product and flip the master switch on. */
        $product = Product::save([
            'name'           => 'T22 Product',
            'price'          => '300.00',
            'pv'             => '250.00',
            'stock'          => '10',
            'status'         => 'active',
            'binary_enabled' => '1',
        ]);
        setting_set('binary_enabled', '1');
        setting_cache_clear();

        (new WalletService())->credit($buyer['id'], '1000.00', 'commission', random_int(1000000, 9999999), 'Test funds');

        (new CartService())->add($buyer['id'], $product, 1);
        $orderId = (int) (new OrderService())->placeFromCart(
            $buyer['id'],
            [
                'billing_name'      => 'Buyer One',
                'billing_phone'     => '0917 000 0000',
                'shipping_name'     => 'Buyer One',
                'shipping_phone'    => '0917 000 0000',
                'shipping_address'  => '12 Mabini Street',
                'shipping_barangay' => 'Poblacion',
                'shipping_city'     => 'Makati',
                'shipping_province' => 'Metro Manila',
                'shipping_postal'   => '1200',
                'notes_member'      => '',
                'payment_method'    => 'ewallet',
                'idempotency_key'   => bin2hex(random_bytes(16)),
                'terms'             => '1',
                'binary_position'   => 'left',
            ]
        )['order_id'];

        $order = Order::find($orderId);
        assert_not_null($order);
        assert_same('paid', $order['status']);

        /* No money hook exists in this phase: nothing binary is written. */
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_awards WHERE order_id = ' . $orderId)->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM binary_volume WHERE order_id = ' . $orderId)->fetchColumn());
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'binary'")->fetchColumn());
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'binary_reversal'")->fetchColumn());

        setting_set('binary_enabled', '0');
        setting_cache_clear();
    },

    /* ---------- Member pages stay binary-free (P22B-T21) ---------- */

    'P22B-T21 member earnings page renders no binary copy' => function (): void {
        $renderView = static function (string $template, array $data): string {
            ob_start();

            try {
                View::page('member', $template, $data);
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            return (string) ob_get_clean();
        };

        $earningsData = static function (int $userId): array {
            $filters = Commission::normaliseFilters([]);
            $pager   = Paginator::make(Commission::countForUser($userId, $filters), 1, Paginator::DEFAULT_PER_PAGE);

            return [
                'page_title'    => 'Earnings',
                'current_nav'   => 'earnings',
                'totals'        => Commission::totalsForUser($userId),
                'levels'        => Commission::levelBreakdown($userId),
                'filters'       => $filters,
                'pager'         => $pager,
                'commissions'   => Commission::pageForUser($userId, $filters, $pager),
                'wallet_url'    => url('wallet'),
                'genealogy_url' => url('genealogy'),
                'statement_url' => url('earnings'),
                'export_url'    => url('earnings/export'),
            ];
        };

        $member = test_user('p22b_t21_' . bin2hex(random_bytes(4)));
        test_signed_in_as($member);

        $html = $renderView('member/earnings', $earningsData((int) $member['id']));

        /* 22B adds no member-facing binary surface; 22F owns the earnings tab. */
        assert_not_contains('binary', strtolower($html));
    },
];
