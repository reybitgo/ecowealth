<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyReportTest — Phase 19D (plan §8, §17)
 *
 *  Proves the reports desk and the dashboard read the Royalty money:
 *
 *      cards       the summary card, the rank table and the period
 *                  table render from the same read model
 *      CSV         `type=royalty` downloads the period/rank rows
 *      dashboard   the Royalty KPI is all-time + this month, never the
 *                  open month's preview
 *      ratio       royalty paid ÷ paid sales, one decimal
 *
 *  The fixtures insert the Phase 19C rows directly: this file tests the
 *  reporting reads, not the engine (RoyaltyEngineTest owns the money).
 *
 *  Run this group alone with:  php tests/run.php RoyaltyReport
 * ============================================================== */

$period      = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());
$prior       = RoyaltyPeriod::previous($period);
$periodStart = substr(RoyaltyPeriod::bounds($period)[0], 0, 10);
$periodLast  = date('Y-m-d', (int) strtotime(substr(RoyaltyPeriod::bounds($period)[1], 0, 10) . ' -1 day'));
$priorStart  = substr(RoyaltyPeriod::bounds($prior)[0], 0, 10);
/* The window covers both fixture months, so the tests exercise the
 * whole-period read (there is no pro-rata inside a month). */
$range       = ReportService::range($priorStart, $periodLast);

/** One member account with a stable username. */
$member = static function (string $username): array {
    return test_user($username);
};

/**
 * One period header + its award rows (the same shape the close writes).
 *
 * @param list<array{user:array<string, mixed>, rank:int, gs?:float, ps?:float,
 *                   amount?:float, status?:string, reason?:string}> $drafts
 * @return array{header:array<string, mixed>, awards:list<array<string, mixed>>}
 */
$seed = static function (string $period, array $drafts, string $status = 'evaluated'): array {
    $quotas = [RoyaltyRank::SUPERVISOR => 500000.0, RoyaltyRank::MANAGER => 1000000.0, RoyaltyRank::DIRECTOR => 5000000.0];
    $groups = [RoyaltyRank::SUPERVISOR => 3.0, RoyaltyRank::MANAGER => 5.0, RoyaltyRank::DIRECTOR => 10.0];
    $personals = [RoyaltyRank::SUPERVISOR => 5.0, RoyaltyRank::MANAGER => 10.0, RoyaltyRank::DIRECTOR => 15.0];

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

        $rank   = (int) $draft['rank'];
        $amount = isset($draft['amount'])
            ? (float) $draft['amount']
            : round(
                (float) ($draft['gs'] ?? 0) * (float) ($groups[$rank] ?? 0) / 100
                + (float) ($draft['ps'] ?? 0) * (float) ($personals[$rank] ?? 0) / 100,
                2
            );

        $awardTotal += $amount;

        if ($draftStatus === 'paid') {
            $paidTotal += $amount;
            $paidCount++;
        }
    }

    [$start, $end] = RoyaltyPeriod::bounds($period);

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
         VALUES (?, ?, 1, ?, NULL, ?, NULL, ?, 10, 10, 10, 10,
                 500000.00, 1000000.00, 5000000.00,
                 3.000, 5.000, 5.000, 10.000, 10.000, 15.000,
                 ?, 0, 0, 0, 0, ?, ?, ?, ?, ?, NULL)'
    )->execute([
        $period,
        $status,
        $start,
        $status === 'paid' ? $start : null,
        $end,
        max(1, count($drafts)),
        count($drafts),
        $paidCount,
        $skipCount,
        number_format(round($awardTotal, 2), 2, '.', ''),
        number_format(round($paidTotal, 2), 2, '.', ''),
    ]);

    foreach ($drafts as $draft) {
        $draftStatus = (string) ($draft['status'] ?? 'pending');
        $rank        = (int) $draft['rank'];
        $amount      = isset($draft['amount'])
            ? (float) $draft['amount']
            : round(
                (float) ($draft['gs'] ?? 0) * (float) ($groups[$rank] ?? 0) / 100
                + (float) ($draft['ps'] ?? 0) * (float) ($personals[$rank] ?? 0) / 100,
                2
            );

        $awardId = RoyaltyAward::create([
            'period'         => $period,
            'user_id'        => (int) $draft['user']['id'],
            'rank_level'     => $rank,
            'group_volume'   => $draft['gs'] ?? 0.0,
            'personal_volume' => $draft['ps'] ?? 0.0,
            'quota'          => $quotas[$rank] ?? 0.0,
            'group_pct'      => $groups[$rank] ?? 0.0,
            'personal_pct'   => $personals[$rank] ?? 0.0,
            'amount'         => $draftStatus === 'skipped' ? 0.0 : $amount,
            'status'         => $draftStatus === 'skipped' ? 'skipped' : 'pending',
            'reason'         => $draftStatus === 'skipped' ? (string) ($draft['reason'] ?? RoyaltyAward::REASON_QUOTA) : null,
        ]);

        if ($draftStatus === 'paid') {
            RoyaltyAward::markPaid($awardId, 200000 + $awardId);
        }
    }

    return [
        'header' => (array) RoyaltyPeriod::find($period),
        'awards' => RoyaltyAward::rowsForPeriod($period),
    ];
};

