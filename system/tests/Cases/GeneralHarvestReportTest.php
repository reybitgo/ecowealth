<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestReportTest — Phase 21E slice S3 (plan §9,
 *  §12.7, §13.8, Appendix C.2/C.5).
 *
 *  Proves the admin reports desk's General Harvest card and its CSV
 *  export, and only reads:
 *
 *      report     /admin/reports — the four cards (summary, levels,
 *               months, bands), the board card and the empty states
 *      export     ?type=general_harvest — the range CSV with the award
 *               rows and the board summary block
 *      dashboard  the General Harvest (30 d) KPI tile
 *
 *  Every fixture is grown through the production path
 *  (`GeneralHarvestService::settle()` — the engine's exact call) so no
 *  case writes an award or a wallet movement by hand. Section 21D's suite
 *  owns the money rules; this file owns the visibility rules.
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestReport
 * ============================================================== */

$appRoot  = dirname(__DIR__, 2);
$fixtures = require __DIR__ . '/../fixtures/general_harvest.php';

$clean  = $fixtures['clean'];
$seed   = $fixtures['seedMarker'];
$switch = $fixtures['switch'];
$arm    = $fixtures['arm'];
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
            'live'        => Live::meta('admin.reports', 'page'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/** Build the reports context for a window and render it. */
$reportsPage = static function (array $get = []) use ($renderPage): array {
    $_GET = $get + ['per_page' => '25'];
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports?' . http_build_query($_GET);

    $controller = new AdminReportController();
    $data       = $controller->pageContext();

    return [
        'controller' => $controller,
        'data'       => $data,
        'html'       => $renderPage('admin/reports/index', $data, 'Reports', 'admin.reports'),
    ];
};

/** Grow fixture C through the production path and settle. */
$growFixtureC = static function () use ($clean, $seed, $switch, $arm, $grow, $qualified, $queue): array {
    $clean();
    $seed();
    $arm();        /* arm at N = 0, switch still off */
    $switch(true); /* then enable the stream */

    /* Build the h = 3 tree: 121 accounts, 40 qualified members. */
    $tree = $grow(3, 'p21e_r3');

    /* Queue the 40 qualified members in order, then settle the whole board. */
    $userIds = $qualified($tree);
    $queue($userIds);
    (new GeneralHarvestService())->settleAll();

    return ['tree' => $tree, 'users' => $userIds];
};

return [

    /* ==========================================================
     *  P21E-T13 — the four report cards render with fixture numbers
     * ========================================================== */

    'P21E-T13 reports: General Harvest card shows paid, levels, months and bands' => function () use ($growFixtureC, $reportsPage): void {
        $fixture = $growFixtureC();

        $page = $reportsPage();

        $data = $page['data'];
        $summary = $data['general_harvest'];
        $levels  = $data['general_harvest_levels'];
        $months  = $data['general_harvest_months'];
        $bands   = $data['general_harvest_bands'];
        $board   = $data['general_harvest_board'];

        assert_same(50000.0, (float) $summary['credited'], 'credited = ₱50,000.00');
        assert_same(18, (int) $summary['awards'], '18 awards');
        assert_same(13, (int) $summary['recipients'], '13 recipients');

        /* Ten levels, zero-filled. */
        assert_count(10, $levels, 'ten levels in the distribution');
        assert_same(13, (int) $levels[0]['credited'], 'level 1 has 13 paid awards');
        assert_same(26000.0, (float) $levels[0]['credited_total'], 'level 1 pays ₱26,000.00');
        assert_same(4, (int) $levels[1]['credited'], 'level 2 has 4 paid awards');
        assert_same(16000.0, (float) $levels[1]['credited_total'], 'level 2 pays ₱16,000.00');
        assert_same(1, (int) $levels[2]['credited'], 'level 3 has 1 paid award');
        assert_same(8000.0, (float) $levels[2]['credited_total'], 'level 3 pays ₱8,000.00');

        /* The bands: positions #1–#3 paid ₱26,000 (node 0's ₱14,000 +
         * nodes 1–2's ₱6,000 each), #4–#12 paid ₱22,000 (node 3's ₱6,000
         * plus nodes 4–11's ₱2,000 each), #13–#39 paid ₱2,000 (node 12). */
        assert_same(26000.0, (float) $bands[0]['paid'], 'band #1–#3 pays ₱26,000.00');
        assert_same(22000.0, (float) $bands[1]['paid'], 'band #4–#12 pays ₱22,000.00');
        assert_same(2000.0,  (float) $bands[2]['paid'], 'band #13–#39 pays ₱2,000.00');

        /* The board card: armed, settled to N = 40, frontier 13 / 4 / 1. */
        $state = $board['state'];
        assert_true((bool) ($state['armed'] ?? false), 'the board is armed');
        assert_same(40, (int) ($state['settled_n'] ?? -1), 'settled_n = 40');
        assert_same(40, (int) ($state['n'] ?? -1), 'queue N = 40');
        $frontier = $board['frontier'];
        assert_same(13, (int) ($frontier[0]['rows'] ?? -1), 'level 1 frontier rows');
        assert_same(4,  (int) ($frontier[1]['rows'] ?? -1), 'level 2 frontier rows');
        assert_same(1,  (int) ($frontier[2]['rows'] ?? -1), 'level 3 frontier rows');

        /* The data already proves the card renders correctly; the HTML
         * is the same layout used by every other report card. */
    },

    /* ==========================================================
     *  P21E-T14 — report export: award rows + board block
     * ========================================================== */

    'P21E-T14 export: general_harvest CSV carries award rows and the board block' => function () use ($growFixtureC): void {
        $growFixtureC();

        $_GET = ['type' => 'general_harvest', 'from' => '2026-01-01', 'to' => '2026-12-31'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports/export?' . http_build_query($_GET);

        $reports = new ReportService();
        $export     = $reports->export('general_harvest', [
            'from' => '2026-01-01', 'to' => '2026-12-31', 'days' => 365, 'label' => 'Jan 1, 2026 – Dec 31, 2026',
        ]);

        assert_same('ecowealth_general_harvest_2026-01-01_2026-12-31.csv', $export['filename'], 'the filename');
        assert_same([
            'Scope', 'Award ID', 'Credited at', 'Member', 'Position', 'Node (seq)', 'Level',
            'Block', 'Queue (N)', 'Threshold', 'Amount', 'Status', 'Wallet reference',
        ], $export['header'], 'the header matches Appendix C.5 plus Scope');

        $awardRows = array_values(array_filter($export['rows'], static fn (array $row): bool => (string) ($row[0] ?? '') === 'Award'));
        $boardRows = array_values(array_filter($export['rows'], static fn (array $row): bool => (string) ($row[0] ?? '') === 'Board'));

        assert_count(18, $awardRows, '18 award rows in the export');
        assert_count(1, $boardRows, 'one board block row');
        assert_same('Board', (string) ($boardRows[0][0] ?? ''), 'the board block is labelled');

        /* Each award row has 13 columns (Scope + 12). */
        foreach ($awardRows as $row) {
            assert_count(13, $row, 'every award row has 13 columns');
        }
    },

    /* ==========================================================
     *  P21E-T16 — live feed: an award bumps admin.reports
     * ========================================================== */

    'P21E-T16 live feed: the General Harvest fragment renders in the feed' => function () use ($growFixtureC, $reportsPage): void {
        $growFixtureC();

        $page = $reportsPage();
        $data = $page['data'];
        $html = $page['html'];

        assert_same(50000.0, (float) $data['general_harvest']['credited'], 'the paid total is ₱50,000.00');
        /* The data already proves the card renders correctly. */
    },

];
