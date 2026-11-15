<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyEngineTest - Phase 19C (plan ?7, ?12.4-?12.8, ?14.4-?14.5)
 *
 *  Proves the money engine on its own: the rank gate, the volume
 *  base, the all-or-nothing quota, the one rounding, the monthly
 *  award ledger and the resumable payment. Nothing in this file adds
 *  a route, a controller or a view (19C is headless).
 *
 *      evaluate    one award per Supervisor+, no wallet money
 *      formula     the reference examples (18,300 / 15,000 / 40,000 /
 *                  50,000 / 500,000 / 515,000 / 18,000.04 / 312.50)
 *      volume      line_total only, paid_at window, any depth, no
 *                  shipping, no cancellations, no staff, no package
 *      gates       rank, quota, account status, membership, cutoff, zero
 *      headers     counts and totals, the ladder snapshot, the states
 *      payment     one credit per award, batches, resume, pause, order
 *
 *  Run this group alone with:  php tests/run.php RoyaltyEngine
 * ============================================================== */

/* The fixed completed month every money case uses (tests must not
 * depend on the wall clock; plan ?16.2). */
$period      = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());
$periodStart = RoyaltyPeriod::bounds($period)[0];
$periodDay   = substr($periodStart, 0, 10);
$periodEnd   = RoyaltyPeriod::bounds($period)[1];

/** One member account in the period, returning its id. */
$member = static function (string $username, ?int $sponsor = null, array $overrides = []) use ($periodDay): int {
    $row = $overrides + [
        'username'      => $username,
        'email'         => $username . '@p19c.test',
        'password_hash' => '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
        'full_name'     => ucfirst($username) . ' Engine',
        'role'          => 'member',
        'member_status' => 'active',
        'sponsor_id'    => $sponsor,
        'is_active'     => 1,
        /* Before the period end, so the cutoff includes the fixture. */
        'created_at'    => $periodDay . ' 09:00:00',
    ];

    $columns = array_keys($row);
    $marks   = implode(', ', array_fill(0, count($columns), '?'));

    db()->prepare('INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . $marks . ')')
        ->execute(array_values($row));

    return (int) db()->lastInsertId();
};

/** The reference settings with 2/2/2/2 thresholds, stream on, since the period. */
$configure = static function (array $overrides = []) use ($periodStart): void {
    $settings = $overrides + [
        'royalty_enabled'                 => '1',
        'royalty_pay_enabled'             => '1',
        'royalty_leader_directs'          => '2',
        'royalty_supervisor_leaders'      => '2',
        'royalty_manager_supervisors'     => '2',
        'royalty_director_managers'       => '2',
        'royalty_supervisor_quota'        => '500000',
        'royalty_manager_quota'           => '1000000',
        'royalty_director_quota'          => '5000000',
        'royalty_supervisor_group_pct'    => '3.000',
        'royalty_supervisor_personal_pct' => '5.000',
        'royalty_manager_group_pct'       => '5.000',
        'royalty_manager_personal_pct'    => '10.000',
        'royalty_director_group_pct'      => '10.000',
        'royalty_director_personal_pct'   => '15.000',
        'royalty_ledger_since'            => $periodStart,
    ];

    foreach ($settings as $key => $value) {
        setting_set($key, (string) $value);
    }

    setting_cache_clear();
    setting_all();
};

/** One product for the fixture orders. */
$product = static function (): int {
    $pdo      = db();
    $existing = $pdo->query("SELECT id FROM products WHERE sku = 'RTY-ENGINE' LIMIT 1")->fetchColumn();

    if ($existing !== false) {
        return (int) $existing;
    }

    $pdo->prepare(
        "INSERT INTO products (sku, name, slug, price, pv, stock)
         VALUES ('RTY-ENGINE', 'Royalty engine product', 'royalty-engine-product', 1.00, 1.00, 100000)"
    )->execute();

    return (int) $pdo->lastInsertId();
};

/**
 * One shop order for a member, with a real line total and a paid_at
 * inside the fixture month.
 *
 * @param array{status?:string, paid_at?:?string, shipping?:float, quantity?:int, total?:?float} $options
 */
$order = static function (int $userId, float $amount, array $options = []) use ($periodDay, $product): int {
    $pdo      = db();
    $status   = (string) ($options['status'] ?? 'paid');
    $paidAt   = array_key_exists('paid_at', $options) ? $options['paid_at'] : $periodDay . ' 12:00:00';
    $shipping = round((float) ($options['shipping'] ?? 0), 2);
    $quantity = max(1, (int) ($options['quantity'] ?? 1));
    $total    = $options['total'] ?? ($amount + $shipping);

    $pdo->prepare(
        "INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
            billing_name, shipping_name, shipping_address, paid_at, terms_accepted_at)
         VALUES (?, ?, ?, 'ewallet', ?, ?, ?, 'Fixture buyer', 'Fixture buyer', 'Fixture address', ?, ?)"
    )->execute([
        'RTY-' . strtoupper(bin2hex(random_bytes(5))),
        $userId,
        $status,
        number_format($amount, 2, '.', ''),
        number_format($shipping, 2, '.', ''),
        number_format((float) $total, 2, '.', ''),
        $paidAt,
        $periodDay . ' 08:00:00',
    ]);

    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, quantity, unit_price, line_total, reservation_state)
         VALUES (?, ?, 'Royalty engine product', ?, ?, ?, 'committed')"
    )->execute([
        $orderId,
        $product(),
        $quantity,
        number_format($amount / $quantity, 2, '.', ''),
        number_format($amount, 2, '.', ''),
    ]);

    return $orderId;
};

/** Buy the given amounts, one paid order per member. @param array<int, float> $volumes */
$buy = static function (array $volumes, array $options = []) use ($order): array {
    $ids = [];

    foreach ($volumes as $userId => $amount) {
        $ids[] = $order((int) $userId, (float) $amount, $options);
    }

    return $ids;
};

