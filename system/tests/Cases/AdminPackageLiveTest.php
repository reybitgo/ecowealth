<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminPackageLiveTest — the package list and the member catalogue
 *
 *  Two surfaces: `admin.packages` (stats, rows, pagination) and
 *  `shop.packages` (the member card grid). Package writes also bump the
 *  shared `config` revision, which the membership and order pages mix
 *  into their ETags.
 * ============================================================== */

$makeAdmin = static function (): array {
    return test_user('pkg_live_admin', 'admin');
};

$makePackage = static function (string $name = ''): int {
    return Package::save([
        'name'                  => $name !== '' ? $name : 'Live Package ' . bin2hex(random_bytes(3)),
        'price'                 => '100.00',
        'product_value'         => '120.00',
        'direct_referral_bonus' => '10.00',
        'description'           => 'A live test package.',
        'perks'                 => '',
        'status'                => 'active',
        'sort_order'            => '0',
        'indirect_enabled'      => true,
    ], [], [], null);
};

return [
    'package writes bump the admin list, member catalogue and config' => function () use ($makePackage): void {
        $before = [
            'admin.packages' => Live::revision('admin.packages'),
            'shop.packages'  => Live::revision('shop.packages'),
            'config'         => Live::revision(Live::CONFIG),
            'dashboard'      => Live::revision('admin.dashboard'),
        ];

        $id = $makePackage();

        assert_same($before['admin.packages'] + 1, Live::revision('admin.packages'), 'save (admin list)');
        assert_same($before['shop.packages'] + 1, Live::revision('shop.packages'), 'save (member catalogue)');
        assert_same($before['config'] + 1, Live::revision(Live::CONFIG), 'save (config)');
        assert_same($before['dashboard'] + 1, Live::revision('admin.dashboard'), 'save (dashboard)');

        $before = [
            'admin.packages' => Live::revision('admin.packages'),
            'shop.packages'  => Live::revision('shop.packages'),
            'config'         => Live::revision(Live::CONFIG),
        ];

        Package::setStatus($id, 'inactive');

        assert_same($before['admin.packages'] + 1, Live::revision('admin.packages'), 'status (admin list)');
        assert_same($before['shop.packages'] + 1, Live::revision('shop.packages'), 'status (member catalogue)');
        assert_same($before['config'] + 1, Live::revision(Live::CONFIG), 'status (config)');

        /* Setting the same status again writes nothing and moves no surface. */
        $before = [
            'admin.packages' => Live::revision('admin.packages'),
            'shop.packages'  => Live::revision('shop.packages'),
            'config'         => Live::revision(Live::CONFIG),
        ];

        Package::setStatus($id, 'inactive');

        assert_same($before['admin.packages'], Live::revision('admin.packages'));
        assert_same($before['shop.packages'], Live::revision('shop.packages'));
        assert_same($before['config'], Live::revision(Live::CONFIG));
    },

    'the admin package feed renders stats, rows and pagination' => function () use ($makeAdmin, $makePackage): void {
        $admin  = $makeAdmin();
        $hostile = $makePackage('<b>Live Package</b>');

        test_signed_in_as($admin);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/packages?per_page=5';

        $controller = new AdminPackageController();
        $payload    = $controller->feedPayload(
            6,
            '"admin.packages:6:abc"',
            $controller->packagesContext()
        );

        assert_same('admin.packages', $payload['surface']);
        assert_same(
            ['package-stats', 'package-rows', 'package-pagination'],
            array_keys($payload['fragments'])
        );

        $stats      = $payload['fragments']['package-stats'];
        $rows       = $payload['fragments']['package-rows'];
        $pagination = $payload['fragments']['package-pagination'];

        assert_contains('data-live-region="package-stats"', $stats);
        assert_contains('Packages', $stats);
        assert_contains('Active', $stats);
        assert_contains('Inactive', $stats);

        assert_contains('data-live-region="package-rows"', $rows);
        assert_contains('data-live-key="' . $hostile . '"', $rows);
        assert_contains('data-live-focus="edit"', $rows);
        assert_contains('data-live-focus="toggle"', $rows);
        assert_contains('&lt;b&gt;Live Package&lt;/b&gt;', $rows);
        assert_not_contains('<b>Live Package</b>', $rows);
        assert_contains(money(100), $rows);
        assert_contains(url('admin/packages/' . $hostile . '/edit'), $rows);
        assert_contains(url('admin/packages/' . $hostile . '/status'), $rows);
        assert_not_contains('name="q"', $rows, 'the filter form stays static');

        assert_contains('data-live-region="package-pagination"', $pagination);
        assert_contains('Rows per page', $pagination);
        assert_not_contains('/feed', $pagination);

        assert_same(url('admin/packages?per_page=5'), $payload['meta']['canonical']);
    },

    'the member package catalogue feed renders the cards and the empty state' => function (): void {
        $member = test_user('pkg_live_member');

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/packages';

        $controller = new PackageController();
        $payload    = $controller->feedPayload(
            2,
            '"shop.packages:2:abc"',
            $controller->packagesContext()
        );

        assert_same('shop.packages', $payload['surface']);
        assert_same(['package-cards'], array_keys($payload['fragments']));

        $html = $payload['fragments']['package-cards'];

        assert_contains('data-live-region="package-cards"', $html);
        assert_contains('Package A', $html);
        assert_contains('Package B', $html);
        assert_contains('Activate from Membership', $html);
        assert_same(url('packages'), $payload['meta']['canonical']);

        $empty = $controller->feedPayload(3, '"shop.packages:3:def"', ['packages' => []]);
        $emptyHtml = $empty['fragments']['package-cards'];

        assert_contains('data-live-region="package-cards"', $emptyHtml);
        assert_contains('No packages are available yet', $emptyHtml);
        assert_not_contains('Package A', $emptyHtml);
    },

    'the package pages declare their live surfaces and regions' => function () use ($makeAdmin): void {
        $admin = $makeAdmin();

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/packages';

        $controller = new AdminPackageController();
        $data       = $controller->packagesContext();

        ob_start();

        try {
            View::page('admin', 'admin/packages/index', $data + [
                'page_title'  => 'Packages',
                'current_nav' => 'admin.packages',
                'page_css'    => ['assets/vendor/libs/sweetalert2/sweetalert2.css'],
                'page_js'     => ['assets/vendor/libs/sweetalert2/sweetalert2.js'],
                'live'        => Live::meta('admin.packages', 'page'),
            ]);
        } finally {
            $adminHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.packages"', $adminHtml);
        assert_contains('data-live-enabled="1"', $adminHtml);
        assert_contains('data-live-region="package-stats"', $adminHtml);
        assert_contains('data-live-region="package-rows"', $adminHtml);
        assert_contains('data-live-region="package-pagination"', $adminHtml);
        assert_contains('data-live-status', $adminHtml);
        assert_not_contains('data-live-stale', $adminHtml, 'the rows have no held form');

        test_signed_in_as(test_user('pkg_live_member'));

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/packages';

        $memberController = new PackageController();
        $memberData       = $memberController->packagesContext();

        ob_start();

        try {
            View::page('member', 'shop/packages', $memberData + [
                'page_title'  => 'Packages',
                'current_nav' => 'packages',
                'live'        => Live::meta('shop.packages', 'page'),
            ]);
        } finally {
            $memberHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="shop.packages"', $memberHtml);
        assert_contains('data-live-region="package-cards"', $memberHtml);
        assert_contains('data-live-status', $memberHtml);
        assert_contains('Associate packages', $memberHtml);
    },
];
