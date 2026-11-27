<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyMemberViewTest — Phase 19E (plan §9)
 *
 *  Proves the member-facing Royalty surface and only reads:
 *
 *      tab        the third pill on /earnings, the rank card, the five
 *                 counters, the monthly rows and the pagination
 *      copy       "team"/"Team chain", the month close, Leader is not a
 *                 paying rank, explicit statuses (Appendix C.3/C.7/C.8)
 *      scoping    every query is `WHERE user_id = ?`; `?user_id=` and a
 *                 hand-edited export URL cannot read another member
 *      stream     off → history stays and the promise surfaces change
 *      pause      `royalty_pay_enabled = 0` → "payment is paused", never
 *                 "paid"
 *      CSV        the Appendix C.6 columns and the member's own rows
 *      set        the package and repeat tabs keep their bytes
 *      read-only  rendering every 19E surface writes no period, award,
 *                 wallet or audit row (P19E-T16)
 *
 *  Fixtures are built directly against the Phase 19C tables: this file
 *  tests the visibility, not the engine (RoyaltyEngineTest owns the
 *  money). The one engine call is deliberate — P19E-T4 proves the member
 *  projection equals what the close actually pays (plan §14.11).
 *
 *  Run this group alone with:  php tests/run.php RoyaltyMemberView
 * ============================================================== */

/* The open month (the rank card's frame) and the two closed months
 * behind it. No wall-clock dates: every period is derived. */
$open  = RoyaltyPeriod::periodOf();
$prev  = RoyaltyPeriod::previous($open);
$prior = RoyaltyPeriod::previous($prev);

/** The reference ladder (10/10/10/10, 500k/1M/5M, 3/5, 5/10, 10/15). */
$configure = static function (array $overrides = []): void {
    $settings = $overrides + [
        'royalty_enabled'                 => '1',
        'royalty_pay_enabled'             => '1',
        'royalty_leader_directs'          => '10',
        'royalty_supervisor_leaders'      => '10',
        'royalty_manager_supervisors'     => '10',
        'royalty_director_managers'       => '10',
        'royalty_supervisor_quota'        => '500000',
        'royalty_manager_quota'           => '1000000',
        'royalty_director_quota'          => '5000000',
        'royalty_supervisor_group_pct'    => '3.000',
        'royalty_supervisor_personal_pct' => '5.000',
        'royalty_manager_group_pct'       => '5.000',
        'royalty_manager_personal_pct'    => '10.000',
        'royalty_director_group_pct'      => '10.000',
        'royalty_director_personal_pct'   => '15.000',
        'royalty_ledger_since'            => '2020-01-01 00:00:00',
    ];

    foreach ($settings as $key => $value) {
        setting_set($key, (string) $value);
    }

    setting_cache_clear();
    setting_all();
};

/** One member account the rank pass can see (registered long ago). */
$member = static function (string $username, ?int $sponsor = null, array $overrides = []): int {
    $row = $overrides + [
        'username'       => $username,
        'email'          => $username . '@p19e.test',
        'password_hash'  => '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
        'full_name'      => ucfirst($username) . ' Member',
        'role'           => 'member',
        'member_status'  => 'active',
        'sponsor_id'     => $sponsor,
        'is_active'      => 1,
        'created_at'     => '2020-01-02 09:00:00',
    ];

    $columns = array_keys($row);
    $marks   = implode(', ', array_fill(0, count($columns), '?'));

    db()->prepare('INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . $marks . ')')
        ->execute(array_values($row));

    return (int) db()->lastInsertId();
};

/** One product for the fixture orders. */
$product = static function (): int {
    $existing = db()->query("SELECT id FROM products WHERE sku = 'RTY-MEMBER' LIMIT 1")->fetchColumn();

    if ($existing !== false) {
        return (int) $existing;
    }

    db()->prepare(
        "INSERT INTO products (sku, name, slug, price, pv, stock)
         VALUES ('RTY-MEMBER', 'Royalty member product', 'royalty-member-product', 1.00, 1.00, 100000)"
    )->execute();

    return (int) db()->lastInsertId();
};

/**
 * One paid shop order with a real line total. `paid_at` defaults to now,
 * so the order is inside the **open** month the rank card reads.
 *
 * @param array{status?:string, paid_at?:?string} $options
 */
$order = static function (int $userId, float $amount, array $options = []) use ($product): int {
    $pdo    = db();
    $status = (string) ($options['status'] ?? 'paid');
    $paidAt = array_key_exists('paid_at', $options) ? $options['paid_at'] : date('Y-m-d H:i:s');

    $pdo->prepare(
        "INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
            billing_name, shipping_name, shipping_address, paid_at, terms_accepted_at)
         VALUES (?, ?, ?, 'ewallet', ?, 0.00, ?, 'Fixture buyer', 'Fixture buyer', 'Fixture address', ?, ?)"
    )->execute([
        'RTY-M-' . strtoupper(bin2hex(random_bytes(5))),
        $userId,
        $status,
        number_format($amount, 2, '.', ''),
        number_format($amount, 2, '.', ''),
        $paidAt,
        date('Y-m-d H:i:s'),
    ]);

    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, quantity, unit_price, line_total, reservation_state)
         VALUES (?, ?, 'Royalty member product', 1, ?, ?, 'committed')"
    )->execute([$orderId, $product(), number_format($amount, 2, '.', ''), number_format($amount, 2, '.', '')]);

    return $orderId;
};

