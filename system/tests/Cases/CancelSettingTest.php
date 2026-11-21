<?php
declare(strict_types=1);

/* ==============================================================
 *  CancelSettingTest — the order-cancellation kill switch
 *
 *  `shop_cancel_enabled` (Settings → Shop → **Enable Cancel of Order**)
 *  ships **off**. While it is off, neither a member nor an administrator
 *  may cancel an order, so no wallet refund and no repeat-purchase
 *  escrow reversal happens in normal operation. The operator turns it on
 *  only for a special case.
 *
 *  The cases prove:
 *
 *    · the seed/default and the model rules;
 *    · the service refusals (member and admin) with the status guard
 *      still running first;
 *    · the escrow contract when the switch is off (a pending award stays
 *      pending and releases normally) and when it is on (the plan §8
 *      cancellation rule still cancels pending awards);
 *    · automatic expiry keeps its own switch and still closes an unpaid
 *      order;
 *    · both order pages replace the form with an explanation, and the
 *      admin settings card holds the switch.
 *
 *  Every case runs inside the harness transaction and rolls back.
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Cancel Switch ' . bin2hex(random_bytes(4)),
        'sku'    => 'CXL-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeRepeatProduct = static function (): int {
    return Product::save([
        'name'             => 'Cancel Escrow ' . bin2hex(random_bytes(3)),
        'sku'              => 'CXL-ESC-' . strtoupper(bin2hex(random_bytes(3))),
        'price'            => '300.00',
        'pv'               => '250.00',
        'unilevel_enabled' => '1',
        'unilevel_base'    => 'auto',
        'stock'            => '10',
        'status'           => 'active',
    ], null, ProductUnilevel::referencePreset());
};

$makeMember = static function (string $prefix = 'cxl'): array {
    return test_user($prefix . '_' . bin2hex(random_bytes(4)));
};

/** buyer → L1 → L2 */
$makeChain = static function (string $prefix = 'cxl') use ($makeMember): array {
    $buyer  = $makeMember($prefix . '_buyer');
    $levels = [];
    $child  = $buyer;

    for ($level = 1; $level <= 2; $level++) {
        $upline = $makeMember($prefix . '_u' . $level);

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $upline['id'], (int) $child['id']]);

        $levels[$level] = $upline;
        $child          = $upline;
    }

    return ['buyer' => $buyer, 'levels' => $levels];
};

$checkout = static function (array $overrides = []): array {
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
        'payment_method'    => 'gcash',
        'idempotency_key'   => bin2hex(random_bytes(16)),
        'terms'             => '1',
    ];
};

$placeOrder = static function (int $userId, int $productId, string $method = 'gcash') use ($checkout): int {
    (new CartService())->add($userId, $productId, 1);

    return (int) (new OrderService())->placeFromCart(
        $userId,
        $checkout(['payment_method' => $method])
    )['order_id'];
};

$enableEscrow = static function (): void {
    foreach ([
        'unilevel_enabled'                => '1',
        'unilevel_max_levels'             => '10',
        'unilevel_requires_active_upline' => '0',
        'unilevel_active_threshold'       => '500',
        'unilevel_hold_days'              => '0',
        'unilevel_release_enabled'        => '1',
        'unilevel_reversal_enabled'       => '1',
    ] as $key => $value) {
        setting_set($key, $value);
    }

    setting_cache_clear();
};

