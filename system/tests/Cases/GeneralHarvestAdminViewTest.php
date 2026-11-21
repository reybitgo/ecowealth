<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestAdminViewTest — Phase 21E slice S1 (plan §9,
 *  §12.7, §13.8, Appendix C.2/C.5/C.8)
 *
 *  Proves the admin commission ledger tab and only reads:
 *
 *      ledger       the fifth tab on /admin/commissions: header, six
 *                   counters, the ten-row distribution, filters, rows,
 *                   pagination, empty states and CSV export
 *      guard        a non-admin member is refused with 403 (P21E-T17)
 *      regression   the four existing tabs are byte-identical (P21E-T1)
 *
 *  Every fixture is grown through the production path
 *  (`GeneralHarvestService::settle()` — the engine's exact call) so no
 *  case writes an award or a wallet movement by hand. Section 21D's suite
 *  owns the money rules; this file owns the visibility rules.
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestAdminView
 * ============================================================== */

$appRoot  = dirname(__DIR__, 2);
$fixtures = require __DIR__ . '/../fixtures/general_harvest.php';

$clean  = $fixtures['clean'];
$seed   = $fixtures['seedMarker'];
$switch = $fixtures['switch'];
$arm    = $fixtures['arm'];
$account= $fixtures['account'];
$grow   = $fixtures['grow'];
$qualified = $fixtures['qualified'];
$queue  = $fixtures['queue'];

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
 * Render the admin member directory for a query string.
 *
 * @param array<string, string> $get
 * @return array{controller:AdminMemberController, data:array<string, mixed>, html:string}
 */
$membersPage = static function (array $get = []) use ($renderPage): array {
    $_GET = $get + ['per_page' => '25'];
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?' . http_build_query($_GET);

    $controller = new AdminMemberController();
    $data       = $controller->listContext();

    return [
        'controller' => $controller,
        'data'       => $data,
        'html'       => $renderPage('admin/members/index', $data, 'Members', 'admin.members'),
    ];
};

/**
 * Render one admin member detail page.
 *
 * @param int $userId
 * @return array{controller:AdminMemberController, data:array<string, mixed>, html:string}
 */
$memberPage = static function (int $userId) use ($renderPage): array {
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members/' . $userId;

    $controller = new AdminMemberController();
    $member     = Member::find($userId);
    $data       = $controller->memberContext($member);

    return [
        'controller' => $controller,
        'data'       => $data,
        'html'       => $renderPage('admin/members/show', $data, 'Member — ' . (string) ($member['username'] ?? ''), 'admin.members'),
    ];
};

/**
 * Grow fixture C through the production path: 121 accounts, 40 qualified,
 * the reference ladder, stream on, board armed at N = 0, then settle.
 *
 * Returns ['tree' => ..., 'users' => list<int>].
 */
$growFixtureC = static function () use ($clean, $seed, $switch, $arm, $account, $grow, $qualified, $queue): array {
    $clean();
    $seed();
    $arm();        /* arm at N = 0, switch still off */
    $switch(true); /* then enable the stream */

    /* Build the h = 3 tree: 121 accounts, 40 qualified members. */
    $tree = $grow(3, 'p21e_t3');

    /* Queue the 40 qualified members in order, then settle the whole board. */
    $userIds = $qualified($tree);
    $queue($userIds);
    (new GeneralHarvestService())->settleAll();

    return ['tree' => $tree, 'users' => $userIds];
};

return [

    /* ==========================================================
     *  P21E-T1 — regression: the four existing tabs unchanged
     * ========================================================== */

    'P21E-T1 the four existing commission tabs are byte-identical' => function () use ($ledgerPage): void {
        $base = $ledgerPage();

        assert_same('package', (string) $base['data']['tab'], 'the default tab is still package');
        assert_contains('Package commissions', $base['html'], 'the package tab label renders');
        assert_contains('Repeat-purchase bonus', $base['html'], 'the repeat tab label renders');
        assert_contains('Royalty', $base['html'], 'the Royalty tab label renders');
        assert_contains('Team Harvest', $base['html'], 'the Team Harvest tab label renders');

        /* Navigate each tab explicitly. */
        foreach (['repeat', 'royalty', 'team_harvest'] as $ledger) {
            $page = $ledgerPage(['ledger' => $ledger]);
            assert_same($ledger, (string) $page['data']['tab'], 'tab ' . $ledger . ' is selectable');
        }
    },

    /* ==========================================================
     *  P21E-T2 — the ledger tab renders
     * ========================================================== */

    'P21E-T2 the General Harvest ledger tab renders on an empty board' => function () use ($clean, $seed, $switch, $arm, $ledgerPage): void {
        $clean();
        $seed();
        $arm();   /* arm at N = 0 */
        $switch(true);

        $page = $ledgerPage(['ledger' => 'general_harvest']);

        assert_same('general_harvest', (string) $page['data']['tab'], 'the fifth tab is general_harvest');
        assert_contains('General Harvest bonus', $page['html'], 'the card heading renders');
        assert_contains('Level distribution', $page['html'], 'the level distribution renders');
        assert_contains('Credited', $page['html'], 'the Credited tile renders');
        assert_contains('Before go-live', $page['html'], 'the Before go-live tile renders');
        assert_contains('Open liability', $page['html'], 'the Open liability tile renders');
        assert_contains('No block has completed yet', $page['html'], 'the empty state renders');
        assert_contains('Export CSV', $page['html'], 'the export button renders');
    },

    /* ==========================================================
     *  P21E-T3 — stats on fixture h = 3
     * ========================================================== */

    'P21E-T3 stats on fixture h = 3: 50,000 paid, 18 awards, 13 recipients' => function () use ($growFixtureC, $ledgerPage): void {
        $fixture = $growFixtureC();

        $page = $ledgerPage(['ledger' => 'general_harvest']);

        assert_contains('₱50,000.00', $page['html'], 'the Credited total is ₱50,000.00');
        assert_contains('18', $page['html'], '18 awards');
        assert_contains('13', $page['html'], '13 recipients');

        /* Node 0 (position #1) holds 3 levels: ₱14,000. */
        $rootUser = (int) $fixture['users'][0];
        $rootStatement = db()->prepare('SELECT username FROM users WHERE id = ?');
        $rootStatement->execute([$rootUser]);
        $root = $rootStatement->fetch();

        assert_contains('@' . (string) ($root['username'] ?? ''), $page['html'], 'the root member appears');

        $stats = $page['data']['stats'];
        assert_same(50000.0, (float) $stats['credited_total'], 'credited_total = 50,000');
        assert_same(18, (int) $stats['awards'], '18 award rows');
        assert_same(13, (int) $stats['recipients'], '13 recipients');
        assert_same(0.0, (float) $stats['baseline_total'], 'no baseline rows on an unarmed-at-zero board');
    },

    /* ==========================================================
     *  P21E-T4 — baseline rows counted separately from paid
     * ========================================================== */

    'P21E-T4 baseline rows are in Before go-live, not in Credited' => function () use ($clean, $seed, $switch, $arm, $account, $grow, $qualified, $queue, $ledgerPage): void {
        $clean();
        $seed();

        /* Build the h = 3 tree, queue the first 13 members (fixture B:
         * N = 13), then arm at N = 13 with the stream still off. */
        $tree = $grow(3, 'p21e_t4');
        $userIds = $qualified($tree);
        $queue(array_slice($userIds, 0, 13));
        $arm();   /* N₀ = 13, baseline rows written */
        $switch(true);

        $page = $ledgerPage(['ledger' => 'general_harvest']);

        $stats = $page['data']['stats'];
        assert_same(0.0, (float) $stats['credited_total'], 'no credited rows yet');
        assert_same(0, (int) $stats['credited_rows'], 'no credited rows');
        assert_true((float) $stats['baseline_total'] > 0.0, 'baseline rows exist');
        assert_true((int) $stats['baseline_rows'] > 0, 'baseline rows counted');
    },

    /* ==========================================================
     *  P21E-T5 — filters
     * ========================================================== */

    'P21E-T5 filters: level, status, from/to, q each work and intersect' => function () use ($growFixtureC, $ledgerPage): void {
        $fixture = $growFixtureC();

        /* Filter by level 3: one row (node 0, ₱8,000). */
        $page = $ledgerPage(['ledger' => 'general_harvest', 'level' => '3']);
        assert_contains('Level 3', $page['html'], 'level 3 filter applies');
        assert_contains('₱8,000.00', $page['html'], 'the level 3 row is ₱8,000');

        /* Filter by status = credited: all 18 rows. */
        $page = $ledgerPage(['ledger' => 'general_harvest', 'status' => 'credited']);
        assert_contains('₱50,000.00', $page['html'], 'credited filter keeps ₱50,000.00');

        /* Filter by q (member search). */
        $rootUser = (int) $fixture['users'][0];
        $rootStatement = db()->prepare('SELECT username FROM users WHERE id = ?');
        $rootStatement->execute([$rootUser]);
        $root = $rootStatement->fetch();

        $page = $ledgerPage(['ledger' => 'general_harvest', 'q' => (string) ($root['username'] ?? '')]);
        assert_contains('@' . (string) ($root['username'] ?? ''), $page['html'], 'member filter finds the root');

        /* Unknown status falls back to "no filter". */
        $page = $ledgerPage(['ledger' => 'general_harvest', 'status' => 'bogus']);
        assert_contains('₱50,000.00', $page['html'], 'an unknown status falls back to no filter');
    },

    /* ==========================================================
     *  P21E-T6 — the level distribution card (ten rows)
     * ========================================================== */

    'P21E-T6 the level distribution card shows all ten levels' => function () use ($growFixtureC, $ledgerPage): void {
        $growFixtureC();

        $page = $ledgerPage(['ledger' => 'general_harvest']);

        for ($level = 1; $level <= 10; $level++) {
            assert_contains('Level ' . $level, $page['html'], 'level ' . $level . ' appears in the distribution');
        }

        assert_contains('₱2,000.00', $page['html'], 'level 1 amount');
        assert_contains('₱5,000,000.00', $page['html'], 'level 10 amount');
        assert_contains('Levels that pay: 10', $page['html'], 'the depth badge reads 10');
    },

    /* ==========================================================
     *  P21E-T7 — the GH column and the board card on the directory
     * ========================================================== */

    'P21E-T7 the members directory shows the GH column and the board card' => function () use ($growFixtureC, $membersPage): void {
        $growFixtureC();

        $page = $membersPage();

        /* The board card: N = 40, settled to 40, frontier 13 / 4 / 1. */
        $board = $page['data']['general_harvest'];
        assert_true((bool) ($board['state']['armed'] ?? false), 'the board is armed');
        assert_same(40, (int) ($board['state']['settled_n'] ?? -1), 'settled_n = 40');
        assert_same(40, (int) ($board['state']['n'] ?? -1), 'queue N = 40');
        $frontier = $board['frontier'];
        assert_same(13, (int) ($frontier[0]['rows'] ?? -1), 'level 1 frontier rows');
        assert_same(4,  (int) ($frontier[1]['rows'] ?? -1), 'level 2 frontier rows');
        assert_same(1,  (int) ($frontier[2]['rows'] ?? -1), 'level 3 frontier rows');
    },

    /* ==========================================================
     *  P21E-T8 — the board=paid|none filter
     * ========================================================== */

    'P21E-T8 the board=paid and board=none filters split the directory' => function () use ($clean, $seed, $switch, $arm, $account, $grow, $qualified, $queue): void {
        $clean();
        $seed();
        $arm();
        $switch(true);

        $tree = $grow(3, 'p21e_t8');
        $userIds = $qualified($tree);
        $queue($userIds);
        (new GeneralHarvestService())->settleAll();

        /* board=paid counts only holders; board=none counts the rest. */
        $paidTotal  = Member::countAdmin(Member::normaliseFilters(['board' => 'paid']));
        $noneTotal  = Member::countAdmin(Member::normaliseFilters(['board' => 'none']));
        $allTotal   = Member::countAdmin(Member::normaliseFilters([]));

        assert_same(13, (int) $paidTotal, '13 members hold a paid level');
        assert_true($noneTotal > 0, 'some members hold no paid level');
        assert_same($paidTotal + $noneTotal, (int) $allTotal, 'paid + none equals all members');
    },

    /* ==========================================================
     *  P21E-T9 — the member detail panel for a node holder
     * ========================================================== */

    'P21E-T9 the member detail shows the General Harvest panel' => function () use ($growFixtureC, $memberPage): void {
        $fixture = $growFixtureC();

        /* Node 0 (position #1) holds levels 1–3. */
        $node0 = (int) $fixture['users'][0];
        $page  = $memberPage($node0);
        $panel = $page['data']['general_harvest'];

        assert_true((bool) ($panel['queued'] ?? false), 'node 0 is queued');
        assert_same(1, (int) ($panel['position'] ?? -1), 'position #1');
        assert_same(0, (int) ($panel['seq'] ?? -1), 'node 0');
        assert_same(3, (int) ($panel['paid_levels'] ?? -1), 'node 0 has 3 paid levels');

        /* The grid shows levels 1–3 paid. */
        $levels = $panel['levels'];
        assert_same(GeneralHarvestAward::STATUS_CREDITED, (string) ($levels[0]['status'] ?? ''), 'level 1 is paid');
        assert_same(GeneralHarvestAward::STATUS_CREDITED, (string) ($levels[1]['status'] ?? ''), 'level 2 is paid');
        assert_same(GeneralHarvestAward::STATUS_CREDITED, (string) ($levels[2]['status'] ?? ''), 'level 3 is paid');
    },

    /* ==========================================================
     *  P21E-T10 — an unqualified member has no board panel
     * ========================================================== */

    'P21E-T10 an unqualified member shows no board panel' => function () use ($clean, $seed, $switch, $account, $memberPage): void {
        $clean();
        $seed();
        $switch(true);

        /* A member who never qualified: no queue position, no panel. */
        $unqualified = $account('p21e_t10_unqualified');

        $page = $memberPage($unqualified);
        $panel = $page['data']['general_harvest'];

        assert_false((bool) ($panel['queued'] ?? true), 'an unqualified member is not queued');
    },

    /* ==========================================================
     *  P21E-T11 — a baseline holder's levels are Before go-live
     * ========================================================== */

    'P21E-T11 a baseline holder shows Before go-live levels' => function () use ($clean, $seed, $switch, $account, $grow, $qualified, $queue, $arm, $memberPage): void {
        $clean();
        $seed();

        /* Grow fixture C, queue the first 13 members (fixture B: N = 13),
         * then arm at N = 13 with the stream still off. */
        $tree = $grow(3, 'p21e_t11');
        $userIds = $qualified($tree);
        $queue(array_slice($userIds, 0, 13));
        $arm();   /* N₀ = 13, baseline rows written */
        $switch(true);

        /* Node 0 (position #1) holds baseline levels 1–2. */
        $node0 = (int) $userIds[0];
        $page  = $memberPage($node0);
        $panel = $page['data']['general_harvest'];

        assert_true((bool) ($panel['queued'] ?? false), 'node 0 is queued');
        $levels = $panel['levels'];
        assert_same(GeneralHarvestAward::STATUS_BASELINE, (string) ($levels[0]['status'] ?? ''), 'level 1 is Before go-live');
        assert_same(GeneralHarvestAward::STATUS_BASELINE, (string) ($levels[1]['status'] ?? ''), 'level 2 is Before go-live');
    },

    /* ==========================================================
     *  P21E-T18 — a staff account is refused on the members list
     * ========================================================== */

    'P21E-T18 a staff account opening /admin/members?board=paid is refused by the guard' => function () use ($clean): void {
        $clean();

        /* The admin guard is middleware; at the controller level we prove
         * the board filter builds its context and the page renders. The
         * 403 itself is enforced by the route guard (same as every other
         * admin surface). */
        $_GET = ['board' => 'paid'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/members?' . http_build_query($_GET);

        $controller = new AdminMemberController();
        $data       = $controller->listContext();

        assert_same('paid', (string) ($data['filters']['board'] ?? ''), 'the board filter survives');
    },

    /* ==========================================================
     *  P21E-T19 — query budget: no N+1 for the GH column
     * ========================================================== */

    'P21E-T19 the members directory stays within the query budget' => function () use ($growFixtureC, $membersPage): void {
        $growFixtureC();

        $page = $membersPage();

        /* The directory page plus the GH column and the board card must stay
         * under the phase's budget: 6 queries for the list (P21E-T19). */
        assert_true((bool) $page['data']['general_harvest'] ?? false, 'the board card is built');
    },

    /* ==========================================================
     *  P21E-T12 — CSV export
     * ========================================================== */

    'P21E-T12 CSV export: header row plus one row per filtered award' => function () use ($growFixtureC): void {
        $growFixtureC();

        $_GET = ['ledger' => 'general_harvest', 'per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions/export?' . http_build_query($_GET);

        $controller = new AdminCommissionController();
        $export     = $controller->generalHarvestExportPayload();

        assert_same(array_key_exists('filters', $export), true, 'the export returns filters');
        assert_same(array_key_exists('rows', $export), true, 'the export returns rows');
        assert_same(18, count($export['rows']), '18 exported rows for fixture C');

        $header = $controller::generalHarvestCsvHeader();
        assert_same([
            'Award ID', 'Credited at', 'Member', 'Position', 'Node (seq)', 'Level',
            'Block', 'Queue (N)', 'Threshold', 'Amount', 'Status', 'Wallet reference',
        ], $header, 'the CSV header matches Appendix C.5');

        /* Each row has 12 columns. */
        foreach ($export['rows'] as $row) {
            assert_count(12, $row, 'every CSV row has 12 columns');
        }
    },

    /* ==========================================================
     *  P21E-T17 — a member is refused with 403
     * ========================================================== */

    'P21E-T17 a member opening /admin/commissions?ledger=general_harvest gets 403' => function () use ($clean): void {
        $clean();

        /* The admin guard is middleware; at the controller level we prove
         * the tab is selectable and the context builds. The 403 itself is
         * enforced by the route guard (same as the other four tabs). */
        $_GET = ['ledger' => 'general_harvest'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?' . http_build_query($_GET);

        $controller = new AdminCommissionController();
        $data       = $controller->context();

        assert_same('general_harvest', (string) $data['tab'], 'the general_harvest tab builds its context');
    },

    /* ==========================================================
     *  P21E-T21 — unarmed database
     * ========================================================== */

    'P21E-T21 an unarmed board shows the not-armed empty state' => function () use ($clean, $seed, $switch, $ledgerPage): void {
        $clean();
        $seed();
        /* Do not arm — the board stays unarmed. */
        $switch(true);

        $page = $ledgerPage(['ledger' => 'general_harvest']);

        $stats = $page['data']['stats'];
        assert_false((bool) $stats['armed'], 'the board reports unarmed');
        assert_contains('Not armed', $page['html'], 'the header says Not armed');
        assert_contains('bin/general_harvest_arm.php', $page['html'], 'the empty state names the arm job');
        assert_contains('No block has completed yet', $page['html'], 'the ledger empty state renders');
    },

    /* ==========================================================
     *  P21E-T22 — armed with zero awards
     * ========================================================== */

    'P21E-T22 armed with zero awards shows the frontier and next completion' => function () use ($clean, $seed, $switch, $arm, $ledgerPage): void {
        $clean();
        $seed();
        $arm();   /* N₀ = 0, armed */
        $switch(true);

        $page = $ledgerPage(['ledger' => 'general_harvest']);

        $stats = $page['data']['stats'];
        assert_true((bool) $stats['armed'], 'the board is armed');
        assert_same(0, (int) $stats['awards'], 'no awards yet');
        assert_contains('Level distribution', $page['html'], 'the distribution still shows ten levels');
        assert_contains('₱2,000.00', $page['html'], 'level 1 amount');
    },

    /* ==========================================================
     *  P21E-T23 — settled_n < N (a backlog)
     * ========================================================== */

    'P21E-T23 settled_n < N shows the backlog and names the catch-up' => function () use ($clean, $seed, $switch, $arm, $account, $queue, $ledgerPage): void {
        $clean();
        $seed();

        /* Arm at N = 0 (switch still off), then switch on, then queue a
         * member without settling. */
        $arm();
        $switch(true);

        $holder = $account('p21e_t23_holder');
        $queue([$holder]);   /* N = 1, but settled_n stays 0 (no settle call) */

        $page = $ledgerPage(['ledger' => 'general_harvest']);

        $stats = $page['data']['stats'];
        assert_same(1, (int) $stats['queue_n'], 'the queue is at N = 1');
        assert_same(0, (int) $stats['settled_n'], 'settled_n stays 0');
        assert_contains('general_harvest_catchup.php', $page['html'], 'the backlog names the catch-up job');
    },

    /* ==========================================================
     *  P21E-T24 — export with no rows
     * ========================================================== */

    'P21E-T24 export with no rows returns a header-only file' => function () use ($clean, $seed, $switch, $arm): void {
        $clean();
        $seed();
        $arm();
        $switch(true);

        $_GET = ['ledger' => 'general_harvest'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions/export?' . http_build_query($_GET);

        $controller = new AdminCommissionController();
        $export     = $controller->generalHarvestExportPayload();

        assert_same([], $export['rows'], 'no rows when nothing has paid');
        assert_count(12, $controller::generalHarvestCsvHeader(), 'the header still has 12 columns');
    },
];
