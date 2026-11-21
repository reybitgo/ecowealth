<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryReportTest — Phase 22E (plan §9, §16)
 *
 *  Proves the reports desk and the dashboard read the Product binary
 *  money:
 *
 *      cards      binarySummary / binaryByDay / binaryByKind /
 *                 binaryByProduct / binaryTopNodes match the fixture
 *      page       the reports screen renders the five binary blocks
 *                 and offers `type=binary` export
 *      CSV        `type=binary` downloads the binary rows plus a
 *                 readiness block, header naming the stream
 *      dashboard  the Product binary (30 d) KPI reads the last 30 days
 *      readiness  counts match SQL; nothing here is a button
 *      feed       an award bumps `admin.commissions`
 *
 *  Fixture rows are inserted directly: this file tests the reporting
 *  reads, not the engine (BinaryServiceTest owns the money).
 *
 *  Run this group alone with:  php tests/run.php BinaryReport
 * ============================================================== */

$token   = bin2hex(random_bytes(4));
$from    = '2026-01-01';
$to      = '2026-01-31';
$day1    = $from . ' 00:00:00';
$day2    = date('Y-m-d H:i:s', strtotime($from . ' +9 days'));
$day3    = date('Y-m-d H:i:s', strtotime($from . ' +11 days'));
$day4    = date('Y-m-d H:i:s', strtotime($from . ' +14 days'));

$member = static function (string $username): array {
    return test_user($username);
};

$adminId = static function () use ($member): int {
    $stmt = db()->prepare("SELECT id FROM users WHERE username = 'admin' AND role = 'admin' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row !== false) {
        return (int) $row['id'];
    }
    $admin = $member('r22e_admin');

    db()->prepare(
        "INSERT IGNORE INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by,
            personal_points, left_points, right_points, pairs_paid)
         VALUES (?, NULL, NULL, ?, 0, '', 'placed', NOW(), ?, 0, 0, 0, 0)"
    )->execute([
        (int) $admin['id'], (int) $admin['id'], (int) $admin['id'],
    ]);

    return (int) $admin['id'];
};

