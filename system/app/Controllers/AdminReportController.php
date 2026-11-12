<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminReportController — the finance reports desk
 *  (plan §7 Phase 15; Appendix A route extension)
 *
 *  Routes:
 *      GET /admin/reports        the report screen
 *      GET /admin/reports/export one CSV (sales | commissions |
 *                                wallet | payouts | codes)
 *
 *  Every number comes from the Report read model through
 *  ReportService, so the screen, the CSV and the dashboards can
 *  never disagree. The health card is HealthService's reconciliation
 *  (plan §6.8) — the same checks `bin/wallet_reconcile.php` runs,
 *  plus order totals, stock availability and award integrity.
 *
 *  The screen is one live surface (`admin.reports`) whose scope mixes
 *  the revisions of the surfaces whose writes change a report (orders,
 *  commissions, wallets, payouts, settings). An order transition or a
 *  settings save therefore refreshes an open reports tab without a
 *  write hook in every service.
 * ============================================================== */

final class AdminReportController
{
    private ReportService $reports;

    public function __construct()
    {
        $this->reports = new ReportService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        if (Live::isFeedRequest()) {
            $this->feed();
            return;
        }

        View::page('admin', 'admin/reports/index', $this->pageContext() + [
            'page_title'  => 'Reports',
            'current_nav' => 'admin.reports',
            'page_css'    => ['assets/css/reports.css'],
            'page_js'     => [
                'assets/vendor/libs/apex-charts/apexcharts.js',
                'assets/js/admin-reports.js',
            ],
            'live'        => Live::meta('admin.reports', $this->scope()),
        ]);
    }

    /**
     * Everything the screen renders, built once for the page and its feed.
     *
     * @return array<string, mixed>
     */
    public function pageContext(): array
    {
        $range = ReportService::rangeFromRequest();

        return $this->reports->page($range) + [
            'export_types' => ReportService::EXPORTS,
            'export_url'   => url('admin/reports/export'),
            'canonical'    => url('admin/reports'),
        ];
    }

    /**
     * GET /admin/reports/export — one CSV for the selected window.
     *
     * @param array<string, string> $params
     */
    public function export(array $params = []): void
    {
        $type  = strtolower(trim(Request::string('type')));
        $range = ReportService::rangeFromRequest();

        if (!isset(ReportService::EXPORTS[$type])) {
            flash_set('danger', 'Unknown report export.');
            redirect('admin/reports?from=' . rawurlencode($range['from']) . '&to=' . rawurlencode($range['to']));
        }

        $export = $this->reports->export($type, $range);
        $admin  = auth_user();

        /* A download is a read, but it copies a slice of the money
         * ledger off the system, so it is audited like an export. */
        AuditLog::record([
            'action'       => 'report.export',
            'actor_id'     => $admin === null ? null : (int) $admin['id'],
            'actor_role'   => $admin === null ? null : (string) $admin['role'],
            'subject_type' => 'report',
            'subject_id'   => null,
            'meta'         => [
                'type'   => $type,
                'from'   => $range['from'],
                'to'     => $range['to'],
                'rows'   => count($export['rows']),
            ],
        ]);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, $export['header']);

        foreach ($export['rows'] as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /* ----------------------------------------------------------
     *  Live feed
     * ---------------------------------------------------------- */

    /**
     * ETag scope: the window, today's date (the range defaults and the
     * relative labels), and every surface whose write changes a number.
     */
    private function scope(?int $userId = null): string
    {
        $userId ??= (int) (auth_user()['id'] ?? 0);

        return Live::scope($_GET, $userId)
            . '|day:' . date('Y-m-d')
            . '|orders:' . ($this->revisionOrZero('admin.orders'))
            . '|commissions:' . ($this->revisionOrZero('admin.commissions'))
            . '|wallets:' . ($this->revisionOrZero('admin.wallets'))
            . '|payouts:' . ($this->revisionOrZero(Live::ADMIN_PAYOUTS))
            . '|config:' . ($this->revisionOrZero(Live::CONFIG));
    }

    private function revisionOrZero(string $surface): int
    {
        return Live::revisionOrZero($surface);
    }

    /**
     * JSON feed for the open reports tab.
     *
     * The 304 check runs before pageContext(), so an unchanged report
     * costs the revision lookups only — no aggregates, no health checks.
     */
    private function feed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        session_write_close();

        $surface = 'admin.reports';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->scope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->pageContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] reports live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the reports. Please try again.', 500);
        }
    }

    /**
     * Render the feed fragments. Public so tests can assert them without
     * going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        return [
            'ok'        => true,
            'surface'   => 'admin.reports',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'report-health' => View::capture('admin/reports/_health', [
                    'health' => $data['health'],
                ]),
                'report-kpis' => View::capture('admin/reports/_kpis', [
                    'sales'       => $data['sales'],
                    'sales_today' => $data['sales_today'],
                    'sales_30d'   => $data['sales_30d'],
                    'commissions' => $data['commissions'],
                    'wallet'      => $data['wallet'],
                    'payouts'     => $data['payouts'],
                    'codes'       => $data['codes'],
                    'range'       => $data['range'],
                ]),
                'report-sales' => View::capture('admin/reports/_sales', [
                    'sales'           => $data['sales'],
                    'sales_by_day'    => $data['sales_by_day'],
                    'sales_by_method' => $data['sales_by_method'],
                    'range'           => $data['range'],
                ]),
                'report-top-products' => View::capture('admin/reports/_top_products', [
                    'top_products' => $data['top_products'],
                    'range'        => $data['range'],
                ]),
                'report-commissions' => View::capture('admin/reports/_commissions', [
                    'commissions' => $data['commissions'],
                    'levels'      => $data['levels'],
                    'range'       => $data['range'],
                ]),
                'report-payouts' => View::capture('admin/reports/_payouts', [
                    'payouts' => $data['payouts'],
                    'range'   => $data['range'],
                ]),
                'report-codes' => View::capture('admin/reports/_codes', [
                    'codes' => $data['codes'],
                ]),
                'report-orders' => View::capture('admin/reports/_orders', [
                    'orders'      => $data['orders'],
                    'open_orders' => $data['open_orders'],
                ]),
                'report-unilevel' => View::capture('admin/reports/_unilevel', [
                    'unilevel' => $data['unilevel'],
                    'range'    => $data['range'],
                ]),
                'report-unilevel-levels' => View::capture('admin/reports/_unilevel_levels', [
                    'unilevel_levels' => $data['unilevel_levels'],
                    'range'           => $data['range'],
                ]),
                'report-unilevel-products' => View::capture('admin/reports/_unilevel_products', [
                    'unilevel_products' => $data['unilevel_products'],
                    'range'             => $data['range'],
                ]),
                'report-unilevel-periods' => View::capture('admin/reports/_unilevel_periods', [
                    'unilevel_periods' => $data['unilevel_periods'],
                ]),
            ],
            'data'      => [
                'sales' => [
                    'labels' => array_map(
                        static fn (array $day): string => date('M j', strtotime($day['day'] . ' 12:00:00')),
                        $data['sales_by_day']
                    ),
                    'values' => array_map(
                        static fn (array $day): float => (float) $day['amount'],
                        $data['sales_by_day']
                    ),
                    'orders' => array_map(
                        static fn (array $day): int => (int) $day['orders'],
                        $data['sales_by_day']
                    ),
                ],
            ],
        ];
    }
}