$renderMemberOrder = static function (int $orderId): string {
    $order = Order::find($orderId);
    assert_not_null($order);

    $context = (new OrderController())->orderContext($order);

    ob_start();

    try {
        View::page('member', 'shop/order', $context + [
            'page_title'  => 'Order ' . (string) $order['order_no'],
            'current_nav' => 'orders',
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

return [
    /* ---------- The shipped default --------------------------------- */

    'the cancellation switch ships off and gates both rules' => function () use ($appRoot): void {
        assert_same('0', setting('shop_cancel_enabled'), 'the seed ships the switch off');
        assert_false(Order::cancelEnabled());

        $pending = ['status' => 'pending'];
        $paid    = ['status' => 'paid'];

        assert_true(Order::isMemberCancellableStatus($pending), 'the status itself is still cancellable');
        assert_true(Order::isAdminCancellableStatus($paid), 'the status itself is still cancellable');
        assert_false(Order::canMemberCancel($pending));
        assert_false(Order::canAdminCancel($paid));
        assert_false(Order::isRefundable($paid), 'no refund copy while cancellation is closed');

        assert_true(
            is_file($appRoot . '/database/migrations/022_shop_cancel_toggle.sql'),
            'migration 022 carries the setting for upgraded databases'
        );
    },

    'the shop section owns the switch, its rule and its exact label' => function (): void {
        $rulesMethod = new ReflectionMethod(AdminSettingsController::class, 'sectionRules');
        $labelsMethod = new ReflectionMethod(AdminSettingsController::class, 'sectionLabels');
        $togglesMethod = new ReflectionMethod(AdminSettingsController::class, 'sectionToggles');
        $rulesMethod->setAccessible(true);
        $labelsMethod->setAccessible(true);
        $togglesMethod->setAccessible(true);

        assert_same('required|in:0,1', $rulesMethod->invoke(null, 'shop')['shop_cancel_enabled'] ?? '');
        assert_same('Enable Cancel of Order', $labelsMethod->invoke(null, 'shop')['shop_cancel_enabled'] ?? '');
        assert_same(
            ['shop_enabled', 'shop_cancel_enabled'],
            $togglesMethod->invoke(null, 'shop')
        );

        $controller = new AdminSettingsController();
        assert_false($controller->settingsContext()['cancel_enabled']);

        test_enable_order_cancel();
        assert_true($controller->settingsContext()['cancel_enabled']);
    },

    /* ---------- The member path ------------------------------------- */

    'a member cancellation is refused while the switch is off and works after it is on' => function () use ($makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);

        $orderId = $placeOrder($userId, $product, 'gcash');
        $order   = Order::find($orderId);

        assert_not_null($order);
        assert_same('pending', $order['status']);
        assert_same(4, StockService::available($product), 'one unit is reserved');
        assert_false(Order::canMemberCancel($order));

        $error = assert_throws(
            static fn () => (new OrderService())->cancelByMember($userId, $orderId, 'Changed my mind'),
            OrderException::class
        );
        assert_same('cancel_disabled', $error->reason);
        assert_contains('turned off', $error->getMessage());

        assert_same('pending', (string) Order::find($orderId)['status'], 'the order is untouched');
        assert_same('reserved', (string) Order::items($orderId)[0]['reservation_state']);
        assert_same(4, StockService::available($product), 'the reservation is still held');

        /* The status guard still wins over the policy guard. */
        db()->prepare("UPDATE shop_orders SET status = 'payment_review' WHERE id = ?")->execute([$orderId]);
        $statusError = assert_throws(
            static fn () => (new OrderService())->cancelByMember($userId, $orderId),
            OrderException::class
        );
        assert_same('not_cancellable', $statusError->reason);

        db()->prepare("UPDATE shop_orders SET status = 'pending' WHERE id = ?")->execute([$orderId]);

        test_enable_order_cancel();
        assert_true(Order::canMemberCancel(Order::find($orderId)));

        $result = (new OrderService())->cancelByMember($userId, $orderId, 'Changed my mind');
        assert_same('cancelled', $result['status']);
        assert_same('cancelled', (string) Order::find($orderId)['status']);
        assert_same('released', (string) Order::items($orderId)[0]['reservation_state']);
        assert_same(5, StockService::available($product), 'the reservation is released');
    },

    /* ---------- The admin + escrow path ----------------------------- */

    'an admin cancellation is refused while the switch is off and the escrow stays pending' => function () use ($makeChain, $makeRepeatProduct, $placeOrder, $enableEscrow): void {
        $enableEscrow();
        $chain   = $makeChain();
        $buyerId = (int) $chain['buyer']['id'];
        $product = $makeRepeatProduct();

        (new WalletService())->credit($buyerId, '1000.00', 'commission', random_int(1000000, 9999999), 'Test funds');
        $orderId = $placeOrder($buyerId, $product, 'ewallet');
        $order   = Order::find($orderId);

        assert_not_null($order);
        assert_same('paid', $order['status']);

        $awards = UnilevelAward::forOrder($orderId);
        assert_count(2, $awards);
        foreach ($awards as $award) {
            assert_same('pending', $award['status']);
        }
        assert_same(25.0, (float) $order['unilevel_total']);
        assert_same(700.0, (new WalletService())->balance($buyerId));

        assert_false(Order::canAdminCancel($order));
        assert_false(Order::isRefundable($order));

        $admin = test_user('cxl_admin', 'admin');
        $error = assert_throws(
            static fn () => (new PaymentService())->cancelByAdmin((int) $admin['id'], $orderId, 'admin_cancel'),
            PaymentException::class
        );
        assert_same('cancel_disabled', $error->reason);
        assert_contains('Enable Cancel of Order', $error->getMessage());

        assert_same('paid', (string) Order::find($orderId)['status'], 'the order is untouched');
        assert_same(700.0, (new WalletService())->balance($buyerId), 'nothing was refunded');

        $count = (int) db()->query(
            "SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'refund' AND ref_id = " . $orderId
        )->fetchColumn();
        assert_same(0, $count, 'no refund ledger row');

        foreach (UnilevelAward::forOrder($orderId) as $award) {
            assert_same('pending', $award['status'], 'the escrow is untouched');
        }

        /* The escrow keeps working while cancellation is closed: delivery
         * plus the zero hold releases the pending awards to the uplines. */
        test_deliver_order($orderId, date('Y-m-d H:i:s'));
        $release = (new UnilevelService())->releaseForOrder($orderId);
        assert_same(2, $release['released']);
        assert_same(12.5, (new WalletService())->withdrawable((int) $chain['levels'][1]['id']));
        assert_same(12.5, (new WalletService())->withdrawable((int) $chain['levels'][2]['id']));
    },

    'with the switch on an admin cancellation still cancels the pending escrow awards' => function () use ($makeChain, $makeRepeatProduct, $placeOrder, $enableEscrow): void {
        test_enable_order_cancel();
        $enableEscrow();

        $chain   = $makeChain();
        $buyerId = (int) $chain['buyer']['id'];
        $product = $makeRepeatProduct();

        (new WalletService())->credit($buyerId, '1000.00', 'commission', random_int(1000000, 9999999), 'Test funds');
        $orderId = $placeOrder($buyerId, $product, 'ewallet');
        assert_same(25.0, (float) Order::find($orderId)['unilevel_total']);

        $admin  = test_user('cxl_admin', 'admin');
        $result = (new PaymentService())->cancelByAdmin((int) $admin['id'], $orderId, 'admin_cancel', 'Special case');

        assert_same('cancelled', $result['status']);
        assert_same(300.0, $result['refunded']);
        assert_same(1000.0, (new WalletService())->balance($buyerId), 'the wallet is refunded');

        foreach (UnilevelAward::forOrder($orderId) as $award) {
            assert_same('cancelled', $award['status'], 'pending awards are cancelled before the reversal switch is read');
            assert_same(0.0, (float) $award['reversed_amount']);
        }

        assert_same('0.00', number_format((float) Order::find($orderId)['unilevel_total'], 2, '.', ''));
    },

    /* ---------- Expiry keeps its own switch ------------------------- */

    'automatic expiry still closes an unpaid order while cancellation is off' => function () use ($makeMember, $makeProduct): void {
        $member  = $makeMember();
        $product = $makeProduct(['stock' => '4']);
        $orderId = test_order((int) $member['id'], [['product_id' => $product]], 'pending', [
            'payment_method'   => 'gcash',
            'payment_deadline' => date('Y-m-d H:i:s', time() - 3600),
            'subtotal'         => '25.00',
            'total'            => '25.00',
        ]);

        assert_false(Order::cancelEnabled());
        assert_true(setting_bool('shop_expiry_enabled', true));

        $result = (new PaymentService())->expireOverdue();

        assert_true(in_array($orderId, $result['ids'], true), 'the expiry sweep is independent of the cancel switch');
        assert_same('cancelled', (string) Order::find($orderId)['status']);
        assert_same('released', (string) Order::items($orderId)[0]['reservation_state']);
        assert_same(4, StockService::available($product));

        $events = Order::events($orderId);
        assert_same('payment_expired', end($events)['reason_code']);
    },

    /* ---------- The two order pages --------------------------------- */

    'the member order page replaces the cancel form with an explanation while the switch is off' => function () use ($makeProduct, $makeMember, $placeOrder, $renderMemberOrder): void {
        $member  = $makeMember();
        $product = $makeProduct();

        $orderId = $placeOrder((int) $member['id'], $product, 'gcash');
        test_signed_in_as($member);

        $html = $renderMemberOrder($orderId);

        assert_not_contains(url('orders/' . $orderId . '/cancel'), $html);
        assert_not_contains('data-confirm-title="Cancel this order?"', $html);
        assert_contains('Order cancellation is turned off', $html);

        test_enable_order_cancel();

        $html = $renderMemberOrder($orderId);

        assert_contains(url('orders/' . $orderId . '/cancel'), $html);
        assert_contains('data-confirm-title="Cancel this order?"', $html);
        assert_contains('Cancel order', $html);
        assert_not_contains('Order cancellation is turned off', $html);
    },

    'the admin order card explains the switch instead of offering the form' => function () use ($makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $orderId = $placeOrder((int) $member['id'], $product, 'gcash');

        $admin = test_user('cxl_admin', 'admin');
        test_signed_in_as($admin);

        $controller = new AdminOrderController();
        $context    = $controller->orderContext(Order::find($orderId));
        $fragment   = $controller->detailFeedPayload(1, '"x"', $context)['fragments']['order-cancel'];

        assert_false((bool) $context['can_cancel']);
        assert_contains('Order cancellation is turned off', $fragment);
        assert_contains(url('admin/settings') . '#settings-shop', $fragment);
        assert_not_contains('name="to" value="cancelled"', $fragment);

        test_enable_order_cancel();

        $context  = $controller->orderContext(Order::find($orderId));
        $fragment = $controller->detailFeedPayload(2, '"y"', $context)['fragments']['order-cancel'];

        assert_true((bool) $context['can_cancel']);
        assert_contains('name="to" value="cancelled"', $fragment);
        assert_contains('Cancel order', $fragment);
        assert_not_contains('Order cancellation is turned off', $fragment);
    },
];
