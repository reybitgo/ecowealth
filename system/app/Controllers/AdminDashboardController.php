<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminDashboardController — back-office landing page
 *  (reference: admin). Phase 1 shipped account counts; Phase 3
 *  adds the live catalogue, stock and packaging picture the
 *  operations screen needs. Phase 15 adds the commerce and finance
 *  roll-up (sales today/30d, orders by status, top products,
 *  commissions paid, wallet float and the payout hold) from the
 *  same ReportService the reports desk uses, so the two screens can
 *  never disagree.
 *
 *  The same URL doubles as its JSON feed (`X-Live: 1`). The KPI
 *  cards, commerce cards, catalogue chart, stock health card, orders
 *  table, top products, recent accounts and package list are separate
 *  live regions; the browser patches only the regions that changed.
 * ============================================================== */

final class AdminDashboardController
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

        $data = $this->dashboardContext();

        View::page('admin', 'admin/dashboard', [
            'page_title'  => 'Dashboard',
            'current_nav' => 'admin.dashboard',
            'page_css'    => ['assets/vendor/libs/apex-charts/apex-charts.css'],
            'page_js'     => [
                'assets/vendor/libs/apex-charts/apexcharts.js',
                'assets/js/admin-dashboard.js',
            ],
            'stats'       => $data['stats'],
            'packages'    => $data['packages'],
            'recent'      => $data['recent'],
            'chart'       => $data['chart'],
            'stock'       => $data['stock'],
            'attention'   => $data['attention'],
            'chart_data'  => $data['chart_data'],
            'commerce'    => $data['commerce'],
            'nav_notes'   => $data['notes'],
            'live'        => $this->liveMeta(),
        ]);
    }

    /**
     * Everything the dashboard renders, built once for the page and its
     * feed. Each block stays independently guarded so a missing later-phase
     * table can never blank the whole screen.
     *
     * @return array{
     *   stats: array<string, int|float>,
     *   packages: list<array<string, mixed>>,
     *   recent: list<array<string, mixed>>,
     *   chart: array{labels: list<string>, values: list<float>, stock: list<int>},
     *   stock: array{healthy:int, low:int, out:int},
     *   attention: list<array<string, mixed>>,
     *   notes: list<array<string, string>>,
     *   commerce: array<string, mixed>,
     *   chart_data: array{
     *     catalogue: array{labels: list<string>, values: list<float>},
     *     stock: array{labels: list<string>, values: list<int>}
     *   }
     * }
     */
    private function dashboardContext(): array
    {
        $screen = [
            'accounts'         => 0,
            'accounts_active'  => 0,
            'members'          => 0,
            'admins'           => 0,
            'new_accounts'     => 0,
            'new_prev'         => 0,
            'products'         => 0,
            'products_active'  => 0,
            'low_stock'        => 0,
            'out_of_stock'     => 0,
            'stock_units'      => 0,
            'inventory_value'  => 0.0,
            'packages'         => 0,
            'packages_active'  => 0,
            'package_value'    => 0.0,
        ];

        /** @var list<array<string, mixed>> $products */
        $products = [];

        /** @var list<array<string, mixed>> $packages */
        $packages = [];

        /** @var list<array<string, mixed>> $recent */
        $recent = [];

        $db = db();

        try {
            $screen['accounts']        = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            /* "Active" means the member status, not merely sign-in enabled:
             * pending members can sign in, so is_active would over-count. */
            $screen['accounts_active'] = (int) $db->query("SELECT COUNT(*) FROM users WHERE member_status = 'active'")->fetchColumn();
            $screen['members']         = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
            $screen['admins']          = (int) $db->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin')")->fetchColumn();
            $screen['new_accounts']    = (int) $db->query('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetchColumn();
            $screen['new_prev']        = (int) $db->query(
                'SELECT COUNT(*) FROM users
                 WHERE created_at >= NOW() - INTERVAL 60 DAY
                   AND created_at <  NOW() - INTERVAL 30 DAY'
            )->fetchColumn();
        } catch (Throwable $e) {
            error_log('[ecowealth] admin dashboard account stats: ' . $e->getMessage());
        }

        try {
            $screen['products']        = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
            $screen['products_active'] = (int) $db->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
            $screen['low_stock']       = (int) $db->query('SELECT COUNT(*) FROM products WHERE stock > 0 AND stock < 30')->fetchColumn();
            $screen['out_of_stock']    = (int) $db->query('SELECT COUNT(*) FROM products WHERE stock = 0')->fetchColumn();
            $screen['stock_units']     = (int) $db->query('SELECT COALESCE(SUM(stock), 0) FROM products')->fetchColumn();
            $screen['inventory_value'] = (float) $db->query('SELECT COALESCE(SUM(price * stock), 0) FROM products')->fetchColumn();

            $products = $db->query(
                'SELECT id, name, sku, price, stock, status, image_url
                 FROM products
                 ORDER BY stock ASC, name ASC'
            )->fetchAll();
        } catch (Throwable $e) {
            error_log('[ecowealth] admin dashboard product stats: ' . $e->getMessage());
        }

        try {
            $screen['packages']        = (int) $db->query('SELECT COUNT(*) FROM packages')->fetchColumn();
            $screen['packages_active'] = (int) $db->query("SELECT COUNT(*) FROM packages WHERE status = 'active'")->fetchColumn();
            $screen['package_value']   = (float) $db->query("SELECT COALESCE(SUM(price), 0) FROM packages WHERE status = 'active'")->fetchColumn();

            $packages = $db->query(
                'SELECT id, name, price, product_value, direct_referral_bonus, status
                 FROM packages
                 ORDER BY sort_order, id'
            )->fetchAll();
        } catch (Throwable $e) {
            error_log('[ecowealth] admin dashboard package stats: ' . $e->getMessage());
        }

        try {
            $recent = $db->query(
                'SELECT id, username, email, full_name, role, member_status, is_active, created_at, last_login_at
                 FROM users
                 ORDER BY created_at DESC, id DESC
                 LIMIT 7'
            )->fetchAll();
        } catch (Throwable $e) {
            error_log('[ecowealth] admin dashboard recent accounts: ' . $e->getMessage());
        }

        /* ---- Chart + donut payloads (computed once) ---- */
        $chart = ['labels' => [], 'values' => [], 'stock' => []];
        $stock = ['healthy' => 0, 'low' => 0, 'out' => 0];
        $chartRows = [];

        foreach ($products as $product) {
            $units = (int) ($product['stock'] ?? 0);
            $price = (float) ($product['price'] ?? 0);

            if ($units === 0) {
                $stock['out']++;
            } elseif ($units < 30) {
                $stock['low']++;
            } else {
                $stock['healthy']++;
            }

            $chartRows[] = [
                'label' => (string) ($product['name'] ?? 'Product'),
                'value' => round($price * $units, 2),
                'stock' => $units,
            ];
        }

        /* The chart reads best with the most valuable stock first. */
        usort($chartRows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        foreach ($chartRows as $row) {
            $chart['labels'][] = $row['label'];
            $chart['values'][] = $row['value'];
            $chart['stock'][]  = $row['stock'];
        }

        /* Items that deserve a second look: zero or low stock, lowest first. */
        $attention = array_values(array_filter(
            $products,
            static fn (array $product): bool => (int) ($product['stock'] ?? 0) < 30
        ));
        $attention = array_slice($attention, 0, 4);

        /* Navbar notifications: the newest accounts. */
        $notes = [];
        foreach (array_slice($recent, 0, 4) as $account) {
            $role  = (string) ($account['role'] ?? 'member');
            $notes[] = [
                'icon'  => $role === 'member' ? 'bx-user-plus' : 'bx-shield-quarter',
                'tone'  => $role === 'member' ? 'success' : 'primary',
                'title' => (string) ($account['username'] ?? 'Account'),
                'text'  => 'Joined ' . time_ago((string) ($account['created_at'] ?? '')),
                'time'  => '',
            ];
        }

        $chartData = [
            'catalogue' => [
                'labels' => array_values($chart['labels']),
                'values' => array_values(array_map('floatval', $chart['values'])),
            ],
            'stock' => [
                'labels' => ['Healthy', 'Low stock', 'Out of stock'],
                'values' => [(int) $stock['healthy'], (int) $stock['low'], (int) $stock['out']],
            ],
        ];

        /* Commerce and finance roll-up (Phase 15). One guarded block so a
         * reporting query can never blank the operational dashboard. */
        $commerce = [];

        try {
            $commerce = $this->reports->dashboard();
        } catch (Throwable $e) {
            error_log('[ecowealth] admin dashboard commerce stats: ' . $e->getMessage());
            $commerce = $this->emptyCommerce();
        }

        return [
            'stats'      => $screen,
            'packages'   => $packages,
            'recent'     => $recent,
            'chart'      => $chart,
            'stock'      => $stock,
            'attention'  => $attention,
            'notes'      => $notes,
            'commerce'   => $commerce,
            'chart_data' => $chartData,
        ];
    }

    /**
     * Zero-shaped commerce payload for the view when a reporting query
     * fails: the screen keeps its shape and says "no data", never a 500.
     *
     * @return array<string, mixed>
     */
    private function emptyCommerce(): array
    {
        return [
            'sales_today'      => ['orders' => 0, 'amount' => 0.0, 'average' => 0.0],
            'sales_30d'        => ['orders' => 0, 'amount' => 0.0, 'average' => 0.0],
            'sales_all'        => ['orders' => 0, 'amount' => 0.0, 'average' => 0.0],
            'open_orders'      => 0,
            'orders_by_status' => [],
            'top_products'     => [],
            'commissions'      => [
                'amount' => 0.0, 'count' => 0, 'skipped' => 0, 'recipients' => 0,
                'all_time' => 0.0, 'this_month' => 0.0,
            ],
            'unilevel' => [
                'amount' => 0.0, 'count' => 0, 'skipped' => 0, 'voided' => 0,
                'unrecovered' => 0.0, 'recipients' => 0, 'all_time' => 0.0, 'this_month' => 0.0,
            ],
            'wallet' => [
                'accounts' => 0, 'float' => 0.0, 'withdrawable' => 0.0,
                'credited_today' => 0.0, 'debited_today' => 0.0, 'movements_today' => 0,
            ],
            'payouts' => [
                'by_status' => [], 'by_method' => [], 'held' => 0.0,
                'held_requests' => 0, 'completed_amount' => 0.0, 'completed_fees' => 0.0,
            ],
            'members' => [
                'total' => 0, 'members' => 0, 'active' => 0, 'pending' => 0,
                'suspended' => 0, 'new_30d' => 0,
            ],
        ];
    }

    /**
     * Live metadata for the initial page render.
     *
     * @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int}
     */
    private function liveMeta(): array
    {
        return Live::meta('admin.dashboard', $this->scope());
    }

    /**
     * JSON feed for the open dashboard.
     *
     * The 304 check runs before dashboardContext(), so an unchanged
     * revision costs one primary-key lookup and no aggregate queries.
     */
    private function feed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        /* Prime the token while the session is writable; a future live
         * region may render a POST form. */
        csrf_token();
        session_write_close();

        $surface = 'admin.dashboard';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->scope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->dashboardContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] admin dashboard live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the dashboard. Please try again.', 500);
        }
    }

    /**
     * Render the feed payload. Public so tests can assert the fragments
     * without going through Live::json()'s exit.
     *
     * @param array{
     *   stats: array<string, int|float>,
     *   packages: list<array<string, mixed>>,
     *   recent: list<array<string, mixed>>,
     *   chart: array{labels: list<string>, values: list<float>, stock: list<int>},
     *   stock: array{healthy:int, low:int, out:int},
     *   attention: list<array<string, mixed>>,
     *   notes: list<array<string, string>>,
     *   commerce: array<string, mixed>,
     *   chart_data: array{
     *     catalogue: array{labels: list<string>, values: list<float>},
     *     stock: array{labels: list<string>, values: list<int>}
     *   }
     * } $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $commerce = (array) ($data['commerce'] ?? $this->emptyCommerce());

        return [
            'ok'        => true,
            'surface'   => 'admin.dashboard',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'dashboard-kpis' => View::capture('admin/dashboard/_kpis', [
                    'stats' => $data['stats'],
                ]),
                'dashboard-commerce' => View::capture('admin/dashboard/_commerce', [
                    'commerce' => $commerce,
                ]),
                'dashboard-catalogue' => View::capture('admin/dashboard/_catalogue', [
                    'stats' => $data['stats'],
                    'chart' => $data['chart'],
                ]),
                'dashboard-stock' => View::capture('admin/dashboard/_stock', [
                    'stats'     => $data['stats'],
                    'stock'     => $data['stock'],
                    'attention' => $data['attention'],
                ]),
                'dashboard-order-status' => View::capture('admin/dashboard/_order_status', [
                    'commerce' => $commerce,
                ]),
                'dashboard-top-products' => View::capture('admin/dashboard/_top_products', [
                    'commerce' => $commerce,
                ]),
                'dashboard-recent' => View::capture('admin/dashboard/_recent', [
                    'recent' => $data['recent'],
                ]),
                'dashboard-packages' => View::capture('admin/dashboard/_packages', [
                    'packages' => $data['packages'],
                ]),
            ],
            'data'      => $data['chart_data'],
        ];
    }

    /**
     * ETag scope for the dashboard.
     *
     * The 30/60-day account windows and "time ago" labels are relative to
     * the current date, so the day is part of the scope. A tab left open
     * across midnight revalidates on its next poll instead of staying
     * stale until some unrelated write.
     *
     * Phase 15 adds the commerce roll-up, which reads orders, commissions,
     * wallets, payouts and settings. Rather than touching this surface in
     * every one of those services, the scope mixes the revisions of the
     * surfaces their writes already bump: any such write makes the next
     * poll answer 200 with fresh figures.
     */
    private function scope(?int $userId = null): string
    {
        $userId ??= (int) (auth_user()['id'] ?? 0);

        return Live::scope($_GET, $userId)
            . '|day:' . date('Y-m-d')
            . '|orders:' . Live::revisionOrZero('admin.orders')
            . '|commissions:' . Live::revisionOrZero('admin.commissions')
            . '|wallets:' . Live::revisionOrZero('admin.wallets')
            . '|payouts:' . Live::revisionOrZero(Live::ADMIN_PAYOUTS)
            . '|config:' . Live::revisionOrZero(Live::CONFIG);
    }
}
