<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyAdminMemberViewTest — Phase 19B (plan §6, §12.2)
 *
 *  Proves the two admin surfaces 19B adds: the Rank column in the
 *  member directory and the compact rank distribution on its stats
 *  strip. The member's own royalty panel is 19D and is not asserted
 *  here; nothing in this file moves money.
 *
 *      column      each row's badge is the rank RoyaltyRank derived
 *      distribution the five exclusive counts plus `ranked`
 *      money       saving the card and rendering the list moves no
 *                  wallet, ledger, period or award row (P19B-T25)
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

        /* The empty state spans the eight columns, not seven. */
        $_GET = ['q' => 'no-such-member-in-any-tree'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?q=no-such-member-in-any-tree';

        $empty = $controller->feedPayload(3, '"etag"', $controller->listContext())['fragments']['member-rows'];

        assert_contains('colspan="8"', $empty);
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
         * the three edits. */
        $posted = [];

        foreach (array_merge($sectionMethod('sectionKeys', 'commissions'), $sectionMethod('sectionToggles', 'commissions')) as $key) {
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
];
