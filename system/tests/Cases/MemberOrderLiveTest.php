<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberOrderLiveTest — the member's order history and one order
 *
 *  Two live surfaces: `member.orders:{userId}` for the history and
 *  `member.order:{orderId}` for one order. These cases pin:
 *
 *    · touchOrder bumps the admin surfaces, the member detail page and,
 *      when the buyer id is known, that buyer's history;
 *    · placing an order, cancelling it and an admin fulfilment step bump
 *      the two member surfaces;
 *    · the history feed renders keyed rows and pagination, and the detail
 *      feed renders every card with the status/proof forms held;
 *    · both pages declare their live surfaces, regions and the shared banner.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Member Order Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'MOL-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '20',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('mol_' . bin2hex(random_bytes(4)));
};

$fund = static function (int $userId, string $amount = '200.00'): void {
    (new WalletService())->credit(
        $userId,
        $amount,
        'commission',
        random_int(1000000, 9999999),
        'Test funds'
    );
};

$orderInput = static function (array $overrides = []): array {
    return $overrides + [
        'billing_name'      => 'Order Live Buyer',
        'billing_phone'     => '0917 000 0000',
        'shipping_name'     => 'Order Live Buyer',
        'shipping_phone'    => '0917 000 0000',
        'shipping_address'  => '12 Mabini Street',
        'shipping_barangay' => 'Poblacion',
        'shipping_city'     => 'Makati',
        'shipping_province' => 'Metro Manila',
        'shipping_postal'   => '1200',
        'notes_member'      => '',
        'payment_method'    => 'gcash',
        'idempotency_key'   => bin2hex(random_bytes(16)),
        'terms'             => '1',
    ];
};

