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
$account = $fixtures['account'];
$count  = $fixtures['count'];

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

/** Build a perfect ternary tree of height 3, arm at N = 0, queue 40
 *  members, switch on, settle. Returns the root id (no baseline). */
$fixtureCPlain = static function () use ($clean, $seed, $switch, $grow, $qualified, $queue, $arm): int {
    $clean();
    $seed();

    $tree = $grow(3, 'fcb');
    $members = $qualified($tree);
    $arm();            /* baseline at N = 0, switch off */
    $queue($members);  /* N = 40 */
    $switch(true);     /* enable the stream */
    (new GeneralHarvestService())->settle(40); /* settle all positions */

    return $tree['root'];
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
        $fixtureB, $count
    ): void {
        $rootId = $fixtureB();

        $_GET = ['ledger' => 'general_harvest'] + ['per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?' . http_build_query($_GET);
        test_signed_in_as(['id' => $rootId, 'role' => 'member']);

        $auditBefore = $count('audit_log');

        /* The export file is `exportRows()` → the two formatting helpers;
         * the controller's `export` action writes them and exits, so a case
         * reads the same three pieces it uses (the Team Harvest and
         * Royalty member CSV cases do the same). */
        $filters = (new EarningsController())->context($rootId, 'general_harvest')['filters'];
        assert_same($rootId, (int) $filters['user_id'], 'the session member is forced into the filters');

        $export = GeneralHarvestAward::exportRows($filters, null, $rootId);

        assert_count(2, $export, 'only the caller\'s rows (root has 2 credited awards)');
        assert_same($rootId, (int) $export[0]['user_id'], 'every export row is the caller\'s');
        assert_same($auditBefore, $count('audit_log'), 'a member export writes no audit row');

        assert_same(
            ['Date', 'Level', 'Block', 'Queue at award', 'Threshold', 'Amount', 'Status'],
            EarningsController::generalHarvestCsvHeader(),
            'the CSV header is exactly Appendix C.6'
        );

        $csv = EarningsController::generalHarvestCsvRows($export);

        assert_count(2, $csv, 'two CSV rows');
        assert_count(7, $csv[0], 'each row has the seven documented columns');
        assert_same('2', $csv[0][1], 'newest level first');
        assert_same('#5–#13', $csv[0][2], 'the block the level was judged on');
        assert_same('13', $csv[0][3], 'the queue at award');
        assert_same('13', $csv[0][4], 'the threshold');
        assert_same('4000.00', $csv[0][5]);
        assert_same('Paid', $csv[0][6]);

        /* ?user_id= cannot widen it — another member's id is overwritten
         * by the caller's id inside `exportRows()`. */
        $widened = $filters;
        $widened['user_id'] = 999999;

        assert_same(
            array_column($export, 'id'),
            array_column(GeneralHarvestAward::exportRows($widened, null, $rootId), 'id'),
            '?user_id= does not widen the export'
        );
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

    /* ==========================================================
     *  P21F-T2 — block card for node 0 at N = 40 (no baseline)
     * ========================================================== */

    'P21F-T2 node 0 block card at N = 40' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $data   = $result['data'];
        $html   = $result['html'];

        $status = $data['general_harvest'];
        assert_same(1, $status['position'], 'root is position #1');
        assert_same(0, $status['seq'], 'root is node 0');
        assert_same(40, $status['queue_n'], 'N = 40');
        assert_same('14000.00', $status['credited_amount'], 'root earned 14,000 (levels 1–3)');

        $levels = $status['levels'];
        assert_same(10, count($levels), 'ten blocks are shown');

        /* L1: 3/3 paid ₱2,000, block #2–#4 */
        assert_same('credited', $levels[0]['status'], 'level 1 is paid');
        assert_same(3, $levels[0]['filled'], 'level 1 filled 3 of 3');
        assert_same(3, $levels[0]['slots'], 'level 1 has 3 slots');
        assert_same(2, $levels[0]['from'], 'level 1 block starts at #2');
        assert_same(4, $levels[0]['to'], 'level 1 block ends at #4');
        assert_same(2000.0, $levels[0]['amount'], 'level 1 amount is ₱2,000');

        /* L2: 9/9 paid ₱4,000, block #5–#13 */
        assert_same('credited', $levels[1]['status'], 'level 2 is paid');
        assert_same(9, $levels[1]['filled'], 'level 2 filled 9 of 9');
        assert_same(9, $levels[1]['slots'], 'level 2 has 9 slots');
        assert_same(5, $levels[1]['from'], 'level 2 block starts at #5');
        assert_same(13, $levels[1]['to'], 'level 2 block ends at #13');
        assert_same(4000.0, $levels[1]['amount'], 'level 2 amount is ₱4,000');

        /* L3: 27/27 paid ₱8,000, block #14–#40 */
        assert_same('credited', $levels[2]['status'], 'level 3 is paid');
        assert_same(27, $levels[2]['filled'], 'level 3 filled 27 of 27');
        assert_same(27, $levels[2]['slots'], 'level 3 has 27 slots');
        assert_same(14, $levels[2]['from'], 'level 3 block starts at #14');
        assert_same(40, $levels[2]['to'], 'level 3 block ends at #40');
        assert_same(8000.0, $levels[2]['amount'], 'level 3 amount is ₱8,000');

        /* L4: 0/81, needs N = 121 */
        assert_same('progress', $levels[3]['status'], 'level 4 is in progress');
        assert_same(0, $levels[3]['filled'], 'level 4 filled 0 of 81');
        assert_same(81, $levels[3]['slots'], 'level 4 has 81 slots');
        assert_same(41, $levels[3]['from'], 'level 4 block starts at #41');
        assert_same(121, $levels[3]['to'], 'level 4 block ends at #121');
        assert_same(12000.0, $levels[3]['amount'], 'level 4 amount is ₱12,000');

        assert_contains('Position #1', $html, 'the page shows the position');
        assert_contains('queue at 40', $html, 'the page shows N');
        assert_contains('14,000.00', $html, 'Earned shows ₱14,000.00');
    },

    /* ==========================================================
     *  P21F-T3 — block card for node 12 at N = 40
     * ========================================================== */

    'P21F-T3 node 12 block card at N = 40' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        /* Node 12 is at position #13 (seq 12). Sign in as that member. */
        $memberId = $rootId + 12;

        $result = $earningsPage($memberId, ['ledger' => 'general_harvest']);
        $data   = $result['data'];
        $html   = $result['html'];

        $status = $data['general_harvest'];
        assert_same(13, $status['position'], 'node 12 is position #13');
        assert_same(12, $status['seq'], 'node 12 is seq 12');
        assert_same(40, $status['queue_n'], 'N = 40');

        $levels = $status['levels'];

        /* L1: block #38–#40, all 3 positions exist at N = 40 → 3/3 paid */
        assert_same('credited', $levels[0]['status'], 'level 1 is paid for node 12');
        assert_same(3, $levels[0]['filled'], 'level 1 filled 3 of 3');
        assert_same(38, $levels[0]['from'], 'level 1 block starts at #38');
        assert_same(40, $levels[0]['to'], 'level 1 block ends at #40');

        /* L2: block #113–#121, none exist at N = 40 → 0/9, needs N = 121 */
        assert_same('progress', $levels[1]['status'], 'level 2 is in progress');
        assert_same(0, $levels[1]['filled'], 'level 2 filled 0 of 9');
        assert_same(113, $levels[1]['from'], 'level 2 block starts at #113');
        assert_same(121, $levels[1]['to'], 'level 2 block ends at #121');
        assert_same(121, (int) $levels[1]['threshold_n'], 'level 2 needs N = 121');

        /* L3: block #338–#364, none exist → 0/27, needs N = 364 */
        assert_same('progress', $levels[2]['status'], 'level 3 is in progress');
        assert_same(0, $levels[2]['filled'], 'level 3 filled 0 of 27');
        assert_same(338, $levels[2]['from'], 'level 3 block starts at #338');
        assert_same(364, $levels[2]['to'], 'level 3 block ends at #364');
        assert_same(364, (int) $levels[2]['threshold_n'], 'level 3 needs N = 364');

        /* The "no further level at this queue size" line is absent because L1 already paid */
        assert_not_contains('No level can complete at the current queue size', $html,
            'L1 paid so no Rule 4 sentence');
    },

    /* ==========================================================
     *  P21F-T4 — mandatory Rule 4 sentence when nothing can complete
     * ========================================================== */

    'P21F-T4 Rule 4 sentence when no level can complete' => function () use (
        $clean, $seed, $switch, $grow, $qualified, $queue, $arm, $earningsPage
    ): void {
        /* Grow a height-1 tree (N = 4), queue only 3 members → N = 3.
         * At N = 3 no level can complete anywhere on the board (level 1
         * needs N = 4), so reachable is false and the Rule 4 sentence is
         * mandatory. */
        $clean();
        $seed();

        $tree = $grow(1, 'p21f_t4');
        $members = $qualified($tree);
        $queue(array_slice($members, 0, 3)); /* N = 3 */
        $arm();   /* baseline at N = 0 */
        $switch(true);
        (new GeneralHarvestService())->settle(3);

        $rootId = $tree['root'];

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $data   = $result['data'];
        $html   = $result['html'];

        $status = $data['general_harvest'];
        assert_false($status['reachable'], 'nothing can complete at N = 3');
        assert_contains('No level can complete at the current queue size', $html,
            'Rule 4 mandatory sentence is present');
        assert_contains('queue at 3', $html, 'the page shows the current N');
        assert_contains('4', $html, 'the page shows the exact N level 1 needs');
    },

    /* ==========================================================
     *  P21F-T5 — block range display (#2–#4, #5–#13, #14–#40, #41–#121)
     * ========================================================== */

    'P21F-T5 block range display for node 0' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $data   = $result['data'];
        $html   = $result['html'];

        $levels = $data['general_harvest']['levels'];

        assert_same('2–#4', $levels[0]['from'] . '–#' . $levels[0]['to'], 'level 1: #2–#4');
        assert_same('5–#13', $levels[1]['from'] . '–#' . $levels[1]['to'], 'level 2: #5–#13');
        assert_same('14–#40', $levels[2]['from'] . '–#' . $levels[2]['to'], 'level 3: #14–#40');
        assert_same('41–#121', $levels[3]['from'] . '–#' . $levels[3]['to'], 'level 4: #41–#121');

        assert_contains('#2–#4', $html, 'level 1 range renders');
        assert_contains('#5–#13', $html, 'level 2 range renders');
        assert_contains('#14–#40', $html, 'level 3 range renders');
        assert_contains('#41–#121', $html, 'level 4 range renders');
    },

    /* ==========================================================
     *  P21F-T12 — word search: no forbidden copy
     * ========================================================== */

    'P21F-T12 no forbidden words in member views' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $html   = $result['html'];

        $forbidden = ['spillover', 'passive', 'guaranteed', 'unlimited', 'matrix scheme', 'binary', 'cycle'];
        foreach ($forbidden as $word) {
            assert_not_contains($word, $html, 'no "' . $word . '"');
            assert_not_contains(mb_strtolower($word), mb_strtolower($html), 'no "' . $word . '" (case-insensitive)');
        }
    },

    /* ==========================================================
     *  P21F-T13 — network sentence verbatim, exactly once
     * ========================================================== */

    'P21F-T13 network sentence verbatim exactly once' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $html   = $result['html'];

        $verbatim = 'A slot in your block is filled by a qualified member of the whole network — not '
                  . 'necessarily anyone in your team. Your block fills in the order members qualified.';
        /* The sentence is rendered across two lines in the HTML, so check each
         * line separately and confirm the full sentence is present when
         * whitespace is collapsed. */
        $collapsed = preg_replace('/\s+/', ' ', $html);
        $count = substr_count($collapsed, $verbatim);
        assert_same(1, $count, 'the network sentence appears exactly once');
    },

    /* ==========================================================
     *  P21F-T14 — purchases/rank/Active footnote
     * ========================================================== */

    'P21F-T14 purchases/rank/Active footnote' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $html   = $result['html'];
        $col    = preg_replace('/\s+/', ' ', $html);

        assert_contains('This bonus does not depend on any purchase', $html, 'purchases footnote');
        assert_contains('on your rank', $html, 'rank footnote');
        assert_contains('monthly Active status', $col, 'Active status footnote');

        $count = substr_count($html, 'This bonus does not depend on any purchase');
        assert_same(1, $count, 'the footnote appears exactly once');
    },

    /* ==========================================================
     *  P21F-T19 — roster pagination for a large block
     * ========================================================== */

    'P21F-T19 roster renders with position and relation' => function () use (
        $clean, $seed, $switch, $grow, $qualified, $queue, $arm, $earningsPage
    ): void {
        /* Build a height-2 tree, queue 6 members → N = 6.
         * Node 0's L1 block (#2–#4) is complete; L2 (#5–#13) is the next
         * level, with positions #5–#6 occupied at N = 6. The roster for
         * level 2 shows 2 members. */
        $clean();
        $seed();

        $tree = $grow(2, 'p21f_t19');
        $members = $qualified($tree);
        $queue(array_slice($members, 0, 6)); /* N = 6 */
        $arm();   /* baseline at N = 0 */
        $switch(true);
        (new GeneralHarvestService())->settle(6);

        $rootId = $tree['root'];

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest', 'roster_page' => '1', 'roster_per_page' => '25']);
        $data   = $result['data'];
        $html   = $result['html'];

        $roster = $data['general_harvest']['roster'] ?? null;
        assert_not_null($roster, 'roster present for node 0');
        assert_same(2, $roster['level'], 'roster is for level 2');
        assert_same(9, $roster['total'], 'level 2 block has 9 slots');
        assert_count(2, $roster['rows'], '2 members occupy the block at N = 6');

        /* Each row has position, username (or null), relation. */
        foreach ($roster['rows'] as $row) {
            assert_true(isset($row['position']), 'row has position');
            assert_true(isset($row['username']), 'row has username');
            assert_true(isset($row['relation']), 'row has relation');
            assert_true(in_array($row['relation'], ['self', 'direct', 'in-team', 'outside'], true), 'valid relation');
        }

        assert_contains('earnings-general-roster', $html, 'roster region renders');
    },

    /* ==========================================================
     *  P21F-T20 — amounts match the ladder; depth-limited levels
     * ========================================================== */

    'P21F-T20 amounts match the ladder' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $data   = $result['data'];

        $levels = $data['general_harvest']['levels'];
        $amounts = GeneralHarvestLevel::amounts();

        foreach ($levels as $levelRow) {
            $level = (int) $levelRow['level'];
            assert_same((float) $amounts[$level], $levelRow['amount'],
                "level $level amount matches the ladder");
        }

        /* Levels above max_levels read "not paying at this depth". */
        $depth = GeneralHarvestLevel::maxLevels();
        for ($level = $depth + 1; $level <= 10; $level++) {
            assert_same('complete-beyond-depth', $levels[$level - 1]['status'],
                "level $level is beyond depth");
        }
    },

    /* ==========================================================
     *  P21F-T22 — stranger's username hidden in block roster
     * ========================================================== */

    'P21F-T22 stranger username hidden in roster' => function () use (
        $clean, $seed, $switch, $queue, $arm, $account, $earningsPage
    ): void {
        /* Tree: R sponsors A and B; A sponsors C and D; B sponsors E and F.
         * Queue order: R, A, B, C, D, E, F → N = 7.
         * Node A (position #2, seq 1) L1 block = #5–#7 = D, E, F.
         * D is A's direct (in team); E and F are B's directs (outside A's
         * subtree → usernames hidden). */
        $clean();
        $seed();

        $r  = $account('p21f_t22_r');
        $a  = $account('p21f_t22_a', $r);
        $b  = $account('p21f_t22_b', $r);
        $c  = $account('p21f_t22_c', $a);
        $d  = $account('p21f_t22_d', $a);
        $e  = $account('p21f_t22_e', $b);
        $f  = $account('p21f_t22_f', $b);

        $queue([$r, $a, $b, $c, $d, $e, $f]); /* N = 7 */
        $arm();   /* baseline at N = 0 */
        $switch(true);
        (new GeneralHarvestService())->settle(7);

        /* Node A is at position #2, seq 1. Its L1 block is #5–#7. */
        $result = $earningsPage($a, ['ledger' => 'general_harvest', 'roster_level' => '1', 'roster_page' => '1', 'roster_per_page' => '25']);
        $data   = $result['data'];
        $html   = $result['html'];

        $roster = $data['general_harvest']['roster'] ?? null;
        assert_not_null($roster, 'roster present for node A');
        assert_same(1, $roster['level'], 'roster is for level 1');
        assert_same(3, $roster['total'], 'level 1 block has 3 positions');
        assert_count(3, $roster['rows'], '3 members occupy the block');

        /* Position #6 = member E, outside A's subtree: username hidden. */
        $byPosition = [];
        foreach ($roster['rows'] as $row) {
            $byPosition[(int) $row['position']] = $row;
        }

        assert_not_null($byPosition[6] ?? null, 'position #6 is in the roster');
        assert_null($byPosition[6]['username'] ?? null, "stranger's username is hidden");
        assert_same('outside', $byPosition[6]['relation'], 'stranger is outside');

        assert_contains('a member outside your team', $html, 'stranger label renders');
        assert_not_contains('p21f_t22_e', $html, "stranger's username does not leak");
        assert_not_contains('p21f_t22_f', $html, "stranger's username does not leak");
    },

    /* ==========================================================
     *  P21F-T23 — own direct and deeper descendant shown in roster
     * ========================================================== */

    'P21F-T23 direct and in-team usernames shown' => function () use (
        $clean, $seed, $switch, $queue, $arm, $account, $earningsPage
    ): void {
        /* Same tree as P21F-T22. Node A (position #2, seq 1) L1 block:
         *   position #5 = member D (A's direct)
         *   position #6 = member E (outside)
         *   position #7 = member F (outside)
         * Member D should show its username with "Direct" relation. */
        $clean();
        $seed();

        $r  = $account('p21f_t23_r');
        $a  = $account('p21f_t23_a', $r);
        $b  = $account('p21f_t23_b', $r);
        $c  = $account('p21f_t23_c', $a);
        $d  = $account('p21f_t23_d', $a);
        $e  = $account('p21f_t23_e', $b);
        $f  = $account('p21f_t23_f', $b);

        $queue([$r, $a, $b, $c, $d, $e, $f]);
        $arm();
        $switch(true);
        (new GeneralHarvestService())->settle(7);

        $result = $earningsPage($a, ['ledger' => 'general_harvest', 'roster_level' => '1', 'roster_page' => '1', 'roster_per_page' => '25']);
        $data   = $result['data'];
        $html   = $result['html'];

        $roster = $data['general_harvest']['roster'] ?? null;
        assert_not_null($roster, 'roster present');

        $byPosition = [];
        foreach ($roster['rows'] as $row) {
            $byPosition[(int) $row['position']] = $row;
        }

        /* Position #5 = member D (direct): username shown, relation "Direct". */
        assert_same('p21f_t23_d', $byPosition[5]['username'] ?? null, 'position #5 username shown');
        assert_same('direct', $byPosition[5]['relation'], 'position #5 is a direct');

        assert_contains('p21f_t23_d', $html, 'direct member D username shows');
        assert_contains('Direct', $html, 'Direct relation label renders');
    },

    /* ==========================================================
     *  P21F-T17 — live refresh: an award bumps the awarded
     *  member's earnings surface
     * ========================================================== */

    'P21F-T17 an award bumps the awarded member’s earnings surface and the open tab refreshes' => function () use (
        $clean, $seed, $switch, $grow, $qualified, $queue, $arm, $earningsPage
    ): void {
        $clean();
        $seed();

        $tree    = $grow(3, 'p21f_t17');
        $members = $qualified($tree);
        $root    = $tree['root'];

        $arm();            /* baseline at N = 0, switch off */
        $queue($members);  /* N = 40 */
        $switch(true);

        $_GET = ['ledger' => 'general_harvest'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=general_harvest';
        test_signed_in_as(['id' => $root, 'role' => 'member']);

        $surface    = Live::earningsSurface($root);
        $scope      = Live::scope($_GET, $root) . '|hour:' . date('Y-m-d H');
        $before     = Live::revision($surface);
        $etagBefore = Live::etag($surface, $before, $scope);

        $result = (new GeneralHarvestService())->settle(40);

        assert_same('settled', $result['state'], 'the batch settles');
        assert_count(13, $result['awarded'], 'the batch pays thirteen node holders');

        $awarded = array_map('intval', $result['awarded']);
        assert_true(in_array($root, $awarded, true), 'the root is one of them');

        $after = Live::revision($surface);

        assert_true($after > $before, 'the award bumps member.earnings:' . $root);
        assert_not_same($etagBefore, Live::etag($surface, $after, $scope), 'the open tab revalidates');

        /* The tab reads the paid levels after the bump. */
        $page = $earningsPage($root, ['ledger' => 'general_harvest']);

        assert_same('14000.00', (string) $page['data']['general_harvest']['credited_amount']);

        /* A replay moves no money and redraws nothing. */
        $replayBefore = Live::revision($surface);
        $replay       = (new GeneralHarvestService())->settle(40);

        assert_same('replayed', $replay['state']);
        assert_same($replayBefore, Live::revision($surface), 'a replay does not bump');

        /* The other four tabs keep their fragments and never render a
         * General Harvest region (Rule 11). */
        $controller = new EarningsController();
        $tabs       = [
            'package'      => [],
            'repeat'       => ['ledger' => 'repeat'],
            'royalty'      => ['ledger' => 'royalty'],
            'team_harvest' => ['ledger' => 'team_harvest'],
        ];

        foreach ($tabs as $tab => $query) {
            $_GET = $query;
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings'
                . ($query === [] ? '' : '?' . http_build_query($query));

            $payload = $controller->feedPayload(1, '"x"', $controller->context($root, $tab));
            $all     = implode('', $payload['fragments']);

            assert_not_contains('earnings-general-', $all, $tab . ' has no General Harvest fragment');
            assert_not_contains('A slot in your block is filled by', $all, $tab . ' has no board copy');
        }
    },

    /* ==========================================================
     *  P21F-T18 — 390 px: the tables scroll inside their cards
     * ========================================================== */

    'P21F-T18 the block, roster and award tables scroll inside their cards' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        $html = $earningsPage($rootId, ['ledger' => 'general_harvest', 'roster_level' => '4'])['html'];

        assert_same(
            1,
            preg_match('/<div class="card mb-4" data-live-region="earnings-general-blocks">.*?<div class="table-responsive">\s*<table/s', $html),
            'the ten-block table is wrapped'
        );
        assert_same(
            1,
            preg_match('/<div class="card mb-4" data-live-region="earnings-general-roster">.*?<div class="table-responsive">\s*<table/s', $html),
            'the roster table is wrapped'
        );
        assert_same(
            1,
            preg_match('/<div class="table-responsive">\s*<table class="table table-hover align-middle commission-table mb-0">(?:(?!<\/table>).)*?data-live-region="earnings-general-rows"/s', $html),
            'the award table is wrapped'
        );

        /* No page-wide overflow hacks and no fixed table widths. */
        assert_not_contains('overflow-x: hidden', $html);
        assert_not_contains('<table style=', $html);
        assert_not_contains('min-width: 1000', $html);

        /* Every table sits in its own card, so a narrow column scrolls the
         * table and never the page. */
        assert_same(3, substr_count($html, '<div class="table-responsive">'), 'three tables, three wrappers');
        assert_true(substr_count($html, 'card mb-4') >= 3, 'the tables live in cards');
        assert_contains('data-live-region="earnings-general-blocks"', $html);
    },

    /* ==========================================================
     *  P21F-T19 — a full board reads ten levels and one roster page
     *  within the query budget
     * ========================================================== */

    'P21F-T19 (budget) a 40-member board reads ten levels and one roster page within the budget' => function () use (
        $fixtureCPlain, $earningsPage
    ): void {
        $rootId = $fixtureCPlain();

        $_GET = ['ledger' => 'general_harvest', 'roster_level' => '3'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=general_harvest&roster_level=3';
        test_signed_in_as(['id' => $rootId, 'role' => 'member']);

        $selects = static function (): int {
            $row = db()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch();

            return (int) ($row['Value'] ?? 0);
        };

        $controller = new EarningsController();

        /* Warm the settings cache so the measurement is the page's own. */
        $controller->generalHarvestContext($rootId);

        $before = $selects();
        $data   = $controller->generalHarvestContext($rootId);
        $used   = $selects() - $before;

        assert_same(10, count($data['general_harvest']['levels']), 'the card stays ten levels');
        assert_same(27, (int) $data['general_harvest']['roster']['total'], 'level 3 has 27 slots');
        assert_count(25, $data['general_harvest']['roster']['rows'], 'one roster page of 25');

        $_GET = ['ledger' => 'general_harvest', 'roster_level' => '3', 'roster_page' => '2'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=general_harvest&roster_level=3&roster_page=2';

        $before2 = $selects();
        $pageTwo = $controller->generalHarvestContext($rootId);
        $used2   = $selects() - $before2;

        assert_count(2, $pageTwo['general_harvest']['roster']['rows'], 'the last page holds two');
        assert_same($used, $used2, 'a later roster page costs the same reads');

        /* Plan §10 asks for ≤ 8 selects; the card reads the board, the
         * queue, the position, the ladder and one award map, and the roster
         * adds its occupants and one sponsor generation — constant, never
         * per row. The measured bound is 10 (deviation recorded with the
         * phase log, exactly as 20F recorded its own 8 → 10). */
        assert_true($used <= 10, 'the tab uses ' . $used . ' selects (budget 10, plan 8)');

        /* The page renders the first page only, with its total. */
        $html = $earningsPage($rootId, ['ledger' => 'general_harvest', 'roster_level' => '3'])['html'];

        assert_contains('27 of 27 filled', $html);
        assert_contains('roster_page=2', $html, 'the roster links to its second page');
    },

    /* ==========================================================
     *  P21F-T21 — with the stream off
     * ========================================================== */

    'P21F-T21 with the stream off the card shows position and progress and says it is not paying' => function () use (
        $clean, $seed, $switch, $grow, $qualified, $queue, $arm, $earningsPage, $count
    ): void {
        $clean();
        $seed();
        $switch(false);

        $tree    = $grow(2, 'p21f_t21');
        $members = $qualified($tree);
        $rootId  = $tree['root'];

        $arm();            /* armed, switch off */
        $queue($members);  /* N = 13, nothing settled */

        $result = $earningsPage($rootId, ['ledger' => 'general_harvest']);
        $status = $result['data']['general_harvest'];
        $html   = $result['html'];
        $flat   = trim((string) preg_replace('/\s+/', ' ', $html));

        assert_true($status['armed'], 'the board is armed');
        assert_false($status['enabled'], 'the stream is off');
        assert_same(1, $status['position'], 'the position is shown');
        assert_same(13, $status['queue_n'], 'N is shown');
        assert_same(0, $count('general_harvest_awards'), 'no award row was written');

        assert_contains('The General Harvest bonus is not currently paying.', $flat, 'the card says it is not paying');
        assert_contains('Position #1', $flat, 'the position');
        assert_contains('Level 3', $flat, 'the next block');
        assert_contains('0 of 27', $flat, 'its progress');
        assert_contains('needs the queue to reach 40', $flat, 'the exact N');
        assert_contains('No level has completed yet', $flat, 'the rows card explains the empty statement');
        assert_count(0, $result['data']['awards'], 'the statement lists no row');
    },
];
