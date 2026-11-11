<?php
declare(strict_types=1);

/* ==============================================================
 *  ReportTest — the Phase 15 reporting read model and CSV builder
 *  (plan §7 Phase 15, §6.8)
 *
 *  Every case seeds the rows it asserts on (inside the harness
 *  transaction, rolled back afterwards), so the numbers do not
 *  depend on what another test left behind.
 * ============================================================== */

/** A paid order with one line, for report fixtures. */
$paidOrder = static function (
    int $userId,
    float $total,
    string $status = 'paid',
    ?string $paidAt = null,
    string $productName = 'Coffee',
    int $quantity = 1,
    ?string $sku = 'COF-1'
): int {
    /* A real catalogue row so the item is a genuine order line; the
     * helper's own line values are then overwritten with the fixture's. */
    $productId = (int) db()->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();

    $orderId = test_order($userId, [['product_id' => $productId, 'quantity' => $quantity]], $status, [
        'total'    => $total,
        'subtotal' => $total,
        'paid_at'  => $paidAt ?? date('Y-m-d H:i:s'),
    ]);

    db()->prepare(
        'UPDATE shop_order_items
            SET product_name = ?, product_sku = ?, quantity = ?, unit_price = ?, line_total = ?
          WHERE order_id = ?'
    )->execute([$productName, $sku, $quantity, $total, $total, $orderId]);

    return $orderId;
};

