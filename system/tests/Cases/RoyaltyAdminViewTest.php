<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyAdminViewTest — Phase 19D (plan §8)
 *
 *  Proves the three admin surfaces 19D adds, and only reads:
 *
 *      ledger      the third tab on /admin/commissions: header,
 *                  counters, closed-month cards, filters, rows,
 *                  pagination and the labelled open-month preview
 *      CSV         the Appendix C.5 columns, the screen's filters and
 *                  the single `royalty.export` audit row
 *      regression  the package and repeat tabs keep their bytes
 *      read-only   no admin surface writes a period, an award or a
 *                  wallet row (P19D-T17)
 *      guard       a member cannot reach the ledger or its export
 *                  (P19D-T18)
 *
 *  Every fixture is built directly against the Phase 19C tables: this
 *  file tests the visibility, not the engine (RoyaltyEngineTest owns
 *  the money). Rows are inserted with `RoyaltyAward::create()` and
 *  `markPaid()`, so the ledger sees the same shapes the close writes.
 *
 *  Run this group alone with:  php tests/run.php RoyaltyAdminView
 * ============================================================== */

/* The fixed completed months every case uses (no wall-clock dates). */
$period = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());
$prior  = RoyaltyPeriod::previous($period);
$open   = RoyaltyPeriod::periodOf();

/* Keep the settings consistent with the fixture ladder (the preview reads them). */
$configure = static function (): void {
    foreach ([
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
        'royalty_ledger_since'            => '2020-01-01 00:00:00',
    ] as $key => $value) {
        setting_set($key, (string) $value);
    }

    setting_cache_clear();
};

/** One member account with a predictable username. */
$member = static function (string $username, string $fullName = ''): array {
    $user = test_user($username);

    if ($fullName !== '') {
        db()->prepare('UPDATE users SET full_name = ? WHERE id = ?')
            ->execute([$fullName, (int) $user['id']]);
        $user['full_name'] = $fullName;
    }

    return $user;
};

/**
 * Insert one period header and its award rows.
 *
 * The header's counts and totals are derived from the drafts, so the
 * period card and the header row always agree (P19D-T4). A `paid` row
 * is created as pending and then marked paid, the only update the
 * ledger ever applies, so `credited_at` is a real timestamp.
 *
 * @param list<array{
 *   user:array<string, mixed>, rank:int, gs?:float, ps?:float,
 *   amount?:float, status?:string, reason?:string, quota?:float,
 *   group_pct?:float, personal_pct?:float
 * }> $drafts
 * @return array{header:array<string, mixed>, awards:list<array<string, mixed>>}
 */
$seed = static function (string $period, array $drafts, string $status = 'evaluated', ?string $note = null): array {
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

        $rank        = (int) $draft['rank'];
        $groupPct    = (float) ($draft['group_pct'] ?? $groups[$rank] ?? 0.0);
        $personalPct = (float) ($draft['personal_pct'] ?? $personals[$rank] ?? 0.0);
        $amount      = isset($draft['amount'])
            ? (float) $draft['amount']
            : round((float) ($draft['gs'] ?? 0) * $groupPct / 100 + (float) ($draft['ps'] ?? 0) * $personalPct / 100, 2);

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
         VALUES (?, ?, ?, ?, NULL, ?, NULL, ?, 2, 2, 2, 2,
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
        $groupPct    = (float) ($draft['group_pct'] ?? $groups[$rank] ?? 0.0);
        $personalPct = (float) ($draft['personal_pct'] ?? $personals[$rank] ?? 0.0);
        $amount      = isset($draft['amount'])
            ? (float) $draft['amount']
            : round((float) ($draft['gs'] ?? 0) * $groupPct / 100 + (float) ($draft['ps'] ?? 0) * $personalPct / 100, 2);

        $awardId = RoyaltyAward::create([
            'period'         => $period,
            'user_id'        => (int) $draft['user']['id'],
            'rank_level'     => $rank,
            'directs'        => 2,
            'downline_rank_count' => 2,
            'team_size'      => 6,
            'group_volume'   => $draft['gs'] ?? 0.0,
            'personal_volume' => $draft['ps'] ?? 0.0,
            'quota'          => $draft['quota'] ?? $quotas[$rank] ?? 0.0,
            'group_pct'      => $groupPct,
            'personal_pct'   => $personalPct,
            'amount'         => $draftStatus === 'skipped' ? 0.0 : $amount,
            'status'         => $draftStatus === 'skipped' ? 'skipped' : 'pending',
            'reason'         => $draftStatus === 'skipped' ? (string) ($draft['reason'] ?? RoyaltyAward::REASON_QUOTA) : null,
        ]);

        if ($draftStatus === 'paid') {
            RoyaltyAward::markPaid($awardId, 100000 + $awardId);
        }
    }

    return [
        'header' => (array) RoyaltyPeriod::find($period),
        'awards' => RoyaltyAward::rowsForPeriod($period),
    ];
};

