<?php
declare(strict_types=1);

/* ==============================================================
 *  CronTest — the shop-expiry job's supporting pieces
 *  (plan §5.4, §7 Phase 7 follow-up)
 *
 *  Covers what the shell-level smoke checks cannot: the access
 *  decision for the web entry, the exclusive lock, the monthly log,
 *  the kill switch and the `--from-settings` recomputation.
 *
 *  Each case runs inside the harness transaction, so its orders and
 *  setting changes roll back; scratch log/lock files are removed here.
 * ============================================================== */

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'   => 'Cron Product ' . bin2hex(random_bytes(4)),
        'sku'    => 'CRN-' . strtoupper(bin2hex(random_bytes(3))),
        'price'  => '25.00',
        'stock'  => '10',
        'status' => 'active',
    ]);
};

$makeMember = static function (): array {
    return test_user('cron_' . bin2hex(random_bytes(4)));
};

$placeManual = static function (int $userId, int $productId): int {
    (new CartService())->add($userId, $productId, 1);

    return (new OrderService())->placeFromCart($userId, [
        'billing_name'      => 'Juan Dela Cruz',
        'shipping_name'     => 'Juan Dela Cruz',
        'shipping_address'  => '12 Mabini Street',
        'shipping_city'     => 'Makati',
        'payment_method'    => 'gcash',
        'idempotency_key'   => bin2hex(random_bytes(16)),
        'terms'             => '1',
    ])['order_id'];
};

