<?php
declare(strict_types=1);

/* ==============================================================
 *  FulfilmentViewTest — member/admin fulfilment panels and the
 *  printable invoice (plan §5.5–5.7, §7 Phase 8; supports P8-T1 …
 *  P8-T10)
 *
 *  Renders the real templates with the data the controllers build and
 *  asserts what a browser would see.
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

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'View Product ' . bin2hex(random_bytes(3)),
        'sku'    => 'FV-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('fv_' . bin2hex(random_bytes(4)));
};

$checkout = static function (array $overrides = []): array {
    return $overrides + [
        'billing_name'     => 'Juan Dela Cruz',
        'shipping_name'    => 'Juan Dela Cruz',
        'shipping_address' => '12 Mabini Street',
        'shipping_city'    => 'Makati',
        'payment_method'   => 'ewallet',
        'idempotency_key'  => bin2hex(random_bytes(16)),
        'terms'            => '1',
    ];
};

$paidOrder = static function (array $member, int $productId, int $quantity = 1) use ($checkout): array {
    $userId = (int) $member['id'];

    (new WalletService())->credit($userId, '500.00', 'commission', random_int(1000000, 9999999), 'Test funds');
    (new CartService())->add($userId, $productId, $quantity);

    return Order::find((new OrderService())->placeFromCart($userId, $checkout())['order_id']);
};

/** Advance a paid order to the requested status. */
$advance = static function (int $orderId, string $target): void {
    $admin   = test_user('fv_admin', 'admin');
    $adminId = (int) $admin['id'];
    $service = new FulfilmentService();

    if ($target === 'packing') {
        $service->pack($adminId, $orderId);
    }

    if (in_array($target, ['ready_to_ship', 'shipped', 'delivered'], true)) {
        $service->pack($adminId, $orderId);
        $service->ready($adminId, $orderId);
    }

    if (in_array($target, ['shipped', 'delivered'], true)) {
        $service->ship($adminId, $orderId, [
            'courier'         => 'J&T Express',
            'tracking_number' => 'JT1234567890',
            'notes'           => 'Two boxes',
        ]);
    }

    if ($target === 'delivered') {
        $service->deliver($adminId, $orderId, 'Received by the guard');
    }
};

/** Render the member order page exactly as OrderController::show builds it. */
$memberOrderHtml = static function (int $orderId) use ($render): string {
    $order = Order::find($orderId);

    return $render('member', 'shop/order', [
        'page_title'  => 'Order ' . (string) $order['order_no'],
        'current_nav' => 'orders',
        'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
        'page_js'     => ['assets/js/app-confirm.js'],
        'order'        => $order,
        'lines'        => Order::items($orderId),
        'events'       => Order::events($orderId),
        'can_cancel'   => Order::canMemberCancel($order),
        'proofs'       => [],
        'latest_proof' => null,
        'window'       => (new PaymentService())->windowFor($order),
        'shipment'     => Shipment::latestFor($orderId),
        'invoice'      => Invoice::latestFor($orderId),
        'can_confirm'  => Order::canMemberConfirm($order),
        'auto_days'    => FulfilmentService::autoCompleteDays(),
    ]);
};

