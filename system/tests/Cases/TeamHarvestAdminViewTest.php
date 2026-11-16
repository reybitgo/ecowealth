<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestAdminViewTest — Phase 20E (plan §9, §12.7, §13.8,
 *  Appendix C.2/C.5/C.8)
 *
 *  Proves the four admin surfaces 20E adds, and only reads:
 *
 *      ledger       the fourth tab on /admin/commissions: header, six
 *                   counters, the ten-row distribution, filters, rows,
 *                   pagination and the empty states
 *      directory    /admin/members: the Harvest column, the queue card
 *                   and the ?harvest= filter
 *      member       /admin/members/{id}: the Team Harvest panel — the
 *                   position, the levels, the awards and the baseline
 *      reports      the reports block, the range CSV and the dashboard
 *                   KPI
 *
 *  Every fixture is grown through the production path
 *  (`TeamHarvestService::onQualified()` — the registration hook's exact
 *  call) or reconstructed by the real seed, so no case writes an award,
 *  a queue row or a wallet movement by hand. Section 20D's suite owns
 *  the money rules; this file owns the visibility rules.
 *
 *  Run this group alone with:  php tests/run.php TeamHarvestAdminView
 * ============================================================== */

$appRoot  = dirname(__DIR__, 2);
$fixtures = require __DIR__ . '/../fixtures/harvest_money.php';

$account      = $fixtures['account'];
$cleanFixture = $fixtures['clean'];
$arm          = $fixtures['arm'];
$disarm       = $fixtures['disarm'];
$enable       = $fixtures['enable'];
$grow         = $fixtures['grow'];
$depth        = $fixtures['depth'];
$ladder       = $fixtures['ladder'];
$awards       = $fixtures['awards'];
$count        = $fixtures['count'];

/* --------------------------------------------------------------
 *  Rendering helpers
 * -------------------------------------------------------------- */