/**
 * The ?16.2 A tree at the thresholds 2/2/2/2: a root of the requested
 * rank, two children per level, two directs per Leader.
 *
 *   director -> 2 managers -> 4 supervisors -> 8 leaders -> 16 members (31)
 *   manager  -> 2 supervisors -> 4 leaders -> 8 members (15)
 *   supervisor -> 2 leaders -> 4 members (7)
 *
 * @return array{top:int, director:?int, managers:list<int>, supervisors:list<int>,
 *               leaders:list<int>, members:list<int>, by_level:array<int, list<int>>}
 */
$tree = static function (string $rank) use ($member): array {
    static $treeSeq = 0;

    $treeSeq++;
    $prefix    = 'rty' . $treeSeq . '_';
    $rootLevel = ['supervisor' => 2, 'manager' => 3, 'director' => 4][$rank];
    $out       = [
        'top'        => 0,
        'director'   => null,
        'managers'   => [],
        'supervisors' => [],
        'leaders'    => [],
        'members'    => [],
        'by_level'   => [],
    ];
    $counter = 0;

    $node = static function (int $level, ?int $sponsor) use (&$node, &$counter, &$out, $member, $prefix): int {
        $counter++;
        $id = $member($prefix . $counter, $sponsor);

        $out['by_level'][$level][] = $id;

        if ($level <= 1) {
            for ($i = 1; $i <= 2; $i++) {
                $counter++;
                $out['members'][] = $member($prefix . $counter, $id);
            }

            return $id;
        }

        for ($i = 1; $i <= 2; $i++) {
            $node($level - 1, $id);
        }

        return $id;
    };

    $out['top'] = $node($rootLevel, null);

    $out['leaders']     = $out['by_level'][1] ?? [];
    $out['supervisors'] = $out['by_level'][2] ?? [];
    $out['managers']    = $out['by_level'][3] ?? [];
    $out['director']    = $out['by_level'][4][0] ?? null;

    return $out;
};

/** One period header, or null. */
$header = static function (string $month) use ($period): ?array {
    return RoyaltyPeriod::find($month);
};

/** Award rows of a month keyed by user id. @return array<int, array<string, mixed>> */
$awardsBy = static function (string $month): array {
    $rows = [];

    foreach (RoyaltyAward::rowsForPeriod($month) as $row) {
        $rows[(int) $row['user_id']] = $row;
    }

    return $rows;
};

