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

$adminStats = ['members' => 1, 'members_active' => 1, 'admins' => 2, 'settings' => 41];

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

        $html = $render('member', 'shop/placeholder', ['shop_open' => true]);

        assert_contains('id="layout-menu"', $html);
        assert_contains('Dashboard', $html);
        assert_contains('Shop', $html);
        assert_contains('Change password', $html);

        /* P1-T8: member pages never expose back-office items. */
        assert_not_contains('Registration codes', $html);
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
        assert_contains('Registration codes', $html);
        assert_contains('Payouts', $html);
        assert_contains('Newest accounts', $html);
    },

    'View::page hides later-phase items from the superadmin console for a plain admin' => function () use ($render, $adminStats): void {
        test_signed_in_as(test_user('layout_admin', 'admin'));

        $html = $render('admin', 'admin/dashboard', ['stats' => $adminStats, 'recent' => []]);

        /* S-Login is superadmin-only (plan §3.4) — never rendered for an admin. */
        assert_not_contains('S-Login console', $html);
        assert_not_contains('href="' . url('slogin') . '"', $html);
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

    'View::page renders the shop placeholder for members' => function () use ($render): void {
        test_signed_in_as(test_user('layout_member'));

        $html = $render('member', 'shop/placeholder', ['shop_open' => false]);

        assert_contains('The shop is being prepared', $html);
        assert_contains('switched off by the administrator', $html);
        assert_contains('Phase 2', $html);
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
        $links = View::partial('pagination', ['pager' => $pager]);

        assert_contains('Showing 21–40 of 57', $links);
        assert_contains('page=3', $links);
        assert_contains('page=1', $links);
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
