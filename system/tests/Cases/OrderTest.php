<?php
declare(strict_types=1);

/* ==============================================================
 *  OrderTest — checkout, placement, address book, cancellation
 *  (plan §5.3, §7 Phase 6; tests P6-T1 … P6-T14)
 *
 *  Every case runs inside the harness transaction and rolls back, so
 *  it may create members, products and orders freely. P6-T14 commits
 *  its fixtures because a row lock is only observable across two
 *  connections; it cleans up in `finally`.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Order Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'ORD-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('ord_' . bin2hex(random_bytes(4)));
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

$addToCart = static function (int $userId, int $productId, int $quantity = 1): void {
    (new CartService())->add($userId, $productId, $quantity);
};

$input = static function (array $overrides = []): array {
    return $overrides + [
        'billing_name'      => 'Juan Dela Cruz',
        'billing_phone'     => '0917 000 0000',
        'shipping_name'     => 'Juan Dela Cruz',
        'shipping_phone'    => '0917 000 0000',
        'shipping_address'  => '12 Mabini Street',
        'shipping_barangay' => 'Poblacion',
        'shipping_city'     => 'Makati',
        'shipping_province' => 'Metro Manila',
        'shipping_postal'   => '1200',
        'notes_member'      => 'Leave at the gate',
        'payment_method'    => 'ewallet',
        'idempotency_key'   => bin2hex(random_bytes(16)),
        'terms'             => '1',
    ];
};

$ledgerRows = static function (int $userId): array {
    $stmt = db()->prepare("SELECT * FROM ewallet_ledger WHERE user_id = ? AND ref_type = 'shop_order' ORDER BY id ASC");
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
};

/* --------------------------------------------------------------
 *  Phase 17C fixture: a configured product and a four-deep chain
 * -------------------------------------------------------------- */

$makeRepeatProduct = static function (): int {
    return Product::save([
        'name' => 'P17C Order ' . bin2hex(random_bytes(3)),
        'sku'  => 'P17C-ORD-' . strtoupper(bin2hex(random_bytes(3))),
        'price' => '300.00', 'pv' => '250.00', 'unilevel_enabled' => '1',
        'unilevel_base' => 'auto', 'stock' => '10', 'status' => 'active',
    ], null, ProductUnilevel::referencePreset());
};

/** @return array{buyer: array<string, mixed>, levels: array<int, array<string, mixed>>} */
$makeChain = static function (string $prefix): array {
    $buyer  = test_user($prefix . '_buyer_' . bin2hex(random_bytes(3)));
    $levels = [];
    $child  = $buyer;

    for ($level = 1; $level <= 4; $level++) {
        $upline = test_user($prefix . '_u' . $level . '_' . bin2hex(random_bytes(3)));

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $upline['id'], (int) $child['id']]);

        $levels[$level] = $upline;
        $child          = $upline;
    }

    return ['buyer' => $buyer, 'levels' => $levels];
};

