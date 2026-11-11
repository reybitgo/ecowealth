<?php
declare(strict_types=1);

/* ==============================================================
 *  ReportViewTest — the Phase 15 reports screen and health widget
 *  (plan §7 Phase 15, §6.8)
 *
 *  Renders the real template with the real service context, so the
 *  page, its live fragments and its navigation entry are all checked
 *  against the same data the controller builds.
 * ============================================================== */

$renderReports = static function (array $overrides = []): string {
    $range = ReportService::rangeFromRequest();
    $data  = (new ReportService())->page($range) + [
        'page_title'   => 'Reports',
        'current_nav'  => 'admin.reports',
        'page_css'     => ['assets/css/reports.css'],
        'page_js'      => ['assets/js/admin-reports.js'],
        'live'         => Live::meta('admin.reports', 'test'),
        'export_types' => ReportService::EXPORTS,
        'export_url'   => url('admin/reports/export'),
        'canonical'    => url('admin/reports'),
    ];

    ob_start();

    try {
        View::page('admin', 'admin/reports/index', array_merge($data, $overrides));
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

return [
    'P15 the reports page renders every report and its export menu' => function () use ($renderReports): void {
        test_signed_in_as(test_user('rpt_admin', 'admin'));

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports';

        $html = $renderReports();

        assert_contains('data-live-surface="admin.reports"', $html);
        assert_contains('Reports', $html);
        assert_contains('Sales by day', $html);
        assert_contains('Commissions by level', $html);
        assert_contains('Payout register', $html);
        assert_contains('Registration-code revenue', $html);
        assert_contains('Finance health', $html);

        /* One export entry per report type, all carrying the window. */
        foreach (array_keys(ReportService::EXPORTS) as $type) {
            assert_contains('type=' . $type, $html, 'the ' . $type . ' export is offered');
        }

        /* The date window is editable and defaults to 30 days. */
        assert_contains('name="from"', $html);
        assert_contains('name="to"', $html);
        assert_contains('Last 30 days', $html);

        /* Chart containers and live regions. */
        assert_contains('data-live-region="report-kpis"', $html);
        assert_contains('data-live-region="report-health"', $html);
        assert_contains('data-live-region="report-sales"', $html);
        assert_contains('data-live-region="report-commissions"', $html);
        assert_contains('data-live-region="report-payouts"', $html);
        assert_contains('data-live-region="report-codes"', $html);
        assert_contains('window.ECOWEALTH_REPORTS', $html);
    },

    'P15 a report window comes from the query and is echoed in the form' => function () use ($renderReports): void {
        test_signed_in_as(test_user('rpt_admin', 'admin'));

        $_GET = ['from' => '2026-01-05', 'to' => '2026-01-10'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports?from=2026-01-05&to=2026-01-10';

        $html = $renderReports();

        assert_contains('value="2026-01-05"', $html);
        assert_contains('value="2026-01-10"', $html);
        assert_contains('Jan 5, 2026 – Jan 10, 2026', $html);
        assert_contains('6 days', $html);
    },

    'P15 the reports page escapes product and member data' => function () use ($renderReports): void {
        test_signed_in_as(test_user('rpt_admin', 'admin'));

        $user = test_user('rpt_hostile', 'member');
        $productId = (int) db()->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();

        $orderId = test_order((int) $user['id'], [['product_id' => $productId, 'quantity' => 1]], 'paid', [
            'total'   => 100.00,
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        db()->prepare('UPDATE shop_order_items SET product_name = ?, line_total = 100.00 WHERE order_id = ?')
            ->execute(['<script>alert("x")</script>', $orderId]);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports';

        $html = $renderReports();

        assert_contains('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        assert_not_contains('<script>alert("x")</script>', $html);
    },

    'P15 the reports feed returns eight fragments and chart data' => function (): void {
        test_signed_in_as(test_user('rpt_admin', 'admin'));

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports';

        $controller = new AdminReportController();
        $payload    = $controller->feedPayload(3, '"admin.reports:3:abc"', $controller->pageContext());

        assert_true($payload['ok']);
        assert_same('admin.reports', $payload['surface']);
        assert_same(
            [
                'report-health',
                'report-kpis',
                'report-sales',
                'report-top-products',
                'report-commissions',
                'report-payouts',
                'report-codes',
                'report-orders',
            ],
            array_keys($payload['fragments'])
        );

        assert_contains('data-live-region="report-health"', $payload['fragments']['report-health']);
        assert_contains('Wallet balances', $payload['fragments']['report-health']);
        assert_contains('Stock availability', $payload['fragments']['report-health']);
        assert_contains('data-live-region="report-kpis"', $payload['fragments']['report-kpis']);
        assert_contains('Sales in window', $payload['fragments']['report-kpis']);
        assert_contains('data-live-region="report-sales"', $payload['fragments']['report-sales']);

        /* The chart payload is three parallel arrays of the same length. */
        $chart = $payload['data']['sales'];
        assert_same(count($chart['labels']), count($chart['values']));
        assert_same(count($chart['labels']), count($chart['orders']));
        assert_same(30, count($chart['labels']), 'the default window has 30 points');
    },

    'P15 the health widget reports every invariant and passes on a clean fixture' => function (): void {
        $health = (new HealthService())->checks();

        assert_same(7, $health['totals']['checks']);
        assert_same(0, $health['totals']['failing'], 'the seeded fixture reconciles');
        assert_true($health['ok']);

        $keys = array_map(static fn (array $section): string => $section['key'], $health['sections']);
        assert_same(
            ['wallets', 'commissions', 'awards', 'transfers', 'payouts', 'orders', 'stock'],
            $keys
        );

        foreach ($health['sections'] as $section) {
            assert_true($section['ok'], $section['key'] . ' should be clean');
            assert_true($section['summary'] !== '', $section['key'] . ' explains itself');
            assert_true($section['label'] !== '');
        }
    },

    'P15 the health widget flags a broken order total' => function (): void {
        $healthBefore = (new HealthService())->checks();
        assert_true($healthBefore['ok']);

        $user    = test_user('rpt_broken', 'member');
        $orderId = test_order((int) $user['id'], [], 'paid', ['total' => 100.00, 'subtotal' => 100.00]);

        /* The order claims ₱100 but its single line is ₱90: the exact
         * defect invariant #4 exists to catch. */
        db()->prepare('UPDATE shop_order_items SET line_total = 90.00 WHERE order_id = ?')->execute([$orderId]);

        $health = (new HealthService())->checks();
        $orders = null;

        foreach ($health['sections'] as $section) {
            if ($section['key'] === 'orders') {
                $orders = $section;
            }
        }

        assert_not_null($orders);
        assert_false($orders['ok']);
        assert_contains('differs', $orders['summary']);
        assert_false($health['ok']);
        assert_same(1, $health['totals']['failing']);
    },

    'P15 the admin menu offers Reports and the Audit log in phase 15' => function (): void {
        assert_true(APP_PHASE >= 15, 'this build is phase 15');

        $config = nav_config();
        $items  = [];

        foreach ($config['admin']['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $items[$item['key']] = $item;
            }
        }

        assert_true(isset($items['admin.reports']), 'Reports is in the back-office menu');
        assert_true(isset($items['admin.audit']), 'Audit log is in the back-office menu');
        assert_same('admin/reports', $items['admin.reports']['path']);
        assert_same('admin/audit', $items['admin.audit']['path']);
        assert_same('admin', $items['admin.reports']['guard']);
        assert_same(15, $items['admin.reports']['phase']);
        assert_true(nav_item_live($items['admin.reports']));
        assert_true(nav_item_live($items['admin.audit']));

        /* The sidebar highlights the right entry from a deep URL. */
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports?from=2026-01-01';
        assert_same('admin.reports', nav_current_key('admin', test_user('rpt_admin', 'admin')));

        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/audit';
        assert_same('admin.audit', nav_current_key('admin', test_user('rpt_admin', 'admin')));

        $_SERVER['REQUEST_URI'] = '/';
    },
];
