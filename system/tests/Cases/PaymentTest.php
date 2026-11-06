<?php
declare(strict_types=1);

/* ==============================================================
 *  PaymentTest — manual payment proofs and the order lifecycle
 *  (plan §5.4, §4.4, §7 Phase 7; tests P7-T1 … P7-T12)
 *
 *  Every case runs inside the harness transaction and rolls back, so it
 *  may create members, products and orders freely. Uploaded files are
 *  real files under UPLOAD_ROOT (the bootstrap points it at a scratch
 *  folder) and are removed where a case asserts on orphan files.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Payment Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'PAY-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (string $role = 'member'): array {
    return test_user('pay_' . bin2hex(random_bytes(4)), $role);
};

$fund = static function (int $userId, string $amount = '100.00', bool $withdrawable = true): void {
    (new WalletService())->credit(
        $userId,
        $amount,
        'commission',
        random_int(1000000, 9999999),
        'Test funds',
        $withdrawable
    );
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
        'notes_member'      => '',
        'payment_method'    => 'gcash',
        'idempotency_key'   => bin2hex(random_bytes(16)),
        'terms'             => '1',
    ];
};

/** Place a manual (or e-wallet) order and return the order id. */
$placeOrder = static function (int $userId, int $productId, int $quantity = 1, string $method = 'gcash') use ($checkout): int {
    (new CartService())->add($userId, $productId, $quantity);

    return (new OrderService())->placeFromCart($userId, $checkout(['payment_method' => $method]))['order_id'];
};

/** One $_FILES-shaped upload (the bootstrap PNG by default). */
$upload = static function (string $bytes = TEST_PNG_BYTES, string $name = 'proof.png'): array {
    $tmp = (string) tempnam(sys_get_temp_dir(), 'ewproof');
    file_put_contents($tmp, $bytes);

    return [
        'name'     => $name,
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen($bytes),
    ];
};

$claim = static function (array $overrides = []): array {
    return $overrides + [
        'amount_sent'   => '25.00',
        'reference_no'  => 'REF-' . strtoupper(bin2hex(random_bytes(4))),
        'transfer_date' => date('Y-m-d'),
    ];
};

/** Expire a deadline into the past without going through the UI. */
$expireDeadline = static function (int $orderId, string $column = 'payment_deadline'): void {
    db()->prepare('UPDATE shop_orders SET ' . $column . ' = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s', time() - 3600), $orderId]);
};

$proofFiles = static function (): int {
    $files = glob(Upload::root() . 'proofs/*') ?: [];

    return count($files);
};

$status = static function (int $orderId): string {
    return (string) (Order::find($orderId)['status'] ?? '');
};

$submit = static function (int $userId, int $orderId, array $overrides = [], ?array $file = null) use ($claim, $upload): array {
    return (new PaymentService())->submitProof(
        $userId,
        $orderId,
        $file ?? $upload(),
        $claim($overrides)
    );
};