/** Render the admin order page exactly as AdminOrderController::show builds it. */
$adminOrderHtml = static function (int $orderId) use ($render): string {
    $order  = Order::find($orderId);
    $status = (string) $order['status'];
    $admin  = test_user('fv_admin', 'admin');
    $isSelf = (int) $order['user_id'] === (int) $admin['id'];

    return $render('admin', 'admin/orders/show', [
        'page_title'  => 'Order ' . (string) $order['order_no'],
        'current_nav' => 'admin.orders',
        'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
        'page_js'     => ['assets/js/app-confirm.js'],
        'order'           => $order,
        'lines'           => Order::items($orderId),
        'events'          => Order::events($orderId),
        'member'          => Wallet::account((int) $order['user_id']),
        'proofs'          => [],
        'latest_proof'    => null,
        'window'          => (new PaymentService())->windowFor($order),
        'is_self'         => $isSelf,
        'can_review'      => false,
        'can_cancel'      => !$isSelf && Order::canAdminCancel($order),
        'refund_on_cancel' => !$isSelf && Order::isRefundable($order),
        'reject_reasons'  => PaymentService::REJECT_REASONS,
        'cancel_reasons'  => PaymentService::CANCEL_REASONS,
        'shipment'        => Shipment::latestFor($orderId),
        'invoice'         => Invoice::latestFor($orderId),
        'can_pack'        => $status === 'paid',
        'can_ready'       => $status === 'packing',
        'can_ship'        => $status === 'ready_to_ship',
        'can_deliver'     => $status === 'shipped',
        'can_confirm'     => Order::canMemberConfirm($order),
        'auto_days'       => FulfilmentService::autoCompleteDays(),
    ]);
};

