<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminDashboardController — back-office landing page
 *  (reference: admin). Phase 1 shipped account counts; Phase 3
 *  adds the live catalogue, stock and packaging picture the
 *  operations screen needs. Later phases extend $stats instead of
 *  rebuilding the view.
 * ============================================================== */

final class AdminDashboardController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
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

        /* Each block is independent: a table that a later phase has not
         * created yet must never blank the whole dashboard. */
        try {
            $screen['accounts']        = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $screen['accounts_active'] = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
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
                'SELECT id, username, email, full_name, role, is_active, created_at, last_login_at
                 FROM users
                 ORDER BY created_at DESC, id DESC
                 LIMIT 7'
            )->fetchAll();
        } catch (Throwable $e) {
            error_log('[ecowealth] admin dashboard recent accounts: ' . $e->getMessage());
        }

        /* ---- Chart + donut payloads (computed once, printed as JSON) ---- */
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

        View::page('admin', 'admin/dashboard', [
            'page_title'  => 'Dashboard',
            'current_nav' => 'admin.dashboard',
            'page_css'    => ['assets/vendor/libs/apex-charts/apex-charts.css'],
            'page_js'     => [
                'assets/vendor/libs/apex-charts/apexcharts.js',
                'assets/js/admin-dashboard.js',
            ],
            'stats'       => $screen,
            'products'    => $products,
            'packages'    => $packages,
            'recent'      => $recent,
            'chart'       => $chart,
            'stock'       => $stock,
            'attention'   => $attention,
            'nav_notes'   => $notes,
        ]);
    }
}