/** One paid order per member. @param array<int, float> $volumes */
$buy = static function (array $volumes, array $options = []) use ($order): void {
    foreach ($volumes as $userId => $amount) {
        $order((int) $userId, (float) $amount, $options);
    }
};

/**
 * The §16.2 B reference tree: one Supervisor at the default 10/10 gate.
 *
 *     sup -> 10 Leaders -> 10 directs each          (111 members)
 *
 * @return array{sup:int, leaders:list<int>, members:list<int>}
 */
$referenceTree = static function () use ($member): array {
    $sup     = $member('rty_m_ref_sup');
    $leaders = [];
    $members = [];

    for ($l = 1; $l <= 10; $l++) {
        $leader    = $member('rty_m_ref_l' . $l, $sup);
        $leaders[] = $leader;

        for ($d = 1; $d <= 10; $d++) {
            $members[] = $member('rty_m_ref_l' . $l . '_d' . $d, $leader);
        }
    }

    return ['sup' => $sup, 'leaders' => $leaders, 'members' => $members];
};

/**
 * Insert one closed month and its award rows.
 *
 * The header's counters are derived from the drafts, so the period and
 * the rows always agree; a `paid` row is created pending and then marked
 * paid — the only update the ledger ever applies — so `credited_at` is a
 * real timestamp.
 *
 * @param list<array{
 *   user:int, rank:int, gs:float, ps:float, amount:float,
 *   status?:string, reason?:string, quota?:float
 * }> $drafts
 */
$seed = static function (string $period, array $drafts, string $status = 'evaluated', ?string $note = null): void {
    $quotas = [
        RoyaltyRank::SUPERVISOR => 500000.0,
        RoyaltyRank::MANAGER    => 1000000.0,
        RoyaltyRank::DIRECTOR   => 5000000.0,
    ];
    $groups = [
        RoyaltyRank::SUPERVISOR => 3.0,
        RoyaltyRank::MANAGER    => 5.0,
        RoyaltyRank::DIRECTOR   => 10.0,
    ];
    $personals = [
        RoyaltyRank::SUPERVISOR => 5.0,
        RoyaltyRank::MANAGER    => 10.0,
        RoyaltyRank::DIRECTOR   => 15.0,
    ];

    [$start, $end] = RoyaltyPeriod::bounds($period);

    $awardTotal = 0.0;
    $paidTotal  = 0.0;
    $paidCount  = 0;
    $skipCount  = 0;

    foreach ($drafts as $draft) {
        $draftStatus = (string) ($draft['status'] ?? 'pending');

        if ($draftStatus === 'skipped') {
            $skipCount++;
            continue;
        }

        $awardTotal += (float) $draft['amount'];

        if ($draftStatus === 'paid') {
            $paidTotal += (float) $draft['amount'];
            $paidCount++;
        }
    }

    db()->prepare(
        'INSERT INTO royalty_periods
           (period, status, enabled, evaluated_at, evaluated_by, paid_at, paid_by, as_of,
            leader_directs, supervisor_leaders, manager_supervisors, director_managers,
            quota_supervisor, quota_manager, quota_director,
            group_pct_supervisor, personal_pct_supervisor,
            group_pct_manager, personal_pct_manager,
            group_pct_director, personal_pct_director,
            members_count, leaders_count, supervisors_count, managers_count, directors_count,
            award_count, paid_count, skipped_count, award_total, paid_total, note)
         VALUES (?, ?, ?, ?, NULL, ?, NULL, ?, 10, 10, 10, 10,
                 500000.00, 1000000.00, 5000000.00,
                 3.000, 5.000, 5.000, 10.000, 10.000, 15.000,
                 ?, 0, 0, 0, 0, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $period,
        $status,
        $status === 'skipped' ? 0 : 1,
        $start,
        $status === 'paid' ? $start : null,
        $end,
        max(1, count($drafts)),
        count($drafts),
        $paidCount,
        $skipCount,
        number_format(round($awardTotal, 2), 2, '.', ''),
        number_format(round($paidTotal, 2), 2, '.', ''),
        $note,
    ]);

    foreach ($drafts as $draft) {
        $draftStatus = (string) ($draft['status'] ?? 'pending');
        $rank        = (int) $draft['rank'];

        $awardId = RoyaltyAward::create([
            'period'          => $period,
            'user_id'         => (int) $draft['user'],
            'rank_level'      => $rank,
            'directs'         => 10,
            'downline_rank_count' => 10,
            'team_size'       => 111,
            'group_volume'    => $draft['gs'],
            'personal_volume' => $draft['ps'],
            'quota'           => $draft['quota'] ?? $quotas[$rank] ?? 0.0,
            'group_pct'       => $groups[$rank] ?? 0.0,
            'personal_pct'    => $personals[$rank] ?? 0.0,
            'amount'          => $draftStatus === 'skipped' ? 0.0 : (float) $draft['amount'],
            'status'          => $draftStatus === 'skipped' ? 'skipped' : 'pending',
            'reason'          => $draftStatus === 'skipped'
                ? (string) ($draft['reason'] ?? RoyaltyAward::REASON_QUOTA)
                : null,
        ]);

        if ($draftStatus === 'paid') {
            RoyaltyAward::markPaid($awardId, 900000 + $awardId);
        }
    }
};

