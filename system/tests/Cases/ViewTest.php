<?php
declare(strict_types=1);

/* ==============================================================
 *  ViewTest — layouts, menus and error pages (P1-T2, P1-T3,
 *  P1-T8). Renders pages the same way the front controller does,
 *  then asserts on the HTML.
 * ============================================================== */

/**
 * Render a page through View::page() and return the HTML.
 *
 * @param array<string, mixed> $data
 */
$render = static function (string $layout, string $template, array $data = []): string {
    ob_start();

    try {
        View::page($layout, $template, $data);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

$adminStats = [
    'accounts'        => 1,
    'accounts_active' => 1,
    'members'         => 0,
    'admins'          => 2,
    'new_accounts'    => 1,
    'products'        => 7,
    'products_active' => 7,
    'stock_units'     => 330,
    'inventory_value' => 128000.0,
    'packages'        => 2,
    'packages_active' => 2,
    'package_value'   => 11496.0,
];

return [
    'View::capture renders an error template' => function (): void {
        $html = View::capture('errors/403', [
            'code'    => 403,
            'heading' => 'Access denied',
            'message' => 'You do not have permission to open this page.',
        ]);

        assert_contains('403', $html);
        assert_contains('Access denied', $html);
    },

    'View::page renders 404 inside the plain layout without a sidebar' => function () use ($render): void {
        $html = $render('plain', 'errors/404', [
            'code'    => 404,
            'heading' => 'Page not found',
            'message' => 'The page you were looking for could not be found.',
        ]);

        assert_contains('Page not found', $html);
        assert_not_contains('id="layout-menu"', $html, 'guest error pages have no sidebar');
        assert_contains('Sign in', $html, 'guests are offered the login link');
        assert_contains(url('assets/css/demo.css'), $html, 'assets use the install prefix');
    },

    'View::page renders the member layout with member navigation only' => function () use ($render): void {
        test_signed_in_as(test_user('layout_member'));

        $html = $render('member', 'shop/catalog', [
            'products'  => [],
            'available' => [],
            'pager'     => new Paginator(0, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => true,
        ]);

        assert_contains('id="layout-menu"', $html);
        assert_contains('Dashboard', $html);
        assert_contains('Shop', $html);
        assert_contains('Change password', $html);
        assert_contains('<div>Register</div>', $html, 'members get a Register entry under Membership');
        assert_contains('href="' . url('register') . '"', $html);
        assert_contains('menu-icon icon-base bx bx-home-smile', $html, 'sidebar icons keep the Boxicons base class');

        /* P1-T8: member pages never expose back-office items. */
        assert_not_contains(url('admin/codes'), $html);
        assert_not_contains('E-wallet monitor', $html);
        assert_not_contains('S-Login console', $html);
        assert_not_contains('Commissions', $html);
    },

    'View::page renders the admin layout with back-office navigation' => function () use ($render, $adminStats): void {
        test_signed_in_as(test_user('layout_admin', 'admin'));

        $html = $render('admin', 'admin/dashboard', ['stats' => $adminStats, 'recent' => []]);

        assert_contains('id="layout-menu"', $html);
        assert_contains('Back office', $html);
        assert_contains('Products', $html);
        assert_contains('Packages', $html);
        assert_contains('Recent accounts', $html);
        assert_contains('menu-icon icon-base bx bx-home-smile', $html, 'sidebar icons keep the Boxicons base class');

        /* Phase 9 made the membership screens live. */
        assert_contains('Members', $html);
        assert_contains('Activations', $html);
        assert_contains('href="' . url('admin/members') . '"', $html);
        assert_contains('<div>Register</div>', $html, 'admins get a Register entry under Membership');
        assert_contains('href="' . url('register') . '"', $html);

        /* The codes item is short ("Codes") so it never truncates. */
        assert_contains('<div>Codes</div>', $html);
        assert_contains('href="' . url('admin/codes') . '"', $html);

        /* Unbuilt screens are left out of the menu entirely. */
        assert_not_contains('Payouts', $html);
        assert_not_contains('Soon', $html);
    },

    'View::page shows the member status, not sign-in state, in recent accounts' => function () use ($render, $adminStats): void {
        test_signed_in_as(test_user('layout_admin', 'admin'));

        $html = $render('admin', 'admin/dashboard', [
            'stats'  => $adminStats,
            'recent' => [
                [
                    'id'            => 9001,
                    'username'      => 'status_pending',
                    'email'         => 'pending@example.com',
                    'full_name'     => '',
                    'role'          => 'member',
                    'member_status' => 'pending',
                    'is_active'     => 1,
                    'created_at'    => '2026-11-07 10:00:00',
                    'last_login_at' => null,
                ],
                [
                    'id'            => 9002,
                    'username'      => 'status_suspended',
                    'email'         => 'suspended@example.com',
                    'full_name'     => '',
                    'role'          => 'member',
                    'member_status' => 'suspended',
                    'is_active'     => 0,
                    'created_at'    => '2026-11-06 10:00:00',
                    'last_login_at' => null,
                ],
            ],
        ]);

        assert_contains('bg-label-warning">Pending', $html);
        assert_contains('bg-label-danger">Suspended', $html);

        /* A pending member can sign in (is_active = 1); that must never
         * turn the row's status into "Active" (the members page says
         * Pending). */
        $start = strpos($html, 'status_pending');
        $end   = strpos($html, 'status_suspended', (int) $start);

        assert_true($start !== false && $end !== false, 'both rows render');

        $pendingRow = substr($html, (int) $start, (int) $end - (int) $start);

        assert_not_contains('>Active<', $pendingRow);
    },

    'View::page hides later-phase items from the superadmin console for a plain admin' => function () use ($render, $adminStats): void {
        test_signed_in_as(test_user('layout_admin', 'admin'));

        $html = $render('admin', 'admin/dashboard', ['stats' => $adminStats, 'recent' => []]);

        /* S-Login is superadmin-only (plan §3.4) — never rendered for an admin. */
        assert_not_contains('S-Login console', $html);
        assert_not_contains('href="' . url('slogin') . '"', $html);
    },

    'View::page links the top-bar identity to the member profile' => function () use ($render): void {
        $member = test_user('identity_member');
        test_signed_in_as($member);

        $html = $render('member', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => 'profile',
            'user'        => $member,
            'addresses'   => [],
            'editing'     => null,
            'posted'      => [],
        ]);

        /* The identity row in the user dropdown is the account shortcut. */
        assert_contains(
            'class="dropdown-item" href="' . url('profile') . '" data-user-menu-identity',
            $html
        );
        assert_not_contains(
            'class="dropdown-item" href="' . url('dashboard') . '"><div class="d-flex">',
            $html,
            'the identity row no longer links to the dashboard'
        );
        assert_contains('My orders', $html);
    },

    'View::page renders an admin profile inside the back-office layout' => function () use ($render): void {
        $admin = test_user('layout_admin', 'admin');
        test_signed_in_as($admin);

        $html = $render('admin', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => 'admin.profile',
            'user'        => $admin,
            'addresses'   => [],
            'editing'     => null,
            'posted'      => [],
        ]);

        assert_contains('id="layout-menu"', $html);
        assert_contains('E-wallet monitor', $html, 'the back-office sidebar is in use');
        assert_contains('My profile', $html);
        assert_contains('menu-item active', $html);
        assert_contains('class="dropdown-item" href="' . url('profile') . '" data-user-menu-identity', $html);
        assert_not_contains('My orders', $html, 'admin pages never show member-only menu items');
    },

    'View::page marks the active menu item' => function () use ($render): void {
        test_signed_in_as(test_user('layout_member'));

        $html = $render('member', 'profile/password', ['current_nav' => 'password']);

        assert_contains('Current password', $html);
        assert_contains('name="_csrf"', $html);
        assert_contains('menu-item active', $html);
    },

    'View::page renders the password form with CSRF protection' => function () use ($render): void {
        test_signed_in_as(test_user('layout_member'));

        $html = $render('member', 'profile/password');

        assert_contains('action="' . url('profile/password') . '"', $html);
        assert_contains('name="current_password"', $html);
        assert_contains('name="new_password"', $html);
        assert_contains('name="confirm_password"', $html);
        assert_contains(csrf_token(), $html);
    },

    'View::page renders the storefront for members' => function () use ($render): void {
        test_signed_in_as(test_user('layout_member'));

        $html = $render('member', 'shop/catalog', [
            'products'  => [],
            'available' => [],
            'pager'     => new Paginator(0, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => false,
        ]);

        assert_contains('Shop', $html);
        assert_contains('shop is currently closed', $html);
        assert_contains('name="q"', $html);
    },

    'View refuses unknown and traversing template names' => function (): void {
        assert_throws(static fn () => View::capture('errors/does-not-exist'), RuntimeException::class);
        assert_throws(static fn () => View::capture('../../app/config'), InvalidArgumentException::class);
    },

    'Partials render empty states and pagination' => function (): void {
        $empty = View::partial('empty_state', [
            'title' => 'No orders yet',
            'text'  => 'Orders appear here after checkout.',
        ]);

        assert_contains('No orders yet', $empty);
        assert_contains('Orders appear here after checkout.', $empty);

        $pager = new Paginator(57, 20, 2);

        $_GET = ['per_page' => '20', 'q' => 'ana'];

        try {
            $links = View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Orders']);
        } finally {
            $_GET = [];
        }

        assert_contains('Showing 21–40 of 57', $links);
        assert_contains('Orders', $links);
        assert_contains('page=3', $links);
        assert_contains('page=1', $links);
        assert_contains('First page', $links);
        assert_contains('Last page', $links);

        /* The rows-per-page control offers 5 … 100, keeps filters and
         * selects the current size (P6-T9, §3.6). */
        assert_contains('Rows per page', $links);
        assert_contains('name="per_page"', $links);
        assert_contains('data-per-page', $links);
        assert_contains('data-pagination="', $links, 'a stable key lets the table stay in view');
        assert_contains('<option value="5"', $links);
        assert_contains('<option value="10"', $links);
        assert_contains('<option value="100"', $links);
        assert_contains('<option value="20" selected', $links);
        assert_contains('name="q" value="ana"', $links, 'the rows form keeps the active filter');

        /* A list without a size choice omits the control. */
        $fixed = View::partial('pagination', [
            'pager'         => new Paginator(30, 12, 1),
            'show_per_page' => false,
        ]);

        assert_not_contains('Rows per page', $fixed);
        assert_contains('Showing 1–12 of 30', $fixed);
    },

    'View::page footer carries only the EcoWealth copyright' => function () use ($render): void {
        test_signed_in_as(test_user('layout_footer'));

        $html = $render('member', 'profile/password');

        assert_contains('All rights reserved', $html);
        assert_contains(APP_NAME, $html, 'the footer keeps the brand');
        assert_not_contains('Built with', $html);
        assert_not_contains('ThemeSelection', $html);
        assert_not_contains('PHP ' . PHP_VERSION, $html);
    },

    'Escaping is applied everywhere user text is printed' => function (): void {
        $html = View::capture('errors/404', [
            'code'    => 404,
            'heading' => 'Not found',
            'message' => '<script>alert(1)</script>',
        ]);

        assert_not_contains('<script>alert(1)</script>', $html);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    },
];
