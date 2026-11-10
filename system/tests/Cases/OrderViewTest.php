<?php
declare(strict_types=1);

/* ==============================================================
 *  OrderViewTest — checkout, order pages, address book, order desk
 *  (plan §5.3, §7 Phase 6; supports P6-T6/T7/T9/T10/T11)
 *
 *  Renders the real templates through View::page() with the data the
 *  controllers build, and asserts what a browser would see.
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

/** The sidebar block from a rendered page (empty when there is no menu). */
$sidebar = static function (string $html): string {
    $start = strpos($html, '<ul class="menu-inner py-1">');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, '</ul>', $start);

    return $end === false ? '' : substr($html, $start, $end - $start);
};

/** The opening tag of an element with the given id (for attribute checks). */
$tag = static function (string $html, string $id, string $element = 'input'): string {
    $pattern = '/<' . $element . '\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/';

    return preg_match($pattern, $html, $matches) === 1 ? $matches[0] : '';
};

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Checkout Product ' . bin2hex(random_bytes(3)),
        'sku'    => 'CHK-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('ov_' . bin2hex(random_bytes(4)));
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

/** Place one real order and return [order, items, events]. */
$placeOrder = static function (array $member, int $productId, int $quantity, array $overrides = []) use ($input): array {
    $userId = (int) $member['id'];
    (new WalletService())->credit($userId, '500.00', 'commission', random_int(1000000, 9999999), 'Test funds');
    (new CartService())->add($userId, $productId, $quantity);

    $result = (new OrderService())->placeFromCart($userId, $input($overrides));
    $order  = Order::find($result['order_id']);

    return [$order, Order::items($result['order_id']), Order::events($result['order_id'])];
};

