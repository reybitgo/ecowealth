<?php
declare(strict_types=1);

/* ==============================================================
 *  CartTest — cart model, service rules and concurrency
 *  (plan §5.2, §7 Phase 4; tests P4-T1 … P4-T10)
 *
 *  Every mutation is scoped by the caller's cart id; the IDOR cases
 *  (P4-T5/T6) drive that guard directly. The concurrency case commits
 *  its fixtures and drives two PDO connections, because a row lock can
 *  only be observed across connections — it cleans up in `finally`.
 * ============================================================== */

/** A product with a predictable price and stock. */
$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Cart Product ' . bin2hex(random_bytes(4)),
        'price'  => '10.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$service = static fn (): CartService => new CartService();

return [
    /* ---------- P4-T1: create / add ------------------------------- */

    'P4-T1 adding a product creates the cart and its first line' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_owner');
        $id     = $makeProduct(['price' => '12.50', 'stock' => '4']);

        $result = $service()->add((int) $member['id'], $id, 2);

        assert_same(2, $result['count'], 'total units');
        assert_same(1, $result['items'], 'line count');
        assert_same(25.0, $result['subtotal']);
        assert_same(2, (int) $result['item']['quantity']);
        assert_same('12.50', number_format((float) $result['item']['unit_price'], 2, '.', ''));

        $cart = Cart::active((int) $member['id']);
        assert_not_null($cart);
        assert_same('active', $cart['status']);
        assert_same(1, Cart::totals((int) $cart['id'])['items']);
        assert_same(2, Cart::countFor((int) $member['id']));
    },

    'P4-T1 a page view never creates a cart row' => function () use ($service): void {
        $member = test_user('cart_browser');
        $before = (int) db()->query('SELECT COUNT(*) FROM carts')->fetchColumn();

        $state = $service()->state((int) $member['id']);

        assert_same(null, $state['cart']);
        assert_same([], $state['items']);
        assert_same(0, $state['totals']['quantity']);
        assert_same($before, (int) db()->query('SELECT COUNT(*) FROM carts')->fetchColumn());
    },

    'P4-T2 adding the same product again increments the single line' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_repeat');
        $id     = $makeProduct();

        $service()->add((int) $member['id'], $id, 1);
        $result = $service()->add((int) $member['id'], $id, 2);

        assert_same(1, $result['items'], 'no duplicate line');
        assert_same(3, $result['count']);
        assert_same(3, (int) $result['item']['quantity']);

        $cart = Cart::active((int) $member['id']);
        assert_same(1, (int) db()->query('SELECT COUNT(*) FROM cart_items WHERE cart_id = ' . (int) $cart['id'])->fetchColumn());
    },

    /* ---------- P4-T3: stock and quantity guards ------------------ */

    'P4-T3 adding beyond available stock is refused with the exact numbers' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_stock');
        $id     = $makeProduct(['stock' => '3']);

        $service()->add((int) $member['id'], $id, 2);

        $error = assert_throws(
            static fn () => $service()->add((int) $member['id'], $id, 2),
            CartException::class
        );

        assert_same('stock', $error->reason);
        assert_same('Insufficient stock. Requested 4, only 3 available.', $error->getMessage());

        $cart = Cart::active((int) $member['id']);
        assert_same(2, Cart::totals((int) $cart['id'])['quantity'], 'the refused add wrote nothing');
    },

    'P4-T3 quantity must be at least 1 and at most the line cap' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_qty_guard');
        $id     = $makeProduct();

        $zero = assert_throws(static fn () => $service()->add((int) $member['id'], $id, 0), CartException::class);
        assert_same('quantity', $zero->reason);
        assert_same('Quantity must be at least 1.', $zero->getMessage());

        $tooMany = assert_throws(
            static fn () => $service()->add((int) $member['id'], $id, CartService::MAX_QUANTITY + 1),
            CartException::class
        );
        assert_same('quantity', $tooMany->reason);
        assert_same(0, Cart::countFor((int) $member['id']), 'no cart was written by a refused add');
    },

    'P4-T10 inactive and unknown products cannot be added' => function () use ($service, $makeProduct): void {
        $member  = test_user('cart_inactive');
        $id      = $makeProduct(['name' => 'Retired Product', 'status' => 'inactive']);

        $inactive = assert_throws(
            static fn () => $service()->add((int) $member['id'], $id, 1),
            CartException::class
        );
        assert_same('product_inactive', $inactive->reason);
        assert_contains('Retired Product', $inactive->getMessage());

        $missing = assert_throws(
            static fn () => $service()->add((int) $member['id'], 999999, 1),
            CartException::class
        );
        assert_same('product_missing', $missing->reason);
    },

    'P4-T3 reserved stock reduces what the cart will accept' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_reserved');
        $id     = $makeProduct(['stock' => '3']);

        /* A real pending order holds two units (Phase 6 tables now exist). */
        $orderId = test_order((int) $member['id'], [
            ['product_id' => $id, 'quantity' => 2, 'reservation_state' => 'reserved'],
        ]);

        assert_same(1, StockService::available($id));

        $error = assert_throws(
            static fn () => $service()->add((int) $member['id'], $id, 2),
            CartException::class
        );

        assert_same('Insufficient stock. Requested 2, only 1 available.', $error->getMessage());

        /* One unit is still purchasable. */
        $ok = $service()->add((int) $member['id'], $id, 1);
        assert_same(1, $ok['count']);

        /* Cancelling the order releases the hold again. */
        db()->prepare("UPDATE shop_orders SET status = 'cancelled' WHERE id = ?")->execute([$orderId]);
        db()->prepare("UPDATE shop_order_items SET reservation_state = 'released' WHERE order_id = ?")->execute([$orderId]);
        assert_same(3, StockService::available($id));
    },

    /* ---------- P4-T4: update / remove --------------------------- */

    'P4-T4 updating the quantity persists and qty 0 removes the line' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_update');
        $id     = $makeProduct(['stock' => '10']);

        $added  = $service()->add((int) $member['id'], $id, 1);
        $itemId = (int) $added['item']['id'];

        $updated = $service()->updateQuantity((int) $member['id'], $itemId, 4);
        assert_same(4, $updated['count']);
        assert_same(4, (int) $updated['item']['quantity']);
        assert_same(40.0, $updated['subtotal']);

        $removed = $service()->updateQuantity((int) $member['id'], $itemId, 0);
        assert_true($removed['removed']);
        assert_same(0, $removed['count']);
        assert_same(0, (int) Cart::totals((int) $removed['cart_id'])['items']);

        assert_throws(
            static fn () => $service()->updateQuantity((int) $member['id'], $itemId, 2),
            CartException::class
        );
    },

    'P4-T4 updating beyond available is refused and keeps the old quantity' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_update_stock');
        $id     = $makeProduct(['stock' => '3']);

        $added  = $service()->add((int) $member['id'], $id, 1);
        $itemId = (int) $added['item']['id'];

        $error = assert_throws(
            static fn () => $service()->updateQuantity((int) $member['id'], $itemId, 4),
            CartException::class
        );

        assert_same('stock', $error->reason);
        assert_contains('only 3 available', $error->getMessage());

        $cart = Cart::active((int) $member['id']);
        assert_same(1, Cart::totals((int) $cart['id'])['quantity']);
    },

    'P4-T4 an inactive product cannot have its quantity raised' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_update_inactive');
        $id     = $makeProduct(['name' => 'Soon Retired']);

        $added  = $service()->add((int) $member['id'], $id, 1);
        $itemId = (int) $added['item']['id'];

        Product::setStatus($id, 'inactive');

        $error = assert_throws(
            static fn () => $service()->updateQuantity((int) $member['id'], $itemId, 2),
            CartException::class
        );
        assert_same('product_inactive', $error->reason);

        /* Removing it is still allowed — the member must be able to clear it. */
        $removed = $service()->updateQuantity((int) $member['id'], $itemId, 0);
        assert_true($removed['removed']);
    },

    'P4-T4 the no-JS stepper adjusts the quantity by ±1' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_adjust');
        $id     = $makeProduct(['stock' => '5']);

        $added  = $service()->add((int) $member['id'], $id, 1);
        $itemId = (int) $added['item']['id'];

        $up = $service()->adjustQuantity((int) $member['id'], $itemId, 1);
        assert_same(2, $up['count']);
        assert_same(2, (int) $up['item']['quantity']);
        assert_same(20.0, $up['subtotal']);

        $down = $service()->adjustQuantity((int) $member['id'], $itemId, -1);
        assert_same(1, $down['count']);

        /* Stepping below 1 removes the line, exactly like the JS path. */
        $removed = $service()->adjustQuantity((int) $member['id'], $itemId, -1);
        assert_true($removed['removed']);
        assert_same(0, $removed['count']);
        assert_same(0, (int) Cart::totals((int) $removed['cart_id'])['items']);

        assert_throws(
            static fn () => $service()->adjustQuantity((int) $member['id'], $itemId, 1),
            CartException::class
        );
    },

    'P4-T4 the no-JS stepper cannot oversell, raise an inactive line or cross carts' => function () use ($service, $makeProduct): void {
        $owner = test_user('cart_adjust_owner');
        $other = test_user('cart_adjust_other');
        $id    = $makeProduct(['name' => 'Step Product', 'stock' => '1']);

        $added  = $service()->add((int) $owner['id'], $id, 1);
        $itemId = (int) $added['item']['id'];

        $stock = assert_throws(
            static fn () => $service()->adjustQuantity((int) $owner['id'], $itemId, 1),
            CartException::class
        );
        assert_same('stock', $stock->reason);
        assert_same('Insufficient stock. Requested 2, only 1 available.', $stock->getMessage());

        $service()->add((int) $other['id'], $makeProduct(), 1);
        $idor = assert_throws(
            static fn () => $service()->adjustQuantity((int) $other['id'], $itemId, 1),
            CartException::class
        );
        assert_same('item_missing', $idor->reason);

        Product::setStatus($id, 'inactive');

        $inactive = assert_throws(
            static fn () => $service()->adjustQuantity((int) $owner['id'], $itemId, 1),
            CartException::class
        );
        assert_same('product_inactive', $inactive->reason);

        /* Stepping down still clears an unavailable line. */
        $down = $service()->adjustQuantity((int) $owner['id'], $itemId, -1);
        assert_true($down['removed']);

        assert_throws(
            static fn () => $service()->adjustQuantity((int) $owner['id'], $itemId, 0),
            CartException::class
        );
    },

    /* ---------- P4-T5 / P4-T6: IDOR ------------------------------ */

    'P4-T5 updating another member’s item is refused (IDOR)' => function () use ($service, $makeProduct): void {
        $owner  = test_user('cart_idor_owner');
        $other  = test_user('cart_idor_other');
        $id     = $makeProduct();

        $added  = $service()->add((int) $owner['id'], $id, 1);
        $itemId = (int) $added['item']['id'];

        /* The other member gets a cart too, so this is not "no cart". */
        $service()->add((int) $other['id'], $makeProduct(), 1);

        $error = assert_throws(
            static fn () => $service()->updateQuantity((int) $other['id'], $itemId, 5),
            CartException::class
        );

        assert_same('item_missing', $error->reason);
        assert_same(1, (int) db()->query('SELECT quantity FROM cart_items WHERE id = ' . $itemId)->fetchColumn());
    },

    'P4-T6 removing another member’s item is refused (IDOR)' => function () use ($service, $makeProduct): void {
        $owner  = test_user('cart_remove_owner');
        $other  = test_user('cart_remove_other');
        $id     = $makeProduct();

        $added  = $service()->add((int) $owner['id'], $id, 2);
        $itemId = (int) $added['item']['id'];

        $service()->add((int) $other['id'], $makeProduct(), 1);

        $error = assert_throws(
            static fn () => $service()->remove((int) $other['id'], $itemId),
            CartException::class
        );

        assert_same('item_missing', $error->reason);
        assert_same(1, (int) db()->query('SELECT COUNT(*) FROM cart_items WHERE id = ' . $itemId)->fetchColumn());
        assert_same(2, Cart::countFor((int) $owner['id']));
    },

    /* ---------- P4-T7: live prices ------------------------------- */

    'P4-T7 a catalogue price change reaches the cart totals' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_price');
        $id     = $makeProduct(['price' => '10.00']);

        $service()->add((int) $member['id'], $id, 2);

        $cart = Cart::active((int) $member['id']);
        assert_same(20.0, Cart::totals((int) $cart['id'])['subtotal']);

        Product::save([
            'name'   => (string) Product::find($id)['name'],
            'price'  => '12.50',
            'stock'  => '10',
            'status' => 'active',
        ], $id);

        $state = $service()->state((int) $member['id']);

        assert_same(25.0, $state['totals']['subtotal'], 'totals use the live price');
        assert_same(25.0, (float) $state['items'][0]['line_total']);
        assert_same('10.00', number_format((float) $state['items'][0]['unit_price'], 2, '.', ''), 'snapshot unchanged before a refresh');

        $refreshed = $service()->state((int) $member['id'], true);
        assert_same('12.50', number_format((float) $refreshed['items'][0]['unit_price'], 2, '.', ''));
    },

    /* ---------- P4-T8: isolation -------------------------------- */

    'P4-T8 two members’ carts are fully isolated' => function () use ($service, $makeProduct): void {
        $alice = test_user('cart_alice');
        $bob   = test_user('cart_bob');
        $coffee = $makeProduct(['name' => 'Alice Coffee']);
        $tea    = $makeProduct(['name' => 'Bob Tea']);

        $service()->add((int) $alice['id'], $coffee, 2);
        $service()->add((int) $bob['id'], $tea, 1);

        $aliceState = $service()->state((int) $alice['id']);
        $bobState   = $service()->state((int) $bob['id']);

        assert_same(1, count($aliceState['items']));
        assert_same(1, count($bobState['items']));
        assert_same('Alice Coffee', $aliceState['items'][0]['product_name']);
        assert_same('Bob Tea', $bobState['items'][0]['product_name']);
        assert_not_same((int) $aliceState['cart']['id'], (int) $bobState['cart']['id']);
        assert_same(2, $aliceState['totals']['quantity']);
        assert_same(1, $bobState['totals']['quantity']);
    },

    /* ---------- P4-T9: concurrency ------------------------------ */

    'P4-T9 concurrent adds to the last unit cannot oversell' => function (): void {
        $pdo = db();

        /* The second connection can only see committed rows, so the fixtures
         * have to be committed first. This case cleans them up in `finally`. */
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $username = 'race_' . bin2hex(random_bytes(4));
        $member   = test_user($username);
        $product  = Product::save([
            'name'   => 'Last Unit',
            'price'  => '5.00',
            'stock'  => '1',
            'status' => 'active',
        ]);

        $connectionA = test_pdo(['db' => DB_NAME]);
        $connectionB = test_pdo(['db' => DB_NAME]);
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $serviceA = new CartService($connectionA);
            $serviceB = new CartService($connectionB);

            /* A adds the unit inside its own transaction: the row locks are
             * held and A's line is not visible to B yet. */
            $connectionA->beginTransaction();
            $resultA = $serviceA->add((int) $member['id'], $product, 1);
            assert_same(1, $resultA['count']);
            assert_same(1, (int) $resultA['item']['quantity']);

            /* B must queue on the same member/cart rows and time out. */
            $blocked = assert_throws(
                static fn () => $serviceB->add((int) $member['id'], $product, 1),
                PDOException::class
            );
            assert_contains('1205', (string) $blocked->getCode() . ' ' . $blocked->getMessage());

            $connectionA->commit();

            /* Once A lands, B sees the line and refuses to oversell. */
            $error = assert_throws(
                static fn () => $serviceB->add((int) $member['id'], $product, 1),
                CartException::class
            );
            assert_same('stock', $error->reason);
            assert_same('Insufficient stock. Requested 2, only 1 available.', $error->getMessage());

            $cart = Cart::active((int) $member['id']);
            assert_same(1, Cart::totals((int) $cart['id'])['quantity'], 'never more than the available unit');
            assert_true(StockService::available($product) >= 0, 'available never goes negative');
        } finally {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $member['id']]);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$product]);
        }
    },

    /* ---------- Lifecycle --------------------------------------- */

    'P4-T1 getOrCreate returns one active cart; convert starts a new one' => function () use ($service): void {
        $member = test_user('cart_lifecycle');

        $first  = $service()->getOrCreate((int) $member['id']);
        $second = $service()->getOrCreate((int) $member['id']);

        assert_same((int) $first['id'], (int) $second['id']);
        assert_same(1, (int) db()->query(
            'SELECT COUNT(*) FROM carts WHERE user_id = ' . (int) $member['id'] . " AND status = 'active'"
        )->fetchColumn());

        assert_true($service()->convert((int) $member['id']));

        $third = $service()->getOrCreate((int) $member['id']);

        assert_not_same((int) $first['id'], (int) $third['id']);
        assert_same('converted', Cart::find((int) $first['id'])['status']);
        assert_same('active', Cart::find((int) $third['id'])['status']);
    },

    'P4-T4 clear empties the active cart but keeps it' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_clear');

        $service()->add((int) $member['id'], $makeProduct(), 2);
        $service()->add((int) $member['id'], $makeProduct(), 1);

        $cart = Cart::active((int) $member['id']);
        assert_same(2, Cart::totals((int) $cart['id'])['items']);

        assert_true($service()->clear((int) $member['id']));
        assert_same(0, Cart::countFor((int) $member['id']));
        assert_not_null(Cart::active((int) $member['id']), 'the cart row survives a clear');
    },

    'P4-T13 validateStock explains every blocking line' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_validate');

        $retired = $makeProduct(['name' => 'Retired Item']);
        $scarce  = $makeProduct(['name' => 'Scarce Item', 'stock' => '5']);

        $service()->add((int) $member['id'], $retired, 1);
        $service()->add((int) $member['id'], $scarce, 3);

        Product::setStatus($retired, 'inactive');

        Product::save([
            'name'   => 'Scarce Item',
            'price'  => '10.00',
            'stock'  => '2',
            'status' => 'active',
        ], $scarce);

        $errors = $service()->validateStock((int) $member['id']);

        assert_count(2, $errors);
        assert_contains('is no longer available', $errors[0]);
        assert_contains('Scarce Item: requested 3, only 2 available.', $errors[1]);
    },

    'P4-T13 a clean cart reports no stock problems' => function () use ($service, $makeProduct): void {
        $member = test_user('cart_clean');

        $service()->add((int) $member['id'], $makeProduct(), 1);

        assert_same([], $service()->validateStock((int) $member['id']));
        assert_same(1, $service()->summary((int) $member['id'])['items']);
    },
];
