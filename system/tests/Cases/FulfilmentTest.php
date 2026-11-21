<?php
declare(strict_types=1);

/* ==============================================================
 *  FulfilmentTest — pack, ship, deliver, complete, auto-complete
 *  (plan §5.5, §4.4, §7 Phase 8; tests P8-T1 … P8-T4, P8-T8, P8-T9)
 *
 *  Every case runs inside the harness transaction and rolls back, so it
 *  may create members, products and paid orders freely.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Fulfilment Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'FUL-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('ful_' . bin2hex(random_bytes(4)));
};

$checkout = static function (array $overrides = []): array {
    return $overrides + [
        'billing_name'     => 'Juan Dela Cruz',
        'billing_phone'    => '0917 000 0000',
        'shipping_name'    => 'Juan Dela Cruz',
        'shipping_address' => '12 Mabini Street',
        'shipping_city'    => 'Makati',
        'payment_method'   => 'ewallet',
        'idempotency_key'  => bin2hex(random_bytes(16)),
        'terms'            => '1',
    ];
};

/** Fund the wallet and place a paid (e-wallet) order. */
$paidOrder = static function (array $member, int $productId, int $quantity = 1) use ($checkout): array {
    $userId = (int) $member['id'];

    (new WalletService())->credit(
        $userId,
        '500.00',
        'commission',
        random_int(1000000, 9999999),
        'Test funds'
    );
    (new CartService())->add($userId, $productId, $quantity);

    $result = (new OrderService())->placeFromCart($userId, $checkout());

    return Order::find($result['order_id']);
};

/** Walk a paid order to a chosen status. */
$toStatus = static function (int $adminId, int $orderId, string $target): void {
    $service = new FulfilmentService();

    if ($target === 'packing' || $target === 'ready_to_ship' || $target === 'shipped'
        || $target === 'delivered') {
        if ($target === 'packing') {
            $service->pack($adminId, $orderId);
        }

        if ($target === 'ready_to_ship') {
            $service->pack($adminId, $orderId);
            $service->ready($adminId, $orderId);
        }

        if ($target === 'shipped') {
            $service->pack($adminId, $orderId);
            $service->ready($adminId, $orderId);
            $service->ship($adminId, $orderId, [
                'courier'         => 'J&T Express',
                'tracking_number' => 'JT' . strtoupper(bin2hex(random_bytes(4))),
            ]);
        }

        if ($target === 'delivered') {
            $service->pack($adminId, $orderId);
            $service->ready($adminId, $orderId);
            $service->ship($adminId, $orderId, [
                'courier'         => 'LBC Express',
                'tracking_number' => 'LBC' . strtoupper(bin2hex(random_bytes(4))),
            ]);
            $service->deliver($adminId, $orderId);
        }
    }
};

$statusOf = static function (int $orderId): string {
    return (string) (Order::find($orderId)['status'] ?? '');
};

/** Run a case with temporary settings, restoring them afterwards. */
$withSettings = static function (array $values, callable $case): void {
    $before = [];

    foreach ($values as $key => $value) {
        $before[$key] = (string) setting($key, '');
        setting_set($key, (string) $value);
    }

    setting_cache_clear();

    try {
        $case();
    } finally {
        foreach ($before as $key => $value) {
            setting_set($key, $value);
        }

        setting_cache_clear();
    }
};