return [
    'member order surfaces use the list + detail keys and bump together' => function (): void {
        $adminListBefore   = Live::revision('admin.orders');
        $adminDetailBefore = Live::revision('admin.orders:4242');
        $listBefore        = Live::revision('member.orders:7');
        $detailBefore      = Live::revision('member.order:4242');

        Live::touchOrder(4242, 7);

        assert_same('member.orders:7', Live::memberOrdersSurface(7));
        assert_same('member.order:4242', Live::memberOrderSurface(4242));
        assert_same($adminListBefore + 1, Live::revision('admin.orders'));
        assert_same($adminDetailBefore + 1, Live::revision('admin.orders:4242'));
        assert_same($listBefore + 1, Live::revision('member.orders:7'));
        assert_same($detailBefore + 1, Live::revision('member.order:4242'));

        /* Without the buyer id the detail still revalidates; the history
         * list is left alone because its owner is unknown. */
        $listBefore   = Live::revision('member.orders:7');
        $detailBefore = Live::revision('member.order:4242');

        Live::touchOrder(4242);

        assert_same($listBefore, Live::revision('member.orders:7'));
        assert_same($detailBefore + 1, Live::revision('member.order:4242'));
    },

    'placement, cancellation and fulfilment bump the member order surfaces' => function () use ($makeProduct, $makeMember, $fund, $orderInput): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct();
        $admin   = test_user('mol_admin', 'admin');

        (new CartService())->add($userId, $product, 1);

        $listBefore = Live::revision(Live::memberOrdersSurface($userId));

        $placed  = (new OrderService())->placeFromCart($userId, $orderInput());
        $orderId = (int) $placed['order_id'];

        assert_same($listBefore + 1, Live::revision(Live::memberOrdersSurface($userId)), 'placement (history)');
        assert_same(1, Live::revision(Live::memberOrderSurface($orderId)), 'placement (detail)');

        $listBefore   = Live::revision(Live::memberOrdersSurface($userId));
        $detailBefore = Live::revision(Live::memberOrderSurface($orderId));

        (new OrderService())->cancelByMember($userId, $orderId, 'Changed my mind');

        assert_same($listBefore + 1, Live::revision(Live::memberOrdersSurface($userId)), 'cancellation (history)');
        assert_same($detailBefore + 1, Live::revision(Live::memberOrderSurface($orderId)), 'cancellation (detail)');

        /* A paid order can be packed by the admin; the member surfaces move. */
        $fund($userId, '100.00');
        (new CartService())->add($userId, $product, 1);

        $paid = (new OrderService())->placeFromCart($userId, $orderInput(['payment_method' => 'ewallet']));
        $paidId = (int) $paid['order_id'];

        $listBefore   = Live::revision(Live::memberOrdersSurface($userId));
        $detailBefore = Live::revision(Live::memberOrderSurface($paidId));

        (new FulfilmentService())->pack((int) $admin['id'], $paidId);

        assert_same($listBefore + 1, Live::revision(Live::memberOrdersSurface($userId)), 'packing (history)');
        assert_same($detailBefore + 1, Live::revision(Live::memberOrderSurface($paidId)), 'packing (detail)');
    },

    'the order history feed renders rows and pagination' => function () use ($makeProduct, $makeMember): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct();

        $hostileId = 0;

        for ($i = 1; $i <= 6; $i++) {
            $id = test_order($userId, [['product_id' => $product]], 'paid', [
                'payment_method' => 'ewallet',
                'subtotal'       => '25.00',
                'total'          => '25.00',
                'paid_at'        => date('Y-m-d H:i:s'),
            ]);

            if ($i === 6) {
                $hostileId = $id;
            }
        }

        db()->prepare('UPDATE shop_orders SET order_no = ? WHERE id = ?')
            ->execute(['<b>Hostile Order</b>', $hostileId]);

        test_signed_in_as($member);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/orders?per_page=5';

        $controller = new OrderController();
        $payload    = $controller->feedPayload(3, '"member.orders:' . $userId . ':3:abc"', $controller->ordersContext($userId));

        assert_same('member.orders:' . $userId, $payload['surface']);
        assert_same(
            ['member-orders-rows', 'member-orders-pagination'],
            array_keys($payload['fragments'])
        );

        $rows  = $payload['fragments']['member-orders-rows'];
        $pages = $payload['fragments']['member-orders-pagination'];

        assert_contains('data-live-region="member-orders-rows"', $rows);
        assert_contains('data-live-key=', $rows);
        assert_contains('data-live-focus="order"', $rows);
        assert_contains('data-live-focus="view"', $rows);
        assert_contains('Paid', $rows);
        assert_contains('E-wallet', $rows);
        assert_contains(money(25), $rows);
        assert_contains('&lt;b&gt;Hostile Order&lt;/b&gt;', $rows);
        assert_not_contains('<b>Hostile Order</b>', $rows);
        assert_not_contains('order-filters', $rows, 'the filter form never travels in a row fragment');
        assert_not_contains('name="status"', $rows);

        assert_contains('data-live-region="member-orders-pagination"', $pages);
        assert_contains('orders?per_page=5&amp;page=2', $pages);
        assert_not_contains('/feed', $pages);

        assert_contains('orders?per_page=5', $payload['meta']['canonical']);
    },

    'the order history feed renders its filtered empty state' => function () use ($makeMember): void {
        $member = $makeMember();

        test_signed_in_as($member);

        $_GET = ['q' => 'NO-SUCH-ORDER'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/orders?q=NO-SUCH-ORDER';

        $controller = new OrderController();
        $payload    = $controller->feedPayload(1, '"member.orders:' . (int) $member['id'] . ':1:def"', $controller->ordersContext((int) $member['id']));

        $rows = $payload['fragments']['member-orders-rows'];

        assert_contains('No orders match these filters', $rows);
        assert_contains('Clear filters', $rows);
    },

    'the order detail feed renders every card with the forms held' => function () use ($makeProduct, $makeMember, $orderInput): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct();

        (new CartService())->add($userId, $product, 1);

        $placed  = (new OrderService())->placeFromCart($userId, $orderInput(['shipping_name' => '<b>Buyer</b>']));
        $orderId = (int) $placed['order_id'];

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/orders/' . $orderId;

        $controller = new OrderController();
        $order      = Order::find($orderId);
        assert_not_null($order);

        $payload = $controller->detailFeedPayload(
            4,
            '"member.order:' . $orderId . ':4:abc"',
            $controller->orderContext($order)
        );

        assert_same('member.order:' . $orderId, $payload['surface']);
        assert_same(
            [
                'member-order-header',
                'member-order-status',
                'member-order-proofs',
                'member-order-delivery',
                'member-order-payment',
                'member-order-history',
            ],
            array_keys($payload['fragments'])
        );

        $header   = $payload['fragments']['member-order-header'];
        $status   = $payload['fragments']['member-order-status'];
        $proofs   = $payload['fragments']['member-order-proofs'];
        $delivery = $payload['fragments']['member-order-delivery'];
        $payment  = $payload['fragments']['member-order-payment'];
        $history  = $payload['fragments']['member-order-history'];

        assert_contains('data-live-region="member-order-header"', $header);
        assert_contains((string) $order['order_no'], $header);
        assert_contains('Pending', $header);
        assert_contains(money(25), $header);

        assert_contains('data-live-region="member-order-status"', $status);
        assert_contains('data-live-hold="dirty"', $status);
        assert_contains('Payment pending', $status);
        assert_contains('Upload your payment proof', $status);
        assert_contains('Submit payment proof', $status);
        assert_contains('name="amount_sent"', $status);
        assert_contains('name="proof_image"', $status);
        assert_contains('name="_csrf"', $status);
        assert_contains('Cancel order', $status);
        assert_contains('action="' . url('orders/' . $orderId . '/cancel') . '"', $status);

        assert_contains('data-live-region="member-order-proofs"', $proofs);
        assert_contains('hidden', $proofs);
        assert_not_contains('Payment attempts', $proofs);

        assert_contains('data-live-region="member-order-delivery"', $delivery);
        assert_contains('&lt;b&gt;Buyer&lt;/b&gt;', $delivery);
        assert_not_contains('<b>Buyer</b>', $delivery);
        assert_contains('12 Mabini Street', $delivery);

        assert_contains('data-live-region="member-order-payment"', $payment);
        assert_contains('GCash', $payment);
        assert_contains(money(25), $payment);

        assert_contains('data-live-region="member-order-history"', $history);
        assert_contains('Order placed', $history);

        assert_same(url('orders/' . $orderId), $payload['meta']['canonical']);
    },

    'the order detail feed renders a paid order without action forms' => function () use ($makeProduct, $makeMember): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct();

        $orderId = test_order($userId, [['product_id' => $product]], 'paid', [
            'payment_method' => 'ewallet',
            'subtotal'       => '25.00',
            'total'          => '25.00',
            'paid_at'        => date('Y-m-d H:i:s'),
        ]);

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/orders/' . $orderId;

        $controller = new OrderController();
        $order      = Order::find($orderId);
        assert_not_null($order);

        $payload = $controller->detailFeedPayload(
            5,
            '"member.order:' . $orderId . ':5:def"',
            $controller->orderContext($order)
        );

        $header  = $payload['fragments']['member-order-header'];
        $status  = $payload['fragments']['member-order-status'];
        $payment = $payload['fragments']['member-order-payment'];

        assert_contains('View / print invoice', $header);
        assert_contains('Payment received', $status);
        assert_not_contains('Cancel order', $status);
        assert_not_contains('Upload your payment proof', $status);
        assert_contains('Paid', $payment);
    },

    'the member order pages declare their live surfaces and regions' => function () use ($makeProduct, $makeMember, $orderInput): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct();

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/orders';

        $controller = new OrderController();

        (new CartService())->add($userId, $product, 1);
        $placed  = (new OrderService())->placeFromCart($userId, $orderInput());
        $orderId = (int) $placed['order_id'];

        $listData = $controller->ordersContext($userId);

        ob_start();

        try {
            View::page('member', 'shop/orders', $listData + [
                'page_title'  => 'My orders',
                'current_nav' => 'orders',
                'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
                'live'        => Live::meta(Live::memberOrdersSurface($userId), 'page'),
            ]);
        } finally {
            $listHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.orders:' . $userId . '"', $listHtml);
        assert_contains('data-live-enabled="1"', $listHtml);
        assert_contains('data-live-region="member-orders-rows"', $listHtml);
        assert_contains('data-live-region="member-orders-pagination"', $listHtml);
        assert_contains('data-live-status', $listHtml);
        assert_contains('order-filters', $listHtml);
        assert_not_contains('data-live-stale', $listHtml, 'the filter form is static');

        $order = Order::find($orderId);
        assert_not_null($order);

        $_SERVER['REQUEST_URI'] = '/ecowealth/system/orders/' . $orderId;

        $detailData = $controller->orderContext($order);

        ob_start();

        try {
            View::page('member', 'shop/order', $detailData + [
                'page_title'  => 'Order ' . (string) $order['order_no'],
                'current_nav' => 'orders',
                'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
                'page_js'     => ['assets/js/admin-live.js'],
                'live'        => Live::meta(Live::memberOrderSurface($orderId), 'page'),
            ]);
        } finally {
            $detailHtml = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.order:' . $orderId . '"', $detailHtml);
        assert_contains('data-live-region="member-order-header"', $detailHtml);
        assert_contains('data-live-region="member-order-status"', $detailHtml);
        assert_contains('data-live-region="member-order-proofs"', $detailHtml);
        assert_contains('data-live-region="member-order-delivery"', $detailHtml);
        assert_contains('data-live-region="member-order-payment"', $detailHtml);
        assert_contains('data-live-region="member-order-history"', $detailHtml);
        assert_contains('data-live-hold="dirty"', $detailHtml);
        assert_contains('data-live-status', $detailHtml);
        assert_contains('data-live-stale', $detailHtml);
        assert_contains('admin-live.js', $detailHtml);
        assert_contains('name="amount_sent"', $detailHtml);
    },
];
