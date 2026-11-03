<?php
declare(strict_types=1);

/* ==============================================================
 *  ProductViewTest — storefront and back-office product pages
 *  (plan §7 Phase 2, tests P2-T1/T2/T5/T9)
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

$product = static fn (array $overrides = []): array => $overrides + [
    'id'                => 1,
    'sku'               => 'EW-COFFEE',
    'name'              => 'Coffee',
    'slug'              => 'coffee',
    'price'             => '300.00',
    'stock'             => 50,
    'image_url'         => 'assets/img/products/collagen-coffee.webp',
    'short_description' => 'Roasted coffee blend, sold per pack.',
    'description'       => "First line.\nSecond line.",
    'status'            => 'active',
    'sort_order'        => 10,
    'created_at'        => '2026-11-03 10:00:00',
    'updated_at'        => '2026-11-03 10:00:00',
];

return [
    'P2-T1 the catalogue renders cards with prices and stock badges' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_member'));

        $html = $render('member', 'shop/catalog', [
            'products'  => [$product()],
            'available' => [1 => 50],
            'pager'     => new Paginator(1, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => true,
        ]);

        assert_contains('Coffee', $html);
        assert_contains(money(300), $html);
        assert_contains('In stock', $html);
        assert_contains(url('shop/1'), $html);
        assert_contains('name="q"', $html);
        assert_contains('product-card', $html);
    },

    'P2-T2 the catalogue shows sold-out and low-stock badges' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_member'));

        $html = $render('member', 'shop/catalog', [
            'products'  => [
                $product(['id' => 1, 'name' => 'Alpha', 'short_description' => '']),
                $product(['id' => 2, 'name' => 'Beta', 'short_description' => '']),
                $product(['id' => 3, 'name' => 'Gamma', 'short_description' => '']),
            ],
            'available' => [1 => 0, 2 => 3, 3 => 9],
            'pager'     => new Paginator(3, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => true,
        ]);

        assert_contains('Sold out', $html);
        assert_contains('3 left', $html);
        assert_contains('In stock', $html);
    },

    'P2-T5 the catalogue shows an empty state when nothing matches' => function () use ($render): void {
        test_signed_in_as(test_user('catalog_member'));

        $html = $render('member', 'shop/catalog', [
            'products'  => [],
            'available' => [],
            'pager'     => new Paginator(0, 12, 1),
            'filters'   => ['q' => 'zzz'],
            'shop_open' => true,
        ]);

        assert_contains('No products match your search', $html);
        assert_contains('Clear search', $html);
        assert_not_contains('product-card', $html);
    },

    'P2-T5 a closed shop hides the catalogue' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_member'));

        $html = $render('member', 'shop/catalog', [
            'products'  => [$product()],
            'available' => [1 => 50],
            'pager'     => new Paginator(1, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => false,
        ]);

        assert_contains('shop is currently closed', $html);
        assert_not_contains('product-card', $html);
    },

    'P2-T9 product names, prices and descriptions are escaped' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_member'));

        $html = $render('member', 'shop/catalog', [
            'products'  => [$product([
                'id'                => 7,
                'name'              => '<script>alert(1)</script>',
                'short_description' => '<img src=x onerror=alert(2)>',
                'description'       => '<b>bold</b>',
            ])],
            'available' => [7 => 50],
            'pager'     => new Paginator(1, 12, 1),
            'filters'   => ['q' => ''],
            'shop_open' => true,
        ]);

        assert_not_contains('<script>alert(1)</script>', $html);
        assert_not_contains('<img src=x onerror=alert(2)>', $html);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    },

    'P2-T1 the detail page renders the product with a disabled add-to-cart' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_member'));

        $html = $render('member', 'shop/product', [
            'product'   => $product(),
            'available' => 50,
            'stock'     => StockService::label(50),
            'shop_open' => true,
        ]);

        assert_contains('Coffee', $html);
        assert_contains(money(300), $html);
        assert_contains('First line.', $html);
        assert_contains('EW-COFFEE', $html);
        assert_contains('Add to cart', $html);
        assert_contains('disabled', $html);
        assert_contains(url('shop'), $html);
    },

    'P2-T2 a sold-out detail page says so' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_member'));

        $html = $render('member', 'shop/product', [
            'product'   => $product(['stock' => 0]),
            'available' => 0,
            'stock'     => StockService::label(0),
            'shop_open' => true,
        ]);

        assert_contains('Sold out', $html);
        assert_contains('Out of stock', $html);
    },

    'P2-T1 the admin list renders rows, filters and guarded delete buttons' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $html = $render('admin', 'admin/products/index', [
            'products'   => [
                $product(['id' => 1, 'name' => 'Coffee']),
                $product(['id' => 2, 'name' => 'Inactive Item', 'status' => 'inactive']),
            ],
            'pager'      => new Paginator(2, 20, 1),
            'filters'    => ['q' => '', 'status' => ''],
            'stats'      => ['total' => 2, 'active' => 1, 'inactive' => 1, 'out_of_stock' => 0],
            'references' => [1 => ['orders' => 0, 'carts' => 2], 2 => ['orders' => 0, 'carts' => 0]],
            'available'  => [1 => 50, 2 => 0],
        ]);

        assert_contains('Products', $html);
        assert_contains('Coffee', $html);
        assert_contains('Inactive Item', $html);
        assert_contains(url('admin/products/1/edit'), $html);
        assert_contains(url('admin/products/1/status'), $html);
        assert_contains('This product is in a member’s cart.', $html);
        assert_contains('Showing 1–2 of 2', $html);
        assert_contains('Inactive', $html);
    },

    'P2-T1 the admin form renders values, errors and the upload field' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));
        errors_set(['name' => 'Product name is required.', 'image' => 'That file type is not allowed.']);
        old_set(['name' => 'Unsaved <b>', 'price' => '99.00']);

        $html = $render('admin', 'admin/products/form', [
            'product' => null,
            'current' => [
                'name' => '', 'sku' => '', 'price' => '', 'stock' => '0', 'sort_order' => '0',
                'short_description' => '', 'description' => '', 'status' => 'active', 'image_url' => null,
            ],
        ]);

        assert_contains('New product', $html);
        assert_contains('enctype="multipart/form-data"', $html);
        assert_contains('value="Unsaved &lt;b&gt;"', $html);
        assert_contains('Product name is required.', $html);
        assert_contains('That file type is not allowed.', $html);
        assert_contains('name="image"', $html);
        assert_contains('name="_csrf"', $html);
        assert_contains('Generated from the name', $html);
    },

    'P2-T4 the edit form shows the current image and a remove option' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $html = $render('admin', 'admin/products/form', [
            'product' => $product(),
            'current' => $product(),
        ]);

        assert_contains('Edit product', $html);
        assert_contains('Save changes', $html);
        assert_contains(url('assets/img/products/collagen-coffee.webp'), $html);
        assert_contains('name="remove_image"', $html);
        assert_contains('value="300.00"', $html);
        assert_contains('Stable after creation', $html);
    },
];