return [
    /* ---------- P7-T1 / P7-T2: proof upload ------------------------ */

    'P7-T1 a valid proof moves the order to payment_review and keeps the hold' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '40.00', 'stock' => '5']);

        $orderId = $placeOrder($userId, $product, 2);
        assert_same('pending', $status($orderId));
        assert_same(3, StockService::available($product), 'two units are held');

        $before = Order::find($orderId);
        $result = $submit($userId, $orderId, ['amount_sent' => '80.00', 'reference_no' => 'GCASH-12345']);

        assert_same('payment_review', $result['status']);
        assert_same(1, $result['attempt']);
        assert_same('payment_review', $status($orderId));

        $proof = PaymentProof::latestFor($orderId);
        assert_not_null($proof);
        assert_same(1, (int) $proof['attempt_no']);
        assert_same('pending', $proof['status']);
        assert_same('80.00', number_format((float) $proof['amount_claimed'], 2, '.', ''));
        assert_same('GCASH-12345', $proof['reference_no']);
        assert_same('image/png', $proof['mime']);
        assert_true((int) $proof['size_bytes'] > 0, 'the stored size is recorded');
        assert_contains('uploads/proofs/', (string) $proof['file_path']);
        assert_true(is_file(Upload::root() . substr((string) $proof['file_path'], strlen('uploads/'))), 'the file exists');

        $after = Order::find($orderId);
        assert_same((string) $before['payment_deadline'], (string) $after['payment_deadline'], 'the deadline stamp is kept');
        assert_null($after['paid_at']);
        assert_same(3, StockService::available($product), 'the hold is unchanged while under review');

        $events = Order::events($orderId);
        $last   = end($events);
        assert_same('proof_submitted', $last['reason_code']);
        assert_same('payment_review', $last['to_status']);
        assert_same('member', $last['actor_type']);
        assert_same($userId, (int) $last['actor_id']);
        assert_contains('attempt 1 of 3', (string) $last['note']);
    },

    'P7-T1 a PDF proof is accepted and its facts are read from the file' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $upload): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00']);
        $orderId = $placeOrder($userId, $product, 1);

        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $submit($userId, $orderId, ['amount_sent' => '25.00'], $upload($pdf, 'bank-slip.pdf'));

        $proof = PaymentProof::latestFor($orderId);
        assert_same('application/pdf', $proof['mime']);
    },

    'P7-T2 a wrong file type or an oversized file is refused with no writes' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $upload, $proofFiles, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00']);
        $orderId = $placeOrder($userId, $product, 1);

        $filesBefore = $proofFiles();

        /* A PHP script renamed .png is rejected by the MIME sniff. */
        $error = assert_throws(
            static fn () => $submit($userId, $orderId, [], $upload('<?php echo "pwned";', 'proof.png')),
            PaymentException::class
        );
        assert_same('validation', $error->reason);
        assert_true(isset($error->fieldErrors['proof_image']));

        /* Over the 5 MB cap. */
        $big = str_repeat('0', UPLOAD_MAX_BYTES + 1024);
        $bigError = assert_throws(
            static fn () => $submit($userId, $orderId, [], $upload($big, 'big.png')),
            PaymentException::class
        );
        assert_true(isset($bigError->fieldErrors['proof_image']));
        assert_contains('too large', $bigError->fieldErrors['proof_image']);

        assert_same('pending', $status($orderId), 'the order is unchanged');
        assert_same(0, PaymentProof::countFor($orderId));
        assert_same($filesBefore, $proofFiles(), 'a refused upload leaves no orphan file');
    },

    'P7-T2 the claim is validated field by field' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $proofFiles): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00']);
        $orderId = $placeOrder($userId, $product, 1);

        $errors = PaymentService::validateClaim([
            'amount_sent'   => '0',
            'reference_no'  => 'x',
            'transfer_date' => date('Y-m-d', strtotime('+2 days')),
        ]);

        assert_true(isset($errors['amount_sent']), 'a zero amount is refused');
        assert_true(isset($errors['reference_no']), 'a one-character reference is refused');
        assert_true(isset($errors['transfer_date']), 'a future transfer date is refused');

        $filesBefore = $proofFiles();

        $error = assert_throws(
            static fn () => $submit($userId, $orderId, ['amount_sent' => '', 'reference_no' => '']),
            PaymentException::class
        );
        assert_same('validation', $error->reason);
        assert_true(isset($error->fieldErrors['amount_sent']));
        assert_true(isset($error->fieldErrors['reference_no']));
        assert_same(0, PaymentProof::countFor($orderId));
        assert_same($filesBefore, $proofFiles(), 'an invalid claim never stores the file');
    },

    /* ---------- P7-T3: the attempt cap ----------------------------- */

    'P7-T3 the attempt cap refuses the fourth upload' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00']);
        $orderId = $placeOrder($userId, $product, 1);

        $service = new PaymentService();
        $admin   = test_user('pay_admin', 'admin');

        assert_same(3, PaymentService::maxAttempts());

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = $submit($userId, $orderId, ['reference_no' => 'REF-CAP-' . $attempt]);
            assert_same($attempt, $result['attempt']);

            if ($attempt < 3) {
                $service->reject((int) $admin['id'], $orderId, 'unclear_proof', 'Try again');
                assert_same('payment_failed', $status($orderId));
            }
        }

        /* The third proof is still under review: the cap message is about
         * the upload window, so reject first and then try again. */
        $service->reject((int) $admin['id'], $orderId, 'unclear_proof', 'Last try');

        $window = $service->windowFor(Order::find($orderId));
        assert_same(false, $window['open']);
        assert_same('attempts', $window['reason']);
        assert_same(0, $window['left']);

        $error = assert_throws(
            static fn () => $submit($userId, $orderId),
            PaymentException::class
        );
        assert_same('attempts', $error->reason);
        assert_contains('used all 3', $error->getMessage());
        assert_same(3, PaymentProof::countFor($orderId), 'no fourth row was written');
    },

    /* ---------- P7-T4: verification -------------------------------- */

    'P7-T4 verification pays the order, commits the stock and logs the event' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '40.00', 'stock' => '5']);
        $orderId = $placeOrder($userId, $product, 2);

        $submit($userId, $orderId, ['amount_sent' => '80.00', 'reference_no' => 'GCASH-88888']);

        $admin  = test_user('pay_admin', 'admin');
        $result = (new PaymentService())->verify((int) $admin['id'], $orderId, [
            'amount_received'   => '80.00',
            'payment_reference' => 'GCASH-88888',
            'note'              => 'Matched the GCash receipt',
        ]);

        assert_same('paid', $result['status']);
        assert_same(80.0, $result['total']);

        $order = Order::find($orderId);
        assert_same('paid', $order['status']);
        assert_same('GCASH-88888', $order['payment_reference']);
        assert_not_null($order['paid_at']);
        assert_null($order['correction_deadline']);
        assert_same('committed', Order::items($orderId)[0]['reservation_state']);
        assert_same(3, StockService::available($product), 'committed stock is still held until shipment');

        $proof = PaymentProof::latestFor($orderId);
        assert_same('verified', $proof['status']);
        assert_same((int) $admin['id'], (int) $proof['reviewed_by']);
        assert_not_null($proof['reviewed_at']);

        $events = Order::events($orderId);
        $last   = end($events);
        assert_same('payment_verified', $last['reason_code']);
        assert_same('payment_review', $last['from_status']);
        assert_same('paid', $last['to_status']);
        assert_same('admin', $last['actor_type']);
        assert_same((int) $admin['id'], (int) $last['actor_id']);
        assert_contains('ref GCASH-88888', (string) $last['note']);
    },

    'P7-T4 verification refuses a wrong amount, a reused reference or no proof' => function () use ($makeProduct, $makeMember, $placeOrder, $submit): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '50.00']);
        $orderId = $placeOrder($userId, $product, 1);
        $admin   = test_user('pay_admin', 'admin');
        $service = new PaymentService();

        /* No proof yet: the order is not in review. */
        $statusError = assert_throws(
            static fn () => $service->verify((int) $admin['id'], $orderId, [
                'amount_received'   => '50.00',
                'payment_reference' => 'REF-A',
            ]),
            PaymentException::class
        );
        assert_same('status', $statusError->reason);

        $submit($userId, $orderId, ['amount_sent' => '50.00', 'reference_no' => 'REF-A']);

        $amountError = assert_throws(
            static fn () => $service->verify((int) $admin['id'], $orderId, [
                'amount_received'   => '49.99',
                'payment_reference' => 'REF-A',
            ]),
            PaymentException::class
        );
        assert_same('amount', $amountError->reason);
        assert_contains('₱49.99', $amountError->getMessage());
        assert_same('payment_review', Order::find($orderId)['status'], 'nothing changed');

        /* A reference already used on a verified order is refused. */
        $thisOrder = $service->verify((int) $admin['id'], $orderId, [
            'amount_received'   => '50.00',
            'payment_reference' => 'REF-A',
        ]);
        assert_same('paid', $thisOrder['status']);

        $secondOrder = $placeOrder($userId, $product, 1);
        $submit($userId, $secondOrder, ['amount_sent' => '50.00', 'reference_no' => 'REF-A']);

        $referenceError = assert_throws(
            static fn () => $service->verify((int) $admin['id'], $secondOrder, [
                'amount_received'   => '50.00',
                'payment_reference' => 'REF-A',
            ]),
            PaymentException::class
        );
        assert_same('reference', $referenceError->reason);
        assert_same('payment_review', Order::find($secondOrder)['status']);

        /* Missing fields are field errors, never a silent pass. */
        $errors = PaymentService::validateVerification([]);
        assert_true(isset($errors['amount_received']));
        assert_true(isset($errors['payment_reference']));
    },

    /* ---------- P7-T5 / P7-T6: rejection and correction ------------ */

    'P7-T5 rejection releases the stock and opens the correction window' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '30.00', 'stock' => '4']);
        $orderId = $placeOrder($userId, $product, 2);

        assert_same(2, StockService::available($product));
        $submit($userId, $orderId, ['amount_sent' => '60.00']);

        $admin  = test_user('pay_admin', 'admin');
        $result = (new PaymentService())->reject((int) $admin['id'], $orderId, 'wrong_amount', 'Only ₱30 arrived');

        assert_same('payment_failed', $result['status']);
        assert_same('payment_failed', $status($orderId));
        assert_same(4, StockService::available($product), 'the hold went back to the shelf');
        assert_same('released', Order::items($orderId)[0]['reservation_state']);

        $order = Order::find($orderId);
        assert_not_null($order['correction_deadline']);
        $correction = strtotime((string) $order['correction_deadline']);
        assert_true($correction > time() + 3600, 'the correction window is in the future');
        assert_true($correction <= time() + (PaymentService::correctionWindowHours() + 1) * 3600);

        $proof = PaymentProof::latestFor($orderId);
        assert_same('rejected', $proof['status']);
        assert_contains('amount received does not match', (string) $proof['reject_reason']);
        assert_contains('Only ₱30 arrived', (string) $proof['reject_reason']);
        assert_same((int) $admin['id'], (int) $proof['reviewed_by']);

        $events = Order::events($orderId);
        $last   = end($events);
        assert_same('payment_rejected', $last['reason_code']);
        assert_same('payment_failed', $last['to_status']);
        assert_contains('Attempt 1 rejected', (string) $last['note']);
    },

    'P7-T5 a rejection needs a real reason and a pending proof' => function () use ($makeProduct, $makeMember, $placeOrder, $submit): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '30.00']);
        $orderId = $placeOrder($userId, $product, 1);
        $admin   = test_user('pay_admin', 'admin');
        $service = new PaymentService();

        $statusError = assert_throws(
            static fn () => $service->reject((int) $admin['id'], $orderId, 'unclear_proof'),
            PaymentException::class
        );
        assert_same('status', $statusError->reason);

        $submit($userId, $orderId);

        $reasonError = assert_throws(
            static fn () => $service->reject((int) $admin['id'], $orderId, 'other', ''),
            PaymentException::class
        );
        assert_same('reason', $reasonError->reason);
        assert_same('payment_review', Order::find($orderId)['status'], 'an unusable rejection changes nothing');
    },

    'P7-T6 a re-upload after a rejection re-reserves the stock' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '20.00', 'stock' => '3']);
        $orderId = $placeOrder($userId, $product, 2);

        $admin   = test_user('pay_admin', 'admin');
        $service = new PaymentService();

        $submit($userId, $orderId, ['reference_no' => 'FIRST-REF']);
        $service->reject((int) $admin['id'], $orderId, 'unclear_proof', 'Blurry');
        assert_same(3, StockService::available($product));

        $retry = $submit($userId, $orderId, ['reference_no' => 'SECOND-REF']);

        assert_same(2, $retry['attempt']);
        assert_same('payment_review', $status($orderId));
        assert_same(1, StockService::available($product), 'the hold is taken again');
        assert_same('reserved', Order::items($orderId)[0]['reservation_state']);
        assert_null(Order::find($orderId)['correction_deadline'], 'the correction window is cleared');

        $proofs = PaymentProof::forOrder($orderId);
        assert_count(2, $proofs);
        assert_same('rejected', $proofs[0]['status']);
        assert_same('pending', $proofs[1]['status']);
        assert_same(2, (int) $proofs[1]['attempt_no']);
    },

    /* ---------- P7-T7 / P7-T8: deadlines -------------------------- */

    'P7-T7 the expiry sweep cancels overdue orders and is idempotent' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $expireDeadline, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '10.00', 'stock' => '10']);

        $overdue = $placeOrder($userId, $product, 2);
        $expireDeadline($overdue);

        /* An order under review is exempt even when its stamp is old. */
        $underReview = $placeOrder($userId, $product, 1);
        $submit($userId, $underReview);
        $expireDeadline($underReview);

        /* A future deadline is untouched. */
        $future = $placeOrder($userId, $product, 1);

        $service = new PaymentService();
        $first   = $service->expireOverdue();

        assert_true(in_array($overdue, $first['ids'], true), 'the overdue order is cancelled');
        assert_same('cancelled', $status($overdue));
        assert_same('released', Order::items($overdue)[0]['reservation_state']);
        assert_same(8, StockService::available($product), 'the expired hold is back; the other two orders still hold theirs');
        assert_same('payment_review', $status($underReview), 'a submitted proof is never expired');
        assert_same('pending', $status($future));

        $events = Order::events($overdue);
        $last   = end($events);
        assert_same('payment_expired', $last['reason_code']);
        assert_same('system', $last['actor_type']);
        assert_same('cancelled', $last['to_status']);
        assert_same('Payment window expired', Order::find($overdue)['cancelled_reason']);

        /* The second run has nothing left to do. */
        $second = $service->expireOverdue();
        assert_same(0, $second['expired']);
        assert_same([], $second['ids']);
        assert_same('cancelled', $status($overdue), 'a cancelled order is not touched again');
    },

    'P7-T7 a rejected order expires on its correction deadline' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $expireDeadline, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '15.00', 'stock' => '2']);
        $orderId = $placeOrder($userId, $product, 1);
        $admin   = test_user('pay_admin', 'admin');

        $submit($userId, $orderId);
        (new PaymentService())->reject((int) $admin['id'], $orderId, 'not_received', 'Nothing arrived');
        assert_same('payment_failed', $status($orderId));

        /* The original payment deadline is long gone but the correction
         * window is what governs a payment_failed order. */
        $expireDeadline($orderId, 'payment_deadline');
        assert_same(0, (new PaymentService())->expireOverdue()['expired'], 'the correction window still governs');

        $expireDeadline($orderId, 'correction_deadline');
        $result = (new PaymentService())->expireOverdue();
        assert_true(in_array($orderId, $result['ids'], true));
        assert_same('cancelled', $status($orderId));
    },

    'P7-T8 an upload past the deadline is refused with clear copy' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $expireDeadline, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00']);
        $orderId = $placeOrder($userId, $product, 1);

        $expireDeadline($orderId);

        $error = assert_throws(
            static fn () => $submit($userId, $orderId),
            PaymentException::class
        );

        assert_same('expired', $error->reason);
        assert_contains('payment window', strtolower($error->getMessage()));
        assert_same('pending', $status($orderId));
        assert_same(0, PaymentProof::countFor($orderId));
    },

    'P7-T8 a second upload while under review is refused' => function () use ($makeProduct, $makeMember, $placeOrder, $submit): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00']);
        $orderId = $placeOrder($userId, $product, 1);

        $submit($userId, $orderId);

        $error = assert_throws(
            static fn () => $submit($userId, $orderId),
            PaymentException::class
        );

        assert_same('status', $error->reason);
        assert_contains('already with us', $error->getMessage());
        assert_same(1, PaymentProof::countFor($orderId));
    },

    /* ---------- P7-T9 / P7-T10: access and segregation ------------- */

    'P7-T9 another member cannot upload a proof on this order' => function () use ($makeProduct, $makeMember, $placeOrder, $submit): void {
        $alice   = $makeMember();
        $bob     = $makeMember();
        $product = $makeProduct(['price' => '25.00']);
        $orderId = $placeOrder((int) $alice['id'], $product, 1);

        $error = assert_throws(
            static fn () => $submit((int) $bob['id'], $orderId),
            PaymentException::class
        );

        assert_same('not_found', $error->reason);
        assert_same(0, PaymentProof::countFor($orderId));
        assert_same('pending', Order::find($orderId)['status']);
    },

    'P7-T10 an admin cannot verify, reject or cancel their own order' => function () use ($makeProduct, $makeMember, $placeOrder, $submit): void {
        $admin   = test_user('pay_self_admin', 'admin');
        $adminId = (int) $admin['id'];
        $product = $makeProduct(['price' => '25.00', 'stock' => '5']);

        $orderId = $placeOrder($adminId, $product, 1);
        $submit($adminId, $orderId, ['amount_sent' => '25.00']);

        $service = new PaymentService();

        foreach ([
            static fn () => $service->verify($adminId, $orderId, [
                'amount_received'   => '25.00',
                'payment_reference' => 'SELF-1',
            ]),
            static fn () => $service->reject($adminId, $orderId, 'unclear_proof', 'Nope'),
            static fn () => $service->cancelByAdmin($adminId, $orderId, 'admin_cancel', 'Nope'),
        ] as $attempt) {
            $error = assert_throws($attempt, PaymentException::class);
            assert_same('self_review', $error->reason);
        }

        assert_same('payment_review', Order::find($orderId)['status'], 'nothing changed');
        assert_same(1, PaymentProof::countFor($orderId));
    },

    /* ---------- P7-T11: the timeline ------------------------------ */

    'P7-T11 the timeline records every step with its actor and reason' => function () use ($makeProduct, $makeMember, $placeOrder, $submit, $status): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '20.00']);
        $orderId = $placeOrder($userId, $product, 1);
        $admin   = test_user('pay_admin', 'admin');

        $submit($userId, $orderId, ['reference_no' => 'TEST-1']);
        (new PaymentService())->reject((int) $admin['id'], $orderId, 'unclear_proof', 'Retake it');
        $submit($userId, $orderId, ['reference_no' => 'TEST-2']);
        (new PaymentService())->verify((int) $admin['id'], $orderId, [
            'amount_received'   => '20.00',
            'payment_reference' => 'TEST-2',
        ]);

        $events = Order::events($orderId);
        $codes  = array_map(static fn (array $event): string => (string) $event['reason_code'], $events);

        assert_same(
            ['order_placed', 'proof_submitted', 'payment_rejected', 'proof_submitted', 'payment_verified'],
            $codes
        );
        assert_same(['pending', 'review', 'failed', 'review', 'paid'], array_map(
            static fn (array $event): string => match ((string) $event['to_status']) {
                'payment_review' => 'review',
                'payment_failed' => 'failed',
                'paid'           => 'paid',
                default          => 'pending',
            },
            $events
        ));
        assert_same('paid', $status($orderId));

        foreach ($events as $event) {
            assert_not_null($event['created_at']);
        }
    },

    /* ---------- P7-T12: admin cancellation and refunds ------------- */

    'P7-T12 cancelling a wallet-paid order refunds the buckets it spent' => function () use ($makeProduct, $makeMember, $fund, $placeOrder): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '120.00', 'stock' => '3']);

        /* 100 withdrawable + 50 spend-only: the purchase spends the 50
         * first and 70 of the withdrawable money. */
        $fund($userId, '100.00', true);
        $fund($userId, '50.00', false);

        $orderId = $placeOrder($userId, $product, 1, 'ewallet');

        $wallet = new WalletService();
        assert_same(30.0, $wallet->balance($userId));
        assert_same(30.0, $wallet->withdrawable($userId));
        assert_same(2, StockService::available($product), 'the unit is committed');

        $admin = test_user('pay_admin', 'admin');

        $result = (new PaymentService())->cancelByAdmin(
            (int) $admin['id'],
            $orderId,
            'customer_request',
            'Member changed their mind'
        );

        assert_same('cancelled', $result['status']);
        assert_same(120.0, $result['refunded']);

        assert_same(150.0, $wallet->balance($userId), 'the full amount is back');
        assert_same(100.0, $wallet->withdrawable($userId), 'the withdrawable share is restored exactly');
        assert_same(3, StockService::available($product), 'the reservation is released');

        $stmt = db()->prepare(
            "SELECT * FROM ewallet_ledger WHERE user_id = ? AND ref_type = 'refund' AND ref_id = ?"
        );
        $stmt->execute([$userId, $orderId]);
        $rows = $stmt->fetchAll();
        assert_count(1, $rows);
        assert_same('credit', $rows[0]['type']);
        assert_same('120.00', number_format((float) $rows[0]['amount'], 2, '.', ''));
        assert_same('100.00', number_format((float) $rows[0]['withdrawable_after'], 2, '.', ''));

        $order = Order::find($orderId);
        assert_same('cancelled', $order['status']);
        assert_contains('The member asked to cancel', (string) $order['cancelled_reason']);

        $events = Order::events($orderId);
        $last   = end($events);
        assert_same('customer_request', $last['reason_code']);
        assert_contains('refunded to the e-wallet', (string) $last['note']);
    },

    'P7-T12 cancelling a proof-paid order refunds nothing' => function () use ($makeProduct, $makeMember, $placeOrder, $submit): void {
        $member   = $makeMember();
        $userId   = (int) $member['id'];
        $product  = $makeProduct(['price' => '35.00', 'stock' => '3']);
        $orderId  = $placeOrder($userId, $product, 1);
        $admin    = test_user('pay_admin', 'admin');
        $service  = new PaymentService();

        $submit($userId, $orderId, ['amount_sent' => '35.00']);
        $service->verify((int) $admin['id'], $orderId, [
            'amount_received'   => '35.00',
            'payment_reference' => 'PROOF-ONLY-1',
        ]);
        assert_same('paid', Order::find($orderId)['status']);

        $result = $service->cancelByAdmin((int) $admin['id'], $orderId, 'out_of_stock', 'No stock left');

        assert_same(0.0, $result['refunded']);
        assert_same('cancelled', Order::find($orderId)['status']);

        $stmt = db()->prepare("SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = ? AND ref_type = 'refund'");
        $stmt->execute([$userId]);
        assert_same(0, (int) $stmt->fetchColumn(), 'no wallet money was moved');
    },

    'P7-T12 an admin cancel rejects a pending proof and never double-cancels' => function () use ($makeProduct, $makeMember, $placeOrder, $submit): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['price' => '25.00', 'stock' => '2']);
        $orderId = $placeOrder($userId, $product, 1);
        $admin   = test_user('pay_admin', 'admin');
        $service = new PaymentService();

        $submit($userId, $orderId);

        $result = $service->cancelByAdmin((int) $admin['id'], $orderId, 'admin_cancel', 'Duplicate order');
        assert_same('cancelled', $result['status']);

        $proof = PaymentProof::latestFor($orderId);
        assert_same('rejected', $proof['status']);
        assert_contains('Order cancelled', (string) $proof['reject_reason']);
        assert_same(2, StockService::available($product));

        $again = assert_throws(
            static fn () => $service->cancelByAdmin((int) $admin['id'], $orderId, 'admin_cancel', 'Again'),
            PaymentException::class
        );
        assert_same('status', $again->reason);
        assert_same('cancelled', Order::find($orderId)['status']);
        assert_same(1, (int) db()->query(
            'SELECT COUNT(*) FROM shop_order_events WHERE order_id = ' . $orderId . " AND reason_code = 'admin_cancel'"
        )->fetchColumn());
    },

    'P7-T12 a shipped order cannot be cancelled by an admin' => function () use ($makeProduct, $makeMember, $placeOrder): void {
        $member  = $makeMember();
        $product = $makeProduct(['price' => '25.00']);
        $orderId = $placeOrder((int) $member['id'], $product, 1);

        db()->prepare("UPDATE shop_orders SET status = 'shipped' WHERE id = ?")->execute([$orderId]);

        $admin = test_user('pay_admin', 'admin');
        $error = assert_throws(
            static fn () => (new PaymentService())->cancelByAdmin((int) $admin['id'], $orderId, 'admin_cancel'),
            PaymentException::class
        );

        assert_same('status', $error->reason);
        assert_same('shipped', Order::find($orderId)['status']);
        assert_false(Order::canAdminCancel(Order::find($orderId)));
    },
];
