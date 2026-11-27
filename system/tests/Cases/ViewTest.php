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
        assert_contains('<div>Register</div>', $html, 'admins may register a new member');
        assert_contains('href="' . url('register') . '"', $html);

        /* The codes item is short ("Codes") so it never truncates. */
        assert_contains('<div>Codes</div>', $html);
        assert_contains('href="' . url('admin/codes') . '"', $html);

        /* Unbuilt screens are left out of the menu entirely; the payout
         * queue arrived in Phase 13 and is now a live entry. */
        assert_contains('href="' . url('admin/payouts') . '"', $html);
        assert_contains('href="' . url('payouts') . '"', $html, 'admins may withdraw their own earnings too');
        assert_not_contains('Soon', $html);

        /* The dashboard is one live surface with five replaceable regions. */
        assert_contains('data-live-surface="admin.dashboard"', $html);
        assert_contains('data-live-region="dashboard-kpis"', $html);
        assert_contains('data-live-region="dashboard-catalogue"', $html);
        assert_contains('data-live-region="dashboard-stock"', $html);
        assert_contains('data-live-region="dashboard-recent"', $html);
        assert_contains('data-live-region="dashboard-packages"', $html);
        assert_contains('data-live-status', $html);
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

    'UX-08 an admin changing their password stays in the back-office layout' => function (): void {
        test_signed_in_as(test_user('layout_admin_password', 'admin'));

        ob_start();

        try {
            (new ProfileController())->password();
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('id="layout-menu"', $html);
        assert_contains('E-wallet monitor', $html, 'the back-office sidebar is in use');
        assert_contains('My profile', $html);
        assert_contains('menu-item active', $html);
        assert_not_contains('My orders', $html, 'admin pages never show member-only menu items');
        assert_contains('<h4 class="mb-1">Change password</h4>', $html, 'the page has a normal page header');
        assert_contains('Back to profile', $html);
        assert_contains('href="' . url('profile') . '" class="btn btn-label-secondary">Cancel</a>', $html);
    },

    'UX-13 the member password page opens with the standard page header' => function () use ($render): void {
        $user = test_user('ux13_member');
        test_signed_in_as($user);

        $html = $render('member', 'profile/password', ['current_nav' => 'password']);

        $heading = '<h4 class="mb-1">Change password</h4>';

        assert_contains($heading, $html, 'the page opens with the page-level heading');
        assert_contains('Signed in as <strong>' . e((string) $user['username']) . '</strong>', $html);
        assert_contains('Changing your password signs out other remembered devices.', $html);
        assert_not_contains('<h5 class="mb-1">Change password</h5>', $html, 'the title is not an in-card widget');
        assert_contains('Back to profile</a>', $html, 'the header carries the way back');
        assert_contains('href="' . url('profile') . '" class="btn btn-label-secondary">Cancel</a>', $html, 'Cancel returns to Profile');

        $headingAt = strpos($html, $heading);
        $formAt    = strpos($html, 'name="current_password"');

        assert_true(
            $headingAt !== false && $formAt !== false && $headingAt < $formAt,
            'the page header precedes the form card'
        );
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

    'UX-30 the brand is spelled EcoWealth everywhere' => function (): void {
        $root  = dirname(__DIR__, 2);
        $files = glob($root . '/public/*.php') ?: [];

        foreach (['/app/views', '/app/layout'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $directory)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $offenders = [];

        foreach ($files as $file) {
            if (stripos((string) file_get_contents($file), 'eco-wealth') !== false) {
                $offenders[] = str_replace($root . '/', '', $file);
            }
        }

        assert_same([], $offenders, 'only the configured spelling is used in the templates');

        assert_contains(
            'alt="EcoWealth"',
            (string) file_get_contents($root . '/app/layout/logo.php'),
            'the logo alt is the configured brand'
        );
        assert_contains(
            'Sign in to <?= e(APP_NAME) ?>',
            (string) file_get_contents($root . '/public/login.php'),
            'the sign-in heading is the configured brand'
        );
    },

    'UX-31 the binary program uses one term per concept' => function (): void {
        $root  = dirname(__DIR__, 2);
        $files = [];

        foreach (['/app/views', '/app/layout'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $directory)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $offenders = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name   = str_replace($root . '/', '', $file);

            /* "Product binary" and "Package binary" are the payout programs;
             * "Binary bonus" is neither, and the placement view is a tree. */
            if (preg_match('/(?<!Product )(?<!Package )Binary bonus/', $source) === 1) {
                $offenders[] = $name . ': Binary bonus';
            }

            if (str_contains($source, 'Binary placement tree')) {
                $offenders[] = $name . ': Binary placement tree';
            }

            if (preg_match('/>Binary</', $source) === 1) {
                $offenders[] = $name . ': bare Binary label';
            }
        }

        assert_same([], $offenders, 'one term per concept in every template');

        $memberEarnings = (string) file_get_contents($root . '/app/views/member/earnings.php');
        assert_contains("'label' => 'Product binary'", $memberEarnings, 'the earnings tab names the payout program');

        assert_contains(
            '<h5 class="mb-1">Product binary</h5>',
            (string) file_get_contents($root . '/app/views/member/earnings/_binary_header.php'),
            'the statement heading names the payout program'
        );

        $genealogy = (string) file_get_contents($root . '/app/views/member/genealogy.php');
        assert_contains('<h5 class="card-title mb-0">Binary tree</h5>', $genealogy, 'the placement view is a binary tree');
        assert_contains(
            'aria-label="Binary tree"',
            (string) file_get_contents($root . '/app/views/member/genealogy/_tree.php'),
            'the no-JS tree carries the same name'
        );

        $packages = (string) file_get_contents($root . '/app/views/admin/packages/index.php');
        assert_contains('for="package-filter-binary">Package binary</label>', $packages, 'the package filter names the package binary');
        assert_contains('<th scope="col" class="text-end">Package binary</th>', $packages, 'the package column names the package binary');
    },

    'UX-32 the catalogue names the repeat-purchase bonus in full' => function (): void {
        $root = dirname(__DIR__, 2);

        $card = (string) file_get_contents($root . '/app/views/partials/product_card.php');
        assert_contains('% repeat-purchase bonus', $card, 'the catalogue badge uses the program name');

        /* The shop is the first place a member meets the program, so no
         * member-facing shop template may fall back to the short form. */
        $offenders = [];

        foreach (['/app/views/shop', '/app/views/partials'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $directory)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/(?<!repeat-purchase )repeat bonus/i', $source) === 1) {
                    $offenders[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        assert_same([], $offenders, 'the shop uses the program name, not the shorthand');

        assert_contains(
            "'label' => 'Repeat-purchase bonus'",
            (string) file_get_contents($root . '/app/views/member/earnings.php'),
            'the statement names the same program'
        );
    },

    'UX-33 the login page speaks in the product voice' => function (): void {
        $login = (string) file_get_contents(dirname(__DIR__, 2) . '/public/login.php');

        /* The template's stock auth copy, its emoji heading and its
         * title-cased checkbox are gone; the page reads like the product. */
        $retired = [
            'start the adventure',
            'Please sign-in to your account',
            'Remember Me',
            'New on our platform',
            '&#x1f44b;',
            'Login',
        ];

        foreach ($retired as $copy) {
            assert_not_contains($copy, $login, 'retired template copy: ' . $copy);
        }

        assert_contains('Sign in to <?= e(APP_NAME) ?>', $login, 'the heading names the configured product');
        assert_contains('Manage your membership, orders and e-wallet.', $login, 'the description names the job');
        assert_contains('Remember me', $login, 'the remember option is sentence-cased');
        assert_contains('New to <?= e(APP_NAME) ?>?', $login, 'the sign-up prompt names the configured product');
        assert_same(
            1,
            preg_match('/\?>>\s*Sign in\s*<\/button>/', $login),
            'the submit button reads "Sign in"'
        );
    },

    'UX-34 the layouts allow pinching to zoom' => function () use ($render): void {
        $root = dirname(__DIR__, 2);

        /* Zoom is a low-vision reading aid (WCAG 1.4.4), so no layout may
         * cap the scale. app_header serves the member, admin and plain
         * (error) layouts. */
        $page = $render('plain', 'errors/404', [
            'code'    => 404,
            'heading' => 'Page not found',
            'message' => 'The page you were looking for could not be found.',
        ]);

        assert_contains(
            'content="width=device-width, initial-scale=1"',
            $page,
            'the app layout leaves zoom free'
        );
        assert_same(1, substr_count($page, 'name="viewport"'), 'the app page declares one viewport');

        ob_start();

        try {
            $page_title = 'Sign in';
            require $root . '/app/layout/auth_header.php';
        } finally {
            $auth = (string) ob_get_clean();
        }

        assert_contains(
            'content="width=device-width, initial-scale=1"',
            $auth,
            'the auth layout leaves zoom free'
        );
        assert_same(1, substr_count($auth, 'name="viewport"'), 'the auth page declares one viewport');

        /* The sweep keeps a future layout or standalone page from turning
         * zoom back off. */
        $offenders = [];

        foreach (['/app/layout', '/app/views'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $directory)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/user-scalable\s*=\s*no|maximum-scale/i', $source) === 1) {
                    $offenders[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        assert_same([], $offenders, 'no template caps the zoom level');
    },

    'UX-35 the login password toggle is a control' => function (): void {
        $root  = dirname(__DIR__, 2);
        $login = (string) file_get_contents($root . '/public/login.php');

        /* The show/hide affordance used to be a span: no role, no focus,
         * no name and no state. It is now a real button whose name and
         * pressed state follow what it does. */
        assert_same(
            1,
            preg_match(
                '/<button\s+type="button"\s+class="input-group-text cursor-pointer"\s+data-password-toggle\s+aria-controls="password"\s+aria-label="Show password"\s+aria-pressed="false"\s*>/',
                $login
            ),
            'the password toggle is a button with a name, a target and a pressed state'
        );

        assert_not_contains('<span class="input-group-text cursor-pointer"', $login, 'the toggle is no longer a span');
        assert_not_contains('aria-describedby="password"', $login, 'the password input no longer describes itself');
        assert_not_contains('form-password-toggle', $login, 'the theme toggle no longer double-handles the click');

        $script = (string) file_get_contents($root . '/public/assets/js/app-login.js');
        assert_contains("input.type = show ? 'text' : 'password';", $script, 'the toggle reveals and hides the password');
        assert_contains("toggle.setAttribute('aria-pressed'", $script, 'the toggle reports its pressed state');
        assert_contains("toggle.setAttribute('aria-label'", $script, 'the toggle renames itself for the next action');
        assert_contains("\$auth_page_js = ['assets/js/app-login.js'];", $login, 'the page loads its toggle script');
    },

    'UX-09 the shared page header sets one page rhythm' => function (): void {
        $html = View::partial('page_header', [
            'title'       => 'Reports',
            'description' => 'Sales and payouts.',
            'attributes'  => ['data-live-region' => 'page-header'],
            'actions'     => '<a href="/reports.csv" class="btn btn-primary">Export</a>',
        ]);

        assert_contains('class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6"', $html);
        assert_contains('<h4 class="mb-1">Reports</h4>', $html);
        assert_contains('data-live-region="page-header"', $html);
        assert_contains('class="btn btn-primary"', $html);
        assert_not_contains('mb-4', $html);
    },

    'UX-09 portal pages do not hand-roll the old tighter page rhythm' => function (): void {
        $root      = dirname(__DIR__, 2) . '/app/views';
        $offenders = [];

        foreach (['admin', 'member', 'profile'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/class="d-flex[^"]*align-items-(?:start|center)[^"]*justify-content-between[^"]*gap-3 mb-4"/', $source) === 1) {
                    $offenders[] = $file->getPathname() . ': page header uses mb-4';
                }

                if (preg_match('/class="row g-4 mb-[46]"[^>]*data-live-region/', $source) === 1) {
                    $offenders[] = $file->getPathname() . ': section grid uses g-4';
                }
            }
        }

        assert_same([], $offenders, 'page headers and first-level card grids share the 24px rhythm');
    },

    'UX-09 card sections use the 24px page rhythm' => function (): void {
        $root      = dirname(__DIR__, 2) . '/app/views';
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            preg_match_all('/class="([^"]*)"/', $source, $matches);

            foreach ($matches[1] as $classList) {
                $classes = preg_split('/\s+/', trim($classList)) ?: [];

                if (!in_array('card', $classes, true)) {
                    continue;
                }

                foreach (['mb-4', 'mt-4'] as $spacing) {
                    if (in_array($spacing, $classes, true)) {
                        $offenders[] = $file->getPathname() . ': card uses ' . $spacing;
                    }
                }
            }
        }

        assert_same([], $offenders, 'card sections share the 24px page rhythm');
    },

    'UX-16 every filter reset is one labelled, icon-bearing Clear filters control' => function (): void {
        $root      = dirname(__DIR__, 2) . '/app/views';
        $offenders = [];
        $clears    = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/>\s*Clear\s*</', $source) === 1) {
                $offenders[] = $file->getPathname() . ': a filter reset still reads Clear';
            }

            if (str_contains($source, 'Start over')) {
                $offenders[] = $file->getPathname() . ': the checkout draft still reads Start over';
            }

            if ($file->getFilename() === 'catalog.php' && str_contains($source, 'aria-label="Clear search"')) {
                $offenders[] = $file->getPathname() . ': the storefront clear control has no visible label';
            }

            $clears += (int) preg_match_all('/bx-x me-1"><\/i> Clear filters/', $source);
        }

        assert_same([], $offenders, 'every filter reset uses one name and one treatment');
        assert_true($clears >= 20, 'the sweep finds the clear controls in the views');
    },

    'UX-17 every filter control carries a visible label' => function (): void {
        $root = dirname(__DIR__, 2) . '/app/views';

        /* The bars that used to rely on aria-label and placeholder text only,
         * with the number of controls each one labels. */
        $bars = [
            'admin/members/_filters.php'  => 6,
            'admin/activations/index.php' => 2,
            'admin/products/index.php'    => 4,
            'admin/packages/index.php'    => 3,
            'admin/wallets/index.php'     => 7,
            'admin/payouts/index.php'     => 3,
        ];

        $offenders = [];

        foreach ($bars as $path => $expected) {
            $source = (string) file_get_contents($root . '/' . $path);

            preg_match_all('/<label class="form-label" for="([^"]+)"/', $source, $labels);
            $labelIds = $labels[1] ?? [];

            if (count($labelIds) !== $expected) {
                $offenders[] = $path . ': expected ' . $expected . ' visible filter labels, found ' . count($labelIds);
            }

            foreach ($labelIds as $id) {
                if (!str_contains($source, 'id="' . $id . '"')) {
                    $offenders[] = $path . ': the label for "' . $id . '" points at no control';
                }
            }

            if (preg_match('/aria-label="(?:Search|Filter by)/', $source) === 1) {
                $offenders[] = $path . ': a filter control still relies on aria-label only';
            }
        }

        assert_same([], $offenders, 'every filter control names itself in a visible label');
    },

    'UX-18 every required field uses the one required marker' => function (): void {
        assert_same(
            '<span class="required" aria-hidden="true">*</span><span class="visually-hidden"> (required)</span>',
            required_marker(),
            'the marker pairs the asterisk with an accessible label'
        );

        $root      = dirname(__DIR__, 2) . '/app/views';
        $offenders = [];
        $markers   = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, '<span class="required">*</span>')) {
                $offenders[] = $file->getPathname() . ': the old unstyled asterisk is back';
            }

            if (str_contains($source, '<span class="text-danger">*</span>')) {
                $offenders[] = $file->getPathname() . ': a red asterisk bypasses required_marker()';
            }

            $markers += (int) preg_match_all('/required_marker\(\)/', $source);
        }

        assert_same([], $offenders, 'no view hand-rolls a required marker');
        assert_true($markers >= 50, 'the marker is applied across the forms');

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        assert_contains('.required {', $css, 'the marker is styled once in the application CSS');
    },

    'UX-19 same-role actions share one emphasis' => function () use ($render): void {
        $root      = dirname(__DIR__, 2) . '/app/views';
        $offenders = [];
        $exports   = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            /* The class attribute and the label sit in one tag, possibly
             * across a few concatenated lines. */
            if (preg_match_all(
                '/<(?:a|button)\b[^>]*class="(btn[^"]*)"[^>]*>(?:(?!<\/a>|<\/button>)[\s\S]){0,240}?Export CSV/',
                $source,
                $matches,
                PREG_SET_ORDER
            ) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $exports++;
                $classes = preg_split('/\s+/', trim($match[1])) ?: [];

                if (!in_array('btn-label-secondary', $classes, true)) {
                    $offenders[] = $file->getPathname() . ': Export CSV uses "' . $match[1] . '"';
                }
            }
        }

        assert_same([], $offenders, 'every export action is the same quiet secondary button');
        assert_same(4, $exports, 'the sweep finds the four export actions');

        /* A forward cross-link keeps the label weight instead of borrowing
         * the page's primary action. */
        $user = test_user('ux19_member');
        test_signed_in_as($user);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings';

        $controller = new EarningsController();

        try {
            $html = $render('member', 'member/earnings', $controller->earningsContext((int) $user['id']) + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
            ]);
        } finally {
            $_GET = [];
        }

        assert_contains(
            'class="btn btn-label-primary"><i class="icon-base bx bx-wallet me-1"></i> Open e-wallet</a>',
            $html,
            'the e-wallet cross-link matches the statement links'
        );
        assert_not_contains(
            'class="btn btn-primary"><i class="icon-base bx bx-wallet me-1"></i> Open e-wallet</a>',
            $html,
            'the cross-link does not take the page primary'
        );
    },

    'UX-12 the member earnings tabs keep two primary pills and one statement menu' => function () use ($render): void {
        $user = test_user('ux12_member');
        test_signed_in_as($user);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings';

        $controller = new EarningsController();

        try {
            $html = $render('member', 'member/earnings', $controller->earningsContext((int) $user['id']) + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
            ]);
        } finally {
            $_GET = [];
        }

        /* Two primary statements stay as pills; the rest are one menu. */
        assert_contains('class="nav nav-pills view-tabs earnings-tabs d-none d-md-flex"', $html);

        preg_match('/<ul class="nav nav-pills view-tabs earnings-tabs[^"]*"[\s\S]*?<li class="nav-item dropdown"/', $html, $pills);

        assert_same(2, preg_match_all('/class="nav-link(?: active)?"/', $pills[0] ?? ''), 'only the two primary statements are pills');
        assert_contains('Package commissions', $pills[0] ?? '');
        assert_contains('Repeat-purchase bonus', $pills[0] ?? '');

        /* The overflow menu carries the secondary streams. */
        assert_contains('id="earnings-more-statements"', $html);
        assert_contains('More statements', $html);

        preg_match('/<ul class="dropdown-menu" aria-labelledby="earnings-more-statements">([\s\S]*?)<\/ul>/', $html, $menu);
        assert_true($menu !== [], 'the More statements menu renders');

        preg_match_all('/<a\s+href="([^"]+)"/', $menu[1], $menuLinks);

        assert_same(url('earnings?ledger=royalty'), $menuLinks[1][0] ?? null);
        assert_same(url('earnings?ledger=team_harvest'), $menuLinks[1][1] ?? null);
        assert_same(url('earnings?ledger=general_harvest'), $menuLinks[1][2] ?? null);

        /* A phone gets one select over the same statements. */
        preg_match('/<select[^>]*data-statement-select[^>]*>([\s\S]*?)<\/select>/', $html, $select);
        assert_true($select !== [], 'the phone statement select renders');

        preg_match('/<optgroup label="Main statements">([\s\S]*?)<\/optgroup>/', $select[1], $mainGroup);
        preg_match('/<optgroup label="More statements">([\s\S]*?)<\/optgroup>/', $select[1], $moreGroup);

        assert_same(2, preg_match_all('/<option /', $mainGroup[1] ?? ''), 'two primary statements');
        assert_same(
            count($menuLinks[1]),
            preg_match_all('/<option /', $moreGroup[1] ?? ''),
            'the select and the menu list the same secondary statements'
        );
        assert_contains('<option value="package" selected>', $select[1]);

        /* A secondary tab keeps both switchers on that statement. */
        $_GET = ['ledger' => 'royalty'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?ledger=royalty';

        try {
            $royalty = $render('member', 'member/earnings', $controller->royaltyContext((int) $user['id']) + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
            ]);
        } finally {
            $_GET = [];
        }

        assert_contains('class="nav-link dropdown-toggle active"', $royalty, 'the menu names the active stream');
        assert_contains('<option value="royalty" selected>', $royalty, 'the phone select shows the active stream');
    },

    'UX-24 every header cell is scoped and report tables share one density' => function (): void {
        $root      = dirname(__DIR__, 2) . '/app/views';
        $offenders = [];
        $headers   = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path   = str_replace('\\', '/', $file->getPathname());
            $source = (string) file_get_contents($file->getPathname());

            /* A header cell must say which cells it describes: a column
             * header, or a row header in the definition tables. */
            preg_match_all('/<th\b([^>]*)>/i', $source, $matches);

            foreach ($matches[1] as $attributes) {
                $headers++;

                if (preg_match('/\bscope="(?:col|row|colgroup|rowgroup)"/', $attributes) !== 1) {
                    $offenders[] = $path . ': <th' . $attributes . '> has no scope';
                }
            }

            /* One density per context: a report table is the compact table
             * treatment, a page-level list keeps the default row height. */
            if (str_contains($path, '/admin/reports/')) {
                preg_match_all('/<table\b([^>]*)>/i', $source, $tables);

                foreach ($tables[1] as $attributes) {
                    if (!str_contains($attributes, 'table-sm') || !str_contains($attributes, 'report-table')) {
                        $offenders[] = $path . ': a report table leaves the report density';
                    }
                }
            }
        }

        assert_same([], $offenders, 'every header cell is scoped and report tables share one density');
        assert_true($headers >= 200, 'the sweep reads the tables');

        /* A level is a number even when it is printed as a badge: its header
         * and its cell sit on the same right edge as the money columns. */
        $levelColumns = [
            'admin/commissions/index.php'        => '<th scope="col" class="text-end">Lvl</th>',
            'admin/commissions/_repeat_rows.php' => '<td class="text-end text-nowrap">',
            'admin/orders/_unilevel.php'         => '<th scope="col" class="text-end">Lvl</th>',
        ];

        foreach ($levelColumns as $path => $needle) {
            assert_contains($needle, (string) file_get_contents($root . '/' . $path), $path . ' right-aligns its level column');
        }

        assert_contains(
            '<td class="text-end text-nowrap">L<?= (int) $award[\'level\'] ?></td>',
            (string) file_get_contents($root . '/admin/orders/_unilevel.php'),
            'the order ladder level cell is right-aligned'
        );
    },

    'UX-25 the notification bell only renders when it has something to show' => function () use ($render): void {
        test_signed_in_as(test_user('ux25_member'));

        $catalog = [
            'products'  => [],
            'available' => [],
            'pager'     => new Paginator(0, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => true,
            'page_title'  => 'Shop',
            'current_nav' => 'shop',
        ];

        $quiet = $render('member', 'shop/catalog', $catalog);

        assert_not_contains('dropdown-notifications', $quiet, 'no bell opens an empty notification centre');
        assert_not_contains('0 new', $quiet, 'the empty badge is never rendered');
        assert_not_contains('Nothing new right now', $quiet, 'the empty panel is gone');

        $notes = [
            ['icon' => 'bx-user-plus', 'tone' => 'success', 'title' => 'New member', 'text' => 'Joined 2 min ago', 'time' => ''],
            ['icon' => 'bx-package', 'tone' => 'primary', 'title' => 'New order', 'text' => 'Order #1042 paid', 'time' => ''],
        ];

        $loud = $render('member', 'shop/catalog', $catalog + ['nav_notes' => $notes]);

        assert_contains('class="nav-item dropdown-notifications navbar-dropdown dropdown', $loud, 'the bell renders with notes');
        assert_contains('aria-label="Notifications (2 new)"', $loud, 'the bell names its count');
        assert_contains('<span class="badge rounded-pill bg-label-primary">2 new</span>', $loud, 'the panel counts the notes');
        assert_contains('New member', $loud);
        assert_contains('New order', $loud);
        assert_not_contains('0 new', $loud, 'a populated bell never claims zero');
    },

    'UX-27 the admin sidebar separates personal destinations from the back office' => function () use ($render, $adminStats): void {
        test_signed_in_as(test_user('ux27_admin', 'admin'));

        $html = $render('admin', 'admin/dashboard', ['stats' => $adminStats, 'recent' => []]);

        $start = strpos($html, '<ul class="menu-inner py-1">');
        $end   = $start === false ? false : strpos($html, '</ul>', $start);

        assert_true($start !== false && $end !== false, 'the back-office sidebar renders');

        $sidebar = substr($html, (int) $start, (int) $end - (int) $start);

        /* Split the menu into its labelled sections, in render order. */
        preg_match_all(
            '/<span class="menu-header-text">([^<]+)<\/span>([\s\S]*?)(?=<span class="menu-header-text">|$)/',
            $sidebar,
            $sections,
            PREG_SET_ORDER
        );

        $menu = [];

        foreach ($sections as $section) {
            preg_match_all('/<div>([^<]+)<\/div>/', $section[2], $items);
            $menu[trim($section[1])] = $items[1];
        }

        /* Operational sections first; the admin's own destinations last. */
        assert_same(
            ['Overview', 'Catalogue', 'Commerce', 'Finance', 'System', 'My account'],
            array_keys($menu),
            'the back-office sections come first and one personal section closes the menu'
        );
        assert_true(!array_key_exists('Membership', $menu), 'no Membership section mixes personal and operational entries');

        /* "Payouts" is the queue; the admin's own withdrawals live elsewhere. */
        assert_same(
            ['Commissions', 'E-wallet monitor', 'Payouts', 'Reports'],
            $menu['Finance'] ?? [],
            'Finance holds only the operational money screens'
        );
        assert_same(
            ['Settings', 'Audit log'],
            $menu['System'] ?? [],
            'System holds only back-office tools'
        );

        $personal = [
            'My profile',
            'Change password',
            'My e-wallet',
            'Send funds',
            'My payouts',
            'My membership',
            'Register',
            'My genealogy',
            'Sign out',
        ];

        assert_same($personal, $menu['My account'] ?? [], 'My account lists every personal destination');

        $offenders = [];

        foreach ($menu as $label => $items) {
            if ($label === 'My account') {
                continue;
            }

            foreach ($personal as $item) {
                if (in_array($item, $items, true)) {
                    $offenders[] = $label . ' still lists ' . $item;
                }
            }
        }

        assert_same([], $offenders, 'no operational section carries a personal destination');
    },

    'UX-29 one date, time and month format across every view' => function () use ($render, $adminStats): void {
        /* The display shapes: a timestamp, a date, a clock time, a
         * month period and a chart tick. */
        assert_same('Nov 1, 2026 2:05 PM', format_datetime('2026-11-01 14:05:00'));
        assert_same('Nov 1, 2026', format_date('2026-11-01 14:05:00'));
        assert_same('Nov 1, 2026', format_date(strtotime('2026-11-01 14:05:00')), 'a Unix timestamp formats like a datetime');
        assert_same('2:05 PM', format_time('2026-11-01 14:05:00'));
        assert_same('November 2026', format_month('2026-11'));
        assert_same('November 2026', format_month('2026-11-01 14:05:00'));
        assert_same('not a period', format_month('not a period', 'not a period'), 'an unreadable period still names itself');
        assert_same('Nov 1', format_day('2026-11-01'));
        assert_same('—', format_date(null));
        assert_same('—', format_date(''));
        assert_same('—', format_datetime('0000-00-00 00:00:00'));

        /* No view formats a date itself: the five shapes the audit found,
         * and the split date/time cells, all go through the helpers. */
        $root = dirname(__DIR__, 2) . '/app/views';
        $raw  = [
            "date('M j, Y g:i A'",
            "date('M j, Y'",
            "date('j M Y'",
            "date('M Y'",
            "date('F Y'",
            "date('D, M j, Y')",
            "date('g:i A'",
            "date('M j'",
        ];

        $offenders = [];
        $files     = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files++;
            $source = (string) file_get_contents($file->getPathname());

            foreach ($raw as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $file->getPathname() . ': ' . $needle;
                }
            }
        }

        assert_same([], $offenders, 'every view uses the shared formatters');
        assert_true($files >= 300, 'the sweep reads the whole view tree');

        /* The dashboard's welcome date is the shared date. */
        test_signed_in_as(test_user('ux29_admin', 'admin'));
        $html = $render('admin', 'admin/dashboard', ['stats' => $adminStats, 'recent' => []]);

        assert_contains(format_date('now'), $html, 'the welcome date uses the shared date format');
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
