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

    'P4-T1 the detail page renders an active add-to-cart form' => function () use ($render, $product): void {
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
        assert_contains('action="' . url('cart/add') . '"', $html);
        assert_contains('name="quantity"', $html);
        assert_contains('class="input-group product-qty-group"', $html);
        assert_not_contains('Online checkout opens soon', $html);
        assert_contains(url('shop'), $html);

        /* "Back to shop" lives in the breadcrumb row, above the card, exactly once. */
        $breadcrumbAt = strpos($html, 'aria-label="breadcrumb"');
        $backAt       = strpos($html, 'Back to shop');
        $cardAt       = strpos($html, '<div class="card">');

        assert_same(1, substr_count($html, 'Back to shop'), 'the page has one Back to shop link');
        assert_true(
            $breadcrumbAt !== false && $backAt !== false && $cardAt !== false
                && $breadcrumbAt < $backAt && $backAt < $cardAt,
            'Back to shop must sit in the breadcrumb row, before the product card'
        );
    },

    'P4-T1 the product stepper is a single-row, content-width group' => function (): void {
        /* The theme's .btn padding needs ~115px for [−][n][+] and Bootstrap's
         * .input-group sets width:100%; without fit-content + nowrap the "+"
         * wrapped onto a second row and stretched the whole action row. */
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/shop.css');

        assert_contains('.product-qty-group', $css);
        assert_true(
            str_contains($css, 'width: fit-content'),
            'the product quantity group must override .input-group { width: 100% }'
        );
        assert_true(
            str_contains($css, 'flex-wrap: nowrap'),
            'the product quantity stepper must never wrap onto two rows'
        );
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
        assert_not_contains('action="' . url('cart/add') . '"', $html, 'no add form when sold out');
    },

    'P2-T1 the admin list renders rows, filters and guarded delete buttons' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $html = $render('admin', 'admin/products/index', [
            'products'   => [
                $product(['id' => 1, 'name' => 'Coffee']),
                $product(['id' => 2, 'name' => 'Inactive Item', 'status' => 'inactive', 'image_url' => null]),
            ],
            'pager'      => new Paginator(2, 20, 1),
            'filters'    => ['q' => '', 'status' => ''],
            'stats'      => ['total' => 2, 'active' => 1, 'inactive' => 1, 'out_of_stock' => 0],
            'references' => [
                1 => ['orders' => 0, 'carts' => 2, 'packages' => 0],
                2 => ['orders' => 0, 'carts' => 0, 'packages' => 3],
            ],
            'available'  => [1 => 50, 2 => 0],
            'page_css'   => ['assets/vendor/libs/sweetalert2/sweetalert2.css'],
            'page_js'    => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
            ],
            'live'       => [
                'surface'  => 'admin.products',
                'enabled'  => true,
                'revision' => 9,
                'etag'     => '"admin.products:9:abc"',
                'interval' => 30000,
            ],
        ]);

        assert_contains('Products', $html);
        assert_contains('Coffee', $html);
        assert_contains('Inactive Item', $html);
        assert_contains(url('admin/products/1/edit'), $html);
        assert_contains(url('admin/products/1/status'), $html);
        assert_contains('data-confirm-title="Disable this product?"', $html, 'the toggle asks before hiding it');
        assert_contains('data-confirm-title="Enable this product?"', $html, 'and before showing it again');
        assert_contains('This product appears in a member’s cart.', $html);
        assert_contains('This product appears in a package.', $html);
        assert_contains('Showing 1–2 of 2', $html);
        assert_contains('Inactive', $html);
        assert_contains(url('assets/img/ecowealth-mark.png'), $html, 'missing image falls back to the leaf mark');

        /* The list is one live surface with three replaceable regions. */
        assert_contains('data-live-surface="admin.products"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-revision="9"', $html);
        assert_contains('data-live-etag="&quot;admin.products:9:abc&quot;"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="product-stats"', $html);
        assert_contains('data-live-region="product-rows"', $html);
        assert_contains('data-live-region="product-pagination"', $html);
        assert_contains('data-live-key="1"', $html);
        assert_contains('data-live-action="status"', $html);
        assert_contains('data-live-action="delete"', $html);
        assert_contains('data-live-status', $html);
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
        assert_contains(url('assets/img/ecowealth-mark.png'), $html, 'new-product preview uses the leaf mark');
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

    'P17B-T14 the form view itself renders the repeat-purchase card' => function () use ($render, $product): void {
        /* The controller's own render path is covered by
         * UnilevelConfigurationTest; this case proves the shared form view
         * carries the card for *any* caller and that an older caller that
         * passes no ladder still gets ten editable, blank rows. */
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $html = $render('admin', 'admin/products/form', [
            'product' => $product(['unilevel_enabled' => 0, 'unilevel_base' => 'auto']),
            'current' => $product(['unilevel_enabled' => 0, 'unilevel_base' => 'auto']),
        ]);

        assert_contains('Unilevel repeat-purchase bonus', $html);
        assert_contains('Levels 1–10', $html);
        assert_contains('name="pv"', $html);
        assert_same(10, preg_match_all('/name="unilevel_levels\[\d+\]"/', $html));
        assert_contains('Load reference ladder', $html);
        assert_not_contains('direct_unilevel', $html);
        assert_not_contains('indirect_unilevel', $html);
    },

    'P17B-T21 the ladder card carries the reference toolbar and its preset' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $form = ProductUnilevel::forForm(ProductUnilevel::referencePreset());
        $html = $render('admin', 'admin/products/form', [
            'product'  => $product(['unilevel_enabled' => 0, 'unilevel_base' => 'auto']),
            'current'  => $product(['unilevel_enabled' => 0, 'unilevel_base' => 'auto']),
            'unilevel' => $form,
        ]);

        /* One panel, wired by data attributes so the shared script can serve
         * a rate ladder (thousandths of a percent) as well as a money one. */
        assert_contains('data-ladder-toolbar', $html);
        assert_contains('data-ladder-unit="percent"', $html);
        assert_contains('data-ladder-card="[data-unilevel-card]"', $html);
        assert_contains('data-ladder-field="[data-unilevel-rate]"', $html);
        assert_contains('data-ladder-saved="data-unilevel-saved"', $html);
        assert_contains('data-ladder-save-word="product"', $html);

        /* The panel sits inside the card, above the table it fills. */
        $cardStart = strpos($html, 'data-unilevel-card');
        assert_true($cardStart !== false);
        assert_true(strpos($html, 'data-ladder-toolbar') > $cardStart, 'the panel is inside the ladder card');
        assert_true(
            strpos($html, 'data-ladder-toolbar') < strpos($html, 'data-unilevel-rate="1"'),
            'the panel sits above the ladder inputs'
        );

        /* The preset's total and its ten values are on the panel — never in
         * the script. */
        assert_contains('29% in total across the ten levels', $html);
        assert_contains('the reference preset 5 / 5 / 5 / 3 / 3 / 2 / 2 / 2 / 1 / 1', $html);

        /* Both actions, their explanation, and a live confirmation region. */
        assert_contains('data-ladder-preset', $html);
        assert_contains('data-ladder-clear', $html);
        assert_contains('data-unilevel-preset', $html);
        assert_contains('data-unilevel-clear', $html);
        assert_contains('aria-live="polite"', $html);
        assert_contains('role="status"', $html);
        assert_contains('id="unilevel-reference-help"', $html);
        assert_contains('aria-describedby="unilevel-reference-help"', $html);

        /* A ladder that already holds the preset says so before any click —
         * and the badge is server-rendered, so it is right with JS off. */
        assert_contains('data-ladder-state', $html);
        assert_contains('Matches reference', $html);
        assert_not_contains('Unsaved changes', $html);

        /* Every input carries both values the badge compares: the preset it
         * would load, and the rate the form currently holds. */
        foreach (ProductUnilevel::referencePreset() as $level => $rate) {
            $pattern = '/<input\\b[^>]*data-unilevel-rate="' . $level . '"[^>]*>/';

            assert_same(1, preg_match($pattern, $html, $input), 'level ' . $level . ' rate input exists');
            assert_contains('data-reference="' . $rate . '"', $input[0], 'level ' . $level . ' rides the preset');
            assert_contains('data-unilevel-saved="' . $form[$level] . '"', $input[0], 'level ' . $level . ' rides the stored rate');
        }
    },

    'P17B-T22 the badge tells the preset, a custom ladder and an empty one apart' => function () use ($render, $product): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $custom = $render('admin', 'admin/products/form', [
            'product'  => $product(),
            'current'  => $product(),
            'unilevel' => array_fill(1, 10, '1'),
        ]);

        assert_contains('Custom ladder', $custom);
        assert_not_contains('Matches reference', $custom);
        assert_not_contains('Empty ladder', $custom);

        $empty = $render('admin', 'admin/products/form', [
            'product' => $product(),
            'current' => $product(),
        ]);

        assert_contains('Empty ladder', $empty);
        assert_not_contains('Custom ladder', $empty);
        assert_not_contains('Matches reference', $empty);
    },
];