return [
    /* ---------- Window maths -------------------------------------- */

    'P15 the report window defaults to 30 days and is inclusive' => function (): void {
        $range = ReportService::range(null, null);

        assert_same(date('Y-m-d'), $range['to']);
        assert_same(30, $range['days']);
        assert_same(date('Y-m-d', strtotime('-29 days')), $range['from']);

        $single = ReportService::range('2026-03-01', '2026-03-01');
        assert_same(1, $single['days']);
        assert_same('Mar 1, 2026', $single['label']);
    },

    'P15 the report window swaps a reversed range and caps a long one' => function (): void {
        $swapped = ReportService::range('2026-03-31', '2026-03-01');
        assert_same('2026-03-01', $swapped['from']);
        assert_same('2026-03-31', $swapped['to']);
        assert_same(31, $swapped['days']);

        /* Invalid input falls back to the default window. */
        $invalid = ReportService::range('not-a-date', '2026-13-40');
        assert_same(date('Y-m-d'), $invalid['to']);

        /* More than a year is shortened to the last 366 days ending at `to`. */
        $long = ReportService::range('2020-01-01', '2026-11-11');
        assert_same(ReportService::MAX_DAYS, $long['days']);
        assert_same('2025-11-11', $long['from']);
    },

    /* ---------- Sales ------------------------------------------------- */

    'P15 sales today, in the window and all time count paid orders only' => function () use ($paidOrder): void {
        $user = test_user('rep_sales', 'member');

        $paidOrder((int) $user['id'], 300.00, 'paid', date('Y-m-d H:i:s'));
        $paidOrder((int) $user['id'], 500.00, 'delivered', date('Y-m-d H:i:s'));
        $paidOrder((int) $user['id'], 999.00, 'cancelled', date('Y-m-d H:i:s'));
        $paidOrder((int) $user['id'], 400.00, 'payment_review', null);

        $today = Report::salesToday();
        assert_same(2, $today['orders'], 'cancelled and unpaid orders are not sales');
        assert_same(800.0, $today['amount']);
        assert_same(400.0, $today['average']);

        $last30 = Report::salesLast30();
        assert_true($last30['orders'] >= 2);
        assert_true($last30['amount'] >= 800.0);

        $range = Report::paidBetween(date('Y-m-d'), date('Y-m-d'));
        assert_same(2, $range['orders']);
        assert_same(800.0, $range['amount']);

        $all = Report::salesAllTime();
        assert_true($all['orders'] >= 2);
    },

    'P15 sales by day zero-fills every day in the window' => function () use ($paidOrder): void {
        $user = test_user('rep_days', 'member');
        $day  = date('Y-m-d', strtotime('-3 days'));

        $paidOrder((int) $user['id'], 250.00, 'paid', $day . ' 10:00:00');

        $days = Report::salesByDay(date('Y-m-d', strtotime('-4 days')), date('Y-m-d'));

        assert_count(5, $days);
        assert_same(date('Y-m-d', strtotime('-4 days')), $days[0]['day']);
        assert_same(0, $days[0]['orders']);

        $target = null;
        foreach ($days as $row) {
            if ($row['day'] === $day) {
                $target = $row;
            }
        }

        assert_not_null($target);
        assert_same(1, $target['orders']);
        assert_same(250.0, $target['amount']);
    },

    'P15 orders by status keep the lifecycle order' => function (): void {
        $user = test_user('rep_status', 'member');
        test_order((int) $user['id'], [], 'pending');

        $rows  = Report::ordersByStatus();
        $order = array_map(static fn (array $row): string => $row['status'], $rows);

        assert_same(
            [
                'pending', 'payment_review', 'payment_failed', 'paid', 'packing',
                'ready_to_ship', 'shipped', 'delivered', 'completed', 'cancelled',
            ],
            $order
        );

        $pending = $rows[0];
        assert_true($pending['count'] >= 1);
    },

    'P15 top products rank paid lines and exclude unpaid orders' => function () use ($paidOrder): void {
        $user = test_user('rep_top', 'member');

        $paidOrder((int) $user['id'], 900.00, 'paid', date('Y-m-d H:i:s'), 'Coffee', 3);
        $paidOrder((int) $user['id'], 500.00, 'delivered', date('Y-m-d H:i:s'), 'Spirulina', 1);
        $paidOrder((int) $user['id'], 100.00, 'payment_review', null, 'Kojic Soap', 9);

        $top = Report::topProducts(date('Y-m-d'), date('Y-m-d'), 5);

        assert_true($top !== [], 'paid lines must produce at least one row');
        assert_same('Coffee', $top[0]['name']);
        assert_same(3, $top[0]['units']);
        assert_same(900.0, $top[0]['amount']);
        assert_not_contains('Kojic Soap', (string) json_encode($top), 'unpaid lines are not sales');
    },

    'P15 sales by method groups paid orders' => function () use ($paidOrder): void {
        $user = test_user('rep_method', 'member');

        $paidOrder((int) $user['id'], 300.00);
        $paidOrder((int) $user['id'], 200.00);

        $rows = Report::salesByMethod(date('Y-m-d'), date('Y-m-d'));
        $byMethod = [];

        foreach ($rows as $row) {
            $byMethod[$row['method']] = $row;
        }

        assert_true(isset($byMethod['ewallet']), 'the fixture orders are e-wallet orders');
        assert_true($byMethod['ewallet']['orders'] >= 2);
        assert_true($byMethod['ewallet']['amount'] >= 500.0);
    },

    /* ---------- Commissions ------------------------------------------ */

    'P15 commissions by level separate credited from skipped' => function (): void {
        $user   = test_user('rep_comm', 'member');
        $source = test_user('rep_comm_src', 'member');
        $pkg    = Package::findBySlug('package-a') ?? [];
        $activationId = Activation::create([
            'user_id'        => (int) $user['id'],
            'package_id'     => (int) $pkg['id'],
            'amount'         => (float) $pkg['price'],
            'payment_method' => 'code',
            'status'         => 'approved',
        ]);

        Commission::create([
            'user_id' => (int) $user['id'], 'type' => 'direct_referral', 'amount' => 500.00,
            'level' => 1, 'source_user_id' => (int) $source['id'],
            'package_id' => (int) $pkg['id'], 'activation_id' => $activationId,
            'status' => 'credited',
        ]);
        Commission::create([
            'user_id' => (int) $user['id'], 'type' => 'indirect_referral', 'amount' => 100.00,
            'level' => 2, 'source_user_id' => (int) $source['id'],
            'package_id' => (int) $pkg['id'], 'activation_id' => $activationId,
            'status' => 'credited',
        ]);
        Commission::create([
            'user_id' => (int) $user['id'], 'type' => 'indirect_referral', 'amount' => 50.00,
            'level' => 3, 'source_user_id' => (int) $source['id'],
            'package_id' => (int) $pkg['id'], 'activation_id' => $activationId,
            'status' => 'skipped', 'reason' => 'inactive upline',
        ]);

        $levelRows = Report::commissionsByLevel(date('Y-m-d'), date('Y-m-d'));
        $byLevel   = [];

        foreach ($levelRows as $row) {
            $byLevel['L' . $row['level'] . ':' . $row['type']] = $row;
        }

        assert_same(500.0, $byLevel['L1:direct_referral']['amount'] ?? null);
        assert_same(100.0, $byLevel['L2:indirect_referral']['amount'] ?? null);
        assert_same(1, $byLevel['L3:indirect_referral']['skipped'] ?? null);

        $summary = Report::commissionSummary(date('Y-m-d'), date('Y-m-d'));
        assert_true($summary['amount'] >= 600.0);
        assert_true($summary['count'] >= 2);
        assert_true($summary['skipped'] >= 1);
        assert_true($summary['recipients'] >= 1);
    },

    /* ---------- Wallets and payouts ---------------------------------- */

    'P15 the wallet float equals the sum of both buckets' => function (): void {
        $expected = db()->query(
            'SELECT COUNT(*) AS accounts,
                    COALESCE(SUM(ewallet_balance), 0) AS float_total,
                    COALESCE(SUM(withdrawable_balance), 0) AS withdrawable
               FROM users'
        )->fetch();

        $float = Report::walletFloat();

        assert_same((int) $expected['accounts'], $float['accounts']);
        assert_same(round((float) $expected['float_total'], 2), $float['float']);
        assert_same(round((float) $expected['withdrawable'], 2), $float['withdrawable']);
        assert_true($float['withdrawable'] <= $float['float']);
    },

    'P15 the wallet statement export rows are ordered and member-scoped' => function (): void {
        $juan = test_user('juan', 'member');

        db()->prepare(
            "INSERT INTO ewallet_ledger
               (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
             VALUES (?, 'credit', 10.00, 10.00, 10.00, 'adjustment', 91001, 'Report fixture')"
        )->execute([(int) $juan['id']]);

        $rows = Report::walletRows(date('Y-m-d'), date('Y-m-d'));

        assert_true($rows !== []);
        $previous = 0;
        $found    = false;

        foreach ($rows as $row) {
            assert_true((int) $row['id'] > $previous, 'rows are ordered by id');
            $previous = (int) $row['id'];

            if ((string) $row['username'] === (string) $juan['username']) {
                $found = true;
            }
        }

        assert_true($found, 'the member fixture movement is in the export');
    },

    'P15 the payout summary separates held, completed and rejected' => function (): void {
        $user = test_user('rep_payout', 'member');

        $insert = static function (int $userId, string $status, float $amount, float $fee): void {
            db()->prepare(
                'INSERT INTO payout_requests
                   (user_id, amount, payout_method, payout_account, service_fee_pct,
                    service_fee_amount, status, requested_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([$userId, $amount, 'gcash', '09170000000', 5.00, $fee, $status]);
        };

        $insert((int) $user['id'], 'pending', 500.00, 25.00);
        $insert((int) $user['id'], 'completed', 300.00, 15.00);
        $insert((int) $user['id'], 'rejected', 200.00, 10.00);

        $summary = Report::payoutSummary(date('Y-m-d'), date('Y-m-d'));
        $byStatus = [];

        foreach ($summary['by_status'] as $row) {
            $byStatus[$row['status']] = $row;
        }

        assert_same(1, $byStatus['pending']['count'] ?? null);
        assert_same(1, $byStatus['completed']['count'] ?? null);
        assert_same(1, $byStatus['rejected']['count'] ?? null);
        assert_true($summary['held'] >= 500.0);
        assert_true($summary['held_requests'] >= 1);
        assert_true($summary['completed_amount'] >= 300.0);
        assert_true($summary['completed_fees'] >= 15.0);
        assert_true($summary['by_method'] !== []);
    },

    /* ---------- Registration codes ------------------------------------ */

    'P15 code revenue counts issued, redeemed and expired codes' => function (): void {
        $rows = Report::codeRevenue();
        $byPackage = [];

        foreach ($rows as $row) {
            $byPackage[$row['package']] = $row;
        }

        assert_true(isset($byPackage['Package A']), 'Package A is seeded');
        assert_true($byPackage['Package A']['issued'] >= 2, 'two demo Package A codes');
        assert_true($byPackage['Package A']['unused'] >= 1);
        assert_true($byPackage['Package A']['expired'] >= 1);
        assert_same(0, $byPackage['Package A']['used'], 'nothing redeemed in a fresh fixture');
        assert_true($byPackage['Package A']['issued_value'] > 0);
    },

    /* ---------- CSV exports ------------------------------------------- */

    'P15 each export builds a filename, header and rows' => function (): void {
        $range = ReportService::range(date('Y-m-d'), date('Y-m-d'));
        $service = new ReportService();

        foreach (array_keys(ReportService::EXPORTS) as $type) {
            $export = $service->export($type, $range);

            assert_same('ecowealth_' . $type . '_' . $range['from'] . '_' . $range['to'] . '.csv', $export['filename']);
            assert_true($export['header'] !== [], $type . ' has a header');
            assert_true(is_array($export['rows']), $type . ' has rows');

            foreach ($export['rows'] as $row) {
                assert_same(count($export['header']), count($row), $type . ' row width matches the header');
            }
        }
    },

    'P15 an unknown export type is refused' => function (): void {
        $range = ReportService::range(null, null);

        assert_throws(
            static fn () => (new ReportService())->export('everything', $range),
            InvalidArgumentException::class
        );
    },
];