return [
    /* ---------- P8-T1: the sequential status walk ------------------ */

    'P8-T1 pack → ready → ship moves the statuses and saves the tracking' => function () use ($makeProduct, $makeMember, $paidOrder, $statusOf): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '5']);
        $order   = $paidOrder($member, $product, 2);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');

        $service = new FulfilmentService();

        assert_same('paid', $statusOf($orderId));

        $packed = $service->pack((int) $admin['id'], $orderId, 'Two boxes');
        assert_same('packing', $packed['status']);
        assert_same('packing', $statusOf($orderId));

        $afterPack = Order::find($orderId);
        assert_not_null($afterPack['packed_at']);
        assert_null($afterPack['shipped_at']);

        $ready = $service->ready((int) $admin['id'], $orderId);
        assert_same('ready_to_ship', $ready['status']);
        assert_same('ready_to_ship', $statusOf($orderId));

        $shipped = $service->ship((int) $admin['id'], $orderId, [
            'courier'         => 'J&T Express',
            'tracking_number' => 'JT1234567890',
            'notes'           => 'Fragile — keep upright',
        ]);

        assert_same('shipped', $shipped['status']);
        assert_same('J&T Express', $shipped['courier']);
        assert_same('JT1234567890', $shipped['tracking_number']);
        assert_true($shipped['shipment_id'] > 0);

        $shippedOrder = Order::find($orderId);
        assert_same('shipped', $shippedOrder['status']);
        assert_not_null($shippedOrder['shipped_at']);

        $shipment = Shipment::latestFor($orderId);
        assert_not_null($shipment);
        assert_same('J&T Express', $shipment['courier']);
        assert_same('JT1234567890', $shipment['tracking_number']);
        assert_same('Fragile — keep upright', $shipment['notes']);
        assert_null($shipment['delivered_at']);

        $events = Order::events($orderId);
        $codes  = array_map(static fn (array $event): string => (string) $event['reason_code'], $events);
        assert_same(
            ['order_placed', 'paid', 'packing_started', 'ready_to_ship', 'shipped'],
            $codes
        );
        $last = end($events);
        assert_same('ready_to_ship', $last['from_status']);
        assert_same('shipped', $last['to_status']);
        assert_same('admin', $last['actor_type']);
        assert_same((int) $admin['id'], (int) $last['actor_id']);
        assert_contains('JT1234567890', (string) $last['note']);
    },

    'P8-T1 a fulfilment step cannot be skipped, repeated or reversed' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '5']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];
        $service = new FulfilmentService();

        /* ready before packing */
        $error = assert_throws(
            static fn () => $service->ready($adminId, $orderId),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);
        assert_same('paid', Order::find($orderId)['status']);

        /* ship before packing */
        $error = assert_throws(
            static fn () => $service->ship($adminId, $orderId, [
                'courier'         => 'J&T Express',
                'tracking_number' => 'JT-1',
            ]),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);

        /* deliver before shipping */
        $error = assert_throws(
            static fn () => $service->deliver($adminId, $orderId),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);

        $service->pack($adminId, $orderId);

        /* packing cannot be packed again */
        $error = assert_throws(
            static fn () => $service->pack($adminId, $orderId),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);

        /* or shipped from packing */
        $error = assert_throws(
            static fn () => $service->ship($adminId, $orderId, [
                'courier'         => 'J&T Express',
                'tracking_number' => 'JT-2',
            ]),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);
        assert_same('packing', Order::find($orderId)['status']);
        assert_same([], Shipment::forOrder($orderId), 'a skipped step creates no shipment');

        /* A pending order is not packable at all. */
        db()->prepare("UPDATE shop_orders SET status = 'pending' WHERE id = ?")->execute([$orderId]);
        $error = assert_throws(
            static fn () => $service->pack($adminId, $orderId),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);
    },

    /* ---------- P8-T2: courier + tracking -------------------------- */

    'P8-T2 shipping without a courier or tracking is refused' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '5']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];

        $toStatus($adminId, $orderId, 'ready_to_ship');
        $service = new FulfilmentService();

        foreach ([
            ['courier' => '', 'tracking_number' => 'JT123'],
            ['courier' => 'J&T Express', 'tracking_number' => ''],
            ['courier' => 'J', 'tracking_number' => 'JT123'],
            ['courier' => 'J&T Express', 'tracking_number' => '!!!'],
        ] as $input) {
            $error = assert_throws(
                static fn () => $service->ship($adminId, $orderId, $input),
                FulfilmentException::class
            );
            assert_same('validation', $error->reason);
            assert_true($error->fieldErrors !== []);
        }

        /* Nothing was written and the stock is untouched. */
        assert_same('ready_to_ship', Order::find($orderId)['status']);
        assert_same([], Shipment::forOrder($orderId));
        assert_same(5, (int) db()->query('SELECT stock FROM products WHERE id = ' . $product)->fetchColumn());

        $errors = FulfilmentService::validateShipment([]);
        assert_true(isset($errors['courier']));
        assert_true(isset($errors['tracking_number']));
    },

    'P8-T2 the courier picker resolves a listed courier or a typed "Others" name' => function (): void {
        /* The select's own list, without the "type it yourself" entry. */
        $choices = FulfilmentService::courierChoices();

        assert_same('Others', FulfilmentService::OTHER_COURIER);
        assert_true(in_array('J&T Express', $choices, true));
        assert_false(in_array('Others', $choices, true), 'the sentinel is rendered separately');

        /* A listed courier wins; the free-text field is ignored. */
        $listed = FulfilmentService::resolveCourier([
            'courier'       => 'J&T Express',
            'courier_other' => 'ignored text',
        ]);
        assert_same('J&T Express', $listed['value']);
        assert_same([], $listed['errors']);

        /* "Others" uses the typed name, trimmed. */
        $typed = FulfilmentService::resolveCourier([
            'courier'       => 'Others',
            'courier_other' => '  Flash Express  ',
        ]);
        assert_same('Flash Express', $typed['value']);
        assert_same([], $typed['errors']);

        /* "Others" without a name, or with an unusable one, is refused. */
        $blank = FulfilmentService::resolveCourier(['courier' => 'Others', 'courier_other' => '   ']);
        assert_same('', $blank['value']);
        assert_same('Enter the courier name.', $blank['errors']['courier_other'] ?? '');

        $short = FulfilmentService::resolveCourier(['courier' => 'Others', 'courier_other' => 'X']);
        assert_contains('at least 2', $short['errors']['courier_other'] ?? '');

        $long = FulfilmentService::resolveCourier([
            'courier'       => 'Others',
            'courier_other' => str_repeat('a', 41),
        ]);
        assert_contains('at most 40', $long['errors']['courier_other'] ?? '');

        /* A missing or tampered select value never reaches the shipment. */
        $empty = FulfilmentService::resolveCourier(['courier' => '']);
        assert_same('Choose a courier.', $empty['errors']['courier'] ?? '');
        assert_same('', $empty['value']);

        $tampered = FulfilmentService::resolveCourier(['courier' => 'Free Courier Ltd']);
        assert_same('Choose a courier from the list.', $tampered['errors']['courier'] ?? '');
        assert_same('', $tampered['value']);
    },

    /* ---------- P8-T3: stock deduction at ship --------------------- */

    'P8-T3 shipping deducts the physical stock exactly once' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '10']);
        $order   = $paidOrder($member, $product, 2);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];

        $toStatus($adminId, $orderId, 'ready_to_ship');

        /* Before shipping: 10 on hand, 2 held → 8 available. */
        assert_same(10, (int) db()->query('SELECT stock FROM products WHERE id = ' . $product)->fetchColumn());
        assert_same(8, StockService::available($product));

        (new FulfilmentService())->ship($adminId, $orderId, [
            'courier'         => 'Ninja Van',
            'tracking_number' => 'NV-000001',
        ]);

        /* After shipping: 8 on hand, nothing held → still 8 available. */
        assert_same(8, (int) db()->query('SELECT stock FROM products WHERE id = ' . $product)->fetchColumn());
        assert_same(8, StockService::available($product), 'availability is continuous across the shipment');
        assert_same('deducted', Order::items($orderId)[0]['reservation_state']);

        /* A second ship attempt cannot deduct again. */
        $error = assert_throws(
            static fn () => (new FulfilmentService())->ship($adminId, $orderId, [
                'courier'         => 'Ninja Van',
                'tracking_number' => 'NV-000002',
            ]),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);
        assert_same(8, (int) db()->query('SELECT stock FROM products WHERE id = ' . $product)->fetchColumn());
        assert_same(1, count(Shipment::forOrder($orderId)));
    },

    'P8-T3 shipping refuses when the physical stock went short' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus): void {
        $member  = $makeMember();
        $product = $makeProduct(['name' => 'Short Stock Tea', 'stock' => '3']);
        $order   = $paidOrder($member, $product, 2);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];

        $toStatus($adminId, $orderId, 'ready_to_ship');

        /* The admin corrected the count down after the sale. */
        db()->prepare('UPDATE products SET stock = 1 WHERE id = ?')->execute([$product]);

        $error = assert_throws(
            static fn () => (new FulfilmentService())->ship($adminId, $orderId, [
                'courier'         => 'LBC Express',
                'tracking_number' => 'LBC-77',
            ]),
            FulfilmentException::class
        );

        assert_same('stock', $error->reason);
        assert_contains('Short Stock Tea', $error->getMessage());
        assert_same('ready_to_ship', Order::find($orderId)['status'], 'the order stays ready to ship');
        assert_same('committed', Order::items($orderId)[0]['reservation_state']);
        assert_same(1, (int) db()->query('SELECT stock FROM products WHERE id = ' . $product)->fetchColumn());
        assert_same([], Shipment::forOrder($orderId));
    },

    /* ---------- P8-T4: delivery and completion --------------------- */

    'P8-T4 deliver stamps the shipment and the member confirms the receipt' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '4']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];

        $toStatus($adminId, $orderId, 'shipped');

        $delivered = (new FulfilmentService())->deliver($adminId, $orderId, 'Received by the guard');

        assert_same('delivered', $delivered['status']);
        assert_not_null($delivered['shipment_id']);

        $order = Order::find($orderId);
        assert_same('delivered', $order['status']);
        assert_not_null($order['delivered_at']);

        $shipment = Shipment::latestFor($orderId);
        assert_not_null($shipment['delivered_at']);
        assert_contains('POD: Received by the guard', (string) $shipment['notes']);
        assert_same('J&T Express', (string) $shipment['courier']);

        assert_true(Order::canMemberConfirm($order));

        $confirmed = (new FulfilmentService())->confirmByMember((int) $member['id'], $orderId, 'All good');

        assert_same('completed', $confirmed['status']);

        $order = Order::find($orderId);
        assert_same('completed', $order['status']);
        assert_not_null($order['completed_at']);
        assert_false(Order::canMemberConfirm($order));

        $events = Order::events($orderId);
        $last   = end($events);
        assert_same('receipt_confirmed', $last['reason_code']);
        assert_same('delivered', $last['from_status']);
        assert_same('completed', $last['to_status']);
        assert_same('member', $last['actor_type']);
        assert_same((int) $member['id'], (int) $last['actor_id']);
    },

    'P8-T4 only the buyer can confirm, and only once the order is delivered' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus): void {
        $seller  = $makeMember();
        $buyer   = $makeMember();
        $product = $makeProduct(['stock' => '3']);
        $order   = $paidOrder($buyer, $product, 1);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];

        $service = new FulfilmentService();

        /* Not delivered yet: refused for the buyer too. */
        $error = assert_throws(
            static fn () => $service->confirmByMember((int) $buyer['id'], $orderId),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);

        $toStatus($adminId, $orderId, 'delivered');

        /* Another member's id resolves to "not found", never a leak. */
        $error = assert_throws(
            static fn () => $service->confirmByMember((int) $seller['id'], $orderId),
            FulfilmentException::class
        );
        assert_same('not_found', $error->reason);
        assert_same('delivered', Order::find($orderId)['status']);

        /* A second confirm after completion is refused. */
        $service->confirmByMember((int) $buyer['id'], $orderId);
        $error = assert_throws(
            static fn () => $service->confirmByMember((int) $buyer['id'], $orderId),
            FulfilmentException::class
        );
        assert_same('status', $error->reason);
    },

    /* ---------- P8-T8: no cancellation after shipment -------------- */

    'P8-T8 an order that has shipped can no longer be cancelled' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '3']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];

        $toStatus($adminId, $orderId, 'shipped');

        $error = assert_throws(
            static fn () => (new PaymentService())->cancelByAdmin($adminId, $orderId, 'customer_request', 'Too late'),
            PaymentException::class
        );

        assert_same('status', $error->reason);
        assert_same('shipped', Order::find($orderId)['status']);
        assert_false(Order::canAdminCancel(Order::find($orderId)));
    },

    /* ---------- P8-T9: auto-complete ------------------------------- */

    'P8-T9 the auto-complete sweep completes old deliveries and is idempotent' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '6']);
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];

        $oldOrder = $paidOrder($member, $product, 1);
        $oldId    = (int) $oldOrder['id'];
        $toStatus($adminId, $oldId, 'delivered');

        $freshOrder = $paidOrder($member, $product, 1);
        $freshId    = (int) $freshOrder['id'];
        $toStatus($adminId, $freshId, 'delivered');

        /* Backdate the first delivery beyond the window. */
        $days    = FulfilmentService::autoCompleteDays();
        $backdate = date('Y-m-d H:i:s', time() - ($days + 1) * 86400);
        db()->prepare('UPDATE shop_orders SET delivered_at = ? WHERE id = ?')->execute([$backdate, $oldId]);
        db()->prepare('UPDATE shop_shipments SET delivered_at = ? WHERE order_id = ?')->execute([$backdate, $oldId]);

        $service = new FulfilmentService();

        assert_true(in_array($oldId, $service->dueOrderIds(), true));
        assert_false(in_array($freshId, $service->dueOrderIds(), true));

        $result = $service->autoComplete();

        assert_same(1, $result['completed']);
        assert_same([$oldId], $result['ids']);
        assert_same($days, $result['days']);
        assert_same(null, $result['skipped']);

        $completed = Order::find($oldId);
        assert_same('completed', $completed['status']);
        assert_not_null($completed['completed_at']);

        $events = Order::events($oldId);
        $last   = end($events);
        assert_same('auto_completed', $last['reason_code']);
        assert_same('system', $last['actor_type']);
        assert_contains('after delivery', (string) $last['note']);

        assert_same('delivered', Order::find($freshId)['status'], 'a fresh delivery is untouched');

        /* Second run: nothing left to do. */
        $again = $service->autoComplete();
        assert_same(0, $again['completed']);
        assert_same([], $again['ids']);
        assert_same('completed', Order::find($oldId)['status']);
    },

    'P8-T9 the auto-complete kill switch and day count are respected' => function () use ($makeProduct, $makeMember, $paidOrder, $toStatus, $withSettings): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '3']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];
        $admin   = test_user('ful_admin', 'admin');
        $adminId = (int) $admin['id'];

        $toStatus($adminId, $orderId, 'delivered');

        $backdate = date('Y-m-d H:i:s', time() - 400 * 86400);
        db()->prepare('UPDATE shop_orders SET delivered_at = ? WHERE id = ?')->execute([$backdate, $orderId]);

        $service = new FulfilmentService();

        $withSettings(['shop_autocomplete_enabled' => '0'], function () use ($service, $orderId): void {
            assert_false(FulfilmentService::autoCompleteEnabled());
            assert_false(FulfilmentService::autoCompleteActive());
            assert_same([], $service->dueOrderIds());

            $result = $service->autoComplete();
            assert_same('disabled', $result['skipped']);
            assert_same(0, $result['completed']);
            assert_same('delivered', Order::find($orderId)['status'], 'nothing changes while paused');
        });

        $withSettings(['shop_autocomplete_enabled' => '1', 'shop_autocomplete_days' => '0'], function () use ($service, $orderId): void {
            assert_false(FulfilmentService::autoCompleteActive());
            assert_same('disabled', $service->autoComplete()['skipped']);
            assert_same('delivered', Order::find($orderId)['status']);
        });

        $withSettings(['shop_autocomplete_enabled' => '1', 'shop_autocomplete_days' => '365'], function () use ($service, $orderId): void {
            assert_same(365, FulfilmentService::autoCompleteDays());

            $result = $service->autoComplete();

            assert_same(1, $result['completed']);
            assert_same('completed', Order::find($orderId)['status']);
        });
    },
];
