<?php
declare(strict_types=1);

/* ==============================================================
 *  InvoiceTest — the immutable invoice snapshot (plan §5.7,
 *  §7 Phase 8; tests P8-T5 … P8-T7, plus the void-on-cancel rule)
 *
 *  Every case runs inside the harness transaction and rolls back.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Invoice Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'INV-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '40.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('inv_' . bin2hex(random_bytes(4)));
};

$fund = static function (int $userId, string $amount = '500.00'): void {
    (new WalletService())->credit(
        $userId,
        $amount,
        'commission',
        random_int(1000000, 9999999),
        'Test funds'
    );
};

$input = static function (array $overrides = []): array {
    return $overrides + [
        'billing_name'     => 'Maria Santos',
        'billing_phone'    => '0917 111 2222',
        'shipping_name'    => 'Maria Santos',
        'shipping_address' => '12 Mabini Street',
        'shipping_barangay' => 'Poblacion',
        'shipping_city'    => 'Makati',
        'shipping_province' => 'Metro Manila',
        'shipping_postal'  => '1200',
        'payment_method'   => 'ewallet',
        'idempotency_key'  => bin2hex(random_bytes(16)),
        'terms'            => '1',
    ];
};

$paidOrder = static function (array $member, int $productId, int $quantity = 1) use ($fund, $input): array {
    $userId = (int) $member['id'];
    $fund($userId);
    (new CartService())->add($userId, $productId, $quantity);

    $result = (new OrderService())->placeFromCart($userId, $input());

    return Order::find($result['order_id']);
};

$manualOrder = static function (array $member, int $productId, int $quantity = 1) use ($input): array {
    $userId = (int) $member['id'];
    (new CartService())->add($userId, $productId, $quantity);

    $result = (new OrderService())->placeFromCart($userId, $input([
        'payment_method' => 'gcash',
        'idempotency_key' => bin2hex(random_bytes(16)),
    ]));

    return Order::find($result['order_id']);
};

$uploadProof = static function (int $userId, int $orderId): void {
    $tmp = (string) tempnam(sys_get_temp_dir(), 'invproof');
    file_put_contents($tmp, TEST_PNG_BYTES);

    (new PaymentService())->submitProof($userId, $orderId, [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ], [
        'amount_sent'  => '40.00',
        'reference_no' => 'INV-REF-' . strtoupper(bin2hex(random_bytes(3))),
    ]);
};

return [
    /* ---------- P8-T5: issuing ----------------------------------- */

    'P8-T5 an e-wallet payment freezes one invoice at payment time' => function () use ($makeProduct, $makeMember, $paidOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['name' => 'Invoice Coffee', 'sku' => 'COF-1', 'price' => '40.00', 'stock' => '6']);
        $order   = $paidOrder($member, $product, 2);
        $orderId = (int) $order['id'];

        assert_same(1, Invoice::countFor($orderId));

        $invoice = Invoice::latestFor($orderId);
        assert_not_null($invoice);

        assert_true(InvoiceNumber::isValid((string) $invoice['invoice_no']));
        assert_contains('INV-' . date('ymd') . '-', (string) $invoice['invoice_no']);
        assert_same('paid', $invoice['status_at_issue']);
        assert_same('EcoWealth Trading Inc.', $invoice['seller_name']);
        assert_same((string) $member['username'], $invoice['buyer_username']);
        assert_same('Maria Santos', $invoice['billing_name']);
        assert_same('0917 111 2222', $invoice['billing_phone']);
        assert_same('Maria Santos', $invoice['shipping_name']);
        assert_contains('12 Mabini Street', (string) $invoice['shipping_address']);
        assert_contains('Makati', (string) $invoice['shipping_address']);
        assert_same('ewallet', $invoice['payment_method']);
        assert_not_null($invoice['paid_at']);
        assert_null($invoice['voided_at']);

        assert_same('80.00', number_format((float) $invoice['subtotal'], 2, '.', ''));
        assert_same('0.00', number_format((float) $invoice['shipping_fee'], 2, '.', ''));
        assert_same('80.00', number_format((float) $invoice['total'], 2, '.', ''));

        $items = Invoice::items((int) $invoice['id']);
        assert_count(1, $items);
        assert_same('Invoice Coffee', $items[0]['description']);
        assert_same('COF-1', $items[0]['sku']);
        assert_same(2, (int) $items[0]['quantity']);
        assert_same('40.00', number_format((float) $items[0]['unit_price'], 2, '.', ''));
        assert_same('80.00', number_format((float) $items[0]['amount'], 2, '.', ''));
    },

    'P8-T5 a verified manual payment issues the invoice too' => function () use ($makeProduct, $makeMember, $manualOrder, $uploadProof): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00', 'stock' => '5']);
        $order   = $manualOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        /* Nothing is issued before the money is confirmed. */
        assert_same(0, Invoice::countFor($orderId));
        assert_null((new InvoiceService())->document($orderId));

        $uploadProof((int) $member['id'], $orderId);

        $admin   = test_user('inv_admin', 'admin');
        (new PaymentService())->verify((int) $admin['id'], $orderId, [
            'amount_received'   => '40.00',
            'payment_reference' => 'INV-VERIFY-1',
        ]);

        assert_same(1, Invoice::countFor($orderId));

        $invoice = Invoice::latestFor($orderId);
        assert_same('paid', $invoice['status_at_issue']);
        assert_same('gcash', $invoice['payment_method']);
        assert_same('40.00', number_format((float) $invoice['total'], 2, '.', ''));
        assert_same('INV-VERIFY-1', (string) Order::find($orderId)['payment_reference']);
    },

    'P8-T5 an unpaid order has no invoice and never gets one' => function () use ($makeProduct, $makeMember, $manualOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '30.00']);
        $order   = $manualOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $service = new InvoiceService();

        assert_same(0, Invoice::countFor($orderId));
        assert_null($service->document($orderId));
        assert_null($service->ensureForOrder($orderId));
        assert_same(0, Invoice::countFor($orderId));

        /* Cancelling an unpaid order does not create one either. */
        (new OrderService())->cancelByMember((int) $member['id'], $orderId, 'changed my mind');

        assert_null($service->ensureForOrder($orderId));
        assert_same(0, Invoice::countFor($orderId));
    },

    /* ---------- P8-T6: reprinting --------------------------------- */

    'P8-T6 reprinting returns the same number and frozen content' => function () use ($makeProduct, $makeMember, $paidOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['name' => 'Reprint Coffee', 'price' => '40.00', 'stock' => '5']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $service = new InvoiceService();
        $first   = $service->ensureForOrder($orderId);
        $second  = $service->ensureForOrder($orderId);

        assert_not_null($first);
        assert_not_null($second);
        assert_same((int) $first['invoice']['id'], (int) $second['invoice']['id']);
        assert_same((string) $first['invoice']['invoice_no'], (string) $second['invoice']['invoice_no']);
        assert_same($first['invoice']['total'], $second['invoice']['total']);
        assert_same(1, Invoice::countFor($orderId), 'a reprint never creates a second snapshot');

        /* Change the settings and the catalogue: the document is frozen. */
        $sellerBefore = (string) setting('seller_name', '');
        $priceBefore  = (string) db()->query('SELECT price FROM products WHERE id = ' . $product)->fetchColumn();

        setting_set('seller_name', 'A Different Company');
        db()->prepare('UPDATE products SET price = 999.00 WHERE id = ?')->execute([$product]);

        try {
            $third = $service->document($orderId);

            assert_not_null($third);
            assert_same('EcoWealth Trading Inc.', $third['invoice']['seller_name'], 'settings never rewrite an invoice');
            assert_same('Reprint Coffee', $third['items'][0]['description']);
            assert_same('40.00', number_format((float) $third['items'][0]['unit_price'], 2, '.', ''));
            assert_same('40.00', number_format((float) $third['invoice']['total'], 2, '.', ''));
        } finally {
            setting_set('seller_name', $sellerBefore);
            db()->prepare('UPDATE products SET price = ? WHERE id = ?')->execute([$priceBefore, $product]);
        }
    },

    'P8-T5 a paid order from an earlier phase is backfilled exactly once' => function () use ($makeMember, $makeProduct): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '25.00']);
        $orderId = test_order((int) $member['id'], [
            ['product_id' => $product, 'quantity' => 1, 'reservation_state' => 'committed'],
        ], 'paid', [
            'paid_at'      => date('Y-m-d H:i:s'),
            'subtotal'     => '25.00',
            'total'        => '25.00',
            'billing_name' => 'Legacy Buyer',
        ]);

        assert_same(0, Invoice::countFor($orderId));

        $document = (new InvoiceService())->ensureForOrder($orderId);

        assert_not_null($document);
        assert_same(1, Invoice::countFor($orderId));
        assert_true(
            str_starts_with((string) $document['invoice']['invoice_no'], 'INV-' . date('ymd') . '-'),
            'the invoice number starts with INV-yymmdd-'
        );
        assert_count(1, $document['items']);

        /* A second view finds the snapshot and does not write another. */
        $again = (new InvoiceService())->ensureForOrder($orderId);
        assert_same((int) $document['invoice']['id'], (int) $again['invoice']['id']);
        assert_same(1, Invoice::countFor($orderId));
    },

    'P8-T5 invoice numbers are unique and follow INV-yymmdd-#####' => function () use ($makeProduct, $makeMember, $paidOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '25.00']);

        $first  = $paidOrder($member, $product, 1);
        $second = $paidOrder($member, $product, 1);

        $one = Invoice::latestFor((int) $first['id']);
        $two = Invoice::latestFor((int) $second['id']);

        assert_not_same($one['invoice_no'], $two['invoice_no']);
        assert_true(InvoiceNumber::isValid((string) $one['invoice_no']));
        assert_true(InvoiceNumber::isValid((string) $two['invoice_no']));
        assert_same(InvoiceNumber::make((int) $one['id']), $one['invoice_no']);
        assert_same(InvoiceNumber::make((int) $two['id']), $two['invoice_no']);
        assert_true(str_ends_with((string) $one['invoice_no'], str_pad((string) $one['id'], 5, '0', STR_PAD_LEFT)));

        assert_throws(
            static fn () => db()->prepare('UPDATE shop_invoices SET invoice_no = ? WHERE id = ?')
                ->execute([$two['invoice_no'], (int) $one['id']]),
            PDOException::class,
            'the invoice number is unique'
        );
    },

    /* ---------- Voiding on cancellation --------------------------- */

    'cancelling a paid order voids its invoice without changing its content' => function () use ($makeProduct, $makeMember, $paidOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['name' => 'Void Coffee', 'price' => '35.00', 'stock' => '4']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];

        $before = Invoice::latestFor($orderId);
        assert_null($before['voided_at']);

        $admin = test_user('inv_admin', 'admin');
        (new PaymentService())->cancelByAdmin((int) $admin['id'], $orderId, 'customer_request', 'Member changed their mind');

        $after = Invoice::latestFor($orderId);
        assert_not_null($after['voided_at']);
        assert_contains('Order cancelled', (string) $after['void_reason']);
        assert_contains('The member asked to cancel', (string) $after['void_reason']);

        /* The frozen content is identical; only the validity flag moved. */
        assert_same((string) $before['invoice_no'], (string) $after['invoice_no']);
        assert_same((string) $before['seller_name'], (string) $after['seller_name']);
        assert_same(number_format((float) $before['total'], 2, '.', ''), number_format((float) $after['total'], 2, '.', ''));

        $document = (new InvoiceService())->document($orderId);
        assert_not_null($document);
        assert_not_null($document['invoice']['voided_at']);
        assert_same(1, Invoice::countFor($orderId));

        /* Cancelling an unpaid order has nothing to void. */
        $otherMember = $makeMember();
        $otherOrder  = test_order((int) $otherMember['id'], [], 'pending');
        assert_false((new InvoiceService())->voidForOrder($otherOrder, 'Nothing to void'));
    },

    /* ---------- P8-T7: scoping ------------------------------------ */

    'P8-T7 invoice documents are scoped to their own order' => function () use ($makeProduct, $makeMember, $paidOrder): void {
        $alice   = $makeMember();
        $bob     = $makeMember();
        $product = $makeProduct(['name' => 'Scoped Tea', 'price' => '25.00']);

        $aliceOrder = $paidOrder($alice, $product, 1);
        $bobOrder   = $paidOrder($bob, $product, 2);

        /* Member reads are ownership-scoped: another id is simply absent. */
        assert_null(Order::findForUser((int) $aliceOrder['id'], (int) $bob['id']));
        assert_not_null(Order::findForUser((int) $bobOrder['id'], (int) $bob['id']));

        $aliceDoc = (new InvoiceService())->document((int) $aliceOrder['id']);
        $bobDoc   = (new InvoiceService())->document((int) $bobOrder['id']);

        assert_not_null($aliceDoc);
        assert_not_null($bobDoc);
        assert_not_same($aliceDoc['invoice']['invoice_no'], $bobDoc['invoice']['invoice_no']);
        assert_same((string) $alice['username'], $aliceDoc['invoice']['buyer_username']);
        assert_same((string) $bob['username'], $bobDoc['invoice']['buyer_username']);
        assert_same('25.00', number_format((float) $aliceDoc['invoice']['total'], 2, '.', ''));
        assert_same('50.00', number_format((float) $bobDoc['invoice']['total'], 2, '.', ''));
        assert_count(1, $aliceDoc['items']);
    },

    'P8 the invoice snapshot keeps the status it was issued at' => function () use ($makeProduct, $makeMember, $paidOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '25.00', 'stock' => '4']);
        $order   = $paidOrder($member, $product, 1);
        $orderId = (int) $order['id'];
        $admin   = test_user('inv_admin', 'admin');

        (new FulfilmentService())->pack((int) $admin['id'], $orderId);

        $invoice = Invoice::latestFor($orderId);
        assert_same('paid', $invoice['status_at_issue'], 'the status does not follow later transitions');
        assert_same('packing', Order::find($orderId)['status']);
    },
];