return [
    'P6-T1 the checkout page carries items, payments, terms and the key' => function () use ($render, $tag, $makeProduct, $makeMember, $input): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['name' => 'Checkout Coffee', 'price' => '300.00']);

        (new WalletService())->credit($userId, '1000.00', 'commission', 501, 'Test funds');
        (new CartService())->add($userId, $product, 2);

        $book   = new AddressService();
        $book->save($userId, [
            'recipient_name' => 'Juan Dela Cruz',
            'phone'          => '0917 000 0000',
            'address_line'   => '12 Mabini Street',
            'city'           => 'Makati',
            'province'       => 'Metro Manila',
        ]);

        test_signed_in_as($member);

        $state = (new CartService())->state($userId, true);
        $html  = $render('member', 'shop/checkout', [
            'page_title'      => 'Checkout',
            'current_nav'     => 'cart',
            'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
            'page_js'         => ['assets/js/app-checkout.js'],
            'state'           => $state,
            'addresses'       => $book->forUser($userId),
            'selected'        => $book->defaultFor($userId),
            'prefill'         => [
                'billing_name'      => 'Juan Dela Cruz',
                'billing_phone'     => '0917 000 0000',
                'shipping_name'     => 'Juan Dela Cruz',
                'shipping_phone'    => '0917 000 0000',
                'shipping_address'  => '12 Mabini Street',
                'shipping_barangay' => '',
                'shipping_city'     => 'Makati',
                'shipping_province' => 'Metro Manila',
                'shipping_postal'   => '',
                'notes_member'      => '',
            ],
            'wallet'          => ['balance' => 1000.0, 'withdrawable' => 1000.0],
            'payment_methods' => [
                'ewallet' => ['label' => 'E-wallet', 'icon' => 'bx-wallet', 'balance' => 1000.0, 'hint' => 'Pay immediately from your wallet balance.', 'available' => true],
                'gcash'   => ['label' => 'GCash', 'icon' => 'bx-mobile-alt', 'hint' => 'Send the payment, then upload the proof (Phase 7).', 'available' => true],
                'maya'    => ['label' => 'Maya', 'icon' => 'bx-mobile', 'hint' => 'Send the payment, then upload the proof (Phase 7).', 'available' => true],
                'bank'    => ['label' => 'Bank transfer', 'icon' => 'bx-building-bank', 'hint' => 'Transfer to the company account.', 'available' => true],
            ],
            'terms_version'   => OrderService::TERMS_VERSION,
            'idempotency_key' => 'checkout-key-123',
        ]);

        assert_contains('Checkout', $html);
        assert_contains('Checkout Coffee', $html);
        assert_contains(money(600), $html);
        assert_contains('Saved addresses', $html);
        assert_contains('Juan Dela Cruz', $html);
        assert_contains('12 Mabini Street', $html);
        assert_contains('Selected', $html);
        assert_contains('name="shipping_name"', $html);
        assert_contains('value="Juan Dela Cruz"', $html);
        assert_contains('E-wallet', $html);
        assert_contains('GCash', $html);
        assert_contains('Maya', $html);
        assert_contains('Bank transfer', $html);
        assert_contains('name="payment_method"', $html);
        assert_contains('value="ewallet"', $html);
        assert_contains('checked', $html, 'the affordable e-wallet is preselected');
        assert_contains('name="terms"', $html);
        assert_contains('terms (v1)', $html);
        assert_contains('name="idempotency_key"', $html);
        assert_contains('value="checkout-key-123"', $html);
        assert_contains('name="save_address"', $html);
        assert_contains('data-wallet-balance', $html, 'the e-wallet option shows the balance');
        assert_contains(money(1000), $html);
        assert_contains('app-checkout.js', $html);
        assert_contains('checked', $tag($html, 'terms'), 'the terms start ticked');

        $placeOrder = $tag($html, 'placeOrder', 'button');
        assert_true($placeOrder !== '', 'the Place order button must render');
        assert_not_contains('disabled', $placeOrder, 'the button starts enabled');

        assert_contains('data-terms-hint', $html);
        assert_contains('Place order', $html);
        assert_contains('action="' . url('checkout') . '"', $html);
    },

    'P6-T5 an unaffordable e-wallet is disabled and manual is preselected' => function () use ($render, $makeProduct, $makeMember): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '300.00']);
        (new CartService())->add($userId, $product, 1);

        test_signed_in_as($member);

        $state = (new CartService())->state($userId, true);
        $html  = $render('member', 'shop/checkout', [
            'page_title'      => 'Checkout',
            'current_nav'     => 'cart',
            'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
            'page_js'         => ['assets/js/app-checkout.js'],
            'state'           => $state,
            'addresses'       => [],
            'selected'        => null,
            'prefill'         => [
                'billing_name' => 'Juan', 'billing_phone' => '', 'shipping_name' => 'Juan',
                'shipping_phone' => '', 'shipping_address' => '', 'shipping_barangay' => '',
                'shipping_city' => '', 'shipping_province' => '', 'shipping_postal' => '',
                'notes_member' => '',
            ],
            'wallet'          => ['balance' => 50.0, 'withdrawable' => 0.0],
            'payment_methods' => [
                'ewallet' => ['label' => 'E-wallet', 'icon' => 'bx-wallet', 'balance' => 50.0, 'hint' => 'Not enough for this order — choose another method or top up.', 'available' => false],
                'gcash'   => ['label' => 'GCash', 'icon' => 'bx-mobile-alt', 'hint' => 'Send the payment.', 'available' => true],
                'maya'    => ['label' => 'Maya', 'icon' => 'bx-mobile', 'hint' => 'Send the payment.', 'available' => true],
                'bank'    => ['label' => 'Bank transfer', 'icon' => 'bx-building-bank', 'hint' => 'Transfer.', 'available' => true],
            ],
            'terms_version'   => OrderService::TERMS_VERSION,
            'idempotency_key' => 'key-short',
        ]);

        assert_contains('data-wallet-balance', $html);
        assert_contains('Balance ' . money(50), $html);
        assert_contains('is-short', $html);
        assert_contains('Not enough for this order', $html);
        assert_contains('disabled', $html);
        assert_contains('value="gcash"', $html);
        assert_contains('checked', $html, 'a manual method is preselected');
        assert_contains('Save this address to my address book', $html);
    },

    'P6-T6 checkout errors render inline and keep the typed input' => function () use ($render, $tag, $makeProduct, $makeMember): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct();

        (new CartService())->add($userId, $product, 1);
        test_signed_in_as($member);

        errors_set([
            'shipping_name' => 'Recipient name is required.',
            'shipping_address' => 'Delivery address is required.',
            'terms' => 'Please accept the terms to place your order.',
            'checkout' => 'Please fix the highlighted checkout fields.',
        ]);
        old_payload_set([
            'billing_name'      => 'Typed Buyer',
            'shipping_name'     => '',
            'shipping_address'  => '',
            'payment_method'    => 'gcash',
            'idempotency_key'   => 'retained-key',
            'terms'             => '',
        ]);

        $state = (new CartService())->state($userId, true);
        $html  = $render('member', 'shop/checkout', [
            'page_title'      => 'Checkout',
            'current_nav'     => 'cart',
            'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
            'page_js'         => ['assets/js/app-checkout.js'],
            'state'           => $state,
            'addresses'       => [],
            'selected'        => null,
            'prefill'         => [
                'billing_name' => 'Prefill', 'billing_phone' => '', 'shipping_name' => 'Prefill',
                'shipping_phone' => '', 'shipping_address' => 'Prefill street', 'shipping_barangay' => '',
                'shipping_city' => '', 'shipping_province' => '', 'shipping_postal' => '',
                'notes_member' => '',
            ],
            'wallet'          => ['balance' => 0.0, 'withdrawable' => 0.0],
            'payment_methods' => [
                'ewallet' => ['label' => 'E-wallet', 'icon' => 'bx-wallet', 'balance' => 0.0, 'hint' => 'Not enough for this order.', 'available' => false],
                'gcash'   => ['label' => 'GCash', 'icon' => 'bx-mobile-alt', 'hint' => 'Send the payment.', 'available' => true],
            ],
            'terms_version'   => OrderService::TERMS_VERSION,
            'idempotency_key' => 'fresh-key',
        ]);

        assert_contains('Please fix the highlighted checkout fields.', $html);
        assert_contains('Recipient name is required.', $html);
        assert_contains('Delivery address is required.', $html);
        assert_contains('Please accept the terms to place your order.', $html);
        assert_contains('value="Typed Buyer"', $html, 'the posted billing name is kept');
        assert_contains('value="retained-key"', $html, 'the same idempotency key is reused');
        assert_contains('value="gcash"', $html);
        assert_contains('is-invalid', $html);
        assert_not_contains('value="Prefill"', $html, 'posted values beat the prefill');

        /* The unticked terms survive the failed submit and gate the button. */
        assert_not_contains('checked', $tag($html, 'terms'));
        $placeOrder = $tag($html, 'placeOrder', 'button');
        assert_true($placeOrder !== '', 'the Place order button must render');
        assert_contains('disabled', $placeOrder);
        assert_contains('Tick the terms above to place your order.', $html);
    },

    'P6-T6 the checkout script gates Place order on the terms checkbox' => function (): void {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app-checkout.js');

        assert_contains('terms.checked', $js);
        assert_contains('button.disabled = !accepted;', $js);
        assert_contains("terms.addEventListener('change', sync);", $js);
        assert_contains("terms.addEventListener('input', sync);", $js);
        assert_contains("window.addEventListener('pageshow', sync);", $js);
        assert_contains("hint.classList.toggle('d-none', accepted);", $js);

        /* A CSS fallback keeps the button inert before the script runs. */
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/orders.css');

        assert_contains(':has(#terms:not(:checked)) [data-checkout-submit]', $css);
        assert_contains('pointer-events: none;', $css);
    },

    'P6-T9 the orders list renders rows, filters and the empty state' => function () use ($render, $makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);

        [$order] = $placeOrder($member, $product, 1, ['payment_method' => 'gcash']);

        test_signed_in_as($member);

        $filters = Order::normaliseFilters([]);
        $pager   = new Paginator(1, 10, 1);
        $html    = $render('member', 'shop/orders', [
            'page_title'  => 'My orders',
            'current_nav' => 'orders',
            'orders'      => Order::listForUser((int) $member['id'], $filters, $pager),
            'pager'       => $pager,
            'filters'     => $filters,
        ]);

        assert_contains('My orders', $html);
        assert_contains((string) $order['order_no'], $html);
        assert_contains('Pending', $html);
        assert_contains('GCash', $html);
        assert_contains(money(40), $html);
        assert_contains('href="' . url('orders/' . (int) $order['id']) . '"', $html);
        assert_contains('name="status"', $html);
        assert_contains('name="q"', $html);
        assert_contains('Filter', $html);
        assert_not_contains('No orders yet', $html);

        $empty = $render('member', 'shop/orders', [
            'page_title'  => 'My orders',
            'current_nav' => 'orders',
            'orders'      => [],
            'pager'       => new Paginator(0),
            'filters'     => Order::normaliseFilters([]),
        ]);

        assert_contains('No orders yet', $empty);
        assert_contains('Browse the shop', $empty);
    },

    'P6-T9 order rows escape the snapshot product name' => function () use ($render, $makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['name' => '<script>alert(1)</script> Coffee']);

        [$order, $items] = $placeOrder($member, $product, 1, ['payment_method' => 'gcash']);

        test_signed_in_as($member);

        $html = $render('member', 'shop/order', [
            'page_title'  => 'Order ' . $order['order_no'],
            'current_nav' => 'orders',
            'order'       => $order,
            'lines'       => $items,
            'events'      => Order::events((int) $order['id']),
            'can_cancel'  => true,
        ]);

        assert_not_contains('<script>alert(1)</script>', $html);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    },

    'P6-T1 the paid order page shows items, payment, delivery and history' => function () use ($render, $makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['name' => 'Paid Coffee', 'sku' => 'PAID-1', 'price' => '25.00']);

        [$order, $items, $events] = $placeOrder($member, $product, 2);

        test_signed_in_as($member);

        $html = $render('member', 'shop/order', [
            'page_title'  => 'Order ' . $order['order_no'],
            'current_nav' => 'orders',
            'order'       => $order,
            'lines'       => $items,
            'events'      => $events,
            'can_cancel'  => false,
        ]);

        assert_contains((string) $order['order_no'], $html);
        assert_contains('Paid', $html);
        assert_contains('Payment received', $html);
        assert_contains('Paid Coffee', $html);
        assert_contains('PAID-1', $html);
        assert_contains(money(50), $html);
        assert_contains('Delivery', $html);
        assert_contains('Juan Dela Cruz', $html);
        assert_contains('12 Mabini Street', $html);
        assert_contains('Payment', $html);
        assert_contains('E-wallet', $html);
        assert_contains('History', $html);
        assert_contains('Order placed', $html);
        assert_contains('Paid from the e-wallet', $html);
        assert_not_contains('Cancel order', $html, 'a paid order is not cancellable online');
        assert_not_contains('Payment proof upload', $html);
    },

    'P6-T11 the pending order page shows instructions and the cancel form' => function () use ($render, $makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '75.00']);

        [$order, $items, $events] = $placeOrder($member, $product, 1, ['payment_method' => 'gcash']);

        test_signed_in_as($member);

        $html = $render('member', 'shop/order', [
            'page_title'  => 'Order ' . $order['order_no'],
            'current_nav' => 'orders',
            'page_css'    => [
                'assets/css/shop.css',
                'assets/css/orders.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
            ],
            'order'       => $order,
            'lines'       => $items,
            'events'      => $events,
            'can_cancel'  => true,
            'proofs'      => [],
            'latest_proof' => null,
            'window'      => [
                'open'     => true,
                'reason'   => 'open',
                'deadline' => (string) $order['payment_deadline'],
                'used'     => 0,
                'left'     => 3,
                'max'      => 3,
            ],
        ]);

        assert_contains('Payment pending', $html);
        assert_contains('GCash number', $html);
        assert_contains((string) setting('payment_gcash_number', ''), $html);
        assert_contains('Pay by', $html);
        assert_contains('Cancel order', $html);
        assert_contains('name="reason"', $html);
        assert_contains('action="' . url('orders/' . (int) $order['id'] . '/cancel') . '"', $html);
        assert_contains('name="_csrf"', $html);

        /* Phase 7: the proof panel replaces the old "arrives later" note. */
        assert_contains('Upload your payment proof', $html);
        assert_contains('enctype="multipart/form-data"', $html);
        assert_contains('action="' . url('orders/' . (int) $order['id'] . '/proof') . '"', $html);
        assert_contains('name="amount_sent"', $html);
        assert_contains('name="reference_no"', $html);
        assert_contains('name="transfer_date"', $html);
        assert_contains('name="proof_image"', $html);
        assert_contains('Submit payment proof', $html);
        assert_contains('attempt', strtolower($html));
        assert_not_contains('arrives with the', $html);

        /* Cancellation asks through the shared SweetAlert2 modal, not confirm(). */
        assert_contains('data-confirm', $html);
        assert_contains('data-confirm-title="Cancel this order?"', $html);
        assert_contains('data-confirm-button="Cancel order"', $html);
        assert_contains('data-confirm-tone="danger"', $html);
        assert_not_contains('onsubmit="return confirm', $html);
        assert_contains('sweetalert2.js', $html);
        assert_contains('app-confirm.js', $html);
    },

    'P6-T11 the confirmation script uses SweetAlert2 with a native fallback' => function (): void {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app-confirm.js');

        assert_contains("'form[data-confirm]'", $js);
        assert_contains('window.Swal.fire', $js);
        assert_contains('form.submit()', $js);
        assert_contains('window.confirm', $js);
        assert_contains('result.isConfirmed', $js);
        assert_contains('didOpen', $js);
        assert_contains("button.style.setProperty('background-color', '#d33', 'important');", $js);
    },

    'P6-T11 a cancelled order shows its reason and no cancel form' => function () use ($render, $makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '30.00']);

        [$order] = $placeOrder($member, $product, 1, ['payment_method' => 'bank']);
        (new OrderService())->cancelByMember((int) $member['id'], (int) $order['id'], 'Ordered by mistake');

        $order = Order::find((int) $order['id']);

        test_signed_in_as($member);

        $html = $render('member', 'shop/order', [
            'page_title'  => 'Order ' . $order['order_no'],
            'current_nav' => 'orders',
            'order'       => $order,
            'lines'       => Order::items((int) $order['id']),
            'events'      => Order::events((int) $order['id']),
            'can_cancel'  => Order::canMemberCancel($order),
        ]);

        assert_contains('Order cancelled', $html);
        assert_contains('Ordered by mistake', $html);
        assert_contains('Cancelled', $html);
        assert_not_contains('Cancel order', $html);
    },

    'P6-T10 the admin desk lists orders with stats and member context' => function () use ($render, $sidebar, $makeProduct, $makeMember, $placeOrder): void {
        $admin   = test_user('ov_admin', 'admin');
        $member  = $makeMember();
        $product = $makeProduct(['price' => '60.00']);

        [$order] = $placeOrder($member, $product, 2);

        test_signed_in_as($admin);

        $filters = Order::normaliseFilters([]);
        $pager   = new Paginator(1, 20, 1);
        $html    = $render('admin', 'admin/orders/index', [
            'page_title'  => 'Orders',
            'current_nav' => 'admin.orders',
            'orders'      => Order::adminList($filters, $pager),
            'pager'       => $pager,
            'filters'     => $filters,
            'stats'       => Order::stats(),
        ]);

        assert_contains('Orders', $html);
        assert_contains('Awaiting payment', $html);
        assert_contains('Paid revenue', $html);
        assert_contains(money(120), $html);
        assert_contains((string) $order['order_no'], $html);
        assert_contains((string) $member['username'], $html);
        assert_contains('Paid', $html);
        assert_contains(url('admin/orders/' . (int) $order['id']), $html);
        assert_contains('name="q"', $html);
        assert_contains('name="method"', $html);

        $nav = $sidebar($html);
        assert_contains('Orders', $nav);
        assert_contains('E-wallet monitor', $nav);
        assert_contains('menu-item active', $nav);
    },

    'P6-T10 the admin order page shows the member, items and timeline' => function () use ($render, $makeProduct, $makeMember, $placeOrder): void {
        $admin   = test_user('ov_admin', 'admin');
        $member  = $makeMember();
        $product = $makeProduct(['name' => 'Admin Coffee', 'price' => '25.00']);

        [$order, $items, $events] = $placeOrder($member, $product, 2);

        test_signed_in_as($admin);

        $html = $render('admin', 'admin/orders/show', [
            'page_title'  => 'Order ' . $order['order_no'],
            'current_nav' => 'admin.orders',
            'order'       => $order,
            'lines'       => $items,
            'events'      => $events,
            'member'      => Wallet::account((int) $member['id']),
        ]);

        assert_contains((string) $order['order_no'], $html);
        assert_contains((string) $member['username'], $html);
        assert_contains('E-wallet', $html);
        assert_contains('Open wallet', $html);
        assert_contains('Admin Coffee', $html);
        assert_contains(money(50), $html);
        assert_contains('Status history', $html);
        assert_contains('Order placed', $html);
        assert_contains('Delivery', $html);
        assert_contains('12 Mabini Street', $html);
    },

    'P6-T7 the profile page renders the address book and the form' => function () use ($render, $makeMember): void {
        $member = $makeMember();
        $book   = new AddressService();

        $book->save((int) $member['id'], [
            'label'          => 'Home',
            'recipient_name' => 'Juan Dela Cruz',
            'phone'          => '0917 000 0000',
            'address_line'   => '12 Mabini Street',
            'city'           => 'Makati',
            'province'       => 'Metro Manila',
        ]);

        test_signed_in_as($member);

        $html = $render('member', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => 'profile',
            'page_css'    => ['assets/css/orders.css', 'assets/vendor/libs/sweetalert2/sweetalert2.css'],
            'page_js'     => ['assets/vendor/libs/sweetalert2/sweetalert2.js', 'assets/js/app-confirm.js'],
            'user'        => $member,
            'addresses'   => $book->forUser((int) $member['id']),
            'editing'     => null,
            'posted'      => [],
        ]);

        assert_contains('Profile', $html);
        assert_contains('Address book', $html);
        assert_contains('Home', $html);
        assert_contains('Juan Dela Cruz', $html);
        assert_contains('12 Mabini Street', $html);
        assert_contains('Default', $html);
        assert_contains('data-confirm-title="Delete this address?"', $html);
        assert_not_contains('onsubmit="return confirm', $html);
        assert_contains('app-confirm.js', $html);
        assert_contains('Add an address', $html);
        assert_contains('name="recipient_name"', $html);
        assert_contains('name="address_line"', $html);
        assert_contains('name="is_default"', $html);
        assert_contains('action="' . url('profile/address') . '"', $html);
        assert_contains('name="_csrf"', $html);
        assert_contains('Save profile', $html);
    },

    'P6-T7 editing an address opens the form with its values' => function () use ($render, $makeMember): void {
        $member = $makeMember();
        $book   = new AddressService();

        $id = $book->save((int) $member['id'], [
            'recipient_name' => 'Maria Santos',
            'address_line'   => '99 Editing Lane',
            'city'           => 'Quezon City',
        ]);

        test_signed_in_as($member);

        $html = $render('member', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => 'profile',
            'user'        => $member,
            'addresses'   => $book->forUser((int) $member['id']),
            'editing'     => Address::findOwned($id, (int) $member['id']),
            'posted'      => [],
        ]);

        assert_contains('Edit address', $html);
        assert_contains('value="Maria Santos"', $html);
        assert_contains('value="99 Editing Lane"', $html);
        assert_contains('name="address_id" value="' . $id . '"', $html);
        assert_contains('Update address', $html);
        assert_contains('profile?edit=' . $id, $html);
    },

    'P6-T1 the member menu now offers Orders and Profile' => function () use ($render, $sidebar, $makeMember): void {
        test_signed_in_as($makeMember());

        $html = $render('member', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => 'profile',
            'user'        => auth_user(),
            'addresses'   => [],
            'editing'     => null,
            'posted'      => [],
        ]);

        $nav = $sidebar($html);
        assert_contains('My orders', $nav);
        assert_contains('Profile', $nav);
        assert_contains('E-wallet', $nav);
        assert_contains('href="' . url('payouts') . '"', $nav, 'Phase 13 makes the payout page live');
        assert_contains('Earnings', $nav, 'Phase 10 makes the earnings statement live');
        assert_contains('href="' . url('earnings') . '"', $nav);
    },
];
