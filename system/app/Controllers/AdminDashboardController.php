<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminDashboardController — back-office landing page
 *  (reference: admin). Phase 1 shipped account counts; Phase 3
 *  adds the live catalogue, stock and packaging picture the
 *  operations screen needs. Later phases extend $stats instead of
 *  rebuilding the view.
 *
 *  The same URL doubles as its JSON feed (`X-Live: 1`). The KPI
 *  cards, catalogue chart, stock health card, recent accounts and
 *  package list are separate live regions; the browser patches only
 *  the regions that changed.
 * ============================================================== */

final class AdminDashboardController
{
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

        return [
            'stats'      => $screen,
            'packages'   => $packages,
            'recent'     => $recent,
            'chart'      => $chart,
            'stock'      => $stock,
            'attention'  => $attention,
            'notes'      => $notes,
            'chart_data' => $chartData,
        ];
    }

    /**
     * Live metadata for the initial page render.
     *
     * @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int}
     */
    private function liveMeta(): array
    {
        $surface = 'admin.dashboard';
        $meta    = [
            'surface'  => $surface,
            'enabled'  => true,
            'revision' => 0,
            'etag'     => '',
            'interval' => 30000,
        ];

        try {
            $revision         = Live::revision($surface);
            $meta['revision'] = $revision;
            $meta['etag']     = Live::etag($surface, $revision, $this->scope());
        } catch (Throwable $e) {
            error_log('[ecowealth] admin dashboard live revision unavailable: ' . $e->getMessage());
            $meta['enabled'] = false;
        }

        return $meta;
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
     *   chart_data: array{
     *     catalogue: array{labels: list<string>, values: list<float>},
     *     stock: array{labels: list<string>, values: list<int>}
     *   }
     * } $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        return [
            'ok'        => true,
            'surface'   => 'admin.dashboard',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'dashboard-kpis' => View::capture('admin/dashboard/_kpis', [
                    'stats' => $data['stats'],
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
     */
    private function scope(?int $userId = null): string
    {
        $userId ??= (int) (auth_user()['id'] ?? 0);

        return Live::scope($_GET, $userId) . '|day:' . date('Y-m-d');
    }
}
