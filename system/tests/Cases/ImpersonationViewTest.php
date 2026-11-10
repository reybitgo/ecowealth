<?php
declare(strict_types=1);

/* ==============================================================
 *  ImpersonationViewTest — the S-Login surfaces a browser sees
 *  (plan §3.4, §3.5, §7 Phase 14)
 *
 *  The banner in the member layout, its Exit form, the token on the
 *  links around it, the superadmin-only console menu entry and the
 *  audit screen. The HTTP session switch itself is covered by
 *  tests/smoke.ps1.
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

$makeSuper = static fn (): array => test_user('impv_super_' . bin2hex(random_bytes(4)), 'superadmin');
$makeMember = static fn (): array => test_user('impv_member_' . bin2hex(random_bytes(4)));

$catalog = [
    'products'  => [],
    'available' => [],
    'pager'     => new Paginator(0, 12, 1),
    'filters'   => ['q' => ''],
    'shop_open' => true,
];

return [
    'P14-T1 the member layout shows the banner while impersonating' => function () use ($render, $makeSuper, $makeMember, $catalog): void {
        $super  = $makeSuper();
        $member = $makeMember();

        $issued = (new ImpersonationService())->start((string) $member['username'], $super, '203.0.113.7', 'UA-view');
        ImpersonationService::bind($issued);
        auth_cache_reset();

        $html = $render('member', 'shop/catalog', $catalog);

        assert_contains('data-impersonation-banner', $html);
        assert_contains('Viewing as @' . $member['username'], $html);
        assert_contains('opened by @' . $super['username'], $html);
        assert_contains('Exit member session', $html);
        assert_contains('action="' . url('slogin/exit') . '"', $html);
        assert_contains('name="_csrf"', $html, 'the Exit form is CSRF protected');
        assert_contains('imp=' . $issued['nonce'], $html, 'the page URLs carry the token');
        assert_contains('ends automatically at', $html, 'the banner says when the session ends');

        /* The tab is a member tab: no back-office menu. */
        assert_not_contains('E-wallet monitor', $html);
        assert_not_contains('S-Login console', $html);
    },

    'P14-T2 a normal member page has no banner' => function () use ($render, $makeMember, $catalog): void {
        test_signed_in_as($makeMember());

        $html = $render('member', 'shop/catalog', $catalog);

        assert_not_contains('data-impersonation-banner', $html);
        assert_not_contains('Viewing as @', $html);
        assert_not_contains('Exit member session', $html);
    },

    'P14-T3 the banner is always present and never dismissible' => function () use ($render, $makeSuper, $makeMember, $catalog): void {
        $super  = $makeSuper();
        $member = $makeMember();

        $issued = (new ImpersonationService())->start((string) $member['username'], $super, '203.0.113.7', 'UA-view');
        ImpersonationService::bind($issued);
        auth_cache_reset();

        $html = $render('member', 'shop/catalog', $catalog);

        assert_not_contains('btn-close', substr($html, (int) strpos($html, 'data-impersonation-banner'), 2500), 'the banner has no dismiss control');
        assert_contains('role="status"', $html);
        assert_contains('Every page you open here is as this member', $html);
    },

    'P14-T4 the S-Login console is offered to superadmins only' => function () use ($render): void {
        $stats = [
            'accounts' => 1, 'accounts_active' => 1, 'members' => 0, 'admins' => 2,
            'new_accounts' => 1, 'products' => 0, 'products_active' => 0,
            'stock_units' => 0, 'inventory_value' => 0.0,
            'packages' => 0, 'packages_active' => 0, 'package_value' => 0.0,
        ];

        test_signed_in_as(test_user('impv_sadmin_' . bin2hex(random_bytes(4)), 'superadmin'));
        $superHtml = $render('admin', 'admin/dashboard', ['stats' => $stats, 'recent' => []]);

        assert_contains('S-Login console', $superHtml);
        assert_contains('href="' . url('slogin') . '"', $superHtml);

        test_signed_in_as(test_user('impv_admin_' . bin2hex(random_bytes(4)), 'admin'));
        $adminHtml = $render('admin', 'admin/dashboard', ['stats' => $stats, 'recent' => []]);

        assert_not_contains('S-Login console', $adminHtml);
        assert_not_contains('href="' . url('slogin') . '"', $adminHtml);
    },

    'P14-T5 the audit screen shows statuses but never a nonce hash' => function () use ($render, $makeSuper, $makeMember): void {
        $super  = $makeSuper();
        $member = $makeMember();

        $issued = (new ImpersonationService())->start((string) $member['username'], $super, '203.0.113.9', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/121');
        Impersonation::mark((int) $issued['log_id'], 'logged_out');

        test_signed_in_as($super);

        $sessions = Impersonation::queue(Impersonation::normaliseFilters([]), new Paginator(1, 10, 1));

        $html = $render('admin', 'admin/impersonations/index', [
            'filters'     => Impersonation::normaliseFilters([]),
            'sessions'    => $sessions,
            'pager'       => new Paginator(1, 10, 1),
            'counts'      => Impersonation::statusCounts(),
            'totals'      => Impersonation::totals(),
            'swept'       => 0,
            'console_url' => url('slogin'),
            'audit_url'   => url('admin/impersonations'),
        ]);

        assert_contains('Super-login audit', $html);
        assert_contains('@' . $member['username'], $html);
        assert_contains('@' . $super['username'], $html);
        assert_contains('203.0.113.9', $html);
        assert_contains('Firefox · Linux', $html);
        assert_contains('Logged out', $html);
        assert_contains('Sessions today', $html);
        assert_contains('All sessions', $html);

        assert_not_contains((string) $issued['nonce'], $html, 'the raw token is absent');
        assert_not_contains(hash('sha256', (string) $issued['nonce']), $html, 'even the stored hash is not rendered');
    },

    'P14-T6 the audit screen has an empty state and filters' => function () use ($render): void {
        test_signed_in_as(test_user('impv_empty_' . bin2hex(random_bytes(4)), 'superadmin'));

        $filters = Impersonation::normaliseFilters([]);

        $html = $render('admin', 'admin/impersonations/index', [
            'filters'     => $filters,
            'sessions'    => [],
            'pager'       => new Paginator(0, 10, 1),
            'counts'      => Impersonation::statusCounts(),
            'totals'      => Impersonation::totals(),
            'swept'       => 0,
            'console_url' => url('slogin'),
            'audit_url'   => url('admin/impersonations'),
        ]);

        assert_contains('No session matches these filters', $html);
        assert_contains('name="q"', $html);
        assert_contains('name="from"', $html);
        assert_contains('name="to"', $html);
        assert_contains('href="' . url('slogin') . '"', $html);
    },
];
