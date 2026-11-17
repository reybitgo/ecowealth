<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestMemberViewTest — Phase 21F slice S1 (plan §10,
 *  §12.7, §13.8, Appendix C.3/C.6/C.8)
 *
 *  Proves the member's General Harvest tab on /earnings and only reads:
 *
 *      tab           the fifth tab on /earnings: header, stats card,
 *                    ten-block grid, next-block progress, award rows,
 *                    pagination and the member CSV export
 *      guard         a member who has not qualified reads the gate, not
 *                    an empty board (P21F-T6)
 *      scope         every row is the session member's own — ?user_id=
 *                    cannot widen it (P21F-T16)
 *      wallet link   each credited row links to its movement by ref_id
 *                    (P21F-T15)
 *      baseline      a baseline member's levels read Before go-live and
 *                    are excluded from Earned (P21F-T7)
 *      regression    the other four tabs are unchanged (P21F-T9)
 *
 *  Every fixture is grown through the production path
 *  (`GeneralHarvestService::settle()` — the engine's exact call) so no
 *  case writes an award or a wallet movement by hand. Section 21D's suite
 *  owns the money rules; this file owns the visibility rules.
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestMemberView
 * ============================================================== */

$fixtures = require __DIR__ . '/../fixtures/general_harvest.php';

$clean  = $fixtures['clean'];
$seed   = $fixtures['seedMarker'];
$switch = $fixtures['switch'];
$arm    = $fixtures['arm'];
$grow   = $fixtures['grow'];
$qualified = $fixtures['qualified'];
$queue  = $fixtures['queue'];
$append = $fixtures['append'];

$reference = GeneralHarvestLevel::referencePreset();

/** Render the member earnings page for a query string, signed in as $userId. *
 * @param int $userId
 * @param array<string, string> $get
 * @return array{controller:EarningsController, data:array<string, mixed>, html:string}
 */
$earningsPage = static function (int $userId, array $get = []): array {
    $_GET = $get + ['per_page' => '25'];
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?' . http_build_query($_GET);

    test_signed_in_as([
        'id'    => $userId,
        'role'  => 'member',
    ]);

    $controller = new EarningsController();
    $data       = $controller->context($userId);

    ob_start();
    try {
        View::page('member', 'member/earnings', $data + [
            'page_title'  => 'Earnings',
            'current_nav' => 'earnings',
            'page_css'    => [],
            'page_js'     => [],
            'live'        => Live::meta('member.earnings:' . $userId, 'page'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return [
        'controller' => $controller,
        'data'       => $data,
        'html'       => $html,
    ];
};

/** Build a perfect ternary tree of height 2, arm at N = 0, queue 13
 *  members, switch on, settle. Returns the root id. */
$fixtureB = static function () use ($clean, $seed, $switch, $grow, $qualified, $queue, $arm): int {
    $clean();
    $seed();

    $tree = $grow(2, 'fb');
    $members = $qualified($tree);
    $arm();            /* baseline at N = 0, switch off */
    $queue($members);  /* N = 13 */
    $switch(true);     /* enable the stream */
    (new GeneralHarvestService())->settle(13); /* settle all positions */

    return $tree['root'];
};

/** Build a perfect ternary tree of height 2, queue it, arm at N = 13,
 *  grow to N = 40, switch on, settle. Returns the root id. */
$fixtureC = static function () use ($clean, $seed, $switch, $grow, $qualified, $queue, $append, $arm): int {
    $clean();
    $seed();

    $treeB = $grow(2, 'fcb');
    $membersB = $qualified($treeB);
    $queue($membersB); /* N = 13 */
    $arm();             /* baseline at N = 13, switch still off */

    $treeC = $grow(3, 'fcc');
    $membersC = $qualified($treeC);
    $newMembers = array_slice($membersC, 13);
    foreach ($newMembers as $m) {
        $append($m);
    } /* N = 40 */

    $switch(true);      /* enable the stream */
    (new GeneralHarvestService())->settle(27);

    return $treeB['root'];
};

return [

    /* ==========================================================
     *  P21F-T1 — /earnings?ledger=general_harvest for a node holder
     * ========================================================== */

    'P21F-T1 node holder sees position, N, ten blocks and award rows' => function () use (
        $fixtureB, $earningsPage
    ): void {
        $rootId = $fixtureB();

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $data   = $result['data'];
        $html   = $result['html'];

        $status = $data['general_harvest'];
        assert_true($status['queued'], 'the root is queued');
        assert_same(1, $status['position'], 'the root is position #1');
        assert_same(0, $status['seq'], 'the root is node 0');
        assert_same(13, $status['queue_n'], 'N = 13');
        assert_true($status['armed'], 'the board is armed');
        assert_true($status['enabled'], 'the stream is on');
        assert_same('6000.00', $status['credited_amount'], 'root earned 6,000 (levels 1–2)');

        $levels = $status['levels'];
        assert_same(10, count($levels), 'ten blocks are shown');
assert_same('credited', $levels[0]['status'], 'level 1 is credited');
assert_same('credited', $levels[1]['status'], 'level 2 is credited');
        assert_same('2–#4', $levels[0]['from'] . '–#' . $levels[0]['to'], 'level 1 block is #2–#4');
        assert_same('5–#13', $levels[1]['from'] . '–#' . $levels[1]['to'], 'level 2 block is #5–#13');

        assert_contains('Position #1', $html, 'the page shows the position');
        assert_contains('queue at 13', $html, 'the page shows N');
        assert_contains('Level 1', $html, 'level 1 block row');
        assert_contains('Level 2', $html, 'level 2 block row');

        $awards = $data['awards'];
        assert_count(2, $awards, 'two award rows for the root (levels 1–2)');
        assert_same('credited', $awards[0]['status'], 'awards are credited');

        /* P21F-T15: a credited row links to its wallet movement by ref_id. */
        $first = $awards[0];
        assert_same('credited', $first['status'], 'first award is credited');
        assert_not_null($first['ledger_id'], 'the award has a ledger id');
assert_contains(
    'ref_id=' . (int) $first['id'],
    $html,
    'the row links to the wallet movement by ref_id'
);
    },

    /* ==========================================================
     *  P21F-T6 — a member who has not qualified reads the gate
     * ========================================================== */

    'P21F-T6 not qualified member reads the gate, not a board' => function () use (
        $clean, $seed, $switch, $earningsPage
    ): void {
        $clean();
        $seed();
        $switch(true);

        $member = test_user('p21f_t6_not_qualified');
        $id     = (int) $member['id'];

        $result = $earningsPage($id, ['ledger' => 'general_harvest']);
        $data   = $result['data'];
        $html   = $result['html'];

        $status = $data['general_harvest'];
        assert_false($status['queued'], 'the member is not queued');
        assert_null($status['position'], 'no position');
        assert_contains('not qualified yet', $html, 'the gate is shown');
        assert_contains('direct members', $html, 'the gate explains the count');
        assert_not_contains('Level 1', $html, 'no board blocks are shown');
        assert_not_contains('Level 2', $html, 'no board blocks are shown');
    },

    /* ==========================================================
     *  P21F-T7 — baseline member: levels 1–2 Before go-live,
     *  Earned shows only credited levels
     * ========================================================== */

    'P21F-T7 baseline member shows Before go-live and credited-only Earned' => function () use (
        $fixtureC, $earningsPage
    ): void {
        $rootId = $fixtureC();

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $data   = $result['data'];
        $html   = $result['html'];

        $status = $data['general_harvest'];
        assert_true($status['queued'], 'root is queued');
assert_same('8000.00', $status['credited_amount'], 'root earned 8,000 (level 3 only)');
assert_same('6000.00', $status['baseline_earned'], 'root baseline is 6,000 (levels 1–2)');

        $levels = $status['levels'];
assert_same('baseline', $levels[0]['status'], 'level 1 is Before go-live');
assert_same('baseline', $levels[1]['status'], 'level 2 is Before go-live');
assert_same('credited', $levels[2]['status'], 'level 3 is credited');

        assert_contains('Before go-live', $html, 'the card shows Before go-live');
        assert_contains('8,000.00', $html, 'Earned shows only credited level (₱8,000.00)');
    },

    /* ==========================================================
     *  P21F-T8 — member CSV: documented columns, only caller's rows,
     *  ?user_id= cannot widen it
     * ========================================================== */

    'P21F-T8 member CSV has Appendix C.6 columns and caller rows only' => function () use (
        $fixtureB
    ): void {
        $rootId = $fixtureB();

        $_GET = ['ledger' => 'general_harvest'] + ['per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings/export?' . http_build_query($_GET);
        test_signed_in_as(['id' => $rootId, 'role' => 'member']);

        ob_start();
        try {
            $controller = new EarningsController();
            $controller->export([]);
        } finally {
            $csv = (string) ob_get_clean();
        }

        $lines = array_filter(explode("\n", $csv));
        assert_same(
            'Date,Level,Block,Queue at award,Threshold,Amount,Status',
            $lines[0],
            'the CSV header is exactly Appendix C.6'
        );
        $rowCount = count($lines) - 1;
        assert_same(2, $rowCount, 'only the caller\'s rows (root has 2 credited awards)');

        /* ?user_id= cannot widen it — append another member's id and confirm no change. */
        $_GET = ['ledger' => 'general_harvest', 'user_id' => '999999'] + ['per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings/export?' . http_build_query($_GET);

        ob_start();
        try {
            $controller = new EarningsController();
            $controller->export([]);
        } finally {
            $csv2 = (string) ob_get_clean();
        }

        assert_same($csv, $csv2, '?user_id= does not widen the export');
    },

    /* ==========================================================
     *  P21F-T9 — /earnings (the other four tabs): unchanged regression
     * ========================================================== */

    'P21F-T9 the other four tabs are unchanged' => function () use (
        $fixtureB, $earningsPage
    ): void {
        $rootId = $fixtureB();

        /* Default = package tab. */
        $result = $earningsPage($rootId, []);
        $data   = $result['data'];
        $html   = $result['html'];

        assert_same('package', $data['tab'], 'the default tab is still package');
        assert_contains('Package commissions', $html, 'the package tab renders');
        assert_not_contains('General Harvest bonus', $html, 'the package tab does not show General Harvest');

        /* The repeat, royalty and team_harvest tabs still work. */
        assert_same('repeat', $earningsPage($rootId, ['ledger' => 'repeat'])['data']['tab'], 'the repeat tab still renders');
        assert_same('royalty', $earningsPage($rootId, ['ledger' => 'royalty'])['data']['tab'], 'the royalty tab still renders');
        assert_same('team_harvest', $earningsPage($rootId, ['ledger' => 'team_harvest'])['data']['tab'], 'the team_harvest tab still renders');
    },

    /* ==========================================================
     *  P21F-T16 — /earnings?ledger=general_harvest&user_id={other} is ignored
     * ========================================================== */

    'P21F-T16 ?user_id= cannot widen the member statement' => function () use (
        $clean, $seed, $switch, $earningsPage
    ): void {
        $clean();
        $seed();
        $switch(true);

        $member = test_user('p21f_t16_requester');
        $id     = (int) $member['id'];

        $other = test_user('p21f_t16_other');
        $otherId = (int) $other['id'];

        $result = $earningsPage($id, ['ledger' => 'general_harvest', 'user_id' => (string) $otherId]);
        $data   = $result['data'];
        $html   = $result['html'];

        $status = $data['general_harvest'];
        assert_false($status['queued'], 'the requesting member is not queued');
        assert_same(0, count($data['awards']), 'no award rows for the requesting member');
    },

];
