<?php
declare(strict_types=1);

/* ==============================================================
 *  MembershipViewTest — registration, membership, genealogy,
 *  dashboard and the back-office screens (plan §7 Phase 9)
 *
 *  Supports P9-T2, T4, T6, T10, T14–T17: the services are covered
 *  by MembershipTest; this file proves the pages render the right
 *  controls, escape user text and never mention a binary column.
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

/** Render the register form on its own (it lives in the auth layout). */
$renderRegister = static function (array $overrides = []): string {
    $data = array_merge([
        'mode'            => 'standard',
        'prefill_sponsor' => '',
        'free_enabled'    => true,
        'packages'        => PackageService::publicCards(),
        'registrar'       => null,
        'registrar_balance' => 0.0,
        'has_errors'      => false,
        'summary_error'   => '',
    ], $overrides);

    ob_start();

    try {
        (static function () use ($data): void {
            extract($data, EXTR_SKIP);
            require dirname(__DIR__, 2) . '/app/views/auth/register_form.php';
        })();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return (string) ob_get_clean();
};

$makeMember = static function (array $overrides = []): array {
    return $overrides + test_user('mv_member_' . bin2hex(random_bytes(3)), 'member');
};

$membershipData = static function (array $member) use ($makeMember): array {
    $balance = (new WalletService())->balance((int) $member['id']);
    $total   = Activation::countForUser((int) $member['id']);

    return [
        'page_title'        => 'Membership',
        'current_nav'       => 'membership',
        'member'            => $member,
        'status'            => (string) $member['member_status'],
        'has_package'       => $member['package_id'] !== null,
        'can_activate'      => in_array((string) $member['member_status'], ['pending', 'inactive'], true),
        'is_standard'       => (string) $member['member_status'] === 'active' && $member['package_id'] === null,
        'packages'          => PackageService::publicCards(),
        'balance'           => $balance,
        'withdrawable'      => (new WalletService())->withdrawable((int) $member['id']),
        'minimum_price'     => (float) Package::findBySlug('package-a')['price'],
        'open_activation'   => Activation::latestOpen((int) $member['id']),
        'history'           => Activation::forUser((int) $member['id']),
        'history_pager'     => Paginator::make($total, 1, Paginator::DEFAULT_PER_PAGE),
        'referral_link'     => absolute_url('register?ref=' . rawurlencode((string) $member['referral_code'])),
        'channels'          => MembershipController::receivingAccounts(),
        'free_registration' => setting_bool('free_registration_enabled', true),
    ];
};

return [
    /* ---------- Register form (P9-T1 … T7) ------------------------ */

    'P9-T1 the register form offers the free, code and pending modes' => function () use ($renderRegister): void {
        $html = $renderRegister();

        assert_contains('name="mode"', $html);
        assert_contains('value="standard"', $html);
        assert_contains('value="code"', $html);
        assert_contains('value="pending"', $html);

        /* A guest is offered a sign-in path for the e-wallet mode instead
         * of the control itself (P9-T5). */
        assert_not_contains('value="ewallet"', $html);
        assert_contains('Sign in', $html);

        assert_contains('name="sponsor"', $html, 'the sponsor field is present');
        assert_contains('Sponsor username or referral code', $html);
        assert_contains('(optional)', $html, 'the sponsor field is optional');
        assert_contains('Leave this blank to join under the company account.', $html);
        assert_same(
            1,
            preg_match('/id="sponsor"[\s\S]{0,400}?\/>/', $html),
            'the sponsor input renders'
        );
        assert_same(
            0,
            preg_match('/id="sponsor"[\s\S]{0,400}?required/', $html),
            'the sponsor input is not required'
        );
        assert_contains('name="reg_code"', $html);
        assert_contains('name="terms"', $html);
        assert_contains('name="_csrf"', $html);
        assert_contains('XXXX-XXXX-XXXX', $html);
    },

    'P9-T3 a code error is announced once, not twice' => function () use ($renderRegister): void {
        errors_set(['reg_code' => 'That registration code is not recognised.']);

        try {
            $html = $renderRegister(['mode' => 'code']);
        } finally {
            errors_set([]);
        }

        /* One generic prompt above the form, one detail under the field. */
        assert_same(1, substr_count($html, 'alert-danger'));
        assert_contains('Please fix the highlighted field and try again.', $html);
        assert_same(
            1,
            substr_count($html, 'That registration code is not recognised.'),
            'the message must not be repeated'
        );
        assert_contains('data-code-feedback', $html, 'the live Validate check can clear the server error');
        assert_not_contains('That registration code is not recognised.</strong>', $html);
    },

    'P9-T3 a global registration failure shows its message once' => function () use ($renderRegister): void {
        errors_set(['_form' => 'The account could not be created. Please try again.']);

        try {
            $html = $renderRegister();
        } finally {
            errors_set([]);
        }

        assert_same(1, substr_count($html, 'alert-danger'));
        assert_same(1, substr_count($html, 'The account could not be created. Please try again.'));
        assert_not_contains('Please fix the highlighted field', $html);
    },

    'P9-T1 the register form pre-fills a referral sponsor' => function () use ($renderRegister): void {
        $html = $renderRegister(['mode' => 'pending', 'prefill_sponsor' => 'juan']);

        assert_contains('name="sponsor"', $html);
        assert_contains('value="juan"', $html);
        assert_contains('value="pending"', $html);
        assert_same(
            1,
            preg_match('/value="pending"[\s\S]{0,160}?checked/', $html),
            'the pending mode is selected'
        );
    },

    'P9-T4 a signed-in registrar sees the e-wallet mode and package cards' => function () use ($renderRegister): void {
        $registrar = test_user('mv_registrar', 'member');

        $html = $renderRegister([
            'mode'              => 'ewallet',
            'registrar'         => $registrar,
            'registrar_balance' => 9000.0,
        ]);

        assert_contains('value="ewallet"', $html);
        assert_contains('name="package_id"', $html);
        assert_contains('Package A', $html);
        assert_contains('Package B', $html);
        assert_contains('Your balance: ' . money(9000.0), $html);
        assert_not_contains('register-mode-muted', $html, 'a registrar gets the real e-wallet option');
    },

    'P9-T4 a signed-in registrar sees the register-a-member framing' => function () use ($renderRegister): void {
        $registrar = test_user('mv_registrar', 'member');

        $html = $renderRegister([
            'mode'      => 'standard',
            'registrar' => $registrar,
        ]);

        assert_contains('Register a member', $html);
        assert_contains('@' . $registrar['username'], $html);
        assert_contains('Back to dashboard', $html);
        assert_not_contains('Already have an account?', $html, 'a signed-in registrar is not offered sign-in');
        assert_contains(
            'Defaults to your account, @' . $registrar['username'] . '.',
            $html,
            'the sponsor hint names the registrar'
        );
    },

    'P9-T1 closed free registration leaves only the code mode' => function () use ($renderRegister): void {
        $html = $renderRegister(['mode' => 'code', 'free_enabled' => false]);

        assert_not_contains('value="standard"', $html);
        assert_not_contains('value="pending"', $html);
        assert_contains('value="code"', $html);
    },

    'P9-T18 the register form has no binary placement field' => function () use ($renderRegister): void {
        $html = $renderRegister();

        foreach (['binary_parent', 'binary_position', 'upline_username', 'position_mode'] as $needle) {
            assert_not_contains($needle, $html);
        }
    },

    /* ---------- Membership page (P9-T9 … T11) --------------------- */

    'P9-T9 a pending member sees the three activation paths' => function () use ($renderView, $makeMember, $membershipData): void {
        $member = $makeMember([
            'member_status' => 'pending',
            'package_id'    => null,
            'package_name'  => null,
        ]);
        test_signed_in_as($member);

        $html = $renderView('member', 'member/membership', $membershipData($member));

        assert_contains('Membership', $html);
        assert_contains('Registration code', $html);
        assert_contains('E-wallet', $html);
        assert_contains('Payment proof', $html);
        assert_contains('name="method" value="code"', $html);
        assert_contains('name="method" value="ewallet"', $html);
        assert_contains('name="method" value="manual"', $html);
        assert_contains('name="proof"', $html);
        assert_contains('enctype="multipart/form-data"', $html);

        /* The company receiving accounts are shown for the manual path. */
        assert_contains((string) setting('payment_gcash_number'), $html);
        assert_contains((string) setting('payment_bank_name'), $html);

        /* History empty state. */
        assert_contains('No activation requests yet', $html);

        /* The referral card's copy control is a bare icon with a tooltip,
         * matching the code list (no labelled "Copy" button). */
        $referralStart = strpos($html, 'Referral link');
        $referralBox   = $referralStart === false ? '' : substr($html, (int) $referralStart, 1600);

        assert_contains('class="btn btn-icon btn-text-secondary"', $referralBox);
        assert_contains('data-copy-tooltip="Copy link"', $referralBox);
        assert_not_contains('data-copy-text', $referralBox);
    },

    'P9-T10 an open proof request replaces the activation forms' => function () use ($renderView, $makeMember, $membershipData): void {
        $member = $makeMember([
            'member_status' => 'pending',
            'package_id'    => null,
        ]);

        Activation::create([
            'user_id'        => (int) $member['id'],
            'package_id'     => (int) Package::findBySlug('package-a')['id'],
            'amount'         => 4498.00,
            'payment_method' => 'gcash',
            'status'         => 'pending_review',
            'reference_no'   => 'GC-123456',
            'proof_path'     => 'uploads/proofs/probe.png',
        ]);

        test_signed_in_as($member);

        $html = $renderView('member', 'member/membership', $membershipData($member));

        assert_contains('Activation in progress', $html);
        assert_contains('GCash', $html);
        assert_not_contains('name="method" value="code"', $html, 'no second request while one is open');
    },

    'P9-T11 an active associate sees the package, not the forms' => function () use ($renderView, $makeMember, $membershipData): void {
        $member = $makeMember([
            'member_status' => 'active',
            'package_id'    => (int) Package::findBySlug('package-a')['id'],
            'package_name'  => 'Package A',
            'activated_at'  => date('Y-m-d H:i:s'),
        ]);

        test_signed_in_as($member);

        $html = $renderView('member', 'member/membership', $membershipData($member));

        assert_contains('Active associate', $html);
        assert_contains('Package A', $html);
        assert_not_contains('name="method" value="ewallet"', $html);
        assert_not_contains('Activation opens', $html);
    },

    'P9-T1 a standard member reads the standard-membership note' => function () use ($renderView, $makeMember, $membershipData): void {
        $member = $makeMember([
            'member_status' => 'active',
            'package_id'    => null,
            'package_name'  => null,
        ]);

        test_signed_in_as($member);

        $html = $renderView('member', 'member/membership', $membershipData($member));

        assert_contains('Standard membership', $html);
        assert_not_contains('name="method" value="manual"', $html);
    },

    'P9-T11 a suspended member sees no activation form' => function () use ($renderView, $makeMember, $membershipData): void {
        $member = $makeMember([
            'member_status' => 'suspended',
            'package_id'    => null,
            'package_name'  => null,
        ]);

        test_signed_in_as($member);

        $html = $renderView('member', 'member/membership', $membershipData($member));

        assert_contains('suspended', $html);
        assert_not_contains('name="method" value="code"', $html);
    },

    /* ---------- Genealogy (P9-T17) -------------------------------- */

    'P9-T17 genealogy renders the upline and the team levels' => function () use ($renderView): void {
        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $sponsors = new SponsorService();
        $team     = $sponsors->downline((int) $juan['id']);

        $byLevel = [];
        foreach ($team as $row) {
            $byLevel[(int) $row['level']][] = $row;
        }

        $direct = $byLevel[1] ?? [];
        unset($byLevel[1]);

        $html = $renderView('member', 'member/genealogy', [
            'page_title'    => 'Genealogy',
            'current_nav'   => 'genealogy',
            'member'        => $juan,
            'upline'        => $sponsors->chain((int) $juan['id']),
            'direct'        => $direct,
            'recruits_pager' => Paginator::make(count($direct), 1, Paginator::DEFAULT_PER_PAGE),
            'deeper'        => $byLevel,
            'team_count'    => count($team),
            'levels_used'   => $byLevel === [] ? 0 : max(array_keys($byLevel)),
            'referral_code' => (string) $juan['referral_code'],
            'referral_link' => absolute_url('register?ref=' . (string) $juan['referral_code']),
        ]);

        assert_contains('Upline chain', $html);
        assert_contains('admin', $html, 'the company root is the upline');
        assert_contains('L1', $html);

        assert_contains('Direct recruits', $html);
        assert_contains('maria', $html);
        assert_contains('Rows per page', $html, 'the recruits table paginates');

        /* Level 2 holds maria's recruits: peter, sarah and david. */
        assert_contains('Level 2', $html);
        assert_contains('peter', $html);
        assert_contains('sarah', $html);
        assert_contains('david', $html);

        assert_contains((string) $juan['referral_code'], $html);

        /* No binary column or placement field is rendered. */
        foreach (['binary_parent', 'binary_position', 'binary_tree', 'left_count', 'right_count'] as $needle) {
            assert_not_contains($needle, $html);
        }
    },

    'P9-T17 genealogy escapes a hostile username' => function () use ($renderView): void {
        $juan  = Member::findByUsername('juan');
        $maria = Member::findByUsername('maria');
        assert_not_null($juan);
        assert_not_null($maria);

        /* maria is a direct recruit of juan, so her name renders on his page. */
        db()->prepare("UPDATE users SET username = '<script>alert(1)</script>' WHERE id = ?")
            ->execute([(int) $maria['id']]);

        try {
            $team = (new SponsorService())->downline((int) $juan['id']);

            $html = $renderView('member', 'member/genealogy', [
                'page_title'    => 'Genealogy',
                'current_nav'   => 'genealogy',
                'member'        => Member::find((int) $juan['id']),
                'upline'        => [],
                'direct'        => $team,
                'deeper'        => [],
                'team_count'    => count($team),
                'levels_used'   => 1,
                'referral_code' => 'TESTCODE',
                'referral_link' => absolute_url('register?ref=TESTCODE'),
            ]);

            assert_not_contains('<script>alert(1)</script>', $html);
            assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        } finally {
            db()->prepare("UPDATE users SET username = 'maria' WHERE id = ?")->execute([(int) $maria['id']]);
        }
    },

    /* ---------- Dashboard (P9-T16) -------------------------------- */

    'P9-T16 the dashboard shows status, both wallet buckets and the referral link' => function () use ($renderView): void {
        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        test_signed_in_as($juan);

        $wallet = new WalletService();
        $orders = Order::listForUser((int) $juan['id'], [], new Paginator(5, 5, 1));

        $html = $renderView('member', 'member/dashboard', [
            'page_title'  => 'Dashboard',
            'current_nav' => 'dashboard',
            'member'      => $juan,
            'balance'     => $wallet->balance((int) $juan['id']),
            'withdrawable' => $wallet->withdrawable((int) $juan['id']),
            'orders'      => $orders,
            'order_stats' => Order::statsForUser((int) $juan['id']),
            'commissions' => (new CommissionService())->totalsFor((int) $juan['id']),
            'team'        => (new SponsorService())->teamStats((int) $juan['id']),
            'open_activation' => null,
            'last_activation' => null,
            'activation_count' => 0,
            'referral_code' => (string) $juan['referral_code'],
            'referral_link' => absolute_url('register?ref=' . (string) $juan['referral_code']),
            'recent_history' => [],
        ]);

        assert_contains('Welcome back', $html);
        assert_contains('Active associate', $html);
        assert_contains(money(2000.0), $html);
        assert_contains(money(1500.0), $html);
        assert_contains((string) $juan['referral_code'], $html);
        assert_contains('Recent orders', $html);
        assert_contains('Earnings', $html);
        assert_contains('Withdrawable', $html);
        assert_contains('data-copy-tooltip="Copy link"', $html, 'the referral copy button has a tooltip');
        assert_contains('class="btn btn-icon btn-text-secondary"', $html, 'the referral copy control is a bare icon');
        assert_not_contains('binary', strtolower($html));
    },

    'P9-T16 the dashboard tells a pending member what to do' => function () use ($renderView): void {
        $david = Member::findByUsername('david');
        assert_not_null($david);

        test_signed_in_as($david);

        $html = $renderView('member', 'member/dashboard', [
            'page_title'  => 'Dashboard',
            'current_nav' => 'dashboard',
            'member'      => $david,
            'balance'     => 0.0,
            'withdrawable' => 0.0,
            'orders'      => [],
            'order_stats' => ['total' => 0, 'open' => 0, 'completed' => 0, 'spent' => 0.0],
            'commissions' => ['count' => 0, 'credited' => 0.0, 'this_month' => 0.0, 'last_at' => null],
            'team'        => ['direct' => 0, 'team' => 0, 'levels' => 0],
            'open_activation' => null,
            'last_activation' => null,
            'activation_count' => 0,
            'referral_code' => (string) $david['referral_code'],
            'referral_link' => absolute_url('register?ref=' . (string) $david['referral_code']),
            'recent_history' => [],
        ]);

        assert_contains('Pending activation', $html);
        assert_contains('Membership', $html);
        assert_contains('waiting for activation', $html);
        assert_contains('No orders yet', $html);
    },

    /* ---------- Admin: codes (P9-T14, P9-T15) --------------------- */

    'P9-T14 the codes page renders stats, the issue form and the list' => function () use ($renderView): void {
        test_signed_in_as(test_user('mv_admin', 'admin'));

        $batch = (new CodeService())->generate([
            'package_id' => (int) Package::findBySlug('package-a')['id'],
            'quantity'   => 2,
            'price'      => '4600.00',
            'expires_at' => date('Y-m-d', strtotime('+14 days')),
        ], test_user('mv_code_admin', 'admin'));

        $filters = RegCode::normaliseFilters(['status' => 'unused']);
        $pager   = Paginator::fromRequest(RegCode::countAdmin($filters), 20);

        $html = $renderView('admin', 'admin/codes/index', [
            'page_title'  => 'Registration codes',
            'current_nav' => 'admin.codes',
            'stats'       => RegCode::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'codes'       => RegCode::adminList($filters, $pager),
            'packages'    => Package::all(),
        ]);

        assert_contains('Registration codes', $html);
        assert_contains('Issue a batch', $html);
        assert_contains('name="package_id"', $html);
        assert_contains('name="quantity"', $html);
        assert_contains('name="price"', $html);
        assert_contains('name="expires_at"', $html);
        assert_contains('Export CSV', $html);
        assert_contains(url('admin/codes/export'), $html);
        assert_contains((string) $batch['codes'][0], $html);
        assert_contains('Total codes', $html);
        assert_contains('Revenue (used)', $html);
        assert_contains('data-bs-toggle="tooltip"', $html, 'copy buttons carry a tooltip');
        assert_contains('data-copy-tooltip="Copy code"', $html);
    },

    'P9-T15 a member cannot reach the admin screens (route guards)' => function (): void {
        $routes = require dirname(__DIR__, 2) . '/app/routes.php';

        foreach ($routes as [$method, $path, , $guard]) {
            if (str_starts_with($path, '/admin/members') || str_starts_with($path, '/admin/codes')
                || str_starts_with($path, '/admin/activations')) {
                assert_same('admin', $guard, $method . ' ' . $path);
            }
        }

        /* And the nav never shows these to a member. */
        $member = test_user('mv_plain_member', 'member');
        assert_false(nav_can_see(['guard' => 'admin'], $member));
        assert_true(nav_can_see(['guard' => 'member'], $member));
    },

    /* ---------- Admin: activations (P9-T10, T11) ------------------ */

    'P9-T10 the review queue offers approve, reject and the proof' => function () use ($renderView): void {
        test_signed_in_as(test_user('mv_admin', 'admin'));

        $member = test_user('mv_activation_member_' . bin2hex(random_bytes(3)), 'member');
        db()->prepare("UPDATE users SET member_status = 'pending' WHERE id = ?")->execute([(int) $member['id']]);

        $activationId = Activation::create([
            'user_id'        => (int) $member['id'],
            'package_id'     => (int) Package::findBySlug('package-a')['id'],
            'amount'         => 4498.00,
            'payment_method' => 'gcash',
            'status'         => 'pending_review',
            'reference_no'   => 'GC-777888',
            'proof_path'     => 'uploads/proofs/probe.png',
        ]);

        $filters = Activation::normaliseFilters(['status' => 'pending_review']);
        $pager   = Paginator::fromRequest(Activation::countAdmin($filters), 20);

        $html = $renderView('admin', 'admin/activations/index', [
            'page_title'  => 'Activations',
            'current_nav' => 'admin.activations',
            'stats'       => Activation::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'activations' => Activation::adminList($filters, $pager),
            'tab_counts'  => [
                'review'   => Activation::countAdmin(['status' => 'pending_review', 'method' => '', 'q' => '']),
                'approved' => 0,
                'rejected' => 0,
            ],
        ]);

        assert_contains('Activations', $html);
        assert_contains('Awaiting review', $html);
        assert_contains('@' . (string) $member['username'], $html);
        assert_contains('Approve', $html);
        assert_contains('Reject', $html);
        assert_contains('name="reason"', $html);
        assert_contains('View proof', $html);
        assert_contains(url('admin/activations/' . $activationId . '/proof'), $html);
        assert_contains(url('admin/activations/' . $activationId . '/approve'), $html);
        assert_contains('data-confirm', $html);
    },

    'P9-T11 an approved activation has no review actions' => function () use ($renderView): void {
        test_signed_in_as(test_user('mv_admin', 'admin'));

        $member = test_user('mv_approved_member_' . bin2hex(random_bytes(3)), 'member');

        $activationId = Activation::create([
            'user_id'        => (int) $member['id'],
            'package_id'     => (int) Package::findBySlug('package-a')['id'],
            'amount'         => 4498.00,
            'payment_method' => 'bank',
            'status'         => 'approved',
        ]);

        Activation::markApproved($activationId, (int) test_user('mv_admin', 'admin')['id']);
        Activation::markCommissionProcessed($activationId);

        $filters = Activation::normaliseFilters(['status' => 'approved']);
        $pager   = Paginator::fromRequest(Activation::countAdmin($filters), 20);

        $html = $renderView('admin', 'admin/activations/index', [
            'page_title'  => 'Activations',
            'current_nav' => 'admin.activations',
            'stats'       => Activation::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'activations' => Activation::adminList($filters, $pager),
            'tab_counts'  => ['review' => 0, 'approved' => 1, 'rejected' => 0],
        ]);

        assert_not_contains(url('admin/activations/' . $activationId . '/approve'), $html);
        assert_not_contains(url('admin/activations/' . $activationId . '/reject'), $html);
        assert_contains('Approved', $html);
    },

    /* ---------- Admin: members (P9-T16) --------------------------- */

    'P9-T16 the member directory shows sponsor, package, wallet and status' => function () use ($renderView): void {
        test_signed_in_as(test_user('mv_admin', 'admin'));

        $filters = Member::normaliseFilters(['q' => 'maria']);
        $pager   = Paginator::fromRequest(Member::countAdmin($filters), 20);

        $html = $renderView('admin', 'admin/members/index', [
            'page_title'  => 'Members',
            'current_nav' => 'admin.members',
            'stats'       => Member::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'members'     => Member::adminList($filters, $pager),
            'packages'    => Member::packageBreakdown(),
        ]);

        assert_contains('@maria', $html);
        assert_contains('Package A', $html);
        assert_contains('@juan', $html, 'the sponsor column');
        assert_contains(money(1000.0), $html);
        assert_contains(money(800.0), $html);
        assert_contains('Active', $html);
        assert_contains('data-confirm', $html);
        assert_contains('name="action" value="suspend"', $html);
    },

    'P9-T16 the member detail page carries the membership record' => function () use ($renderView): void {
        test_signed_in_as(test_user('mv_admin', 'admin'));

        $maria = Member::findByUsername('maria');
        assert_not_null($maria);

        /* One activation so the history table and its footer render. */
        Activation::create([
            'user_id'        => (int) $maria['id'],
            'package_id'     => (int) Package::findBySlug('package-a')['id'],
            'amount'         => 4498.00,
            'payment_method' => 'gcash',
            'status'         => 'pending_review',
            'reference_no'   => 'QA-VIEW-1',
        ]);

        $activations = Activation::forUser((int) $maria['id']);

        $wallet   = new WalletService();
        $sponsors = new SponsorService();

        $html = $renderView('admin', 'admin/members/show', [
            'page_title'   => 'Member — maria',
            'current_nav'  => 'admin.members',
            'member'       => $maria,
            'balance'      => $wallet->balance((int) $maria['id']),
            'withdrawable' => $wallet->withdrawable((int) $maria['id']),
            'activations'  => $activations,
            'activations_pager' => Paginator::make(count($activations), 1, Paginator::DEFAULT_PER_PAGE),
            'orders'       => Order::statsForUser((int) $maria['id']),
            'commissions'  => (new CommissionService())->totalsFor((int) $maria['id']),
            'team'         => $sponsors->teamStats((int) $maria['id']),
            'upline'       => $sponsors->chain((int) $maria['id']),
            'audits'       => [],
            'statement_url' => url('admin/wallets/' . (int) $maria['id']),
        ]);

        assert_contains('@maria', $html);
        assert_contains('Membership', $html);
        assert_contains('Package A', $html);
        assert_contains('@juan', $html);
        assert_contains(money(1000.0), $html);
        assert_contains('Commission credited', $html);
        assert_contains('Rows per page', $html, 'the activation history paginates');
        assert_contains('GCash', $html);
        assert_contains('Pending review', $html);
        assert_contains('Upline', $html);
        assert_contains('Recent audit', $html);
        assert_contains('Suspend member', $html);
        assert_not_contains('binary', strtolower($html));
    },

    'P9-T16 the member table honours the rows-per-page choice' => function (): void {
        test_signed_in_as(test_user('mv_admin', 'admin'));

        $filters = Member::normaliseFilters([]);

        $_GET = ['per_page' => '5'];

        try {
            $pager = Paginator::fromTableRequest(Member::countAdmin($filters));
            $rows  = Member::adminList($filters, $pager);

            assert_same(5, $pager->perPage);
            assert_same(min(5, $pager->total), count($rows), 'the first page holds exactly the chosen size');
            assert_true($pager->total >= 5, 'the demo tree supplies at least five members');

            $_GET = ['per_page' => '7'];

            $fallback = Paginator::fromTableRequest(Member::countAdmin($filters));
            assert_same(10, $fallback->perPage, 'an unlisted size falls back to 10');
        } finally {
            $_GET = [];
        }
    },
];
