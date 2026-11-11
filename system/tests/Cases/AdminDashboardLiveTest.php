<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminDashboardLiveTest — the admin.dashboard surface
 *
 *  The dashboard feed must render the same five regions as the page,
 *  carry the chart data for the ApexCharts re-render hook, and the
 *  write paths must bump admin.dashboard as well as admin.products.
 * ============================================================== */

return [
    'dashboard writes bump admin.dashboard' => function (): void {
        $before = Live::revision('admin.dashboard');

        $productId = Product::save([
            'name'   => 'Dashboard Probe Product',
            'price'  => '25.00',
            'stock'  => '4',
            'status' => 'active',
        ]);

        assert_same($before + 1, Live::revision('admin.dashboard'), 'product create');
        assert_same($before + 1, Live::revision('admin.products'), 'product surface still bumps');

        Product::setStatus($productId, 'inactive');
        assert_same($before + 2, Live::revision('admin.dashboard'), 'product status');

        $deleted = Product::delete($productId);
        assert_true($deleted['ok']);
        assert_same($before + 3, Live::revision('admin.dashboard'), 'product delete');

        $levels = [];
        for ($level = Package::LEVEL_MIN; $level <= Package::LEVEL_MAX; $level++) {
            $levels[$level] = 0.0;
        }

        $packageId = Package::save([
            'name'                  => 'Dashboard Probe Package',
            'price'                 => '100.00',
            'product_value'         => '50.00',
            'direct_referral_bonus' => '5.00',
            'indirect_enabled'      => 0,
            'status'                => 'active',
            'sort_order'            => 0,
        ], $levels, []);

        assert_same($before + 4, Live::revision('admin.dashboard'), 'package create');

        Package::setStatus($packageId, 'inactive');
        assert_same($before + 5, Live::revision('admin.dashboard'), 'package status');

        /* A no-op package toggle must not invalidate the dashboard. */
        Package::setStatus($packageId, 'inactive');
        assert_same($before + 5, Live::revision('admin.dashboard'), 'no-op package status');
    },

    'member status changes bump admin.dashboard' => function (): void {
        $member = test_user('dashboard_status_member');
        $before = Live::revision('admin.dashboard');

        Member::setStatus((int) $member['id'], 'suspended');

        assert_same($before + 1, Live::revision('admin.dashboard'));
    },

    'the dashboard feed renders eight regions and chart data' => function (): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $now = date('Y-m-d H:i:s');

        $data = [
            'stats' => [
                'accounts'         => 10,
                'accounts_active'  => 6,
                'members'          => 8,
                'admins'           => 2,
                'new_accounts'     => 3,
                'new_prev'         => 1,
                'products'         => 4,
                'products_active'  => 3,
                'low_stock'        => 1,
                'out_of_stock'     => 1,
                'stock_units'      => 20,
                'inventory_value'  => 12345.0,
                'packages'         => 2,
                'packages_active'  => 2,
                'package_value'    => 5000.0,
            ],
            'packages' => [[
                'id'                    => 1,
                'name'                  => 'Starter <script>alert(1)</script>',
                'price'                 => '2500.00',
                'product_value'         => '2000.00',
                'direct_referral_bonus' => '500.00',
                'status'                => 'active',
            ]],
            'recent' => [[
                'id'            => 1,
                'username'      => '<script>alert(2)</script>',
                'email'         => 'probe@example.com',
                'full_name'     => '',
                'role'          => 'member',
                'member_status' => 'pending',
                'is_active'     => 1,
                'created_at'    => $now,
                'last_login_at' => null,
            ]],
            'chart' => [
                'labels' => ['Coffee'],
                'values' => [300.0],
                'stock'  => [5],
            ],
            'stock' => ['healthy' => 1, 'low' => 0, 'out' => 0],
            'attention' => [[
                'id'    => 1,
                'name'  => 'Coffee',
                'stock' => 5,
            ]],
            'notes' => [],
            'commerce' => [
                'sales_today' => ['orders' => 2, 'amount' => 1500.0, 'average' => 750.0],
                'sales_30d' => ['orders' => 9, 'amount' => 12345.0, 'average' => 1371.67],
                'sales_all' => ['orders' => 20, 'amount' => 30000.0, 'average' => 1500.0],
                'open_orders' => 3,
                'orders_by_status' => [
                    ['status' => 'paid', 'count' => 1, 'amount' => 500.0],
                    ['status' => 'pending', 'count' => 2, 'amount' => 900.0],
                ],
                'top_products' => [
                    ['name' => 'Coffee <script>alert(3)</script>', 'sku' => 'COF-1', 'units' => 7, 'amount' => 2100.0],
                ],
                'commissions' => [
                    'amount' => 500.0, 'count' => 1, 'skipped' => 0,
                    'recipients' => 1, 'all_time' => 900.0, 'this_month' => 500.0,
                ],
                'wallet' => [
                    'accounts' => 5, 'float' => 3000.0, 'withdrawable' => 2500.0,
                    'credited_today' => 0.0, 'debited_today' => 0.0, 'movements_today' => 0,
                ],
                'payouts' => [
                    'by_status' => [], 'by_method' => [], 'held' => 500.0,
                    'held_requests' => 1, 'completed_amount' => 0.0, 'completed_fees' => 0.0,
                ],
                'members' => [
                    'total' => 10, 'members' => 8, 'active' => 6, 'pending' => 2,
                    'suspended' => 0, 'new_30d' => 3,
                ],
            ],
            'chart_data' => [
                'catalogue' => [
                    'labels' => ['Coffee'],
                    'values' => [300.0],
                ],
                'stock' => [
                    'labels' => ['Healthy', 'Low stock', 'Out of stock'],
                    'values' => [1, 0, 0],
                ],
            ],
        ];

        $payload = (new AdminDashboardController())->feedPayload(
            5,
            '"admin.dashboard:5:abc"',
            $data
        );

        assert_true($payload['ok']);
        assert_same('admin.dashboard', $payload['surface']);
        assert_same(5, $payload['revision']);
        assert_same(
            [
                'dashboard-kpis',
                'dashboard-commerce',
                'dashboard-catalogue',
                'dashboard-stock',
                'dashboard-order-status',
                'dashboard-top-products',
                'dashboard-recent',
                'dashboard-packages',
            ],
            array_keys($payload['fragments'])
        );

        $kpis      = $payload['fragments']['dashboard-kpis'];
        $commerce  = $payload['fragments']['dashboard-commerce'];
        $catalogue = $payload['fragments']['dashboard-catalogue'];
        $stock     = $payload['fragments']['dashboard-stock'];
        $orders    = $payload['fragments']['dashboard-order-status'];
        $top       = $payload['fragments']['dashboard-top-products'];
        $recent    = $payload['fragments']['dashboard-recent'];
        $packages  = $payload['fragments']['dashboard-packages'];

        assert_contains('data-live-region="dashboard-kpis"', $kpis);
        assert_contains('Total accounts', $kpis);
        assert_contains('Inventory value', $kpis);

        /* Phase 15: the commerce and finance roll-up. */
        assert_contains('data-live-region="dashboard-commerce"', $commerce);
        assert_contains('Sales today', $commerce);
        assert_contains('₱1,500.00', $commerce);
        assert_contains('Sales · 30 days', $commerce);
        assert_contains('Commissions · 30 days', $commerce);
        assert_contains('Wallet float', $commerce);

        assert_contains('data-live-region="dashboard-order-status"', $orders);
        assert_contains('Payment review, packing, ready, shipped', $commerce);
        assert_contains('Paid', $orders);
        assert_contains('Pending', $orders);

        assert_contains('data-live-region="dashboard-top-products"', $top);
        assert_contains('Coffee &lt;script&gt;alert(3)&lt;/script&gt;', $top);
        assert_not_contains('<script>alert(3)</script>', $top);
        assert_contains('7 units', $top);

        assert_contains('data-live-region="dashboard-catalogue"', $catalogue);
        assert_contains('id="catalogueValueChart"', $catalogue);
        assert_contains('Catalogue value', $catalogue);
        assert_contains('Coffee', $catalogue);

        assert_contains('data-live-region="dashboard-stock"', $stock);
        assert_contains('id="stockHealthChart"', $stock);
        assert_contains('Needs attention', $stock);
        assert_contains('5 left', $stock);

        assert_contains('data-live-region="dashboard-recent"', $recent);
        assert_contains('&lt;script&gt;alert(2)&lt;/script&gt;', $recent);
        assert_not_contains('<script>alert(2)</script>', $recent);
        assert_contains('Pending', $recent);

        assert_contains('data-live-region="dashboard-packages"', $packages);
        assert_contains('Starter &lt;script&gt;alert(1)&lt;/script&gt;', $packages);
        assert_not_contains('<script>alert(1)</script>', $packages);
        assert_contains('Direct referral', $packages);

        assert_same(['Coffee'], $payload['data']['catalogue']['labels']);
        assert_same([300.0], $payload['data']['catalogue']['values']);
        assert_same([1, 0, 0], $payload['data']['stock']['values']);
    },
];