/** Render /earnings through the member layout, exactly as the route does. */
$renderPage = static function (array $data): string {
    ob_start();

    try {
        View::page('member', 'member/earnings', $data + [
            'page_title'  => 'Earnings',
            'current_nav' => 'earnings',
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
            'live'        => Live::meta(Live::earningsSurface((int) $data['user_id']), 'page'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/** Sign in as a fixture member and put the browser on the Royalty tab. */
$signIn = static function (array $user, array $query = ['ledger' => 'royalty']): void {
    test_signed_in_as($user);

    $_GET = $query;
    $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?' . http_build_query($query);
};

return [
    /* ==========================================================
     *  P19E-T1 — the Royalty tab renders
     * ========================================================== */

    'P19E-T1 the Royalty tab renders the pill, rank card, stats, rows and pagination' => function () use (
        $configure,
        $member,
        $referenceTree,
        $buy,
        $seed,
        $prev,
        $signIn,
        $renderPage
    ): void {
        $configure();

        $tree = $referenceTree();
        $buy([$tree['sup'] => 60000.0, $tree['members'][0] => 225000.0, $tree['members'][1] => 225000.0]);
        $seed($prev, [[
            'user' => $tree['sup'], 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 510000.0, 'ps' => 60000.0, 'amount' => 18300.0, 'status' => 'paid',
        ]], 'paid');

        $sup = test_user('rty_m_ref_sup');
        $signIn($sup);

        $controller = new EarningsController();
        $data       = $controller->royaltyContext((int) $sup['id']);

        assert_same('royalty', $data['tab']);
        assert_same(1, $data['pager']->total, 'one closed month');

        $html = $renderPage($data);

        /* The third pill, active, and the plan's title and subtitle
         * (Appendix C.3). */
        assert_contains('Your Royalty bonus', $html);
        assert_contains('Royalty is paid once a month, after the month closes', $html);
        assert_contains('href="' . url('earnings?ledger=royalty') . '"', $html);
        assert_contains('aria-current="page"', $html);

        foreach ([
            'earnings-royalty-rank',
            'earnings-royalty-stats',
            'earnings-royalty-header',
            'earnings-royalty-rows',
            'earnings-royalty-pagination',
        ] as $region) {
            assert_contains('data-live-region="' . $region . '"', $html, $region . ' renders');
        }

        /* The rank card (Appendix C.3 labels). */
        assert_contains('Your rank', $html);
        assert_contains('Direct members', $html);
        assert_contains('Leaders in your team', $html);
        assert_contains('Supervisors in your team', $html);
        assert_contains('Managers in your team', $html);
        assert_contains('Supervisor', $html);
        assert_contains(money(60000.0), $html, 'personal sales this month');
        assert_contains(money(510000.0), $html, 'group sales this month');
        assert_contains(money(500000.0), $html, 'the Supervisor quota');
        assert_contains(money(18300.0), $html, 'the projection and the paid month');
        assert_contains('If this month closed now', $html, 'the projection is labelled');
        assert_contains('Quota met', $html);

        /* The stats card and the month row. */
        assert_contains('Paid all-time', $html);
        assert_contains('Awaiting payment', $html);
        assert_contains('Last paid', $html);
        assert_contains('Your Royalty awards', $html);
        assert_contains('One row per closed month', $html);

        /* The feed carries the matching five fragments and no form. */
        $payload = $controller->feedPayload(3, '"member.earnings:' . (int) $sup['id'] . ':3:t1"', $data);

        assert_same(
            [
                'earnings-royalty-header',
                'earnings-royalty-rank',
                'earnings-royalty-stats',
                'earnings-royalty-rows',
                'earnings-royalty-pagination',
            ],
            array_keys($payload['fragments'])
        );
        assert_contains('data-live-region="earnings-royalty-rank"', $payload['fragments']['earnings-royalty-rank']);
        assert_contains('data-live-region="earnings-royalty-rows"', $payload['fragments']['earnings-royalty-rows']);
        assert_not_contains('name="period"', $payload['fragments']['earnings-royalty-rows'], 'the filter form never travels in a row fragment');
        assert_contains('earnings?ledger=royalty', $payload['meta']['canonical']);
    },

    /* ==========================================================
     *  P19E-T2 — the package tab is unchanged
     * ========================================================== */

    'P19E-T2 the package tab keeps its regions, filters, columns, drawer and export' => function () use (
        $member,
        $signIn,
        $renderPage
    ): void {
        $owner = $member('rty_m_t2_owner');
        $signIn(test_user('rty_m_t2_owner'), ['status' => 'credited', 'per_page' => '25']);

        $controller = new EarningsController();
        $data       = $controller->earningsContext($owner);
        $payload    = $controller->feedPayload(2, '"member.earnings:' . $owner . ':2:t2"', $data);

        assert_same(EarningsController::TAB_PACKAGE, $data['tab']);
        assert_same(
            [
                'earnings-header',
                'earnings-stats',
                'earnings-levels',
                'earnings-skipped',
                'earnings-rows',
                'earnings-pagination',
            ],
            array_keys($payload['fragments'])
        );

        /* Byte-identical to the partials the tab has always rendered. */
        assert_same(
            View::capture('member/earnings/_header', ['pager' => $data['pager']]),
            $payload['fragments']['earnings-header']
        );
        assert_same(
            View::capture('member/earnings/_stats', ['totals' => $data['totals']]),
            $payload['fragments']['earnings-stats']
        );
        assert_same(
            View::capture('member/earnings/_levels', ['levels' => $data['levels'], 'totals' => $data['totals']]),
            $payload['fragments']['earnings-levels']
        );
        assert_same(
            View::capture('member/earnings/_skipped', ['totals' => $data['totals']]),
            $payload['fragments']['earnings-skipped']
        );
        assert_same(
            View::capture('member/earnings/_rows', [
                'commissions' => $data['commissions'],
                'filters'     => $data['filters'],
                'wallet_url'  => $data['wallet_url'],
            ]),
            $payload['fragments']['earnings-rows']
        );
        assert_same(
            View::capture('member/earnings/_pagination', ['pager' => $data['pager']]),
            $payload['fragments']['earnings-pagination']
        );

        /* The export still points at the package statement. */
        assert_same(url('earnings/export?status=credited'), $data['export_url']);

        $html = $renderPage($data);

        foreach (['Source', 'Package', 'Type', 'Amount', 'Status', 'Details'] as $column) {
            assert_contains($column, $html, 'the package table keeps its ' . $column . ' column');
        }

        foreach (['name="type"', 'name="level"', 'name="status"', 'name="from"', 'name="to"', 'name="q"'] as $field) {
            assert_contains($field, $html, 'the package filter form keeps ' . $field);
        }

        assert_contains('id="commissionDetail"', $html, 'the award drawer is still there');
        assert_not_contains('data-live-region="earnings-royalty-', $html, 'no Royalty region on the package tab');
        assert_not_contains('data-live-region="earnings-repeat-', $html, 'no repeat region on the package tab');
    },

    /* ==========================================================
     *  P19E-T3 — the repeat tab is unchanged
     * ========================================================== */

    'P19E-T3 the repeat tab keeps its five regions and escrow states' => function () use (
        $member,
        $signIn
    ): void {
        $owner = $member('rty_m_t3_owner');
        $signIn(test_user('rty_m_t3_owner'), ['ledger' => 'repeat']);

        $controller = new EarningsController();
        $data       = $controller->repeatContext($owner);
        $payload    = $controller->feedPayload(3, '"member.earnings:' . $owner . ':3:t3"', $data);

        assert_same(EarningsController::TAB_REPEAT, $data['tab']);
        assert_same(
            [
                'earnings-repeat-header',
                'earnings-repeat-stats',
                'earnings-repeat-status',
                'earnings-repeat-levels',
                'earnings-repeat-rows',
                'earnings-repeat-pagination',
            ],
            array_keys($payload['fragments'])
        );

        assert_same(
            View::capture('member/earnings/_repeat_rows', [
                'awards'        => $data['awards'],
                'filters'       => $data['filters'],
                'statement_url' => $data['statement_url'],
                'wallet_url'    => $data['wallet_url'],
                'shop_url'      => $data['shop_url'],
            ]),
            $payload['fragments']['earnings-repeat-rows']
        );
        assert_same(
            View::capture('member/earnings/_repeat_stats', ['totals' => $data['totals']]),
            $payload['fragments']['earnings-repeat-stats']
        );
        assert_same(
            View::capture('member/earnings/_repeat_status', ['status' => $data['status']]),
            $payload['fragments']['earnings-repeat-status']
        );
        assert_same(
            View::capture('member/earnings/_repeat_levels', ['levels' => $data['levels'], 'totals' => $data['totals']]),
            $payload['fragments']['earnings-repeat-levels']
        );

        assert_contains('Pending release', $payload['fragments']['earnings-repeat-stats']);
        assert_same(url('earnings/export?ledger=repeat'), $data['export_url']);

        /* The repeat tab never renders a Royalty region. */
        assert_not_contains('earnings-royalty-', implode('', $payload['fragments']));
    },

    /* ==========================================================
     *  P19E-T4 — the rank card is a Supervisor's truth, and its
     *            projection is exactly what the close pays
     * ========================================================== */

    'P19E-T4 a Supervisor sees 10/10, the quota, the projection, and the close pays it' => function () use (
        $configure,
        $member,
        $referenceTree,
        $buy,
        $signIn,
        $renderPage,
        $open,
        $prev
    ): void {
        $configure();

        $tree = $referenceTree();

        /* The same 510,000 / 60,000 month in both frames: the open
         * month drives the rank card, the closed month is what the
         * engine will actually pay. */
        $buy(
            [$tree['sup'] => 60000.0, $tree['members'][0] => 225000.0, $tree['members'][1] => 225000.0],
            ['paid_at' => date('Y-m-d H:i:s')]
        );
        $buy(
            [$tree['sup'] => 60000.0, $tree['members'][0] => 225000.0, $tree['members'][1] => 225000.0],
            ['paid_at' => RoyaltyPeriod::bounds($prev)[0]]
        );

        $sup = test_user('rty_m_ref_sup');
        $signIn($sup);

        $controller = new EarningsController();
        $data       = $controller->royaltyContext((int) $sup['id']);
        $card       = $data['royalty'];

        assert_same(RoyaltyRank::SUPERVISOR, $card['rank']);
        assert_same('Supervisor', $card['rank_name']);
        assert_same(10, $card['directs']);
        assert_same(10, $card['downline']['leaders']);
        assert_same(0, $card['downline']['supervisors']);
        assert_same(RoyaltyRank::MANAGER, $card['next']['rank']);
        assert_same(10, $card['next']['need']);
        assert_same(0, $card['next']['have']);
        assert_same(60000.0, $card['personal']);
        assert_same(510000.0, $card['group']);
        assert_same(500000.0, $card['quota']);
        assert_same(3.0, $card['group_pct']);
        assert_same(5.0, $card['personal_pct']);
        assert_same(100, $card['progress']);
        assert_true($card['eligible']);
        assert_same(18300.0, $card['projected'], '0.03 x 510,000 + 0.05 x 60,000');

        $html = $renderPage($data);

        assert_contains('0 / 10', $html, 'the Manager requirement shows Supervisors in the team');
        assert_contains('<strong>If this month closed now: ' . money(18300.0) . '</strong>', $html);
        assert_contains(money(500000.0), $html);
        assert_contains('3.000% group', $html);
        assert_contains('5.000% personal', $html);

        /* The projection is the engine's own result: close the same
         * month and compare. */
        $engine    = new RoyaltyService();
        $projected = $card['projected'];
        $status    = $engine->statusFor((int) $sup['id'], $prev);

        assert_same($projected, $status['projected'], 'the closed-month projection is the same amount');

        $evaluation = $engine->evaluate($prev, null);

        assert_true((float) $evaluation['award_total'] >= $projected, 'the month evaluated money');

        $row = RoyaltyAward::forMemberPeriod((int) $sup['id'], $prev);

        assert_not_null($row);
        assert_same('pending', (string) $row['status']);
        assert_same(number_format($projected, 2, '.', ''), (string) $row['amount'], 'the close paid the projection');

        $payment = $engine->pay($prev, 500, 20, null);

        assert_true((int) $payment['paid'] >= 1);
        assert_true((float) $payment['amount'] >= $projected);

        $paid = RoyaltyAward::forMemberPeriod((int) $sup['id'], $prev);

        assert_not_null($paid);
        assert_same('paid', (string) $paid['status']);

        /* A stored month is never recomputed (plan §14.11). */
        assert_same($projected, $engine->statusFor((int) $sup['id'], $prev)['projected']);
        assert_same($open, $engine->statusFor((int) $sup['id'])['period'], 'the card defaults to the open month');
    },

    /* ==========================================================
     *  P19E-T5 — a Leader is explained, not hidden
     * ========================================================== */

    'P19E-T5 a Leader sees "not a paying rank" and the progress to Supervisor' => function () use (
        $configure,
        $member,
        $referenceTree,
        $buy,
        $signIn,
        $renderPage
    ): void {
        $configure();

        $tree   = $referenceTree();
        $leader = $tree['leaders'][0];

        /* Ten million of group sales and a Leader is still paid nothing
         * (plan §12.7 F). */
        $buy([$leader => 10000000.0]);

        $signIn(test_user('rty_m_ref_l1'));

        $controller = new EarningsController();
        $data       = $controller->royaltyContext($leader);
        $card       = $data['royalty'];

        assert_same(RoyaltyRank::LEADER, $card['rank']);
        assert_same('Leader', $card['rank_name']);
        assert_same(10, $card['directs']);
        assert_same(0, $card['downline']['leaders']);
        assert_same(RoyaltyRank::SUPERVISOR, $card['next']['rank']);
        assert_same(10, $card['next']['need']);
        assert_same(0, $card['next']['have']);
        assert_same(0.0, (float) $card['quota'], 'a Leader has no quota');
        assert_same(0.0, $card['projected'], 'a Leader is not paid');

        $html = $renderPage($data);

        assert_contains('Leader is a rank, not a royalty rank', $html);
        assert_contains('the first rank that pays is Supervisor', $html);
        assert_contains('Reach 10 Leaders in your team to become a Supervisor', $html);
        assert_contains('Leaders in your team', $html);
        assert_not_contains('If this month closed now', $html, 'no quota preview for a non-paying rank');
        assert_not_contains('Leader quota', $html, 'no quota line for a Leader');
        assert_contains(money(10000000.0), $html, 'the group sales are still stated');
    },

    /* ==========================================================
     *  P19E-T6 — a member can only ever read their own rows
     * ========================================================== */

    'P19E-T6 ?user_id= is ignored by the statement and by the export' => function () use (
        $configure,
        $member,
        $seed,
        $signIn,
        $renderPage,
        $prev,
        $prior
    ): void {
        $configure();

        $mine  = $member('rty_m_t6_mine');
        $other = $member('rty_m_t6_other');

        $seed($prev, [
            ['user' => $mine, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 510000, 'ps' => 60000, 'amount' => 18300, 'status' => 'paid'],
            ['user' => $other, 'rank' => RoyaltyRank::MANAGER, 'gs' => 1200000, 'ps' => 100000, 'amount' => 70000, 'status' => 'paid'],
        ], 'paid');
        $seed($prior, [
            ['user' => $other, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 500000, 'ps' => 0, 'amount' => 15000, 'status' => 'paid'],
        ], 'paid');

        $signIn(test_user('rty_m_t6_mine'), ['ledger' => 'royalty', 'user_id' => (string) $other, 'period' => '', 'status' => '', 'rank' => '']);

        $controller = new EarningsController();
        $data       = $controller->royaltyContext($mine);

        assert_same(1, $data['pager']->total, 'only the session member has a row in this month set');
        assert_same(1, count($data['awards']));
        assert_same($mine, (int) $data['awards'][0]['user_id']);
        assert_same(85000.0, (float) RoyaltyAward::totalsForUser($other)['paid_total'], 'the other member keeps their money');

        /* The export is scoped the same way: a hand-edited `user_id`
         * cannot reach another member's rows. */
        $rows = RoyaltyAward::exportRows([], null, $mine);

        assert_same(1, count($rows));
        assert_same($mine, (int) $rows[0]['user_id']);

        $unscoped = RoyaltyAward::exportRows([], null, $other);

        assert_same(2, count($unscoped), 'the model scopes to the user it is given');

        /* The page itself never prints the other member's money. */
        $html = $renderPage($data);

        assert_contains(money(18300.0), $html);
        assert_not_contains(money(70000.0), $html, 'no other member award');
        assert_not_contains(money(15000.0), $html, 'no other member award');
    },

    /* ==========================================================
     *  P19E-T7 — the history rows carry every field
     * ========================================================== */

    'P19E-T7 the rows show period, rank, GS, PS, quota, rates, amount, status and paid date' => function () use (
        $configure,
        $member,
        $seed,
        $signIn,
        $renderPage,
        $prev,
        $prior
    ): void {
        $configure();

        $owner = $member('rty_m_t7_owner');

        $seed($prev, [[
            'user' => $owner, 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 510000, 'ps' => 60000, 'amount' => 18300, 'status' => 'paid',
        ]], 'paid');
        $seed($prior, [[
            'user' => $owner, 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 500000, 'ps' => 0, 'amount' => 15000, 'status' => 'pending',
        ]]);

        $signIn(test_user('rty_m_t7_owner'));

        $controller = new EarningsController();
        $data       = $controller->royaltyContext($owner);
        $html       = $renderPage($data);
        $rows       = $controller->feedPayload(7, '"x"', $data)['fragments']['earnings-royalty-rows'];

        assert_same(2, $data['pager']->total);
        assert_same(33300.0, (float) $data['stats']['paid_all_time'] + (float) $data['stats']['pending_total']);

        assert_contains(format_month($prev), $rows);
        assert_contains(format_month($prior), $rows);
        assert_contains('Supervisor', $rows);
        assert_contains(money(510000.0), $rows);
        assert_contains(money(60000.0), $rows);
        assert_contains(money(500000.0), $rows, 'the quota the month was judged on');
        assert_contains('3.000% / 5.000%', $rows, 'the two rates');
        assert_contains(money(18300.0), $rows);
        assert_contains('Paid', $rows);
        assert_contains(format_date(RoyaltyAward::forMemberPeriod($owner, $prev)['credited_at'] ?? ''), $rows);

        /* The page shows the same two months under one table. */
        assert_contains('data-live-region="earnings-royalty-rows"', $html);
        assert_contains(money(18300.0), $html);
        assert_contains(money(15000.0), $html);
    },

    /* ==========================================================
     *  P19E-T8 — a skipped month explains itself
     * ========================================================== */

    'P19E-T8 a below-quota row shows the stored reason and the GS/PS it was judged on' => function () use (
        $configure,
        $member,
        $seed,
        $signIn,
        $prev
    ): void {
        $configure();

        $owner = $member('rty_m_t8_owner');

        $seed($prev, [[
            'user' => $owner, 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 410000, 'ps' => 60000, 'amount' => 0.0,
            'status' => 'skipped', 'reason' => RoyaltyAward::REASON_QUOTA,
        ]]);

        $signIn(test_user('rty_m_t8_owner'));

        $controller = new EarningsController();
        $data       = $controller->royaltyContext($owner);
        $rows       = $controller->feedPayload(8, '"x"', $data)['fragments']['earnings-royalty-rows'];

        assert_same(1, $data['pager']->total);
        assert_same(0.0, (float) $data['stats']['paid_all_time']);
        assert_same(1, (int) $data['stats']['skipped_rows']);

        /* The member label, never the admin's "Skipped". */
        assert_contains('Not paid', $rows);
        assert_not_contains('Skipped', $rows);

        /* The reason names the exact quota it missed, and the row still
         * shows the volume it was judged on. */
        assert_contains('Group sales below the ' . money(500000.0) . ' quota', $rows);
        assert_contains('Judged on ' . money(410000.0) . ' group / ' . money(60000.0) . ' personal', $rows);
        assert_contains(money(410000.0), $rows);
        assert_contains(money(0.0), $rows, 'a skipped month is not money');
        assert_contains('no payment', $rows);
    },

    /* ==========================================================
     *  P19E-T9 — a pending month is awaiting payment, not paid
     * ========================================================== */

    'P19E-T9 a pending row says "Awaiting payment" and carries no paid date' => function () use (
        $configure,
        $member,
        $seed,
        $signIn,
        $prev
    ): void {
        $configure();

        $owner = $member('rty_m_t9_owner');

        $seed($prev, [[
            'user' => $owner, 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 510000, 'ps' => 60000, 'amount' => 18300, 'status' => 'pending',
        ]]);

        $signIn(test_user('rty_m_t9_owner'));

        $controller = new EarningsController();
        $data       = $controller->royaltyContext($owner);
        $rows       = $controller->feedPayload(9, '"x"', $data)['fragments']['earnings-royalty-rows'];

        assert_same(1, (int) $data['stats']['pending_rows']);
        assert_same(18300.0, (float) $data['stats']['pending_total']);
        assert_same(0.0, (float) $data['stats']['paid_all_time']);

        assert_contains('Awaiting payment', $rows);
        assert_contains('not in your wallet yet', $rows);
        assert_not_contains('bg-label-success">Paid', $rows, 'a pending row is never a paid badge');
        assert_contains('>—<', str_replace(["\n", ' '], '', $rows), 'no paid date');

        /* The stats card names the same state. */
        $stats = $controller->feedPayload(9, '"x"', $data)['fragments']['earnings-royalty-stats'];

        assert_contains('Awaiting payment', $stats);
        assert_contains(money(18300.0), $stats);
    },

    /* ==========================================================
     *  P19E-T10 — the stream is off: history stays, promises change
     * ========================================================== */

    'P19E-T10 with Royalty off the history stays and the promise is marked off' => function () use (
        $configure,
        $member,
        $seed,
        $signIn,
        $renderPage,
        $prev
    ): void {
        $configure(['royalty_enabled' => '0']);

        $owner = $member('rty_m_t10_owner');

        $seed($prev, [[
            'user' => $owner, 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 510000, 'ps' => 60000, 'amount' => 18300, 'status' => 'paid',
        ]], 'paid');

        $signIn(test_user('rty_m_t10_owner'));

        $controller = new EarningsController();
        $data       = $controller->royaltyContext($owner);
        $card       = $data['royalty'];

        assert_false($card['enabled']);
        assert_same(18300.0, $card['paid_all_time'], 'history is still the member’s money');
        assert_same(1, $data['pager']->total);

        $html = $renderPage($data);

        assert_contains('Royalty is currently off', $html);
        assert_contains('Past months stay in your history', $html);
        assert_not_contains('If this month closed now', $html, 'no projection while the stream is off');

        /* The history row is still rendered and still paid. */
        assert_contains(money(18300.0), $html);
        assert_contains('Paid', $html);

        $rows = $controller->feedPayload(10, '"x"', $data)['fragments']['earnings-royalty-rows'];

        assert_contains('data-live-key=', $rows);
        assert_contains(money(18300.0), $rows);
    },

    /* ==========================================================
     *  P19E-T11 — payments paused: pending, never paid
     * ========================================================== */

    'P19E-T11 with payments paused a pending month says so and is never paid' => function () use (
        $configure,
        $member,
        $seed,
        $signIn,
        $prev
    ): void {
        $configure(['royalty_pay_enabled' => '0']);

        $owner = $member('rty_m_t11_owner');

        $seed($prev, [[
            'user' => $owner, 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 510000, 'ps' => 60000, 'amount' => 18300, 'status' => 'pending',
        ]]);

        $signIn(test_user('rty_m_t11_owner'));

        $controller = new EarningsController();
        $data       = $controller->royaltyContext($owner);
        $rows       = $controller->feedPayload(11, '"x"', $data)['fragments']['earnings-royalty-rows'];
        $rank       = $controller->feedPayload(11, '"x"', $data)['fragments']['earnings-royalty-rank'];

        assert_false($data['royalty']['payments_enabled']);
        assert_same(1, (int) $data['stats']['pending_rows']);

        assert_contains('Awaiting payment', $rows);
        assert_contains('Payment is paused; the award stays pending.', $rows);
        assert_contains('payment is paused', $rows);
        assert_not_contains('bg-label-success">Paid', $rows);
        assert_contains('Payment is paused right now', $rank, 'the rank card states the pause too');
        assert_same(0.0, (float) RoyaltyAward::totalsForUser($owner)['paid_total'], 'no money reached the wallet');
    },

    /* ==========================================================
     *  P19E-T12 — the member CSV
     * ========================================================== */

    'P19E-T12 the export writes the Appendix C.6 columns for this member only' => function () use (
        $configure,
        $member,
        $seed,
        $signIn,
        $prev,
        $prior
    ): void {
        $configure();

        $mine  = $member('rty_m_t12_mine');
        $other = $member('rty_m_t12_other');

        $seed($prev, [
            ['user' => $mine, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 510000, 'ps' => 60000, 'amount' => 18300, 'status' => 'paid'],
            ['user' => $other, 'rank' => RoyaltyRank::MANAGER, 'gs' => 1200000, 'ps' => 100000, 'amount' => 70000, 'status' => 'paid'],
        ], 'paid');
        $seed($prior, [[
            'user' => $mine, 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 410000, 'ps' => 60000, 'amount' => 0.0,
            'status' => 'skipped', 'reason' => RoyaltyAward::REASON_QUOTA,
        ]]);

        $signIn(test_user('rty_m_t12_mine'), ['ledger' => 'royalty', 'status' => '']);

        $auditBefore = (int) db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

        assert_same(
            ['Period', 'Rank', 'Group sales', 'Personal sales', 'Quota', 'Group %', 'Personal %', 'Amount', 'Status', 'Reason', 'Paid at'],
            EarningsController::royaltyCsvHeader()
        );

        $rows = RoyaltyAward::exportRows([], null, $mine);

        assert_same(2, count($rows), 'only this member’s months');
        assert_same($prev, (string) $rows[0]['period'], 'newest month first');

        $csv = EarningsController::royaltyCsvRows($rows);

        assert_same($prev, $csv[0][0]);
        assert_same('Supervisor', $csv[0][1]);
        assert_same('510000.00', $csv[0][2]);
        assert_same('60000.00', $csv[0][3]);
        assert_same('500000.00', $csv[0][4]);
        assert_same('3.000', $csv[0][5]);
        assert_same('5.000', $csv[0][6]);
        assert_same('18300.00', $csv[0][7]);
        assert_same('Paid', $csv[0][8]);
        assert_same('', $csv[0][9]);
        assert_true($csv[0][10] !== '', 'the paid date is written');

        assert_same($prior, $csv[1][0]);
        assert_same('0.00', $csv[1][7]);
        assert_same('Not paid', $csv[1][8], 'the member label, not "skipped"');
        assert_same(RoyaltyAward::REASON_QUOTA, $csv[1][9]);
        assert_same('', $csv[1][10], 'a skipped month has no paid date');

        /* The export mirrors the screen's filters and the member's scope. */
        $filters = RoyaltyAward::normaliseFilters(['period' => $prev, 'status' => 'paid', 'rank' => '', 'q' => '']);

        assert_same(1, count(RoyaltyAward::exportRows($filters, null, $mine)));

        /* A member export writes no audit row (plan §15.1). */
        assert_same($auditBefore, (int) db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());

        /* The screen's own export URL is the Royalty one, and it keeps
         * the tab when the filters are empty. */
        $context = (new EarningsController())->royaltyContext($mine);

        assert_same(url('earnings/export?ledger=royalty'), $context['export_url']);

        $_GET    = ['ledger' => 'royalty', 'status' => 'paid'];
        $context = (new EarningsController())->royaltyContext($mine);

        assert_same(url('earnings/export?ledger=royalty&status=paid'), $context['export_url']);
        assert_same(1, $context['pager']->total, 'the status filter narrows the statement');
    },

    /* ==========================================================
     *  P19E-T16 — no member surface writes a row
     * ========================================================== */

    'P19E-T16 rendering, filtering and exporting write no royalty, wallet or audit row' => function () use (
        $configure,
        $member,
        $seed,
        $signIn,
        $prev
    ): void {
        $configure();

        $member('rty_m_t16_owner');

        $seed($prev, [[
            'user' => test_user('rty_m_t16_owner')['id'], 'rank' => RoyaltyRank::SUPERVISOR,
            'gs' => 510000, 'ps' => 60000, 'amount' => 18300, 'status' => 'paid',
        ]], 'paid');

        $owner = test_user('rty_m_t16_owner');

        $count = static fn (string $table): int => (int) db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        $snapshot = [
            'royalty_periods' => $count('royalty_periods'),
            'royalty_awards'  => $count('royalty_awards'),
            'ewallet_ledger'  => $count('ewallet_ledger'),
            'audit_log'       => $count('audit_log'),
        ];

        $signIn($owner);
        $controller = new EarningsController();

        foreach ([
            ['ledger' => 'royalty'],
            ['ledger' => 'royalty', 'status' => 'paid'],
            ['ledger' => 'royalty', 'period' => $prev],
            ['ledger' => 'royalty', 'rank' => (string) RoyaltyRank::SUPERVISOR],
        ] as $query) {
            $_GET = $query;
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?' . http_build_query($query);

            $data = $controller->royaltyContext((int) $owner['id']);

            ob_start();

            try {
                View::page('member', 'member/earnings', $data + [
                    'page_title'  => 'Earnings',
                    'current_nav' => 'earnings',
                    'page_css'    => ['assets/css/commissions.css'],
                    'page_js'     => ['assets/js/app-commissions.js'],
                    'live'        => Live::meta(Live::earningsSurface((int) $owner['id']), 'page'),
                ]);
            } finally {
                ob_end_clean();
            }

            $controller->feedPayload(1, '"x"', $data);
        }

        RoyaltyAward::exportRows([], null, (int) $owner['id']);

        assert_same($snapshot['royalty_periods'], $count('royalty_periods'));
        assert_same($snapshot['royalty_awards'], $count('royalty_awards'));
        assert_same($snapshot['ewallet_ledger'], $count('ewallet_ledger'));
        assert_same($snapshot['audit_log'], $count('audit_log'));
    },

    /* ==========================================================
     *  P19G-T9 — a ₱0 quota is met (the engine's rule)
     *
     *  A quota of 0 is a legal stored value (0.00 … 999,999,999.99)
     *  and the engine pays every group volume against it, but the
     *  rank card used to treat `quota > 0` as a precondition. With
     *  a 0 quota it said "below the ₱0.00 quota" and projected ₱0
     *  while the close would have paid. The card now follows the
     *  engine: group sales >= quota, and 0 >= 0 is met.
     * ========================================================== */

    'P19G-T9 a zero quota is met: the card projects what the close would pay' => function () use (
        $configure,
        $referenceTree,
        $buy,
        $signIn,
        $renderPage
    ): void {
        $configure(['royalty_supervisor_quota' => '0']);

        $tree = $referenceTree();
        $buy([$tree['sup'] => 10000.0]);

        $sup = test_user('rty_m_ref_sup');
        $signIn($sup);

        $data = (new EarningsController())->royaltyContext((int) $sup['id']);
        $html = $renderPage($data);

        /* 0.03 × 10,000 group + 0.05 × 10,000 personal = ₱800.00. */
        assert_same(800.0, (float) $data['royalty']['projected']);
        assert_contains('Quota met', $html, 'a zero quota reads as met');
        assert_contains('If this month closed now: ' . money(800.0), $html, 'the projection is the engine amount');
        assert_not_contains('below the ' . money(0.0) . ' quota', $html, 'no false "below quota" copy');
        assert_not_contains('not met yet', $html);
    },
];
