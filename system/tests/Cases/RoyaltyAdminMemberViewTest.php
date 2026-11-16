<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyAdminMemberViewTest — Phase 19B / 19D (plan §6, §8, §12.2)
 *
 *  Proves the admin surfaces around the rank ladder:
 *
 *      column        each row's badge is the rank RoyaltyRank derived
 *      distribution  the five exclusive counts plus `ranked`
 *      money         saving the card and rendering the list moves no
 *                    wallet, ledger, period or award row (P19B-T25)
 *      member panel  the read-only Royalty card on /admin/members/{id}:
 *                    rank, quota progress, MTD volume, the last six
 *                    awards and all-time paid (P19D-T14)
 *
 *  Run this group alone with:  php tests/run.php RoyaltyAdminMemberView
 * ============================================================== */

/** One member account, returning its id. */
$insertMember = static function (array $row): int {
    $columns = array_keys($row);
    $marks   = implode(', ', array_fill(0, count($columns), '?'));

    db()->prepare('INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . $marks . ')')
        ->execute(array_values($row));

    return (int) db()->lastInsertId();
};

/** Create one member account (the fixed hash keeps the fixture cheap). */
$member = static function (string $username, ?int $sponsor = null, array $overrides = []) use ($insertMember): int {
    return $insertMember($overrides + [
        'username'      => $username,
        'email'         => $username . '@view.test',
        'password_hash' => '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
        'full_name'     => ucfirst($username) . ' View',
        'role'          => 'member',
        'member_status' => 'active',
        'sponsor_id'    => $sponsor,
        'is_active'     => 1,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
};

/** Overwrite the four thresholds and drop the settings cache. */
$setThresholds = static function (array $thresholds): void {
    foreach ($thresholds as $rank => $value) {
        setting_set(RoyaltyRank::THRESHOLD_KEYS[$rank], (string) $value);
    }

    setting_cache_clear();
};

/**
 * The small-threshold Director tree: Director + two Managers + four
 * Supervisors + eight Leaders + eight directs (23 members).
 *
 * Thresholds: leader=1, supervisor=2, manager=2, director=2.
 *
 * @return array{director:int, managers:list<int>, supervisors:list<int>, leaders:list<int>}
 */
$directorTree = static function () use ($member): array {
    $director    = $member('view_director');
    $managers    = [];
    $supervisors = [];
    $leaders     = [];

    foreach (['a', 'b'] as $branch) {
        $manager    = $member('view_manager_' . $branch, $director);
        $managers[] = $manager;

        foreach (['x', 'y'] as $leg) {
            $supervisor    = $member('view_sup_' . $branch . $leg, $manager);
            $supervisors[] = $supervisor;

            foreach (['1', '2'] as $leaf) {
                $leader    = $member('view_leader_' . $branch . $leg . $leaf, $supervisor);
                $leaders[] = $leader;
                $member('view_direct_' . $branch . $leg . $leaf, $leader);
            }
        }
    }

    return [
        'director'    => $director,
        'managers'    => $managers,
        'supervisors' => $supervisors,
        'leaders'     => $leaders,
    ];
};

/** Render a page through View::page() and return the HTML. */
$renderPage = static function (array $data, string $template = 'admin/members/index'): string {
    ob_start();

    try {
        View::page('admin', $template, $data);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/** Call a private static method on the settings controller. */
$sectionMethod = static function (string $method, string $section): array {
    $reflection = new ReflectionMethod(AdminSettingsController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $section);
};

/** The `<tr>` markup of one keyed member row. */
$rowFor = static function (string $html, int $id): string {
    $pattern = '/<tr\b[^>]*data-live-key="' . $id . '"[^>]*>(.*?)<\/tr>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[1] : '';
};

return [
    /* ==========================================================
     *  The Rank column (P19B-T23)
     * ========================================================== */

    'P19B-T23 the member directory shows the derived rank badge per row' => function () use ($setThresholds, $directorTree, $member, $renderPage, $rowFor): void {
        $setThresholds(['leader' => 1, 'supervisor' => 2, 'manager' => 2, 'director' => 2]);

        $admin = test_user('view_admin', 'admin');
        $tree  = $directorTree();

        /* A Leader outside the Director tree and a plain Member. */
        $leader = $member('view_standalone_leader');
        $member('view_leader_direct', $leader);
        $plain = $member('view_plain_member');

        test_signed_in_as($admin);

        $_GET = ['per_page' => '100'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?per_page=100';

        $controller = new AdminMemberController;
        $context    = $controller->listContext();

        $html = $renderPage($context + [
            'page_title'  => 'Members',
            'current_nav' => 'admin.members',
            'page_css'    => ['assets/css/membership.css'],
            'page_js'     => ['assets/js/admin-live.js'],
            'live'        => Live::meta('admin.members', 'page'),
        ]);

        assert_contains('<th>Rank</th>', $html, 'the directory table has the Rank column');
        assert_contains('data-live-key="' . $tree['director'] . '"', $html);
        assert_contains('Rank distribution', $html);

        $expectations = [
            $tree['director']      => ['Director', 'success'],
            $tree['managers'][0]    => ['Manager', 'warning'],
            $tree['supervisors'][0] => ['Supervisor', 'primary'],
            $tree['leaders'][0]     => ['Leader', 'info'],
            $leader                => ['Leader', 'info'],
            $plain                 => ['Member', 'secondary'],
        ];

        foreach ($expectations as $id => [$name, $tone]) {
            $row = $rowFor($html, (int) $id);

            assert_true($row !== '', 'row ' . $id . ' renders');
            assert_contains('bg-label-' . $tone . '">' . $name . '<', $row, 'row ' . $id . ' carries the ' . $name . ' badge');
        }

        /* The row fragment of the live feed carries the same badges. */
        $payload = $controller->feedPayload(2, '"etag"', $controller->listContext());
        $rows    = $payload['fragments']['member-rows'];

        assert_contains('bg-label-success">Director<', $rows);
        assert_contains('bg-label-secondary">Member<', $rows);

        /* The empty state spans every column: eight in 19B, nine since 20E
         * added the Harvest column. */
        $_GET = ['q' => 'no-such-member-in-any-tree'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?q=no-such-member-in-any-tree';

        $empty = $controller->feedPayload(3, '"etag"', $controller->listContext())['fragments']['member-rows'];

        assert_contains('colspan="9"', $empty);
        assert_contains('No members match these filters', $empty);
    },

    /* ==========================================================
     *  The distribution (P19B-T24)
     * ========================================================== */

    'P19B-T24 the stats card counts the fixture tree exactly' => function () use ($setThresholds, $directorTree, $member): void {
        $setThresholds(['leader' => 1, 'supervisor' => 2, 'manager' => 2, 'director' => 2]);

        test_signed_in_as(test_user('view_dist_admin', 'admin'));

        $tree = $directorTree();
        $member('view_dist_plain');

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members';

        $controller = new AdminMemberController;
        $context    = $controller->listContext();

        $expected = [
            'member'     => 12,  // eight tree directs, the plain Member, peter/sarah/david
            'leader'     => 10,  // eight tree Leaders, juan and maria
            'supervisor' => 4,
            'manager'    => 2,
            'director'   => 1,
            'ranked'     => 7,
            'total'      => 29,
        ];

        assert_same($expected, $context['rank_counts'], 'the distribution is the exact exclusive count');

        $stats = $controller->feedPayload(4, '"etag"', $context)['fragments']['member-stats'];

        assert_contains('data-rank-distribution', $stats);
        assert_contains('Rank distribution', $stats);

        foreach (['member' => 'Member 12', 'leader' => 'Leader 10', 'supervisor' => 'Supervisor 4',
            'manager' => 'Manager 2', 'director' => 'Director 1'] as $key => $text) {
            assert_contains('data-rank-count="' . $key . '"', $stats, $key . ' badge renders');
            assert_contains($text, $stats, $text . ' renders');
        }

        assert_contains('7 ranked (Supervisor+)', $stats);

        /* A filter does not change the distribution: it describes the
         * whole member base, not the page or the filter. */
        $_GET = ['status' => 'suspended'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?status=suspended';

        assert_same($expected, $controller->listContext()['rank_counts']);
    },

    /* ==========================================================
     *  No money (P19B-T25)
     * ========================================================== */

    'P19B-T25 saving the card and rendering the list moves no money' => function () use ($setThresholds, $renderPage, $sectionMethod): void {
        $setThresholds(['leader' => 1, 'supervisor' => 2, 'manager' => 2, 'director' => 2]);

        $admin = test_user('view_money_admin', 'admin');
        test_signed_in_as($admin);

        $walletBefore = (string) db()->query(
            "SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users"
        )->fetchColumn();
        $ledgerBefore = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();
        $royaltyLedgerBefore = (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn();
        $periodBefore = (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn();
        $awardBefore  = (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn();
        $auditBefore  = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        /* The card payload the browser posts: every stored value, then
         * the three edits. The Team Harvest (20B) and General Harvest (21B)
         * amounts are ladder rows, not settings keys, and a real card always
         * posts all ten of each. */
        $posted        = [];
        $teamAmounts   = TeamHarvestLevel::forForm(TeamHarvestLevel::amounts());
        $generalAmounts = GeneralHarvestLevel::forForm(GeneralHarvestLevel::amounts());

        foreach (array_merge($sectionMethod('sectionKeys', 'commissions'), $sectionMethod('sectionToggles', 'commissions')) as $key) {
            if (str_starts_with($key, TeamHarvestLevel::AMOUNT_FIELD . '.')) {
                $level        = (int) substr($key, strlen(TeamHarvestLevel::AMOUNT_FIELD . '.'));
                $posted[$key] = (string) ($teamAmounts[$level] ?? '');
                continue;
            }

            if (str_starts_with($key, GeneralHarvestLevel::AMOUNT_FIELD . '.')) {
                $level        = (int) substr($key, strlen(GeneralHarvestLevel::AMOUNT_FIELD . '.'));
                $posted[$key] = (string) ($generalAmounts[$level] ?? '');
                continue;
            }

            $posted[$key] = (string) setting($key, '');
        }

        $posted['royalty_enabled']            = '1';
        $posted['royalty_supervisor_leaders'] = '11';
        $posted['royalty_supervisor_quota']   = '550000.00';

        $result = (new AdminSettingsController)->applySection('commissions', $posted);

        assert_same([], $result['errors']);
        assert_same('11', setting('royalty_supervisor_leaders'));
        assert_same('1', setting('royalty_enabled'));

        /* Render the directory and its feed. */
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members';

        $controller = new AdminMemberController;
        $context    = $controller->listContext();
        $payload    = $controller->feedPayload(5, '"etag"', $context);

        $renderPage($context + [
            'page_title'  => 'Members',
            'current_nav' => 'admin.members',
            'page_css'    => ['assets/css/membership.css'],
            'page_js'     => ['assets/js/admin-live.js'],
            'live'        => Live::meta('admin.members', 'page'),
        ]);

        assert_true($payload['ok']);
        assert_contains('data-rank-count="member"', $payload['fragments']['member-stats']);

        /* Nothing money-shaped changed. */
        assert_same(
            $walletBefore,
            (string) db()->query("SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users")->fetchColumn(),
            'wallet balances are unchanged'
        );
        assert_same($ledgerBefore, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(), 'no ledger row');
        assert_same($royaltyLedgerBefore, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn());
        assert_same($periodBefore, (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn(), 'no period');
        assert_same($awardBefore, (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn(), 'no award');

        /* Exactly one audit row, and it is the settings save. */
        $audits = db()->prepare('SELECT action FROM audit_log WHERE id > ? ORDER BY id');
        $audits->execute([$auditBefore]);

        assert_same(['settings.update'], array_map('strval', $audits->fetchAll(PDO::FETCH_COLUMN)));
    },

    /* ==========================================================
     *  The member Royalty panel (P19D-T14)
     * ========================================================== */

    'P19D-T14 the member page shows rank, progress, MTD volume, six awards and all-time paid' => function () use ($setThresholds, $directorTree, $renderPage): void {
        $setThresholds(['leader' => 1, 'supervisor' => 2, 'manager' => 2, 'director' => 2]);

        foreach ([
            'royalty_enabled'          => '1',
            'royalty_pay_enabled'      => '1',
            'royalty_supervisor_quota' => '100000',
            'royalty_manager_quota'    => '250000',
            'royalty_director_quota'   => '500000',
        ] as $key => $value) {
            setting_set($key, $value);
        }

        setting_cache_clear();

        $admin    = test_user('view_royalty_panel_admin', 'admin');
        $tree     = $directorTree();
        $director = $tree['director'];

        /* A paid order in the open month: the panel's month-to-date
         * volume and its quota progress read `paid_at`, not the tree. */
        $productId = (int) db()->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
        $orderId   = test_order($director, [['product_id' => $productId]], 'paid', [
            'paid_at'  => date('Y-m-d H:i:s'),
            'subtotal' => '600000.00',
            'total'    => '600000.00',
        ]);

        db()->prepare('UPDATE shop_order_items SET line_total = ?, unit_price = ? WHERE order_id = ?')
            ->execute(['600000.00', '600000.00', $orderId]);

        /* Seven closed months of awards, so the panel must slice six. */
        $months = [];
        $cursor = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());

        for ($i = 0; $i < 7; $i++) {
            $months[] = $cursor;
            $cursor   = RoyaltyPeriod::previous($cursor);
        }

        foreach ($months as $period) {
            [$start, $end] = RoyaltyPeriod::bounds($period);

            db()->prepare(
                'INSERT INTO royalty_periods
                   (period, status, enabled, evaluated_at, evaluated_by, paid_at, paid_by, as_of,
                    leader_directs, supervisor_leaders, manager_supervisors, director_managers,
                    quota_supervisor, quota_manager, quota_director,
                    group_pct_supervisor, personal_pct_supervisor,
                    group_pct_manager, personal_pct_manager,
                    group_pct_director, personal_pct_director,
                    members_count, leaders_count, supervisors_count, managers_count, directors_count,
                    award_count, paid_count, skipped_count, award_total, paid_total, note)
                 VALUES (?, \'paid\', 1, ?, NULL, ?, NULL, ?, 1, 2, 2, 2,
                         100000.00, 250000.00, 500000.00,
                         3.000, 5.000, 5.000, 10.000, 10.000, 15.000,
                         1, 0, 0, 0, 1, 1, 1, 0, 1000.00, 1000.00, NULL)'
            )->execute([$period, $start, $start, $end]);

            $awardId = RoyaltyAward::create([
                'period'          => $period,
                'user_id'         => $director,
                'rank_level'      => RoyaltyRank::DIRECTOR,
                'group_volume'    => 5000000,
                'personal_volume' => 0,
                'quota'           => 500000,
                'group_pct'       => 10,
                'personal_pct'    => 15,
                'amount'          => 1000.00,
                'status'          => 'pending',
            ]);

            RoyaltyAward::markPaid($awardId, 500000 + $awardId);
        }

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members/' . $director;

        $controller = new AdminMemberController();
        $context    = $controller->memberContext(Member::find($director));

        assert_true(isset($context['royalty']), 'the panel data is built for the member page');

        $panel = $context['royalty'];
        assert_same(RoyaltyRank::DIRECTOR, (int) $panel['rank']);
        assert_same('Director', (string) $panel['rank_name']);
        assert_same(600000.0, (float) $panel['group'], 'group sales are the month-to-date volume');
        assert_same(600000.0, (float) $panel['personal'], 'the own purchase is inside the group');
        assert_same(500000.0, (float) $panel['quota']);
        assert_same(100, (int) $panel['progress'], 'the quota is met and the bar is capped');
        assert_same(7000.0, (float) $panel['paid_all_time']);
        assert_count(7, $panel['history']);

        $payload  = $controller->detailFeedPayload(2, '"x"', $context);
        $fragment = $payload['fragments']['member-royalty'];

        assert_contains('data-live-region="member-royalty"', $fragment);
        assert_contains('Royalty', $fragment);
        assert_contains('Director', $fragment);
        assert_contains('Quota progress', $fragment);
        assert_contains('Personal this month', $fragment);
        assert_contains('Group sales ' . money(600000.0) . ' of the ' . money(500000.0) . ' quota', $fragment);
        assert_contains('met', $fragment);
        assert_contains('Paid all time', $fragment);
        assert_contains(money(7000.0), $fragment);
        assert_contains('Last six awards', $fragment);

        $monthLabel = static function (string $period): string {
            $ts = strtotime($period . '-01 12:00:00');

            return $ts === false ? $period : date('M Y', $ts);
        };

        foreach (array_slice($months, 0, 6) as $period) {
            assert_contains($monthLabel($period), $fragment, 'the last six awards include ' . $period);
        }

        assert_not_contains($monthLabel($months[6]), $fragment, 'the seventh month is outside the slice');

        /* The page carries the region and the card is read-only. */
        $ledgerBefore = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();
        $walletBefore = (string) db()->query(
            "SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users"
        )->fetchColumn();

        $html = $renderPage($context + [
            'page_title'  => 'Member',
            'current_nav' => 'admin.members',
            'page_css'    => ['assets/css/membership.css'],
            'page_js'     => ['assets/js/admin-live.js'],
            'live'        => Live::meta(Live::memberSurface($director), 'page'),
        ], 'admin/members/show');

        assert_contains('data-live-region="member-royalty"', $html);
        assert_contains('Quota progress', $html);
        assert_contains('Paid all time', $html);

        assert_same($ledgerBefore, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(), 'no wallet movement');
        assert_same($walletBefore, (string) db()->query(
            "SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users"
        )->fetchColumn());
    },

    /* ==========================================================
     *  P19G-T10 — the admin panel agrees with the engine on a ₱0 quota
     *
     *  Companion to P19G-T9: the member panel is rendered from the
     *  same engine read, and its progress bar must not say "not met
     *  yet" at 100% when the stored quota is 0 (which the engine
     *  treats as met).
     * ========================================================== */

    'P19G-T10 a zero quota reads as met on the admin member panel' => function () use (
        $setThresholds,
        $directorTree
    ): void {
        $setThresholds(['leader' => 1, 'supervisor' => 2, 'manager' => 2, 'director' => 2]);

        foreach ([
            'royalty_enabled'          => '1',
            'royalty_pay_enabled'      => '1',
            'royalty_director_quota'   => '0',
        ] as $key => $value) {
            setting_set($key, (string) $value);
        }

        setting_cache_clear();

        $director  = $directorTree()['director'];
        $productId = (int) db()->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
        $orderId   = test_order($director, [['product_id' => $productId]], 'paid', [
            'paid_at'  => date('Y-m-d H:i:s'),
            'subtotal' => '10000.00',
            'total'    => '10000.00',
        ]);

        db()->prepare('UPDATE shop_order_items SET line_total = ?, unit_price = ? WHERE order_id = ?')
            ->execute(['10000.00', '10000.00', $orderId]);

        test_signed_in_as(test_user('view_royalty_panel_zero_admin', 'admin'));

        $context  = (new AdminMemberController())->memberContext(Member::find($director));
        $payload  = (new AdminMemberController())->detailFeedPayload(2, '"zero"', $context);
        $fragment = $payload['fragments']['member-royalty'];

        assert_same(0.0, (float) $context['royalty']['quota']);

        /* The source wraps the "met" word onto its own line; normalise
         * the whitespace before matching the sentence. */
        $text = (string) preg_replace('/\s+/u', ' ', $fragment);

        assert_contains('Group sales ' . money(10000.0) . ' of the ' . money(0.0) . ' quota — met.', $text);
        assert_not_contains('not met yet', $text);
        assert_not_contains('not met yet', $fragment);
    },
];