$submitProof = static function (int $userId, int $orderId): void {
    $tmp = (string) tempnam(sys_get_temp_dir(), 'cronproof');
    file_put_contents($tmp, TEST_PNG_BYTES);

    (new PaymentService())->submitProof($userId, $orderId, [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ], [
        'amount_sent'  => '25.00',
        'reference_no' => 'CRON-' . strtoupper(bin2hex(random_bytes(3))),
    ]);
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
    /* ---------- Access decision (web entry) ------------------------ */

    'the web cron is trusted on loopback and needs the key elsewhere' => function (): void {
        /* Loopback is the local developer's browser / the server itself. */
        assert_true(CronAccess::allowed('127.0.0.1', null, null));
        assert_true(CronAccess::allowed('::1', null, null));
        assert_true(CronAccess::allowed('127.0.0.1', 'whatever', 'secret'));

        /* Anywhere else needs the exact configured key. */
        assert_false(CronAccess::allowed('203.0.113.9', null, null));
        assert_false(CronAccess::allowed('203.0.113.9', 'secret', null));
        assert_false(CronAccess::allowed('203.0.113.9', 'wrong', 'secret'));
        assert_false(CronAccess::allowed('203.0.113.9', '', 'secret'));
        assert_true(CronAccess::allowed('203.0.113.9', 'secret', 'secret'));
    },

    /* ---------- Lock ------------------------------------------------- */

    'the cron lock allows one run at a time' => function (): void {
        $name = 'test-lock-' . bin2hex(random_bytes(4));
        $first  = new CronLock($name);
        $second = new CronLock($name);

        assert_same(CronLock::defaultDirectory(), dirname($first->path()));
        assert_contains($name . '.lock', $first->path());
        assert_true($first->isFree(), 'a fresh lock is free');

        assert_true($first->acquire());
        assert_false($second->acquire(), 'a second handle cannot take the lock');
        assert_false($first->isFree());

        $first->release();

        assert_true($second->acquire(), 'the lock is available after the first release');
        $second->release();
        assert_true($first->isFree());
    },

    /* ---------- Log -------------------------------------------------- */

    'the cron log writes one monthly line per run' => function (): void {
        $prefix = 'cron_test_' . bin2hex(random_bytes(3));
        $log    = new CronLog(null, $prefix);
        $file   = $log->fileFor();

        assert_true(str_starts_with($file, CronLog::defaultDirectory()));
        assert_true(str_ends_with($file, '-' . date('Y-m') . '.log'));
        assert_true($log->isWritable());

        assert_true($log->write('OK', "expired=2 ids=57,58\nsecond line flattened"));
        assert_true(is_file($file));

        $line = (string) $log->latestLine();
        assert_contains('[OK   ]', $line);
        assert_contains('expired=2 ids=57,58 second line flattened', $line);
        assert_false(str_contains($line, PHP_EOL), 'one run is one line');

        /* Unknown levels are still recorded, lower-cased input is fixed. */
        assert_true($log->write('warn', 'skipped: disabled'));
        assert_contains('[WARN ]', (string) $log->latestLine());
        assert_contains('skipped: disabled', (string) $log->latestLine());

        assert_false($log->write('INFO', '   '), 'blank messages are ignored');
        @unlink($file);
    },

    /* ---------- Kill switch ----------------------------------------- */

    'the expiry sweep obeys the admin kill switch' => function () use ($makeProduct, $makeMember, $placeManual, $withSettings): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);
        $orderId = $placeManual($userId, $product);

        db()->prepare('UPDATE shop_orders SET payment_deadline = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 3600), $orderId]);

        $withSettings(['shop_expiry_enabled' => '0'], function () use ($orderId): void {
            assert_false(PaymentService::expiryEnabled());

            $result = (new PaymentService())->expireOverdue();

            assert_same('disabled', $result['skipped']);
            assert_same(0, $result['expired']);
            assert_same('pending', Order::find($orderId)['status'], 'nothing is cancelled while paused');
        });

        $withSettings(['shop_expiry_enabled' => '1'], function () use ($orderId): void {
            assert_true(PaymentService::expiryEnabled());

            $result = (new PaymentService())->expireOverdue();

            assert_same(null, $result['skipped']);
            assert_true(in_array($orderId, $result['ids'], true));
            assert_same('cancelled', Order::find($orderId)['status']);
        });
    },

    /* ---------- Settings mode --------------------------------------- */

    'the settings mode recomputes a pending order from the payment window' => function () use ($makeProduct, $makeMember, $placeManual, $withSettings): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);
        $orderId = $placeManual($userId, $product);

        /* The stored stamp is 24h out; make the order look 3h old. */
        db()->prepare('UPDATE shop_orders SET created_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE id = ?')
            ->execute([$orderId]);

        $service = new PaymentService();

        $withSettings(['shop_payment_deadline_hours' => '24'], function () use ($service, $orderId): void {
            assert_false(
                in_array($orderId, $service->overdueOrderIds(200, true), true),
                'still inside the 24h window'
            );
            assert_false(in_array($orderId, $service->overdueOrderIds(200), true));
        });

        $withSettings(['shop_payment_deadline_hours' => '1'], function () use ($service, $orderId): void {
            assert_true(
                in_array($orderId, $service->overdueOrderIds(200, true), true),
                'past the recomputed 1h window'
            );
            assert_false(
                in_array($orderId, $service->overdueOrderIds(200), true),
                'the stored 24h stamp still governs the default mode'
            );

            $result = $service->expireOverdue(200, true);

            assert_same('settings', $result['mode']);
            assert_true(in_array($orderId, $result['ids'], true));
            assert_same('cancelled', Order::find($orderId)['status']);

            $events = Order::events($orderId);
            $last   = end($events);
            assert_same('payment_expired', $last['reason_code']);

            $again = $service->expireOverdue(200, true);
            assert_same(0, $again['expired']);
        });
    },

    'the settings mode measures a rejected order from its rejection' => function () use ($makeProduct, $makeMember, $placeManual, $submitProof, $withSettings): void {
        $member   = $makeMember();
        $userId   = (int) $member['id'];
        $product  = $makeProduct(['stock' => '5']);
        $orderId  = $placeManual($userId, $product);

        $submitProof($userId, $orderId);
        (new PaymentService())->reject((int) test_user('cron_admin', 'admin')['id'], $orderId, 'unclear_proof', 'Try again');
        assert_same('payment_failed', Order::find($orderId)['status']);

        /* Rejection happened 5h ago, but the stored stamp is +24h. */
        db()->prepare(
            "UPDATE shop_order_events SET created_at = DATE_SUB(NOW(), INTERVAL 5 HOUR)
              WHERE order_id = ? AND reason_code = 'payment_rejected'"
        )->execute([$orderId]);

        $service = new PaymentService();

        $withSettings(['shop_correction_window_hours' => '1'], function () use ($service, $orderId): void {
            assert_true(in_array($orderId, $service->overdueOrderIds(200, true), true));
            assert_false(in_array($orderId, $service->overdueOrderIds(200), true));

            $result = $service->expireOverdue(200, true);

            assert_true(in_array($orderId, $result['ids'], true));
            assert_same('cancelled', Order::find($orderId)['status']);
            assert_same('released', Order::items($orderId)[0]['reservation_state']);
        });
    },

    'the settings mode keeps a fresh order alive' => function () use ($makeProduct, $makeMember, $placeManual, $withSettings): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);
        $orderId = $placeManual($userId, $product);

        $withSettings(['shop_payment_deadline_hours' => '24'], function () use ($orderId): void {
            $service = new PaymentService();

            assert_false(in_array($orderId, $service->overdueOrderIds(200, true), true));
            assert_same(0, $service->expireOverdue(200, true)['expired']);
            assert_same('pending', Order::find($orderId)['status']);
        });
    },

    /* ---------- Return shape --------------------------------------- */

    'the sweep reports candidates, mode and expiry state' => function () use ($makeProduct, $makeMember, $placeManual): void {
        $member  = $makeMember();
        $userId  = (int) $member['id'];
        $product = $makeProduct(['stock' => '5']);
        $orderId = $placeManual($userId, $product);

        db()->prepare('UPDATE shop_orders SET payment_deadline = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 3600), $orderId]);

        $result = (new PaymentService())->expireOverdue();

        assert_same(1, $result['candidates']);
        assert_same(1, $result['expired']);
        assert_same([$orderId], $result['ids']);
        assert_same('stamps', $result['mode']);
        assert_same(null, $result['skipped']);
    },
];
