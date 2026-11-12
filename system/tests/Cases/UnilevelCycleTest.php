<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelCycleTest — Phase 17C, the monthly Active clock
 *  (plan §12.7, §14.8; tests P17C-T40 … T50)
 *
 *  The close and its resolution order are complete in 17C; 17F only
 *  wraps close() in an operational job. These cases prove the two
 *  clocks, the immutable snapshot, the live fallback, the batched
 *  lookup and the timezone boundaries — without any cron.
 *
 *  Run this group alone with:  php tests/run.php UnilevelCycle
 * ============================================================== */

/* --------------------------------------------------------------
 *  Fixtures
 * -------------------------------------------------------------- */

$makeUser = static function (string $prefix = 'p17c_cycle'): array {
    return test_user($prefix . '_' . bin2hex(random_bytes(4)));
};

/**
 * buyer → L1 → … → Ldepth → NULL.
 *
 * @return array{buyer: array<string, mixed>, levels: array<int, array<string, mixed>>}
 */
$makeChain = static function (int $depth, string $prefix) use ($makeUser): array {
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

/** One repeat-purchase product (P1 shape). */
$makeProduct = static fn (): int => Product::save([
    'name' => 'P17C Cycle ' . bin2hex(random_bytes(3)),
    'sku'  => 'P17C-CYC-' . strtoupper(bin2hex(random_bytes(3))),
    'price' => '300.00', 'pv' => '250.00', 'unilevel_enabled' => '1',
    'unilevel_base' => 'auto', 'stock' => '100', 'status' => 'active',
], null, ProductUnilevel::referencePreset());

/** A paid order with one priced line; returns its id. */
$order = static function (int $userId, string $paidAt, float $lineTotal, int $productId = 1): int {
    $orderId = test_order($userId, [], 'paid', [
        'paid_at'  => $paidAt,
        'subtotal' => number_format($lineTotal, 2, '.', ''),
        'total'    => number_format($lineTotal, 2, '.', ''),
    ]);

    db()->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, quantity, unit_price, line_total,
            unit_base, base_total, reservation_state)
         VALUES (?, ?, 'Cycle fixture', 1, ?, ?, ?, ?, 'committed')"
    )->execute([
        $orderId,
        $productId,
        number_format($lineTotal, 2, '.', ''),
        number_format($lineTotal, 2, '.', ''),
        number_format($lineTotal, 2, '.', ''),
        number_format($lineTotal, 2, '.', ''),
    ]);

    return $orderId;
};

$enable = static function (): void {
    setting_set('unilevel_enabled', '1');
    setting_set('unilevel_max_levels', '10');
    setting_set('unilevel_requires_active_upline', '1');
    setting_set('unilevel_active_threshold', '500');
    setting_set('unilevel_reversal_enabled', '1');
};

$awards = static fn (int $orderId): array => UnilevelAward::forOrder($orderId);

$sum = static function (array $rows): float {
    $total = 0.0;

    foreach ($rows as $row) {
        if ((string) $row['status'] === 'credited') {
            $total += (float) $row['amount'];
        }
    }

    return round($total, 2);
};

$questions = static function (string $name = 'Com_select'): int {
    $row = db()->query("SHOW SESSION STATUS LIKE '" . $name . "'")->fetch();

    return (int) ($row['Value'] ?? 0);
};

