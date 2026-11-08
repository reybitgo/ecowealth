<?php
declare(strict_types=1);

/* ==============================================================
 *  ShopLiveTest — the storefront catalogue and one product page
 *
 *  Two live surfaces: `shop.catalog` for the grid and
 *  `shop.product:{id}` for one product. These cases pin:
 *
 *    · product writes and every reservation change (order placement and
 *      cancellation) bump the surfaces whose `available` moved;
 *    · the catalogue feed renders the heading, the result states and the
 *      quick-view JSON payload, and escapes every product value;
 *    · the product feed renders the heading, the closed-shop notice and the
 *      card, and swaps to an "unavailable" card after a product is removed;
 *    · both pages declare their live surfaces and regions.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Shop Live Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'SLV-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('slv_' . bin2hex(random_bytes(4)));
};

$fund = static function (int $userId, string $amount = '100.00'): void {
    (new WalletService())->credit(
        $userId,
        $amount,
        'commission',
        random_int(1000000, 9999999),
        'Test funds'
    );
};

$orderInput = static function (array $overrides = []): array {
    return $overrides + [
        'billing_name'      => 'Shop Live Buyer',
        'billing_phone'     => '0917 000 0000',
        'shipping_name'     => 'Shop Live Buyer',
        'shipping_phone'    => '0917 000 0000',
        'shipping_address'  => '12 Mabini Street',
        'shipping_barangay' => 'Poblacion',
        'shipping_city'     => 'Makati',
        'shipping_province' => 'Metro Manila',
        'shipping_postal'   => '1200',
        'notes_member'      => '',
        'payment_method'    => 'gcash',
        'idempotency_key'   => bin2hex(random_bytes(16)),
        'terms'             => '1',
    ];
};

return [
    'shop surfaces use the catalogue + product keys and bump together' => function (): void {
        $catalogBefore = Live::revision('shop.catalog');
        $productBefore = Live::revision('shop.product:4242');

        Live::touchShop(4242);

        assert_same('shop.product:4242', Live::shopProductSurface(4242));
        assert_same($catalogBefore + 1, Live::revision('shop.catalog'));
        assert_same($productBefore + 1, Live::revision('shop.product:4242'));

        /* A catalogue-only touch (a shop setting) leaves product pages alone. */
        Live::touchShop();

        assert_same($catalogBefore + 2, Live::revision('shop.catalog'));
        assert_same($productBefore + 1, Live::revision('shop.product:4242'));
    },

    'product writes bump the catalogue and the product page' => function () use ($makeProduct): void {
        $catalogBefore = Live::revision('shop.catalog');
        $productId     = $makeProduct(['name' => 'Live Probe']);

        assert_same($catalogBefore + 1, Live::revision('shop.catalog'), 'create');
        assert_same(1, Live::revision(Live::shopProductSurface($productId)), 'create (product)');

        $catalogBefore = Live::revision('shop.catalog');
        $productBefore = Live::revision(Live::shopProductSurface($productId));

        Product::save([
            'name'   => 'Live Probe Renamed',
            'sku'    => 'SLV-RENAMED',
            'price'  => '30.00',
            'stock'  => '8',
            'status' => 'active',
        ], $productId);

        assert_same($catalogBefore + 1, Live::revision('shop.catalog'), 'edit');
        assert_same($productBefore + 1, Live::revision(Live::shopProductSurface($productId)), 'edit (product)');

        $catalogBefore = Live::revision('shop.catalog');
        $productBefore = Live::revision(Live::shopProductSurface($productId));

        Product::setStatus($productId, 'inactive');

        assert_same($catalogBefore + 1, Live::revision('shop.catalog'), 'status');
        assert_same($productBefore + 1, Live::revision(Live::shopProductSurface($productId)), 'status (product)');

        /* A no-op status toggle changes nothing. */
        $catalogBefore = Live::revision('shop.catalog');
        Product::setStatus($productId, 'inactive');
        assert_same($catalogBefore, Live::revision('shop.catalog'));

        $catalogBefore = Live::revision('shop.catalog');
        $productBefore = Live::revision(Live::shopProductSurface($productId));

        Product::delete($productId);

        assert_same($catalogBefore + 1, Live::revision('shop.catalog'), 'delete');
        assert_same($productBefore + 1, Live::revision(Live::shopProductSurface($productId)), 'delete (product)');
    },

    'order reservations and releases bump the storefront' => function () use ($makeProduct, $makeMember, $fund, $orderInput): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '10']);

        $fund($userId, '100.00');

        (new CartService())->add($userId, $product, 2);

        $catalogBefore = Live::revision('shop.catalog');
        $productBefore = Live::revision(Live::shopProductSurface($product));

        $placed = (new OrderService())->placeFromCart($userId, $orderInput());

        assert_same($catalogBefore + 1, Live::revision('shop.catalog'), 'placement');
        assert_same($productBefore + 1, Live::revision(Live::shopProductSurface($product)), 'placement (product)');

        $catalogBefore = Live::revision('shop.catalog');
        $productBefore = Live::revision(Live::shopProductSurface($product));

        (new OrderService())->cancelByMember($userId, (int) $placed['order_id'], 'Changed my mind');

        assert_same($catalogBefore + 1, Live::revision('shop.catalog'), 'cancellation');
        assert_same($productBefore + 1, Live::revision(Live::shopProductSurface($product)), 'cancellation (product)');
    },

    'the catalogue feed renders the heading, results and quick view' => function () use ($makeProduct): void {
        $member = test_user('slv_catalog_member');

        $productId = $makeProduct([
            'name'              => '<script>alert(1)</script>',
            'sku'               => 'SLV-XSS',
            'short_description' => '<img src=x onerror=alert(2)>',
            'description'       => '<b>bold</b> description',
            'price'             => '25.00',
            'stock'             => '3',
        ]);

        $makeProduct([
            'name'  => 'Sold Out Probe',
            'stock' => '0',
        ]);

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/shop';

        $controller = new ShopController();
        $payload    = $controller->feedPayload(5, '"shop.catalog:5:abc"', $controller->catalogContext());

        assert_true($payload['ok']);
        assert_same('shop.catalog', $payload['surface']);
        assert_same(
            ['shop-catalog-heading', 'shop-catalog-results', 'shop-catalog-quickview'],
            array_keys($payload['fragments'])
        );

        $heading  = $payload['fragments']['shop-catalog-heading'];
        $results  = $payload['fragments']['shop-catalog-results'];
        $quick    = $payload['fragments']['shop-catalog-quickview'];

        assert_contains('data-live-region="shop-catalog-heading"', $heading);
        assert_contains('product', $heading);
        assert_contains('Explore the EcoWealth range', $heading);

        assert_contains('data-live-region="shop-catalog-results"', $results);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $results);
        assert_not_contains('<script>alert(1)</script>', $results);
        assert_contains('&lt;img src=x onerror=alert(2)&gt;', $results);
        assert_not_contains('<img src=x onerror=alert(2)>', $results);
        assert_contains('3 left', $results);
        assert_contains('Sold out', $results);
        assert_contains('data-quick-view="' . $productId . '"', $results);
        assert_contains('data-cart-add', $results);
        assert_contains('name="_csrf"', $results);

        assert_contains('data-live-region="shop-catalog-quickview"', $quick);
        assert_contains('id="productQuickViewData"', $quick);
        assert_contains('\\u003Cb\\u003Ebold', $quick);
        assert_not_contains('<b>bold</b>', $quick);

        assert_same(url('shop'), $payload['meta']['canonical']);
    },

    'the catalogue feed renders the filtered empty state' => function (): void {
        test_signed_in_as(test_user('slv_empty_member'));

        $_GET = ['q' => 'no-such-product-anywhere'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/shop?q=no-such-product-anywhere';

        $controller = new ShopController();
        $payload    = $controller->feedPayload(2, '"shop.catalog:2:def"', $controller->catalogContext());

        $results = $payload['fragments']['shop-catalog-results'];

        assert_contains('No products match your search', $results);
        assert_contains('Clear search', $results);
        assert_contains('data-live-region="shop-catalog-results"', $results);
    },

    'the product feed renders the heading, notice and card' => function () use ($makeProduct): void {
        $member = test_user('slv_product_member');

        $productId = $makeProduct([
            'name'              => '<b>Product Live</b>',
            'sku'               => 'SLV-CARD',
            'short_description' => 'Short and sweet',
            'description'       => 'Full description',
            'price'             => '25.00',
            'stock'             => '4',
        ]);

        $product = Product::find($productId);
        assert_not_null($product);

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/shop/' . $productId;

        $controller = new ShopController();
        $payload    = $controller->productFeedPayload(
            6,
            '"shop.product:' . $productId . ':6:abc"',
            $controller->productContext($product)
        );

        assert_same('shop.product:' . $productId, $payload['surface']);
        assert_same(
            ['shop-product-heading', 'shop-product-notice', 'shop-product-card'],
            array_keys($payload['fragments'])
        );

        $heading = $payload['fragments']['shop-product-heading'];
        $notice  = $payload['fragments']['shop-product-notice'];
        $card    = $payload['fragments']['shop-product-card'];

        assert_contains('data-live-region="shop-product-heading"', $heading);
        assert_contains('&lt;b&gt;Product Live&lt;/b&gt;', $heading);
        assert_not_contains('<b>Product Live</b>', $heading);

        assert_contains('data-live-region="shop-product-notice"', $notice);
        assert_contains('hidden', $notice);
        assert_not_contains('shop is currently closed', $notice);

        assert_contains('data-live-region="shop-product-card"', $card);
        assert_contains('data-live-hold="dirty"', $card);
        assert_contains('&lt;b&gt;Product Live&lt;/b&gt;', $card);
        assert_contains(money(25), $card);
        assert_contains('4 left', $card);
        assert_contains('4 units available', $card);
        assert_contains('data-cart-add', $card);
        assert_contains('name="quantity"', $card);
        assert_contains('name="_csrf"', $card);

        assert_same(url('shop/' . $productId), $payload['meta']['canonical']);
    },

    'the product feed swaps to the unavailable card after removal' => function () use ($makeProduct): void {
        $member    = test_user('slv_gone_member');
        $productId = $makeProduct(['name' => 'Soon Gone']);

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/shop/' . $productId;

        $controller = new ShopController();
        $payload    = $controller->unavailablePayload(7, '"shop.product:' . $productId . ':7:def"', $productId);

        assert_same('shop.product:' . $productId, $payload['surface']);
        assert_same(
            ['shop-product-heading', 'shop-product-notice', 'shop-product-card'],
            array_keys($payload['fragments'])
        );

        assert_contains('Unavailable', $payload['fragments']['shop-product-heading']);
        assert_contains('no longer available', $payload['fragments']['shop-product-card']);
        assert_contains('data-live-region="shop-product-card"', $payload['fragments']['shop-product-card']);
        assert_same(url('shop/' . $productId), $payload['meta']['canonical']);
    },

    'the shop pages declare their live surfaces and regions' => function () use ($makeProduct): void {
        $member = test_user('slv_page_member');

        $productId = $makeProduct([
            'name'        => 'Page Probe',
            'description' => 'A product for the page test',
            'stock'       => '5',
        ]);

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/shop';

        $controller = new ShopController();
        $catalog    = $controller->catalogContext();

        ob_start();

        try {
            View::page('member', 'shop/catalog', $catalog + [
                'page_title'  => 'Shop',
                'current_nav' => 'shop',
                'page_css'    => ['assets/css/shop.css'],
                'live'        => Live::meta('shop.catalog', 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="shop.catalog"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-region="shop-catalog-heading"', $html);
        assert_contains('data-live-region="shop-catalog-results"', $html);
        assert_contains('data-live-region="shop-catalog-quickview"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('id="productQuickView"', $html);
        assert_contains('id="productQuickViewData"', $html);
        assert_contains('name="q"', $html);
        assert_not_contains('data-live-stale', $html, 'the search form is static');

        $product = Product::find($productId);
        assert_not_null($product);

        $_SERVER['REQUEST_URI'] = '/ecowealth/system/shop/' . $productId;

        $detail = $controller->productContext($product);

        ob_start();

        try {
            View::page('member', 'shop/product', $detail + [
                'page_title'  => 'Page Probe',
                'current_nav' => 'shop',
                'page_css'    => ['assets/css/shop.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta(Live::shopProductSurface($productId), 'page'),
            ]);
        } finally {
            $detailHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="shop.product:' . $productId . '"', $detailHtml);
        assert_contains('data-live-region="shop-product-heading"', $detailHtml);
        assert_contains('data-live-region="shop-product-notice"', $detailHtml);
        assert_contains('data-live-region="shop-product-card"', $detailHtml);
        assert_contains('data-live-hold="dirty"', $detailHtml);
        assert_contains('data-live-status', $detailHtml);
        assert_contains('data-live-stale', $detailHtml);
        assert_contains('admin-live.js', $detailHtml);
        assert_contains('name="quantity"', $detailHtml);
    },
];
