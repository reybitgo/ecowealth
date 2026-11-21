<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelEscrowTest — Phase 18 escrow lifecycle (plan §23.2)
 *
 *  Proves the A++ core with no page involved:
 *
 *      creation    a paid order writes `pending` awards, no ledger row
 *                  and no wallet movement (ESC-T1, T2, T3)
 *      release     the hold is respected, delivery is required, the
 *                  credit lands exactly once, a failure rolls back
 *                  (ESC-T4 … T10, T16)
 *      cancellation pending awards are cancelled even with the reversal
 *                  switch off; a released award still reverses
 *                  (ESC-T11 … T15)
 *      presentation the pending summary, payout options, the over-limit
 *                  refusal and the success flash (ESC-T17 … T20)
 *      books       reconcile and the monthly close (ESC-T21 … T24)
 *      surfaces    the CSV shape and the admin filters (ESC-T27, T28)
 *
 *  The CLI cases (dry run, doctor, lock) live in
 *  UnilevelReleaseJobTest; the migration/fresh-install cases live in
 *  MigrationTest / InstallTest.
 *
 *  Every case runs inside the harness transaction and rolls back.
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/* --------------------------------------------------------------
 *  Fixtures
 * -------------------------------------------------------------- */

$makeUser = static function (string $prefix = 'p18'): array {
    return test_user($prefix . '_' . bin2hex(random_bytes(4)));
};

/** `buyer → L1 → L2 … → Ldepth → NULL`. */
$makeChain = static function (int $depth, string $prefix = 'p18') use ($makeUser): array {
    $buyer  = $makeUser($prefix . '_buyer');
    $levels = [];
    $child  = $buyer;

    for ($level = 1; $level <= $depth; $level++) {
        $upline = $makeUser($prefix . '_u' . $level);

        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $upline['id'], (int) $child['id']]);

        $levels[$level] = $upline;
        $child          = $upline;
    }

    return ['buyer' => $buyer, 'levels' => $levels];
};

$makeProduct = static function (array $overrides = []): int {
    return Product::save($overrides + [
        'name'             => 'P18 Product ' . bin2hex(random_bytes(3)),
        'sku'              => 'P18-' . strtoupper(bin2hex(random_bytes(3))),
        'price'            => '300.00',
        'pv'               => '250.00',
        'unilevel_enabled' => '1',
        'unilevel_base'    => 'auto',
        'stock'            => '100',
        'status'           => 'active',
    ], null, ProductUnilevel::referencePreset());
};

/**
 * A paid order fixture with one priced line and the base snapshot.
 *
 * @param array{quantity?:int, unit_price?:float, unit_base?:float, base_total?:float, paid_at?:string} $overrides
 */
$paidOrder = static function (int $buyerId, int $productId, array $overrides = []): int {
    $quantity  = (int) ($overrides['quantity'] ?? 1);
    $unitPrice = (float) ($overrides['unit_price'] ?? 300.00);
    $unitBase  = (float) ($overrides['unit_base'] ?? 250.00);
    $baseTotal = (float) ($overrides['base_total'] ?? ($unitBase * $quantity));
    $paidAt    = (string) ($overrides['paid_at'] ?? date('Y-m-d H:i:s'));

    $orderId = test_order($buyerId, [], 'paid', [
        'paid_at'  => $paidAt,
        'subtotal' => number_format($unitPrice * $quantity, 2, '.', ''),
        'total'    => number_format($unitPrice * $quantity, 2, '.', ''),
    ]);

    db()->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total,
            unit_base, base_total, reservation_state)
         VALUES (?, ?, 'Fixture product', NULL, ?, ?, ?, ?, ?, 'committed')"
    )->execute([
        $orderId,
        $productId,
        $quantity,
        number_format($unitPrice, 2, '.', ''),
        number_format($unitPrice * $quantity, 2, '.', ''),
        number_format($unitBase, 2, '.', ''),
        number_format($baseTotal, 2, '.', ''),
    ]);

    return $orderId;
};