return [
    'P17C-T40 an October shortfall blocks c for November while the others pay' => function () use ($enable, $makeChain, $makeProduct, $order, $awards, $sum): void {
        $enable();
        $product = $makeProduct();
        $chain   = $makeChain(4, 'p17c_t40');

        /* October: c buys ₱450 (below ₱500); b, a and boss buy ₱600. */
        $order((int) $chain['levels'][1]['id'], '2026-10-10 10:00:00', 450.00, $product);
        $order((int) $chain['levels'][2]['id'], '2026-10-11 10:00:00', 600.00, $product);
        $order((int) $chain['levels'][3]['id'], '2026-10-12 10:00:00', 600.00, $product);
        $order((int) $chain['levels'][4]['id'], '2026-10-13 10:00:00', 600.00, $product);

        UnilevelCycle::close('2026-10');

        assert_false(UnilevelCycle::isActive((int) $chain['levels'][1]['id'], '2026-11'));
        assert_true(UnilevelCycle::isActive((int) $chain['levels'][2]['id'], '2026-11'));

        /* November: the buyer places an order; c is level 1. */
        $november = test_order((int) $chain['buyer']['id'], [], 'paid', [
            'paid_at' => '2026-11-05 10:00:00', 'subtotal' => '600.00', 'total' => '600.00',
        ]);
        db()->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total,
                unit_base, base_total, reservation_state)
             VALUES (?, ?, 'Cycle fixture', 2, 300.00, 600.00, 250.00, 500.00, 'committed')"
        )->execute([$november, $product]);

        (new UnilevelService())->onOrderPaid($november);
        $rows = $awards($november);

        assert_count(4, $rows);
        assert_same('skipped', $rows[0]['status']);
        assert_same('recipient is not Active for 2026-10', $rows[0]['reason']);
        assert_same(2, (int) $rows[1]['level']);
        assert_same('credited', $rows[1]['status']);
        assert_same(65.0, $sum($rows));
    },

    'P17C-T41 a November ₱2,000 month restores c for December' => function () use ($enable, $makeChain, $makeProduct, $order, $awards, $sum): void {
        $enable();
        $product = $makeProduct();
        $chain   = $makeChain(4, 'p17c_t41');

        $order((int) $chain['levels'][1]['id'], '2026-11-02 10:00:00', 2000.00, $product);
        $order((int) $chain['levels'][2]['id'], '2026-11-03 10:00:00', 600.00, $product);
        $order((int) $chain['levels'][3]['id'], '2026-11-04 10:00:00', 600.00, $product);
        $order((int) $chain['levels'][4]['id'], '2026-11-05 10:00:00', 600.00, $product);

        UnilevelCycle::close('2026-11');

        assert_true(UnilevelCycle::isActive((int) $chain['levels'][1]['id'], '2026-12'));

        $december = test_order((int) $chain['buyer']['id'], [], 'paid', [
            'paid_at' => '2026-12-06 10:00:00', 'subtotal' => '600.00', 'total' => '600.00',
        ]);
        db()->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total,
                unit_base, base_total, reservation_state)
             VALUES (?, ?, 'Cycle fixture', 2, 300.00, 600.00, 250.00, 500.00, 'committed')"
        )->execute([$december, $product]);

        (new UnilevelService())->onOrderPaid($december);
        $rows = $awards($december);

        assert_count(4, $rows);
        assert_same('credited', $rows[0]['status']);
        assert_same(90.0, $sum($rows));
    },

    'P17C-T42 a member with no snapshot row is Active' => function () use ($enable, $makeChain, $makeProduct): void {
        $enable();

        /* Close October before the member exists: there is no row for
         * them, so the missing flag means Active (reference §7.5). */
        UnilevelCycle::close('2026-10');

        $product = $makeProduct();
        $chain   = $makeChain(1, 'p17c_t42');

        assert_true(UnilevelCycle::isActive((int) $chain['levels'][1]['id'], '2026-11'));

        $order = test_order((int) $chain['buyer']['id'], [], 'paid', [
            'paid_at' => '2026-11-10 10:00:00', 'subtotal' => '300.00', 'total' => '300.00',
        ]);
        db()->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total,
                unit_base, base_total, reservation_state)
             VALUES (?, ?, 'Cycle fixture', 1, 300.00, 300.00, 250.00, 250.00, 'committed')"
        )->execute([$order, $product]);

        (new UnilevelService())->onOrderPaid($order);
        $rows = UnilevelAward::forOrder($order);

        assert_count(1, $rows);
        assert_same('credited', $rows[0]['status']);
        assert_same(12.5, (float) $rows[0]['amount']);
    },

    'P17C-T43 cancelling after the close never rewrites the snapshot' => function () use ($enable, $makeChain, $makeProduct, $order): void {
        $enable();
        $product = $makeProduct();
        $chain   = $makeChain(2, 'p17c_t43');
        $c       = (int) $chain['levels'][1]['id'];

        $october = test_order($c, [], 'paid', [
            'paid_at' => '2026-10-20 10:00:00', 'subtotal' => '450.00', 'total' => '450.00',
        ]);
        db()->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total,
                unit_base, base_total, reservation_state)
             VALUES (?, ?, 'Cycle fixture', 1, 450.00, 450.00, 450.00, 450.00, 'committed')"
        )->execute([$october, $product]);

        UnilevelCycle::close('2026-10');

        $before = db()->query(
            "SELECT personal_purchases, qualified FROM unilevel_activity
              WHERE period = '2026-10' AND user_id = " . $c
        )->fetch();

        $paid = test_order((int) $chain['buyer']['id'], [], 'paid', [
            'paid_at' => '2026-10-21 10:00:00', 'subtotal' => '600.00', 'total' => '600.00',
        ]);
        db()->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total,
                unit_base, base_total, reservation_state)
             VALUES (?, ?, 'Cycle fixture', 2, 300.00, 600.00, 250.00, 500.00, 'committed')"
        )->execute([$paid, $product]);

        (new UnilevelService())->onOrderPaid($paid);
        (new UnilevelService())->reverseForOrder($paid, 'Cancelled after the close');

        $after = db()->query(
            "SELECT personal_purchases, qualified FROM unilevel_activity
              WHERE period = '2026-10' AND user_id = " . $c
        )->fetch();

        assert_same($before, $after, 'a closed period is immutable');

        foreach (UnilevelAward::forOrder($paid) as $row) {
            assert_same('voided', $row['status'], 'the award is still voided');
        }
    },

    'P17C-T44 close writes one row per account, one header and the award snapshot' => function () use ($enable, $makeChain, $makeProduct, $order): void {
        $enable();
        $product = $makeProduct();
        $chain   = $makeChain(4, 'p17c_t44');

        $october = test_order((int) $chain['buyer']['id'], [], 'paid', [
            'paid_at' => '2026-10-15 10:00:00', 'subtotal' => '600.00', 'total' => '600.00',
        ]);
        db()->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total,
                unit_base, base_total, reservation_state)
             VALUES (?, ?, 'Cycle fixture', 2, 300.00, 600.00, 250.00, 500.00, 'committed')"
        )->execute([$october, $product]);

        (new UnilevelService())->onOrderPaid($october);

        $result = UnilevelCycle::close('2026-10');
        $users  = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        assert_same(1, $result['closed']);
        assert_same($users, $result['members'], 'all accounts get a snapshot row');
        assert_same(1, (int) db()->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn());
        assert_same(
            $users,
            (int) db()->query("SELECT COUNT(*) FROM unilevel_activity WHERE period = '2026-10'")->fetchColumn()
        );

        $header = db()->query("SELECT * FROM unilevel_periods WHERE period = '2026-10'")->fetch();
        assert_same($result['members'], (int) $header['members_total']);
        assert_same($result['qualified'], (int) $header['qualified_count']);
        assert_same(90.0, (float) $header['award_total'], 'the credited awards of the period are snapshotted');
        assert_same(500.0, (float) $header['threshold']);
    },

    'P17C-T45 a second close writes nothing' => function () use ($enable, $makeChain, $makeProduct): void {
        $enable();
        $makeProduct();
        $makeChain(1, 'p17c_t45');

        UnilevelCycle::close('2026-10');
        $before = [
            (int) db()->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
        ];

        $again = UnilevelCycle::close('2026-10');

        assert_same(0, $again['closed']);
        assert_same(true, $again['skipped']);
        assert_same('already closed', $again['reason']);
        assert_same($before, [
            (int) db()->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
        ]);
    },

    'P17C-T46 a future period is refused and nothing is written' => function (): void {
        $before = [
            (int) db()->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
        ];

        $error = assert_throws(
            static fn (): array => UnilevelCycle::close('2099-01'),
            InvalidArgumentException::class
        );

        assert_contains('future', $error->getMessage());
        assert_same($before, [
            (int) db()->query('SELECT COUNT(*) FROM unilevel_periods')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM unilevel_activity')->fetchColumn(),
        ]);
    },

    'P17C-T47 the live fallback agrees with a later close' => function () use ($makeUser, $order): void {
        $high = $makeUser('p17c_t47_high');
        $low  = $makeUser('p17c_t47_low');

        $order((int) $high['id'], '2026-09-10 10:00:00', 600.00);
        $order((int) $low['id'], '2026-09-11 10:00:00', 450.00);

        assert_true(UnilevelCycle::isActive((int) $high['id'], '2026-10'), 'an open month is computed live');
        assert_false(UnilevelCycle::isActive((int) $low['id'], '2026-10'));

        UnilevelCycle::close('2026-09');

        assert_true(UnilevelCycle::isActive((int) $high['id'], '2026-10'), 'the snapshot agrees');
        assert_false(UnilevelCycle::isActive((int) $low['id'], '2026-10'));
    },

    'P17C-T48 periodOf and bounds handle the Manila midnight' => function (): void {
        assert_same('2026-10', UnilevelCycle::periodOf('2026-10-31 23:59:59'));
        assert_same('2026-11', UnilevelCycle::periodOf('2026-11-01 00:00:00'));
        assert_same('2025-12', UnilevelCycle::previous('2026-01'));
        assert_same('2026-11', UnilevelCycle::previous('2026-12'));

        assert_same(['2026-10-01 00:00:00', '2026-11-01 00:00:00'], UnilevelCycle::bounds('2026-10'));

        /* Half-open: the end belongs to the next period. */
        [$start, $end] = UnilevelCycle::bounds('2026-10');
        assert_true(strtotime($end) > strtotime('2026-10-31 23:59:59'));
        assert_same('2026-10', date('Y-m', (int) strtotime($start)));
    },

    'P17C-T49 activeMap is one previous-period query for ten recipients' => function () use ($makeUser, $questions): void {
        UnilevelCycle::close('2026-10');

        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = (int) $makeUser('p17c_t49_' . $i)['id'];
        }

        /* Warm the settings cache before measuring: the threshold is
         * already loaded in every real request. */
        setting('unilevel_active_threshold');

        $before = $questions();
        $map    = UnilevelCycle::activeMap($ids, '2026-11');
        $after  = $questions();

        assert_count(10, $map);
        assert_same(1, $after - $before, 'one snapshot query, not one per recipient');
    },

    'P17C-T50 the close still snapshots when the stream is off' => function () use ($makeChain, $makeProduct): void {
        setting_set('unilevel_enabled', '0');

        $product = $makeProduct();
        $chain   = $makeChain(1, 'p17c_t50');

        $october = test_order((int) $chain['buyer']['id'], [], 'paid', [
            'paid_at' => '2026-10-15 10:00:00', 'subtotal' => '300.00', 'total' => '300.00',
        ]);
        db()->prepare(
            "INSERT INTO shop_order_items
               (order_id, product_id, product_name, quantity, unit_price, line_total,
                unit_base, base_total, reservation_state)
             VALUES (?, ?, 'Cycle fixture', 1, 300.00, 300.00, 250.00, 250.00, 'committed')"
        )->execute([$october, $product]);

        $summary = (new UnilevelService())->onOrderPaid($october);
        assert_count(0, UnilevelAward::forOrder($october));
        assert_same('disabled', $summary['reason']);
        assert_not_null(Order::find($october)['unilevel_processed_at']);

        $result = UnilevelCycle::close('2026-10');

        assert_same(1, $result['closed']);
        assert_same(0.0, $result['award_total']);
        assert_same(
            1,
            (int) db()->query("SELECT COUNT(*) FROM unilevel_periods WHERE period = '2026-10'")->fetchColumn()
        );
        assert_same(
            (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            (int) db()->query("SELECT COUNT(*) FROM unilevel_activity WHERE period = '2026-10'")->fetchColumn()
        );
    },
];