/** Render a page through the admin layout. */
$renderPage = static function (string $template, array $data, string $title, string $nav): string {
    ob_start();

    try {
        View::page('admin', $template, $data + [
            'page_title'  => $title,
            'current_nav' => $nav,
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
            'live'        => Live::meta('admin.commissions', 'page'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/** The `<tr>` markup of one period card row. */
$periodRowFor = static function (string $html, string $period): string {
    $pattern = '/<tr[^>]*>(?:(?!<\/tr>).)*?period=' . preg_quote($period, '/') . '.*?<\/tr>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[0] : '';
};

return [
    /* ==========================================================
     *  P19D-T1 — the Royalty tab renders
     * ========================================================== */

    'P19D-T1 the Royalty tab renders the pill, header, stats, cards and rows' => function () use ($configure, $member, $seed, $period, $prior, $renderPage): void {
        $configure();

        $director    = $member('r19d_t1_director', 'Dana Director');
        $manager     = $member('r19d_t1_manager', 'Milo Manager');
        $supervisor  = $member('r19d_t1_supervisor', 'Sofia Supervisor');

        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'ps' => 100000, 'status' => 'paid'],
            ['user' => $manager, 'rank' => RoyaltyRank::MANAGER, 'gs' => 2450000, 'status' => 'paid'],
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 1225000, 'ps' => 1225000, 'status' => 'paid'],
        ], 'paid');

        $seed($prior, [
            ['user' => $manager, 'rank' => RoyaltyRank::MANAGER, 'gs' => 2000000, 'status' => 'pending'],
        ]);

        test_signed_in_as(test_user('r19d_t1_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&per_page=25';

        $controller = new AdminCommissionController();
        $data       = $controller->royaltyContext();

        assert_same(AdminCommissionController::TAB_ROYALTY, $data['tab']);
        assert_same($period, $data['filters']['period'], 'the default period is the latest final month');
        assert_same(3, $data['pager']->total);

        $html = $renderPage('admin/commissions/index', $data, 'Commissions', 'admin.commissions');

        /* The third pill, and it is the active one. */
        assert_contains('href="' . url('admin/commissions?ledger=royalty') . '"', $html);
        assert_same(
            1,
            preg_match('/href="[^"]*ledger=royalty"[^>]*class="nav-link([^"]*)"/s', $html, $pill),
            'the Royalty pill renders'
        );
        assert_contains('active', $pill[1], 'the Royalty pill is active');

        /* The page title and the plan’s copy. */
        assert_contains('<h4 class="mb-1">Royalty bonus</h4>', $html);
        assert_contains('Never per order.', $html);

        /* All five regions. */
        foreach ([
            'commission-royalty-header',
            'commission-royalty-stats',
            'commission-royalty-periods',
            'commission-royalty-rows',
            'commission-royalty-pagination',
        ] as $region) {
            assert_contains('data-live-region="' . $region . '"', $html, $region . ' renders');
        }

        assert_contains('Royalty ledger', $html);
        assert_contains('One award per member per closed month.', $html);
        assert_contains('If this month closed now', $html, 'the preview is labelled');

        /* The three stored rows and their ranks. */
        foreach ([$director, $manager, $supervisor] as $user) {
            assert_contains('@' . (string) $user['username'], $html);
        }

        foreach (['Director', 'Manager', 'Supervisor'] as $rank) {
            assert_contains($rank, $html);
        }

        assert_contains(money(500000.0), $html, 'the Director award renders');
        assert_contains(money(122500.0), $html, 'the Manager award renders');
        assert_contains(money(98000.0), $html, 'the Supervisor award renders');

        /* The feed carries the matching fragment set. */
        $payload = $controller->feedPayload(4, '"admin.commissions:4:t1"', $data);

        assert_same(
            [
                'commission-royalty-header',
                'commission-royalty-stats',
                'commission-royalty-periods',
                'commission-royalty-rows',
                'commission-royalty-pagination',
            ],
            array_keys($payload['fragments'])
        );
        assert_contains('data-live-region="commission-royalty-rows"', $payload['fragments']['commission-royalty-rows']);
        assert_contains('@' . (string) $director['username'], $payload['fragments']['commission-royalty-rows']);
        assert_not_contains('commission-filters', $payload['fragments']['commission-royalty-rows']);
    },

    /* ==========================================================
     *  P19D-T1 (preview) — the open month is labelled and excluded
     * ========================================================== */

    'P19D-T1 the open month renders as a labelled preview and enters no total' => function () use ($configure, $member, $seed, $period, $open, $renderPage): void {
        $configure();

        $director = $member('r19d_t1p_director');
        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'status' => 'paid'],
        ], 'paid');

        test_signed_in_as(test_user('r19d_t1p_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'period' => 'current'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=current';

        $controller = new AdminCommissionController();
        $data       = $controller->royaltyContext();

        assert_same($open, $data['filters']['period'], 'period=current selects the open month');
        assert_true($data['preview_mode']);
        assert_same(0, $data['pager']->total, 'an open month has no ledger rows');
        assert_same([], $data['awards']);
        assert_same(0.0, (float) $data['stats']['paid_total'], 'the preview enters no period total');
        assert_not_null($data['preview'], 'the preview is computed');

        $html = $renderPage('admin/commissions/index', $data, 'Commissions', 'admin.commissions');

        assert_contains('If this month closed now', $html);
        assert_contains('read-only preview', $html);
        assert_contains('data-live-region="commission-royalty-stats"', $html);
        assert_contains('The month is evaluated and paid by the monthly close job', $html);

        /* The closed month's money never leaks into the preview card. */
        $stats = $controller->feedPayload(5, '"x"', $data)['fragments']['commission-royalty-stats'];

        assert_contains('If this month closed now', $stats);
        assert_not_contains(money(500000.0), $stats, 'a closed month total is not a preview number');

        /* The rows region says the month is not closed, it does not
         * invent rows. */
        $rows = $controller->feedPayload(6, '"x"', $data)['fragments']['commission-royalty-rows'];

        assert_contains('This month is not closed yet', $rows);
        assert_contains('Nothing is stored until the monthly', $rows);
    },

    /* ==========================================================
     *  P19D-T2 — the package tab is unchanged
     * ========================================================== */

    'P19D-T2 the package tab keeps its four regions, filters, columns, drawer and export' => function () use ($renderPage): void {
        test_signed_in_as(test_user('r19d_t2_admin', 'admin'));

        $_GET = ['status' => 'credited', 'per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?status=credited&per_page=25';

        $controller = new AdminCommissionController();
        $data       = $controller->listContext();
        $payload    = $controller->feedPayload(2, '"admin.commissions:2:t2"', $data);

        assert_same(AdminCommissionController::TAB_PACKAGE, $data['tab']);
        assert_same(
            ['commission-header', 'commission-stats', 'commission-rows', 'commission-pagination'],
            array_keys($payload['fragments'])
        );

        /* The fragments are byte-identical to the partials the tab has
         * always rendered — the Royalty branch does not touch them. */
        assert_same(
            View::capture('admin/commissions/_header', ['pager' => $data['pager']]),
            $payload['fragments']['commission-header']
        );
        assert_same(
            View::capture('admin/commissions/_stats', ['stats' => $data['stats']]),
            $payload['fragments']['commission-stats']
        );
        assert_same(
            View::capture('admin/commissions/_rows', [
                'commissions' => $data['commissions'],
                'filters'     => $data['filters'],
            ]),
            $payload['fragments']['commission-rows']
        );
        assert_same(
            View::capture('admin/commissions/_pagination', ['pager' => $data['pager']]),
            $payload['fragments']['commission-pagination']
        );

        /* The export URL is the package one, with the package filters. */
        assert_same(url('admin/commissions/export?status=credited'), $data['export_url']);

        $html = $renderPage('admin/commissions/index', $data, 'Commissions', 'admin.commissions');

        foreach (['Recipient', 'Source', 'Type', 'Activation', 'Awarded'] as $column) {
            assert_contains($column, $html, 'the package table keeps its ' . $column . ' column');
        }

        foreach (['name="q"', 'name="type"', 'name="level"', 'name="status"', 'name="from"', 'name="to"'] as $field) {
            assert_contains($field, $html, 'the package filter form keeps ' . $field);
        }

        assert_contains('id="commissionDetail"', $html, 'the award detail drawer is still there');
        assert_not_contains('commission-royalty-', $html, 'no Royalty region on the package tab');
        assert_not_contains('data-live-region="repeat-', $html, 'no repeat region on the package tab');
    },

    /* ==========================================================
     *  P19D-T3 — the repeat tab is unchanged
     * ========================================================== */

    'P19D-T3 the repeat tab keeps its five regions and escrow states' => function () use ($renderPage): void {
        test_signed_in_as(test_user('r19d_t3_admin', 'admin'));

        $_GET = ['ledger' => 'repeat'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=repeat';

        $controller = new AdminCommissionController();
        $data       = $controller->repeatContext();
        $payload    = $controller->feedPayload(3, '"admin.commissions:3:t3"', $data);

        assert_same(AdminCommissionController::TAB_REPEAT, $data['tab']);
        assert_same(
            ['repeat-header', 'repeat-stats', 'repeat-periods', 'repeat-rows', 'repeat-pagination'],
            array_keys($payload['fragments'])
        );

        assert_same(
            View::capture('admin/commissions/_repeat_rows', [
                'awards'  => $data['awards'],
                'filters' => $data['filters'],
            ]),
            $payload['fragments']['repeat-rows']
        );

        /* The escrow vocabulary and the CSV shape survive. */
        foreach (['Pending release', 'Cancelled before release', 'Unrecovered'] as $label) {
            assert_contains($label, $payload['fragments']['repeat-stats'], $label . ' remains a repeat counter');
        }

        assert_same(
            [
                'Award', 'Date', 'Order', 'Buyer', 'Recipient', 'Product', 'Level',
                'Base', 'Rate %', 'Amount', 'Status', 'Reason', 'Recovered',
                'Hold days', 'Released at',
            ],
            UnilevelAward::csvHeader()
        );

        $html = $renderPage('admin/commissions/index', $data, 'Commissions', 'admin.commissions');

        foreach ([
            'repeat-header', 'repeat-stats', 'repeat-periods', 'repeat-rows', 'repeat-pagination',
        ] as $region) {
            assert_contains('data-live-region="' . $region . '"', $html);
        }

        assert_not_contains('commission-royalty-', $html);
    },

    /* ==========================================================
     *  P19D-T4 — the period cards match the header rows
     * ========================================================== */

    'P19D-T4 the period cards carry the stored status, counts and totals' => function () use ($configure, $member, $seed, $period, $prior, $renderPage, $periodRowFor): void {
        $configure();

        $director   = $member('r19d_t4_director');
        $supervisor = $member('r19d_t4_supervisor');

        /* The older month is final; the newer one is still evaluated. */
        $final = $seed($prior, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'status' => 'paid'],
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 1225000, 'ps' => 1225000, 'amount' => 98000.00, 'status' => 'skipped', 'reason' => RoyaltyAward::REASON_QUOTA],
        ], 'paid');

        $evaluated = $seed($period, [
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 600000, 'amount' => 18000.00, 'status' => 'pending'],
        ], 'evaluated');

        test_signed_in_as(test_user('r19d_t4_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'period' => $prior];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $prior;

        $data = (new AdminCommissionController())->royaltyContext();
        $html = $renderPage('admin/commissions/index', $data, 'Commissions', 'admin.commissions');

        $finalHeader = $final['header'];
        $row         = $periodRowFor($html, $prior);

        assert_true($row !== '', 'the final month card renders');
        assert_contains('Paid', $row);
        assert_contains('>' . (int) $finalHeader['award_count'] . '<', $row);
        assert_contains('>' . (int) $finalHeader['paid_count'] . '<', $row);
        assert_contains('>' . (int) $finalHeader['skipped_count'] . '<', $row);
        assert_contains(money((float) $finalHeader['award_total']), $row);
        assert_contains(money((float) $finalHeader['paid_total']), $row);
        assert_contains('period=' . $prior, $row, 'the card links back into the ledger');

        /* The card’s totals agree with the awards table to the centavo. */
        $totals = RoyaltyAward::totalsForPeriod($prior);
        assert_same((int) $finalHeader['award_count'], $totals['rows']);
        assert_same((int) $finalHeader['paid_count'], $totals['paid_rows']);
        assert_same((int) $finalHeader['skipped_count'], $totals['skipped_rows']);
        assert_same(round((float) $finalHeader['award_total'], 2), $totals['award_total']);
        assert_same(round((float) $finalHeader['paid_total'], 2), $totals['paid_total']);

        /* The evaluated month says so, and its row carries its own counts. */
        $evaluatedRow = $periodRowFor($html, $period);
        assert_true($evaluatedRow !== '');
        assert_contains('Evaluated', $evaluatedRow);
        assert_contains('>' . (int) $evaluated['header']['award_count'] . '<', $evaluatedRow);
        assert_contains(money((float) $evaluated['header']['award_total']), $evaluatedRow);

        /* The default period is a final month even when a newer one is
         * still evaluated (plan §8 Rule 3). */
        $_GET = ['ledger' => 'royalty'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty';

        $default = (new AdminCommissionController())->royaltyContext();

        assert_same($prior, $default['filters']['period'], 'the latest final month is the default');
        assert_not_same($period, $default['filters']['period']);
    },

    /* ==========================================================
     *  P19D-T5 — the counters match the rows
     * ========================================================== */

    'P19D-T5 the stats read paid, pending, skipped, recipients and average from the rows' => function () use ($configure, $member, $seed, $period, $renderPage): void {
        $configure();

        $director    = $member('r19d_t5_director');
        $manager     = $member('r19d_t5_manager');
        $supervisor  = $member('r19d_t5_supervisor');

        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'ps' => 100000, 'status' => 'paid'],
            ['user' => $manager, 'rank' => RoyaltyRank::MANAGER, 'gs' => 2450000, 'status' => 'paid'],
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 1225000, 'ps' => 1225000, 'status' => 'paid'],
        ], 'paid');

        test_signed_in_as(test_user('r19d_t5_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'period' => $period];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period;

        $data  = (new AdminCommissionController())->royaltyContext();
        $stats = $data['stats'];

        $paid    = 0.0;
        $pending = 0.0;
        $skipped = 0;
        $earning = 0;

        foreach (RoyaltyAward::rowsForPeriod($period) as $award) {
            $status = (string) $award['status'];

            if ($status === 'paid') {
                $paid += (float) $award['amount'];
                $earning++;
            } elseif ($status === 'pending') {
                $pending += (float) $award['amount'];
                $earning++;
            } else {
                $skipped++;
            }
        }

        $paid    = round($paid, 2);
        $pending = round($pending, 2);

        assert_same($paid, $stats['paid_total']);
        assert_same($pending, $stats['pending_total']);
        assert_same(round($paid + $pending, 2), $stats['award_total']);
        assert_same($skipped, $stats['skipped_rows']);
        assert_same($earning, $stats['recipients']);
        assert_same($earning === 0 ? 0.0 : round(($paid + $pending) / $earning, 2), $stats['average']);
        assert_same('Director', $stats['top_rank'], 'the highest rank with a row');

        $html = $renderPage('admin/commissions/index', $data, 'Commissions', 'admin.commissions');

        foreach (['Paid in this period', 'Awaiting payment', 'This period', 'Recipients', 'Skipped', 'Average award', 'Top rank', 'Paid all time'] as $label) {
            assert_contains($label, $html, $label . ' card renders');
        }

        assert_contains(money(735500.0), $html, 'the paid total renders');
        assert_contains(money(735500.0), $html, 'the period total renders');
        assert_contains(money(245166.67), $html, 'the average award renders');
    },

    /* ==========================================================
     *  P19D-T6 — the rank filter
     * ========================================================== */

    'P19D-T6 the rank filter keeps only the selected rank rows' => function () use ($configure, $member, $seed, $period): void {
        $configure();

        $director   = $member('r19d_t6_director');
        $manager    = $member('r19d_t6_manager');
        $supervisor = $member('r19d_t6_supervisor');

        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'status' => 'paid'],
            ['user' => $manager, 'rank' => RoyaltyRank::MANAGER, 'gs' => 2450000, 'status' => 'paid'],
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 1225000, 'status' => 'paid'],
        ], 'paid');

        test_signed_in_as(test_user('r19d_t6_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'period' => $period, 'rank' => (string) RoyaltyRank::MANAGER];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&rank=3';

        $data = (new AdminCommissionController())->royaltyContext();

        assert_same('3', $data['filters']['rank']);
        assert_same(1, $data['pager']->total);
        assert_count(1, $data['awards']);
        assert_same((int) $manager['id'], (int) $data['awards'][0]['user_id']);

        $payload = (new AdminCommissionController())->feedPayload(5, '"x"', $data);
        $rows    = $payload['fragments']['commission-royalty-rows'];

        assert_contains('@' . (string) $manager['username'], $rows);
        assert_not_contains('@' . (string) $director['username'], $rows);
        assert_not_contains('@' . (string) $supervisor['username'], $rows);

        /* An unknown rank is ignored, not trusted. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'rank' => '99'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&rank=99';

        $unfiltered = (new AdminCommissionController())->royaltyContext();
        assert_same('', $unfiltered['filters']['rank']);
        assert_same(3, $unfiltered['pager']->total);
    },

    /* ==========================================================
     *  P19D-T7 — the status filter
     * ========================================================== */

    'P19D-T7 the status filter keeps only pending, paid or skipped rows' => function () use ($configure, $member, $seed, $period): void {
        $configure();

        $paid     = $member('r19d_t7_paid');
        $skipped  = $member('r19d_t7_skipped');
        $skipped2 = $member('r19d_t7_skipped2');

        $seed($period, [
            ['user' => $paid, 'rank' => RoyaltyRank::MANAGER, 'gs' => 2450000, 'status' => 'paid'],
            ['user' => $skipped, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 410000, 'ps' => 60000, 'status' => 'skipped', 'reason' => RoyaltyAward::REASON_INACTIVE],
            ['user' => $skipped2, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 0, 'status' => 'skipped', 'reason' => RoyaltyAward::REASON_QUOTA],
        ], 'paid');

        test_signed_in_as(test_user('r19d_t7_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'period' => $period, 'status' => 'skipped'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&status=skipped';

        $data = (new AdminCommissionController())->royaltyContext();

        assert_same(2, $data['pager']->total);
        assert_count(2, $data['awards']);

        foreach ($data['awards'] as $award) {
            assert_same('skipped', (string) $award['status']);
        }

        $rows = (new AdminCommissionController())->feedPayload(6, '"x"', $data)['fragments']['commission-royalty-rows'];

        assert_contains('Skipped', $rows);
        assert_contains(RoyaltyAward::REASON_QUOTA, $rows, 'the stored reason renders verbatim');
        assert_contains(RoyaltyAward::REASON_INACTIVE, $rows);
        assert_contains('would have been', $rows, 'the skipped row shows what it would have paid');
        assert_not_contains('@' . (string) $paid['username'], $rows);

        $_GET = ['ledger' => 'royalty', 'period' => $period, 'status' => 'paid'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&status=paid';

        $paidOnly = (new AdminCommissionController())->royaltyContext();

        assert_same(1, $paidOnly['pager']->total);
        assert_same('paid', (string) $paidOnly['awards'][0]['status']);

        /* An unknown status is ignored. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'status' => 'bogus'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&status=bogus';

        assert_same(3, (new AdminCommissionController())->royaltyContext()['pager']->total);
    },

    /* ==========================================================
     *  P19D-T8 — search
     * ========================================================== */

    'P19D-T8 search matches username or full name and leaks no other member' => function () use ($configure, $member, $seed, $period): void {
        $configure();

        $target = $member('r19d_t8_target', 'Zelda Searchtarget');
        $other  = $member('r19d_t8_other', 'Other Member');

        $seed($period, [
            ['user' => $target, 'rank' => RoyaltyRank::MANAGER, 'gs' => 2450000, 'status' => 'paid'],
            ['user' => $other, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 1225000, 'status' => 'paid'],
        ], 'paid');

        test_signed_in_as(test_user('r19d_t8_admin', 'admin'));

        /* Username fragment. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'q' => 't8_target'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&q=t8_target';

        $byUsername = (new AdminCommissionController())->royaltyContext();

        assert_same(1, $byUsername['pager']->total);
        assert_same((int) $target['id'], (int) $byUsername['awards'][0]['user_id']);

        $rows = (new AdminCommissionController())->feedPayload(7, '"x"', $byUsername)['fragments']['commission-royalty-rows'];
        assert_contains('@' . (string) $target['username'], $rows);
        assert_not_contains('@' . (string) $other['username'], $rows);

        /* Full-name fragment. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'q' => 'Searchtarget'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&q=Searchtarget';

        $byName = (new AdminCommissionController())->royaltyContext();

        assert_same(1, $byName['pager']->total);
        assert_same((int) $target['id'], (int) $byName['awards'][0]['user_id']);

        /* A query that matches nobody is empty, not everyone. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'q' => 'no-such-member-anywhere'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&q=nobody';

        $empty = (new AdminCommissionController())->royaltyContext();

        assert_same(0, $empty['pager']->total);
        assert_same([], $empty['awards']);

        $emptyRows = (new AdminCommissionController())->feedPayload(8, '"x"', $empty)['fragments']['commission-royalty-rows'];
        assert_contains('No Royalty awards match these filters', $emptyRows);
    },

    /* ==========================================================
     *  P19D-T9 — pagination
     * ========================================================== */

    'P19D-T9 the ledger pages 10/25/50/100 and clamps an out-of-range page' => function () use ($configure, $member, $seed, $period): void {
        $configure();

        $drafts = [];

        for ($i = 1; $i <= 12; $i++) {
            $user     = $member('r19d_t9_member_' . $i);
            $drafts[] = [
                'user'   => $user,
                'rank'   => $i % 2 === 0 ? RoyaltyRank::MANAGER : RoyaltyRank::SUPERVISOR,
                'gs'     => 2000000,
                'amount' => 100000.00 + $i,
                'status' => 'paid',
            ];
        }

        $seed($period, $drafts, 'paid');

        test_signed_in_as(test_user('r19d_t9_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'period' => $period, 'per_page' => '10', 'page' => '2'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&per_page=10&page=2';

        $controller = new AdminCommissionController();
        $second     = $controller->royaltyContext();

        assert_same(10, $second['pager']->perPage);
        assert_same(2, $second['pager']->page);
        assert_same(2, $second['pager']->pages);
        assert_count(2, $second['awards']);

        $payload = $controller->feedPayload(9, '"x"', $second);
        assert_contains('data-live-region="commission-royalty-pagination"', $payload['fragments']['commission-royalty-pagination']);

        /* The offered sizes are the app’s sizes. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'per_page' => '25'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&per_page=25';

        $twentyFive = $controller->royaltyContext();
        assert_same(25, $twentyFive['pager']->perPage);
        assert_count(12, $twentyFive['awards']);

        /* A size that is not offered falls back to the default. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'per_page' => '13'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&per_page=13';

        assert_same(10, $controller->royaltyContext()['pager']->perPage);

        /* An out-of-range page clamps to the last real page. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'per_page' => '10', 'page' => '99'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&per_page=10&page=99';

        $clamped = $controller->royaltyContext();

        assert_same(2, $clamped['pager']->page);
        assert_count(2, $clamped['awards']);

        $canonical = $controller->feedPayload(10, '"x"', $clamped)['meta']['canonical'];
        assert_contains('page=2', $canonical);
        assert_not_contains('page=99', $canonical);
    },

    /* ==========================================================
     *  P19D-T10 — the ledger CSV
     * ========================================================== */

    'P19D-T10 the ledger CSV has the Appendix C.5 columns and the screen totals' => function () use ($configure, $member, $seed, $period): void {
        $configure();

        $director   = $member('r19d_t10_director', 'Dana Director');
        $supervisor = $member('r19d_t10_supervisor', 'Sofia Supervisor');

        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'ps' => 100000, 'status' => 'paid'],
            ['user' => $supervisor, 'rank' => RoyaltyRank::SUPERVISOR, 'gs' => 410000, 'ps' => 60000, 'status' => 'skipped', 'reason' => RoyaltyAward::REASON_QUOTA],
        ], 'paid');

        test_signed_in_as(test_user('r19d_t10_admin', 'admin'));

        $_GET = ['ledger' => 'royalty', 'period' => $period];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period;

        $payload = (new AdminCommissionController())->royaltyExportPayload();

        assert_same($period, $payload['filters']['period']);
        assert_count(2, $payload['rows']);

        $header = AdminCommissionController::royaltyCsvHeader();

        assert_same(
            [
                'Period', 'Member', 'Full name', 'Rank', 'Group sales', 'Personal sales',
                'Quota', 'Group %', 'Personal %', 'Amount', 'Status', 'Reason',
                'Evaluated at', 'Paid at', 'Ledger id',
            ],
            $header,
            'the CSV header is plan Appendix C.5 exactly'
        );

        $csv = AdminCommissionController::royaltyCsvRows($payload['rows']);

        assert_count(2, $csv);

        foreach ($csv as $row) {
            assert_count(count($header), $row, 'every row matches the header width');
        }

        $byMember = [];

        foreach ($csv as $row) {
            $byMember[$row[1]] = $row;
        }

        $directorRow = $byMember[(string) $director['username']];
        assert_same($period, $directorRow[0]);
        assert_same('Dana Director', $directorRow[2]);
        assert_same('Director', $directorRow[3]);
        assert_same('5000000.00', $directorRow[4]);
        assert_same('100000.00', $directorRow[5]);
        assert_same('5000000.00', $directorRow[6]);
        assert_same('10.000', $directorRow[7]);
        assert_same('15.000', $directorRow[8]);
        assert_same('515000.00', $directorRow[9]);
        assert_same('paid', $directorRow[10]);
        assert_same('', $directorRow[11]);
        assert_true($directorRow[12] !== '', 'the evaluated time is stamped');
        assert_true($directorRow[13] !== '', 'the paid time is stamped');
        assert_true($directorRow[14] !== '', 'the ledger id is present');

        $skippedRow = $byMember[(string) $supervisor['username']];
        assert_same('skipped', $skippedRow[10]);
        assert_same(RoyaltyAward::REASON_QUOTA, $skippedRow[11]);
        assert_same('0.00', $skippedRow[9]);
        assert_same('', $skippedRow[14], 'a skipped row has no ledger id');

        /* The CSV total agrees with the screen’s header to the centavo. */
        $csvPaid = 0.0;

        foreach ($csv as $row) {
            if ($row[10] === 'paid') {
                $csvPaid += (float) $row[9];
            }
        }

        assert_same(
            RoyaltyAward::totalsForPeriod($period)['paid_total'],
            round($csvPaid, 2)
        );

        /* The export reuses the screen’s other filters too. */
        $_GET = ['ledger' => 'royalty', 'period' => $period, 'status' => 'skipped'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&status=skipped';

        $filtered = (new AdminCommissionController())->royaltyExportPayload();

        assert_count(1, $filtered['rows']);
        assert_same('skipped', (string) $filtered['rows'][0]['status']);
    },

    /* ==========================================================
     *  P19D-T11 — the export audit
     * ========================================================== */

    'P19D-T11 the export writes one royalty.export audit row and no money' => function () use ($configure, $member, $seed, $period): void {
        $configure();

        $director = $member('r19d_t11_director');

        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'status' => 'paid'],
        ], 'paid');

        $admin = test_user('r19d_t11_admin', 'admin');
        test_signed_in_as($admin);

        $periodsBefore = (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn();
        $awardsBefore  = (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn();
        $ledgerBefore  = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();
        $walletBefore  = (string) db()->query(
            "SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users"
        )->fetchColumn();
        $auditBefore   = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        $_GET = ['ledger' => 'royalty', 'period' => $period, 'status' => 'paid'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period . '&status=paid';

        (new AdminCommissionController())->royaltyExportPayload();

        $stmt = db()->prepare('SELECT * FROM audit_log WHERE id > ? ORDER BY id');
        $stmt->execute([$auditBefore]);
        $audits = $stmt->fetchAll();

        assert_count(1, $audits, 'exactly one audit row');
        assert_same('royalty.export', (string) $audits[0]['action']);
        assert_same((int) $admin['id'], (int) $audits[0]['actor_id']);
        assert_same('admin', (string) $audits[0]['actor_role']);
        assert_same('royalty_award', (string) $audits[0]['subject_type']);

        $meta = AuditLog::meta((string) $audits[0]['meta_json']);
        assert_true(is_array($meta) && $meta !== [], 'the meta is JSON');
        assert_same('royalty', (string) $meta['tab']);
        assert_same($period, (string) $meta['filters']['period']);
        assert_same('paid', (string) $meta['filters']['status']);
        assert_same(1, (int) $meta['rows']);

        /* The export is read-only on every money table. */
        assert_same($periodsBefore, (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same($awardsBefore, (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
        assert_same($ledgerBefore, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());
        assert_same($walletBefore, (string) db()->query(
            "SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users"
        )->fetchColumn());
    },

    /* ==========================================================
     *  P19D-T17 — no write path
     * ========================================================== */

    'P19D-T17 no admin GET renders a write and no 19D surface moves money' => function () use ($configure, $member, $seed, $period): void {
        $configure();

        $director = $member('r19d_t17_director');
        $seed($period, [
            ['user' => $director, 'rank' => RoyaltyRank::DIRECTOR, 'gs' => 5000000, 'status' => 'paid'],
        ], 'paid');

        test_signed_in_as(test_user('r19d_t17_admin', 'admin'));

        /* The route table: every commission/report route is a GET. */
        $routes = require dirname(__DIR__, 2) . '/app/routes.php';

        foreach ($routes as $route) {
            [$method, $path, $handler] = $route;
            $class = is_array($handler) ? (string) $handler[0] : '';

            if (in_array($class, [AdminCommissionController::class, AdminReportController::class], true)) {
                assert_same('GET', $method, $path . ' is read-only');
            }
        }

        $counts = static fn (): array => [
            (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn(),
            (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
            (string) db()->query("SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users")->fetchColumn(),
            (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn(),
        ];

        $before = $counts();

        /* Render every 19D surface. */
        $_GET = ['ledger' => 'royalty', 'period' => $period];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/commissions?ledger=royalty&period=' . $period;

        $controller = new AdminCommissionController();
        $data       = $controller->royaltyContext();
        $controller->feedPayload(11, '"x"', $data);

        ob_start();

        try {
            View::page('admin', 'admin/commissions/index', $data + [
                'page_title'  => 'Commissions',
                'current_nav' => 'admin.commissions',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta('admin.commissions', 'page'),
            ]);
        } finally {
            ob_end_clean();
        }

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/reports';

        $reports = (new ReportService())->page(ReportService::range(null, null));

        ob_start();

        try {
            View::page('admin', 'admin/reports/index', $reports + [
                'page_title'  => 'Reports',
                'current_nav' => 'admin.reports',
                'page_css'    => [],
                'page_js'     => [],
                'live'        => Live::meta('admin.reports', 'page'),
            ]);
        } finally {
            ob_end_clean();
        }

        $memberRow = Member::findByUsername((string) $director['username']);
        assert_not_null($memberRow);

        $memberController = new AdminMemberController();
        $memberContext    = $memberController->memberContext($memberRow);
        $memberController->detailFeedPayload(12, '"x"', $memberContext);

        /* The dashboard's Royalty KPI reads the same summary. */
        $dashboard = (new ReportService())->dashboard();
        assert_true(isset($dashboard['royalty']));

        assert_same($before, $counts(), 'rendering the 19D surfaces writes no period, award, wallet or audit row');
    },

    /* ==========================================================
     *  P19D-T18 — permission
     * ========================================================== */

    'P19D-T18 a member cannot reach the Royalty ledger or its export' => function (): void {
        $member = test_user('r19d_t18_member');
        test_signed_in_as($member);

        assert_same('member', (string) ($member['role'] ?? ''));
        assert_false(is_admin(), 'the admin guard refuses a member session');

        $routes = require dirname(__DIR__, 2) . '/app/routes.php';
        $live   = [];

        foreach ($routes as $route) {
            $live[$route[0] . ' ' . $route[1]] = $route[3] ?? 'member';
        }

        foreach ([
            'GET /admin/commissions',
            'GET /admin/commissions/export',
            'GET /admin/reports',
            'GET /admin/reports/export',
            'GET /admin/members/{id}',
            'GET /admin',
        ] as $path) {
            assert_same('admin', $live[$path], $path . ' keeps the admin guard');
        }
    },
];
