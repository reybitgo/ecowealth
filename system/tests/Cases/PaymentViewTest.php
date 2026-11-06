<?php
declare(strict_types=1);

/* ==============================================================
 *  PaymentViewTest — proof panel, review pane and settings page
 *  (plan §5.4, §7 Phase 7; supports P7-T1/T3/T5/T6/T8/T10/T11/T12)
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

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Proof Product ' . bin2hex(random_bytes(3)),
        'sku'    => 'PV-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '40.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('pv_' . bin2hex(random_bytes(4)));
};

$checkout = static function (array $overrides = []): array {
    return $overrides + [
        'billing_name'     => 'Juan Dela Cruz',
        'shipping_name'    => 'Juan Dela Cruz',
        'shipping_address' => '12 Mabini Street',
        'shipping_city'    => 'Makati',
        'payment_method'   => 'gcash',
        'idempotency_key'  => bin2hex(random_bytes(16)),
        'terms'            => '1',
    ];
};

$placeOrder = static function (array $member, int $productId, string $method = 'gcash') use ($checkout): int {
    (new CartService())->add((int) $member['id'], $productId, 1);

    return (new OrderService())->placeFromCart((int) $member['id'], $checkout(['payment_method' => $method]))['order_id'];
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

$upload = static function (): array {
    $tmp = (string) tempnam(sys_get_temp_dir(), 'evproof');
    file_put_contents($tmp, TEST_PNG_BYTES);

    return [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ];
};

/** Upload a proof with a chosen reference. */
$submit = static function (int $userId, int $orderId, string $reference = 'VIEW-REF-1') use ($upload): void {
    (new PaymentService())->submitProof($userId, $orderId, $upload(), [
        'amount_sent'   => '40.00',
        'reference_no'  => $reference,
        'transfer_date' => date('Y-m-d'),
    ]);
};

/** Render the member order page exactly as OrderController::show builds it. */
$memberOrderHtml = static function (array $member, int $orderId) use ($render): string {
    $order  = Order::findForUser($orderId, (int) $member['id']);
    $proofs = PaymentProof::forOrder($orderId);

    return $render('member', 'shop/order', [
        'page_title'  => 'Order ' . (string) $order['order_no'],
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
        'order'        => $order,
        'lines'        => Order::items($orderId),
        'events'       => Order::events($orderId),
        'can_cancel'   => Order::canMemberCancel($order),
        'proofs'       => $proofs,
        'latest_proof' => $proofs === [] ? null : $proofs[count($proofs) - 1],
        'window'       => (new PaymentService())->windowFor($order),
    ]);
};

/** Render the admin order page exactly as AdminOrderController::show builds it. */
$adminOrderHtml = static function (int $orderId) use ($render): string {
    $admin   = test_user('pv_admin', 'admin');
    $order   = Order::find($orderId);
    $proofs  = PaymentProof::forOrder($orderId);
    $latest  = $proofs === [] ? null : $proofs[count($proofs) - 1];
    $isSelf  = (int) $order['user_id'] === (int) $admin['id'];

    return $render('admin', 'admin/orders/show', [
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
        ],
        'order'           => $order,
        'lines'           => Order::items($orderId),
        'events'          => Order::events($orderId),
        'member'          => Wallet::account((int) $order['user_id']),
        'proofs'          => $proofs,
        'latest_proof'    => $latest,
        'window'          => (new PaymentService())->windowFor($order),
        'is_self'         => $isSelf,
        'can_review'      => !$isSelf
            && (string) $order['status'] === 'payment_review'
            && $latest !== null
            && (string) $latest['status'] === 'pending',
        'can_cancel'      => !$isSelf && Order::canAdminCancel($order),
        'refund_on_cancel' => !$isSelf && Order::isRefundable($order),
        'reject_reasons'  => PaymentService::REJECT_REASONS,
        'cancel_reasons'  => PaymentService::CANCEL_REASONS,
    ]);
};