$product = static function (string $name, string $sku, float $pv, int $binaryEnabled = 1) use ($token): array {
    $slug = slugify($name . '_' . $token);

    db()->prepare(
        'INSERT INTO products
           (name, sku, slug, price, pv, binary_enabled, unilevel_enabled, unilevel_base, stock, status, sort_order)
         VALUES (?, ?, ?, 0, ?, ?, 0, \'auto\', 100, \'active\', 100)'
    )->execute([
        $name,
        $sku . '_' . bin2hex(random_bytes(4)),
        $slug,
        number_format($pv, 2, '.', ''),
        $binaryEnabled,
    ]);

    $stmt = db()->prepare('SELECT * FROM products WHERE sku LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sku . '%']);

    return (array) $stmt->fetch();
};

$order = static function (int $userId): array {
    $now = date('Y-m-d H:i:s');

    db()->prepare(
        "INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
            billing_name, shipping_name, shipping_address, shipping_city, shipping_province,
            shipping_postal, shipping_country, terms_version, terms_accepted_at,
            payment_deadline, binary_position, binary_processed_at, paid_at)
         VALUES (?, ?, 'paid', 'ewallet', 0, 0, 0, 'Test', 'Test', 'Test', 'Test', 'Test',
                 '1234', 'PH', 'v1', NOW(), NULL, 'left', NULL, ?)"
    )->execute([
        'T' . bin2hex(random_bytes(9)),
        $userId,
        $now,
    ]);

    $stmt = db()->prepare('SELECT * FROM shop_orders WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);

    return (array) $stmt->fetch();
};

$orderItem = static function (int $orderId, int $productId): int {
    db()->prepare(
        'INSERT INTO shop_order_items
           (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total,
            unit_base, base_total, unit_points, points_total, reservation_state)
         VALUES (?, ?, \'Test Item\', NULL, 1, 0, 0, 0, 0, 250, 250, \'reserved\')'
    )->execute([$orderId, $productId]);

    $stmt = db()->prepare('SELECT id FROM shop_order_items WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);

    return (int) $stmt->fetchColumn();
};

$award = static function (
    int $orderId, int $buyerId, int $userId, string $kind, string $status,
    float $amount, string $createdAt, float $reversed = 0.0, string $reason = ''
): array {
    $points   = $kind === 'self' ? 250 : 250;
    $pcount   = $kind === 'pair' ? 1 : 0;
    $ppoints  = $kind === 'pair' ? 125 : 0;

    db()->prepare(
        "INSERT INTO binary_awards
           (kind, order_id, buyer_id, user_id, side, points, pair_count, pair_points, rate_pct, amount,
            status, hold_days, paid_at, created_at, ledger_id, credited_at, voided_at,
            reversal_ledger_id, reversed_amount, void_reason)
         VALUES (?, ?, ?, ?, 'left', ?, ?, ?, 0, ?, ?, 7, ?, ?, NULL, NULL, NULL,
                 NULL, ?, ?)"
    )->execute([
        $kind, $orderId, $buyerId, $userId, $points, $pcount, $ppoints, $amount, $status,
        $createdAt, $createdAt, $reversed, $reason,
    ]);

    $stmt = db()->prepare('SELECT * FROM binary_awards WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);

    return (array) $stmt->fetch();
};

$volume = static function (
    int $orderId, int $orderItemId, int $buyerId, string $side, int $points, string $kind, string $createdAt
) use ($adminId): void {
    db()->prepare(
        "INSERT INTO binary_volume
           (node_user_id, side, kind, points, order_id, order_item_id, buyer_id, note, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'fixture', ?, ?)"
    )->execute([
        $buyerId, $side, $kind, $points, $orderId, $orderItemId, $buyerId, $adminId(), $createdAt,
    ]);
};

$node = static function (int $userId, ?int $uplineId, ?string $side, int $depth, string $path,
                          int $personal = 0, string $state = 'placed') use ($adminId): void {
    db()->prepare(
        "INSERT IGNORE INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by,
            personal_points, left_points, right_points, pairs_paid)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 0, 0, 0)"
    )->execute([
        $userId, $uplineId, $side, $depth === 0 ? $userId : null, $depth, $path,
        $state, $adminId(), $personal,
    ]);
};

return [

    /* ----------------------------------------------------------
     *  P22E-T3 — the stats tiles match the fixture exactly
     * ---------------------------------------------------------- */

    'P22E-T3 binary summary tiles match the fixture' => function () use (
        $from, $to, $day1, $day2, $day3, $day4, $adminId, $member, $product, $order,
        $orderItem, $award, $volume, $node
    ): void {
        $buyerA = $member('r22e_t3_buyer_a');
        $buyerB = $member('r22e_t3_buyer_b');

        $adminId();

        $p1 = $product('Coffee', 'COFFEE', 250.00, 1);
        $p2 = $product('Zero', 'ZERO', 0.00, 0);

        $oA = $order((int) $buyerA['id']);
        $oB = $order((int) $buyerB['id']);
        $oC = $order((int) $buyerA['id']);
        $oD = $order((int) $buyerB['id']);

        $iA = $orderItem((int) $oA['id'], (int) $p1['id']);
        $iB = $orderItem((int) $oB['id'], (int) $p2['id']);
        $iC = $orderItem((int) $oC['id'], (int) $p1['id']);
        $iD = $orderItem((int) $oD['id'], (int) $p2['id']);

        /* Self credited ₱50, pair credited ₱25, pending ₱30, cancelled ₱10,
         * voided ₱20 with ₱5 recovered → ₱15 unrecovered. One self award
         * per (order, buyer) — cancelled and voided live on their own
         * orders so they do not collide on the unique key. */
        $award((int) $oA['id'], (int) $buyerA['id'], (int) $buyerA['id'], 'self', 'credited', 50.00, $day1);
        $award((int) $oA['id'], (int) $buyerA['id'], (int) $buyerB['id'], 'pair', 'credited', 25.00, $day1);
        $award((int) $oB['id'], (int) $buyerB['id'], (int) $buyerB['id'], 'self', 'pending', 30.00, $day2);
        $award((int) $oC['id'], (int) $buyerA['id'], (int) $buyerA['id'], 'self', 'cancelled', 10.00, $day3);
        $award((int) $oD['id'], (int) $buyerB['id'], (int) $buyerB['id'], 'self', 'voided', 20.00, $day4, 5.00);

        /* Points placed: order A left 250 / right 250 on P1, order B left 100 /
         * right 100 on P2, order C left 50 / right 50 on P1, order D left 75 /
         * right 75 on P2. */
        $volume((int) $oA['id'], $iA, (int) $buyerA['id'], 'left',  250, 'own', $day1);
        $volume((int) $oA['id'], $iA, (int) $buyerA['id'], 'right', 250, 'own', $day1);
        $volume((int) $oB['id'], $iB, (int) $buyerB['id'], 'left',  100, 'own', $day2);
        $volume((int) $oB['id'], $iB, (int) $buyerB['id'], 'right', 100, 'own', $day2);
        $volume((int) $oC['id'], $iC, (int) $buyerA['id'], 'left',   50, 'own', $day3);
        $volume((int) $oC['id'], $iC, (int) $buyerA['id'], 'right',  50, 'own', $day3);
        $volume((int) $oD['id'], $iD, (int) $buyerB['id'], 'left',   75, 'own', $day4);
        $volume((int) $oD['id'], $iD, (int) $buyerB['id'], 'right',  75, 'own', $day4);

        /* A fresh root keeps the fixture out of the demo tree's slots. */
        $root = $member('r22e_t3_root');
        $node((int) $root['id'], null, null, 0, '');
        $node((int) $buyerA['id'], (int) $root['id'], 'left',  1, 'L', 500);
        $node((int) $buyerB['id'], (int) $root['id'], 'right', 1, 'R', 100);

        $summary = Report::binarySummary($from, $to);

        assert_same(75.0,   (float) $summary['amount'],        'credited money');
        assert_same(2,      (int)   $summary['count'],         'credited awards');
        assert_same(30.0,   (float) $summary['pending_amount'], 'pending liability');
        assert_same(1,      (int)   $summary['pending_count']);
        assert_same(1,      (int)   $summary['cancelled']);
        assert_same(10.0,   (float) $summary['cancelled_total']);
        assert_same(1,      (int)   $summary['voided']);
        assert_same(20.0,   (float) $summary['voided_total']);
        assert_same(15.0,   (float) $summary['unrecovered'],   'shortfall after reversal');
        assert_same(2,      (int)   $summary['recipients']);
        assert_same(80.0,   (float) $summary['self_total']);
        assert_same(25.0,   (float) $summary['pair_total']);
        assert_same(475,    (int)   $summary['points_left']);
        assert_same(475,    (int)   $summary['points_right']);
        assert_same(75.0,   (float) $summary['all_time']);

        /* By kind. */
        $byKind = Report::binaryByKind($from, $to);
        $kinds  = array_column($byKind, null, 'kind');
        assert_same(110.0,  (float) $kinds['self']['amount'],  'self total');
        assert_same(25.0,   (float) $kinds['pair']['amount'],  'pair total');
        assert_same(1,      (int)   $kinds['self']['credited']);
        assert_same(1,      (int)   $kinds['pair']['credited']);
        assert_same(1,      (int)   $kinds['self']['pending']);
        assert_same(0,      (int)   $kinds['pair']['pending']);

        /* By day: day 1 pays 75, day 2 pays nothing but places 100 pts per side. */
        $byDay = Report::binaryByDay($from, $to);
        $days  = array_column($byDay, null, 'day');
        assert_same(2,   (int)   $days[$from]['awards']);
        assert_same(75.0,(float) $days[$from]['amount']);
        assert_same(250, (int)   $days[$from]['points_left']);
        assert_same(250, (int)   $days[$from]['points_right']);
        assert_same(1,   (int)   $days[date('Y-m-d', strtotime($day2))]['awards']);
        assert_same(0.0, (float) $days[date('Y-m-d', strtotime($day2))]['amount']);
        assert_same(30.0,(float) $days[date('Y-m-d', strtotime($day2))]['pending_amount']);
        assert_same(100, (int)   $days[date('Y-m-d', strtotime($day2))]['points_left']);
        assert_same(100, (int)   $days[date('Y-m-d', strtotime($day2))]['points_right']);
        assert_count(31, $byDay, 'every day in the January window renders, zeros included');

        /* By product: P1 places 600 pts and carries 4 awards, P2 places 350 and carries 2. */
        $byProd = Report::binaryByProduct($from, $to, 8);
        $prods  = array_column($byProd, null, 'product_id');
        assert_same(600,  (int) $prods[(int) $p1['id']]['points_placed'], 'P1 points placed');
        assert_same(3,    (int) $prods[(int) $p1['id']]['awards'],       'P1 awards');
        assert_same(350,  (int) $prods[(int) $p2['id']]['points_placed'], 'P2 points placed');
        assert_same(2,    (int) $prods[(int) $p2['id']]['awards'],       'P2 awards');

        /* Top nodes: buyerA 500 own, buyerB 100 own. */
        $tops = Report::binaryTopNodes(8);
        assert_same((int) $buyerA['id'], $tops[0]['user_id']);
        assert_same(500, (int) $tops[0]['personal_points']);
        assert_same((int) $buyerB['id'], $tops[1]['user_id']);
        assert_same(100, (int) $tops[1]['personal_points']);
    },

    /* ----------------------------------------------------------
     *  P22E-T14 — the reports page and the dashboard KPI
     * ---------------------------------------------------------- */

    'P22E-T14 the reports page renders the five binary blocks' => function () use (
        $from, $to, $day1, $adminId, $member, $product, $order, $orderItem, $award, $volume
    ): void {
        $buyerA = $member('r22e_t14_buyer_a');

        $adminId();

        $p1 = $product('Coffee', 'COFFEE14', 250.00, 1);

        $oA = $order((int) $buyerA['id']);
        $iA = $orderItem((int) $oA['id'], (int) $p1['id']);
        $award((int) $oA['id'], (int) $buyerA['id'], (int) $buyerA['id'], 'self', 'credited', 60.00, $day1);
        $volume((int) $oA['id'], $iA, (int) $buyerA['id'], 'left',  250, 'own', $day1);
        $volume((int) $oA['id'], $iA, (int) $buyerA['id'], 'right', 250, 'own', $day1);

        $range = ReportService::range($from, $to);
        $data  = (new ReportService())->page($range) + [
            'page_title'   => 'Reports',
            'current_nav'  => 'admin.reports',
            'page_css'     => ['assets/css/reports.css'],
            'page_js'      => ['assets/js/admin-reports.js'],
            'live'         => Live::meta('admin.reports', 'test'),
            'export_types' => ReportService::EXPORTS,
            'export_url'   => url('admin/reports/export'),
            'canonical'    => url('admin/reports'),
        ];

        ob_start();
        try {
            View::page('admin', 'admin/reports/index', $data);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('Product binary bonus', $html, 'the summary card renders');
        assert_contains(money(60.0), $html, 'the credited figure renders');
        assert_contains('Self bonus vs pair bonus', $html, 'the kind breakdown renders');
        assert_contains('Points placed by product', $html, 'the product table renders');
        assert_contains('Product binary readiness', $html, 'the readiness card renders');

        assert_true(isset(ReportService::EXPORTS['binary']), 'binary export is offered');
        assert_same('Product binary bonus', ReportService::EXPORTS['binary']);
        assert_contains('type=binary', $html, 'the binary export is offered on the page');
    },

    'P22E-T14 the dashboard binary tile reads the last 30 days' => function () use (
        $from, $to, $day1, $adminId, $member, $product, $order, $orderItem, $award, $volume
    ): void {
        $buyerA = $member('r22e_t14d_buyer_a');

        $adminId();

        $p1 = $product('Coffee', 'COFFEE14D', 250.00, 1);
        $oA = $order((int) $buyerA['id']);
        $iA = $orderItem((int) $oA['id'], (int) $p1['id']);
        $now = date('Y-m-d H:i:s');
        $award((int) $oA['id'], (int) $buyerA['id'], (int) $buyerA['id'], 'self', 'credited', 40.00, $now);
        $volume((int) $oA['id'], $iA, (int) $buyerA['id'], 'left',  200, 'own', $now);
        $volume((int) $oA['id'], $iA, (int) $buyerA['id'], 'right', 200, 'own', $now);

        /* An award older than the window must not appear in the 30 d tile. */
        $old = date('Y-m-d H:i:s', strtotime('-40 days'));
        $oOld = $order((int) $buyerA['id']);
        $award((int) $oOld['id'], (int) $buyerA['id'], (int) $buyerA['id'], 'self', 'credited', 999.00, $old);

        $dashboard = (new ReportService())->dashboard();
        $binary    = $dashboard['binary'];

        assert_true(isset($binary), 'the dashboard carries the binary KPI');
        assert_same(40.0,  (float) $binary['credited'],   'last-30-days credited');
        assert_same(1,     (int)   $binary['count'],       'last-30-days count');
        assert_same(400,   (int)   $binary['points_left'] + $binary['points_right'], 'last-30-days points');
        assert_same(40.0,  (float) $binary['this_month'],  'the recent award is this month');

        $tile = View::capture('admin/dashboard/_commerce', ['commerce' => $dashboard]);
        assert_contains('Product binary (30 d)', $tile);
        assert_contains(money(40.0), $tile);
        assert_contains('admin/commissions?ledger=binary', $tile);
    },

    /* ----------------------------------------------------------
     *  P22E-T15 — the binary export
     * ---------------------------------------------------------- */

    'P22E-T15 export type=binary names the stream and carries the rows' => function () use (
        $from, $to, $day1, $adminId, $member, $product, $order, $orderItem, $award, $volume
    ): void {
        $buyerA = $member('r22e_t15_buyer_a');

        $adminId();

        $p1 = $product('Coffee', 'COFFEE15', 250.00, 1);
        $oA = $order((int) $buyerA['id']);
        $iA = $orderItem((int) $oA['id'], (int) $p1['id']);
        $award((int) $oA['id'], (int) $buyerA['id'], (int) $buyerA['id'], 'self', 'credited', 50.00, $day1);
        $volume((int) $oA['id'], $iA, (int) $buyerA['id'], 'left', 250, 'own', $day1);

        $range = ReportService::range($from, $to);
        $export = (new ReportService())->export('binary', $range);

        assert_same('ecowealth_binary_' . $from . '_' . $to . '.csv', $export['filename']);

        $header = $export['header'];
        assert_same('Scope',   $header[0], 'the header names the stream');
        assert_same('Award',   $header[1], 'the first binary column names the stream');
        assert_true(in_array('Amount', $header, true), 'the amount column is exported');

        $awardRows = array_filter($export['rows'], static fn (array $row): bool => $row[0] === 'Award');
        assert_count(1, $awardRows, 'the binary award row is exported');
        $awardRow  = array_values($awardRows)[0];
        $amountIdx = array_search('Amount', $header, true);
        assert_same('50.00', $awardRow[$amountIdx], 'the amount rides along');

        $readyRows = array_filter($export['rows'], static fn (array $row): bool => $row[0] === 'Readiness');
        assert_count(1, $readyRows, 'the readiness block is exported');

        /* Width stays consistent: every row matches the header width. */
        foreach ($export['rows'] as $row) {
            assert_count(count($header), $row, 'every export row matches the header width');
        }
    },

    /* ----------------------------------------------------------
     *  P22E-T16 — live feed: an award bumps admin.commissions
     * ---------------------------------------------------------- */

    'P22E-T16 an award bumps admin.commissions' => function () use (
        $from, $day1, $adminId, $member, $product, $order, $orderItem, $node
    ): void {
        $buyerA = $member('r22e_t16_buyer_a');

        $adminId();

        /* A minimal placed tree: admin root → boss (right) → buyerA (left). */
        $node($adminId(), null, null, 0, '');
        $boss = $member('r22e_t16_boss');
        $node((int) $boss['id'], $adminId(), 'right', 1, 'R');
        $node((int) $buyerA['id'], (int) $boss['id'], 'left', 2, 'RL');

        db()->prepare("INSERT INTO settings (key_name, value) VALUES ('binary_enabled', '1')
            ON DUPLICATE KEY UPDATE value = '1'")->execute();
        db()->prepare("INSERT INTO settings (key_name, value) VALUES ('binary_requires_active', '0')
            ON DUPLICATE KEY UPDATE value = '0'")->execute();
        db()->prepare("INSERT INTO settings (key_name, value) VALUES ('binary_self_pct', '5.000')
            ON DUPLICATE KEY UPDATE value = '5.000'")->execute();

        $p1 = $product('Coffee', 'COFFEE16', 250.00, 1);
        $oA = $order((int) $buyerA['id']);
        $orderItem((int) $oA['id'], (int) $p1['id']);

        $before = Live::revision('admin.commissions');

        (new BinaryService())->onOrderPaid((int) $oA['id']);

        $after = Live::revision('admin.commissions');
        assert_true($after > $before, 'the award bumps the commission surface');

        assert_count(1, BinaryAward::forOrder((int) $oA['id']), 'an award row was written');
    },

    /* ----------------------------------------------------------
     *  P22E-T19 — the readiness card counts match SQL
     * ---------------------------------------------------------- */

    'P22E-T19 readiness counts match SQL' => function () use ($from, $day1, $adminId, $member, $product, $node): void {
        $buyerA = $member('r22e_t19_buyer_a');
        $buyerB = $member('r22e_t19_buyer_b');
        $nodeLess = $member('r22e_t19_node_less');

        $adminId();

        $product('Coffee', 'COFFEE19', 250.00, 1);   // flagged, has P.V.
        $product('Zero', 'ZERO19', 0.00, 1);         // flagged, no points
        $product('Off',  'OFF19',  300.00, 0);       // switched off

        /* A fresh root keeps the fixture out of the demo tree's slots. */
        $root = $member('r22e_t19_root');
        $node((int) $root['id'], null, null, 0, '');
        $node((int) $buyerA['id'], (int) $root['id'], 'left', 1, 'L');
        $node((int) $buyerB['id'], (int) $root['id'], 'right', 1, 'R');
        /* $nodeLess has no node: unplaced. */

        db()->prepare("INSERT INTO settings (key_name, value) VALUES ('binary_enabled', '1')
            ON DUPLICATE KEY UPDATE value = '1'")->execute();

        $ready = Report::binaryReadiness();

        $expectedOnNoPoints = (int) db()->query(
            "SELECT COUNT(*) FROM products WHERE binary_enabled = 1 AND pv = 0"
        )->fetchColumn();
        $expectedOff = (int) db()->query("SELECT COUNT(*) FROM products WHERE binary_enabled = 0")->fetchColumn();
        $expectedUnplaced = (int) db()->query(
            "SELECT COUNT(*) FROM users u
             WHERE u.role = 'member'
               AND NOT EXISTS (SELECT 1 FROM binary_nodes n WHERE n.user_id = u.id)"
        )->fetchColumn();
        $expectedTotal = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();

        assert_same(true,   $ready['stream_on']);
        assert_same($expectedOnNoPoints, $ready['products_on_no_points']);
        assert_same($expectedOff,        $ready['products_off']);
        assert_same($expectedUnplaced,   $ready['members_unplaced']);
        assert_same($expectedTotal,      $ready['members_total']);
        assert_same(1, $ready['products_on_no_points'], 'Zero has binary on with no P.V.');
        assert_true($ready['members_unplaced'] > 0,     'the node-less member counts');
    },

];