/** One funded wallet (commission credit, withdrawable). */
$fund = static function (int $userId, string $amount, bool $withdrawable = true): void {
    (new WalletService())->credit($userId, $amount, 'commission', null, 'P18 funding', $withdrawable);
};

$settings = static function (array $values): void {
    foreach ($values as $key => $value) {
        setting_set((string) $key, (string) $value);
    }

    setting_cache_clear();
};

$enable = static function (int $holdDays = 7) use ($settings): void {
    $settings([
        'unilevel_enabled'                => 1,
        'unilevel_max_levels'             => 10,
        'unilevel_requires_active_upline' => 0,
        'unilevel_active_threshold'       => 500,
        'unilevel_reversal_enabled'       => 1,
        'unilevel_hold_days'              => $holdDays,
        'unilevel_release_enabled'        => 1,
        'min_payout'                      => 0,
        'service_fee_gcash'               => 0,
        'payout_gcash_enabled'            => 1,
    ]);
};

$payoutDefaults = static function () use ($settings): void {
    $settings([
        'min_payout'                => '0',
        'service_fee_gcash'         => '0',
        'usdt_rate'                 => '0',
        'payout_gcash_enabled'      => '1',
        'payout_maya_enabled'       => '1',
        'payout_usdt_trc20_enabled' => '1',
        'payout_usdt_bep20_enabled' => '1',
    ]);
};

$service    = static fn (): UnilevelService => new UnilevelService();
$run        = static fn (int $orderId): array => (new UnilevelService())->onOrderPaid($orderId);
$release    = static fn (int $orderId, bool $force = false): array => (new UnilevelService())->releaseForOrder($orderId, $force);
$awards     = static fn (int $orderId): array => UnilevelAward::forOrder($orderId);
$withdrawable = static fn (int $userId): float => (new WalletService())->withdrawable($userId);
$balance      = static fn (int $userId): float => (new WalletService())->balance($userId);

$ledgerRows = static function (int $orderId = 0): array {
    if ($orderId <= 0) {
        return db()->query('SELECT * FROM ewallet_ledger ORDER BY id')->fetchAll();
    }

    $stmt = db()->prepare(
        'SELECT * FROM ewallet_ledger
          WHERE ref_id IN (SELECT id FROM unilevel_awards WHERE order_id = ?)
          ORDER BY id'
    );
    $stmt->execute([$orderId]);

    return $stmt->fetchAll();
};

/** Deliver at a fixed instant (the release window starts there). */
$deliverAt = static function (int $orderId, string $at): void {
    test_deliver_order($orderId, $at);
};