return [
    /* ---------- Member order page ---------------------------------- */

    'P7-T1 the pending page offers the proof upload with its fields' => function () use ($render, $tag, $makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);

        test_signed_in_as($member);

        $order = Order::find($orderId);
        $html  = $render('member', 'shop/order', [
            'page_title'   => 'Order',
            'current_nav'  => 'orders',
            'order'        => $order,
            'lines'        => Order::items($orderId),
            'events'       => Order::events($orderId),
            'can_cancel'   => true,
            'proofs'       => [],
            'latest_proof' => null,
            'window'       => (new PaymentService())->windowFor($order),
        ]);

        assert_contains('Payment pending', $html);
        assert_contains('Upload your payment proof', $html);
        assert_contains('action="' . url('orders/' . $orderId . '/proof') . '"', $html);
        assert_contains('enctype="multipart/form-data"', $html);
        assert_contains('name="amount_sent"', $html);
        assert_contains('value="40.00"', $html, 'the amount is prefilled with the order total');
        assert_contains('name="reference_no"', $html);
        assert_contains('name="transfer_date"', $html);
        assert_contains('name="proof_image"', $html);
        assert_contains('accept=".jpg,.jpeg,.png,.webp,.pdf"', $html);
        assert_contains('up to 5&nbsp;MB', $html);
        assert_contains('attempt', strtolower($html));
        assert_contains('name="_csrf"', $html);
        assert_contains('Submit payment proof', $html);

        /* No attempts table until a proof exists. */
        assert_not_contains('Payment attempts', $html);
    },

    'P7-T8 a closed window hides the form and explains why' => function () use ($render, $makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);

        db()->prepare('UPDATE shop_orders SET payment_deadline = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 60), $orderId]);

        test_signed_in_as($member);

        $order = Order::find($orderId);
        $html  = $render('member', 'shop/order', [
            'page_title'   => 'Order',
            'current_nav'  => 'orders',
            'order'        => $order,
            'lines'        => Order::items($orderId),
            'events'       => Order::events($orderId),
            'can_cancel'   => true,
            'proofs'       => [],
            'latest_proof' => null,
            'window'       => (new PaymentService())->windowFor($order),
        ]);

        assert_contains('The payment window closed on', $html);
        assert_not_contains('Upload your payment proof', $html);
        assert_not_contains('name="proof_image"', $html);
        assert_contains('cancel this order below and place a new one', $html);
        assert_contains('Cancel order', $html);
    },

    'P7-T1 the page shows the review state without an upload form' => function () use ($render, $makeProduct, $makeMember, $placeOrder, $submit, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);

        $submit((int) $member['id'], $orderId, 'GCASH-777');
        test_signed_in_as($member);

        $html = $memberOrderHtml($member, $orderId);

        assert_contains('We are verifying your payment', $html);
        assert_contains('Attempt 1 of 3', $html);
        assert_contains('GCASH-777', $html);
        assert_not_contains('Upload your payment proof', $html);
        assert_not_contains('name="proof_image"', $html);
        assert_not_contains('Cancel order', $html, 'an order under review is not cancellable online');

        assert_contains('Payment attempts', $html);
        assert_contains('Awaiting review', $html);
    },

    'P7-T5 the rejected page shows the reason, the window and the retry form' => function () use ($render, $makeProduct, $makeMember, $placeOrder, $submit, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);
        $admin   = test_user('pv_admin', 'admin');

        $submit((int) $member['id'], $orderId, 'FIRST-REF');
        (new PaymentService())->reject((int) $admin['id'], $orderId, 'wrong_amount', 'Only ₱20 arrived');

        test_signed_in_as($member);

        $html = $memberOrderHtml($member, $orderId);

        assert_contains('Payment needs correction', $html);
        assert_contains('Only ₱20 arrived', $html);
        assert_contains('Correct and re-upload by', $html);
        assert_contains('Upload your payment proof', $html);
        assert_contains('Attempt <strong>2</strong>', $html);
        assert_contains('Rejected', $html);
        assert_contains('Cancel order', $html, 'a failed payment can still be cancelled');

        /* The attempt row is in the payments table too. */
        assert_contains('Payment attempts', $html);
        assert_contains('FIRST-REF', $html);
    },

    'P7-T3 an exhausted attempt cap explains itself and hides the form' => function () use ($render, $makeProduct, $makeMember, $placeOrder, $submit, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);
        $admin   = test_user('pv_admin', 'admin');
        $service = new PaymentService();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $submit((int) $member['id'], $orderId, 'CAP-REF-' . $attempt);
            $service->reject((int) $admin['id'], $orderId, 'unclear_proof', 'Try again');
        }

        test_signed_in_as($member);

        $html = $memberOrderHtml($member, $orderId);

        assert_contains('Payment needs correction', $html);
        assert_contains('used all 3 payment-proof attempts', $html);
        assert_contains('No proof attempts left', $html);
        assert_not_contains('Upload your payment proof', $html);
        assert_not_contains('name="proof_image"', $html);
        assert_contains('3 of 3', $html);
        assert_contains('CAP-REF-3', $html);
    },

    'P7-T1 attempt rows escape a hostile reference and reason' => function () use ($render, $makeProduct, $makeMember, $placeOrder, $submit, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);
        $admin   = test_user('pv_admin', 'admin');

        $submit((int) $member['id'], $orderId, 'SAFE-REF');
        (new PaymentService())->reject(
            (int) $admin['id'],
            $orderId,
            'other',
            '<script>alert("x")</script>'
        );

        test_signed_in_as($member);

        $html = $memberOrderHtml($member, $orderId);

        assert_not_contains('<script>alert("x")</script>', $html);
        assert_contains('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
    },

    'P7-T4 the paid page shows the verified reference and no proof panel' => function () use ($render, $makeProduct, $makeMember, $placeOrder, $submit, $memberOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);
        $admin   = test_user('pv_admin', 'admin');

        $submit((int) $member['id'], $orderId, 'PAID-REF');
        (new PaymentService())->verify((int) $admin['id'], $orderId, [
            'amount_received'   => '40.00',
            'payment_reference' => 'PAID-REF',
        ]);

        test_signed_in_as($member);

        $html = $memberOrderHtml($member, $orderId);

        assert_contains('Payment received', $html);
        assert_contains('PAID-REF', $html);
        assert_contains('Paid', $html);
        assert_not_contains('Upload your payment proof', $html);
        assert_not_contains('Cancel order', $html);
        assert_contains('Verified', $html);
    },

    'P7-T12 a cancelled order says what happened to the money' => function () use ($render, $makeProduct, $makeMember, $placeOrder, $fund, $memberOrderHtml): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '40.00']);

        $fund($userId, '40.00');
        $orderId = $placeOrder($member, $product, 'ewallet');

        (new PaymentService())->cancelByAdmin(
            (int) test_user('pv_admin', 'admin')['id'],
            $orderId,
            'customer_request',
            'Per the member request'
        );

        test_signed_in_as($member);

        $html = $memberOrderHtml($member, $orderId);

        assert_contains('Order cancelled', $html);
        assert_contains('Per the member request', $html);
        assert_contains('wallet refund, if owed, appears in your e-wallet statement', $html);
        assert_not_contains('Cancel order', $html);
    },

    /* ---------- Admin review pane ---------------------------------- */

    'P7-T4 the review pane offers verify and reject with confirmations' => function () use ($render, $tag, $makeProduct, $makeMember, $placeOrder, $submit, $adminOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);

        $submit((int) $member['id'], $orderId, 'GCASH-REVIEW-1');
        test_signed_in_as(test_user('pv_admin', 'admin'));

        $html = $adminOrderHtml($orderId);

        assert_contains('Payment review', $html);
        assert_contains('Awaiting review', $html);
        assert_contains('Verify payment', $html);
        assert_contains('Reject proof', $html);
        assert_contains('name="to" value="paid"', $html);
        assert_contains('name="to" value="payment_failed"', $html);
        assert_contains('action="' . url('admin/orders/' . $orderId . '/transition') . '"', $html);
        assert_contains('name="amount_received"', $html);
        assert_contains('value="40.00"', $html, 'the amount is prefilled with the total');
        assert_contains('name="payment_reference"', $html);
        assert_contains('value="GCASH-REVIEW-1"', $html, 'the claimed reference is prefilled');
        assert_contains('name="reason_code"', $html);
        assert_contains('The proof is unclear or unreadable', $html);

        /* Every attempt links to the guarded stream, never a public path. */
        $proof = PaymentProof::latestFor($orderId);
        assert_contains('href="' . url('admin/proofs/' . (int) $proof['id']) . '"', $html);
        assert_not_contains('uploads/proofs/', $html, 'the stored path is never rendered');

        /* Both actions go through the shared modal, never confirm(). */
        assert_contains('data-confirm-title="Verify this payment?"', $html);
        assert_contains('data-confirm-title="Reject this payment proof?"', $html);
        assert_contains('data-confirm-tone="danger"', $html);
        assert_not_contains('onsubmit="return confirm', $html);
        assert_contains('sweetalert2.js', $html);
        assert_contains('app-confirm.js', $html);
    },

    'P7-T10 the admin own order shows the segregation banner instead of actions' => function () use ($makeProduct, $placeOrder, $submit, $adminOrderHtml): void {
        $admin   = test_user('pv_admin', 'admin');
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($admin, $product);

        $submit((int) $admin['id'], $orderId, 'SELF-REF-1');
        test_signed_in_as($admin);

        $html = $adminOrderHtml($orderId);

        assert_contains('You placed this order.', $html);
        assert_contains('Payment review', $html);
        assert_not_contains('name="to" value="paid"', $html);
        assert_not_contains('name="to" value="payment_failed"', $html);
        assert_not_contains('Verify and mark paid', $html);
        assert_not_contains('name="to" value="cancelled"', $html);
    },

    'P7-T12 a paid order shows the refunding cancel action' => function () use ($makeProduct, $makeMember, $placeOrder, $fund, $adminOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);

        $fund((int) $member['id'], '40.00');
        $orderId = $placeOrder($member, $product, 'ewallet');
        test_signed_in_as(test_user('pv_admin', 'admin'));

        $html = $adminOrderHtml($orderId);

        assert_contains('Cancel order', $html);
        assert_contains('refunds', $html);
        assert_contains(money(40), $html);
        assert_contains('The wallet refund cannot be undone.', $html);
        assert_contains('name="to" value="cancelled"', $html);
        assert_contains('The member asked to cancel', $html);
        assert_contains('Payment verified', $html);
        assert_contains('Fulfilment actions', $html);
        assert_not_contains('Verify and mark paid', $html);
    },

    'P7-T5 the rejected order tells the admin the correction window is open' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $adminOrderHtml): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '40.00']);
        $orderId = $placeOrder($member, $product);
        $admin   = test_user('pv_admin', 'admin');

        $submit((int) $member['id'], $orderId, 'REJECT-REF');
        (new PaymentService())->reject((int) $admin['id'], $orderId, 'not_received', 'No transfer found');
        test_signed_in_as($admin);

        $html = $adminOrderHtml($orderId);

        assert_contains('The proof was rejected', $html);
        assert_contains('No transfer found', $html);
        assert_contains('The member can re-upload until', $html);
        assert_contains('2 attempts left', $html);
        assert_not_contains('Verify and mark paid', $html);
        assert_contains('Cancel order', $html);
    },

    /* ---------- Admin settings page -------------------------------- */

    'P7-T4 the settings page renders the accounts, windows and save' => function () use ($render): void {
        test_signed_in_as(test_user('pv_admin', 'admin'));

        $html = $render('admin', 'admin/settings/index', [
            'page_title'     => 'Settings',
            'current_nav'    => 'admin.settings',
            'page_css'       => ['assets/css/orders.css'],
            'settings'       => setting_all(),
            'account_groups' => (new ReflectionClass(AdminSettingsController::class))->getConstant('ACCOUNT_GROUPS'),
            'window_fields'  => (new ReflectionClass(AdminSettingsController::class))->getConstant('WINDOW_FIELDS'),
            'shop_enabled'   => true,
            'manual_open'    => false,
        ]);

        assert_contains('Receiving accounts', $html);
        assert_contains('GCash number', $html);
        assert_contains('Maya number', $html);
        assert_contains('Bank', $html);
        assert_contains('name="payment_gcash_number"', $html);
        assert_contains('name="payment_maya_number"', $html);
        assert_contains('name="payment_bank_account_number"', $html);
        assert_contains('name="shop_payment_deadline_hours"', $html);
        assert_contains('name="shop_correction_window_hours"', $html);
        assert_contains('name="shop_max_proof_attempts"', $html);
        assert_contains('value="' . (string) setting('shop_payment_deadline_hours', '24') . '"', $html);
        assert_contains('name="shop_enabled"', $html);
        assert_contains('name="_csrf"', $html);
        assert_contains('action="' . url('admin/settings') . '"', $html);
        assert_contains('Save settings', $html);
        assert_contains('placeholders', $html, 'the placeholder warning is shown');
        assert_contains('bin/shop_expire.php', $html);
    },

    'P7-T4 the settings form echoes field errors and typed values' => function () use ($render): void {
        test_signed_in_as(test_user('pv_admin', 'admin'));

        errors_set([
            'shop_payment_deadline_hours' => 'Payment window (hours) must be at least 1.',
            'payment_gcash_number'        => 'GCash number must be at most 120 characters.',
        ]);
        old_set([
            'shop_payment_deadline_hours' => '0',
            'payment_gcash_number'        => 'typed-number',
        ]);

        $html = $render('admin', 'admin/settings/index', [
            'page_title'     => 'Settings',
            'current_nav'    => 'admin.settings',
            'settings'       => setting_all(),
            'account_groups' => (new ReflectionClass(AdminSettingsController::class))->getConstant('ACCOUNT_GROUPS'),
            'window_fields'  => (new ReflectionClass(AdminSettingsController::class))->getConstant('WINDOW_FIELDS'),
            'shop_enabled'   => true,
            'manual_open'    => true,
        ]);

        assert_contains('must be at least 1', $html);
        assert_contains('is-invalid', $html);
        assert_contains('value="typed-number"', $html);
        assert_contains('value="0"', $html);
    },

    'P7-T4 the admin menu offers Settings and the desk links the review filter' => function () use ($render): void {
        $admin = test_user('pv_admin', 'admin');
        test_signed_in_as($admin);

        $html = $render('admin', 'admin/orders/index', [
            'page_title'  => 'Orders',
            'current_nav' => 'admin.orders',
            'orders'      => [],
            'pager'       => new Paginator(0),
            'filters'     => Order::normaliseFilters([]),
            'stats'       => Order::stats(),
        ]);

        assert_contains('Settings', $html);
        assert_contains('href="' . url('admin/settings') . '"', $html);
        assert_contains('awaiting review', $html);
        assert_contains('href="' . url('admin/orders?status=payment_review') . '"', $html);
        assert_not_contains('Payment-proof upload arrives', $html);
        assert_contains('verify or reject a manual payment proof', $html);
    },
];
