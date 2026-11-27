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
