<?php
declare(strict_types=1);

/* ==============================================================
 *  CommissionViewTest — the earnings statement and the admin ledger
 *  (plan §7 Phase 10 tests P10-T7, T8, T9)
 *
 *  Renders the real templates through View::page() with the data the
 *  controllers build, and asserts what a browser would see: summary
 *  cards, rows, filters, the drawer and escaping.
 * ============================================================== */

$renderView = static function (string $layout, string $template, array $data = []): string {
    ob_start();

    try {
        View::page($layout, $template, $data);
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return (string) ob_get_clean();
};

/** Register a pending member under the given sponsor. */
$pendingMember = static function (string $sponsor = 'juan'): array {
    $username = 'cv_' . bin2hex(random_bytes(4));

    $result = (new RegistrationService())->register([
        'mode'             => 'pending',
        'username'         => $username,
        'email'            => $username . '@test.local',
        'full_name'        => 'View Test',
        'phone'            => '',
        'password'         => 'Member@1234',
        'password_confirm' => 'Member@1234',
        'sponsor'          => $sponsor,
        'package_id'       => '',
        'reg_code'         => '',
        'terms'            => true,
    ], null);

    $member = Member::find((int) $result['user_id']);

    if ($member === null) {
        fail('the pending member was not created');
    }

    return $member;
};

/** Activate a member with Package A (runs the commission hook). */
$activate = static function (array $member): int {
    $package = Package::findBySlug('package-a');
    assert_not_null($package);

    $activationId = Activation::create([
        'user_id'        => (int) $member['id'],
        'package_id'     => (int) $package['id'],
        'amount'         => (float) $package['price'],
        'payment_method' => 'code',
        'status'         => 'approved',
    ]);

    (new ActivationService())->complete($activationId);

    return $activationId;
};

/** Build the data EarningsController hands to the template. */
$earningsData = static function (int $userId, array $filters = []): array {
    $filters = Commission::normaliseFilters($filters);
    $pager   = Paginator::make(Commission::countForUser($userId, $filters), 1, Paginator::DEFAULT_PER_PAGE);

    return [
        'page_title'    => 'Earnings',
        'current_nav'   => 'earnings',
        'totals'        => Commission::totalsForUser($userId),
        'levels'        => Commission::levelBreakdown($userId),
        'filters'       => $filters,
        'pager'         => $pager,
        'commissions'   => Commission::pageForUser($userId, $filters, $pager),
        'wallet_url'    => url('wallet'),
        'genealogy_url' => url('genealogy'),
        'statement_url' => url('earnings'),
        'export_url'    => url('earnings/export'),
    ];
};

/** Build the data AdminCommissionController hands to the template. */
$ledgerData = static function (array $filters = [], int $page = 1): array {
    $filters = Commission::normaliseFilters($filters);
    $pager   = Paginator::make(Commission::countAdmin($filters), $page, Paginator::DEFAULT_PER_PAGE);

    return [
        'page_title'  => 'Commissions',
        'current_nav' => 'admin.commissions',
        'stats'       => Commission::stats(),
        'filters'     => $filters,
        'pager'       => $pager,
        'commissions' => Commission::adminList($filters, $pager),
        'ledger_url'  => url('admin/commissions'),
        'export_url'  => url('admin/commissions/export'),
        'wallet_url'  => url('admin/wallets'),
    ];
};

return [
    /* ---------- Member statement --------------------------------- */

    'P10-T7 the earnings page shows the summary, the award and the wallet link' => function () use ($renderView, $earningsData, $pendingMember, $activate): void {
        $member = $pendingMember('juan');
        $activate($member);

        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $html = $renderView('member', 'member/earnings', $earningsData((int) $juan['id']));

        /* Summary cards: the four questions the page answers. */
        assert_contains('Total credited', $html);
        assert_contains('This month', $html);
        assert_contains('Direct referral', $html);
        assert_contains('Indirect referral', $html);
        assert_contains('₱500.00', $html);

        /* The award row names the source member and the package. */
        assert_contains('@' . (string) $member['username'], $html);
        assert_contains('Package A', $html);
        assert_contains('Level 1 · Direct', $html);
        assert_contains('bg-label-success">Credited', $html);

        /* The page routes to the money and the team. */
        assert_contains('href="' . url('wallet') . '"', $html);
        assert_contains('href="' . url('genealogy') . '"', $html);
        assert_contains('href="' . url('earnings/export') . '"', $html, 'the unilevel CSV export is offered');
        assert_contains('Export CSV', $html);

        /* The level breakdown is always rendered, even with one level paid. */
        assert_contains('Unilevel breakdown', $html);
        assert_contains('data-live-region="earnings-levels"', $html);
        assert_contains('Level 1 · Direct', $html);
        assert_contains('Level 10', $html);

        /* Filters and the drawer are present. */
        assert_contains('name="type"', $html);
        assert_contains('name="level"', $html);
        assert_contains('name="from"', $html);
        assert_contains('data-commission="{', $html, 'the row carries its record for the drawer');
        assert_contains('id="commissionDetail"', $html);
    },

    'P10-T7 an empty earnings page explains where awards come from' => function () use ($renderView, $earningsData): void {
        $member = test_user('cv_empty_' . bin2hex(random_bytes(3)));
        test_signed_in_as($member);

        $html = $renderView('member', 'member/earnings', $earningsData((int) $member['id']));

        assert_contains('No commissions yet', $html);
        assert_contains('direct referral lands here', $html);
        assert_contains('href="' . url('wallet') . '"', $html, 'the empty state still offers the wallet');
        assert_not_contains('data-commission="{', $html, 'no rows, no drawer triggers');
    },

    'P10-T7 a skipped award shows its reason and never claims money' => function () use ($renderView, $earningsData, $pendingMember, $activate): void {
        /* david is pending with no package, so the award is skipped. */
        $member = $pendingMember('david');
        $activate($member);

        $david = Member::findByUsername('david');
        assert_not_null($david);

        test_signed_in_as($david);

        $html = $renderView('member', 'member/earnings', $earningsData((int) $david['id']));

        assert_contains('bg-label-secondary">Skipped', $html);
        assert_contains('1 award', $html);
        assert_contains('was skipped', $html);
        assert_contains('recipient membership is pending', $html);
        assert_contains('No wallet movement', $html, 'the drawer says the award never moved money');
        assert_contains('not paid', $html, 'the row marks the amount as unpaid');

        /* The stat card must not claim money for a skipped row. */
        assert_contains('commission-stat-value">' . e(money(0)) . '<', $html);
    },

    'P10-T7 the earnings filters keep their values and offer Clear' => function () use ($renderView, $earningsData, $pendingMember, $activate): void {
        $member = $pendingMember('juan');
        $activate($member);

        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $html = $renderView('member', 'member/earnings', $earningsData((int) $juan['id'], [
            'type'   => 'indirect_referral',
            'level'  => '2',
            'status' => 'credited',
            'from'   => '2026-01-01',
            'to'     => '2026-12-31',
        ]));

        assert_contains('value="indirect_referral" selected', $html);
        assert_contains('value="2" selected', $html);
        assert_contains('value="credited" selected', $html);
        assert_contains('value="2026-01-01"', $html);
        assert_contains('value="2026-12-31"', $html);
        assert_contains('me-1"></i> Clear', $html);
        assert_contains('No awards match these filters', $html, 'an indirect filter matches nothing in Phase 10');
    },

    'P10-T7 a hostile username is escaped in the table and the drawer' => function () use ($renderView, $earningsData, $pendingMember, $activate): void {
        $member = $pendingMember('juan');
        $activate($member);

        $payload = '<script>alert(1)</script>';
        db()->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$payload, (int) $member['id']]);

        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $html = $renderView('member', 'member/earnings', $earningsData((int) $juan['id']));

        assert_not_contains($payload, $html);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    },

    /* ---------- Admin ledger ------------------------------------- */

    'P10-T8 the admin ledger shows the stats, both sides of the award and the links' => function () use ($renderView, $ledgerData, $pendingMember, $activate): void {
        $member = $pendingMember('juan');
        $activationId = $activate($member);

        test_signed_in_as(test_user('cv_admin', 'admin'));

        $html = $renderView('admin', 'admin/commissions/index', $ledgerData());

        assert_contains('Total credited', $html);
        assert_contains('Awards', $html);
        assert_contains('Skipped', $html);
        assert_contains('Commission ledger', $html);

        /* Recipient and source are both named. */
        assert_contains('@juan', $html);
        assert_contains('@' . (string) $member['username'], $html);
        assert_contains('Package A', $html);

        /* The row links to the member, the activation and the drawer. */
        $juanId = (int) Member::findByUsername('juan')['id'];

        assert_contains('href="' . url('admin/members/' . $juanId) . '"', $html);
        assert_contains('admin/activations?q=', $html);
        assert_contains('#' . $activationId, $html);
        assert_contains('data-commission="{', $html);
        assert_contains('id="commissionDetail"', $html);
        assert_contains('E-wallet monitor', $html);
        assert_contains('href="' . url('admin/commissions/export') . '"', $html, 'the ledger CSV export is offered');

        /* The admin drawer includes the recipient row the member one hides. */
        assert_contains('data-detail="recipient"', $html);
    },

    'P10-T8 the admin ledger filters by type, level and status' => function () use ($renderView, $ledgerData, $pendingMember, $activate): void {
        $member = $pendingMember('david');
        $activate($member);

        test_signed_in_as(test_user('cv_admin', 'admin'));

        $html = $renderView('admin', 'admin/commissions/index', $ledgerData([
            'status' => 'skipped',
            'q'      => (string) $member['username'],
        ]));

        assert_contains('value="skipped" selected', $html);
        assert_contains('value="' . e((string) $member['username']) . '"', $html);
        assert_contains('bg-label-secondary">Skipped', $html);
        assert_contains('recipient membership is pending', $html);
    },

    'P10-T8 an empty ledger explains where awards come from' => function () use ($renderView, $ledgerData): void {
        test_signed_in_as(test_user('cv_admin', 'admin'));

        $html = $renderView('admin', 'admin/commissions/index', $ledgerData(['q' => 'nobody_has_this_name']));

        assert_contains('No commissions match these filters', $html);
        assert_contains('Clear filters', $html);
    },

    /* ---------- Dashboard + guards ------------------------------- */

    'P10-T7 the dashboard links to the earnings page' => function () use ($renderView): void {
        $member = test_user('cv_dash_' . bin2hex(random_bytes(3)));
        test_signed_in_as($member);

        $html = $renderView('member', 'member/dashboard', [
            'member'           => Member::find((int) $member['id']),
            'balance'          => 0.0,
            'withdrawable'     => 0.0,
            'orders'           => [],
            'order_stats'      => ['total' => 0, 'open' => 0, 'completed' => 0, 'spent' => 0.0],
            'commissions'      => (new CommissionService())->totalsFor((int) $member['id']),
            'team'             => ['direct' => 0, 'team' => 0, 'levels' => 0],
            'open_activation'  => null,
            'last_activation'  => null,
            'activation_count' => 0,
            'referral_code'    => (string) $member['referral_code'],
            'referral_link'    => absolute_url('register?ref=' . rawurlencode((string) $member['referral_code'])),
            'recent_history'   => [],
        ]);

        assert_contains('href="' . url('earnings') . '"', $html);
        assert_contains('see earnings', $html);
        assert_contains('No commissions yet', $html);
        assert_not_contains('arrive with the next phase', $html);
    },
];
