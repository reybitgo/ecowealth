<?php
declare(strict_types=1);

/* ==============================================================
 *  CommissionTest — direct referral commissions (plan §6.2–6.5,
 *  §7 Phase 10, tests P10-T1 … P10-T10)
 *
 *  The award is always produced by a real activation path: either a
 *  registration code (the whole flow), an admin approval, or the
 *  shared ActivationService::complete() the other paths call. Nothing
 *  here inserts a commission row directly, so the tests prove the
 *  wiring, not just the table.
 * ============================================================== */

/** Register a pending member under the given sponsor (username or code). */
$pendingMember = static function (string $sponsor = 'juan'): array {
    $username = 'comm_' . bin2hex(random_bytes(4));

    $result = (new RegistrationService())->register([
        'mode'             => 'pending',
        'username'         => $username,
        'email'            => $username . '@test.local',
        'full_name'        => 'Commission Test',
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

/** An already-approved activation row, ready for ActivationService::complete(). */
$approvedActivation = static function (int $userId, array $package, string $method = 'code'): int {
    return Activation::create([
        'user_id'        => $userId,
        'package_id'     => (int) $package['id'],
        'amount'         => (float) $package['price'],
        'payment_method' => $method,
        'status'         => 'approved',
    ]);
};

/** A real uploaded-file fixture that Upload::store can accept. */
$proofFile = static function (): array {
    $tmp = (string) sys_get_temp_dir() . '/eco-commission-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($tmp, TEST_PNG_BYTES);

    return [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ];
};

$wallet = static fn (): WalletService => new WalletService();
$juan   = static fn (): array => Member::findByUsername('juan') ?? [];
$maria  = static fn (): array => Member::findByUsername('maria') ?? [];
$pkgA   = static fn (): array => Package::findBySlug('package-a') ?? [];
$pkgB   = static fn (): array => Package::findBySlug('package-b') ?? [];
$admin  = static fn (): array => test_user('comm_admin', 'admin');

/** Activate a pending member through the shared completion step. */
$activate = static function (array $member, array $package) use ($approvedActivation): array {
    $activationId = $approvedActivation((int) $member['id'], $package);

    return (new ActivationService())->complete($activationId);
};

/**
 * Phase 10 pins the DIRECT leg in isolation.
 *
 * From Phase 11 the same activation also walks levels 2–10, so the demo
 * chain would add the indirect awards (and the company-root skip) to
 * every expected row count. The package toggle is the documented way to
 * switch the ladder off (P11-T5); each direct-leg case turns it off for
 * its own transaction, which the harness rolls back.
 */
$directOnly = static function (): void {
    db()->exec('UPDATE packages SET indirect_enabled = 0');
};

return [
    /* ---------- P10-T1: the award itself -------------------------- */

    'P10-T1 an active sponsor earns the package direct bonus exactly once' => function () use ($pendingMember, $wallet, $juan, $pkgA, $admin, $directOnly): void {
        $directOnly();
        $member = $pendingMember('juan');
        $juanId = (int) $juan()['id'];

        $before              = $wallet()->balance($juanId);
        $withdrawableBefore  = $wallet()->withdrawable($juanId);
        $ledgerBefore        = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();
        $commissionsBefore   = (int) db()->query('SELECT COUNT(*) FROM commissions')->fetchColumn();

        $activationId = Activation::create([
            'user_id'        => (int) $member['id'],
            'package_id'     => (int) $pkgA()['id'],
            'amount'         => (float) $pkgA()['price'],
            'payment_method' => 'gcash',
            'status'         => 'pending_review',
        ]);

        $summary = (new ActivationService())->approve($activationId, $admin());

        /* Money: the sponsor's wallet gained the direct bonus, and it is
         * withdrawable because it is earnings. */
        assert_same($before + 500.0, $wallet()->balance($juanId), 'the sponsor is credited the direct bonus');
        assert_same($withdrawableBefore + 500.0, $wallet()->withdrawable($juanId), 'the credit is withdrawable');

        /* One commission row, joined to the activation and the package. */
        $rows = Commission::forActivation($activationId);
        assert_count(1, $rows);
        $row = $rows[0];
        assert_same('direct_referral', $row['type']);
        assert_same(1, (int) $row['level']);
        assert_same(500.0, (float) $row['amount']);
        assert_same('credited', $row['status']);
        assert_same($juanId, (int) $row['user_id']);
        assert_same((int) $member['id'], (int) $row['source_user_id']);
        assert_same((int) $pkgA()['id'], (int) $row['package_id']);
        assert_not_null($row['ledger_id']);
        assert_same('juan', $row['recipient_username']);
        assert_same((string) $member['username'], $row['source_username']);

        /* One immutable ledger row, ref_type 'commission', ref_id = award. */
        assert_same($ledgerBefore + 1, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());
        assert_same($commissionsBefore + 1, (int) db()->query('SELECT COUNT(*) FROM commissions')->fetchColumn());

        $ledger = db()->prepare('SELECT * FROM ewallet_ledger WHERE id = ? LIMIT 1');
        $ledger->execute([(int) $row['ledger_id']]);
        $movement = $ledger->fetch();

        assert_not_same(false, $movement);
        assert_same('credit', $movement['type']);
        assert_same('commission', $movement['ref_type']);
        assert_same((int) $row['id'], (int) $movement['ref_id']);
        assert_same($juanId, (int) $movement['user_id']);
        assert_same(500.0, (float) $movement['amount']);
        assert_contains('Direct referral', (string) $movement['note']);
        assert_contains('Package A', (string) $movement['note']);

        /* The activation is stamped so it can never pay twice. */
        assert_not_null(Activation::find($activationId)['commission_processed_at']);
        assert_same(500.0, (float) $summary['commission_credited'], 'the completion summary carries the credit');
    },

    'P10-T1 registration with a code pays the sponsor immediately' => function () use ($juan, $wallet, $directOnly): void {
        $directOnly();
        $admin = test_user('comm_code_admin', 'admin');

        $batch = (new CodeService())->generate([
            'package_id' => (int) Package::findBySlug('package-a')['id'],
            'quantity'   => 1,
            'price'      => '4498.00',
            'expires_at' => date('Y-m-d', strtotime('+30 days')),
            'code_type'  => 'registration',
        ], $admin);

        $juanId = (int) $juan()['id'];
        $before = $wallet()->balance($juanId);
        $username = 'comm_code_' . bin2hex(random_bytes(4));

        $result = (new RegistrationService())->register([
            'mode'             => 'code',
            'username'         => $username,
            'email'            => $username . '@test.local',
            'full_name'        => 'Code Recruit',
            'phone'            => '',
            'password'         => 'Member@1234',
            'password_confirm' => 'Member@1234',
            'sponsor'          => 'juan',
            'package_id'       => '',
            'reg_code'         => $batch['codes'][0],
            'terms'            => true,
        ], null);

        assert_same('active', $result['member_status']);
        assert_same($before + 500.0, $wallet()->balance($juanId), 'the sponsor is paid inside the registration transaction');

        $rows = Commission::forActivation((int) $result['activation_id']);
        assert_count(1, $rows);
        assert_same('credited', $rows[0]['status']);
        assert_same(500.0, (float) $rows[0]['amount']);
    },

    'P10-T1 package B pays its premium direct bonus' => function () use ($pendingMember, $wallet, $juan, $pkgB, $directOnly): void {
        $directOnly();
        $member = $pendingMember('juan');
        $juanId = (int) $juan()['id'];
        $before = $wallet()->balance($juanId);

        $activationId = Activation::create([
            'user_id'        => (int) $member['id'],
            'package_id'     => (int) $pkgB()['id'],
            'amount'         => (float) $pkgB()['price'],
            'payment_method' => 'ewallet',
            'status'         => 'approved',
        ]);

        (new ActivationService())->complete($activationId);

        assert_same($before + 600.0, $wallet()->balance($juanId), 'Package B pays its higher direct bonus');
        assert_same('Package B', Commission::forActivation($activationId)[0]['package_name']);
        assert_same(600.0, (float) Commission::forActivation($activationId)[0]['amount']);
    },

    /* ---------- P10-T2: ineligible recipients are skipped --------- */

    'P10-T2 a pending sponsor is skipped without moving money' => function () use ($pendingMember, $wallet, $juan, $pkgA, $activate, $directOnly): void {
        $directOnly();
        /* david is a pending demo member with no package. */
        $david = Member::findByUsername('david');
        assert_not_null($david);

        $member = $pendingMember('david');
        $juanId = (int) $juan()['id'];

        $juanBefore   = $wallet()->balance($juanId);
        $davidBefore  = $wallet()->balance((int) $david['id']);
        $ledgerBefore = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();

        $summary = $activate($member, $pkgA());

        $rows = Commission::forActivation((int) $summary['activation_id']);
        assert_count(1, $rows);
        assert_same('skipped', $rows[0]['status']);
        assert_same(500.0, (float) $rows[0]['amount'], 'the configured amount is recorded for the audit trail');
        assert_contains('pending', (string) $rows[0]['reason']);
        assert_null($rows[0]['ledger_id']);

        assert_same($juanBefore, $wallet()->balance($juanId), 'an unrelated member is untouched');
        assert_same($davidBefore, $wallet()->balance((int) $david['id']), 'a skipped award pays nothing');
        assert_same($ledgerBefore, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());
        assert_same(0.0, (float) $summary['commission_credited']);
        assert_same(1, (int) $summary['commission_skipped']);

        /* The activation still completes — the member is active. */
        assert_same('active', Member::find((int) $member['id'])['member_status']);
    },

    'P10-T2 an inactive or package-less sponsor is skipped with a reason' => function () use ($pendingMember, $wallet, $juan, $pkgA, $activate, $directOnly): void {
        $directOnly();
        $juanId = (int) $juan()['id'];

        /* juan has a package; the award is paid. */
        $paid = $activate($pendingMember('juan'), $pkgA());
        assert_same('credited', Commission::forActivation((int) $paid['activation_id'])[0]['status']);

        /* Now juan loses his package: the next award is skipped. */
        db()->prepare('UPDATE users SET package_id = NULL WHERE id = ?')->execute([$juanId]);
        $balance = $wallet()->balance($juanId);

        $skipped = $activate($pendingMember('juan'), $pkgA());
        $row     = Commission::forActivation((int) $skipped['activation_id'])[0];

        assert_same('skipped', $row['status']);
        assert_contains('package', strtolower((string) $row['reason']));
        assert_same($balance, $wallet()->balance($juanId));

        /* And a deactivated sponsor is skipped too (the member is created
         * before juan is switched off — registration refuses a dead sponsor). */
        $lateMember = $pendingMember('juan');
        db()->prepare("UPDATE users SET package_id = ?, is_active = 0 WHERE id = ?")
            ->execute([(int) $pkgA()['id'], $juanId]);

        $inactive = $activate($lateMember, $pkgA());
        $row      = Commission::forActivation((int) $inactive['activation_id'])[0];

        assert_same('skipped', $row['status']);
        assert_contains('not active', strtolower((string) $row['reason']));
    },

    'P10-T2 a member with no sponsor earns nothing and no row is written' => function () use ($pendingMember, $pkgA, $activate): void {
        $member = $pendingMember('juan');

        db()->prepare('UPDATE users SET sponsor_id = NULL WHERE id = ?')->execute([(int) $member['id']]);

        $summary = $activate($member, $pkgA());

        assert_count(0, Commission::forActivation((int) $summary['activation_id']), 'no recipient means no row');
        assert_same(0.0, (float) $summary['commission_credited']);
        assert_same(0, (int) $summary['commission_skipped']);
        assert_same('active', Member::find((int) $member['id'])['member_status'], 'the activation still completes');
    },

    /* ---------- P10-T3: a zero bonus writes nothing --------------- */

    'P10-T3 a package with a zero direct bonus awards nothing and does not error' => function () use ($pendingMember, $wallet, $juan, $activate): void {
        $packageId = Package::save([
            'name'                  => 'Zero Bonus ' . bin2hex(random_bytes(3)),
            'price'                 => '100.00',
            'product_value'         => '100.00',
            'direct_referral_bonus' => '0.00',
            'status'                => 'active',
            'sort_order'            => 99,
        ], [], []);

        $package = Package::find($packageId);
        assert_not_null($package);

        $juanId = (int) $juan()['id'];
        $before = $wallet()->balance($juanId);
        $rows   = (int) db()->query('SELECT COUNT(*) FROM commissions')->fetchColumn();

        $summary = $activate($pendingMember('juan'), $package);

        assert_same($rows, (int) db()->query('SELECT COUNT(*) FROM commissions')->fetchColumn(), 'no row for a zero bonus');
        assert_same($before, $wallet()->balance($juanId));
        assert_same([], Commission::forActivation((int) $summary['activation_id']));
        assert_same('active', Member::find((int) $summary['user_id'])['member_status']);
    },

    /* ---------- P10-T4: idempotency ------------------------------- */

    'P10-T4 replaying the hook pays nothing twice' => function () use ($pendingMember, $wallet, $juan, $pkgA, $activate, $directOnly): void {
        $directOnly();
        $member = $pendingMember('juan');
        $juanId = (int) $juan()['id'];
        $before = $wallet()->balance($juanId);

        $summary = $activate($member, $pkgA());
        assert_same($before + 500.0, $wallet()->balance($juanId));

        /* The activation cannot be completed again... */
        assert_throws(
            static fn () => (new ActivationService())->complete((int) $summary['activation_id']),
            ActivationException::class
        );

        /* ...and a direct replay of the hook returns the same award. */
        $replay = (new CommissionService())->onActivation((int) $summary['activation_id']);

        assert_same(1, count($replay['awards']));
        assert_same(0.0, (float) $replay['credited'], 'a replay credits nothing');
        assert_same($before + 500.0, $wallet()->balance($juanId), 'the wallet is unchanged by the replay');
        assert_same(1, count(Commission::forActivation((int) $summary['activation_id'])));
        assert_same(
            1,
            (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'commission'")->fetchColumn()
        );
    },

    'P10-T4 the unique key refuses a second award for the same event' => function () use ($pendingMember, $pkgA, $activate, $directOnly): void {
        $directOnly();
        $member = $pendingMember('juan');
        $summary = $activate($member, $pkgA());
        $row     = Commission::forActivation((int) $summary['activation_id'])[0];

        assert_throws(
            static fn () => Commission::create([
                'user_id'        => (int) $row['user_id'],
                'type'           => 'direct_referral',
                'amount'         => 500.00,
                'level'          => 1,
                'source_user_id' => (int) $row['source_user_id'],
                'package_id'     => (int) $row['package_id'],
                'activation_id'  => (int) $row['activation_id'],
            ]),
            PDOException::class,
            'uq_commission_event is the database half of the idempotency anchor'
        );
    },

    /* ---------- P10-T5: two recruits ------------------------------ */

    'P10-T5 two recruits credit two separate awards' => function () use ($pendingMember, $wallet, $juan, $pkgA, $activate, $directOnly): void {
        $directOnly();
        $juanId = (int) $juan()['id'];
        $before = $wallet()->balance($juanId);
        $count  = Commission::countForUser($juanId, Commission::filterDefaults());

        $first  = $activate($pendingMember('juan'), $pkgA());
        $second = $activate($pendingMember('juan'), $pkgA());

        assert_same($before + 1000.0, $wallet()->balance($juanId), 'two recruits are ₱1,000');
        assert_same($count + 2, Commission::countForUser($juanId, Commission::filterDefaults()));

        $rows = Commission::forActivation((int) $first['activation_id']);
        assert_count(1, $rows);
        $rows = Commission::forActivation((int) $second['activation_id']);
        assert_count(1, $rows);

        /* Two distinct awards, never one row paid twice. */
        assert_not_same(
            (int) Commission::forActivation((int) $first['activation_id'])[0]['id'],
            (int) Commission::forActivation((int) $second['activation_id'])[0]['id']
        );
    },

    /* ---------- P10-T6: finance invariants ------------------------ */

    'P10-T6 wallet and commission reconciliation stay clean' => function () use ($pendingMember, $pkgA, $activate, $directOnly): void {
        $directOnly();
        /* A credited award, a skipped one and an unrelated top-up. */
        $activate($pendingMember('juan'), $pkgA());
        $activate($pendingMember('david'), $pkgA());

        $adminId    = (int) test_user('comm_admin', 'admin')['id'];
        $recipient  = $pendingMember('juan');

        (new WalletService())->adminTopUp($adminId, (int) $recipient['id'], '250.00', 'Invariant probe');

        $walletReport = (new WalletService())->reconcile();

        assert_same([], $walletReport['mismatches'], 'wallets match the ledger: ' . json_encode($walletReport['mismatches']));
        assert_same([], $walletReport['violations']);

        $commissionReport = (new CommissionService())->reconcile();

        assert_true($commissionReport['ok'], 'commissions reconcile: ' . json_encode($commissionReport['mismatches']));
        assert_same($commissionReport['credited_total'], $commissionReport['ledger_total']);
        assert_same(1, $commissionReport['credited_rows']);
        assert_same(1, $commissionReport['skipped_rows']);
    },

    'P10-T6 reconciliation catches an award without its wallet movement' => function () use ($pendingMember, $pkgA, $activate, $directOnly): void {
        $directOnly();
        $activate($pendingMember('juan'), $pkgA());

        $row = db()->query("SELECT id FROM commissions WHERE status = 'credited' LIMIT 1")->fetch();
        assert_not_same(false, $row);

        /* Point the award at no ledger row: the report must see it. */
        db()->prepare('UPDATE commissions SET ledger_id = NULL WHERE id = ?')->execute([(int) $row['id']]);

        $report = (new CommissionService())->reconcile();

        assert_false($report['ok']);
        assert_contains('missing_ledger', json_encode($report['mismatches']));
    },

    /* ---------- P10-T7: member totals and pagination -------------- */

    'P10-T7 member totals, filters and pagination follow the awards' => function () use ($pendingMember, $pkgA, $activate, $juan, $directOnly): void {
        $directOnly();
        $juanId = (int) $juan()['id'];

        for ($i = 0; $i < 3; $i++) {
            $activate($pendingMember('juan'), $pkgA());
        }

        $totals = Commission::totalsForUser($juanId);

        assert_true($totals['count'] >= 3);
        assert_true($totals['credited'] >= 1500.0);
        assert_same($totals['credited'], round($totals['direct'] + $totals['indirect'], 2));
        assert_not_null($totals['last_at']);

        /* Pagination: two per page over a filtered set of this member's rows. */
        $filters = Commission::normaliseFilters(['type' => 'direct_referral']);
        $pager   = Paginator::make(Commission::countForUser($juanId, $filters), 1, 2);
        $pageOne = Commission::pageForUser($juanId, $filters, $pager);

        assert_count(2, $pageOne);
        assert_true($pager->pages >= 2);

        $pagerTwo = Paginator::make(Commission::countForUser($juanId, $filters), 2, 2);
        $pageTwo  = Commission::pageForUser($juanId, $filters, $pagerTwo);

        assert_true($pageTwo !== []);
        assert_not_same((int) $pageOne[0]['id'], (int) $pageTwo[0]['id'], 'page two is a different slice');

        /* Every row belongs to the member. */
        foreach (array_merge($pageOne, $pageTwo) as $row) {
            assert_same($juanId, (int) $row['user_id']);
        }

        /* Invalid filters are ignored, never trusted. */
        $junk = Commission::normaliseFilters([
            'type' => 'binary_pairing', 'level' => '99', 'status' => 'paid',
            'from' => 'not-a-date', 'to' => '2026-13-40',
        ]);

        assert_same(Commission::filterDefaults(), $junk);

        /* The per-level breakdown (rendered in Phase 11) already reads level 1
         * correctly and zero-fills levels 2–10. */
        $levels = Commission::levelBreakdown($juanId);

        assert_same($totals['count'], $levels[1]['count']);
        assert_same($totals['credited'], $levels[1]['amount']);

        for ($level = 2; $level <= Commission::LEVEL_MAX; $level++) {
            assert_same(0, $levels[$level]['count'], 'level ' . $level . ' has no award yet');
            assert_same(0.0, $levels[$level]['amount']);
        }
    },

    /* ---------- P10-T8: the admin ledger ------------------------- */

    'P10-T8 the admin ledger filters by member, type, level, status and date' => function () use ($pendingMember, $pkgA, $activate, $directOnly): void {
        $directOnly();
        $credited = $activate($pendingMember('juan'), $pkgA());

        $davidMember = $pendingMember('david');
        $skipped     = $activate($davidMember, $pkgA());

        /* A row from last month, so the date filter has something to exclude. */
        db()->prepare('UPDATE commissions SET created_at = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE activation_id = ?')
            ->execute([(int) $credited['activation_id']]);

        $all = Commission::countAdmin(Commission::filterDefaults());

        $byMember = Commission::normaliseFilters(['q' => 'juan']);
        assert_true(Commission::countAdmin($byMember) >= 1);

        $bySource = Commission::normaliseFilters(['q' => (string) $davidMember['username']]);
        assert_same(1, Commission::countAdmin($bySource), 'the source member is searchable');

        $byStatus = Commission::normaliseFilters(['status' => 'skipped']);
        assert_true(Commission::countAdmin($byStatus) >= 1);

        $byType = Commission::normaliseFilters(['type' => 'direct_referral']);
        assert_true(Commission::countAdmin($byType) >= 1);

        $byLevel = Commission::normaliseFilters(['level' => '2']);
        assert_same(0, Commission::countAdmin($byLevel), 'the direct-only fixture has no indirect awards');

        $recent = Commission::normaliseFilters(['from' => date('Y-m-d', strtotime('-1 day'))]);
        assert_true(Commission::countAdmin($recent) >= 1);

        $old = Commission::normaliseFilters(['to' => date('Y-m-d', strtotime('-30 days'))]);
        assert_same(1, Commission::countAdmin($old), 'the back-dated award is the only old row');

        $rows = Commission::adminList(Commission::filterDefaults(), Paginator::make($all, 1, 100));
        assert_true(count($rows) >= 2);
        assert_not_null($rows[0]['recipient_username']);
        assert_not_null($rows[0]['source_username']);

        $stats = Commission::stats();
        assert_true($stats['credited'] >= 1);
        assert_true($stats['credited_total'] >= 500.0);
        assert_true($stats['skipped'] >= 1);
    },

    /* ---------- P10-T9: isolation -------------------------------- */

    'P10-T9 a member query never returns another member commissions' => function () use ($pendingMember, $pkgA, $activate, $maria, $directOnly): void {
        $directOnly();
        $juanId  = (int) (Member::findByUsername('juan') ?? [])['id'];
        $mariaId = (int) $maria()['id'];

        $activate($pendingMember('juan'), $pkgA());
        $activate($pendingMember('maria'), $pkgA());

        $filters = Commission::filterDefaults();

        $juanRows  = Commission::pageForUser($juanId, $filters, Paginator::make(100, 1, 100));
        $mariaRows = Commission::pageForUser($mariaId, $filters, Paginator::make(100, 1, 100));

        assert_true($juanRows !== []);
        assert_true($mariaRows !== []);

        foreach ($juanRows as $row) {
            assert_same($juanId, (int) $row['user_id']);
        }

        foreach ($mariaRows as $row) {
            assert_same($mariaId, (int) $row['user_id']);
        }

        /* The counts disagree by whoever has awards — never mixed. */
        assert_same(
            Commission::countForUser($juanId, $filters),
            count(Commission::pageForUser($juanId, $filters, Paginator::make(100, 1, 100)))
        );
    },

    /* ---------- P10-T10: the detail trail ------------------------ */

    'P10-T10 an award links to its activation, source member and wallet entry' => function () use ($pendingMember, $pkgA, $activate, $directOnly): void {
        $directOnly();
        $member  = $pendingMember('juan');
        $summary = $activate($member, $pkgA());
        $row     = Commission::forActivation((int) $summary['activation_id'])[0];

        $detail = Commission::find((int) $row['id']);

        assert_not_null($detail);
        assert_same((int) $member['id'], (int) $detail['source_user_id']);
        assert_same((string) $member['username'], $detail['source_username']);
        assert_same('juan', $detail['recipient_username']);
        assert_same((int) $summary['activation_id'], (int) $detail['activation_id']);
        assert_same('approved', $detail['activation_status']);
        assert_same('Package A', $detail['package_name']);
        assert_same('code', $detail['activation_method']);

        $ledger = db()->query(
            "SELECT l.* FROM ewallet_ledger l WHERE l.id = " . (int) $detail['ledger_id']
        )->fetch();

        assert_not_same(false, $ledger);
        assert_same('commission', $ledger['ref_type']);
        assert_same((int) $detail['id'], (int) $ledger['ref_id']);
        assert_same((int) $detail['user_id'], (int) $ledger['user_id']);

        /* The wallet snapshot on the award is the recipient's balance. */
        assert_same(
            (new WalletService())->balance((int) $detail['user_id']),
            (float) $detail['wallet_balance_after']
        );
    },

    'P10-T10 the hook is still invoked exactly once per activation' => function () use ($pendingMember, $pkgA, $activate): void {
        $before = count(CommissionService::invocations());

        $summary = $activate($pendingMember('juan'), $pkgA());

        assert_same($before + 1, count(CommissionService::invocations()));
        assert_true(CommissionService::wasInvokedFor((int) $summary['activation_id']));
    },
];
