<?php
declare(strict_types=1);

/* ==============================================================
 *  LiveTest — surface revisions, ETags and the product JSON feed
 *
 *  The live client only trusts the server. These cases pin the
 *  contract: a successful write bumps the surface, a no-op does not,
 *  an unchanged ETag becomes a 304, and the feed renders the same
 *  fragments as the page (including correct pagination URLs).
 * ============================================================== */

$product = static fn (array $overrides = []): array => $overrides + [
    'id'                => 1,
    'sku'               => 'EW-COFFEE',
    'name'              => 'Coffee',
    'slug'              => 'coffee',
    'price'             => '300.00',
    'stock'             => 50,
    'image_url'         => null,
    'short_description' => 'Roasted coffee blend.',
    'description'       => 'A test product.',
    'status'            => 'active',
    'sort_order'        => 10,
    'created_at'        => '2026-11-03 10:00:00',
    'updated_at'        => '2026-11-03 10:00:00',
];

return [
    'live revision starts at zero and touch increments it' => function (): void {
        assert_same(0, Live::revision('test.surface'));

        Live::touch('test.surface');
        assert_same(1, Live::revision('test.surface'));

        Live::touch(['test.surface', 'test.other']);
        assert_same(2, Live::revision('test.surface'));
        assert_same(1, Live::revision('test.other'));
    },

    'live touch de-duplicates repeated surfaces' => function (): void {
        Live::touch(['dupe.surface', 'dupe.surface']);

        assert_same(1, Live::revision('dupe.surface'));
    },

    'live etag is stable and changes with revision or scope' => function (): void {
        $a = Live::etag('admin.products', 1, 'q=coffee');
        $b = Live::etag('admin.products', 1, 'q=coffee');
        $c = Live::etag('admin.products', 2, 'q=coffee');
        $d = Live::etag('admin.products', 1, 'q=tea');

        assert_same($a, $b);
        assert_not_same($a, $c);
        assert_not_same($a, $d);
        assert_contains('"admin.products:1:', $a);
    },

    'live If-None-Match accepts strong, weak and list forms' => function (): void {
        $etag = '"admin.products:7:abc123"';

        assert_true(Live::matchesIfNoneMatch($etag, $etag));
        assert_true(Live::matchesIfNoneMatch('W/' . $etag, $etag));
        assert_true(Live::matchesIfNoneMatch('"other", ' . $etag, $etag));
        assert_true(Live::matchesIfNoneMatch('*', $etag));
        assert_true(Live::matchesIfNoneMatch(trim($etag, '"'), $etag), 'unquoted command-line form');

        assert_false(Live::matchesIfNoneMatch(null, $etag));
        assert_false(Live::matchesIfNoneMatch('"other"', $etag));
        assert_false(Live::matchesIfNoneMatch('', $etag));
    },

    'live scope is order-independent and user-specific' => function (): void {
        $a = Live::scope(['q' => 'coffee', 'status' => 'active'], 7);
        $b = Live::scope(['status' => 'active', 'q' => 'coffee'], 7);
        $c = Live::scope(['q' => 'coffee', 'status' => 'active'], 8);

        assert_same($a, $b);
        assert_not_same($a, $c);
    },

    'product writes bump the admin.products revision' => function (): void {
        $before = Live::revision('admin.products');

        $id = Product::save([
            'name'   => 'Live Probe',
            'price'  => '12.50',
            'stock'  => '3',
            'status' => 'active',
        ]);

        assert_same($before + 1, Live::revision('admin.products'));

        $created = Product::find($id);
        assert_not_null($created);

        /* Setting the status it already has is a no-op. */
        Product::setStatus($id, (string) $created['status']);
        assert_same($before + 1, Live::revision('admin.products'));

        Product::setStatus($id, 'inactive');
        assert_same($before + 2, Live::revision('admin.products'));

        $deleted = Product::delete($id);
        assert_true($deleted['ok']);
        assert_same($before + 3, Live::revision('admin.products'));
    },

    'a missing product delete does not bump the revision' => function (): void {
        $before = Live::revision('admin.products');
        $result = Product::delete(999999);

        assert_false($result['ok']);
        assert_same('missing', $result['reason']);
        assert_same($before, Live::revision('admin.products'));
    },

    'live publish queues surfaces and ignores stale entries' => function (): void {
        $_SESSION['_live_publish'] = [];

        Live::publish(['a.surface', 'b.surface']);
        assert_same(['a.surface', 'b.surface'], Live::takePublished());
        assert_same([], Live::takePublished());

        $_SESSION['_live_publish'] = [
            'old.surface'   => time() - 400,
            'fresh.surface' => time(),
        ];

        assert_same(['fresh.surface'], Live::takePublished());
    },

    'the product feed renders stats, rows and pagination fragments' => function () use ($product): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $_GET = ['q' => 'coffee', 'status' => 'active'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/products?q=coffee&status=active&page=2';

        $data = [
            'products'   => [
                $product(['id' => 1, 'name' => 'Coffee']),
                $product(['id' => 2, 'name' => '<script>alert(1)</script>', 'status' => 'inactive']),
            ],
            'pager'      => new Paginator(12, 5, 2),
            'filters'    => ['q' => 'coffee', 'status' => 'active'],
            'stats'      => ['total' => 2, 'active' => 1, 'inactive' => 1, 'out_of_stock' => 0],
            'references' => [
                1 => ['orders' => 0, 'carts' => 2, 'packages' => 0],
                2 => ['orders' => 0, 'carts' => 0, 'packages' => 0],
            ],
            'available'  => [1 => 50, 2 => 0],
        ];

        $payload = (new AdminProductController())->feedPayload(7, '"admin.products:7:abc"', $data);

        assert_true($payload['ok']);
        assert_same(7, $payload['revision']);
        assert_same(
            ['product-stats', 'product-rows', 'product-pagination'],
            array_keys($payload['fragments'])
        );

        $stats = $payload['fragments']['product-stats'];
        $rows  = $payload['fragments']['product-rows'];
        $pages = $payload['fragments']['product-pagination'];

        assert_contains('data-live-region="product-stats"', $stats);
        assert_contains('Out of stock', $stats);

        assert_contains('data-live-region="product-rows"', $rows);
        assert_contains('data-live-key="1"', $rows);
        assert_contains('data-live-action="status"', $rows);
        assert_contains('data-live-action="delete"', $rows);
        assert_contains('data-live-focus="delete"', $rows);
        assert_contains('This product appears in a member’s cart.', $rows);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $rows);
        assert_not_contains('<script>alert(1)</script>', $rows);

        assert_contains('data-live-region="product-pagination"', $pages);
        assert_contains('page=3', $pages);
        assert_contains('admin/products?q=coffee&amp;status=active&amp;page=3', $pages);
        assert_not_contains('/feed', $pages);

        assert_same(2, $payload['meta']['page']);
        assert_same(3, $payload['meta']['pages']);
        assert_same(12, $payload['meta']['total']);
        assert_contains('admin/products?q=coffee&status=active&page=2', $payload['meta']['canonical']);
    },

    'the product feed renders the empty state' => function (): void {
        test_signed_in_as(test_user('catalog_admin', 'admin'));

        $_GET = ['q' => 'nothing-matches'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/products?q=nothing-matches';

        $payload = (new AdminProductController())->feedPayload(3, '"admin.products:3:empty"', [
            'products'   => [],
            'pager'      => new Paginator(0, 5, 1),
            'filters'    => ['q' => 'nothing-matches', 'status' => ''],
            'stats'      => ['total' => 0, 'active' => 0, 'inactive' => 0, 'out_of_stock' => 0],
            'references' => [],
            'available'  => [],
        ]);

        $rows = $payload['fragments']['product-rows'];

        assert_contains('data-live-region="product-rows"', $rows);
        assert_contains('No products match these filters', $rows);
        assert_contains('Clear filters', $rows);
        assert_not_contains('data-live-key=', $rows);
    },
];