return [
    /* ---------- P6-T1: the two placement paths --------------------- */

    'P6-T1 an e-wallet order is paid, reserved, converted and debited once' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input, $ledgerRows): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['name' => 'Coffee Beans', 'sku' => 'COF-1', 'price' => '25.00', 'stock' => '10']);

        $fund($userId, '100.00');
        $addToCart($userId, $product, 2);

        $result = (new OrderService())->placeFromCart($userId, $input());

        assert_same('paid', $result['status']);
        assert_same(false, $result['replayed']);
        assert_same(50.0, $result['total']);

        $order = Order::find($result['order_id']);
        assert_not_null($order);
        assert_same($userId, (int) $order['user_id']);
        assert_same('paid', $order['status']);
        assert_same('50.00', number_format((float) $order['subtotal'], 2, '.', ''));
        assert_same('0.00', number_format((float) $order['shipping_fee'], 2, '.', ''));
        assert_same('50.00', number_format((float) $order['total'], 2, '.', ''));
        assert_same('v1', $order['terms_version']);
        assert_not_null($order['terms_accepted_at']);
        assert_not_null($order['paid_at']);
        assert_null($order['payment_deadline']);
        assert_true(OrderNumber::isValid((string) $order['order_no']));

        /* Wallet: one debit, balance 50, one ledger row. */
        assert_same(50.0, (new WalletService())->balance($userId));
        $rows = $ledgerRows($userId);
        assert_count(1, $rows);
        assert_same('debit', $rows[0]['type']);
        assert_same('50.00', number_format((float) $rows[0]['amount'], 2, '.', ''));
        assert_same($result['order_id'], (int) $rows[0]['ref_id']);

        /* Cart converted; next add creates a fresh cart. */
        assert_null(Cart::active($userId));
        assert_same(1, (new CartService())->add($userId, $product, 1)['count']);
        assert_count(1, Cart::items((int) Cart::active($userId)['id']));

        /* Items: snapshot + committed reservation. */
        $items = Order::items($result['order_id']);
        assert_count(1, $items);
        assert_same('Coffee Beans', $items[0]['product_name']);
        assert_same('COF-1', $items[0]['product_sku']);
        assert_same(2, (int) $items[0]['quantity']);
        assert_same('25.00', number_format((float) $items[0]['unit_price'], 2, '.', ''));
        assert_same('50.00', number_format((float) $items[0]['line_total'], 2, '.', ''));
        assert_same('committed', $items[0]['reservation_state']);

        /* Committed stock still counts as held until it ships. */
        assert_same(8, StockService::available($product));

        /* Events: order_placed then paid. */
        $events = Order::events($result['order_id']);
        assert_count(2, $events);
        assert_same('order_placed', $events[0]['reason_code']);
        assert_same('member', $events[0]['actor_type']);
        assert_same('paid', $events[1]['reason_code']);
        assert_same('system', $events[1]['actor_type']);
    },

    'P6-T1 a manual order stays pending without touching the wallet' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input, $ledgerRows): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '40.00', 'stock' => '5']);

        $fund($userId, '500.00');
        $addToCart($userId, $product, 1);

        $result = (new OrderService())->placeFromCart($userId, $input(['payment_method' => 'gcash']));

        assert_same('pending', $result['status']);
        assert_same(40.0, $result['total']);

        $order = Order::find($result['order_id']);
        assert_same('pending', $order['status']);
        assert_null($order['paid_at']);
        assert_not_null($order['payment_deadline']);
        assert_same('gcash', $order['payment_method']);

        $deadline = strtotime((string) $order['payment_deadline']);
        assert_true($deadline !== false && $deadline > time(), 'a manual order gets a future payment deadline');
        assert_true($deadline <= time() + 26 * 3600, 'the deadline follows shop_payment_deadline_hours');

        assert_same(500.0, (new WalletService())->balance($userId), 'nothing was debited');
        assert_count(0, $ledgerRows($userId));
        assert_same('reserved', Order::items($result['order_id'])[0]['reservation_state']);

        $events = Order::events($result['order_id']);
        assert_count(1, $events);
        assert_same('order_placed', $events[0]['reason_code']);
    },

    /* ---------- P6-T2 / P6-T3: idempotency and pricing ------------ */

    'P6-T2 the same idempotency key returns the original order once' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input, $ledgerRows): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '30.00', 'stock' => '5']);

        $fund($userId, '100.00');
        $addToCart($userId, $product, 2);

        $payload = $input(['idempotency_key' => 'idem-' . bin2hex(random_bytes(8))]);
        $first   = (new OrderService())->placeFromCart($userId, $payload);
        $second  = (new OrderService())->placeFromCart($userId, $payload);

        assert_same($first['order_id'], $second['order_id']);
        assert_same(true, $second['replayed']);
        assert_count(1, Order::listForUser($userId, Order::normaliseFilters([]), new Paginator(1, 10, 1)));
        assert_count(1, Order::items($first['order_id']));
        assert_count(1, $ledgerRows($userId));
        assert_same(40.0, (new WalletService())->balance($userId), 'the wallet was debited exactly once');
    },

    'P6-T3 posted prices are ignored; the catalogue price wins' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00', 'stock' => '10']);

        $fund($userId, '200.00');
        $addToCart($userId, $product, 2);

        $payload = $input([
            'price'      => '0.01',
            'unit_price' => '0.01',
            'subtotal'   => '0.02',
            'total'      => '0.02',
        ]);

        $result = (new OrderService())->placeFromCart($userId, $payload);
        assert_same(50.0, $result['total'], 'the server total comes from the catalogue');

        /* A catalog price change after the cart snapshot is used too. */
        Product::save([
            'name'   => (string) Product::find($product)['name'],
            'price'  => '30.00',
            'stock'  => '10',
            'status' => 'active',
        ], $product);

        $second = $makeMember();
        $secondId = (int) $second['id'];
        $fund($secondId, '200.00');
        $addToCart($secondId, $product, 2);

        $changed = (new OrderService())->placeFromCart($secondId, $input(['payment_method' => 'gcash']));
        assert_same(60.0, $changed['total']);
        assert_same('30.00', number_format((float) Order::items($changed['order_id'])[0]['unit_price'], 2, '.', ''));
    },

    /* ---------- P6-T4 / P6-T5: refusals --------------------------- */

    'P6-T4 stock changed after the cart renders refuses the whole order' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input, $ledgerRows): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['name' => 'Scarce Item', 'price' => '10.00', 'stock' => '5']);

        $fund($userId, '100.00');
        $addToCart($userId, $product, 3);

        Product::save([
            'name'   => 'Scarce Item',
            'price'  => '10.00',
            'stock'  => '1',
            'status' => 'active',
        ], $product);

        $error = assert_throws(
            static fn () => (new OrderService())->placeFromCart($userId, $input()),
            OrderException::class
        );

        assert_same('stock', $error->reason);
        assert_contains('Scarce Item', $error->getMessage());
        assert_contains('only has 1 left', $error->getMessage());
        assert_contains('asked for 3', $error->getMessage());

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM shop_orders WHERE user_id = ' . $userId)->fetchColumn());
        assert_count(0, $ledgerRows($userId));
        assert_same(100.0, (new WalletService())->balance($userId));
        assert_not_null(Cart::active($userId), 'the cart is untouched');
        assert_same(3, Cart::countFor($userId));
    },

    'P6-T5 insufficient wallet refuses the order and keeps everything' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input, $ledgerRows): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00', 'stock' => '10']);

        $fund($userId, '10.00');
        $addToCart($userId, $product, 2);

        $error = assert_throws(
            static fn () => (new OrderService())->placeFromCart($userId, $input()),
            OrderException::class
        );

        assert_same('wallet', $error->reason);
        assert_contains('not enough', $error->getMessage());

        assert_same(10.0, (new WalletService())->balance($userId));
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM shop_orders WHERE user_id = ' . $userId)->fetchColumn());
        assert_count(0, $ledgerRows($userId));
        assert_same(2, Cart::countFor($userId), 'the cart keeps both units');

        /* Manual payment still works and leaves the wallet alone. */
        $manual = (new OrderService())->placeFromCart($userId, $input(['payment_method' => 'maya']));
        assert_same('pending', $manual['status']);
        assert_same(10.0, (new WalletService())->balance($userId));
    },

    'P6-T6 missing address fields and terms are field-level errors' => function () use ($makeProduct, $makeMember, $fund, $addToCart): void {
        $errors = OrderService::validateCheckout([]);

        foreach (['billing_name', 'shipping_name', 'shipping_address', 'payment_method', 'terms'] as $field) {
            assert_true(isset($errors[$field]), 'missing required field: ' . $field);
        }

        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct();

        $fund($userId, '100.00');
        $addToCart($userId, $product, 1);

        $error = assert_throws(
            static fn () => (new OrderService())->placeFromCart($userId, [
                'billing_name'      => '',
                'shipping_name'     => '',
                'shipping_address'  => '',
                'payment_method'    => 'bitcoin',
                'terms'             => '',
            ]),
            OrderException::class
        );

        assert_same('validation', $error->reason);
        assert_true(isset($error->fieldErrors['terms']));
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM shop_orders WHERE user_id = ' . $userId)->fetchColumn());
        assert_same(1, Cart::countFor($userId));
    },

    /* ---------- P6-T7: address book ------------------------------- */

    'P6-T7 the address book enforces one default and member ownership' => function () use ($makeMember): void {
        $alice = $makeMember();
        $bob   = $makeMember();
        $a     = (int) $alice['id'];
        $b     = (int) $bob['id'];
        $book  = new AddressService();

        $base = [
            'recipient_name' => 'Alice Cruz',
            'phone'          => '0917 111 1111',
            'address_line'   => '1 First Street',
            'barangay'       => 'Poblacion',
            'city'           => 'Makati',
            'province'       => 'Metro Manila',
            'postal'         => '1200',
        ];

        $home = $book->save($a, $base + ['label' => 'Home']);
        $work = $book->save($a, ['recipient_name' => 'Alice Cruz', 'address_line' => '2 Office Road', 'label' => 'Work']);

        assert_same($home, (int) $book->defaultFor($a)['id'], 'the first address becomes the default');
        assert_same(2, $book->countFor($a));

        assert_true($book->setDefault($work, $a));
        assert_same($work, (int) $book->defaultFor($a)['id']);
        assert_same(0, (int) Address::findOwned($home, $a)['is_default'], 'the old default is cleared');

        assert_true($book->remove($work, $a));
        assert_same($home, (int) $book->defaultFor($a)['id'], 'deleting the default promotes the remaining address');

        /* Member B cannot read, edit, delete or default A's address. */
        assert_null(Address::findOwned($home, $b));
        assert_false($book->remove($home, $b));
        assert_false($book->setDefault($home, $b));
        assert_throws(
            static fn () => $book->save($b, ['recipient_name' => 'Bob', 'address_line' => '3 Other Street'], $home),
            InvalidArgumentException::class
        );
        assert_not_null(Address::findOwned($home, $a), 'A still owns the address');
    },

    'P6-T7 checkout can save the typed address for next time' => function () use ($makeMember): void {
        $member = $makeMember();
        $id     = (int) $member['id'];
        $book   = new AddressService();

        assert_same(0, $book->countFor($id));

        $addressId = $book->saveFromCheckout($id, [
            'shipping_name'     => 'Juan Dela Cruz',
            'shipping_phone'    => '0917 000 0000',
            'shipping_address'  => '12 Mabini Street',
            'shipping_barangay' => 'Poblacion',
            'shipping_city'     => 'Makati',
            'shipping_province' => 'Metro Manila',
            'shipping_postal'   => '1200',
            'billing_name'      => 'Juan Dela Cruz',
            'billing_phone'     => '0917 000 0000',
            'notes_member'      => 'Gate code 1234',
        ]);

        $saved = Address::findOwned($addressId, $id);
        assert_not_null($saved);
        assert_same('Juan Dela Cruz', $saved['recipient_name']);
        assert_same('12 Mabini Street', $saved['address_line']);
        assert_same('Makati', $saved['city']);
        assert_same(1, (int) $saved['is_default'], 'the first saved address is the default');
    },

    /* ---------- P6-T8 / P6-T9 / P6-T10: reads and isolation ------- */

    'P6-T8 another member cannot read or cancel the order' => function () use ($makeProduct, $makeMember, $addToCart, $input): void {
        $alice = $makeMember();
        $bob   = $makeMember();
        $product = $makeProduct();

        $addToCart((int) $alice['id'], $product, 1);
        $order = (new OrderService())->placeFromCart((int) $alice['id'], $input(['payment_method' => 'bank']));

        assert_not_null(Order::findForUser($order['order_id'], (int) $alice['id']));
        assert_null(Order::findForUser($order['order_id'], (int) $bob['id']));
        assert_count(0, Order::listForUser((int) $bob['id'], Order::normaliseFilters([]), new Paginator(0)));

        $error = assert_throws(
            static fn () => (new OrderService())->cancelByMember((int) $bob['id'], $order['order_id']),
            OrderException::class
        );
        assert_same('not_found', $error->reason);
        assert_same('pending', Order::find($order['order_id'])['status'], 'the owner’s order is untouched');
    },

    'P6-T9 the order list filters, paginates and totals correctly' => function () use ($makeProduct, $makeMember, $addToCart, $input): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '10.00', 'stock' => '20']);

        $orders = [];
        foreach (['gcash', 'maya', 'bank'] as $method) {
            $addToCart($userId, $product, 1);
            $orders[] = (new OrderService())->placeFromCart($userId, $input(['payment_method' => $method]))['order_id'];
        }

        (new OrderService())->cancelByMember($userId, $orders[2], 'Changed my mind');

        $all = new Paginator(3, 2, 1);
        $page1 = Order::listForUser($userId, Order::normaliseFilters([]), $all);
        $page2 = Order::listForUser($userId, Order::normaliseFilters([]), new Paginator(3, 2, 2));
        assert_count(2, $page1);
        assert_count(1, $page2);

        $pending = Order::listForUser($userId, Order::normaliseFilters(['status' => 'pending']), new Paginator(2, 10, 1));
        assert_count(2, $pending);
        $cancelled = Order::listForUser($userId, Order::normaliseFilters(['status' => 'cancelled']), new Paginator(1, 10, 1));
        assert_count(1, $cancelled);

        $gcash = Order::listForUser($userId, Order::normaliseFilters(['method' => 'gcash']), new Paginator(1, 10, 1));
        assert_count(1, $gcash);
        assert_same('gcash', $gcash[0]['payment_method']);

        /* Search by the order number: a bare numeric id could match the
         * yymmdd/id digits inside several order numbers and make this
         * case depend on AUTO_INCREMENT. */
        $firstNo = (string) Order::find($orders[0])['order_no'];
        $search = Order::listForUser($userId, Order::normaliseFilters(['q' => $firstNo]), new Paginator(1, 10, 1));
        assert_count(1, $search);

        $today = date('Y-m-d');
        $byDate = Order::listForUser($userId, Order::normaliseFilters(['from' => $today, 'to' => $today]), new Paginator(3, 10, 1));
        assert_count(3, $byDate);

        /* Invalid filters are ignored, never trusted. */
        $invalid = Order::normaliseFilters(['status' => 'not-a-status', 'method' => 'crypto', 'from' => '2026-02-31']);
        assert_same('', $invalid['status']);
        assert_same('', $invalid['method']);
        assert_same('', $invalid['from']);
    },

    'P6-T10 admins see every order; the stats count what they paid' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input): void {
        $alice = $makeMember();
        $bob   = $makeMember();
        $product = $makeProduct(['price' => '50.00', 'stock' => '10']);

        $fund((int) $alice['id'], '100.00');
        $addToCart((int) $alice['id'], $product, 2);
        (new OrderService())->placeFromCart((int) $alice['id'], $input());

        $addToCart((int) $bob['id'], $product, 1);
        (new OrderService())->placeFromCart((int) $bob['id'], $input(['payment_method' => 'gcash']));

        $global = Order::normaliseFilters([]);
        assert_true(Order::countAdmin($global) >= 2);

        $aliceOrders = Order::listForUser((int) $alice['id'], $global, new Paginator(1, 5, 1));
        $bobOrders   = Order::listForUser((int) $bob['id'], $global, new Paginator(1, 5, 1));
        assert_count(1, $aliceOrders);
        assert_count(1, $bobOrders);
        assert_same((int) $alice['id'], (int) $aliceOrders[0]['user_id']);
        assert_same((int) $bob['id'], (int) $bobOrders[0]['user_id']);

        $rows = Order::adminList($global, new Paginator(Order::countAdmin($global), 50, 1));
        $userIds = array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
        assert_true(in_array((int) $alice['id'], $userIds, true));
        assert_true(in_array((int) $bob['id'], $userIds, true));

        $stats = Order::stats();
        assert_true($stats['total'] >= 2);
        assert_true($stats['revenue'] >= 100.0, 'the paid order is part of revenue');

        $paidOnly = Order::adminList(
            Order::normaliseFilters(['status' => 'paid']),
            new Paginator(Order::countAdmin(Order::normaliseFilters(['status' => 'paid'])), 50, 1)
        );
        assert_count(1, $paidOnly);
        assert_same((int) $alice['id'], (int) $paidOnly[0]['user_id']);
    },

    /* ---------- P6-T11 / P6-T12: cancel and events ---------------- */

    'P6-T11 cancelling an unpaid order releases stock and logs the transition' => function () use ($makeProduct, $makeMember, $addToCart, $input): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '20.00', 'stock' => '5']);

        $addToCart($userId, $product, 2);
        $orderId = (new OrderService())->placeFromCart($userId, $input(['payment_method' => 'gcash']))['order_id'];

        assert_same(3, StockService::available($product), 'two units are reserved');

        $result = (new OrderService())->cancelByMember($userId, $orderId, 'Found a better deal');
        assert_same('cancelled', $result['status']);

        $order = Order::find($orderId);
        assert_same('cancelled', $order['status']);
        assert_not_null($order['cancelled_at']);
        assert_same('Found a better deal', $order['cancelled_reason']);

        $items = Order::items($orderId);
        assert_same('released', $items[0]['reservation_state']);
        assert_same(5, StockService::available($product), 'the reservation is gone');

        $events = Order::events($orderId);
        assert_count(2, $events);
        $last = $events[1];
        assert_same('member_cancelled', $last['reason_code']);
        assert_same('pending', $last['from_status']);
        assert_same('cancelled', $last['to_status']);
        assert_same('member', $last['actor_type']);
        assert_same($userId, (int) $last['actor_id']);

        /* A second cancel is refused and changes nothing. */
        $again = assert_throws(
            static fn () => (new OrderService())->cancelByMember($userId, $orderId),
            OrderException::class
        );
        assert_same('not_cancellable', $again->reason);
    },

    'P6-T11 a paid order cannot be cancelled online' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '20.00', 'stock' => '5']);

        $fund($userId, '50.00');
        $addToCart($userId, $product, 1);
        $orderId = (new OrderService())->placeFromCart($userId, $input())['order_id'];

        $error = assert_throws(
            static fn () => (new OrderService())->cancelByMember($userId, $orderId),
            OrderException::class
        );

        assert_same('not_cancellable', $error->reason);
        assert_same('paid', Order::find($orderId)['status']);
        assert_same('committed', Order::items($orderId)[0]['reservation_state']);
    },

    'P6-T12 every transition writes one event with actor and timestamp' => function () use ($makeProduct, $makeMember, $fund, $addToCart, $input): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '15.00', 'stock' => '5']);

        $fund($userId, '50.00');
        $addToCart($userId, $product, 1);
        $paid = (new OrderService())->placeFromCart($userId, $input())['order_id'];

        $events = Order::events($paid);
        assert_count(2, $events);
        foreach ($events as $event) {
            assert_not_null($event['created_at']);
            assert_true(in_array($event['actor_type'], ['member', 'admin', 'system'], true));
            assert_true((string) $event['to_status'] !== '');
        }
        assert_null($events[0]['from_status']);
        assert_same('pending', $events[0]['to_status']);
        assert_same('paid', $events[1]['to_status']);

        $addToCart($userId, $product, 1);
        $manual = (new OrderService())->placeFromCart($userId, $input(['payment_method' => 'bank']))['order_id'];
        (new OrderService())->cancelByMember($userId, $manual, 'Test cancel');

        $cancelled = Order::events($manual);
        assert_count(2, $cancelled);
        assert_same('cancelled', end($cancelled)['to_status']);
    },

    /* ---------- P6-T13: order number ------------------------------ */

    'P6-T13 the order number is EW + yymmdd + id and is unique' => function () use ($makeProduct, $makeMember, $addToCart, $input): void {
        assert_same('EW26110500042', OrderNumber::make(42, '2026-11-05 10:00:00'));
        assert_true(OrderNumber::isValid('EW26110500042'));
        assert_false(OrderNumber::isValid('EW26110542'));
        assert_false(OrderNumber::isValid('XX26110500042'));

        $member  = $makeMember();
        $product = $makeProduct(['stock' => '10', 'price' => '5.00']);
        $numbers = [];

        for ($i = 0; $i < 2; $i++) {
            $addToCart((int) $member['id'], $product, 1);
            $orderId = (new OrderService())->placeFromCart((int) $member['id'], $input(['payment_method' => 'gcash']))['order_id'];
            $order   = Order::find($orderId);

            assert_true(OrderNumber::isValid((string) $order['order_no']), 'invalid order number: ' . $order['order_no']);
            assert_same(
                'EW' . date('ymd', (int) strtotime((string) $order['created_at'])) . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT),
                $order['order_no']
            );

            $numbers[] = $order['order_no'];
        }

        assert_same(2, count(array_unique($numbers)));
    },

    /* ---------- P6-T14: concurrency ------------------------------- */

    'P6-T14 two checkouts for the last unit create at most one order' => function () use ($makeProduct, $fund, $input): void {
        $pdo = db();

        /* The second connection only sees committed rows. */
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $alice = test_user('ord_race_a_' . bin2hex(random_bytes(3)));
        $bob   = test_user('ord_race_b_' . bin2hex(random_bytes(3)));
        $product = Product::save([
            'name'   => 'Last Unit Order',
            'price'  => '20.00',
            'stock'  => '1',
            'status' => 'active',
        ]);

        $fund((int) $alice['id'], '50.00');
        $fund((int) $bob['id'], '50.00');
        (new CartService())->add((int) $alice['id'], $product, 1);
        (new CartService())->add((int) $bob['id'], $product, 1);

        $connectionA = test_pdo(['db' => DB_NAME]);
        $connectionB = test_pdo(['db' => DB_NAME]);
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $serviceA = new OrderService($connectionA);
            $serviceB = new OrderService($connectionB);

            $connectionA->beginTransaction();
            $orderA = $serviceA->placeFromCart((int) $alice['id'], $input());
            assert_same('paid', $orderA['status']);

            /* B waits on the product row A locked and times out. */
            $blocked = assert_throws(
                static fn () => $serviceB->placeFromCart((int) $bob['id'], $input()),
                PDOException::class
            );
            assert_contains('1205', (string) $blocked->getCode() . ' ' . $blocked->getMessage());

            $connectionA->commit();

            /* Once A lands, B sees no available unit and is refused. */
            $error = assert_throws(
                static fn () => (new OrderService())->placeFromCart((int) $bob['id'], $input()),
                OrderException::class
            );
            assert_same('stock', $error->reason);

            $orderCount = (int) $pdo->query('SELECT COUNT(*) FROM shop_orders WHERE user_id IN ('
                . (int) $alice['id'] . ',' . (int) $bob['id'] . ')')->fetchColumn();
            assert_same(1, $orderCount, 'exactly one order for the last unit');
            assert_same(0, StockService::available($product), 'the unit is held, never oversold');

            $bobCart = Cart::active((int) $bob['id']);
            assert_not_null($bobCart, 'the loser keeps their cart');
            assert_same(1, Cart::totals((int) $bobCart['id'])['quantity']);
        } finally {
            $pdo->exec('DELETE FROM shop_orders WHERE user_id IN (' . (int) $alice['id'] . ',' . (int) $bob['id'] . ')');
            $pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([(int) $alice['id'], (int) $bob['id']]);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$product]);
        }
    },

    /* ----------------------------------------------------------
     *  Phase 17C — the placement snapshot (P17C-T34)
     * ---------------------------------------------------------- */

    'P17C-T34 placement snapshots the bonus base and a later price edit cannot rewrite it' => function () use ($makeRepeatProduct, $makeChain, $addToCart, $input): void {
        setting_set('unilevel_enabled', '1');
        setting_set('unilevel_max_levels', '10');
        setting_set('unilevel_requires_active_upline', '1');

        $chain   = $makeChain('p17c_t34');
        $buyerId = (int) $chain['buyer']['id'];
        $product = $makeRepeatProduct();

        $addToCart($buyerId, $product, 2);
        $result = (new OrderService())->placeFromCart($buyerId, $input(['payment_method' => 'gcash']));
        $orderId = $result['order_id'];

        $item = db()->query('SELECT * FROM shop_order_items WHERE order_id = ' . $orderId)->fetch();

        assert_same('250.00', number_format((float) $item['unit_base'], 2, '.', ''), 'the unit base is the P.V.');
        assert_same('500.00', number_format((float) $item['base_total'], 2, '.', ''), 'the line base is × quantity');

        /* The admin edits the product before the proof is verified. */
        db()->prepare('UPDATE products SET price = 999.00, pv = 888.00 WHERE id = ?')->execute([$product]);

        $tmp = (string) tempnam(sys_get_temp_dir(), 'p17c');
        file_put_contents($tmp, TEST_PNG_BYTES);

        $upload = [
            'name' => 'proof.png', 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => strlen(TEST_PNG_BYTES),
        ];

        (new PaymentService())->submitProof($buyerId, $orderId, $upload, [
            'amount_sent' => '600.00', 'reference_no' => 'P17C-T34-REF-' . bin2hex(random_bytes(3)),
            'transfer_date' => date('Y-m-d'),
        ]);

        (new PaymentService())->verify((int) Member::primaryAdmin()['id'], $orderId, [
            'amount_received' => '600.00', 'payment_reference' => 'P17C-T34-PAY-1',
        ]);

        $rows = UnilevelAward::forOrder($orderId);

        assert_count(4, $rows);
        assert_same(500.0, (float) $rows[0]['base_amount'], 'the award keeps the placement base');
        assert_same('line', $rows[0]['base_source']);
        assert_same(90.0, round(array_sum(array_column($rows, 'amount')), 2));
    },
];
