<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminOrderLiveTest — the admin.orders list and one detail page
 *
 *  Both pages are one live surface each: the desk is `admin.orders`,
 *  one order is `admin.orders:{id}`. These cases pin the contract:
 *
 *    · every order write bumps both surfaces (list + detail);
 *    · a refused / no-op write does not;
 *    · the list feed renders the same four regions as the page and
 *      keeps filters out of the fragments;
 *    · the detail feed renders every card, keeps escaping, marks the
 *      form cards for the dirty-input guard and keeps the action URLs.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Desk Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'DSK-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (string $username = ''): array {
    return test_user($username !== '' ? $username : 'desk_' . bin2hex(random_bytes(4)));
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

/** Place one manual order and return its id. */
$placeOrder = static function (int $userId, int $productId, string $method = 'gcash') use ($checkout): int {
    (new CartService())->add($userId, $productId, 1);

    return (new OrderService())->placeFromCart(
        $userId,
        $checkout(['payment_method' => $method])
    )['order_id'];
};

$upload = static function (): array {
    $tmp = (string) tempnam(sys_get_temp_dir(), 'ewdesk');
    file_put_contents($tmp, TEST_PNG_BYTES);

    return [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ];
};

/** Upload one proof whose claim matches the order total. */
$submitProof = static function (int $userId, array $order) use ($upload): void {
    (new PaymentService())->submitProof($userId, (int) $order['id'], $upload(), [
        'amount_sent'   => money_amount((float) $order['total']),
        'reference_no'  => 'DESK-' . strtoupper(bin2hex(random_bytes(4))),
        'transfer_date' => date('Y-m-d'),
    ]);
};