/** Render the printable invoice for one order. */
$invoiceHtml = static function (int $orderId): string {
    $document = (new InvoiceService())->ensureForOrder($orderId);
    $order    = Order::find($orderId);

    ob_start();

    try {
        View::render('shop/invoice', [
            'document' => $document,
            'order'    => $order,
            'back_url' => url('orders/' . $orderId),
        ]);
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return (string) ob_get_clean();
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
    /* ---------- Member pages --------------------------------------- */

    'P8-T5 the paid member page offers the invoice and explains what next' => function () use ($makeProduct, $makeMember, $paidOrder, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        test_signed_in_as($member);

        $html = $memberOrderHtml($orderId);

        assert_contains('Payment received', $html);
        assert_contains('View / print invoice', $html);
        assert_contains('href="' . url('orders/' . $orderId . '/invoice') . '"', $html);
        assert_contains('as soon as the order ships', $html);
        assert_not_contains('arrive with the fulfilment phase', $html);
        assert_not_contains('Courier tracking and the printable invoice', $html);
    },

    'P8-T1 the shipped member page shows the courier and tracking' => function () use ($makeProduct, $makeMember, $paidOrder, $advance, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $advance($orderId, 'shipped');
        test_signed_in_as($member);

        $html = $memberOrderHtml($orderId);

        assert_contains('On the way', $html);
        assert_contains('J&amp;T Express', $html);
        assert_contains('JT1234567890', $html);
        assert_contains('Tracking number:', $html);
        assert_contains('Shipment', $html);
        assert_contains('View / print invoice', $html);
        assert_not_contains('Confirm your receipt', $html);
    },

    'P8-T4 the delivered member page asks for a confirmation through the modal' => function () use ($makeProduct, $makeMember, $paidOrder, $advance, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $advance($orderId, 'delivered');
        test_signed_in_as($member);

        $html = $memberOrderHtml($orderId);

        assert_contains('Delivered', $html);
        assert_contains('Confirm your receipt', $html);
        assert_contains('action="' . url('orders/' . $orderId . '/confirm') . '"', $html);
        assert_contains('name="note"', $html);
        assert_contains('data-confirm-title="Confirm you received this order?"', $html);
        assert_contains('data-confirm-button="Confirm receipt"', $html);
        assert_contains('Confirm receipt', $html);
        assert_contains('completes automatically', $html);
        assert_contains(FulfilmentService::autoCompleteDays() . ' days after delivery', $html);
        assert_not_contains('onsubmit="return confirm', $html);
        assert_not_contains('Cancel order', $html);
    },

    'P8-T4 the completed member page shows the closed order and the invoice' => function () use ($makeProduct, $makeMember, $paidOrder, $advance, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $advance($orderId, 'delivered');
        (new FulfilmentService())->confirmByMember((int) $member['id'], $orderId);

        test_signed_in_as($member);

        $html = $memberOrderHtml($orderId);

        assert_contains('Order complete', $html);
        assert_contains('Thank you for shopping with', $html);
        assert_contains('Completed', $html);
        assert_contains('View / print invoice', $html);
        assert_not_contains('Confirm your receipt', $html);

        $events = Order::events($orderId);
        $last   = end($events);
        assert_same('receipt_confirmed', $last['reason_code']);
        assert_same('completed', $last['to_status']);
    },

    /* ---------- Admin fulfilment panel ----------------------------- */

    'P8-T1 the paid admin page offers Start packing' => function () use ($makeProduct, $makeMember, $paidOrder, $adminOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        test_signed_in_as(test_user('fv_admin', 'admin'));

        $html = $adminOrderHtml($orderId);

        assert_contains('Fulfilment', $html);
        assert_contains('Start packing', $html);
        assert_contains('name="to" value="packing"', $html);
        assert_contains('action="' . url('admin/orders/' . $orderId . '/transition') . '"', $html);
        assert_contains('data-confirm-title="Start packing this order?"', $html);
        assert_contains('View / print invoice', $html);
        assert_contains('Invoice issued', $html);
        assert_not_contains('name="tracking_number"', $html);
    },

    'P8-T1 the packing admin page offers Mark ready to ship' => function () use ($makeProduct, $makeMember, $paidOrder, $advance, $adminOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $advance($orderId, 'packing');

        test_signed_in_as(test_user('fv_admin', 'admin'));

        $html = $adminOrderHtml($orderId);

        assert_contains('Mark ready to ship', $html);
        assert_contains('name="to" value="ready_to_ship"', $html);
        assert_not_contains('Start packing', $html);
    },

    'P8-T2 the ready admin page carries the guarded shipping form' => function () use ($makeProduct, $makeMember, $paidOrder, $advance, $adminOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $advance($orderId, 'ready_to_ship');

        test_signed_in_as(test_user('fv_admin', 'admin'));

        $html = $adminOrderHtml($orderId);

        assert_contains('Ship order', $html);
        assert_contains('action="' . url('admin/orders/' . $orderId . '/ship') . '"', $html);
        assert_contains('name="courier"', $html);
        assert_contains('name="tracking_number"', $html);
        assert_contains('name="notes"', $html);
        assert_contains('datalist id="courier-options"', $html);
        assert_contains('J&amp;T Express', $html);
        assert_contains('deducts the stock', $html);
        assert_contains('data-confirm-title="Ship this order?"', $html);
        assert_contains('name="_csrf"', $html);
    },

    'P8-T4 the shipped admin page carries the delivery form and the shipment' => function () use ($makeProduct, $makeMember, $paidOrder, $advance, $adminOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $advance($orderId, 'shipped');

        test_signed_in_as(test_user('fv_admin', 'admin'));

        $html = $adminOrderHtml($orderId);

        assert_contains('Mark delivered', $html);
        assert_contains('action="' . url('admin/orders/' . $orderId . '/deliver') . '"', $html);
        assert_contains('name="note"', $html);
        assert_contains('Shipment', $html);
        assert_contains('JT1234567890', $html);
        assert_contains('Two boxes', $html);
        assert_contains('data-confirm-title="Mark this order delivered?"', $html);
    },

    'P8-T9 the delivered admin page explains the auto-complete' => function () use ($makeProduct, $makeMember, $paidOrder, $advance, $adminOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct();
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $advance($orderId, 'delivered');

        test_signed_in_as(test_user('fv_admin', 'admin'));

        $html = $adminOrderHtml($orderId);

        assert_contains('Waiting for the member to confirm receipt', $html);
        assert_contains(FulfilmentService::autoCompleteDays() . ' days after delivery', $html);
        assert_not_contains('Mark delivered', $html);
    },

    /* ---------- The printable invoice ------------------------------ */

    'P8-T5 the invoice page is a standalone A4 document' => function () use ($makeProduct, $makeMember, $paidOrder, $invoiceHtml, $withSettings): void {
        $withSettings([
            'seller_name'    => 'EcoWealth Trading Inc.',
            'seller_tin'     => '123-456-789-000',
            'seller_phone'   => '0917 000 1111',
            'seller_address' => 'Unit 1, EcoWealth Building, Makati',
        ], function () use ($makeProduct, $makeMember, $paidOrder, $invoiceHtml): void {
            $member  = $makeMember();
            $product = $makeProduct(['name' => 'Invoice Coffee', 'sku' => 'COF-9', 'price' => '40.00']);
            $order   = $paidOrder($member, $product, 2);
            $orderId = (int) $order['id'];

            test_signed_in_as($member);

            $html    = $invoiceHtml($orderId);
            $invoice = Invoice::latestFor($orderId);

            assert_contains('<!doctype html>', $html);
            assert_contains('<title>Invoice ' . (string) $invoice['invoice_no'], $html);
            assert_contains('@page { size: A4; margin: 14mm; }', $html);
            assert_contains('.toolbar { display: none; }', $html);
            assert_contains('window.print()', $html);
            assert_contains('Back to order', $html);
            assert_contains('href="' . url('orders/' . $orderId) . '"', $html);

            /* No app chrome: this is a document, not a page inside the layout. */
            assert_not_contains('layout-menu', $html);
            assert_not_contains('<aside', $html);
            assert_not_contains('menu-inner', $html);

            /* Seller identity, buyer, delivery and money. */
            assert_contains('EcoWealth Trading Inc.', $html);
            assert_contains('Unit 1, EcoWealth Building, Makati', $html);
            assert_contains('TIN: 123-456-789-000', $html);
            assert_contains('0917 000 1111', $html);
            assert_contains((string) $invoice['invoice_no'], $html);
            assert_contains((string) $order['order_no'], $html);
            assert_contains('Juan Dela Cruz', $html);
            assert_contains('@' . (string) $member['username'], $html);
            assert_contains('12 Mabini Street', $html);
            assert_contains('Invoice Coffee', $html);
            assert_contains('COF-9', $html);
            assert_contains(money(40), $html);
            assert_contains(money(80), $html);
            assert_contains('Subtotal', $html);
            assert_contains('Total', $html);
            assert_contains('not a VAT official receipt', $html);
            assert_not_contains('VOID', $html);
        });
    },

    'P8 the invoice page marks a voided document and keeps its content' => function () use ($makeProduct, $makeMember, $paidOrder, $invoiceHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['name' => 'Void Coffee', 'price' => '35.00', 'stock' => '3']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        (new PaymentService())->cancelByAdmin(
            (int) test_user('fv_admin', 'admin')['id'],
            $orderId,
            'customer_request',
            'Member changed their mind'
        );

        test_signed_in_as($member);

        $html = $invoiceHtml($orderId);

        assert_contains('VOID', $html);
        assert_contains('This invoice has been voided.', $html);
        assert_contains('void-stamp', $html);
        assert_contains('Voided', $html);
        assert_contains('Member changed their mind', $html);
        assert_contains('Void Coffee', $html);
        assert_contains(money(35), $html);
    },

    'P8 the invoice page escapes every snapshot value' => function () use ($makeProduct, $makeMember, $paidOrder, $invoiceHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['name' => '<script>alert(1)</script> Coffee', 'stock' => '3']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        /* A hostile seller name is frozen from settings at issue time. */
        $sellerBefore = (string) setting('seller_name', '');
        setting_set('seller_name', '<script>alert("seller")</script>');

        try {
            /* Re-issue a fresh order so the snapshot takes the hostile name. */
            $other  = $paidOrder($member, $product, 1);
            $html   = $invoiceHtml((int) $other['id']);
            assert_not_contains('<script>alert("seller")</script>', $html);
            assert_contains('&lt;script&gt;alert(&quot;seller&quot;)&lt;/script&gt;', $html);

            $first = $invoiceHtml($orderId);
            assert_not_contains('<script>alert(1)</script>', $first);
            assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $first);
        } finally {
            setting_set('seller_name', $sellerBefore);
        }
    },

    /* ---------- Settings ------------------------------------------ */

    'P8 the settings page edits the seller identity and the completion rules' => function () use ($render): void {
        test_signed_in_as(test_user('fv_admin', 'admin'));

        $html = $render('admin', 'admin/settings/index', [
            'page_title'        => 'Settings',
            'current_nav'       => 'admin.settings',
            'page_css'          => ['assets/css/orders.css'],
            'settings'          => setting_all(),
            'account_groups'    => (new ReflectionClass(AdminSettingsController::class))->getConstant('ACCOUNT_GROUPS'),
            'window_fields'     => (new ReflectionClass(AdminSettingsController::class))->getConstant('WINDOW_FIELDS'),
            'seller_fields'     => (new ReflectionClass(AdminSettingsController::class))->getConstant('SELLER_FIELDS'),
            'fulfilment_fields' => (new ReflectionClass(AdminSettingsController::class))->getConstant('FULFILMENT_FIELDS'),
            'shop_enabled'      => true,
            'expiry_enabled'    => true,
            'autocomplete_enabled' => true,
            'manual_open'       => true,
        ]);

        assert_contains('Fulfilment &amp; invoices', $html);
        assert_contains('Seller identity', $html);
        assert_contains('name="seller_name"', $html);
        assert_contains('value="' . e((string) setting('seller_name', '')) . '"', $html);
        assert_contains('name="seller_tin"', $html);
        assert_contains('name="seller_phone"', $html);
        assert_contains('name="seller_address"', $html);
        assert_contains('name="shop_autocomplete_days"', $html);
        assert_contains('value="' . (string) setting('shop_autocomplete_days', '7') . '"', $html);
        assert_contains('name="shop_autocomplete_enabled"', $html);
        assert_contains('Complete delivered orders automatically', $html);
        assert_contains('bin/order_autocomplete.php', $html);
        assert_contains('Save settings', $html);
        assert_contains('name="_csrf"', $html);
    },

    'P8 the settings page echoes a bad completion window and the switch state' => function () use ($render): void {
        test_signed_in_as(test_user('fv_admin', 'admin'));

        errors_set([
            'shop_autocomplete_days' => 'Auto-complete after (days) must be at least 1.',
            'seller_name'            => 'Registered business name is required.',
        ]);
        old_set([
            'shop_autocomplete_days'      => '0',
            'seller_name'                 => 'Typed Seller',
            'shop_autocomplete_enabled'   => '0',
        ]);

        $html = $render('admin', 'admin/settings/index', [
            'page_title'        => 'Settings',
            'current_nav'       => 'admin.settings',
            'settings'          => setting_all(),
            'account_groups'    => (new ReflectionClass(AdminSettingsController::class))->getConstant('ACCOUNT_GROUPS'),
            'window_fields'     => (new ReflectionClass(AdminSettingsController::class))->getConstant('WINDOW_FIELDS'),
            'seller_fields'     => (new ReflectionClass(AdminSettingsController::class))->getConstant('SELLER_FIELDS'),
            'fulfilment_fields' => (new ReflectionClass(AdminSettingsController::class))->getConstant('FULFILMENT_FIELDS'),
            'shop_enabled'      => true,
            'expiry_enabled'    => true,
            'autocomplete_enabled' => true,
            'manual_open'       => true,
        ]);

        assert_contains('must be at least 1', $html);
        assert_contains('Registered business name is required.', $html);
        assert_contains('value="Typed Seller"', $html);
        assert_contains('value="0"', $html);
        assert_contains('is-invalid', $html);

        $switch = static function (string $html): string {
            return preg_match('/<input[^>]*id="shop-autocomplete-enabled"[^>]*>/', $html, $matches) === 1
                ? $matches[0]
                : '';
        };
        assert_not_contains('checked', $switch($html), 'the unticked switch stays off after a failed save');
    },
];
