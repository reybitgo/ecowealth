<?php
declare(strict_types=1);

/* ==============================================================
 *  ProductTest — catalogue model and stock service
 *  (plan §7 Phase 2, tests P2-T1 … P2-T9)
 *
 *  Cases that need tables from later phases (cart_items,
 *  shop_order_items, shop_orders) create and drop them locally:
 *  DDL commits implicitly, so each one cleans up after itself.
 * ============================================================== */

/** Minimal Phase 4 table, so the cart-reference guard can be exercised now. */
$cartTable = static function (): void {
    db()->exec('DROP TABLE IF EXISTS cart_items');
    db()->exec(
        'CREATE TABLE cart_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cart_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1
        ) ENGINE=InnoDB'
    );
};

/** Minimal Phase 6 tables, so reservations and order references can be exercised. */
$orderTables = static function (): void {
    db()->exec('DROP TABLE IF EXISTS shop_order_items');
    db()->exec('DROP TABLE IF EXISTS shop_orders');
    db()->exec(
        'CREATE TABLE shop_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\'
        ) ENGINE=InnoDB'
    );
    db()->exec(
        'CREATE TABLE shop_order_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            reservation_state VARCHAR(20) NOT NULL DEFAULT \'reserved\'
        ) ENGINE=InnoDB'
    );
    StockService::forgetSchemaCache();
};

$dropLateTables = static function (): void {
    db()->exec('DROP TABLE IF EXISTS shop_order_items');
    db()->exec('DROP TABLE IF EXISTS shop_orders');
    db()->exec('DROP TABLE IF EXISTS cart_items');
    StockService::forgetSchemaCache();
};

