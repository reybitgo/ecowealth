<?php
declare(strict_types=1);

/* ==============================================================
 *  CartViewTest — cart page, off-canvas drawer and cart controls
 *  (plan §5.2, §7 Phase 4; tests P4-T1 … P4-T13)
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

/** The sidebar block from a rendered page (empty when there is no menu). */
$sidebar = static function (string $html): string {
    $start = strpos($html, '<ul class="menu-inner py-1">');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, '</ul>', $start);

    return $end === false ? '' : substr($html, $start, $end - $start);
};

/** Product fixture with a predictable name/price/stock. */
$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Cart View Product ' . bin2hex(random_bytes(3)),
        'price'  => '300.00',
        'stock'  => '20',
        'status' => 'active',
    ]);
};

/** Cart state for a member, as the controller would build it. */
$stateFor = static function (int $userId): array {
    return (new CartService())->state($userId);
};

return [
    'P4-T1 the cart page renders lines, totals and no-JS forms' => function () use ($render, $makeProduct, $stateFor): void {
        $member  = test_user('cart_view_member');
        $product = $makeProduct(['name' => 'Coffee', 'price' => '300.00']);

        (new CartService())->add((int) $member['id'], $product, 2);
        test_signed_in_as($member);

        $html = $render('member', 'shop/cart', [
            'state'     => $stateFor((int) $member['id']),
            'shop_open' => true,
        ]);

        assert_contains('Your cart', $html);
        assert_contains('Coffee', $html);
        assert_contains(money(300), $html, 'unit price');
        assert_contains(money(600), $html, 'line total and subtotal');
        assert_contains('value="2"', $html);
        assert_contains('action="' . url('cart/update') . '"', $html);
        assert_contains('action="' . url('cart/remove') . '"', $html);
        assert_contains('name="item_id"', $html);
        assert_contains('name="_csrf"', $html);

        /* The steppers are real submit buttons (no-JS fallback), with a hidden
         * default submit button first so Enter posts the typed value. */
        assert_contains('name="direction" value="down"', $html);
        assert_contains('name="direction" value="up"', $html);
        $defaultSubmit = strpos($html, 'class="visually-hidden" tabindex="-1"');
        $firstStep     = strpos($html, 'name="direction" value="down"');
        assert_true(
            $defaultSubmit !== false && $firstStep !== false && $defaultSubmit < $firstStep,
            'the default submit button must come before the stepper buttons'
        );

        /* The steppers are the only visible quantity controls: the old
         * "Update" button was removed as redundant (a typed value saves on
         * change, and Enter still posts through the hidden default). */
        assert_not_contains('cart-update-btn', $html);
        assert_same(2, substr_count($html, 'name="direction"'), 'only the two steppers submit the quantity');

        assert_contains('Proceed to checkout', $html);
        assert_contains('href="' . url('checkout') . '"', $html);
        assert_contains('Choose the delivery address', $html);
        assert_not_contains('Checkout opens in Phase 6', $html);
        assert_contains('Continue shopping', $html);
    },

    'P4-T4 a typed quantity saves on change, without a separate Update button' => function (): void {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app-cart.js');

        assert_contains("input.matches('[data-cart-qty]')", $js, 'the change listener targets quantity inputs');
        assert_contains('updateFrom(form);', $js, 'a change posts the typed value');
        assert_not_contains('cart-update-btn', $js, 'the script does not depend on the removed button');
    },

    'P4-T1 an empty cart shows the browse state and no table' => function () use ($render, $stateFor): void {
        $member = test_user('cart_view_empty');
        test_signed_in_as($member);

        $html = $render('member', 'shop/cart', [
            'state'     => $stateFor((int) $member['id']),
            'shop_open' => true,
        ]);

        assert_contains('Your cart is empty', $html);
        assert_contains('Browse the shop', $html);
        assert_not_contains('Proceed to checkout', $html);
        assert_not_contains('data-cart-page-items', $html);
    },

    'P4-T13 an over-stock line blocks checkout and explains why' => function () use ($render, $makeProduct, $stateFor): void {
        $member  = test_user('cart_view_stock');
        $product = $makeProduct(['name' => 'Scarce Item', 'price' => '50.00', 'stock' => '5']);

        (new CartService())->add((int) $member['id'], $product, 3);

        Product::save([
            'name'   => 'Scarce Item',
            'price'  => '50.00',
            'stock'  => '1',
            'status' => 'active',
        ], $product);

        test_signed_in_as($member);

        $html = $render('member', 'shop/cart', [
            'state'     => $stateFor((int) $member['id']),
            'shop_open' => true,
        ]);

        assert_contains('Some items need attention before checkout', $html);
        assert_contains('Scarce Item: requested 3, only 1 available.', $html);
        assert_contains('Only 1 left', $html);
        assert_contains('Fix the quantities marked above to continue.', $html);

        /* The page-level summary switches its hint and the checkout link
         * stays disabled while a line is over stock. */
        assert_not_contains('Total is what you pay — no shipping or service fee.', $html);
        assert_not_contains('href="' . url('checkout') . '"', $html);
    },

    'P4-T10 an inactive product line is flagged as unavailable' => function () use ($render, $makeProduct, $stateFor): void {
        $member  = test_user('cart_view_inactive');
        $product = $makeProduct(['name' => 'Retired Item']);

        (new CartService())->add((int) $member['id'], $product, 1);
        Product::setStatus($product, 'inactive');

        test_signed_in_as($member);

        $html = $render('member', 'shop/cart', [
            'state'     => $stateFor((int) $member['id']),
            'shop_open' => true,
        ]);

        assert_contains('Unavailable', $html);
        assert_contains('is no longer available. Remove it before checking out.', $html);
    },

    'P4-T9 product names in the cart are escaped' => function () use ($render, $makeProduct, $stateFor): void {
        $member  = test_user('cart_view_xss');
        $product = $makeProduct(['name' => '<script>alert(1)</script>']);

        (new CartService())->add((int) $member['id'], $product, 1);
        test_signed_in_as($member);

        $html = $render('member', 'shop/cart', [
            'state'     => $stateFor((int) $member['id']),
            'shop_open' => true,
        ]);

        assert_not_contains('<script>alert(1)</script>', $html);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    },

    'P4-T1 a closed shop still shows the saved cart with a notice' => function () use ($render, $makeProduct, $stateFor): void {
        $member  = test_user('cart_view_closed');
        $product = $makeProduct(['name' => 'Saved Item']);

        (new CartService())->add((int) $member['id'], $product, 1);
        test_signed_in_as($member);

        $html = $render('member', 'shop/cart', [
            'state'     => $stateFor((int) $member['id']),
            'shop_open' => false,
        ]);

        assert_contains('The shop is currently closed', $html);
        assert_contains('Saved Item', $html);
    },

    'P4-T13 the off-canvas drawer renders lines, count and totals' => function () use ($makeProduct, $stateFor): void {
        $member  = test_user('cart_drawer_member');
        $product = $makeProduct(['name' => 'Drawer Coffee', 'price' => '300.00']);

        (new CartService())->add((int) $member['id'], $product, 2);

        $html = View::partial('cart_offcanvas', ['state' => $stateFor((int) $member['id'])]);

        assert_contains('id="cartOffcanvas"', $html);
        assert_contains('id="cartOffcanvasBody"', $html);
        assert_contains('Drawer Coffee', $html);
        assert_contains('data-cart-count', $html);
        assert_contains('data-cart-subtotal', $html);
        assert_contains(money(600), $html);
        assert_contains('action="' . url('cart/update') . '"', $html);
        assert_contains('action="' . url('cart/remove') . '"', $html);
        assert_contains('View full cart', $html);
        assert_contains('href="' . url('checkout') . '"', $html);
    },

    'P4-T1 the empty drawer invites the member to the shop' => function (): void {
        $html = View::partial('cart_offcanvas', [
            'state' => [
                'cart'   => null,
                'items'  => [],
                'totals' => ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0],
            ],
        ]);

        assert_contains('Your cart is empty', $html);
        assert_contains('Browse the shop', $html);
        assert_contains('data-cart-count', $html);
        assert_not_contains('View full cart', $html);
    },

    'P4-T13 the navbar, sidebar and drawer carry the live badge' => function () use ($render, $makeProduct): void {
        $member  = test_user('cart_badge_member');
        $product = $makeProduct(['name' => 'Badge Item']);

        (new CartService())->add((int) $member['id'], $product, 2);
        test_signed_in_as($member);

        $html = $render('member', 'shop/catalog', [
            'products'  => [],
            'available' => [],
            'pager'     => new Paginator(0, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => true,
        ]);

        assert_contains('data-cart-badge', $html);
        assert_contains('>2</span>', $html);
        assert_contains('href="' . url('cart') . '"', $html);
        assert_contains('id="cartConfig"', $html);
        assert_contains('data-add-url="' . url('cart/add') . '"', $html);
        assert_contains('app-cart.js', $html);
        assert_contains('id="toastContainer"', $html);
        assert_contains('toast-container position-fixed end-0 p-3', $html);
        assert_not_contains('toast-container position-fixed bottom-0', $html);
        assert_contains('top: 5rem;', (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/app.css'
        ));
        assert_contains('right: 27rem !important;', (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/app.css'
        ));

        /* The sidebar renders the cart badge from $nav_badges. */
        assert_contains('badge rounded-pill bg-label-primary ms-auto', $html);
    },

    'P4-T1 the catalogue card offers a no-JS add-to-cart form' => function () use ($render): void {
        $member = test_user('cart_card_member');
        test_signed_in_as($member);

        $product = [
            'id'                => 41,
            'sku'               => 'CARD-1',
            'name'              => 'Card Coffee',
            'slug'              => 'card-coffee',
            'price'             => '300.00',
            'stock'             => 10,
            'image_url'         => null,
            'short_description' => '',
            'description'       => '',
            'status'            => 'active',
            'sort_order'        => 0,
            'created_at'        => '2026-11-04 10:00:00',
            'updated_at'        => '2026-11-04 10:00:00',
        ];

        $html = $render('member', 'shop/catalog', [
            'products'  => [$product],
            'available' => [41 => 10],
            'pager'     => new Paginator(1, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => true,
        ]);

        assert_contains('action="' . url('cart/add') . '"', $html);
        assert_contains('data-cart-add', $html);
        assert_contains('name="product_id"', $html);
        assert_contains('name="quantity"', $html);
        assert_contains('Add Card Coffee to cart', $html);
    },

    'P4-T13 the admin sidebar also carries the cart menu with the live badge' => function () use ($render, $makeProduct, $stateFor, $sidebar): void {
        $admin   = test_user('cart_admin_nav', 'admin');
        $product = $makeProduct(['name' => 'Admin Cart Item', 'price' => '10.00']);

        (new CartService())->add((int) $admin['id'], $product, 2);
        test_signed_in_as($admin);

        $html = $render('admin', 'shop/cart', [
            'state'       => $stateFor((int) $admin['id']),
            'shop_open'   => true,
            'current_nav' => 'admin.cart',
        ]);

        $menu = $sidebar($html);

        assert_contains('admin/products', $menu, 'the cart page keeps the back-office menu');
        assert_contains('href="' . url('cart') . '"', $menu, 'the sidebar links to the cart');
        assert_contains('<div>Cart</div>', $menu, 'the sidebar shows the Cart item');
        assert_contains('data-nav-badge="cart"', $menu);
        assert_contains('data-cart-badge', $menu, 'the sidebar badge is a cart-updatable element');
        assert_contains('>2</span>', $menu, 'the badge shows the live count');
        assert_contains('menu-item active', $menu, 'the active item is the admin cart');
        assert_contains('aria-current="page"', $menu);
    },

    'P4-T13 the member sidebar still shows the cart without admin items' => function () use ($render, $makeProduct, $stateFor, $sidebar): void {
        $member  = test_user('cart_member_nav');
        $product = $makeProduct(['name' => 'Member Cart Item']);

        (new CartService())->add((int) $member['id'], $product, 1);
        test_signed_in_as($member);

        $html = $render('member', 'shop/cart', [
            'state'     => $stateFor((int) $member['id']),
            'shop_open' => true,
        ]);

        $menu = $sidebar($html);

        assert_contains('<div>Cart</div>', $menu);
        assert_contains('data-nav-badge="cart"', $menu);
        assert_contains('data-cart-badge', $menu);
        assert_contains('>1</span>', $menu);
        assert_not_contains('admin/products', $menu, 'members never see back-office items');
    },

    'P4-T13 an empty cart keeps the sidebar badge hidden for the script to reveal' => function () use ($render, $sidebar): void {
        test_signed_in_as(test_user('cart_nav_empty'));

        $html = $render('member', 'shop/catalog', [
            'products'  => [],
            'available' => [],
            'pager'     => new Paginator(0, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => true,
        ]);

        $menu = $sidebar($html);

        /* The element must exist at zero: the AJAX add reveals it client-side
         * instead of waiting for a page reload. */
        assert_contains('data-cart-badge', $menu);
        assert_contains('>0</span>', $menu);
        assert_true(
            preg_match('/data-cart-badge[^>]*\shidden/', $menu) === 1,
            'a zero sidebar badge must start hidden'
        );
    },

    'P4-T13 the cart drawer and navbar badge are styled by the global shell sheet' => function (): void {
        $public = dirname(__DIR__, 2) . '/public/assets/css/';
        $app    = (string) file_get_contents($public . 'app.css');
        $shop   = (string) file_get_contents($public . 'shop.css');

        /* Both elements render on every signed-in page, so their rules must be
         * in app.css; shop.css only loads on the catalogue and cart pages. */
        foreach (['.cart-navbar-badge', '.cart-drawer', '.cart-drawer-media', '.cart-qty-group'] as $selector) {
            assert_contains($selector, $app, $selector . ' belongs in app.css');
        }

        assert_contains('#layout-navbar .cart-navbar-badge', $app, 'the theme .btn .badge rule must be outranked');
        assert_not_contains('cart-navbar-badge', $shop, 'the navbar badge must not depend on shop.css');
        assert_not_contains('.cart-drawer {', $shop, 'the drawer must not depend on shop.css');

        /* The shared stepper sizes to its content with a fixed, readable value
         * field, so two/three-digit quantities never clip in the drawer. */
        assert_true(
            str_contains($app, '.cart-qty-group input[type=\'number\']'),
            'the cart quantity input needs a fixed width rule'
        );
        assert_true(
            str_contains($app, 'flex: 0 0 2.75rem'),
            'the cart quantity input must not shrink below its digit width'
        );
        assert_not_contains(
            '.cart-drawer .cart-qty-group',
            $app,
            'the drawer must not override the shared stepper width'
        );
    },

    'P4-T5 the cart page never leaks another member’s line' => function () use ($render, $makeProduct, $stateFor): void {
        $owner = test_user('cart_view_owner');
        $other = test_user('cart_view_other');

        (new CartService())->add((int) $owner['id'], $makeProduct(['name' => 'Owner Only']), 1);
        (new CartService())->add((int) $other['id'], $makeProduct(['name' => 'Other Only']), 1);

        test_signed_in_as($other);

        $html = $render('member', 'shop/cart', [
            'state'     => $stateFor((int) $other['id']),
            'shop_open' => true,
        ]);

        assert_contains('Other Only', $html);
        assert_not_contains('Owner Only', $html);
    },
];
