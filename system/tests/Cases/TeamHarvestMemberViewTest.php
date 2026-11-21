<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestMemberViewTest — Phase 20F (plan §10, §12.5, §12.6,
 *  Appendix C.3/C.4/C.6/C.7/C.8/C.9)
 *
 *  Proves the member-facing Team Harvest surfaces, and only reads:
 *
 *      tab        the fourth pill on /earnings: the five counters, the
 *                 position and gate sentence, the next level's progress,
 *                 the whole 3×10 matrix with its empty slots, the
 *                 paginated roster and the level rows
 *      copy       "Team Harvest"/"Harvest position"/"Team matrix", the
 *                 two exclusions once, the purchases footnote once, no
 *                 forbidden word, no promised date (Rules 1–8)
 *      gate       a member who has not qualified reads the gate, not an
 *                 empty matrix (Appendix C.8, T6)
 *      baseline   a pre-go-live level is *Before go-live*, excluded from
 *                 *Earned*, and the footnote names the start date (T7)
 *      scoping    every read is the session member's; `?user_id=`
 *                 cannot widen the tab or the CSV (Rules 10–11, T16)
 *      money      a paid level links to its wallet movement by `ref_id`
 *                 (T15); every amount is `team_harvest_levels`' (T20)
 *      budget     the roster paginates and the page is constant-edged
 *                 (T19); the tables scroll inside their cards (T18)
 *
 *  Fixtures. The live trees are grown through the production path
 *  (`TeamHarvestService::onQualified()`, the registration hook's exact
 *  call) or reconstructed by the real seed, so no case writes an award or
 *  a wallet movement by hand — 20D's suite owns the money rules and this
 *  file owns the visibility of them. Two cases (P20F-T2's four counters
 *  and P20F-T19's 500-teammate roster) set `harvest_queue.teammates`
 *  directly: they pin what the page renders for a given counter, and the
 *  counter's own truth is 20C's (P20C-T…), not this file's.
 *
 *  Run this group alone with:  php tests/run.php TeamHarvestMemberView
 * ============================================================== */

$appRoot  = dirname(__DIR__, 2);
$fixtures = require __DIR__ . '/../fixtures/harvest_money.php';

$account          = $fixtures['account'];
$cleanFixture     = $fixtures['clean'];
$arm              = $fixtures['arm'];
$disarm           = $fixtures['disarm'];
$enable           = $fixtures['enable'];
$grow             = $fixtures['grow'];
$growPastBaseline = $fixtures['growPastBaseline'];
$depth            = $fixtures['depth'];
$awards           = $fixtures['awards'];
$count            = $fixtures['count'];
$firstPosition    = $fixtures['firstPosition'];

/** The reference ladder the fixtures restore (Appendix A), keyed 1 … 10. */
$reference = TeamHarvestLevel::referencePreset();

/* --------------------------------------------------------------
 *  Rendering helpers
 * -------------------------------------------------------------- */

/** Render one page through the real layout, exactly as the route does. */
$renderPage = static function (string $layout, string $template, array $data, string $nav): string {
    ob_start();

    try {
        View::page($layout, $template, $data + [
            'page_title'  => ucfirst($nav),
            'current_nav' => $nav,
            'page_css'    => [],
            'page_js'     => [],
            'live'        => Live::meta('member.earnings:0', 'page'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/** Render /earnings for a built context. */
$renderEarnings = static function (array $data) use ($renderPage): string {
    return $renderPage('member', 'member/earnings', $data, 'earnings');
};

/**
 * Build the Team Harvest tab for the signed-in member and render it.
 *
 * @param array<string, string> $get
 * @return array{controller:EarningsController, data:array<string, mixed>, html:string, payload:array<string, mixed>}
 */
$teamTab = static function (array $get = []) use ($renderEarnings): array {
    $_GET = $get + ['ledger' => 'team_harvest'];
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?' . http_build_query($_GET);

    $user = auth_user();
    assert_not_null($user);

    $controller = new EarningsController();
    $data       = $controller->teamHarvestContext((int) $user['id']);

    return [
        'controller' => $controller,
        'data'       => $data,
        'html'       => $renderEarnings($data),
        'payload'    => $controller->feedPayload(
            9,
            '"member.earnings:' . (int) $user['id'] . ':9:x"',
            $data
        ),
    ];
};

/** One `<tr data-level="N">…</tr>` of the matrix card. */
$levelRow = static function (string $html, int $level): string {
    $pattern = '/<tr data-level="' . $level . '"(?:(?!<\/tr>).)*?<\/tr>/s';

    return preg_match($pattern, $html, $match) === 1 ? $match[0] : '';
};

/** One `<tr data-live-key="ID">…</tr>` of the level rows. */
$awardRow = static function (string $html, int $id): string {
    $pattern = '/<tr data-live-key="' . $id . '"(?:(?!<\/tr>).)*?<\/tr>/s';

    return preg_match($pattern, $html, $match) === 1 ? $match[0] : '';
};

/** One white-space-normalised copy of a fragment (copy spans source lines). */
$flat = static fn (string $html): string => trim((string) preg_replace('/\s+/', ' ', $html));

/** How many SELECT statements the server has run on this session. */
$selects = static function (): int {
    $row = db()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch();

    return (int) ($row['Value'] ?? 0);
};

/* --------------------------------------------------------------
 *  Fixtures
 * -------------------------------------------------------------- */

/**
 * A live tree: the queue seeded (armed) and every qualification paid.
 *
 * @return array{root:int, levels:array<int, list<int>>, accounts:int, leaves:int}
 */
$liveTree = static function (string $prefix, int $height = 3) use ($cleanFixture, $arm, $grow): array {
    $cleanFixture();
    $arm();

    return $grow($height, $prefix, new TeamHarvestService());
};

/**
 * A queued member with a **counter** set directly: the view fixture for
 * a member the engine has not actually grown to. Used by T2 only.
 */
$counterMember = static function (string $username, int $teammates) use ($cleanFixture, $arm, $account): int {
    $cleanFixture();
    $arm();

    $userId = $account($username);

    (new HarvestQueue())->insert($userId, '2026-11-01 00:00:00', 3, HarvestQueue::SOURCE_SEED);
    db()->prepare('UPDATE harvest_queue SET teammates = ? WHERE user_id = ?')->execute([$teammates, $userId]);

    return $userId;
};

/**
 * A queued member with `$size` real queued descendants: the large-team
 * fixture T19 needs (the roster, not the money).
 */
$bigTeam = static function (string $prefix, int $size) use ($cleanFixture, $arm, $account): int {
    $cleanFixture();
    $arm();

    $queue  = new HarvestQueue();
    $root   = $account($prefix . '_root');

    $queue->insert($root, '2026-11-01 00:00:00', 3, HarvestQueue::SOURCE_SEED);

    for ($i = 1; $i <= $size; $i++) {
        $member = $account($prefix . '_t' . $i, $root);
        $queue->insert($member, '2026-11-01 00:01:00', 3, HarvestQueue::SOURCE_SEED);
    }

    db()->prepare('UPDATE harvest_queue SET teammates = ? WHERE user_id = ?')->execute([$size, $root]);

    return $root;
};

/**
 * The go-live baseline tree: root + 12 directs with three unqualified
 * leaves each, reconstructed by the real seed, then evaluated. Levels 1
 * and 2 are `baseline` (₱3,000.00), nothing is paid.
 *
 * The tree also exposes nine depth-2 leaves so `growPastBaseline()` can
 * take the root from `n = 12` to `n = 39` and pay level 3 after go-live.
 *
 * @return array{root:int, levels:array<int, list<int>>, directs:list<int>}
 */
$baselineTree = static function (string $prefix) use (
    $account,
    $cleanFixture,
    $disarm,
    $enable
): array {
    $cleanFixture();
    $disarm();

    $root    = $account($prefix . '_root');
    $start   = strtotime('2026-11-01 08:00:00');
    $minute  = 0;
    $directs = [];
    $leaves  = [];

    for ($i = 1; $i <= 12; $i++) {
        $minute++;
        $direct = $account(
            $prefix . '_d' . $i,
            $root,
            'member',
            date('Y-m-d H:i:s', $start + 60 * $minute)
        );
        $directs[] = $direct;

        for ($j = 1; $j <= 3; $j++) {
            $leaf     = $account($prefix . '_l' . $i . '_' . $j, $direct);
            $leaves[] = $leaf;
        }
    }

    $seed = (new HarvestQueue())->seed();
    assert_true((bool) $seed['ok'], 'the baseline fixture seeds');
    assert_same(13, (int) $seed['inserted'], 'the root and its twelve directs are queued');

    $enable(true);

    $result = (new TeamHarvestService())->evaluate([$root]);
    assert_same(2, (int) $result['baseline'], 'levels 1 and 2 complete before go-live');
    assert_same(0, (int) $result['paid'], 'a baseline level is never paid');

    return ['root' => $root, 'levels' => [1 => $directs, 2 => array_slice($leaves, 0, 9)], 'directs' => $directs];
};

return [
    /* ==========================================================
     *  P20F-T1 — the tab renders
     * ========================================================== */

    'P20F-T1 a queued member reads the position, the gate, the matrix, the roster and the rows' => function () use (
        $liveTree,
        $firstPosition,
        $teamTab,
        $levelRow,
        $flat
    ): void {
        $tree = $liveTree('p20f_t1', 3);
        $root = $tree['root'];
        $row  = (new HarvestQueue())->row($root);

        assert_not_null($row);
        assert_same($firstPosition(), $row['position'], 'the root took the first position');

        test_signed_in_as(test_user('p20f_t1_root'));

        $tab  = $teamTab();
        $data = $tab['data'];
        $html = $tab['html'];

        assert_same(EarningsController::TAB_TEAM_HARVEST, $data['tab']);
        assert_same(true, $data['team_harvest']['queued']);
        assert_same($row['position'], $data['team_harvest']['position']);
        assert_same(39, $data['team_harvest']['teammates']);
        assert_same(3, $data['team_harvest']['directs_at_qualification']);
        assert_same(3, $data['pager']->total, 'levels 1–3 were paid');
        assert_same(3, count($data['awards']));
        assert_same(39, $data['roster_pager']->total);

        /* The fourth pill, active, on its own URL. */
        assert_contains('href="' . url('earnings?ledger=team_harvest') . '"', $html);
        assert_contains('aria-current="page"', $html);
        assert_contains('Team Harvest', $html);

        /* The heading, the position and the gate sentence (Rule 2). */
        assert_contains('Your Team Harvest bonus', $html);
        assert_contains('Harvest position #' . $row['position'], $html);
        assert_contains(
            'You qualified for position #' . $row['position'] . ' when you registered your third direct member.',
            $flat($html)
        );
        assert_contains('Qualified ' . format_date((string) $row['qualified_at']), $html);

        /* The counters, the matrix, the roster and the rows. */
        assert_contains('Earned', $html);
        assert_contains(money(7000.0), $html, 'levels 1–3 paid ₱7,000.00');
        assert_contains('Your Team matrix', $html);
        assert_contains('Teammates, in qualification order', $html);
        assert_contains('39 teammates', $html);
        assert_contains('Your levels', $html);

        foreach ([
            'earnings-team-stats',
            'earnings-team-progress',
            'earnings-team-matrix',
            'earnings-team-header',
            'earnings-team-rows',
            'earnings-team-pagination',
        ] as $region) {
            assert_contains('data-live-region="' . $region . '"', $html, $region . ' renders');
        }

        /* The filter form carries the tab and four filters. */
        assert_contains('name="ledger" value="team_harvest"', $html);
        foreach (['name="level"', 'name="status"', 'name="from"', 'name="to"'] as $field) {
            assert_contains($field, $html, 'the tab keeps its ' . $field . ' filter');
        }

        assert_contains('href="' . $data['export_url'] . '"', $html, 'the export link is the Team Harvest one');

        /* The matrix is ten level rows, and a paid level says so. */
        $level3 = $levelRow($html, 3);
        assert_contains('27 of 27', $level3);
        assert_contains('Paid', $level3);
        assert_contains(money(4000.0), $level3);

        /* The feed carries one fragment per region and no form. */
        assert_same(
            [
                'earnings-team-stats',
                'earnings-team-progress',
                'earnings-team-matrix',
                'earnings-team-header',
                'earnings-team-rows',
                'earnings-team-pagination',
            ],
            array_keys($tab['payload']['fragments'])
        );
        assert_not_contains('name="level"', $tab['payload']['fragments']['earnings-team-rows'], 'the filter form never travels in a row fragment');
        assert_contains('ledger=team_harvest', $tab['payload']['meta']['canonical']);
    },

    /* ==========================================================
     *  P20F-T2 — the progress card for four counters
     * ========================================================== */

    'P20F-T2 the progress card reads level 1/2/3/4 for n = 1/5/20/50' => function () use (
        $counterMember,
        $flat
    ): void {
        $root = $counterMember('p20f_t2', 1);

        test_signed_in_as(test_user('p20f_t2'));

        $cases = [
            1  => ['level' => 1, 'threshold' => 3,   'filled' => 1,  'slots' => 3,  'percent' => 33.3, 'remaining' => 2,  'amount' => 1000.0],
            5  => ['level' => 2, 'threshold' => 12,  'filled' => 2,  'slots' => 9,  'percent' => 22.2, 'remaining' => 7,  'amount' => 2000.0],
            20 => ['level' => 3, 'threshold' => 39,  'filled' => 8,  'slots' => 27, 'percent' => 29.6, 'remaining' => 19, 'amount' => 4000.0],
            50 => ['level' => 4, 'threshold' => 120, 'filled' => 11, 'slots' => 81, 'percent' => 13.6, 'remaining' => 70, 'amount' => 6000.0],
        ];

        $service = new TeamHarvestService();

        foreach ($cases as $n => $expected) {
            db()->prepare('UPDATE harvest_queue SET teammates = ? WHERE user_id = ?')->execute([$n, $root]);

            $status   = $service->statusFor($root);
            $card     = $flat(View::capture('member/earnings/_team_progress', ['team_harvest' => $status]));
            $next     = $status['next'];

            assert_same($expected['level'], (int) $next['level'], 'n = ' . $n . ' next level');
            assert_same($expected['threshold'], (int) $next['threshold'], 'n = ' . $n . ' threshold');
            assert_same($expected['filled'], (int) $next['filled'], 'n = ' . $n . ' filled');
            assert_same($expected['slots'], (int) $next['slots'], 'n = ' . $n . ' slots');
            assert_same($expected['percent'], (float) $next['percent'], 'n = ' . $n . ' percent');
            assert_same($expected['remaining'], (int) $next['remaining'], 'n = ' . $n . ' remaining');

            assert_contains('Level ' . $expected['level'], $card);
            assert_contains(
                $expected['filled'] . ' of ' . $expected['slots'] . ' slots',
                $card,
                'n = ' . $n . ' renders the block'
            );
            assert_contains(number_format($expected['percent'], 1, '.', '') . '%', $card);
            assert_contains(
                $expected['remaining'] . ' more qualified teammate',
                $card,
                'n = ' . $n . ' renders what is missing'
            );
            assert_contains('reach ' . number_format($expected['threshold']), $card);
            assert_contains(money($expected['amount']), $card, 'n = ' . $n . ' renders the amount');
        }
    },

    /* ==========================================================
     *  P20F-T3 — the matrix for n = 12
     * ========================================================== */

    'P20F-T3 the matrix shows 3 of 3 and 9 of 9 filled, then 0 of 27 to 0 of 59,049' => function () use (
        $liveTree,
        $teamTab,
        $levelRow,
        $reference
    ): void {
        $tree = $liveTree('p20f_t3', 2);   // the root holds n = 12
        $root = $tree['root'];

        test_signed_in_as(test_user('p20f_t3_root'));

        $tab  = $teamTab();
        $data = $tab['data'];
        $html = $tab['html'];

        assert_same(12, $data['team_harvest']['teammates']);
        assert_same(2, $data['pager']->total, 'levels 1 and 2 were paid');

        assert_contains('3 of 3', $levelRow($html, 1));
        assert_contains('Paid', $levelRow($html, 1));
        assert_contains('9 of 9', $levelRow($html, 2));
        assert_contains('Paid', $levelRow($html, 2));

        /* A level the member has not reached is still shown, with its
         * amount — the amount is the offer (Rule 5). */
        $slots = [3 => '0 of 27', 4 => '0 of 81', 5 => '0 of 243', 6 => '0 of 729'];
        $slots[7] = '0 of 2,187';
        $slots[8] = '0 of 6,561';
        $slots[9] = '0 of 19,683';
        $slots[10] = '0 of 59,049';

        foreach ($slots as $level => $label) {
            $row = $levelRow($html, $level);

            assert_contains($label, $row, 'level ' . $level . ' shows its empty block');
            assert_contains(money($reference[$level]), $row, 'level ' . $level . ' shows its amount');
            assert_contains('In progress', $row, 'an empty level reads in progress');
        }

        assert_same(10, count($data['team_harvest']['levels']), 'the matrix always holds ten levels');
    },

    /* ==========================================================
     *  P20F-T4 — the matrix for n = 39
     * ========================================================== */

    'P20F-T4 the matrix for n = 39 marks levels 1–3 paid and level 4 empty at ₱6,000.00' => function () use (
        $liveTree,
        $teamTab,
        $levelRow
    ): void {
        $tree = $liveTree('p20f_t4', 3);
        $root = $tree['root'];

        test_signed_in_as(test_user('p20f_t4_root'));

        $tab  = $teamTab();
        $html = $tab['html'];

        assert_same(39, $tab['data']['team_harvest']['teammates']);

        foreach ([1 => '3 of 3', 2 => '9 of 9', 3 => '27 of 27'] as $level => $block) {
            $row = $levelRow($html, $level);

            assert_contains($block, $row, 'level ' . $level . ' is full');
            assert_contains('Paid', $row, 'level ' . $level . ' is paid');
        }

        $level4 = $levelRow($html, 4);
        assert_contains('0 of 81', $level4);
        assert_contains(money(6000.0), $level4);
        assert_contains('In progress', $level4);
        assert_contains('120', $level4, 'level 4 needs 120 teammates');
    },

    /* ==========================================================
     *  P20F-T5 — the roster under the matrix
     * ========================================================== */

    'P20F-T5 the roster lists teammates in qualification order with their position and slot' => function () use (
        $liveTree,
        $teamTab,
        $flat
    ): void {
        $tree = $liveTree('p20f_t5', 3);
        $root = $tree['root'];

        test_signed_in_as(test_user('p20f_t5_root'));

        $tab   = $teamTab();
        $data  = $tab['data'];
        $roster = $data['roster'];

        assert_same(39, $data['roster_pager']->total);
        assert_same(10, count($roster), 'the first page holds ten teammates');

        /* The first teammate is the first member who qualified after the
         * root: the first direct. The slot is 1 (the card counts from
         * one); the position is the member's stored one. */
        $first = $roster[0];
        assert_same(0, (int) $first['slot']);
        assert_same(1, (int) $first['level']);
        assert_same($root, (int) $first['placed_under']['user_id'], 'slot 1 is placed under the member');
        assert_true((int) $first['position'] > (int) $data['team_harvest']['position'], 'a teammate qualified after the member');

        /* The three directs come first, in qualification order. */
        for ($index = 0; $index < 3; $index++) {
            assert_same((int) $tree['levels'][1][$index], (int) $roster[$index]['user_id'], 'roster order is qualification order');
        }

        foreach ($roster as $index => $row) {
            assert_same($index, (int) $row['slot'], 'slot ' . $index . ' is the ' . ($index + 1) . 'th teammate');
        }

        $html = $tab['html'];

        assert_contains('Teammates, in qualification order', $html);
        assert_contains('Showing 1–10 of 39', $html);
        assert_contains('@' . (string) $first['username'], $html);
        assert_contains('Slot 1', $html);
        assert_contains('Level 1', $html);
        assert_contains('roster_page=2', $html, 'the roster links to its second page');
        assert_contains('name="roster_per_page"', $html, 'the roster has its own size control');

        /* Page two, five rows: the card shows that slice only. */
        $second = $teamTab(['roster_page' => '2', 'roster_per_page' => '5']);
        $html2  = $second['html'];

        assert_same(5, count($second['data']['roster']));
        assert_same(5, $second['data']['roster_pager']->offset);
        assert_contains('Showing 6–10 of 39', $html2);
        assert_contains('Slot 6', $html2);
        assert_not_contains('Slot 1<', $html2, 'the first page is gone');

        /* An out-of-range page is clamped, never an empty card. */
        $clamped = $teamTab(['roster_page' => '99', 'roster_per_page' => '10']);

        assert_same(4, $clamped['data']['roster_pager']->page, 'the last real page');
        assert_same(9, count($clamped['data']['roster']));
        assert_contains('Showing 31–39 of 39', $flat($clamped['html']));
    },

    /* ==========================================================
     *  P20F-T6 — a member who has not qualified
     * ========================================================== */

    'P20F-T6 an unqualified member reads the gate, the count, no matrix and no rows' => function () use (
        $account,
        $cleanFixture,
        $disarm,
        $teamTab,
        $flat
    ): void {
        $cleanFixture();
        $disarm();

        $member = $account('p20f_t6_member');

        $account('p20f_t6_d1', $member);
        $account('p20f_t6_d2', $member);

        test_signed_in_as(test_user('p20f_t6_member'));

        $tab  = $teamTab();
        $data = $tab['data'];
        $html = $tab['html'];

        assert_same(false, $data['team_harvest']['queued']);
        assert_same(2, $data['team_harvest']['directs']);
        assert_same(3, $data['team_harvest']['gate']);
        assert_same(0, $data['pager']->total);
        assert_same([], $data['roster']);

        $flatHtml = $flat($html);

        assert_contains('Not qualified yet', $html);
        assert_contains('You are not qualified yet: 2 of 3 direct members.', $flatHtml);
        assert_contains('When your third direct member registers', $flatHtml);

        /* No matrix, no award table — and no region for either. */
        assert_not_contains('Your Team matrix', $html);
        assert_not_contains('Teammates, in qualification order', $html);
        assert_not_contains('Your levels', $html);
        assert_not_contains('data-live-region="earnings-team-matrix"', $html);
        assert_not_contains('data-live-region="earnings-team-rows"', $html);
        assert_not_contains('data-live-region="earnings-team-stats"', $html);

        /* The page still explains the bonus (the FAQ is always there). */
        assert_contains('Team Harvest questions', $html);
        assert_contains('How do I qualify?', $html);

        /* One region on the page, one fragment in the feed. */
        assert_same(['earnings-team-progress'], array_keys($tab['payload']['fragments']));
        assert_contains('data-live-region="earnings-team-progress"', $tab['payload']['fragments']['earnings-team-progress']);
    },

    /* ==========================================================
     *  P20F-T7 — the baseline member
     * ========================================================== */

    'P20F-T7 a pre-go-live level reads Before go-live, stays out of Earned and is dated once' => function () use (
        $baselineTree,
        $growPastBaseline,
        $awards,
        $teamTab,
        $levelRow,
        $awardRow,
        $flat
    ): void {
        $tree = $baselineTree('p20f_t7');
        $root = $tree['root'];

        /* Take the root from n = 12 to n = 39 after go-live, so level 3
         * is credited and levels 1–2 stay baseline. */
        $added = $growPastBaseline($tree, 'p20f_t7_grow', new TeamHarvestService());
        assert_same(108, $added, 'nine depth-2 members each add twelve accounts');

        (new TeamHarvestService())->evaluate([$root]);

        $rows = $awards($root);

        assert_same(3, count($rows));
        assert_same(TeamHarvestAward::STATUS_BASELINE, (string) $rows[0]['status'], 'level 1 was complete at go-live');
        assert_same(TeamHarvestAward::STATUS_BASELINE, (string) $rows[1]['status'], 'level 2 was complete at go-live');
        assert_same(TeamHarvestAward::STATUS_CREDITED, (string) $rows[2]['status'], 'level 3 completed after go-live');
        assert_same(4000.0, (float) $rows[2]['amount']);

        test_signed_in_as(test_user('p20f_t7_root'));

        $tab  = $teamTab();
        $data = $tab['data'];
        $html = $tab['html'];

        assert_same(39, $data['team_harvest']['teammates']);
        assert_same('4000.00', (string) $data['team_harvest']['paid_total'], 'Earned is the credited level only');
        assert_same('3000.00', (string) $data['team_harvest']['baseline_total']);
        assert_same(2, (int) $data['team_harvest']['baseline_levels']);

        /* The stats card: Earned is ₱4,000.00, Before go-live ₱3,000.00. */
        $stats = $tab['payload']['fragments']['earnings-team-stats'];
        assert_contains('Earned', $stats);
        assert_contains(money(4000.0), $stats);
        assert_contains('Before go-live', $stats);
        assert_contains(money(3000.0), $stats);
        assert_not_contains(money(7000.0), $stats, 'a baseline level is never Earned');

        /* The matrix labels the two baseline levels and the paid one. */
        assert_contains('Before go-live', $levelRow($html, 1));
        assert_contains('Before go-live', $levelRow($html, 2));
        assert_contains('Paid', $levelRow($html, 3));

        /* The rows say the baseline level was not paid and link to no
         * movement; the credited level links to its wallet credit. */
        $baselineRow = $awardRow($html, (int) $rows[0]['id']);
        $creditedRow = $awardRow($html, (int) $rows[2]['id']);

        assert_contains('Before go-live', $baselineRow);
        assert_contains('not paid', $baselineRow, 'a baseline level reads not paid');
        assert_not_contains('ref_id=', $baselineRow, 'a baseline level has no movement');
        assert_contains('ref_id=' . (int) $rows[2]['id'], $creditedRow);

        /* The footnote names the date the plan started paying, once. */
        $baseline = (string) $data['team_harvest']['baseline'];
        $footnote = sprintf(TeamHarvestService::BASELINE_COPY, format_date($baseline));

        assert_true($baseline !== '', 'the baseline instant is stored');
        assert_contains(format_date($baseline), $html, 'the footnote names the start date');
        assert_same(1, substr_count($html, e($footnote)), 'the go-live footnote is said once');
        assert_contains($footnote, $flat($html));
    },

    /* ==========================================================
     *  P20F-T8 — the member CSV
     * ========================================================== */

    'P20F-T8 the CSV writes the six columns for the caller only and no audit row' => function () use (
        $liveTree,
        $teamTab,
        $count,
        $awards
    ): void {
        $tree  = $liveTree('p20f_t8', 3);
        $root  = $tree['root'];
        $other = $tree['levels'][1][0];

        test_signed_in_as(test_user('p20f_t8_root'));

        $tab     = $teamTab();
        $filters = $tab['data']['filters'];

        assert_same($root, (int) $filters['user_id'], 'the session member is forced into the filters');

        $auditBefore = $count('audit_log');

        $export = TeamHarvestAward::exportRows($filters, null, $root);

        assert_same(3, count($export), 'only the caller’s levels');
        assert_same($root, (int) $export[0]['user_id']);
        assert_same($auditBefore, $count('audit_log'), 'a member export writes no audit row');
        assert_same(0, $count('audit_log', "action LIKE 'team_harvest.export%'"), 'the member export is not an admin download');

        /* The six documented columns (Appendix C.6). */
        assert_same(
            ['Date', 'Level', 'Teammates at award', 'Threshold', 'Amount', 'Status'],
            EarningsController::teamHarvestCsvHeader()
        );

        $csv = EarningsController::teamHarvestCsvRows($export);

        assert_same(3, count($csv));
        assert_same(6, count($csv[0]));
        assert_same('3', $csv[0][1], 'newest level first');
        assert_same('39', $csv[0][2], 'the teammates the level was judged on');
        assert_same('39', $csv[0][3]);
        assert_same('4000.00', $csv[0][4]);
        assert_same('Paid', $csv[0][5]);

        /* A baseline row is labelled with the member's words. */
        $baseline = TeamHarvestAward::create($root, 9, [
            'threshold_n' => 29523,
            'n_at_award'  => 29523,
            'amount'      => 1200000,
            'status'      => TeamHarvestAward::STATUS_BASELINE,
            'credited_at' => '2026-11-01 00:00:00',
        ]);
        assert_true($baseline > 0);

        $baselineCsv = EarningsController::teamHarvestCsvRows(TeamHarvestAward::exportRows($filters, null, $root));

        /* The baseline row is the oldest, so it sorts last. */
        $last = $baselineCsv[count($baselineCsv) - 1];
        assert_same('9', $last[1]);
        assert_same('Before go-live', $last[5]);

        /* A hand-edited `user_id` cannot widen the file. */
        $_GET = ['ledger' => 'team_harvest', 'user_id' => (string) $other];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=team_harvest&user_id=' . $other;

        $hijacked = (new EarningsController())->teamHarvestContext($root);

        assert_same($root, (int) $hijacked['filters']['user_id']);
        assert_same(4, count(TeamHarvestAward::exportRows($hijacked['filters'], null, $root)));

        foreach (TeamHarvestAward::exportRows($hijacked['filters'], null, $root) as $row) {
            assert_same($root, (int) $row['user_id']);
        }

        assert_same(2, count($awards($other)), 'the other member keeps their own rows');
    },

    /* ==========================================================
     *  P20F-T9 — the other three tabs are unchanged
     * ========================================================== */

    'P20F-T9 the package, repeat and Royalty tabs keep their regions and exports' => function () use (
        $account,
        $cleanFixture
    ): void {
        $cleanFixture();

        $owner = $account('p20f_t9_owner');

        test_signed_in_as(test_user('p20f_t9_owner'));

        $controller = new EarningsController();
        $expected   = [
            EarningsController::TAB_PACKAGE => [
                'earnings-header',
                'earnings-stats',
                'earnings-levels',
                'earnings-skipped',
                'earnings-rows',
                'earnings-pagination',
            ],
            EarningsController::TAB_REPEAT => [
                'earnings-repeat-header',
                'earnings-repeat-stats',
                'earnings-repeat-status',
                'earnings-repeat-levels',
                'earnings-repeat-rows',
                'earnings-repeat-pagination',
            ],
            EarningsController::TAB_ROYALTY => [
                'earnings-royalty-header',
                'earnings-royalty-rank',
                'earnings-royalty-stats',
                'earnings-royalty-rows',
                'earnings-royalty-pagination',
            ],
        ];

        foreach ($expected as $tab => $keys) {
            $_GET = $tab === EarningsController::TAB_PACKAGE ? [] : ['ledger' => $tab];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings'
                . ($_GET === [] ? '' : '?' . http_build_query($_GET));

            $data    = $controller->context($owner, $tab);
            $payload = $controller->feedPayload(3, '"member.earnings:' . $owner . ':3:x"', $data);

            assert_same($tab, $data['tab'], $tab . ' keeps its tab key');
            assert_same($keys, array_keys($payload['fragments']), $tab . ' keeps its fragments');
            assert_not_contains('earnings-team-', implode('', $payload['fragments']), $tab . ' never renders a Team Harvest region');
        }

        /* The three exports keep their names. */
        $_GET = [];
        assert_same(url('earnings/export'), $controller->earningsContext($owner)['export_url']);

        $_GET = ['ledger' => 'repeat'];
        assert_same(url('earnings/export?ledger=repeat'), $controller->repeatContext($owner)['export_url']);

        $_GET = ['ledger' => 'royalty'];
        assert_same(url('earnings/export?ledger=royalty'), $controller->royaltyContext($owner)['export_url']);

        /* The Team Harvest tab is the fourth pill and its own export. */
        $_GET = ['ledger' => 'team_harvest'];
        assert_same(EarningsController::TAB_TEAM_HARVEST, EarningsController::tab());
        assert_same(url('earnings/export?ledger=team_harvest'), $controller->teamHarvestContext($owner)['export_url']);
        assert_same(url('earnings/export?ledger=team_harvest'), $controller->teamHarvestContext($owner)['export_url']);
    },

    /* ==========================================================
     *  P20F-T12 — the words a member never reads
     * ========================================================== */

    'P20F-T12 no new member surface uses a forbidden word' => function () use (
        $liveTree,
        $teamTab,
        $renderPage,
        $account,
        $cleanFixture
    ): void {
        $tree = $liveTree('p20f_t12', 3);
        $root = $tree['root'];

        test_signed_in_as(test_user('p20f_t12_root'));

        $earnings = $teamTab()['html'];

        $dashboardController = new DashboardController();
        $dashboard = $renderPage(
            'member',
            'member/dashboard',
            $dashboardController->dashboardContext($root),
            'dashboard'
        );

        $genealogyController = new GenealogyController();
        $genealogy = $renderPage(
            'member',
            'member/genealogy',
            $genealogyController->genealogyContext(Member::find($root)),
            'genealogy'
        );

        $forbidden = ['spillover', 'passive', 'guaranteed', 'unlimited', 'matrix scheme', 'sponsor chain'];

        foreach (['earnings' => $earnings, 'dashboard' => $dashboard, 'genealogy' => $genealogy] as $surface => $html) {
            $lower = mb_strtolower($html);

            foreach ($forbidden as $word) {
                assert_not_contains($word, $lower, $surface . ' must not say "' . $word . '"');
            }
        }

        /* And the words it must say (Rule 1). */
        assert_contains('Team Harvest', $earnings);
        assert_contains('Harvest position', $earnings);
        assert_contains('Team matrix', $earnings);
    },

    /* ==========================================================
     *  P20F-T13 — the two exclusions, once, on the matrix card
     * ========================================================== */

    'P20F-T13 the two exclusions appear verbatim once on the matrix card and not on the tile' => function () use (
        $liveTree,
        $teamTab,
        $renderPage
    ): void {
        $tree = $liveTree('p20f_t13', 3);
        $root = $tree['root'];

        test_signed_in_as(test_user('p20f_t13_root'));

        $tab  = $teamTab();
        $html = $tab['html'];

        assert_same(1, substr_count($html, TeamHarvestService::EXCLUSIONS_COPY), 'the exclusions are said once');
        assert_contains(
            'Only members who qualify after you count. Direct members who are not qualified do not count.',
            $html
        );
        assert_contains('data-live-region="earnings-team-matrix"', $html);
        assert_same(
            1,
            substr_count($tab['payload']['fragments']['earnings-team-matrix'], TeamHarvestService::EXCLUSIONS_COPY)
        );

        $dashboard = new DashboardController();
        $payload   = $dashboard->feedPayload(
            4,
            '"member.dashboard:' . $root . ':4:x"',
            $dashboard->dashboardContext($root)
        );

        assert_not_contains(TeamHarvestService::EXCLUSIONS_COPY, $payload['fragments']['dashboard-team-harvest']);
    },

    /* ==========================================================
     *  P20F-T14 — the purchases footnote
     * ========================================================== */

    'P20F-T14 the purchases footnote appears once, on the matrix card' => function () use (
        $liveTree,
        $teamTab,
        $renderPage
    ): void {
        $tree = $liveTree('p20f_t14', 3);
        $root = $tree['root'];

        test_signed_in_as(test_user('p20f_t14_root'));

        $tab     = $teamTab();
        $escaped = e(TeamHarvestService::PURCHASES_COPY);

        assert_same(1, substr_count($tab['html'], $escaped), 'the footnote is said once');

        $dashboardController = new DashboardController();
        $dashboard = $renderPage(
            'member',
            'member/dashboard',
            $dashboardController->dashboardContext($root),
            'dashboard'
        );

        assert_same(0, substr_count($dashboard, $escaped), 'the tile never repeats the footnote');
    },

    /* ==========================================================
     *  P20F-T15 — a paid level links to its wallet movement
     * ========================================================== */

    'P20F-T15 a paid level links to the movement that credited it, by ref_id' => function () use (
        $liveTree,
        $teamTab,
        $awardRow,
        $count
    ): void {
        $tree = $liveTree('p20f_t15', 3);
        $root = $tree['root'];

        test_signed_in_as(test_user('p20f_t15_root'));

        $tab    = $teamTab();
        $awards = $tab['data']['awards'];
        $html   = $tab['html'];

        /* The oldest level, level 1, is paid and has a movement. */
        $level1 = null;

        foreach ($awards as $award) {
            if ((int) $award['level'] === 1) {
                $level1 = $award;
            }
        }

        assert_not_null($level1);
        assert_same(TeamHarvestAward::STATUS_CREDITED, (string) $level1['status']);
        assert_not_null($level1['ledger_id']);

        $row = $awardRow($html, (int) $level1['id']);

        assert_contains(
            'ref_type=' . TeamHarvestAward::REF_TYPE . '&amp;ref_id=' . (int) $level1['id'],
            $row,
            'the row links to its own movement'
        );

        /* The statement the link lands on: exactly that movement. */
        $statement = (new WalletService())->statement(
            $root,
            Wallet::normaliseFilters([
                'ref_type' => TeamHarvestAward::REF_TYPE,
                'ref_id'   => (string) (int) $level1['id'],
            ]),
            1
        );

        assert_same(1, $statement['pager']->total, 'one movement for that level');
        assert_same((int) $level1['ledger_id'], (int) $statement['rows'][0]['id'], 'the movement the award names');
        assert_same((int) $level1['id'], (int) $statement['rows'][0]['ref_id'], 'the award id is the movement ref');
        assert_same(1000.0, (float) $statement['rows'][0]['amount']);

        /* The same member’s three movements still list as a group. */
        $all = (new WalletService())->statement(
            $root,
            Wallet::normaliseFilters(['ref_type' => TeamHarvestAward::REF_TYPE]),
            1
        );

        assert_same(3, $all['pager']->total);
        assert_same(3, $count('ewallet_ledger', "user_id = " . $root . " AND ref_type = 'team_harvest'"));
    },

    /* ==========================================================
     *  P20F-T16 — a member can only ever read their own rows
     * ========================================================== */

    'P20F-T16 ?user_id= is ignored by the tab and by the CSV' => function () use (
        $liveTree,
        $teamTab
    ): void {
        $tree  = $liveTree('p20f_t16', 3);
        $root  = $tree['root'];
        $other = $tree['levels'][1][0];

        test_signed_in_as(test_user('p20f_t16_root'));

        $clean = $teamTab();

        assert_same(3, $clean['data']['pager']->total);

        $_GET = ['ledger' => 'team_harvest', 'user_id' => (string) $other];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=team_harvest&user_id=' . $other;

        $hijacked = $teamTab();
        $data     = $hijacked['data'];

        assert_same($root, (int) $data['filters']['user_id'], 'the session member wins');
        assert_same(3, $data['pager']->total, 'the other member’s rows never appear');
        assert_same(3, count($data['awards']));

        foreach ($data['awards'] as $award) {
            assert_same($root, (int) $award['user_id']);
        }

        $otherIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            TeamHarvestAward::forMember($other)
        );
        $shownIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $data['awards']
        );

        assert_same([], array_intersect($otherIds, $shownIds), 'no other member award id is rendered');

        $export = EarningsController::teamHarvestCsvRows(
            TeamHarvestAward::exportRows($data['filters'], null, $root)
        );

        assert_same(3, count($export));
        assert_same('3', $export[0][1], 'the newest level is still the caller’s');
    },

    /* ==========================================================
     *  P20F-T18 — 390 px: the tables scroll inside their cards
     * ========================================================== */

    'P20F-T18 the matrix, roster and level tables scroll inside their cards' => function () use (
        $liveTree,
        $teamTab
    ): void {
        $tree = $liveTree('p20f_t18', 3);

        test_signed_in_as(test_user('p20f_t18_root'));

        $html = $teamTab()['html'];

        /* Every table that can exceed the viewport is inside a
         * `.table-responsive` wrapper, and that wrapper is inside the
         * card — so the page itself never scrolls sideways. */
        assert_same(
            1,
            preg_match('/<div class="table-responsive">\s*<table class="table table-hover align-middle mb-0 commission-table/s', $html),
            'the matrix table is wrapped'
        );
        assert_same(
            1,
            preg_match('/<div class="table-responsive">\s*<table class="table table-sm table-hover align-middle mb-0/s', $html),
            'the roster table is wrapped'
        );
        assert_same(
            1,
            preg_match('/<div class="table-responsive">\s*<table class="table table-hover align-middle commission-table mb-0/s', $html),
            'the level rows are wrapped'
        );

        /* No page-wide overflow hacks and no fixed table widths. */
        assert_not_contains('overflow-x: hidden', $html);
        assert_not_contains('<table style=', $html);
        assert_not_contains('min-width: 1000', $html);

        /* The card layout is the responsive grid the other tabs use. */
        assert_contains('col-sm-6 col-xl', $html);
        assert_contains('data-live-region="earnings-team-matrix"', $html);
    },

    /* ==========================================================
     *  P20F-T19 — 500 teammates: the roster paginates, the matrix
     *              does not grow, and the reads are constant
     * ========================================================== */

    'P20F-T19 a 500-teammate member reads ten matrix levels and one roster page' => function () use (
        $bigTeam,
        $teamTab,
        $selects
    ): void {
        $root = $bigTeam('p20f_t19', 500);

        test_signed_in_as(test_user('p20f_t19_root'));

        $_GET = ['ledger' => 'team_harvest'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=team_harvest';

        $controller = new EarningsController();

        $before = $selects();
        $data   = $controller->teamHarvestContext($root);
        $used   = $selects() - $before;

        assert_same(500, $data['team_harvest']['teammates']);
        assert_same(500, $data['roster_pager']->total);
        assert_same(10, count($data['roster']), 'the roster shows one page');
        assert_same(10, count($data['team_harvest']['levels']), 'the matrix stays ten levels');

        /* The cost is a page's, not a team's: page 2 costs the same. */
        $_GET = ['ledger' => 'team_harvest', 'roster_page' => '2'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=team_harvest&roster_page=2';

        $before2 = $selects();
        $pageTwo = $controller->teamHarvestContext($root);
        $used2   = $selects() - $before2;

        assert_same(10, count($pageTwo['roster']));
        assert_same($used, $used2, 'a later roster page costs the same reads');

        /* §14.9's budget is 8 for the matrix card; the 20C derivation adds
         * the children map and the queue map for any team size (deviation
         * recorded in docs/phase-log.md). Constant, never per-row. */
        assert_true($used <= 10, 'the tab uses ' . $used . ' selects (budget 10, plan 8)');

        /* The page renders the first page only, with the total. */
        $html = $teamTab()['html'];

        assert_contains('Showing 1–10 of 500', $html);
        assert_contains('500 teammates', $html);
    },

    /* ==========================================================
     *  P20F-T20 — every amount is the ladder's; beyond the depth
     *              completes but does not pay
     * ========================================================== */

    'P20F-T20 the card shows the configured amount for every level and marks a depth-complete level' => function () use (
        $cleanFixture,
        $arm,
        $grow,
        $depth,
        $reference,
        $teamTab,
        $levelRow,
        $awards
    ): void {
        $cleanFixture();
        $depth(2);          // only levels 1–2 may pay
        $arm();

        $tree = $grow(3, 'p20f_t20', new TeamHarvestService());
        $root = $tree['root'];

        test_signed_in_as(test_user('p20f_t20_root'));

        $tab    = $teamTab();
        $status = $tab['data']['team_harvest'];
        $html   = $tab['html'];

        assert_same(39, $status['teammates']);
        assert_same(2, (int) $tab['data']['pager']->total, 'only two levels may pay');

        foreach ($reference as $level => $amount) {
            assert_same($amount, (float) $status['levels'][$level]['amount'], 'level ' . $level . ' amount');
            assert_contains(money($amount), $levelRow($html, $level), 'level ' . $level . ' renders its amount');
        }

        assert_same(TeamHarvestService::LEVEL_PAID, (string) $status['levels'][2]['status']);
        assert_same(TeamHarvestService::LEVEL_BEYOND_DEPTH, (string) $status['levels'][3]['status']);
        assert_contains('Complete — not paying at this depth', $levelRow($html, 3));
        assert_contains('27 of 27', $levelRow($html, 3), 'the block is full');
        assert_not_contains('Paid', $levelRow($html, 3), 'a level beyond the depth is not paid');

        /* No award row was written for level 3. */
        assert_same(2, count($awards($root)));

        /* A level that is neither complete nor payable still reads its
         * amount and In progress. */
        assert_contains('In progress', $levelRow($html, 4));
        assert_contains(money($reference[4]), $levelRow($html, 4));
    },

    /* ==========================================================
     *  P20F-T21 — the stream is off
     * ========================================================== */

    'P20F-T21 with the stream off the card keeps the position and progress and says so' => function () use (
        $cleanFixture,
        $arm,
        $grow,
        $teamTab,
        $awards
    ): void {
        $cleanFixture();
        $arm('2026-11-01 00:00:00', null, false);
        $grow(3, 'p20f_t21', new TeamHarvestService());

        $root = test_user('p20f_t21_root');

        test_signed_in_as($root);

        $tab  = $teamTab();
        $data = $tab['data'];
        $html = $tab['html'];

        assert_same(false, $data['team_harvest']['enabled']);
        assert_same(true, $data['team_harvest']['queued'], 'the queue still recorded the position');
        assert_same(39, $data['team_harvest']['teammates']);
        assert_same(0, $data['pager']->total, 'nothing was paid');
        assert_same([], $awards((int) $root['id']));

        assert_contains('The Team Harvest bonus is not currently paying.', $html);
        assert_contains('recorded and will count when it is switched on', $html);
        assert_contains('Harvest position #', $html);
        assert_contains('Level 4', $html, 'the progress is still stated');
        assert_contains('Your Team matrix', $html);
        assert_contains('No level has completed yet', $html);
    },

    /* ==========================================================
     *  P20F-T22 — four tabs on one route, and no new route
     * ========================================================== */

    'P20F-T22 the fourth tab rides the existing route, the other three keep their URLs' => function () use (
        $appRoot,
        $account,
        $cleanFixture
    ): void {
        $cleanFixture();

        $owner = $account('p20f_t22_owner');

        $controller = new EarningsController();

        /* One query parameter, four tabs, and an unknown value is the
         * package statement — no new route anywhere (D-16, D-14). */
        $map = [
            ''                 => EarningsController::TAB_PACKAGE,
            'package'          => EarningsController::TAB_PACKAGE,
            'repeat'           => EarningsController::TAB_REPEAT,
            'royalty'          => EarningsController::TAB_ROYALTY,
            'team_harvest'     => EarningsController::TAB_TEAM_HARVEST,
            'TEAM_HARVEST'     => EarningsController::TAB_TEAM_HARVEST,
            'team-harvest'     => EarningsController::TAB_PACKAGE,
            'something-else'   => EarningsController::TAB_PACKAGE,
        ];

        foreach ($map as $ledger => $expected) {
            $_GET = $ledger === '' ? [] : ['ledger' => $ledger];

            assert_same($expected, EarningsController::tab(), 'ledger=' . $ledger);
        }

        foreach ([
            EarningsController::TAB_PACKAGE,
            EarningsController::TAB_REPEAT,
            EarningsController::TAB_ROYALTY,
            EarningsController::TAB_TEAM_HARVEST,
        ] as $tab) {
            $_GET = $tab === EarningsController::TAB_PACKAGE ? [] : ['ledger' => $tab];

            assert_same($tab, $controller->context($owner, $tab)['tab'], $tab . ' builds its own context');
        }

        /* The route table and the inventory gained nothing. */
        foreach (['/app/routes.php', '/app/routes_inventory.php'] as $file) {
            $source = (string) file_get_contents($appRoot . '/' . ltrim($file, '/'));

            assert_not_contains('team_harvest', $source, $file . ' names no Team Harvest route');
            assert_not_contains('team-harvest', $source, $file . ' names no Team Harvest route');
        }

        /* The three pre-existing exports are untouched. */
        $_GET = [];
        assert_same(url('earnings/export'), $controller->earningsContext($owner)['export_url']);
        $_GET = ['ledger' => 'repeat'];
        assert_same(url('earnings/export?ledger=repeat'), $controller->repeatContext($owner)['export_url']);
        $_GET = ['ledger' => 'royalty'];
        assert_same(url('earnings/export?ledger=royalty'), $controller->royaltyContext($owner)['export_url']);
        $_GET = ['ledger' => 'team_harvest'];
        assert_same(url('earnings/export?ledger=team_harvest'), $controller->teamHarvestContext($owner)['export_url']);
    },
];
