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

    'the dashboard feed renders five regions and chart data' => function (): void {
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
                'dashboard-catalogue',
                'dashboard-stock',
                'dashboard-recent',
                'dashboard-packages',
            ],
            array_keys($payload['fragments'])
        );

        $kpis      = $payload['fragments']['dashboard-kpis'];
        $catalogue = $payload['fragments']['dashboard-catalogue'];
        $stock     = $payload['fragments']['dashboard-stock'];
        $recent    = $payload['fragments']['dashboard-recent'];
        $packages  = $payload['fragments']['dashboard-packages'];

        assert_contains('data-live-region="dashboard-kpis"', $kpis);
        assert_contains('Total accounts', $kpis);
        assert_contains('Inventory value', $kpis);

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