return [
    'order surfaces use the list + detail keys and bump together' => function (): void {
        $listBefore = Live::revision('admin.orders');
        $detailBefore = Live::revision('admin.orders:4242');

        Live::touchOrder(4242);

        assert_same('admin.orders:4242', Live::orderSurface(4242));
        assert_same($listBefore + 1, Live::revision('admin.orders'));
        assert_same($detailBefore + 1, Live::revision('admin.orders:4242'));

        $meta = Live::meta('admin.orders', 'user:1');

        assert_true($meta['enabled']);
        assert_same($listBefore + 1, $meta['revision']);
        assert_contains('"admin.orders:' . ($listBefore + 1) . ':', $meta['etag']);
        assert_same(30000, $meta['interval']);
    },

    'placing an order bumps the desk and changes its ETag' => function () use ($makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct();

        $listBefore = Live::revision('admin.orders');
        $orderId    = $placeOrder((int) $member['id'], $product);

        assert_same($listBefore + 1, Live::revision('admin.orders'), 'the order desk sees the new row');
        assert_same(1, Live::revision(Live::orderSurface($orderId)), 'the detail surface is touched too');

        /* The conditional-GET contract: the old ETag no longer matches,
         * the fresh one does (so the next poll is a 304). */
        $before = Live::etag('admin.orders', $listBefore, 'user:1');
        $after  = Live::etag('admin.orders', Live::revision('admin.orders'), 'user:1');

        assert_not_same($before, $after);
        assert_false(Live::matchesIfNoneMatch($before, $after));
        assert_true(Live::matchesIfNoneMatch($after, $after));
    },

    'every payment and fulfilment step bumps both surfaces' => function () use ($makeProduct, $makeMember, $placeOrder, $submitProof): void {
        $member  = $makeMember();
        $admin   = test_user('desk_admin', 'admin');
        $adminId = (int) $admin['id'];
        $product = $makeProduct(['price' => '40.00']);

        $orderId = $placeOrder((int) $member['id'], $product);
        $order   = Order::find($orderId);
        $detail  = Live::orderSurface($orderId);

        $bump = static function (callable $write, string $label) use ($detail): void {
            $listBefore   = Live::revision('admin.orders');
            $detailBefore = Live::revision($detail);

            $write();

            assert_same($listBefore + 1, Live::revision('admin.orders'), $label . ' (list)');
            assert_same($detailBefore + 1, Live::revision($detail), $label . ' (detail)');
        };

        $bump(static function () use ($member, $order, $submitProof): void {
            $submitProof((int) $member['id'], $order);
        }, 'proof upload');

        $bump(static function () use ($adminId, $orderId, $order): void {
            (new PaymentService())->verify($adminId, $orderId, [
                'amount_received'   => money_amount((float) $order['total']),
                'payment_reference' => 'DESK-PAY-1',
                'note'              => '',
            ]);
        }, 'payment verification');

        $bump(static function () use ($adminId, $orderId): void {
            (new FulfilmentService())->pack($adminId, $orderId, '');
        }, 'packing');

        $bump(static function () use ($adminId, $orderId): void {
            (new FulfilmentService())->ready($adminId, $orderId, '');
        }, 'ready to ship');

        $bump(static function () use ($adminId, $orderId): void {
            (new FulfilmentService())->ship($adminId, $orderId, [
                'courier'         => 'J&T Express',
                'tracking_number' => 'JT1234567890',
                'notes'           => '',
            ]);
        }, 'shipment');

        $bump(static function () use ($adminId, $orderId): void {
            (new FulfilmentService())->deliver($adminId, $orderId, 'Handed to the guard');
        }, 'delivery');

        $bump(static function () use ($member, $orderId): void {
            (new FulfilmentService())->confirmByMember((int) $member['id'], $orderId, 'Received');
        }, 'member confirmation');

        assert_same('completed', (string) Order::find($orderId)['status']);

        /* A refused step is a no-op for the surfaces. */
        $listBefore   = Live::revision('admin.orders');
        $detailBefore = Live::revision($detail);

        assert_throws(
            static fn () => (new FulfilmentService())->pack($adminId, $orderId),
            FulfilmentException::class
        );

        assert_same($listBefore, Live::revision('admin.orders'));
        assert_same($detailBefore, Live::revision($detail));
    },

    'review decisions and cancellations bump their surfaces' => function () use ($makeProduct, $makeMember, $placeOrder, $submitProof): void {
        $member  = $makeMember();
        $admin   = test_user('desk_reviewer', 'admin');
        $adminId = (int) $admin['id'];
        $product = $makeProduct(['price' => '10.00']);

        /* Rejection. */
        $rejected = $placeOrder((int) $member['id'], $product);
        $submitProof((int) $member['id'], Order::find($rejected));

        $listBefore   = Live::revision('admin.orders');
        $detailBefore = Live::revision(Live::orderSurface($rejected));

        (new PaymentService())->reject($adminId, $rejected, 'unclear_proof', 'Blurred screenshot');

        assert_same($listBefore + 1, Live::revision('admin.orders'));
        assert_same($detailBefore + 1, Live::revision(Live::orderSurface($rejected)));
        assert_same('payment_failed', (string) Order::find($rejected)['status']);

        /* A second rejection has no pending proof: no bump. */
        $listBefore   = Live::revision('admin.orders');
        $detailBefore = Live::revision(Live::orderSurface($rejected));

        assert_throws(
            static fn () => (new PaymentService())->reject($adminId, $rejected, 'unclear_proof', ''),
            PaymentException::class
        );

        assert_same($listBefore, Live::revision('admin.orders'));
        assert_same($detailBefore, Live::revision(Live::orderSurface($rejected)));

        /* Administrator cancellation. */
        $cancelled = $placeOrder((int) $member['id'], $product);
        $listBefore   = Live::revision('admin.orders');
        $detailBefore = Live::revision(Live::orderSurface($cancelled));

        (new PaymentService())->cancelByAdmin($adminId, $cancelled, 'admin_cancel', 'Duplicate order');

        assert_same($listBefore + 1, Live::revision('admin.orders'));
        assert_same($detailBefore + 1, Live::revision(Live::orderSurface($cancelled)));
        assert_same('cancelled', (string) Order::find($cancelled)['status']);

        /* Member cancellation of an unpaid order. */
        $memberCancelled = $placeOrder((int) $member['id'], $product);
        $listBefore   = Live::revision('admin.orders');
        $detailBefore = Live::revision(Live::orderSurface($memberCancelled));

        (new OrderService())->cancelByMember((int) $member['id'], $memberCancelled, 'Changed my mind');

        assert_same($listBefore + 1, Live::revision('admin.orders'));
        assert_same($detailBefore + 1, Live::revision(Live::orderSurface($memberCancelled)));
    },

    'the expiry and auto-complete sweeps bump the surfaces' => function () use ($makeMember): void {
        $member   = $makeMember();
        $memberId = (int) $member['id'];

        /* The payment window expired. */
        $expired = test_order($memberId, [], 'pending', [
            'payment_deadline' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        $listBefore   = Live::revision('admin.orders');
        $detailBefore = Live::revision(Live::orderSurface($expired));

        $result = (new PaymentService())->expireOverdue();

        assert_true(in_array($expired, $result['ids'], true), 'the overdue order is cancelled');
        assert_same($listBefore + 1, Live::revision('admin.orders'));
        assert_same($detailBefore + 1, Live::revision(Live::orderSurface($expired)));

        /* A delivered order past its auto-complete window. */
        $delivered = test_order($memberId, [], 'delivered', [
            'delivered_at' => date('Y-m-d H:i:s', time() - 8 * 86400),
            'completed_at' => null,
        ]);

        $listBefore   = Live::revision('admin.orders');
        $detailBefore = Live::revision(Live::orderSurface($delivered));

        $auto = (new FulfilmentService())->autoComplete();

        assert_true(in_array($delivered, $auto['ids'], true), 'the delivered order is completed');
        assert_same($listBefore + 1, Live::revision('admin.orders'));
        assert_same($detailBefore + 1, Live::revision(Live::orderSurface($delivered)));
        assert_same('completed', (string) Order::find($delivered)['status']);
    },

    'the order desk feed renders header, stats, rows and pagination' => function (): void {
        test_signed_in_as(test_user('desk_feed_admin', 'admin'));

        $_GET = ['q' => 'coffee', 'status' => 'paid', 'page' => 2];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/orders?q=coffee&status=paid&page=2';

        $filters = Order::normaliseFilters([
            'q'      => 'coffee',
            'status' => 'paid',
        ]);

        $orders = [
            [
                'id'             => 1,
                'order_no'       => 'EW-1001',
                'username'       => 'buyer',
                'user_name'      => 'Buyer One',
                'created_at'     => '2026-11-03 10:00:00',
                'item_count'     => 2,
                'total'          => '199.50',
                'payment_method' => 'gcash',
                'status'         => 'paid',
            ],
            [
                'id'             => 2,
                'order_no'       => '<script>alert(1)</script>',
                'username'       => '<script>alert(2)</script>',
                'user_name'      => '',
                'created_at'     => '2026-11-03 11:00:00',
                'item_count'     => 1,
                'total'          => '25.00',
                'payment_method' => 'bank',
                'status'         => 'payment_review',
            ],
        ];

        $stats = [
            'total'            => 12,
            'awaiting_payment' => 2,
            'in_review'        => 3,
            'in_progress'      => 4,
            'completed'        => 5,
            'cancelled'        => 1,
            'revenue'          => 1234.5,
            'today_count'      => 2,
            'today_total'      => 100.0,
        ];

        $payload = (new AdminOrderController())->feedPayload(7, '"admin.orders:7:abc"', [
            'orders'  => $orders,
            'pager'   => new Paginator(12, 5, 2),
            'filters' => $filters,
            'stats'   => $stats,
        ]);

        assert_true($payload['ok']);
        assert_same('admin.orders', $payload['surface']);
        assert_same(7, $payload['revision']);
        assert_same(
            ['order-header', 'order-stats', 'order-rows', 'order-pagination'],
            array_keys($payload['fragments'])
        );

        $header = $payload['fragments']['order-header'];
        $cards  = $payload['fragments']['order-stats'];
        $rows   = $payload['fragments']['order-rows'];
        $pages  = $payload['fragments']['order-pagination'];

        assert_contains('data-live-region="order-header"', $header);
        assert_contains('3 awaiting review', $header);
        assert_contains('status=payment_review', $header);

        assert_contains('data-live-region="order-stats"', $cards);
        assert_contains('Awaiting payment', $cards);
        assert_contains(money(1234.5), $cards);
        assert_contains('2 today', $cards);

        assert_contains('data-live-region="order-rows"', $rows);
        assert_contains('data-live-key="1"', $rows);
        assert_contains('data-live-key="2"', $rows);
        assert_contains('data-live-focus="order"', $rows);
        assert_contains('data-live-focus="open"', $rows);
        assert_contains('href="' . url('admin/orders/1') . '"', $rows);
        assert_contains(money(199.5), $rows);
        assert_contains('Payment review', $rows);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $rows);
        assert_contains('&lt;script&gt;alert(2)&lt;/script&gt;', $rows);
        assert_not_contains('<script>alert(1)</script>', $rows);
        assert_not_contains('<script>alert(2)</script>', $rows);

        /* The filter form never enters a fragment: a refresh must not
         * steal focus or overwrite an unsubmitted search. */
        assert_not_contains('admin-order-q', $rows);
        assert_not_contains('name="q"', $rows);
        assert_not_contains('name="from"', $rows);

        assert_contains('data-live-region="order-pagination"', $pages);
        assert_contains('admin/orders?q=coffee&amp;status=paid&amp;page=3', $pages);
        assert_not_contains('/feed', $pages);

        assert_same(2, $payload['meta']['page']);
        assert_same(3, $payload['meta']['pages']);
        assert_same(12, $payload['meta']['total']);
        assert_same(5, $payload['meta']['per_page']);
        assert_contains('admin/orders?q=coffee&status=paid&page=2', $payload['meta']['canonical']);
    },

    'the order desk feed shows the filtered empty state' => function (): void {
        test_signed_in_as(test_user('desk_feed_admin', 'admin'));

        $_GET = ['status' => 'cancelled'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/orders?status=cancelled';

        $payload = (new AdminOrderController())->feedPayload(2, '"admin.orders:2:empty"', [
            'orders'  => [],
            'pager'   => new Paginator(0, 10, 1),
            'filters' => Order::normaliseFilters(['status' => 'cancelled']),
            'stats'   => [
                'total'            => 0,
                'awaiting_payment' => 0,
                'in_review'        => 0,
                'in_progress'      => 0,
                'completed'        => 0,
                'cancelled'        => 0,
                'revenue'          => 0.0,
                'today_count'      => 0,
                'today_total'      => 0.0,
            ],
        ]);

        $rows = $payload['fragments']['order-rows'];

        assert_contains('data-live-region="order-rows"', $rows);
        assert_contains('No orders match these filters', $rows);
        assert_contains('Clear filters', $rows);
        assert_not_contains('data-live-key=', $rows);
    },

    'the detail feed renders every card and keeps escaping' => function () use ($makeProduct, $makeMember, $placeOrder): void {
        $admin   = test_user('desk_detail_admin', 'admin');
        $member  = $makeMember('<b>desk buyer</b>');
        $product = $makeProduct(['name' => '<script>alert(1)</script> Desk Coffee']);

        $orderId = $placeOrder((int) $member['id'], $product);
        $order   = Order::find($orderId);

        test_signed_in_as($admin);

        $ctx     = (new AdminOrderController())->orderContext($order);
        $payload = (new AdminOrderController())->detailFeedPayload(
            4,
            '"admin.orders:' . $orderId . ':4:abc"',
            $ctx
        );

        assert_true($payload['ok']);
        assert_same('admin.orders:' . $orderId, $payload['surface']);
        assert_same(4, $payload['revision']);
        assert_same(
            [
                'order-status',
                'order-payment',
                'order-fulfilment',
                'order-items',
                'order-timeline',
                'order-member',
                'order-delivery',
                'order-shipment',
                'order-payment-summary',
                'order-cancel',
            ],
            array_keys($payload['fragments'])
        );

        $fragments = $payload['fragments'];
        $status    = $fragments['order-status'];
        $payment   = $fragments['order-payment'];
        $fulfil    = $fragments['order-fulfilment'];
        $items     = $fragments['order-items'];
        $timeline  = $fragments['order-timeline'];
        $memberCard = $fragments['order-member'];
        $delivery  = $fragments['order-delivery'];
        $shipment  = $fragments['order-shipment'];
        $summary   = $fragments['order-payment-summary'];
        $cancel    = $fragments['order-cancel'];

        assert_contains('data-live-region="order-status"', $status);
        assert_contains('Pending', $status);
        assert_contains(money((float) $order['total']), $status);

        /* Form cards are marked for the client's dirty-input guard. */
        assert_contains('data-live-region="order-payment"', $payment);
        assert_contains('data-live-hold="dirty"', $payment);
        assert_contains('Waiting for the member to pay', $payment);
        assert_not_contains('Verify and mark paid', $payment);

        assert_contains('data-live-region="order-fulfilment"', $fulfil);
        assert_contains('data-live-hold="dirty"', $fulfil);
        assert_contains('Fulfilment starts once the payment is verified', $fulfil);

        assert_contains('data-live-region="order-items"', $items);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $items);
        assert_not_contains('<script>alert(1)</script>', $items);

        assert_contains('data-live-region="order-timeline"', $timeline);
        assert_contains('Order placed', $timeline);

        assert_contains('data-live-region="order-member"', $memberCard);
        assert_contains('&lt;b&gt;desk buyer&lt;/b&gt;', $memberCard);
        assert_not_contains('<b>desk buyer</b>', $memberCard);

        assert_contains('data-live-region="order-delivery"', $delivery);
        assert_contains('12 Mabini Street', $delivery);

        /* No shipment yet: the region exists hidden so it can be revealed. */
        assert_contains('data-live-region="order-shipment"', $shipment);
        assert_contains('data-live-region="order-shipment" hidden', $shipment);

        assert_contains('data-live-region="order-payment-summary"', $summary);
        assert_contains('Total', $summary);
        assert_contains(payment_method_label('gcash'), $summary);

        /* A pending order is still cancellable by this administrator. */
        assert_contains('data-live-region="order-cancel"', $cancel);
        assert_contains('data-live-hold="dirty"', $cancel);
        assert_contains('Cancel order', $cancel);
        assert_contains('action="' . url('admin/orders/' . $orderId . '/transition') . '"', $cancel);
        assert_contains('name="to" value="cancelled"', $cancel);
        assert_contains('name="_csrf"', $cancel);
        assert_contains('order-cancel-card', $cancel);
        assert_not_contains('data-live-region="order-cancel" hidden', $cancel);

        assert_contains('admin/orders/' . $orderId, (string) $payload['meta']['canonical']);
    },

    'the detail feed opens the review pane and the fulfilment steps' => function () use ($makeProduct, $makeMember, $placeOrder, $submitProof): void {
        $admin   = test_user('desk_steps_admin', 'admin');
        $adminId = (int) $admin['id'];
        $member  = $makeMember();
        $product = $makeProduct(['price' => '20.00']);

        $orderId = $placeOrder((int) $member['id'], $product);
        $submitProof((int) $member['id'], Order::find($orderId));

        test_signed_in_as($admin);

        $controller = new AdminOrderController();

        $review = $controller->detailFeedPayload(1, '"x"', $controller->orderContext(Order::find($orderId)));

        assert_contains('data-live-region="order-payment"', $review['fragments']['order-payment']);
        assert_contains('Verify and mark paid', $review['fragments']['order-payment']);
        assert_contains('Reject proof', $review['fragments']['order-payment']);
        assert_contains(
            'action="' . url('admin/orders/' . $orderId . '/transition') . '"',
            $review['fragments']['order-payment']
        );

        /* Verify, then start packing. */
        (new PaymentService())->verify($adminId, $orderId, [
            'amount_received'   => money_amount((float) Order::find($orderId)['total']),
            'payment_reference' => 'DESK-PAY-STEPS',
            'note'              => '',
        ]);

        $paid = $controller->detailFeedPayload(2, '"x"', $controller->orderContext(Order::find($orderId)));

        assert_not_contains('Verify and mark paid', $paid['fragments']['order-payment']);
        assert_contains('Start packing', $paid['fragments']['order-fulfilment']);
        assert_contains('View / print invoice', $paid['fragments']['order-fulfilment']);
        assert_contains('order-cancel-card', $paid['fragments']['order-cancel']);

        /* Ready to ship exposes the guarded courier form. */
        (new FulfilmentService())->pack($adminId, $orderId);
        (new FulfilmentService())->ready($adminId, $orderId);

        $ready = $controller->detailFeedPayload(3, '"x"', $controller->orderContext(Order::find($orderId)));

        assert_contains('Ship order', $ready['fragments']['order-fulfilment']);
        assert_contains('id="ship-courier"', $ready['fragments']['order-fulfilment']);
        assert_contains('data-courier-other', $ready['fragments']['order-fulfilment']);
        assert_contains('name="tracking_number"', $ready['fragments']['order-fulfilment']);

        /* Shipped: the hidden shipment region is replaced by the card. */
        (new FulfilmentService())->ship($adminId, $orderId, [
            'courier'         => 'J&T Express',
            'tracking_number' => 'JT-DESK-1',
            'notes'           => 'Two boxes',
        ]);

        $shipped = $controller->detailFeedPayload(4, '"x"', $controller->orderContext(Order::find($orderId)));

        assert_contains('data-live-region="order-shipment"', $shipped['fragments']['order-shipment']);
        assert_contains('order-shipment-card', $shipped['fragments']['order-shipment']);
        assert_not_contains('data-live-region="order-shipment" hidden', $shipped['fragments']['order-shipment']);
        assert_contains('J&amp;T Express', $shipped['fragments']['order-shipment']);
        assert_contains('JT-DESK-1', $shipped['fragments']['order-shipment']);
        assert_contains('Mark delivered', $shipped['fragments']['order-fulfilment']);

        /* Delivered: the cancel card disappears; delivery stays visible. */
        (new FulfilmentService())->deliver($adminId, $orderId, 'Received by the guard');

        $delivered = $controller->detailFeedPayload(5, '"x"', $controller->orderContext(Order::find($orderId)));

        assert_contains('data-live-region="order-cancel"', $delivered['fragments']['order-cancel']);
        assert_contains('data-live-region="order-cancel" hidden', $delivered['fragments']['order-cancel']);
        assert_not_contains('Cancel order', $delivered['fragments']['order-cancel']);
        assert_contains('Delivered', $delivered['fragments']['order-status']);
        assert_contains('Shipped', $delivered['fragments']['order-shipment']);
    },

    'the live client keeps form regions and reports the held keys' => function (): void {
        $root = dirname(__DIR__, 2) . '/public/assets/js/';

        $live = (string) file_get_contents($root . 'app-live.js');

        assert_contains("getAttribute('data-live-hold')", $live);
        assert_contains('function hasUnsavedInput(region)', $live);
        assert_contains('function defaultSelectedIndex(select)', $live);
        assert_contains('held.push(key)', $live);
        assert_contains('bindHeld(surface)', $live);
        assert_contains('held: held', $live);

        /* A swapped-in confirm form must still ask (delegated submit). */
        $confirm = (string) file_get_contents($root . 'app-confirm.js');
        assert_contains("document.addEventListener('submit'", $confirm);
        assert_contains("form.matches('form[data-confirm]')", $confirm);
        assert_not_contains("document.querySelectorAll('form[data-confirm]')", $confirm);

        /* The page banner is driven by detail.held, not by every refresh. */
        $adminJs = (string) file_get_contents($root . 'admin-live.js');
        assert_contains("document.querySelector('[data-live-stale]')", $adminJs);
        assert_contains('detail.held', $adminJs);
        assert_contains("classList.remove('d-none')", $adminJs);
        assert_contains("classList.add('d-none')", $adminJs);
        assert_contains('isAdminSurface', $adminJs);
        assert_contains('data-live-reload', $adminJs);
    },

    'the order pages declare their live surfaces and regions' => function () use ($makeProduct, $makeMember, $placeOrder): void {
        $admin   = test_user('desk_page_admin', 'admin');
        $member  = $makeMember();
        $product = $makeProduct();

        $orderId = $placeOrder((int) $member['id'], $product);
        $order   = Order::find($orderId);

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/orders';

        $filters = Order::normaliseFilters([]);
        $pager   = new Paginator(1, 10, 1);

        ob_start();

        try {
            View::page('admin', 'admin/orders/index', [
                'page_title'  => 'Orders',
                'current_nav' => 'admin.orders',
                'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
                'orders'      => Order::adminList($filters, $pager),
                'pager'       => $pager,
                'filters'     => $filters,
                'stats'       => Order::stats(),
                'live'        => Live::meta('admin.orders', 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.orders"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="order-header"', $html);
        assert_contains('data-live-region="order-stats"', $html);
        assert_contains('data-live-region="order-rows"', $html);
        assert_contains('data-live-region="order-pagination"', $html);
        assert_contains('data-live-key="' . $orderId . '"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('order-filters', $html);

        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/orders/' . $orderId;

        $controller = new AdminOrderController();
        $ctx        = $controller->orderContext($order);

        ob_start();

        try {
            View::page('admin', 'admin/orders/show', [
                'page_title'  => 'Order ' . (string) $order['order_no'],
                'current_nav' => 'admin.orders',
                'page_css'    => [
                    'assets/css/shop.css',
                    'assets/css/orders.css',
                    'assets/vendor/libs/sweetalert2/sweetalert2.css',
                ],
                'page_js'     => [
                    'assets/vendor/libs/sweetalert2/sweetalert2.js',
                    'assets/js/app-confirm.js',
                    'assets/js/app-ship.js',
                    'assets/js/admin-live.js',
                ],
                'ctx'  => $ctx,
                'live' => Live::meta('admin.orders:' . $orderId, 'page'),
            ]);
        } finally {
            $detailHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.orders:' . $orderId . '"', $detailHtml);
        assert_contains('data-live-status', $detailHtml);
        assert_contains('data-live-stale', $detailHtml);
        assert_contains('admin-live.js', $detailHtml);
        assert_contains('data-live-region="order-status"', $detailHtml);
        assert_contains('data-live-region="order-payment"', $detailHtml);
        assert_contains('data-live-region="order-fulfilment"', $detailHtml);
        assert_contains('data-live-region="order-items"', $detailHtml);
        assert_contains('data-live-region="order-timeline"', $detailHtml);
        assert_contains('data-live-region="order-member"', $detailHtml);
        assert_contains('data-live-region="order-delivery"', $detailHtml);
        assert_contains('data-live-region="order-shipment"', $detailHtml);
        assert_contains('data-live-region="order-payment-summary"', $detailHtml);
        assert_contains('data-live-region="order-cancel"', $detailHtml);
        assert_contains('action="' . url('admin/orders/' . $orderId . '/transition') . '"', $detailHtml);
    },
];