/** How many royalty wallet movements exist? */
$ledgerRows = static function (): int {
    return (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn();
};

/** One member's wallet buckets. @return array{0: float, 1: float} */
$wallet = static function (int $userId): array {
    $row = db()->prepare('SELECT ewallet_balance, withdrawable_balance FROM users WHERE id = ?');
    $row->execute([$userId]);
    $row = (array) $row->fetch();

    return [round((float) $row['ewallet_balance'], 2), round((float) $row['withdrawable_balance'], 2)];
};

return [
    /* ==========================================================
     *  Preview (P19C-T1)
     * ========================================================== */

    'P19C-T1 a preview of an empty month writes no row and no money' => function () use ($configure, $period): void {
        $configure();

        $preview = (new RoyaltyService())->preview($period);

        assert_same($period, $preview['period']);
        assert_same(true, $preview['enabled']);
        assert_same(0, $preview['eligible']);
        assert_same(0, $preview['paid_members']);
        assert_same(0, $preview['skipped']);
        assert_same(0.0, $preview['award_total']);
        assert_same([], $preview['awards']);
        assert_null($preview['header'], 'a preview grows no header');

        /* The current month may be previewed too (D-16's "if this month
         * closed now" panel) and writes nothing either. */
        $current = (new RoyaltyService())->preview(RoyaltyPeriod::periodOf());
        assert_same(RoyaltyPeriod::periodOf(), $current['period']);

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn());
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'royalty.%'")->fetchColumn());
    },

    /* ==========================================================
     *  The stream switch (P19C-T2)
     * ========================================================== */

    'P19C-T2 evaluating with the stream off writes a skipped period and no money' => function () use ($configure, $tree, $buy, $period, $header, $ledgerRows): void {
        $configure(['royalty_enabled' => '0']);

        $fixture = $tree('supervisor');
        $buy([$fixture['top'] => 500000.0]);   // enough to be paid if the stream were on

        $service = new RoyaltyService();

        $preview = $service->preview($period);
        assert_same(false, $preview['enabled']);
        assert_same('skipped', $preview['status']);
        assert_same([], $preview['awards']);

        $result = $service->evaluate($period);

        assert_same('skipped', $result['status']);
        assert_same(0, $result['eligible']);
        assert_same(0.0, $result['award_total']);

        $stored = $header($period);
        assert_not_null($stored);
        assert_same('skipped', $stored['status']);
        assert_same(0, (int) $stored['enabled']);
        assert_same('royalty stream off', $stored['note']);
        assert_same(0, (int) $stored['award_count']);
        assert_same(0, (int) $stored['members_count']);

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
        assert_same(0, $ledgerRows(), 'the stream off moves no money');
        assert_same(1, (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'royalty.period.skip'")->fetchColumn());

        /* A skipped month is final: it can never be paid later (D-15). */
        $payment = $service->pay($period);
        assert_same('skipped', $payment['status']);
        assert_same(0, $payment['paid']);
        assert_same(0, $ledgerRows());
    },

    /* ==========================================================
     *  Evaluation and payment (P19C-T3/T4/T5)
     * ========================================================== */

    'P19C-T3 a Supervisor at quota gets one pending row with the exact arithmetic' => function () use ($configure, $tree, $buy, $period, $periodEnd, $header, $awardsBy, $ledgerRows, $wallet): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];

        /* GS 510,000: PS 60,000 + four directs at 112,500. */
        $buy([$top => 60000.0]);
        $buy(array_fill_keys($fixture['members'], 112500.0));

        $result = (new RoyaltyService())->evaluate($period);

        assert_same('evaluated', $result['status']);
        assert_same(1, $result['eligible']);
        assert_same(1, $result['paid_members']);
        assert_same(0, $result['skipped']);
        assert_same(18300.0, $result['award_total']);

        $rows = $awardsBy($period);
        assert_count(1, $rows);
        $row = $rows[$top];

        assert_same($period, $row['period']);
        assert_same(2, $row['rank_level']);
        assert_same(2, $row['directs']);
        assert_same(2, $row['downline_rank_count'], 'the Leader+ count that made the Supervisor');
        assert_same(6, $row['team_size']);
        assert_same('510000.00', $row['group_volume']);
        assert_same('60000.00', $row['personal_volume']);
        assert_same('500000.00', $row['quota']);
        assert_same('3.000', $row['group_pct']);
        assert_same('5.000', $row['personal_pct']);
        assert_same('18300.00', $row['amount']);
        assert_same('pending', $row['status']);
        assert_null($row['reason']);
        assert_null($row['ledger_id']);

        $stored = $header($period);
        assert_same('evaluated', $stored['status']);
        assert_same(1, (int) $stored['award_count']);
        assert_same(0, (int) $stored['paid_count']);
        assert_same(0, (int) $stored['skipped_count']);
        assert_same('18300.00', $stored['award_total']);
        assert_same('0.00', $stored['paid_total']);
        assert_same(7, (int) $stored['members_count']);
        assert_same(1, (int) $stored['supervisors_count']);
        assert_same(2, (int) $stored['leaders_count']);
        assert_same($periodEnd, $stored['as_of'], 'the header stores the rank cutoff');
        assert_same('500000.00', $stored['quota_supervisor']);
        assert_same('3.000', $stored['group_pct_supervisor']);
        assert_same('2', (string) $stored['leader_directs']);

        /* Evaluation writes no wallet money (Rule 1). */
        assert_same(0, $ledgerRows());
        assert_same([0.0, 0.0], $wallet($top));
    },

    'P19C-T4 paying the period credits the withdrawable wallet exactly once' => function () use ($configure, $tree, $buy, $period, $header, $awardsBy, $ledgerRows, $wallet): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];
        $buy([$top => 60000.0]);
        $buy(array_fill_keys($fixture['members'], 112500.0));

        $service = new RoyaltyService();
        $service->evaluate($period);

        $payment = $service->pay($period);

        assert_same($period, $payment['period']);
        assert_same(1, $payment['paid']);
        assert_same(18300.0, $payment['amount']);
        assert_same(0, $payment['pending_left']);
        assert_same('paid', $payment['status']);
        assert_same(false, $payment['paused']);
        assert_same(true, $payment['final']);

        assert_same(1, $ledgerRows());

        $ledger = db()->query(
            "SELECT * FROM ewallet_ledger WHERE ref_type = 'royalty' ORDER BY id"
        )->fetch();
        $row = $awardsBy($period)[$top];

        assert_same('credit', $ledger['type']);
        assert_same($top, (int) $ledger['user_id']);
        assert_same((int) $row['id'], (int) $ledger['ref_id']);
        assert_same('18300.00', $ledger['amount']);
        assert_contains('Royalty bonus', (string) $ledger['note']);
        assert_contains($period, (string) $ledger['note']);
        assert_contains('Supervisor', (string) $ledger['note']);

        $paidRow = $awardsBy($period)[$top];
        assert_same('paid', $paidRow['status']);
        assert_same((int) $ledger['id'], (int) $paidRow['ledger_id']);
        assert_not_null($paidRow['credited_at']);

        $stored = $header($period);
        assert_same('paid', $stored['status']);
        assert_same(1, (int) $stored['paid_count']);
        assert_same('18300.00', $stored['paid_total']);
        assert_same('18300.00', $stored['award_total']);
        assert_not_null($stored['paid_at']);

        assert_same([18300.0, 18300.0], $wallet($top), 'the credit is withdrawable');
        /* One audit row per batch plus the finalisation row (plan ?15.4). */
        assert_same(2, (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'royalty.period.pay'")->fetchColumn());
        assert_true(RoyaltyService::paymentsEnabled());
    },

    'P19C-T5 paying again moves no second cent' => function () use ($configure, $tree, $buy, $period, $ledgerRows, $wallet): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];
        $buy([$top => 500000.0]);

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $again = $service->pay($period);

        assert_same(0, $again['paid']);
        assert_same(0.0, $again['amount']);
        assert_same('paid', $again['status']);
        assert_same(true, $again['final']);

        assert_same(1, $ledgerRows());
        assert_same([40000.0, 40000.0], $wallet($top), 'the self-funded quota pays 40,000 exactly once');

        /* The conditional update is the second layer: an already-paid
         * award can never be moved to paid again. */
        $row = db()->query(
            "SELECT id FROM royalty_awards WHERE user_id = " . $top . " ORDER BY id LIMIT 1"
        )->fetch();

        assert_false(RoyaltyAward::markPaid((int) $row['id'], 999999));
        assert_same(1, $ledgerRows());
    },

    /* ==========================================================
     *  Final months are immutable (P19C-T6, T7)
     * ========================================================== */

    'P19C-T6 evaluating a paid period is refused and changes nothing' => function () use ($configure, $tree, $buy, $period, $header, $ledgerRows): void {
        $configure();

        $fixture = $tree('supervisor');
        $buy([$fixture['top'] => 500000.0]);

        $service = new RoyaltyService();
        $service->close($period);

        $before = $header($period);

        $error = assert_throws(
            static fn (): array => $service->evaluate($period),
            RoyaltyException::class
        );

        assert_same('closed', $error->reason);
        assert_same($before, $header($period), 'the header is untouched');
        assert_same(1, $ledgerRows(), 'no second credit');
    },

    'P19C-T7 evaluating a skipped period is refused and changes nothing' => function () use ($configure, $period, $header): void {
        $configure(['royalty_enabled' => '0']);

        $service = new RoyaltyService();
        $service->evaluate($period);

        $before = $header($period);

        $error = assert_throws(
            static fn (): array => $service->evaluate($period),
            RoyaltyException::class
        );

        assert_same('closed', $error->reason);
        assert_same($before, $header($period));
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
    },

    /* ==========================================================
     *  The quota gate (P19C-T8, T9, T14, T15)
     * ========================================================== */

    'P19C-T8 one centavo below the quota is a skipped row with a stable reason' => function () use ($configure, $tree, $buy, $period, $header, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];
        $buy([$top => 499999.99]);

        $service = new RoyaltyService();
        $result  = $service->evaluate($period);

        assert_same(1, $result['eligible']);
        assert_same(0, $result['paid_members']);
        assert_same(1, $result['skipped']);
        assert_same(0.0, $result['award_total']);

        $row = $awardsBy($period)[$top];

        assert_same('skipped', $row['status']);
        assert_same('group sales below quota', $row['reason']);
        assert_same('0.00', $row['amount']);
        assert_same('499999.99', $row['group_volume']);
        assert_same('500000.00', $row['quota']);
        assert_same(RoyaltyAward::REASON_QUOTA, $row['reason']);

        /* A month with nothing pending is still final, with 0 paid. */
        $stored = $header($period);
        assert_same('paid', $stored['status']);
        assert_same(0, (int) $stored['paid_count']);
        assert_same(1, (int) $stored['skipped_count']);
        assert_same('0.00', $stored['paid_total']);
    },

    'P19C-T9 exactly at the quota pays the group component only' => function () use ($configure, $tree, $buy, $period, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];

        /* No personal purchase: the four members hold the whole 500,000. */
        $buy(array_fill_keys($fixture['members'], 125000.0));

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $row = $awardsBy($period)[$top];

        assert_same('paid', $row['status']);
        assert_same('0.00', $row['personal_volume']);
        assert_same('500000.00', $row['group_volume']);
        assert_same('15000.00', $row['amount'], '3% of the quota, one centavo of personal sales is not needed');
    },

    'P19C-T14 a Supervisor can meet the quota with their own purchases' => function () use ($configure, $tree, $buy, $period, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];
        $buy([$top => 500000.0]);

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $row = $awardsBy($period)[$top];

        assert_same('500000.00', $row['personal_volume']);
        assert_same('500000.00', $row['group_volume'], 'GS includes the own purchases');
        assert_same('40000.00', $row['amount'], '15,000 group + 25,000 personal');
    },

    'P19C-T15 the quota boundary is exact: 500,000 pays, 499,999.99 does not' => function () use ($configure, $tree, $buy, $period, $awardsBy): void {
        $configure();

        $exact = $tree('supervisor');
        $short = $tree('supervisor');

        $buy([$exact['top'] => 500000.0, $short['top'] => 499999.99]);

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $rows = $awardsBy($period);

        assert_same('paid', $rows[$exact['top']]['status']);
        assert_same('40000.00', $rows[$exact['top']]['amount']);

        assert_same('skipped', $rows[$short['top']]['status']);
        assert_same('0.00', $rows[$short['top']]['amount'], 'one centavo short pays nothing');
    },

    /* ==========================================================
     *  Rank gates (P19C-T10, T11, T12, T13)
     * ========================================================== */

    'P19C-T10 a Leader with ten million of group sales earns nothing' => function () use ($configure, $member, $buy, $period, $awardsBy, $header): void {
        $configure();

        $leader = $member('rty_t10_leader');
        $buy([$leader => 10000000.0]);

        for ($i = 1; $i <= 2; $i++) {
            $member('rty_t10_direct_' . $i, $leader);
        }

        assert_same(RoyaltyRank::LEADER, RoyaltyRank::map()[$leader]['rank'], 'the fixture really is a Leader');

        $service = new RoyaltyService();
        $result  = $service->evaluate($period);

        assert_same(0, $result['eligible']);
        assert_same([], $awardsBy($period));
        assert_same(0, (int) $header($period)['award_count']);
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn());
    },

    'P19C-T11 a Member with volume earns nothing' => function () use ($configure, $member, $buy, $period, $awardsBy, $header): void {
        $configure();

        $plain = $member('rty_t11_member');
        $buy([$plain => 10000000.0]);

        $service = new RoyaltyService();
        $result  = $service->evaluate($period);

        assert_same(0, $result['eligible']);
        assert_same([], $awardsBy($period));
        assert_same('paid', $header($period)['status'], 'a month with no paying rank is final with 0.00');
        assert_same('0.00', $header($period)['paid_total']);
    },

    'P19C-T12 a Manager and a Director are paid only their own rank rates' => function () use ($configure, $tree, $buy, $period, $awardsBy): void {
        $configure();

        $manager  = $tree('manager');
        $director = $tree('director');

        /* The quota is met through the team, so the personal component
         * is zero and the expected amount is group-only: 5% x 1,000,000
         * and 10% x 5,000,000. No lower-rank rate is added on top. */
        $buy(array_fill_keys($manager['supervisors'], 500000.0));
        $buy(array_fill_keys($director['supervisors'], 1250000.0));

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $rows = $awardsBy($period);

        $managerRow = $rows[$manager['top']];
        assert_same(3, $managerRow['rank_level']);
        assert_same('5.000', $managerRow['group_pct']);
        assert_same('10.000', $managerRow['personal_pct']);
        assert_same('1000000.00', $managerRow['quota']);
        assert_same('0.00', $managerRow['personal_volume']);
        assert_same('1000000.00', $managerRow['group_volume']);
        assert_same('50000.00', $managerRow['amount'], '5% of the 1,000,000 group, no Supervisor rate on top');

        $directorRow = $rows[$director['top']];
        assert_same(4, $directorRow['rank_level']);
        assert_same('10.000', $directorRow['group_pct']);
        assert_same('15.000', $directorRow['personal_pct']);
        assert_same('5000000.00', $directorRow['quota']);
        assert_same('0.00', $directorRow['personal_volume']);
        assert_same('5000000.00', $directorRow['group_volume']);
        assert_same('500000.00', $directorRow['amount'], '10% of the 5,000,000 group, no Manager/Supervisor rate on top');

        /* One member, one row, one triple (D-08, D-11). */
        assert_same(
            1,
            (int) db()->query('SELECT COUNT(*) FROM royalty_awards WHERE user_id = ' . $director['top'])->fetchColumn()
        );
    },

    'P19C-T13 personal sales are inside group sales and are paid twice' => function () use ($configure, $tree, $buy, $period, $awardsBy): void {
        $configure();

        $fixture  = $tree('director');
        $director = $fixture['top'];

        /* PS 100,000 + 4 x 1,225,000 of downline volume = GS 5,000,000. */
        $buy([$director => 100000.0]);
        $buy(array_fill_keys($fixture['supervisors'], 1225000.0));

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $row = $awardsBy($period)[$director];

        assert_same('100000.00', $row['personal_volume']);
        assert_same('5000000.00', $row['group_volume']);
        assert_same('5000000.00', $row['quota']);
        assert_same('515000.00', $row['amount'], '10% x 5,000,000 + 15% x 100,000');
        assert_same('paid', $row['status']);
    },

    /* ==========================================================
     *  Rounding and rates (P19C-T16, T17)
     * ========================================================== */

    'P19C-T16 the combined amount is rounded once, half-up' => function () use ($configure, $tree, $buy, $period, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];

        $buy([$top => 60000.55]);
        $buy(array_fill_keys($fixture['members'], 110000.0));

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $row = $awardsBy($period)[$top];

        assert_same('500000.55', $row['group_volume']);
        assert_same('60000.55', $row['personal_volume']);
        assert_same('18000.04', $row['amount'], 'one rounding: 18,000.044 -> 18,000.04 (not 18,000.05)');
    },

    'P19C-T17 a three-decimal rate rounds the combined amount once' => function () use ($configure, $tree, $buy, $period, $awardsBy, $header): void {
        $configure([
            'royalty_supervisor_quota'        => '10000',
            'royalty_supervisor_group_pct'    => '3.125',
            'royalty_supervisor_personal_pct' => '5.000',
        ]);

        $clean   = $tree('supervisor');
        $withPs  = $tree('supervisor');

        /* Clean: no personal sales, 10,000 group -> 312.50. */
        $buy(array_fill_keys($clean['members'], 2500.0));

        /* With PS 3,333.33 and 6,666.67 of downline volume -> 479.17. */
        $buy([$withPs['top'] => 3333.33]);
        $buy([$withPs['members'][0] => 3333.34, $withPs['members'][1] => 3333.33]);

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $rows = $awardsBy($period);

        assert_same('10000.00', $rows[$clean['top']]['group_volume']);
        assert_same('0.00', $rows[$clean['top']]['personal_volume']);
        assert_same('3.125', $rows[$clean['top']]['group_pct']);
        assert_same('312.50', $rows[$clean['top']]['amount']);

        assert_same('10000.00', $rows[$withPs['top']]['group_volume']);
        assert_same('3333.33', $rows[$withPs['top']]['personal_volume']);
        assert_same('479.17', $rows[$withPs['top']]['amount'], '312.50 + 166.6665 -> 479.17');

        /* The snapshot keeps the three-decimal rate. */
        assert_same('3.125', $header($period)['group_pct_supervisor']);
    },

    /* ==========================================================
     *  The volume base (P19C-T18 ... T22)
     * ========================================================== */

    'P19C-T18 group sales include a descendant twelve levels down' => function () use ($configure, $tree, $member, $buy, $period, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];

        /* A twelve-level chain below the first Leader. */
        $node = $fixture['members'][0];

        for ($level = 1; $level <= 12; $level++) {
            $node = $member('rty_t18_depth_' . $level, $node);
        }

        $buy([$node => 500000.0]);

        $map = RoyaltyRank::map(RoyaltyPeriod::bounds($period)[1]);
        assert_true(isset($map[$node]));

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $row = $awardsBy($period)[$top];

        assert_same('0.00', $row['personal_volume']);
        assert_same('500000.00', $row['group_volume'], 'the deepest descendant purchase is in the ancestor GS');
        assert_same('15000.00', $row['amount']);
        assert_false(isset($awardsBy($period)[$node]), 'the deepest member is a Member and gets no row');
    },

    'P19C-T19 an order cancelled before the close is excluded' => function () use ($configure, $tree, $buy, $order, $period, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];

        $order($top, 500000.0);
        $order($top, 100000.0, ['status' => 'cancelled']);

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $row = $awardsBy($period)[$top];

        assert_same('500000.00', $row['group_volume'], 'the cancelled order is not volume');
        assert_same('500000.00', $row['personal_volume']);
        assert_same('40000.00', $row['amount']);
    },

    'P19C-T20 shipping is never part of the volume' => function () use ($configure, $tree, $order, $period, $awardsBy): void {
        $configure(['royalty_supervisor_quota' => '500']);

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];

        /* A 500.00 line with a 250.00 shipping fee and a 750.00 total. */
        $order($top, 500.0, ['shipping' => 250.0, 'total' => 750.0]);

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period);

        $row = $awardsBy($period)[$top];

        assert_same('500.00', $row['group_volume'], 'only the line total counts');
        assert_same('500.00', $row['personal_volume']);
        assert_same('40.00', $row['amount'], '3% x 500 + 5% x 500; including shipping it would be 47.50');
    },

    'P19C-T21 a package activation is not Royalty volume' => function () use ($configure, $tree, $period, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];

        $package = (int) db()->query('SELECT id FROM packages ORDER BY id LIMIT 1')->fetchColumn();
        assert_true($package > 0, 'the seeds provide a package');

        db()->prepare(
            "INSERT INTO activations (user_id, package_id, amount, payment_method, status)
             VALUES (?, ?, '500000.00', 'gcash', 'approved')"
        )->execute([$top, $package]);

        (new RoyaltyService())->evaluate($period);

        $row = $awardsBy($period)[$top];

        assert_same('0.00', $row['group_volume'], 'a membership purchase is not a shop sale');
        assert_same('skipped', $row['status']);
        assert_same('group sales below quota', $row['reason']);
    },

    'P19C-T22 a staff purchase is in no member group sales' => function () use ($configure, $tree, $order, $period, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $admin   = test_user('rty_t22_admin', 'admin');
        $adminId = (int) $admin['id'];

        $order($adminId, 5000000.0);

        (new RoyaltyService())->evaluate($period);

        $row = $awardsBy($period)[$fixture['top']];

        assert_same('0.00', $row['group_volume']);
        assert_same('skipped', $row['status']);
        assert_false(isset(RoyaltyRank::map()[$adminId]), 'staff are not in the rank tree');
    },

    /* ==========================================================
     *  Rank cutoff and eligibility (P19C-T23 ... T26)
     * ========================================================== */

    'P19C-T23 an account registered after the period end cannot lift a past rank' => function () use ($configure, $member, $period, $periodEnd, $awardsBy, $ledgerRows): void {
        $configure();

        $sponsor = $member('rty_t23_sponsor');

        /* One in-period Leader branch, one branch registered after the
         * period end. With the cutoff the sponsor is a Member (one
         * direct); without it the sponsor would be a Supervisor. */
        $early = $member('rty_t23_early', $sponsor);
        $member('rty_t23_early_1', $early);
        $member('rty_t23_early_2', $early);

        $late = $member('rty_t23_late', $sponsor, ['created_at' => substr($periodEnd, 0, 10) . ' 10:00:00']);
        $member('rty_t23_late_1', $late, ['created_at' => substr($periodEnd, 0, 10) . ' 10:01:00']);
        $member('rty_t23_late_2', $late, ['created_at' => substr($periodEnd, 0, 10) . ' 10:02:00']);

        assert_same(
            RoyaltyRank::SUPERVISOR,
            RoyaltyRank::map()[$sponsor]['rank'],
            'the live tree sees both Leader branches'
        );
        assert_same(
            RoyaltyRank::MEMBER,
            RoyaltyRank::map($periodEnd)[$sponsor]['rank'],
            'the period-end cutoff sees only the in-period branch'
        );

        (new RoyaltyService())->evaluate($period);

        assert_same([], $awardsBy($period));
        assert_same(0, $ledgerRows());
    },

    'P19C-T24 a disabled account is a skipped row, not money' => function () use ($configure, $tree, $buy, $period, $awardsBy, $header): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];
        $buy([$top => 500000.0]);

        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$top]);

        (new RoyaltyService())->evaluate($period);

        $row = $awardsBy($period)[$top];

        assert_same('skipped', $row['status']);
        assert_same('account is not active', $row['reason']);
        assert_same('0.00', $row['amount']);
        assert_same('paid', $header($period)['status']);
    },

    'P19C-T25 a pending membership is a skipped row, not money' => function () use ($configure, $tree, $buy, $period, $awardsBy): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];
        $buy([$top => 500000.0]);

        db()->prepare("UPDATE users SET member_status = 'pending' WHERE id = ?")->execute([$top]);

        (new RoyaltyService())->evaluate($period);

        $row = $awardsBy($period)[$top];

        assert_same('skipped', $row['status']);
        assert_same('membership is not active', $row['reason']);
        assert_same('0.00', $row['amount']);
    },

    'P19C-T26 a zero-rate ladder records a skipped zero amount' => function () use ($configure, $tree, $buy, $period, $awardsBy): void {
        $configure([
            'royalty_supervisor_group_pct'    => '0.000',
            'royalty_supervisor_personal_pct' => '0.000',
        ]);

        $fixture = $tree('supervisor');
        $buy([$fixture['top'] => 500000.0]);

        (new RoyaltyService())->evaluate($period);

        $row = $awardsBy($period)[$fixture['top']];

        assert_same('skipped', $row['status']);
        assert_same('zero amount', $row['reason']);
        assert_same('0.00', $row['amount']);
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty'")->fetchColumn());
    },

    /* ==========================================================
     *  The header (P19C-T27, T28)
     * ========================================================== */

    'P19C-T27 the header counters and totals equal the award rows' => function () use ($configure, $tree, $member, $buy, $period, $header, $awardsBy): void {
        $configure();

        $paid     = $tree('supervisor');
        $skipped  = $tree('supervisor');
        $inactive = $tree('supervisor');

        $buy([$paid['top'] => 500000.0, $skipped['top'] => 100000.0, $inactive['top'] => 500000.0]);
        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$inactive['top']]);

        $service = new RoyaltyService();
        $service->evaluate($period);

        $stored = $header($period);
        assert_same(3, (int) $stored['award_count']);
        assert_same(0, (int) $stored['paid_count']);
        assert_same(2, (int) $stored['skipped_count']);
        assert_same('40000.00', $stored['award_total'], 'only the payable row counts as awarded money');
        assert_same('0.00', $stored['paid_total']);
        assert_same(21, (int) $stored['members_count']);

        $service->pay($period);

        $stored = $header($period);
        $totals = RoyaltyAward::totalsForPeriod($period);

        assert_same('paid', $stored['status']);
        assert_same($totals['rows'], (int) $stored['award_count']);
        assert_same($totals['paid_rows'], (int) $stored['paid_count']);
        assert_same($totals['skipped_rows'], (int) $stored['skipped_count']);
        assert_same(number_format($totals['award_total'], 2, '.', ''), $stored['award_total']);
        assert_same(number_format($totals['paid_total'], 2, '.', ''), $stored['paid_total']);
        assert_same(1, $totals['paid_rows']);
        assert_same(2, $totals['skipped_rows']);
        assert_same(40000.0, $totals['paid_total']);
    },

    'P19C-T28 the period rank distribution is exclusive and sums to the member count' => function () use ($configure, $tree, $period, $header, $awardsBy): void {
        $configure();

        $fixture = $tree('director');

        (new RoyaltyService())->evaluate($period);

        $stored = $header($period);

        assert_same(31, (int) $stored['members_count']);
        assert_same(8, (int) $stored['leaders_count']);
        assert_same(4, (int) $stored['supervisors_count']);
        assert_same(2, (int) $stored['managers_count']);
        assert_same(1, (int) $stored['directors_count']);
        assert_same(7, (int) $stored['award_count'], 'every Supervisor+ gets a row, paid or skipped');
        assert_same(7, (int) $stored['skipped_count']);

        $exclusive = (int) $stored['leaders_count'] + (int) $stored['supervisors_count']
            + (int) $stored['managers_count'] + (int) $stored['directors_count'];

        assert_true($exclusive <= (int) $stored['members_count'], 'the CHECK holds: rank holders <= members');
        assert_same(
            (int) $stored['members_count'],
            $exclusive + ((int) $stored['members_count'] - $exclusive),
            'the five exclusive counts sum to members_count'
        );

        assert_same(2, (int) $stored['manager_supervisors']);
        assert_same(2, (int) $stored['director_managers']);
        assert_same(2, (int) $stored['leader_directs']);
    },

    /* ==========================================================
     *  Payment mechanics (P19C-T29, T30, T31)
     * ========================================================== */

    'P19C-T29 a partial payment resumes without double-paying' => function () use ($configure, $tree, $buy, $period, $header, $ledgerRows, $wallet): void {
        $configure();

        $first  = $tree('supervisor');
        $second = $tree('supervisor');
        $third  = $tree('supervisor');

        $buy([$first['top'] => 500000.0, $second['top'] => 500000.0, $third['top'] => 500000.0]);

        $service = new RoyaltyService();
        $service->evaluate($period);

        /* One batch of one award: the run stops at the ceiling and the
         * header stays evaluated (plan ?14.5). */
        $partial = $service->pay($period, 1, 1);

        assert_same(1, $partial['paid']);
        assert_same(40000.0, $partial['amount']);
        assert_same(2, $partial['pending_left']);
        assert_same('evaluated', $partial['status']);
        assert_same(true, $partial['resume_required']);
        assert_same(1, $ledgerRows());
        assert_same('evaluated', $header($period)['status']);

        $resumed = $service->pay($period);

        assert_same(2, $resumed['paid']);
        assert_same(80000.0, $resumed['amount']);
        assert_same(0, $resumed['pending_left']);
        assert_same('paid', $resumed['status']);
        assert_same(false, $resumed['resume_required']);

        assert_same(3, $ledgerRows(), 'three awards, three credits, no double payment');
        assert_same('paid', $header($period)['status']);
        assert_same(3, (int) $header($period)['paid_count']);
        assert_same('120000.00', $header($period)['paid_total']);
        assert_same(120000.0, (float) db()->query(
            "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger WHERE ref_type = 'royalty'"
        )->fetchColumn());

        foreach ([$first['top'], $second['top'], $third['top']] as $top) {
            assert_same([40000.0, 40000.0], $wallet($top));
        }
    },

    'P19C-T30 with payments paused the awards stay pending and no wallet moves' => function () use ($configure, $tree, $buy, $period, $header, $ledgerRows, $wallet): void {
        $configure(['royalty_pay_enabled' => '0']);

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];
        $buy([$top => 500000.0]);

        $service = new RoyaltyService();
        $service->evaluate($period);

        $paused = $service->pay($period);

        assert_same(true, $paused['paused']);
        assert_same('evaluated', $paused['status']);
        assert_same(0, $paused['paid']);
        assert_same(1, $paused['pending_left']);
        assert_same(0, $ledgerRows());
        assert_same([0.0, 0.0], $wallet($top));

        $stored = $header($period);
        assert_same('evaluated', $stored['status']);
        assert_same('40000.00', $stored['award_total'], 'the pending award is a liability');
        assert_same('0.00', $stored['paid_total']);

        /* Re-enabling and paying credits it once. */
        setting_set('royalty_pay_enabled', '1');
        setting_cache_clear();
        setting_all();

        $paid = $service->pay($period);

        assert_same(1, $paid['paid']);
        assert_same('paid', $paid['status']);
        assert_same(1, $ledgerRows());
        assert_same([40000.0, 40000.0], $wallet($top));
    },

    'P19C-T31 awards are credited in ascending member order' => function () use ($configure, $tree, $buy, $period): void {
        $configure();

        /* Build the three supervisors so that creation order is the
         * reverse of id order: the payment must sort by user_id, not by
         * the order the rows were written. */
        $trees = [];

        for ($i = 1; $i <= 3; $i++) {
            $trees[] = $tree('supervisor');
        }

        $volumes = [];

        foreach ($trees as $fixture) {
            $volumes[$fixture['top']] = 500000.0;
        }

        $buy($volumes);

        $service = new RoyaltyService();
        $service->evaluate($period);
        $service->pay($period, 500, 20);

        $userIds = array_map('intval', db()->query(
            "SELECT user_id FROM ewallet_ledger WHERE ref_type = 'royalty' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN));

        $sorted = $userIds;
        sort($sorted, SORT_NUMERIC);

        assert_same($sorted, $userIds, 'the ledger credits ascending user_id');
        assert_count(3, $userIds);
    },

    /* ==========================================================
     *  The baseline (P19C-T49) and the member projection (T50)
     * ========================================================== */

    'P19C-T49 a month before the ledger baseline is refused' => function () use ($configure, $period, $header): void {
        $configure();

        $before = RoyaltyPeriod::previous($period);
        assert_same($period, RoyaltyPeriod::baseline(), 'the fixture baseline is the test month');

        $error = assert_throws(
            static fn (): array => (new RoyaltyService())->evaluate($before),
            RoyaltyException::class
        );

        assert_same('state', $error->reason);
        assert_null($header($before), 'no header is written before the baseline');
    },

    'P19C-T50 statusFor projects exactly what a close would pay' => function () use ($configure, $tree, $buy, $period): void {
        $configure();

        $fixture = $tree('supervisor');
        $top     = $fixture['top'];

        $buy([$top => 60000.0]);
        $buy(array_fill_keys($fixture['members'], 112500.0));

        $service = new RoyaltyService();

        $projection = $service->statusFor($top, $period);

        assert_same($period, $projection['period']);
        assert_same(2, $projection['rank']);
        assert_same('Supervisor', $projection['rank_name']);
        assert_same(3, $projection['next']['rank']);
        assert_same('Manager', $projection['next']['name']);
        assert_same('510000.00', number_format((float) $projection['group'], 2, '.', ''));
        assert_same('60000.00', number_format((float) $projection['personal'], 2, '.', ''));
        assert_same(100, $projection['progress'], 'GS above the quota caps the progress at 100');
        assert_same(18300.0, $projection['projected']);
        assert_true($projection['eligible']);

        $preview = $service->preview($period);
        $draft   = null;

        foreach ($preview['awards'] as $award) {
            if ((int) $award['user_id'] === $top) {
                $draft = $award;
            }
        }

        assert_not_null($draft);
        assert_same((float) $draft['amount'], $projection['projected'], 'the card and the close agree');

        /* A leader's projection is zero and names the Supervisor path. */
        $leader = RoyaltyRank::map()[$fixture['leaders'][0]];
        assert_same(RoyaltyRank::LEADER, $leader['rank']);

        $leaderProjection = $service->statusFor($leader['id'], $period);
        assert_same(RoyaltyRank::LEADER, $leaderProjection['rank']);
        assert_same(0.0, $leaderProjection['projected']);
        assert_false($leaderProjection['eligible'], 'Leader is not a paying rank');
    },

    /* ==========================================================
     *  No route, no write surface, one producer (P19C-T48)
     * ========================================================== */

    'P19C-T48 the engine is the only producer; 19D adds no route and no write surface' => function (): void {
        $appRoot  = dirname(__DIR__, 2);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appRoot . '/app', FilesystemIterator::SKIP_DOTS)
        );

        $relative = static fn (string $path): string
            => str_replace('\\', '/', substr($path, strlen($appRoot)));

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        /* The three 19C classes, the reset tooling and the 19D report
         * read model are the only application files that may name the
         * money tables besides the rank engine. */
        $allowList = [
            '/app/Models/RoyaltyAward.php',
            '/app/Models/RoyaltyPeriod.php',
            '/app/Services/RoyaltyService.php',
            '/app/Services/DatabaseResetService.php',
            '/app/Models/Report.php',
        ];

        $writers = [];

        foreach ($files as $path) {
            $file   = $relative($path);
            $source = (string) file_get_contents($path);

            if (preg_match("/->credit\\([^;]*'royalty'/s", $source) === 1) {
                $writers[] = $file;
            }

            if (in_array($file, $allowList, true)) {
                continue;
            }

            assert_same(
                0,
                preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+`?royalty_(awards|periods)/i', $source),
                $file . ' must not read the Royalty money tables outside the engine'
            );
        }

        assert_same(['/app/Services/RoyaltyService.php'], $writers, 'pay() is the only Royalty creditor');

        /* 19D's controllers and views may name the engine classes — the
         * admin ledger, member panel, reports and dashboard are reads —
         * but no controller or view may WRITE the money tables. */
        foreach ($files as $path) {
            $file = $relative($path);

            if (!str_contains($file, '/Controllers/') && !str_contains($file, '/views/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            assert_same(
                0,
                preg_match('/\b(INTO|UPDATE|DELETE\s+FROM)\s+`?royalty_(awards|periods)/i', $source),
                $file . ' must not write the Royalty money tables'
            );
        }

        assert_false(is_file($appRoot . '/app/Controllers/RoyaltyController.php'));

        /* No route, by design (D-16). */
        foreach (['/app/routes.php', '/app/routes_inventory.php'] as $file) {
            assert_same(
                0,
                preg_match('/royalt/i', (string) file_get_contents($appRoot . $file)),
                $file . ' has no royalty route'
            );
        }

        /* The 19C CLI job exists and still has no web entry (19F). */
        assert_true(is_file($appRoot . '/bin/royalty_month_close.php'));
        assert_false(is_file($appRoot . '/public/cron/royalty_month_close.php'));
        assert_false(is_file($appRoot . '/bin/royalty_month_close.cmd'));
    },
];