return [
    /* ==========================================================
     *  Creation (ESC-T1 … T3)
     * ========================================================== */

    'P18-T1 a paid order writes pending awards and moves no money' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards, $ledgerRows, $balance, $withdrawable, $makeUser): void {
        $enable(7);
        $product = $makeProduct();
        $chain   = $makeChain(2, 'p18_t1');
        $buyer   = $chain['buyer'];
        $l1      = $chain['levels'][1];

        /* Give the recipient a wallet so a movement would be visible. */
        (new WalletService())->credit((int) $l1['id'], '100.00', 'commission', null, 'fixture');

        $order = $paidOrder((int) $buyer['id'], $product);
        $run($order);

        $rows = $awards($order);

        assert_count(2, $rows, 'two chain hops pay two awards');

        foreach ($rows as $row) {
            assert_same('pending', $row['status'], 'a new award is pending');
            assert_null($row['ledger_id'], 'a pending award has no wallet movement');
            assert_null($row['credited_at'], 'a pending award has no release date');
            assert_same(7, (int) $row['hold_days'], 'the hold is snapshotted on the award');
        }

        assert_count(0, $ledgerRows($order), 'no unilevel ledger row exists yet');
        assert_same(100.0, $withdrawable((int) $l1['id']), 'the recipient wallet is untouched');
        assert_same(100.0, $balance((int) $l1['id']));
    },

    'P18-T2 the order is stamped with the awarded total' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards): void {
        $enable(7);
        $chain = $makeChain(2, 'p18_t2');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $summary = $run($order);
        $row     = Order::find($order);

        assert_not_null($row['unilevel_processed_at'], 'the order is stamped at payment');
        assert_same('25.00', number_format((float) $row['unilevel_total'], 2, '.', ''), '5% of 250 + 5% of 250');

        $sum = 0.0;
        foreach ($awards($order) as $award) {
            $sum += (float) $award['amount'];
        }

        assert_same(round($sum, 2), (float) $row['unilevel_total'], 'the stamp equals the pending total');
        assert_same(25.0, (float) $summary['awarded']);
        assert_same(25.0, (float) $summary['pending']);
        assert_same(25.0, (float) $summary['credited'], 'credited stays an alias for awarded');
    },

    'P18-T3 replaying onOrderPaid adds no row and no money' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards, $ledgerRows): void {
        $enable(7);
        $chain = $makeChain(2, 'p18_t3');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $count = count($awards($order));

        $second = $run($order);

        assert_same(false, $second['processed']);
        assert_same(true, $second['replayed']);
        assert_same($count, count($awards($order)), 'no second set of rows');
        assert_count(0, $ledgerRows($order), 'no wallet movement');
    },

    /* ==========================================================
     *  Release (ESC-T4 … T10, T16)
     * ========================================================== */

    'P18-T4 releasing before delivery credits nothing' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $awards, $withdrawable): void {
        $enable(0);
        $chain = $makeChain(2, 'p18_t4');
        $l1    = $chain['levels'][1];
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $result = $release($order);

        assert_same(0, $result['released']);
        assert_same('not delivered', $result['reason']);
        assert_same(0.0, $withdrawable((int) $l1['id']));

        foreach ($awards($order) as $row) {
            assert_same('pending', $row['status']);
        }
    },

    'P18-T5 releasing before the hold window credits nothing' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $awards, $withdrawable, $deliverAt): void {
        $enable(7);
        $chain = $makeChain(2, 'p18_t5');
        $l1    = $chain['levels'][1];
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $deliverAt($order, date('Y-m-d H:i:s', time() - 6 * 86400));

        $result = $release($order);

        assert_same(0, $result['released']);
        assert_same('not due', $result['reason']);
        assert_same(0.0, $withdrawable((int) $l1['id']));

        foreach ($awards($order) as $row) {
            assert_same('pending', $row['status']);
        }
    },

    'P18-T6 delivery with a zero hold releases exactly once' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $awards, $withdrawable, $deliverAt): void {
        $enable(0);
        $chain = $makeChain(2, 'p18_t6');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $deliverAt($order, date('Y-m-d H:i:s'));

        $result = $release($order);

        assert_same(2, $result['released']);
        assert_same(25.0, $result['amount']);

        foreach ($awards($order) as $row) {
            assert_same('credited', $row['status']);
            assert_not_null($row['ledger_id']);
            assert_not_null($row['credited_at'], 'the release date is stamped');
        }

        assert_same(12.5, $withdrawable((int) $chain['levels'][1]['id']));
        assert_same(12.5, $withdrawable((int) $chain['levels'][2]['id']));
        assert_same('25.00', number_format((float) Order::find($order)['unilevel_total'], 2, '.', ''), 'release never changes the awarded total');
    },

    'P18-T7 the seven-day window is respected and then releases' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $awards, $withdrawable, $deliverAt): void {
        $enable(7);
        $chain = $makeChain(1, 'p18_t7');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $deliverAt($order, date('Y-m-d H:i:s', time() - 7 * 86400));

        $result = $release($order);

        assert_same(1, $result['released'], 'day 7 is due');
        assert_same(12.5, $withdrawable((int) $chain['levels'][1]['id']));
    },

    'P18-T8 releasing twice credits once' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $awards, $ledgerRows, $withdrawable, $deliverAt): void {
        $enable(0);
        $chain = $makeChain(2, 'p18_t8');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $deliverAt($order, date('Y-m-d H:i:s'));

        $first = $release($order);
        $after = $withdrawable((int) $chain['levels'][1]['id']);

        $second = $release($order);

        assert_same(2, $first['released']);
        assert_same(0, $second['released']);
        assert_same($after, $withdrawable((int) $chain['levels'][1]['id']), 'no second credit');
        assert_count(2, $ledgerRows($order), 'one ledger row per award');

        $statuses = array_column($awards($order), 'status');
        assert_same(['credited', 'credited'], $statuses);
    },

    'P18-T9 only the due order releases' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $deliverAt, $withdrawable): void {
        $enable(0);
        $chain = $makeChain(1, 'p18_t9');

        $due    = $paidOrder((int) $chain['buyer']['id'], $makeProduct());
        $notYet = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($due);
        $run($notYet);

        $deliverAt($due, date('Y-m-d H:i:s'));

        $now = $release($due);

        assert_same(1, $now['released']);
        assert_count(0, $release($notYet)['awards'], 'the undelivered order pays nothing');
        assert_same('not delivered', $release($notYet)['reason']);
        assert_same(12.5, $withdrawable((int) $chain['levels'][1]['id']));
    },

    'P18-T10 a wallet failure rolls the whole order back' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $awards, $deliverAt, $ledgerRows): void {
        $enable(0);
        $chain = $makeChain(2, 'p18_t10');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $deliverAt($order, date('Y-m-d H:i:s'));

        /* Make the second recipient's wallet impossible to credit: the
         * withdrawable bucket already exceeds the total, so the credit's
         * invariant check refuses the movement. */
        $ids = [(int) $chain['levels'][1]['id'], (int) $chain['levels'][2]['id']];
        sort($ids);
        $broken = $ids[1];

        db()->prepare('UPDATE users SET ewallet_balance = 0.00, withdrawable_balance = 50.00 WHERE id = ?')
            ->execute([$broken]);

        assert_throws(
            static fn (): array => (new UnilevelService())->releaseForOrder($order),
            Throwable::class,
            'the invariant violation must surface'
        );

        foreach ($awards($order) as $row) {
            assert_same('pending', $row['status'], 'the rollback keeps every award pending');
            assert_null($row['ledger_id']);
        }

        assert_count(0, $ledgerRows($order), 'no movement survived the rollback');
    },

    'P18-T16 the hold is snapshotted, so a later setting change cannot move it' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $awards, $settings, $deliverAt): void {
        $enable(7);
        $chain = $makeChain(1, 'p18_t16');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);

        /* The operator shortens the window; the award keeps its promise. */
        $settings(['unilevel_hold_days' => 0]);

        foreach ($awards($order) as $row) {
            assert_same(7, (int) $row['hold_days']);
        }

        $deliverAt($order, date('Y-m-d H:i:s'));

        assert_same(0, $release($order)['released'], 'the award still waits its seven days');

        /* A NEW award on the same shop takes the new window. */
        $second = $paidOrder((int) $chain['buyer']['id'], $makeProduct());
        $run($second);
        $deliverAt($second, date('Y-m-d H:i:s'));

        assert_same(1, $release($second)['released'], 'a new award uses the new hold');
    },

    /* ==========================================================
     *  Cancellation (ESC-T11 … T15)
     * ========================================================== */

    'P18-T11 cancelling before release cancels the awards with no money moved' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards, $ledgerRows, $withdrawable, $balance, $fund): void {
        test_enable_order_cancel();
        $enable(7);
        $chain = $makeChain(2, 'p18_t11');
        $buyer = $chain['buyer'];
        $l1    = $chain['levels'][1];
        $order = $paidOrder((int) $buyer['id'], $makeProduct());

        $run($order);

        (new PaymentService())->cancelByAdmin((int) Member::primaryAdmin()['id'], $order, 'admin_cancel');

        foreach ($awards($order) as $row) {
            assert_same('cancelled', $row['status']);
            assert_same(0.0, (float) $row['reversed_amount'], 'no reversal money');
            assert_null($row['reversal_ledger_id']);
            assert_not_null($row['voided_at']);
            assert_contains('cancelled', strtolower((string) $row['void_reason']));
        }

        assert_same('0.00', number_format((float) Order::find($order)['unilevel_total'], 2, '.', ''));
        assert_count(0, $ledgerRows($order), 'a cancelled pending award wrote no wallet row');
        assert_same(0.0, $withdrawable((int) $l1['id']));
        assert_same(0.0, $balance((int) $l1['id']));

        /* The release job must never pay a cancelled order afterwards. */
        test_deliver_order($order, date('Y-m-d H:i:s'));

        $result = (new UnilevelService())->releaseForOrder($order);

        assert_same(0, $result['released']);
        assert_count(0, $ledgerRows($order));
    },

    'P18-T12 the reversal switch off still cancels pending awards' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards, $settings, $ledgerRows): void {
        $enable(7);
        $chain = $makeChain(2, 'p18_t12');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $settings(['unilevel_reversal_enabled' => 0]);

        $result = (new UnilevelService())->reverseForOrder($order, 'Order cancelled');

        assert_same(2, $result['pending_cancelled'], 'pending rows are cancelled before the switch is read');
        assert_same(0, $result['voided']);
        assert_same(true, $result['disabled']);

        foreach ($awards($order) as $row) {
            assert_same('cancelled', $row['status']);
        }

        assert_count(0, $ledgerRows($order));
        assert_same('0.00', number_format((float) Order::find($order)['unilevel_total'], 2, '.', ''));
    },

    'P18-T13 cancelling after release voids the award and records a shortfall' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards, $legacyCredit, $withdrawable, $balance): void {
        test_enable_order_cancel();
        $enable(0);
        $chain = $makeChain(1, 'p18_t13');
        $buyer = $chain['buyer'];
        $l1    = $chain['levels'][1];
        $order = $paidOrder((int) $buyer['id'], $makeProduct());

        $run($order);
        test_legacy_credit_unilevel($order);

        assert_same(12.5, $withdrawable((int) $l1['id']));

        /* The upline spends ₱5 of the released bonus before the return. */
        (new WalletService())->debit((int) $l1['id'], '5.00', 'payout', null, 'spent', true);

        (new PaymentService())->cancelByAdmin((int) Member::primaryAdmin()['id'], $order, 'admin_cancel');

        $row = $awards($order)[0];

        assert_same('voided', $row['status']);
        assert_same(7.5, (float) $row['reversed_amount'], 'only the withdrawable part came back');
        assert_not_null($row['reversal_ledger_id']);
        assert_same(5.0, round((float) $row['amount'] - (float) $row['reversed_amount'], 2), 'the shortfall is recorded');
        assert_same(0.0, $withdrawable((int) $l1['id']));
        assert_same(0.0, $balance((int) $l1['id']), 'never a negative balance');
    },

    'P18-T14 a release after cancellation changes nothing' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards, $ledgerRows, $deliverAt): void {
        $enable(0);
        $chain = $makeChain(1, 'p18_t14');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        (new UnilevelService())->reverseForOrder($order, 'Order cancelled');

        $deliverAt($order, date('Y-m-d H:i:s'));

        $result = (new UnilevelService())->releaseForOrder($order);

        assert_same(0, $result['released']);
        assert_true(in_array($result['reason'] ?? '', ['cancelled', 'not delivered', 'nothing pending'], true));
        assert_same('cancelled', $awards($order)[0]['status']);
        assert_count(0, $ledgerRows($order));
    },

    'P18-T15 a cancelled order with a delivered stamp still refuses to release' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards, $deliverAt): void {
        $enable(0);
        $chain = $makeChain(1, 'p18_t15');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);

        /* Mark cancelled directly but leave the pending row behind: the
         * service's order guard must refuse it (the mismatch check is in
         * the reconcile). */
        db()->prepare("UPDATE shop_orders SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")->execute([$order]);
        $deliverAt($order, date('Y-m-d H:i:s'));

        $result = (new UnilevelService())->releaseForOrder($order);

        assert_same(0, $result['released']);
        assert_same('cancelled', $result['reason']);
        assert_same('pending', $awards($order)[0]['status'], 'the award was not marked credited');
    },

    /* ==========================================================
     *  Presentation (ESC-T17 … T20)
     * ========================================================== */

    'P18-T17 the pending summary reports the total, count and next release' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $deliverAt): void {
        $enable(7);
        $chain  = $makeChain(1, 'p18_t17');
        $l1     = (int) $chain['levels'][1]['id'];
        $buyer  = (int) $chain['buyer']['id'];

        $summary = UnilevelAward::pendingSummaryForUser($l1);
        assert_same(0.0, $summary['total']);
        assert_same(0, $summary['count']);
        assert_null($summary['next_release_at']);

        $first = $paidOrder($buyer, $makeProduct());
        $run($first);

        $summary = UnilevelAward::pendingSummaryForUser($l1);

        assert_same(12.5, $summary['total']);
        assert_same(1, $summary['count']);
        assert_same(1, $summary['undelivered'], 'no delivery date yet');
        assert_null($summary['next_release_at'], 'an undelivered award has no promised date');
        assert_same(true, $summary['release_enabled']);

        $second = $paidOrder($buyer, $makeProduct());
        $run($second);

        $deliverAt($second, date('Y-m-d H:i:s', time() - 86400));

        $summary = UnilevelAward::pendingSummaryForUser($l1);

        assert_same(25.0, $summary['total']);
        assert_same(2, $summary['count']);
        assert_same(1, $summary['undelivered']);
        assert_not_null($summary['next_release_at']);
        assert_same(
            date('Y-m-d', strtotime((string) $summary['next_release_at'])),
            date('Y-m-d', time() + 6 * 86400),
            'delivery + 7 days'
        );
        assert_same(0, $summary['overdue'], 'not due yet');
    },

    'P18-T18 the payout options expose the pending escrow and never add it to max_amount' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $payoutDefaults, $fund): void {
        $enable(7);
        $payoutDefaults();

        $chain  = $makeChain(1, 'p18_t18');
        $member = (int) $chain['levels'][1]['id'];

        $run($paidOrder((int) $chain['buyer']['id'], $makeProduct()));
        $fund($member, '1000.00');

        $options = (new PayoutService())->optionsFor(Wallet::account($member));

        assert_same(12.5, (float) $options['pending_repeat']['total']);
        assert_same(1, (int) $options['pending_repeat']['count']);
        assert_same(1000.0, (float) $options['withdrawable']);
        assert_same(1000.0, (float) $options['max_amount'], 'pending is not withdrawable');
    },

    'P18-T19 an over-limit refusal names the pending amount' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $payoutDefaults, $fund): void {
        $enable(7);
        $payoutDefaults();

        $chain  = $makeChain(1, 'p18_t19');
        $member = (int) $chain['levels'][1]['id'];

        $run($paidOrder((int) $chain['buyer']['id'], $makeProduct()));

        /* Only ₱100 is withdrawable; ₱12.50 more is pending release. */
        $fund($member, '100.00');

        $error = assert_throws(
            static fn (): array => (new PayoutService())->request($member, 'gcash', '09171234567', '500.00'),
            PayoutException::class
        );

        assert_same('withdrawable', $error->reason);
        assert_contains('You can withdraw up to', $error->getMessage());
        assert_contains(money(100), $error->getMessage(), 'the withdrawable figure is named');
        assert_contains('pending release', $error->getMessage());
        assert_contains('12.50', $error->getMessage(), 'the pending amount is named');
        assert_count(0, db()->query('SELECT * FROM payout_requests')->fetchAll(), 'nothing was written');
    },

    'P18-T20 the success flash names the pending amount' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $payoutDefaults, $fund): void {
        $enable(7);
        $payoutDefaults();

        $chain  = $makeChain(1, 'p18_t20');
        $member = (int) $chain['levels'][1]['id'];

        $run($paidOrder((int) $chain['buyer']['id'], $makeProduct()));
        $fund($member, '1000.00');

        $result  = (new PayoutService())->request($member, 'gcash', '09171234567', '500.00');
        $message = (new PayoutController())->successMessage($result);

        assert_same(12.5, (float) $result['pending_repeat']['total']);
        assert_contains('held for payout', $message);
        assert_contains('pending release', $message);
        assert_contains('12.50', $message);
    },

    /* ==========================================================
     *  Books (ESC-T21 … T24)
     * ========================================================== */

    'P18-T21 the reconcile is clean after cancelling pending awards' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run): void {
        test_enable_order_cancel();
        $enable(7);
        $chain = $makeChain(2, 'p18_t21');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        (new PaymentService())->cancelByAdmin((int) Member::primaryAdmin()['id'], $order, 'admin_cancel');

        $reconcile = (new UnilevelService())->reconcile();

        assert_true($reconcile['ok'], json_encode($reconcile['mismatches']));
        assert_same(0, $reconcile['pending_rows']);
        assert_same(0.0, $reconcile['pending_total']);
        assert_same(2, $reconcile['cancelled_rows']);
        assert_same(25.0, $reconcile['cancelled_total']);
        assert_same(0.0, $reconcile['ledger_total'], 'a cancelled award has no credit');
        assert_same(0.0, $reconcile['reversal_total'], 'a cancelled award has no reversal');
        assert_same(0.0, $reconcile['awarded_total']);
    },

    'P18-T22 the reconcile is clean after a release and counts the ledger' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $deliverAt): void {
        $enable(0);
        $chain = $makeChain(2, 'p18_t22');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        $deliverAt($order, date('Y-m-d H:i:s'));
        $release($order);

        $reconcile = (new UnilevelService())->reconcile();

        assert_true($reconcile['ok'], json_encode($reconcile['mismatches']));
        assert_same(2, $reconcile['credited_rows']);
        assert_same(25.0, $reconcile['credited_total']);
        assert_same(25.0, $reconcile['ever_credited_total']);
        assert_same(25.0, $reconcile['ledger_total']);
        assert_same(0, $reconcile['pending_rows']);
        assert_same(25.0, $reconcile['awarded_total']);
    },

    'P18-T23 the reconcile reports a pending award left on a cancelled order' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $awards): void {
        test_enable_order_cancel();
        $enable(7);
        $chain = $makeChain(1, 'p18_t23');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);
        (new PaymentService())->cancelByAdmin((int) Member::primaryAdmin()['id'], $order, 'admin_cancel');

        /* Put one row back: the exact money bug the reconcile exists for. */
        db()->prepare(
            "UPDATE unilevel_awards SET status = 'pending', voided_at = NULL, void_reason = NULL
              WHERE id = ?"
        )->execute([(int) $awards($order)[0]['id']]);

        $reconcile = (new UnilevelService())->reconcile();
        $kinds     = array_column($reconcile['mismatches'], 'kind');

        assert_same(false, $reconcile['ok']);
        assert_true(in_array('pending_on_cancelled', $kinds, true), 'the stray pending row is reported');
    },

    'P18-T24 the monthly close includes pending and credited awards' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $settings): void {
        $enable(7);

        $period = UnilevelCycle::previous(UnilevelCycle::periodOf());
        [$start] = UnilevelCycle::bounds($period);

        $settings(['unilevel_ledger_since' => $period . '-01 00:00:00']);

        $chain = $makeChain(2, 'p18_t24');

        /* One order paid inside the closed month, still pending. */
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct(), ['paid_at' => $start]);
        $run($order);

        $close = UnilevelCycle::close($period);

        assert_same(1, $close['closed']);
        assert_same(25.0, (float) $close['award_total'], 'the earned total includes the pending release');
    },

    /* ==========================================================
     *  Surfaces (ESC-T27, T28)
     * ========================================================== */

    'P18-T27 the CSV carries the release date and the hold' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $release, $deliverAt): void {
        $enable(0);
        $chain = $makeChain(1, 'p18_t27');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);

        $pending = UnilevelAward::csvRows(UnilevelAward::forOrder($order));
        $header  = UnilevelAward::csvHeader();

        assert_same('Hold days', $header[array_key_last($header) - 1]);
        assert_same('Released at', $header[array_key_last($header)]);
        assert_count(count($header), $pending[0]);
        assert_same('pending', $pending[0][10]);
        assert_same('', $pending[0][count($header) - 1], 'a pending row has no release date');

        $deliverAt($order, date('Y-m-d H:i:s'));
        $release($order);

        $credited = UnilevelAward::csvRows(UnilevelAward::forOrder($order));

        assert_same('credited', $credited[0][10]);
        assert_not_same('', $credited[0][count($header) - 1], 'a credited row has a release date');
    },

    'P18-T28 the admin ledger filters accept pending and cancelled' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $deliverAt): void {
        $enable(0);
        $chain = $makeChain(1, 'p18_t28');
        $order = $paidOrder((int) $chain['buyer']['id'], $makeProduct());

        $run($order);

        $pending = UnilevelAward::normaliseFilters(['status' => 'pending']);
        assert_same('pending', $pending['status']);
        assert_same(1, UnilevelAward::countAdmin($pending));

        (new UnilevelService())->reverseForOrder($order, 'Order cancelled');

        $cancelled = UnilevelAward::normaliseFilters(['status' => 'cancelled']);
        assert_same('cancelled', $cancelled['status']);
        assert_same(1, UnilevelAward::countAdmin($cancelled));
        assert_same(0, UnilevelAward::countAdmin(UnilevelAward::normaliseFilters(['status' => 'pending'])));

        $stats = UnilevelAward::stats();
        assert_same(0.0, $stats['pending_total']);
        assert_same(1, $stats['cancelled']);
        assert_same(0.0, $stats['unrecovered'], 'a cancelled award is not a shortfall');
    },

    'P18-T31 totals and breakdowns expose pending next to credited' => function () use ($enable, $makeChain, $makeProduct, $paidOrder, $run, $makeUser): void {
        $enable(7);
        $chain  = $makeChain(1, 'p18_t31');
        $member = (int) $chain['levels'][1]['id'];

        $run($paidOrder((int) $chain['buyer']['id'], $makeProduct()));
        $run($paidOrder((int) $chain['buyer']['id'], $makeProduct()));

        $totals = UnilevelAward::totalsForUser($member);

        assert_same(25.0, $totals['pending']);
        assert_same(2, $totals['pending_count']);
        assert_same(0.0, $totals['credited']);
        assert_same(25.0, $totals['total_awarded']);
        assert_same(25.0, $totals['earned_this_month']);

        $levels = UnilevelAward::levelBreakdown($member);

        assert_same(25.0, $levels[1]['pending_amount']);
        assert_same(2, $levels[1]['pending_count']);
        assert_same(0.0, $levels[1]['amount']);

        $byBuyer = UnilevelAward::creditedByBuyer($member);
        $buyerId = (int) $chain['buyer']['id'];

        assert_same(25.0, $byBuyer[1][$buyerId]['pending_amount']);
        assert_same(2, $byBuyer[1][$buyerId]['pending_count']);
        assert_same(0.0, $byBuyer[1][$buyerId]['amount']);
    },
];