return [
    /* ---------- Seed + reads --------------------------------------- */

    'P2-T1 the demo seed loads six active products' => function (): void {
        $products = Product::all();

        assert_count(6, $products);
        assert_same('Coffee', $products[0]['name']);
        assert_same('active', $products[0]['status']);
        assert_same('assets/img/products/collagen-coffee.webp', $products[0]['image_url']);

        /* Two seed rows intentionally have no image. */
        $withoutImage = array_filter($products, static fn (array $p): bool => $p['image_url'] === null);
        assert_count(2, $withoutImage);
    },

    'P2-T4 the storefront list is ordered and filtered by status' => function (): void {
        $coffee = Product::findBySlug('coffee');
        assert_not_null($coffee);

        Product::setStatus((int) $coffee['id'], 'inactive');

        $catalog = Product::catalogList([], new Paginator(0, 20, 1));
        assert_count(5, $catalog);

        $names = array_column($catalog, 'name');
        assert_false(in_array('Coffee', $names, true));

        $admin = Product::adminList([], new Paginator(0, 20, 1));
        assert_count(6, $admin);

        /* The rest of the suite expects the seed untouched. */
        Product::setStatus((int) $coffee['id'], 'active');
    },

    'P2-T8 search matches name, SKU and short description' => function (): void {
        assert_same(1, Product::countCatalog(['q' => 'spirulina']));
        assert_same(1, Product::countCatalog(['q' => 'EW-KOJIC']));
        assert_same(1, Product::countCatalog(['q' => 'powder']));
        assert_same(0, Product::countCatalog(['q' => 'no-such-product']));
    },

    'P2-T8 LIKE wildcards in the search term are escaped' => function (): void {
        assert_same(0, Product::countCatalog(['q' => '%']));
        assert_same(0, Product::countCatalog(['q' => '_']));
    },

    'P2-T8 the admin list paginates and keeps filters' => function (): void {
        $first = Product::adminList([], new Paginator(6, 4, 1));
        $second = Product::adminList([], new Paginator(6, 4, 2));

        assert_count(4, $first);
        assert_count(2, $second);

        $pager = new Paginator(6, 4, 2);
        assert_same(2, $pager->pages);
        assert_contains('page=2', $pager->url(2));
    },

    /* ---------- Writes ---------------------------------------------- */

    'P2-T1 creating a product generates a unique slug' => function (): void {
        $id = Product::save([
            'name'  => 'Test Widget',
            'price' => '99.50',
            'stock' => '7',
            'status' => 'active',
        ]);

        $product = Product::find($id);
        assert_not_null($product);
        assert_same('test-widget', $product['slug']);
        assert_same('99.50', $product['price']);
        assert_same(7, (int) $product['stock']);
        assert_null($product['sku']);

        $secondId = Product::save([
            'name'  => 'Test Widget',
            'price' => '5.00',
            'stock' => '1',
            'status' => 'active',
        ]);
        assert_same('test-widget-2', Product::find($secondId)['slug']);
    },

    'P2-T4 updating keeps the slug and stores the new price' => function (): void {
        $id = Product::save([
            'name'  => 'Slug Keeper',
            'price' => '10.00',
            'stock' => '3',
            'status' => 'active',
        ]);

        Product::save([
            'name'  => 'Slug Keeper Renamed',
            'price' => '12.75',
            'stock' => '4',
            'status' => 'inactive',
        ], $id);

        $product = Product::find($id);
        assert_same('slug-keeper', $product['slug']);
        assert_same('Slug Keeper Renamed', $product['name']);
        assert_same('12.75', $product['price']);
        assert_same('inactive', $product['status']);
    },

    'P2-T2 validation rejects empty name, zero price and negative stock' => function (): void {
        $errors = Product::validate([
            'name'  => ' ',
            'price' => '0',
            'stock' => '-1',
            'status' => 'bogus',
        ]);

        assert_true(isset($errors['name']), 'name must fail');
        assert_true(isset($errors['price']), 'price must fail');
        assert_true(isset($errors['stock']), 'stock must fail');
        assert_true(isset($errors['status']), 'status must fail');
    },

    'P2-T2 validation enforces the description length' => function (): void {
        $errors = Product::validate([
            'name'        => 'Long copy',
            'price'       => '1.00',
            'stock'       => '1',
            'status'      => 'active',
            'description' => str_repeat('x', 5001),
        ]);

        assert_true(isset($errors['description']));
        assert_contains('at most 5000', $errors['description']);
    },

    'P2-T1 a valid product passes validation' => function (): void {
        $errors = Product::validate([
            'name'              => 'Valid Product',
            'sku'               => 'VAL-1',
            'price'             => '19.99',
            'stock'             => '0',
            'sort_order'        => '10',
            'short_description' => 'Short line',
            'description'       => 'Longer copy.',
            'status'            => 'active',
        ]);

        assert_same([], $errors);
    },

    'P2-T1 SKUs stay unique while empty SKUs may repeat' => function (): void {
        $id = Product::save([
            'name'  => 'SKU Owner',
            'sku'   => 'TEST-SKU-1',
            'price' => '10.00',
            'stock' => '1',
            'status' => 'active',
        ]);

        $errors = Product::validate([
            'name'  => 'Other',
            'sku'   => 'TEST-SKU-1',
            'price' => '10.00',
            'stock' => '1',
            'status' => 'active',
        ]);
        assert_true(isset($errors['sku']), 'duplicate SKU must fail');

        /* The same row may keep its own SKU. */
        assert_same([], Product::validate([
            'name'  => 'SKU Owner',
            'sku'   => 'TEST-SKU-1',
            'price' => '10.00',
            'stock' => '1',
            'status' => 'active',
        ], $id));

        /* Two products without a SKU are fine (NULL is not unique). */
        Product::save(['name' => 'No SKU A', 'price' => '1.00', 'stock' => '1', 'status' => 'active']);
        $secondId = Product::save(['name' => 'No SKU B', 'price' => '1.00', 'stock' => '1', 'status' => 'active']);
        assert_null(Product::find($secondId)['sku']);
    },

    'P2-T5 setStatus toggles products and refuses unknown statuses' => function (): void {
        $id = Product::save(['name' => 'Toggle Me', 'price' => '1.00', 'stock' => '1', 'status' => 'active']);

        assert_true(Product::setStatus($id, 'inactive'));
        assert_same('inactive', Product::find($id)['status']);

        assert_true(Product::setStatus($id, 'active'));
        assert_same('active', Product::find($id)['status']);

        assert_throws(static fn () => Product::setStatus($id, 'archived'), InvalidArgumentException::class);
        assert_false(Product::setStatus(999999, 'inactive'));
    },

    /* ---------- Delete guards (P2-T6, P2-T7) ------------------------- */

    'P2-T6 an unreferenced product can be deleted' => function (): void {
        $id = Product::save(['name' => 'Delete Me', 'price' => '1.00', 'stock' => '1', 'status' => 'active']);

        $result = Product::delete($id);

        assert_true($result['ok']);
        assert_null(Product::find($id));
        assert_same('missing', Product::delete($id)['reason']);
    },

    'P2-T6 deleting a product removes its uploaded image' => function (): void {
        $tmp = sys_get_temp_dir() . '/eco-delete-image-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($tmp, TEST_PNG_BYTES);

        $upload = UploadService::image([
            'name'     => 'delete-me.png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($tmp),
        ], 'products');

        assert_true($upload['ok'], 'upload failed: ' . (string) $upload['error']);

        $id = Product::save([
            'name'      => 'With An Image',
            'price'     => '1.00',
            'stock'     => '1',
            'status'    => 'active',
            'image_url' => $upload['path'],
        ]);

        $absolute = Upload::root() . substr((string) $upload['path'], strlen('uploads/'));
        assert_true(is_file($absolute), 'uploaded image should exist before the delete');

        assert_true(Product::delete($id)['ok']);
        assert_false(is_file($absolute), 'the uploaded image should be removed with the product');
    },

    'P2-T7 delete is refused while a cart references the product' => function () use ($cartTable, $dropLateTables): void {
        $id = Product::save(['name' => 'In A Cart', 'price' => '1.00', 'stock' => '1', 'status' => 'active']);

        $cartTable();
        db()->prepare('INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (1, ?, 2)')->execute([$id]);

        try {
            $result = Product::delete($id);

            assert_false($result['ok']);
            assert_same('cart', $result['reason']);
            assert_same(1, $result['carts']);
            assert_not_null(Product::find($id), 'the product must survive');
        } finally {
            $dropLateTables();
            /* The DDL above committed the transaction — clean up for real. */
            Product::delete($id);
        }
    },

    'P2-T7 delete is refused while an order references the product' => function () use ($orderTables, $dropLateTables): void {
        $id = Product::save(['name' => 'In An Order', 'price' => '1.00', 'stock' => '1', 'status' => 'active']);

        $orderTables();
        db()->prepare('INSERT INTO shop_order_items (order_id, product_id, quantity) VALUES (1, ?, 1)')->execute([$id]);

        try {
            $result = Product::delete($id);

            assert_false($result['ok']);
            assert_same('orders', $result['reason']);
            assert_same(1, $result['orders']);
        } finally {
            $dropLateTables();
            Product::delete($id);
        }
    },

    'P2-T7 referencesMap batches counts for a page of products' => function () use ($cartTable, $dropLateTables): void {
        $a = Product::save(['name' => 'Ref A', 'price' => '1.00', 'stock' => '1', 'status' => 'active']);
        $b = Product::save(['name' => 'Ref B', 'price' => '1.00', 'stock' => '1', 'status' => 'active']);

        $cartTable();
        db()->prepare('INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (1, ?, 1), (2, ?, 3)')
            ->execute([$a, $a]);

        try {
            $map = Product::referencesMap([$a, $b, 999999]);

            assert_same(2, $map[$a]['carts']);
            assert_same(0, $map[$b]['carts']);
            assert_same(['orders' => 0, 'carts' => 0], $map[999999]);
        } finally {
            $dropLateTables();
            Product::delete($a);
            Product::delete($b);
        }
    },

    /* ---------- StockService ---------------------------------------- */

    'P2-T2 StockService labels stock levels' => function (): void {
        assert_same('out', StockService::label(0)['key']);
        assert_same('Sold out', StockService::label(0)['label']);
        assert_same('low', StockService::label(1)['key']);
        assert_same('1 left', StockService::label(1)['label']);
        assert_same('5 left', StockService::label(5)['label']);
        assert_same('in', StockService::label(6)['key']);
        assert_same('In stock', StockService::label(6)['label']);
    },

    'P2-T2 available stock is the physical count while no orders exist' => function (): void {
        $coffee = Product::findBySlug('coffee');
        assert_not_null($coffee);

        assert_same((int) $coffee['stock'], StockService::available((int) $coffee['id']));
        assert_same(0, StockService::available(999999));

        $map = StockService::availableMap([(int) $coffee['id'], 999999]);
        assert_same((int) $coffee['stock'], $map[(int) $coffee['id']]);
        assert_false(array_key_exists(999999, $map), 'missing products are omitted');
    },

    'P2-T2 reservations from non-terminal orders reduce availability' => function () use ($orderTables, $dropLateTables): void {
        $coffee = Product::findBySlug('coffee');
        assert_not_null($coffee);
        $id  = (int) $coffee['id'];
        $stock = (int) $coffee['stock'];

        $orderTables();

        try {
            db()->exec("INSERT INTO shop_orders (id, status) VALUES (1, 'pending'), (2, 'cancelled'), (3, 'completed')");
            db()->exec(
                "INSERT INTO shop_order_items (order_id, product_id, quantity, reservation_state) VALUES
                 (1, {$id}, 3, 'reserved'),
                 (2, {$id}, 4, 'reserved'),
                 (3, {$id}, 5, 'reserved')"
            );

            /* Only the pending order holds stock. */
            assert_same($stock - 3, StockService::available($id));
            assert_same($stock - 3, StockService::availableMap([$id])[$id]);

            db()->exec("UPDATE shop_orders SET status = 'cancelled' WHERE id = 1");
            assert_same($stock, StockService::available($id), 'cancelling releases the reservation');

            db()->exec("UPDATE shop_orders SET status = 'pending' WHERE id = 1");
            db()->exec("UPDATE shop_order_items SET reservation_state = 'deducted' WHERE order_id = 1");
            assert_same($stock, StockService::available($id), 'shipped stock is deducted, not reserved');
        } finally {
            $dropLateTables();
        }
    },

    'P2-T2 availability never drops below zero' => function () use ($orderTables, $dropLateTables): void {
        $id = Product::save(['name' => 'Zero Stock', 'price' => '1.00', 'stock' => '2', 'status' => 'active']);

        $orderTables();

        try {
            db()->exec("INSERT INTO shop_orders (id, status) VALUES (10, 'paid')");
            db()->prepare('INSERT INTO shop_order_items (order_id, product_id, quantity) VALUES (10, ?, 9)')->execute([$id]);

            assert_same(0, StockService::available($id));
        } finally {
            $dropLateTables();
            Product::delete($id);
        }
    },

    /* ---------- Upload service (P2-T3) ------------------------------- */

    'P2-T3 UploadService replaces a product image and removes the old file' => function (): void {
        $path = sys_get_temp_dir() . '/eco-product-image-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path, TEST_PNG_BYTES);

        $first = UploadService::image([
            'name'     => 'one.png',
            'tmp_name' => $path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($path),
        ], 'products');

        assert_true($first['ok'], 'first upload failed: ' . (string) $first['error']);

        $path2 = sys_get_temp_dir() . '/eco-product-image-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path2, TEST_PNG_BYTES);

        $second = UploadService::replace($first['path'], [
            'name'     => 'two.png',
            'tmp_name' => $path2,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($path2),
        ], 'products');

        assert_true($second['ok'], 'second upload failed: ' . (string) $second['error']);
        assert_not_same($first['path'], $second['path']);

        $oldFile = Upload::root() . substr((string) $first['path'], strlen('uploads/'));
        $newFile = Upload::root() . substr((string) $second['path'], strlen('uploads/'));
        assert_false(is_file($oldFile), 'the replaced image should be gone');
        assert_true(is_file($newFile), 'the new image should exist');

        UploadService::remove((string) $second['path']);
    },

    'P2-T3 UploadService ignores seeded asset paths when removing' => function (): void {
        /* assets/… is not user content — remove() must refuse it. */
        assert_false(UploadService::remove('assets/img/products/collagen-coffee.webp'));
        assert_true(is_file(dirname(__DIR__, 2) . '/public/assets/img/products/collagen-coffee.webp'));
    },

    /* ---------- Slug helper ----------------------------------------- */

    'P2-T1 slugify normalises names safely' => function (): void {
        assert_same('coffee', slugify('Coffee'));
        assert_same('immune-booster', slugify('  Immune   Booster! '));
        assert_same('kojic-soap-100g', slugify('Kojic Soap (100g)'));
        assert_same('cafe-creme', slugify('Café Crème'));
        assert_same('', slugify('!!!'));
    },
];