/** One paid shop order, so the payout ratio has a sales denominator. */
$paidSale = static function (float $amount, ?string $paidAt = null): int {
    $buyer     = test_user('r19d_sale_' . bin2hex(random_bytes(3)));
    $productId = (int) db()->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();

    $orderId = test_order((int) $buyer['id'], [['product_id' => $productId]], 'paid', [
        'paid_at'  => $paidAt ?? date('Y-m-d H:i:s'),
        'subtotal' => number_format($amount, 2, '.', ''),
        'total'    => number_format($amount, 2, '.', ''),
    ]);

    db()->prepare('UPDATE shop_order_items SET line_total = ? WHERE order_id = ?')
        ->execute([number_format($amount, 2, '.', ''), $orderId]);

    return $orderId;
};

return [
    /* ==========================================================
     *  P19D-T12 — the report cards
     * ========================================================== */

    'P19D-T12 the royalty summary, rank table and period table render' => function () use ($member, $seed, $paidSale, $period, $prior, $periodStart, $range): void {
        $director   = $member('r19d_rep_director');
        $manager    = $member('r19d_rep_manager');
        $supervisor = $member('r19d_rep_supervisor');

        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'ps' => 100000, 'status' => 'paid'],
            ['user' => $manager, 'rank' => RoyaltyRank::MANAGER, 'gs' => 2450000, 'status' => 'paid'],
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 410000, 'ps' => 60000, 'status' => 'skipped', 'reason' => RoyaltyAward::REASON_QUOTA],
        ], 'paid');

        $seed($prior, [
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 600000, 'amount' => 18000.00, 'status' => 'pending'],
        ], 'evaluated');

        $paidSale(1000000.00, $periodStart . ' 12:00:00');

        test_signed_in_as(test_user('r19d_rep_admin', 'admin'));

        $page = (new ReportService())->page($range);

        foreach (['royalty', 'royalty_ranks', 'royalty_periods', 'royalty_ratio'] as $key) {
            assert_true(isset($page[$key]), $key . ' is in the page payload');
        }

        assert_same(637500.0, (float) $page['royalty']['paid'], '515,000 + 122,500 paid');
        assert_same(18000.0, (float) $page['royalty']['pending']);
        assert_same(1, (int) $page['royalty']['skipped']);
        assert_same(3, (int) $page['royalty']['recipients'], 'two paid members and one pending');
        assert_same(2, (int) $page['royalty']['periods']);

        /* The ratio: 637,500 of 1,000,000 sales = 63.75% → one decimal. */
        assert_same(637500.0, (float) $page['royalty_ratio']['royalty']);
        assert_same(1000000.0, (float) $page['royalty_ratio']['sales']);
        assert_same(63.8, (float) $page['royalty_ratio']['ratio']);
        assert_same(63.8, (float) $page['royalty']['payout_ratio']);

        /* The rank table lists every paying rank, Director first. */
        $ranks = $page['royalty_ranks'];
        assert_count(3, $ranks);
        assert_same(RoyaltyRank::DIRECTOR, (int) $ranks[0]['rank']);
        assert_same('Director', (string) $ranks[0]['name']);
        assert_same(515000.0, (float) $ranks[0]['paid']);
        assert_same(RoyaltyRank::MANAGER, (int) $ranks[1]['rank']);
        assert_same(122500.0, (float) $ranks[1]['paid']);
        assert_same(RoyaltyRank::SUPERVISOR, (int) $ranks[2]['rank']);
        assert_same(0.0, (float) $ranks[2]['paid'], 'the skipped Supervisor paid nothing');
        assert_same(18000.0, (float) $ranks[2]['pending']);
        assert_same(1, (int) $ranks[2]['skipped']);

        /* The period table is newest first and carries the stored header. */
        $periods = $page['royalty_periods'];
        assert_count(2, $periods);
        assert_same($period, (string) $periods[0]['period']);
        assert_same('paid', (string) $periods[0]['status']);
        assert_same(3, (int) $periods[0]['award_count']);
        assert_same(2, (int) $periods[0]['paid_count']);
        assert_same(1, (int) $periods[0]['skipped_count']);
        assert_same(637500.0, (float) $periods[0]['award_total']);
        assert_same(637500.0, (float) $periods[0]['paid_total']);
        assert_same($prior, (string) $periods[1]['period']);
        assert_same('evaluated', (string) $periods[1]['status']);
        assert_same(18000.0, (float) $periods[1]['award_total']);

        /* The three cards render. */
        $summary = View::capture('admin/reports/_royalty', [
            'royalty'       => $page['royalty'],
            'royalty_ratio' => $page['royalty_ratio'],
            'range'         => $range,
        ]);

        assert_contains('Royalty bonus', $summary);
        assert_contains('data-live-region="report-royalty"', $summary);
        assert_contains(money(637500.0), $summary);
        assert_contains('Royalty paid ÷ paid sales', $summary);
        assert_contains('63.8%', $summary);
        assert_contains('Pending liability', $summary);
        assert_contains(money(18000.0), $summary);
        assert_contains('admin/commissions?ledger=royalty', $summary);

        $rankCard = View::capture('admin/reports/_royalty_ranks', [
            'royalty_ranks' => $page['royalty_ranks'],
            'range'         => $range,
        ]);

        assert_contains('data-live-region="report-royalty-ranks"', $rankCard);
        assert_contains('Director', $rankCard);
        assert_contains('Manager', $rankCard);
        assert_contains('Supervisor', $rankCard);
        assert_contains(money(515000.0), $rankCard);
        assert_contains(money(122500.0), $rankCard);
        assert_contains(money(18000.0), $rankCard, 'the pending Supervisor money appears');
        assert_contains('Window', $rankCard);

        $periodCard = View::capture('admin/reports/_royalty_periods', [
            'royalty_periods' => $page['royalty_periods'],
        ]);

        assert_contains('data-live-region="report-royalty-periods"', $periodCard);
        assert_contains(date('M Y', (int) strtotime($period . '-01 12:00:00')), $periodCard);
        assert_contains(money(637500.0), $periodCard);

        /* The cards are on the page, in their own rows. */
        ob_start();

        try {
            View::page('admin', 'admin/reports/index', $page + [
                'page_title'  => 'Reports',
                'current_nav' => 'admin.reports',
                'page_css'    => [],
                'page_js'     => [],
                'live'        => Live::meta('admin.reports', 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-region="report-royalty"', $html);
        assert_contains('data-live-region="report-royalty-ranks"', $html);
        assert_contains('data-live-region="report-royalty-periods"', $html);
    },

    /* ==========================================================
     *  P19D-T13 — the report CSV
     * ========================================================== */

    'P19D-T13 the royalty report export downloads the period and rank rows' => function () use ($member, $seed, $period, $prior, $range): void {
        $director   = $member('r19d_rep13_director');
        $manager    = $member('r19d_rep13_manager');
        $supervisor = $member('r19d_rep13_supervisor');

        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'ps' => 100000, 'status' => 'paid'],
            ['user' => $manager, 'rank' => RoyaltyRank::MANAGER, 'gs' => 2450000, 'status' => 'paid'],
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 410000, 'status' => 'skipped', 'reason' => RoyaltyAward::REASON_QUOTA],
        ], 'paid');

        $seed($prior, [
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'amount' => 18000.00, 'status' => 'pending'],
        ], 'evaluated');

        test_signed_in_as(test_user('r19d_rep13_admin', 'admin'));

        assert_true(isset(ReportService::EXPORTS['royalty']), 'the royalty export is offered');
        assert_same('Royalty', ReportService::EXPORTS['royalty']);

        $export = (new ReportService())->export('royalty', $range);

        assert_same('ecowealth_royalty_' . $range['from'] . '_' . $range['to'] . '.csv', $export['filename']);
        assert_same(
            [
                'Scope', 'Period / rank', 'Status', 'Recipients', 'Paid',
                'Pending', 'Skipped', 'Award total', 'Average award',
            ],
            $export['header']
        );

        foreach ($export['rows'] as $row) {
            assert_count(count($export['header']), $row, 'every export row matches the header width');
        }

        $periodRows = [];
        $rankRows   = [];

        foreach ($export['rows'] as $row) {
            if ($row[0] === 'Period') {
                $periodRows[$row[1]] = $row;
            } elseif ($row[0] === 'Rank') {
                $rankRows[$row[1]] = $row;
            }
        }

        assert_count(2, $periodRows, 'both closed months export');
        assert_true(isset($periodRows[$period]), 'the paid month is a Period row');
        assert_same('paid', $periodRows[$period][2]);
        assert_same('637500.00', $periodRows[$period][4]);
        assert_same('1', $periodRows[$period][6]);
        assert_same('637500.00', $periodRows[$period][7]);

        assert_true(isset($periodRows[$prior]), 'the evaluated month is a Period row');
        assert_same('evaluated', $periodRows[$prior][2]);
        assert_same('18000.00', $periodRows[$prior][5]);

        assert_count(3, $rankRows, 'every paying rank exports');
        assert_same('515000.00', $rankRows['Director'][4]);
        assert_same('122500.00', $rankRows['Manager'][4]);
        assert_same('18000.00', $rankRows['Supervisor'][5], 'the pending Supervisor money is in the rank block');

        /* The two blocks reconcile with the cards. */
        assert_same(
            (new ReportService())->page($range)['royalty']['paid'],
            round(array_sum(array_map(
                static fn (array $row): float => (float) $row[4],
                $rankRows
            )), 2)
        );
    },

    /* ==========================================================
     *  P19D-T15 — the dashboard KPI
     * ========================================================== */

    'P19D-T15 the dashboard Royalty tile is all-time plus this month, never the preview' => function () use ($member, $seed, $period, $prior): void {
        $director   = $member('r19d_rep15_director');
        $supervisor = $member('r19d_rep15_supervisor');

        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'amount' => 515000.00, 'status' => 'paid'],
        ], 'paid');

        $seed($prior, [
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 600000, 'amount' => 18000.00, 'status' => 'pending'],
        ], 'evaluated');

        test_signed_in_as(test_user('r19d_rep15_admin', 'admin'));

        $dashboard = (new ReportService())->dashboard();

        assert_true(isset($dashboard['royalty']), 'the dashboard carries the Royalty KPI');
        assert_same(515000.0, (float) $dashboard['royalty']['paid'], 'all-time paid');
        assert_same(18000.0, (float) $dashboard['royalty']['pending'], 'all-time pending liability');
        assert_same(2, (int) $dashboard['royalty']['periods']);
        assert_same(515000.0, (float) $dashboard['royalty']['this_month'], 'the paid month was credited this month');

        /* The all-time figure matches the ledger's header arithmetic. */
        $allTime = RoyaltyAward::totalsForPeriod($period);
        $priorTotals = RoyaltyAward::totalsForPeriod($prior);

        assert_same(
            round($allTime['paid_total'] + $priorTotals['paid_total'], 2),
            (float) $dashboard['royalty']['paid']
        );

        /* The tile renders. */
        $tile = View::capture('admin/dashboard/_commerce', ['commerce' => $dashboard]);

        assert_contains('Royalty paid', $tile);
        assert_contains(money(515000.0), $tile);
        assert_contains('this month', $tile);
        assert_contains(money(18000.0), $tile, 'the pending liability is on the tile');
        assert_contains('admin/commissions?ledger=royalty', $tile);

        /* The open month's projection is not on the tile. */
        $preview = (new RoyaltyService())->preview(RoyaltyPeriod::periodOf());
        assert_not_contains('If this month closed now', $tile);
        assert_true($preview['period'] === RoyaltyPeriod::periodOf());
    },
];
