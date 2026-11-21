<?php
declare(strict_types=1);

/* ==============================================================
 *  CartLiveTest — the member's own cart page
 *
 *  One live surface per member (`member.cart:{id}`). These cases pin:
 *
 *    · every successful cart write bumps that member's cart page and the
 *      checkout page, and only that member's surfaces;
 *    · a refused write (an over-stock line, someone else's line) bumps
 *      nothing;
 *    · the feed renders the one `cart-content` region for a full cart, an
 *      empty cart and a closed shop, and escapes product text;
 *    · the page declares its live surface, holds the quantity forms and
 *      loads the shared stale banner.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Cart Live Product ' . bin2hex(random_bytes(4)),
        'price'  => '300.00',
        'stock'  => '20',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('clv_' . bin2hex(random_bytes(4)));
};

/** The empty cart state the controller would build for a fresh member. */
$emptyState = static fn (): array => [
    'cart'         => null,
    'items'        => [],
    'totals'       => ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0],
    'stock_errors' => [],
];

return [
    'cart surfaces are per member and bump on every cart write' => function () use ($makeProduct, $makeMember): void {
        $member  = $makeMember();
        $other   = $makeMember();
        $id      = (int) $member['id'];
        $otherId = (int) $other['id'];
        $carts   = new CartService();

        assert_same('member.cart:' . $id, Live::memberCartSurface($id));

        $productId = $makeProduct(['price' => '100.00']);

        $cartBefore     = Live::revision(Live::memberCartSurface($id));
        $checkoutBefore = Live::revision(Live::checkoutSurface($id));
        $otherBefore    = Live::revision(Live::memberCartSurface($otherId));

        $result = $carts->add($id, $productId, 2);

        assert_same($cartBefore + 1, Live::revision(Live::memberCartSurface($id)), 'add');
        assert_same($checkoutBefore + 1, Live::revision(Live::checkoutSurface($id)), 'add bumps checkout');
        assert_same($otherBefore, Live::revision(Live::memberCartSurface($otherId)), 'another member is untouched');

        $itemId = (int) $result['item']['id'];

        $before = Live::revision(Live::memberCartSurface($id));
        $carts->updateQuantity($id, $itemId, 3);
        assert_same($before + 1, Live::revision(Live::memberCartSurface($id)), 'typed quantity');

        $before = Live::revision(Live::memberCartSurface($id));
        $carts->adjustQuantity($id, $itemId, 1);
        assert_same($before + 1, Live::revision(Live::memberCartSurface($id)), 'step button');

        /* Over-stock: the write is refused, so no tab redraws. */
        $before = Live::revision(Live::memberCartSurface($id));

        try {
            $carts->updateQuantity($id, $itemId, 999);
            fail('an over-stock quantity must be refused');
        } catch (CartException) {
        }

        assert_same($before, Live::revision(Live::memberCartSurface($id)), 'refused quantity');

        /* Another member's line is invisible here, so removing it fails. */
        $foreign     = $carts->add((int) $makeMember()['id'], $productId, 1);
        $foreignItem = (int) $foreign['item']['id'];

        $before = Live::revision(Live::memberCartSurface($id));

        try {
            $carts->remove($id, $foreignItem);
            fail('another cart line must not be removable');
        } catch (CartException) {
        }

        assert_same($before, Live::revision(Live::memberCartSurface($id)), 'refused removal');

        $before = Live::revision(Live::memberCartSurface($id));
        $carts->remove($id, $itemId);
        assert_same($before + 1, Live::revision(Live::memberCartSurface($id)), 'remove');

        /* Checkout converts the active cart; a second convert is a no-op. */
        $before = Live::revision(Live::memberCartSurface($id));
        assert_true($carts->convert($id));
        assert_same($before + 1, Live::revision(Live::memberCartSurface($id)), 'convert');

        $before = Live::revision(Live::memberCartSurface($id));
        assert_false($carts->convert($id));
        assert_same($before, Live::revision(Live::memberCartSurface($id)), 'a no-op convert');
    },

    'clearing a cart bumps the surface and an empty add is refused' => function () use ($makeProduct, $makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];
        $carts  = new CartService();

        $productId = $makeProduct(['stock' => '2']);

        $carts->add($id, $productId, 2);

        $before = Live::revision(Live::memberCartSurface($id));

        /* Adding more than the remaining stock fails before the write. */
        try {
            $carts->add($id, $productId, 1);
            fail('an over-stock add must be refused');
        } catch (CartException) {
        }

        assert_same($before, Live::revision(Live::memberCartSurface($id)), 'refused add');

        assert_true($carts->clear($id));
        assert_same($before + 1, Live::revision(Live::memberCartSurface($id)), 'clear');
    },

    'the cart feed renders one content region with the lines' => function () use ($makeProduct, $makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $productId = $makeProduct([
            'name'  => '<b>Live Cart</b>',
            'price' => '300.00',
        ]);

        (new CartService())->add($id, $productId, 2);
        test_signed_in_as($member);

        $controller = new CartController();
        $payload    = $controller->feedPayload(
            5,
            '"member.cart:' . $id . ':5:abc"',
            $controller->cartContext($id)
        );

        assert_true($payload['ok']);
        assert_same('member.cart:' . $id, $payload['surface']);
        assert_same(5, $payload['revision']);
        assert_same(['cart-content'], array_keys($payload['fragments']));

        $content = $payload['fragments']['cart-content'];

        assert_contains('data-live-region="cart-content"', $content);
        assert_contains('data-live-hold="dirty"', $content);
        assert_contains('data-cart-page-items', $content);
        assert_contains('&lt;b&gt;Live Cart&lt;/b&gt;', $content);
        assert_not_contains('<b>Live Cart</b>', $content);
        assert_contains(money(300), $content, 'unit price');
        assert_contains(money(600), $content, 'line total and subtotal');
        assert_contains('value="2"', $content);
        assert_contains('action="' . url('cart/update') . '"', $content);
        assert_contains('action="' . url('cart/remove') . '"', $content);
        assert_contains('name="_csrf"', $content);
        assert_contains('Proceed to checkout', $content);
        assert_contains('href="' . url('checkout') . '"', $content);
        assert_not_contains('Your cart is empty', $content);

        assert_same(2, $payload['meta']['count']);
        assert_same(1, $payload['meta']['items']);
        assert_same(600.0, $payload['meta']['subtotal']);
        assert_same(url('cart'), $payload['meta']['canonical']);
    },

    'the cart feed renders the empty state and the closed-shop notice' => function () use ($makeMember, $emptyState): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        test_signed_in_as($member);

        $controller = new CartController();

        $empty = $controller->feedPayload(2, '"member.cart:' . $id . ':2:empty"', [
            'user_id'   => $id,
            'state'     => $emptyState(),
            'shop_open' => true,
        ]);

        $content = $empty['fragments']['cart-content'];

        assert_contains('data-live-region="cart-content"', $content);
        assert_contains('Your cart is empty', $content);
        assert_contains('Browse the shop', $content);
        assert_not_contains('data-cart-page-items', $content);
        assert_not_contains('Proceed to checkout', $content);
        assert_same(0, $empty['meta']['count']);
        assert_same(0.0, $empty['meta']['subtotal']);

        $closed = $controller->feedPayload(3, '"member.cart:' . $id . ':3:closed"', [
            'user_id'   => $id,
            'state'     => $emptyState(),
            'shop_open' => false,
        ]);

        assert_contains('The shop is currently closed', $closed['fragments']['cart-content']);
    },

    'the cart feed keeps the stock warning and the blocked checkout' => function () use ($makeProduct, $makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $productId = $makeProduct([
            'name'  => 'Scarce Live Item',
            'price' => '50.00',
            'stock' => '5',
        ]);

        (new CartService())->add($id, $productId, 3);

        Product::save([
            'name'   => 'Scarce Live Item',
            'price'  => '50.00',
            'stock'  => '1',
            'status' => 'active',
        ], $productId);

        test_signed_in_as($member);

        $controller = new CartController();
        $payload    = $controller->feedPayload(
            4,
            '"member.cart:' . $id . ':4:stock"',
            $controller->cartContext($id)
        );

        $content = $payload['fragments']['cart-content'];

        assert_contains('Some items need attention before checkout', $content);
        assert_contains('Scarce Live Item: requested 3, only 1 available.', $content);
        assert_contains('Only 1 left', $content);
        assert_contains('Fix the quantities marked above to continue.', $content);
        assert_not_contains('href="' . url('checkout') . '"', $content);
        assert_contains('disabled', $content);
    },

    'the cart page declares its live surface, regions and banner' => function () use ($makeProduct, $makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $productId = $makeProduct(['name' => 'Page Cart Item']);

        (new CartService())->add($id, $productId, 1);
        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/cart';

        $controller = new CartController();
        $data       = $controller->cartContext($id);

        ob_start();

        try {
            View::page('member', 'shop/cart', $data + [
                'page_title'  => 'Cart',
                'current_nav' => 'cart',
                'page_css'    => ['assets/css/shop.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta(Live::memberCartSurface($id), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.cart:' . $id . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="cart-content"', $html);
        assert_contains('data-live-hold="dirty"', $html);
        assert_contains('data-cart-page', $html);
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_contains('Page Cart Item', $html);
        assert_contains('app-live.js', $html);

        /* The drawer and the badge stay shell widgets, not cart regions. */
        assert_contains('id="cartOffcanvas"', $html);
        assert_contains('data-cart-badge', $html);
    },
];
