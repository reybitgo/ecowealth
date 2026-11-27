<?php
declare(strict_types=1);

/* ==============================================================
 *  SearchTest — UX-26: the navbar search reaches every destination
 *  it advertises, and the results page groups them.
 *
 *  The model reads are the real ones (the harness database is
 *  rebuilt and seeded); nothing here re-implements a destination's
 *  filter. Each case runs in the harness's rolled-back transaction.
 * ============================================================== */

/** Render a page through View::page() and return the HTML. */
$render = static function (string $layout, string $template, array $data = []): string {
    ob_start();

    try {
        View::page($layout, $template, $data);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

$catalog = [
    'products'  => [],
    'available' => [],
    'pager'     => new Paginator(0, 12, 1),
    'filters'   => ['q' => ''],
    'shop_open' => true,
];

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
    'UX-26 the navbar search is global and names what it covers' => function () use ($render, $catalog, $adminStats): void {
        test_signed_in_as(test_user('ux26_member'));

        $member = $render('member', 'shop/catalog', $catalog);

        assert_contains('data-search-url="' . url('search') . '"', $member, 'the default search is the global page');
        assert_contains('placeholder="Search products and your orders…"', $member);
        assert_contains('aria-label="Search products and your orders"', $member);
        assert_not_contains('data-search-url="' . url('admin/products') . '"', $member);

        test_signed_in_as(test_user('ux26_admin', 'admin'));

        $admin = $render('admin', 'admin/dashboard', ['stats' => $adminStats, 'recent' => []]);

        assert_contains('data-search-url="' . url('search') . '"', $admin);
        assert_contains('placeholder="Search products, members, orders, codes…"', $admin);
        assert_contains('aria-label="Search products, members, orders and registration codes"', $admin);
        assert_not_contains('data-search-url="' . url('admin/products') . '"', $admin, 'the admin header no longer jumps to products');

        /* A page that scopes the field keeps its target and hint. */
        $scoped = $render('member', 'shop/catalog', $catalog + [
            'nav_search_placeholder' => 'Search the shop…',
            'nav_search_url'         => url('shop'),
        ]);

        assert_contains('data-search-url="' . url('shop') . '"', $scoped);
        assert_contains('placeholder="Search the shop…"', $scoped);
    },

    'UX-26 an admin search groups products, members, orders and codes' => function (): void {
        test_signed_in_as(test_user('ux26_admin', 'admin'));

        $member  = test_user('ux26_member');
        $orderId = test_order((int) $member['id'], [], 'paid');

        /* test_order() keeps its generated number over an override. */
        db()->prepare('UPDATE shop_orders SET order_no = ? WHERE id = ?')
            ->execute(['UX26-ORDER-1', $orderId]);

        $search = new SearchController();

        $coffee = $search->results('coffee');
        assert_same(['products'], array_column($coffee['groups'], 'key'), 'a product name reaches the catalogue');
        assert_same(1, $coffee['total']);
        assert_same('coffee', $coffee['query']);

        /* The same username also matches that member's order, so the order
         * preview is legitimately part of the answer. */
        $people = $search->results('ux26_member');
        assert_same(['members', 'orders'], array_column($people['groups'], 'key'), 'a username reaches the member directory');
        assert_same('@ux26_member', $people['groups'][0]['rows'][0]['title']);

        $orders = $search->results('UX26-ORDER-1');
        assert_same(['orders'], array_column($orders['groups'], 'key'), 'an order number reaches the order desk');
        assert_same('UX26-ORDER-1', $orders['groups'][0]['rows'][0]['title']);

        /* "DEMO" also appears in the demo members' e-mail addresses, so the
         * directory may answer beside the codes; the code group must be there. */
        $codes = $search->results('DEMO');
        assert_true(in_array('codes', array_column($codes['groups'], 'key'), true), 'a code reaches the registration-code list');
        $codeGroup = $codes['groups'][array_search('codes', array_column($codes['groups'], 'key'), true)];
        assert_true($codeGroup['total'] >= 3, 'the demo codes are found');

        /* Every preview links onward to the destination with its query. */
        assert_contains('admin/orders?q=UX26-ORDER-1', $orders['groups'][0]['all_url']);
        assert_contains('admin/codes?q=DEMO', $codeGroup['all_url']);

        /* No match anywhere is an empty group list, not an empty card. */
        $none = $search->results('zz-no-such-thing-anywhere');
        assert_same([], $none['groups']);
        assert_same(0, $none['total']);
    },

    'UX-26 a member searches the catalogue and their own orders, not the back office' => function (): void {
        $me = test_user('ux26_shopper');
        test_signed_in_as($me);

        $mineId  = test_order((int) $me['id'], [], 'pending');
        $otherId = test_order((int) test_user('ux26_other')['id'], [], 'pending');

        /* test_order() keeps its generated number over an override. */
        db()->prepare('UPDATE shop_orders SET order_no = ? WHERE id = ?')
            ->execute(['UX26-SHOP-1', $mineId]);
        db()->prepare('UPDATE shop_orders SET order_no = ? WHERE id = ?')
            ->execute(['UX26-OTHER-1', $otherId]);

        $search = new SearchController();

        $mine = $search->results('UX26-SHOP-1');
        assert_same(['orders'], array_column($mine['groups'], 'key'));
        assert_same('products and your own orders', $mine['scope']);

        $theirs = $search->results('UX26-OTHER-1');
        assert_same([], $theirs['groups'], 'another member\'s order is never a result');
        assert_same(1, $mine['groups'][0]['total']);

        /* The member scope has no member directory or code list. */
        assert_same([], $search->results('DEMO')['groups']);
    },

    'UX-26 the results page groups matches and uses the shared empty state' => function () use ($render): void {
        test_signed_in_as(test_user('ux26_admin', 'admin'));

        $search = new SearchController();

        $html = $render('admin', 'search/index', $search->results('coffee') + [
            'page_title'       => 'Search',
            'nav_search_value' => 'coffee',
        ]);

        assert_contains('1 result for', $html);
        assert_contains('<h5 class="card-title mb-0">Products</h5>', $html);
        assert_contains('View all products', $html);
        assert_contains('value="coffee"', $html, 'the box keeps the query');
        assert_contains('Coffee', $html);
        assert_contains('empty-state', $render('admin', 'search/index', $search->results('zz-no-such-thing') + [
            'page_title' => 'Search',
        ]));

        $none = $render('admin', 'search/index', $search->results('zz-no-such-thing') + [
            'page_title' => 'Search',
        ]);

        assert_contains('No matches for', $none);
        assert_contains('zz-no-such-thing', $none);
        assert_not_contains('list-group-item', $none, 'no empty destination card is rendered');

        $empty = $render('admin', 'search/index', $search->results('') + [
            'page_title' => 'Search',
        ]);

        assert_contains('What are you looking for?', $empty);
        assert_contains('products, members, orders and registration codes', $empty);
    },
];