/** Render one admin page through the real layout. */
$renderPage = static function (string $template, array $data, string $title, string $nav): string {
    ob_start();

    try {
        View::page('admin', $template, $data + [
            'page_title'  => $title,
            'current_nav' => $nav,
            'page_css'    => [],
            'page_js'     => [],
            'live'        => Live::meta('admin.commissions', 'page'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/**
 * Build the commission ledger context for a query string and render it.
 *
 * @param array<string, string> $get
 * @return array{controller:AdminCommissionController, data:array<string, mixed>, html:string}
 */
$ledgerPage = static function (array $get = []) use ($renderPage): array {
    $_GET = $get + ['per_page' => '25'];
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?' . http_build_query($_GET);

    $controller = new AdminCommissionController();
    $data       = $controller->context();

    return [
        'controller' => $controller,
        'data'       => $data,
        'html'       => $renderPage('admin/commissions/index', $data, 'Commissions', 'admin.commissions'),
    ];
};

/**
 * Build the member directory context for a query string and render it.
 *
 * @param array<string, string> $get
 * @return array{controller:AdminMemberController, data:array<string, mixed>, html:string}
 */
$membersPage = static function (array $get = []) use ($renderPage): array {
    $_GET = $get;
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members'
        . ($get === [] ? '' : '?' . http_build_query($get));

    $controller = new AdminMemberController();
    $data       = $controller->listContext();

    return [
        'controller' => $controller,
        'data'       => $data,
        'html'       => $renderPage('admin/members/index', $data, 'Members', 'admin.members'),
    ];
};

/**
 * The member page's Team Harvest fragment and the rendered page.
 *
 * @return array{panel:string, html:string, data:array<string, mixed>}
 */
$memberPanel = static function (int $userId) use ($renderPage): array {
    $member = Member::find($userId);
    assert_not_null($member);

    $controller = new AdminMemberController();
    $data       = $controller->memberContext($member);
    $payload    = $controller->detailFeedPayload(9, '"admin.members:' . $userId . ':9:x"', $data);

    return [
        'panel' => $payload['fragments']['member-team-harvest'],
        'html'  => $renderPage('admin/members/show', $data, 'Member', 'admin.members'),
        'data'  => $data,
    ];
};

/* --------------------------------------------------------------
 *  Fixtures
 * -------------------------------------------------------------- */

/**
 * Fixture A, grown live: the plan's perfect 3-ary tree (§16.2).
 *
 * 121 accounts; the root holds n = 39, the three children 12, the nine
 * grandchildren 3. The money the engine paid, exactly as plan §16.3
 * pins it: **18 awards, ₱25,000.00, 13 recipients** — the numbers
 * P20E-T3 asserts.
 *
 * @return array{root:int, levels:array<int, list<int>>, accounts:int, leaves:int}
 */
$treeA = static function (string $prefix = 'p20e') use ($cleanFixture, $arm, $grow): array {
    $cleanFixture();
    $arm();

    return $grow(3, $prefix, new TeamHarvestService());
};

/**
 * A smaller live tree (root + 3 + 9, the root holding n = 12): 40
 * accounts, 5 awards and ₱6,000.00. Enough for the directory, the member
 * panel and the reports block, at a third of fixture A's cost.
 *
 * @return array{root:int, levels:array<int, list<int>>, accounts:int, leaves:int}
 */
$treeMid = static function (string $prefix = 'p20e_mid') use ($cleanFixture, $arm, $grow): array {
    $cleanFixture();
    $arm();

    return $grow(2, $prefix, new TeamHarvestService());
};

/**
 * The go-live baseline fixture: root + 12 directs, each direct holding
 * three unqualified leaves, reconstructed by the real seed — so the root
 * holds `n = 12` and `baseline_n = 12` — then evaluated once. Levels 1
 * and 2 are recorded `baseline` (₱3,000.00) and nothing is paid
 * (D-07, P20E-T4/T11).
 *
 * @return array{root:int, directs:list<int>, positions:int}
 */
$treeBaseline = static function (string $prefix = 'p20e_base') use (
    $account,
    $cleanFixture,
    $disarm,
    $enable
): array {
    $cleanFixture();
    $disarm();

    $root  = $account($prefix . '_root');
    $start = strtotime('2026-11-01 08:00:00');
    $minute = 0;

    for ($i = 1; $i <= 12; $i++) {
        $minute++;
        $direct = $account(
            $prefix . '_d' . $i,
            $root,
            'member',
            date('Y-m-d H:i:s', $start + 60 * $minute)
        );

        for ($j = 1; $j <= 3; $j++) {
            $account($prefix . '_l' . $i . '_' . $j, $direct);
        }
    }

    $seed = (new HarvestQueue())->seed();
    assert_true((bool) $seed['ok'], 'the baseline fixture seeds');
    assert_same(13, (int) $seed['inserted'], 'the root and its twelve directs are queued');

    $enable(true);

    $result = (new TeamHarvestService())->evaluate([$root]);
    assert_same(2, (int) $result['baseline'], 'levels 1 and 2 are complete at go-live');
    assert_same(0, (int) $result['paid'], 'a baseline level is never paid');

    return ['root' => $root, 'directs' => [], 'positions' => 13];
};

/** How many SELECT statements the server has run on this session. */
$selects = static function (): int {
    $row = db()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch();

    return (int) ($row['Value'] ?? 0);
};

/** One `<tr>` of the members table, keyed by its live key. */
$memberRow = static function (string $html, int $userId): string {
    $pattern = '/<tr[^>]*data-live-key="' . $userId . '"(?:(?!<\/tr>).)*?<\/tr>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[0] : '';
};

/** One `<tr>` of the ledger's level card carrying `Level <n>`. */
$levelRow = static function (string $html, int $level): string {
    $pattern = '/<tr[^>]*>(?:(?!<\/tr>).)*?Level ' . $level . '<\/td>.*?<\/tr>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[0] : '';
};

return [
    /* ==========================================================
     *  P20E-T1 — the three existing tabs are untouched
     * ========================================================== */

    'P20E-T1 the three existing tabs keep their fragments, bytes and counts' => function () use ($ledgerPage): void {
        test_signed_in_as(test_user('p20e_t1_admin', 'admin'));

        /* The exact fragment sets the three tabs had at p20d-money. */
        $expected = [
            'package' => ['commission-header', 'commission-stats', 'commission-rows', 'commission-pagination'],
            'repeat'  => ['repeat-header', 'repeat-stats', 'repeat-periods', 'repeat-rows', 'repeat-pagination'],
            'royalty' => [
                'commission-royalty-ops', 'commission-royalty-header', 'commission-royalty-stats',
                'commission-royalty-periods', 'commission-royalty-rows', 'commission-royalty-pagination',
            ],
        ];

        foreach ($expected as $tab => $fragments) {
            $page = $ledgerPage($tab === 'package' ? [] : ['ledger' => $tab]);

            assert_same($tab, (string) $page['data']['tab'], $tab . ' still resolves');

            $payload = $page['controller']->feedPayload(4, '"admin.commissions:4:t1"', $page['data']);

            assert_same($fragments, array_keys($payload['fragments']), $tab . ' keeps its regions');
            assert_not_contains('data-live-region="team-harvest-', $page['html'], $tab . ' renders no Team Harvest region');

            foreach ($fragments as $name) {
                assert_contains('data-live-region="' . $name . '"', $payload['fragments'][$name], $name . ' renders');
            }
        }

        /* The package rows and counters are the exact bytes of their
         * partials — the fourth tab does not touch them. */
        $package = $ledgerPage(['per_page' => '25']);
        $payload = $package['controller']->feedPayload(5, '"x"', $package['data']);

        assert_same(
            View::capture('admin/commissions/_rows', [
                'commissions' => $package['data']['commissions'],
                'filters'     => $package['data']['filters'],
            ]),
            $payload['fragments']['commission-rows']
        );
        assert_same(
            View::capture('admin/commissions/_stats', ['stats' => $package['data']['stats']]),
            $payload['fragments']['commission-stats']
        );
        assert_same(
            View::capture('admin/commissions/_pagination', ['pager' => $package['data']['pager']]),
            $payload['fragments']['commission-pagination']
        );
        assert_same(url('admin/commissions'), $package['data']['package_url']);
        assert_same(url('admin/commissions?ledger=repeat'), $package['data']['repeat_url']);
        assert_same(url('admin/commissions'), $package['data']['ledger_url']);

        /* The repeat tab's counters and rows keep their bytes. */
        $repeat  = $ledgerPage(['ledger' => 'repeat']);
        $payload = $repeat['controller']->feedPayload(6, '"x"', $repeat['data']);

        assert_same(
            View::capture('admin/commissions/_repeat_stats', ['stats' => $repeat['data']['stats']]),
            $payload['fragments']['repeat-stats']
        );
        assert_same(
            View::capture('admin/commissions/_repeat_rows', [
                'awards'  => $repeat['data']['awards'],
                'filters' => $repeat['data']['filters'],
            ]),
            $payload['fragments']['repeat-rows']
        );

        /* The Royalty tab's period card keeps its bytes. */
        $royalty = $ledgerPage(['ledger' => 'royalty']);
        $payload = $royalty['controller']->feedPayload(7, '"x"', $royalty['data']);

        assert_same(
            View::capture('admin/commissions/_royalty_periods', [
                'periods'  => $royalty['data']['periods'],
                'selected' => $royalty['data']['filters']['period'],
                'open'     => $royalty['data']['open_period'],
            ]),
            $payload['fragments']['commission-royalty-periods']
        );
        assert_same(
            View::capture('admin/commissions/_royalty_header', [
                'pager'         => $royalty['data']['pager'],
                'period'        => $royalty['data']['filters']['period'],
                'period_status' => $royalty['data']['stats']['period_status'],
                'preview_mode'  => $royalty['data']['preview_mode'],
            ]),
            $payload['fragments']['commission-royalty-header']
        );

        /* The four pills, and the counts of the three old tabs unchanged. */
        $html = $package['html'];

        assert_contains('href="' . url('admin/commissions') . '"', $html);
        assert_contains('href="' . url('admin/commissions?ledger=repeat') . '"', $html);
        assert_contains('href="' . url('admin/commissions?ledger=royalty') . '"', $html);
        assert_contains('href="' . url('admin/commissions?ledger=team_harvest') . '"', $html);

        /* Exactly four pills, and the three old ones are where they were. */
        assert_same(
            1,
            preg_match('/<ul class="nav nav-pills mb-4 commission-tabs"[^>]*>(.*?)<\/ul>/s', $html, $tabs)
        );
        assert_same(4, preg_match_all('/class="nav-link/s', $tabs[1]));
    },

    /* ==========================================================
     *  P20E-T2 — the Team Harvest tab renders
     * ========================================================== */

    'P20E-T2 the fourth tab renders the pill, header, tiles, levels, filters and rows' => function () use ($treeA, $ledgerPage, $awards): void {
        test_signed_in_as(test_user('p20e_t2_admin', 'admin'));

        $tree = $treeA('p20e_t2');

        $page = $ledgerPage(['ledger' => 'team_harvest', 'per_page' => '25']);
        $html = $page['html'];
        $data = $page['data'];

        assert_same('team_harvest', (string) $data['tab']);
        assert_same(18, $page['data']['pager']->total, 'the fixture paid eighteen levels');

        /* The pill is the active one. */
        assert_same(
            1,
            preg_match('/href="[^"]*ledger=team_harvest"[^>]*class="nav-link([^"]*)"/s', $html, $pill),
            'the Team Harvest pill renders'
        );
        assert_contains('active', $pill[1], 'and it is the active one');

        /* The plan’s copy (Appendix C.2). */
        assert_contains('<h4 class="mb-1">Team Harvest bonus</h4>', $html);
        assert_contains('One row per level a member completed', $html);
        assert_contains('were already complete when the plan started: they are recorded, not paid.', $html);

        /* The five regions. */
        $payload = $page['controller']->feedPayload(8, '"x"', $data);

        foreach ([
            'team-harvest-header',
            'team-harvest-stats',
            'team-harvest-levels',
            'team-harvest-rows',
            'team-harvest-pagination',
        ] as $region) {
            assert_contains('data-live-region="' . $region . '"', $html, $region . ' renders');
            assert_contains('data-live-region="' . $region . '"', $payload['fragments'][$region], $region . ' travels');
        }

        /* The columns the plan names. */
        foreach (['Date', 'Member', 'Position', 'Level', 'Teammates', 'Threshold', 'Amount', 'Status', 'Wallet'] as $column) {
            assert_contains('>' . $column . '<', $html, 'the ledger has its ' . $column . ' column');
        }

        /* The fixture's rows: the root holds three, and every one renders. */
        foreach ($awards($tree['root']) as $award) {
            assert_contains('data-live-key="' . (int) $award['id'] . '"', $html);
        }

        assert_contains('Level 1', $html);
        assert_contains(money(1000.0), $html);
        assert_contains(money(4000.0), $html);

        /* The filter form is the tab's own. */
        foreach (['name="ledger" value="team_harvest"', 'name="q"', 'name="level"', 'name="status"', 'name="from"', 'name="to"'] as $field) {
            assert_contains($field, $html, 'the filter form carries ' . $field);
        }

        /* The feed's fragments are the tab's own five regions. */
        $payload = $page['controller']->feedPayload(9, '"admin.commissions:9:t2"', $data);

        assert_same(
            [
                'team-harvest-header',
                'team-harvest-stats',
                'team-harvest-levels',
                'team-harvest-rows',
                'team-harvest-pagination',
            ],
            array_keys($payload['fragments'])
        );

        assert_contains('data-live-region="team-harvest-rows"', $payload['fragments']['team-harvest-rows']);
        assert_not_contains('commission-filters', $payload['fragments']['team-harvest-rows']);
        assert_contains(money(25000.0), $payload['fragments']['team-harvest-stats']);
        assert_contains('Level 10', $payload['fragments']['team-harvest-levels']);
        assert_contains('Team Harvest ledger', $payload['fragments']['team-harvest-header']);
    },

    /* ==========================================================
     *  P20E-T3 — the tiles on fixture A
     * ========================================================== */

    'P20E-T3 fixture A tiles read 25,000 credited, 18 awards, 13 recipients, 0 baseline, 0 open' => function () use ($treeA, $ledgerPage, $awards): void {
        test_signed_in_as(test_user('p20e_t3_admin', 'admin'));

        $tree = $treeA('p20e_t3');

        $page  = $ledgerPage(['ledger' => 'team_harvest', 'per_page' => '50']);
        $stats = $page['data']['stats'];

        /* The model and the page agree to the centavo. */
        $totals = Report::teamHarvestTotals();

        assert_same(25000.0, $totals['credited_total']);
        assert_same(18, $totals['credited_rows']);
        assert_same(13, $totals['recipients']);
        assert_same(0.0, $totals['baseline_total']);
        assert_same(0, $totals['baseline_rows']);

        assert_same(25000.0, (float) $stats['credited_total']);
        assert_same(18, (int) $stats['credited_rows']);
        assert_same(13, (int) $stats['recipients']);
        assert_same(0.0, (float) $stats['baseline_total']);
        assert_same(0.0, (float) $stats['open_amount']);
        assert_same(0, (int) $stats['open_levels']);

        /* The six tiles are on the page, with those numbers. */
        $statsHtml = $page['controller']->feedPayload(3, '"x"', $page['data'])['fragments']['team-harvest-stats'];

        foreach (['Credited', 'This month', 'Awards', 'Recipients', 'Before go-live', 'Open liability'] as $tile) {
            assert_contains($tile, $statsHtml, $tile . ' tile renders');
        }

        assert_contains(money(25000.0), $statsHtml);
        assert_contains('18 paid level', $statsHtml);
        assert_contains('not yet paid', $statsHtml, 'the open liability is labelled');

        /* The engine agrees: nothing is still open. */
        $open = (new TeamHarvestService())->openLiability();

        assert_same(0.0, $open['amount']);
        assert_same(0, $open['levels']);

        /* And the ladder was paid exactly (plan §16.3). */
        assert_same(7000.0, (float) array_sum(array_column($awards($tree['root']), 'amount')));
        assert_same(
            25000.0,
            (float) db()->query(
                "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger WHERE ref_type = 'team_harvest'"
            )->fetchColumn()
        );
    },

    /* ==========================================================
     *  P20E-T4 — baseline rows never enter a paid total
     * ========================================================== */

    'P20E-T4 baseline rows are counted in their own tile, never in Credited' => function () use ($treeBaseline, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t4_admin', 'admin'));

        $tree  = $treeBaseline('p20e_t4');
        $page  = $ledgerPage(['ledger' => 'team_harvest', 'per_page' => '25']);
        $stats = $page['data']['stats'];

        assert_same(0.0, (float) $stats['credited_total'], 'a baseline level is not paid money');
        assert_same(0, (int) $stats['credited_rows']);
        assert_same(0, (int) $stats['recipients']);
        assert_same(3000.0, (float) $stats['baseline_total'], 'levels 1 and 2 are worth 3,000 at go-live');
        assert_same(2, (int) $stats['baseline_rows']);
        assert_same(2, (int) $stats['awards']);
        assert_same(2, $page['data']['pager']->total);

        $statsHtml = $page['controller']->feedPayload(4, '"x"', $page['data'])['fragments']['team-harvest-stats'];

        assert_contains(money(3000.0), $statsHtml, 'the Baseline tile carries the amount');
        assert_contains('2 levels recorded, never paid', $statsHtml);

        /* The two rows are badged Before go-live and point at no wallet. */
        $rows = $page['controller']->feedPayload(5, '"x"', $page['data'])['fragments']['team-harvest-rows'];

        assert_same(2, substr_count($rows, 'Before go-live'));
        assert_contains('not paid', $rows);
        assert_not_contains(url('admin/wallets/' . $tree['root']), $rows, 'a baseline row has no wallet movement');

        /* The model agrees. */
        $totals = TeamHarvestAward::totals();

        assert_same(0.0, (float) $totals['credited_total']);
        assert_same(3000.0, (float) $totals['baseline_total']);
        assert_same(2, (int) $totals['baseline_rows']);
    },

    /* ==========================================================
     *  P20E-T5 — filters fall back and intersect
     * ========================================================== */

    'P20E-T5 the ledger filters level, status, dates and search, and fall back silently' => function () use ($treeA, $treeBaseline, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t5_admin', 'admin'));

        $tree = $treeA('p20e_t5');

        /* Unfiltered: eighteen rows. */
        $all = $ledgerPage(['ledger' => 'team_harvest', 'per_page' => '50']);
        assert_same(18, $all['data']['pager']->total);

        /* Level 3: the root's 4,000 only. */
        $level3 = $ledgerPage(['ledger' => 'team_harvest', 'level' => '3', 'per_page' => '50']);
        assert_same(1, $level3['data']['pager']->total);
        assert_same(3, (int) $level3['data']['awards'][0]['level']);
        assert_same('4000.00', number_format((float) $level3['data']['awards'][0]['amount'], 2, '.', ''));
        assert_same((int) $tree['root'], (int) $level3['data']['awards'][0]['user_id']);

        /* Search: the root's three rows. */
        $search = $ledgerPage(['ledger' => 'team_harvest', 'q' => 'p20e_t5_root', 'per_page' => '50']);
        assert_same(3, $search['data']['pager']->total);

        /* Two filters intersect: level 2 and the root. */
        $both = $ledgerPage(['ledger' => 'team_harvest', 'level' => '2', 'q' => 'p20e_t5_root', 'per_page' => '50']);
        assert_same(1, $both['data']['pager']->total);
        assert_same(2, (int) $both['data']['awards'][0]['level']);

        /* Dates: today holds every decision; tomorrow holds none. */
        $today = $ledgerPage(['ledger' => 'team_harvest', 'from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'per_page' => '50']);
        assert_same(18, $today['data']['pager']->total);

        $future = $ledgerPage(['ledger' => 'team_harvest', 'from' => date('Y-m-d', strtotime('+1 day')), 'per_page' => '50']);
        assert_same(0, $future['data']['pager']->total);

        /* A bad value means "no filter", never an error. */
        $badLevel = $ledgerPage(['ledger' => 'team_harvest', 'level' => '99', 'per_page' => '50']);
        assert_same('', (string) $badLevel['data']['filters']['level'], 'an unknown level is dropped');
        assert_same(18, $badLevel['data']['pager']->total, 'and filters nothing out');

        $wordLevel = $ledgerPage(['ledger' => 'team_harvest', 'level' => 'three', 'per_page' => '50']);
        assert_same('', (string) $wordLevel['data']['filters']['level']);
        assert_same(18, $wordLevel['data']['pager']->total);

        $badStatus = $ledgerPage(['ledger' => 'team_harvest', 'status' => 'voided', 'per_page' => '50']);
        assert_same('', (string) $badStatus['data']['filters']['status']);
        assert_same(18, $badStatus['data']['pager']->total);

        $badDate = $ledgerPage(['ledger' => 'team_harvest', 'from' => '31-12-2026', 'to' => 'yesterday', 'per_page' => '50']);
        assert_same('', (string) $badDate['data']['filters']['from']);
        assert_same('', (string) $badDate['data']['filters']['to']);
        assert_same(18, $badDate['data']['pager']->total);

        /* Status baseline needs the go-live fixture, which replaces the
         * tree (and cascades the awards with it). */
        $treeBaseline('p20e_t5b');

        $baseline = $ledgerPage(['ledger' => 'team_harvest', 'status' => 'baseline', 'per_page' => '25']);

        assert_same(2, $baseline['data']['pager']->total);
        assert_same(['baseline'], array_values(array_unique(array_column($baseline['data']['awards'], 'status'))));

        /* Status credited on the same ledger is empty: no level paid. */
        $credited = $ledgerPage(['ledger' => 'team_harvest', 'status' => 'credited', 'per_page' => '25']);
        assert_same(0, $credited['data']['pager']->total);
    },

    /* ==========================================================
     *  P20E-T6 — the level distribution is always ten rows
     * ========================================================== */

    'P20E-T6 the level card lists ten levels, marks depth and zero amounts' => function () use ($treeA, $depth, $ladder, $levelRow, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t6_admin', 'admin'));

        $treeA('p20e_t6');

        /* The depth decides what may pay; a zero amount is a switch. */
        $depth(2);
        $ladder([2 => 0.0]);

        $page   = $ledgerPage(['ledger' => 'team_harvest', 'per_page' => '25']);
        $levels = $page['data']['levels'];
        $html   = $page['controller']->feedPayload(6, '"x"', $page['data'])['fragments']['team-harvest-levels'];

        assert_same(10, count($levels), 'always ten rows');
        assert_same(range(1, 10), array_column($levels, 'level'));

        /* Amounts come from the configured ladder, thresholds from the
         * structural arithmetic — no view computes its own. */
        assert_same(1000.0, (float) $levels[1]['amount']);
        assert_same(3500000.0, (float) $levels[10]['amount']);
        assert_same(3, TeamHarvestLevel::threshold(1));
        assert_same(12, TeamHarvestLevel::threshold(2));
        assert_same(39, TeamHarvestLevel::threshold(3));
        assert_same(88572, TeamHarvestLevel::threshold(10));

        /* Counts come from the awards: the fixture paid level 1 thirteen
         * times, level 2 four times and level 3 once. */
        assert_same(13, (int) $levels[1]['credited']);
        assert_same(4, (int) $levels[2]['credited']);
        assert_same(1, (int) $levels[3]['credited']);
        assert_same(13000.0, (float) $levels[1]['credited_total']);

        assert_same(10, (int) preg_match_all('/Level \d+<\/td>/', $html), 'the card’s body has ten rows');

        /* Level 3 is complete but above the configured depth: not paid. */
        assert_contains('above the configured depth of 2', $levelRow($html, 3));
        assert_contains('Not paid', $levelRow($html, 3));

        /* Level 2 has no amount configured: complete or not, it cannot pay. */
        assert_contains('No amount', $levelRow($html, 2));
        assert_contains('a zero amount is a switch', $levelRow($html, 2));

        /* Level 1 pays. */
        assert_contains('Pays', $levelRow($html, 1));

        /* The page says how many levels pay. */
        assert_contains('Levels that pay: 2', $html);
    },

    /* ==========================================================
     *  P20E-T7 — the directory's position column and queue card
     * ========================================================== */

    'P20E-T7 the Harvest column reads the stored position and the queue card counts it' => function () use ($treeMid, $memberRow, $membersPage): void {
        test_signed_in_as(test_user('p20e_t7_admin', 'admin'));

        $tree     = $treeMid('p20e_t7');
        $position = (int) (new HarvestQueue())->positionOf($tree['root']);

        assert_true($position > 0, 'the root holds a position');

        /* The queued member's row prints #N. */
        $page = $membersPage(['q' => 'p20e_t7_root', 'per_page' => '25']);

        assert_true($page['data']['pager']->total >= 1);
        assert_contains('>Harvest<', $page['html'], 'the column exists');
        assert_contains('#' . $position, $memberRow($page['html'], $tree['root']));

        /* The row carries the position in the same query, no N+1. */
        $rows = Member::adminList(Member::normaliseFilters(['q' => 'p20e_t7_root']), new Paginator(25, 25, 1));
        $own  = [];

        foreach ($rows as $row) {
            if ((int) $row['id'] === $tree['root']) {
                $own = $row;
            }
        }

        assert_same($position, (int) $own['harvest_position']);
        assert_same(12, (int) $own['harvest_teammates']);

        /* An unqualified leaf prints nothing but a dash. */
        $leaf = db()->query(
            "SELECT id FROM users WHERE username LIKE 'p20e\\_t7\\_x%' LIMIT 1"
        )->fetchColumn();

        assert_true($leaf !== false, 'the fixture has leaves');

        $leafPage = $membersPage(['per_page' => '100']);
        $leafRow  = $memberRow($leafPage['html'], (int) $leaf);

        assert_true($leafRow !== '', 'the leaf renders');
        assert_contains('Not in the Team Harvest queue', $leafRow);

        /* The queue card: queued, next position, open liability. */
        $card   = $leafPage['data']['harvest'];
        $region = $leafPage['controller']->feedPayload(7, '"x"', $leafPage['data'])['fragments']['member-harvest'];

        assert_not_null($card);
        assert_same(13, (int) $card['queued']);
        assert_same($position + 13, (int) $card['next'], 'the sequence hands out the next position');
        assert_same(12, (int) $card['deepest']);
        assert_same(0.0, (float) $card['open']['amount'], 'every completed level is paid');

        assert_contains('Team Harvest queue', $region);
        assert_contains('Next position', $region);
        assert_contains('#' . (int) $card['next'], $region);
        assert_contains('Open liability', $region);
        assert_contains('not yet paid', $region);
        assert_contains('@p20e_t7_root', $region, 'the card lists the positions');
        assert_contains('#' . $position, $region);
    },

    /* ==========================================================
     *  P20E-T8 — the harvest filter
     * ========================================================== */

    'P20E-T8 the harvest filter pages, combines with status and never leaks a member' => function () use ($treeMid, $membersPage): void {
        test_signed_in_as(test_user('p20e_t8_admin', 'admin'));

        $tree = $treeMid('p20e_t8');

        /* One queued member is pending, so the two filters can intersect. */
        db()->prepare("UPDATE users SET member_status = 'pending' WHERE id = ?")
            ->execute([$tree['levels'][2][0]]);

        $yes = $membersPage(['harvest' => 'yes', 'per_page' => '5']);

        assert_same('yes', (string) $yes['data']['filters']['harvest']);
        assert_same(13, $yes['data']['pager']->total, 'only the queued members');
        assert_same(5, count($yes['data']['members']));
        assert_same(3, $yes['data']['pager']->pages, 'the filter survives pagination');

        foreach ($yes['data']['members'] as $row) {
            assert_not_null($row['harvest_position'], '@' . $row['username'] . ' is queued');
        }

        /* Page two keeps the filter. */
        $second = $membersPage(['harvest' => 'yes', 'per_page' => '5', 'page' => '2']);

        assert_same('yes', (string) $second['data']['filters']['harvest']);
        assert_same(2, $second['data']['pager']->page);
        assert_same(5, count($second['data']['members']));

        /* No: everyone else, and none of them carries a position. */
        $no = $membersPage(['harvest' => 'no', 'per_page' => '100']);

        assert_same(27, $no['data']['pager']->total, 'the fixture has forty accounts and thirteen are queued');

        foreach ($no['data']['members'] as $row) {
            assert_null($row['harvest_position'], '@' . $row['username'] . ' is not queued');
        }

        /* Combined with status: thirteen queued, one of them pending. */
        $active = $membersPage(['harvest' => 'yes', 'status' => 'active', 'per_page' => '25']);
        assert_same(12, $active['data']['pager']->total);

        $pending = $membersPage(['harvest' => 'yes', 'status' => 'pending', 'per_page' => '25']);
        assert_same(1, $pending['data']['pager']->total);
        assert_same((int) $tree['levels'][2][0], (int) $pending['data']['members'][0]['id']);

        /* A value outside yes/no means "no filter". */
        $weird = $membersPage(['harvest' => 'maybe', 'per_page' => '100']);

        assert_same('', (string) $weird['data']['filters']['harvest']);
        assert_same(40, $weird['data']['pager']->total);

        /* The filter is in the form. */
        $filters = $weird['controller']->feedPayload(8, '"x"', $weird['data'])['fragments']['member-filters'];

        assert_contains('name="harvest"', $filters);
        assert_contains('>In the Harvest queue<', $filters);
        assert_contains('>Not qualified<', $filters);
    },

    /* ==========================================================
     *  P20E-T9 — the queued member's panel
     * ========================================================== */

    'P20E-T9 a queued member panel shows the position, the progress and the awards' => function () use ($treeMid, $memberPanel): void {
        test_signed_in_as(test_user('p20e_t9_admin', 'admin'));

        $tree  = $treeMid('p20e_t9');
        $panel = $memberPanel($tree['root']);
        $html  = $panel['panel'];

        $position = (int) (new HarvestQueue())->positionOf($tree['root']);

        assert_contains('data-live-region="member-team-harvest"', $html);
        assert_contains('Position #' . $position, $html, 'the panel names the position');
        assert_contains('Qualified', $html);

        /* Teammates, the paid levels and the money. */
        $status = $panel['data']['team_harvest'];

        assert_same(12, (int) $status['teammates']);
        assert_same(2, (int) $status['credited_levels']);
        assert_contains('Levels paid', $html);
        assert_contains(money(3000.0), $html, 'the root earned levels 1 and 2');

        /* The next level and what is left. */
        assert_contains('Level 3', $html);
        assert_contains('0 of 27 slots', $html);
        assert_contains('27 teammates remaining', $html);
        assert_contains(money(4000.0), $html);

        /* The baseline state is stated, not implied. */
        assert_contains('qualified after go-live', $html);
        assert_contains('no level of theirs was complete before the plan', $html);

        /* The awards table lists the two stored rows, each with its wallet. */
        assert_contains('Teammates at award', $html);
        assert_contains('Threshold', $html);
        assert_same(2, substr_count($html, 'title="Open the wallet movement'));
        assert_contains(url('admin/wallets/' . $tree['root']), $html);

        /* The whole page renders the panel. */
        assert_contains('data-live-region="member-team-harvest"', $panel['html']);

        /* Read-only: the panel wrote no award. */
        assert_same(5, (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());
    },

    /* ==========================================================
     *  P20E-T10 — a member who has not qualified
     * ========================================================== */

    'P20E-T10 an unqualified member sees the gate, not a matrix' => function () use ($account, $cleanFixture, $memberPanel): void {
        test_signed_in_as(test_user('p20e_t10_admin', 'admin'));

        $cleanFixture();

        $sponsor = $account('p20e_t10_sponsor');
        $account('p20e_t10_d1', $sponsor);
        $account('p20e_t10_d2', $sponsor);

        $panel = $memberPanel($sponsor);
        $html  = $panel['panel'];

        assert_contains('Not qualified', $html);
        assert_contains('Not qualified: 2 of 3 direct members.', $html);
        assert_contains('Direct members', $html);
        assert_contains('of 3', $html);

        /* No matrix and no awards table for a member with no position. */
        assert_not_contains('Teammates at award', $html);
        assert_not_contains('Position #', $html);
        assert_not_contains('Levels paid', $html);

        /* The panel still says where the gate is. */
        assert_contains('enter the queue when their third direct member registers', $html);
    },

    /* ==========================================================
     *  P20E-T11 — a baseline member
     * ========================================================== */

    'P20E-T11 a baseline member marks levels 1 and 2 Before go-live with no wallet link' => function () use ($treeBaseline, $memberPanel): void {
        test_signed_in_as(test_user('p20e_t11_admin', 'admin'));

        $tree  = $treeBaseline('p20e_t11');
        $panel = $memberPanel($tree['root']);
        $html  = $panel['panel'];

        assert_contains('Position #' . (int) (new HarvestQueue())->positionOf($tree['root']), $html);
        assert_contains('At go-live this member already held', $html);
        assert_contains('<strong>12 teammates</strong>', $html);
        assert_contains('recorded as', $html);

        /* Both stored rows are baseline and carry their amounts; neither
         * has a wallet link, because nothing was credited. */
        assert_true(substr_count($html, 'Before go-live') >= 2);
        assert_contains(money(1000.0), $html);
        assert_contains(money(2000.0), $html);
        assert_same(0, substr_count($html, 'title="Open the wallet movement'));
        assert_not_contains(url('admin/wallets/' . $tree['root']), $html);
        assert_same(0.0, (new WalletService())->withdrawable($tree['root']));

        /* The paid total is zero. */
        assert_same(0.0, (float) $panel['data']['team_harvest']['paid_amount']);
        assert_same(2, (int) $panel['data']['team_harvest']['baseline_levels']);
    },

    /* ==========================================================
     *  P20E-T12 — the ledger CSV
     * ========================================================== */

    'P20E-T12 the ledger CSV is the plan C.5 columns and audits one export' => function () use ($treeA, $ledgerPage): void {
        $admin = test_user('p20e_t12_admin', 'admin');
        test_signed_in_as($admin);

        $tree = $treeA('p20e_t12');

        $_GET = ['ledger' => 'team_harvest', 'level' => '3'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions/export?ledger=team_harvest&level=3';

        $controller = new AdminCommissionController();
        $export     = $controller->teamHarvestExportPayload();

        assert_same('3', (string) $export['filters']['level']);
        assert_same(1, count($export['rows']), 'one level-3 award in the fixture');

        /* The header is plan Appendix C.5, verbatim. */
        assert_same(
            [
                'Award id', 'Credited at', 'Member', 'Position', 'Level',
                'Teammates (n)', 'Threshold', 'Amount', 'Status', 'Wallet reference',
            ],
            ReportService::teamHarvestCsvHeader()
        );

        $row = ReportService::teamHarvestColumns($export['rows'][0]);

        assert_same(10, count($row));
        assert_same((string) (int) $export['rows'][0]['id'], $row[0]);
        assert_same('p20e_t12_root', $row[2]);
        assert_same((string) (int) (new HarvestQueue())->positionOf($tree['root']), $row[3]);
        assert_same('3', $row[4]);
        assert_same('39', $row[5], 'the block was full at 39 teammates');
        assert_same('39', $row[6]);
        assert_same('4000.00', $row[7]);
        assert_same('Paid', $row[8]);
        assert_same((string) (int) $export['rows'][0]['ledger_id'], $row[9], 'the wallet reference is the ledger id');

        /* One audit row, with the filters and the count. */
        $audit = db()->query(
            "SELECT actor_id, actor_role, subject_type, meta_json
               FROM audit_log WHERE action = 'team_harvest.export' ORDER BY id DESC LIMIT 1"
        )->fetch();

        assert_true($audit !== false, 'the export is audited');
        assert_same((int) $admin['id'], (int) $audit['actor_id']);
        assert_same('admin', (string) $audit['actor_role']);
        assert_same('team_harvest_award', (string) $audit['subject_type']);

        $meta = json_decode((string) $audit['meta_json'], true);

        assert_same('team_harvest', (string) ($meta['tab'] ?? ''));
        assert_same('3', (string) ($meta['filters']['level'] ?? ''));
        assert_same(1, (int) ($meta['rows'] ?? 0));

        /* The screen's filter and the export agree. */
        $page = $ledgerPage(['ledger' => 'team_harvest', 'level' => '3']);

        assert_same(1, $page['data']['pager']->total);
    },

    /* ==========================================================
     *  P20E-T13 — the reports block and the dashboard KPI
     * ========================================================== */

    'P20E-T13 the reports cards and the dashboard KPI read the same numbers' => function () use ($treeMid, $renderPage, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t13_admin', 'admin'));

        $treeMid('p20e_t13');

        $range = ReportService::range(date('Y-m-d'), date('Y-m-d'));
        $page  = (new ReportService())->page($range);

        /* The window's counters. */
        assert_same(6000.0, (float) $page['team_harvest']['credited']);
        assert_same(5, (int) $page['team_harvest']['count']);
        assert_same(4, (int) $page['team_harvest']['recipients']);
        assert_same(0.0, (float) $page['team_harvest']['baseline']);
        assert_same(0.0, (float) $page['team_harvest_open']['amount']);

        /* The ten-level ladder, windowed. */
        assert_same(10, count($page['team_harvest_levels']));
        assert_same(4, (int) $page['team_harvest_levels'][0]['credited']);
        assert_same(1, (int) $page['team_harvest_levels'][1]['credited']);

        /* The queue block. */
        assert_same(13, (int) $page['team_harvest_queue']['queued']);
        assert_true((bool) $page['team_harvest_queue']['seeded']);
        assert_same(5, count($page['team_harvest_queue']['rows']), 'the card shows the first five positions');

        /* The months: today's decisions are one calendar month. */
        assert_same(date('Y-m'), (string) $page['team_harvest_months'][0]['month']);

        /* The page renders the block. */
        $html = $renderPage('admin/reports/index', $page + [
            'export_types' => ReportService::EXPORTS,
            'export_url'   => url('admin/reports/export'),
            'canonical'    => url('admin/reports'),
        ], 'Reports', 'admin.reports');

        assert_contains('data-live-region="report-team-harvest"', $html);
        assert_contains('Team Harvest bonus', $html);
        assert_contains('The queue', $html);
        assert_contains('The ladder in this window', $html);
        assert_contains('By month', $html);
        assert_contains(money(6000.0), $html);
        assert_contains('type=team_harvest', $html, 'the export is offered');

        /* The dashboard KPI reads the last 30 days. */
        $dashboard = (new ReportService())->dashboard();

        assert_true(isset($dashboard['team_harvest']));
        assert_same(6000.0, (float) $dashboard['team_harvest']['credited'], 'the fixture was paid today');
        assert_same(5, (int) $dashboard['team_harvest']['count']);
        assert_same(13, (int) $dashboard['team_harvest']['queued']);
        assert_same(0.0, (float) $dashboard['team_harvest']['open']);

        /* And the ledger tab agrees, tile for tile. */
        $ledger = $ledgerPage(['ledger' => 'team_harvest']);

        assert_same(6000.0, (float) $ledger['data']['stats']['credited_total']);
        assert_same(5, (int) $ledger['data']['stats']['credited_rows']);
        assert_same(4, (int) $ledger['data']['stats']['recipients']);
    },

    /* ==========================================================
     *  P20E-T14 — the report CSV carries the queue block
     * ========================================================== */

    'P20E-T14 the range CSV carries the award rows and a queue summary block' => function () use ($treeMid): void {
        test_signed_in_as(test_user('p20e_t14_admin', 'admin'));

        $treeMid('p20e_t14');

        $range  = ReportService::range(date('Y-m-d'), date('Y-m-d'));
        $export = (new ReportService())->export('team_harvest', $range);

        assert_same('ecowealth_team_harvest_' . $range['from'] . '_' . $range['to'] . '.csv', $export['filename']);

        /* The header is the ledger's, with the scope discriminator. */
        assert_same(array_merge(['Scope'], ReportService::teamHarvestCsvHeader()), $export['header']);

        /* Five award rows and one queue row. */
        assert_same(6, count($export['rows']));

        $scope = array_column($export['rows'], 0);

        assert_same('Award', $scope[0]);
        assert_same(5, count(array_keys($scope, 'Award', true)));
        assert_same('Queue', end($scope), 'the queue block closes the file');

        foreach ($export['rows'] as $row) {
            assert_same(count($export['header']), count($row), 'every row is the full width');
        }

        /* The queue row carries the state, not a fake award. */
        $queueRow = $export['rows'][5];

        assert_same('', $queueRow[1]);
        assert_same('13 queued', $queueRow[3]);
        assert_same('Seeded', $queueRow[9]);
        assert_same('0.00', $queueRow[8], 'the open liability is zero on this fixture');

        /* The award rows are the ledger's own members. */
        $members = array_column(array_slice($export['rows'], 0, 5), 3);
        assert_true(in_array('p20e_t14_root', $members, true), 'the root is in the file');
    },

    /* ==========================================================
     *  P20E-T15 — the live feed
     * ========================================================== */

    'P20E-T15 an award bumps the ledger and the dashboard and leaves the other tabs alone' => function () use ($cleanFixture, $arm, $grow, $enable, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t15_admin', 'admin'));

        $cleanFixture();
        $arm('2026-11-01 00:00:00', 3, false);

        /* The queue grows through the hook; the switch is off, so nothing
         * is paid yet. */
        $tree = $grow(1, 'p20e_t15', null);

        assert_same(3, (new HarvestQueue())->teammatesOf($tree['root']));
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());

        /* The other tabs' fragments before the award. */
        $before = [];

        foreach (['package' => [], 'repeat' => ['ledger' => 'repeat']] as $tab => $get) {
            $page = $ledgerPage($get);
            $before[$tab] = $page['controller']->feedPayload(1, '"x"', $page['data'])['fragments'];
        }

        $royaltyBefore = $ledgerPage(['ledger' => 'royalty']);

        $scope = 'scope:test';

        $commissionRevision = Live::revision('admin.commissions');
        $dashboardRevision  = Live::revision('admin.dashboard');
        $reportsRevision    = Live::revision('admin.reports');
        $etagBefore         = Live::etag('admin.commissions', $commissionRevision, $scope);

        /* Turn the stream on and pay the backlog. */
        $enable(true);

        $result = (new TeamHarvestService())->evaluate([$tree['root']]);

        assert_same(1, (int) $result['paid']);
        assert_same(1000.0, (float) $result['amount']);

        assert_same($commissionRevision + 1, Live::revision('admin.commissions'), 'the ledger surface is bumped');
        assert_same($dashboardRevision + 1, Live::revision('admin.dashboard'), 'the dashboard is bumped');
        assert_same($reportsRevision + 1, Live::revision('admin.reports'), 'and the reports desk');

        $etagAfter = Live::etag('admin.commissions', Live::revision('admin.commissions'), $scope);

        assert_not_same($etagBefore, $etagAfter, 'an open ledger tab revalidates');

        /* The package and repeat tabs are byte-identical after the award. */
        foreach (['package' => [], 'repeat' => ['ledger' => 'repeat']] as $tab => $get) {
            $page  = $ledgerPage($get);
            $after = $page['controller']->feedPayload(1, '"x"', $page['data'])['fragments'];

            assert_same($before[$tab], $after, $tab . ' is untouched by a Team Harvest award');
        }

        /* The Royalty tab keeps its regions and its row count. */
        $royaltyAfter = $ledgerPage(['ledger' => 'royalty']);

        assert_same(
            array_keys($royaltyBefore['controller']->feedPayload(1, '"x"', $royaltyBefore['data'])['fragments']),
            array_keys($royaltyAfter['controller']->feedPayload(1, '"x"', $royaltyAfter['data'])['fragments'])
        );
        assert_same($royaltyBefore['data']['pager']->total, $royaltyAfter['data']['pager']->total);

        /* The Team Harvest tab itself now shows the row. */
        $page = $ledgerPage(['ledger' => 'team_harvest']);

        assert_same(1, $page['data']['pager']->total);
        assert_contains(
            money(1000.0),
            $page['controller']->feedPayload(2, '"x"', $page['data'])['fragments']['team-harvest-stats']
        );
    },

    /* ==========================================================
     *  P20E-T16 / T17 — permission
     * ========================================================== */

    'P20E-T16 and T17 a member session cannot reach the ledger or the directory filter' => function (): void {
        $member = test_user('p20e_t16_member');
        test_signed_in_as($member);

        assert_same('member', (string) ($member['role'] ?? ''));
        assert_false(is_admin(), 'the admin guard refuses a member session');

        $routes = require dirname(__DIR__, 2) . '/app/routes.php';
        $live   = [];

        foreach ($routes as $route) {
            $live[$route[0] . ' ' . $route[1]] = $route[3] ?? 'member';
        }

        foreach ([
            'GET /admin/commissions',
            'GET /admin/commissions/export',
            'GET /admin/members',
            'GET /admin/members/{id}',
            'GET /admin/reports',
            'GET /admin/reports/export',
            'GET /admin',
        ] as $path) {
            assert_same('admin', $live[$path], $path . ' keeps the admin guard');
        }

        /* There is no third staff role: `admin` is the only guard value that
         * reaches these pages, and a member session is never it. */
        $roles = (string) db()->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
        )->fetchColumn();

        assert_contains("'member'", $roles);
        assert_contains("'admin'", $roles);
        assert_not_contains("'staff'", $roles);
    },

    /* ==========================================================
     *  P20E-T18 — the query budget
     * ========================================================== */

    'P20E-T18 the ledger stays inside eight selects and the position adds no N+1' => function () use ($treeMid, $selects, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t18_admin', 'admin'));

        $treeMid('p20e_t18');

        /* The ledger tab's whole context: filters, count, page, tiles, the
         * ten-row ladder, the queue state and the open liability. */
        $_GET = ['ledger' => 'team_harvest', 'per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=team_harvest&per_page=25';

        $controller = new AdminCommissionController();
        $before     = $selects();
        $data       = $controller->teamHarvestContext();
        $used       = $selects() - $before;

        assert_same(5, (int) $data['pager']->total, 'the live tree holds five awards');
        assert_true($used <= 8, 'the ledger page uses ' . $used . ' selects (budget 8)');

        /* The directory's rows path is two selects — the count and the page
         * — and the position rides in the page's own query. */
        $filters = Member::normaliseFilters(['harvest' => 'yes']);

        $before = $selects();
        $rows   = Member::adminList($filters, new Paginator(25, 25, 1));
        $used   = $selects() - $before;

        assert_same(1, $used, 'the rows are one select with the position joined in');
        assert_true(isset($rows[0]['harvest_position']), 'the position comes from that select');
        assert_true(count($rows) <= 25);

        /* The queue card is constant: its cost does not grow with the
         * number of positions. */
        $before = $selects();
        $card   = Report::teamHarvestQueue(10);
        $used   = $selects() - $before;

        assert_true($used <= 4, 'the queue card uses ' . $used . ' selects whatever the queue size');
        assert_same(13, (int) $card['queued']);

        /* The ledger page itself renders without asking for a row at a
         * time: a page of 25 is the same read as a page of 5. */
        $small = $ledgerPage(['ledger' => 'team_harvest', 'per_page' => '5']);
        $large = $ledgerPage(['ledger' => 'team_harvest', 'per_page' => '25']);

        assert_same($small['data']['pager']->total, $large['data']['pager']->total);
        assert_same(5, count($small['data']['awards']));
        assert_same(5, count($large['data']['awards']));
    },

    /* ==========================================================
     *  P20E-T19 — the queue card at 390 px
     * ========================================================== */

    'P20E-T19 the queue card scrolls its table and stacks its tiles' => function () use ($treeMid, $membersPage, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t19_admin', 'admin'));

        $treeMid('p20e_t19');

        $page   = $membersPage(['per_page' => '25']);
        $region = $page['controller']->feedPayload(1, '"x"', $page['data'])['fragments']['member-harvest'];

        /* The position table is the scroll container: no page-level
         * horizontal scroll at 390 px. */
        assert_contains('table-responsive', $region);
        assert_same(1, preg_match('/table-responsive[^>]*>\s*<table/s', $region));

        /* The tiles stack two-up at the narrow breakpoint. */
        assert_contains('col-6 col-md-3', $region);

        /* No fixed pixel width forces the page wider than the viewport. */
        assert_not_contains('style="width:', $region);
        assert_not_contains('min-width', $region);

        /* The ledger's own table is a scroll container too. */
        $ledger = $ledgerPage(['ledger' => 'team_harvest']);

        assert_same(
            1,
            preg_match('/<div class="table-responsive">\s*<table[^>]*class="table table-hover align-middle commission-table/s', $ledger['html'])
        );
    },

    /* ==========================================================
     *  P20E-T20 — unseeded
     * ========================================================== */

    'P20E-T20 an unseeded database says so and names the job' => function () use ($cleanFixture, $membersPage, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t20_admin', 'admin'));

        $cleanFixture();

        assert_null((new HarvestQueue())->baseline());

        $members = $membersPage(['per_page' => '25']);
        $card    = $members['controller']->feedPayload(1, '"x"', $members['data'])['fragments']['member-harvest'];

        assert_contains('Not seeded', $card);
        assert_contains('bin/harvest_queue_seed.php', $card);
        assert_contains('nothing can pay', $card);

        $ledger  = $ledgerPage(['ledger' => 'team_harvest']);
        $payload = $ledger['controller']->feedPayload(1, '"x"', $ledger['data']);

        assert_contains('No level has completed yet', $payload['fragments']['team-harvest-rows']);
        assert_contains('needs 3 qualified teammates', $payload['fragments']['team-harvest-rows']);
        assert_contains('Not seeded', $payload['fragments']['team-harvest-header']);
        assert_contains('nothing can pay until the queue exists', $payload['fragments']['team-harvest-header']);
        assert_same(0, $ledger['data']['pager']->total);
    },

    /* ==========================================================
     *  P20E-T21 — seeded, no awards
     * ========================================================== */

    'P20E-T21 a seeded queue with no awards shows positions and the open liability' => function () use ($cleanFixture, $arm, $grow, $membersPage, $ledgerPage): void {
        test_signed_in_as(test_user('p20e_t21_admin', 'admin'));

        $cleanFixture();
        $arm('2026-11-01 00:00:00', 3, false);

        /* Four positions, four completed levels and no award: the switch is
         * off, so the engine has never written one. */
        $tree = $grow(1, 'p20e_t21', null);

        $position = (int) (new HarvestQueue())->positionOf($tree['root']);

        assert_same(4, (int) db()->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn());

        $members = $membersPage(['per_page' => '25']);
        $card    = $members['controller']->feedPayload(1, '"x"', $members['data'])['fragments']['member-harvest'];

        $harvest = $members['data']['harvest'];

        assert_same(4, (int) $harvest['queued']);
        assert_same($position + 4, (int) $harvest['next']);
        assert_same(1000.0, (float) $harvest['open']['amount'], 'the root completed level 1, unpaid');
        assert_same(1, (int) $harvest['open']['levels']);

        assert_contains('Open liability', $card);
        assert_contains(money(1000.0), $card);
        assert_contains('not yet paid', $card);
        assert_contains('@p20e_t21_root', $card);
        assert_contains('#' . $position, $card);

        /* The ledger is empty and explains the gate, not the switch. */
        $ledger = $ledgerPage(['ledger' => 'team_harvest']);

        assert_same(0, $ledger['data']['pager']->total);
        assert_contains(
            'No level has completed yet',
            $ledger['controller']->feedPayload(1, '"x"', $ledger['data'])['fragments']['team-harvest-rows']
        );
    },

    /* ==========================================================
     *  P20E-T22 — a position with no award
     * ========================================================== */

    'P20E-T22 a member with a position and no awards is in the queue and absent from the ledger' => function () use ($cleanFixture, $arm, $grow, $awards, $membersPage, $ledgerPage, $memberRow): void {
        test_signed_in_as(test_user('p20e_t22_admin', 'admin'));

        $cleanFixture();
        $arm('2026-11-01 00:00:00', 3, false);

        $tree = $grow(1, 'p20e_t22', null);

        $position = (int) (new HarvestQueue())->positionOf($tree['root']);

        assert_true($position > 0);
        assert_same([], $awards($tree['root']), 'the root holds no award');

        /* In the queue card. */
        $members = $membersPage(['per_page' => '25']);
        $card    = $members['controller']->feedPayload(1, '"x"', $members['data'])['fragments']['member-harvest'];

        assert_contains('@p20e_t22_root', $card);
        assert_contains('#' . $position, $card);

        /* In the directory, with its position. */
        $filtered = $membersPage(['per_page' => '100']);

        assert_true($filtered['data']['pager']->total >= 1);
        assert_contains('#' . $position, $memberRow($filtered['html'], $tree['root']));

        /* Absent from the ledger. */
        $ledger = $ledgerPage(['ledger' => 'team_harvest', 'q' => 'p20e_t22_root']);

        assert_same(0, $ledger['data']['pager']->total);
        assert_not_contains('@p20e_t22_root', $ledger['html']);
    },

    /* ==========================================================
     *  P20E-T23 — an empty export
     * ========================================================== */

    'P20E-T23 an export with no rows is a header and one audit row' => function () use ($cleanFixture): void {
        test_signed_in_as(test_user('p20e_t23_admin', 'admin'));

        $cleanFixture();

        $_GET = ['ledger' => 'team_harvest'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions/export?ledger=team_harvest';

        $before = (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'team_harvest.export'")->fetchColumn();

        $export = (new AdminCommissionController())->teamHarvestExportPayload();

        assert_same([], $export['rows'], 'nothing to export');
        assert_same(
            [
                'Award id', 'Credited at', 'Member', 'Position', 'Level',
                'Teammates (n)', 'Threshold', 'Amount', 'Status', 'Wallet reference',
            ],
            ReportService::teamHarvestCsvHeader(),
            'the file still carries its header'
        );

        $after = (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'team_harvest.export'")->fetchColumn();

        assert_same($before + 1, $after, 'the download is audited even when it is empty');
    },

    /* ==========================================================
     *  P20E-T24 — no route and no write
     * ========================================================== */

    'P20E-T24 no new route, no inventory entry and no admin GET moves money' => function () use ($appRoot, $treeMid, $ledgerPage, $membersPage, $memberPanel): void {
        test_signed_in_as(test_user('p20e_t24_admin', 'admin'));

        $tree = $treeMid('p20e_t24');

        /* The routes and the inventory are untouched (D-14). */
        foreach (['/app/routes.php', '/app/routes_inventory.php'] as $file) {
            assert_same(
                0,
                preg_match('/team_harvest|harvest_queue/i', (string) file_get_contents($appRoot . $file)),
                $file . ' has no Team Harvest route'
            );
        }

        $routes = require $appRoot . '/app/routes.php';

        foreach ($routes as $route) {
            [$method, $path, $handler] = $route;
            $class = is_array($handler) ? (string) $handler[0] : '';

            /* The ledger, the reports desk and the directory's two read
             * routes are GET; the member page's status actions stay the
             * POSTs they have always been. */
            if (in_array($class, [AdminCommissionController::class, AdminReportController::class], true)) {
                assert_same('GET', $method, $path . ' is read-only');
            }

            if ($class === AdminMemberController::class) {
                assert_same(
                    in_array($path, ['/admin/members', '/admin/members/{id}'], true) ? 'GET' : 'POST',
                    $method,
                    $path . ' keeps its method'
                );
            }
        }

        /* Rendering every 20E surface writes no award, no queue row, no
         * ledger row and no audit row. */
        $snapshot = static fn (): array => [
            (int) db()->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn(),
            (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'team_harvest'")->fetchColumn(),
            (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn(),
        ];

        $before = $snapshot();

        $ledgerPage(['ledger' => 'team_harvest']);
        $membersPage(['per_page' => '25']);
        $memberPanel($tree['root']);
        (new ReportService())->page(ReportService::range(null, null));
        (new ReportService())->dashboard();

        assert_same($before, $snapshot(), 'the 20E surfaces are read-only');

        /* The phase's own files exist and the stream still has no
         * controller (it adds no route). */
        assert_true(is_file($appRoot . '/app/views/admin/commissions/_team_harvest_rows.php'));
        assert_true(is_file($appRoot . '/app/views/admin/reports/_team_harvest.php'));
        assert_true(is_file($appRoot . '/app/views/admin/members/_harvest.php'));
        assert_false(is_file($appRoot . '/app/Controllers/TeamHarvestController.php'));
    },
];
